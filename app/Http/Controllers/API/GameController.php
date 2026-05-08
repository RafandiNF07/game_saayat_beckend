<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Chapter;
use App\Models\Leaderboard;
use App\Models\EMufassir\User as AppUser;
use App\Models\UserProgress;
use App\Models\Verse;
use Illuminate\Http\Request;

class GameController extends Controller
{
    public function startSession(Request $request)
    {
        $chapterId = $request->input('chapter_id');
        $juzNumber = $request->input('juz');
        $jumlahSoal = $request->input('jumlah_soal', 5);

        // --- STRICT PROGRESSION LOGIC ---
        if ($chapterId && $chapterId > 1) {
            $previousChapterId = $chapterId - 1;
            $passed = UserProgress::where('user_id', $user->id)
                ->where('chapter_id', $previousChapterId)
                ->where('is_passed', true)
                ->exists();

            if (!$passed) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Anda harus menyelesaikan Surah sebelumnya untuk membuka level ini.'
                ], 403);
            }
        }

        // Ambil data ayat sesuai filter
        $query = Verse::query()
            ->join('verse_translations', 'verses.id', '=', 'verse_translations.id_verse')
            ->join('verse_audios', 'verses.id', '=', 'verse_audios.id_verse')
            ->select('verses.id', 'verses.text_uthmani as text', 'verse_audios.url as audio_url')
            ->where('verse_translations.id_translation', 174) // Default translation ID
            ->where('verse_audios.id_recitation', 7); // Default reciter ID

        if ($chapterId) {
            $query->where('verses.id_chapter', $chapterId);
        } else if ($juzNumber) {
            // Jika Anda menambahkan relasi juz ke tabel verses
            // $query->where('verses.juz_number', $juzNumber); 
        }

        // Ambil ayat secara acak sejumlah soal
        $questions = $query->inRandomOrder()->limit($jumlahSoal)->get();

        $quizData = $questions->map(function ($q) use ($chapterId, $juzNumber) {
            // Ambil 3 pengecoh dari chapter yang sama, atau dari seluruh verse jika chapter tidak dipilih
            $wrongQuery = Verse::where('id', '!=', $q->id);

            if ($chapterId) {
                $wrongQuery->where('id_chapter', $chapterId);
            }

            $wrongOptions = $wrongQuery
                ->inRandomOrder()
                ->limit(3)
                ->pluck('text_uthmani')
                ->toArray();

            // Gabungkan benar & pengecoh lalu acak urutan option-nya
            $options = array_merge([$q->text], $wrongOptions);
            shuffle($options);

            return [
                'verse_id' => $q->id,
                'correct_order' => $q->text,
                'audio_url' => $q->audio_url,
                'options' => $options,
                'juz_number' => $juzNumber,
                'surah_number' => $chapterId ?? 0,
            ];
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Sesi game dimulai',
            'data' => $quizData
        ]);
    }

    public function submitScore(Request $request)
    {
        $request->validate([
            'score' => 'required|integer',
            'streak' => 'required|integer',
            'combo' => 'required|integer',
            'surah_id' => 'required|integer',
            'is_perfect' => 'required|boolean'
        ]);

        $user = AppUser::query()->first();

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Tidak ada user aktif untuk menyimpan skor.'
            ], 422);
        }

        // 1. Update User Progress
        $progress = UserProgress::firstOrNew([
            'user_id' => $user->id,
            'chapter_id' => $request->surah_id
        ]);

        // Strict Progression: Lulus jika 100% benar (is_perfect = true dari Android)
        if ($request->is_perfect) {
            $progress->is_passed = true;
        }
        $progress->last_played_at = now();
        $progress->save();

        // 2. Update Leaderboard
        $leaderboard = Leaderboard::firstOrNew(['user_id' => $user->id]);
        $leaderboard->total_points += $request->score;
        
        if ($request->streak > $leaderboard->max_streak) {
            $leaderboard->max_streak = $request->streak;
        }
        
        if ($request->combo > $leaderboard->max_combo) {
            $leaderboard->max_combo = $request->combo;
        }
        
        $leaderboard->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Skor berhasil disimpan',
            'data' => [
                'total_points' => $leaderboard->total_points,
                'surah_passed' => $progress->is_passed
            ]
        ]);
    }

    public function getLeaderboard()
    {
        // Asumsi relasi user() ada di model Leaderboard
        $leaderboards = Leaderboard::with('user') 
            ->orderBy('total_points', 'desc')
            ->limit(50)
            ->get();

        return response()->json([
            'status' => 'success',
            'message' => 'Data Leaderboard',
            'data' => $leaderboards
        ]);
    }
}
