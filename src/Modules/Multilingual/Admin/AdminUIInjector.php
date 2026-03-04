<?php
namespace ContentCore\Modules\Multilingual\Admin;

class AdminUIInjector
{
    /** @var callable */
    private $is_active_check;

    public function __construct(callable $is_active_check)
    {
        $this->is_active_check = $is_active_check;
    }

    public function init(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_styles']);
    }

    private function is_active(): bool
    {
        return call_user_func($this->is_active_check);
    }

    public function enqueue_admin_styles($hook): void
    {
        if (!in_array($hook, ['edit.php', 'post.php', 'post-new.php', 'edit-tags.php', 'term.php'], true)) {
            return;
        }

        if (!$this->is_active()) {
            return;
        }
        wp_enqueue_style('cc-admin-ui');
    }

    public function get_flag_html(string $code, int $flag_id = 0): string
    {
        $code = sanitize_key($code);

        if ($flag_id > 0) {
            $img = wp_get_attachment_image_src($flag_id, [32, 32]);
            if ($img) {
                return '<img src="' . esc_url($img[0]) . '" alt="' . esc_attr($code) . '" />';
            }
        }

        $svg = $this->get_flat_flag_svg($code);
        if ($svg !== '') {
            return $svg;
        }

        return '<span class="cc-flag-fallback">' . esc_html(strtoupper($code)) . '</span>';
    }

    private function get_flat_flag_svg(string $code): string
    {
        $flags = [
            'de' => '<rect width="18" height="12" fill="#000"/><rect y="4" width="18" height="4" fill="#dd0000"/><rect y="8" width="18" height="4" fill="#ffce00"/>',
            'fr' => '<rect width="18" height="12" fill="#fff"/><rect width="6" height="12" fill="#0055a4"/><rect x="12" width="6" height="12" fill="#ef4135"/>',
            'it' => '<rect width="18" height="12" fill="#fff"/><rect width="6" height="12" fill="#009246"/><rect x="12" width="6" height="12" fill="#ce2b37"/>',
            'es' => '<rect width="18" height="12" fill="#aa151b"/><rect y="3" width="18" height="6" fill="#f1bf00"/>',
            'nl' => '<rect width="18" height="12" fill="#fff"/><rect width="18" height="4" fill="#ae1c28"/><rect y="8" width="18" height="4" fill="#21468b"/>',
            'pt' => '<rect width="18" height="12" fill="#ff0000"/><rect width="7" height="12" fill="#006600"/>',
            'pl' => '<rect width="18" height="12" fill="#fff"/><rect y="6" width="18" height="6" fill="#dc143c"/>',
            'ru' => '<rect width="18" height="12" fill="#fff"/><rect y="4" width="18" height="4" fill="#0039a6"/><rect y="8" width="18" height="4" fill="#d52b1e"/>',
            'tr' => '<rect width="18" height="12" fill="#e30a17"/>',
            // Flat Union Jack.
            'en' => '<rect width="18" height="12" fill="#012169"/><polygon points="0,0 2,0 18,10 18,12 16,12 0,2" fill="#fff"/><polygon points="16,0 18,0 18,2 2,12 0,12 0,10" fill="#fff"/><polygon points="0,0 1,0 18,10.5 18,12 17,12 0,1.5" fill="#c8102e"/><polygon points="17,0 18,0 18,1.5 1,12 0,12 0,10.5" fill="#c8102e"/><rect x="7" width="4" height="12" fill="#fff"/><rect y="4" width="18" height="4" fill="#fff"/><rect x="7.75" width="2.5" height="12" fill="#c8102e"/><rect y="4.75" width="18" height="2.5" fill="#c8102e"/>',
        ];

        $shapes = $flags[$code] ?? '';
        if ($shapes === '') {
            return '';
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 18 12" width="18" height="12" role="img" aria-label="' . esc_attr(strtoupper($code)) . '">' . $shapes . '</svg>';
    }
}
