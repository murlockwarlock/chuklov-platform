<?php

return [
    'reset_client_ids' => array_values(array_filter(
        array_map(
            static fn (string $clientId): int => (int) trim($clientId),
            explode(',', (string) env('STAGING_RESET_CLIENT_IDS', '')),
        ),
        static fn (int $clientId): bool => $clientId > 0,
    )),
];
