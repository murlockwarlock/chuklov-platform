<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\Finance\Domain\Contracts\ReceiptStorage;
use App\Modules\Finance\Domain\Models\FinancialReceipt;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class FinanceReceiptController extends Controller
{
    public function __invoke(int $receiptId, ClientPortalContext $clientContext, ReceiptStorage $storage): StreamedResponse
    {
        $client = $clientContext->client();
        $receipt = FinancialReceipt::query()
            ->where('organization_id', $client->organization_id)
            ->whereKey($receiptId)
            ->whereHas('ledgerEntry.obligation', fn ($query) => $query
                ->where('organization_id', $client->organization_id)
                ->where('client_id', $client->getKey()))
            ->firstOrFail();

        return response()->streamDownload(
            function () use ($storage, $receipt): void {
                $stream = $storage->readStream($receipt->path);
                fpassthru($stream);
                fclose($stream);
            },
            $receipt->original_name,
            [
                'Content-Type' => $receipt->mime_type,
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
