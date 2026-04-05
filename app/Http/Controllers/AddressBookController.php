<?php

namespace App\Http\Controllers;

use App\Http\Resources\AddressBookResource;
use App\Models\AddressBook;
use App\Models\Profile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AddressBookController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $ownerProfileId = $user->profile_id;
        if (! $ownerProfileId) {
            abort(422, 'Profil utilisateur requis pour le carnet d\'adresses.');
        }

        $query = AddressBook::query()
            ->where('owner_profile_id', $ownerProfileId)
            ->with(['contactProfile.city', 'contactProfile.state', 'contactProfile.country']);

        if ($request->filled('search')) {
            $term = '%'.$request->input('search').'%';
            $query->whereHas('contactProfile', function ($q) use ($term) {
                $q->where('first_name', 'like', $term)
                    ->orWhere('last_name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('phone', 'like', $term)
                    ->orWhereRaw("CONCAT(first_name, ' ', last_name) like ?", [$term]);
            });
        }

        $entries = $query->latest()->paginate(25)->withQueryString();

        return response()->json([
            'address_book' => AddressBookResource::collection($entries),
            'meta' => [
                'current_page' => $entries->currentPage(),
                'last_page' => $entries->lastPage(),
                'per_page' => $entries->perPage(),
                'total' => $entries->total(),
            ],
            'filters' => $request->only(['search']),
        ]);
    }

    /**
     * Zero-duplication logic: find or create a Profile, then attach to address book.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'phone' => ['required_without:email', 'nullable', 'string', 'max:32'],
            'email' => ['required_without:phone', 'nullable', 'email', 'max:255'],
            'phone_secondary' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:500'],
            'landmark' => ['nullable', 'string', 'max:500'],
            'zip_code' => ['nullable', 'string', 'max:16'],
            'country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'state_id' => [
                'nullable',
                'integer',
                Rule::exists('states', 'id')->when(
                    $request->filled('country_id'),
                    fn ($rule) => $rule->where('country_id', $request->integer('country_id'))
                ),
            ],
            'city_id' => [
                'nullable',
                'integer',
                Rule::exists('cities', 'id')->when(
                    $request->filled('state_id'),
                    fn ($rule) => $rule->where('state_id', $request->integer('state_id'))
                ),
            ],
            'alias' => ['nullable', 'string', 'max:100'],
            'is_default' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $entry = DB::transaction(function () use ($data, $user) {
            if (! $user->profile_id) {
                abort(422, 'Profil utilisateur requis pour le carnet d\'adresses.');
            }

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
                    'phone' => $data['phone'] ?? null,
                    'phone_secondary' => $data['phone_secondary'] ?? null,
                    'address' => $data['address'] ?? null,
                    'landmark' => $data['landmark'] ?? null,
                    'zip_code' => $data['zip_code'] ?? null,
                    'city_id' => $data['city_id'] ?? null,
                    'state_id' => $data['state_id'] ?? null,
                    'country_id' => $data['country_id'] ?? null,
                    'agency_id' => $user->agency_id,
                    'is_active' => true,
                    'is_client' => false,
                    'is_staff' => false,
                ]);
            }

            $existing = AddressBook::where('owner_profile_id', $user->profile_id)
                ->where('contact_profile_id', $profile->id)
                ->first();

            if ($existing) {
                return $existing;
            }

            if (! empty($data['is_default'])) {
                AddressBook::where('owner_profile_id', $user->profile_id)->update(['is_default' => false]);
            }

            return AddressBook::create([
                'owner_profile_id' => $user->profile_id,
                'contact_profile_id' => $profile->id,
                'alias' => $data['alias'] ?? null,
                'is_default' => $data['is_default'] ?? false,
                'notes' => $data['notes'] ?? null,
            ]);
        });

        $entry->load('contactProfile.city', 'contactProfile.state', 'contactProfile.country');

        return response()->json([
            'message' => 'Contact ajouté au carnet d\'adresses.',
            'entry' => new AddressBookResource($entry),
        ], 201);
    }

    public function show(AddressBook $addressBook): JsonResponse
    {
        $this->authorizeOwner(request()->user(), $addressBook);

        $addressBook->load('contactProfile.city', 'contactProfile.state', 'contactProfile.country', 'contactProfile.user');

        return response()->json([
            'entry' => new AddressBookResource($addressBook),
        ]);
    }

    public function update(Request $request, AddressBook $addressBook): JsonResponse
    {
        $this->authorizeOwner($request->user(), $addressBook);

        $data = $request->validate([
            'alias' => ['nullable', 'string', 'max:100'],
            'is_default' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'first_name' => ['sometimes', 'string', 'max:120'],
            'last_name' => ['sometimes', 'string', 'max:120'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone_secondary' => ['sometimes', 'nullable', 'string', 'max:32'],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'landmark' => ['sometimes', 'nullable', 'string', 'max:500'],
            'zip_code' => ['sometimes', 'nullable', 'string', 'max:16'],
            'country_id' => ['sometimes', 'nullable', 'integer', 'exists:countries,id'],
            'state_id' => ['sometimes', 'nullable', 'integer', 'exists:states,id'],
            'city_id' => ['sometimes', 'nullable', 'integer', 'exists:cities,id'],
        ]);

        DB::transaction(function () use ($data, $addressBook) {
            $pivotFields = ['alias', 'notes', 'is_default'];
            $pivotData = array_intersect_key($data, array_flip($pivotFields));

            if (! empty($pivotData['is_default'])) {
                AddressBook::where('owner_profile_id', $addressBook->owner_profile_id)
                    ->where('id', '!=', $addressBook->id)
                    ->update(['is_default' => false]);
            }

            if (! empty($pivotData)) {
                $addressBook->update($pivotData);
            }

            $profileFields = [
                'first_name', 'last_name', 'phone', 'email', 'phone_secondary',
                'address', 'landmark', 'zip_code', 'country_id', 'state_id', 'city_id',
            ];
            $profileData = array_intersect_key($data, array_flip($profileFields));

            if (! empty($profileData)) {
                $addressBook->contactProfile->update($profileData);
            }
        });

        $addressBook->load('contactProfile.city', 'contactProfile.state', 'contactProfile.country');

        return response()->json([
            'message' => 'Contact mis à jour.',
            'entry' => new AddressBookResource($addressBook),
        ]);
    }

    public function destroy(Request $request, AddressBook $addressBook): JsonResponse
    {
        $this->authorizeOwner($request->user(), $addressBook);

        $addressBook->delete();

        return response()->json(['message' => 'Contact retiré du carnet d\'adresses.']);
    }

    public function setDefault(Request $request, AddressBook $addressBook): JsonResponse
    {
        $this->authorizeOwner($request->user(), $addressBook);

        DB::transaction(function () use ($addressBook) {
            AddressBook::where('owner_profile_id', $addressBook->owner_profile_id)
                ->where('id', '!=', $addressBook->id)
                ->update(['is_default' => false]);

            $addressBook->update(['is_default' => true]);
        });

        return response()->json(['message' => 'Contact défini par défaut.']);
    }

    private function authorizeOwner($user, AddressBook $addressBook): void
    {
        if (! $user->profile_id || (int) $addressBook->owner_profile_id !== (int) $user->profile_id) {
            abort(403);
        }
    }
}
