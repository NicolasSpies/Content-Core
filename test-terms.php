<?php
require_once dirname(dirname(dirname(__DIR__))) . '/wp-load.php';
$terms = get_terms([
    'taxonomy' => 'category',
    'hide_empty' => false,
    'number' => 0
]);
if (is_wp_error($terms)) {
    echo "ERROR:\n" . $terms->get_error_message() . "\n";
} else {
    echo "Count: " . count($terms) . "\n";
}
global $wpdb;
echo "LAST QUERY:\n" . $wpdb->last_query . "\n";
