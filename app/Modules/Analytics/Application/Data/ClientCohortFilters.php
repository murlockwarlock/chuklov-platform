<?php

namespace App\Modules\Analytics\Application\Data;

use App\Modules\Analytics\Domain\Enums\ClientSegment;
use App\Modules\Analytics\Domain\Enums\PainScoreBasis;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class ClientCohortFilters
{
    /** @var list<string> */
    public array $segments;

    public PainScoreBasis $painScoreBasis;

    /** @param list<int> $clientIds
     * @param  list<int>  $excludeClientIds
     * @param  list<int>  $savedGroupIds
     * @param  list<string>  $segments
     * @param  list<int>  $problemTagIds
     * @param  list<int>  $excludeProblemTagIds
     * @param  list<int>  $feedbackThemeIds
     * @param  list<int>  $goalTagIds
     * @param  list<string>  $genders
     * @param  list<string>  $countries
     * @param  list<string>  $sourceKeys
     * @param  list<int>  $serviceIds
     * @param  list<string>  $serviceCategories
     * @param  list<int>  $specialistIds
     * @param  list<string>  $bookingStatuses
     * @param  list<string>  $bookingEventTypes
     * @param  list<string>  $purchaseTypes
     * @param  list<string>  $purchaseStatuses
     */
    public function __construct(
        public array $clientIds = [],
        public array $excludeClientIds = [],
        public array $savedGroupIds = [],
        array $segments = [],
        public ?bool $archived = null,
        public ?bool $blacklist = null,
        public array $problemTagIds = [],
        public string $problemTagMode = 'any',
        public array $excludeProblemTagIds = [],
        public array $feedbackThemeIds = [],
        public array $goalTagIds = [],
        public ?int $ageMin = null,
        public ?int $ageMax = null,
        public array $genders = [],
        public ?bool $children = null,
        public array $countries = [],
        public array $sourceKeys = [],
        public array $serviceIds = [],
        public array $serviceCategories = [],
        public array $specialistIds = [],
        public array $bookingStatuses = [],
        public array $bookingEventTypes = [],
        public ?int $completedVisitsMin = null,
        public ?int $completedVisitsMax = null,
        public ?string $lastVisitFrom = null,
        public ?string $lastVisitTo = null,
        public array $purchaseTypes = [],
        public array $purchaseStatuses = [],
        public ?bool $hasPainScore = null,
        public ?int $painScoreMin = null,
        public ?int $painScoreMax = null,
        public ?int $satisfactionMin = null,
        public ?int $satisfactionMax = null,
        mixed $painScoreBasis = PainScoreBasis::Any,
    ) {
        $this->segments = self::normalizeSegments($segments);
        $this->painScoreBasis = PainScoreBasis::fromInput($painScoreBasis);
        if (! in_array($problemTagMode, ['any', 'all'], true)) {
            throw new InvalidArgumentException('The problem tag mode is invalid.');
        }
        if ($children !== null && ($ageMin !== null || $ageMax !== null)) {
            throw new InvalidArgumentException('Выберите либо возрастную группу, либо точный возрастной диапазон.');
        }
        self::assertRange($ageMin, $ageMax, 0, 150, 'Возрастной диапазон');
        self::assertRange($completedVisitsMin, $completedVisitsMax, 0, null, 'Диапазон завершённых визитов');
        self::assertRange($painScoreMin, $painScoreMax, 0, 10, 'Диапазон оценки боли');
        self::assertRange($satisfactionMin, $satisfactionMax, 0, 10, 'Диапазон удовлетворённости');
        self::assertDate($lastVisitFrom, 'Дата последнего визита от');
        self::assertDate($lastVisitTo, 'Дата последнего визита до');
        if ($lastVisitFrom !== null && $lastVisitTo !== null && $lastVisitFrom > $lastVisitTo) {
            throw new InvalidArgumentException('Дата последнего визита до не может быть раньше даты начала.');
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $list = static function (mixed $value): array {
            if (! is_array($value)) {
                $value = $value === null || $value === '' ? [] : [$value];
            }

            return array_values(array_filter($value, static fn (mixed $item): bool => $item !== null && $item !== ''));
        };
        $integerList = static fn (mixed $value): array => array_values(array_unique(array_filter(
            array_map(static fn (mixed $item): int => (int) $item, $list($value)),
            static fn (int $item): bool => $item > 0,
        )));
        $stringList = static fn (mixed $value): array => array_values(array_unique(array_filter(
            array_map(static fn (mixed $item): string => trim((string) $item), $list($value)),
            static fn (string $item): bool => $item !== '',
        )));

        return new self(
            clientIds: $integerList($data['client_ids'] ?? $data['client_id'] ?? []),
            excludeClientIds: $integerList($data['exclude_client_ids'] ?? []),
            savedGroupIds: $integerList($data['saved_group_ids'] ?? []),
            segments: $stringList($data['segments'] ?? $data['segment'] ?? []),
            archived: self::nullableBool($data['archived'] ?? null),
            blacklist: self::nullableBool($data['blacklist'] ?? null),
            problemTagIds: $integerList($data['problem_tag_ids'] ?? $data['problem_tag_id'] ?? []),
            problemTagMode: (string) ($data['problem_tag_mode'] ?? 'any'),
            excludeProblemTagIds: $integerList($data['exclude_problem_tag_ids'] ?? []),
            feedbackThemeIds: $integerList($data['feedback_theme_ids'] ?? $data['feedback_theme_id'] ?? []),
            goalTagIds: $integerList($data['goal_tag_ids'] ?? $data['goal_tag_id'] ?? []),
            ageMin: self::nullableInt($data['age_min'] ?? null),
            ageMax: self::nullableInt($data['age_max'] ?? null),
            genders: $stringList($data['genders'] ?? $data['gender'] ?? []),
            children: self::nullableBool($data['children'] ?? null),
            countries: $stringList($data['countries'] ?? $data['country'] ?? []),
            sourceKeys: $stringList($data['source_keys'] ?? $data['source_key'] ?? []),
            serviceIds: $integerList($data['service_ids'] ?? $data['service_id'] ?? []),
            serviceCategories: $stringList($data['service_categories'] ?? $data['service_category'] ?? []),
            specialistIds: $integerList($data['specialist_ids'] ?? $data['specialist_id'] ?? []),
            bookingStatuses: $stringList($data['booking_statuses'] ?? $data['booking_status'] ?? []),
            bookingEventTypes: $stringList($data['booking_event_types'] ?? $data['booking_event_type'] ?? []),
            completedVisitsMin: self::nullableInt($data['completed_visits_min'] ?? null),
            completedVisitsMax: self::nullableInt($data['completed_visits_max'] ?? null),
            lastVisitFrom: self::nullableString($data['last_visit_from'] ?? null),
            lastVisitTo: self::nullableString($data['last_visit_to'] ?? null),
            purchaseTypes: $stringList($data['purchase_types'] ?? $data['purchase_type'] ?? []),
            purchaseStatuses: $stringList($data['purchase_statuses'] ?? $data['purchase_status'] ?? []),
            hasPainScore: self::nullableBool($data['has_pain_score'] ?? null),
            painScoreMin: self::nullableInt($data['pain_score_min'] ?? null),
            painScoreMax: self::nullableInt($data['pain_score_max'] ?? null),
            satisfactionMin: self::nullableInt($data['satisfaction_min'] ?? null),
            satisfactionMax: self::nullableInt($data['satisfaction_max'] ?? null),
            painScoreBasis: $data['pain_score_basis'] ?? PainScoreBasis::Any->value,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'client_ids' => $this->clientIds,
            'exclude_client_ids' => $this->excludeClientIds,
            'saved_group_ids' => $this->savedGroupIds,
            'segments' => $this->segments,
            'archived' => $this->archived,
            'blacklist' => $this->blacklist,
            'problem_tag_ids' => $this->problemTagIds,
            'problem_tag_mode' => $this->problemTagMode,
            'exclude_problem_tag_ids' => $this->excludeProblemTagIds,
            'feedback_theme_ids' => $this->feedbackThemeIds,
            'goal_tag_ids' => $this->goalTagIds,
            'age_min' => $this->ageMin,
            'age_max' => $this->ageMax,
            'genders' => $this->genders,
            'children' => $this->children,
            'countries' => $this->countries,
            'source_keys' => $this->sourceKeys,
            'service_ids' => $this->serviceIds,
            'service_categories' => $this->serviceCategories,
            'specialist_ids' => $this->specialistIds,
            'booking_statuses' => $this->bookingStatuses,
            'booking_event_types' => $this->bookingEventTypes,
            'completed_visits_min' => $this->completedVisitsMin,
            'completed_visits_max' => $this->completedVisitsMax,
            'last_visit_from' => $this->lastVisitFrom,
            'last_visit_to' => $this->lastVisitTo,
            'purchase_types' => $this->purchaseTypes,
            'purchase_statuses' => $this->purchaseStatuses,
            'has_pain_score' => $this->hasPainScore,
            'pain_score_min' => $this->painScoreMin,
            'pain_score_max' => $this->painScoreMax,
            'pain_score_basis' => $this->painScoreBasis->value,
            'satisfaction_min' => $this->satisfactionMin,
            'satisfaction_max' => $this->satisfactionMax,
        ];
    }

    public function isUnrestricted(): bool
    {
        foreach ($this->toArray() as $key => $value) {
            if ($key === 'pain_score_basis' && $value === PainScoreBasis::Any->value) {
                continue;
            }
            if ($key === 'problem_tag_mode' && $this->problemTagIds === [] && $this->excludeProblemTagIds === []) {
                continue;
            }
            if ($value === null || $value === [] || $value === '') {
                continue;
            }

            return false;
        }

        return true;
    }

    /** @param list<string> $segments
     * @return list<string>
     */
    private static function normalizeSegments(array $segments): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $segment): string => trim((string) $segment), $segments),
            static fn (string $segment): bool => $segment !== '' && $segment !== ClientSegment::All->value,
        )));
    }

    private static function nullableInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $integer = (int) $value;

        return (float) $integer === (float) $value ? $integer : null;
    }

    private static function nullableBool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === '1') {
            return true;
        }
        if ($value === 0 || $value === '0') {
            return false;
        }

        return null;
    }

    private static function nullableString(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }

    private static function assertRange(?int $minimum, ?int $maximum, int $floor, ?int $ceiling, string $label): void
    {
        foreach ([$minimum, $maximum] as $value) {
            if ($value === null || $value >= $floor && ($ceiling === null || $value <= $ceiling)) {
                continue;
            }

            throw new InvalidArgumentException($label.' содержит недопустимое значение.');
        }
        if ($minimum !== null && $maximum !== null && $minimum > $maximum) {
            throw new InvalidArgumentException($label.' задан в неверном порядке.');
        }
    }

    private static function assertDate(?string $value, string $label): void
    {
        if ($value === null) {
            return;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            throw new InvalidArgumentException($label.' должна быть указана в формате ГГГГ-ММ-ДД.');
        }
        if (! $date instanceof CarbonImmutable || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException($label.' должна быть указана в формате ГГГГ-ММ-ДД.');
        }
    }
}
