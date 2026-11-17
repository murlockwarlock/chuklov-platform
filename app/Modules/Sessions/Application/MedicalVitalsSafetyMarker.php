<?php

namespace App\Modules\Sessions\Application;

use App\Models\User;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalKeyResolverInterface;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use App\Modules\Sessions\Domain\Models\MedicalSessionSafetyMarker;
use Illuminate\Support\Facades\DB;

final readonly class MedicalVitalsSafetyMarker
{
    public const MARKER_KEY = 'vitals_out_of_range';

    public const DEFAULT_TEXT = 'Показатель вне допустимого диапазона';

    public function __construct(
        private MedicalSessionAuthorization $authorization,
        private MedicalEncryptorInterface $encryptor,
        private MedicalKeyResolverInterface $keyResolver,
        private MedicalVitalsSettings $settings,
        private MedicalMeasurementCalculator $calculator,
        private RecordAuditEvent $audit,
    ) {}

    /** @param array<string, mixed>|null $measurements */
    public function sync(User $actor, MedicalSession $session, ?array $measurements): void
    {
        $organization = $this->authorization->authorizeManageSession($actor, $session);
        $orgId = (int) $organization->getKey();
        $red = $measurements !== null
            && $this->containsRed($this->calculator->evaluate($measurements, $this->settings->forOrganization($orgId)));
        $keyVersion = $this->keyResolver->getCurrentKeyVersion($orgId);

        DB::transaction(function () use ($actor, $organization, $session, $orgId, $red, $keyVersion): void {
            $marker = MedicalSessionSafetyMarker::query()
                ->where('organization_id', $orgId)
                ->where('medical_session_id', $session->getKey())
                ->where('marker_key', self::MARKER_KEY)
                ->lockForUpdate()
                ->first();

            if (! $red) {
                if ($marker !== null) {
                    $marker->delete();
                }

                return;
            }

            $marker ??= new MedicalSessionSafetyMarker;
            $marker->forceFill([
                'organization_id' => $orgId,
                'client_id' => $session->client_id,
                'medical_session_id' => $session->getKey(),
                'marker_key' => self::MARKER_KEY,
                'marker_text' => $this->encryptor->encryptField($orgId, self::DEFAULT_TEXT, $keyVersion),
                'encryption_key_version' => $keyVersion,
            ])->save();

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'medical.session.updated',
                targetType: MedicalSession::class,
                targetId: (string) $session->getKey(),
                metadata: [
                    'source' => self::MARKER_KEY,
                    'updated_fields' => 'safety_marker',
                ],
            );
        });
    }

    /** @param array<string, array<string, array{value: float|null, state: string>>> $evaluation */
    private function containsRed(array $evaluation): bool
    {
        foreach ($evaluation as $phase) {
            foreach ($phase as $item) {
                if (($item['state'] ?? null) === 'red') {
                    return true;
                }
            }
        }

        return false;
    }
}
