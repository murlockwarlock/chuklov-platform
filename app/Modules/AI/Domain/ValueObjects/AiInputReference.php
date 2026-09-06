<?php

namespace App\Modules\AI\Domain\ValueObjects;

use InvalidArgumentException;

final readonly class AiInputReference
{
    public const ALLOWED_TYPES = [
        'client',
        'companion_attachment',
        'medical_session',
        'medical_attachment',
        'survey_attempt',
        'booking',
        'knowledge_source',
        'ai_run',
    ];

    public function __construct(
        public string $type,
        public int $id,
        public ?string $role = null,
    ) {
        if (! in_array($this->type, self::ALLOWED_TYPES, true)) {
            throw new InvalidArgumentException("Invalid input reference type: {$this->type}");
        }

        if ($this->id < 1) {
            throw new InvalidArgumentException('Input reference ID must be positive.');
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            type: (string) ($data['type'] ?? ''),
            id: (int) ($data['id'] ?? 0),
            role: isset($data['role']) ? (string) $data['role'] : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'type' => $this->type,
            'id' => $this->id,
        ];

        if ($this->role !== null) {
            $data['role'] = $this->role;
        }

        return $data;
    }
}
