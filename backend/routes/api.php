<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\GmailAccountController;
use App\Http\Controllers\EmailController;
use App\Http\Controllers\UserController;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'user']);

    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users/{user}/verify', [UserController::class, 'verify']);
    Route::put('/users/{user}', [UserController::class, 'update']);
    Route::delete('/users/{user}', [UserController::class, 'destroy']);

    // Gmail Accounts Routing
    Route::get('/accounts', [GmailAccountController::class, 'index']);
    Route::post('/accounts', [GmailAccountController::class, 'store']);
    Route::post('/accounts/{account}/test', [GmailAccountController::class, 'testExistingConnection']);
    Route::post('/accounts/{account}/sync', [GmailAccountController::class, 'sync']);
    Route::delete('/accounts/{account}', [GmailAccountController::class, 'destroy']);

    // Emails Routing
    Route::get('/emails', [EmailController::class, 'index']);
    Route::get('/emails/stats', [EmailController::class, 'statistics']);
    Route::get('/emails/{email}', [EmailController::class, 'show']);
    Route::post('/emails/{email}/reply', [EmailController::class, 'reply']);
    Route::post('/emails/{email}/forward', [EmailController::class, 'forward']);
    Route::get('/emails/{email}/attachments/{attachmentId}', [EmailController::class, 'downloadAttachment'])
        ->where('attachmentId', '[0-9.]+');
    Route::delete('/emails/{email}', [EmailController::class, 'destroy']);
});
