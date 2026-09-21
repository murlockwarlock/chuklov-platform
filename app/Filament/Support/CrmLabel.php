<?php

namespace App\Filament\Support;

use App\Modules\B2B\Domain\Enums\B2bLeadStatus;
use App\Modules\B2B\Domain\Enums\B2bSalesCallStatus;
use App\Modules\B2B\Domain\Enums\VideoMeetingMode;
use App\Modules\B2B\Domain\Enums\VideoMeetingSyncStatus;
use App\Modules\Broadcasts\Domain\Enums\BroadcastCampaignState;
use App\Modules\Broadcasts\Domain\Enums\BroadcastRecipientState;
use App\Modules\Commerce\Domain\Enums\CommerceFulfillmentStatus;
use App\Modules\Commerce\Domain\Enums\PurchaseStatus;
use App\Modules\Finance\Domain\Enums\FinancialStatus;
use App\Modules\Referrals\Domain\Enums\ReferralPartnerStatus;
use App\Modules\Referrals\Domain\Enums\ReferralPayoutRequestStatus;
use App\Modules\Scenarios\Domain\Enums\ScenarioActionStatus;
use App\Modules\Scenarios\Domain\Enums\ScenarioDeliveryStatus;
use App\Modules\Scenarios\Domain\Enums\ScenarioRulePurpose;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Surveys\Domain\Enums\SurveyAttemptStatus;
use UnitEnum;

final class CrmLabel
{
    public static function enum(?UnitEnum $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof BookingStatus) {
            return match ($value) {
                BookingStatus::Requested => __('Ожидает подтверждения'),
                BookingStatus::PendingReview => __('На рассмотрении'),
                BookingStatus::Confirmed => __('Подтверждена'),
                BookingStatus::Rejected => __('Отклонена'),
                BookingStatus::Cancelled => __('Отменена'),
                BookingStatus::Completed => __('Завершена'),
                BookingStatus::NoShow => __('Не состоялась'),
            };
        }

        if ($value instanceof B2bLeadStatus) {
            return match ($value) {
                B2bLeadStatus::New => __('Новый'),
                B2bLeadStatus::Contacted => __('Связались'),
                B2bLeadStatus::ZoomScheduled => __('Разговор запланирован'),
                B2bLeadStatus::Closed => __('Закрыт'),
            };
        }

        if ($value instanceof B2bSalesCallStatus) {
            return match ($value) {
                B2bSalesCallStatus::Scheduled => __('Запланирован'),
                B2bSalesCallStatus::Cancelled => __('Отменён'),
            };
        }

        if ($value instanceof VideoMeetingMode) {
            return match ($value) {
                VideoMeetingMode::Automatic => __('Zoom автоматически'),
                VideoMeetingMode::Manual => __('Используется ручная ссылка'),
            };
        }

        if ($value instanceof VideoMeetingSyncStatus) {
            return match ($value) {
                VideoMeetingSyncStatus::NotRequired => __('Не требуется'),
                VideoMeetingSyncStatus::Pending => __('Ожидает'),
                VideoMeetingSyncStatus::Ready => __('Готово'),
                VideoMeetingSyncStatus::Failed => __('Ошибка'),
                VideoMeetingSyncStatus::CancellationPending => __('Отмена'),
                VideoMeetingSyncStatus::ReconciliationRequired => __('Требуется сверка'),
            };
        }

        if ($value instanceof VisitFormat) {
            return match ($value) {
                VisitFormat::Office => __('В клинике'),
                VisitFormat::HomeVisit => __('Выезд на дом'),
                VisitFormat::Online => __('Онлайн'),
            };
        }

        if ($value instanceof FinancialStatus) {
            return match ($value) {
                FinancialStatus::Outstanding => __('К оплате'),
                FinancialStatus::PartiallyPaid => __('Оплачено частично'),
                FinancialStatus::Settled => __('Оплачено'),
            };
        }

        if ($value instanceof CommerceFulfillmentStatus) {
            return match ($value) {
                CommerceFulfillmentStatus::Pending => __('Ожидает выдачи'),
                CommerceFulfillmentStatus::Processing => __('Выдаётся'),
                CommerceFulfillmentStatus::Fulfilled => __('Выполнено'),
                CommerceFulfillmentStatus::Failed => __('Ошибка выдачи'),
            };
        }

        if ($value instanceof PurchaseStatus) {
            return match ($value) {
                PurchaseStatus::PendingPayment => __('Ожидает оплаты'),
                PurchaseStatus::Paid => __('Оплачена'),
                PurchaseStatus::Refunded => __('Возвращена'),
            };
        }

        if ($value instanceof SurveyAttemptStatus) {
            return match ($value) {
                SurveyAttemptStatus::InProgress => __('В процессе'),
                SurveyAttemptStatus::Completed => __('Завершён'),
            };
        }

        if ($value instanceof ReferralPartnerStatus) {
            return match ($value) {
                ReferralPartnerStatus::Active => __('Активен'),
                ReferralPartnerStatus::Inactive => __('Отключён'),
            };
        }

        if ($value instanceof ReferralPayoutRequestStatus) {
            return match ($value) {
                ReferralPayoutRequestStatus::Requested => __('Запрошена'),
                ReferralPayoutRequestStatus::Approved => __('Одобрена'),
                ReferralPayoutRequestStatus::Paid => __('Отмечена как выплаченная'),
                ReferralPayoutRequestStatus::Rejected => __('Отклонена'),
                ReferralPayoutRequestStatus::Cancelled => __('Отменена'),
            };
        }

        if ($value instanceof ScenarioRulePurpose) {
            return match ($value) {
                ScenarioRulePurpose::Service => __('Сервисное сообщение'),
                ScenarioRulePurpose::Transactional => __('Системное сообщение'),
                ScenarioRulePurpose::Marketing => __('Маркетинговое сообщение'),
            };
        }

        if ($value instanceof ScenarioActionStatus) {
            return match ($value) {
                ScenarioActionStatus::Scheduled => __('Запланировано'),
                ScenarioActionStatus::Processing => __('Отправляется'),
                ScenarioActionStatus::Delivered => __('Отправлено'),
                ScenarioActionStatus::Retryable => __('Повторим позже'),
                ScenarioActionStatus::Failed, ScenarioActionStatus::Suppressed => __('Не отправлено'),
                ScenarioActionStatus::Cancelled => __('Отменено'),
            };
        }

        if ($value instanceof ScenarioDeliveryStatus) {
            return match ($value) {
                ScenarioDeliveryStatus::Pending => __('Ожидает отправки'),
                ScenarioDeliveryStatus::Processing => __('Отправляется'),
                ScenarioDeliveryStatus::Delivered => __('Отправлено'),
                ScenarioDeliveryStatus::Retryable => __('Повторим позже'),
                ScenarioDeliveryStatus::PermanentFailure => __('Не отправлено'),
                ScenarioDeliveryStatus::Unavailable => __('Канал недоступен'),
                ScenarioDeliveryStatus::Suppressed => __('Получатель отключил сообщения'),
            };
        }

        if ($value instanceof BroadcastCampaignState) {
            return match ($value) {
                BroadcastCampaignState::Draft => __('Черновик'),
                BroadcastCampaignState::Scheduled => __('Запланирована'),
                BroadcastCampaignState::Dispatching => __('Отправляется'),
                BroadcastCampaignState::Completed => __('Завершена'),
                BroadcastCampaignState::Cancelled => __('Отменена'),
            };
        }

        if ($value instanceof BroadcastRecipientState) {
            return match ($value) {
                BroadcastRecipientState::Pending => __('Ожидает отправки'),
                BroadcastRecipientState::Suppressed => __('Исключён'),
                BroadcastRecipientState::Claimed => __('Отправляется'),
                BroadcastRecipientState::Delivered => __('Доставлено'),
                BroadcastRecipientState::Failed => __('Ошибка отправки'),
            };
        }

        $label = method_exists($value, 'label') ? $value->label() : $value->name;

        return __($label);
    }
}
