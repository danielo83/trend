<?php

/**
 * Endpoint web per esecuzione via cron URL
 * Chiamare: https://www.smorfeo.it/trend/cron.php?token=IL_TUO_TOKEN
 *
 * Utile per hosting condivisi che supportano cron via URL (es. cPanel, Plesk)
 */

// Impedisci timeout del web server
set_time_limit(300);

// Carica configurazione
$config = require __DIR__ . '/config.php';

// Verifica token di sicurezza
$token = $_GET['token'] ?? '';
$validToken = EnvLoader::get('CRON_TOKEN');

if (empty($validToken) || $token !== $validToken) {
    http_response_code(403);
    echo 'Accesso negato.';
    exit;
}

// Carica classi
require __DIR__ . '/src/AutocompleteFetcher.php';
require __DIR__ . '/src/TopicFilter.php';
require __DIR__ . '/src/ContentGenerator.php';
require __DIR__ . '/src/RSSFeedBuilder.php';

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
}

// Lock file
$lockFile = __DIR__ . '/data/.lock';
$lockFp = fopen($lockFile, 'w');
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    logMsg('SKIP: altra esecuzione in corso');
    echo 'SKIP: esecuzione gia in corso';
    fclose($lockFp);
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

try {
    logMsg('=== INIZIO ESECUZIONE (via web) ===');
    echo "INIZIO ESECUZIONE\n";

    // 1. Recupera keyword (Google Autocomplete oppure lista manuale)
    $keywordSource = $config['keyword_source'] ?? 'google';

    if ($keywordSource === 'manual') {
        $suggerimenti = array_values(array_filter(array_map('trim', $config['manual_keywords'] ?? [])));
        logMsg('Keyword manuali caricate: ' . count($suggerimenti));
        echo 'Keyword manuali: ' . count($suggerimenti) . "\n";
    } else {
        $fetcher = new AutocompleteFetcher($config);
        $suggerimenti = $fetcher->fetch();
        logMsg('Trovati ' . count($suggerimenti) . ' suggerimenti totali');
        echo 'Suggerimenti: ' . count($suggerimenti) . "\n";
    }

    if (empty($suggerimenti)) {
        logMsg('Nessuna keyword trovata. Fine.');
        echo "Nessuna keyword. Fine.\n";
    }

    // 2. Filtra topic nuovi
    $nuovi = [];
    if (!empty($suggerimenti)) {
        $filter = new TopicFilter($config);
        $nuovi = $filter->filter($suggerimenti);
        logMsg('Topic nuovi: ' . count($nuovi));
        echo 'Topic nuovi: ' . count($nuovi) . "\n";

        if (empty($nuovi)) {
            logMsg('Nessun topic nuovo. Fine.');
            echo "Nessun topic nuovo. Fine.\n";
        }
    }

    // 3. Genera contenuti
    $generati = 0;
    if (!empty($nuovi)) {
    $generator = new ContentGenerator($config);
    $feedBuilder = new RSSFeedBuilder($config);

    foreach ($nuovi as $topic) {
        logMsg("Elaboro: \"{$topic}\"");
        echo "Elaboro: {$topic}...";
        $filter->markInProgress($topic);

        $articolo = $generator->generate($topic);

        if ($articolo !== null) {
            // Prepara la meta description per il feed
            $metaDescription = $articolo['meta_description'] ?? '';
            if (empty($metaDescription)) {
                $metaDescription = ContentGenerator::extractMetaDescription($articolo['body']);
            }
            $feedBuilder->addItem($articolo['title'], $articolo['body'], null, $metaDescription);
            $filter->markCompleted($topic);
            $generati++;
            logMsg("OK: \"{$articolo['title']}\"");
            echo " OK\n";
        } else {
            $filter->markFailed($topic);
            logMsg("ERRORE: impossibile generare per \"{$topic}\"");
            echo " ERRORE\n";
        }

        sleep(2);
    }

    logMsg("Generati {$generati} articoli. Feed: " . $feedBuilder->getItemCount() . " item.");
    echo "FINE: {$generati} articoli generati.\n";
    } // fine if (!empty($nuovi))

    logMsg('=== FINE ESECUZIONE ===');

} catch (Throwable $e) {
    logMsg('ERRORE FATALE: ' . $e->getMessage());
    echo 'ERRORE: ' . $e->getMessage() . "\n";
} finally {
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    @unlink($lockFile);
}
