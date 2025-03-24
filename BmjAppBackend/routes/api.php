<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AccessesController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\QuotationController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\ProformaInvoiceController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\BackOrderController;
use App\Http\Controllers\BuyController;
use App\Http\Controllers\SparepartController;
use App\Http\Controllers\WorkOrderController;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\LoginController;

// Token and Login Routes
Route::post('/tokens/create', function (Request $request) {
    $token = $request->user()->createToken($request->token_name);
    return ['token' => $token->plainTextToken];
});
Route::post('/login',  [LoginController::class, 'index']);

// Authenticated Routes
Route::middleware("auth:sanctum")->group(function () {
    // Authorization
    Route::prefix('user')->group(function () {
        Route::get('/', [LoginController::class, 'getCurrentUser']);
    });
    Route::post('logout', [LoginController::class, 'logout']);

    // Employee Access
    Route::prefix('access')->group(function () {
        Route::get('/', [AccessesController::class, 'index']);
        Route::get('/{id}', [AccessesController::class, 'show']);
        Route::post('/', [AccessesController::class, 'store']);
        Route::put('/{id}', [AccessesController::class, 'update']);
        Route::delete('/{id}', [AccessesController::class, 'destroy']);
    });

    // Common Routes for Multiple Roles
    $commonRoutes = function () {
        Route::prefix('quotation')->group(function () {
            Route::get('/', [QuotationController::class, 'getAll']);
            Route::get('/{slug}', [QuotationController::class, 'getDetail']);
            Route::post('/', [QuotationController::class, 'store']);
            Route::put('/{slug}', [QuotationController::class, 'update']);
            Route::get('/moveToPo/{slug}', [QuotationController::class, 'moveToPo']);
            Route::get('/review/{isNeedReview}', [QuotationController::class, 'isNeedReview']);
            Route::get('/needChange/{slug}', [QuotationController::class, 'needChange']);
            Route::get('/approve/{slug}', [QuotationController::class, 'approve']);
            Route::get('/decline/{slug}', [QuotationController::class, 'decline']);
        });

        Route::prefix('purchase-order')->group(function () {
            Route::get('/', [PurchaseOrderController::class, 'getAll']);
            Route::get('/{id}', [PurchaseOrderController::class, 'getDetail']);
            Route::post('/', [PurchaseOrderController::class, 'store']);
            Route::put('/{id}', [PurchaseOrderController::class, 'update']);
            Route::delete('/{id}', [PurchaseOrderController::class, 'destroy']);
            Route::get('/moveUp/{id}/{employeId}', [PurchaseOrderController::class, 'moveUp']);
        });

        Route::prefix('proforma-invoice')->group(function () {
            Route::get('/', [ProformaInvoiceController::class, 'index']);
            Route::get('/{id}', [ProformaInvoiceController::class, 'getDetail']);
            Route::post('/', [ProformaInvoiceController::class, 'store']);
            Route::put('/{id}', [ProformaInvoiceController::class, 'update']);
            Route::delete('/{id}', [ProformaInvoiceController::class, 'destroy']);
            Route::get('/moveUp/{id}', [ProformaInvoiceController::class, 'moveUp']);
        });

        Route::prefix('invoice')->group(function () {
            Route::get('/', [InvoiceController::class, 'index']);
            Route::get('/{id}', [InvoiceController::class, 'show']);
            Route::post('/', [InvoiceController::class, 'store']);
            Route::put('/{id}', [InvoiceController::class, 'update']);
            Route::delete('/{id}', [InvoiceController::class, 'destroy']);
        });

        Route::prefix('back-order')->group(function () {
            Route::get('/', [BackOrderController::class, 'index']);
            Route::get('/{id}', [BackOrderController::class, 'show']);
            Route::post('/', [BackOrderController::class, 'store']);
            Route::put('/{id}', [BackOrderController::class, 'update']);
            Route::delete('/{id}', [BackOrderController::class, 'destroy']);
        });
    };

    // Inventory Middleware
    Route::middleware(['is_common_route'])->group(function () use ($commonRoutes) {
        $commonRoutes();

        // Director has all access
        Route::middleware(['is_director'])->group(function () {
            // Employee Routes
            Route::prefix('employee')->group(function () {
                Route::get('/', [EmployeeController::class, 'getAll']);
                Route::get('/{slug}', [EmployeeController::class, 'show']);
                Route::post('/', [EmployeeController::class, 'store']);
                Route::put('/{slug}', [EmployeeController::class, 'update']);
                Route::delete('/{slug}', [EmployeeController::class, 'destroy']);
                Route::get('/access/{slug}', [EmployeeController::class, 'getEmployeeAccess']);
                Route::get('/resetPassword/{slug}', [EmployeeController::class, 'resetPassword']);
                Route::post('/changePassword/{slug}', [EmployeeController::class, 'changePassword']);
            });
        });

        // Service Middleware
        Route::middleware(['is_service'])->group(function () {
            Route::prefix('work-order')->group(function () {
                Route::get('/', [WorkOrderController::class, 'index']);
                Route::get('/{id}', [WorkOrderController::class, 'show']);
                Route::post('/', [WorkOrderController::class, 'store']);
                Route::put('/{id}', [WorkOrderController::class, 'update']);
                Route::delete('/{id}', [WorkOrderController::class, 'destroy']);
            });
        });

        // Inventory Middleware
        Route::middleware(['is_inventory'])->group(function () use ($commonRoutes) {
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
                Route::get('/', [SparepartController::class, 'getAll']);
                Route::get('/{slug}', [SparepartController::class, 'getDetail']);
                Route::post('/updateAllData', [SparepartController::class, 'updateAllData']);
            });
        });

    });
});
