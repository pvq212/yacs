<?php

declare(strict_types=1);

use App\Modules\AccessControl\Http\Controllers\RoleController;
use App\Modules\Audit\Http\Controllers\AuditLogController;
use App\Modules\Identity\Http\Controllers\AccountSecurityController;
use App\Modules\Identity\Http\Controllers\PublicAuthController;
use App\Modules\Identity\Http\Controllers\StaffAuthController;
use App\Modules\Workspaces\Http\Controllers\BrandController;
use App\Modules\Workspaces\Http\Controllers\InboxController;
use App\Modules\Workspaces\Http\Controllers\MemberController;
use App\Modules\Workspaces\Http\Controllers\TeamController;
use App\Modules\Workspaces\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| YACS API v1（前綴 /api/v1，見 bootstrap/app.php）
|--------------------------------------------------------------------------
|
| 路由名稱與 OpenAPI operationId 一致（tests/Contract/RouteParityTest 驗證兩者同步）。
| 所有需要副作用的建立/狀態操作都掛 `idempotent`（Idempotency-Key 必填）。
|
*/

// ---------------------------------------------------------------------
// Staff：同站 session + CSRF
// ---------------------------------------------------------------------
Route::middleware('staff')->group(function (): void {
    Route::get('auth/csrf-cookie', [StaffAuthController::class, 'csrfCookie'])->name('csrfCookie');
    Route::post('auth/login', [StaffAuthController::class, 'login'])->middleware('throttle:staff-login')->name('login');
    Route::post('auth/mfa/verify', [StaffAuthController::class, 'verifyMfa'])->middleware('throttle:staff-login')->name('verifyMfa');

    Route::middleware('throttle:staff-auth-public')->group(function (): void {
        Route::post('auth/password/forgot', [PublicAuthController::class, 'forgotPassword'])->name('forgotPassword');
        Route::post('auth/password/reset', [PublicAuthController::class, 'resetPassword'])->name('resetPassword');
        Route::post('auth/invitations/lookup', [PublicAuthController::class, 'lookupInvitation'])->name('lookupInvitation');
        Route::post('auth/invitations/accept', [PublicAuthController::class, 'acceptInvitation'])->name('acceptInvitation');
    });

    // 尚未完成 MFA 設定的使用者仍可使用：登出、查看自己、設定 MFA、改密碼。
    Route::middleware(['staff.auth:allow-enrollment', 'throttle:staff'])->group(function (): void {
        Route::post('auth/logout', [StaffAuthController::class, 'logout'])->name('logoutStaff');
        Route::get('me', [StaffAuthController::class, 'me'])->name('getCurrentUser');
        Route::put('me/password', [AccountSecurityController::class, 'changePassword'])->name('changePassword');
        Route::post('me/mfa/setup', [AccountSecurityController::class, 'setup'])->name('setupMfa');
        Route::post('me/mfa/confirm', [AccountSecurityController::class, 'confirm'])->name('confirmMfa');
        Route::post('me/mfa/disable', [AccountSecurityController::class, 'disable'])->name('disableMfa');
        Route::post('me/mfa/recovery-codes', [AccountSecurityController::class, 'regenerateRecoveryCodes'])->name('regenerateMfaRecoveryCodes');
    });

    Route::middleware(['staff.auth', 'throttle:staff'])->group(function (): void {
        Route::get('workspaces', [WorkspaceController::class, 'index'])->name('listWorkspaces');

        Route::prefix('workspaces/{workspace_id}')->middleware('staff.workspace')->group(function (): void {
            Route::get('/', [WorkspaceController::class, 'show'])->name('getWorkspace');
            Route::patch('/', [WorkspaceController::class, 'update'])->middleware('idempotent')->name('updateWorkspace');
            Route::get('me', [WorkspaceController::class, 'me'])->name('getWorkspaceMe');

            Route::get('brands', [BrandController::class, 'index'])->name('listBrand');
            Route::post('brands', [BrandController::class, 'store'])->middleware('idempotent')->name('createBrand');
            Route::get('brands/{brand_id}', [BrandController::class, 'show'])->name('getBrand');
            Route::patch('brands/{brand_id}', [BrandController::class, 'update'])->middleware('idempotent')->name('updateBrand');

            Route::get('inboxes', [InboxController::class, 'index'])->name('listInbox');
            Route::post('inboxes', [InboxController::class, 'store'])->middleware('idempotent')->name('createInbox');
            Route::get('inboxes/{inbox_id}', [InboxController::class, 'show'])->name('getInbox');
            Route::patch('inboxes/{inbox_id}', [InboxController::class, 'update'])->middleware('idempotent')->name('updateInbox');

            Route::get('members', [MemberController::class, 'index'])->name('listMember');
            Route::post('members', [MemberController::class, 'store'])->middleware('idempotent')->name('createMember');
            Route::get('members/{member_id}', [MemberController::class, 'show'])->name('getMember');
            Route::patch('members/{member_id}', [MemberController::class, 'update'])->middleware('idempotent')->name('updateMember');
            Route::get('members/{member_id}/role-bindings', [MemberController::class, 'roleBindings'])->name('getMemberRoleBindings');
            Route::put('members/{member_id}/role-bindings', [MemberController::class, 'replaceRoleBindings'])->middleware('idempotent')->name('replaceMemberRoleBindings');

            Route::get('teams', [TeamController::class, 'index'])->name('listTeam');
            Route::post('teams', [TeamController::class, 'store'])->middleware('idempotent')->name('createTeam');
            Route::get('teams/{team_id}', [TeamController::class, 'show'])->name('getTeam');
            Route::patch('teams/{team_id}', [TeamController::class, 'update'])->middleware('idempotent')->name('updateTeam');

            Route::get('roles', [RoleController::class, 'index'])->name('listRole');
            Route::post('roles', [RoleController::class, 'store'])->middleware('idempotent')->name('createRole');
            Route::get('roles/{role_id}', [RoleController::class, 'show'])->name('getRole');
            Route::patch('roles/{role_id}', [RoleController::class, 'update'])->middleware('idempotent')->name('updateRole');

            Route::get('audit-logs', [AuditLogController::class, 'index'])->name('listAuditLogs');
        });
    });
});
