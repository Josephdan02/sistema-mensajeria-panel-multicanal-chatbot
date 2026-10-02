<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/chatbot', function () {
    return view('chatbot');
})->name('chatbot');
