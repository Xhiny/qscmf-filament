<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Quansitech\Cmf\Media\Http\Controllers\MediaFileController;
use Quansitech\Cmf\Media\Http\Controllers\MediaUploadController;

Route::middleware(config('cmf-media.middleware', ['web', 'auth']))
    ->prefix(config('cmf-media.route_prefix', 'cmf-media'))
    ->name('cmf-media.')
    ->group(function (): void {
        Route::post('check', [MediaUploadController::class, 'check'])->name('check');
        Route::post('sign', [MediaUploadController::class, 'sign'])->name('sign');
        Route::post('upload', [MediaUploadController::class, 'upload'])->name('upload'); // local 驱动专用
        Route::post('callback', [MediaUploadController::class, 'callback'])->name('callback');
        Route::get('library', [MediaUploadController::class, 'library'])->name('library');
    });

// local 驱动文件访问（按入口规则输出缓存/预览/下载响应头）：文件按公开可读
// 设计（同云桶公开读），默认仅 web 中间件，收口用 cmf-media.file_middleware。
Route::middleware(config('cmf-media.file_middleware', ['web']))
    ->prefix(config('cmf-media.route_prefix', 'cmf-media'))
    ->name('cmf-media.')
    ->group(function (): void {
        Route::get('file/{media}', [MediaFileController::class, 'show'])->name('file');
    });
