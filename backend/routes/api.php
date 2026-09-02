<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\VacancyController;
use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\InterviewController;
use App\Http\Controllers\SelectionController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);
Route::post('/change-password', [AuthController::class, 'changePassword']);
Route::get('/dashboard/summary', [DashboardController::class, 'summary']);
Route::get('/vacancies', [VacancyController::class, 'published']);
Route::get('/vacancies/{vacancy}', [VacancyController::class, 'showPublic']);
Route::post('/vacancies/{vacancy}/applications', [ApplicationController::class, 'store']);
Route::get('/applications', [ApplicationController::class, 'index']);
Route::post('/applications/{application}/status', [ApplicationController::class, 'updateStatus']);
Route::get('/interviews', [InterviewController::class, 'index']);
Route::post('/interviews', [InterviewController::class, 'store']);
Route::post('/interviews/{interview}/evaluate', [InterviewController::class, 'evaluate']);
Route::get('/rankings', [SelectionController::class, 'rankings']);
Route::get('/final-selections', [SelectionController::class, 'index']);
Route::post('/final-selections', [SelectionController::class, 'nominate']);
Route::post('/final-selections/{selection}/approve', [SelectionController::class, 'approve']);
Route::post('/final-selections/{selection}/reject', [SelectionController::class, 'reject']);
Route::get('/final-selections/{selection}/recommendation', [SelectionController::class, 'recommendation']);
Route::post('/final-selections/{selection}/finalize', [SelectionController::class, 'finalize']);
Route::get('/notifications', [SelectionController::class, 'notifications']);
Route::get('/vacancies/all', [VacancyController::class, 'index']);
Route::post('/vacancies', [VacancyController::class, 'store']);
Route::post('/vacancies/{vacancy}/submit', [VacancyController::class, 'submit']);
Route::post('/vacancies/{vacancy}/hod-approve', [VacancyController::class, 'hodApprove']);
Route::post('/vacancies/{vacancy}/md-approve', [VacancyController::class, 'mdApprove']);
Route::post('/vacancies/{vacancy}/reject', [VacancyController::class, 'reject']);
Route::post('/vacancies/{vacancy}/publish', [VacancyController::class, 'publish']);
