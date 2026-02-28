<?php
namespace ContentCore\Modules\Diagnostics\Engine;

class HealthCheckRegistry
{
    /** @var HealthCheckInterface[] */
    private $checks = [];

    private const LOG_OPTION_KEY = 'cc_diagnostics_log';
    private const MAX_LOG_ENTRIES = 500;

    public function register(HealthCheckInterface $check): void
    {
        $this->checks[$check->get_id()] = $check;
    }

    public function get_check(string $id): ?HealthCheckInterface
    {
        return $this->checks[$id] ?? null;
    }

    public function get_all(): array
    {
        return $this->checks;
    }

    /**
     * Executes all registered checks and updates the persistent log.
     */
    public function run_all_checks(): array
    {
        $current_log = get_option(self::LOG_OPTION_KEY, []);
        if (!is_array($current_log)) {
            $current_log = [];
        }

        $scan_time = time();
        $found_issues_keys = [];

        foreach ($this->checks as $check) {
            $results = $check->run_check();

            foreach ($results as $result) {
                // Determine a unique identifier for this exact issue
                $issue_uid = md5($check->get_id() . '|' . $result->issue_id);
                $found_issues_keys[] = $issue_uid;

                if (isset($current_log[$issue_uid])) {
                    $current_log[$issue_uid]['last_seen'] = $scan_time;
                    $current_log[$issue_uid]['status'] = 'active';
                    $current_log[$issue_uid]['message'] = $result->message;
                    $current_log[$issue_uid]['severity'] = $result->status;
                    $current_log[$issue_uid]['can_fix'] = $result->can_fix;
                    $current_log[$issue_uid]['fix_preview_data'] = $result->fix_preview_data;
                } else {
                    $current_log[$issue_uid] = [
                        'issue_id' => $result->issue_id,
                        'check_id' => $check->get_id(),
                        'first_seen' => $scan_time,
                        'last_seen' => $scan_time,
                        'status' => 'active',
                        'message' => $result->message,
                        'severity' => $result->status,
                        'can_fix' => $result->can_fix,
                        'fix_preview_data' => $result->fix_preview_data
                    ];
                }
            }
        }

        // Mark any active issues that were NOT found in this scan as 'resolved'
        foreach ($current_log as $uid => &$entry) {
            if ($entry['status'] === 'active' && !in_array($uid, $found_issues_keys, true)) {
                $entry['status'] = 'resolved';
                $entry['last_scan'] = $scan_time; // It was scanned during this time and found resolved
            } else {
                $entry['last_scan'] = $scan_time;
            }
        }
        unset($entry); // break reference

        // Enforce Ring Buffer Cap (keep newest/active first if sorting needed, but here we just slice by first_seen)
        if (count($current_log) > self::MAX_LOG_ENTRIES) {
            // Sort by first_seen DESC to keep newest, or keep active ones prioritized.
            // Let's sort to keep active ones first, then newest resolved.
            uasort($current_log, function ($a, $b) {
                if ($a['status'] === 'active' && $b['status'] !== 'active')
                    return -1;
                if ($a['status'] !== 'active' && $b['status'] === 'active')
                    return 1;
                return $b['first_seen'] <=> $a['first_seen'];
            });
            $current_log = array_slice($current_log, 0, self::MAX_LOG_ENTRIES, true);
        }

        update_option(self::LOG_OPTION_KEY, $current_log);

        return $current_log;
    }

    public function get_log(): array
    {
        $log = get_option(self::LOG_OPTION_KEY, []);
        return is_array($log) ? $log : [];
    }

    public function clear_resolved(): int
    {
        $log = $this->get_log();
        $cleared = 0;
        foreach ($log as $uid => $entry) {
            if ($entry['status'] === 'resolved') {
                unset($log[$uid]);
                $cleared++;
            }
        }

        if ($cleared > 0) {
            update_option(self::LOG_OPTION_KEY, $log);
        }

        return $cleared;
    }

    public function clear_all(): int
    {
        $log = $this->get_log();
        $count = count($log);
        delete_option(self::LOG_OPTION_KEY);
        return $count;
    }
}
