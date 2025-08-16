<?php
namespace Automind;

defined('ABSPATH') || exit;

class Rag {

    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'automind_embeddings';
    }

    public static function ensure_table(): void {
        global $wpdb;
        $table = self::table_name();

        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if ($exists !== $table) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            $charset = $wpdb->get_charset_collate();
            $sql = "CREATE TABLE {$table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                source_type VARCHAR(20) NOT NULL,
                source_id BIGINT UNSIGNED NULL,
                title TEXT NULL,
                url TEXT NULL,
                chunk_index INT UNSIGNED NOT NULL,
                chunk_text LONGTEXT NOT NULL,
                embedding LONGTEXT NOT NULL,
                vector_dim SMALLINT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY source_type (source_type),
                KEY source_id (source_id)
            ) {$charset};";
            dbDelta($sql);
        }

        // FULLTEXT na chunk_text (jeśli brak)
        $has_ft = $wpdb->get_var("SHOW INDEX FROM {$table} WHERE Key_name = 'am_ft_chunk'");
        if (!$has_ft) {
            $wpdb->query("ALTER TABLE {$table} ADD FULLTEXT am_ft_chunk (chunk_text)");
        }
    }

    public static function clean_text(string $html_or_text): string {
        $text = wp_strip_all_tags($html_or_text, true);
        $text = preg_replace('/\r\n|\r/u', "\n", $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text);
        $text = preg_replace('/\n{3,}/u', "\n\n", $text);
        return trim($text);
    }

    public static function chunk_text(string $text, int $size = 1000, int $overlap = 200): array {
        $text = (string) $text;
        if ($size < 200) $size = 200;
        if ($overlap < 0) $overlap = 0;
        if ($overlap >= $size) $overlap = (int) floor($size / 4);

        $len = mb_strlen($text, 'UTF-8');
        if ($len <= $size) return [$text];

        $chunks = [];
        $step = max(1, $size - $overlap);
        for ($start = 0; $start < $len; $start += $step) {
            $chunk = mb_substr($text, $start, $size, 'UTF-8');
            $chunk = trim($chunk);
            if ($chunk !== '') $chunks[] = $chunk;
            if ($start + $size >= $len) break;
        }
        return $chunks;
    }

    public static function normalize_vector(array $vec): array {
        $sum = 0.0;
        foreach ($vec as $v) { $f = (float) $v; $sum += $f * $f; }
        if ($sum <= 0) return array_map('floatval', $vec);
        $inv = 1.0 / sqrt($sum);
        foreach ($vec as $i => $v) { $vec[$i] = (float) $v * $inv; }
        return $vec;
    }

    protected static function dot(array $a, array $b): float {
        $n = min(count($a), count($b));
        $s = 0.0;
        for ($i = 0; $i < $n; $i++) $s += (float)$a[$i] * (float)$b[$i];
        return $s;
    }

    // Import ręczny (Q&A / tekst)
    public static function index_manual(string $title, ?string $url, string $rawText, array $opts = []): array {
        global $wpdb;
        self::ensure_table();
        $table = self::table_name();

        $title = sanitize_text_field($title ?: 'Manual import');
        $url   = $url ? esc_url_raw($url) : null;

        $chunkSize = isset($opts['chunk_size']) ? (int) $opts['chunk_size'] : 1000;
        $overlap   = isset($opts['overlap']) ? (int) $opts['overlap'] : 200;
        $model     = $opts['model'] ?? 'text-embedding-3-small';

        $clean  = self::clean_text($rawText);
        $chunks = self::chunk_text($clean, $chunkSize, $overlap);
        if (!$chunks) return ['ok' => true, 'inserted' => 0, 'dim' => 0];

        $batchSize = 32;
        $allVectors = [];
        $dim = 0;
        for ($i = 0; $i < count($chunks); $i += $batchSize) {
            $slice = array_slice($chunks, $i, $batchSize);
            $res = OpenAI::embed($slice, $model);
            if (!$res['ok']) return ['ok' => false, 'error' => $res['error'] ?? 'Embedding error'];
            $vectors = $res['vectors'] ?? [];
            $dim = max($dim, (int) ($res['dim'] ?? 0));
            foreach ($vectors as $v) $allVectors[] = self::normalize_vector($v);
        }

        $now = current_time('mysql');
        $inserted = 0;
        foreach ($chunks as $idx => $chunk) {
            $vec = $allVectors[$idx] ?? null;
            if (!$vec) continue;

            $row = [
                'source_type' => 'manual',
                'source_id'   => null,
                'title'       => $title,
                'url'         => $url,
                'chunk_index' => $idx,
                'chunk_text'  => $chunk,
                'embedding'   => wp_json_encode($vec),
                'vector_dim'  => $dim,
                'created_at'  => $now,
                'updated_at'  => $now,
            ];
            $ok = $wpdb->insert($table, $row, ['%s','%d','%s','%s','%d','%s','%s','%d','%s','%s']);
            if ($ok !== false) $inserted++;
        }

        return ['ok' => true, 'inserted' => $inserted, 'dim' => $dim];
    }

    // Reindeks postów/stron z opcjonalnym filtrem kategorii dla 'post'
    public static function reindex_posts(array $post_types, int $chunk_size = 1000, int $overlap = 200, array $opts = []): array {
        global $wpdb;

        self::ensure_table();
        $table = self::table_name();

        // Walidacja typu
        $allowed = ['post', 'page'];
        $post_types = array_values(array_intersect($allowed, array_map('strval', $post_types)));
        if (empty($post_types)) {
            return ['ok' => false, 'error' => 'Nie wybrano typów treści (post/page).'];
        }

        // Filtrowanie kategorii (dla postów)
        $catIdsRaw = [];
        if (!empty($opts['categories']) && is_array($opts['categories'])) {
            foreach ($opts['categories'] as $cid) {
                $cid = (int) $cid;
                if ($cid > 0) $catIdsRaw[] = $cid;
            }
        }
        $catIdsRaw = array_values(array_unique($catIdsRaw));
        $includeChildren = true;
        if (isset($opts['include_children'])) {
            $includeChildren = (bool) $opts['include_children'];
        }

        // Rozszerz o dzieci
        $catIds = $catIdsRaw;
        if ($catIds && $includeChildren) {
            foreach ($catIdsRaw as $cid) {
                $children = get_term_children($cid, 'category');
                if (!is_wp_error($children) && $children) {
                    foreach ($children as $ch) { $catIds[] = (int) $ch; }
                }
            }
            $catIds = array_values(array_unique(array_map('intval', $catIds)));
        }

        // Usuń stare wpisy dla wybranych typów
        $ph = implode(',', array_fill(0, count($post_types), '%s'));
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE source_type IN ($ph)", $post_types));

        $totalPosts = 0;
        $totalChunks = 0;
        $inserted = 0;
        $dim = 0;
        $now = current_time('mysql');

        foreach ($post_types as $type) {
            $args = [
                'post_type'        => $type,
                'post_status'      => 'publish',
                'posts_per_page'   => -1,
                'fields'           => 'ids',
                'suppress_filters' => true,
                'ignore_sticky_posts' => true,
                'no_found_rows'    => true,
            ];

            // Tylko dla 'post': filtr kategorii
            if ($type === 'post' && $catIds) {
                $args['category__in'] = $catIds;
            }

            $ids = get_posts($args);
            if (!$ids) continue;

            foreach ($ids as $pid) {
                $p = get_post($pid);
                if (!$p) continue;

                $title = get_the_title($pid);
                $url   = get_permalink($pid);
                $content = self::clean_text($p->post_content);
                $text = trim($title . "\n\n" . $content);

                $chunks = self::chunk_text($text, $chunk_size, $overlap);
                if (!$chunks) continue;

                $totalPosts++;
                $totalChunks += count($chunks);

                // Embeddingi partiami
                $batchSize = 32;
                for ($i = 0; $i < count($chunks); $i += $batchSize) {
                    $slice = array_slice($chunks, $i, $batchSize);
                    $res = OpenAI::embed($slice, 'text-embedding-3-small');
                    if (!$res['ok']) {
                        return ['ok' => false, 'error' => $res['error'] ?? 'Embedding error', 'post_id' => $pid];
                    }
                    $vectors = $res['vectors'] ?? [];
                    $dim = max($dim, (int) ($res['dim'] ?? 0));

                    foreach ($vectors as $j => $vec) {
                        $vec = self::normalize_vector($vec);
                        $row = [
                            'source_type' => $type,
                            'source_id'   => $pid,
                            'title'       => $title,
                            'url'         => $url,
                            'chunk_index' => $i + $j,
                            'chunk_text'  => $slice[$j],
                            'embedding'   => wp_json_encode($vec),
                            'vector_dim'  => $dim,
                            'created_at'  => $now,
                            'updated_at'  => $now,
                        ];
                        $ok = $wpdb->insert($table, $row, ['%s','%d','%s','%s','%d','%s','%s','%d','%s','%s']);
                        if ($ok !== false) $inserted++;
                    }
                }
            }
        }

        return [
            'ok'          => true,
            'posts'       => $totalPosts,
            'chunks'      => $totalChunks,
            'inserted'    => $inserted,
            'dim'         => $dim,
        ];
    }

    public static function clear_index(): array {
        global $wpdb;
        self::ensure_table();
        $table = self::table_name();
        $wpdb->query("TRUNCATE TABLE {$table}");
        return ['ok' => true];
    }

    public static function status(): array {
        global $wpdb;
        self::ensure_table();
        $table = self::table_name();
        $row = $wpdb->get_row("SELECT COUNT(*) AS total, MAX(vector_dim) AS dim FROM {$table}", ARRAY_A);
        return [
            'ok'    => true,
            'total' => (int) ($row['total'] ?? 0),
            'dim'   => (int) ($row['dim'] ?? 0),
        ];
    }

    // Retrieve z FULLTEXT prefilter
    public static function retrieve(string $query, array $opts = []): array {
    global $wpdb;
    self::ensure_table();
    $table = self::table_name();

    $topK        = max(1, (int) ($opts['topk'] ?? 5));
    $limit       = max(256, (int) ($opts['limit'] ?? 2048));
    $prefetch    = max($topK, (int) ($opts['prefetch'] ?? 300));
    $maxRows     = max($prefetch, (int) ($opts['max_rows'] ?? 4000));
    $srcTypes    = $opts['source_types'] ?? ['manual'];
    // NOWE: kontrola źródeł i progów podobieństwa
    $maxSources  = max(1, (int) ($opts['max_sources'] ?? 1));     // ile linków w “Źródła”
    $minSimAbs   = isset($opts['min_sim']) ? (float)$opts['min_sim'] : 0.78; // próg bezwzględny
    $relDrop     = isset($opts['rel_drop']) ? (float)$opts['rel_drop'] : 0.08; // max spadek względem top-1

    // Embedding pytania
    $er = OpenAI::embed([$query], 'text-embedding-3-small');
    if (!$er['ok']) return ['ok' => false, 'error' => $er['error'] ?? 'Embedding error'];
    $qvec = $er['vectors'][0] ?? [];
    if (!$qvec) return ['ok' => true, 'items' => [], 'context' => '', 'sources' => []];
    $qvec = self::normalize_vector($qvec);

    // FULLTEXT prefilter (jeśli jest indeks)
    $useFT = (bool) $wpdb->get_var("SHOW INDEX FROM {$table} WHERE Key_name = 'am_ft_chunk'");
    $rows = [];
    $ph   = implode(',', array_fill(0, count($srcTypes), '%s'));

    if ($useFT) {
        $sql = "SELECT id, title, url, chunk_index, chunk_text, embedding, vector_dim,
                       MATCH(chunk_text) AGAINST (%s IN NATURAL LANGUAGE MODE) AS ft_score
                FROM {$table}
                WHERE source_type IN ($ph)
                ORDER BY ft_score DESC
                LIMIT %d";
        $params = array_merge([$query], $srcTypes, [$prefetch]);
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
    }

    if (!$rows) {
        $sql = "SELECT id, title, url, chunk_index, chunk_text, embedding, vector_dim
                FROM {$table}
                WHERE source_type IN ($ph)
                ORDER BY id DESC
                LIMIT %d";
        $params = array_merge($srcTypes, [$maxRows]);
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
    }

    if (!$rows) return ['ok' => true, 'items' => [], 'context' => '', 'sources' => []];

    $scored = [];
    foreach ($rows as $r) {
        $vec = json_decode($r['embedding'] ?? '[]', true);
        if (!is_array($vec) || !$vec) continue;
        $score = self::dot($qvec, $vec);
        $scored[] = [
            'score' => (float) $score,
            'title' => (string) ($r['title'] ?? ''),
            'url'   => (string) ($r['url'] ?? ''),
            'text'  => (string) ($r['chunk_text'] ?? ''),
            'id'    => (int) $r['id'],
            'idx'   => (int) $r['chunk_index'],
        ];
    }
    if (!$scored) return ['ok' => true, 'items' => [], 'context' => '', 'sources' => []];

    usort($scored, function($a, $b){
        if ($a['score'] === $b['score']) return 0;
        return ($a['score'] > $b['score']) ? -1 : 1;
    });

    // TopK do kontekstu
    $items = array_slice($scored, 0, $topK);

    // NOWE: filtr podobieństwa i deduplikacja po URL/tytule
    if (!empty($items)) {
        $s0 = (float) $items[0]['score'];
        $filtered = [];
        $seenSrc = [];
        foreach ($items as $i) {
            $key = $i['url'] ?: $i['title'] ?: (string)($i['id'] ?? '');
            $okSim = ($s0 <= 0) ? true : ($i['score'] >= max($minSimAbs, $s0 - $relDrop));
            if (!$okSim) continue;
            if ($key !== '' && isset($seenSrc[$key])) continue;
            $seenSrc[$key] = true;
            $filtered[] = $i;
        }
        if ($filtered) $items = $filtered;
    }

    // Budowa kontekstu i źródeł (limitujemy liczbę źródeł do $maxSources)
    $context = '';
    $usedLen = 0;
    $sep = "\n---\n";
    $sources = [];
    $seenForSources = [];

    foreach ($items as $i) {
        $head = trim(($i['title'] ?: 'Źródło') . ($i['url'] ? ' — ' . $i['url'] : ''));
        $piece = ($head !== '' ? ($head . "\n") : '') . trim($i['text']);
        if ($piece === '') continue;

        $remain = max(0, $limit - $usedLen);
        if ($remain <= 0) break;

        if (mb_strlen($piece) > $remain) {
            $piece = mb_substr($piece, 0, $remain - 1) . '…';
        }

        $context .= ($context === '' ? '' : $sep) . $piece;
        $usedLen = mb_strlen($context);

        // Źródła — tylko najlepsze (max $maxSources)
        $key = $i['url'] ?: $i['title'];
        if ($key && !isset($seenForSources[$key]) && count($sources) < $maxSources) {
            $seenForSources[$key] = true;
            $sources[] = ['title' => $i['title'] ?: 'Źródło', 'url' => $i['url'] ?: ''];
        }
    }

    return ['ok' => true, 'items' => $items, 'context' => $context, 'sources' => $sources];
    }
}