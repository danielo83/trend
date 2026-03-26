    <?php
    require_once __DIR__ . '/src/LinkBuilder.php';
    require_once __DIR__ . '/src/SmartLinkBuilder.php';
    $lb = new SmartLinkBuilder($config);
    $lbEnabled = $lb->isEnabled();
    $wpArticles = [];
    $wpArticlesError = '';

    if ($lbEnabled) {
        $cacheInfo = $lb->getCacheInfo();
        if ($cacheInfo['count'] === 0) {
            $lb->refreshCache();
            $cacheInfo = $lb->getCacheInfo();
        }
        // Carica articoli dalla cache
        $cachePath = ($config['base_dir'] ?? __DIR__) . '/data/cache_wp_posts.json';
        if (file_exists($cachePath)) {
            $cacheData = json_decode(file_get_contents($cachePath), true);
            $wpArticles = $cacheData['posts'] ?? [];
        }
        if (empty($wpArticles)) {
            $wpArticlesError = 'Nessun articolo trovato nella cache. Clicca "Aggiorna Cache" per caricare gli articoli da WordPress.';
        }
    } else {
        $wpArticlesError = 'Link Building non abilitato. Abilita i link interni nella tab Configurazione e assicurati che WordPress sia configurato.';
    }

    // Conta link interni per ogni articolo
    $wpUrl = rtrim($config['wp_site_url'] ?? '', '/');
    $siteHost = parse_url($wpUrl, PHP_URL_HOST);
    ?>
    <div class="header">
        <h2>Link Building</h2>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <?php if ($lbEnabled): ?>
                <button type="button" class="btn btn-primary btn-sm" onclick="lbRefreshCache()">Aggiorna Cache</button>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($lbEnabled): ?>
    <div class="stats">
        <div class="stat-card">
            <div class="label">Articoli in Cache</div>
            <div class="value blue" id="lbCacheCount"><?= count($wpArticles) ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Link interni abilitati</div>
            <div class="value <?= !empty($config['link_internal_enabled']) ? 'green' : '' ?>"><?= !empty($config['link_internal_enabled']) ? 'SI' : 'NO' ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Max link interni</div>
            <div class="value purple"><?= $config['link_max_internal'] ?? 5 ?></div>
        </div>
    </div>
    <?php endif; ?>

    <div id="lbStatusBar" style="display:none;margin-bottom:16px;padding:12px 16px;border-radius:8px;font-size:13px;"></div>

    <?php if (!empty($wpArticlesError)): ?>
        <div class="card">
            <p style="color: #fbbf24;"><?= htmlspecialchars($wpArticlesError) ?></p>
        </div>
    <?php elseif (!empty($wpArticles)): ?>
        <?php
        // --- Paginazione ---
        $lbPerPage = 20;
        $lbTotalArticles = count($wpArticles);
        $lbTotalPages = max(1, ceil($lbTotalArticles / $lbPerPage));
        $lbCurrentPage = max(1, min($lbTotalPages, intval($_GET['lbpage'] ?? 1)));
        $lbOffset = ($lbCurrentPage - 1) * $lbPerPage;
        $lbPageArticles = array_slice($wpArticles, $lbOffset, $lbPerPage);
        ?>
        <div class="card">
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:12px;">
                <h3 style="margin:0;">Articoli WordPress</h3>
                <span style="color:#64748b;font-size:13px;">Pagina <?= $lbCurrentPage ?> di <?= $lbTotalPages ?> (<?= $lbTotalArticles ?> articoli)</span>
            </div>

            <!-- Barra selezione e azioni bulk -->
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;padding:12px;background:#0f172a;border-radius:8px;border:1px solid #334155;flex-wrap:wrap;">
                <label style="display:flex;align-items:center;gap:6px;cursor:pointer;color:#94a3b8;font-size:13px;">
                    <input type="checkbox" id="lbSelectAll" onchange="lbToggleSelectAll(this)" style="width:16px;height:16px;accent-color:#6366f1;">
                    Seleziona pagina
                </label>
                <span id="lbSelectedCount" style="color:#64748b;font-size:13px;"></span>
                <button type="button" class="btn btn-primary btn-sm" id="lbBulkBtn" style="display:none;margin-left:auto;" onclick="lbRelinkSelected()">
                    Applica Link Building (<span id="lbBulkCount">0</span> selezionati)
                </button>
            </div>

            <!-- Lista articoli della pagina corrente -->
            <?php foreach ($lbPageArticles as $idx => $article):
                $articleTitle = $article['title'] ?? '';
                $articleUrl = $article['url'] ?? $article['link'] ?? '';
                $articleExcerpt = $article['excerpt'] ?? '';
                $articleId = $article['id'] ?? 0;
                $articleDate = $article['date'] ?? '';
            ?>
                <div class="feed-item" id="lb-item-<?= $articleId ?>" style="position:relative;">
                    <div style="display:flex;align-items:flex-start;gap:10px;">
                        <input type="checkbox" class="lb-checkbox" value="<?= $articleId ?>" onchange="lbUpdateBulkUI()" style="width:16px;height:16px;margin-top:3px;accent-color:#6366f1;flex-shrink:0;">
                        <div style="flex:1;min-width:0;">
                            <h4 style="margin-bottom:4px;"><?= htmlspecialchars($articleTitle) ?></h4>
                            <div class="date" style="margin-bottom:6px;">
                                <?php if (!empty($articleDate)): ?>
                                    <?= htmlspecialchars(date('d/m/Y H:i', strtotime($articleDate))) ?>
                                <?php endif; ?>
                                <span id="lb-links-badge-<?= $articleId ?>" class="badge links-no" style="margin-left:8px;">
                                    <span class="link-indicator">⏳ Verifica link...</span>
                                </span>
                            </div>
                            <?php if (!empty($articleExcerpt)): ?>
                                <div style="color:#94a3b8;font-size:13px;margin-bottom:8px;line-height:1.5;"><?= mb_substr(strip_tags(html_entity_decode($articleExcerpt, ENT_QUOTES, 'UTF-8')), 0, 200) ?>...</div>
                            <?php endif; ?>
                            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                                <button type="button" class="btn btn-primary btn-sm" id="lb-btn-<?= $articleId ?>" onclick="lbRelinkSingle(<?= $articleId ?>, this)">Applica Link Building</button>
                                <?php if (!empty($articleUrl)): ?>
                                    <a href="<?= htmlspecialchars($articleUrl) ?>" target="_blank" style="color:#818cf8;font-size:12px;text-decoration:underline;">Vedi articolo</a>
                                <?php endif; ?>
                                <span id="lb-status-<?= $articleId ?>" style="font-size:12px;"></span>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

            <!-- Paginazione -->
            <?php if ($lbTotalPages > 1): ?>
            <div style="display:flex;align-items:center;justify-content:center;gap:6px;margin-top:20px;padding-top:16px;border-top:1px solid #334155;flex-wrap:wrap;">
                <?php if ($lbCurrentPage > 1): ?>
                    <a href="?tab=linkbuilding&lbpage=1" class="btn btn-sm" style="background:#334155;color:#e2e8f0;text-decoration:none;">« Prima</a>
                    <a href="?tab=linkbuilding&lbpage=<?= $lbCurrentPage - 1 ?>" class="btn btn-sm" style="background:#334155;color:#e2e8f0;text-decoration:none;">‹ Prec</a>
                <?php endif; ?>

                <?php
                // Mostra al massimo 7 pagine centrate sulla corrente
                $paginationRange = 3;
                $startPage = max(1, $lbCurrentPage - $paginationRange);
                $endPage = min($lbTotalPages, $lbCurrentPage + $paginationRange);
                // Aggiusta per avere sempre ~7 pagine visibili
                if ($endPage - $startPage < $paginationRange * 2) {
                    $startPage = max(1, $endPage - $paginationRange * 2);
                    $endPage = min($lbTotalPages, $startPage + $paginationRange * 2);
                }
                for ($p = $startPage; $p <= $endPage; $p++):
                ?>
                    <?php if ($p === $lbCurrentPage): ?>
                        <span class="btn btn-sm" style="background:#6366f1;color:white;cursor:default;"><?= $p ?></span>
                    <?php else: ?>
                        <a href="?tab=linkbuilding&lbpage=<?= $p ?>" class="btn btn-sm" style="background:#1e293b;color:#94a3b8;text-decoration:none;"><?= $p ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($lbCurrentPage < $lbTotalPages): ?>
                    <a href="?tab=linkbuilding&lbpage=<?= $lbCurrentPage + 1 ?>" class="btn btn-sm" style="background:#334155;color:#e2e8f0;text-decoration:none;">Succ ›</a>
                    <a href="?tab=linkbuilding&lbpage=<?= $lbTotalPages ?>" class="btn btn-sm" style="background:#334155;color:#e2e8f0;text-decoration:none;">Ultima »</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

