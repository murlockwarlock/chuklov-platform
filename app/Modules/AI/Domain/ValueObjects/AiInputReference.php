<?php

namespace App\Modules\AI\Domain\ValueObjects;

use InvalidArgumentException;

final readonly class AiInputReference
{
    public const ALLOWED_TYPES = [
        'client',
        'medical_session',
        'medical_attachment',
        'survey_attempt',
        'booking',
        'knowledge_source',
    ];

    public function __construct(
        public string $type,
        public int $id,
        public ?string $description = null,
    ) {
        if (! in_array($this->type, self::ALLOWED_TYPES, true)) {
            throw new InvalidArgumentException("Invalid input reference type: {$this->type}");
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            type: (string) ($data['type'] ?? ''),
            id: (int) ($data['id'] ?? 0),
            description: isset($data['description']) ? (string) $data['description'] : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'id' => $this->id,
            'description' => $this->description,
        ];
    }
}
