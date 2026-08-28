<?php

namespace App\Modules\Channels\Application;

use App\Modules\Channels\Domain\Enums\TelegramMenuLaunchMode;
use LogicException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ResolveTelegramMiniAppEntry
{
    private const ALLOWED_ROUTE_NAMES = [
        'portal.home',
        'portal.b2b',
        'portal.section',
    ];

    public function launchMode(string $key): ?TelegramMenuLaunchMode
    {
        $definition = $this->definition($key);

        if ($definition === null) {
            return null;
        }

        return TelegramMenuLaunchMode::tryFrom((string) ($definition['launch'] ?? ''));
    }

    public function launchUrl(string $key): string
    {
        $this->requireMiniApp($key);
        $baseUrl = $this->canonicalPortalUrl();
        $path = route('portal.telegram.launch', ['entry' => $key], false);

        return $baseUrl.'/'.ltrim($path, '/');
    }

    public function destination(string $key): string
    {
        $definition = $this->definition($key);

        if ($definition === null || $this->launchMode($key) !== TelegramMenuLaunchMode::MiniApp) {
            throw new NotFoundHttpException('The Telegram Mini App entry is not available.');
        }

        $routeName = $definition['route'] ?? null;

        if (! is_string($routeName) || ! in_array($routeName, self::ALLOWED_ROUTE_NAMES, true)) {
            throw new LogicException('The Telegram Mini App destination is not allowlisted.');
        }

        return route($routeName, $this->routeParameters($routeName, $definition['parameters'] ?? []), false);
    }

    public function requiresAuthentication(string $key): bool
    {
        $definition = $this->definition($key);

        if ($definition === null || $this->launchMode($key) !== TelegramMenuLaunchMode::MiniApp) {
            throw new NotFoundHttpException('The Telegram Mini App entry is not available.');
        }

        if (! is_bool($definition['requires_auth'] ?? null)) {
            throw new LogicException('The Telegram Mini App authentication policy is invalid.');
        }

        return $definition['requires_auth'];
    }

    public function destinationOrNull(mixed $key): ?string
    {
        if (! is_string($key) || trim($key) === '') {
            return null;
        }

        try {
            return $this->destination($key);
        } catch (LogicException|NotFoundHttpException) {
            return null;
        }
    }

    public function externalUrl(string $key): ?string
    {
        $definition = $this->definition($key);

        if ($definition === null || $this->launchMode($key) !== TelegramMenuLaunchMode::ExternalUrl) {
            return null;
        }

        $url = $definition['url'] ?? null;

        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = $parts['host'] ?? null;

        if (! in_array($scheme, ['http', 'https'], true)
            || ! is_string($host)
            || trim($host) === ''
            || array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)) {
            return null;
        }

        return $url;
    }

    /** @return array<string, mixed>|null */
    private function definition(string $key): ?array
    {
        $definitions = config('portal.telegram.entries', []);

        if (! is_array($definitions)) {
            return null;
        }

        $definition = $definitions[$key] ?? null;

        return is_array($definition) ? $definition : null;
    }

    private function requireMiniApp(string $key): void
    {
        if ($this->launchMode($key) !== TelegramMenuLaunchMode::MiniApp) {
            throw new NotFoundHttpException('The Telegram Mini App entry is not available.');
        }
    }

    private function canonicalPortalUrl(): string
    {
        $configuredUrl = config('portal.telegram.portal_url');

        if (! is_string($configuredUrl) || trim($configuredUrl) === '') {
            throw new LogicException('The Telegram Mini App URL is not configured.');
        }

        $configuredUrl = trim($configuredUrl);
        $parts = parse_url($configuredUrl);

        if (! is_array($parts)) {
            throw new LogicException('The Telegram Mini App URL is invalid.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = $parts['host'] ?? null;
        $path = $parts['path'] ?? '';

        if ($scheme !== 'https'
            || ! is_string($host)
            || trim($host) === ''
            || ! in_array($path, ['', '/'], true)
            || array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)
            || array_key_exists('query', $parts)
            || array_key_exists('fragment', $parts)) {
            throw new LogicException('The Telegram Mini App URL is invalid.');
        }

        return rtrim($configuredUrl, '/');
    }

    /** @return array<string, string> */
    private function routeParameters(string $routeName, mixed $parameters): array
    {
        if (! is_array($parameters)) {
            throw new LogicException('The Telegram Mini App route parameters are invalid.');
        }

        if (in_array($routeName, ['portal.home', 'portal.b2b'], true)) {
            if ($parameters !== []) {
                throw new LogicException('The Telegram Mini App route parameters are not allowlisted.');
            }

            return [];
        }

        $sectionRegistry = config('portal.content_sections', []);
        $section = $parameters['section'] ?? null;

        if ($routeName !== 'portal.section'
            || count($parameters) !== 1
            || ! is_string($section)
            || trim($section) === ''
            || ! is_array($sectionRegistry)
            || ! array_key_exists($section, $sectionRegistry)) {
            throw new LogicException('The Telegram Mini App section is not allowlisted.');
        }

        return ['section' => $section];
    }
}
