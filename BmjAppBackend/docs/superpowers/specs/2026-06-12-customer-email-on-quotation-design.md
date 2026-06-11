# Customer Email on Quotation Create — Design

**Date:** 2026-06-12
**Status:** Approved (pending spec review)

## Goal

Capture an **optional** customer email when creating a quotation, and store it.
No sending, no display on detail/PDF — just input and persist. (Scope is
deliberately "input and stored for now".)

## Decision: where email lives

Email is a **Customer** attribute, stored as a new nullable `email` column on the
`customers` table — consistent with how `address`, `city`, `office`,
`postal_code`, etc. already work. The quotation links to a customer via
`customer_id` (belongsTo), so an email added here follows the customer
everywhere and autofills via the existing customer-search dropdown.

Rejected alternative: storing `email` directly on the `quotations` table. That
would diverge from how every other customer field is stored and tie the email to
a single quotation instead of the customer.

## Decision: validation

Optional but format-validated: `nullable|email`. Empty is fine; a non-empty
value must look like an email. Catches typos without blocking submission.

## Decision: dedup behavior (the load-bearing one)

`QuotationController::store` currently **finds-or-creates** the customer by
matching on all 8 identity fields (`company_name`, `office`, `address`, `urban`,
`subdistrict`, `city`, `province`, `postal_code`). See
`QuotationController.php:208-216`.

**Email is excluded from this match.** Email is set only when a *new* customer
row is created. Rationale: if email were part of the match, the same company with
a different or newly-added email would spawn a duplicate customer row. Identity =
the 8 existing fields; email is supplementary data.

## Changes

### Backend (Laravel)

1. **Migration** `add_email_to_customers_table`:
   `$table->string('email')->nullable()->after('postal_code');`
2. **`app/Models/Customer.php`** — add `'email'` to `$fillable`.
3. **`app/Http/Controllers/QuotationController.php`**:
   - `store`: add validation `'customer.email' => 'nullable|email'`; add
     `'email' => $request->input('customer.email')` to `$customerData`.
   - `update`: mirror the same validation + payload addition for symmetry.
   - **Do not** add `email` to the find-or-create `where(...)` chain.
4. **`app/Http/Controllers/CustomerController.php`** — add
   `'email' => 'nullable|email'` to the `store` and `update` validators (keeps
   the standalone Customer CRUD consistent now that `email` is a customer field).

### Frontend (Vue 3 SPA — bmj-app-frontend)

5. **`src/components/quotation/QuotationForm.vue`** — add an optional Email
   input in the Customer section (alongside Office / Postal Code), bound to
   `quotation.customer.email`. `type="email"`, not required, respects the
   existing `:disabled="disabled"` pattern.
6. **`src/stores/quotation.js`** — add `email: data?.customer?.email || ''` to
   the customer field mapping (snake→camel on load). The create payload already
   sends `quotation.customer`, so `email` rides along automatically.

## Out of scope

- Sending email.
- Showing email on quotation detail pages or PDFs.
- Any email-based customer lookup/dedup.

## Testing

Per the discipline adapter's TDD note (write failing test first for
feature-bearing code):

- **Backend (`php artisan test`)**: a feature test asserting (a) a quotation
  create with `customer.email = "foo@bar.com"` persists `email` on the created
  customer, and (b) an invalid email (`"notanemail"`) is rejected with a 422.
- **Frontend (`vite build`)**: build passes; manual check that the field renders,
  binds, and submits.

## Files touched (summary)

| Repo | File | Change |
|------|------|--------|
| backend | `database/migrations/*_add_email_to_customers_table.php` | new nullable column |
| backend | `app/Models/Customer.php` | `email` fillable |
| backend | `app/Http/Controllers/QuotationController.php` | validate + persist email (store & update), NOT in dedup |
| backend | `app/Http/Controllers/CustomerController.php` | validate email (store & update) |
| frontend | `src/components/quotation/QuotationForm.vue` | optional Email input |
| frontend | `src/stores/quotation.js` | map `email` field |
