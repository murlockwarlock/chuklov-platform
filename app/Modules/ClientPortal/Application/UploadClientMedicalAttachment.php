<?php

namespace App\Modules\ClientPortal\Application;

use App\Modules\Attachments\Application\DTOs\AttachmentUploadCommand;
use App\Modules\Attachments\Application\UploadMedicalAttachment;
use App\Modules\Attachments\Domain\Exceptions\InvalidAttachmentException;
use App\Modules\Attachments\Domain\Exceptions\UnsupportedDicomException;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use App\Modules\ClientPortal\Domain\Exceptions\MedicalAttachmentUploadFailed;
use App\Modules\Identity\Application\ResolvePatientClinicalClient;
use App\Modules\Identity\Domain\Models\Client;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

final readonly class UploadClientMedicalAttachment
{
    public function __construct(private ClientPortalContext $context, private UploadMedicalAttachment $upload, private ResolvePatientClinicalClient $patients) {}

    public function handle(UploadedFile $file, string $uploadKey, ?int $patientId = null): MedicalAttachment
    {
        $client = $this->context->client();
        $validator = Validator::make(['file' => $file, 'upload_key' => $uploadKey], [
            'file' => ['required', 'file', 'mimetypes:image/jpeg,image/png,application/pdf', 'max:20480'],
            'upload_key' => ['required', 'uuid'],
        ]);

        if ($validator->fails()) {
            $this->logFailure('warning', 'portal.medical_attachment.upload_rejected', $client, $patientId, $this->validationErrorCode($validator->failed()), 422);
            throw new ValidationException($validator);
        }

        try {
            $subject = $this->patients->handle($client, $patientId);
        } catch (AuthorizationException $exception) {
            $this->logFailure('warning', 'portal.medical_attachment.upload_rejected', $client, $patientId, 'patient_access_denied', 403, $exception);

            throw $exception;
        } catch (ModelNotFoundException $exception) {
            $this->logFailure('warning', 'portal.medical_attachment.upload_rejected', $client, $patientId, 'patient_not_found', 404, $exception);

            throw $exception;
        } catch (ValidationException $exception) {
            $this->logFailure('warning', 'portal.medical_attachment.upload_rejected', $client, $patientId, 'patient_selection_invalid', 422);

            throw $exception;
        } catch (Throwable $exception) {
            $this->logFailure('error', 'portal.medical_attachment.upload_failed', $client, $patientId, 'patient_resolution_failed', 503, $exception);

            throw new MedicalAttachmentUploadFailed('Medical attachment upload could not be completed.', 0, $exception);
        }

        try {
            return $this->upload->handle($client, new AttachmentUploadCommand($file, (int) $subject->getKey(), uploadKey: $uploadKey));
        } catch (InvalidAttachmentException|UnsupportedDicomException $exception) {
            $this->logFailure('warning', 'portal.medical_attachment.upload_rejected', $client, $patientId, 'unsupported_file_type', 422, $exception);

            throw ValidationException::withMessages(['file' => __('Файл не прошёл проверку. Выберите JPG, PNG или PDF размером до 20 МБ.')]);
        } catch (ValidationException $exception) {
            $errorCode = array_key_exists('upload_key', $exception->errors())
                ? 'duplicate_upload_key'
                : 'attachment_validation_failed';
            $this->logFailure('warning', 'portal.medical_attachment.upload_rejected', $client, $patientId, $errorCode, 422);

            throw $exception;
        } catch (Throwable $exception) {
            $this->logFailure('error', 'portal.medical_attachment.upload_failed', $client, $patientId, 'private_storage_or_persistence_failed', 503, $exception);

            throw new MedicalAttachmentUploadFailed('Medical attachment upload could not be completed.', 0, $exception);
        }
    }

    /** @param array<string, array<string, mixed>> $failed */
    private function validationErrorCode(array $failed): string
    {
        if (isset($failed['file']['Mimetypes'])) {
            return 'unsupported_file_type';
        }
        if (isset($failed['file']['Max'])) {
            return 'file_too_large';
        }
        if (isset($failed['upload_key'])) {
            return 'invalid_upload_key';
        }

        return 'invalid_upload';
    }

    private function logFailure(
        string $level,
        string $event,
        Client $client,
        ?int $patientId,
        string $errorCode,
        int $responseStatus,
        ?Throwable $exception = null,
    ): void {
        $context = [
            'operation' => 'upload',
            'organization_id' => $client->organization_id,
            'actor_type' => 'client',
            'actor_client_id' => $client->getKey(),
            'entity_type' => $patientId === null ? 'client' : 'patient',
            'entity_id' => $patientId ?? $client->getKey(),
            'safe_error_code' => $errorCode,
            'outcome' => $level === 'error' ? 'failed' : 'rejected',
            'response_status' => $responseStatus,
            'occurred_at_utc' => now()->utc()->toIso8601String(),
        ];

        if ($exception !== null) {
            $cause = $exception->getPrevious() ?? $exception;
            $context['exception_class'] = $cause::class;
        }

        Log::log($level, $event, $context);
    }
}
