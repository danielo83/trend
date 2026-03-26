    <?php
    // SEO Analytics Dashboard
    require_once __DIR__ . '/src/SEOOptimizer.php';
    require_once __DIR__ . '/src/ContentAnalytics.php';
    require_once __DIR__ . '/src/SEOMonitor.php';
    require_once __DIR__ . '/src/MaxSEOGEOConfig.php';
    
    $analytics = new ContentAnalytics(['base_dir' => __DIR__]);
    $report = $analytics->generateReport();
    $monitor = new SEOMonitor(['base_dir' => __DIR__]);
    $performanceReport = $monitor->getPerformanceReport();
    $targets = MaxSEOGEOConfig::getTargetMetrics();
    ?>
    
    <div class="header">
        <h2>📊 SEO Analytics</h2>
    </div>
    
    <!-- Target SEO/GEO -->
    <div class="card" style="background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);margin-bottom:25px;">
        <h3>🎯 Target SEO & GEO</h3>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:15px;">
            <div style="text-align:center;padding:15px;background:#0f172a;border-radius:8px;">
                <div style="font-size:28px;font-weight:700;color:#4ade80;">90+</div>
                <div style="font-size:11px;color:#64748b;text-transform:uppercase;">SEO Score Target</div>
            </div>
            <div style="text-align:center;padding:15px;background:#0f172a;border-radius:8px;">
                <div style="font-size:28px;font-weight:700;color:#60a5fa;">85+</div>
                <div style="font-size:11px;color:#64748b;text-transform:uppercase;">GEO Score Target</div>
            </div>
            <div style="text-align:center;padding:15px;background:#0f172a;border-radius:8px;">
                <div style="font-size:28px;font-weight:700;color:#a78bfa;">70+</div>
                <div style="font-size:11px;color:#64748b;text-transform:uppercase;">Snippet Potential</div>
            </div>
            <div style="text-align:center;padding:15px;background:#0f172a;border-radius:8px;">
                <div style="font-size:28px;font-weight:700;color:#fbbf24;">1500+</div>
                <div style="font-size:11px;color:#64748b;text-transform:uppercase;">Parole Minimo</div>
            </div>
        </div>
    </div>
    
    <!-- Statistiche Principali -->
    <div class="stats-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:15px;margin-bottom:25px;">
        <div class="stat-card">
            <div class="label">Articoli Totali</div>
            <div class="value"><?= $report['summary']['total_articles'] ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Indicizzati</div>
            <div class="value green"><?= $report['summary']['indexed'] ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Featured Snippets</div>
            <div class="value purple"><?= $report['summary']['with_featured_snippet'] ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Posizione Media</div>
            <div class="value orange"><?= $report['summary']['avg_position'] ?? 'N/A' ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Traffico Stimato</div>
            <div class="value blue"><?= number_format($report['summary']['total_estimated_traffic']) ?></div>
        </div>
    </div>
    
    <!-- Raccomandazioni -->
    <?php if (!empty($report['recommendations'])): ?>
    <div class="card" style="border-left:4px solid #f59e0b;">
        <h3>💡 Raccomandazioni</h3>
        <ul style="margin:0;padding-left:20px;">
            <?php foreach ($report['recommendations'] as $rec): ?>
                <li style="margin-bottom:8px;color:#e2e8f0;"><?= htmlspecialchars($rec) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>
    
    <!-- Alert SEO -->
    <?php if (!empty($performanceReport['alerts'])): ?>
    <div class="card" style="border-left:4px solid #dc2626;">
        <h3>🚨 Alert SEO Recenti</h3>
        <div style="display:flex;flex-direction:column;gap:10px;">
            <?php foreach (array_slice($performanceReport['alerts'], 0, 5) as $alert): ?>
            <div style="padding:12px;background:#0f172a;border-radius:6px;">
                <span style="font-size:11px;padding:2px 8px;border-radius:4px;background:<?= $alert['severity'] === 'critical' ? '#dc2626' : '#f59e0b' ?>;color:white;">
                    <?= strtoupper($alert['severity']) ?>
                </span>
                <p style="margin:8px 0 0 0;color:#e2e8f0;"><?= htmlspecialchars($alert['message']) ?></p>
                <p style="margin:5px 0 0 0;font-size:12px;color:#818cf8;">💡 <?= htmlspecialchars($alert['suggestion']) ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Performance Overview -->
    <div class="card">
        <h3>📈 Performance Overview</h3>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:15px;">
            <div style="padding:15px;background:#0f172a;border-radius:8px;text-align:center;">
                <div style="font-size:32px;font-weight:700;color:#4ade80;"><?= $performanceReport['summary']['position_1_3'] ?></div>
                <div style="font-size:12px;color:#64748b;">Posizioni 1-3</div>
            </div>
            <div style="padding:15px;background:#0f172a;border-radius:8px;text-align:center;">
                <div style="font-size:32px;font-weight:700;color:#60a5fa;"><?= $performanceReport['summary']['position_4_10'] ?></div>
                <div style="font-size:12px;color:#64748b;">Posizioni 4-10</div>
            </div>
            <div style="padding:15px;background:#0f172a;border-radius:8px;text-align:center;">
                <div style="font-size:32px;font-weight:700;color:#f87171;"><?= $performanceReport['summary']['position_11_plus'] ?></div>
                <div style="font-size:12px;color:#64748b;">Posizioni 11+</div>
            </div>
        </div>
    </div>
    
    <!-- Top Performers -->
    <?php if (!empty($report['top_performers'])): ?>
    <div class="card">
        <h3>🏆 Top Performers</h3>
        <div style="overflow-x:auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Titolo</th>
                        <th>Posizione</th>
                        <th>Traffico Stimato</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($report['top_performers'] as $article): ?>
                    <tr>
                        <td><a href="<?= htmlspecialchars($article['url']) ?>" target="_blank" style="color:#60a5fa;"><?= htmlspecialchars(mb_substr($article['title'], 0, 60)) ?>...</a></td>
                        <td style="color:#4ade80;font-weight:600;">#<?= $article['position'] ?></td>
                        <td><?= number_format($article['traffic']) ?> visite/mese</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Articoli da Migliorare -->
    <?php if (!empty($report['underperforming'])): ?>
    <div class="card">
        <h3>⚠️ Articoli da Migliorare</h3>
        <div style="overflow-x:auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Titolo</th>
                        <th>Problema</th>
                        <th>Raccomandazione</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($report['underperforming'], 0, 10) as $article): ?>
                    <tr>
                        <td><a href="<?= htmlspecialchars($article['url']) ?>" target="_blank" style="color:#60a5fa;"><?= htmlspecialchars(mb_substr($article['title'], 0, 50)) ?>...</a></td>
                        <td>
                            <?php if ($article['issue'] === 'not_indexed'): ?>
                                <span style="color:#f87171;">Non indicizzato</span>
                            <?php else: ?>
                                <span style="color:#fbbf24;">Posizione bassa</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:12px;color:#94a3b8;"><?= htmlspecialchars($article['recommendation']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Analisi SEO Articoli nel Feed -->
    <div class="card">
        <h3>🔍 Analisi SEO Articoli Recenti</h3>
        <?php
        $feedBuilder = new RSSFeedBuilder($config);
        $items = array_slice($feedBuilder->getItems(), 0, 5);
        
        if (!empty($items)):
            $optimizer = new SEOOptimizer();
        ?>
        <div style="display:flex;flex-direction:column;gap:15px;">
            <?php foreach ($items as $idx => $item): 
                $analysis = $optimizer->analyzeArticle(
                    $item['title'],
                    $item['content'],
                    $item['meta_description'] ?? '',
                    strip_tags($item['title'])
                );
                $score = $analysis['overall_score'];
                $scoreColor = $score >= 70 ? '#4ade80' : ($score >= 50 ? '#fbbf24' : '#f87171');
            ?>
            <div style="padding:15px;background:#0f172a;border-radius:8px;border:1px solid #334155;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                    <h4 style="margin:0;font-size:14px;color:#f1f5f9;"><?= htmlspecialchars(mb_substr($item['title'], 0, 60)) ?>...</h4>
                    <span style="font-size:18px;font-weight:700;color:<?= $scoreColor ?>;"><?= $score ?>/100</span>
                </div>
                <div style="display:flex;gap:15px;font-size:12px;color:#94a3b8;margin-bottom:10px;">
                    <span>SEO: <?= $analysis['seo_score'] ?></span>
                    <span>GEO: <?= $analysis['geo_score'] ?></span>
                    <span>Leggibilità: <?= $analysis['readability_score'] ?></span>
                </div>
                <?php if (!empty($analysis['suggestions'])): ?>
                <div style="font-size:11px;color:#64748b;">
                    💡 <?= htmlspecialchars($analysis['suggestions'][0]) ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
            <p style="color:#64748b;">Nessun articolo nel feed da analizzare.</p>
        <?php endif; ?>
    </div>

