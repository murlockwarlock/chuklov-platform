<?php

namespace App\Modules\Knowledge\Application;

use App\Models\User;
use App\Modules\Knowledge\Domain\Enums\KnowledgeSourceType;
use App\Modules\Knowledge\Domain\Models\KnowledgeRevision;
use App\Modules\Knowledge\Domain\Models\KnowledgeSource;
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
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(User $actor, KnowledgeSource $source, array $data): KnowledgeRevision
    {
        $organization = $this->authorization->organizationForSource($actor, $source, OrganizationPermission::ManageKnowledge);
        if ($source->status->value === 'retired') {
            throw ValidationException::withMessages(['source' => 'Сначала восстановите источник.']);
        }
        $source->loadMissing('revisions');
        $title = is_string($data['title'] ?? null) ? trim($data['title']) : $source->title;
        $category = array_key_exists('category', $data) && is_string($data['category']) ? trim($data['category']) : $source->category;
        $sourceReference = is_string($data['source_reference'] ?? null) ? trim($data['source_reference']) : null;
        if ($title === '' || mb_strlen($title) > 200 || ($category !== null && mb_strlen($category) > 80) || ($sourceReference !== null && mb_strlen($sourceReference) > 500)) {
            throw ValidationException::withMessages(['source' => 'Проверьте название, категорию и ссылку на источник.']);
        }
        $type = $source->type;
        $content = null;
        $disk = null;
        $path = null;
        $filename = null;
        $mime = 'text/markdown';
        $size = 0;

        if ($type === KnowledgeSourceType::AuthoredText) {
            $content = (string) ($data['content'] ?? '');
            if (trim($content) === '') {
                throw ValidationException::withMessages(['content' => 'Добавьте текст источника.']);
            }
            if (mb_strlen($content) > (int) config('rag.uploads.maximum_extracted_characters')) {
                throw ValidationException::withMessages(['content' => 'Текст превышает допустимый размер.']);
            }
            $size = strlen($content);
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
            throw ValidationException::withMessages(['file' => 'Загрузите новую версию текстового документа.']);
        }

        $checksum = hash('sha256', (string) $content);
        try {
            $revision = DB::transaction(function () use ($actor, $source, $organization, $title, $category, $sourceReference, $content, $disk, $path, $filename, $mime, $size, $checksum): KnowledgeRevision {
                $lockedSource = KnowledgeSource::query()
                    ->where('organization_id', $organization->getKey())
                    ->whereKey($source->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                if ($lockedSource->status->value === 'retired') {
                    throw ValidationException::withMessages(['source' => 'Сначала восстановите источник.']);
                }
                $version = ((int) $lockedSource->revisions()->max('version')) + 1;
                $revision = KnowledgeRevision::query()->create([
                    'organization_id' => $organization->getKey(),
                    'knowledge_source_id' => $lockedSource->getKey(),
                    'version' => $version,
                    'status' => 'pending',
                    'content' => $lockedSource->type === KnowledgeSourceType::AuthoredText ? $content : null,
                    'storage_disk' => $disk,
                    'storage_path' => $path,
                    'original_filename' => $filename,
                    'mime_type' => $mime,
                    'size_bytes' => $size,
                    'content_checksum' => $checksum,
                    'source_reference' => $sourceReference,
                    'created_by_user_id' => $actor->getKey(),
                ]);
                $lockedSource->update(['title' => $title, 'category' => $category]);
                $this->audit->handle($organization, $actor, 'knowledge.revision.created', KnowledgeRevision::class, (string) $revision->getKey(), ['source_id' => $lockedSource->getKey(), 'version' => $version]);

                return $revision;
            });
        } catch (Throwable $exception) {
            if ($path !== null) {
                Storage::disk((string) config('rag.uploads.disk'))->delete($path);
            }

            throw $exception;
        }

        IngestKnowledgeRevision::dispatch($organization->getKey(), $source->getKey(), $revision->getKey());

        return $revision;
    }
}
