<?php

namespace App\Filament\Support;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Specialists\SpecialistResource;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Specialists\Domain\Models\Specialist;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class CrmEntityLinks
{
    public static function clientUrl(?Client $client, ?bool $canView = null): ?string
    {
        if (! self::belongsToCurrentOrganization($client)) {
            return null;
        }

        if (($canView ?? ClientResource::canView($client)) !== true) {
            return null;
        }

        return ClientResource::getUrl('view', ['record' => $client->getKey()]);
    }

    public static function specialistUrl(?Specialist $specialist, ?bool $canView = null): ?string
    {
        if (! self::belongsToCurrentOrganization($specialist)) {
            return null;
        }

        if (($canView ?? SpecialistResource::canView($specialist)) !== true) {
            return null;
        }

        return SpecialistResource::getUrl('view', ['record' => $specialist->getKey()]);
    }

    private static function belongsToCurrentOrganization(?Model $entity): bool
    {
        if ($entity === null) {
            return false;
        }

        $organizationId = $entity->getAttribute('organization_id');
        if ($organizationId === null || $organizationId === '') {
            return false;
        }

        try {
            return (string) $organizationId === (string) app(OrganizationContext::class)->id();
        } catch (LogicException) {
            return false;
        }
    }
}
