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
                                    $wpCategoryName
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
            $wpCategoryName
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

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AutoPilot - Pannello di Controllo</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #0f172a; color: #e2e8f0; }

        /* --- Mobile top bar --- */
        .mobile-topbar {
            display: none;
            position: fixed; top: 0; left: 0; right: 0; z-index: 1001;
            background: #1e293b; border-bottom: 1px solid #334155;
            padding: 12px 16px; align-items: center; justify-content: space-between;
        }
        .mobile-topbar h1 { font-size: 16px; color: #818cf8; }
        .hamburger {
            background: none; border: none; color: #e2e8f0; font-size: 24px;
            cursor: pointer; padding: 4px 8px; line-height: 1;
        }
        .sidebar-overlay {
            display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5);
            z-index: 1002;
        }
        .sidebar-overlay.open { display: block; }

        /* --- Sidebar --- */
        .sidebar {
            position: fixed; left: 0; top: 0; bottom: 0; width: 240px;
            background: #1e293b; border-right: 1px solid #334155; padding: 20px 0;
            z-index: 1003; transition: transform 0.25s ease;
            overflow-y: auto; -webkit-overflow-scrolling: touch;
        }
        .sidebar h1 { font-size: 18px; padding: 0 20px 20px; border-bottom: 1px solid #334155; color: #818cf8; }
        .sidebar nav { margin-top: 20px; }
        .sidebar a { display: block; padding: 10px 20px; color: #94a3b8; text-decoration: none; font-size: 14px; transition: all 0.2s; }
        .sidebar a:hover, .sidebar a.active { background: #334155; color: #e2e8f0; }
        .sidebar a.active { border-left: 3px solid #818cf8; }

        .main { margin-left: 240px; padding: 30px; min-height: 100vh; }

        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; flex-wrap: wrap; gap: 12px; }
        .header h2 { font-size: 24px; color: #f1f5f9; }

        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 30px; }
        .stat-card { background: #1e293b; border: 1px solid #334155; border-radius: 12px; padding: 20px; }
        .stat-card .label { font-size: 12px; text-transform: uppercase; color: #64748b; letter-spacing: 1px; }
        .stat-card .value { font-size: 32px; font-weight: 700; color: #f1f5f9; margin-top: 4px; }
        .stat-card .value.green { color: #4ade80; }
        .stat-card .value.blue { color: #60a5fa; }
        .stat-card .value.purple { color: #a78bfa; }
        .stat-card .value.orange { color: #fb923c; }

        .card { background: #1e293b; border: 1px solid #334155; border-radius: 12px; padding: 24px; margin-bottom: 20px; }
        .card h3 { font-size: 16px; margin-bottom: 16px; color: #f1f5f9; }

        .message { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; }
        .message.success { background: #065f46; color: #6ee7b7; border: 1px solid #059669; }
        .message.error { background: #7f1d1d; color: #fca5a5; border: 1px solid #dc2626; }
        .message.info { background: #1e3a5f; color: #93c5fd; border: 1px solid #3b82f6; }

        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #334155; }
        th { color: #64748b; font-size: 12px; text-transform: uppercase; letter-spacing: 1px; }
        td { color: #cbd5e1; }

        .badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; }
        .badge.completed { background: #065f46; color: #6ee7b7; }
        .badge.in_progress { background: #78350f; color: #fbbf24; }
        .badge.pending { background: #1e3a5f; color: #93c5fd; }
        .badge.skipped { background: #4a1d1d; color: #fca5a5; }
        .badge.links-yes { background: #065f46; color: #6ee7b7; }
        .badge.links-no { background: #1e293b; color: #64748b; }
        .badge.links-partial { background: #78350f; color: #fbbf24; }
        .link-indicator { display: inline-flex; align-items: center; gap: 4px; font-size: 11px; }
        .link-indicator .link-icon { font-size: 13px; }

        input[type="text"], input[type="number"], input[type="password"], textarea, select {
            width: 100%; padding: 10px 12px; background: #0f172a; border: 1px solid #334155;
            border-radius: 8px; color: #e2e8f0; font-size: 16px; font-family: inherit;
        }
        input:focus, textarea:focus, select:focus { outline: none; border-color: #818cf8; }
        textarea { resize: vertical; min-height: 120px; }

        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-size: 13px; color: #94a3b8; margin-bottom: 6px; }
        .form-group .hint { font-size: 11px; color: #64748b; margin-top: 4px; }

        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }

        .btn { display: inline-block; padding: 10px 20px; border-radius: 8px; border: none; cursor: pointer;
               font-size: 14px; font-weight: 600; transition: all 0.2s; text-decoration: none; }
        .btn-primary { background: #6366f1; color: white; }
        .btn-primary:hover { background: #4f46e5; }
        .btn-danger { background: #dc2626; color: white; }
        .btn-danger:hover { background: #b91c1c; }
        .btn-success { background: #059669; color: white; }
        .btn-success:hover { background: #047857; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }

        .log-output { background: #0f172a; border: 1px solid #334155; border-radius: 8px; padding: 16px;
                       font-family: 'Fira Code', 'Consolas', monospace; font-size: 12px; line-height: 1.6;
                       color: #94a3b8; max-height: 400px; overflow-y: auto; white-space: pre-wrap; }

        .feed-item { border-bottom: 1px solid #334155; padding: 16px 0; }
        .feed-item:last-child { border-bottom: none; }
        .feed-item h4 { color: #f1f5f9; margin-bottom: 6px; font-size: 15px; line-height: 1.4; }
        .feed-item .date { font-size: 12px; color: #64748b; margin-bottom: 8px; }
        .feed-item .content-preview { font-size: 13px; color: #94a3b8; max-height: 80px; overflow: hidden; }
        .feed-item .actions { margin-top: 8px; }

        .content-full { display: none; padding: 16px; background: #0f172a; border-radius: 8px; margin-top: 8px;
                         font-size: 13px; line-height: 1.6; }
        .content-full.show { display: block; }
        .content-full h2, .content-full h3 { color: #f1f5f9; margin: 12px 0 8px; }
        .content-full p { margin-bottom: 8px; }
        .content-full ul, .content-full ol { margin: 8px 0 8px 20px; }

        /* --- Responsive: tablet --- */
        @media (max-width: 900px) {
            .form-row { grid-template-columns: 1fr; }
            .stats { grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); }
            .stat-card .value { font-size: 26px; }
        }

        /* --- Responsive: mobile --- */
        @media (max-width: 768px) {
            .mobile-topbar { display: flex; }
            .sidebar {
                transform: translateX(-100%);
                width: min(240px, 85vw);
            }
            .sidebar.open {
                transform: translateX(0);
            }
            /* Touch targets sidebar: minimo 44px (Apple HIG) */
            .sidebar a { padding: 13px 20px; font-size: 15px; }
            .main {
                margin-left: 0;
                padding: 70px 14px 32px;
            }
            .header { flex-direction: column; align-items: flex-start; gap: 10px; margin-bottom: 20px; }
            .header h2 { font-size: 20px; }
            .header > div { display: flex; flex-wrap: wrap; gap: 8px; width: 100%; }
            .header > div .btn { flex: 1 1 auto; text-align: center; margin-right: 0 !important; min-height: 44px; display: flex; align-items: center; justify-content: center; }
            .header > div form { flex: 1 1 auto; display: flex; }
            .header > div form .btn { width: 100%; }
            /* Stat cards: 3 colonne su mobile per numeri più compatti */
            .stats { grid-template-columns: repeat(3, 1fr); gap: 8px; margin-bottom: 20px; }
            .stat-card { padding: 12px 8px; text-align: center; }
            .stat-card .value { font-size: 22px; }
            .stat-card .label { font-size: 10px; }
            .card { padding: 16px; border-radius: 10px; }
            .card h3 { font-size: 15px; }
            /* Prevenire zoom iOS su tutti gli input */
            input[type="text"], input[type="number"], input[type="password"], textarea, select { font-size: 16px !important; }
            table { font-size: 13px; display: block; overflow-x: auto; -webkit-overflow-scrolling: touch; }
            th, td { padding: 8px; white-space: nowrap; }
            .btn { padding: 10px 16px; font-size: 13px; min-height: 44px; }
            .btn-sm { padding: 8px 12px; min-height: 36px; }
            .feed-item .actions { display: flex; flex-wrap: wrap; gap: 6px; }
            /* cfg-tabs: scrollabili orizzontalmente su mobile */
            .cfg-tabs { flex-wrap: nowrap; overflow-x: auto; -webkit-overflow-scrolling: touch; padding-bottom: 6px; gap: 6px; }
            .cfg-tab { white-space: nowrap; font-size: 13px; padding: 10px 14px; flex-shrink: 0; min-height: 44px; }
            /* Message banner leggibile su mobile */
            .message { font-size: 13px; padding: 10px 14px; }
        }

        /* Schermi molto piccoli (< 390px) */
        @media (max-width: 390px) {
            .stats { grid-template-columns: repeat(2, 1fr); }
            .stat-card .value { font-size: 20px; }
            .main { padding: 66px 10px 28px; }
        }
    </style>
</head>
<body>

<div class="mobile-topbar">
    <h1>AutoPilot</h1>
    <button class="hamburger" onclick="toggleSidebar()" aria-label="Menu">&#9776;</button>
</div>
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<div class="sidebar" id="sidebar">
    <h1>AutoPilot</h1>
    <div style="padding: 0 20px 15px; font-size: 11px; color: #64748b; border-bottom: 1px solid #334155; margin-bottom: 5px;">
        Nicchia: <strong style="color: #818cf8;"><?= htmlspecialchars($config['niche_name'] ?? 'Non configurata') ?></strong>
    </div>
    <nav>
        <a href="?tab=overview" class="<?= $tab === 'overview' ? 'active' : '' ?>">Panoramica</a>
        <a href="?tab=feed" class="<?= $tab === 'feed' ? 'active' : '' ?>">Gestione Feed</a>
        <a href="?tab=topics" class="<?= $tab === 'topics' ? 'active' : '' ?>">Topic Elaborati</a>
        <a href="?tab=config" class="<?= $tab === 'config' ? 'active' : '' ?>">Configurazione</a>
        <a href="?tab=logs" class="<?= $tab === 'logs' ? 'active' : '' ?>">Log</a>
        <a href="?tab=linkbuilding" class="<?= $tab === 'linkbuilding' ? 'active' : '' ?>">Link Building</a>
        <a href="?tab=seo" class="<?= $tab === 'seo' ? 'active' : '' ?>">SEO Analytics</a>
        <a href="?tab=contenthub" class="<?= $tab === 'contenthub' ? 'active' : '' ?>">🏛️ Content Hub</a>
        <a href="?tab=richresults" class="<?= $tab === 'richresults' ? 'active' : '' ?>">⭐ Rich Results</a>
        <a href="?tab=rewrite" class="<?= $tab === 'rewrite' ? 'active' : '' ?>">Riscrittura</a>
        <a href="?tab=factcheck" class="<?= $tab === 'factcheck' ? 'active' : '' ?>">Fact Check</a>
        <a href="?logout=1" style="margin-top:20px; border-top:1px solid #334155; padding-top:20px; color:#f87171;">Esci</a>
    </nav>
</div>

<div class="main">

<?php if (!empty($message)): ?>
    <div class="message <?= htmlspecialchars($messageType) ?>"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<?php if ($tab === 'overview'): ?>
    <div class="header">
        <h2>Panoramica</h2>
        <div>
            <a href="run.php" class="btn btn-success" style="margin-right:10px;">▶️ Esegui con Log</a>
            <button type="button" class="btn" style="background:#7c3aed;color:#fff;margin-right:10px;" onclick="openCustomRun()">🎯 Esegui Custom</button>
            <form method="post" style="display:inline;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="run_now">
                <button type="submit" class="btn btn-primary">Esegui Silenzioso</button>
            </form>
        </div>
    </div>

    <div class="stats">
        <div class="stat-card">
            <div class="label">Articoli nel Feed</div>
            <div class="value blue"><?= $feedCount ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Topic Elaborati</div>
            <div class="value green"><?= $dbStats['completed'] ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Topic Totali</div>
            <div class="value purple"><?= $dbStats['total'] ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Topic Scartati</div>
            <div class="value" style="color: #fca5a5;"><?= $dbStats['skipped'] ?? 0 ?></div>
        </div>
        <div class="stat-card">
            <div class="label"><?= ($config['keyword_source'] ?? 'google') === 'manual' ? 'Keyword Manuali' : 'Semi di Ricerca' ?></div>
            <div class="value orange"><?= ($config['keyword_source'] ?? 'google') === 'manual' ? count($config['manual_keywords'] ?? []) : count($config['semi_ricerca']) ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Link Building</div>
            <div class="value <?= !empty($config['link_internal_enabled']) ? 'green' : '' ?>"><?= !empty($config['link_internal_enabled']) ? 'ATTIVO' : 'OFF' ?></div>
        </div>
    </div>

    <div class="card">
        <h3>Ultimi Articoli Generati</h3>
        <?php if (empty($feedItems)): ?>
            <p style="color: #64748b;">Nessun articolo ancora generato. Clicca "Esegui Ora" per avviare.</p>
        <?php else: ?>
            <table>
                <thead><tr><th>Titolo</th><th>Link</th><th>WP</th><th>Data</th></tr></thead>
                <tbody>
                <?php foreach (array_slice($feedItems, 0, 10) as $item):
                    $wpUrl = $config['wp_site_url'] ?? '';
                    $intLinks = countInternalLinks($item['content'], $wpUrl);
                    $extLinks = countExternalLinks($item['content'], $wpUrl);
                    $hasLinks = $intLinks > 0;
                ?>
                    <tr>
                        <td><?= htmlspecialchars($item['title']) ?></td>
                        <td style="white-space:nowrap;">
                            <?php if ($hasLinks): ?>
                                <span class="badge links-yes" title="<?= $intLinks ?> link interni, <?= $extLinks ?> esterni">🔗 <?= $intLinks ?>i / <?= $extLinks ?>e</span>
                            <?php else: ?>
                                <span class="badge links-no" title="Nessun link interno rilevato">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="white-space:nowrap;">
                            <?php if (!empty($item['wp_post_id'])): ?>
                                <span class="badge completed" title="Pubblicato su WordPress">✓ WP</span>
                            <?php else: ?>
                                <span class="badge links-no">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="white-space:nowrap;"><?= htmlspecialchars($item['pubDate']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>Ultimi Topic Trovati</h3>
        <?php if (empty($recentTopics)): ?>
            <p style="color: #64748b;">Nessun topic ancora elaborato.</p>
        <?php else: ?>
            <table>
                <thead><tr><th>Topic</th><th>Stato</th><th>Data</th></tr></thead>
                <tbody>
                <?php foreach (array_slice($recentTopics, 0, 10) as $t): ?>
                    <tr>
                        <td><?= htmlspecialchars($t['topic']) ?></td>
                        <td><span class="badge <?= htmlspecialchars($t['status']) ?>"><?= htmlspecialchars($t['status']) ?></span></td>
                        <td style="white-space:nowrap;"><?= htmlspecialchars($t['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    
    <!-- SEO/GEO Scores Overview -->
    <div class="card">
        <h3>🔍 SEO & GEO Scores - Ultimi Articoli</h3>
        <?php
        require_once __DIR__ . '/src/SEOOptimizer.php';
        $optimizer = new SEOOptimizer();
        $recentItems = array_slice($feedItems, 0, 5);
        ?>
        <?php if (!empty($recentItems)): ?>
        <div style="display:flex;flex-direction:column;gap:12px;">
            <?php foreach ($recentItems as $item): 
                $analysis = $optimizer->analyzeArticle(
                    $item['title'],
                    $item['content'],
                    $item['meta_description'] ?? '',
                    strip_tags($item['title'])
                );
                $overallScore = $analysis['overall_score'];
                $scoreColor = $overallScore >= 90 ? '#4ade80' : ($overallScore >= 80 ? '#60a5fa' : ($overallScore >= 70 ? '#fbbf24' : '#f87171'));
                $scoreBg = $overallScore >= 90 ? 'rgba(74, 222, 128, 0.1)' : ($overallScore >= 80 ? 'rgba(96, 165, 250, 0.1)' : ($overallScore >= 70 ? 'rgba(251, 191, 36, 0.1)' : 'rgba(248, 113, 113, 0.1)'));
            ?>
            <div style="padding:15px;background:<?= $scoreBg ?>;border-radius:8px;border:1px solid #334155;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                    <h4 style="margin:0;font-size:14px;color:#f1f5f9;max-width:70%;"><?= htmlspecialchars(mb_substr($item['title'], 0, 50)) ?>...</h4>
                    <span style="font-size:20px;font-weight:700;color:<?= $scoreColor ?>;"><?= $overallScore ?>/100</span>
                </div>
                <div style="display:flex;gap:20px;font-size:12px;">
                    <span style="color:#94a3b8;">SEO: <strong style="color:#60a5fa;"><?= $analysis['seo_score'] ?></strong></span>
                    <span style="color:#94a3b8;">GEO: <strong style="color:#a78bfa;"><?= $analysis['geo_score'] ?></strong></span>
                    <span style="color:#94a3b8;">Leggibilità: <strong style="color:#4ade80;"><?= $analysis['readability_score'] ?></strong></span>
                    <span style="color:#94a3b8;">Tecnico: <strong style="color:#fbbf24;"><?= $analysis['technical_score'] ?></strong></span>
                </div>
                <?php if (!empty($analysis['suggestions'])): ?>
                <div style="margin-top:8px;font-size:11px;color:#64748b;">
                    💡 <?= htmlspecialchars($analysis['suggestions'][0]) ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <div style="margin-top:15px;padding:10px;background:#0f172a;border-radius:6px;font-size:12px;color:#64748b;">
            <strong>Target:</strong> SEO 90+ | GEO 85+ | Leggibilità 70+ | Tecnico 90+
            <a href="?tab=seo" style="color:#60a5fa;margin-left:15px;">Vedi dettagli →</a>
        </div>
        <?php else: ?>
            <p style="color: #64748b;">Nessun articolo da analizzare.</p>
        <?php endif; ?>
    </div>

<?php elseif ($tab === 'feed'): ?>
    <div class="header">
        <h2>Gestione Feed RSS</h2>
        <div>
            <?php if (file_exists($config['feed_path'])): ?>
                <a href="data/feed.xml" target="_blank" class="btn btn-primary btn-sm">Apri Feed XML</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="stats">
        <div class="stat-card">
            <div class="label">Item nel Feed</div>
            <div class="value blue"><?= $feedCount ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Max Item</div>
            <div class="value purple"><?= $config['max_feed_items'] ?></div>
        </div>
    </div>

    <div class="card">
        <h3>Contenuti del Feed</h3>
        <?php if (empty($feedItems)): ?>
            <p style="color: #64748b;">Il feed e' vuoto.</p>
        <?php else: ?>
            <form method="post" id="bulkDeleteForm" onsubmit="return confirmBulkDelete()">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="delete_feed_items_bulk">
                <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;padding:12px;background:#0f172a;border-radius:8px;border:1px solid #334155;">
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;color:#94a3b8;font-size:13px;">
                        <input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)" style="width:16px;height:16px;accent-color:#6366f1;">
                        Seleziona tutti
                    </label>
                    <span id="selectedCount" style="color:#64748b;font-size:13px;"></span>
                    <button type="submit" class="btn btn-danger btn-sm" id="bulkDeleteBtn" style="display:none;margin-left:auto;">
                        Elimina selezionati (<span id="deleteCount">0</span>)
                    </button>
                </div>

                <?php foreach ($feedItems as $idx => $item): ?>
                    <div class="feed-item" id="feed-item-<?= $idx ?>">
                        <div style="display:flex;align-items:flex-start;gap:10px;">
                            <input type="checkbox" name="selected_items[]" value="<?= $idx ?>" class="item-checkbox" onchange="updateBulkUI()" style="width:16px;height:16px;margin-top:3px;accent-color:#6366f1;flex-shrink:0;">
                            <div style="flex:1;min-width:0;">
                                <?php if (!empty($item['image'])): ?>
                                    <div style="margin-bottom:10px;">
                                        <img src="<?= htmlspecialchars($item['image']) ?>" alt="<?= htmlspecialchars($item['title']) ?>" style="max-width:100%;max-height:200px;border-radius:8px;object-fit:cover;">
                                    </div>
                                <?php endif; ?>
                                <h4 id="title-display-<?= $idx ?>"><?= htmlspecialchars($item['title']) ?></h4>
                                <div class="date">
                                    <?= htmlspecialchars($item['pubDate']) ?>
                                    <?php
                                        $wpUrl = $config['wp_site_url'] ?? '';
                                        $iLinks = countInternalLinks($item['content'], $wpUrl);
                                        $eLinks = countExternalLinks($item['content'], $wpUrl);
                                    ?>
                                    <?php if ($iLinks > 0): ?>
                                        <span class="badge links-yes" style="margin-left:8px;" title="<?= $iLinks ?> link interni, <?= $eLinks ?> esterni">🔗 <?= $iLinks ?> interni / <?= $eLinks ?> esterni</span>
                                    <?php else: ?>
                                        <span class="badge links-no" style="margin-left:8px;" title="Nessun link building applicato">Nessun link</span>
                                    <?php endif; ?>
                                    <?php if (!empty($item['wp_post_id'])): ?>
                                        <span class="badge completed" style="margin-left:4px;">WP #<?= $item['wp_post_id'] ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="content-preview" id="preview-<?= $idx ?>"><?= mb_substr(strip_tags($item['content']), 0, 200) ?>...</div>
                                <div class="actions" style="display:flex;gap:8px;flex-wrap:wrap;">
                                    <button type="button" class="btn btn-primary btn-sm" onclick="toggleContent(<?= $idx ?>)">Mostra/Nascondi</button>
                                    <button type="button" class="btn btn-sm" style="background:#d97706;color:white;" onclick="openEditor(<?= $idx ?>)">Modifica</button>
                                    <?php if (!empty($config['wp_enabled'])): ?>
                                        <?php if (!empty($item['wp_post_id'])): ?>
                                        <span class="btn btn-sm" style="background:#065f46;color:#4ade80;cursor:default;opacity:0.9;" id="wp-btn-<?= $idx ?>">Pubblicato su WP</span>
                                        <a href="<?= htmlspecialchars($item['wp_post_url']) ?>" target="_blank" style="color:#4ade80;font-size:12px;text-decoration:underline;">Vedi post</a>
                                        <?php else: ?>
                                        <button type="button" class="btn btn-sm" style="background:#059669;color:white;" onclick="publishToWP(<?= $idx ?>, this)" id="wp-btn-<?= $idx ?>">Pubblica su WP</button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                                <div class="content-full" id="content-<?= $idx ?>"><?= $item['content'] ?></div>

                                <!-- Editor inline -->
                                <div class="edit-panel" id="editor-<?= $idx ?>" style="display:none;margin-top:12px;padding:16px;background:#0f172a;border:1px solid #334155;border-radius:8px;">
                                    <div style="margin-bottom:10px;">
                                        <label style="display:block;font-size:12px;color:#94a3b8;margin-bottom:4px;">Titolo</label>
                                        <input type="text" id="edit-title-<?= $idx ?>" value="<?= htmlspecialchars($item['title']) ?>" style="width:100%;padding:8px 12px;background:#1e293b;border:1px solid #475569;border-radius:6px;color:#f1f5f9;font-size:14px;">
                                    </div>
                                    <div style="margin-bottom:10px;">
                                        <label style="display:block;font-size:12px;color:#94a3b8;margin-bottom:4px;">Contenuto (HTML)</label>
                                        <textarea id="edit-content-<?= $idx ?>" rows="18" style="width:100%;padding:8px 12px;background:#1e293b;border:1px solid #475569;border-radius:6px;color:#f1f5f9;font-family:'Fira Code',Consolas,monospace;font-size:12px;line-height:1.5;resize:vertical;"><?= htmlspecialchars($item['content']) ?></textarea>
                                    </div>
                                    <div style="display:flex;gap:8px;">
                                        <button type="button" class="btn btn-sm" style="background:#059669;color:white;" onclick="saveEdit(<?= $idx ?>)">Salva</button>
                                        <button type="button" class="btn btn-sm" style="background:#475569;color:white;" onclick="closeEditor(<?= $idx ?>)">Annulla</button>
                                        <span id="edit-status-<?= $idx ?>" style="font-size:12px;line-height:28px;margin-left:8px;"></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </form>
        <?php endif; ?>
    </div>

<?php elseif ($tab === 'topics'): ?>
    <div class="header">
        <h2>Topic Elaborati</h2>
        <form method="post" onsubmit="return confirm('Cancellare tutto lo storico? I topic potranno essere rielaborati.')">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" value="clear_history">
            <button type="submit" class="btn btn-danger btn-sm">Cancella Storico</button>
        </form>
    </div>

    <div class="card">
        <?php if (empty($recentTopics)): ?>
            <p style="color: #64748b;">Nessun topic nello storico.</p>
        <?php else: ?>
            <table>
                <thead><tr><th>Topic</th><th>Stato</th><th>Data Creazione</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($recentTopics as $t): ?>
                    <tr>
                        <td><?= htmlspecialchars($t['topic']) ?></td>
                        <td><span class="badge <?= htmlspecialchars($t['status']) ?>"><?= htmlspecialchars($t['status']) ?></span></td>
                        <td><?= htmlspecialchars($t['created_at']) ?></td>
                        <td>
                            <form method="post" style="margin:0;" onsubmit="return confirm('Eliminare questo topic?')">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="action" value="delete_topic">
                                <input type="hidden" name="topic_id" value="<?= intval($t['id']) ?>">
                                <button type="submit" class="btn btn-danger btn-sm" title="Elimina">✕</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

<?php elseif ($tab === 'config'): ?>
    <div class="header">
        <h2>Configurazione</h2>
    </div>

    <style>
        .cfg-tabs { display:flex; gap:6px; margin-bottom:24px; flex-wrap:wrap; }
        .cfg-tab { padding:8px 18px; border-radius:8px; border:1px solid #334155; background:#1e293b; color:#94a3b8; font-size:14px; font-weight:500; cursor:pointer; transition:all .15s; }
        .cfg-tab:hover { border-color:#818cf8; color:#c7d2fe; }
        .cfg-tab.active { background:#4f46e5; border-color:#4f46e5; color:#fff; }
        .cfg-panel { display:none; }
        .cfg-panel.active { display:block; }
    </style>

    <div class="cfg-tabs">
        <button type="button" class="cfg-tab active" onclick="showCfgTab('ai')">🤖 AI &amp; Provider</button>
        <button type="button" class="cfg-tab" onclick="showCfgTab('contenuto')">📝 Contenuto</button>
        <button type="button" class="cfg-tab" onclick="showCfgTab('immagini')">🖼️ Immagini</button>
        <button type="button" class="cfg-tab" onclick="showCfgTab('pubblicazione')">🚀 Pubblicazione &amp; SEO</button>
        <button type="button" class="cfg-tab" onclick="showCfgTab('prompt')">💬 Prompt AI</button>
    </div>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <input type="hidden" name="action" value="save_config">

        <!-- TAB: AI & Provider -->
        <div class="cfg-panel active" id="cfg-ai">

        <div class="card">
            <h3>API Keys</h3>
            <p style="font-size:12px; color:#64748b; margin-bottom:12px;">Le chiavi sono salvate nel file .env protetto. Inserisci una nuova chiave per aggiornarla, oppure lascia vuoto per mantenere quella attuale.</p>
            <div class="form-row">
                <div class="form-group">
                    <label>OpenAI API Key (primario) - Attuale: <?= maskKey($config['openai_api_key']) ?></label>
                    <input type="password" name="openai_api_key" value="" placeholder="Inserisci nuova chiave per aggiornare...">
                </div>
                <div class="form-group">
                    <label>Gemini API Key (fallback) - Attuale: <?= maskKey($config['gemini_api_key']) ?></label>
                    <input type="password" name="gemini_api_key" value="" placeholder="Inserisci nuova chiave per aggiornare...">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>OpenRouter API Key (fallback) - Attuale: <?= maskKey($config['openrouter_api_key']) ?></label>
                    <input type="password" name="openrouter_api_key" value="" placeholder="Inserisci nuova chiave per aggiornare...">
                    <div class="hint">Ottienila da <strong>openrouter.ai/keys</strong></div>
                </div>
                <div class="form-group">
                    <label>fal.ai API Key (immagini) - Attuale: <?= maskKey($config['fal_api_key']) ?></label>
                    <input type="password" name="fal_api_key" value="" placeholder="Inserisci nuova chiave per aggiornare...">
                    <div class="hint">Ottienila da <strong>fal.ai/dashboard/keys</strong></div>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Cron Token (sicurezza cron URL) - Attuale: <?= maskKey(EnvLoader::get('CRON_TOKEN')) ?></label>
                    <input type="password" name="cron_token" value="" placeholder="Inserisci token per aggiornare...">
                    <div class="hint">Token segreto per chiamare <strong>cron.php?token=...</strong> via URL. Usalo per hosting che supportano cron via HTTP (es. cPanel, Plesk).</div>
                </div>
            </div>

            <div class="form-group" style="margin-bottom:20px;">
                <label>Provider predefinito per generazione articoli</label>
                <select name="default_provider" style="max-width:400px;">
                    <?php $currentProvider = $config['default_provider'] ?? 'openai'; ?>
                    <option value="openai" <?= $currentProvider === 'openai' ? 'selected' : '' ?>>OpenAI (GPT)</option>
                    <option value="gemini" <?= $currentProvider === 'gemini' ? 'selected' : '' ?>>Google Gemini</option>
                    <option value="openrouter" <?= $currentProvider === 'openrouter' ? 'selected' : '' ?>>OpenRouter</option>
                </select>
                <div class="hint">Il provider scelto viene usato per primo. Gli altri fungono da fallback automatico in caso di errore.</div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Modello OpenAI</label>
                    <input type="text" name="openai_model" value="<?= htmlspecialchars($config['openai_model']) ?>">
                </div>
                <div class="form-group">
                    <label>Modello Gemini</label>
                    <input type="text" name="gemini_model" value="<?= htmlspecialchars($config['gemini_model']) ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Modello OpenRouter</label>
                    <input type="text" name="openrouter_model" value="<?= htmlspecialchars($config['openrouter_model'] ?? 'openai/gpt-4o-mini') ?>" placeholder="es: openai/gpt-4o-mini, xiaomi/mimo-v2-omni, minimax/minimax-m2.7">
                    <div class="hint">Inserisci il nome completo del modello. Esempi: openai/gpt-4o-mini, anthropic/claude-3.5-sonnet, xiaomi/mimo-v2-omni, minimax/minimax-m2.7, google/gemini-2.0-flash. <a href="https://openrouter.ai/models" target="_blank" style="color:#818cf8;">Vedi tutti i modelli disponibili</a></div>
                </div>
                <div class="form-group"></div>
            </div>
        </div>

        <button type="submit" class="btn btn-primary">Salva Configurazione</button>
        </div><!-- /cfg-ai -->

        <!-- TAB: Contenuto -->
        <div class="cfg-panel" id="cfg-contenuto">

        <div class="card">
            <h3>Nicchia / Argomento</h3>
            <p style="font-size:12px; color:#64748b; margin-bottom:12px;">Definisci l'argomento principale del sistema. Viene usato per filtrare automaticamente i topic non pertinenti e come contesto per la generazione.</p>
            <div class="form-row">
                <div class="form-group">
                    <label>Nome Nicchia</label>
                    <input type="text" name="niche_name" value="<?= htmlspecialchars($config['niche_name'] ?? 'Sogni e Dormire') ?>" placeholder="Es: Sogni e Dormire, Cucina Italiana, Fitness...">
                </div>
            </div>
            <div class="form-group">
                <label>Descrizione Nicchia (usata dall'AI per verificare la pertinenza dei topic)</label>
                <textarea name="niche_description" rows="3" placeholder="Elenca i temi pertinenti separati da virgola..."><?= htmlspecialchars($config['niche_description'] ?? '') ?></textarea>
                <div class="hint">Elenca i temi separati da virgola. Es: "sogni, interpretazione dei sogni, dormire, qualità del sonno, smorfia napoletana"</div>
            </div>
        </div>

        <div class="card">
            <h3>Sorgente Keyword</h3>
            <div class="form-group">
                <label>Modalita di recupero keyword</label>
                <select name="keyword_source" id="keyword_source" onchange="toggleKeywordSource()">
                    <option value="google" <?= ($config['keyword_source'] ?? 'google') === 'google' ? 'selected' : '' ?>>Google Autocomplete (semi di ricerca)</option>
                    <option value="manual" <?= ($config['keyword_source'] ?? 'google') === 'manual' ? 'selected' : '' ?>>Keyword manuali (lista personalizzata)</option>
                </select>
                <div class="hint">Google Autocomplete genera keyword automaticamente dai semi. Le keyword manuali ti permettono di specificare esattamente gli argomenti da trattare.</div>
            </div>

            <div id="google_keywords_section" style="<?= ($config['keyword_source'] ?? 'google') === 'manual' ? 'display:none' : '' ?>">
                <div class="form-group">
                    <label>Semi di ricerca (un seme per riga, usati come prefisso per Google Autocomplete)</label>
                    <textarea name="semi_ricerca" rows="12"><?= htmlspecialchars(implode("\n", $config['semi_ricerca'])) ?></textarea>
                    <div class="hint">Es: "sognare di ", "significato sogno ", "cosa significa sognare "</div>
                </div>
            </div>

            <div id="manual_keywords_section" style="<?= ($config['keyword_source'] ?? 'google') !== 'manual' ? 'display:none' : '' ?>">
                <div class="form-group">
                    <label>Keyword personalizzate (una per riga, verranno usate direttamente come topic per gli articoli)</label>
                    <textarea name="manual_keywords" rows="12"><?= htmlspecialchars(implode("\n", $config['manual_keywords'] ?? [])) ?></textarea>
                    <div class="hint">Es: "significato sognare di volare", "come curare l'insonnia cronica", "smorfia napoletana numero 33"</div>
                </div>
            </div>
        </div>

        <div class="card">
            <h3>Parametri Generazione</h3>
            <div class="form-row">
                <div class="form-group">
                    <label>Max articoli per esecuzione</label>
                    <input type="number" name="max_articles_per_run" value="<?= $config['max_articles_per_run'] ?>" min="1" max="20">
                </div>
                <div class="form-group">
                    <label>Max item nel feed RSS</label>
                    <input type="number" name="max_feed_items" value="<?= $config['max_feed_items'] ?>" min="10" max="500">
                </div>
            </div>
        </div>

        <button type="submit" class="btn btn-primary">Salva Configurazione</button>
        </div><!-- /cfg-contenuto -->

        <!-- TAB: Immagini -->
        <div class="cfg-panel" id="cfg-immagini">

        <div class="card">
            <h3>Generazione Immagini (fal.ai)</h3>
            <p style="font-size:12px; color:#64748b; margin-bottom:12px;">Genera automaticamente immagini AI per ogni articolo tramite le API di fal.ai. Richiede una API Key valida.</p>

            <div class="form-row">
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="fal_enabled" value="1" <?= !empty($config['fal_enabled']) ? 'checked' : '' ?> style="width:auto;margin-right:6px;">
                        Abilita immagine featured
                    </label>
                    <div class="hint">Genera un'immagine di copertina per ogni articolo</div>
                </div>
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="fal_inline_enabled" value="1" <?= !empty($config['fal_inline_enabled']) ? 'checked' : '' ?> style="width:auto;margin-right:6px;">
                        Abilita immagini inline
                    </label>
                    <div class="hint">Inserisce immagini nel corpo dell'articolo ogni N sezioni H2</div>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Modello fal.ai</label>
                    <select name="fal_model_id">
                        <?php
                        $falModels = [
                            'fal-ai/flux/schnell'          => 'Flux Schnell (~$0.003/img)',
                            'fal-ai/flux/dev'              => 'Flux Dev (~$0.025/img)',
                            'fal-ai/flux-pro'              => 'Flux Pro (~$0.05/img)',
                            'fal-ai/flux-2-pro'            => 'Flux 2 Pro (~$0.03/img)',
                            'fal-ai/gpt-image-1.5'         => 'GPT Image 1.5 - OpenAI ($0.011-$0.19/img)',
                            'fal-ai/gpt-image-1/text-to-image' => 'GPT Image 1 - OpenAI ($0.011-$0.25/img)',
                            'fal-ai/gpt-image-1-mini'      => 'GPT Image 1 Mini - OpenAI',
                            'xai/grok-imagine-image'       => 'Grok Imagine - xAI Aurora',
                        ];
                        $currentModel = $config['fal_model_id'] ?? 'fal-ai/flux/schnell';
                        foreach ($falModels as $modelVal => $modelLabel): ?>
                            <option value="<?= htmlspecialchars($modelVal) ?>" <?= $currentModel === $modelVal ? 'selected' : '' ?>><?= htmlspecialchars($modelLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Formato output</label>
                    <select name="fal_output_format">
                        <?php $currentFormat = $config['fal_output_format'] ?? 'jpeg'; ?>
                        <option value="jpeg" <?= $currentFormat === 'jpeg' ? 'selected' : '' ?>>JPEG</option>
                        <option value="png" <?= $currentFormat === 'png' ? 'selected' : '' ?>>PNG</option>
                        <option value="webp" <?= $currentFormat === 'webp' ? 'selected' : '' ?>>WebP</option>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Dimensione immagine featured</label>
                    <select name="fal_image_size" id="fal_image_size">
                        <?php
                        $currentModel = $config['fal_model_id'] ?? 'fal-ai/flux/schnell';
                        $isGPTImage = str_contains($currentModel, 'gpt-image');
                        
                        if ($isGPTImage):
                            // Opzioni GPT Image
                            $gptSizes = ImageGenerator::getGPTImageSizes();
                            $currentSize = $config['fal_image_size'] ?? '1024x1024';
                            foreach ($gptSizes as $sizeVal => $sizeLabel): ?>
                                <option value="<?= htmlspecialchars($sizeVal) ?>" <?= $currentSize === $sizeVal ? 'selected' : '' ?>><?= htmlspecialchars($sizeLabel) ?></option>
                            <?php endforeach;
                        else:
                            // Opzioni Flux
                            $fluxSizes = ImageGenerator::getFluxSizes();
                            $currentSize = $config['fal_image_size'] ?? 'landscape_16_9';
                            foreach ($fluxSizes as $sizeVal => $sizeLabel): ?>
                                <option value="<?= htmlspecialchars($sizeVal) ?>" <?= $currentSize === $sizeVal ? 'selected' : '' ?>><?= htmlspecialchars($sizeLabel) ?></option>
                            <?php endforeach;
                        endif; ?>
                    </select>
                    <div class="hint" id="size_hint">
                        <?php if ($isGPTImage): ?>
                            1024x1024 = Square (più economico), 1536x1024 = Landscape, 1024x1536 = Portrait
                        <?php else: ?>
                            Seleziona il formato adatto al tuo contenuto
                        <?php endif; ?>
                    </div>
                </div>
                <div class="form-group">
                    <label>Qualita'</label>
                    <select name="fal_quality">
                        <?php $currentQuality = $config['fal_quality'] ?? ''; ?>
                        <option value="" <?= $currentQuality === '' ? 'selected' : '' ?>>Default (nessuna)</option>
                        <option value="low" <?= $currentQuality === 'low' ? 'selected' : '' ?>>Low</option>
                        <option value="medium" <?= $currentQuality === 'medium' ? 'selected' : '' ?>>Medium</option>
                        <option value="high" <?= $currentQuality === 'high' ? 'selected' : '' ?>>High</option>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Dimensione immagini inline</label>
                    <select name="fal_inline_size" id="fal_inline_size">
                        <?php
                        // Usa la stessa logica del modello selezionato
                        $isGPTImageInline = str_contains($currentModel, 'gpt-image');
                        
                        if ($isGPTImageInline):
                            // Opzioni GPT Image
                            $gptSizesInline = ImageGenerator::getGPTImageSizes();
                            $currentInlineSize = $config['fal_inline_size'] ?? '1024x1024';
                            foreach ($gptSizesInline as $sizeVal => $sizeLabel): ?>
                                <option value="<?= htmlspecialchars($sizeVal) ?>" <?= $currentInlineSize === $sizeVal ? 'selected' : '' ?>><?= htmlspecialchars($sizeLabel) ?></option>
                            <?php endforeach;
                        else:
                            // Opzioni Flux
                            $fluxSizesInline = ImageGenerator::getFluxSizes();
                            $currentInlineSize = $config['fal_inline_size'] ?? 'landscape_16_9';
                            foreach ($fluxSizesInline as $sizeVal => $sizeLabel): ?>
                                <option value="<?= htmlspecialchars($sizeVal) ?>" <?= $currentInlineSize === $sizeVal ? 'selected' : '' ?>><?= htmlspecialchars($sizeLabel) ?></option>
                            <?php endforeach;
                        endif; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Inserisci immagine inline ogni N sezioni H2</label>
                    <input type="number" name="fal_inline_interval" value="<?= $config['fal_inline_interval'] ?? 3 ?>" min="1" max="10">
                </div>
            </div>

            <div class="form-group">
                <label>Prompt immagine featured. Usa <code style="color:#818cf8;">[title]</code> e <code style="color:#818cf8;">[keyword]</code> come segnaposto.</label>
                <textarea name="fal_prompt_template" rows="4" style="font-size:12px; line-height:1.5;"><?= htmlspecialchars($config['fal_prompt_template'] ?? ImageGenerator::defaultPrompt()) ?></textarea>
            </div>

            <div class="form-group">
                <label>Prompt immagini inline. Usa <code style="color:#818cf8;">[context]</code>, <code style="color:#818cf8;">[title]</code> e <code style="color:#818cf8;">[keyword]</code>.</label>
                <textarea name="fal_inline_prompt_template" rows="4" style="font-size:12px; line-height:1.5;"><?= htmlspecialchars($config['fal_inline_prompt_template'] ?? ImageGenerator::defaultInlinePrompt()) ?></textarea>
            </div>
        </div>

        <button type="submit" class="btn btn-primary">Salva Configurazione</button>
        </div><!-- /cfg-immagini -->

        <!-- TAB: Pubblicazione & SEO -->
        <div class="cfg-panel" id="cfg-pubblicazione">

        <div class="card">
            <h3>Feed Social Media (Auto-posting)</h3>
            <p style="font-size:12px; color:#64748b; margin-bottom:12px;">Genera feed RSS ottimizzati per Facebook e X/Twitter con copy generato dall'AI.</p>
            
            <div class="form-row">
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="social_feeds_enabled" value="1" <?= !empty($config['social_feeds_enabled']) ? 'checked' : '' ?> style="width:auto;margin-right:6px;">
                        Abilita feed social
                    </label>
                    <div class="hint">Crea feed separati per Facebook e X/Twitter con copy ottimizzato</div>
                </div>
            </div>
            
            <div class="form-group">
                <label>URL del sito (WordPress)</label>
                <input type="text" name="social_site_url" value="<?= htmlspecialchars($config['social_site_url'] ?? $config['feed_link'] ?? 'https://example.com') ?>" placeholder="https://tuo-sito.com">
                <div class="hint">URL base del tuo sito WordPress dove verranno pubblicati gli articoli</div>
            </div>
            
            <div class="form-group">
                <label>Struttura Permalink</label>
                <select name="social_permalink_structure">
                    <?php 
                    $currentStructure = $config['social_permalink_structure'] ?? '/%year%/%monthnum%/%day%/%postname%/';
                    $structures = [
                        '/%year%/%monthnum%/%day%/%postname%/' => 'Giorno e nome (WordPress default) - /2026/03/19/titolo-articolo/',
                        '/%year%/%monthnum%/%postname%/'       => 'Mese e nome - /2026/03/titolo-articolo/',
                        '/%postname%/'                         => 'Nome articolo - /titolo-articolo/',
                        '/archives/%post_id%'                  => 'Numerico - /archives/123/',
                    ];
                    foreach ($structures as $value => $label): 
                    ?>
                        <option value="<?= htmlspecialchars($value) ?>" <?= $currentStructure === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="hint">Seleziona la stessa struttura permalink usata in WordPress (Impostazioni → Permalink)</div>
            </div>
            
            <div class="form-group">
                <label>Prompt per Facebook Copy (max 400 caratteri)</label>
                <textarea name="facebook_prompt" rows="8" style="font-size:12px; line-height:1.5;"><?= htmlspecialchars($config['facebook_prompt'] ?? SocialFeedBuilder::defaultFacebookPrompt()) ?></textarea>
                <div class="hint">Usa <code>[title]</code> per il titolo dell'articolo. Il copy sarà generato dall'AI.</div>
            </div>
            
            <div class="form-group">
                <label>Prompt per X/Twitter Copy (max 250 caratteri)</label>
                <textarea name="twitter_prompt" rows="8" style="font-size:12px; line-height:1.5;"><?= htmlspecialchars($config['twitter_prompt'] ?? SocialFeedBuilder::defaultTwitterPrompt()) ?></textarea>
                <div class="hint">Usa <code>[title]</code> per il titolo dell'articolo. Il copy sarà generato dall'AI.</div>
            </div>
            
            <?php if (!empty($config['social_feeds_enabled'])): ?>
            <div style="margin-top:15px;padding:10px;background:#0f172a;border-radius:8px;">
                <strong style="color:#818cf8;">Feed disponibili:</strong><br>
                <a href="data/feed-facebook.xml" target="_blank" style="color:#60a5fa;">📘 Feed Facebook</a><br>
                <a href="data/feed-twitter.xml" target="_blank" style="color:#60a5fa;">🐦 Feed X/Twitter</a>
            </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <h3>Pubblicazione WordPress (REST API)</h3>
            <p style="font-size:12px; color:#64748b; margin-bottom:12px;">Pubblica automaticamente o manualmente gli articoli su WordPress tramite REST API. Richiede <strong>Application Passwords</strong> (WordPress 5.6+).</p>

            <div class="form-row">
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="wp_enabled" value="1" <?= !empty($config['wp_enabled']) ? 'checked' : '' ?> style="width:auto;margin-right:6px;">
                        Abilita pubblicazione WordPress
                    </label>
                    <div class="hint">Attiva la possibilita' di pubblicare articoli su WordPress</div>
                </div>
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="wp_auto_publish" value="1" <?= !empty($config['wp_auto_publish']) ? 'checked' : '' ?> style="width:auto;margin-right:6px;">
                        Pubblicazione automatica
                    </label>
                    <div class="hint">Pubblica automaticamente ogni articolo appena generato (durante esecuzione cron o manuale)</div>
                </div>
            </div>

            <div class="form-group">
                <label>URL Sito WordPress</label>
                <input type="text" name="wp_site_url" value="<?= htmlspecialchars($config['wp_site_url'] ?? '') ?>" placeholder="https://tuo-sito.com">
                <div class="hint">URL base del sito WordPress (senza slash finale). Es: https://www.smorfeo.it</div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Username WordPress</label>
                    <input type="text" name="wp_username" value="<?= htmlspecialchars($config['wp_username'] ?? '') ?>" placeholder="admin">
                    <div class="hint">L'utente WordPress con permessi di pubblicazione</div>
                </div>
                <div class="form-group">
                    <label>Application Password - Attuale: <?= maskKey($config['wp_app_password'] ?? '') ?></label>
                    <input type="password" name="wp_app_password" value="" placeholder="Inserisci nuova password per aggiornare...">
                    <div class="hint">Generala da WordPress: Utenti → Profilo → Application Passwords</div>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Stato Post</label>
                    <select name="wp_post_status">
                        <?php
                        $wpStatus = $config['wp_post_status'] ?? 'draft';
                        $statuses = ['draft' => 'Bozza (draft)', 'publish' => 'Pubblicato (publish)', 'pending' => 'In attesa di revisione (pending)'];
                        foreach ($statuses as $val => $label): ?>
                            <option value="<?= $val ?>" <?= $wpStatus === $val ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="hint">Stato con cui vengono creati i post su WordPress</div>
                </div>
                <div class="form-group">
                    <label>Categoria di fallback (opzionale)</label>
                    <input type="text" name="wp_category" value="<?= htmlspecialchars($config['wp_category'] ?? '') ?>" placeholder="Lascia vuoto per auto-detect AI" id="wpCategoryInput">
                    <div class="hint">Il sistema sceglie automaticamente la categoria piu' pertinente tramite AI, analizzando titolo e argomento dell'articolo. Se specifichi un valore qui, verra' usato solo come fallback quando l'AI non e' disponibile.</div>
                </div>
            </div>

            <div style="margin-top:15px;display:flex;gap:10px;align-items:center;">
                <button type="button" class="btn btn-primary btn-sm" onclick="testWpConnection()">Testa Connessione</button>
                <span id="wpTestResult" style="font-size:13px;"></span>
            </div>
        </div>

        <div class="card">
            <h3>Link Building (SEO)</h3>
            <p style="font-size:12px; color:#64748b; margin-bottom:12px;">Inserisce automaticamente link interni verso altri articoli del sito e opzionalmente link esterni verso fonti autorevoli nei nuovi articoli generati. Richiede WordPress abilitato.</p>

            <div class="form-row">
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="link_internal_enabled" value="1" <?= !empty($config['link_internal_enabled']) ? 'checked' : '' ?> style="width:auto;margin-right:6px;">
                        Abilita link interni
                    </label>
                    <div class="hint">Inserisce link verso altri articoli del sito WordPress nei nuovi contenuti generati</div>
                </div>
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="link_external_enabled" value="1" <?= !empty($config['link_external_enabled']) ? 'checked' : '' ?> style="width:auto;margin-right:6px;">
                        Abilita link esterni
                    </label>
                    <div class="hint">Permette all'AI di inserire link verso fonti autorevoli esterne</div>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Max link interni per articolo</label>
                    <input type="number" name="link_max_internal" value="<?= $config['link_max_internal'] ?? 5 ?>" min="1" max="10">
                </div>
                <div class="form-group">
                    <label>Max link esterni per articolo</label>
                    <input type="number" name="link_max_external" value="<?= $config['link_max_external'] ?? 2 ?>" min="0" max="5">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Durata cache lista articoli</label>
                    <select name="link_cache_ttl">
                        <?php $currentTtl = $config['link_cache_ttl'] ?? 21600;
                        $ttlOptions = [3600 => '1 ora', 10800 => '3 ore', 21600 => '6 ore', 43200 => '12 ore', 86400 => '24 ore'];
                        foreach ($ttlOptions as $val => $label): ?>
                            <option value="<?= $val ?>" <?= $currentTtl == $val ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="hint">La lista dei post WordPress viene cachata localmente per evitare chiamate API ripetute</div>
                </div>
                <div class="form-group">
                    <?php
                    require_once __DIR__ . '/src/LinkBuilder.php';
                    require_once __DIR__ . '/src/SmartLinkBuilder.php';
                    $lbInfo = (new SmartLinkBuilder($config))->getCacheInfo();
                    ?>
                    <label>Stato cache</label>
                    <div style="padding:10px;background:#0f172a;border:1px solid #334155;border-radius:8px;font-size:13px;">
                        <span id="linkCacheCount"><?= $lbInfo['count'] ?></span> articoli in cache
                        <?php if ($lbInfo['fetched_at']): ?>
                            <br><span style="color:#64748b;font-size:11px;">Ultimo aggiornamento: <?= date('d/m/Y H:i', $lbInfo['fetched_at']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div style="margin-top:15px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                <button type="button" class="btn btn-primary btn-sm" onclick="refreshLinkCache()">Aggiorna cache articoli</button>
                <button type="button" class="btn btn-sm" style="background:#d97706;color:white;" onclick="relinkBulk()">Applica link building ai vecchi articoli</button>
                <span id="linkCacheResult" style="font-size:13px;"></span>
            </div>
        </div>

        <div class="card">
            <h3>Feed RSS</h3>
            <div class="form-row">
                <div class="form-group">
                    <label>Titolo Feed</label>
                    <input type="text" name="feed_title" value="<?= htmlspecialchars($config['feed_title']) ?>">
                </div>
                <div class="form-group">
                    <label>Link Feed</label>
                    <input type="text" name="feed_link" value="<?= htmlspecialchars($config['feed_link']) ?>">
                </div>
            </div>
        </div>

        <button type="submit" class="btn btn-primary">Salva Configurazione</button>
        </div><!-- /cfg-pubblicazione -->

        <!-- TAB: Prompt AI -->
        <div class="cfg-panel" id="cfg-prompt">

        <div class="card">
            <h3>Prompt Generazione Titolo (SEO / GEO / Google Discover)</h3>
            <p style="font-size:12px; color:#64748b; margin-bottom:12px;">Il titolo viene <strong>sempre</strong> rigenerato con una chiamata AI dedicata dopo la creazione dell'articolo, ottimizzato per SEO, GEO e Google Discover. Personalizza il prompt qui sotto oppure lascia vuoto per usare quello di default.</p>
            <div class="form-group">
                <label>Prompt per il titolo. Usa <code style="color:#818cf8;">[keyword]</code> come segnaposto. L'AI deve rispondere SOLO con il testo del titolo.</label>
                <textarea name="title_prompt_template" rows="10" style="font-size:12px; line-height:1.5;" placeholder="Lascia vuoto per usare il prompt di default (ottimizzato SEO/GEO/Discover)..."><?= htmlspecialchars($config['title_prompt_template'] ?? '') ?></textarea>
                <div class="hint">Se vuoto, il sistema usa un prompt interno ottimizzato per SEO (keyword prominente, 50-60 char), GEO (chiarezza per AI generativa), e Google Discover (curiosita' + autorita'). <button type="button" class="btn btn-sm" style="padding:2px 8px;font-size:11px;margin-left:8px;" onclick="document.querySelector('textarea[name=title_prompt_template]').value = <?= htmlspecialchars(json_encode(ContentGenerator::defaultTitlePrompt()), ENT_QUOTES) ?>">Carica prompt di default</button></div>
            </div>
        </div>

        <div class="card">
            <h3>Prompt di Generazione Articolo</h3>
            <div class="form-group">
                <label>Template del prompt inviato all'AI. Usa <code style="color:#818cf8;">[keyword]</code> come segnaposto per il topic trovato.</label>
                <textarea name="prompt_template" rows="20" style="font-size:12px; line-height:1.5;"><?= htmlspecialchars($config['prompt_template'] ?? ContentGenerator::defaultPrompt()) ?></textarea>
                <div class="hint">Ogni occorrenza di [keyword] verra' sostituita con il topic di ricerca trovato da Google Autocomplete.</div>
            </div>
        </div>

        <button type="submit" class="btn btn-primary">Salva Configurazione</button>
        </div><!-- /cfg-prompt -->

    </form>

<?php elseif ($tab === 'logs'): ?>
    <div class="header">
        <h2>Log Esecuzioni</h2>
        <div style="display:flex;gap:10px;">
            <form method="post" onsubmit="return confirm('Sei sicuro di voler svuotare il log? Questa azione non può essere annullata.')">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="clear_log">
                <button type="submit" class="btn btn-danger btn-sm">🗑️ Svuota Log</button>
            </form>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="download_log">
                <button type="submit" class="btn btn-primary btn-sm">📥 Scarica Log</button>
            </form>
        </div>
    </div>

    <div class="card">
        <h3>Ultime 100 righe del log</h3>
        <?php
        // Ricarica il contenuto del log dopo eventuali modifiche
        $logContent = '';
        $logLines = [];
        $logError = '';
        $logExists = file_exists($config['log_path']);
        $logSize = 0;
        
        if ($logExists) {
            $logSize = @filesize($config['log_path']);
            if ($logSize === false) {
                $logSize = 0;
            }
            
            if ($logSize > 0) {
                $logLines = @file($config['log_path'], FILE_IGNORE_NEW_LINES);
                if ($logLines === false) {
                    $logError = 'Errore durante la lettura del file log. Verifica i permessi.';
                    $logLines = [];
                } else {
                    $logContent = implode("\n", array_slice($logLines, -100));
                }
            } else {
                $logError = 'Il file log è vuoto. Verrà ricreato automaticamente alla prossima esecuzione.';
            }
        } else {
            $logError = 'File log non trovato: ' . $config['log_path'] . '. Verrà creato automaticamente alla prossima esecuzione.';
        }
        
        // Formatta dimensione
        $logSizeFormatted = $logSize < 1024 ? $logSize . ' B' : ($logSize < 1048576 ? round($logSize / 1024, 2) . ' KB' : round($logSize / 1048576, 2) . ' MB');
        $totalLines = count($logLines);
        ?>
        <div style="margin-bottom:15px;padding:10px;background:#0f172a;border-radius:8px;font-size:12px;color:#94a3b8;">
            <strong>File:</strong> <?= htmlspecialchars($config['log_path']) ?> | 
            <strong>Dimensione:</strong> <?= $logSizeFormatted ?> | 
            <strong>Righe totali:</strong> <?= $totalLines ?>
        </div>
        <?php if ($logError): ?>
            <div style="margin-bottom:15px;padding:10px;background:<?= $logExists ? '#1e3a5f' : '#7f1d1d' ?>;border-radius:8px;font-size:13px;color:<?= $logExists ? '#93c5fd' : '#fca5a5' ?>;">
                <strong>ℹ️ <?= htmlspecialchars($logError) ?></strong>
            </div>
        <?php endif; ?>
        <div class="log-output"><?= htmlspecialchars($logContent ?: 'Nessun log disponibile.') ?></div>
    </div>

<?php elseif ($tab === 'linkbuilding'): ?>
    <?php
    require_once __DIR__ . '/src/LinkBuilder.php';
    require_once __DIR__ . '/src/SmartLinkBuilder.php';
    $lb = new SmartLinkBuilder($config);
    $lbEnabled = $lb->isEnabled();
    $wpArticles = [];
    $wpArticlesError = '';

    if ($lbEnabled) {
        $cacheInfo = $lb->getCacheInfo();
        if ($cacheInfo['count'] === 0) {
            $lb->refreshCache();
            $cacheInfo = $lb->getCacheInfo();
        }
        // Carica articoli dalla cache
        $cachePath = ($config['base_dir'] ?? __DIR__) . '/data/cache_wp_posts.json';
        if (file_exists($cachePath)) {
            $cacheData = json_decode(file_get_contents($cachePath), true);
            $wpArticles = $cacheData['posts'] ?? [];
        }
        if (empty($wpArticles)) {
            $wpArticlesError = 'Nessun articolo trovato nella cache. Clicca "Aggiorna Cache" per caricare gli articoli da WordPress.';
        }
    } else {
        $wpArticlesError = 'Link Building non abilitato. Abilita i link interni nella tab Configurazione e assicurati che WordPress sia configurato.';
    }

    // Conta link interni per ogni articolo
    $wpUrl = rtrim($config['wp_site_url'] ?? '', '/');
    $siteHost = parse_url($wpUrl, PHP_URL_HOST);
    ?>
    <div class="header">
        <h2>Link Building</h2>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <?php if ($lbEnabled): ?>
                <button type="button" class="btn btn-primary btn-sm" onclick="lbRefreshCache()">Aggiorna Cache</button>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($lbEnabled): ?>
    <div class="stats">
        <div class="stat-card">
            <div class="label">Articoli in Cache</div>
            <div class="value blue" id="lbCacheCount"><?= count($wpArticles) ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Link interni abilitati</div>
            <div class="value <?= !empty($config['link_internal_enabled']) ? 'green' : '' ?>"><?= !empty($config['link_internal_enabled']) ? 'SI' : 'NO' ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Max link interni</div>
            <div class="value purple"><?= $config['link_max_internal'] ?? 5 ?></div>
        </div>
    </div>
    <?php endif; ?>

    <div id="lbStatusBar" style="display:none;margin-bottom:16px;padding:12px 16px;border-radius:8px;font-size:13px;"></div>

    <?php if (!empty($wpArticlesError)): ?>
        <div class="card">
            <p style="color: #fbbf24;"><?= htmlspecialchars($wpArticlesError) ?></p>
        </div>
    <?php elseif (!empty($wpArticles)): ?>
        <?php
        // --- Paginazione ---
        $lbPerPage = 20;
        $lbTotalArticles = count($wpArticles);
        $lbTotalPages = max(1, ceil($lbTotalArticles / $lbPerPage));
        $lbCurrentPage = max(1, min($lbTotalPages, intval($_GET['lbpage'] ?? 1)));
        $lbOffset = ($lbCurrentPage - 1) * $lbPerPage;
        $lbPageArticles = array_slice($wpArticles, $lbOffset, $lbPerPage);
        ?>
        <div class="card">
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:12px;">
                <h3 style="margin:0;">Articoli WordPress</h3>
                <span style="color:#64748b;font-size:13px;">Pagina <?= $lbCurrentPage ?> di <?= $lbTotalPages ?> (<?= $lbTotalArticles ?> articoli)</span>
            </div>

            <!-- Barra selezione e azioni bulk -->
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;padding:12px;background:#0f172a;border-radius:8px;border:1px solid #334155;flex-wrap:wrap;">
                <label style="display:flex;align-items:center;gap:6px;cursor:pointer;color:#94a3b8;font-size:13px;">
                    <input type="checkbox" id="lbSelectAll" onchange="lbToggleSelectAll(this)" style="width:16px;height:16px;accent-color:#6366f1;">
                    Seleziona pagina
                </label>
                <span id="lbSelectedCount" style="color:#64748b;font-size:13px;"></span>
                <button type="button" class="btn btn-primary btn-sm" id="lbBulkBtn" style="display:none;margin-left:auto;" onclick="lbRelinkSelected()">
                    Applica Link Building (<span id="lbBulkCount">0</span> selezionati)
                </button>
            </div>

            <!-- Lista articoli della pagina corrente -->
            <?php foreach ($lbPageArticles as $idx => $article):
                $articleTitle = $article['title'] ?? '';
                $articleUrl = $article['url'] ?? $article['link'] ?? '';
                $articleExcerpt = $article['excerpt'] ?? '';
                $articleId = $article['id'] ?? 0;
                $articleDate = $article['date'] ?? '';
            ?>
                <div class="feed-item" id="lb-item-<?= $articleId ?>" style="position:relative;">
                    <div style="display:flex;align-items:flex-start;gap:10px;">
                        <input type="checkbox" class="lb-checkbox" value="<?= $articleId ?>" onchange="lbUpdateBulkUI()" style="width:16px;height:16px;margin-top:3px;accent-color:#6366f1;flex-shrink:0;">
                        <div style="flex:1;min-width:0;">
                            <h4 style="margin-bottom:4px;"><?= htmlspecialchars($articleTitle) ?></h4>
                            <div class="date" style="margin-bottom:6px;">
                                <?php if (!empty($articleDate)): ?>
                                    <?= htmlspecialchars(date('d/m/Y H:i', strtotime($articleDate))) ?>
                                <?php endif; ?>
                                <span id="lb-links-badge-<?= $articleId ?>" class="badge links-no" style="margin-left:8px;">
                                    <span class="link-indicator">⏳ Verifica link...</span>
                                </span>
                            </div>
                            <?php if (!empty($articleExcerpt)): ?>
                                <div style="color:#94a3b8;font-size:13px;margin-bottom:8px;line-height:1.5;"><?= mb_substr(strip_tags(html_entity_decode($articleExcerpt, ENT_QUOTES, 'UTF-8')), 0, 200) ?>...</div>
                            <?php endif; ?>
                            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                                <button type="button" class="btn btn-primary btn-sm" id="lb-btn-<?= $articleId ?>" onclick="lbRelinkSingle(<?= $articleId ?>, this)">Applica Link Building</button>
                                <?php if (!empty($articleUrl)): ?>
                                    <a href="<?= htmlspecialchars($articleUrl) ?>" target="_blank" style="color:#818cf8;font-size:12px;text-decoration:underline;">Vedi articolo</a>
                                <?php endif; ?>
                                <span id="lb-status-<?= $articleId ?>" style="font-size:12px;"></span>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

            <!-- Paginazione -->
            <?php if ($lbTotalPages > 1): ?>
            <div style="display:flex;align-items:center;justify-content:center;gap:6px;margin-top:20px;padding-top:16px;border-top:1px solid #334155;flex-wrap:wrap;">
                <?php if ($lbCurrentPage > 1): ?>
                    <a href="?tab=linkbuilding&lbpage=1" class="btn btn-sm" style="background:#334155;color:#e2e8f0;text-decoration:none;">« Prima</a>
                    <a href="?tab=linkbuilding&lbpage=<?= $lbCurrentPage - 1 ?>" class="btn btn-sm" style="background:#334155;color:#e2e8f0;text-decoration:none;">‹ Prec</a>
                <?php endif; ?>

                <?php
                // Mostra al massimo 7 pagine centrate sulla corrente
                $paginationRange = 3;
                $startPage = max(1, $lbCurrentPage - $paginationRange);
                $endPage = min($lbTotalPages, $lbCurrentPage + $paginationRange);
                // Aggiusta per avere sempre ~7 pagine visibili
                if ($endPage - $startPage < $paginationRange * 2) {
                    $startPage = max(1, $endPage - $paginationRange * 2);
                    $endPage = min($lbTotalPages, $startPage + $paginationRange * 2);
                }
                for ($p = $startPage; $p <= $endPage; $p++):
                ?>
                    <?php if ($p === $lbCurrentPage): ?>
                        <span class="btn btn-sm" style="background:#6366f1;color:white;cursor:default;"><?= $p ?></span>
                    <?php else: ?>
                        <a href="?tab=linkbuilding&lbpage=<?= $p ?>" class="btn btn-sm" style="background:#1e293b;color:#94a3b8;text-decoration:none;"><?= $p ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($lbCurrentPage < $lbTotalPages): ?>
                    <a href="?tab=linkbuilding&lbpage=<?= $lbCurrentPage + 1 ?>" class="btn btn-sm" style="background:#334155;color:#e2e8f0;text-decoration:none;">Succ ›</a>
                    <a href="?tab=linkbuilding&lbpage=<?= $lbTotalPages ?>" class="btn btn-sm" style="background:#334155;color:#e2e8f0;text-decoration:none;">Ultima »</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

<?php elseif ($tab === 'seo'): ?>
    <?php
    // SEO Analytics Dashboard
    require_once __DIR__ . '/src/SEOOptimizer.php';
    require_once __DIR__ . '/src/ContentAnalytics.php';
    require_once __DIR__ . '/src/SEOMonitor.php';
    require_once __DIR__ . '/src/MaxSEOGEOConfig.php';
    
    $analytics = new ContentAnalytics(['base_dir' => __DIR__]);
    $report = $analytics->generateReport();
    $monitor = new SEOMonitor(['base_dir' => __DIR__]);
    $performanceReport = $monitor->getPerformanceReport();
    $targets = MaxSEOGEOConfig::getTargetMetrics();
    ?>
    
    <div class="header">
        <h2>📊 SEO Analytics</h2>
    </div>
    
    <!-- Target SEO/GEO -->
    <div class="card" style="background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);margin-bottom:25px;">
        <h3>🎯 Target SEO & GEO</h3>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:15px;">
            <div style="text-align:center;padding:15px;background:#0f172a;border-radius:8px;">
                <div style="font-size:28px;font-weight:700;color:#4ade80;">90+</div>
                <div style="font-size:11px;color:#64748b;text-transform:uppercase;">SEO Score Target</div>
            </div>
            <div style="text-align:center;padding:15px;background:#0f172a;border-radius:8px;">
                <div style="font-size:28px;font-weight:700;color:#60a5fa;">85+</div>
                <div style="font-size:11px;color:#64748b;text-transform:uppercase;">GEO Score Target</div>
            </div>
            <div style="text-align:center;padding:15px;background:#0f172a;border-radius:8px;">
                <div style="font-size:28px;font-weight:700;color:#a78bfa;">70+</div>
                <div style="font-size:11px;color:#64748b;text-transform:uppercase;">Snippet Potential</div>
            </div>
            <div style="text-align:center;padding:15px;background:#0f172a;border-radius:8px;">
                <div style="font-size:28px;font-weight:700;color:#fbbf24;">1500+</div>
                <div style="font-size:11px;color:#64748b;text-transform:uppercase;">Parole Minimo</div>
            </div>
        </div>
    </div>
    
    <!-- Statistiche Principali -->
    <div class="stats-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:15px;margin-bottom:25px;">
        <div class="stat-card">
            <div class="label">Articoli Totali</div>
            <div class="value"><?= $report['summary']['total_articles'] ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Indicizzati</div>
            <div class="value green"><?= $report['summary']['indexed'] ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Featured Snippets</div>
            <div class="value purple"><?= $report['summary']['with_featured_snippet'] ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Posizione Media</div>
            <div class="value orange"><?= $report['summary']['avg_position'] ?? 'N/A' ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Traffico Stimato</div>
            <div class="value blue"><?= number_format($report['summary']['total_estimated_traffic']) ?></div>
        </div>
    </div>
    
    <!-- Raccomandazioni -->
    <?php if (!empty($report['recommendations'])): ?>
    <div class="card" style="border-left:4px solid #f59e0b;">
        <h3>💡 Raccomandazioni</h3>
        <ul style="margin:0;padding-left:20px;">
            <?php foreach ($report['recommendations'] as $rec): ?>
                <li style="margin-bottom:8px;color:#e2e8f0;"><?= htmlspecialchars($rec) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>
    
    <!-- Alert SEO -->
    <?php if (!empty($performanceReport['alerts'])): ?>
    <div class="card" style="border-left:4px solid #dc2626;">
        <h3>🚨 Alert SEO Recenti</h3>
        <div style="display:flex;flex-direction:column;gap:10px;">
            <?php foreach (array_slice($performanceReport['alerts'], 0, 5) as $alert): ?>
            <div style="padding:12px;background:#0f172a;border-radius:6px;">
                <span style="font-size:11px;padding:2px 8px;border-radius:4px;background:<?= $alert['severity'] === 'critical' ? '#dc2626' : '#f59e0b' ?>;color:white;">
                    <?= strtoupper($alert['severity']) ?>
                </span>
                <p style="margin:8px 0 0 0;color:#e2e8f0;"><?= htmlspecialchars($alert['message']) ?></p>
                <p style="margin:5px 0 0 0;font-size:12px;color:#818cf8;">💡 <?= htmlspecialchars($alert['suggestion']) ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Performance Overview -->
    <div class="card">
        <h3>📈 Performance Overview</h3>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:15px;">
            <div style="padding:15px;background:#0f172a;border-radius:8px;text-align:center;">
                <div style="font-size:32px;font-weight:700;color:#4ade80;"><?= $performanceReport['summary']['position_1_3'] ?></div>
                <div style="font-size:12px;color:#64748b;">Posizioni 1-3</div>
            </div>
            <div style="padding:15px;background:#0f172a;border-radius:8px;text-align:center;">
                <div style="font-size:32px;font-weight:700;color:#60a5fa;"><?= $performanceReport['summary']['position_4_10'] ?></div>
                <div style="font-size:12px;color:#64748b;">Posizioni 4-10</div>
            </div>
            <div style="padding:15px;background:#0f172a;border-radius:8px;text-align:center;">
                <div style="font-size:32px;font-weight:700;color:#f87171;"><?= $performanceReport['summary']['position_11_plus'] ?></div>
                <div style="font-size:12px;color:#64748b;">Posizioni 11+</div>
            </div>
        </div>
    </div>
    
    <!-- Top Performers -->
    <?php if (!empty($report['top_performers'])): ?>
    <div class="card">
        <h3>🏆 Top Performers</h3>
        <div style="overflow-x:auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Titolo</th>
                        <th>Posizione</th>
                        <th>Traffico Stimato</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($report['top_performers'] as $article): ?>
                    <tr>
                        <td><a href="<?= htmlspecialchars($article['url']) ?>" target="_blank" style="color:#60a5fa;"><?= htmlspecialchars(mb_substr($article['title'], 0, 60)) ?>...</a></td>
                        <td style="color:#4ade80;font-weight:600;">#<?= $article['position'] ?></td>
                        <td><?= number_format($article['traffic']) ?> visite/mese</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Articoli da Migliorare -->
    <?php if (!empty($report['underperforming'])): ?>
    <div class="card">
        <h3>⚠️ Articoli da Migliorare</h3>
        <div style="overflow-x:auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Titolo</th>
                        <th>Problema</th>
                        <th>Raccomandazione</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($report['underperforming'], 0, 10) as $article): ?>
                    <tr>
                        <td><a href="<?= htmlspecialchars($article['url']) ?>" target="_blank" style="color:#60a5fa;"><?= htmlspecialchars(mb_substr($article['title'], 0, 50)) ?>...</a></td>
                        <td>
                            <?php if ($article['issue'] === 'not_indexed'): ?>
                                <span style="color:#f87171;">Non indicizzato</span>
                            <?php else: ?>
                                <span style="color:#fbbf24;">Posizione bassa</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:12px;color:#94a3b8;"><?= htmlspecialchars($article['recommendation']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Analisi SEO Articoli nel Feed -->
    <div class="card">
        <h3>🔍 Analisi SEO Articoli Recenti</h3>
        <?php
        $feedBuilder = new RSSFeedBuilder($config);
        $items = array_slice($feedBuilder->getItems(), 0, 5);
        
        if (!empty($items)):
            $optimizer = new SEOOptimizer();
        ?>
        <div style="display:flex;flex-direction:column;gap:15px;">
            <?php foreach ($items as $idx => $item): 
                $analysis = $optimizer->analyzeArticle(
                    $item['title'],
                    $item['content'],
                    $item['meta_description'] ?? '',
                    strip_tags($item['title'])
                );
                $score = $analysis['overall_score'];
                $scoreColor = $score >= 70 ? '#4ade80' : ($score >= 50 ? '#fbbf24' : '#f87171');
            ?>
            <div style="padding:15px;background:#0f172a;border-radius:8px;border:1px solid #334155;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                    <h4 style="margin:0;font-size:14px;color:#f1f5f9;"><?= htmlspecialchars(mb_substr($item['title'], 0, 60)) ?>...</h4>
                    <span style="font-size:18px;font-weight:700;color:<?= $scoreColor ?>;"><?= $score ?>/100</span>
                </div>
                <div style="display:flex;gap:15px;font-size:12px;color:#94a3b8;margin-bottom:10px;">
                    <span>SEO: <?= $analysis['seo_score'] ?></span>
                    <span>GEO: <?= $analysis['geo_score'] ?></span>
                    <span>Leggibilità: <?= $analysis['readability_score'] ?></span>
                </div>
                <?php if (!empty($analysis['suggestions'])): ?>
                <div style="font-size:11px;color:#64748b;">
                    💡 <?= htmlspecialchars($analysis['suggestions'][0]) ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
            <p style="color:#64748b;">Nessun articolo nel feed da analizzare.</p>
        <?php endif; ?>
    </div>

<?php elseif ($tab === 'contenthub'): ?>
    <?php
    // Content Hub Manager
    require_once __DIR__ . '/src/ContentHubManager.php';
    require_once __DIR__ . '/src/MaxSEOGEOConfig.php';
    
    $hub = new ContentHubManager(['base_dir' => __DIR__]);
    $hubReport = $hub->generateHubReport();
    $suggestions = $hub->suggestNextArticle();
    $topicMenu = $hub->generateTopicMenu();
    ?>
    
    <div class="header">
        <h2>🏛️ Content Hub & Topic Clusters</h2>
    </div>
    
    <!-- Coverage Score -->
    <div class="stats-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:15px;margin-bottom:25px;">
        <div class="stat-card">
            <div class="label">Coverage Score</div>
            <div class="value <?= $hubReport['overview']['coverage_score'] >= 80 ? 'green' : ($hubReport['overview']['coverage_score'] >= 50 ? 'orange' : 'red') ?>">
                <?= $hubReport['overview']['coverage_score'] ?>%
            </div>
        </div>
        <div class="stat-card">
            <div class="label">Pillar Content</div>
            <div class="value blue"><?= $hubReport['overview']['total_pillars'] ?>/4</div>
        </div>
        <div class="stat-card">
            <div class="label">Cluster Articles</div>
            <div class="value purple"><?= $hubReport['overview']['total_clusters'] ?></div>
        </div>
    </div>
    
    <!-- Prossimo Articolo Consigliato -->
    <?php if (!empty($suggestions)): ?>
    <div class="card" style="border-left:4px solid #4ade80;">
        <h3>🎯 Prossimo Articolo Consigliato</h3>
        <?php $top = $suggestions[0]; ?>
        <div style="padding:15px;background:#0f172a;border-radius:8px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                <span style="font-size:18px;font-weight:600;color:#f1f5f9;"><?= htmlspecialchars($top['keyword']) ?></span>
                <span style="padding:4px 12px;background:<?= $top['priority'] === 'CRITICAL' ? '#dc2626' : '#f59e0b' ?>;color:white;border-radius:4px;font-size:12px;">
                    <?= $top['priority'] ?>
                </span>
            </div>
            <p style="color:#94a3b8;margin-bottom:10px;">
                <strong>Tipo:</strong> <?= $top['type'] ?> | 
                <strong>Topic:</strong> <?= htmlspecialchars($top['topic']) ?>
            </p>
            <p style="color:#64748b;font-size:13px;"><?= htmlspecialchars($top['reason']) ?></p>
            <p style="color:#818cf8;font-size:12px;margin-top:10px;">
                💡 <?= htmlspecialchars($top['expected_impact']) ?>
            </p>
            <div class="generate-container" id="generate-container-0">
                <button type="button" class="btn btn-success generate-btn" 
                        data-keyword="<?= htmlspecialchars($top['keyword']) ?>" 
                        data-topic="<?= htmlspecialchars($top['topic']) ?>"
                        data-index="0">
                    ✨ Crea Ora
                </button>
                <div class="generate-progress" style="display:none;margin-top:10px;">
                    <div class="progress-bar" style="width:100%;height:4px;background:#334155;border-radius:2px;overflow:hidden;">
                        <div class="progress-fill" style="width:0%;height:100%;background:#22c55e;transition:width 0.3s;"></div>
                    </div>
                    <div class="progress-logs" style="margin-top:8px;font-size:12px;color:#94a3b8;max-height:150px;overflow-y:auto;background:#0f172a;padding:8px;border-radius:4px;"></div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Topic Coverage -->
    <div class="card">
        <h3>📊 Copertura Topic</h3>
        <div style="display:flex;flex-direction:column;gap:15px;">
            <?php foreach ($hubReport['topic_coverage'] as $topicKey => $topic): ?>
            <div style="padding:15px;background:#0f172a;border-radius:8px;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                    <h4 style="margin:0;color:#f1f5f9;"><?= htmlspecialchars($topic['name']) ?></h4>
                    <span style="color:<?= $topic['coverage_percent'] >= 80 ? '#4ade80' : ($topic['coverage_percent'] >= 50 ? '#fbbf24' : '#f87171') ?>;font-weight:600;">
                        <?= $topic['coverage_percent'] ?>%
                    </span>
                </div>
                <div style="width:100%;height:8px;background:#334155;border-radius:4px;overflow:hidden;margin-bottom:10px;">
                    <div style="width:<?= $topic['coverage_percent'] ?>%;height:100%;background:<?= $topic['coverage_percent'] >= 80 ? '#4ade80' : ($topic['coverage_percent'] >= 50 ? '#fbbf24' : '#f87171') ?>;transition:width 0.3s;"></div>
                </div>
                <div style="display:flex;justify-content:space-between;font-size:12px;color:#64748b;">
                    <span>
                        <?= $topic['pillar_present'] ? '✅ Pillar' : '❌ Pillar mancante' ?> | 
                        <?= $topic['clusters_count'] ?>/<?= $topic['expected_clusters'] ?> cluster
                    </span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <!-- Raccomandazioni -->
    <?php if (!empty($hubReport['recommendations'])): ?>
    <div class="card">
        <h3>💡 Raccomandazioni Strategiche</h3>
        <div style="display:flex;flex-direction:column;gap:10px;">
            <?php $recIndex = 0; foreach (array_slice($hubReport['recommendations'], 0, 5) as $rec): ?>
            <div style="padding:12px;background:#0f172a;border-radius:6px;border-left:3px solid <?= $rec['priority'] === 'CRITICAL' ? '#dc2626' : ($rec['priority'] === 'HIGH' ? '#f59e0b' : '#60a5fa') ?>;">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;">
                    <div style="flex:1;">
                        <span style="font-size:11px;color:#64748b;text-transform:uppercase;"><?= $rec['priority'] ?></span>
                        <p style="margin:5px 0 0 0;color:#e2e8f0;"><?= htmlspecialchars($rec['action']) ?></p>
                        <?php if (!empty($rec['keyword'])): ?>
                            <code style="font-size:11px;color:#818cf8;"><?= htmlspecialchars($rec['keyword']) ?></code>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($rec['keyword'])): ?>
                    <div class="generate-container" id="generate-container-rec-<?= $recIndex ?>">
                        <button type="button" class="btn btn-sm btn-success generate-btn" 
                                data-keyword="<?= htmlspecialchars($rec['keyword']) ?>" 
                                data-topic="<?= htmlspecialchars($rec['topic'] ?? 'general') ?>"
                                data-index="rec-<?= $recIndex ?>"
                                style="padding:4px 12px;font-size:11px;">
                            ✨ Crea
                        </button>
                        <div class="generate-progress" style="display:none;margin-top:10px;">
                            <div class="progress-bar" style="width:100%;height:4px;background:#334155;border-radius:2px;overflow:hidden;">
                                <div class="progress-fill" style="width:0%;height:100%;background:#22c55e;transition:width 0.3s;"></div>
                            </div>
                            <div class="progress-logs" style="margin-top:8px;font-size:11px;color:#94a3b8;max-height:100px;overflow-y:auto;background:#0f172a;padding:6px;border-radius:4px;"></div>
                        </div>
                    </div>
                    <?php $recIndex++; endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

<?php elseif ($tab === 'richresults'): ?>
    <?php
    // Rich Results Generator
    require_once __DIR__ . '/src/RichResultsGenerator.php';
    
    // Test schema markup
    $testArticle = [
        'title' => 'Esempio Articolo',
        'meta_description' => 'Descrizione di esempio per testare lo schema markup',
        'url' => 'https://example.com/articolo-di-test',
        'published_at' => date('c'),
        'site_name' => 'Sito di Test',
        'site_url' => 'https://example.com',
        'author_name' => 'Autore Test',
        'category' => 'Categoria',
        'word_count' => 1500,
        'faqs' => [
            ['question' => 'Domanda 1?', 'answer' => 'Risposta 1'],
            ['question' => 'Domanda 2?', 'answer' => 'Risposta 2'],
        ],
        'featured_image' => [
            'url' => 'https://example.com/image.jpg',
            'width' => 1200,
            'height' => 630,
        ],
    ];
    
    $testSchema = RichResultsGenerator::generateFullMarkup($testArticle);
    ?>
    
    <div class="header">
        <h2>⭐ Rich Results & Schema Markup</h2>
    </div>
    
    <!-- Info -->
    <div class="card" style="background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);">
        <h3>🎯 Schema Markup Automatico</h3>
        <p style="color:#94a3b8;margin-bottom:15px;">
            Il sistema genera automaticamente schema markup avanzato per ogni articolo pubblicato:
        </p>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:15px;">
            <div style="padding:15px;background:#0f172a;border-radius:8px;">
                <div style="font-size:24px;margin-bottom:8px;">📰</div>
                <strong style="color:#f1f5f9;">Article</strong>
                <p style="font-size:12px;color:#64748b;margin:5px 0 0 0;">Markup completo con author, publisher, date</p>
            </div>
            <div style="padding:15px;background:#0f172a;border-radius:8px;">
                <div style="font-size:24px;margin-bottom:8px;">❓</div>
                <strong style="color:#f1f5f9;">FAQPage</strong>
                <p style="font-size:12px;color:#64748b;margin:5px 0 0 0;">Per sezione FAQ</p>
            </div>
            <div style="padding:15px;background:#0f172a;border-radius:8px;">
                <div style="font-size:24px;margin-bottom:8px;">📋</div>
                <strong style="color:#f1f5f9;">HowTo</strong>
                <p style="font-size:12px;color:#64748b;margin:5px 0 0 0;">Per guide passo-passo</p>
            </div>
            <div style="padding:15px;background:#0f172a;border-radius:8px;">
                <div style="font-size:24px;margin-bottom:8px;">🗣️</div>
                <strong style="color:#f1f5f9;">Speakable</strong>
                <p style="font-size:12px;color:#64748b;margin:5px 0 0 0;">Per voice search</p>
            </div>
        </div>
    </div>
    
    <!-- Esempio Schema -->
    <div class="card">
        <h3>📝 Esempio Schema Markup Generato</h3>
        <p style="color:#64748b;font-size:12px;margin-bottom:10px;">
            Questo è un esempio del markup che viene generato automaticamente per ogni articolo:
        </p>
        <pre style="background:#0f172a;padding:15px;border-radius:8px;overflow-x:auto;font-size:11px;color:#94a3b8;"><?= htmlspecialchars($testSchema) ?></pre>
    </div>
    
    <!-- Validazione -->
    <div class="card">
        <h3>✅ Validazione Schema</h3>
        <p style="color:#94a3b8;margin-bottom:15px;">
            Usa questi strumenti per validare lo schema markup:
        </p>
        <div style="display:flex;gap:15px;flex-wrap:wrap;">
            <a href="https://search.google.com/test/rich-results" target="_blank" class="btn btn-primary" style="text-decoration:none;">
                🔍 Google Rich Results Test
            </a>
            <a href="https://validator.schema.org/" target="_blank" class="btn btn-primary" style="text-decoration:none;">
                📋 Schema Markup Validator
            </a>
        </div>
    </div>

<?php elseif ($tab === 'rewrite'): ?>
    <?php
    // Carica statistiche riscrittura dal database
    $rwDbPath = $config['db_path'] ?? __DIR__ . '/data/history.sqlite';
    $rwDb = new PDO('sqlite:' . $rwDbPath);
    $rwDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $rwDb->exec("
        CREATE TABLE IF NOT EXISTS rewrite_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            post_id INTEGER NOT NULL,
            old_title TEXT,
            new_title TEXT,
            status TEXT NOT NULL DEFAULT 'completed',
            rewritten_at TEXT NOT NULL,
            provider TEXT,
            time_ms INTEGER,
            UNIQUE(post_id)
        )
    ");

    $rwCompleted = $rwDb->query('SELECT COUNT(*) FROM rewrite_log WHERE status = "completed"')->fetchColumn();
    $rwFailed = $rwDb->query('SELECT COUNT(*) FROM rewrite_log WHERE status = "failed"')->fetchColumn();

    // Mappa stato riscrittura per post_id (con conteggio riscritture)
    $rwStatusMap = [];
    $rwRewriteCounts = [];
    $rwStmt = $rwDb->query('SELECT post_id, status, new_title, rewritten_at FROM rewrite_log ORDER BY rewritten_at DESC');
    while ($rwRow = $rwStmt->fetch(PDO::FETCH_ASSOC)) {
        // Mantieni solo l'ultima riscrittura per post_id nella mappa
        if (!isset($rwStatusMap[$rwRow['post_id']])) {
            $rwStatusMap[$rwRow['post_id']] = $rwRow;
        }
        // Conta il numero totale di riscritture
        $rwRewriteCounts[$rwRow['post_id']] = ($rwRewriteCounts[$rwRow['post_id']] ?? 0) + 1;
    }

    // Carica post da cache WordPress
    $rwCachePath = ($config['base_dir'] ?? __DIR__) . '/data/cache_wp_posts.json';
    $rwAllPosts = [];
    $rwCacheFetchedAt = null;
    if (file_exists($rwCachePath)) {
        $rwCacheData = json_decode(file_get_contents($rwCachePath), true);
        $rwAllPosts = $rwCacheData['posts'] ?? [];
        $rwCacheFetchedAt = $rwCacheData['fetched_at'] ?? null;
    }
    $rwWpTotal = count($rwAllPosts);
    $rwRemaining = max(0, $rwWpTotal - $rwCompleted);

    // Categorie WordPress per il filtro
    $rwWpPublisher = new WordPressPublisher($config);
    $rwCategories = $rwWpPublisher->isEnabled() ? $rwWpPublisher->getCategories() : [];
    $rwCatMap = [];
    foreach ($rwCategories as $cat) {
        $rwCatMap[$cat['id']] = $cat['name'];
    }

    // Filtro categoria dalla query string
    $rwFilterCat = isset($_GET['rwcat']) ? intval($_GET['rwcat']) : 0;
    // Filtro stato: all, pending, completed, failed
    $rwFilterStatus = $_GET['rwstatus'] ?? 'all';
    // Ricerca testo
    $rwSearch = trim($_GET['rwsearch'] ?? '');

    // Applica filtri
    $rwFilteredPosts = [];
    foreach ($rwAllPosts as $post) {
        // Filtro categoria
        if ($rwFilterCat > 0) {
            $postCats = $post['categories'] ?? [];
            if (!in_array($rwFilterCat, $postCats)) continue;
        }

        // Filtro stato riscrittura
        $postRwStatus = $rwStatusMap[$post['id']]['status'] ?? 'pending';
        if ($rwFilterStatus === 'pending' && $postRwStatus !== 'pending') continue;
        if ($rwFilterStatus === 'completed' && $postRwStatus !== 'completed') continue;
        if ($rwFilterStatus === 'failed' && $postRwStatus !== 'failed') continue;

        // Ricerca testo nel titolo
        if (!empty($rwSearch)) {
            if (mb_stripos($post['title'] ?? '', $rwSearch) === false) continue;
        }

        $rwFilteredPosts[] = $post;
    }

    // Paginazione
    $rwPerPage = 20;
    $rwTotalFiltered = count($rwFilteredPosts);
    $rwTotalPages = max(1, ceil($rwTotalFiltered / $rwPerPage));
    $rwCurrentPage = max(1, min($rwTotalPages, intval($_GET['rwpage'] ?? 1)));
    $rwPageOffset = ($rwCurrentPage - 1) * $rwPerPage;
    $rwPagePosts = array_slice($rwFilteredPosts, $rwPageOffset, $rwPerPage);
    ?>
    <div class="header">
        <h2>Riscrittura Articoli</h2>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <button type="button" class="btn btn-primary btn-sm" onclick="rwRefreshCache()">Aggiorna Cache</button>
            <button type="button" class="btn btn-sm" style="background:#334155;color:#e2e8f0;" onclick="rwResetLog()">Resetta Log</button>
        </div>
    </div>

    <!-- Statistiche -->
    <div class="stats">
        <div class="stat-card">
            <div class="label">Post su WordPress</div>
            <div class="value blue"><?= $rwWpTotal ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Gia' riscritti</div>
            <div class="value green"><?= $rwCompleted ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Da riscrivere</div>
            <div class="value <?= $rwRemaining > 0 ? 'yellow' : 'green' ?>"><?= $rwRemaining ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Falliti</div>
            <div class="value <?= $rwFailed > 0 ? 'red' : '' ?>"><?= $rwFailed ?></div>
        </div>
    </div>

    <?php if (empty($rwAllPosts)): ?>
        <div class="card">
            <p style="color:#fbbf24;">Nessun articolo in cache. Clicca "Aggiorna Cache" per caricare i post da WordPress.</p>
        </div>
    <?php else: ?>

    <!-- Filtri e ricerca -->
    <div class="card" style="padding:16px;">
        <div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
            <div>
                <label style="display:block;color:#64748b;font-size:11px;margin-bottom:4px;">Cerca per titolo</label>
                <input type="text" id="rwSearchInput" value="<?= htmlspecialchars($rwSearch) ?>" placeholder="Cerca..." style="padding:8px 12px;background:#0f172a;border:1px solid #334155;border-radius:6px;color:#e2e8f0;font-size:13px;width:200px;">
            </div>
            <div>
                <label style="display:block;color:#64748b;font-size:11px;margin-bottom:4px;">Categoria</label>
                <select id="rwFilterCat" style="padding:8px 12px;background:#0f172a;border:1px solid #334155;border-radius:6px;color:#e2e8f0;font-size:13px;">
                    <option value="0">Tutte</option>
                    <?php foreach ($rwCategories as $cat): ?>
                        <option value="<?= $cat['id'] ?>" <?= $rwFilterCat === $cat['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="display:block;color:#64748b;font-size:11px;margin-bottom:4px;">Stato</label>
                <select id="rwFilterStatus" style="padding:8px 12px;background:#0f172a;border:1px solid #334155;border-radius:6px;color:#e2e8f0;font-size:13px;">
                    <option value="all" <?= $rwFilterStatus === 'all' ? 'selected' : '' ?>>Tutti</option>
                    <option value="pending" <?= $rwFilterStatus === 'pending' ? 'selected' : '' ?>>Da riscrivere</option>
                    <option value="completed" <?= $rwFilterStatus === 'completed' ? 'selected' : '' ?>>Riscritti</option>
                    <option value="failed" <?= $rwFilterStatus === 'failed' ? 'selected' : '' ?>>Falliti</option>
                </select>
            </div>
            <button type="button" class="btn btn-primary btn-sm" onclick="rwApplyFilters()">Filtra</button>
        </div>
        <div style="margin-top:8px;color:#64748b;font-size:12px;">
            <?= $rwTotalFiltered ?> articoli trovati
            <?php if ($rwCacheFetchedAt): ?>
                &middot; Cache aggiornata il <?= date('d/m/Y H:i', $rwCacheFetchedAt) ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Barra selezione e azioni bulk -->
    <div class="card" style="padding:12px 16px;">
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;color:#94a3b8;font-size:13px;">
                <input type="checkbox" id="rwSelectAll" onchange="rwToggleSelectAll(this)" style="width:16px;height:16px;accent-color:#6366f1;">
                Seleziona pagina
            </label>
            <span id="rwSelectedCount" style="color:#64748b;font-size:13px;"></span>
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;color:#94a3b8;font-size:13px;margin-left:auto;">
                <input type="checkbox" id="rwNewImages" style="width:16px;height:16px;accent-color:#6366f1;">
                Rigenera immagini
            </label>
            <button type="button" class="btn btn-success btn-sm" id="rwBulkBtn" style="display:none;" onclick="rwStartSelected()">
                Riscrivi selezionati (<span id="rwBulkCount">0</span>)
            </button>
        </div>
    </div>

    <!-- Lista articoli -->
    <div class="card">
        <?php if (empty($rwPagePosts)): ?>
            <p style="color:#64748b;">Nessun articolo corrisponde ai filtri selezionati.</p>
        <?php else: ?>
            <?php foreach ($rwPagePosts as $post):
                $postId = $post['id'];
                $postTitle = $post['title'] ?? '';
                $postUrl = $post['url'] ?? $post['link'] ?? '';
                $postExcerpt = $post['excerpt'] ?? '';
                $postCats = $post['categories'] ?? [];
                $postRwInfo = $rwStatusMap[$postId] ?? null;
                $postRwStatus = $postRwInfo['status'] ?? 'pending';

                // Nomi categorie
                $postCatNames = [];
                foreach ($postCats as $cid) {
                    $postCatNames[] = $rwCatMap[$cid] ?? "#{$cid}";
                }
            ?>
                <div class="feed-item" id="rw-item-<?= $postId ?>" style="position:relative;">
                    <div style="display:flex;align-items:flex-start;gap:10px;">
                        <input type="checkbox" class="rw-checkbox" value="<?= $postId ?>" data-title="<?= htmlspecialchars($postTitle) ?>" onchange="rwUpdateBulkUI()" style="width:16px;height:16px;margin-top:3px;accent-color:#6366f1;flex-shrink:0;">
                        <div style="flex:1;min-width:0;">
                            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:4px;">
                                <h4 style="margin:0;"><?= htmlspecialchars($postTitle) ?></h4>
                                <?php 
                                $rewriteCount = $rwRewriteCounts[$postId] ?? 0;
                                if ($postRwStatus === 'completed'):
                                    $badgeText = $rewriteCount > 1 ? "Riscritto {$rewriteCount}x" : 'Riscritto';
                                ?>
                                    <span style="display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;background:#064e3b;color:#34d399;"><?= $badgeText ?></span>
                                <?php elseif ($postRwStatus === 'failed'): ?>
                                    <span style="display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;background:#7f1d1d;color:#f87171;">Fallito</span>
                                <?php else: ?>
                                    <span style="display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;background:#1e293b;color:#94a3b8;">Da riscrivere</span>
                                <?php endif; ?>
                            </div>
                            <div class="date" style="margin-bottom:6px;">
                                ID: <?= $postId ?>
                                <?php if (!empty($postCatNames)): ?>
                                    &middot; <?= htmlspecialchars(implode(', ', $postCatNames)) ?>
                                <?php endif; ?>
                                <?php if ($postRwInfo && $postRwInfo['rewritten_at']): ?>
                                    &middot; Riscritto il <?= htmlspecialchars(date('d/m/Y H:i', strtotime($postRwInfo['rewritten_at']))) ?>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($postExcerpt)): ?>
                                <div style="color:#94a3b8;font-size:13px;margin-bottom:8px;line-height:1.5;"><?= mb_substr(strip_tags(html_entity_decode($postExcerpt, ENT_QUOTES, 'UTF-8')), 0, 200) ?>...</div>
                            <?php endif; ?>
                            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                                <button type="button" class="btn btn-primary btn-sm" id="rw-btn-<?= $postId ?>" onclick="rwRewriteSingle(<?= $postId ?>, this)">Riscrivi</button>
                                <?php if (!empty($postUrl)): ?>
                                    <a href="<?= htmlspecialchars($postUrl) ?>" target="_blank" style="color:#818cf8;font-size:12px;text-decoration:underline;">Vedi articolo</a>
                                <?php endif; ?>
                                <span id="rw-status-<?= $postId ?>" style="font-size:12px;"></span>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

            <!-- Paginazione -->
            <?php if ($rwTotalPages > 1): ?>
            <div style="display:flex;align-items:center;justify-content:center;gap:6px;margin-top:20px;padding-top:16px;border-top:1px solid #334155;flex-wrap:wrap;">
                <?php
                // Costruisci base URL per paginazione con filtri
                $rwBaseUrl = '?tab=rewrite';
                if ($rwFilterCat > 0) $rwBaseUrl .= '&rwcat=' . $rwFilterCat;
                if ($rwFilterStatus !== 'all') $rwBaseUrl .= '&rwstatus=' . urlencode($rwFilterStatus);
                if (!empty($rwSearch)) $rwBaseUrl .= '&rwsearch=' . urlencode($rwSearch);
                ?>
                <?php if ($rwCurrentPage > 1): ?>
                    <a href="<?= $rwBaseUrl ?>&rwpage=1" class="btn btn-sm" style="background:#334155;color:#e2e8f0;text-decoration:none;">« Prima</a>
                    <a href="<?= $rwBaseUrl ?>&rwpage=<?= $rwCurrentPage - 1 ?>" class="btn btn-sm" style="background:#334155;color:#e2e8f0;text-decoration:none;">‹ Prec</a>
                <?php endif; ?>

                <?php
                $rwPagRange = 3;
                $rwStartPage = max(1, $rwCurrentPage - $rwPagRange);
                $rwEndPage = min($rwTotalPages, $rwCurrentPage + $rwPagRange);
                if ($rwEndPage - $rwStartPage < $rwPagRange * 2) {
                    $rwStartPage = max(1, $rwEndPage - $rwPagRange * 2);
                    $rwEndPage = min($rwTotalPages, $rwStartPage + $rwPagRange * 2);
                }
                for ($p = $rwStartPage; $p <= $rwEndPage; $p++):
                ?>
                    <?php if ($p === $rwCurrentPage): ?>
                        <span class="btn btn-sm" style="background:#6366f1;color:white;cursor:default;"><?= $p ?></span>
                    <?php else: ?>
                        <a href="<?= $rwBaseUrl ?>&rwpage=<?= $p ?>" class="btn btn-sm" style="background:#1e293b;color:#94a3b8;text-decoration:none;"><?= $p ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($rwCurrentPage < $rwTotalPages): ?>
                    <a href="<?= $rwBaseUrl ?>&rwpage=<?= $rwCurrentPage + 1 ?>" class="btn btn-sm" style="background:#334155;color:#e2e8f0;text-decoration:none;">Succ ›</a>
                    <a href="<?= $rwBaseUrl ?>&rwpage=<?= $rwTotalPages ?>" class="btn btn-sm" style="background:#334155;color:#e2e8f0;text-decoration:none;">Ultima »</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php endif; /* fine if rwAllPosts */ ?>

    <!-- Progresso live -->
    <div class="card" id="rwProgressCard" style="display:none;">
        <h3 style="margin-bottom:12px;">Progresso</h3>
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
            <div style="flex:1;background:#0f172a;border-radius:8px;height:24px;overflow:hidden;border:1px solid #334155;">
                <div id="rwProgressBar" style="height:100%;background:linear-gradient(90deg,#6366f1,#818cf8);width:0%;transition:width 0.5s;border-radius:8px;"></div>
            </div>
            <span id="rwProgressPercent" style="color:#94a3b8;font-size:13px;min-width:45px;text-align:right;">0%</span>
        </div>
        <div id="rwLogContainer" style="background:#0f172a;border-radius:8px;border:1px solid #334155;padding:12px;max-height:400px;overflow-y:auto;font-family:'Cascadia Code','Fira Code',monospace;font-size:12px;line-height:1.7;">
        </div>
        <div id="rwSummary" style="display:none;margin-top:16px;padding:16px;background:#0f172a;border-radius:8px;border:1px solid #334155;">
        </div>
    </div>

<?php elseif ($tab === 'factcheck'): ?>
    <?php
    // Carica statistiche fact-check dal database
    $fcDbPath = $config['db_path'] ?? __DIR__ . '/data/history.sqlite';
    $fcDb = new PDO('sqlite:' . $fcDbPath);
    $fcDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $fcDb->exec("
        CREATE TABLE IF NOT EXISTS factcheck_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            post_id INTEGER NOT NULL,
            title TEXT,
            score INTEGER,
            issues TEXT,
            summary TEXT,
            status TEXT NOT NULL DEFAULT 'completed',
            checked_at TEXT NOT NULL,
            provider TEXT,
            time_ms INTEGER
        )
    ");

    $fcChecked      = $fcDb->query('SELECT COUNT(DISTINCT post_id) FROM factcheck_log WHERE status != "failed"')->fetchColumn();
    $fcWithIssues   = $fcDb->query('SELECT COUNT(DISTINCT post_id) FROM factcheck_log WHERE status = "issues_found"')->fetchColumn();
    $fcClean        = $fcDb->query('SELECT COUNT(DISTINCT post_id) FROM factcheck_log WHERE status = "clean"')->fetchColumn();
    $fcFailed       = $fcDb->query('SELECT COUNT(DISTINCT post_id) FROM factcheck_log WHERE status = "failed"')->fetchColumn();
    $fcAvgScore     = $fcDb->query('SELECT ROUND(AVG(score),1) FROM factcheck_log WHERE score IS NOT NULL')->fetchColumn();

    // Mappa ultimo risultato per post_id
    $fcStatusMap = [];
    $fcStmt = $fcDb->query('SELECT post_id, score, issues, summary, status, checked_at FROM factcheck_log ORDER BY checked_at DESC');
    while ($fcRow = $fcStmt->fetch(PDO::FETCH_ASSOC)) {
        if (!isset($fcStatusMap[$fcRow['post_id']])) {
            $fcStatusMap[$fcRow['post_id']] = $fcRow;
        }
    }

    // Carica post da cache WordPress
    $fcCachePath = ($config['base_dir'] ?? __DIR__) . '/data/cache_wp_posts.json';
    $fcAllPosts  = [];
    $fcCacheFetchedAt = null;
    if (file_exists($fcCachePath)) {
        $fcCacheData = json_decode(file_get_contents($fcCachePath), true);
        $fcAllPosts  = $fcCacheData['posts'] ?? [];
        $fcCacheFetchedAt = $fcCacheData['fetched_at'] ?? null;
    }

    // Categorie
    $fcWpPublisher = new WordPressPublisher($config);
    $fcCategories  = $fcWpPublisher->isEnabled() ? $fcWpPublisher->getCategories() : [];
    $fcCatMap = [];
    foreach ($fcCategories as $cat) { $fcCatMap[$cat['id']] = $cat['name']; }

    // Filtri
    $fcFilterCat    = isset($_GET['fccat']) ? intval($_GET['fccat']) : 0;
    $fcFilterStatus = $_GET['fcstatus'] ?? 'all';
    $fcSearch       = trim($_GET['fcsearch'] ?? '');

    // Applica filtri
    $fcFilteredPosts = [];
    foreach ($fcAllPosts as $post) {
        if ($fcFilterCat > 0) {
            if (!in_array($fcFilterCat, $post['categories'] ?? [])) continue;
        }
        $postFcStatus = $fcStatusMap[$post['id']]['status'] ?? 'pending';
        if ($fcFilterStatus === 'pending'      && $postFcStatus !== 'pending')      continue;
        if ($fcFilterStatus === 'clean'        && $postFcStatus !== 'clean')        continue;
        if ($fcFilterStatus === 'issues_found' && $postFcStatus !== 'issues_found') continue;
        if ($fcFilterStatus === 'failed'       && $postFcStatus !== 'failed')       continue;
        if (!empty($fcSearch) && mb_stripos($post['title'] ?? '', $fcSearch) === false) continue;
        $fcFilteredPosts[] = $post;
    }

    // Paginazione
    $fcPerPage      = 20;
    $fcTotalFiltered = count($fcFilteredPosts);
    $fcTotalPages   = max(1, ceil($fcTotalFiltered / $fcPerPage));
    $fcCurrentPage  = max(1, min($fcTotalPages, intval($_GET['fcpage'] ?? 1)));
    $fcPageOffset   = ($fcCurrentPage - 1) * $fcPerPage;
    $fcPagePosts    = array_slice($fcFilteredPosts, $fcPageOffset, $fcPerPage);
    ?>

    <div class="header">
        <h2>Fact Check Articoli</h2>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <button type="button" class="btn btn-sm" style="background:#334155;color:#e2e8f0;" onclick="fcResetLog()">Resetta Log</button>
        </div>
    </div>

    <!-- Statistiche -->
    <div class="stats">
        <div class="stat-card">
            <div class="label">Post su WordPress</div>
            <div class="value blue"><?= count($fcAllPosts) ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Verificati</div>
            <div class="value green"><?= $fcChecked ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Con problemi</div>
            <div class="value <?= $fcWithIssues > 0 ? 'red' : '' ?>"><?= $fcWithIssues ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Senza problemi</div>
            <div class="value green"><?= $fcClean ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Score medio</div>
            <div class="value <?= ($fcAvgScore !== false && $fcAvgScore < 7) ? 'red' : 'green' ?>"><?= $fcAvgScore !== false ? $fcAvgScore . '/10' : '—' ?></div>
        </div>
    </div>

    <?php if (empty($fcAllPosts)): ?>
        <div class="card">
            <p style="color:#fbbf24;">Nessun articolo in cache. Aggiorna la cache dal tab Riscrittura.</p>
        </div>
    <?php else: ?>

    <!-- Filtri -->
    <div class="card" style="padding:16px;">
        <div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
            <div>
                <label style="display:block;color:#64748b;font-size:11px;margin-bottom:4px;">Cerca per titolo</label>
                <input type="text" id="fcSearchInput" value="<?= htmlspecialchars($fcSearch) ?>" placeholder="Cerca..." style="padding:8px 12px;background:#0f172a;border:1px solid #334155;border-radius:6px;color:#e2e8f0;font-size:13px;width:200px;">
            </div>
            <div>
                <label style="display:block;color:#64748b;font-size:11px;margin-bottom:4px;">Categoria</label>
                <select id="fcFilterCat" style="padding:8px 12px;background:#0f172a;border:1px solid #334155;border-radius:6px;color:#e2e8f0;font-size:13px;">
                    <option value="0">Tutte</option>
                    <?php foreach ($fcCategories as $cat): ?>
                        <option value="<?= $cat['id'] ?>" <?= $fcFilterCat === $cat['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="display:block;color:#64748b;font-size:11px;margin-bottom:4px;">Stato</label>
                <select id="fcFilterStatus" style="padding:8px 12px;background:#0f172a;border:1px solid #334155;border-radius:6px;color:#e2e8f0;font-size:13px;">
                    <option value="all"          <?= $fcFilterStatus === 'all'          ? 'selected' : '' ?>>Tutti</option>
                    <option value="pending"      <?= $fcFilterStatus === 'pending'      ? 'selected' : '' ?>>Da verificare</option>
                    <option value="clean"        <?= $fcFilterStatus === 'clean'        ? 'selected' : '' ?>>Senza problemi</option>
                    <option value="issues_found" <?= $fcFilterStatus === 'issues_found' ? 'selected' : '' ?>>Con problemi</option>
                    <option value="failed"       <?= $fcFilterStatus === 'failed'       ? 'selected' : '' ?>>Falliti</option>
                </select>
            </div>
            <button type="button" class="btn btn-primary btn-sm" onclick="fcApplyFilters()">Filtra</button>
        </div>
        <div style="margin-top:8px;color:#64748b;font-size:12px;">
            <?= $fcTotalFiltered ?> articoli trovati
            <?php if ($fcCacheFetchedAt): ?>
                &middot; Cache aggiornata il <?= date('d/m/Y H:i', $fcCacheFetchedAt) ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Selezione bulk -->
    <div class="card" style="padding:12px 16px;">
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;color:#94a3b8;font-size:13px;">
                <input type="checkbox" id="fcSelectAll" onchange="fcToggleSelectAll(this)" style="width:16px;height:16px;accent-color:#6366f1;">
                Seleziona pagina
            </label>
            <span id="fcSelectedCount" style="color:#64748b;font-size:13px;"></span>
            <button type="button" class="btn btn-primary btn-sm" id="fcBulkBtn" style="display:none;" onclick="fcStartSelected()">
                Verifica selezionati (<span id="fcBulkCount">0</span>)
            </button>
        </div>
    </div>

    <!-- Lista articoli -->
    <div class="card">
        <?php if (empty($fcPagePosts)): ?>
            <p style="color:#64748b;">Nessun articolo corrisponde ai filtri selezionati.</p>
        <?php else: ?>
            <?php foreach ($fcPagePosts as $post):
                $postId    = $post['id'];
                $postTitle = $post['title'] ?? '';
                $postUrl   = $post['url'] ?? $post['link'] ?? '';
                $postCats  = $post['categories'] ?? [];
                $fcInfo    = $fcStatusMap[$postId] ?? null;
                $fcStatus  = $fcInfo['status'] ?? 'pending';
                $fcScore   = $fcInfo['score'] ?? null;
                $fcIssues  = $fcInfo ? json_decode($fcInfo['issues'] ?? '[]', true) : [];
                $fcSummary = $fcInfo['summary'] ?? '';

                $postCatNames = [];
                foreach ($postCats as $cid) { $postCatNames[] = $fcCatMap[$cid] ?? "#{$cid}"; }
            ?>
                <div class="feed-item" id="fc-item-<?= $postId ?>">
                    <div style="display:flex;align-items:flex-start;gap:10px;">
                        <input type="checkbox" class="fc-checkbox" value="<?= $postId ?>" onchange="fcUpdateBulkUI()" style="width:16px;height:16px;margin-top:3px;accent-color:#6366f1;flex-shrink:0;">
                        <div style="flex:1;min-width:0;">
                            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:4px;">
                                <h4 style="margin:0;"><?= htmlspecialchars($postTitle) ?></h4>
                                <?php if ($fcStatus === 'clean'): ?>
                                    <span style="display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:4px;font-size:11px;background:#064e3b;color:#34d399;">✓ OK <?= $fcScore ?>/10</span>
                                <?php elseif ($fcStatus === 'issues_found'): ?>
                                    <span style="display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:4px;font-size:11px;background:#7f1d1d;color:#fca5a5;">⚠ Problemi <?= $fcScore ?>/10</span>
                                <?php elseif ($fcStatus === 'failed'): ?>
                                    <span style="display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:4px;font-size:11px;background:#422006;color:#fbbf24;">✗ Errore</span>
                                <?php else: ?>
                                    <span style="display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:4px;font-size:11px;background:#1e293b;color:#94a3b8;">Da verificare</span>
                                <?php endif; ?>
                            </div>
                            <div class="date" style="margin-bottom:6px;">
                                ID: <?= $postId ?>
                                <?php if (!empty($postCatNames)): ?>&middot; <?= htmlspecialchars(implode(', ', $postCatNames)) ?><?php endif; ?>
                                <?php if ($fcInfo && $fcInfo['checked_at']): ?>&middot; Verificato il <?= date('d/m/Y H:i', strtotime($fcInfo['checked_at'])) ?><?php endif; ?>
                            </div>
                            <?php if (!empty($fcSummary)): ?>
                                <div style="color:#94a3b8;font-size:13px;margin-bottom:6px;font-style:italic;"><?= htmlspecialchars($fcSummary) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($fcIssues)): ?>
                                <div id="fc-issues-<?= $postId ?>" style="margin-bottom:8px;">
                                    <ul style="margin:0;padding-left:18px;color:#fca5a5;font-size:12px;">
                                        <?php foreach ($fcIssues as $issue): ?>
                                            <li style="margin-bottom:2px;"><?= htmlspecialchars($issue) ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>
                            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                                <button type="button" class="btn btn-primary btn-sm" id="fc-btn-<?= $postId ?>" onclick="fcCheckSingle(<?= $postId ?>, this)">Verifica</button>
                                <?php if ($fcStatus === 'issues_found'): ?>
                                    <a href="?tab=rewrite&rwsearch=<?= urlencode($postTitle) ?>" style="font-size:12px;color:#fbbf24;text-decoration:underline;">Riscrivi per correggere →</a>
                                <?php endif; ?>
                                <?php if (!empty($postUrl)): ?>
                                    <a href="<?= htmlspecialchars($postUrl) ?>" target="_blank" style="color:#818cf8;font-size:12px;text-decoration:underline;">Vedi articolo</a>
                                <?php endif; ?>
                                <span id="fc-status-<?= $postId ?>" style="font-size:12px;"></span>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

            <!-- Paginazione -->
            <?php if ($fcTotalPages > 1): ?>
            <div style="display:flex;align-items:center;justify-content:center;gap:6px;margin-top:20px;padding-top:16px;border-top:1px solid #334155;flex-wrap:wrap;">
                <?php
                $fcBaseUrl = '?tab=factcheck';
                if ($fcFilterCat > 0) $fcBaseUrl .= '&fccat=' . $fcFilterCat;
                if ($fcFilterStatus !== 'all') $fcBaseUrl .= '&fcstatus=' . urlencode($fcFilterStatus);
                if (!empty($fcSearch)) $fcBaseUrl .= '&fcsearch=' . urlencode($fcSearch);
                ?>
                <?php if ($fcCurrentPage > 1): ?>
                    <a href="<?= $fcBaseUrl ?>&fcpage=1" class="btn btn-sm" style="background:#334155;color:#e2e8f0;text-decoration:none;">« Prima</a>
                    <a href="<?= $fcBaseUrl ?>&fcpage=<?= $fcCurrentPage - 1 ?>" class="btn btn-sm" style="background:#334155;color:#e2e8f0;text-decoration:none;">‹ Prec</a>
                <?php endif; ?>
                <span style="color:#64748b;font-size:13px;"><?= $fcCurrentPage ?> / <?= $fcTotalPages ?></span>
                <?php if ($fcCurrentPage < $fcTotalPages): ?>
                    <a href="<?= $fcBaseUrl ?>&fcpage=<?= $fcCurrentPage + 1 ?>" class="btn btn-sm" style="background:#334155;color:#e2e8f0;text-decoration:none;">Succ ›</a>
                    <a href="<?= $fcBaseUrl ?>&fcpage=<?= $fcTotalPages ?>" class="btn btn-sm" style="background:#334155;color:#e2e8f0;text-decoration:none;">Ultima »</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Progress card -->
    <div class="card" id="fcProgressCard" style="display:none;">
        <h3 style="margin-bottom:12px;">Progresso Fact-Check</h3>
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
            <div style="flex:1;background:#0f172a;border-radius:8px;height:24px;overflow:hidden;border:1px solid #334155;">
                <div id="fcProgressBar" style="height:100%;background:linear-gradient(90deg,#f59e0b,#fbbf24);width:0%;transition:width 0.5s;border-radius:8px;"></div>
            </div>
            <span id="fcProgressPercent" style="color:#94a3b8;font-size:13px;min-width:45px;text-align:right;">0%</span>
        </div>
        <div id="fcLogContainer" style="background:#0f172a;border-radius:8px;border:1px solid #334155;padding:12px;max-height:400px;overflow-y:auto;font-family:'Cascadia Code','Fira Code',monospace;font-size:12px;line-height:1.7;"></div>
        <div id="fcSummary" style="display:none;margin-top:16px;padding:16px;background:#0f172a;border-radius:8px;border:1px solid #334155;"></div>
    </div>

    <?php endif; ?>

<?php endif; ?>

</div>

<script>
// --- Config sub-tabs ---
function showCfgTab(name) {
    document.querySelectorAll('.cfg-panel').forEach(function(p) { p.classList.remove('active'); });
    document.querySelectorAll('.cfg-tab').forEach(function(t) { t.classList.remove('active'); });
    var panel = document.getElementById('cfg-' + name);
    if (panel) panel.classList.add('active');
    event.currentTarget.classList.add('active');
}

// --- Keyword source toggle ---
function toggleKeywordSource() {
    var source = document.getElementById('keyword_source').value;
    document.getElementById('google_keywords_section').style.display = source === 'google' ? '' : 'none';
    document.getElementById('manual_keywords_section').style.display = source === 'manual' ? '' : 'none';
}

// --- Mobile sidebar toggle ---
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('open');
}
function closeSidebar() {
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('sidebarOverlay').classList.remove('open');
}
// Close sidebar on nav link click (mobile)
document.querySelectorAll('.sidebar a').forEach(function(a) {
    a.addEventListener('click', closeSidebar);
});

function toggleContent(idx) {
    const el = document.getElementById('content-' + idx);
    el.classList.toggle('show');
}

// --- Selezione multipla articoli feed ---
function toggleSelectAll(checkbox) {
    const items = document.querySelectorAll('.item-checkbox');
    items.forEach(item => item.checked = checkbox.checked);
    updateBulkUI();
}

function updateBulkUI() {
    const checkboxes = document.querySelectorAll('.item-checkbox');
    const checked = document.querySelectorAll('.item-checkbox:checked');
    const count = checked.length;
    const total = checkboxes.length;

    const btn = document.getElementById('bulkDeleteBtn');
    const countSpan = document.getElementById('deleteCount');
    const selectedInfo = document.getElementById('selectedCount');
    const selectAllCheckbox = document.getElementById('selectAll');

    if (btn) {
        btn.style.display = count > 0 ? 'inline-block' : 'none';
    }
    if (countSpan) {
        countSpan.textContent = count;
    }
    if (selectedInfo) {
        selectedInfo.textContent = count > 0 ? count + ' di ' + total + ' selezionati' : '';
    }
    if (selectAllCheckbox) {
        selectAllCheckbox.checked = count === total && total > 0;
        selectAllCheckbox.indeterminate = count > 0 && count < total;
    }
}

function confirmBulkDelete() {
    const count = document.querySelectorAll('.item-checkbox:checked').length;
    if (count === 0) {
        alert('Seleziona almeno un articolo da eliminare.');
        return false;
    }
    return confirm('Eliminare ' + count + ' articoli dal feed?');
}

// --- Editor articoli feed ---
function openEditor(idx) {
    // Chiudi altri editor aperti
    document.querySelectorAll('.edit-panel').forEach(function(el) { el.style.display = 'none'; });
    document.getElementById('editor-' + idx).style.display = 'block';
    // Chiudi la preview del contenuto se aperta
    var contentFull = document.getElementById('content-' + idx);
    if (contentFull) contentFull.classList.remove('show');
}

function closeEditor(idx) {
    document.getElementById('editor-' + idx).style.display = 'none';
}

function saveEdit(idx) {
    var titleInput = document.getElementById('edit-title-' + idx);
    var contentInput = document.getElementById('edit-content-' + idx);
    var statusEl = document.getElementById('edit-status-' + idx);

    var newTitle = titleInput.value.trim();
    var newContent = contentInput.value.trim();

    if (!newTitle || !newContent) {
        statusEl.textContent = 'Titolo e contenuto non possono essere vuoti.';
        statusEl.style.color = '#fca5a5';
        return;
    }

    statusEl.textContent = 'Salvataggio...';
    statusEl.style.color = '#94a3b8';

    var formData = new FormData();
    formData.append('csrf_token', '<?= htmlspecialchars($csrfToken) ?>');
    formData.append('action', 'edit_feed_item');
    formData.append('item_index', idx);
    formData.append('new_title', newTitle);
    formData.append('new_content', newContent);

    fetch('dashboard.php', { method: 'POST', body: formData })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.success) {
                statusEl.textContent = 'Salvato!';
                statusEl.style.color = '#4ade80';

                // Aggiorna UI senza ricaricare la pagina
                var titleDisplay = document.getElementById('title-display-' + idx);
                if (titleDisplay) titleDisplay.textContent = newTitle;

                var preview = document.getElementById('preview-' + idx);
                if (preview) {
                    var tmp = document.createElement('div');
                    tmp.innerHTML = newContent;
                    var text = tmp.textContent || tmp.innerText || '';
                    preview.textContent = text.substring(0, 200) + '...';
                }

                var contentFull = document.getElementById('content-' + idx);
                if (contentFull) contentFull.innerHTML = newContent;

                setTimeout(function() { closeEditor(idx); }, 800);
            } else {
                statusEl.textContent = 'Errore: ' + data.message;
                statusEl.style.color = '#fca5a5';
            }
        })
        .catch(function(err) {
            statusEl.textContent = 'Errore di rete: ' + err.message;
            statusEl.style.color = '#fca5a5';
        });
}

// --- WordPress Test Connection ---
function testWpConnection() {
    var resultEl = document.getElementById('wpTestResult');
    resultEl.textContent = 'Connessione in corso...';
    resultEl.style.color = '#94a3b8';

    var formData = new FormData();
    formData.append('csrf_token', '<?= htmlspecialchars($csrfToken) ?>');
    formData.append('action', 'wp_test_connection');

    fetch('dashboard.php', { method: 'POST', body: formData })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.success) {
                resultEl.textContent = data.message;
                resultEl.style.color = '#4ade80';
            } else {
                resultEl.textContent = data.message;
                resultEl.style.color = '#fca5a5';
            }
        })
        .catch(function(err) {
            resultEl.textContent = 'Errore di rete: ' + err.message;
            resultEl.style.color = '#fca5a5';
        });
}

// --- Link Building ---
function refreshLinkCache() {
    var resultEl = document.getElementById('linkCacheResult');
    resultEl.textContent = 'Aggiornamento cache...';
    resultEl.style.color = '#94a3b8';

    var formData = new FormData();
    formData.append('csrf_token', '<?= htmlspecialchars($csrfToken) ?>');
    formData.append('action', 'refresh_link_cache');

    fetch('dashboard.php', { method: 'POST', body: formData })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.success) {
                resultEl.textContent = data.message;
                resultEl.style.color = '#4ade80';
                var countEl = document.getElementById('linkCacheCount');
                if (countEl) countEl.textContent = data.count;
            } else {
                resultEl.textContent = data.message;
                resultEl.style.color = '#fca5a5';
            }
        })
        .catch(function(err) {
            resultEl.textContent = 'Errore: ' + err.message;
            resultEl.style.color = '#fca5a5';
        });
}

function relinkBulk() {
    var maxPosts = prompt('Quanti articoli vuoi processare? (max 50)', '10');
    if (!maxPosts) return;
    maxPosts = parseInt(maxPosts);
    if (isNaN(maxPosts) || maxPosts < 1) return;

    var resultEl = document.getElementById('linkCacheResult');
    resultEl.textContent = 'Elaborazione in corso... (puo\' richiedere qualche minuto)';
    resultEl.style.color = '#fbbf24';

    var formData = new FormData();
    formData.append('csrf_token', '<?= htmlspecialchars($csrfToken) ?>');
    formData.append('action', 'relink_wp_bulk');
    formData.append('max_posts', maxPosts);

    fetch('dashboard.php', { method: 'POST', body: formData })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.success) {
                resultEl.textContent = data.message;
                resultEl.style.color = '#4ade80';
            } else {
                resultEl.textContent = data.message;
                resultEl.style.color = '#fca5a5';
            }
        })
        .catch(function(err) {
            resultEl.textContent = 'Errore: ' + err.message;
            resultEl.style.color = '#fca5a5';
        });
}

// --- Link Building Tab ---
function lbRefreshCache() {
    var statusBar = document.getElementById('lbStatusBar');
    statusBar.style.display = 'block';
    statusBar.style.background = '#1e3a5f';
    statusBar.style.color = '#93c5fd';
    statusBar.textContent = 'Aggiornamento cache articoli...';

    var formData = new FormData();
    formData.append('csrf_token', '<?= htmlspecialchars($csrfToken) ?>');
    formData.append('action', 'refresh_link_cache');

    fetch('dashboard.php', { method: 'POST', body: formData })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.success) {
                statusBar.style.background = '#064e3b';
                statusBar.style.color = '#4ade80';
                statusBar.textContent = data.message + ' Ricarica la pagina per vedere la lista aggiornata.';
                var countEl = document.getElementById('lbCacheCount');
                if (countEl) countEl.textContent = data.count;
            } else {
                statusBar.style.background = '#7f1d1d';
                statusBar.style.color = '#fca5a5';
                statusBar.textContent = data.message;
            }
        })
        .catch(function(err) {
            statusBar.style.background = '#7f1d1d';
            statusBar.style.color = '#fca5a5';
            statusBar.textContent = 'Errore: ' + err.message;
        });
}

function lbToggleSelectAll(master) {
    var checkboxes = document.querySelectorAll('.lb-checkbox');
    checkboxes.forEach(function(cb) { cb.checked = master.checked; });
    lbUpdateBulkUI();
}

function lbUpdateBulkUI() {
    var checkboxes = document.querySelectorAll('.lb-checkbox');
    var checked = document.querySelectorAll('.lb-checkbox:checked');
    var count = checked.length;
    var countEl = document.getElementById('lbSelectedCount');
    var bulkBtn = document.getElementById('lbBulkBtn');
    var bulkCount = document.getElementById('lbBulkCount');

    if (count > 0) {
        countEl.textContent = count + ' di ' + checkboxes.length + ' selezionati in questa pagina';
        bulkBtn.style.display = 'inline-block';
        bulkCount.textContent = count;
    } else {
        countEl.textContent = '';
        bulkBtn.style.display = 'none';
    }

    var selectAll = document.getElementById('lbSelectAll');
    if (selectAll) {
        selectAll.checked = count === checkboxes.length && count > 0;
    }
}

function lbRelinkSingle(postId, btn) {
    if (!confirm('Applicare il link building a questo articolo?')) return;

    var originalText = btn.textContent;
    btn.textContent = 'Elaborazione...';
    btn.disabled = true;
    btn.style.opacity = '0.6';
    var statusEl = document.getElementById('lb-status-' + postId);
    statusEl.textContent = '';

    var formData = new FormData();
    formData.append('csrf_token', '<?= htmlspecialchars($csrfToken) ?>');
    formData.append('action', 'relink_wp_article');
    formData.append('wp_post_id', postId);

    fetch('dashboard.php', { method: 'POST', body: formData })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.success) {
                btn.textContent = '✓ Completato';
                btn.style.background = '#065f46';
                btn.style.color = '#4ade80';
                statusEl.textContent = data.message;
                statusEl.style.color = '#4ade80';
                // Aggiorna il badge dei link
                if (typeof data.internal_links !== 'undefined') {
                    lbUpdateLinkBadge(postId, data.internal_links, data.external_links || 0);
                }
            } else {
                btn.textContent = originalText;
                btn.disabled = false;
                btn.style.opacity = '1';
                statusEl.textContent = data.message;
                statusEl.style.color = '#fca5a5';
            }
        })
        .catch(function(err) {
            btn.textContent = originalText;
            btn.disabled = false;
            btn.style.opacity = '1';
            statusEl.textContent = 'Errore: ' + err.message;
            statusEl.style.color = '#fca5a5';
        });
}

function lbRelinkSelected() {
    var checked = document.querySelectorAll('.lb-checkbox:checked');
    if (checked.length === 0) return;
    if (!confirm('Applicare il link building a ' + checked.length + ' articoli? Questa operazione puo\' richiedere diversi minuti.')) return;

    var postIds = [];
    checked.forEach(function(cb) { postIds.push(parseInt(cb.value)); });

    var statusBar = document.getElementById('lbStatusBar');
    statusBar.style.display = 'block';
    statusBar.style.background = '#1e3a5f';
    statusBar.style.color = '#fbbf24';
    statusBar.textContent = 'Elaborazione di ' + postIds.length + ' articoli in corso... (puo\' richiedere qualche minuto)';

    var bulkBtn = document.getElementById('lbBulkBtn');
    bulkBtn.disabled = true;
    bulkBtn.style.opacity = '0.6';

    var formData = new FormData();
    formData.append('csrf_token', '<?= htmlspecialchars($csrfToken) ?>');
    formData.append('action', 'relink_wp_selected');
    formData.append('post_ids', JSON.stringify(postIds));

    fetch('dashboard.php', { method: 'POST', body: formData })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            bulkBtn.disabled = false;
            bulkBtn.style.opacity = '1';
            if (data.success) {
                statusBar.style.background = '#064e3b';
                statusBar.style.color = '#4ade80';
                statusBar.textContent = data.message;
                // Segna gli articoli processati e aggiorna badge
                checked.forEach(function(cb) {
                    var item = document.getElementById('lb-item-' + cb.value);
                    if (item) {
                        var btn = item.querySelector('.btn-primary');
                        if (btn && data.processed > 0) {
                            btn.textContent = '✓ Completato';
                            btn.style.background = '#065f46';
                            btn.style.color = '#4ade80';
                        }
                    }
                });
                // Re-verifica i link dopo il bulk
                setTimeout(function() { lbCheckAllLinks(); }, 1000);
            } else {
                statusBar.style.background = '#7f1d1d';
                statusBar.style.color = '#fca5a5';
                statusBar.textContent = data.message;
            }
        })
        .catch(function(err) {
            bulkBtn.disabled = false;
            bulkBtn.style.opacity = '1';
            statusBar.style.background = '#7f1d1d';
            statusBar.style.color = '#fca5a5';
            statusBar.textContent = 'Errore: ' + err.message;
        });
}

// --- Link Building: Check links status on page load ---
function lbUpdateLinkBadge(postId, internal, external) {
    var badge = document.getElementById('lb-links-badge-' + postId);
    if (!badge) return;
    if (internal < 0) {
        badge.className = 'badge links-no';
        badge.innerHTML = '<span class="link-indicator">⚠️ Errore verifica</span>';
        return;
    }
    if (internal > 0) {
        badge.className = 'badge links-yes';
        badge.innerHTML = '<span class="link-indicator">🔗 ' + internal + ' interni / ' + external + ' esterni</span>';
        // Cambia il bottone se ha già link
        var btn = document.getElementById('lb-btn-' + postId);
        if (btn && !btn.disabled) {
            btn.textContent = 'Aggiorna Link (' + internal + ')';
            btn.style.background = '#065f46';
        }
    } else {
        badge.className = 'badge links-no';
        badge.innerHTML = '<span class="link-indicator">Nessun link</span>';
    }
}

function lbCheckAllLinks() {
    var checkboxes = document.querySelectorAll('.lb-checkbox');
    if (checkboxes.length === 0) return;

    var postIds = [];
    checkboxes.forEach(function(cb) { postIds.push(parseInt(cb.value)); });

    // Con la paginazione abbiamo max ~20 articoli per pagina.
    // Facciamo 2 batch da 10 in sequenza per non sovraccaricare.
    var batchSize = 10;
    var batches = [];
    for (var i = 0; i < postIds.length; i += batchSize) {
        batches.push(postIds.slice(i, i + batchSize));
    }

    function processBatch(index) {
        if (index >= batches.length) return;
        var batch = batches[index];

        var formData = new FormData();
        formData.append('csrf_token', '<?= htmlspecialchars($csrfToken) ?>');
        formData.append('action', 'check_wp_links');
        formData.append('post_ids', JSON.stringify(batch));

        fetch('dashboard.php', { method: 'POST', body: formData })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data.success && data.results) {
                    for (var pid in data.results) {
                        lbUpdateLinkBadge(pid, data.results[pid].internal, data.results[pid].external);
                    }
                }
                // Processa il batch successivo dopo 500ms
                setTimeout(function() { processBatch(index + 1); }, 500);
            })
            .catch(function() {
                batch.forEach(function(pid) {
                    lbUpdateLinkBadge(pid, -1, -1);
                });
                setTimeout(function() { processBatch(index + 1); }, 500);
            });
    }

    processBatch(0);
}

// Auto-check links when link building tab is visible
if (document.querySelector('.lb-checkbox')) {
    lbCheckAllLinks();
}

// --- WordPress Publish Article ---
function publishToWP(index, btn) {
    if (!confirm('Pubblicare questo articolo su WordPress?')) return;

    var originalText = btn.textContent;
    btn.textContent = 'Pubblicazione...';
    btn.disabled = true;
    btn.style.opacity = '0.6';

    var formData = new FormData();
    formData.append('csrf_token', '<?= htmlspecialchars($csrfToken) ?>');
    formData.append('action', 'wp_publish_article');
    formData.append('item_index', index);

    fetch('dashboard.php', { method: 'POST', body: formData })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.success) {
                // Sostituisci il pulsante con lo stato "Pubblicato"
                btn.textContent = 'Pubblicato su WP';
                btn.style.background = '#065f46';
                btn.style.color = '#4ade80';
                btn.style.cursor = 'default';
                btn.style.opacity = '0.9';
                btn.disabled = true;
                btn.onclick = null;
                if (data.post_url) {
                    var link = document.createElement('a');
                    link.href = data.post_url;
                    link.target = '_blank';
                    link.textContent = 'Vedi post';
                    link.style.cssText = 'color:#4ade80;font-size:12px;text-decoration:underline;';
                    btn.parentNode.appendChild(link);
                }
            } else {
                btn.textContent = 'Errore';
                btn.style.background = '#991b1b';
                alert('Errore: ' + data.message);
                setTimeout(function() {
                    btn.textContent = originalText;
                    btn.style.background = '#059669';
                    btn.style.opacity = '1';
                    btn.disabled = false;
                }, 3000);
            }
        })
        .catch(function(err) {
            btn.textContent = 'Errore';
            btn.style.background = '#991b1b';
            alert('Errore di rete: ' + err.message);
            setTimeout(function() {
                btn.textContent = originalText;
                btn.style.background = '#059669';
                btn.style.opacity = '1';
                btn.disabled = false;
            }, 3000);
        });
}

// Gestione cambio modello fal.ai per aggiornare le opzioni dimensioni
document.addEventListener('DOMContentLoaded', function() {
    const modelSelect = document.querySelector('select[name="fal_model_id"]');
    const sizeSelect = document.getElementById('fal_image_size');
    const inlineSizeSelect = document.querySelector('select[name="fal_inline_size"]');
    const sizeHint = document.getElementById('size_hint');

    function updateSizeOptions(isGPTImage, targetSelect) {
        targetSelect.innerHTML = '';

        if (isGPTImage) {
            const gptSizes = {
                '1024x1024': '1024x1024 (Square - Min crediti)',
                '1536x1024': '1536x1024 (Landscape - Orizzontale)',
                '1024x1536': '1024x1536 (Portrait - Verticale)'
            };
            for (const [value, label] of Object.entries(gptSizes)) {
                const option = document.createElement('option');
                option.value = value;
                option.textContent = label;
                if (value === '1024x1024') option.selected = true;
                targetSelect.appendChild(option);
            }
        } else {
            const fluxSizes = {
                'landscape_16_9': 'Landscape 16:9 (Panoramico)',
                'landscape_4_3': 'Landscape 4:3 (Classico)',
                'square': 'Square 1:1 (Quadrato)',
                'portrait_4_3': 'Portrait 4:3 (Verticale)',
                'portrait_16_9': 'Portrait 16:9 (Stories)'
            };
            for (const [value, label] of Object.entries(fluxSizes)) {
                const option = document.createElement('option');
                option.value = value;
                option.textContent = label;
                if (value === 'landscape_16_9') option.selected = true;
                targetSelect.appendChild(option);
            }
        }
    }

    if (modelSelect) {
        modelSelect.addEventListener('change', function() {
            const isGPTImage = this.value.includes('gpt-image');
            if (sizeSelect) updateSizeOptions(isGPTImage, sizeSelect);
            if (inlineSizeSelect) updateSizeOptions(isGPTImage, inlineSizeSelect);
            if (sizeHint) {
                sizeHint.textContent = isGPTImage
                    ? '1024x1024 = Square (più economico), 1536x1024 = Landscape, 1024x1536 = Portrait'
                    : 'Seleziona il formato adatto al tuo contenuto';
            }
        });
    }
});

// ============================================================
// RISCRITTURA - Selezione, filtri, avvio e polling
// ============================================================

var rwPolling = null;
var rwOffset = 0;
var rwActiveIds = []; // ID dei post in fase di riscrittura

// --- Filtri ---
function rwApplyFilters() {
    var search = document.getElementById('rwSearchInput') ? document.getElementById('rwSearchInput').value : '';
    var cat = document.getElementById('rwFilterCat') ? document.getElementById('rwFilterCat').value : '0';
    var status = document.getElementById('rwFilterStatus') ? document.getElementById('rwFilterStatus').value : 'all';
    var url = '?tab=rewrite';
    if (cat !== '0') url += '&rwcat=' + encodeURIComponent(cat);
    if (status !== 'all') url += '&rwstatus=' + encodeURIComponent(status);
    if (search) url += '&rwsearch=' + encodeURIComponent(search);
    window.location.href = url;
}

// Enter key nella ricerca
(function() {
    var searchInput = document.getElementById('rwSearchInput');
    if (searchInput) {
        searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') { e.preventDefault(); rwApplyFilters(); }
        });
    }
})();

// --- Selezione ---
function rwToggleSelectAll(checkbox) {
    document.querySelectorAll('.rw-checkbox').forEach(function(cb) { cb.checked = checkbox.checked; });
    rwUpdateBulkUI();
}

function rwUpdateBulkUI() {
    var all = document.querySelectorAll('.rw-checkbox');
    var checked = document.querySelectorAll('.rw-checkbox:checked');
    var count = checked.length;
    var total = all.length;

    var btn = document.getElementById('rwBulkBtn');
    var countSpan = document.getElementById('rwBulkCount');
    var info = document.getElementById('rwSelectedCount');
    var selectAll = document.getElementById('rwSelectAll');

    if (btn) btn.style.display = count > 0 ? 'inline-block' : 'none';
    if (countSpan) countSpan.textContent = count;
    if (info) info.textContent = count > 0 ? count + ' di ' + total + ' selezionati' : '';
    if (selectAll) {
        selectAll.checked = count === total && total > 0;
        selectAll.indeterminate = count > 0 && count < total;
    }
}

// --- Aggiorna Cache ---
function rwRefreshCache() {
    // Riusa il sistema del LinkBuilder per aggiornare la cache
    var btn = event.target;
    btn.disabled = true;
    btn.textContent = 'Aggiornamento...';
    fetch('dashboard.php?tab=rewrite&action=refresh_rw_cache&csrf_token=<?= $csrfToken ?>')
        .then(function(r) { return r.text(); })
        .then(function() { location.reload(); })
        .catch(function() { btn.disabled = false; btn.textContent = 'Aggiorna Cache'; });
}

// --- Riscrittura singolo articolo ---
function rwRewriteSingle(postId, btn) {
    if (!confirm('Vuoi riscrivere questo articolo?\n\nPuoi riscriverlo anche se è già stato riscritto in precedenza. Il permalink resterà invariato.')) return;

    var newImages = document.getElementById('rwNewImages') ? (document.getElementById('rwNewImages').checked ? '1' : '') : '';
    var params = 'action=start&ids=' + postId;
    if (newImages) params += '&new_images=1';

    btn.disabled = true;
    btn.textContent = 'Riscrittura...';
    btn.style.opacity = '0.6';

    var statusEl = document.getElementById('rw-status-' + postId);
    if (statusEl) { statusEl.textContent = 'In corso...'; statusEl.style.color = '#fbbf24'; }

    rwShowProgress();
    rwActiveIds = [postId];
    rwOffset = 0;

    fetch('rewrite_stream.php?' + params)
        .then(function() {
            setTimeout(function() { rwPolling = setInterval(rwPoll, 800); }, 1000);
        })
        .catch(function(err) {
            rwAddLog('Errore: ' + err, 'error');
            btn.disabled = false; btn.textContent = 'Riscrivi'; btn.style.opacity = '1';
        });
}

// --- Riscrittura selezionati ---
function rwStartSelected() {
    var checked = document.querySelectorAll('.rw-checkbox:checked');
    if (checked.length === 0) return;

    var ids = [];
    checked.forEach(function(cb) { ids.push(cb.value); });

    if (!confirm('Vuoi riscrivere ' + ids.length + ' articoli selezionati?')) return;

    var newImages = document.getElementById('rwNewImages') ? (document.getElementById('rwNewImages').checked ? '1' : '') : '';
    var params = 'action=start&ids=' + ids.join(',');
    if (newImages) params += '&new_images=1';

    // Disabilita tutti i pulsanti
    document.querySelectorAll('.rw-checkbox:checked').forEach(function(cb) {
        var pid = cb.value;
        var btn = document.getElementById('rw-btn-' + pid);
        if (btn) { btn.disabled = true; btn.textContent = 'In coda...'; btn.style.opacity = '0.6'; }
        var st = document.getElementById('rw-status-' + pid);
        if (st) { st.textContent = 'In coda'; st.style.color = '#fbbf24'; }
    });

    var bulkBtn = document.getElementById('rwBulkBtn');
    if (bulkBtn) { bulkBtn.disabled = true; bulkBtn.textContent = 'In esecuzione...'; bulkBtn.style.opacity = '0.6'; }

    rwShowProgress();
    rwActiveIds = ids;
    rwOffset = 0;

    fetch('rewrite_stream.php?' + params)
        .then(function() {
            setTimeout(function() { rwPolling = setInterval(rwPoll, 800); }, 1000);
        })
        .catch(function(err) {
            rwAddLog('Errore: ' + err, 'error');
            rwResetAllBtns();
        });
}

// --- Progress UI ---
function rwShowProgress() {
    var card = document.getElementById('rwProgressCard');
    if (card) { card.style.display = ''; card.scrollIntoView({behavior:'smooth', block:'nearest'}); }
    var log = document.getElementById('rwLogContainer');
    if (log) log.innerHTML = '';
    var summary = document.getElementById('rwSummary');
    if (summary) summary.style.display = 'none';
    var bar = document.getElementById('rwProgressBar');
    if (bar) bar.style.width = '0%';
    var pct = document.getElementById('rwProgressPercent');
    if (pct) pct.textContent = '0%';
}

function rwPoll() {
    fetch('rewrite_stream.php?action=poll&offset=' + rwOffset)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            rwOffset = data.offset;

            data.lines.forEach(function(line) {
                var event = line.event || line.type || '';

                if (event === 'log') {
                    rwAddLog(line.message || '', line.type || 'info');

                    // Aggiorna badge nella lista quando un post viene completato o fallisce
                    var msg = line.message || '';
                    var successMatch = msg.match(/Post (\d+) aggiornato/);
                    var failMatch = msg.match(/fallito per ID (\d+)/);
                    if (successMatch) rwUpdateItemStatus(successMatch[1], 'completed');
                    if (failMatch) rwUpdateItemStatus(failMatch[1], 'failed');
                } else if (event === 'section') {
                    rwAddLog('--- ' + (line.title || '') + ' ---', 'step');
                } else if (event === 'summary') {
                    var summaryEl = document.getElementById('rwSummary');
                    if (summaryEl) {
                        summaryEl.style.display = '';
                        summaryEl.innerHTML = '<div style="display:flex;gap:24px;flex-wrap:wrap;">'
                            + '<div><span style="color:#64748b;">Totale:</span> <strong style="color:#e2e8f0;">' + (line.total || 0) + '</strong></div>'
                            + '<div><span style="color:#64748b;">Successo:</span> <strong style="color:#34d399;">' + (line.success || 0) + '</strong></div>'
                            + '<div><span style="color:#64748b;">Falliti:</span> <strong style="color:#f87171;">' + (line.failed || 0) + '</strong></div>'
                            + '</div>';
                    }
                    var bar = document.getElementById('rwProgressBar');
                    if (bar) bar.style.width = '100%';
                    var pctEl = document.getElementById('rwProgressPercent');
                    if (pctEl) pctEl.textContent = '100%';
                }
            });

            if (data.done) {
                clearInterval(rwPolling);
                rwPolling = null;
                rwAddLog('Riscrittura completata!', 'success');
                rwResetAllBtns();
            }
        })
        .catch(function(err) {
            // Errore di rete, riprova
        });
}

function rwUpdateItemStatus(postId, status) {
    var btn = document.getElementById('rw-btn-' + postId);
    var st = document.getElementById('rw-status-' + postId);
    if (status === 'completed') {
        if (btn) { btn.textContent = 'Riscritto'; btn.style.background = '#059669'; btn.disabled = true; }
        if (st) { st.textContent = 'Completato'; st.style.color = '#34d399'; }
    } else {
        if (btn) { btn.textContent = 'Fallito'; btn.style.background = '#dc2626'; }
        if (st) { st.textContent = 'Errore'; st.style.color = '#f87171'; }
    }
}

function rwAddLog(message, type) {
    var container = document.getElementById('rwLogContainer');
    if (!container) return;

    var colors = {
        'success': '#34d399', 'error': '#f87171', 'warning': '#fbbf24',
        'step': '#818cf8', 'detail': '#94a3b8', 'info': '#94a3b8'
    };

    var div = document.createElement('div');
    div.style.color = colors[type] || '#94a3b8';
    div.style.padding = '2px 0';
    div.textContent = message;
    container.appendChild(div);
    container.scrollTop = container.scrollHeight;
}

function rwResetAllBtns() {
    rwActiveIds.forEach(function(pid) {
        var btn = document.getElementById('rw-btn-' + pid);
        if (btn && !btn.disabled) {
            btn.textContent = 'Riscrivi';
            btn.style.opacity = '1';
            btn.disabled = false;
        }
    });
    var bulkBtn = document.getElementById('rwBulkBtn');
    if (bulkBtn) { bulkBtn.disabled = false; bulkBtn.style.opacity = '1'; bulkBtn.textContent = 'Riscrivi selezionati (' + document.querySelectorAll('.rw-checkbox:checked').length + ')'; }
    rwActiveIds = [];
}

function rwResetLog() {
    if (!confirm('Sei sicuro? Questo resettera\' il log delle riscritture e permettera\' di riscrivere nuovamente tutti i post.')) return;

    fetch('dashboard.php?tab=rewrite&action=reset_rewrite_log&csrf_token=<?= $csrfToken ?>')
        .then(function(r) { return r.text(); })
        .then(function() { location.reload(); });
}

// ============================================================
// GENERAZIONE ARTICOLO ASINCRONA (Content Hub)
// ============================================================
var genPolling = null;
var genOffset = 0;

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.generate-btn').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            var keyword = this.getAttribute('data-keyword');
            var topic = this.getAttribute('data-topic');
            var index = this.getAttribute('data-index');
            startGeneration(keyword, topic, index, this);
        });
    });
});

function startGeneration(keyword, topic, index, btn) {
    if (!keyword) return;
    
    var container = document.getElementById('generate-container-' + index) || btn.closest('.generate-container');
    var progressDiv = container.querySelector('.generate-progress');
    var logsDiv = container.querySelector('.progress-logs');
    var fillDiv = container.querySelector('.progress-fill');
    
    // Disabilita bottone e mostra progresso
    btn.disabled = true;
    btn.textContent = 'Generazione in corso...';
    progressDiv.style.display = 'block';
    logsDiv.innerHTML = '';
    genOffset = 0;
    
    // Avvia polling
    if (genPolling) clearInterval(genPolling);
    genPolling = setInterval(function() { pollGeneration(index, btn, logsDiv, fillDiv, progressDiv); }, 800);
    
    // Avvia generazione
    var url = 'generate_stream.php?action=start&keyword=' + encodeURIComponent(keyword) + '&topic=' + encodeURIComponent(topic);
    fetch(url, { credentials: 'same-origin' })
        .then(function(r) { 
            if (!r.ok) {
                throw new Error('HTTP ' + r.status + ': ' + r.statusText);
            }
            return r.text(); 
        })
        .catch(function(err) {
            logsDiv.innerHTML += '<div style="color:#f87171;">Errore: ' + err.message + '</div>';
            btn.disabled = false;
            btn.textContent = '✨ Riprova';
            if (genPolling) clearInterval(genPolling);
        });
}

function pollGeneration(index, btn, logsDiv, fillDiv, progressDiv) {
    fetch('generate_stream.php?action=poll&offset=' + genOffset, { credentials: 'same-origin' })
        .then(function(r) { 
            if (!r.ok) {
                throw new Error('HTTP ' + r.status);
            }
            return r.json(); 
        })
        .then(function(data) {
            if (data.error) {
                logsDiv.innerHTML += '<div style="color:#f87171;padding:2px 0;">Errore: ' + data.error + '</div>';
                clearInterval(genPolling);
                genPolling = null;
                btn.disabled = false;
                btn.textContent = '✨ Riprova';
                return;
            }
            if (data.lines && data.lines.length > 0) {
                data.lines.forEach(function(line) {
                    var event = line.event || '';
                    if (event === 'log') {
                        var type = line.type || 'info';
                        var colors = { 'success': '#34d399', 'error': '#f87171', 'warning': '#fbbf24', 'detail': '#94a3b8', 'info': '#94a3b8' };
                        var color = colors[type] || '#94a3b8';
                        logsDiv.innerHTML += '<div style="color:' + color + ';padding:2px 0;">' + (line.message || '') + '</div>';
                        logsDiv.scrollTop = logsDiv.scrollHeight;
                    } else if (event === 'section') {
                        logsDiv.innerHTML += '<div style="color:#818cf8;font-weight:600;margin-top:8px;padding:2px 0;">▶ ' + (line.title || '') + '</div>';
                        logsDiv.scrollTop = logsDiv.scrollHeight;
                    } else if (event === 'summary') {
                        logsDiv.innerHTML += '<div style="color:#34d399;font-weight:600;margin-top:8px;padding:2px 0;">✓ Completato: ' + (line.title || '') + '</div>';
                        if (line.wp_url) {
                            logsDiv.innerHTML += '<div style="color:#34d399;padding:2px 0;"><a href="' + line.wp_url + '" target="_blank" style="color:#34d399;">Visualizza articolo</a></div>';
                        }
                        logsDiv.scrollTop = logsDiv.scrollHeight;
                    }
                });
                genOffset = data.offset;
                
                // Aggiorna progress bar
                var progress = Math.min(100, (genOffset / 30) * 100);
                fillDiv.style.width = progress + '%';
            }
            
            if (data.done) {
                clearInterval(genPolling);
                genPolling = null;
                fillDiv.style.width = '100%';
                btn.textContent = 'Completato!';
                btn.style.background = '#059669';
                setTimeout(function() {
                    location.reload();
                }, 2000);
            }
        })
        .catch(function(err) {
            // Errore di rete, riprova silenziosamente per 5 volte poi mostra errore
            if (!window.genErrorCount) window.genErrorCount = 0;
            window.genErrorCount++;
            if (window.genErrorCount > 5) {
                logsDiv.innerHTML += '<div style="color:#f87171;padding:2px 0;">Errore di connessione. Ricarica la pagina e riprova.</div>';
                clearInterval(genPolling);
                genPolling = null;
                btn.disabled = false;
                btn.textContent = '✨ Riprova';
            }
        });
}

// ============================================================
// FACT CHECK
// ============================================================
var fcPolling = null;
var fcOffset  = 0;
var fcActiveIds = [];

function fcApplyFilters() {
    var search = document.getElementById('fcSearchInput') ? document.getElementById('fcSearchInput').value : '';
    var cat    = document.getElementById('fcFilterCat')    ? document.getElementById('fcFilterCat').value    : '0';
    var status = document.getElementById('fcFilterStatus') ? document.getElementById('fcFilterStatus').value : 'all';
    var url = '?tab=factcheck';
    if (cat !== '0') url += '&fccat=' + encodeURIComponent(cat);
    if (status !== 'all') url += '&fcstatus=' + encodeURIComponent(status);
    if (search) url += '&fcsearch=' + encodeURIComponent(search);
    window.location.href = url;
}
(function() {
    var si = document.getElementById('fcSearchInput');
    if (si) si.addEventListener('keydown', function(e) { if (e.key === 'Enter') { e.preventDefault(); fcApplyFilters(); } });
})();

function fcToggleSelectAll(cb) {
    document.querySelectorAll('.fc-checkbox').forEach(function(c) { c.checked = cb.checked; });
    fcUpdateBulkUI();
}
function fcUpdateBulkUI() {
    var all     = document.querySelectorAll('.fc-checkbox');
    var checked = document.querySelectorAll('.fc-checkbox:checked');
    var count   = checked.length;
    var btn     = document.getElementById('fcBulkBtn');
    var countSp = document.getElementById('fcBulkCount');
    var info    = document.getElementById('fcSelectedCount');
    var selAll  = document.getElementById('fcSelectAll');
    if (btn)    btn.style.display = count > 0 ? 'inline-block' : 'none';
    if (countSp) countSp.textContent = count;
    if (info)   info.textContent = count > 0 ? count + ' di ' + all.length + ' selezionati' : '';
    if (selAll) { selAll.checked = count === all.length && all.length > 0; selAll.indeterminate = count > 0 && count < all.length; }
}

function fcCheckSingle(postId, btn) {
    btn.disabled = true; btn.textContent = 'Verifica...'; btn.style.opacity = '0.6';
    var statusEl = document.getElementById('fc-status-' + postId);
    if (statusEl) { statusEl.textContent = 'In corso...'; statusEl.style.color = '#fbbf24'; }
    fcShowProgress();
    fcActiveIds = [postId];
    fcOffset = 0;
    fetch('factcheck_stream.php?action=start&ids=' + postId)
        .then(function() { setTimeout(function() { fcPolling = setInterval(fcPoll, 800); }, 1000); })
        .catch(function(err) { fcAddLog('Errore: ' + err, 'error'); btn.disabled = false; btn.textContent = 'Verifica'; btn.style.opacity = '1'; });
}

function fcStartSelected() {
    var checked = document.querySelectorAll('.fc-checkbox:checked');
    if (checked.length === 0) return;
    var ids = [];
    checked.forEach(function(cb) { ids.push(cb.value); });
    if (!confirm('Vuoi verificare ' + ids.length + ' articoli selezionati?')) return;
    checked.forEach(function(cb) {
        var pid = cb.value;
        var btn = document.getElementById('fc-btn-' + pid);
        if (btn) { btn.disabled = true; btn.textContent = 'In coda...'; btn.style.opacity = '0.6'; }
        var st = document.getElementById('fc-status-' + pid);
        if (st) { st.textContent = 'In coda'; st.style.color = '#fbbf24'; }
    });
    var bulkBtn = document.getElementById('fcBulkBtn');
    if (bulkBtn) { bulkBtn.disabled = true; bulkBtn.style.opacity = '0.6'; bulkBtn.textContent = 'In esecuzione...'; }
    fcShowProgress();
    fcActiveIds = ids;
    fcOffset = 0;
    fetch('factcheck_stream.php?action=start&ids=' + ids.join(','))
        .then(function() { setTimeout(function() { fcPolling = setInterval(fcPoll, 800); }, 1000); })
        .catch(function(err) { fcAddLog('Errore: ' + err, 'error'); fcResetAllBtns(); });
}

function fcShowProgress() {
    var card = document.getElementById('fcProgressCard');
    if (card) { card.style.display = ''; card.scrollIntoView({behavior:'smooth', block:'nearest'}); }
    var log = document.getElementById('fcLogContainer');
    if (log) log.innerHTML = '';
    var summary = document.getElementById('fcSummary');
    if (summary) summary.style.display = 'none';
    var bar = document.getElementById('fcProgressBar');
    if (bar) bar.style.width = '0%';
    var pct = document.getElementById('fcProgressPercent');
    if (pct) pct.textContent = '0%';
}

function fcPoll() {
    fetch('factcheck_stream.php?action=poll&offset=' + fcOffset)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            fcOffset = data.offset;
            data.lines.forEach(function(line) {
                var ev = line.event || '';
                if (ev === 'log') {
                    fcAddLog(line.message || '', line.type || 'info');
                } else if (ev === 'section') {
                    fcAddLog('--- ' + (line.title || '') + ' ---', 'step');
                } else if (ev === 'result') {
                    fcUpdateItemStatus(line.post_id, line.status, line.score, line.issues);
                } else if (ev === 'summary') {
                    var summaryEl = document.getElementById('fcSummary');
                    if (summaryEl) {
                        summaryEl.style.display = '';
                        var issuesTxt = line.issues > 0 ? '<strong style="color:#fca5a5;">' + line.issues + ' con problemi</strong>' : '<strong style="color:#34d399;">nessun problema</strong>';
                        summaryEl.innerHTML = '<div style="display:flex;gap:24px;flex-wrap:wrap;">'
                            + '<div><span style="color:#64748b;">Totale:</span> <strong style="color:#e2e8f0;">' + (line.total || 0) + '</strong></div>'
                            + '<div><span style="color:#64748b;">Verificati:</span> <strong style="color:#34d399;">' + (line.success || 0) + '</strong></div>'
                            + '<div><span style="color:#64748b;">Risultato:</span> ' + issuesTxt + '</div>'
                            + '<div><span style="color:#64748b;">Falliti:</span> <strong style="color:#f87171;">' + (line.failed || 0) + '</strong></div>'
                            + '</div>';
                    }
                    var bar = document.getElementById('fcProgressBar');
                    if (bar) bar.style.width = '100%';
                    var pct = document.getElementById('fcProgressPercent');
                    if (pct) pct.textContent = '100%';
                }
            });
            if (data.done) {
                clearInterval(fcPolling); fcPolling = null;
                fcAddLog('Fact-check completato!', 'success');
                fcResetAllBtns();
            }
        })
        .catch(function() {});
}

function fcUpdateItemStatus(postId, status, score, issuesCount) {
    var btn = document.getElementById('fc-btn-' + postId);
    var st  = document.getElementById('fc-status-' + postId);
    var item = document.getElementById('fc-item-' + postId);
    if (status === 'clean') {
        if (btn) { btn.textContent = 'Verificato'; btn.style.background = '#059669'; btn.disabled = false; btn.style.opacity = '1'; }
        if (st)  { st.textContent = 'OK ' + score + '/10'; st.style.color = '#34d399'; }
    } else if (status === 'issues_found') {
        if (btn) { btn.textContent = 'Ri-verifica'; btn.style.background = '#b45309'; btn.disabled = false; btn.style.opacity = '1'; }
        if (st)  { st.textContent = '⚠ ' + issuesCount + ' problemi — score ' + score + '/10'; st.style.color = '#fca5a5'; }
    } else {
        if (btn) { btn.textContent = 'Fallito'; btn.style.background = '#dc2626'; btn.disabled = false; btn.style.opacity = '1'; }
        if (st)  { st.textContent = 'Errore'; st.style.color = '#f87171'; }
    }
}

function fcAddLog(message, type) {
    var container = document.getElementById('fcLogContainer');
    if (!container) return;
    var colors = { 'success':'#34d399','error':'#f87171','warning':'#fbbf24','step':'#818cf8','detail':'#94a3b8','info':'#94a3b8' };
    var div = document.createElement('div');
    div.style.color   = colors[type] || '#94a3b8';
    div.style.padding = '2px 0';
    div.textContent   = message;
    container.appendChild(div);
    container.scrollTop = container.scrollHeight;
}

function fcResetAllBtns() {
    fcActiveIds.forEach(function(pid) {
        var btn = document.getElementById('fc-btn-' + pid);
        if (btn && btn.textContent === 'Verifica...' ) { btn.textContent = 'Verifica'; btn.style.opacity = '1'; btn.disabled = false; }
    });
    var bulkBtn = document.getElementById('fcBulkBtn');
    if (bulkBtn) { bulkBtn.disabled = false; bulkBtn.style.opacity = '1'; bulkBtn.textContent = 'Verifica selezionati (' + document.querySelectorAll('.fc-checkbox:checked').length + ')'; }
    fcActiveIds = [];
}

function fcResetLog() {
    if (!confirm('Resettare il log dei fact-check? I post potranno essere riverificati.')) return;
    fetch('dashboard.php?tab=factcheck&action=reset_factcheck_log&csrf_token=<?= $csrfToken ?>')
        .then(function(r) { return r.text(); })
        .then(function() { location.reload(); });
}

// --- Esegui Custom ---
function openCustomRun() {
    document.getElementById('customRunModal').style.display = 'flex';
    document.getElementById('customTopicInput').focus();
}
function closeCustomRun() {
    document.getElementById('customRunModal').style.display = 'none';
    document.getElementById('customTopicInput').value = '';
}
function startCustomRun() {
    var topic = document.getElementById('customTopicInput').value.trim();
    if (!topic) { alert('Inserisci un topic.'); return; }
    window.location.href = 'run.php?custom_topic=' + encodeURIComponent(topic);
}
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeCustomRun();
});
</script>

<!-- Modal Esegui Custom -->
<div id="customRunModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;align-items:center;justify-content:center;padding:16px;">
    <div style="background:#1e293b;border:1px solid #334155;border-radius:16px;padding:24px;width:100%;max-width:500px;">
        <h3 style="color:#818cf8;margin-bottom:8px;">🎯 Esegui Custom</h3>
        <p style="color:#64748b;font-size:13px;margin-bottom:20px;">Specifica un topic unico per questa singola esecuzione. Le fasi 1 e 2 (keyword e filtro) vengono saltate.</p>
        <input id="customTopicInput" type="text" placeholder="Es: significato sognare di volare"
            style="width:100%;padding:12px;background:#0f172a;border:1px solid #334155;border-radius:8px;color:#e2e8f0;font-size:16px;margin-bottom:16px;"
            onkeydown="if(event.key==='Enter') startCustomRun()">
        <div style="display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap;">
            <button type="button" class="btn btn-sm" style="background:#334155;color:#e2e8f0;min-height:44px;" onclick="closeCustomRun()">Annulla</button>
            <button type="button" class="btn btn-primary" style="min-height:44px;" onclick="startCustomRun()">▶️ Avvia</button>
        </div>
    </div>
</div>

</body>
</html>
