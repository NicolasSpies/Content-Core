<?php
namespace ContentCore\Modules\Settings\Data;

/**
 * Registry to manage settings schemas, defaults, and validation.
 */
class SettingsRegistry
{
    private array $registry = [];

    /**
     * Register a settings key with its schema and defaults.
     */
    public function register(string $key, array $schema): void
    {
        $this->registry[$key] = array_merge([
            'default' => [],
            'sanitize_callback' => null,
            'capability' => 'manage_options'
        ], $schema);
    }

    /**
     * Get the registered schema for a key.
     */
    public function get_schema(string $key): ?array
    {
        return $this->registry[$key] ?? null;
    }

    /**
     * Get defaults for a key.
     */
    public function get_defaults(string $key): array
    {
        return $this->registry[$key]['default'] ?? [];
    }

    /**
     * Get the full registry.
     */
    public function get_all(): array
    {
        return $this->registry;
    }

    /**
     * Get settings with defaults enforced.
     */
    public function get(string $key): array
    {
        $defaults = $this->get_defaults($key);
        $settings = get_option($key, $defaults);
        
        // Ensure $settings is an array for array_replace_recursive in PHP 8
        if (!is_array($settings)) {
            $settings = [];
        }

        return array_replace_recursive((array) $defaults, (array) $settings);
    }

    /**
     * Atomically save/merge settings.
     */
    public function save(string $key, array $new_data): bool
    {
        $schema = $this->get_schema($key);
        if (!$schema) {
            return false;
        }

        // 1. Get current (with defaults)
        $current = $this->get($key);

        // 2. Merge
        $merged = array_replace_recursive($current, $new_data);

        // 3. Sanitize
        $sanitized = $this->sanitize($key, $merged);

        // 4. Persist
        $updated = update_option($key, $sanitized);

        // WP update_option returns false if the value hasn't changed. 
        // We only want to return false if the validation fails or if there's a database error.
        // If the schema exists and we got here, we consider it a success if not explicitly failing.
        // Actually, we can check if the current option value matches $sanitized.
        if (!$updated && get_option($key) !== $sanitized) {
            \ContentCore\Logger::error(sprintf('[CC Settings Registry] update_option failed for key: %s', $key));
            return false;
        }

        \ContentCore\Logger::debug(sprintf('[CC Settings Registry] Settings saved successfully for key: %s', $key));
        return true;
    }

    /**
     * Sanitize data based on registered callback.
     */
    public function sanitize(string $key, array $data): array
    {
        $schema = $this->get_schema($key);
        if (!$schema || !isset($schema['sanitize_callback']) || !is_callable($schema['sanitize_callback'])) {
            return $data;
        }

        return call_user_func($schema['sanitize_callback'], $data);
    }
}
