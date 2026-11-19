<?php

namespace Nawasara\AsnPortal\Http\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Nawasara\AsnPortal\Http\Resources\LauncherItemResource;
use Nawasara\AsnPortal\Models\PortalApp;
use Illuminate\Support\Facades\Log;
use Nawasara\AsnPortal\Services\KisaraAccess;

/**
 * GET /api/v1/kisara/launcher?nip=   scope: asn-portal.launcher.read
 *
 * The 9-dot menu for Akun Kisara, Kisara Mail and Kisara Workspace, already
 * filtered for one ASN (FR-03, ADR 0006). Called by those products' servers,
 * never by a browser; they keep a 5-minute cache and the last good copy.
 *
 * An item whose audience is an app key (`workspace`, `mail`) is shown only
 * to whoever Akses Layanan Kisara lets use that app (KisaraAccess), the same
 * rule that app's own door applies, so the menu never offers an app that
 * then refuses the person. Fail closed: any error while asking hides the
 * item. A missing Docs icon is a small annoyance; an icon that leads to
 * "sedang uji coba" for every ASN is a visible bug.
 *
 * Answers differ only by which apps the person may use, so callers share a
 * handful of variants per catalog version. The ETag is the hash of the body,
 * so a caller revalidating with If-None-Match gets a 304 until the catalog
 * or the person's access changes.
 */
class LauncherController extends Controller
{
    public function index(Request $request, KisaraAccess $access): JsonResponse|Response
    {
        $nip = (string) $request->query('nip', '');

        if (preg_match('/^\d{18}$/', $nip) !== 1) {
            return response()->json([
                'error' => ['code' => 'invalid_nip', 'message' => 'Parameter nip wajib berisi 18 digit NIP.'],
            ], 422);
        }

        $allowed = [];
        $may = function (?string $app) use ($access, $nip, &$allowed): bool {
            if ($app === null) {
                return true;
            }
            if (! array_key_exists($app, $allowed)) {
                try {
                    $allowed[$app] = $access->allows($app, $nip)['allowed'] === true;
                } catch (\Throwable $e) {
                    Log::warning('asn-portal: launcher access check failed, hiding the app', ['app' => $app, 'error' => $e->getMessage()]);
                    $allowed[$app] = false;
                }
            }

            return $allowed[$app];
        };

        $items = array_values(array_filter(
            $this->catalog(),
            fn (array $item) => $may(PortalApp::audienceApp($item['audience'])),
        ));

        $sections = config('nawasara-asn-portal.launcher.sections', []);

        $body = ['data' => [
            'sections' => array_map(
                fn (string $id) => ['id' => $id, 'label' => (string) ($sections[$id] ?? $id)],
                PortalApp::LAUNCHER_SECTIONS,
            ),
            'items' => array_map(fn (array $item) => $item['resource'], $items),
            'all_apps' => [
                'label' => (string) config('nawasara-asn-portal.launcher.all_apps_label', 'Semua aplikasi'),
                'url' => (string) config('nawasara-asn-portal.launcher.all_apps_url'),
            ],
        ]];

        $etag = '"'.sha1(json_encode($body)).'"';
        $headers = ['ETag' => $etag, 'Cache-Control' => 'private, max-age=300'];

        if (trim((string) $request->header('If-None-Match')) === $etag) {
            return response('', 304, $headers);
        }

        return response()->json($body, 200, $headers);
    }

    /**
     * Every launcher entry with its audience, in menu order (section first,
     * then launcher_sort). Cached; PortalApp clears it on save and delete.
     * An unknown audience value (not `all`, not an app key in config) is
     * kept out entirely: fail closed.
     *
     * @return array<int, array{audience: string, resource: array<string, mixed>}>
     */
    private function catalog(): array
    {
        return Cache::remember(PortalApp::LAUNCHER_CACHE_KEY, 300, function () {
            $rank = array_flip(PortalApp::LAUNCHER_SECTIONS);

            return PortalApp::query()->inLauncher()->get()
                ->filter(fn (PortalApp $app) => in_array(PortalApp::audienceApp((string) $app->launcher_audience) ?? PortalApp::AUDIENCE_ALL, PortalApp::launcherAudiences(), true))
                ->sortBy(fn (PortalApp $app) => [$rank[$app->launcher_section], $app->launcher_sort, $app->name])
                ->map(fn (PortalApp $app) => [
                    'audience' => $app->launcher_audience,
                    'resource' => (new LauncherItemResource($app))->resolve(),
                ])
                ->values()
                ->all();
        });
    }
}
