# Discipline Adapter — bmj-app-backend (BmjAppBackend)

> This file declares the capabilities of **this repo** for the Engineering
> Discipline pipeline. The process itself lives in the core discipline file;
> this adapter only supplies facts.

## Repository Facts

| Capability | Value |
| ---------- | ----- |
| `indexed` | no |
| `rulesRef` | `AGENTS.MD` + `docs/*.md` |
| `testCommand` | `php artisan test` (PHPUnit 11) |
| `buildCommand` | none (interpreted — no build step) |
| `monorepo` | false |
| `polyglot` | single (PHP/Laravel) |
| `crossRepoCallers` | `bmj-app-frontend` (Vue 3 SPA consumes all API endpoints) |

## Degradation Notes

- **Phase 0 (Understand)**: No graph index — use bounded Grep/Glob/Read.
  Start with `AGENTS.MD`, then `routes/api.php`, then relevant controller.
- **Phase 5 (Execute)**: PHPUnit test harness exists but test coverage is
  minimal. TDD Iron Law applies — write failing test first for any new
  feature-bearing code.
- **Phase 8 (Verify)**: `php artisan test` runs the test suite. No build
  step — PHP is interpreted.

## Development

```bash
php artisan serve              # Start dev server (port 8000)
php artisan migrate            # Run migrations
php artisan db:seed            # Seed database
php artisan test               # Run PHPUnit tests
composer dev                   # Start all: server + queue + logs + vite
```

## Key Entry Points

1. `AGENTS.MD` — full system overview and orchestration
2. `routes/api.php` — all API routes with middleware groups
3. `app/Http/Controllers/` — controller logic
4. `app/Models/` — Eloquent models with relationships
5. `docs/` — feature-specific documentation
