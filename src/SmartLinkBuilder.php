<?php

/**
 * SmartLinkBuilder - Link building avanzato con ottimizzazione SEO
 * 
 * Estende le funzionalità di LinkBuilder con:
 * - Analisi semantica dei contenuti per link pertinenti
 * - Anchor text ottimizzato per SEO
 * - Link juice distribution
 * - Topic clusters
 */
class SmartLinkBuilder extends LinkBuilder
{
    private array $config;
    /** @var callable|null */
    private $logCallback = null;
    
    // Pesi per il calcolo della rilevanza semantica
    private const WEIGHT_TITLE = 0.4;
    private const WEIGHT_CONTENT = 0.3;
    private const WEIGHT_CATEGORY = 0.2;
    private const WEIGHT_DATE = 0.1;
    
    public function __construct(array $config)
    {
        parent::__construct($config);
        $this->config = array_merge([
            'min_relevance_score' => 0.3,
            'max_links_per_post' => 5,
            'prefer_fresh_content' => true,
            'anchor_text_variants' => true,
        ], $config);
    }
    
    public function setLogCallback(callable $callback): void
    {
        $this->logCallback = $callback;
        parent::setLogCallback($callback);
    }
    
    private function log(string $message, string $type = 'detail'): void
    {
        if ($this->logCallback !== null) {
            ($this->logCallback)('[SMART_LINK] ' . $message, $type);
        }
    }
    
    /**
     * Trova opportunità di link building con analisi semantica avanzata
     * 
     * @return array Lista di opportunità con score, anchor text suggerito, posizione consigliata
     */
    public function findLinkOpportunities(string $content, string $targetKeyword, int $limit = 5): array
    {
        $opportunities = [];
        $posts = $this->getCachedPosts();
        
        if (empty($posts)) {
            return [];
        }
        
        // Estrai entità e concetti dal contenuto
        $contentEntities = $this->extractEntities($content);
        $contentTopics = $this->extractTopics($content);
        
        foreach ($posts as $post) {
            $score = $this->calculateSemanticRelevance($content, $targetKeyword, $post, $contentEntities, $contentTopics);
            
            if ($score >= $this->config['min_relevance_score']) {
                $anchorText = $this->suggestAnchorText($post, $targetKeyword, $content);
                $position = $this->suggestLinkPosition($content, $post, $anchorText);
                
                $opportunities[] = [
                    'post' => $post,
                    'score' => $score,
                    'anchor_text' => $anchorText,
                    'suggested_position' => $position,
                    'context_snippet' => $this->extractContextSnippet($content, $position),
                    'link_type' => $this->determineLinkType($score),
                ];
            }
        }
        
        // Ordina per score e limita
        usort($opportunities, fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($opportunities, 0, $limit);
    }
    
    /**
     * Calcola la rilevanza semantica tra contenuto e post
     */
    private function calculateSemanticRelevance(
        string $content, 
        string $targetKeyword, 
        array $post, 
        array $contentEntities,
        array $contentTopics
    ): float {
        $score = 0;
        
        // 1. Rilevanza del titolo (peso 0.4)
        $titleScore = $this->calculateTextSimilarity($targetKeyword, $post['title']);
        $score += $titleScore * self::WEIGHT_TITLE;
        
        // 2. Rilevanza del contenuto (peso 0.3)
        if (!empty($post['excerpt'])) {
            $contentScore = $this->calculateTextSimilarity($content, $post['excerpt']);
            $score += $contentScore * self::WEIGHT_CONTENT;
        }
        
        // 3. Match entità (bonus)
        $postEntities = $this->extractEntities($post['title'] . ' ' . ($post['excerpt'] ?? ''));
        $entityOverlap = count(array_intersect($contentEntities, $postEntities));
        $score += min($entityOverlap * 0.05, 0.15); // Max 0.15 bonus
        
        // 4. Freshness (peso 0.1) - preferisci contenuti recenti
        if ($this->config['prefer_fresh_content'] && !empty($post['date'])) {
            $daysOld = $this->getDaysSince($post['date']);
            if ($daysOld < 30) {
                $score += self::WEIGHT_DATE * 0.5;
            } elseif ($daysOld < 90) {
                $score += self::WEIGHT_DATE * 0.3;
            }
        }
        
        return min($score, 1.0);
    }
    
    /**
     * Calcola similarità testuale (semplificata)
     */
    private function calculateTextSimilarity(string $text1, string $text2): float
    {
        $words1 = $this->getSignificantWords($text1);
        $words2 = $this->getSignificantWords($text2);
        
        if (empty($words1) || empty($words2)) {
            return 0;
        }
        
        $intersection = count(array_intersect($words1, $words2));
        $union = count(array_unique(array_merge($words1, $words2)));
        
        return $union > 0 ? $intersection / $union : 0;
    }
    
    /**
     * Estrai parole significative (escludi stop words)
     */
    private function getSignificantWords(string $text): array
    {
        $stopWords = [
            'il', 'lo', 'la', 'i', 'gli', 'le', 'di', 'a', 'da', 'in', 'con', 'su', 'per', 'tra', 'fra',
            'e', 'o', 'ma', 'se', 'come', 'che', 'chi', 'cui', 'quale', 'quali', 'questo', 'questa',
            'è', 'sono', 'era', 'erano', 'un', 'uno', 'una', 'dei', 'delle', 'del', 'della', 'dello'
        ];
        
        $text = mb_strtolower(strip_tags($text));
        $words = preg_split('/\s+/', preg_replace('/[^\w\s]/', '', $text));
        
        return array_filter($words, function($w) use ($stopWords) {
            return strlen($w) > 2 && !in_array($w, $stopWords);
        });
    }
    
    /**
     * Estrai entità dal testo (nomi, concetti chiave)
     */
    private function extractEntities(string $text): array
    {
        $entities = [];
        
        // Pattern per entità potenziali (sostantivi composti, nomi propri)
        if (preg_match_all('/\b[A-Z][a-z]+(?:\s+[A-Z][a-z]+)*\b/', $text, $matches)) {
            $entities = array_map('mb_strtolower', $matches[0]);
        }
        
        // Aggiungi bigrammi significativi
        $words = $this->getSignificantWords($text);
        for ($i = 0; $i < count($words) - 1; $i++) {
            $bigram = $words[$i] . ' ' . $words[$i + 1];
            if (strlen($bigram) > 8) {
                $entities[] = $bigram;
            }
        }
        
        return array_unique(array_slice($entities, 0, 20));
    }
    
    /**
     * Estrai topic principali dal testo
     */
    private function extractTopics(string $text): array
    {
        $topics = [];
        
        // Estrai parole più frequenti (escludendo stop words)
        $words = $this->getSignificantWords($text);
        $freq = array_count_values($words);
        arsort($freq);
        
        return array_slice(array_keys($freq), 0, 10);
    }
    
    /**
     * Suggerisci anchor text ottimizzato per SEO
     */
    private function suggestAnchorText(array $post, string $targetKeyword, string $content): string
    {
        $title = $post['title'];
        
        // Opzione 1: Usa il titolo del post (se breve)
        if (mb_strlen($title) <= 40) {
            return $title;
        }
        
        // Opzione 2: Estrai la parte più rilevante del titolo
        $titleWords = explode(' ', $title);
        $shortTitle = implode(' ', array_slice($titleWords, 0, 5));
        
        // Opzione 3: Cerca nel contenuto frasi che matchano con il post
        $patterns = [
            '/\b(sognare|significato|interpretazione)\s+[^.]{10,50}/i',
            '/\b(come|perché|cosa)\s+[^.]{10,50}/i',
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content, $matches)) {
                $candidate = trim($matches[0]);
                if (mb_strlen($candidate) >= 15 && mb_strlen($candidate) <= 50) {
                    return $candidate;
                }
            }
        }
        
        return $shortTitle;
    }
    
    /**
     * Suggerisci posizione ottimale per il link
     */
    private function suggestLinkPosition(string $content, array $post, string $anchorText): string
    {
        // Preferisci: primo paragrafo, dopo introduzione, o in sezioni correlate
        if (preg_match('/<p[^>]*>(.{100,300})<\/p>/', $content, $matches)) {
            return 'early_content';
        }
        
        return 'contextual';
    }
    
    /**
     * Estrai snippet di contesto per il link
     */
    private function extractContextSnippet(string $content, string $position): string
    {
        // Estrai i primi 200 caratteri di testo
        $text = strip_tags($content);
        return mb_substr($text, 0, 200) . '...';
    }
    
    /**
     * Determina il tipo di link in base allo score
     */
    private function determineLinkType(float $score): string
    {
        if ($score >= 0.7) return 'highly_relevant';
        if ($score >= 0.5) return 'relevant';
        return 'contextual';
    }
    
    /**
     * Crea topic clusters dai post esistenti
     * 
     * @return array Cluster di contenuti correlati
     */
    public function buildTopicClusters(): array
    {
        $posts = $this->getCachedPosts();
        $clusters = [];
        
        // Categorizza post per topic principali
        $topicKeywords = [
            'sogni' => ['sognare', 'sogno', 'incubo', 'onirico', 'inconscio'],
            'sonno' => ['dormire', 'sonno', 'insonnia', 'nanna', 'riposo', 'notte'],
            'psicologia' => ['psicologia', 'freud', 'jung', 'inconscio', 'mente'],
            'smorfia' => ['smorfia', 'napoletana', 'numeri', 'cabala'],
        ];
        
        foreach ($topicKeywords as $topic => $keywords) {
            $clusters[$topic] = [];
            
            foreach ($posts as $post) {
                $text = mb_strtolower($post['title'] . ' ' . ($post['excerpt'] ?? ''));
                $matches = 0;
                
                foreach ($keywords as $kw) {
                    if (strpos($text, $kw) !== false) {
                        $matches++;
                    }
                }
                
                if ($matches >= 2) {
                    $clusters[$topic][] = $post;
                }
            }
        }
        
        return $clusters;
    }
    
    /**
     * Suggerisci pillar content (contenuti pilastro) per il topic cluster
     */
    public function suggestPillarContent(string $topic): ?array
    {
        $clusters = $this->buildTopicClusters();
        $clusterPosts = $clusters[$topic] ?? [];
        
        if (empty($clusterPosts)) {
            return null;
        }
        
        // Trova il post con più link in entrata (simulato con views o semplicemente il più recente)
        usort($clusterPosts, function($a, $b) {
            $scoreA = ($a['score'] ?? 0) + (strtotime($a['date'] ?? 'now') / 1000000);
            $scoreB = ($b['score'] ?? 0) + (strtotime($b['date'] ?? 'now') / 1000000);
            return $scoreB <=> $scoreA;
        });
        
        return $clusterPosts[0];
    }
    
    /**
     * Genera link building report
     */
    public function generateLinkReport(): array
    {
        $posts = $this->getCachedPosts();
        $clusters = $this->buildTopicClusters();
        
        $report = [
            'total_posts' => count($posts),
            'clusters' => [],
            'orphan_posts' => [],
            'link_opportunities' => 0,
        ];
        
        foreach ($clusters as $topic => $clusterPosts) {
            $report['clusters'][$topic] = [
                'count' => count($clusterPosts),
                'posts' => array_column($clusterPosts, 'title'),
            ];
        }
        
        // Trova post orfani (non in alcun cluster)
        $clusteredIds = [];
        foreach ($clusters as $posts) {
            foreach ($posts as $post) {
                $clusteredIds[] = $post['id'] ?? $post['url'];
            }
        }
        
        foreach ($posts as $post) {
            $id = $post['id'] ?? $post['url'];
            if (!in_array($id, $clusteredIds)) {
                $report['orphan_posts'][] = $post['title'];
            }
        }
        
        return $report;
    }
    
    /**
     * Ottieni giorni trascorsi da una data
     */
    private function getDaysSince(string $date): int
    {
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return 999;
        }
        return (time() - $timestamp) / 86400;
    }
    
    /**
     * Costruisci contesto avanzato per il prompt AI
     */
    public function buildAdvancedPromptContext(string $topic, string $content = ''): string
    {
        $parts = [];
        
        // Link interni intelligenti
        if ($this->isEnabled()) {
            $opportunities = $this->findLinkOpportunities($content, $topic, $this->config['max_links_per_post']);
            
            if (!empty($opportunities)) {
                $parts[] = "LINK INTERNI STRATEGICI:\n"
                    . "Inserisci link interni verso questi articoli correlati. Usa anchor text descrittivo e naturale.\n"
                    . "Priorità: HIGH = link obbligatorio, MEDIUM = consigliato, CONTEXTUAL = se pertinente.\n";
                
                foreach ($opportunities as $opp) {
                    $priority = $opp['score'] > 0.6 ? 'HIGH' : ($opp['score'] > 0.4 ? 'MEDIUM' : 'CONTEXTUAL');
                    $parts[] = sprintf(
                        "[%s] '%s' -> %s\n   Anchor suggerita: '%s'\n   Contesto: %s",
                        $priority,
                        $opp['post']['title'],
                        $opp['post']['url'],
                        $opp['anchor_text'],
                        $opp['context_snippet']
                    );
                }
                
                // Suggerimenti per anchor text
                $parts[] = "\nREGOLE ANCHOR TEXT SEO:\n"
                    . "- Usa anchor text descrittivo (3-6 parole)\n"
                    . "- Evita 'clicca qui' o 'leggi qui'\n"
                    . "- Includi keyword pertinenti nell'anchor\n"
                    . "- Varia gli anchor text: non usare sempre lo stesso\n"
                    . "- Linka da parole che descrivono il contenuto di destinazione\n";
            }
        }
        
        // Topic cluster
        $clusters = $this->buildTopicClusters();
        $mainCluster = null;
        
        foreach ($clusters as $clusterTopic => $posts) {
            if (stripos($topic, $clusterTopic) !== false) {
                $mainCluster = $clusterTopic;
                break;
            }
        }
        
        if ($mainCluster) {
            $parts[] = "\nTOPIC CLUSTER: {$mainCluster}\n"
                . "Questo articolo appartiene al cluster '{$mainCluster}'.\n"
                . "Mantieni coerenza terminologica con gli altri articoli del cluster.\n";
        }
        
        return implode("\n", $parts);
    }
}
