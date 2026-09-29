<?php

declare(strict_types=1);

namespace App\Modules\Ai\Adapters\Sdk;

final class AnthropicGateway extends \Laravel\Ai\Gateway\Anthropic\AnthropicGateway
{
    use SecureClient;
}
