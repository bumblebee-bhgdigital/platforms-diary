<?php

namespace Platform\Diary\Organization;

use Illuminate\Database\Eloquent\Builder;
use Platform\Organization\Contracts\EntityLinkProvider;
use Platform\Diary\Enums\DiaryStatus;
use Platform\Diary\Models\Diary;

/**
 * Macht das Diary im Organisations-Modul an Knoten (Entities) verknüpfbar —
 * DimensionLink/Bridge analog PlannerEntityLinkProvider (Alias 'project' -> hier 'diary').
 *
 * Registrierung: DiaryServiceProvider::boot() (EntityLinkRegistry::register).
 * Damit ist der Typ für organization.entity.link.POST (object_type "diary"), entity.detail/summary
 * und die Terminal-App "Verknüpfungen" adressierbar. Anders als beim Planner-Projekt darf ein Diary an
 * mehreren Knoten hängen.
 *
 * Bewusst NICHT implementiert (Messlogik folgt in eigenen Boards): HasMetricDefinitions,
 * HasPersonMetrics, HasContextParent.
 */
class DiaryEntityLinkProvider implements EntityLinkProvider
{
    public function morphAliases(): array
    {
        return [Diary::MORPH_ALIAS];
    }

    public function linkTypeConfig(): array
    {
        return [
            Diary::MORPH_ALIAS => [
                'label' => 'Diaries',
                'singular' => 'Diary',
                'icon' => 'book-open',
                'route' => 'diary.diaries.show',
            ],
        ];
    }

    public function applyEagerLoading(Builder $query, string $morphAlias, string $fqcn): void
    {
        if ($morphAlias === Diary::MORPH_ALIAS) {
            $query->with(['owner:id,name', 'responsibleAgent:id,name']);
        }
    }

    public function extractMetadata(string $morphAlias, mixed $model): array
    {
        if ($morphAlias !== Diary::MORPH_ALIAS) {
            return [];
        }

        return [
            'status' => $model->status?->label(),
            'is_active' => $model->status === DiaryStatus::ACTIVE,
            'responsible' => $model->owner?->name,
            'agent' => $model->responsibleAgent?->name,
        ];
    }

    public function metadataDisplayRules(): array
    {
        return [
            Diary::MORPH_ALIAS => [
                ['field' => 'status', 'format' => 'badge'],
                ['field' => 'responsible', 'format' => 'text'],
            ],
        ];
    }

    /** Keine Zeiterfassung am Diary. */
    public function timeTrackableCascades(): array
    {
        return [];
    }

    /** Noch keine Einträge (folgen in eigenen Boards). */
    public function metrics(string $morphAlias, array $linksByEntity): array
    {
        return [];
    }

    /** Noch keine Kind-Objekte für den Activity-Feed. */
    public function activityChildren(string $morphAlias, array $linkableIds): array
    {
        return [];
    }
}
