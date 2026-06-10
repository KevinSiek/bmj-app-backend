<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$pos = \App\Models\PurchaseOrder::with('quotation')->get();
echo json_encode($pos->toArray(), JSON_PRETTY_PRINT);
