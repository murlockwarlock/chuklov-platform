<?php

namespace App\Modules\Knowledge\Application;

use App\Models\User;
use App\Modules\Knowledge\Domain\Enums\KnowledgeSourceType;
use App\Modules\Knowledge\Domain\Models\KnowledgeRevision;
use App\Modules\Knowledge\Domain\Models\KnowledgeSource;
use App\Modules\Knowledge\Jobs\IngestKnowledgeRevision;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

final class CreateKnowledgeSource
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly KnowledgeAuthorization $authorization,
        private readonly RecordAuditEvent $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(User $actor, array $data): KnowledgeSource
    {
        $organization = $this->context->organization();
        $this->authorization->authorizeManage($actor, $organization);
        $type = KnowledgeSourceType::tryFrom(is_string($data['type'] ?? null) ? $data['type'] : '');

        $title = is_string($data['title'] ?? null) ? trim($data['title']) : '';
        $category = is_string($data['category'] ?? null) ? trim($data['category']) : null;
        $sourceReference = is_string($data['source_reference'] ?? null) ? trim($data['source_reference']) : null;
        $clientCompanionEnabled = (bool) ($data['client_companion_enabled'] ?? false);
        if (! $type instanceof KnowledgeSourceType || $title === '' || mb_strlen($title) > 200 || ($category !== null && mb_strlen($category) > 80) || ($sourceReference !== null && mb_strlen($sourceReference) > 500)) {
            throw ValidationException::withMessages(['source' => 'Укажите корректный тип и название источника.']);
        }

        $content = null;
        $disk = null;
        $path = null;
        $filename = null;
        $mime = 'text/markdown';
        $size = 0;

        if ($type === KnowledgeSourceType::AuthoredText) {
            $content = (string) ($data['content'] ?? '');
            $mime = 'text/markdown';
            $size = strlen($content);
            if (trim($content) === '') {
                throw ValidationException::withMessages(['content' => 'Добавьте текст источника.']);
            }
            if (mb_strlen($content) > (int) config('rag.uploads.maximum_extracted_characters')) {
                throw ValidationException::withMessages(['content' => 'Текст превышает допустимый размер.']);
            }
        } elseif (($data['file'] ?? null) instanceof UploadedFile) {
            $file = $data['file'];
            $mime = (string) $file->getMimeType();
            $extension = strtolower($file->getClientOriginalExtension());
            if (! in_array($mime, config('rag.uploads.allowed_mime_types', []), true) || ! in_array($extension, config('rag.uploads.allowed_extensions', []), true) || $file->getSize() > ((int) config('rag.uploads.maximum_kilobytes') * 1024)) {
                throw ValidationException::withMessages(['file' => 'Поддерживаются только небольшие текстовые документы Markdown или TXT.']);
            }
            $disk = (string) config('rag.uploads.disk');
            $storedPath = $file->store('knowledge/sources/'.$organization->getKey(), $disk);
            if (! is_string($storedPath)) {
                throw ValidationException::withMessages(['file' => 'Не удалось сохранить документ.']);
            }
            $path = $storedPath;
            $filename = $file->getClientOriginalName();
            $size = (int) $file->getSize();
            $content = Storage::disk($disk)->get($path);
            if (! is_string($content)) {
                Storage::disk($disk)->delete($path);
                throw ValidationException::withMessages(['file' => 'Не удалось прочитать документ.']);
            }
            if (mb_strlen($content) > (int) config('rag.uploads.maximum_extracted_characters')) {
                Storage::disk($disk)->delete($path);
                throw ValidationException::withMessages(['file' => 'Документ превышает допустимый размер текста.']);
            }
        } else {
            throw ValidationException::withMessages(['file' => 'Загрузите текстовый документ.']);
        }

        $checksum = hash('sha256', (string) $content);
        try {
            $source = DB::transaction(function () use ($actor, $organization, $type, $title, $category, $sourceReference, $content, $disk, $path, $filename, $mime, $size, $checksum, $clientCompanionEnabled): KnowledgeSource {
                $source = KnowledgeSource::query()->create([
                    'organization_id' => $organization->getKey(),
                    'type' => $type,
                    'title' => $title,
                    'category' => $category,
                    'status' => 'active',
                    'client_companion_enabled' => $clientCompanionEnabled,
                ]);
                KnowledgeRevision::query()->create([
                    'organization_id' => $organization->getKey(),
                    'knowledge_source_id' => $source->getKey(),
                    'version' => 1,
                    'status' => 'pending',
                    'content' => $type === KnowledgeSourceType::AuthoredText ? $content : null,
                    'storage_disk' => $disk,
                    'storage_path' => $path,
                    'original_filename' => $filename,
                    'mime_type' => $mime,
                    'size_bytes' => $size,
                    'content_checksum' => $checksum,
                    'source_reference' => $sourceReference,
                    'created_by_user_id' => $actor->getKey(),
                ]);
                $this->audit->handle($organization, $actor, 'knowledge.source.created', KnowledgeSource::class, (string) $source->getKey(), [
                    'source_type' => $type->value,
                    'client_companion_enabled' => $clientCompanionEnabled,
                ]);

                return $source->refresh();
            });
        } catch (Throwable $exception) {
            if ($path !== null) {
                Storage::disk((string) config('rag.uploads.disk'))->delete($path);
            }

            throw $exception;
        }

        $revision = $source->revisions()->sole();
        try {
            $dispatch = IngestKnowledgeRevision::dispatch($organization->getKey(), $source->getKey(), $revision->getKey());
            unset($dispatch);
        } catch (Throwable) {
            try {
                $this->audit->handle(
                    organization: $organization,
                    actor: $actor,
                    action: 'knowledge.ingestion.dispatch_failed',
                    targetType: KnowledgeRevision::class,
                    targetId: (string) $revision->getKey(),
                    metadata: [
                        'source_id' => $source->getKey(),
                        'revision_id' => $revision->getKey(),
                        'operation' => 'create',
                    ],
                );
            } catch (Throwable $auditException) {
                report($auditException);
            }
        }

        return $source;
    }
}
