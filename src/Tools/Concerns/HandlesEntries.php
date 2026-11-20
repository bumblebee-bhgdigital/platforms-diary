<?php

namespace Platform\Diary\Tools\Concerns;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;
use Platform\Diary\Enums\DiaryEntryType;
use Platform\Diary\Enums\DiaryEntryVisibility;
use Platform\Diary\Models\DiaryEntry;

/** Gemeinsame Helfer der diary.entries.*-Tools (nutzt zusätzlich HandlesDiaries). */
trait HandlesEntries
{
    /** @return array<string,mixed> */
    protected function entryToArray(DiaryEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'uuid' => $entry->uuid,
            'team_id' => $entry->team_id,
            'diary_id' => $entry->diary_id,
            'entry_at' => $entry->entry_at?->toIso8601String(),
            'title' => $entry->title,
            'content' => $entry->content,
            'type' => $entry->type?->value,
            'type_label' => $entry->type?->label(),
            'visibility' => $entry->visibility?->value,
            'visibility_label' => $entry->visibility?->label(),
            'author_user_id' => $entry->author_user_id,
            'tags' => $entry->tags ?? [],
            'links' => $entry->links->map(fn ($l) => ['type' => $l->linkable_type, 'id' => (int) $l->linkable_id])->all(),
            'created_at' => $entry->created_at?->toIso8601String(),
            'updated_at' => $entry->updated_at?->toIso8601String(),
        ];
    }

    protected function entryFieldSchema(): array
    {
        return [
            'title' => ['type' => 'string', 'description' => 'Optional: Titel des Eintrags.'],
            'content' => ['type' => 'string', 'description' => 'Inhalt als Markdown.'],
            'entry_at' => ['type' => 'string', 'description' => 'Optional: Datum/Uhrzeit des Eintrags (ISO 8601, Default jetzt).'],
            'type' => ['type' => 'string', 'enum' => DiaryEntryType::values(), 'description' => 'Optional: note=Notiz, decision=Entscheidung, insight=Erkenntnis, reflection=Reflexion, idea=Idee.'],
            'visibility' => ['type' => 'string', 'enum' => DiaryEntryVisibility::values(), 'description' => 'Optional: private=privat (Default, nur Autor/Diary-Owner), node=Knoten (alle mit Zugriff auf das Diary).'],
            'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Optional: Tags.'],
            'links' => [
                'type' => 'array',
                'description' => 'Optional: Verweise auf andere Objekte (ersetzt alle bisherigen), z. B. Projekte, Kunden, Beschlüsse, Aufnahmen.',
                'items' => ['type' => 'object', 'properties' => ['type' => ['type' => 'string', 'description' => 'Morph-Alias, z. B. planner_project'], 'id' => ['type' => 'integer']], 'required' => ['type', 'id']],
            ],
        ];
    }

    /** Eintrag im Team laden und Sichtbarkeit prüfen; sonst NOT_FOUND (verrät private Einträge nicht). */
    protected function findVisibleEntry(ToolContext $context, int $teamId, int $id): DiaryEntry|ToolResult
    {
        $entry = DiaryEntry::query()->where('team_id', $teamId)->with(['links', 'diary'])->find($id);
        if (! $entry || ! $entry->isVisibleTo($context->user)) {
            return ToolResult::error('NOT_FOUND', 'Eintrag nicht gefunden.');
        }

        return $entry;
    }
}
