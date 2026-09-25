<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Staff\MfaService;

/**
 * `User` DTO（OpenAPI `User`）。不含密碼、MFA secret 或復原碼。
 */
final class UserResource
{
    /**
     * @param  list<string>  $workspaceIds
     * @return array<string, mixed>
     */
    public static function toArray(User $user, array $workspaceIds): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'workspace_ids' => $workspaceIds,
            'locale' => $user->locale,
            'mfa_enabled' => $user->hasMfa(),
            'mfa_enrollment_required' => app(MfaService::class)->enrollmentRequired($user),
            'is_platform_operator' => $user->is_platform_operator,
        ];
    }
}
