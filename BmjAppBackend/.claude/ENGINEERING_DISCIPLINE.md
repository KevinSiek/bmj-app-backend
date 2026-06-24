# Engineering Discipline — Zero to Finish (portable core)

The single, mandatory process for every coding task in **any repo of this
workspace**. This file is the one authority. Each repo carries a thin
`.claude/discipline-adapter.md` that declares *its* capabilities; this core
defines the pipeline and how it degrades when a capability is absent. Adapters
never restate this file — that would be the redundancy the prime directive
forbids.

> **Workspace anchor.** This file lives in `spfio-core/.claude/` and is THE
> authority for every repo in this workspace. All repos in `Progres_2/` sit
> under one parent folder, so every adapter references this file with a
> **relative path** (e.g. `../../spfio-core/.claude/ENGINEERING_DISCIPLINE.md`
> from a sibling repo; `../../../spfio-core/.claude/ENGINEERING_DISCIPLINE.md`
> from a nested repo like `Spf_io_projector/spfio-projector/`). Relative paths
> keep the contract machine-portable — clone the workspace anywhere and links
> still resolve.

It fuses three installed systems so each phase is both **disciplined** and
**capable**:

- **Compound Engineering** (`ce:*`) — the phase skeleton (ideate → brainstorm →
  plan → work → review → compound) and the unique learning loop.
- **Superpowers** — the discipline that hardens each joint (TDD Iron Law,
  brainstorming HARD-GATE, verification-before-completion, root-cause debugging).
- **gstack** — specialist muscle and the eyes (real browser QA, `/codex` second
  opinion, `/ship`→`/land-and-deploy`→`/canary`, design tooling).

> **The prime directive is code quality.** Every line is written like a senior
> expert engineer: simple, optimized for a *human reader*, no redundancy, reuse
> existing code before writing new, expandable, easy to maintain. Every other
> phase exists to protect that outcome. A clever solution a teammate cannot read
> in one pass is a defect, not an achievement.

---

## Two locked constraints (never violated)

1. 🔒 **No auto-commit. Ever.** Never `git commit`, `git push`, or open a PR
   without an explicit, in-conversation request for *that specific action*.
   This overrides every skill default that commits automatically — Superpowers
   `brainstorming` step 6, gstack `/ship` and routing setup, `ce:work` and
   `ce:review` commit steps. Write the file, stop, tell the user it is ready,
   let them decide. See memory `feedback-no-auto-commit`.
2. 🔒 **Browser/QA = gstack only.** Superpowers has no browser. gstack's win32
   friction is an accepted tradeoff. All page testing, deployment verification,
   and visual QA go through gstack `/qa`, `/qa-only`, or the `browse` binary.

---

## The pipeline

Every coding task — from a one-line fix to a cross-cutting feature — passes
through these phases. Ceremony scales with **scope**; behavior adapts to repo
**capability** (see the two gates below). The phase *order* never changes and
no phase is skipped silently.

```
0. UNDERSTAND   →  graph if indexed, else bounded targeted reads — never guess
1. IDEATE       →  (only when "what should we do?" is open)
2. BRAINSTORM   →  WHAT to build  (HARD-GATE: design approved before code)
3. PLAN         →  HOW to build it (decisions + exact files + test scenarios)
4. WORKTREE     →  isolated branch, clean test baseline
5. EXECUTE      →  TDD Iron Law per unit, follow existing patterns
6. REVIEW       →  multi-persona + independent second model
7. QA           →  real browser evidence (gstack)
8. VERIFY       →  evidence-before-claims gate
9. FINISH       →  user-approved integration (never auto)
10. COMPOUND    →  institutionalize the learning
```

### Phase ownership — which system runs each phase

| # | Phase | Owner skill | Discipline overlay | Hard rule |
|---|-------|-------------|--------------------|-----------|
| 0 | Understand | `discipline-explorer` (graph if `indexed`, else targeted reads) | — | When indexed: never read 3+ files the graph answers; run impact analysis before edits. When not: bounded Grep/Glob/Read on task-named symbols, state "graph unavailable". |
| 1 | Ideate | `ce:ideate` | — | Only when the user asks "what should I improve / what's worth doing". Skip when the task is already specified. |
| 2 | Brainstorm | **`ce:brainstorm`** (canonical) | Superpowers `brainstorming` **HARD-GATE** | No code, no scaffold, no implementation skill until a design is presented and the user approves. Applies to *every* task regardless of perceived simplicity. |
| 3 | Plan | `ce:plan` / `discipline-planner` | Superpowers `writing-plans` rigor | Plan contains exact file paths + explicit test scenarios per feature-bearing unit. "Decisions, not code." Ready = an implementer can start without the plan writing the code for them. |
| 4 | Worktree | Superpowers `using-git-worktrees` | — | Isolated branch, project setup run, clean test baseline verified *before* writing anything. |
| 5 | Execute | `ce:work` + Superpowers **`test-driven-development`** | Superpowers `subagent-driven-development` for independent units | **Iron Law: NO production code without a failing test first.** Degrades per the capability matrix when no harness exists — it never silently disappears. Follow existing patterns; reuse before inventing; extract a helper on the *second* use, not the first. |
| 6 | Review | `ce:review` / `discipline-reviewer` (confidence-gated) + gstack **`/codex`** (independent 2nd model) | Superpowers `requesting-code-review` / `receiving-code-review` | Critical (P0/P1) findings block progress. "Ask Codex" on tricky logic is itself a severity signal. Cross-model agreement is a recommendation; the user decides. |
| 7 | QA | gstack **`/qa`** (or `/qa-only`) | — | Real browser evidence for any user-facing change. Screenshots shown via Read. Use env vars for test creds. |
| 8 | Verify | Superpowers **`verification-before-completion`** / `discipline-verifier` | — | No "done/fixed/passing" claim without the command output that proves it. Report failures and skipped steps plainly. |
| 9 | Finish | Superpowers `finishing-a-development-branch` | — | Present merge/PR/keep/discard options. **Never auto-commit/push/PR** (constraint #1). |
| 10 | Compound | **`ce:compound`** | auto-memory write | Document the solved problem in `docs/solutions/` (or the repo's equivalent) so the next occurrence takes minutes. Do not skip on non-trivial work. |

---

## Gate 1 — Scope: how much ceremony

Match process weight to the work. The phase *order* is fixed; the *depth* flexes.

| Scope | Signals | Pipeline depth |
|-------|---------|----------------|
| **Trivial** | 1–2 files, no behavior change (typo, config, rename) | 0 → 5 (test if behavior-bearing) → 8. Brainstorm = one-sentence design + explicit user OK (HARD-GATE still applies, just compact). Skip 1,3,4,7,10. |
| **Small/Medium** | Clear scope, < ~10 files | Full pipeline, compact artifacts. Ideate usually skipped. |
| **Large** | Cross-cutting, architectural, 10+ files, touches auth/payments/migrations/conversion | Full pipeline, full artifacts. Ideate if direction is open. `/autoplan` may run phases 2–3+codex in one pass. |

When unsure which bucket, ask one targeted scope question, then proceed.

---

## Gate 2 — Capability: how the pipeline adapts per repo

Scope flexes *depth*; capability flexes *behavior*. Each repo's
`.claude/discipline-adapter.md` declares these. The pipeline order is identical
everywhere — only the tactics below change when a capability is absent.

| Capability | Values | Meaning |
|------------|--------|---------|
| `indexed` | yes / no | GitNexus index exists for this repo |
| `rulesRef` | path / none | repo's documented-pattern catalog |
| `testCommand` | string / per-function / none | how tests run |
| `buildCommand` | string / none | how the repo builds |
| `monorepo` | true / false | independent sub-projects, no root build |
| `polyglot` | langs / single | multiple languages in one repo |
| `crossRepoCallers` | list / none / unknown | repos depending on this one |

**Degradation matrix (authoritative):**

| Phase | Full capability | Degraded behavior when absent |
|-------|-----------------|-------------------------------|
| 0 Understand | `indexed=yes` → graph-first (`gitnexus_*`) | `indexed=no` → targeted Grep/Glob/Read bounded to task-named symbols; declare "graph unavailable, reads bounded" |
| 0 Understand | `crossRepoCallers` known → blast radius across repos | `none`/`unknown` → single-repo analysis, explicitly stated |
| 3 Plan / 6 Review | `rulesRef` set → conform to documented patterns (by behavior, never by ID) | `none` → conform to surrounding code + the global/workspace engineering principles; reviewer runs the universal checks only |
| 5 Execute / 8 Verify | `testCommand` is a single suite | `monorepo`/`per-function` → test only the touched function/unit (`cd` into it). **No harness for the touched unit → TDD Iron Law degrades to: bootstrap a minimal failing test for that unit as the first change. If bootstrap is infeasible, report VERIFIED-WITH-GAP naming the unit — never silently claim pass.** |
| 5 Execute | `polyglot` | match the touched unit's language toolchain (e.g. Node vs Python test runner) — do not assume one |
| 8 Verify | `buildCommand` set | absent → skip the build claim explicitly; do not imply green |

The Iron Law's *intent* (no unproven code) is preserved everywhere; only its
literal form bends where a harness genuinely cannot exist yet.

---

## Canonical brainstorming decision

Three brainstorming skills are installed: Superpowers `brainstorming`, gstack
`/office-hours`, CE `ce:brainstorm`. **Decision: `ce:brainstorm` is canonical**,
with the Superpowers HARD-GATE layered on top.

- **Pipeline coherence.** `ce:brainstorm`'s requirements doc feeds `ce:plan` →
  `ce:work` → `ce:review` as one artifact chain. A different brainstorm skill
  would break the hand-off into `ce:plan`.
- **Right-sizing built in.** `ce:brainstorm` Phase 0 scales ceremony and
  short-circuits when requirements are already clear.
- **Discipline is additive.** Superpowers `brainstorming` HARD-GATE supplies
  the one thing `ce:brainstorm` lacks — an absolute "no implementation until
  design approved" gate, every task regardless of simplicity.
- `/office-hours` (gstack) only for pure new-product strategy ("is this worth
  building at all"), not the default feature path.

---

## Conflicts resolved

| Conflict | Resolution |
|----------|------------|
| 3 brainstorming skills compete | `ce:brainstorm` canonical + Superpowers HARD-GATE overlay. `/office-hours` only for new-product strategy. |
| Multiple skills auto-commit | Constraint #1 wins universally. Strip/decline every commit step; surface the file as ready and wait. |
| gstack browser friction on win32 | Accepted. gstack still owns QA (constraint #2). Pure-prompt phases unaffected by OS. |
| `ce:review` vs gstack `/review` vs `/codex` | `ce:review`/`discipline-reviewer` primary; gstack `/codex` alongside as independent 2nd model. gstack `/review` not used as a third — avoids redundant noise. |
| `ce:work` TDD vs Superpowers TDD | Superpowers `test-driven-development` Iron Law governs; `ce:work` provides the execution loop and pattern-following around it. |

---

## Self-check before claiming any coding task done

1. Did a design get presented and approved **before** any code? (HARD-GATE)
2. Was every production unit preceded by a **failing test I watched fail** — or, where no harness can exist, a stated VERIFIED-WITH-GAP? (Iron Law / its degraded form)
3. Did I **reuse** existing code/patterns instead of re-inventing? Any redundancy left?
4. Would a teammate read each changed function top-to-bottom **without a guide**?
5. If the repo is indexed: did I run impact analysis on every modified symbol and surface HIGH/CRITICAL? If not indexed: were my reads bounded and stated as such?
6. Do I have **command output** proving tests pass / behavior works? (no claim without evidence)
7. Did I avoid every auto-commit, and surface artifacts as *ready* for the user to commit?
8. For non-trivial work: did I `ce:compound` the learning?

If any answer is "no", the task is not done — say so plainly with the gap.

---

## Quick reference — task to first action

| User says | First action |
|-----------|--------------|
| "what should I improve / give me ideas" | `ce:ideate` |
| "is this worth building" (new product) | gstack `/office-hours` |
| "let's build / add / change X" (feature) | `ce:brainstorm` (HARD-GATE) |
| "plan this / how should we build X" | `ce:plan` (after requirements exist) |
| "implement the plan" | worktree → `ce:work` + TDD Iron Law |
| "review my changes" | `ce:review` + gstack `/codex` |
| "test the site / does it work" | gstack `/qa` |
| "why is this broken / bug" | Superpowers `systematic-debugging` (root cause first) |
| "ship it / deploy" | confirm scope → gstack `/ship` → **stop for commit approval** |
| "we just solved X, remember it" | `ce:compound` |

---

## Agent roster — subagents + on-demand teams

Four reusable subagent definitions live at **user scope**
`~/.claude/agents/` (`C:\Users\Lukas_K\.claude\agents\`), so they are reachable
from *every* repo of this workspace regardless of which folder they sit in
(Claude Code walks up from cwd to user scope; project scope would trap them in
one repo and break the others). All four are **read-only** — none have the
Edit/Write tool, so they cannot violate constraint #1 structurally.
`discipline-verifier` is intentionally narrower still (`Read, Bash` only). The
other three carry `Read, Grep, Glob, Bash`.

Each agent's first act is to read the **current repo's**
`.claude/discipline-adapter.md` and announce the detected capabilities, then
behave per the degradation matrix above. They never assume spfio-core specifics.

| Subagent | Phase | When Claude delegates |
|----------|-------|-----------------------|
| `discipline-explorer` | 0 Understand | Start of any task touching existing code — recon + blast radius |
| `discipline-planner` | 3 Plan | After requirements clear, before code — returns the plan, doesn't write code |
| `discipline-reviewer` | 6 Review | After an implementation chunk — confidence-gated P0–P3, report-only |
| `discipline-verifier` | 8 Verify | Before any "done/passing" claim — runs checks, reports real output |

Brainstorm (2) and QA (7) stay skills, not subagents — brainstorm is
interactive (HARD-GATE needs approval), QA is gstack's browser. Execute (5)
stays main-session with the TDD Iron Law.

**On-demand teams (experimental, enabled).** A team is *proposed and
confirmed*, never silently auto-spawned — the only honest "automatic", and it
respects the Scope Gate. Best fits: a parallel review team (2–3
`discipline-reviewer` with security / correctness / readability lenses) for
large diffs; a competing-hypothesis investigation team for hard bugs. On
win32/VSCode: in-process teammates only (no split panes, no session resume);
teammates inherit lead permissions, so no-auto-commit stays a lead-enforced
behavioral gate.

**Routing posture.** On a coding task: silently delegate to the right subagent
by scope (cheap, automatic). For a team, state the proposed structure and get a
one-word go-ahead first. Editing an agent file on disk needs a Claude Code
restart to take effect (or `/agents`).

---

*This document is the contract. Each repo's `.claude/discipline-adapter.md`
supplies only that repo's facts; this file supplies the process. When a skill
default conflicts with this document, this document and the user's explicit
instructions win, in that order below the user. Update it only when the process
itself changes — not for task notes, not for repo-specific facts (those live in
the adapter).*
