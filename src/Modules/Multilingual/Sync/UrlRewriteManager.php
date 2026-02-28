<?php
namespace ContentCore\Modules\Multilingual\Sync;

class UrlRewriteManager
{
    /** @var callable */
    private $settings_getter;

    public function __construct(callable $settings_getter)
    {
        $this->settings_getter = $settings_getter;
    }

    public function init(): void
    {
        add_action('init', [$this, 'cc_add_rewrite_rules'], 10);
        add_action('init', [$this, 'maybe_flush_rewrites'], 11);

        add_filter('post_link', [$this, 'cc_filter_post_link'], 10, 2);
        add_filter('page_link', [$this, 'cc_filter_page_link'], 10, 2);
        add_filter('post_type_link', [$this, 'cc_filter_post_link'], 10, 2);

        add_filter('term_link', [$this, 'cc_filter_term_link'], 10, 3);
    }

    public function cc_filter_post_link(string $post_link, \WP_Post $post): string
    {
        if (!post_type_supports($post->post_type, 'cc-multilingual'))
            return $post_link;
        $settings = call_user_func($this->settings_getter);
        if (empty($settings['permalink_enabled']))
            return $post_link;

        $lang = get_post_meta($post->ID, '_cc_language', true);
        $default_lang = $settings['default_lang'] ?? 'de';
        if (!$lang || $lang === $default_lang)
            return $post_link;

        $bases = $settings['permalink_bases'] ?? [];
        $obj = get_post_type_object($post->post_type);
        $default_slug = $obj->rewrite['slug'] ?? ($post->post_type === 'post' ? '' : $post->post_type);
        $base = $bases[$post->post_type][$lang] ?? $default_slug;

        $home_url = home_url('/');
        $slug = $post->post_name;
        $url_path = $lang . '/' . (!empty($base) ? $base . '/' : '') . $slug;
        return user_trailingslashit($home_url . $url_path);
    }

    public function cc_filter_page_link(string $post_link, int $post_id): string
    {
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'page')
            return $post_link;
        $settings = call_user_func($this->settings_getter);
        if (empty($settings['permalink_enabled']))
            return $post_link;

        $lang = get_post_meta($post_id, '_cc_language', true);
        $default_lang = $settings['default_lang'] ?? 'de';
        if (!$lang || $lang === $default_lang)
            return $post_link;

        return user_trailingslashit(home_url('/') . $lang . '/' . get_page_uri($post_id));
    }


    public function cc_filter_term_link(string $url, \WP_Term $term, string $taxonomy): string
    {
        $settings = call_user_func($this->settings_getter);
        if (empty($settings['permalink_enabled']))
            return $url;

        $lang = get_term_meta($term->term_id, '_cc_language', true) ?: $settings['default_lang'];
        if ($lang === $settings['default_lang'])
            return $url;

        $home_url = home_url('/');
        $path = str_replace($home_url, '', $url);
        $tax_bases = $settings['taxonomy_bases'] ?? [];
        $tax_obj = get_taxonomy($taxonomy);
        $default_base = $tax_obj->rewrite['slug'] ?? $taxonomy;
        $localized_base = $tax_bases[$taxonomy][$lang] ?? $default_base;

        if ($localized_base !== $default_base) {
            $path = preg_replace('/^' . preg_quote($default_base, '/') . '\//', $localized_base . '/', $path);
        }

        return $home_url . $lang . '/' . $path;
    }

    public function cc_add_rewrite_rules(): void
    {
        $settings = call_user_func($this->settings_getter);
        if (empty($settings['enabled']) || empty($settings['permalink_enabled']))
            return;

        $languages = array_column($settings['languages'], 'code');
        $other_langs = array_diff($languages, [$settings['default_lang']]);
        if (empty($other_langs))
            return;

        $lang_regex = '(' . implode('|', $other_langs) . ')';
        add_rewrite_rule('^' . $lang_regex . '/(.?.+?)(?:/([0-9]+))?/?$', 'index.php?pagename=$matches[2]&cc_lang=$matches[1]&page=$matches[3]', 'top');

        $public_pts = get_post_types(['public' => true], 'objects');
        $bases = $settings['permalink_bases'] ?? [];
        foreach ($public_pts as $pt) {
            if ($pt->name === 'page' || $pt->name === 'attachment')
                continue;
            foreach ($other_langs as $lang) {
                $base = $bases[$pt->name][$lang] ?? ($pt->rewrite['slug'] ?? $pt->name);
                if (empty($base))
                    continue;
                add_rewrite_rule('^' . $lang . '/' . preg_quote($base, '/') . '/([^/]+)/?$', 'index.php?' . $pt->query_var . '=$matches[1]&cc_lang=' . $lang, 'top');
            }
        }
    }

    public function maybe_flush_rewrites(): void
    {
        if (get_transient('cc_flush_multilingual_rewrites')) {
            delete_transient('cc_flush_multilingual_rewrites');
            flush_rewrite_rules();
        }
    }
}
