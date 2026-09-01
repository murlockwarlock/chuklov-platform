<?php

namespace Tests\Feature\B2b;

use App\Modules\B2B\Domain\ValueObjects\ProviderOperationDeadline;
use App\Modules\B2B\Domain\ValueObjects\VideoMeetingIdentity;
use App\Modules\B2B\Domain\ValueObjects\VideoMeetingRequest;
use App\Modules\B2B\Domain\ValueObjects\VideoMeetingResult;
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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class B2bVideoMeetingProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_zoom_adapter_uses_documented_fields_and_sends_the_agenda_correlation_marker(): void
    {
        $organization = $this->organization();
        $this->fakeZoom([
            'create' => [
                ...$this->meeting(123456, 'meeting-uuid', 'https://zoom.us/j/123456'),
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
            $request,
            ProviderOperationDeadline::fromNow(60),
        );
        $provider->cancelMeeting(
            $organization,
            $created->identity,
            $request,
            ProviderOperationDeadline::fromNow(60),
        );

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

    #[DataProvider('acceptedStartTimes')]
    public function test_get_meeting_accepts_strict_zoom_start_time_forms(
        string $startTime,
        string $expectedUtc,
    ): void {
        $organization = $this->organization();
        $meeting = $this->meeting(123456, 'meeting-uuid', 'https://zoom.us/j/123456');
        $meeting['start_time'] = $startTime;
        $this->fakeZoom(['get' => $meeting]);

        $result = app(ZoomVideoMeetingProvider::class)->getMeeting(
            $organization,
            new VideoMeetingIdentity('123456', 'meeting-uuid'),
            $this->request(),
            ProviderOperationDeadline::fromNow(60),
        );

        self::assertInstanceOf(VideoMeetingResult::class, $result);
        self::assertInstanceOf(CarbonImmutable::class, $result->startsAt);
        self::assertSame('UTC', $result->startsAt->getTimezone()->getName());
        self::assertSame($expectedUtc, $result->startsAt->format('Y-m-d\TH:i:s.u\Z'));
    }

    /** @return array<string, array{string, string}> */
    public static function acceptedStartTimes(): array
    {
        return [
            'uppercase T and Z' => ['2026-08-31T10:00:00Z', '2026-08-31T10:00:00.000000Z'],
            'lowercase t' => ['2026-08-31t10:00:00Z', '2026-08-31T10:00:00.000000Z'],
            'lowercase z' => ['2026-08-31T10:00:00z', '2026-08-31T10:00:00.000000Z'],
            'one fractional digit' => ['2026-08-31T10:00:00.1Z', '2026-08-31T10:00:00.100000Z'],
            'six fractional digits' => ['2026-08-31T10:00:00.123456Z', '2026-08-31T10:00:00.123456Z'],
            'positive numeric offset' => ['2026-08-31T15:00:00+05:00', '2026-08-31T10:00:00.000000Z'],
            'negative numeric offset' => ['2026-08-31T03:30:00-06:30', '2026-08-31T10:00:00.000000Z'],
            'explicit zero numeric offset' => ['2026-08-31T10:00:00+00:00', '2026-08-31T10:00:00.000000Z'],
        ];
    }

    #[DataProvider('rejectedStartTimes')]
    public function test_get_meeting_rejects_non_canonical_zoom_start_times(string $startTime): void
    {
        $organization = $this->organization();
        $meeting = $this->meeting(123456, 'meeting-uuid', 'https://zoom.us/j/123456');
        $meeting['start_time'] = $startTime;
        $this->fakeZoom(['get' => $meeting]);

        try {
            app(ZoomVideoMeetingProvider::class)->getMeeting(
                $organization,
                new VideoMeetingIdentity('123456', 'meeting-uuid'),
                $this->request(),
                ProviderOperationDeadline::fromNow(60),
            );
            self::fail('A non-canonical Zoom start_time was accepted: '.var_export($startTime, true));
        } catch (VideoMeetingException $exception) {
            self::assertSame('zoom_meeting_response_invalid', $exception->safeCode);
            self::assertTrue($exception->requiresReconciliation);
        }
    }

    /** @return array<string, array{string}> */
    public static function rejectedStartTimes(): array
    {
        return [
            'missing timezone' => ['2026-08-31T10:00:00'],
            'space separator' => ['2026-08-31 10:00:00Z'],
            'date only' => ['2026-08-31'],
            'missing seconds' => ['2026-08-31T10:00Z'],
            'empty fraction' => ['2026-08-31T10:00:00.Z'],
            'fraction longer than six digits' => ['2026-08-31T10:00:00.1234567Z'],
            'compact numeric offset' => ['2026-08-31T10:00:00+0500'],
            'offset without minutes' => ['2026-08-31T10:00:00+05'],
            'offset hour out of range' => ['2026-08-31T10:00:00+24:00'],
            'offset minute out of range' => ['2026-08-31T10:00:00+05:60'],
            'negative zero offset' => ['2026-08-31T10:00:00-00:00'],
            'invalid calendar date' => ['2026-02-30T10:00:00Z'],
            'non-leap February 29' => ['2025-02-29T10:00:00Z'],
            'hour out of range' => ['2026-08-31T24:00:00Z'],
            'minute out of range' => ['2026-08-31T10:60:00Z'],
            'second out of range' => ['2026-08-31T10:00:60Z'],
            'leading whitespace' => [' 2026-08-31T10:00:00Z'],
            'trailing whitespace' => ['2026-08-31T10:00:00Z '],
            'embedded newline' => ["2026-08-31T10:00:\n00Z"],
            'embedded control character' => ["2026-08-31T10:00:\0000Z"],
            'named timezone' => ['2026-08-31T10:00:00Europe/Moscow'],
            'timezone abbreviation' => ['2026-08-31T10:00:00UTC'],
        ];
    }

    public function test_create_meeting_rejects_a_malformed_start_time_as_reconciliation_required(): void
    {
        $organization = $this->organization();
        $meeting = $this->meeting(123456, 'meeting-uuid', 'https://zoom.us/j/123456');
        $meeting['start_time'] = '2026-08-31T10:00:00';
        $this->fakeZoom(['create' => $meeting]);

        try {
            app(ZoomVideoMeetingProvider::class)->createMeeting(
                $organization,
                $this->request(),
                ProviderOperationDeadline::fromNow(60),
            );
            self::fail('A malformed CREATE response returned a VideoMeetingResult.');
        } catch (VideoMeetingException $exception) {
            self::assertSame('zoom_meeting_response_invalid', $exception->safeCode);
            self::assertTrue($exception->requiresReconciliation);
        }

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $sent): bool => $sent->method() === 'POST'
            && str_ends_with($sent->url(), '/meetings'));
    }

    public function test_get_meeting_rejects_a_malformed_start_time_as_reconciliation_required(): void
    {
        $organization = $this->organization();
        $meeting = $this->meeting(123456, 'meeting-uuid', 'https://zoom.us/j/123456');
        $meeting['start_time'] = '2026-08-31T10:00:00';
        $this->fakeZoom(['get' => $meeting]);

        try {
            app(ZoomVideoMeetingProvider::class)->getMeeting(
                $organization,
                new VideoMeetingIdentity('123456', 'meeting-uuid'),
                $this->request(),
                ProviderOperationDeadline::fromNow(60),
            );
            self::fail('A malformed GET response returned a VideoMeetingResult.');
        } catch (VideoMeetingException $exception) {
            self::assertSame('zoom_meeting_response_invalid', $exception->safeCode);
            self::assertTrue($exception->requiresReconciliation);
        }
    }

    public function test_correlated_list_meeting_rejects_a_malformed_start_time_without_creating(): void
    {
        $organization = $this->organization();
        $meeting = $this->meeting(123456, 'meeting-uuid', 'https://zoom.us/j/123456');
        $meeting['start_time'] = '2026-08-31T10:00:00';
        $this->fakeZoom(['list' => [$meeting]]);

        try {
            app(ZoomVideoMeetingProvider::class)->findMeeting(
                $organization,
                $this->request(),
                ProviderOperationDeadline::fromNow(60),
            );
            self::fail('A correlated malformed LIST response was treated as absence.');
        } catch (VideoMeetingException $exception) {
            self::assertSame('zoom_find_incomplete', $exception->safeCode);
            self::assertTrue($exception->requiresReconciliation);
        }

        Http::assertNotSent(fn (Request $sent): bool => $sent->method() === 'POST'
            && str_ends_with($sent->url(), '/meetings'));
    }

    public function test_create_rejects_a_remote_schedule_mismatch(): void
    {
        $organization = $this->organization();
        $this->fakeZoom([
            'create' => [
                ...$this->meeting(123456, 'meeting-uuid', 'https://zoom.us/j/123456'),
                'start_time' => '2026-08-31T11:00:00Z',
            ],
        ]);

        try {
            app(ZoomVideoMeetingProvider::class)->createMeeting(
                $organization,
                $this->request(),
                ProviderOperationDeadline::fromNow(60),
            );
            self::fail('A create response with a mismatched schedule was accepted.');
        } catch (VideoMeetingException $exception) {
            self::assertSame('zoom_schedule_mismatch', $exception->safeCode);
            self::assertTrue($exception->requiresReconciliation);
        }
    }

    public function test_host_launch_rejects_a_remote_schedule_mismatch(): void
    {
        $organization = $this->organization();
        $this->fakeZoom([
            'get' => [
                ...$this->meeting(123456, 'meeting-uuid', 'https://zoom.us/j/123456'),
                'duration' => 30,
                'start_url' => 'https://us02web.zoom.us/start/123456',
            ],
        ]);

        try {
            app(ZoomVideoMeetingProvider::class)->obtainHostLaunchUrl(
                $organization,
                new VideoMeetingIdentity('123456', 'meeting-uuid'),
                $this->request(),
                ProviderOperationDeadline::fromNow(60),
            );
            self::fail('A host URL was returned for a remote schedule mismatch.');
        } catch (VideoMeetingException $exception) {
            self::assertSame('zoom_schedule_mismatch', $exception->safeCode);
            self::assertTrue($exception->requiresReconciliation);
        }

        Http::assertNotSent(fn (Request $sent): bool => $sent->method() === 'PATCH'
            || $sent->method() === 'DELETE');
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

    #[DataProvider('malformedListResponses')]
    public function test_malformed_zoom_list_responses_require_reconciliation(mixed $listResponse): void
    {
        $organization = $this->organization();
        $this->fakeZoom(['list_response' => $listResponse]);

        try {
            app(ZoomVideoMeetingProvider::class)->findMeeting(
                $organization,
                $this->request(),
                ProviderOperationDeadline::fromNow(60),
            );
            self::fail('Malformed Zoom list evidence was accepted.');
        } catch (VideoMeetingException $exception) {
            self::assertSame('zoom_find_incomplete', $exception->safeCode);
            self::assertTrue($exception->requiresReconciliation);
        }

        Http::assertNotSent(fn (Request $sent): bool => $sent->method() === 'POST'
            && str_ends_with($sent->url(), '/meetings'));
    }

    /** @return array<string, array{mixed}> */
    public static function malformedListResponses(): array
    {
        return [
            'top-level array' => [[]],
            'missing meetings' => [['next_page_token' => '']],
            'null meetings' => [['meetings' => null, 'next_page_token' => '']],
            'scalar meetings' => [['meetings' => 'not-an-array', 'next_page_token' => '']],
            'object meetings' => [['meetings' => (object) ['unexpected' => []], 'next_page_token' => '']],
            'non-array meeting entry' => [['meetings' => ['not-an-array'], 'next_page_token' => '']],
            'missing agenda' => [['meetings' => [['id' => 123456]], 'next_page_token' => '']],
            'invalid agenda type' => [['meetings' => [['id' => 123456, 'agenda' => []]], 'next_page_token' => '']],
            'missing next page token' => [['meetings' => []]],
            'null next page token' => [['meetings' => [], 'next_page_token' => null]],
            'integer next page token' => [['meetings' => [], 'next_page_token' => 123]],
            'boolean next page token' => [['meetings' => [], 'next_page_token' => false]],
            'array next page token' => [['meetings' => [], 'next_page_token' => []]],
            'object next page token' => [['meetings' => [], 'next_page_token' => (object) ['token' => 'invalid']]],
        ];
    }

    public function test_empty_array_shaped_zoom_list_entry_requires_reconciliation(): void
    {
        $organization = $this->organization();
        $this->fakeZoom([
            'list_response' => [
                'meetings' => [[]],
                'next_page_token' => '',
            ],
        ]);

        try {
            app(ZoomVideoMeetingProvider::class)->findMeeting(
                $organization,
                $this->request(),
                ProviderOperationDeadline::fromNow(60),
            );
            self::fail('An empty array-shaped meeting entry was treated as absence.');
        } catch (VideoMeetingException $exception) {
            self::assertSame('zoom_find_incomplete', $exception->safeCode);
            self::assertTrue($exception->requiresReconciliation);
        }

        Http::assertNotSent(fn (Request $sent): bool => $sent->method() === 'POST'
            && str_ends_with($sent->url(), '/meetings'));
    }

    #[DataProvider('invalidMeetingIds')]
    public function test_zoom_list_entry_with_an_invalid_meeting_id_requires_reconciliation(mixed $id): void
    {
        $organization = $this->organization();
        $request = $this->request();
        $this->fakeZoom([
            'list_response' => [
                'meetings' => [[
                    'id' => $id,
                    'agenda' => $request->correlationMarker(),
                ]],
                'next_page_token' => '',
            ],
        ]);

        try {
            app(ZoomVideoMeetingProvider::class)->findMeeting(
                $organization,
                $this->request(),
                ProviderOperationDeadline::fromNow(60),
            );
            self::fail('A meeting entry with an invalid identity was treated as absence.');
        } catch (VideoMeetingException $exception) {
            self::assertSame('zoom_find_incomplete', $exception->safeCode);
            self::assertTrue($exception->requiresReconciliation);
        }

        Http::assertNotSent(fn (Request $sent): bool => $sent->method() === 'POST'
            && str_ends_with($sent->url(), '/meetings'));
    }

    /** @return array<string, array{mixed}> */
    public static function invalidMeetingIds(): array
    {
        return [
            'array' => [['invalid-id']],
            'garbage string' => ['garbage'],
            'numeric string' => ['123456'],
            'zero' => [0],
            'negative integer' => [-123],
            'float' => [12.3],
            'null' => [null],
            'boolean' => [true],
        ];
    }

    #[DataProvider('invalidParticipantJoinUrls')]
    public function test_correlation_matching_zoom_list_entry_with_an_invalid_join_url_requires_reconciliation(mixed $joinUrl): void
    {
        $organization = $this->organization();
        $request = $this->request();
        $this->fakeZoom([
            'list_response' => [
                'meetings' => [[
                    'id' => 123456,
                    'agenda' => $request->correlationMarker(),
                    'join_url' => $joinUrl,
                ]],
                'next_page_token' => '',
            ],
        ]);

        try {
            app(ZoomVideoMeetingProvider::class)->findMeeting(
                $organization,
                $request,
                ProviderOperationDeadline::fromNow(60),
            );
            self::fail('A malformed correlated meeting entry was accepted.');
        } catch (VideoMeetingException $exception) {
            self::assertSame('zoom_find_incomplete', $exception->safeCode);
            self::assertTrue($exception->requiresReconciliation);
        }

        Http::assertNotSent(fn (Request $sent): bool => $sent->method() === 'POST'
            && str_ends_with($sent->url(), '/meetings'));
    }

    /** @return array<string, array{mixed}> */
    public static function invalidParticipantJoinUrls(): array
    {
        return [
            'null' => [null],
            'scheme only' => ['https://'],
            'hostless path' => ['https:///join/123456'],
            'hostless port' => ['https://:443/j/123456'],
            'http scheme' => ['http://zoom.us/j/123456'],
            'userinfo' => ['https://user:pass@zoom.us/j/123456'],
            'invalid port' => ['https://zoom.us:bad/j/123456'],
        ];
    }

    public function test_correlated_zoom_list_meeting_with_malformed_schedule_requires_reconciliation(): void
    {
        $organization = $this->organization();
        $meeting = $this->meeting(123456, 'meeting-uuid', 'https://zoom.us/j/123456');
        $meeting['duration'] = '45';
        $this->fakeZoom([
            'list' => [$meeting],
        ]);

        try {
            app(ZoomVideoMeetingProvider::class)->findMeeting(
                $organization,
                $this->request(),
                ProviderOperationDeadline::fromNow(60),
            );
            self::fail('Malformed correlated schedule data was accepted.');
        } catch (VideoMeetingException $exception) {
            self::assertSame('zoom_find_incomplete', $exception->safeCode);
            self::assertTrue($exception->requiresReconciliation);
        }
    }

    public function test_credible_unrelated_zoom_list_entry_without_optional_fields_remains_a_non_match(): void
    {
        $organization = $this->organization();
        $request = $this->request();
        $this->fakeZoom([
            'list_response' => [
                'meetings' => [[
                    'id' => 654321,
                    'agenda' => 'Unrelated Zoom meeting',
                ]],
                'next_page_token' => '',
            ],
            'create' => $this->meeting(123456, 'meeting-uuid', 'https://zoom.us/j/123456'),
        ]);
        $provider = app(ZoomVideoMeetingProvider::class);

        self::assertNull($provider->findMeeting(
            $organization,
            $request,
            ProviderOperationDeadline::fromNow(60),
        ));

        $created = $provider->createMeeting(
            $organization,
            $request,
            ProviderOperationDeadline::fromNow(60),
        );

        self::assertSame('123456', $created->identity->meetingId);
    }

    #[DataProvider('validParticipantJoinUrls')]
    public function test_valid_correlated_zoom_list_meeting_is_still_adopted(string $joinUrl): void
    {
        $organization = $this->organization();
        $this->fakeZoom([
            'list' => [$this->meeting(123456, 'meeting-uuid', $joinUrl)],
        ]);

        $found = app(ZoomVideoMeetingProvider::class)->findMeeting(
            $organization,
            $this->request(),
            ProviderOperationDeadline::fromNow(60),
        );

        self::assertInstanceOf(VideoMeetingResult::class, $found);
        self::assertSame('123456', $found->identity->meetingId);
        self::assertSame($joinUrl, $found->joinUrl);
    }

    /** @return array<string, array{string}> */
    public static function validParticipantJoinUrls(): array
    {
        return [
            'zoom domain' => ['https://zoom.us/j/123456'],
            'vanity subdomain' => ['https://company.zoom.us/meeting/123456'],
            'query string' => ['https://zoom.us/j/123456?pwd=passcode'],
            'valid port' => ['https://zoom.us:443/j/123456'],
        ];
    }

    public function test_empty_zoom_list_with_terminal_token_allows_create(): void
    {
        $organization = $this->organization();
        $this->fakeZoom([
            'list' => [],
            'next_page_token' => '',
            'create' => $this->meeting(123456, 'meeting-uuid', 'https://zoom.us/j/123456'),
        ]);
        $provider = app(ZoomVideoMeetingProvider::class);

        self::assertNull($provider->findMeeting(
            $organization,
            $this->request(),
            ProviderOperationDeadline::fromNow(60),
        ));

        $created = $provider->createMeeting(
            $organization,
            $this->request(),
            ProviderOperationDeadline::fromNow(60),
        );

        self::assertSame('123456', $created->identity->meetingId);
        Http::assertSent(fn (Request $sent): bool => $sent->method() === 'POST'
            && str_ends_with($sent->url(), '/meetings'));
    }

    public function test_valid_non_empty_page_token_continues_to_the_next_page(): void
    {
        $organization = $this->organization();
        $listCalls = 0;
        Http::fake(function (Request $request) use (&$listCalls) {
            if ($request->url() === (string) config('b2b.zoom.oauth_url')) {
                return Http::response(['access_token' => 'server-token'], 200);
            }

            if ($request->method() === 'GET' && str_contains($request->url(), '/users/')) {
                $listCalls++;

                return $listCalls === 1
                    ? Http::response(['meetings' => [], 'next_page_token' => 'page-2'], 200)
                    : Http::response([
                        'meetings' => [$this->meeting(123456, 'meeting-uuid', 'https://zoom.us/j/123456')],
                        'next_page_token' => '',
                    ], 200);
            }

            return Http::response([], 404);
        });

        $found = app(ZoomVideoMeetingProvider::class)->findMeeting(
            $organization,
            $this->request(),
            ProviderOperationDeadline::fromNow(60),
        );

        self::assertInstanceOf(VideoMeetingResult::class, $found);
        self::assertSame('123456', $found->identity->meetingId);
        self::assertSame(2, $listCalls);
    }

    public function test_matching_meeting_with_a_malformed_page_token_requires_reconciliation_without_adoption(): void
    {
        $organization = $this->organization();
        $this->fakeZoom([
            'list' => [$this->meeting(123456, 'meeting-uuid', 'https://zoom.us/j/123456')],
            'next_page_token' => null,
        ]);

        try {
            app(ZoomVideoMeetingProvider::class)->findMeeting(
                $organization,
                $this->request(),
                ProviderOperationDeadline::fromNow(60),
            );
            self::fail('A matching meeting on an incomplete page was adopted.');
        } catch (VideoMeetingException $exception) {
            self::assertSame('zoom_find_incomplete', $exception->safeCode);
            self::assertTrue($exception->requiresReconciliation);
        }

        Http::assertNotSent(fn (Request $sent): bool => $sent->method() === 'POST'
            && str_ends_with($sent->url(), '/meetings'));
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

    public function test_nearly_exhausted_deadline_bounds_the_zoom_request_budget(): void
    {
        $organization = $this->organization();
        $base = CarbonImmutable::create(2026, 8, 31, 10, 0, 0, 'UTC');
        CarbonImmutable::setTestNow($base);
        $deadline = new ProviderOperationDeadline($base->addSeconds(5), 1);
        self::assertSame(4, $deadline->timeoutSeconds(30));
        $this->fakeZoom([
            'create' => $this->meeting(1, 'uuid-1', 'https://zoom.us/j/1'),
        ]);

        try {
            app(ZoomVideoMeetingProvider::class)->createMeeting($organization, $this->request(), $deadline);
        } finally {
            CarbonImmutable::setTestNow();
        }

        Http::assertSentCount(2);
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

                return Http::response(['meetings' => [], 'next_page_token' => ''], 200);
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

                return Http::response([
                    ...$this->meeting(123456, 'meeting-uuid', 'https://zoom.us/j/123456'),
                    'start_url' => $hostUrl,
                ], 200);
            });

            try {
                app(ZoomVideoMeetingProvider::class)->obtainHostLaunchUrl(
                    $organization,
                    $identity,
                    $this->request(),
                    ProviderOperationDeadline::fromNow(60),
                );
                self::fail('An invalid Zoom host URL was accepted: '.$hostUrl);
            } catch (VideoMeetingException $exception) {
                self::assertSame('zoom_host_url_invalid', $exception->safeCode);
            }
        }
    }

    public function test_get_meeting_rejects_a_remote_meeting_id_mismatch(): void
    {
        $organization = $this->organization();
        $remote = $this->meeting(654321, 'meeting-uuid', 'https://zoom.us/j/654321');
        $this->fakeZoom(['get' => $remote]);

        try {
            app(ZoomVideoMeetingProvider::class)->getMeeting(
                $organization,
                new VideoMeetingIdentity('123456', 'meeting-uuid'),
                $this->request(),
                ProviderOperationDeadline::fromNow(60),
            );
            self::fail('A remote meeting with a different ID was accepted.');
        } catch (VideoMeetingException $exception) {
            self::assertSame('zoom_meeting_identity_mismatch', $exception->safeCode);
            self::assertTrue($exception->requiresReconciliation);
        }
    }

    public function test_get_meeting_rejects_a_malformed_join_url(): void
    {
        $organization = $this->organization();
        $remote = $this->meeting(123456, 'meeting-uuid', 'https://');
        $this->fakeZoom(['get' => $remote]);

        try {
            app(ZoomVideoMeetingProvider::class)->getMeeting(
                $organization,
                new VideoMeetingIdentity('123456', 'meeting-uuid'),
                $this->request(),
                ProviderOperationDeadline::fromNow(60),
            );
            self::fail('A remote meeting with a malformed join URL was accepted.');
        } catch (VideoMeetingException $exception) {
            self::assertSame('zoom_meeting_response_invalid', $exception->safeCode);
            self::assertTrue($exception->requiresReconciliation);
        }
    }

    public function test_get_meeting_rejects_a_remote_meeting_uuid_mismatch(): void
    {
        $organization = $this->organization();
        $this->fakeZoom([
            'get' => $this->meeting(123456, 'different-uuid', 'https://zoom.us/j/123456'),
        ]);

        try {
            app(ZoomVideoMeetingProvider::class)->getMeeting(
                $organization,
                new VideoMeetingIdentity('123456', 'meeting-uuid'),
                $this->request(),
                ProviderOperationDeadline::fromNow(60),
            );
            self::fail('A remote meeting with a different UUID was accepted.');
        } catch (VideoMeetingException $exception) {
            self::assertSame('zoom_meeting_identity_mismatch', $exception->safeCode);
            self::assertTrue($exception->requiresReconciliation);
        }
    }

    public function test_get_meeting_rejects_a_missing_remote_uuid_when_one_is_persisted(): void
    {
        $organization = $this->organization();
        $remote = $this->meeting(123456, 'meeting-uuid', 'https://zoom.us/j/123456');
        $remote['uuid'] = null;
        $this->fakeZoom(['get' => $remote]);

        try {
            app(ZoomVideoMeetingProvider::class)->getMeeting(
                $organization,
                new VideoMeetingIdentity('123456', 'meeting-uuid'),
                $this->request(),
                ProviderOperationDeadline::fromNow(60),
            );
            self::fail('A missing remote UUID was accepted for a persisted UUID.');
        } catch (VideoMeetingException $exception) {
            self::assertSame('zoom_meeting_response_invalid', $exception->safeCode);
            self::assertTrue($exception->requiresReconciliation);
        }
    }

    public function test_get_meeting_rejects_a_remote_correlation_mismatch(): void
    {
        $organization = $this->organization();
        $remote = $this->meeting(123456, 'meeting-uuid', 'https://zoom.us/j/123456');
        $remote['agenda'] = 'CHUKLOV-B2B:another-provider-key';
        $this->fakeZoom(['get' => $remote]);

        try {
            app(ZoomVideoMeetingProvider::class)->getMeeting(
                $organization,
                new VideoMeetingIdentity('123456', 'meeting-uuid'),
                $this->request(),
                ProviderOperationDeadline::fromNow(60),
            );
            self::fail('A remote meeting with a different correlation marker was accepted.');
        } catch (VideoMeetingException $exception) {
            self::assertSame('zoom_meeting_correlation_mismatch', $exception->safeCode);
            self::assertTrue($exception->requiresReconciliation);
        }
    }

    public function test_update_does_not_patch_a_remote_identity_mismatch(): void
    {
        $organization = $this->organization();
        $this->fakeZoom([
            'get' => $this->meeting(654321, 'meeting-uuid', 'https://zoom.us/j/654321'),
        ]);

        try {
            app(ZoomVideoMeetingProvider::class)->updateMeeting(
                $organization,
                new VideoMeetingIdentity('123456', 'meeting-uuid'),
                $this->request(),
                ProviderOperationDeadline::fromNow(60),
            );
            self::fail('An update proceeded after a remote identity mismatch.');
        } catch (VideoMeetingException $exception) {
            self::assertSame('zoom_meeting_identity_mismatch', $exception->safeCode);
        }

        Http::assertNotSent(fn (Request $sent): bool => $sent->method() === 'PATCH');
    }

    public function test_update_does_not_patch_a_remote_uuid_mismatch(): void
    {
        $organization = $this->organization();
        $this->fakeZoom([
            'get' => $this->meeting(123456, 'different-uuid', 'https://zoom.us/j/123456'),
        ]);

        try {
            app(ZoomVideoMeetingProvider::class)->updateMeeting(
                $organization,
                new VideoMeetingIdentity('123456', 'meeting-uuid'),
                $this->request(),
                ProviderOperationDeadline::fromNow(60),
            );
            self::fail('An update proceeded after a remote UUID mismatch.');
        } catch (VideoMeetingException $exception) {
            self::assertSame('zoom_meeting_identity_mismatch', $exception->safeCode);
        }

        Http::assertNotSent(fn (Request $sent): bool => $sent->method() === 'PATCH');
    }

    public function test_update_does_not_patch_a_remote_correlation_mismatch(): void
    {
        $organization = $this->organization();
        $remote = $this->meeting(123456, 'meeting-uuid', 'https://zoom.us/j/123456');
        $remote['agenda'] = 'CHUKLOV-B2B:another-provider-key';
        $this->fakeZoom(['get' => $remote]);

        try {
            app(ZoomVideoMeetingProvider::class)->updateMeeting(
                $organization,
                new VideoMeetingIdentity('123456', 'meeting-uuid'),
                $this->request(),
                ProviderOperationDeadline::fromNow(60),
            );
            self::fail('An update proceeded after a remote correlation mismatch.');
        } catch (VideoMeetingException $exception) {
            self::assertSame('zoom_meeting_correlation_mismatch', $exception->safeCode);
        }

        Http::assertNotSent(fn (Request $sent): bool => $sent->method() === 'PATCH');
    }

    public function test_cancel_does_not_delete_a_remote_correlation_mismatch(): void
    {
        $organization = $this->organization();
        $remote = $this->meeting(123456, 'meeting-uuid', 'https://zoom.us/j/123456');
        $remote['agenda'] = 'CHUKLOV-B2B:another-provider-key';
        $this->fakeZoom(['get' => $remote]);

        try {
            app(ZoomVideoMeetingProvider::class)->cancelMeeting(
                $organization,
                new VideoMeetingIdentity('123456', 'meeting-uuid'),
                $this->request(),
                ProviderOperationDeadline::fromNow(60),
            );
            self::fail('A delete proceeded after a remote correlation mismatch.');
        } catch (VideoMeetingException $exception) {
            self::assertSame('zoom_meeting_correlation_mismatch', $exception->safeCode);
        }

        Http::assertNotSent(fn (Request $sent): bool => $sent->method() === 'DELETE');
    }

    public function test_cancel_does_not_delete_a_remote_id_mismatch(): void
    {
        $organization = $this->organization();
        $this->fakeZoom([
            'get' => $this->meeting(654321, 'meeting-uuid', 'https://zoom.us/j/654321'),
        ]);

        try {
            app(ZoomVideoMeetingProvider::class)->cancelMeeting(
                $organization,
                new VideoMeetingIdentity('123456', 'meeting-uuid'),
                $this->request(),
                ProviderOperationDeadline::fromNow(60),
            );
            self::fail('A delete proceeded after a remote ID mismatch.');
        } catch (VideoMeetingException $exception) {
            self::assertSame('zoom_meeting_identity_mismatch', $exception->safeCode);
        }

        Http::assertNotSent(fn (Request $sent): bool => $sent->method() === 'DELETE');
    }

    public function test_cancel_does_not_delete_a_remote_uuid_mismatch(): void
    {
        $organization = $this->organization();
        $this->fakeZoom([
            'get' => $this->meeting(123456, 'different-uuid', 'https://zoom.us/j/123456'),
        ]);

        try {
            app(ZoomVideoMeetingProvider::class)->cancelMeeting(
                $organization,
                new VideoMeetingIdentity('123456', 'meeting-uuid'),
                $this->request(),
                ProviderOperationDeadline::fromNow(60),
            );
            self::fail('A delete proceeded after a remote UUID mismatch.');
        } catch (VideoMeetingException $exception) {
            self::assertSame('zoom_meeting_identity_mismatch', $exception->safeCode);
        }

        Http::assertNotSent(fn (Request $sent): bool => $sent->method() === 'DELETE');
    }

    public function test_cancel_treats_an_authoritative_remote_404_as_absence_without_deleting(): void
    {
        $organization = $this->organization();
        Http::fake(function (Request $request) {
            if ($request->url() === (string) config('b2b.zoom.oauth_url')) {
                return Http::response(['access_token' => 'server-token'], 200);
            }

            if ($request->method() === 'GET' && str_ends_with($request->url(), '/meetings/123456')) {
                return Http::response([], 404);
            }

            if ($request->method() === 'DELETE') {
                return Http::response([], 204);
            }

            return Http::response([], 404);
        });

        app(ZoomVideoMeetingProvider::class)->cancelMeeting(
            $organization,
            new VideoMeetingIdentity('123456', 'meeting-uuid'),
            $this->request(),
            ProviderOperationDeadline::fromNow(60),
        );

        Http::assertNotSent(fn (Request $sent): bool => $sent->method() === 'DELETE');
    }

    public function test_host_launch_does_not_return_a_url_for_a_remote_identity_mismatch(): void
    {
        $organization = $this->organization();
        $remote = [
            ...$this->meeting(654321, 'meeting-uuid', 'https://zoom.us/j/654321'),
            'start_url' => 'https://us02web.zoom.us/start/654321',
        ];
        $this->fakeZoom(['get' => $remote]);

        try {
            app(ZoomVideoMeetingProvider::class)->obtainHostLaunchUrl(
                $organization,
                new VideoMeetingIdentity('123456', 'meeting-uuid'),
                $this->request(),
                ProviderOperationDeadline::fromNow(60),
            );
            self::fail('A host URL was returned for a remote identity mismatch.');
        } catch (VideoMeetingException $exception) {
            self::assertSame('zoom_meeting_identity_mismatch', $exception->safeCode);
        }
    }

    public function test_host_launch_does_not_return_a_url_for_a_remote_uuid_mismatch(): void
    {
        $organization = $this->organization();
        $remote = [
            ...$this->meeting(123456, 'different-uuid', 'https://zoom.us/j/123456'),
            'start_url' => 'https://us02web.zoom.us/start/123456',
        ];
        $this->fakeZoom(['get' => $remote]);

        try {
            app(ZoomVideoMeetingProvider::class)->obtainHostLaunchUrl(
                $organization,
                new VideoMeetingIdentity('123456', 'meeting-uuid'),
                $this->request(),
                ProviderOperationDeadline::fromNow(60),
            );
            self::fail('A host URL was returned for a remote UUID mismatch.');
        } catch (VideoMeetingException $exception) {
            self::assertSame('zoom_meeting_identity_mismatch', $exception->safeCode);
        }
    }

    public function test_host_launch_does_not_return_a_url_for_a_remote_correlation_mismatch(): void
    {
        $organization = $this->organization();
        $this->fakeZoom([
            'get' => [
                ...$this->meeting(123456, 'meeting-uuid', 'https://zoom.us/j/123456'),
                'agenda' => 'CHUKLOV-B2B:another-provider-key',
                'start_url' => 'https://us02web.zoom.us/start/123456',
            ],
        ]);

        try {
            app(ZoomVideoMeetingProvider::class)->obtainHostLaunchUrl(
                $organization,
                new VideoMeetingIdentity('123456', 'meeting-uuid'),
                $this->request(),
                ProviderOperationDeadline::fromNow(60),
            );
            self::fail('A host URL was returned for a remote correlation mismatch.');
        } catch (VideoMeetingException $exception) {
            self::assertSame('zoom_meeting_correlation_mismatch', $exception->safeCode);
        }
    }

    public function test_historical_null_uuid_still_requires_id_and_correlation(): void
    {
        $organization = $this->organization();
        $this->fakeZoom([
            'get' => $this->meeting(123456, 'remote-uuid', 'https://zoom.us/j/123456'),
        ]);

        $result = app(ZoomVideoMeetingProvider::class)->getMeeting(
            $organization,
            new VideoMeetingIdentity('123456'),
            $this->request(),
            ProviderOperationDeadline::fromNow(60),
        );

        self::assertInstanceOf(VideoMeetingResult::class, $result);
        self::assertSame('remote-uuid', $result->identity->meetingUuid);
        self::assertSame('123456', $result->identity->meetingId);
    }

    public function test_historical_null_uuid_still_rejects_an_id_mismatch(): void
    {
        $organization = $this->organization();
        $this->fakeZoom([
            'get' => $this->meeting(654321, 'remote-uuid', 'https://zoom.us/j/654321'),
        ]);

        try {
            app(ZoomVideoMeetingProvider::class)->getMeeting(
                $organization,
                new VideoMeetingIdentity('123456'),
                $this->request(),
                ProviderOperationDeadline::fromNow(60),
            );
            self::fail('A historical null-UUID ID mismatch was accepted.');
        } catch (VideoMeetingException $exception) {
            self::assertSame('zoom_meeting_identity_mismatch', $exception->safeCode);
        }
    }

    public function test_historical_null_uuid_still_rejects_a_correlation_mismatch(): void
    {
        $organization = $this->organization();
        $this->fakeZoom([
            'get' => [
                ...$this->meeting(123456, 'remote-uuid', 'https://zoom.us/j/123456'),
                'agenda' => 'CHUKLOV-B2B:another-provider-key',
            ],
        ]);

        try {
            app(ZoomVideoMeetingProvider::class)->getMeeting(
                $organization,
                new VideoMeetingIdentity('123456'),
                $this->request(),
                ProviderOperationDeadline::fromNow(60),
            );
            self::fail('A historical null-UUID correlation mismatch was accepted.');
        } catch (VideoMeetingException $exception) {
            self::assertSame('zoom_meeting_correlation_mismatch', $exception->safeCode);
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
                if (array_key_exists('list_response', $responses)) {
                    return Http::response($responses['list_response'], 200);
                }

                return Http::response([
                    'meetings' => $responses['list'] ?? [],
                    'next_page_token' => array_key_exists('next_page_token', $responses)
                        ? $responses['next_page_token']
                        : '',
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
