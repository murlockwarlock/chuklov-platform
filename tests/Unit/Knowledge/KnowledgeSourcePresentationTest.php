<?php

namespace Tests\Unit\Knowledge;

use App\Filament\Support\KnowledgeSourcePresentation;
use App\Modules\Knowledge\Domain\Enums\KnowledgeRevisionStatus;
use App\Modules\Knowledge\Domain\Enums\KnowledgeSourceStatus;
use App\Modules\Knowledge\Domain\Models\KnowledgeRevision;
use App\Modules\Knowledge\Domain\Models\KnowledgeSource;
use PHPUnit\Framework\TestCase;

final class KnowledgeSourcePresentationTest extends TestCase
{
    public function test_source_availability_and_newest_processing_are_presented_separately(): void
    {
        $presentation = new KnowledgeSourcePresentation;

        $firstPending = $this->source(KnowledgeRevisionStatus::Pending, null, false);
        self::assertSame('Не в поиске', $presentation->searchAvailability($firstPending));
        self::assertSame('Ожидает обработки', $presentation->latestProcessing($firstPending));
        self::assertTrue($presentation->canStartPending($firstPending, $firstPending->latestRevision));

        $firstProcessing = $this->source(KnowledgeRevisionStatus::Processing, null, false);
        self::assertSame('Не в поиске', $presentation->searchAvailability($firstProcessing));
        self::assertSame('Обрабатывается', $presentation->latestProcessing($firstProcessing));

        $firstFailed = $this->source(KnowledgeRevisionStatus::Failed, null, false);
        self::assertSame('Не в поиске', $presentation->searchAvailability($firstFailed));
        self::assertSame('Требуется повторная обработка', $presentation->latestProcessing($firstFailed));

        $ready = $this->source(KnowledgeRevisionStatus::Ready, 1, true);
        self::assertSame('В поиске', $presentation->searchAvailability($ready));
        self::assertSame('Материал обработан', $presentation->latestProcessing($ready));

        foreach ([
            [KnowledgeRevisionStatus::Pending, 'Новая версия ожидает обработки'],
            [KnowledgeRevisionStatus::Processing, 'Новая версия обрабатывается'],
            [KnowledgeRevisionStatus::Failed, 'Новая версия не обработана'],
        ] as [$status, $label]) {
            $source = $this->sourceWithActiveRevisionAndNewer($status);
            self::assertSame('В поиске', $presentation->searchAvailability($source));
            self::assertSame($label, $presentation->latestProcessing($source));
        }

        $incompatible = $this->source(KnowledgeRevisionStatus::Ready, 1, false);
        self::assertSame('Требуется переобработка для поиска', $presentation->searchAvailability($incompatible));
        self::assertSame('Требуется переобработка для поиска', $presentation->latestProcessing($incompatible));
        $activeRevision = $incompatible->activeRevision;
        self::assertInstanceOf(KnowledgeRevision::class, $activeRevision);
        self::assertTrue($presentation->canReprocessForSearch($incompatible, $activeRevision));

        $currentlyProcessing = $this->source(KnowledgeRevisionStatus::Ready, 1, false, KnowledgeSourceStatus::Active, true);
        self::assertSame('Требуется переобработка для поиска', $presentation->searchAvailability($currentlyProcessing));
        self::assertSame('Подготовка для поиска выполняется', $presentation->latestProcessing($currentlyProcessing));
        self::assertFalse($presentation->canReprocessForSearch($currentlyProcessing, $currentlyProcessing->activeRevision));

        $retired = $this->source(KnowledgeRevisionStatus::Ready, 1, true, KnowledgeSourceStatus::Retired);
        self::assertSame('Источник выключен', $presentation->searchAvailability($retired));
        self::assertSame('Источник выключен', $presentation->latestProcessing($retired));
    }

    public function test_unknown_failure_is_safe_and_null_means_no_recorded_error(): void
    {
        $presentation = new KnowledgeSourcePresentation;

        self::assertSame('Нет зарегистрированной ошибки', $presentation->errorMessage(null));
        self::assertSame('Обработка не завершена. Попробуйте повторить обработку.', $presentation->errorMessage('unexpected_provider_detail'));
        self::assertStringNotContainsString('unexpected_provider_detail', $presentation->errorMessage('unexpected_provider_detail'));
    }

    private function source(
        KnowledgeRevisionStatus $latestStatus,
        ?int $activeRevisionId,
        bool $compatibleRun,
        KnowledgeSourceStatus $sourceStatus = KnowledgeSourceStatus::Active,
        bool $processingRun = false,
    ): KnowledgeSource {
        $revision = new KnowledgeRevision;
        $revision->forceFill(['id' => 1, 'status' => $latestStatus, 'original_filename' => null]);
        $revision->setAttribute('has_compatible_ready_run', $compatibleRun);
        $revision->setAttribute('has_compatible_processing_run', $processingRun);

        $source = new KnowledgeSource;
        $source->forceFill([
            'id' => 10,
            'status' => $sourceStatus,
            'active_revision_id' => $activeRevisionId,
        ]);
        $source->setRelation('activeRevision', $activeRevisionId === null ? null : $revision);
        $source->setRelation('latestRevision', $revision);

        return $source;
    }

    private function sourceWithActiveRevisionAndNewer(KnowledgeRevisionStatus $newestStatus): KnowledgeSource
    {
        $activeRevision = new KnowledgeRevision;
        $activeRevision->forceFill(['id' => 1, 'status' => KnowledgeRevisionStatus::Ready]);
        $activeRevision->setAttribute('has_compatible_ready_run', true);

        $newestRevision = new KnowledgeRevision;
        $newestRevision->forceFill(['id' => 2, 'status' => $newestStatus]);

        $source = new KnowledgeSource;
        $source->forceFill(['id' => 10, 'status' => KnowledgeSourceStatus::Active, 'active_revision_id' => 1]);
        $source->setRelation('activeRevision', $activeRevision);
        $source->setRelation('latestRevision', $newestRevision);

        return $source;
    }
}
