<?php

namespace App\Modules\ClientPortal\Application;

use Illuminate\Validation\ValidationException;

final class PortalBookingErrorMessages
{
    /** @var array<string, array{ru: string, en: string}> */
    private const Messages = [
        'address_too_long' => [
            'ru' => 'Адрес слишком длинный.',
            'en' => 'The address is too long.',
        ],
        'availability_range_invalid' => [
            'ru' => 'Выберите корректный месяц.',
            'en' => 'Choose a valid month.',
        ],
        'availability_range_too_large' => [
            'ru' => 'Выберите один месяц за раз.',
            'en' => 'Choose one month at a time.',
        ],
        'booking_action_failed' => [
            'ru' => 'Не удалось изменить запись. Попробуйте ещё раз.',
            'en' => 'We could not update the booking. Try again.',
        ],
        'booking_cancel_unavailable' => [
            'ru' => 'Отменить запись онлайн уже нельзя. Свяжитесь с нами.',
            'en' => 'This booking can no longer be cancelled online. Please contact us.',
        ],
        'booking_create_failed' => [
            'ru' => 'Не удалось создать запись. Попробуйте ещё раз.',
            'en' => 'We could not create the booking. Try again.',
        ],
        'booking_created' => [
            'ru' => 'Запись создана.',
            'en' => 'Booking created.',
        ],
        'booking_reschedule_failed' => [
            'ru' => 'Не удалось перенести запись. Попробуйте ещё раз.',
            'en' => 'We could not reschedule the booking. Try again.',
        ],
        'booking_reschedule_unavailable' => [
            'ru' => 'Перенести запись онлайн уже нельзя. Свяжитесь с нами.',
            'en' => 'This booking can no longer be rescheduled online. Please contact us.',
        ],
        'booking_stale' => [
            'ru' => 'Запись изменилась. Обновите страницу и выберите время ещё раз.',
            'en' => 'This booking changed. Refresh the page and choose a time again.',
        ],
        'comment_too_long' => [
            'ru' => 'Комментарий слишком длинный.',
            'en' => 'The comment is too long.',
        ],
        'date_invalid' => [
            'ru' => 'Выберите корректную дату.',
            'en' => 'Choose a valid date.',
        ],
        'date_time_invalid' => [
            'ru' => 'Выберите корректные дату и время.',
            'en' => 'Choose a valid date and time.',
        ],
        'format_unavailable' => [
            'ru' => 'Выберите другой формат для этой услуги.',
            'en' => 'Choose another format for this service.',
        ],
        'format_from_list' => [
            'ru' => 'Выберите формат визита из списка.',
            'en' => 'Choose a visit format from the list.',
        ],
        'select_format' => [
            'ru' => 'Выберите формат визита.',
            'en' => 'Choose a visit format.',
        ],
        'select_new_time' => [
            'ru' => 'Выберите новое время.',
            'en' => 'Choose a new time.',
        ],
        'select_service' => [
            'ru' => 'Выберите услугу.',
            'en' => 'Choose a service.',
        ],
        'select_service_from_list' => [
            'ru' => 'Выберите услугу из списка.',
            'en' => 'Choose a service from the list.',
        ],
        'select_specialist' => [
            'ru' => 'Выберите специалиста.',
            'en' => 'Choose a specialist.',
        ],
        'select_specialist_from_list' => [
            'ru' => 'Выберите специалиста из списка.',
            'en' => 'Choose a specialist from the list.',
        ],
        'select_time' => [
            'ru' => 'Выберите дату и время.',
            'en' => 'Choose a date and time.',
        ],
        'self_booking_unavailable' => [
            'ru' => 'Самостоятельная запись сейчас недоступна. Свяжитесь с нами.',
            'en' => 'Self-service booking is unavailable. Please contact us.',
        ],
        'service_unavailable' => [
            'ru' => 'Эта услуга сейчас недоступна.',
            'en' => 'This service is not available.',
        ],
        'specialist_unavailable' => [
            'ru' => 'Сейчас для этой услуги нет доступного специалиста.',
            'en' => 'There is no available specialist for this service right now.',
        ],
        'slot_unavailable' => [
            'ru' => 'Это время уже недоступно. Выберите другое.',
            'en' => 'This time is no longer available. Choose another.',
        ],
        'timezone_detect_failed' => [
            'ru' => 'Не удалось определить часовой пояс. Обновите страницу.',
            'en' => 'We could not determine your time zone. Refresh the page.',
        ],
        'timezone_invalid' => [
            'ru' => 'Выберите корректный часовой пояс.',
            'en' => 'Choose a valid time zone.',
        ],
        'timezone_save_failed' => [
            'ru' => 'Не удалось сохранить часовой пояс.',
            'en' => 'We could not save the time zone.',
        ],
        'timezone_select' => [
            'ru' => 'Выберите часовой пояс.',
            'en' => 'Choose a time zone.',
        ],
        'party_size_invalid' => [
            'ru' => 'Укажите корректное количество человек.',
            'en' => 'Enter a valid number of people.',
        ],
        'party_size_max' => [
            'ru' => 'Количество человек не может быть больше 20.',
            'en' => 'The number of people cannot be greater than 20.',
        ],
        'party_size_min' => [
            'ru' => 'Количество человек должно быть не меньше одного.',
            'en' => 'The number of people must be at least one.',
        ],
        'provide_address' => [
            'ru' => 'Укажите адрес выезда.',
            'en' => 'Enter the visit address.',
        ],
        'request_invalid' => [
            'ru' => 'Не удалось обработать запрос. Обновите страницу и попробуйте ещё раз.',
            'en' => 'We could not process the request. Refresh the page and try again.',
        ],
        'request_sent' => [
            'ru' => 'Заявка отправлена. Мы подтвердим время отдельно.',
            'en' => 'Request sent. We will confirm the time separately.',
        ],
    ];

    public function message(string $key): string
    {
        $messages = self::Messages[$key] ?? self::Messages['request_invalid'];

        return $messages[app()->getLocale() === 'en' ? 'en' : 'ru'];
    }

    /** @return array<string, string> */
    public function creationRequestMessages(): array
    {
        return [
            'service_id.required' => $this->message('select_service'),
            'service_id.integer' => $this->message('select_service_from_list'),
            'service_id.min' => $this->message('select_service_from_list'),
            'specialist_id.required' => $this->message('select_specialist'),
            'specialist_id.integer' => $this->message('select_specialist_from_list'),
            'specialist_id.min' => $this->message('select_specialist_from_list'),
            'starts_at.required' => $this->message('select_time'),
            'starts_at.date' => $this->message('date_time_invalid'),
            'format.required' => $this->message('select_format'),
            'format.string' => $this->message('format_from_list'),
            'format.in' => $this->message('format_from_list'),
            'party_size.integer' => $this->message('party_size_invalid'),
            'party_size.min' => $this->message('party_size_min'),
            'party_size.max' => $this->message('party_size_max'),
            'location.string' => $this->message('provide_address'),
            'location.max' => $this->message('address_too_long'),
        ];
    }

    /** @return array<string, string> */
    public function queryRequestMessages(): array
    {
        return [
            'reschedule.boolean' => $this->message('request_invalid'),
            'service_id.integer' => $this->message('select_service_from_list'),
            'service_id.min' => $this->message('select_service_from_list'),
            'specialist_id.integer' => $this->message('select_specialist_from_list'),
            'specialist_id.min' => $this->message('select_specialist_from_list'),
            'date_from.date_format' => $this->message('date_invalid'),
            'date_to.date_format' => $this->message('date_invalid'),
            'format.string' => $this->message('format_from_list'),
            'format.in' => $this->message('format_from_list'),
            'display_timezone.string' => $this->message('timezone_detect_failed'),
            'display_timezone.max' => $this->message('timezone_detect_failed'),
        ];
    }

    /** @return array<string, string> */
    public function rescheduleRequestMessages(): array
    {
        return [
            'starts_at.required' => $this->message('select_new_time'),
            'starts_at.date' => $this->message('date_time_invalid'),
            'expected_event_version.required' => $this->message('booking_stale'),
            'expected_event_version.integer' => $this->message('booking_stale'),
            'expected_event_version.min' => $this->message('booking_stale'),
            'client_timezone.string' => $this->message('timezone_save_failed'),
            'client_timezone.max' => $this->message('timezone_save_failed'),
            'reason.string' => $this->message('comment_too_long'),
            'reason.max' => $this->message('comment_too_long'),
        ];
    }

    /** @return array<string, string> */
    public function actionRequestMessages(): array
    {
        return [
            'reason.string' => $this->message('comment_too_long'),
            'reason.max' => $this->message('comment_too_long'),
        ];
    }

    /** @return array<string, string> */
    public function availabilityRequestMessages(): array
    {
        return [
            'specialist_id.required' => $this->message('select_specialist'),
            'specialist_id.integer' => $this->message('select_specialist_from_list'),
            'specialist_id.min' => $this->message('select_specialist_from_list'),
            'service_id.required' => $this->message('select_service'),
            'service_id.integer' => $this->message('select_service_from_list'),
            'service_id.min' => $this->message('select_service_from_list'),
            'date_from.required' => $this->message('date_invalid'),
            'date_from.date_format' => $this->message('date_invalid'),
            'date_to.required' => $this->message('date_invalid'),
            'date_to.date_format' => $this->message('date_invalid'),
            'format.required' => $this->message('select_format'),
            'format.string' => $this->message('format_from_list'),
            'format.in' => $this->message('format_from_list'),
            'display_timezone.string' => $this->message('timezone_detect_failed'),
            'display_timezone.max' => $this->message('timezone_detect_failed'),
        ];
    }

    /** @return array<string, string> */
    public function timezonePreferenceRequestMessages(): array
    {
        return [
            'timezone.required' => $this->message('timezone_select'),
            'timezone.string' => $this->message('timezone_save_failed'),
            'timezone.max' => $this->message('timezone_save_failed'),
        ];
    }

    /** @return array<string, list<string>> */
    public function bookingErrors(ValidationException $exception): array
    {
        $errors = [];

        foreach ($exception->errors() as $field => $_messages) {
            [$displayField, $key] = match ($field) {
                'startsAt' => ['starts_at', 'slot_unavailable'],
                'client' => ['starts_at', 'self_booking_unavailable'],
                'service', 'service_id' => ['service_id', 'service_unavailable'],
                'specialist', 'specialist_id', 'assignment' => ['specialist_id', 'specialist_unavailable'],
                'format', 'meetingLinkMode' => ['format', 'format_unavailable'],
                'partySize' => ['party_size', 'party_size_invalid'],
                'location' => ['location', 'provide_address'],
                'clientTimezone' => ['starts_at', 'timezone_invalid'],
                default => ['starts_at', 'booking_create_failed'],
            };
            $errors[$displayField][] = $this->message($key);
        }

        return $errors;
    }

    /** @return array<string, list<string>> */
    public function rescheduleErrors(ValidationException $exception): array
    {
        $errors = [];

        foreach ($exception->errors() as $field => $_messages) {
            [$displayField, $key] = match ($field) {
                'startsAt' => ['starts_at', 'slot_unavailable'],
                'booking' => ['booking', 'booking_reschedule_unavailable'],
                'expected_event_version' => ['expected_event_version', 'booking_stale'],
                'clientTimezone' => ['booking', 'timezone_invalid'],
                default => ['booking', 'booking_reschedule_failed'],
            };
            $errors[$displayField][] = $this->message($key);
        }

        return $errors;
    }

    /** @return array<string, list<string>> */
    public function bookingActionErrors(ValidationException $exception): array
    {
        $errors = [];

        foreach ($exception->errors() as $field => $_messages) {
            $errors['booking'][] = $this->message(
                $field === 'booking' ? 'booking_cancel_unavailable' : 'booking_action_failed',
            );
        }

        return $errors;
    }

    /** @return array<string, list<string>> */
    public function availabilityErrors(ValidationException $exception): array
    {
        $errors = [];

        foreach ($exception->errors() as $field => $_messages) {
            [$displayField, $key] = match ($field) {
                'dateFrom' => ['date_from', 'availability_range_invalid'],
                'dateTo' => ['date_to', 'availability_range_too_large'],
                'service' => ['service_id', 'service_unavailable'],
                'specialist', 'assignment' => ['specialist_id', 'specialist_unavailable'],
                'format' => ['format', 'format_unavailable'],
                'clientTimezone', 'timezone' => ['display_timezone', 'timezone_invalid'],
                default => ['date_from', 'request_invalid'],
            };
            $errors[$displayField][] = $this->message($key);
        }

        return $errors;
    }

    /** @return array<string, list<string>> */
    public function timezoneErrors(ValidationException $exception): array
    {
        $errors = [];

        foreach ($exception->errors() as $field => $_messages) {
            $errors[$field][] = $this->message('timezone_invalid');
        }

        return $errors;
    }
}
