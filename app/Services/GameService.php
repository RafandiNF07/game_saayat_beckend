<?php

namespace App\Services;

use App\Models\GameSession;
use App\Models\GameSessionQuestion;
use App\Models\Leaderboard;
use App\Models\UserProgress;
use App\Models\Verse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class GameService
{
    /**
     * Ambil ayat-ayat terurut sesuai scope (mode, juz, atau surah)
     */
    public function getOrderedScopeVerses(GameSession $session): Collection
    {
        $query = Verse::query();

        $this->applyScope(
            $query,
            $session->mode,
            isset($session->scope['juz']) ? (int) $session->scope['juz'] : null,
            collect($session->scope['surah_ids'] ?? [])->map(fn ($id) => (int) $id)->all()
        );

        return $query->orderBy('id_chapter')->orderBy('number')->get()->values();
    }

    /**
     * Terapkan filter scope berdasarkan mode
     */
    public function applyScope($query, string $mode, ?int $juzNumber, array $surahIds): void
    {
        if ($mode === 'juz') {
            $query->whereHas('chapter', function ($q) use ($juzNumber) {
                $q->where('juz_number', $juzNumber);
            });
        }

        if ($mode === 'surah') {
            $query->whereIn('id_chapter', $surahIds);
        }
    }

    /**
     * Cari ayat berikutnya menggunakan query langsung, bukan array_search
     * OPTIMASI: Hindari menarik seluruh koleksi ayat ke memory
     */
    public function findNextVerseIdInScope(Verse $currentVerse, int $juzNumber = null, array $surahIds = []): ?int
    {
        // Cari ayat di surah yang sama dengan nomor berikutnya
        $nextVerse = Verse::where('id_chapter', $currentVerse->id_chapter)
            ->where('number', $currentVerse->number + 1)
            ->first(['id']);

        if ($nextVerse) {
            return (int) $nextVerse->id;
        }

        // Jika ayat terakhir di surah, cari ayat pertama surah berikutnya
        $nextChapter = $currentVerse->chapter()
            ->first()
            ->where('juz_number', '>=', $currentVerse->chapter->juz_number ?? 0)
            ->orderBy('id')
            ->skip(1)
            ->first(['id']);

        if ($nextChapter) {
            $firstVerse = Verse::where('id_chapter', $nextChapter->id)
                ->where('number', 1)
                ->first(['id']);

            return $firstVerse ? (int) $firstVerse->id : null;
        }

        return null;
    }

    /**
     * Cari ayat berikutnya dari koleksi terurut (fallback untuk backward compatibility)
     */
    public function findNextVerseIdInOrderedScope(Collection|array $orderedVerses, int $currentVerseId): ?int
    {
        $verseIds = $orderedVerses instanceof Collection ? $orderedVerses->pluck('id')->values()->all() : array_values($orderedVerses);
        $currentIndex = array_search($currentVerseId, $verseIds, true);

        if ($currentIndex === false || !isset($verseIds[$currentIndex + 1])) {
            return null;
        }

        return (int) $verseIds[$currentIndex + 1];
    }

    /**
     * Bangun opsi pilihan ganda untuk pertanyaan lanjutan
     * OPTIMASI: Hindari ORDER BY RAND() pada tabel besar
     * Menggunakan LIMIT dengan offset acak atau ID range
     */
    public function buildContinuationOptions(Collection $orderedVerses, int $promptVerseId, ?int $correctVerseId): array
    {
        $verseIds = $orderedVerses->pluck('id')->values()->all();
        
        // Ambil 3 jawaban salah dari koleksi terurut yang sudah diambil
        $wrongIds = collect($verseIds)
            ->filter(fn ($verseId) => (int) $verseId !== $promptVerseId && (int) $verseId !== (int) ($correctVerseId ?? 0))
            ->shuffle()
            ->take(3)
            ->values();

        // Jika jawaban salah kurang dari 3, ambil dari database dengan strategi yang lebih efisien
        if ($wrongIds->count() < 3) {
            $needed = 3 - $wrongIds->count();
            $excludeIds = array_merge([$promptVerseId], $correctVerseId ? [$correctVerseId] : []);
            
            // Daripada ORDER BY RAND(), gunakan offset random pada ID
            $additional = $this->getRandomVersesEfficiently($excludeIds, $needed);
            $wrongIds = $wrongIds->merge($additional)->unique()->values();
        }

        // Susun 4 opsi dengan jawaban benar diacak di dalamnya
        if ($correctVerseId !== null) {
            $options = $wrongIds->push($correctVerseId)
                ->shuffle()
                ->values()
                ->all();
        } else {
            $options = $wrongIds->values()->all();
        }

        return $options;
    }

    /**
     * Ambil verse secara random tanpa menggunakan ORDER BY RAND()
     * Strategi: Ambil range ID verse, generate random ID dalam range, query dengan IN
     * OPTIMASI: Jauh lebih cepat daripada ORDER BY RAND()
     */
    private function getRandomVersesEfficiently(array $excludeIds, int $limit): Collection
    {
        $minId = Verse::query()->min('id') ?? 1;
        $maxId = Verse::query()->max('id') ?? 1;

        $randomIds = [];
        $attempts = 0;
        $maxAttempts = $limit * 5; // Hindari infinite loop

        while (count($randomIds) < $limit && $attempts < $maxAttempts) {
            $randomId = mt_rand($minId, $maxId);
            
            if (!in_array($randomId, $excludeIds) && !in_array($randomId, $randomIds)) {
                $randomIds[] = $randomId;
            }
            
            $attempts++;
        }

        // Query verse dengan ID random yang sudah dihasilkan
        return Verse::whereIn('id', $randomIds)
            ->pluck('id')
            ->values();
    }

    /**
     * Hitung hasil game (score, streak, combo)
     */
    public function calculateResult(Collection $questions): array
    {
        $score = 0;
        $correctCount = 0;
        $currentCombo = 0;
        $currentStreak = 0;
        $maxCombo = 0;
        $maxStreak = 0;

        foreach ($questions as $question) {
            if ($question->is_correct) {
                $correctCount++;
                $currentCombo++;
                $currentStreak++;

                $score += 100 + (($currentCombo - 1) * 20);
                $maxCombo = max($maxCombo, $currentCombo);
                $maxStreak = max($maxStreak, $currentStreak);
            } else {
                $currentCombo = 0;
                $currentStreak = 0;
            }
        }

        return [$score, $maxStreak, $maxCombo, $correctCount];
    }

    /**
     * Tandai progress sebagai passed untuk mode surah
     */
    public function markProgressPassed(GameSession $session): void
    {
        if ($session->mode !== 'surah') {
            return;
        }

        $surahIds = collect($session->scope['surah_ids'] ?? [])->map(fn ($id) => (int) $id)->all();

        foreach ($surahIds as $surahId) {
            UserProgress::updateOrCreate(
                ['user_id' => $session->user_id, 'chapter_id' => $surahId],
                [
                    'is_passed' => true,
                    'last_played_at' => now(),
                ]
            );
        }
    }

    /**
     * Proses submission jawaban dan hitung skor
     * OPTIMASI: Query ayat berikutnya langsung dari database, bukan dari array memory
     */
    public function processSubmitScore(array $answers, GameSession $session, GameSessionQuestion $questionMap): array
    {
        $results = [];

        DB::transaction(function () use ($answers, $session, $questionMap, &$results) {
            foreach ($answers as $answer) {
                $question = $questionMap[$answer['question_id']];
                $currentVerse = Verse::find($question->verse_id);

                // Query ayat berikutnya langsung dari database
                $correctVerseId = $this->findNextVerseIdInScope(
                    $currentVerse,
                    isset($session->scope['juz']) ? (int) $session->scope['juz'] : null,
                    collect($session->scope['surah_ids'] ?? [])->map(fn ($id) => (int) $id)->all()
                );

                $isCorrect = $correctVerseId !== null && (int) $answer['selected_verse_id'] === (int) $correctVerseId;

                $question->update([
                    'selected_verse_id' => $answer['selected_verse_id'],
                    'is_correct' => $isCorrect,
                ]);

                $results[] = ['question_id' => $question->id, 'is_correct' => $isCorrect];
            }
        });

        return $results;
    }

    /**
     * Update leaderboard setelah game selesai
     */
    public function updateLeaderboard(int $userId, int $score, int $maxStreak, int $maxCombo): Leaderboard
    {
        $leaderboard = Leaderboard::firstOrNew(['user_id' => $userId]);
        $leaderboard->total_points = (int) $leaderboard->total_points + $score;
        $leaderboard->max_streak = max((int) $leaderboard->max_streak, $maxStreak);
        $leaderboard->max_combo = max((int) $leaderboard->max_combo, $maxCombo);
        $leaderboard->save();

        return $leaderboard;
    }
}
