<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Zuordnungs-Einstellungen je Diary und Knoten.
     *
     * Die Verknüpfung selbst ist ein DimensionLink (Organization-Bridge); die Dimension Bridge kennt
     * aber keine Vererbungs-Option pro Link. Diese Tabelle hält sie modul-eigen: eine Zeile je
     * (Diary, Knoten) mit inherit_down = "Diary wirkt auch an allen Unterknoten".
     */
    public function up(): void
    {
        if (Schema::hasTable('diary_nodes')) {
            return;
        }

        Schema::create('diary_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('diary_id')->constrained('diaries')->cascadeOnDelete();
            // Organisations-Entity (bewusst ohne FK: das Organization-Modul besitzt die Tabelle)
            $table->unsignedBigInteger('entity_id');
            $table->boolean('inherit_down')->default(false);
            $table->timestamps();

            $table->unique(['diary_id', 'entity_id']);
            $table->index('entity_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diary_nodes');
    }
};
