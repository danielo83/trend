<?php

/**
 * ContentHubManager - Gestione Content Hub e Topic Clusters
 * 
 * Crea una struttura di contenuti interconnessi per dominare
 * i topic principali e ottenere massima autorità sui motori di ricerca.
 */
class ContentHubManager
{
    private array $config;
    private string $hubPath;
    private ?ContentAnalytics $analytics = null;
    private ?SmartLinkBuilder $linkBuilder = null;
    
    // Topic principali per la nicchia sogni/sonno
    private const CORE_TOPICS = [
        'sogni' => [
            'name' => 'Interpretazione dei Sogni',
            'pillar_keywords' => [
                'significato dei sogni',
                'interpretazione dei sogni',
                'sogni ricorrenti',
                'sogni comuni',
            ],
            'cluster_keywords' => [
                'sognare animali',
                'sognare persone',
                'sognare oggetti',
                'sognare situazioni',
                'sognare emozioni',
                'sognare luoghi',
                'sognare colori',
                'sognare numeri',
            ],
        ],
        'psicologia' => [
            'name' => 'Psicologia dei Sogni',
            'pillar_keywords' => [
                'psicologia dei sogni',
                'inconscio e sogni',
                'significato psicologico dei sogni',
            ],
            'cluster_keywords' => [
                'sogni e ansia',
                'sogni e stress',
                'sogni e emozioni',
                'sogni e memoria',
                'sogni e creatività',
                'sogni e problem solving',
                'sogni lucidi',
                'incubi e terrori notturni',
            ],
        ],
        'sonno' => [
            'name' => 'Sonno e Benessere',
            'pillar_keywords' => [
                'come dormire meglio',
                'qualità del sonno',
                'igiene del sonno',
            ],
            'cluster_keywords' => [
                'insonnia rimedi',
                'posizioni per dormire',
                'routine sonno',
                'alimentazione e sonno',
                'sport e sonno',
                'meditazione sonno',
                'disturbi del sonno',
                'apnee notturne',
            ],
        ],
        'smorfia' => [
            'name' => 'Smorfia Napoletana',
            'pillar_keywords' => [
                'smorfia napoletana',
                'significato numeri smorfia',
                'tradizione smorfia',
            ],
            'cluster_keywords' => [
                'numeri sogni',
                'cabala napoletana',
                'simboli smorfia',
                'storia smorfia',
                'interpretazione numeri',
            ],
        ],
    ];
    
    public function __construct(array $config)
    {
        $this->config = array_merge([
            'base_dir' => __DIR__ . '/..',
            'min_cluster_articles' => 5,
            'max_cluster_articles' => 15,
        ], $config);
        
        $this->hubPath = $this->config['base_dir'] . '/data/content_hub.json';
        $this->ensureHubFile();
    }
    
    public function setAnalytics(ContentAnalytics $analytics): void
    {
        $this->analytics = $analytics;
    }
    
    public function setLinkBuilder(SmartLinkBuilder $linkBuilder): void
    {
        $this->linkBuilder = $linkBuilder;
    }
    
    /**
     * Inizializza il file hub se non esiste
     */
    private function ensureHubFile(): void
    {
        if (!file_exists($this->hubPath)) {
            $dir = dirname($this->hubPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            
            file_put_contents($this->hubPath, json_encode([
                'pillars' => [],
                'clusters' => [],
                'orphans' => [],
                'last_update' => date('Y-m-d H:i:s'),
            ], JSON_PRETTY_PRINT));
        }
    }
    
    /**
     * Carica il content hub
     */
    private function loadHub(): array
    {
        $content = file_get_contents($this->hubPath);
        return json_decode($content, true) ?: [
            'pillars' => [],
            'clusters' => [],
            'orphans' => [],
        ];
    }
    
    /**
     * Salva il content hub
     */
    private function saveHub(array $hub): void
    {
        $hub['last_update'] = date('Y-m-d H:i:s');
        file_put_contents($this->hubPath, json_encode($hub, JSON_PRETTY_PRINT));
    }
    
    /**
     * Ottieni la struttura dei topic core
     */
    public function getCoreTopics(): array
    {
        return self::CORE_TOPICS;
    }
    
    /**
     * Suggerisci prossimo articolo da creare per massimizzare SEO
     */
    public function suggestNextArticle(): array
    {
        $hub = $this->loadHub();
        $suggestions = [];
        
        foreach (self::CORE_TOPICS as $topicKey => $topicData) {
            // Verifica se esiste il pillar
            $pillarExists = false;
            foreach ($hub['pillars'] as $pillar) {
                if ($pillar['topic'] === $topicKey) {
                    $pillarExists = true;
                    break;
                }
            }
            
            if (!$pillarExists) {
                // Priorità massima: creare pillar content
                $suggestions[] = [
                    'priority' => 'CRITICAL',
                    'type' => 'pillar',
                    'topic' => $topicKey,
                    'keyword' => $topicData['pillar_keywords'][0],
                    'reason' => 'Pillar content mancante per topic ' . $topicData['name'],
                    'expected_impact' => 'Alto - Fondamentale per autorità topic',
                ];
            } else {
                // Verifica cluster
                $existingClusterCount = 0;
                foreach ($hub['clusters'] as $cluster) {
                    if ($cluster['parent_topic'] === $topicKey) {
                        $existingClusterCount++;
                    }
                }
                
                $missingClusters = array_slice(
                    $topicData['cluster_keywords'],
                    $existingClusterCount
                );
                
                foreach (array_slice($missingClusters, 0, 3) as $clusterKeyword) {
                    $suggestions[] = [
                        'priority' => 'HIGH',
                        'type' => 'cluster',
                        'topic' => $topicKey,
                        'keyword' => $clusterKeyword,
                        'reason' => 'Completa cluster per ' . $topicData['name'],
                        'expected_impact' => 'Medio-Alto - Rafforza topic authority',
                    ];
                }
            }
        }
        
        // Ordina per priorità
        usort($suggestions, function($a, $b) {
            $priorityOrder = ['CRITICAL' => 0, 'HIGH' => 1, 'MEDIUM' => 2];
            return $priorityOrder[$a['priority']] <=> $priorityOrder[$b['priority']];
        });
        
        return $suggestions;
    }
    
    /**
     * Registra un nuovo articolo nel content hub
     */
    public function registerArticle(array $articleData): void
    {
        $hub = $this->loadHub();
        
        $article = [
            'id' => $articleData['id'] ?? md5($articleData['url']),
            'title' => $articleData['title'],
            'url' => $articleData['url'],
            'keyword' => $articleData['keyword'],
            'topic' => $articleData['topic'] ?? $this->detectTopic($articleData['keyword']),
            'type' => $articleData['type'] ?? 'cluster', // pillar o cluster
            'published_at' => $articleData['published_at'] ?? date('Y-m-d H:i:s'),
            'word_count' => $articleData['word_count'] ?? 0,
            'internal_links' => $articleData['internal_links'] ?? [],
        ];
        
        if ($article['type'] === 'pillar') {
            $hub['pillars'][] = $article;
        } else {
            $hub['clusters'][] = $article;
        }
        
        $this->saveHub($hub);
    }
    
    /**
     * Rileva il topic da una keyword
     */
    private function detectTopic(string $keyword): string
    {
        $keywordLower = mb_strtolower($keyword);
        
        foreach (self::CORE_TOPICS as $topicKey => $topicData) {
            // Controlla pillar keywords
            foreach ($topicData['pillar_keywords'] as $pk) {
                if (strpos($keywordLower, mb_strtolower($pk)) !== false) {
                    return $topicKey;
                }
            }
            
            // Controlla cluster keywords
            foreach ($topicData['cluster_keywords'] as $ck) {
                if (strpos($keywordLower, mb_strtolower($ck)) !== false) {
                    return $topicKey;
                }
            }
        }
        
        return 'general';
    }
    
    /**
     * Genera mappa dei link interni ottimale
     */
    public function generateInternalLinkMap(string $newArticleKeyword, string $newArticleTopic): array
    {
        $hub = $this->loadHub();
        $linkMap = [
            'must_link_to' => [],
            'should_link_to' => [],
            'can_link_to' => [],
        ];
        
        // Deve linkare al pillar del suo topic
        foreach ($hub['pillars'] as $pillar) {
            if ($pillar['topic'] === $newArticleTopic) {
                $linkMap['must_link_to'][] = [
                    'article' => $pillar,
                    'reason' => 'Pillar content dello stesso topic',
                    'anchor_suggestion' => $this->generateAnchorText($pillar['title'], $newArticleKeyword),
                ];
            }
        }
        
        // Dovrebbe linkare ad altri cluster dello stesso topic
        $sameTopicClusters = array_filter($hub['clusters'], function($c) use ($newArticleTopic) {
            return $c['topic'] === $newArticleTopic;
        });
        
        foreach (array_slice($sameTopicClusters, 0, 3) as $cluster) {
            $linkMap['should_link_to'][] = [
                'article' => $cluster,
                'reason' => 'Articolo correlato nello stesso cluster',
                'anchor_suggestion' => $this->generateAnchorText($cluster['title'], $newArticleKeyword),
            ];
        }
        
        // Può linkare ad altri pillar (cross-linking)
        foreach ($hub['pillars'] as $pillar) {
            if ($pillar['topic'] !== $newArticleTopic) {
                // Verifica rilevanza semantica
                $relevance = $this->calculateSemanticRelevance(
                    $newArticleKeyword,
                    $pillar['keyword']
                );
                
                if ($relevance > 0.3) {
                    $linkMap['can_link_to'][] = [
                        'article' => $pillar,
                        'reason' => 'Topic semanticamente correlato',
                        'relevance' => $relevance,
                        'anchor_suggestion' => $this->generateAnchorText($pillar['title'], $newArticleKeyword),
                    ];
                }
            }
        }
        
        // Ordina can_link_to per rilevanza
        usort($linkMap['can_link_to'], fn($a, $b) => $b['relevance'] <=> $a['relevance']);
        
        return $linkMap;
    }
    
    /**
     * Genera anchor text ottimizzato
     */
    private function generateAnchorText(string $targetTitle, string $sourceKeyword): string
    {
        // Estrai parole chiave dal titolo target
        $titleWords = explode(' ', mb_strtolower($targetTitle));
        $sourceWords = explode(' ', mb_strtolower($sourceKeyword));
        
        // Trova overlap
        $overlap = array_intersect($titleWords, $sourceWords);
        
        if (count($overlap) >= 2) {
            // Usa le parole in comune + contesto
            return implode(' ', array_slice($titleWords, 0, 4));
        }
        
        // Altrimenti usa prime 4-5 parole del titolo
        return implode(' ', array_slice($titleWords, 0, 5));
    }
    
    /**
     * Calcola rilevanza semantica semplificata
     */
    private function calculateSemanticRelevance(string $keyword1, string $keyword2): float
    {
        $words1 = array_unique(explode(' ', mb_strtolower($keyword1)));
        $words2 = array_unique(explode(' ', mb_strtolower($keyword2)));
        
        $stopWords = ['il', 'lo', 'la', 'i', 'gli', 'le', 'di', 'a', 'da', 'in', 'con', 'su', 'per', 'e'];
        
        $words1 = array_diff($words1, $stopWords);
        $words2 = array_diff($words2, $stopWords);
        
        if (empty($words1) || empty($words2)) {
            return 0;
        }
        
        $intersection = count(array_intersect($words1, $words2));
        $union = count(array_unique(array_merge($words1, $words2)));
        
        return $union > 0 ? $intersection / $union : 0;
    }
    
    /**
     * Genera report del content hub
     */
    public function generateHubReport(): array
    {
        $hub = $this->loadHub();
        $report = [
            'overview' => [
                'total_pillars' => count($hub['pillars']),
                'total_clusters' => count($hub['clusters']),
                'total_orphans' => count($hub['orphans']),
                'coverage_score' => 0,
            ],
            'topic_coverage' => [],
            'gaps' => [],
            'recommendations' => [],
        ];
        
        // Calcola coverage per ogni topic
        foreach (self::CORE_TOPICS as $topicKey => $topicData) {
            $pillarCount = count(array_filter($hub['pillars'], fn($p) => $p['topic'] === $topicKey));
            $clusterCount = count(array_filter($hub['clusters'], fn($c) => $c['topic'] === $topicKey));
            
            $expectedClusters = count($topicData['cluster_keywords']);
            $coverage = $expectedClusters > 0 ? $clusterCount / $expectedClusters : 0;
            
            $report['topic_coverage'][$topicKey] = [
                'name' => $topicData['name'],
                'pillar_present' => $pillarCount > 0,
                'clusters_count' => $clusterCount,
                'expected_clusters' => $expectedClusters,
                'coverage_percent' => round($coverage * 100, 1),
            ];
            
            if (!$pillarCount) {
                $report['gaps'][] = [
                    'type' => 'missing_pillar',
                    'topic' => $topicKey,
                    'priority' => 'CRITICAL',
                ];
            }
            
            if ($coverage < 0.5) {
                $report['gaps'][] = [
                    'type' => 'incomplete_cluster',
                    'topic' => $topicKey,
                    'missing' => $expectedClusters - $clusterCount,
                    'priority' => 'HIGH',
                ];
            }
        }
        
        // Calcola coverage score generale
        $totalTopics = count(self::CORE_TOPICS);
        $coveredTopics = count(array_filter($report['topic_coverage'], fn($t) => $t['pillar_present']));
        $report['overview']['coverage_score'] = round(($coveredTopics / $totalTopics) * 100, 1);
        
        // Genera raccomandazioni
        $report['recommendations'] = $this->generateHubRecommendations($report);
        
        return $report;
    }
    
    /**
     * Genera raccomandazioni per il content hub
     */
    private function generateHubRecommendations(array $report): array
    {
        $recommendations = [];
        
        if ($report['overview']['coverage_score'] < 50) {
            $recommendations[] = [
                'priority' => 'CRITICAL',
                'action' => 'Crea i pillar content mancanti',
                'impact' => 'Fondamentale per stabilire autorità topic',
            ];
        }
        
        foreach ($report['topic_coverage'] as $topic => $data) {
            if (!$data['pillar_present']) {
                $recommendations[] = [
                    'priority' => 'HIGH',
                    'action' => "Crea pillar content per {$data['name']}",
                    'keyword' => self::CORE_TOPICS[$topic]['pillar_keywords'][0],
                ];
            }
            
            if ($data['coverage_percent'] < 50) {
                $recommendations[] = [
                    'priority' => 'MEDIUM',
                    'action' => "Completa cluster {$data['name']}",
                    'missing' => $data['expected_clusters'] - $data['clusters_count'],
                ];
            }
        }
        
        return $recommendations;
    }
    
    /**
     * Ottieni contenuto pillar per un topic
     */
    public function getPillarContent(string $topic): ?array
    {
        $hub = $this->loadHub();
        
        foreach ($hub['pillars'] as $pillar) {
            if ($pillar['topic'] === $topic) {
                return $pillar;
            }
        }
        
        return null;
    }
    
    /**
     * Ottieni tutti i cluster per un topic
     */
    public function getClusterContent(string $topic): array
    {
        $hub = $this->loadHub();
        
        return array_filter($hub['clusters'], function($c) use ($topic) {
            return $c['topic'] === $topic;
        });
    }
    
    /**
     * Genera menu di navigazione topic
     */
    public function generateTopicMenu(): array
    {
        $menu = [];
        
        foreach (self::CORE_TOPICS as $topicKey => $topicData) {
            $pillar = $this->getPillarContent($topicKey);
            $clusters = $this->getClusterContent($topicKey);
            
            $menu[$topicKey] = [
                'name' => $topicData['name'],
                'pillar' => $pillar,
                'clusters' => array_slice($clusters, 0, 5),
                'total_clusters' => count($clusters),
            ];
        }
        
        return $menu;
    }
}
