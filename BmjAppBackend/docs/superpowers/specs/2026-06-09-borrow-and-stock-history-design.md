# Borrow (BOR) + Stock History — Design

**Date:** 2026-06-09
**Branch:** `e2e-tests-and-fixes`
**Status:** Approved (design); implementation pending.

Two related features for the BMJ Sparepart area, built as **two sequential specs**:

1. **Borrow (BOR)** — a new page to lend out spareparts and track their return. Affects only
   sparepart stock numbers; touches nothing outside stock.
2. **Stock History** — a per-sparepart in/out movement audit, backed by a new ledger table that
   every stock-mutation site writes to.

They are sequential because the Stock History ledger must capture Borrow movements (and all
other movements). Borrow is built and verified first; History second.

---

## Shared context (how BMJ already works)

- **Stock is per-branch**, in the `branch_spareparts` pivot (`BranchSparepart`: branch_id,
  sparepart_id, quantity). There is no `Sparepart.quantity` column. A part in stock in Jakarta
  is still 0 in Semarang.
- **All stock mutation goes through `app/Services/SparepartStockService.php`**
  (`increase`/`decrease`/`ensureStockRecord`/`hasSufficientStock`/`getQuantity`/`resolveBranchId`).
  `increase`/`decrease` use `lockForUpdate` but only inside the caller's transaction.
- **Two-field status convention:** every state change writes the live string to `current_status`
  AND appends `['state','employee','timestamp']` to a `status` JSON array. Only `Quotation`
  currently casts `status => array`; any new model that uses it must add the cast.
- **Transitions are named POST sub-routes** (`/approve`, `/process`, `/done`, ...), never
  PUT/PATCH on the resource.
- **Document numbers are backend-generated:** `{PREFIX}/{seq}/BMJ-MEGAH/{branchCode}/{userId}/{romanMonth}/{year}`,
  monthly-resetting sequence under `lockForUpdate`.
- **Frontend layering:** `src/api/<entity>.js` (thin httpApi wrappers) → `src/stores/<entity>.js`
  (Pinia + `map<Entity>` snake↔camel boundary) → `src/views/menu/*`. URL + route + status-label
  constants live in `src/config`. Routes are registered in `src/router/index.js` via
  `menuConfig.*` entries with lazy-imported components.
- **Roles** normalize via `str_replace(' ','_') + strtolower`; **Director bypasses any role gate
  implicitly** (include `director` in lists for readability).

---

# SPEC 1 — Borrow (BOR)

## Requirements (decided)

- **Lifecycle:** `Created` (draft, no stock effect) → `Borrowed` (decrease stock) → `Returned`
  (increase stock back). `Cancelled` from `Created` has no stock effect; `Cancelled` from
  `Borrowed` reverses the decrease.
- **Branch:** the logged-in user's branch. No branch picker in the form.
- **Contents:** one BOR holds **multiple sparepart lines** (sparepart + quantity), plus a
  borrower name and optional notes.
- **Insufficient stock:** moving to `Borrowed` is **blocked (422)** if any line's branch stock <
  borrowed quantity. A borrow can never drive stock negative. (This differs from the auto-indent
  rule used by sales/PO flows, which intentionally allows negative.)
- **Access:** Inventory Admin, Inventory Purchase, Director.

## Data model

### Table `borrows`
| column | type | notes |
|---|---|---|
| `id` | pk | |
| `borrow_number` | string, unique | `BOR/{seq}/BMJ-MEGAH/{branchCode}/{userId}/{romanMonth}/{year}` |
| `branch_id` | fk → branches | creator's branch; stock decremented/incremented here |
| `employee_id` | fk → employees | creator |
| `borrower_name` | string | who physically took the parts |
| `current_status` | string | `Created` / `Borrowed` / `Returned` / `Cancelled` |
| `status` | json (cast array) | history trail `[{state, employee, timestamp}]` |
| `notes` | string, nullable | |
| timestamps | | |

### Table `detail_borrows`
| column | type |
|---|---|
| `id` | pk |
| `borrow_id` | fk → borrows (cascade) |
| `sparepart_id` | fk → spareparts |
| `quantity` | integer |
| timestamps |

### Models
- `App\Models\Borrow` — `hasMany(DetailBorrow)`, `belongsTo(Branch)`, `belongsTo(Employee)`;
  `protected $casts = ['status' => 'array']`; fillable for all writable columns.
- `App\Models\DetailBorrow` — `belongsTo(Borrow)`, `belongsTo(Sparepart)`.

## Controller — `App\Http\Controllers\BorrowController`

Follows BMJ controller conventions: status constants; injected `SparepartStockService`;
`handleError`/`handleNotFound`/`handleForbidden` helpers (re-throw Laravel
ModelNotFound/Validation/Http/Authorization so 404/422 surface correctly); `getAccessedBorrow($request)`
query scope.

```php
const CREATED   = 'Created';
const BORROWED  = 'Borrowed';
const RETURNED  = 'Returned';
const CANCELLED = 'Cancelled';
```

| Method | Route | Effect | Stock |
|---|---|---|---|
| `getAll` | `GET /api/borrow` | grouped/paginated list (mirror BackOrder getAll) | — |
| `get` | `GET /api/borrow/{id}` | single + line items + per-branch stock display | — |
| `store` | `POST /api/borrow` | create in `Created`; generate `borrow_number` | none (draft) |
| `update` | `PUT /api/borrow/{id}` | edit only while `Created`; delete-and-recreate detail rows | none |
| `borrow` | `POST /api/borrow/borrow/{id}` | `Created → Borrowed` | **decrease** each line from `branch_id` |
| `returnItems` | `POST /api/borrow/return/{id}` | `Borrowed → Returned` | **increase** each line back |
| `cancel` | `POST /api/borrow/cancel/{id}` | `→ Cancelled` | if was `Borrowed`: increase back; if `Created`: none |

### Stock-safety rules
- **`borrow` (decrement) is all-or-nothing and race-safe.** Inside one `DB::transaction`:
  for each line, lock the `branch_spareparts` row (`ensureStockRecord(..., lockForUpdate: true)`)
  and verify `quantity >= line.quantity`. If **any** line is short, roll back the whole
  transaction and return **422** naming the short part + branch — nothing is decremented. Only if
  all lines pass, decrement each via `stockService->decrease(...)`. The locked read + the write
  live in the same transaction, closing the `hasSufficientStock` TOCTOU.
- `return`/`cancel-from-Borrowed` increase stock back via `stockService->increase(...)`, also in a
  transaction.

### Transition guards (all append to `status` trail + set `current_status`)
- `borrow`: only from `Created` (else 400).
- `returnItems`: only from `Borrowed` (else 400).
- `cancel`: not from `Returned` or `Cancelled` (else 400).
- Re-calling an already-applied transition → 400 (idempotency guard, mirrors WorkOrder `done()`).

## Routes (`routes/api.php`)
New group gated to the chosen roles:
```php
Route::middleware(['role:inventory_admin,inventory_purchase,director'])->group(function () {
    Route::prefix('borrow')->group(function () {
        Route::get('/',             [BorrowController::class, 'getAll']);
        Route::get('/{id}',         [BorrowController::class, 'get']);
        Route::post('/',            [BorrowController::class, 'store']);
        Route::put('/{id}',         [BorrowController::class, 'update']);
        Route::post('/borrow/{id}', [BorrowController::class, 'borrow']);
        Route::post('/return/{id}', [BorrowController::class, 'returnItems']);
        Route::post('/cancel/{id}', [BorrowController::class, 'cancel']);
    });
});
```

## Frontend

| Layer | File | Content |
|---|---|---|
| URL/route/label consts | `src/config` | `api.borrow = '/borrow'`; `menuMapping.borrow` + `borrow_add` + `borrow_detail` (path/name); `common.status.borrow.*` labels (created/borrowed/returned/cancelled) |
| API | `src/api/borrow.js` | `getAllBorrow`, `getBorrowById`, `addBorrow`, `updateBorrow`, `borrowBorrow`, `returnBorrow`, `cancelBorrow` (thin `httpApi` wrappers) |
| Store | `src/stores/borrow.js` | Pinia + `mapBorrow` (snake↔camel), `getAllBorrow`/`getBorrow`/`addBorrow`/`updateBorrow` + transition actions |
| Views | `src/views/menu/BorrowPage.vue` (list), `BorrowAddPage.vue` (borrower + notes + multi-sparepart rows w/ existing autocomplete), `BorrowDetailPage.vue` (detail + status-gated Borrow/Return/Cancel buttons + Track) |
| Router | `src/router/index.js` | lazy imports + `menuConfig.borrow` parent with `''`/`add`/`detail` children (Spareparts-block pattern) |
| Menu | `InventoryAdminMenu.vue`, `InventoryPurchaseMenu.vue`, `DirectorMenu.vue` + `src/config` menu list | "Borrow" item presented **grouped under Spareparts** |

**Form UX:** reuse the sparepart autocomplete (`pressSequentially` + `/api/sparepart` search);
multi-row "Add Sparepart" like the quotation form; no prices/currency. Borrow/Return/Cancel use
the existing `ModalNotes` + `closeModal` success-modal pattern. No PDF for BOR.

**Routing decision:** Borrow is a **standalone `/borrow` route grouped under the Spareparts menu**
(not a literal `/spareparts/borrow` nested route) — matches how BMJ menus group related items
without nesting routes.

## Out of scope (Spec 1)
- No ledger writes yet (that is Spec 2; Borrow's `decrease`/`increase` calls will start logging
  automatically once Spec 2 adds logging inside the service — no Borrow change needed then).
- No BOR PDF/print.
- No editing after `Borrowed`.

---

# SPEC 2 — Stock History (documented now, built after Spec 1)

## Requirements (decided)
- Backed by a **new `stock_movements` ledger table** (append-only).
- Ledger writes happen **centrally inside `SparepartStockService`** so every caller logs
  automatically and no mutation site can forget.
- Viewed **per-sparepart**, opened from the Sparepart detail page.
- Access: Inventory Admin, Inventory Purchase, Director (NOT Marketing).

## Data model — `stock_movements`
| column | type | notes |
|---|---|---|
| `id` | pk | |
| `sparepart_id` | fk → spareparts | |
| `branch_id` | fk → branches | |
| `delta` | integer | signed: + for increase, − for decrease |
| `source_type` | string | `PurchaseOrder` / `Buy` / `BackOrder` / `Return` / `Borrow` / `ManualEdit` / `Import` / ... |
| `source_id` | unsignedBigInt, nullable | id of the originating doc (null for manual/import) |
| `reason` | string, nullable | human note |
| `employee_id` | fk → employees, nullable | actor |
| `created_at` | timestamp | (append-only; `updated_at` optional) |

## Service changes
- `SparepartStockService::increase()` / `decrease()` gain optional context params
  `(string $sourceType = null, $sourceId = null, $employeeId = null, string $reason = null)` and
  write one `stock_movements` row per call (delta sign matching the operation), inside the
  caller's transaction.
- Existing callers (QuotationController moveToPo inline decrement, BackOrderController process
  increase, Buy receive/done, Return restore, Borrow) pass their context.
- **Bypass sites logged explicitly:** `SparepartController::setStockForBranch()` (manual edit)
  and the Excel import path (`SparepartImport`, sets quantity=0 / resets) write their own
  `stock_movements` rows since they don't route through increase/decrease.

## Endpoint + frontend
- `GET /api/sparepart/{id}/history` (Inventory + Director) → movements for that sparepart,
  newest first, with source label + branch + delta + actor + date. Paginated.
- Sparepart detail page (`SparepartsDetailPage.vue`) gains a **History** section/tab
  (`v-if` Inventory/Director) reading a new `stores/sparepart` history action +
  `api/sparepart` history call.

## Out of scope (Spec 2)
- No global cross-sparepart history report (per-sparepart only, by decision).
- No backfill of historical movements that predate the ledger (history starts at deploy).

---

## Testing approach (both specs)
- Live-verify specs under `playwright.verify.config.js` (non-destructive), matching the existing
  `feature-verify*.spec.js` pattern: drive transitions API-direct as Director, assert
  status + exact stock deltas (read `getQuantity` before/after), assert the insufficient-stock
  422 block, and UI screenshots of the new pages.
- For Spec 2: assert a `stock_movements` row is written per mutation with correct sign +
  source_type, and that the per-sparepart history endpoint returns them.
