<?php

use App\Livewire\Front\HomePage;
use Illuminate\Support\Facades\Route;

Route::middleware('site.maintenance')->group(function (): void {
    Route::get('/', HomePage::class);
});
