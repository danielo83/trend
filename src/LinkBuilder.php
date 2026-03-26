<?php

/**
 * Link Building automatico per gli articoli generati.
 *
 * Recupera la lista dei post pubblicati su WordPress via REST API,
 * li cachea localmente, e fornisce contesto al prompt AI per inserire
 * link interni. Post-processa l'HTML per validare i link generati.
 */
class LinkBuilder
{
    private string $wpSiteUrl;
    private string $wpUsername;
    private string $wpAppPassword;
    private bool $internalEnabled;
    private bool $externalEnabled;
    private int $maxInternal;
    private int $maxExternal;
    private int $cacheTtl;
    private string $cachePath;

    /** @var callable|null */
    private $logCallback = null;

    public function __construct(array $config)
    {
        $this->wpSiteUrl     = rtrim($config['wp_site_url'] ?? '', '/');
        $this->wpUsername     = $config['wp_username'] ?? '';
        $this->wpAppPassword  = $config['wp_app_password'] ?? '';
        $this->internalEnabled = !empty($config['link_internal_enabled']);
        $this->externalEnabled = !empty($config['link_external_enabled']);
        $this->maxInternal    = max(1, intval($config['link_max_internal'] ?? 5));
        $this->maxExternal    = max(0, intval($config['link_max_external'] ?? 2));
        $this->cacheTtl       = max(1800, intval($config['link_cache_ttl'] ?? 21600));
        $this->cachePath      = ($config['base_dir'] ?? __DIR__ . '/..') . '/data/cache_wp_posts.json';
    }

    public function setLogCallback(callable $cb): void
    {
        $this->logCallback = $cb;
    }

    private function log(string $message, string $type = 'detail'): void
    {
        error_log("[LinkBuilder] " . $message);
        if ($this->logCallback !== null) {
            ($this->logCallback)('[LINK] ' . $message, $type);
        }
    }

    /**
     * Il link building e' attivo se almeno un tipo e' abilitato e WordPress e' configurato.
     */
    public function isEnabled(): bool
    {
        if (!$this->internalEnabled && !$this->externalEnabled) {
            return false;
        }
        // Per i link interni serve WordPress configurato
        if ($this->internalEnabled && (empty($this->wpSiteUrl) || empty($this->wpUsername) || empty($this->wpAppPassword))) {
            return false;
        }
        return true;
    }

    /**
     * Forza il refresh della cache e restituisce i post.
     */
    public function refreshCache(): array
    {
        $posts = $this->fetchAllPosts();
        $this->saveCache($posts);
        return $posts;
    }

    /**
     * Restituisce info sulla cache corrente.
     */
    public function getCacheInfo(): array
    {
        $cache = $this->loadCache();
        if ($cache === null) {
            return ['count' => 0, 'fetched_at' => null, 'valid' => false];
        }
        return [
            'count'      => count($cache['posts'] ?? []),
            'fetched_at' => $cache['fetched_at'] ?? null,
            'valid'      => $this->isCacheValid(),
        ];
    }

    /**
     * Recupera gli URL delle pagine WordPress (non post) per la whitelist.
     * @return string[] Lista di URL
     */
    private function fetchPages(): array
    {
        if (empty($this->wpSiteUrl) || empty($this->wpUsername) || empty($this->wpAppPassword)) {
            return [];
        }

        $url = $this->wpSiteUrl . '/wp-json/wp/v2/pages?' . http_build_query([
            'per_page' => 50,
            'status'   => 'publish',
            '_fields'  => 'link',
        ]);

        $response = $this->wpApiGet($url);
        if ($response === null || !is_array($response)) {
            return [];
        }

        $urls = [];
        foreach ($response as $page) {
            if (!empty($page['link'])) {
                $urls[] = $page['link'];
            }
        }

        return $urls;
    }

    /**
     * Trova articoli correlati al topic dato.
     * @return array Lista di ['id', 'title', 'url', 'excerpt', 'score']
     */
    public function getRelatedArticles(string $topic, int $limit = 10): array
    {
        $posts = $this->getCachedPosts();
        if (empty($posts)) {
            return [];
        }

        // Calcola rilevanza per ogni post
        $scored = [];
        foreach ($posts as $post) {
            $score = $this->computeRelevance($topic, $post);
            if ($score > 0) {
                $post['score'] = $score;
                $scored[] = $post;
            }
        }

        // Ordina per score decrescente
        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, $limit);
    }

    /**
     * Costruisce il blocco di contesto da iniettare nel prompt AI.
     */
    public function buildPromptContext(string $topic): string
    {
        $parts = [];

        if ($this->internalEnabled) {
            $related = $this->getRelatedArticles($topic, $this->maxInternal * 2);

            if (!empty($related)) {
                $this->log('Trovati ' . count($related) . ' articoli correlati per linking interno', 'detail');

                $parts[] = "LINK INTERNI OBBLIGATORI:\n"
                    . "Inserisci nel testo dell'articolo dei link interni verso altri articoli del sito, usando tag <a href=\"URL\">testo ancora descrittivo</a>.\n"
                    . "Scegli i link piu' rilevanti tra quelli elencati sotto e inseriscili in modo naturale nel corpo del testo (NON in una lista separata, NON raggruppati in fondo all'articolo).\n"
                    . "Inserisci tra 2 e " . $this->maxInternal . " link interni.\n"
                    . "Usa come anchor text una frase descrittiva e naturale, MAI l'URL nudo.\n\n"
                    . "Articoli disponibili per il linking interno:";

                foreach ($related as $i => $article) {
                    $parts[] = ($i + 1) . '. "' . $article['title'] . '" - ' . $article['url'];
                }
            } else {
                $this->log('Nessun articolo correlato trovato per linking interno', 'detail');
            }
        }

        if ($this->externalEnabled) {
            $parts[] = "\nLINK ESTERNI:\n"
                . "Puoi inserire fino a " . $this->maxExternal . " link esterni verso fonti autorevoli e pertinenti (es. siti di psicologia, medicina, enciclopedie, universita').\n"
                . "Usa il formato <a href=\"URL\" target=\"_blank\" rel=\"noopener\">testo descrittivo</a>.\n"
                . "I link esterni devono puntare a pagine reali e autorevoli. Non inventare URL.";
        }

        return implode("\n", $parts);
    }

    /**
     * Post-processa l'HTML generato: valida link interni, arricchisce link esterni.
     */
    public function postProcess(string $html): string
    {
        if (empty($html) || (!$this->internalEnabled && !$this->externalEnabled)) {
            return $html;
        }

        $cachedUrls = [];
        if ($this->internalEnabled) {
            foreach ($this->getCachedPosts() as $post) {
                $cachedUrls[$post['url']] = true;
                // Aggiungi anche la versione senza trailing slash e viceversa
                $alt = str_ends_with($post['url'], '/') ? rtrim($post['url'], '/') : $post['url'] . '/';
                $cachedUrls[$alt] = true;
            }

            // Whitelist: homepage, pagine strutturali e path comuni del sito
            // Questi URL sono sempre validi anche se non sono nel cache dei post
            if (!empty($this->wpSiteUrl)) {
                $siteBase = $this->wpSiteUrl;
                $whitelistPaths = ['', '/', '/blog', '/blog/', '/chi-siamo', '/chi-siamo/', '/contatti', '/contatti/'];
                foreach ($whitelistPaths as $path) {
                    $cachedUrls[$siteBase . $path] = true;
                }

                // Aggiungi anche le pagine WordPress reali (non solo i post)
                $pages = $this->fetchPages();
                foreach ($pages as $pageUrl) {
                    $cachedUrls[$pageUrl] = true;
                    $altPage = str_ends_with($pageUrl, '/') ? rtrim($pageUrl, '/') : $pageUrl . '/';
                    $cachedUrls[$altPage] = true;
                }
            }
        }

        $siteHost = parse_url($this->wpSiteUrl, PHP_URL_HOST);

        // Usa DOMDocument per parsare l'HTML
        $doc = new DOMDocument('1.0', 'UTF-8');
        // Evita warning per HTML parziale
        $wrappedHtml = '<div id="lb-wrapper">' . $html . '</div>';
        @$doc->loadHTML('<?xml encoding="UTF-8"><body>' . $wrappedHtml . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        $links = $doc->getElementsByTagName('a');
        $internalCount = 0;
        $externalCount = 0;
        $seenUrls = [];
        $toRemove = [];

        // Iteriamo raccogliendo prima, poi rimuoviamo (non si puo' modificare durante iterazione)
        $linkNodes = [];
        for ($i = 0; $i < $links->length; $i++) {
            $linkNodes[] = $links->item($i);
        }

        foreach ($linkNodes as $link) {
            $href = trim($link->getAttribute('href'));

            if (empty($href) || str_starts_with($href, '#')) {
                continue;
            }

            $linkHost = parse_url($href, PHP_URL_HOST);
            $isInternal = ($linkHost === $siteHost);

            if ($isInternal) {
                // Link interno: verificare che esista nella cache o tramite HEAD request
                if (!isset($cachedUrls[$href])) {
                    // Fallback: verifica con HEAD request se la pagina esiste davvero
                    if ($this->urlExists($href)) {
                        $cachedUrls[$href] = true; // Aggiungi alla whitelist per evitare check ripetuti
                        $this->log("Link interno verificato via HEAD: {$href}", 'detail');
                    } else {
                        $this->log("Link interno inventato rimosso: {$href}", 'warning');
                        $toRemove[] = $link;
                        continue;
                    }
                }
                if (isset($seenUrls[$href])) {
                    $toRemove[] = $link;
                    continue;
                }
                $internalCount++;
                if ($internalCount > $this->maxInternal) {
                    $toRemove[] = $link;
                    continue;
                }
                $seenUrls[$href] = true;
            } else {
                // Link esterno
                $link->setAttribute('target', '_blank');
                $link->setAttribute('rel', 'noopener');

                if (isset($seenUrls[$href])) {
                    $toRemove[] = $link;
                    continue;
                }
                $externalCount++;
                if ($externalCount > $this->maxExternal) {
                    $toRemove[] = $link;
                    continue;
                }
                $seenUrls[$href] = true;
            }
        }

        // Rimuovi link non validi (sostituisci con il loro testo)
        foreach ($toRemove as $link) {
            $textNode = $doc->createTextNode($link->textContent);
            $link->parentNode->replaceChild($textNode, $link);
        }

        $this->log("Post-processing: {$internalCount} link interni, {$externalCount} link esterni", 'detail');

        // Estrai solo il contenuto del wrapper
        $wrapper = $doc->getElementById('lb-wrapper');
        if ($wrapper === null) {
            return $html; // fallback
        }

        $result = '';
        foreach ($wrapper->childNodes as $child) {
            $result .= $doc->saveHTML($child);
        }

        return $result;
    }

    /**
     * Linking bidirezionale: aggiorna i vecchi articoli WordPress aggiungendo
     * un link verso il nuovo articolo appena pubblicato.
     *
     * @param int    $newPostId    ID del nuovo post WordPress
     * @param string $newPostUrl   URL del nuovo post
     * @param string $newPostTitle Titolo del nuovo post
     * @param string $newTopic     Topic/keyword del nuovo post
     * @param int    $maxUpdates   Max vecchi articoli da aggiornare
     * @return int Numero di articoli aggiornati
     */
    public function updateOldPostsWithLink(
        int $newPostId,
        string $newPostUrl,
        string $newPostTitle,
        string $newTopic,
        int $maxUpdates = 3
    ): int {
        if (!$this->internalEnabled || empty($this->wpSiteUrl)) {
            return 0;
        }

        $posts = $this->getCachedPosts();
        if (empty($posts)) {
            return 0;
        }

        // Trova i post più correlati al nuovo
        $related = $this->getRelatedArticles($newTopic, $maxUpdates * 2);
        if (empty($related)) {
            $this->log('Linking bidirezionale: nessun post correlato trovato', 'detail');
            return 0;
        }

        $updated = 0;

        foreach ($related as $oldPost) {
            if ($updated >= $maxUpdates) break;
            if ($oldPost['id'] === $newPostId) continue;

            // Recupera il contenuto corrente del vecchio post
            $url = $this->wpSiteUrl . '/wp-json/wp/v2/posts/' . $oldPost['id'] . '?_fields=id,content';
            $postData = $this->wpApiGet($url);
            if ($postData === null || !isset($postData['content']['rendered'])) continue;

            $oldContent = $postData['content']['rendered'];

            // Controlla se contiene già un link al nuovo post
            if (str_contains($oldContent, $newPostUrl)) continue;

            // Trova l'ultimo </p> prima delle FAQ (o l'ultimo </p> del body) per inserire il link
            $anchorText = $this->generateAnchorText($newPostTitle, $newTopic);
            $linkHtml = '<p>Potrebbe interessarti anche: <a href="' . htmlspecialchars($newPostUrl) . '">' . htmlspecialchars($anchorText) . '</a></p>';

            // Inserisci prima della sezione FAQ se esiste, altrimenti alla fine
            $faqPos = strpos($oldContent, '<h2>Domande frequenti</h2>');
            if ($faqPos === false) {
                $faqPos = strpos($oldContent, 'Domande frequenti');
            }

            if ($faqPos !== false) {
                // Trova l'ultimo </p> prima della FAQ
                $beforeFaq = substr($oldContent, 0, $faqPos);
                $lastPClose = strrpos($beforeFaq, '</p>');
                if ($lastPClose !== false) {
                    $insertPos = $lastPClose + 4; // dopo </p>
                    $newContent = substr($oldContent, 0, $insertPos) . "\n" . $linkHtml . substr($oldContent, $insertPos);
                } else {
                    $newContent = $oldContent . "\n" . $linkHtml;
                }
            } else {
                $newContent = $oldContent . "\n" . $linkHtml;
            }

            // Aggiorna il post via WordPress REST API
            $updateResult = $this->wpApiUpdate($oldPost['id'], $newContent);
            if ($updateResult) {
                $updated++;
                $this->log("Linking bidirezionale: aggiornato post #{$oldPost['id']} \"{$oldPost['title']}\" con link a \"{$newPostTitle}\"", 'success');
            }
        }

        if ($updated > 0) {
            $this->log("Linking bidirezionale: {$updated} vecchi articoli aggiornati con link al nuovo post", 'success');
        }

        return $updated;
    }

    /**
     * Genera un anchor text naturale per il link bidirezionale.
     */
    private function generateAnchorText(string $title, string $topic): string
    {
        // Usa il titolo se è ragionevolmente corto, altrimenti il topic
        if (mb_strlen($title) <= 60) {
            return $title;
        }
        return ucfirst($topic);
    }

    /**
     * Aggiorna il contenuto di un post WordPress via REST API.
     */
    private function wpApiUpdate(int $postId, string $newContent): bool
    {
        if (empty($this->wpSiteUrl) || empty($this->wpUsername) || empty($this->wpAppPassword)) {
            return false;
        }

        $url = $this->wpSiteUrl . '/wp-json/wp/v2/posts/' . $postId;
        $payload = json_encode(['content' => $newContent], JSON_UNESCAPED_UNICODE);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Basic ' . base64_encode($this->wpUsername . ':' . $this->wpAppPassword),
                'Content-Type: application/json',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300) {
            $this->log("Aggiornamento post #{$postId} fallito (HTTP {$httpCode})", 'error');
            return false;
        }

        return true;
    }

    /**
     * Verifica se un URL esiste con una HEAD request (leggera, senza scaricare il body).
     */
    private function urlExists(string $url): bool
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_NOBODY         => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode >= 200 && $httpCode < 400;
    }

    // =========================================================================
    // Metodi privati
    // =========================================================================

    /**
     * Restituisce i post dalla cache (ricaricandola se necessario).
     */
    private function getCachedPosts(): array
    {
        if (!$this->isCacheValid()) {
            $this->log('Cache scaduta o assente, aggiornamento...', 'detail');
            $posts = $this->fetchAllPosts();
            if (!empty($posts)) {
                $this->saveCache($posts);
            } else {
                // Se il fetch fallisce, prova a usare la cache scaduta
                $cache = $this->loadCache();
                return $cache['posts'] ?? [];
            }
            return $posts;
        }

        $cache = $this->loadCache();
        return $cache['posts'] ?? [];
    }

    /**
     * Fetch paginato di tutti i post pubblicati da WordPress REST API.
     */
    private function fetchAllPosts(): array
    {
        if (empty($this->wpSiteUrl) || empty($this->wpUsername) || empty($this->wpAppPassword)) {
            $this->log('WordPress non configurato, skip fetch post', 'warning');
            return [];
        }

        $allPosts = [];
        $page = 1;
        $perPage = 100;

        $this->log('Fetch post da WordPress...', 'detail');

        while (true) {
            $url = $this->wpSiteUrl . '/wp-json/wp/v2/posts?'
                . http_build_query([
                    'per_page' => $perPage,
                    'page'     => $page,
                    'status'   => 'publish',
                    '_fields'  => 'id,title,link,excerpt,categories',
                    'orderby'  => 'date',
                    'order'    => 'desc',
                ]);

            $response = $this->wpApiGet($url);
            if ($response === null || !is_array($response)) {
                break;
            }

            if (empty($response)) {
                break;
            }

            foreach ($response as $post) {
                $title = strip_tags(html_entity_decode($post['title']['rendered'] ?? '', ENT_QUOTES, 'UTF-8'));
                $excerpt = strip_tags(html_entity_decode($post['excerpt']['rendered'] ?? '', ENT_QUOTES, 'UTF-8'));

                $allPosts[] = [
                    'id'         => $post['id'],
                    'title'      => trim($title),
                    'url'        => $post['link'] ?? '',
                    'excerpt'    => trim($excerpt),
                    'categories' => $post['categories'] ?? [],
                ];
            }

            if (count($response) < $perPage) {
                break; // ultima pagina
            }

            $page++;

            // Safety: max 50 pagine (5000 post)
            if ($page > 50) {
                break;
            }
        }

        $this->log('Fetch completato: ' . count($allPosts) . ' post trovati', 'success');
        return $allPosts;
    }

    /**
     * GET request alla WP REST API con autenticazione.
     */
    private function wpApiGet(string $url): ?array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Basic ' . base64_encode($this->wpUsername . ':' . $this->wpAppPassword),
                'Content-Type: application/json',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            $this->log("CURL error: {$error}", 'error');
            return null;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $this->log("WP API HTTP {$httpCode}: " . mb_substr($response, 0, 200), 'error');
            return null;
        }

        return json_decode($response, true);
    }

    /**
     * Carica la cache dal file.
     */
    private function loadCache(): ?array
    {
        if (!file_exists($this->cachePath)) {
            return null;
        }
        $content = @file_get_contents($this->cachePath);
        if ($content === false) {
            return null;
        }
        $data = json_decode($content, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Salva la cache su file.
     */
    private function saveCache(array $posts): void
    {
        $data = [
            'fetched_at' => time(),
            'posts'      => $posts,
        ];
        @file_put_contents($this->cachePath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    /**
     * Verifica se la cache e' ancora valida.
     */
    private function isCacheValid(): bool
    {
        $cache = $this->loadCache();
        if ($cache === null || !isset($cache['fetched_at'])) {
            return false;
        }
        return (time() - $cache['fetched_at']) < $this->cacheTtl;
    }

    /**
     * Calcola un punteggio di rilevanza tra un topic e un post.
     * Basato su match di parole (>= 3 caratteri) tra topic e titolo/excerpt del post.
     */
    private function computeRelevance(string $topic, array $post): float
    {
        $topicWords = $this->extractWords($topic);
        if (empty($topicWords)) {
            return 0;
        }

        $postText = mb_strtolower($post['title'] . ' ' . ($post['excerpt'] ?? ''));
        $matches = 0;

        foreach ($topicWords as $word) {
            if (mb_strpos($postText, $word) !== false) {
                $matches++;
            }
        }

        return $matches / count($topicWords);
    }

    /**
     * Estrae parole significative (>= 3 caratteri) da un testo.
     */
    private function extractWords(string $text): array
    {
        $text = mb_strtolower(trim($text));
        $words = preg_split('/[\s\-_,;:.!?]+/', $text);
        // Filtra parole troppo corte e stop words italiane comuni
        $stopWords = ['che', 'per', 'con', 'una', 'del', 'dei', 'gli', 'nel', 'non', 'come', 'sono', 'alle', 'dalla', 'delle'];
        return array_values(array_filter($words, function ($w) use ($stopWords) {
            return mb_strlen($w) >= 3 && !in_array($w, $stopWords, true);
        }));
    }
}
