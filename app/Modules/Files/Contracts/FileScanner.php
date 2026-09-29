<?php

declare(strict_types=1);

namespace App\Modules\Files\Contracts;

interface FileScanner
{
    /** @return array{body:string,mime:string,width:?int,height:?int,rejection:?string} */
    public function scan(string $body, object $file): array;
}
