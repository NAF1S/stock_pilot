<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\PasswordResetLink;
use App\Models\Organization;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

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

    /**
     * Email a password reset link.
     *
     * Always answers with the same generic body, whether or not the address
     * belongs to an account (and whether or not the send succeeded) so this
     * endpoint cannot be used to enumerate registered users.
     */
    public function sendResetLink(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => 'required|email']);

        try {
            // The broker owns token generation + the per-account throttle; the
            // callback overrides only how the link is delivered so the email
            // can use our own Blade template instead of the framework default.
            Password::sendResetLink($data, function (User $user, string $token) {
                Mail::to($user->email)->send(new PasswordResetLink(
                    resetUrl: $this->passwordResetUrl($user->email, $token),
                    expiresMinutes: (int) config('auth.passwords.users.expire'),
                ));
            });
        } catch (\Throwable $e) {
            // A mail outage must not produce a different response — that would
            // leak whether the address exists. Log it and stay generic.
            report($e);
        }

        return response()->json([
            'ok' => true,
            'message' => 'If an account exists for that email address, a password reset link is on its way.',
        ]);
    }

    /**
     * Complete a password reset with the token from the emailed link.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => ['required', 'string', 'confirmed', PasswordRule::min(8)],
        ]);

        $status = Password::reset($data, function (User $user, string $password) {
            $user->forceFill([
                'password' => Hash::make($password),
                'remember_token' => Str::random(60),
            ])->save();

            // OWASP: a password change must terminate other sessions, so a
            // stolen cookie cannot outlive the recovery that revoked it.
            if (Schema::hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }
        });

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'error' => 'This password reset link is invalid or has expired. Please request a new one.',
            ], 422);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Build the SPA reset URL from APP_URL.
     *
     * Deliberately not derived from the request: a spoofed Host header would
     * otherwise let an attacker make the emailed link point at their domain.
     */
    private function passwordResetUrl(string $email, string $token): string
    {
        $base = rtrim((string) config('app.url'), '/');

        return $base.'/reset-password?'.http_build_query([
            'token' => $token,
            'email' => $email,
        ]);
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
