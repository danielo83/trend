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

