<?php

namespace App\Modules\Sessions\Application\DTOs;

final readonly class UpdateSessionCommand
{
    public function __construct(
        public ?string $pain = null,
        public ?string $tests = null,
        public ?string $observations = null,
        public ?string $rootCauseHypothesis = null,
        public ?string $protocol = null,
        public ?string $result = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            pain: isset($data['pain']) && is_string($data['pain']) ? trim($data['pain']) : null,
            tests: isset($data['tests']) && is_string($data['tests']) ? trim($data['tests']) : null,
            observations: isset($data['observations']) && is_string($data['observations']) ? trim($data['observations']) : null,
            rootCauseHypothesis: isset($data['root_cause_hypothesis']) && is_string($data['root_cause_hypothesis']) ? trim($data['root_cause_hypothesis']) : null,
            protocol: isset($data['protocol']) && is_string($data['protocol']) ? trim($data['protocol']) : null,
            result: isset($data['result']) && is_string($data['result']) ? trim($data['result']) : null,
        );
    }
}
