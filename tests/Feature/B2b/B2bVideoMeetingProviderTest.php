<?php

namespace Tests\Feature\B2b;

use App\Modules\B2B\Domain\ValueObjects\ProviderOperationDeadline;
use App\Modules\B2B\Domain\ValueObjects\VideoMeetingIdentity;
use App\Modules\B2B\Domain\ValueObjects\VideoMeetingRequest;
use App\Modules\B2B\Infrastructure\Video\VideoMeetingException;
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

    public function test_zoom_adapter_uses_documented_fields_and_sends_the_agenda_correlation_marker(): void
    {
        $organization = $this->organization();
        $this->fakeZoom([
            'create' => [
                'id' => 123456,
                'uuid' => 'meeting-uuid',
                'join_url' => 'https://zoom.us/j/123456',
            ],
            'list' => [$this->meeting(123456, 'meeting-uuid', 'https://zoom.us/j/123456')],
            'get' => [
                ...$this->meeting(123456, 'meeting-uuid', 'https://zoom.us/j/123456'),
                'start_url' => 'https://us02web.zoom.us/s/123456',
            ],
        ]);
        $request = $this->request();
        $provider = app(ZoomVideoMeetingProvider::class);
        $deadline = ProviderOperationDeadline::fromNow(60);

        $created = $provider->createMeeting($organization, $request, $deadline);
        $found = $provider->findMeeting($organization, $request, ProviderOperationDeadline::fromNow(60));
        $provider->updateMeeting($organization, $created->identity, $request, ProviderOperationDeadline::fromNow(60));
        $hostUrl = $provider->obtainHostLaunchUrl(
            $organization,
            new VideoMeetingIdentity('123456', 'meeting-uuid'),
            ProviderOperationDeadline::fromNow(60),
        );
        $provider->cancelMeeting($organization, $created->identity, ProviderOperationDeadline::fromNow(60));

        self::assertSame('123456', $created->identity->meetingId);
        self::assertSame('meeting-uuid', $created->identity->meetingUuid);
        self::assertSame('https://zoom.us/j/123456', $created->joinUrl);
        self::assertNotNull($found);
        self::assertSame('123456', $found->identity->meetingId);
        self::assertSame('https://us02web.zoom.us/s/123456', $hostUrl);
        self::assertArrayNotHasKey('start_url', get_object_vars($created));

        Http::assertSent(fn (Request $sent): bool => $sent->method() === 'POST'
            && str_ends_with($sent->url(), '/users/evgeny%40example.test/meetings')
            && str_starts_with((string) $sent->data()['agenda'], 'CHUKLOV-B2B:'.$request->externalKey)
            && ! array_key_exists('tracking_fields', $sent->data()));
        Http::assertSent(fn (Request $sent): bool => $sent->method() === 'GET'
            && str_contains($sent->url(), '/users/evgeny%40example.test/meetings')
            && ! str_contains($sent->url(), 'tracking_fields')
            && ! str_contains($sent->url(), 'from=')
            && ! str_contains($sent->url(), 'to='));
    }

    public function test_multiple_matching_list_results_require_reconciliation(): void
    {
        $organization = $this->organization();
        $request = $this->request();
        $this->fakeZoom([
            'list' => [
                $this->meeting(1, 'uuid-1', 'https://zoom.us/j/1'),
                $this->meeting(2, 'uuid-2', 'https://zoom.us/j/2'),
            ],
        ]);

        try {
            app(ZoomVideoMeetingProvider::class)->findMeeting(
                $organization,
                $request,
                ProviderOperationDeadline::fromNow(60),
            );
            self::fail('Ambiguous meeting correlation was accepted.');
        } catch (VideoMeetingException $exception) {
            self::assertSame('zoom_meeting_ambiguous', $exception->safeCode);
            self::assertTrue($exception->requiresReconciliation);
        }
    }

    public function test_exhausted_pagination_requires_reconciliation_instead_of_claiming_absence(): void
    {
        $organization = $this->organization();
        $request = $this->request();
        config()->set('b2b.provider.list_max_pages', 1);
        $this->fakeZoom(['list' => [], 'next_page_token' => 'more']);

        try {
            app(ZoomVideoMeetingProvider::class)->findMeeting(
                $organization,
                $request,
                ProviderOperationDeadline::fromNow(60),
            );
            self::fail('Incomplete pagination was interpreted as an absent meeting.');
        } catch (VideoMeetingException $exception) {
            self::assertSame('zoom_find_incomplete', $exception->safeCode);
            self::assertTrue($exception->requiresReconciliation);
        }
    }

    public function test_deadline_exhaustion_prevents_a_remote_mutation(): void
    {
        $organization = $this->organization();
        $this->fakeZoom(['create' => ['id' => 1, 'uuid' => 'uuid-1', 'join_url' => 'https://zoom.us/j/1']]);
        $deadline = new ProviderOperationDeadline(CarbonImmutable::now('UTC')->subSecond());

        try {
            app(ZoomVideoMeetingProvider::class)->createMeeting($organization, $this->request(), $deadline);
            self::fail('A create was started after the provider deadline expired.');
        } catch (VideoMeetingException $exception) {
            self::assertSame('zoom_deadline_exhausted', $exception->safeCode);
        }

        Http::assertNotSent(fn (Request $sent): bool => str_ends_with($sent->url(), '/meetings'));
    }

    public function test_one_absolute_deadline_spans_reconciliation_and_blocks_a_late_create(): void
    {
        $organization = $this->organization();
        $base = CarbonImmutable::create(2026, 8, 31, 10, 0, 0, 'UTC');
        $oauthCalls = 0;
        $listCalls = 0;
        $createCalls = 0;
        CarbonImmutable::setTestNow($base);
        Http::fake(function (Request $request) use (&$oauthCalls, &$listCalls, &$createCalls, $base) {
            if ($request->url() === (string) config('b2b.zoom.oauth_url')) {
                $oauthCalls++;

                return Http::response(['access_token' => 'server-token'], 200);
            }

            if ($request->method() === 'GET' && str_contains($request->url(), '/users/')) {
                $listCalls++;
                CarbonImmutable::setTestNow($base->addSeconds(9));

                return Http::response(['meetings' => [], 'next_page_token' => null], 200);
            }

            if ($request->method() === 'POST' && str_ends_with($request->url(), '/meetings')) {
                $createCalls++;

                return Http::response(['id' => 1, 'uuid' => 'uuid-1', 'join_url' => 'https://zoom.us/j/1'], 201);
            }

            return Http::response([], 404);
        });

        try {
            $provider = app(ZoomVideoMeetingProvider::class);
            $deadline = new ProviderOperationDeadline($base->addSeconds(10), 2);

            self::assertNull($provider->findMeeting($organization, $this->request(), $deadline));

            try {
                $provider->createMeeting($organization, $this->request(), $deadline);
                self::fail('A create was started after the shared operation deadline expired.');
            } catch (VideoMeetingException $exception) {
                self::assertSame('zoom_deadline_exhausted', $exception->safeCode);
            }

            self::assertSame(1, $oauthCalls);
            self::assertSame(1, $listCalls);
            self::assertSame(0, $createCalls);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_pagination_stops_when_the_absolute_deadline_is_exhausted(): void
    {
        $organization = $this->organization();
        $base = CarbonImmutable::create(2026, 8, 31, 10, 0, 0, 'UTC');
        $listCalls = 0;
        CarbonImmutable::setTestNow($base);
        Http::fake(function (Request $request) use (&$listCalls, $base) {
            if ($request->url() === (string) config('b2b.zoom.oauth_url')) {
                return Http::response(['access_token' => 'server-token'], 200);
            }

            if ($request->method() === 'GET' && str_contains($request->url(), '/users/')) {
                $listCalls++;
                CarbonImmutable::setTestNow($base->addSeconds(9));

                return Http::response(['meetings' => [], 'next_page_token' => 'more'], 200);
            }

            return Http::response([], 404);
        });

        try {
            try {
                app(ZoomVideoMeetingProvider::class)->findMeeting(
                    $organization,
                    $this->request(),
                    new ProviderOperationDeadline($base->addSeconds(10), 2),
                );
                self::fail('Incomplete pagination was treated as a complete search.');
            } catch (VideoMeetingException $exception) {
                self::assertSame('zoom_find_incomplete', $exception->safeCode);
                self::assertTrue($exception->requiresReconciliation);
            }

            self::assertSame(1, $listCalls);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_host_launch_url_rejects_non_zoom_hosts_and_url_tricks(): void
    {
        $organization = $this->organization();
        $identity = new VideoMeetingIdentity('123456', 'meeting-uuid');

        foreach ([
            'https://attacker.example/start/123456',
            'https://zoom.us.attacker.example/start/123456',
            'https://attacker:secret@zoom.us/start/123456',
            'https://attacker@zoom.us/start/123456',
            'http://zoom.us/start/123456',
        ] as $hostUrl) {
            Http::fake(function (Request $request) use ($hostUrl) {
                if ($request->url() === (string) config('b2b.zoom.oauth_url')) {
                    return Http::response(['access_token' => 'server-token'], 200);
                }

                return Http::response(['start_url' => $hostUrl], 200);
            });

            try {
                app(ZoomVideoMeetingProvider::class)->obtainHostLaunchUrl(
                    $organization,
                    $identity,
                    ProviderOperationDeadline::fromNow(60),
                );
                self::fail('An invalid Zoom host URL was accepted: '.$hostUrl);
            } catch (VideoMeetingException $exception) {
                self::assertSame('zoom_host_url_invalid', $exception->safeCode);
            }
        }
    }

    private function organization(): Organization
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

        return $organization;
    }

    private function request(): VideoMeetingRequest
    {
        return new VideoMeetingRequest(
            externalKey: 'opaque-provider-key',
            startsAt: CarbonImmutable::create(2026, 8, 31, 10, 0, 0, 'UTC'),
            durationMinutes: 45,
            timezone: 'Asia/Almaty',
            topic: 'Chuklov B2B sales call',
        );
    }

    /** @return array<string, mixed> */
    private function meeting(int $id, string $uuid, string $joinUrl): array
    {
        return [
            'id' => $id,
            'uuid' => $uuid,
            'agenda' => 'CHUKLOV-B2B:opaque-provider-key Chuklov B2B sales call',
            'start_time' => '2026-08-31T10:00:00Z',
            'duration' => 45,
            'timezone' => 'Asia/Almaty',
            'join_url' => $joinUrl,
            'type' => 2,
        ];
    }

    /** @param array<string, mixed> $responses */
    private function fakeZoom(array $responses): void
    {
        Http::fake(function (Request $request) use ($responses) {
            if ($request->url() === (string) config('b2b.zoom.oauth_url')) {
                return Http::response(['access_token' => 'server-token'], 200);
            }

            if ($request->method() === 'POST' && str_ends_with($request->url(), '/meetings')) {
                return Http::response($responses['create'] ?? [], 201);
            }

            if ($request->method() === 'GET' && str_contains($request->url(), '/users/')) {
                return Http::response([
                    'meetings' => $responses['list'] ?? [],
                    'next_page_token' => $responses['next_page_token'] ?? null,
                ], 200);
            }

            if ($request->method() === 'GET' && str_ends_with($request->url(), '/meetings/123456')) {
                return Http::response($responses['get'] ?? [], 200);
            }

            if ($request->method() === 'PATCH' || $request->method() === 'DELETE') {
                return Http::response([], 204);
            }

            return Http::response([], 404);
        });
    }
}
