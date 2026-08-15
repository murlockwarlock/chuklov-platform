<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Modules\Finance\Application\FinanceAuthorization;
use App\Modules\Finance\Domain\Contracts\ReceiptStorage;
use App\Modules\Finance\Domain\Models\FinancialReceipt;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AdminFinanceReceiptController extends Controller
{
    public function __invoke(int $receiptId, ReceiptStorage $storage): StreamedResponse
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        $organization = app(FinanceAuthorization::class)->authorizeView($actor);
        $receipt = FinancialReceipt::query()
            ->where('organization_id', $organization->getKey())
            ->whereKey($receiptId)
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
