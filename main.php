<?php

/**
 * AutoPilot - Script principale Orchestratore
 * Eseguire via cron ogni ora:
 * 0 * * * * /usr/bin/php /path/to/main.php
 */

// Carica configurazione
$config = require __DIR__ . '/config.php';

// Carica classi
require __DIR__ . '/src/AutocompleteFetcher.php';
require __DIR__ . '/src/TopicFilter.php';
require __DIR__ . '/src/ContentGenerator.php';
require __DIR__ . '/src/ImageGenerator.php';
require __DIR__ . '/src/RSSFeedBuilder.php';
require __DIR__ . '/src/SocialFeedBuilder.php';
require __DIR__ . '/src/WordPressPublisher.php';

// Funzione di log
function logMsg(string $message): void
{
    global $config;
    
    // Crea la cartella logs se non esiste
    $logDir = dirname($config['log_path']);
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    @file_put_contents($config['log_path'], $line, FILE_APPEND | LOCK_EX);
    echo $line;
}

// Funzione per ottenere info sul provider in uso
function getProviderDisplayName(string $provider): string
{
    $names = [
        'openai' => 'OpenAI',
        'gemini' => 'Google Gemini',
        'kimi'   => 'Kimi (Moonshot AI)'
    ];
    return $names[$provider] ?? $provider;
}

// Lock file per evitare esecuzioni concorrenti (nella directory locale, compatibile con hosting condiviso)
$lockFile = __DIR__ . '/data/.lock';
$lockFp = fopen($lockFile, 'w');
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    logMsg('SKIP: altra esecuzione in corso');
    fclose($lockFp);
    exit(0);
}

try {
    logMsg('=== INIZIO ESECUZIONE ===');

    // Pulizia file di progresso vecchi
    foreach (['/data/.run_progress.jsonl', '/data/.rewrite_progress.jsonl'] as $pf) {
        $pfPath = __DIR__ . $pf;
        if (file_exists($pfPath) && filesize($pfPath) > 1048576) { // > 1MB
            @file_put_contents($pfPath, '');
            logMsg("File progresso pulito: {$pf}");
        }
    }

    // Scheduling intelligente: adatta max_articles_per_run in base all'ora
    if (!empty($config['smart_scheduling_enabled'])) {
        $currentHour = (int)date('G');
        $peakHours = $config['smart_scheduling_peak_hours'] ?? [8,9,10,12,13,18,19,20,21];
        if (in_array($currentHour, $peakHours)) {
            $config['max_articles_per_run'] = intval($config['smart_scheduling_peak_articles'] ?? 3);
            logMsg("Scheduling intelligente: ora di punta ({$currentHour}:00) - max " . $config['max_articles_per_run'] . " articoli");
        } else {
            $config['max_articles_per_run'] = intval($config['smart_scheduling_offpeak_articles'] ?? 1);
            logMsg("Scheduling intelligente: ora non di punta ({$currentHour}:00) - max " . $config['max_articles_per_run'] . " articoli");
        }
    }

    // 1. Recupera keyword (Google Autocomplete oppure lista manuale)
    $keywordSource = $config['keyword_source'] ?? 'google';

    if ($keywordSource === 'manual') {
        logMsg('=== FASE 1: KEYWORD MANUALI ===');
        $suggerimenti = array_values(array_filter(array_map('trim', $config['manual_keywords'] ?? [])));
        logMsg('Keyword manuali caricate: ' . count($suggerimenti));
        if (!empty($suggerimenti)) {
            logMsg('Primi 5: ' . implode(', ', array_slice($suggerimenti, 0, 5)));
        }
    } else {
        logMsg('=== FASE 1: RECUPERO SUGGERIMENTI GOOGLE AUTOCOMPLETE ===');
        $fetcher = new AutocompleteFetcher($config);
        $suggerimenti = $fetcher->fetch();
        logMsg('Trovati ' . count($suggerimenti) . ' suggerimenti totali');
        if (!empty($suggerimenti)) {
            logMsg('Primi 5 suggerimenti: ' . implode(', ', array_slice($suggerimenti, 0, 5)));
        }
    }

    if (empty($suggerimenti)) {
        logMsg('Nessuna keyword trovata. Fine.');
    }

    // 2. Filtra topic nuovi
    $nuovi = [];
    if (!empty($suggerimenti)) {
        logMsg('=== FASE 2: FILTRO TOPIC NUOVI ===');
        $filter = new TopicFilter($config);
        $filter->setLogCallback(function(string $message, string $_type) {
            logMsg($message);
        });
        $nuovi = $filter->filter($suggerimenti);
        logMsg('Topic nuovi da elaborare: ' . count($nuovi));
        if (!empty($nuovi)) {
            logMsg('Topic da elaborare: ' . implode(', ', array_slice($nuovi, 0, 5)) . (count($nuovi) > 5 ? '...' : ''));
        }

        if (empty($nuovi)) {
            logMsg('Nessun topic nuovo. Fine.');
        }
    }

    // 3. Genera contenuti e aggiorna feed
    if (!empty($nuovi)) {
    logMsg('=== FASE 3: GENERAZIONE CONTENUTI ===');
    $defaultProvider = $config['default_provider'] ?? 'openai';
    logMsg('Provider predefinito: ' . getProviderDisplayName($defaultProvider));
    
    $generator = new ContentGenerator($config);
    $generator->setLogCallback(function(string $message, string $type) {
        logMsg($message);
    });
    $imageGen = new ImageGenerator($config);
    $feedBuilder = new RSSFeedBuilder($config);
    $socialFeedBuilder = (!empty($config['social_feeds_enabled'])) ? new SocialFeedBuilder($config) : null;
    $generati = 0;

    if ($imageGen->isEnabled()) {
        logMsg('Generazione immagini fal.ai ATTIVA (modello: ' . ($config['fal_model_id'] ?? 'fal-ai/flux/schnell') . ')');
    } else {
        logMsg('Generazione immagini fal.ai DISATTIVATA');
    }
    
    if ($socialFeedBuilder !== null) {
        logMsg('Feed social ATTIVI (Facebook + X/Twitter)');
    }

    $wpPublisher = new WordPressPublisher($config);
    if ($wpPublisher->isEnabled()) {
        $wpPublisher->setLogCallback(function(string $message, string $_type) {
            logMsg($message);
        });
        logMsg('WordPress Publisher ATTIVO (auto-publish: ' . (!empty($config['wp_auto_publish']) ? 'SI' : 'NO') . ')');
    }

    // Link Building (Smart Link Building con analisi semantica)
    require __DIR__ . '/src/LinkBuilder.php';
    require __DIR__ . '/src/SmartLinkBuilder.php';
    $linkBuilder = new SmartLinkBuilder($config);
    if ($linkBuilder->isEnabled()) {
        $linkBuilder->setLogCallback(function(string $message, string $_type) {
            logMsg($message);
        });
        $generator->setLinkBuilder($linkBuilder);
        logMsg('Link Building ATTIVO (interni: ' . (!empty($config['link_internal_enabled']) ? 'SI' : 'NO') . ', esterni: ' . (!empty($config['link_external_enabled']) ? 'SI' : 'NO') . ')');
    }

    foreach ($nuovi as $topic) {
        logMsg("--- Inizio elaborazione topic: \"{$topic}\" ---");

        // Check pertinenza via AI
        $relevanceResult = $generator->isRelevantDetailed($topic);
        if (!$relevanceResult['relevant']) {
            logMsg("  [RELEVANCE] Topic non pertinente alla nicchia (provider: {$relevanceResult['provider']}, {$relevanceResult['time_ms']}ms) - SKIPPATO");
            $filter->markSkipped($topic);
            continue;
        }
        logMsg("  [RELEVANCE] Topic pertinente (provider: {$relevanceResult['provider']}, {$relevanceResult['time_ms']}ms)");

        $filter->markInProgress($topic);

        logMsg("  [GENERAZIONE] Avvio generazione articolo...");
        $articolo = $generator->generate($topic);

        if ($articolo !== null) {
            $body = $articolo['body'];
            $featuredImage = null;
            $providerUsed = $articolo['provider'] ?? 'sconosciuto';
            $timeMs = $articolo['time_ms'] ?? 0;
            
            logMsg("  [GENERAZIONE] SUCCESSO - Provider: " . getProviderDisplayName($providerUsed) . " | Tempo: {$timeMs}ms");
            logMsg("  [GENERAZIONE] Titolo generato: \"{$articolo['title']}\"");

            // Genera immagine featured (solo per il tag image del feed, non nel contenuto)
            if ($imageGen->isEnabled()) {
                logMsg("  [IMMAGINI] Generazione immagine featured...");
                $featuredImage = $imageGen->generateFeaturedImage($articolo['title'], $topic);
                if ($featuredImage !== null) {
                    logMsg("  [IMMAGINI] Featured generata: {$featuredImage['url']}");
                } else {
                    logMsg("  [IMMAGINI] WARN: impossibile generare immagine featured");
                }
            }

            // Genera e inserisci immagini inline nel body (solo immagini inline, non la featured)
            if ($imageGen->isInlineEnabled()) {
                logMsg("  [IMMAGINI] Generazione immagini inline...");
                $body = $imageGen->insertInlineImages($articolo['title'], $body, $topic);
                logMsg("  [IMMAGINI] Immagini inline inserite nel contenuto");
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
                logMsg("  [WORDPRESS] Pubblicazione automatica su WordPress...");

                // Determina categoria con AI
                $wpCategories = $wpPublisher->getCategories();
                $wpCategoryName = $generator->suggestCategory($articolo['title'], $topic, $wpCategories);
                logMsg("  [WORDPRESS] Categoria: {$wpCategoryName}");

                $metaDescription = $articolo['meta_description'] ?? ContentGenerator::extractMetaDescription($body);
                logMsg("  [WORDPRESS] Meta description: \"" . mb_substr($metaDescription, 0, 80) . "...\"");
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
                    logMsg("  [WORDPRESS] Pubblicato! ID: {$wpResult['post_id']} | URL: {$wpPostUrl}");

                    // Segna l'item come pubblicato nel feed
                    $itemIdx = $feedBuilder->findItemIndex($articolo['title']);
                    if ($itemIdx !== null) {
                        $feedBuilder->markAsPublished($itemIdx, $wpResult['post_id'], $wpPostUrl);
                    }

                    // Linking bidirezionale: aggiorna vecchi articoli con link al nuovo
                    if ($linkBuilder->isEnabled() && !empty($config['link_internal_enabled'])) {
                        logMsg("  [LINK] Linking bidirezionale: aggiornamento vecchi articoli...");
                        $biUpdated = $linkBuilder->updateOldPostsWithLink(
                            $wpResult['post_id'], $wpPostUrl, $articolo['title'], $topic
                        );
                        logMsg("  [LINK] Linking bidirezionale: {$biUpdated} articoli aggiornati");
                    }
                } else {
                    logMsg("  [WORDPRESS] Errore pubblicazione su WordPress");
                }
            }

            // Feed social: solo se l'articolo e' stato pubblicato su WordPress
            if ($socialFeedBuilder !== null && $wpPostUrl !== null) {
                logMsg("  [SOCIAL] Generazione copy per social media...");
                $socialFeedBuilder->addItem($articolo['title'], $featuredImage['url'] ?? null, $generator, $wpPostUrl);
                logMsg("  [SOCIAL] Feed social aggiornati con URL: {$wpPostUrl}");
            } elseif ($socialFeedBuilder !== null) {
                logMsg("  [SOCIAL] Feed social NON aggiornati (articolo non pubblicato su WordPress)");
            }

            $filter->markCompleted($topic);
            $generati++;
            logMsg("  [FEED] Articolo aggiunto al feed RSS");
            logMsg("--- Fine elaborazione topic: OK ---");
        } else {
            $filter->markFailed($topic);
            logMsg("  [GENERAZIONE] ERRORE: impossibile generare articolo (tutti i provider hanno fallito)");
            logMsg("--- Fine elaborazione topic: ERRORE ---");
        }

        // Pausa tra generazioni per rispettare rate limits
        sleep(2);
    }

    logMsg("Generati {$generati} articoli. Feed contiene " . $feedBuilder->getItemCount() . " item totali.");
    } // fine if (!empty($nuovi))

    logMsg('=== FINE ESECUZIONE ===');

} catch (Throwable $e) {
    logMsg('ERRORE FATALE: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
} finally {
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    @unlink($lockFile);
}
