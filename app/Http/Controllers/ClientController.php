<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProfileResource;
use App\Models\Agency;
use App\Models\Invoice;
use App\Models\Locker;
use App\Models\Profile;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            ->whereNotNull('agency_id')
            ->with(['agency', 'user', 'city', 'state', 'country'])
            ->withCount(['savedByUsers as address_book_count']);

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
            ->with(['status'])
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
                Rule::requiredIf($createPortal),
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
                ]);
            }

            if ($createPortal && ! $profile->user) {
                $fullName = trim($data['first_name'] . ' ' . $data['last_name']);
                $portalUser = User::create([
                    'profile_id' => $profile->id,
                    'name' => $fullName,
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'email' => $data['email'],
                    'phone' => $data['phone'],
                    'phone_mobile' => $data['phone_secondary'] ?? null,
                    'password' => Hash::make($data['password']),
                    'agency_id' => $agencyId,
                    'email_verified_at' => now(),
                ]);
                $portalUser->assignRole('client');
            }

            $lockerNumber = $this->generateLockerNumber();

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
            'message' => 'Fiche client créée avec casier ' . $lockerCode . $suffix,
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
                    'name' => trim($data['first_name'] . ' ' . $data['last_name']),
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'email' => $data['email'],
                    'phone' => $data['phone'],
                    'phone_mobile' => $data['phone_secondary'] ?? null,
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
            'password' => ['required', Rules\Password::defaults()],
        ]);

        $portalUser = DB::transaction(function () use ($data, $client) {
            if (! $client->email) {
                $client->update(['email' => $data['email']]);
            }

            $newUser = User::create([
                'profile_id' => $client->id,
                'name' => $client->full_name,
                'first_name' => $client->first_name,
                'last_name' => $client->last_name,
                'email' => $data['email'],
                'phone' => $client->phone,
                'phone_mobile' => $client->phone_secondary,
                'password' => Hash::make($data['password']),
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

        return response()->json([
            'message' => 'Compte portail créé pour ce client.',
            'client' => new ProfileResource($client->fresh(['user'])),
        ]);
    }

    private function authorizeAgency(User $user, Profile $client): void
    {
        if (! $user->canAccessAllAgencies() && (int) $client->agency_id !== (int) $user->agency_id) {
            abort(403);
        }
    }

    private function getAgencies(User $user): \Illuminate\Support\Collection
    {
        if ($user->canAccessAllAgencies()) {
            return Agency::where('is_active', true)->get(['id', 'name']);
        }

        return Agency::where('id', $user->agency_id)->get(['id', 'name']);
    }

    private function generateLockerNumber(): string
    {
        $prefix = Setting::getValue('locker_prefix', 'MRP');
        $digits = (int) Setting::getValue('locker_digits', '4');
        $mode = Setting::getValue('locker_mode', 'random');

        if ($mode === 'sequential') {
            $last = Locker::query()->orderByDesc('id')->value('code');
            $lastNum = $last ? (int) preg_replace('/\D/', '', $last) : 0;

            return $prefix . '-' . str_pad($lastNum + 1, $digits, '0', STR_PAD_LEFT);
        }

        do {
            $code = $prefix . '-' . str_pad(random_int(0, pow(10, $digits) - 1), $digits, '0', STR_PAD_LEFT);
        } while (Locker::where('code', $code)->exists());

        return $code;
    }
}
