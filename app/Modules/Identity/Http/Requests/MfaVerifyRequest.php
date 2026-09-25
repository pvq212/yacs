<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Support\Http\ApiRequest;

final class MfaVerifyRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'challenge_id' => ['required', 'string', 'max:64'],
            'code' => ['required', 'string', 'min:6', 'max:32'],
        ];
    }
}
