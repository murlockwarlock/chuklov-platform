<?php

namespace App\Modules\B2B\Infrastructure\Video;

use App\Modules\B2B\Domain\Contracts\VideoMeetingProvider;
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

    public function createMeeting(Organization $organization, VideoMeetingRequest $request): VideoMeetingResult
    {
        $credentials = $this->credential($organization);
        $token = $this->accessToken($credentials);

        try {
            $response = $this->api($token)->post(
                $this->url('/users/'.rawurlencode((string) $credentials['host_user_id']).'/meetings'),
                $this->payload($request),
            );
        } catch (ConnectionException) {
            throw VideoMeetingException::retryable('zoom_create_connection', true);
        }

        if (! $response->successful()) {
            throw $this->responseException($response, 'zoom_create', true);
        }

        return $this->result($response);
    }

    public function updateMeeting(
        Organization $organization,
        VideoMeetingIdentity $identity,
        VideoMeetingRequest $request,
    ): void {
        $credentials = $this->credential($organization);
        $token = $this->accessToken($credentials);

        try {
            $response = $this->api($token)->patch(
                $this->url('/meetings/'.rawurlencode($identity->meetingId)),
                $this->payload($request),
            );
        } catch (ConnectionException) {
            throw VideoMeetingException::retryable('zoom_update_connection', true);
        }

        if (! $response->successful()) {
            throw $this->responseException($response, 'zoom_update', true);
        }
    }

    public function cancelMeeting(Organization $organization, VideoMeetingIdentity $identity): void
    {
        $credentials = $this->credential($organization);
        $token = $this->accessToken($credentials);

        try {
            $response = $this->api($token)->delete(
                $this->url('/meetings/'.rawurlencode($identity->meetingId)),
            );
        } catch (ConnectionException) {
            throw VideoMeetingException::retryable('zoom_cancel_connection', true);
        }

        if (! $response->successful() && $response->status() !== 404) {
            throw $this->responseException($response, 'zoom_cancel', true);
        }
    }

    public function obtainHostLaunchUrl(Organization $organization, VideoMeetingIdentity $identity): string
    {
        $credentials = $this->credential($organization);
        $token = $this->accessToken($credentials);

        try {
            $response = $this->api($token)->get(
                $this->url('/meetings/'.rawurlencode($identity->meetingId)),
            );
        } catch (ConnectionException) {
            throw VideoMeetingException::retryable('zoom_host_url_connection');
        }

        if (! $response->successful()) {
            throw $this->responseException($response, 'zoom_host_url', true);
        }

        $startUrl = $response->json('start_url');

        if (! is_string($startUrl) || ! str_starts_with($startUrl, 'https://')) {
            throw VideoMeetingException::permanent('zoom_host_url_missing');
        }

        return $startUrl;
    }

    public function findMeeting(Organization $organization, VideoMeetingRequest $request): ?VideoMeetingResult
    {
        $credentials = $this->credential($organization);
        $token = $this->accessToken($credentials);
        $date = $request->startsAt->setTimezone('UTC');
        $matches = [];
        $nextPageToken = null;

        for ($page = 0; $page < 5; $page++) {
            try {
                $response = $this->api($token)->get(
                    $this->url('/users/'.rawurlencode((string) $credentials['host_user_id']).'/meetings'),
                    [
                        'type' => 'scheduled',
                        'from' => $date->subDay()->toDateString(),
                        'to' => $date->addDay()->toDateString(),
                        'page_size' => 300,
                        ...($nextPageToken === null ? [] : ['next_page_token' => $nextPageToken]),
                    ],
                );
            } catch (ConnectionException) {
                throw VideoMeetingException::retryable('zoom_find_connection');
            }

            if (! $response->successful()) {
                throw $this->responseException($response, 'zoom_find', true);
            }

            $matches = [...$matches, ...$this->matchingMeetings((array) $response->json('meetings', []), $request)];
            $nextPageToken = $response->json('next_page_token');

            if (! is_string($nextPageToken) || $nextPageToken === '') {
                break;
            }
        }

        if (is_string($nextPageToken) && $nextPageToken !== '') {
            throw VideoMeetingException::reconciliationRequired('zoom_find_incomplete');
        }

        if ($matches === []) {
            return null;
        }

        if (count($matches) > 1) {
            throw VideoMeetingException::reconciliationRequired('zoom_meeting_ambiguous');
        }

        return $this->resultFromMeeting($matches[0]);
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
    private function accessToken(array $credentials): string
    {
        try {
            $response = Http::asForm()
                ->withoutRedirecting()
                ->connectTimeout(3)
                ->timeout((int) config('b2b.zoom.timeout_seconds'))
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

    private function api(string $token): PendingRequest
    {
        return Http::acceptJson()
            ->withoutRedirecting()
            ->connectTimeout(3)
            ->timeout((int) config('b2b.zoom.timeout_seconds'))
            ->withToken($token);
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
            'start_time' => $request->startsAt->utc()->format('Y-m-d\\TH:i:s\\Z'),
            'duration' => $request->durationMinutes,
            'timezone' => $request->timezone,
            'tracking_fields' => [
                ['field' => 'chuklov_sales_call', 'value' => $request->externalKey],
            ],
            'settings' => [
                'join_before_host' => false,
                'waiting_room' => true,
            ],
        ];
    }

    /** @param array<string, mixed> $meeting */
    private function matches(array $meeting, VideoMeetingRequest $request): bool
    {
        $trackingFields = $meeting['tracking_fields'] ?? [];

        if (! is_array($trackingFields)) {
            return false;
        }

        foreach ($trackingFields as $field) {
            if (is_array($field)
                && ($field['field'] ?? null) === 'chuklov_sales_call'
                && ($field['value'] ?? null) === $request->externalKey) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $meeting */
    private function resultFromMeeting(array $meeting): VideoMeetingResult
    {
        $id = $meeting['id'] ?? null;
        $joinUrl = $meeting['join_url'] ?? null;

        if ((! is_string($id) && ! is_int($id)) || ! is_string($joinUrl) || ! str_starts_with($joinUrl, 'https://')) {
            throw VideoMeetingException::retryable('zoom_meeting_response_invalid');
        }

        $uuid = $meeting['uuid'] ?? null;

        return new VideoMeetingResult(
            identity: new VideoMeetingIdentity((string) $id, is_string($uuid) ? $uuid : null),
            joinUrl: $joinUrl,
            synchronizedAt: CarbonImmutable::now('UTC'),
        );
    }

    private function result(Response $response): VideoMeetingResult
    {
        return $this->resultFromMeeting((array) $response->json());
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
