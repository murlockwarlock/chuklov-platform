<?php

namespace App\Filament\Support;

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
        'schedule_impact',
    ];

    public static function notify(ValidationException $exception): void
    {
        FormValidationFeedback::notifyValidation(
            $exception,
            __('Не удалось сохранить специалиста. Исправьте отмеченные поля.'),
        );
    }

    public static function forForm(ValidationException $exception): ValidationException
    {
        return FormValidationFeedback::forForm(
            $exception,
            aliases: [
                'viewer_timezone_source' => 'viewer_timezone',
                'schedule_impact' => 'acknowledge_impact',
            ],
            allowedFields: self::FORM_FIELDS,
        );
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
