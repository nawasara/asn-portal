<?php

namespace Nawasara\AsnPortal\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * One version of the privacy policy or the terms of service.
 *
 * A published version is immutable: a change is a new version, so what a
 * user agreed to on a given date stays readable. Editing is allowed only
 * while `published_at` is null (a draft).
 */
class LegalDocument extends Model
{
    use LogsActivity;

    public const TYPE_PRIVACY = 'privacy';

    public const TYPE_TERMS = 'terms';

    public const TYPES = [self::TYPE_PRIVACY, self::TYPE_TERMS];

    protected $table = 'nawasara_asn_portal_legal_documents';

    protected $fillable = [
        'type', 'version', 'title', 'body', 'change_summary', 'effective_at', 'published_at', 'published_by',
    ];

    protected $casts = [
        'effective_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $document) {
            if ($document->getOriginal('published_at') !== null) {
                throw new LogicException('A published legal document cannot be changed; create a new version instead.');
            }
        });

        static::deleting(function (self $document) {
            if ($document->published_at !== null) {
                throw new LogicException('A published legal document cannot be deleted.');
            }
        });
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    public function getActivitylogOptions(): LogOptions
    {
        // The body itself is not logged: it can be tens of kilobytes and is
        // already preserved by the immutable row.
        return LogOptions::defaults()
            ->logOnly(['type', 'version', 'title', 'effective_at', 'published_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
