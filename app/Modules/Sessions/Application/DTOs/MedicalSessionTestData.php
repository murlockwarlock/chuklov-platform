<?php

namespace App\Modules\Sessions\Application\DTOs;

final readonly class MedicalSessionTestData
{
    public function __construct(
        public int $testId,
        public string $testName,
        public ?int $beforeValueId,
        public ?string $beforeLabel,
        public bool $beforeAbnormal,
        public ?int $afterValueId,
        public ?string $afterLabel,
        public bool $afterAbnormal,
        /** @var list<array{id:int,name:string}> */
        public array $causes = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'test_id' => $this->testId,
            'test_name' => $this->testName,
            'before_value_id' => $this->beforeValueId,
            'before_label' => $this->beforeLabel,
            'before_abnormal' => $this->beforeAbnormal,
            'after_value_id' => $this->afterValueId,
            'after_label' => $this->afterLabel,
            'after_abnormal' => $this->afterAbnormal,
            'causes' => $this->causes,
        ];
    }
}
