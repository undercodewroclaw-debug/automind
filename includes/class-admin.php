<?php
namespace Automind;

defined('ABSPATH') || exit;

class Admin {

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_notices', [__CLASS__, 'notices']);
    }

    public static function notices() {
        if (!current_user_can('manage_automind')) return;
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        // Pokazuj na stronach Automind + Kokpit
        $allowed = ['toplevel_page_automind','automind_page_automind-chatbot','automind_page_automind-rag','automind_page_automind-logs','dashboard'];
        if ($screen && !in_array($screen->id, $allowed, true)) return;

        $has_key = (defined('AUTOMIND_OPENAI_KEY') && (string) constant('AUTOMIND_OPENAI_KEY') !== '');
        if (!$has_key) {
            echo '<div class="notice notice-error"><p><strong>Automind:</strong> Brak klucza OpenAI (AUTOMIND_OPENAI_KEY). Widżet pokaże komunikat „Chwilowo niedostępny”. Dodaj klucz w wp-config.php lub w Ustawieniach.</p></div>';
        }
    }

    public static function menu() {
        // Główna strona: Ustawienia
        add_menu_page(
            __('Automind', 'automind'),
            __('Automind', 'automind'),
            'manage_automind',
            'automind',
            [__CLASS__, 'render_settings'],
            'dashicons-format-chat',
            58
        );

        // Submenu: Ustawienia
        add_submenu_page(
            'automind',
            __('Automind — Ustawienia', 'automind'),
            __('Ustawienia', 'automind'),
            'manage_automind',
            'automind',
            [__CLASS__, 'render_settings']
        );

        // Submenu: Chatbot
        add_submenu_page(
            'automind',
            __('Automind — Chatbot', 'automind'),
            __('Chatbot', 'automind'),
            'manage_automind',
            'automind-chatbot',
            [__CLASS__, 'render_chatbot']
        );

        // Submenu: RAG
        add_submenu_page(
            'automind',
            __('Automind — RAG', 'automind'),
            __('RAG', 'automind'),
            'manage_automind',
            'automind-rag',
            [__CLASS__, 'render_rag']
        );

        add_submenu_page(
            'automind',
            __('Automind — Logi i diagnostyka', 'automind'),
            __('Logi', 'automind'),
            'manage_automind',
            'automind-logs',
            ['Automind\Logger', 'render_page']
        );
    }

    public static function render_settings() {
        if (!current_user_can('manage_automind')) {
            wp_die(__('Brak uprawnień.', 'automind'));
        }
        Settings::render_page();
    }

    public static function render_chatbot() {
        if (!current_user_can('manage_automind')) {
            wp_die(__('Brak uprawnień.', 'automind'));
        }
        if (class_exists('\Automind\Chatbot')) {
            Chatbot::render_page();
        } else {
            echo '<div class="wrap"><h1>Automind — Chatbot</h1><p>Brak klasy Chatbot. Sprawdź, czy plik includes/class-chatbot.php jest dołączony.</p></div>';
        }
    }

    public static function render_rag() {
        if (!current_user_can('manage_automind')) {
            wp_die(__('Brak uprawnień.', 'automind'));
        }

        // Wczytaj zapisane kategorie i ustawienie podkategorii
        $selectedCats = array_map('intval', (array) get_option('automind_rag_post_cats', []));
        $includeChildren = get_option('automind_rag_include_children', '1') === '1';

        // Helper: rekurencyjne rysowanie drzewka kategorii
        $print_tree = function($parent = 0) use (&$print_tree, $selectedCats) {
            $terms = get_terms([
                'taxonomy'   => 'category',
                'hide_empty' => false,
                'parent'     => (int) $parent,
                'orderby'    => 'name',
                'order'      => 'ASC',
            ]);
            if (is_wp_error($terms) || empty($terms)) return;
            echo '<ul class="am-cat-tree" style="list-style:none;margin:4px 0 0 0;padding-left:'.($parent ? 16 : 0).'px">';
            foreach ($terms as $t) {
                $id = (int) $t->term_id;
                $checked = in_array($id, $selectedCats, true) ? ' checked' : '';
                echo '<li style="margin:2px 0">';
                echo '<label><input type="checkbox" name="am-rag-cat[]" value="'.esc_attr($id).'"'.$checked.' /> '.esc_html($t->name).'</label>';
                // dzieci
                $print_tree($id);
                echo '</li>';
            }
            echo '</ul>';
        };
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Automind — RAG (Embeddings)', 'automind'); ?></h1>

            <h2><?php echo esc_html__('Źródła', 'automind'); ?></h2>
            <p><?php echo esc_html__('Wybierz typy treści do indeksowania:', 'automind'); ?></p>
            <label><input type="checkbox" id="am-rag-pt-post" checked /> <?php echo esc_html__('Posty', 'automind'); ?></label>
            <label style="margin-left:12px"><input type="checkbox" id="am-rag-pt-page" checked /> <?php echo esc_html__('Strony', 'automind'); ?></label>

            <h2 style="margin-top:18px"><?php echo esc_html__('Kategorie postów (opcjonalnie)', 'automind'); ?></h2>
            <p class="description"><?php echo esc_html__('Dotyczy tylko typu Post. Zaznacz kategorie, które mają trafić do indeksu RAG. Gdy nic nie wybierzesz — zindeksujemy wszystkie posty.', 'automind'); ?></p>
            <div id="am-rag-cats-wrap" style="max-width:540px;border:1px solid #ddd;padding:8px;border-radius:4px;background:#fff">
                <?php $print_tree(0); ?>
            </div>
            <p>
                <label>
                    <input type="checkbox" id="am-rag-cats-children" <?php checked($includeChildren); ?> />
                    <?php echo esc_html__('Uwzględnij podkategorie', 'automind'); ?>
                </label>
            </p>

            <h2 style="margin-top:18px"><?php echo esc_html__('Parametry chunkingu', 'automind'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php echo esc_html__('Chunk size', 'automind'); ?></th>
                    <td><input type="number" id="am-rag-chunk" value="1000" min="300" max="4000" /> <span class="description"><?php echo esc_html__('znaki', 'automind'); ?></span></td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__('Overlap', 'automind'); ?></th>
                    <td><input type="number" id="am-rag-overlap" value="200" min="0" max="800" /> <span class="description"><?php echo esc_html__('znaki', 'automind'); ?></span></td>
                </tr>
            </table>

            <h2 style="margin-top:18px"><?php echo esc_html__('Akcje', 'automind'); ?></h2>
            <p>
                <button class="button" id="am-rag-reindex"><?php echo esc_html__('Reindeksuj', 'automind'); ?></button>
                <button class="button" id="am-rag-status" style="margin-left:6px"><?php echo esc_html__('Status', 'automind'); ?></button>
                <button class="button button-secondary" id="am-rag-clear" style="margin-left:6px"><?php echo esc_html__('Wyczyść indeks', 'automind'); ?></button>
                <span id="am-rag-status-text" style="margin-left:10px;color:#666"></span>
            </p>

            <hr />

            <h2 style="margin-top:18px"><?php echo esc_html__('Manualny import (Q&A / tekst)', 'automind'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php echo esc_html__('Tytuł', 'automind'); ?></th>
                    <td>
                        <input type="text" id="am-rag-manual-title" value="Baza Q&A" style="width:380px" />
                        <p class="description"><?php echo esc_html__('Nazwa źródła w indeksie (np. “Baza Q&A”).', 'automind'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__('URL (opcjonalnie)', 'automind'); ?></th>
                    <td><input type="url" id="am-rag-manual-url" placeholder="https://undercode.eu/..." style="width:380px" /></td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__('Plik (.txt/.md)', 'automind'); ?></th>
                    <td>
                        <input type="file" id="am-rag-file" accept=".txt,.md" />
                        <p class="description"><?php echo esc_html__('Wybierz plik – treść zostanie wczytana do pola “Tekst”.', 'automind'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row" valign="top"><?php echo esc_html__('Tekst', 'automind'); ?></th>
                    <td>
                        <textarea id="am-rag-manual-text" rows="14" style="width:100%;max-width:960px" placeholder="<?php echo esc_attr__('Wklej albo załaduj plik Q&A (.txt/.md).', 'automind'); ?>"></textarea>
                        <p class="description"><?php echo esc_html__('Zostanie pocięty na chunki i zindeksowany (model: text-embedding-3-small).', 'automind'); ?></p>
                        <button class="button button-primary" id="am-rag-manual-import"><?php echo esc_html__('Importuj', 'automind'); ?></button>
                        <span id="am-rag-manual-status" style="margin-left:10px;color:#666"></span>
                    </td>
                </tr>
            </table>

            <p class="description" style="margin-top:10px">
                <?php echo esc_html__('Używany model embeddingów: text-embedding-3-small (1536). Tabela: wp_automind_embeddings.', 'automind'); ?>
            </p>
        </div>
        <?php
    }
}