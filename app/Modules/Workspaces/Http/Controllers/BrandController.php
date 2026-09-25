<?php

declare(strict_types=1);

namespace App\Modules\Workspaces\Http\Controllers;

use App\Modules\AccessControl\Authorizer;
use App\Modules\AccessControl\Permission;
use App\Modules\AccessControl\ResourceScope;
use App\Modules\AccessControl\StaffActor;
use App\Modules\Audit\AuditActor;
use App\Modules\Audit\AuditLogger;
use App\Modules\Workspaces\Http\Resources\OpsResources;
use App\Modules\Workspaces\Models\Brand;
use App\Modules\Workspaces\Support\BrandTheme;
use App\Support\Database\Cas;
use App\Support\Http\ApiException;
use App\Support\Http\ApiResponse;
use App\Support\Http\ErrorCode;
use App\Support\Http\Input;
use App\Support\Http\KeysetPaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 品牌設定（OpenAPI: listBrand / createBrand / getBrand / updateBrand）。
 *
 * 讀取：workspace 內任何成員皆可看到品牌名稱與外觀（用於篩選與顯示），
 * 但只回傳授權範圍內有關的品牌（brand.manage、或在該品牌有任何授予）。
 */
final class BrandController
{
    public function index(Request $request, StaffActor $actor, Authorizer $authz): JsonResponse
    {
        $page = Input::validate($request, Input::pageRules());
        $query = Brand::query()->forWorkspace($actor->workspaceId());
        if (! $authz->can(Permission::BrandManage)) {
            $query->whereIn('id', $this->visibleBrandIds($actor, $authz));
        }

        return ApiResponse::page(KeysetPaginator::paginate(
            $query, [['name', 'asc'], ['id', 'asc']],
            KeysetPaginator::clampLimit($page['limit'] ?? null), $page['cursor'] ?? null,
            static fn (Brand $b): array => OpsResources::brand($b),
        ));
    }

    public function show(string $workspace_id, string $brand_id, StaffActor $actor, Authorizer $authz): JsonResponse
    {
        $brand = $this->find($actor, $brand_id);
        if (! $authz->can(Permission::BrandManage) && ! in_array($brand->id, $this->visibleBrandIds($actor, $authz), true)) {
            throw ApiException::notFound();
        }

        return ApiResponse::data(OpsResources::brand($brand));
    }

    public function store(Request $request, StaffActor $actor, Authorizer $authz, AuditLogger $audit): JsonResponse
    {
        $authz->authorize(Permission::BrandManage);
        $data = Input::validate($request, [
            'name' => ['required', 'string', 'min:1', 'max:100'],
            'slug' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9-]{1,62}$/'],
            'status' => ['required', 'string', 'in:active,disabled'],
            ...BrandTheme::rules(),
        ]);

        $brand = new Brand;
        $brand->forceFill([
            'workspace_id' => $actor->workspaceId(),
            'name' => $data['name'],
            'slug' => $data['slug'],
            'status' => $data['status'],
            'settings' => ['theme' => BrandTheme::sanitize($data['theme'] ?? [])],
        ]);
        try {
            DB::transaction(function () use ($brand, $actor, $audit): void {
                $brand->save();
                $audit->record($actor->workspaceId(), AuditActor::staff($actor), 'brand.created', 'brand', $brand->id, null, OpsResources::brand($brand));
            });
        } catch (UniqueConstraintViolationException) {
            throw new ApiException(ErrorCode::ValidationFailed, null, ['fields' => ['slug' => ['taken']]]);
        }

        return ApiResponse::data(OpsResources::brand($brand->refresh()), 201);
    }

    public function update(Request $request, string $workspace_id, string $brand_id, StaffActor $actor, Authorizer $authz, AuditLogger $audit): JsonResponse
    {
        $brand = $this->find($actor, $brand_id);
        $authz->authorize(Permission::BrandManage, ResourceScope::brand($brand->id));
        $data = Input::validate($request, [
            'name' => ['sometimes', 'string', 'min:1', 'max:100'],
            'slug' => ['sometimes', 'string', 'regex:/^[a-z0-9][a-z0-9-]{1,62}$/'],
            'status' => ['sometimes', 'string', 'in:active,disabled'],
            ...BrandTheme::rules(),
            'expected_version' => Input::seq(),
        ]);

        $before = OpsResources::brand($brand);
        $changes = array_intersect_key($data, array_flip(['name', 'slug', 'status']));
        if (array_key_exists('theme', $data)) {
            $settings = (array) $brand->settings;
            $settings['theme'] = BrandTheme::sanitize((array) $data['theme']);
            $changes['settings'] = json_encode($settings, JSON_UNESCAPED_UNICODE);
        }
        try {
            DB::transaction(function () use ($brand, $data, $changes, $actor, $audit, $before): void {
                Cas::update($brand, $data['expected_version'], $changes);
                $audit->record($actor->workspaceId(), AuditActor::staff($actor), 'brand.updated', 'brand', $brand->id, $before, OpsResources::brand($brand));
            });
        } catch (UniqueConstraintViolationException) {
            throw new ApiException(ErrorCode::ValidationFailed, null, ['fields' => ['slug' => ['taken']]]);
        }

        return ApiResponse::data(OpsResources::brand($brand));
    }

    private function find(StaffActor $actor, string $brandId): Brand
    {
        $brand = Input::isUuid($brandId) ? Brand::query()->forWorkspace($actor->workspaceId())->find($brandId) : null;
        if ($brand === null) {
            throw ApiException::notFound();
        }

        return $brand;
    }

    /**
     * 使用者在任何權限中被授予的品牌，以及授予的收件匣所屬品牌。
     *
     * @return list<string>
     */
    private function visibleBrandIds(StaffActor $actor, Authorizer $authz): array
    {
        $brandIds = [];
        $inboxIds = [];
        $hasWorkspaceGrant = false;
        foreach ($authz->permissionCodes() as $code) {
            foreach ($authz->grantsFor(Permission::from($code)) as $grant) {
                match ($grant->scopeType) {
                    'workspace' => $hasWorkspaceGrant = true,
                    'brand' => $brandIds[] = (string) $grant->scopeId,
                    'inbox' => $inboxIds[] = (string) $grant->scopeId,
                    default => null,
                };
            }
        }
        if ($hasWorkspaceGrant) {
            return DB::table('brands')->where('workspace_id', $actor->workspaceId())->pluck('id')->map(static fn ($id): string => (string) $id)->all();
        }
        if ($inboxIds !== []) {
            $brandIds = [...$brandIds, ...DB::table('inboxes')->where('workspace_id', $actor->workspaceId())->whereIn('id', $inboxIds)->pluck('brand_id')->map(static fn ($id): string => (string) $id)->all()];
        }

        return array_values(array_unique($brandIds));
    }
}
