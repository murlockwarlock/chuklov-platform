<?php

namespace App\Filament\Support;

use App\Modules\Knowledge\Domain\Enums\KnowledgeRevisionStatus;
use App\Modules\Knowledge\Domain\Enums\KnowledgeSourceStatus;
use App\Modules\Knowledge\Domain\Enums\KnowledgeSourceType;
use App\Modules\Knowledge\Domain\Models\KnowledgeRevision;
use App\Modules\Knowledge\Domain\Models\KnowledgeSource;

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
            return 'Источник выключен';
        }

        $activeRevision = $source->activeRevision;
        if (! $activeRevision instanceof KnowledgeRevision || $activeRevision->status !== KnowledgeRevisionStatus::Ready) {
            return 'Не в поиске';
        }
        if (! $this->hasCompatibleReadyRun($source)) {
            return 'Требуется переобработка для поиска';
        }

        return 'В поиске';
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

        $hasActiveDifferentRevision = $source->active_revision_id !== null
            && (int) $source->active_revision_id !== (int) $latestRevision->getKey();

        if (! $hasActiveDifferentRevision
            && $latestRevision->status === KnowledgeRevisionStatus::Ready
            && ! $this->hasCompatibleReadyRun($source)) {
            return 'Требуется подготовка для поиска';
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
            && $revision->status === KnowledgeRevisionStatus::Failed;
    }

    public function canDownload(KnowledgeSource $source, KnowledgeRevision $revision): bool
    {
        return $source->type === KnowledgeSourceType::UploadedText
            && $revision->storage_disk !== null
            && $revision->storage_path !== null;
    }

    public function canReprocessForSearch(KnowledgeSource $source, KnowledgeRevision $revision): bool
    {
        return $source->status === KnowledgeSourceStatus::Active
            && (int) $source->active_revision_id === (int) $revision->getKey()
            && $revision->status === KnowledgeRevisionStatus::Ready
            && ! (bool) $revision->getAttribute('has_compatible_ready_run')
            && ! (bool) $revision->getAttribute('has_compatible_processing_run');
    }

    private function hasCompatibleReadyRun(KnowledgeSource $source): bool
    {
        $activeRevision = $source->activeRevision;

        return $activeRevision instanceof KnowledgeRevision
            && $activeRevision->status === KnowledgeRevisionStatus::Ready
            && (bool) $activeRevision->getAttribute('has_compatible_ready_run');
    }
}
