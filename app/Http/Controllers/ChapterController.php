<?php

namespace App\Http\Controllers;

use App\Models\Chapter;
use App\Models\Recitation;
use App\Models\Translations;
use App\Models\Verse;
use App\Models\VerseAudios;
use Illuminate\Http\Request;

class ChapterController extends Controller
{
    public function index()
    {

        $chapter = Chapter::join('chapter_translations', 'chapters.id', '=', 'chapter_translations.id_chapter')
            ->where('chapter_translations.id_language', 13)
            ->select('chapters.*', 'chapter_translations.text AS translate_text')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $chapter
        ]);
    }

    public function arabicNumber($number)
    {
        $western_arabic = array('0', '1', '2', '3', '4', '5', '6', '7', '8', '9');
        $eastern_arabic = array('٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩');

        return str_replace($western_arabic, $eastern_arabic, strval($number));
    }

    public function show(Chapter $chapter, Request $request)
    {
        if ($request->has('audio') && $request->has('translation')
            && $request->has('verse_page') && $request->has('style')) {
            $chapterId = $chapter->id;
            $reciter_Id = $request->audio;
            $translationId = $request->translation;
            $verse_page = $request->verse_page;
            $style = $request->style;

            $ayat = Verse::join('verse_translations', 'verses.id', '=', 'verse_translations.id_verse')
                ->join('verse_audios', 'verses.id', '=', 'verse_audios.id_verse')
                ->join('chapters', 'verses.id_chapter', '=', 'chapters.id')
                ->where('verses.id_chapter', $chapterId)
                ->where('verse_translations.id_translation', $translationId)
                ->where('verse_audios.id_recitation', $reciter_Id)
                ->select('verses.*', 'verse_translations.text AS translation_text', 'verse_audios.url', 'chapters.name')
                ->paginate($verse_page);

            $surahNow = Chapter::query()->where('id', $chapterId)->get();
            $surahs = Chapter::all();

            $recitations = Recitation::all();
            $reciterSelected = Recitation::query()->where('id', $reciter_Id)->firstOrFail();

            $translations = Translations::all()->groupBy('language_name');
            // Mengurutkan berdasarkan nama bahasa secara ascending (A-Z)
            $translations = $translations->sortBy(function ($group, $language_name) {
                return $language_name;
            });
            $translationsSelected = Translations::query()->where('id', $translationId)->firstOrFail();

            foreach ($ayat as $ayah) {
                $ayah->numberArab = self::arabicNumber($ayah->number);
            }

            return response()->json([
                'status' => 'success',
                'data' => [
                    'ayat' => $ayat,
                    'surahNow' => $surahNow,
                    'surahs' => $surahs,
                    'chapter' => $chapter,
                    'style' => $style,
                    'recitations' => $recitations,
                    'reciterSelected' => $reciterSelected,
                    'chapterId' => $chapterId,
                    'translations' => $translations,
                    'translationsSelected' => $translationsSelected,
                    'verse_page' => $verse_page,
                    'reciter_Id' => $reciter_Id,
                    'translationId' => $translationId
                ]
            ]);

        } else {
            $reciter_Id = 7;
            $translationId = 174;
            $verse_page = $chapter->verse_count;
            $chapterId = $chapter->id;
            $style = "text_uthmani";

            $ayat = Verse::join('verse_translations', 'verses.id', '=', 'verse_translations.id_verse')
                ->join('verse_audios', 'verses.id', '=', 'verse_audios.id_verse')
                ->join('chapters', 'verses.id_chapter', '=', 'chapters.id')
                ->where('verses.id_chapter', $chapterId)
                ->where('verse_translations.id_translation', $translationId)
                ->where('verse_audios.id_recitation', $reciter_Id)
                ->select('verses.*', 'verse_translations.text AS translation_text', 'verse_audios.url', 'chapters.name')
                ->paginate($verse_page);

            $surahNow = Chapter::query()->where('id', $chapterId)->get();
            $surahs = Chapter::all();
            $recitations = Recitation::all();
            $reciterSelected = Recitation::query()->where('id', $reciter_Id)->firstOrFail();

            $translations = Translations::all()->groupBy('language_name');

            // Mengurutkan berdasarkan nama bahasa secara ascending (A-Z)
            $translations = $translations->sortBy(function ($group, $language_name) {
                return $language_name;
            });
            $translationsSelected = Translations::query()->where('id', $translationId)->firstOrFail();

            foreach ($ayat as $ayah) {
                $ayah->numberArab = self::arabicNumber($ayah->number);
            }

            return response()->json([
                'status' => 'success',
                'data' => [
                    'ayat' => $ayat,
                    'surahNow' => $surahNow,
                    'surahs' => $surahs,
                    'chapter' => $chapter,
                    'style' => $style,
                    'recitations' => $recitations,
                    'reciterSelected' => $reciterSelected,
                    'chapterId' => $chapterId,
                    'translations' => $translations,
                    'translationsSelected' => $translationsSelected,
                    'verse_page' => $verse_page
                ]
            ]);
        }

    }


    public function getAudioVerse($id_reciter, $id_chapter, $verse_number)
    {
        try {
            $verse = Verse::query()
                ->where('id_chapter', $id_chapter)
                ->where('number', $verse_number)
                ->firstOrFail();
            $id_verse = $verse->id;
            $audioList = VerseAudios::join('recitations', "verse_audios.id_recitation", "=", "recitations.id")
                ->where('id_recitation', $id_reciter)
                ->where('id_verse', $id_verse)
                ->firstOrFail();
            return response()->json($audioList);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
