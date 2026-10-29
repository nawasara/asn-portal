<?php

declare(strict_types=1);

namespace Nawasara\AsnPortal\Tests;

use InvalidArgumentException;
use Nawasara\AsnPortal\Support\AccountActions;
use Nawasara\AsnPortal\Support\AccountIdentity;
use Nawasara\AsnPortal\Support\LegalDocumentSchedule;
use PHPUnit\Framework\TestCase;

class AccountLogicTest extends TestCase
{
    private const NOW = 1_800_000_000;

    // ---- AccountIdentity ---------------------------------------------------

    public function test_identity_exposes_only_allow_listed_fields(): void
    {
        $identity = AccountIdentity::fromClaims([
            'sub' => 'f1c2', 'name' => 'Dyah Ayu Lestari', 'preferred_username' => '198804122010012003',
            'email' => 'dyah@ponorogo.go.id', 'email_verified' => true, 'auth_time' => self::NOW,
            // Things a future mapper might add; none may leak.
            'realm_access' => ['roles' => ['admin']], 'whatsapp' => '0812', 'sid' => 'session-id',
        ]);

        $this->assertSame(
            ['sub', 'name', 'nip', 'username', 'email', 'email_verified', 'signed_in_at'],
            array_keys($identity),
        );
        $this->assertSame('198804122010012003', $identity['nip']);
        $this->assertSame('2027-01-15T08:00:00Z', $identity['signed_in_at']);
    }

    /** A service account or test user must never be shown as having a NIP. */
    public function test_nip_only_when_username_is_eighteen_digits(): void
    {
        $this->assertNull(AccountIdentity::fromClaims(['preferred_username' => 'service-account-x'])['nip']);
        $this->assertNull(AccountIdentity::fromClaims(['preferred_username' => '1988041220100120'])['nip']);
        $this->assertSame('service-account-x', AccountIdentity::fromClaims(['preferred_username' => 'service-account-x'])['username']);
    }

    public function test_missing_claims_become_nulls_not_errors(): void
    {
        $identity = AccountIdentity::fromClaims([]);

        $this->assertNull($identity['sub']);
        $this->assertNull($identity['signed_in_at']);
        $this->assertFalse($identity['email_verified']);
    }

    // ---- AccountActions ----------------------------------------------------

    public function test_recent_login_gets_the_action_only(): void
    {
        $this->assertSame(
            ['kc_action' => 'webauthn-register-passwordless'],
            AccountActions::authorizeParams('passkey', loginIsRecent: true),
        );
    }

    /** Stale login: re-authentication and the action in ONE redirect. */
    public function test_stale_login_adds_max_age_zero(): void
    {
        $this->assertSame(
            ['kc_action' => 'CONFIGURE_TOTP', 'max_age' => 0],
            AccountActions::authorizeParams('totp', loginIsRecent: false),
        );
    }

    public function test_unknown_action_is_refused(): void
    {
        $this->assertFalse(AccountActions::supports('delete_account'));
        $this->assertFalse(AccountActions::supports('UPDATE_PASSWORD'));

        $this->expectException(InvalidArgumentException::class);
        AccountActions::authorizeParams('delete_account', true);
    }

    // ---- LegalDocumentSchedule ---------------------------------------------

    public function test_latest_effective_version_is_current(): void
    {
        $result = LegalDocumentSchedule::resolve([
            ['id' => 1, 'effective_at' => self::NOW - 200],
            ['id' => 2, 'effective_at' => self::NOW - 100],
        ], self::NOW);

        $this->assertSame(2, $result['current']['id']);
        $this->assertNull($result['upcoming']);
    }

    /** The 14-day announcement: the next version is shown, not applied. */
    public function test_future_version_is_upcoming_not_current(): void
    {
        $result = LegalDocumentSchedule::resolve([
            ['id' => 1, 'effective_at' => self::NOW - 100],
            ['id' => 2, 'effective_at' => self::NOW + 14 * 86_400],
        ], self::NOW);

        $this->assertSame(1, $result['current']['id']);
        $this->assertSame(2, $result['upcoming']['id']);
    }

    public function test_nearest_future_version_is_announced(): void
    {
        $result = LegalDocumentSchedule::resolve([
            ['id' => 3, 'effective_at' => self::NOW + 60 * 86_400],
            ['id' => 2, 'effective_at' => self::NOW + 14 * 86_400],
        ], self::NOW);

        $this->assertNull($result['current']);
        $this->assertSame(2, $result['upcoming']['id']);
    }

    /** Published without a date must never replace the policy in force. */
    public function test_version_without_effective_date_is_ignored(): void
    {
        $result = LegalDocumentSchedule::resolve([
            ['id' => 1, 'effective_at' => self::NOW - 100],
            ['id' => 2, 'effective_at' => null],
        ], self::NOW);

        $this->assertSame(1, $result['current']['id']);
        $this->assertNull($result['upcoming']);
    }

    public function test_first_version_may_take_effect_immediately(): void
    {
        $this->assertSame(self::NOW, LegalDocumentSchedule::earliestEffectiveAt(false, self::NOW));
    }

    /** A replacement binds people who agreed to the old text: 14 days notice. */
    public function test_later_versions_need_fourteen_days_notice(): void
    {
        $this->assertSame(self::NOW + 14 * 86_400, LegalDocumentSchedule::earliestEffectiveAt(true, self::NOW));
    }

    public function test_version_effective_exactly_now_is_current(): void
    {
        $result = LegalDocumentSchedule::resolve([['id' => 1, 'effective_at' => self::NOW]], self::NOW);

        $this->assertSame(1, $result['current']['id']);
    }
}
