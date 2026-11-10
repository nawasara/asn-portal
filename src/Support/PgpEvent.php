<?php

namespace Nawasara\AsnPortal\Support;

/**
 * What Kisara Mail does with a mailbox's OpenPGP key, as it appears in the
 * shared mailbox's access history (Kisara Mail PGP FSD FR-12, 03b K-7).
 *
 * Stored as one audit event, `mailbox_pgp_event`, with the type in its
 * properties: the history query and the retention prune then need one
 * event name, and adding a type later needs no migration.
 *
 * The private key itself is opened in the user's browser and never seen by
 * any server; `pgp.copy_fetched` (Kisara Mail handed out the locked copy) is
 * the closest observable step, and is labelled for what it is.
 */
final class PgpEvent
{
    /** type => label in the access history; "%s" is the other holder, when the event has one. */
    public const TYPES = [
        'pgp.key_added' => 'memasang kunci PGP',
        'pgp.key_replaced' => 'mengganti kunci PGP',
        'pgp.copy_fetched' => 'mengambil kunci PGP',
        'pgp.copy_passphrase_changed' => 'mengganti passphrase kunci PGP',
        'pgp.backup_downloaded' => 'mengunduh cadangan kunci PGP',
        'pgp.access_requested' => 'meminta akses kunci PGP',
        'pgp.access_granted' => 'memberi akses kunci PGP kepada %s',
        'pgp.access_rejected' => 'menolak akses kunci PGP untuk %s',
        'pgp.copy_revoked' => 'akses kunci PGP dicabut (tidak lagi memegang kotak email)',
        'pgp.publish_changed' => 'mengubah penerbitan kunci PGP',
    ];

    /** Types that name another holder (who was given or refused access). */
    public const WITH_TARGET = ['pgp.access_granted', 'pgp.access_rejected'];

    public static function isKnown(string $type): bool
    {
        return array_key_exists($type, self::TYPES);
    }

    /** "Kisara Mail · …" for the access history's `via` column. */
    public static function label(string $type, ?string $targetName = null): string
    {
        $text = self::TYPES[$type] ?? 'kunci PGP';

        return 'Kisara Mail · '.(str_contains($text, '%s') ? sprintf($text, $targetName ?: 'pemegang lain') : $text);
    }
}
