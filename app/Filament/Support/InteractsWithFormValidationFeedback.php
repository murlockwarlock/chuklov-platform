<?php

namespace App\Filament\Support;

use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

trait InteractsWithFormValidationFeedback
{
    protected function onValidationError(ValidationException $exception): void
    {
        FormValidationFeedback::notifyValidation($exception);
    }

    /**
     * @param  array<string, mixed>|null  $state
     * @param  array<string, string>  $aliases
     * @param  list<string>  $preserve
     */
    protected function formValidationFailure(
        ValidationException $exception,
        ?array $state = null,
        array $aliases = [],
        array $preserve = [],
    ): ValidationException {
        if ($state !== null) {
            $this->form->fill($state);
        }

        $mapped = FormValidationFeedback::forForm($exception, $aliases, $preserve);
        FormValidationFeedback::notifyValidation($mapped);

        return $mapped;
    }

    /** @param array<string, mixed>|null $state */
    protected function unexpectedFormFailure(Throwable $exception, ?array $state = null): ValidationException
    {
        if ($state !== null) {
            $this->form->fill($state);
        }

        $mapped = FormValidationFeedback::unexpectedForForm($exception);
        FormValidationFeedback::notifyValidation($mapped);

        return $mapped;
    }

    /**
     * @param  array<string, mixed>|null  $state
     * @param  array<string, int|null>  $context
     */
    protected function unexpectedAutosaveFailure(
        Throwable $exception,
        ?array $state = null,
        array $context = [],
    ): ValidationException {
        if ($state !== null) {
            $this->form->fill($state);
        }

        $referenceId = (string) Str::uuid();
        Log::error('crm.medical_session.autosave_failed', [
            'operation' => 'crm_medical_session_autosave',
            'organization_id' => $context['organization_id'] ?? null,
            'actor_user_id' => $context['actor_user_id'] ?? null,
            'entity_type' => 'medical_session',
            'entity_id' => $context['entity_id'] ?? null,
            'safe_error_code' => 'medical_session_autosave_failed',
            'exception_class' => $exception::class,
            'exception_code' => is_scalar($exception->getCode()) ? (string) $exception->getCode() : null,
            'request_id' => request()->header('X-Request-Id') ?? request()->header('X-Correlation-Id'),
            'reference_id' => $referenceId,
            'occurred_at_utc' => now()->utc()->toIso8601String(),
        ]);

        return ValidationException::withMessages([
            'data.__form' => [__('Не удалось сохранить изменения. Код :reference.', ['reference' => $referenceId])],
        ]);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('Изменения сохранены');
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return __('Изменения сохранены');
    }
}
