<?php

/**
 * RichResultsGenerator - Generatore Schema Markup per Google Rich Results
 * 
 * Crea markup avanzato per:
 * - Article (ampio)
 * - FAQPage
 * - HowTo
 * - BreadcrumbList
 * - Speakable (per voice search)
 * - VideoObject (se presenti video)
 * - ImageObject (per immagini)
 */
class RichResultsGenerator
{
    /**
     * Genera tutti gli schemi necessari per un articolo
     */
    public static function generateFullMarkup(array $articleData): string
    {
        $schemas = [];
        
        // 1. Article avanzato
        $schemas[] = self::generateArticleSchema($articleData);
        
        // 2. FAQPage se presenti FAQ
        if (!empty($articleData['faqs'])) {
            $schemas[] = self::generateFAQSchema($articleData['faqs']);
        }
        
        // 3. HowTo se presenti passaggi
        if (!empty($articleData['steps'])) {
            $schemas[] = self::generateHowToSchema($articleData);
        }
        
        // 4. BreadcrumbList
        $schemas[] = self::generateBreadcrumbSchema($articleData);
        
        // 5. Speakable per voice search
        $schemas[] = self::generateSpeakableSchema($articleData);
        
        // 6. VideoObject se presente video
        if (!empty($articleData['video'])) {
            $schemas[] = self::generateVideoSchema($articleData['video']);
        }
        
        // 7. ImageObject per immagine principale
        if (!empty($articleData['featured_image'])) {
            $schemas[] = self::generateImageSchema($articleData['featured_image'], $articleData);
        }
        
        // 8. WebSite schema (solo per homepage, ma utile)
        if (!empty($articleData['include_website_schema'])) {
            $schemas[] = self::generateWebSiteSchema($articleData);
        }
        
        // Genera output HTML
        $output = '';
        foreach ($schemas as $schema) {
            if ($schema !== null) {
                $output .= '<script type="application/ld+json">' . 
                          json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . 
                          '</script>' . "\n";
            }
        }
        
        return $output;
    }
    
    /**
     * Schema Article avanzato
     */
    private static function generateArticleSchema(array $data): array
    {
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            '@id' => $data['url'] . '#article',
            'headline' => self::truncate($data['title'], 110),
            'description' => $data['meta_description'] ?? '',
            'url' => $data['url'],
            'datePublished' => $data['published_at'],
            'dateModified' => $data['updated_at'] ?? $data['published_at'],
            'author' => [
                '@type' => ($data['author_type'] ?? 'Organization'),
                'name' => $data['author_name'] ?? $data['site_name'],
                'url' => $data['author_url'] ?? $data['site_url'],
            ],
            'publisher' => [
                '@type' => 'Organization',
                'name' => $data['site_name'],
                'url' => $data['site_url'],
                'logo' => [
                    '@type' => 'ImageObject',
                    'url' => $data['logo_url'] ?? ($data['site_url'] . '/logo.png'),
                    'width' => 600,
                    'height' => 60,
                ],
            ],
            'mainEntityOfPage' => [
                '@type' => 'WebPage',
                '@id' => $data['url'],
            ],
        ];
        
        // Aggiungi immagine se presente
        if (!empty($data['featured_image'])) {
            $schema['image'] = [
                '@type' => 'ImageObject',
                'url' => $data['featured_image']['url'],
                'width' => $data['featured_image']['width'] ?? 1200,
                'height' => $data['featured_image']['height'] ?? 630,
                'caption' => $data['featured_image']['caption'] ?? $data['title'],
            ];
        }
        
        // Aggiungi word count
        if (!empty($data['word_count'])) {
            $schema['wordCount'] = $data['word_count'];
        }
        
        // Aggiungi keywords
        if (!empty($data['keywords'])) {
            $schema['keywords'] = is_array($data['keywords']) 
                ? implode(', ', $data['keywords']) 
                : $data['keywords'];
        }
        
        // Aggiungi article section
        if (!empty($data['category'])) {
            $schema['articleSection'] = $data['category'];
        }
        
        // Aggiungi speakable
        $schema['speakable'] = [
            '@type' => 'SpeakableSpecification',
            'cssSelector' => ['.article-headline', '.article-summary'],
        ];
        
        return $schema;
    }
    
    /**
     * Schema FAQPage
     */
    private static function generateFAQSchema(array $faqs): array
    {
        $mainEntity = [];
        
        foreach ($faqs as $faq) {
            $mainEntity[] = [
                '@type' => 'Question',
                'name' => $faq['question'],
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => $faq['answer'],
                ],
            ];
        }
        
        return [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => $mainEntity,
        ];
    }
    
    /**
     * Schema HowTo
     */
    private static function generateHowToSchema(array $data): array
    {
        $steps = [];
        foreach ($data['steps'] as $i => $step) {
            $stepData = [
                '@type' => 'HowToStep',
                'position' => $i + 1,
                'name' => $step['name'] ?? ('Passaggio ' . ($i + 1)),
                'text' => $step['text'],
                'url' => $data['url'] . '#step-' . ($i + 1),
            ];
            
            if (!empty($step['image'])) {
                $stepData['image'] = [
                    '@type' => 'ImageObject',
                    'url' => $step['image'],
                ];
            }
            
            $steps[] = $stepData;
        }
        
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'HowTo',
            'name' => $data['title'],
            'description' => $data['meta_description'] ?? '',
            'totalTime' => $data['duration'] ?? 'PT30M',
            'estimatedCost' => [
                '@type' => 'MonetaryAmount',
                'currency' => 'EUR',
                'value' => '0',
            ],
            'step' => $steps,
        ];
        
        // Aggiungi supply/tools se presenti
        if (!empty($data['tools'])) {
            $schema['tool'] = $data['tools'];
        }
        
        if (!empty($data['supplies'])) {
            $schema['supply'] = $data['supplies'];
        }
        
        return $schema;
    }
    
    /**
     * Schema BreadcrumbList
     */
    private static function generateBreadcrumbSchema(array $data): array
    {
        $items = [
            [
                '@type' => 'ListItem',
                'position' => 1,
                'name' => 'Home',
                'item' => $data['site_url'],
            ],
        ];
        
        if (!empty($data['category'])) {
            $items[] = [
                '@type' => 'ListItem',
                'position' => 2,
                'name' => $data['category'],
                'item' => $data['site_url'] . '/category/' . self::slugify($data['category']) . '/',
            ];
            
            $items[] = [
                '@type' => 'ListItem',
                'position' => 3,
                'name' => self::truncate($data['title'], 50),
                'item' => $data['url'],
            ];
        } else {
            $items[] = [
                '@type' => 'ListItem',
                'position' => 2,
                'name' => self::truncate($data['title'], 50),
                'item' => $data['url'],
            ];
        }
        
        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $items,
        ];
    }
    
    /**
     * Schema Speakable per Voice Search
     */
    private static function generateSpeakableSchema(array $data): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'WebPage',
            '@id' => $data['url'],
            'speakable' => [
                [
                    '@type' => 'SpeakableSpecification',
                    'cssSelector' => ['h1', '.lead', '.summary'],
                ],
                [
                    '@type' => 'SpeakableSpecification',
                    'xpath' => ['/html/head/title', '//article/p[1]'],
                ],
            ],
        ];
    }
    
    /**
     * Schema VideoObject
     */
    private static function generateVideoSchema(array $video): array
    {
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'VideoObject',
            'name' => $video['title'],
            'description' => $video['description'] ?? '',
            'thumbnailUrl' => $video['thumbnail_url'],
            'contentUrl' => $video['url'],
            'embedUrl' => $video['embed_url'] ?? $video['url'],
            'uploadDate' => $video['published_at'],
            'duration' => $video['duration'] ?? 'PT2M',
        ];
        
        if (!empty($video['views'])) {
            $schema['interactionStatistic'] = [
                '@type' => 'InteractionCounter',
                'interactionType' => ['@type' => 'WatchAction'],
                'userInteractionCount' => $video['views'],
            ];
        }
        
        return $schema;
    }
    
    /**
     * Schema ImageObject
     */
    private static function generateImageSchema(array $image, array $articleData): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'ImageObject',
            '@id' => $image['url'],
            'url' => $image['url'],
            'contentUrl' => $image['url'],
            'name' => $image['caption'] ?? $articleData['title'],
            'description' => $image['description'] ?? $articleData['meta_description'] ?? '',
            'width' => $image['width'] ?? 1200,
            'height' => $image['height'] ?? 630,
            'author' => [
                '@type' => 'Organization',
                'name' => $articleData['site_name'],
            ],
        ];
    }
    
    /**
     * Schema WebSite (per Sitelinks Searchbox)
     */
    private static function generateWebSiteSchema(array $data): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => $data['site_name'],
            'url' => $data['site_url'],
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => [
                    '@type' => 'EntryPoint',
                    'urlTemplate' => $data['site_url'] . '/?s={search_term_string}',
                ],
                'query-input' => 'required name=search_term_string',
            ],
        ];
    }
    
    /**
     * Schema ItemList per articoli correlati
     */
    public static function generateRelatedArticlesSchema(array $articles, string $listName): array
    {
        $items = [];
        foreach ($articles as $i => $article) {
            $items[] = [
                '@type' => 'ListItem',
                'position' => $i + 1,
                'url' => $article['url'],
                'name' => $article['title'],
            ];
        }
        
        return [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'name' => $listName,
            'itemListElement' => $items,
        ];
    }
    
    /**
     * Schema Organization
     */
    public static function generateOrganizationSchema(array $data): array
    {
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => $data['name'],
            'url' => $data['url'],
            'logo' => $data['logo_url'],
            'sameAs' => $data['social_profiles'] ?? [],
        ];
        
        if (!empty($data['description'])) {
            $schema['description'] = $data['description'];
        }
        
        if (!empty($data['founding_date'])) {
            $schema['foundingDate'] = $data['founding_date'];
        }
        
        return $schema;
    }
    
    /**
     * Utility: tronca testo
     */
    private static function truncate(string $text, int $length): string
    {
        if (mb_strlen($text) <= $length) {
            return $text;
        }
        return mb_substr($text, 0, $length - 3) . '...';
    }
    
    /**
     * Utility: crea slug
     */
    private static function slugify(string $text): string
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        $text = trim($text, '-');
        return $text;
    }
    
    /**
     * Valida lo schema markup generato
     */
    public static function validateSchema(array $schema): array
    {
        $errors = [];
        $warnings = [];
        
        // Validazioni base
        if (empty($schema['@context'])) {
            $errors[] = 'Manca @context';
        }
        
        if (empty($schema['@type'])) {
            $errors[] = 'Manca @type';
        }
        
        // Validazioni specifiche per tipo
        switch ($schema['@type'] ?? '') {
            case 'Article':
                if (empty($schema['headline'])) {
                    $errors[] = 'Article: manca headline';
                }
                if (empty($schema['author'])) {
                    $errors[] = 'Article: manca author';
                }
                if (empty($schema['datePublished'])) {
                    $errors[] = 'Article: manca datePublished';
                }
                break;
                
            case 'FAQPage':
                if (empty($schema['mainEntity'])) {
                    $errors[] = 'FAQPage: manca mainEntity';
                }
                break;
                
            case 'HowTo':
                if (empty($schema['step'])) {
                    $errors[] = 'HowTo: manca step';
                }
                break;
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }
}
