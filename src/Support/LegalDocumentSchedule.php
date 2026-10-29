<?php

namespace Nawasara\AsnPortal\Support;

/**
 * Decide which version of a legal document is in force, and which one is
 * announced but not yet in force.
 *
 * Pure function over published versions so it can be tested without a
 * database, and so the API and the admin panel agree on the answer.
 */
final class LegalDocumentSchedule
{
    /**
     * @template T of array{effective_at: ?int}
     *
     * @param  iterable<T>  $published  versions that have a published_at;
     *                                  effective_at as a unix timestamp
     * @return array{current: ?T, upcoming: ?T}
     */
    public static function resolve(iterable $published, int $now): array
    {
        $current = null;
        $upcoming = null;

        foreach ($published as $version) {
            $effective = $version['effective_at'] ?? null;

            // Published without a date is treated as not yet scheduled:
            // never shown as in force, so a half-filled publish cannot
            // replace the policy users are bound by.
            if ($effective === null) {
                continue;
            }

            if ($effective <= $now) {
                if ($current === null || $effective > $current['effective_at']) {
                    $current = $version;
                }
            } elseif ($upcoming === null || $effective < $upcoming['effective_at']) {
                // The NEAREST future version is the one to announce.
                $upcoming = $version;
            }
        }

        return ['current' => $current, 'upcoming' => $upcoming];
    }
}
