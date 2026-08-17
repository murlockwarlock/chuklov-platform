<?php

namespace App\Modules\AI\Application\Data;

use App\Models\User;
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
        public ?User $actor = null,
    ) {}

    public function withActor(?User $actor): self
    {
        return new self(
            capability: $this->capability,
            workflowKey: $this->workflowKey,
            origin: $this->origin,
            executionMode: $this->executionMode,
            initiatedByUserId: $this->initiatedByUserId,
            clientId: $this->clientId,
            promptVersionId: $this->promptVersionId,
            modelReleaseId: $this->modelReleaseId,
            inputVariables: $this->inputVariables,
            inputReferences: $this->inputReferences,
            idempotencyKey: $this->idempotencyKey,
            timeoutSeconds: $this->timeoutSeconds,
            actor: $actor,
        );
    }
}
