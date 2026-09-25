<?php

declare(strict_types=1);

namespace App\Modules\Identity\Staff;

use Illuminate\Validation\Rules\Password;

/**
 * Staff 密碼規則：至少 12 字元、上限 1024；不呼叫外部外洩資料庫（避免未經批准的對外連線）。
 */
final class PasswordPolicy
{
    /**
     * @return list<mixed>
     */
    public static function rules(): array
    {
        return ['required', 'string', 'max:1024', Password::min(12)->letters()->numbers()];
    }
}
