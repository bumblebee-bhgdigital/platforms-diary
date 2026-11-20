<?php

namespace Platform\Diary\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Diary\Enums\DiaryEntryType;
use Platform\Diary\Enums\DiaryEntryVisibility;
use Platform\Diary\Models\DiaryEntry;
use Platform\Diary\Tools\Concerns\HandlesDiaries;
use Platform\Diary\Tools\Concerns\HandlesEntries;

class ListEntriesTool implements ToolContract, ToolMetadataContract
{
    use HandlesDiaries, HandlesEntries;

    public function getName(): string
    {
        return 'diary.entries.GET';
    }

    public function getDescription(): string
    {
        return 'GET /diary/entries - Listet Diary-Einträge (neueste zuerst). Es werden nur Einträge geliefert, die der User sehen darf: '
            . 'eigene und die des eigenen Diaries (privat) sowie Einträge mit visibility=node aus Diaries mit Lesezugriff. '
            . 'Einzelner Eintrag: id. Filter: diary_id, from/to (Zeitraum), type, visibility, tag, author_user_id, search, limit/offset.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Optional: einzelner Eintrag.'],
                'team_id' => ['type' => 'integer', 'description' => 'Optional: Team-ID (Default: aktuelles Team).'],
                'diary_id' => ['type' => 'integer', 'description' => 'Optional: nur Einträge dieses Diaries.'],
                'from' => ['type' => 'string', 'description' => 'Optional: Einträge ab diesem Zeitpunkt (ISO 8601).'],
                'to' => ['type' => 'string', 'description' => 'Optional: Einträge bis zu diesem Zeitpunkt (ISO 8601).'],
                'type' => ['type' => 'string', 'enum' => DiaryEntryType::values(), 'description' => 'Optional: Art-Filter.'],
                'visibility' => ['type' => 'string', 'enum' => DiaryEntryVisibility::values(), 'description' => 'Optional: Sichtbarkeits-Filter.'],
                'tag' => ['type' => 'string', 'description' => 'Optional: Tag-Filter (exakt).'],
                'author_user_id' => ['type' => 'integer', 'description' => 'Optional: Autor-Filter.'],
                'search' => ['type' => 'string', 'description' => 'Optional: Suche in Titel/Inhalt.'],
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
                $entry = $this->findVisibleEntry($context, $team->id, (int) $arguments['id']);

                return $entry instanceof ToolResult ? $entry : ToolResult::success(['entry' => $this->entryToArray($entry)]);
            }

            $query = DiaryEntry::query()->where('team_id', $team->id)->visibleTo($context->user)->with('links');

            if (! empty($arguments['diary_id'])) {
                $query->where('diary_id', (int) $arguments['diary_id']);
            }
            if (! empty($arguments['from'])) {
                $query->where('entry_at', '>=', $arguments['from']);
            }
            if (! empty($arguments['to'])) {
                $query->where('entry_at', '<=', $arguments['to']);
            }
            if (! empty($arguments['type'])) {
                if (! DiaryEntryType::tryFrom((string) $arguments['type'])) {
                    return ToolResult::error('VALIDATION_ERROR', 'type muss einer von ' . implode(', ', DiaryEntryType::values()) . ' sein.');
                }
                $query->where('type', $arguments['type']);
            }
            if (! empty($arguments['visibility'])) {
                if (! DiaryEntryVisibility::tryFrom((string) $arguments['visibility'])) {
                    return ToolResult::error('VALIDATION_ERROR', 'visibility muss einer von ' . implode(', ', DiaryEntryVisibility::values()) . ' sein.');
                }
                $query->where('visibility', $arguments['visibility']);
            }
            if (! empty($arguments['tag'])) {
                $query->whereJsonContains('tags', (string) $arguments['tag']);
            }
            if (! empty($arguments['author_user_id'])) {
                $query->where('author_user_id', (int) $arguments['author_user_id']);
            }
            if (! empty($arguments['search'])) {
                $term = '%' . $arguments['search'] . '%';
                $query->where(fn ($q) => $q->where('title', 'like', $term)->orWhere('content', 'like', $term));
            }

            $total = (clone $query)->count();
            $limit = min(max((int) ($arguments['limit'] ?? 50), 1), 200);
            $offset = max((int) ($arguments['offset'] ?? 0), 0);
            $entries = $query->orderByDesc('entry_at')->orderByDesc('id')->skip($offset)->take($limit)->get();

            return ToolResult::success([
                'entries' => $entries->map(fn ($e) => $this->entryToArray($e))->all(),
                'total' => $total,
                'team_id' => $team->id,
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Laden der Einträge: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['diary', 'entries', 'list'],
            'read_only' => true,
            'requires_auth' => true,
            'requires_team' => false,
            'risk_level' => 'safe',
            'idempotent' => true,
        ];
    }
}
