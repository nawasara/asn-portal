<?php

declare(strict_types=1);

namespace Nawasara\AsnPortal\Tests;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Nawasara\AsnPortal\Services\OtpChallenge;
use Nawasara\AsnPortal\Support\ActivityPresenter;
use Nawasara\AsnPortal\Support\CredentialSummary;
use Nawasara\AsnPortal\Support\PhoneNumber;
use Nawasara\AsnPortal\Support\SessionPresenter;
use PHPUnit\Framework\TestCase;

class SecurityLogicTest extends TestCase
{
    private const CREDENTIALS = [
        ['id' => 'pw', 'type' => 'password', 'createdDate' => 1_700_000_000_000],
        ['id' => 'otp-1', 'type' => 'otp', 'userLabel' => 'Ponsel Samsung', 'createdDate' => 1_760_000_000_000,
            'credentialData' => '{"subType":"totp"}'],
        ['id' => 'pk-1', 'type' => 'webauthn-passwordless', 'userLabel' => 'iPhone',
            'credentialData' => '{"credentialId":"abc","aaguid":"x"}'],
    ];

    // ---- CredentialSummary -------------------------------------------------

    /** The password belongs to SimasHebat; credentialData never leaves. */
    public function test_present_hides_password_and_raw_data(): void
    {
        $out = CredentialSummary::present(self::CREDENTIALS);

        $this->assertSame(['otp-1', 'pk-1'], array_column($out, 'id'));
        $this->assertSame(['id', 'type', 'label', 'created_at'], array_keys($out[0]));
        $this->assertSame('totp', $out[0]['type']);
        $this->assertSame('passkey', $out[1]['type']);
        $this->assertSame('2025-10-09T08:53:20Z', $out[0]['created_at']);
    }

    public function test_summary_suggests_what_is_missing(): void
    {
        $s = CredentialSummary::summarize(self::CREDENTIALS, hasRecoveryContact: true);

        $this->assertSame(1, $s['passkeys']);
        $this->assertSame(1, $s['authenticators']);
        $this->assertSame(['create_backup_codes'], $s['suggestions']);

        $none = CredentialSummary::summarize([['id' => 'pw', 'type' => 'password']], hasRecoveryContact: false);
        $this->assertSame(['add_passkey', 'add_authenticator', 'create_backup_codes', 'add_recovery_contact'], $none['suggestions']);
    }

    /** Two-step verification is mandatory: the last authenticator stays. */
    public function test_last_authenticator_cannot_be_removed(): void
    {
        $this->assertSame('last_authenticator', CredentialSummary::removalBlockedReason(self::CREDENTIALS, 'otp-1'));

        $two = [...self::CREDENTIALS, ['id' => 'otp-2', 'type' => 'otp']];
        $this->assertNull(CredentialSummary::removalBlockedReason($two, 'otp-1'));
    }

    public function test_password_and_foreign_ids_are_not_removable(): void
    {
        $this->assertSame('not_found', CredentialSummary::removalBlockedReason(self::CREDENTIALS, 'pw'));
        $this->assertSame('not_found', CredentialSummary::removalBlockedReason(self::CREDENTIALS, 'someone-elses'));
        $this->assertNull(CredentialSummary::removalBlockedReason(self::CREDENTIALS, 'pk-1'));
    }

    public function test_ownership_check(): void
    {
        $this->assertTrue(CredentialSummary::owns(self::CREDENTIALS, 'pk-1'));
        $this->assertFalse(CredentialSummary::owns(self::CREDENTIALS, 'pw'));
        $this->assertFalse(CredentialSummary::owns(self::CREDENTIALS, 'nope'));
    }

    // ---- SessionPresenter --------------------------------------------------

    public function test_sessions_named_from_catalog_and_current_first(): void
    {
        $sessions = [
            ['id' => 's-old', 'ipAddress' => '10.0.0.2', 'start' => 1_000, 'lastAccess' => 2_000_000, 'clients' => ['u1' => 'sadap']],
            ['id' => 's-me', 'ipAddress' => '10.0.0.1', 'start' => 1_000, 'lastAccess' => 1_000_000, 'clients' => ['u2' => 'asn-portal']],
        ];

        $out = SessionPresenter::present($sessions, ['sadap' => 'Satu Data Ponorogo'], 's-me');

        $this->assertSame('s-me', $out[0]['id']);
        $this->assertTrue($out[0]['current']);
        $this->assertSame('asn-portal', $out[0]['apps'][0]['name'], 'unknown clients fall back to their id');
        $this->assertSame('Satu Data Ponorogo', $out[1]['apps'][0]['name']);
        $this->assertTrue(SessionPresenter::owns($sessions, 's-old'));
        $this->assertFalse(SessionPresenter::owns($sessions, 's-other-user'));
    }

    // ---- ActivityPresenter -------------------------------------------------

    public function test_activity_merges_sources_newest_first_and_drops_noise(): void
    {
        $kc = [
            ['type' => 'LOGIN', 'time' => 1_800_000_000_000, 'clientId' => 'sadap', 'ipAddress' => '1.1.1.1'],
            ['type' => 'CODE_TO_TOKEN', 'time' => 1_800_000_001_000],
            ['type' => 'LOGIN_ERROR', 'time' => 1_799_000_000_000, 'error' => 'invalid_user_credentials'],
        ];
        $portal = [['event' => 'whatsapp_changed', 'at' => 1_800_000_500, 'ip' => '2.2.2.2']];

        $out = ActivityPresenter::merge($kc, $portal, ['sadap' => 'Satu Data Ponorogo']);

        $this->assertCount(3, $out);
        $this->assertSame('Nomor WhatsApp diubah', $out[0]['label']);
        $this->assertSame('Satu Data Ponorogo', $out[1]['app']);
        $this->assertSame('invalid_user_credentials', $out[2]['detail']);
    }

    // ---- PhoneNumber -------------------------------------------------------

    public function test_phone_numbers_are_normalised_like_the_old_portal(): void
    {
        $this->assertSame('6285736676648', PhoneNumber::normalize('0857-3667-6648'));
        $this->assertSame('6285736676648', PhoneNumber::normalize('+62 857 3667 6648'));
        $this->assertSame('6285736676648', PhoneNumber::normalize('6285736676648'));
        $this->assertNull(PhoneNumber::normalize('0352481113'), 'landline is not a WhatsApp number');
        $this->assertNull(PhoneNumber::normalize('08123'));
    }

    public function test_masking_never_shows_the_middle(): void
    {
        $this->assertSame('0857 •••• 6648', PhoneNumber::mask('6285736676648'));
        $this->assertSame('dy•••@ponorogo.go.id', PhoneNumber::maskEmail('dyah.lestari@ponorogo.go.id'));
        $this->assertNull(PhoneNumber::mask(null));
    }

    // ---- OtpChallenge ------------------------------------------------------

    private function otp(int $maxSends = 3, int $maxVerify = 5): OtpChallenge
    {
        return new OtpChallenge(new Repository(new ArrayStore), 'secret', 15, $maxSends, 10, $maxVerify);
    }

    public function test_code_verifies_once(): void
    {
        $otp = $this->otp();
        $code = $otp->issue('mfa', '198804122010012003', '203.0.113.5');

        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->assertSame('valid', $otp->verify('mfa', '198804122010012003', $code));
        $this->assertSame('expired', $otp->verify('mfa', '198804122010012003', $code), 'a code is consumed on use');
    }

    /** The old portal had no limit: a 6-digit code could be brute-forced. */
    public function test_too_many_wrong_attempts_burn_the_code(): void
    {
        $otp = $this->otp(maxVerify: 3);
        $code = $otp->issue('mfa', 'nip', '203.0.113.5');
        $wrong = $code === '000000' ? '111111' : '000000';

        $this->assertSame('invalid', $otp->verify('mfa', 'nip', $wrong));
        $this->assertSame('invalid', $otp->verify('mfa', 'nip', $wrong));
        $this->assertSame('expired', $otp->verify('mfa', 'nip', $wrong));
        $this->assertSame('expired', $otp->verify('mfa', 'nip', $code), 'even the right code is dead now');
    }

    public function test_code_is_bound_to_identity_and_purpose(): void
    {
        $otp = $this->otp();
        $code = $otp->issue('mfa', 'nip-a', '203.0.113.5');

        $this->assertSame('expired', $otp->verify('mfa', 'nip-b', $code));
        $this->assertSame('expired', $otp->verify('whatsapp', 'nip-a', $code));
        $this->assertSame('valid', $otp->verify('mfa', 'nip-a', $code));
    }

    /**
     * Plan §4b: the limit is per identity AND real IP. Two people behind the
     * portal server must not share one counter.
     */
    public function test_send_limit_is_per_identity_and_ip(): void
    {
        $otp = $this->otp(maxSends: 2);

        foreach ([1, 2] as $_) {
            $this->assertNotNull($otp->issue('mfa', 'nip-a', '203.0.113.5'));
            $otp->markSent('mfa', 'nip-a', '203.0.113.5');
        }

        $this->assertNull($otp->issue('mfa', 'nip-a', '203.0.113.5'));
        $this->assertNotNull($otp->issue('mfa', 'nip-a', '198.51.100.7'), 'another IP has its own counter');
        $this->assertNotNull($otp->issue('mfa', 'nip-b', '203.0.113.5'), 'another NIP has its own counter');
    }

    /** A delivery that failed must not use up an attempt. */
    public function test_unsent_codes_do_not_count(): void
    {
        $otp = $this->otp(maxSends: 1);

        $otp->issue('mfa', 'nip', '203.0.113.5');
        $otp->discard('mfa', 'nip');

        $this->assertNotNull($otp->issue('mfa', 'nip', '203.0.113.5'));
    }

    public function test_backup_codes_off_hides_action_and_suggestion(): void
    {
        $off = CredentialSummary::summarize([['id' => 'pw', 'type' => 'password']], hasRecoveryContact: true, backupCodesSupported: false);
        $this->assertNotContains('create_backup_codes', $off['suggestions']);
        $this->assertFalse($off['backup_codes_supported']);

        $this->assertFalse(\Nawasara\AsnPortal\Support\AccountActions::supports('backup-codes', false));
        $this->assertTrue(\Nawasara\AsnPortal\Support\AccountActions::supports('totp', false));
        $this->assertTrue(\Nawasara\AsnPortal\Support\AccountActions::supports('backup-codes'));
    }
}
