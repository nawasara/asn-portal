<?php

namespace Nawasara\AsnPortal\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A FAQ entry or a "Tidak bisa masuk?" way out. Answers are Markdown.
 */
class HelpItem extends Model
{
    use LogsActivity;

    public const KIND_FAQ = 'faq';

    public const KIND_SIGN_IN_HELP = 'sign_in_help';

    public const KINDS = [self::KIND_FAQ, self::KIND_SIGN_IN_HELP];

    protected $table = 'nawasara_asn_portal_help_items';

    protected $fillable = [
        'slug', 'kind', 'question', 'answer', 'action_label', 'action_url', 'sort_order', 'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
