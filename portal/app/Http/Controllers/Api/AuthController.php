<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:sp_users,email',
            'password' => 'required|string|min:8',
            'organization_name' => 'required|string|max:255',
            'currency' => 'nullable|string|max:3',
            'timezone' => 'nullable|string|max:64',
        ]);

        $user = DB::transaction(function () use ($data) {
            $currency = strtoupper(trim((string) ($data['currency'] ?? 'BDT')));
            if (empty($currency)) {
                $currency = 'BDT';
            }

            $org = Organization::create([
                'name' => $data['organization_name'],
                'currency' => $currency,
                'timezone' => $data['timezone'] ?? 'UTC',
            ]);

            $settings = app(SettingService::class);
            $settings->set($org->id, 'allow_negative_stock', false);
            $settings->set($org->id, 'warning_cooldown_hours', 24);

            return User::create([
                'organization_id' => $org->id,
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'role' => User::ROLE_ADMIN,
                'is_active' => true,
            ]);
        });

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        $user->last_login_at = now();
        $user->save();

        Auth::guard('web')->login($user);

        return response()->json(['user' => $this->userPayload($user)], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! $user->is_active || ! Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Invalid credentials or inactive account.'], 422);
        }

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        $user->last_login_at = now();
        $user->save();

        Auth::guard('web')->login($user);

        return response()->json(['user' => $this->userPayload($user)]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->userPayload($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['ok' => true]);
    }

    public function sendResetLink(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);

        $status = Password::sendResetLink($request->only('email'));

        return $status === Password::RESET_LINK_SENT
            ? response()->json(['ok' => true])
            : response()->json(['error' => __($status)], 422);
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'organization' => $user->organization?->only(['id', 'name', 'currency', 'timezone']),
        ];
    }
}
