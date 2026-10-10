<?php

namespace Tests\Feature;

use App\Mail\PasswordResetLink;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $alice;
    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create([
            'name' => 'Acme Supplies',
            'currency' => 'USD',
            'timezone' => 'America/New_York',
        ]);

        $this->alice = User::create([
            'organization_id' => $this->org->id,
            'name' => 'Alice Admin',
            'email' => 'alice@acme.com',
            'password' => bcrypt('old-password'),
            'role' => 'admin',
        ]);

        $this->bob = User::create([
            'organization_id' => $this->org->id,
            'name' => 'Bob Staff',
            'email' => 'bob@acme.com',
            'password' => bcrypt('old-password'),
            'role' => 'staff',
        ]);
    }

    // ---------------------------------------------------------------- forgot

    public function test_forgot_password_emails_a_reset_link_to_a_registered_user(): void
    {
        Mail::fake();

        $this->requestResetLink('alice@acme.com');

        Mail::assertSent(PasswordResetLink::class, 'alice@acme.com');
    }

    /**
     * The endpoint must not confirm whether an address has an account, so the
     * unknown-address response is byte-identical to the known-address one.
     */
    public function test_forgot_password_is_indistinguishable_for_an_unknown_address(): void
    {
        Mail::fake();

        $known = $this->postJson('/api/auth/forgot', ['email' => 'alice@acme.com']);
        $unknown = $this->postJson('/api/auth/forgot', ['email' => 'nobody@acme.com']);

        $known->assertOk();
        $unknown->assertOk();
        $this->assertSame($known->json(), $unknown->json());

        Mail::assertSentCount(1);
        Mail::assertNotSent(PasswordResetLink::class, 'nobody@acme.com');
    }

    public function test_forgot_password_must_be_a_valid_email_address(): void
    {
        Mail::fake();

        $this->postJson('/api/auth/forgot', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        Mail::assertNothingSent();
    }

    public function test_forgot_password_is_a_generic_success_when_mail_delivery_fails(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP is down'));

        // Same body as a successful request — a mail outage must not become an
        // oracle for which addresses are registered.
        $this->requestResetLink('alice@acme.com');
    }

    public function test_reset_link_carries_the_email_and_a_single_use_token(): void
    {
        Mail::fake();

        $this->requestResetLink('alice@acme.com');
        $link = $this->capturedLink();

        $this->assertNotSame('', $link['token']);
        $this->assertSame('alice@acme.com', $link['email']);

        // The plaintext token is never stored — only its hash.
        $stored = DB::table('password_reset_tokens')->where('email', 'alice@acme.com')->value('token');
        $this->assertNotSame($link['token'], $stored);
        $this->assertTrue(Hash::check($link['token'], $stored));
    }

    public function test_reset_link_is_built_from_app_url_not_the_request_host(): void
    {
        config(['app.url' => 'https://portal.example.com']);
        Mail::fake();

        // A spoofed Host header must not be able to point the emailed link at
        // an attacker-controlled domain.
        $this->postJson('/api/auth/forgot', ['email' => 'alice@acme.com'], ['Host' => 'evil.example.net'])
            ->assertOk();

        $this->assertStringStartsWith('https://portal.example.com/reset-password?', $this->capturedLink()['url']);
    }

    public function test_forgot_password_does_not_reissue_a_link_within_the_throttle_window(): void
    {
        Mail::fake();

        $this->requestResetLink('alice@acme.com');
        $this->requestResetLink('alice@acme.com');

        Mail::assertSentCount(1);
    }

    // ----------------------------------------------------------------- reset

    public function test_a_valid_token_sets_the_new_password_and_consumes_the_token(): void
    {
        $token = $this->issueTokenFor('alice@acme.com');

        $this->postJson('/api/auth/reset', [
            'token' => $token,
            'email' => 'alice@acme.com',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertOk()->assertJson(['ok' => true]);

        $alice = $this->alice->fresh();
        $this->assertTrue(Hash::check('brand-new-password', $alice->password));
        $this->assertFalse(Hash::check('old-password', $alice->password));

        // Single use: the token row is gone, so replaying the same link fails.
        $this->assertSame(0, DB::table('password_reset_tokens')->where('email', 'alice@acme.com')->count());

        $this->postJson('/api/auth/reset', [
            'token' => $token,
            'email' => 'alice@acme.com',
            'password' => 'attacker-password',
            'password_confirmation' => 'attacker-password',
        ])->assertStatus(422);

        $this->assertFalse(Hash::check('attacker-password', $this->alice->fresh()->password));
    }

    public function test_reset_terminates_every_existing_session(): void
    {
        DB::table('sessions')->insert([
            [
                'id' => 'alice-laptop',
                'user_id' => $this->alice->id,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'phpunit',
                'payload' => base64_encode(serialize([])),
                'last_activity' => now()->getTimestamp(),
            ],
            [
                'id' => 'bob-laptop',
                'user_id' => $this->bob->id,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'phpunit',
                'payload' => base64_encode(serialize([])),
                'last_activity' => now()->getTimestamp(),
            ],
        ]);

        $token = $this->issueTokenFor('alice@acme.com');

        $this->postJson('/api/auth/reset', [
            'token' => $token,
            'email' => 'alice@acme.com',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertOk();

        $this->assertSame(0, DB::table('sessions')->where('user_id', $this->alice->id)->count());
        $this->assertSame(1, DB::table('sessions')->where('user_id', $this->bob->id)->count());
    }

    public function test_reset_rotates_the_remember_token(): void
    {
        $this->alice->forceFill(['remember_token' => 'stolen-remember-token'])->save();

        $token = $this->issueTokenFor('alice@acme.com');

        $this->postJson('/api/auth/reset', [
            'token' => $token,
            'email' => 'alice@acme.com',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertOk();

        $this->assertNotSame('stolen-remember-token', $this->alice->fresh()->remember_token);
    }

    public function test_reset_rejects_an_invalid_token(): void
    {
        $this->postJson('/api/auth/reset', [
            'token' => 'not-a-real-token',
            'email' => 'alice@acme.com',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertStatus(422);

        $this->assertTrue(Hash::check('old-password', $this->alice->fresh()->password));
    }

    public function test_reset_rejects_a_token_issued_for_a_different_address(): void
    {
        $token = $this->issueTokenFor('alice@acme.com');

        $this->postJson('/api/auth/reset', [
            'token' => $token,
            'email' => 'bob@acme.com',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertStatus(422);

        $this->assertTrue(Hash::check('old-password', $this->bob->fresh()->password));
    }

    public function test_reset_requires_a_matching_password_confirmation(): void
    {
        $token = $this->issueTokenFor('alice@acme.com');

        $this->postJson('/api/auth/reset', [
            'token' => $token,
            'email' => 'alice@acme.com',
            'password' => 'brand-new-password',
            'password_confirmation' => 'different-password',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->assertTrue(Hash::check('old-password', $this->alice->fresh()->password));
    }

    public function test_reset_enforces_a_minimum_password_length(): void
    {
        $token = $this->issueTokenFor('alice@acme.com');

        $this->postJson('/api/auth/reset', [
            'token' => $token,
            'email' => 'alice@acme.com',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->assertTrue(Hash::check('old-password', $this->alice->fresh()->password));
    }

    public function test_the_new_password_can_be_used_to_sign_in(): void
    {
        $token = $this->issueTokenFor('alice@acme.com');

        $this->postJson('/api/auth/reset', [
            'token' => $token,
            'email' => 'alice@acme.com',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertOk();

        $this->postJson('/api/auth/login', [
            'email' => 'alice@acme.com',
            'password' => 'brand-new-password',
        ])->assertOk()->assertJsonPath('user.email', 'alice@acme.com');

        $this->postJson('/api/auth/login', [
            'email' => 'alice@acme.com',
            'password' => 'old-password',
        ])->assertStatus(422);
    }

    // ------------------------------------------------------------------ view

    /**
     * Mail::fake() never renders the template, so a broken mail view would only
     * be discovered in production. Render it for real here.
     */
    public function test_the_reset_email_renders_the_link(): void
    {
        $html = (new PasswordResetLink(
            resetUrl: 'https://portal.example.com/reset-password?token=abc123&email=alice%40acme.com',
            expiresMinutes: 60,
        ))->render();

        $this->assertStringContainsString('https://portal.example.com/reset-password?token=abc123', $html);
        $this->assertStringContainsString('expires in', $html);
        $this->assertStringContainsString('60 minutes', $html);
    }

    // --------------------------------------------------------------- helpers

    private function requestResetLink(string $email): void
    {
        $this->postJson('/api/auth/forgot', ['email' => $email])
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    /** Issue a token through the real endpoint and read it back off the email. */
    private function issueTokenFor(string $email): string
    {
        Mail::fake();

        $this->requestResetLink($email);

        return $this->capturedLink()['token'];
    }

    /** @return array{url: string, token: string, email: string} */
    private function capturedLink(): array
    {
        $mailable = Mail::sent(PasswordResetLink::class)->first();
        $this->assertNotNull($mailable, 'No password reset email was sent.');

        $query = [];
        parse_str((string) parse_url($mailable->resetUrl, PHP_URL_QUERY), $query);

        return [
            'url' => $mailable->resetUrl,
            'token' => (string) ($query['token'] ?? ''),
            'email' => (string) ($query['email'] ?? ''),
        ];
    }
}
