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

        $this->app->singleton(Services\KeycloakDirectory::class);

        // Codes are HMAC'd with the app key, so a cache dump alone does not
        // reveal them.
        $this->app->bind(Services\OtpChallenge::class, fn ($app) => new Services\OtpChallenge(
            $app['cache']->store(),
            (string) $app['config']->get('app.key'),
            (int) config('nawasara-asn-portal.otp.ttl_minutes', 15),
            (int) config('nawasara-asn-portal.otp.max_sends', 3),
            (int) config('nawasara-asn-portal.otp.send_window_minutes', 10),
            (int) config('nawasara-asn-portal.otp.max_verify_attempts', 5),
        ));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'nawasara-asn-portal');

        $this->registerLivewire();
        $this->registerRateLimiters();
        $this->registerApiScopes();
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

        // Registered BEFORE the open public group: same prefix, and these
        // routes need the server token, not just a rate limit.
        Route::prefix($prefix.'/public/mfa-reset')
            ->middleware(['api', 'api.auth', 'scope:asn-portal.mfa.reset', 'api.log', 'throttle:nawasara-asn-portal-mfa'])
            ->name('nawasara-asn-portal.mfa-reset.')
            ->group(__DIR__.'/../routes/mfa-reset.php');

        Route::prefix($prefix.'/public')
            ->middleware(['api', 'throttle:nawasara-asn-portal-public'])
            ->name('nawasara-asn-portal.public.')
            ->group(__DIR__.'/../routes/public.php');
    }

    /**
     * Scope for the portal server's own nws_ token. Registered before the
     * routes, or the scope cannot be assigned to a token at all and the
     * routes reject everything (reference_nawasara_api_scope).
     */
    protected function registerApiScopes(): void
    {
        if (! class_exists(\Nawasara\Api\Support\ScopeRegistry::class)) {
            return;
        }

        $this->app->make(\Nawasara\Api\Support\ScopeRegistry::class)->register(
            'asn-portal.mfa.reset',
            'Reset verifikasi dua langkah ASN tanpa login (kirim + verifikasi kode). Hanya untuk server Akun ASN; jangan digabung dengan scope lain.',
        );
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

        // Keyed on the REAL requester (forwarded by the portal server), not
        // the server's own address, which is the same for everybody.
        RateLimiter::for('nawasara-asn-portal-mfa', function (Request $request) {
            return Limit::perMinute((int) config('nawasara-asn-portal.mfa_reset_rate_per_minute', 10))
                ->by('asn-portal-mfa:'.\Nawasara\AsnPortal\Support\ClientIp::from($request));
        });

        RateLimiter::for('nawasara-asn-portal-public', function (Request $request) {
            return Limit::perMinute((int) config('nawasara-asn-portal.public_rate_limit_per_minute', 120))
                ->by('asn-portal-public:'.$request->ip());
        });
    }
}
