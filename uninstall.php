<?php
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

// Usuwaj dane tylko, jeśli admin świadomie to włączył (np. w Ustawieniach – do dodania później).
$delete = get_option('automind_delete_on_uninstall', '0') === '1';
if (!$delete) return;

global $wpdb;
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}automind_embeddings");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}automind_logs");

// Usuń wybrane opcje
$opts = $wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'automind_%'");
foreach ($opts as $o) { delete_option($o); }