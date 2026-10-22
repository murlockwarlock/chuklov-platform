<?php

namespace App\Filament\Support;

use App\Models\User;
use App\Modules\Attachments\Domain\Exceptions\InvalidAttachmentException;
use App\Modules\Attachments\Domain\Exceptions\UnsupportedDicomException;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ClientAttachmentUploadFailureReporter
{
    public function handle(Throwable $exception, User $actor, Client $client): bool
    {
        if ($exception instanceof ValidationException) {
            if (! array_key_exists('file_count', $exception->errors())) {
                return false;
            }

            $this->report(
                level: 'warning',
                event: 'crm.client_attachment.upload_rejected',
                safeErrorCode: 'medical_attachment_limit_reached',
                title: __('Файл не загружен'),
                body: __('В медицинском профиле уже пять файлов. Откройте список «Файлы и МРТ», чтобы проверить загруженные документы.'),
                actor: $actor,
                client: $client,
            );

            return true;
        }

        if ($exception instanceof AuthorizationException) {
            $this->report(
                level: 'warning',
                event: 'crm.client_attachment.upload_rejected',
                safeErrorCode: 'attachment_upload_forbidden',
                title: __('Нет доступа к загрузке файла'),
                body: __('Проверьте, что ваша учетная запись активна и у нее есть право работать с файлами клиента.'),
                actor: $actor,
                client: $client,
                exception: $exception,
            );

            return true;
        }

        if ($exception instanceof ModelNotFoundException) {
            $this->report(
                level: 'warning',
                event: 'crm.client_attachment.upload_rejected',
                safeErrorCode: 'client_record_unavailable',
                title: __('Карточка клиента недоступна'),
                body: __('Обновите страницу и откройте актуальную карточку клиента, затем повторите загрузку.'),
                actor: $actor,
                client: $client,
                exception: $exception,
            );

            return true;
        }

        if ($exception instanceof UnsupportedDicomException || $exception instanceof InvalidAttachmentException) {
            $errorCode = $exception instanceof UnsupportedDicomException
                ? 'unsupported_medical_file_type'
                : 'invalid_medical_attachment';
            $body = $exception instanceof UnsupportedDicomException
                ? __('Формат DICOM не поддерживается. Выберите PDF, текстовый файл, JPG, PNG или WebP размером до 20 МБ.')
                : __('Проверьте формат и размер файла. Поддерживаются PDF, текстовые файлы, JPG, PNG и WebP до 20 МБ.');

            $this->report(
                level: 'warning',
                event: 'crm.client_attachment.upload_rejected',
                safeErrorCode: $errorCode,
                title: __('Файл не загружен'),
                body: $body,
                actor: $actor,
                client: $client,
                exception: $exception,
            );

            return true;
        }

        $referenceId = (string) Str::uuid();
        $this->report(
            level: 'error',
            event: 'crm.client_attachment.upload_failed',
            safeErrorCode: 'private_storage_or_persistence_failed',
            title: __('Файл не загружен'),
            body: __('Не удалось сохранить файл. Повторите загрузку. Если ошибка повторится, сообщите администратору код :reference.', ['reference' => $referenceId]),
            actor: $actor,
            client: $client,
            exception: $exception,
            referenceId: $referenceId,
        );

        return true;
    }

    private function report(
        string $level,
        string $event,
        string $safeErrorCode,
        string $title,
        string $body,
        User $actor,
        Client $client,
        ?Throwable $exception = null,
        ?string $referenceId = null,
    ): void {
        $context = [
            'operation' => 'crm_medical_attachment_upload',
            'organization_id' => app(OrganizationContext::class)->id(),
            'actor_user_id' => $actor->getKey(),
            'entity_type' => 'client',
            'entity_id' => $client->getKey(),
            'safe_error_code' => $safeErrorCode,
            'outcome' => $level === 'error' ? 'failed' : 'rejected',
            'occurred_at_utc' => now()->utc()->toIso8601String(),
        ];

        if ($exception !== null) {
            $context['exception_class'] = $exception::class;
        }

        if ($referenceId !== null) {
            $context['reference_id'] = $referenceId;
        }

        Log::log($level, $event, $context);

        $notification = Notification::make()->title($title)->body($body);
        if ($level === 'error') {
            $notification->danger();
        } else {
            $notification->warning();
        }
        $notification->send();
    }
}
