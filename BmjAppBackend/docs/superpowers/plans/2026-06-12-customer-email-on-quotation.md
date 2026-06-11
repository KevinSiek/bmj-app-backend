# Customer Email on Quotation Create — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Capture an optional, format-validated customer email when creating (and updating) a quotation, and store it on the customers table.

**Architecture:** Email is a Customer attribute — a new nullable `email` column on `customers`. The quotation create/update flow validates `customer.email` as `nullable|email`, includes it in the customer payload, but **excludes it from the find-or-create dedup match** so a differing email never spawns a duplicate customer. Frontend adds one optional input bound to `quotation.customer.email` and maps the field snake↔camel.

**Tech Stack:** Laravel 11 / PHPUnit 11 (backend), Vue 3 + Pinia (frontend).

**Spec:** `docs/superpowers/specs/2026-06-12-customer-email-on-quotation-design.md`

---

## File Structure

| Repo | File | Responsibility |
|------|------|----------------|
| backend | `database/migrations/2026_06_12_000000_add_email_to_customers_table.php` | add nullable `email` column |
| backend | `app/Models/Customer.php` | make `email` mass-assignable |
| backend | `app/Http/Controllers/QuotationController.php` | validate + persist email in `store`/`update`, not in dedup |
| backend | `app/Http/Controllers/CustomerController.php` | validate email in `store`/`update` |
| backend | `tests/Feature/QuotationEmailTest.php` | prove email persists / invalid rejected |
| frontend | `src/stores/quotation.js` | map `email` field (snake→camel) |
| frontend | `src/components/quotation/QuotationForm.vue` | optional Email input |

---

## Task 1: Migration — add `email` column to customers

**Files:**
- Create: `database/migrations/2026_06_12_000000_add_email_to_customers_table.php`

- [ ] **Step 1: Create the migration file**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('email')->nullable()->after('postal_code');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('email');
        });
    }
};
```

- [ ] **Step 2: Run the migration**

Run: `php artisan migrate`
Expected: `Migrating: 2026_06_12_000000_add_email_to_customers_table` then `DONE`.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_06_12_000000_add_email_to_customers_table.php
git commit -m "feat: add nullable email column to customers"
```

---

## Task 2: Customer model — make `email` fillable

**Files:**
- Modify: `app/Models/Customer.php:13-15`

- [ ] **Step 1: Add `email` to `$fillable`**

Replace the `$fillable` array:

```php
    protected $fillable = [
        'slug', 'company_name', 'office', 'address', 'urban', 'subdistrict', 'city', 'province', 'postal_code', 'email'
    ];
```

- [ ] **Step 2: Commit**

```bash
git add app/Models/Customer.php
git commit -m "feat: make customer email mass-assignable"
```

---

## Task 3: Failing test — email persists on quotation create, invalid rejected

**Files:**
- Create: `tests/Feature/QuotationEmailTest.php`

This test drives the QuotationController change. It posts a minimal Spareparts
quotation as an authenticated employee and asserts the created customer carries
the email; a second case asserts a malformed email is rejected with 422.

> **Note for implementer:** Before writing, open an existing feature test that
> hits `POST /api/quotation` (search `tests/` for `moveToPo` or `quotation`) to
> copy this project's exact auth/seed setup (Sanctum `actingAs`, branch/employee
> factories, the sparepart factory). The payload shape below matches
> `QuotationController::store` validation; reuse the existing test's helpers for
> the auth header and a valid `spareparts` row rather than inventing new ones.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Customer;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class QuotationEmailTest extends TestCase
{
    use RefreshDatabase;

    /** Build the minimal valid quotation payload; override customer fields per test. */
    private function quotationPayload(array $customerOverrides = []): array
    {
        // TODO(implementer): fill project/spareparts/price using the SAME helper
        // an existing quotation feature test uses. Keep customer block here:
        $customer = array_merge([
            'companyName' => 'PT Email Test',
            'office' => 'HQ',
            'address' => 'Jl. Test 1',
            'urban' => 'Urban',
            'subdistrict' => 'Sub',
            'city' => 'Semarang',
            'province' => 'Jateng',
            'postalCode' => 50111,
            'email' => 'sales@emailtest.com',
        ], $customerOverrides);

        return [
            'project' => [/* type=Spareparts, date, branch as existing test does */],
            'customer' => $customer,
            'spareparts' => [/* one valid row: sparepartId, quantity>=1, unitPriceSell>=1 */],
            'price' => ['amount' => 1000000, 'totalDiscountPercent' => 0],
            'notes' => '',
        ];
    }

    public function test_quotation_create_persists_customer_email(): void
    {
        // TODO(implementer): authenticate as an employee exactly as the existing
        // quotation feature test does (Sanctum actingAs + employee/branch setup).

        $response = $this->postJson('/api/quotation', $this->quotationPayload());

        $response->assertSuccessful();
        $this->assertDatabaseHas('customers', [
            'company_name' => 'PT Email Test',
            'email' => 'sales@emailtest.com',
        ]);
    }

    public function test_quotation_create_rejects_invalid_email(): void
    {
        // TODO(implementer): same auth setup as above.

        $response = $this->postJson(
            '/api/quotation',
            $this->quotationPayload(['email' => 'notanemail'])
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['customer.email']);
    }

    public function test_quotation_create_allows_empty_email(): void
    {
        // TODO(implementer): same auth setup as above.

        $response = $this->postJson(
            '/api/quotation',
            $this->quotationPayload(['email' => ''])
        );

        $response->assertSuccessful();
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter QuotationEmailTest`
Expected: FAIL — `test_quotation_create_persists_customer_email` fails because
`email` is not validated/saved yet (customer row has null email), and
`test_quotation_create_rejects_invalid_email` fails because `notanemail` is
currently accepted (no 422).

- [ ] **Step 3: Commit the failing test**

```bash
git add tests/Feature/QuotationEmailTest.php
git commit -m "test: customer email on quotation create (failing)"
```

---

## Task 4: QuotationController — validate + persist email (store)

**Files:**
- Modify: `app/Http/Controllers/QuotationController.php:112` (store validation)
- Modify: `app/Http/Controllers/QuotationController.php:195-205` (`$customerData`)

Do **not** touch the find-or-create `where(...)` chain at lines 208-216.

- [ ] **Step 1: Add validation rule in `store`**

After the `'customer.postalCode' => 'required|numeric',` line (around line 112), add:

```php
            'customer.email' => 'nullable|email',
```

- [ ] **Step 2: Add email to `$customerData`**

In the `$customerData` array (around lines 195-205), after the `'postal_code'`
entry, add:

```php
                'email' => $request->input('customer.email'),
```

- [ ] **Step 3: Run the test to verify store cases pass**

Run: `php artisan test --filter QuotationEmailTest`
Expected: PASS — all three cases now pass (email saved, invalid rejected, empty allowed).

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/QuotationController.php
git commit -m "feat: validate and persist customer email on quotation create"
```

---

## Task 5: QuotationController — mirror email in `update`

**Files:**
- Modify: `app/Http/Controllers/QuotationController.php:400` (update validation)
- Modify: `app/Http/Controllers/QuotationController.php` (update's `$customerData`)

The `update` method validates **outside** the DB transaction (line 384-410) so
the 422 isn't swallowed — add the rule there. Then add `email` to update's
customer payload the same way as store.

- [ ] **Step 1: Add validation rule in `update`**

After `'customer.postalCode' => 'required|numeric',` (around line 400), add:

```php
            'customer.email' => 'nullable|email',
```

- [ ] **Step 2: Add email to update's customer payload**

Find update's `$customerData` (the block that builds `company_name`, `office`,
... `postal_code` from `$request->input('customer.*')`, mirroring store's lines
195-205). After its `'postal_code'` entry, add:

```php
                'email' => $request->input('customer.email'),
```

> If `update` reuses store's customer array shape via a shared helper, add the
> field once there instead — check before duplicating.

- [ ] **Step 3: Run the full suite**

Run: `php artisan test`
Expected: PASS (no regressions; QuotationEmailTest still green).

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/QuotationController.php
git commit -m "feat: persist customer email on quotation update"
```

---

## Task 6: CustomerController — validate email in store/update

**Files:**
- Modify: `app/Http/Controllers/CustomerController.php:77-86` (store validation)
- Modify: `app/Http/Controllers/CustomerController.php:124-133` (update validation)

`email` is already fillable (Task 2), so adding it to the validated array is
enough for the standalone Customer CRUD to persist it.

- [ ] **Step 1: Add email rule to `store` validation**

In `store`, after `'postal_code' => 'required|numeric',`, add:

```php
                'email' => 'nullable|email',
```

- [ ] **Step 2: Add email rule to `update` validation**

In `update`, after `'postal_code' => 'required|numeric',`, add the identical line:

```php
                'email' => 'nullable|email',
```

- [ ] **Step 3: Run the full suite**

Run: `php artisan test`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/CustomerController.php
git commit -m "feat: validate customer email in customer CRUD"
```

---

## Task 7: Frontend store — map the email field

**Files:**
- Modify: `src/stores/quotation.js:21-30` (`mapQuotation` customer block)

Adding `email` here gives both the loaded quotation and the empty form (via
`$resetQuotation` → `mapQuotation()`) an `email` key, and it rides the create
payload automatically through `addQuotation`.

- [ ] **Step 1: Add `email` to the customer mapping**

In `mapQuotation`, change the `customer` block so the `postalCode` line ends with
a comma and an `email` line is added after it:

```js
      customer: {
        companyName: data?.customer.company_name || '',
        address: data?.customer.address || '',
        city: data?.customer.city || '',
        province: data?.customer.province || '',
        office: data?.customer.office || '',
        urban: data?.customer.urban || '',
        subdistrict: data?.customer?.subdistrict || '',
        postalCode: data?.customer?.postal_code || '',
        email: data?.customer?.email || ''
      },
```

- [ ] **Step 2: Commit**

```bash
git add src/stores/quotation.js
git commit -m "feat: map customer email in quotation store"
```

---

## Task 8: Frontend form — optional Email input

**Files:**
- Modify: `src/components/quotation/QuotationForm.vue:107-111` (Customer "right" column, after Postal Code)

- [ ] **Step 1: Add the Email input**

In the Customer section's `.right` column, after the Postal Code `div`
(around lines 107-111), add:

```html
          <div class="input form-group col-12">
            <label for="">Email <small class="text-muted">(optional)</small></label><br>
            <input type="email" class="form-control mt-2" v-model="quotation.customer.email"
              placeholder="Email" :disabled="disabled">
          </div>
```

- [ ] **Step 2: Build to verify it compiles**

Run (from `bmj-app-frontend`): `npx vite build`
Expected: `✓ built in …s`, no errors.

- [ ] **Step 3: Manual check**

Start the app, open Add Quotation, confirm the Email field renders in the
Customer section, accepts input, and is not required (submitting blank still
works). Submit with an email and confirm the created customer has it (DB or
network response).

- [ ] **Step 4: Commit**

```bash
git add src/components/quotation/QuotationForm.vue
git commit -m "feat: optional customer email input on quotation form"
```

---

## Task 9: Verify & refresh index

- [ ] **Step 1: Backend suite green**

Run: `php artisan test`
Expected: PASS, including QuotationEmailTest.

- [ ] **Step 2: detect_changes (per project CLAUDE.md)**

Run GitNexus `detect_changes()` to confirm only expected symbols/flows changed,
and refresh the index: `node .gitnexus/run.cjs analyze` from the backend root.

- [ ] **Step 3: Frontend build green**

Run (from `bmj-app-frontend`): `npx vite build`
Expected: `✓ built`.

---

## Self-Review

- **Spec coverage:** migration (Task 1), fillable (2), QuotationController store (4) + update (5), CustomerController (6), frontend form (8) + store map (7), dedup-exclusion explicitly preserved (Task 4 note), tests (3). All spec sections covered.
- **Dedup invariant:** Tasks 4/5 add email to the *payload* only; the find-or-create `where(...)` chain is explicitly left untouched. ✔
- **Type/name consistency:** backend uses `email` (snake, DB) and `customer.email` (request); frontend uses `customer.email` (camel already, no transform needed since "email" has no case split). `mapQuotation` reads `data?.customer?.email`. Consistent. ✔
- **Empty vs invalid:** `nullable|email` — empty/absent passes, malformed 422s. Test covers both. ✔
