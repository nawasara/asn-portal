<?php

namespace Nawasara\AsnPortal\Http\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Nawasara\AsnPortal\Services\KeycloakDirectory;
use Nawasara\AsnPortal\Services\MailboxDirectory;
use Nawasara\AsnPortal\Services\PortalAudit;
use Nawasara\AsnPortal\Services\SecurityNotifier;
use Nawasara\AsnPortal\Support\ClientIp;
use Nawasara\AsnPortal\Support\ForwardingPolicy;

/**
 * Official email in the account portal: the personal mailbox and the
 * agency mailboxes the user is a PIC of. Every action first resolves the
 * address through MailboxDirectory::find(), which only returns mailboxes
 * this user may manage, so an address from the request cannot reach
 * anyone else's mailbox.
 */
class MailboxController extends Controller
{
    public function __construct(
        protected MailboxDirectory $mailboxes,
        protected KeycloakDirectory $directory,
        protected PortalAudit $audit,
        protected SecurityNotifier $notifier,
    ) {}

    /** GET /account/mailboxes */
    public function index(Request $request): JsonResponse
    {
        $list = $this->mailboxes->forUser($this->sub($request), $this->accountEmail($request));

        return response()->json([
            'data' => $list->map(fn (array $m) => $this->present($m))->values(),
        ]);
    }

    /**
     * POST /account/mailboxes/{address}/webmail — a one-time webmail login
     * URL (WHM create_user_session). The user never sees the mailbox
     * password, which is what lets agency mailbox access end with the PIC
     * assignment.
     */
    public function webmail(Request $request, string $address): JsonResponse
    {
        $mailbox = $this->resolve($request, $address);

        if ($mailbox === null) {
            return $this->notFound();
        }

        if (! class_exists(\Nawasara\Whm\Services\WebmailSessionService::class)) {
            return $this->unavailable();
        }

        $ip = ClientIp::from($request);

        try {
            $session = app(\Nawasara\Whm\Services\WebmailSessionService::class)
                ->createWebmailUrl($mailbox['address'], $mailbox['instance']);
        } catch (\Throwable $e) {
            $this->logWebmail($request, $mailbox, 'failed', class_basename($e));

            return response()->json(['error' => [
                'code' => 'webmail_unavailable',
                'message' => 'Masuk otomatis ke webmail sedang tidak tersedia. Silakan coba lagi.',
            ]], 503);
        }

        $this->logWebmail($request, $mailbox, 'issued', null);
        $this->audit->record('webmail_opened', $this->sub($request), $ip, ['mailbox' => $mailbox['address'], 'kind' => $mailbox['kind']]);

        return response()->json(['data' => [
            'url' => $session['url'],
            'expires_in' => $session['expires_in'] ?? 300,
        ]]);
    }

    /**
     * GET /account/mailboxes/{address}/access — who opened an AGENCY mailbox
     * through the portal, so its PICs can see each other's use.
     */
    public function access(Request $request, string $address): JsonResponse
    {
        $mailbox = $this->resolve($request, $address);

        if ($mailbox === null || $mailbox['kind'] !== 'agency') {
            return $this->notFound();
        }

        $rows = \Spatie\Activitylog\Models\Activity::query()
            ->where('log_name', PortalAudit::LOG_NAME)
            ->where('event', 'webmail_opened')
            ->where('properties->mailbox', $mailbox['address'])
            ->where('created_at', '>=', now()->subDays(90))
            ->latest()
            ->limit(100)
            ->get();

        $names = $this->namesBySub($rows->map(fn ($a) => $a->getExtraProperty('sub'))->filter()->unique()->all());

        return response()->json(['data' => $rows->map(fn ($a) => [
            'at' => $a->created_at->toIso8601String(),
            'by' => $names[$a->getExtraProperty('sub')] ?? 'PIC',
        ])->values()]);
    }

    /** PUT /account/mailboxes/{address}/password — personal mailbox only, fresh login. */
    public function password(Request $request, string $address): JsonResponse
    {
        $mailbox = $this->resolve($request, $address);

        if ($mailbox === null || $mailbox['kind'] !== 'personal') {
            return $this->notFound();
        }

        $password = (string) $request->input('password');

        if ($problem = ForwardingPolicy::passwordProblem($password, $mailbox['address'])) {
            return response()->json(['error' => ['code' => 'weak_password', 'message' => $problem]], 422);
        }

        $result = $this->whm($mailbox)->changeEmailPassword($mailbox['cpanel_user'], $mailbox['address'], $password);

        if (! ($result['success'] ?? false)) {
            return $this->whmFailed();
        }

        $this->audit->record('mailbox_password_changed', $this->sub($request), ClientIp::from($request), ['mailbox' => $mailbox['address']]);
        $this->notifier->alert($this->accountEmail($request), 'Kata sandi email dinas diganti', [
            'Kata sandi '.$mailbox['address'].' baru saja diganti lewat Akun ASN.',
            'Aplikasi email di ponsel atau Outlook perlu diisi kata sandi yang baru.',
        ]);

        return response()->json(null, 204);
    }

    /** GET /account/mailboxes/{address}/auto-reply */
    public function showAutoReply(Request $request, string $address): JsonResponse
    {
        $mailbox = $this->resolve($request, $address);

        if ($mailbox === null) {
            return $this->notFound();
        }

        return response()->json(['data' => $this->whm($mailbox)->getAutoResponder($mailbox['cpanel_user'], $mailbox['address'])]);
    }

    /** PUT /account/mailboxes/{address}/auto-reply {subject, body, start?, stop?} */
    public function setAutoReply(Request $request, string $address): JsonResponse
    {
        $mailbox = $this->resolve($request, $address);

        if ($mailbox === null) {
            return $this->notFound();
        }

        $data = $request->validate([
            'subject' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:3000'],
            'start' => ['nullable', 'date'],
            'stop' => ['nullable', 'date', 'after:start'],
        ]);

        $result = $this->whm($mailbox)->setAutoResponder(
            $mailbox['cpanel_user'],
            $mailbox['address'],
            $mailbox['address'],
            $data['subject'],
            $data['body'],
            isset($data['start']) ? strtotime($data['start']) : null,
            isset($data['stop']) ? strtotime($data['stop']) : null,
        );

        if (! ($result['success'] ?? false)) {
            return $this->whmFailed();
        }

        $this->audit->record('mailbox_auto_reply_set', $this->sub($request), ClientIp::from($request), ['mailbox' => $mailbox['address']]);

        return response()->json(null, 204);
    }

    /** DELETE /account/mailboxes/{address}/auto-reply */
    public function clearAutoReply(Request $request, string $address): JsonResponse
    {
        $mailbox = $this->resolve($request, $address);

        if ($mailbox === null) {
            return $this->notFound();
        }

        $this->whm($mailbox)->deleteAutoResponder($mailbox['cpanel_user'], $mailbox['address']);
        $this->audit->record('mailbox_auto_reply_cleared', $this->sub($request), ClientIp::from($request), ['mailbox' => $mailbox['address']]);

        return response()->json(null, 204);
    }

    /** GET /account/mailboxes/{address}/forwarders */
    public function forwarders(Request $request, string $address): JsonResponse
    {
        $mailbox = $this->resolve($request, $address);

        if ($mailbox === null) {
            return $this->notFound();
        }

        return response()->json(['data' => $this->whm($mailbox)->listForwarders($mailbox['cpanel_user'], $mailbox['address'])]);
    }

    /** POST /account/mailboxes/{address}/forwarders {destination} — fresh login */
    public function addForwarder(Request $request, string $address): JsonResponse
    {
        $mailbox = $this->resolve($request, $address);

        if ($mailbox === null) {
            return $this->notFound();
        }

        $destination = strtolower(trim((string) $request->input('destination')));
        $allowed = (array) config('nawasara-asn-portal.mail.forward_allowed_domains', ['ponorogo.go.id']);

        if (! ForwardingPolicy::allows($destination, $allowed)) {
            return response()->json(['error' => [
                'code' => 'destination_not_allowed',
                'message' => 'Email dinas hanya boleh diteruskan ke alamat '.implode(', ', $allowed).'.',
            ]], 422);
        }

        $result = $this->whm($mailbox)->addForwarder($mailbox['cpanel_user'], $mailbox['address'], $destination);

        if (! ($result['success'] ?? false)) {
            return $this->whmFailed();
        }

        $this->audit->record('mailbox_forwarder_added', $this->sub($request), ClientIp::from($request), ['mailbox' => $mailbox['address'], 'destination' => $destination]);
        $this->notifier->alert($this->accountEmail($request), 'Penerusan email ditambahkan', [
            'Email ke '.$mailbox['address'].' kini juga diteruskan ke '.$destination.'.',
        ]);

        return response()->json(null, 204);
    }

    /** DELETE /account/mailboxes/{address}/forwarders {destination} */
    public function removeForwarder(Request $request, string $address): JsonResponse
    {
        $mailbox = $this->resolve($request, $address);

        if ($mailbox === null) {
            return $this->notFound();
        }

        $destination = strtolower(trim((string) $request->input('destination')));

        if (! in_array($destination, $this->whm($mailbox)->listForwarders($mailbox['cpanel_user'], $mailbox['address']), true)) {
            return $this->notFound();
        }

        $this->whm($mailbox)->deleteForwarder($mailbox['cpanel_user'], $mailbox['address'], $destination);
        $this->audit->record('mailbox_forwarder_removed', $this->sub($request), ClientIp::from($request), ['mailbox' => $mailbox['address'], 'destination' => $destination]);

        return response()->json(null, 204);
    }

    // ------------------------------------------------------------------------

    private function resolve(Request $request, string $address): ?array
    {
        return $this->mailboxes->find($this->sub($request), $this->accountEmail($request), urldecode($address));
    }

    private function present(array $m): array
    {
        return [
            'address' => $m['address'],
            'kind' => $m['kind'],
            'role' => $m['role'],
            'pics' => $m['pics'],
            'quota_mb' => $m['quota_mb'],
            'used_mb' => $m['used_mb'],
            'used_percent' => $m['quota_mb'] ? (int) round(($m['used_mb'] ?? 0) / $m['quota_mb'] * 100) : null,
        ];
    }

    private function whm(array $mailbox): object
    {
        return app(\Nawasara\Whm\Services\WhmClient::class)->forInstance($mailbox['instance']);
    }

    private function logWebmail(Request $request, array $mailbox, string $status, ?string $error): void
    {
        if (! class_exists(\Nawasara\Whm\Models\WebmailSession::class)) {
            return;
        }

        try {
            $userModel = config('auth.providers.users.model');

            \Nawasara\Whm\Models\WebmailSession::create([
                'user_id' => $userModel::query()->where('keycloak_id', $this->sub($request))->value('id'),
                'email_account' => $mailbox['address'],
                'launch_kind' => 'self',
                'reason' => 'asn-portal:'.$mailbox['kind'],
                'ip' => ClientIp::from($request),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
                'status' => $status,
                'error' => $error,
            ]);
        } catch (\Throwable) {
            // Audit must not block the user; PortalAudit has its own record.
        }
    }

    /** @return array<string, string> */
    private function namesBySub(array $subs): array
    {
        $userModel = config('auth.providers.users.model');

        return $userModel::query()->whereIn('keycloak_id', $subs)->pluck('name', 'keycloak_id')->all();
    }

    private function accountEmail(Request $request): ?string
    {
        $email = $request->attributes->get('staff_claims')['email'] ?? null;

        return is_string($email) ? $email : null;
    }

    private function sub(Request $request): string
    {
        return (string) $request->attributes->get('staff_sub');
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['error' => ['code' => 'not_found', 'message' => 'Kotak email tidak ditemukan.']], 404);
    }

    private function unavailable(): JsonResponse
    {
        return response()->json(['error' => ['code' => 'mail_unavailable', 'message' => 'Layanan email belum tersedia.']], 503);
    }

    private function whmFailed(): JsonResponse
    {
        return response()->json(['error' => [
            'code' => 'mail_server_error',
            'message' => 'Server email menolak perubahan ini. Silakan coba lagi atau hubungi RAKACA.',
        ]], 502);
    }
}
