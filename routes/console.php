<?php

use Illuminate\Support\Facades\Schedule;

// Enforce data retention daily. Topics with no effective retention window
// (no per-topic value and no MELDE_DEFAULT_RETENTION_DAYS) are skipped, so
// this is a no-op until retention is configured.
Schedule::command('reports:prune')->daily();

// Delete role-less user accounts dormant past MELDE_INACTIVE_USER_DAYS so the
// users table doesn't grow unbounded with one-off logins. A no-op when the
// window is disabled (0) or nothing is stale; admins are never pruned.
Schedule::command('users:prune')->daily();

// Remind case handlers each morning about reports approaching or past an
// acknowledgement/feedback deadline. A no-op for topics with no configured
// notification mailbox or no reports needing attention.
Schedule::command('reports:remind')->dailyAt('07:00');

// Mirror OTRS case-handler answers back into reports so reporters see them in
// the platform. A no-op unless MELDE_OTRS_INBOUND_ENABLED is set; the command
// self-skips when inbound is disabled or the connection is unconfigured.
//
// The overlap lock expires after 10 minutes rather than the 1440-minute (24h)
// default. Shared hosting can kill a cron run on max_execution_time, and a PHP
// fatal does not unwind, so the mutex would survive and silently skip every run
// for a day. Two poll runs overlapping is harmless (the high-water mark makes
// imports idempotent); a day of silence is not.
Schedule::command('otrs:poll-replies')->everyFiveMinutes()->withoutOverlapping(10);
