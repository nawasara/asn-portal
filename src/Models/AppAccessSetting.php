<?php

namespace Nawasara\AsnPortal\Models;

use Illuminate\Database\Eloquent\Model;
use Nawasara\AsnPortal\Events\KisaraAccessChanged;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Per-app switches of Akses Layanan Kisara. No row = both off: the app is in
 * pilot and only listed people may use it. Read through KisaraAccess, which
 * caches it briefly; write through KisaraAccess::setFlag so the cache is
 * dropped at once.
 */
class AppAccessSetting extends Model
{
    use LogsActivity;

    protected $table = 'nawasara_asn_portal_app_access_settings';

    protected $fillable = ['app', 'kill_switch', 'open_to_all', 'updated_by'];

    protected $casts = [
        'kill_switch' => 'boolean',
        'open_to_all' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saved(fn (self $row) => KisaraAccessChanged::dispatch($row->app));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('kisara-access')
            ->logOnly(['app', 'kill_switch', 'open_to_all'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn () => 'Pengaturan akses layanan Kisara diubah');
    }
}
