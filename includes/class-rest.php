<?php
namespace Automind;

defined('ABSPATH') || exit;

class Rest {

public static function init() {
    add_action('rest_api_init', [__CLASS__, 'routes']);
}

public static function routes() {
    register_rest_route('automind/v1', '/ping', [
        'methods'  => 'GET',
        'callback' => function () {
            return new \WP_REST_Response([
                'ok'      => true,
                'message' => 'pong',
                'version' => defined('AUTOMIND_VERSION') ? AUTOMIND_VERSION : 'dev',
            ], 200);
        },
        'permission_callback' => '__return_true',
    ]);

    register_rest_route('automind/v1', '/chat', [
        'methods'             => 'POST',
        'callback'            => [__CLASS__, 'chat'],
        'permission_callback' => '__return_true',
        'args' => [
            'message' => ['required' => true],
            'history' => ['required' => false],
            'botId'   => ['required' => false],
        ],
    ]);

    // Admin: modele/test/bearer
    register_rest_route('automind/v1', '/models', [
        'methods'  => 'GET',
        'callback' => [__CLASS__, 'models'],
        'permission_callback' => function () { return current_user_can('manage_automind'); },
    ]);
    register_rest_route('automind/v1', '/test', [
        'methods'  => 'POST',
        'callback' => [__CLASS__, 'test'],
        'permission_callback' => function () { return current_user_can('manage_automind'); },
    ]);
    register_rest_route('automind/v1', '/bearer/regenerate', [
        'methods'  => 'POST',
        'callback' => [__CLASS__, 'regen_bearer'],
        'permission_callback' => function () { return current_user_can('manage_automind'); },
    ]);

    // Admin: RAG
    register_rest_route('automind/v1', '/rag/status', [
        'methods'  => 'GET',
        'callback' => [__CLASS__, 'rag_status'],
        'permission_callback' => function () { return current_user_can('manage_automind'); },
    ]);
    register_rest_route('automind/v1', '/rag/clear', [
        'methods'  => 'POST',
        'callback' => [__CLASS__, 'rag_clear'],
        'permission_callback' => function () { return current_user_can('manage_automind'); },
    ]);
    register_rest_route('automind/v1', '/rag/manual', [
        'methods'  => 'POST',
        'callback' => [__CLASS__, 'rag_manual'],
        'permission_callback' => function () { return current_user_can('manage_automind'); },
        'args' => [
            'title'   => ['required' => true],
            'url'     => ['required' => false],
            'text'    => ['required' => true],
            'chunk'   => ['required' => false],
            'overlap' => ['required' => false],
        ],
    ]);
    register_rest_route('automind/v1', '/rag/reindex', [
        'methods'  => 'POST',
        'callback' => [__CLASS__, 'rag_reindex'],
        'permission_callback' => function () { return current_user_can('manage_automind'); },
        'args' => [
            'post_types'       => ['required' => true],
            'chunk'            => ['required' => false],
            'overlap'          => ['required' => false],
            'categories'       => ['required' => false],
            'include_children' => ['required' => false],
        ],
    ]);
}

// Helpers
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

protected static function rate_limit_check(): ?\WP_Error {
    $ip = md5(self::client_ip());
    $k1 = 'automind_rl_s_' . $ip;
    $k2 = 'automind_rl_m_' . $ip;
    $c1 = (int) get_transient($k1);
    $c2 = (int) get_transient($k2);
    if ($c1 >= 1)  return new \WP_Error('rate_limited', 'Za dużo żądań (1 req/s).', ['status' => 429]);
    if ($c2 >= 60) return new \WP_Error('rate_limited', 'Za dużo żądań (60/min).',  ['status' => 429]);
    set_transient($k1, $c1 + 1, 1);
    set_transient($k2, $c2 + 1, 60);
    return null;
}

protected static function check_bearer(\WP_REST_Request $req): ?\WP_Error {
    $enabled = get_option('automind_bearer_enabled', '0') === '1';
    if (!$enabled) return null;
    $expected = (string) get_option('automind_bearer_secret', '');
    $auth = $req->get_header('authorization') ?: $req->get_header('Authorization');
    $token = '';
    if (is_string($auth) && stripos($auth, 'Bearer ') === 0) $token = trim(substr($auth, 7));
    if (!$expected || !$token || !hash_equals($expected, $token)) {
        return new \WP_Error('forbidden', 'Brak lub błędny Bearer token.', ['status' => 403]);
    }
    return null;
}

// Handlers
public static function chat(\WP_REST_Request $req) {
    if ($e = self::rate_limit_check()) return $e;
    if ($e = self::check_bearer($req))  return $e;

    $text = trim((string) $req->get_param('message'));
    if ($text === '') return new \WP_Error('bad_request', __('Pusta wiadomość.', 'automind'), ['status' => 400]);
    if (mb_strlen($text) > 4000) return new \WP_Error('too_long', __('Wiadomość zbyt długa.', 'automind').' (max ~4000)', ['status' => 413]);

    $history = $req->get_param('history');
    $history = is_array($history) ? $history : [];

    $sys = get_option('automind_system_prompt',
        'Jesteś pomocnym asystentem Automind na stronie undercode.eu. Odpowiadaj krótko, chyba że użytkownik poprosi inaczej.'
    );

    $messages = [ ['role'=>'system','content'=>$sys] ];
    foreach ($history as $h) {
        if (!is_array($h)) continue;
        $r = $h['role'] ?? ''; $c = $h['content'] ?? '';
        if (in_array($r, ['user','assistant','system'], true) && is_string($c) && $c !== '') {
            $messages[] = ['role'=>$r, 'content'=>mb_substr($c, 0, 2000)];
        }
    }

    // RAG
    $sources  = []; // <-- źródła wyłączone
    $useRag   = get_option('automind_use_rag', '0') === '1';
    $ragTopK  = (int) get_option('automind_rag_topk', 5);
    $ragLimit = (int) get_option('automind_rag_context_limit', 2048);
    $mode     = get_option('automind_rag_mode', 'strict'); // strict|soft
    $hasContext = false;

    if ($useRag) {
        $ret = Rag::retrieve($text, [
            'topk'         => $ragTopK > 0 ? $ragTopK : 5,
            'limit'        => $ragLimit > 0 ? $ragLimit : 2048,
            'max_rows'     => 4000,
            'source_types' => ['manual','post','page'],
        ]);
        if (($ret['ok'] ?? false) && !empty($ret['context'])) {
            $hasContext = true;
            $context = $ret['context'];

            // Neutralne – polityka językowa dodana niżej
            $rag_sys_strict = "Use only the context below to answer the user's question. If the context does not contain an answer, briefly say that you don't have the data. Keep answers concise.\n\nContext:\n---\n{$context}\n---";
            $rag_sys_soft   = "Prefer the context below when answering. If there is no answer in the context, you may add safe, general information (no fabricated company-specific facts). If unsure, say briefly that data is missing. Keep answers concise and avoid long quotations.\n\nContext:\n---\n{$context}\n---";
            $messages[] = ['role'=>'system','content'=>($mode === 'soft' ? $rag_sys_soft : $rag_sys_strict)];
        }
    }

    // --- AUTO language detect for guardrail & hints ---
    $detect_lang = function(string $s): string {
        $s = trim($s);
        if ($s === '') return 'en';
        // Strong scripts
        if (preg_match('/\p{Han}/u', $s)) return 'zh';               // Chinese (CJK)
        if (preg_match('/[\x{0900}-\x{097F}]/u', $s)) return 'hi';   // Devanagari (Hindi)
        if (preg_match('/[\x{0600}-\x{06FF}]/u', $s)) return 'ar';   // Arabic
        if (preg_match('/[\x{3040}-\x{30FF}]/u', $s)) return 'ja';   // Japanese (kana)
        if (preg_match('/[\x{AC00}-\x{D7AF}]/u', $s)) return 'ko';   // Korean (Hangul)
        if (preg_match('/[\x{0400}-\x{04FF}]/u', $s)) return 'ru';   // Cyrillic (Russian)
        // Latin hints
        $latin = mb_strtolower($s, 'UTF-8');
        if (preg_match('/[ąćęłńóśżź]/u', $latin)) return 'pl';
        if (preg_match('/[áéíóúñü¿¡]/u', $latin)) return 'es';
        if (preg_match('/[àâçéèêëîïôùûüÿ]/u', $latin)) return 'fr';
        if (preg_match('/[äöüß]/u', $latin)) return 'de';
        if (preg_match('/[ãáâéêíóôõúç]/u', $latin)) return 'pt';
        if (preg_match('/[ìíîïèéêëòóôùú]/u', $latin) && preg_match('/\b(il|lo|la|gli|le|uno|una|di|che)\b/u', $latin)) return 'it';
        if (preg_match('/\b(de|het|een|en|je|wij|jij)\b/u', $latin)) return 'nl';
        if (preg_match('/\b(ve|veya|bir|için|ile)\b/u', $latin)) return 'tr';
        if (preg_match('/\b(dan|yang|untuk|apa|itu)\b/u', $latin)) return 'id';
        if (preg_match('/[ăâîșț]/u', $latin)) return 'ro';
        if (preg_match('/\b(và|của|cho|là)\b/u', $latin)) return 'vi';
        // default guess
        if (preg_match('/[A-Za-z]/', $s)) return 'en';
        return 'en';
    };

    $replyLang = get_option('automind_reply_lang','auto');
    $target = 'en';
    if ($replyLang === 'auto') {
        $target = $detect_lang($text);
    } elseif ($replyLang === 'plugin') {
        $target = (get_option('automind_locale','default') === 'pl_PL') ? 'pl' : (
            get_option('automind_locale') === 'zh_CN' ? 'zh' :
            (get_option('automind_locale') === 'hi_IN' ? 'hi' :
            (get_option('automind_locale') === 'es_ES' ? 'es' :
            (get_option('automind_locale') === 'fr_FR' ? 'fr' : 'en'))));
    } elseif ($replyLang === 'wp') {
        $sloc = function_exists('get_locale') ? get_locale() : 'en_US';
        $target = (strpos((string)$sloc, 'pl_') === 0) ? 'pl' :
                ((strpos((string)$sloc, 'zh_') === 0) ? 'zh' :
                ((strpos((string)$sloc, 'hi_') === 0) ? 'hi' :
                ((strpos((string)$sloc, 'es_') === 0) ? 'es' :
                ((strpos((string)$sloc, 'fr_') === 0) ? 'fr' : 'en'))));
    } elseif ($replyLang === 'pl') {
        $target = 'pl';
    } elseif ($replyLang === 'en') {
        $target = 'en';
    }

    // Language policy system hint (keep in EN, model understands)
    $langPolicy = ($replyLang === 'auto')
        ? "Language policy (overrides previous hints): Always reply in the same language as the user's last message. If unclear, reply in English."
        : ($target === 'pl'
            ? "Polityka językowa (nadpisuje wcześniejsze wskazówki): Odpowiadaj zawsze po polsku."
            : "Language policy (overrides previous hints): Always reply in {$target}.");
    $messages[] = ['role'=>'system','content'=>$langPolicy];

    // Strict no-data message per language
    $noData = [
    'en'=>"I don't have data on this topic.",
    'pl'=>'Nie mam danych na ten temat.',
    'es'=>'No tengo datos al respecto.',
    'fr'=>"Je n'ai pas d'informations à ce sujet.",
    'zh'=>'对此我没有相关数据。',
    'hi'=>'मेरे पास इस विषय की जानकारी नहीं है。',
    'de'=>'Dazu habe ich keine Daten.',
    'pt'=>'Não tenho dados sobre isso.',
    'ar'=>'لا توجد لدي بيانات حول هذا الموضوع.',
    'ja'=>'この件に関するデータはありません。',
    'ko'=>'이와 관련된 데이터가 없습니다。',
    'it'=>'Non ho dati a riguardo.',
    'ru'=>'У меня нет данных по этому вопросу.',
    'tr'=>'Bu konuda verim yok.',
    'nl'=>'Ik heb hier geen gegevens over.',
    'id'=>'Saya tidak memiliki data tentang itu.',
    'vi'=>'Tôi không có dữ liệu về vấn đề này.',
    ];
    $strictNoDataReply = $noData[$target] ?? $noData['en'];
    if ($useRag && $mode === 'strict' && !$hasContext) {
        if (get_option('automind_logs_enabled','0') === '1') {
            Logger::log_chat([
                'question'=>$text,'used_rag'=>($useRag && $hasContext),'sources'=>[],'model'=>get_option('automind_model','gpt-4o-mini'),'status'=>'guard'
            ]);
        }
        return new \WP_REST_Response([
            'ok'=>true,'reply'=>$strictNoDataReply,'usage'=>null,
            'model'=>get_option('automind_model','gpt-4o-mini'),'sources'=>[]
        ], 200);
    }

    $messages[] = ['role'=>'user','content'=>$text];

    $args = [
        'model'       => get_option('automind_model', 'gpt-4o-mini'),
        'temperature' => (float) get_option('automind_temperature', '0.3'),
        'max_tokens'  => (int) get_option('automind_max_tokens', 512),
    ];

    $res = OpenAI::chat($messages, $args);

    if (get_option('automind_logs_enabled','0') === '1') {
        if ($res['ok']) {
        Logger::log_chat([
            'question'=>$text,'used_rag'=>($useRag && $hasContext),'sources'=>[],
            'usage'=>$res['usage'] ?? null,'model'=>$res['model'] ?? '','status'=>'ok'
        ]);
        // Historia Q&A (jeśli włączona)
        \Automind\History::log_pair([
            'question'=>$text,
            'answer'=>$res['content'] ?? '',
            'lang'=>$target ?? 'en',
            'used_rag'=>($useRag && $hasContext),
            'model'=>$res['model'] ?? ($args['model'] ?? '')
        ]);
    } else {
        Logger::log_chat([
            'question'=>$text,'used_rag'=>($useRag && $hasContext),'sources'=>[],
            'model'=>$args['model'],'status'=>'error'
        ]);
    }
    }

    if (!$res['ok']) {
        return new \WP_REST_Response([
            'ok'=>false,'error'=>$res['error'] ?? __('Błąd połączenia.','automind'),'status'=>$res['status'] ?? 500
        ], $res['status'] ?? 500);
    }

    return new \WP_REST_Response([
        'ok'=>true,'reply'=>$res['content'],'usage'=>$res['usage'],
        'model'=>$res['model'],'sources'=>[] // puste
    ], 200);
}

public static function models(\WP_REST_Request $req) {
    $refresh  = (bool) $req->get_param('refresh');
    $cacheKey = 'automind_models_cache';
    if (!$refresh) {
        $cached = get_transient($cacheKey);
        if ($cached) return new \WP_REST_Response(['ok'=>true,'models'=>$cached,'cached'=>true], 200);
    }
    $key = defined('AUTOMIND_OPENAI_KEY') ? (string) constant('AUTOMIND_OPENAI_KEY') : '';
    if (!$key) return new \WP_REST_Response(['ok'=>false,'error'=>'Brak klucza OpenAI.'], 400);

    $resp = wp_remote_get('https://api.openai.com/v1/models', [
        'timeout'=>30,
        'headers'=>['Authorization'=>'Bearer '.$key],
    ]);
    if (is_wp_error($resp)) return new \WP_REST_Response(['ok'=>false,'error'=>$resp->get_error_message()], 500);

    $code = wp_remote_retrieve_response_code($resp);
    $body = json_decode(wp_remote_retrieve_body($resp), true);
    if ($code >= 400) {
        $msg = $body['error']['message'] ?? ('HTTP '.$code);
        return new \WP_REST_Response(['ok'=>false,'error'=>$msg], $code);
    }

    $items = $body['data'] ?? [];
    $ids = [];
    foreach ($items as $it) {
        if (!empty($it['id'])) {
            $id = (string) $it['id'];
            if (preg_match('/^(gpt\-|o|ft:)/', $id)) $ids[] = $id;
        }
    }
    sort($ids);
    if (!$ids) $ids = ['gpt-4o-mini', 'gpt-4o'];

    set_transient($cacheKey, $ids, 12 * HOUR_IN_SECONDS);
    return new \WP_REST_Response(['ok'=>true,'models'=>$ids,'cached'=>false], 200);
}

public static function test(\WP_REST_Request $req) {
    $model = get_option('automind_model','gpt-4o-mini');
    $res = OpenAI::chat([
        ['role'=>'system','content'=>'Jesteś testowym asystentem Automind. Odpowiadaj jednym słowem: OK.'],
        ['role'=>'user','content'=>'Sprawdź połączenie.'],
    ], ['model'=>$model,'temperature'=>0,'max_tokens'=>2]);

    if (!$res['ok']) return new \WP_REST_Response(['ok'=>false,'error'=>$res['error'] ?? 'Błąd'], 500);
    return new \WP_REST_Response(['ok'=>true,'model'=>$res['model'],'usage'=>$res['usage']], 200);
}

public static function regen_bearer() {
    try { $secret = bin2hex(random_bytes(32)); } catch (\Throwable $e) { $secret = wp_generate_password(64, false, false); }
    update_option('automind_bearer_secret', $secret);
    return new \WP_REST_Response(['ok'=>true,'secret'=>$secret], 200);
}

// RAG admin
public static function rag_status() {
    $st = Rag::status();
    return new \WP_REST_Response($st, ($st['ok'] ?? false) ? 200 : 500);
}
public static function rag_clear() {
    $res = Rag::clear_index();
    return new \WP_REST_Response($res, ($res['ok'] ?? false) ? 200 : 500);
}
public static function rag_manual(\WP_REST_Request $req) {
    $title = sanitize_text_field((string) $req->get_param('title'));
    $url   = $req->get_param('url'); $url = $url ? esc_url_raw((string) $url) : null;
    $text  = (string) $req->get_param('text');
    $chunk = (int) $req->get_param('chunk');   if ($chunk <= 0) $chunk = 1000;
    $over  = (int) $req->get_param('overlap'); if ($over < 0) $over = 200; if ($over >= $chunk) $over = (int) floor($chunk/4);
    if ($title === '' || $text === '') return new \WP_REST_Response(['ok'=>false,'error'=>'Brak tytułu lub tekstu.'], 400);
    if (mb_strlen($text) > 300000) return new \WP_REST_Response(['ok'=>false,'error'=>'Tekst zbyt duży (limit ~300k).'], 413);

    $res = Rag::index_manual($title, $url, $text, ['chunk_size'=>$chunk,'overlap'=>$over,'model'=>'text-embedding-3-small']);
    return new \WP_REST_Response($res, ($res['ok'] ?? false) ? 200 : 500);
}
public static function rag_reindex(\WP_REST_Request $req) {
    $pts = $req->get_param('post_types'); $pts = is_array($pts) ? array_values($pts) : [];
    $pts = array_values(array_filter($pts, function($t){ return in_array($t, ['post','page'], true); }));
    $chunk = (int) $req->get_param('chunk');   if ($chunk <= 0) $chunk = 1000;
    $over  = (int) $req->get_param('overlap'); if ($over < 0) $over = 200; if ($over >= $chunk) $over = (int) floor($chunk/4);
    if (empty($pts)) return new \WP_REST_Response(['ok'=>false,'error'=>'Zaznacz Posty i/lub Strony.'], 400);

    // Kategorie (array lub "1,2")
    $catsRaw = $req->get_param('categories');
    $cats = [];
    if (is_array($catsRaw)) {
        $cats = array_values(array_unique(array_map('intval', $catsRaw)));
    } elseif (is_string($catsRaw) && trim($catsRaw) !== '') {
        $cats = array_values(array_unique(array_map('intval', explode(',', $catsRaw))));
    }
    $include_children = $req->get_param('include_children');
    $include_children = ($include_children === '0' || $include_children === 0 || $include_children === false) ? false : true;

    update_option('automind_rag_post_cats', $cats);
    update_option('automind_rag_include_children', $include_children ? '1' : '0');

    $res = \Automind\Rag::reindex_posts($pts, $chunk, $over, [
        'categories'       => $cats,
        'include_children' => $include_children,
    ]);
    return new \WP_REST_Response($res, ($res['ok'] ?? false) ? 200 : 500);
}
}