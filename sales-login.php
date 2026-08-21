<?php
/**
 * Legacy sign-in URL.
 *
 * The sign-in screen is served by index.php now (includes/login-page.php),
 * so this file only forwards. Kept so old bookmarks, e-mailed links and any
 * cached "/sales-login" URL on the deployed host keep working instead of 404ing.
 *
 * The Location header is RELATIVE on purpose: it resolves against whatever host
 * and folder the app is served from (https://aione.payg.in/index.php in
 * production, http://localhost/frontend/index.php in dev) with no hard-coded domain.
 */
require_once __DIR__ . '/config/app.php';
payg_start_session();

// Already signed in? index.php sends the user on to their own home screen.
// Signed in? index.php sends the user on to their own home screen either way.
header('Location: index.php', true, 302);
exit;
