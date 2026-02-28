<?php
require_once '/app/public/wp-load.php';

$settings = get_option('cc_languages_settings', []);
$lang = $settings['default_lang'] ?? 'de';
$opts = get_option('cc_site_options_' . $lang);

var_dump(get_option('cc_site_options'));
var_dump(get_option('cc_site_logo_id'));
var_dump($opts);
