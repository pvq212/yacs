<?php

declare(strict_types=1);

namespace App\Modules\Ai\Adapters\Sdk;

final class GeminiGateway extends \Laravel\Ai\Gateway\Gemini\GeminiGateway
{
    use SecureClient;
}
