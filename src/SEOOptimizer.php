<?php

/**
 * SEOOptimizer - Classe per ottimizzare i contenuti per SEO e GEO
 * 
 * Fornisce funzionalità avanzate per:
 * - Ottimizzazione on-page SEO
 * - GEO (Generative Engine Optimization)
 * - Analisi della qualità dei contenuti
 * - Suggerimenti per miglioramenti
 */
class SEOOptimizer
{
    private array $config;
    /** @var callable|null */
    private $logCallback = null;
    
    // Parametri SEO ottimali
    private const OPTIMAL_TITLE_LENGTH = [50, 60];
    private const OPTIMAL_META_DESC_LENGTH = [150, 160];
    private const OPTIMAL_CONTENT_LENGTH = [1200, 2500]; // parole
    private const KEYWORD_DENSITY_IDEAL = [1, 2.5]; // percentuale
    
    // Parole di transizione per leggibilità
    private const TRANSITION_WORDS = [
        'inoltre', 'tuttavia', 'pertanto', 'infatti', 'ad esempio',
        'in particolare', 'daltra parte', 'in conclusione', 'prima di tutto',
        'in secondo luogo', 'infine', 'mentre', 'poiché', 'dato che',
        'al contrario', 'allo stesso modo', 'invece', 'così', 'quindi'
    ];
    
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'min_word_count' => 800,
            'max_word_count' => 3000,
            'target_keyword_density' => 1.5,
            'min_headings' => 3,
            'min_paragraph_length' => 50,
            'max_paragraph_length' => 150,
        ], $config);
    }
    
    public function setLogCallback(callable $callback): void
    {
        $this->logCallback = $callback;
    }
    
    private function log(string $message, string $type = 'info'): void
    {
        if ($this->logCallback !== null) {
            ($this->logCallback)('[SEO] ' . $message, $type);
        }
    }
    
    /**
     * Analizza un articolo e restituisce un punteggio SEO/GEO dettagliato
     * 
     * @return array Report completo con punteggi e suggerimenti
     */
    public function analyzeArticle(string $title, string $content, string $metaDescription, string $targetKeyword): array
    {
        $report = [
            'overall_score' => 0,
            'seo_score' => 0,
            'geo_score' => 0,
            'readability_score' => 0,
            'technical_score' => 0,
            'checks' => [],
            'suggestions' => [],
        ];
        
        // Analisi titolo
        $titleAnalysis = $this->analyzeTitle($title, $targetKeyword);
        $report['checks']['title'] = $titleAnalysis;
        
        // Analisi meta description
        $metaAnalysis = $this->analyzeMetaDescription($metaDescription, $targetKeyword);
        $report['checks']['meta_description'] = $metaAnalysis;
        
        // Analisi contenuto
        $contentAnalysis = $this->analyzeContent($content, $targetKeyword);
        $report['checks']['content'] = $contentAnalysis;
        
        // Analisi struttura headings
        $headingsAnalysis = $this->analyzeHeadings($content, $targetKeyword);
        $report['checks']['headings'] = $headingsAnalysis;
        
        // Analisi leggibilità
        $readabilityAnalysis = $this->analyzeReadability($content);
        $report['checks']['readability'] = $readabilityAnalysis;
        
        // Analisi GEO
        $geoAnalysis = $this->analyzeGEO($title, $content, $metaDescription, $targetKeyword);
        $report['checks']['geo'] = $geoAnalysis;
        
        // Calcola punteggi
        $report['seo_score'] = $this->calculateSEOScore($report['checks']);
        $report['geo_score'] = $geoAnalysis['score'];
        $report['readability_score'] = $readabilityAnalysis['score'];
        $report['technical_score'] = $this->calculateTechnicalScore($report['checks']);
        $report['overall_score'] = round(
            ($report['seo_score'] * 0.35) + 
            ($report['geo_score'] * 0.25) + 
            ($report['readability_score'] * 0.25) + 
            ($report['technical_score'] * 0.15)
        );
        
        // Genera suggerimenti
        $report['suggestions'] = $this->generateSuggestions($report['checks']);
        
        return $report;
    }
    
    /**
     * Analisi del titolo per SEO
     */
    private function analyzeTitle(string $title, string $keyword): array
    {
        $length = mb_strlen($title);
        $hasKeyword = $this->containsKeyword($title, $keyword);
        $keywordPosition = $hasKeyword ? $this->getKeywordPosition($title, $keyword) : -1;
        
        $checks = [
            'length' => $length,
            'length_ok' => $length >= self::OPTIMAL_TITLE_LENGTH[0] && $length <= self::OPTIMAL_TITLE_LENGTH[1],
            'has_keyword' => $hasKeyword,
            'keyword_at_start' => $keywordPosition >= 0 && $keywordPosition <= 20,
            'has_power_words' => $this->hasPowerWords($title),
            'has_numbers' => preg_match('/\d/', $title) === 1,
            'is_clickable' => $this->isClickableTitle($title),
        ];
        
        // Calcola punteggio
        $score = 0;
        if ($checks['length_ok']) $score += 25;
        if ($checks['has_keyword']) $score += 25;
        if ($checks['keyword_at_start']) $score += 20;
        if ($checks['has_power_words']) $score += 10;
        if ($checks['has_numbers']) $score += 10;
        if ($checks['is_clickable']) $score += 10;
        
        $checks['score'] = $score;
        return $checks;
    }
    
    /**
     * Analisi della meta description
     */
    private function analyzeMetaDescription(string $meta, string $keyword): array
    {
        $length = mb_strlen($meta);
        $hasKeyword = $this->containsKeyword($meta, $keyword);
        $hasCallToAction = $this->hasCallToAction($meta);
        
        $checks = [
            'length' => $length,
            'length_ok' => $length >= self::OPTIMAL_META_DESC_LENGTH[0] && $length <= self::OPTIMAL_META_DESC_LENGTH[1],
            'has_keyword' => $hasKeyword,
            'has_call_to_action' => $hasCallToAction,
            'is_compelling' => $this->isCompelling($meta),
        ];
        
        $score = 0;
        if ($checks['length_ok']) $score += 30;
        if ($checks['has_keyword']) $score += 30;
        if ($checks['has_call_to_action']) $score += 20;
        if ($checks['is_compelling']) $score += 20;
        
        $checks['score'] = $score;
        return $checks;
    }
    
    /**
     * Analisi del contenuto
     */
    private function analyzeContent(string $content, string $keyword): array
    {
        $plainText = strip_tags($content);
        $wordCount = str_word_count($plainText);
        $keywordCount = $this->countKeywordOccurrences($plainText, $keyword);
        $keywordDensity = $wordCount > 0 ? ($keywordCount / $wordCount) * 100 : 0;
        
        // Analisi link
        $internalLinks = substr_count($content, 'href="/');
        $externalLinks = substr_count($content, 'href="http') - $internalLinks;
        
        // Analisi immagini
        $images = substr_count($content, '<img');
        $imagesWithAlt = substr_count($content, 'alt="');
        
        $checks = [
            'word_count' => $wordCount,
            'word_count_ok' => $wordCount >= self::OPTIMAL_CONTENT_LENGTH[0] && $wordCount <= self::OPTIMAL_CONTENT_LENGTH[1],
            'keyword_count' => $keywordCount,
            'keyword_density' => round($keywordDensity, 2),
            'keyword_density_ok' => $keywordDensity >= self::KEYWORD_DENSITY_IDEAL[0] && $keywordDensity <= self::KEYWORD_DENSITY_IDEAL[1],
            'internal_links' => $internalLinks,
            'external_links' => $externalLinks,
            'has_links' => $internalLinks > 0 || $externalLinks > 0,
            'images' => $images,
            'images_with_alt' => $imagesWithAlt,
            'all_images_have_alt' => $images === 0 || $images === $imagesWithAlt,
        ];
        
        $score = 0;
        if ($checks['word_count_ok']) $score += 30;
        if ($checks['keyword_density_ok']) $score += 25;
        if ($keywordCount >= 3) $score += 15;
        if ($checks['has_links']) $score += 15;
        if ($checks['all_images_have_alt']) $score += 15;
        
        $checks['score'] = $score;
        return $checks;
    }
    
    /**
     * Analisi della struttura headings
     */
    private function analyzeHeadings(string $content, string $keyword): array
    {
        preg_match_all('/<h([1-6])[^>]*>(.*?)<\/h\1>/is', $content, $matches);
        
        $h1Count = 0;
        $h2Count = 0;
        $h3Count = 0;
        $keywordInH1 = false;
        $keywordInH2 = 0;
        
        foreach ($matches[1] as $i => $level) {
            $headingText = strip_tags($matches[2][$i]);
            if ($level == '1') {
                $h1Count++;
                if ($this->containsKeyword($headingText, $keyword)) {
                    $keywordInH1 = true;
                }
            } elseif ($level == '2') {
                $h2Count++;
                if ($this->containsKeyword($headingText, $keyword)) {
                    $keywordInH2++;
                }
            } elseif ($level == '3') {
                $h3Count++;
            }
        }
        
        $checks = [
            'h1_count' => $h1Count,
            'h1_ok' => $h1Count === 1,
            'h2_count' => $h2Count,
            'h2_ok' => $h2Count >= 2,
            'h3_count' => $h3Count,
            'keyword_in_h1' => $keywordInH1,
            'keyword_in_h2' => $keywordInH2,
            'structure_ok' => $h1Count === 1 && $h2Count >= 2,
        ];
        
        $score = 0;
        if ($checks['h1_ok']) $score += 25;
        if ($checks['h2_ok']) $score += 20;
        if ($h3Count >= 2) $score += 15;
        if ($keywordInH1) $score += 20;
        if ($keywordInH2 >= 1) $score += 20;
        
        $checks['score'] = $score;
        return $checks;
    }
    
    /**
     * Analisi della leggibilità
     */
    private function analyzeReadability(string $content): array
    {
        $plainText = strip_tags($content);
        $sentences = preg_split('/[.!?]+/', $plainText, -1, PREG_SPLIT_NO_EMPTY);
        $words = str_word_count($plainText, 1);
        $wordCount = count($words);
        
        // Lunghezza media frasi
        $avgSentenceLength = count($sentences) > 0 ? $wordCount / count($sentences) : 0;
        
        // Paragrafi
        preg_match_all('/<p[^>]*>(.*?)<\/p>/is', $content, $paragraphMatches);
        $paragraphs = $paragraphMatches[1];
        $paragraphCount = count($paragraphs);
        
        // Parole di transizione
        $transitionWordCount = 0;
        $textLower = mb_strtolower($plainText);
        foreach (self::TRANSITION_WORDS as $tw) {
            $transitionWordCount += substr_count($textLower, $tw);
        }
        $transitionWordRatio = $wordCount > 0 ? ($transitionWordCount / $wordCount) * 100 : 0;
        
        $checks = [
            'avg_sentence_length' => round($avgSentenceLength, 1),
            'sentence_length_ok' => $avgSentenceLength <= 20,
            'paragraph_count' => $paragraphCount,
            'paragraph_count_ok' => $paragraphCount >= 5,
            'transition_words_ratio' => round($transitionWordRatio, 2),
            'transition_words_ok' => $transitionWordRatio >= 15,
            'passive_voice_estimate' => $this->estimatePassiveVoice($plainText),
        ];
        
        $score = 0;
        if ($checks['sentence_length_ok']) $score += 30;
        if ($checks['paragraph_count_ok']) $score += 25;
        if ($checks['transition_words_ok']) $score += 25;
        if ($checks['passive_voice_estimate'] < 15) $score += 20;
        
        $checks['score'] = $score;
        return $checks;
    }
    
    /**
     * Analisi GEO (Generative Engine Optimization)
     */
    private function analyzeGEO(string $title, string $content, string $metaDescription, string $keyword): array
    {
        $plainText = strip_tags($content);
        
        // Verifica se il contenuto è facilmente "citabile" dalle AI
        $hasClearDefinitions = $this->hasClearDefinitions($plainText);
        $hasStructuredData = $this->hasStructuredDataMarkers($content);
        $hasFAQ = strpos($content, 'faq-item') !== false || strpos($content, 'FAQ') !== false;
        $hasLists = preg_match('/<(ul|ol)[^>]*>/', $content) === 1;
        $hasTables = strpos($content, '<table') !== false;
        
        // Entità e concetti chiari
        $entities = $this->extractEntities($plainText);
        
        // Risposta diretta alla query (per featured snippets)
        $hasDirectAnswer = $this->hasDirectAnswer($plainText, $keyword);
        
        // Primo paragrafo ottimizzato
        $firstParagraph = $this->getFirstParagraph($plainText);
        $firstParagraphOptimized = $this->containsKeyword($firstParagraph, $keyword) && mb_strlen($firstParagraph) <= 300;
        
        $checks = [
            'has_clear_definitions' => $hasClearDefinitions,
            'has_structured_data' => $hasStructuredData,
            'has_faq' => $hasFAQ,
            'has_lists' => $hasLists,
            'has_tables' => $hasTables,
            'entities_count' => count($entities),
            'has_direct_answer' => $hasDirectAnswer,
            'first_paragraph_optimized' => $firstParagraphOptimized,
            'content_is_scannable' => $hasLists || $hasTables || substr_count($content, '<h2') >= 3,
        ];
        
        $score = 0;
        if ($hasClearDefinitions) $score += 15;
        if ($hasStructuredData) $score += 15;
        if ($hasFAQ) $score += 15;
        if ($hasLists) $score += 10;
        if ($hasTables) $score += 10;
        if ($hasDirectAnswer) $score += 15;
        if ($firstParagraphOptimized) $score += 10;
        if ($checks['content_is_scannable']) $score += 10;
        
        $checks['score'] = $score;
        $checks['entities'] = $entities;
        
        return $checks;
    }
    
    /**
     * Calcola il punteggio SEO complessivo
     */
    private function calculateSEOScore(array $checks): int
    {
        $scores = [
            $checks['title']['score'] ?? 0,
            $checks['meta_description']['score'] ?? 0,
            $checks['content']['score'] ?? 0,
            $checks['headings']['score'] ?? 0,
        ];
        return round(array_sum($scores) / count($scores));
    }
    
    /**
     * Calcola il punteggio tecnico
     */
    private function calculateTechnicalScore(array $checks): int
    {
        $score = 0;
        if ($checks['headings']['h1_ok'] ?? false) $score += 25;
        if ($checks['headings']['structure_ok'] ?? false) $score += 25;
        if ($checks['content']['all_images_have_alt'] ?? false) $score += 25;
        if ($checks['content']['has_links'] ?? false) $score += 25;
        return $score;
    }
    
    /**
     * Genera suggerimenti per migliorare l'articolo
     */
    private function generateSuggestions(array $checks): array
    {
        $suggestions = [];
        
        // Titolo
        if (!($checks['title']['length_ok'] ?? true)) {
            $suggestions[] = 'Il titolo dovrebbe essere tra 50-60 caratteri per non essere troncato nelle SERP';
        }
        if (!($checks['title']['has_keyword'] ?? true)) {
            $suggestions[] = 'Includi la keyword principale nel titolo';
        }
        if (!($checks['title']['keyword_at_start'] ?? true)) {
            $suggestions[] = 'Posiziona la keyword all\'inizio del titolo per maggiore impatto SEO';
        }
        
        // Meta description
        if (!($checks['meta_description']['length_ok'] ?? true)) {
            $suggestions[] = 'La meta description dovrebbe essere tra 150-160 caratteri';
        }
        if (!($checks['meta_description']['has_call_to_action'] ?? true)) {
            $suggestions[] = 'Aggiungi una call to action nella meta description (es. "Scopri...", "Leggi...")';
        }
        
        // Contenuto
        if (!($checks['content']['word_count_ok'] ?? true)) {
            $current = $checks['content']['word_count'] ?? 0;
            if ($current < self::OPTIMAL_CONTENT_LENGTH[0]) {
                $suggestions[] = 'Aumenta la lunghezza del contenuto a almeno ' . self::OPTIMAL_CONTENT_LENGTH[0] . ' parole';
            } else {
                $suggestions[] = 'Riduci il contenuto per mantenere la focalizzazione';
            }
        }
        if (!($checks['content']['keyword_density_ok'] ?? true)) {
            $density = $checks['content']['keyword_density'] ?? 0;
            if ($density < self::KEYWORD_DENSITY_IDEAL[0]) {
                $suggestions[] = 'Aumenta la densità della keyword (obiettivo: 1-2.5%)';
            } else {
                $suggestions[] = 'Riduci l\'uso della keyword per evitare keyword stuffing';
            }
        }
        
        // Headings
        if (!($checks['headings']['h1_ok'] ?? true)) {
            $suggestions[] = 'Usa esattamente un tag H1 per articolo';
        }
        if (!($checks['headings']['h2_ok'] ?? true)) {
            $suggestions[] = 'Aggiungi almeno 2 sottotitoli H2 per strutturare il contenuto';
        }
        
        // GEO
        if (!($checks['geo']['has_faq'] ?? true)) {
            $suggestions[] = 'Aggiungi una sezione FAQ per aumentare le probabilità di apparire nei featured snippets';
        }
        if (!($checks['geo']['has_direct_answer'] ?? true)) {
            $suggestions[] = 'Fornisci una risposta diretta e concisa alla query nei primi 100 caratteri';
        }
        if (!($checks['geo']['content_is_scannable'] ?? true)) {
            $suggestions[] = 'Usa liste puntate e sottotitoli per rendere il contenuto più scansionabile dalle AI';
        }
        
        return $suggestions;
    }
    
    // ==================== METODI DI UTILITÀ ====================
    
    private function containsKeyword(string $text, string $keyword): bool
    {
        $textLower = mb_strtolower($text);
        $keywordLower = mb_strtolower($keyword);
        
        // Match esatto
        if (strpos($textLower, $keywordLower) !== false) {
            return true;
        }
        
        // Match parziale (prime 3 parole della keyword)
        $keywordParts = explode(' ', $keywordLower);
        if (count($keywordParts) >= 3) {
            $partialKeyword = implode(' ', array_slice($keywordParts, 0, 3));
            if (strpos($textLower, $partialKeyword) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    private function getKeywordPosition(string $text, string $keyword): int
    {
        $pos = mb_stripos($text, $keyword);
        return $pos === false ? -1 : $pos;
    }
    
    private function countKeywordOccurrences(string $text, string $keyword): int
    {
        return substr_count(mb_strtolower($text), mb_strtolower($keyword));
    }
    
    private function hasPowerWords(string $title): bool
    {
        $powerWords = [
            'guida', 'definitiva', 'completa', 'essenziale', 'pratica',
            'facile', 'veloce', 'gratis', 'migliore', 'top',
            'segreti', 'trucchi', 'consigli', 'errori', 'evitare',
            'scopri', 'impara', 'trova', 'risolvi', 'migliora'
        ];
        
        $titleLower = mb_strtolower($title);
        foreach ($powerWords as $word) {
            if (strpos($titleLower, $word) !== false) {
                return true;
            }
        }
        return false;
    }
    
    private function isClickableTitle(string $title): bool
    {
        // Verifica se il titolo suscita curiosità o promette un beneficio
        $clickablePatterns = [
            '/\?$/', // Domanda
            '/\d+/', // Numeri
            '/come |perché |cosa |quando |dove /i', // Interrogativi
            '/miglior|più |meno |più/i', // Comparativi
        ];
        
        foreach ($clickablePatterns as $pattern) {
            if (preg_match($pattern, $title)) {
                return true;
            }
        }
        return false;
    }
    
    private function hasCallToAction(string $text): bool
    {
        $ctaWords = ['scopri', 'leggi', 'trova', 'impara', 'vedi', 'guarda', 'scoprire', 'imparare'];
        $textLower = mb_strtolower($text);
        foreach ($ctaWords as $word) {
            if (strpos($textLower, $word) !== false) {
                return true;
            }
        }
        return false;
    }
    
    private function isCompelling(string $text): bool
    {
        // Verifica se il testo è coinvolgente (usa benefici, curiosità, urgenza)
        $compellingPatterns = [
            '/\?$/', // Domanda
            '/!$/', // Esclamazione
            '/benefici|vantaggi|perché|motivi/i',
        ];
        
        foreach ($compellingPatterns as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }
        return false;
    }
    
    private function estimatePassiveVoice(string $text): float
    {
        // Stima semplificata della voce passiva in italiano
        $passivePatterns = [
            '/\b(è|sono|era|erano|stato|stata|stati|state)\s+\w+(ato|ito|uto)\b/i',
            '/\b(viene|vengono|veniva|venivano)\s+\w+(ato|ito|uto)\b/i',
        ];
        
        $sentences = preg_split('/[.!?]+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (empty($sentences)) return 0;
        
        $passiveCount = 0;
        foreach ($sentences as $sentence) {
            foreach ($passivePatterns as $pattern) {
                if (preg_match($pattern, $sentence)) {
                    $passiveCount++;
                    break;
                }
            }
        }
        
        return (count($sentences) > 0) ? ($passiveCount / count($sentences)) * 100 : 0;
    }
    
    private function hasClearDefinitions(string $text): bool
    {
        // Cerca pattern di definizione chiara
        $definitionPatterns = [
            '/\b(significa|definisce|indica|è un|è una)\b/i',
            '/\b[in\s]+sostanza\b/i',
            '/\bin\s+altre\s+parole\b/i',
        ];
        
        foreach ($definitionPatterns as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }
        return false;
    }
    
    private function hasStructuredDataMarkers(string $content): bool
    {
        return strpos($content, 'application/ld+json') !== false ||
               strpos($content, 'schema.org') !== false ||
               strpos($content, 'faq-item') !== false;
    }
    
    private function extractEntities(string $text): array
    {
        // Estrazione semplificata di entità (nomi propri, concetti chiave)
        $entities = [];
        
        // Cerca parole con maiuscola (potenziali nomi propri)
        if (preg_match_all('/\b[A-Z][a-z]+(?:\s+[A-Z][a-z]+)*\b/', $text, $matches)) {
            $entities = array_unique($matches[0]);
        }
        
        return array_slice($entities, 0, 10); // Max 10 entità
    }
    
    private function hasDirectAnswer(string $text, string $keyword): bool
    {
        // Cerca una risposta diretta nei primi 200 caratteri
        $firstPart = mb_substr($text, 0, 200);
        
        // Pattern di risposta diretta
        $answerPatterns = [
            '/\b(significa che|indica|è)\b/i',
            '/\b(in\s+generale|generalmente)\b/i',
        ];
        
        foreach ($answerPatterns as $pattern) {
            if (preg_match($pattern, $firstPart)) {
                return true;
            }
        }
        return false;
    }
    
    private function getFirstParagraph(string $text): string
    {
        $sentences = preg_split('/[.!?]+/', $text, 2, PREG_SPLIT_NO_EMPTY);
        return trim($sentences[0] ?? '');
    }
    
    /**
     * Genera un prompt ottimizzato per AI che produce contenuti SEO/GEO
     */
    public static function generateOptimizedPrompt(string $basePrompt, string $keyword, array $options = []): string
    {
        $seoGuidelines = <<<GUIDELINES


---
OTTIMIZZAZIONE SEO E GEO - ISTRUZIONI OBBLIGATORIE:

1. SEO ON-PAGE:
   - Titolo: 50-60 caratteri, keyword all'inizio, tono accattivante
   - Meta description: 150-160 caratteri, includi keyword e call to action
   - Struttura: 1 H1, almeno 3 H2 con keyword, H3 dove appropriato
   - Contenuto: 1200-1800 parole, densità keyword 1-2%
   - Primo paragrafo: rispondi direttamente alla query entro 100 parole

2. GEO (GENERATIVE ENGINE OPTIMIZATION):
   - Fornisci definizioni chiare e concise
   - Usa liste puntate per informazioni scansionabili
   - Includi una sezione FAQ con domande specifiche
   - Struttura il contenuto con markup semantico chiaro
   - Usa paragrafi brevi (max 3-4 frasi)
   - Includi dati strutturati dove possibile

3. FEATURED SNIPPET OPTIMIZATION:
   - Rispondi alla domanda principale nei primi 40-60 caratteri
   - Usa formati: definizione, lista numerata, tabella, passaggi
   - Per domande "come": usa lista numerata di passaggi
   - Per domande "cosa è": usa definizione concisa seguita da dettagli
   - Per domande "perché": spiega causa-effetto chiaramente

4. ENTITÀ E CONTESTO:
   - Menziona entità correlate (persone, luoghi, concetti)
   - Usa sinonimi e termini semanticamente correlati
   - Crea collegamenti logici tra concetti

GUIDELINES;

        return $basePrompt . $seoGuidelines;
    }
}
