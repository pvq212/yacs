<?php

declare(strict_types=1);

namespace App\Support\Extensions;

use App\Modules\Files\Contracts\FileScanner;

/** Composer ServiceProvider 可註冊實作；禁止呼叫端指定任意 class 或載入遠端程式。 */
final class Registry
{
    private array $entries = [];

    public function register(string $kind, string $key, string $class): void
    {
        if ($kind === 'file_scanner' && ! is_subclass_of($class, FileScanner::class)) {
            throw new \InvalidArgumentException('Invalid scanner contract.');
        }
        if (isset($this->entries[$kind][$key])) {
            throw new \LogicException('Duplicate extension key.');
        }
        $this->entries[$kind][$key] = $class;
    }

    public function resolve(string $kind, string $key): object
    {
        if (! isset($this->entries[$kind][$key])) {
            throw new \InvalidArgumentException('Unknown extension.');
        }

        return app($this->entries[$kind][$key]);
    }
}
