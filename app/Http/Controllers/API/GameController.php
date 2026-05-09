<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\LeaderboardResource;
use App\Http\Resources\GameSessionResource;
use App\Http\Resources\GameSubmitResource;
use App\Models\GameSession;
use App\Models\GameSessionQuestion;
use App\Models\Leaderboard;
use App\Models\Verse;
use App\Services\GameService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GameController extends Controller
{
    private GameService $gameService;

    public function __construct(GameService $gameService)
    {
        $this->gameService = $gameService;
    }
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

        $this->gameService->applyScope($baseQuery, $mode, $juzNumber, $surahIds);

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
                $nextVerseId = $this->gameService->findNextVerseIdInOrderedScope($orderedVerses, $verse->id);
                $optionVerseIds = $this->gameService->buildContinuationOptions($orderedVerses, $verse->id, $nextVerseId);

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
                $nextVerseId = $this->gameService->findNextVerseIdInOrderedScope($orderedVerses, $verse->id);
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
            'data' => GameSessionResource::withQuestions($session, $responseQuestions),
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

        // OPTIMASI: Gunakan GameService untuk processing submission dengan query-level optimization
        $this->gameService->processSubmitScore($validated['answers'], $session, $questionMap);

        $resolvedQuestions = GameSessionQuestion::query()
            ->where('game_session_id', $session->id)
            ->orderBy('question_order')
            ->get();

        [$score, $maxStreak, $maxCombo, $correctCount] = $this->gameService->calculateResult($resolvedQuestions);
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
            $this->gameService->markProgressPassed($session);
        }

        $leaderboard = $this->gameService->updateLeaderboard($user->id, $score, $maxStreak, $maxCombo);

        return response()->json([
            'status' => 'success',
            'message' => 'Skor berhasil disimpan.',
            'data' => GameSubmitResource::withTotalPoints($session, (int) $leaderboard->total_points),
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

    private function findBlockedChapter(int $userId, array $surahIds): ?int
    {
        $orderedSurahIds = collect($surahIds)->sort()->values();

        foreach ($orderedSurahIds as $chapterId) {
            if ($chapterId <= 1) {
                continue;
            }

            $prevChapterId = $chapterId - 1;

            $passed = \App\Models\UserProgress::query()
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
