<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Modules\AccessControl\RoleTemplates;
use Illuminate\Support\Facades\DB;
use Tests\Support\StaffClient;
use Tests\Support\World;
use Tests\TestCase;

/**
 * SEC-005：角色不可自我提權（403 + 稽核，資料不變）；SEC-004 的權限層面（Agent 沒有主管權限）。
 */
final class PrivilegeEscalationTest extends TestCase
{
    public function test_operations_admin_cannot_create_role_with_permissions_they_lack(): void
    {
        $world = World::create();
        $admin = $world->member('Ops Admin', [[RoleTemplates::OPERATIONS_ADMIN]]);
        $client = StaffClient::loginAs($this, $admin);
        $base = "/api/v1/workspaces/{$world->workspace->id}";

        $response = $client->post("{$base}/roles", ['key' => 'super', 'name' => 'Super', 'permission_codes' => ['workspace.manage', 'conversation.read']]);
        $response->assertForbidden();
        $this->assertMatchesOpenApi($response, 'createRole');
        $this->assertSame('privilege_escalation', $response->json('error.details.reason'));

        $this->assertFalse($world->in(fn () => DB::table('roles')->where('key', 'super')->exists()));
        $this->assertTrue($world->in(fn () => DB::table('audit_logs')->where('action', 'role.create.denied')->where('actor_id', $admin->id())->exists()));

        // 自己擁有的權限可以組成新角色。
        $ok = $client->post("{$base}/roles", ['key' => 'triage', 'name' => 'Triage', 'permission_codes' => ['conversation.read', 'conversation.assign']]);
        $ok->assertCreated();
        $this->assertMatchesOpenApi($ok, 'createRole');
    }

    public function test_operations_admin_cannot_grant_self_owner_or_edit_owner_role(): void
    {
        $world = World::create();
        $admin = $world->member('Ops Admin', [[RoleTemplates::OPERATIONS_ADMIN]]);
        $client = StaffClient::loginAs($this, $admin);
        $base = "/api/v1/workspaces/{$world->workspace->id}";

        $before = $client->get("{$base}/members/{$admin->id()}/role-bindings");
        $before->assertOk();
        $this->assertMatchesOpenApi($before, 'getMemberRoleBindings');

        $bindings = $before->json('data.role_bindings');
        $bindings[] = ['role_id' => $world->roleId(RoleTemplates::OWNER), 'scope_type' => 'workspace', 'scope_id' => null];
        $grant = $client->put("{$base}/members/{$admin->id()}/role-bindings", ['role_bindings' => $bindings], idempotent: true);
        $grant->assertForbidden();
        $this->assertMatchesOpenApi($grant, 'replaceMemberRoleBindings');
        $this->assertSame($before->json('data.role_bindings'), $client->get("{$base}/members/{$admin->id()}/role-bindings")->json('data.role_bindings'));
        $this->assertTrue($world->in(fn () => DB::table('audit_logs')->where('action', 'member.role_bindings.denied')->exists()));

        $ownerRole = $client->get("{$base}/roles/{$world->roleId(RoleTemplates::OWNER)}")->json('data');
        $edit = $client->patch("{$base}/roles/{$ownerRole['id']}", ['permission_codes' => ['conversation.read'], 'expected_version' => $ownerRole['version']]);
        $edit->assertForbidden();
    }

    public function test_scoped_grant_cannot_exceed_granter_scope(): void
    {
        $world = World::create();
        $brandA = $world->brand('A');
        $brandB = $world->brand('B');
        $inboxB = $world->inbox($brandB);
        // 只在品牌 A 擁有 supervisor 權限的運營管理員（roles.assign 仍在 workspace 層級）。
        $admin = $world->member('Scoped Admin', [
            [RoleTemplates::OPERATIONS_ADMIN, 'brand', $brandA->id],
            ['__assign_only'],
        ]);
        $agent = $world->member('Agent');
        $client = StaffClient::loginAs($this, $admin);
        $base = "/api/v1/workspaces/{$world->workspace->id}";

        $response = $client->put("{$base}/members/{$agent->id()}/role-bindings", ['role_bindings' => [
            ['role_id' => $world->roleId(RoleTemplates::SUPERVISOR), 'scope_type' => 'inbox', 'scope_id' => $inboxB->id],
        ]], idempotent: true);
        $response->assertForbidden();
    }

    public function test_last_owner_cannot_be_removed(): void
    {
        $world = World::create();
        $client = StaffClient::loginAs($this, $world->ownerStaff());
        $base = "/api/v1/workspaces/{$world->workspace->id}";

        $response = $client->put("{$base}/members/{$world->ownerMembership->id}/role-bindings", ['role_bindings' => []], idempotent: true);
        $response->assertConflict();
        $this->assertSame('last_owner', $response->json('error.details.reason'));
    }

    public function test_agent_has_no_supervisor_capabilities(): void
    {
        $world = World::create();
        $agent = $world->member('Agent');
        $client = StaffClient::loginAs($this, $agent);

        $me = $client->get("/api/v1/workspaces/{$world->workspace->id}/me");
        $me->assertOk();
        $this->assertMatchesOpenApi($me, 'getWorkspaceMe');
        $codes = $me->json('data.permission_codes');
        $this->assertContains('conversation.claim', $codes);
        $this->assertNotContains('conversation.assist_other', $codes);
        $this->assertNotContains('conversation.assign', $codes);
    }
}
