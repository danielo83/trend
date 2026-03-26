<?php

/**
 * Endpoint per riscrittura batch con progress tracking (integrazione dashboard).
 *
 * Due modalita':
 *   ?action=start   -> Avvia la riscrittura in background
 *   ?action=poll&offset=N -> Ritorna le righe di log dal offset N in poi
 *
 * Parametri per start:
 *   &category=ID     -> Solo post di questa categoria
 *   &after=YYYY-MM-DD -> Solo post dopo questa data
 *   &before=YYYY-MM-DD -> Solo post prima di questa data
 *   &ids=1,2,3       -> Solo questi post ID
 *   &limit=N         -> Max N post per esecuzione
 *   &offset_posts=N  -> Salta i primi N post
 *   &new_images=1    -> Rigenera anche le immagini
 */

session_start();

$config = require __DIR__ . '/config.php';

if (empty($_SESSION['dashboard_auth'])) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['error' => 'Non autenticato']);
    exit;
}

$action = $_GET['action'] ?? '';
$progressFile = __DIR__ . '/data/.rewrite_progress.jsonl';

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
// START: avvia la riscrittura in background
// ============================================================
if ($action !== 'start') {
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(['error' => 'Azione non valida. Usa ?action=start oppure ?action=poll']);
    exit;
}

// Impedisci timeout
set_time_limit(3600); // 1 ora per riscritture lunghe
ignore_user_abort(true);

// Chiudi sessione subito per non bloccare il polling
session_write_close();

// Pulisci file progress precedente
@file_put_contents($progressFile, '');

// Chiudi la connessione HTTP immediatamente
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
echo '<!-- started -->';

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    header('Content-Length: 16');
    header('Connection: close');
    if (ob_get_level() > 0) ob_end_flush();
    flush();
}

// Da qui in poi il browser ha ricevuto la risposta e non aspetta piu'.

require __DIR__ . '/src/ContentGenerator.php';
require __DIR__ . '/src/ImageGenerator.php';
require __DIR__ . '/src/WordPressPublisher.php';
require __DIR__ . '/src/LinkBuilder.php';

// --- Funzioni di logging ---

function writeProgress(string $event, array $data): void
{
    global $progressFile;

    $data['event'] = $event;
    $data['ts'] = date('H:i:s');
    $line = json_encode($data, JSON_UNESCAPED_UNICODE) . "\n";
    @file_put_contents($progressFile, $line, FILE_APPEND | LOCK_EX);
}

function logEvent(string $message, string $type = 'info'): void
{
    global $config;
    writeProgress('log', ['message' => $message, 'type' => $type]);

    // Scrivi anche nel log file
    $logPath = __DIR__ . '/logs/rewrite.log';
    $prefix = strtoupper($type);
    $logLine = '[' . date('Y-m-d H:i:s') . '] [' . $prefix . '] ' . $message . "\n";
    @file_put_contents($logPath, $logLine, FILE_APPEND | LOCK_EX);
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

// --- Parsing parametri dalla query string ---

$paramCategory   = $_GET['category'] ?? '';
$paramAfter      = $_GET['after'] ?? '';
$paramBefore     = $_GET['before'] ?? '';
$paramIds        = $_GET['ids'] ?? '';
$paramLimit      = max(0, intval($_GET['limit'] ?? 0));
$paramOffsetPosts = max(0, intval($_GET['offset_posts'] ?? 0));
$paramNewImages  = !empty($_GET['new_images']);

$filters = [];
if (!empty($paramCategory)) {
    $filters['categories'] = array_map('intval', explode(',', $paramCategory));
}
if (!empty($paramAfter)) {
    $filters['after'] = $paramAfter;
}
if (!empty($paramBefore)) {
    $filters['before'] = $paramBefore;
}
if (!empty($paramIds)) {
    $filters['include'] = array_map('intval', explode(',', $paramIds));
}

// ============================================================
// ESECUZIONE RISCRITTURA
// ============================================================

try {
    sectionEvent('Inizializzazione riscrittura');
    logEvent('=== INIZIO RISCRITTURA ===', 'success');

    // Database per tracking
    $dbPath = $config['db_path'] ?? __DIR__ . '/data/history.sqlite';
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("
        CREATE TABLE IF NOT EXISTS rewrite_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            post_id INTEGER NOT NULL,
            old_title TEXT,
            new_title TEXT,
            status TEXT NOT NULL DEFAULT 'completed',
            rewritten_at TEXT NOT NULL,
            provider TEXT,
            time_ms INTEGER
        )
    ");
    
    // Aggiungi indice per post_id (senza UNIQUE per permettere riscritture multiple)
    $db->exec('CREATE INDEX IF NOT EXISTS idx_rewrite_post_id ON rewrite_log(post_id)');

    // Inizializza componenti
    $wpPublisher = new WordPressPublisher($config);
    if (!$wpPublisher->isEnabled()) {
        logEvent('WordPress non configurato o non abilitato!', 'error');
        doneEvent();
        exit;
    }
    $wpPublisher->setLogCallback(function (string $msg, string $type) {
        logEvent($msg, $type);
    });

    $generator = new ContentGenerator($config);
    $generator->setLogCallback(function (string $msg, string $type) {
        logEvent($msg, $type);
    });

    require_once __DIR__ . '/src/SmartLinkBuilder.php';
    $linkBuilder = new SmartLinkBuilder($config);
    if ($linkBuilder->isEnabled()) {
        $linkBuilder->setLogCallback(function (string $msg, string $type) {
            logEvent($msg, $type);
        });
        $generator->setLinkBuilder($linkBuilder);
        logEvent('Link Building ATTIVO', 'detail');
    }

    $imageGen = new ImageGenerator($config);

    // Recupera post da WordPress
    sectionEvent('Recupero post da WordPress');
    $allPosts = $wpPublisher->fetchAllPosts($filters);

    if (empty($allPosts)) {
        logEvent('Nessun post trovato con i filtri specificati.', 'warning');
        doneEvent();
        exit;
    }

    logEvent('Post trovati: ' . count($allPosts), 'success');

    // NOTA: Non escludiamo i post gia' riscritti per permettere riscritture multiple
    // L'utente puo' riscrivere lo stesso articolo piu' volte per migliorarlo
    $postsToRewrite = $allPosts;
    
    // Conteggio per info (non bloccante)
    $alreadyRewritten = [];
    $stmt = $db->query('SELECT post_id, COUNT(*) as rewrite_count FROM rewrite_log WHERE status = "completed" GROUP BY post_id');
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $alreadyRewritten[$row['post_id']] = $row['rewrite_count'];
    }
    
    $rewriteCount = count($alreadyRewritten);
    logEvent('Post con riscritture precedenti: ' . $rewriteCount, 'detail');
    logEvent('Post in coda: ' . count($postsToRewrite), 'detail');

    // Offset e limit
    if ($paramOffsetPosts > 0) {
        $postsToRewrite = array_slice($postsToRewrite, $paramOffsetPosts);
        logEvent("Offset: saltati i primi {$paramOffsetPosts} post", 'detail');
    }
    if ($paramLimit > 0) {
        $postsToRewrite = array_slice($postsToRewrite, 0, $paramLimit);
        logEvent("Limit: max {$paramLimit} post", 'detail');
    }

    $total = count($postsToRewrite);
    logEvent("Post in coda: {$total}", 'success');

    // Refresh cache link interni
    if ($linkBuilder->isEnabled()) {
        logEvent('Aggiornamento cache link interni...', 'detail');
        $linkBuilder->refreshCache();
    }

    // Riscrittura
    sectionEvent("Riscrittura di {$total} post");

    $success = 0;
    $failed  = 0;
    $stmtInsert = $db->prepare('
        INSERT OR REPLACE INTO rewrite_log (post_id, old_title, new_title, status, rewritten_at, provider, time_ms)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ');

    foreach ($postsToRewrite as $i => $post) {
        $num = $i + 1;
        logEvent("[{$num}/{$total}] Riscrittura post ID {$post['id']}: \"{$post['title']}\"", 'step');

        // Usa lo slug come topic: è la keyword originale pulita
        // Es: "sognare-di-essere-incinta" → "sognare di essere incinta"
        $topic = !empty($post['slug']) ? str_replace('-', ' ', $post['slug']) : $post['title'];
        $articolo = $generator->generate($topic);

        if ($articolo === null) {
            logEvent("ERRORE: impossibile rigenerare post ID {$post['id']}", 'error');
            $stmtInsert->execute([
                $post['id'], $post['title'], null, 'failed',
                date('Y-m-d H:i:s'), null, null,
            ]);
            $failed++;
            sleep(2);
            continue;
        }

        $newBody = $articolo['body'];

        // Nuova immagine (se richiesto)
        $newImageUrl = null;
        if ($paramNewImages && $imageGen->isEnabled()) {
            logEvent('Generazione nuova immagine featured...', 'detail');
            $featuredImage = $imageGen->generateFeaturedImage($articolo['title'], $topic);
            if ($featuredImage !== null) {
                $newImageUrl = $featuredImage['url'];
                logEvent("Nuova immagine: {$newImageUrl}", 'success');
            }
        }

        // Aggiorna su WordPress
        $result = $wpPublisher->update(
            $post['id'],
            $articolo['title'],
            $newBody,
            mb_substr(strip_tags($newBody), 0, 160),
            $newImageUrl
        );

        if ($result !== null) {
            logEvent("Post {$post['id']} aggiornato! URL: {$result['post_url']}", 'success');
            $stmtInsert->execute([
                $post['id'], $post['title'], $articolo['title'], 'completed',
                date('Y-m-d H:i:s'), $articolo['provider'] ?? null, $articolo['time_ms'] ?? null,
            ]);
            $success++;
        } else {
            logEvent("Aggiornamento WP fallito per ID {$post['id']}", 'error');
            $stmtInsert->execute([
                $post['id'], $post['title'], $articolo['title'], 'failed',
                date('Y-m-d H:i:s'), $articolo['provider'] ?? null, $articolo['time_ms'] ?? null,
            ]);
            $failed++;
        }

        if ($num < $total) {
            sleep(3);
        }
    }

    // Riepilogo
    summaryEvent([
        'success' => $success,
        'failed'  => $failed,
        'total'   => $total,
    ]);

    logEvent("Riscrittura completata: {$success} successi, {$failed} falliti su {$total} totali", 'success');
    logEvent('=== FINE RISCRITTURA ===', 'success');

} catch (Throwable $e) {
    logEvent('ERRORE FATALE: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(), 'error');
}

doneEvent();
