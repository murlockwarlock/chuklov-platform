<?php

namespace App\Filament\Resources\ReferralPayoutRequests\Pages;

use App\Filament\Resources\ReferralPayoutRequests\ReferralPayoutRequestResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewReferralPayoutRequest extends ViewRecord
{
    protected static string $resource = ReferralPayoutRequestResource::class;

    protected static ?string $title = 'Запрос на выплату';
}
