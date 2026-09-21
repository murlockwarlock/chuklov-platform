<?php

namespace App\Filament\Resources\PaymentGatewayReconciliation\Pages;

use App\Filament\Resources\PaymentGatewayReconciliation\PaymentGatewayReconciliationResource;
use App\Filament\Support\LocalizedListRecords;

final class ListPaymentGatewayReconciliation extends LocalizedListRecords
{
    protected static string $resource = PaymentGatewayReconciliationResource::class;

    protected static ?string $title = 'Платежи, требующие сверки';

    public function getBreadcrumbs(): array
    {
        return [];
    }
}
