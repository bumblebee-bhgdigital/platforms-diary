<?php

/**
 * Diary Service Provider
 * 
 * Dieser Service Provider ist das Herzstück jedes Platform-Moduls.
 * 
 * WICHTIG FÜR LLMs:
 * - Dieser Service Provider folgt dem exakten Muster von HCM und Planner
 * - Alle wichtigen Schritte sind kommentiert
 * - Config wird in register() geladen (Laravel Best Practice)
 * - Modul-Registrierung erfolgt in boot()
 * 
 * ANPASSUNGEN FÜR NEUES MODUL:
 * 1. Ersetze "Diary" durch deinen Modul-Namen (PascalCase)
 * 2. Ersetze "diary" durch deinen Modul-Namen (kebab-case)
 * 3. Passe Namespaces an
 * 4. Füge Commands/Tools hinzu falls nötig
 * 
 * @see Platform\Core\PlatformCore für Modul-Registrierung
 * @see Platform\Core\Routing\ModuleRouter für Route-Registrierung
 */

namespace Platform\Diary;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Platform\Core\PlatformCore;
use Platform\Core\Routing\ModuleRouter;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class DiaryServiceProvider extends ServiceProvider
{
    /**
     * Register Services
     * 
     * Wird VOR boot() aufgerufen.
     * Hier sollten nur leichte Registrierungen erfolgen.
     * 
     * LARAVEL BEST PRACTICE:
     * - Config sollte hier geladen werden (mergeConfigFrom)
     * - Commands können hier registriert werden
     */
    public function register(): void
    {
        /**
         * Config laden
         * 
         * mergeConfigFrom lädt die Config aus dem Package-Verzeichnis
         * und merged sie mit der Config aus config/ (falls vorhanden).
         * 
         * WICHTIG: Muss in register() sein, nicht in boot()!
         */
        $this->mergeConfigFrom(__DIR__.'/../config/diary.php', 'diary');
        
        /**
         * Commands registrieren (optional)
         * 
         * Falls dein Modul Artisan Commands hat:
         * 
         * if ($this->app->runningInConsole()) {
         *     $this->commands([
         *         \Platform\Diary\Console\Commands\YourCommand::class,
         *     ]);
         * }
         */
    }

    /**
     * Boot Services
     * 
     * Wird NACH register() aufgerufen.
     * Hier erfolgt die eigentliche Modul-Registrierung.
     * 
     * REIHENFOLGE IST WICHTIG:
     * 1. Config prüfen (bereits in register() geladen)
     * 2. Modul bei PlatformCore registrieren
     * 3. Routes laden (nur wenn Modul registriert)
     * 4. Migrationen, Views, Livewire registrieren
     */
    public function boot(): void
    {
        /**
         * SCHRITT 1: Modul-Registrierung prüfen
         * 
         * Prüft ob:
         * - Config vorhanden ist
         * - modules-Tabelle existiert (für Datenbank-Registrierung)
         * 
         * Nur wenn beide Bedingungen erfüllt, wird das Modul registriert.
         */
        if (
            config()->has('diary.routing') &&
            config()->has('diary.navigation') &&
            PlatformCore::modulesTableExists()
        ) {
            /**
             * Modul bei PlatformCore registrieren
             * 
             * Dies registriert das Modul in:
             * - Der Modul-Registry (für Navigation, Sidebar)
             * - Der Datenbank (modules-Tabelle)
             * 
             * Die Config wird automatisch aus config/diary.php geladen.
             */
            PlatformCore::registerModule([
                'key'        => 'diary', // Eindeutiger Schlüssel
                'title'      => 'Diary', // Anzeige-Name
                'group'      => 'dev',
                'routing'    => config('diary.routing'),
                'guard'      => config('diary.guard'),
                'navigation' => config('diary.navigation'),
                'sidebar'    => config('diary.sidebar'),
            ]);
        }

        /**
         * SCHRITT 2: Routes laden
         * 
         * Routes werden nur geladen, wenn das Modul erfolgreich registriert wurde.
         * 
         * ModuleRouter::group() erstellt automatisch:
         * - Route-Prefix (aus Config)
         * - Middleware (web, auth, etc.)
         * - Domain-Handling (für Subdomain-Modus)
         */
        if (PlatformCore::getModule('diary')) {
            /**
             * Web-Routes (authentifiziert)
             * 
             * Standard: requireAuth = true
             * Für öffentliche Routes: requireAuth = false
             */
            ModuleRouter::group('diary', function () {
                $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
            });
            
            /**
             * API-Routes (optional)
             * 
             * Falls dein Modul API-Endpoints hat:
             * 
             * ModuleRouter::apiGroup('diary', function () {
             *     $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
             * });
             */
        }

        /**
         * SCHRITT 3: Migrationen laden
         * 
         * Lädt alle Migrationen aus database/migrations/
         * Wird automatisch bei `php artisan migrate` ausgeführt.
         */
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        /**
         * SCHRITT 4: Config veröffentlichen
         * 
         * Ermöglicht es, die Config in config/diary.php zu überschreiben.
         * 
         * Publizieren mit:
         * php artisan vendor:publish --tag=config --provider="Platform\Diary\DiaryServiceProvider"
         * 
         * WICHTIG: mergeConfigFrom funktioniert auch OHNE Publizierung!
         */
        $this->publishes([
            __DIR__.'/../config/diary.php' => config_path('diary.php'),
        ], 'config');

        /**
         * SCHRITT 5: Views laden
         * 
         * Registriert Views unter dem Namespace 'diary'
         * 
         * Verwendung in Views:
         * @return view('diary::livewire.dashboard')
         */
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'diary');
        
        /**
         * SCHRITT 6: Livewire Components registrieren
         * 
         * Registriert alle Livewire-Komponenten automatisch.
         * 
         * Pattern:
         * - Datei: src/Livewire/Dashboard.php
         * - Alias: diary.dashboard
         * 
         * Verwendung:
         * <livewire:diary.dashboard />
         */
        $this->registerLivewireComponents();

        /**
         * SCHRITT 7: Morph-Alias, Policy, Tools
         */
        $this->registerMorphMap();
        $this->registerPolicies();
        $this->registerEntityLinkProvider();
        $this->registerTools();
    }

    /**
     * Organization-Bridge: Diary als verknüpfbarer Objekttyp (EntityLinkProvider) + Link-Hook.
     */
    protected function registerEntityLinkProvider(): void
    {
        try {
            resolve(\Platform\Organization\Services\EntityLinkRegistry::class)
                ->register(new \Platform\Diary\Organization\DiaryEntityLinkProvider());
        } catch (\Throwable $e) {
            // Organization-Modul nicht geladen
        }

        // Verknüpfungen entstehen nicht nur über den DiaryService, sondern auch extern
        // (organization.entity.link.POST, Terminal-App "Verknüpfungen"). Der Hook hält die
        // Vererbungs-Einstellungen (diary_nodes) konsistent und dispatcht das Event.
        if (class_exists(\Platform\Organization\Models\OrganizationDimensionLink::class)) {
            \Platform\Organization\Models\OrganizationDimensionLink::created(function ($link) {
                $this->onDimensionLinkChanged($link, \Platform\Diary\Events\DiaryLinkChanged::LINKED);
            });
            \Platform\Organization\Models\OrganizationDimensionLink::deleted(function ($link) {
                $this->onDimensionLinkChanged($link, \Platform\Diary\Events\DiaryLinkChanged::UNLINKED);
            });
        }
    }

    protected function onDimensionLinkChanged($link, string $action): void
    {
        if ($link->linkable_type !== \Platform\Diary\Models\Diary::MORPH_ALIAS) {
            return;
        }

        try {
            $diary = \Platform\Diary\Models\Diary::withTrashed()->find($link->linkable_id);
            if (! $diary) {
                return;
            }
            \Platform\Organization\Services\EntityDimensionBridge::flush();
            $entityId = \Platform\Organization\Services\EntityDimensionBridge::entityId((int) $link->dimension_value_id);

            if ($entityId) {
                if ($action === \Platform\Diary\Events\DiaryLinkChanged::LINKED) {
                    \Platform\Diary\Models\DiaryNode::query()->firstOrCreate(
                        ['diary_id' => $diary->id, 'entity_id' => $entityId],
                        ['inherit_down' => false],
                    );
                } else {
                    \Platform\Diary\Models\DiaryNode::query()
                        ->where('diary_id', $diary->id)->where('entity_id', $entityId)->delete();
                }
            }

            \Platform\Diary\Events\DiaryLinkChanged::dispatch($diary, $action, $entityId ?: null);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Diary: DimensionLink-Hook fehlgeschlagen', ['error' => $e->getMessage()]);
        }
    }

    /** Morph-Alias `diary` (DimensionLinks, EntityLinkProvider, Authz). */
    protected function registerMorphMap(): void
    {
        Relation::morphMap([
            \Platform\Diary\Models\Diary::MORPH_ALIAS => \Platform\Diary\Models\Diary::class,
        ]);
    }

    /** Graph-Autorisierung wie PlannerProjectPolicy. */
    protected function registerPolicies(): void
    {
        Gate::policy(
            \Platform\Diary\Models\Diary::class,
            \Platform\Diary\Policies\DiaryPolicy::class
        );
    }

    /** MCP-Tools diary.diaries.GET/POST/PUT/DELETE in der Tool-Registry. */
    protected function registerTools(): void
    {
        try {
            $registry = resolve(\Platform\Core\Tools\ToolRegistry::class);
            $registry->register(new \Platform\Diary\Tools\ListDiariesTool());
            $registry->register(new \Platform\Diary\Tools\CreateDiaryTool());
            $registry->register(new \Platform\Diary\Tools\UpdateDiaryTool());
            $registry->register(new \Platform\Diary\Tools\DeleteDiaryTool());
        } catch (\Throwable $e) {
            // ToolRegistry nicht verfügbar
        }
    }

    /**
     * Registriert alle Livewire-Komponenten automatisch
     * 
     * Scant das src/Livewire/ Verzeichnis rekursiv und registriert
     * alle PHP-Dateien als Livewire-Komponenten.
     * 
     * NAMING CONVENTION:
     * - Datei: src/Livewire/Dashboard.php
     * - Namespace: Platform\Diary\Livewire\Dashboard
     * - Alias: diary.dashboard
     * 
     * - Datei: src/Livewire/Entity/Index.php
     * - Namespace: Platform\Diary\Livewire\Entity\Index
     * - Alias: diary.entity.index
     * 
     * @return void
     */
    protected function registerLivewireComponents(): void
    {
        $basePath = __DIR__ . '/Livewire';
        $baseNamespace = 'Platform\\Diary\\Livewire';
        $prefix = 'diary';

        // Prüfe ob Verzeichnis existiert
        if (!is_dir($basePath)) {
            return;
        }

        // Rekursiv alle PHP-Dateien durchsuchen
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($basePath)
        );

        foreach ($iterator as $file) {
            // Nur PHP-Dateien verarbeiten
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            // Relativen Pfad extrahieren
            $relativePath = str_replace($basePath . DIRECTORY_SEPARATOR, '', $file->getPathname());
            
            // Klassenpfad generieren (z.B. Entity\Index -> Entity\Index)
            $classPath = str_replace(['/', '.php'], ['\\', ''], $relativePath);
            $class = $baseNamespace . '\\' . $classPath;

            // Prüfe ob Klasse existiert
            if (!class_exists($class)) {
                continue;
            }

            // Alias generieren (z.B. Entity\Index -> entity.index)
            $aliasPath = str_replace(['\\', '/'], '.', Str::kebab(str_replace('.php', '', $relativePath)));
            $alias = $prefix . '.' . $aliasPath;

            // Livewire-Komponente registrieren
            Livewire::component($alias, $class);
        }
    }
}
