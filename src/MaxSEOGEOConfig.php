<?php

/**
 * MaxSEOGEOConfig - Configurazione per massimizzare risultati SEO e GEO
 * 
 * Questa classe fornisce configurazioni ottimali e prompt avanzati
 * per ottenere il massimo punteggio su entrambi i fronti.
 */
class MaxSEOGEOConfig
{
    /**
     * Configurazione ottimale per SEO/GEO
     */
    public static function getOptimalConfig(): array
    {
        return [
            // SEO Settings
            'seo' => [
                'target_score' => 90,
                'min_word_count' => 1500,
                'max_word_count' => 2500,
                'keyword_density' => 1.5, // 1-2% ideale
                'min_headings' => 5,
                'internal_links_per_article' => 4,
                'external_links_per_article' => 2,
            ],
            
            // GEO Settings
            'geo' => [
                'target_score' => 85,
                'answer_directness' => 'high', // Risposta diretta entro 50 parole
                'structured_format' => true,
                'entity_density' => 'high',
                'faq_required' => true,
            ],
            
            // Content Quality
            'quality' => [
                'min_score' => 8,
                'readability_target' => 75, // Flesch Reading Ease
                'sentence_max_length' => 20,
                'paragraph_max_sentences' => 4,
            ],
            
            // Featured Snippets
            'snippets' => [
                'target_all_types' => true,
                'definition_length' => [40, 60],
                'list_items' => [5, 8],
                'table_rows' => [3, 5],
            ],
        ];
    }
    
    /**
     * Prompt MASTER per generazione contenuti MAX SEO/GEO
     */
    public static function getMasterPrompt(): string
    {
        return <<<'PROMPT'
# 🎯 MASTER PROMPT - MASSIMIZZAZIONE SEO E GEO

## OBIETTIVO
Crea un articolo che raggiunga il punteggio MASSIMO su:
- SEO (Search Engine Optimization): target 90+/100
- GEO (Generative Engine Optimization): target 85+/100
- Featured Snippet: conquista posizione zero
- Google Discover: alto engagement

---

## 📋 STRUTTURA OBBLIGATORIA

### 1. TITOLO (H1) - Ottimizzazione Estrema
```
- Lunghezza: 50-60 caratteri (non più di 70)
- Keyword principale entro i primi 3-4 parole
- Power words: "Guida", "Definitiva", "Completa", "2024"
- Numero specifico quando rilevante
- Curiosità o promessa di valore
- Formato: "Keyword: [Beneficio/Valore Unico]"
```

### 2. META DESCRIPTION (155-160 caratteri)
```
- Inizia con verbo d'azione: "Scopri", "Impara", "Trova"
- Include keyword principale entro 10 parole
- Promise specifico beneficio
- Call to action finale
- Non usare virgolette interne
```

### 3. INTRODUZIONE - Risposta Diretta (GEO)
```
PRIMO PARAGRAFO (50-80 parole):
- Risposta diretta e completa alla query
- Definizione chiara se richiesta
- Keyword nei primi 100 caratteri
- Hook emotivo o dati sorprendenti

SECONDO PARAGRAFO (60-80 parole):
- Contestualizzazione del problema
- Perché l'argomento è importante ORA
- Preview di cosa imparerà il lettore
```

### 4. STRUTTURA CORPO ARTICOLO

#### SEZIONE A: Approfondimento (H2)
```
<h2>[Keyword]: cosa devi sapere</h2>
- 2-3 paragrafi di 80-100 parole ciascuno
- Keyword nel primo H2
- Entità semantiche correlate
- Dati/fatti specifici
- Citazioni implicite (studiosi, ricerche)
```

#### SEZIONE B: Lista/Procedura (H2 + H3)
```
<h2>Come [azione correlata a keyword]</h2>
<p>Introduzione alla procedura (40-60 parole)</p>

<h3>1. [Primo passo specifico]</h3>
<p>3-4 frasi con dettagli pratici</p>

<h3>2. [Secondo passo specifico]</h3>
<p>3-4 frasi con dettagli pratici</p>

[... minimo 5 passaggi numerati]
```

#### SEZIONE C: Prospetto Comparativo (H2 + Tabella)
```
<h2>[Keyword]: confronto tra approcci</h2>
<table>
<thead>
<tr><th>Caratteristica</th><th>Approccio A</th><th>Approccio B</th></tr>
</thead>
<tbody>
<tr><td>[Aspetto 1]</td><td>[Valore]</td><td>[Valore]</td></tr>
<tr><td>[Aspetto 2]</td><td>[Valore]</td><td>[Valore]</td></tr>
<tr><td>[Aspetto 3]</td><td>[Valore]</td><td>[Valore]</td></tr>
</tbody>
</table>
```

#### SEZIONE D: FAQ (H2 + Domande)
```
<h2>Domande frequenti su [keyword]</h2>

<div class="faq-item">
<h3>[Domanda 1 specifica e long-tail]?</h3>
<p>[Risposta concisa 2-3 frasi]</p>
</div>

<div class="faq-item">
<h3>[Domanda 2 specifica e long-tail]?</h3>
<p>[Risposta concisa 2-3 frasi]</p>
</div>

[... minimo 3 domande, massimo 5]
```

### 5. CONCLUSIONE (H2)
```
<h2>Conclusione</h2>
- Riassunto dei punti chiave (NO "in conclusione")
- Takeaway actionabile
- Apertura a domande/interazione
- Link interno consigliato (se contesto lo permette)
```

---

## 🔍 SPECIFICHE SEO TECNICHE

### Keyword Placement (OBBLIGATORIO)
- [ ] Titolo (H1): keyword entro le prime 3 parole
- [ ] Meta description: keyword entro 10 parole
- [ ] Primo paragrafo: keyword entro 100 caratteri
- [ ] Almeno 2 H2: includono keyword o variante
- [ ] Ultimo paragrafo: keyword naturale
- [ ] Alt text immagini: keyword se rilevante

### Densità Keyword
- Keyword principale: 1.5% (15-20 volte in 1500 parole)
- Keyword secondarie: 0.5% ciascuna (5-8 volte)
- Sinonimi e LSI: distribuiti naturalmente

### Link Building Interno
- Minimo 3 link interni ad articoli correlati
- Anchor text descrittivo (3-6 parole)
- Mai "clicca qui" o "leggi qui"
- Link nel primo terzo dell'articolo

### Link Esterni
- 1-2 link a fonti autorevoli (.edu, .gov, siti medici/psicologici)
- Target="_blank" e rel="noopener"
- Anchor text descrittivo

---

## 🤖 SPECIFICHE GEO (AI-READY)

### Formattazione per AI
- Paragrafi: max 4 frasi
- Frasi: max 20 parole
- Liste: sempre quando possibile
- Tabelle: per confronti numerici
- Definizioni: box separati o in grassetto

### Entità e Contesto
- Menziona almeno 5 entità correlate (nomi, concetti, luoghi)
- Usa 10+ termini semanticamente correlati
- Collegamenti logici espliciti tra concetti
- Contesto storico/culturale quando rilevante

### Struttura Scansionabile
- Ogni H2 deve essere auto-esplicativo
- Liste numerate per procedure
- Liste puntate per caratteristiche
- Grassetto per concetti chiave
- Citazioni in formato chiaro

---

## ⭐ SPECIFICHE FEATURED SNIPPET

### Per Query "Cosa è" / Definizione
```
<p><strong>[Keyword]</strong> è [definizione in 25-40 parole]. 
[Contesto aggiuntivo in 20-30 parole].</p>
```

### Per Query "Come" / Procedura
```
<ol>
<li><strong>[Azione]</strong>: [Spiegazione breve]</li>
[... 5-7 passaggi]
</ol>
```

### Per Query "Perché" / Cause
```
<ul>
<li><strong>[Causa 1]</strong>: [Spiegazione]</li>
<li><strong>[Causa 2]</strong>: [Spiegazione]</li>
[... 3-5 cause]
</ul>
```

---

## 📝 REgole di Scrittura

### Tono e Stile
- Discorsivo ma autorevole
- Seconda persona singolare (tu)
- Frasi attive (90%+)
- Transizioni fluide tra paragrafi
- Nessun gergo non spiegato

### Proibito
- "In conclusione", "in sintesi", "in questo articolo"
- Emoji
- Markdown (#, **, -)
- Paragrafi > 150 parole
- Frasi > 25 parole
- Voce passiva eccessiva

### Obbligatorio
- SOLO tag HTML: h1, h2, h3, p, strong, ul, ol, li, table, thead, tbody, tr, td, th
- Ogni paragrafo in <p></p>
- Titoli in <h1>, <h2>, <h3>
- Keyword in <strong> alla prima occorrenza

---

## 📊 SCHEMA MARKUP RICHIESTO

Includi nel body:
```html
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "Article",
  "headline": "[Titolo]",
  "description": "[Meta description]",
  "datePublished": "[DATA]",
  "author": {"@type": "Organization", "name": "[SITO]"}
}
</script>

<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "FAQPage",
  "mainEntity": [
    {
      "@type": "Question",
      "name": "[Domanda 1]",
      "acceptedAnswer": {
        "@type": "Answer",
        "text": "[Risposta 1]"
      }
    }
    [...]
  ]
}
</script>
```

---

## ✅ CHECKLIST FINALE

Prima di restituire il JSON, verifica:

- [ ] Titolo: 50-60 caratteri, keyword all'inizio
- [ ] Meta description: 155-160 caratteri, call to action
- [ ] Primo paragrafo: risposta diretta entro 80 parole
- [ ] Lunghezza totale: 1500-2000 parole
- [ ] H2: minimo 4, almeno 2 con keyword
- [ ] Lista numerata: presente per procedure
- [ ] Tabella: presente per confronti
- [ ] FAQ: 3-5 domande con risposte concise
- [ ] Link interni: 3-5 distribuiti nel testo
- [ ] Link esterni: 1-2 a fonti autorevoli
- [ ] Schema markup: Article + FAQPage
- [ ] Densità keyword: ~1.5%
- [ ] Frasi corte: media < 20 parole
- [ ] Paragrafi brevi: max 4 frasi

---

## 📤 FORMATO OUTPUT

Rispondi SOLO con questo JSON:
```json
{
  "title": "Titolo ottimizzato 50-60 char",
  "meta_description": "Meta 155-160 char con CTA",
  "body": "HTML completo con tutte le sezioni richieste"
}
```

NIENTE ALTRO FUORI DAL JSON.
PROMPT;
    }
    
    /**
     * Strategia keyword per massima copertura
     */
    public static function getKeywordStrategy(): array
    {
        return [
            'primary_keyword' => [
                'placement' => [
                    'title_first_3_words' => true,
                    'meta_first_10_words' => true,
                    'h1_once' => true,
                    'h2_at_least_2' => true,
                    'first_paragraph_first_100_chars' => true,
                    'last_paragraph' => true,
                    'body_natural_distribution' => true,
                ],
                'density' => 1.5, // percentuale
                'variants' => [
                    'sostantivo' => true,
                    'verbo' => true,
                    'aggettivale' => true,
                ],
            ],
            
            'secondary_keywords' => [
                'count' => 3,
                'density_each' => 0.5,
                'placement' => [
                    'h2_h3' => true,
                    'body_natural' => true,
                ],
            ],
            
            'lsi_keywords' => [
                'count' => 10,
                'source' => 'semantic_related_terms',
                'placement' => 'natural_throughout',
            ],
            
            'long_tail' => [
                'faq_questions' => true,
                'h3_subsections' => true,
                'target_voice_search' => true,
            ],
        ];
    }
    
    /**
     * Schema markup avanzato per rich results
     */
    public static function getAdvancedSchema(string $type, array $data): array
    {
        $schemas = [];
        
        // Article base
        $schemas[] = [
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => $data['title'],
            'description' => $data['meta_description'],
            'datePublished' => $data['published_at'],
            'dateModified' => $data['updated_at'] ?? $data['published_at'],
            'author' => [
                '@type' => 'Organization',
                'name' => $data['site_name'],
                'url' => $data['site_url'],
            ],
            'publisher' => [
                '@type' => 'Organization',
                'name' => $data['site_name'],
                'logo' => [
                    '@type' => 'ImageObject',
                    'url' => $data['logo_url'] ?? '',
                ],
            ],
            'mainEntityOfPage' => [
                '@type' => 'WebPage',
                '@id' => $data['url'],
            ],
        ];
        
        // FAQPage
        if (!empty($data['faqs'])) {
            $faqEntities = [];
            foreach ($data['faqs'] as $faq) {
                $faqEntities[] = [
                    '@type' => 'Question',
                    'name' => $faq['question'],
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => $faq['answer'],
                    ],
                ];
            }
            
            $schemas[] = [
                '@context' => 'https://schema.org',
                '@type' => 'FAQPage',
                'mainEntity' => $faqEntities,
            ];
        }
        
        // HowTo per procedure
        if ($type === 'howto' && !empty($data['steps'])) {
            $steps = [];
            foreach ($data['steps'] as $i => $step) {
                $steps[] = [
                    '@type' => 'HowToStep',
                    'position' => $i + 1,
                    'name' => $step['name'],
                    'text' => $step['text'],
                    'url' => $data['url'] . '#step-' . ($i + 1),
                ];
            }
            
            $schemas[] = [
                '@context' => 'https://schema.org',
                '@type' => 'HowTo',
                'name' => $data['title'],
                'description' => $data['meta_description'],
                'totalTime' => $data['duration'] ?? 'PT30M',
                'step' => $steps,
            ];
        }
        
        // BreadcrumbList
        $schemas[] = [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                [
                    '@type' => 'ListItem',
                    'position' => 1,
                    'name' => 'Home',
                    'item' => $data['site_url'],
                ],
                [
                    '@type' => 'ListItem',
                    'position' => 2,
                    'name' => $data['category'] ?? 'Articoli',
                    'item' => $data['site_url'] . '/category/',
                ],
                [
                    '@type' => 'ListItem',
                    'position' => 3,
                    'name' => $data['title'],
                    'item' => $data['url'],
                ],
            ],
        ];
        
        return $schemas;
    }
    
    /**
     * Checklist pre-pubblicazione MAX SEO/GEO
     */
    public static function getPrePublishChecklist(): array
    {
        return [
            'seo_technical' => [
                'title_length' => ['min' => 50, 'max' => 60],
                'meta_description_length' => ['min' => 155, 'max' => 160],
                'h1_unique' => true,
                'h2_minimum' => 4,
                'h3_minimum' => 3,
                'word_count' => ['min' => 1500, 'max' => 2500],
                'keyword_density' => ['min' => 1.0, 'max' => 2.5],
                'internal_links' => ['min' => 3, 'max' => 5],
                'external_links' => ['min' => 1, 'max' => 2],
                'images_with_alt' => true,
                'schema_markup_present' => true,
            ],
            
            'geo_optimization' => [
                'direct_answer_first_100' => true,
                'structured_lists_present' => true,
                'faq_section_present' => true,
                'entities_mentioned' => ['min' => 5],
                'short_paragraphs' => true,
                'transition_words_ratio' => ['min' => 15],
            ],
            
            'featured_snippet' => [
                'definition_format' => true,
                'numbered_list_for_procedure' => true,
                'table_for_comparison' => true,
                'faq_format' => true,
            ],
            
            'content_quality' => [
                'no_grammar_errors' => true,
                'no_spelling_errors' => true,
                'active_voice_dominant' => true,
                'engaging_hook' => true,
                'clear_cta' => true,
            ],
        ];
    }
    
    /**
     * Metriche target per massimi risultati
     */
    public static function getTargetMetrics(): array
    {
        return [
            'seo_score' => ['min' => 90, 'target' => 95],
            'geo_score' => ['min' => 85, 'target' => 90],
            'readability_score' => ['min' => 70, 'target' => 80],
            'technical_score' => ['min' => 90, 'target' => 95],
            'snippet_potential' => ['min' => 70, 'target' => 85],
            'quality_score' => ['min' => 8, 'target' => 9],
        ];
    }
}
