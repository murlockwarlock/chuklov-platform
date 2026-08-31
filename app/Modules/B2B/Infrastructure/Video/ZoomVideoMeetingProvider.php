<?php

namespace App\Modules\B2B\Infrastructure\Video;

use App\Modules\B2B\Domain\Contracts\VideoMeetingProvider;
use App\Modules\B2B\Domain\ValueObjects\ProviderOperationDeadline;
use App\Modules\B2B\Domain\ValueObjects\VideoMeetingIdentity;
use App\Modules\B2B\Domain\ValueObjects\VideoMeetingRequest;
use App\Modules\B2B\Domain\ValueObjects\VideoMeetingResult;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Domain\Enums\CredentialStatus;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class ZoomVideoMeetingProvider implements VideoMeetingProvider
{
    public function name(): string
    {
        return 'zoom';
    }

    public function createMeeting(
        Organization $organization,
        VideoMeetingRequest $request,
        ProviderOperationDeadline $deadline,
    ): VideoMeetingResult {
        $credentials = $this->credential($organization);
        $token = $this->accessToken($credentials, $deadline);

        try {
            $response = $this->api($token, $deadline)->post(
                $this->url('/users/'.rawurlencode((string) $credentials['host_user_id']).'/meetings'),
                $this->payload($request),
            );
        } catch (ConnectionException) {
            throw VideoMeetingException::retryable('zoom_create_connection', true);
        }

        if (! $response->successful()) {
            throw $this->responseException($response, 'zoom_create', true);
        }

        try {
            $result = $this->result($response);
            $this->assertCorrelatedMeeting($result, $request);
            if (! $result->matchesRequest($request)) {
                throw VideoMeetingException::reconciliationRequired('zoom_schedule_mismatch');
            }

            return $result;
        } catch (VideoMeetingException $exception) {
            throw VideoMeetingException::reconciliationRequired($exception->safeCode);
        }
    }

    public function updateMeeting(
        Organization $organization,
        VideoMeetingIdentity $identity,
        VideoMeetingRequest $request,
        ProviderOperationDeadline $deadline,
    ): void {
        $credentials = $this->credential($organization);
        $token = $this->accessToken($credentials, $deadline);

        if ($this->fetchExpectedMeeting(
            $this->api($token, $deadline),
            $identity,
            $request,
            'zoom_update',
        ) === null) {
            throw VideoMeetingException::reconciliationRequired('zoom_update_identity_missing');
        }

        try {
            $response = $this->api($token, $deadline)->patch(
                $this->url('/meetings/'.rawurlencode($identity->meetingId)),
                $this->payload($request),
            );
        } catch (ConnectionException) {
            throw VideoMeetingException::retryable('zoom_update_connection', true);
        }

        if (! $response->successful()) {
            if ($response->status() === 404) {
                throw VideoMeetingException::reconciliationRequired('zoom_update_404');
            }

            throw $this->responseException($response, 'zoom_update', true);
        }
    }

    public function cancelMeeting(
        Organization $organization,
        VideoMeetingIdentity $identity,
        VideoMeetingRequest $request,
        ProviderOperationDeadline $deadline,
    ): void {
        $credentials = $this->credential($organization);
        $token = $this->accessToken($credentials, $deadline);

        if ($this->fetchExpectedMeeting(
            $this->api($token, $deadline),
            $identity,
            $request,
            'zoom_cancel',
        ) === null) {
            return;
        }

        try {
            $response = $this->api($token, $deadline)->delete(
                $this->url('/meetings/'.rawurlencode($identity->meetingId)),
            );
        } catch (ConnectionException) {
            throw VideoMeetingException::retryable('zoom_cancel_connection', true);
        }

        if (! $response->successful() && $response->status() !== 404) {
            throw $this->responseException($response, 'zoom_cancel', true);
        }
    }

    public function obtainHostLaunchUrl(
        Organization $organization,
        VideoMeetingIdentity $identity,
        VideoMeetingRequest $request,
        ProviderOperationDeadline $deadline,
    ): string {
        $credentials = $this->credential($organization);
        $token = $this->accessToken($credentials, $deadline);

        $meeting = $this->fetchExpectedMeeting(
            $this->api($token, $deadline),
            $identity,
            $request,
            'zoom_host_url',
        );

        if ($meeting === null) {
            throw VideoMeetingException::permanent('zoom_host_url_404');
        }

        if (! $meeting['result']->matchesRequest($request)) {
            throw VideoMeetingException::reconciliationRequired('zoom_schedule_mismatch');
        }

        $startUrl = $meeting['response']->json('start_url');

        if (! is_string($startUrl) || ! $this->isAllowedHostUrl($startUrl)) {
            throw VideoMeetingException::permanent('zoom_host_url_invalid');
        }

        return $startUrl;
    }

    public function findMeeting(
        Organization $organization,
        VideoMeetingRequest $request,
        ProviderOperationDeadline $deadline,
    ): ?VideoMeetingResult {
        $credentials = $this->credential($organization);
        $token = $this->accessToken($credentials, $deadline);
        $matches = [];
        $nextPageToken = null;
        $maxPages = min(20, max(1, (int) config('b2b.provider.list_max_pages', 5)));

        for ($page = 0; $page < $maxPages; $page++) {
            try {
                $response = $this->api($token, $deadline)->get(
                    $this->url('/users/'.rawurlencode((string) $credentials['host_user_id']).'/meetings'),
                    [
                        'type' => 'scheduled',
                        'page_size' => min(300, max(1, (int) config('b2b.provider.list_page_size', 100))),
                        ...($nextPageToken === null ? [] : ['next_page_token' => $nextPageToken]),
                    ],
                );
            } catch (ConnectionException) {
                throw VideoMeetingException::retryable('zoom_find_connection');
            } catch (VideoMeetingException $exception) {
                if ($exception->safeCode === 'zoom_deadline_exhausted') {
                    throw VideoMeetingException::reconciliationRequired('zoom_find_incomplete');
                }

                throw $exception;
            }

            if (! $response->successful()) {
                throw $this->responseException($response, 'zoom_find', true);
            }

            $pageResponse = $this->listPageResponse($response);
            $matches = [...$matches, ...$this->matchingMeetings($pageResponse['meetings'], $request)];
            $nextPageToken = $pageResponse['next_page_token'];

            if ($nextPageToken === '') {
                $nextPageToken = null;
                break;
            }
        }

        if ($nextPageToken !== null) {
            throw VideoMeetingException::reconciliationRequired('zoom_find_incomplete');
        }

        if ($matches === []) {
            return null;
        }

        if (count($matches) > 1) {
            throw VideoMeetingException::reconciliationRequired('zoom_meeting_ambiguous');
        }

        $result = $this->resultFromMeeting($matches[0]);
        $this->assertCorrelatedMeeting($result, $request);

        return $result;
    }

    public function getMeeting(
        Organization $organization,
        VideoMeetingIdentity $identity,
        VideoMeetingRequest $request,
        ProviderOperationDeadline $deadline,
    ): ?VideoMeetingResult {
        $credentials = $this->credential($organization);
        $token = $this->accessToken($credentials, $deadline);

        $meeting = $this->fetchExpectedMeeting(
            $this->api($token, $deadline),
            $identity,
            $request,
            'zoom_get',
        );

        return $meeting['result'] ?? null;
    }

    /** @return array<string, string> */
    private function credential(Organization $organization): array
    {
        $credential = OrganizationCredential::query()
            ->where('organization_id', $organization->getKey())
            ->where('provider', 'zoom')
            ->where('credential_name', config('b2b.credential_name'))
            ->where('status', CredentialStatus::Active->value)
            ->first();

        if (! $credential instanceof OrganizationCredential) {
            throw VideoMeetingException::permanent('zoom_credentials_missing');
        }

        $values = [];
        foreach (['account_id', 'client_id', 'client_secret', 'host_user_id'] as $key) {
            $value = $credential->credentials[$key] ?? null;

            if (! is_string($value) || trim($value) === '') {
                throw VideoMeetingException::permanent('zoom_credentials_invalid');
            }

            $values[$key] = trim($value);
        }

        return $values;
    }

    /** @param array<string, string> $credentials */
    private function accessToken(array $credentials, ProviderOperationDeadline $deadline): string
    {
        try {
            $response = $this->request($deadline)
                ->asForm()
                ->withBasicAuth($credentials['client_id'], $credentials['client_secret'])
                ->post((string) config('b2b.zoom.oauth_url'), [
                    'grant_type' => 'account_credentials',
                    'account_id' => $credentials['account_id'],
                ]);
        } catch (ConnectionException) {
            throw VideoMeetingException::retryable('zoom_oauth_connection');
        }

        if (! $response->successful()) {
            throw $this->responseException($response, 'zoom_oauth', false);
        }

        $token = $response->json('access_token');

        if (! is_string($token) || trim($token) === '') {
            throw VideoMeetingException::permanent('zoom_oauth_token_missing');
        }

        return $token;
    }

    private function api(string $token, ProviderOperationDeadline $deadline): PendingRequest
    {
        return $this->request($deadline)->withToken($token);
    }

    private function request(ProviderOperationDeadline $deadline): PendingRequest
    {
        $timeout = $deadline->timeoutSeconds((int) config('b2b.zoom.timeout_seconds'));

        if ($timeout === null) {
            throw VideoMeetingException::retryable('zoom_deadline_exhausted');
        }

        return Http::acceptJson()
            ->withoutRedirecting()
            ->connectTimeout(min(3, $timeout))
            ->timeout($timeout);
    }

    private function url(string $path): string
    {
        return rtrim((string) config('b2b.zoom.api_base_url'), '/').$path;
    }

    /** @return array<string, mixed> */
    private function payload(VideoMeetingRequest $request): array
    {
        return [
            'type' => 2,
            'topic' => $request->topic,
            'agenda' => $this->agenda($request),
            'start_time' => $request->startsAt->utc()->format('Y-m-d\\TH:i:s\\Z'),
            'duration' => $request->durationMinutes,
            'timezone' => $request->timezone,
            'settings' => [
                'join_before_host' => false,
                'waiting_room' => true,
            ],
        ];
    }

    private function agenda(VideoMeetingRequest $request): string
    {
        $marker = $request->correlationMarker();
        $topic = trim($request->topic);

        return mb_substr($topic === '' ? $marker : $marker.' '.$topic, 0, 250);
    }

    /** @param array<string, mixed> $meeting */
    private function matches(array $meeting, VideoMeetingRequest $request): bool
    {
        $agenda = $meeting['agenda'] ?? null;

        return $request->matchesCorrelation(is_string($agenda) ? $agenda : null);
    }

    /** @param array<string, mixed> $meeting */
    private function resultFromMeeting(array $meeting): VideoMeetingResult
    {
        $id = $meeting['id'] ?? null;
        $joinUrl = $meeting['join_url'] ?? null;

        if ((! is_string($id) && ! is_int($id))
            || trim((string) $id) === ''
            || ! is_string($joinUrl)
            || ! str_starts_with($joinUrl, 'https://')) {
            throw VideoMeetingException::retryable('zoom_meeting_response_invalid');
        }

        $uuid = $meeting['uuid'] ?? null;
        $startsAt = null;
        if (is_string($meeting['start_time'] ?? null)) {
            try {
                $startsAt = CarbonImmutable::parse($meeting['start_time'])->utc();
            } catch (\Throwable) {
                throw VideoMeetingException::retryable('zoom_meeting_response_invalid');
            }
        }
        $duration = $meeting['duration'] ?? null;

        return new VideoMeetingResult(
            identity: new VideoMeetingIdentity(
                (string) $id,
                is_string($uuid) && trim($uuid) !== '' ? $uuid : null,
            ),
            joinUrl: $joinUrl,
            synchronizedAt: CarbonImmutable::now('UTC'),
            startsAt: $startsAt,
            durationMinutes: is_int($duration) || (is_string($duration) && ctype_digit($duration))
                ? (int) $duration
                : null,
            timezone: is_string($meeting['timezone'] ?? null) ? $meeting['timezone'] : null,
            agenda: is_string($meeting['agenda'] ?? null) ? $meeting['agenda'] : null,
        );
    }

    private function result(Response $response): VideoMeetingResult
    {
        return $this->resultFromMeeting((array) $response->json());
    }

    /** @return array{response: Response, result: VideoMeetingResult}|null */
    private function fetchExpectedMeeting(
        PendingRequest $api,
        VideoMeetingIdentity $identity,
        VideoMeetingRequest $request,
        string $operation,
    ): ?array {
        try {
            $response = $api->get(
                $this->url('/meetings/'.rawurlencode($identity->meetingId)),
            );
        } catch (ConnectionException) {
            throw VideoMeetingException::retryable($operation.'_connection', true);
        }

        if ($response->status() === 404) {
            return null;
        }

        if (! $response->successful()) {
            throw $this->responseException($response, $operation, true);
        }

        $result = $this->result($response);
        if (! $result->matchesIdentity($identity)) {
            throw VideoMeetingException::reconciliationRequired('zoom_meeting_identity_mismatch');
        }
        if (! $result->matchesCorrelation($request)) {
            throw VideoMeetingException::reconciliationRequired('zoom_meeting_correlation_mismatch');
        }

        return ['response' => $response, 'result' => $result];
    }

    private function assertCorrelatedMeeting(VideoMeetingResult $result, VideoMeetingRequest $request): void
    {
        if (! $result->matchesCorrelation($request)) {
            throw VideoMeetingException::reconciliationRequired('zoom_meeting_correlation_mismatch');
        }
    }

    /** @return array{meetings: list<array<string, mixed>>, next_page_token: string} */
    private function listPageResponse(Response $response): array
    {
        $envelope = $response->json();
        $decodedObject = $response->object();

        if (! is_object($decodedObject)
            || ! is_array($envelope)
            || array_is_list($envelope)
            || ! property_exists($decodedObject, 'meetings')
            || ! is_array($decodedObject->meetings)
            || ! array_is_list($decodedObject->meetings)
            || ! array_key_exists('meetings', $envelope)
            || ! is_array($envelope['meetings'])
            || ! array_is_list($envelope['meetings'])
            || ! property_exists($decodedObject, 'next_page_token')
            || ! is_string($decodedObject->next_page_token)
            || ! array_key_exists('next_page_token', $envelope)
            || ! is_string($envelope['next_page_token'])) {
            throw VideoMeetingException::reconciliationRequired('zoom_find_incomplete');
        }

        foreach ($envelope['meetings'] as $meeting) {
            if (! is_array($meeting)) {
                throw VideoMeetingException::reconciliationRequired('zoom_find_incomplete');
            }
        }

        return [
            'meetings' => $envelope['meetings'],
            'next_page_token' => $envelope['next_page_token'],
        ];
    }

    /** @param array<int, mixed> $meetings
     * @return list<array<string, mixed>>
     */
    private function matchingMeetings(array $meetings, VideoMeetingRequest $request): array
    {
        $matches = [];

        foreach ($meetings as $meeting) {
            if (is_array($meeting) && $this->matches($meeting, $request)) {
                $matches[] = $meeting;
            }
        }

        return $matches;
    }

    private function isAllowedHostUrl(string $url): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! isset($parts['host'])
            || array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)
            || array_key_exists('port', $parts)) {
            return false;
        }

        $host = strtolower((string) $parts['host']);

        return $host === 'zoom.us' || preg_match('/^[a-z0-9-]+\\.zoom\\.us$/', $host) === 1;
    }

    private function responseException(Response $response, string $operation, bool $unknown): VideoMeetingException
    {
        $status = $response->status();
        $retryable = $status === 408 || $status === 429 || $status >= 500;

        if ($retryable) {
            return VideoMeetingException::retryable($operation.'_'.$status, $unknown);
        }

        return VideoMeetingException::permanent($operation.'_'.$status);
    }
}
