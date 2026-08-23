<?php

namespace App\Modules\ClientCompanion\Domain\Enums;

enum CompanionFailureCode: string
{
    case NotConfigured = 'not_configured';
    case BudgetUnavailable = 'budget_unavailable';
    case ProviderUnavailable = 'provider_unavailable';
    case InvalidOutput = 'invalid_output';
    case RetrievalFailure = 'retrieval_failure';
    case QueueFailure = 'queue_failure';
    case DeliveryFailure = 'delivery_failure';
    case RateLimited = 'rate_limited';
    case ImageUnavailable = 'image_unavailable';
    case InputLimitExceeded = 'input_limit_exceeded';
}
