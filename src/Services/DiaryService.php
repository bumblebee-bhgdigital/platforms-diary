<?php

namespace Platform\Diary\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\Diary\Enums\DiaryStatus;
use Platform\Organization\Models\OrganizationEntity;
use Platform\Organization\Services\EntityDimensionBridge;
use Platform\Diary\Events\DiaryLinkChanged;
use Platform\Diary\Exceptions\DiaryException;
use Platform\Diary\Models\Diary;
use Platform\Diary\Models\DiaryNode;

/**
 * Use-Cases rund ums Diary (analog ProjectCreationService/LifecycleService im Planner).
 *
 * Der Service autorisiert NICHT — Rechte werden an der Grenze (Livewire, MCP-Tool) per
 * Gate/DiaryPolicy geprüft, wie im Planner. Er validiert nur Fachregeln.
 */
class DiaryService
{
    /** Felder, die create()/update() aus $data übernehmen. */
    private const FIELDS = ['title', 'description', 'owner_user_id', 'responsible_agent_user_id'];

    // ── Lesen ────────────────────────────────────────────────────

    public function find(int $id, bool $withTrashed = false): ?Diary
    {
        $query = Diary::query();
        if ($withTrashed) {
            $query->withTrashed();
        }

        return $query->find($id);
    }

    /**
     * Diaries eines Teams (optional Status-Filter). Sichtbarkeit ($viewer) = Graph-Scope wie die UI.
     *
     * @param int|int[] $teamIds
     */
    public function listForTeams(int|array $teamIds, ?User $viewer = null, ?DiaryStatus $status = null): Collection
    {
        $query = Diary::query()
            ->whereIn('team_id', (array) $teamIds)
            ->orderBy('title');

        if ($viewer) {
            $query->visibleTo($viewer);
        }
        if ($status) {
            $query->status($status);
        }

        return $query->get();
    }

    // ── Anlegen / Ändern ─────────────────────────────────────────

    /**
     * @param array<string,mixed> $data title (Pflicht) + Felder aus self::FIELDS, optional status (draft|active)
     */
    public function create(User $creator, Team|int $team, array $data): Diary
    {
        $teamId = $team instanceof Team ? $team->id : $team;
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            throw new DiaryException('Titel ist erforderlich.');
        }

        $status = DiaryStatus::DRAFT;
        if (! empty($data['status'])) {
            $status = DiaryStatus::tryFrom((string) $data['status'])
                ?? throw new DiaryException('status muss einer von ' . implode(', ', DiaryStatus::values()) . ' sein.');
            if (! in_array($status, [DiaryStatus::DRAFT, DiaryStatus::ACTIVE], true)) {
                throw new DiaryException('Neue Diaries starten als draft oder active.');
            }
        }

        $attrs = $this->validated($data, $teamId);
        $attrs['title'] = $title;
        $attrs['team_id'] = $teamId;
        $attrs['created_by_user_id'] = $creator->id;
        $attrs['owner_user_id'] = $attrs['owner_user_id'] ?? $creator->id;
        $attrs['status'] = $status;

        return Diary::create($attrs)->refresh();
    }

    /** @param array<string,mixed> $data nur gesetzte Schlüssel werden geändert (null leert, wo erlaubt) */
    public function update(Diary $diary, array $data): Diary
    {
        if (array_key_exists('title', $data) && trim((string) $data['title']) === '') {
            throw new DiaryException('Titel darf nicht leer sein.');
        }

        $attrs = $this->validated($data, $diary->team_id);
        if (array_key_exists('title', $data)) {
            $attrs['title'] = trim((string) $data['title']);
        }

        $diary->fill($attrs)->save();

        return $diary->refresh();
    }

    // ── Statuswechsel ────────────────────────────────────────────

    public function activate(Diary $diary): Diary
    {
        if ($diary->trashed()) {
            throw new DiaryException('Ein gelöschtes Diary kann nicht aktiviert werden.', 'INVALID_STATE');
        }
        if ($diary->status !== DiaryStatus::ACTIVE) {
            $diary->status = DiaryStatus::ACTIVE;
            $diary->save();
        }

        return $diary->refresh();
    }

    public function archive(Diary $diary): Diary
    {
        if ($diary->status !== DiaryStatus::ARCHIVED) {
            $diary->status = DiaryStatus::ARCHIVED;
            $diary->save();
        }

        return $diary->refresh();
    }

    /** Soft-Delete (wiederherstellbar). Die Knoten-Verknüpfungen bleiben bestehen, wie beim Planner-Projekt. */
    public function delete(Diary $diary): void
    {
        DB::transaction(fn () => $diary->delete());
    }

    // ── Knoten-Zuordnung (Dimension Bridge) ──────────────────────

    /** Knoten (Entity-IDs), an denen das Diary hängt. @return int[] */
    public function nodeIds(Diary $diary): array
    {
        return EntityDimensionBridge::linksForLinkables([Diary::MORPH_ALIAS], [$diary->id], false)
            ->pluck('entity_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /**
     * Zuordnungen des Diary: entity_id => inherit_down. Knoten ohne Settings-Zeile (z. B. extern per
     * organization.entity.link.POST verknüpft) gelten als nicht vererbend.
     *
     * @return array<int,bool>
     */
    public function nodeAssignments(Diary $diary): array
    {
        $inherit = DiaryNode::query()->where('diary_id', $diary->id)->pluck('inherit_down', 'entity_id');

        $out = [];
        foreach ($this->nodeIds($diary) as $entityId) {
            $out[$entityId] = (bool) ($inherit[$entityId] ?? false);
        }

        return $out;
    }

    /*
     * Hinweis zu Events: Die Bridge löscht per Query (keine Model-Events). LINKED kommt daher beim
     * Anlegen aus dem DimensionLink-Hook (DiaryServiceProvider, greift auch bei externen
     * Verknüpfungen), UNLINKED/RELINKED/INHERIT_CHANGED dispatcht dieser Service.
     */

    /** Diary an einen weiteren Knoten hängen (zusätzlich zu bestehenden Knoten). */
    public function link(Diary $diary, int $entityId, bool $inheritDown = false): void
    {
        DB::transaction(function () use ($diary, $entityId, $inheritDown) {
            EntityDimensionBridge::createLink($entityId, Diary::MORPH_ALIAS, $diary->id, ['team_id' => $diary->team_id]);
            EntityDimensionBridge::flush();

            DiaryNode::query()->updateOrCreate(
                ['diary_id' => $diary->id, 'entity_id' => $entityId],
                ['inherit_down' => $inheritDown],
            );
        });

    }

    /** Vererbung nach unten für eine bestehende Zuordnung ein-/ausschalten. */
    public function setInherit(Diary $diary, int $entityId, bool $inheritDown): void
    {
        if (! in_array($entityId, $this->nodeIds($diary), true)) {
            throw new DiaryException("Das Diary hängt nicht an Knoten {$entityId}.", 'NOT_LINKED');
        }

        DiaryNode::query()->updateOrCreate(
            ['diary_id' => $diary->id, 'entity_id' => $entityId],
            ['inherit_down' => $inheritDown],
        );

        DiaryLinkChanged::dispatch($diary, DiaryLinkChanged::INHERIT_CHANGED, $entityId, null, $inheritDown);
    }

    /** Zuordnung zu einem Knoten lösen; ohne $entityId alle Knoten (Diary wird "unverknüpft"). */
    public function unlink(Diary $diary, ?int $entityId = null): void
    {
        $nodes = $entityId ? [$entityId] : $this->nodeIds($diary);

        DB::transaction(function () use ($diary, $nodes) {
            foreach ($nodes as $id) {
                EntityDimensionBridge::deleteLink($id, Diary::MORPH_ALIAS, $diary->id);
            }
            DiaryNode::query()->where('diary_id', $diary->id)->whereIn('entity_id', $nodes)->delete();
        });
        EntityDimensionBridge::flush();

        foreach ($nodes as $id) {
            DiaryLinkChanged::dispatch($diary, DiaryLinkChanged::UNLINKED, $id);
        }
    }

    /**
     * Umhängen: ersetzt die Zuordnung zu $fromEntityId durch $toEntityId. Die übrigen Knoten bleiben
     * unberührt (ein Diary hängt an mehreren Knoten). Die Vererbungs-Option wandert mit.
     */
    public function relink(Diary $diary, int $fromEntityId, int $toEntityId): void
    {
        $assignments = $this->nodeAssignments($diary);
        if (! array_key_exists($fromEntityId, $assignments)) {
            throw new DiaryException("Das Diary hängt nicht an Knoten {$fromEntityId}.", 'NOT_LINKED');
        }
        if ($fromEntityId === $toEntityId) {
            return;
        }

        $inherit = $assignments[$fromEntityId];

        DB::transaction(function () use ($diary, $fromEntityId, $toEntityId, $inherit) {
            EntityDimensionBridge::deleteLink($fromEntityId, Diary::MORPH_ALIAS, $diary->id);
            DiaryNode::query()->where('diary_id', $diary->id)->where('entity_id', $fromEntityId)->delete();
            EntityDimensionBridge::createLink($toEntityId, Diary::MORPH_ALIAS, $diary->id, ['team_id' => $diary->team_id]);
            DiaryNode::query()->updateOrCreate(
                ['diary_id' => $diary->id, 'entity_id' => $toEntityId],
                ['inherit_down' => $inherit],
            );
        });
        EntityDimensionBridge::flush();

        DiaryLinkChanged::dispatch($diary, DiaryLinkChanged::RELINKED, $toEntityId, $fromEntityId, $inherit);
    }

    /**
     * Diaries, die an einem Knoten wirken: direkt verknüpfte und (optional) von Vorfahren vererbte.
     * Direkt hat Vorrang: hängt ein Diary direkt UND vererbt am Knoten, erscheint es nur als "direct".
     *
     * @return SupportCollection<int, array{diary: Diary, source: 'direct'|'inherited', via_entity_id: int}>
     */
    public function diariesForNode(int $entityId, bool $includeInherited = true): SupportCollection
    {
        $result = collect();

        $direct = $this->diaryIdsAtNodes([$entityId]);
        foreach (Diary::query()->whereIn('id', $direct)->orderBy('title')->get() as $diary) {
            $result->put($diary->id, ['diary' => $diary, 'source' => 'direct', 'via_entity_id' => $entityId]);
        }

        if ($includeInherited) {
            foreach ($this->ancestorIds($entityId) as $ancestorId) {
                $inheritingIds = DiaryNode::query()
                    ->where('entity_id', $ancestorId)->where('inherit_down', true)
                    ->pluck('diary_id')->map(fn ($id) => (int) $id)->all();
                $inheritingIds = array_values(array_diff(
                    array_intersect($inheritingIds, $this->diaryIdsAtNodes([$ancestorId])),
                    $result->keys()->all()
                ));
                if ($inheritingIds === []) {
                    continue;
                }
                foreach (Diary::query()->whereIn('id', $inheritingIds)->orderBy('title')->get() as $diary) {
                    $result->put($diary->id, ['diary' => $diary, 'source' => 'inherited', 'via_entity_id' => $ancestorId]);
                }
            }
        }

        return $result->values();
    }

    /** Vorfahren eines Knotens (nächster Elternknoten zuerst, strukturell über parent_entity_id). @return int[] */
    public function ancestorIds(int $entityId): array
    {
        $ids = [];
        $current = $entityId;
        for ($depth = 0; $depth < 50; $depth++) {
            $parent = OrganizationEntity::query()->whereKey($current)->value('parent_entity_id');
            if (! $parent || in_array((int) $parent, $ids, true)) {
                break;
            }
            $ids[] = (int) $parent;
            $current = (int) $parent;
        }

        return $ids;
    }

    /** @param int[] $entityIds @return int[] Diary-IDs, die direkt an den Knoten hängen */
    private function diaryIdsAtNodes(array $entityIds): array
    {
        return EntityDimensionBridge::linksForEntities($entityIds)
            ->where('linkable_type', Diary::MORPH_ALIAS)
            ->pluck('linkable_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    // ── intern ───────────────────────────────────────────────────

    /**
     * Validiert/normalisiert die Eingabefelder. Nur vorhandene Schlüssel kommen zurück.
     *
     * @return array<string,mixed>
     */
    private function validated(array $data, int $teamId): array
    {
        $out = [];
        foreach (self::FIELDS as $field) {
            if (array_key_exists($field, $data) && $field !== 'title') {
                $out[$field] = $data[$field] === '' ? null : $data[$field];
            }
        }

        if (! empty($out['owner_user_id'])) {
            $owner = User::find((int) $out['owner_user_id']);
            if (! $owner || ! $owner->teams()->where('teams.id', $teamId)->exists()) {
                throw new DiaryException('owner_user_id ist kein Mitglied des Teams.');
            }
        }

        if (! empty($out['responsible_agent_user_id'])) {
            $agent = User::find((int) $out['responsible_agent_user_id']);
            if (! $agent || $agent->type !== 'ai_user') {
                throw new DiaryException('responsible_agent_user_id muss ein Agent-User (type ai_user) sein.');
            }
        }

        return $out;
    }
}
