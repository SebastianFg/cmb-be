<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'name' => config('app.name'),
        'type' => 'api',
        'version' => 'v1',
        'docs' => url('/api/v1'),
    ]);
});
