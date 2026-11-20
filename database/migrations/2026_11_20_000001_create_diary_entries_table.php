<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('diary_entries')) {
            Schema::create('diary_entries', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
                $table->foreignId('diary_id')->constrained('diaries')->cascadeOnDelete();

                $table->dateTime('entry_at');
                $table->string('title')->nullable();
                $table->longText('content')->nullable();

                // note | decision | insight | reflection | idea (optional)
                $table->string('type', 20)->nullable();
                // private = nur Autor/Diary-Owner, node = alle mit Zugriff auf das Diary (Knoten)
                $table->string('visibility', 20)->default('private');

                // Autor: Mensch oder Agent (beides users)
                $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->json('tags')->nullable();
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

                $table->timestamps();
                $table->softDeletes();

                $table->index(['diary_id', 'entry_at']);
                $table->index(['team_id', 'visibility']);
                $table->index('type');
            });
        }

        if (! Schema::hasTable('diary_entry_links')) {
            Schema::create('diary_entry_links', function (Blueprint $table) {
                $table->id();
                $table->foreignId('entry_id')->constrained('diary_entries')->cascadeOnDelete();
                $table->string('linkable_type');
                $table->unsignedBigInteger('linkable_id');
                $table->timestamps();

                $table->unique(['entry_id', 'linkable_type', 'linkable_id'], 'diary_entry_links_unique');
                $table->index(['linkable_type', 'linkable_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('diary_entry_links');
        Schema::dropIfExists('diary_entries');
    }
};
