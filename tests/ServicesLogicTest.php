<?php

declare(strict_types=1);

namespace Nawasara\AsnPortal\Tests;

use Nawasara\AsnPortal\Http\Api\StorageController;
use Nawasara\AsnPortal\Support\ForwardingPolicy;
use Nawasara\AsnPortal\Support\SiteHost;
use PHPUnit\Framework\TestCase;

/**
 * Stage 4–5: official email, sites, storage.
 */
class ServicesLogicTest extends TestCase
{
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
}
