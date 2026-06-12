# Backend API Routes Reference

> **Read this** for the complete API route map with middleware groups.

## Route File: `routes/api.php`

All routes are prefixed with `/api/` automatically by Laravel.

## Authentication (No middleware)

| Method | URI | Controller@Method | Purpose |
| ------ | --- | ------------------ | ------- |
| POST | `/login` | LoginController@index | Login (returns Sanctum token) |

## Authenticated Routes (`auth:sanctum` + `password.changed`)

All authenticated routes require a valid Sanctum token AND that the user has completed password-change flow (if using a temporary password). Routes requiring specific roles are noted in their sections.

### User Management
| Method | URI | Controller@Method | Purpose |
| ------ | --- | ------------------ | ------- |
| GET | `/user` | LoginController@getCurrentUser | Get current user |
| POST | `/user/changePassword` | LoginController@changePassword | Change password |
| POST | `/logout` | LoginController@logout | Logout (revoke token) |

### Per-Role Summaries
| Method | URI | Controller@Method | Purpose |
| ------ | --- | ------------------ | ------- |
| GET | `/summary/director` | SummaryController@summaryDirector | Director summary |
| GET | `/summary/marketing` | SummaryController@summaryMarketing | Marketing summary |
| GET | `/summary/inventory` | SummaryController@summaryInventory | Inventory summary |
| GET | `/summary/finance` | SummaryController@summaryFinance | Finance summary |
| GET | `/summary/service` | SummaryController@summaryService | Service summary |

### Access Management (any authenticated)
| Method | URI | Controller@Method | Purpose |
| ------ | --- | ------------------ | ------- |
| GET | `/access` | AccessesController@index | List accesses |
| GET | `/access/{id}` | AccessesController@show | Get access |
| POST | `/access` | AccessesController@store | Create access |
| PUT | `/access/{id}` | AccessesController@update | Update access |
| DELETE | `/access/{id}` | AccessesController@destroy | Delete access |

## Marketing + Finance + Director (`role:marketing,finance,director`)

### Quotation
| Method | URI | Controller@Method | Purpose |
| ------ | --- | ------------------ | ------- |
| GET | `/quotation` | QuotationController@getAll | List quotations |
| GET | `/quotation/{slug}` | QuotationController@get | Get detail |
| POST | `/quotation` | QuotationController@store | Create |
| PUT | `/quotation/{slug}` | QuotationController@update | Update |
| POST | `/quotation/moveToPo/{slug}` | QuotationController@moveToPo | Convert to PO (requires `poNumber` in body) |
| GET | `/quotation/review/{flag}` | QuotationController@isNeedReview | Review list |
| GET | `/quotation/return/{flag}` | QuotationController@isNeedReturn | Return list |
| POST | `/quotation/needChange/{slug}` | QuotationController@needChange | Request changes |
| POST | `/quotation/approve/{slug}` | QuotationController@approve | Approve |
| POST | `/quotation/reject/{slug}` | QuotationController@decline | Reject |
| POST | `/quotation/return/{id}` | QuotationController@changeStatusToReturn | Return |
| GET | `/quotation/rejectReturn/{slug}` | QuotationController@declineReturn | Reject return |
| GET | `/quotation/approveReturn/{slug}` | QuotationController@approveReturn | Approve return |

## Marketing + Finance + Inventory + Inventory Admin + Director + Service (`role:marketing,finance,inventory,inventory_admin,director,service`)

Service is included because the Service role releases Service-type POs into Work Orders.

### Purchase Order
| Method | URI | Controller@Method | Purpose |
| ------ | --- | ------------------ | ------- |
| GET | `/purchase-order` | PurchaseOrderController@getAll | List POs |
| GET | `/purchase-order/{id}` | PurchaseOrderController@get | Get detail |
| GET | `/purchase-order/return` | PurchaseOrderController@getAllReturn | List returned POs |
| POST | `/purchase-order/moveToPi/{id}` | PurchaseOrderController@moveToPi | → PI |
| POST | `/purchase-order/status/{id}` | PurchaseOrderController@updateStatus | Update status |
| POST | `/purchase-order/ready/{id}` | PurchaseOrderController@ready | Mark ready |
| POST | `/purchase-order/release/{id}` | PurchaseOrderController@release | Release (→WO+DO+BO) |
| POST | `/purchase-order/done/{id}` | PurchaseOrderController@done | Mark done |
| POST | `/purchase-order/decline/{id}` | PurchaseOrderController@decline | Decline |
| PUT | `/purchase-order/{id}` | PurchaseOrderController@update | Update |

## Director Only (`role:director`)

### Employee
| Method | URI | Controller@Method | Purpose |
| ------ | --- | ------------------ | ------- |
| GET | `/employee` | EmployeeController@getAll | List employees |
| GET | `/employee/{slug}` | EmployeeController@get | Get detail |
| POST | `/employee` | EmployeeController@store | Create |
| PUT | `/employee/{slug}` | EmployeeController@update | Update |
| DELETE | `/employee/{slug}` | EmployeeController@destroy | Delete |
| GET | `/employee/access/{slug}` | EmployeeController@getEmployeeAccess | Get access |
| POST | `/employee/reset-password/{slug}` | EmployeeController@resetPassword | Reset password (sets must_change_password) |

### General Settings
| Method | URI | Controller@Method | Purpose |
| ------ | --- | ------------------ | ------- |
| GET | `/general` | GeneralController@get | Get settings |
| PUT | `/general` | GeneralController@update | Update settings |

### Dashboard
| Method | URI | Controller@Method | Purpose |
| ------ | --- | ------------------ | ------- |
| GET | `/dashboard/summary` | DashboardController@getSummary | Dashboard data |

## Finance + Director (`role:finance,director`)

### Invoice
| Method | URI | Controller@Method | Purpose |
| ------ | --- | ------------------ | ------- |
| GET | `/invoice` | InvoiceController@getAll | List invoices |
| GET | `/invoice/{id}` | InvoiceController@get | Get detail |

### Proforma Invoice
| Method | URI | Controller@Method | Purpose |
| ------ | --- | ------------------ | ------- |
| GET | `/proforma-invoice` | ProformaInvoiceController@getAll | List PIs |
| GET | `/proforma-invoice/{id}` | ProformaInvoiceController@get | Get detail |
| POST | `/proforma-invoice/moveToInvoice/{id}` | ProformaInvoiceController@moveToInvoice | → Invoice |
| POST | `/proforma-invoice/dpPaid/{id}` | ProformaInvoiceController@dpPaid | Mark DP paid |
| POST | `/proforma-invoice/fullPaid/{po_id}` | ProformaInvoiceController@fullPaid | Mark full paid |
| PUT | `/proforma-invoice/{id}` | ProformaInvoiceController@update | Update |

## Service + Director (`role:service,director`)

### Work Order
| Method | URI | Controller@Method | Purpose |
| ------ | --- | ------------------ | ------- |
| GET | `/work-order` | WorkOrderController@getAll | List WOs |
| GET | `/work-order/{id}` | WorkOrderController@get | Get detail |
| PUT | `/work-order/{id}` | WorkOrderController@update | Update |
| POST | `/work-order/process/{id}` | WorkOrderController@process | Process |
| POST | `/work-order/done/{id}` | WorkOrderController@done | Mark done |

## Inventory Purchase + Inventory + Head Inventory + Director

### Buy (Purchase)
| Method | URI | Controller@Method | Purpose |
| ------ | --- | ------------------ | ------- |
| GET | `/buy` | BuyController@getAll | List purchases |
| GET | `/buy/{id}` | BuyController@get | Get detail |
| POST | `/buy` | BuyController@store | Create |
| PUT | `/buy/{id}` | BuyController@update | Update |
| DELETE | `/buy/{id}` | BuyController@destroy | Delete |
| POST | `/buy/approve/{id}` | BuyController@approve | Approve |
| POST | `/buy/reject/{id}` | BuyController@decline | Reject |
| POST | `/buy/needChange/{id}` | BuyController@needChange | Request changes |
| POST | `/buy/done/{id}` | BuyController@done | Mark done (received) |
| GET | `/buy/review/{flag}` | BuyController@isNeedReview | Review list |

## Inventory Admin + Inventory + Head Inventory + Director

### Delivery Order (Delivery Note)
| Method | URI | Controller@Method | Purpose |
| ------ | --- | ------------------ | ------- |
| GET | `/delivery-order` | DeliveryOrderController@getAll | List delivery orders |
| GET | `/delivery-order/{id}` | DeliveryOrderController@get | Get detail |
| PUT | `/delivery-order/{id}` | DeliveryOrderController@update | Update |
| POST | `/delivery-order/process/{id}` | DeliveryOrderController@process | Process |

## Inventory Purchase + Inventory Admin + Inventory + Head Inventory + Director

### Back Order
| Method | URI | Controller@Method | Purpose |
| ------ | --- | ------------------ | ------- |
| GET | `/back-order` | BackOrderController@getAll | List back orders |
| GET | `/back-order/{id}` | BackOrderController@get | Get detail |
| GET | `/back-order/analyze/{id}` | BackOrderController@analyze | Check if stock is sufficient |
| POST | `/back-order/process/{id}` | BackOrderController@process | Process and decrement stock |

### Borrow (Pinjaman)

Lifecycle: `Created → Approved → Borrowed → Returned → Done`, with side-exits
`Rejected` (reviewer) and `Cancelled` (Marketing, from Created only). Stock
decreases at **Send** and increases by returned quantities at **Done**, both via
`SparepartStockService` (`reference_type='Borrow'`). Roles are gated per action.

| Method | URI | Controller@Method | Roles | Purpose |
| ------ | --- | ------------------ | ----- | ------- |
| GET | `/borrow` | BorrowController@getAll | marketing, inventory_admin, inventory_purchase, head_inventory, director | List borrow requests |
| GET | `/borrow/options/purchase-orders?type=Service\|Spareparts&search=&page=` | BorrowController@purchaseOrderOptions | same as list | Searchable, paginated PO picker (Service embeds Work Order; Spareparts embeds line items) |
| GET | `/borrow/{id}` | BorrowController@get | same as list | Get detail |
| POST | `/borrow` | BorrowController@store | marketing, director | Create request against a Service PO (`{purchaseOrderId, notes, spareparts[]}`) |
| PUT | `/borrow/{id}` | BorrowController@update | marketing, director | Update while Created |
| POST | `/borrow/cancel/{id}` | BorrowController@cancel | marketing, director | Cancel while Created |
| POST | `/borrow/kembali/{id}` | BorrowController@kembali | marketing, director | Return with notes (Borrowed → Returned) |
| POST | `/borrow/approve/{id}` | BorrowController@approve | head_inventory, director | Approve (Created → Approved) |
| POST | `/borrow/reject/{id}` | BorrowController@reject | head_inventory, director | Reject with notes (terminal) |
| POST | `/borrow/send/{id}` | BorrowController@send | inventory_admin, inventory_purchase, head_inventory, director | Handover: decrease stock (Approved → Borrowed) |
| POST | `/borrow/done/{id}` | BorrowController@done | inventory_admin, inventory_purchase, head_inventory, director | Reconcile returned quantities; shortfall requires a covering Spareparts PO (`{returned[], sparepartPoId?}`) |

## Inventory Purchase + Inventory Admin + Head Inventory + Director

### Stock Movements
| Method | URI | Controller@Method | Purpose |
| ------ | --- | ------------------ | ------- |
| GET | `/stock-movement` | SparepartController@stockMovements | List all stock movements across spareparts |

## Inventory Purchase + Inventory Admin + Marketing + Inventory + Head Inventory + Director

### Sparepart
| Method | URI | Controller@Method | Purpose |
| ------ | --- | ------------------ | ------- |
| GET | `/sparepart` | SparepartController@getAll | List spareparts |
| GET | `/sparepart/{id}` | SparepartController@get | Get detail |
| POST | `/sparepart` | SparepartController@store | Create |
| PUT | `/sparepart/{id}` | SparepartController@update | Update |
| DELETE | `/sparepart/{id}` | SparepartController@destroy | Delete |
| POST | `/sparepart/updateAllData` | SparepartController@uploadFile | Bulk Excel upload |

## Any Authenticated User

### Customer
| Method | URI | Controller@Method | Purpose |
| ------ | --- | ------------------ | ------- |
| GET | `/customer` | CustomerController@getAll | List customers |
| GET | `/customer/{slug}` | CustomerController@get | Get detail |
| POST | `/customer` | CustomerController@store | Create |
| PUT | `/customer/{slug}` | CustomerController@update | Update |
| DELETE | `/customer/{slug}` | CustomerController@destroy | Delete |

### Seller
| Method | URI | Controller@Method | Purpose |
| ------ | --- | ------------------ | ------- |
| GET | `/seller` | SellerController@getAll | List sellers |
| GET | `/seller/{slug}` | SellerController@get | Get detail |
| POST | `/seller` | SellerController@store | Create |
| PUT | `/seller/{slug}` | SellerController@update | Update |
| DELETE | `/seller/{slug}` | SellerController@destroy | Delete |
