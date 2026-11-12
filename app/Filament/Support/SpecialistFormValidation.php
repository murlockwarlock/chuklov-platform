<?php

namespace App\Filament\Support;

use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class SpecialistFormValidation
{
    private const FORM_FIELDS = [
        'display_name',
        'profile_image',
        'specialization',
        'practices_since_year',
        'country',
        'city',
        'qualification',
        'bio',
        'phone',
        'phone_visible',
        'email',
        'email_visible',
        'telegram',
        'telegram_visible',
        'timezone',
        'viewer_timezone',
        'staff_user_id',
        'notifications_enabled',
        'is_active',
    ];

    public static function notify(ValidationException $exception): void
    {
        $firstMessage = collect($exception->errors())
            ->flatten()
            ->filter(static fn (mixed $message): bool => is_string($message) && trim($message) !== '')
            ->first();

        Notification::make()
            ->danger()
            ->title(__('Не удалось сохранить специалиста. Исправьте отмеченные поля.'))
            ->body(is_string($firstMessage) ? $firstMessage : __('Проверьте заполнение полей.'))
            ->send();
    }

    public static function forForm(ValidationException $exception): ValidationException
    {
        $errors = [];

        foreach ($exception->errors() as $field => $messages) {
            $path = self::pathFor($field);

            if ($path === null) {
                continue;
            }

            $errors[$path] = [...($errors[$path] ?? []), ...array_values((array) $messages)];
        }

        return ValidationException::withMessages($errors);
    }

    /** @param array<string, mixed> $data */
    public static function fromInvalidArgument(InvalidArgumentException $exception, array $data): ValidationException
    {
        $message = $exception->getMessage();
        $field = match (true) {
            str_contains($message, 'specialist name') => 'display_name',
            str_contains($message, 'specialist timezone') => 'timezone',
            str_contains($message, 'practice year') => 'practices_since_year',
            str_contains($message, 'profile text') => self::longTextField($data),
            str_contains($message, 'profile value') => self::invalidProfileField($data),
            default => 'display_name',
        };

        $humanMessage = match (true) {
            $field === 'display_name' => __('Укажите корректные имя и фамилию специалиста.'),
            $field === 'timezone' => __('Выберите корректный часовой пояс специалиста.'),
            $field === 'practices_since_year' => __('Укажите корректный год начала практики.'),
            default => __('Проверьте заполнение поля.'),
        };

        return ValidationException::withMessages([$field => [$humanMessage]]);
    }

    private static function pathFor(string $field): ?string
    {
        if (str_starts_with($field, 'data.')) {
            return $field;
        }

        if (in_array($field, self::FORM_FIELDS, true)) {
            return 'data.'.$field;
        }

        return match ($field) {
            'viewer_timezone_source' => 'data.viewer_timezone',
            'schedule_impact' => 'data.acknowledge_impact',
            default => null,
        };
    }

    /** @param array<string, mixed> $data */
    private static function longTextField(array $data): string
    {
        return is_string($data['bio'] ?? null) && mb_strlen($data['bio']) > 10000
            ? 'bio'
            : 'qualification';
    }

    /** @param array<string, mixed> $data */
    private static function invalidProfileField(array $data): string
    {
        foreach (['specialization', 'qualification', 'bio', 'country', 'city'] as $field) {
            if (array_key_exists($field, $data) && ! is_string($data[$field]) && $data[$field] !== null) {
                return $field;
            }
        }

        return 'specialization';
    }
}
