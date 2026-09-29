<?php

declare(strict_types=1);

namespace App\Modules\Ai\DTOs;

final readonly class ChatResult
{
    public function __construct(public string $text, public string $finishReason, public ?int $inputTokens = null, public ?int $outputTokens = null, public string $usageState = 'unavailable') {}
}
