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

        return $value === KnowledgeSourceType::AuthoredText->value ? __('Текст') : __('Документ');
    }

    public function searchAvailability(KnowledgeSource $source): string
    {
        if ($source->status === KnowledgeSourceStatus::Retired) {
            return __('Индексация недоступна');
        }

        $activeRevision = $source->activeRevision;
        if (! $activeRevision instanceof KnowledgeRevision || $activeRevision->status !== KnowledgeRevisionStatus::Ready) {
            return __('Ожидает индексации');
        }
        $extractionStatus = $activeRevision->getAttribute('extraction_status');
        if ($extractionStatus !== null && $extractionStatus !== KnowledgeExtractionStatus::Ready->value) {
            return __('Ошибка индексации');
        }
        if ($this->semanticSearchStatus() !== __('Готов')) {
            return __('Индексация недоступна');
        }
        if (! $this->hasCompatibleReadyRun($source)) {
            return $this->hasCompatibleProcessingRun($source) ? __('Индексируется') : __('Ожидает индексации');
        }

        return __('Готов к поиску');
    }

    public function materialStatus(KnowledgeSource $source): string
    {
        return $source->status === KnowledgeSourceStatus::Active ? __('Используется') : __('Скрыт');
    }

    public function semanticSearchStatus(): string
    {
        try {
            $configuration = EmbeddingConfiguration::active();
            $pricing = EmbeddingPricingPolicy::active();
            if (! $pricing->zeroCostLocal && ! $this->providerCredentialIsConfigured($configuration->provider)) {
                return __('Не настроен');
            }
            $pricing->assertCompatible($configuration);
        } catch (InvalidArgumentException) {
            return __('Не настроен');
        } catch (\Throwable) {
            return __('Не настроен');
        }

        return __('Готов');
    }

    public function semanticSearchSummary(): string
    {
        try {
            $configuration = EmbeddingConfiguration::active();
        } catch (\Throwable) {
            return __('Поиск по смыслу пока не настроен. Добавьте подключение AI и сохраните настройки поиска.');
        }

        $summary = __('Поиск по смыслу: :status. Модель AI: :model.', [
            'status' => $this->semanticSearchStatus(),
            'model' => $configuration->model,
        ]);
        $gap = $this->semanticSearchGap($configuration);

        return $gap === null ? $summary : $summary.' '.$gap;
    }

    public function semanticSearchGap(?EmbeddingConfiguration $configuration = null): ?string
    {
        $configuration ??= EmbeddingConfiguration::active();

        if (! $this->providerCredentialIsConfigured($configuration->provider)) {
            return __('Не настроено подключение AI.');
        }

        try {
            EmbeddingPricingPolicy::active()->assertCompatible($configuration);
        } catch (InvalidArgumentException) {
            return __('Не настроена стоимость обработки материалов.');
        } catch (\Throwable) {
            return __('Не настроены параметры поиска по смыслу.');
        }

        return null;
    }

    public function latestProcessing(KnowledgeSource $source): string
    {
        if ($source->status === KnowledgeSourceStatus::Retired) {
            return __('Источник выключен');
        }

        $latestRevision = $source->latestRevision;
        if (! $latestRevision instanceof KnowledgeRevision) {
            return __('Материал не добавлен');
        }

        if ($latestRevision->extraction_status === KnowledgeExtractionStatus::TextNotFound->value) {
            return __('Текст не найден. Можно запустить AI-разбор.');
        }
        if ($latestRevision->extraction_status === KnowledgeExtractionStatus::Suspicious->value) {
            return __('Извлечение заблокировано: требуется проверка владельца');
        }
        if ($latestRevision->extraction_status === KnowledgeExtractionStatus::AiParseRequested->value) {
            return __('AI-разбор запрошен владельцем');
        }

        $hasActiveDifferentRevision = $source->active_revision_id !== null
            && (int) $source->active_revision_id !== (int) $latestRevision->getKey();

        if (! $hasActiveDifferentRevision
            && $latestRevision->status === KnowledgeRevisionStatus::Ready
            && ! $this->hasCompatibleReadyRun($source)) {
            if ($this->hasCompatibleProcessingRun($source)) {
                return __('Индексируется');
            }

            return $this->semanticSearchStatus() === __('Готов')
                ? __('Ожидает индексации')
                : __('Индексация недоступна');
        }

        return match ($latestRevision->status) {
            KnowledgeRevisionStatus::Pending => $hasActiveDifferentRevision ? __('Новая версия ожидает обработки') : __('Ожидает обработки'),
            KnowledgeRevisionStatus::Processing => $hasActiveDifferentRevision ? __('Новая версия обрабатывается') : __('Обрабатывается'),
            KnowledgeRevisionStatus::Failed => $hasActiveDifferentRevision ? __('Новая версия не обработана') : __('Требуется повторная обработка'),
            KnowledgeRevisionStatus::Ready => $hasActiveDifferentRevision ? __('Готова новая версия') : __('Материал обработан'),
            KnowledgeRevisionStatus::Stale => __('Предыдущая версия'),
            KnowledgeRevisionStatus::Retired => __('Версия выключена'),
        };
    }

    public function revisionStatus(KnowledgeRevisionStatus|string $status): string
    {
        $value = $status instanceof KnowledgeRevisionStatus ? $status->value : $status;

        return match ($value) {
            KnowledgeRevisionStatus::Pending->value => __('Ожидает обработки'),
            KnowledgeRevisionStatus::Processing->value => __('Обрабатывается'),
            KnowledgeRevisionStatus::Ready->value => __('Готова'),
            KnowledgeRevisionStatus::Failed->value => __('Не обработана'),
            KnowledgeRevisionStatus::Stale->value => __('Предыдущая версия'),
            KnowledgeRevisionStatus::Retired->value => __('Скрыта'),
            default => __('Состояние недоступно'),
        };
    }

    public function errorMessage(?string $errorCode): string
    {
        if ($errorCode === null) {
            return __('Нет зарегистрированной ошибки');
        }

        return match ($errorCode) {
            'invalid_source_content' => __('Файл повреждён или изменён'),
            'source_text_too_large' => __('Слишком большой объём текста'),
            'empty_source_content' => __('В документе нет текста'),
            'extraction_not_ready' => __('Извлечение не подтверждено владельцем'),
            'parser_failed' => __('Не удалось безопасно извлечь данные'),
            'text_not_found' => __('Текст не найден. Можно запустить AI-разбор.'),
            'table_text_not_found' => __('Таблица не содержит данных'),
            'embedding_or_persistence_failed' => __('Обработка не завершена'),
            default => __('Обработка не завершена. Попробуйте повторить обработку.'),
        };
    }

    public function materialName(KnowledgeRevision $revision): string
    {
        if ($revision->original_filename === null || trim($revision->original_filename) === '') {
            return __('Текст вручную');
        }

        $filename = basename(str_replace('\\', '/', $revision->original_filename));
        $filename = preg_replace('/[\x00-\x1F\x7F"<>:|?*]+/u', ' ', $filename) ?? '';
        $filename = trim(mb_substr($filename, 0, 120), " .\t\n\r\0\x0B");

        return $filename !== '' ? $filename : __('Файл');
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
