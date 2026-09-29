<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Identity\Mail\PasswordResetMail;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Mail;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\StaffClient;
use Tests\Support\World;
use Tests\TestCase;

/**
 * MFA 設定/登入挑戰、復原碼、密碼變更與重設。
 */
final class AccountSecurityTest extends TestCase
{
    public function test_mfa_enrollment_and_login_challenge(): void
    {
        $world = World::create();
        $client = StaffClient::loginAs($this, $world->ownerStaff());
        $google2fa = new Google2FA;

        $setup = $client->post('/api/v1/me/mfa/setup', idempotent: false);
        $setup->assertOk();
        $this->assertMatchesOpenApi($setup, 'setupMfa');
        $secret = $setup->json('data.secret');

        $client->post('/api/v1/me/mfa/confirm', ['code' => '000000'], idempotent: false)->assertUnprocessable();
        $confirm = $client->post('/api/v1/me/mfa/confirm', ['code' => $google2fa->getCurrentOtp($secret)], idempotent: false);
        $confirm->assertOk();
        $this->assertMatchesOpenApi($confirm, 'confirmMfa');
        $recovery = $confirm->json('data.recovery_codes');
        $this->assertCount(10, $recovery);

        $me = $client->get('/api/v1/me');
        $this->assertTrue($me->json('data.mfa_enabled'));
        $this->assertStringNotContainsString($secret, (string) $me->getContent());

        // 新登入需要 MFA 挑戰。
        $second = new StaffClient($this);
        $second->csrf();
        $login = $second->post('/api/v1/auth/login', ['email' => $world->owner->email, 'password' => World::PASSWORD], idempotent: false);
        $login->assertOk();
        $this->assertMatchesOpenApi($login, 'login');
        $this->assertTrue($login->json('data.mfa_required'));
        $second->get('/api/v1/me')->assertUnauthorized();

        $challenge = $login->json('data.challenge_id');
        $second->post('/api/v1/auth/mfa/verify', ['challenge_id' => $challenge, 'code' => '123456'], idempotent: false)->assertUnauthorized();
        // 同一組 TOTP 已在設定時用過，不能重放；改用復原碼。
        $verify = $second->post('/api/v1/auth/mfa/verify', ['challenge_id' => $challenge, 'code' => $recovery[0]], idempotent: false);
        $verify->assertOk();
        $this->assertMatchesOpenApi($verify, 'verifyMfa');
        $second->get('/api/v1/me')->assertOk();

        // 復原碼只能用一次。
        $third = new StaffClient($this);
        $third->csrf();
        $challenge3 = $third->post('/api/v1/auth/login', ['email' => $world->owner->email, 'password' => World::PASSWORD], idempotent: false)->json('data.challenge_id');
        $third->post('/api/v1/auth/mfa/verify', ['challenge_id' => $challenge3, 'code' => $recovery[0]], idempotent: false)->assertUnauthorized();
    }

    public function test_mfa_enforcement_limits_access_until_enrolled(): void
    {
        config(['yacs.staff.mfa_enforcement' => 'all']);
        $world = World::create();
        $client = StaffClient::loginAs($this, $world->ownerStaff());

        $me = $client->get('/api/v1/me');
        $me->assertOk();
        $this->assertTrue($me->json('data.mfa_enrollment_required'));

        $blocked = $client->get("/api/v1/workspaces/{$world->workspace->id}/brands");
        $blocked->assertUnauthorized();
        $this->assertSame('MFA_REQUIRED', $blocked->json('error.code'));
        $this->assertTrue($blocked->json('error.details.enrollment_required'));
    }

    public function test_password_change_revokes_other_sessions(): void
    {
        $world = World::create();
        $first = StaffClient::loginAs($this, $world->ownerStaff());
        $second = StaffClient::loginAs($this, $world->ownerStaff());

        $first->put('/api/v1/me/password', ['current_password' => 'wrong', 'password' => 'another-password-2'])->assertUnprocessable();
        $first->put('/api/v1/me/password', ['current_password' => World::PASSWORD, 'password' => 'another-password-2'])->assertNoContent();

        $first->get('/api/v1/me')->assertOk();
        $second->get('/api/v1/me')->assertUnauthorized();
    }

    public function test_password_reset_flow_is_enumeration_safe_and_single_use(): void
    {
        Mail::fake();
        $world = World::create();
        $guest = new StaffClient($this);
        $guest->csrf();

        $unknown = $guest->post('/api/v1/auth/password/forgot', ['email' => 'nobody@example.test'], idempotent: false);
        $known = $guest->post('/api/v1/auth/password/forgot', ['email' => $world->owner->email], idempotent: false);
        $this->assertSame($unknown->getStatusCode(), $known->getStatusCode());
        $this->assertMatchesOpenApi($known, 'forgotPassword');

        $token = null;
        Mail::assertQueued(PasswordResetMail::class, function (PasswordResetMail $mail) use (&$token): bool {
            $token = (fn () => $this->token)->call($mail);

            return true;
        });
        Mail::assertQueuedCount(1);

        $reset = $guest->post('/api/v1/auth/password/reset', ['email' => $world->owner->email, 'token' => $token, 'password' => 'brand-new-password-3'], idempotent: false);
        $reset->assertNoContent();
        $guest->post('/api/v1/auth/password/reset', ['email' => $world->owner->email, 'token' => $token, 'password' => 'brand-new-password-4'], idempotent: false)->assertUnprocessable();

        StaffClient::loginAs($this, $world->owner->email, 'brand-new-password-3');
        $this->assertTrue(User::query()->whereKey($world->owner->id)->exists());
    }
}
