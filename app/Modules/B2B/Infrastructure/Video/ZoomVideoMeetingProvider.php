<?php

namespace App\Modules\B2B\Infrastructure\Video;

use App\Modules\B2B\Domain\Contracts\VideoMeetingProvider;
use App\Modules\B2B\Domain\ValueObjects\ProviderAccountAffinity;
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
        $credentials = $this->credential(
            organization: $organization,
            expectedAffinity: $request->providerAccountAffinity,
            requiresAffinity: true,
        );
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
            $result = $this->result($response, $credentials['provider_account_affinity']);
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
        $credentials = $this->credential(
            organization: $organization,
            expectedAffinity: $identity->providerAccountAffinity,
            requiresAffinity: true,
        );
        $token = $this->accessToken($credentials, $deadline);

        if ($this->fetchExpectedMeeting(
            $this->api($token, $deadline),
            $identity,
            $request,
            $credentials['provider_account_affinity'],
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
        $credentials = $this->credential(
            organization: $organization,
            expectedAffinity: $identity->providerAccountAffinity,
            requiresAffinity: true,
        );
        $token = $this->accessToken($credentials, $deadline);

        if ($this->fetchExpectedMeeting(
            $this->api($token, $deadline),
            $identity,
            $request,
            $credentials['provider_account_affinity'],
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
        $credentials = $this->credential(
            organization: $organization,
            expectedAffinity: $identity->providerAccountAffinity,
            requiresAffinity: true,
        );
        $token = $this->accessToken($credentials, $deadline);

        $meeting = $this->fetchExpectedMeeting(
            $this->api($token, $deadline),
            $identity,
            $request,
            $credentials['provider_account_affinity'],
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
        $credentials = $this->credential(
            organization: $organization,
            expectedAffinity: $request->providerAccountAffinity,
            requiresAffinity: true,
        );
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

        try {
            $result = $this->resultFromMeeting($matches[0], $credentials['provider_account_affinity']);
        } catch (VideoMeetingException $exception) {
            if ($exception->safeCode === 'zoom_meeting_response_invalid') {
                throw VideoMeetingException::reconciliationRequired('zoom_find_incomplete');
            }

            throw $exception;
        }
        $this->assertCorrelatedMeeting($result, $request);

        return $result;
    }

    public function getMeeting(
        Organization $organization,
        VideoMeetingIdentity $identity,
        VideoMeetingRequest $request,
        ProviderOperationDeadline $deadline,
    ): ?VideoMeetingResult {
        $credentials = $this->credential(
            organization: $organization,
            expectedAffinity: $identity->providerAccountAffinity,
            requiresAffinity: true,
        );
        $token = $this->accessToken($credentials, $deadline);

        $meeting = $this->fetchExpectedMeeting(
            $this->api($token, $deadline),
            $identity,
            $request,
            $credentials['provider_account_affinity'],
            'zoom_get',
        );

        return $meeting['result'] ?? null;
    }

    /** @return array{account_id: string, client_id: string, client_secret: string, host_user_id: string, provider_account_affinity: ProviderAccountAffinity} */
    private function credential(
        Organization $organization,
        ?ProviderAccountAffinity $expectedAffinity = null,
        bool $requiresAffinity = false,
    ): array {
        $credential = OrganizationCredential::query()
            ->where('organization_id', $organization->getKey())
            ->where('provider', 'zoom')
            ->where('credential_name', config('b2b.credential_name'))
            ->where('status', CredentialStatus::Active->value)
            ->first();

        if (! $credential instanceof OrganizationCredential) {
            if ($requiresAffinity) {
                throw VideoMeetingException::reconciliationRequired('zoom_credentials_missing');
            }

            throw VideoMeetingException::permanent('zoom_credentials_missing');
        }

        $values = [];
        foreach (['account_id', 'client_id', 'client_secret', 'host_user_id'] as $key) {
            $value = $credential->credentials[$key] ?? null;

            if (! is_string($value) || trim($value) === '') {
                if ($requiresAffinity) {
                    throw VideoMeetingException::reconciliationRequired('zoom_credentials_invalid');
                }

                throw VideoMeetingException::permanent('zoom_credentials_invalid');
            }

            $values[$key] = trim($value);
        }

        $affinity = new ProviderAccountAffinity(
            accountId: $values['account_id'],
            hostUserId: $values['host_user_id'],
        );

        if ($requiresAffinity
            && (! $expectedAffinity instanceof ProviderAccountAffinity
                || ! $affinity->equals($expectedAffinity))) {
            throw VideoMeetingException::reconciliationRequired(
                $expectedAffinity instanceof ProviderAccountAffinity
                    ? 'zoom_provider_affinity_mismatch'
                    : 'zoom_provider_affinity_missing',
            );
        }

        return [
            ...$values,
            'provider_account_affinity' => $affinity,
        ];
    }

    /** @param array{account_id: string, client_id: string, client_secret: string, host_user_id: string, provider_account_affinity: ProviderAccountAffinity} $credentials */
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
        return array_key_exists('agenda', $meeting)
            && is_string($meeting['agenda'])
            && $request->matchesCorrelation($meeting['agenda']);
    }

    /** @param array<string, mixed> $meeting */
    private function resultFromMeeting(array $meeting, ProviderAccountAffinity $affinity): VideoMeetingResult
    {
        $id = $this->parseMeetingId($meeting['id'] ?? null);
        $joinUrl = $meeting['join_url'] ?? null;

        if ($id === null
            || ! $this->isUsableJoinUrl($joinUrl)
            || ! array_key_exists('agenda', $meeting)
            || ! is_string($meeting['agenda'])
            || ! array_key_exists('start_time', $meeting)
            || ! is_string($meeting['start_time'])
            || trim($meeting['start_time']) === ''
            || ! array_key_exists('duration', $meeting)
            || ! is_int($meeting['duration'])
            || $meeting['duration'] <= 0
            || ! array_key_exists('timezone', $meeting)
            || ! is_string($meeting['timezone'])
            || trim($meeting['timezone']) === '') {
            throw VideoMeetingException::retryable('zoom_meeting_response_invalid');
        }

        $uuid = null;
        if (array_key_exists('uuid', $meeting)) {
            if (! is_string($meeting['uuid']) || trim($meeting['uuid']) === '') {
                throw VideoMeetingException::retryable('zoom_meeting_response_invalid');
            }

            $uuid = $meeting['uuid'];
        }

        try {
            $startsAt = $this->parseStartTime($meeting['start_time']);
        } catch (\Throwable) {
            throw VideoMeetingException::retryable('zoom_meeting_response_invalid');
        }

        return new VideoMeetingResult(
            identity: new VideoMeetingIdentity(
                $id,
                $uuid,
                $affinity,
            ),
            joinUrl: (string) $joinUrl,
            synchronizedAt: CarbonImmutable::now('UTC'),
            startsAt: $startsAt,
            durationMinutes: $meeting['duration'],
            timezone: $meeting['timezone'],
            agenda: $meeting['agenda'],
        );
    }

    private function parseStartTime(string $value): CarbonImmutable
    {
        $matches = [];

        if (preg_match(
            '/\A(?<date>[0-9]{4}-[0-9]{2}-[0-9]{2})[Tt](?<time>[0-9]{2}:[0-9]{2}:[0-9]{2})(?:\.(?<fraction>[0-9]{1,6}))?(?<offset>Z|z|[+-][0-9]{2}:[0-9]{2})\z/',
            $value,
            $matches,
        ) !== 1) {
            throw new \InvalidArgumentException;
        }

        $offset = $matches['offset'];
        if ($offset === '-00:00') {
            throw new \InvalidArgumentException;
        }

        $normalizedOffset = $offset;
        if ($offset === 'Z' || $offset === 'z') {
            $normalizedOffset = '+00:00';
        } elseif ((int) substr($offset, 1, 2) > 23 || (int) substr($offset, 4, 2) > 59) {
            throw new \InvalidArgumentException;
        }

        $fraction = $matches['fraction'];
        $normalizedFraction = str_pad($fraction, 6, '0');
        $normalized = $matches['date']
            .'T'.$matches['time']
            .($fraction === '' ? '' : '.'.$normalizedFraction)
            .$normalizedOffset;
        $format = $fraction === '' ? '!Y-m-d\TH:i:sP' : '!Y-m-d\TH:i:s.uP';

        try {
            $parsed = CarbonImmutable::createFromFormat($format, $normalized);
        } catch (\Throwable) {
            throw new \InvalidArgumentException;
        }

        $errors = CarbonImmutable::getLastErrors();
        if (! $parsed instanceof CarbonImmutable
            || (is_array($errors)
                && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $parsed->format('Y-m-d\TH:i:s') !== $matches['date'].'T'.$matches['time']
            || $parsed->format('u') !== $normalizedFraction
            || $parsed->format('P') !== $normalizedOffset) {
            throw new \InvalidArgumentException;
        }

        return $parsed->utc();
    }

    private function result(Response $response, ProviderAccountAffinity $affinity): VideoMeetingResult
    {
        return $this->resultFromMeeting((array) $response->json(), $affinity);
    }

    /** @return array{response: Response, result: VideoMeetingResult}|null */
    private function fetchExpectedMeeting(
        PendingRequest $api,
        VideoMeetingIdentity $identity,
        VideoMeetingRequest $request,
        ProviderAccountAffinity $affinity,
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

        try {
            $result = $this->resultFromMeeting((array) $response->json(), $affinity);
        } catch (VideoMeetingException $exception) {
            if ($exception->safeCode === 'zoom_meeting_response_invalid') {
                throw VideoMeetingException::reconciliationRequired('zoom_meeting_response_invalid');
            }

            throw $exception;
        }

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
            if (! $this->isCredibleListMeeting($meeting)) {
                throw VideoMeetingException::reconciliationRequired('zoom_find_incomplete');
            }
        }

        return [
            'meetings' => $envelope['meetings'],
            'next_page_token' => $envelope['next_page_token'],
        ];
    }

    private function isCredibleListMeeting(mixed $meeting): bool
    {
        if (! is_array($meeting) || array_is_list($meeting)) {
            return false;
        }

        return array_key_exists('id', $meeting)
            && $this->parseMeetingId($meeting['id']) !== null
            && array_key_exists('agenda', $meeting)
            && is_string($meeting['agenda']);
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

    private function parseMeetingId(mixed $id): ?string
    {
        return is_int($id) && $id > 0 ? (string) $id : null;
    }

    private function isUsableJoinUrl(mixed $url): bool
    {
        if (! is_string($url)
            || $url === ''
            || trim($url) !== $url
            || preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            return false;
        }

        try {
            $parts = parse_url($url);
        } catch (\ValueError) {
            return false;
        }

        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! array_key_exists('host', $parts)
            || trim((string) $parts['host']) === ''
            || array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)) {
            return false;
        }

        return ! array_key_exists('port', $parts)
            || $parts['port'] > 0;
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
