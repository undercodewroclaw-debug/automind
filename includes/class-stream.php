<?php
namespace Automind;

defined('ABSPATH') || exit;

class Stream {

    public static function init() {
    add_action('rest_api_init', [__CLASS__, 'routes']);
}

public static function routes() {
    register_rest_route('automind/v1', '/chat/stream', [
        'methods'             => 'POST',
        'callback'            => [__CLASS__, 'stream'],
        'permission_callback' => '__return_true',
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
    $k1 = 'automind_sse_s_' . $ip;
    $k2 = 'automind_sse_m_' . $ip;
    $c1 = (int) get_transient($k1);
    $c2 = (int) get_transient($k2);
    if ($c1 >= 1)  return new \WP_Error('rate_limited', 'Za dużo żądań (1 req/s).', ['status' => 429]);
    if ($c2 >= 30) return new \WP_Error('rate_limited', 'Za dużo żądań (30/min).',  ['status' => 429]);
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
protected static function get_key(): string {
    return defined('AUTOMIND_OPENAI_KEY') ? (string) constant('AUTOMIND_OPENAI_KEY') : '';
}
protected static function send_headers(): void {
    if (!headers_sent()) {
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-transform');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');
    }
    @ini_set('output_buffering', 'off');
    @ini_set('zlib.output_compression', '0');
    while (ob_get_level() > 0) { @ob_end_flush(); }
    @ob_implicit_flush(true);
    echo "retry: 5000\n\n";
    @flush();
}
protected static function sse_send(string $event, $data): void {
    echo "event: {$event}\n";
    echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    @flush();
}

public static function stream(\WP_REST_Request $req) {
    if ($e = self::rate_limit_check()) return $e;
    if ($e = self::check_bearer($req))  return $e;

    $key = self::get_key();
    if (!$key) return new \WP_REST_Response(['ok'=>false,'error'=>__('Brak klucza OpenAI.','automind')], 400);

    $text = trim((string) $req->get_param('message'));
    if ($text === '')  return new \WP_REST_Response(['ok'=>false,'error'=>__('Pusta wiadomość.','automind')], 400);
    if (mb_strlen($text) > 4000) return new \WP_REST_Response(['ok'=>false,'error'=>__('Wiadomość zbyt długa.','automind')], 413);

    $history = $req->get_param('history'); $history = is_array($history) ? $history : [];

    $sys = get_option('automind_system_prompt',
        'Jesteś pomocnym asystentem Automind na stronie undercode.eu. Odpowiadaj krótko, chyba że użytkownik poprosi inaczej.'
    );

    $messages = [ ['role'=>'system','content'=>$sys] ];
    foreach ($history as $h) {
        if (!is_array($h)) continue;
        $r = $h['role'] ?? ''; $c = $h['content'] ?? '';
        if (in_array($r, ['user','assistant','system'], true) && is_string($c) && $c !== '') {
            $messages[] = ['role'=>$r,'content'=>mb_substr($c, 0, 2000)];
        }
    }

    // RAG
    $sources=[]; $useRag=get_option('automind_use_rag','0')==='1';
    $ragTopK=(int)get_option('automind_rag_topk',5);
    $ragLimit=(int)get_option('automind_rag_context_limit',2048);
    $mode=get_option('automind_rag_mode','strict');
    $hasContext=false;

    if ($useRag) {
        $ret = Rag::retrieve($text, [
            'topk'=>$ragTopK>0?$ragTopK:5,'limit'=>$ragLimit>0?$ragLimit:2048,
            'prefetch'=>300,'source_types'=>['manual','post','page']
        ]);
        if (($ret['ok'] ?? false) && !empty($ret['context'])) {
            $hasContext=true;
            $context = $ret['context'];

            // Neutralne – bez wymuszania języka
            $rag_sys_strict="Use only the context below to answer the user's question. If the context does not contain an answer, briefly say that you don't have the data. Keep answers concise.\n\nContext:\n---\n{$context}\n---";
            $rag_sys_soft="Prefer the context below when answering. If there is no answer in the context, you may add safe, general information (no fabricated company-specific facts). If unsure, say briefly that data is missing. Keep answers concise and avoid long quotations.\n\nContext:\n---\n{$context}\n---";
            $messages[]=['role'=>'system','content'=>($mode==='soft'?$rag_sys_soft:$rag_sys_strict)];
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
    if ($useRag && $mode==='strict' && !$hasContext) {
        self::sse_send('delta', ['delta'=>$strictNoDataReply]);
        self::sse_send('done',  ['ok'=>true]);
        if (get_option('automind_logs_enabled','0')==='1') {
            Logger::log_chat(['question'=>$text,'used_rag'=>($useRag && $hasContext),'sources'=>[],'model'=>get_option('automind_model','gpt-4o-mini'),'status'=>'guard']);
        }
        exit;
    }

    if (!function_exists('curl_init')) {
        self::sse_send('error', ['message'=>'cURL not available on server']);
        self::sse_send('done',  ['ok'=>false]);
        exit;
    }

    $model = get_option('automind_model','gpt-4o-mini');
    $temperature = (float) get_option('automind_temperature','0.3');
    $max_tokens = (int) get_option('automind_max_tokens',512);

    $messages[]=['role'=>'user','content'=>$text];

    $payload = [
        'model'       => $model,
        'messages'    => $messages,
        'temperature' => $temperature,
        'max_tokens'  => $max_tokens,
        'stream'      => true,
        'stream_options' => ['include_usage'=>true],
    ];

    $finalUsage = null;
    $finalText  = '';

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer '.$key],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_WRITEFUNCTION  => function($ch, $chunk) use (&$finalUsage) {
            static $buffer = '';
            $send = function($ev, $data){ echo "event: {$ev}\n"; echo "data: ".json_encode($data,JSON_UNESCAPED_UNICODE)."\n\n"; @flush(); };
            $buffer .= $chunk;
            while(($pos = strpos($buffer, "\n\n")) !== false){
                $frame = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 2);
                $lines = explode("\n", $frame);
                $dataLine = '';
                foreach ($lines as $ln) { if (stripos($ln,'data:') === 0) $dataLine .= trim(substr($ln, 5)); }
                if ($dataLine === '') continue;
                if ($dataLine === '[DONE]') { $send('done', ['ok'=>true]); continue; }
                $obj = json_decode($dataLine, true);
                if (!is_array($obj)) continue;
                $delta = $obj['choices'][0]['delta']['content'] ?? '';
                if ($delta !== '') $finalText .= $delta; $send('delta', ['delta'=>$delta]);
                if (!empty($obj['usage'])) { $finalUsage = $obj['usage']; $send('usage', $obj['usage']); }
            }
            return strlen($chunk);
        },
        CURLOPT_BUFFERSIZE     => 8192,
        CURLOPT_TIMEOUT        => 0,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_HEADER         => false,
    ]);

    @ignore_user_abort(true);
    @set_time_limit(0);
    curl_exec($ch);
    if ($err = curl_error($ch)) {
        self::sse_send('error', ['message'=>$err]);
    }
    curl_close($ch);

if (get_option('automind_logs_enabled','0')==='1') {
    Logger::log_chat([
        'question'=>$text,
        'used_rag'=>($useRag && $hasContext),
        'sources'=>[],
        'usage'=>$finalUsage,
        'model'=>$model,
        'status'=>'stream'
    ]);
}

// Historia Q&A (jeśli włączona)
if (class_exists('\Automind\History')) {
    \Automind\History::log_pair([
        'question'=>$text,
        'answer'=>$finalText ?? '',
        'lang'=>$target ?? 'en',
        'used_rag'=>($useRag && $hasContext),
        'model'=>$model
    ]);
}

exit;
}
}