<?php
/**
 * Plugin Name: Automind
 * Plugin URI: https://undercode.eu/
 * Description: Chatbot for WordPress z OpenAI i lokalnym RAG (MVP).
 * Version: 1.0.0
 * Author: undercode.eu
 * Text Domain: automind
 * Domain Path: /languages
 * Requires at least: 6.4
 * Requires PHP: 8.1
 */
defined('ABSPATH') || exit;

define('AUTOMIND_VERSION', '1.0.0');
define('AUTOMIND_PATH', plugin_dir_path(__FILE__));
define('AUTOMIND_URL', plugin_dir_url(__FILE__));
define('AUTOMIND_BASENAME', plugin_basename(__FILE__));

/**
 * Wymuszenie języka tylko dla textdomain 'automind' (przełącznik w Ustawieniach).
 */
function automind_plugin_locale($locale, $domain) {
    if ($domain === 'automind') {
        $opt = get_option('automind_locale', 'default');
        if ($opt && $opt !== 'default') {
            return $opt; // np. 'en_US' lub 'pl_PL'
        }
    }
    return $locale;
}
add_filter('plugin_locale', 'automind_plugin_locale', 10, 2);

function automind_load_textdomain() {
    load_plugin_textdomain('automind', false, dirname(AUTOMIND_BASENAME) . '/languages');
}
add_action('plugins_loaded', 'automind_load_textdomain');

// TYLKO najbezpieczniejsze include’y (Admin + Assets)
require_once AUTOMIND_PATH . 'includes/class-admin.php';
require_once AUTOMIND_PATH . 'includes/class-assets.php';
require_once AUTOMIND_PATH . 'includes/class-settings.php';
require_once AUTOMIND_PATH . 'includes/class-openai.php';
require_once AUTOMIND_PATH . 'includes/class-rag.php';
require_once AUTOMIND_PATH . 'includes/class-logger.php';
require_once AUTOMIND_PATH . 'includes/class-rest.php';
require_once AUTOMIND_PATH . 'includes/class-stream.php';
require_once AUTOMIND_PATH . 'includes/class-chatbot.php';
require_once AUTOMIND_PATH . 'includes/class-i18n-runtime.php';
require_once AUTOMIND_PATH . 'includes/class-history.php';
require_once AUTOMIND_PATH . 'includes/class-pages.php';

// Minimalny bootstrap opcji (bez niczego, co rusza DB/tabele)
function automind_bootstrap_options_safe() {
    if (get_option('automind_model', '') === '') update_option('automind_model', 'gpt-4o-mini');
    if (get_option('automind_temperature', '') === '') update_option('automind_temperature', '0.3');
    if (get_option('automind_max_tokens', '') === '') update_option('automind_max_tokens', 512);
    // Bearer domyślnie OFF
    if (get_option('automind_bearer_enabled', '') === '') update_option('automind_bearer_enabled', '0');
    if (get_option('automind_locale', '') === '') {
    update_option('automind_locale', 'en_US');
}
}
add_action('init', 'automind_bootstrap_options_safe');

// Boot TYLKO Admin + Assets
Automind\Admin::init();
Automind\Assets::init();
Automind\Settings::init();
Automind\Logger::init();
Automind\Rest::init();
Automind\Stream::init();
Automind\Chatbot::init();
Automind\I18n_Runtime::init();
Automind\History::init();
Automind\Pages::init();