    <?php
    // Content Hub Manager
    require_once __DIR__ . '/../src/ContentHubManager.php';
    require_once __DIR__ . '/../src/MaxSEOGEOConfig.php';
    
    $hub = new ContentHubManager(['base_dir' => __DIR__]);
    $hubReport = $hub->generateHubReport();
    $suggestions = $hub->suggestNextArticle();
    $topicMenu = $hub->generateTopicMenu();
    ?>
    
    <div class="header">
        <h2>🏛️ Content Hub & Topic Clusters</h2>
        <button type="button" class="btn btn-primary btn-sm" onclick="location.reload()">↻ Aggiorna</button>
    </div>

    <!-- Stats overview -->
    <?php
        $totalRecs = count($hubReport['recommendations'] ?? []);
        $criticalRecs = count(array_filter($hubReport['recommendations'] ?? [], fn($r) => $r['priority'] === 'CRITICAL'));
        $totalTopics = count($hubReport['topic_coverage'] ?? []);
        $completeTopics = count(array_filter($hubReport['topic_coverage'] ?? [], fn($t) => $t['coverage_percent'] >= 80));
    ?>
    <div class="stats-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:15px;margin-bottom:25px;">
        <div class="stat-card">
            <div class="label">Coverage Score</div>
            <div class="value <?= $hubReport['overview']['coverage_score'] >= 80 ? 'green' : ($hubReport['overview']['coverage_score'] >= 50 ? 'orange' : 'red') ?>">
                <?= $hubReport['overview']['coverage_score'] ?>%
            </div>
        </div>
        <div class="stat-card">
            <div class="label">Pillar Content</div>
            <div class="value blue"><?= $hubReport['overview']['total_pillars'] ?>/<?= $totalTopics ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Cluster Articles</div>
            <div class="value purple"><?= $hubReport['overview']['total_clusters'] ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Topic Completi</div>
            <div class="value <?= $completeTopics === $totalTopics ? 'green' : 'orange' ?>"><?= $completeTopics ?>/<?= $totalTopics ?></div>
        </div>
        <?php if ($criticalRecs > 0): ?>
        <div class="stat-card">
            <div class="label">Azioni Critiche</div>
            <div class="value red"><?= $criticalRecs ?></div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Suggerimenti articoli da creare -->
    <?php if (!empty($suggestions)): ?>
    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;">
            <h3 style="margin:0;">🎯 Articoli Consigliati da Creare</h3>
            <span style="font-size:12px;color:#64748b;"><?= count($suggestions) ?> suggerimenti</span>
        </div>
        <div style="display:flex;flex-direction:column;gap:10px;">
        <?php foreach ($suggestions as $sIdx => $sug): ?>
        <?php
            $borderColor = $sug['priority'] === 'CRITICAL' ? '#dc2626' : ($sug['priority'] === 'HIGH' ? '#f59e0b' : '#60a5fa');
            $bgPriority  = $sug['priority'] === 'CRITICAL' ? '#dc2626' : ($sug['priority'] === 'HIGH' ? '#d97706' : '#2563eb');
        ?>
        <div style="padding:14px;background:#0f172a;border-radius:8px;border-left:3px solid <?= $borderColor ?>;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap;">
                <div style="flex:1;min-width:0;">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;flex-wrap:wrap;">
                        <span style="font-size:15px;font-weight:600;color:#f1f5f9;"><?= htmlspecialchars($sug['keyword']) ?></span>
                        <span style="padding:2px 8px;background:<?= $bgPriority ?>;color:white;border-radius:4px;font-size:10px;font-weight:600;"><?= $sug['priority'] ?></span>
                        <span style="padding:2px 8px;background:#1e293b;color:#94a3b8;border-radius:4px;font-size:10px;border:1px solid #334155;"><?= htmlspecialchars($sug['type']) ?></span>
                    </div>
                    <p style="color:#64748b;font-size:12px;margin:0 0 4px 0;"><?= htmlspecialchars($sug['reason']) ?></p>
                    <p style="color:#818cf8;font-size:11px;margin:0;">💡 <?= htmlspecialchars($sug['expected_impact']) ?></p>
                </div>
                <div class="generate-container" id="generate-container-sug-<?= $sIdx ?>" style="flex-shrink:0;">
                    <button type="button" class="btn btn-sm btn-success generate-btn"
                            data-keyword="<?= htmlspecialchars($sug['keyword']) ?>"
                            data-topic="<?= htmlspecialchars($sug['topic']) ?>"
                            data-index="sug-<?= $sIdx ?>">
                        ✨ Crea
                    </button>
                    <div class="generate-progress" style="display:none;margin-top:8px;min-width:200px;">
                        <div class="progress-bar" style="width:100%;height:4px;background:#334155;border-radius:2px;overflow:hidden;">
                            <div class="progress-fill" style="width:0%;height:100%;background:#22c55e;transition:width 0.3s;"></div>
                        </div>
                        <div class="progress-logs" style="margin-top:6px;font-size:11px;color:#94a3b8;max-height:100px;overflow-y:auto;background:#0a0f1a;padding:6px;border-radius:4px;"></div>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Topic Coverage dettagliato -->
    <div class="card">
        <h3>📊 Copertura Topic</h3>
        <div style="display:flex;flex-direction:column;gap:16px;">
        <?php foreach ($hubReport['topic_coverage'] as $topicKey => $topic):
            $color = $topic['coverage_percent'] >= 80 ? '#4ade80' : ($topic['coverage_percent'] >= 50 ? '#fbbf24' : '#f87171');
            $missingClusters = max(0, $topic['expected_clusters'] - $topic['clusters_count']);
        ?>
        <div style="padding:16px;background:#0f172a;border-radius:8px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                <div style="display:flex;align-items:center;gap:10px;">
                    <h4 style="margin:0;color:#f1f5f9;"><?= htmlspecialchars($topic['name']) ?></h4>
                    <?php if ($topic['pillar_present']): ?>
                        <span style="font-size:11px;background:#064e3b;color:#4ade80;padding:2px 7px;border-radius:4px;">✓ Pillar</span>
                    <?php else: ?>
                        <span style="font-size:11px;background:#450a0a;color:#f87171;padding:2px 7px;border-radius:4px;">✗ Pillar mancante</span>
                    <?php endif; ?>
                </div>
                <span style="color:<?= $color ?>;font-weight:700;font-size:16px;"><?= $topic['coverage_percent'] ?>%</span>
            </div>
            <div style="width:100%;height:6px;background:#1e293b;border-radius:3px;overflow:hidden;margin-bottom:10px;">
                <div style="width:<?= $topic['coverage_percent'] ?>%;height:100%;background:<?= $color ?>;transition:width 0.5s;border-radius:3px;"></div>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                <span style="font-size:12px;color:#64748b;">
                    <?= $topic['clusters_count'] ?>/<?= $topic['expected_clusters'] ?> cluster
                    <?php if ($missingClusters > 0): ?>
                        · <span style="color:#fbbf24;"><?= $missingClusters ?> mancanti</span>
                    <?php endif; ?>
                </span>
                <?php if (!$topic['pillar_present'] && !empty($topic['pillar_keywords'][0])): ?>
                <div class="generate-container" id="generate-container-pillar-<?= htmlspecialchars($topicKey) ?>">
                    <button type="button" class="btn btn-sm generate-btn"
                            style="background:#dc2626;color:white;font-size:11px;padding:3px 10px;"
                            data-keyword="<?= htmlspecialchars($topic['pillar_keywords'][0] ?? $topic['name']) ?>"
                            data-topic="<?= htmlspecialchars($topicKey) ?>"
                            data-index="pillar-<?= htmlspecialchars($topicKey) ?>">
                        ✨ Crea Pillar
                    </button>
                    <div class="generate-progress" style="display:none;margin-top:6px;">
                        <div class="progress-bar" style="width:100%;height:3px;background:#334155;border-radius:2px;overflow:hidden;">
                            <div class="progress-fill" style="width:0%;height:100%;background:#22c55e;transition:width 0.3s;"></div>
                        </div>
                        <div class="progress-logs" style="margin-top:5px;font-size:11px;color:#94a3b8;max-height:80px;overflow-y:auto;background:#0a0f1a;padding:5px;border-radius:4px;"></div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    </div>

    <!-- Raccomandazioni complete -->
    <?php if (!empty($hubReport['recommendations'])): ?>
    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;">
            <h3 style="margin:0;">💡 Raccomandazioni Strategiche</h3>
            <span style="font-size:12px;color:#64748b;"><?= $totalRecs ?> totali<?= $criticalRecs > 0 ? ' · <span style="color:#f87171;">'.$criticalRecs.' critiche</span>' : '' ?></span>
        </div>
        <!-- Filtro priorità -->
        <div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap;">
            <button type="button" onclick="filterRecs('all')" id="recfilter-all" class="btn btn-sm" style="background:#6366f1;color:white;">Tutte</button>
            <button type="button" onclick="filterRecs('CRITICAL')" id="recfilter-CRITICAL" class="btn btn-sm" style="background:#1e293b;color:#94a3b8;border:1px solid #334155;">Critiche (<?= $criticalRecs ?>)</button>
            <button type="button" onclick="filterRecs('HIGH')" id="recfilter-HIGH" class="btn btn-sm" style="background:#1e293b;color:#94a3b8;border:1px solid #334155;">Alte (<?= count(array_filter($hubReport['recommendations'], fn($r) => $r['priority'] === 'HIGH')) ?>)</button>
            <button type="button" onclick="filterRecs('MEDIUM')" id="recfilter-MEDIUM" class="btn btn-sm" style="background:#1e293b;color:#94a3b8;border:1px solid #334155;">Medie (<?= count(array_filter($hubReport['recommendations'], fn($r) => $r['priority'] === 'MEDIUM')) ?>)</button>
        </div>
        <div style="display:flex;flex-direction:column;gap:8px;" id="recs-list">
            <?php $recIndex = 0; foreach ($hubReport['recommendations'] as $rec):
                $recBorder = $rec['priority'] === 'CRITICAL' ? '#dc2626' : ($rec['priority'] === 'HIGH' ? '#f59e0b' : '#60a5fa');
            ?>
            <div class="hub-rec-item" data-priority="<?= $rec['priority'] ?>"
                 style="padding:12px;background:#0f172a;border-radius:6px;border-left:3px solid <?= $recBorder ?>;">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap;">
                    <div style="flex:1;min-width:0;">
                        <div style="display:flex;align-items:center;gap:6px;margin-bottom:4px;">
                            <span style="font-size:10px;color:<?= $recBorder ?>;text-transform:uppercase;font-weight:700;"><?= $rec['priority'] ?></span>
                        </div>
                        <p style="margin:0 0 4px 0;color:#e2e8f0;font-size:13px;"><?= htmlspecialchars($rec['action']) ?></p>
                        <?php if (!empty($rec['keyword'])): ?>
                            <code style="font-size:11px;color:#818cf8;"><?= htmlspecialchars($rec['keyword']) ?></code>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($rec['keyword'])): ?>
                    <div class="generate-container" id="generate-container-rec-<?= $recIndex ?>" style="flex-shrink:0;">
                        <button type="button" class="btn btn-sm btn-success generate-btn"
                                data-keyword="<?= htmlspecialchars($rec['keyword']) ?>"
                                data-topic="<?= htmlspecialchars($rec['topic'] ?? 'general') ?>"
                                data-index="rec-<?= $recIndex ?>"
                                style="padding:3px 10px;font-size:11px;">
                            ✨ Crea
                        </button>
                        <div class="generate-progress" style="display:none;margin-top:8px;min-width:180px;">
                            <div class="progress-bar" style="width:100%;height:3px;background:#334155;border-radius:2px;overflow:hidden;">
                                <div class="progress-fill" style="width:0%;height:100%;background:#22c55e;transition:width 0.3s;"></div>
                            </div>
                            <div class="progress-logs" style="margin-top:5px;font-size:11px;color:#94a3b8;max-height:80px;overflow-y:auto;background:#0a0f1a;padding:5px;border-radius:4px;"></div>
                        </div>
                    </div>
                    <?php $recIndex++; endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

