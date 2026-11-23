<?php

use App\NativeComponents\Home;
use App\NativeComponents\Spike;
use Illuminate\Support\Facades\Route;

Route::native('/', Home::class);
Route::native('/spike', Spike::class);
