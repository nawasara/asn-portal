<?php

namespace Nawasara\AsnPortal\Support;

use Illuminate\Http\Request;

/**
 * The real requester's IP when the call comes through the portal server.
 *
 * Every request from the Astro portal reaches Nawasara from the portal
 * server's address, so request()->ip() is the same for everybody. The portal
 * forwards the browser's address in `X-Portal-Client-IP`. Used for rate
 * limit keys and audit (plan §4b); without it the per-person OTP limit
 * becomes one global limit.
 *
 * Trust: on the MFA reset endpoints the caller is authenticated with the
 * portal's own nws_ token (IP allow-listed), so the header is the portal's
 * word. On account endpoints a user calling the API directly could set it,
 * but only to misstate their OWN address in their own audit row.
 */
final class ClientIp
{
    public const HEADER = 'X-Portal-Client-IP';

    public static function from(Request $request): string
    {
        $forwarded = trim((string) $request->header(self::HEADER, ''));

        if ($forwarded !== '' && filter_var($forwarded, FILTER_VALIDATE_IP) !== false) {
            return $forwarded;
        }

        return (string) $request->ip();
    }
}
