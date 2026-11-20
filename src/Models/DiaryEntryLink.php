<?php

namespace Platform\Diary\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Polymorpher Verweis eines Eintrags auf Projekte, Kunden, Beschlüsse, Aufnahmen etc. */
class DiaryEntryLink extends Model
{
    protected $table = 'diary_entry_links';

    protected $fillable = ['entry_id', 'linkable_type', 'linkable_id'];

    public function entry(): BelongsTo
    {
        return $this->belongsTo(DiaryEntry::class, 'entry_id');
    }

    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }
}
