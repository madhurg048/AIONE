<?php

/**
 * Loads KEY=VALUE pairs from a .env file in the project root into the environment.
 * Real environment variables (Azure App Service → Application Settings) always win, so
 * this is only a convenience for servers/dev boxes that have no env plumbing.
 */
if (!function_exists('payg_load_env')) {
    function payg_load_env(?string $file = null): void
    {
        static $loaded = false;
        if ($loaded) return;
        $loaded = true;

        $file = $file ?: dirname(__DIR__) . '/.env';
        if (!is_readable($file)) return;

        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;

            [$key, $value] = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value);
            if ($key === '') continue;

            // strip one layer of surrounding quotes, if present
            $len = strlen($value);
            if ($len >= 2 && (($value[0] === '"' && $value[$len - 1] === '"') || ($value[0] === "'" && $value[$len - 1] === "'"))) {
                $value = substr($value, 1, -1);
            }

            // never clobber a real environment variable
            if (getenv($key) !== false) continue;
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}
payg_load_env();

if (!function_exists('env_flag')) {
    function env_flag(string $name, bool $default = false): bool
    {
        $value = getenv($name);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        $normalized = strtolower(trim((string)$value));
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }
}

if (!function_exists('payg_is_https')) {
    function payg_is_https(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
            return true;
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
            return true;
        }
        return false;
    }
}

if (!function_exists('payg_start_session')) {
    function payg_start_session(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_secure', payg_is_https() ? '1' : '0');
        ini_set('session.cookie_samesite', 'Lax');
        session_name('PAYGSESSID');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => payg_is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

if (!function_exists('payg_csrf_token')) {
    function payg_csrf_token(): string
    {
        payg_start_session();
        if (empty($_SESSION['_csrf_token']) || !is_string($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf_token'];
    }
}

if (!function_exists('payg_rotate_csrf_token')) {
    function payg_rotate_csrf_token(): string
    {
        payg_start_session();
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        return $_SESSION['_csrf_token'];
    }
}

if (!function_exists('payg_verify_csrf_request')) {
    function payg_raw_input(): string
    {
        if (!array_key_exists('__payg_raw_input', $GLOBALS)) {
            $GLOBALS['__payg_raw_input'] = file_get_contents('php://input') ?: '';
        }
        return (string)$GLOBALS['__payg_raw_input'];
    }

    function payg_verify_csrf_request(): bool
    {
        payg_start_session();
        $sessionToken = $_SESSION['_csrf_token'] ?? '';
        if (!is_string($sessionToken) || $sessionToken === '') {
            return false;
        }

        $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (is_string($headerToken) && $headerToken !== '' && hash_equals($sessionToken, $headerToken)) {
            return true;
        }

        $body = json_decode(payg_raw_input(), true);
        $bodyToken = is_array($body) ? ($body['_csrf'] ?? '') : ($_POST['_csrf'] ?? '');
        return is_string($bodyToken) && $bodyToken !== '' && hash_equals($sessionToken, $bodyToken);
    }
}

if (!function_exists('payg_sample_login_enabled')) {
    function payg_sample_login_enabled(): bool
    {
        // DEFAULT IS OFF so production is safe even where .htaccess is ignored (nginx / Azure
        // App Service). Dev boxes opt in with PAYG_ENABLE_SAMPLE_LOGIN=true.
        return env_flag('PAYG_ENABLE_SAMPLE_LOGIN', false);
    }
}
