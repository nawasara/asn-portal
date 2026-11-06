<?php

namespace Nawasara\AsnPortal\Http\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Nawasara\AsnPortal\Services\PortalAudit;
use Nawasara\AsnPortal\Services\ServerDirectory;
use Nawasara\AsnPortal\Support\ClientIp;

class ServerController extends Controller
{
    public function __invoke(Request $request, ServerDirectory $servers): JsonResponse
    {
        return response()->json(['data' => $servers->forUser($this->sub($request))]);
    }

    /**
     * POST /account/servers/{vmid}/console — tautan console sekali pakai.
     *
     * Siapa yang boleh diputuskan DI SINI, bukan di nawasara/proxmox: hanya
     * PIC aktif (utama atau cadangan) dari aset registry mesin ini. Daftar
     * yang sama dengan yang menampilkan servernya di Akun Kisara, jadi orang
     * tidak pernah melihat tombol yang menolaknya, dan mencabut PIC langsung
     * mencabut console.
     *
     * Rutenya memakai `api.recent-auth`: console membuka shell root di dalam
     * container, jadi token yang dicuri dari peramban yang terbuka berjam-jam
     * tidak cukup.
     */
    public function console(Request $request, int $vmid, PortalAudit $audit): JsonResponse
    {
        if (! class_exists(\Nawasara\Proxmox\Services\KisaraConsole::class)
            || ! class_exists(\Nawasara\Registry\Services\AssetAssignmentService::class)) {
            return $this->error('unavailable', 'Console belum tersedia.', 503);
        }

        $sub = $this->sub($request);

        $isPic = app(\Nawasara\Registry\Services\AssetAssignmentService::class)
            ->assetsForKeycloakSub($sub, ServerDirectory::ASSET_TYPES)
            ->contains(fn ($a) => $a->package_ref === 'proxmox' && (string) $a->external_id === (string) $vmid);

        // 404, bukan 403: orang yang bukan PIC tidak perlu tahu mesin itu ada.
        if (! $isPic) {
            return $this->error('not_found', 'Server tidak ditemukan.', 404);
        }

        $vm = \Nawasara\Proxmox\Models\ProxmoxVm::where('vmid', $vmid)->first();
        $user = config('auth.providers.users.model')::query()->where('keycloak_id', $sub)->first();

        if (! $vm || ! $user) {
            return $this->error('not_found', 'Server tidak ditemukan.', 404);
        }

        $ip = ClientIp::from($request);

        // Tautan diikat ke IP peramban HANYA bila portal mengirimkannya.
        // ClientIp::from() jatuh ke IP pemanggil, dan pemanggilnya server
        // portal: mengikat ke alamat itu akan menolak setiap peramban.
        $forwarded = trim((string) $request->header(ClientIp::HEADER, ''));
        $bindIp = filter_var($forwarded, FILTER_VALIDATE_IP) !== false ? $forwarded : null;

        try {
            $url = app(\Nawasara\Proxmox\Services\KisaraConsole::class)->issue($user, $vm, $bindIp);
        } catch (\RuntimeException $e) {
            return $this->error('console_unavailable', $e->getMessage(), 422);
        }

        $audit->record('server_console_opened', $sub, $ip, ['vmid' => $vmid, 'name' => $vm->name]);

        return response()->json(['data' => ['url' => $url]]);
    }

    private function sub(Request $request): string
    {
        return (string) $request->attributes->get('staff_sub');
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
