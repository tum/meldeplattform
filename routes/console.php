<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment('TUM SafeSignal.');
})->purpose('Display an inspiring quote');
