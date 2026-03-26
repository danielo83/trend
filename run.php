<?php

/**
 * Pagina di esecuzione con output in tempo reale via AJAX polling.
 *
 * L'HTML si carica subito, JS avvia l'esecuzione via fetch POST,
 * poi fa polling ogni 500ms su un file di progresso per mostrare i log.
 */

session_start();

$config = require __DIR__ . '/config.php';

if (empty($_SESSION['dashboard_auth'])) {
    header('Location: dashboard.php');
    exit;
}

// Chiudi sessione per non bloccare le richieste di polling
session_write_close();

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Esecuzione AutoPilot - Log in tempo reale</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0f172a;
            color: #e2e8f0;
            padding: 20px;
        }
        .container { max-width: 1000px; margin: 0 auto; }
        h1 { color: #818cf8; margin-bottom: 10px; }
        .subtitle { color: #64748b; margin-bottom: 20px; font-size: 14px; }

        .status-bar {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 16px;
            padding: 10px 16px;
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 8px;
            font-size: 13px;
        }
        .status-dot {
            width: 10px; height: 10px;
            border-radius: 50%;
            background: #fbbf24;
            animation: pulse 1.2s ease-in-out infinite;
        }
        .status-dot.done { background: #4ade80; animation: none; }
        .status-dot.error { background: #fca5a5; animation: none; }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.3; }
        }
        .status-text { color: #94a3b8; }
        .status-elapsed { margin-left: auto; color: #64748b; font-size: 12px; font-family: 'Fira Code', monospace; }

        .log-container {
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: 8px;
            padding: 20px;
            max-height: 70vh;
            overflow-y: auto;
            font-family: 'Fira Code', 'Consolas', monospace;
            font-size: 13px;
        }

        .log-line { margin: 2px 0; line-height: 1.5; }
        .log-line .time { color: #475569; margin-right: 6px; }

        .section {
            margin: 15px 0;
            padding: 10px;
            background: #1e293b;
            border-left: 3px solid #60a5fa;
            border-radius: 0 8px 8px 0;
        }
        .section-title {
            color: #60a5fa;
            font-weight: bold;
            margin-bottom: 8px;
            font-size: 14px;
        }

        .summary {
            margin-top: 20px;
            padding: 15px;
            background: #1e293b;
            border-radius: 8px;
            border: 1px solid #334155;
            display: none;
        }
        .summary.show { display: block; }
        .summary-title { color: #4ade80; font-weight: bold; margin-bottom: 10px; }
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 12px;
        }
        .summary-stat {
            background: #0f172a;
            border-radius: 8px;
            padding: 12px;
            text-align: center;
        }
        .summary-stat .num { font-size: 24px; font-weight: bold; }
        .summary-stat .label { font-size: 12px; color: #64748b; margin-top: 4px; }

        .back-link {
            display: inline-block;
            margin-top: 20px;
            padding: 10px 20px;
            background: #6366f1;
            color: white;
            text-decoration: none;
            border-radius: 8px;
        }
        .back-link:hover { background: #4f46e5; }
    </style>
</head>
<body>

<div class="container">
    <h1>Esecuzione AutoPilot</h1>
    <p class="subtitle" id="runSubtitle">Output in tempo reale</p>
    <script>
    (function(){
        var t = new URLSearchParams(window.location.search).get('custom_topic');
        if (t) document.getElementById('runSubtitle').textContent = 'Topic custom: ' + t;
    })();
    </script>

    <div class="status-bar">
        <div class="status-dot" id="statusDot"></div>
        <span class="status-text" id="statusText">Avvio in corso...</span>
        <span class="status-elapsed" id="elapsed"></span>
    </div>

    <div class="log-container" id="log"></div>

    <div class="summary" id="summary">
        <div class="summary-title">Riepilogo</div>
        <div class="summary-grid" id="summaryGrid"></div>
    </div>

    <a href="dashboard.php" class="back-link">&#8592; Torna alla Dashboard</a>
</div>

<script>
(function() {
    var logContainer = document.getElementById('log');
    var statusDot = document.getElementById('statusDot');
    var statusText = document.getElementById('statusText');
    var elapsedEl = document.getElementById('elapsed');
    var summaryEl = document.getElementById('summary');
    var summaryGrid = document.getElementById('summaryGrid');

    var colors = {
        info: '#94a3b8',
        success: '#4ade80',
        error: '#fca5a5',
        warning: '#fbbf24',
        step: '#60a5fa',
        detail: '#64748b'
    };

    var currentSection = null;
    var autoScroll = true;
    var offset = 0;
    var pollTimer = null;
    var startTime = Date.now();
    var elapsedTimer = null;

    logContainer.addEventListener('scroll', function() {
        var atBottom = logContainer.scrollHeight - logContainer.scrollTop - logContainer.clientHeight < 60;
        autoScroll = atBottom;
    });

    function scrollToBottom() {
        if (autoScroll) {
            logContainer.scrollTop = logContainer.scrollHeight;
        }
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    }

    function addLogLine(time, message, type) {
        var div = document.createElement('div');
        div.className = 'log-line';
        var color = colors[type] || colors.info;
        div.innerHTML = '<span class="time">[' + escapeHtml(time) + ']</span><span style="color:' + color + '">' + escapeHtml(message) + '</span>';
        if (currentSection) {
            currentSection.appendChild(div);
        } else {
            logContainer.appendChild(div);
        }
        scrollToBottom();
    }

    function addSection(title) {
        var section = document.createElement('div');
        section.className = 'section';
        var titleEl = document.createElement('div');
        titleEl.className = 'section-title';
        titleEl.textContent = title;
        section.appendChild(titleEl);
        logContainer.appendChild(section);
        currentSection = section;
        scrollToBottom();
    }

    function showSummary(stats) {
        var items = [
            { num: stats.generati, label: 'Articoli generati', color: '#4ade80' },
            { num: stats.errori, label: 'Errori', color: stats.errori > 0 ? '#fca5a5' : '#94a3b8' },
            { num: stats.immagini, label: 'Immagini generate', color: '#60a5fa' },
            { num: stats.feed_totale, label: 'Feed totale items', color: '#818cf8' }
        ];
        summaryGrid.innerHTML = items.map(function(item) {
            return '<div class="summary-stat"><div class="num" style="color:' + item.color + '">' + item.num + '</div><div class="label">' + item.label + '</div></div>';
        }).join('');
        summaryEl.classList.add('show');
        scrollToBottom();
    }

    function setStatus(text, state) {
        statusText.textContent = text;
        statusDot.className = 'status-dot' + (state ? ' ' + state : '');
    }

    function updateElapsed() {
        var secs = Math.floor((Date.now() - startTime) / 1000);
        var m = Math.floor(secs / 60);
        var s = secs % 60;
        elapsedEl.textContent = (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
    }

    function processLine(line) {
        var ev = line.event;
        var ts = line.ts || '';

        if (ev === 'log') {
            addLogLine(ts, line.message || '', line.type || 'info');
            // Aggiorna status text con l'ultimo messaggio di tipo step
            if (line.type === 'step') {
                setStatus(line.message, '');
            }
        } else if (ev === 'section') {
            addSection(line.title || '');
            setStatus(line.title || 'In esecuzione...', '');
        } else if (ev === 'summary') {
            showSummary(line);
        } else if (ev === 'done') {
            return true; // fine
        }
        return false;
    }

    // --- Polling ---
    function poll() {
        fetch('run_stream.php?action=poll&offset=' + offset + '&_t=' + Date.now())
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data.lines && data.lines.length > 0) {
                    var isDone = false;
                    for (var i = 0; i < data.lines.length; i++) {
                        if (processLine(data.lines[i])) {
                            isDone = true;
                        }
                    }
                    offset = data.offset;

                    if (isDone || data.done) {
                        stopPolling();
                        setStatus('Esecuzione completata', 'done');
                        return;
                    }
                }
                // Continua polling
                pollTimer = setTimeout(poll, 500);
            })
            .catch(function(err) {
                console.error('Poll error:', err);
                // Riprova dopo 1s
                pollTimer = setTimeout(poll, 1000);
            });
    }

    function stopPolling() {
        if (pollTimer) clearTimeout(pollTimer);
        if (elapsedTimer) clearInterval(elapsedTimer);
        pollTimer = null;
    }

    // --- Avvia ---
    setStatus('Avvio esecuzione...', '');
    elapsedTimer = setInterval(updateElapsed, 1000);

    // Lancia l'esecuzione tramite un iframe nascosto (fire-and-forget).
    // L'iframe carica run_stream.php?action=start che:
    //   1) chiude la connessione HTTP subito (fastcgi_finish_request)
    //   2) continua l'esecuzione in background scrivendo su file
    // Questo evita che fetch() resti in attesa della fine dello script.
    var iframe = document.createElement('iframe');
    iframe.style.display = 'none';
    var customTopic = new URLSearchParams(window.location.search).get('custom_topic') || '';
    var customParam = customTopic ? '&custom_topic=' + encodeURIComponent(customTopic) : '';
    iframe.src = 'run_stream.php?action=start' + customParam + '&_t=' + Date.now();
    document.body.appendChild(iframe);

    // Inizia il polling subito (il file progress viene creato immediatamente)
    setStatus('In esecuzione...', '');
    pollTimer = setTimeout(poll, 800);

    // Timeout di sicurezza 10 minuti
    setTimeout(function() {
        if (pollTimer) {
            stopPolling();
            setStatus('Timeout raggiunto (10 min)', 'error');
        }
    }, 600000);
})();
</script>

</body>
</html>
