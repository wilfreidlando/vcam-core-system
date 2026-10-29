<?php

declare(strict_types=1);

namespace Core\Portal\Domain\Model;

use Core\Portal\Domain\Exception\InvalidPortalSettings;

/**
 * ADR-0075 §4 — what a project chooses for its portal: its look, where a
 * customer may be sent back to, and what a customer may do alone.
 *
 * Every value that ends up in a page or in a redirect is validated here,
 * once, so no template has to wonder whether it is safe.
 */
final readonly class PortalSettings
{
    public const DEFAULT_COLOR = '#2563eb';

    /**
     * @param list<string> $allowedReturnUrls exact origins, optionally with a path prefix
     * @param list<string> $paymentChannels the canonical channels a customer may pay with
     */
    public function __construct(
        public string $projectId,
        public string $displayName,
        public ?string $logoUrl,
        public string $primaryColor,
        public ?string $supportUrl,
        public string $locale,
        public array $allowedReturnUrls,
        public bool $allowSelfCancel,
        public bool $allowPlanChange,
        public array $paymentChannels,
        public bool $directLoginEnabled = false,
        public ?string $directLoginSession = null,
    ) {}

    /**
     * ADR-0075 §2 — the login by code needs the switch **and** the WhatsApp
     * session the code leaves from; with either missing it does not exist.
     */
    public function offersDirectLogin(): bool
    {
        return $this->directLoginEnabled && $this->directLoginSession !== null && $this->directLoginSession !== '';
    }

    public static function defaultsFor(string $projectId): self
    {
        return new self($projectId, 'Espace client', null, self::DEFAULT_COLOR, null, 'fr', [], false, false, []);
    }

    /**
     * @param list<string> $allowedReturnUrls
     * @param list<string> $paymentChannels
     */
    public static function validated(
        string $projectId,
        string $displayName,
        ?string $logoUrl,
        string $primaryColor,
        ?string $supportUrl,
        string $locale,
        array $allowedReturnUrls,
        bool $allowSelfCancel,
        bool $allowPlanChange,
        array $paymentChannels,
        bool $directLoginEnabled = false,
        ?string $directLoginSession = null,
    ): self {
        $displayName = trim($displayName);

        if ($displayName === '' || mb_strlen($displayName) > 80) {
            throw InvalidPortalSettings::because('display_name must be 1 to 80 characters.');
        }

        if (preg_match('/^#[0-9a-fA-F]{6}$/', $primaryColor) !== 1) {
            throw InvalidPortalSettings::because('primary_color must be a #rrggbb colour.');
        }

        if ($logoUrl !== null && ! self::isHttpsUrl($logoUrl)) {
            throw InvalidPortalSettings::because('logo_url must be an https URL.');
        }

        if ($supportUrl !== null && ! self::isHttpsUrl($supportUrl) && preg_match('/^mailto:[^\s@]+@[^\s@]+$/', $supportUrl) !== 1) {
            throw InvalidPortalSettings::because('support_url must be an https URL or a mailto: address.');
        }

        if (preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $locale) !== 1) {
            throw InvalidPortalSettings::because('locale must look like "fr" or "fr-CM".');
        }

        if (count($allowedReturnUrls) > 20) {
            throw InvalidPortalSettings::because('allowed_return_urls holds at most 20 entries.');
        }

        foreach ($allowedReturnUrls as $entry) {
            if (! self::isAllowableReturnEntry($entry)) {
                throw InvalidPortalSettings::because("allowed_return_urls entry \"{$entry}\" must be an https origin (http only for localhost), without query or fragment.");
            }
        }

        foreach ($paymentChannels as $channel) {
            if (preg_match('/^[a-z][a-z0-9_]*$/', $channel) !== 1) {
                throw InvalidPortalSettings::because("payment_channels entry \"{$channel}\" is not a canonical channel name.");
            }
        }

        $directLoginSession = $directLoginSession === null ? null : trim($directLoginSession);
        $directLoginSession = $directLoginSession === '' ? null : $directLoginSession;

        if ($directLoginSession !== null && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $directLoginSession) !== 1) {
            throw InvalidPortalSettings::because('direct_login_session must be 1 to 64 letters, digits, "-" or "_" (the name of a WhatsApp session of the project).');
        }

        if ($directLoginEnabled && $directLoginSession === null) {
            throw InvalidPortalSettings::because('direct_login_session is required to enable the direct login: the code is sent from that WhatsApp session.');
        }

        return new self(
            $projectId,
            $displayName,
            $logoUrl,
            strtolower($primaryColor),
            $supportUrl,
            $locale,
            array_values(array_unique($allowedReturnUrls)),
            $allowSelfCancel,
            $allowPlanChange,
            array_values(array_unique($paymentChannels)),
            $directLoginEnabled,
            $directLoginSession,
        );
    }

    /**
     * ADR-0075 §Sécurité — exact origin (scheme, host, port) and a path that
     * is the entry's path or lies under it, on a segment boundary. So
     * `https://app.example.com.evil.io` and `https://app.example.com/billingevil`
     * never match `https://app.example.com/billing`.
     */
    public function allowsReturnUrl(string $url): bool
    {
        if (str_contains($url, '\\') || preg_match('/[\x00-\x20]/', $url) === 1) {
            return false;
        }

        $target = parse_url($url);

        if ($target === false || ! isset($target['scheme'], $target['host']) || isset($target['user']) || isset($target['pass'])) {
            return false;
        }

        $targetPath = $target['path'] ?? '/';

        // The browser normalises `%2e%2e` and `%2f` like `..` and `/`: look at
        // the path as the browser will, or the prefix can be walked out of.
        $decoded = rawurldecode($targetPath);

        if (in_array('..', explode('/', str_replace('\\', '/', $decoded)), true)
            || preg_match('#%(2f|5c)#i', $targetPath) === 1) {
            return false;
        }

        foreach ($this->allowedReturnUrls as $entry) {
            $allowed = parse_url($entry);

            if ($allowed === false || ! isset($allowed['scheme'], $allowed['host'])) {
                continue;
            }

            if (strtolower($allowed['scheme']) !== strtolower($target['scheme'])
                || strtolower($allowed['host']) !== strtolower($target['host'])
                || self::port($allowed) !== self::port($target)) {
                continue;
            }

            $prefix = rtrim($allowed['path'] ?? '', '/');

            if ($prefix === '' || $targetPath === $prefix || str_starts_with($targetPath, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{scheme?: string, port?: int} $parts
     */
    private static function port(array $parts): int
    {
        return $parts['port'] ?? (strtolower($parts['scheme'] ?? '') === 'https' ? 443 : 80);
    }

    private static function isHttpsUrl(string $url): bool
    {
        $parts = parse_url($url);

        return $parts !== false
            && ($parts['scheme'] ?? '') === 'https'
            && isset($parts['host'])
            && ! isset($parts['user'], $parts['pass'])
            && preg_match('/[\x00-\x20"<>\\\\]/', $url) !== 1;
    }

    private static function isAllowableReturnEntry(string $entry): bool
    {
        $parts = parse_url($entry);

        if ($parts === false || ! isset($parts['scheme'], $parts['host']) || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user'])) {
            return false;
        }

        if (preg_match('/[\x00-\x20"<>\\\\]/', $entry) === 1) {
            return false;
        }

        $local = in_array(strtolower($parts['host']), ['localhost', '127.0.0.1'], true);

        return $parts['scheme'] === 'https' || ($parts['scheme'] === 'http' && $local);
    }
}
