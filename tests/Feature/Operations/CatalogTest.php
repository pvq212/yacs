<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Modules\Conversations\Conversations;
use App\Modules\Identity\Visitor\VisitorActor;
use App\Modules\Identity\Visitor\VisitorSessions;
use App\Modules\Tasks\Tasks;
use App\Modules\Tasks\Watchdog;
use App\Support\Database\Records as R;
use App\Support\Http\ApiException;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\StaffClient;
use Tests\Support\World;
use Tests\TestCase;

final class CatalogTest extends TestCase
{
    public function test_identity_exchange_is_issuer_scoped_single_use_and_does_not_merge_anonymous_history(): void
    {
        $world = World::create();
        $inbox = $world->inbox($world->brand());
        $staff = StaffClient::loginAs($this, $world->ownerStaff());
        $base = '/api/v1/workspaces/'.$world->workspace->id;
        $secret = str_repeat('s', 32);
        $issuer = $staff->post("$base/identity-issuers", ['brand_id' => $inbox->brand_id, 'name' => '網站會員身分', 'issuer' => 'shop-backend', 'key_id' => 'k1', 'secret' => base64_encode($secret), 'inbox_ids' => [$inbox->id], 'status' => 'active']);
        $issuer->assertCreated();
        $this->assertMatchesOpenApi($issuer, 'createIdentityIssuer');
        $this->assertStringNotContainsString($secret, $issuer->getContent());
        [$anonymous,$conversation] = $world->in(function () use ($inbox): array {
            $token = app(VisitorSessions::class)->anonymous($inbox, []);
            $actor = new VisitorActor(DB::table('visitor_sessions')->where('id', $token['visitor_session_id'])->first());

            return [$token, app(Conversations::class)->create($actor, [])];
        });
        $claims = ['iss' => 'shop-backend', 'aud' => 'yacs:visitor', 'sub' => 'member-123', 'iat' => time(), 'exp' => time() + 60, 'jti' => R::id(), 'workspace_id' => $world->workspace->id, 'brand_id' => $inbox->brand_id, 'inbox_key' => $inbox->public_key];
        $assertion = JWT::encode($claims, $secret, 'HS256', 'k1');
        $identified = $world->in(fn () => app(VisitorSessions::class)->identify($inbox, $assertion, $anonymous['access_token']));
        $this->assertSame('verified', $identified['identity_level']);
        $world->in(function () use ($identified, $anonymous, $conversation, $inbox, $assertion): void {
            $session = app(VisitorSessions::class)->authenticate($identified['access_token']);
            $this->assertNotSame($conversation->contact_id, $session->contact_id);
            $this->assertNotNull(DB::table('visitor_sessions')->where('id', $anonymous['visitor_session_id'])->value('revoked_at'));
            try {
                app(VisitorSessions::class)->identify($inbox, $assertion, null);
                $this->fail('重用 assertion 不得成功');
            } catch (ApiException $e) {
                $this->assertSame('IDENTITY_REPLAYED', $e->errorCode->value);
            }
        });
        $staff->patch("$base/identity-issuers/{$issuer->json('data.id')}", ['status' => 'disabled', 'expected_version' => '0'])->assertOk();
        try {
            $world->in(fn () => app(VisitorSessions::class)->authenticate($identified['access_token']));
            $this->fail('停用簽發者須撤销 session');
        } catch (ApiException $e) {
            $this->assertSame('UNAUTHENTICATED', $e->errorCode->value);
        }
    }

    public function test_waiting_case_is_assigned_when_agent_becomes_available_and_revoked_agent_is_requeued(): void
    {
        $world = World::create();
        $inbox = $world->inbox($world->brand());
        $world->in(function () use ($world, $inbox): void {
            $token = app(VisitorSessions::class)->anonymous($inbox, []);
            $actor = new VisitorActor(DB::table('visitor_sessions')->where('id', $token['visitor_session_id'])->first());
            $c = app(Conversations::class)->create($actor, []);
            $this->assertSame('human_queue', $c->handling_mode);
            DB::table('agent_capacity')->where('membership_id', $world->ownerMembership->id)->update(['presence_status' => 'available', 'last_heartbeat_at' => now()]);
            app(Watchdog::class)->tick();
            $this->assertSame($world->ownerMembership->id, DB::table('conversations')->where('id', $c->id)->value('assignee_id'));
            DB::table('workspace_memberships')->where('id', $world->ownerMembership->id)->update(['status' => 'disabled']);
            app(Watchdog::class)->tick();
            $this->assertSame('human_queue', DB::table('conversations')->where('id', $c->id)->value('handling_mode'));
            $this->assertNull(DB::table('conversations')->where('id', $c->id)->value('assignee_id'));
        });
    }

    public function test_export_is_private_and_task_cannot_be_read_by_other_requester(): void
    {
        Storage::fake('r2');
        config(['yacs.files.disk' => 'r2']);
        $world = World::create();
        $staff = StaffClient::loginAs($this, $world->ownerStaff());
        $base = '/api/v1/workspaces/'.$world->workspace->id;
        $task = $staff->post("$base/exports", ['kind' => 'report_summary', 'from' => now()->subDay()->toISOString(), 'to' => now()->toISOString()]);
        $task->assertAccepted();
        $world->in(fn () => app(Tasks::class)->run($task->json('data.id'), $world->workspace->id));
        $result = $staff->get("$base/async-tasks/{$task->json('data.id')}");
        $result->assertOk();
        $this->assertMatchesOpenApi($result, 'getTask');
        $this->assertSame('succeeded', $result->json('data.state'));
        $file = $result->json('data.result.file_id');
        $link = $staff->get("$base/files/$file/download");
        $link->assertOk();
        $this->get($link->json('data.url'))->assertOk();
        $world->in(fn () => DB::table('export_jobs')->where('task_id', $task->json('data.id'))->update(['expires_at' => now()->subSecond()]));
        $staff->get("$base/files/$file/download")->assertForbidden();
        $staff->get("$base/tags/not-a-uuid")->assertNotFound();
    }

    public function test_horizon_denies_guests_and_non_operator_owners(): void
    {
        $this->get('/horizon', ['Accept' => 'application/json'])->assertUnauthorized();
        $world = World::create();
        $staff = StaffClient::loginAs($this, $world->ownerStaff());
        $staff->get('/horizon')->assertForbidden();
    }
}
