<?php

declare(strict_types=1);

namespace App\Modules\Operations\Http\Controllers;

use App\Modules\AccessControl\Authorizer;
use App\Modules\AccessControl\Permission;
use App\Modules\AccessControl\ScopeResolver;
use App\Modules\AccessControl\StaffActor;
use App\Modules\Audit\AuditActor;
use App\Modules\Audit\AuditLogger;
use App\Support\Database\Records as R;
use App\Support\Http\ApiException;
use App\Support\Http\ApiResponse;
use App\Support\Http\ContractRequest;
use App\Support\Http\ErrorCode;
use App\Support\Security\Egress;
use App\Support\Security\SecretBox;
use App\Support\Security\Tokens;
use Illuminate\Support\Facades\DB;

final class CatalogController
{
    private const TYPES = ['IdentityIssuer' => 'identity_issuers', 'ChannelConnector' => 'channel_connectors', 'BusinessHours' => 'business_hours', 'Macro' => 'macros', 'Tag' => 'tags', 'ResolutionReason' => 'resolution_reasons', 'CustomerAttribute' => 'customer_attributes'];

    public function __invoke(ContractRequest $request): mixed
    {
        $op = $request->route()->getName();
        $type = null;
        foreach (self::TYPES as $kind => $table) {
            if (str_ends_with($op, $kind)) {
                $type = $kind;
                break;
            }
        }
        if ($type === null) {
            throw new \LogicException('Unknown catalog.');
        } $table = self::TYPES[$type];
        $actor = app(StaffActor::class);
        $auth = app(Authorizer::class);
        $input = $request->payload();
        $manage = in_array($type, ['IdentityIssuer', 'ChannelConnector'], true) ? Permission::IntegrationManage : ($type === 'BusinessHours' ? Permission::InboxManage : Permission::AutomationManage);
        $read = str_starts_with($op, 'get') || str_starts_with($op, 'list');
        if ($read && in_array($type, ['Macro', 'Tag', 'ResolutionReason', 'CustomerAttribute'], true) && $auth->canAnywhere(Permission::ConversationRead)) {
            $auth->authorizeAnywhere(Permission::ConversationRead);
        } else {
            $auth->authorize($manage);
        }
        $id = $request->route('resource_id');
        $row = $id ? DB::table($table)->where('id', $id)->first() : null;
        if ($id && $row === null) {
            throw new ApiException(ErrorCode::NotFound);
        }
        if ($read) {
            $q = DB::table($table);
            if ($id) {
                $q->where('id', $id);
            }
            if ($type === 'Macro' && ! $auth->can($manage)) {
                $brands = DB::table('inboxes')->get()->filter(fn ($i) => $auth->can(Permission::ConversationRead, app(ScopeResolver::class)->forInbox($i->id)))->pluck('brand_id');
                $q->where(fn ($q) => $q->whereNull('brand_id')->orWhereIn('brand_id', $brands));
            }
            $rows = $q->orderByDesc('id')->limit(100)->get()->map(fn ($r) => self::dto($type, $r))->all();

            return $id ? ($rows ? ApiResponse::data($rows[0]) : throw new ApiException(ErrorCode::NotFound)) : ApiResponse::list($rows);
        }

        return DB::transaction(function () use ($type, $table, $row, $input, $actor): mixed {
            $id = $row->id ?? R::id();
            $values = $input;
            unset($values['expected_version']);
            $create = $row === null;
            if (! $create) {
                $row = DB::table($table)->where('id', $id)->lockForUpdate()->first();
                if ((string) $row->version !== $input['expected_version']) {
                    throw new ApiException(ErrorCode::VersionConflict);
                } $values['version'] = (int) $row->version + 1;
            } else {
                $values += ['id' => $id, 'workspace_id' => $actor->workspaceId(), 'created_at' => now()];
            }
            $values['updated_at'] = now();
            if (isset($values['brand_id']) && ! DB::table('brands')->where('id', $values['brand_id'])->exists()) {
                throw new ApiException(ErrorCode::NotFound);
            }
            if (in_array($type, ['IdentityIssuer', 'ChannelConnector'], true)) {
                $brand = $values['brand_id'] ?? $row?->brand_id;
                foreach ($values['inbox_ids'] ?? [] as $inbox) {
                    if (! DB::table('inboxes')->where('id', $inbox)->where('brand_id', $brand)->exists()) {
                        throw new ApiException(ErrorCode::NotFound);
                    }
                }
                if (isset($values['inbox_id']) && ! DB::table('inboxes')->where('id', $values['inbox_id'])->where('brand_id', $brand)->exists()) {
                    throw new ApiException(ErrorCode::NotFound);
                }
                if (array_key_exists('secret', $values)) {
                    if ($type === 'IdentityIssuer') {
                        $decoded = base64_decode($values['secret'], true);
                        if ($decoded === false || strlen($decoded) < 32) {
                            throw new ApiException(ErrorCode::ValidationFailed);
                        }
                        $values['secret'] = $decoded;
                    }
                    $values['secret_encrypted'] = app(SecretBox::class)->encrypt($values['secret'], ($type === 'IdentityIssuer' ? 'identity_issuer:' : 'connector:').$id);
                    unset($values['secret']);
                }
                if ($type === 'IdentityIssuer' && isset($values['inbox_ids'])) {
                    $values['inbox_scope'] = R::encode($values['inbox_ids']);
                    unset($values['inbox_ids']);
                }
                if ($type === 'ChannelConnector') {
                    if ($create) {
                        $values['public_key'] = Tokens::publicKey('cnx_');
                        $values['capabilities'] = R::encode(['text' => true, 'attachments' => false]);
                    } if (isset($values['outbound_url'])) {
                        app(Egress::class)->request($values['outbound_url'], 10);
                    }
                }
            }
            if ($type === 'BusinessHours') {
                if (isset($values['timezone']) && ! in_array($values['timezone'], timezone_identifiers_list(), true)) {
                    throw new ApiException(ErrorCode::ValidationFailed);
                }
                foreach ($values['weekly_schedule'] ?? [] as $ranges) {
                    foreach ($ranges as [$start, $end]) {
                        if ($start >= $end || substr($start, 0, 2) > '23' || substr($end, 0, 2) > '23') {
                            throw new ApiException(ErrorCode::ValidationFailed);
                        }
                    }
                }
                foreach (['weekly_schedule', 'holidays'] as $field) {
                    if (isset($values[$field])) {
                        $values[$field] = R::encode($values[$field]);
                    }
                }
            }
            if ($type === 'Macro' && isset($values['body_template'])) {
                preg_match_all('/\{\{\s*([^}]+?)\s*\}\}/', $values['body_template'], $matches);
                if (array_diff($matches[1], ['contact.name', 'brand.name']) !== []) {
                    throw new ApiException(ErrorCode::ValidationFailed);
                } $values['allowed_variables'] = R::encode(array_values(array_unique($matches[1])));
            }
            if ($type === 'CustomerAttribute' && ($values['editable_by_visitor'] ?? $row->editable_by_visitor ?? false) && in_array($values['key'] ?? $row?->key, ['vip', 'verified', 'balance', 'identity_level', 'credit'], true)) {
                throw new ApiException(ErrorCode::Forbidden);
            }
            if ($create) {
                DB::table($table)->insert($values);
            } else {
                DB::table($table)->where('id', $id)->update($values);
            }
            $after = self::dto($type, DB::table($table)->where('id', $id)->first());
            app(AuditLogger::class)->record($actor->workspaceId(), AuditActor::staff($actor), $table.'.'.($create ? 'created' : 'updated'), $table, $id, $row ? self::dto($type, $row) : null, $after);

            return ApiResponse::data($after, $create ? 201 : 200);
        });
    }

    public static function dto(string $type, object $row): array
    {
        $doc = json_decode(file_get_contents(base_path('docs/spec/contracts/openapi.json')), true);
        $out = [];
        foreach (array_keys($doc['components']['schemas'][$type]['properties']) as $key) {
            $out[$key] = match ($key) {
                'version' => (string) $row->version, 'secret_present' => $row->secret_encrypted !== null, 'inbox_ids' => R::json($row->inbox_scope), 'weekly_schedule' => (object) R::json($row->weekly_schedule), 'holidays' => R::json($row->holidays), default => $row->$key ?? null
            };
        }

        return $out;
    }
}
