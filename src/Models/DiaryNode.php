<?php

namespace Platform\Diary\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Zuordnung Diary <-> Knoten (Einstellungen). Die Verknüpfung selbst ist ein DimensionLink;
 * diese Zeile trägt nur die Option "nach unten vererben" je Zuordnung.
 *
 * @property int $diary_id
 * @property int $entity_id
 * @property bool $inherit_down
 */
class DiaryNode extends Model
{
    protected $table = 'diary_nodes';

    protected $fillable = ['diary_id', 'entity_id', 'inherit_down'];

    protected $casts = [
        'diary_id' => 'integer',
        'entity_id' => 'integer',
        'inherit_down' => 'boolean',
    ];

    public function diary(): BelongsTo
    {
        return $this->belongsTo(Diary::class, 'diary_id');
    }
}
