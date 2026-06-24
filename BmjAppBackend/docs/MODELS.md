# Backend Models & Relationships

> **Read this** when adding or modifying Eloquent models.

## Entity Relationship Diagram

```
Employee (Authenticatable, HasApiTokens, SoftDeletes)
  │ Fields: fullname, branch, slug, role, email, username, password,
  │         temp_password, temp_pass_already_use, temp_pass_expires_at,
  │         must_change_password (boolean, default false — Jun 11)
  ├── hasMany → Quotation
  ├── hasMany → PurchaseOrder
  ├── hasMany → Invoice
  ├── hasMany → ProformaInvoice
  ├── hasMany → BackOrder
  ├── hasMany → DetailAccesses
  ├── hasMany → Borrow (Jun 11)
  └── hasMany → StockMovement (Jun 11)

Customer
  │ Fields: slug, company_name, office, address, urban, subdistrict,
  │         city, province, postal_code
  └── hasMany → Quotation

Quotation (status JSON cast)
  │ Fields: quotation_number, version, slug, customer_id, project, type,
  │         date, amount, discount, total_discount_percent, subtotal, ppn,
  │         grand_total, notes, employee_id, branch_id, current_status,
  │         status, is_return, review
  │         (total_discount_percent: decimal 5,2, default 0; >0 forces review — Jun 11)
  ├── belongsTo → Customer
  ├── belongsTo → Employee
  ├── belongsTo → Branch
  ├── hasMany → DetailQuotation
  └── hasMany → PurchaseOrder

DetailQuotation
  │ Fields: quotation_id, sparepart_id, service, service_price,
  │         quantity, unit_price, unit_price_buy, is_return, stock
  ├── belongsTo → Quotation
  └── belongsTo → Sparepart

PurchaseOrder
  │ Fields: quotation_id, purchase_order_number, po_number, purchase_order_date,
  │         payment_due, employee_id, current_status, notes, version
  │         (purchase_order_number = auto "No Internal Request";
  │          po_number = user-entered, required+UNIQUE at moveToPo — Jun 11)
  ├── belongsTo → Quotation
  ├── belongsTo → Employee
  ├── hasOne → ProformaInvoice
  ├── hasOne → BackOrder (hasOne, not hasMany)
  ├── hasOne → WorkOrder
  └── hasOne → DeliveryOrder

ProformaInvoice (casts: is_dp_paid, is_full_paid as boolean; date cast)
  │ Fields: purchase_order_id, proforma_invoice_number, proforma_invoice_date,
  │         down_payment, grand_total, is_dp_paid, is_full_paid,
  │         total_amount_text, employee_id, notes
  ├── belongsTo → PurchaseOrder
  ├── belongsTo → Employee
  └── hasOne → Invoice

Invoice
  │ Fields: proforma_invoice_id, invoice_number, invoice_date,
  │         term_of_payment, employee_id
  ├── belongsTo → ProformaInvoice
  └── belongsTo → Employee

WorkOrder
  │ Fields: purchase_order_id, work_order_number, received_by,
  │         expected_days, expected_start_date, expected_end_date,
  │         start_date, end_date, current_status, worker, compiled,
  │         head_of_service, approver, is_done, spareparts,
  │         backup_sparepart, scope, execution_time, notes
  │   (current_status lifecycle: Wait On Progress -> On Progress -> Done.
  │    vaccine/apd/peduli_lindungi columns exist in DB but UNUSED — Jun 11.)
  ├── belongsTo → PurchaseOrder
  └── hasMany → WoUnit (FK: id_wo)

WoUnit
  │ Fields: id_wo + unit-specific fields
  └── belongsTo → WorkOrder

DeliveryOrder
  │ Fields: purchase_order_id, type, current_status, notes,
  │         delivery_order_number, delivery_order_date, received_by,
  │         prepared_by, picked_by, ship_mode, order_type, delivery, npwp
  └── belongsTo → PurchaseOrder

BackOrder
  │ Fields: purchase_order_id, back_order_number, current_status, employee_id
  ├── belongsTo → PurchaseOrder
  ├── belongsTo → Employee
  ├── hasMany → DetailBackOrder
  └── hasOne → Buy

DetailBackOrder
  │ Fields: back_order_id, sparepart_id, quantity
  ├── belongsTo → BackOrder
  └── belongsTo → Sparepart

Buy
  │ Fields: buy_number, total_amount, review, current_status, notes,
  │         back_order_id, branch_id
  ├── belongsTo → BackOrder
  ├── belongsTo → Branch
  └── hasMany → DetailBuy

DetailBuy
  │ Fields: buy_id, sparepart_id, quantity, unit_price
  ├── belongsTo → Buy
  └── belongsTo → Sparepart

Borrow / Pinjaman (redesigned Jun 12)
  │ Fields: borrow_number, branch_id, employee_id, purchase_order_id,
  │         sparepart_po_id (nullable), current_status, status (JSON),
  │         notes, return_notes, reject_notes
  ├── hasMany → DetailBorrow
  ├── belongsTo → Branch
  ├── belongsTo → Employee
  ├── belongsTo → PurchaseOrder (the Service PO; purchaseOrder)
  └── belongsTo → PurchaseOrder (reconciliation Spareparts PO; sparepartPo)

DetailBorrow (Jun 12)
  │ Fields: borrow_id, sparepart_id, quantity, quantity_return (nullable)
  ├── belongsTo → Borrow
  └── belongsTo → Sparepart

StockMovement (Jun 11)
  │ Fields: sparepart_id, branch_id, delta (int), source_type, source_id,
  │         reason, employee_id
  │         (indexed on sparepart_id + created_at; per-sparepart ledger)
  ├── belongsTo → Sparepart
  ├── belongsTo → Branch
  └── belongsTo → Employee

Sparepart (SoftDeletes)
  │ Fields: slug, sparepart_number, sparepart_name, unit_price_sell, unit_price_buy
  ├── hasMany → DetailQuotation
  ├── hasMany → DetailBuy
  ├── hasMany → DetailBackOrder
  ├── hasMany → DetailSparepart
  ├── hasMany → DetailBorrow (Jun 11)
  ├── hasMany → StockMovement (Jun 11)
  ├── hasMany → BranchSparepart (branchStocks)
  ├── belongsToMany → Branch (via branch_spareparts pivot)
  └── method: getStockForBranch($branch): int

Branch
  │ Fields: name, code
  ├── belongsToMany → Sparepart (via branch_spareparts)
  ├── hasMany → BranchSparepart (sparepartStocks)
  ├── hasMany → Borrow (Jun 11)
  └── hasMany → StockMovement (Jun 11)

BranchSparepart (Pivot)
  │ Fields: sparepart_id, branch_id, quantity
  │ Table: branch_spareparts

DetailSparepart
  │ Fields: sparepart_id + seller price fields
  └── belongsTo → Sparepart

Seller
  │ Fields: code, name, type

General (singleton)
  │ Fields: discount, ppn, currency_converter

Accesses
  │ Fields: role access definitions
  └── hasMany → DetailAccesses

DetailAccesses
  │ Fields: employee_id, access_id
  ├── belongsTo → Accesses
  └── belongsTo → Employee
```

## Key Design Decisions

1. **Employee is the User model** — extends `Authenticatable`, not the default User
2. **Status stored as JSON array** — `Quotation.status` and `Borrow.status` cast to array, hold history
3. **PO has one-to-one relationships** downstream — `hasOne` for PI, WO, DO, BO
4. **Sparepart uses SoftDeletes** — deleted spareparts remain for historical data
5. **Branch stock is a pivot** — `branch_spareparts` tracks quantity per branch
6. **Versioning** — Quotations and POs share the same number with different version values
7. **Borrow/Pinjaman** — Marketing-driven request tied to a Service PO + Work Order, reviewed by Head Inventory/Director, handed over via signed PDF + Send, partially returned, and reconciled against a Spareparts PO for any shortfall (redesigned Jun 12)
8. **Stock movements** — per-sparepart indexed ledger recording all inventory changes (Jun 11)
9. **Temp password flow** — `must_change_password` gate requires single-use temp password change (Jun 11)
10. **Quotation review triggers** — any item below base*(1-discount) OR total_discount_percent > 0 forces review (Jun 11)
