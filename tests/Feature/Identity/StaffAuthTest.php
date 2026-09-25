<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use Tests\Support\StaffClient;
use Tests\Support\World;
use Tests\TestCase;

/**
 * Staff 登入/登出/目前使用者與 CSRF（SEC-010）。
 */
final class StaffAuthTest extends TestCase
{
    public function test_login_me_logout_flow(): void
    {
        $world = World::create();
        $client = StaffClient::loginAs($this, $world->ownerStaff());

        $me = $client->get('/api/v1/me');
        $me->assertOk();
        $this->assertMatchesOpenApi($me, 'getCurrentUser');
        $this->assertSame([$world->workspace->id], $me->json('data.workspace_ids'));

        $client->post('/api/v1/auth/logout', idempotent: false)->assertNoContent();
        $client->get('/api/v1/me')->assertUnauthorized();
    }

    public function test_wrong_password_is_rejected_with_generic_error(): void
    {
        $world = World::create();
        $client = new StaffClient($this);
        $client->csrf();

        $response = $client->post('/api/v1/auth/login', ['email' => $world->owner->email, 'password' => 'wrong-password-123'], idempotent: false);
        $response->assertUnauthorized();
        $this->assertMatchesOpenApi($response, 'login');
        $this->assertSame('UNAUTHENTICATED', $response->json('error.code'));

        $unknown = $client->post('/api/v1/auth/login', ['email' => 'nobody@example.test', 'password' => 'wrong-password-123'], idempotent: false);
        $unknown->assertUnauthorized();
        $this->assertSame($response->json('error.message'), $unknown->json('error.message'));
    }

    public function test_login_without_csrf_token_is_rejected(): void
    {
        $world = World::create();
        $client = new StaffClient($this);

        $response = $client->post('/api/v1/auth/login', ['email' => $world->owner->email, 'password' => World::PASSWORD], idempotent: false);
        $response->assertForbidden();
        $this->assertSame('FORBIDDEN', $response->json('error.code'));
    }

    /**
     * SEC-010：已登入 staff 的寫入請求缺少 CSRF header 時拒絕；合法流程可用。
     */
    public function test_staff_write_requires_csrf_header(): void
    {
        $world = World::create();
        $client = StaffClient::loginAs($this, $world->ownerStaff());
        $uri = "/api/v1/workspaces/{$world->workspace->id}/brands";
        $body = ['name' => 'CSRF Brand', 'slug' => 'csrf-brand', 'status' => 'active'];

        $missing = $client->request('POST', $uri, $body, 'csrf-test-key-0000001', withCsrf: false);
        $missing->assertForbidden();

        $forged = $client->request('POST', $uri, $body, 'csrf-test-key-0000002', ['X-XSRF-TOKEN' => 'forged'], withCsrf: false);
        $forged->assertForbidden();

        $client->post($uri, $body)->assertCreated();
    }

    public function test_disabled_user_session_is_invalidated(): void
    {
        $world = World::create();
        $client = StaffClient::loginAs($this, $world->ownerStaff());
        $client->get('/api/v1/me')->assertOk();

        $world->owner->forceFill(['status' => 'disabled'])->save();

        $client->get('/api/v1/me')->assertUnauthorized();
    }
}
