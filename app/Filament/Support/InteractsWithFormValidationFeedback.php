<?php

namespace App\Filament\Support;

use Illuminate\Validation\ValidationException;
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

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('Изменения сохранены');
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return __('Изменения сохранены');
    }
}
