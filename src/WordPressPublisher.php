<?php

/**
 * Pubblica articoli su WordPress tramite REST API.
 *
 * Supporta:
 *   - Pubblicazione automatica (dopo generazione) o manuale (dal dashboard)
 *   - Upload immagine featured
 *   - Categorie e stato del post configurabili
 *   - Application Passwords di WordPress (metodo raccomandato)
 */
class WordPressPublisher
{
    private string $siteUrl;
    private string $username;
    private string $appPassword;
    private string $defaultStatus;
    private string $defaultCategory;
    private bool $enabled;

    /** @var callable|null */
    private $logCallback = null;

    public function __construct(array $config)
    {
        $this->siteUrl         = rtrim($config['wp_site_url'] ?? '', '/');
        $this->username        = $config['wp_username'] ?? '';
        $this->appPassword     = $config['wp_app_password'] ?? '';
        $this->defaultStatus   = $config['wp_post_status'] ?? 'draft';
        $this->defaultCategory = $config['wp_category'] ?? '';
        $this->enabled         = !empty($config['wp_enabled']);
    }

    /**
     * Verifica se la pubblicazione WordPress e' abilitata e configurata.
     */
    public function isEnabled(): bool
    {
        return $this->enabled
            && !empty($this->siteUrl)
            && !empty($this->username)
            && !empty($this->appPassword);
    }

    public function setLogCallback(callable $callback): void
    {
        $this->logCallback = $callback;
    }

    private function log(string $message, string $type = 'detail'): void
    {
        error_log("[WordPressPublisher] " . $message);
        if ($this->logCallback !== null) {
            ($this->logCallback)('[WORDPRESS] ' . $message, $type);
        }
    }

    /**
     * Pubblica un articolo su WordPress.
     *
     * @param string      $title        Titolo dell'articolo
     * @param string      $htmlContent  Contenuto HTML completo
     * @param string|null $imageUrl     URL immagine featured (opzionale)
     * @param string|null $excerpt      Estratto/meta description (opzionale)
     * @param string|null $status       Stato: 'publish', 'draft', 'pending' (opzionale, usa default)
     * @param string|null $category     Nome categoria (opzionale, se null usa AI o default)
     * @param string|null $focusKeyphrase Keyword focus per Yoast SEO (opzionale)
     * @return array|null ['post_id' => int, 'post_url' => string] oppure null se fallisce
     */
    public function publish(
        string $title,
        string $htmlContent,
        ?string $imageUrl = null,
        ?string $excerpt = null,
        ?string $status = null,
        ?string $category = null,
        ?string $focusKeyphrase = null
    ): ?array {
        if (!$this->isEnabled()) {
            $this->log('Pubblicazione non abilitata o credenziali mancanti', 'warning');
            return null;
        }

        $postStatus = $status ?? $this->defaultStatus;
        $this->log("Pubblicazione articolo: \"{$title}\" (stato: {$postStatus})", 'detail');

        // 1. Se c'e' un'immagine, caricala prima come media
        $featuredMediaId = null;
        if (!empty($imageUrl)) {
            $featuredMediaId = $this->uploadMediaFromUrl($imageUrl, $title);
            if ($featuredMediaId !== null) {
                $this->log("Immagine featured caricata (media ID: {$featuredMediaId})", 'success');
            } else {
                $this->log("Upload immagine fallito, procedo senza featured image", 'warning');
            }
        }

        // 2. Prepara il body del post
        $postData = [
            'title'   => $title,
            'content' => $htmlContent,
            'status'  => $postStatus,
        ];

        if (!empty($excerpt)) {
            $postData['excerpt'] = $excerpt;
        }

        if ($featuredMediaId !== null) {
            $postData['featured_media'] = $featuredMediaId;
        }

        // Yoast SEO: includi meta nel post data (funziona se Yoast ha show_in_rest abilitato)
        $yoastMeta = [];
        if (!empty($excerpt)) {
            $yoastMeta['_yoast_wpseo_metadesc'] = $excerpt;
        }
        if (!empty($focusKeyphrase)) {
            $yoastMeta['_yoast_wpseo_focuskw'] = $focusKeyphrase;
        }
        if (!empty($yoastMeta)) {
            $postData['meta'] = $yoastMeta;
        }

        // Yoast REST API: aggiungi anche nel formato nativo Yoast
        if (!empty($excerpt) || !empty($focusKeyphrase)) {
            $postData['yoast_head_json'] = null; // trigger Yoast per ricalcolare
        }

        // 3. Assegna categoria (da parametro, oppure da default configurato)
        $categoryName = $category ?? $this->defaultCategory;
        $categoryId = $this->resolveCategoryByName($categoryName);
        if ($categoryId !== null) {
            $postData['categories'] = [$categoryId];
            $this->log("Categoria assegnata: \"{$categoryName}\" (ID: {$categoryId})", 'detail');
        }

        // 4. Crea il post
        $url = $this->siteUrl . '/wp-json/wp/v2/posts';
        $response = $this->apiRequest('POST', $url, $postData);

        if ($response === null) {
            $this->log('Creazione post fallita', 'error');
            return null;
        }

        $postId  = $response['id'] ?? null;
        $postUrl = $response['link'] ?? '';

        if ($postId === null) {
            $this->log('Risposta API senza post ID. Raw: ' . mb_substr(json_encode($response), 0, 300), 'error');
            return null;
        }

        // 5. Aggiorna meta Yoast SEO con chiamata dedicata (fallback affidabile)
        if (!empty($yoastMeta)) {
            $this->updateYoastMeta($postId, $excerpt, $focusKeyphrase);
        }

        $this->log("Post creato con successo! ID: {$postId} | URL: {$postUrl}", 'success');

        // ==================== TRACKING ANALYTICS ====================
        if (class_exists('ContentAnalytics')) {
            require_once __DIR__ . '/ContentAnalytics.php';
            $analytics = new ContentAnalytics([
                'base_dir' => dirname(__DIR__),
            ]);
            
            $analytics->trackArticle([
                'title' => $title,
                'url' => $postUrl,
                'wordpress_post_id' => $postId,
                'published_at' => date('Y-m-d H:i:s'),
            ]);
            
            $this->log("Articolo tracciato in analytics", 'detail');
        }
        // ============================================================

        return [
            'post_id'  => $postId,
            'post_url' => $postUrl,
        ];
    }

    /**
     * Testa la connessione all'API WordPress.
     * @return array ['success' => bool, 'message' => string, 'user' => ?string]
     */
    public function testConnection(): array
    {
        if (empty($this->siteUrl)) {
            return ['success' => false, 'message' => 'URL sito non configurato', 'user' => null];
        }
        if (empty($this->username) || empty($this->appPassword)) {
            return ['success' => false, 'message' => 'Credenziali non configurate', 'user' => null];
        }

        // Chiama /wp-json/wp/v2/users/me per verificare autenticazione
        $url = $this->siteUrl . '/wp-json/wp/v2/users/me?context=edit';
        $response = $this->apiRequest('GET', $url);

        if ($response === null) {
            return ['success' => false, 'message' => 'Impossibile connettersi all\'API WordPress', 'user' => null];
        }

        if (isset($response['code']) && $response['code'] !== 200) {
            $msg = $response['message'] ?? 'Errore sconosciuto';
            return ['success' => false, 'message' => "Errore API: {$msg}", 'user' => null];
        }

        $userName = $response['name'] ?? $response['slug'] ?? 'sconosciuto';
        $roles = implode(', ', $response['roles'] ?? []);

        return [
            'success' => true,
            'message' => "Connesso come \"{$userName}\" (ruoli: {$roles})",
            'user'    => $userName,
        ];
    }

    /**
     * Recupera le categorie disponibili su WordPress.
     * @return array Lista di ['id' => int, 'name' => string]
     */
    public function getCategories(): array
    {
        $url = $this->siteUrl . '/wp-json/wp/v2/categories?per_page=100';
        $response = $this->apiRequest('GET', $url);

        if ($response === null || !is_array($response)) {
            return [];
        }

        $categories = [];
        foreach ($response as $cat) {
            if (isset($cat['id']) && isset($cat['name'])) {
                $categories[] = [
                    'id'   => $cat['id'],
                    'name' => html_entity_decode($cat['name']),
                ];
            }
        }

        return $categories;
    }

    /**
     * Carica un'immagine da URL remoto come media WordPress.
     * @return int|null Media ID oppure null
     */
    private function uploadMediaFromUrl(string $imageUrl, string $title): ?int
    {
        $this->log("Download immagine da: " . mb_substr($imageUrl, 0, 100), 'detail');

        // Scarica l'immagine
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $imageUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
        ]);
        $imageData = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($imageData === false || $httpCode !== 200) {
            $this->log("Download immagine fallito (HTTP {$httpCode})", 'error');
            return null;
        }

        // Determina estensione dal content type
        $ext = 'jpg';
        if (str_contains($contentType, 'png')) $ext = 'png';
        elseif (str_contains($contentType, 'webp')) $ext = 'webp';
        elseif (str_contains($contentType, 'gif')) $ext = 'gif';

        $slug = $this->slugify($title);
        $filename = $slug . '.' . $ext;

        // Upload via REST API media endpoint
        $url = $this->siteUrl . '/wp-json/wp/v2/media';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $imageData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Basic ' . base64_encode($this->username . ':' . $this->appPassword),
                'Content-Type: ' . ($contentType ?: 'image/jpeg'),
                'Content-Disposition: attachment; filename="' . $filename . '"',
            ],
        ]);

        $response = curl_exec($ch);
        $uploadCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($uploadCode < 200 || $uploadCode >= 300) {
            $this->log("Upload media fallito (HTTP {$uploadCode}): " . mb_substr($response, 0, 300), 'error');
            return null;
        }

        $data = json_decode($response, true);
        return $data['id'] ?? null;
    }

    /**
     * Risolve il nome/ID categoria in un ID WordPress.
     * Se la categoria non esiste, la crea automaticamente.
     */
    private function resolveCategoryByName(?string $category): ?int
    {
        if (empty($category)) {
            return null;
        }

        // Se e' gia' un numero, usalo direttamente
        if (is_numeric($category)) {
            return (int) $category;
        }

        // Cerca per nome (match esatto)
        $url = $this->siteUrl . '/wp-json/wp/v2/categories?search=' . urlencode($category);
        $response = $this->apiRequest('GET', $url);

        if (!empty($response) && is_array($response)) {
            foreach ($response as $cat) {
                if (isset($cat['id']) && mb_strtolower(html_entity_decode($cat['name'])) === mb_strtolower($category)) {
                    return (int) $cat['id'];
                }
            }
            // Se non c'e' match esatto, usa il primo risultato della ricerca
            if (isset($response[0]['id'])) {
                return (int) $response[0]['id'];
            }
        }

        // Categoria non trovata, creala
        $this->log("Categoria \"{$category}\" non trovata, creazione...", 'detail');
        $createResponse = $this->apiRequest('POST', $this->siteUrl . '/wp-json/wp/v2/categories', [
            'name' => $category,
        ]);

        if (!empty($createResponse['id'])) {
            $this->log("Categoria creata con ID: {$createResponse['id']}", 'success');
            return (int) $createResponse['id'];
        }

        return null;
    }

    /**
     * Esegue una richiesta all'API WordPress con autenticazione.
     */
    private function apiRequest(string $method, string $url, ?array $body = null): ?array
    {
        $ch = curl_init();

        $headers = [
            'Authorization: Basic ' . base64_encode($this->username . ':' . $this->appPassword),
            'Content-Type: application/json',
        ];

        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
        ];

        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            if ($body !== null) {
                $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
            }
        } elseif ($method === 'PUT' || $method === 'PATCH') {
            $opts[CURLOPT_CUSTOMREQUEST] = $method;
            if ($body !== null) {
                $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
            }
        }

        curl_setopt_array($ch, $opts);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            $this->log("CURL error: {$error}", 'error');
            return null;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $this->log("HTTP {$httpCode}: " . mb_substr($response, 0, 500), 'error');
            // Ritorna comunque la risposta per gestire i messaggi di errore
            return json_decode($response, true);
        }

        return json_decode($response, true);
    }

    /**
     * Aggiorna un post esistente su WordPress (preserva slug/permalink).
     *
     * @param int         $postId          ID del post da aggiornare
     * @param string      $title           Nuovo titolo
     * @param string      $htmlContent     Nuovo contenuto HTML
     * @param string|null $excerpt         Nuovo estratto (opzionale)
     * @param string|null $imageUrl        Nuova immagine featured (opzionale, null = mantiene quella esistente)
     * @param string|null $focusKeyphrase  Keyword focus per Yoast SEO (opzionale)
     * @return array|null ['post_id' => int, 'post_url' => string] oppure null se fallisce
     */
    public function update(
        int $postId,
        string $title,
        string $htmlContent,
        ?string $excerpt = null,
        ?string $imageUrl = null,
        ?string $focusKeyphrase = null
    ): ?array {
        if (!$this->isEnabled()) {
            $this->log('Pubblicazione non abilitata o credenziali mancanti', 'warning');
            return null;
        }

        $this->log("Aggiornamento post ID {$postId}: \"{$title}\"", 'detail');

        $postData = [
            'title'   => $title,
            'content' => $htmlContent,
        ];

        if (!empty($excerpt)) {
            $postData['excerpt'] = $excerpt;
        }

        // Yoast SEO: meta description e focus keyphrase
        $yoastMeta = [];
        if (!empty($excerpt)) {
            $yoastMeta['_yoast_wpseo_metadesc'] = $excerpt;
        }
        if (!empty($focusKeyphrase)) {
            $yoastMeta['_yoast_wpseo_focuskw'] = $focusKeyphrase;
        }
        if (!empty($yoastMeta)) {
            $postData['meta'] = $yoastMeta;
        }

        // Aggiorna meta Yoast con chiamata dedicata
        if (!empty($yoastMeta)) {
            $this->updateYoastMeta($postId, $excerpt, $focusKeyphrase);
        }

        // Upload nuova immagine solo se fornita
        if (!empty($imageUrl)) {
            $featuredMediaId = $this->uploadMediaFromUrl($imageUrl, $title);
            if ($featuredMediaId !== null) {
                $postData['featured_media'] = $featuredMediaId;
                $this->log("Nuova immagine featured caricata (media ID: {$featuredMediaId})", 'success');
            } else {
                $this->log("Upload nuova immagine fallito, mantengo quella esistente", 'warning');
            }
        }

        // NON includiamo 'slug' nei dati: WordPress lo mantiene invariato
        $url = $this->siteUrl . '/wp-json/wp/v2/posts/' . $postId;
        $response = $this->apiRequest('PUT', $url, $postData);

        if ($response === null) {
            $this->log("Aggiornamento post {$postId} fallito", 'error');
            return null;
        }

        $returnedId  = $response['id'] ?? null;
        $postUrl = $response['link'] ?? '';

        if ($returnedId === null) {
            $this->log('Risposta API senza post ID. Raw: ' . mb_substr(json_encode($response), 0, 300), 'error');
            return null;
        }

        $this->log("Post {$postId} aggiornato con successo! URL: {$postUrl}", 'success');

        return [
            'post_id'  => $returnedId,
            'post_url' => $postUrl,
        ];
    }

    /**
     * Recupera tutti i post pubblicati da WordPress con contenuto completo (paginato).
     *
     * @param array $filters Filtri opzionali: 'categories' => int[], 'after' => 'Y-m-d', 'before' => 'Y-m-d', 'include' => int[]
     * @return array Lista di post con id, title, url, slug, content, excerpt, categories
     */
    public function fetchAllPosts(array $filters = []): array
    {
        if (!$this->isEnabled()) {
            $this->log('WordPress non abilitato', 'warning');
            return [];
        }

        $allPosts = [];
        $page = 1;
        $perPage = 100;

        $this->log('Recupero post da WordPress per riscrittura...', 'detail');

        while (true) {
            $params = [
                'per_page' => $perPage,
                'page'     => $page,
                'status'   => 'publish',
                '_fields'  => 'id,title,link,slug,content,excerpt,categories',
                'orderby'  => 'id',
                'order'    => 'asc',
            ];

            // Filtro per categoria
            if (!empty($filters['categories'])) {
                $params['categories'] = implode(',', $filters['categories']);
            }

            // Filtro per data
            if (!empty($filters['after'])) {
                $params['after'] = $filters['after'] . 'T00:00:00';
            }
            if (!empty($filters['before'])) {
                $params['before'] = $filters['before'] . 'T23:59:59';
            }

            // Filtro per ID specifici
            if (!empty($filters['include'])) {
                $params['include'] = implode(',', $filters['include']);
                $params['orderby'] = 'include';
                unset($params['order']);
            }

            $url = $this->siteUrl . '/wp-json/wp/v2/posts?' . http_build_query($params);
            $response = $this->apiRequest('GET', $url);

            if ($response === null || !is_array($response)) {
                break;
            }
            if (empty($response)) {
                break;
            }

            foreach ($response as $post) {
                $allPosts[] = [
                    'id'         => $post['id'],
                    'title'      => strip_tags(html_entity_decode($post['title']['rendered'] ?? '', ENT_QUOTES, 'UTF-8')),
                    'url'        => $post['link'] ?? '',
                    'slug'       => $post['slug'] ?? '',
                    'content'    => $post['content']['rendered'] ?? '',
                    'excerpt'    => strip_tags(html_entity_decode($post['excerpt']['rendered'] ?? '', ENT_QUOTES, 'UTF-8')),
                    'categories' => $post['categories'] ?? [],
                ];
            }

            if (count($response) < $perPage) {
                break;
            }

            $page++;

            if ($page > 50) {
                break; // Safety: max 5000 post
            }
        }

        $this->log('Recuperati ' . count($allPosts) . ' post da WordPress', 'success');
        return $allPosts;
    }

    /**
     * Genera uno slug URL-friendly.
     */
    /**
     * Aggiorna i meta Yoast SEO per un post usando metodi multipli (fallback chain):
     * 1. Yoast REST API nativa (/wp-json/yoast/v1/...)
     * 2. WordPress REST API meta fields (PUT /wp/v2/posts/{id})
     * 3. ACF/Custom fields endpoint
     */
    private function updateYoastMeta(int $postId, ?string $metaDescription = null, ?string $focusKeyphrase = null): void
    {
        if (empty($metaDescription) && empty($focusKeyphrase)) {
            return;
        }

        // Metodo 1: Yoast REST API nativa (Yoast SEO >= 14.0)
        // Endpoint: /wp-json/yoast/v1/configurator
        $yoastData = [];
        if (!empty($metaDescription)) {
            $yoastData['wpseo_metadesc'] = $metaDescription;
        }
        if (!empty($focusKeyphrase)) {
            $yoastData['wpseo_focuskw'] = $focusKeyphrase;
        }

        // Prova l'endpoint Yoast dedicato per i meta
        $yoastEndpoint = $this->siteUrl . '/wp-json/yoast/v1/configurator';
        $yoastPayload = array_merge(['post_id' => $postId], $yoastData);
        $yoastResponse = $this->apiRequest('POST', $yoastEndpoint, $yoastPayload);

        if ($yoastResponse !== null && !isset($yoastResponse['code'])) {
            $this->log("Yoast SEO meta aggiornati via Yoast API per post #{$postId}", 'success');
            return;
        }

        // Metodo 2: WordPress REST API con meta fields
        // Yoast SEO salva i meta nei campi custom _yoast_wpseo_*
        $metaFields = [];
        if (!empty($metaDescription)) {
            $metaFields['_yoast_wpseo_metadesc'] = $metaDescription;
        }
        if (!empty($focusKeyphrase)) {
            $metaFields['_yoast_wpseo_focuskw'] = $focusKeyphrase;
        }

        $wpUrl = $this->siteUrl . '/wp-json/wp/v2/posts/' . $postId;
        $wpResponse = $this->apiRequest('PUT', $wpUrl, ['meta' => $metaFields]);

        if ($wpResponse !== null && !isset($wpResponse['code'])) {
            // Verifica che i meta siano stati effettivamente salvati
            $savedMeta = $wpResponse['meta'] ?? [];
            $metaDescSaved = empty($metaDescription) || ($savedMeta['_yoast_wpseo_metadesc'] ?? '') === $metaDescription;
            $focusKwSaved = empty($focusKeyphrase) || ($savedMeta['_yoast_wpseo_focuskw'] ?? '') === $focusKeyphrase;

            if ($metaDescSaved && $focusKwSaved) {
                $this->log("Yoast SEO meta aggiornati per post #{$postId}", 'success');
                return;
            }
        }

        // Metodo 3: Fallback con POST (alcune configurazioni WP richiedono POST invece di PUT)
        $fallbackResponse = $this->apiRequest('POST', $wpUrl, ['meta' => $metaFields]);

        if ($fallbackResponse !== null && !isset($fallbackResponse['code'])) {
            $this->log("Yoast SEO meta aggiornati via POST fallback per post #{$postId}", 'success');
        } else {
            $this->log("Impossibile aggiornare meta Yoast per post #{$postId} - verificare che Yoast SEO sia attivo e che l'utente abbia permessi di editare i meta", 'warning');
        }
    }

    private function slugify(string $text): string
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        $text = trim($text, '-');
        return mb_substr($text, 0, 60);
    }
}
