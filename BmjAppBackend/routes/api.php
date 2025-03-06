<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AccessesController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\QuotationController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\ProformaInvoiceController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\BackOrderController;
use App\Http\Controllers\BuyController;
use App\Http\Controllers\GoodController;
use App\Http\Controllers\DetailBuyController;
use App\Http\Controllers\DetailQuotationController;
use App\Http\Controllers\WorkOrderController;
use App\Http\Middleware\RestApiTest;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\LoginController;

# For production use "auth:api"
# For test Rest Api use "RestApiTest::class"

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::post('/tokens/create', function (Request $request) {
    $token = $request->user()->createToken($request->token_name);
    return ['token' => $token->plainTextToken];
});
Route::post('/login',  [LoginController::class, 'index']);
Route::get('/logout', [LoginController::class, 'logout']);

Route::middleware("auth:sanctum")->group(function () {
    // Employee Access
    Route::prefix('access')->group(function () {
        Route::get('/', [AccessesController::class, 'index']);
        Route::get('/{id}', [AccessesController::class, 'show']);
        Route::post('/', [AccessesController::class, 'store']);
        Route::put('/{id}', [AccessesController::class, 'update']);
        Route::delete('/{id}', [AccessesController::class, 'destroy']);
    });

    // Employee Routes
    Route::prefix('employee')->group(function () {
        Route::get('/', [EmployeeController::class, 'getAll']);
        Route::get('/{id}', [EmployeeController::class, 'show']);
        Route::post('/', [EmployeeController::class, 'store']);
        Route::put('/{id}', [EmployeeController::class, 'update']);
        Route::delete('/{id}', [EmployeeController::class, 'destroy']);
        // Aditional route
        Route::get('/access/{id}', [EmployeeController::class, 'getEmployeeAccess']);
        Route::get('/search', [EmployeeController::class, 'search']);
    });

    // Customer Routes
    Route::prefix('customer')->group(function () {
        Route::get('/', [CustomerController::class, 'index']);
        Route::get('/{id}', [CustomerController::class, 'show']);
        Route::post('/', [CustomerController::class, 'store']);
        Route::put('/{id}', [CustomerController::class, 'update']);
        Route::delete('/{id}', [CustomerController::class, 'destroy']);
    });

    // Quotation Routes
    Route::prefix('quotation')->group(function () {
        Route::get('/', [QuotationController::class, 'getAll']);
        Route::get('/{id}', [QuotationController::class, 'getDetail']);
        Route::post('/', [QuotationController::class, 'store']);
        Route::put('/{id}', [QuotationController::class, 'update']);
        Route::delete('/{id}', [QuotationController::class, 'destroy']);
        // Aditional route
        Route::get('/moveUp/{id}', [QuotationController::class, 'moveUp']);
        Route::get('/review/{id}/{reviewState}', [QuotationController::class, 'review']);
    });

    // PO Routes
    Route::prefix('purchase-order')->group(function () {
        Route::get('/', [PurchaseOrderController::class, 'getAll']);
        Route::get('/{id}', [PurchaseOrderController::class, 'getDetail']);
        Route::post('/', [PurchaseOrderController::class, 'store']);
        Route::put('/{id}', [PurchaseOrderController::class, 'update']);
        Route::delete('/{id}', [PurchaseOrderController::class, 'destroy']);
        // Aditional route
        Route::get('/moveUp/{id}/{employeId}', [PurchaseOrderController::class, 'moveUp']);
    });

    // PI Routes
    Route::prefix('proforma-invoice')->group(function () {
        Route::get('/', [ProformaInvoiceController::class, 'index']);
        Route::get('/{id}', [ProformaInvoiceController::class, 'getDetail']);
        Route::post('/', [ProformaInvoiceController::class, 'store']);
        Route::put('/{id}', [ProformaInvoiceController::class, 'update']);
        Route::delete('/{id}', [ProformaInvoiceController::class, 'destroy']);
        // Aditional route
        Route::get('/moveUp/{id}', [ProformaInvoiceController::class, 'moveUp']);
    });

    // Invoice Routes
    Route::prefix('invoice')->group(function () {
        Route::get('/', [InvoiceController::class, 'index']);
        Route::get('/{id}', [InvoiceController::class, 'show']);
        Route::post('/', [InvoiceController::class, 'store']);
        Route::put('/{id}', [InvoiceController::class, 'update']);
        Route::delete('/{id}', [InvoiceController::class, 'destroy']);
        // TODO: Handle status done
    });

    // Work Order Routes
    Route::prefix('work-order')->group(function () {
        Route::get('/', [WorkOrderController::class, 'index']);
        Route::get('/{id}', [WorkOrderController::class, 'show']);
        Route::post('/', [WorkOrderController::class, 'store']);
        Route::put('/{id}', [WorkOrderController::class, 'update']);
        Route::delete('/{id}', [WorkOrderController::class, 'destroy']);
        // TODO: Handle status
    });

    // BO Routes
    Route::prefix('back-order')->group(function () {
        Route::get('/', [BackOrderController::class, 'index']);
        Route::get('/{id}', [BackOrderController::class, 'show']);
        Route::post('/', [BackOrderController::class, 'store']);
        Route::put('/{id}', [BackOrderController::class, 'update']);
        Route::delete('/{id}', [BackOrderController::class, 'destroy']);
        // TODO: Handle status
    });

    // Buy Routes
    Route::prefix('buy')->group(function () {
        Route::get('/', [BuyController::class, 'getAll']);
        Route::get('/{id}', [BuyController::class, 'getDetail']);
        Route::post('/', [BuyController::class, 'store']);
        Route::put('/{id}', [BuyController::class, 'update']);
        Route::delete('/{id}', [BuyController::class, 'destroy']);
    });

    // Sparepart Routes
    Route::prefix('sparepart')->group(function () {
        Route::get('/', [GoodController::class, 'index']);
        Route::get('/{id}', [GoodController::class, 'show']);
        Route::post('/', [GoodController::class, 'store']);
        Route::put('/{id}', [GoodController::class, 'update']);
        Route::delete('/{id}', [GoodController::class, 'destroy']);
    });
});
