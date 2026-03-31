<?php

namespace App\Http\Controllers;

use App\Models\Agency;
use App\Models\CrmClient;
use App\Models\Invoice;
use App\Models\Locker;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        $query = CrmClient::query()
            ->with(['agency', 'locker', 'user'])
            ->withCount(['recipients']);

        if (! $user->canAccessAllAgencies()) {
            $query->where('agency_id', $user->agency_id);
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('phone_mobile', 'like', "%{$search}%")
                    ->orWhereRaw("CONCAT(first_name, ' ', last_name) like ?", ["%{$search}%"])
                    ->orWhereHas('locker', fn ($l) => $l->where('code', 'like', "%{$search}%"));
            });
        }

        if ($request->filled('agency_id')) {
            $query->where('agency_id', $request->input('agency_id'));
        }

        if ($request->filled('status')) {
            if ($request->input('status') === 'active') {
                $query->where('is_active', true);
            } else {
                $query->where('is_active', false);
            }
        }

        if ($request->filled('portal')) {
            if ($request->input('portal') === 'yes') {
                $query->whereNotNull('user_id');
            } elseif ($request->input('portal') === 'no') {
                $query->whereNull('user_id');
            }
        }

        $clients = $query->latest()->paginate(25)->withQueryString();

        return response()->json([
            'clients' => $clients,
            'filters' => $request->only(['search', 'agency_id', 'status', 'portal']),
            'agencies' => $this->getAgencies($user),
        ]);
    }

    public function show(Request $request, CrmClient $client): JsonResponse
    {
        $this->authorizeAgency($request->user(), $client);

        $client->load(['agency', 'locker', 'user', 'billingCountry', 'billingState', 'billingCity', 'recipients']);

        $shipments = Shipment::query()
            ->where('sender_client_id', $client->id)
            ->with(['status'])
            ->latest()
            ->take(20)
            ->get();

        $invoices = $client->user_id
            ? Invoice::query()
                ->where('user_id', $client->user_id)
                ->latest()
                ->take(20)
                ->get()
            : collect();

        $financeSummary = $client->user_id ? [
            'total_invoiced' => Invoice::where('user_id', $client->user_id)->sum('amount'),
            'total_paid' => Invoice::where('user_id', $client->user_id)->where('status', 'paid')->sum('amount'),
            'total_pending' => Invoice::where('user_id', $client->user_id)->where('status', 'pending')->sum('amount'),
        ] : [
            'total_invoiced' => 0,
            'total_paid' => 0,
            'total_pending' => 0,
        ];

        return response()->json([
            'client' => $client,
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
                Rule::unique('users', 'email'),
            ],
            'phone' => ['required', 'string', 'max:32'],
            'phone_mobile' => ['nullable', 'string', 'max:32'],
            'password' => [
                Rule::requiredIf($createPortal),
                'nullable',
                Rules\Password::defaults(),
            ],
            'agency_id' => ['nullable', 'exists:agencies,id'],
            'billing_address' => ['nullable', 'string', 'max:500'],
            'billing_postal_code' => ['nullable', 'string', 'max:16'],
            'billing_country_id' => ['required', 'integer', 'exists:countries,id'],
            'billing_state_id' => [
                'required',
                'integer',
                Rule::exists('states', 'id')->where('country_id', $request->integer('billing_country_id')),
            ],
            'billing_city_id' => [
                'required',
                'integer',
                Rule::exists('cities', 'id')->where('state_id', $request->integer('billing_state_id')),
            ],
        ]);

        $agencyId = $data['agency_id'] ?? $request->user()->agency_id;

        $crm = null;
        $portalUser = null;

        \Illuminate\Support\Facades\DB::transaction(function () use ($data, $createPortal, $agencyId, $request, &$crm, &$portalUser) {
            $crm = CrmClient::create([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => $createPortal ? $data['email'] : ($data['email'] ?? null),
                'phone' => $data['phone'],
                'phone_mobile' => $data['phone_mobile'] ?? null,
                'agency_id' => $agencyId,
                'billing_address' => $data['billing_address'] ?? null,
                'billing_postal_code' => $data['billing_postal_code'] ?? null,
                'billing_country_id' => $data['billing_country_id'],
                'billing_state_id' => $data['billing_state_id'],
                'billing_city_id' => $data['billing_city_id'],
                'user_id' => null,
                'is_active' => true,
            ]);

            if ($createPortal) {
                $fullName = trim($data['first_name'] . ' ' . $data['last_name']);
                $portalUser = User::create([
                    'name' => $fullName,
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'email' => $data['email'],
                    'phone' => $data['phone'],
                    'phone_mobile' => $data['phone_mobile'] ?? null,
                    'billing_address' => $data['billing_address'] ?? null,
                    'billing_postal_code' => $data['billing_postal_code'] ?? null,
                    'billing_country_id' => $data['billing_country_id'],
                    'billing_state_id' => $data['billing_state_id'],
                    'billing_city_id' => $data['billing_city_id'],
                    'password' => Hash::make($data['password']),
                    'agency_id' => $agencyId,
                    'email_verified_at' => now(),
                ]);
                $portalUser->assignRole('client');
                $crm->update(['user_id' => $portalUser->id]);
            }

            $prefix = \App\Models\Setting::getValue('locker_prefix', 'MRP');
            $digits = (int) \App\Models\Setting::getValue('locker_digits', '4');
            $mode = \App\Models\Setting::getValue('locker_mode', 'random');

            if ($mode === 'sequential') {
                $last = Locker::query()->orderByDesc('id')->value('code');
                $lastNum = $last ? (int) preg_replace('/\D/', '', $last) : 0;
                $code = $prefix . '-' . str_pad($lastNum + 1, $digits, '0', STR_PAD_LEFT);
            } else {
                do {
                    $code = $prefix . '-' . str_pad(random_int(0, pow(10, $digits) - 1), $digits, '0', STR_PAD_LEFT);
                } while (Locker::where('code', $code)->exists());
            }

            $template = \App\Models\Setting::getValue('locker_address_template', '');
            $formatted = str_replace('{{locker_code}}', $code, $template);

            Locker::create([
                'crm_client_id' => $crm->id,
                'user_id' => $portalUser?->id,
                'code' => $code,
                'formatted_address' => $formatted,
            ]);
        });

        $crm->load('locker');
        $suffix = $createPortal ? ' (compte portail créé)' : ' (sans compte portail)';
        $code = $crm->locker?->code ?? '';

        return back()->with('success', 'Fiche client créée avec casier ' . $code . $suffix);
    }

    public function update(Request $request, CrmClient $client): JsonResponse
    {
        $this->authorizeAgency($request->user(), $client);

        $request->merge([
            'email' => $this->normalizeOptionalEmail($request->input('email')),
        ]);

        $emailRules = $client->user_id
            ? ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($client->user_id)]
            : ['nullable', 'email', 'max:255', Rule::unique('users', 'email')];

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'email' => $emailRules,
            'phone' => ['required', 'string', 'max:32'],
            'phone_mobile' => ['nullable', 'string', 'max:32'],
            'agency_id' => ['nullable', 'exists:agencies,id'],
            'billing_address' => ['nullable', 'string', 'max:500'],
            'billing_postal_code' => ['nullable', 'string', 'max:16'],
            'billing_country_id' => ['required', 'integer', 'exists:countries,id'],
            'billing_state_id' => [
                'required',
                'integer',
                Rule::exists('states', 'id')->where('country_id', $request->integer('billing_country_id')),
            ],
            'billing_city_id' => [
                'required',
                'integer',
                Rule::exists('cities', 'id')->where('state_id', $request->integer('billing_state_id')),
            ],
        ]);

        $client->update($data);

        if ($client->user_id) {
            $u = User::find($client->user_id);
            if ($u) {
                $u->update([
                    'name' => trim($data['first_name'] . ' ' . $data['last_name']),
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'email' => $data['email'],
                    'phone' => $data['phone'],
                    'phone_mobile' => $data['phone_mobile'] ?? null,
                    'billing_address' => $data['billing_address'] ?? null,
                    'billing_postal_code' => $data['billing_postal_code'] ?? null,
                    'billing_country_id' => $data['billing_country_id'],
                    'billing_state_id' => $data['billing_state_id'],
                    'billing_city_id' => $data['billing_city_id'],
                    'agency_id' => $data['agency_id'] ?? $u->agency_id,
                ]);
            }
        }

        return response()->json(['message' => 'Client mis à jour.']);
    }

    public function toggleActive(Request $request, CrmClient $client): JsonResponse
    {
        $this->authorizeAgency($request->user(), $client);

        $nextActive = ! $client->is_active;
        $client->update(['is_active' => $nextActive]);

        if ($client->user_id) {
            $u = User::find($client->user_id);
            if ($u) {
                if ($nextActive) {
                    $u->update(['email_verified_at' => now()]);
                } else {
                    $u->update(['email_verified_at' => null]);
                }
            }
        }

        return response()->json(['message' => 'Statut du client modifié.']);
    }

    /**
     * Crée un compte portail pour une fiche sans utilisateur.
     */
    public function createPortal(Request $request, CrmClient $client): JsonResponse
    {
        $this->authorizeAgency($request->user(), $client);

        if ($client->user_id) {
            return back()->withErrors(['portal' => 'Ce client a déjà un compte portail.']);
        }

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', Rules\Password::defaults()],
        ]);

        $portalUser = User::create([
            'name' => $client->display_name,
            'first_name' => $client->first_name,
            'last_name' => $client->last_name,
            'email' => $data['email'],
            'phone' => $client->phone,
            'phone_mobile' => $client->phone_mobile,
            'billing_address' => $client->billing_address,
            'billing_postal_code' => $client->billing_postal_code,
            'billing_country_id' => $client->billing_country_id,
            'billing_state_id' => $client->billing_state_id,
            'billing_city_id' => $client->billing_city_id,
            'password' => Hash::make($data['password']),
            'agency_id' => $client->agency_id,
            'email_verified_at' => now(),
        ]);
        $portalUser->assignRole('client');

        $client->update([
            'user_id' => $portalUser->id,
            'email' => $data['email'],
        ]);

        $client->locker?->update(['user_id' => $portalUser->id]);

        return response()->json(['message' => 'Compte portail créé pour ce client.']);
    }

    private function authorizeAgency(User $user, CrmClient $client): void
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
}
