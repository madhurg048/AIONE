<?php
/**
 * Shared gate for the cron entrypoints.
 *
 * These scripts run happily under both CLI and HTTP (they branch on PHP_SAPI),
 * but over HTTP they had NO authentication — anyone who could reach
 * /cron/run-sales-automations.php could fire the whole automation sweep
 * (reassignments, customer emails, escalations) as often as they liked.
 *
 * Why HTTP access is needed at all: Azure App Service on Linux has no cron
 * daemon, so the usual every-30-minutes crontab line has nowhere to live. The
 * supported pattern is an external scheduler (Azure Logic App / Function timer,
 * or `curl` from any box that already runs cron) calling these over HTTPS.
 *
 * Rules:
 *   - CLI  -> always allowed (a shell on the box is already trusted).
 *   - HTTP -> requires CRON_TOKEN to be set in the environment AND presented as
 *             ?token=... or an X-Cron-Token header. Compared with hash_equals()
 *             so the check is not timing-attackable.
 *   - HTTP with no CRON_TOKEN configured -> refused. Fails closed, so forgetting
 *             to set it can never leave the endpoints wide open.
 */

require_once __DIR__ . '/../config/env.php';

if (PHP_SAPI === 'cli') {
    return;
}

$expected = (string) env('CRON_TOKEN', '');

if ($expected === '') {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Not found']);
    error_log('cron: HTTP invocation refused — CRON_TOKEN is not set in the environment');
    exit(1);
}

$supplied = (string) ($_GET['token'] ?? $_SERVER['HTTP_X_CRON_TOKEN'] ?? '');

if ($supplied === '' || !hash_equals($expected, $supplied)) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Not found']);
    error_log('cron: HTTP invocation refused — bad or missing token from ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
    exit(1);
}

// Scheduled sweeps can outlive the default web timeout.
@set_time_limit(600);
ignore_user_abort(true);
