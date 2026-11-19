<?php

/**
 * Diary Web Routes
 * 
 * Diese Datei definiert alle Web-Routes für das Modul.
 * 
 * WICHTIG FÜR LLMs:
 * - Routes werden automatisch mit dem Modul-Prefix versehen (aus Config)
 * - Middleware wird automatisch hinzugefügt (web, auth, etc.)
 * - Route-Namen sollten mit dem Modul-Prefix beginnen
 * 
 * BEISPIEL:
 * Route::get('/', Dashboard::class)->name('diary.dashboard');
 * 
 * Wird zu: /diary/ (wenn prefix = 'diary')
 * 
 * @see Platform\Core\Routing\ModuleRouter für Details
 */

use Platform\Diary\Livewire\Dashboard;
use Platform\Diary\Livewire\Show;
use Platform\Diary\Livewire\Showcase;

/**
 * Dashboard Route
 *
 * Hauptübersicht des Moduls
 */
Route::get('/', Dashboard::class)->name('diary.dashboard');

// Übersicht aller Diaries (Alias auf dieselbe Komponente) und Detailseite.
// {diary} = Route-Model-Binding auf die ID (wie {plannerProject} im Planner).
Route::get('/diaries', Dashboard::class)->name('diary.diaries.index');
Route::get('/diaries/{diary}', Show::class)->name('diary.diaries.show');

/**
 * nx-Showcase Route
 *
 * Lebendes Verzeichnis aller x-nx-* Komponenten (Design-Referenz).
 */
Route::get('/showcase', Showcase::class)->name('diary.showcase');

/**
 * Weitere Routes hinzufügen:
 *
 * Route::get('/entities', Entity\Index::class)->name('diary.entities.index');
 * Route::get('/entities/{entity}', Entity\Show::class)->name('diary.entities.show');
 */
