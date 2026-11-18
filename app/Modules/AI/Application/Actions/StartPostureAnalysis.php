<?php

namespace App\Modules\AI\Application\Actions;

use App\Models\User;
use App\Modules\AI\Application\Attachments\AiAttachmentResolver;
use App\Modules\AI\Application\Data\AiRunRequest;
use App\Modules\AI\Application\Services\PostureAnalysisAvailability;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiExecutionMode;
use App\Modules\AI\Domain\Enums\AiModelModality;
use App\Modules\AI\Domain\Enums\AiRunOrigin;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\AI\Domain\ValueObjects\AiInputReference;
use App\Modules\Attachments\Application\AttachmentAuthorization;
use App\Modules\Attachments\Domain\Enums\AttachmentType;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Sessions\Application\MedicalSessionAuthorization;
use App\Modules\Sessions\Domain\Enums\MedicalSessionAttachmentGallery;
use App\Modules\Sessions\Domain\Enums\MedicalSessionAttachmentPlane;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use App\Modules\Sessions\Domain\Models\MedicalSessionAttachment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class StartPostureAnalysis
{
    /** @var list<string> */
    private const ROLES = ['front', 'side', 'back'];

    public function __construct(
        private OrganizationContext $context,
        private AttachmentAuthorization $authorization,
        private AiAttachmentResolver $attachmentResolver,
        private DispatchAsyncAiRun $dispatcher,
        private MedicalSessionAuthorization $sessionAuthorization,
        private PostureAnalysisAvailability $availability,
    ) {}

    /** @param array<string, int> $attachmentIds */
    public function handle(User $actor, Client $client, array $attachmentIds, bool $rerun = false): AiRun
    {
        $organization = $this->context->organization();
        $this->authorization->authorizeView($actor, $client);
        $this->availability->assertReady();

        $normalizedIds = [];
        foreach (self::ROLES as $role) {
            if (! array_key_exists($role, $attachmentIds)) {
                throw new InvalidArgumentException('Для анализа осанки нужны три разных фото: спереди, сбоку и сзади.');
            }

            $normalizedIds[$role] = (int) $attachmentIds[$role];
        }

        if (count(array_unique($normalizedIds)) !== 3) {
            throw new InvalidArgumentException('Для анализа осанки нужны три разных фото: спереди, сбоку и сзади.');
        }

        $organizationAttachments = MedicalAttachment::query()
            ->where('organization_id', $organization->getKey())
            ->where('attachment_type', AttachmentType::PosturePhoto)
            ->whereNull('evaluation_fixture_key')
            ->whereIn('id', array_values($normalizedIds))
            ->get()
            ->keyBy('id');

        if ($organizationAttachments->count() === 3
            && $organizationAttachments->contains(static fn (MedicalAttachment $attachment): bool => (int) $attachment->client_id !== (int) $client->getKey())) {
            throw new AuthorizationException('Одно или несколько фото осанки принадлежат другому клиентскому контексту.');
        }

        $attachments = $organizationAttachments->filter(
            static fn (MedicalAttachment $attachment): bool => (int) $attachment->client_id === (int) $client->getKey(),
        );

        if ($attachments->count() !== 3) {
            throw new InvalidArgumentException('Одно или несколько фото осанки недоступны в текущем клиентском контексте.');
        }

        $references = [new AiInputReference('client', (int) $client->getKey())];
        foreach (self::ROLES as $role) {
            $attachment = $attachments->get($normalizedIds[$role]);
            if (! $attachment instanceof MedicalAttachment) {
                throw new InvalidArgumentException('Не удалось определить фото осанки.');
            }

            $references[] = new AiInputReference('medical_attachment', (int) $attachment->getKey(), $role);
        }

        $this->attachmentResolver->describe(
            organizationId: (int) $organization->getKey(),
            capability: AiCapability::PostureAnalysis,
            references: array_slice($references, 1),
            actor: $actor,
            clientId: (int) $client->getKey(),
        );

        $baseKey = 'posture_analysis:'.hash('sha256', implode('|', array_values($normalizedIds)));
        $idempotencyKey = $this->idempotencyKey((int) $organization->getKey(), $baseKey, $rerun);

        return $this->dispatcher->handle($actor, new AiRunRequest(
            capability: AiCapability::PostureAnalysis,
            workflowKey: 'posture_analysis',
            origin: AiRunOrigin::User,
            executionMode: AiExecutionMode::Async,
            clientId: (int) $client->getKey(),
            inputReferences: $references,
            requiredModalities: [AiModelModality::ImageInput],
            idempotencyKey: $idempotencyKey,
            actor: $actor,
        ));
    }

    /**
     * @param  array<string, int>  $attachmentIds
     */
    public function handleSession(
        User $actor,
        Client $client,
        MedicalSession $session,
        MedicalSessionAttachmentGallery $gallery,
        array $attachmentIds,
        bool $rerun = false,
    ): AiRun {
        $organization = $this->sessionAuthorization->authorizeView($actor, $session, $client);
        $normalizedIds = $this->normalizedIds($attachmentIds);

        $links = MedicalSessionAttachment::query()
            ->where('organization_id', $organization->getKey())
            ->where('client_id', $client->getKey())
            ->where('medical_session_id', $session->getKey())
            ->where('gallery', $gallery->value)
            ->whereIn('medical_attachment_id', array_values($normalizedIds))
            ->with('attachment')
            ->get()
            ->keyBy('medical_attachment_id');

        if ($links->count() !== 3) {
            throw new InvalidArgumentException('Для выбранной фазы нужны три фото, привязанные к этому сеансу: спереди, сбоку и сзади.');
        }

        $references = [
            new AiInputReference('client', (int) $client->getKey()),
            new AiInputReference('medical_session', (int) $session->getKey(), $gallery->value),
        ];
        foreach (self::ROLES as $role) {
            $link = $links->get($normalizedIds[$role]);
            if (! $link instanceof MedicalSessionAttachment
                || $link->gallery !== $gallery
                || $link->plane !== MedicalSessionAttachmentPlane::from($role)
                || ! $link->attachment instanceof MedicalAttachment
                || (int) $link->attachment->organization_id !== (int) $organization->getKey()
                || (int) $link->attachment->client_id !== (int) $client->getKey()
                || $link->attachment->attachment_type !== AttachmentType::PosturePhoto
                || $link->attachment->evaluation_fixture_key !== null) {
                throw new InvalidArgumentException('Фото не соответствуют выбранной фазе и направлению. Выберите три фото из этой галереи.');
            }

            $references[] = new AiInputReference('medical_attachment', (int) $link->attachment->getKey(), $role);
        }

        $this->attachmentResolver->describe(
            organizationId: (int) $organization->getKey(),
            capability: AiCapability::PostureAnalysis,
            references: array_slice($references, 2),
            actor: $actor,
            clientId: (int) $client->getKey(),
        );
        $this->availability->assertReady();

        $baseKey = 'posture_analysis_session:' . $session->getKey() . ':' . $gallery->value . ':' . hash('sha256', implode('|', array_values($normalizedIds)));
        $idempotencyKey = $this->sessionIdempotencyKey((int) $organization->getKey(), $baseKey, $rerun);

        return $this->dispatcher->handle($actor, new AiRunRequest(
            capability: AiCapability::PostureAnalysis,
            workflowKey: 'posture_analysis_session',
            origin: AiRunOrigin::User,
            executionMode: AiExecutionMode::Async,
            clientId: (int) $client->getKey(),
            inputReferences: $references,
            requiredModalities: [AiModelModality::ImageInput],
            idempotencyKey: $idempotencyKey,
            actor: $actor,
        ));
    }

    /** @param array<string, int> $attachmentIds @return array<string, int> */
    private function normalizedIds(array $attachmentIds): array
    {
        $normalizedIds = [];
        foreach (self::ROLES as $role) {
            if (! array_key_exists($role, $attachmentIds)) {
                throw new InvalidArgumentException('Для анализа осанки нужны три разных фото: спереди, сбоку и сзади.');
            }

            $normalizedIds[$role] = (int) $attachmentIds[$role];
        }

        if (count(array_unique($normalizedIds)) !== 3) {
            throw new InvalidArgumentException('Для анализа осанки нужны три разных фото: спереди, сбоку и сзади.');
        }

        return $normalizedIds;
    }

    private function idempotencyKey(int $organizationId, string $baseKey, bool $rerun): string
    {
        if ($rerun) {
            return $baseKey.':rerun:'.substr(hash('sha256', (string) Str::uuid()), 0, 16);
        }

        $failedRun = AiRun::query()
            ->where('organization_id', $organizationId)
            ->where('idempotency_key', $baseKey)
            ->whereIn('status', ['failed', 'timed_out', 'invalid_output', 'cancelled'])
            ->first();

        return $failedRun === null ? $baseKey : $baseKey.':retry:'.$failedRun->getKey();
    }

    private function sessionIdempotencyKey(int $organizationId, string $baseKey, bool $rerun): string
    {
        if (! $rerun) {
            return $baseKey;
        }

        $previousTerminalRun = AiRun::query()
            ->where('organization_id', $organizationId)
            ->where('workflow_key', 'posture_analysis_session')
            ->where(function ($query) use ($baseKey): void {
                $query
                    ->where('idempotency_key', $baseKey)
                    ->orWhere('idempotency_key', 'like', $baseKey.':%');
            })
            ->whereIn('status', ['succeeded', 'failed', 'timed_out', 'invalid_output', 'cancelled'])
            ->orderByDesc('id')
            ->first();

        return $baseKey.':rerun:'.($previousTerminalRun?->getKey() ?? 'initial');
    }
}
