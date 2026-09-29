<?php

declare(strict_types=1);

namespace App\Modules\Tasks;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class WorkTask implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public string $taskId, public string $workspaceId, string $queueName)
    {
        $this->timeout = (int) config('yacs.queues.'.$queueName.'.job_timeout', 30);
    }

    public function handle(Tasks $tasks): void
    {
        $tasks->run($this->taskId, $this->workspaceId);
    }
}
