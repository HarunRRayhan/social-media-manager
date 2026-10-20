<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('posts:publish-due')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::command('social-sets:purge')->daily();
Schedule::command('threads:refresh-tokens')->daily();
