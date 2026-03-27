    <div class="header">
        <h2>Topic Elaborati</h2>
        <div style="display:flex;gap:8px;align-items:center;">
            <form method="post" onsubmit="return confirm('Cancellare tutto lo storico? I topic potranno essere rielaborati.')">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="clear_history">
                <button type="submit" class="btn btn-danger btn-sm">Cancella Storico</button>
            </form>
        </div>
    </div>

    <?php
    // Info cache WordPress per rilevamento duplicati
    $topicFilterForCache = new TopicFilter($config);
    $wpCacheInfo = $topicFilterForCache->getWpCacheInfo();
    $wpCacheAge = $wpCacheInfo['fetched_at'] ? round((time() - $wpCacheInfo['fetched_at']) / 60) : null;
    $wpCacheStatus = $wpCacheInfo['valid'] ? 'valida' : 'scaduta';
    $wpCacheColor = $wpCacheInfo['valid'] ? '#22c55e' : '#f59e0b';
    ?>
    <div class="card" style="margin-bottom:16px;">
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
            <div>
                <strong style="font-size:14px;">Post WordPress in cache</strong>
                <span style="margin-left:8px;color:#94a3b8;font-size:13px;">
                    (usata per evitare duplicati con contenuti scritti manualmente)
                </span>
                <div style="margin-top:6px;font-size:13px;color:#cbd5e1;">
                    <?php if (!$wpCacheInfo['configured']): ?>
                        <span style="color:#f87171;">WordPress non configurato — la cache WP non viene aggiornata.</span>
                    <?php elseif ($wpCacheInfo['fetched_at'] === null): ?>
                        <span style="color:#f59e0b;">Cache assente — verrà creata al prossimo avvio della generazione.</span>
                    <?php else: ?>
                        <span style="color:<?= $wpCacheColor ?>;">&#9679;</span>
                        <?= $wpCacheInfo['count'] ?> post &mdash; cache <?= $wpCacheStatus ?>
                        (aggiornata <?= $wpCacheAge ?> min fa)
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($wpCacheInfo['configured']): ?>
            <button class="btn btn-sm" id="syncWpCacheBtn" onclick="syncWpCache()" style="white-space:nowrap;">
                Sincronizza da WP
            </button>
            <?php endif; ?>
        </div>
    </div>
    <script>
    function syncWpCache() {
        var btn = document.getElementById('syncWpCacheBtn');
        btn.disabled = true;
        btn.textContent = 'Sincronizzazione...';
        fetch('dashboard.php?action=refresh_wp_topics_cache&csrf_token=<?= urlencode($csrfToken) ?>')
            .then(function(r){ return r.text(); })
            .then(function(t){
                if (t.startsWith('OK:')) {
                    btn.textContent = 'Sincronizzato (' + t.split(':')[1] + ' post)';
                    btn.style.color = '#22c55e';
                    setTimeout(function(){ location.reload(); }, 1200);
                } else {
                    btn.textContent = 'Errore: ' + t;
                    btn.style.color = '#f87171';
                    btn.disabled = false;
                }
            })
            .catch(function(){ btn.textContent = 'Errore rete'; btn.disabled = false; });
    }
    </script>

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

