<?php

/**
 * Endpoint per esecuzione con progress tracking.
 *
 * Due modalita':
 *   ?action=start   -> Avvia l'esecuzione, scrive progress su file JSONL
 *   ?action=poll&offset=N -> Ritorna le righe di log dal offset N in poi
 *
 * Il frontend (run.php) chiama start, poi fa polling ogni 500ms.
 */

session_start();

$config = require __DIR__ . '/config.php';

if (empty($_SESSION['dashboard_auth'])) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['error' => 'Non autenticato']);
    exit;
}

$action = $_GET['action'] ?? '';
$progressFile = __DIR__ . '/data/.run_progress.jsonl';

// ============================================================
// POLL: ritorna le righe nuove dal file di progresso
// ============================================================
if ($action === 'poll') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache');

    $offset = max(0, intval($_GET['offset'] ?? 0));
    $lines = [];
    $done = false;

    if (file_exists($progressFile)) {
        $fp = @fopen($progressFile, 'r');
        if ($fp) {
            // Salta le prime N righe gia' lette
            $currentLine = 0;
            while (!feof($fp)) {
                $line = fgets($fp);
                if ($line === false) break;
                $currentLine++;
                if ($currentLine <= $offset) continue;
                $decoded = json_decode(trim($line), true);
                if ($decoded !== null) {
                    $lines[] = $decoded;
                    if (($decoded['event'] ?? '') === 'done') {
                        $done = true;
                    }
                }
            }
            fclose($fp);
        }
    }

    echo json_encode([
        'lines'  => $lines,
        'offset' => $offset + count($lines),
        'done'   => $done,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================
// START: avvia l'esecuzione effettiva
// ============================================================
if ($action !== 'start') {
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(['error' => 'Azione non valida. Usa ?action=start oppure ?action=poll']);
    exit;
}

// Impedisci timeout
set_time_limit(600);
ignore_user_abort(true);

// Chiudi sessione subito per non bloccare il polling
session_write_close();

// Pulisci file progress precedente
@file_put_contents($progressFile, '');

// Chiudi la connessione HTTP immediatamente.
// fastcgi_finish_request() e' il metodo affidabile su PHP-FPM/nginx.
// Su Apache/mod_php usiamo Connection: close come fallback.
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
echo '<!-- started -->';

if (function_exists('fastcgi_finish_request')) {
    // PHP-FPM: chiude la connessione per davvero
    fastcgi_finish_request();
} else {
    // Apache/mod_php fallback
    header('Content-Length: 16');
    header('Connection: close');
    if (ob_get_level() > 0) ob_end_flush();
    flush();
}

// Da qui in poi il browser ha ricevuto la risposta e non aspetta piu'.
// Il processo PHP continua in background.

require __DIR__ . '/src/AutocompleteFetcher.php';
require __DIR__ . '/src/TopicFilter.php';
require __DIR__ . '/src/ContentGenerator.php';
require __DIR__ . '/src/ImageGenerator.php';
require __DIR__ . '/src/RSSFeedBuilder.php';
require __DIR__ . '/src/LinkBuilder.php';
require __DIR__ . '/src/SocialFeedBuilder.php';
require __DIR__ . '/src/WordPressPublisher.php';

/**
 * Scrive un evento di log nel file di progresso.
 */
function writeProgress(string $event, array $data): void
{
    global $progressFile, $config;

    $data['event'] = $event;
    $data['ts'] = date('H:i:s');
    $line = json_encode($data, JSON_UNESCAPED_UNICODE) . "\n";
    @file_put_contents($progressFile, $line, FILE_APPEND | LOCK_EX);

    // Scrivi anche nel file log
    if (isset($data['message'])) {
        $type = strtoupper($data['type'] ?? 'INFO');
        $logLine = '[' . date('Y-m-d H:i:s') . '] [' . $type . '] ' . $data['message'] . "\n";
        @file_put_contents($config['log_path'], $logLine, FILE_APPEND | LOCK_EX);
    }
}

function logEvent(string $message, string $type = 'info'): void
{
    writeProgress('log', ['message' => $message, 'type' => $type]);
}

function sectionEvent(string $title): void
{
    writeProgress('section', ['title' => $title]);
}

function summaryEvent(array $stats): void
{
    writeProgress('summary', $stats);
}

function doneEvent(): void
{
    writeProgress('done', []);
}

function getProviderDisplayName(string $provider): string
{
    $names = [
        'openai'     => 'OpenAI',
        'gemini'     => 'Google Gemini',
        'openrouter' => 'OpenRouter',
    ];
    return $names[$provider] ?? $provider;
}

// Crea cartella logs se necessario
$logDir = dirname($config['log_path']);
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}

// === ESECUZIONE ===

// Topic custom passato da "Esegui Custom" (bypassa fasi 1 e 2)
$customTopic = trim($_GET['custom_topic'] ?? '');

try {
    logEvent('=== INIZIO ESECUZIONE ===', 'success');

    // Pulizia file rewrite progress se troppo grande
    $rwProgressFile = __DIR__ . '/data/.rewrite_progress.jsonl';
    if (file_exists($rwProgressFile) && filesize($rwProgressFile) > 1048576) {
        @file_put_contents($rwProgressFile, '');
        logEvent('File progresso riscrittura pulito (>1MB)', 'detail');
    }

    // Scheduling intelligente
    if (!empty($config['smart_scheduling_enabled'])) {
        $currentHour = (int)date('G');
        $peakHours = $config['smart_scheduling_peak_hours'] ?? [8,9,10,12,13,18,19,20,21];
        if (in_array($currentHour, $peakHours)) {
            $config['max_articles_per_run'] = intval($config['smart_scheduling_peak_articles'] ?? 3);
            logEvent("Scheduling intelligente: ora di punta ({$currentHour}:00) - max " . $config['max_articles_per_run'] . " articoli", 'detail');
        } else {
            $config['max_articles_per_run'] = intval($config['smart_scheduling_offpeak_articles'] ?? 1);
            logEvent("Scheduling intelligente: ora non di punta ({$currentHour}:00) - max " . $config['max_articles_per_run'] . " articoli", 'detail');
        }
    }

    logEvent('Provider predefinito: ' . getProviderDisplayName($config['default_provider'] ?? 'openai'), 'detail');
    if (($config['default_provider'] ?? '') === 'openrouter') {
        logEvent('Modello OpenRouter: ' . ($config['openrouter_model'] ?? 'openai/gpt-4o-mini'), 'detail');
    }

    // Inizializza sempre il filter (serve per markInProgress/markCompleted anche in modalità custom)
    $dbDir = dirname($config['db_path']);
    if (!is_dir($dbDir)) {
        @mkdir($dbDir, 0755, true);
    }
    $filter = new TopicFilter($config);
    $filter->setLogCallback(function(string $message, string $type) {
        logEvent($message, $type);
    });

    // --- MODALITÀ CUSTOM (bypassa fasi 1 e 2) ---
    if ($customTopic !== '') {
        sectionEvent('ESECUZIONE CUSTOM');
        logEvent('Topic specificato manualmente: ' . $customTopic, 'success');
        $nuovi = [$customTopic];
    } else {
    // --- FASE 1 ---
    $keywordSource = $config['keyword_source'] ?? 'google';

    if ($keywordSource === 'manual') {
        sectionEvent('FASE 1: Keyword Manuali');
        $suggerimenti = array_values(array_filter(array_map('trim', $config['manual_keywords'] ?? [])));
        $count = count($suggerimenti);
        logEvent("Keyword manuali caricate: {$count}", 'success');
    } else {
        sectionEvent('FASE 1: Recupero Suggerimenti Google Autocomplete');
        logEvent('Inizializzazione AutocompleteFetcher...', 'detail');

        $fetcher = new AutocompleteFetcher($config);
        $suggerimenti = $fetcher->fetch();
        $count = count($suggerimenti);
        logEvent("Trovati {$count} suggerimenti totali", 'success');
    }

    if (!empty($suggerimenti)) {
        logEvent('Primi 5:', 'detail');
        foreach (array_slice($suggerimenti, 0, 5) as $i => $s) {
            logEvent('  ' . ($i + 1) . '. ' . $s, 'detail');
        }
    }

    if (empty($suggerimenti)) {
        logEvent('Nessuna keyword trovata. Fine.', 'warning');
    }

    // --- FASE 2 ---
    $nuovi = [];
    if (!empty($suggerimenti)) {
        sectionEvent('FASE 2: Filtro Topic Nuovi');
        logEvent('Database path: ' . $config['db_path'], 'detail');

        $nuovi = $filter->filter($suggerimenti);
        logEvent('Topic nuovi da elaborare: ' . count($nuovi), 'success');

        if (!empty($nuovi)) {
            logEvent('Topic da elaborare:', 'detail');
            foreach ($nuovi as $i => $t) {
                logEvent('  ' . ($i + 1) . '. ' . $t, 'detail');
            }
        }

        if (empty($nuovi)) {
            logEvent('Nessun topic nuovo. Fine.', 'warning');
        }
    }
    } // fine else modalità normale

    // --- FASE 3 ---
    if (!empty($nuovi)) {
    sectionEvent('FASE 3: Generazione Contenuti');

    $generator = new ContentGenerator($config);
    // Collega il logging del generator al progress file
    $generator->setLogCallback(function(string $message, string $type) {
        logEvent($message, $type);
    });
    $imageGen = new ImageGenerator($config);
    $feedBuilder = new RSSFeedBuilder($config);
    $socialFeedBuilder = (!empty($config['social_feeds_enabled'])) ? new SocialFeedBuilder($config) : null;

    $wpPublisher = new WordPressPublisher($config);
    if ($wpPublisher->isEnabled()) {
        $wpPublisher->setLogCallback(function(string $message, string $type) {
            logEvent($message, $type);
        });
        logEvent('WordPress Publisher ATTIVO (auto-publish: ' . (!empty($config['wp_auto_publish']) ? 'SI' : 'NO') . ')', 'detail');
    }

    // Link Building (Smart Link Building con analisi semantica)
    require_once __DIR__ . '/src/SmartLinkBuilder.php';
    $linkBuilder = new SmartLinkBuilder($config);
    if ($linkBuilder->isEnabled()) {
        $linkBuilder->setLogCallback(function(string $message, string $type) {
            logEvent($message, $type);
        });
        $generator->setLinkBuilder($linkBuilder);
        logEvent('Link Building ATTIVO (interni: ' . (!empty($config['link_internal_enabled']) ? 'SI' : 'NO') . ', esterni: ' . (!empty($config['link_external_enabled']) ? 'SI' : 'NO') . ')', 'detail');
    }

    logEvent('ContentGenerator inizializzato', 'detail');
    logEvent('ImageGenerator - Enabled: ' . ($imageGen->isEnabled() ? 'SI' : 'NO'), 'detail');
    if (!empty($config['title_prompt_template'])) {
        logEvent('Prompt titolo dedicato: ATTIVO', 'detail');
    }
    if ($socialFeedBuilder !== null) {
        logEvent('SocialFeedBuilder ATTIVO (Facebook + X/Twitter)', 'detail');
    }

    $generati = 0;
    $errori = 0;
    $immaginiGen = 0;

    foreach ($nuovi as $topic) {
        logEvent('--- Elaborazione: "' . $topic . '" ---', 'step');

        $topicCategory = ContentGenerator::classifyTopic($topic);
        logEvent('[GENERAZIONE] Categoria topic: ' . $topicCategory, 'detail');

        // Check pertinenza via AI
        logEvent('[RELEVANCE] Verifica pertinenza alla nicchia...', 'detail');
        $relevanceResult = $generator->isRelevantDetailed($topic);
        if (!$relevanceResult['relevant']) {
            logEvent('[RELEVANCE] Topic non pertinente (provider: ' . $relevanceResult['provider'] . ', ' . $relevanceResult['time_ms'] . 'ms) - SKIPPATO', 'warning');
            $filter->markSkipped($topic);
            continue;
        }
        logEvent('[RELEVANCE] Topic pertinente (provider: ' . $relevanceResult['provider'] . ', ' . $relevanceResult['time_ms'] . 'ms)', 'success');

        $filter->markInProgress($topic);
        logEvent('[GENERAZIONE] Avvio generazione articolo...', 'detail');

        $articolo = $generator->generate($topic);

        if ($articolo !== null) {
            $body = $articolo['body'];
            $featuredImage = null;
            $providerUsed = $articolo['provider'] ?? 'sconosciuto';
            $timeMs = $articolo['time_ms'] ?? 0;

            logEvent('[GENERAZIONE] SUCCESSO - Provider: ' . getProviderDisplayName($providerUsed) . ' | Tempo: ' . $timeMs . 'ms', 'success');
            logEvent('[GENERAZIONE] Titolo: "' . $articolo['title'] . '"', 'success');

            // Immagine featured
            if ($imageGen->isEnabled()) {
                logEvent('[IMMAGINI] Generazione immagine featured...', 'detail');
                $featuredImage = $imageGen->generateFeaturedImage($articolo['title'], $topic);
                if ($featuredImage !== null) {
                    $immaginiGen++;
                    logEvent('[IMMAGINI] Featured generata: ' . $featuredImage['url'], 'success');
                } else {
                    logEvent('[IMMAGINI] WARN: impossibile generare featured', 'warning');
                }
            }

            // Immagini inline
            if ($imageGen->isInlineEnabled()) {
                logEvent('[IMMAGINI] Generazione immagini inline...', 'detail');
                $body = $imageGen->insertInlineImages($articolo['title'], $body, $topic);
                logEvent('[IMMAGINI] Immagini inline inserite', 'success');
            }

            // Prepara la meta description per il feed
            $metaDescription = $articolo['meta_description'] ?? '';
            if (empty($metaDescription)) {
                $metaDescription = ContentGenerator::extractMetaDescription($body);
            }
            $feedBuilder->addItem($articolo['title'], $body, $featuredImage, $metaDescription);

            // Pubblicazione su WordPress (se auto-publish attivo)
            $wpPostUrl = null;
            if ($wpPublisher->isEnabled() && !empty($config['wp_auto_publish'])) {
                logEvent('[WORDPRESS] Pubblicazione automatica su WordPress...', 'detail');

                // Determina categoria con AI
                logEvent('[WORDPRESS] Determinazione categoria...', 'detail');
                $wpCategories = $wpPublisher->getCategories();
                $wpCategoryName = $generator->suggestCategory($articolo['title'], $topic, $wpCategories);

                $metaDescription = $articolo['meta_description'] ?? ContentGenerator::extractMetaDescription($body);
                logEvent('[WORDPRESS] Meta description: "' . mb_substr($metaDescription, 0, 80) . '..."', 'detail');
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
                    logEvent('[WORDPRESS] Pubblicato! ID: ' . $wpResult['post_id'] . ' | URL: ' . $wpPostUrl, 'success');

                    // Segna l'item come pubblicato nel feed
                    $itemIdx = $feedBuilder->findItemIndex($articolo['title']);
                    if ($itemIdx !== null) {
                        $feedBuilder->markAsPublished($itemIdx, $wpResult['post_id'], $wpPostUrl);
                    }

                    // Linking bidirezionale: aggiorna vecchi articoli con link al nuovo
                    if ($linkBuilder->isEnabled() && !empty($config['link_internal_enabled'])) {
                        logEvent('[LINK] Linking bidirezionale: aggiornamento vecchi articoli...', 'detail');
                        $biUpdated = $linkBuilder->updateOldPostsWithLink(
                            $wpResult['post_id'], $wpPostUrl, $articolo['title'], $topic
                        );
                        logEvent("[LINK] Linking bidirezionale: {$biUpdated} articoli aggiornati", 'success');
                    }
                } else {
                    logEvent('[WORDPRESS] Errore pubblicazione su WordPress', 'error');
                }
            }

            // Feed social: solo se l'articolo e' stato pubblicato su WordPress
            if ($socialFeedBuilder !== null && $wpPostUrl !== null) {
                logEvent('[SOCIAL] Generazione copy per social media...', 'detail');
                $socialFeedBuilder->addItem($articolo['title'], $featuredImage['url'] ?? null, $generator, $wpPostUrl);
                logEvent('[SOCIAL] Feed social aggiornati con URL: ' . $wpPostUrl, 'success');
            } elseif ($socialFeedBuilder !== null && $wpPostUrl === null) {
                logEvent('[SOCIAL] Feed social NON aggiornati (articolo non pubblicato su WordPress)', 'detail');
            }

            $filter->markCompleted($topic);
            $generati++;
            logEvent('[FEED] Articolo aggiunto al feed RSS', 'success');
            logEvent('--- Topic completato: OK ---', 'success');
        } else {
            $filter->markFailed($topic);
            $errori++;
            logEvent('[GENERAZIONE] ERRORE: impossibile generare articolo (tutti i provider falliti)', 'error');
            logEvent('--- Topic completato: ERRORE ---', 'error');
        }

        sleep(2);
    }

    // Riepilogo
    summaryEvent([
        'generati'    => $generati,
        'errori'      => $errori,
        'immagini'    => $immaginiGen,
        'feed_totale' => $feedBuilder->getItemCount(),
    ]);
    } // fine if (!empty($nuovi))

    logEvent('=== FINE ESECUZIONE ===', 'success');

} catch (Throwable $e) {
    logEvent('ERRORE FATALE: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(), 'error');
}

doneEvent();
