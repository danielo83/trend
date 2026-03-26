    <?php
    // Carica statistiche riscrittura dal database
    $rwDbPath = $config['db_path'] ?? __DIR__ . '/../data/history.sqlite';
    $rwDb = new PDO('sqlite:' . $rwDbPath);
    $rwDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $rwDb->exec("
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

    $rwCompleted = $rwDb->query('SELECT COUNT(*) FROM rewrite_log WHERE status = "completed"')->fetchColumn();
    $rwFailed = $rwDb->query('SELECT COUNT(*) FROM rewrite_log WHERE status = "failed"')->fetchColumn();

    // Mappa stato riscrittura per post_id (con conteggio riscritture)
    $rwStatusMap = [];
    $rwRewriteCounts = [];
    $rwStmt = $rwDb->query('SELECT post_id, status, new_title, rewritten_at FROM rewrite_log ORDER BY rewritten_at DESC');
    while ($rwRow = $rwStmt->fetch(PDO::FETCH_ASSOC)) {
        // Mantieni solo l'ultima riscrittura per post_id nella mappa
        if (!isset($rwStatusMap[$rwRow['post_id']])) {
            $rwStatusMap[$rwRow['post_id']] = $rwRow;
        }
        // Conta il numero totale di riscritture
        $rwRewriteCounts[$rwRow['post_id']] = ($rwRewriteCounts[$rwRow['post_id']] ?? 0) + 1;
    }

    // Carica post da cache WordPress
    $rwCachePath = ($config['base_dir'] ?? __DIR__) . '/data/cache_wp_posts.json';
    $rwAllPosts = [];
    $rwCacheFetchedAt = null;
    if (file_exists($rwCachePath)) {
        $rwCacheData = json_decode(file_get_contents($rwCachePath), true);
        $rwAllPosts = $rwCacheData['posts'] ?? [];
        $rwCacheFetchedAt = $rwCacheData['fetched_at'] ?? null;
    }
    $rwWpTotal = count($rwAllPosts);
    $rwRemaining = max(0, $rwWpTotal - $rwCompleted);

    // Categorie WordPress per il filtro
    $rwWpPublisher = new WordPressPublisher($config);
    $rwCategories = $rwWpPublisher->isEnabled() ? $rwWpPublisher->getCategories() : [];
    $rwCatMap = [];
    foreach ($rwCategories as $cat) {
        $rwCatMap[$cat['id']] = $cat['name'];
    }

    // Filtro categoria dalla query string
    $rwFilterCat = isset($_GET['rwcat']) ? intval($_GET['rwcat']) : 0;
    // Filtro stato: all, pending, completed, failed
    $rwFilterStatus = $_GET['rwstatus'] ?? 'all';
    // Ricerca testo
    $rwSearch = trim($_GET['rwsearch'] ?? '');

    // Applica filtri
    $rwFilteredPosts = [];
    foreach ($rwAllPosts as $post) {
        // Filtro categoria
        if ($rwFilterCat > 0) {
            $postCats = $post['categories'] ?? [];
            if (!in_array($rwFilterCat, $postCats)) continue;
        }

        // Filtro stato riscrittura
        $postRwStatus = $rwStatusMap[$post['id']]['status'] ?? 'pending';
        if ($rwFilterStatus === 'pending' && $postRwStatus !== 'pending') continue;
        if ($rwFilterStatus === 'completed' && $postRwStatus !== 'completed') continue;
        if ($rwFilterStatus === 'failed' && $postRwStatus !== 'failed') continue;

        // Ricerca testo nel titolo
        if (!empty($rwSearch)) {
            if (mb_stripos($post['title'] ?? '', $rwSearch) === false) continue;
        }

        $rwFilteredPosts[] = $post;
    }

    // Paginazione
    $rwPerPage = 20;
    $rwTotalFiltered = count($rwFilteredPosts);
    $rwTotalPages = max(1, ceil($rwTotalFiltered / $rwPerPage));
    $rwCurrentPage = max(1, min($rwTotalPages, intval($_GET['rwpage'] ?? 1)));
    $rwPageOffset = ($rwCurrentPage - 1) * $rwPerPage;
    $rwPagePosts = array_slice($rwFilteredPosts, $rwPageOffset, $rwPerPage);
    ?>
    <div class="header">
        <h2>Riscrittura Articoli</h2>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <button type="button" class="btn btn-primary btn-sm" onclick="rwRefreshCache()">Aggiorna Cache</button>
            <button type="button" class="btn btn-sm" style="background:#334155;color:#e2e8f0;" onclick="rwResetLog()">Resetta Log</button>
        </div>
    </div>

    <!-- Statistiche -->
    <div class="stats">
        <div class="stat-card">
            <div class="label">Post su WordPress</div>
            <div class="value blue"><?= $rwWpTotal ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Gia' riscritti</div>
            <div class="value green"><?= $rwCompleted ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Da riscrivere</div>
            <div class="value <?= $rwRemaining > 0 ? 'yellow' : 'green' ?>"><?= $rwRemaining ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Falliti</div>
            <div class="value <?= $rwFailed > 0 ? 'red' : '' ?>"><?= $rwFailed ?></div>
        </div>
    </div>

    <?php if (empty($rwAllPosts)): ?>
        <div class="card">
            <p style="color:#fbbf24;">Nessun articolo in cache. Clicca "Aggiorna Cache" per caricare i post da WordPress.</p>
        </div>
    <?php else: ?>

    <!-- Filtri e ricerca -->
    <div class="card" style="padding:16px;">
        <div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
            <div>
                <label style="display:block;color:#64748b;font-size:11px;margin-bottom:4px;">Cerca per titolo</label>
                <input type="text" id="rwSearchInput" value="<?= htmlspecialchars($rwSearch) ?>" placeholder="Cerca..." style="padding:8px 12px;background:#0f172a;border:1px solid #334155;border-radius:6px;color:#e2e8f0;font-size:13px;width:200px;">
            </div>
            <div>
                <label style="display:block;color:#64748b;font-size:11px;margin-bottom:4px;">Categoria</label>
                <select id="rwFilterCat" style="padding:8px 12px;background:#0f172a;border:1px solid #334155;border-radius:6px;color:#e2e8f0;font-size:13px;">
                    <option value="0">Tutte</option>
                    <?php foreach ($rwCategories as $cat): ?>
                        <option value="<?= $cat['id'] ?>" <?= $rwFilterCat === $cat['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="display:block;color:#64748b;font-size:11px;margin-bottom:4px;">Stato</label>
                <select id="rwFilterStatus" style="padding:8px 12px;background:#0f172a;border:1px solid #334155;border-radius:6px;color:#e2e8f0;font-size:13px;">
                    <option value="all" <?= $rwFilterStatus === 'all' ? 'selected' : '' ?>>Tutti</option>
                    <option value="pending" <?= $rwFilterStatus === 'pending' ? 'selected' : '' ?>>Da riscrivere</option>
                    <option value="completed" <?= $rwFilterStatus === 'completed' ? 'selected' : '' ?>>Riscritti</option>
                    <option value="failed" <?= $rwFilterStatus === 'failed' ? 'selected' : '' ?>>Falliti</option>
                </select>
            </div>
            <button type="button" class="btn btn-primary btn-sm" onclick="rwApplyFilters()">Filtra</button>
        </div>
        <div style="margin-top:8px;color:#64748b;font-size:12px;">
            <?= $rwTotalFiltered ?> articoli trovati
            <?php if ($rwCacheFetchedAt): ?>
                &middot; Cache aggiornata il <?= date('d/m/Y H:i', $rwCacheFetchedAt) ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Barra selezione e azioni bulk -->
    <div class="card" style="padding:12px 16px;">
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;color:#94a3b8;font-size:13px;">
                <input type="checkbox" id="rwSelectAll" onchange="rwToggleSelectAll(this)" style="width:16px;height:16px;accent-color:#6366f1;">
                Seleziona pagina
            </label>
            <span id="rwSelectedCount" style="color:#64748b;font-size:13px;"></span>
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;color:#94a3b8;font-size:13px;margin-left:auto;">
                <input type="checkbox" id="rwNewImages" style="width:16px;height:16px;accent-color:#6366f1;">
                Rigenera immagini
            </label>
            <button type="button" class="btn btn-success btn-sm" id="rwBulkBtn" style="display:none;" onclick="rwStartSelected()">
                Riscrivi selezionati (<span id="rwBulkCount">0</span>)
            </button>
        </div>
    </div>

    <!-- Lista articoli -->
    <div class="card">
        <?php if (empty($rwPagePosts)): ?>
            <p style="color:#64748b;">Nessun articolo corrisponde ai filtri selezionati.</p>
        <?php else: ?>
            <?php foreach ($rwPagePosts as $post):
                $postId = $post['id'];
                $postTitle = $post['title'] ?? '';
                $postUrl = $post['url'] ?? $post['link'] ?? '';
                $postExcerpt = $post['excerpt'] ?? '';
                $postCats = $post['categories'] ?? [];
                $postRwInfo = $rwStatusMap[$postId] ?? null;
                $postRwStatus = $postRwInfo['status'] ?? 'pending';

                // Nomi categorie
                $postCatNames = [];
                foreach ($postCats as $cid) {
                    $postCatNames[] = $rwCatMap[$cid] ?? "#{$cid}";
                }
            ?>
                <div class="feed-item" id="rw-item-<?= $postId ?>" style="position:relative;">
                    <div style="display:flex;align-items:flex-start;gap:10px;">
                        <input type="checkbox" class="rw-checkbox" value="<?= $postId ?>" data-title="<?= htmlspecialchars($postTitle) ?>" onchange="rwUpdateBulkUI()" style="width:16px;height:16px;margin-top:3px;accent-color:#6366f1;flex-shrink:0;">
                        <div style="flex:1;min-width:0;">
                            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:4px;">
                                <h4 style="margin:0;"><?= htmlspecialchars($postTitle) ?></h4>
                                <?php 
                                $rewriteCount = $rwRewriteCounts[$postId] ?? 0;
                                if ($postRwStatus === 'completed'):
                                    $badgeText = $rewriteCount > 1 ? "Riscritto {$rewriteCount}x" : 'Riscritto';
                                ?>
                                    <span style="display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;background:#064e3b;color:#34d399;"><?= $badgeText ?></span>
                                <?php elseif ($postRwStatus === 'failed'): ?>
                                    <span style="display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;background:#7f1d1d;color:#f87171;">Fallito</span>
                                <?php else: ?>
                                    <span style="display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;background:#1e293b;color:#94a3b8;">Da riscrivere</span>
                                <?php endif; ?>
                            </div>
                            <div class="date" style="margin-bottom:6px;">
                                ID: <?= $postId ?>
                                <?php if (!empty($postCatNames)): ?>
                                    &middot; <?= htmlspecialchars(implode(', ', $postCatNames)) ?>
                                <?php endif; ?>
                                <?php if ($postRwInfo && $postRwInfo['rewritten_at']): ?>
                                    &middot; Riscritto il <?= htmlspecialchars(date('d/m/Y H:i', strtotime($postRwInfo['rewritten_at']))) ?>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($postExcerpt)): ?>
                                <div style="color:#94a3b8;font-size:13px;margin-bottom:8px;line-height:1.5;"><?= mb_substr(strip_tags(html_entity_decode($postExcerpt, ENT_QUOTES, 'UTF-8')), 0, 200) ?>...</div>
                            <?php endif; ?>
                            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                                <button type="button" class="btn btn-primary btn-sm" id="rw-btn-<?= $postId ?>" onclick="rwRewriteSingle(<?= $postId ?>, this)">Riscrivi</button>
                                <?php if (!empty($postUrl)): ?>
                                    <a href="<?= htmlspecialchars($postUrl) ?>" target="_blank" style="color:#818cf8;font-size:12px;text-decoration:underline;">Vedi articolo</a>
                                <?php endif; ?>
                                <span id="rw-status-<?= $postId ?>" style="font-size:12px;"></span>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

            <!-- Paginazione -->
            <?php if ($rwTotalPages > 1): ?>
            <div style="display:flex;align-items:center;justify-content:center;gap:6px;margin-top:20px;padding-top:16px;border-top:1px solid #334155;flex-wrap:wrap;">
                <?php
                // Costruisci base URL per paginazione con filtri
                $rwBaseUrl = '?tab=rewrite';
                if ($rwFilterCat > 0) $rwBaseUrl .= '&rwcat=' . $rwFilterCat;
                if ($rwFilterStatus !== 'all') $rwBaseUrl .= '&rwstatus=' . urlencode($rwFilterStatus);
                if (!empty($rwSearch)) $rwBaseUrl .= '&rwsearch=' . urlencode($rwSearch);
                ?>
                <?php if ($rwCurrentPage > 1): ?>
                    <a href="<?= $rwBaseUrl ?>&rwpage=1" class="btn btn-sm" style="background:#334155;color:#e2e8f0;text-decoration:none;">« Prima</a>
                    <a href="<?= $rwBaseUrl ?>&rwpage=<?= $rwCurrentPage - 1 ?>" class="btn btn-sm" style="background:#334155;color:#e2e8f0;text-decoration:none;">‹ Prec</a>
                <?php endif; ?>

                <?php
                $rwPagRange = 3;
                $rwStartPage = max(1, $rwCurrentPage - $rwPagRange);
                $rwEndPage = min($rwTotalPages, $rwCurrentPage + $rwPagRange);
                if ($rwEndPage - $rwStartPage < $rwPagRange * 2) {
                    $rwStartPage = max(1, $rwEndPage - $rwPagRange * 2);
                    $rwEndPage = min($rwTotalPages, $rwStartPage + $rwPagRange * 2);
                }
                for ($p = $rwStartPage; $p <= $rwEndPage; $p++):
                ?>
                    <?php if ($p === $rwCurrentPage): ?>
                        <span class="btn btn-sm" style="background:#6366f1;color:white;cursor:default;"><?= $p ?></span>
                    <?php else: ?>
                        <a href="<?= $rwBaseUrl ?>&rwpage=<?= $p ?>" class="btn btn-sm" style="background:#1e293b;color:#94a3b8;text-decoration:none;"><?= $p ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($rwCurrentPage < $rwTotalPages): ?>
                    <a href="<?= $rwBaseUrl ?>&rwpage=<?= $rwCurrentPage + 1 ?>" class="btn btn-sm" style="background:#334155;color:#e2e8f0;text-decoration:none;">Succ ›</a>
                    <a href="<?= $rwBaseUrl ?>&rwpage=<?= $rwTotalPages ?>" class="btn btn-sm" style="background:#334155;color:#e2e8f0;text-decoration:none;">Ultima »</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php endif; /* fine if rwAllPosts */ ?>

    <!-- Progresso live -->
    <div class="card" id="rwProgressCard" style="display:none;">
        <h3 style="margin-bottom:12px;">Progresso</h3>
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
            <div style="flex:1;background:#0f172a;border-radius:8px;height:24px;overflow:hidden;border:1px solid #334155;">
                <div id="rwProgressBar" style="height:100%;background:linear-gradient(90deg,#6366f1,#818cf8);width:0%;transition:width 0.5s;border-radius:8px;"></div>
            </div>
            <span id="rwProgressPercent" style="color:#94a3b8;font-size:13px;min-width:45px;text-align:right;">0%</span>
        </div>
        <div id="rwLogContainer" style="background:#0f172a;border-radius:8px;border:1px solid #334155;padding:12px;max-height:400px;overflow-y:auto;font-family:'Cascadia Code','Fira Code',monospace;font-size:12px;line-height:1.7;">
        </div>
        <div id="rwSummary" style="display:none;margin-top:16px;padding:16px;background:#0f172a;border-radius:8px;border:1px solid #334155;">
        </div>
    </div>

