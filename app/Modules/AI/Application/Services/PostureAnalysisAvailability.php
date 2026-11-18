<?php

namespace App\Modules\AI\Application\Services;

use App\Modules\AI\Application\Actions\ResolveAiExecutionCandidates;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiModelModality;
use App\Modules\AI\Domain\Models\AiOrganizationSafetyControl;
use App\Modules\AI\Domain\Models\AiPrompt;
use App\Modules\AI\Domain\Enums\PromptVersionStatus;
use App\Modules\Organizations\Application\OrganizationContext;
use Illuminate\Validation\ValidationException;

final readonly class PostureAnalysisAvailability
{
    public function __construct(
        private OrganizationContext $context,
        private ResolveAiExecutionCandidates $candidates,
    ) {}

    /** @return array{status: string, issues: list<string>} */
    public function diagnose(): array
    {
        $organizationId = (int) $this->context->id();
        $safetyControls = AiOrganizationSafetyControl::query()
            ->where('organization_id', $organizationId)
            ->first();

        if ($safetyControls !== null && ! $safetyControls->is_ai_globally_enabled) {
            return [
                'status' => 'disabled',
                'issues' => ['AI отключён политикой безопасности организации.'],
            ];
        }

        if ($safetyControls !== null && ! $safetyControls->isCapabilityEnabled(AiCapability::PostureAnalysis->value)) {
            return [
                'status' => 'disabled',
                'issues' => ['Анализ осанки отключён в настройках AI организации.'],
            ];
        }

        $prompt = AiPrompt::query()
            ->where('organization_id', $organizationId)
            ->where('capability', AiCapability::PostureAnalysis)
            ->with('activeVersion')
            ->latest('id')
            ->first();
        $activeVersion = $prompt?->activeVersion;
        if ($prompt === null
            || $activeVersion === null
            || (int) $activeVersion->organization_id !== $organizationId
            || (int) $activeVersion->prompt_id !== (int) $prompt->getKey()
            || $activeVersion->status !== PromptVersionStatus::Active) {
            return [
                'status' => 'not_configured',
                'issues' => ['Для анализа осанки нет утверждённой активной версии промпта.'],
            ];
        }

        $diagnosis = $this->candidates->diagnose(
            organizationId: $organizationId,
            capability: AiCapability::PostureAnalysis,
            safetyControls: $safetyControls,
            requiredModalities: [AiModelModality::ImageInput],
        );

        if ($diagnosis['status'] === 'ready') {
            return $diagnosis;
        }

        return [
            'status' => $diagnosis['status'],
            'issues' => [$this->messageForStatus($diagnosis['status'])],
        ];
    }

    public function assertReady(): void
    {
        $diagnosis = $this->diagnose();
        if ($diagnosis['status'] === 'ready') {
            return;
        }

        throw ValidationException::withMessages([
            'ai' => $diagnosis['issues'][0] ?? 'Анализ осанки сейчас недоступен.',
        ]);
    }

    public function isReady(): bool
    {
        return $this->diagnose()['status'] === 'ready';
    }

    public function message(): ?string
    {
        $diagnosis = $this->diagnose();

        return $diagnosis['status'] === 'ready' ? null : ($diagnosis['issues'][0] ?? null);
    }

    private function messageForStatus(string $status): string
    {
        return match ($status) {
            'provider_unavailable' => 'Разрешённый AI-провайдер временно недоступен. Повторите попытку позже.',
            'disabled' => 'Анализ осанки отключён в настройках AI организации.',
            default => 'Нужна активная модель с поддержкой изображений и разрешённой политикой провайдера.',
        };
    }
}
