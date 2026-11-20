<?php

namespace Platform\Diary\Livewire\Concerns;

use Illuminate\Support\Facades\Gate;
use Platform\Organization\Authz\DimensionLinkAuthorizer;
use Platform\Organization\Models\OrganizationEntity;
use Platform\Diary\Models\Diary;
use Platform\Diary\Services\DiaryService;

/**
 * Verknüpfen / Umhängen / Lösen eines Diaries an Knoten aus der UI (Sidebar, Detailseite).
 *
 * Nutzt die Dimension Bridge über DiaryService (analog ProjectsCleanup::replaceLinks im Planner),
 * prüft Schreibrecht auf das Diary (Policy) UND auf den Zielknoten (Core-Graph, writableEntityIds) und
 * dispatcht anschließend das UI-Event `updateSidebar`, auf das die Sidebar hört.
 *
 * Unterschied zum Planner: ein Diary hängt an mehreren Knoten. "Verknüpfen" fügt einen Knoten hinzu
 * (linkingFromEntityId = null), "Umhängen" ersetzt genau die Zuordnung zu linkingFromEntityId.
 */
trait LinksDiariesToNodes
{
    public ?int $linkingDiaryId = null;
    public ?int $linkingFromEntityId = null;
    /** Zeile (Knoten-ID, 0 = Unverknüpft-Liste), an der der Picker geöffnet wurde. */
    public ?int $linkingAnchor = null;
    public bool $linkInherit = false;
    public string $linkSearch = '';

    /** @param int|null $fromEntityId null = zusätzlichen Knoten hinzufügen, sonst von diesem Knoten umhängen */
    public function startLinking(int $diaryId, ?int $fromEntityId = null, ?int $anchor = null): void
    {
        Gate::authorize('update', Diary::findOrFail($diaryId));
        $this->linkingDiaryId = $diaryId;
        $this->linkingFromEntityId = $fromEntityId;
        $this->linkingAnchor = $anchor ?? $fromEntityId ?? 0;
        $this->linkInherit = false;
        $this->linkSearch = '';
    }

    public function cancelLinking(): void
    {
        $this->linkingDiaryId = null;
        $this->linkingFromEntityId = null;
        $this->linkingAnchor = null;
        $this->linkInherit = false;
        $this->linkSearch = '';
    }

    public function linkTo(int $entityId): void
    {
        $diary = Diary::findOrFail((int) $this->linkingDiaryId);
        Gate::authorize('update', $diary);
        $this->authorizeNode($entityId);

        $service = app(DiaryService::class);
        if ($this->linkingFromEntityId) {
            $this->authorizeNode($this->linkingFromEntityId);
            $service->relink($diary, $this->linkingFromEntityId, $entityId);
        } else {
            $service->link($diary, $entityId, $this->linkInherit);
        }

        $this->afterLinkChange();
    }

    /** Zuordnung zu einem Knoten lösen. */
    public function unlinkNode(int $diaryId, int $entityId): void
    {
        $diary = Diary::findOrFail($diaryId);
        Gate::authorize('update', $diary);
        $this->authorizeNode($entityId);

        app(DiaryService::class)->unlink($diary, $entityId);

        $this->afterLinkChange();
    }

    /** Vererbung nach unten für die Zuordnung Diary <-> Knoten umschalten. */
    public function toggleInherit(int $diaryId, int $entityId): void
    {
        $diary = Diary::findOrFail($diaryId);
        Gate::authorize('update', $diary);
        $this->authorizeNode($entityId);

        $service = app(DiaryService::class);
        $service->setInherit($diary, $entityId, ! ($service->nodeAssignments($diary)[$entityId] ?? false));

        $this->afterLinkChange();
    }

    protected function authorizeNode(int $entityId): void
    {
        if (! in_array($entityId, (new DimensionLinkAuthorizer())->writableEntityIds(auth()->user()), true)) {
            abort(403, 'Kein Schreibrecht auf diesen Knoten.');
        }
    }

    protected function afterLinkChange(): void
    {
        $this->cancelLinking();
        $this->dispatch('updateSidebar');
    }

    /**
     * Kandidaten für den Knoten-Picker: Entities, auf die der User schreiben darf, optional gefiltert.
     * Knoten, an denen das Diary bereits hängt, werden ausgelassen.
     *
     * @return array<int, array{id:int,name:string,type:?string}>
     */
    protected function linkCandidates(): array
    {
        $user = auth()->user();
        if (! $user || ! $this->linkingDiaryId) {
            return [];
        }

        $writable = (new DimensionLinkAuthorizer())->writableEntityIds($user);
        if ($writable === []) {
            return [];
        }

        $diary = Diary::find($this->linkingDiaryId);
        $taken = $diary ? array_keys(app(DiaryService::class)->nodeAssignments($diary)) : [];
        $search = trim($this->linkSearch);

        return OrganizationEntity::query()
            ->with('type:id,name')
            ->whereIn('id', array_values(array_diff($writable, $taken)))
            ->where('is_active', true)
            ->when($search !== '', fn ($q) => $q->where(fn ($s) => $s->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%")))
            ->orderBy('name')
            ->limit($search !== '' ? 20 : 10)
            ->get(['id', 'name', 'entity_type_id'])
            ->map(fn ($e) => ['id' => (int) $e->id, 'name' => $e->name, 'type' => $e->type?->name])
            ->all();
    }
}
