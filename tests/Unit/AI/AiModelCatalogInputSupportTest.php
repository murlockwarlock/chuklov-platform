<?php

namespace Tests\Unit\AI;

use App\Modules\AI\Domain\Enums\AiModelModality;
use App\Modules\AI\Domain\Enums\ModelLifecycleStatus;
use App\Modules\AI\Domain\Registry\AiModelCatalog;
use App\Modules\AI\Domain\Registry\AiModelDefinition;
use PHPUnit\Framework\TestCase;

final class AiModelCatalogInputSupportTest extends TestCase
{
    public function test_model_summary_explicitly_distinguishes_text_image_and_document_support(): void
    {
        $textOnly = $this->definition('deepseek', []);
        $imageModel = $this->definition('deepseek', [AiModelModality::ImageInput->value]);
        $documentModel = $this->definition('openai', [
            AiModelModality::ImageInput->value,
            AiModelModality::DocumentInput->value,
        ]);

        self::assertSame(
            'Текст: есть · Изображения: нет · PDF/документы: нет',
            AiModelCatalog::humanInputSupportSummary($textOnly),
        );
        self::assertSame(
            'Текст: есть · Изображения: есть · PDF/документы: нет',
            AiModelCatalog::humanInputSupportSummary($imageModel),
        );
        self::assertSame(
            'Текст: есть · Изображения: есть · PDF/документы: есть',
            AiModelCatalog::humanInputSupportSummary($documentModel),
        );
        self::assertSame('Изображения и сканы (JPG, PNG, WebP)', AiModelModality::ImageInput->label());
        self::assertSame('PDF и документы', AiModelModality::DocumentInput->label());
    }

    private function definition(string $provider, array $modalities): AiModelDefinition
    {
        return new AiModelDefinition(
            provider: $provider,
            modelName: 'test-model',
            displayName: 'Test model',
            family: 'Test family',
            supportedCapabilities: ['text_generation'],
            modalities: array_map(
                static fn (string $modality): AiModelModality => AiModelModality::from($modality),
                $modalities,
            ),
            pricing: null,
            lifecycleStatus: ModelLifecycleStatus::Active,
        );
    }
}
