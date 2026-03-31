<?php

namespace App\Http\Controllers;

use App\Models\CrmClient;
use App\Models\Recipient;
use App\Models\RecipientAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RecipientController extends Controller
{
    use Concerns\DenormalizesRecipientLocation;
    use Concerns\InteractsWithAgencyVisibility;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Recipient::query()->with(['user', 'crmClient', 'addresses']);

        if ($user->hasRole('client')) {
            $crm = CrmClient::query()->where('user_id', $user->id)->first();
            if ($crm) {
                $query->where('crm_client_id', $crm->id);
            } else {
                $query->whereRaw('1 = 0');
            }
        } elseif (! $user->canAccessAllAgencies()) {
            $query->where(function ($q) use ($user) {
                $q->whereHas('user', fn ($u) => $u->where('agency_id', $user->agency_id))
                    ->orWhereHas('crmClient', fn ($c) => $c->where('agency_id', $user->agency_id));
            });
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%");
            });
        }

        $recipients = $query->latest()->paginate(25)->withQueryString();

        return response()->json([
            'recipients' => $recipients,
            'filters' => $request->only(['search']),
            'isClient' => $user->hasRole('client'),
        ]);
    }

    public function create(Request $request): JsonResponse
    {
        $user = $request->user();
        $crmClients = [];
        if (! $user->hasRole('client')) {
            $q = CrmClient::query()->orderBy('last_name')->orderBy('first_name')->limit(500);
            if (! $user->canAccessAllAgencies()) {
                $q->where('agency_id', $user->agency_id);
            }
            $crmClients = $q->get(['id', 'first_name', 'last_name', 'email']);
        }

        return response()->json([
            'crmClients' => $crmClients,
            'isClientUser' => $user->hasRole('client'),
            'defaultCrmClientId' => $request->filled('crm_client_id') ? (int) $request->query('crm_client_id') : null,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $extra = [];
        if ($user->hasRole('client')) {
            $extra['crm_client_id'] = ['prohibited'];
        } else {
            $extra['crm_client_id'] = ['required', 'exists:crm_clients,id'];
        }

        $data = $request->validate(array_merge($this->recipientBaseRules($request), $extra));

        if ($user->hasRole('client')) {
            $crm = CrmClient::query()->where('user_id', $user->id)->firstOrFail();
        } else {
            $crm = CrmClient::query()->findOrFail($data['crm_client_id']);
            $this->authorizeCrmAgency($user, $crm);
        }

        unset($data['crm_client_id']);

        $this->denormalizeRecipientLocationFromCityId($data);

        Recipient::create([
            ...$data,
            'crm_client_id' => $crm->id,
            'user_id' => $crm->user_id,
        ]);

        return response()->json(['message' => 'Destinataire créé.']);
    }

    public function update(Request $request, Recipient $recipient): JsonResponse
    {
        $this->authorizeAccess($request->user(), $recipient);

        $data = $request->validate(array_merge($this->recipientBaseRules($request), [
            'is_active' => ['boolean'],
        ]));

        $this->denormalizeRecipientLocationFromCityId($data);

        $recipient->update($data);

        return response()->json(['message' => 'Destinataire mis à jour.']);
    }

    public function destroy(Request $request, Recipient $recipient): JsonResponse
    {
        $this->authorizeAccess($request->user(), $recipient);

        $recipient->delete();

        return response()->json(['message' => 'Destinataire supprimé.']);
    }

    public function storeAddress(Request $request, Recipient $recipient): JsonResponse
    {
        $this->authorizeAccess($request->user(), $recipient);

        $data = $request->validate([
            'label' => ['required', 'string', 'max:100'],
            'address' => ['required', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
            'zip_code' => ['nullable', 'string', 'max:16'],
            'phone' => ['nullable', 'string', 'max:32'],
            'is_default' => ['boolean'],
        ]);

        if (! empty($data['is_default'])) {
            $recipient->addresses()->update(['is_default' => false]);
        }

        $recipient->addresses()->create($data);

        return response()->json(['message' => 'Adresse ajoutée.']);
    }

    public function destroyAddress(Request $request, RecipientAddress $address): JsonResponse
    {
        $this->authorizeAccess($request->user(), $address->recipient);

        $address->delete();

        return response()->json(['message' => 'Adresse supprimée.']);
    }

    private function authorizeAccess($user, Recipient $recipient): void
    {
        if ($user->hasRole('client')) {
            $crm = CrmClient::query()->where('user_id', $user->id)->first();
            if (! $crm || (int) $recipient->crm_client_id !== (int) $crm->id) {
                abort(403);
            }
        }
    }

    private function authorizeCrmAgency($user, CrmClient $crm): void
    {
        if (! $user->canAccessAllAgencies() && (int) $crm->agency_id !== (int) $user->agency_id) {
            abort(403);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function recipientBaseRules(Request $request): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:32'],
            'phone_secondary' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:500'],
            'landmark' => ['nullable', 'string', 'max:500'],
            'zip_code' => ['nullable', 'string', 'max:16'],
            'notes' => ['nullable', 'string', 'max:1000'],
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
        ];
    }
}
