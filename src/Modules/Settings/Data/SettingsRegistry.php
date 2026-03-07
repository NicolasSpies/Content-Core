<?php
namespace ContentCore\Modules\Settings\Data;

/**
 * Registry to manage settings schemas, defaults, and validation.
 */
class SettingsRegistry
{
    private const LAST_SAVE_OPTION = 'cc_settings_last_save';
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
        $merged = $this->merge_for_key($key, $current, $new_data);
        $merged = $this->normalize_legacy_payload($key, $merged);

        // 3. Sanitize
        $sanitized = $this->sanitize($key, $merged);

        // 4. Persist
        $updated = update_option($key, $sanitized);

        // WP update_option returns false if the value hasn't changed.
        if (!$updated) {
            $current_in_db = get_option($key);
            if ($current_in_db === $sanitized) {
                \ContentCore\Logger::debug(sprintf('[CC Settings Registry] Settings unchanged for key: %s', $key));
                $this->record_last_save($key, $sanitized);
                return true;
            }
            \ContentCore\Logger::error(sprintf('[CC Settings Registry] update_option failed for key: %s', $key));
            return false;
        }

        $this->record_last_save($key, $sanitized);
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

    private function record_last_save(string $key, array $data): void
    {
        $last = get_option(self::LAST_SAVE_OPTION, []);
        if (!is_array($last)) {
            $last = [];
        }

        $last[$key] = [
            'timestamp' => current_time('mysql'),
            'fields' => $this->count_leaf_fields($data),
            'user_id' => get_current_user_id(),
        ];

        update_option(self::LAST_SAVE_OPTION, $last);
    }

    private function count_leaf_fields(array $data): int
    {
        $count = 0;
        array_walk_recursive($data, function () use (&$count) {
            $count++;
        });
        return $count;
    }

    private function normalize_legacy_payload(string $key, array $data): array
    {
        if ($key === 'cc_site_images') {
            if (isset($data['social_id']) && !isset($data['social_icon_id'])) {
                $data['social_icon_id'] = $data['social_id'];
            }
            if (isset($data['og_image_id']) && !isset($data['og_default_id'])) {
                $data['og_default_id'] = $data['og_image_id'];
            }
            if (isset($data['apple_touch_icon_id']) && !isset($data['apple_touch_id'])) {
                $data['apple_touch_id'] = $data['apple_touch_icon_id'];
            }
        }

        if ($key === 'cc_languages_settings' && isset($data['languages'])) {
            $languages = $data['languages'];
            if (is_array($languages)) {
                $is_associative = array_keys($languages) !== range(0, count($languages) - 1);
                if ($is_associative) {
                    $normalized = [];
                    foreach ($languages as $code => $entry) {
                        $normalized_code = sanitize_key((string) $code);
                        if ($normalized_code === '') {
                            continue;
                        }

                        $normalized_entry = [
                            'code' => $normalized_code,
                            'label' => strtoupper($normalized_code),
                            'flag_id' => 0,
                        ];

                        if (is_array($entry)) {
                            if (!empty($entry['code'])) {
                                $normalized_entry['code'] = sanitize_key((string) $entry['code']);
                            }
                            if (!empty($entry['label'])) {
                                $normalized_entry['label'] = sanitize_text_field((string) $entry['label']);
                            }
                            if (isset($entry['flag_id'])) {
                                $normalized_entry['flag_id'] = absint($entry['flag_id']);
                            }
                        } elseif (is_string($entry) && $entry !== '') {
                            $normalized_entry['label'] = sanitize_text_field($entry);
                        }

                        $normalized[] = $normalized_entry;
                    }
                    $data['languages'] = $normalized;
                }
            }
        }

        return $data;
    }

    private function merge_for_key(string $key, array $current, array $new_data): array
    {
        $merged = array_replace_recursive($current, $new_data);

        if ($key !== 'cc_languages_settings') {
            return $merged;
        }

        // Languages and base maps are list-like structures in the UI.
        // Replacing them avoids "deleted language comes back" merge artifacts.
        foreach (['languages', 'permalink_bases', 'taxonomy_bases'] as $replace_key) {
            if (array_key_exists($replace_key, $new_data)) {
                $merged[$replace_key] = is_array($new_data[$replace_key]) ? $new_data[$replace_key] : [];
            }
        }

        return $merged;
    }
}
