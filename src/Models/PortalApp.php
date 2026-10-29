<?php

namespace Nawasara\AsnPortal\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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

    protected $table = 'nawasara_asn_portal_apps';

    protected $fillable = [
        'slug', 'name', 'description', 'url', 'keycloak_client_id', 'category',
        'abbreviation', 'color', 'icon_url', 'sort_order', 'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('name');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
