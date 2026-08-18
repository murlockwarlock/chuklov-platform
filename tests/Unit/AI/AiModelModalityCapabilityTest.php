<?php

namespace Tests\Unit\AI;

use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiModelModality;
use App\Modules\AI\Domain\Models\AiModelRelease;
use App\Modules\AI\Infrastructure\Providers\AiProviderFactory;
use PHPUnit\Framework\TestCase;

final class AiModelModalityCapabilityTest extends TestCase
{
    public function test_explicit_image_capability_allows_an_arbitrary_deployment_name(): void
    {
        $release = $this->release(
            modelName: 'medical-prod',
            capabilities: [AiCapability::PostureAnalysis->value, AiModelModality::ImageInput->value],
        );

        self::assertTrue(AiProviderFactory::supportsAttachments(
            providerName: 'azure',
            release: $release,
            requiredModalities: [AiModelModality::ImageInput],
        ));
    }

    public function test_model_name_does_not_grant_image_or_document_capability(): void
    {
        $release = $this->release(
            modelName: 'gpt-5-vision',
            capabilities: [AiCapability::PostureAnalysis->value],
        );

        self::assertFalse(AiProviderFactory::supportsAttachments(
            providerName: 'openai',
            release: $release,
            requiredModalities: [AiModelModality::ImageInput],
        ));
        self::assertFalse(AiProviderFactory::supportsAttachments(
            providerName: 'openai',
            release: $release,
            requiredModalities: [AiModelModality::DocumentInput],
        ));
    }

    public function test_document_execution_requires_explicit_document_capability(): void
    {
        $release = $this->release(
            modelName: 'document-deployment',
            capabilities: [AiCapability::ClinicalDocumentExtraction->value, AiModelModality::ImageInput->value],
        );

        self::assertFalse(AiProviderFactory::supportsAttachments(
            providerName: 'openai',
            release: $release,
            requiredModalities: [AiModelModality::DocumentInput],
        ));
    }

    public function test_provider_adapter_modality_support_is_fail_closed(): void
    {
        $release = $this->release(
            modelName: 'vision-deployment',
            capabilities: [AiModelModality::ImageInput->value, AiModelModality::DocumentInput->value],
        );

        self::assertTrue(AiProviderFactory::supportsAttachments(
            providerName: 'openai_compatible',
            release: $release,
            requiredModalities: [AiModelModality::ImageInput],
        ));
        self::assertFalse(AiProviderFactory::supportsAttachments(
            providerName: 'openai_compatible',
            release: $release,
            requiredModalities: [AiModelModality::DocumentInput],
        ));
    }

    public function test_text_only_candidate_behavior_remains_unchanged(): void
    {
        $release = $this->release(
            modelName: 'text-only-deployment',
            capabilities: [AiCapability::GeneralAssistant->value],
        );

        self::assertTrue(AiProviderFactory::supportsAttachments(
            providerName: 'unknown-provider',
            release: $release,
            requiredModalities: [],
        ));
    }

    /** @param list<string> $capabilities */
    private function release(string $modelName, array $capabilities): AiModelRelease
    {
        return new AiModelRelease([
            'model_name' => $modelName,
            'capabilities' => $capabilities,
        ]);
    }
}
