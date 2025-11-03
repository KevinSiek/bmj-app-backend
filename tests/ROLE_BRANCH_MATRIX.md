# Role × Branch Test Matrix (Manual/E2E Blueprint)

## Purpose
Validates core BMJ flows across all user roles and branch contexts after multi-branch implementation.

## Scope
- Branch contexts: All Branches, Jakarta, Semarang
- Roles: Director, Marketing, Finance, Inventory, Service, Inventory Admin, Inventory Purchase
- Modules: Dashboard, Quotation, Purchase Order, Invoice/Proforma, Spareparts, Back Order, Work Order, Employee

## Preconditions
- Backend: http://localhost:8000
- Frontend: http://localhost:5173
- Seeded accounts exist for each role (see Employee list)
- Director account: director.jkt@bmj.com / password

## Legend
- V: View
- C: Create
- U: Update
- D: Delete
- F: Filter/Scope by Branch

## Matrix (High level)

| Role | Dashboard | Quotation | Purchase Order | Invoice/Proforma | Spareparts | Back Order | Work Order | Employee |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Director | V,F | C/R/U/D,F | C/R/U/D,F | C/R/U/D,F | C/R/U/D,F | C/R/U/D,F | C/R/U/D,F | C/R/U/D,F |
| Marketing | V (sales),F | C/R/U/D,F | V,F | V,F | V (if allowed) | V | V | - |
| Finance | V (finance),F | V,F | V,F | C/R/U/D,F | - | - | - | - |
| Inventory | V (ops),F | - | C/R/U/D,F | - | C/R/U/D,F | C/R/U/D,F | - | - |
| Service | V (ops),F | V (service),F | V,F | V,F | - | - | C/R/U/D,F | - |
| Inventory Admin | V (ops),F | - | C/R/U/D,F | - | C/R/U/D,F | C/R/U/D,F | - | - |
| Inventory Purchase | V (ops),F | - | C/R/U/D,F | - | C/R/U/D,F | C/R/U/D,F | - | - |

Notes:
- Exact permissions enforced by backend policies; adjust per policy updates.
- F implies branch selector impacts data scope and KPIs.

## Test Cases (Representative)

### 1. Dashboard Branch Filtering
- For each role with dashboard access: switch All/Jakarta/Semarang and verify KPIs, Operations Snapshot, Branch Mix update correctly.
- Expected: No cross-branch leakage; Inventory Alerts BRANCH column reflects scope.

### 2. Quotation (Director, Marketing)
- Create quotation with Branch=Jakarta and Branch=Semarang; verify numbering includes JKT/SMG.
- Filter list by month/year; ensure only scoped branch entries appear.

### 3. Purchase Order (Director, Inventory, Inventory Admin, Inventory Purchase)
- Create PO for both branches; convert quotation→PO; confirm branch continuity.
- Edit and delete within allowed roles; verify audit/logs if available.

### 4. Invoice/Proforma (Director, Finance)
- Generate proforma from quotation (permitted roles) and convert to invoice; confirm branch attribution.
- Validate revenue metrics reflect scoped branch on dashboard.

### 5. Spareparts & Inventory (Inventory family)
- Verify spareparts listing; branch-aware stock and alerts; create/update items and confirm branch stock changes.

### 6. Work Order (Service)
- Create/Update work orders in each branch; ensure Service role sees appropriate data.

### 7. Employee (Director)
- Add users across all roles with branch=Jakarta/Semarang; validate uniqueness (username/email) and role-restricted menu visibility on login (optional E2E).

## Negative & Edge Cases
- Attempt restricted action per role → expect 403 or hidden UI.
- Switch branch rapidly while loading → no stale state or mixed results.
- Submit forms with missing branch → backend validation error.
- Username/email duplicates on Add Employee → proper error messaging.

## E2E Suggestions (Optional)
- Automate with Playwright/Cypress for: Director full path, Marketing quotation path, Inventory PO+spareparts path, Finance invoice path, Service work order path
- Parametrize branch context and role credentials

## Exit Criteria
- All role×branch representative tests pass with no cross-branch leakage
- No console errors; performance acceptable
- Documentation updated if policies change

Last updated: 2025-11-04
