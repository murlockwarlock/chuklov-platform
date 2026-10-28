<?php

namespace App\Modules\Analytics\Application;

use App\Models\User;
use App\Modules\Analytics\Domain\Enums\ClientSegment;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;

final readonly class ClientSegmentAnalytics
{
    public function __construct(
        private OrganizationContext $context,
        private OrganizationAuthorizer $authorizer,
        private ClientSegmentQuery $segments,
    ) {}

    /** @return array{client_count: int, segments: list<array{label: string, count: int, percent: float}>} */
    public function handle(User $actor, ?Client $client = null): array
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ViewClients);
        $organizationId = (int) $organization->getKey();

        if ($client instanceof Client && (int) $client->organization_id !== $organizationId) {
            abort(404);
        }

        $base = Client::query()->where('organization_id', $organizationId);
        if ($client instanceof Client) {
            $base->whereKey($client->getKey());
        }
        $total = (clone $base)->count();
        $rows = [];
        foreach (ClientSegment::cases() as $segment) {
            $count = (clone $this->segments->apply(clone $base, $segment))->count();
            $rows[] = [
                'label' => $segment->label(),
                'count' => $count,
                'percent' => $total === 0 ? 0.0 : round($count * 100 / $total, 2),
            ];
        }

        return [
            'client_count' => $total,
            'segments' => $rows,
        ];
    }
}
