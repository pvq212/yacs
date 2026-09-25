<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Support\Http\ApiRequest;

final class LoginRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:320'],
            'password' => ['required', 'string', 'min:1', 'max:1024'],
        ];
    }
}
