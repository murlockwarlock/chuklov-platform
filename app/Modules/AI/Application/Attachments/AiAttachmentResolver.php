<?php

namespace App\Modules\AI\Application\Attachments;

use App\Models\User;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\ValueObjects\AiInputReference;
use App\Modules\Attachments\Application\AttachmentAuthorization;
use App\Modules\Attachments\Domain\Enums\AttachmentType;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\File;
use Laravel\Ai\Files\Image;

final class AiAttachmentResolver
{
    private const MAX_ATTACHMENTS = 3;

    /** @var list<string> */
    private const IMAGE_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    /** @var list<string> */
    private const DOCUMENT_MIMES = [
        'application/pdf',
        'text/plain',
    ];

    public function __construct(
        private readonly AttachmentAuthorization $authorization,
        private readonly OrganizationContext $context,
    ) {}

    /**
     * @param  list<AiInputReference>  $references
     * @return list<array<string, mixed>>
     */
    public function describe(
        int $organizationId,
        AiCapability $capability,
        array $references,
        ?User $actor,
    ): array {
        return $this->resolve($organizationId, $capability, $references, $actor)['provenance'];
    }

    /**
     * @param  list<AiInputReference>  $references
     * @return array{files: list<File>, provenance: list<array<string, mixed>>}
     */
    public function resolve(
        int $organizationId,
        AiCapability $capability,
        array $references,
        ?User $actor,
    ): array {
        $attachmentReferences = array_values(array_filter(
            $references,
            static fn (AiInputReference $reference): bool => $reference->type === 'medical_attachment',
        ));

        if ($capability === AiCapability::PostureAnalysis && count($attachmentReferences) !== self::MAX_ATTACHMENTS) {
            throw new InvalidArgumentException('Posture analysis requires exactly three medical attachments.');
        }

        if ($attachmentReferences === []) {
            return ['files' => [], 'provenance' => []];
        }

        if (count($attachmentReferences) > self::MAX_ATTACHMENTS) {
            throw new InvalidArgumentException('AI execution accepts at most three medical attachments.');
        }

        $ids = array_map(static fn (AiInputReference $reference): int => $reference->id, $attachmentReferences);
        if (count($ids) !== count(array_unique($ids))) {
            throw new InvalidArgumentException('An AI execution cannot use the same medical attachment more than once.');
        }

        $organization = Organization::query()->whereKey($organizationId)->first()
            ?? throw new InvalidArgumentException('AI attachment organization was not found.');
        $this->context->set($organization);

        $attachments = MedicalAttachment::query()
            ->where('organization_id', $organizationId)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        if ($attachments->count() !== count($ids)) {
            throw new InvalidArgumentException('AI medical attachment input reference was not found in the current organization.');
        }

        $files = [];
        $provenance = [];
        foreach ($ids as $id) {
            /** @var MedicalAttachment $attachment */
            $attachment = $attachments->get($id);
            if (! $actor instanceof User) {
                throw new InvalidArgumentException('An explicit authorized actor is required for protected medical attachments.');
            }

            $this->authorization->authorizeAiProcessing($actor, $attachment, $organization);
            $this->assertCompatible($capability, $attachment, count($ids));
            $provenance[] = $this->safeProvenance($attachment);
            $files[] = $this->toSdkFile($attachment);
        }

        return ['files' => $files, 'provenance' => $provenance];
    }

    /** @return array<string, mixed> */
    private function safeProvenance(MedicalAttachment $attachment): array
    {
        if ($attachment->disk !== 'private'
            || ! str_starts_with($attachment->storage_path, 'medical/attachments/'.((int) $attachment->organization_id).'/')) {
            throw new InvalidArgumentException('Medical attachment storage is not private and organization-scoped.');
        }

        if (! $attachment->isAvailable()) {
            throw new InvalidArgumentException('Medical attachment is not cleared for AI processing.');
        }

        $disk = Storage::disk($attachment->disk);
        if (! $disk->exists($attachment->storage_path)) {
            throw new InvalidArgumentException('Medical attachment content is unavailable.');
        }

        $actualSize = $disk->size($attachment->storage_path);
        $maxBytes = (int) config('medical.attachment_max_bytes', 20_971_520);
        if ($actualSize <= 0 || $actualSize > $maxBytes || (int) $attachment->size_bytes !== $actualSize) {
            throw new InvalidArgumentException('Medical attachment size is invalid or changed.');
        }

        $stream = $disk->readStream($attachment->storage_path);
        if (! is_resource($stream)) {
            throw new InvalidArgumentException('Medical attachment content is unavailable.');
        }

        $context = hash_init('sha256');
        hash_update_stream($context, $stream);
        fclose($stream);
        $checksum = hash_final($context);
        if (! hash_equals((string) $attachment->sha256_checksum, $checksum)) {
            throw new InvalidArgumentException('Medical attachment checksum does not match its immutable record.');
        }

        return [
            'attachment_id' => (int) $attachment->id,
            'attachment_uuid' => (string) $attachment->uuid,
            'attachment_type' => $attachment->attachment_type->value,
            'sha256_checksum' => $checksum,
            'mime_type' => (string) $attachment->mime_type,
            'size_bytes' => $actualSize,
        ];
    }

    private function assertCompatible(AiCapability $capability, MedicalAttachment $attachment, int $count): void
    {
        $type = $attachment->attachment_type;
        $mime = strtolower(trim($attachment->mime_type));

        if ($capability === AiCapability::PostureAnalysis) {
            if ($count !== self::MAX_ATTACHMENTS || $type !== AttachmentType::PosturePhoto || ! in_array($mime, self::IMAGE_MIMES, true)) {
                throw new InvalidArgumentException('Posture analysis accepts exactly three cleared posture images.');
            }

            return;
        }

        if ($capability === AiCapability::ClinicalDocumentExtraction && $type !== AttachmentType::MedicalReport) {
            throw new InvalidArgumentException('Clinical document extraction accepts medical report attachments only.');
        }

        if (! in_array($mime, [...self::DOCUMENT_MIMES, ...self::IMAGE_MIMES], true)) {
            throw new InvalidArgumentException('Medical attachment MIME type is not supported for AI processing.');
        }
    }

    private function toSdkFile(MedicalAttachment $attachment): File
    {
        $file = in_array(strtolower($attachment->mime_type), self::IMAGE_MIMES, true)
            ? Image::fromStorage($attachment->storage_path, $attachment->disk)
            : Document::fromStorage($attachment->storage_path, $attachment->disk);

        return $file
            ->as($attachment->original_filename)
            ->withMimeType($attachment->mime_type);
    }
}
