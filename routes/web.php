<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Disabled for API Only Mode.
|
*/

Route::get('/', function () {
    return response()->json([
        'message' => 'Quran Sambung Ayat API is Running',
        'status' => 'success'
    ]);
});
