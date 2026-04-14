<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('clusters:refresh-status')->everyMinute();
