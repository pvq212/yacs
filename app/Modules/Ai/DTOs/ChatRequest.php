<?php

declare(strict_types=1);

namespace App\Modules\Ai\DTOs;

/** DTO 不持有解密後 API key，且與 SDK response class 無關。 */
final readonly class ChatRequest
{
    public function __construct(public object $connection, public object $model, public string $system, public array $messages, public int $maxOutputTokens = 1024, public int $timeout = 45) {}
}
