<?php

declare(strict_types=1);

namespace App\Modules\Files;

use App\Support\Security\SecretBox;
use Illuminate\Contracts\Filesystem\Filesystem;
use RuntimeException;

/** 遠端物件以 envelope 加密；只有授權後的應用下載能取得明文。 */
final class ObjectStorage
{
    public function __construct(private readonly Filesystem $storage, private readonly bool $encrypted) {}

    public function put(string $path, string $body): bool
    {
        return $this->storage->put($path, $this->encrypted ? app(SecretBox::class)->encrypt($body, 'file-object:'.$path) : $body);
    }

    public function get(string $path): string
    {
        $body = $this->storage->get($path);
        if (! is_string($body)) {
            throw new RuntimeException('File object unavailable.');
        }

        return $this->encrypted ? app(SecretBox::class)->decrypt($body, 'file-object:'.$path) : $body;
    }

    /** @return resource */
    public function readStream(string $path)
    {
        if (! $this->encrypted) {
            $stream = $this->storage->readStream($path);
            if (! is_resource($stream)) {
                throw new RuntimeException('File stream unavailable.');
            }

            return $stream;
        }
        $stream = fopen('php://temp/maxmemory:2097152', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('File stream unavailable.');
        }
        try {
            $body = $this->get($path);
            if (fwrite($stream, $body) !== strlen($body)) {
                throw new RuntimeException('File stream write failed.');
            }
            rewind($stream);

            return $stream;
        } catch (\Throwable $e) {
            fclose($stream);
            throw $e;
        }
    }

    public function exists(string $path): bool
    {
        return $this->storage->exists($path);
    }

    public function delete(string $path): bool
    {
        return $this->storage->delete($path);
    }
}
