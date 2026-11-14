<?php

namespace App\Modules\Sessions\Application;

use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use App\Modules\Sessions\Domain\Models\MedicalSessionProtocolStep;

final readonly class MedicalSessionProtocolStepReader
{
    public function __construct(private MedicalEncryptorInterface $encryptor) {}

    /** @return list<array{id:int,lineage_key:string,step_order:int,text:string}> */
    public function forSession(MedicalSession $session): array
    {
        $organizationId = (int) $session->organization_id;

        return array_values($session->protocolSteps()->get()->map(fn (MedicalSessionProtocolStep $step): array => [
            'id' => (int) $step->getKey(),
            'lineage_key' => (string) $step->lineage_key,
            'step_order' => (int) $step->step_order,
            'text' => (string) $this->encryptor->decryptField(
                $organizationId,
                $step->getRawOriginal('step_text'),
                (int) $step->encryption_key_version,
            ),
        ])->all());
    }
}
