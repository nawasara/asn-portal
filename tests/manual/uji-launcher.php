<?php
/*
 * Uji menu 9 titik (T-15, FR-03, ADR 0006): kolom launcher_* di katalog,
 * LauncherSeeder, GET /api/v1/kisara/launcher?nip=, dan Portal ASN yang
 * tidak berubah. SQLite di memori, lewat remote-test, permintaan HTTP
 * sungguhan melewati middleware api.auth + scope.
 */

$root = dirname(__DIR__, 4);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$app['config']->set('database.default', 'uji');
$app['config']->set('database.connections.uji', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
$app['db']->setDefaultConnection('uji');
$app['config']->set('cache.default', 'array');
$app['config']->set('nawasara-workspace.sync_on_change', false);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Nawasara\Api\Services\TokenManager;
use Nawasara\AsnPortal\Database\Seeders\ContentSeeder;
use Nawasara\AsnPortal\Database\Seeders\LauncherSeeder;
use Nawasara\AsnPortal\Http\Api\PublicContentController;
use Nawasara\AsnPortal\Models\AppAccess;
use Nawasara\AsnPortal\Models\PortalApp;
use Nawasara\AsnPortal\Services\KisaraAccess;

// Dideklarasikan sebelum dipakai: kelas turunan tidak di-hoist PHP.
class UjiAksesRusak extends KisaraAccess
{
    public function allows(string $app, string $nip): array
    {
        throw new RuntimeException('db mati');
    }
}

$gagal = 0;
$cek = function (string $label, bool $ok, string $detail = '') use (&$gagal) {
    if (! $ok) { $gagal++; }
    echo ($ok ? '  OK   ' : '  GAGAL')." {$label}".($detail ? "\n         {$detail}" : '')."\n";
};

Schema::create('users', function ($t) {
    $t->id(); $t->string('name'); $t->string('email')->nullable(); $t->string('username')->nullable();
    $t->string('keycloak_id')->nullable(); $t->string('password')->nullable(); $t->timestamps();
});
Schema::create('nawasara_registry_opd', function ($t) { $t->id(); $t->string('code'); $t->string('name'); $t->timestamps(); });
Schema::create('activity_log', function ($t) {
    $t->id(); $t->string('log_name')->nullable(); $t->text('description');
    $t->nullableMorphs('subject'); $t->nullableMorphs('causer'); $t->json('properties')->nullable();
    $t->uuid('batch_uuid')->nullable(); $t->string('event')->nullable(); $t->timestamps();
});
(require $root.'/packages/nawasara-keycloak/database/migrations/2026_04_25_130001_create_nawasara_keycloak_users_table.php')->up();
foreach (glob($root.'/packages/nawasara-workspace/database/migrations/*.php') as $f) { (require $f)->up(); }
foreach (glob($root.'/packages/nawasara-api/database/migrations/*.php') as $f) { (require $f)->up(); }

$migrasiAsn = glob($root.'/packages/nawasara-asn-portal/database/migrations/*.php');
sort($migrasiAsn);
$migrasiLauncher = null;
$migrasiAudience = null;
foreach ($migrasiAsn as $f) {
    if (str_contains($f, 'add_launcher_columns')) { $migrasiLauncher = $f; continue; }
    if (str_contains($f, 'rename_workspace_pilot_launcher_audience')) { $migrasiAudience = $f; continue; }
    (require $f)->up();
}

// Katalog Portal ASN seperti di produksi SEBELUM T-15, plus satu baris lama
// yang sudah menunjuk ke Portal ASN dengan slug lain.
(new ContentSeeder)->run();
DB::table('nawasara_asn_portal_apps')->insert([
    'slug' => 'portal-lama', 'name' => 'Portal ASN (lama)', 'url' => 'https://asn.ponorogo.go.id/',
    'sort_order' => 5, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
]);
// Jawaban /public/apps sebelum T-15, dengan query lama (active() saja): kolom
// show_in_portal belum ada, jadi controller baru belum dapat dipanggil.
$portalSebelum = json_encode(['data' => Nawasara\AsnPortal\Http\Resources\PortalAppResource::collection(PortalApp::query()->active()->get())->resolve()]);

echo "=== migrasi kolom launcher ===\n";
$m = require $migrasiLauncher;
$m->up();
$cols = Schema::getColumnListing('nawasara_asn_portal_apps');
$cek('enam kolom baru ada', array_diff(['show_in_portal', 'launcher_section', 'launcher_sort', 'launcher_audience', 'launcher_label', 'launcher_icon'], $cols) === []);
$cek('baris lama: tetap di portal, belum di menu', PortalApp::where('show_in_portal', false)->count() === 0 && PortalApp::whereNotNull('launcher_section')->count() === 0);

(require $migrasiAudience)->up(); // urutan produksi: kolom dulu, lalu ganti nama audience

(new LauncherSeeder)->run();
$jumlah = PortalApp::count();
(new LauncherSeeder)->run();
$cek('seeder idempoten (dijalankan dua kali, jumlah baris sama)', PortalApp::count() === $jumlah, "{$jumlah} baris");
$cek('baris lama ber-URL sama dipakai, bukan diduplikasi', PortalApp::where('slug', 'portal-asn')->doesntExist()
    && PortalApp::where('slug', 'portal-lama')->value('launcher_section') === 'ecosystem');
$cek('baris baru launcher-saja tidak tampil di portal', PortalApp::where('slug', 'kisara-docs')->first()->show_in_portal === false);

echo "\n=== Portal ASN tidak berubah ===\n";
$portalSesudah = json_encode((new PublicContentController)->apps()->getData(true));
$cek('/public/apps identik sebelum dan sesudah', $portalSebelum === $portalSesudah, substr($portalSesudah, 0, 160));
$cek('/public/apps tidak kosong (uji bermakna)', count(json_decode($portalSesudah, true)['data']) === 6);
$cek('urutan portal (sort_order) tidak disentuh', PortalApp::where('slug', 'portal-lama')->value('sort_order') === 5);

// Peserta Workspace + Mail, peserta Mail saja, dan bukan peserta.
$peserta = '199506142020121004';
$bukan = '199001012015011001';
$mailSaja = '198604082020121003';
AppAccess::create(['nip' => $peserta, 'app' => 'workspace', 'name' => 'Pringgo', 'stage' => 0]);
AppAccess::create(['nip' => $peserta, 'app' => 'mail', 'name' => 'Pringgo', 'stage' => 0]);
AppAccess::create(['nip' => $mailSaja, 'app' => 'mail', 'name' => 'Warist', 'stage' => 1]);
$akses = app(KisaraAccess::class);

$tokens = $app->make(TokenManager::class);
$tokenOk = $tokens->create('uji launcher', ['asn-portal.launcher.read'])['plaintext'];
$tokenLain = $tokens->create('uji lain', ['asn-portal.mail.holdings'])['plaintext'];

$http = $app->make(Illuminate\Contracts\Http\Kernel::class);
$get = function (string $query, ?string $token, array $headers = []) use ($http) {
    $server = ['REMOTE_ADDR' => '10.9.9.'.random_int(1, 250), 'HTTP_ACCEPT' => 'application/json'];
    if ($token) { $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token; }
    foreach ($headers as $k => $v) { $server['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v; }

    return $http->handle(Request::create('/api/v1/kisara/launcher'.$query, 'GET', [], [], [], $server));
};
$items = fn ($res) => json_decode($res->getContent(), true)['data']['items'] ?? [];
$ids = fn ($res) => array_column(json_decode($res->getContent(), true)['data']['items'] ?? [], 'id');

echo "\n=== rute dan scope ===\n";
$route = Route::getRoutes()->getByName('nawasara-asn-portal.kisara.launcher');
$cek('rute di /api/v1/kisara/launcher', $route !== null && $route->uri() === 'api/v1/kisara/launcher', $route?->uri() ?? 'tidak ada');
$cek('tanpa token: 401', $get('?nip='.$peserta, null)->getStatusCode() === 401);
$cek('token tanpa scope asn-portal.launcher.read: 403', $get('?nip='.$peserta, $tokenLain)->getStatusCode() === 403);
$cek('nip kosong: 422', $get('', $tokenOk)->getStatusCode() === 422);
$cek('nip bukan 18 digit: 422', $get('?nip=admin', $tokenOk)->getStatusCode() === 422);

echo "\n=== isi menu ===\n";
$resPeserta = $get('?nip='.$peserta, $tokenOk);
$resBukan = $get('?nip='.$bukan, $tokenOk);
$cek('peserta: 200', $resPeserta->getStatusCode() === 200, substr($resPeserta->getContent(), 0, 200));
$urutPeserta = ['kisara-docs', 'kisara-sheets', 'kisara-slides', 'kisara-drive', 'kisara-mail', 'akun-kisara', 'portal-lama', 'nawasara', 'gasta'];
$cek('peserta melihat Docs, Sheets, Slides, Drive, lalu Mail, Akun, Portal ASN, Nawasara, Gasta', $ids($resPeserta) === $urutPeserta, implode(',', $ids($resPeserta)));
$cek('bukan peserta: tanpa Docs/Sheets/Slides/Drive dan tanpa Mail', $ids($resBukan) === ['akun-kisara', 'portal-lama', 'nawasara', 'gasta'], implode(',', $ids($resBukan)));
$cek('peserta Mail saja: Mail ada, Docs dkk tidak', $ids($get('?nip='.$mailSaja, $tokenOk)) === ['kisara-mail', 'akun-kisara', 'portal-lama', 'nawasara', 'gasta'], implode(',', $ids($get('?nip='.$mailSaja, $tokenOk))));
$cek('seeder: audience Docs = workspace, Mail = mail', PortalApp::where('slug', 'kisara-docs')->value('launcher_audience') === 'workspace' && PortalApp::where('slug', 'kisara-mail')->value('launcher_audience') === 'mail');
$cek('label stabil', array_column($items($resPeserta), 'label') === ['Docs', 'Sheets', 'Slides', 'Drive', 'Mail', 'Akun', 'Portal ASN', 'Nawasara', 'Gasta'],
    implode(',', array_column($items($resPeserta), 'label')));
$data = json_decode($resPeserta->getContent(), true)['data'];
$cek('kunci item persis daftar-izin', array_keys($data['items'][0]) === ['id', 'label', 'name', 'url', 'icon', 'icon_url', 'abbreviation', 'color', 'section', 'order'], implode(',', array_keys($data['items'][0])));
$cek('bagian: workspace lalu ecosystem, berlabel', $data['sections'] === [['id' => 'workspace', 'label' => 'Kisara Workspace'], ['id' => 'ecosystem', 'label' => 'Ekosistem Kisara']]);
$cek('tautan Semua aplikasi', $data['all_apps'] === ['label' => 'Semua aplikasi', 'url' => 'https://asn-account.ponorogo.go.id/apps']);
$cek('tidak ada id angka atau client Keycloak di jawaban', ! str_contains($resPeserta->getContent(), 'keycloak') && ! is_int($data['items'][0]['id']));
$cek('Docs mengarah ke docs.ponorogo.go.id/docs, ikon docs', $data['items'][0]['url'] === 'https://docs.ponorogo.go.id/docs' && $data['items'][0]['icon'] === 'docs');

echo "\n=== ramah cache ===\n";
$etag = $resPeserta->headers->get('ETag');
$cek('ETag + Cache-Control private, max-age=300', $etag !== null && str_contains((string) $resPeserta->headers->get('Cache-Control'), 'max-age=300') && str_contains((string) $resPeserta->headers->get('Cache-Control'), 'private'), (string) $resPeserta->headers->get('Cache-Control'));
$cek('If-None-Match sama: 304', $get('?nip='.$peserta, $tokenOk, ['If-None-Match' => $etag])->getStatusCode() === 304);
$cek('ETag peserta beda dengan bukan peserta', $etag !== $resBukan->headers->get('ETag'));
$cek('bukan peserta lain: jawaban sama persis (cache-able per varian)', $get('?nip=198001012005011001', $tokenOk)->getContent() === $resBukan->getContent());

PortalApp::where('slug', 'gasta')->first()->update(['launcher_label' => 'Gasta CCTV']);
$res = $get('?nip='.$bukan, $tokenOk);
$cek('ubah label di panel: cache katalog dibersihkan, label baru keluar', (array_column($items($res), 'label')[3] ?? null) === 'Gasta CCTV', implode(',', array_column($items($res), 'label')));
$cek('ETag berubah setelah katalog berubah', $res->headers->get('ETag') !== $resBukan->headers->get('ETag'));

PortalApp::where('slug', 'nawasara')->first()->update(['is_active' => false]);
$cek('baris nonaktif keluar dari menu', ! in_array('nawasara', $ids($get('?nip='.$bukan, $tokenOk)), true));
PortalApp::where('slug', 'nawasara')->first()->update(['is_active' => true]);

DB::table('nawasara_asn_portal_apps')->where('slug', 'gasta')->update(['launcher_audience' => 'salah-ketik']);
Cache::forget(PortalApp::LAUNCHER_CACHE_KEY);
$cek('audience tak dikenal: tidak tampil (fail closed)', ! in_array('gasta', $ids($get('?nip='.$peserta, $tokenOk)), true));

echo "\n=== status akses ===\n";
$akses->setFlag('workspace', KisaraAccess::FLAG_KILL_SWITCH, true);
$cek('kill switch workspace: peserta pun tanpa Docs, Mail tetap', ! in_array('kisara-docs', $ids($get('?nip='.$peserta, $tokenOk)), true) && in_array('kisara-mail', $ids($get('?nip='.$peserta, $tokenOk)), true));
$akses->setFlag('workspace', KisaraAccess::FLAG_KILL_SWITCH, false);
$akses->setFlag('workspace', KisaraAccess::FLAG_OPEN, true);
$cek('workspace terbuka untuk semua (tahap 3): bukan peserta pun melihat Docs', in_array('kisara-docs', $ids($get('?nip='.$bukan, $tokenOk)), true));
$akses->setFlag('workspace', KisaraAccess::FLAG_OPEN, false);
AppAccess::where('nip', $peserta)->where('app', 'workspace')->first()->update(['removed_at' => now()]);
$cek('akses workspace dinonaktifkan: Docs hilang', ! in_array('kisara-docs', $ids($get('?nip='.$peserta, $tokenOk)), true));
AppAccess::where('nip', $peserta)->where('app', 'workspace')->first()->update(['removed_at' => null]);

DB::table('nawasara_asn_portal_apps')->where('slug', 'kisara-sheets')->update(['launcher_audience' => 'workspace-pilot']);
Cache::forget(PortalApp::LAUNCHER_CACHE_KEY);
$cek('nilai lama workspace-pilot tetap dibaca sebagai workspace', in_array('kisara-sheets', $ids($get('?nip='.$peserta, $tokenOk)), true) && ! in_array('kisara-sheets', $ids($get('?nip='.$bukan, $tokenOk)), true));

$app->instance(KisaraAccess::class, new UjiAksesRusak);
$cek('pemeriksaan akses melempar galat: item berakses disembunyikan, sisanya tetap', $ids($get('?nip='.$peserta, $tokenOk)) === ['akun-kisara', 'portal-lama', 'nawasara'], implode(',', $ids($get('?nip='.$peserta, $tokenOk))));
$app->instance(KisaraAccess::class, $akses);

echo "\n=== migrasi dapat dibalik ===\n";
(require $migrasiAudience)->down();
$cek('down() audience: workspace kembali ke workspace-pilot', PortalApp::where('slug', 'kisara-docs')->value('launcher_audience') === 'workspace-pilot');
$m->down();
$cols = Schema::getColumnListing('nawasara_asn_portal_apps');
$cek('down() membuang keenam kolom', array_intersect(['show_in_portal', 'launcher_section', 'launcher_sort', 'launcher_audience', 'launcher_label', 'launcher_icon'], $cols) === []);
$m->up();
$cek('up() lagi setelah down() berhasil', Schema::hasColumn('nawasara_asn_portal_apps', 'launcher_section'));

echo "\n".($gagal === 0 ? 'SEMUA LULUS' : "ADA {$gagal} YANG GAGAL")."\n";
exit($gagal === 0 ? 0 : 1);

