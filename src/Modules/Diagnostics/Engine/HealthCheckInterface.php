<?php
namespace ContentCore\Modules\Diagnostics\Engine;

interface HealthCheckInterface
{
    /** Unique ID for the check suite */
    public function get_id(): string;

    /** Human-readable name */
    public function get_name(): string;

    /** Category (e.g. multilingual, settings, structural) */
    public function get_category(): string;

    /**
     * Run the check. Must be strictly read-only.
     * 
     * @return HealthCheckResult[] Array of issues found.
     */
    public function run_check(): array;

    /**
     * Return a preview of what `apply_fix` will do, given the issue ID and context data.
     */
    public function get_fix_preview(string $issue_id, $context_data = null): ?array;

    /**
     * Actually perform the fix. Must not touch WP core tables outside CC scope.
     * Returns true on success, or an error string/WP_Error.
     */
    public function apply_fix(string $issue_id, $context_data = null);
}
