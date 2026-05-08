<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Modifikasi tabel chapters
        Schema::table('chapters', function (Blueprint $table) {
            // Menambahkan juz_number (opsional default 1 atau nullable)
            if (!Schema::hasColumn('chapters', 'juz_number')) {
                $table->integer('juz_number')->nullable()->after('id');
            }
        });

        // 2. Tabel User Progress (Progres Level Surah)
        Schema::create('user_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->integer('chapter_id');
            $table->foreign('chapter_id')->references('id')->on('chapters')->onDelete('cascade');
            $table->boolean('is_passed')->default(false);
            $table->timestamp('last_played_at')->nullable();
            $table->timestamps();
            
            // Mencegah duplikasi data user & chapter
            $table->unique(['user_id', 'chapter_id']);
        });

        // 3. Tabel Leaderboard
        Schema::create('leaderboards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->integer('total_points')->default(0);
            $table->integer('max_streak')->default(0);
            $table->integer('max_combo')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leaderboards');
        Schema::dropIfExists('user_progress');
        Schema::table('chapters', function (Blueprint $table) {
            $table->dropColumn('juz_number');
        });
    }
};
