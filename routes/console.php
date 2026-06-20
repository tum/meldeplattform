<?php

use Illuminate\Support\Facades\Schedule;

// Enforce data retention daily. Topics with no effective retention window
// (no per-topic value and no MELDE_DEFAULT_RETENTION_DAYS) are skipped, so
// this is a no-op until retention is configured.
Schedule::command('reports:prune')->daily();
