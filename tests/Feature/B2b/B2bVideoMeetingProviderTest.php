<?php

namespace Tests\Feature\B2b;

use App\Modules\B2B\Domain\ValueObjects\VideoMeetingIdentity;
use App\Modules\B2B\Domain\ValueObjects\VideoMeetingRequest;
use App\Modules\B2B\Infrastructure\Video\ZoomVideoMeetingProvider;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Domain\Enums\CredentialStatus;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class B2bVideoMeetingProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_zoom_adapter_uses_server_to_server_oauth_and_keeps_host_url_out_of_client_identity(): void
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $credential = new OrganizationCredential;
        $credential->forceFill([
            'organization_id' => $organization->getKey(),
            'provider' => 'zoom',
            'credential_name' => config('b2b.credential_name'),
            'revision_id' => (string) Str::uuid(),
            'status' => CredentialStatus::Active,
            'credentials' => [
                'account_id' => 'account-1',
                'client_id' => 'client-1',
                'client_secret' => 'secret-1',
                'host_user_id' => 'evgeny@example.test',
            ],
            'last_rotated_at' => now(),
        ])->save();

        Http::fake(function (Request $request) use ($organization) {
            if ($request->url() === (string) config('b2b.zoom.oauth_url')) {
                return Http::response(['access_token' => 'server-token'], 200);
            }

            if ($request->method() === 'POST' && str_ends_with($request->url(), '/users/evgeny%40example.test/meetings')) {
                return Http::response([
                    'id' => 123456,
                    'uuid' => 'meeting-uuid',
                    'join_url' => 'https://zoom.example.test/join/123456',
                ], 201);
            }

            if ($request->method() === 'GET' && str_contains($request->url(), '/users/evgeny%40example.test/meetings')) {
                return Http::response([
                    'meetings' => [[
                        'id' => 123456,
                        'uuid' => 'meeting-uuid',
                        'join_url' => 'https://zoom.example.test/join/123456',
                        'tracking_fields' => [[
                            'field' => 'chuklov_sales_call',
                            'value' => 'b2b-sales-call:'.$organization->getKey().':1',
                        ]],
                    ]],
                ], 200);
            }

            if ($request->method() === 'PATCH' && str_ends_with($request->url(), '/meetings/123456')) {
                return Http::response([], 204);
            }

            if ($request->method() === 'GET' && str_ends_with($request->url(), '/meetings/123456')) {
                return Http::response(['start_url' => 'https://zoom.example.test/start/123456'], 200);
            }

            if ($request->method() === 'DELETE' && str_ends_with($request->url(), '/meetings/123456')) {
                return Http::response([], 204);
            }

            return Http::response([], 404);
        });

        $request = new VideoMeetingRequest(
            externalKey: 'b2b-sales-call:'.$organization->getKey().':1',
            startsAt: CarbonImmutable::create(2026, 8, 31, 10, 0, 0, 'UTC'),
            durationMinutes: 60,
            timezone: 'Asia/Almaty',
            topic: 'Chuklov B2B sales call',
        );
        $provider = app(ZoomVideoMeetingProvider::class);
        $result = $provider->createMeeting($organization, $request);
        $found = $provider->findMeeting($organization, $request);

        $provider->updateMeeting($organization, $result->identity, $request);
        $hostUrl = $provider->obtainHostLaunchUrl($organization, new VideoMeetingIdentity('123456', 'meeting-uuid'));
        $provider->cancelMeeting($organization, $result->identity);

        self::assertSame('123456', $result->identity->meetingId);
        self::assertSame('meeting-uuid', $result->identity->meetingUuid);
        self::assertSame('https://zoom.example.test/join/123456', $result->joinUrl);
        self::assertNotNull($found);
        self::assertSame('123456', $found->identity->meetingId);
        self::assertSame('https://zoom.example.test/start/123456', $hostUrl);
        self::assertArrayNotHasKey('start_url', get_object_vars($result));
        self::assertSame(['account_id', 'client_id', 'client_secret', 'host_user_id'], array_keys($credential->fresh()->credentials));

        Http::assertSent(fn (Request $sent): bool => $sent->url() === (string) config('b2b.zoom.oauth_url')
            && $sent->method() === 'POST'
            && $sent->data()['grant_type'] === 'account_credentials'
            && $sent->data()['account_id'] === 'account-1');
        Http::assertSent(fn (Request $sent): bool => $sent->method() === 'POST'
            && str_ends_with($sent->url(), '/users/evgeny%40example.test/meetings')
            && $sent->data()['tracking_fields'][0]['value'] === $request->externalKey
            && ! array_key_exists('start_url', $sent->data()));
    }
}
