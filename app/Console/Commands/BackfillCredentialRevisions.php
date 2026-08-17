<?php

namespace App\Console\Commands;

use App\Modules\Security\Domain\Models\OrganizationCredential;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

#[Signature('security:backfill-credential-revisions {--limit=500 : Maximum number of legacy rows to process in this invocation}')]
#[Description('Assign immutable revisions to a bounded batch of legacy organization credentials')]
final class BackfillCredentialRevisions extends Command
{
    public function handle(): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 5000) {
            $this->error('The --limit option must be an integer between 1 and 5000.');

            return self::INVALID;
        }

        $ids = OrganizationCredential::query()
            ->whereNull('revision_id')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');
        $updated = 0;

        foreach ($ids as $id) {
            $updated += DB::table('organization_credentials')
                ->where('id', $id)
                ->whereNull('revision_id')
                ->update(['revision_id' => (string) Str::uuid()]);
        }

        $remaining = OrganizationCredential::query()->whereNull('revision_id')->count();
        $this->info("Assigned {$updated} credential revision(s); {$remaining} legacy row(s) remain.");

        return self::SUCCESS;
    }
}
