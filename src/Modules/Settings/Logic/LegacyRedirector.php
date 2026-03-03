<?php
namespace ContentCore\Modules\Settings\Logic;

/**
 * Class LegacyRedirector
 * 
 * Handles redirects from legacy URL fragments (hashes) to the new dedicated settings pages.
 */
class LegacyRedirector
{
    /**
     * Handle legacy admin URL hashes
     */
    public function handle_legacy_admin_redirects(): void
    {
        if (!is_admin())
            return;

        $page = sanitize_text_field($_GET['page'] ?? '');
        if ($page !== 'cc-settings' && $page !== 'cc-site-settings') {
            return;
        }

        // JS-based redirect for hash fragments
        $target = ($page === 'cc-site-settings') ? 'cc-multilingual' : 'cc-visibility';
        ?>
        <script>
            var hashMap = {
                '#menu': 'cc-visibility',
                '#media': 'cc-media',
                '#redirect': 'cc-redirect',
                '#multilingual': 'cc-multilingual',
                '#seo': 'cc-seo',
                '#site_images': 'cc-seo',
                '#site_options': 'cc-site-options',
                '#cookie': 'cc-cookie-banner'
            };

            var hash = window.location.hash;
            var redirectPage = '<?php echo esc_js($target); ?>';

            if (hash && hashMap[hash]) {
                redirectPage = hashMap[hash];
            }

            window.location.replace('admin.php?page=' + redirectPage);
        </script>
        <?php
        exit;
    }
}
