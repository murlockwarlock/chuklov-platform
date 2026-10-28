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
        'email_code_delivery_failed' => [
            'ru' => 'Не удалось отправить код. Попробуйте позже или войдите через Telegram.',
            'en' => 'We could not send the code. Try again later or sign in with Telegram.',
        ],
        'email_code_rate_limited' => [
            'ru' => 'Вы запрашивали код слишком часто. Подождите немного и попробуйте снова.',
            'en' => 'Too many code requests. Wait a little and try again.',
        ],
        'family_name_required' => [
            'ru' => 'Укажите имя и фамилию пациента.',
            'en' => 'Enter the patient’s first and last name.',
        ],
        'family_name_too_long' => [
            'ru' => 'Имя пациента слишком длинное.',
            'en' => 'The patient’s name is too long.',
        ],
        'family_gender_required' => [
            'ru' => 'Выберите пол пациента.',
            'en' => 'Choose the patient’s gender.',
        ],
        'family_gender_invalid' => [
            'ru' => 'Выберите пол пациента из списка.',
            'en' => 'Choose a gender from the list.',
        ],
        'family_birth_date_required' => [
            'ru' => 'Укажите дату рождения пациента.',
            'en' => 'Enter the patient’s date of birth.',
        ],
        'family_birth_date_invalid' => [
            'ru' => 'Укажите корректную дату рождения.',
            'en' => 'Enter a valid date of birth.',
        ],
        'family_birth_date_future' => [
            'ru' => 'Дата рождения не может быть в будущем.',
            'en' => 'The date of birth cannot be in the future.',
        ],
        'family_relationship_required' => [
            'ru' => 'Выберите родство.',
            'en' => 'Choose the family relationship.',
        ],
        'family_relationship_invalid' => [
            'ru' => 'Выберите родство из списка.',
            'en' => 'Choose a relationship from the list.',
        ],
        'family_form_retry' => [
            'ru' => 'Форма устарела. Обновите страницу и заполните её заново.',
            'en' => 'This form has expired. Refresh the page and fill it in again.',
        ],
        'family_telegram_unavailable' => [
            'ru' => 'Создание Telegram-приглашений сейчас недоступно. Попробуйте позже.',
            'en' => 'Telegram invitations are temporarily unavailable. Try again later.',
        ],
        'family_created' => [
            'ru' => 'Член семьи добавлен.',
            'en' => 'Family member added.',
        ],
        'family_updated' => [
            'ru' => 'Данные пациента сохранены.',
            'en' => 'Patient details saved.',
        ],
        'family_unlinked' => [
            'ru' => 'Семейная связь отозвана.',
            'en' => 'Family relationship revoked.',
        ],
        'family_invitation_accepted' => [
            'ru' => 'Приглашение принято.',
            'en' => 'Invitation accepted.',
        ],
        'family_invitation_expired' => [
            'ru' => 'Срок приглашения истёк. Попросите отправить новое.',
            'en' => 'This invitation has expired. Ask the sender for a new one.',
        ],
        'family_invitation_revoked' => [
            'ru' => 'Приглашение отозвано. Попросите отправить новое.',
            'en' => 'This invitation was revoked. Ask the sender for a new one.',
        ],
        'family_invitation_wrong_account' => [
            'ru' => 'Это приглашение связано с другим аккаунтом. Войдите в аккаунт получателя или попросите отправить новое приглашение.',
            'en' => 'This invitation belongs to a different account. Sign in to the invited account or ask for a new invitation.',
        ],
        'feedback_review' => [
            'ru' => 'Оставить отзыв',
            'en' => 'Leave a review',
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
        'referral_payout_already_exists' => [
            'ru' => 'Эта заявка уже существует. Проверьте её статус в истории выплат.',
            'en' => 'This payout request already exists. Check its status in payout history.',
        ],
        'referral_payout_cancelled' => [
            'ru' => 'Запрос на выплату отменён.',
            'en' => 'Payout request cancelled.',
        ],
        'referral_partner_required' => [
            'ru' => 'Создание кампаний доступно только партнёрам.',
            'en' => 'Campaign links are available to partners only.',
        ],
        'referral_credit_applied' => [
            'ru' => 'Бонус применён к оплате.',
            'en' => 'Referral credit applied to the payment.',
        ],
        'referral_credit_amount_invalid' => [
            'ru' => 'Укажите сумму бонуса в допустимом формате.',
            'en' => 'Enter a valid referral credit amount.',
        ],
        'referral_credit_unavailable' => [
            'ru' => 'Сумма превышает доступный бонус или остаток к оплате.',
            'en' => 'The amount exceeds the available credit or remaining balance.',
        ],
        'referral_credit_currency_invalid' => [
            'ru' => 'Бонус можно применить только в валюте обязательства.',
            'en' => 'Referral credit can only be applied in the obligation currency.',
        ],
        'referral_credit_retry' => [
            'ru' => 'Повторите операцию с новой страницы.',
            'en' => 'Refresh the page and try the operation again.',
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
            'ru' => 'Не удалось проверить заявку. Обновите страницу и отправьте её ещё раз.',
            'en' => 'We could not verify the request. Refresh the page and submit it again.',
        ],
        'payout_idempotency_conflict' => [
            'ru' => 'Этот ключ уже использован для другой суммы или валюты. Обновите страницу и отправьте запрос ещё раз.',
            'en' => 'This key was already used for a different amount or currency. Refresh the page and submit the request again.',
        ],
        'payout_form_retry' => [
            'ru' => 'Форма устарела. Обновите страницу и отправьте заявку ещё раз.',
            'en' => 'This form has expired. Refresh the page and submit the request again.',
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
            'ru' => 'Денежные выплаты доступны только партнёрам.',
            'en' => 'Cash payouts are available to partners only.',
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
        'tracker_task_status_invalid' => [
            'ru' => 'Не удалось определить отметку задачи. Выберите состояние и попробуйте снова.',
            'en' => 'We could not identify the task update. Choose a status and try again.',
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
            'patient_profile' => [
                'creation_key.required' => $this->message('family_form_retry'),
                'creation_key.uuid' => $this->message('family_form_retry'),
                'first_name.required' => $this->message('family_name_required'),
                'first_name.string' => $this->message('family_name_required'),
                'first_name.max' => $this->message('family_name_too_long'),
                'last_name.required' => $this->message('family_name_required'),
                'last_name.string' => $this->message('family_name_required'),
                'last_name.max' => $this->message('family_name_too_long'),
                'middle_name.string' => $this->message('family_name_required'),
                'middle_name.max' => $this->message('family_name_too_long'),
                'gender.required' => $this->message('family_gender_required'),
                'gender.in' => $this->message('family_gender_invalid'),
                'birth_date.required' => $this->message('family_birth_date_required'),
                'birth_date.date_format' => $this->message('family_birth_date_invalid'),
                'birth_date.before_or_equal' => $this->message('family_birth_date_future'),
                'relationship.required' => $this->message('family_relationship_required'),
                'relationship.in' => $this->message('family_relationship_invalid'),
                'return_to.in' => $this->message('request_invalid'),
                'service_id.integer' => $this->message('family_form_retry'),
                'service_id.min' => $this->message('family_form_retry'),
                'specialist_id.integer' => $this->message('family_form_retry'),
                'specialist_id.min' => $this->message('family_form_retry'),
                'format.in' => $this->message('family_form_retry'),
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
            'referral_credit' => [
                'amount.required' => $this->message('referral_credit_amount_invalid'),
                'amount.regex' => $this->message('referral_credit_amount_invalid'),
                'currency.required' => $this->message('referral_credit_currency_invalid'),
                'idempotency_key.required' => $this->message('referral_credit_retry'),
            ],
            'attribution' => [
                'source_detail.string' => $this->message('attribution_detail_invalid'),
                'source_detail.max' => $this->message('attribution_detail_too_long'),
            ],
            'tracker_check_in' => [
                'note.required' => $this->message('tracker_note_required'),
            ],
            'tracker_task' => [
                'status.required' => $this->message('tracker_task_status_invalid'),
                'status.in' => $this->message('tracker_task_status_invalid'),
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
                'partner' => $this->message('referral_partner_required'),
                'link' => $this->message('referral_link_not_found'),
                default => $this->message('request_invalid'),
            },
            'payout' => match ($field) {
                'idempotency_conflict' => $this->message('payout_idempotency_conflict'),
                'idempotency_key' => $this->message('payout_retry'),
                'partner' => $this->message('payout_partner_required'),
                'amount' => array_key_exists('currency', $exception->errors())
                    ? $this->message('payout_amount_invalid')
                    : $this->message('payout_amount_unavailable'),
                'currency' => $this->message('payout_currency_invalid'),
                default => $this->message('request_invalid'),
            },
            'referral_credit' => match ($field) {
                'idempotency_key' => $this->message('referral_credit_retry'),
                'currency' => $this->message('referral_credit_currency_invalid'),
                'amount' => $this->message('referral_credit_unavailable'),
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
