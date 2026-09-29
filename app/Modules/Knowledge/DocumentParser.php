<?php

declare(strict_types=1);

namespace App\Modules\Knowledge;

use App\Modules\AccessControl\StaffActor;
use App\Modules\Files\Files;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use Symfony\Component\Process\Process;

/** 使用受限 parser；PDF 不执行嵌入腳本，無文字的掃描文件明確回 OCR_REQUIRED。 */
final class DocumentParser
{
    public function parse(array $input): string
    {
        if (isset($input['body_text'])) {
            return $input['body_text'];
        }
        if (isset($input['source_url'])) {
            throw new ApiException(ErrorCode::CapabilityUnsupported, null, ['reason' => 'url_import_requires_reviewed_source']);
        }
        $files = app(Files::class);
        $file = $files->authorize(app(StaffActor::class), $input['source_file_id']);
        if ($file->purpose !== 'knowledge' || $file->scan_state !== 'clean') {
            throw new ApiException(ErrorCode::InvalidState);
        }
        $body = $files->disk($file)->get($file->object_key);
        if ($file->detected_mime !== 'application/pdf') {
            return $body;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'yacs-pdf-');
        file_put_contents($tmp, $body);
        try {
            $info = new Process(['pdfinfo', $tmp]);
            $info->setTimeout(10);
            $info->run();
            if (! $info->isSuccessful() || ! preg_match('/Pages:\s+(\d+)/', $info->getOutput(), $match)) {
                throw new ApiException(ErrorCode::ValidationFailed, null, ['reason' => 'parse_failed']);
            }
            if ((int) $match[1] > 200) {
                throw new ApiException(ErrorCode::PayloadTooLarge);
            }
            $parser = new Process(['pdftotext', '-layout', $tmp, '-']);
            $parser->setTimeout(20);
            $parser->mustRun();
            $text = $parser->getOutput();
            if (trim($text) === '') {
                throw new ApiException(ErrorCode::ValidationFailed, null, ['reason' => 'OCR_REQUIRED']);
            }
            if (strlen($text) > 2000000) {
                throw new ApiException(ErrorCode::PayloadTooLarge);
            }

            return $text;
        } finally {
            unlink($tmp);
        }
    }
}
