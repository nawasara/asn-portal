<?php

namespace Nawasara\AsnPortal\Http\Api;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Nawasara\AsnPortal\Livewire\App\Section\Table;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/v1/asn-portal/public/icons/{file} — an uploaded app icon.
 *
 * Served by Laravel instead of through /storage: in production the
 * public/storage path is a separate empty volume, so `storage:link` never
 * takes and every /storage URL answers 404.
 *
 * File names carry a random suffix and never change content, so browsers and
 * Cloudflare may cache them for a year.
 */
class AppIconController extends Controller
{
    public function __invoke(string $file): Response
    {
        $path = Table::ICON_DIR.'/'.$file;
        $disk = Storage::disk('public');

        abort_unless($disk->exists($path), 404);

        return $disk->response($path, null, [
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
