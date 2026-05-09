<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\LeaderboardResource;
use App\Models\GameSession;
use App\Models\GameSessionQuestion;
use App\Models\Leaderboard;
use App\Models\UserProgress;
use App\Models\Verse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class GameController extends Controller
{
    public function startSession(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'mode' => 'required|in:all_juz,juz,surah',
            'jumlah_soal' => 'required|integer|in:5,10,20',
            'juz' => 'required_if:mode,juz|integer|min:1|max:30',
            'surah_ids' => 'required_if:mode,surah|array|min:1',
            'surah_ids.*' => 'integer|distinct|exists:chapters,id',
            'reciter_id' => 'nullable|integer',
        ]);

        $mode = $validated['mode'];
        $jumlahSoal = (int) $validated['jumlah_soal'];
        $reciterId = (int) ($validated['reciter_id'] ?? 7);
        $juzNumber = isset($validated['juz']) ? (int) $validated['juz'] : null;
        $surahIds = collect($validated['surah_ids'] ?? [])->map(fn ($id) => (int) $id)->values()->all();

        if ($mode === 'surah' && empty($surahIds)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Mode surah membutuhkan minimal 1 surah pada surah_ids.',
            ], 422);
        }

        if ($mode === 'surah') {
            // FindBlockedChapter removed to allow free play
            $blockedChapter = null; 
            /*
            $blockedChapter = $this->findBlockedChapter($user->id, $surahIds);
            if ($blockedChapter !== null) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Anda harus menyelesaikan Surah sebelumnya untuk membuka level ini.',
                    'data' => [
                        'blocked_chapter_id' => $blockedChapter,
                    ],
                ], 403);
            }
            */
        }

        $baseQuery = Verse::query();
        $scope = [
            'mode' => $mode,
            'juz' => $juzNumber,
            'surah_ids' => $surahIds,
        ];

        $this->applyScope($baseQuery, $mode, $juzNumber, $surahIds);

        $orderedVerses = (clone $baseQuery)
            ->with(['chapter', 'audio' => function ($q) use ($reciterId) {
                $q->where('id_recitation', $reciterId);
            }])
            ->orderBy('id_chapter')
            ->orderBy('number')
            ->get()
            ->values();

        // Use slice to exclude last verse (which has no continuation)
        // This is more lenient and handles edge cases better
        $eligiblePrompts = $orderedVerses->slice(0, max(0, $orderedVerses->count() - 1))->values();
        
        $availableCount = $eligiblePrompts->count();

        if ($availableCount < $jumlahSoal) {
            return response()->json([
                'status' => 'error',
                'message' => 'Data ayat pada mode yang dipilih tidak cukup untuk jumlah soal ini.',
                'data' => [
                    'available' => $availableCount,
                    'requested' => $jumlahSoal,
                ],
            ], 422);
        }

        $selectedVerses = $eligiblePrompts->shuffle()->take($jumlahSoal)->values();

        $session = DB::transaction(function () use ($user, $mode, $scope, $jumlahSoal, $reciterId, $selectedVerses, $orderedVerses) {
            $session = GameSession::create([
                'user_id' => $user->id,
                'mode' => $mode,
                'scope' => $scope,
                'question_count' => $jumlahSoal,
                'reciter_id' => $reciterId,
                'status' => 'in_progress',
                'started_at' => now(),
            ]);

            foreach ($selectedVerses->values() as $index => $verse) {
                $nextVerseId = $this->findNextVerseIdInOrderedScope($orderedVerses, $verse->id);
                $optionVerseIds = $this->buildContinuationOptions($orderedVerses, $verse->id, $nextVerseId);

                GameSessionQuestion::create([
                    'game_session_id' => $session->id,
                    'question_order' => $index + 1,
                    'verse_id' => $verse->id,
                    'option_verse_ids' => $optionVerseIds,
                ]);
            }

            return $session;
        });

        $responseQuestions = GameSessionQuestion::query()
            ->where('game_session_id', $session->id)
            ->with(['verse.chapter'])
            ->get()
            ->map(function (GameSessionQuestion $question) use ($reciterId, $orderedVerses) {
                $verse = $question->verse;
                $nextVerseId = $this->findNextVerseIdInOrderedScope($orderedVerses, $verse->id);
                $promptTranslation = DB::table('verse_translations')->where('id_verse', $verse->id)->where('id_translation', 33)->value('text') ?? '';
                $audioUrl = optional($verse->audio()->where('id_recitation', $reciterId)->first())->url;

                $options = Verse::query()
                    ->whereIn('id', $question->option_verse_ids)
                    ->with('chapter')
                    ->get()
                    ->sortBy(function ($option) use ($question) {
                        return array_search($option->id, $question->option_verse_ids, true);
                    })
                    ->values()
                    ->map(function (Verse $option) {
                        return [
                            'verse_id' => $option->id,
                            'text' => $option->text_uthmani,
                            'surah_id' => $option->id_chapter,
                            'surah_name' => optional($option->chapter)->name,
                            'verse_number' => $option->number,
                        ];
                    })
                    ->values();

                return [
                    'question_id' => $question->id,
                    'order' => $question->question_order,
                    'verse_id' => $verse->id,
                    'text' => $verse->text_uthmani,
                    'surah_id' => $verse->id_chapter,
                    'surah_name' => optional($verse->chapter)->name,
                    'verse_number' => $verse->number,
                    'audio_url' => $audioUrl,
                    'translation_text' => $promptTranslation,
                    'next_verse_id' => $nextVerseId,
                    'options' => $options,
                ];
            });

        return response()->json([
            'status' => 'success',
            'data' => [
                'session_id' => $session->id,
                'mode' => $mode,
                'jumlah_soal' => $jumlahSoal,
                'questions' => $responseQuestions,
            ],
        ]);
    }

    public function submitScore(Request $request)
    {
        $validated = $request->validate([
            'session_id' => 'required|integer|exists:game_sessions,id',
            'answers' => 'required|array|min:1',
            'answers.*.question_id' => 'required|integer|exists:game_session_questions,id',
            'answers.*.selected_verse_id' => 'required|integer|exists:verses,id',
        ]);

        $user = $request->user();

        $session = GameSession::query()
            ->where('id', $validated['session_id'])
            ->where('user_id', $user->id)
            ->first();

        if (!$session) {
            return response()->json([
                'status' => 'error',
                'message' => 'Sesi game tidak ditemukan.',
            ], 404);
        }

        if ($session->status !== 'in_progress') {
            return response()->json([
                'status' => 'error',
                'message' => 'Sesi game sudah disubmit sebelumnya.',
            ], 409);
        }

        $questionMap = GameSessionQuestion::query()
            ->where('game_session_id', $session->id)
            ->get()
            ->keyBy('id');

        $submittedQuestionIds = collect($validated['answers'])->pluck('question_id')->unique();

        if ($submittedQuestionIds->count() !== $session->question_count) {
            return response()->json([
                'status' => 'error',
                'message' => 'Jumlah jawaban harus sesuai jumlah soal pada sesi.',
            ], 422);
        }

        $allBelongToSession = $submittedQuestionIds->every(function ($questionId) use ($questionMap) {
            return $questionMap->has($questionId);
        });

        if (!$allBelongToSession) {
            return response()->json([
                'status' => 'error',
                'message' => 'Terdapat jawaban untuk soal yang bukan milik sesi ini.',
            ], 422);
        }

        $orderedVerses = $this->getOrderedScopeVerses($session);
        $verseIdMap = $orderedVerses->pluck('id')->values()->all();

        DB::transaction(function () use ($validated, $questionMap, $verseIdMap) {
            foreach ($validated['answers'] as $answer) {
                $question = $questionMap[$answer['question_id']];
                $correctVerseId = $this->findNextVerseIdInOrderedScope($verseIdMap, (int) $question->verse_id);
                $isCorrect = (int) $answer['selected_verse_id'] === (int) $correctVerseId;

                $question->update([
                    'selected_verse_id' => $answer['selected_verse_id'],
                    'is_correct' => $isCorrect,
                ]);
            }
        });

        $resolvedQuestions = GameSessionQuestion::query()
            ->where('game_session_id', $session->id)
            ->orderBy('question_order')
            ->get();

        [$score, $maxStreak, $maxCombo, $correctCount] = $this->calculateResult($resolvedQuestions);
        $isPerfect = $correctCount === (int) $session->question_count;

        $session->update([
            'status' => 'submitted',
            'finished_at' => now(),
            'correct_count' => $correctCount,
            'score' => $score,
            'max_streak' => $maxStreak,
            'max_combo' => $maxCombo,
            'is_perfect' => $isPerfect,
        ]);

        if ($isPerfect) {
            $this->markProgressPassed($session);
        }

        $leaderboard = Leaderboard::firstOrNew(['user_id' => $user->id]);
        $leaderboard->total_points = (int) $leaderboard->total_points + $score;
        $leaderboard->max_streak = max((int) $leaderboard->max_streak, $maxStreak);
        $leaderboard->max_combo = max((int) $leaderboard->max_combo, $maxCombo);
        $leaderboard->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Skor berhasil disimpan.',
            'data' => [
                'session_id' => $session->id,
                'score' => $score,
                'correct_count' => $correctCount,
                'total_questions' => (int) $session->question_count,
                'max_streak' => $maxStreak,
                'max_combo' => $maxCombo,
                'is_perfect' => $isPerfect,
                'total_points' => (int) $leaderboard->total_points,
            ],
        ]);
    }

    public function getLeaderboard()
    {
        $leaderboards = Leaderboard::with('user')
            ->orderBy('total_points', 'desc')
            ->limit(50)
            ->get();

        return LeaderboardResource::collection($leaderboards)->additional([
            'status' => 'success',
        ]);
    }

    private function applyScope($query, string $mode, ?int $juzNumber, array $surahIds): void
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

    private function getOrderedScopeVerses(GameSession $session): Collection
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

    private function findNextVerseIdInOrderedScope(Collection|array $orderedVerses, int $currentVerseId): ?int
    {
        $verseIds = $orderedVerses instanceof Collection ? $orderedVerses->pluck('id')->values()->all() : array_values($orderedVerses);
        $currentIndex = array_search($currentVerseId, $verseIds, true);

        if ($currentIndex === false || !isset($verseIds[$currentIndex + 1])) {
            return null;
        }

        return (int) $verseIds[$currentIndex + 1];
    }

    private function buildContinuationOptions(Collection $orderedVerses, int $promptVerseId, ?int $correctVerseId): array
    {
        $verseIds = $orderedVerses->pluck('id')->values()->all();
        $wrongIds = collect($verseIds)
            ->filter(fn ($verseId) => (int) $verseId !== $promptVerseId && (int) $verseId !== (int) $correctVerseId)
            ->shuffle()
            ->take(3)
            ->values();

        if ($wrongIds->count() < 3) {
            $additional = Verse::query()
                ->whereNotIn('id', array_merge([$promptVerseId], $correctVerseId ? [$correctVerseId] : []))
                ->inRandomOrder()
                ->limit(3 - $wrongIds->count())
                ->pluck('id');

            $wrongIds = $wrongIds->merge($additional)->values();
        }

        if ($correctVerseId !== null) {
            return $wrongIds->push($correctVerseId)->shuffle()->values()->all();
        }

        return $wrongIds->values()->all();
    }

    private function calculateResult(Collection $questions): array
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

    private function markProgressPassed(GameSession $session): void
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

    private function findBlockedChapter(int $userId, array $surahIds): ?int
    {
        $orderedSurahIds = collect($surahIds)->sort()->values();

        foreach ($orderedSurahIds as $chapterId) {
            if ($chapterId <= 1) {
                continue;
            }

            $prevChapterId = $chapterId - 1;

            $passed = UserProgress::query()
                ->where('user_id', $userId)
                ->where('chapter_id', $prevChapterId)
                ->where('is_passed', true)
                ->exists();

            if (!$passed) {
                return $chapterId;
            }
        }

        return null;
    }
}
