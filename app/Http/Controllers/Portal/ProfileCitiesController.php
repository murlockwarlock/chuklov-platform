<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProfileCitiesRequest;
use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\Identity\Application\ProfileReferenceData;
use Illuminate\Http\JsonResponse;

final class ProfileCitiesController extends Controller
{
    public function __invoke(ProfileCitiesRequest $request, ClientPortalContext $context, ProfileReferenceData $reference): JsonResponse
    {
        $data = $request->validated();

        return response()->json([
            'cities' => $reference->cities($data['country'], $data['query'] ?? '', $context->client()->language),
            'attribution' => ['source' => 'GeoNames', 'url' => 'https://www.geonames.org/', 'license' => 'https://creativecommons.org/licenses/by/4.0/'],
        ])
            ->header('Cache-Control', 'private, no-store');
    }
}
