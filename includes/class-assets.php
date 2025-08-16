<?php
namespace Automind;

defined('ABSPATH') || exit;

class Assets {
    public static function init() {
        add_action('wp_enqueue_scripts', [__CLASS__, 'register_front']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'admin_assets']);
        add_action('wp_footer', [__CLASS__, 'render_popup']);
        add_shortcode('automind', [__CLASS__, 'shortcode']);
    }

    public static function register_front() {
        if (!wp_style_is('automind-chat', 'registered')) {
            wp_register_style('automind-chat', AUTOMIND_URL . 'assets/css/chat.css', [], AUTOMIND_VERSION);
        }
        if (!wp_script_is('automind-chat', 'registered')) {
            wp_register_script('automind-chat', AUTOMIND_URL . 'assets/js/chat.js', [], AUTOMIND_VERSION, true);
        }
        if (!wp_style_is('automind-pop', 'registered')) {
            wp_register_style('automind-pop', AUTOMIND_URL . 'assets/css/popup.css', [], AUTOMIND_VERSION);
        }
        if (!wp_script_is('automind-pop', 'registered')) {
            wp_register_script('automind-pop', AUTOMIND_URL . 'assets/js/popup.js', [], AUTOMIND_VERSION, true);
        }

        $bearer_enabled = get_option('automind_bearer_enabled', '0') === '1';
        $bearer_secret  = $bearer_enabled ? (string) get_option('automind_bearer_secret', '') : '';

        $has_key = false;
        if (defined('AUTOMIND_OPENAI_KEY') && (string) constant('AUTOMIND_OPENAI_KEY') !== '') $has_key = true;
        elseif ((string) get_option('automind_openai_key', '') !== '') $has_key = true;

        wp_localize_script('automind-chat', 'AUTOMIND_CONFIG', [
            'restUrl'       => esc_url_raw(rest_url('automind/v1/')),
            'nonce'         => wp_create_nonce('wp_rest'),
            'bearerEnabled' => $bearer_enabled,
            'bearer'        => $bearer_secret,
            'model'         => get_option('automind_model', 'gpt-4o-mini'),
            'botName'       => get_option('automind_bot_name', 'Codi'),
            'userLabel'     => get_option('automind_user_label', 'Ty'),
            'greeting'      => get_option('automind_greeting', 'Cześć! Jak mogę pomóc?'),
            'hasKey'        => $has_key,
            'sseTimeoutMs'  => 2500,
        ]);
    }

    public static function admin_assets($hook) {
        $allowed = [
            'toplevel_page_automind',
            'automind_page_automind-chatbot',
            'automind_page_automind-rag',
            'automind_page_automind-logs',
        ];
        if (!in_array($hook, $allowed, true)) return;

        if (!wp_script_is('automind-admin', 'registered')) {
            wp_register_script('automind-admin', AUTOMIND_URL . 'assets/js/admin.js', [], AUTOMIND_VERSION, true);
        }
        wp_localize_script('automind-admin', 'AUTOMIND_ADMIN', [
            'restUrl' => esc_url_raw(rest_url('automind/v1/')),
            'nonce'   => wp_create_nonce('wp_rest'),
        ]);
        wp_enqueue_script('automind-admin');
    }

    public static function shortcode($atts) {
        $atts = shortcode_atts([
            'bot'   => get_option('automind_bot_id', 'codi'),
            'popup' => 'false',
            'theme' => 'auto',
        ], $atts, 'automind');

        wp_enqueue_style('automind-chat');
        wp_enqueue_script('automind-chat');

        ob_start(); ?>
        <div class="automind-widget" data-bot="<?php echo esc_attr($atts['bot']); ?>" data-theme="<?php echo esc_attr($atts['theme']); ?>">
            <div class="automind-messages" aria-live="polite"></div>
            <form class="automind-form" autocomplete="off">
                <textarea name="message" rows="2" placeholder="<?php echo esc_attr__('Napisz wiadomość…', 'automind'); ?>" aria-label="<?php echo esc_attr__('Pole wiadomości', 'automind'); ?>"></textarea>
                <div class="automind-actions">
                    <button type="submit" class="button button-primary" title="<?php echo esc_attr__('Wyślij (Enter)', 'automind'); ?>"><?php echo esc_html__('Wyślij', 'automind'); ?></button>
                    <button type="button" class="button automind-clear" title="<?php echo esc_attr__('Wyczyść czat', 'automind'); ?>"><?php echo esc_html__('Wyczyść', 'automind'); ?></button>
                </div>
            </form>
            <div class="automind-hint"><?php echo esc_html__('Enter – wyślij, Shift+Enter – nowa linia', 'automind'); ?></div>
        </div>
        <?php
        return ob_get_clean();
    }

    protected static function icon_url_by_slug(string $slug): ?string {
        $dir = AUTOMIND_PATH . 'assets/icons/';
        if (!is_dir($dir)) return null;
        foreach (glob($dir . '*.svg') as $full) {
            $base = basename($full, '.svg');
            $s = strtolower(preg_replace('/[^a-z0-9\-_.]/', '-', $base));
            if ($s === strtolower($slug)) {
                return AUTOMIND_URL . 'assets/icons/' . basename($full);
            }
        }
        return null;
    }

    // Popup
    public static function render_popup() {
        if (is_admin()) return;

        $enabled = get_option('automind_popup_enabled','0')==='1';
        if(!$enabled) return;
        if(!did_action('wp_enqueue_scripts')) return;

        $showMobile  = get_option('automind_popup_show_mobile','1')==='1';
        $showDesktop = get_option('automind_popup_show_desktop','1')==='1';
        $delayMs     = (int) get_option('automind_popup_delay',0);
        $position    = get_option('automind_popup_position','right');
        $accent      = (string) get_option('automind_popup_accent','#0b5cab');
        $hideRegex   = (string) get_option('automind_popup_hide_regex','');
        $preset      = (string) get_option('automind_popup_icon_preset','default');
        $iconUrl     = (string) get_option('automind_popup_icon_url','');
        $iconSize    = (int) get_option('automind_popup_icon_size',32);
        $iconSize    = max(16, min(128, $iconSize));
        $bot         = get_option('automind_bot_id','codi');

        $isMobile = function_exists('wp_is_mobile') ? wp_is_mobile() : false;
        if ($isMobile && !$showMobile)  return;
        if (!$isMobile && !$showDesktop) return;

        if ($hideRegex !== '') {
            $path = $_SERVER['REQUEST_URI'] ?? '';
            if (@preg_match($hideRegex, $path)) return;
        }

        if (!wp_style_is('automind-chat','enqueued')) wp_enqueue_style('automind-chat');
        if (!wp_script_is('automind-chat','enqueued')) wp_enqueue_script('automind-chat');
        if (!wp_style_is('automind-pop','enqueued'))  wp_enqueue_style('automind-pop');
        if (!wp_script_is('automind-pop','enqueued')) wp_enqueue_script('automind-pop');

        if ($accent && preg_match('/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/',$accent)) {
            wp_add_inline_style('automind-pop', '#automind-pop .am-pop-bubble{background:'.$accent.'}');
        }

        // Ikona
        $bg = '';
        if ($preset === 'custom' && $iconUrl !== '') {
            $bg = esc_url($iconUrl);
        } elseif ($preset !== 'default') {
            $u = self::icon_url_by_slug($preset);
            if ($u) $bg = $u;
        }

        // Fallback – dymek w kolorze akcentu (żeby był widoczny na białym tle)
        if ($bg === '') {
            $hex = preg_match('/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/',$accent) ? ltrim($accent, '#') : '0b5cab';
            $bg = 'data:image/svg+xml;utf8,' . rawurlencode("<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'><path fill='#{$hex}' d='M20 2H4a2 2 0 0 0-2 2v12c0 1.1.9 2 2 2h2.6l3.7 3.7c.6.6 1.7.2 1.7-.7V18H20a2 2 0 0 0 2-2V4c0-1.1-.9-2-2-2zM4 4h16v10H12a1 1 0 0 0-1 1v2.6l-2.3-2.3A1 1 0 0 0 8 15H4z'/></svg>");
        }

        $posClass = ($position === 'left') ? 'left' : 'right';
        ?>
        <div id="automind-pop" class="<?php echo esc_attr($posClass); ?> am-ready" data-delay="<?php echo (int)$delayMs; ?>">
            <button class="am-pop-bubble" aria-label="<?php echo esc_attr__('Otwórz czat','automind'); ?>"
                    style="--am-icon-size: <?php echo (int)$iconSize; ?>px">
                <span class="am-pop-ico" style="background-image:url('<?php echo esc_url($bg); ?>')"></span>
            </button>
            <?php $greet = trim((string) get_option('automind_greeting','')); ?>
            <?php if ($greet !== ''): ?>
            <div class="am-pop-tip" role="status" aria-live="polite">
                <?php echo esc_html($greet); ?>
                <button class="am-tip-close" aria-label="<?php echo esc_attr__('Zamknij','automind'); ?>">×</button>
            </div>
            <?php endif; ?>
            <div class="am-pop-panel" role="dialog" aria-modal="false" aria-hidden="true">
                <div class="am-pop-header">
                    <div class="am-pop-title"><?php echo esc_html(get_option('automind_bot_name','Codi')); ?></div>
                    <button class="am-pop-close" aria-label="<?php echo esc_attr__('Zamknij','automind'); ?>">×</button>
                </div>
                <div class="am-pop-body">
                    <?php echo self::render_widget_html($bot); ?>
                </div>
            </div>
        </div>
        <?php
    }

    protected static function render_widget_html(string $bot) {
        ob_start(); ?>
        <div class="automind-widget" data-bot="<?php echo esc_attr($bot); ?>" data-theme="auto">
            <div class="automind-messages" aria-live="polite"></div>
            <form class="automind-form" autocomplete="off">
                <textarea name="message" rows="2" placeholder="<?php echo esc_attr__('Napisz wiadomość…', 'automind'); ?>" aria-label="<?php echo esc_attr__('Pole wiadomości', 'automind'); ?>"></textarea>
                <div class="automind-actions">
                    <button type="submit" class="button button-primary"><?php echo esc_html__('Wyślij', 'automind'); ?></button>
                    <button type="button" class="button automind-clear"><?php echo esc_html__('Wyczyść', 'automind'); ?></button>
                </div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }
}