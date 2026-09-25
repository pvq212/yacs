<?php

declare(strict_types=1);

namespace App\Modules\Workspaces\Support;

/**
 * 品牌 widget 外觀（OpenAPI `Theme`）。只接受白名單欄位與格式，不接受任意 CSS/JS/HTML（F-WEB-002）。
 */
final class BrandTheme
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(string $prefix = 'theme'): array
    {
        return [
            $prefix => ['sometimes', 'array:primary_color,position,display_name,welcome_text,logo_url'],
            $prefix.'.primary_color' => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            $prefix.'.position' => ['sometimes', 'string', 'in:bottom-right,bottom-left'],
            $prefix.'.display_name' => ['sometimes', 'string', 'max:80'],
            $prefix.'.welcome_text' => ['sometimes', 'string', 'max:500'],
            $prefix.'.logo_url' => ['sometimes', 'nullable', 'string', 'url:https', 'max:2048'],
        ];
    }

    /**
     * @param  array<string, mixed>  $theme
     * @return array<string, mixed>
     */
    public static function sanitize(array $theme): array
    {
        return array_intersect_key($theme, array_flip(['primary_color', 'position', 'display_name', 'welcome_text', 'logo_url']));
    }

    /**
     * 補上預設值後輸出給 widget。
     *
     * @param  array<string, mixed>  $theme
     * @return array<string, mixed>
     */
    public static function withDefaults(array $theme, string $fallbackName): array
    {
        return array_merge([
            'primary_color' => '#2563EB',
            'position' => (string) config('yacs.widget.initial_position', 'bottom-right'),
            'display_name' => mb_substr($fallbackName, 0, 80),
            'welcome_text' => __('widget.default_welcome'),
            'logo_url' => null,
        ], self::sanitize($theme));
    }
}
