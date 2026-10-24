<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Modules\Attachments\Application\GetTemporaryAttachmentUrl;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\ClientPortal\Application\DownloadClientMedicalAttachment;
use App\Modules\ClientPortal\Application\GetClientTemporaryAttachmentUrl;
use App\Modules\ClientPortal\Domain\Exceptions\MedicalAttachmentDownloadFailed;
use App\Modules\Identity\Domain\Models\PatientProfile;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class MedicalAttachmentController extends Controller
{
    public function __invoke(
        Request $request,
        string $uuid,
        ClientPortalContext $context,
        GetClientTemporaryAttachmentUrl $urls,
        DownloadClientMedicalAttachment $download,
    ): Response {
        if (! $request->hasValidSignature()) {
            return $this->rejected($request, $context, $uuid, 'invalid_or_expired_signature', 403, __('Медицинская ссылка устарела или недействительна. Откройте медицинские данные и попробуйте снова.'));
        }

        $mode = $request->query('mode');
        if (! is_string($mode) || ! in_array($mode, ['download', 'preview'], true)) {
            return $this->rejected($request, $context, $uuid, 'invalid_download_mode', 403, __('Медицинская ссылка недействительна. Откройте медицинские данные и попробуйте снова.'));
        }

        try {
            $client = $context->client();
            $attachment = MedicalAttachment::query()
                ->where('organization_id', $client->organization_id)
                ->where(fn ($query) => $query->where('client_id', $client->getKey())->orWhereIn('client_id',
                    PatientProfile::query()->select('clinical_client_id')
                        ->where('organization_id', $client->organization_id)->where('linked_account_client_id', $client->getKey())))
                ->where('uuid', $uuid)
                ->first();
        } catch (Throwable $exception) {
            return $this->unavailable($request, $context, $uuid, $exception);
        }

        if (! $attachment instanceof MedicalAttachment) {
            return $this->rejected($request, $context, $uuid, 'attachment_not_found_or_unavailable', 404, __('Файл не найден. Откройте медицинские данные и попробуйте снова.'));
        }

        try {
            $urls->assertAccessible($attachment);
        } catch (AuthorizationException) {
            return $this->rejected($request, $context, $uuid, 'attachment_not_available', 404, __('Файл не найден. Откройте медицинские данные и попробуйте снова.'));
        } catch (Throwable $exception) {
            return $this->unavailable($request, $context, $uuid, $exception);
        }

        if ($mode === 'preview') {
            if (! GetTemporaryAttachmentUrl::supportsPreview($attachment->mime_type)) {
                return $this->rejected($request, $context, $uuid, 'attachment_preview_not_supported', 404, __('Предпросмотр этого файла недоступен. Скачайте файл или откройте медицинские данные снова.'));
            }
        }

        try {
            $result = $download->handle($attachment);
        } catch (AuthorizationException) {
            return $this->rejected($request, $context, $uuid, 'attachment_not_available', 404, __('Файл не найден. Откройте медицинские данные и попробуйте снова.'));
        } catch (Throwable $exception) {
            return $this->unavailable($request, $context, $uuid, $exception);
        }

        $stream = static function () use ($result): void {
            fpassthru($result->stream);
            if (is_resource($result->stream)) {
                fclose($result->stream);
            }
        };

        return response()->stream($stream, 200, [
            'Content-Type' => $result->mimeType,
            'Content-Length' => (string) $result->sizeBytes,
            'Content-Disposition' => HeaderUtils::makeDisposition(
                $mode === 'preview' ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT,
                $result->filename,
                'download',
            ),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function rejected(Request $request, ClientPortalContext $context, string $uuid, string $errorCode, int $status, string $message): Response
    {
        $client = $context->client();
        $entityId = Str::isUuid($uuid) ? $uuid : null;

        Log::warning('portal.medical_attachment.access_denied', [
            'operation' => 'download',
            'organization_id' => $client->organization_id,
            'actor_type' => 'client',
            'actor_client_id' => $client->getKey(),
            'entity_type' => $entityId === null ? null : 'medical_attachment',
            'entity_id' => $entityId,
            'safe_error_code' => $errorCode,
            'outcome' => 'denied',
            'response_status' => $status,
            'occurred_at_utc' => now()->utc()->toIso8601String(),
        ]);

        return $this->response($request, $message, $status);
    }

    private function unavailable(Request $request, ClientPortalContext $context, string $uuid, Throwable $exception): Response
    {
        $client = $context->client();
        $entityId = Str::isUuid($uuid) ? $uuid : null;
        $cause = $exception->getPrevious() ?? $exception;

        $failureStage = $exception instanceof MedicalAttachmentDownloadFailed ? $exception->failureStage : 'request_or_database';
        $errorCode = match ($failureStage) {
            'private_storage_read' => 'private_storage_read_failed',
            'audit_write' => 'download_audit_write_failed',
            default => 'attachment_download_unavailable',
        };

        Log::error('portal.medical_attachment.download_failed', [
            'operation' => 'download',
            'organization_id' => $client->organization_id,
            'actor_type' => 'client',
            'actor_client_id' => $client->getKey(),
            'entity_type' => $entityId === null ? null : 'medical_attachment',
            'entity_id' => $entityId,
            'storage_disk' => 'private',
            'failure_stage' => $failureStage,
            'safe_error_code' => $errorCode,
            'exception_class' => $cause::class,
            'outcome' => 'failed',
            'response_status' => Response::HTTP_SERVICE_UNAVAILABLE,
            'occurred_at_utc' => now()->utc()->toIso8601String(),
        ]);

        return $this->response($request, __('Не удалось открыть файл. Повторите попытку. Если ошибка повторится, снова откройте медицинские данные.'), Response::HTTP_SERVICE_UNAVAILABLE);
    }

    private function response(Request $request, string $message, int $status): Response
    {
        $response = $request->expectsJson()
            ? response()->json(['message' => $message], $status)
            : response($message, $status)->header('Content-Type', 'text/plain; charset=UTF-8');

        return $response->header('Cache-Control', 'private, no-store');
    }
}
