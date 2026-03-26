<?php

/**
 * Pannello di Controllo - AutoPilot RSS
 * Gestione configurazione, feed e monitoraggio
 */

session_start();

$config = require __DIR__ . '/config.php';

// --- Protezione con password ---
$dashboardHash = EnvLoader::get('DASHBOARD_PASSWORD');

// Logout
if (isset($_GET['logout'])) {
    unset($_SESSION['dashboard_auth']);
    session_regenerate_id(true);
    header('Location: dashboard.php');
    exit;
}

// Rate limiting login: max 5 tentativi in 15 minuti
if (isset($_POST['dashboard_password'])) {
    if (!isset($_SESSION['login_attempts'])) {
        $_SESSION['login_attempts'] = [];
    }
    // Rimuovi tentativi piu' vecchi di 15 minuti
    $_SESSION['login_attempts'] = array_filter($_SESSION['login_attempts'], function ($ts) {
        return $ts > time() - 900;
    });

    if (count($_SESSION['login_attempts']) >= 5) {
        $loginError = 'Troppi tentativi. Riprova tra qualche minuto.';
    } elseif (password_verify($_POST['dashboard_password'], $dashboardHash)) {
        $_SESSION['dashboard_auth'] = true;
        $_SESSION['login_attempts'] = [];
        session_regenerate_id(true);
    } else {
        $_SESSION['login_attempts'][] = time();
        $loginError = 'Password non valida.';
    }
}

// Genera token CSRF per i form
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'] ?? '';

// Se non autenticato, mostra form login
if (empty($_SESSION['dashboard_auth'])) {
    ?>
    <!DOCTYPE html>
    <html lang="it">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Login - AutoPilot</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #0f172a; color: #e2e8f0; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
            .login-box { background: #1e293b; border: 1px solid #334155; border-radius: 16px; padding: 40px; width: 100%; max-width: 400px; }
            .login-box h1 { font-size: 22px; color: #818cf8; margin-bottom: 8px; }
            .login-box p { font-size: 14px; color: #64748b; margin-bottom: 24px; }
            .login-box input { width: 100%; padding: 12px; background: #0f172a; border: 1px solid #334155; border-radius: 8px; color: #e2e8f0; font-size: 16px; margin-bottom: 16px; }
            .login-box input:focus { outline: none; border-color: #818cf8; }
            .login-box button { width: 100%; padding: 12px; background: #6366f1; color: white; border: none; border-radius: 8px; font-size: 16px; font-weight: 600; cursor: pointer; }
            .login-box button:hover { background: #4f46e5; }
            .error { color: #fca5a5; font-size: 13px; margin-bottom: 12px; }
        </style>
    </head>
    <body>
        <div class="login-box">
            <h1>AutoPilot</h1>
            <p>Pannello di Controllo</p>
            <?php if (!empty($loginError)): ?>
                <div class="error"><?= htmlspecialchars($loginError) ?></div>
            <?php endif; ?>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="password" name="dashboard_password" placeholder="Password" autofocus required>
                <button type="submit">Accedi</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

require __DIR__ . '/src/AutocompleteFetcher.php';
require __DIR__ . '/src/TopicFilter.php';
require __DIR__ . '/src/ContentGenerator.php';
require __DIR__ . '/src/SocialFeedBuilder.php';
require __DIR__ . '/src/ImageGenerator.php';
require __DIR__ . '/src/RSSFeedBuilder.php';
require __DIR__ . '/src/WordPressPublisher.php';

// Helper: maschera una chiave API mostrando solo gli ultimi 4 caratteri
function maskKey(string $key): string
{
    if (strlen($key) <= 8 || str_starts_with($key, 'YOUR_')) {
        return '(non configurata)';
    }
    return str_repeat('*', strlen($key) - 4) . substr($key, -4);
}

// --- Gestione azioni AJAX GET per fact-check ---
if (isset($_GET['action']) && $_GET['action'] === 'reset_factcheck_log') {
    $postedCsrf = $_GET['csrf_token'] ?? '';
    if (hash_equals($csrfToken, $postedCsrf)) {
        $fcDbPath = $config['db_path'] ?? __DIR__ . '/data/history.sqlite';
        $fcDb = new PDO('sqlite:' . $fcDbPath);
        $fcDb->exec("DELETE FROM factcheck_log");
        echo 'OK';
    } else {
        echo 'CSRF error';
    }
    exit;
}

// --- Gestione azioni AJAX GET per riscrittura ---
if (isset($_GET['action']) && $_GET['action'] === 'reset_rewrite_log') {
    $postedCsrf = $_GET['csrf_token'] ?? '';
    if (hash_equals($csrfToken, $postedCsrf)) {
        $rwDbPath = $config['db_path'] ?? __DIR__ . '/data/history.sqlite';
        $rwDb = new PDO('sqlite:' . $rwDbPath);
        $rwDb->exec('DELETE FROM rewrite_log');
        echo 'OK';
    } else {
        echo 'CSRF error';
    }
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'refresh_rw_cache') {
    $postedCsrf = $_GET['csrf_token'] ?? '';
    if (hash_equals($csrfToken, $postedCsrf)) {
        require_once __DIR__ . '/src/LinkBuilder.php';
        require_once __DIR__ . '/src/SmartLinkBuilder.php';
        $lb = new SmartLinkBuilder($config);
        $lb->refreshCache();
        echo 'OK';
    } else {
        echo 'CSRF error';
    }
    exit;
}

// --- Gestione azioni POST ---
$message = '';
$messageType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verifica CSRF token
    $postedToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($csrfToken, $postedToken)) {
        $message = 'Richiesta non valida (token CSRF mancante o errato). Ricarica la pagina.';
        $messageType = 'error';
    }

    $action = (!empty($message)) ? '' : ($_POST['action'] ?? '');

    if ($action === 'save_config') {
        // Salva API keys nel file .env (sicuro, fuori dal config.php)
        $newOpenaiKey = trim($_POST['openai_api_key'] ?? '');
        $newGeminiKey = trim($_POST['gemini_api_key'] ?? '');

        // Aggiorna solo se l'utente ha inserito una nuova chiave (non il valore mascherato)
        if (!empty($newOpenaiKey) && !str_contains($newOpenaiKey, '***')) {
            EnvLoader::set('OPENAI_API_KEY', $newOpenaiKey);
        }
        if (!empty($newGeminiKey) && !str_contains($newGeminiKey, '***')) {
            EnvLoader::set('GEMINI_API_KEY', $newGeminiKey);
        }

        $newOpenrouterKey = trim($_POST['openrouter_api_key'] ?? '');
        if (!empty($newOpenrouterKey) && !str_contains($newOpenrouterKey, '***')) {
            EnvLoader::set('OPENROUTER_API_KEY', $newOpenrouterKey);
        }

        $newFalKey = trim($_POST['fal_api_key'] ?? '');
        if (!empty($newFalKey) && !str_contains($newFalKey, '***')) {
            EnvLoader::set('FAL_API_KEY', $newFalKey);
        }

        $newCronToken = trim($_POST['cron_token'] ?? '');
        if (!empty($newCronToken) && !str_contains($newCronToken, '***')) {
            EnvLoader::set('CRON_TOKEN', $newCronToken);
        }

        $newWpAppPassword = trim($_POST['wp_app_password'] ?? '');
        if (!empty($newWpAppPassword) && !str_contains($newWpAppPassword, '***')) {
            EnvLoader::set('WP_APP_PASSWORD', $newWpAppPassword);
        }

        // Salva configurazione non sensibile in un file JSON separato
        $settingsPath = __DIR__ . '/data/settings.json';
        $settings = file_exists($settingsPath) ? json_decode(file_get_contents($settingsPath), true) : [];

        $settings['niche_name'] = trim($_POST['niche_name'] ?? 'Sogni e Dormire');
        $settings['niche_description'] = trim($_POST['niche_description'] ?? '');
        $settings['max_articles_per_run'] = max(1, intval($_POST['max_articles_per_run'] ?? 3));
        $settings['max_feed_items'] = max(10, intval($_POST['max_feed_items'] ?? 50));
        $settings['default_provider'] = trim($_POST['default_provider'] ?? 'openai');
        $settings['openai_model'] = trim($_POST['openai_model'] ?? 'gpt-4o-mini');
        $settings['gemini_model'] = trim($_POST['gemini_model'] ?? 'gemini-2.0-flash');
        $settings['openrouter_model'] = trim($_POST['openrouter_model'] ?? 'openai/gpt-4o-mini');
        $settings['feed_title'] = trim($_POST['feed_title'] ?? '');
        $settings['feed_link'] = trim($_POST['feed_link'] ?? '');

        // Impostazioni fal.ai
        $settings['fal_enabled']              = !empty($_POST['fal_enabled']);
        $settings['fal_model_id']             = trim($_POST['fal_model_id'] ?? 'fal-ai/flux/schnell');
        $settings['fal_image_size']           = trim($_POST['fal_image_size'] ?? 'landscape_16_9');
        $settings['fal_output_format']        = trim($_POST['fal_output_format'] ?? 'jpeg');
        $settings['fal_quality']              = trim($_POST['fal_quality'] ?? '');
        $settings['fal_inline_enabled']       = !empty($_POST['fal_inline_enabled']);
        $settings['fal_inline_interval']      = max(1, intval($_POST['fal_inline_interval'] ?? 3));
        $settings['fal_inline_size']          = trim($_POST['fal_inline_size'] ?? 'landscape_16_9');

        $falPrompt = trim($_POST['fal_prompt_template'] ?? '');
        if (!empty($falPrompt)) {
            $settings['fal_prompt_template'] = $falPrompt;
        }

        $falInlinePrompt = trim($_POST['fal_inline_prompt_template'] ?? '');
        if (!empty($falInlinePrompt)) {
            $settings['fal_inline_prompt_template'] = $falInlinePrompt;
        }

        // Salva sorgente keyword
        $settings['keyword_source'] = in_array($_POST['keyword_source'] ?? '', ['google', 'manual']) ? $_POST['keyword_source'] : 'google';

        // Salva semi di ricerca (Google Autocomplete)
        $semiRaw = trim($_POST['semi_ricerca'] ?? '');
        if (!empty($semiRaw)) {
            $settings['semi_ricerca'] = array_values(array_filter(array_map('trim', explode("\n", $semiRaw))));
        }

        // Salva keyword manuali
        $manualRaw = trim($_POST['manual_keywords'] ?? '');
        $settings['manual_keywords'] = !empty($manualRaw) ? array_values(array_filter(array_map('trim', explode("\n", $manualRaw)))) : [];

        // Salva prompt personalizzato
        $promptRaw = trim($_POST['prompt_template'] ?? '');
        if (!empty($promptRaw)) {
            $settings['prompt_template'] = $promptRaw;
        }

        // Salva prompt titolo dedicato
        $titlePromptRaw = trim($_POST['title_prompt_template'] ?? '');
        $settings['title_prompt_template'] = $titlePromptRaw; // può essere vuoto (usa default interno)

        // Salva impostazioni WordPress Publishing
        $settings['wp_enabled']      = !empty($_POST['wp_enabled']);
        $settings['wp_site_url']     = rtrim(trim($_POST['wp_site_url'] ?? ''), '/');
        $settings['wp_username']     = trim($_POST['wp_username'] ?? '');
        $settings['wp_post_status']  = trim($_POST['wp_post_status'] ?? 'draft');
        $settings['wp_category']     = trim($_POST['wp_category'] ?? '');
        $settings['wp_auto_publish'] = !empty($_POST['wp_auto_publish']);

        // Salva impostazioni Link Building
        $settings['link_internal_enabled'] = !empty($_POST['link_internal_enabled']);
        $settings['link_external_enabled'] = !empty($_POST['link_external_enabled']);
        $settings['link_max_internal']     = max(1, min(10, intval($_POST['link_max_internal'] ?? 5)));
        $settings['link_max_external']     = max(0, min(5, intval($_POST['link_max_external'] ?? 2)));
        $settings['link_cache_ttl']        = max(1800, intval($_POST['link_cache_ttl'] ?? 21600));

        // Salva impostazioni social
        $oldSocialSiteUrl = $settings['social_site_url'] ?? $config['social_site_url'] ?? '';
        $newSocialSiteUrl = trim($_POST['social_site_url'] ?? '');
        
        $settings['social_feeds_enabled'] = !empty($_POST['social_feeds_enabled']);
        $settings['social_site_url'] = $newSocialSiteUrl;
        $settings['social_permalink_structure'] = trim($_POST['social_permalink_structure'] ?? '/%year%/%monthnum%/%day%/%postname%/');
        $settings['facebook_prompt'] = trim($_POST['facebook_prompt'] ?? '');
        $settings['twitter_prompt'] = trim($_POST['twitter_prompt'] ?? '');

        file_put_contents($settingsPath, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // Aggiorna URL nei feed se è cambiato l'URL del sito
        $urlUpdateMessages = [];
        if (!empty($oldSocialSiteUrl) && !empty($newSocialSiteUrl) && $oldSocialSiteUrl !== $newSocialSiteUrl) {
            try {
                require __DIR__ . '/src/SocialFeedBuilder.php';
                require __DIR__ . '/src/RSSFeedBuilder.php';
                
                // Crea config temporaneo con il nuovo URL
                $updatedConfig = array_merge($config, ['social_site_url' => $newSocialSiteUrl]);
                
                // Aggiorna feed social
                $socialFeedBuilder = new SocialFeedBuilder($updatedConfig);
                $socialResults = $socialFeedBuilder->updateAllUrls($oldSocialSiteUrl, $newSocialSiteUrl);
                foreach ($socialResults as $feed => $result) {
                    $urlUpdateMessages[] = "Feed {$feed}: {$result}";
                }
                
                // Aggiorna feed principale
                $updatedConfig['feed_link'] = $newSocialSiteUrl;
                $rssFeedBuilder = new RSSFeedBuilder($updatedConfig);
                $rssResult = $rssFeedBuilder->updateAllUrls($oldSocialSiteUrl, $newSocialSiteUrl);
                $urlUpdateMessages[] = "Feed principale: {$rssResult}";
            } catch (Throwable $e) {
                $urlUpdateMessages[] = "Errore aggiornamento URL: " . $e->getMessage();
            }
        }

        $message = 'Configurazione salvata con successo. Le API key sono nel file .env protetto.';
        if (!empty($urlUpdateMessages)) {
            $message .= ' URL aggiornati nei feed: ' . implode(', ', $urlUpdateMessages);
        }
        $messageType = 'success';

        // Ricarica config
        $config = require __DIR__ . '/config.php';

        // Sovrascrivi con settings salvati
        $config = array_merge($config, $settings);
    }

    if ($action === 'run_now') {
        // Esecuzione diretta (compatibile con hosting dove exec() è disabilitato)
        ob_start();
        try {
            // Evita conflitto di ri-dichiarazione classi (già caricate sopra)
            // Esegui la logica di main.php inline
            $fetcher = new AutocompleteFetcher($config);
            $suggerimenti = $fetcher->fetch();

            if (empty($suggerimenti)) {
                $message = 'Nessun suggerimento trovato da Google Autocomplete.';
                $messageType = 'info';
            } else {
                $filter = new TopicFilter($config);
                $nuovi = $filter->filter($suggerimenti);

                if (empty($nuovi)) {
                    $message = 'Trovati ' . count($suggerimenti) . ' suggerimenti, ma nessun topic nuovo da elaborare.';
                    $messageType = 'info';
                } else {
                    $generator = new ContentGenerator($config);
                    $imageGen = new ImageGenerator($config);
                    $feedBuilderRun = new RSSFeedBuilder($config);
                    $socialFeedBuilder = (!empty($config['social_feeds_enabled'])) ? new SocialFeedBuilder($config) : null;
                    $wpPublisher = new WordPressPublisher($config);
                    $generati = 0;
                    $errori = 0;
                    $scartati = 0;
                    $immaginiGen = 0;

                    foreach ($nuovi as $topic) {
                        // Check pertinenza DISABILITATO
                        
                        $filter->markInProgress($topic);
                        $articolo = $generator->generate($topic);

                        if ($articolo !== null) {
                            $body = $articolo['body'];
                            $featuredImage = null;

                            // Genera immagine featured (solo per il tag image del feed)
                            if ($imageGen->isEnabled()) {
                                $featuredImage = $imageGen->generateFeaturedImage($articolo['title'], $topic);
                                if ($featuredImage !== null) {
                                    $immaginiGen++;
                                }
                            }

                            // Genera immagini inline (solo nel contenuto)
                            if ($imageGen->isInlineEnabled()) {
                                $body = $imageGen->insertInlineImages($articolo['title'], $body, $topic);
                            }

                            // Prepara la meta description per il feed
                            $metaDescription = $articolo['meta_description'] ?? '';
                            if (empty($metaDescription)) {
                                $metaDescription = ContentGenerator::extractMetaDescription($body);
                            }
                            $feedBuilderRun->addItem($articolo['title'], $body, $featuredImage, $metaDescription);

                            // Pubblicazione su WordPress (se auto-publish attivo)
                            $wpPostUrl = null;
                            if ($wpPublisher->isEnabled() && !empty($config['wp_auto_publish'])) {
                                $wpCategories = $wpPublisher->getCategories();
                                $wpCategoryName = $generator->suggestCategory($articolo['title'], $topic, $wpCategories);
                                
                                // Usa la meta description generata dall'AI o estratta dal body
                                $metaDescription = $articolo['meta_description'] ?? '';
                                if (empty($metaDescription)) {
                                    $metaDescription = ContentGenerator::extractMetaDescription($body);
                                }
                                
                                $wpResult = $wpPublisher->publish(
                                    $articolo['title'],
                                    $body,
                                    $featuredImage['url'] ?? null,
                                    $metaDescription,
                                    null,
                                    $wpCategoryName,
                                    $topic,
                                    $articolo['tags'] ?? [],
                                    $articolo['seo_title'] ?? null,
                                    $articolo['schema_markup'] ?? null
                                );
                                if ($wpResult !== null) {
                                    $wpPostUrl = $wpResult['post_url'];

                                    // Segna l'item come pubblicato nel feed
                                    $itemIdx = $feedBuilderRun->findItemIndex($articolo['title']);
                                    if ($itemIdx !== null) {
                                        $feedBuilderRun->markAsPublished($itemIdx, $wpResult['post_id'], $wpPostUrl);
                                    }
                                }
                            }

                            // Feed social: solo se pubblicato su WordPress
                            if ($socialFeedBuilder !== null && $wpPostUrl !== null) {
                                $socialFeedBuilder->addItem($articolo['title'], $featuredImage['url'] ?? null, $generator, $wpPostUrl);
                            }

                            $filter->markCompleted($topic);
                            $generati++;
                        } else {
                            $filter->markFailed($topic);
                            $errori++;
                        }
                        sleep(2);
                    }

                    $imgMsg = $immaginiGen > 0 ? ", {$immaginiGen} immagini generate" : '';
                    $message = "Esecuzione completata: {$generati} articoli generati{$imgMsg}, {$scartati} scartati (non pertinenti), {$errori} errori su " . count($nuovi) . " topic.";
                    $messageType = $errori === 0 ? 'success' : 'info';
                }
            }
        } catch (Throwable $e) {
            $message = 'Errore durante esecuzione: ' . $e->getMessage();
            $messageType = 'error';
        }
        ob_end_clean();

        // Ricarica dati feed dopo esecuzione
        $feedBuilder = new RSSFeedBuilder($config);
        $feedItems = $feedBuilder->getItems();
        $feedCount = count($feedItems);
    }

    if ($action === 'clear_history') {
        $dbPath = $config['db_path'];
        if (file_exists($dbPath)) {
            $db = new PDO('sqlite:' . $dbPath);
            $db->exec('DELETE FROM topics');
            $message = 'Storico topic cancellato.';
            $messageType = 'success';
        }
    }

    if ($action === 'delete_topic') {
        $topicId = intval($_POST['topic_id'] ?? 0);
        if ($topicId > 0 && file_exists($config['db_path'])) {
            $db = new PDO('sqlite:' . $config['db_path']);
            $stmt = $db->prepare('DELETE FROM topics WHERE id = ?');
            $stmt->execute([$topicId]);
            $message = 'Topic eliminato.';
            $messageType = 'success';
        }
    }

    if ($action === 'delete_feed_item') {
        $indexToDelete = intval($_POST['item_index'] ?? -1);
        $feedPath = $config['feed_path'];
        if (file_exists($feedPath) && $indexToDelete >= 0) {
            $doc = new DOMDocument();
            $doc->load($feedPath);
            $items = $doc->getElementsByTagName('item');
            if ($indexToDelete < $items->length) {
                $items->item($indexToDelete)->parentNode->removeChild($items->item($indexToDelete));
                $doc->formatOutput = true;
                file_put_contents($feedPath, $doc->saveXML());
                $message = 'Item eliminato dal feed.';
                $messageType = 'success';
            }
        }
    }

    if ($action === 'delete_feed_items_bulk') {
        $feedPath = $config['feed_path'];
        $indicesToDelete = $_POST['selected_items'] ?? [];
        if (file_exists($feedPath) && !empty($indicesToDelete)) {
            $doc = new DOMDocument();
            $doc->load($feedPath);
            $items = $doc->getElementsByTagName('item');
            // Ordina gli indici in ordine decrescente per rimuovere dal fondo
            $indicesToDelete = array_map('intval', $indicesToDelete);
            rsort($indicesToDelete);
            $deleted = 0;
            foreach ($indicesToDelete as $idx) {
                if ($idx >= 0 && $idx < $items->length) {
                    $items->item($idx)->parentNode->removeChild($items->item($idx));
                    $deleted++;
                }
            }
            if ($deleted > 0) {
                $doc->formatOutput = true;
                file_put_contents($feedPath, $doc->saveXML());
                $message = $deleted . ' articoli eliminati dal feed.';
                $messageType = 'success';
            }
        } else {
            $message = 'Nessun articolo selezionato.';
            $messageType = 'warning';
        }
    }

    if ($action === 'edit_feed_item') {
        header('Content-Type: application/json; charset=utf-8');

        $itemIndex = intval($_POST['item_index'] ?? -1);
        $newTitle = trim($_POST['new_title'] ?? '');
        $newContent = trim($_POST['new_content'] ?? '');

        if ($itemIndex < 0 || empty($newTitle) || empty($newContent)) {
            echo json_encode(['success' => false, 'message' => 'Dati mancanti o non validi.']);
            exit;
        }

        $feedBuilder = new RSSFeedBuilder($config);
        $ok = $feedBuilder->updateItem($itemIndex, $newTitle, $newContent);

        if ($ok) {
            echo json_encode(['success' => true, 'message' => 'Articolo aggiornato con successo.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Errore durante l\'aggiornamento.']);
        }
        exit;
    }

    if ($action === 'wp_test_connection') {
        header('Content-Type: application/json; charset=utf-8');
        $wp = new WordPressPublisher($config);
        $result = $wp->testConnection();
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'wp_publish_article') {
        header('Content-Type: application/json; charset=utf-8');

        $itemIndex = intval($_POST['item_index'] ?? -1);
        $feedPath = $config['feed_path'];

        if (!file_exists($feedPath) || $itemIndex < 0) {
            echo json_encode(['success' => false, 'message' => 'Feed non trovato o indice non valido']);
            exit;
        }

        $feedBuilder = new RSSFeedBuilder($config);
        $items = $feedBuilder->getItems();

        if ($itemIndex >= count($items)) {
            echo json_encode(['success' => false, 'message' => 'Articolo non trovato nel feed']);
            exit;
        }

        $item = $items[$itemIndex];
        $wp = new WordPressPublisher($config);

        if (!$wp->isEnabled()) {
            echo json_encode(['success' => false, 'message' => 'Pubblicazione WordPress non abilitata o credenziali mancanti. Configura nella tab Configurazione.']);
            exit;
        }

        // Determina categoria con AI
        $generator = new ContentGenerator($config);
        $wpCategories = $wp->getCategories();
        $wpCategoryName = $generator->suggestCategory($item['title'], $item['title'], $wpCategories);

        // Applica link building al contenuto prima della pubblicazione (se abilitato)
        $publishContent = $item['content'];
        require_once __DIR__ . '/src/LinkBuilder.php';
        require_once __DIR__ . '/src/SmartLinkBuilder.php';
        $lb = new SmartLinkBuilder($config);
        if ($lb->isEnabled()) {
            $topic = strip_tags(html_entity_decode($item['title'], ENT_QUOTES, 'UTF-8'));
            $related = $lb->getRelatedArticles($topic, 10);
            if (!empty($related)) {
                $relatedList = '';
                foreach (array_slice($related, 0, 10) as $i => $r) {
                    $relatedList .= ($i + 1) . '. "' . $r['title'] . '" - ' . $r['url'] . "\n";
                }
                $maxLinks = $config['link_max_internal'] ?? 5;
                $linkPrompt = "Hai il seguente articolo HTML gia' scritto in italiano. Devi inserire dei link interni verso altri articoli del sito, scegliendo i piu' pertinenti dalla lista fornita.\n\n"
                    . "REGOLE:\n"
                    . "- Inserisci tra 2 e {$maxLinks} link interni usando <a href=\"URL\">testo ancora descrittivo</a>\n"
                    . "- Inserisci i link in modo NATURALE nel corpo del testo\n"
                    . "- NON aggiungere sezioni nuove, NON raggruppare i link in fondo\n"
                    . "- NON modificare il testo esistente se non per inserire i tag <a>\n"
                    . "- Restituisci l'INTERO articolo HTML con i link inseriti\n\n"
                    . "Articoli disponibili per il linking:\n" . $relatedList . "\n"
                    . "ARTICOLO DA MODIFICARE:\n" . $publishContent . "\n\n"
                    . "Rispondi SOLO con l'HTML dell'articolo modificato, senza spiegazioni.";
                $modifiedContent = $generator->generateText($linkPrompt, 8000);
                if ($modifiedContent !== null && mb_strlen($modifiedContent) >= 200) {
                    $publishContent = $lb->postProcess($modifiedContent);
                }
            }
        }

        // Usa la meta description salvata nel feed o genera dall'AI
        $metaDescription = $item['meta_description'] ?? '';
        if (empty($metaDescription)) {
            $metaDescription = ContentGenerator::extractMetaDescription($publishContent);
        }
        
        $result = $wp->publish(
            $item['title'],
            $publishContent,
            $item['image'] ?? null,
            $metaDescription,
            null,
            $wpCategoryName,
            $item['keyword'] ?? $item['title'],
            $item['tags'] ?? [],
            $item['seo_title'] ?? null,
            $item['schema_markup'] ?? null
        );

        if ($result !== null) {
            // Segna l'item come pubblicato nel feed
            $feedBuilder->markAsPublished($itemIndex, $result['post_id'], $result['post_url']);

            // Aggiorna feed social con l'URL reale di WordPress
            if (!empty($config['social_feeds_enabled'])) {
                $socialFeedBuilder = new SocialFeedBuilder($config);
                $socialFeedBuilder->addItem($item['title'], $item['image'] ?? null, $generator, $result['post_url']);
            }

            echo json_encode(['success' => true, 'message' => 'Pubblicato con successo!', 'post_id' => $result['post_id'], 'post_url' => $result['post_url']]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Errore durante la pubblicazione. Controlla i log per dettagli.']);
        }
        exit;
    }

    if ($action === 'refresh_link_cache') {
        header('Content-Type: application/json; charset=utf-8');
        require_once __DIR__ . '/src/LinkBuilder.php';
        require_once __DIR__ . '/src/SmartLinkBuilder.php';
        $lb = new SmartLinkBuilder($config);
        if (!$lb->isEnabled()) {
            echo json_encode(['success' => false, 'message' => 'Link Building non abilitato o WordPress non configurato.']);
            exit;
        }
        $posts = $lb->refreshCache();
        echo json_encode(['success' => true, 'message' => count($posts) . ' articoli caricati nella cache.', 'count' => count($posts)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'check_wp_links') {
        header('Content-Type: application/json; charset=utf-8');

        $postIds = json_decode($_POST['post_ids'] ?? '[]', true);
        if (!is_array($postIds) || empty($postIds)) {
            echo json_encode(['success' => false, 'message' => 'Nessun ID fornito.']);
            exit;
        }

        $postIds = array_map('intval', array_slice($postIds, 0, 50));
        $wpUrl = rtrim($config['wp_site_url'] ?? '', '/');
        $wpUser = $config['wp_username'] ?? '';
        $wpPass = $config['wp_app_password'] ?? '';
        $results = [];

        foreach ($postIds as $postId) {
            $apiUrl = $wpUrl . '/wp-json/wp/v2/posts/' . $postId . '?_fields=id,content';
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $apiUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Basic ' . base64_encode($wpUser . ':' . $wpPass),
                ],
            ]);
            $resp = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($resp === false || $httpCode !== 200) {
                $results[$postId] = ['internal' => -1, 'external' => -1];
                continue;
            }
            $post = json_decode($resp, true);
            $content = $post['content']['rendered'] ?? '';
            $results[$postId] = [
                'internal' => countInternalLinks($content, $wpUrl),
                'external' => countExternalLinks($content, $wpUrl),
            ];
        }

        echo json_encode(['success' => true, 'results' => $results]);
        exit;
    }

    if ($action === 'relink_wp_article') {
        header('Content-Type: application/json; charset=utf-8');
        require_once __DIR__ . '/src/LinkBuilder.php';
        require_once __DIR__ . '/src/SmartLinkBuilder.php';
        $lb = new SmartLinkBuilder($config);
        if (!$lb->isEnabled()) {
            echo json_encode(['success' => false, 'message' => 'Link Building non abilitato.']);
            exit;
        }

        $wpPostId = intval($_POST['wp_post_id'] ?? 0);
        if ($wpPostId <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID post non valido.']);
            exit;
        }

        // Fetch il post da WordPress
        $wpUrl = rtrim($config['wp_site_url'] ?? '', '/');
        $wpUser = $config['wp_username'] ?? '';
        $wpPass = $config['wp_app_password'] ?? '';
        $apiUrl = $wpUrl . '/wp-json/wp/v2/posts/' . $wpPostId . '?_fields=id,title,content,link';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Authorization: Basic ' . base64_encode($wpUser . ':' . $wpPass),
                'Content-Type: application/json',
            ],
        ]);
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false || $httpCode !== 200) {
            echo json_encode(['success' => false, 'message' => 'Impossibile recuperare il post da WordPress (HTTP ' . $httpCode . ').']);
            exit;
        }

        $post = json_decode($resp, true);
        $postTitle = $post['title']['rendered'] ?? '';
        $postContent = $post['content']['rendered'] ?? '';

        if (empty($postContent)) {
            echo json_encode(['success' => false, 'message' => 'Il post non ha contenuto.']);
            exit;
        }

        // Costruisci contesto link (escludendo il post stesso)
        $topic = strip_tags(html_entity_decode($postTitle, ENT_QUOTES, 'UTF-8'));
        $related = $lb->getRelatedArticles($topic, 10);
        // Escludi il post stesso dai correlati
        $postUrl = $post['link'] ?? '';
        $related = array_filter($related, fn($r) => $r['url'] !== $postUrl && $r['id'] !== $wpPostId);
        $related = array_values($related);

        if (empty($related)) {
            echo json_encode(['success' => false, 'message' => 'Nessun articolo correlato trovato per il linking.']);
            exit;
        }

        // Usa AI per inserire i link nel contenuto esistente
        $generator = new ContentGenerator($config);
        $relatedList = '';
        foreach (array_slice($related, 0, $lb->getCacheInfo()['count'] > 0 ? 10 : 5) as $i => $r) {
            $relatedList .= ($i + 1) . '. "' . $r['title'] . '" - ' . $r['url'] . "\n";
        }

        $maxLinks = $config['link_max_internal'] ?? 5;
        $prompt = "Hai il seguente articolo HTML gia' scritto in italiano. Devi inserire dei link interni verso altri articoli del sito, scegliendo i piu' pertinenti dalla lista fornita.\n\n"
            . "REGOLE:\n"
            . "- Inserisci tra 2 e {$maxLinks} link interni usando <a href=\"URL\">testo ancora descrittivo</a>\n"
            . "- Inserisci i link in modo NATURALE nel corpo del testo, dove il contesto e' pertinente\n"
            . "- NON aggiungere sezioni nuove, NON raggruppare i link in fondo\n"
            . "- NON modificare il testo esistente se non per inserire i tag <a>\n"
            . "- Restituisci l'INTERO articolo HTML con i link inseriti\n\n"
            . "Articoli disponibili per il linking:\n" . $relatedList . "\n"
            . "ARTICOLO DA MODIFICARE:\n" . $postContent . "\n\n"
            . "Rispondi SOLO con l'HTML dell'articolo modificato, senza spiegazioni.";

        $modifiedContent = $generator->generateText($prompt, 8000);
        if ($modifiedContent === null || mb_strlen($modifiedContent) < 200) {
            echo json_encode(['success' => false, 'message' => 'Generazione AI fallita.']);
            exit;
        }

        // Post-processing: valida i link
        $modifiedContent = $lb->postProcess($modifiedContent);

        // Aggiorna il post su WordPress
        $updateUrl = $wpUrl . '/wp-json/wp/v2/posts/' . $wpPostId;
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $updateUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['content' => $modifiedContent], JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => [
                'Authorization: Basic ' . base64_encode($wpUser . ':' . $wpPass),
                'Content-Type: application/json',
            ],
        ]);
        $updateResp = curl_exec($ch);
        $updateCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($updateResp === false || $updateCode < 200 || $updateCode >= 300) {
            echo json_encode(['success' => false, 'message' => 'Errore aggiornamento WordPress (HTTP ' . $updateCode . ').']);
            exit;
        }

        // Conta i link interni nel contenuto aggiornato
        $updatedIntLinks = countInternalLinks($modifiedContent, $wpUrl);
        $updatedExtLinks = countExternalLinks($modifiedContent, $wpUrl);

        echo json_encode([
            'success' => true,
            'message' => "Link building applicato! {$updatedIntLinks} link interni, {$updatedExtLinks} esterni.",
            'internal_links' => $updatedIntLinks,
            'external_links' => $updatedExtLinks,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'relink_wp_bulk') {
        header('Content-Type: application/json; charset=utf-8');
        require_once __DIR__ . '/src/LinkBuilder.php';
        require_once __DIR__ . '/src/SmartLinkBuilder.php';
        $lb = new SmartLinkBuilder($config);
        if (!$lb->isEnabled()) {
            echo json_encode(['success' => false, 'message' => 'Link Building non abilitato.']);
            exit;
        }

        // Prendi tutti i post dalla cache
        $cacheInfo = $lb->getCacheInfo();
        if ($cacheInfo['count'] === 0) {
            $lb->refreshCache();
            $cacheInfo = $lb->getCacheInfo();
        }

        $allPosts = $lb->getRelatedArticles('', 9999); // tutti
        if (empty($allPosts)) {
            // Fallback: usa la cache direttamente
            $cachePath = ($config['base_dir'] ?? __DIR__) . '/data/cache_wp_posts.json';
            if (file_exists($cachePath)) {
                $cacheData = json_decode(file_get_contents($cachePath), true);
                $allPosts = $cacheData['posts'] ?? [];
            }
        }

        $maxToProcess = min(intval($_POST['max_posts'] ?? 10), 50);
        $processed = 0;
        $errors = 0;
        $generator = new ContentGenerator($config);
        $wpUrl = rtrim($config['wp_site_url'] ?? '', '/');
        $wpUser = $config['wp_username'] ?? '';
        $wpPass = $config['wp_app_password'] ?? '';
        $maxLinks = $config['link_max_internal'] ?? 5;

        foreach (array_slice($allPosts, 0, $maxToProcess) as $post) {
            $postId = $post['id'];

            // Fetch il contenuto completo
            $apiUrl = $wpUrl . '/wp-json/wp/v2/posts/' . $postId . '?_fields=id,title,content,link';
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $apiUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Basic ' . base64_encode($wpUser . ':' . $wpPass),
                    'Content-Type: application/json',
                ],
            ]);
            $resp = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($resp === false || $httpCode !== 200) {
                $errors++;
                continue;
            }

            $fullPost = json_decode($resp, true);
            $postContent = $fullPost['content']['rendered'] ?? '';
            $postTitle = strip_tags(html_entity_decode($fullPost['title']['rendered'] ?? '', ENT_QUOTES, 'UTF-8'));
            $postLink = $fullPost['link'] ?? '';

            if (empty($postContent) || mb_strlen($postContent) < 200) {
                continue;
            }

            // Verifica se il post ha gia' link interni
            $doc = new DOMDocument();
            @$doc->loadHTML('<?xml encoding="UTF-8"><body>' . $postContent . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            $existingLinks = $doc->getElementsByTagName('a');
            $siteHost = parse_url($wpUrl, PHP_URL_HOST);
            $internalLinkCount = 0;
            for ($i = 0; $i < $existingLinks->length; $i++) {
                $href = $existingLinks->item($i)->getAttribute('href');
                if (parse_url($href, PHP_URL_HOST) === $siteHost) {
                    $internalLinkCount++;
                }
            }

            // Salta se ha gia' abbastanza link interni
            if ($internalLinkCount >= $maxLinks) {
                continue;
            }

            // Trova articoli correlati (escludendo se stesso)
            $related = $lb->getRelatedArticles($postTitle, 10);
            $related = array_filter($related, fn($r) => $r['url'] !== $postLink && $r['id'] !== $postId);
            $related = array_values($related);

            if (empty($related)) {
                continue;
            }

            $relatedList = '';
            foreach (array_slice($related, 0, 10) as $i => $r) {
                $relatedList .= ($i + 1) . '. "' . $r['title'] . '" - ' . $r['url'] . "\n";
            }

            $prompt = "Hai il seguente articolo HTML gia' scritto in italiano. Devi inserire dei link interni verso altri articoli del sito, scegliendo i piu' pertinenti dalla lista fornita.\n\n"
                . "REGOLE:\n"
                . "- Inserisci tra 2 e {$maxLinks} link interni usando <a href=\"URL\">testo ancora descrittivo</a>\n"
                . "- Inserisci i link in modo NATURALE nel corpo del testo\n"
                . "- NON aggiungere sezioni nuove, NON raggruppare i link in fondo\n"
                . "- NON modificare il testo esistente se non per inserire i tag <a>\n"
                . "- Restituisci l'INTERO articolo HTML con i link inseriti\n\n"
                . "Articoli disponibili per il linking:\n" . $relatedList . "\n"
                . "ARTICOLO DA MODIFICARE:\n" . $postContent . "\n\n"
                . "Rispondi SOLO con l'HTML dell'articolo modificato, senza spiegazioni.";

            $modifiedContent = $generator->generateText($prompt, 8000);
            if ($modifiedContent === null || mb_strlen($modifiedContent) < 200) {
                $errors++;
                continue;
            }

            $modifiedContent = $lb->postProcess($modifiedContent);

            // Aggiorna il post
            $updateUrl = $wpUrl . '/wp-json/wp/v2/posts/' . $postId;
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $updateUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode(['content' => $modifiedContent], JSON_UNESCAPED_UNICODE),
                CURLOPT_HTTPHEADER => [
                    'Authorization: Basic ' . base64_encode($wpUser . ':' . $wpPass),
                    'Content-Type: application/json',
                ],
            ]);
            $updateResp = curl_exec($ch);
            $updateCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($updateResp !== false && $updateCode >= 200 && $updateCode < 300) {
                $processed++;
            } else {
                $errors++;
            }

            sleep(2); // Rate limiting
        }

        echo json_encode([
            'success' => true,
            'message' => "Completato: {$processed} articoli aggiornati, {$errors} errori.",
            'processed' => $processed,
            'errors' => $errors,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'relink_wp_selected') {
        header('Content-Type: application/json; charset=utf-8');
        require_once __DIR__ . '/src/LinkBuilder.php';
        require_once __DIR__ . '/src/SmartLinkBuilder.php';
        $lb = new SmartLinkBuilder($config);
        if (!$lb->isEnabled()) {
            echo json_encode(['success' => false, 'message' => 'Link Building non abilitato.']);
            exit;
        }

        $postIds = json_decode($_POST['post_ids'] ?? '[]', true);
        if (!is_array($postIds) || empty($postIds)) {
            echo json_encode(['success' => false, 'message' => 'Nessun articolo selezionato.']);
            exit;
        }

        $postIds = array_map('intval', $postIds);
        $postIds = array_filter($postIds, fn($id) => $id > 0);
        $postIds = array_slice($postIds, 0, 50);

        $processed = 0;
        $errors = 0;
        $skipped = 0;
        $generator = new ContentGenerator($config);
        $wpUrl = rtrim($config['wp_site_url'] ?? '', '/');
        $wpUser = $config['wp_username'] ?? '';
        $wpPass = $config['wp_app_password'] ?? '';
        $maxLinks = $config['link_max_internal'] ?? 5;

        foreach ($postIds as $postId) {
            $apiUrl = $wpUrl . '/wp-json/wp/v2/posts/' . $postId . '?_fields=id,title,content,link';
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $apiUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Basic ' . base64_encode($wpUser . ':' . $wpPass),
                    'Content-Type: application/json',
                ],
            ]);
            $resp = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($resp === false || $httpCode !== 200) {
                $errors++;
                continue;
            }

            $fullPost = json_decode($resp, true);
            $postContent = $fullPost['content']['rendered'] ?? '';
            $postTitle = strip_tags(html_entity_decode($fullPost['title']['rendered'] ?? '', ENT_QUOTES, 'UTF-8'));
            $postLink = $fullPost['link'] ?? '';

            if (empty($postContent) || mb_strlen($postContent) < 200) {
                $skipped++;
                continue;
            }

            // Conta link interni esistenti
            $doc = new DOMDocument();
            @$doc->loadHTML('<?xml encoding="UTF-8"><body>' . $postContent . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            $existingLinks = $doc->getElementsByTagName('a');
            $siteHost = parse_url($wpUrl, PHP_URL_HOST);
            $internalLinkCount = 0;
            for ($i = 0; $i < $existingLinks->length; $i++) {
                $href = $existingLinks->item($i)->getAttribute('href');
                if (parse_url($href, PHP_URL_HOST) === $siteHost) {
                    $internalLinkCount++;
                }
            }

            if ($internalLinkCount >= $maxLinks) {
                $skipped++;
                continue;
            }

            $related = $lb->getRelatedArticles($postTitle, 10);
            $related = array_filter($related, fn($r) => $r['url'] !== $postLink && $r['id'] !== $postId);
            $related = array_values($related);

            if (empty($related)) {
                $skipped++;
                continue;
            }

            $relatedList = '';
            foreach (array_slice($related, 0, 10) as $i => $r) {
                $relatedList .= ($i + 1) . '. "' . $r['title'] . '" - ' . $r['url'] . "\n";
            }

            $prompt = "Hai il seguente articolo HTML gia' scritto in italiano. Devi inserire dei link interni verso altri articoli del sito, scegliendo i piu' pertinenti dalla lista fornita.\n\n"
                . "REGOLE:\n"
                . "- Inserisci tra 2 e {$maxLinks} link interni usando <a href=\"URL\">testo ancora descrittivo</a>\n"
                . "- Inserisci i link in modo NATURALE nel corpo del testo\n"
                . "- NON aggiungere sezioni nuove, NON raggruppare i link in fondo\n"
                . "- NON modificare il testo esistente se non per inserire i tag <a>\n"
                . "- Restituisci l'INTERO articolo HTML con i link inseriti\n\n"
                . "Articoli disponibili per il linking:\n" . $relatedList . "\n"
                . "ARTICOLO DA MODIFICARE:\n" . $postContent . "\n\n"
                . "Rispondi SOLO con l'HTML dell'articolo modificato, senza spiegazioni.";

            $modifiedContent = $generator->generateText($prompt, 8000);
            if ($modifiedContent === null || mb_strlen($modifiedContent) < 200) {
                $errors++;
                continue;
            }

            $modifiedContent = $lb->postProcess($modifiedContent);

            $updateUrl = $wpUrl . '/wp-json/wp/v2/posts/' . $postId;
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $updateUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode(['content' => $modifiedContent], JSON_UNESCAPED_UNICODE),
                CURLOPT_HTTPHEADER => [
                    'Authorization: Basic ' . base64_encode($wpUser . ':' . $wpPass),
                    'Content-Type: application/json',
                ],
            ]);
            $updateResp = curl_exec($ch);
            $updateCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($updateResp !== false && $updateCode >= 200 && $updateCode < 300) {
                $processed++;
            } else {
                $errors++;
            }

            sleep(2);
        }

        echo json_encode([
            'success' => true,
            'message' => "Completato: {$processed} aggiornati, {$skipped} saltati, {$errors} errori.",
            'processed' => $processed,
            'skipped' => $skipped,
            'errors' => $errors,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'clear_log') {
        $logPath = $config['log_path'];
        if (file_exists($logPath)) {
            file_put_contents($logPath, '');
            $message = 'Log svuotato con successo.';
            $messageType = 'success';
        } else {
            $message = 'Nessun file log trovato.';
            $messageType = 'info';
        }
    }

    if ($action === 'download_log') {
        $logPath = $config['log_path'];
        if (file_exists($logPath)) {
            header('Content-Type: text/plain');
            header('Content-Disposition: attachment; filename="trend_log_' . date('Y-m-d_H-i-s') . '.txt"');
            header('Content-Length: ' . filesize($logPath));
            readfile($logPath);
            exit;
        }
    }
}

// --- Raccogli dati per la dashboard ---

// Statistiche DB
$dbStats = ['total' => 0, 'completed' => 0, 'failed' => 0];
$recentTopics = [];
if (file_exists($config['db_path'])) {
    $db = new PDO('sqlite:' . $config['db_path']);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Verifica che la tabella esista prima di fare query
    $tableExists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='topics'")->fetch();
    if ($tableExists) {
        $dbStats['total'] = $db->query('SELECT COUNT(*) FROM topics')->fetchColumn();
        $dbStats['completed'] = $db->query('SELECT COUNT(*) FROM topics WHERE status = "completed"')->fetchColumn();
        $dbStats['skipped'] = $db->query('SELECT COUNT(*) FROM topics WHERE status = "skipped"')->fetchColumn();
        $dbStats['failed'] = $db->query('SELECT COUNT(*) FROM topics WHERE status = "in_progress"')->fetchColumn();
        $recentTopics = $db->query('SELECT id, topic, status, created_at FROM topics ORDER BY created_at DESC LIMIT 20')->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Feed items
$feedBuilder = new RSSFeedBuilder($config);
$feedItems = $feedBuilder->getItems();
$feedCount = count($feedItems);

// Helper: conta i link interni nel contenuto HTML di un articolo
function countInternalLinks(string $html, string $siteUrl): int {
    if (empty($siteUrl) || empty($html)) return 0;
    $host = parse_url($siteUrl, PHP_URL_HOST);
    if (!$host) return 0;
    preg_match_all('/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>/i', $html, $matches);
    $count = 0;
    foreach ($matches[1] as $url) {
        $linkHost = parse_url($url, PHP_URL_HOST);
        if ($linkHost && (strcasecmp($linkHost, $host) === 0 || str_ends_with(strtolower($linkHost), '.' . strtolower($host)))) {
            $count++;
        }
    }
    return $count;
}

function countExternalLinks(string $html, string $siteUrl): int {
    if (empty($html)) return 0;
    $host = $siteUrl ? parse_url($siteUrl, PHP_URL_HOST) : '';
    preg_match_all('/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>/i', $html, $matches);
    $count = 0;
    foreach ($matches[1] as $url) {
        $linkHost = parse_url($url, PHP_URL_HOST);
        if ($linkHost && (!$host || strcasecmp($linkHost, $host) !== 0)) {
            $count++;
        }
    }
    return $count;
}

// Log recenti
$logContent = '';
if (file_exists($config['log_path'])) {
    $logLines = file($config['log_path']);
    $logContent = implode('', array_slice($logLines, -50));
}

// Tab attiva
$tab = $_GET['tab'] ?? 'overview';
