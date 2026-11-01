<?php

declare(strict_types=1);

namespace Core\Portal\Presentation\Http;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * ADR-0075 §1 — the one cookie of the portal.
 *
 * Over HTTPS it is `__Host-portal` (Secure, Path=/, no Domain: the browser
 * itself refuses to let a sibling subdomain set or read it); HttpOnly and
 * SameSite=Lax always. Plain HTTP is allowed only for local development,
 * where the `__Host-` prefix cannot be honoured.
 */
final class PortalCookie
{
    public static function name(Request $request): string
    {
        return $request->isSecure() ? '__Host-portal' : 'portal';
    }

    public static function make(Request $request, string $value, int $lifetimeSeconds): Cookie
    {
        return new Cookie(
            self::name($request),
            $value,
            time() + $lifetimeSeconds,
            '/',
            null,
            $request->isSecure(),
            true,
            false,
            Cookie::SAMESITE_LAX,
        );
    }

    public static function forget(Request $request): Cookie
    {
        return new Cookie(self::name($request), '', 1, '/', null, $request->isSecure(), true, false, Cookie::SAMESITE_LAX);
    }
}
