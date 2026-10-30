<?php

namespace Nawasara\AsnPortal\Services;

use Illuminate\Support\Collection;

/**
 * Which official mailboxes a civil servant may manage from the portal.
 *
 * Two kinds, decided here and nowhere else:
 *
 *   personal  the mailbox whose address equals the account's email in
 *             Keycloak. The person may change its password.
 *   agency    a shared mailbox (kominfo@, ppid.kominfo@) registered as an
 *             `email` asset in the registry, with this person as primary or
 *             backup PIC. Opened without the password; the password is never
 *             given out, so access ends when the PIC assignment ends.
 *
 * Quota and usage come from the WHM email cache (nawasara_whm_email_accounts),
 * not live WHM calls: the list must load in one request even when a server
 * is slow.
 *
 * Soft dependencies (class_exists): without nawasara/whm there are no
 * mailboxes; without nawasara/registry there are no agency mailboxes.
 */
class MailboxDirectory
{
    /**
     * @return Collection<int, array{kind: string, address: string, instance: string, cpanel_user: string, quota_mb: ?int, used_mb: ?float, role: ?string, pics: list<string>, account: object}>
     */
    public function forUser(string $sub, ?string $accountEmail): Collection
    {
        if (! class_exists(\Nawasara\Whm\Models\WhmEmailAccount::class)) {
            return collect();
        }

        $mailboxes = collect();

        if (is_string($accountEmail) && $accountEmail !== '') {
            foreach ($this->accounts([$accountEmail]) as $account) {
                $mailboxes->push($this->row($account, 'personal', null, []));
            }
        }

        foreach ($this->agencyAssets($sub) as $asset) {
            $address = strtolower($asset->identifier);

            if ($mailboxes->contains(fn ($m) => $m['address'] === $address)) {
                continue;
            }

            $pics = $asset->assignments
                ->sortBy(fn ($a) => $a->role === 'primary' ? 0 : 1)
                ->map(fn ($a) => $a->user?->name)
                ->filter()
                ->values()
                ->all();

            foreach ($this->accounts([$address]) as $account) {
                $mailboxes->push($this->row($account, 'agency', $asset->my_role, $pics));
            }
        }

        return $mailboxes->values();
    }

    /** The mailbox with this address, only if the user may manage it. */
    public function find(string $sub, ?string $accountEmail, string $address): ?array
    {
        $address = strtolower(trim($address));

        return $this->forUser($sub, $accountEmail)->first(fn ($m) => $m['address'] === $address);
    }

    /** @return iterable<object> */
    private function accounts(array $addresses): iterable
    {
        return \Nawasara\Whm\Models\WhmEmailAccount::query()
            ->whereIn('email', array_map('strtolower', $addresses))
            ->get();
    }

    private function agencyAssets(string $sub): Collection
    {
        if (! class_exists(\Nawasara\Registry\Services\AssetAssignmentService::class)) {
            return collect();
        }

        return app(\Nawasara\Registry\Services\AssetAssignmentService::class)->assetsForKeycloakSub($sub, ['email']);
    }

    private function row(object $account, string $kind, ?string $role, array $pics): array
    {
        return [
            'kind' => $kind,
            'address' => strtolower($account->email),
            'instance' => (string) $account->instance,
            'cpanel_user' => (string) $account->cpanel_user,
            'quota_mb' => $account->quota_mb !== null ? (int) $account->quota_mb : null,
            'used_mb' => $account->disk_used_mb !== null ? round((float) $account->disk_used_mb, 1) : null,
            'role' => $role,
            'pics' => $pics,
            'account' => $account,
        ];
    }
}
