<?php

declare(strict_types=1);

namespace Nawasara\AsnPortal\Tests;

use Nawasara\AsnPortal\Http\Api\MailServiceController;
use Nawasara\AsnPortal\Http\Api\StorageController;
use Nawasara\AsnPortal\Services\MailboxDirectory;
use Nawasara\AsnPortal\Services\MailUsage;
use Nawasara\AsnPortal\Services\KisaraMailHealth;
use Carbon\CarbonImmutable;
use Nawasara\AsnPortal\Support\ForwardingPolicy;
use Nawasara\AsnPortal\Support\SiteHost;
use PHPUnit\Framework\TestCase;

/**
 * Stage 4–5: official email, sites, storage.
 */
class ServicesLogicTest extends TestCase
{
    public function test_kisara_mail_imap_alert_needs_enough_logins_and_more_than_the_ratio(): void
    {
        $this->assertSame('quiet', KisaraMailHealth::assess(5, 5, 0.05, 10));
        $this->assertSame('ok', KisaraMailHealth::assess(100, 5, 0.05, 10));
        $this->assertSame('failing', KisaraMailHealth::assess(100, 6, 0.05, 10));
        $this->assertSame('failing', KisaraMailHealth::assess(10, 10, 0.05, 10));
    }

    public function test_mail_usage_counts_openings_and_unique_staff_per_channel_per_wib_week(): void
    {
        $now = CarbonImmutable::parse('2026-11-07 10:00', 'Asia/Jakarta'); // Saturday
        $at = fn (string $wib) => CarbonImmutable::parse($wib, 'Asia/Jakarta')->utc();
        $rows = [
            ['at' => $at('2026-11-02 00:30'), 'event' => 'webmail_opened', 'channel' => null, 'sub' => 'a'],  // Monday 00:30 WIB = Sunday UTC
            ['at' => $at('2026-11-03 09:00'), 'event' => 'webmail_opened', 'channel' => null, 'sub' => 'a'],
            ['at' => $at('2026-11-04 09:00'), 'event' => 'mailbox_opened', 'channel' => 'kisara-mail', 'sub' => 'a'],
            ['at' => $at('2026-11-05 09:00'), 'event' => 'mailbox_opened', 'channel' => 'kisara-mail', 'sub' => 'b'],
            ['at' => $at('2026-11-05 10:00'), 'event' => 'mailbox_opened', 'channel' => 'other', 'sub' => 'c'],
            ['at' => $at('2026-11-01 23:00'), 'event' => 'webmail_opened', 'channel' => null, 'sub' => 'd'],  // previous week
            ['at' => $at('2026-01-01 09:00'), 'event' => 'webmail_opened', 'channel' => null, 'sub' => 'e'],  // outside the range
        ];

        $out = MailUsage::aggregate($rows, $now, 3);

        $this->assertSame(['2026-11-02', '2026-10-26', '2026-10-19'], array_column($out, 'week'));
        $this->assertSame(['week' => '2026-11-02', 'roundcube_opens' => 2, 'roundcube_users' => 1, 'kisara_opens' => 2, 'kisara_users' => 2], $out[0]);
        $this->assertSame(1, $out[1]['roundcube_opens']);
        $this->assertSame(0, $out[2]['roundcube_opens'] + $out[2]['kisara_opens']);
    }

    public function test_holdings_answers_yes_or_no_per_pair_with_one_lookup_per_person(): void
    {
        $lookups = [];
        $directory = function (string $sub, ?string $nip) use (&$lookups): array {
            $lookups[] = $sub.'|'.$nip;

            return $sub === 'pringgo' ? ['Pringgo@ponorogo.go.id', 'aptika@ponorogo.go.id'] : [];
        };

        $out = MailServiceController::answer([
            ['sub' => 'pringgo', 'nip' => '198001012010011001', 'mailbox' => 'pringgo@ponorogo.go.id'],
            ['sub' => 'pringgo', 'nip' => '198001012010011001', 'mailbox' => 'APTIKA@ponorogo.go.id'],
            ['sub' => 'pringgo', 'nip' => '198001012010011001', 'mailbox' => 'dicabut@ponorogo.go.id'],
            ['sub' => 'lain', 'mailbox' => 'aptika@ponorogo.go.id'],
        ], $directory);

        $this->assertSame([true, true, false, false], array_column($out, 'held'));
        $this->assertSame('aptika@ponorogo.go.id', $out[1]['mailbox']);
        $this->assertSame(['pringgo|198001012010011001', 'lain|'], $lookups);
    }

    public function test_kisara_mail_sees_only_address_kind_and_role(): void
    {
        $row = [
            'address' => 'aptika@ponorogo.go.id',
            'kind' => 'agency',
            'role' => 'PIC',
            'instance' => 'Ryder',
            'pics' => [['sub' => 'other-sub', 'name' => 'Rekan']],
            'quota_mb' => 5120,
            'used_mb' => 4700,
        ];

        $this->assertSame(
            ['address' => 'aptika@ponorogo.go.id', 'kind' => 'shared', 'role' => 'PIC'],
            \Nawasara\AsnPortal\Http\Api\MailController::present($row),
        );
        $this->assertSame('personal', \Nawasara\AsnPortal\Http\Api\MailController::present(['address' => 'a@ponorogo.go.id', 'kind' => 'personal', 'role' => null])['kind']);
    }

    public function test_kisara_mail_openings_are_labelled_and_kept_with_webmail_openings(): void
    {
        $this->assertSame('Membuka email di Kisara Mail', \Nawasara\AsnPortal\Support\ActivityPresenter::PORTAL_EVENTS['mailbox_opened'][0]);
        // Both channels feed the shared mailbox's access history and share the 1-year retention.
        $this->assertSame(['webmail_opened', 'mailbox_opened'], \Nawasara\AsnPortal\Services\PortalAudit::MAILBOX_OPEN_EVENTS);
        $this->assertSame(365, \Nawasara\AsnPortal\Services\PortalAudit::MAILBOX_OPEN_RETENTION_DAYS);
    }

    public function test_site_host_normalises_every_spelling_to_one(): void
    {
        foreach ([
            'kominfo.ponorogo.go.id',
            'https://kominfo.ponorogo.go.id',
            'https://WWW.Kominfo.Ponorogo.go.id:443/berita?x=1',
            'http://www.kominfo.ponorogo.go.id/',
            'kominfo.ponorogo.go.id.',
        ] as $spelling) {
            $this->assertSame('kominfo.ponorogo.go.id', SiteHost::normalize($spelling), $spelling);
        }
    }

    public function test_site_host_rejects_what_is_not_a_host(): void
    {
        foreach (['', null, '10.1.1.30', 'https://10.1.1.30:8443', 'kominfo@ponorogo.go.id', 'localhost', 'bad host.go.id'] as $value) {
            $this->assertNull(SiteHost::normalize($value), var_export($value, true));
        }
    }

    public function test_forwarding_only_to_allowed_domains_and_their_subdomains(): void
    {
        $allowed = ['ponorogo.go.id'];

        $this->assertTrue(ForwardingPolicy::allows('pringgo@ponorogo.go.id', $allowed));
        $this->assertTrue(ForwardingPolicy::allows('PPID@Kominfo.Ponorogo.go.id', $allowed));
        $this->assertFalse(ForwardingPolicy::allows('pringgo@gmail.com', $allowed));
        // Suffix tricks must not pass.
        $this->assertFalse(ForwardingPolicy::allows('x@evilponorogo.go.id', $allowed));
        $this->assertFalse(ForwardingPolicy::allows('x@ponorogo.go.id.evil.com', $allowed));
        $this->assertFalse(ForwardingPolicy::allows('not-an-email', $allowed));
        $this->assertFalse(ForwardingPolicy::allows('x@ponorogo.go.id', []));
    }

    public function test_mailbox_password_rules(): void
    {
        $this->assertNotNull(ForwardingPolicy::passwordProblem('pendek', 'pringgo@ponorogo.go.id'));
        $this->assertNotNull(ForwardingPolicy::passwordProblem('Pringgo2026!!xyz', 'pringgo@ponorogo.go.id'));
        $this->assertNotNull(ForwardingPolicy::passwordProblem('aaaaaaaaaaaaaa', 'x@ponorogo.go.id'));
        $this->assertNull(ForwardingPolicy::passwordProblem('kuda-lari-cepat-42', 'pringgo@ponorogo.go.id'));
    }

    public function test_storage_percent(): void
    {
        $this->assertSame(50, StorageController::percent(512.0, 1024));
        $this->assertSame(100, StorageController::percent(2048.0, 1024));
        $this->assertSame(0, StorageController::percent(null, 1024));
        // Unlimited is not 0%.
        $this->assertNull(StorageController::percent(500.0, 0));
        $this->assertNull(StorageController::percent(500.0, null));
    }

    public function test_holder_names_never_show_the_nip(): void
    {
        $this->assertSame('Raras Widayanti', MailboxDirectory::holderName('197405091998072001 - Raras Widayanti', '197405091998072001'));
        $this->assertSame('Pegawai', MailboxDirectory::holderName('197405091998072001', '197405091998072001'));
        $this->assertSame('Pegawai', MailboxDirectory::holderName(null, '197405091998072001'));
        $this->assertSame('Admin Kominfo', MailboxDirectory::holderName('Admin Kominfo', 'x'));
    }

    public function test_pgp_events_read_as_kisara_mail_actions_in_the_access_history(): void
    {
        $via = fn (string $event, ?string $type = null, ?string $target = null) => \Nawasara\AsnPortal\Http\Api\MailboxController::via($event, $type, $target);

        $this->assertSame('Kisara Mail', $via('mailbox_opened'));
        $this->assertSame('Webmail', $via('webmail_opened'));
        $this->assertSame('Kisara Mail · mengambil kunci PGP', $via('mailbox_pgp_event', 'pgp.copy_fetched'));
        $this->assertSame('Kisara Mail · memberi akses kunci PGP kepada Satrio', $via('mailbox_pgp_event', 'pgp.access_granted', 'Satrio'));
        $this->assertSame('Kisara Mail · menolak akses kunci PGP untuk pemegang lain', $via('mailbox_pgp_event', 'pgp.access_rejected'));
        // An unknown type (newer Kisara Mail, older Nawasara) still reads as a key event, never as webmail.
        $this->assertSame('Kisara Mail · kunci PGP', $via('mailbox_pgp_event', 'pgp.something_new'));
    }

    public function test_pgp_event_types_are_an_allow_list_matching_kisara_mail(): void
    {
        // Kisara Mail PGP FSD FR-12 + pgp.publish_changed (FR-22).
        $this->assertSame([
            'pgp.key_added', 'pgp.key_replaced', 'pgp.copy_fetched', 'pgp.copy_passphrase_changed', 'pgp.backup_downloaded',
            'pgp.access_requested', 'pgp.access_granted', 'pgp.access_rejected', 'pgp.copy_revoked', 'pgp.publish_changed',
        ], array_keys(\Nawasara\AsnPortal\Support\PgpEvent::TYPES));
        $this->assertFalse(\Nawasara\AsnPortal\Support\PgpEvent::isKnown('mailbox_opened'));
    }

    public function test_key_events_share_the_access_history_and_its_retention_but_not_usage_counts(): void
    {
        $audit = \Nawasara\AsnPortal\Services\PortalAudit::class;
        $this->assertSame(['webmail_opened', 'mailbox_opened', 'mailbox_pgp_event'], $audit::MAILBOX_ACCESS_EVENTS);
        // MailUsage counts openings only; a key fetch is not a visit.
        $this->assertNotContains('mailbox_pgp_event', $audit::MAILBOX_OPEN_EVENTS);
        $this->assertSame('Kunci PGP email dinas (Kisara Mail)', \Nawasara\AsnPortal\Support\ActivityPresenter::PORTAL_EVENTS['mailbox_pgp_event'][0]);
    }
}
