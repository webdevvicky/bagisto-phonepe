<?php

use Illuminate\Support\Facades\Route;
use Vfixtechnology\Phonepe\Http\Controllers\PhonepeController;

Route::group(['middleware' => ['web']], function () {
    Route::get('phonepe/redirect', [PhonepeController::class, 'redirect'])->name('phonepe.redirect');
    Route::post('/phonepe/callback', [PhonepeController::class, 'verify'])->name('phonepe.verify');

});
