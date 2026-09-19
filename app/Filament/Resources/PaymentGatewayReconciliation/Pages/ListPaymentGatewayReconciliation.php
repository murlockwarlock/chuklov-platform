<?php

namespace App\Filament\Resources\PaymentGatewayReconciliation\Pages;

use App\Filament\Resources\PaymentGatewayReconciliation\PaymentGatewayReconciliationResource;
use Filament\Resources\Pages\ListRecords;

final class ListPaymentGatewayReconciliation extends ListRecords
{
    protected static string $resource = PaymentGatewayReconciliationResource::class;

    protected static ?string $title = 'Платежи, требующие сверки';

    public function getBreadcrumbs(): array
    {
        return [];
    }
}
