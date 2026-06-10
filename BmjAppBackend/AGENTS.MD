# AGENTS.MD — BMJ App Backend (Laravel 11)

> **Purpose**: This file is the LLM orchestrator for `bmj-app-backend`. Read
> this FIRST in every new session to understand the entire backend codebase in
> under 60 seconds. Then read the feature-specific `docs/*.md` files only when
> you need to modify that feature.

---

## 1. What This Application Is

**BMJ App Backend** is the REST API server for **PT Berkat Megah Jaya's**
internal ERP system. It manages the full order lifecycle from quotation to
invoice, plus inventory (spareparts), service (work orders), logistics
(delivery orders), and employee management. It serves a **Vue 3 SPA frontend**.

### Business Domain (Order Lifecycle)

```
Quotation → Purchase Order → Proforma Invoice → Invoice
                           → Work Order (service jobs)
                           → Delivery Order (logistics)
                           → Back Order → Buy (purchasing missing parts from sellers)
```

Each entity has a status lifecycle managed by its controller, with
approval/rejection/review workflows.

---

## 2. Tech Stack

| Layer          | Technology                                      |
| -------------- | ----------------------------------------------- |
| Framework      | Laravel 11                                      |
| PHP            | ≥ 8.0                                           |
| Auth           | Laravel Sanctum (token-based API auth)           |
| Database       | MySQL (configurable via `.env`)                  |
| Excel Import   | maatwebsite/excel 3.1.67                        |
| Testing        | PHPUnit 11                                       |
| Queue          | Laravel Queue (database driver)                  |
| Dev Tools      | Laravel Pail (logs), Laravel Sail (Docker)       |

---

## 3. Project Structure Map

```
BmjAppBackend/
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Api/
│   │   │   │   └── LoginController.php      # Auth: login, logout, getCurrentUser, changePassword
│   │   │   ├── QuotationController.php      # ★ Largest controller (104KB) — full quotation lifecycle
│   │   │   ├── PurchaseOrderController.php  # PO lifecycle + status transitions
│   │   │   ├── ProformaInvoiceController.php# PI lifecycle + payment tracking
│   │   │   ├── InvoiceController.php        # Invoice management
│   │   │   ├── WorkOrderController.php      # Service work order management
│   │   │   ├── DeliveryOrderController.php  # Logistics/delivery management
│   │   │   ├── BackOrderController.php      # Missing sparepart tracking
│   │   │   ├── BuyController.php            # Purchase from sellers
│   │   │   ├── SparepartController.php      # Sparepart CRUD + bulk upload
│   │   │   ├── EmployeeController.php       # Employee CRUD + access management
│   │   │   ├── CustomerController.php       # Customer CRUD
│   │   │   ├── SellerController.php         # Seller CRUD
│   │   │   ├── DashboardController.php      # Dashboard analytics/summaries
│   │   │   ├── GeneralController.php        # Global settings (discount, PPN, currency)
│   │   │   ├── SummaryController.php        # Per-role summary data
│   │   │   └── AccessesController.php       # Role access management
│   │   └── Middleware/
│   │       ├── RoleMiddleware.php           # ★ Role-based access control
│   │       └── RestApiTest.php              # Dev testing middleware
│   ├── Models/                              # Eloquent models (→ docs/MODELS.md)
│   │   ├── Employee.php                     # ★ User model (extends Authenticatable)
│   │   ├── Quotation.php                    # Quotation with versioning
│   │   ├── PurchaseOrder.php                # Links Quotation → downstream entities
│   │   ├── ProformaInvoice.php              # Payment tracking (DP + full)
│   │   ├── Invoice.php                      # Final invoice
│   │   ├── WorkOrder.php                    # Service job tracking
│   │   ├── DeliveryOrder.php                # Delivery logistics
│   │   ├── BackOrder.php                    # Missing sparepart orders
│   │   ├── Buy.php                          # Purchase from seller
│   │   ├── Sparepart.php                    # Sparepart with branch-based stock
│   │   ├── Branch.php                       # Company branches (Jakarta, Semarang)
│   │   ├── BranchSparepart.php              # Pivot: branch ↔ sparepart stock
│   │   ├── Customer.php                     # Customer companies
│   │   ├── Seller.php                       # Sparepart sellers
│   │   ├── General.php                      # Global settings
│   │   ├── Accesses.php                     # Access roles
│   │   ├── DetailAccesses.php               # Employee ↔ access mapping
│   │   ├── DetailQuotation.php              # Quotation line items
│   │   ├── DetailBuy.php                    # Purchase line items
│   │   ├── DetailBackOrder.php              # Back order line items
│   │   ├── DetailSparepart.php              # Seller price per sparepart
│   │   └── WoUnit.php                       # Work order units
│   ├── Services/
│   │   └── SparepartStockService.php        # ★ Stock management (increase/decrease/check)
│   ├── Imports/
│   │   └── SparepartImport.php              # Excel bulk import for spareparts
│   └── Providers/
│       └── AppServiceProvider.php           # Default provider
├── routes/
│   ├── api.php                              # ★ ALL API routes (Sanctum-protected)
│   └── web.php                              # Legacy test routes (all commented out)
├── database/
│   ├── migrations/                          # 29 migration files
│   └── seeders/                             # Database seeders
├── config/                                  # Laravel config files
│   ├── auth.php                             # Auth guards (employee guard)
│   ├── sanctum.php                          # Sanctum config
│   ├── cors.php                             # CORS settings
│   └── ...
└── .env                                     # Environment config
```

---

## 4. Architecture Patterns (MUST FOLLOW)

### 4.1 Authentication & Authorization

**Auth model**: `Employee` (not Laravel's default `User`). It extends
`Authenticatable` and uses `HasApiTokens` (Sanctum).

**Login flow**:
1. `POST /api/login` with `email` + `password`
2. Auth guard: `employee` (configured in `config/auth.php`)
3. Supports temporary passwords with one-time use and expiry
4. Returns Sanctum `plainTextToken` + user object
5. All subsequent requests use `Authorization: Bearer <token>`

**Role middleware**: `RoleMiddleware` checks `$user->role` against allowed
roles. **Director role always passes** (hardcoded bypass in middleware).

### 4.2 Route Organization

All active routes are in `routes/api.php`, organized by role middleware:

| Middleware                                               | Routes                            |
| -------------------------------------------------------- | --------------------------------- |
| `auth:sanctum` (any authenticated user)                  | user, logout, customer, seller    |
| `role:marketing,finance,director`                        | quotation                         |
| `role:marketing,finance,inventory,inventory_admin,director` | purchase-order                 |
| `role:director`                                          | employee, general, dashboard      |
| `role:finance,director`                                  | invoice, proforma-invoice         |
| `role:service,director`                                  | work-order                        |
| `role:inventory_purchase,inventory,director`             | buy                               |
| `role:inventory_admin,inventory,director`                | delivery-order                    |
| `role:inventory_purchase,inventory_admin,inventory,director` | back-order                    |
| `role:inventory_purchase,inventory_admin,marketing,inventory,director` | sparepart          |

### 4.3 Controller Pattern

Controllers follow a consistent pattern:
1. **`getAll()`** — paginated list with search/filter/sort
2. **`get($id)`** — detail view with eager-loaded relationships
3. **`store()`** — create with validation
4. **`update($id)`** — update with validation
5. **`destroy($id)`** — soft delete or hard delete
6. **Status transition methods** — `approve()`, `decline()`, `process()`, `done()`, etc.
7. **Cross-entity creation** — `moveToPo()`, `moveToPi()`, `moveToInvoice()`

### 4.4 Model Relationships (Entity Graph)

```
Employee ─────────┐
    │              │
    ├── Quotation ─┼── Customer
    │   ├── DetailQuotation ── Sparepart
    │   ├── Branch
    │   └── PurchaseOrder
    │       ├── ProformaInvoice ── Invoice
    │       ├── WorkOrder ── WoUnit
    │       ├── DeliveryOrder
    │       └── BackOrder
    │           ├── DetailBackOrder ── Sparepart
    │           └── Buy
    │               └── DetailBuy ── Sparepart
    │
Sparepart ── BranchSparepart ── Branch
    └── DetailSparepart (seller prices)

Seller (standalone, referenced in DetailSparepart)
General (singleton settings: discount, ppn, currency_converter)
Accesses ── DetailAccesses ── Employee
```

### 4.5 Branch-Based Inventory

Spareparts have **per-branch stock** via pivot table `branch_spareparts`.
The `SparepartStockService` handles:
- `increase()` / `decrease()` — stock mutations with row locking
- `ensureStockRecord()` — auto-create pivot if missing
- `hasSufficientStock()` — check availability
- `resolveBranchId()` — resolve branch by model/id/name/code

### 4.6 Status Lifecycle Pattern

Most entities track status as an array (JSON column) of historical status
entries, plus a `current_status` string for the latest status. Controllers
manage transitions:

```php
// Typical status transition pattern
$entity->current_status = 'Approved';
$entity->status = array_merge($entity->status ?? [], [
    ['status' => 'Approved', 'date' => now(), 'by' => $user->fullname]
]);
$entity->save();
```

### 4.7 Quotation Versioning

Quotations support **versioning**. Multiple quotations can share the same
`quotation_number` with different `version` values. The frontend groups them
by `quotation_number` and shows version tabs.

---

## 5. API Endpoint Reference

### Auth
| Method | Endpoint                        | Controller                  | Purpose              |
| ------ | ------------------------------- | --------------------------- | -------------------- |
| POST   | `/api/login`                    | LoginController@index       | Login                |
| POST   | `/api/logout`                   | LoginController@logout      | Logout               |
| GET    | `/api/user`                     | LoginController@getCurrentUser | Get current user  |
| POST   | `/api/user/changePassword`      | LoginController@changePassword | Change password   |

### Quotation (`/api/quotation`)
| Method | Endpoint                              | Purpose                    |
| ------ | ------------------------------------- | -------------------------- |
| GET    | `/`                                   | List all (paginated)       |
| GET    | `/{slug}`                             | Get detail                 |
| POST   | `/`                                   | Create quotation           |
| PUT    | `/{slug}`                             | Update quotation           |
| POST   | `/moveToPo/{slug}`                    | Convert to Purchase Order  |
| GET    | `/review/{isNeedReview}`              | Get items needing review   |
| GET    | `/return/{isNeedReturn}`              | Get items needing return   |
| POST   | `/approve/{slug}`                     | Approve quotation          |
| POST   | `/reject/{slug}`                      | Reject quotation           |
| POST   | `/needChange/{slug}`                  | Request changes            |
| POST   | `/return/{id}`                        | Change status to return    |
| GET    | `/rejectReturn/{slug}`                | Reject return              |
| GET    | `/approveReturn/{slug}`               | Approve return             |

### Purchase Order (`/api/purchase-order`)
| Method | Endpoint                              | Purpose                    |
| ------ | ------------------------------------- | -------------------------- |
| GET    | `/`                                   | List all                   |
| GET    | `/{id}`                               | Get detail                 |
| POST   | `/moveToPi/{id}`                      | Convert to Proforma Invoice|
| POST   | `/ready/{id}`                         | Mark as ready              |
| POST   | `/release/{id}`                       | Release (creates WO/DO/BO) |
| POST   | `/done/{id}`                          | Mark as done               |
| POST   | `/decline/{id}`                       | Decline/reject             |
| PUT    | `/{id}`                               | Update PO                  |

### Other CRUD Endpoints
Each follows the standard pattern: `GET /`, `GET /{id}`, `POST /`, `PUT /{id}`,
`DELETE /{id}` plus domain-specific status/action endpoints.

See `routes/api.php` for the complete route list.

---

## 6. Database Schema Overview

### Core Tables
| Table                | Key Columns                                                        |
| -------------------- | ------------------------------------------------------------------ |
| `employees`          | fullname, branch, slug, role, email, username, password, temp_password |
| `customers`          | slug, company_name, office, address, city, province, postal_code   |
| `quotations`         | quotation_number, version, slug, customer_id, type, amount, discount, subtotal, ppn, grand_total, current_status, status(JSON), branch_id |
| `detail_quotations`  | quotation_id, sparepart_id, service, service_price, quantity, unit_price |
| `purchase_orders`    | quotation_id, purchase_order_number, purchase_order_date, payment_due, current_status, version |
| `proforma_invoices`  | purchase_order_id, proforma_invoice_number, down_payment, grand_total, is_dp_paid, is_full_paid |
| `invoices`           | proforma_invoice_id, invoice_number, invoice_date, term_of_payment |
| `work_orders`        | purchase_order_id, work_order_number, expected_days, current_status, worker, scope |
| `work_order_units`   | id_wo, unit fields                                                 |
| `delivery_orders`    | purchase_order_id, type, delivery_order_number, ship_mode, current_status |
| `back_orders`        | purchase_order_id, back_order_number, current_status               |
| `detail_back_orders` | back_order_id, sparepart_id, quantity                              |
| `buys`               | buy_number, total_amount, review, current_status, back_order_id, branch_id |
| `detail_buys`        | buy_id, sparepart_id, quantity, unit_price                         |
| `spareparts`         | slug, sparepart_number, sparepart_name, unit_price_sell, unit_price_buy |
| `branches`           | name, code                                                         |
| `branch_spareparts`  | sparepart_id, branch_id, quantity (pivot)                          |
| `detail_spareparts`  | sparepart_id, seller prices                                       |
| `sellers`            | code, name, type                                                   |
| `generals`           | discount, ppn, currency_converter                                  |
| `accesses`           | role access definitions                                            |
| `detail_accesses`    | employee_id, access_id mapping                                    |

---

## 7. Key Files to Read First

When starting a new session or task, read these files in order:

1. **This file** (`AGENTS.MD`) — system overview
2. **`routes/api.php`** — all API routes and middleware groups
3. **The specific controller** for the feature you're modifying
4. **The specific model(s)** involved
5. **`app/Http/Middleware/RoleMiddleware.php`** — if touching auth/access
6. **`app/Services/SparepartStockService.php`** — if touching inventory

---

## 8. Naming Conventions

| Entity           | Convention                        | Example                       |
| ---------------- | --------------------------------- | ----------------------------- |
| Controllers      | `{Entity}Controller.php`          | `QuotationController.php`     |
| Models           | Singular PascalCase               | `PurchaseOrder.php`           |
| Migrations       | Laravel timestamp convention      | `create_quotations.php`       |
| Routes           | kebab-case prefixes               | `/api/purchase-order`         |
| DB columns       | snake_case                        | `current_status`              |
| Relationships    | camelCase method names            | `purchaseOrder()`             |

---

## 9. Common Patterns You MUST Replicate

### Adding a new entity:
1. Create migration in `database/migrations/`
2. Create Model in `app/Models/` with `$fillable`, relationships, casts
3. Create Controller in `app/Http/Controllers/` following the getAll/get/store/update pattern
4. Add routes in `routes/api.php` with appropriate role middleware
5. If it has line items, create a Detail model and migration

### Adding a status transition:
1. Add new method in the entity's controller
2. Update `current_status` and append to `status` JSON array
3. Add route in `routes/api.php`
4. If it triggers downstream entity creation, handle in the same transaction

---

## 10. Related Frontend

The frontend is a **Vue 3 SPA** at:
`e:\Kerja\Kerja\Bkawan\PT_BerkatMegahJaya\Code\FrontEnd\bmj-app-frontend`

See `AGENTS.MD` in that repo for the frontend orchestration doc.

---

## 11. Testing & Quality Assurance

The real, implemented test suite is a **Playwright E2E suite in the frontend repo** —
**35 specs / 328 tests, verified green** — that exercises THIS backend over HTTP (lifecycle,
full role×route authz matrix, per-field validation, stock + concurrency, negatives, edge cases).

**Read these FIRST before touching any controller/service:**
1. `Code\FrontEnd\bmj-app-frontend\docs\tests\LLM_HANDOVER.md` — ⭐ current state, how to run,
   the **6 backend bugs fixed** (and which test guards each), and a validation failure-code
   cheat sheet (422 vs 400 vs 404 differ per controller).
2. `Code\FrontEnd\bmj-app-frontend\e2e\README.md` — verified status + spec map.
3. The `e2e\*.spec.js` specs themselves are the **source of truth** (the older
   `docs\tests\TEST_*.md` files are a stale planning catalog, not the implemented suite).

**Backend behaviors now guarded by tests (do not regress):** `handleError` re-throws HTTP
exceptions so `findOrFail`→404 / `validate`→422 (not 500); `Buy@destroy` deletes `detail_buys`
first; temp passwords are single-use via the `must_change_password` gate
(`EnsurePasswordChanged` middleware on the `auth:sanctum` group); `moveToPo` stock floors at 0;
`PO updateStatus` validates against a status enum; slugs are length-capped. Run
`npx playwright test` (servers up, single worker — it reseeds the DB) before declaring a
controller/service change safe.

---

## 12. Development Commands

```bash
php artisan serve                # Start dev server (default: http://localhost:8000)
php artisan migrate              # Run migrations
php artisan db:seed              # Seed database
php artisan queue:listen         # Start queue worker
composer dev                     # Start all: server + queue + logs + vite
php artisan test                 # Run PHPUnit tests
```

---

## 12. Environment Variables (Key Ones)

| Variable          | Purpose                            |
| ----------------- | ---------------------------------- |
| `DB_CONNECTION`   | Database driver (mysql)            |
| `DB_HOST`         | Database host                      |
| `DB_PORT`         | Database port                      |
| `DB_DATABASE`     | Database name                      |
| `DB_USERNAME`     | Database user                      |
| `DB_PASSWORD`     | Database password                  |
| `SANCTUM_STATEFUL_DOMAINS` | Frontend domains for Sanctum |
| `SESSION_DRIVER`  | Session storage                    |
