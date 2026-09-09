<?php

namespace App\Filament\Support;

use App\Modules\Knowledge\Domain\Enums\KnowledgeExtractionStatus;
use App\Modules\Knowledge\Domain\Enums\KnowledgeRevisionStatus;
use App\Modules\Knowledge\Domain\Enums\KnowledgeSourceStatus;
use App\Modules\Knowledge\Domain\Enums\KnowledgeSourceType;
use App\Modules\Knowledge\Domain\Models\KnowledgeRevision;
use App\Modules\Knowledge\Domain\Models\KnowledgeSource;
use App\Modules\Knowledge\Domain\ValueObjects\EmbeddingConfiguration;
use App\Modules\Knowledge\Domain\ValueObjects\EmbeddingPricingPolicy;
use InvalidArgumentException;

final class KnowledgeSourcePresentation
{
    public function sourceType(KnowledgeSourceType|string $type): string
    {
        $value = $type instanceof KnowledgeSourceType ? $type->value : $type;

        return $value === KnowledgeSourceType::AuthoredText->value ? 'Текст' : 'Документ';
    }

    public function searchAvailability(KnowledgeSource $source): string
    {
        if ($source->status === KnowledgeSourceStatus::Retired) {
            return 'Индексация недоступна';
        }

        $activeRevision = $source->activeRevision;
        if (! $activeRevision instanceof KnowledgeRevision || $activeRevision->status !== KnowledgeRevisionStatus::Ready) {
            return 'Ожидает индексации';
        }
        $extractionStatus = $activeRevision->getAttribute('extraction_status');
        if ($extractionStatus !== null && $extractionStatus !== KnowledgeExtractionStatus::Ready->value) {
            return 'Ошибка индексации';
        }
        if ($this->semanticSearchStatus() !== 'Готов') {
            return 'Индексация недоступна';
        }
        if (! $this->hasCompatibleReadyRun($source)) {
            return $this->hasCompatibleProcessingRun($source) ? 'Индексируется' : 'Ожидает индексации';
        }

        return 'Готов к поиску';
    }

    public function materialStatus(KnowledgeSource $source): string
    {
        return $source->status === KnowledgeSourceStatus::Active ? 'Используется' : 'Скрыт';
    }

    public function semanticSearchStatus(): string
    {
        try {
            $configuration = EmbeddingConfiguration::active();
            $pricing = EmbeddingPricingPolicy::active();
            if (! $pricing->zeroCostLocal && ! $this->providerCredentialIsConfigured($configuration->provider)) {
                return 'Не настроен';
            }
            $pricing->assertCompatible($configuration);
        } catch (InvalidArgumentException) {
            return 'Не настроен';
        } catch (\Throwable) {
            return 'Не настроен';
        }

        return 'Готов';
    }

    public function semanticSearchSummary(): string
    {
        try {
            $configuration = EmbeddingConfiguration::active();
        } catch (\Throwable) {
            return 'Семантический поиск: Не настроен. Провайдер: OpenAI · text-embedding-3-small. Модель индексации превращает материалы базы знаний в данные для смыслового поиска AI. Не настроена конфигурация индексации.';
        }

        $provider = $this->providerLabel($configuration->provider);
        $summary = 'Семантический поиск: '.$this->semanticSearchStatus().'. Провайдер: '.$provider.' · '.$configuration->model.'. Модель индексации превращает материалы базы знаний в данные для смыслового поиска AI.';
        $gap = $this->semanticSearchGap($configuration);

        return $gap === null ? $summary : $summary.' '.$gap;
    }

    public function semanticSearchGap(?EmbeddingConfiguration $configuration = null): ?string
    {
        $configuration ??= EmbeddingConfiguration::active();

        if (! $this->providerCredentialIsConfigured($configuration->provider)) {
            return 'Не настроена переменная окружения '.$this->credentialName($configuration->provider).'.';
        }

        try {
            EmbeddingPricingPolicy::active()->assertCompatible($configuration);
        } catch (InvalidArgumentException) {
            return 'Не настроена стоимость индексации в окружении staging.';
        } catch (\Throwable) {
            return 'Не настроена конфигурация индексации в окружении staging.';
        }

        return null;
    }

    public function latestProcessing(KnowledgeSource $source): string
    {
        if ($source->status === KnowledgeSourceStatus::Retired) {
            return 'Источник выключен';
        }

        $latestRevision = $source->latestRevision;
        if (! $latestRevision instanceof KnowledgeRevision) {
            return 'Материал не добавлен';
        }

        if ($latestRevision->extraction_status === KnowledgeExtractionStatus::TextNotFound->value) {
            return 'Текст не найден. Можно запустить AI-разбор.';
        }
        if ($latestRevision->extraction_status === KnowledgeExtractionStatus::Suspicious->value) {
            return 'Извлечение заблокировано: требуется проверка владельца';
        }
        if ($latestRevision->extraction_status === KnowledgeExtractionStatus::AiParseRequested->value) {
            return 'AI-разбор запрошен владельцем';
        }

        $hasActiveDifferentRevision = $source->active_revision_id !== null
            && (int) $source->active_revision_id !== (int) $latestRevision->getKey();

        if (! $hasActiveDifferentRevision
            && $latestRevision->status === KnowledgeRevisionStatus::Ready
            && ! $this->hasCompatibleReadyRun($source)) {
            if ($this->hasCompatibleProcessingRun($source)) {
                return 'Индексируется';
            }

            return $this->semanticSearchStatus() === 'Готов'
                ? 'Ожидает индексации'
                : 'Индексация недоступна';
        }

        return match ($latestRevision->status) {
            KnowledgeRevisionStatus::Pending => $hasActiveDifferentRevision ? 'Новая версия ожидает обработки' : 'Ожидает обработки',
            KnowledgeRevisionStatus::Processing => $hasActiveDifferentRevision ? 'Новая версия обрабатывается' : 'Обрабатывается',
            KnowledgeRevisionStatus::Failed => $hasActiveDifferentRevision ? 'Новая версия не обработана' : 'Требуется повторная обработка',
            KnowledgeRevisionStatus::Ready => $hasActiveDifferentRevision ? 'Готова новая версия' : 'Материал обработан',
            KnowledgeRevisionStatus::Stale => 'Предыдущая версия',
            KnowledgeRevisionStatus::Retired => 'Версия выключена',
        };
    }

    public function revisionStatus(KnowledgeRevisionStatus|string $status): string
    {
        $value = $status instanceof KnowledgeRevisionStatus ? $status->value : $status;

        return match ($value) {
            KnowledgeRevisionStatus::Pending->value => 'Ожидает обработки',
            KnowledgeRevisionStatus::Processing->value => 'Обрабатывается',
            KnowledgeRevisionStatus::Ready->value => 'Готова',
            KnowledgeRevisionStatus::Failed->value => 'Не обработана',
            KnowledgeRevisionStatus::Stale->value => 'Предыдущая версия',
            KnowledgeRevisionStatus::Retired->value => 'Скрыта',
            default => 'Состояние недоступно',
        };
    }

    public function errorMessage(?string $errorCode): string
    {
        if ($errorCode === null) {
            return 'Нет зарегистрированной ошибки';
        }

        return match ($errorCode) {
            'invalid_source_content' => 'Файл повреждён или изменён',
            'source_text_too_large' => 'Слишком большой объём текста',
            'empty_source_content' => 'В документе нет текста',
            'extraction_not_ready' => 'Извлечение не подтверждено владельцем',
            'parser_failed' => 'Не удалось безопасно извлечь данные',
            'text_not_found' => 'Текст не найден. Можно запустить AI-разбор.',
            'table_text_not_found' => 'Таблица не содержит данных',
            'embedding_or_persistence_failed' => 'Обработка не завершена',
            default => 'Обработка не завершена. Попробуйте повторить обработку.',
        };
    }

    public function materialName(KnowledgeRevision $revision): string
    {
        if ($revision->original_filename === null || trim($revision->original_filename) === '') {
            return 'Текст вручную';
        }

        $filename = basename(str_replace('\\', '/', $revision->original_filename));
        $filename = preg_replace('/[\x00-\x1F\x7F"<>:|?*]+/u', ' ', $filename) ?? '';
        $filename = trim(mb_substr($filename, 0, 120), " .\t\n\r\0\x0B");

        return $filename !== '' ? $filename : 'Файл';
    }

    public function canRetry(KnowledgeSource $source, KnowledgeRevision $revision): bool
    {
        return $source->status === KnowledgeSourceStatus::Active
            && (int) $source->latestRevision?->getKey() === (int) $revision->getKey()
            && ! in_array($revision->extraction_status, [
                KnowledgeExtractionStatus::TextNotFound->value,
                KnowledgeExtractionStatus::Suspicious->value,
                KnowledgeExtractionStatus::AiParseRequested->value,
            ], true)
            && $revision->status === KnowledgeRevisionStatus::Failed;
    }

    public function canStartPending(KnowledgeSource $source, KnowledgeRevision $revision): bool
    {
        $extractionStatus = $revision->getAttribute('extraction_status');

        return $source->status === KnowledgeSourceStatus::Active
            && (int) $source->latestRevision?->getKey() === (int) $revision->getKey()
            && ($extractionStatus === null || $extractionStatus === KnowledgeExtractionStatus::Ready->value)
            && $revision->status === KnowledgeRevisionStatus::Pending;
    }

    public function canDownload(KnowledgeSource $source, KnowledgeRevision $revision): bool
    {
        return $source->type === KnowledgeSourceType::UploadedText
            && filled($revision->getAttribute('storage_disk'))
            && filled($revision->getAttribute('storage_path'));
    }

    public function canReprocessForSearch(KnowledgeSource $source, KnowledgeRevision $revision): bool
    {
        return $source->status === KnowledgeSourceStatus::Active
            && (int) $source->active_revision_id === (int) $revision->getKey()
            && $revision->status === KnowledgeRevisionStatus::Ready
            && ! (bool) $revision->getAttribute('has_compatible_ready_run')
            && ! (bool) $revision->getAttribute('has_compatible_processing_run');
    }

    private function providerCredentialIsConfigured(string $provider): bool
    {
        if ($provider === 'ollama') {
            return true;
        }

        $key = config('ai.providers.'.$provider.'.key');

        return is_string($key) && trim($key) !== '';
    }

    private function providerLabel(string $provider): string
    {
        return match (strtolower($provider)) {
            'openai' => 'OpenAI',
            'deepseek' => 'DeepSeek',
            'anthropic' => 'Anthropic',
            'ollama' => 'Ollama',
            default => $provider,
        };
    }

    private function credentialName(string $provider): string
    {
        return match (strtolower($provider)) {
            'openai' => 'OPENAI_API_KEY',
            'deepseek' => 'DEEPSEEK_API_KEY',
            'anthropic' => 'ANTHROPIC_API_KEY',
            'gemini' => 'GEMINI_API_KEY',
            default => strtoupper($provider).'_API_KEY',
        };
    }

    private function hasCompatibleReadyRun(KnowledgeSource $source): bool
    {
        $activeRevision = $source->activeRevision;

        return $activeRevision instanceof KnowledgeRevision
            && $activeRevision->status === KnowledgeRevisionStatus::Ready
            && (bool) $activeRevision->getAttribute('has_compatible_ready_run');
    }

    private function hasCompatibleProcessingRun(KnowledgeSource $source): bool
    {
        $activeRevision = $source->activeRevision;

        return $activeRevision instanceof KnowledgeRevision
            && $activeRevision->status === KnowledgeRevisionStatus::Ready
            && (bool) $activeRevision->getAttribute('has_compatible_processing_run');
    }
}
