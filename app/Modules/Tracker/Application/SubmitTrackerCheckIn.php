<?php

namespace App\Modules\Tracker\Application;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Tracker\Domain\Models\TrackerCheckIn;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

final class SubmitTrackerCheckIn
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly ResolveTrackerAccess $access,
    ) {}

    public function handle(Client $client, string $note): TrackerCheckIn
    {
        if ((int) $client->organization_id !== $this->context->id()) {
            throw new AuthorizationException('The client is outside the current organization.');
        }
        if (! $this->access->handle($client)->allowed()) {
            throw new AuthorizationException('Tracker access is required.');
        }
        $note = trim($note);
        if ($note === '' || mb_strlen($note) > 5000) {
            throw ValidationException::withMessages(['note' => 'Напишите короткую отметку о сегодняшнем состоянии.']);
        }
        $entry = new TrackerCheckIn;
        $entry->forceFill([
            'organization_id' => $this->context->id(),
            'client_id' => $client->getKey(),
            'occurred_at' => now('UTC'),
            'note' => $note,
            'source' => 'portal',
        ])->save();

        return $entry->refresh();
    }
}
