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
| `BorrowController` | 18KB | ★★ | Borrow + DetailBorrow |
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

**WorkOrder**: `process()`, `done()`

**DeliveryOrder**: `process()`

**BackOrder**: `analyze()`, `process()`

**Buy**: `approve()`, `decline()`, `needChange()`, `done()`

**Borrow**: `approve()`, `reject()`, `send()`, `kembali()`, `done()`, `cancel()`

## Cross-Entity Creation Pattern

The most important pattern: when a status transition creates downstream entities.

### Quotation → Purchase Order (`moveToPo`)
```
1. Require poNumber (user-entered real PO number, must be unique) in addition to notes
2. Validate quotation is Approved and has no existing PO
3. Generate internal purchase_order_number (auto-generated IR number)
4. Store po_number (the real PO provided by user)
5. For Spareparts type: create BackOrder with DetailBackOrder entries
   - Stock decrement is floored at 0 (cannot go negative). Any shortfall becomes a BackOrder quantity.
6. Log stock_movements for each sparepart deduction
7. Set PO status to 'BO' if any item would have backorder; otherwise to 'Prepare'
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
1. Create WorkOrder if quotation type includes service (status: "Wait On Progress")
2. Create DeliveryOrder
3. Create BackOrder with DetailBackOrder for insufficient stock items
4. Update PO status to 'Release'
```

## Document Number Generation

Controllers generate numbers using patterns like:
```
QUO-YYYYMMDD-NNN    (Quotation)
PO-YYYYMMDD-NNN     (Purchase Order; also captures unique po_number from user)
PI-YYYYMMDD-NNN     (Proforma Invoice)
INV-YYYYMMDD-NNN    (Invoice)
WO-YYYYMMDD-NNN     (Work Order)
DO-YYYYMMDD-NNN     (Delivery Order)
BO-YYYYMMDD-NNN     (Back Order)
BUY-YYYYMMDD-NNN    (Purchase/Buy)
BRW-YYYYMMDD-NNN    (Borrow)
```

## Error Handling Pattern

All controller methods follow a consistent re-throw convention to preserve HTTP semantics:

```php
try {
    // business logic
    return response()->json([...], Response::HTTP_OK);
} catch (\Throwable $th) {
    return $this->handleError($th, 'Error description');
}

protected function handleError(\Throwable $th, $message = 'Internal server error')
{
    // Preserve Laravel HTTP semantics: HttpExceptionInterface, ModelNotFoundException,
    // ValidationException, AuthorizationException must surface with real status (404/422/403),
    // not be flattened into a generic 500. These are re-thrown.
    if ($th instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
        || $th instanceof \Illuminate\Database\Eloquent\ModelNotFoundException
        || $th instanceof \Illuminate\Validation\ValidationException
        || $th instanceof \Illuminate\Auth\Access\AuthorizationException) {
        throw $th;
    }

    return response()->json([
        'message' => $message,
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
- `password.changed` middleware enforces `must_change_password` gate on protected routes

## Key Controller Behaviors

### QuotationController

**Review Trigger Logic** (sets `current_status = 'On Review'`, `review = false`):
1. **Total Discount**: If `total_discount_percent > 0`, quotation is forced to review (any value triggers Director review)
2. **Per-Item Below Base**: If any sparepart is priced below base*(1-general.discount), quotation is forced to review

Both conditions automatically set the quotation to "On Review" status.

**moveToPo**: Requires both `notes` and `poNumber` (unique). Generates internal `purchase_order_number` separately. Stock deductions floor at 0; any shortfall is tracked via BackOrder.

**Response Data**: Quotation get/getAll now include `created_by_name` and `price.total_discount_percent`.

### PurchaseOrderController

**Stock Handling**: Stock deductions floor at 0 (no negative stock). A shortfall when fulfilling orders generates a BackOrder which must be fulfilled through a manual Purchase/Buy.

**PO Data**: Responses include both `po_number` (user-entered real PO) and `purchase_order_number` (auto-generated internal request number) at top level and in nested `purchase_order` object.

**Response Data**: get/getAll include `created_by_name`, `po_number` at top level.

**Status Update**: Uses `Rule::in` enum validation.

### WorkOrderController

**3-State Lifecycle**:
1. **Wait On Progress** (initial state when PO is released)
2. **On Progress** (after `process()` is called)
3. **Done** (after `done()` is called; propagates Done status to PO + quotation)

**Response Data**: WO detail now includes IR (internal request) `purchase_order_number` and real `po_number`.

### SparepartController

**Role-Based Field Hiding**:
- **Marketing**: Can view sparepart list and detail but `unit_price_buy`, `unit_price_sell`, and `unit_price_seller` list are hidden (set to null / empty array)
- **Inventory Admin / Inventory Purchase / Head Inventory**: Can view sparepart detail but `unit_price_sell` is hidden (no margin info)
- **All others** (Director, Finance, etc.): Full visibility

**Response Data**: All formats use `formatSparepartResponse($sparepart, $request->user())` to apply role-based restrictions.

### BorrowController

**Pinjaman controller** (redesigned Jun 12). A borrow is a Marketing request
tied to a **Service** PO (`purchase_order_id`) and its Work Order, reviewed by
Head Inventory/Director, physically handed over (signed PDF + **Send**), returned
(**Kembali**), and reconciled (**Done**). `detail_borrows` carry `quantity` and,
after reconciliation, `quantity_return`.

**Lifecycle** (`current_status`):
1. **Created** — Marketing draft (no stock effect); editable/cancellable by Marketing only while Created.
2. **Approved** — reviewer accepted; Marketing can no longer cancel/edit.
3. **Borrowed** — **Send** decremented branch stock (all-or-nothing, TOCTOU-safe).
4. **Returned** — Marketing pressed **Kembali** with notes (no stock effect).
5. **Done** — Inventory recorded `quantity_return` per line; returned units restocked. A shortfall (returned < borrowed) requires a covering Spareparts-type PO (`sparepart_po_id`).
- **Rejected** — reviewer declined with notes (terminal, from Created).
- **Cancelled** — Marketing cancelled (terminal, from Created).

**Methods**:
- `purchaseOrderOptions()`: searchable/paginated PO picker (Service or Spareparts).
- `store()` / `update()`: create/edit a Created borrow against a Service PO.
- `approve()` / `reject()`: review step (Head Inventory/Director).
- `send()`: Approved → Borrowed; decrements stock per line, all-or-nothing.
- `kembali()`: Borrowed → Returned with `return_notes`.
- `done()`: Returned → Done; validates returned quantities (0..borrowed), restocks, and validates the shortfall PO covers the missing quantities.
- `cancel()`: Created → Cancelled.

**Stock Service**: `send()`/`done()` use `SparepartStockService` with all-or-nothing locking (TOCTOU-safe), `reference_type='Borrow'`.

## SparepartStockService

Centralized stock management service:
- `increase(Sparepart, branch, amount)` — add stock with row locking
- `decrease(Sparepart, branch, amount)` — reduce stock (allows negative)
- `hasSufficientStock(Sparepart, branch, qty)` — check availability
- `ensureStockRecord(Sparepart, branch)` — create pivot if missing
- `resolveBranchId($branch)` — resolve from model/id/name/code
- `logMovement(Sparepart, branch, delta, source_type, source_id, employee_id, reason)` — append to stock_movements ledger

**Negative Stock**: NO LONGER ALLOWED. `decrease()` logic floors stock at 0. If an order requests more stock than available, the available stock drops to 0, and the difference is recorded via a BackOrder.

**Used by**: `BuyController` (on purchase receive), `QuotationController.moveToPo()`, `PurchaseOrderController.release()`, `BackOrderController`, `BorrowController`.

## SparepartImport (Excel)

`app/Imports/SparepartImport.php` (12KB) handles bulk Excel upload:
- Uses `maatwebsite/excel` package
- Maps Excel columns to sparepart fields
- Creates or updates spareparts based on sparepart_number
- Handles seller price data
- Handles branch stock data
