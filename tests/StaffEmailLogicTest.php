<?php

declare(strict_types=1);

namespace Nawasara\AsnPortal\Tests;

use Nawasara\AsnPortal\Support\EmailChange;
use PHPUnit\Framework\TestCase;

/**
 * Email pushed from SIMAS Hebat into the Kisara account.
 */
class StaffEmailLogicTest extends TestCase
{
    public function test_normalize_trims_and_lowercases_a_valid_address(): void
    {
        $this->assertSame('ricky.romdhoni@gmail.com', EmailChange::normalize('  Ricky.Romdhoni@Gmail.com '));
    }

    public function test_normalize_rejects_anything_that_is_not_one_address(): void
    {
        foreach ([null, '', '   ', 'bukan-email', 'a@b', 'a@b.com, c@d.com', "a@b.com\r\nBcc: x@y.com", ['a@b.com'], 123, str_repeat('a', 250).'@b.com'] as $bad) {
            $this->assertNull(EmailChange::normalize($bad), var_export($bad, true));
        }
    }

    public function test_same_address_in_any_case_is_unchanged(): void
    {
        $this->assertSame(EmailChange::UNCHANGED, EmailChange::decide('Ricky@Gmail.com', 'ricky@gmail.com', 'u1', 'u1'));
    }

    public function test_address_used_by_another_account_is_a_conflict_never_a_move(): void
    {
        $this->assertSame(EmailChange::CONFLICT, EmailChange::decide('lama@gmail.com', 'baru@gmail.com', 'someone-else', 'u1'));
        // Even when the account has no email yet.
        $this->assertSame(EmailChange::CONFLICT, EmailChange::decide(null, 'baru@gmail.com', 'someone-else', 'u1'));
    }

    public function test_free_address_is_an_update_including_for_an_account_without_email(): void
    {
        $this->assertSame(EmailChange::UPDATE, EmailChange::decide('lama@gmail.com', 'baru@gmail.com', null, 'u1'));
        $this->assertSame(EmailChange::UPDATE, EmailChange::decide(null, 'baru@gmail.com', null, 'u1'));
    }
}
