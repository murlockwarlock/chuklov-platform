<?php

namespace App\Modules\Scheduling\Application;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\ValueObjects\IanaTimezone;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Specialists\Domain\Models\Specialist;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class ResolveSpecialistViewerTimezone
{
    public function __construct(private OrganizationContext $context) {}

    public function forSpecialist(Specialist $specialist): string
    {
        if ((int) $specialist->organization_id !== $this->context->id()) {
            throw new AuthorizationException('The specialist is outside the current organization.');
        }

        return $this->normalize($specialist->viewer_timezone) ?? $this->context->defaultTimezone();
    }

    public function forUser(User $user): string
    {
        $specialist = Specialist::query()
            ->where('organization_id', $this->context->id())
            ->where('staff_user_id', $user->getKey())
            ->first();

        return $specialist instanceof Specialist
            ? $this->forSpecialist($specialist)
            : $this->context->defaultTimezone();
    }

    public function forBooking(Booking $booking): string
    {
        $specialist = $booking->specialist;
        if ((int) $specialist->organization_id !== (int) $booking->organization_id) {
            return $booking->schedule_timezone;
        }

        return $this->normalize($specialist->viewer_timezone)
            ?? $specialist->organization->defaultTimezone();
    }

    private function normalize(?string $timezone): ?string
    {
        if ($timezone === null || trim($timezone) === '') {
            return null;
        }

        return IanaTimezone::from($timezone)->value;
    }
}
