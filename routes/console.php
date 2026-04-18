<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment('TUM Meldeplattform.');
})->purpose('Display an inspiring quote');
