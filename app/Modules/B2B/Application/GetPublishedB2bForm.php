<?php

namespace App\Modules\B2B\Application;

use App\Modules\B2B\Domain\Enums\B2bFormConfigurationStatus;
use App\Modules\B2B\Domain\Models\B2bFormConfiguration;
use App\Modules\Organizations\Application\OrganizationContext;

final readonly class GetPublishedB2bForm
{
    public function __construct(private OrganizationContext $context) {}

    /** @return array{version: int|null, fields: array<int, array<string, mixed>>} */
    public function handle(): array
    {
        $configuration = B2bFormConfiguration::query()
            ->where('organization_id', $this->context->id())
            ->where('status', B2bFormConfigurationStatus::Published->value)
            ->latest('version')
            ->first();

        return [
            'version' => $configuration?->version,
            'fields' => array_values(array_filter(
                $configuration?->fields ?? [],
                static fn (mixed $field): bool => is_array($field) && ($field['active'] ?? true) === true,
            )),
        ];
    }
}
