<?php
/**
 * env.php — tiny dotenv loader + typed accessors.
 * ----------------------------------------------------------------------------
 * This project has no Composer/vendor dir, so this is a dependency-free reader.
 *
 * Precedence (highest first):
 *   1. Real environment variables  — on Azure App Service these are the
 *      "Environment variables → App settings" entries. ALWAYS win.
 *   2. The .env file next to the app root (local dev / self-hosted VM).
 *   3. The $default passed at the call site.
 *
 * Because (1) beats (2), you can ship a .env for the VM today and later move to
 * Azure App Settings without touching a single line of code — and a stale .env
 * accidentally left in the image can never override the portal values.
 *
 * .env format: KEY=value, one per line. `#` starts a comment. Values may be
 * wrapped in single or double quotes (quotes are stripped). Blank lines ignored.
 */

if (!function_exists('payg_load_env')) {
    function payg_load_env(?string $path = null): void
    {
        static $loaded = false;
        if ($loaded) return;
        $loaded = true;

        $path = $path ?: dirname(__DIR__) . '/.env';
        if (!is_readable($path)) return;

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) return;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;

            $pos = strpos($line, '=');
            if ($pos === false) continue;

            $key = trim(substr($line, 0, $pos));
            $val = trim(substr($line, $pos + 1));
            if ($key === '') continue;

            // Strip a matching pair of surrounding quotes, if present.
            $len = strlen($val);
            if ($len >= 2
                && (($val[0] === '"' && $val[$len - 1] === '"')
                 || ($val[0] === "'" && $val[$len - 1] === "'"))) {
                $val = substr($val, 1, -1);
            }

            // Real env vars (Azure App Settings) win — never clobber them.
            if (getenv($key) !== false) continue;

            putenv("$key=$val");
            $_ENV[$key] = $val;
        }
    }
}

if (!function_exists('env')) {
    /** Raw string from the environment, or $default when unset/empty. */
    function env(string $key, $default = null)
    {
        payg_load_env();
        $v = getenv($key);
        if ($v === false || $v === '') {
            return $default;
        }
        return $v;
    }
}

if (!function_exists('env_bool')) {
    /** Truthy env value: 1/true/yes/on (case-insensitive). */
    function env_bool(string $key, bool $default = false): bool
    {
        $v = env($key);
        if ($v === null) return $default;
        return in_array(strtolower(trim((string)$v)), ['1', 'true', 'yes', 'on'], true);
    }
}

if (!function_exists('env_int')) {
    function env_int(string $key, int $default = 0): int
    {
        $v = env($key);
        return $v === null ? $default : (int)$v;
    }
}

// Load immediately so anything that only does getenv() (e.g. config/app.php's
// env_flag(), ai-bridge.php) also sees the .env values.
payg_load_env();
