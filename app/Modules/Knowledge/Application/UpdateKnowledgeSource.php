<?php

namespace App\Modules\Knowledge\Application;

use App\Models\User;
use App\Modules\Knowledge\Application\Data\KnowledgeSourceUpdateResult;
use App\Modules\Knowledge\Domain\Enums\KnowledgeExtractionStatus;
use App\Modules\Knowledge\Domain\Enums\KnowledgeSourceType;
use App\Modules\Knowledge\Domain\Models\KnowledgeRevision;
use App\Modules\Knowledge\Domain\Models\KnowledgeSource;
use App\Modules\Knowledge\Infrastructure\Parsing\KnowledgeDocumentParser;
use App\Modules\Knowledge\Jobs\IngestKnowledgeRevision;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

final class UpdateKnowledgeSource
{
    public function __construct(
        private readonly KnowledgeAuthorization $authorization,
        private readonly RecordAuditEvent $audit,
        private readonly KnowledgeDocumentParser $parser,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(User $actor, KnowledgeSource $source, array $data): KnowledgeSourceUpdateResult
    {
        $organization = $this->authorization->organizationForSource($actor, $source, OrganizationPermission::ManageKnowledge);
        $title = is_string($data['title'] ?? null) ? trim($data['title']) : $source->title;
        $category = array_key_exists('category', $data)
            ? (is_string($data['category']) ? trim($data['category']) : null)
            : $source->category;
        $hasExplicitSourceReference = array_key_exists('source_reference', $data);
        $sourceReference = $hasExplicitSourceReference
            ? (is_string($data['source_reference']) ? trim($data['source_reference']) : null)
            : null;
        $hasCompanionScope = array_key_exists('client_companion_enabled', $data);
        $clientCompanionEnabled = $hasCompanionScope
            ? (bool) $data['client_companion_enabled']
            : null;

        if ($title === '' || mb_strlen($title) > 200 || ($category !== null && mb_strlen($category) > 80) || ($hasExplicitSourceReference && $sourceReference !== null && mb_strlen($sourceReference) > 500)) {
            throw ValidationException::withMessages(['source' => 'Проверьте название и категорию источника.']);
        }

        $type = $source->type;
        $content = null;
        $disk = null;
        $path = null;
        $filename = null;
        $mime = 'text/markdown';
        $size = 0;
        $materialProvided = false;
        $originalChecksum = null;
        $parserType = 'authored_text';
        $parserVersion = 'manual-v1';
        $extractionStatus = KnowledgeExtractionStatus::Ready;
        $extractionDiagnostics = ['character_count' => 0];
        $extractedAt = now();
        $revisionContent = null;

        if ($type === KnowledgeSourceType::AuthoredText && array_key_exists('content', $data)) {
            $materialProvided = true;
            $content = is_string($data['content']) ? $data['content'] : '';
            if (trim($content) === '') {
                throw ValidationException::withMessages(['content' => 'Добавьте текст источника.']);
            }
            if (mb_strlen($content) > (int) config('rag.uploads.maximum_extracted_characters')) {
                throw ValidationException::withMessages(['content' => 'Текст превышает допустимый размер.']);
            }
            $size = strlen($content);
            $revisionContent = $content;
            $originalChecksum = hash('sha256', $content);
            $extractionDiagnostics = ['character_count' => mb_strlen($content)];
        } elseif ($type === KnowledgeSourceType::UploadedText && ($data['file'] ?? null) instanceof UploadedFile) {
            $materialProvided = true;
            $file = $data['file'];
            $extension = strtolower($file->getClientOriginalExtension());
            if (! in_array($extension, config('rag.uploads.allowed_extensions', []), true) || $file->getSize() > ((int) config('rag.uploads.maximum_kilobytes') * 1024)) {
                throw ValidationException::withMessages(['file' => 'Поддерживаются небольшие TXT, Markdown, PDF, CSV, XLSX, XLS и ODS.']);
            }
            $disk = (string) config('rag.uploads.disk');
            $storedPath = $file->store('knowledge/sources/'.$organization->getKey(), $disk);
            if (! is_string($storedPath)) {
                throw ValidationException::withMessages(['file' => 'Не удалось сохранить документ.']);
            }
            $path = $storedPath;
            $filename = $file->getClientOriginalName();
            $size = (int) $file->getSize();
            try {
                $parsed = $this->parser->parse(Storage::disk($disk)->path($path), $filename);
            } catch (Throwable $exception) {
                Storage::disk($disk)->delete($path);
                throw $exception;
            }
            $mime = $parsed->mimeType;
            $originalChecksum = $parsed->originalChecksum;
            $parserType = $parsed->parserType;
            $parserVersion = $parsed->parserVersion;
            $extractionStatus = $parsed->status;
            $extractionDiagnostics = $parsed->diagnostics;
            $extractedAt = now();
            $content = $parsed->content;
            $revisionContent = $parsed->isReady() && ! in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), ['txt', 'md', 'markdown'], true)
                ? $parsed->content
                : null;
        }

        $checksum = $materialProvided ? hash('sha256', (string) ($content ?? '')) : null;

        try {
            $result = DB::transaction(function () use ($actor, $source, $organization, $title, $category, $hasExplicitSourceReference, $sourceReference, $revisionContent, $disk, $path, $filename, $mime, $size, $checksum, $originalChecksum, $parserType, $parserVersion, $extractionStatus, $extractionDiagnostics, $extractedAt, $materialProvided, $hasCompanionScope, $clientCompanionEnabled): KnowledgeSourceUpdateResult {
                $lockedSource = KnowledgeSource::query()
                    ->where('organization_id', $organization->getKey())
                    ->whereKey($source->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                if ($lockedSource->status->value === 'retired') {
                    throw ValidationException::withMessages(['source' => 'Сначала восстановите источник.']);
                }

                $latestRevision = $lockedSource->revisions()
                    ->orderByDesc('version')
                    ->lockForUpdate()
                    ->first();
                $materialChanged = $materialProvided && (
                    $lockedSource->type === KnowledgeSourceType::UploadedText
                    || $latestRevision === null
                    || $latestRevision->content_checksum !== $checksum
                );

                if (! $materialChanged && $hasExplicitSourceReference && $latestRevision?->source_reference !== $sourceReference) {
                    throw ValidationException::withMessages(['source_reference' => 'Происхождение версии нельзя изменить без новой версии материала.']);
                }

                $changedFields = [];
                if ($lockedSource->title !== $title) {
                    $changedFields[] = 'title';
                }
                if ($lockedSource->category !== $category) {
                    $changedFields[] = 'category';
                }
                if ($hasCompanionScope && (bool) $lockedSource->client_companion_enabled !== $clientCompanionEnabled) {
                    $changedFields[] = 'client_companion_enabled';
                }
                if ($changedFields !== []) {
                    $lockedSource->update([
                        'title' => $title,
                        'category' => $category,
                        ...($hasCompanionScope ? ['client_companion_enabled' => $clientCompanionEnabled] : []),
                    ]);
                    $this->audit->handle(
                        organization: $organization,
                        actor: $actor,
                        action: 'knowledge.source.updated',
                        targetType: KnowledgeSource::class,
                        targetId: (string) $lockedSource->getKey(),
                        metadata: ['fields' => implode(',', $changedFields)],
                    );
                }

                if (! $materialChanged) {
                    return new KnowledgeSourceUpdateResult($lockedSource->refresh(), null, false);
                }

                $version = ((int) $lockedSource->revisions()->max('version')) + 1;
                $revision = KnowledgeRevision::query()->create([
                    'organization_id' => $organization->getKey(),
                    'knowledge_source_id' => $lockedSource->getKey(),
                    'version' => $version,
                    'status' => $extractionStatus === KnowledgeExtractionStatus::Ready ? 'pending' : 'failed',
                    'content' => $revisionContent,
                    'storage_disk' => $disk,
                    'storage_path' => $path,
                    'original_filename' => $filename,
                    'mime_type' => $mime,
                    'size_bytes' => $size,
                    'content_checksum' => $checksum,
                    'original_checksum' => $originalChecksum,
                    'parser_type' => $parserType,
                    'parser_version' => $parserVersion,
                    'extraction_status' => $extractionStatus->value,
                    'extraction_diagnostics' => $extractionDiagnostics,
                    'extracted_at' => $extractedAt,
                    'source_reference' => $hasExplicitSourceReference ? $sourceReference : $latestRevision?->source_reference,
                    'created_by_user_id' => $actor->getKey(),
                ]);
                $this->audit->handle(
                    organization: $organization,
                    actor: $actor,
                    action: 'knowledge.revision.created',
                    targetType: KnowledgeRevision::class,
                    targetId: (string) $revision->getKey(),
                    metadata: ['source_id' => $lockedSource->getKey(), 'version' => $version],
                );
                $this->audit->handle(
                    organization: $organization,
                    actor: $actor,
                    action: 'knowledge.revision.extracted',
                    targetType: KnowledgeRevision::class,
                    targetId: (string) $revision->getKey(),
                    metadata: [
                        'source_id' => $lockedSource->getKey(),
                        'revision_id' => $revision->getKey(),
                        'parser_type' => $parserType,
                        'parser_version' => $parserVersion,
                        'extraction_status' => $extractionStatus->value,
                    ],
                );

                return new KnowledgeSourceUpdateResult($lockedSource->refresh(), $revision, true);
            });
        } catch (Throwable $exception) {
            if ($path !== null) {
                Storage::disk((string) config('rag.uploads.disk'))->delete($path);
            }

            throw $exception;
        }

        if ($result->revision instanceof KnowledgeRevision && $result->revision->extraction_status === KnowledgeExtractionStatus::Ready->value) {
            try {
                $dispatch = IngestKnowledgeRevision::dispatch(
                    $organization->getKey(),
                    $result->source->getKey(),
                    $result->revision->getKey(),
                );
                unset($dispatch);
            } catch (Throwable) {
                try {
                    $this->audit->handle(
                        organization: $organization,
                        actor: $actor,
                        action: 'knowledge.ingestion.dispatch_failed',
                        targetType: KnowledgeRevision::class,
                        targetId: (string) $result->revision->getKey(),
                        metadata: [
                            'source_id' => $result->source->getKey(),
                            'revision_id' => $result->revision->getKey(),
                            'operation' => 'replacement',
                        ],
                    );
                } catch (Throwable $auditException) {
                    report($auditException);
                }
            }
        }

        return $result;
    }
}
