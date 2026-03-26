<?php

/**
 * SEOMonitor - Monitoraggio avanzato performance SEO
 * 
 * Traccia in tempo reale:
 * - Ranking per keyword
 * - Featured snippets
 * - Click-through rate
 * - Indicizzazione
 * - Core Web Vitals (se disponibili)
 */
class SEOMonitor
{
    private string $dbPath;
    private array $config;
    
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'base_dir' => __DIR__ . '/..',
            'check_interval_hours' => 24,
            'alert_threshold_position' => 10,
            'alert_threshold_traffic_drop' => 30,
        ], $config);
        
        $this->dbPath = $this->config['base_dir'] . '/data/seo_monitor.json';
        $this->ensureDatabase();
    }
    
    private function ensureDatabase(): void
    {
        if (!file_exists($this->dbPath)) {
            $dir = dirname($this->dbPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($this->dbPath, json_encode([
                'articles' => [],
                'keywords' => [],
                'alerts' => [],
                'checks' => [],
            ], JSON_PRETTY_PRINT));
        }
    }
    
    private function loadDb(): array
    {
        if (!file_exists($this->dbPath)) {
            return ['articles' => [], 'keywords' => [], 'alerts' => [], 'checks' => []];
        }
        return json_decode(file_get_contents($this->dbPath), true) ?: [];
    }
    
    private function saveDb(array $data): void
    {
        file_put_contents($this->dbPath, json_encode($data, JSON_PRETTY_PRINT));
    }
    
    /**
     * Registra un articolo per monitoraggio
     */
    public function trackArticle(array $article): void
    {
        $db = $this->loadDb();
        
        $articleId = $article['id'] ?? md5($article['url']);
        
        $db['articles'][$articleId] = array_merge([
            'id' => $articleId,
            'url' => '',
            'title' => '',
            'keyword' => '',
            'target_positions' => [],
            'added_at' => date('Y-m-d H:i:s'),
            'last_check' => null,
        ], $article);
        
        // Registra anche la keyword
        if (!empty($article['keyword'])) {
            $this->trackKeyword($article['keyword'], $articleId);
        }
        
        $this->saveDb($db);
    }
    
    /**
     * Registra una keyword per monitoraggio
     */
    public function trackKeyword(string $keyword, string $articleId): void
    {
        $db = $this->loadDb();
        
        $keywordHash = md5(mb_strtolower($keyword));
        
        if (!isset($db['keywords'][$keywordHash])) {
            $db['keywords'][$keywordHash] = [
                'keyword' => $keyword,
                'articles' => [],
                'search_volume' => null,
                'difficulty' => null,
                'added_at' => date('Y-m-d H:i:s'),
            ];
        }
        
        if (!in_array($articleId, $db['keywords'][$keywordHash]['articles'])) {
            $db['keywords'][$keywordHash]['articles'][] = $articleId;
        }
        
        $this->saveDb($db);
    }
    
    /**
     * Aggiorna i dati di ranking
     */
    public function updateRanking(string $articleId, array $rankingData): void
    {
        $db = $this->loadDb();
        
        if (!isset($db['articles'][$articleId])) {
            return;
        }
        
        $check = [
            'timestamp' => date('Y-m-d H:i:s'),
            'position' => $rankingData['position'] ?? null,
            'featured_snippet' => $rankingData['featured_snippet'] ?? false,
            'people_also_ask' => $rankingData['people_also_ask'] ?? false,
            'search_volume' => $rankingData['search_volume'] ?? null,
            'estimated_traffic' => $rankingData['estimated_traffic'] ?? 0,
        ];
        
        $db['articles'][$articleId]['last_check'] = $check['timestamp'];
        
        if (!isset($db['articles'][$articleId]['history'])) {
            $db['articles'][$articleId]['history'] = [];
        }
        
        $db['articles'][$articleId]['history'][] = $check;
        
        // Mantieni solo ultimi 30 check
        $db['articles'][$articleId]['history'] = array_slice(
            $db['articles'][$articleId]['history'],
            -30
        );
        
        $this->saveDb($db);
        
        // Verifica alert
        $this->checkAlerts($articleId, $check);
    }
    
    /**
     * Verifica e genera alert
     */
    private function checkAlerts(string $articleId, array $check): void
    {
        $db = $this->loadDb();
        $article = $db['articles'][$articleId];
        
        $alerts = [];
        
        // Alert: posizione scesa sotto soglia
        if ($check['position'] > $this->config['alert_threshold_position']) {
            $alerts[] = [
                'type' => 'low_position',
                'severity' => 'warning',
                'message' => "Posizione {$check['position']} per '{$article['keyword']}'",
                'suggestion' => 'Considera ottimizzazione contenuto',
            ];
        }
        
        // Alert: featured snippet perso
        $history = $article['history'] ?? [];
        if (count($history) >= 2) {
            $prevCheck = $history[count($history) - 2];
            if ($prevCheck['featured_snippet'] && !$check['featured_snippet']) {
                $alerts[] = [
                    'type' => 'snippet_lost',
                    'severity' => 'critical',
                    'message' => "Featured snippet perso per '{$article['keyword']}'",
                    'suggestion' => 'Aggiorna contenuto per riconquistare posizione',
                ];
            }
        }
        
        // Alert: traffico in calo
        if (count($history) >= 2) {
            $prevCheck = $history[count($history) - 2];
            if ($prevCheck['estimated_traffic'] > 0) {
                $drop = (($prevCheck['estimated_traffic'] - $check['estimated_traffic']) / $prevCheck['estimated_traffic']) * 100;
                if ($drop > $this->config['alert_threshold_traffic_drop']) {
                    $alerts[] = [
                        'type' => 'traffic_drop',
                        'severity' => 'critical',
                        'message' => "Traffico calato del " . round($drop, 1) . "%",
                        'suggestion' => 'Verifica ranking e aggiorna contenuto',
                    ];
                }
            }
        }
        
        // Salva alert
        foreach ($alerts as $alert) {
            $alert['article_id'] = $articleId;
            $alert['timestamp'] = date('Y-m-d H:i:s');
            $db['alerts'][] = $alert;
        }
        
        // Mantieni solo ultimi 100 alert
        $db['alerts'] = array_slice($db['alerts'], -100);
        
        $this->saveDb($db);
    }
    
    /**
     * Ottieni report performance
     */
    public function getPerformanceReport(?string $startDate = null, ?string $endDate = null): array
    {
        $db = $this->loadDb();
        
        $report = [
            'period' => [
                'start' => $startDate ?? 'all time',
                'end' => $endDate ?? date('Y-m-d'),
            ],
            'summary' => [
                'total_tracked' => count($db['articles']),
                'total_keywords' => count($db['keywords']),
                'avg_position' => null,
                'featured_snippets' => 0,
                'position_1_3' => 0,
                'position_4_10' => 0,
                'position_11_plus' => 0,
            ],
            'trends' => [
                'position' => [],
                'traffic' => [],
            ],
            'top_performers' => [],
            'needs_attention' => [],
            'alerts' => array_slice($db['alerts'], -20),
        ];
        
        $positions = [];
        
        foreach ($db['articles'] as $article) {
            $lastCheck = $article['history'][count($article['history']) - 1] ?? null;
            
            if ($lastCheck) {
                $positions[] = $lastCheck['position'];
                
                if ($lastCheck['featured_snippet']) {
                    $report['summary']['featured_snippets']++;
                }
                
                if ($lastCheck['position'] <= 3) {
                    $report['summary']['position_1_3']++;
                } elseif ($lastCheck['position'] <= 10) {
                    $report['summary']['position_4_10']++;
                } else {
                    $report['summary']['position_11_plus']++;
                }
                
                // Top performers
                if ($lastCheck['position'] <= 3) {
                    $report['top_performers'][] = [
                        'title' => $article['title'],
                        'keyword' => $article['keyword'],
                        'position' => $lastCheck['position'],
                        'featured_snippet' => $lastCheck['featured_snippet'],
                    ];
                }
                
                // Needs attention
                if ($lastCheck['position'] > 10 || empty($lastCheck['position'])) {
                    $report['needs_attention'][] = [
                        'title' => $article['title'],
                        'keyword' => $article['keyword'],
                        'position' => $lastCheck['position'] ?? 'N/A',
                        'issue' => $lastCheck['position'] > 10 ? 'low_ranking' : 'not_indexed',
                    ];
                }
            }
        }
        
        if (!empty($positions)) {
            $report['summary']['avg_position'] = round(array_sum($positions) / count($positions), 1);
        }
        
        return $report;
    }
    
    /**
     * Ottieni trend per un articolo specifico
     */
    public function getArticleTrend(string $articleId): array
    {
        $db = $this->loadDb();
        
        if (!isset($db['articles'][$articleId])) {
            return ['error' => 'Articolo non trovato'];
        }
        
        $article = $db['articles'][$articleId];
        $history = $article['history'] ?? [];
        
        return [
            'article' => [
                'title' => $article['title'],
                'keyword' => $article['keyword'],
                'url' => $article['url'],
            ],
            'current_position' => $history[count($history) - 1]['position'] ?? null,
            'best_position' => !empty($history) ? min(array_column($history, 'position')) : null,
            'position_trend' => $this->calculateTrend(array_column($history, 'position')),
            'traffic_trend' => $this->calculateTrend(array_column($history, 'estimated_traffic')),
            'history' => $history,
        ];
    }
    
    /**
     * Calcola trend (up/down/stable)
     */
    private function calculateTrend(array $values): string
    {
        if (count($values) < 2) {
            return 'insufficient_data';
        }
        
        $first = $values[0];
        $last = $values[count($values) - 1];
        
        // Per posizioni: minore è meglio
        // Per traffico: maggiore è meglio
        $diff = $last - $first;
        
        if (abs($diff) < 2) {
            return 'stable';
        }
        
        return $diff < 0 ? 'improving' : 'declining';
    }
    
    /**
     * Simula check ranking (in produzione, usa API come SerpAPI)
     */
    public function simulateRankingCheck(string $keyword): array
    {
        // Simulazione per testing
        // In produzione, integrare con SerpAPI, DataForSEO, etc.
        
        return [
            'position' => rand(1, 15),
            'featured_snippet' => rand(1, 10) === 1,
            'people_also_ask' => rand(1, 3) === 1,
            'search_volume' => rand(100, 5000),
            'estimated_traffic' => rand(10, 500),
        ];
    }
    
    /**
     * Esegui check per tutti gli articoli
     */
    public function runFullCheck(): array
    {
        $db = $this->loadDb();
        $results = [
            'checked' => 0,
            'updated' => 0,
            'errors' => [],
        ];
        
        foreach ($db['articles'] as $articleId => $article) {
            if (empty($article['keyword'])) {
                continue;
            }
            
            try {
                $ranking = $this->simulateRankingCheck($article['keyword']);
                $this->updateRanking($articleId, $ranking);
                $results['updated']++;
            } catch (Exception $e) {
                $results['errors'][] = [
                    'article' => $article['title'],
                    'error' => $e->getMessage(),
                ];
            }
            
            $results['checked']++;
            
            // Rate limiting
            sleep(1);
        }
        
        return $results;
    }
    
    /**
     * Esporta dati per Google Search Console
     */
    public function exportForGSC(): string
    {
        $db = $this->loadDb();
        $csv = "URL,Keyword,Position,Featured Snippet,Clicks,Impressions,CTR\n";
        
        foreach ($db['articles'] as $article) {
            $lastCheck = $article['history'][count($article['history']) - 1] ?? null;
            
            $csv .= sprintf(
                "%s,%s,%s,%s,%s,%s,%s\n",
                $article['url'],
                $article['keyword'],
                $lastCheck['position'] ?? 'N/A',
                $lastCheck['featured_snippet'] ? 'Yes' : 'No',
                rand(10, 1000), // Simulato
                rand(100, 10000), // Simulato
                rand(1, 15) / 100 // Simulato
            );
        }
        
        return $csv;
    }
}
