<?php

namespace Nawasara\AsnPortal\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The ASN address book for Kisara Mail's recipient suggestions (FSD FR-19).
 *
 * Source: personal mailboxes, i.e. a mailbox linked to exactly ONE NIP in
 * WHM (nawasara_whm_mailbox_links). Shared mailboxes are not people and are
 * left out. Unit comes from the registry employee row with the same NIP.
 *
 * Returns name, unit and email only. The NIP is used for the join and never
 * leaves this class: the address book is visible to every ASN, and a NIP is
 * personal data.
 */
class StaffDirectory
{
    public const LIMIT = 10;

    /** @return list<array{name: string, unit: ?string, email: string}> */
    public function search(string $q): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2 || ! Schema::hasTable('nawasara_whm_mailbox_links')) {
            return [];
        }
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';

        $personal = DB::table('nawasara_whm_mailbox_links')
            ->select('mailbox')
            ->groupBy('mailbox')
            ->havingRaw('COUNT(DISTINCT subject) = 1');

        $rows = DB::table('nawasara_whm_mailbox_links as l')
            ->joinSub($personal, 'p', 'p.mailbox', '=', 'l.mailbox')
            ->when(Schema::hasTable('nawasara_registry_employees'), function ($query) {
                $query->leftJoin('nawasara_registry_employees as e', 'e.nip', '=', 'l.subject')
                    ->leftJoin('nawasara_registry_units as u', 'u.id', '=', 'e.unit_id')
                    ->addSelect(DB::raw('COALESCE(u.name, e.simas_unit_kerja) as unit'));
            })
            ->addSelect('l.mailbox', 'l.display_name')
            ->where(fn ($w) => $w->where('l.display_name', 'like', $like)->orWhere('l.mailbox', 'like', $like))
            ->orderByRaw('CASE WHEN l.display_name LIKE ? THEN 0 ELSE 1 END', [str_replace('%', '', $like).'%'])
            ->orderBy('l.display_name')
            ->limit(self::LIMIT)
            ->get();

        return $rows->map(fn ($r) => [
            'name' => (string) ($r->display_name ?: $r->mailbox),
            'unit' => isset($r->unit) && $r->unit !== '' ? (string) $r->unit : null,
            'email' => strtolower((string) $r->mailbox),
        ])->unique('email')->values()->all();
    }
}
