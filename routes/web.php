<?php

use Illuminate\Support\Facades\Route;

Route::livewire('/', 'pages::home')->name('home');

Route::livewire('ranking', 'pages::ranking')->name('public.ranking');
Route::livewire('prefeitos', 'pages::mayors')->name('public.mayors');
Route::livewire('municipios', 'pages::municipalities')->name('public.municipalities');
Route::livewire('municipios/{ibgeCode}', 'pages::municipality')
    ->where('ibgeCode', '\d{7}')
    ->name('public.municipality');
Route::livewire('metodologia', 'pages::methodology')->name('public.methodology');
Route::livewire('dados-abertos', 'pages::open-data')->name('public.open-data');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
