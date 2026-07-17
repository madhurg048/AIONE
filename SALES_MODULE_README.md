# PayG Sales CRM Module (Lead → Conversion → Merchant)

A drop-in sales CRM bolted onto the existing PayG SuperChat CRM. Reuses the same
stack (PHP + MySQL `super_payg_crm`, `getDB()` PDO, `$_SESSION['user']` auth), the same
look & feel (Tailwind/Font Awesome/Flatpickr, indigo-600), and the same agentic AI
service (`/chat_v3`) already used by `ai-summary.php`.

## The funnel
```
LEAD  ──convert──►  ACCOUNT + CONTACT + DEAL  ──drag to "Closed Won"──►  MERCHANT (PIMS)
 │  metrics, AI score            pipeline, AI next-best-action          │  KYC → Activate
 │                                                                       ▼
 └───────────────────────────────────────────────────►  INCENTIVE booked to agent
                                                          (accrued → approved → paid)
```

## Files added
| File | Purpose |
|---|---|
| `database/migrations/phase1_sales_crm.sql` | leads, accounts, contacts, pipelines, deal_stages, deals, activities |
| `database/migrations/phase2_incentives_pims.sql` | merchants (PIMS), merchant_documents, incentive_plans/rules/ledger, sales_targets, ai_insights |
| `includes/sales-layout.php` | shared head/header/nav (your exact look & feel) + `sales_guard()` |
| `js/sales.js` | shared fetch/toast/modal/AI helpers |
| `api/sales/_bootstrap.php` | DB, JSON, session, input helpers |
| `api/sales/_incentive_engine.php` | rule evaluation + ledger booking |
| `api/sales/leads.php` | leads CRUD + **metrics** (funnel, source perf, agent leaderboard) |
| `api/sales/lead-convert.php` | lead → account+contact+deal (transaction) |
| `api/sales/deals.php` | deals CRUD + Kanban feed |
| `api/sales/deal-move-stage.php` | move stage; **Closed Won auto-creates merchant** |
| `api/sales/merchants.php` | PIMS CRUD, KYC, **activate → books incentive** |
| `api/sales/incentives.php` | ledger, summary, leaderboard, approve/pay, bulk payout |
| `api/sales/ai.php` | agentic AI: score_lead, next_best_action, coach_agent, funnel_insights |
| `api/sales/insight.php` | **evidence-backed** page AI: builds SQL evidence pack per scope, LLM interprets only |
| `api/sales/reports.php` | analytics aggregates (funnel, monthly, source ROI, win rate, cycle, agents) |
| `sales-reports.php` | **Reports & Analytics** — Chart.js dashboards (Zoho-parity gap-closer) |
| `database/migrations/phase3_automation_customfields.sql` | automation_rules/logs, assignment_state, custom_fields/values, email templates, web_lead_sources |
| `api/sales/_automation_engine.php` | **workflow engine** — conditions + actions (assign/notify/email/update/tag/task) |
| `api/sales/automations.php` | rule CRUD + logs + manual time-based trigger |
| `api/sales/custom-fields.php` | custom-field definitions + EAV values |
| `api/sales/web-to-lead.php` | **public** inbound lead capture (fires automations) |
| `cron/run-sales-automations.php` | time-based rule sweep (cron every 15-60 min) |
| `sales-automation.php` | **workflow rule builder** + activity log |
| `sales-settings.php` | **custom-field manager** + web-to-lead snippet |
| `web-to-lead-form.html` | embeddable sample capture form (honeypot-protected) |
| `sales-dashboard.php` | funnel + KPIs + **AI insights panel** |
| `sales-leads.php` | **Lead metrics** + table + add/convert/AI-score |
| `sales-pipeline.php` | drag-drop **Kanban** + AI next-best-action |
| `sales-merchants.php` | **PIMS** list/detail, KYC, activate |
| `sales-incentives.php` | **incentive management** ledger + leaderboard + AI coaching |
| `sales-setup.php` | one-shot installer + health check (delete after use) |

## Install
1. Copy all the files above into your CRM web root (keeping folders).
2. Browse to `/payg/sales-setup.php` once — it applies both migrations and seeds demo leads.
3. Open `/payg/sales-dashboard.php`. Delete `sales-setup.php`.

> Prereq: an `employees` table with `id`, `full_name` (already in your DB). Ownership
> uses `employees.id`. Adjust `sales_guard()` role allow-list in `includes/sales-layout.php`
> to your role model (defaults to manager/employee/admin/department).

## Where AI is wired (agentic system + dashboards)
All four call your existing `/chat_v3` service via `api/sales/ai.php`, gated by
`serviceIsUp()`, and cache results in `ai_insights`:

1. **Lead scoring** (`score_lead`) — 0-100 fit score + hot/warm/cold band + next best action, written back to `leads.score`/`rating` so the table sorts by AI priority.
2. **Deal next-best-action** (`next_best_action`) — per-card coach: actions, main risk, talk-track, on the Kanban.
3. **Agent coaching** (`coach_agent`) — turns each agent's funnel numbers into strengths/gaps/coaching on the incentive leaderboard → **direct sales-performance tracking**.
4. **Funnel insights** (`funnel_insights`) — executive read (headline/risks/recommendations) on the dashboard.

### Evidence-backed page insights (the trust model)
Every main page carries a one-line `<div data-ai-scope="leads|pipeline|merchants|incentives|dashboard"></div>`
strip. `api/sales/insight.php` computes a deterministic **evidence pack** (real SQL
aggregates) and the LLM only *interprets* it into `summary / key_findings / decisions /
watch` — it is told never to invent numbers. The UI shows an **Evidence** toggle with the
raw figures, so every claim is auditable. If the AI service is offline, the strip degrades
to evidence-only (numbers still live).

### Recommended next AI hooks (not yet built)
- **Auto-score on lead create** — call `score_lead` from `leads.php` POST so every new lead lands pre-prioritised.
- **Screen-pop enrichment** — when a WhatsApp/call lead arrives (existing channels), auto-create a lead + AI score, tying the support agentic system to sales.
- **Churn/again-propensity** on activated merchants using PIMS volume trends.
- **Manager digest** — a cron that runs `funnel_insights` + `coach_agent` for the team and posts to the multi-role dashboard/notifications you already have.

## Workflow automation (operationalises the AI insights)
`api/sales/_automation_engine.php` runs on lead create/update, deal stage-change,
merchant create, and on a time-based cron. Rules are `{entity, trigger, conditions, actions}`:
- **Conditions** ops: `eq ne gt lt contains in is_empty not_empty older_than_days`, matched `all`/`any`.
- **Actions**: `assign_owner` (round-robin or specific), `update_field`, `add_tag`, `create_task`, `notify` (in-app), `send_email` (via your `sendCrmEmail`), all token-aware (`{{first_name}}`).
- Seeded rules: round-robin assign new leads · welcome email to web leads · reassign leads stale >14d · escalate KYC stuck >7d. Manage them in **sales-automation.php**.
- Cron: `*/30 * * * * php /path/payg/cron/run-sales-automations.php` (or click "Run time-based now").

## Custom fields (Zoho-style, no schema change)
Define fields per entity in **sales-settings.php** (`custom_fields` + `custom_field_values` EAV).
They render automatically in the New-Lead modal and save via `custom-fields.php?values=1`.

## Web-to-Lead
`api/sales/web-to-lead.php` is a public endpoint; `web-to-lead-form.html` is a ready sample.
Honeypot + 24h email de-dup built in. Every submission fires the on_create automations
(so assignment + welcome email are automatic). Tighten `Access-Control-Allow-Origin` in prod.

## Incentive rules (edit in DB, no code change)
`incentive_plans` + `incentive_rules` drive payouts. Seeded example:
- Flat ₹500 per activated merchant, **plus** a volume slab (₹250 / ₹750 / ₹2000).
Add `percent` rules (% of deal amount) or more slabs by inserting rows. The engine
(`_incentive_engine.php`) evaluates all active rules and is idempotent per merchant.

## "AI service offline" — wiring the brain
The AI panels call `SERVICE_CHAT_ENDPOINT` (`/chat_v3` contract: POST `{user_input}` → `{response}`),
gated by `serviceIsUp()`. If it shows **offline**, the page still works (evidence-only mode) — but to
get the narrative you need a reachable LLM:

1. **Your internal service** (`config/config.php` → `SERVICE_CHAT_ENDPOINT`, default
   `http://10.215.72.140:8000/chat_v3`) must be **up and reachable from the CRM server**. That IP is a
   private address — it will look "offline" from any machine outside that network (e.g. a laptop/test box).
2. **Use any OpenAI-compatible model instead** (LM Studio, llama-server, vLLM, Ollama `/v1`, a cloud model):
   drop in the included **`ai-bridge.php`** adapter (it translates the `/chat_v3` contract to
   `/v1/chat/completions`) and point config at it:
   ```php
   define('SERVICE_BASE_URL', 'http://<llm-host>:<port>');                 // for the health check
   define('SERVICE_CHAT_ENDPOINT', 'http://<crm-host>/ai-bridge.php');     // the adapter
   ```
   Set the target model via env `LLM_URL` (default `http://127.0.0.1:8090/v1/chat/completions`).
   > Note: don't run the adapter on the *same* single-threaded `php -S` dev server that calls it (it will
   > deadlock). Under Apache/nginx (production) or a separate port this is a non-issue.

Verified live against a local **Qwen3-Coder-30B** (OpenAI-compatible): insights returned in ~13s and
correctly cited the real figures from the evidence pack.

## My Tasks & delegation (phase 4)
A **My Tasks** button sits on the right of the top bar on every page (with an open-count badge).
It opens a slide-over panel with two tabs: **Assigned to me** and **I assigned**.
- Managers click **Assign** to delegate a task (e.g. *KYC Validation*, Follow-up, Document Collection…)
  to a subordinate; it can be linked to a merchant/lead/deal for context.
- The task instantly appears in the subordinate's **My Tasks** panel + badge, and a `task_assigned`
  notification row is created. The assignee can Start / Mark done; the manager tracks status under **I assigned**.
- Who you can assign to = your **subordinates** (`employees.manager_id = you`); if no hierarchy is
  configured it falls back to all other employees. Table: `tasks`. Migration: `phase4_tasks.sql`
  (also adds `employees.manager_id`). Verified live: manager→subordinate assign, badge, status, notify.

## Lead follow-up process + WhatsApp (phase 5)
On the Leads screen each row now has **Follow-up** and **WhatsApp** actions.
- **Follow-up** (`followups.php`, table `lead_followups`): log a touchpoint — channel (call/whatsapp/
  email/meeting/visit), outcome (connected / interested / not_interested / callback_requested /
  converted / …), the **lead's feedback**, and schedule the **next follow-up**. It rolls up onto the
  lead: `last_contacted_at`, `followup_count`, `next_followup_at`, and the **final status**
  (auto-derived from outcome, or set explicitly; `lost_reason` captured when not interested). Full
  history shows in the modal. Due queue: `GET followups.php?due=1[&mine=1]`.
- **WhatsApp** (`whatsapp-send.php`): message the lead from this screen. Integrates with your existing
  `config/whatsapp.php` — if `WHATSAPP_ENABLED=true` it sends via the Business/Cloud API
  (`{base_url}/{phone_id}/messages`, Bearer `api_key`); otherwise it returns a **wa.me click-to-chat
  link** (works today, no approval needed). Every send is logged to `whatsapp_messages` and as a
  `whatsapp` follow-up on the lead. Phone auto-normalised to +91 for 10-digit numbers.
  Migration: `phase5_lead_followups.sql`. Verified live (follow-up → auto status, due queue, wa.me link + logging).

### Follow-up loop — now surfaced everywhere (integration)
The follow-up process is closed end-to-end, not just logged:
- **Leads screen**: a **Next Follow-up** column (overdue shown red) + a **"Due (N)"** filter chip
  (`leads.php?due=1`); the leads metric returns `due_followups`.
- **Dashboard**: a **Follow-ups Due** KPI.
- **My Tasks panel** (every page): a **"Follow-ups due"** section for the logged-in owner
  (`followups.php?due=1&mine=1`), and the top-bar **badge counts open tasks + due follow-ups** together.
So a rep always sees what to chase today from the lead list, the dashboard, or the task panel.

## Dense UI redesign (Zoho-style)
The shell now uses a compact design system (in `includes/sales-layout.php`): base font 12px,
thin-bordered `.card` (not heavy shadow boxes), inline `.kstrip` KPI strips (replaces big KPI cards),
`table.dense` (sticky headers, tight rows, hover, row-select highlight), `.rowact` (row action buttons
at the end, revealed on hover), a `.bulkbar` for group actions, and `.pager` pagination.
- **Leads** is the flagship dense list: KPI strip, toolbar (search + status + source + Due), **checkbox
  column + bulk-action bar** (set status / assign owner / set follow-up date / delete via `leads.php?bulk=1`),
  **sortable columns** (`?sort=&dir=`), per-row actions, and a **pagination footer with page-size (20/50/100)**.
- **Merchants** and **Incentives** use the same kstrip + dense tables + pagination.
Outer padding tightened to `p-2` across all screens to conserve space and show more rows per view.

## AI summary caching (time-based)
The AI narrative (the expensive LLM call) is cached per scope in `ai_insights` as a full snapshot
(narrative + the evidence it was based on + `generated_at`). On page load, `insight.php` / `ai.php`
serve the cached read if it's **< 30 minutes old** (instant, no LLM); they only re-call the model when
**Refresh** is clicked (`force=1`) or the cache is older than 30 min. The panel shows a stamp —
"cached · as of HH:MM" vs "live · just now". Measured: cold ~12s, cached ~0.2s. Window: `AI_CACHE_MINUTES`.

## Single sign-on + roles (phase 6)
`sales-login.php` is a single login page; `api/sales/auth.php` verifies against `sales_users`
(auto-seeds 4 sample accounts on first run) and returns a **role-based redirect**:
admin → `sales-admin.php`, manager → `sales-dashboard.php`, sales_exec → `sales-leads.php`.
Below the form are **click-to-login sample buttons** (admin/admin123, manager/manager123,
asha/asha123, ravi/ravi123) for testing. `sales_guard()` now redirects unauthenticated users to
`sales-login.php`; logout hits `auth.php?action=logout`. An **Admin** nav tab shows only for admins.

## Flexible incentive structure (admin panel)
`sales-admin.php` (admin-only) defines the incentive structure with no code:
- **Plans** — name, effective dates, active, and a **stack toggle** (sum all matching rules vs pay only the highest).
- **Rules** (any number per plan) — types: **flat per merchant · % of deal · % of monthly volume · volume slab · ₹ per enabled product**, each with optional **targeting filters** (product / category / business_type) and priority.
- **Live payout preview** — enter a hypothetical merchant (volume, deal, products, category, type) and see exactly what the current rules would pay, itemised. Backed by `incentive-admin.php` (+ `?preview` dry-run of the same engine that books real incentives). Migration: `phase6_users_incentive_admin.sql`.

## Layman-friendly automation builder + rich email editor
The automation **rule editor** no longer exposes JSON. It's a visual builder:
- **When these conditions match** — add rows of [field ▾][operator ▾][value]; field list adapts to the
  entity (lead/deal/merchant), enum fields (status, source, KYC…) show value dropdowns, operators use
  plain words ("is", "is not", "greater than", "older than (days)"…). No conditions = always run.
- **Then do these actions** — add rows; each action type reveals only its relevant fields
  (Assign owner → round-robin or a person picker; Create task → title + due days; Notify → owner/person +
  title + message; etc.).
- **Send email** action uses a **Quill rich-text editor** for the body (bold/italic/lists/links, with
  `{{first_name}}`/`{{company}}` tokens); subject is a plain field (email subjects are plain text).
  The engine sends HTML bodies as-is (with a stripped text fallback).
The builder serialises to the same `conditions`/`actions` JSON the API already stores — existing rules
open back into the visual editor.

## Reports fixes
- Reports page rebuilt to the dense design system (kstrip KPIs + `.card` + fixed-height chart containers
  with `maintainAspectRatio:false`) to match the rest of the app.
- **Source ROI "Leads vs Activated"** now attributes activated merchants via the lead→deal→merchant chain
  (`merchant.source_lead_id OR merchant.deal_id = lead.converted_deal_id`) instead of the often-null
  `source_lead_id`; `deal-move-stage.php` now also stamps `source_lead_id` when the merchant is created.
- **Agent Performance "Merchants vs Incentive"** is now a clear **dual-axis** chart (merchant bars on the
  left axis, incentive ₹ as a line on the right axis, with axis titles) so both series are visible despite
  very different scales.

## AI loads last / non-blocking
Two changes so the AI analysis never holds up the rest of the page:
1. **Front-end (js/sales.js):** the AI insight strip now shows a light placeholder immediately, and its
   fetch is deferred via `requestIdleCallback` so the page's own data (KPIs, tables) loads first and the
   AI loads **last**. (Previously the strip's fetch was issued before the page's own loads.)
2. **Back-end (insight.php, ai.php):** these slow, read-only endpoints call `session_write_close()` right
   after auth, releasing the PHP session lock before the ~12s LLM call. Under Apache/nginx + php-fpm this
   lets other page requests run **in parallel** instead of queuing behind the AI (PHP serialises requests
   that hold the same session lock). Note: the `php -S` dev server is single-threaded so it can't show the
   parallelism, but the front-end deferral fixes the perceived blocking there too.

## Phase 8 — Timesheets, AI/voice fill, approval, configurable RBAC
- **Timesheet** (`sales-timesheet.php`, `timesheet.php`, table `timesheets`): log hours per activity type
  against a day; **AI-fill** drafts entries from that day's real CRM activity (follow-ups/tasks/activities);
  **voice** dictation records audio → `voice-transcribe.php` → your Hetzner parakeet STT → text; **Submit**
  sends the day's drafts for **manager approval** (managers see subordinates' submitted entries and approve/reject).
- **Configurable RBAC** (`rbac.php`, table `role_access`): admin sets, via a matrix on the Admin page, which
  **modules each role** (admin/manager/sales_exec) may access. `sales_nav()` hides disallowed tabs AND
  `sales_guard()` hard-enforces it (direct URL to a disallowed module → redirect to dashboard). Seeded:
  admin=all, manager=most, sales_exec=field tools (dashboard/leads/pipeline/merchants/timesheet).

### Required config additions (add to your real config/config.php — NOT shipped)
```php
define('VOICE_API_URL',   'https://167.235.90.27/voice/transcribe'); // your parakeet STT endpoint
define('VOICE_API_TOKEN', '<bearer token>');                          // enables voice timesheet entry
```
Without the token, voice degrades gracefully to a "not configured" message (typing/AI-fill still work).
The voice endpoint path/response-field may need tuning to your STT's exact contract (it accepts transcript/text/result).

## Executive dashboard + UI consistency pass
- **Dashboard rebuilt** as a whole-system executive overview (`sales-dashboard.php` + one aggregation
  endpoint `overview.php`): a system-wide KPI strip (leads, conv%, open deals, pipeline ₹, merchants live,
  live volume, txns today, incentive/mo, follow-ups due, open tickets, timesheet pending), acquisition
  funnel, 6-month trend, per-module summary cards (each linking through), and live feeds (recent
  transactions, ITSM tickets, top agents). Uses the dense design system.
- **UI consistency**: all remaining pages (dashboard, automation, settings) migrated off the old
  `rounded-xl shadow-md` boxes to the compact `.card`/`kstrip` system. Every module screen now matches.

## Voice → structured timesheet + auto-tasks from system events
- **Voice parse** (`timesheet.php?parse=1`): the timesheet mic records → `voice-transcribe.php` (parakeet STT)
  → the transcript is sent to the LLM to produce ONE structured entry {activity_type, hours, description},
  and the CRM tries to **link a merchant/lead** named in the dictation. e.g. "spent 2 hours onboarding
  Patel Traders" → onboarding / 2h / linked to that merchant. Falls back to a raw entry if the LLM is down.
- **Event-driven tasks** (`_task_events.php`): system events auto-create tasks in the owner's My Tasks panel
  (de-duplicated): lead → **qualified** = "Prepare & send proposal"; ITSM ticket **waiting on us** = "Respond
  to ticket…" (auto-closes when the ticket resolves); PIMS **KYC pending / rejected / activated** = the matching
  onboarding task. Hooked into followups.php, leads.php, integrations/ticket-update.php, merchant-confirm.php.
  These tasks also enrich the activity data the AI timesheet-fill draws from — system data + AI → timesheet.

## Voice wired live + AI-phrased tasks
- **Voice STT** (`voice-transcribe.php`) confirmed against the live parakeet service: it now sends the
  RAW audio bytes (not multipart) to `{VOICE_API_URL}?lang=en` with `Authorization: Bearer <key>` and reads
  the `text` field. Set on your server:
  `define('VOICE_API_URL','https://<host>/voice/transcribe'); define('VOICE_API_TOKEN','<voice-app key>');`
  (the key is a gateway client key — keep it server-side, never in the repo). End-to-end verified:
  audio → proxy → parakeet → transcript → LLM → structured, merchant-linked timesheet entry.
- **AI-phrased tasks** (`_task_events.php` → `ai_task_phrase()`): the auto-created event tasks now get an
  LLM-written title + description from the situation (lead qualified / ITSM waiting-on-us / PIMS KYC-etc.),
  with a short timeout and deterministic fallback so events never block if the model is slow/down.

## Live-chat lead capture (source = live_chat)
- **`client.html`** — a sample client/marketing website with an embedded live-chat widget (bubble → intent
  chips → lead-capture form → chat). Drop-in demo; the widget POSTs to the CRM's public capture endpoint.
- **`api/sales/chat-lead.php`** — PUBLIC (CORS) capture endpoint. Creates a lead with **lead_source =
  'live_chat'**, stores the full chat transcript in the description, honeypot-protects against spam,
  de-dupes repeat chats within 6h, and fires on_create automations (round-robin assignment + welcome).
  Returns a friendly reference (LCxxxxxx). Leads then appear in the CRM Leads list with source "live_chat"
  and are tracked in Source Performance / Source ROI. Verified end-to-end in a real browser.

## Full-system test
`fulltest.sh` (repo root of the test env) exercises 59 checks across auth, leads, follow-ups, convert,
pipeline, merchants, incentives, reports, overview, automation, tasks, timesheet (+voice parse),
integrations (+bad-key 401), RBAC (+enforcement), AI insight, and every page render. Result: 58/59 pass
(the one miss was a transient LLM timeout under the single-threaded dev server; verified working in isolation).

## Row-level security, multi-department users, bug fixes, consistent formatting
- **Per-user data visibility** (`_bootstrap.php`: `visible_owner_ids/owner_scope/assert_can_see`): admins &
  managers see everything; a **sales rep sees ONLY their own** leads, deals, merchants — enforced on list
  queries, metrics, the dashboard **overview**, AND single-record fetches (opening someone else's record → 403).
- **Cross-department users & roles**: `create-users.php` extends the role set to **hr** and **it**, seeds
  employees + logins (hr/hr123, it/it123, rohan/rohan123) and their module grants in `role_access`
  (HR → Dashboard+Timesheet; IT → +Integrations/Settings/Automation). Nav hides and `sales_guard` hard-blocks
  everything else. `sales_guard` allow-list + `auth.php` landing pages updated for hr/it.
- **Bugs fixed**: (1) header title overlapped the user block at ~800px → header is now responsive (flex +
  truncation, secondary text hidden below `lg`); (2) `web-to-lead.php` always stored `lead_source='web'` →
  now honours the `src` param.
- **Consistent formatting**: all money now goes through the shared `inr()` helper (was `₹1,250.00` in the
  admin rule/preview views, now uniform `₹1,250` everywhere).

### Creating the users on your server
Run once from the app root: `php create-users.php` (change the demo passwords first).

## Conversion Flow on the dashboard (Lead → Conversion → Merchant)
- **`api/sales/flow.php`** — end-to-end funnel: 6 stages (Leads → Qualified → Converted → Won → Merchants →
  Activated) with **stage-to-stage conversion %**, overall lead→live-merchant %, and per-phase metrics
  (Lead: qualified %, avg days to convert; Conversion: win rate, avg deal size, open pipeline, incentive
  booked; Merchant: activated %, KYC pending, avg days to activate, live volume, incentive/merchant) plus
  source ROI (leads → live merchants). Row-level scoped like the rest.
- **Dashboard section** (`sales-dashboard.php`) renders it as a colour-grouped stage flow with conversion
  badges between stages, three phase panels, an overall-conversion headline, and a source-ROI line.

## Lead-capture QR code (Web-to-Lead)
- **Settings → Web-to-Lead** now has a **QR generator** (`sales-settings.php`, client-side via qrcodejs — no
  data leaves the browser). It encodes the public lead form URL with a **campaign source** (`?src=qr`,
  `qr_flyer`, `qr_event_delhi`, …). Buttons to **Download PNG** and **Print** (for flyers/standees/invoices).
- **`web-to-lead-form.html`** upgraded: endpoint now resolves relative to wherever it's hosted (portable),
  and it reads `?src=` from its URL so every QR/link is tracked as a distinct source on the lead.
- Flow: scan QR → phone opens the form → submit → `web-to-lead.php` creates a lead with `lead_source=<src>`,
  fires round-robin assignment, and the source shows in **Source Performance / Source ROI**. Verified: a
  `src=qr_event` submit created a lead tagged `qr_event`.

## Email / notification account (Settings → Email)
- **Settings → Email / Notifications** lets an admin (or IT) configure the outbound mail account used for ALL
  app notifications (welcome emails, follow-up reminders, automation emails). Account types: **SMTP (basic)**,
  **Microsoft 365 / Exchange (AD)** — sign in with the AD login (user@domain), and **IMAP/POP account**.
  Fields: from name/email, SMTP host/port, encryption (STARTTLS/SSL/None), username, password, AD domain,
  optional IMAP/POP incoming host/port. **Enabled** toggle + **Send test email** button.
- **`includes/email-helper.php`** — dependency-free native SMTP mailer (sockets + AUTH LOGIN + SSL/STARTTLS,
  no PHPMailer needed). Defines `sendCrmEmail()`, which the automation engine already calls — so once a valid
  account is saved & enabled, notifications flow app-wide. Verified against live Office 365 (connect → EHLO →
  STARTTLS → AUTH). Config in `email_settings` (1 row); the password is stored server-side and NEVER returned
  to the browser (the UI only sees a has_password flag).
- Endpoint `api/sales/email-settings.php` (admin/IT): GET (masked), POST (save), POST ?test=1 (send test).

Security note: the SMTP password is stored in the DB. For production, encrypt it at rest (app key / DPAPI /
secrets vault) rather than plaintext.

## WhatsApp integration — settings + click-to-call/chat everywhere
- **Settings → WhatsApp Integration**: configure the Business API (Meta Cloud) from the UI — provider,
  business number, default country code, phone_number_id, WABA id, API token, and an **Enabled** toggle +
  **Send test**. Token stored server-side, never returned to the browser. Backed by `whatsapp_settings`
  (1 row) + `api/sales/whatsapp-settings.php` (admin/IT). `whatsapp-send.php` now reads this DB config first
  (falls back to `config/whatsapp.php`); with the API on it sends & logs, otherwise it returns a wa.me link.
- **Click-to-call + click-to-chat now visible** (previously the WA button was hover-only and there was no
  call action, nothing on deals):
  - **Leads**: each row's contact cell shows a `tel:` **click-to-call** link and a **WhatsApp** button.
  - **Deals (Pipeline)**: each card shows **call** + **WhatsApp** icons using the linked contact's phone
    (deals query now joins contacts for the number); the WA link is pre-filled with the contact + deal name.
  - Shared `waNum()` helper normalises local numbers to international for wa.me.

## Live chat pickup + click-to-call + conversations monitor + auto time-logging
- **Two-way live chat** (`chat.php`, tables `chat_sessions`/`chat_messages`): the website widget
  (`client.html`) starts a session (creates a live_chat lead + round-robin assign); agents see waiting
  chats in the **Live Chat console** (`sales-chat.php`), pick one up, and message in real time (polling).
  Visitor and agent both poll for new messages.
- **Click-to-call** (`call.php` + `telephony-settings.php` + `call-status.php` webhook): a **cloud-call**
  button on each lead. With a provider configured (Settings → Click-to-Call; **TeleCMI** cheapest, **Exotel**
  pay-per-call) it rings the agent then the customer and bridges them; otherwise it falls back to the device
  dialer (tel:). Call time auto-logs when the provider's status webhook fires.
- **Conversations monitor** (`sales-conversations.php`, admin/manager): a live tab showing all chats in
  progress, calls (24h), and WhatsApp threads (24h), refreshing every 4s.
- **Auto time-logging** (`auto_log_activity()`): closing a chat / sending WhatsApp / completing a call
  auto-creates a **draft timesheet entry** (source=auto) with the duration, an **activity** touchpoint, and a
  **lead follow-up** — so time on chat/WhatsApp/calls is logged automatically and feeds My Timesheet + AI-fill.
- New nav tabs: **Live Chat** (all agents), **Conversations** (admin/manager). timesheet/activity/followup
  enums extended for chat & whatsapp.

## Provider choice (TeleCMI/Exotel), widget call-me, professional header
- **Both providers** (`_telephony.php`): Settings → Click-to-Call lets you pick **Exotel** (pay-per-call) or
  **TeleCMI** (subscription); `call.php` and the widget route to the chosen one via `crm_place_call()`.
  Exotel uses connect.json; TeleCMI uses ind_click_to_call. Switching keeps the saved token.
- **Widget "Call me now"** (`client.html` + `chat.php?action=v_call`): in the live chat, a green Call-me
  button appears **only when telephony is enabled + a Sales number is set + the visitor gave a phone**
  (`call_enabled` flag from v_poll). Clicking rings the operator (Sales number) and the visitor and bridges
  them. Abuse-guarded (one request / 3 min / session). New `sales_number` field in telephony settings.
- **Professional header**: brand is now **"PayG Sales Cloud"**; the page title is derived centrally from the
  page (`sales_page_title()`) and sits in a **fixed position** right after the brand — it no longer shifts
  page to page. Titles standardised (Dashboard, Leads, Pipeline, Merchants, Live Chat, Conversations,
  Timesheet, Incentives, Reports & Analytics, Automation, Settings, Admin, Integrations). Login + tab titles
  rebranded to PayG Sales Cloud.

## AI box integration (Superchat Chat API), separate keys, user management
- **Settings → AI & Voice Engine**: configure the on-prem **AI box** (IP/URL, e.g. http://192.168.0.100:8000),
  a separate **AI API key** and **Voice API key**, a **cloud fallback** endpoint+key, and a **restart token**.
  Live **health badge** shows "AI box is UP / DOWN" with metrics; **Check box** re-probes; **Restart box** POSTs
  /restart (token-gated) to bring it up. A **"Hit the AI box"** tester runs /summarize or /chat_v3 and shows the
  reply + which source answered.
- **Routing** (`_ai.php` `crm_ai_call()`): all AI (insight, lead scoring, coaching, funnel, AI-fill, task
  phrasing) now routes **AI box (if enabled + /health OK) → cloud fallback → local bridge**. If the box is down
  it's detected via /health and it falls over automatically. Endpoints: /chat_v3, /summarize, /translate.
  Tables: `ai_settings` (1 row). Voice endpoint now reads the voice URL+key from here too.
- **User Management** (`sales-users.php` + `users.php`, admin only): a Users tab to list/create/edit login
  accounts, assign roles (Admin/Manager/Sales Exec/HR/IT), link to an employee, reset passwords, and
  activate/deactivate (soft, never hard-delete; can't deactivate your own account). Verified end-to-end
  (create → login works → deactivate → login blocked).

## Document Q&A over the AI box (merchant docs)
- **`api/sales/doc-ai.php`** + a **file-question button** on each merchant row: reps upload a merchant document
  (PAN/GST/agreement — pdf/docx/txt/csv) which is sent to the AI box's **`/document/upload`** (embeddings) so
  they can **ask grounded questions** via **`/document/ask`** ("What's the GST number? Is KYC complete?"), or get
  a **summary**. If the box has no embeddings it falls back to **`/file/upload` + `/file/summarize`** (summary
  only). Uploaded docs are tracked in `ai_documents` (linked to the merchant). Degrades gracefully: if the box
  is down it says "AI box is down — document Q&A needs the box online".

## AI Inbox — read mailboxes, triage & draft replies
- **Settings → Mailboxes to Read (AI Inbox)**: add mailboxes to monitor (IMAP/POP3, incl. Microsoft 365 / AD
  logins — e.g. outlook.office365.com:993 SSL). Stored in `email_mailboxes` (passwords server-side, masked).
- **Inbox tab** (`sales-inbox.php` + `mailbox.php`): **Fetch new** pulls recent emails via the PHP IMAP
  extension, stores them (`inbox_emails`), and **matches each sender to a lead**. For each email the rep clicks
  **Triage with AI** → the AI (box→cloud→local) returns a **summary**, a **category**, a **suggested action**
  ("what to update in the CRM"), and a **draft reply**. The rep edits the draft and **Send reply** goes out via
  the configured SMTP mailer (and logs a follow-up on the matched lead). Archive/re-triage supported.
- Graceful: bad/unreachable mailbox → clear IMAP error; box down → falls over to cloud/local for triage.
- Verified: mailbox CRUD + masking, IMAP fetch actually reached Office 365, and AI triage produced
  summary + "create lead / schedule demo" action + a full draft reply on a sample email.

## Grouped navigation (dropdowns)
The top nav is now grouped into dropdowns to reduce the number of top-level links (15 → ~5):
- **Dashboard** (single)
- **Sales ▾**: Leads, Pipeline, Merchants, Incentives, Reports
- **Conversations ▾**: Live Chat, AI Inbox, Live Monitor
- **Timesheet** (single)
- **Admin ▾**: Incentive Setup, Users, Settings, Automation, Integrations
Groups respect RBAC (empty groups hide, single-item groups render as a plain link — e.g. a sales rep sees
Dashboard · Sales ▾ · Conversations ▾ · Timesheet). Hover or focus opens a dropdown (no JS dependency).

## Mobile hamburger + command palette (⌘K)
- **Responsive nav**: on md+ screens the grouped dropdown bar shows; on narrow screens it collapses to a
  **hamburger "Menu"** that expands a vertical, grouped list (`toggleMobileNav()`). No JS framework needed.
- **Command palette**: press **Ctrl/Cmd + K** (or the search box in the nav) to open a quick-jump palette —
  type to filter pages, ↑/↓ to move, Enter to go, Esc to close. It's **RBAC-aware** (only lists pages the
  signed-in role can access — a sales rep sees 7, an admin sees 15). Powered by `sales_accessible_pages()`.

## Search fixes
- **List search bars now filter as you type** (debounced 300ms) on Leads and Merchants — previously they only
  ran on Enter, which felt like "search doesn't work". Enter still works too.
- **Command palette**: added **"/"** as a shortcut (Ctrl/Cmd-K is often intercepted by the browser), plus the
  clickable Search box in the nav. Type to filter, ↑/↓, Enter, Esc.

## Inline "how to get keys" help in Settings
Every third-party integration card in Settings now has a **?** help icon next to its title. Clicking it opens
a step-by-step guide for registering and getting the credentials, with a link to the provider console:
- **Email (SMTP)** → Microsoft 365 (Authenticated SMTP + App Password) / Gmail (App Password)
- **WhatsApp** → Meta for Developers → Cloud API (Phone number ID, WABA ID, permanent System-User token)
- **Click-to-Call** → Exotel (API Key/Token/SID + ExoPhone) / TeleCMI (App ID + Secret + DID)
- **Mailboxes (IMAP)** → Microsoft 365 / Gmail IMAP hosts + app passwords
- **AI & Voice** → on-prem box URL, keys, restart token, voice endpoint

## "Need keys?" hints + Test-connection buttons
- Each third-party card's help icon is now a visible **"❓ Need keys?"** link → opens the step-by-step guide.
- **Test connection** added where missing:
  - **Mailboxes**: a per-row test does a real **IMAP login** and reports "Connected ✓ — N messages" or the exact error.
  - **Telephony**: a **credential check** — for Exotel it calls the account API (auth check, **no call placed / no charge**); for TeleCMI it validates config completeness.
  Every integration card now has a test: AI (Check box), Email (Send test), WhatsApp (Send test), Telephony &
  Mailboxes (Test connection).

## Auto-assign (round-robin) + new-chat alerts
- **Auto-assign**: a toggle in the Live Monitor. When ON, a new chat is instantly handed to the **next
  available operator** (least-loaded round-robin among active sales_exec/manager). When OFF, chats sit in
  **Currently free** for manual pickup/assignment. A **Distribute waiting now** button assigns any backlog.
  Backed by `chat_settings` (auto_assign) + chat.php actions: settings, distribute, and start-time auto-assign.
- **New-chat alerts**: a 🔔 toggle on both the agent console and the monitor. When a new chat lands in
  "Currently free", it plays a **beep** (Web Audio) and shows a **desktop notification** (browser Notification
  API, permission requested on enable). Only alerts for genuinely new chats (seeds the backlog silently).
