<?php

namespace App\Modules\AI\Domain\ValueObjects;

final readonly class AiVariableDefinition
{
    public function __construct(
        public string $key,
        public string $name,
        public string $type = 'string',
        public ?string $description = null,
        public bool $isRequired = true,
        public mixed $defaultValue = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            key: (string) ($data['key'] ?? ''),
            name: (string) ($data['name'] ?? $data['key'] ?? ''),
            type: (string) ($data['type'] ?? 'string'),
            description: isset($data['description']) ? (string) $data['description'] : null,
            isRequired: (bool) ($data['is_required'] ?? true),
            defaultValue: $data['default_value'] ?? null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'type' => $this->type,
            'description' => $this->description,
            'is_required' => $this->isRequired,
            'default_value' => $this->defaultValue,
        ];
    }
}
