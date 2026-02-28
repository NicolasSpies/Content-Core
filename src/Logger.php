<?php
namespace ContentCore;

/**
 * Centralized logging utility for Content Core.
 */
class Logger
{
    /**
     * Log an informational message.
     *
     * @param string $message The message to log.
     * @param array $context Additional context data.
     * @return void
     */
    public static function info(string $message, array $context = []): void
    {
        self::log('INFO', $message, $context);
    }

    /**
     * Log a warning message.
     *
     * @param string $message The message to log.
     * @param array $context Additional context data.
     * @return void
     */
    public static function warning(string $message, array $context = []): void
    {
        self::log('WARNING', $message, $context);
    }

    /**
     * Log an error message.
     *
     * @param string $message The message to log.
     * @param array $context Additional context data.
     * @return void
     */
    public static function error(string $message, array $context = []): void
    {
        self::log('ERROR', $message, $context);
    }

    /**
     * Log a debug message (only if WP_DEBUG is enabled).
     *
     * @param string $message The message to log.
     * @param array $context Additional context data.
     * @return void
     */
    public static function debug(string $message, array $context = []): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            self::log('DEBUG', $message, $context);
        }
    }

    /**
     * Internal log handler.
     *
     * @param string $level Log level (INFO, WARNING, ERROR, DEBUG).
     * @param string $message The message to log.
     * @param array $context Additional context data.
     * @return void
     */
    private static function log(string $level, string $message, array $context): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $context_str = !empty($context) ? ' ' . json_encode($context) : '';
        $log_entry = sprintf("[%s] [%s] %s%s\n", $timestamp, $level, $message, $context_str);

        $log_file = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/content-core.log' : dirname(__FILE__, 2) . '/content-core.log';

        // Use error_log with destination type 3 to append to our specific log file.
        // We suppress errors in case the file is not writable.
        @error_log($log_entry, 3, $log_file);
    }
}
