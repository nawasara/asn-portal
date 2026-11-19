<?php
/*
 * Uji Akses Layanan Kisara: aturan KisaraAccess, salinan dari daftar pilot
 * Workspace (migrasi + seeder, dua kali), API GET /api/v1/kisara/access/{nip}
 * lewat HTTP kernel sungguhan (api.auth + scope), dan halaman admin
 * (tambah, ubah, nonaktifkan, hapus ber-sudo, kill switch ber-sudo).
 * SQLite di memori, lewat remote-test:
 *
 *   remote-test.sh -- 'composer install -n && php artisan package:discover \
 *     && php packages/nawasara-asn-portal/tests/manual/uji-akses-layanan.php'
 */

$root = dirname(__DIR__, 4);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$app['config']->set('database.default', 'uji');
$app['config']->set('database.connections.uji', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
$app['db']->setDefaultConnection('uji');
$app['config']->set('cache.default', 'array');
$app['config']->set('nawasara-workspace.sync_on_change', false);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Nawasara\Api\Services\TokenManager;
use Nawasara\AsnPortal\Database\Seeders\KisaraAccessImportSeeder;
use Nawasara\AsnPortal\Events\KisaraAccessChanged;
use Nawasara\AsnPortal\Livewire\Access\Section\Controls;
use Nawasara\AsnPortal\Livewire\Access\Section\Form;
use Nawasara\AsnPortal\Livewire\Access\Section\Table;
use Nawasara\AsnPortal\Models\AppAccess;
use Nawasara\AsnPortal\Services\KisaraAccess;
use Nawasara\AuthPrimitives\Auth\Sudo;

$gagal = 0;
$cek = function (string $label, bool $ok, string $detail = '') use (&$gagal) {
    if (! $ok) { $gagal++; }
    echo ($ok ? '  OK   ' : '  GAGAL')." {$label}".($detail ? "\n         {$detail}" : '')."\n";
};

// ---- skema: tabel induk seminimal mungkin, migrasi paket yang sebenarnya ----
Schema::create('users', function ($t) {
    $t->id(); $t->string('name'); $t->string('email')->nullable(); $t->string('username')->nullable();
    $t->string('keycloak_id')->nullable(); $t->string('password')->nullable(); $t->timestamps();
});
Schema::create('nawasara_registry_opd', function ($t) { $t->id(); $t->string('code'); $t->string('name'); $t->string('simas_name')->nullable(); $t->timestamps(); });
Schema::create('activity_log', function ($t) {
    $t->id(); $t->string('log_name')->nullable(); $t->text('description');
    $t->nullableMorphs('subject'); $t->nullableMorphs('causer'); $t->json('properties')->nullable();
    $t->uuid('batch_uuid')->nullable(); $t->string('event')->nullable(); $t->timestamps();
});
(require $root.'/packages/nawasara-keycloak/database/migrations/2026_04_25_130001_create_nawasara_keycloak_users_table.php')->up();
(require $root.'/vendor/spatie/laravel-permission/database/migrations/create_permission_tables.php.stub')->up();
foreach (glob($root.'/packages/nawasara-api/database/migrations/*.php') as $f) { (require $f)->up(); }
foreach (glob($root.'/packages/nawasara-workspace/database/migrations/*.php') as $f) { (require $f)->up(); }

DB::table('users')->insert([['id' => 1, 'name' => 'Admin Kisara', 'username' => 'admin', 'email' => 'a@x.id'], ['id' => 2, 'name' => 'Admin Lain', 'username' => 'admin2', 'email' => 'b@x.id']]);
DB::table('nawasara_registry_opd')->insert([['id' => 7, 'code' => 'KOMINFO', 'name' => 'Diskominfo'], ['id' => 8, 'code' => 'BKPSDM', 'name' => 'BKPSDM']]);

$kc = fn ($id, $user, $first, $last, $enabled = true) => DB::table('nawasara_keycloak_users')->insert([
    'user_id' => $id, 'username' => $user, 'first_name' => $first, 'last_name' => $last, 'enabled' => $enabled,
    'created_at' => now(), 'updated_at' => now(),
]);
$pringgo = '199506142020121004';
$warist = '198604082020121003';
$citra = '199001012015011001';
$nonaktif = '197001012000011001';
$baru = '198001012005011001';
$kc('kc-p', $pringgo, 'Pringgo', 'Saputro');
$kc('kc-w', $warist, 'Warist', 'Amru');
$kc('kc-c', $citra, 'Citra', 'Lestari');
$kc('kc-n', $nonaktif, 'Akun', 'Mati', false);
$kc('kc-b', $baru, 'Budi', 'Baru');
$kc('kc-op', 'operator-lab', 'Operator', 'Lab');

// Daftar pilot Workspace seperti di produksi: tiga aktif, satu dicabut, satu
// baris rusak (bukan NIP), kill switch mati, pilot_open mati.
$ws = fn (array $r) => DB::table('nawasara_workspace_pilot_members')->insert($r + ['keycloak_id' => null, 'opd_id' => null, 'note' => null, 'added_by' => null, 'removed_at' => null, 'created_at' => '2026-11-17 08:00:00', 'updated_at' => now()]);
$ws(['nip' => $pringgo, 'name' => 'Pringgo', 'stage' => 0, 'opd_id' => 7, 'note' => 'pemilik', 'added_by' => 1, 'keycloak_id' => 'kc-p']);
$ws(['nip' => $warist, 'name' => 'Warist', 'stage' => 1, 'added_by' => 2]);
$ws(['nip' => $nonaktif, 'name' => 'Akun Mati', 'stage' => 1]);
$ws(['nip' => $citra, 'name' => 'Citra', 'stage' => 2, 'removed_at' => now()]);
$ws(['nip' => '12345', 'name' => 'Salah Ketik', 'stage' => 1]);
DB::table('nawasara_workspace_settings')->insert(['key' => 'pilot_open', 'value' => '0', 'created_at' => now(), 'updated_at' => now()]);

echo "=== migrasi + salinan dari daftar pilot Workspace ===\n";
$migrasi = glob($root.'/packages/nawasara-asn-portal/database/migrations/*.php');
sort($migrasi);
foreach ($migrasi as $f) { (require $f)->up(); }
$cek('dua tabel baru ada', Schema::hasTable('nawasara_asn_portal_app_access') && Schema::hasTable('nawasara_asn_portal_app_access_settings'));

$row = fn (string $nip, string $app) => AppAccess::where('nip', $nip)->where('app', $app)->first();
$cek('peserta aktif disalin ke workspace DAN mail', $row($pringgo, 'workspace') && $row($pringgo, 'mail') && $row($warist, 'workspace') && $row($warist, 'mail'));
$p = $row($pringgo, 'workspace');
$cek('tahap, catatan, added_by, keycloak_id, tanggal ikut', $p->stage === 0 && $p->note === 'pemilik' && $p->added_by === 1 && $p->keycloak_id === 'kc-p' && $p->created_at->format('Y-m-d') === '2026-11-17');
$cek('OPD override hanya untuk workspace', $p->opd_id === 7 && $row($pringgo, 'mail')->opd_id === null);
$cek('yang dicabut TIDAK disalin', $row($citra, 'workspace') === null && $row($citra, 'mail') === null);
$cek('baris bukan NIP dilewati', AppAccess::where('nip', '12345')->doesntExist());
$cek('jumlah: 3 aktif x 2 layanan = 6 baris', AppAccess::count() === 6, (string) AppAccess::count());
$cek('pengaturan workspace ikut (kill switch & pilot_open mati)', DB::table('nawasara_asn_portal_app_access_settings')->where('app', 'workspace')->where('kill_switch', false)->where('open_to_all', false)->exists());

// Admin mengubah sesuatu, lalu seeder dijalankan lagi: tidak ada yang kembali.
$row($warist, 'mail')->update(['removed_at' => now(), 'stage' => 2]);
$hasil = (new KisaraAccessImportSeeder)->run();
$cek('dijalankan dua kali: tidak ada baris baru', AppAccess::count() === 6 && array_sum($hasil['created']) === 0, json_encode($hasil));
$cek('perubahan admin tidak ditimpa', $row($warist, 'mail')->removed_at !== null && $row($warist, 'mail')->stage === 2);
$cek('pengaturan tidak disalin dua kali', $hasil['settings_copied'] === false);
$row($warist, 'mail')->update(['removed_at' => null, 'stage' => 1]);

echo "\n=== aturan KisaraAccess ===\n";
$a = app(KisaraAccess::class);
$r = $a->allows('workspace', $pringgo);
$cek('aktif: boleh, tahap + OPD ikut', $r['allowed'] && $r['stage'] === 0 && $r['opd_id'] === 7, json_encode($r));
$cek('mail: boleh, tahap 0', $a->allows('mail', $pringgo)['allowed'] && $a->allows('mail', $pringgo)['stage'] === 0);
$cek('bukan peserta: not_participant', $a->allows('mail', $baru)['reason'] === KisaraAccess::NOT_PARTICIPANT);
$cek('akun Kisara mati kalah walau ada di daftar: account_disabled', $a->allows('workspace', $nonaktif)['reason'] === KisaraAccess::ACCOUNT_DISABLED);
$cek('bukan NIP: invalid_nip', $a->allows('mail', 'admin')['reason'] === KisaraAccess::INVALID_NIP);
$cek('layanan tak dikenal: unknown_app', $a->allows('drive-lama', $pringgo)['reason'] === KisaraAccess::UNKNOWN_APP);
$row($warist, 'mail')->update(['removed_at' => now()]);
$cek('dinonaktifkan: revoked, workspace-nya tetap boleh', $a->allows('mail', $warist)['reason'] === KisaraAccess::REVOKED && $a->allows('workspace', $warist)['allowed']);
$row($warist, 'mail')->update(['removed_at' => null]);

$a->setFlag('mail', KisaraAccess::FLAG_OPEN, true, 1);
$cek('mail terbuka untuk semua: bukan peserta boleh, tahap 3', $a->allows('mail', $baru)['allowed'] && $a->allows('mail', $baru)['stage'] === 3);
$cek('terbuka untuk semua tidak membuka workspace', ! $a->allows('workspace', $baru)['allowed']);
$cek('akun mati tetap ditolak walau terbuka', $a->allows('mail', $nonaktif)['reason'] === KisaraAccess::ACCOUNT_DISABLED);
$a->setFlag('mail', KisaraAccess::FLAG_KILL_SWITCH, true, 1);
$cek('kill switch mengalahkan semuanya', $a->allows('mail', $pringgo)['reason'] === KisaraAccess::KILL_SWITCH && $a->allows('mail', $baru)['reason'] === KisaraAccess::KILL_SWITCH);
$cek('kill switch mail tidak menyentuh workspace', $a->allows('workspace', $pringgo)['allowed']);
$cek('isMember mengabaikan kill switch (berkas tetap ada)', $a->isMember('mail', $pringgo));
$a->setFlag('mail', KisaraAccess::FLAG_KILL_SWITCH, false, 1);
$a->setFlag('mail', KisaraAccess::FLAG_OPEN, false, 1);
$cek('daftar aktif workspace untuk sinkron grup', $a->activeMembers('workspace')->pluck('nip')->sort()->values()->all() === collect([$pringgo, $warist, $nonaktif])->sort()->values()->all());
$cek('OPD override per NIP', $a->opdOverrides('workspace') === [$pringgo => 7]);

$terima = [];
Event::listen(KisaraAccessChanged::class, function ($e) use (&$terima) { $terima[] = $e->app; });
$row($pringgo, 'mail')->update(['note' => 'uji event']);
$a->setFlag('workspace', KisaraAccess::FLAG_OPEN, false, 1);
$cek('setiap perubahan mengabarkan layanannya (KisaraAccessChanged)', $terima === ['mail', 'workspace'], json_encode($terima));

echo "\n=== API GET /api/v1/kisara/access/{nip}?app= ===\n";
$tokens = $app->make(TokenManager::class);
$tokenOk = $tokens->create('uji akses', ['asn-portal.access.read'])['plaintext'];
$tokenLauncher = $tokens->create('uji launcher', ['asn-portal.launcher.read'])['plaintext'];
$http = $app->make(Illuminate\Contracts\Http\Kernel::class);
$get = function (string $path, ?string $token) use ($http) {
    $server = ['REMOTE_ADDR' => '10.9.9.'.random_int(1, 250), 'HTTP_ACCEPT' => 'application/json'];
    if ($token) { $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token; }

    return $http->handle(Request::create('/api/v1/kisara/access/'.$path, 'GET', [], [], [], $server));
};
$body = fn ($res) => json_decode($res->getContent(), true);

$route = Route::getRoutes()->getByName('nawasara-asn-portal.kisara.access.show');
$cek('rute di /api/v1/kisara/access/{nip}', $route !== null && $route->uri() === 'api/v1/kisara/access/{nip}', $route?->uri() ?? 'tidak ada');
$cek('tanpa token: 401', $get($pringgo.'?app=mail', null)->getStatusCode() === 401);
$cek('token launcher (scope lain): 403', $get($pringgo.'?app=mail', $tokenLauncher)->getStatusCode() === 403);
$res = $get($pringgo.'?app=mail', $tokenOk);
$cek('peserta: 200, allowed true', $res->getStatusCode() === 200 && $body($res)['data']['allowed'] === true, $res->getContent());
$cek('kunci persis daftar-izin (tanpa nama, OPD, catatan)', array_keys($body($res)['data']) === ['nip', 'app', 'allowed', 'reason', 'stage'], implode(',', array_keys($body($res)['data'] ?? [])));
$res = $get($baru.'?app=mail', $tokenOk);
$cek('bukan peserta: tetap 200, allowed false, not_participant', $res->getStatusCode() === 200 && $body($res)['data']['allowed'] === false && $body($res)['data']['reason'] === 'not_participant');
$res = $get('admin?app=mail', $tokenOk);
$cek('login bukan NIP: 200 invalid_nip, bukan 404', $res->getStatusCode() === 200 && $body($res)['data']['reason'] === 'invalid_nip');
$cek('app kosong: 422 unknown_app', $get($pringgo, $tokenOk)->getStatusCode() === 422 && $body($get($pringgo, $tokenOk))['error']['code'] === 'unknown_app');
$cek('app tak dikenal: 422', $get($pringgo.'?app=drive-lama', $tokenOk)->getStatusCode() === 422);
$cek('launcher tetap meminta scope launcher', $http->handle(Request::create('/api/v1/kisara/launcher?nip='.$pringgo, 'GET', [], [], [], ['REMOTE_ADDR' => '10.9.9.9', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$tokenOk]))->getStatusCode() === 403);

echo "\n=== halaman admin ===\n";
$admin = (config('auth.providers.users.model'))::find(1);
auth()->setUser($admin); // setUser, bukan login(): login() memicu listener audit & registry
Gate::before(fn () => true); // izin dicek terpisah oleh route + PermissionSeeder

$f = Livewire::test(Form::class)->call('create')->set('userSearch', 'Budi');
$cek('pencarian menemukan pegawai yang belum ada di daftar', count($f->instance()->userResults) === 1);
$f->call('pick', 0)->call('save');
$cek('tanpa layanan dicentang: ditolak', AppAccess::where('nip', $baru)->doesntExist());
$f->set('access.mail.on', true)->set('access.mail.stage', '2')->set('note', 'uji mail')->call('save')->assertHasNoErrors();
$b = $row($baru, 'mail');
$cek('pegawai baru: hanya mail, tahap 2, added_by admin', $b && $b->stage === 2 && $b->added_by === 1 && $b->keycloak_id === 'kc-b' && $row($baru, 'workspace') === null);
$cek('tercatat di log aktivitas', DB::table('activity_log')->where('log_name', 'kisara-access')->where('description', 'Akses layanan Kisara diberikan')->exists());

$f = Livewire::test(Form::class)->call('create')->set('userSearch', 'Pringgo');
$cek('yang sudah ada disaring dari hasil cari', count($f->instance()->userResults) === 0);
$f = Livewire::test(Form::class)->call('create')->set('userSearch', 'Operator')->call('pick', 0);
$cek('akun tanpa NIP tidak terpilih', $f->get('picked') === null);

$f = Livewire::test(Form::class)->call('edit', $baru);
$cek('ubah: centang dan tahap terisi dari data', $f->get('access.mail.on') === true && $f->get('access.mail.stage') === '2' && $f->get('access.workspace.on') === false);
$f->set('access.workspace.on', true)->set('access.workspace.stage', '1')->set('access.workspace.opd_id', '8')->set('access.mail.on', false)->call('save')->assertHasNoErrors();
$cek('ubah: workspace diberikan dengan OPD', $row($baru, 'workspace')?->isActive() && $row($baru, 'workspace')->opd_id === 8);
$cek('ubah: mail dicentang lepas = dinonaktifkan, baris tetap', $row($baru, 'mail') && ! $row($baru, 'mail')->isActive() && $row($baru, 'mail')->removed_by === 1);
Livewire::test(Form::class)->call('edit', $baru)->set('access.mail.on', true)->call('save');
$cek('ubah: centang lagi = aktif kembali, baris yang sama', $row($baru, 'mail')->isActive() && AppAccess::where('nip', $baru)->where('app', 'mail')->count() === 1);

$t = Livewire::test(Table::class);
$t->call('deactivate', $row($baru, 'workspace')->id);
$cek('nonaktifkan dari tabel', ! $row($baru, 'workspace')->isActive());
$t->call('activate', $row($baru, 'workspace')->id);
$cek('aktifkan lagi dari tabel', $row($baru, 'workspace')->isActive());
$t->call('delete', $baru);
$cek('hapus tanpa sudo: tidak terhapus', AppAccess::where('nip', $baru)->count() === 2);

$html = Livewire::test(Table::class)->html();
$cek('tabel: satu baris per pegawai, di dalam tbody (x-slot:table)', preg_match('#<tbody[^>]*>.*'.$pringgo.'#s', $html) === 1 && substr_count($html, 'wire:key="access-'.$pringgo.'"') === 1);
$cek('tabel: kolom per layanan', str_contains($html, '>Workspace<') || str_contains($html, 'Workspace'));
$cek('saringan layanan: hanya yang punya baris mail', Livewire::test(Table::class)->set('appFilter', 'mail')->instance()->people->total() === 4);
$cek('cari NIP', Livewire::test(Table::class)->set('search', $warist)->instance()->people->total() === 1);
$html = Livewire::test(Table::class)->set('search', 'tidak-ada-orang-ini')->html();
$cek('empty state saringan: Tidak ada yang cocok', str_contains($html, 'Tidak ada yang cocok'));

$k = Livewire::test(Controls::class);
$k->call('toggleKillSwitch', 'mail');
$cek('kill switch tanpa sudo: tetap mati', $a->flags('mail')[KisaraAccess::FLAG_KILL_SWITCH] === false);

Sudo::confirm(1);
Livewire::test(Controls::class)->call('toggleKillSwitch', 'mail');
$cek('kill switch dengan sudo: menyala', $a->flags('mail')[KisaraAccess::FLAG_KILL_SWITCH] === true);
Livewire::test(Controls::class)->call('toggleKillSwitch', 'mail');
$cek('mematikan kill switch', $a->flags('mail')[KisaraAccess::FLAG_KILL_SWITCH] === false);
Livewire::test(Table::class)->call('delete', $baru);
$cek('hapus dengan sudo: semua baris pegawai itu hilang', AppAccess::where('nip', $baru)->doesntExist());
try {
    Livewire::test(Controls::class)->call('toggleKillSwitch', 'tidak-ada')->assertStatus(404);
    $ok404 = true;
} catch (\Throwable $e) {
    $ok404 = $e instanceof Symfony\Component\HttpKernel\Exception\HttpException && $e->getStatusCode() === 404;
}
$cek('layanan tak dikenal di kill switch: 404', $ok404 && DB::table('nawasara_asn_portal_app_access_settings')->where('app', 'tidak-ada')->doesntExist());

echo "\n=== migrasi dapat dibalik ===\n";
$m = require current(array_filter($migrasi, fn ($f) => str_contains($f, 'app_access_tables')));
$m->down();
$cek('down() membuang tabel', ! Schema::hasTable('nawasara_asn_portal_app_access'));
$m->up();
$cek('up() lagi menyalin ulang dari daftar pilot', AppAccess::count() === 6, (string) AppAccess::count());

echo "\n".($gagal === 0 ? 'SEMUA LULUS' : "ADA {$gagal} YANG GAGAL")."\n";
exit($gagal === 0 ? 0 : 1);
