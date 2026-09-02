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
        ?int $clientId = null,
    ): array {
        return $this->resolve($organizationId, $capability, $references, $actor, $clientId)['provenance'];
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
        ?int $clientId = null,
    ): array {
        $attachmentReferences = array_values(array_filter(
            $references,
            static fn (AiInputReference $reference): bool => in_array($reference->type, ['medical_attachment', 'companion_attachment'], true),
        ));

        if ($capability === AiCapability::PostureAnalysis && count($attachmentReferences) !== 3) {
            throw new InvalidArgumentException('Posture analysis requires exactly three medical attachments.');
        }

        if ($attachmentReferences === []) {
            return ['files' => [], 'provenance' => []];
        }

        $maxAttachments = $capability === AiCapability::ClientCompanion
            ? max(1, (int) config('ai.companion.maximum_images_per_turn', 10))
            : 3;
        if (count($attachmentReferences) > $maxAttachments) {
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
        foreach ($attachmentReferences as $reference) {
            $attachment = $attachments->get($reference->id);
            if (! $attachment instanceof MedicalAttachment) {
                throw new InvalidArgumentException('AI medical attachment input reference was not found in the current organization.');
            }

            if ($reference->type === 'companion_attachment') {
                if ($capability !== AiCapability::ClientCompanion
                    || $clientId === null
                    || (int) $attachment->client_id !== $clientId
                    || $attachment->attachment_type !== AttachmentType::CompanionImage) {
                    throw new InvalidArgumentException('AI Companion image input is outside the current client context.');
                }
            } elseif (! $actor instanceof User) {
                throw new InvalidArgumentException('An explicit authorized actor is required for protected medical attachments.');
            } else {
                $this->authorization->authorizeAiProcessing($actor, $attachment, $organization);
            }

            $this->assertCompatible($capability, $attachment, count($ids), $reference->type);
            $provenance[] = $this->safeProvenance($attachment, $reference->type);
            $files[] = $this->toSdkFile($attachment, $reference->type === 'companion_attachment');
        }

        return ['files' => $files, 'provenance' => $provenance];
    }

    /** @return array<string, mixed> */
    private function safeProvenance(MedicalAttachment $attachment, string $referenceType): array
    {
        if ($attachment->disk !== 'private'
            || ! str_starts_with($attachment->storage_path, 'medical/attachments/'.((int) $attachment->organization_id).'/')) {
            throw new InvalidArgumentException('Medical attachment storage is not private and organization-scoped.');
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
            'reference_type' => $referenceType,
        ];
    }

    private function assertCompatible(AiCapability $capability, MedicalAttachment $attachment, int $count, string $referenceType): void
    {
        $type = $attachment->attachment_type;
        $mime = strtolower(trim($attachment->mime_type));

        if ($capability === AiCapability::PostureAnalysis) {
            if ($count !== 3 || $type !== AttachmentType::PosturePhoto || ! in_array($mime, self::IMAGE_MIMES, true)) {
                throw new InvalidArgumentException('Posture analysis accepts exactly three posture images.');
            }

            return;
        }

        if ($referenceType === 'companion_attachment'
            && ($capability !== AiCapability::ClientCompanion || $type !== AttachmentType::CompanionImage || ! in_array($mime, self::IMAGE_MIMES, true))) {
            throw new InvalidArgumentException('AI Companion accepts image input only.');
        }

        if ($capability === AiCapability::ClinicalDocumentExtraction && $type !== AttachmentType::MedicalReport) {
            throw new InvalidArgumentException('Clinical document extraction accepts medical report attachments only.');
        }

        if (! in_array($mime, [...self::DOCUMENT_MIMES, ...self::IMAGE_MIMES], true)) {
            throw new InvalidArgumentException('Medical attachment MIME type is not supported for AI processing.');
        }
    }

    private function toSdkFile(MedicalAttachment $attachment, bool $stripMetadata): File
    {
        if ($stripMetadata) {
            $normalized = $this->normalizedImage($attachment);

            return Image::fromBase64(base64_encode($normalized), $attachment->mime_type)
                ->as($attachment->original_filename);
        }

        $file = in_array(strtolower($attachment->mime_type), self::IMAGE_MIMES, true)
            ? Image::fromStorage($attachment->storage_path, $attachment->disk)
            : Document::fromStorage($attachment->storage_path, $attachment->disk);

        return $file
            ->as($attachment->original_filename)
            ->withMimeType($attachment->mime_type);
    }

    private function normalizedImage(MedicalAttachment $attachment): string
    {
        $raw = Storage::disk($attachment->disk)->get($attachment->storage_path);
        if (! is_string($raw) || $raw === '') {
            throw new InvalidArgumentException('Companion image content is unavailable.');
        }

        $imageInfo = @getimagesizefromstring($raw);
        if (! is_array($imageInfo) || $imageInfo[0] < 1 || $imageInfo[1] < 1) {
            throw new InvalidArgumentException('Companion image content is invalid.');
        }

        if (! function_exists('imagecreatefromstring')) {
            return $raw;
        }

        $image = @imagecreatefromstring($raw);
        if ($image === false) {
            throw new InvalidArgumentException('Companion image content is invalid.');
        }

        ob_start();
        $mime = strtolower((string) $attachment->mime_type);
        $encoded = match ($mime) {
            'image/png' => imagepng($image, null, 6),
            'image/webp' => function_exists('imagewebp') ? imagewebp($image, null, 85) : imagejpeg($image, null, 85),
            default => imagejpeg($image, null, 85),
        };
        $result = ob_get_clean();
        imagedestroy($image);

        if ($encoded !== true || ! is_string($result) || $result === '') {
            throw new InvalidArgumentException('Companion image normalization failed.');
        }

        return $result;
    }
}
