<?php

namespace App\Services;

use App\Models\GameSession;
use App\Models\GameSessionQuestion;
use App\Models\Leaderboard;
use App\Models\UserProgress;
use App\Models\Verse;
use App\Models\Chapter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class GameService
{
    /**
     * Ambil ayat-ayat terurut sesuai scope
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
     * Cari ayat berikutnya dengan logika surah yang lebih robust
     */
    public function findNextVerseIdInScope(Verse $currentVerse, int $juzNumber = null, array $surahIds = []): ?int
    {
        // 1. Coba cari di surah yang sama
        $nextVerse = Verse::where('id_chapter', $currentVerse->id_chapter)
            ->where('number', $currentVerse->number + 1)
            ->first(['id']);

        if ($nextVerse) {
            return (int) $nextVerse->id;
        }

        // 2. Jika ayat terakhir surah, cari surah berikutnya (hanya jika mode bukan surah tunggal)
        $nextChapter = Chapter::where('id', '>', $currentVerse->id_chapter)
            ->orderBy('id')
            ->first(['id', 'juz_number']);

        if ($nextChapter) {
            // Jika mode Juz, pastikan surah berikutnya masih di Juz yang sama
            if ($juzNumber !== null && (int)$nextChapter->juz_number !== $juzNumber) {
                return null;
            }

            // Jika mode Surah spesifik, pastikan surah berikutnya ada di daftar surah yang dipilih
            if (!empty($surahIds) && !in_array($nextChapter->id, $surahIds)) {
                return null;
            }

            $firstVerse = Verse::where('id_chapter', $nextChapter->id)
                ->where('number', 1)
                ->first(['id']);

            return $firstVerse ? (int) $firstVerse->id : null;
        }

        return null;
    }

    public function findNextVerseIdInOrderedScope(Collection|array $orderedVerses, int $currentVerseId): ?int
    {
        $verseIds = $orderedVerses instanceof Collection ? $orderedVerses->pluck('id')->values()->all() : array_values($orderedVerses);
        $currentIndex = array_search($currentVerseId, $verseIds, true);

        if ($currentIndex === false || !isset($verseIds[$currentIndex + 1])) {
            return null;
        }

        return (int) $verseIds[$currentIndex + 1];
    }

    public function buildContinuationOptions(Collection $orderedVerses, int $promptVerseId, ?int $correctVerseId): array
    {
        $verseIds = $orderedVerses->pluck('id')->values()->all();
        
        $wrongIds = collect($verseIds)
            ->filter(fn ($verseId) => (int) $verseId !== $promptVerseId && (int) $verseId !== (int) ($correctVerseId ?? 0))
            ->shuffle()
            ->take(3)
            ->values();

        if ($wrongIds->count() < 3) {
            $needed = 3 - $wrongIds->count();
            $excludeIds = array_merge([$promptVerseId], $correctVerseId ? [$correctVerseId] : []);
            $additional = $this->getRandomVersesEfficiently($excludeIds, $needed);
            $wrongIds = $wrongIds->merge($additional)->unique()->values();
        }

        if ($correctVerseId !== null) {
            $options = $wrongIds->push($correctVerseId)->shuffle()->values()->all();
        } else {
            $options = $wrongIds->values()->all();
        }

        return $options;
    }

    private function getRandomVersesEfficiently(array $excludeIds, int $limit): Collection
    {
        $count = Verse::count();
        if ($count === 0) return collect();

        $randomIds = [];
        $maxId = Verse::max('id');
        
        // Strategi: Ambil sedikit lebih banyak ID random untuk antisipasi ID yang tidak ada
        $attempts = 0;
        while (count($randomIds) < $limit && $attempts < 50) {
            $randId = mt_rand(1, $maxId);
            if (!in_array($randId, $excludeIds) && !in_array($randId, $randomIds)) {
                // Verifikasi keberadaan ID
                if (Verse::where('id', $randId)->exists()) {
                    $randomIds[] = $randId;
                }
            }
            $attempts++;
        }

        return collect($randomIds);
    }

    /**
     * Hitung hasil dengan penalti untuk percobaan (attempts)
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

                // Scoring logic: base 100, dikurangi penalti attempt
                // attempt 1 = 100%, attempt 2 = 50%, attempt 3 = 25%, dst.
                $basePoints = 100;
                $penaltyMultiplier = 1.0;
                if ($question->attempts > 1) {
                    $penaltyMultiplier = pow(0.5, $question->attempts - 1);
                }
                
                $earned = ($basePoints * $penaltyMultiplier) + (($currentCombo - 1) * 20);
                $score += (int) $earned;
                
                $maxCombo = max($maxCombo, $currentCombo);
                $maxStreak = max($maxStreak, $currentStreak);
            } else {
                $currentCombo = 0;
                $currentStreak = 0;
            }
        }

        return [$score, $maxStreak, $maxCombo, $correctCount];
    }

    public function markProgressPassed(GameSession $session): void
    {
        if ($session->mode !== 'surah') return;

        $surahIds = collect($session->scope['surah_ids'] ?? [])->map(fn ($id) => (int) $id)->all();

        foreach ($surahIds as $surahId) {
            UserProgress::updateOrCreate(
                ['user_id' => $session->user_id, 'chapter_id' => $surahId],
                ['is_passed' => true, 'last_played_at' => now()]
            );
        }
    }

    public function processSubmitScore(array $answers, GameSession $session, Collection $questionMap): void
    {
        DB::transaction(function () use ($answers, $session, $questionMap) {
            foreach ($answers as $answer) {
                $question = $questionMap->get($answer['question_id']);
                if (!$question) continue;

                $currentVerse = Verse::find($question->verse_id);
                $correctVerseId = $this->findNextVerseIdInScope(
                    $currentVerse,
                    $session->scope['juz'] ?? null,
                    $session->scope['surah_ids'] ?? []
                );

                $isCorrect = $correctVerseId !== null && (int) $answer['selected_verse_id'] === (int) $correctVerseId;

                $question->update([
                    'selected_verse_id' => $answer['selected_verse_id'],
                    'attempts' => $answer['attempts'] ?? 1,
                    'is_correct' => $isCorrect,
                ]);
            }
        });
    }

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
