# nawasara/asn-portal

Backend untuk **Akun ASN** (`asn.ponorogo.go.id`), portal tempat setiap ASN Pemerintah Kabupaten Ponorogo mengelola akun SSO-nya: profil, cara masuk, perangkat, aplikasi yang terhubung, dan layanan kedinasan.

Portalnya sendiri aplikasi Astro yang terpisah. Paket ini memegang seluruh data dan aksinya: portal hanya berbicara dengan API Nawasara dan dengan Kisara (untuk masuk), tidak menyimpan kredensial ke sistem lain, dan tidak punya konten sendiri. Rencana lengkapnya ada di repo app: `docs/plan/plan-asn-portal-astro.md` §11 dan `docs/plan/persiapan-nawasara-akun-asn.md`.

Bekerja bersama `nawasara/api` (jalur JWT pegawai dan pembatas rute).

## Status v0.1.3

| Fitur | Status |
|---|---|
| `GET /account`: identitas dari token, daftar-izin | ✅ |
| `POST /account/actions/{passkey\|totp\|backup-codes}`: parameter `kc_action`, `max_age=0` bila login basi | ✅ |
| `POST /account/reauth`: parameter paksa masuk ulang | ✅ |
| Katalog aplikasi, FAQ, dokumen hukum berversi + endpoint publik | ✅ |
| Data awal dari portal lama (5 aplikasi, 7 bantuan) | ✅ `ContentSeeder` |
| Halaman panel: Katalog Aplikasi, Bantuan, Dokumen Hukum (terbit dengan sudo + izin terpisah) | ✅ |
| Profil + kepegawaian (bila NIP ada di registry) | ✅ |
| Ringkasan keamanan, credential (passkey, autentikator, kode cadangan): lihat, ganti nama, hapus | ✅ |
| Perangkat (sesi): lihat, keluarkan satu, keluar dari semua perangkat lain | ✅ |
| Aktivitas: event login Keycloak + catatan portal, 90 hari | ✅ |
| Ubah nomor WhatsApp (verifikasi kode opsional, mati secara bawaan) | ✅ |
| Reset MFA tanpa login (email, WhatsApp bila email kosong) | ✅ |
| Email peringatan keamanan | ✅ lewat `nawasara/notification` |
| Email dinas: kotak pribadi + kotak OPD (PIC), webmail tanpa kata sandi, riwayat buka, ganti kata sandi pribadi, balasan otomatis, penerusan | ✅ |
| VPN: akun milik sendiri, unduh `.conf`, ajukan perpanjang | ✅ |
| Situs OPD: uptime, disk hosting, versi PHP, jumlah temuan keamanan | ✅ |
| Penyimpanan: kuota email dan hosting | ✅ |
| Jumlah situs mati per minggu, "kosongkan ruang" email | ⏳ |
| Tiket RAKACA | ⏳ menyusul |

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

# opsional, bawaannya sudah benar untuk produksi
ASN_PORTAL_KC_REALM=              # kosong = client Keycloak bawaan (Vault `keycloak`)
ASN_PORTAL_WA_VERIFICATION=false  # verifikasi nomor WA dengan kode; gateway WAGO masih mati
ASN_PORTAL_WA_VAULT_GROUP=whatsapp
ASN_PORTAL_OTP_TTL_MINUTES=15
ASN_PORTAL_ACTIVITY_DAYS=90
ASN_PORTAL_MAIL_FORWARD_DOMAINS=ponorogo.go.id   # tujuan penerusan email yang diizinkan, pisahkan koma
```

Prasyarat di luar paket ini:

- Client admin Keycloak (Vault `keycloak`) butuh role `view-users`, `manage-users`, dan `view-events` di realm pegawai: membaca dan menghapus credential, mengeluarkan sesi, membaca event.
- **Save events** menyala di realm pegawai, dengan masa simpan minimal `ASN_PORTAL_ACTIVITY_DAYS`. Tanpa itu layar Aktivitas hanya berisi catatan portal.
- Server portal punya **token `nws_` sendiri** dengan scope `asn-portal.mfa.reset` saja dan daftar IP berisi IP server portal. Token ini satu-satunya kredensial portal ke Nawasara selain client OIDC-nya.
- Kotak email ditautkan ke NIP di WHM Ryder (External Authentication, provider `kisara`); itu sumber utama. Kotak bersama yang belum ditautkan bisa didaftarkan di registry sebagai aset `email` dengan PIC-nya. Situs memakai aset `domain`/`subdomain`/`hosting`/`hosting_account`/`application`. Tanpa penugasan, halaman Email OPD dan Situs kosong.
- Akun VPN dicari lewat `users.keycloak_id`; akun yang dibuat sebelum pemilihan pemilik dari direktori Keycloak dan belum punya pemilik tidak muncul.
- Required action `webauthn-register-passwordless`, `CONFIGURE_TOTP`, dan `CONFIGURE_RECOVERY_AUTHN_CODES` aktif di realm.

Tambahkan ke `resources/css/app.css`, kalau tidak semua kelas Tailwind halaman panel tidak ter-compile:

```css
@source "../../vendor/nawasara/asn-portal";
```

## Endpoint

Prefix `/api/v1/asn-portal`.

### Akun (butuh login)

Middleware: `api.staff.identity` → `api.client:<ASN_PORTAL_KC_CLIENT>` → batas per `sub`.

| Metode | Path | Isi |
|---|---|---|
| GET | `/account` | `sub`, `name`, `nip`, `username`, `email`, `email_verified`, `signed_in_at` |
| POST | `/account/actions/{action}` | `{ action, authorize_params }` |
| POST | `/account/reauth` | `{ authorize_params: { max_age: 0 } }` |
| GET | `/account/profile` | nama, email, WhatsApp ter-mask, kepegawaian |
| GET | `/account/security` | jumlah passkey/autentikator, kode cadangan, saran pemeriksaan, jumlah sesi |
| GET | `/account/credentials` | passkey, autentikator, kunci keamanan, kode cadangan (tanpa password) |
| PATCH | `/account/credentials/{id}` | ganti nama |
| DELETE | `/account/credentials/{id}` | 🔒 hapus; 409 `last_authenticator` untuk autentikator terakhir |
| GET | `/account/sessions` | perangkat + aplikasi per sesi, sesi ini di atas |
| DELETE | `/account/sessions/{id}` | keluarkan satu |
| POST | `/account/sessions/logout-others` | 🔒 keluarkan semua kecuali sesi ini |
| GET | `/account/activity` | event login + catatan portal, terbaru dulu |
| POST | `/account/whatsapp` | 🔒 `{number}` → `saved` atau `code_sent` |
| POST | `/account/whatsapp/verify` | 🔒 `{number, code}` |
| GET | `/account/mailboxes` | kotak pribadi + kotak OPD yang dipegang: kuota, terpakai, PIC lain |
| POST | `/account/mailboxes/{address}/webmail` | URL masuk webmail sekali pakai |
| GET | `/account/mailboxes/{address}/access` | kotak OPD saja: siapa membukanya lewat portal, 90 hari |
| PUT | `/account/mailboxes/{address}/password` | 🔒 kotak pribadi saja, `{password}` minimal 12 karakter |
| GET/PUT/DELETE | `/account/mailboxes/{address}/auto-reply` | `{subject, body, start?, stop?}` |
| GET | `/account/mailboxes/{address}/forwarders` | daftar alamat tujuan |
| POST | `/account/mailboxes/{address}/forwarders` | 🔒 `{destination}`; 422 `destination_not_allowed` di luar domain izin |
| DELETE | `/account/mailboxes/{address}/forwarders` | `{destination}` |
| GET | `/account/vpn` | akun VPN milik sendiri, `last_handshake_at`, `renew_requested_at` |
| POST | `/account/vpn/{id}/config` | 🔒 `{filename, config}`; QR dirender portal dari isi yang sama |
| POST | `/account/vpn/{id}/renew-request` | tandai minta perpanjang untuk admin |
| GET | `/account/sites` | situs yang dipegang + uptime, hosting, PHP, jumlah temuan |
| GET | `/account/storage` | `personal` (kotak pribadi pertama), `mailboxes` (sisanya), `hosting` |

🔒 = `api.recent-auth`: login lebih tua dari `ASN_PORTAL_RECENT_AUTH_SECONDS` dijawab 401 `reauth_required`.

### Reset MFA tanpa login (server portal)

Middleware: `api.auth` (token `nws_` server portal) → `scope:asn-portal.mfa.reset` → batas per IP asli. Server portal meneruskan IP pengguna di header `X-Portal-Client-IP`.

| Metode | Path | Isi |
|---|---|---|
| POST | `/public/mfa-reset/request` | `{nip}` → `{channel, destination ter-mask, ttl_minutes}`; 404 bila NIP tak terdaftar, 429 bila batas kirim habis |
| POST | `/public/mfa-reset/verify` | `{nip, code}` → semua autentikator dihapus, diaudit, dan diberitahukan ke email |

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

**Versi pengganti dokumen hukum paling cepat berlaku 14 hari setelah terbit.** Dokumennya sendiri menjanjikan pemberitahuan 14 hari; versi pertama boleh langsung berlaku karena belum ada yang perlu diberi tahu. Menerbitkan butuh izin `asn-portal.legal.publish` dan sudo. Tanggal diisi dalam WIB dan disimpan UTC.

**Nama indeks dokumen hukum ditulis eksplisit.** Nama otomatis Laravel untuk tabel ini melebihi batas 64 karakter MySQL, dan migrasinya gagal di tengah dengan tabel yang sudah terbuat.

**Batas laju per `sub`, bukan per IP.** Satu kantor OPD keluar lewat satu IP publik; batas per IP akan membuat satu kantor yang sibuk mengunci stafnya sendiri.

**Kode sekali pakai: HMAC, batas percobaan, terikat NIP dan IP asli.** Dua kelemahan portal lama sengaja tidak ikut dipindah: verifikasi kodenya tanpa batas percobaan (kode 6 digit yang berlaku 60 menit bisa ditebak habis), dan saat kode salah, kode yang benar ditulis ke log. Di sini kode disimpan sebagai HMAC dengan `APP_KEY`, hangus setelah 5 tebakan salah, dan batas kirimnya dihitung per NIP *dan* IP asli dari `X-Portal-Client-IP`. Memakai `request()->ip()` akan membuat semua orang berbagi satu batas, karena semua permintaan datang dari IP server portal (rencana §4b). Email kodenya dikirim dengan `->sensitive()`, jadi kodenya tidak tersimpan di log notifikasi.

**Autentikator terakhir tidak bisa dihapus.** Verifikasi dua langkah wajib untuk akun ASN, jadi menghapusnya akan mengunci pemilik akun pada masuk berikutnya. Pengguna harus menambah autentikator lain dulu, atau memakai "Ganti perangkat" (`kc_action=CONFIGURE_TOTP`).

**Atribut Keycloak ditulis dengan representasi pengguna utuh.** `KeycloakClient::setUserAttribute()` hanya mengirim `attributes`; dengan declarative user profile Keycloak 26, PUT parsial bisa mengosongkan atau gagal pada field profil lain. `KeycloakDirectory::setAttributes()` mengambil pengguna, menggabungkan atribut, lalu mengirim semuanya, seperti portal lama yang sudah terbukti di produksi.

**`whatsapp_verified_at` hanya ditulis bila nomornya dibuktikan dengan kode.** Portal lama menulisnya setiap kali menyimpan, sehingga nomor yang tidak pernah diverifikasi tampak terverifikasi.

**Verifikasi nomor WhatsApp mati secara bawaan** (`ASN_PORTAL_WA_VERIFICATION=false`), sama dengan produksi sejak nomor WAGO diblokir. Menyalakannya diam-diam saat migrasi akan merusak alur selama gateway belum pulih. Ubah nomor tetap butuh login segar, karena nomor ini dipakai untuk reset MFA.

**Reset MFA hanya bisa dipanggil server portal.** Endpoint ini menulis identitas atas permintaan orang anonim. Penjaganya berlapis: token `nws_` khusus dengan daftar IP, NIP yang di-resolve di dalam Nawasara (endpoint tidak pernah menerima user id), kode yang terikat NIP, audit, dan email pemberitahuan ke pemilik akun. NIP yang tidak terdaftar dijawab 404, sama seperti portal lama; kalau perilaku ini mau diubah, ingat bahwa ASN butuh tahu ke mana kodenya dikirim.

**Kotak email diambil dari tautan NIP di WHM Ryder, bukan dari email akun.** Tim email menautkan tiap kotak ke identitas Kisara (subject = NIP) lewat External Authentication cPanel; `nawasara/whm` menyalinnya tiap 30 menit ke `nawasara_whm_mailbox_links`. Per 30 Oktober 2026: 907 tautan, 891 NIP, 890 kotak. Email akun Keycloak tidak dipakai karena bagi banyak ASN itu Gmail pribadi; mencocokkannya akan menyerahkan kotak ke orang yang salah. Penugasan PIC aset `email` di registry ditambahkan di atasnya, untuk kotak bersama yang belum ditautkan.

**Tertaut ke satu NIP = pribadi, ke beberapa NIP = bersama.** Hanya kotak pribadi yang kata sandinya bisa diganti dari portal. Kotak bersama dibuka tanpa kata sandi: kata sandi yang dibagikan tidak pernah bisa ditarik kembali, sedangkan tautan bisa dicabut, dan akses berakhir paling lama 30 menit setelahnya. Setiap pembukaan tercatat di `nawasara_webmail_sessions` dan terlihat oleh pemegang lain di `/access`. Nama pemegang diambil dari `preferred_username` cPanel (`NIP - Nama`) dengan NIP-nya dibuang.

**Alamat dari URL tidak pernah dipakai langsung.** Setiap aksi email mencari alamatnya di antara kotak milik pemanggil (`MailboxDirectory::find`); alamat lain dijawab 404, bukan 403, supaya keberadaan kotak orang lain tidak bisa ditebak.

**Penerusan hanya ke domain `ponorogo.go.id`.** Penerusan ke Gmail pribadi tidak terlihat PIC lain dan terus menyalin surat dinas setelah pemiliknya pindah tugas, yaitu persis akses yang ingin diakhiri desain tanpa kata sandi tadi. Subdomain diterima; akhiran palsu (`evilponorogo.go.id`) tidak.

**Konfigurasi VPN hanya dengan login segar, diaudit, dan diberitahukan.** Isinya kunci privat. Nawasara tidak membuat gambar QR terpisah: portal merender QR dari teks yang sama, supaya kuncinya hanya keluar sekali per unduhan. Akun yang kedaluwarsa dijawab 409; perpanjangan tetap diputuskan admin (`renew_requested_at` tampil di halaman VPN panel), karena kebijakan otomatisnya belum diputuskan.

**Situs dicocokkan lewat host yang dinormalkan, bukan tabel penghubung.** Uptime (Kuma), WHM, dan secscan tidak saling merujuk. `SiteHost` menyamakan `https://www.x.ponorogo.go.id/login` dan `x.ponorogo.go.id`; IP dan nilai yang bukan host tidak pernah dicocokkan. Temuan keamanan hanya dikirim sebagai jumlah: buktinya (kata kunci judi, isi halaman, nama database) tetap di panel.

**Peringatan keamanan dikirim ke email akun, dan kegagalannya tidak menggagalkan aksi.** Perubahan sudah terjadi di Keycloak saat email dikirim; membatalkannya karena SMTP bermasalah justru membingungkan.

## Permissions

| Permission | Untuk |
|---|---|
| `asn-portal.content.view` | melihat konten portal di panel |
| `asn-portal.content.manage` | mengubah katalog, bantuan, dan draf dokumen hukum |
| `asn-portal.legal.publish` | menerbitkan dokumen hukum (mengikat semua ASN, tidak bisa diubah sesudahnya) |

## Roadmap

- Jumlah situs mati per minggu: butuh riwayat heartbeat Kuma, belum disimpan.
- "Kosongkan ruang" email (lampiran besar, sampah): butuh akses isi kotak lewat doveadm.
- Tiket RAKACA: platform terpisah, menyusul.

---

**Author:** Pringgo J. Saputro · **License:** MIT
