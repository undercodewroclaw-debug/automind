<?php
namespace Automind;

defined('ABSPATH') || exit;

class Logger {

    public static function init() {
        add_action('init', [__CLASS__, 'maybe_schedule']);
        add_action('automind_logger_prune_daily', [__CLASS__, 'prune']);
    }

    protected static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'automind_logs';
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
            used_rag TINYINT(1) NOT NULL DEFAULT 0,
            sources LONGTEXT NULL,
            tokens_in INT UNSIGNED NULL,
            tokens_out INT UNSIGNED NULL,
            model VARCHAR(64) NULL,
            status VARCHAR(20) NULL,
            PRIMARY KEY (id),
            KEY ts (ts),
            KEY used_rag (used_rag)
        ) {$charset};";

        dbDelta($sql);
    }

    protected static function salt(): string { return wp_salt('auth'); }

    protected static function client_ip(): string {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
            if (!empty($_SERVER[$k])) {
                $ip = is_string($_SERVER[$k]) ? $_SERVER[$k] : '';
                if ($k === 'HTTP_X_FORWARDED_FOR') $ip = trim(explode(',', $ip)[0]);
                return $ip;
            }
        }
        return '0.0.0.0';
    }

    protected static function ip_hash(): string {
        return hash('sha256', self::client_ip() . '|' . self::salt());
    }

    public static function log_chat(array $args): void {
        if (get_option('automind_logs_enabled', '0') !== '1') return;
        self::ensure_table();
        global $wpdb;
        $table = self::table();

        $question   = isset($args['question']) ? (string) $args['question'] : '';
        $question   = mb_substr($question, 0, 2000);
        $used_rag   = !empty($args['used_rag']) ? 1 : 0;

        $sourcesArr = is_array($args['sources'] ?? null) ? $args['sources'] : [];
        $short = [];
        foreach (array_slice($sourcesArr, 0, 5) as $s) {
            $short[] = [
                'title' => mb_substr((string)($s['title'] ?? ''), 0, 200),
                'url'   => mb_substr((string)($s['url'] ?? ''), 0, 200),
            ];
        }
        $sources = wp_json_encode($short);

        $usage      = $args['usage'] ?? null;
        $tokens_in  = is_array($usage) ? (int)($usage['prompt_tokens'] ?? 0) : null;
        $tokens_out = is_array($usage) ? (int)($usage['completion_tokens'] ?? 0) : null;

        $row = [
            'ts'         => current_time('mysql'),
            'ip_hash'    => self::ip_hash(),
            'user_agent' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            'question'   => $question,
            'used_rag'   => $used_rag,
            'sources'    => $sources,
            'tokens_in'  => $tokens_in,
            'tokens_out' => $tokens_out,
            'model'      => mb_substr((string)($args['model'] ?? ''), 0, 64),
            'status'     => mb_substr((string)($args['status'] ?? 'ok'), 0, 20),
        ];

        $wpdb->insert($table, $row, ['%s','%s','%s','%s','%d','%s','%d','%d','%s','%s']);
    }

    public static function prune(): void {
        $days = (int) get_option('automind_logs_retention', 0);
        if ($days <= 0) return;
        self::ensure_table();
        global $wpdb;
        $table = self::table();

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE ts < (NOW() - INTERVAL %d DAY)",
            $days
        ));
    }

    public static function maybe_schedule(): void {
        if (!wp_next_scheduled('automind_logger_prune_daily')) {
            wp_schedule_event(time() + 3600, 'daily', 'automind_logger_prune_daily');
        }
    }

    public static function render_page() {
        if (!current_user_can('manage_automind')) wp_die(__('Brak uprawnień.', 'automind'));
        self::ensure_table();
        global $wpdb; $table = self::table();

        $total  = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $last24 = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE ts > (NOW() - INTERVAL 1 DAY)");
        $ragCnt = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE used_rag = 1");

        $rows = $wpdb->get_results("
            SELECT id, ts, question, used_rag, model, tokens_in, tokens_out, status
            FROM {$table}
            ORDER BY id DESC
            LIMIT 50
        ", ARRAY_A);

        $top = $wpdb->get_results("
            SELECT question, COUNT(*) AS c
            FROM {$table}
            WHERE ts > (NOW() - INTERVAL 14 DAY)
              AND question IS NOT NULL
              AND question <> ''
            GROUP BY question
            ORDER BY c DESC
            LIMIT 10
        ", ARRAY_A);

        // i18n helpers
        $logsEnabled = get_option('automind_logs_enabled','0')==='1';
        $statusText  = $logsEnabled ? esc_html__('Enabled', 'automind') : esc_html__('Disabled', 'automind');
        $ret         = (int) get_option('automind_logs_retention', 0);
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Automind — Logi i diagnostyka', 'automind'); ?></h1>

            <h2><?php echo esc_html__('Podsumowanie', 'automind'); ?></h2>
            <ul>
                <li><?php echo esc_html__('Razem rozmów:', 'automind'); ?> <strong><?php echo (int)$total; ?></strong></li>
                <li><?php echo esc_html__('Ostatnie 24 h:', 'automind'); ?> <strong><?php echo (int)$last24; ?></strong></li>
                <li><?php echo esc_html__('Użyto RAG:', 'automind'); ?> <strong><?php echo (int)$ragCnt; ?></strong></li>
                <li><?php echo sprintf(
                    esc_html__('Logs: %s (retention: %d days)', 'automind'),
                    $statusText,
                    $ret
                ); ?></li>
            </ul>

            <h2><?php echo esc_html__('Top pytania (14 dni)', 'automind'); ?></h2>
            <ol>
                <?php
                if ($top) {
                    foreach ($top as $t) {
                        echo '<li>' . esc_html(mb_substr((string)$t['question'], 0, 140)) .
                             ' — <em>' . (int)$t['c'] . '×</em></li>';
                    }
                } else {
                    echo '<li>' . esc_html__('Brak danych', 'automind') . '</li>';
                }
                ?>
            </ol>

            <h2><?php echo esc_html__('Ostatnie 50 rozmów', 'automind'); ?></h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php echo esc_html__('Data', 'automind'); ?></th>
                        <th><?php echo esc_html__('Pytanie', 'automind'); ?></th>
                        <th><?php echo esc_html__('RAG', 'automind'); ?></th>
                        <th><?php echo esc_html__('Model', 'automind'); ?></th>
                        <th><?php echo esc_html__('Tok. in/out', 'automind'); ?></th>
                        <th><?php echo esc_html__('Status', 'automind'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if ($rows) {
                        foreach ($rows as $r) {
                            echo '<tr>';
                            echo '<td>' . esc_html($r['ts']) . '</td>';
                            echo '<td>' . esc_html(mb_substr((string)$r['question'], 0, 140)) . '</td>';
                            echo '<td>' . ($r['used_rag'] ? esc_html__('yes', 'automind') : esc_html__('no', 'automind')) . '</td>';
                            echo '<td>' . esc_html((string)$r['model']) . '</td>';
                            echo '<td>' . (int)$r['tokens_in'] . ' / ' . (int)$r['tokens_out'] . '</td>';
                            echo '<td>' . esc_html((string)$r['status']) . '</td>';
                            echo '</tr>';
                        }
                    } else {
                        echo '<tr><td colspan="6">' . esc_html__('Brak wpisów', 'automind') . '</td></tr>';
                    }
                    ?>
                </tbody>
            </table>

            <p class="description" style="margin-top:10px">
                <?php echo esc_html__('IP jest hashowane (SHA-256 + salt). Treści odpowiedzi nie zapisujemy. Włącz/wyłącz logi i retencję w: Automind → Ustawienia.', 'automind'); ?>
            </p>
        </div>
        <?php
    }
}