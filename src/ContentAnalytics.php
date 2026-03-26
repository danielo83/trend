<?php

/**
 * ContentAnalytics - Monitoraggio e analisi delle performance dei contenuti
 * 
 * Traccia metriche importanti per SEO e GEO:
 * - Indicizzazione
 * - Ranking per keyword
 * - Traffico organico (stimato)
 * - Engagement
 * - Featured snippets conquistati
 */
class ContentAnalytics
{
    private string $dbPath;
    private array $config;
    
    public function __construct(array $config)
    {
        $this->config = array_merge([
            'base_dir' => __DIR__ . '/..',
            'enable_tracking' => true,
        ], $config);
        
        $this->dbPath = $this->config['base_dir'] . '/data/content_analytics.json';
        $this->ensureDatabase();
    }
    
    /**
     * Inizializza il database se non esiste
     */
    private function ensureDatabase(): void
    {
        if (!file_exists($this->dbPath)) {
            $dir = dirname($this->dbPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($this->dbPath, json_encode([
                'articles' => [],
                'metrics_history' => [],
                'last_update' => date('Y-m-d H:i:s'),
            ], JSON_PRETTY_PRINT));
        }
    }
    
    /**
     * Carica il database
     */
    private function loadDb(): array
    {
        if (!file_exists($this->dbPath)) {
            return ['articles' => [], 'metrics_history' => [], 'last_update' => null];
        }
        $content = file_get_contents($this->dbPath);
        return json_decode($content, true) ?: ['articles' => [], 'metrics_history' => []];
    }
    
    /**
     * Salva il database
     */
    private function saveDb(array $data): void
    {
        $data['last_update'] = date('Y-m-d H:i:s');
        file_put_contents($this->dbPath, json_encode($data, JSON_PRETTY_PRINT));
    }
    
    /**
     * Registra un nuovo articolo per il tracking
     */
    public function trackArticle(array $articleData): void
    {
        $db = $this->loadDb();
        
        $articleId = $articleData['id'] ?? md5($articleData['url'] ?? $articleData['title']);
        
        $db['articles'][$articleId] = array_merge([
            'id' => $articleId,
            'title' => '',
            'url' => '',
            'keyword' => '',
            'published_at' => date('Y-m-d H:i:s'),
            'wordpress_post_id' => null,
            'metrics' => $this->getInitialMetrics(),
        ], $articleData);
        
        $this->saveDb($db);
    }
    
    /**
     * Metriche iniziali
     */
    private function getInitialMetrics(): array
    {
        return [
            'indexed' => false,
            'index_checked_at' => null,
            'rankings' => [],
            'estimated_traffic' => 0,
            'featured_snippet' => false,
            'avg_position' => null,
            'clicks' => 0,
            'impressions' => 0,
            'ctr' => 0,
            'last_updated' => date('Y-m-d H:i:s'),
        ];
    }
    
    /**
     * Aggiorna le metriche di un articolo
     */
    public function updateMetrics(string $articleId, array $metrics): void
    {
        $db = $this->loadDb();
        
        if (!isset($db['articles'][$articleId])) {
            return;
        }
        
        $db['articles'][$articleId]['metrics'] = array_merge(
            $db['articles'][$articleId]['metrics'],
            $metrics,
            ['last_updated' => date('Y-m-d H:i:s')]
        );
        
        $this->saveDb($db);
    }
    
    /**
     * Verifica se un URL è indicizzato da Google (simulato)
     * In produzione, usare Google Search Console API o servizi come SerpAPI
     */
    public function checkIndexing(string $url): bool
    {
        // Simulazione: in produzione, fai una query a Google
        // "site:example.com/url" e verifica se appare
        
        // Per ora, assumiamo che dopo 7 giorni sia indicizzato
        $db = $this->loadDb();
        $articleId = md5($url);
        
        if (isset($db['articles'][$articleId])) {
            $published = strtotime($db['articles'][$articleId]['published_at']);
            $daysSince = (time() - $published) / 86400;
            
            if ($daysSince > 7) {
                $this->updateMetrics($articleId, [
                    'indexed' => true,
                    'index_checked_at' => date('Y-m-d H:i:s'),
                ]);
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Stima il traffico organico basato su posizione e volume di ricerca
     */
    public function estimateTraffic(string $keyword, ?int $position = null): int
    {
        // Volume di ricerca stimato per keyword (in produzione, usare dati reali)
        $searchVolume = $this->estimateSearchVolume($keyword);
        
        // CTR medio per posizione
        $ctrByPosition = [
            1 => 0.28, 2 => 0.15, 3 => 0.09, 4 => 0.06, 5 => 0.04,
            6 => 0.03, 7 => 0.02, 8 => 0.02, 9 => 0.02, 10 => 0.015,
        ];
        
        if ($position === null || $position > 10) {
            return 0;
        }
        
        $ctr = $ctrByPosition[$position] ?? 0.01;
        return round($searchVolume * $ctr);
    }
    
    /**
     * Stima il volume di ricerca mensile (simulato)
     */
    private function estimateSearchVolume(string $keyword): int
    {
        // In produzione, integrare con API come DataForSEO, Ahrefs, SEMrush
        // Per ora, stima basata sulla lunghezza e tipo di keyword
        
        $baseVolume = 1000;
        
        // Keyword long-tail hanno volume minore
        $words = str_word_count($keyword);
        if ($words > 4) {
            $baseVolume = 100;
        } elseif ($words > 2) {
            $baseVolume = 500;
        }
        
        // Keyword specifiche sui sogni
        if (stripos($keyword, 'sognare') !== false) {
            $baseVolume *= 2;
        }
        
        return $baseVolume;
    }
    
    /**
     * Genera report delle performance
     */
    public function generateReport(?string $startDate = null, ?string $endDate = null): array
    {
        $db = $this->loadDb();
        $articles = $db['articles'] ?? [];
        
        $report = [
            'period' => [
                'start' => $startDate ?? 'all time',
                'end' => $endDate ?? date('Y-m-d'),
            ],
            'summary' => [
                'total_articles' => count($articles),
                'indexed' => 0,
                'with_featured_snippet' => 0,
                'avg_position' => null,
                'total_estimated_traffic' => 0,
            ],
            'top_performers' => [],
            'underperforming' => [],
            'recommendations' => [],
        ];
        
        $positions = [];
        
        foreach ($articles as $article) {
            $metrics = $article['metrics'] ?? [];
            
            if ($metrics['indexed'] ?? false) {
                $report['summary']['indexed']++;
            }
            
            if ($metrics['featured_snippet'] ?? false) {
                $report['summary']['with_featured_snippet']++;
            }
            
            if ($metrics['avg_position'] ?? null) {
                $positions[] = $metrics['avg_position'];
            }
            
            $report['summary']['total_estimated_traffic'] += $metrics['estimated_traffic'] ?? 0;
            
            // Top performers (posizione <= 3)
            if (($metrics['avg_position'] ?? 999) <= 3) {
                $report['top_performers'][] = [
                    'title' => $article['title'],
                    'url' => $article['url'],
                    'position' => $metrics['avg_position'],
                    'traffic' => $metrics['estimated_traffic'],
                ];
            }
            
            // Underperforming (posizione > 20 o non indicizzato dopo 30 giorni)
            $daysSince = (time() - strtotime($article['published_at'])) / 86400;
            if (($metrics['avg_position'] ?? 999) > 20 || (!$metrics['indexed'] && $daysSince > 30)) {
                $report['underperforming'][] = [
                    'title' => $article['title'],
                    'url' => $article['url'],
                    'issue' => !$metrics['indexed'] ? 'not_indexed' : 'low_ranking',
                    'recommendation' => $this->getRecommendation($article, $metrics),
                ];
            }
        }
        
        if (!empty($positions)) {
            $report['summary']['avg_position'] = round(array_sum($positions) / count($positions), 1);
        }
        
        // Ordina top performers per traffico
        usort($report['top_performers'], fn($a, $b) => $b['traffic'] <=> $a['traffic']);
        $report['top_performers'] = array_slice($report['top_performers'], 0, 10);
        
        // Genera raccomandazioni generali
        $report['recommendations'] = $this->generateRecommendations($report);
        
        return $report;
    }
    
    /**
     * Genera raccomandazione per un articolo underperforming
     */
    private function getRecommendation(array $article, array $metrics): string
    {
        if (!($metrics['indexed'] ?? false)) {
            return 'Verifica che il post sia indicizzato. Invia la Sitemap a Google Search Console.';
        }
        
        if (($metrics['avg_position'] ?? 999) > 20) {
            return 'Considera di migliorare il contenuto, aggiungere link interni e ottimizzare per keyword correlate.';
        }
        
        return 'Monitora le performance e considera aggiornamenti periodici.';
    }
    
    /**
     * Genera raccomandazioni generali
     */
    private function generateRecommendations(array $report): array
    {
        $recommendations = [];
        
        $indexedRatio = $report['summary']['total_articles'] > 0 
            ? $report['summary']['indexed'] / $report['summary']['total_articles'] 
            : 0;
        
        if ($indexedRatio < 0.8) {
            $recommendations[] = 'Meno dell\'80% degli articoli è indicizzato. Verifica la Sitemap e la configurazione di Google Search Console.';
        }
        
        if ($report['summary']['avg_position'] > 10) {
            $recommendations[] = 'La posizione media è superiore a 10. Considera di ottimizzare i contenuti esistenti per migliorare il ranking.';
        }
        
        if (count($report['underperforming']) > 5) {
            $recommendations[] = 'Ci sono ' . count($report['underperforming']) . ' articoli con performance scarse. Valuta un audit SEO.';
        }
        
        if ($report['summary']['with_featured_snippet'] < 3 && $report['summary']['indexed'] > 10) {
            $recommendations[] = 'Pochi featured snippets. Ottimizza i contenuti con liste, tabelle e risposte dirette.';
        }
        
        return $recommendations;
    }
    
    /**
     * Ottieni statistiche per keyword
     */
    public function getKeywordStats(string $keyword): array
    {
        $db = $this->loadDb();
        
        $stats = [
            'keyword' => $keyword,
            'articles_count' => 0,
            'avg_position' => null,
            'best_position' => null,
            'total_traffic' => 0,
        ];
        
        $positions = [];
        
        foreach ($db['articles'] as $article) {
            if (strcasecmp($article['keyword'], $keyword) === 0) {
                $stats['articles_count']++;
                $metrics = $article['metrics'] ?? [];
                
                if ($metrics['avg_position'] ?? null) {
                    $positions[] = $metrics['avg_position'];
                }
                
                $stats['total_traffic'] += $metrics['estimated_traffic'] ?? 0;
            }
        }
        
        if (!empty($positions)) {
            $stats['avg_position'] = round(array_sum($positions) / count($positions), 1);
            $stats['best_position'] = min($positions);
        }
        
        return $stats;
    }
    
    /**
     * Esporta dati per Google Search Console (formato CSV)
     */
    public function exportForGSC(): string
    {
        $db = $this->loadDb();
        $csv = "URL,Keyword,Published,Indexed,Position,Clicks\n";
        
        foreach ($db['articles'] as $article) {
            $m = $article['metrics'] ?? [];
            $csv .= sprintf(
                "%s,%s,%s,%s,%s,%s\n",
                $article['url'],
                $article['keyword'],
                $article['published_at'],
                $m['indexed'] ? 'yes' : 'no',
                $m['avg_position'] ?? 'N/A',
                $m['clicks'] ?? 0
            );
        }
        
        return $csv;
    }
    
    /**
     * Pulizia dati vecchi
     */
    public function cleanup(int $daysToKeep = 365): int
    {
        $db = $this->loadDb();
        $cutoff = strtotime("-{$daysToKeep} days");
        $removed = 0;
        
        foreach ($db['articles'] as $id => $article) {
            $articleTime = strtotime($article['published_at']);
            if ($articleTime < $cutoff) {
                unset($db['articles'][$id]);
                $removed++;
            }
        }
        
        $this->saveDb($db);
        return $removed;
    }
}
