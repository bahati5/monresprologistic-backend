<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Http\Resources\ProfileResource;
use App\Http\Resources\UserResource;
use App\Models\Agency;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProfileController extends Controller
{
    private const PROFILE_FIELDS = [
        'first_name',
        'last_name',
        'email',
        'phone',
        'phone_secondary',
        'address',
        'landmark',
        'zip_code',
        'country_id',
        'state_id',
        'city_id',
    ];

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->load(['profile.country', 'profile.city', 'profile.state']);

        return response()->json([
            'user' => new UserResource($user),
            'profile' => $user->profile
                ? (new ProfileResource($user->profile))->resolve()
                : null,
            'mustVerifyEmail' => $user instanceof MustVerifyEmail,
        ]);
    }

    public function update(ProfileUpdateRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validated();

        DB::transaction(function () use ($user, $data) {
            $user->fill([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? $user->phone,
                'theme_preference' => $data['theme_preference'] ?? $user->theme_preference,
            ]);

            if ($user->isDirty('email')) {
                $user->email_verified_at = null;
            }

            $user->save();

            $profileUpdates = [];
            foreach (self::PROFILE_FIELDS as $key) {
                if (array_key_exists($key, $data)) {
                    $profileUpdates[$key] = $data[$key];
                }
            }

            if ($user->profile_id && $user->profile) {
                $user->profile->update($profileUpdates);
            } else {
                $parts = preg_split('/\s+/', trim($data['name']), 2, PREG_SPLIT_NO_EMPTY) ?: ['', ''];
                $agencyId = $user->agency_id ?? Agency::query()->orderBy('id')->value('id');

                $profile = Profile::query()->create([
                    'first_name' => $data['first_name'] ?? $parts[0],
                    'last_name' => $data['last_name'] ?? ($parts[1] ?? ''),
                    'email' => $data['email'],
                    'phone' => $data['phone'] ?? null,
                    'phone_secondary' => $data['phone_secondary'] ?? null,
                    'address' => $data['address'] ?? null,
                    'landmark' => $data['landmark'] ?? null,
                    'zip_code' => $data['zip_code'] ?? null,
                    'country_id' => $data['country_id'] ?? null,
                    'state_id' => $data['state_id'] ?? null,
                    'city_id' => $data['city_id'] ?? null,
                    'agency_id' => $agencyId,
                    'is_active' => true,
                    'is_client' => $user->hasRole('client'),
                    'is_staff' => ! $user->hasRole('client'),
                ]);

                $user->update(['profile_id' => $profile->id]);
            }
        });

        $user->refresh()->load(['profile.country', 'profile.city', 'profile.state']);

        return response()->json([
            'user' => new UserResource($user),
            'profile' => $user->profile
                ? (new ProfileResource($user->profile))->resolve()
                : null,
            'message' => 'Profil mis à jour.',
        ]);
    }

    public function uploadAvatar(Request $request): JsonResponse
    {
        $request->validate([
            'avatar' => ['required', 'image', 'max:4096'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $file = $request->file('avatar');
        $ext = strtolower((string) ($file?->getClientOriginalExtension() ?: 'jpg'));
        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            $ext = 'jpg';
        }

        $dir = 'avatars/'.$user->id;
        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
        }

        $stored = $file->storeAs($dir, 'photo.'.$ext, 'public');
        $user->update(['avatar_path' => $stored]);
        $user->refresh();

        $avatarUrl = Storage::disk('public')->url($stored);

        return response()->json([
            'user' => new UserResource($user->load('profile')),
            'avatar_url' => $avatarUrl,
            'message' => 'Photo de profil mise à jour.',
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::guard('web')->logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Compte supprimé.']);
    }
}
