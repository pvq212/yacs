<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 依 Accept-Language 設定本請求的 locale（僅支援清單內語系）。
 *
 * locale 綁在 app 實例上；Octane 每個請求使用新的 app sandbox，且請求開始時重新設定，
 * 不會殘留上一請求的語系（SEC-006）。
 */
final class ApplyRequestLocale
{
    /** @var array<string, string> 瀏覽器語系 → 應用語系 */
    private const SUPPORTED = [
        'zh-tw' => 'zh_TW', 'zh-hant' => 'zh_TW', 'zh' => 'zh_TW',
        'en' => 'en', 'en-us' => 'en', 'en-gb' => 'en',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = config('yacs.locale', 'zh_TW');
        foreach ($request->getLanguages() as $candidate) {
            $key = strtolower(str_replace('_', '-', $candidate));
            if (isset(self::SUPPORTED[$key])) {
                $locale = self::SUPPORTED[$key];
                break;
            }
        }
        app()->setLocale((string) $locale);

        return $next($request);
    }
}
