<?php

namespace App\Console\Commands;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Referrals\Application\EnsureReferralIdentity;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('clients:adopt-referral-identities {--limit=100 : Maximum number of clients to adopt in this invocation}')]
#[Description('Adopt bounded legacy Clients into stable M11A referral identities.')]
final class AdoptClientReferralIdentities extends Command
{
    public function handle(OrganizationContext $context, EnsureReferralIdentity $ensureIdentity): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $clients = Client::query()
            ->whereDoesntHave('referralIdentity')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'organization_id']);
        $adopted = 0;

        foreach ($clients as $client) {
            $organization = Organization::query()->find($client->organization_id);

            if (! $organization instanceof Organization) {
                continue;
            }

            $context->set($organization);
            $ensureIdentity->handle($client);
            $adopted++;
        }

        $this->info('Adopted '.$adopted.' referral identity(ies).');

        return self::SUCCESS;
    }
}
