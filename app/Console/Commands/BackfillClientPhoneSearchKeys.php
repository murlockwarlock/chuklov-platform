<?php

namespace App\Console\Commands;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\ValueObjects\ClientPhoneSearchKey;
use Illuminate\Console\Command;

final class BackfillClientPhoneSearchKeys extends Command
{
    protected $signature = 'clients:backfill-phone-search-keys
        {--limit=500 : Maximum number of clients to process in this batch}
        {--after-id=0 : Resume after this client ID}';

    protected $description = 'Populate canonical phone search keys for clients in bounded batches.';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $afterId = (int) $this->option('after-id');

        if ($limit < 1 || $limit > 5000 || $afterId < 0) {
            $this->error('The batch limit must be between 1 and 5000 and after-id cannot be negative.');

            return self::FAILURE;
        }

        $clients = Client::query()
            ->where('id', '>', $afterId)
            ->whereNotNull('phone')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'phone']);

        $processed = 0;
        $lastId = $afterId;

        foreach ($clients as $client) {
            $key = ClientPhoneSearchKey::from($client->phone)?->value;

            Client::query()
                ->whereKey($client->getKey())
                ->update(['phone_search_key' => $key]);

            $processed++;
            $lastId = (int) $client->getKey();
        }

        $hasMore = $lastId > $afterId
            && Client::query()
                ->where('id', '>', $lastId)
                ->whereNotNull('phone')
                ->exists();

        $this->info("Processed {$processed} client phone search keys. Last ID: {$lastId}.".($hasMore ? ' More remain.' : ' Complete.'));

        return self::SUCCESS;
    }
}
