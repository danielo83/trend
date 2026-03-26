<?php

/**
 * Endpoint per generazione articolo singolo con progress tracking.
 *
 * Parametri:
 *   &keyword=...     -> Keyword da generare (obbligatorio)
 *   &topic=...       -> Topic/argomento (opzionale)
 *   &action=start    -> Avvia la generazione
 *   &action=poll     -> Poll per lo stato
 */

// Disabilita visualizzazione errori per evitare di corrompere la risposta JSON
ini_set('display_errors', '0');
error_reporting(E_ALL);

session_start();

$config = require __DIR__ . '/config.php';

if (empty($_SESSION['dashboard_auth'])) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['error' => 'Non autenticato']);
    exit;
}

$action = $_GET['action'] ?? '';
$progressFile = __DIR__ . '/data/.generate_progress.jsonl';

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
// START: avvia la generazione in background
// ============================================================
if ($action !== 'start') {
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(['error' => 'Azione non valida. Usa ?action=start oppure ?action=poll']);
    exit;
}

$keyword = $_GET['keyword'] ?? '';
$topic = $_GET['topic'] ?? 'general';

if (empty($keyword)) {
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(['error' => 'Keyword non specificata']);
    exit;
}

// Impedisci timeout
set_time_limit(300); // 5 minuti
ignore_user_abort(true);

// Chiudi sessione subito per non bloccare il polling
session_write_close();

// Verifica che la cartella data sia scrivibile
if (!is_writable(dirname($progressFile))) {
    error_log("[generate_stream] ERRORE: La cartella data non è scrivibile!");
    exit;
}

// Pulisci file progress precedente
@file_put_contents($progressFile, '');

// Chiudi la connessione HTTP immediatamente - TUTTI gli header devono essere prima di qualsiasi output
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');

if (function_exists('fastcgi_finish_request')) {
    echo '<!-- started -->';
    fastcgi_finish_request();
} else {
    // In ambiente non-FPM, devi specificare Content-Length PRIMA di qualsiasi output
    header('Content-Length: 16');
    header('Connection: close');
    echo '<!-- started -->';
    if (ob_get_level() > 0) ob_end_flush();
    flush();
}

// Da qui in poi il browser ha ricevuto la risposta

require __DIR__ . '/src/ContentGenerator.php';
require __DIR__ . '/src/ImageGenerator.php';
require __DIR__ . '/src/RSSFeedBuilder.php';
require __DIR__ . '/src/WordPressPublisher.php';
require __DIR__ . '/src/LinkBuilder.php';
require __DIR__ . '/src/SmartLinkBuilder.php';

// --- Funzioni di logging ---

function writeProgress(string $event, array $data): void {
    global $progressFile;
    $data['event'] = $event;
    $data['ts'] = date('H:i:s');
    $line = json_encode($data, JSON_UNESCAPED_UNICODE) . "\n";
    @file_put_contents($progressFile, $line, FILE_APPEND | LOCK_EX);
}

function logEvent(string $message, string $type = 'info'): void {
    writeProgress('log', ['message' => $message, 'type' => $type]);
}

function sectionEvent(string $title): void {
    writeProgress('section', ['title' => $title]);
}

function doneEvent(): void {
    writeProgress('done', []);
}

// ============================================================
// ESECUZIONE GENERAZIONE
// ============================================================

try {
    sectionEvent('Generazione articolo: ' . $keyword);
    logEvent('Keyword: ' . $keyword, 'detail');
    
    // Inizializza componenti
    $generator = new ContentGenerator($config);
    $generator->setLogCallback(function(string $msg, string $type) {
        logEvent($msg, $type);
    });
    
    $imageGen = new ImageGenerator($config);
    $feedBuilder = new RSSFeedBuilder($config);
    $wpPublisher = new WordPressPublisher($config);
    
    $linkBuilder = new SmartLinkBuilder($config);
    if ($linkBuilder->isEnabled()) {
        $linkBuilder->setLogCallback(function(string $msg, string $type) {
            logEvent('[LINK] ' . $msg, $type);
        });
        $generator->setLinkBuilder($linkBuilder);
        logEvent('Link Building ATTIVO', 'detail');
    }
    
    // Genera articolo
    sectionEvent('Generazione contenuto con AI');
    $articolo = $generator->generate($keyword);
    
    if ($articolo === null) {
        logEvent('Generazione fallita', 'error');
        doneEvent();
        exit;
    }
    
    logEvent('Articolo generato: ' . $articolo['title'], 'success');
    logEvent('SEO Score: ' . ($articolo['seo_score'] ?? 'N/A') . '/100', 'detail');
    logEvent('GEO Score: ' . ($articolo['geo_score'] ?? 'N/A') . '/100', 'detail');
    
    $body = $articolo['body'];
    $featuredImage = null;
    
    // Genera immagine featured
    if ($imageGen->isEnabled()) {
        sectionEvent('Generazione immagine');
        $featuredImage = $imageGen->generateFeaturedImage($articolo['title'], $keyword);
        if ($featuredImage !== null) {
            logEvent('Immagine generata', 'success');
        } else {
            logEvent('Immagine non generata', 'warning');
        }
    }
    
    // Genera immagini inline
    if ($imageGen->isInlineEnabled()) {
        logEvent('Generazione immagini inline...', 'detail');
        $body = $imageGen->insertInlineImages($articolo['title'], $body, $keyword);
    }
    
    // Prepara meta description
    $metaDescription = $articolo['meta_description'] ?? '';
    if (empty($metaDescription)) {
        $metaDescription = ContentGenerator::extractMetaDescription($body);
    }
    
    // Aggiungi al feed
    sectionEvent('Salvataggio nel feed');
    $feedBuilder->addItem($articolo['title'], $body, $featuredImage, $metaDescription);
    logEvent('Articolo aggiunto al feed', 'success');
    
    // Pubblica su WordPress se abilitato
    $wpPostUrl = null;
    if ($wpPublisher->isEnabled() && !empty($config['wp_auto_publish'])) {
        sectionEvent('Pubblicazione su WordPress');
        
        $wpCategories = $wpPublisher->getCategories();
        $wpCategoryName = $generator->suggestCategory($articolo['title'], $keyword, $wpCategories);
        
        $wpResult = $wpPublisher->publish(
            $articolo['title'],
            $body,
            $featuredImage['url'] ?? null,
            $metaDescription,
            null,
            $wpCategoryName,
            $keyword,
            $articolo['tags'] ?? [],
            $articolo['seo_title'] ?? null,
            $articolo['schema_markup'] ?? null
        );
        
        if ($wpResult !== null) {
            $wpPostUrl = $wpResult['post_url'];
            logEvent('Pubblicato su WordPress: ' . $wpPostUrl, 'success');
        } else {
            logEvent('Pubblicazione WordPress fallita', 'error');
        }
    }
    
    // Riepilogo
    sectionEvent('Completato');
    logEvent('✅ Articolo creato con successo!', 'success');
    logEvent('Titolo: ' . $articolo['title'], 'success');
    if ($wpPostUrl) {
        logEvent('URL: ' . $wpPostUrl, 'success');
    }
    
    writeProgress('summary', [
        'title' => $articolo['title'],
        'wp_url' => $wpPostUrl,
        'seo_score' => $articolo['seo_score'] ?? 0,
        'geo_score' => $articolo['geo_score'] ?? 0,
    ]);
    
} catch (Throwable $e) {
    logEvent('Errore: ' . $e->getMessage(), 'error');
}

doneEvent();
