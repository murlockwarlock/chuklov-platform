<?php

namespace App\Modules\AI\Application\Data;

use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiExecutionMode;
use App\Modules\AI\Domain\Enums\AiRunOrigin;
use App\Modules\AI\Domain\ValueObjects\AiInputReference;

final readonly class AiRunRequest
{
    /**
     * @param  array<string, mixed>  $inputVariables
     * @param  list<AiInputReference>  $inputReferences
     */
    public function __construct(
        public AiCapability $capability,
        public string $workflowKey,
        public AiRunOrigin $origin = AiRunOrigin::User,
        public AiExecutionMode $executionMode = AiExecutionMode::Sync,
        public ?int $initiatedByUserId = null,
        public ?int $clientId = null,
        public ?int $promptVersionId = null,
        public ?int $modelReleaseId = null,
        public array $inputVariables = [],
        public array $inputReferences = [],
        public ?string $idempotencyKey = null,
        public ?int $timeoutSeconds = null,
    ) {}
}
