# PayG Sales CRM — What We Have Built (for client walkthrough)

This document summarizes what exists **today**, in production-usable form, in the PayG Sales CRM. It is written as a "here's what we've delivered" reference — use it before any "here's what we can do for you" conversation.

---

## 1. The problem this was built to solve

PayG's sales team needed to track the full funnel from a raw enquiry to an activated, earning merchant, and to pay sales incentives correctly — without spreadsheets, WhatsApp-based follow-up, or manual commission calculation. This CRM covers that entire lifecycle in one system, with different people seeing only what they're allowed to see.

## 2. The funnel we implemented

```
Lead → Qualified → Converted (Deal) → Won Deal → Merchant (onboarding) → Activated
```

- **Leads**: captured from a public website form, a live-chat widget, or manual entry by a sales exec. Each lead is scored (AI + manual) and tracked through follow-ups until it's converted or lost.
- **Deals**: once a lead is qualified, it becomes a deal on a drag-and-drop Kanban pipeline (Negotiating → Closed Won/Lost).
- **Merchants**: the moment a deal is marked "Closed Won," a merchant record is created automatically — no manual re-entry. This merchant goes through KYC and onboarding.
- **Activation & Incentives**: once KYC is verified and the merchant is activated, the system automatically calculates and books the sales rep's incentive against configured rules — flat, percentage of deal, percentage/slab of volume, or per-product. This is idempotent, so re-running it never double-pays.

## 3. Who sees what (role-based access)

- **Admins & Managers**: see and edit everything, company-wide.
- **Sales Execs**: see only their own leads/deals/merchants — enforced at the database query level everywhere, not just hidden in the UI.
- This was audited and hardened: every write endpoint (create/update/delete) checks ownership before allowing a change, every list/report endpoint scopes its results by role, and admin-only areas (incentive approval, automation rules, reports, custom field definitions) are locked to managers/admins only.

## 4. Automation engine

Instead of manual busywork, rules can be configured (no code) to fire on lead/deal events:
- Auto-assign owners (round robin or specific person)
- Update a field, tag a record, create a follow-up task, send a notification, send an email
- Rules run on every create/update across leads, deals, and merchant activation, plus a scheduled sweep (every 30 minutes) for time-based rules (e.g., "no follow-up in 3 days → escalate").
- Automation failures never break the underlying save — if a rule errors, the CRUD operation still succeeds and the error is just logged.

## 5. Where AI is actually used (and what it does)

Every AI feature is **evidence-backed**: the system computes real SQL aggregates first, and AI only narrates/interprets those numbers — it does not invent figures. If the AI service is offline, the app keeps working and just shows the raw numbers instead of a narrative.

| Feature | What it does |
|---|---|
| **Lead scoring** | Reads enquiry details, assigns a 0–100 score and a hot/warm/cold rating, written back onto the lead. |
| **Next-best-action** | Per deal card on the Kanban board, a short coaching suggestion (e.g., "stalled 5 days, price-sensitive — offer X"). |
| **Executive dashboard narrative** | A short written summary of what changed in the funnel this period, generated from real numbers, cached for 30 minutes with a manual refresh. |
| **Agent coaching** | On the incentive leaderboard, per-rep strengths/gaps (e.g., slow follow-up, strong closing rate). |
| **AI-fill timesheet / voice dictation** | A rep can dictate their day ("visited X, discussed pricing, 2 hours") and the system structures it into a timesheet entry, linked to the right lead/merchant. |
| **Document Q&A** | Ask questions against uploaded KYC/merchant documents instead of manually searching PDFs. |
| **Email triage** | Incoming emails auto-categorized with a suggested action and draft reply. |

## 6. Day-to-day operational features

- **Follow-ups**: every call/WhatsApp/email outcome is logged and rolls up onto the lead's status and next-follow-up date automatically.
- **Tasks & KPA/KRA goals**: managers set org- or team-level goals (target tonnage, new accounts, etc.); tasks can be tagged to a goal with hours logged, so contribution rolls up per person and per goal automatically.
- **Timesheets**: daily activity log with manager approval workflow.
- **Tickets**: enquiries or issues (delivery, quality, invoicing) get tracked to resolution instead of living in inboxes or phone calls.
- **Live chat, WhatsApp, telephony (click-to-call)**: integrated so conversations across channels are visible to managers, and calls/chats generate leads automatically.
- **Reports**: funnel conversion, source ROI, monthly trend, sales-cycle length, per-agent performance, win rate — all role-scoped.
- **Custom fields**: admins can add new fields to any entity (lead/deal/merchant) from the UI — no code changes needed to capture industry-specific data (e.g., grade, tonnage, credit limit).
- **Service desk view**: a separate dashboard and KPI builder for the service side of the business (tickets, service leads, response times), independent from the sales-only view.
- **Integration webhooks**: external systems (telephony providers, transaction/accounting feeds, KYC confirmation systems) can push data in via authenticated, scoped API tokens.

## 7. Security posture (already audited and fixed)

- All queries are parameterized (no SQL injection surface).
- Row-level ownership enforced on every write endpoint, not just reads.
- Integration API tokens use exact scope matching (not substring matching).
- Incentive approval/payout, automation rule management, reports, and custom-field definitions are locked to managers/admins.
- Documents (KYC/PAN/GST-grade data) are ownership-checked before any read.

---

**In one line**: this is a complete, secured, role-aware CRM that already automates the sales-to-activation-to-incentive lifecycle end-to-end, with AI layered in as decision support (scoring, coaching, narrative insight, voice-to-structured-data) rather than as a black box — everything AI says is traceable back to a real number in the database.
