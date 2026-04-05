<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\InteractsWithAgencyVisibility;
use App\Http\Controllers\Concerns\NormalizesOptionalEmail;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Settings\ShipLineController;
use App\Models\AddressBook;
use App\Models\Agency;
use App\Models\Locker;
use App\Models\Profile;
use App\Models\Setting;
use App\Models\User;
use App\Support\LockerNumberGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;

class ShipmentWizardController extends Controller
{
    use InteractsWithAgencyVisibility;
    use NormalizesOptionalEmail;

    /**
     * Recherche fiches clients (Profiles avec agency_id, possiblement avec compte portail).
     */
    public function searchClients(Request $request): JsonResponse
    {
        $request->validate(['q' => ['required', 'string', 'min:1']]);

        $user = $request->user();

        $query = Profile::query()
            ->whereNotNull('agency_id')
            ->search($request->input('q'))
            ->with(['user'])
            ->limit(20);

        if (! $user->canAccessAllAgencies()) {
            $query->where('agency_id', $user->agency_id);
        }

        $rows = $query->get();

        return response()->json(
            $rows->map(fn (Profile $p) => [
                'id' => $p->id,
                'name' => $p->full_name,
                'email' => $p->email ?? $p->user?->email ?? '',
                'phone' => $p->phone,
                'locker_code' => $p->user?->locker_number ?? Locker::where('profile_id', $p->id)->value('code'),
                'has_portal' => $p->user !== null,
            ])
        );
    }

    /**
     * Destinataires filtres par carnet d'adresses du client (via address_books + profiles).
     */
    public function searchRecipients(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['required', 'string', 'min:1'],
            'client_id' => ['nullable', 'exists:profiles,id'],
        ]);

        $term = '%'.$request->input('q').'%';
        $clientProfileId = $request->input('client_id');

        if ($clientProfileId) {
            $clientProfile = Profile::find($clientProfileId);

            if (! $clientProfile) {
                return response()->json([]);
            }

            $query = AddressBook::query()
                ->where('owner_profile_id', $clientProfile->id)
                ->whereHas('contactProfile', function ($q) use ($term) {
                    $q->where('is_active', true)
                        ->where(function ($inner) use ($term) {
                            $inner->where('first_name', 'like', $term)
                                ->orWhere('last_name', 'like', $term)
                                ->orWhere('email', 'like', $term)
                                ->orWhere('phone', 'like', $term)
                                ->orWhereRaw("CONCAT(first_name, ' ', last_name) like ?", [$term]);
                        });
                })
                ->with(['contactProfile.city', 'contactProfile.country'])
                ->limit(20);

            $entries = $query->get();

            return response()->json(
                $entries->map(fn (AddressBook $e) => [
                    'id' => $e->contactProfile->id,
                    'address_book_id' => $e->id,
                    'name' => $e->contactProfile->full_name,
                    'email' => $e->contactProfile->email,
                    'phone' => $e->contactProfile->phone,
                    'city' => $e->contactProfile->city?->name ?? '',
                    'country' => $e->contactProfile->country?->name ?? '',
                ])
            );
        }

        $profiles = Profile::query()
            ->where('is_active', true)
            ->where('is_staff', false)
            ->search($request->input('q'))
            ->with(['city', 'country'])
            ->limit(20)
            ->get();

        return response()->json(
            $profiles->map(fn (Profile $p) => [
                'id' => $p->id,
                'name' => $p->full_name,
                'email' => $p->email,
                'phone' => $p->phone,
                'city' => $p->city?->name ?? '',
                'country' => $p->country?->name ?? '',
            ])
        );
    }

    /**
     * Creation rapide d'une fiche client (Profile + portail optionnel).
     */
    public function quickCreateClient(Request $request): JsonResponse
    {
        $createPortal = $request->boolean('create_portal');

        $request->merge([
            'email' => $this->normalizeOptionalEmail($request->input('email')),
        ]);

        $data = $request->validate([
            'create_portal' => ['sometimes', 'boolean'],
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'email' => [
                Rule::requiredIf($createPortal),
                'nullable',
                'email',
                'max:255',
            ],
            'phone' => ['required', 'string', 'max:64'],
            'phone_secondary' => ['nullable', 'string', 'max:64'],
            'password' => [
                Rule::requiredIf($createPortal),
                'nullable',
                Rules\Password::defaults(),
            ],
            'address' => ['nullable', 'string', 'max:500'],
            'landmark' => ['nullable', 'string', 'max:500'],
            'zip_code' => ['nullable', 'string', 'max:16'],
            'country_id' => ['required', 'integer', 'exists:countries,id'],
            'state_id' => [
                'required',
                'integer',
                Rule::exists('states', 'id')->where('country_id', $request->integer('country_id')),
            ],
            'city_id' => [
                'required',
                'integer',
                Rule::exists('cities', 'id')->where('state_id', $request->integer('state_id')),
            ],
        ]);

        $authUser = $request->user();
        $profile = null;
        $portalUser = null;

        DB::transaction(function () use ($data, $createPortal, $authUser, &$profile, &$portalUser) {
            $existing = null;
            if (! empty($data['email'])) {
                $existing = Profile::where('email', $data['email'])->first();
            }
            if (! $existing && ! empty($data['phone'])) {
                $existing = Profile::where('phone', $data['phone'])->first();
            }

            if ($existing) {
                $profile = $existing;
                $profile->update([
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'agency_id' => $authUser->agency_id,
                    'address' => $data['address'] ?? $profile->address,
                    'landmark' => $data['landmark'] ?? $profile->landmark,
                    'zip_code' => $data['zip_code'] ?? $profile->zip_code,
                    'country_id' => $data['country_id'],
                    'state_id' => $data['state_id'],
                    'city_id' => $data['city_id'],
                    'phone' => $data['phone'],
                    'phone_secondary' => $data['phone_secondary'] ?? null,
                    'email' => $data['email'] ?? $profile->email,
                    'is_client' => true,
                    'is_staff' => false,
                ]);
            } else {
                $profile = Profile::create([
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'email' => $data['email'] ?? null,
                    'phone' => $data['phone'],
                    'phone_secondary' => $data['phone_secondary'] ?? null,
                    'address' => $data['address'] ?? null,
                    'landmark' => $data['landmark'] ?? null,
                    'zip_code' => $data['zip_code'] ?? null,
                    'country_id' => $data['country_id'],
                    'state_id' => $data['state_id'],
                    'city_id' => $data['city_id'],
                    'agency_id' => $authUser->agency_id,
                    'is_active' => true,
                    'is_client' => true,
                    'is_staff' => false,
                ]);
            }

            if ($createPortal && ! $profile->user) {
                $fullName = trim($data['first_name'].' '.$data['last_name']);
                $portalUser = User::create([
                    'profile_id' => $profile->id,
                    'name' => $fullName,
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'email' => $data['email'],
                    'phone' => $data['phone'],
                    'phone_mobile' => $data['phone_secondary'] ?? null,
                    'password' => Hash::make($data['password']),
                    'agency_id' => $authUser->agency_id,
                    'email_verified_at' => now(),
                ]);
                $portalUser->assignRole('client');
            }

            $code = LockerNumberGenerator::generate();

            $template = Setting::getValue('locker_address_template', '');
            $formatted = str_replace('{{locker_code}}', $code, $template);

            Locker::create([
                'profile_id' => $profile->id,
                'user_id' => $portalUser?->id,
                'code' => $code,
                'formatted_address' => $formatted,
            ]);

            if ($portalUser) {
                $portalUser->update(['locker_number' => $code]);
            }
        });

        $profile->load('user');

        return response()->json([
            'id' => $profile->id,
            'name' => $profile->full_name,
            'email' => $profile->email ?? $profile->user?->email ?? '',
            'phone' => $profile->phone,
            'locker_code' => $profile->user?->locker_number ?? Locker::where('profile_id', $profile->id)->value('code'),
            'has_portal' => $profile->user !== null,
        ], 201);
    }

    /**
     * Creation rapide d'un destinataire (Profile + address_book entry).
     */
    public function quickCreateRecipient(Request $request): JsonResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:64'],
            'phone_secondary' => ['nullable', 'string', 'max:64'],
            'address' => ['nullable', 'string', 'max:500'],
            'landmark' => ['nullable', 'string', 'max:500'],
            'zip_code' => ['nullable', 'string', 'max:16'],
            'country_id' => ['required', 'integer', 'exists:countries,id'],
            'state_id' => [
                'required',
                'integer',
                Rule::exists('states', 'id')->where('country_id', $request->integer('country_id')),
            ],
            'city_id' => [
                'required',
                'integer',
                Rule::exists('cities', 'id')->where('state_id', $request->integer('state_id')),
            ],
            'client_profile_id' => ['required', 'exists:profiles,id'],
        ]);

        $authUser = $request->user();
        $clientProfile = Profile::findOrFail($data['client_profile_id']);

        if (! $authUser->canAccessAllAgencies() && (int) $clientProfile->agency_id !== (int) $authUser->agency_id) {
            abort(403);
        }

        $result = DB::transaction(function () use ($data, $clientProfile) {
            $profile = null;

            if (! empty($data['email'])) {
                $profile = Profile::where('email', $data['email'])->first();
            }
            if (! $profile && ! empty($data['phone'])) {
                $profile = Profile::where('phone', $data['phone'])->first();
            }

            if (! $profile) {
                $profile = Profile::create([
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'email' => $data['email'] ?? null,
                    'phone' => $data['phone'],
                    'phone_secondary' => $data['phone_secondary'] ?? null,
                    'address' => $data['address'] ?? null,
                    'landmark' => $data['landmark'] ?? null,
                    'zip_code' => $data['zip_code'] ?? null,
                    'country_id' => $data['country_id'],
                    'state_id' => $data['state_id'],
                    'city_id' => $data['city_id'],
                    'agency_id' => $clientProfile->agency_id,
                    'is_active' => true,
                    'is_client' => false,
                    'is_staff' => false,
                ]);
            }

            $exists = AddressBook::where('owner_profile_id', $clientProfile->id)
                ->where('contact_profile_id', $profile->id)
                ->exists();

            if (! $exists) {
                AddressBook::create([
                    'owner_profile_id' => $clientProfile->id,
                    'contact_profile_id' => $profile->id,
                    'is_default' => false,
                ]);
            }

            return $profile;
        });

        $result->load('city', 'country');

        return response()->json([
            'id' => $result->id,
            'name' => $result->full_name,
            'email' => $result->email,
            'phone' => $result->phone,
            'city' => $result->city?->name ?? '',
            'country' => $result->country?->name ?? '',
        ], 201);
    }

    /**
     * Unified profile search for both Sender and Recipient comboboxes.
     *
     * - q:          search term (name, email, phone)
     * - exclude_id: profile_id to omit (the already-selected sender)
     * - related_to: profile_id of the sender — results in their address book
     *               are sorted first and flagged with is_related=true
     */
    public function searchProfiles(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['required', 'string', 'min:1'],
            'exclude_id' => ['nullable', 'integer'],
            'related_to' => ['nullable', 'integer'],
        ]);

        $user = $request->user();
        $excludeId = $request->integer('exclude_id');
        $relatedTo = $request->integer('related_to');

        $relatedProfileIds = collect();

        if ($relatedTo) {
            $relatedProfile = Profile::find($relatedTo);

            if ($relatedProfile) {
                $relatedProfileIds = AddressBook::where('owner_profile_id', $relatedProfile->id)
                    ->pluck('contact_profile_id');
            }
        }

        $query = Profile::query()
            ->where('is_active', true)
            ->where('is_staff', false)
            ->search($request->input('q'))
            ->with(['user', 'city', 'country']);

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        if (! $user->canAccessAllAgencies()) {
            $query->where(function ($q) use ($user) {
                $q->where('agency_id', $user->agency_id)
                    ->orWhereNull('agency_id');
            });
        }

        if ($relatedProfileIds->isNotEmpty()) {
            $ids = $relatedProfileIds->implode(',');
            $query->orderByRaw("FIELD(id, {$ids}) DESC");
        }

        $profiles = $query->limit(20)->get();

        return response()->json(
            $profiles->map(fn (Profile $p) => [
                'id' => $p->id,
                'first_name' => $p->first_name,
                'last_name' => $p->last_name,
                'full_name' => $p->full_name,
                'email' => $p->email,
                'phone' => $p->phone,
                'city' => $p->city?->name,
                'country' => $p->country?->name,
                'country_id' => $p->country_id,
                'has_account' => $p->user !== null,
                'locker_number' => $p->user?->locker_number,
                'is_related' => $relatedProfileIds->contains($p->id),
            ])
        );
    }

    public function agencies(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->canAccessAllAgencies()) {
            $agencies = Agency::where('is_active', true)->get(['id', 'name', 'code']);
        } else {
            $agencies = Agency::where('id', $user->agency_id)->get(['id', 'name', 'code']);
        }

        return response()->json($agencies);
    }

    /**
     * Lignes d'expédition actives couvrant la paire pays départ / arrivée (même logique que Paramètres).
     */
    public function shipLinesForRoute(Request $request): JsonResponse
    {
        return app(ShipLineController::class)->forRoute($request);
    }
}
