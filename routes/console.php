<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('proofs:remind')->hourly();
Schedule::command('orders:deadlines')->everyFifteenMinutes();
