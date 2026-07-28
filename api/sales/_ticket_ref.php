<?php
/**
 * Ticket ref generation — deliberately dependency-free (no session/CSRF, just PDO) so both
 * the authenticated Sales endpoints (_bootstrap.php requires this) AND the public,
 * unauthenticated chatbot-ticket.php (which must NOT pull in _bootstrap.php's session-start +
 * CSRF gate — it's a PUBLIC inbound endpoint, no login) can share the same sequence.
 */

if (!function_exists('lead_completeness_score')) {
    /**
     * Triage score (0-100) from data completeness + source quality. Lives here, next to the
     * ref generator, for the same reason: it must be callable from BOTH the authenticated
     * Sales endpoints and the public chatbot path, which can't pull in _bootstrap.php.
     *
     * Previously this was inlined in leads.php's POST handler only, so every lead the chatbot
     * created was left at score 0 — the Leads list showed a real enquiry as unscored/cold and
     * reps triaged past it. Any new lead-creating path must call this.
     */
    function lead_completeness_score(array $f): int
    {
        $sc = 0;
        if (!empty($f['email']))                        $sc += 25;
        if (!empty($f['phone']) || !empty($f['mobile'])) $sc += 20;
        if (!empty($f['company']))                      $sc += 15;
        if (!empty($f['title']))                        $sc += 10;
        if (!empty($f['industry']))                     $sc += 10;
        $srcBonus = ['referral'=>20,'campaign'=>12,'live_chat'=>12,'chatbot'=>12,'whatsapp'=>10,
                     'web'=>10,'call'=>8,'qr_event'=>8,'service_referral'=>12,'manual'=>5];
        return min(100, $sc + ($srcBonus[$f['lead_source'] ?? 'manual'] ?? 5));
    }
}

if (!function_exists('next_ticket_ref_and_insert')) {
    /**
     * Generate the next ticket ref and insert it, sharing ONE global per-year sequence across
     * every source (chatbot's CHAT-2026-NNNNN, a manually-created one from Sales' "New
     * Lead"/Edit-ticket flow, etc.) — only the 3-letter prefix differs, so an agent can tell
     * where a ticket came from at a glance, e.g. CHAT-2026-00046 (chatbot) then SLS-2026-00047
     * (sales-created). Sharing the counter means the tickets list — which sorts newest-first by
     * ticket_ref — stays in true creation order across sources, instead of one prefix's tickets
     * always sorting above another's regardless of when they were actually created (string
     * compare puts 'S' > 'C', so a differently-prefixed ref would otherwise camp at the top of
     * the list forever no matter its actual sequence number).
     *
     * $insertFn receives the generated ref and must perform the INSERT (returning its result is
     * up to the caller) — the row-generation lock is held across ref-compute AND insert, not
     * just the read, otherwise two concurrent submissions from different sources/prefixes could
     * both compute the same "next" number before either commits (same race chatbot-ticket.php's
     * original ref generator hit: COUNT/MAX-then-INSERT is racy without a lock spanning both).
     */
    function next_ticket_ref_and_insert(PDO $db, string $prefix, callable $insertFn)
    {
        $lockName = 'ticket_ref_gen';
        $gotLock = (bool)$db->query("SELECT GET_LOCK(" . $db->quote($lockName) . ", 5)")->fetchColumn();
        try {
            $year = date('Y');
            // Every ticket_ref (any prefix) follows PFX-YYYY-NNNNN — the numeric suffix after
            // the last dash is the shared sequence for that year, independent of prefix.
            $maxNum = (int)$db->query(
                "SELECT MAX(CAST(SUBSTRING_INDEX(ticket_ref, '-', -1) AS UNSIGNED))
                 FROM crm_tickets WHERE ticket_ref LIKE '%-$year-%'"
            )->fetchColumn();
            $next = $maxNum + 1;
            $ticketRef = $prefix . '-' . $year . '-' . str_pad((string)$next, 5, '0', STR_PAD_LEFT);
            $result = $insertFn($ticketRef);
            return [$ticketRef, $result];
        } finally {
            if ($gotLock) $db->query("SELECT RELEASE_LOCK(" . $db->quote($lockName) . ")");
        }
    }
}
