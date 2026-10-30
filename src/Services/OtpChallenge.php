<?php

namespace Nawasara\AsnPortal\Services;

use Illuminate\Contracts\Cache\Repository;

/**
 * One-time codes for flows that cannot rely on a login (MFA reset) or that
 * must prove ownership of a new contact (WhatsApp number).
 *
 * Differences from the old portal's OtpForm, on purpose:
 *
 * - The code is stored as an HMAC, never in plain text, and never logged.
 *   The old portal logged both the typed and the expected code on mismatch.
 * - Verification has an attempt limit. The old portal had none: a 6-digit
 *   code valid for 60 minutes could simply be tried until it matched.
 * - Send throttling is keyed on the identity AND the requester's real IP
 *   (passed in by the caller), never on request()->ip(): behind the Astro
 *   server that would be one IP for everybody, and the limit would become
 *   global (plan §4b).
 */
class OtpChallenge
{
    public function __construct(
        protected Repository $cache,
        protected string $secret,
        protected int $ttlMinutes = 15,
        protected int $maxSends = 3,
        protected int $sendWindowMinutes = 10,
        protected int $maxVerifyAttempts = 5,
    ) {}

    /**
     * Create a code for `$purpose:$identity`, or null when the send limit for
     * this identity from this IP is reached. Call markSent() only after the
     * code was actually delivered, so a failed delivery does not use up an
     * attempt.
     */
    public function issue(string $purpose, string $identity, string $requesterIp): ?string
    {
        if ((int) $this->cache->get($this->sendsKey($purpose, $identity, $requesterIp), 0) >= $this->maxSends) {
            return null;
        }

        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);

        $this->cache->put($this->codeKey($purpose, $identity), [
            'hash' => $this->hash($purpose, $identity, $code),
            'attempts' => 0,
        ], $this->ttlMinutes * 60);

        return $code;
    }

    public function markSent(string $purpose, string $identity, string $requesterIp): void
    {
        $key = $this->sendsKey($purpose, $identity, $requesterIp);

        $this->cache->put($key, (int) $this->cache->get($key, 0) + 1, $this->sendWindowMinutes * 60);
    }

    /** Forget an issued code whose delivery failed. */
    public function discard(string $purpose, string $identity): void
    {
        $this->cache->forget($this->codeKey($purpose, $identity));
    }

    /**
     * 'valid' (and the code is consumed), 'invalid', 'expired' (no code, or
     * it was burned by too many wrong attempts).
     */
    public function verify(string $purpose, string $identity, string $code): string
    {
        $key = $this->codeKey($purpose, $identity);
        $entry = $this->cache->get($key);

        if (! is_array($entry) || ($entry['attempts'] ?? 0) >= $this->maxVerifyAttempts) {
            return 'expired';
        }

        if (preg_match('/^\d{6}$/', $code) === 1 && hash_equals($entry['hash'], $this->hash($purpose, $identity, $code))) {
            $this->cache->forget($key);

            return 'valid';
        }

        $entry['attempts'] = ($entry['attempts'] ?? 0) + 1;

        // Keep the original expiry window approximately: re-put with the
        // configured TTL is acceptable because attempts only grow.
        if ($entry['attempts'] >= $this->maxVerifyAttempts) {
            $this->cache->forget($key);

            return 'expired';
        }

        $this->cache->put($key, $entry, $this->ttlMinutes * 60);

        return 'invalid';
    }

    public function ttlMinutes(): int
    {
        return $this->ttlMinutes;
    }

    private function hash(string $purpose, string $identity, string $code): string
    {
        return hash_hmac('sha256', "{$purpose}|{$identity}|{$code}", $this->secret);
    }

    private function codeKey(string $purpose, string $identity): string
    {
        return "asn-portal:otp:{$purpose}:".hash('sha256', $identity);
    }

    private function sendsKey(string $purpose, string $identity, string $ip): string
    {
        return "asn-portal:otp-sends:{$purpose}:".hash('sha256', $identity.'|'.$ip);
    }
}
