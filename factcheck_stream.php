<?php

/**
 * Endpoint per fact-check batch con progress tracking (integrazione dashboard).
 *
 * Due modalità:
 *   ?action=start  -> Avvia il fact-check in background
 *   ?action=poll&offset=N -> Ritorna le righe di log dal offset N in poi
 *
 * Parametri per start:
 *   &ids=1,2,3     -> Solo questi post ID (obbligatorio o usa &limit)
 *   &limit=N       -> Max N post per esecuzione
 *   &category=ID   -> Solo post di questa categoria
 */

session_start();

$config = require __DIR__ . '/config.php';

if (empty($_SESSION['dashboard_auth'])) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['error' => 'Non autenticato']);
    exit;
}

$action       = $_GET['action'] ?? '';
$progressFile = __DIR__ . '/data/.factcheck_progress.jsonl';

// ============================================================
// POLL: ritorna le righe nuove dal file di progresso
// ============================================================
if ($action === 'poll') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache');

    $offset = max(0, intval($_GET['offset'] ?? 0));
    $lines  = [];
    $done   = false;

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
// START: avvia il fact-check in background
// ============================================================
if ($action !== 'start') {
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(['error' => 'Azione non valida. Usa ?action=start oppure ?action=poll']);
    exit;
}

set_time_limit(3600);
ignore_user_abort(true);

session_write_close();

@file_put_contents($progressFile, '');

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

require __DIR__ . '/src/ContentGenerator.php';
require __DIR__ . '/src/WordPressPublisher.php';

// --- Funzioni di logging ---

function writeProgress(string $event, array $data): void
{
    global $progressFile;
    $data['event'] = $event;
    $data['ts']    = date('H:i:s');
    $line = json_encode($data, JSON_UNESCAPED_UNICODE) . "\n";
    @file_put_contents($progressFile, $line, FILE_APPEND | LOCK_EX);
}

function logEvent(string $message, string $type = 'info'): void
{
    writeProgress('log', ['message' => $message, 'type' => $type]);
    $logPath = __DIR__ . '/logs/factcheck.log';
    @file_put_contents($logPath, '[' . date('Y-m-d H:i:s') . '] [' . strtoupper($type) . '] ' . $message . "\n", FILE_APPEND | LOCK_EX);
}

function sectionEvent(string $title): void  { writeProgress('section', ['title' => $title]); }
function summaryEvent(array $stats): void   { writeProgress('summary', $stats); }
function doneEvent(): void                  { writeProgress('done', []); }

// --- Parametri ---
$paramIds      = $_GET['ids'] ?? '';
$paramLimit    = max(0, intval($_GET['limit'] ?? 0));
$paramCategory = $_GET['category'] ?? '';

$filters = [];
if (!empty($paramIds)) {
    $filters['include'] = array_map('intval', explode(',', $paramIds));
}
if (!empty($paramCategory)) {
    $filters['categories'] = array_map('intval', explode(',', $paramCategory));
}

// ============================================================
// ESECUZIONE FACT-CHECK
// ============================================================
try {
    sectionEvent('Inizializzazione fact-check');
    logEvent('=== INIZIO FACT-CHECK ===', 'success');

    // Database
    $dbPath = $config['db_path'] ?? __DIR__ . '/data/history.sqlite';
    $db     = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("
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
    $db->exec('CREATE INDEX IF NOT EXISTS idx_factcheck_post_id ON factcheck_log(post_id)');

    // WordPress
    $wpPublisher = new WordPressPublisher($config);
    if (!$wpPublisher->isEnabled()) {
        logEvent('WordPress non configurato o non abilitato!', 'error');
        doneEvent();
        exit;
    }
    $wpPublisher->setLogCallback(function (string $msg, string $type) { logEvent($msg, $type); });

    // ContentGenerator
    $generator = new ContentGenerator($config);
    $generator->setLogCallback(function (string $msg, string $type) { logEvent($msg, $type); });

    // Recupera post
    sectionEvent('Recupero post da WordPress');
    $allPosts = $wpPublisher->fetchAllPosts($filters);

    if (empty($allPosts)) {
        logEvent('Nessun post trovato con i filtri specificati.', 'warning');
        doneEvent();
        exit;
    }

    logEvent('Post trovati: ' . count($allPosts), 'success');

    $postsToCheck = $allPosts;

    if ($paramLimit > 0) {
        $postsToCheck = array_slice($postsToCheck, 0, $paramLimit);
        logEvent("Limit: max {$paramLimit} post", 'detail');
    }

    $total   = count($postsToCheck);
    $success = 0;
    $failed  = 0;
    $issues  = 0;

    logEvent("Post in coda: {$total}", 'success');

    $stmtInsert = $db->prepare('
        INSERT INTO factcheck_log (post_id, title, score, issues, summary, status, checked_at, provider, time_ms)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');

    sectionEvent("Fact-check di {$total} post");

    foreach ($postsToCheck as $i => $post) {
        $num   = $i + 1;
        $pid   = $post['id'];
        $ptitle = $post['title'] ?? '';
        logEvent("[{$num}/{$total}] Fact-check post ID {$pid}: \"{$ptitle}\"", 'step');

        if (empty($post['content'])) {
            logEvent("Contenuto vuoto per post ID {$pid}, skip", 'warning');
            $failed++;
            continue;
        }

        $slug   = !empty($post['slug']) ? str_replace('-', ' ', $post['slug']) : $ptitle;
        $result = $generator->factCheck($ptitle, $post['content'], $slug);

        if ($result === null) {
            logEvent("ERRORE: fact-check fallito per post ID {$pid}", 'error');
            $stmtInsert->execute([$pid, $ptitle, null, '[]', 'Tutte le API hanno fallito', 'failed', date('Y-m-d H:i:s'), null, null]);
            $failed++;
            sleep(2);
            continue;
        }

        $score      = $result['score'];
        $issuesList = $result['issues'];
        $summary    = $result['summary'];
        $provider   = $result['provider'];
        $timeMs     = $result['time_ms'];
        $status     = empty($issuesList) ? 'clean' : 'issues_found';

        if (!empty($issuesList)) {
            $issues++;
            logEvent("Score: {$score}/10 — " . count($issuesList) . " problemi trovati (provider: {$provider}, {$timeMs}ms)", 'warning');
            foreach ($issuesList as $issue) {
                logEvent("  • {$issue}", 'detail');
            }
        } else {
            logEvent("Score: {$score}/10 — Nessun problema rilevato (provider: {$provider}, {$timeMs}ms)", 'success');
        }
        logEvent("Sommario: {$summary}", 'detail');

        $stmtInsert->execute([
            $pid, $ptitle, $score,
            json_encode($issuesList, JSON_UNESCAPED_UNICODE),
            $summary, $status,
            date('Y-m-d H:i:s'), $provider, $timeMs,
        ]);
        $success++;

        writeProgress('result', [
            'post_id' => $pid,
            'score'   => $score,
            'issues'  => count($issuesList),
            'status'  => $status,
        ]);

        if ($num < $total) {
            sleep(2);
        }
    }

    summaryEvent([
        'success' => $success,
        'failed'  => $failed,
        'issues'  => $issues,
        'total'   => $total,
    ]);

    logEvent("Fact-check completato: {$success} verificati ({$issues} con problemi), {$failed} falliti su {$total} totali", 'success');
    logEvent('=== FINE FACT-CHECK ===', 'success');

} catch (Throwable $e) {
    logEvent('ERRORE FATALE: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(), 'error');
}

doneEvent();
