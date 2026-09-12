<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\VacancyController;
use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\InterviewController;
use App\Http\Controllers\SelectionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\InternalEmployeeController;
use Illuminate\Support\Facades\Route;

// Prevent static endpoints such as /vacancies/all from being captured by
// implicit vacancy model binding.
Route::pattern('vacancy', '[0-9]+');
Route::get('/vacancies/{vacancy}/form', [\App\Http\Controllers\VacancyFormController::class, 'show'])->middleware(\App\Http\Middleware\StaffSession::class.':HR Manager');
Route::post('/vacancies/{vacancy}/form', [\App\Http\Controllers\VacancyFormController::class, 'store'])->middleware(\App\Http\Middleware\StaffSession::class.':HR Manager');

Route::prefix('management')->middleware(\App\Http\Middleware\StaffSession::class.':System Administrator,HR Manager')->group(function () {
    $controller = \App\Http\Controllers\StaffManagementController::class;
    Route::get('/', [$controller, 'index']);
    Route::post('/users', [$controller, 'saveUser']);
    Route::put('/users/{staff}', [$controller, 'saveUser'])->whereNumber('staff');
    Route::post('/users/{staff}/reset-password', [$controller, 'resetPassword'])->whereNumber('staff');
    Route::patch('/users/{staff}/active', [$controller, 'setActive'])->whereNumber('staff');
    Route::post('/departments', [$controller, 'saveDepartment']);
    Route::put('/departments/{department}', [$controller, 'saveDepartment'])->whereNumber('department');
});

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('/logout', [AuthController::class, 'logout'])->middleware(\App\Http\Middleware\StaffSession::class.':System Administrator,HR Manager,Head of Department,Managing Director,Data Entry Operator,Interview Panel Member,Internal Employee');
Route::post('/change-password', [AuthController::class, 'changePassword'])->middleware(\App\Http\Middleware\StaffSession::class.':System Administrator,HR Manager,Head of Department,Managing Director,Data Entry Operator,Interview Panel Member,Internal Employee');
Route::get('/dashboard/summary', [DashboardController::class, 'summary'])->middleware(\App\Http\Middleware\StaffSession::class.':System Administrator,HR Manager,Head of Department,Managing Director,Data Entry Operator,Interview Panel Member');
Route::get('/departments', [DepartmentController::class, 'index'])->middleware(\App\Http\Middleware\StaffSession::class.':System Administrator,HR Manager,Head of Department,Managing Director,Data Entry Operator');
Route::post('/internal-auth/request-otp', [InternalEmployeeController::class, 'requestOtp']);
Route::post('/internal-auth/verify-otp', [InternalEmployeeController::class, 'verifyOtp']);
Route::post('/internal-auth/logout', [InternalEmployeeController::class, 'logout']);
Route::get('/internal/vacancies', [InternalEmployeeController::class, 'vacancies']);
Route::post('/internal/vacancies/{vacancy}/apply', [InternalEmployeeController::class, 'apply']);
Route::get('/vacancies', [VacancyController::class, 'published']);
Route::get('/vacancies/{vacancy}', [VacancyController::class, 'showPublic']);
Route::post('/vacancies/{vacancy}/applications', [ApplicationController::class, 'store']);
Route::get('/applications', [ApplicationController::class, 'index'])->middleware(\App\Http\Middleware\StaffSession::class.':HR Manager,Data Entry Operator,Head of Department,Managing Director');
Route::get('/applications/completed', [ApplicationController::class, 'completed'])->middleware(\App\Http\Middleware\StaffSession::class.':HR Manager,System Administrator,Managing Director');
Route::post('/applications/{application}/status', [ApplicationController::class, 'updateStatus'])->middleware(\App\Http\Middleware\StaffSession::class.':HR Manager');
Route::get('/applications/{application}/cv-profile', [ApplicationController::class, 'cvProfile'])->middleware(\App\Http\Middleware\StaffSession::class.':HR Manager,Head of Department,Managing Director');
Route::get('/applications/{application}/documents/{document}', [ApplicationController::class, 'document'])->middleware(\App\Http\Middleware\StaffSession::class.':HR Manager,Head of Department,Managing Director');
Route::get('/interviews', [InterviewController::class, 'index'])->middleware(\App\Http\Middleware\StaffSession::class.':HR Manager,Interview Panel Member,Head of Department,Managing Director');
Route::post('/interviews', [InterviewController::class, 'store'])->middleware(\App\Http\Middleware\StaffSession::class.':HR Manager');
Route::post('/interviews/{interview}/evaluate', [InterviewController::class, 'evaluate'])->middleware(\App\Http\Middleware\StaffSession::class.':Interview Panel Member');
Route::post('/interviews/{interview}/reopen', [InterviewController::class, 'reopen'])->middleware(\App\Http\Middleware\StaffSession::class.':HR Manager');
Route::get('/rankings', [SelectionController::class, 'rankings'])->middleware(\App\Http\Middleware\StaffSession::class.':HR Manager,Managing Director');
Route::get('/final-selections', [SelectionController::class, 'index'])->middleware(\App\Http\Middleware\StaffSession::class.':HR Manager,Managing Director');
Route::post('/final-selections', [SelectionController::class, 'nominate'])->middleware(\App\Http\Middleware\StaffSession::class.':HR Manager');
Route::post('/final-selections/{selection}/approve', [SelectionController::class, 'approve'])->middleware(\App\Http\Middleware\StaffSession::class.':Managing Director');
Route::post('/final-selections/{selection}/reject', [SelectionController::class, 'reject'])->middleware(\App\Http\Middleware\StaffSession::class.':Managing Director');
Route::get('/final-selections/{selection}/recommendation', [SelectionController::class, 'recommendation'])->middleware(\App\Http\Middleware\StaffSession::class.':HR Manager');
Route::post('/final-selections/{selection}/finalize', [SelectionController::class, 'finalize'])->middleware(\App\Http\Middleware\StaffSession::class.':HR Manager');
Route::get('/notifications', [SelectionController::class, 'notifications'])->middleware(\App\Http\Middleware\StaffSession::class.':HR Manager,System Administrator');
Route::get('/vacancies/all', [VacancyController::class, 'index'])->middleware(\App\Http\Middleware\StaffSession::class.':System Administrator,HR Manager,Head of Department,Managing Director,Data Entry Operator');
Route::post('/vacancies', [VacancyController::class, 'store'])->middleware(\App\Http\Middleware\StaffSession::class.':HR Manager');
Route::post('/vacancies/{vacancy}/submit', [VacancyController::class, 'submit'])->middleware(\App\Http\Middleware\StaffSession::class.':HR Manager');
Route::post('/vacancies/{vacancy}/hod-approve', [VacancyController::class, 'hodApprove'])->middleware(\App\Http\Middleware\StaffSession::class.':Head of Department');
Route::post('/vacancies/{vacancy}/md-approve', [VacancyController::class, 'mdApprove'])->middleware(\App\Http\Middleware\StaffSession::class.':Managing Director');
Route::post('/vacancies/{vacancy}/reject', [VacancyController::class, 'reject'])->middleware(\App\Http\Middleware\StaffSession::class.':Head of Department,Managing Director');
Route::post('/vacancies/{vacancy}/publish', [VacancyController::class, 'publish'])->middleware(\App\Http\Middleware\StaffSession::class.':HR Manager');
Route::post('/vacancies/{vacancy}/cancel', [VacancyController::class, 'cancel'])->middleware(\App\Http\Middleware\StaffSession::class.':HR Manager');
Route::post('/vacancies/{vacancy}/close', [VacancyController::class, 'close'])->middleware(\App\Http\Middleware\StaffSession::class.':HR Manager');
Route::patch('/vacancies/{vacancy}', [VacancyController::class, 'update'])->middleware(\App\Http\Middleware\StaffSession::class.':HR Manager');
