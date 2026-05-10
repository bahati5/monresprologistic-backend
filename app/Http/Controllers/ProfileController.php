<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Http\Resources\UserResource;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ProfileController extends Controller
{
    /**
     * Display the user's profile.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->load('profile.country', 'profile.city', 'profile.state');

        return response()->json([
            'user' => new UserResource($user),
            'profile' => $user->profile ? [
                'id' => $user->profile->id,
                'first_name' => $user->profile->first_name,
                'last_name' => $user->profile->last_name,
                'phone' => $user->profile->phone,
                'phone_secondary' => $user->profile->phone_secondary,
                'address' => $user->profile->address,
                'landmark' => $user->profile->landmark,
                'zip_code' => $user->profile->zip_code,
                'city' => $user->profile->city?->name,
                'state' => $user->profile->state?->name,
                'country' => $user->profile->country ? [
                    'id' => $user->profile->country->id,
                    'name' => $user->profile->country->name,
                    'emoji' => $user->profile->country->emoji,
                ] : null,
            ] : null,
            'mustVerifyEmail' => $user instanceof MustVerifyEmail,
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): JsonResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return response()->json([
            'user' => new UserResource($request->user()),
            'message' => 'Profil mis à jour.',
        ]);
    }

    /**
     * Delete the user's account.
     */
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
