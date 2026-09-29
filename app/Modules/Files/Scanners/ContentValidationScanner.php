<?php

declare(strict_types=1);

namespace App\Modules\Files\Scanners;

use App\Modules\Files\Contracts\FileScanner;
use Symfony\Component\Process\Process;

final class ContentValidationScanner implements FileScanner
{
    public function scan(string $body, object $file): array
    {
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($body);
        $max = $file->purpose === 'knowledge' ? 20971520 : 10485760;
        $reason = strlen($body) !== (int) $file->declared_bytes || strlen($body) > $max ? 'size_mismatch' : null;
        $allowed = (array) config('yacs.files.allowed_'.$file->purpose.'_mimes', config('yacs.files.allowed_chat_mimes'));
        if (! in_array($mime, $allowed, true) || ($file->declared_mime !== $mime && ! ($file->declared_mime === 'text/markdown' && $mime === 'text/plain'))) {
            $reason = 'content_type_mismatch';
        }
        if ($mime === 'text/plain' && (str_contains($body, '<?php') || str_contains($body, "\0") || preg_match('/<(?:script|html|svg|iframe)\b/i', $body))) {
            $reason = 'unsafe_content';
        }
        $width = null;
        $height = null;
        if ($reason === null && str_starts_with($mime, 'image/')) {
            $info = @getimagesizefromstring($body);
            if (! $info || $info[0] * $info[1] > 40000000) {
                $reason = 'invalid_image';
            } elseif (! function_exists('imagecreatefromstring')) {
                $reason = 'image_decoder_unavailable';
            } else {
                [$width, $height] = $info;
                $image = @imagecreatefromstring($body);
                if (! $image) {
                    $reason = 'invalid_image';
                } else {
                    ob_start();
                    try {
                        imagealphablending($image, false);
                        imagesavealpha($image, true);
                        $ok = match ($mime) {
                            'image/png' => imagepng($image), 'image/jpeg' => imagejpeg($image, null, 90), 'image/webp' => imagewebp($image, null, 90), default => false
                        };
                        $encoded = ob_get_contents();
                        if (! $ok || ! is_string($encoded) || $encoded === '' || strlen($encoded) > $max) {
                            $reason = 'image_encoding_failed';
                        } else {
                            $body = $encoded;
                        }
                    } finally {
                        ob_end_clean();
                    }
                }
            }
        }
        if ($reason === null && $mime === 'application/pdf') {
            $tmp = tempnam(sys_get_temp_dir(), 'yacs-scan-');
            try {
                file_put_contents($tmp, $body);
                $process = new Process(['pdfinfo', $tmp]);
                $process->setTimeout(10);
                $process->run();
                if (! $process->isSuccessful() || ! preg_match('/Pages:\s+(\d+)/', $process->getOutput(), $match)) {
                    $reason = 'invalid_pdf';
                } elseif ((int) $match[1] > 200) {
                    $reason = 'pdf_page_limit';
                } elseif (preg_match('~/(?:JavaScript|JS|Launch|EmbeddedFile)\b~', $body)) {
                    $reason = 'unsafe_pdf';
                }
            } finally {
                unlink($tmp);
            }
        }

        return ['body' => $body, 'mime' => $mime, 'width' => $width, 'height' => $height, 'rejection' => $reason];
    }
}
