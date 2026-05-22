<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\GmailAccountController;
use App\Http\Controllers\EmailController;

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
