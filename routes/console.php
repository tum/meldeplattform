<?php

use Illuminate\Support\Facades\Schedule;

// Enforce data retention daily. Topics with no effective retention window
// (no per-topic value and no MELDE_DEFAULT_RETENTION_DAYS) are skipped, so
// this is a no-op until retention is configured.
Schedule::command('reports:prune')->daily();

// Remind case handlers each morning about reports approaching or past an
// acknowledgement/feedback deadline. A no-op for topics with no configured
// notification mailbox or no reports needing attention.
Schedule::command('reports:remind')->dailyAt('07:00');

// Mirror OTRS case-handler answers back into reports so reporters see them in
// the platform. A no-op unless MELDE_OTRS_INBOUND_ENABLED is set; the command
// self-skips when inbound is disabled or the connection is unconfigured.
Schedule::command('otrs:poll-replies')->everyFiveMinutes()->withoutOverlapping();
