<?php require vendor/autoload.php; \ = require_once bootstrap/app.php; \->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo Buy count:  . App\Models\Buy::count() . PHP_EOL;
