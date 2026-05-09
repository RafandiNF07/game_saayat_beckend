<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_sessions', function (Blueprint $table) {
            if (!Schema::hasColumn('game_sessions', 'is_autoplay')) {
                $table->boolean('is_autoplay')->default(true)->after('reciter_id');
            }
        });

        Schema::table('game_session_questions', function (Blueprint $table) {
            if (!Schema::hasColumn('game_session_questions', 'attempts')) {
                $table->unsignedTinyInteger('attempts')->default(0)->after('selected_verse_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('game_sessions', function (Blueprint $table) {
            $table->dropColumn('is_autoplay');
        });

        Schema::table('game_session_questions', function (Blueprint $table) {
            $table->dropColumn('attempts');
        });
    }
};
