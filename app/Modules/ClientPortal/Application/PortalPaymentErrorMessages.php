<?php

namespace App\Modules\ClientPortal\Application;

use App\Modules\Finance\Domain\Exceptions\PaymentGatewayInitiationFailure;
use Illuminate\Validation\ValidationException;

final class PortalPaymentErrorMessages
{
    /** @var array<string, array{ru: string, en: string}> */
    private const Messages = [
        'payment_unavailable' => [
            'ru' => 'Онлайн-оплата сейчас временно недоступна. Попробуйте позже.',
            'en' => 'Online payment is temporarily unavailable. Please try again later.',
        ],
        'payment_ambiguous' => [
            'ru' => 'Не удалось открыть страницу оплаты. Если деньги уже списались, не оплачивайте повторно — мы проверим платёж.',
            'en' => "We couldn't open the payment page. If the money has already been charged, don't pay again — we'll check the payment.",
        ],
        'payment_checking' => [
            'ru' => 'Платёж уже проверяется. Обновите страницу немного позже.',
            'en' => 'The payment is already being checked. Refresh the page in a moment.',
        ],
        'payment_conflict' => [
            'ru' => 'Эта операция оплаты уже используется. Начните новую попытку оплаты.',
            'en' => 'This payment request is already in use. Start a new payment attempt.',
        ],
        'payment_not_available' => [
            'ru' => 'Эту задолженность сейчас нельзя оплатить онлайн. Обновите страницу или обратитесь в клинику.',
            'en' => 'This charge cannot be paid online right now. Refresh the page or contact the clinic.',
        ],
    ];

    public function gateway(PaymentGatewayInitiationFailure $failure): string
    {
        return $this->message($failure->isAmbiguous() ? 'payment_ambiguous' : 'payment_unavailable');
    }

    public function validation(ValidationException $exception): string
    {
        $keys = array_keys($exception->errors());

        if (in_array('idempotency_key', $keys, true)) {
            return $this->message('payment_conflict');
        }

        if (in_array('obligation', $keys, true)) {
            return $this->message('payment_not_available');
        }

        return $this->message('payment_unavailable');
    }

    public function message(string $key): string
    {
        $messages = self::Messages[$key] ?? self::Messages['payment_unavailable'];
        $locale = app()->getLocale() === 'en' ? 'en' : 'ru';

        return $messages[$locale];
    }
}
