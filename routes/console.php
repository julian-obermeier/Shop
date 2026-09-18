<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('proofs:remind')->everyFiveMinutes();
Schedule::command('orders:deadlines')->everyFifteenMinutes();
Schedule::command('offers:process-waitlists')->everyFiveMinutes();
Schedule::command('payouts:complete-executed')->hourly();

Schedule::command('privacy:purge')->dailyAt('03:30');
