<?php
namespace Automind;

defined('ABSPATH') || exit;

class Pages {

    public static function init() {
        // Zbuduj menu po menu głównym Automind (Admin::menu) – kolejność ma znaczenie
        add_action('admin_menu', [__CLASS__, 'menu'], 20);
        // Nadaj target="_blank" zewnętrznym linkom w menu
        add_action('admin_head', [__CLASS__, 'head_js']);
    }

    public static function menu() {
        if (!current_user_can('manage_automind')) return;

        global $submenu;
        $cap = 'manage_automind';

        // Upewnij się, że istnieje menu 'automind'
        if (!isset($submenu['automind']) || !is_array($submenu['automind'])) {
            return;
        }

        // Dodaj linki zewnętrzne jako pozycje submenu (href będzie dokładnym URLeM)
        $submenu['automind'][] = [
            __('How to use', 'automind'), // Tytuł w menu
            $cap,
            'https://automind.undercode.eu/',
        ];
        $submenu['automind'][] = [
            __('Buy Pro', 'automind'),
            $cap,
            'https://automind.undercode.eu/pro/',
        ];

        // Strona wewnętrzna — Author
        add_submenu_page(
            'automind',
            __('Automind — Author', 'automind'),
            __('Author', 'automind'),
            $cap,
            'automind-author',
            [__CLASS__, 'render_author']
        );
    }

    // Nadaj docelowym linkom target="_blank" + rel (otwieranie w nowej karcie)
    public static function head_js() {
        if (!current_user_can('manage_automind')) return;
        ?>
        <script>
        document.addEventListener('DOMContentLoaded', function(){
            var sel = '#adminmenu a[href="https://automind.undercode.eu/"], #adminmenu a[href="https://automind.undercode.eu/pro/"]';
            document.querySelectorAll(sel).forEach(function(a){
                a.setAttribute('target', '_blank');
                a.setAttribute('rel', 'noopener');
            });
        });
        </script>
        <?php
    }

    public static function render_author() {
        if (!current_user_can('manage_automind')) wp_die(__('Brak uprawnień.', 'automind'));
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Automind — Author', 'automind'); ?></h1>
            <p><?php echo wp_kses_post(__('Built by <strong>Undercode</strong>. We craft fast, secure WordPress solutions with AI.', 'automind')); ?></p>
            <ul>
                <li>Website: <a href="https://undercode.eu/" target="_blank" rel="noopener">undercode.eu</a></li>
                <li>Email: <a href="mailto:hello@undercode.eu">hello@undercode.eu</a></li>
            </ul>
            <p><?php echo esc_html__('If you enjoy Automind, please rate it on WordPress.org.', 'automind'); ?></p>
        </div>
        <?php
    }
}