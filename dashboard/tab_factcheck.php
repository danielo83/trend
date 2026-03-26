    <?php
    // Carica statistiche fact-check dal database
    $fcDbPath = $config['db_path'] ?? __DIR__ . '/../data/history.sqlite';
    $fcDb = new PDO('sqlite:' . $fcDbPath);
    $fcDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $fcDb->exec("
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

    $fcChecked      = $fcDb->query('SELECT COUNT(DISTINCT post_id) FROM factcheck_log WHERE status != "failed"')->fetchColumn();
    $fcWithIssues   = $fcDb->query('SELECT COUNT(DISTINCT post_id) FROM factcheck_log WHERE status = "issues_found"')->fetchColumn();
    $fcClean        = $fcDb->query('SELECT COUNT(DISTINCT post_id) FROM factcheck_log WHERE status = "clean"')->fetchColumn();
    $fcFailed       = $fcDb->query('SELECT COUNT(DISTINCT post_id) FROM factcheck_log WHERE status = "failed"')->fetchColumn();
    $fcAvgScore     = $fcDb->query('SELECT ROUND(AVG(score),1) FROM factcheck_log WHERE score IS NOT NULL')->fetchColumn();

    // Mappa ultimo risultato per post_id
    $fcStatusMap = [];
    $fcStmt = $fcDb->query('SELECT post_id, score, issues, summary, status, checked_at FROM factcheck_log ORDER BY checked_at DESC');
    while ($fcRow = $fcStmt->fetch(PDO::FETCH_ASSOC)) {
        if (!isset($fcStatusMap[$fcRow['post_id']])) {
            $fcStatusMap[$fcRow['post_id']] = $fcRow;
        }
    }

    // Carica post da cache WordPress
    $fcCachePath = ($config['base_dir'] ?? __DIR__) . '/data/cache_wp_posts.json';
    $fcAllPosts  = [];
    $fcCacheFetchedAt = null;
    if (file_exists($fcCachePath)) {
        $fcCacheData = json_decode(file_get_contents($fcCachePath), true);
        $fcAllPosts  = $fcCacheData['posts'] ?? [];
        $fcCacheFetchedAt = $fcCacheData['fetched_at'] ?? null;
    }

    // Categorie
    $fcWpPublisher = new WordPressPublisher($config);
    $fcCategories  = $fcWpPublisher->isEnabled() ? $fcWpPublisher->getCategories() : [];
    $fcCatMap = [];
    foreach ($fcCategories as $cat) { $fcCatMap[$cat['id']] = $cat['name']; }

    // Filtri
    $fcFilterCat    = isset($_GET['fccat']) ? intval($_GET['fccat']) : 0;
    $fcFilterStatus = $_GET['fcstatus'] ?? 'all';
    $fcSearch       = trim($_GET['fcsearch'] ?? '');

    // Applica filtri
    $fcFilteredPosts = [];
    foreach ($fcAllPosts as $post) {
        if ($fcFilterCat > 0) {
            if (!in_array($fcFilterCat, $post['categories'] ?? [])) continue;
        }
        $postFcStatus = $fcStatusMap[$post['id']]['status'] ?? 'pending';
        if ($fcFilterStatus === 'pending'      && $postFcStatus !== 'pending')      continue;
        if ($fcFilterStatus === 'clean'        && $postFcStatus !== 'clean')        continue;
        if ($fcFilterStatus === 'issues_found' && $postFcStatus !== 'issues_found') continue;
        if ($fcFilterStatus === 'failed'       && $postFcStatus !== 'failed')       continue;
        if (!empty($fcSearch) && mb_stripos($post['title'] ?? '', $fcSearch) === false) continue;
        $fcFilteredPosts[] = $post;
    }

    // Paginazione
    $fcPerPage      = 20;
    $fcTotalFiltered = count($fcFilteredPosts);
    $fcTotalPages   = max(1, ceil($fcTotalFiltered / $fcPerPage));
    $fcCurrentPage  = max(1, min($fcTotalPages, intval($_GET['fcpage'] ?? 1)));
    $fcPageOffset   = ($fcCurrentPage - 1) * $fcPerPage;
    $fcPagePosts    = array_slice($fcFilteredPosts, $fcPageOffset, $fcPerPage);
    ?>

    <div class="header">
        <h2>Fact Check Articoli</h2>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <button type="button" class="btn btn-sm" style="background:#334155;color:#e2e8f0;" onclick="fcResetLog()">Resetta Log</button>
        </div>
    </div>

    <!-- Statistiche -->
    <div class="stats">
        <div class="stat-card">
            <div class="label">Post su WordPress</div>
            <div class="value blue"><?= count($fcAllPosts) ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Verificati</div>
            <div class="value green"><?= $fcChecked ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Con problemi</div>
            <div class="value <?= $fcWithIssues > 0 ? 'red' : '' ?>"><?= $fcWithIssues ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Senza problemi</div>
            <div class="value green"><?= $fcClean ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Score medio</div>
            <div class="value <?= ($fcAvgScore !== false && $fcAvgScore < 7) ? 'red' : 'green' ?>"><?= $fcAvgScore !== false ? $fcAvgScore . '/10' : '—' ?></div>
        </div>
    </div>

    <?php if (empty($fcAllPosts)): ?>
        <div class="card">
            <p style="color:#fbbf24;">Nessun articolo in cache. Aggiorna la cache dal tab Riscrittura.</p>
        </div>
    <?php else: ?>

    <!-- Filtri -->
    <div class="card" style="padding:16px;">
        <div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
            <div>
                <label style="display:block;color:#64748b;font-size:11px;margin-bottom:4px;">Cerca per titolo</label>
                <input type="text" id="fcSearchInput" value="<?= htmlspecialchars($fcSearch) ?>" placeholder="Cerca..." style="padding:8px 12px;background:#0f172a;border:1px solid #334155;border-radius:6px;color:#e2e8f0;font-size:13px;width:200px;">
            </div>
            <div>
                <label style="display:block;color:#64748b;font-size:11px;margin-bottom:4px;">Categoria</label>
                <select id="fcFilterCat" style="padding:8px 12px;background:#0f172a;border:1px solid #334155;border-radius:6px;color:#e2e8f0;font-size:13px;">
                    <option value="0">Tutte</option>
                    <?php foreach ($fcCategories as $cat): ?>
                        <option value="<?= $cat['id'] ?>" <?= $fcFilterCat === $cat['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="display:block;color:#64748b;font-size:11px;margin-bottom:4px;">Stato</label>
                <select id="fcFilterStatus" style="padding:8px 12px;background:#0f172a;border:1px solid #334155;border-radius:6px;color:#e2e8f0;font-size:13px;">
                    <option value="all"          <?= $fcFilterStatus === 'all'          ? 'selected' : '' ?>>Tutti</option>
                    <option value="pending"      <?= $fcFilterStatus === 'pending'      ? 'selected' : '' ?>>Da verificare</option>
                    <option value="clean"        <?= $fcFilterStatus === 'clean'        ? 'selected' : '' ?>>Senza problemi</option>
                    <option value="issues_found" <?= $fcFilterStatus === 'issues_found' ? 'selected' : '' ?>>Con problemi</option>
                    <option value="failed"       <?= $fcFilterStatus === 'failed'       ? 'selected' : '' ?>>Falliti</option>
                </select>
            </div>
            <button type="button" class="btn btn-primary btn-sm" onclick="fcApplyFilters()">Filtra</button>
        </div>
        <div style="margin-top:8px;color:#64748b;font-size:12px;">
            <?= $fcTotalFiltered ?> articoli trovati
            <?php if ($fcCacheFetchedAt): ?>
                &middot; Cache aggiornata il <?= date('d/m/Y H:i', $fcCacheFetchedAt) ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Selezione bulk -->
    <div class="card" style="padding:12px 16px;">
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;color:#94a3b8;font-size:13px;">
                <input type="checkbox" id="fcSelectAll" onchange="fcToggleSelectAll(this)" style="width:16px;height:16px;accent-color:#6366f1;">
                Seleziona pagina
            </label>
            <span id="fcSelectedCount" style="color:#64748b;font-size:13px;"></span>
            <button type="button" class="btn btn-primary btn-sm" id="fcBulkBtn" style="display:none;" onclick="fcStartSelected()">
                Verifica selezionati (<span id="fcBulkCount">0</span>)
            </button>
        </div>
    </div>

    <!-- Lista articoli -->
    <div class="card">
        <?php if (empty($fcPagePosts)): ?>
            <p style="color:#64748b;">Nessun articolo corrisponde ai filtri selezionati.</p>
        <?php else: ?>
            <?php foreach ($fcPagePosts as $post):
                $postId    = $post['id'];
                $postTitle = $post['title'] ?? '';
                $postUrl   = $post['url'] ?? $post['link'] ?? '';
                $postCats  = $post['categories'] ?? [];
                $fcInfo    = $fcStatusMap[$postId] ?? null;
                $fcStatus  = $fcInfo['status'] ?? 'pending';
                $fcScore   = $fcInfo['score'] ?? null;
                $fcIssues  = $fcInfo ? json_decode($fcInfo['issues'] ?? '[]', true) : [];
                $fcSummary = $fcInfo['summary'] ?? '';

                $postCatNames = [];
                foreach ($postCats as $cid) { $postCatNames[] = $fcCatMap[$cid] ?? "#{$cid}"; }
            ?>
                <div class="feed-item" id="fc-item-<?= $postId ?>">
                    <div style="display:flex;align-items:flex-start;gap:10px;">
                        <input type="checkbox" class="fc-checkbox" value="<?= $postId ?>" onchange="fcUpdateBulkUI()" style="width:16px;height:16px;margin-top:3px;accent-color:#6366f1;flex-shrink:0;">
                        <div style="flex:1;min-width:0;">
                            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:4px;">
                                <h4 style="margin:0;"><?= htmlspecialchars($postTitle) ?></h4>
                                <?php if ($fcStatus === 'clean'): ?>
                                    <span style="display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:4px;font-size:11px;background:#064e3b;color:#34d399;">✓ OK <?= $fcScore ?>/10</span>
                                <?php elseif ($fcStatus === 'issues_found'): ?>
                                    <span style="display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:4px;font-size:11px;background:#7f1d1d;color:#fca5a5;">⚠ Problemi <?= $fcScore ?>/10</span>
                                <?php elseif ($fcStatus === 'failed'): ?>
                                    <span style="display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:4px;font-size:11px;background:#422006;color:#fbbf24;">✗ Errore</span>
                                <?php else: ?>
                                    <span style="display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:4px;font-size:11px;background:#1e293b;color:#94a3b8;">Da verificare</span>
                                <?php endif; ?>
                            </div>
                            <div class="date" style="margin-bottom:6px;">
                                ID: <?= $postId ?>
                                <?php if (!empty($postCatNames)): ?>&middot; <?= htmlspecialchars(implode(', ', $postCatNames)) ?><?php endif; ?>
                                <?php if ($fcInfo && $fcInfo['checked_at']): ?>&middot; Verificato il <?= date('d/m/Y H:i', strtotime($fcInfo['checked_at'])) ?><?php endif; ?>
                            </div>
                            <?php if (!empty($fcSummary)): ?>
                                <div style="color:#94a3b8;font-size:13px;margin-bottom:6px;font-style:italic;"><?= htmlspecialchars($fcSummary) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($fcIssues)): ?>
                                <div id="fc-issues-<?= $postId ?>" style="margin-bottom:8px;">
                                    <ul style="margin:0;padding-left:18px;color:#fca5a5;font-size:12px;">
                                        <?php foreach ($fcIssues as $issue): ?>
                                            <li style="margin-bottom:2px;"><?= htmlspecialchars($issue) ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>
                            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                                <button type="button" class="btn btn-primary btn-sm" id="fc-btn-<?= $postId ?>" onclick="fcCheckSingle(<?= $postId ?>, this)">Verifica</button>
                                <?php if ($fcStatus === 'issues_found'): ?>
                                    <a href="?tab=rewrite&rwsearch=<?= urlencode($postTitle) ?>" style="font-size:12px;color:#fbbf24;text-decoration:underline;">Riscrivi per correggere →</a>
                                <?php endif; ?>
                                <?php if (!empty($postUrl)): ?>
                                    <a href="<?= htmlspecialchars($postUrl) ?>" target="_blank" style="color:#818cf8;font-size:12px;text-decoration:underline;">Vedi articolo</a>
                                <?php endif; ?>
                                <span id="fc-status-<?= $postId ?>" style="font-size:12px;"></span>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

            <!-- Paginazione -->
            <?php if ($fcTotalPages > 1): ?>
            <div style="display:flex;align-items:center;justify-content:center;gap:6px;margin-top:20px;padding-top:16px;border-top:1px solid #334155;flex-wrap:wrap;">
                <?php
                $fcBaseUrl = '?tab=factcheck';
                if ($fcFilterCat > 0) $fcBaseUrl .= '&fccat=' . $fcFilterCat;
                if ($fcFilterStatus !== 'all') $fcBaseUrl .= '&fcstatus=' . urlencode($fcFilterStatus);
                if (!empty($fcSearch)) $fcBaseUrl .= '&fcsearch=' . urlencode($fcSearch);
                ?>
                <?php if ($fcCurrentPage > 1): ?>
                    <a href="<?= $fcBaseUrl ?>&fcpage=1" class="btn btn-sm" style="background:#334155;color:#e2e8f0;text-decoration:none;">« Prima</a>
                    <a href="<?= $fcBaseUrl ?>&fcpage=<?= $fcCurrentPage - 1 ?>" class="btn btn-sm" style="background:#334155;color:#e2e8f0;text-decoration:none;">‹ Prec</a>
                <?php endif; ?>
                <span style="color:#64748b;font-size:13px;"><?= $fcCurrentPage ?> / <?= $fcTotalPages ?></span>
                <?php if ($fcCurrentPage < $fcTotalPages): ?>
                    <a href="<?= $fcBaseUrl ?>&fcpage=<?= $fcCurrentPage + 1 ?>" class="btn btn-sm" style="background:#334155;color:#e2e8f0;text-decoration:none;">Succ ›</a>
                    <a href="<?= $fcBaseUrl ?>&fcpage=<?= $fcTotalPages ?>" class="btn btn-sm" style="background:#334155;color:#e2e8f0;text-decoration:none;">Ultima »</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Progress card -->
    <div class="card" id="fcProgressCard" style="display:none;">
        <h3 style="margin-bottom:12px;">Progresso Fact-Check</h3>
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
            <div style="flex:1;background:#0f172a;border-radius:8px;height:24px;overflow:hidden;border:1px solid #334155;">
                <div id="fcProgressBar" style="height:100%;background:linear-gradient(90deg,#f59e0b,#fbbf24);width:0%;transition:width 0.5s;border-radius:8px;"></div>
            </div>
            <span id="fcProgressPercent" style="color:#94a3b8;font-size:13px;min-width:45px;text-align:right;">0%</span>
        </div>
        <div id="fcLogContainer" style="background:#0f172a;border-radius:8px;border:1px solid #334155;padding:12px;max-height:400px;overflow-y:auto;font-family:'Cascadia Code','Fira Code',monospace;font-size:12px;line-height:1.7;"></div>
        <div id="fcSummary" style="display:none;margin-top:16px;padding:16px;background:#0f172a;border-radius:8px;border:1px solid #334155;"></div>
    </div>

    <?php endif; ?>

