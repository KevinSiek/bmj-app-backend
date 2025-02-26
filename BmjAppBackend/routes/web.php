<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DetailAccessesController;
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

# For production use "auth:api"
# For test Rest Api use "RestApiTest::class"
Route::middleware([RestApiTest::class])->group(function () {
    // Employee Access
    Route::prefix('access')->group(function () {
        Route::get('/', [EmployeeController::class, 'index']);
        Route::get('/{id}', [EmployeeController::class, 'show']);
        Route::post('/', [EmployeeController::class, 'store']);
        Route::put('/{id}', [EmployeeController::class, 'update']);
        Route::delete('/{id}', [EmployeeController::class, 'destroy']);
    });

    // Employee Routes
    Route::prefix('employees')->group(function () {
        Route::get('/', [EmployeeController::class, 'getAll']);
        Route::get('/{id}', [EmployeeController::class, 'show']);
        Route::post('/', [EmployeeController::class, 'store']);
        Route::put('/{id}', [EmployeeController::class, 'update']);
        Route::delete('/{id}', [EmployeeController::class, 'destroy']);
        // Aditional route
        Route::get('/access/{id}', [EmployeeController::class, 'getEmployeeAccess']);
    });

    // Customer Routes
    Route::prefix('customers')->group(function () {
        Route::get('/', [CustomerController::class, 'index']);
        Route::get('/{id}', [CustomerController::class, 'show']);
        Route::post('/', [CustomerController::class, 'store']);
        Route::put('/{id}', [CustomerController::class, 'update']);
        Route::delete('/{id}', [CustomerController::class, 'destroy']);
    });

    // Quotation Routes
    Route::prefix('quotations')->group(function () {
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
    Route::prefix('pos')->group(function () {
        Route::get('/', [PurchaseOrderController::class, 'getAll']);
        Route::get('/{id}', [PurchaseOrderController::class, 'getDetail']);
        Route::post('/', [PurchaseOrderController::class, 'store']);
        Route::put('/{id}', [PurchaseOrderController::class, 'update']);
        Route::delete('/{id}', [PurchaseOrderController::class, 'destroy']);
        // Aditional route
        Route::get('/moveUp/{id}/{employeId}', [PurchaseOrderController::class, 'moveUp']);
    });

    // PI Routes
    Route::prefix('pis')->group(function () {
        Route::get('/', [ProformaInvoiceController::class, 'index']);
        Route::get('/{id}', [ProformaInvoiceController::class, 'getDetail']);
        Route::post('/', [ProformaInvoiceController::class, 'store']);
        Route::put('/{id}', [ProformaInvoiceController::class, 'update']);
        Route::delete('/{id}', [ProformaInvoiceController::class, 'destroy']);
        // Aditional route
        Route::get('/moveUp/{id}', [ProformaInvoiceController::class, 'moveUp']);
    });

    // Invoice Routes
    Route::prefix('invoices')->group(function () {
        Route::get('/', [InvoiceController::class, 'index']);
        Route::get('/{id}', [InvoiceController::class, 'show']);
        Route::post('/', [InvoiceController::class, 'store']);
        Route::put('/{id}', [InvoiceController::class, 'update']);
        Route::delete('/{id}', [InvoiceController::class, 'destroy']);
        // TODO: Handle status done
    });

        // Work Order Routes
        Route::prefix('wo')->group(function () {
            Route::get('/', [WorkOrderController::class, 'index']);
            Route::get('/{id}', [WorkOrderController::class, 'show']);
            Route::post('/', [WorkOrderController::class, 'store']);
            Route::put('/{id}', [WorkOrderController::class, 'update']);
            Route::delete('/{id}', [WorkOrderController::class, 'destroy']);
            // TODO: Handle status
        });

    // BO Routes
    Route::prefix('bos')->group(function () {
        Route::get('/', [BackOrderController::class, 'index']);
        Route::get('/{id}', [BackOrderController::class, 'show']);
        Route::post('/', [BackOrderController::class, 'store']);
        Route::put('/{id}', [BackOrderController::class, 'update']);
        Route::delete('/{id}', [BackOrderController::class, 'destroy']);
        // TODO: Handle status
    });

    // Buy Routes
    Route::prefix('buys')->group(function () {
        Route::get('/', [BuyController::class, 'getAll']);
        Route::get('/{id}', [BuyController::class, 'getDetail']);
        Route::post('/', [BuyController::class, 'store']);
        Route::put('/{id}', [BuyController::class, 'update']);
        Route::delete('/{id}', [BuyController::class, 'destroy']);
    });

    // Goods Routes
    Route::prefix('goods')->group(function () {
        Route::get('/', [GoodController::class, 'index']);
        Route::get('/{id}', [GoodController::class, 'show']);
        Route::post('/', [GoodController::class, 'store']);
        Route::put('/{id}', [GoodController::class, 'update']);
        Route::delete('/{id}', [GoodController::class, 'destroy']);
    });

    // Detail Buy Routes
    Route::prefix('detail-buys')->group(function () {
        Route::get('/', [DetailBuyController::class, 'index']);
        Route::get('/{id}', [DetailBuyController::class, 'show']);
        Route::post('/', [DetailBuyController::class, 'store']);
        Route::put('/{id}', [DetailBuyController::class, 'update']);
        Route::delete('/{id}', [DetailBuyController::class, 'destroy']);
    });

    // Detail Quotation Routes
    Route::prefix('detail-quotations')->group(function () {
        Route::get('/', [DetailQuotationController::class, 'index']);
        Route::get('/{id}', [DetailQuotationController::class, 'show']);
        Route::post('/', [DetailQuotationController::class, 'store']);
        Route::put('/{id}', [DetailQuotationController::class, 'update']);
        Route::delete('/{id}', [DetailQuotationController::class, 'destroy']);
    });

    // Detail accesses
    Route::prefix('detail-accesses')->group(function () {
        Route::get('/', [DetailAccessesController::class, 'index']);
        Route::get('/{id}', [DetailAccessesController::class, 'show']);
        Route::post('/', [DetailAccessesController::class, 'store']);
        Route::delete('/{id}', [DetailAccessesController::class, 'destroy']);
    });
});
