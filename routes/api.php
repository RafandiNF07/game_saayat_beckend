<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::post('/auth/register', [\App\Http\Controllers\API\AuthController::class, 'register']);
Route::post('/auth/login', [\App\Http\Controllers\API\AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
	Route::get('/auth/me', [\App\Http\Controllers\API\AuthController::class, 'me']);
	Route::post('/auth/logout', [\App\Http\Controllers\API\AuthController::class, 'logout']);

	Route::post('/game/start', [\App\Http\Controllers\API\GameController::class, 'startSession']);
	Route::post('/game/submit', [\App\Http\Controllers\API\GameController::class, 'submitScore']);
});

Route::get('/game/leaderboard', [\App\Http\Controllers\API\GameController::class, 'getLeaderboard']);

Route::get('chapters', [\App\Http\Controllers\ChapterController::class, 'index']);
Route::get('surah/{chapter}', [\App\Http\Controllers\ChapterController::class, 'show']);
Route::get('audio/{id_reciter}/{id_chapter}/{verse_number}', [\App\Http\Controllers\ChapterController::class, 'getAudioVerse']);
