<?php

namespace Platform\Diary\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Diary\Exceptions\DiaryException;
use Platform\Diary\Services\DiaryEntryService;
use Platform\Diary\Tools\Concerns\HandlesDiaries;
use Platform\Diary\Tools\Concerns\HandlesEntries;

class UpdateEntryTool implements ToolContract, ToolMetadataContract
{
    use HandlesDiaries, HandlesEntries;

    public function getName(): string
    {
        return 'diary.entries.PUT';
    }

    public function getDescription(): string
    {
        return 'PUT /diary/entries/{id} - Ändert einen Eintrag (nur Autor oder Diary-Owner/-Schreibberechtigte). Pflicht: id. '
            . 'Gesendete Felder werden gesetzt (type/title/content mit leerem Wert leeren sie, links ersetzt alle Verweise). '
            . 'Sichtbarkeit ändern über visibility (private|node).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => array_merge(['team_id' => ['type' => 'integer', 'description' => 'Optional: Team-ID (Default: aktuelles Team).'], 'id' => ['type' => 'integer', 'description' => 'ID des Eintrags (ERFORDERLICH).']], $this->entryFieldSchema()),
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

            $entry = app(DiaryEntryService::class)->update($entry, $arguments);

            return ToolResult::success([
                'entry' => $this->entryToArray($entry->load('links')),
                'message' => "Eintrag #{$entry->id} aktualisiert.",
            ]);
        } catch (DiaryException $e) {
            return ToolResult::error($e->errorCode, $e->getMessage());
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Aktualisieren: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['diary', 'entries', 'update'],
            'read_only' => false,
            'requires_auth' => true,
            'requires_team' => false,
            'risk_level' => 'write',
            'idempotent' => true,
        ];
    }
}
