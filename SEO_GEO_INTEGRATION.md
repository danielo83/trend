# Guida all'Integrazione SEO e GEO

Questa guida spiega come integrare i nuovi strumenti SEO e GEO nel sistema di generazione contenuti.

## Nuovi Componenti

### 1. SEOOptimizer (`src/SEOOptimizer.php`)
Analizza e ottimizza i contenuti per SEO tradizionale.

**Funzionalità:**
- Analisi completa dell'articolo (titolo, meta, contenuto, headings)
- Calcolo punteggi SEO, GEO, leggibilità, tecnico
- Suggerimenti di miglioramento
- Validazione keyword density, struttura headings, link

**Uso:**
```php
require_once 'src/SEOOptimizer.php';

$optimizer = new SEOOptimizer([
    'min_word_count' => 800,
    'target_keyword_density' => 1.5,
]);

$report = $optimizer->analyzeArticle(
    $title,
    $content,
    $metaDescription,
    $targetKeyword
);

echo "Punteggio SEO: " . $report['seo_score'] . "/100\n";
echo "Punteggio GEO: " . $report['geo_score'] . "/100\n";

foreach ($report['suggestions'] as $suggestion) {
    echo "- $suggestion\n";
}
```

### 2. SmartLinkBuilder (`src/SmartLinkBuilder.php`)
Estende LinkBuilder con link building intelligente e semantico.

**Funzionalità:**
- Analisi semantica per trovare link pertinenti
- Suggerimento anchor text ottimizzato
- Topic clusters automatici
- Link juice distribution

**Uso:**
```php
require_once 'src/SmartLinkBuilder.php';

$linkBuilder = new SmartLinkBuilder($config);

// Trova opportunità di link
$opportunities = $linkBuilder->findLinkOpportunities($content, $keyword, 5);

foreach ($opportunities as $opp) {
    echo "Link a: {$opp['post']['title']}\n";
    echo "Score: {$opp['score']}\n";
    echo "Anchor suggerita: {$opp['anchor_text']}\n";
}

// Build topic clusters
$clusters = $linkBuilder->buildTopicClusters();
```

### 3. FeaturedSnippetOptimizer (`src/FeaturedSnippetOptimizer.php`)
Ottimizza i contenuti per conquistare Featured Snippets.

**Funzionalità:**
- Analisi query per determinare tipo di snippet
- Generazione contenuto ottimizzato per snippet
- Schema markup per HowTo e FAQ
- Valutazione potenziale snippet

**Uso:**
```php
require_once 'src/FeaturedSnippetOptimizer.php';

$snippetOptimizer = new FeaturedSnippetOptimizer();

// Analizza keyword
$analysis = $snippetOptimizer->analyzeQuery($keyword);
echo "Tipo snippet previsto: {$analysis['snippet_type']}\n";

// Genera istruzioni per AI
$instructions = $snippetOptimizer->generatePromptInstructions($keyword);

// Valuta contenuto esistente
$evaluation = $snippetOptimizer->evaluateSnippetPotential($content, $keyword);
echo "Potenziale snippet: {$evaluation['potential_score']}/100\n";

// Genera schema markup
$schema = $snippetOptimizer->generateSchemaMarkup($keyword, $content, $analysis['snippet_type']);
```

### 4. ContentAnalytics (`src/ContentAnalytics.php`)
Monitora le performance dei contenuti.

**Funzionalità:**
- Tracking articoli e metriche
- Stima traffico organico
- Report performance
- Identificazione contenuti underperforming

**Uso:**
```php
require_once 'src/ContentAnalytics.php';

$analytics = new ContentAnalytics($config);

// Traccia nuovo articolo
$analytics->trackArticle([
    'title' => $title,
    'url' => $url,
    'keyword' => $keyword,
    'wordpress_post_id' => $postId,
]);

// Genera report
$report = $analytics->generateReport();
echo "Articoli totali: {$report['summary']['total_articles']}\n";
echo "Indicizzati: {$report['summary']['indexed']}\n";
echo "Featured snippets: {$report['summary']['with_featured_snippet']}\n";
```

## Integrazione nel Flusso di Generazione

### Modifica ContentGenerator.php

Aggiungi l'analisi SEO dopo la generazione:

```php
public function generate(string $topic): ?array
{
    // ... codice esistente ...
    
    if ($result !== null) {
        // Analisi SEO
        $optimizer = new SEOOptimizer($this->config);
        $seoReport = $optimizer->analyzeArticle(
            $result['title'],
            $result['body'],
            $result['meta_description'] ?? '',
            $topic
        );
        
        $result['seo_score'] = $seoReport['overall_score'];
        $result['seo_report'] = $seoReport;
        
        // Ottimizzazione Featured Snippet
        $snippetOptimizer = new FeaturedSnippetOptimizer();
        $snippetInstructions = $snippetOptimizer->generatePromptInstructions($topic);
        
        // Aggiungi schema markup
        $snippetType = $snippetOptimizer->analyzeQuery($topic)['snippet_type'];
        $schemaMarkup = $snippetOptimizer->generateSchemaMarkup($topic, $result['body'], $snippetType);
        
        if (!empty($schemaMarkup)) {
            $result['body'] .= "\n" . $schemaMarkup;
        }
        
        // ... resto del codice ...
    }
}
```

### Modifica del Prompt Template

Aggiungi istruzioni SEO/GEO al prompt:

```php
public static function defaultPrompt(): string
{
    $basePrompt = '... prompt esistente ...';
    
    // Aggiungi ottimizzazioni
    $seoGuidelines = SEOOptimizer::generateOptimizedPrompt($basePrompt, '[keyword]);
    
    // Aggiungi featured snippet
    $snippetOptimizer = new FeaturedSnippetOptimizer();
    $snippetInstructions = $snippetOptimizer->generatePromptInstructions('[keyword]');
    
    return $basePrompt . $seoGuidelines . $snippetInstructions;
}
```

### Integrazione Smart Link Building

Sostituisci LinkBuilder con SmartLinkBuilder:

```php
// Invece di:
$linkBuilder = new LinkBuilder($config);

// Usa:
$linkBuilder = new SmartLinkBuilder($config);

// Ottieni contesto avanzato
$linkContext = $linkBuilder->buildAdvancedPromptContext($topic, $content);
```

## Dashboard - Nuove Funzionalità

Aggiungi una tab "SEO Analysis" nel dashboard:

```php
// Nuova sezione nel dashboard
if ($action === 'seo_analyze') {
    $articleIndex = intval($_POST['article_index'] ?? -1);
    $items = $feedBuilder->getItems();
    
    if ($articleIndex >= 0 && $articleIndex < count($items)) {
        $item = $items[$articleIndex];
        
        $optimizer = new SEOOptimizer($config);
        $report = $optimizer->analyzeArticle(
            $item['title'],
            $item['content'],
            $item['meta_description'] ?? '',
            strip_tags($item['title'])
        );
        
        echo json_encode([
            'success' => true,
            'report' => $report
        ]);
    }
}
```

## Configurazione Consigliata

Aggiungi al `config.php` o `settings.json`:

```json
{
    "seo_optimization": {
        "enabled": true,
        "min_seo_score": 70,
        "auto_optimize": true,
        "featured_snippets": true,
        "topic_clusters": true
    },
    "analytics": {
        "enabled": true,
        "track_indexing": true,
        "track_rankings": true,
        "report_interval_days": 7
    },
    "link_building": {
        "smart_mode": true,
        "semantic_analysis": true,
        "max_links_per_article": 5,
        "anchor_text_variants": true
    }
}
```

## Checklist Ottimizzazione

Per ogni articolo generato:

- [ ] Titolo: 50-60 caratteri, keyword all'inizio
- [ ] Meta description: 150-160 caratteri, call to action
- [ ] H1 unico, H2 con keyword, struttura gerarchica
- [ ] Primo paragrafo: risposta diretta entro 100 parole
- [ ] Contenuto: 1200-1800 parole, densità keyword 1-2%
- [ ] Lista o tabella per featured snippet
- [ ] Sezione FAQ con 3+ domande
- [ ] 2-5 link interni con anchor text descrittivo
- [ ] Schema markup (Article + FAQ/HowTo)
- [ ] Immagini con alt text

## Metriche da Monitorare

- **SEO Score**: Target > 70/100
- **GEO Score**: Target > 60/100
- **Indicizzazione**: Target 100% entro 14 giorni
- **Featured Snippets**: Target 10% degli articoli
- **Posizione media**: Target < 10
- **Traffico stimato**: Crescita mensile > 20%

## Prossimi Passi

1. Implementare l'integrazione nel ContentGenerator
2. Aggiungere la tab SEO nel dashboard
3. Configurare il tracking analytics
4. Testare con articoli di esempio
5. Ottimizzare i prompt in base ai risultati
