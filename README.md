# nawasara/asn-portal

Backend untuk **Akun ASN** (`asn.ponorogo.go.id`), portal tempat setiap ASN Pemerintah Kabupaten Ponorogo mengelola akun SSO-nya: profil, cara masuk, perangkat, aplikasi yang terhubung, dan layanan kedinasan.

Portalnya sendiri aplikasi Astro yang terpisah. Paket ini memegang seluruh data dan aksinya: portal hanya berbicara dengan API Nawasara dan dengan Kisara (untuk masuk), tidak menyimpan kredensial ke sistem lain, dan tidak punya konten sendiri. Rencana lengkapnya ada di repo app: `docs/plan/plan-asn-portal-astro.md` §11 dan `docs/plan/persiapan-nawasara-akun-asn.md`.

Bekerja bersama `nawasara/api` (jalur JWT pegawai dan pembatas rute).

## Status v0.1.0

| Fitur | Status |
|---|---|
| `GET /account`: identitas dari token, daftar-izin | ✅ |
| `POST /account/actions/{passkey\|totp\|backup-codes}`: parameter `kc_action`, `max_age=0` bila login basi | ✅ |
| `POST /account/reauth`: parameter paksa masuk ulang | ✅ |
| Katalog aplikasi, FAQ, dokumen hukum berversi + endpoint publik | ✅ |
| Data awal dari portal lama (5 aplikasi, 7 bantuan) | ✅ `ContentSeeder` |
| Halaman panel untuk mengelola konten | ⏳ |
| Profil kepegawaian (jabatan, unit) dari SimasHebat | ⏳ |
| Credential (passkey, autentikator), sesi, aktivitas | ⏳ butuh API credential di `nawasara/keycloak` |
| Email dinas, VPN, situs OPD, penyimpanan | ⏳ butuh penugasan PIC di registry |
| Reset MFA tanpa login, ubah nomor WhatsApp | ⏳ dipindah dari portal lama (rencana §4) |

## Setup

```bash
composer require nawasara/asn-portal
php artisan migrate
php artisan db:seed --class="Nawasara\AsnPortal\Database\Seeders\PermissionSeeder"
php artisan db:seed --class="Nawasara\AsnPortal\Database\Seeders\ContentSeeder"
```

`.env`:

```
# client OIDC portal di realm asn-productions
ASN_PORTAL_KC_CLIENT=asn-portal
# client yang sama WAJIB juga ada di daftar ini, kalau tidak token ditolak lebih awal
NAWASARA_STAFF_KC_CLIENTS=nawasara-panel,asn-portal
```

Belum ada view, jadi belum perlu baris `@source` di `resources/css/app.css`. Tambahkan `@source "../../vendor/nawasara/asn-portal";` bersamaan dengan halaman panel pertama.

## Endpoint

Prefix `/api/v1/asn-portal`.

### Akun (butuh login)

Middleware: `api.staff.identity` → `api.client:<ASN_PORTAL_KC_CLIENT>` → batas per `sub`.

| Metode | Path | Isi |
|---|---|---|
| GET | `/account` | `sub`, `name`, `nip`, `username`, `email`, `email_verified`, `signed_in_at` |
| POST | `/account/actions/{action}` | `{ action, authorize_params }` |
| POST | `/account/reauth` | `{ authorize_params: { max_age: 0 } }` |

### Publik (tanpa login)

Batas per IP.

| Metode | Path | Isi |
|---|---|---|
| GET | `/public/apps` | katalog aplikasi aktif |
| GET | `/public/help?kind=faq\|sign_in_help` | bantuan, jawaban Markdown |
| GET | `/public/legal/{privacy\|terms}` | versi berlaku + `upcoming` bila sudah diumumkan; 404 `not_published` bila belum ada |

## Keputusan yang jangan dibatalkan

**Akun memakai `api.staff.identity`, bukan `api.staff`.** `api.staff` menuntut baris `users` dan menolak sisanya dengan 403. Portal melayani *seluruh* ASN, dan sebagian besar tidak pernah membuka panel Nawasara. Identitas diambil dari `sub` token, tidak pernah dari parameter, dan `Auth::setUser()` sengaja tidak dipanggil supaya endpoint akun tidak mewarisi permission panel.

**`api.client` hanya mencocokkan `azp`.** Realm pegawai punya audience mapper yang menaruh nama client lain di `aud` token aplikasi lain. Kalau `aud` ikut dihitung, token aplikasi mana pun yang memuat nama portal di `aud` akan lolos ke endpoint akun. `ASN_PORTAL_KC_CLIENT` yang kosong menolak semua permintaan akun: portal yang belum dikonfigurasi harus tertutup, bukan terbuka.

**Portal yang menyusun permintaan authorize, Nawasara yang menentukan isinya.** Portal adalah client OIDC confidential: hanya ia yang memegang `state` dan verifier PKCE permintaannya, jadi URL Keycloak tidak bisa dibuat di sini. Yang diputuskan Nawasara adalah *aksi apa* yang boleh dimulai dan *kapan* harus masuk ulang. Nama `kc_action` dan aturan kesegaran ada di `AccountActions`, sehingga mengubahnya cukup rilis Nawasara, bukan rilis portal. Login yang lebih tua dari `ASN_PORTAL_RECENT_AUTH_SECONDS` (bawaan 300) mendapat `max_age=0` di permintaan yang sama, jadi pengguna masuk ulang lalu langsung masuk ke aksinya dalam satu redirect.

**Konten disimpan sebagai Markdown, bukan HTML.** Portal lama menyimpan jawaban FAQ dengan `<br>` dan `<a>` mentah lalu merendernya tanpa escape. Konten yang lewat API tidak boleh berupa markup yang harus dipercaya frontend; portal merender Markdown dengan HTML dimatikan.

**Dokumen hukum yang sudah terbit tidak bisa diubah atau dihapus.** Perubahan = versi baru. Dokumennya menjanjikan perubahan diumumkan 14 hari sebelum berlaku, dan apa yang disetujui pengguna pada tanggal tertentu harus tetap bisa dibaca. Versi dengan `effective_at` di masa depan tampil sebagai `upcoming`; versi terbit tanpa tanggal tidak pernah dianggap berlaku. `client_id` Keycloak tidak ikut di direktori publik.

**Batas laju per `sub`, bukan per IP.** Satu kantor OPD keluar lewat satu IP publik; batas per IP akan membuat satu kantor yang sibuk mengunci stafnya sendiri.

## Permissions

| Permission | Untuk |
|---|---|
| `asn-portal.content.view` | melihat konten portal di panel |
| `asn-portal.content.manage` | mengubah katalog, bantuan, dan draf dokumen hukum |
| `asn-portal.legal.publish` | menerbitkan dokumen hukum (mengikat semua ASN, tidak bisa diubah sesudahnya) |

## Roadmap

Urutannya mengikuti `persiapan-nawasara-akun-asn.md`: halaman panel konten → credential, sesi, aktivitas → penugasan PIC di registry → email dinas, VPN, situs OPD → penyimpanan. Tiket RAKACA menyusul.

---

**Author:** Pringgo J. Saputro · **License:** MIT
