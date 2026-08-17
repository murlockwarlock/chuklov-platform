<?php

namespace App\Modules\AI\Infrastructure\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Providers\BedrockProvider;

final class BoundedBedrockProvider extends BedrockProvider
{
    /** @param array<string, mixed> $config */
    public function __construct(array $config, Dispatcher $events)
    {
        parent::__construct($config, $events);
        $gateway = new BoundedBedrockTextGateway;

        $this->useTextGateway($gateway);
        $this->useEmbeddingGateway($gateway);
    }
}
