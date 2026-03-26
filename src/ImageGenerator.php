<?php

/**
 * Generatore di immagini tramite fal.ai API.
 *
 * Genera immagini AI per gli articoli e le salva localmente.
 */
class ImageGenerator
{
    private const API_BASE_URL = 'https://fal.run/';

    private string $apiKey;
    private string $modelId;
    private string $imageSize;
    private string $outputFormat;
    private string $quality;
    private string $promptTemplate;
    private string $imagesDir;
    private string $imagesUrl;
    private bool $enabled;
    private bool $inlineEnabled;
    private int $inlineInterval;
    private string $inlineSize;
    private string $inlinePromptTemplate;

    public function __construct(array $config)
    {
        $this->apiKey              = $config['fal_api_key'] ?? '';
        $this->modelId             = $config['fal_model_id'] ?? 'fal-ai/flux/schnell';
        $this->imageSize           = $config['fal_image_size'] ?? 'landscape_16_9';
        $this->outputFormat        = $config['fal_output_format'] ?? 'jpeg';
        $this->quality             = $config['fal_quality'] ?? '';
        $this->promptTemplate      = $config['fal_prompt_template'] ?? self::defaultPrompt();
        $this->imagesDir           = $config['images_dir'] ?? $config['base_dir'] . '/data/images';
        $this->imagesUrl           = $config['images_url'] ?? 'data/images';
        $this->enabled             = !empty($config['fal_enabled']);
        $this->inlineEnabled       = !empty($config['fal_inline_enabled']);
        $this->inlineInterval      = max(1, intval($config['fal_inline_interval'] ?? 3));
        $this->inlineSize          = $config['fal_inline_size'] ?? 'landscape_16_9';
        $this->inlinePromptTemplate = $config['fal_inline_prompt_template'] ?? self::defaultInlinePrompt();

        // Crea la directory immagini se non esiste
        if (!is_dir($this->imagesDir)) {
            @mkdir($this->imagesDir, 0755, true);
        }
    }

    /**
     * Verifica se la generazione immagini e' abilitata e configurata.
     */
    public function isEnabled(): bool
    {
        return $this->enabled && !empty($this->apiKey) && !empty($this->modelId);
    }

    /**
     * Verifica se le immagini inline sono abilitate.
     */
    public function isInlineEnabled(): bool
    {
        return $this->isEnabled() && $this->inlineEnabled;
    }

    /**
     * Prompt di default per le immagini featured.
     */
    public static function defaultPrompt(): string
    {
        return 'A professional, high-quality featured image for a blog post titled: "[title]". Modern, clean design, vibrant colors, editorial quality. No text or letters in the image.';
    }

    /**
     * Prompt di default per le immagini inline.
     */
    public static function defaultInlinePrompt(): string
    {
        return 'A contextual illustration for a blog section about: [context]. Style: clean, relevant, editorial quality. No text or letters in the image.';
    }

    /**
     * Genera un'immagine featured per un articolo.
     *
     * @param string $title Titolo dell'articolo
     * @param string $topic Topic/keyword dell'articolo
     * @return array|null ['url' => string, 'path' => string, 'filename' => string] oppure null
     */
    public function generateFeaturedImage(string $title, string $topic): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $prompt = str_replace('[title]', $title, $this->promptTemplate);
        $prompt = str_replace('[keyword]', $topic, $prompt);

        $imageUrl = $this->callFalApi($prompt, $this->imageSize);
        if ($imageUrl === null) {
            return null;
        }

        // Usa direttamente l'URL di fal.ai senza scaricare localmente
        return [
            'url'      => $imageUrl,
            'path'     => $imageUrl,
            'filename' => basename(parse_url($imageUrl, PHP_URL_PATH)),
        ];
    }

    /**
     * Genera immagini inline da inserire nel corpo dell'articolo.
     */
    public function insertInlineImages(string $title, string $body, string $topic): string
    {
        if (!$this->isInlineEnabled()) {
            return $body;
        }

        $sections = $this->extractH2Sections($body);

        if (count($sections) < $this->inlineInterval + 1) {
            return $body;
        }

        $imagePositions = [];
        for ($h = $this->inlineInterval; $h < count($sections); $h += $this->inlineInterval) {
            $imagePositions[] = $h;
        }

        if (empty($imagePositions)) {
            return $body;
        }

        $images = [];
        $slug = $this->slugify($title);
        $ext = $this->outputFormat === 'png' ? 'png' : ($this->outputFormat === 'webp' ? 'webp' : 'jpg');

        foreach ($imagePositions as $i => $pos) {
            $context = '';
            if (isset($sections[$pos]) && !empty($sections[$pos]['heading'])) {
                $context = $sections[$pos]['heading'];
            } elseif (isset($sections[$pos - 1]) && !empty($sections[$pos - 1]['heading'])) {
                $context = $sections[$pos - 1]['heading'];
            }

            $contextStart = max(0, $pos - $this->inlineInterval);
            $groupText = '';
            for ($j = $contextStart; $j < $pos && $j < count($sections); $j++) {
                $groupText .= ' ' . $sections[$j]['text'];
            }
            $summary = $this->summarizeText(trim($groupText), 150);
            $fullContext = !empty($context) ? $context . '. ' . $summary : $summary;

            $prompt = str_replace('[context]', $fullContext, $this->inlinePromptTemplate);
            $prompt = str_replace('[title]', $title, $prompt);
            $prompt = str_replace('[keyword]', $topic, $prompt);

            if (mb_strlen($prompt) > 500) {
                $prompt = $this->summarizeText($prompt, 500);
            }

            $imageUrl = $this->callFalApi($prompt, $this->inlineSize);
            if ($imageUrl === null) {
                continue;
            }

            // Usa direttamente l'URL di fal.ai senza scaricare localmente
            $images[$pos] = [
                'url'     => $imageUrl,
                'alt'     => $context ?: $title,
            ];

            if ($i < count($imagePositions) - 1) {
                sleep(2);
            }
        }

        if (empty($images)) {
            return $body;
        }

        return $this->injectImagesIntoBody($body, $images);
    }

    /**
     * Chiama l'API fal.ai per generare un'immagine.
     */
    private function callFalApi(string $prompt, string $imageSize): ?string
    {
        $url = rtrim(self::API_BASE_URL, '/') . '/' . ltrim($this->modelId, '/');

        $requestBody = [
            'prompt'        => $prompt,
            'num_images'    => 1,
            'output_format' => $this->outputFormat,
        ];

        // Grok usa aspect_ratio, GPT Image usa image_size con valori diversi da Flux
        if (str_starts_with($this->modelId, 'xai/')) {
            $requestBody['aspect_ratio'] = $this->mapSizeToAspectRatio($imageSize);
        } elseif (str_contains($this->modelId, 'gpt-image')) {
            $requestBody['image_size'] = $this->mapSizeToOpenAI($imageSize);
        } else {
            $requestBody['image_size'] = $imageSize;
        }

        if (!empty($this->quality)) {
            $requestBody['quality'] = $this->quality;
        }

        $payload = json_encode($requestBody);

        $response = $this->curlPost($url, $payload, [
            'Authorization: Key ' . $this->apiKey,
            'Content-Type: application/json',
        ]);

        if ($response === null) {
            return null;
        }

        $data = json_decode($response, true);

        $imageUrl = '';
        if (!empty($data['images'][0]['url'])) {
            $imageUrl = $data['images'][0]['url'];
        } elseif (!empty($data['image']['url'])) {
            $imageUrl = $data['image']['url'];
        } elseif (!empty($data['output'][0])) {
            $imageUrl = $data['output'][0];
        }

        if (empty($imageUrl) || !filter_var($imageUrl, FILTER_VALIDATE_URL)) {
            error_log('[ImageGenerator] URL immagine non trovato nella risposta API');
            return null;
        }

        return $imageUrl;
    }

    /**
     * Mappa le dimensioni dal formato Flux al formato OpenAI GPT Image.
     * GPT Image supporta: 1024x1024, 1536x1024, 1024x1536
     * 1024x1024 = square (più economico)
     * 1536x1024 = landscape/orizzontale
     * 1024x1536 = portrait/verticale
     */
    private function mapSizeToOpenAI(string $falSize): string
    {
        // Se è già un formato diretto GPT Image (NNNxNNN), usalo così com'è
        $validGptSizes = ['1024x1024', '1536x1024', '1024x1536'];
        if (in_array($falSize, $validGptSizes, true)) {
            return $falSize;
        }

        // Altrimenti mappa dal formato Flux
        return match ($falSize) {
            'landscape_16_9', 'landscape_4_3' => '1536x1024',
            'portrait_4_3', 'portrait_16_9'   => '1024x1536',
            'square'                          => '1024x1024',
            default                           => '1536x1024',
        };
    }

    /**
     * Mappa le dimensioni dal formato Flux al formato aspect_ratio per Grok.
     */
    private function mapSizeToAspectRatio(string $falSize): string
    {
        // Gestisci anche formati diretti NNNxNNN
        return match ($falSize) {
            'landscape_16_9', '1536x1024' => '16:9',
            'landscape_4_3'               => '4:3',
            'portrait_4_3'                => '3:4',
            'portrait_16_9', '1024x1536'  => '9:16',
            'square', '1024x1024'         => '1:1',
            default                       => '16:9',
        };
    }

    /**
     * Restituisce le dimensioni disponibili per Flux.
     */
    public static function getFluxSizes(): array
    {
        return [
            'landscape_16_9' => 'Landscape 16:9 (Panoramico)',
            'landscape_4_3'  => 'Landscape 4:3 (Classico)',
            'square'         => 'Square 1:1 (Quadrato)',
            'portrait_4_3'   => 'Portrait 4:3 (Verticale)',
            'portrait_16_9'  => 'Portrait 16:9 (Stories)',
        ];
    }

    /**
     * Restituisce le dimensioni disponibili per GPT Image.
     */
    public static function getGPTImageSizes(): array
    {
        return [
            '1024x1024' => '1024x1024 (Square - Min crediti)',
            '1536x1024' => '1536x1024 (Landscape - Orizzontale)',
            '1024x1536' => '1024x1536 (Portrait - Verticale)',
        ];
    }

    /**
     * Scarica un'immagine remota e la salva localmente.
     * Nota: attualmente non usato, le immagini vengono servite direttamente da fal.ai
     */
    private function downloadImage(string $imageUrl, string $filename): ?string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $imageUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
        ]);

        $imageData = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($imageData === false || $httpCode < 200 || $httpCode >= 300) {
            error_log("[ImageGenerator] Errore download immagine (HTTP {$httpCode}): {$error}");
            return null;
        }

        $localPath = $this->imagesDir . '/' . $filename;
        if (file_put_contents($localPath, $imageData) === false) {
            error_log("[ImageGenerator] Errore scrittura file: {$localPath}");
            return null;
        }

        return $localPath;
    }

    /**
     * Estrae le sezioni H2 dal contenuto HTML.
     */
    private function extractH2Sections(string $content): array
    {
        $sections = [];
        $parts = preg_split('/(<h2[^>]*>.*?<\/h2>)/is', $content, -1, PREG_SPLIT_DELIM_CAPTURE);

        $currentHeading = '';
        $currentText = '';

        foreach ($parts as $part) {
            if (preg_match('/^<h2[^>]*>(.*?)<\/h2>$/is', $part, $m)) {
                if (!empty($currentHeading) || !empty(trim(strip_tags($currentText)))) {
                    $sections[] = [
                        'heading' => strip_tags($currentHeading),
                        'text'    => trim(strip_tags($currentText)),
                    ];
                }
                $currentHeading = $m[1];
                $currentText = '';
            } else {
                $currentText .= $part;
            }
        }

        if (!empty($currentHeading) || !empty(trim(strip_tags($currentText)))) {
            $sections[] = [
                'heading' => strip_tags($currentHeading),
                'text'    => trim(strip_tags($currentText)),
            ];
        }

        return $sections;
    }

    /**
     * Inserisce le immagini nel body HTML prima dei tag H2 corrispondenti.
     */
    private function injectImagesIntoBody(string $body, array $images): string
    {
        $parts = preg_split('/(<h2[^>]*>.*?<\/h2>)/is', $body, -1, PREG_SPLIT_DELIM_CAPTURE);

        $result = '';
        $h2Index = 0;

        foreach ($parts as $part) {
            if (preg_match('/^<h2[^>]*>.*?<\/h2>$/is', $part)) {
                $h2Index++;
                if (isset($images[$h2Index])) {
                    $img = $images[$h2Index];
                    $alt = htmlspecialchars($img['alt'], ENT_QUOTES, 'UTF-8');
                    $result .= '<figure class="article-inline-image" style="margin:30px 0;text-align:center;">'
                        . '<img src="' . htmlspecialchars($img['url'], ENT_QUOTES, 'UTF-8') . '" alt="' . $alt . '" '
                        . 'style="max-width:100%;height:auto;border-radius:8px;" loading="lazy">'
                        . '</figure>';
                }
                $result .= $part;
            } else {
                $result .= $part;
            }
        }

        return $result;
    }

    /**
     * Riassume un testo troncandolo in modo intelligente.
     */
    private function summarizeText(string $text, int $limit = 300): string
    {
        $text = strip_tags($text);
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        $truncated = mb_substr($text, 0, $limit);
        $lastSpace = mb_strrpos($truncated, ' ');
        if ($lastSpace !== false) {
            $truncated = mb_substr($truncated, 0, $lastSpace);
        }

        return $truncated . '...';
    }

    /**
     * Genera uno slug URL-friendly da un titolo.
     */
    private function slugify(string $text): string
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        $text = trim($text, '-');
        return mb_substr($text, 0, 60);
    }

    /**
     * Esegue una richiesta POST con cURL e retry con exponential backoff.
     */
    private function curlPost(string $url, string $payload, array $headers, int $maxRetries = 3): ?string
    {
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 120,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_FOLLOWLOCATION => true,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($response !== false && $httpCode >= 200 && $httpCode < 300) {
                return $response;
            }

            // Non ritentare per errori client (tranne 429 e 408)
            if ($httpCode >= 400 && $httpCode < 500 && $httpCode !== 429 && $httpCode !== 408) {
                error_log("[ImageGenerator] HTTP Error {$httpCode}: " . substr($response ?: '', 0, 1000));
                return null;
            }

            if ($attempt < $maxRetries) {
                $delay = pow(2, $attempt);
                error_log("[ImageGenerator] Retry {$attempt}/{$maxRetries} dopo HTTP {$httpCode} - attendo {$delay}s");
                sleep($delay);
            }
        }

        error_log("[ImageGenerator] Fallito dopo {$maxRetries} tentativi - CURL Error: {$error}");
        return null;
    }
}
