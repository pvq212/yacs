<?php

declare(strict_types=1);

namespace App\Modules\Conversations;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

final class RealtimeEvent implements ShouldBroadcastNow
{
    public function __construct(private array $channels, private array $payload) {}

    public function broadcastOn(): array
    {
        return array_map(fn ($channel) => new PrivateChannel($channel), $this->channels);
    }

    public function broadcastAs(): string
    {
        return 'yacs.event';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
