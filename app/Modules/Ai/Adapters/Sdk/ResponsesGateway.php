<?php

declare(strict_types=1);

namespace App\Modules\Ai\Adapters\Sdk;

use Laravel\Ai\Gateway\OpenAi\OpenAiGateway;

final class ResponsesGateway extends OpenAiGateway
{
    use SecureClient;
}
