<?php
namespace Automind;

defined('ABSPATH') || exit;

class History {

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_post_automind_hist_save', [__CLASS__, 'handle_save']);
        add_action('admin_post_automind_hist_clear', [__CLASS__, 'handle_clear']);
    }

    protected static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'automind_history';
    }

    public static function ensure_table(): void {
        global $wpdb;
        $table = self::table();
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if ($exists === $table) return;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ts DATETIME NOT NULL,
            ip_hash CHAR(64) NULL,
            user_agent VARCHAR(255) NULL,
            question TEXT NULL,
            answer LONGTEXT NULL,
            lang VARCHAR(10) NULL,
            used_rag TINYINT(1) NOT NULL DEFAULT 0,
            model VARCHAR(64) NULL,
            PRIMARY KEY (id),
            KEY ts (ts),
            KEY used_rag (used_rag)
        ) {$charset};";
        dbDelta($sql);
    }

    protected static function client_ip(): string {
        foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'] as $k) {
            if (!empty($_SERVER[$k])) {
                $ip = is_string($_SERVER[$k]) ? $_SERVER[$k] : '';
                if ($k === 'HTTP_X_FORWARDED_FOR') $ip = trim(explode(',', $ip)[0]);
                return $ip;
            }
        }
        return '0.0.0.0';
    }
    protected static function ip_hash(): string {
        return hash('sha256', self::client_ip() . '|' . wp_salt('auth'));
    }

    public static function log_pair(array $args): void {
        if (get_option('automind_history_enabled','0') !== '1') return;
        self::ensure_table();
        global $wpdb; $table = self::table();

        $question = mb_substr((string)($args['question'] ?? ''), 0, 4000);
        $answer   = (string)($args['answer'] ?? '');
        if (mb_strlen($answer) > 100000) $answer = mb_substr($answer, 0, 100000) . '…';

        $row = [
            'ts'        => current_time('mysql'),
            'ip_hash'   => self::ip_hash(),
            'user_agent'=> mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            'question'  => $question,
            'answer'    => $answer,
            'lang'      => mb_substr((string)($args['lang'] ?? ''), 0, 10),
            'used_rag'  => !empty($args['used_rag']) ? 1 : 0,
            'model'     => mb_substr((string)($args['model'] ?? ''), 0, 64),
        ];
        $wpdb->insert($table, $row, ['%s','%s','%s','%s','%s','%s','%d','%s']);

        self::prune_by_limit();
    }

    protected static function prune_by_limit(): void {
        $limit = (int) get_option('automind_history_limit', 50);
        if ($limit <= 0) return;
        self::ensure_table();
        global $wpdb; $table = self::table();

        $offset = max(0, $limit - 1);
        $keepId = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d", $offset
        ));
        if ($keepId) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE id < %d", $keepId));
        }
    }

    public static function menu() {
        add_submenu_page(
            'automind',
            __('Automind — Historia rozmów', 'automind'),
            __('Historia', 'automind'),
            'manage_automind',
            'automind-history',
            [__CLASS__, 'render_page']
        );
    }

    public static function handle_save() {
        if (!current_user_can('manage_automind')) wp_die(__('Brak uprawnień.', 'automind'));
        // Unikalny nonce name: automind_hist_save_nonce
        check_admin_referer('automind_hist_save', 'automind_hist_save_nonce');

        $enabled = isset($_POST['automind_history_enabled']) ? '1' : '0';
        $limit   = isset($_POST['automind_history_limit']) ? (int) $_POST['automind_history_limit'] : 50;
        if ($limit < 10) $limit = 10;
        if ($limit > 500) $limit = 500;

        update_option('automind_history_enabled', $enabled);
        update_option('automind_history_limit', $limit);

        if ($enabled === '1') self::ensure_table();
        if ($enabled === '1') self::prune_by_limit();

        wp_safe_redirect(admin_url('admin.php?page=automind-history&updated=1'));
        exit;
    }

    public static function handle_clear() {
        if (!current_user_can('manage_automind')) wp_die(__('Brak uprawnień.', 'automind'));
        // Unikalny nonce name: automind_hist_clear_nonce
        check_admin_referer('automind_hist_clear', 'automind_hist_clear_nonce');

        self::ensure_table();
        global $wpdb; $table = self::table();
        $wpdb->query("TRUNCATE TABLE {$table}");

        wp_safe_redirect(admin_url('admin.php?page=automind-history&cleared=1'));
        exit;
    }

    public static function render_page() {
        if (!current_user_can('manage_automind')) wp_die(__('Brak uprawnień.', 'automind'));
        self::ensure_table();
        global $wpdb; $table = self::table();

        $enabled = get_option('automind_history_enabled','0') === '1';
        $limit   = (int) get_option('automind_history_limit', 50);

        $rows = $wpdb->get_results(
        $wpdb->prepare("SELECT id, ts, question, answer, lang, used_rag, model FROM {$table} ORDER BY id DESC LIMIT %d", max(10,$limit)),
        ARRAY_A
        );
        $rows = is_array($rows) ? array_reverse($rows) : [];

        $notice = '';
        if (!empty($_GET['updated'])) $notice = __('Zapisano ustawienia.', 'automind');
        if (!empty($_GET['cleared'])) $notice = __('Wyczyszczono historię.', 'automind');
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Automind — Historia rozmów', 'automind'); ?></h1>

            <?php if ($notice): ?>
                <div class="notice notice-success"><p><?php echo esc_html($notice); ?></p></div>
            <?php endif; ?>

            <!-- Formularz zapisu ustawień (unikalny nonce name) -->
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:12px 0 10px">
                <?php wp_nonce_field('automind_hist_save', 'automind_hist_save_nonce'); ?>
                <input type="hidden" name="action" value="automind_hist_save" />
                <label>
                    <input type="checkbox" name="automind_history_enabled" value="1" <?php checked($enabled); ?> />
                    <?php echo esc_html__('Zapisuj historię Q&A (przechowuje Pytanie i Odpowiedź)', 'automind'); ?>
                </label>
                <br/>
                <label>
                    <?php echo esc_html__('Limit wpisów', 'automind'); ?>:
                    <input type="number" name="automind_history_limit" value="<?php echo (int)$limit; ?>" min="10" max="500" />
                </label>
                <p class="description">
                    <?php echo esc_html__('Dla ochrony prywatności treści są zapisywane tylko gdy funkcja jest włączona. IP jest hashowane (SHA‑256 + salt).', 'automind'); ?>
                </p>
                <p><button class="button button-primary" type="submit"><?php echo esc_html__('Zapisz', 'automind'); ?></button></p>
            </form>

            <!-- Osobny formularz czyszczenia (unikalny nonce name) -->
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0 0 18px">
                <?php wp_nonce_field('automind_hist_clear', 'automind_hist_clear_nonce'); ?>
                <input type="hidden" name="action" value="automind_hist_clear" />
                <button class="button button-secondary" type="submit"
                    onclick="return confirm('<?php echo esc_attr__('Wyczyścić całą historię?', 'automind'); ?>');">
                    <?php echo esc_html__('Wyczyść historię', 'automind'); ?>
                </button>
            </form>

            <h2><?php echo esc_html__('Ostatnie wpisy', 'automind'); ?></h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php echo esc_html__('Data', 'automind'); ?></th>
                        <th><?php echo esc_html__('Język', 'automind'); ?></th>
                        <th><?php echo esc_html__('RAG', 'automind'); ?></th>
                        <th style="width:40%"><?php echo esc_html__('Pytanie', 'automind'); ?></th>
                        <th style="width:40%"><?php echo esc_html__('Odpowiedź', 'automind'); ?></th>
                        <th><?php echo esc_html__('Model', 'automind'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($rows) {
                        foreach ($rows as $r) {
                            echo '<tr>';
                            echo '<td>'.esc_html($r['ts']).'</td>';
                            echo '<td>'.esc_html($r['lang'] ?: '-').'</td>';
                            echo '<td>'.($r['used_rag'] ? 'tak':'nie').'</td>';
                            echo '<td>'.esc_html(mb_substr((string)$r['question'],0,300)).'</td>';
                            echo '<td>'.esc_html(mb_substr((string)$r['answer'],0,300)).'</td>';
                            echo '<td>'.esc_html((string)$r['model']).'</td>';
                            echo '</tr>';
                        }
                    } else {
                        echo '<tr><td colspan="6">'.esc_html__('Brak wpisów','automind').'</td></tr>';
                    } ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}