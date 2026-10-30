<?php

namespace Nawasara\AsnPortal\Services;

use Illuminate\Support\Collection;

/**
 * Which official mailboxes a civil servant may manage from the portal.
 *
 * Source of truth: cPanel External Authentication on the mail server, where
 * the mail team links each mailbox to a Kisara identity whose subject is the
 * NIP (nawasara/whm mirrors them into nawasara_whm_mailbox_links). Registry
 * PIC assignments of `email` assets are added on top, for shared mailboxes
 * registered there but not linked yet.
 *
 * Two kinds, decided here and nowhere else:
 *
 *   personal  linked to this NIP and to nobody else. The owner may change
 *             its password.
 *   agency    shared: linked to several NIPs, or reached through a registry
 *             PIC assignment. Opened without the password, so access ends
 *             when the link or the assignment ends; every opening is visible
 *             to the other holders.
 *
 * The Keycloak account email is NOT used: for many ASN it is a private Gmail
 * address, and matching on it would hand mailboxes to the wrong person.
 *
 * Quota and usage come from the WHM email cache, not live WHM calls.
 * Soft dependencies (class_exists): without nawasara/whm there are no
 * mailboxes; without nawasara/registry there are no registry mailboxes.
 */
class MailboxDirectory
{
    /**
     * @return Collection<int, array{kind: string, address: string, instance: string, cpanel_user: string, quota_mb: ?int, used_mb: ?float, role: ?string, pics: list<string>, account: object}>
     */
    public function forUser(string $sub, ?string $nip): Collection
    {
        if (! class_exists(\Nawasara\Whm\Models\WhmEmailAccount::class)) {
            return collect();
        }

        /** @var array<string, array{kind: string, role: ?string, pics: list<string>}> $found */
        $found = [];

        foreach ($this->linkedMailboxes($nip) as $address => $holders) {
            $found[$address] = [
                'kind' => count($holders) === 1 ? 'personal' : 'agency',
                'role' => null,
                'pics' => count($holders) > 1 ? $holders : [],
            ];
        }

        foreach ($this->registryMailboxes($sub) as $address => $asset) {
            $found[$address] = [
                'kind' => 'agency',
                'role' => $asset['role'],
                'pics' => array_values(array_unique(array_merge($found[$address]['pics'] ?? [], $asset['pics']))),
            ];
        }

        if ($found === []) {
            return collect();
        }

        $accounts = \Nawasara\Whm\Models\WhmEmailAccount::query()
            ->whereIn('email', array_keys($found))
            ->get()
            ->keyBy(fn ($a) => strtolower($a->email));

        // personal first, then shared, each alphabetical
        return collect($found)
            ->filter(fn ($_, string $address) => $accounts->has($address))
            ->map(fn (array $m, string $address) => $this->row($accounts[$address], $m))
            ->sortBy(fn (array $m) => ($m['kind'] === 'personal' ? '0' : '1').$m['address'])
            ->values();
    }

    /** The mailbox with this address, only if the user may manage it. */
    public function find(string $sub, ?string $nip, string $address): ?array
    {
        $address = strtolower(trim($address));

        return $this->forUser($sub, $nip)->first(fn ($m) => $m['address'] === $address);
    }

    /**
     * Mailboxes linked to this NIP, each with the names of everyone linked
     * to it.
     *
     * @return array<string, list<string>>
     */
    private function linkedMailboxes(?string $nip): array
    {
        if ($nip === null || $nip === '' || ! class_exists(\Nawasara\Whm\Models\WhmMailboxLink::class)) {
            return [];
        }

        $mine = \Nawasara\Whm\Models\WhmMailboxLink::query()->where('subject', $nip)->pluck('mailbox')->unique();

        if ($mine->isEmpty()) {
            return [];
        }

        return \Nawasara\Whm\Models\WhmMailboxLink::query()
            ->whereIn('mailbox', $mine)
            ->get(['mailbox', 'subject', 'display_name'])
            ->groupBy('mailbox')
            ->map(fn (Collection $links) => $links->unique('subject')->map(fn ($l) => self::holderName($l->display_name, $l->subject))->values()->all())
            ->all();
    }

    /** @return array<string, array{role: ?string, pics: list<string>}> */
    private function registryMailboxes(string $sub): array
    {
        if (! class_exists(\Nawasara\Registry\Services\AssetAssignmentService::class)) {
            return [];
        }

        $out = [];

        foreach (app(\Nawasara\Registry\Services\AssetAssignmentService::class)->assetsForKeycloakSub($sub, ['email']) as $asset) {
            $out[strtolower($asset->identifier)] = [
                'role' => $asset->my_role,
                'pics' => $asset->assignments
                    ->sortBy(fn ($a) => $a->role === 'primary' ? 0 : 1)
                    ->map(fn ($a) => $a->user?->name)
                    ->filter()->values()->all(),
            ];
        }

        return $out;
    }

    /**
     * "197405091998072001 - Raras Widayanti" → "Raras Widayanti". cPanel
     * stores Keycloak's preferred_username, which on this realm is the NIP
     * followed by the name; a NIP is never shown to the other holders.
     */
    public static function holderName(?string $displayName, string $subject): string
    {
        $name = trim(preg_replace('/^\d{18}\s*-\s*/', '', (string) $displayName) ?? '');

        return $name !== '' && ! preg_match('/^\d+$/', $name) ? $name : 'Pegawai';
    }

    private function row(object $account, array $m): array
    {
        return [
            'kind' => $m['kind'],
            'address' => strtolower($account->email),
            'instance' => (string) $account->instance,
            'cpanel_user' => (string) $account->cpanel_user,
            'quota_mb' => $account->quota_mb !== null ? (int) $account->quota_mb : null,
            'used_mb' => $account->disk_used_mb !== null ? round((float) $account->disk_used_mb, 1) : null,
            'role' => $m['role'],
            'pics' => $m['pics'],
            'account' => $account,
        ];
    }
}
