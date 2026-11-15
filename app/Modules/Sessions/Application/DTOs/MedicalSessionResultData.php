<?php

namespace App\Modules\Sessions\Application\DTOs;

final readonly class MedicalSessionResultData
{
    /** @var list<string> */
    public const SOURCE_HINTS = [
        'Боль',
        'Подвижность',
        'Визуальный баланс',
        'Дискомфорт, энергия, сон, аппетит, переживания',
        'Результаты тестов',
    ];

    /** @param list<string> $sourceHints */
    public function __construct(
        public string $summary,
        public MedicalSessionEffectivenessData $effectiveness,
        public array $sourceHints = self::SOURCE_HINTS,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'summary' => $this->summary,
            'effectiveness' => $this->effectiveness->toArray(),
            'source_hints' => $this->sourceHints,
        ];
    }
}
