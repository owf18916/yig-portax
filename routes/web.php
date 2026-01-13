<?php

use Illuminate\Support\Facades\Route;

// Login route (required by auth middleware for redirects)
Route::get('/login', function () {
    return redirect('/');
})->name('login');

Route::get('/{any}', function () {
    return view('app');
})->where('any', '.*');