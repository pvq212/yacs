<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\OpenApiAssertions;

/**
 * 所有需要資料庫的測試基底：真實 PostgreSQL（compose.test.yaml），每個測試包在交易內並於結束時 rollback。
 *
 * migration 以 owner 角色（pgsql_migrator）執行一次；測試本身以 runtime 角色（受 RLS 約束）連線，
 * 與正式環境相同。
 */
abstract class TestCase extends BaseTestCase
{
    use OpenApiAssertions;
    use RefreshDatabase;

    /** @var list<string> */
    protected $connectionsToTransact = ['pgsql'];

    /**
     * @return array<string, mixed>
     */
    protected function migrateFreshUsing(): array
    {
        return [
            '--database' => 'pgsql_migrator',
            '--drop-views' => true,
            '--seed' => false,
        ];
    }
}
