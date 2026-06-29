<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProfileResource;
use App\Models\AddressBook;
use App\Models\Agency;
use App\Models\AssistedPurchase;
use App\Models\Country;
use App\Models\Invoice;
use App\Models\Locker;
use App\Models\PreAlert;
use App\Models\Profile;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\ShipmentLog;
use App\Models\User;
use App\Support\LockerNumberGenerator;
use App\Support\PhoneNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;

class ClientController extends Controller
{
    use Concerns\InteractsWithAgencyVisibility;
    use Concerns\NormalizesOptionalEmail;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Profile::query()
            ->with(['agency', 'user', 'city', 'state', 'country'])
            ->withCount(['savedByProfiles as address_book_count'])
            ->withCount('sentShipments')
            ->withCount('receivedShipments');

        if (! $user->canAccessAllAgencies()) {
            $query->where('agency_id', $user->agency_id);
        }

        if ($request->filled('search')) {
            $query->search($request->input('search'));
        }

        if ($request->filled('agency_id')) {
            $query->where('agency_id', $request->input('agency_id'));
        }

        if ($request->filled('status')) {
            $query->where('is_active', $request->input('status') === 'active');
        }

        if ($request->filled('portal')) {
            if ($request->input('portal') === 'yes') {
                $query->whereHas('user');
            } elseif ($request->input('portal') === 'no') {
                $query->whereDoesntHave('user');
            }
        }

        $clients = $query->latest()->paginate(25)->withQueryString();

        return response()->json([
            'clients' => ProfileResource::collection($clients),
            'meta' => [
                'current_page' => $clients->currentPage(),
                'last_page' => $clients->lastPage(),
                'per_page' => $clients->perPage(),
                'total' => $clients->total(),
            ],
            'filters' => $request->only(['search', 'agency_id', 'status', 'portal']),
            'agencies' => $this->getAgencies($user),
        ]);
    }

    public function show(Request $request, Profile $client): JsonResponse
    {
        $this->authorizeAgency($request->user(), $client);

        $client->load(['agency', 'user', 'city', 'state', 'country']);

        $shipments = Shipment::query()
            ->where('sender_profile_id', $client->id)
            ->latest()
            ->take(20)
            ->get();

        $userId = $client->user?->id;

        $invoices = $userId
            ? Invoice::query()->where('user_id', $userId)->latest()->take(20)->get()
            : collect();

        $financeSummary = $userId ? [
            'total_invoiced' => Invoice::where('user_id', $userId)->sum('amount'),
            'total_paid' => Invoice::where('user_id', $userId)->where('status', 'paid')->sum('amount'),
            'total_pending' => Invoice::where('user_id', $userId)->where('status', 'pending')->sum('amount'),
        ] : [
            'total_invoiced' => 0,
            'total_paid' => 0,
            'total_pending' => 0,
        ];

        return response()->json([
            'client' => new ProfileResource($client),
            'shipments' => $shipments,
            'invoices' => $invoices,
            'financeSummary' => $financeSummary,
        ]);
    }

    public function store(Request $request): JsonResponse
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
                'nullable',
                Rules\Password::defaults(),
            ],
            'agency_id' => ['nullable', 'exists:agencies,id'],
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

        $country = Country::query()->find($data['country_id']);
        $phoneCode = $country !== null ? (string) ($country->phonecode ?? '') : '';
        $data['phone'] = PhoneNormalizer::normalize($data['phone'], $phoneCode !== '' ? '+'.$phoneCode : null);
        if (! empty($data['phone_secondary'])) {
            $data['phone_secondary'] = PhoneNormalizer::normalize($data['phone_secondary'], $phoneCode !== '' ? '+'.$phoneCode : null);
        }

        $agencyId = $data['agency_id'] ?? $request->user()->agency_id;
        $profile = null;
        $portalUser = null;

        DB::transaction(function () use ($data, $createPortal, $agencyId, &$profile, &$portalUser) {
            $existingProfile = null;
            if (! empty($data['email'])) {
                $existingProfile = Profile::where('email', $data['email'])->first();
            }
            if (! $existingProfile && ! empty($data['phone'])) {
                $existingProfile = Profile::where('phone', $data['phone'])->first();
            }

            if ($existingProfile) {
                $profile = $existingProfile;
                $profile->update([
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'email' => $data['email'] ?? $profile->email,
                    'phone' => $data['phone'],
                    'phone_secondary' => $data['phone_secondary'] ?? null,
                    'address' => $data['address'] ?? null,
                    'landmark' => $data['landmark'] ?? null,
                    'zip_code' => $data['zip_code'] ?? null,
                    'country_id' => $data['country_id'],
                    'state_id' => $data['state_id'],
                    'city_id' => $data['city_id'],
                    'agency_id' => $agencyId,
                    'is_active' => true,
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
                    'agency_id' => $agencyId,
                    'is_active' => true,
                    'is_client' => true,
                    'is_staff' => false,
                ]);
            }

            if ($createPortal && ! $profile->user) {
                $fullName = trim($data['first_name'].' '.$data['last_name']);
                $password = ! empty($data['password'])
                    ? $data['password']
                    : \Illuminate\Support\Str::random(32);
                $portalUser = User::create([
                    'profile_id' => $profile->id,
                    'name' => $fullName,
                    'email' => $data['email'],
                    'phone' => $data['phone'],
                    'password' => Hash::make($password),
                    'agency_id' => $agencyId,
                    'email_verified_at' => now(),
                ]);
                $portalUser->assignRole('client');

                if (empty($data['password'])) {
                    $token = app('auth.password.broker')->createToken($portalUser);
                    $portalUser->sendPasswordResetNotification($token);
                }
            }

            $lockerNumber = LockerNumberGenerator::generate();

            Locker::create([
                'profile_id' => $profile->id,
                'user_id' => $portalUser?->id,
                'code' => $lockerNumber,
                'formatted_address' => str_replace(
                    '{{locker_code}}',
                    $lockerNumber,
                    Setting::getValue('locker_address_template', '')
                ),
            ]);

            if ($portalUser) {
                $portalUser->update(['locker_number' => $lockerNumber]);
            }
        });

        $profile->load('user');
        $lockerCode = Locker::where('profile_id', $profile->id)->value('code') ?? '';
        $suffix = $createPortal ? ' (compte portail créé)' : ' (sans compte portail)';

        return response()->json([
            'message' => 'Fiche client créée avec casier '.$lockerCode.$suffix,
            'client' => new ProfileResource($profile),
        ], 201);
    }

    public function update(Request $request, Profile $client): JsonResponse
    {
        $this->authorizeAgency($request->user(), $client);

        $request->merge([
            'email' => $this->normalizeOptionalEmail($request->input('email')),
        ]);

        $portalUser = $client->user;

        $emailRules = $portalUser
            ? ['required', 'email', 'max:255', Rule::unique('profiles', 'email')->ignore($client->id)]
            : ['nullable', 'email', 'max:255', Rule::unique('profiles', 'email')->ignore($client->id)];

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'email' => $emailRules,
            'phone' => ['required', 'string', 'max:64'],
            'phone_secondary' => ['nullable', 'string', 'max:64'],
            'agency_id' => ['nullable', 'exists:agencies,id'],
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

        DB::transaction(function () use ($data, $client) {
            $client->update($data);

            if ($client->user) {
                $client->user->update([
                    'name' => trim($data['first_name'].' '.$data['last_name']),
                    'email' => $data['email'],
                    'phone' => $data['phone'],
                    'agency_id' => $data['agency_id'] ?? $client->user->agency_id,
                ]);
            }
        });

        return response()->json([
            'message' => 'Client mis à jour.',
            'client' => new ProfileResource($client->fresh(['user', 'city', 'state', 'country'])),
        ]);
    }

    public function toggleActive(Request $request, Profile $client): JsonResponse
    {
        $this->authorizeAgency($request->user(), $client);

        $nextActive = ! $client->is_active;
        $client->update(['is_active' => $nextActive]);

        if ($client->user) {
            $client->user->update([
                'email_verified_at' => $nextActive ? now() : null,
            ]);
        }

        return response()->json(['message' => 'Statut du client modifié.']);
    }

    public function createPortal(Request $request, Profile $client): JsonResponse
    {
        $this->authorizeAgency($request->user(), $client);

        if ($client->user) {
            return response()->json(['message' => 'Ce client a déjà un compte portail.'], 422);
        }

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['nullable', Rules\Password::defaults()],
        ]);

        $portalUser = DB::transaction(function () use ($data, $client) {
            if (! $client->email) {
                $client->update(['email' => $data['email']]);
            }

            $password = ! empty($data['password'])
                ? $data['password']
                : \Illuminate\Support\Str::random(32);

            $newUser = User::create([
                'profile_id' => $client->id,
                'name' => $client->full_name,
                'email' => $data['email'],
                'phone' => $client->phone,
                'password' => Hash::make($password),
                'agency_id' => $client->agency_id,
                'email_verified_at' => now(),
            ]);
            $newUser->assignRole('client');

            $locker = Locker::where('profile_id', $client->id)->first();
            $locker?->update(['user_id' => $newUser->id]);

            if ($locker) {
                $newUser->update(['locker_number' => $locker->code]);
            }

            return $newUser;
        });

        if (empty($data['password'])) {
            $token = app('auth.password.broker')->createToken($portalUser);
            $portalUser->sendPasswordResetNotification($token);
        }

        return response()->json([
            'message' => 'Compte portail créé pour ce client. Un e-mail a été envoyé.',
            'client' => new ProfileResource($client->fresh(['user'])),
        ]);
    }

    public function activity(Request $request, Profile $client): JsonResponse
    {
        $this->authorizeAgency($request->user(), $client);

        $client->load(['agency', 'user', 'city', 'state', 'country']);
        $client->loadCount(['savedByProfiles', 'sentShipments', 'receivedShipments']);

        $userId = $client->user?->id;

        $sentShipments = Shipment::query()
            ->where('sender_profile_id', $client->id)
            ->with(['recipientProfile'])
            ->latest()
            ->paginate(10, ['*'], 'sent_page');

        $receivedShipments = Shipment::query()
            ->where('recipient_profile_id', $client->id)
            ->with(['senderProfile'])
            ->latest()
            ->paginate(10, ['*'], 'received_page');

        $assistedPurchases = AssistedPurchase::query()
            ->when(
                $userId,
                fn ($q) => $q->where('user_id', $userId),
                fn ($q) => $q->whereRaw('0 = 1'),
            )
            ->latest()
            ->paginate(10, ['*'], 'purchase_page');

        $shipmentNotices = PreAlert::query()
            ->when(
                $userId,
                fn ($q) => $q->where('user_id', $userId),
                fn ($q) => $q->whereRaw('0 = 1'),
            )
            ->latest()
            ->paginate(10, ['*'], 'notice_page');

        $invoices = Invoice::query()
            ->when(
                $userId,
                fn ($q) => $q->where('user_id', $userId),
                fn ($q) => $q->whereRaw('0 = 1'),
            )
            ->latest()
            ->paginate(10, ['*'], 'invoice_page');

        $addressBookEntries = AddressBook::query()
            ->where('owner_profile_id', $client->id)
            ->with('contactProfile')
            ->latest()
            ->paginate(10, ['*'], 'contact_page');

        $relatedShipmentIds = Shipment::query()
            ->where('sender_profile_id', $client->id)
            ->orWhere('recipient_profile_id', $client->id)
            ->pluck('id');

        $timeline = ShipmentLog::query()
            ->whereIn('shipment_id', $relatedShipmentIds)
            ->with(['shipment:id,public_tracking,sender_profile_id,recipient_profile_id', 'user:id,name'])
            ->latest('created_at')
            ->limit(50)
            ->get()
            ->map(fn (ShipmentLog $log) => [
                'id' => $log->id,
                'type' => 'shipment_log',
                'shipment_id' => $log->shipment_id,
                'tracking' => $log->shipment?->public_tracking,
                'role' => $log->shipment?->sender_profile_id === $client->id ? 'sender' : 'recipient',
                'status' => $log->status?->value ?? $log->status,
                'title' => $log->title,
                'description' => $log->description,
                'user_name' => $log->user?->name,
                'created_at' => $log->created_at?->toIso8601String(),
            ]);

        $paginatorToArray = fn ($paginator) => [
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];

        return response()->json([
            'client' => new ProfileResource($client),
            'sentShipments' => $paginatorToArray($sentShipments),
            'receivedShipments' => $paginatorToArray($receivedShipments),
            'assistedPurchases' => $paginatorToArray($assistedPurchases),
            'shipmentNotices' => $paginatorToArray($shipmentNotices),
            'invoices' => $paginatorToArray($invoices),
            'addressBookEntries' => $paginatorToArray($addressBookEntries),
            'timeline' => $timeline,
        ]);
    }

    private function authorizeAgency(User $user, Profile $client): void
    {
        if (! $user->canAccessAllAgencies() && (int) $client->agency_id !== (int) $user->agency_id) {
            abort(403);
        }
    }

    private function getAgencies(User $user): Collection
    {
        if ($user->canAccessAllAgencies()) {
            return Agency::where('is_active', true)->get(['id', 'name']);
        }

        return Agency::where('id', $user->agency_id)->get(['id', 'name']);
    }

    /**
     * §21.2 — Détection de doublon client avant création.
     * Retourne les profils existants similaires (même téléphone, email ou nom proche).
     */
    public function checkDuplicates(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);

        $user = $request->user();
        $duplicates = [];

        if (! empty($data['phone'])) {
            $byPhone = Profile::query()
                ->where('phone', $data['phone'])
                ->when(! $user->canAccessAllAgencies(), fn ($q) => $q->where('agency_id', $user->agency_id))
                ->with('user')
                ->limit(5)
                ->get(['id', 'full_name', 'email', 'phone', 'agency_id']);
            foreach ($byPhone as $p) {
                $duplicates[] = ['profile' => $p, 'match_type' => 'phone'];
            }
        }

        if (! empty($data['email'])) {
            $byEmail = Profile::query()
                ->where('email', $data['email'])
                ->when(! $user->canAccessAllAgencies(), fn ($q) => $q->where('agency_id', $user->agency_id))
                ->limit(5)
                ->get(['id', 'full_name', 'email', 'phone', 'agency_id']);
            foreach ($byEmail as $p) {
                if (! collect($duplicates)->contains(fn ($d) => $d['profile']->id === $p->id)) {
                    $duplicates[] = ['profile' => $p, 'match_type' => 'email'];
                }
            }
        }

        return response()->json([
            'has_duplicates' => count($duplicates) > 0,
            'duplicates' => $duplicates,
        ]);
    }
}
