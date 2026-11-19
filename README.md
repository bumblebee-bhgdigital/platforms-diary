# platforms-diary

Plattform-Modul: Diary (persönliches Tagebuch, an Knoten hängbar).
Gerüst aus `martin3r-me/platform-module-template`. Namespace `Platform\Diary`, Paket `martin3r/platform-diary`. Doku zum Gerüst: `LLM_GUIDE.md`, `DESIGN.md`.

## Diary (Basis, #1036–#1038)

Grundmodell: `Diary` (Morph-Alias `diary`), Service `DiaryService`, Policy `DiaryPolicy`,
MCP-Tools `diaries.GET/POST/PUT/DELETE`. Die Einträge selbst sind nicht Teil der Basis (Platzhalter auf der Detailseite) und folgen in eigenen Boards.

### Unterschiede zum Planner-Projekt

| Thema | Planner-Projekt | Diary |
|-------|-----------------|-------|
| Knoten-Zuordnung | ein Knoten | mehrere Knoten gleichzeitig |
| Vererbung nach unten | – | pro Zuordnung wählbar (`diary_nodes.inherit_down`; die Dimension Bridge kennt keine Vererbungs-Option je Link) |
| Umhängen | ersetzt den Knoten | ersetzt genau die Zuordnung `from_entity_id` -> `entity_id`, übrige Knoten bleiben |
| Status | Projekt-Lifecycle | `draft` / `active` / `archived`, keine Einzel-Aktiv-Regel pro Knoten |
| Verantwortlicher Agent | – | `responsible_agent_user_id` (User vom Typ `ai_user`) |
| Event | Sidebar-Event | `DiaryLinkChanged` (linked/unlinked/relinked/inherit_changed) + UI-Event `updateSidebar` |

Direkt vs. vererbt: `DiaryService::diariesForNode()` bzw. `diaries.GET` mit `entity_id` liefert je Diary `source=direct|inherited`.
