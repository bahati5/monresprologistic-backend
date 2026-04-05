<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\Locker;
use App\Models\Profile;
use App\Models\Setting;
use App\Models\User;
use App\Support\LockerNumberGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        return response()->json([
            'user' => new UserResource($request->user()->load('profile')),
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:32'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = DB::transaction(function () use ($validated) {
            $profile = Profile::query()
                ->where('email', $validated['email'])
                ->orWhere('phone', $validated['phone'])
                ->first();

            if ($profile && $profile->user) {
                throw ValidationException::withMessages([
                    'email' => 'Un compte existe déjà avec cet email ou ce téléphone.',
                ]);
            }

            if (! $profile) {
                $profile = Profile::create([
                    'first_name' => $validated['first_name'],
                    'last_name' => $validated['last_name'],
                    'email' => $validated['email'],
                    'phone' => $validated['phone'],
                    'is_active' => true,
                    'is_client' => true,
                    'is_staff' => false,
                ]);
            } else {
                $profile->update([
                    'first_name' => $validated['first_name'],
                    'last_name' => $validated['last_name'],
                    'email' => $validated['email'],
                    'phone' => $validated['phone'],
                ]);
            }

            $lockerNumber = LockerNumberGenerator::generate();

            $newUser = User::create([
                'profile_id' => $profile->id,
                'name' => trim($validated['first_name'].' '.$validated['last_name']),
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'],
                'password' => Hash::make($validated['password']),
                'locker_number' => $lockerNumber,
            ]);

            $newUser->assignRole('client');

            $prefix = Setting::getValue('locker_prefix', 'MRP');
            $template = Setting::getValue('locker_address_template', '');
            $formatted = str_replace('{{locker_code}}', $lockerNumber, $template);

            Locker::create([
                'profile_id' => $profile->id,
                'user_id' => $newUser->id,
                'code' => $lockerNumber,
                'formatted_address' => $formatted,
            ]);

            return $newUser;
        });

        Auth::login($user);

        return response()->json([
            'user' => new UserResource($user->load('profile')),
        ], 201);
    }

    public function user(Request $request): JsonResponse
    {
        return response()->json([
            'user' => new UserResource($request->user()->load('profile')),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logged out']);
    }

    public function createToken(Request $request): JsonResponse
    {
        $request->validate([
            'device_name' => ['required', 'string', 'max:255'],
        ]);

        $user = $request->user();

        $token = $user->createToken(
            $request->device_name,
            ['*'],
        )->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => new UserResource($user->load('profile')),
        ]);
    }
}
