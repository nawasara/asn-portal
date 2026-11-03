<?php

namespace Nawasara\AsnPortal\Support;

/**
 * Where a sign-in came from, as the account portal shows it.
 *
 * Pure, so the rules are tested without a geolocation service.
 *
 * Two decisions, both easy to undo by mistake:
 *
 * - Known networks (the office range, private addresses) are named HERE and
 *   never sent to the geolocation service: "Jaringan Diskominfo" is more
 *   useful than whatever city the provider guesses, and internal addresses
 *   have no business leaving the building.
 *
 * - Only a sign-in from ANOTHER COUNTRY is "unusual". City-level IP data is
 *   poor for Indonesian mobile carriers (a phone in Ponorogo routinely shows
 *   up as Jakarta or Surabaya), so flagging by city would mark nearly every
 *   phone login as suspicious and teach people to ignore the warning. The
 *   city is shown, prefixed "sekitar", as a hint only.
 */
final class IpPlace
{
    /**
     * A name for an IP inside a known network, or null.
     *
     * @param  array<string, string>  $networks  CIDR → label
     * @return array{label: string, network: ?string, unusual: bool}|null
     */
    public static function known(string $ip, array $networks): ?array
    {
        foreach ($networks as $cidr => $label) {
            if (self::inCidr($ip, (string) $cidr)) {
                return ['label' => $label, 'network' => null, 'unusual' => false];
            }
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return ['label' => 'Jaringan internal Pemkab', 'network' => null, 'unusual' => false];
        }

        return null;
    }

    /**
     * Turn a geolocation result (secscan IpGeolocator shape) into a place.
     *
     * @param  array{country?: ?string, country_code?: ?string, city?: ?string, org?: ?string}|null  $geo
     * @return array{label: string, network: ?string, unusual: bool}|null
     */
    public static function fromGeo(?array $geo, string $homeCountry = 'ID'): ?array
    {
        $code = strtoupper((string) ($geo['country_code'] ?? ''));

        if ($geo === null || $code === '') {
            return null;
        }

        $city = is_string($geo['city'] ?? null) && $geo['city'] !== '' ? $geo['city'] : null;
        $abroad = $code !== strtoupper($homeCountry);

        $label = $abroad
            ? implode(', ', array_filter([$city, $geo['country'] ?? $code]))
            : ($city !== null ? "sekitar {$city}" : 'Indonesia');

        // ipinfo writes "AS7713 PT Telekomunikasi Indonesia"; the AS number
        // means nothing to the reader.
        $network = is_string($geo['org'] ?? null) ? trim((string) preg_replace('/^AS\d+\s+/', '', $geo['org'])) : null;

        return ['label' => $label, 'network' => $network ?: null, 'unusual' => $abroad];
    }

    private static function inCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton((string) $subnet);

        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $bits = $bits === null ? strlen($ipBin) * 8 : (int) $bits;
        $bytes = intdiv($bits, 8);

        if (substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
            return false;
        }

        $rest = $bits % 8;

        if ($rest === 0) {
            return true;
        }

        $mask = chr((0xFF << (8 - $rest)) & 0xFF);

        return (($ipBin[$bytes] ?? "\0") & $mask) === (($subnetBin[$bytes] ?? "\0") & $mask);
    }
}
