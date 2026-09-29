<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

final class GenerateKeys extends Command
{
    protected $signature = 'yacs:generate-keys';

    protected $description = '填入尚未設定的應用、秘密加密與查詢金鑰，不覆寫已存在的金鑰';

    public function handle(): int
    {
        $path = base_path('.env');
        if (! is_file($path)) {
            $this->error('請先複製 .env.example 為 .env。');

            return 1;
        }
        $content = file_get_contents($path);
        foreach (['APP_KEY' => 'base64:'.base64_encode(random_bytes(32)), 'YACS_SECRET_ENCRYPTION_KEY' => base64_encode(random_bytes(32)), 'YACS_LOOKUP_DIGEST_KEY' => base64_encode(random_bytes(32)), 'REVERB_APP_KEY' => bin2hex(random_bytes(16)), 'REVERB_APP_SECRET' => bin2hex(random_bytes(32))] as $name => $value) {
            if (preg_match('/^'.preg_quote($name, '/').'=(.*)$/m', $content, $match)) {
                if (trim($match[1], " \t\"'") !== '') {
                    continue;
                } $content = preg_replace('/^'.preg_quote($name, '/').'=.*$/m', $name.'='.$value, $content);
            } else {
                $content .= "\n".$name.'='.$value;
            }
        }
        file_put_contents($path, $content);
        chmod($path, 0600);
        $this->info('缺少的金鑰已寫入 .env；既有金鑰保持不變。');

        return 0;
    }
}
