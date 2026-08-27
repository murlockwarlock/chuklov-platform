<?php

namespace App\Modules\B2B\Application;

use App\Modules\Broadcasts\Application\GetClientB2bSpecialistAnswer;
use App\Modules\Broadcasts\Domain\Enums\B2bSpecialistAnswer;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Scheduling\Application\CalculateAvailability;
use App\Modules\Scheduling\Domain\Models\SpecialistWorkingHour;
use App\Modules\Specialists\Domain\Models\Specialist;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

final class ListB2bSalesCallAvailability
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly GetB2bSalesCallDuration $duration,
        private readonly GetB2bZoomHostCapability $zoomCapability,
        private readonly GetClientB2bSpecialistAnswer $specialistAnswer,
        private readonly CalculateAvailability $availability,
    ) {}

    /** @return array{specialists: list<array{id: int, displayName: string}>, selectedSpecialistId: int|null, availability: array<string, mixed>|null, configurationReady: bool, configurationIssue: string|null, specialistAnswer: string|null} */
    public function handle(
        Client $client,
        string $dateFrom,
        string $dateTo,
        ?int $specialistId = null,
        ?string $displayTimezone = null,
    ): array {
        $organization = $this->context->organization();

        if ((int) $client->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The client is outside the current organization.');
        }

        $specialists = $this->eligibleSpecialists();
        $selectedSpecialist = $specialistId === null
            ? $specialists->count() === 1 ? $specialists->first() : null
            : $specialists->firstWhere('id', $specialistId);

        if ($specialistId !== null && $selectedSpecialist === null) {
            throw ValidationException::withMessages([
                'specialist_id' => 'The selected specialist is not eligible for B2B sales calls.',
            ]);
        }

        $answer = $this->specialistAnswer->handle($client);
        $durationMinutes = $this->duration->handle();
        $configurationIssue = $durationMinutes === null
            ? 'missing_duration'
            : (! $this->zoomCapability->supportsAutomaticDuration($durationMinutes)
                ? 'zoom_duration_exceeds_capability'
                : null);
        $result = null;

        if ($selectedSpecialist instanceof Specialist
            && $answer === B2bSpecialistAnswer::Yes
            && $configurationIssue === null) {
            $result = $this->availability->forB2b(
                client: $client,
                specialist: $selectedSpecialist,
                dateFrom: $dateFrom,
                dateTo: $dateTo,
                durationMinutes: $durationMinutes,
                displayTimezone: $displayTimezone,
            )->toArray();
        }

        return [
            'specialists' => array_values($specialists
                ->map(static fn (Specialist $specialist): array => [
                    'id' => (int) $specialist->getKey(),
                    'displayName' => (string) $specialist->display_name,
                ])
                ->values()
                ->all()),
            'selectedSpecialistId' => $selectedSpecialist instanceof Specialist
                ? (int) $selectedSpecialist->getKey()
                : null,
            'availability' => $result,
            'configurationReady' => $configurationIssue === null,
            'configurationIssue' => $configurationIssue,
            'specialistAnswer' => $answer?->value,
        ];
    }

    /** @return Collection<int, Specialist> */
    private function eligibleSpecialists(): Collection
    {
        $organizationId = $this->context->id();
        $scheduledSpecialists = SpecialistWorkingHour::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->select('specialist_id')
            ->distinct();

        return Specialist::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->whereIn('id', $scheduledSpecialists)
            ->orderBy('display_name')
            ->limit((int) config('b2b.availability.max_specialists', 20))
            ->get(['id', 'organization_id', 'display_name', 'timezone', 'is_active']);
    }
}
