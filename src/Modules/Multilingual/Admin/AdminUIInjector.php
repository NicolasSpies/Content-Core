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

        $css = "
            /* Translation Column Flags - Unified Styling */
            .column-cc_translations {
                text-align: left !important;
                width: 125px !important;
            }
            .column-cc_translations .cc-translation-column-wrap {
                display: flex !important;
                flex-direction: row !important;
                justify-content: flex-start !important;
                gap: 6px !important;
                align-items: center !important;
                vertical-align: middle !important;
                flex-wrap: nowrap !important;
                line-height: 1 !important;
            }
            .column-cc_translations .cc-flag {
                display: inline-flex !important;
                align-items: center !important;
                justify-content: center !important;
                text-decoration: none !important;
                transition: all 0.2s ease-in-out !important;
                height: 24px !important;
                width: 24px !important;
                padding: 0 !important;
                margin: 0 !important;
                line-height: 0 !important;
                border-radius: 4px !important;
            }
            
            /* Status Visual States */
            .cc-flag.cc-flag--published {
                background-color: rgba(34, 197, 94, 0.22) !important;
                border: 1px solid rgba(34, 197, 94, 0.35) !important;
                opacity: 1.0 !important;
            }
            
            .cc-flag.cc-flag--unpublished {
                background-color: rgba(0, 0, 0, 0.06) !important;
                opacity: 1.0 !important;
            }
            
            .cc-flag.cc-flag--missing {
                opacity: 0.4 !important;
                background-color: transparent !important;
            }
            
            /* Hover interactions */
            .cc-flag:hover {
                opacity: 1.0 !important;
                transform: scale(1.1) !important;
                background-color: rgba(0, 0, 0, 0.1) !important;
            }

            .cc-flag.cc-flag--published:hover {
                background-color: rgba(70, 180, 80, 0.2) !important;
            }
            
            /* Content consistency */
            .cc-flag img,
            .cc-flag span {
                max-width: 16px !important;
                height: auto !important;
                border-radius: 1px !important;
                display: inline-block !important;
            }
        ";

        wp_add_inline_style('common', $css);
    }

    public function get_flag_html(string $code, int $flag_id = 0): string
    {
        if ($flag_id > 0) {
            $img = wp_get_attachment_image_src($flag_id, [32, 32]);
            if ($img) {
                return '<img src="' . esc_url($img[0]) . '" alt="' . esc_attr($code) . '" />';
            }
        }

        $flags = [
            'de' => '🇩🇪',
            'en' => '🇬🇧',
            'fr' => '🇫🇷',
            'it' => '🇮🇹',
            'es' => '🇪🇸',
            'nl' => '🇳🇱',
            'pt' => '🇵🇹',
            'pl' => '🇵🇱',
            'ru' => '🇷🇺',
            'tr' => '🇹🇷'
        ];

        return '<span style="font-size:16px;">' . ($flags[$code] ?? strtoupper($code)) . '</span>';
    }
}
