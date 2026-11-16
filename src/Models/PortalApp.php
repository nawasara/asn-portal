<?php

namespace Nawasara\AsnPortal\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * An application in the ASN portal catalog.
 *
 * Named PortalApp rather than App to avoid colliding with the application's
 * own `App\` namespace in every `use` statement.
 */
class PortalApp extends Model
{
    use LogsActivity;

    public const SECTION_WORKSPACE = 'workspace';

    public const SECTION_ECOSYSTEM = 'ecosystem';

    /** Launcher sections, in the order the menu shows them. */
    public const LAUNCHER_SECTIONS = [self::SECTION_WORKSPACE, self::SECTION_ECOSYSTEM];

    public const AUDIENCE_ALL = 'all';

    /** Shown only to whoever Kisara Workspace lets in (AccessDecider). */
    public const AUDIENCE_WORKSPACE_PILOT = 'workspace-pilot';

    public const LAUNCHER_AUDIENCES = [self::AUDIENCE_ALL, self::AUDIENCE_WORKSPACE_PILOT];

    /** Cache key for the launcher catalog; cleared on every save/delete. */
    public const LAUNCHER_CACHE_KEY = 'nawasara-asn-portal:launcher-catalog';

    protected $table = 'nawasara_asn_portal_apps';

    protected $fillable = [
        'slug', 'name', 'description', 'url', 'keycloak_client_id', 'category',
        'abbreviation', 'color', 'icon_url', 'sort_order', 'is_active',
        'show_in_portal', 'launcher_section', 'launcher_sort', 'launcher_audience',
        'launcher_label', 'launcher_icon',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
        'show_in_portal' => 'boolean',
        'launcher_sort' => 'integer',
    ];

    protected static function booted(): void
    {
        $forget = fn () => Cache::forget(self::LAUNCHER_CACHE_KEY);
        static::saved($forget);
        static::deleted($forget);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('name');
    }

    /** What Portal ASN lists: active rows that are not launcher-only. */
    public function scopeInPortal(Builder $query): Builder
    {
        return $query->active()->where('show_in_portal', true);
    }

    /** Active rows placed in the 9-dot launcher, in menu order. */
    public function scopeInLauncher(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->whereIn('launcher_section', self::LAUNCHER_SECTIONS)
            ->orderBy('launcher_sort')
            ->orderBy('name');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
