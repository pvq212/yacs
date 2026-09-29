<?php

declare(strict_types=1);

namespace Tests\Feature;

use PragmaRX\Google2FA\Google2FA;
use Tests\Support\StaffClient;
use Tests\Support\World;
use Tests\TestCase;

/** 保留原先 Claude 調查的情境，改為有效的安全回歸檢查。 */
final class DebugTest extends TestCase
{
    public function test_invalid_mfa_does_not_consume_recovery_code_or_activate_session(): void
    {
        $world = World::create();
        $owner = StaffClient::loginAs($this, $world->ownerStaff());
        $secret = $owner->post('/api/v1/me/mfa/setup', idempotent: false)->json('data.secret');
        $codes = $owner->post('/api/v1/me/mfa/confirm', ['code' => (new Google2FA)->getCurrentOtp($secret)], idempotent: false)->json('data.recovery_codes');
        $second = new StaffClient($this);
        $second->csrf();
        $challenge = $second->post('/api/v1/auth/login', ['email' => $world->owner->email, 'password' => World::PASSWORD], idempotent: false)->json('data.challenge_id');
        $second->post('/api/v1/auth/mfa/verify', ['challenge_id' => $challenge, 'code' => 'invalid-recovery-code'], idempotent: false)->assertUnauthorized();
        $second->get('/api/v1/me')->assertUnauthorized();
        $verified = $second->post('/api/v1/auth/mfa/verify', ['challenge_id' => $challenge, 'code' => $codes[0]], idempotent: false);
        $verified->assertOk();
        $this->assertMatchesOpenApi($verified, 'verifyMfa');
        $second->get('/api/v1/me')->assertOk();
    }
}
