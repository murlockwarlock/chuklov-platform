<?php

namespace App\Modules\AI\Infrastructure\Providers;

use App\Modules\AI\Domain\Exceptions\AiProviderProbeUnsupportedException;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

final class AiProviderEndpointGuard
{
    public static function assertSafeUrl(string $value, string $field, string $provider): string
    {
        if (preg_match('/[\r\n]/', $value) === 1) {
            throw new AiProviderProbeUnsupportedException("The provider option {$field} is invalid.");
        }

        $parts = parse_url($value);
        if (! is_array($parts)
            || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || ! is_string($parts['host'] ?? null)
            || isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])) {
            throw new AiProviderProbeUnsupportedException("The provider option {$field} must be an HTTP(S) URL without credentials or query parameters.");
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($provider !== 'ollama' && $scheme !== 'https') {
            throw new AiProviderProbeUnsupportedException("The provider option {$field} must use HTTPS.");
        }

        $host = trim(strtolower(rtrim($parts['host'], '.')), '[]');
        if ($host === '' || self::isMetadataHost($host) || self::isLiteralMetadataIp($host)) {
            throw new AiProviderProbeUnsupportedException('The provider endpoint is not allowed.');
        }

        if (self::isLiteralSpecialIp($host)) {
            throw new AiProviderProbeUnsupportedException('The provider endpoint is not allowed.');
        }

        if ($provider !== 'ollama' && self::isForbiddenHost($host)) {
            throw new AiProviderProbeUnsupportedException('The provider endpoint is not allowed.');
        }

        if (isset($parts['port'])
            && filter_var($parts['port'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) === false) {
            throw new AiProviderProbeUnsupportedException("The provider option {$field} has an invalid port.");
        }

        return rtrim($value, '/');
    }

    public static function guardRequest(RequestInterface $request): RequestInterface
    {
        $provider = strtolower(trim($request->getHeaderLine('X-Chuklov-AI-Provider')));
        if ($provider === '') {
            return $request;
        }

        $uri = $request->getUri();
        $host = trim(strtolower(rtrim($uri->getHost(), '.')), '[]');
        if (! in_array(strtolower($uri->getScheme()), ['http', 'https'], true)
            || $host === ''
            || self::isMetadataHost($host)
            || self::isLiteralMetadataIp($host)) {
            throw new RuntimeException('The provider endpoint is not allowed.');
        }

        if (self::isLiteralSpecialIp($host)) {
            throw new RuntimeException('The provider endpoint is not allowed.');
        }

        if ($provider !== 'ollama' && strtolower($uri->getScheme()) !== 'https') {
            throw new RuntimeException('The provider endpoint must use HTTPS.');
        }

        if ($provider !== 'ollama' && self::isForbiddenHost($host)) {
            throw new RuntimeException('The provider endpoint is not allowed.');
        }

        return $request;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public static function pinDns(RequestInterface $request, array $options): array
    {
        $provider = strtolower(trim($request->getHeaderLine('X-Chuklov-AI-Provider')));
        if (! in_array($provider, ['openai_compatible', 'ollama', 'azure'], true)) {
            return $options;
        }

        $uri = $request->getUri();
        $host = trim(strtolower(rtrim($uri->getHost(), '.')), '[]');
        if ($host === 'localhost'
            || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6) !== false) {
            return $options;
        }

        $addresses = self::dnsAddresses($host, $provider === 'ollama');
        $port = $uri->getPort() ?? 443;
        $resolve = array_map(
            static fn (string $address): string => $host.':'.$port.':'.(
                str_contains($address, ':') ? '['.$address.']' : $address
            ),
            $addresses,
        );
        $curlOptions = is_array($options['curl'] ?? null) ? $options['curl'] : [];
        $curlOptions[CURLOPT_RESOLVE] = $resolve;
        $options['curl'] = $curlOptions;

        return $options;
    }

    private static function isForbiddenHost(string $host): bool
    {
        if ($host === 'localhost') {
            return true;
        }

        $ipVersion = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6);
        if ($ipVersion !== false) {
            return self::isForbiddenIp($host);
        }

        if (preg_match('/^(?:0x[0-9a-f]+|[0-9]+)(?:\.(?:0x[0-9a-f]+|[0-9]+)){0,3}$/i', $host) === 1) {
            return true;
        }

        return false;
    }

    private static function isLiteralSpecialIp(string $host): bool
    {
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6) !== false
            && self::isSpecialIp($host);
    }

    private static function isLiteralMetadataIp(string $host): bool
    {
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6) !== false
            && self::isMetadataIp($host);
    }

    /** @return list<string> */
    private static function dnsAddresses(string $host, bool $allowPrivate): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records === false || $records === []) {
            throw new RuntimeException('The provider endpoint could not be resolved safely.');
        }

        $addresses = [];
        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if (! is_string($address)
                || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6) === false) {
                continue;
            }

            if (self::isMetadataIp($address)
                || self::isSpecialIp($address)
                || (! $allowPrivate && self::isForbiddenIp($address))) {
                throw new RuntimeException('The provider endpoint resolved to a forbidden network.');
            }

            $addresses[$address] = $address;
        }

        if ($addresses === []) {
            throw new RuntimeException('The provider endpoint could not be resolved safely.');
        }

        return array_values($addresses);
    }

    private static function isForbiddenIp(string $ip): bool
    {
        $mappedIpv4 = self::mappedIpv4($ip);
        if ($mappedIpv4 !== null) {
            return self::isForbiddenIp($mappedIpv4);
        }

        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false;
    }

    private static function isMetadataIp(string $ip): bool
    {
        $mappedIpv4 = self::mappedIpv4($ip);
        if ($mappedIpv4 !== null) {
            return self::isMetadataIp($mappedIpv4);
        }

        $normalized = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6);
        if ($normalized === false) {
            return true;
        }

        $packed = inet_pton($normalized);
        if ($packed === false) {
            return true;
        }

        $canonical = inet_ntop($packed);

        return in_array($canonical, [
            '169.254.169.254',
            '169.254.170.2',
            'fd00:ec2::254',
        ], true);
    }

    private static function isSpecialIp(string $ip): bool
    {
        $mappedIpv4 = self::mappedIpv4($ip);
        if ($mappedIpv4 !== null) {
            return self::isSpecialIp($mappedIpv4);
        }

        $normalized = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6);
        if ($normalized === false) {
            return true;
        }

        $packed = inet_pton($normalized);
        if ($packed === false) {
            return true;
        }

        if (strlen($packed) === 4) {
            $numeric = (int) sprintf('%u', ip2long($normalized));

            return $numeric === 0
                || $numeric === 4_294_967_295
                || ($numeric & 0xF0000000) === 0xE0000000;
        }

        $canonical = inet_ntop($packed);
        if (! is_string($canonical)) {
            return true;
        }

        return $canonical === '::' || str_starts_with($canonical, 'ff');
    }

    private static function mappedIpv4(string $ip): ?string
    {
        $packed = @inet_pton($ip);
        if ($packed === false
            || strlen($packed) !== 16
            || substr($packed, 0, 10) !== str_repeat("\0", 10)
            || substr($packed, 10, 2) !== "\xff\xff") {
            return null;
        }

        return inet_ntop(substr($packed, 12)) ?: null;
    }

    private static function isMetadataHost(string $host): bool
    {
        return in_array($host, [
            '169.254.169.254',
            '169.254.170.2',
            'metadata.google.internal',
            'instance-data.ec2.internal',
        ], true);
    }
}
