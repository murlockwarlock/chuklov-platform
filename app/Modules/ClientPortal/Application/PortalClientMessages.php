<?php

namespace App\Modules\ClientPortal\Application;

use App\Support\SupportedLocale;
use Illuminate\Validation\ValidationException;

final class PortalClientMessages
{
    /** @var array<string, array{ru: string, en: string}> */
    private const Messages = [
        'request_invalid' => [
            'ru' => 'Не удалось обработать запрос. Обновите страницу и попробуйте ещё раз.',
            'en' => 'We could not process the request. Refresh the page and try again.',
        ],
        'companion_message_required' => [
            'ru' => 'Добавьте сообщение или изображение.',
            'en' => 'Add a message or an image.',
        ],
        'companion_request_conflict' => [
            'ru' => 'Этот запрос уже принят с другими данными.',
            'en' => 'This request was already accepted with different details.',
        ],
        'email_code_invalid' => [
            'ru' => 'Код неверный или уже истёк.',
            'en' => 'The code is incorrect or has expired.',
        ],
        'feedback_review' => [
            'ru' => 'Оставить отзыв',
            'en' => 'Leave a review',
        ],
        'referral_partner_activated' => [
            'ru' => 'Партнёрская программа подключена.',
            'en' => 'The partner program is now active.',
        ],
        'referral_link_created' => [
            'ru' => 'Ссылка создана.',
            'en' => 'Link created.',
        ],
        'referral_link_not_found' => [
            'ru' => 'Реферальная ссылка не найдена.',
            'en' => 'The referral link was not found.',
        ],
        'referral_payout_failed' => [
            'ru' => 'Не удалось отправить заявку. Попробуйте ещё раз.',
            'en' => 'We could not send the request. Try again.',
        ],
        'referral_payout_requested' => [
            'ru' => 'Заявка на выплату отправлена',
            'en' => 'Payout request sent',
        ],
        'referral_payout_cancelled' => [
            'ru' => 'Запрос на выплату отменён.',
            'en' => 'Payout request cancelled.',
        ],
        'b2b_date_time_invalid' => [
            'ru' => 'Выберите корректные дату и время.',
            'en' => 'Choose a valid date and time.',
        ],
        'health_test_title' => [
            'ru' => 'Тест',
            'en' => 'Test',
        ],
        'telegram_auth_failed' => [
            'ru' => 'Не удалось войти через Telegram. Закройте приложение и откройте его снова.',
            'en' => 'Telegram sign-in failed. Close the app and open it again.',
        ],
        'locale_unavailable' => [
            'ru' => 'Выберите доступный язык.',
            'en' => 'Choose an available language.',
        ],
        'onboarding_b2b_answer' => [
            'ru' => 'Выберите «да» или «нет».',
            'en' => 'Choose yes or no.',
        ],
        'onboarding_stage_current' => [
            'ru' => 'Сначала завершите текущий шаг.',
            'en' => 'Complete the current step first.',
        ],
        'onboarding_complete' => [
            'ru' => 'Заполнение профиля уже завершено.',
            'en' => 'This profile flow is already complete.',
        ],
        'onboarding_fields' => [
            'ru' => 'На этом шаге нельзя изменять эти данные.',
            'en' => 'These details cannot be changed at this step.',
        ],
        'profile_name_too_long' => [
            'ru' => 'Имя слишком длинное.',
            'en' => 'The name is too long.',
        ],
        'profile_email_invalid' => [
            'ru' => 'Введите корректный email.',
            'en' => 'Enter a valid email address.',
        ],
        'profile_email_too_long' => [
            'ru' => 'Email слишком длинный.',
            'en' => 'The email address is too long.',
        ],
        'profile_phone_too_long' => [
            'ru' => 'Телефон слишком длинный.',
            'en' => 'The phone number is too long.',
        ],
        'profile_timezone_too_long' => [
            'ru' => 'Часовой пояс указан слишком длинно.',
            'en' => 'The time zone is too long.',
        ],
        'consents_invalid' => [
            'ru' => 'Проверьте согласия с документами.',
            'en' => 'Check your document consents.',
        ],
        'consent_document_required' => [
            'ru' => 'Выберите документ.',
            'en' => 'Choose a document.',
        ],
        'consent_grant_required' => [
            'ru' => 'Подтвердите согласие.',
            'en' => 'Confirm your consent.',
        ],
        'email_required' => [
            'ru' => 'Введите email.',
            'en' => 'Enter your email address.',
        ],
        'code_required' => [
            'ru' => 'Введите код из письма.',
            'en' => 'Enter the code from the email.',
        ],
        'code_digits' => [
            'ru' => 'Код должен содержать 6 цифр.',
            'en' => 'The code must contain 6 digits.',
        ],
        'referral_name_required' => [
            'ru' => 'Укажите название ссылки.',
            'en' => 'Enter a link name.',
        ],
        'referral_name_min' => [
            'ru' => 'Название ссылки должно содержать не менее 2 символов.',
            'en' => 'The link name must contain at least 2 characters.',
        ],
        'referral_name_max' => [
            'ru' => 'Название ссылки слишком длинное.',
            'en' => 'The link name is too long.',
        ],
        'referral_channel_required' => [
            'ru' => 'Выберите канал.',
            'en' => 'Choose a channel.',
        ],
        'referral_channel_invalid' => [
            'ru' => 'Выберите канал из списка.',
            'en' => 'Choose a channel from the list.',
        ],
        'payout_retry' => [
            'ru' => 'Повторите отправку операции.',
            'en' => 'Submit the operation again.',
        ],
        'payout_form_retry' => [
            'ru' => 'Повторите отправку формы.',
            'en' => 'Submit the form again.',
        ],
        'payout_amount_required' => [
            'ru' => 'Укажите сумму выплаты.',
            'en' => 'Enter the payout amount.',
        ],
        'payout_amount_invalid' => [
            'ru' => 'Укажите положительную сумму в допустимом формате.',
            'en' => 'Enter a positive amount in a supported format.',
        ],
        'payout_currency_required' => [
            'ru' => 'Выберите валюту.',
            'en' => 'Choose a currency.',
        ],
        'payout_currency_invalid' => [
            'ru' => 'Выберите допустимую валюту.',
            'en' => 'Choose a supported currency.',
        ],
        'payout_partner_required' => [
            'ru' => 'Сначала активируйте партнёрскую программу.',
            'en' => 'Activate the partner program first.',
        ],
        'payout_amount_unavailable' => [
            'ru' => 'Сумма превышает доступный остаток.',
            'en' => 'The amount exceeds the available balance.',
        ],
        'attribution_detail_invalid' => [
            'ru' => 'Укажите имя, Telegram, телефон или другое уточнение текстом.',
            'en' => 'Enter a name, Telegram handle, phone number, or another detail.',
        ],
        'attribution_detail_too_long' => [
            'ru' => 'Укажите не более 500 символов.',
            'en' => 'Enter no more than 500 characters.',
        ],
        'tracker_note_required' => [
            'ru' => 'Напишите короткую отметку о сегодняшнем состоянии.',
            'en' => 'Write a short note about how you feel today.',
        ],
        'tracker_comment_too_long' => [
            'ru' => 'Комментарий должен быть не длиннее 500 символов.',
            'en' => 'The comment must be no longer than 500 characters.',
        ],
        'tracker_task_unavailable' => [
            'ru' => 'Эта задача сейчас недоступна для отметки.',
            'en' => 'This task is not available to update right now.',
        ],
        'tracker_access_required' => [
            'ru' => 'Для этого действия нужен доступ к трекеру.',
            'en' => 'Tracker access is required for this action.',
        ],
    ];

    public function message(string $key): string
    {
        $messages = self::Messages[$key] ?? self::Messages['request_invalid'];

        return $messages[SupportedLocale::normalize(app()->getLocale())];
    }

    /** @return array<string, string> */
    public function validationMessages(string $context): array
    {
        return match ($context) {
            'profile' => [
                'full_name.max' => $this->message('profile_name_too_long'),
                'email.email' => $this->message('profile_email_invalid'),
                'email.max' => $this->message('profile_email_too_long'),
                'phone.max' => $this->message('profile_phone_too_long'),
                'timezone.max' => $this->message('profile_timezone_too_long'),
            ],
            'onboarding' => [
                'full_name.max' => $this->message('profile_name_too_long'),
                'email.email' => $this->message('profile_email_invalid'),
                'email.max' => $this->message('profile_email_too_long'),
                'phone.max' => $this->message('profile_phone_too_long'),
                'consents.array' => $this->message('consents_invalid'),
                'consents.*.legal_document_id.required' => $this->message('consent_document_required'),
                'consents.*.granted.required' => $this->message('consent_grant_required'),
            ],
            'consents' => [
                'consents.array' => $this->message('consents_invalid'),
                'consents.present' => $this->message('consents_invalid'),
                'consents.*.legal_document_id.required' => $this->message('consent_document_required'),
                'consents.*.granted.required' => $this->message('consent_grant_required'),
            ],
            'email_request' => [
                'email.required' => $this->message('email_required'),
                'email.email' => $this->message('profile_email_invalid'),
                'email.max' => $this->message('profile_email_too_long'),
            ],
            'email_verify' => [
                'email.required' => $this->message('email_required'),
                'email.email' => $this->message('profile_email_invalid'),
                'code.required' => $this->message('code_required'),
                'code.digits' => $this->message('code_digits'),
            ],
            'referral_link' => [
                'name.required' => $this->message('referral_name_required'),
                'name.min' => $this->message('referral_name_min'),
                'name.max' => $this->message('referral_name_max'),
                'channel.required' => $this->message('referral_channel_required'),
                'channel.enum' => $this->message('referral_channel_invalid'),
            ],
            'payout' => [
                'amount.required' => $this->message('payout_amount_required'),
                'amount.regex' => $this->message('payout_amount_invalid'),
                'currency.required' => $this->message('payout_currency_required'),
                'currency.in' => $this->message('payout_currency_invalid'),
                'idempotency_key.required' => $this->message('payout_form_retry'),
            ],
            'payout_cancel' => [
                'idempotency_key.required' => $this->message('payout_retry'),
            ],
            'attribution' => [
                'source_detail.string' => $this->message('attribution_detail_invalid'),
                'source_detail.max' => $this->message('attribution_detail_too_long'),
            ],
            'tracker_check_in' => [
                'note.required' => $this->message('tracker_note_required'),
            ],
            'tracker_task' => [
                'comment.max' => $this->message('tracker_comment_too_long'),
            ],
            default => [],
        };
    }

    /** @return array<string, list<string>> */
    public function validationException(string $context, ValidationException $exception): array
    {
        $messages = [];

        foreach (array_keys($exception->errors()) as $field) {
            $messages[$field] = [$this->exceptionMessage($context, $field, $exception)];
        }

        return $messages === [] ? ['request' => [$this->message('request_invalid')]] : $messages;
    }

    private function exceptionMessage(string $context, string $field, ValidationException $exception): string
    {
        return match ($context) {
            'onboarding' => match ($field) {
                'b2b_specialist_answer' => $this->message('onboarding_b2b_answer'),
                'stage' => $this->message('onboarding_stage_current'),
                default => $this->message('request_invalid'),
            },
            'referral_link' => match ($field) {
                'name' => $this->message('referral_name_min'),
                'partner' => $this->message('payout_partner_required'),
                'link' => $this->message('referral_link_not_found'),
                default => $this->message('request_invalid'),
            },
            'payout' => match ($field) {
                'idempotency_key' => $this->message('payout_retry'),
                'partner' => $this->message('payout_partner_required'),
                'amount' => array_key_exists('currency', $exception->errors())
                    ? $this->message('payout_amount_invalid')
                    : $this->message('payout_amount_unavailable'),
                'currency' => $this->message('payout_currency_invalid'),
                default => $this->message('request_invalid'),
            },
            'tracker' => match ($field) {
                'note' => $this->message('tracker_note_required'),
                'comment' => $this->message('tracker_comment_too_long'),
                'task' => $this->message('tracker_task_unavailable'),
                'client' => $this->message('tracker_access_required'),
                default => $this->message('request_invalid'),
            },
            default => $this->message('request_invalid'),
        };
    }
}
