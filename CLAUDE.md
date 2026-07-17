# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

This is a **sales CRM module** for PayG SuperChat. It models the end-to-end funnel: **Leads → Qualified → Converted (Deal) → Won Deal → Merchant (PIMS) → Activated**. A companion **incentive engine** books payouts when merchants activate, and a **workflow automation engine** operationalizes the flow.

For high-level feature architecture and deployment, see **SALES_MODULE_README.md** (very detailed).

## Architecture

### Core data model
- **Leads**: prospects with contact info, scoring (AI + manual), and follow-up state.
- **Deals**: converted leads with pipeline stages (Negotiating → Closed Won); drag-drop Kanban.
- **Merchants** (PIMS): won deals; onboarding progress (KYC → Activated). When a deal moves to "Closed Won", a merchant is auto-created.
- **Incentives**: computed from active rules when a merchant activates; ledger tracks payouts (accrued → approved → paid).
- All owned by **employees** (`employees.id`); **admins & managers** see all, **sales execs** see only their own rows (row-level security).

### Key file groups

#### Shared bootstrap & helpers
- **`config/database.php`**: `getDB()` singleton, PDO config.
- **`api/sales/_bootstrap.php`**: DB, session, input, response helpers; **row-level scoping** (`visible_owner_ids()`, `owner_scope()`, `assert_can_see()`).
- ~~`includes/utils.php`~~, ~~`api/auth.php`~~, ~~`login-unified.php`~~: **removed**. Dead legacy-CRM code referencing `teams`/`sla_policies`/`departments`/`users`/`clients`/`agent_activity` tables that never existed in any migration or the live DB dump — confirmed unused by any Sales CRM file before deletion.
- **`includes/sales-layout.php`**: shared HTML shell (head, header, nav), `sales_guard()` auth + RBAC enforcement, page-title helpers.
- **`js/sales.js`**: fetch wrappers, toast/modal, AI insight placeholders, bulk-action state, command palette.

#### API endpoints (`api/sales/*.php`)
Each endpoint is standalone, requires `require_once '_bootstrap.php'` and typically calls `require_login()`.

**Core CRUD:**
- **`leads.php`**: list (with filters), single, create, bulk (status/owner/delete), PATCH update. Fires automations on create/update.
- **`deals.php`**: Kanban feed (stages + deals), single, create, PATCH. Moving to "Closed Won" triggers merchant creation.
- **`deal-move-stage.php`**: stage-change endpoint (separate from deals.php PATCH); auto-creates merchant, updates lead, fires automations.
- **`merchants.php`**: list, single, create, PATCH; KYC fields; activate endpoint (which triggers incentive booking).

**Conversion & follow-ups:**
- **`lead-convert.php`**: single transaction: lead → (account, contact, deal). Fires automations.
- **`followups.php`**: logs a follow-up (channel, outcome, feedback) and **rolls up onto the lead** (status, next_followup_at, lost_reason).

**Integrations & Analytics:**
- **`reports.php`**: aggregates (funnel, source ROI, monthly trend, cycle, agent perf, win rate). All rows visible (admin/manager); reps scoped.
- **`insight.php`**: **evidence-backed AI narrative**. Builds deterministic SQL aggregates + scope, then LLM interprets. Caches < 30min.
- **`ai.php`**: agentic AI (lead scoring, next-best-action, coaching, funnel insights). Calls your `/chat_v3` service; falls back to `ai-bridge.php` (OpenAI-compatible adapter).
- **`web-to-lead.php`**: **PUBLIC** lead capture (honeypot + 24h email dedup); fires on_create automations. CORS-open for form embeds.
- **`chat-lead.php`**: **PUBLIC** live-chat lead capture; source = 'live_chat'.

**Automation & Incentives:**
- **`automations.php`**: rule CRUD + logs + manual time-based trigger. Rules stored as `{entity, trigger, conditions, actions}` JSON.
- **`_automation_engine.php`**: evaluates conditions, executes actions (assign owner, update field, tag, task, notify, email).
- **`incentive-admin.php`**: plan/rule CRUD; preview dry-run of incentive calc for a hypothetical merchant.
- **`_incentive_engine.php`**: `incentive_calculate()` evaluates active rules; `incentive_book()` writes the ledger (idempotent).

**Settings & Admin:**
- **`email-settings.php`**: SMTP config (basic, Office 365, IMAP/POP); test-send. Password never returned to browser.
- **`whatsapp-settings.php`**: Business API config (provider, phone_id, token). Test-send.
- **`telephony-settings.php`**: Click-to-call (Exotel / TeleCMI). Provider-specific routing in `_telephony.php`.
- **`custom-fields.php`**: EAV definitions + values per entity. Define in admin, render auto in forms.
- **`ai-settings.php`**: on-prem box + cloud fallback routing, voice STT endpoint, health check.
- **`users.php`**: sales_users CRUD (login, role, employee link, active status).
- **`rbac.php`**: role_access matrix (admin/manager/sales_exec/hr/it per module).

#### Auth
- **`auth.php`**: login (verify against sales_users), logout, /me. Returns role-based redirect (admin → admin panel, manager → dashboard, sales_exec → leads). Seeds 4 sample accounts on first run (empty sales_users).

#### Integration webhooks (`api/integrations/*.php`)
- **`call-status.php`**: provider webhook (Exotel, TeleCMI) → logs call time.
- **`merchant-confirm.php`**: external system → confirms a merchant's KYC/onboarding state.
- **`transaction.php`**: transaction feed → updates merchant.actual_monthly_volume.
- **`_auth.php`**: shared integration bootstrap; API key via `X-API-Key` or `Authorization: Bearer`.

#### Frontend (`*.php` pages, `js/sales.js`, `client.html`)
- **`sales-dashboard.php`**: executive overview; KPIs, funnel, 6-month trend, per-module cards, live feeds, AI insights.
- **`sales-leads.php`**: rep workhorse; lead list (dense design), filters, metrics (funnel, source perf, leaderboard), add/convert/score actions. My Tasks sidebar.
- **`sales-pipeline.php`**: drag-drop Kanban; stages + deals. Per-card AI next-best-action.
- **`sales-merchants.php`**: PIMS list, KYC fields, activate, incentive preview.
- **`sales-incentives.php`**: ledger, leaderboard, AI coaching, approve/pay, bulk payout.
- **`sales-reports.php`**: Chart.js dashboards; source ROI, agent perf, cycle time, win rate.
- **`sales-chat.php`**: live-chat operator console (waiting queue, pick up, message).
- **`sales-conversations.php`**: admin/manager monitor; live chats, calls, WhatsApp (24h).
- **`sales-automation.php`**: visual rule builder (conditions + actions dropdowns); activity log.
- **`sales-settings.php`**: email, WhatsApp, telephony, mailboxes, AI/voice, custom fields, web-to-lead snippet + QR gen.
- **`sales-admin.php`**: admin-only; incentive plans/rules + live preview, user management, RBAC matrix.
- **`sales-login.php`**: login form; sample account buttons for dev.
- **`sales-timesheet.php`**: daily log (activity type + hours); AI-fill from that day's CRM work; voice dictation; manager approval.
- **`sales-users.php`** (if exists): user list + invite; also in admin.php.
- **`create-users.php`**: one-shot seed (employees + sales_users + role_access). Change demo passwords, run once, then delete.

#### Cron & setup
- **`cron/run-sales-automations.php`**: time-based rule sweep. Schedule: `*/30 * * * *` (every 30 min) or adjust.
- **`sales-setup.php`**: one-time installer; applies migrations, seeds demo data, health-checks. Delete after run.

## Row-level security pattern (critical)

**The principle**: admins & managers **see/edit everything**; a **sales exec sees ONLY their own** records (leads, deals, merchants).

**Enforcement**:
1. **Reads** (`_bootstrap.php`):
   - `visible_owner_ids()`: returns `null` (unrestricted) if current_role is admin/manager; otherwise `[current_employee_id()]`.
   - `owner_scope($col, &$args)`: builds `$col IN (?,?)...` fragment for parameterized WHERE, appends to $args; returns '1' if unrestricted.
   - `owner_scope_inline($col)`: same, but for paramless ->query() strings (intval-safe).
   - `assert_can_see($ownerId)`: guards single-record fetches; 403 if the user can't see that owner.

2. **Writes** — verified current (2026-07-03 audit): `leads.php`, `deals.php`, `merchants.php`, `followups.php` PATCH/POST/bulk paths all correctly call `assert_can_see()`/`owner_scope()`/`assert_can_assign_owner()` before mutating. The previously-noted gap (PATCH/bulk endpoints skipping the ownership check) has been fixed in this codebase — no longer an open issue in these four files.
   - **Fix pattern** (for any *new* endpoint you add): call `assert_can_see($id)` immediately after fetching the old row in any write path, before mutation.

3. **Metrics & aggregates**:
   - `reports.php`: no owner_scope (admin/manager only — all visible by role check).
   - `leads.php?metrics=1`: calls `owner_scope_inline()` on list, funnel, leaderboard queries to scope them.

## Authorization & integration tokens

- **User auth**: `$_SESSION['user']` set by `auth.php`. Current user via `current_employee_id()`, `current_role()`.
- **Integration API tokens**: `integration_tokens` table (token, scopes, is_active, last_used_at). Authenticated by `integration_auth()` in `api/integrations/_auth.php`.
  - **Scope check**: verified current — already uses `explode()` on scopes + exact `in_array(..., true)` membership, not substring matching. Fixed.

## Automation & incentive engines (non-blocking, fault-tolerant)

### Automation engine (`api/sales/_automation_engine.php`)
- Entry: `run_automations(PDO $db, $entity, $event, $record)`.
- Evaluates active rules WHERE `(entity, trigger_event)` match and conditions pass (all/any logic).
- Executes actions: assign_owner (round-robin or specific), update_field, add_tag, create_task, notify, send_email.
- **Non-fatal**: wraps in try-catch; errors logged, never thrown back to the caller (automation must not break CRUD).
- Called from: `leads.php` POST/PATCH, `lead-convert.php`, `deal-move-stage.php`, `merchants.php` activation. Also cron.

### Incentive engine (`api/sales/_incentive_engine.php`)
- Entry: `incentive_calculate()` — evaluates all active plans/rules, returns `{total, lines[]}`.
- Rule types: flat, percent (of deal), percent_volume, slab_volume, per_product.
- **Idempotent**: `incentive_book()` checks if a merchant already has ledger rows; if yes, skips (no double-booking on re-runs).
- Called from: `merchants.php` when activated. Also previewed in `incentive-admin.php?preview=1`.

## AI/LLM integration

All AI calls route through `_ai.php` → `crm_ai_call()`:
1. Try on-prem box (if enabled + /health OK).
2. Fall back to cloud.
3. Fall back to local `ai-bridge.php` (translates `/chat_v3` contract to OpenAI-compatible `/v1/chat/completions`).

**Endpoints called**: `/chat_v3`, `/summarize`, `/translate`.

**AI features**:
- **Lead scoring** (`score_lead`): AI score (0-100) + rating (hot/warm/cold) written back to `leads.score`/`rating`.
- **Next-best-action** (per deal card in Kanban): coach message.
- **Funnel insights** (dashboard): executive summary.
- **Agent coaching** (incentive leaderboard): per-agent strengths/gaps.
- **AI-fill timesheet**: transcribe voice → struct entry (activity, hours, description) + merchant link.
- **Task phrasing** (`_task_events.php`): system events auto-create AI-written task titles.
- **Document Q&A** (`doc-ai.php`): merchant docs → embeddings (`/document/upload`) → ask/summarize.
- **Email triage** (`mailbox.php`): incoming emails → category + suggested action + draft reply.

**Caching**: insights cached in `ai_insights` (< 30 min served from cache; **Refresh** button forces re-call).

## Database and migrations

All schema in `/database/migrations/phase*.sql` (phase1 → phase13+), but the authoritative live source is now `database/payg_sales_crm.sql` (44-table mysqldump). Run via `sales-setup.php` once, or manually:
```sql
mysql super_payg_crm < database/migrations/phase1_sales_crm.sql
mysql super_payg_crm < database/migrations/phase2_incentives_pims.sql
... etc
```

**Key tables**: leads, deals, merchants, accounts, contacts, pipelines, deal_stages, incentive_plans/rules/ledger, automation_rules/logs, sales_users, activities, email_settings, whatsapp_settings, telephony_settings, email_mailboxes, ai_documents, ai_insights, ai_settings, custom_fields/values, tasks, timesheets, call_logs, chat_sessions/messages, notifications, inbox_emails, integration_tokens, role_access, assignment_state, employees.

Note: `sla_policies`/`teams`/`departments`/`users`/`clients` referenced by the legacy `api/auth.php`/`includes/utils.php` (now removed) never existed in any migration or the live dump — that subsystem was dead code, not a real gap.

## Development setup

### One-time setup
1. Copy all files into your web root (path: `/payg/`).
2. Run `php create-users.php` once (creates employees + sales_users + role_access seeding).
3. Browse `/payg/sales-setup.php` once (applies migrations, seeds demo leads, health-checks AI).
4. Delete `sales-setup.php` after.

### Dev server
```bash
# From the project root
php -S 127.0.0.1:8000
# or use Apache/nginx if you have it running (better for session parallelism)
```

### Testing logins
**Sample accounts** (auto-seeded):
- `admin` / `admin123` → Admin panel
- `manager` / `manager123` → Dashboard
- `asha` / `asha123` → Sales exec (leads, pipeline, merchants)
- `ravi` / `ravi123` → Sales exec

Test via `/payg/sales-login.php` or click-buttons (dev convenience only — change in prod).

### Key config values
Set in `config/config.php`:
```php
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'super_payg_crm');
define('DB_USER', 'root');
define('DB_PASS', '');

define('SERVICE_BASE_URL', 'http://10.215.72.140:8000');     // AI box IP
define('SERVICE_CHAT_ENDPOINT', 'http://127.0.0.1:8000/chat_v3');

define('VOICE_API_URL', 'https://167.235.90.27/voice/transcribe');
define('VOICE_API_TOKEN', '<your-key>');

// WhatsApp (set in UI also)
define('WHATSAPP_ENABLED', true);
define('WHATSAPP_PHONE_ID', '...');
define('WHATSAPP_API_KEY', '...');
```

## Common workflows

### Adding a new field to leads
1. Add column to `leads` table (schema migration or raw ALTER).
2. Add to `$allowed` whitelist in `leads.php` PATCH handler (line 208).
3. Add form input to sales-leads.php modal (JavaScript).
4. If it should trigger automations, add to automation condition operators (in `_automation_engine.php`).

### Adding a new automation action
1. Define the action type in the rule editor UI (sales-automation.php).
2. Add a `case` in `automation_execute()` (`_automation_engine.php`, line 97+).
3. Handle action-specific fields (e.g., if assigning owner, call `automation_round_robin()`).

### Integrating a new third-party API
1. Create `api/integrations/<service>.php`; start with `require_once '_auth.php'` and call `integration_auth()`.
2. Define scopes (e.g., 'merchant-confirm', 'transaction', 'call-status').
3. Create rows in `integration_tokens` with the scope string.
4. Webhook receiver in your endpoint; call the handler.

### AI service is offline / not reachable
- The UI shows **"AI box is DOWN"** in the health badge.
- Pages still work in **evidence-only mode** (show raw numbers, no narrative).
- Use `ai-bridge.php` to point to an external OpenAI-compatible model (`LLM_URL` env var).
- Set `SERVICE_CHAT_ENDPOINT='http://127.0.0.1:8000/ai-bridge.php'` in config.

### Running time-based automations
```bash
# Cron (run every 30 min)
*/30 * * * * php /path/payg/cron/run-sales-automations.php

# Or manually from the UI (sales-automation.php → "Run time-based now")
```

## Code quality & review notes

**Security concerns** (verified 2026-07-03; #1 and #2 previously flagged here are fixed as of this audit):
1. ~~PATCH/bulk write endpoints lack owner-scope checks~~ — fixed, all core endpoints call `assert_can_see($id)` before UPDATE/DELETE.
2. ~~Integration scope check uses substring match~~ — fixed, uses explode + in_array.
3. **Deal creation dereferences a possibly-empty stage lookup** without null-check — `deals.php` POST does check `if (!$stage) fail(...)`; verify this still holds if pipeline/stage config changes.
4. **Incentive approve/pay/leaderboard/monthly endpoints previously had no role check** (`incentives.php`) — a `sales_exec` could approve/pay any ledger row, including their own, and view other agents' earnings. **Fixed 2026-07-03**: approve/pay/bulk/leaderboard/monthly now require `can_manage_all_records()`; the ledger list is forced to the caller's own `employee_id` for non-managers. Verified live (403 for sales_exec, 200 with own-only data).
5. **Timesheet manager-approval action had no manager/subordinate check** (`timesheet.php` PATCH) — any logged-in user could approve/reject any timesheet by id. **Fixed 2026-07-03**: now requires `can_manage_all_records()` plus verifies the target row's `employees.manager_id` matches the caller (or caller is admin). Verified live (403 for sales_exec).
6. **`automations.php` had zero role check** — any logged-in user could create/edit/delete workflow rules (which reassign owners, send emails) and trigger the full time-based sweep. **Fixed 2026-07-03**: whole file now requires `can_manage_all_records()`. Verified live.
7. **`reports.php` had zero role check** despite being company-wide/unscoped (agent performance + incentive figures for everyone). **Fixed 2026-07-03**: requires `can_manage_all_records()`. Verified live.
8. **`custom-fields.php` definitions (create/edit/delete) had zero role check** — any user could add/remove fields on every form. **Fixed 2026-07-03**: definition writes now require `can_manage_all_records()`; reading definitions and saving per-record values remain open to any logged-in user (needed for the lead/deal/merchant forms to render and save).
9. **`doc-ai.php` list/ask/summary/delete had no ownership check** — any user could read another agent's uploaded merchant/lead documents (PAN/GST/KYC-grade data) by passing an arbitrary id. **Fixed 2026-07-03**: added `assert_can_see()` on the related merchant/lead owner for list, upload, ask/summary, and delete.
10. **`ai.php`'s `funnel_insights` and `insight.php`'s evidence builders had no role check** despite being company-wide aggregates (including per-agent incentive figures / top-earner name+amount). **Fixed 2026-07-03**: both require `can_manage_all_records()`.

All fixes verified 2026-07-03 against a live local server + real DB dump: logged in as `sales_exec` (asha) and confirmed 403 on every restricted action, confirmed row-level scoping forces `?employee=`/`?id=` params back to the caller's own data rather than erroring silently, and confirmed `manager` retains full access with no regression.

**Common patterns**:
- **Parameterized queries everywhere**: use `->prepare()` and `->execute()` with placeholders, never string concat.
- **JSON encode/decode carefully**: `json_decode($raw, true) ?: []` to handle invalid JSON.
- **Error handling is non-fatal**: automations/incentives wrap in try-catch and log rather than throw (they run during CRUD).
- **Idempotency checks**: `incentive_book()` checks for existing ledger rows before inserting (safe to re-run).

## Files to read first

1. **SALES_MODULE_README.md** — full feature list and deployment.
2. **`api/sales/_bootstrap.php`** — the scoping pattern that gates all reads (and should gate all writes).
3. **`api/sales/leads.php`** — a representative endpoint; shows filters, metrics, bulk, CRUD.
4. **`api/sales/_automation_engine.php`** — how rules are evaluated and executed (non-fatal, token-aware).
5. **`includes/sales-layout.php`** — shell, nav, RBAC enforcement, page-title helpers.

## Tips for future work

- **Always enforce row-level scope on writes**, not just reads. Call `assert_can_see()` in PATCH/DELETE handlers.
- **Test under Apache/nginx, not `php -S`** when working with sessions, AI parallelism, or long-running endpoints (dev server is single-threaded).
- **AI calls are not-blocking**: `insight.php` and `ai.php` call `session_write_close()` early so other requests can run in parallel.
- **Caching is time-based**: AI insights cache for 30 min; the UI shows age ("cached · as of HH:MM" vs "live · just now").
- **Automations are fault-tolerant**: errors don't throw; they log and the CRUD continues. Check logs if an automation doesn't fire.
- **Evidence-backed AI**: every AI feature surfaces raw SQL aggregates so claims are auditable. The LLM only interprets, never invents numbers.
