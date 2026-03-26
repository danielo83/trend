# 🎯 Guida Completa per Massimizzare SEO e GEO

## Panoramica

Questa guida ti mostra come ottenere il **massimo punteggio** su SEO (90+/100) e GEO (85+/100) per ogni articolo generato.

---

## 📋 Checklist Giornaliera Operativa

### ✅ Fase 1: Pianificazione Contenuti (Mattina)

#### 1.1 Analizza Content Hub
```php
require_once 'src/ContentHubManager.php';

$hub = new ContentHubManager($config);
$suggestions = $hub->suggestNextArticle();

// Prendi il primo suggerimento con priorità CRITICAL o HIGH
$nextArticle = $suggestions[0];
echo "Prossimo articolo: {$nextArticle['keyword']}\n";
echo "Priorità: {$nextArticle['priority']}\n";
echo "Tipo: {$nextArticle['type']}\n";
```

**Azione:** Crea prima i pillar content mancanti, poi completa i cluster.

#### 1.2 Verifica Report SEO
- Controlla la tab "SEO Analytics" nel dashboard
- Identifica articoli "needs_attention"
- Prendi nota degli alert

### ✅ Fase 2: Generazione Contenuto (Metà mattina)

#### 2.1 Usa il Prompt Master
```php
require_once 'src/MaxSEOGEOConfig.php';

// Ottieni il prompt ottimizzato
$masterPrompt = MaxSEOGEOConfig::getMasterPrompt();

// Oppure ottieni la configurazione completa
$config = MaxSEOGEOConfig::getOptimalConfig();
```

#### 2.2 Configurazione Ottimale per Generazione
```php
$generator = new ContentGenerator(array_merge($config, [
    'min_quality_score' => 8,        // Minimo 8/10
    'schema_markup_enabled' => true,  // Sempre attivo
]));

$articolo = $generator->generate($keyword);
```

#### 2.3 Verifica Punteggi Post-Generazione
```php
// Dopo la generazione, verifica i punteggi
if ($articolo['seo_score'] < 90) {
    echo "⚠️ SEO Score basso: {$articolo['seo_score']}\n";
    print_r($articolo['seo_report']['suggestions']);
}

if ($articolo['geo_score'] < 85) {
    echo "⚠️ GEO Score basso: {$articolo['geo_score']}\n";
}

if ($articolo['snippet_potential'] < 70) {
    echo "⚠️ Snippet potential basso: {$articolo['snippet_potential']}\n";
}
```

### ✅ Fase 3: Ottimizzazione Link Building (Pomeriggio)

#### 3.1 Genera Mappa Link Interni
```php
require_once 'src/ContentHubManager.php';

$hub = new ContentHubManager($config);
$linkMap = $hub->generateInternalLinkMap($keyword, $topic);

// Link obbligatori (al pillar)
foreach ($linkMap['must_link_to'] as $link) {
    echo "DEVE linkare a: {$link['article']['title']}\n";
    echo "Anchor: {$link['anchor_suggestion']}\n";
}

// Link consigliati (altri cluster)
foreach ($linkMap['should_link_to'] as $link) {
    echo "DOVREBBE linkare a: {$link['article']['title']}\n";
}
```

#### 3.2 Usa Smart Link Building
```php
require_once 'src/SmartLinkBuilder.php';

$linkBuilder = new SmartLinkBuilder($config);
$opportunities = $linkBuilder->findLinkOpportunities($content, $keyword, 5);

foreach ($opportunities as $opp) {
    if ($opp['score'] > 0.6) {
        echo "Link consigliato: {$opp['post']['title']}\n";
        echo "Score: {$opp['score']}\n";
        echo "Anchor: {$opp['anchor_text']}\n";
    }
}
```

### ✅ Fase 4: Schema Markup Avanzato (Pre-pubblicazione)

#### 4.1 Genera Rich Results Schema
```php
require_once 'src/RichResultsGenerator.php';

$articleData = [
    'title' => $articolo['title'],
    'meta_description' => $articolo['meta_description'],
    'url' => $url,
    'published_at' => date('c'),
    'site_name' => 'Il Tuo Sito',
    'site_url' => 'https://www.example.com',
    'author_name' => 'Nome Autore',
    'category' => $category,
    'word_count' => str_word_count(strip_tags($articolo['body'])),
    'faqs' => $faqs, // Estratto dal contenuto
    'featured_image' => [
        'url' => $imageUrl,
        'width' => 1200,
        'height' => 630,
    ],
];

$schemaMarkup = RichResultsGenerator::generateFullMarkup($articleData);
$articolo['body'] .= "\n" . $schemaMarkup;
```

### ✅ Fase 5: Pre-Pubblicazione Checklist

#### 5.1 Verifica Finale
```php
require_once 'src/MaxSEOGEOConfig.php';

$checklist = MaxSEOGEOConfig::getPrePublishChecklist();
$targets = MaxSEOGEOConfig::getTargetMetrics();

// Verifica ogni elemento
foreach ($checklist['seo_technical'] as $check => $criteria) {
    echo "Verifica: $check\n";
    // Implementa verifica specifica
}
```

#### 5.2 Checklist Manuale Rapida
- [ ] Titolo: 50-60 caratteri, keyword iniziale
- [ ] Meta: 155-160 caratteri, call to action
- [ ] H1: unico, keyword presente
- [ ] H2: minimo 4, 2+ con keyword
- [ ] Parole: 1500-2000
- [ ] Lista numerata: presente
- [ ] Tabella: presente
- [ ] FAQ: 3-5 domande
- [ ] Link interni: 3-5
- [ ] Link esterni: 1-2 autorevoli
- [ ] Schema markup: valido

### ✅ Fase 6: Pubblicazione e Monitoraggio

#### 6.1 Pubblica e Traccia
```php
// La pubblicazione include già il tracking automatico
$wpResult = $wpPublisher->publish(
    $articolo['title'],
    $body,
    $imageUrl,
    $metaDescription,
    'publish',
    $category,
    $focusKeyword
);

// Registra nel Content Hub
$hub->registerArticle([
    'title' => $articolo['title'],
    'url' => $wpResult['post_url'],
    'keyword' => $keyword,
    'topic' => $topic,
    'type' => $articleType, // 'pillar' o 'cluster'
]);
```

#### 6.2 Monitora Performance
```php
require_once 'src/SEOMonitor.php';

$monitor = new SEOMonitor($config);
$monitor->trackArticle([
    'title' => $articolo['title'],
    'url' => $wpResult['post_url'],
    'keyword' => $keyword,
]);

// Dopo 7 giorni, verifica indicizzazione
// Dopo 30 giorni, verifica ranking
```

---

## 🎯 Metriche Target

| Metrica | Minimo | Target | Ottimale |
|---------|--------|--------|----------|
| SEO Score | 80 | 90 | 95+ |
| GEO Score | 75 | 85 | 90+ |
| Snippet Potential | 60 | 70 | 85+ |
| Quality Score | 7 | 8 | 9+ |
| Word Count | 1200 | 1800 | 2000+ |
| Keyword Density | 1% | 1.5% | 2% |
| Internal Links | 3 | 4 | 5 |
| Posizione Media | < 15 | < 10 | < 5 |

---

## 🔧 Configurazione Ottimale

### config.php / settings.json
```json
{
    "seo_optimization": {
        "enabled": true,
        "target_seo_score": 90,
        "target_geo_score": 85,
        "min_word_count": 1500,
        "max_word_count": 2500,
        "keyword_density": 1.5,
        "auto_optimize": true,
        "featured_snippets": true
    },
    "content_hub": {
        "enabled": true,
        "prioritize_pillars": true,
        "min_cluster_articles": 5,
        "max_cluster_articles": 15
    },
    "link_building": {
        "smart_mode": true,
        "semantic_analysis": true,
        "max_links_per_article": 5,
        "anchor_text_variants": true
    },
    "monitoring": {
        "enabled": true,
        "check_interval_hours": 24,
        "alert_threshold_position": 10,
        "track_featured_snippets": true
    }
}
```

---

## 📊 Dashboard SEO - Cosa Monitorare

### Statistiche Principali
1. **Coverage Score**: % topic coperti
2. **Avg Position**: Posizione media
3. **Featured Snippets**: Quanti hai conquistato
4. **Traffic Estimate**: Traffico organico stimato

### Alert da Gestire
- 🔴 **Snippet perso**: Aggiorna contenuto immediatamente
- 🔴 **Traffic drop >30%**: Verifica ranking
- 🟡 **Posizione >10**: Ottimizza contenuto
- 🟡 **Non indicizzato**: Controlla Search Console

---

## 🚀 Strategia Content Hub

### Fase 1: Pillar Content (Settimane 1-4)
Crea 4 pillar content (uno per topic):
1. "Guida Completa all'Interpretazione dei Sogni"
2. "Psicologia dei Sogni: La Scienza dell'Inconscio"
3. "Come Dormire Meglio: La Guida Definitiva"
4. "Smorfia Napoletana: Storia e Significato"

### Fase 2: Cluster Content (Settimane 5-12)
Per ogni pillar, crea 8-10 articoli cluster:
- Sogni → sognare gatti, sognare acqua, sognare volare...
- Psicologia → sogni e ansia, incubi, sogni lucidi...
- Sonno → insonnia, posizioni, routine...
- Smorfia → numeri, simboli, storia...

### Fase 3: Link Building Interno (Continuo)
- Ogni cluster linka al suo pillar
- Pillar linka ai cluster più importanti
- Cross-linking tra topic correlati

---

## 🎓 Best Practices

### Per SEO Massimo
1. **Keyword nel titolo**: entro prime 3 parole
2. **Primo paragrafo**: risposta diretta entro 80 parole
3. **Struttura H2/H3**: gerarchia chiara
4. **Liste numerate**: per procedure (HowTo)
5. **Tabelle**: per confronti
6. **FAQ**: minimo 3 domande specifiche

### Per GEO Massimo
1. **Paragrafi brevi**: max 4 frasi
2. **Frasi corte**: media < 20 parole
3. **Definizioni chiare**: in grassetto
4. **Entità**: menziona 5+ concetti correlati
5. **Transizioni**: 15%+ parole di collegamento
6. **Formato scansionabile**: liste, bullet point

### Per Featured Snippets
1. **Definizione**: 40-60 caratteri, formato "X è Y"
2. **Procedure**: 5-7 passaggi numerati
3. **Tabelle**: 3-5 righe, confronto chiaro
4. **FAQ**: domande specifiche, risposte concise

---

## ⚡ Ottimizzazioni Avanzate

### 1. Ottimizza Prompt per AI
```php
// Usa il prompt master
$prompt = MaxSEOGEOConfig::getMasterPrompt();

// Personalizza per keyword specifica
$customPrompt = str_replace('[keyword]', $keyword, $prompt);
```

### 2. A/B Testing Titoli
```php
// Genera 3 varianti di titolo
$titles = [];
for ($i = 0; $i < 3; $i++) {
    $titles[] = $generator->generateTitle($keyword, $provider);
}

// Scegli quello con miglior SEO score
$bestTitle = '';
$bestScore = 0;
foreach ($titles as $title) {
    $score = $seoOptimizer->analyzeTitle($title, $keyword);
    if ($score > $bestScore) {
        $bestScore = $score;
        $bestTitle = $title;
    }
}
```

### 3. Aggiornamento Contenuti
```php
// Identifica articoli da aggiornare
$report = $analytics->generateReport();
foreach ($report['underperforming'] as $article) {
    // Riscrivi contenuto mantenendo URL
    // Aggiorna data di modifica
    // Aggiungi nuove FAQ
}
```

---

## 📈 KPI da Raggiungere (3 Mesi)

- [ ] **100+ articoli** pubblicati
- [ ] **4 pillar content** completi
- [ ] **80%+ coverage** topic cluster
- [ ] **Posizione media < 10**
- [ ] **10+ featured snippets**
- [ ] **5000+ visite/mese** organiche

---

## 🔗 Risorse Utili

1. **Google Search Console**: monitora indicizzazione
2. **Google Rich Results Test**: valida schema markup
3. **PageSpeed Insights**: ottimizza performance
4. **Mobile-Friendly Test**: verifica responsive

---

## ✅ Checklist Finale Pre-Pubblicazione

Copia questa checklist per ogni articolo:

```
□ Titolo: 50-60 char, keyword iniziale
□ Meta: 155-160 char, CTA presente
□ H1: unico, keyword presente
□ H2: 4+, 2 con keyword
□ Intro: risposta diretta < 80 parole
□ Contenuto: 1500-2000 parole
□ Keyword density: 1-2%
□ Lista numerata: per procedure
□ Tabella: per confronti
□ FAQ: 3-5 domande
□ Link interni: 3-5 distribuiti
□ Link esterni: 1-2 autorevoli
□ Immagini: alt text ottimizzato
□ Schema markup: validato
□ Mobile friendly: testato
□ SEO Score: 90+
□ GEO Score: 85+
□ Snippet Potential: 70+
```

---

**Ricorda:** La qualità batte la quantità. Meglio 10 articoli perfetti che 100 mediocri.
