<?php

namespace Nawasara\AsnPortal\Services;

use Nawasara\AsnPortal\Support\IpPlace;

/**
 * IP → place for the "Perangkat" and checkup pages.
 *
 * Public addresses go through nawasara/secscan's IpGeolocator (ipinfo.io,
 * cached 30 days, failures cached briefly, never throws). Secscan is an
 * optional neighbour: without it, or with `location.enabled` off, only
 * known networks get a name and everything else shows its IP alone.
 */
class IpLocator
{
    /** @return array{label: string, network: ?string, unusual: bool}|null */
    public function place(?string $ip): ?array
    {
        if (! is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $known = IpPlace::known($ip, (array) config('nawasara-asn-portal.location.networks', []));

        if ($known !== null) {
            return $known;
        }

        if (! config('nawasara-asn-portal.location.enabled', true)
            || ! class_exists(\Nawasara\Secscan\Services\IpGeolocator::class)) {
            return null;
        }

        try {
            $geo = app(\Nawasara\Secscan\Services\IpGeolocator::class)->locate($ip);
        } catch (\Throwable) {
            return null;
        }

        return IpPlace::fromGeo($geo, (string) config('nawasara-asn-portal.location.home_country', 'ID'));
    }

    /**
     * @param  iterable<?string>  $ips
     * @return array<string, array{label: string, network: ?string, unusual: bool}>
     */
    public function places(iterable $ips): array
    {
        $out = [];

        foreach ($ips as $ip) {
            if (is_string($ip) && ! array_key_exists($ip, $out) && ($place = $this->place($ip)) !== null) {
                $out[$ip] = $place;
            }
        }

        return $out;
    }
}
