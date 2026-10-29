<?php

namespace Nawasara\AsnPortal;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\Finder\Finder;

/**
 * Backend for the ASN account portal (asn.ponorogo.go.id).
 *
 * API only. The portal is an Astro app that talks to Nawasara for every piece
 * of data and every action; it holds no credentials to other systems and no
 * content of its own (docs/plan/persiapan-nawasara-akun-asn.md, "Prinsip").
 */
class AsnPortalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/nawasara-asn-portal.php', 'nawasara-asn-portal');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'nawasara-asn-portal');

        $this->registerLivewire();
        $this->registerRateLimiters();
        $this->registerApiRoutes();
    }

    /**
     * Auto-discover Livewire components under src/Livewire.
     *
     * Alias = nawasara-asn-portal.<kebab-path>, so Livewire/App/Section/Table.php
     * becomes nawasara-asn-portal.app.section.table.
     */
    protected function registerLivewire(): void
    {
        $base = __DIR__.'/Livewire';

        if (! is_dir($base)) {
            return;
        }

        foreach ((new Finder)->files()->in($base)->name('*.php') as $file) {
            $relative = Str::of($file->getRelativePathname())
                ->replace(['/', '\\'], '\\')
                ->replace('.php', '');

            $class = 'Nawasara\\AsnPortal\\Livewire\\'.$relative;

            if (! class_exists($class)) {
                continue;
            }

            $alias = 'nawasara-asn-portal.'.Str::of($relative)
                ->explode('\\')
                ->map(fn ($segment) => Str::kebab($segment))
                ->implode('.');

            Livewire::component($alias, $class);
        }
    }

    /**
     * Guarded on class_exists rather than declaring routes unconditionally:
     * the route middleware aliases come from nawasara/api, and without it the
     * routes would fail at request time instead of simply not existing.
     */
    protected function registerApiRoutes(): void
    {
        if (! class_exists(\Nawasara\Api\ApiServiceProvider::class)) {
            return;
        }

        $prefix = (string) config('nawasara-api.route.prefix', 'api/v1').'/asn-portal';

        // An empty client id yields "api.client:" with no client, which the
        // middleware treats as reject-all. Unconfigured means closed.
        $client = trim((string) config('nawasara-asn-portal.client_id', ''));

        Route::prefix($prefix.'/account')
            ->middleware(['api', 'api.staff.identity', 'api.client:'.$client, 'throttle:nawasara-asn-portal'])
            ->name('nawasara-asn-portal.account.')
            ->group(__DIR__.'/../routes/account.php');

        Route::prefix($prefix.'/public')
            ->middleware(['api', 'throttle:nawasara-asn-portal-public'])
            ->name('nawasara-asn-portal.public.')
            ->group(__DIR__.'/../routes/public.php');
    }

    /**
     * Account routes are limited per USER (`sub`), not per IP: a whole OPD
     * office reaches us from one public address, and a per-IP limit would let
     * one busy office lock out its own staff. Public routes have no user, so
     * they are limited per IP.
     */
    protected function registerRateLimiters(): void
    {
        RateLimiter::for('nawasara-asn-portal', function (Request $request) {
            $sub = $request->attributes->get('staff_sub');

            return Limit::perMinute((int) config('nawasara-asn-portal.rate_limit_per_minute', 60))
                ->by(is_string($sub) && $sub !== '' ? 'asn-portal:'.$sub : 'asn-portal-ip:'.$request->ip());
        });

        RateLimiter::for('nawasara-asn-portal-public', function (Request $request) {
            return Limit::perMinute((int) config('nawasara-asn-portal.public_rate_limit_per_minute', 120))
                ->by('asn-portal-public:'.$request->ip());
        });
    }
}
