<?php
namespace Automind;

defined('ABSPATH') || exit;

class OpenAI {

    /**
     * Zwraca klucz OpenAI:
     * 1) preferowany z wp-config.php (AUTOMIND_OPENAI_KEY)
     * 2) fallback z opcji w DB (automind_openai_key)
     */
    protected static function get_key(): string {
        // 1) wp-config.php (zalecane)
        if (defined('AUTOMIND_OPENAI_KEY')) {
            $const = (string) constant('AUTOMIND_OPENAI_KEY');
            if ($const !== '') {
                return $const;
            }
        }
        // 2) fallback: opcja w DB (jeśli chcesz trzymać klucz w bazie)
        $opt = (string) get_option('automind_openai_key', '');
        return $opt !== '' ? $opt : '';
    }

    /**
     * Wywołanie Chat Completions.
     * @param array $messages  Tablica wiadomości [{role:'system|user|assistant', content:'...'}]
     * @param array $args      ['model' => 'gpt-4o-mini', 'temperature' => 0.3, 'max_tokens' => 512]
     * @return array           ['ok'=>bool, 'content'=>string, 'usage'=>array|null, 'model'=>string, 'error'=>string?, 'status'=>int?]
     */
    public static function chat(array $messages, array $args = []): array {
        $key = self::get_key();
        if ($key === '') {
            return ['ok' => false, 'error' => 'Brak klucza OpenAI (AUTOMIND_OPENAI_KEY lub automind_openai_key).'];
        }

        $model       = isset($args['model']) ? (string) $args['model'] : 'gpt-4o-mini';
        $temperature = isset($args['temperature']) ? (float) $args['temperature'] : 0.3;
        if ($temperature < 0) $temperature = 0;
        if ($temperature > 2) $temperature = 2;
        $max_tokens  = isset($args['max_tokens']) ? (int) $args['max_tokens'] : 512;
        if ($max_tokens < 1) $max_tokens = 1;

        // Upewnij się, że messages to tablica tablic {role, content}
        $msgs = [];
        foreach ($messages as $m) {
            if (is_array($m)) {
                $role = isset($m['role']) ? (string) $m['role'] : '';
                $content = isset($m['content']) ? (string) $m['content'] : '';
                if ($role !== '' && $content !== '') {
                    $msgs[] = ['role' => $role, 'content' => $content];
                }
            }
        }

        $resp = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'timeout' => 60,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $key,
            ],
            'body' => wp_json_encode([
                'model'       => $model,
                'messages'    => $msgs,
                'temperature' => $temperature,
                'max_tokens'  => $max_tokens,
            ]),
        ]);

        if (is_wp_error($resp)) {
            return ['ok' => false, 'error' => $resp->get_error_message()];
        }

        $code = (int) wp_remote_retrieve_response_code($resp);
        $body = json_decode(wp_remote_retrieve_body($resp), true);

        if ($code >= 400) {
            $msg = is_array($body) ? ($body['error']['message'] ?? 'HTTP ' . $code) : ('HTTP ' . $code);
            return ['ok' => false, 'error' => $msg, 'status' => $code];
        }

        $content = is_array($body) ? ($body['choices'][0]['message']['content'] ?? '') : '';
        $usage   = is_array($body) ? ($body['usage'] ?? null) : null;

        return [
            'ok'      => true,
            'content' => $content,
            'usage'   => $usage,
            'model'   => $model,
        ];
    }

    /**
     * Wywołanie Embeddings.
     * @param array  $texts  Tablica stringów do embedowania
     * @param string $model  Domyślnie 'text-embedding-3-small'
     * @return array         ['ok'=>bool, 'vectors'=>array[], 'dim'=>int, 'usage'=>array|null, 'error'=>string?, 'status'=>int?]
     */
    public static function embed(array $texts, string $model = 'text-embedding-3-small'): array {
        $key = self::get_key();
        if ($key === '') {
            return ['ok' => false, 'error' => 'Brak klucza OpenAI (AUTOMIND_OPENAI_KEY lub automind_openai_key).'];
        }
        if (empty($texts)) {
            return ['ok' => true, 'vectors' => [], 'dim' => 0, 'usage' => null];
        }

        // Upewnij się, że wszystkie elementy są stringami
        $inputs = [];
        foreach (array_values($texts) as $t) {
            if (is_array($t))        $t = wp_json_encode($t);
            elseif (!is_string($t))  $t = (string) $t;
            $inputs[] = $t;
        }

        $resp = wp_remote_post('https://api.openai.com/v1/embeddings', [
            'timeout' => 60,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $key,
            ],
            'body' => wp_json_encode([
                'model' => $model,
                'input' => $inputs,
            ]),
        ]);

        if (is_wp_error($resp)) {
            return ['ok' => false, 'error' => $resp->get_error_message()];
        }

        $code = (int) wp_remote_retrieve_response_code($resp);
        $body = json_decode(wp_remote_retrieve_body($resp), true);

        if ($code >= 400) {
            $msg = is_array($body) ? ($body['error']['message'] ?? 'HTTP ' . $code) : ('HTTP ' . $code);
            return ['ok' => false, 'error' => $msg, 'status' => $code];
        }

        $data    = is_array($body) ? ($body['data'] ?? []) : [];
        $vectors = [];
        $dim     = 0;

        foreach ($data as $row) {
            $vec = $row['embedding'] ?? null;
            if (is_array($vec)) {
                $vec = array_map('floatval', $vec);
                $dim = max($dim, count($vec));
                $vectors[] = $vec;
            }
        }

        return [
            'ok'      => true,
            'vectors' => $vectors,
            'dim'     => $dim,
            'usage'   => $body['usage'] ?? null,
        ];
    }
}