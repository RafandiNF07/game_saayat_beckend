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
            'jumlah_soal' => 'required|integer|min:1|max:50',
            'juz' => 'required_if:mode,juz|integer|min:1|max:30',
            'surah_ids' => 'required_if:mode,surah|array|min:1',
            'surah_ids.*' => 'integer|distinct|exists:chapters,id',
            'reciter_id' => 'nullable|integer',
            'is_autoplay' => 'nullable|boolean',
        ]);

        $mode = $validated['mode'];
        $jumlahSoal = (int) $validated['jumlah_soal'];
        $reciterId = (int) ($validated['reciter_id'] ?? 7);
        $isAutoplay = (bool) ($validated['is_autoplay'] ?? true);
        $juzNumber = isset($validated['juz']) ? (int) $validated['juz'] : null;
        $surahIds = collect($validated['surah_ids'] ?? [])->map(fn ($id) => (int) $id)->values()->all();

        $baseQuery = Verse::query();
        $scope = [
            'mode' => $mode,
            'juz' => $juzNumber,
            'surah_ids' => $surahIds,
        ];

        $this->gameService->applyScope($baseQuery, $mode, $juzNumber, $surahIds);

        // Optimization: Hanya ambil kolom yang diperlukan untuk prompt selection
        $orderedVerses = $baseQuery
            ->orderBy('id_chapter')
            ->orderBy('number')
            ->get(['id', 'id_chapter', 'number']);

        // Exclude last verse of the scope
        $eligiblePrompts = $orderedVerses->slice(0, max(0, $orderedVerses->count() - 1))->values();
        
        if ($eligiblePrompts->count() < $jumlahSoal) {
            return response()->json([
                'status' => 'error',
                'message' => 'Data ayat tidak cukup untuk jumlah soal ini.',
            ], 422);
        }

        $selectedVerses = $eligiblePrompts->shuffle()->take($jumlahSoal)->values();

        $session = DB::transaction(function () use ($user, $mode, $scope, $jumlahSoal, $reciterId, $isAutoplay, $selectedVerses, $orderedVerses) {
            $session = GameSession::create([
                'user_id' => $user->id,
                'mode' => $mode,
                'scope' => $scope,
                'question_count' => $jumlahSoal,
                'reciter_id' => $reciterId,
                'is_autoplay' => $isAutoplay,
                'status' => 'in_progress',
                'started_at' => now(),
            ]);

            foreach ($selectedVerses as $index => $verse) {
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

        // Load data lengkap untuk response
        $responseQuestions = GameSessionQuestion::where('game_session_id', $session->id)
            ->with(['verse.chapter'])
            ->orderBy('question_order')
            ->get()
            ->map(function (GameSessionQuestion $question) use ($reciterId, $orderedVerses) {
                $verse = $question->verse;
                $nextVerseId = $this->gameService->findNextVerseIdInOrderedScope($orderedVerses, $verse->id);
                $promptTranslation = DB::table('verse_translations')
                    ->where('id_verse', $verse->id)
                    ->where('id_translation', 33) // Default Kemenag
                    ->value('text') ?? '';
                
                $audioUrl = optional($verse->audio()->where('id_recitation', $reciterId)->first())->url;

                $options = Verse::whereIn('id', $question->option_verse_ids)
                    ->with('chapter')
                    ->get()
                    ->sortBy(fn($opt) => array_search($opt->id, $question->option_verse_ids))
                    ->values()
                    ->map(fn($opt) => [
                        'verse_id' => $opt->id,
                        'text' => $opt->text_uthmani,
                        'surah_name' => optional($opt->chapter)->name,
                        'verse_number' => $opt->number,
                    ]);

                return [
                    'question_id' => $question->id,
                    'order' => $question->question_order,
                    'verse_id' => $verse->id,
                    'text' => $verse->text_uthmani,
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
            'answers' => 'required|array',
            'answers.*.question_id' => 'required|integer|exists:game_session_questions,id',
            'answers.*.selected_verse_id' => 'required|integer|exists:verses,id',
            'answers.*.attempts' => 'required|integer|min:1',
        ]);

        $user = $request->user();
        $session = GameSession::where('id', $validated['session_id'])
            ->where('user_id', $user->id)
            ->first();

        if (!$session || $session->status !== 'in_progress') {
            return response()->json(['status' => 'error', 'message' => 'Sesi tidak valid.'], 400);
        }

        $questionMap = GameSessionQuestion::where('game_session_id', $session->id)->get()->keyBy('id');
        
        if ($questionMap->count() !== count($validated['answers'])) {
            return response()->json(['status' => 'error', 'message' => 'Jumlah jawaban tidak sesuai.'], 422);
        }

        $this->gameService->processSubmitScore($validated['answers'], $session, $questionMap);

        $resolvedQuestions = GameSessionQuestion::where('game_session_id', $session->id)
            ->orderBy('question_order')
            ->get();

        [$score, $maxStreak, $maxCombo, $correctCount] = $this->gameService->calculateResult($resolvedQuestions);
        
        $session->update([
            'status' => 'submitted',
            'finished_at' => now(),
            'correct_count' => $correctCount,
            'score' => $score,
            'max_streak' => $maxStreak,
            'max_combo' => $maxCombo,
            'is_perfect' => ($correctCount === (int)$session->question_count),
        ]);

        if ($session->is_perfect) {
            $this->gameService->markProgressPassed($session);
        }

        $leaderboard = $this->gameService->updateLeaderboard($user->id, $score, $maxStreak, $maxCombo);

        return response()->json([
            'status' => 'success',
            'data' => GameSubmitResource::withTotalPoints($session, (int) $leaderboard->total_points),
        ]);
    }

    public function getLeaderboard()
    {
        $leaderboards = Leaderboard::with('user')
            ->orderBy('total_points', 'desc')
            ->limit(50)
            ->get();

        return LeaderboardResource::collection($leaderboards)->additional(['status' => 'success']);
    }
}
