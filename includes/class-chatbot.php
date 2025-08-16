<?php
namespace Automind;

defined('ABSPATH') || exit;

class Chatbot {

    public static function init() {
        add_action('admin_init', [__CLASS__, 'register']);
    }

    public static function register() {
        // Profil bota
        register_setting('automind_chatbot', 'automind_bot_id', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_title',
            'default'           => 'codi',
        ]);
        register_setting('automind_chatbot', 'automind_bot_name', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => 'Codi',
        ]);
        register_setting('automind_chatbot', 'automind_user_label', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => 'Ty',
        ]);
        register_setting('automind_chatbot', 'automind_greeting', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => 'Cześć! Jak mogę pomóc?',
        ]);
        register_setting('automind_chatbot', 'automind_system_prompt', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_textarea_field',
            'default'           => 'Jesteś pomocnym asystentem Automind na stronie undercode.eu. Odpowiadaj krótko i po polsku, chyba że użytkownik poprosi inaczej.',
        ]);

        // RAG
        register_setting('automind_chatbot', 'automind_use_rag', [
            'type'              => 'string',
            'sanitize_callback' => [__CLASS__, 'sanitize_bool'],
            'default'           => '0',
        ]);
        register_setting('automind_chatbot', 'automind_rag_topk', [
            'type'              => 'integer',
            'sanitize_callback' => [__CLASS__, 'sanitize_int'],
            'default'           => 5,
        ]);
        register_setting('automind_chatbot', 'automind_rag_context_limit', [
            'type'              => 'integer',
            'sanitize_callback' => [__CLASS__, 'sanitize_int'],
            'default'           => 2048,
        ]);
        // Tryb RAG (strict/soft)
        register_setting('automind_chatbot', 'automind_rag_mode', [
            'type'              => 'string',
            'sanitize_callback' => [__CLASS__, 'sanitize_mode'],
            'default'           => 'strict',
        ]);
        register_setting('automind_chatbot', 'automind_reply_lang', [
            'type' => 'string', 
            'sanitize_callback' => [__CLASS__, 'sanitize_reply_lang'], 
            'default' => 'auto',
        ]);
    }

    public static function sanitize_bool($v) {
        return ($v === '1' || $v === 1 || $v === true || $v === 'on') ? '1' : '0';
    }
    public static function sanitize_int($v) {
        $n = (int) $v;
        if ($n < 0) $n = 0;
        if ($n > 999999) $n = 999999;
        return $n;
    }
    public static function sanitize_mode($v) {
        $v = is_string($v) ? strtolower($v) : 'strict';
        return in_array($v, ['strict', 'soft'], true) ? $v : 'strict';
    }
    public static function sanitize_reply_lang($v){
        $v = is_string($v) ? strtolower($v) : 'auto';
        $allowed = ['auto','plugin','wp','pl','en'];
        return in_array($v, $allowed, true) ? $v : 'auto';
    }

    public static function render_page() {
        if (!current_user_can('manage_automind')) {
            wp_die(__('Brak uprawnień.', 'automind'));
        }

        $botId     = get_option('automind_bot_id', 'codi');
        $botName   = get_option('automind_bot_name', 'Codi');
        $userLabel = get_option('automind_user_label', 'Ty');
        $greeting  = get_option('automind_greeting', 'Cześć! Jak mogę pomóc?');
        $sysPrompt = get_option('automind_system_prompt', 'Jesteś pomocnym asystentem Automind na stronie undercode.eu. Odpowiadaj krótko, chyba że użytkownik poprosi inaczej.');

        $useRag   = get_option('automind_use_rag', '0') === '1';
        $ragTopK  = (int) get_option('automind_rag_topk', 5);
        $ragLimit = (int) get_option('automind_rag_context_limit', 2048);
        $ragMode  = get_option('automind_rag_mode', 'strict');
        $replyLang = get_option('automind_reply_lang','auto');
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Automind — Chatbot', 'automind'); ?></h1>

            <form method="post" action="options.php">
                <?php settings_fields('automind_chatbot'); ?>

                <h2><?php echo esc_html__('Profil bota', 'automind'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php echo esc_html__('Bot ID (slug)', 'automind'); ?></th>
                        <td>
                            <input type="text" name="automind_bot_id" value="<?php echo esc_attr($botId); ?>" />
                            <p class="description"><?php echo esc_html__('Używany w shortcode atrybut bot="..."', 'automind'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Nazwa bota', 'automind'); ?></th>
                        <td>
                            <input type="text" name="automind_bot_name" value="<?php echo esc_attr($botName); ?>" />
                            <p class="description"><?php echo esc_html__('Wyświetlana w czacie (np. “Codi”).', 'automind'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Etykieta użytkownika', 'automind'); ?></th>
                        <td>
                            <input type="text" name="automind_user_label" value="<?php echo esc_attr($userLabel); ?>" />
                            <p class="description"><?php echo esc_html__('Wyświetlana przy wiadomościach użytkownika (np. “Ty”).', 'automind'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Powitanie', 'automind'); ?></th>
                        <td>
                            <input type="text" name="automind_greeting" value="<?php echo esc_attr($greeting); ?>" style="width: 480px" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('System prompt', 'automind'); ?></th>
                        <td>
                            <textarea name="automind_system_prompt" rows="5" cols="80"><?php echo esc_textarea($sysPrompt); ?></textarea>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Język odpowiedzi', 'automind'); ?></th>
                        <td>
                            <select name="automind_reply_lang">
                                <option value="auto"   <?php selected($replyLang,'auto'); ?>><?php echo esc_html__('Auto (dopasuj do języka użytkownika)', 'automind'); ?></option>
                                <option value="plugin" <?php selected($replyLang,'plugin'); ?>><?php echo esc_html__('Język wtyczki (Settings → Plugin language)', 'automind'); ?></option>
                                <option value="wp"     <?php selected($replyLang,'wp'); ?>><?php echo esc_html__('Język WordPress (Site language)', 'automind'); ?></option>
                                <option value="pl"     <?php selected($replyLang,'pl'); ?>><?php echo esc_html__('Polski', 'automind'); ?></option>
                                <option value="en"     <?php selected($replyLang,'en'); ?>><?php echo esc_html__('English (US)', 'automind'); ?></option>
                            </select>
                            <p class="description">
                                <?php echo esc_html__('Polityka językowa nadpisuje wcześniejsze wskazówki w promptach. W trybie “Auto” odpowiadaj w języku ostatniej wiadomości użytkownika.', 'automind'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <h2>RAG</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php echo esc_html__('Użyj RAG', 'automind'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="automind_use_rag" value="1" <?php checked($useRag); ?> />
                                <?php echo esc_html__('Włącz wyszukiwanie w lokalnej bazie wiedzy (manualny import Q&A).', 'automind'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Tryb RAG', 'automind'); ?></th>
                        <td>
                            <select name="automind_rag_mode">
                                <option value="strict" <?php selected($ragMode, 'strict'); ?>><?php echo esc_html__('Ścisły (tylko kontekst)', 'automind'); ?></option>
                                <option value="soft"   <?php selected($ragMode, 'soft'); ?>><?php echo esc_html__('Miękki (preferuj kontekst, możesz dopowiedzieć ogólne info)', 'automind'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">topK</th>
                        <td><input type="number" name="automind_rag_topk" value="<?php echo esc_attr($ragTopK); ?>" min="1" max="20" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Limit kontekstu', 'automind'); ?></th>
                        <td><input type="number" name="automind_rag_context_limit" value="<?php echo esc_attr($ragLimit); ?>" min="256" max="8192" /></td>
                    </tr>
                </table>

                <?php submit_button(__('Zapisz', 'automind')); ?>
            </form>
        </div>
        <?php
    }
}