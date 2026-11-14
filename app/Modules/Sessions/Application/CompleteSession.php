<?php

namespace App\Modules\Sessions\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Sessions\Domain\Enums\MedicalSessionStatus;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final readonly class CompleteSession
{
    public function __construct(
        private MedicalSessionAuthorization $authorization,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, MedicalSession $session, ?Client $expectedClient = null): MedicalSession
    {
        $organization = $this->authorization->authorizeManageSession($actor, $session, $expectedClient);

        return DB::transaction(function () use ($actor, $session, $organization): MedicalSession {
            $locked = MedicalSession::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($session->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            if ($locked->status === MedicalSessionStatus::Completed) {
                return $locked;
            }

            $locked->forceFill([
                'status' => MedicalSessionStatus::Completed,
                'completed_at' => Carbon::now('UTC'),
                'edited_at' => null,
            ])->save();
            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'medical.session.completed',
                targetType: MedicalSession::class,
                targetId: (string) $locked->getKey(),
                metadata: ['source' => 'crm'],
            );

            return $locked->refresh();
        });
    }
}
