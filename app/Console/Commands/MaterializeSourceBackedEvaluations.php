<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\AI\Application\Actions\MaterializeSourceBackedEvaluations as Materializer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('app:materialize-source-backed-evaluations {organization_id : Organization identifier} {--user-id= : Authorized organization user identifier} {--activate : Activate imported source-backed prompt versions}')]
#[Description('Materialize the existing source-backed synthetic evaluation suites for an organization.')]
final class MaterializeSourceBackedEvaluations extends Command
{
    public function handle(OrganizationContext $context, Materializer $materializer): int
    {
        $organizationId = (int) $this->argument('organization_id');
        $userId = (int) $this->option('user-id');
        $organization = Organization::query()->find($organizationId);
        $actor = $userId > 0 ? User::query()->find($userId) : null;

        if (! $organization instanceof Organization) {
            $this->error('Organization was not found.');

            return self::FAILURE;
        }

        if (! $actor instanceof User || ! $actor->hasPermission(OrganizationPermission::ManageAiPrompts, $organization)) {
            $this->error('An authorized --user-id from the same organization is required.');

            return self::FAILURE;
        }

        $context->set($organization);

        try {
            $summary = $materializer->handle($actor, (bool) $this->option('activate'));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(['Metric', 'Count'], [
            ['Prompts created', $summary['prompts_created']],
            ['Prompt versions created', $summary['prompt_versions_created']],
            ['Prompt versions activated', $summary['prompts_activated']],
            ['Evaluation suites created', $summary['suites_created']],
            ['Evaluation cases created', $summary['cases_created']],
        ]);

        return self::SUCCESS;
    }
}
