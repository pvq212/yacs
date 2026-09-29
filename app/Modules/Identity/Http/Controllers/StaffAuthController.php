<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Http\Requests\LoginRequest;
use App\Modules\Identity\Http\Requests\MfaVerifyRequest;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Staff\MfaService;
use App\Modules\Identity\Staff\StaffSessionManager;
use App\Support\Http\ApiException;
use App\Support\Http\ApiResponse;
use App\Support\Http\ErrorCode;
use App\Support\Tenancy\TenantDatabase;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Staff 登入、MFA 驗證、登出與目前使用者（OpenAPI tag: Auth）。
 */
final class StaffAuthController
{
    private const CHALLENGE_KEY = 'yacs.mfa_challenge';

    private const CHALLENGE_TTL_SECONDS = 300;

    private const CHALLENGE_MAX_ATTEMPTS = 5;

    /** 帳號不存在時用來比對的 bcrypt hash（明文無意義），使回應時間一致。 */
    private const TIMING_EQUALIZER_HASH = '$2y$12$mDqacVhlBX8nqHa1PDCRB.66ttkmBt40Sid461sHE0zWIXhbcHB9m';

    public function __construct(
        private readonly StaffSessionManager $sessions,
        private readonly MfaService $mfa,
    ) {}

    /**
     * GET /api/v1/auth/csrf-cookie：ValidateCsrfToken middleware 會附上 XSRF-TOKEN cookie。
     */
    public function csrfCookie(): Response
    {
        return new Response('', 204);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $email = User::normalizeEmail((string) $request->validated('email'));
        $password = (string) $request->validated('password');

        $user = User::query()->where('email_normalized', $email)->first();
        // 使用者不存在時仍做一次 hash 比對，避免以回應時間探測帳號是否存在。
        $hash = $user->password_hash ?? self::TIMING_EQUALIZER_HASH;
        $valid = Hash::check($password, $hash) && $user !== null && $user->password_hash !== null;

        if (! $valid || ! $user->isActive()) {
            throw new ApiException(ErrorCode::Unauthenticated, __('auth.failed'));
        }

        if (Hash::needsRehash((string) $user->password_hash)) {
            $user->forceFill(['password_hash' => Hash::make($password)])->save();
        }

        if ($user->hasMfa()) {
            $challengeId = Str::random(40);
            $request->session()->put(self::CHALLENGE_KEY, [
                'id' => $challengeId,
                'user_id' => $user->id,
                'expires_at' => CarbonImmutable::now()->addSeconds(self::CHALLENGE_TTL_SECONDS)->getTimestamp(),
                'attempts' => 0,
            ]);

            return ApiResponse::data(['authenticated' => false, 'mfa_required' => true, 'challenge_id' => $challengeId]);
        }

        $this->sessions->establish($user, $request, mfaVerified: false);

        return ApiResponse::data(['authenticated' => true, 'mfa_required' => false, 'challenge_id' => null]);
    }

    public function verifyMfa(MfaVerifyRequest $request): JsonResponse
    {
        /** @var array{id:string,user_id:string,expires_at:int,attempts:int}|null $challenge */
        $challenge = $request->session()->get(self::CHALLENGE_KEY);
        $challengeId = (string) $request->validated('challenge_id');

        if ($challenge === null || ! hash_equals($challenge['id'], $challengeId) || $challenge['expires_at'] < time()) {
            $request->session()->forget(self::CHALLENGE_KEY);
            throw new ApiException(ErrorCode::Unauthenticated, __('auth.mfa_challenge_expired'));
        }
        if ($challenge['attempts'] >= self::CHALLENGE_MAX_ATTEMPTS) {
            $request->session()->forget(self::CHALLENGE_KEY);
            throw new ApiException(ErrorCode::RateLimited);
        }
        $challenge['attempts']++;
        $request->session()->put(self::CHALLENGE_KEY, $challenge);

        $user = User::query()->find($challenge['user_id']);
        if ($user === null || ! $user->isActive() || ! $user->hasMfa()) {
            throw new ApiException(ErrorCode::Unauthenticated);
        }

        $code = trim((string) $request->validated('code'));
        $ok = preg_match('/^[0-9]{6}$/', $code) === 1
            ? $this->mfa->verifyTotp($user, $code)
            : $this->mfa->consumeRecoveryCode($user, $code);
        if (! $ok) {
            throw new ApiException(ErrorCode::Unauthenticated, __('auth.mfa_invalid'));
        }

        $request->session()->forget(self::CHALLENGE_KEY);
        $this->sessions->establish($user, $request, mfaVerified: true);

        return $this->me();
    }

    public function logout(Request $request): Response
    {
        $this->sessions->logout($request);

        return new Response('', 204);
    }

    public function me(): JsonResponse
    {
        /** @var User $user */
        $user = Auth::guard('web')->user();
        app(TenantDatabase::class)->setUser($user->id);
        $workspaceIds = DB::table('workspace_memberships as m')
            ->join('workspaces as w', 'w.id', '=', 'm.workspace_id')
            ->where('m.user_id', $user->id)
            ->where('m.status', 'active')
            ->where('w.status', 'active')
            ->orderBy('w.name')
            ->pluck('m.workspace_id')
            ->map(static fn ($id): string => (string) $id)
            ->all();

        return ApiResponse::data(UserResource::toArray($user, $workspaceIds));
    }
}
