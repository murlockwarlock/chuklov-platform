<?php

namespace App\Modules\Attribution\Application;

use App\Modules\Attribution\Domain\Models\PreAuthAttribution;
use App\Modules\Attribution\Domain\ValueObjects\AttributionNormalizer;
use App\Modules\Organizations\Application\OrganizationContext;
use Illuminate\Support\Facades\DB;

final class CapturePreAuthAttribution
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly AttributionNormalizer $normalizer,
        private readonly AttributionSourceDetail $detail,
    ) {}

    /** @param array<string, mixed> $input */
    public function handle(
        string $sessionId,
        array $input,
        string $captureChannel = 'portal',
        ?string $captureContext = null,
        mixed $sourceDetail = null,
    ): ?PreAuthAttribution {
        $sessionId = trim($sessionId);
        $data = $this->normalizer->handle($input);

        if ($sessionId === '' || $data === null) {
            return null;
        }

        $organization = $this->context->organization();
        $detailAttributes = $this->detail->attributes((int) $organization->getKey(), $data->referralCode === null ? $data->source : null, $sourceDetail);
        $now = now();
        $expiresAt = $now->copy()->addSeconds(max(60, min(86400, (int) config('attribution.pre_auth_ttl_seconds', 1800))));
        $sessionHash = hash('sha256', $sessionId);

        return DB::transaction(function () use ($organization, $sessionHash, $data, $captureChannel, $captureContext, $now, $expiresAt, $detailAttributes): PreAuthAttribution {
            $existing = PreAuthAttribution::query()
                ->where('organization_id', $organization->getKey())
                ->where('session_hash', $sessionHash)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof PreAuthAttribution) {
                if ($existing->consumed_at !== null || $existing->expires_at->isFuture()) {
                    return $existing;
                }

                $existing->forceFill([
                    ...$data->toArray(),
                    ...$detailAttributes,
                    'capture_channel' => $captureChannel,
                    'capture_context' => $captureContext,
                    'captured_at' => $now,
                    'expires_at' => $expiresAt,
                    'consumed_at' => null,
                    'consumed_client_id' => null,
                    'updated_at' => $now,
                ])->save();

                return $existing->refresh();
            }

            DB::table('pre_auth_attributions')->insertOrIgnore([
                'organization_id' => $organization->getKey(),
                'session_hash' => $sessionHash,
                ...$data->toArray(),
                ...$detailAttributes,
                'capture_channel' => $captureChannel,
                'capture_context' => $captureContext,
                'captured_at' => $now,
                'expires_at' => $expiresAt,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $record = PreAuthAttribution::query()
                ->where('organization_id', $organization->getKey())
                ->where('session_hash', $sessionHash)
                ->lockForUpdate()
                ->firstOrFail();

            if ($record->consumed_at !== null || $record->expires_at->isFuture()) {
                return $record;
            }

            $record->forceFill([
                ...$data->toArray(),
                ...$detailAttributes,
                'capture_channel' => $captureChannel,
                'capture_context' => $captureContext,
                'captured_at' => $now,
                'expires_at' => $expiresAt,
                'consumed_at' => null,
                'consumed_client_id' => null,
                'updated_at' => $now,
            ])->save();

            return $record->refresh();
        });
    }
}
