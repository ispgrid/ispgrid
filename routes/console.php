<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('radius:close-stale')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('notifications:dispatch')->everyFifteenMinutes()->withoutOverlapping();
