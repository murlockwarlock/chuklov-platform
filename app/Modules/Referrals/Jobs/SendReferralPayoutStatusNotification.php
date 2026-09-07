<?php

namespace App\Modules\Referrals\Jobs;

use App\Modules\Referrals\Application\SendReferralPayoutStatusNotification as Sender;
use App\Modules\Referrals\Domain\Models\ReferralPayoutRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class SendReferralPayoutStatusNotification implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly int $organizationId,
        public readonly int $payoutRequestId,
        public readonly string $status,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(Sender $sender): void
    {
        $request = ReferralPayoutRequest::query()
            ->where('organization_id', $this->organizationId)
            ->whereKey($this->payoutRequestId)
            ->first();

        if ($request === null || $request->status->value !== $this->status) {
            return;
        }

        $sender->handle($request);
    }

    /** @return list<string> */
    public function tags(): array
    {
        return [
            'referral-payout:'.$this->payoutRequestId,
            'organization:'.$this->organizationId,
            'status:'.$this->status,
        ];
    }
}
