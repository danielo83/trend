<?php

/**
 * FeaturedSnippetOptimizer - Ottimizzazione per Featured Snippets di Google
 * 
 * Fornisce strutture e formati ottimali per conquistare:
 * - Paragraph snippets (definizioni)
 * - List snippets (elenchi puntati/numerati)
 * - Table snippets (tabelle comparative)
 * - Video snippets
 */
class FeaturedSnippetOptimizer
{
    /**
     * Tipi di featured snippet supportati
     */
    public const TYPE_PARAGRAPH = 'paragraph';
    public const TYPE_LIST = 'list';
    public const TYPE_TABLE = 'table';
    public const TYPE_VIDEO = 'video';
    
    /**
     * Pattern di query che generano featured snippets
     */
    private const SNIPPET_PATTERNS = [
        'definition' => [
            'patterns' => ['/cosa (è|significa|vuol dire)/i', '/definizione di/i', '/che cos\'è/i'],
            'type' => self::TYPE_PARAGRAPH,
        ],
        'how_to' => [
            'patterns' => ['/come (fare|si fa|posso|possiamo)/i', '/guida per/i', '/tutorial/i'],
            'type' => self::TYPE_LIST,
        ],
        'list' => [
            'patterns' => ['/quali sono/i', '/elenco di/i', '/lista di/i', '/migliori/i', '/tipi di/i'],
            'type' => self::TYPE_LIST,
        ],
        'comparison' => [
            'patterns' => ['/differenza (tra|di)/i', '/vs/i', '/confronto/i', '/meglio/i'],
            'type' => self::TYPE_TABLE,
        ],
        'steps' => [
            'patterns' => ['/passaggi/i', '/step/i', '/fasi/i', '/procedura/i'],
            'type' => self::TYPE_LIST,
        ],
    ];
    
    /**
     * Analizza una keyword e determina il tipo di featured snippet più probabile
     */
    public function analyzeQuery(string $keyword): array
    {
        $result = [
            'keyword' => $keyword,
            'snippet_type' => null,
            'confidence' => 0,
            'suggested_format' => null,
        ];
        
        foreach (self::SNIPPET_PATTERNS as $category => $data) {
            foreach ($data['patterns'] as $pattern) {
                if (preg_match($pattern, $keyword)) {
                    $result['snippet_type'] = $data['type'];
                    $result['confidence'] = 0.8;
                    $result['suggested_format'] = $this->getFormatGuidelines($data['type']);
                    return $result;
                }
            }
        }
        
        // Default: paragraph per query informative
        $result['snippet_type'] = self::TYPE_PARAGRAPH;
        $result['confidence'] = 0.5;
        $result['suggested_format'] = $this->getFormatGuidelines(self::TYPE_PARAGRAPH);
        
        return $result;
    }
    
    /**
     * Ottieni linee guida di formattazione per un tipo di snippet
     */
    private function getFormatGuidelines(string $type): array
    {
        $guidelines = [
            self::TYPE_PARAGRAPH => [
                'max_length' => 320,
                'optimal_length' => 250,
                'structure' => 'Definizione diretta seguita da contesto',
                'example' => '<p>Il [concetto] è [definizione breve]. [Contesto aggiuntivo].</p>',
                'tips' => [
                    'Inizia con la definizione entro le prime 50 parole',
                    'Usa il formato "[Termine] è [definizione]"',
                    'Mantieni il paragrafo tra 40-60 parole per la definizione',
                    'Aggiungi dettagli dopo la definizione principale',
                ],
            ],
            self::TYPE_LIST => [
                'max_items' => 8,
                'optimal_items' => 5,
                'structure' => 'Introduzione + lista ordinata o non ordinata',
                'example' => '<p>Ecco i passaggi...</p><ol><li>Primo passo</li>...</ol>',
                'tips' => [
                    'Usa tag <ol> per procedure sequenziali',
                    'Usa tag <ul> per elenchi non ordinati',
                    'Mantieni ogni item tra 40-80 caratteri',
                    'Inizia con verbi d\'azione per guide',
                    'Numeri specifici aumentano le chances (es. "7 consigli")',
                ],
            ],
            self::TYPE_TABLE => [
                'max_rows' => 5,
                'max_cols' => 3,
                'structure' => 'Tabella con header chiari',
                'example' => '<table><thead><tr><th>Caratteristica</th><th>Opzione A</th><th>Opzione B</th></tr></thead>...',
                'tips' => [
                    'Usa tag <thead> per l\'intestazione',
                    'Mantieni i dati concisi',
                    'Confronta massimo 3-4 elementi',
                    'Usa unità di misura consistenti',
                ],
            ],
            self::TYPE_VIDEO => [
                'structure' => 'Video con transcript ottimizzato',
                'tips' => [
                    'Includi timestamp nel transcript',
                    'Struttura il video in sezioni chiare',
                    'Usa schema markup VideoObject',
                ],
            ],
        ];
        
        return $guidelines[$type] ?? $guidelines[self::TYPE_PARAGRAPH];
    }
    
    /**
     * Genera contenuto ottimizzato per featured snippet
     */
    public function generateSnippetContent(string $keyword, string $content, string $type): array
    {
        $result = [
            'type' => $type,
            'snippet_html' => '',
            'snippet_text' => '',
            'full_content' => $content,
        ];
        
        switch ($type) {
            case self::TYPE_PARAGRAPH:
                $result = array_merge($result, $this->generateParagraphSnippet($keyword, $content));
                break;
            case self::TYPE_LIST:
                $result = array_merge($result, $this->generateListSnippet($keyword, $content));
                break;
            case self::TYPE_TABLE:
                $result = array_merge($result, $this->generateTableSnippet($keyword, $content));
                break;
        }
        
        return $result;
    }
    
    /**
     * Genera snippet di tipo paragrafo (definizione)
     */
    private function generateParagraphSnippet(string $keyword, string $content): array
    {
        // Estrai il primo paragrafo significativo
        $plainText = strip_tags($content);
        $sentences = preg_split('/(?<=[.!?])\s+/', $plainText, -1, PREG_SPLIT_NO_EMPTY);
        
        $definition = '';
        $maxLength = 320;
        
        // Cerca una frase che definisce il concetto
        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            $lowerSentence = mb_strtolower($sentence);
            
            // Pattern di definizione
            if (preg_match('/\b(è|significa|indica|definisce|si tratta di)\b/i', $lowerSentence)) {
                $definition = $sentence;
                break;
            }
        }
        
        // Se non trovata, usa le prime 2-3 frasi
        if (empty($definition) && count($sentences) >= 2) {
            $definition = $sentences[0] . ' ' . $sentences[1];
        }
        
        // Tronca se necessario
        if (mb_strlen($definition) > $maxLength) {
            $definition = mb_substr($definition, 0, $maxLength - 3) . '...';
        }
        
        return [
            'snippet_text' => $definition,
            'snippet_html' => '<p>' . htmlspecialchars($definition) . '</p>',
            'word_count' => str_word_count($definition),
            'is_optimal' => mb_strlen($definition) <= 250,
        ];
    }
    
    /**
     * Genera snippet di tipo lista
     */
    private function generateListSnippet(string $keyword, string $content): array
    {
        // Cerca liste esistenti nel contenuto
        preg_match_all('/<(ul|ol)[^>]*>(.*?)<\/\1>/is', $content, $matches, PREG_SET_ORDER);
        
        $bestList = null;
        $bestScore = 0;
        
        foreach ($matches as $match) {
            $listHtml = $match[0];
            $listText = strip_tags($listHtml);
            
            // Score basato su rilevanza e struttura
            $score = 0;
            
            // Numero di item
            $items = substr_count($listHtml, '<li');
            if ($items >= 3 && $items <= 8) {
                $score += 30;
            }
            
            // Lunghezza media item
            $itemTexts = preg_split('/<li[^>]*>/i', $listHtml);
            $avgItemLength = 0;
            if (count($itemTexts) > 1) {
                $lengths = array_map(fn($t) => strlen(strip_tags($t)), array_slice($itemTexts, 1));
                $avgItemLength = array_sum($lengths) / count($lengths);
            }
            if ($avgItemLength >= 30 && $avgItemLength <= 100) {
                $score += 20;
            }
            
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestList = $listHtml;
            }
        }
        
        if ($bestList) {
            return [
                'snippet_html' => $bestList,
                'snippet_text' => strip_tags($bestList),
                'items_count' => substr_count($bestList, '<li'),
                'is_optimal' => $bestScore >= 40,
            ];
        }
        
        // Se non trovata lista, estrai punti chiave
        return $this->extractKeyPoints($content);
    }
    
    /**
     * Estrai punti chiave per creare una lista
     */
    private function extractKeyPoints(string $content): array
    {
        // Cerca headings H2/H3
        preg_match_all('/<h[23][^>]*>(.*?)<\/h[23]>/is', $content, $headings);
        
        $items = [];
        foreach ($headings[1] as $heading) {
            $text = strip_tags($heading);
            if (strlen($text) > 10 && strlen($text) < 100) {
                $items[] = $text;
            }
        }
        
        if (count($items) >= 3) {
            $listHtml = '<ul>' . implode('', array_map(fn($i) => '<li>' . htmlspecialchars($i) . '</li>', array_slice($items, 0, 7))) . '</ul>';
            
            return [
                'snippet_html' => $listHtml,
                'snippet_text' => strip_tags($listHtml),
                'items_count' => count(array_slice($items, 0, 7)),
                'is_optimal' => count($items) >= 4,
                'note' => 'Generato da headings',
            ];
        }
        
        return [
            'snippet_html' => '',
            'snippet_text' => '',
            'items_count' => 0,
            'is_optimal' => false,
            'note' => 'Nessuna lista trovata',
        ];
    }
    
    /**
     * Genera snippet di tipo tabella
     */
    private function generateTableSnippet(string $keyword, string $content): array
    {
        // Cerca tabelle esistenti
        preg_match_all('/<table[^>]*>(.*?)<\/table>/is', $content, $matches, PREG_SET_ORDER);
        
        if (!empty($matches)) {
            $table = $matches[0][0];
            $rows = substr_count($table, '<tr');
            
            return [
                'snippet_html' => $table,
                'snippet_text' => $this->tableToText($table),
                'rows_count' => $rows,
                'is_optimal' => $rows >= 3 && $rows <= 6,
            ];
        }
        
        return [
            'snippet_html' => '',
            'snippet_text' => '',
            'rows_count' => 0,
            'is_optimal' => false,
        ];
    }
    
    /**
     * Converte tabella in testo
     */
    private function tableToText(string $tableHtml): string
    {
        $text = strip_tags(str_replace(['</td>', '</th>'], " | ", $tableHtml));
        return preg_replace('/\s+\|\s+/', " | ", $text);
    }
    
    /**
     * Genera istruzioni per AI su come strutturare il contenuto per featured snippet
     */
    public function generatePromptInstructions(string $keyword): string
    {
        $analysis = $this->analyzeQuery($keyword);
        $type = $analysis['snippet_type'];
        $guidelines = $analysis['suggested_format'];
        
        $instructions = <<<INSTRUCTIONS

---
OTTIMIZZAZIONE FEATURED SNIPPET (OBBLIGATORIO)

Tipo di snippet previsto: {$type}
Affidabilità: {$analysis['confidence']}

STRUTTURA RICHIESTA:
{$guidelines['structure']}

LINEE GUIDA SPECIFICHE:
INSTRUCTIONS;

        foreach ($guidelines['tips'] as $tip) {
            $instructions .= "\n- {$tip}";
        }
        
        // Istruzioni specifiche per tipo
        $instructions .= "\n\nFORMATO HTML RICHIESTO:\n";
        $instructions .= $guidelines['example'] . "\n";
        
        // Aggiungi esempio specifico per la keyword
        $instructions .= $this->generateSpecificExample($keyword, $type);
        
        return $instructions;
    }
    
    /**
     * Genera esempio specifico per la keyword
     */
    private function generateSpecificExample(string $keyword, string $type): string
    {
        $examples = [
            self::TYPE_PARAGRAPH => <<<EX

ESEMPIO PER "{$keyword}":
<p>{$keyword} è [definizione concisa in 20-30 parole]. Questo fenomeno [contesto aggiuntivo che spiega l'importanza o le implicazioni].</p>

Poi continua con contenuto dettagliato...
EX,
            self::TYPE_LIST => <<<EX

ESEMPIO PER "{$keyword}":
<p>Ecco {$keyword} in modo efficace:</p>
<ol>
<li><strong>Primo passo</strong>: descrizione concisa dell'azione</li>
<li><strong>Secondo passo</strong>: descrizione concisa dell'azione</li>
<li><strong>Terzo passo</strong>: descrizione concisa dell'azione</li>
</ol>
EX,
            self::TYPE_TABLE => <<<EX

ESEMPIO PER "{$keyword}":
<table>
<thead>
<tr><th>Caratteristica</th><th>Opzione A</th><th>Opzione B</th></tr>
</thead>
<tbody>
<tr><td>Caratteristica 1</td><td>Valore A1</td><td>Valore B1</td></tr>
<tr><td>Caratteristica 2</td><td>Valore A2</td><td>Valore B2</td></tr>
</tbody>
</table>
EX,
        ];
        
        return $examples[$type] ?? '';
    }
    
    /**
     * Valuta la qualità di un contenuto per featured snippet
     */
    public function evaluateSnippetPotential(string $content, string $keyword): array
    {
        $analysis = $this->analyzeQuery($keyword);
        $type = $analysis['snippet_type'];
        
        $score = 0;
        $checks = [];
        
        // Check 1: Risposta diretta nei primi 100 caratteri
        $first100 = mb_substr(strip_tags($content), 0, 100);
        $hasDirectAnswer = preg_match('/\b(è|significa|indica|sono|include)\b/i', $first100);
        $checks['direct_answer'] = $hasDirectAnswer;
        if ($hasDirectAnswer) $score += 25;
        
        // Check 2: Struttura corretta per il tipo
        switch ($type) {
            case self::TYPE_PARAGRAPH:
                $hasGoodParagraph = preg_match('/<p[^>]*>[^<]{100,250}<\/p>/', $content);
                $checks['optimal_paragraph'] = (bool)$hasGoodParagraph;
                if ($hasGoodParagraph) $score += 25;
                break;
                
            case self::TYPE_LIST:
                $hasList = preg_match('/<(ul|ol)[^>]*>.*<li.*>.*<\/li>.*<\/\1>/is', $content);
                $checks['has_list'] = (bool)$hasList;
                if ($hasList) $score += 25;
                
                $listItems = substr_count($content, '<li');
                $checks['list_items_count'] = $listItems;
                if ($listItems >= 3 && $listItems <= 8) $score += 15;
                break;
                
            case self::TYPE_TABLE:
                $hasTable = preg_match('/<table[^>]*>.*<\/table>/is', $content);
                $checks['has_table'] = (bool)$hasTable;
                if ($hasTable) $score += 25;
                break;
        }
        
        // Check 3: Keyword nei primi 50 caratteri
        $first50 = mb_strtolower(mb_substr(strip_tags($content), 0, 50));
        $keywordInOpening = strpos($first50, mb_strtolower($keyword)) !== false;
        $checks['keyword_in_opening'] = $keywordInOpening;
        if ($keywordInOpening) $score += 20;
        
        // Check 4: Presenza di FAQ
        $hasFAQ = preg_match('/<h[23][^>]*>.*(faq|domande|frequently).*<\/h[23]>/i', $content) ||
                  strpos($content, 'faq-item') !== false;
        $checks['has_faq'] = $hasFAQ;
        if ($hasFAQ) $score += 10;
        
        // Check 5: Schema markup
        $hasSchema = strpos($content, 'application/ld+json') !== false;
        $checks['has_schema'] = $hasSchema;
        if ($hasSchema) $score += 5;
        
        return [
            'potential_score' => $score,
            'max_score' => 100,
            'snippet_type' => $type,
            'checks' => $checks,
            'recommendations' => $this->getImprovementRecommendations($checks, $type),
        ];
    }
    
    /**
     * Genera raccomandazioni per migliorare il featured snippet
     */
    private function getImprovementRecommendations(array $checks, string $type): array
    {
        $recommendations = [];
        
        if (!$checks['direct_answer'] ?? false) {
            $recommendations[] = 'Fornisci una risposta diretta entro le prime 2 frasi';
        }
        
        if (!$checks['keyword_in_opening'] ?? false) {
            $recommendations[] = 'Includi la keyword principale nei primi 50 caratteri';
        }
        
        switch ($type) {
            case self::TYPE_PARAGRAPH:
                if (!($checks['optimal_paragraph'] ?? false)) {
                    $recommendations[] = 'Crea un paragrafo di definizione tra 100-250 caratteri';
                }
                break;
                
            case self::TYPE_LIST:
                if (!($checks['has_list'] ?? false)) {
                    $recommendations[] = 'Aggiungi una lista numerata o puntata con 3-8 item';
                }
                if (($checks['list_items_count'] ?? 0) > 8) {
                    $recommendations[] = 'Riduci la lista a massimo 8 item';
                }
                break;
                
            case self::TYPE_TABLE:
                if (!($checks['has_table'] ?? false)) {
                    $recommendations[] = 'Aggiungi una tabella comparativa';
                }
                break;
        }
        
        if (!($checks['has_faq'] ?? false)) {
            $recommendations[] = 'Aggiungi una sezione FAQ per aumentare le chances di snippet';
        }
        
        return $recommendations;
    }
    
    /**
     * Genera schema markup per featured snippet
     */
    public function generateSchemaMarkup(string $keyword, string $content, string $type): string
    {
        $schemas = [];
        
        // Article schema base
        $articleSchema = [
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => $keyword,
            'description' => $this->generateParagraphSnippet($keyword, $content)['snippet_text'] ?? '',
        ];
        $schemas[] = $articleSchema;
        
        // HowTo schema per liste di procedure
        if ($type === self::TYPE_LIST && preg_match('/come|passaggi|step/i', $keyword)) {
            $howToSchema = $this->generateHowToSchema($keyword, $content);
            if ($howToSchema) {
                $schemas[] = $howToSchema;
            }
        }
        
        // FAQPage schema
        if (strpos($content, 'faq-item') !== false || preg_match('/<h[23][^>]*>.*faq.*<\/h[23]>/i', $content)) {
            $faqSchema = $this->generateFAQSchema($content);
            if ($faqSchema) {
                $schemas[] = $faqSchema;
            }
        }
        
        // Genera output
        $output = '';
        foreach ($schemas as $schema) {
            $output .= '<script type="application/ld+json">' . json_encode($schema, JSON_UNESCAPED_UNICODE) . '</script>' . "\n";
        }
        
        return $output;
    }
    
    /**
     * Genera schema HowTo
     */
    private function generateHowToSchema(string $keyword, string $content): ?array
    {
        preg_match_all('/<li[^>]*>(.*?)<\/li>/is', $content, $matches);
        
        if (empty($matches[1])) {
            return null;
        }
        
        $steps = [];
        foreach (array_slice($matches[1], 0, 10) as $i => $step) {
            $text = strip_tags($step);
            $steps[] = [
                '@type' => 'HowToStep',
                'position' => $i + 1,
                'text' => $text,
                'name' => 'Passaggio ' . ($i + 1),
            ];
        }
        
        return [
            '@context' => 'https://schema.org',
            '@type' => 'HowTo',
            'name' => $keyword,
            'step' => $steps,
        ];
    }
    
    /**
     * Genera schema FAQPage
     */
    private function generateFAQSchema(string $content): ?array
    {
        $faqs = [];
        
        // Cerca pattern FAQ
        preg_match_all('/<h3[^>]*>(.*?)<\/h3>\s*<p[^>]*>(.*?)<\/p>/is', $content, $matches, PREG_SET_ORDER);
        
        foreach ($matches as $match) {
            $question = strip_tags($match[1]);
            $answer = strip_tags($match[2]);
            
            if (strlen($question) > 10 && strlen($answer) > 20) {
                $faqs[] = [
                    '@type' => 'Question',
                    'name' => $question,
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => $answer,
                    ],
                ];
            }
        }
        
        if (empty($faqs)) {
            return null;
        }
        
        return [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => $faqs,
        ];
    }
}
