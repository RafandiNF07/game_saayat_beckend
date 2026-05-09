<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('mode');
            $table->json('scope')->nullable();
            $table->unsignedTinyInteger('question_count');
            $table->unsignedBigInteger('reciter_id')->default(7);
            $table->string('status')->default('in_progress');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedTinyInteger('correct_count')->default(0);
            $table->unsignedInteger('score')->default(0);
            $table->unsignedSmallInteger('max_streak')->default(0);
            $table->unsignedSmallInteger('max_combo')->default(0);
            $table->boolean('is_perfect')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('game_session_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_session_id')->constrained('game_sessions')->onDelete('cascade');
            $table->unsignedSmallInteger('question_order');
            $table->unsignedBigInteger('verse_id');
            $table->json('option_verse_ids');
            $table->unsignedBigInteger('selected_verse_id')->nullable();
            $table->boolean('is_correct')->nullable();
            $table->timestamps();

            $table->index(['game_session_id', 'question_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_session_questions');
        Schema::dropIfExists('game_sessions');
    }
};
