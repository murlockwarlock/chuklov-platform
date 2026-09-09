<?php

namespace App\Modules\Tracker\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Scenarios\Application\EnsureOperationalNotificationDefaults;
use App\Modules\Scenarios\Application\RecordScenarioEvent;
use App\Modules\Scenarios\Jobs\ProcessScenarioEvent;
use App\Modules\Tracker\Domain\Enums\TrackerTaskFrequency;
use App\Modules\Tracker\Domain\Enums\TrackerTaskType;
use App\Modules\Tracker\Domain\Models\TrackerTask;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AssignTrackerTask
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly EnsureOperationalNotificationDefaults $defaults,
        private readonly RecordScenarioEvent $events,
        private readonly TrackerTaskSchedule $schedule,
    ) {}

    public function handle(
        User $actor,
        Client $client,
        string $title,
        TrackerTaskType $type,
        TrackerTaskFrequency $frequency,
        CarbonImmutable $startsOn,
        ?CarbonImmutable $endsOn = null,
        ?int $weekDay = null,
        int $displayOrder = 0,
    ): TrackerTask {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageClients);
        if ((int) $client->organization_id !== (int) $organization->getKey()) {
            throw new AuthorizationException('The client is outside the current organization.');
        }

        $title = trim($title);
        if ($title === '' || mb_strlen($title) > 160) {
            throw ValidationException::withMessages(['title' => 'Укажите короткое название задачи.']);
        }
        if ($endsOn !== null && $endsOn->lessThan($startsOn)) {
            throw ValidationException::withMessages(['ends_on' => 'Дата окончания не может быть раньше даты начала.']);
        }
        if ($frequency === TrackerTaskFrequency::Weekly && ($weekDay === null || $weekDay < 1 || $weekDay > 7)) {
            throw ValidationException::withMessages(['week_day' => 'Для еженедельной задачи выберите день недели.']);
        }
        if ($frequency === TrackerTaskFrequency::Daily) {
            $weekDay = null;
        }
        if ($displayOrder < 0) {
            throw ValidationException::withMessages(['display_order' => 'Порядок не может быть отрицательным.']);
        }

        $this->defaults->handle($organization);

        $result = DB::transaction(function () use ($actor, $client, $title, $type, $frequency, $startsOn, $endsOn, $weekDay, $displayOrder, $organization): array {
            $scopedClient = Client::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($client->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $task = new TrackerTask;
            $task->forceFill([
                'organization_id' => $organization->getKey(),
                'client_id' => $scopedClient->getKey(),
                'title' => $title,
                'task_type' => $type->value,
                'frequency' => $frequency->value,
                'week_day' => $weekDay,
                'starts_on' => $startsOn->toDateString(),
                'ends_on' => $endsOn?->toDateString(),
                'active' => true,
                'display_order' => $displayOrder,
                'created_by_user_id' => $actor->getKey(),
            ])->save();
            $event = $this->events->trackerTaskAssigned($task, $this->schedule->firstOccurrenceAt($task, $scopedClient));

            return [
                'task_id' => (int) $task->getKey(),
                'event_id' => (int) $event->getKey(),
            ];
        });

        ProcessScenarioEvent::dispatch($result['event_id'])->afterCommit();

        return TrackerTask::query()
            ->where('organization_id', $organization->getKey())
            ->whereKey($result['task_id'])
            ->firstOrFail();
    }
}
