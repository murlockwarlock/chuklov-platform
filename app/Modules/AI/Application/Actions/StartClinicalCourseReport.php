<?php

namespace App\Modules\AI\Application\Actions;

use App\Models\User;
use App\Modules\AI\Application\Data\AiRunRequest;
use App\Modules\AI\Application\Services\BuildClinicalCourseReportInput;
use App\Modules\AI\Application\Services\EnsureClinicalCourseReportPrompt;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiExecutionMode;
use App\Modules\AI\Domain\Enums\AiRunOrigin;
use App\Modules\AI\Domain\Enums\ClinicalSynthesizerWorkflow;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Sessions\Application\MedicalSessionAuthorization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final readonly class StartClinicalCourseReport
{
    public function __construct(
        private OrganizationContext $context,
        private OrganizationAuthorizer $authorizer,
        private MedicalSessionAuthorization $sessionAuthorization,
        private BuildClinicalCourseReportInput $inputBuilder,
        private EnsureClinicalCourseReportPrompt $prompt,
        private DispatchAsyncAiRun $dispatcher,
    ) {}

    public function handle(User $actor, Client $client, string $courseStartDate, bool $rerun = false): AiRun
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ViewClients);
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ViewAiRuns);
        $this->sessionAuthorization->authorizeViewClient($actor, $client);

        $courseStart = $this->parseCourseStart($courseStartDate, $this->context->defaultTimezone());
        $courseEnd = CarbonImmutable::now('UTC');
        if ($courseStart->greaterThan($courseEnd)) {
            throw new InvalidArgumentException('Начало курса не может быть позже момента формирования отчёта.');
        }

        $input = $this->inputBuilder->handle($actor, $client, $courseStart, $courseEnd);
        $promptVersion = $this->prompt->handle();
        $baseKey = ClinicalSynthesizerWorkflow::CourseReport->value.':'.$input->sourceDigest;

        return $this->dispatcher->handle($actor, new AiRunRequest(
            capability: AiCapability::ClinicalSynthesizer,
            workflowKey: ClinicalSynthesizerWorkflow::CourseReport->value,
            origin: AiRunOrigin::User,
            executionMode: AiExecutionMode::Async,
            clientId: (int) $client->getKey(),
            promptVersionId: (int) $promptVersion->getKey(),
            inputVariables: $input->inputVariables,
            inputReferences: $input->inputReferences,
            idempotencyKey: $this->idempotencyKey(
                organizationId: (int) $organization->getKey(),
                baseKey: $baseKey,
                rerun: $rerun,
            ),
            actor: $actor,
        ));
    }

    private function parseCourseStart(string $value, string $timezone): CarbonImmutable
    {
        $value = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Укажите корректную дату начала курса.');
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, $timezone);
        } catch (Throwable) {
            throw new InvalidArgumentException('Укажите корректную дату начала курса.');
        }

        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Укажите корректную дату начала курса.');
        }

        return $date->startOfDay()->utc();
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
