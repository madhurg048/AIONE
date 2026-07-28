<?php
/**
 * Workflow automation engine.
 * run_automations($db, entity, event, $record) — evaluate active rules for the
 * (entity,event) pair against $record, execute matching actions, log each run.
 *
 * Entities map: lead->leads, deal->deals, merchant->merchants (owner col = owner_id).
 * Actions: assign_owner (round_robin|specific), update_field, add_tag, create_task,
 *          notify, send_email.
 */

// Lazily make sendCrmEmail() available (safe: PHPMailer errors surface only on send).
if (!function_exists('sendCrmEmail')) {
    $__eh = __DIR__ . '/../../includes/email-helper.php';
    if (is_file($__eh)) {
        @include_once $__eh;
    }
}

function automation_table(string $entity): ?string
{
    return ['lead' => 'leads', 'deal' => 'deals', 'merchant' => 'merchants'][$entity] ?? null;
}

/** Evaluate one condition against a record. */
function automation_cond_match(array $c, array $rec): bool
{
    $field = $c['field'] ?? '';
    $op = $c['op'] ?? 'eq';
    $val = $c['value'] ?? null;
    $cur = $rec[$field] ?? null;

    switch ($op) {
        case 'eq':
            return (string) $cur === (string) $val;
        case 'ne':
            return (string) $cur !== (string) $val;
        case 'gt':
            return (float) $cur > (float) $val;
        case 'lt':
            return (float) $cur < (float) $val;
        case 'contains':
            return $cur !== null && stripos((string) $cur, (string) $val) !== false;
        case 'is_empty':
            return $cur === null || $cur === '';
        case 'not_empty':
            return !($cur === null || $cur === '');
        case 'in':
            return in_array((string) $cur, array_map('trim', explode(',', (string) $val)), true);
        case 'older_than_days':
            if (empty($cur))
                return false;
            $ts = strtotime((string) $cur);
            return $ts !== false && $ts < strtotime("-" . (int) $val . " days");
        default:
            return false;
    }
}

function automation_conditions_pass(array $conditions, string $match, array $rec): bool
{
    if (!$conditions)
        return true;
    $results = array_map(fn($c) => automation_cond_match($c, $rec), $conditions);
    return $match === 'any' ? in_array(true, $results, true) : !in_array(false, $results, true);
}

/**
 * Round-robin assignment.
 *
 * Only employees with manager_id IS NOT NULL are eligible.
 * This excludes top-level managers from automatic lead assignment.
 *
 * Pool can be:
 * - 'all'
 * - null / empty
 * - CSV employee IDs such as "2,3,5,8"
 *
 * $vertical ('sales'|'service'|null): when the "all" pool is used and the record
 * carries a vertical (leads), candidates are further restricted to sales_users
 * whose role matches — a Service ticket is never round-robined to a sales_exec
 * (or vice versa). A custom CSV pool is an explicit admin choice and is left alone.
 */
/**
 * The ONE rotation pointer every sales assignment shares, whatever the enquiry's origin —
 * chatbot (CHAT-), a Sales-created lead (SLS-), or a Service-forwarded one (CRM-).
 *
 * Assignment used to be split across per-rule pointers ('rule:1', 'rule:3') AND this
 * ticket-scoped one, so each source walked its own copy of the rep list and independently
 * restarted at the top: the rep sitting first by employee id collected a share from every
 * pointer while reps further down got skipped. "Next in line" is only meaningful against a
 * single pointer — hence one scope, keyed on vertical alone.
 */
function automation_rr_scope(?string $vertical): string
{
    return 'ticket:' . (in_array($vertical, ['sales', 'service'], true) ? $vertical : 'sales');
}

/**
 * Assign a lead (and, if given, its ticket) to the next sales exec in the shared rotation, and
 * keep the two rows pointing at the SAME person.
 *
 * Every lead-creating path funnels through here rather than leaning on the 'Round-robin assign
 * new leads' automation rule: assignment via rule meant an admin toggling that rule off in the
 * UI silently stopped assigning every inbound enquiry — leads would pile up owned by nobody
 * with nothing to indicate why. Doing it in code makes it unconditional; the rule keeps only
 * its notify action.
 *
 * Returns ['id'=>int,'name'=>string] or null when no exec is eligible — callers must surface
 * that rather than reporting a successful assignment.
 */
function sales_assign_next(PDO $db, ?int $leadId, ?int $ticketId = null, ?string $vertical = 'sales'): ?array
{
    // Service tickets stay unassigned by design (the whole Service team sees them via
    // tickets.php's vertical scope and claims them) — only sales enquiries get an owner.
    if ($vertical !== 'sales') return null;

    $ownerId = automation_round_robin($db, automation_rr_scope('sales'), 'all', 'sales');
    if (!$ownerId) return null;

    $nm = $db->prepare("SELECT full_name FROM employees WHERE id=?");
    $nm->execute([$ownerId]);
    $ownerName = $nm->fetchColumn() ?: null;

    if ($leadId)   $db->prepare("UPDATE leads SET owner_id=? WHERE id=?")->execute([$ownerId, $leadId]);
    if ($ticketId) $db->prepare("UPDATE crm_tickets SET owner_id=?, assigned_to_name=? WHERE id=?")
                      ->execute([$ownerId, $ownerName, $ticketId]);

    return ['id' => $ownerId, 'name' => $ownerName];
}

function automation_round_robin(PDO $db, string $scope, $pool, ?string $vertical = null): ?int
{
    /*
     * CASE 1:
     * Pool is "all".
     *
     * Only employees who report to a manager are included.
     */
    if ($pool === 'all' || $pool === null || $pool === '') {

        if ($vertical === 'sales' || $vertical === 'service') {
            // vertical-scoped: only execs of the matching vertical, and only staff who report
            // to a manager, with an active login (never HR / IT / support).
            $role = $vertical === 'service' ? 'service_exec' : 'sales_exec';
            $ids = $db->query("
                SELECT e.id
                FROM employees e JOIN sales_users su ON su.employee_id = e.id
                WHERE e.manager_id IS NOT NULL AND su.role = '$role' AND su.is_active = 1
                ORDER BY e.id
            ")->fetchAll(PDO::FETCH_COLUMN);
        } else {
            // no vertical on the record: only SALES-capable staff receive assignments
            // (never HR / IT / support). Falls back to all active-login employees if
            // no sales roles are configured.
            $ids = $db->query("SELECT DISTINCT e.id FROM employees e
                               JOIN sales_users su ON su.employee_id=e.id AND su.is_active=1
                               WHERE su.role IN ('sales_exec','manager') ORDER BY e.id")->fetchAll(PDO::FETCH_COLUMN);
            if (!$ids) $ids = $db->query("SELECT DISTINCT e.id FROM employees e
                               JOIN sales_users su ON su.employee_id=e.id AND su.is_active=1 ORDER BY e.id")->fetchAll(PDO::FETCH_COLUMN);
        }

    } else {

        /*
         * CASE 2:
         * A custom pool of employee IDs was configured.
         *
         * First sanitize the IDs.
         */
        $poolIds = array_values(
            array_filter(
                array_map('intval', explode(',', (string) $pool))
            )
        );

        if (!$poolIds) {
            return null;
        }

        /*
         * Validate custom pool against employees table.
         *
         * This prevents a manager from being assigned even if their
         * employee ID was accidentally added to the automation pool.
         */
        $placeholders = implode(
            ',',
            array_fill(0, count($poolIds), '?')
        );

        $stmt = $db->prepare("
            SELECT id
            FROM employees
            WHERE id IN ($placeholders)
              AND manager_id IS NOT NULL
            ORDER BY id
        ");

        $stmt->execute($poolIds);

        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /*
     * Normalize database values to integers.
     *
     * This is important because PDO may return IDs as strings,
     * while array_search() below uses strict comparison.
     */
    $ids = array_map('intval', $ids);

    if (!$ids) {
        return null;
    }


    /*
     * Get the employee who received the previous lead
     * for this automation scope.
     */
    $s = $db->prepare("
        SELECT last_employee_id
        FROM assignment_state
        WHERE scope = ?
    ");

    $s->execute([$scope]);

    $last = $s->fetchColumn();


    /*
     * Default to the first eligible employee.
     */
    $idx = 0;


    /*
     * If there was a previous assignment, select the next employee.
     *
     * Example:
     *
     * Eligible IDs: [4, 6, 9]
     *
     * Last = 4 → Next = 6
     * Last = 6 → Next = 9
     * Last = 9 → Next = 4
     */
    if (
        $last !== false &&
        ($position = array_search((int) $last, $ids, true)) !== false
    ) {
        $idx = ($position + 1) % count($ids);
    }


    $next = (int) $ids[$idx];


    /*
     * Save the current assignment position.
     */
    $db->prepare("
        INSERT INTO assignment_state (
            scope,
            last_employee_id
        )
        VALUES (?, ?)

        ON DUPLICATE KEY UPDATE
            last_employee_id = VALUES(last_employee_id)
    ")->execute([
                $scope,
                $next
            ]);


    return $next;
}

/**
 * Is at least one exec of the given vertical ('sales'|'service') currently online?
 * "Online" = an active-login sales_users row whose last_heartbeat_at was refreshed in the
 * last 90s (the UI pings every 30s while a CRM tab is open — see sales_scripts()).
 * Used by chatbot-ticket.php to decide whether to open a live chat immediately or stay
 * ticket-only (no point queueing a chat nobody is there to pick up).
 */
function automation_vertical_has_online_agent(PDO $db, string $vertical): bool
{
    $role = $vertical === 'service' ? 'service_exec' : 'sales_exec';
    $s = $db->prepare("SELECT COUNT(*) FROM sales_users
                        WHERE role = ? AND is_active = 1
                          AND last_heartbeat_at IS NOT NULL
                          AND last_heartbeat_at > NOW() - INTERVAL 90 SECOND");
    $s->execute([$role]);
    return (int)$s->fetchColumn() > 0;
}

/**
 * Expire stale 'waiting' chat_sessions into 'missed': immediately if nobody in that vertical is
 * online to take it, otherwise once 60 seconds have passed with nobody picking it up. Called on
 * every inbox/poll so the Missed tab reflects reality without needing a cron.
 */
if (!function_exists('chat_sweep_missed')) {
    /**
     * Waiting-queue timeout ladder for a chat nobody has picked up yet, run on every visitor/agent
     * poll (see chat.php's v_poll/a_poll/inbox):
     *   30s -> nudge the visitor that agents seem busy (once — gated by queue_warn_sent_at).
     *   60s -> apologize, hand back the reference, and end the request. Status moves to 'missed'
     *          (not 'closed') so it still lands in the agent-side Missed tab for follow-up, but
     *          the widget (chat.php) treats a 'missed' status the same as the chat having ended.
     *          missed_reason='waiting_left' tags WHY it's missed — the visitor gave up waiting,
     *          as opposed to any other future reason — so sales-chat.php's Missed tab can label
     *          it distinctly instead of a generic "Missed".
     *
     * Runs from several concurrent pollers (visitor v_poll, agent a_poll, agent inbox) that can
     * all land within the same second, so each step below claims a row with an atomic
     * UPDATE ... WHERE <still in the pre-transition state> compare-and-swap BEFORE pushing its
     * chat message — only the request whose UPDATE actually matched a row (rowCount() > 0) prints
     * the message, so two overlapping sweeps can't both print the same nudge/apology twice.
     */
    function chat_sweep_missed(PDO $db): void {
        $warn = $db->query("SELECT id FROM chat_sessions
                             WHERE status='waiting' AND queue_warn_sent_at IS NULL
                               AND started_at <= NOW() - INTERVAL 30 SECOND")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($warn as $sid) {
            $sid = (int) $sid;
            $claim = $db->prepare("UPDATE chat_sessions SET queue_warn_sent_at=NOW() WHERE id=? AND queue_warn_sent_at IS NULL");
            $claim->execute([$sid]);
            if ($claim->rowCount()) {
                chat_push($db, $sid, 'bot', '⏳ Our agents seem to be busy right now — thanks for your patience, we\'ll connect you shortly.');
            }
        }

        $miss = $db->query("SELECT id FROM chat_sessions
                             WHERE status='waiting' AND started_at <= NOW() - INTERVAL 60 SECOND")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($miss as $sid) {
            $sid = (int) $sid;
            $claim = $db->prepare("UPDATE chat_sessions SET status='missed', missed_reason='waiting_left', closed_at=NOW() WHERE id=? AND status='waiting'");
            $claim->execute([$sid]);
            if ($claim->rowCount()) {
                $r = $db->prepare("SELECT cs.token, ct.ticket_ref FROM chat_sessions cs
                                    LEFT JOIN crm_tickets ct ON ct.id = cs.ticket_id WHERE cs.id=?");
                $r->execute([$sid]);
                $row = $r->fetch(PDO::FETCH_ASSOC) ?: [];
                $ref = $row['ticket_ref'] ?: $row['token'];
                chat_push($db, $sid, 'bot', "😔 Sorry, our agents are busy right now. Your reference no. is {$ref} — they'll respond to you shortly.");
            }
        }
    }
}
if (!function_exists('call_sweep_missed')) {
    /**
     * Same problem as chat's waiting queue, for the Calls workspace: an inbound call nobody
     * claims (call-inbound.php leaves claimed_by NULL) would otherwise sit as status='ringing'
     * forever — cluttering every agent's ringing banner indefinitely and never showing up
     * correctly as "missed" in the call log/KPIs. Run inline on every calls.php?action=state
     * poll (no cron needed), same compare-and-swap pattern as chat_sweep_missed() so concurrent
     * pollers can't double-process the same row.
     */
    function call_sweep_missed(PDO $db): void {
        $miss = $db->query("SELECT id FROM call_logs
                             WHERE status='ringing' AND claimed_by IS NULL
                               AND direction='inbound' AND started_at <= NOW() - INTERVAL 60 SECOND")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($miss as $cid) {
            $cid = (int) $cid;
            $claim = $db->prepare("UPDATE call_logs SET status='missed', ended_at=NOW()
                                    WHERE id=? AND status='ringing' AND claimed_by IS NULL");
            $claim->execute([$cid]);
        }
    }
}
if (!function_exists('chat_sweep_inactive_active')) {
    /**
     * Visitor-inactivity timeout for chats already connected to an agent: once the agent side has
     * gone quiet (last message is 'agent', OR 'system' — e.g. the "Chat started by agent" seed
     * message from ?action=start_from_lead, which never gets an 'agent' message if the agent opens
     * the chat and then never actually types anything) and the visitor hasn't replied within 5
     * minutes, auto-close the chat exactly like a manual close (same status/closed_at/duration_sec
     * bookkeeping as ?action=close), so the widget's normal "chat has been closed" flow (chat.php's
     * showLiveChatClosedMessage) picks it up on its next poll instead of needing separate
     * client-side wording. Without the 'system' case, a manually-started chat with no reply from
     * either side never matches this sweep and sits in 'active' — a permanently "live" icon on the
     * lead row — forever.
     *
     * Same concurrent-poller race as chat_sweep_missed() above — the visitor's v_poll, the
     * agent's a_poll, and the agent's inbox list can all sweep the same row within the same
     * second, so the UPDATE ... WHERE status='active' compare-and-swap decides who (if anyone)
     * gets to push the one closing system message.
     */
    function chat_sweep_inactive_active(PDO $db): void {
        $rows = $db->query("SELECT cs.id, cs.picked_at, cs.started_at
                             FROM chat_sessions cs
                             WHERE cs.status='active'
                               AND (SELECT cm.sender FROM chat_messages cm WHERE cm.session_id=cs.id ORDER BY cm.id DESC LIMIT 1) IN ('agent','system')
                               AND (SELECT cm.created_at FROM chat_messages cm WHERE cm.session_id=cs.id ORDER BY cm.id DESC LIMIT 1) <= NOW() - INTERVAL 5 MINUTE")
            ->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $sid = (int) $row['id'];
            $start = strtotime($row['picked_at'] ?: $row['started_at']);
            $dur = max(0, time() - $start);
            $claim = $db->prepare("UPDATE chat_sessions SET status='closed', closed_at=NOW(), duration_sec=? WHERE id=? AND status='active'");
            $claim->execute([$dur, $sid]);
            if ($claim->rowCount()) {
                chat_push($db, $sid, 'system', 'Chat closed automatically — no activity for 5 minutes');
            }
        }
    }
}

/**
 * Shared live-chat session helpers. Used by both api/sales/chat.php (agent console +
 * visitor v_post/v_poll) and api/sales/chatbot-ticket.php (opens a chat session straight
 * from the public chatbot widget for service/transaction requests when a Service exec is
 * online) so both entry points feed the exact same chat_sessions/chat_messages engine —
 * one waiting queue, one pickup flow, one inbox.
 */
if (!function_exists('chat_push')) {
    // Returns the new message's id. Must be read from lastInsertId() immediately after the
    // INSERT — this driver resets lastInsertId() to 0 on the very next query (even a plain
    // UPDATE/SELECT with no auto-increment of its own), so callers that insert several
    // messages in a row and read lastInsertId() afterward would always get back 0.
    function chat_push(PDO $db, int $sid, string $sender, string $body, ?int $createdBy = null): int {
        $db->prepare("INSERT INTO chat_messages (session_id,sender,body,created_by) VALUES (?,?,?,?)")->execute([$sid, $sender, $body, $createdBy]);
        $mid = (int) $db->lastInsertId();
        $db->prepare("UPDATE chat_sessions SET last_activity_at=NOW() WHERE id=?")->execute([$sid]);
        return $mid;
    }
}
/** Assign/reassign a chat. First assignment (was waiting) announces the agent; reassignment is silent. */
if (!function_exists('chat_assign_to')) {
    function chat_assign_to(PDO $db, int $sid, int $agentId): ?array {
        $s = $db->prepare("SELECT status, lead_id, ticket_id FROM chat_sessions WHERE id=?"); $s->execute([$sid]); $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['status'] === 'closed') return null;
        $wasWaiting = ($row['status'] === 'waiting');
        $db->prepare("UPDATE chat_sessions SET agent_id=?, status='active', picked_at=COALESCE(picked_at,NOW()) WHERE id=?")->execute([$agentId, $sid]);
        if ($wasWaiting) {
            $nm = $db->prepare("SELECT full_name FROM employees WHERE id=?"); $nm->execute([$agentId]);
            $agentName = $nm->fetchColumn() ?: null;
            chat_push($db, $sid, 'system', ($agentName ?: 'An agent') . ' joined the chat');
            // "First to accept it wins": picking up the waiting chat also claims its linked
            // service/transaction/callback ticket (if the ticket itself hasn't been claimed via
            // the Tickets page already) so owner_id doesn't sit NULL forever — this is the same
            // "whoever accepts first" flow, just entered from the live-chat side instead of the
            // Tickets list (see tickets.php?action=claim). Prefer the exact ticket this chat was
            // opened for (ticket_id) over a blanket lead_id match, which would also grab any
            // other unrelated unclaimed ticket the same repeat customer happens to have.
            if ($row['ticket_id']) {
                $db->prepare("UPDATE crm_tickets SET owner_id=?, assigned_to_name=? WHERE id=? AND owner_id IS NULL")
                   ->execute([$agentId, $agentName, $row['ticket_id']]);
            } elseif ($row['lead_id']) {
                $db->prepare("UPDATE crm_tickets SET owner_id=?, assigned_to_name=? WHERE lead_id=? AND owner_id IS NULL")
                   ->execute([$agentId, $agentName, $row['lead_id']]);
            }
        }
        return ['reassigned' => !$wasWaiting];
    }
}
/** Least-loaded active operator for a vertical (round-robin by current active-chat count). 0 if none. */
if (!function_exists('chat_next_agent')) {
    function chat_next_agent(PDO $db, string $vertical = 'sales'): int {
        $roles = $vertical === 'service' ? "'service_exec','service_manager'" : "'sales_exec','manager'";
        $r = $db->query("SELECT e.id,
                (SELECT COUNT(*) FROM chat_sessions cs WHERE cs.agent_id=e.id AND cs.status='active') AS load_n
            FROM sales_users su JOIN employees e ON e.id=su.employee_id
            WHERE su.is_active=1 AND su.role IN($roles)
            ORDER BY load_n ASC, RAND() LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        return $r ? (int)$r['id'] : 0;
    }
}
if (!function_exists('chat_auto_assign_on')) {
    function chat_auto_assign_on(PDO $db): bool {
        try { return (bool)$db->query("SELECT auto_assign FROM chat_settings WHERE id=1")->fetchColumn(); }
        catch (Throwable $e) { return false; }
    }
}
/**
 * Open a new waiting chat session (visitor-facing token flow) and, if auto-assign is on,
 * hand it straight to the least-loaded exec for that vertical. Returns [token, session_id].
 */
if (!function_exists('chat_open_waiting_session')) {
    function chat_open_waiting_session(PDO $db, ?int $leadId, string $name, ?string $email, ?string $phone, string $vertical, ?string $firstVisitorMessage = null, ?string $greeting = null, ?int $ticketId = null): array
    {
        $token = bin2hex(random_bytes(16));
        // $ticketId ties this session to the specific crm_tickets row it was opened for — needed
        // so a "transaction" ticket's attachment only ever shows on ITS OWN chat, not on every
        // later chat that happens to reuse the same lead_id (see phase33 migration).
        $db->prepare("INSERT INTO chat_sessions (token,visitor_name,visitor_email,visitor_phone,lead_id,ticket_id,vertical,status,last_activity_at)
                      VALUES (?,?,?,?,?,?,?, 'waiting', NOW())")
            ->execute([$token, $name, $email, $phone, $leadId, $ticketId, $vertical]);
        $sid = (int) $db->lastInsertId();
        $lastMessageId = chat_push($db, $sid, 'bot', $greeting ?? ('Thanks ' . $name . '! You\'re in the queue — an agent will join shortly.'));
        if ($firstVisitorMessage) {
            // Last message id inserted so far, so the widget can seed its poll "since" cursor
            // and avoid re-rendering the greeting/description it already showed locally before
            // the session existed.
            $lastMessageId = chat_push($db, $sid, 'visitor', $firstVisitorMessage);
        }
        if (chat_auto_assign_on($db) && ($nextAgent = chat_next_agent($db, $vertical))) {
            chat_assign_to($db, $sid, $nextAgent);
        }
        return ['token' => $token, 'session_id' => $sid, 'last_message_id' => $lastMessageId];
    }
}
/**
 * Open a chat session that is 'missed' from the very start — used when a customer reaches out but
 * there is NOBODY online in that vertical to ever pick it up (chatbot-ticket.php's offline branch).
 * Without this the enquiry only became a ticket and never surfaced on the Live Chat "Missed" tab,
 * so the Service team had no single place that showed "a customer tried to chat and no one was
 * here". missed_reason='no_agent_online' distinguishes it from 'waiting_left' (someone WAS online
 * but let the 60s pickup window lapse), so the Missed tab can label the two cases differently.
 * No visitor token is issued — the visitor is not shown a live chat window in this case.
 */
if (!function_exists('chat_open_missed_session')) {
    function chat_open_missed_session(PDO $db, ?int $leadId, string $name, ?string $email, ?string $phone, string $vertical, ?string $firstVisitorMessage = null, ?int $ticketId = null): int
    {
        $token = bin2hex(random_bytes(16));
        $db->prepare("INSERT INTO chat_sessions (token,visitor_name,visitor_email,visitor_phone,lead_id,ticket_id,vertical,status,missed_reason,started_at,closed_at,last_activity_at)
                      VALUES (?,?,?,?,?,?,?, 'missed','no_agent_online', NOW(), NOW(), NOW())")
            ->execute([$token, $name, $email, $phone, $leadId, $ticketId, $vertical]);
        $sid = (int) $db->lastInsertId();
        if ($firstVisitorMessage) {
            chat_push($db, $sid, 'visitor', $firstVisitorMessage);
        }
        chat_push($db, $sid, 'system', 'No agent was online — the customer could not be connected to a live chat.');
        return $sid;
    }
}

function automation_render_tokens(string $tpl, array $rec): string
{
    return preg_replace_callback('/\{\{(\w+)\}\}/', fn($m) => (string) ($rec[$m[1]] ?? ''), $tpl);
}

/**
 * Execute a rule's actions against a record. Returns [labels[], updated $rec].
 */
function automation_execute(PDO $db, array $rule, array $rec): array
{
    $entity = $rule['entity'];
    $table = automation_table($entity);
    $id = (int) ($rec['id'] ?? 0);
    $actions = json_decode($rule['actions'] ?? '[]', true) ?: [];
    $done = [];

    foreach ($actions as $a) {
        $type = $a['type'] ?? '';
        try {
            switch ($type) {
                case 'assign_owner':
                    // Shared rotation pointer, NOT a per-rule one — see automation_rr_scope().
                    // A rule that round-robins (e.g. 'Reassign stale leads') must hand out the
                    // next rep in the same single rotation every other flow draws from;
                    // 'rule:<id>' gave each rule a private pointer that restarted at the top of
                    // the rep list and skewed the split.
                    $emp = ($a['mode'] ?? 'specific') === 'round_robin'
                        ? automation_round_robin($db, automation_rr_scope($rec['vertical'] ?? null), $a['pool'] ?? 'all', $rec['vertical'] ?? null)
                        : (int) ($a['employee_id'] ?? 0);
                    if ($emp && $table) {
                        $db->prepare("UPDATE `$table` SET owner_id=? WHERE id=?")->execute([$emp, $id]);
                        $rec['owner_id'] = $emp;
                        $done[] = "assigned→emp#$emp";
                    }
                    break;

                case 'update_field':
                    $f = $a['field'] ?? '';
                    $v = $a['value'] ?? null;
                    if ($f && $table && preg_match('/^[a-z_]+$/', $f)) {
                        $db->prepare("UPDATE `$table` SET `$f`=? WHERE id=?")->execute([$v, $id]);
                        $rec[$f] = $v;
                        $done[] = "set $f=$v";
                    }
                    break;

                case 'add_tag':
                    if ($table) {
                        $tag = $a['tag'] ?? '';
                        $cur = (string) ($rec['tags'] ?? '');
                        $tags = array_filter(array_map('trim', explode(',', $cur)));
                        if ($tag && !in_array($tag, $tags, true)) {
                            $tags[] = $tag;
                            $new = implode(',', $tags);
                            // leads/merchants may not all have tags col — guard
                            try {
                                $db->prepare("UPDATE `$table` SET tags=? WHERE id=?")->execute([$new, $id]);
                                $rec['tags'] = $new;
                                $done[] = "tag+$tag";
                            } catch (Throwable $e) { /* no tags column */
                            }
                        }
                    }
                    break;

                case 'create_task':
                    $due = isset($a['due_in_days']) ? date('Y-m-d H:i:s', strtotime('+' . (int) $a['due_in_days'] . ' days')) : null;
                    $db->prepare("INSERT INTO activities (activity_type,subject,related_type,related_id,owner_id,status,due_at)
                                  VALUES ('task',?,?,?,?, 'open', ?)")
                        ->execute([$a['subject'] ?? 'Follow up', $entity, $id, $rec['owner_id'] ?? null, $due]);
                    $done[] = 'task created';
                    break;

                case 'notify':
                    $uid = ($a['to'] ?? 'owner') === 'owner' ? (int) ($rec['owner_id'] ?? 0) : (int) ($a['employee_id'] ?? 0);
                    if ($uid) {
                        $link = $entity === 'lead' ? "sales-leads.php" : ($entity === 'merchant' ? "sales-merchants.php" : "sales-pipeline.php");
                        $db->prepare("INSERT INTO notifications (user_id,type,title,message,link)
                                      VALUES (?,?,?,?,?)")
                            ->execute([
                                $uid,
                                'sales_automation',
                                automation_render_tokens($a['title'] ?? 'Automation', $rec),
                                automation_render_tokens($a['message'] ?? '', $rec),
                                $link
                            ]);
                        $done[] = "notified emp#$uid";
                    }
                    break;

                case 'send_email':
                    $to = ($a['to'] ?? 'lead') === 'owner' ? null : ($rec['email'] ?? null);
                    if ($to && filter_var($to, FILTER_VALIDATE_EMAIL)) {
                        $subj = automation_render_tokens($a['subject'] ?? 'PayG', $rec);
                        $body = automation_render_tokens($a['body'] ?? '', $rec);
                        // body may be rich HTML (from the editor) or plain text — handle both
                        $isHtml = strip_tags($body) !== $body;
                        $html = $isHtml ? $body : ('<p>' . nl2br(htmlspecialchars($body)) . '</p>');
                        $text = trim(strip_tags($body));
                        if (function_exists('sendCrmEmail')) {
                            @sendCrmEmail($to, trim(($rec['first_name'] ?? '') . ' ' . ($rec['last_name'] ?? '')), $subj, $html, $text);
                            $done[] = "emailed $to";
                        } else {
                            $done[] = "email skipped (helper missing)";
                        }
                    }
                    break;
            }
        } catch (Throwable $e) {
            $done[] = "ERR $type: " . substr($e->getMessage(), 0, 60);
        }
    }
    return [$done, $rec];
}

/**
 * Main entry. Non-fatal: never throws into the caller (automation must not break CRUD).
 * @return array fired rule names
 */
function run_automations(PDO $db, string $entity, string $event, array $record): array
{
    $fired = [];
    try {
        $rs = $db->prepare("SELECT * FROM automation_rules
                            WHERE entity=? AND trigger_event=? AND is_active=1 ORDER BY id");
        $rs->execute([$entity, $event]);
        foreach ($rs->fetchAll() as $rule) {
            $conds = json_decode($rule['conditions'] ?? '[]', true) ?: [];
            if (!automation_conditions_pass($conds, $rule['match_type'], $record))
                continue;

            [$done, $record] = automation_execute($db, $rule, $record);

            $db->prepare("UPDATE automation_rules SET run_count=run_count+1, last_run_at=NOW() WHERE id=?")
                ->execute([$rule['id']]);
            $db->prepare("INSERT INTO automation_logs (rule_id,entity,entity_id,actions_run,status,message)
                          VALUES (?,?,?,?, 'success', ?)")
                ->execute([$rule['id'], $entity, $record['id'] ?? null, implode('; ', $done), $rule['name']]);
            $fired[] = $rule['name'];
        }
    } catch (Throwable $e) {
        error_log('run_automations error: ' . $e->getMessage());
    }
    return $fired;
}
