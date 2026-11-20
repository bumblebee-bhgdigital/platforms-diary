<?php

/**
 * Sidebar Livewire Component
 *
 * Diaries nach Knoten gruppiert (Entity-Typ -> Baum -> Diaries), nicht verknüpfte Diaries separat.
 * Struktur wie die Planner-Sidebar; ein Diary kann unter mehreren Knoten erscheinen.
 *
 * Sichtbarkeit: Diary::visibleTo (Core-Graph). Verknüpfen/Umhängen/Lösen/Vererbung direkt hier
 * (LinksDiariesToNodes); das UI-Event `updateSidebar` hält die Liste aktuell.
 */

namespace Platform\Diary\Livewire;

use Livewire\Attributes\On;
use Livewire\Component;
use Platform\Organization\Models\OrganizationEntity;
use Platform\Organization\Services\EntityAncestorService;
use Platform\Organization\Services\EntityDimensionBridge;
use Platform\Diary\Enums\DiaryStatus;
use Platform\Diary\Livewire\Concerns\LinksDiariesToNodes;
use Platform\Diary\Livewire\Concerns\ResolvesTeamFamily;
use Platform\Diary\Models\Diary;
use Platform\Diary\Models\DiaryNode;

class Sidebar extends Component
{
    use ResolvesTeamFamily, LinksDiariesToNodes;

    /** Neu rendern, wenn Diaries angelegt/verknüpft/gelöst werden (auch von anderen Komponenten). */
    #[On('updateSidebar')]
    public function updateSidebar(): void
    {
    }

    public function render()
    {
        $user = auth()->user();
        $teamId = $user?->currentTeam?->id;

        $empty = [
            'entityTypeGroups' => collect(),
            'unlinkedDiaries' => collect(),
            'linkCandidates' => [],
        ];

        if (! $user || ! $teamId) {
            return view('diary::livewire.sidebar', $empty);
        }

        // Archivierte Diaries bleiben als Historie über Übersicht/Detail erreichbar, nicht in der Navigation.
        $diaries = Diary::query()
            ->whereIn('team_id', $this->relevantTeamIds($user))
            ->visibleTo($user)
            ->where('status', '!=', DiaryStatus::ARCHIVED->value)
            ->orderBy('title')
            ->get();

        // Knoten-Verknüpfungen (DimensionLink, Alias 'diary'); ein Diary kann an mehreren Knoten hängen
        $entityDiaryMap = []; // entity_id => [diary_id]
        $linkedDiaryIds = [];
        $links = EntityDimensionBridge::linksForLinkables([Diary::MORPH_ALIAS], $diaries->pluck('id')->all());
        foreach ($links as $link) {
            if (! $link->entity_id) {
                continue;
            }
            $entityDiaryMap[$link->entity_id][] = $link->linkable_id;
            $linkedDiaryIds[] = $link->linkable_id;
        }
        foreach ($entityDiaryMap as $entityId => $ids) {
            $entityDiaryMap[$entityId] = array_values(array_unique($ids));
        }
        $linkedDiaryIds = array_values(array_unique($linkedDiaryIds));

        // Vererbungs-Option je Zuordnung (diary_id:entity_id => bool)
        $inheritMap = [];
        if ($linkedDiaryIds !== []) {
            foreach (DiaryNode::query()->whereIn('diary_id', $linkedDiaryIds)->get(['diary_id', 'entity_id', 'inherit_down']) as $row) {
                $inheritMap[$row->diary_id . ':' . $row->entity_id] = (bool) $row->inherit_down;
            }
        }

        // Aufwärts-Traversierung: Tree-Parents (nur struktureller Baum, kein engagement_with-Channel)
        $ancestorService = new EntityAncestorService();
        $channels = [];
        foreach ($ancestorService->expandEntitiesWithAncestors(array_keys($entityDiaryMap), $channels) as $entityId) {
            $entityDiaryMap[$entityId] ??= [];
        }

        $entityTypeGroups = collect();
        if ($entityDiaryMap !== []) {
            $entities = OrganizationEntity::with('type')->whereIn('id', array_keys($entityDiaryMap))->get()->keyBy('id');
            $hierarchy = $ancestorService->buildParentChildrenMap($entities, $channels);
            $childrenMap = $hierarchy['parent_to_children'];

            $buildTree = function (int $entityId) use (&$buildTree, $entities, $childrenMap, $entityDiaryMap, $diaries, $inheritMap): ?array {
                $entity = $entities->get($entityId);
                if (! $entity) {
                    return null;
                }

                $childNodes = collect($childrenMap[$entityId] ?? [])->map(fn ($id) => $buildTree($id))->filter();
                $childrenByType = $childNodes
                    ->groupBy(fn ($c) => $c['type_id'])
                    ->map(function ($group) use ($entities) {
                        $first = $group->first();
                        $type = $entities->get($first['entity_id'])?->type;

                        return [
                            'type_id' => $first['type_id'],
                            'type_name' => $type?->name ?? 'Sonstige',
                            'type_icon' => $type?->icon ?? null,
                            'sort_order' => $type?->sort_order ?? 999,
                            'children' => $group->sortBy('entity_name')->values(),
                        ];
                    })
                    ->sortBy('sort_order')->values();

                // aktive Diaries zuerst, dann Entwürfe
                $nodeDiaries = collect($entityDiaryMap[$entityId] ?? [])
                    ->map(fn ($id) => $diaries->firstWhere('id', $id))->filter()
                    ->sortBy(fn ($s) => [$s->status === DiaryStatus::ACTIVE ? 0 : 1, $s->title])
                    ->values();

                $total = $nodeDiaries->count() + $childNodes->sum('total_diaries');
                if ($total === 0) {
                    return null;
                }

                return [
                    'entity_id' => $entityId,
                    'entity_name' => $entity->name,
                    'type_id' => $entity->type?->id,
                    'diaries' => $nodeDiaries,
                    'inherit' => $nodeDiaries->mapWithKeys(fn ($s) => [$s->id => $inheritMap[$s->id . ':' . $entityId] ?? false])->all(),
                    'children_by_type' => $childrenByType,
                    'total_diaries' => $total,
                ];
            };

            $grouped = [];
            foreach ($hierarchy['roots'] as $entityId) {
                $entity = $entities->get($entityId);
                if (! $entity || ! $entity->type || ! ($tree = $buildTree($entityId))) {
                    continue;
                }
                $typeId = $entity->type->id;
                $grouped[$typeId] ??= [
                    'type_id' => $typeId,
                    'type_name' => $entity->type->name,
                    'type_icon' => $entity->type->icon,
                    'sort_order' => $entity->type->sort_order ?? 999,
                    'entities' => [],
                ];
                $grouped[$typeId]['entities'][] = $tree;
            }

            $entityTypeGroups = collect($grouped)->sortBy('sort_order')
                ->map(function ($g) {
                    $g['entities'] = collect($g['entities'])->sortBy('entity_name')->values();

                    return $g;
                })->values();
        }

        return view('diary::livewire.sidebar', [
            'entityTypeGroups' => $entityTypeGroups,
            'unlinkedDiaries' => $diaries->reject(fn ($s) => in_array($s->id, $linkedDiaryIds, true))->values(),
            'linkCandidates' => $this->linkCandidates(),
        ]);
    }
}
