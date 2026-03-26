<?php

/**
 * Rewrite - Riscrittura batch degli articoli WordPress esistenti.
 *
 * Rigenera il contenuto di articoli gia' pubblicati preservando:
 *   - Permalink (slug invariato)
 *   - Link building interno (ricalcolato con LinkBuilder)
 *   - Post ID e URL
 *   - Categoria e immagine featured (opzionalmente rigenerabile)
 *
 * Uso da CLI:
 *   php rewrite.php                          # Tutti i post (dry-run)
 *   php rewrite.php --execute                # Esegui riscrittura
 *   php rewrite.php --category=19            # Solo categoria ID 19
 *   php rewrite.php --after=2025-01-01       # Solo post dopo questa data
 *   php rewrite.php --before=2026-01-01      # Solo post prima di questa data
 *   php rewrite.php --ids=100,200,300        # Solo post specifici
 *   php rewrite.php --limit=10               # Max 10 post per esecuzione
 *   php rewrite.php --offset=50              # Salta i primi 50 post
 *   php rewrite.php --new-images             # Rigenera anche le immagini featured
 *   php rewrite.php --execute --limit=5      # Riscrivi i primi 5 post
 */

// Carica configurazione e classi
$config = require __DIR__ . '/config.php';

require __DIR__ . '/src/ContentGenerator.php';
require __DIR__ . '/src/ImageGenerator.php';
require __DIR__ . '/src/WordPressPublisher.php';
require __DIR__ . '/src/LinkBuilder.php';

// =========================================================================
// Parsing argomenti CLI
// =========================================================================

$opts = getopt('', [
    'execute',
    'category:',
    'after:',
    'before:',
    'ids:',
    'limit:',
    'offset:',
    'new-images',
    'help',
]);

if (isset($opts['help'])) {
    echo <<<HELP
Rewrite - Riscrittura batch articoli WordPress

Uso:
  php rewrite.php [opzioni]

Opzioni:
  --execute         Esegui la riscrittura (senza questo flag: solo dry-run/anteprima)
  --category=ID     Riscrivi solo i post della categoria con questo ID
  --after=YYYY-MM-DD   Solo post pubblicati dopo questa data
  --before=YYYY-MM-DD  Solo post pubblicati prima di questa data
  --ids=1,2,3       Riscrivi solo i post con questi ID (separati da virgola)
  --limit=N         Riscrivi massimo N post per esecuzione
  --offset=N        Salta i primi N post (per riprendere da dove eri rimasto)
  --new-images      Rigenera anche le immagini featured
  --help            Mostra questo messaggio

Esempi:
  php rewrite.php                              # Anteprima: mostra quanti post verranno riscritti
  php rewrite.php --execute --limit=10         # Riscrivi i primi 10 post
  php rewrite.php --execute --offset=10 --limit=10  # Riscrivi i post 11-20
  php rewrite.php --execute --category=19      # Riscrivi solo la categoria 19
  php rewrite.php --execute --ids=100,200      # Riscrivi solo i post 100 e 200

HELP;
    exit(0);
}

$dryRun      = !isset($opts['execute']);
$newImages   = isset($opts['new-images']);
$limit       = isset($opts['limit']) ? max(1, intval($opts['limit'])) : 0;
$offset      = isset($opts['offset']) ? max(0, intval($opts['offset'])) : 0;

// Costruisci filtri per WordPress API
$filters = [];
if (isset($opts['category'])) {
    $filters['categories'] = array_map('intval', explode(',', $opts['category']));
}
if (isset($opts['after'])) {
    $filters['after'] = $opts['after'];
}
if (isset($opts['before'])) {
    $filters['before'] = $opts['before'];
}
if (isset($opts['ids'])) {
    $filters['include'] = array_map('intval', explode(',', $opts['ids']));
}

// =========================================================================
// Inizializzazione componenti
// =========================================================================

// Database SQLite per tracciare i post gia' riscritti
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
        time_ms INTEGER,
        UNIQUE(post_id)
    )
");

// Logging
$logPath = __DIR__ . '/logs/rewrite.log';
$logDir = dirname($logPath);
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}

function logMsg(string $message, string $type = 'detail'): void
{
    global $logPath;
    $prefix = match($type) {
        'success' => '[SUCCESS]',
        'error'   => '[ERROR]',
        'warning' => '[WARNING]',
        default   => '[DETAIL]',
    };
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $prefix . ' ' . $message . PHP_EOL;
    @file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX);
    echo $line;
}

// Progress file per integrazione dashboard
$progressPath = __DIR__ . '/data/.rewrite_progress.jsonl';

function writeProgress(string $type, string $message, array $extra = []): void
{
    global $progressPath;
    $entry = array_merge([
        'type'      => $type,
        'message'   => $message,
        'timestamp' => date('Y-m-d H:i:s'),
    ], $extra);
    @file_put_contents($progressPath, json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}

// Publisher WordPress
$wpPublisher = new WordPressPublisher($config);
if (!$wpPublisher->isEnabled()) {
    logMsg('WordPress non configurato o non abilitato. Impossibile procedere.', 'error');
    exit(1);
}
$wpPublisher->setLogCallback(function (string $msg, string $type) {
    logMsg($msg, $type);
});

// Content Generator
$generator = new ContentGenerator($config);
$generator->setLogCallback(function (string $msg, string $type) {
    logMsg($msg, $type);
});

// Link Builder (Smart Link Building con analisi semantica)
require_once __DIR__ . '/src/SmartLinkBuilder.php';
$linkBuilder = new SmartLinkBuilder($config);
if ($linkBuilder->isEnabled()) {
    $linkBuilder->setLogCallback(function (string $msg, string $type) {
        logMsg($msg, $type);
    });
    $generator->setLinkBuilder($linkBuilder);
    logMsg('Link Building ATTIVO', 'detail');
}

// Image Generator
$imageGen = new ImageGenerator($config);

// =========================================================================
// Recupero post da WordPress
// =========================================================================

logMsg('=== INIZIO RISCRITTURA ===', 'success');
logMsg('Modalita\': ' . ($dryRun ? 'DRY-RUN (anteprima)' : 'ESECUZIONE'), 'detail');

// Svuota il file di progresso
@file_put_contents($progressPath, '');
writeProgress('section', 'Recupero post da WordPress...');

$allPosts = $wpPublisher->fetchAllPosts($filters);

if (empty($allPosts)) {
    logMsg('Nessun post trovato con i filtri specificati.', 'warning');
    exit(0);
}

logMsg('Post trovati: ' . count($allPosts), 'detail');

// Escludi post gia' riscritti
$alreadyRewritten = [];
$stmt = $db->query('SELECT post_id FROM rewrite_log WHERE status = "completed"');
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $alreadyRewritten[$row['post_id']] = true;
}

$postsToRewrite = [];
foreach ($allPosts as $post) {
    if (!isset($alreadyRewritten[$post['id']])) {
        $postsToRewrite[] = $post;
    }
}

logMsg('Post gia\' riscritti (saltati): ' . count($alreadyRewritten), 'detail');
logMsg('Post da riscrivere: ' . count($postsToRewrite), 'detail');

if (empty($postsToRewrite)) {
    logMsg('Tutti i post sono gia\' stati riscritti!', 'success');
    exit(0);
}

// Applica offset e limit
if ($offset > 0) {
    $postsToRewrite = array_slice($postsToRewrite, $offset);
    logMsg("Offset applicato: saltati i primi {$offset} post", 'detail');
}
if ($limit > 0) {
    $postsToRewrite = array_slice($postsToRewrite, 0, $limit);
    logMsg("Limit applicato: max {$limit} post", 'detail');
}

$total = count($postsToRewrite);
logMsg("Post in coda per questa esecuzione: {$total}", 'detail');

// =========================================================================
// Dry-run: solo anteprima
// =========================================================================

if ($dryRun) {
    logMsg('', 'detail');
    logMsg('=== ANTEPRIMA POST DA RISCRIVERE ===', 'detail');
    foreach ($postsToRewrite as $i => $post) {
        logMsg(sprintf(
            '  [%d/%d] ID: %d | Titolo: "%s" | URL: %s',
            $i + 1, $total, $post['id'], $post['title'], $post['url']
        ), 'detail');
    }
    logMsg('', 'detail');
    logMsg("Totale: {$total} post da riscrivere.", 'detail');
    logMsg('Per eseguire la riscrittura, aggiungi --execute', 'detail');
    logMsg('Suggerimento: usa --limit=5 per iniziare con pochi post di prova', 'detail');
    exit(0);
}

// =========================================================================
// Esecuzione riscrittura
// =========================================================================

// Aggiorna cache link builder prima di iniziare (serve per i link interni)
if ($linkBuilder->isEnabled()) {
    logMsg('Aggiornamento cache link interni...', 'detail');
    $linkBuilder->refreshCache();
}

writeProgress('section', "Riscrittura di {$total} post...");

$success = 0;
$failed  = 0;
$stmtInsert = $db->prepare('
    INSERT OR REPLACE INTO rewrite_log (post_id, old_title, new_title, status, rewritten_at, provider, time_ms)
    VALUES (?, ?, ?, ?, ?, ?, ?)
');

foreach ($postsToRewrite as $i => $post) {
    $num = $i + 1;
    logMsg("--- [{$num}/{$total}] Riscrittura post ID {$post['id']}: \"{$post['title']}\" ---", 'detail');
    writeProgress('log', "[{$num}/{$total}] Riscrittura: \"{$post['title']}\"", [
        'current' => $num,
        'total'   => $total,
    ]);

    // Usa lo slug del post come topic: lo slug deriva dalla keyword originale
    // Es: "sognare-di-essere-incinta" → "sognare di essere incinta"
    // Evita di usare il titolo perché è già formattato e causerebbe duplicazioni nel prompt
    $topic = !empty($post['slug']) ? str_replace('-', ' ', $post['slug']) : $post['title'];

    // Genera nuovo contenuto
    $articolo = $generator->generate($topic);

    if ($articolo === null) {
        logMsg("  ERRORE: impossibile rigenerare articolo per ID {$post['id']}", 'error');
        $stmtInsert->execute([
            $post['id'], $post['title'], null, 'failed',
            date('Y-m-d H:i:s'), null, null,
        ]);
        $failed++;
        writeProgress('log', "ERRORE: post ID {$post['id']} fallito");
        sleep(2);
        continue;
    }

    $newBody = $articolo['body'];

    // Genera nuova immagine featured (se richiesto)
    $newImageUrl = null;
    if ($newImages && $imageGen->isEnabled()) {
        logMsg('  Generazione nuova immagine featured...', 'detail');
        $featuredImage = $imageGen->generateFeaturedImage($articolo['title'], $topic);
        if ($featuredImage !== null) {
            $newImageUrl = $featuredImage['url'];
            logMsg("  Nuova immagine: {$newImageUrl}", 'success');
        } else {
            logMsg('  Immagine non generata, mantengo quella esistente', 'warning');
        }
    }

    // Aggiorna il post su WordPress
    $result = $wpPublisher->update(
        $post['id'],
        $articolo['title'],
        $newBody,
        mb_substr(strip_tags($newBody), 0, 160),
        $newImageUrl
    );

    if ($result !== null) {
        logMsg("  Post {$post['id']} aggiornato! URL: {$result['post_url']}", 'success');
        $stmtInsert->execute([
            $post['id'], $post['title'], $articolo['title'], 'completed',
            date('Y-m-d H:i:s'), $articolo['provider'] ?? null, $articolo['time_ms'] ?? null,
        ]);
        $success++;
        writeProgress('log', "OK: post ID {$post['id']} riscritto", ['post_url' => $result['post_url']]);
    } else {
        logMsg("  ERRORE: aggiornamento WordPress fallito per ID {$post['id']}", 'error');
        $stmtInsert->execute([
            $post['id'], $post['title'], $articolo['title'], 'failed',
            date('Y-m-d H:i:s'), $articolo['provider'] ?? null, $articolo['time_ms'] ?? null,
        ]);
        $failed++;
        writeProgress('log', "ERRORE: aggiornamento WP fallito per ID {$post['id']}");
    }

    // Pausa tra le riscritture per rate limits
    if ($num < $total) {
        sleep(3);
    }
}

// =========================================================================
// Riepilogo
// =========================================================================

logMsg('', 'detail');
logMsg('=== RIEPILOGO RISCRITTURA ===', 'success');
logMsg("Totale processati: {$total}", 'detail');
logMsg("Successo: {$success}", 'success');
logMsg("Falliti: {$failed}", $failed > 0 ? 'error' : 'detail');
logMsg('=== FINE RISCRITTURA ===', 'success');

writeProgress('summary', "Riscrittura completata: {$success} successi, {$failed} falliti", [
    'success' => $success,
    'failed'  => $failed,
    'total'   => $total,
]);
writeProgress('done', 'Completato');
