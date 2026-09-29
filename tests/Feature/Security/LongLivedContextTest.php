<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Modules\AccessControl\StaffActor;
use Illuminate\Support\Facades\DB;
use Tests\Support\StaffClient;
use Tests\Support\World;
use Tests\TestCase;

/**
 * SEC-006：同一個長駐程序（此處以同一個 app 實例模擬 Octane worker）交錯服務 A/B workspace，
 * 資料庫租戶設定、行為者上下文與語系不得殘留。
 *
 * 真正的 Octane/FrankenPHP 行為另由 e2e 測試（tests/e2e）在容器內驗證。
 */
final class LongLivedContextTest extends TestCase
{
    public function test_tenant_settings_are_cleared_after_each_request(): void
    {
        $a = World::create('Alpha');
        $b = World::create('Beta');
        $a->brand('Alpha brand');
        $b->brand('Beta brand');

        $clientA = StaffClient::loginAs($this, $a->ownerStaff());
        $clientB = StaffClient::loginAs($this, $b->ownerStaff());

        foreach ([[$clientA, $a], [$clientB, $b], [$clientA, $a], [$clientB, $b]] as [$client, $world]) {
            $response = $client->get("/api/v1/workspaces/{$world->workspace->id}/brands");
            $response->assertOk();
            $this->assertCount(1, $response->json('data'));
            $this->assertStringStartsWith($world->workspace->name, $response->json('data.0.name'));

            // 請求結束後，連線上的租戶設定已清除、角色回到 runtime。
            $state = DB::selectOne("SELECT current_user AS u, current_setting('yacs.workspace_id', true) AS w, current_setting('yacs.user_id', true) AS usr");
            $this->assertSame('yacs_runtime', $state->u);
            $this->assertSame('', (string) $state->w);
            $this->assertSame('', (string) $state->usr);
        }

        // A 的 session 不能被拿來讀 B（即使 app 實例上一個請求剛服務過 B）。
        $clientB->get("/api/v1/workspaces/{$b->workspace->id}/brands")->assertOk();
        $clientA->get("/api/v1/workspaces/{$b->workspace->id}/brands")->assertNotFound();
    }

    public function test_actor_and_locale_do_not_leak_between_requests(): void
    {
        $a = World::create('Alpha');
        $b = World::create('Beta');
        $clientA = StaffClient::loginAs($this, $a->ownerStaff());
        $clientB = StaffClient::loginAs($this, $b->ownerStaff());

        $en = $clientA->request('GET', "/api/v1/workspaces/{$a->workspace->id}/brands/00000000-0000-4000-8000-000000000000", headers: ['Accept-Language' => 'en']);
        $en->assertNotFound();
        $this->assertSame('The requested resource was not found.', $en->json('error.message'));
        $actorA = app(StaffActor::class);

        // 注意：Symfony 測試請求預設帶 Accept-Language: en-us，因此這裡明確指定。
        $zh = $clientB->request('GET', "/api/v1/workspaces/{$b->workspace->id}/brands/00000000-0000-4000-8000-000000000000", headers: ['Accept-Language' => 'zh-TW']);
        $this->assertSame('找不到指定的資料。', $zh->json('error.message'));
        $actorB = app(StaffActor::class);

        $this->assertNotSame($actorA, $actorB);
        $this->assertSame($b->workspace->id, $actorB->workspaceId());
    }
}
