<?php

namespace Nawasara\AsnPortal\Http\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Nawasara\AsnPortal\Http\Resources\HelpItemResource;
use Nawasara\AsnPortal\Http\Resources\LegalDocumentResource;
use Nawasara\AsnPortal\Http\Resources\PortalAppResource;
use Nawasara\AsnPortal\Models\HelpItem;
use Nawasara\AsnPortal\Models\LegalDocument;
use Nawasara\AsnPortal\Models\PortalApp;
use Nawasara\AsnPortal\Support\LegalDocumentSchedule;

/**
 * Content for the portal's guest pages. No login: these are what a civil
 * servant sees before signing in, including the help for when they cannot.
 */
class PublicContentController extends Controller
{
    /** GET /public/apps */
    public function apps(): JsonResponse
    {
        return response()->json([
            'data' => PortalAppResource::collection(PortalApp::query()->active()->get()),
        ]);
    }

    /** GET /public/help?kind=faq|sign_in_help */
    public function help(Request $request): JsonResponse
    {
        $kind = $request->query('kind');

        $items = HelpItem::query()
            ->active()
            ->when(in_array($kind, HelpItem::KINDS, true), fn ($q) => $q->where('kind', $kind))
            ->get();

        return response()->json(['data' => HelpItemResource::collection($items)]);
    }

    /**
     * GET /public/legal/{type} — the version in force, plus the next one if
     * it has been announced. 404 while nothing is in force yet: a portal must
     * not show a draft as if it were the policy.
     */
    public function legal(string $type): JsonResponse
    {
        $published = LegalDocument::query()
            ->where('type', $type)
            ->whereNotNull('published_at')
            ->get()
            ->keyBy('id');

        $schedule = LegalDocumentSchedule::resolve(
            $published->map(fn (LegalDocument $d) => [
                'id' => $d->id,
                'effective_at' => $d->effective_at?->getTimestamp(),
            ])->values(),
            time(),
        );

        if ($schedule['current'] === null) {
            return response()->json([
                'error' => ['code' => 'not_published', 'message' => 'Dokumen ini belum diterbitkan.'],
            ], 404);
        }

        $upcoming = $schedule['upcoming'] !== null ? $published[$schedule['upcoming']['id']] : null;

        return response()->json([
            'data' => new LegalDocumentResource($published[$schedule['current']['id']]),
            'upcoming' => $upcoming ? [
                'version' => $upcoming->version,
                'effective_at' => $upcoming->effective_at?->toIso8601String(),
                'change_summary' => $upcoming->change_summary,
            ] : null,
        ]);
    }
}
