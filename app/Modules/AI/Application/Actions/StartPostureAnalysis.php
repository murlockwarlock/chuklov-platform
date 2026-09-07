<?php

namespace App\Modules\AI\Application\Actions;

use App\Models\User;
use App\Modules\AI\Application\Attachments\AiAttachmentResolver;
use App\Modules\AI\Application\Data\AiRunRequest;
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
    ) {}

    /** @param array<string, int> $attachmentIds */
    public function handle(User $actor, Client $client, array $attachmentIds, bool $rerun = false): AiRun
    {
        $organization = $this->context->organization();
        $this->authorization->authorizeView($actor, $client);

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
}
