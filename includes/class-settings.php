<?php
namespace Automind;

defined('ABSPATH') || exit;

class Settings {

    public static function init() {
        add_action('admin_init', [__CLASS__, 'register']);
    }

    public static function register() {
        // OpenAI
        register_setting('automind_settings', 'automind_openai_key', [
            'type' => 'string', 'sanitize_callback' => [__CLASS__, 'sanitize_secret'], 'default' => '',
        ]);
        register_setting('automind_settings', 'automind_model', [
            'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => 'gpt-4o-mini',
        ]);
        register_setting('automind_settings', 'automind_temperature', [
            'type' => 'string', 'sanitize_callback' => [__CLASS__, 'sanitize_temperature'], 'default' => '0.3',
        ]);
        register_setting('automind_settings', 'automind_max_tokens', [
            'type' => 'integer', 'sanitize_callback' => [__CLASS__, 'sanitize_max_tokens'], 'default' => 512,
        ]);

        // Security
        register_setting('automind_settings', 'automind_bearer_enabled', [
            'type' => 'string', 'sanitize_callback' => [__CLASS__, 'sanitize_bool'], 'default' => '0',
        ]);

        // Logs
        register_setting('automind_settings', 'automind_logs_enabled', [
            'type' => 'string', 'sanitize_callback' => [__CLASS__, 'sanitize_bool'], 'default' => '0',
        ]);
        register_setting('automind_settings', 'automind_logs_retention', [
            'type' => 'integer', 'sanitize_callback' => [__CLASS__, 'sanitize_retention'], 'default' => 0,
        ]);

        // Popup base
        register_setting('automind_settings', 'automind_popup_enabled', [
            'type' => 'string', 'sanitize_callback' => [__CLASS__, 'sanitize_bool'], 'default' => '0',
        ]);
        register_setting('automind_settings', 'automind_popup_position', [
            'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => 'right', // right|left
        ]);

        // Popup rules
        register_setting('automind_settings', 'automind_popup_delay', [
            'type' => 'integer', 'sanitize_callback' => [__CLASS__, 'sanitize_int'], 'default' => 0,
        ]);
        register_setting('automind_settings', 'automind_popup_show_mobile', [
            'type' => 'string', 'sanitize_callback' => [__CLASS__, 'sanitize_bool'], 'default' => '1',
        ]);
        register_setting('automind_settings', 'automind_popup_show_desktop', [
            'type' => 'string', 'sanitize_callback' => [__CLASS__, 'sanitize_bool'], 'default' => '1',
        ]);
        register_setting('automind_settings', 'automind_popup_hide_regex', [
            'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '',
        ]);
        register_setting('automind_settings', 'automind_popup_accent', [
            'type' => 'string', 'sanitize_callback' => [__CLASS__, 'sanitize_color'], 'default' => '#0b5cab',
        ]);

        // Popup icon – preset / custom URL / size
        register_setting('automind_settings', 'automind_popup_icon_preset', [
            'type' => 'string', 'sanitize_callback' => [__CLASS__, 'sanitize_preset'], 'default' => 'default',
        ]);
        register_setting('automind_settings', 'automind_popup_icon_url', [
            'type' => 'string', 'sanitize_callback' => [__CLASS__, 'sanitize_url'], 'default' => '',
        ]);
        register_setting('automind_settings', 'automind_popup_icon_size', [
            'type' => 'integer', 'sanitize_callback' => [__CLASS__, 'sanitize_icon_size'], 'default' => 32,
        ]);

        // Język wtyczki (przełącznik)
        register_setting('automind_settings', 'automind_locale', [
            'type' => 'string', 'sanitize_callback' => [__CLASS__, 'sanitize_locale'], 'default' => 'en_US',
        ]);
    }

    // Sanitizers
    public static function sanitize_secret($v){ return trim((string)$v); }
    public static function sanitize_temperature($v){ $t=(float)$v; if($t<0)$t=0; if($t>2)$t=2; return (string)$t; }
    public static function sanitize_max_tokens($v){ $n=(int)$v; if($n<64)$n=64; if($n>4096)$n=4096; return $n; }
    public static function sanitize_bool($v){ return ($v==='1'||$v===1||$v===true||$v==='on')?'1':'0'; }
    public static function sanitize_retention($v){ $n=(int)$v; if($n<0)$n=0; if($n>365)$n=365; return $n; }
    public static function sanitize_int($v){ $n=(int)$v; if($n<0)$n=0; if($n>99999999)$n=99999999; return $n; }
    public static function sanitize_color($v){ $v=trim((string)$v); if($v==='') return '#0b5cab'; return preg_match('/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/',$v)?$v:'#0b5cab'; }
    public static function sanitize_url($v){ return esc_url_raw(trim((string)$v)); }
    public static function sanitize_icon_size($v){ $n=(int)$v; if($n<16)$n=16; if($n>128)$n=128; return $n; }
    public static function sanitize_locale($v){
    $v = trim((string)$v);
    $allowed = [
        'default','en_US','pl_PL',
        'zh_CN','hi_IN','es_ES','fr_FR',
        'de_DE','pt_BR','ar','ja_JP','ko_KR'
    ];
    return in_array($v, $allowed, true) ? $v : 'default';
    }

    // Helpers ikon
    protected static function icon_slug(string $base): string {
        return strtolower(preg_replace('/[^a-z0-9\-_.]/', '-', $base));
    }
    protected static function find_icon_by_slug(string $slug): ?string {
        $dir = AUTOMIND_PATH . 'assets/icons/';
        if (!is_dir($dir)) return null;
        foreach (glob($dir . '*.svg') as $full) {
            $base = basename($full, '.svg');
            if (self::icon_slug($base) === $slug) {
                return $full; // pełna ścieżka
            }
        }
        return null;
    }

    // preset = 'custom' | 'default' | slug pliku (niezależnie od spacji/wielkich liter)
    public static function sanitize_preset($v){
        $v = trim(strtolower((string)$v));
        if ($v === 'custom' || $v === 'default') return $v;
        if (!preg_match('/^[a-z0-9\-_.]+$/', $v)) return 'default';
        return self::find_icon_by_slug($v) ? $v : 'default';
    }

    public static function render_page() {
        if (!current_user_can('manage_automind')) wp_die(__('Brak uprawnień.', 'automind'));

        // Skanuj katalog ikon → lista slug => real file
        $icons = [];
        $icons_dir = AUTOMIND_PATH . 'assets/icons/';
        if (is_dir($icons_dir)) {
            foreach (glob($icons_dir . '*.svg') as $full) {
                $base = basename($full, '.svg'); // bez .svg
                $slug = self::icon_slug($base);
                $icons[$slug] = $base . '.svg';
            }
        }
        ksort($icons);

        $keyDefined = (defined('AUTOMIND_OPENAI_KEY') && (string) constant('AUTOMIND_OPENAI_KEY') !== '');

        $model       = get_option('automind_model','gpt-4o-mini');
        $temperature = get_option('automind_temperature','0.3');
        $maxTokens   = (int) get_option('automind_max_tokens',512);

        $bearerOn    = get_option('automind_bearer_enabled','0')==='1';

        $logsOn      = get_option('automind_logs_enabled','0')==='1';
        $retention   = (int) get_option('automind_logs_retention',0);

        $popEnabled  = get_option('automind_popup_enabled','0')==='1';
        $popPos      = get_option('automind_popup_position','right');
        $popDelay    = (int) get_option('automind_popup_delay',0);
        $popMob      = get_option('automind_popup_show_mobile','1')==='1';
        $popDesk     = get_option('automind_popup_show_desktop','1')==='1';
        $popRegex    = (string) get_option('automind_popup_hide_regex','');
        $popAccent   = (string) get_option('automind_popup_accent','#0b5cab');

        $preset      = (string) get_option('automind_popup_icon_preset','default');
        $iconUrl     = (string) get_option('automind_popup_icon_url','');
        $iconSize    = (int) get_option('automind_popup_icon_size',32);

        // NOWE: język wtyczki
        $uiLocale    = (string) get_option('automind_locale', 'default');
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Automind — Ustawienia', 'automind'); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields('automind_settings'); ?>

                <h2>OpenAI</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php echo esc_html__('Klucz API', 'automind'); ?></th>
                        <td>
                            <?php if ($keyDefined): ?>
                                <span style="color:green"><?php echo esc_html__('Wykryto w wp-config.php (AUTOMIND_OPENAI_KEY)', 'automind'); ?></span>
                            <?php else: ?>
                                <input type="password" name="automind_openai_key" placeholder="sk-..." style="width:320px" autocomplete="off" />
                                <p class="description"><?php echo esc_html__('Zalecane: dodać klucz w wp-config.php. To pole zapisuje klucz w bazie.', 'automind'); ?></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Model', 'automind'); ?></th>
                        <td>
                            <select name="automind_model" id="automind_model">
                                <option value="<?php echo esc_attr($model); ?>"><?php echo esc_html($model); ?></option>
                            </select>
                            <button type="button" class="button" id="am-refresh-models"><?php echo esc_html__('Odśwież listę modeli', 'automind'); ?></button>
                            <span id="am-models-status" style="margin-left:8px;color:#666"></span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Temperatura', 'automind'); ?></th>
                        <td><input type="number" step="0.1" min="0" max="2" name="automind_temperature" value="<?php echo esc_attr($temperature); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Max tokens', 'automind'); ?></th>
                        <td><input type="number" min="64" max="4096" name="automind_max_tokens" value="<?php echo esc_attr($maxTokens); ?>" /></td>
                    </tr>
                </table>

                <h2><?php echo esc_html__('Bezpieczeństwo', 'automind'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Bearer</th>
                        <td>
                            <label><input type="checkbox" name="automind_bearer_enabled" value="1" <?php checked($bearerOn); ?> />
                                <?php echo esc_html__('Wymagaj Bearer dla /chat (zewnętrzne fronty)', 'automind'); ?></label>
                        </td>
                    </tr>
                </table>

                <h2><?php echo esc_html__('Logi', 'automind'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php echo esc_html__('Włącz logi', 'automind'); ?></th>
                        <td><label><input type="checkbox" name="automind_logs_enabled" value="1" <?php checked($logsOn); ?> />
                            <?php echo esc_html__('Loguj metadane rozmów', 'automind'); ?></label></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Retencja (dni)', 'automind'); ?></th>
                        <td><input type="number" min="0" max="365" name="automind_logs_retention" value="<?php echo esc_attr($retention); ?>" /></td>
                    </tr>
                </table>

                <h2><?php echo esc_html__('Język wtyczki', 'automind'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php echo esc_html__('Język interfejsu', 'automind'); ?></th>
                        <td>
                            <select name="automind_locale">
                                <option value="default" <?php selected($uiLocale,'default'); ?>>
                                    <?php echo esc_html__('Domyślny (wg WordPress)', 'automind'); ?>
                                </option>
                                <option value="en_US" <?php selected($uiLocale,'en_US'); ?>>English (US)</option>
                                <option value="pl_PL" <?php selected($uiLocale,'pl_PL'); ?>>Polski (PL)</option>
                                <option value="zh_CN" <?php selected($uiLocale,'zh_CN'); ?>>简体中文 (zh_CN)</option>
                                <option value="hi_IN" <?php selected($uiLocale,'hi_IN'); ?>>हिंदी (hi_IN)</option>
                                <option value="es_ES" <?php selected($uiLocale,'es_ES'); ?>>Español (es_ES)</option>
                                <option value="fr_FR" <?php selected($uiLocale,'fr_FR'); ?>>Français (fr_FR)</option>
                                <option value="de_DE" <?php selected($uiLocale,'de_DE'); ?>>Deutsch (de_DE)</option>
                                <option value="pt_BR" <?php selected($uiLocale,'pt_BR'); ?>>Português (Brasil)</option>
                                <option value="ar"    <?php selected($uiLocale,'ar');    ?>>العربية (ar)</option>
                                <option value="ja_JP" <?php selected($uiLocale,'ja_JP'); ?>>日本語 (ja_JP)</option>
                                <option value="ko_KR" <?php selected($uiLocale,'ko_KR'); ?>>한국어 (ko_KR)</option>
                                </select>
                            <p class="description">
                                <?php echo esc_html__('Możesz wymusić język wtyczki niezależnie od języka WordPress.', 'automind'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <h2>Popup</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php echo esc_html__('Włącz popup', 'automind'); ?></th>
                        <td>
                            <label><input type="checkbox" name="automind_popup_enabled" value="1" <?php checked($popEnabled); ?> />
                                <?php echo esc_html__('Pokaż bąbelek czatu', 'automind'); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Pozycja', 'automind'); ?></th>
                        <td>
                            <select name="automind_popup_position">
                                <option value="right" <?php selected($popPos,'right'); ?>><?php echo esc_html__('Prawy dół', 'automind'); ?></option>
                                <option value="left"  <?php selected($popPos,'left');  ?>><?php echo esc_html__('Lewy dół', 'automind'); ?></option>
                            </select>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php echo esc_html__('Opóźnienie pojawienia (ms)', 'automind'); ?></th>
                        <td><input type="number" min="0" max="600000" name="automind_popup_delay" value="<?php echo esc_attr($popDelay); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Widoczność', 'automind'); ?></th>
                        <td>
                            <label><input type="checkbox" name="automind_popup_show_mobile" value="1" <?php checked($popMob); ?> /> <?php echo esc_html__('Pokaż na mobile', 'automind'); ?></label>
                            &nbsp;&nbsp;
                            <label><input type="checkbox" name="automind_popup_show_desktop" value="1" <?php checked($popDesk); ?> /> <?php echo esc_html__('Pokaż na desktop', 'automind'); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Ukryj na stronach (regex URL)', 'automind'); ?></th>
                        <td>
                            <input type="text" name="automind_popup_hide_regex" value="<?php echo esc_attr($popRegex); ?>" style="width:420px" placeholder="/checkout|cart/i" />
                            <p class="description"><?php echo esc_html__('Przykład: /cart|checkout/i — nie pokazuj na koszyku i checkout.', 'automind'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Kolor akcentu', 'automind'); ?></th>
                        <td><input type="text" name="automind_popup_accent" value="<?php echo esc_attr($popAccent); ?>" placeholder="#0b5cab" /></td>
                    </tr>

                    <tr>
                        <th scope="row"><?php echo esc_html__('Wbudowana ikona', 'automind'); ?></th>
                        <td>
                            <select name="automind_popup_icon_preset">
                                <option value="default" <?php selected($preset,'default'); ?>><?php echo esc_html__('Domyślna (dymek)', 'automind'); ?></option>
                                <?php
                                if ($icons) {
                                    foreach ($icons as $slug => $file) {
                                        echo '<option value="'.esc_attr($slug).'" '.selected($preset,$slug,false).'>'.esc_html($slug.' ('.$file.')').'</option>';
                                    }
                                }
                                ?>
                                <option value="custom" <?php selected($preset,'custom'); ?>><?php echo esc_html__('Własny URL', 'automind'); ?></option>
                            </select>
                            <p class="description"><?php echo esc_html__('Aby dodać ikonę do listy, wrzuć plik .svg do: wp-content/plugins/automind/assets/icons/', 'automind'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Ikona (URL SVG/PNG)', 'automind'); ?></th>
                        <td>
                            <input type="url" name="automind_popup_icon_url" value="<?php echo esc_attr($iconUrl); ?>" placeholder="https://..." style="width:420px" />
                            <p class="description"><?php echo esc_html__('Używane tylko gdy wybrano “Własny URL”.', 'automind'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Rozmiar ikony (px)', 'automind'); ?></th>
                        <td><input type="number" min="16" max="128" name="automind_popup_icon_size" value="<?php echo esc_attr($iconSize); ?>" /></td>
                    </tr>
                </table>

                <?php submit_button(__('Zapisz ustawienia','automind')); ?>
                <button type="button" class="button button-primary" id="am-test-openai" style="margin-left:8px"><?php echo esc_html__('Test połączenia', 'automind'); ?></button>
                <span id="am-test-status" style="margin-left:8px;color:#666"></span>
            </form>
        </div>
        <?php
    }
}