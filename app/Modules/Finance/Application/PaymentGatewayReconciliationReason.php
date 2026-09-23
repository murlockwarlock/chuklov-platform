<?php

namespace App\Modules\Finance\Application;

use App\Modules\Finance\Domain\Enums\PaymentGatewayEventType;

final class PaymentGatewayReconciliationReason
{
    public static function label(?string $reason, ?PaymentGatewayEventType $eventType = null): string
    {
        if ($reason === 'manual_reconciliation_required') {
            return match ($eventType) {
                PaymentGatewayEventType::Refund => 'Получено событие возврата, которое требует ручной сверки.',
                PaymentGatewayEventType::Chargeback => 'Получено оспаривание платежа, которое требует ручной сверки.',
                PaymentGatewayEventType::Unknown => 'Получено неизвестное событие провайдера, которое требует ручной сверки.',
                default => 'Платёж требует ручной сверки.',
            };
        }

        if ($reason === 'refund_manual_reconciliation_required') {
            return 'Получено событие возврата, которое требует ручной сверки.';
        }

        if ($reason === 'chargeback_manual_reconciliation_required') {
            return 'Получено оспаривание платежа, которое требует ручной сверки.';
        }

        return match ($reason) {
            'amount_or_currency_mismatch' => 'Сумма или валюта платежа не совпала с ожидаемой.',
            'pending_link_stale' => 'Платёж от провайдера получен, но операция в CRM не была найдена вовремя.',
            'provider_event_key_payload_conflict' => 'Повторное событие провайдера пришло с другими данными.',
            'provider_reference_mismatch' => 'Не удалось надёжно сопоставить платёж с операцией.',
            'amount_exceeds_outstanding' => 'Сумма платежа превышает остаток задолженности.',
            'invalid_failure_transition' => 'Провайдер сообщил об ошибке для уже завершённого платежа.',
            'invalid_settlement_transition' => 'Провайдер сообщил об оплате, но текущий статус операции не допускает автоматическое зачисление.',
            'settled_transaction_without_ledger' => 'Оплата отмечена завершённой, но финансовая проводка отсутствует.',
            'ledger_idempotency_conflict' => 'Обнаружен конфликт финансовой проводки для этого платежа.',
            'missing_amount_or_currency' => 'Платёж не содержит полной суммы или валюты.',
            'missing_offer_mapping' => 'Для товара или услуги не настроено предложение Lava.',
            'ambiguous_offer_mapping' => 'Для товара или услуги найдено несколько активных предложений Lava.',
            'invalid_payment_request' => 'Параметры онлайн-оплаты настроены некорректно.',
            'missing_credential' => 'API-ключ Lava не настроен.',
            'invalid_credential' => 'Lava отклонила сохранённый API-ключ.',
            'invalid_configuration' => 'Настройки подключения Lava требуют проверки.',
            'invalid_offer' => 'Offer ID Lava отклонён провайдером.',
            'invalid_provider_response' => 'Lava вернула некорректный ответ при создании оплаты.',
            'provider_rejected_request' => 'Провайдер отклонил создание оплаты.',
            'payment_initiation_stale' => 'Создание оплаты не завершилось вовремя. Проверьте платёж вручную.',
            'provider_timeout' => 'Провайдер не ответил вовремя, операция требует проверки.',
            'provider_unavailable' => 'Провайдер временно недоступен, настройка оплаты требует проверки.',
            'unsupported_automatic_event', 'unknown_provider_event' => 'Событие провайдера требует ручной сверки.',
            default => 'Платёж требует ручной сверки.',
        };
    }
}
