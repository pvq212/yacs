<?php

declare(strict_types=1);

namespace App\Modules\Ai\Contracts;

use App\Modules\Ai\DTOs\ChatRequest;
use App\Modules\Ai\DTOs\ChatResult;

interface ChatGateway
{
    public function generate(ChatRequest $request): ChatResult;
}
