<?php

namespace App\Modules\Scenarios\Application;

use App\Modules\MedicalProfiles\Application\MedicalProfileCompletion;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Services\Domain\Models\Service;
use Carbon\CarbonImmutable;

final readonly class BookingNeedsMedicalProfile
{
    public function __construct(private MedicalProfileCompletion $completion) {}

    public function needsCompletion(Booking $booking): bool
    {
        return in_array($booking->status, [BookingStatus::Requested, BookingStatus::PendingReview, BookingStatus::Confirmed], true)
            && $booking->startsAtUtc()->greaterThan(CarbonImmutable::now('UTC'))
            && OrganizationFeatureFlag::query()->where('organization_id', $booking->organization_id)
                ->where('feature_key', OrganizationFeature::ClientRecords->value)->where('enabled', true)->exists()
            && Service::query()
                ->where('organization_id', $booking->organization_id)
                ->whereKey($booking->service_id)
                ->where('requires_medical_profile', true)
                ->exists()
            && ! $this->completion->isComplete((int) $booking->organization_id, (int) $booking->client_id);
    }
}
