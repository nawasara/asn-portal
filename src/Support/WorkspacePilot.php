<?php

namespace Nawasara\AsnPortal\Support;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;

/**
 * Whether an ASN may see the Kisara Workspace apps (Docs, Sheets, Slides,
 * Drive) in the 9-dot launcher (FR-03 AC-3).
 *
 * Asks nawasara/workspace's AccessDecider, the same rule the Workspace access
 * API and the Keycloak group `workspace-pilot` use (ADR 0004), so the
 * launcher never offers an app whose door would then refuse the person.
 *
 * nawasara/workspace is NOT a dependency of this package: an app without it
 * still serves the launcher, with the pilot-only items hidden. Fail closed,
 * as on any error from the decider: a missing Docs icon is a small
 * annoyance, an icon that leads to "sedang uji coba" for every ASN is a
 * visible bug.
 */
class WorkspacePilot
{
    public const DECIDER = 'Nawasara\Workspace\Services\AccessDecider';

    public function __construct(
        private Container $container,
        private string $deciderClass = self::DECIDER,
    ) {}

    public function available(): bool
    {
        return class_exists($this->deciderClass);
    }

    public function allows(string $nip): bool
    {
        if (! $this->available()) {
            return false;
        }

        try {
            $decision = $this->container->make($this->deciderClass)->decide($nip);

            return ($decision['allowed'] ?? false) === true;
        } catch (\Throwable $e) {
            Log::warning('asn-portal: launcher pilot check failed, hiding pilot apps', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
