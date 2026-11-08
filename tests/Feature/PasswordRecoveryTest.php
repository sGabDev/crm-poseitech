<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ResetPassword;
use Illuminate\Auth\Events\PasswordReset as PasswordResetEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class PasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private function credentials(User $user, string $token, array $overrides = []): array
    {
        return array_replace(['email' => $user->email, 'token' => $token,
            'password' => 'NovaSenha12345', 'password_confirmation' => 'NovaSenha12345'], $overrides);
    }

    public function test_request_normalizes_email_and_sends_a_single_use_link(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'pessoa@example.test']);
        $this->post('/forgot-password', ['email' => ' PESSOA@EXAMPLE.TEST '])
            ->assertRedirect('/forgot-password')->assertSessionHas('success');
        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $this->assertTrue(Password::tokenExists($user, $notification->token));
            $this->assertNotSame($notification->token, DB::table('password_reset_tokens')->value('token'));

            return true;
        });
        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('success');
        Notification::assertSentToTimes($user, ResetPassword::class, 1);
    }

    public function test_unknown_and_inactive_accounts_receive_the_same_public_response(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'inactive@example.test', 'active' => false]);
        $this->post('/forgot-password', ['email' => 'unknown@example.test'])->assertSessionHas('success');
        $message = session('success');
        $this->post('/forgot-password', ['email' => 'inactive@example.test'])->assertSessionHas('success', $message);
        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_password_reset_revokes_sessions_and_remember_token_and_cannot_be_reused(): void
    {
        Event::fake([PasswordResetEvent::class]);
        $user = User::factory()->create(['must_change_password' => true]);
        $other = User::factory()->create();
        $remember = $user->remember_token;
        foreach ([$user, $other] as $account) {
            DB::table('sessions')->insert(['id' => 'session-'.$account->id, 'user_id' => $account->id,
                'payload' => '', 'last_activity' => time()]);
        }
        $token = Password::createToken($user);
        $this->post('/reset-password', $this->credentials($user, $token, ['email' => strtoupper($user->email)]))
            ->assertRedirect('/login')->assertSessionHas('success');
        $user->refresh();
        $this->assertTrue(Hash::check('NovaSenha12345', $user->password));
        $this->assertFalse($user->must_change_password);
        $this->assertNotSame($remember, $user->remember_token);
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertDatabaseHas('sessions', ['user_id' => $other->id]);
        $this->assertFalse(Password::tokenExists($user, $token));
        $this->assertGuest();
        Event::assertDispatched(PasswordResetEvent::class);
        $this->post('/reset-password', $this->credentials($user, $token))->assertSessionHasErrors('email');
        $this->post('/login', ['email' => $user->email, 'password' => 'NovaSenha12345'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_invalid_expired_and_wrong_account_tokens_are_rejected(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $original = $user->password;
        $token = Password::createToken($user);
        $this->post('/reset-password', $this->credentials($user, 'invalid'))->assertSessionHasErrors('email');
        $this->post('/reset-password', $this->credentials($other, $token))->assertSessionHasErrors('email');
        $this->travel(61)->minutes();
        $this->post('/reset-password', $this->credentials($user, $token))->assertSessionHasErrors('email');
        $this->assertSame($original, $user->fresh()->password);
    }

    public function test_validation_and_disabled_accounts_do_not_change_passwords(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);
        $this->post('/forgot-password', ['email' => 'invalid'])->assertSessionHasErrors('email');
        $this->post('/reset-password', $this->credentials($user, $token, ['password' => 'weak', 'password_confirmation' => 'weak']))
            ->assertSessionHasErrors('password');
        $this->post('/reset-password', $this->credentials($user, $token, ['password_confirmation' => 'different']))
            ->assertSessionHasErrors('password');
        $user->update(['active' => false]);
        $this->post('/reset-password', $this->credentials($user, $token))->assertSessionHasErrors('email');
        $this->assertFalse(Hash::check('NovaSenha12345', $user->fresh()->password));
    }

    public function test_email_uses_configured_origin_and_portuguese_content(): void
    {
        config(['app.url' => 'https://crm.example.test']);
        $user = User::factory()->create();
        $mail = (new ResetPassword('example-token'))->toMail($user);
        $this->assertSame('Redefina sua senha · PoseiTech CRM', $mail->subject);
        $this->assertStringStartsWith('https://crm.example.test/reset-password/example-token?', $mail->actionUrl);
        $this->assertStringContainsString(rawurlencode($user->email), $mail->actionUrl);
        $this->assertStringContainsString('60 minutos', implode(' ', $mail->outroLines));
        $this->assertNotEmpty((string) $mail->render());
    }

    public function test_mail_transport_failure_is_generic_and_allows_a_later_retry(): void
    {
        $user = User::factory()->create();
        Notification::shouldReceive('send')->once()->andThrow(new TransportException('SMTP unavailable'));
        $this->post('/forgot-password', ['email' => $user->email])->assertRedirect('/forgot-password')->assertSessionHas('success');
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_forms_include_recovery_guidance_and_are_not_cached(): void
    {
        $this->get('/forgot-password')->assertOk()->assertSee('link de recuperação')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $response = $this->get('/reset-password/example-token?email=pessoa%40example.test')
            ->assertOk()->assertSee('pessoa@example.test')->assertSee('Solicitar outro link');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_recovery_requests_are_rate_limited(): void
    {
        Notification::fake();
        for ($i = 0; $i < 3; $i++) {
            $this->post('/forgot-password', ['email' => 'unknown@example.test'])->assertRedirect();
        }
        $this->post('/forgot-password', ['email' => 'unknown@example.test'])->assertStatus(429);
    }

    public function test_new_link_replaces_previous_token_and_reset_attempts_are_limited(): void
    {
        $user = User::factory()->create();
        $old = Password::createToken($user);
        $new = Password::createToken($user);
        $this->assertFalse(Password::tokenExists($user, $old));
        $this->assertTrue(Password::tokenExists($user, $new));
        for ($i = 0; $i < 5; $i++) {
            $this->post('/reset-password', $this->credentials($user, 'invalid'))->assertSessionHasErrors('email');
        }
        $this->post('/reset-password', $this->credentials($user, $new))->assertStatus(429);
    }
}
