<?php

class RSSFeedBuilder
{
    private string $feedPath;
    private int $maxItems;
    private string $feedTitle;
    private string $feedDescription;
    private string $feedLink;
    private string $feedLanguage;

    public function __construct(array $config)
    {
        $this->feedPath        = $config['feed_path'];
        $this->maxItems        = $config['max_feed_items'];
        $this->feedTitle       = $config['feed_title'];
        $this->feedDescription = $config['feed_description'];
        $this->feedLink        = $config['feed_link'];
        $this->feedLanguage    = $config['feed_language'];
    }

    /**
     * Aggiunge un articolo al feed RSS.
     * @param string     $title         Titolo dell'articolo
     * @param string     $content       Contenuto completo HTML dell'articolo
     * @param array|null $featuredImage Immagine featured ['url' => string, 'path' => string] oppure null
     * @param string|null $metaDescription Meta description per SEO (opzionale)
     */
    public function addItem(string $title, string $content, ?array $featuredImage = null, ?string $metaDescription = null): void
    {
        $doc = $this->loadOrCreateFeed();

        $channel = $doc->getElementsByTagName('channel')->item(0);

        // Crea nuovo item
        $item = $doc->createElement('item');

        $titleEl = $doc->createElement('title');
        $titleEl->appendChild($doc->createTextNode($title));
        $item->appendChild($titleEl);

        // Campo content:encoded per contenuto completo HTML
        $contentEl = $doc->createElementNS('http://purl.org/rss/1.0/modules/content/', 'content:encoded');
        $contentEl->appendChild($doc->createCDATASection($content));
        $item->appendChild($contentEl);

        // description come riassunto (primi 200 caratteri)
        $descEl = $doc->createElement('description');
        $summary = mb_substr(strip_tags($content), 0, 200) . '...';
        $descEl->appendChild($doc->createCDATASection($summary));
        $item->appendChild($descEl);

        // Meta description per SEO (se fornita)
        if (!empty($metaDescription)) {
            $metaDescEl = $doc->createElement('meta_description');
            $metaDescEl->appendChild($doc->createCDATASection($metaDescription));
            $item->appendChild($metaDescEl);
        }

        $pubDate = $doc->createElement('pubDate', date(DATE_RSS));
        $item->appendChild($pubDate);

        $guid = $doc->createElement('guid', hash('sha256', $title . date('Y-m-d H:i:s')));
        $guid->setAttribute('isPermaLink', 'false');
        $item->appendChild($guid);

        // Immagine featured (se presente)
        if ($featuredImage !== null && !empty($featuredImage['url'])) {
            $imageEl = $doc->createElement('image');
            $imageUrl = $doc->createElement('url', $featuredImage['url']);
            $imageEl->appendChild($imageUrl);
            $item->appendChild($imageEl);
        }

        // Inserisci come primo item (dopo lastBuildDate)
        $firstItem = $channel->getElementsByTagName('item')->item(0);
        if ($firstItem) {
            $channel->insertBefore($item, $firstItem);
        } else {
            $channel->appendChild($item);
        }

        // Aggiorna lastBuildDate
        $lastBuild = $channel->getElementsByTagName('lastBuildDate')->item(0);
        if ($lastBuild) {
            $lastBuild->textContent = date(DATE_RSS);
        }

        // Limita numero di item
        $this->trimItems($channel);

        // Salva
        $this->saveFeed($doc);
    }

    /**
     * Carica feed esistente o ne crea uno nuovo.
     */
    private function loadOrCreateFeed(): DOMDocument
    {
        $doc = new DOMDocument('1.0', 'UTF-8');

        if (file_exists($this->feedPath)) {
            // Sopprimi warning di libxml e verifica il risultato
            $prevUseErrors = libxml_use_internal_errors(true);
            $loaded = $doc->load($this->feedPath);
            $errors = libxml_get_errors();
            libxml_clear_errors();
            libxml_use_internal_errors($prevUseErrors);

            if ($loaded && $doc->getElementsByTagName('channel')->length > 0) {
                return $doc;
            }

            // Feed corrotto: log errore e prova il backup
            error_log('[RSSFeedBuilder] Feed corrotto, tentativo recupero da backup: ' . $this->feedPath);
            $backupPath = $this->feedPath . '.bak';
            if (file_exists($backupPath)) {
                $doc2 = new DOMDocument('1.0', 'UTF-8');
                if ($doc2->load($backupPath) && $doc2->getElementsByTagName('channel')->length > 0) {
                    error_log('[RSSFeedBuilder] Recuperato feed dal backup');
                    return $doc2;
                }
            }

            // Nessun backup valido: crea feed nuovo (gli articoli vecchi sono persi)
            error_log('[RSSFeedBuilder] Impossibile recuperare feed, creazione nuovo feed vuoto');
            $doc = new DOMDocument('1.0', 'UTF-8');
        }

        // Crea feed RSS 2.0 vuoto
        $rss = $doc->createElement('rss');
        $rss->setAttribute('version', '2.0');
        $rss->setAttribute('xmlns:content', 'http://purl.org/rss/1.0/modules/content/');
        $doc->appendChild($rss);

        $channel = $doc->createElement('channel');
        $rss->appendChild($channel);

        $channel->appendChild($doc->createElement('title', $this->feedTitle));
        $channel->appendChild($doc->createElement('link', $this->feedLink));
        $channel->appendChild($doc->createElement('description', $this->feedDescription));
        $channel->appendChild($doc->createElement('language', $this->feedLanguage));
        $channel->appendChild($doc->createElement('lastBuildDate', date(DATE_RSS)));

        return $doc;
    }

    /**
     * Salva il feed su disco con backup atomico.
     */
    private function saveFeed(DOMDocument $doc): void
    {
        $doc->formatOutput = true;
        $xml = $doc->saveXML();

        // Crea backup del feed corrente prima di sovrascrivere
        if (file_exists($this->feedPath) && filesize($this->feedPath) > 0) {
            @copy($this->feedPath, $this->feedPath . '.bak');
        }

        file_put_contents($this->feedPath, $xml, LOCK_EX);
    }

    /**
     * Rimuove gli item in eccesso (mantiene i piu' recenti).
     */
    private function trimItems(DOMElement $channel): void
    {
        $items = $channel->getElementsByTagName('item');
        while ($items->length > $this->maxItems) {
            $channel->removeChild($items->item($items->length - 1));
        }
    }

    /**
     * Aggiorna titolo e contenuto di un item esistente nel feed.
     * @return bool True se l'aggiornamento e' riuscito
     */
    public function updateItem(int $index, string $newTitle, string $newContent): bool
    {
        if (!file_exists($this->feedPath)) {
            return false;
        }

        $doc = new DOMDocument();
        $doc->load($this->feedPath);
        $items = $doc->getElementsByTagName('item');

        if ($index < 0 || $index >= $items->length) {
            return false;
        }

        $item = $items->item($index);

        // Aggiorna titolo
        $titleNode = $item->getElementsByTagName('title')->item(0);
        if ($titleNode) {
            $titleNode->textContent = '';
            $titleNode->appendChild($doc->createTextNode($newTitle));
        }

        // Aggiorna content:encoded
        $contentNodes = $item->getElementsByTagNameNS('http://purl.org/rss/1.0/modules/content/', 'encoded');
        if ($contentNodes->length > 0) {
            $oldNode = $contentNodes->item(0);
            $newNode = $doc->createElementNS('http://purl.org/rss/1.0/modules/content/', 'content:encoded');
            $newNode->appendChild($doc->createCDATASection($newContent));
            $oldNode->parentNode->replaceChild($newNode, $oldNode);
        }

        // Aggiorna description (anteprima)
        $descNode = $item->getElementsByTagName('description')->item(0);
        if ($descNode) {
            $newDesc = mb_substr(strip_tags($newContent), 0, 200) . '...';
            $newDescNode = $doc->createElement('description');
            $newDescNode->appendChild($doc->createCDATASection($newDesc));
            $descNode->parentNode->replaceChild($newDescNode, $descNode);
        }

        $this->saveFeed($doc);
        return true;
    }

    /**
     * Segna un item del feed come pubblicato su WordPress.
     * Salva post_id e post_url come elementi custom nell'XML.
     */
    public function markAsPublished(int $index, int $postId, string $postUrl): bool
    {
        if (!file_exists($this->feedPath)) {
            return false;
        }

        $doc = new DOMDocument();
        $doc->load($this->feedPath);
        $items = $doc->getElementsByTagName('item');

        if ($index < 0 || $index >= $items->length) {
            return false;
        }

        $item = $items->item($index);

        // Rimuovi eventuali wp_post_id/wp_post_url precedenti
        foreach (['wp_post_id', 'wp_post_url'] as $tag) {
            $existing = $item->getElementsByTagName($tag);
            while ($existing->length > 0) {
                $item->removeChild($existing->item(0));
            }
        }

        $item->appendChild($doc->createElement('wp_post_id', (string) $postId));
        $item->appendChild($doc->createElement('wp_post_url', $postUrl));

        $this->saveFeed($doc);
        return true;
    }

    /**
     * Trova l'indice di un item nel feed cercando per titolo.
     * @return int|null Indice dell'item oppure null
     */
    public function findItemIndex(string $title): ?int
    {
        if (!file_exists($this->feedPath)) {
            return null;
        }

        $doc = new DOMDocument();
        $doc->load($this->feedPath);
        $items = $doc->getElementsByTagName('item');

        for ($i = 0; $i < $items->length; $i++) {
            $itemTitle = $items->item($i)->getElementsByTagName('title')->item(0)?->textContent ?? '';
            if ($itemTitle === $title) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Restituisce tutti gli item del feed come array.
     */
    public function getItems(): array
    {
        if (!file_exists($this->feedPath)) {
            return [];
        }

        $doc = new DOMDocument();
        $doc->load($this->feedPath);

        $items = [];
        foreach ($doc->getElementsByTagName('item') as $item) {
            $title = $item->getElementsByTagName('title')->item(0)?->textContent ?? '';
            $content = '';
            $contentNodes = $item->getElementsByTagNameNS('http://purl.org/rss/1.0/modules/content/', 'encoded');
            if ($contentNodes->length > 0) {
                $content = $contentNodes->item(0)->textContent;
            }
            $pubDate = $item->getElementsByTagName('pubDate')->item(0)?->textContent ?? '';

            // Immagine featured
            $imageUrl = '';
            $imageNode = $item->getElementsByTagName('image')->item(0);
            if ($imageNode) {
                $urlNode = $imageNode->getElementsByTagName('url')->item(0);
                if ($urlNode) {
                    $imageUrl = $urlNode->textContent;
                }
            }

            // Dati pubblicazione WordPress
            $wpPostId = $item->getElementsByTagName('wp_post_id')->item(0)?->textContent ?? '';
            $wpPostUrl = $item->getElementsByTagName('wp_post_url')->item(0)?->textContent ?? '';

            // Meta description (se presente)
            $metaDescription = '';
            $metaDescNode = $item->getElementsByTagName('meta_description')->item(0);
            if ($metaDescNode) {
                $metaDescription = $metaDescNode->textContent;
            }

            $items[] = [
                'title'           => $title,
                'content'         => $content,
                'pubDate'         => $pubDate,
                'image'           => $imageUrl,
                'wp_post_id'      => $wpPostId,
                'wp_post_url'     => $wpPostUrl,
                'meta_description' => $metaDescription,
            ];
        }

        return $items;
    }

    /**
     * Numero totale di item nel feed.
     */
    public function getItemCount(): int
    {
        if (!file_exists($this->feedPath)) {
            return 0;
        }
        $doc = new DOMDocument();
        $doc->load($this->feedPath);
        return $doc->getElementsByTagName('item')->length;
    }

    /**
     * Aggiorna tutti gli URL nel feed con il nuovo siteUrl.
     * Da chiamare quando viene modificato l'URL del sito nelle impostazioni.
     */
    public function updateAllUrls(string $oldSiteUrl, string $newSiteUrl): string
    {
        if (!file_exists($this->feedPath)) {
            return 'Feed non esistente';
        }
        
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->load($this->feedPath);
        
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
            
            // Aggiorna GUID se è un permalink
            $guidNode = $item->getElementsByTagName('guid')->item(0);
            if ($guidNode) {
                $isPermalink = $guidNode->getAttribute('isPermaLink');
                if ($isPermalink !== 'false') {
                    $oldGuid = $guidNode->textContent;
                    $newGuid = str_replace($oldSiteUrl, $newSiteUrl, $oldGuid);
                    if ($oldGuid !== $newGuid) {
                        $guidNode->textContent = $newGuid;
                    }
                }
            }
            
            // Aggiorna URL nelle immagini
            $imageNodes = $item->getElementsByTagName('image');
            foreach ($imageNodes as $imageNode) {
                $urlNode = $imageNode->getElementsByTagName('url')->item(0);
                if ($urlNode) {
                    $oldImgUrl = $urlNode->textContent;
                    // Aggiorna solo se l'URL dell'immagine contiene il vecchio siteUrl
                    if (str_starts_with($oldImgUrl, $oldSiteUrl)) {
                        $newImgUrl = str_replace($oldSiteUrl, $newSiteUrl, $oldImgUrl);
                        $urlNode->textContent = $newImgUrl;
                    }
                }
            }
        }
        
        // Aggiorna anche il link del channel
        $channel = $doc->getElementsByTagName('channel')->item(0);
        if ($channel) {
            $channelLink = $channel->getElementsByTagName('link')->item(0);
            if ($channelLink) {
                $channelLink->textContent = str_replace($oldSiteUrl, $newSiteUrl, $channelLink->textContent);
            }
        }
        
        // Salva il feed aggiornato
        $this->saveFeed($doc);
        
        return "Aggiornati {$updatedCount} URL";
    }
}
