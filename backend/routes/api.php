<?php

use App\Http\Controllers\ProjectController;
use App\Http\Controllers\Admin\AdminAdsController;
use App\Http\Controllers\Admin\AdminAnalyticsController;
use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\AdminProjectsController;
use App\Http\Controllers\Admin\AdminScrapersController;
use Illuminate\Support\Facades\Route;

Route::get('/projects', [ProjectController::class, 'index']);
Route::get('/search', [ProjectController::class, 'search']);
Route::get('/sources', [ProjectController::class, 'sources']);

Route::prefix('admin')->group(function () {
	Route::post('/auth/login', [AdminAuthController::class, 'login']);
	Route::middleware('admin.token')->group(function () {
		Route::get('/auth/me', [AdminAuthController::class, 'me']);
		Route::post('/auth/logout', [AdminAuthController::class, 'logout']);
		Route::get('/analytics', [AdminAnalyticsController::class, 'index']);
		Route::get('/projects', [AdminProjectsController::class, 'index']);
		Route::post('/projects', [AdminProjectsController::class, 'store']);
		Route::patch('/projects/{project}', [AdminProjectsController::class, 'update']);
		Route::delete('/projects/{project}', [AdminProjectsController::class, 'destroy']);
		Route::patch('/projects/{project}/featured', [AdminProjectsController::class, 'updateFeatured']);
		Route::get('/scrapers', [AdminScrapersController::class, 'index']);
		Route::patch('/scrapers/{key}', [AdminScrapersController::class, 'update']);
		Route::post('/scrapers/{key}/run', [AdminScrapersController::class, 'run']);
		Route::get('/ads', [AdminAdsController::class, 'index']);
		Route::patch('/ads/{key}', [AdminAdsController::class, 'update']);
	});
});
