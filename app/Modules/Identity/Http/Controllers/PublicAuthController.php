<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Staff\InvitationService;
use App\Modules\Identity\Staff\PasswordPolicy;
use App\Modules\Identity\Staff\PasswordResetService;
use App\Support\Http\ApiException;
use App\Support\Http\ApiResponse;
use App\Support\Http\ErrorCode;
use App\Support\Http\Input;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * 未登入可用的帳號流程：忘記密碼、重設密碼、查詢/接受邀請。
 *
 * token 一律以 POST body 傳遞（前端從 URL fragment 讀取），不出現在 URL 與伺服器 log。
 */
final class PublicAuthController
{
    public function forgotPassword(Request $request, PasswordResetService $resets): JsonResponse
    {
        $data = Input::validate($request, ['email' => ['required', 'string', 'email:rfc', 'max:320']]);
        $resets->request($data['email']);

        // 不論帳號是否存在，回應一致。
        return ApiResponse::data(['accepted' => true], 202);
    }

    public function resetPassword(Request $request, PasswordResetService $resets): Response
    {
        $data = Input::validate($request, [
            'email' => ['required', 'string', 'email:rfc', 'max:320'],
            'token' => ['required', 'string', 'min:20', 'max:200'],
            'password' => PasswordPolicy::rules(),
        ]);
        if (! $resets->reset($data['email'], $data['token'], $data['password'])) {
            throw new ApiException(ErrorCode::ValidationFailed, __('auth.password_reset_invalid'), ['fields' => ['token' => ['invalid']]]);
        }

        return new Response('', 204);
    }

    public function lookupInvitation(Request $request, InvitationService $invitations): JsonResponse
    {
        $data = Input::validate($request, ['token' => ['required', 'string', 'min:20', 'max:200']]);
        $found = $invitations->find($data['token']);
        if ($found === null) {
            throw new ApiException(ErrorCode::NotFound, __('auth.invitation_invalid'));
        }

        return ApiResponse::data([
            'workspace_name' => $found['workspace']->name,
            'email' => $found['user']->email,
            'requires_password' => $found['user']->password_hash === null,
            'expires_at' => $found['invitation']->expires_at->utc()->format('Y-m-d\TH:i:s.v\Z'),
        ]);
    }

    public function acceptInvitation(Request $request, InvitationService $invitations): Response
    {
        $data = Input::validate($request, [
            'token' => ['required', 'string', 'min:20', 'max:200'],
            'name' => ['sometimes', 'string', 'min:1', 'max:100'],
            'password' => ['sometimes', ...array_slice(PasswordPolicy::rules(), 1)],
        ]);
        if (! $invitations->accept($data['token'], $data['password'] ?? null, $data['name'] ?? null)) {
            throw new ApiException(ErrorCode::ValidationFailed, __('auth.invitation_invalid'), ['fields' => ['token' => ['invalid']]]);
        }

        return new Response('', 204);
    }
}
