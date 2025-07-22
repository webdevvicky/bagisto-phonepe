<?php

use Illuminate\Support\Facades\Route;
use Webdevvicky\Phonepe\Http\Controllers\PhonepeController;

Route::group(['middleware' => ['web']], function () {
    Route::get('phonepe/redirect', [PhonepeController::class, 'redirect'])->name('phonepe.redirect');
  
   Route::get('/phonepe/verify', [PhonepeController::class, 'verify'])->name('phonepe.verify');


});
