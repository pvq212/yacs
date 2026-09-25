<?php

declare(strict_types=1);

namespace App\Modules\Workspaces\Http\Resources;

use App\Modules\Workspaces\Models\Brand;
use App\Modules\Workspaces\Models\Inbox;
use App\Modules\Workspaces\Models\Team;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Support\BrandTheme;
use App\Support\Database\Seq;
use Illuminate\Support\Facades\DB;

/**
 * 運營設定資源的對外 DTO（OpenAPI `Workspace`、`Brand`、`Inbox`、`Team`）。
 *
 * 集中在同一檔案以便對照 OpenAPI；每個方法只輸出契約定義的欄位。
 */
final class OpsResources
{
    /**
     * @return array<string, mixed>
     */
    public static function workspace(Workspace $w): array
    {
        return [
            'id' => $w->id,
            'name' => $w->name,
            'slug' => $w->slug,
            'timezone' => $w->timezone,
            'version' => Seq::str($w->version),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function brand(Brand $b): array
    {
        $theme = (array) ($b->settings['theme'] ?? []);

        return [
            'id' => $b->id,
            'workspace_id' => $b->workspace_id,
            'version' => Seq::str($b->version),
            'name' => $b->name,
            'slug' => $b->slug,
            'status' => $b->status,
            'theme' => (object) BrandTheme::sanitize($theme),
        ];
    }

    /**
     * @param  list<string>|null  $origins
     * @param  list<string>|null  $teamIds
     * @return array<string, mixed>
     */
    public static function inbox(Inbox $i, ?array $origins = null, ?array $teamIds = null): array
    {
        $origins ??= DB::table('inbox_origins')->where('inbox_id', $i->id)->orderBy('origin')->pluck('origin')->map(static fn ($o): string => (string) $o)->all();
        $teamIds ??= DB::table('inbox_teams')->where('inbox_id', $i->id)->orderBy('team_id')->pluck('team_id')->map(static fn ($t): string => (string) $t)->all();

        return [
            'id' => $i->id,
            'workspace_id' => $i->workspace_id,
            'version' => Seq::str($i->version),
            'brand_id' => $i->brand_id,
            'name' => $i->name,
            'channel_type' => $i->channel_type,
            'status' => $i->status,
            'ai_mode' => $i->ai_mode,
            'ai_profile_id' => $i->ai_profile_id,
            'business_hours_id' => $i->business_hours_id,
            'allowed_origins' => $origins,
            'team_ids' => $teamIds,
            'public_key' => $i->public_key,
            'settings' => (object) InboxSettings::sanitize((array) $i->settings),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function team(Team $t): array
    {
        return [
            'id' => $t->id,
            'workspace_id' => $t->workspace_id,
            'version' => Seq::str($t->version),
            'name' => $t->name,
            'status' => $t->status,
            'member_ids' => DB::table('team_memberships')->where('team_id', $t->id)->orderBy('membership_id')
                ->pluck('membership_id')->map(static fn ($m): string => (string) $m)->all(),
        ];
    }
}
