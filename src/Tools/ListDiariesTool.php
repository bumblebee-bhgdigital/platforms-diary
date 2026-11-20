<?php

namespace Platform\Diary\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Diary\Enums\DiaryStatus;
use Platform\Diary\Models\Diary;
use Platform\Diary\Services\DiaryService;
use Platform\Diary\Tools\Concerns\HandlesDiaries;

class ListDiariesTool implements ToolContract, ToolMetadataContract
{
    use HandlesDiaries;

    public function getName(): string
    {
        return 'diary.diaries.GET';
    }

    public function getDescription(): string
    {
        return 'GET /diaries - Listet Diaries des Teams (nur für den User sichtbare). Einzelnes Diary: id=<diary_id>. '
            . ''
            . '"Diaries eines Knotens": entity_id=<Entity-ID> liefert direkt verknüpfte Diaries (source=direct) und, mit include_inherited=true (Default), von Vorfahren nach unten vererbte (source=inherited, via_entity_id). '
            . 'Weitere Filter: status, unlinked=true (Diaries ohne Knoten), search, limit/offset.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Optional: einzelnes Diary anhand der ID.'],
                'team_id' => ['type' => 'integer', 'description' => 'Optional: Team-ID (Default: aktuelles Team).'],
                'entity_id' => ['type' => 'integer', 'description' => 'Optional: nur Diaries, die an diesem Knoten wirken (direkt und optional vererbt).'],
                'include_inherited' => ['type' => 'boolean', 'description' => 'Nur mit entity_id: auch vererbte Diaries einbeziehen (Default true).'],
                'unlinked' => ['type' => 'boolean', 'description' => 'Optional: nur Diaries ohne Knoten-Zuordnung.'],
                'status' => ['type' => 'string', 'enum' => DiaryStatus::values(), 'description' => 'Optional: Status-Filter.'],
                'search' => ['type' => 'string', 'description' => 'Optional: Suche in Titel/Beschreibung.'],
                'limit' => ['type' => 'integer', 'description' => 'Optional: max. Anzahl (Default 50, max 200).'],
                'offset' => ['type' => 'integer', 'description' => 'Optional: Offset.'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            ['team' => $team, 'error' => $error] = $this->resolveTeam($arguments, $context);
            if ($error) {
                return $error;
            }

            if (! empty($arguments['id'])) {
                $diary = Diary::query()->where('team_id', $team->id)->find((int) $arguments['id']);
                if (! $diary) {
                    return ToolResult::error('NOT_FOUND', 'Diary nicht gefunden.');
                }
                if ($denied = $this->authorizeDiary($context, 'view', $diary)) {
                    return $denied;
                }

                return ToolResult::success(['diary' => $this->diaryToArray($diary)]);
            }

            $query = Diary::query()->where('team_id', $team->id)->visibleTo($context->user);

            if (! empty($arguments['status'])) {
                $status = DiaryStatus::tryFrom((string) $arguments['status']);
                if (! $status) {
                    return ToolResult::error('VALIDATION_ERROR', 'status muss einer von ' . implode(', ', DiaryStatus::values()) . ' sein.');
                }
                $query->status($status);
            }

            if (! empty($arguments['search'])) {
                $term = '%' . $arguments['search'] . '%';
                $query->where(fn ($q) => $q->where('title', 'like', $term)->orWhere('description', 'like', $term));
            }

            $sourceByDiary = [];
            if (! empty($arguments['entity_id'])) {
                $entries = app(DiaryService::class)->diariesForNode((int) $arguments['entity_id'], $arguments['include_inherited'] ?? true);
                foreach ($entries as $entry) {
                    $sourceByDiary[$entry['diary']->id] = ['source' => $entry['source'], 'via_entity_id' => $entry['via_entity_id']];
                }
                $query->whereIn('id', array_keys($sourceByDiary) ?: [0]);
            }

            $diaries = $query->orderBy('title')->get();

            if (! empty($arguments['unlinked'])) {
                $linked = \Platform\Organization\Services\EntityDimensionBridge::linksForLinkables(
                    [Diary::MORPH_ALIAS], $diaries->pluck('id')->all(), false
                )->pluck('linkable_id')->map(fn ($id) => (int) $id)->all();
                $diaries = $diaries->reject(fn ($s) => in_array($s->id, $linked, true))->values();
            }

            $limit = min(max((int) ($arguments['limit'] ?? 50), 1), 200);
            $offset = max((int) ($arguments['offset'] ?? 0), 0);

            return ToolResult::success([
                'diaries' => $diaries->slice($offset, $limit)->values()->map(fn ($s) => $this->diaryToArray($s) + ($sourceByDiary[$s->id] ?? []))->all(),
                'total' => $diaries->count(),
                'team_id' => $team->id,
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Laden der Diaries: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['diary', 'diaries', 'list'],
            'read_only' => true,
            'requires_auth' => true,
            'requires_team' => false,
            'risk_level' => 'safe',
            'idempotent' => true,
        ];
    }
}
