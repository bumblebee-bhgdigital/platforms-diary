<?php

namespace Platform\Diary\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\Core\Contracts\HasDisplayName;
use Platform\Core\Models\Concerns\HasEntityLinks;
use Platform\Diary\Enums\DiaryStatus;
use Symfony\Component\Uid\UuidV7;

/**
 * @ai.description Diary = persönliches Tagebuch (die Einträge selbst folgen in eigenen Boards). Wird an Knoten (Organisationseinheiten) gehängt; ein Diary ist an mehreren Knoten wiederverwendbar, die Vererbung nach unten ist pro Zuordnung wählbar.
 *
 * Morph-Alias: `diary` (Relation::morphMap im ServiceProvider). Der Knoten hängt NICHT als
 * Fremdschlüssel am Diary, sondern lose per DimensionLink (Entity-Dimension der Organization) —
 * wie PlannerProject, aber mit mehreren Knoten pro Diary. Die Vererbungs-Option je Zuordnung liegt
 * in `diary_nodes` (DiaryNode). Verknüpfen/Lösen/Umhängen laufen über DiaryService.
 *
 * Die Einträge selbst sind nicht Teil der Basis und folgen in eigenen Boards.
 *
 * @property-read string $uuid
 */
class Diary extends Model implements HasDisplayName
{
    use SoftDeletes, HasEntityLinks;

    /** Morph-Alias für DimensionLinks / EntityLinkProvider. */
    public const MORPH_ALIAS = 'diary';

    protected $table = 'diaries';

    protected $fillable = [
        'uuid',
        'team_id',
        'title',
        'description',
        'status',
        'owner_user_id',
        'responsible_agent_user_id',
        'created_by_user_id',
    ];

    protected $casts = [
        'uuid' => 'string',
        'status' => DiaryStatus::class,
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
            if (empty($model->status)) {
                $model->status = DiaryStatus::DRAFT;
            }
        });
    }

    // ── Beziehungen ──────────────────────────────────────────────

    public function team(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\Team::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\User::class, 'owner_user_id');
    }

    public function responsibleAgent(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\User::class, 'responsible_agent_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\User::class, 'created_by_user_id');
    }

    /** Zuordnungs-Einstellungen je Knoten (Vererbung nach unten). */
    public function nodeSettings(): HasMany
    {
        return $this->hasMany(DiaryNode::class, 'diary_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(DiaryEntry::class, 'diary_id');
    }

    // ── Anzeige ──────────────────────────────────────────────────

    public function getDisplayName(): ?string
    {
        return $this->title;
    }

    // ── Scopes / Sichtbarkeit ────────────────────────────────────

    public function scopeStatus(Builder $query, DiaryStatus|string $status): Builder
    {
        return $query->where($query->getModel()->getTable() . '.status', $status instanceof DiaryStatus ? $status->value : $status);
    }

    /**
     * Sichtbar = Core-Graph (Ersteller ODER über den Org-Baum erreichbar, read) ODER Owner des Diary.
     * Spiegelt DiaryPolicy::view().
     */
    public function scopeVisibleTo(Builder $query, \Platform\Core\Models\User $user): Builder
    {
        $table = $query->getModel()->getTable();

        return $query->where(function (Builder $q) use ($user, $table) {
            $q->authzVisibleTo($user, 'read')
                ->orWhere($table . '.owner_user_id', $user->id);
        });
    }

    /** Alias wie im Planner (Sidebar). */
    public function scopeViewableBy(Builder $query, \Platform\Core\Models\User $user): Builder
    {
        return $this->scopeVisibleTo($query, $user);
    }

    public function isActive(): bool
    {
        return $this->status === DiaryStatus::ACTIVE;
    }
}
