<?php

namespace App\Filament\Support;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;
use Throwable;

final class FormValidationFeedback
{
    public const VALIDATION_TITLE = 'Не удалось сохранить. Исправьте отмеченные поля.';

    public const ACTION_TITLE = 'Не удалось выполнить действие. Попробуйте ещё раз.';

    private const GENERIC_MESSAGE = 'Не удалось выполнить действие. Попробуйте ещё раз.';

    /**
     * @param  array<string, string>  $aliases
     * @param  list<string>  $preserve
     * @param  list<string>|null  $allowedFields
     */
    public static function forForm(
        ValidationException $exception,
        array $aliases = [],
        array $preserve = [],
        ?array $allowedFields = null,
    ): ValidationException {
        return ValidationException::withMessages(self::mapErrors(
            $exception->errors(),
            static function (string $field) use ($aliases): string {
                if (str_starts_with($field, 'data.') || str_starts_with($field, 'mountedActions.')) {
                    return $field;
                }

                return 'data.'.($aliases[$field] ?? $field);
            },
            $preserve,
            $allowedFields,
        ));
    }

    public static function forAction(ValidationException $exception, Action $action): ValidationException
    {
        $prefix = 'mountedActions.'.$action->getNestingIndex().'.data.';

        return ValidationException::withMessages(self::mapErrors(
            $exception->errors(),
            static fn (string $field): string => str_starts_with($field, 'mountedActions.')
                ? $field
                : $prefix.$field,
        ));
    }

    public static function unexpectedForForm(Throwable $exception): ValidationException
    {
        report($exception);

        return ValidationException::withMessages([
            'data.__form' => [self::GENERIC_MESSAGE],
        ]);
    }

    public static function unexpectedForAction(Throwable $exception, Action $action): ValidationException
    {
        report($exception);

        return ValidationException::withMessages([
            'mountedActions.'.$action->getNestingIndex().'.data.__form' => [self::GENERIC_MESSAGE],
        ]);
    }

    public static function notifyValidation(ValidationException $exception, ?string $title = null): void
    {
        $generic = collect($exception->errors())
            ->flatten()
            ->contains(static fn (mixed $message): bool => $message === self::GENERIC_MESSAGE);

        Notification::make()
            ->danger()
            ->title(__($generic ? self::ACTION_TITLE : ($title ?? self::VALIDATION_TITLE)))
            ->body(__($generic ? self::GENERIC_MESSAGE : 'Проверьте поля, отмеченные ошибкой.'))
            ->send();
    }

    /**
     * @param  array<string, array<int, mixed>>  $errors
     * @param  callable(string): string  $path
     * @param  list<string>  $preserve
     * @param  list<string>|null  $allowedFields
     * @return array<string, array<int, string>>
     */
    private static function mapErrors(array $errors, callable $path, array $preserve = [], ?array $allowedFields = null): array
    {
        $mapped = [];

        foreach ($errors as $field => $messages) {
            $rootField = explode('.', $field, 2)[0];
            if ($allowedFields !== null
                && ! str_starts_with($field, 'data.')
                && ! str_starts_with($field, 'mountedActions.')
                && ! in_array($rootField, $allowedFields, true)
            ) {
                continue;
            }

            $messages = array_values(array_filter(
                (array) $messages,
                static fn (mixed $message): bool => is_string($message) && trim($message) !== '',
            ));

            if ($messages === []) {
                continue;
            }

            $mapped[$path($field)] = [...($mapped[$path($field)] ?? []), ...$messages];

            if (in_array($field, $preserve, true)) {
                $mapped[$field] = [...($mapped[$field] ?? []), ...$messages];
            }
        }

        return $mapped;
    }
}
