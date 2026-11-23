<?php

use App\Models\PageView;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Cookie-less analytics: delete raw page views past the retention window.
Schedule::command('model:prune', ['--model' => [PageView::class]])->daily();
