<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Support\Tenancy\TenantDatabase;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\World;
use Tests\TestCase;

/**
 * SEC-003：資料庫層的隔離（複合外鍵 + RLS），不依賴 Controller 判斷。
 * 以 runtime 角色（應用程式實際使用的連線）直接操作資料庫。
 */
final class DatabaseIsolationTest extends TestCase
{
    public function test_runtime_connection_is_not_superuser_and_cannot_bypass_rls(): void
    {
        $role = DB::selectOne('SELECT current_user AS u, (SELECT rolbypassrls FROM pg_roles WHERE rolname = current_user) AS bypass, (SELECT rolsuper FROM pg_roles WHERE rolname = current_user) AS super');
        $this->assertSame('yacs_runtime', $role->u);
        $this->assertFalse($role->bypass);
        $this->assertFalse($role->super);
    }

    public function test_composite_foreign_key_rejects_cross_workspace_parent(): void
    {
        $a = World::create('Alpha');
        $b = World::create('Beta');
        $brandB = $b->brand();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/foreign key/i');

        // 在 A 的範圍內，插入指向 B 品牌的收件匣：複合外鍵 (workspace_id, brand_id) 拒絕。
        $a->in(fn () => DB::transaction(fn () => DB::table('inboxes')->insert([
            'id' => (string) Str::uuid7(),
            'workspace_id' => $a->workspace->id,
            'brand_id' => $brandB->id,
            'name' => 'cross',
            'public_key' => 'ibx_'.Str::random(32),
            'channel_type' => 'web',
            'status' => 'active',
            'ai_mode' => 'disabled',
            'settings' => '{}',
            'version' => 0,
            'created_at' => CarbonImmutable::now(),
            'updated_at' => CarbonImmutable::now(),
        ])));
    }

    public function test_rls_hides_rows_without_workspace_context_and_from_other_workspaces(): void
    {
        $a = World::create('Alpha');
        $b = World::create('Beta');
        $a->brand('A brand');
        $b->brand('B brand');
        $tenant = app(TenantDatabase::class);

        $tenant->reset();
        $this->assertSame(0, DB::table('brands')->count(), '未設定 workspace 時 RLS 應看不到任何列');
        $this->assertSame(0, DB::table('roles')->count());

        $this->assertSame(1, $a->in(fn () => DB::table('brands')->count()));
        $this->assertSame(0, $a->in(fn () => DB::table('brands')->where('workspace_id', $b->workspace->id)->count()));
        $this->assertSame(2, $tenant->asSystem(fn () => DB::table('brands')->count()), 'system 角色可跨 workspace（僅限明確的系統工作）');
        $this->assertSame('yacs_runtime', DB::selectOne('SELECT current_user AS u')->u, 'asSystem 結束後必須回到 runtime 角色');
    }

    public function test_rls_rejects_writes_into_another_workspace(): void
    {
        $a = World::create('Alpha');
        $b = World::create('Beta');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/row-level security/i');

        $a->in(fn () => DB::transaction(fn () => DB::table('teams')->insert([
            'id' => (string) Str::uuid7(),
            'workspace_id' => $b->workspace->id,
            'name' => 'intruder',
            'status' => 'active',
            'version' => 0,
            'created_at' => CarbonImmutable::now(),
            'updated_at' => CarbonImmutable::now(),
        ])));
    }

    public function test_audit_logs_are_append_only(): void
    {
        $a = World::create('Alpha');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/append-only/');

        $a->in(fn () => DB::transaction(fn () => DB::table('audit_logs')->where('workspace_id', $a->workspace->id)->update(['action' => 'tampered'])));
    }
}
