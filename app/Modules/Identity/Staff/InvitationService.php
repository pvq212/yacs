<?php

declare(strict_types=1);

namespace App\Modules\Identity\Staff;

use App\Modules\Audit\AuditActor;
use App\Modules\Audit\AuditLogger;
use App\Modules\Identity\Mail\StaffInvitationMail;
use App\Modules\Identity\Models\User;
use App\Modules\Workspaces\Models\StaffInvitation;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Models\WorkspaceMembership;
use App\Support\Security\Tokens;
use App\Support\Tenancy\TenantDatabase;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use SensitiveParameter;

/**
 * Staff 邀請：建立（寄信）、查詢、接受。
 *
 * token 只存 SHA-256 hash；接受時以 `asSystem()` 依 hash 找出所屬 workspace（尚未登入，
 * 無法先知道 workspace），確認後立即切回該 workspace 範圍完成寫入。
 */
final class InvitationService
{
    public function __construct(
        private readonly TenantDatabase $tenant,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * 建立邀請並寄信；回傳明文 token（僅供 CLI/測試使用，API 不回傳）。
     */
    public function invite(WorkspaceMembership $membership, User $user, ?WorkspaceMembership $inviter): string
    {
        $token = Tokens::generate('yacs_inv_');
        $invitation = new StaffInvitation;
        $invitation->forceFill([
            'workspace_id' => $membership->workspace_id,
            'email_normalized' => $user->email_normalized,
            'token_hash' => Tokens::hash($token),
            'membership_id' => $membership->id,
            'invited_by_membership_id' => $inviter?->id,
            'expires_at' => CarbonImmutable::now()->addHours((int) config('yacs.staff.invitation_ttl_hours', 72)),
            'created_at' => CarbonImmutable::now(),
        ])->save();

        /** @var Workspace $workspace */
        $workspace = Workspace::query()->findOrFail($membership->workspace_id);
        Mail::to($user->email)
            ->locale($user->locale ?? $workspace->default_locale)
            ->queue(new StaffInvitationMail($workspace->name, $inviter->display_name ?? $workspace->name, $token));

        return $token;
    }

    /**
     * @return array{invitation: StaffInvitation, user: User, workspace: Workspace}|null
     */
    public function find(#[SensitiveParameter] string $token): ?array
    {
        $invitation = $this->tenant->asSystem(static fn (): ?StaffInvitation => StaffInvitation::query()
            ->where('token_hash', Tokens::hash($token))
            ->first());
        if ($invitation === null || $invitation->accepted_at !== null || $invitation->revoked_at !== null || $invitation->expires_at->isPast()) {
            return null;
        }
        $user = User::query()->where('email_normalized', $invitation->email_normalized)->first();
        $workspace = $this->tenant->asSystem(static fn (): ?Workspace => Workspace::query()->find($invitation->workspace_id));
        if ($user === null || $workspace === null || ! $workspace->isActive()) {
            return null;
        }

        return ['invitation' => $invitation, 'user' => $user, 'workspace' => $workspace];
    }

    /**
     * 接受邀請：尚未設定密碼的新使用者必須同時設定密碼。
     */
    public function accept(#[SensitiveParameter] string $token, #[SensitiveParameter] ?string $password, ?string $name): bool
    {
        $found = $this->find($token);
        if ($found === null) {
            return false;
        }
        ['invitation' => $invitation, 'user' => $user] = $found;
        if ($user->password_hash === null && ($password === null || $password === '')) {
            return false;
        }

        return $this->tenant->withinWorkspace($invitation->workspace_id, function () use ($invitation, $user, $password, $name): bool {
            return DB::transaction(function () use ($invitation, $user, $password, $name): bool {
                $claimed = StaffInvitation::query()->whereKey($invitation->id)->whereNull('accepted_at')->whereNull('revoked_at')
                    ->update(['accepted_at' => CarbonImmutable::now()]);
                if ($claimed !== 1) {
                    return false;
                }
                if ($user->password_hash === null && $password !== null) {
                    $user->forceFill(['password_hash' => Hash::make($password)]);
                }
                if ($name !== null && $name !== '') {
                    $user->forceFill(['name' => $name]);
                }
                $user->save();

                WorkspaceMembership::query()->whereKey($invitation->membership_id)->where('status', 'invited')
                    ->update(['status' => 'active', 'version' => DB::raw('version + 1'), 'updated_at' => CarbonImmutable::now()]);

                $this->audit->record($invitation->workspace_id, AuditActor::platform($user->id), 'member.invitation_accepted', 'member', $invitation->membership_id);

                return true;
            });
        });
    }
}
