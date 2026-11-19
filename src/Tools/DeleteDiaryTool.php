<?php

namespace Platform\Diary\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Diary\Models\Diary;
use Platform\Diary\Services\DiaryService;
use Platform\Diary\Tools\Concerns\HandlesDiaries;

class DeleteDiaryTool implements ToolContract, ToolMetadataContract
{
    use HandlesDiaries;

    public function getName(): string
    {
        return 'diaries.DELETE';
    }

    public function getDescription(): string
    {
        return 'DELETE /diaries/{id} - Löscht ein Diary (Soft-Delete, wiederherstellbar). Pflicht: id. '
            . 'Zum Beenden eines Diary ohne Löschen stattdessen diaries.PUT mit status=archived nutzen.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'ID des Diary (ERFORDERLICH).'],
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
            $diary = Diary::query()->where('team_id', $team->id)->find((int) ($arguments['id'] ?? 0));
            if (! $diary) {
                return ToolResult::error('NOT_FOUND', 'Diary nicht gefunden.');
            }
            if ($denied = $this->authorizeDiary($context, 'delete', $diary)) {
                return $denied;
            }

            $title = $diary->title;
            $id = $diary->id;
            app(DiaryService::class)->delete($diary);

            return ToolResult::success([
                'id' => $id,
                'title' => $title,
                'message' => "Diary '{$title}' (#{$id}) gelöscht (Soft-Delete).",
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Löschen: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['diary', 'diaries', 'delete'],
            'read_only' => false,
            'requires_auth' => true,
            'requires_team' => false,
            'risk_level' => 'destructive',
            'idempotent' => false,
        ];
    }
}
