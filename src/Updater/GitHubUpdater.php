<?php

namespace ContentCore\Updater;

class GitHubUpdater
{
    private string $repo_owner = 'NicolasSpies';
    private string $repo_name = 'Content-Core';

    private string $plugin_slug;
    private string $plugin_file;

    public function __construct(string $plugin_file)
    {
        $this->plugin_file = $plugin_file;
        $this->plugin_slug = plugin_basename($plugin_file); // e.g. content-core/content-core.php
    }

    public function init(): void
    {
        add_filter('site_transient_update_plugins', [$this, 'check_update']);
        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_update']);
        add_filter('plugins_api', [$this, 'plugin_popup'], 20, 3);

        // Critical: fix extracted folder name if GitHub zip uses a random root folder
        add_filter('upgrader_source_selection', [$this, 'fix_extracted_folder_name'], 10, 4);
        add_filter('upgrader_post_install', [$this, 'ensure_plugin_installed_in_correct_dir'], 10, 3);
    }

    public function check_update($transient)
    {
        if (empty($transient->checked) || !is_object($transient)) {
            return $transient;
        }

        $remote = $this->get_remote_release();
        if (!$remote || empty($remote->tag_name)) {
            return $transient;
        }

        $local_version = $this->get_local_version();
        $remote_version = ltrim((string) $remote->tag_name, 'v');

        if (version_compare($local_version, $remote_version, '<')) {
            $asset = $this->get_zip_asset($remote);

            if ($asset && !empty($asset->browser_download_url)) {
                $obj = new \stdClass();
                $obj->slug = $this->plugin_slug;
                $obj->new_version = $remote_version;
                $obj->url = "https://github.com/{$this->repo_owner}/{$this->repo_name}";
                $obj->package = $asset->browser_download_url;
                $obj->tested = get_bloginfo('version');

                $transient->response[$this->plugin_slug] = $obj;
            }
        }

        return $transient;
    }

    public function plugin_popup($result, $action, $args)
    {
        $slug = is_object($args) && isset($args->slug) && is_string($args->slug) ? $args->slug : '';
        if ($action !== 'plugin_information' || ($slug !== $this->plugin_slug && $slug !== dirname($this->plugin_slug))) {
            return $result;
        }

        $remote = $this->get_remote_release();
        if (!$remote) {
            return $result;
        }

        $asset = $this->get_zip_asset($remote);

        $res = new \stdClass();
        $res->name = 'Content Core';
        $res->slug = $this->plugin_slug;
        $res->version = ltrim((string) $remote->tag_name, 'v');
        $res->author = 'Nicolas Spies';
        $res->homepage = "https://github.com/{$this->repo_owner}/{$this->repo_name}";
        $res->download_link = $asset->browser_download_url ?? '';
        $res->sections = [
            'description' => 'Modular internal agency framework for headless WordPress projects.',
            'changelog' => wp_kses_post(nl2br((string) ($remote->body ?? ''))),
        ];
        $res->last_updated = (string) ($remote->published_at ?? '');

        return $res;
    }

    private function get_remote_release()
    {
        $cache_key = 'content_core_github_release';
        $remote = get_site_transient($cache_key);

        if ($remote !== false) {
            return $remote;
        }

        $url = "https://api.github.com/repos/{$this->repo_owner}/{$this->repo_name}/releases/latest";

        $headers = [
            'Accept' => 'application/vnd.github.v3+json',
            'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url'),
        ];

        $token = get_option('content_core_github_token');
        if ($token) {
            $headers['Authorization'] = 'token ' . $token;
        }

        $response = wp_remote_get($url, [
            'timeout' => 15,
            'headers' => $headers,
        ]);

        if (is_wp_error($response)) {
            $this->log_error('GitHub API error: ' . $response->get_error_message());
            return false;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            if ($code === 403 || $code === 404) {
                return false;
            }
            $this->log_error('GitHub API HTTP Error ' . $code);
            return false;
        }

        $remote = json_decode((string) wp_remote_retrieve_body($response));
        if (!$remote || (!empty($remote->prerelease) && $remote->prerelease)) {
            return false;
        }

        set_site_transient($cache_key, $remote, 12 * HOUR_IN_SECONDS);
        return $remote;
    }

    private function get_zip_asset($remote)
    {
        if (empty($remote->assets) || !is_array($remote->assets)) {
            return null;
        }

        // Prefer a stable, explicit asset name first
        $preferred_names = [
            'Content-Core.zip',
            'content-core.zip',
            'Content Core.zip',
            'content core.zip',
        ];

        $zip_assets = [];
        foreach ($remote->assets as $asset) {
            $name = strtolower((string) ($asset->name ?? ''));
            $type = strtolower((string) ($asset->content_type ?? ''));

            $is_zip = str_ends_with($name, '.zip') || $type === 'application/zip' || $type === 'application/x-zip-compressed';
            if (!$is_zip) {
                continue;
            }

            $zip_assets[] = $asset;
        }

        if (!$zip_assets) {
            return null;
        }

        foreach ($preferred_names as $p) {
            $p = strtolower($p);
            foreach ($zip_assets as $asset) {
                $name = strtolower((string) ($asset->name ?? ''));
                if ($name === $p) {
                    return $asset;
                }
            }
        }

        // Fallback: first zip asset
        return $zip_assets[0];
    }

    private function get_local_version(): string
    {
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugin_data = get_plugin_data($this->plugin_file);
        return (string) ($plugin_data['Version'] ?? '0.0.0');
    }

    /**
     * If GitHub zip extracts into a random folder name, rename it to the plugin folder.
     * This runs before install moves files into final location.
     */
    public function fix_extracted_folder_name($source, $remote_source, $upgrader, $hook_extra)
    {
        if (!$this->is_our_plugin_upgrade($hook_extra)) {
            return $source;
        }

        $desired_dir_name = dirname($this->plugin_slug); // e.g. content-core
        $source = untrailingslashit((string) $source);

        if (!is_dir($source)) {
            return $source;
        }

        $current_dir_name = basename($source);
        if ($current_dir_name === $desired_dir_name) {
            return $source;
        }

        $parent = dirname($source);
        $target = trailingslashit($parent) . $desired_dir_name;

        // If target exists, clear it (WordPress usually handles this, but be defensive)
        if (is_dir($target)) {
            $this->rmdir_recursive($target);
        }

        $renamed = @rename($source, $target);
        if ($renamed) {
            return $target;
        }

        return $source;
    }

    /**
     * Ensure final install path uses the correct plugin directory.
     * This catches edge cases where the upgrader would otherwise install into a mismatched folder.
     */
    public function ensure_plugin_installed_in_correct_dir($response, $hook_extra, $result)
    {
        if (!$this->is_our_plugin_upgrade($hook_extra)) {
            return $response;
        }

        if (empty($result['destination']) || empty($result['local_destination'])) {
            return $response;
        }

        $desired_dir = trailingslashit(WP_PLUGIN_DIR) . dirname($this->plugin_slug);

        // If it already installed there, nothing to do
        $installed_dir = untrailingslashit((string) $result['destination']);
        if (untrailingslashit($desired_dir) === $installed_dir) {
            return $response;
        }

        // Move installed folder to desired folder
        if (is_dir($desired_dir)) {
            $this->rmdir_recursive($desired_dir);
        }

        @rename($installed_dir, untrailingslashit($desired_dir));
        $result['destination'] = untrailingslashit($desired_dir);

        return $response;
    }

    private function is_our_plugin_upgrade($hook_extra): bool
    {
        if (!is_array($hook_extra)) {
            return false;
        }

        if (($hook_extra['action'] ?? '') !== 'update' || ($hook_extra['type'] ?? '') !== 'plugin') {
            return false;
        }

        $plugins = $hook_extra['plugins'] ?? null;
        if (!is_array($plugins)) {
            return false;
        }

        return in_array($this->plugin_slug, $plugins, true);
    }

    private function rmdir_recursive(string $dir): void
    {
        $dir = untrailingslashit($dir);
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if (!is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->rmdir_recursive($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }

    private function log_error(string $message): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Content Core GitHub Updater: ' . $message);
        }
    }
}