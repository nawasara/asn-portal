<?php

namespace Nawasara\AsnPortal\Database\Seeders;

use Illuminate\Database\Seeder;
use Nawasara\AsnPortal\Models\HelpItem;
use Nawasara\AsnPortal\Models\PortalApp;

/**
 * Initial content, copied from the old Laravel portal (subapps/sso/portal):
 * AppsService::getApps() and the Faq Livewire component.
 *
 * Idempotent by slug and never overwrites: once an admin has edited an entry
 * in the panel, running the seeder again must not undo it.
 *
 * Answers were HTML in the old portal (raw <br> and <a>); here they are
 * Markdown. Keycloak client ids are left empty on purpose: they must be
 * checked against the realm, not guessed.
 */
class ContentSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->apps() as $order => $app) {
            PortalApp::firstOrCreate(['slug' => $app['slug']], $app + ['sort_order' => ($order + 1) * 10]);
        }

        foreach ($this->helpItems() as $order => $item) {
            HelpItem::firstOrCreate(['slug' => $item['slug']], $item + ['sort_order' => ($order + 1) * 10]);
        }
    }

    /** @return list<array<string, string>> */
    private function apps(): array
    {
        return [
            ['slug' => 'simashebat', 'name' => 'Simashebat', 'abbreviation' => 'SH', 'category' => 'Kepegawaian',
                'description' => 'Sistem Informasi Manajemen ASN Pemerintah Kabupaten Ponorogo',
                'url' => 'https://simashebat.ponorogo.go.id/login/sso/'],
            ['slug' => 'supergratifikasi', 'name' => 'Supergratifikasi', 'abbreviation' => 'SG', 'category' => 'Integritas',
                'description' => 'Aplikasi pengendalian gratifikasi di lingkungan Pemkab Ponorogo',
                'url' => 'https://supergratifikasi.ponorogo.go.id/login/sso'],
            ['slug' => 'rakaca', 'name' => 'Rakaca', 'abbreviation' => 'RK', 'category' => 'Layanan TI',
                'description' => 'Etalase layanan teknologi informasi dan infrastruktur Bidang Aplikasi dan Informatika Dinas Kominfo dan Statistik',
                'url' => 'https://rakaca.ponorogo.go.id/login'],
            ['slug' => 'sipras', 'name' => 'Sipras', 'abbreviation' => 'SP', 'category' => 'Geospasial',
                'description' => 'Aplikasi pengelolaan data peta dan koordinat untuk perangkat daerah di lingkungan Pemkab Ponorogo',
                'url' => 'https://sipras.ponorogo.go.id/admin'],
            ['slug' => 'sadap', 'name' => 'Satu Data Ponorogo (Sadap)', 'abbreviation' => 'SD', 'category' => 'Data',
                'description' => 'Portal satu data Pemerintah Kabupaten Ponorogo',
                'url' => 'https://sadap.ponorogo.go.id/sso'],
        ];
    }

    /** @return list<array<string, string>> */
    private function helpItems(): array
    {
        return [
            ['slug' => 'sso-mfa', 'kind' => HelpItem::KIND_FAQ,
                'question' => 'Apa itu Single Sign On dan Multi-Factor Authentication?',
                'answer' => "SSO (Single Sign-On, Masuk Tunggal) memungkinkan Anda membuka banyak aplikasi dengan satu kali masuk dan satu akun. Dengan SSO, ASN Ponorogo dapat membuka layanan milik Pemerintah Kabupaten Ponorogo tanpa berganti-ganti akun.\n\nMFA (Multi-Factor Authentication, Autentikasi Multifaktor) adalah metode keamanan yang memerlukan dua langkah verifikasi atau lebih untuk masuk ke akun."],
            ['slug' => 'portal-asn', 'kind' => HelpItem::KIND_FAQ,
                'question' => 'Apa itu Portal ASN Ponorogo?',
                'answer' => 'Portal ASN Ponorogo adalah portal milik Pemerintah Kabupaten Ponorogo yang berisi layanan untuk ASN, sehingga ASN mudah mengakses layanan daerah yang memakai SSO.'],
            ['slug' => 'kisara-sso', 'kind' => HelpItem::KIND_FAQ,
                'question' => 'Apa itu Kisara?',
                'answer' => 'Kisara adalah layanan SSO milik Pemerintah Kabupaten Ponorogo yang dikelola Dinas Komunikasi, Informatika dan Statistik Kabupaten Ponorogo.'],
            ['slug' => 'data-simas', 'kind' => HelpItem::KIND_FAQ,
                'question' => 'Mengapa memakai akun SimasHebat?',
                'answer' => 'SimasHebat adalah sistem informasi kepegawaian Pemerintah Kabupaten Ponorogo. Seluruh data ASN ada di SimasHebat, jadi untuk memudahkan, Anda masuk dengan akun SimasHebat.'],
            ['slug' => 'lupa-password', 'kind' => HelpItem::KIND_SIGN_IN_HELP,
                'question' => 'Saya tidak bisa masuk dengan akun SimasHebat',
                'answer' => "Untuk lupa kata sandi, silakan berkoordinasi dengan BKPSDM, atau atur ulang sendiri lewat halaman lupa kata sandi SimasHebat.\n\nPastikan Anda bisa membuka email Anda dan kotak emailnya tidak penuh, supaya kata sandi pemulihan dapat diterima.",
                'action_label' => 'Lupa kata sandi SimasHebat',
                'action_url' => 'https://simashebat.ponorogo.go.id/reset-password/'],
            ['slug' => 'invalid-credentials', 'kind' => HelpItem::KIND_SIGN_IN_HELP,
                'question' => 'Muncul "invalid_credentials" di halaman masuk',
                'answer' => 'Pesan ini muncul karena NIP atau kata sandi tidak sesuai. Mohon periksa kembali NIP dan kata sandi Anda.'],
            ['slug' => 'kode-otp-salah', 'kind' => HelpItem::KIND_SIGN_IN_HELP,
                'question' => 'Muncul "OTP tidak valid" saat memindai kode QR',
                'answer' => "Periksa pengaturan waktu di ponsel Anda: aktifkan waktu otomatis atau waktu dari jaringan.\n\nLalu hapus entri OTP bernama \"Kisara ASN Ponorogo\" di aplikasi autentikator (geser ke kiri, lalu konfirmasi hapus) dan pindai ulang."],
        ];
    }
}
