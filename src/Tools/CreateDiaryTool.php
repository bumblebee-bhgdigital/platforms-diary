<?php

namespace Platform\Diary\Tools;

use Illuminate\Support\Facades\Gate;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Diary\Exceptions\DiaryException;
use Platform\Diary\Models\Diary;
use Platform\Diary\Services\DiaryService;
use Platform\Diary\Tools\Concerns\HandlesDiaries;

class CreateDiaryTool implements ToolContract, ToolMetadataContract
{
    use HandlesDiaries;

    public function getName(): string
    {
        return 'diary.diaries.POST';
    }

    public function getDescription(): string
    {
        return 'POST /diaries - Legt ein Diary an (Status standardmäßig draft). Pflicht: title. '
            . 'Optional entity_id (+ inherit_down): hängt das Diary direkt an einen Knoten. status=active aktiviert es sofort. Ein Diary kann an mehrere Knoten gehängt werden (weitere über diary.diaries.PUT).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => array_merge($this->setFieldSchema(), [
                'team_id' => ['type' => 'integer', 'description' => 'Optional: Team-ID (Default: aktuelles Team).'],
                'status' => ['type' => 'string', 'enum' => ['draft', 'active'], 'description' => 'Optional: draft (Default) oder active.'],
                'entity_id' => ['type' => 'integer', 'description' => 'Optional: Knoten (Organisations-Entity), an den das Diary gleich gehängt wird. Nutze "organization.entities.GET". Weitere Knoten über diary.diaries.PUT.'],
                'inherit_down' => ['type' => 'boolean', 'description' => 'Optional (mit entity_id): Diary wirkt auch an allen Unterknoten (Default false).'],
            ]),
            'required' => ['title'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            ['team' => $team, 'error' => $error] = $this->resolveTeam($arguments, $context);
            if ($error) {
                return $error;
            }
            if (! Gate::forUser($context->user)->allows('create', Diary::class)) {
                return ToolResult::error('ACCESS_DENIED', 'Du darfst keine Diaries anlegen.');
            }

            $entityId = ! empty($arguments['entity_id']) ? (int) $arguments['entity_id'] : null;
            if ($entityId && ! $this->canWriteEntity($context, $entityId)) {
                return ToolResult::error('ACCESS_DENIED', "Kein Schreibrecht auf Knoten {$entityId} (oder Knoten existiert nicht).");
            }

            $service = app(DiaryService::class);
            $diary = $service->create($context->user, $team, $arguments);
            if ($entityId) {
                $service->link($diary, $entityId, ! empty($arguments['inherit_down']));
            }

            return ToolResult::success([
                'diary' => $this->diaryToArray($diary),
                'message' => "Diary '{$diary->title}' (#{$diary->id}) angelegt (Status {$diary->status->value}).",
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
            'tags' => ['diary', 'diaries', 'create'],
            'read_only' => false,
            'requires_auth' => true,
            'requires_team' => false,
            'risk_level' => 'write',
            'idempotent' => false,
        ];
    }
}
