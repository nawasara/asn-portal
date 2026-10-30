<?php

namespace Nawasara\AsnPortal\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Nawasara\Vault\Facades\Vault;
use RuntimeException;

/**
 * WhatsApp gateway (WAGO) for one-time codes, ported from the old portal's
 * WaNotificationService.
 *
 * Credentials come from Vault (group `nawasara-asn-portal.whatsapp.vault_group`,
 * default `whatsapp`, fields `api_base` and `api_key`) instead of .env.
 *
 * isRegistered() keeps the old portal's most important behaviour: it THROWS
 * when the gateway itself is unhealthy (session dropped, 401, 5xx, timeout)
 * and returns false only when the gateway clearly answered that the number
 * is not on WhatsApp. Folding both into `false` would tell people their
 * correct number is wrong whenever the gateway is down.
 */
class WhatsappGateway
{
    public function send(string $phone, string $message): void
    {
        $response = $this->http()->post($this->url('/wago/sendMessage'), [
            'phone' => $phone,
            'message' => $message,
        ]);

        if ($response->failed() || (int) ($response->json('code') ?? 200) >= 400) {
            Log::warning('asn-portal: WhatsApp send failed', ['status' => $response->status()]);

            throw new RuntimeException('Layanan WhatsApp sedang bermasalah.');
        }
    }

    /**
     * @throws RuntimeException when the gateway cannot answer
     */
    public function isRegistered(string $phone): bool
    {
        try {
            $response = $this->http()->get($this->url('/wago/userCheck'), ['phone' => $phone]);
        } catch (\Throwable $e) {
            Log::info('asn-portal: WhatsApp userCheck unreachable', ['reason' => $e->getMessage()]);

            throw new RuntimeException('Layanan WhatsApp sedang tidak dapat dihubungi.');
        }

        // The gateway wraps errors in the body (a dropped session answers
        // code 401 "you are not connect to services server", sometimes with
        // HTTP 200).
        $code = $response->json('code') ?? $response->status();

        if ($response->status() >= 400 || (is_int($code) && $code >= 400)) {
            Log::info('asn-portal: WhatsApp userCheck error', ['status' => $response->status()]);

            throw new RuntimeException('Layanan WhatsApp sedang bermasalah.');
        }

        return (bool) $response->json('results.is_on_whatsapp', false);
    }

    private function http()
    {
        return Http::withToken((string) Vault::get($this->group(), 'api_key'))
            ->acceptJson()
            ->timeout(15);
    }

    private function url(string $path): string
    {
        return rtrim((string) Vault::get($this->group(), 'api_base'), '/').$path;
    }

    private function group(): string
    {
        return (string) config('nawasara-asn-portal.whatsapp.vault_group', 'whatsapp');
    }
}
