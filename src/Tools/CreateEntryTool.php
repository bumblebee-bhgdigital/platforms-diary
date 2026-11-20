<?php

namespace Platform\Diary\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Diary\Exceptions\DiaryException;
use Platform\Diary\Models\Diary;
use Platform\Diary\Services\DiaryEntryService;
use Platform\Diary\Tools\Concerns\HandlesDiaries;
use Platform\Diary\Tools\Concerns\HandlesEntries;

class CreateEntryTool implements ToolContract, ToolMetadataContract
{
    use HandlesDiaries, HandlesEntries;

    public function getName(): string
    {
        return 'diary.entries.POST';
    }

    public function getDescription(): string
    {
        return 'POST /diary/entries - Schreibt einen Eintrag in ein Diary (auch für Agenten). Pflicht: diary_id und title oder content. '
            . 'Autor ist der aufrufende User. visibility: private (Default, nur Autor/Diary-Owner) oder node (alle mit Zugriff auf das Diary). '
            . 'Optional type, tags, entry_at, links (Verweise auf andere Objekte).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => array_merge(['team_id' => ['type' => 'integer', 'description' => 'Optional: Team-ID (Default: aktuelles Team).'], 'diary_id' => ['type' => 'integer', 'description' => 'Diary (ERFORDERLICH).']], $this->entryFieldSchema()),
            'required' => ['diary_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            ['team' => $team, 'error' => $error] = $this->resolveTeam($arguments, $context);
            if ($error) {
                return $error;
            }
            $diary = Diary::query()->where('team_id', $team->id)->find((int) ($arguments['diary_id'] ?? 0));
            if (! $diary) {
                return ToolResult::error('NOT_FOUND', 'Diary nicht gefunden.');
            }
            if ($denied = $this->authorizeDiary($context, 'update', $diary)) {
                return $denied;
            }

            $entry = app(DiaryEntryService::class)->create($context->user, $diary, $arguments);

            return ToolResult::success([
                'entry' => $this->entryToArray($entry->load('links')),
                'message' => "Eintrag #{$entry->id} in Diary #{$diary->id} angelegt ({$entry->visibility->label()}).",
            ]);
        } catch (DiaryException $e) {
            return ToolResult::error($e->errorCode, $e->getMessage());
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Anlegen: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['diary', 'entries', 'create'],
            'read_only' => false,
            'requires_auth' => true,
            'requires_team' => false,
            'risk_level' => 'write',
            'idempotent' => false,
        ];
    }
}
