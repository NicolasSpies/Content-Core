<?php
require_once '/Users/nicolasspies/Desktop/Local test/app/public/wp-load.php';

$settings = get_option('cc_languages_settings', []);
$lang = $settings['default_lang'] ?? 'de';
$opts = get_option('cc_site_options_' . $lang);

var_dump($opts['logo_id']);
$url = wp_get_attachment_url((int) $opts['logo_id']);
var_dump($url);
