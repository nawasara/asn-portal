<?php

namespace Nawasara\AsnPortal\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Nawasara\AsnPortal\Events\KisaraAccessChanged;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * One person's access to one Kisara app (Akses Layanan Kisara).
 * Active = `removed_at` is null ("Nonaktifkan" sets it, the row stays).
 */
class AppAccess extends Model
{
    use LogsActivity;

    protected $table = 'nawasara_asn_portal_app_access';

    protected $fillable = ['nip', 'app', 'name', 'keycloak_id', 'stage', 'opd_id', 'note', 'added_by', 'removed_at', 'removed_by'];

    protected $casts = [
        'stage' => 'integer',
        'opd_id' => 'integer',
        'removed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        $changed = fn (self $row) => KisaraAccessChanged::dispatch($row->app);
        static::saved($changed);
        static::deleted($changed);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('kisara-access')
            ->logOnly(['nip', 'app', 'name', 'stage', 'opd_id', 'note', 'removed_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $event) => match ($event) {
                'created' => 'Akses layanan Kisara diberikan',
                'deleted' => 'Akses layanan Kisara dihapus',
                default => 'Akses layanan Kisara diubah',
            });
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('removed_at');
    }

    public function scopeForApp(Builder $query, string $app): Builder
    {
        return $query->where('app', $app);
    }

    public function isActive(): bool
    {
        return $this->removed_at === null;
    }
}
