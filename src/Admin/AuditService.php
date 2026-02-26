<?php
namespace ContentCore\Admin;

/**
 * Service to handle auditing of administrative actions.
 */
class AuditService
{
    const LOG_OPTION = 'cc_admin_audit_log';
    const MAX_LOGS = 50;

    /**
     * Log an administrative action.
     *
     * @param string $action  The action name.
     * @param string $status  Status of the action (success, error, warning).
     * @param string $message Detailed message.
     */
    public function log_action(string $action, string $status, string $message): void
    {
        $logs = $this->get_logs();
        $user = wp_get_current_user();

        $new_entry = [
            'action' => $action,
            'status' => $status,
            'message' => $message,
            'user' => $user->display_name ?: $user->user_login,
            'timestamp' => current_time('mysql'),
        ];

        array_unshift($logs, $new_entry);

        // Cap at MAX_LOGS
        if (count($logs) > self::MAX_LOGS) {
            $logs = array_slice($logs, 0, self::MAX_LOGS);
        }

        update_option(self::LOG_OPTION, $logs, false);
    }

    /**
     * Get the audit logs.
     *
     * @return array
     */
    public function get_logs(): array
    {
        $logs = get_option(self::LOG_OPTION, []);
        return is_array($logs) ? $logs : [];
    }

    /**
     * Clear the audit logs.
     */
    public function clear_logs(): void
    {
        delete_option(self::LOG_OPTION);
    }
}