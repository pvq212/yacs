<?php

declare(strict_types=1);

namespace App\Modules\Ai\Adapters\Sdk;

use Laravel\Ai\Gateway\OpenAiCompatible\OpenAiCompatibleGateway;

final class ChatCompletionsGateway extends OpenAiCompatibleGateway
{
    use SecureClient;
}
