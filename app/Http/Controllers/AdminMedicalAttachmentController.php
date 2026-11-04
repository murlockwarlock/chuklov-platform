<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Modules\Attachments\Application\AttachmentAuthorization;
use App\Modules\Attachments\Application\DownloadMedicalAttachment;
use App\Modules\Attachments\Application\GetTemporaryAttachmentUrl;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AdminMedicalAttachmentController extends Controller
{
    public function __invoke(Request $request, string $uuid, DownloadMedicalAttachment $downloadAction): StreamedResponse
    {
        abort_unless($request->hasValidSignature(), 403, 'Ссылка для скачивания недействительна или срок её действия истёк.');

        $mode = $request->query('mode');
        abort_unless(is_string($mode) && in_array($mode, ['download', 'preview'], true), 403);

        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $organization = app(AttachmentAuthorization::class)->organization();
        $attachment = MedicalAttachment::query()
            ->where('organization_id', $organization->getKey())
            ->where('uuid', $uuid)
            ->firstOrFail();

        if ($mode === 'preview') {
            abort_unless(GetTemporaryAttachmentUrl::supportsPreview($attachment->mime_type), 404);
        }

        $range = $this->range($request->header('Range'), (int) $attachment->size_bytes);
        $result = $downloadAction->handle($actor, $attachment);
        $status = $range === null ? 200 : 206;
        $start = $range === null ? 0 : $range['start'];
        $end = $range === null ? $result->sizeBytes - 1 : $range['end'];
        $length = $end - $start + 1;

        if (fseek($result->stream, $start) !== 0) {
            fclose($result->stream);
            abort(404);
        }

        $stream = static function () use ($result, $length): void {
            $remaining = $length;

            while ($remaining > 0 && ! feof($result->stream)) {
                $chunk = fread($result->stream, min(8192, $remaining));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                echo $chunk;
                $remaining -= strlen($chunk);
            }

            if (is_resource($result->stream)) {
                fclose($result->stream);
            }
        };
        $headers = [
            'Content-Type' => $result->mimeType,
            'Content-Length' => (string) $length,
            'Content-Disposition' => HeaderUtils::makeDisposition(
                $mode === 'preview' ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT,
                $result->filename,
                self::asciiFilename($result->filename),
            ),
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            ...($range === null ? [] : ['Content-Range' => "bytes {$start}-{$end}/{$result->sizeBytes}"]),
        ];

        return response()->stream($stream, $status, $headers);
    }

    private static function asciiFilename(string $filename): string
    {
        $fallback = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($filename));
        $fallback = is_string($fallback) ? trim($fallback, '._') : '';

        return $fallback !== '' ? $fallback : 'download';
    }

    /** @return array{start: int, end: int}|null */
    private function range(?string $header, int $size): ?array
    {
        if ($header === null || trim($header) === '') {
            return null;
        }

        if ($size < 1 || preg_match('/\Abytes=(\d*)-(\d*)\z/D', trim($header), $matches) !== 1) {
            abort(416, 'Неверный диапазон файла.');
        }

        $start = $matches[1] === '' ? null : (int) $matches[1];
        $end = $matches[2] === '' ? null : (int) $matches[2];

        if ($start === null && $end === null) {
            abort(416, 'Неверный диапазон файла.');
        }

        if ($start === null) {
            $length = min($end, $size);
            $start = $size - $length;
            $end = $size - 1;
        } else {
            if ($start >= $size) {
                abort(416, 'Диапазон файла недоступен.');
            }

            $end = min($end ?? ($size - 1), $size - 1);
        }

        if ($end < $start) {
            abort(416, 'Диапазон файла недоступен.');
        }

        return ['start' => $start, 'end' => $end];
    }
}
