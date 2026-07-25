<?php

use App\Livewire\Front\HomePage;
use App\Livewire\Whatsapp\PairAccount;
use Illuminate\Support\Facades\Route;

Route::middleware('site.maintenance')->group(function (): void {
    Route::get('/', HomePage::class);
    Route::get('/whatsapp/pair/{token}', PairAccount::class)
        ->middleware('throttle:30,1')
        ->where('token', '[A-Za-z0-9_]+')
        ->name('whatsapp.pair.show');
});