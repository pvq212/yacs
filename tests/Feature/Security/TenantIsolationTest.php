<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Modules\AccessControl\RoleTemplates;
use Tests\Support\StaffClient;
use Tests\Support\World;
use Tests\TestCase;

/**
 * SEC-001：跨 workspace 物件讀取；SEC-002：同 workspace 跨品牌隔離（M0 範圍的資源）。
 * 對話/附件/報表層面的隔離在 M1 以後的測試補強。
 */
final class TenantIsolationTest extends TestCase
{
    public function test_staff_cannot_read_or_modify_another_workspace(): void
    {
        $a = World::create('Alpha');
        $b = World::create('Beta');
        $brandA = $a->brand('Same Name');
        $brandB = $b->brand('Same Name');
        $inboxB = $b->inbox($brandB);

        $client = StaffClient::loginAs($this, $a->ownerStaff());

        // 直接改 URL 中的 workspace_id：404（不透露是否存在）。
        $response = $client->get("/api/v1/workspaces/{$b->workspace->id}/brands");
        $response->assertNotFound();
        $this->assertMatchesOpenApi($response, 'listBrand');
        $client->get("/api/v1/workspaces/{$b->workspace->id}")->assertNotFound();

        // 在自己的 workspace 路徑下帶入別人的資源 id：404。
        $client->get("/api/v1/workspaces/{$a->workspace->id}/brands/{$brandB->id}")->assertNotFound();
        $client->get("/api/v1/workspaces/{$a->workspace->id}/inboxes/{$inboxB->id}")->assertNotFound();
        $client->patch("/api/v1/workspaces/{$a->workspace->id}/brands/{$brandB->id}", ['name' => 'hijack', 'expected_version' => '0'])->assertNotFound();

        // 列表只有自己的資料。
        $list = $client->get("/api/v1/workspaces/{$a->workspace->id}/brands");
        $list->assertOk();
        $this->assertMatchesOpenApi($list, 'listBrand');
        $this->assertSame([$brandA->id], array_column($list->json('data'), 'id'));

        $workspaces = $client->get('/api/v1/workspaces');
        $this->assertMatchesOpenApi($workspaces, 'listWorkspaces');
        $this->assertSame([$a->workspace->id], array_column($workspaces->json('data'), 'id'));

        // body 中夾帶別人的 brand_id 建立收件匣：驗證失敗，不會建立在他人品牌下。
        $create = $client->post("/api/v1/workspaces/{$a->workspace->id}/inboxes", [
            'brand_id' => $brandB->id, 'name' => 'x', 'channel_type' => 'web', 'status' => 'active', 'ai_mode' => 'disabled',
        ]);
        $create->assertUnprocessable();
        $this->assertMatchesOpenApi($create, 'createInbox');
    }

    public function test_brand_scoped_agent_only_sees_granted_brand(): void
    {
        $world = World::create();
        $brandA = $world->brand('Brand A');
        $brandB = $world->brand('Brand B');
        $inboxA = $world->inbox($brandA, 'A web');
        $inboxB = $world->inbox($brandB, 'B web');
        $agent = $world->member('Agent A', [[RoleTemplates::AGENT, 'inbox', $inboxA->id]], [$inboxA]);

        $client = StaffClient::loginAs($this, $agent);
        $base = "/api/v1/workspaces/{$world->workspace->id}";

        $inboxes = $client->get("{$base}/inboxes");
        $inboxes->assertOk();
        $this->assertMatchesOpenApi($inboxes, 'listInbox');
        $this->assertSame([$inboxA->id], array_column($inboxes->json('data'), 'id'));

        $client->get("{$base}/inboxes/{$inboxB->id}")->assertNotFound();
        $this->assertSame([$brandA->id], array_column($client->get("{$base}/brands")->json('data'), 'id'));
        $client->get("{$base}/brands/{$brandB->id}")->assertNotFound();

        // 無管理權限：寫入 403。
        $client->patch("{$base}/inboxes/{$inboxA->id}", ['name' => 'renamed', 'expected_version' => '0'])->assertForbidden();
        $client->get("{$base}/members")->assertForbidden();
        $client->get("{$base}/audit-logs")->assertForbidden();
    }
}
