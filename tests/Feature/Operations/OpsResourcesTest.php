<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Modules\Identity\Mail\StaffInvitationMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\StaffClient;
use Tests\Support\World;
use Tests\TestCase;

/**
 * 運營設定資源的 CRUD、CAS 版本衝突與契約（OpenAPI）一致性。
 */
final class OpsResourcesTest extends TestCase
{
    public function test_brand_and_inbox_lifecycle(): void
    {
        $world = World::create();
        $client = StaffClient::loginAs($this, $world->ownerStaff());
        $base = "/api/v1/workspaces/{$world->workspace->id}";

        $brand = $client->post("{$base}/brands", [
            'name' => '品牌一', 'slug' => 'brand-one', 'status' => 'active',
            'theme' => ['primary_color' => '#112233', 'display_name' => '小幫手', 'position' => 'bottom-left'],
        ]);
        $brand->assertCreated();
        $this->assertMatchesOpenApi($brand, 'createBrand');
        $brandId = $brand->json('data.id');

        $dup = $client->post("{$base}/brands", ['name' => 'dup', 'slug' => 'brand-one', 'status' => 'active']);
        $dup->assertUnprocessable();

        $unknown = $client->post("{$base}/brands", ['name' => 'x', 'slug' => 'brand-x', 'status' => 'active', 'workspace_id' => $world->workspace->id]);
        $unknown->assertUnprocessable();
        $this->assertArrayHasKey('workspace_id', $unknown->json('error.details.fields'));

        $theme = $client->post("{$base}/brands", ['name' => 'x', 'slug' => 'brand-y', 'status' => 'active', 'theme' => ['css' => 'body{}']]);
        $theme->assertUnprocessable();

        $inbox = $client->post("{$base}/inboxes", [
            'brand_id' => $brandId, 'name' => '官網', 'channel_type' => 'web', 'status' => 'active', 'ai_mode' => 'disabled',
            'allowed_origins' => ['https://Shop.Example.com:443/', 'https://shop.example.com', 'http://localhost:3000'],
            'settings' => ['offline_message' => '目前無人在線，請留言。', 'auto_assign' => false],
        ]);
        $inbox->assertCreated();
        $this->assertMatchesOpenApi($inbox, 'createInbox');
        $this->assertSame(['http://localhost:3000', 'https://shop.example.com'], $inbox->json('data.allowed_origins'));
        $this->assertStringStartsWith('ibx_', $inbox->json('data.public_key'));
        $this->assertFalse($inbox->json('data.settings.auto_assign'));

        $bad = $client->post("{$base}/inboxes", [
            'brand_id' => $brandId, 'name' => 'bad', 'channel_type' => 'web', 'status' => 'active', 'ai_mode' => 'disabled',
            'allowed_origins' => ['https://*.example.com'],
        ]);
        $bad->assertUnprocessable();

        $inboxId = $inbox->json('data.id');
        $patched = $client->patch("{$base}/inboxes/{$inboxId}", ['name' => '官網 2', 'expected_version' => '0']);
        $patched->assertOk();
        $this->assertMatchesOpenApi($patched, 'updateInbox');
        $this->assertSame('1', $patched->json('data.version'));

        $stale = $client->patch("{$base}/inboxes/{$inboxId}", ['name' => 'stale', 'expected_version' => '0']);
        $stale->assertConflict();
        $this->assertMatchesOpenApi($stale, 'updateInbox');
        $this->assertSame('VERSION_CONFLICT', $stale->json('error.code'));
        $this->assertSame('1', $stale->json('error.details.current_version'));

        $numeric = $client->patch("{$base}/inboxes/{$inboxId}", ['name' => 'num', 'expected_version' => 1]);
        $numeric->assertUnprocessable();

        $show = $client->get("{$base}/inboxes/{$inboxId}");
        $this->assertMatchesOpenApi($show, 'getInbox');
        $this->assertMatchesOpenApi($client->get("{$base}/brands/{$brandId}"), 'getBrand');

        $this->assertTrue($world->in(fn () => DB::table('audit_logs')->where('action', 'inbox.updated')->where('resource_id', $inboxId)->exists()));
    }

    public function test_team_and_member_invitation_flow(): void
    {
        Mail::fake();
        $world = World::create();
        $client = StaffClient::loginAs($this, $world->ownerStaff());
        $base = "/api/v1/workspaces/{$world->workspace->id}";
        $brand = $world->brand();
        $inbox = $world->inbox($brand);

        $team = $client->post("{$base}/teams", ['name' => '一線客服', 'status' => 'active']);
        $team->assertCreated();
        $this->assertMatchesOpenApi($team, 'createTeam');

        $member = $client->post("{$base}/members", [
            'email' => 'New.Agent@Example.test', 'display_name' => '新客服', 'status' => 'invited',
            'max_active_conversations' => 3, 'inbox_ids' => [$inbox->id], 'team_ids' => [$team->json('data.id')],
        ]);
        $member->assertCreated();
        $this->assertMatchesOpenApi($member, 'createMember');
        $this->assertSame('invited', $member->json('data.status'));
        $this->assertSame(3, $member->json('data.max_active_conversations'));

        $token = null;
        Mail::assertQueued(StaffInvitationMail::class, function (StaffInvitationMail $mail) use (&$token): bool {
            $token = (fn () => $this->token)->call($mail);

            return $mail->hasTo('New.Agent@Example.test');
        });

        // 管理員不可替受邀者直接啟用。
        $client->patch("{$base}/members/{$member->json('data.id')}", ['status' => 'active', 'expected_version' => '0'])->assertConflict();

        $guest = new StaffClient($this);
        $guest->csrf();
        $info = $guest->post('/api/v1/auth/invitations/lookup', ['token' => $token], idempotent: false);
        $info->assertOk();
        $this->assertMatchesOpenApi($info, 'lookupInvitation');
        $this->assertTrue($info->json('data.requires_password'));

        $guest->post('/api/v1/auth/invitations/accept', ['token' => $token], idempotent: false)->assertUnprocessable();
        $guest->post('/api/v1/auth/invitations/accept', ['token' => $token, 'password' => 'short'], idempotent: false)->assertUnprocessable();
        $accept = $guest->post('/api/v1/auth/invitations/accept', ['token' => $token, 'password' => 'new-agent-password-1'], idempotent: false);
        $accept->assertNoContent();
        $guest->post('/api/v1/auth/invitations/accept', ['token' => $token, 'password' => 'new-agent-password-1'], idempotent: false)->assertUnprocessable();

        $agent = StaffClient::loginAs($this, 'new.agent@example.test', 'new-agent-password-1');
        $this->assertSame([$world->workspace->id], $agent->get('/api/v1/me')->json('data.workspace_ids'));

        $listed = $client->get("{$base}/members");
        $this->assertMatchesOpenApi($listed, 'listMember');
        $this->assertContains('active', array_column($listed->json('data'), 'status'));

        $teamShow = $client->get("{$base}/teams/{$team->json('data.id')}");
        $this->assertMatchesOpenApi($teamShow, 'getTeam');
        $this->assertSame([$member->json('data.id')], $teamShow->json('data.member_ids'));
    }

    public function test_disabling_member_revokes_access_immediately(): void
    {
        $world = World::create();
        $agent = $world->member('Agent');
        $admin = StaffClient::loginAs($this, $world->ownerStaff());
        $agentClient = StaffClient::loginAs($this, $agent);
        $base = "/api/v1/workspaces/{$world->workspace->id}";

        $agentClient->get("{$base}/teams")->assertOk();
        $admin->patch("{$base}/members/{$agent->id()}", ['status' => 'disabled', 'expected_version' => '0'])->assertOk();

        // 該使用者沒有其他啟用中的 workspace：所有 session 立即撤銷。
        $agentClient->get("{$base}/teams")->assertUnauthorized();
        $agentClient->get('/api/v1/me')->assertUnauthorized();
    }

    public function test_roles_listing_and_workspace_update(): void
    {
        $world = World::create();
        $client = StaffClient::loginAs($this, $world->ownerStaff());
        $base = "/api/v1/workspaces/{$world->workspace->id}";

        $roles = $client->get("{$base}/roles");
        $roles->assertOk();
        $this->assertMatchesOpenApi($roles, 'listRole');
        $this->assertContains('owner', array_column($roles->json('data'), 'key'));

        $workspace = $client->patch($base, ['name' => '新名稱', 'timezone' => 'Asia/Tokyo', 'expected_version' => '0']);
        $workspace->assertOk();
        $this->assertMatchesOpenApi($workspace, 'updateWorkspace');
        $client->patch($base, ['timezone' => 'Mars/Olympus', 'expected_version' => '1'])->assertUnprocessable();

        $audit = $client->get("{$base}/audit-logs", ['action' => 'workspace.updated']);
        $audit->assertOk();
        $this->assertMatchesOpenApi($audit, 'listAuditLogs');
        $this->assertCount(1, $audit->json('data'));
    }
}
