<?php

namespace Platform\Diary\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Gate;
use Platform\Core\Models\User;
use Platform\Diary\Enums\DiaryEntryType;
use Platform\Diary\Enums\DiaryEntryVisibility;
use Symfony\Component\Uid\UuidV7;

/**
 * @ai.description Diary-Eintrag = einzelner Tagebuch-Eintrag (Markdown) in einem Diary, mit optionaler Art (Notiz/Entscheidung/Erkenntnis/Reflexion/Idee), Tags und Verweisen auf andere Objekte. Sichtbarkeit: privat (Default, nur Autor/Diary-Owner) oder Knoten (alle mit Zugriff auf das Diary).
 *
 * @property-read string $uuid
 */
class DiaryEntry extends Model
{
    use SoftDeletes;

    protected $table = 'diary_entries';

    protected $fillable = [
        'uuid',
        'team_id',
        'diary_id',
        'entry_at',
        'title',
        'content',
        'type',
        'visibility',
        'author_user_id',
        'tags',
        'created_by_user_id',
    ];

    protected $casts = [
        'uuid' => 'string',
        'entry_at' => 'datetime',
        'type' => DiaryEntryType::class,
        'visibility' => DiaryEntryVisibility::class,
        'tags' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                do {
                    $uuid = UuidV7::generate();
                } while (self::withTrashed()->where('uuid', $uuid)->exists());
                $model->uuid = $uuid;
            }
            if (empty($model->visibility)) {
                $model->visibility = DiaryEntryVisibility::PRIVATE;
            }
            if (empty($model->entry_at)) {
                $model->entry_at = now();
            }
        });
    }

    public function diary(): BelongsTo
    {
        return $this->belongsTo(Diary::class, 'diary_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function links(): HasMany
    {
        return $this->hasMany(DiaryEntryLink::class, 'entry_id');
    }

    /**
     * Sichtbarkeit pro Eintrag (zentral, gilt für Tools, UI und Suche/Qdrant-Payload):
     * Autor und Diary-Owner sehen immer; visibility=node zusätzlich jeder mit Lesezugriff aufs Diary.
     * Spiegelt isVisibleTo().
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $table = $query->getModel()->getTable();
        $diaryIds = Diary::query()->visibleTo($user)->select('diaries.id');
        $ownedDiaryIds = Diary::query()->where('owner_user_id', $user->id)->select('diaries.id');

        return $query->where(function (Builder $q) use ($user, $table, $diaryIds, $ownedDiaryIds) {
            $q->where($table . '.author_user_id', $user->id)
                ->orWhereIn($table . '.diary_id', $ownedDiaryIds)
                ->orWhere(function (Builder $n) use ($table, $diaryIds) {
                    $n->where($table . '.visibility', DiaryEntryVisibility::NODE->value)
                        ->whereIn($table . '.diary_id', $diaryIds);
                });
        });
    }

    public function isVisibleTo(User $user): bool
    {
        if ($this->author_user_id && (int) $this->author_user_id === (int) $user->id) {
            return true;
        }
        $diary = $this->diary;
        if (! $diary) {
            return false;
        }
        if ($diary->owner_user_id && (int) $diary->owner_user_id === (int) $user->id) {
            return true;
        }

        return $this->visibility === DiaryEntryVisibility::NODE
            && Gate::forUser($user)->allows('view', $diary);
    }

    /** Ändern/Löschen: Autor oder Diary-Owner, sonst wer das Diary schreiben darf (nur bei sichtbarem Eintrag). */
    public function isEditableBy(User $user): bool
    {
        if (! $this->isVisibleTo($user)) {
            return false;
        }
        if ($this->author_user_id && (int) $this->author_user_id === (int) $user->id) {
            return true;
        }

        return $this->diary !== null && Gate::forUser($user)->allows('update', $this->diary);
    }
}
