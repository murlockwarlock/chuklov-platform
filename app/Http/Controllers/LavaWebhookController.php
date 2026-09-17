<?php

namespace App\Http\Controllers;

use App\Modules\Finance\Application\ReceiveLavaWebhook;
use App\Modules\Finance\Infrastructure\Lava\LavaWebhookAuthenticator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LavaWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        LavaWebhookAuthenticator $authenticator,
        ReceiveLavaWebhook $receive,
    ): JsonResponse {
        $organizationId = $authenticator->authenticate($request);
        if ($organizationId === null) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $payload = $request->json()->all();
        if (! is_array($payload)) {
            return response()->json(['message' => 'Invalid webhook payload'], 422);
        }

        $receive->handle($organizationId, $payload);

        return response()->json([], 204);
    }
}
