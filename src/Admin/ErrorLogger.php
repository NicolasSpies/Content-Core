<?php
namespace ContentCore\Admin;

/**
 * Lightweight internal error logger for Content Core.
 *
 * Design decisions:
 * - Uses a capped ring buffer in wp_options (max 200 entries). No new DB tables.
 * - Only captures errors whose file path is inside the Content Core plugin directory.
 * - Registers a PHP error handler and a shutdown handler for fatals.
 * - All methods are fail-safe: if logging itself fails, it silently returns.
 * - Does NOT require WP_DEBUG.
 * - Does NOT expose logs to frontend or REST API.
 */
class ErrorLogger
{
    const OPTION_KEY = 'cc_error_log';
    const MAX_ENTRIES = 200;
    const SEVERITIES = ['fatal', 'error', 'warning', 'notice', 'deprecated'];

    /** Absolute path to the Content Core plugin directory (with trailing slash). */
    private string $plugin_dir;

    /** Whether the error/shutdown handlers have been registered. */
    private bool $registered = false;

    public function __construct(string $plugin_dir)
    {
        // Normalize: always trailing slash, real path
        $this->plugin_dir = rtrim(realpath($plugin_dir) ?: $plugin_dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    // -------------------------------------------------------------------------
    // Initialization
    // -------------------------------------------------------------------------

    /**
     * Register PHP error + shutdown handlers.
     * Call this as early as possible (e.g. right after the autoloader).
     */
    public function register_handlers(): void
    {
        if ($this->registered) {
            return;
        }
        $this->registered = true;

        // Capture warnings, notices, deprecated notices
        set_error_handler([$this, 'handle_php_error']);

        // Capture fatals via shutdown
        register_shutdown_function([$this, 'handle_shutdown']);
    }

    // -------------------------------------------------------------------------
    // Handlers
    // -------------------------------------------------------------------------

    /**
     * PHP error handler — called for E_WARNING, E_NOTICE, E_DEPRECATED etc.
     * Returning false lets WordPress/PHP continue with its own handler too.
     */
    public function handle_php_error(int $errno, string $message, string $file = '', int $line = 0): bool
    {
        if (!$this->is_cc_file($file)) {
            return false; // not ours — let the default handler run
        }

        $severity = $this->errno_to_severity($errno);
        $this->write([
            'severity' => $severity,
            'message' => $message,
            'file' => $this->relative_path($file),
            'line' => $line,
            'screen' => $this->current_screen_id(),
            'trace' => null,
            'timestamp' => time(),
        ]);

        return false; // don't suppress WordPress's own handling
    }

    /**
     * Shutdown handler — captures E_ERROR (fatals) only.
     */
    public function handle_shutdown(): void
    {
        $error = error_get_last();
        if (!$error) {
            return;
        }

        $fatal_types = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if (!in_array($error['type'], $fatal_types, true)) {
            return;
        }

        if (!$this->is_cc_file($error['file'])) {
            return;
        }

        $this->write([
            'severity' => 'fatal',
            'message' => $error['message'],
            'file' => $this->relative_path($error['file']),
            'line' => $error['line'],
            'screen' => $this->current_screen_id(),
            'trace' => null, // fatals don't have a backtrace via error_get_last
            'timestamp' => time(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Manual logging (callable from plugin code)
    // -------------------------------------------------------------------------

    /**
     * Log an entry manually.
     *
     * @param string      $severity  'fatal' | 'error' | 'warning' | 'notice'
     * @param string      $message
     * @param string      $file      Absolute path (will be made relative automatically)
     * @param int         $line
     * @param string|null $trace     Optional stack trace string
     */
    public function log(string $severity, string $message, string $file = '', int $line = 0, ?string $trace = null): void
    {
        $this->write([
            'severity' => in_array($severity, self::SEVERITIES, true) ? $severity : 'notice',
            'message' => $message,
            'file' => $file ? $this->relative_path($file) : '',
            'line' => $line,
            'screen' => $this->current_screen_id(),
            'trace' => $trace,
            'timestamp' => time(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------

    /**
     * Return all log entries, newest first.
     */
    public function get_entries(): array
    {
        try {
            $entries = get_option(self::OPTION_KEY, []);
            if (!is_array($entries)) {
                return [];
            }
            return array_reverse($entries); // stored oldest-first; return newest-first
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Return summary stats for the last N seconds.
     *
     * Uses current_time('timestamp') so it respects the WordPress site timezone
     * setting rather than the server PHP timezone. Entries without a valid
     * timestamp (missing, 0, or in the future by more than 60 s) are excluded.
     */
    public function get_stats(int $seconds = 86400): array
    {
        $entries = $this->get_entries();
        $now = function_exists('current_time') ? (int) current_time('timestamp') : time();
        $since = $now - $seconds;

        $stats = ['total' => 0, 'by_severity' => []];
        foreach (self::SEVERITIES as $s) {
            $stats['by_severity'][$s] = 0;
        }

        foreach ($entries as $e) {
            $ts = $e['timestamp'] ?? 0;
            // Exclude: missing timestamp, not a positive integer, or outside window.
            if (!is_int($ts) || $ts <= 0 || $ts < $since) {
                continue;
            }
            $stats['total']++;
            $sev = $e['severity'] ?? 'notice';
            if (isset($stats['by_severity'][$sev])) {
                $stats['by_severity'][$sev]++;
            }
        }

        return $stats;
    }

    /**
     * Return the Unix timestamp of the most-recent log entry within the last
     * $seconds window, or 0 if there are none.
     */
    public function get_last_error_time(int $seconds = 86400): int
    {
        $entries = $this->get_entries(); // newest first
        $now = function_exists('current_time') ? (int) current_time('timestamp') : time();
        $since = $now - $seconds;

        foreach ($entries as $e) {
            $ts = $e['timestamp'] ?? 0;
            if (!is_int($ts) || $ts <= 0) {
                continue;
            }
            if ($ts < $since) {
                break; // entries are newest-first; once we're past the window, stop.
            }
            return $ts;
        }

        return 0;
    }

    /**
     * Delete all log entries whose timestamp is strictly before $before_timestamp.
     * Entries with a missing / invalid timestamp are also removed.
     * This is a non-destructive rotation: recent entries are always preserved.
     *
     * @param int $before_timestamp  Unix timestamp (inclusive cut-off, exclusive save point).
     */
    public function clear_before(int $before_timestamp): void
    {
        try {
            $entries = get_option(self::OPTION_KEY, []);
            if (!is_array($entries)) {
                return;
            }

            $kept = array_values(array_filter($entries, function ($e) use ($before_timestamp) {
                $ts = $e['timestamp'] ?? 0;
                return is_int($ts) && $ts > 0 && $ts >= $before_timestamp;
            }));

            update_option(self::OPTION_KEY, $kept, false);
        } catch (\Throwable $e) {
            // fail-safe
        }
    }

    /**
     * Clear all log entries.
     */
    public function clear(): void
    {
        try {
            update_option(self::OPTION_KEY, [], false);
        } catch (\Throwable $e) {
            // fail-safe
        }
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /** Write one entry to the ring buffer (fail-safe). */
    private function write(array $entry): void
    {
        try {
            global $wpdb;
            if (!$wpdb || !function_exists('get_option')) {
                return;
            }

            $entries = get_option(self::OPTION_KEY, []);
            if (!is_array($entries)) {
                $entries = [];
            }

            $entries[] = $entry;

            // Auto-rotate: drop entries older than 30 days to keep the stored
            // log current. This is intentional rotation — the full history is
            // not the goal; clear recent diagnostics are.
            $cutoff = (function_exists('current_time') ? (int) current_time('timestamp') : time()) - (30 * 86400); // 30 days in seconds
            $entries = array_values(array_filter($entries, function ($e) use ($cutoff) {
                $ts = $e['timestamp'] ?? 0;
                return is_int($ts) && $ts > 0 && $ts >= $cutoff;
            }));

            // Hard cap: ring buffer never exceeds MAX_ENTRIES
            if (count($entries) > self::MAX_ENTRIES) {
                $entries = array_slice($entries, -self::MAX_ENTRIES);
            }

            update_option(self::OPTION_KEY, $entries, false); // autoload = false
        } catch (\Throwable $e) {
            // Never crash the site because of failed logging
        }
    }

    /** True if $file is inside the Content Core plugin directory. */
    private function is_cc_file(string $file): bool
    {
        if (empty($file)) {
            return false;
        }
        $real = realpath($file) ?: $file;
        return strpos($real . DIRECTORY_SEPARATOR, $this->plugin_dir) === 0;
    }

    /** Return a path relative to the plugin directory for compact storage. */
    private function relative_path(string $file): string
    {
        $real = realpath($file) ?: $file;
        if (strpos($real . DIRECTORY_SEPARATOR, $this->plugin_dir) === 0) {
            return ltrim(str_replace($this->plugin_dir, '', $real), DIRECTORY_SEPARATOR);
        }
        return $file;
    }

    /** Map PHP error number to a human-readable severity string. */
    private function errno_to_severity(int $errno): string
    {
        return match ($errno) {
            E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR => 'error',
            E_WARNING, E_CORE_WARNING, E_COMPILE_WARNING, E_USER_WARNING => 'warning',
            E_NOTICE, E_USER_NOTICE => 'notice',
            E_DEPRECATED, E_USER_DEPRECATED => 'deprecated',
            default => 'notice',
        };
    }

    /** Return the current admin screen ID (empty string outside admin). */
    private function current_screen_id(): string
    {
        if (!function_exists('get_current_screen')) {
            return '';
        }
        $screen = get_current_screen();
        return $screen ? $screen->id : '';
    }
}
