    <div class="header">
        <h2>Panoramica</h2>
        <div>
            <a href="run.php" class="btn btn-success" style="margin-right:10px;">▶️ Esegui con Log</a>
            <button type="button" class="btn" style="background:#7c3aed;color:#fff;margin-right:10px;" onclick="openCustomRun()">🎯 Esegui Custom</button>
            <form method="post" style="display:inline;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="run_now">
                <button type="submit" class="btn btn-primary">Esegui Silenzioso</button>
            </form>
        </div>
    </div>

    <div class="stats">
        <div class="stat-card">
            <div class="label">Articoli nel Feed</div>
            <div class="value blue"><?= $feedCount ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Topic Elaborati</div>
            <div class="value green"><?= $dbStats['completed'] ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Topic Totali</div>
            <div class="value purple"><?= $dbStats['total'] ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Topic Scartati</div>
            <div class="value" style="color: #fca5a5;"><?= $dbStats['skipped'] ?? 0 ?></div>
        </div>
        <div class="stat-card">
            <div class="label"><?= ($config['keyword_source'] ?? 'google') === 'manual' ? 'Keyword Manuali' : 'Semi di Ricerca' ?></div>
            <div class="value orange"><?= ($config['keyword_source'] ?? 'google') === 'manual' ? count($config['manual_keywords'] ?? []) : count($config['semi_ricerca']) ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Link Building</div>
            <div class="value <?= !empty($config['link_internal_enabled']) ? 'green' : '' ?>"><?= !empty($config['link_internal_enabled']) ? 'ATTIVO' : 'OFF' ?></div>
        </div>
    </div>

    <div class="card">
        <h3>Ultimi Articoli Generati</h3>
        <?php if (empty($feedItems)): ?>
            <p style="color: #64748b;">Nessun articolo ancora generato. Clicca "Esegui Ora" per avviare.</p>
        <?php else: ?>
            <table>
                <thead><tr><th>Titolo</th><th>Link</th><th>WP</th><th>Data</th></tr></thead>
                <tbody>
                <?php foreach (array_slice($feedItems, 0, 10) as $item):
                    $wpUrl = $config['wp_site_url'] ?? '';
                    $intLinks = countInternalLinks($item['content'], $wpUrl);
                    $extLinks = countExternalLinks($item['content'], $wpUrl);
                    $hasLinks = $intLinks > 0;
                ?>
                    <tr>
                        <td><?= htmlspecialchars($item['title']) ?></td>
                        <td style="white-space:nowrap;">
                            <?php if ($hasLinks): ?>
                                <span class="badge links-yes" title="<?= $intLinks ?> link interni, <?= $extLinks ?> esterni">🔗 <?= $intLinks ?>i / <?= $extLinks ?>e</span>
                            <?php else: ?>
                                <span class="badge links-no" title="Nessun link interno rilevato">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="white-space:nowrap;">
                            <?php if (!empty($item['wp_post_id'])): ?>
                                <span class="badge completed" title="Pubblicato su WordPress">✓ WP</span>
                            <?php else: ?>
                                <span class="badge links-no">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="white-space:nowrap;"><?= htmlspecialchars($item['pubDate']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>Ultimi Topic Trovati</h3>
        <?php if (empty($recentTopics)): ?>
            <p style="color: #64748b;">Nessun topic ancora elaborato.</p>
        <?php else: ?>
            <table>
                <thead><tr><th>Topic</th><th>Stato</th><th>Data</th></tr></thead>
                <tbody>
                <?php foreach (array_slice($recentTopics, 0, 10) as $t): ?>
                    <tr>
                        <td><?= htmlspecialchars($t['topic']) ?></td>
                        <td><span class="badge <?= htmlspecialchars($t['status']) ?>"><?= htmlspecialchars($t['status']) ?></span></td>
                        <td style="white-space:nowrap;"><?= htmlspecialchars($t['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    
    <!-- SEO/GEO Scores Overview -->
    <div class="card">
        <h3>🔍 SEO & GEO Scores - Ultimi Articoli</h3>
        <?php
        require_once __DIR__ . '/../src/SEOOptimizer.php';
        $optimizer = new SEOOptimizer();
        $recentItems = array_slice($feedItems, 0, 5);
        ?>
        <?php if (!empty($recentItems)): ?>
        <div style="display:flex;flex-direction:column;gap:12px;">
            <?php foreach ($recentItems as $item): 
                $analysis = $optimizer->analyzeArticle(
                    $item['title'],
                    $item['content'],
                    $item['meta_description'] ?? '',
                    strip_tags($item['title'])
                );
                $overallScore = $analysis['overall_score'];
                $scoreColor = $overallScore >= 90 ? '#4ade80' : ($overallScore >= 80 ? '#60a5fa' : ($overallScore >= 70 ? '#fbbf24' : '#f87171'));
                $scoreBg = $overallScore >= 90 ? 'rgba(74, 222, 128, 0.1)' : ($overallScore >= 80 ? 'rgba(96, 165, 250, 0.1)' : ($overallScore >= 70 ? 'rgba(251, 191, 36, 0.1)' : 'rgba(248, 113, 113, 0.1)'));
            ?>
            <div style="padding:15px;background:<?= $scoreBg ?>;border-radius:8px;border:1px solid #334155;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                    <h4 style="margin:0;font-size:14px;color:#f1f5f9;max-width:70%;"><?= htmlspecialchars(mb_substr($item['title'], 0, 50)) ?>...</h4>
                    <span style="font-size:20px;font-weight:700;color:<?= $scoreColor ?>;"><?= $overallScore ?>/100</span>
                </div>
                <div style="display:flex;gap:20px;font-size:12px;">
                    <span style="color:#94a3b8;">SEO: <strong style="color:#60a5fa;"><?= $analysis['seo_score'] ?></strong></span>
                    <span style="color:#94a3b8;">GEO: <strong style="color:#a78bfa;"><?= $analysis['geo_score'] ?></strong></span>
                    <span style="color:#94a3b8;">Leggibilità: <strong style="color:#4ade80;"><?= $analysis['readability_score'] ?></strong></span>
                    <span style="color:#94a3b8;">Tecnico: <strong style="color:#fbbf24;"><?= $analysis['technical_score'] ?></strong></span>
                </div>
                <?php if (!empty($analysis['suggestions'])): ?>
                <div style="margin-top:8px;font-size:11px;color:#64748b;">
                    💡 <?= htmlspecialchars($analysis['suggestions'][0]) ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <div style="margin-top:15px;padding:10px;background:#0f172a;border-radius:6px;font-size:12px;color:#64748b;">
            <strong>Target:</strong> SEO 90+ | GEO 85+ | Leggibilità 70+ | Tecnico 90+
            <a href="?tab=seo" style="color:#60a5fa;margin-left:15px;">Vedi dettagli →</a>
        </div>
        <?php else: ?>
            <p style="color: #64748b;">Nessun articolo da analizzare.</p>
        <?php endif; ?>
    </div>

