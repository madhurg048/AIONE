<?php
/**
 * Service overview — a high-level snapshot of the Service desk in one call
 * (live chats → calls → WhatsApp → tickets → transfers → service leads).
 *
 * Row-level scoped like overview.php: admin / service_manager see the whole Service
 * vertical; a service_exec sees only their own interactions. Sales-only roles
 * (manager / sales_exec) are rejected — this endpoint is Service-vertical data.
 *   GET service-overview.php            (admin reaches it via ?vertical=service nav links)
 */
require_once __DIR__ . '/_bootstrap.php';
require_login();
if (current_vertical() === 'sales') fail('Not authorized — Service dashboard is for the Service department', 403);
$db = db();
$q   = fn($s) => (function () use ($db, $s) { try { return $db->query($s); } catch (Throwable $e) { return null; } })();
$one = fn($s, $d = 0) => ($r = $q($s)) ? ($r->fetchColumn() ?: $d) : $d;
$all = fn($s) => ($r = $q($s)) ? $r->fetchAll() : [];

$me     = (int)current_employee_id();
$isExec = visible_owner_ids() !== null;                 // true => scope to my own rows

/* Service-side agents (service_exec + service_manager) — used to attribute calls and
   WhatsApp threads, which carry no vertical column of their own. */
$svcIds = array_map('intval', $db->query(
    "SELECT employee_id FROM sales_users WHERE is_active=1 AND employee_id IS NOT NULL
     AND role IN ('service_exec','service_manager')")->fetchAll(PDO::FETCH_COLUMN) ?: []);
$inSvc = $svcIds ? ('IN (' . implode(',', $svcIds) . ')') : 'IN (-1)';

/* ---- inline scope fragments (int-safe, mirror overview.php's style) ---- */
// chat_sessions: vertical column exists; an exec sees chats they handle or whose lead they own
$CS = "cs.vertical='service'" . ($isExec ? " AND (cs.agent_id=$me OR EXISTS(SELECT 1 FROM leads lx WHERE lx.id=cs.lead_id AND lx.owner_id=$me))" : '');
// call_logs: no vertical — attribute via the lead's vertical or a service-side agent
$CL = "(EXISTS(SELECT 1 FROM leads lx WHERE lx.id=cl.lead_id AND lx.vertical='service') OR cl.agent_id $inSvc)"
    . ($isExec ? " AND cl.agent_id=$me" : '');
// whatsapp_threads: no vertical — same attribution rule
$WT = "(EXISTS(SELECT 1 FROM leads lx WHERE lx.id=wt.lead_id AND lx.vertical='service') OR wt.agent_id $inSvc)"
    . ($isExec ? " AND (wt.agent_id=$me OR EXISTS(SELECT 1 FROM leads lx WHERE lx.id=wt.lead_id AND lx.owner_id=$me))" : '');
$TK = "t.vertical='service'" . ($isExec ? " AND t.owner_id=$me" : '');
$LD = "l.vertical='service'" . ($isExec ? " AND l.owner_id=$me" : '');
// chat_transfers: an exec sees handoffs they were part of
$TR_OUT = "ct.action='transfer' AND ct.from_vertical='service'" . ($isExec ? " AND ct.from_agent_id=$me" : '');
$TR_IN  = "ct.action='transfer' AND ct.to_vertical='service'"  . ($isExec ? " AND ct.to_agent_id=$me"   : '');

$MON = "DATE_FORMAT(%s,'%%Y-%%m')=DATE_FORMAT(CURDATE(),'%%Y-%%m')";
$mon = fn($col) => sprintf($MON, $col);

/* ================= KPI numbers ================= */
$chats = [
    'waiting_now'      => (int)$one("SELECT COUNT(*) FROM chat_sessions cs WHERE $CS AND cs.status='waiting'"),
    'active_now'       => (int)$one("SELECT COUNT(*) FROM chat_sessions cs WHERE $CS AND cs.status='active'"),
    'today'            => (int)$one("SELECT COUNT(*) FROM chat_sessions cs WHERE $CS AND DATE(cs.started_at)=CURDATE()"),
    'month'            => (int)$one("SELECT COUNT(*) FROM chat_sessions cs WHERE $CS AND " . $mon('cs.started_at')),
    'handled_month'    => (int)$one("SELECT COUNT(*) FROM chat_sessions cs WHERE $CS AND cs.picked_at IS NOT NULL AND " . $mon('cs.started_at')),
    'missed_month'     => (int)$one("SELECT COUNT(*) FROM chat_sessions cs WHERE $CS AND cs.status='missed' AND " . $mon('cs.started_at')),
    'avg_pickup_sec'   => (int)round((float)$one("SELECT AVG(TIMESTAMPDIFF(SECOND,cs.started_at,cs.picked_at)) FROM chat_sessions cs
                                                  WHERE $CS AND cs.picked_at IS NOT NULL AND " . $mon('cs.started_at'))),
    'avg_duration_sec' => (int)round((float)$one("SELECT AVG(cs.duration_sec) FROM chat_sessions cs
                                                  WHERE $CS AND cs.duration_sec>0 AND " . $mon('cs.started_at'))),
];
$calls = [
    'today'            => (int)$one("SELECT COUNT(*) FROM call_logs cl WHERE $CL AND DATE(cl.started_at)=CURDATE()"),
    'month'            => (int)$one("SELECT COUNT(*) FROM call_logs cl WHERE $CL AND " . $mon('cl.started_at')),
    'connected_month'  => (int)$one("SELECT COUNT(*) FROM call_logs cl WHERE $CL AND cl.duration_sec>0 AND " . $mon('cl.started_at')),
    'inbound_month'    => (int)$one("SELECT COUNT(*) FROM call_logs cl WHERE $CL AND cl.direction='inbound' AND " . $mon('cl.started_at')),
    'avg_duration_sec' => (int)round((float)$one("SELECT AVG(cl.duration_sec) FROM call_logs cl
                                                  WHERE $CL AND cl.duration_sec>0 AND " . $mon('cl.started_at'))),
];
$wa = [
    'msgs_today'    => (int)$one("SELECT COUNT(*) FROM whatsapp_messages wm JOIN whatsapp_threads wt ON wt.id=wm.thread_id
                                  WHERE $WT AND DATE(wm.created_at)=CURDATE()"),
    'msgs_month'    => (int)$one("SELECT COUNT(*) FROM whatsapp_messages wm JOIN whatsapp_threads wt ON wt.id=wm.thread_id
                                  WHERE $WT AND " . $mon('wm.created_at')),
    'inbound_month' => (int)$one("SELECT COUNT(*) FROM whatsapp_messages wm JOIN whatsapp_threads wt ON wt.id=wm.thread_id
                                  WHERE $WT AND wm.direction='inbound' AND " . $mon('wm.created_at')),
    'threads_open'  => (int)$one("SELECT COUNT(*) FROM whatsapp_threads wt WHERE $WT AND wt.status='open'"),
    'unread'        => (int)$one("SELECT COALESCE(SUM(wt.unread_count),0) FROM whatsapp_threads wt WHERE $WT AND wt.status='open'"),
];
$tickets = [
    'total'             => (int)$one("SELECT COUNT(*) FROM crm_tickets t WHERE $TK"),
    'open'              => (int)$one("SELECT COUNT(*) FROM crm_tickets t WHERE $TK AND (t.status NOT IN('closed','resolved') OR t.status IS NULL)"),
    'waiting_us'        => (int)$one("SELECT COUNT(*) FROM crm_tickets t WHERE $TK AND t.waiting_on IN('us','service_team')"),
    'unclaimed'         => (int)$one("SELECT COUNT(*) FROM crm_tickets t WHERE t.vertical='service' AND t.owner_id IS NULL AND (t.status NOT IN('closed','resolved') OR t.status IS NULL)"),
    'resolved_month'    => (int)$one("SELECT COUNT(*) FROM crm_tickets t WHERE $TK AND t.status IN('closed','resolved') AND " . $mon('t.updated_at')),
    'new_month'         => (int)$one("SELECT COUNT(*) FROM crm_tickets t WHERE $TK AND " . $mon('t.created_at')),
    'callbacks_pending' => (int)$one("SELECT COUNT(*) FROM crm_tickets t WHERE $TK AND t.assist_type='callback' AND (t.status NOT IN('closed','resolved') OR t.status IS NULL)"),
];
$transfers = [
    'out_month'    => (int)$one("SELECT COUNT(*) FROM chat_transfers ct WHERE $TR_OUT AND " . $mon('ct.created_at')),
    'in_month'     => (int)$one("SELECT COUNT(*) FROM chat_transfers ct WHERE $TR_IN AND " . $mon('ct.created_at')),
    'today'        => (int)$one("SELECT COUNT(*) FROM chat_transfers ct WHERE (($TR_OUT) OR ($TR_IN)) AND DATE(ct.created_at)=CURDATE()"),
    'assign_month' => (int)$one("SELECT COUNT(*) FROM chat_transfers ct WHERE ct.action='assign' AND ct.to_vertical='service'"
                                . ($isExec ? " AND ct.to_agent_id=$me" : '') . " AND " . $mon('ct.created_at')),
];
$leads = [
    'total' => (int)$one("SELECT COUNT(*) FROM leads l WHERE $LD"),
    'today' => (int)$one("SELECT COUNT(*) FROM leads l WHERE $LD AND DATE(l.created_at)=CURDATE()"),
    'month' => (int)$one("SELECT COUNT(*) FROM leads l WHERE $LD AND " . $mon('l.created_at')),
];

$out = [
    'chats' => $chats, 'calls' => $calls, 'whatsapp' => $wa,
    'tickets' => $tickets, 'transfers' => $transfers, 'leads' => $leads,
];

/* ================= Service flow (mirrors the sales Conversion Flow strip) =================
   Incoming (this month, all channels) → Handled → Tickets raised → Resolved. */
$incoming = $chats['month'] + $calls['month']
          + (int)$one("SELECT COUNT(*) FROM whatsapp_threads wt WHERE $WT AND " . $mon('wt.created_at'));
$handled  = $chats['handled_month'] + $calls['connected_month']
          + (int)$one("SELECT COUNT(DISTINCT wm.thread_id) FROM whatsapp_messages wm JOIN whatsapp_threads wt ON wt.id=wm.thread_id
                       WHERE $WT AND wm.sender='agent' AND " . $mon('wm.created_at'));
$pct = fn($n, $d) => $d > 0 ? round($n * 100 / $d, 1) : 0;
$out['flow'] = [
    'stages' => [
        ['label' => 'Incoming',       'count' => $incoming,                  'phase' => 'channel', 'conv' => 0],
        ['label' => 'Handled',        'count' => $handled,                   'phase' => 'handling', 'conv' => $pct($handled, $incoming)],
        ['label' => 'Tickets raised', 'count' => $tickets['new_month'],      'phase' => 'handling', 'conv' => $pct($tickets['new_month'], max(1,$handled))],
        ['label' => 'Resolved',       'count' => $tickets['resolved_month'], 'phase' => 'resolution', 'conv' => $pct($tickets['resolved_month'], max(1,$tickets['new_month']))],
    ],
    'overall' => $pct($tickets['resolved_month'], $incoming),
    'phases' => [
        'channel' => ['label' => 'Channels (this month)', 'icon' => 'fa-tower-broadcast', 'color' => 'indigo', 'metrics' => [
            ['Live chats (chatbot)', $chats['month'], 'n'],
            ['Phone calls', $calls['month'], 'n'],
            ['WhatsApp messages', $wa['msgs_month'], 'n'],
            ['New service leads', $leads['month'], 'n'],
        ]],
        'handling' => ['label' => 'Handling', 'icon' => 'fa-headset', 'color' => 'amber', 'metrics' => [
            ['Avg pickup time', $chats['avg_pickup_sec'] . 's', 'n'],
            ['Avg chat duration', round($chats['avg_duration_sec'] / 60, 1) . 'm', 'n'],
            ['Missed chats', $chats['missed_month'], 'n'],
            ['Transfers out / in', $transfers['out_month'] . ' / ' . $transfers['in_month'], 'n'],
        ]],
        'resolution' => ['label' => 'Resolution', 'icon' => 'fa-circle-check', 'color' => 'green', 'metrics' => [
            ['Open tickets', $tickets['open'], 'n'],
            ['Resolved this month', $tickets['resolved_month'], 'n'],
            ['Callbacks pending', $tickets['callbacks_pending'], 'n'],
            ['Waiting on us', $tickets['waiting_us'], 'n'],
        ]],
    ],
];

/* ================= Charts ================= */
// donut: where service leads come from (last 30 days)
$out['channel_mix'] = $all("SELECT COALESCE(NULLIF(l.lead_source,''),'unknown') channel, COUNT(*) c
    FROM leads l WHERE $LD AND l.created_at>=NOW()-INTERVAL 30 DAY GROUP BY channel ORDER BY c DESC LIMIT 8");
// donut: chat outcomes this month
$out['chat_outcomes'] = $all("SELECT cs.status, COUNT(*) c FROM chat_sessions cs
    WHERE $CS AND " . $mon('cs.started_at') . " GROUP BY cs.status");
// pie: tickets by assist type (this month)
$out['tickets_by_type'] = $all("SELECT COALESCE(NULLIF(t.assist_type,''),'other') type, COUNT(*) c
    FROM crm_tickets t WHERE $TK AND " . $mon('t.created_at') . " GROUP BY type ORDER BY c DESC");
// bar: last-7-days volume per channel
$out['weekly'] = $all("
    SELECT d.d,
      (SELECT COUNT(*) FROM chat_sessions cs WHERE $CS AND DATE(cs.started_at)=d.d) chats,
      (SELECT COUNT(*) FROM call_logs cl WHERE $CL AND DATE(cl.started_at)=d.d) calls,
      (SELECT COUNT(*) FROM whatsapp_messages wm JOIN whatsapp_threads wt ON wt.id=wm.thread_id
        WHERE $WT AND DATE(wm.created_at)=d.d) wa
    FROM (SELECT CURDATE()-INTERVAL n DAY d FROM
          (SELECT 0 n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6) t) d
    ORDER BY d.d");
// line: 6-month trend per channel + tickets
$out['monthly'] = $all("
    SELECT m.ym,
      (SELECT COUNT(*) FROM chat_sessions cs WHERE $CS AND DATE_FORMAT(cs.started_at,'%Y-%m')=m.ym) chats,
      (SELECT COUNT(*) FROM call_logs cl WHERE $CL AND DATE_FORMAT(cl.started_at,'%Y-%m')=m.ym) calls,
      (SELECT COUNT(*) FROM whatsapp_messages wm JOIN whatsapp_threads wt ON wt.id=wm.thread_id
        WHERE $WT AND DATE_FORMAT(wm.created_at,'%Y-%m')=m.ym) wa,
      (SELECT COUNT(*) FROM crm_tickets t WHERE $TK AND DATE_FORMAT(t.created_at,'%Y-%m')=m.ym) tickets
    FROM (SELECT DATE_FORMAT(CURDATE()-INTERVAL n MONTH,'%Y-%m') ym
          FROM (SELECT 0 n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5) t) m
    ORDER BY m.ym");

/* ================= Agent leaderboard (scoped: an exec sees only themselves) ================= */
$agentFilter = $isExec ? "AND e.id=$me" : '';
$out['agents'] = $all("
    SELECT e.full_name,
      (SELECT COUNT(*) FROM chat_sessions cs2 WHERE cs2.agent_id=e.id AND cs2.vertical='service'
        AND cs2.picked_at IS NOT NULL AND " . $mon('cs2.started_at') . ") chats,
      (SELECT ROUND(AVG(TIMESTAMPDIFF(SECOND,cs3.started_at,cs3.picked_at))) FROM chat_sessions cs3
        WHERE cs3.agent_id=e.id AND cs3.vertical='service' AND cs3.picked_at IS NOT NULL AND " . $mon('cs3.started_at') . ") avg_pickup_sec,
      (SELECT COUNT(*) FROM call_logs cl2 WHERE cl2.agent_id=e.id AND " . $mon('cl2.started_at') . ") calls,
      (SELECT COUNT(*) FROM crm_tickets t2 WHERE t2.owner_id=e.id AND t2.vertical='service'
        AND t2.status IN('closed','resolved') AND " . $mon('t2.updated_at') . ") tickets_closed
    FROM employees e JOIN sales_users su ON su.employee_id=e.id
    WHERE su.is_active=1 AND su.role IN ('service_exec','service_manager') $agentFilter
    HAVING chats>0 OR calls>0 OR tickets_closed>0
    ORDER BY chats DESC, tickets_closed DESC LIMIT 8");

/* ================= Live feeds ================= */
$out['recent_chats'] = $all("SELECT cs.id, cs.visitor_name, cs.status, cs.started_at, e.full_name agent
    FROM chat_sessions cs LEFT JOIN employees e ON e.id=cs.agent_id
    WHERE $CS ORDER BY cs.id DESC LIMIT 6");
$out['recent_tickets'] = $all("SELECT t.ticket_ref, t.subject, t.status, t.assist_type, t.waiting_on
    FROM crm_tickets t WHERE $TK ORDER BY t.updated_at DESC LIMIT 6");
$out['recent_transfers'] = $all("SELECT ct.action, ct.created_at, ct.from_vertical, ct.to_vertical,
      ef.full_name from_agent, et.full_name to_agent, cs.visitor_name
    FROM chat_transfers ct
    LEFT JOIN employees ef ON ef.id=ct.from_agent_id
    LEFT JOIN employees et ON et.id=ct.to_agent_id
    LEFT JOIN chat_sessions cs ON cs.id=ct.session_id
    WHERE (($TR_OUT) OR ($TR_IN) OR (ct.action='assign' AND ct.to_vertical='service'" . ($isExec ? " AND ct.to_agent_id=$me" : '') . "))
    ORDER BY ct.id DESC LIMIT 6");

ok($out);
