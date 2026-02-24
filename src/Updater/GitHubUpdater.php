<?php

namespace ContentCore\Updater;

/**
 * GitHub Automatic Update System for Content Core
 * Version 2: Supports optional authentication and robust asset detection.
 */
class GitHubUpdater
{
    private string $repo_owner = 'NicolasSpies';
    private string $repo_name = 'Content-Core';
    private string $plugin_slug;
    private string $plugin_file;

    /**
     * Constructor
     */
    public function __construct(string $plugin_file)
    {
        $this->plugin_file = $plugin_file;
        $this->plugin_slug = plugin_basename($plugin_file);
    }

    /**
     * Initialize the updater
     */
    public function init(): void
    {
        add_filter('site_transient_update_plugins', [$this, 'check_update']);
        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_update']);
        add_filter('plugins_api', [$this, 'plugin_popup'], 20, 3);
    }

    /**
     * Check for updates in GitHub Releases
     */
    public function check_update($transient)
    {
        if (empty($transient->checked)) {
            return $transient;
        }

        $remote = $this->get_remote_release();

        if (!$remote || empty($remote->tag_name)) {
            return $transient;
        }

        $local_version = $this->get_local_version();
        $remote_version = ltrim($remote->tag_name, 'v');

        if (version_compare($local_version, $remote_version, '<')) {
            $asset = $this->get_zip_asset($remote);

            if ($asset) {
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

    /**
     * Show release notes in the "View details" modal
     */
    public function plugin_popup($result, $action, $args)
    {
        // WordPress might pass the slug as just the directory name or the full path
        $slug = is_string($args->slug) ? $args->slug : '';
        if ($action !== 'plugin_information' || ($slug !== $this->plugin_slug && $slug !== dirname($this->plugin_slug))) {
            return $result;
        }

        $remote = $this->get_remote_release();

        if (!$remote) {
            return $result;
        }

        $res = new \stdClass();
        $res->name = 'Content Core';
        $res->slug = $this->plugin_slug;
        $res->version = ltrim($remote->tag_name, 'v');
        $res->author = 'Nicolas Spies';
        $res->homepage = "https://github.com/{$this->repo_owner}/{$this->repo_name}";
        $res->download_link = $this->get_zip_asset($remote)->browser_download_url ?? '';
        $res->sections = [
            'description' => 'Modular internal agency framework for headless WordPress projects.',
            'changelog' => wp_kses_post(nl2br($remote->body)),
        ];
        $res->last_updated = $remote->published_at;

        return $res;
    }

    /**
     * Fetch latest stable release from GitHub API
     */
    private function get_remote_release()
    {
        $cache_key = 'content_core_github_release';
        $remote = get_site_transient($cache_key);

        if ($remote === false) {
            $url = "https://api.github.com/repos/{$this->repo_owner}/{$this->repo_name}/releases/latest";

            $headers = [
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url'),
            ];

            // Add optional GitHub token for private repos or higher rate limits
            $token = get_option('content_core_github_token');
            if ($token) {
                $headers['Authorization'] = 'token ' . $token;
            }

            $response = wp_remote_get($url, [
                'timeout' => 10,
                'headers' => $headers,
            ]);

            if (is_wp_error($response)) {
                $this->log_error('GitHub API error: ' . $response->get_error_message());
                return false;
            }

            $code = wp_remote_retrieve_response_code($response);
            if ($code !== 200) {
                // Return silently if rate limited or not found
                if ($code === 403 || $code === 404) {
                    return false;
                }
                $this->log_error('GitHub API HTTP Error ' . $code);
                return false;
            }

            $remote = json_decode(wp_remote_retrieve_body($response));

            if (!$remote || (!empty($remote->prerelease) && $remote->prerelease)) {
                return false;
            }

            // Cache for 12 hours
            set_site_transient($cache_key, $remote, 12 * HOUR_IN_SECONDS);
        }

        return $remote;
    }

    /**
     * Get the ZIP asset from a release object
     */
    private function get_zip_asset($remote)
    {
        if (empty($remote->assets)) {
            return null;
        }

        foreach ($remote->assets as $asset) {
            // Check for ZIP content type or extension
            $content_type = $asset->content_type ?? '';
            $name = $asset->name ?? '';

            if ($content_type === 'application/zip' ||
            $content_type === 'application/x-zip-compressed' ||
            str_ends_with(strtolower($name), '.zip')) {
                return $asset;
            }
        }

        return null;
    }

    /**
     * Get current plugin version dynamically from the plugin header
     */
    private function get_local_version(): string
    {
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugin_data = get_plugin_data($this->plugin_file);
        return $plugin_data['Version'] ?? '0.0.0';
    }

    /**
     * Log errors if WP_DEBUG is enabled
     */
    private function log_error(string $message): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Content Core GitHub Updater: ' . $message);
        }
    }
}