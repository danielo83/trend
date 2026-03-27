<?php

class TopicFilter
{
    private PDO $db;
    private int $maxArticles;
    private float $similarityThreshold;
    private string $wpCachePath;

    /** @var string[] Concetti core gia' esistenti (da WP cache + SQLite) */
    private array $existingConcepts = [];

    /** @var bool Abilita dedup con embeddings OpenAI */
    private bool $embeddingEnabled;

    /** @var string Chiave API OpenAI per embeddings */
    private string $openaiKey;

    /** @var float Soglia cosine similarity per embeddings (0.85 = molto simili) */
    private float $embeddingSimilarityThreshold;

    /** @var string Path al file cache embeddings */
    private string $embeddingCachePath;

    /** @var array Cache in-memory degli embeddings */
    private array $embeddingCache = [];

    /** @var callable|null */
    private $logCallback = null;

    // Configurazione WordPress per fetch diretto dei post pubblicati
    private string $wpSiteUrl;
    private string $wpUsername;
    private string $wpAppPassword;
    private int $wpCacheTtl;

    // Prefissi da strippare (ordine: dal piu' lungo al piu' corto per evitare match parziali)
    private const STRIP_PREFIXES = [
        // Sogni - varianti lunghe prima
        'cosa significa sognare di', 'cosa significa sognare', 'cosa vuol dire sognare di', 'cosa vuol dire sognare',
        'significato del sogno di', 'significato del sogno', 'significato sogno di', 'significato sogno',
        'interpretazione del sogno di', 'interpretazione del sogno', 'interpretazione sogno di', 'interpretazione sogno',
        'ho sognato di', 'ho sognato che', 'ho sognato',
        'sogno ricorrente di', 'sogno ricorrente',
        'perché sogno sempre di', 'perché sogno sempre', 'perché sogno di', 'perché sogno',
        'perchè sogno sempre di', 'perchè sogno sempre', 'perchè sogno di', 'perchè sogno',
        'sognare di', 'sognare un', 'sognare una', 'sognare il', 'sognare la',
        'sognare i', 'sognare le', 'sognare lo', 'sognare gli', 'sognare',
        // Sonno
        'come fare per dormire', 'come riuscire a dormire', 'come dormire meglio',
        'come dormire bene', 'come dormire',
        'non riesco a dormire', 'dormire meglio', 'dormire bene', 'dormire',
        'come addormentarsi velocemente', 'come addormentarsi', 'addormentarsi',
        'perché mi sveglio di notte', 'perché mi sveglio alle', 'perché mi sveglio',
        'perchè mi sveglio di notte', 'perchè mi sveglio alle', 'perchè mi sveglio',
        'svegliarsi di notte', 'svegliarsi',
        // Smorfia
        'smorfia napoletana', 'numeri smorfia', 'nella smorfia', 'smorfia',
    ];

    // Suffissi da strippare (boilerplate dei titoli WordPress)
    private const STRIP_SUFFIXES = [
        'significato interpretazione e numeri', 'significato e interpretazione psicologica',
        'significato e interpretazione', 'cosa significa davvero interpretazione e simboli',
        'cosa significa davvero', 'cosa significa e come interpretarlo',
        'cosa significa e perché il cervello lo crea', 'cosa significa',
        'significato e simboli', 'significato psicologico', 'significato',
        'interpretazione e simbolismo', 'interpretazione',
        'come interpretarlo', 'come interpretarli',
        'rimedi e consigli', 'cause e rimedi', 'rimedi naturali', 'rimedi',
    ];

    // Stop words italiane da rimuovere
    private const STOP_WORDS = [
        'di', 'il', 'lo', 'la', 'i', 'gli', 'le', 'un', 'una', 'uno',
        'del', 'dello', 'della', 'dei', 'degli', 'delle',
        'nel', 'nello', 'nella', 'nei', 'negli', 'nelle',
        'al', 'allo', 'alla', 'ai', 'agli', 'alle',
        'che', 'e', 'per', 'con', 'da', 'dal', 'dalla',
        'su', 'sul', 'sulla', 'tra', 'fra',
        'non', 'come', 'cosa', 'sono', 'è', 'ha',
        'si', 'ci', 'ne', 'mi', 'ti', 'vi',
        'a', 'o', 'ma', 'se', 'più', 'piu', 'molto', 'anche',
    ];

    public function __construct(array $config)
    {
        $this->maxArticles = $config['max_articles_per_run'];
        $this->similarityThreshold = floatval($config['topic_similarity_threshold'] ?? 0.7);
        $this->wpCachePath = ($config['base_dir'] ?? __DIR__ . '/..') . '/data/cache_wp_posts.json';
        $this->embeddingEnabled = !empty($config['embedding_dedup_enabled']);
        $this->openaiKey = $config['openai_api_key'] ?? '';
        $this->embeddingSimilarityThreshold = floatval($config['embedding_similarity_threshold'] ?? 0.85);
        $this->embeddingCachePath = ($config['base_dir'] ?? __DIR__ . '/..') . '/data/cache_embeddings.json';
        $this->wpSiteUrl = rtrim($config['wp_site_url'] ?? '', '/');
        $this->wpUsername = $config['wp_username'] ?? '';
        $this->wpAppPassword = $config['wp_app_password'] ?? '';
        $this->wpCacheTtl = max(1800, intval($config['link_cache_ttl'] ?? 21600));
        $this->db = new PDO('sqlite:' . $config['db_path']);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->initDb();
        // Aggiorna la cache WP prima di caricare i concetti esistenti,
        // per includere anche i post scritti manualmente sul sito WordPress.
        if (!$this->isWpCacheValid() && $this->isWpConfigured()) {
            $this->syncWpPostsToCache();
        }
        $this->loadExistingConcepts();
        if ($this->embeddingEnabled) {
            $this->loadEmbeddingCache();
        }
    }

    public function setLogCallback(callable $callback): void
    {
        $this->logCallback = $callback;
    }

    private function log(string $message, string $type = 'detail'): void
    {
        error_log("[TopicFilter] " . $message);
        if ($this->logCallback !== null) {
            ($this->logCallback)('[FILTRO] ' . $message, $type);
        }
    }

    /**
     * Crea la tabella se non esiste.
     */
    private function initDb(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS topics (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                hash TEXT UNIQUE NOT NULL,
                topic TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'pending',
                created_at TEXT DEFAULT NULL,
                completed_at TEXT DEFAULT NULL
            )
        ");
    }

    /**
     * Carica i concetti core gia' esistenti da:
     * 1. Cache WordPress (titoli e slug di tutti i post pubblicati)
     * 2. Database SQLite locale (topic elaborati)
     */
    private function loadExistingConcepts(): void
    {
        $this->existingConcepts = [];
        $sources = 0;

        // 1. Cache WordPress
        if (file_exists($this->wpCachePath)) {
            $data = json_decode(@file_get_contents($this->wpCachePath), true);
            $posts = $data['posts'] ?? [];

            foreach ($posts as $post) {
                // Estrai concetto dal titolo
                $title = $post['title'] ?? '';
                if (!empty($title)) {
                    $concept = $this->extractCoreConcept($title);
                    if (!empty($concept)) {
                        $this->existingConcepts[] = $concept;
                    }
                }

                // Estrai concetto dallo slug (dall'URL)
                $url = $post['url'] ?? '';
                if (!empty($url)) {
                    $path = parse_url($url, PHP_URL_PATH);
                    $slug = trim($path, '/');
                    $slugText = str_replace('-', ' ', $slug);
                    $concept = $this->extractCoreConcept($slugText);
                    if (!empty($concept) && !in_array($concept, $this->existingConcepts, true)) {
                        $this->existingConcepts[] = $concept;
                    }
                }
            }
            $sources = count($posts);
        }

        // 2. SQLite locale (topic con status completed o in_progress)
        $stmt = $this->db->query('SELECT topic FROM topics WHERE status IN ("completed", "in_progress")');
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $concept = $this->extractCoreConcept($row['topic']);
            if (!empty($concept) && !in_array($concept, $this->existingConcepts, true)) {
                $this->existingConcepts[] = $concept;
            }
        }

        // Deduplica
        $this->existingConcepts = array_values(array_unique($this->existingConcepts));

        $this->log("Caricati " . count($this->existingConcepts) . " concetti esistenti ({$sources} post WP + DB locale)", 'detail');
    }

    /**
     * Estrae il "concetto core" da un testo, strippando prefissi/suffissi
     * comuni della nicchia e stop words italiane.
     *
     * Es: "Cosa significa sognare gatti neri" => "gatti neri"
     *     "Sognare di cadere nel vuoto: significato e interpretazione" => "cadere vuoto"
     */
    public function extractCoreConcept(string $text): string
    {
        $text = mb_strtolower(trim($text));

        // Rimuovi punteggiatura
        $text = preg_replace('/[?!:;,.\x{2013}\x{2014}\x{2026}]+/u', ' ', $text);
        $text = preg_replace('/\s+/', ' ', trim($text));

        // Strippa prefissi (dal piu' lungo al piu' corto)
        $matchedPrefix = '';
        foreach (self::STRIP_PREFIXES as $prefix) {
            if (str_starts_with($text, $prefix . ' ') || $text === $prefix) {
                $matchedPrefix = $prefix;
                $text = trim(mb_substr($text, mb_strlen($prefix)));
                break; // Solo il primo match
            }
        }

        // Strippa suffissi
        foreach (self::STRIP_SUFFIXES as $suffix) {
            if (str_ends_with($text, ' ' . $suffix) || $text === $suffix) {
                $text = trim(mb_substr($text, 0, mb_strlen($text) - mb_strlen($suffix)));
                break;
            }
        }

        // Rimuovi stop words
        $words = preg_split('/\s+/', trim($text));
        $contentWords = [];
        foreach ($words as $word) {
            $word = trim($word);
            if (mb_strlen($word) < 2) continue;
            if (in_array($word, self::STOP_WORDS, true)) continue;
            // Rimuovi numeri puri (es. "7", "10") ma mantieni "h2", "b12", etc.
            if (preg_match('/^\d+$/', $word)) continue;
            $contentWords[] = $word;
        }

        // Se dopo lo strip non restano parole significative,
        // usa il prefisso stesso come concetto (es: "dormire bene" → il concetto E' "dormire bene")
        if (empty($contentWords) && !empty($matchedPrefix)) {
            $prefixWords = preg_split('/\s+/', $matchedPrefix);
            foreach ($prefixWords as $pw) {
                if (mb_strlen($pw) >= 2 && !in_array($pw, self::STOP_WORDS, true)) {
                    $contentWords[] = $pw;
                }
            }
        }

        // Ordina alfabeticamente per rendere il confronto order-independent
        sort($contentWords);

        return implode(' ', $contentWords);
    }

    /**
     * Filtra i suggerimenti con deduplicazione a 3 livelli:
     *   1. Hash esatto (SQLite locale)
     *   2. Concetto core vs post WordPress esistenti (Jaccard + subset)
     *   3. Dedup intra-batch (tra i suggerimenti della stessa esecuzione)
     *
     * @param array $suggerimenti Lista di stringhe suggerite
     * @return array Topic nuovi da elaborare
     */
    public function filter(array $suggerimenti): array
    {
        $nuovi = [];
        $batchConcepts = []; // Concetti gia' accettati in questo batch

        foreach ($suggerimenti as $topic) {
            if (count($nuovi) >= $this->maxArticles) {
                break;
            }

            // Layer 1: hash esatto nel DB locale
            $hash = $this->hashTopic($topic);
            $stmt = $this->db->prepare('SELECT id FROM topics WHERE hash = ?');
            $stmt->execute([$hash]);
            if ($stmt->fetch() !== false) {
                continue; // Gia' nel DB locale
            }

            // Layer 2: concetto core vs post WordPress esistenti
            $concept = $this->extractCoreConcept($topic);
            if (empty($concept)) {
                $this->log("Topic scartato (concetto vuoto dopo normalizzazione): \"{$topic}\"", 'warning');
                continue;
            }

            $duplicate = $this->findDuplicate($concept, $this->existingConcepts);
            if ($duplicate !== null) {
                $this->log("Topic scartato (simile a esistente): \"{$topic}\" [concetto: \"{$concept}\"] ~ \"{$duplicate}\"", 'detail');
                continue;
            }

            // Layer 3: dedup intra-batch
            $batchDup = $this->findDuplicate($concept, $batchConcepts);
            if ($batchDup !== null) {
                $this->log("Topic scartato (duplicato nel batch): \"{$topic}\" [concetto: \"{$concept}\"] ~ \"{$batchDup}\"", 'detail');
                continue;
            }

            // Layer 4: dedup semantica con embeddings (se abilitata)
            if ($this->embeddingEnabled) {
                $embeddingDup = $this->findDuplicateByEmbedding($topic);
                if ($embeddingDup !== null) {
                    $this->log("Topic scartato (semanticamente simile via embedding): \"{$topic}\" ~ \"{$embeddingDup}\"", 'detail');
                    continue;
                }
            }

            // Accettato
            $nuovi[] = $topic;
            $batchConcepts[] = $concept;
        }

        return $nuovi;
    }

    /**
     * Cerca un duplicato in una lista di concetti usando Jaccard + subset.
     * @return string|null Il concetto duplicato trovato, o null se nessun match
     */
    private function findDuplicate(string $concept, array $conceptList): ?string
    {
        $wordsA = explode(' ', $concept);
        $countA = count($wordsA);

        foreach ($conceptList as $existing) {
            // Match esatto
            if ($concept === $existing) {
                return $existing;
            }

            $wordsB = explode(' ', $existing);
            $countB = count($wordsB);

            // Subset check: se tutte le parole del concetto piu' corto sono nel piu' lungo
            // (solo se il concetto piu' corto ha almeno 2 parole)
            $shorter = $countA <= $countB ? $wordsA : $wordsB;
            $longer = $countA <= $countB ? $wordsB : $wordsA;
            if (count($shorter) >= 2) {
                $allInLonger = true;
                foreach ($shorter as $w) {
                    if (!in_array($w, $longer, true)) {
                        $allInLonger = false;
                        break;
                    }
                }
                if ($allInLonger) {
                    return $existing;
                }
            }

            // Subset check anche per parole singole con match esatto
            // "gatti" vs "gatti" è gia' catturato sopra dal match esatto
            // Ma "gatti" (1 parola) vs "gatti neri" (2 parole) NON va bloccato
            // perché sono argomenti diversi

            // Jaccard similarity
            $jaccard = $this->jaccardSimilarity($wordsA, $wordsB);
            if ($jaccard >= $this->similarityThreshold) {
                return $existing;
            }
        }

        return null;
    }

    /**
     * Calcola la similarita' di Jaccard tra due array di parole.
     * J(A,B) = |A ∩ B| / |A ∪ B|
     */
    private function jaccardSimilarity(array $wordsA, array $wordsB): float
    {
        if (empty($wordsA) && empty($wordsB)) {
            return 1.0;
        }
        if (empty($wordsA) || empty($wordsB)) {
            return 0.0;
        }

        $intersection = count(array_intersect($wordsA, $wordsB));
        $union = count(array_unique(array_merge($wordsA, $wordsB)));

        return $union > 0 ? $intersection / $union : 0.0;
    }

    /**
     * Segna un topic come in lavorazione.
     */
    public function markInProgress(string $topic): void
    {
        $hash = $this->hashTopic($topic);
        $stmt = $this->db->prepare('
            INSERT OR IGNORE INTO topics (hash, topic, status, created_at) VALUES (?, ?, "in_progress", ?)
        ');
        $stmt->execute([$hash, $topic, date('Y-m-d H:i:s')]);

        // Aggiungi il concetto alla lista in-memory per evitare duplicati
        // se filter() viene chiamato di nuovo nello stesso processo
        $concept = $this->extractCoreConcept($topic);
        if (!empty($concept) && !in_array($concept, $this->existingConcepts, true)) {
            $this->existingConcepts[] = $concept;
        }
    }

    /**
     * Segna un topic come completato.
     */
    public function markCompleted(string $topic): void
    {
        $hash = $this->hashTopic($topic);
        $stmt = $this->db->prepare('
            UPDATE topics SET status = "completed", completed_at = ? WHERE hash = ?
        ');
        $stmt->execute([date('Y-m-d H:i:s'), $hash]);
    }

    /**
     * Segna un topic come scartato (non pertinente, non verra' ritentato).
     */
    public function markSkipped(string $topic): void
    {
        $hash = $this->hashTopic($topic);
        $stmt = $this->db->prepare('
            INSERT OR IGNORE INTO topics (hash, topic, status, created_at) VALUES (?, ?, "skipped", ?)
        ');
        $stmt->execute([$hash, $topic, date('Y-m-d H:i:s')]);
    }

    /**
     * Segna un topic come fallito (potra' essere ritentato).
     */
    public function markFailed(string $topic): void
    {
        $hash = $this->hashTopic($topic);
        $stmt = $this->db->prepare('DELETE FROM topics WHERE hash = ?');
        $stmt->execute([$hash]);
    }

    /**
     * Genera hash normalizzato di un topic.
     */
    private function hashTopic(string $topic): string
    {
        $normalized = mb_strtolower(trim($topic));
        return hash('sha256', $normalized);
    }

    /**
     * Carica la cache embeddings da file.
     */
    private function loadEmbeddingCache(): void
    {
        if (file_exists($this->embeddingCachePath)) {
            $data = json_decode(@file_get_contents($this->embeddingCachePath), true);
            if (is_array($data)) {
                $this->embeddingCache = $data;
            }
        }
        $this->log("Cache embeddings: " . count($this->embeddingCache) . " vettori caricati", 'detail');
    }

    /**
     * Salva la cache embeddings su file.
     */
    private function saveEmbeddingCache(): void
    {
        @file_put_contents($this->embeddingCachePath, json_encode($this->embeddingCache, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    /**
     * Ottiene l'embedding di un testo via OpenAI API.
     * @return float[]|null Vettore embedding oppure null se fallisce
     */
    private function getEmbedding(string $text): ?array
    {
        $cacheKey = md5($text);
        if (isset($this->embeddingCache[$cacheKey])) {
            return $this->embeddingCache[$cacheKey];
        }

        if (empty($this->openaiKey) || $this->openaiKey === 'YOUR_OPENAI_API_KEY') {
            return null;
        }

        $payload = json_encode([
            'model' => 'text-embedding-3-small',
            'input' => $text,
        ]);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => 'https://api.openai.com/v1/embeddings',
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->openaiKey,
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            $this->log("Embedding API fallita (HTTP {$httpCode})", 'warning');
            return null;
        }

        $data = json_decode($response, true);
        $embedding = $data['data'][0]['embedding'] ?? null;

        if ($embedding !== null) {
            $this->embeddingCache[$cacheKey] = $embedding;
            $this->saveEmbeddingCache();
        }

        return $embedding;
    }

    /**
     * Calcola la cosine similarity tra due vettori.
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        $len = min(count($a), count($b));

        for ($i = 0; $i < $len; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        $denom = sqrt($normA) * sqrt($normB);
        return $denom > 0 ? $dot / $denom : 0.0;
    }

    /**
     * Verifica se un topic è semanticamente duplicato usando embeddings.
     * @return string|null Il concetto duplicato trovato, oppure null
     */
    private function findDuplicateByEmbedding(string $topic): ?string
    {
        if (!$this->embeddingEnabled) {
            return null;
        }

        $topicEmbedding = $this->getEmbedding($topic);
        if ($topicEmbedding === null) {
            return null;
        }

        foreach ($this->existingConcepts as $existing) {
            $existingEmbedding = $this->getEmbedding($existing);
            if ($existingEmbedding === null) continue;

            $similarity = $this->cosineSimilarity($topicEmbedding, $existingEmbedding);
            if ($similarity >= $this->embeddingSimilarityThreshold) {
                $this->log("Embedding similarity: \"{$topic}\" vs \"{$existing}\" = " . round($similarity, 3), 'detail');
                return $existing;
            }
        }

        return null;
    }

    /**
     * Verifica se WordPress e' configurato (credenziali presenti).
     */
    private function isWpConfigured(): bool
    {
        return !empty($this->wpSiteUrl) && !empty($this->wpUsername) && !empty($this->wpAppPassword);
    }

    /**
     * Verifica se la cache locale dei post WP e' ancora valida (non scaduta).
     */
    private function isWpCacheValid(): bool
    {
        if (!file_exists($this->wpCachePath)) {
            return false;
        }
        $data = json_decode(@file_get_contents($this->wpCachePath), true);
        if (!is_array($data) || !isset($data['fetched_at'])) {
            return false;
        }
        return (time() - $data['fetched_at']) < $this->wpCacheTtl;
    }

    /**
     * Recupera tutti i post pubblicati da WordPress via REST API e li salva in cache.
     * Include sia gli articoli generati automaticamente che quelli scritti manualmente.
     *
     * @return int Numero di post recuperati (0 se fallisce o WP non configurato)
     */
    public function syncWpPostsToCache(): int
    {
        if (!$this->isWpConfigured()) {
            $this->log('WordPress non configurato, skip sincronizzazione post', 'warning');
            return 0;
        }

        $this->log('Sincronizzazione post WordPress per rilevamento duplicati...', 'detail');

        $allPosts = [];
        $page = 1;
        $perPage = 100;
        $auth = base64_encode($this->wpUsername . ':' . $this->wpAppPassword);

        while (true) {
            $params = http_build_query([
                'per_page' => $perPage,
                'page'     => $page,
                'status'   => 'publish',
                '_fields'  => 'id,title,link,slug',
                'orderby'  => 'id',
                'order'    => 'asc',
            ]);

            $url = $this->wpSiteUrl . '/wp-json/wp/v2/posts?' . $params;

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Basic ' . $auth,
                    'Accept: application/json',
                ],
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response === false || $httpCode !== 200) {
                $this->log("Fetch WP fallito (HTTP {$httpCode}), uso cache esistente", 'warning');
                return 0;
            }

            $posts = json_decode($response, true);
            if (!is_array($posts) || empty($posts)) {
                break;
            }

            foreach ($posts as $post) {
                $allPosts[] = [
                    'id'    => $post['id'],
                    'title' => strip_tags(html_entity_decode($post['title']['rendered'] ?? '', ENT_QUOTES, 'UTF-8')),
                    'url'   => $post['link'] ?? '',
                    'slug'  => $post['slug'] ?? '',
                ];
            }

            if (count($posts) < $perPage) {
                break;
            }

            $page++;
            if ($page > 50) {
                break; // Safety: max 5000 post
            }
        }

        if (!empty($allPosts)) {
            $data = ['fetched_at' => time(), 'posts' => $allPosts];
            @file_put_contents($this->wpCachePath, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
            $this->log('Cache WP aggiornata: ' . count($allPosts) . ' post (inclusi quelli scritti manualmente)', 'detail');
        }

        return count($allPosts);
    }

    /**
     * Restituisce informazioni sulla cache WP per il dashboard.
     */
    public function getWpCacheInfo(): array
    {
        $configured = $this->isWpConfigured();

        if (!file_exists($this->wpCachePath)) {
            return ['count' => 0, 'fetched_at' => null, 'valid' => false, 'configured' => $configured];
        }

        $data = json_decode(@file_get_contents($this->wpCachePath), true);
        return [
            'count'      => count($data['posts'] ?? []),
            'fetched_at' => $data['fetched_at'] ?? null,
            'valid'      => $this->isWpCacheValid(),
            'configured' => $configured,
        ];
    }

    /**
     * Restituisce statistiche utili per debug/dashboard.
     */
    public function getStats(): array
    {
        return [
            'existing_concepts' => count($this->existingConcepts),
            'similarity_threshold' => $this->similarityThreshold,
            'db_completed' => $this->db->query('SELECT COUNT(*) FROM topics WHERE status = "completed"')->fetchColumn(),
            'db_total' => $this->db->query('SELECT COUNT(*) FROM topics')->fetchColumn(),
        ];
    }
}
