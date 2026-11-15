# Agents & Workflows — audit and implementation matrix

Updated in 0.126.0 (phase 1 only). Status words: DONE = code + automated test run here; PARTIAL; MISSING.

## Existing foundation (reused, not rebuilt)
| Area | Where |
|---|---|
| Workflow engine, runs, approvals, per-run credit cap | `app/Workflow/*`, `phase*` wf_* tables |
| Tools + risk levels (read / write_own / external / money) | `app/Workflow/ToolRegistry.php`, `Risk.php`, `Tools/*` |
| Desktop tools (pc.*) and pairing | `app/Workflow/Tools/Pc*`, `app/Pc/*` |
| Credits, model choice, plan entitlements | `CreditLedger`, `ModelPicker`, `effective_feature()` |
| Workflow editor | JSON textarea only (no visual builder) |

## Phase 1 matrix
| Requirement | Status | Files | Evidence |
|---|---|---|---|
| Agent tables, RLS, versions, usage log | DONE | `database/sql/phase32b.*.sql`, migration `2027_01_01_100000_wf_agents.php` | `tests/db/test_wf_agents.py` 22 pass |
| Plan limit (SaaS) | DONE (agents only) | `wf_agent_limit`, feature `agents.max` | DB tests |
| Model selection validated against plan | DONE | `AgentService::test` -> `ModelPicker` | unit test (fake picker); real catalog picker NOT run |
| Prompt, tools, knowledge/memory flags, permissions | DONE (stored, shown) | `AgentSpec`, `form.blade.php` | unit tests |
| Knowledge / memory actually used at run time | MISSING | — | flags are stored only |
| Test run with credit safety | DONE | `AgentService::test` | 8 unit tests incl. release on failure |
| Version history + restore | DONE | `PgAgentRepo`, form view | DB tests; UI not rendered |
| Money tools forbidden; external/desktop need permission | DONE | `AgentSpec` | unit tests |
| Cross-user / cross-workspace isolation | DONE at DB | RLS + owner checks | DB tests |
| Agent UI (Hybrid Premium hero) | PARTIAL | `resources/views/agents/*` | Blade NOT rendered here; look unverified |
| Agents inside workflows, multi-agent handoff | MISSING (phase 3) | — | — |
| Visual drag-and-drop workflow builder | MISSING (phase 2) | — | — |
| Triggers (schedule/webhook/event), loops | MISSING (phase 2) | — | — |
| Templates, analytics dashboard, workflow limits | MISSING (phase 4) | usage numbers exist on index page | — |

## Environment limits
Pest/Laravel vendor unavailable (Packagist blocked): PHP tests run on `tests/tools/mini_pest.php`; Blade, routes and middleware are not executed. Real model calls, the VPS and real browsers were not exercised.

---

# Workflow management update (0.127.0)

## Audit of the existing code (before changing anything)
| Finding | Where | What was done |
|---|---|---|
| 13 templates are defined in code and copied into the person's own list; the copy kept NO link to the template | `app/Workflow/Templates.php`, `WorkflowService::fromTemplate` | Added `wf_workflows.template_key` (set once by `wf_mark_template`). Old rows have NULL; nothing depended on a template key, so nothing breaks. |
| A workflow's identity is its `id`: the daily job (`wf_due`) reads `schedule_time` + `last_scheduled_on` of that row; runs hold `workflow_id` | `phase21a.up.sql` | Rename is a dedicated function that updates only `name`, `description`, `updated_at`. Tested: id, version, steps, schedule, `last_scheduled_on`, template, history unchanged; the schedule still fires after a rename. |
| Editing was a JSON textarea; the only "edit" path (`wf_save`) rewrote every field | `edit.blade.php`, `WorkflowController::save` | Kept as "Advanced (JSON)". New narrow functions change one thing each (name / settings / steps / state). |
| No pause, no duplicate, no per-workflow history, no test mode, no search | — | Added (see matrix). |
| The AI step always used the automatic model | `CreditedAiStep` | Optional `model_id` per workflow; the picker still checks the plan, so a model the plan does not allow falls back to automatic. |
| Desktop tools (`pc.*`) only FILE a request: device rules + the person's approval decide; a workflow can never approve its own request | `Tools/PcActionTool.php`, `pc_request` | Unchanged and not bypassed. Test mode never sends them. The detail page shows paired computers, the mode and which desktop tools a workflow uses. |
| Voice and the MCP/public API address workflows by id and show the name | `WorkflowVoiceActions`, `LaravelMcpBackend` | Unaffected by a rename (only the displayed name changes). |
| Bugs found on the way | see CHANGELOG 0.127.0 | `phase32a` file-name clash (Skills SQL had been overwritten by Agents SQL) fixed; `agents.*` route-name clash fixed; a closure in the runner missed `$test` (caught by its test). |

## Requirements matrix
| # | Requirement | Status | Files | Evidence |
|---|---|---|---|---|
| 1 | Rename after adding to "My Workflows" | DONE | `wf_rename`, `WorkflowService::rename`, `show.blade.php` | DB: rename keeps id/version/steps/schedule/last_scheduled_on/template/history, schedule still fires; PHP: validation, owner-only |
| 2 | Edit name, description, AI prompt, schedule, steps, model, tools | DONE | `wf_set_meta`, `wf_set_definition`, builder inspector, settings form | DB + PHP unit + browser (prompt, max tokens, tool + arguments, connections) |
| 3 | Run, Pause, Enable/Disable, Duplicate, Delete, Execution history | DONE | `wf_set_state`, `wf_duplicate`, `runsFor`, list + detail | DB: paused skipped by schedule but runnable by hand; disabled cannot run; duplicate is OFF / unscheduled / limit 50 |
| 4 | Categories: Productivity, Marketing, Communication, Knowledge, Desktop AI, Development | DONE | `Templates::GROUPS/GROUP_OF`, `WorkflowCatalog` | every template in one group, every group non-empty; old categories map onto the six (stored values are not rewritten) |
| 5 | Search, filters, status indicators | DONE | `WorkflowCatalog`, `WorkflowService::cards`, `index.blade.php` | PHP unit (status rules, filters); page rendered with real data. Search/filters are server-side GET forms |
| 6 | Detail page + visual builder | DONE (see limits) | `show.blade.php`, `public/js/workflow-builder.js` | 10 node tests + 13 headless-Chrome tests (palette drag/click, node drag, connectors, inspector, relink on delete, XSS, save blocking) |
| 7 | Custom workflow from scratch | DONE | `WorkflowService::createBlank` | PHP: starter definition passes `WorkflowDefinition::validate` |
| 8 | Test mode with step-by-step logs | DONE | `wf_test_run_create`, `WorkflowRunner` | PHP: look-only tools run; writers / senders / `pc.*` / approvals / waits are skipped and logged; AI real or simulated; no failure notification |
| 9 | Desktop Agent only via device permissions + approvals | DONE (unchanged path) | `PcActionTool`, `PcService`, detail-page panel | existing `WorkflowExtrasTest` for the tools; test mode skips them; NOT verified against a real Windows agent |
| 10 | Keep existing behaviour + Hybrid Premium design | PARTIAL | hero header kept; builder uses a dark purple/blue palette | 13 originals still validate; the original run/template/decide/cancel/JSON routes kept. The shared Hybrid Premium CSS is not in this repository, so pages rely on the same classes the other pages use |
| 11 | Audit first | DONE | this document | — |
| 12 | Real backend, DB, validation, permissions, tests; no dead buttons | DONE | all of the above | every button posts to a route that exists (route-name test) and to a function that is tested |

## Limits / not done (honest)
- The builder canvas has no free-form "drag a wire from port to port"; connections are chosen in a dropdown in the inspector (and drawn as lines). Drag-and-drop is for adding steps (palette → canvas) and moving them.
- `parallel` steps are shown and kept intact but not editable visually (Advanced JSON).
- Loops, schedule types other than "daily at HH:MM", webhook/event triggers: not implemented (the engine has none; no placeholder UI was added).
- Test mode with the real AI costs credits (up to the workflow's limit); the default is the free simulated AI.
- The workflow list needs a page reload to show a run's progress (as before).
- Not run against a live Laravel/PostgreSQL stack: PHP tests use the mini runner (`tests/tools/mini_pest.php`), pages were rendered with Laravel's real Blade compiler but stub helpers (route/asset/csrf/session), and `<x-layouts.app>` was replaced by a plain element.
- 9 tests in `WorkflowTest.php` and 11 in `WorkflowExtrasTest.php` fail under the mini runner both before and after this change (stale English/Bengali message expectations; see `docs/PHP_STALE_TESTS.md`).
