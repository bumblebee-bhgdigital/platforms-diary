<?php

namespace Platform\Diary\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Diary\Services\DiaryEntryService;
use Platform\Diary\Tools\Concerns\HandlesDiaries;
use Platform\Diary\Tools\Concerns\HandlesEntries;

class DeleteEntryTool implements ToolContract, ToolMetadataContract
{
    use HandlesDiaries, HandlesEntries;

    public function getName(): string
    {
        return 'diary.entries.DELETE';
    }

    public function getDescription(): string
    {
        return 'DELETE /diary/entries/{id} - Löscht einen Eintrag (Soft-Delete, wiederherstellbar; nur Autor oder Diary-Owner/-Schreibberechtigte). Pflicht: id.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'ID des Eintrags (ERFORDERLICH).'],
                'team_id' => ['type' => 'integer', 'description' => 'Optional: Team-ID (Default: aktuelles Team).'],
            ],
            'required' => ['id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            ['team' => $team, 'error' => $error] = $this->resolveTeam($arguments, $context);
            if ($error) {
                return $error;
            }
            $entry = $this->findVisibleEntry($context, $team->id, (int) ($arguments['id'] ?? 0));
            if ($entry instanceof ToolResult) {
                return $entry;
            }
            if (! $entry->isEditableBy($context->user)) {
                return ToolResult::error('ACCESS_DENIED', "Keine Berechtigung für Eintrag #{$entry->id}.");
            }

            $id = $entry->id;
            app(DiaryEntryService::class)->delete($entry);

            return ToolResult::success(['id' => $id, 'message' => "Eintrag #{$id} gelöscht (Soft-Delete)."]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Löschen: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['diary', 'entries', 'delete'],
            'read_only' => false,
            'requires_auth' => true,
            'requires_team' => false,
            'risk_level' => 'write',
            'idempotent' => true,
        ];
    }
}
