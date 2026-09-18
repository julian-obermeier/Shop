<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('proofs:remind')->hourly();
Schedule::command('orders:deadlines')->everyFifteenMinutes();
Schedule::command('payouts:complete-executed')->hourly();

Schedule::command('privacy:purge')->dailyAt('03:30');
