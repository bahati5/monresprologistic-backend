<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\DenormalizesRecipientLocation;
use App\Http\Controllers\Concerns\NormalizesOptionalEmail;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\InteractsWithAgencyVisibility;
use App\Models\Agency;
use App\Models\CrmClient;
use App\Models\DeliveryTime;
use App\Models\Locker;
use App\Models\Recipient;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;

class ShipmentWizardController extends Controller
{
    use DenormalizesRecipientLocation;
    use NormalizesOptionalEmail;
    use InteractsWithAgencyVisibility;

    /**
     * Recherche fiches clients CRM (avec ou sans compte portail).
     */
    public function searchClients(Request $request): JsonResponse
    {
        $request->validate(['q' => ['required', 'string', 'min:1']]);

        $user = $request->user();
        $term = '%' . $request->input('q') . '%';

        $query = CrmClient::query()
            ->where(function ($q) use ($term) {
                $q->where('first_name', 'like', $term)
                    ->orWhere('last_name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('phone', 'like', $term)
                    ->orWhere('phone_mobile', 'like', $term)
                    ->orWhereRaw("CONCAT(first_name, ' ', last_name) like ?", [$term])
                    ->orWhereHas('locker', fn ($l) => $l->where('code', 'like', $term));
            })
            ->with(['locker', 'user'])
            ->limit(20);

        if (! $user->canAccessAllAgencies()) {
            $query->where('agency_id', $user->agency_id);
        }

        $rows = $query->get();

        return response()->json(
            $rows->map(fn (CrmClient $c) => [
                'id' => $c->id,
                'name' => $c->display_name,
                'email' => $c->email ?? $c->user?->email ?? '',
                'phone' => $c->phone,
                'locker_code' => $c->locker?->code,
                'has_portal' => $c->user_id !== null,
            ])
        );
    }

    /**
     * Destinataires filtrés par fiche client CRM (paramètre client_id = id CRM).
     */
    public function searchRecipients(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['required', 'string', 'min:1'],
            'client_id' => ['nullable', 'exists:crm_clients,id'],
        ]);

        $term = '%' . $request->input('q') . '%';
        $crmClientId = $request->input('client_id');

        $query = Recipient::query()
            ->where('is_active', true)
            ->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('phone', 'like', $term)
                    ->orWhere('city', 'like', $term);
            })
            ->limit(20);

        if ($crmClientId) {
            $query->where('crm_client_id', $crmClientId);
        }

        $recipients = $query->get(['id', 'crm_client_id', 'user_id', 'name', 'email', 'phone', 'city', 'country']);

        return response()->json($recipients);
    }

    /**
     * Création rapide d’une fiche client (+ portail optionnel).
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
                Rule::unique('users', 'email'),
            ],
            'phone' => ['required', 'string', 'max:32'],
            'phone_mobile' => ['nullable', 'string', 'max:32'],
            'password' => [
                Rule::requiredIf($createPortal),
                'nullable',
                Rules\Password::defaults(),
            ],
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

        $authUser = $request->user();

        $crm = null;
        $portalUser = null;

        DB::transaction(function () use ($data, $createPortal, $authUser, &$crm, &$portalUser) {
            $crm = CrmClient::create([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => $createPortal ? $data['email'] : ($data['email'] ?? null),
                'phone' => $data['phone'],
                'phone_mobile' => $data['phone_mobile'] ?? null,
                'agency_id' => $authUser->agency_id,
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
                    'agency_id' => $authUser->agency_id,
                    'email_verified_at' => now(),
                ]);
                $portalUser->assignRole('client');
                $crm->update(['user_id' => $portalUser->id]);
            }

            $prefix = Setting::getValue('locker_prefix', 'MRP');
            $digits = (int) Setting::getValue('locker_digits', '4');
            $mode = Setting::getValue('locker_mode', 'random');

            if ($mode === 'sequential') {
                $last = Locker::query()->orderByDesc('id')->value('code');
                $lastNum = $last ? (int) preg_replace('/\D/', '', $last) : 0;
                $code = $prefix . '-' . str_pad($lastNum + 1, $digits, '0', STR_PAD_LEFT);
            } else {
                do {
                    $code = $prefix . '-' . str_pad(random_int(0, pow(10, $digits) - 1), $digits, '0', STR_PAD_LEFT);
                } while (Locker::where('code', $code)->exists());
            }

            $template = Setting::getValue('locker_address_template', '');
            $formatted = str_replace('{{locker_code}}', $code, $template);

            Locker::create([
                'crm_client_id' => $crm->id,
                'user_id' => $portalUser?->id,
                'code' => $code,
                'formatted_address' => $formatted,
            ]);
        });

        $crm->load('locker');

        return response()->json([
            'id' => $crm->id,
            'name' => $crm->display_name,
            'email' => $crm->email ?? $crm->user?->email ?? '',
            'phone' => $crm->phone,
            'locker_code' => $crm->locker?->code,
            'has_portal' => $crm->user_id !== null,
        ], 201);
    }

    /**
     * Création rapide d’un destinataire rattaché à une fiche CRM.
     */
    public function quickCreateRecipient(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:32'],
            'phone_secondary' => ['nullable', 'string', 'max:32'],
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
            'crm_client_id' => ['required', 'exists:crm_clients,id'],
        ]);

        $authUser = $request->user();
        $crm = CrmClient::query()->findOrFail($data['crm_client_id']);
        if (! $authUser->canAccessAllAgencies() && (int) $crm->agency_id !== (int) $authUser->agency_id) {
            abort(403);
        }

        $crmId = $data['crm_client_id'];
        unset($data['crm_client_id']);

        $this->denormalizeRecipientLocationFromCityId($data);

        $recipient = Recipient::create([
            ...$data,
            'crm_client_id' => $crmId,
            'user_id' => $crm->user_id,
        ]);

        return response()->json([
            'id' => $recipient->id,
            'crm_client_id' => $recipient->crm_client_id,
            'user_id' => $recipient->user_id,
            'name' => $recipient->name,
            'email' => $recipient->email,
            'phone' => $recipient->phone,
            'city' => $recipient->city,
            'country' => $recipient->country,
        ], 201);
    }

    /**
     * Création rapide d’un délai de livraison pour un mode d’expédition (assistant expédition).
     */
    public function quickCreateDeliveryTime(Request $request): JsonResponse
    {
        $data = $request->validate([
            'shipping_mode_id' => ['required', 'integer', 'exists:shipping_modes,id'],
            'label' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $maxSort = (int) DeliveryTime::query()
            ->where('shipping_mode_id', $data['shipping_mode_id'])
            ->max('sort_order');

        $row = DeliveryTime::query()->create([
            'shipping_mode_id' => $data['shipping_mode_id'],
            'label' => $data['label'],
            'description' => $data['description'] ?? null,
            'is_active' => true,
            'sort_order' => $maxSort + 1,
        ]);

        return response()->json([
            'id' => $row->id,
            'label' => $row->label,
        ], 201);
    }

    /**
     * Get agencies list for dropdown.
     */
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
}
