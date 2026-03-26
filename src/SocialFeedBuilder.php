<?php

/**
 * Generatore di feed RSS per social media (Facebook e X/Twitter).
 * Crea feed ottimizzati con copy e link per auto-posting.
 */
class SocialFeedBuilder
{
    private string $facebookFeedPath;
    private string $twitterFeedPath;
    private string $siteUrl;
    private string $permalinkStructure;
    private string $facebookPrompt;
    private string $twitterPrompt;

    public function __construct(array $config)
    {
        $this->facebookFeedPath = $config['base_dir'] . '/data/feed-facebook.xml';
        $this->twitterFeedPath = $config['base_dir'] . '/data/feed-twitter.xml';
        $this->siteUrl = $config['social_site_url'] ?? $config['feed_link'] ?? 'https://example.com';
        $this->permalinkStructure = $config['social_permalink_structure'] ?? '/%year%/%monthnum%/%day%/%postname%/';
        $this->facebookPrompt = $config['facebook_prompt'] ?? self::defaultFacebookPrompt();
        $this->twitterPrompt = $config['twitter_prompt'] ?? self::defaultTwitterPrompt();
    }

    /**
     * Prompt di default per Facebook (max 500 caratteri per il copy + link).
     */
    public static function defaultFacebookPrompt(): string
    {
        return <<<'PROMPT'
Scrivi un copy accattivante per Facebook per promuovere questo articolo.

REGOLE:
- Massimo 400 caratteri (escluso il link)
- Tono conversazionale e coinvolgente
- Usa emoji pertinenti (max 2-3)
- Includi una call to action (es. "Leggi l'articolo completo 👇")
- Non usare hashtag
- Non ripetere il titolo parola per word
- Crea curiosità senza rivelare tutto il contenuto

Articolo: "[title]"

Rispondi SOLO con il testo del copy, nient'altro.
PROMPT;
    }

    /**
     * Prompt di default per X/Twitter (max 280 caratteri totali).
     */
    public static function defaultTwitterPrompt(): string
    {
        return <<<'PROMPT'
Scrivi un tweet per X/Twitter per promuovere questo articolo.

REGOLE:
- Massimo 250 caratteri (escluso il link che sarà aggiunto dopo)
- Tono diretto e incisivo
- Usa max 1-2 emoji pertinenti
- Crea curiosità o poni una domanda
- Non usare hashtag
- Non ripetere il titolo parola per word
- Deve invogliare al click

Articolo: "[title]"

Rispondi SOLO con il testo del tweet, nient'altro.
PROMPT;
    }

    /**
     * Genera il copy per Facebook usando l'AI.
     */
    public function generateFacebookCopy(string $title, ContentGenerator $generator): ?string
    {
        $prompt = str_replace('[title]', $title, $this->facebookPrompt);
        
        // Usa il provider disponibile per generare il copy
        $copy = $this->callAiForCopy($generator, $prompt, 400);
        
        if ($copy === null) {
            // Fallback: usa un copy generico
            $copy = "📖 Scopri di più su: \"{$title}\"\n\nLeggi l'articolo completo 👇";
        }
        
        // Assicurati che non superi i 400 caratteri
        if (mb_strlen($copy) > 400) {
            $copy = mb_substr($copy, 0, 397) . '...';
        }
        
        return $copy;
    }

    /**
     * Genera il copy per X/Twitter usando l'AI.
     */
    public function generateTwitterCopy(string $title, ContentGenerator $generator): ?string
    {
        $prompt = str_replace('[title]', $title, $this->twitterPrompt);
        
        // Usa il provider disponibile per generare il copy
        $copy = $this->callAiForCopy($generator, $prompt, 250);
        
        if ($copy === null) {
            // Fallback: usa un copy generico
            $copy = "📖 {$title}\n\nCosa ne pensi? 👇";
        }
        
        // Assicurati che non superi i 250 caratteri
        if (mb_strlen($copy) > 250) {
            $copy = mb_substr($copy, 0, 247) . '...';
        }
        
        return $copy;
    }

    /**
     * Chiama l'AI per generare il copy.
     */
    private function callAiForCopy(ContentGenerator $generator, string $prompt, int $maxChars): ?string
    {
        try {
            $result = $generator->generateText($prompt, 500);
            if ($result !== null && mb_strlen($result) > 5) {
                return $result;
            }
        } catch (Throwable $e) {
            error_log('[SocialFeedBuilder] Errore generazione copy: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Aggiunge un articolo ai feed social.
     *
     * @param string           $title      Titolo dell'articolo
     * @param string|null      $imageUrl   URL immagine featured
     * @param ContentGenerator $generator  Generatore AI per il copy
     * @param string|null      $articleUrl URL reale dell'articolo (da WordPress). Se null, viene generato dalla struttura permalink.
     */
    public function addItem(string $title, ?string $imageUrl, ContentGenerator $generator, ?string $articleUrl = null): void
    {
        // Usa l'URL reale di WordPress se disponibile, altrimenti genera dalla struttura permalink
        if (empty($articleUrl)) {
            $articleUrl = $this->generateArticleUrl($title);
        }

        // Genera copy per Facebook
        $facebookCopy = $this->generateFacebookCopy($title, $generator);
        $this->addToFacebookFeed($title, $facebookCopy, $articleUrl, $imageUrl);

        // Genera copy per Twitter
        $twitterCopy = $this->generateTwitterCopy($title, $generator);
        $this->addToTwitterFeed($title, $twitterCopy, $articleUrl, $imageUrl);
    }

    /**
     * Aggiunge al feed Facebook.
     */
    private function addToFacebookFeed(string $title, ?string $copy, string $url, ?string $imageUrl): void
    {
        $doc = $this->loadOrCreateFeed($this->facebookFeedPath, 'Facebook Social Feed');
        $channel = $doc->getElementsByTagName('channel')->item(0);
        
        $item = $doc->createElement('item');
        
        $titleEl = $doc->createElement('title');
        $titleEl->appendChild($doc->createTextNode($title));
        $item->appendChild($titleEl);
        
        $descEl = $doc->createElement('description');
        $text = $copy ?: $title;
        $descEl->appendChild($doc->createCDATASection($text));
        $item->appendChild($descEl);
        
        $linkEl = $doc->createElement('link', $url);
        $item->appendChild($linkEl);
        
        $guidEl = $doc->createElement('guid', hash('sha256', $title . 'facebook'));
        $guidEl->setAttribute('isPermaLink', 'false');
        $item->appendChild($guidEl);
        
        $pubDate = $doc->createElement('pubDate', date(DATE_RSS));
        $item->appendChild($pubDate);
        
        if ($imageUrl !== null) {
            $imageEl = $doc->createElement('image');
            $imageUrlEl = $doc->createElement('url', $imageUrl);
            $imageEl->appendChild($imageUrlEl);
            $item->appendChild($imageEl);
        }
        
        // Inserisci come primo item
        $firstItem = $channel->getElementsByTagName('item')->item(0);
        if ($firstItem) {
            $channel->insertBefore($item, $firstItem);
        } else {
            $channel->appendChild($item);
        }
        
        // Limita a 50 item
        $this->trimItems($channel, 50);
        
        $doc->formatOutput = true;
        file_put_contents($this->facebookFeedPath, $doc->saveXML());
    }

    /**
     * Aggiunge al feed X/Twitter.
     */
    private function addToTwitterFeed(string $title, ?string $copy, string $url, ?string $imageUrl): void
    {
        $doc = $this->loadOrCreateFeed($this->twitterFeedPath, 'X/Twitter Social Feed');
        $channel = $doc->getElementsByTagName('channel')->item(0);
        
        $item = $doc->createElement('item');
        
        $titleEl = $doc->createElement('title');
        $titleEl->appendChild($doc->createTextNode($title));
        $item->appendChild($titleEl);
        
        // Per Twitter includi il copy + link nel description
        $descEl = $doc->createElement('description');
        $text = ($copy ?: $title) . "\n\n" . $url;
        $descEl->appendChild($doc->createCDATASection($text));
        $item->appendChild($descEl);
        
        $linkEl = $doc->createElement('link', $url);
        $item->appendChild($linkEl);
        
        $guidEl = $doc->createElement('guid', hash('sha256', $title . 'twitter'));
        $guidEl->setAttribute('isPermaLink', 'false');
        $item->appendChild($guidEl);
        
        $pubDate = $doc->createElement('pubDate', date(DATE_RSS));
        $item->appendChild($pubDate);
        
        if ($imageUrl !== null) {
            $imageEl = $doc->createElement('image');
            $imageUrlEl = $doc->createElement('url', $imageUrl);
            $imageEl->appendChild($imageUrlEl);
            $item->appendChild($imageEl);
        }
        
        // Inserisci come primo item
        $firstItem = $channel->getElementsByTagName('item')->item(0);
        if ($firstItem) {
            $channel->insertBefore($item, $firstItem);
        } else {
            $channel->appendChild($item);
        }
        
        // Limita a 50 item
        $this->trimItems($channel, 50);
        
        $doc->formatOutput = true;
        file_put_contents($this->twitterFeedPath, $doc->saveXML());
    }

    /**
     * Carica feed esistente o ne crea uno nuovo.
     */
    private function loadOrCreateFeed(string $feedPath, string $feedTitle): DOMDocument
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        
        if (file_exists($feedPath)) {
            $doc->load($feedPath);
            return $doc;
        }
        
        $rss = $doc->createElement('rss');
        $rss->setAttribute('version', '2.0');
        $doc->appendChild($rss);
        
        $channel = $doc->createElement('channel');
        $rss->appendChild($channel);
        
        $channel->appendChild($doc->createElement('title', $feedTitle));
        $channel->appendChild($doc->createElement('link', $this->siteUrl));
        $channel->appendChild($doc->createElement('description', 'Feed per auto-posting su social media'));
        $channel->appendChild($doc->createElement('language', 'it'));
        $channel->appendChild($doc->createElement('lastBuildDate', date(DATE_RSS)));
        
        return $doc;
    }

    /**
     * Rimuove item in eccesso.
     */
    private function trimItems(DOMElement $channel, int $maxItems): void
    {
        $items = $channel->getElementsByTagName('item');
        while ($items->length > $maxItems) {
            $channel->removeChild($items->item($items->length - 1));
        }
    }

    /**
     * Restituisce i percorsi dei feed social.
     */
    public function getFeedPaths(): array
    {
        return [
            'facebook' => $this->facebookFeedPath,
            'twitter' => $this->twitterFeedPath,
        ];
    }

    /**
     * Aggiorna tutti gli URL nei feed social con il nuovo siteUrl.
     * Da chiamare quando viene modificato l'URL del sito nelle impostazioni.
     */
    public function updateAllUrls(string $oldSiteUrl, string $newSiteUrl): array
    {
        $results = [];
        
        foreach (['facebook', 'twitter'] as $feedType) {
            $feedPath = $feedType === 'facebook' ? $this->facebookFeedPath : $this->twitterFeedPath;
            
            if (!file_exists($feedPath)) {
                $results[$feedType] = 'Feed non esistente';
                continue;
            }
            
            $doc = new DOMDocument('1.0', 'UTF-8');
            $doc->load($feedPath);
            
            $items = $doc->getElementsByTagName('item');
            $updatedCount = 0;
            
            foreach ($items as $item) {
                // Aggiorna link
                $linkNode = $item->getElementsByTagName('link')->item(0);
                if ($linkNode) {
                    $oldUrl = $linkNode->textContent;
                    $newUrl = str_replace($oldSiteUrl, $newSiteUrl, $oldUrl);
                    if ($oldUrl !== $newUrl) {
                        $linkNode->textContent = $newUrl;
                        $updatedCount++;
                    }
                }
                
                // Aggiorna URL nelle descrizioni (per Twitter dove l'URL è nel testo)
                $descNode = $item->getElementsByTagName('description')->item(0);
                if ($descNode && $feedType === 'twitter') {
                    $oldDesc = $descNode->textContent;
                    $newDesc = str_replace($oldSiteUrl, $newSiteUrl, $oldDesc);
                    if ($oldDesc !== $newDesc) {
                        // Rimuovi vecchio nodo e crea nuovo con CDATA
                        $item->removeChild($descNode);
                        $newDescEl = $doc->createElement('description');
                        $newDescEl->appendChild($doc->createCDATASection($newDesc));
                        // Inserisci dopo il titolo
                        $titleNode = $item->getElementsByTagName('title')->item(0);
                        if ($titleNode && $titleNode->nextSibling) {
                            $item->insertBefore($newDescEl, $titleNode->nextSibling);
                        } else {
                            $item->appendChild($newDescEl);
                        }
                    }
                }
            }
            
            // Salva il feed aggiornato
            $doc->formatOutput = true;
            file_put_contents($feedPath, $doc->saveXML());
            
            $results[$feedType] = "Aggiornati {$updatedCount} URL";
        }
        
        return $results;
    }

    /**
     * Genera uno slug URL-friendly da un titolo (compatibile WordPress).
     */
    public static function slugify(string $text): string
    {
        // Converti in lowercase
        $text = mb_strtolower($text, 'UTF-8');
        
        // Rimuovi accenti e caratteri speciali
        $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        
        // Sostituisci spazi e caratteri non alfanumerici con trattini
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        
        // Rimuovi trattini multipli
        $text = preg_replace('/-+/', '-', $text);
        
        // Trim trattini iniziali e finali
        $text = trim($text, '-');
        
        // Limita lunghezza (WordPress usa max 200 caratteri, noi usiamo 100 per sicurezza)
        return mb_substr($text, 0, 100);
    }

    /**
     * Genera l'URL completo di un articolo compatibile con WordPress.
     * Supporta varie strutture permalink configurabili.
     */
    public function generateArticleUrl(string $title, ?string $publishDate = null): string
    {
        $slug = self::slugify($title);
        $date = $publishDate ? new DateTime($publishDate) : new DateTime();
        
        // Sostituisci i tag nella struttura permalink
        $structure = $this->permalinkStructure;
        
        $replacements = [
            '%year%'     => $date->format('Y'),
            '%monthnum%' => $date->format('m'),
            '%day%'      => $date->format('d'),
            '%postname%' => $slug,
        ];
        
        $path = str_replace(array_keys($replacements), array_values($replacements), $structure);
        
        // Rimuovi doppi slash e assicurati che inizi con /
        $path = '/' . trim($path, '/');
        
        return rtrim($this->siteUrl, '/') . $path;
    }
}
