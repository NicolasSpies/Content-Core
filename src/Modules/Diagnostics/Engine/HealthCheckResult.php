<?php
namespace ContentCore\Modules\Diagnostics\Engine;

class HealthCheckResult
{
    /** @var string ok|warning|critical */
    public $status;

    /** @var string */
    public $message;

    /** @var bool */
    public $can_fix;

    /** @var mixed */
    public $fix_preview_data;

    /** @var string */
    public $issue_id;

    public function __construct(string $issue_id, string $status, string $message, bool $can_fix = false, $fix_preview_data = null)
    {
        $this->issue_id = $issue_id;
        $this->status = $status;
        $this->message = $message;
        $this->can_fix = $can_fix;
        $this->fix_preview_data = $fix_preview_data;
    }
}
