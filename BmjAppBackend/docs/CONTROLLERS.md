# Backend Controllers Guide

> **Read this** when modifying or adding controllers.

## Controller Index

| Controller | Size | Complexity | Primary Entity |
| ---------- | ---- | ---------- | -------------- |
| `QuotationController` | 104KB | ★★★★★ | Quotation + DetailQuotation |
| `PurchaseOrderController` | 51KB | ★★★★ | PurchaseOrder (lifecycle hub) |
| `DashboardController` | 27KB | ★★★ | Aggregation queries |
| `ProformaInvoiceController` | 25KB | ★★★ | ProformaInvoice |
| `BuyController` | 24KB | ★★★ | Buy + DetailBuy |
| `SparepartController` | 22KB | ★★★ | Sparepart + BranchSparepart |
| `WorkOrderController` | 21KB | ★★★ | WorkOrder + WoUnit |
| `BackOrderController` | 20KB | ★★★ | BackOrder + DetailBackOrder |
| `DeliveryOrderController` | 18KB | ★★ | DeliveryOrder |
| `InvoiceController` | 15KB | ★★ | Invoice |
| `SummaryController` | 11KB | ★★ | Aggregation queries |
| `EmployeeController` | 10KB | ★★ | Employee |
| `CustomerController` | 5KB | ★ | Customer |
| `SellerController` | 4KB | ★ | Seller |
| `AccessesController` | 3KB | ★ | Accesses + DetailAccesses |
| `GeneralController` | 3KB | ★ | General |
| `LoginController` | 5.6KB | ★★ | Employee (auth) |

## Standard Controller Pattern

Every controller follows these method conventions:

### `getAll(Request $request)`
- Paginated list with search, filter, sort
- Typical query params: `page`, `search`, `per_page`, `start_date`, `end_date`
- Returns Laravel paginated response
- Eager loads related models

### `get($id)` or `get($slug)`
- Single entity detail
- Eager loads all relationships
- Returns assembled JSON response with nested data

### `store(Request $request)`
- Validate input
- Create entity + child records (DetailQuotation, DetailBuy, etc.)
- Often generates a document number (e.g., `QUO-20260101-001`)
- Returns created entity

### `update($id, Request $request)`
- Validate input
- Update entity + sync child records
- Often deletes old children and recreates

### `destroy($id)`
- Soft delete or hard delete
- May cascade to children

### Status Transition Methods
Each entity has its own set of status transition methods:

**Quotation**: `moveToPo()`, `approve()`, `decline()`, `needChange()`, `changeStatusToReturn()`, `approveReturn()`, `declineReturn()`

**PurchaseOrder**: `moveToPi()`, `ready()`, `release()`, `done()`, `decline()`, `updateStatus()`

**ProformaInvoice**: `moveToInvoice()`, `dpPaid()`, `fullPaid()`

**WorkOrder**: `process()`

**DeliveryOrder**: `process()`

**BackOrder**: `process()`

**Buy**: `approve()`, `decline()`, `needChange()`, `done()`

## Cross-Entity Creation Pattern

The most important pattern: when a status transition creates downstream entities.

### Quotation → Purchase Order (`moveToPo`)
```
1. Set quotation.current_status = 'PO'
2. Create PurchaseOrder with quotation_id
3. Generate PO number
```

### PurchaseOrder → ProformaInvoice (`moveToPi`)
```
1. Create ProformaInvoice with purchase_order_id
2. Copy pricing from quotation
3. Set PO status to 'Prepare'
```

### ProformaInvoice → Invoice (`moveToInvoice`)
```
1. Create Invoice with proforma_invoice_id
2. Generate invoice number
```

### PurchaseOrder → Release (creates WO + DO + BO)
```
1. Create WorkOrder if quotation type includes service
2. Create DeliveryOrder
3. Create BackOrder with DetailBackOrder for insufficient stock items
4. Update PO status to 'Release'
```

## Document Number Generation

Controllers generate numbers using patterns like:
```
QUO-YYYYMMDD-NNN    (Quotation)
PO-YYYYMMDD-NNN     (Purchase Order)
PI-YYYYMMDD-NNN     (Proforma Invoice)
INV-YYYYMMDD-NNN    (Invoice)
WO-YYYYMMDD-NNN     (Work Order)
DO-YYYYMMDD-NNN     (Delivery Order)
BO-YYYYMMDD-NNN     (Back Order)
BUY-YYYYMMDD-NNN    (Purchase/Buy)
```

## Error Handling Pattern

All controller methods follow:
```php
try {
    // business logic
    return response()->json([...], Response::HTTP_OK);
} catch (\Throwable $th) {
    return response()->json([
        'message' => 'Error description',
        'error' => $th->getMessage()
    ], Response::HTTP_INTERNAL_SERVER_ERROR);
}
```

## Middleware & Auth

### RoleMiddleware
- Checks `$user->role` against allowed roles
- **Director always passes** (hardcoded bypass: `$userRole !== 'director'`)
- Role names are normalized: spaces → underscores, lowercased
- Applied per route group in `routes/api.php`

### Auth Guard
- Uses `employee` guard (not default `web`)
- Configured in `config/auth.php`
- Sanctum tokens via `HasApiTokens` trait on Employee model

## SparepartStockService

Centralized stock management service:
- `increase(Sparepart, branch, amount)` — add stock with row locking
- `decrease(Sparepart, branch, amount)` — reduce stock (floor at 0)
- `hasSufficientStock(Sparepart, branch, qty)` — check availability
- `ensureStockRecord(Sparepart, branch)` — create pivot if missing
- `resolveBranchId($branch)` — resolve from model/id/name/code

Used by: `BuyController` (on purchase receive), `PurchaseOrderController` (on
release for stock deduction), `BackOrderController`.

## SparepartImport (Excel)

`app/Imports/SparepartImport.php` (12KB) handles bulk Excel upload:
- Uses `maatwebsite/excel` package
- Maps Excel columns to sparepart fields
- Creates or updates spareparts based on sparepart_number
- Handles seller price data
- Handles branch stock data
