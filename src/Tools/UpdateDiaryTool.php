<?php

namespace Platform\Diary\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Diary\Enums\DiaryStatus;
use Platform\Diary\Exceptions\DiaryException;
use Platform\Diary\Models\Diary;
use Platform\Diary\Services\DiaryService;
use Platform\Diary\Tools\Concerns\HandlesDiaries;

class UpdateDiaryTool implements ToolContract, ToolMetadataContract
{
    use HandlesDiaries;

    public function getName(): string
    {
        return 'diary.diaries.PUT';
    }

    public function getDescription(): string
    {
        return 'PUT /diaries/{id} - Ändert ein Diary. Pflicht: id. '
            . 'Felder ändern (title, description, owner_user_id, responsible_agent_user_id). '
            . 'status=active aktiviert das Diary, status=archived archiviert es. '
            . 'Knoten (ein Diary kann an mehrere Knoten hängen): entity_id hängt zusätzlich an diesen Knoten (inherit_down = Vererbung nach unten; ist das Diary dort schon verknüpft und inherit_down gesetzt, wird nur die Vererbung geändert). '
            . 'from_entity_id + entity_id hängt von einem Knoten auf einen anderen um (Vererbungs-Option wandert mit). '
            . 'unlink_entity_id löst die Zuordnung zu einem Knoten, unlink=true löst alle Knoten.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => array_merge($this->setFieldSchema(), [
                'id' => ['type' => 'integer', 'description' => 'ID des Diary (ERFORDERLICH).'],
                'team_id' => ['type' => 'integer', 'description' => 'Optional: Team-ID (Default: aktuelles Team).'],
                'status' => ['type' => 'string', 'enum' => ['active', 'archived'], 'description' => 'Optional: active = aktivieren, archived = archivieren.'],
                'entity_id' => ['type' => 'integer', 'description' => 'Optional: Knoten, an den das Diary zusätzlich gehängt wird (bzw. Ziel beim Umhängen mit from_entity_id).'],
                'inherit_down' => ['type' => 'boolean', 'description' => 'Optional (mit entity_id): Diary wirkt auch an allen Unterknoten.'],
                'from_entity_id' => ['type' => 'integer', 'description' => 'Optional: Umhängen von diesem Knoten auf entity_id.'],
                'unlink_entity_id' => ['type' => 'integer', 'description' => 'Optional: Zuordnung zu diesem Knoten lösen.'],
                'unlink' => ['type' => 'boolean', 'description' => 'Optional: alle Knoten-Zuordnungen lösen (Diary wird unverknüpft).'],
            ]),
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
            if (empty($arguments['id'])) {
                return ToolResult::error('VALIDATION_ERROR', 'id ist erforderlich.');
            }

            $diary = Diary::query()->where('team_id', $team->id)->find((int) $arguments['id']);
            if (! $diary) {
                return ToolResult::error('NOT_FOUND', 'Diary nicht gefunden.');
            }
            if ($denied = $this->authorizeDiary($context, 'update', $diary)) {
                return $denied;
            }

            $status = null;
            if (! empty($arguments['status'])) {
                $status = DiaryStatus::tryFrom((string) $arguments['status']);
                if (! in_array($status, [DiaryStatus::ACTIVE, DiaryStatus::ARCHIVED], true)) {
                    return ToolResult::error('VALIDATION_ERROR', "status darf nur 'active' oder 'archived' sein.");
                }
            }

            $entityId = ! empty($arguments['entity_id']) ? (int) $arguments['entity_id'] : null;
            $fromEntityId = ! empty($arguments['from_entity_id']) ? (int) $arguments['from_entity_id'] : null;
            $unlinkEntityId = ! empty($arguments['unlink_entity_id']) ? (int) $arguments['unlink_entity_id'] : null;
            if ($fromEntityId && ! $entityId) {
                return ToolResult::error('VALIDATION_ERROR', 'from_entity_id erfordert entity_id (Ziel-Knoten).');
            }
            foreach (array_filter([$entityId, $fromEntityId, $unlinkEntityId]) as $checkId) {
                if (! $this->canWriteEntity($context, $checkId)) {
                    return ToolResult::error('ACCESS_DENIED', "Kein Schreibrecht auf Knoten {$checkId} (oder Knoten existiert nicht).");
                }
            }

            $service = app(DiaryService::class);

            $fields = array_intersect_key($arguments, array_flip(['title', 'description', 'owner_user_id', 'responsible_agent_user_id']));
            if ($fields !== []) {
                $diary = $service->update($diary, $fields);
            }
            if (! empty($arguments['unlink'])) {
                $service->unlink($diary);
            }
            if ($unlinkEntityId) {
                $service->unlink($diary, $unlinkEntityId);
            }
            if ($entityId && $fromEntityId) {
                $service->relink($diary, $fromEntityId, $entityId);
            } elseif ($entityId) {
                if (in_array($entityId, $service->nodeIds($diary), true)) {
                    if (array_key_exists('inherit_down', $arguments)) {
                        $service->setInherit($diary, $entityId, (bool) $arguments['inherit_down']);
                    }
                } else {
                    $service->link($diary, $entityId, ! empty($arguments['inherit_down']));
                }
            }
            if ($status === DiaryStatus::ACTIVE) {
                $service->activate($diary);
            } elseif ($status === DiaryStatus::ARCHIVED) {
                $service->archive($diary);
            }

            $diary->refresh();

            return ToolResult::success([
                'diary' => $this->diaryToArray($diary),
                'message' => "Diary #{$diary->id} aktualisiert (Status {$diary->status->value}).",
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
            'tags' => ['diary', 'diaries', 'update', 'activate', 'archive', 'link'],
            'read_only' => false,
            'requires_auth' => true,
            'requires_team' => false,
            'risk_level' => 'write',
            'idempotent' => true,
        ];
    }
}
