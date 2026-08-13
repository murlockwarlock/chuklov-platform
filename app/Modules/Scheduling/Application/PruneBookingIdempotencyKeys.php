<?php

namespace App\Modules\Scheduling\Application;

use Illuminate\Support\Facades\DB;

final class PruneBookingIdempotencyKeys
{
    public function handle(): int
    {
        return DB::transaction(function (): int {
            $expiredIds = DB::table('booking_idempotency_keys')
                ->where('expires_at', '<=', now())
                ->lockForUpdate()
                ->pluck('id');

            if ($expiredIds->isEmpty()) {
                return 0;
            }

            DB::table('booking_idempotency_keys')
                ->whereIn('id', $expiredIds)
                ->update(['booking_id' => null]);

            return DB::table('booking_idempotency_keys')
                ->whereIn('id', $expiredIds)
                ->delete();
        });
    }
}
