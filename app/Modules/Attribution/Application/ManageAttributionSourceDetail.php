<?php

namespace App\Modules\Attribution\Application;

use App\Models\User;
use App\Modules\Attribution\Domain\Models\ClientAttribution;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class ManageAttributionSourceDetail
{
    public function __construct(
        private OrganizationContext $context,
        private OrganizationAuthorizer $authorizer,
        private AttributionSourceDetail $detail,
        private MedicalEncryptorInterface $encryptor,
        private RecordAuditEvent $audit,
    ) {}

    public function read(User $actor, Client $client): ?string
    {
        $this->authorize($actor, $client, OrganizationPermission::ViewClients);
        $record = $this->query($client)->first();
        if ($record === null || $record->encrypted_source_detail === null) {
            return null;
        }

        return $this->encryptor->decryptField($this->context->id(), $record->encrypted_source_detail, (int) $record->source_detail_key_version);
    }

    public function update(User $actor, Client $client, mixed $detail): void
    {
        $this->authorize($actor, $client, OrganizationPermission::ManageClients);
        DB::transaction(function () use ($actor, $client, $detail): void {
            $record = $this->query($client)->lockForUpdate()->first();
            if ($record === null || ! in_array($record->source_type, ['manual', 'source'], true) || ! $this->detail->supports($record->source)) {
                throw ValidationException::withMessages(['source_detail' => 'У клиента не указана рекомендация знакомых или другой источник.']);
            }
            $record->forceFill($this->detail->attributes($this->context->id(), $record->source, $detail))->save();
            $this->audit->handle($this->context->organization(), $actor, 'attribution.source_detail.updated', $record::class, (string) $record->getKey(), []);
        });
    }

    private function authorize(User $actor, Client $client, OrganizationPermission $permission): void
    {
        $this->authorizer->authorize($actor, $this->context->organization(), $permission);
        abort_unless((int) $client->organization_id === $this->context->id(), 404);
    }

    /** @return Builder<ClientAttribution> */
    private function query(Client $client): Builder
    {
        return ClientAttribution::query()->where('organization_id', $this->context->id())->where('client_id', $client->getKey());
    }
}
