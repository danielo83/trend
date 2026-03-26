<?php

class ContentGenerator
{
    private string $openaiKey;
    private string $geminiKey;
    private string $openrouterKey;
    private string $openaiModel;
    private string $geminiModel;
    private string $openrouterModel;
    private string $defaultProvider;
    private string $promptTemplate;
    private string $titlePromptTemplate;
    private string $nicheDescription;
    private string $nicheName;

    /** @var int Punteggio minimo per accettare un articolo (1-10) */
    private int $minQualityScore;

    /** @var int Numero massimo di retry per chiamate API */
    private int $maxRetries;

    /** @var bool Abilita schema markup JSON-LD nell'output */
    private bool $schemaMarkupEnabled;

    /** @var array Prompt personalizzati per categoria (dalla config) */
    private array $categoryPrompts = [];

    /** @var callable|null Callback per logging: fn(string $message, string $type) */
    private $logCallback = null;

    /** @var LinkBuilder|null Link builder per internal/external linking */
    private ?LinkBuilder $linkBuilder = null;

    public function __construct(array $config)
    {
        $this->openaiKey        = $config['openai_api_key'];
        $this->geminiKey        = $config['gemini_api_key'];
        $this->openrouterKey    = $config['openrouter_api_key'] ?? '';
        $this->openaiModel      = $config['openai_model'];
        $this->geminiModel      = $config['gemini_model'];
        $this->openrouterModel  = $config['openrouter_model'] ?? 'openai/gpt-4o-mini';
        $this->defaultProvider  = $config['default_provider'] ?? 'openai';
        $this->promptTemplate   = $config['prompt_template'] ?? self::defaultPrompt();
        $this->titlePromptTemplate = $config['title_prompt_template'] ?? '';
        $this->nicheDescription = $config['niche_description'] ?? 'sogni, dormire, smorfia napoletana';
        $this->nicheName        = $config['niche_name'] ?? 'Sogni e Dormire';
        $this->minQualityScore  = max(1, min(10, intval($config['min_quality_score'] ?? 6)));
        $this->maxRetries       = max(1, intval($config['api_max_retries'] ?? 3));
        $this->schemaMarkupEnabled = !empty($config['schema_markup_enabled'] ?? true);

        // Prompt personalizzati per categoria (opzionali)
        foreach (['sogni', 'sonno', 'smorfia'] as $cat) {
            $key = "prompt_{$cat}";
            if (!empty($config[$key])) {
                $this->categoryPrompts[$cat] = $config[$key];
            }
        }
    }

    /**
     * Imposta un callback per logging dettagliato (usato da run_stream per mostrare log in tempo reale).
     */
    public function setLogCallback(callable $callback): void
    {
        $this->logCallback = $callback;
    }

    /**
     * Imposta il LinkBuilder per il linking interno/esterno.
     */
    public function setLinkBuilder(LinkBuilder $lb): void
    {
        $this->linkBuilder = $lb;
    }

    /**
     * Log interno: scrive sia su error_log che sul callback se presente.
     */
    private function log(string $message, string $type = 'detail'): void
    {
        error_log("[ContentGenerator] " . $message);
        if ($this->logCallback !== null) {
            ($this->logCallback)('[GENERAZIONE] ' . $message, $type);
        }
    }

    /**
     * Restituisce l'ordine dei provider: prima il predefinito, poi gli altri come fallback.
     */
    private function getProviderOrder(): array
    {
        $all = ['openai', 'gemini', 'openrouter'];
        $order = [$this->defaultProvider];
        foreach ($all as $p) {
            if ($p !== $this->defaultProvider) {
                $order[] = $p;
            }
        }
        return $order;
    }

    /**
     * Prompt di default per la generazione articoli.
     */
    public static function defaultPrompt(): string
    {
        return <<<'PROMPT'
Scrivi un articolo completo in italiano sul tema: [keyword]
Segui questa struttura. Scrivi quanto è necessario per coprire l'argomento: NON inventare informazioni per raggiungere una lunghezza minima.
INTRODUZIONE (3-4 righe): Spiega perché questo sogno è comune e cosa rappresenta nell'immaginario collettivo italiano.
SIGNIFICATO GENERALE (sotto <h2>[keyword]</h2>): Interpreta il simbolo principale dal punto di vista psicologico e della tradizione popolare italiana.
VARIANTI DEL SOGNO (sotto <h2>Varianti e situazioni comuni</h2>): Elenca almeno 5 scenari specifici, ognuno con titolo <h3> e 4-5 righe di interpretazione dettagliata. Ad esempio varianti di colore, dimensione, azione, contesto.
INTERPRETAZIONE PSICOLOGICA (sotto <h2>Cosa dice la psicologia</h2>): Un lungo paragrafo con riferimenti a Freud o Jung sul simbolismo.
COLLEGAMENTO AL SONNO (sotto <h3>L'impatto sul riposo</h3>): Scrivi un paragrafo che spieghi come fare questo tipo di sogno possa influenzare la qualità del sonno (es. risvegli improvvisi, tensione, sudorazione) e suggerisci che adottare una buona routine di rilassamento prima di dormire può aiutare l'inconscio a elaborare queste emozioni.
LA SMORFIA NAPOLETANA (sotto <h2>La Smorfia napoletana: tradizione e simbolismo</h2>): Racconta il legame tra il sogno e la tradizione della Smorfia napoletana, antica pratica popolare nata nei vicoli di Napoli e tramandata di generazione in generazione. Spiega come nella cultura partenopea ogni elemento del sogno venisse associato a un numero secondo un codice simbolico condiviso dalla comunità. Presenta i numeri tradizionalmente associati a questo sogno e alle sue varianti principali come elementi di un sistema culturale e simbolico, spiegando il perché dell'associazione quando possibile. Racconta con rispetto, curiosità e tono divulgativo. Non fare mai riferimento al gioco del lotto, alle scommesse o a qualsiasi forma di gioco d'azzardo. Non usare mai espressioni come "numeri da giocare", "tentare la fortuna", "puntare sul", "scommettere su" o qualsiasi altra espressione che possa essere interpretata come un invito o un suggerimento a giocare. I numeri vanno presentati esclusivamente come elementi di un patrimonio culturale e folkloristico. Chiudi la sezione con: "La Smorfia napoletana è un patrimonio culturale immateriale della tradizione italiana, da apprezzare come espressione della sapienza popolare partenopea."
FAQ (sotto <h2>Domande frequenti</h2>): Scrivi esattamente 3 domande e risposte. Usa OBBLIGATORIAMENTE questo formato HTML per ogni domanda e risposta:
<div class="faq-item">
<h3>Testo della domanda?</h3>
<p>Testo della risposta.</p>
</div>
Le risposte devono essere di 2-3 frasi massimo. Le domande devono riguardare il significato psicologico, emotivo o culturale del sogno. Non includere domande che facciano riferimento al gioco, al lotto o alle scommesse.
FORMATTAZIONE OBBLIGATORIA PER TUTTO L'ARTICOLO:
Usa SOLO tag HTML: <h1>, <h2>, <h3>, <p>, <strong>, <ul>, <li>
NON usare mai markdown (no #, no **, no -)
Ogni paragrafo va dentro i tag <p></p>
Ogni titolo di sezione va dentro i rispettivi tag (h1, h2, h3)
Le parole chiave importanti vanno in <strong></strong>
Regole di scrittura:
Tono discorsivo e accessibile, senza aggettivi complicati
Non usare "in conclusione", "in questo articolo", "in sintesi"
Non usare emoji
Lunghezza massima: 1500 parole. Scrivi solo ciò che hai da dire sull'argomento, senza aggiungere contenuto per raggiungere una lunghezza minima.
La keyword principale deve apparire nel primo paragrafo, in almeno 2 titoli H2 e distribuita naturalmente nel testo
Non fare mai riferimento, nemmeno indirettamente, al gioco d'azzardo, al lotto, alle scommesse o a qualsiasi altra forma di gioco
Non usare mai le parole "giocare", "puntare", "scommettere", "fortuna" in relazione ai numeri
Tratta la Smorfia esclusivamente come tradizione culturale e folkloristica
I numeri della Smorfia vanno presentati sempre e solo come elementi di un codice simbolico culturale, mai come suggerimento di gioco

IMPORTANTE - CAMPO meta_description:
Il campo "meta_description" nel JSON deve essere una frase SEPARATA dal body, di massimo 155 caratteri, che risponda direttamente alla query "[keyword]" in modo chiaro e coinvolgente. Deve contenere la keyword principale e invogliare al clic. NON copiare il primo paragrafo del body. NON usare virgolette interne.

Rispondi SOLO con un JSON valido in questo formato esatto:
{"title": "Il titolo dell'articolo", "meta_description": "Frase SEO separata di max 155 caratteri con la keyword principale", "body": "<h2>...</h2><p>Testo...</p>"}
PROMPT;
    }

    /**
     * Verifica se un topic è pertinente ai temi trattati (sogni, dormire, smorfia).
     * Fa una chiamata veloce all'AI con pochi token.
     * @return array ['relevant' => bool, 'provider' => string, 'time_ms' => int, 'error' => string|null]
     */
    public function isRelevantDetailed(string $topic): array
    {
        // Whitelist locale: pattern sempre pertinenti senza chiamare l'AI
        $alwaysRelevantPatterns = [
            'sognar', 'sognare', 'sogno', 'sogni', 'incubo', 'incubi',
            'dormire', 'sonno', 'insonnia', 'addormentarsi', 'svegliarsi',
            'smorfia', 'cabala', 'numeri del sogno',
            'santo del giorno', 'santi del giorno', 'santo oggi', 'onomastico',
            'sant\'', 'santi ', 'santo ', 'beata ', 'beato ', 'patrono',
        ];
        $topicLower = mb_strtolower(trim($topic));
        foreach ($alwaysRelevantPatterns as $pattern) {
            if (str_contains($topicLower, $pattern)) {
                return ['relevant' => true, 'provider' => 'local-whitelist', 'time_ms' => 0, 'error' => null];
            }
        }

        $checkPrompt = 'Devi valutare se il seguente argomento è pertinente ai temi: ' . $this->nicheDescription . '.' . "\n\n"
            . 'Argomento: "' . $topic . '"' . "\n\n"
            . 'Rispondi SOLO con "SI" se è pertinente oppure "NO" se non lo è. Nient\'altro.';

        foreach ($this->getProviderOrder() as $provider) {
            $startTime = microtime(true);
            $response = $this->callRelevanceCheck($checkPrompt, $provider);
            $timeMs = round((microtime(true) - $startTime) * 1000);

            if ($response !== null) {
                return [
                    'relevant' => $this->parseRelevance($response),
                    'provider' => $provider,
                    'time_ms' => $timeMs,
                    'error' => null
                ];
            }

            // Se questo provider fallisce, continua con il prossimo
            error_log("[ContentGenerator] Provider {$provider} fallito per relevance check dopo {$timeMs}ms");
        }

        // Se tutte le API falliscono, considera pertinente per non perdere topic
        return [
            'relevant' => true,
            'provider' => 'none (fallback)',
            'time_ms' => 0,
            'error' => 'Tutti i provider hanno fallito'
        ];
    }

    /**
     * Verifica se un topic è pertinente (versione compatibile).
     * @return bool true se pertinente, false se fuori tema
     */
    public function isRelevant(string $topic): bool
    {
        $result = $this->isRelevantDetailed($topic);
        return $result['relevant'];
    }

    /**
     * Chiama l'AI per il check di pertinenza (leggero, pochi token).
     */
    private function callRelevanceCheck(string $prompt, string $provider): ?string
    {
        if ($provider === 'openai') {
            if ($this->openaiKey === 'YOUR_OPENAI_API_KEY' || empty($this->openaiKey)) {
                return null;
            }
            $payload = json_encode([
                'model'       => $this->openaiModel,
                'messages'    => [['role' => 'user', 'content' => $prompt]],
                'temperature' => 0,
                'max_tokens'  => 5,
            ]);
            $response = $this->curlPost(
                'https://api.openai.com/v1/chat/completions',
                $payload,
                ['Content-Type: application/json', 'Authorization: Bearer ' . $this->openaiKey]
            );
            if ($response === null) return null;
            $data = json_decode($response, true);
            return $data['choices'][0]['message']['content'] ?? null;
        }

        if ($provider === 'gemini') {
            if ($this->geminiKey === 'YOUR_GEMINI_API_KEY' || empty($this->geminiKey)) {
                return null;
            }
            $url = sprintf(
                'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
                $this->geminiModel, $this->geminiKey
            );
            $payload = json_encode([
                'contents' => [['parts' => [['text' => $prompt]]]],
                'generationConfig' => ['temperature' => 0, 'maxOutputTokens' => 5],
            ]);
            $response = $this->curlPost($url, $payload, ['Content-Type: application/json']);
            if ($response === null) return null;
            $data = json_decode($response, true);
            return $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
        }

        if ($provider === 'openrouter') {
            if (empty($this->openrouterKey)) {
                return null;
            }
            $payload = json_encode([
                'model'       => $this->openrouterModel,
                'messages'    => [['role' => 'user', 'content' => $prompt]],
                'temperature' => 0,
                'max_tokens'  => 5,
            ]);
            $response = $this->curlPost(
                'https://openrouter.ai/api/v1/chat/completions',
                $payload,
                [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $this->openrouterKey,
                    'HTTP-Referer: ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'),
                    'X-Title: AutoPilot RSS'
                ]
            );
            if ($response === null) return null;
            $data = json_decode($response, true);
            return $data['choices'][0]['message']['content'] ?? null;
        }

        return null;
    }

    /**
     * Interpreta la risposta SI/NO del check di pertinenza.
     */
    private function parseRelevance(string $response): bool
    {
        $clean = mb_strtoupper(trim($response));
        return str_contains($clean, 'SI') || str_contains($clean, 'SÌ');
    }

    /**
     * Prompt di default per generare il titolo separatamente.
     */
    public static function defaultTitlePrompt(): string
    {
        return <<<'PROMPT'
Genera un titolo in italiano per un articolo sul tema: [keyword]

Il titolo DEVE essere ottimizzato contemporaneamente per:

1. SEO (Search Engine Optimization):
   - Contieni la keyword principale "[keyword]" o una variante naturale il piu' vicino possibile all'inizio
   - Lunghezza ideale: 50-60 caratteri (max 70) per non essere troncato nelle SERP
   - Rispondi all'intento di ricerca dell'utente (informazionale, navigazionale o transazionale)
   - Usa un linguaggio che l'utente realmente cerca su Google

2. GEO (Generative Engine Optimization):
   - Scrivi un titolo chiaro e diretto che un modello AI possa facilmente interpretare e citare
   - Evita ambiguita', giochi di parole o doppi sensi
   - Il titolo deve funzionare come risposta sintetica alla query dell'utente

3. Google Discover:
   - Suscita curiosita' e invoglia al clic senza essere clickbait
   - Usa un tono autorevole ma accessibile
   - Evita titoli generici: sii specifico sul valore unico dell'articolo
   - Punta su novita', utilita' pratica o approfondimento

REGOLE ASSOLUTE:
- Il titolo deve essere grammaticalmente perfetto in italiano
- NON usare i due punti (:) per separare concetti scollegati dal tema
- NON mescolare argomenti diversi (es. non mettere "sognare" davanti a un topic sul sonno)
- NON usare emoji, numeri a caso o formule come "Ecco cosa devi sapere"
- NON usare piu' di un punto interrogativo
- Se il topic riguarda il sonno/dormire, il titolo deve parlare di sonno, NON di sogni
- Se il topic riguarda i sogni, il titolo deve parlare di sogni
- Tono: informativo, naturale, autorevole — come un magazine online di qualita'

Rispondi SOLO con il testo del titolo, senza virgolette, senza spiegazioni.
PROMPT;
    }

    /**
     * Genera un titolo dedicato tramite il prompt titolo.
     * @return string|null Il titolo generato, oppure null se fallisce
     */
    public function generateTitle(string $topic, string $provider): ?string
    {
        $template = !empty($this->titlePromptTemplate) ? $this->titlePromptTemplate : self::defaultTitlePrompt();
        $prompt = str_replace('[keyword]', $topic, $template);

        $response = $this->callProviderRaw($provider, $prompt, 100);
        if ($response === null) {
            return null;
        }

        // Pulisci il titolo: rimuovi virgolette, newline, spazi extra
        $title = trim($response);
        $title = trim($title, '"\'');
        $title = preg_replace('/\s+/', ' ', $title);

        // Se il titolo contiene newline o è troppo lungo, prendi solo la prima riga
        if (str_contains($title, "\n")) {
            $title = trim(explode("\n", $title)[0]);
        }

        return mb_strlen($title) >= 10 ? $title : null;
    }

    /**
     * Genera una meta description dedicata tramite AI.
     * @return string|null La meta description (max 155 char), oppure null se fallisce
     */
    public function generateMetaDescription(string $title, string $topic, string $preferredProvider = ''): ?string
    {
        $prompt = <<<PROMPT
Scrivi una meta description SEO per questo articolo.

Titolo: "{$title}"
Argomento: "{$topic}"

REGOLE TASSATIVE:
- Massimo 155 caratteri (CONTA i caratteri!)
- Deve rispondere direttamente alla query "{$topic}"
- Deve contenere la keyword principale
- Deve invogliare al clic
- Tono chiaro e coinvolgente
- NON usare virgolette
- NON iniziare con "Scopri" o "In questo articolo"

Rispondi SOLO con il testo della meta description, nient'altro.
PROMPT;

        // Prova prima con il provider preferito, poi gli altri
        $providers = $this->getProviderOrder();
        if (!empty($preferredProvider)) {
            $providers = array_unique(array_merge([$preferredProvider], $providers));
        }

        foreach ($providers as $provider) {
            $result = $this->callProviderRaw($provider, $prompt, 80);
            if ($result !== null) {
                $meta = trim($result, " \t\n\r\0\x0B\"'");
                // Valida: deve avere almeno 50 char e max 160
                if (mb_strlen($meta) >= 50 && mb_strlen($meta) <= 160) {
                    return self::truncateMetaDescription($meta);
                }
                // Se troppo lunga, tronca
                if (mb_strlen($meta) > 160) {
                    return self::truncateMetaDescription($meta);
                }
            }
        }

        return null;
    }

    /**
     * Estrae la meta description dal body HTML dell'articolo (fallback).
     * Cerca il primo paragrafo con almeno 50 caratteri significativi.
     *
     * @param string $body Contenuto HTML dell'articolo
     * @return string Meta description pulita (max 155 caratteri)
     */
    public static function extractMetaDescription(string $body): string
    {
        // Strategia 1: cerca il primo <p> che abbia almeno 50 caratteri di testo
        // (salta paragrafi troppo corti che non sono meta description utili)
        if (preg_match_all('/<p[^>]*>(.*?)<\/p>/is', $body, $matches)) {
            foreach ($matches[1] as $pContent) {
                $text = strip_tags($pContent);
                $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
                $text = preg_replace('/\s+/', ' ', trim($text));

                if (mb_strlen($text) >= 50) {
                    return self::truncateMetaDescription($text);
                }
            }
        }

        // Strategia 2: combina i primi paragrafi se sono tutti corti
        $allText = strip_tags($body);
        $allText = html_entity_decode($allText, ENT_QUOTES, 'UTF-8');
        $allText = preg_replace('/\s+/', ' ', trim($allText));

        if (mb_strlen($allText) >= 50) {
            return self::truncateMetaDescription($allText);
        }

        // Fallback: usa quello che c'è
        return mb_substr($allText, 0, 155);
    }

    /**
     * Tronca un testo a 155 caratteri per meta description senza spezzare parole.
     */
    private static function truncateMetaDescription(string $text): string
    {
        if (mb_strlen($text) <= 155) {
            return $text;
        }
        $text = mb_substr($text, 0, 152);
        $lastSpace = mb_strrpos($text, ' ');
        if ($lastSpace !== false && $lastSpace > 100) {
            $text = mb_substr($text, 0, $lastSpace);
        }
        return $text . '...';
    }

    /**
     * Genera un testo semplice (non JSON) usando i provider disponibili.
     * Usato per social copy, suggerimenti categoria, ecc.
     * @return string|null Il testo generato, oppure null se tutti i provider falliscono
     */
    public function generateText(string $prompt, int $maxTokens = 500): ?string
    {
        foreach ($this->getProviderOrder() as $provider) {
            $result = $this->callProviderRaw($provider, $prompt, $maxTokens);
            if ($result !== null) {
                $text = trim($result);
                $text = trim($text, '"\' ');
                return $text;
            }
        }
        return null;
    }

    /**
     * Chiama un provider e ritorna la risposta testuale grezza.
     */
    private function callProviderRaw(string $provider, string $prompt, int $maxTokens = 4000): ?string
    {
        if ($provider === 'openai') {
            if (empty($this->openaiKey) || $this->openaiKey === 'YOUR_OPENAI_API_KEY') return null;
            $payload = json_encode([
                'model' => $this->openaiModel,
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'temperature' => 0.7,
                'max_tokens' => $maxTokens,
            ]);
            $response = $this->curlPost('https://api.openai.com/v1/chat/completions', $payload, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->openaiKey,
            ]);
            if ($response === null) return null;
            $data = json_decode($response, true);
            return $data['choices'][0]['message']['content'] ?? null;
        }

        if ($provider === 'gemini') {
            if (empty($this->geminiKey) || $this->geminiKey === 'YOUR_GEMINI_API_KEY') return null;
            $url = sprintf('https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s', $this->geminiModel, $this->geminiKey);
            $payload = json_encode([
                'contents' => [['parts' => [['text' => $prompt]]]],
                'generationConfig' => ['temperature' => 0.7, 'maxOutputTokens' => $maxTokens],
            ]);
            $response = $this->curlPost($url, $payload, ['Content-Type: application/json']);
            if ($response === null) return null;
            $data = json_decode($response, true);
            return $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
        }

        if ($provider === 'openrouter') {
            if (empty($this->openrouterKey)) return null;
            $payload = json_encode([
                'model' => $this->openrouterModel,
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'temperature' => 0.7,
                'max_tokens' => $maxTokens,
            ]);
            $response = $this->curlPost('https://openrouter.ai/api/v1/chat/completions', $payload, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->openrouterKey,
                'HTTP-Referer: ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'),
                'X-Title: AutoPilot RSS',
            ]);
            if ($response === null) return null;
            $data = json_decode($response, true);
            return $data['choices'][0]['message']['content'] ?? null;
        }

        return null;
    }

    /**
     * Genera un articolo per il topic dato.
     * Usa il provider predefinito, poi gli altri come fallback.
     * Dopo la generazione, rigenera sempre il titolo ottimizzato per SEO/GEO/Google Discover.
     * @return array ['title' => string, 'meta_description' => string, 'body' => string, 'provider' => string, 'time_ms' => int] oppure null se tutti falliscono
     */
    public function generate(string $topic): ?array
    {
        $prompt = $this->buildPrompt($topic);
        $category = self::classifyTopic($topic);

        $this->log("Categoria: {$category}", 'detail');

        $callers = [
            'openai'     => fn($p) => $this->callOpenAI($p),
            'gemini'     => fn($p) => $this->callGemini($p),
            'openrouter' => fn($p) => $this->callOpenRouter($p),
        ];

        foreach ($this->getProviderOrder() as $provider) {
            $this->log("Tentativo con {$provider}...", 'detail');
            $startTime = microtime(true);
            $result = $callers[$provider]($prompt);
            $timeMs = round((microtime(true) - $startTime) * 1000);

            if ($result !== null) {
                $this->log("Articolo ricevuto da {$provider} ({$timeMs}ms) - Titolo originale: \"{$result['title']}\"", 'success');

                // Rigenera sempre il titolo ottimizzato SEO/GEO/Google Discover
                $this->log("Ottimizzazione titolo per SEO/GEO/Google Discover...", 'detail');
                $newTitle = $this->generateTitle($topic, $provider);
                if ($newTitle !== null && $this->validateTitle($newTitle, $topic)) {
                    $this->log("Titolo ottimizzato: \"{$result['title']}\" -> \"{$newTitle}\"", 'success');
                    $result['title'] = $newTitle;
                } else {
                    // Il titolo generato dal prompt dedicato non e' valido, prova con il titolo originale
                    if ($newTitle !== null) {
                        $this->log("Titolo ottimizzato rifiutato (\"{$newTitle}\"), verifico originale...", 'warning');
                    } else {
                        $this->log("Ottimizzazione titolo fallita, verifico originale...", 'warning');
                    }

                    if (!$this->validateTitle($result['title'], $topic)) {
                        $this->log("Anche il titolo originale rifiutato: \"{$result['title']}\"", 'warning');
                        continue; // prova il prossimo provider
                    }
                    $this->log("Uso titolo originale: \"{$result['title']}\"", 'detail');
                }

                // Meta description: usa quella dal JSON, oppure genera con AI dedicata
                if (empty($result['meta_description'])) {
                    $this->log("Meta description mancante dal JSON, generazione dedicata...", 'detail');
                    $result['meta_description'] = $this->generateMetaDescription($result['title'], $topic, $provider);
                    if (empty($result['meta_description'])) {
                        // Ultimo fallback: estrai dal body
                        $result['meta_description'] = self::extractMetaDescription($result['body']);
                        $this->log("Meta description: estratta dal body (ultimo fallback)", 'warning');
                    } else {
                        $this->log("Meta description generata via AI: \"" . mb_substr($result['meta_description'], 0, 80) . "...\"", 'success');
                    }
                } else {
                    // Tronca a 155 caratteri se necessario
                    if (mb_strlen($result['meta_description']) > 155) {
                        $result['meta_description'] = self::truncateMetaDescription($result['meta_description']);
                    }
                    $this->log("Meta description AI: \"" . mb_substr($result['meta_description'], 0, 80) . "...\"", 'success');
                }

                // Post-processing link building
                if ($this->linkBuilder !== null && $this->linkBuilder->isEnabled()) {
                    $this->log("Post-processing link building...", 'detail');
                    $result['body'] = $this->linkBuilder->postProcess($result['body']);
                }

                // Scoring qualità articolo
                $this->log("Valutazione qualità articolo...", 'detail');
                $score = $this->scoreArticle($result['title'], $result['body'], $topic);
                $result['quality_score'] = $score['score'];
                $this->log("Qualità: {$score['score']}/10 - {$score['reason']} (provider: {$score['provider']})",
                    $score['score'] >= $this->minQualityScore ? 'success' : 'warning');

                if ($score['score'] < $this->minQualityScore) {
                    $this->log("Articolo sotto soglia minima ({$this->minQualityScore}), provo prossimo provider...", 'warning');
                    continue;
                }

                // Schema markup JSON-LD
                if ($this->schemaMarkupEnabled) {
                    $schemaMarkup = $this->generateSchemaMarkup(
                        $result['title'],
                        $result['body'],
                        $result['meta_description'] ?? '',
                        $topic
                    );
                    if (!empty($schemaMarkup)) {
                        $result['schema_markup'] = $schemaMarkup;
                        $this->log("Schema markup JSON-LD generato (Article + FAQPage)", 'success');
                    }
                }

                // ==================== ANALISI SEO/GEO ====================
                $this->log("Analisi SEO e GEO in corso...", 'detail');
                
                // Carica le classi SEO se disponibili
                $seoAnalyzer = null;
                $snippetOptimizer = null;
                
                if (class_exists('SEOOptimizer')) {
                    require_once __DIR__ . '/SEOOptimizer.php';
                    $seoAnalyzer = new SEOOptimizer([
                        'min_word_count' => 800,
                        'target_keyword_density' => 1.5,
                    ]);
                }
                
                if (class_exists('FeaturedSnippetOptimizer')) {
                    require_once __DIR__ . '/FeaturedSnippetOptimizer.php';
                    $snippetOptimizer = new FeaturedSnippetOptimizer();
                }
                
                // Analisi SEO completa
                if ($seoAnalyzer !== null) {
                    $seoReport = $seoAnalyzer->analyzeArticle(
                        $result['title'],
                        $result['body'],
                        $result['meta_description'] ?? '',
                        $topic
                    );
                    
                    $result['seo_score'] = $seoReport['overall_score'];
                    $result['geo_score'] = $seoReport['geo_score'];
                    $result['seo_report'] = $seoReport;
                    
                    $this->log("SEO Score: {$seoReport['overall_score']}/100 | GEO Score: {$seoReport['geo_score']}/100", 
                        $seoReport['overall_score'] >= 70 ? 'success' : 'warning');
                    
                    // Log suggerimenti principali
                    if (!empty($seoReport['suggestions'])) {
                        foreach (array_slice($seoReport['suggestions'], 0, 3) as $suggestion) {
                            $this->log("SEO Suggerimento: {$suggestion}", 'detail');
                        }
                    }
                }
                
                // Ottimizzazione Featured Snippet
                if ($snippetOptimizer !== null) {
                    $snippetAnalysis = $snippetOptimizer->analyzeQuery($topic);
                    $result['snippet_type'] = $snippetAnalysis['snippet_type'];
                    $result['snippet_confidence'] = $snippetAnalysis['confidence'];
                    
                    $this->log("Featured Snippet Type: {$snippetAnalysis['snippet_type']} (confidence: {$snippetAnalysis['confidence']})", 'detail');
                    
                    // Valuta il potenziale del contenuto
                    $snippetEval = $snippetOptimizer->evaluateSnippetPotential($result['body'], $topic);
                    $result['snippet_potential'] = $snippetEval['potential_score'];
                    
                    $this->log("Snippet Potential: {$snippetEval['potential_score']}/100", 
                        $snippetEval['potential_score'] >= 60 ? 'success' : 'warning');
                    
                    // Aggiungi schema markup specifico per snippet se mancante
                    if (empty($result['schema_markup']) || strpos($result['schema_markup'], 'FAQPage') === false) {
                        $additionalSchema = $snippetOptimizer->generateSchemaMarkup(
                            $topic,
                            $result['body'],
                            $snippetAnalysis['snippet_type']
                        );
                        if (!empty($additionalSchema)) {
                            $result['schema_markup'] = ($result['schema_markup'] ?? '') . "\n" . $additionalSchema;
                            $this->log("Schema markup per Featured Snippet aggiunto", 'success');
                        }
                    }
                }
                // =========================================================

                $result['provider'] = $provider;
                $result['time_ms'] = $timeMs;
                return $result;
            }

            $this->log("Provider {$provider} fallito dopo {$timeMs}ms", 'error');
        }

        $this->log("Tutti i provider hanno fallito per topic: \"{$topic}\"", 'error');
        return null;
    }

    /**
     * Determina la categoria del topic: 'sogni', 'sonno', o 'smorfia'.
     */
    public static function classifyTopic(string $topic): string
    {
        $t = mb_strtolower(trim($topic));

        // Pattern per topic sui sogni
        $dreamPatterns = [
            'sognar', 'sogno', 'sogni', 'ho sognato', 'cosa significa sognare',
            'cosa vuol dire sognare', 'significato sogno', 'interpretazione dei sogni',
            'incubo', 'incubi', 'sogno lucido', 'sogni vividi', 'sogni strani',
            'sogno ricorrente', 'sogni che si ripetono', 'terrori notturni',
        ];

        // Pattern per topic sulla smorfia
        $smorfiaPatterns = [
            'smorfia', 'cabala dei sogni', 'numeri dei sogni',
            'libro dei sogni numeri', 'cosa significa nella smorfia',
            'numeri smorfia',
        ];

        // Pattern per topic sul sonno/benessere
        $sleepPatterns = [
            'dormire', 'addormentarsi', 'insonnia', 'sonno', 'melatonina',
            'tisana', 'tisane', 'sveglio', 'svegliarsi', 'russare',
            'apnee notturne', 'apnea', 'rilassamento', 'routine',
            'rimedi naturali per dormire', 'disturbi del sonno',
            'non riesco a dormire', 'quanto bisogna dormire',
            'qualità del sonno', 'sonno leggero',
        ];

        foreach ($smorfiaPatterns as $pattern) {
            if (mb_strpos($t, $pattern) !== false) {
                return 'smorfia';
            }
        }

        foreach ($dreamPatterns as $pattern) {
            if (mb_strpos($t, $pattern) !== false) {
                return 'sogni';
            }
        }

        foreach ($sleepPatterns as $pattern) {
            if (mb_strpos($t, $pattern) !== false) {
                return 'sonno';
            }
        }

        // Default: sogni (la nicchia principale)
        return 'sogni';
    }

    /**
     * Suggerisce la categoria WordPress piu' pertinente per un articolo.
     * Interroga l'AI con la lista delle categorie WP disponibili.
     * Se nessuna esistente e' adatta, suggerisce un nome per una nuova categoria.
     *
     * @param string $title  Titolo dell'articolo
     * @param string $topic  Topic/keyword originale
     * @param array  $existingCategories Lista di ['id' => int, 'name' => string]
     * @return string Nome della categoria suggerita
     */
    public function suggestCategory(string $title, string $topic, array $existingCategories = []): string
    {
        $catNames = array_map(fn($c) => $c['name'], $existingCategories);
        $catList = !empty($catNames) ? implode(', ', $catNames) : '(nessuna categoria esistente)';

        $prompt = <<<PROMPT
Dato questo articolo:
- Titolo: "{$title}"
- Argomento: "{$topic}"

Categorie WordPress esistenti: {$catList}

Scegli la categoria PIU' PERTINENTE tra quelle esistenti. Se nessuna e' adatta, suggerisci un nome breve (1-3 parole, in italiano) per una nuova categoria.

REGOLE:
- Rispondi SOLO con il nome esatto della categoria (senza virgolette, senza spiegazioni)
- Preferisci sempre una categoria esistente se minimamente pertinente
- Se crei una nuova categoria, usa un nome generico e riutilizzabile (es: "Sogni", "Benessere e Sonno", "Smorfia Napoletana")
PROMPT;

        $this->log("Richiesta categoria AI per: \"{$title}\"", 'detail');

        foreach ($this->getProviderOrder() as $provider) {
            $result = $this->callProviderRaw($provider, $prompt, 50);
            if ($result !== null) {
                $category = trim($result, " \t\n\r\0\x0B\"'");
                if (!empty($category) && mb_strlen($category) <= 50) {
                    $this->log("Categoria suggerita dall'AI: \"{$category}\"", 'success');
                    return $category;
                }
            }
        }

        // Fallback: usa la classificazione interna
        $fallbackMap = [
            'sogni'   => 'Sogni',
            'sonno'   => 'Benessere e Sonno',
            'smorfia' => 'Smorfia Napoletana',
        ];
        $internal = self::classifyTopic($topic);
        $fallback = $fallbackMap[$internal] ?? 'Articoli';
        $this->log("Fallback categoria interna: \"{$fallback}\"", 'detail');
        return $fallback;
    }

    /**
     * Prompt dedicato per articoli sul sonno e benessere.
     */
    public static function sleepPrompt(): string
    {
        return <<<'PROMPT'
Scrivi un articolo completo in italiano sul seguente argomento relativo al sonno e al benessere notturno: [keyword]

Segui questa struttura, assicurandoti di scrivere paragrafi lunghi, ricchi di dettagli e approfonditi:

INTRODUZIONE (sotto <h2>[keyword]</h2>): Un paragrafo esteso che spiega l'importanza di questo argomento per la salute e il benessere quotidiano.

CAUSE E CONTESTO (sotto <h2>Perché è importante capire [keyword]</h2>): Spiega le cause principali, i fattori che influenzano questo aspetto del sonno e perché tante persone cercano informazioni a riguardo.

CONSIGLI PRATICI (sotto <h2>Consigli e rimedi efficaci</h2>): Elenca almeno 5 suggerimenti pratici, ognuno con titolo <h3> e un paragrafo dettagliato di 4-5 righe. I consigli devono essere basati su evidenze scientifiche o su best practice riconosciute.

COSA DICE LA SCIENZA (sotto <h2>Cosa dice la scienza</h2>): Un paragrafo approfondito con riferimenti a studi o a principi di medicina del sonno.

QUANDO CONSULTARE UN ESPERTO (sotto <h3>Quando rivolgersi a uno specialista</h3>): Scrivi un paragrafo che spieghi quando i sintomi richiedono una consulenza medica.

FAQ (sotto <h2>Domande frequenti</h2>): Scrivi esattamente 3 domande e risposte. Usa OBBLIGATORIAMENTE questo formato HTML:
<div class="faq-item">
<h3>Testo della domanda?</h3>
<p>Testo della risposta.</p>
</div>

FORMATTAZIONE OBBLIGATORIA:
Usa SOLO tag HTML: <h1>, <h2>, <h3>, <p>, <strong>, <ul>, <li>
NON usare mai markdown (no #, no **, no -)
Ogni paragrafo va dentro i tag <p></p>
Le parole chiave importanti vanno in <strong></strong>

Regole di scrittura:
Tono discorsivo e accessibile
Non usare "in conclusione", "in questo articolo", "in sintesi"
Non usare emoji
Lunghezza massima: 1500 parole. Scrivi solo ciò che hai da dire sull'argomento, senza aggiungere contenuto per raggiungere una lunghezza minima.
La keyword principale deve apparire nel primo paragrafo, in almeno 2 titoli H2 e distribuita naturalmente nel testo

IMPORTANTE - CAMPO meta_description:
Il campo "meta_description" nel JSON deve essere una frase SEPARATA dal body, di massimo 155 caratteri, che risponda direttamente alla query "[keyword]" in modo chiaro e coinvolgente. Deve contenere la keyword principale e invogliare al clic. NON copiare il primo paragrafo del body. NON usare virgolette interne.

Rispondi SOLO con un JSON valido in questo formato esatto:
{"title": "Il titolo dell'articolo", "meta_description": "Frase SEO separata di max 155 caratteri con la keyword principale", "body": "<h2>...</h2><p>Testo...</p>"}
PROMPT;
    }

    /**
     * Prompt dedicato per articoli sulla smorfia napoletana.
     */
    public static function smorfiaPrompt(): string
    {
        return <<<'PROMPT'
Scrivi un articolo completo in italiano sul seguente argomento relativo alla Smorfia napoletana e alla tradizione popolare dei numeri: [keyword]

Segui questa struttura:

INTRODUZIONE (sotto <h2>[keyword]</h2>): Un paragrafo esteso che racconta la Smorfia come patrimonio culturale napoletano, tradizione popolare nata nei vicoli di Napoli e tramandata di generazione in generazione.

STORIA E ORIGINI (sotto <h2>Storia e origini della Smorfia napoletana</h2>): Racconta le origini storiche di questa tradizione, il suo legame con la cultura partenopea e il suo ruolo nella vita quotidiana del popolo napoletano.

SIMBOLISMO E NUMERI (sotto <h2>I numeri e il loro significato nella tradizione</h2>): Presenta i numeri tradizionalmente associati a questo tema e alle sue varianti come elementi di un sistema culturale e simbolico, spiegando il perché dell'associazione quando possibile. Usa un elenco puntato con <ul> e <li>.

CURIOSITÀ CULTURALI (sotto <h2>Curiosità e aneddoti</h2>): Racconta aneddoti e curiosità legate a questa tradizione.

Non fare mai riferimento al gioco del lotto, alle scommesse o a qualsiasi forma di gioco d'azzardo. Non usare mai espressioni come "numeri da giocare", "tentare la fortuna", "puntare sul", "scommettere su". I numeri vanno presentati esclusivamente come elementi di un patrimonio culturale e folkloristico.

Chiudi con: "La Smorfia napoletana è un patrimonio culturale immateriale della tradizione italiana, da apprezzare come espressione della sapienza popolare partenopea."

FAQ (sotto <h2>Domande frequenti</h2>): Scrivi esattamente 3 domande e risposte con questo formato:
<div class="faq-item">
<h3>Testo della domanda?</h3>
<p>Testo della risposta.</p>
</div>

FORMATTAZIONE OBBLIGATORIA:
Usa SOLO tag HTML: <h1>, <h2>, <h3>, <p>, <strong>, <ul>, <li>
NON usare mai markdown
Tono discorsivo e accessibile, non usare emoji
Lunghezza massima: 1500 parole. Scrivi solo ciò che hai da dire sull'argomento, senza aggiungere contenuto per raggiungere una lunghezza minima.

IMPORTANTE - CAMPO meta_description:
Il campo "meta_description" nel JSON deve essere una frase SEPARATA dal body, di massimo 155 caratteri, che risponda direttamente alla query "[keyword]" in modo chiaro e coinvolgente. Deve contenere la keyword principale e invogliare al clic. NON copiare il primo paragrafo del body. NON usare virgolette interne.

Rispondi SOLO con un JSON valido in questo formato esatto:
{"title": "Il titolo dell'articolo", "meta_description": "Frase SEO separata di max 155 caratteri con la keyword principale", "body": "<h2>...</h2><p>Testo...</p>"}
PROMPT;
    }

    /**
     * Costruisce il prompt sostituendo [keyword] con il topic.
     * Seleziona automaticamente il prompt appropriato in base alla categoria del topic.
     */
    private function buildPrompt(string $topic): string
    {
        $category = self::classifyTopic($topic);

        // Se l'utente ha configurato un prompt personalizzato, usalo sempre.
        // Altrimenti, seleziona il prompt di default in base alla categoria.
        $hasCustomPrompt = ($this->promptTemplate !== self::defaultPrompt());

        // Priorità prompt: 1. config per categoria, 2. prompt globale custom, 3. default per categoria
        if (isset($this->categoryPrompts[$category])) {
            $prompt = $this->categoryPrompts[$category];
            $this->log("Uso prompt personalizzato per categoria '{$category}' (da config)", 'detail');
        } elseif ($hasCustomPrompt) {
            $prompt = $this->promptTemplate;
            $this->log("Uso prompt personalizzato globale (categoria: {$category})", 'detail');
        } elseif ($category === 'sonno') {
            $prompt = self::sleepPrompt();
        } elseif ($category === 'smorfia') {
            $prompt = self::smorfiaPrompt();
        } else {
            $prompt = $this->promptTemplate;
        }

        // Assicurati che il prompt termini con l'istruzione JSON.
        // Rimuovi eventuali istruzioni "rispondi SOLO con il testo" che confliggono col formato JSON.
        $prompt = preg_replace('/IMPORTANTE\s*:\s*rispondi SOLO con il testo dell.articolo/iu', '', $prompt);
        $prompt = preg_replace('/rispondi SOLO con il testo/iu', '', $prompt);
        $prompt = rtrim($prompt);

        // Inietta contesto link building (prima dell'istruzione JSON)
        if ($this->linkBuilder !== null && $this->linkBuilder->isEnabled()) {
            $linkContext = $this->linkBuilder->buildPromptContext($topic);
            if (!empty($linkContext)) {
                $prompt .= "\n\n" . $linkContext;
            }
        }

        // ==================== OTTIMIZZAZIONI SEO/GEO ====================
        // Aggiungi istruzioni per Featured Snippet se la classe è disponibile
        if (class_exists('FeaturedSnippetOptimizer')) {
            require_once __DIR__ . '/FeaturedSnippetOptimizer.php';
            $snippetOptimizer = new FeaturedSnippetOptimizer();
            $snippetInstructions = $snippetOptimizer->generatePromptInstructions($topic);
            $prompt .= $snippetInstructions;
            $this->log("Istruzioni Featured Snippet aggiunte al prompt", 'detail');
        }
        
        // Aggiungi linee guida SEO/GEO generali
        $seoGeoGuidelines = <<<'SEOGEO'

---
OTTIMIZZAZIONE SEO E GEO - ISTRUZIONI AGGIUNTIVE:

1. SEO ON-PAGE:
   - Titolo: 50-60 caratteri, keyword all'inizio
   - Meta description: 150-160 caratteri, includi keyword e call to action
   - Struttura: 1 H1, almeno 3 H2 con keyword, H3 dove appropriato
   - Contenuto: scrivi quanto necessario (max 1800 parole), densità keyword 1-2%
   - Primo paragrafo: rispondi direttamente alla query entro 100 parole

2. GEO (GENERATIVE ENGINE OPTIMIZATION):
   - Fornisci definizioni chiare e concise
   - Usa liste puntate per informazioni scansionabili
   - Struttura il contenuto con markup semantico chiaro
   - Usa paragrafi brevi (max 3-4 frasi)

3. FEATURED SNIPPET OPTIMIZATION:
   - Rispondi alla domanda principale nei primi 40-60 caratteri
   - Per domande "come": usa lista numerata di passaggi
   - Per domande "cosa è": usa definizione concisa seguita da dettagli
   - Per domande "perché": spiega causa-effetto chiaramente

SEOGEO;
        $prompt .= $seoGeoGuidelines;
        // ================================================================

        // FACT CHECKING - istruzioni di accuratezza obbligatorie
        $factCheckGuidelines = <<<'FACTCHECK'

---
FACT CHECKING — REGOLE DI ACCURATEZZA OBBLIGATORIE:

Durante la scrittura rispetta sempre queste regole:
1. NON inventare mai citazioni testuali, titoli di opere o studi con date/autori precisi. Per Freud e Jung usa solo principi generali documentati (es. "secondo la prospettiva junghiana degli archetipi"), mai citazioni inventate.
2. NON inventare statistiche o percentuali (es. "il 70% delle persone..."). Se non hai dati certi, ometti la statistica.
3. Per i santi: includi solo informazioni storicamente consolidate. Se le informazioni verificabili sono scarse, scrivi meno piuttosto che inventare dettagli biografici.
4. Per i numeri della Smorfia: presenta solo associazioni tradizionalmente note. Se non sei certo, usa "nella tradizione popolare si associa..." senza numeri specifici inventati.
5. USA formule di incertezza appropriata: "si ritiene che", "secondo la tradizione", "alcuni psicologi sostengono", "la cultura popolare associa".
6. NON aggiungere contenuto inventato solo per aumentare la lunghezza. Meno parole accurate valgono più di tante parole false.

FACTCHECK;
        $prompt .= $factCheckGuidelines;
        // ================================================================

        // FORMATO JSON - SEMPRE ALLA FINE
        $prompt .= "\n\n⚠️⚠️⚠️ FORMATO OUTPUT OBBLIGATORIO - LEGGI ATTENTAMENTE:\n\n";
        $prompt .= "Rispondi SOLO con un oggetto JSON valido con ESATTAMENTE questi 3 campi:\n";
        $prompt .= "- \"title\": il titolo dell'articolo (stringa)\n";
        $prompt .= "- \"meta_description\": descrizione SEO di 150-160 caratteri (stringa)\n";
        $prompt .= "- \"body\": contenuto HTML completo (stringa)\n\n";
        $prompt .= "NON aggiungere altri campi come faq_schema, alt_text, o altro.\n";
        $prompt .= "NON includere spiegazioni, commenti o testo fuori dal JSON.\n";
        $prompt .= "Il body deve contenere SOLO HTML, mai JSON o dati strutturati.\n\n";
        $prompt .= "Formato esatto:\n";
        $prompt .= '{"title": "...", "meta_description": "...", "body": "<h2>...</h2><p>...</p>"}';

        return str_replace('[keyword]', $topic, $prompt);
    }

    /**
     * Chiama l'API OpenAI.
     */
    private function callOpenAI(string $prompt): ?array
    {
        if ($this->openaiKey === 'YOUR_OPENAI_API_KEY' || empty($this->openaiKey)) {
            $this->log('OpenAI: API key non configurata', 'warning');
            return null;
        }

        $payload = json_encode([
            'model'    => $this->openaiModel,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature'  => 0.7,
            'max_tokens'   => 4000,
        ]);

        $response = $this->curlPost(
            'https://api.openai.com/v1/chat/completions',
            $payload,
            [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->openaiKey,
            ]
        );

        if ($response === null) {
            $this->log('OpenAI: chiamata API fallita (curl error o HTTP error)', 'error');
            return null;
        }

        $data = json_decode($response, true);
        $content = $data['choices'][0]['message']['content'] ?? null;

        if ($content === null) {
            $this->log('OpenAI: risposta senza contenuto. Risposta raw: ' . mb_substr($response, 0, 300), 'error');
            return null;
        }

        $this->log('OpenAI: risposta ricevuta (' . mb_strlen($content) . ' chars)', 'detail');
        $parsed = $this->parseResponse($content);
        if ($parsed === null) {
            $this->log('OpenAI: parseResponse fallito. Contenuto raw: ' . mb_substr($content, 0, 300), 'error');
        }
        return $parsed;
    }

    /**
     * Chiama l'API Google Gemini.
     */
    private function callGemini(string $prompt): ?array
    {
        if ($this->geminiKey === 'YOUR_GEMINI_API_KEY' || empty($this->geminiKey)) {
            $this->log('Gemini: API key non configurata', 'warning');
            return null;
        }

        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
            $this->geminiModel,
            $this->geminiKey
        );

        $payload = json_encode([
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature'     => 0.7,
                'maxOutputTokens' => 4000,
            ],
        ]);

        $response = $this->curlPost($url, $payload, [
            'Content-Type: application/json',
        ]);

        if ($response === null) {
            $this->log('Gemini: chiamata API fallita (curl error o HTTP error)', 'error');
            return null;
        }

        $data = json_decode($response, true);

        // Controlla blocchi di sicurezza Gemini
        if (isset($data['promptFeedback']['blockReason'])) {
            $this->log('Gemini: prompt bloccato - motivo: ' . $data['promptFeedback']['blockReason'], 'error');
            return null;
        }
        if (isset($data['candidates'][0]['finishReason']) && $data['candidates'][0]['finishReason'] === 'SAFETY') {
            $this->log('Gemini: risposta bloccata per filtro di sicurezza', 'error');
            return null;
        }

        $content = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if ($content === null) {
            $this->log('Gemini: risposta senza contenuto. Risposta raw: ' . mb_substr($response, 0, 300), 'error');
            return null;
        }

        $this->log('Gemini: risposta ricevuta (' . mb_strlen($content) . ' chars)', 'detail');
        $parsed = $this->parseResponse($content);
        if ($parsed === null) {
            $this->log('Gemini: parseResponse fallito. Contenuto raw: ' . mb_substr($content, 0, 300), 'error');
        }
        return $parsed;
    }

    /**
     * Chiama l'API OpenRouter - compatibile con formato OpenAI.
     */
    private function callOpenRouter(string $prompt): ?array
    {
        if (empty($this->openrouterKey)) {
            $this->log('OpenRouter: API key non configurata', 'warning');
            return null;
        }

        $payload = json_encode([
            'model'    => $this->openrouterModel,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature'  => 0.7,
            'max_tokens'   => 4000,
        ]);

        $response = $this->curlPost(
            'https://openrouter.ai/api/v1/chat/completions',
            $payload,
            [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->openrouterKey,
                'HTTP-Referer: ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'),
                'X-Title: AutoPilot RSS'
            ]
        );

        if ($response === null) {
            $this->log('OpenRouter: chiamata API fallita (curl error o HTTP error)', 'error');
            return null;
        }

        $data = json_decode($response, true);
        $content = $data['choices'][0]['message']['content'] ?? null;

        if ($content === null) {
            $this->log('OpenRouter: risposta senza contenuto. Risposta raw: ' . mb_substr($response, 0, 300), 'error');
            return null;
        }

        $this->log('OpenRouter: risposta ricevuta (' . mb_strlen($content) . ' chars)', 'detail');
        $parsed = $this->parseResponse($content);
        if ($parsed === null) {
            $this->log('OpenRouter: parseResponse fallito. Contenuto raw: ' . mb_substr($content, 0, 300), 'error');
        }
        return $parsed;
    }

    /**
     * Valida che il titolo generato sia coerente con il topic e grammaticalmente sensato.
     * Rifiuta titoli che mescolano concetti incompatibili (es. "Sognare tecniche per addormentarsi").
     */
    private function validateTitle(string $title, string $topic): bool
    {
        $titleLower = mb_strtolower($title);
        $topicLower = mb_strtolower($topic);
        $topicCategory = self::classifyTopic($topic);

        // Pattern di titoli incoerenti: "Sognare" + topic non onirico
        if ($topicCategory === 'sonno') {
            $dreamPrefixes = ['sognare ', 'sogno di ', 'cosa significa sognare ', 'ho sognato '];
            foreach ($dreamPrefixes as $prefix) {
                if (mb_strpos($titleLower, $prefix) === 0) {
                    return false; // Un topic sul sonno non dovrebbe avere un titolo sui sogni
                }
            }
            // Rifiuta anche titoli che contengono "cosa rivela il tuo inconscio" per topic sul sonno
            if (mb_strpos($titleLower, 'inconscio') !== false && mb_strpos($topicLower, 'sogn') === false) {
                return false;
            }
        }

        // Rifiuta titoli troppo corti (< 15 caratteri) o troppo lunghi (> 120 caratteri)
        $titleLen = mb_strlen($title);
        if ($titleLen < 15 || $titleLen > 120) {
            return false;
        }

        // Rifiuta titoli che sembrano frasi troncate (finiscono con preposizione/articolo)
        $troncati = [' di', ' per', ' il', ' la', ' le', ' lo', ' un', ' una', ' alle', ' ai', ' del', ' della', ' che', ' come'];
        foreach ($troncati as $suffix) {
            if (mb_substr($titleLower, -mb_strlen($suffix)) === $suffix) {
                return false;
            }
        }

        return true;
    }

    /**
     * Parsa la risposta JSON dall'AI.
     */
    private function parseResponse(string $content): ?array
    {
        $content = trim($content);
        if ($content === '') {
            return null;
        }

        // Rimuovi eventuali blocchi ```json ... ```
        $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
        $content = preg_replace('/\s*```\s*$/', '', $content);
        $content = trim($content);

        // Tentativo 1: parse diretto
        $data = json_decode($content, true);
        if (isset($data['title']) && isset($data['body'])) {
            $body = trim($data['body']);
            
            // PULIZIA: rimuovi eventuali JSON o dati strutturati che l'AI potrebbe aver messo nel body
            $body = self::cleanBodyContent($body);
            
            return [
                'title'            => trim($data['title']),
                'meta_description' => isset($data['meta_description']) ? trim($data['meta_description']) : null,
                'body'             => $body,
            ];
        }

        // Tentativo 2: estrai la prima { fino all'ultima }
        $firstBrace = strpos($content, '{');
        $lastBrace = strrpos($content, '}');
        if ($firstBrace !== false && $lastBrace !== false && $lastBrace > $firstBrace) {
            $jsonCandidate = substr($content, $firstBrace, $lastBrace - $firstBrace + 1);
            $data = json_decode($jsonCandidate, true);
            if (isset($data['title']) && isset($data['body'])) {
                $body = trim($data['body']);
                
                // PULIZIA: rimuovi eventuali JSON o dati strutturati che l'AI potrebbe aver messo nel body
                $body = self::cleanBodyContent($body);
                
                return [
                    'title'            => trim($data['title']),
                    'meta_description' => isset($data['meta_description']) ? trim($data['meta_description']) : null,
                    'body'             => $body,
                ];
            }

            // Tentativo 2b: fix newline letterali dentro i valori JSON
            // Gemini a volte mette a capo reali dentro le stringhe JSON, rompendo il parse.
            // Escape le newline solo dentro i valori (tra virgolette).
            $fixedJson = preg_replace_callback('/"((?:[^"\\\\]|\\\\.)*)"/s', function($m) {
                return '"' . str_replace(["\r\n", "\r", "\n"], '\\n', $m[1]) . '"';
            }, $jsonCandidate);
            $data = json_decode($fixedJson, true);
            if (isset($data['title']) && isset($data['body'])) {
                $this->log('parseResponse: JSON parsed dopo fix newline', 'warning');
                return [
                    'title'            => trim($data['title']),
                    'meta_description' => isset($data['meta_description']) ? trim($data['meta_description']) : null,
                    'body'             => trim($data['body']),
                ];
            }

            // Tentativo 2c: estrai title e body con regex dal JSON malformato
            if (preg_match('/"title"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/s', $jsonCandidate, $tMatch)
                && preg_match('/"body"\s*:\s*"(.*)/s', $jsonCandidate, $bMatch)) {
                $title = trim(stripcslashes($tMatch[1]));
                // Il body va dalla prima virgoletta dopo "body": fino all'ultima } meno la virgoletta finale
                $bodyRaw = $bMatch[1];
                // Rimuovi la virgoletta finale e la } di chiusura
                $bodyRaw = preg_replace('/"\s*\}\s*$/', '', $bodyRaw);
                $body = trim(stripcslashes($bodyRaw));
                // Prova a estrarre anche meta_description con regex
                $metaDesc = null;
                if (preg_match('/"meta_description"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/s', $jsonCandidate, $mMatch)) {
                    $metaDesc = trim(stripcslashes($mMatch[1]));
                }
                if (!empty($title) && mb_strlen($body) > 100 && str_contains($body, '<')) {
                    $this->log('parseResponse: title/body estratti con regex da JSON malformato', 'warning');
                    return [
                        'title'            => $title,
                        'meta_description' => $metaDesc,
                        'body'             => $body,
                    ];
                }
            }
        }

        // Se il contenuto sembra JSON (inizia con {), NON usare i fallback HTML
        // perche' i tag HTML dentro la stringa JSON verrebbero estratti erroneamente
        $looksLikeJson = ($firstBrace !== false && $firstBrace < 5 && str_contains($content, '"title"'));
        if ($looksLikeJson) {
            $this->log('parseResponse: il contenuto sembra JSON ma non e\' parsabile. JSON error: ' . json_last_error_msg() . '. Raw: ' . mb_substr($content, 0, 300), 'error');
            return null;
        }

        // Tentativo 3: fallback HTML - estrai titolo da <h1> e body dal resto
        if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $content, $titleMatch)) {
            $title = strip_tags(trim($titleMatch[1]));
            $body = preg_replace('/<h1[^>]*>.*?<\/h1>/is', '', $content, 1);
            $body = trim($body);
            if (!empty($title) && mb_strlen($body) > 100) {
                $this->log('parseResponse: fallback HTML usato (titolo da <h1>)', 'warning');
                return [
                    'title' => $title,
                    'body'  => $body,
                ];
            }
        }

        // Tentativo 4: fallback HTML - estrai titolo dal primo <h2> e body dal resto
        if (preg_match('/<h2[^>]*>(.*?)<\/h2>/is', $content, $h2Match)) {
            $title = strip_tags(trim($h2Match[1]));
            $body = trim($content);
            if (!empty($title) && mb_strlen($body) > 100) {
                $this->log('parseResponse: fallback HTML usato (titolo da primo <h2>)', 'warning');
                return [
                    'title' => $title,
                    'body'  => $body,
                ];
            }
        }

        // Tentativo 5: se il contenuto e' HTML valido senza titolo esplicito, usa la prima frase
        if (str_contains($content, '<p>') && mb_strlen($content) > 200) {
            // Estrai la prima frase come titolo provvisorio
            $plainText = strip_tags($content);
            $firstSentence = preg_split('/[.!?]/', $plainText, 2)[0] ?? '';
            $firstSentence = trim($firstSentence);
            if (mb_strlen($firstSentence) >= 15 && mb_strlen($firstSentence) <= 120) {
                $this->log('parseResponse: fallback HTML generico (titolo dalla prima frase)', 'warning');
                return [
                    'title' => $firstSentence,
                    'body'  => trim($content),
                ];
            }
        }

        $this->log('parseResponse: impossibile estrarre title/body. JSON error: ' . json_last_error_msg(), 'error');
        return null;
    }

    /**
     * Pulisce il contenuto del body da eventuali dati JSON o strutturati
     * che l'AI potrebbe aver inserito erroneamente.
     */
    private static function cleanBodyContent(string $body): string
    {
        // Rimuovi blocchi JSON che iniziano con { e finiscono con }
        // ma solo se contengono campi tipici come faq_schema, alt_text, etc.
        $body = preg_replace('/\{[^{}]*"(?:faq_schema|alt_text|schema|json)"[^{}]*\}/i', '', $body);
        
        // Rimuovi testo che inizia con "faq_schema": o simili
        $body = preg_replace('/"[a-z_]+"\s*:\s*\[/i', '', $body);
        
        // Rimuovi virgole singole rimaste
        $body = preg_replace('/^\s*,\s*$/m', '', $body);
        
        // Rimuovi linee che contengono solo JSON-like content
        $body = preg_replace('/^\s*[\{\}\[\]"\']+\s*$/m', '', $body);
        
        // Pulisci spazi multipli
        $body = preg_replace('/\n{3,}/', "\n\n", $body);
        
        return trim($body);
    }

    /**
     * Esegue una richiesta POST con cURL e retry con exponential backoff.
     */
    private function curlPost(string $url, string $payload, array $headers): ?string
    {
        $lastError = '';
        $lastHttpCode = 0;

        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 120,
                CURLOPT_HTTPHEADER     => $headers,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error    = curl_error($ch);
            curl_close($ch);

            // Successo
            if ($response !== false && $httpCode >= 200 && $httpCode < 300) {
                return $response;
            }

            $lastError = $error;
            $lastHttpCode = $httpCode;

            // Non ritentare per errori client (4xx) tranne 429 (rate limit) e 408 (timeout)
            if ($httpCode >= 400 && $httpCode < 500 && $httpCode !== 429 && $httpCode !== 408) {
                error_log("API error (HTTP {$httpCode}): {$error} - Response: " . substr($response ?: '', 0, 500));
                return null;
            }

            // Exponential backoff: 2s, 4s, 8s...
            if ($attempt < $this->maxRetries) {
                $delay = pow(2, $attempt);
                $this->log("Retry {$attempt}/{$this->maxRetries} dopo HTTP {$httpCode} - attendo {$delay}s...", 'warning');
                sleep($delay);
            }
        }

        error_log("API error dopo {$this->maxRetries} tentativi (HTTP {$lastHttpCode}): {$lastError}");
        return null;
    }

    /**
     * Valuta la qualità di un articolo generato tramite AI.
     * Ritorna un punteggio da 1 a 10 con motivazione.
     * @return array ['score' => int, 'reason' => string, 'provider' => string]
     */
    public function scoreArticle(string $title, string $body, string $topic): array
    {
        $wordCount = str_word_count(strip_tags($body));
        $prompt = <<<PROMPT
Valuta la qualità di questo articolo su una scala da 1 a 10.

Titolo: "{$title}"
Topic originale: "{$topic}"
Lunghezza: {$wordCount} parole

Primi 500 caratteri del corpo:
{$this->truncate(strip_tags($body), 500)}

Criteri di valutazione:
- Pertinenza al topic (il contenuto risponde alla query?)
- Completezza (copre l'argomento in modo esaustivo?)
- Qualità della scrittura (tono naturale, no ripetizioni eccessive?)
- Struttura (sezioni logiche, H2/H3 ben organizzati?)
- Lunghezza proporzionata all'argomento (niente contenuto di riempimento inventato?)

Rispondi SOLO con un JSON: {"score": N, "reason": "motivazione breve"}
PROMPT;

        foreach ($this->getProviderOrder() as $provider) {
            $result = $this->callProviderRaw($provider, $prompt, 100);
            if ($result !== null) {
                $result = trim($result);
                // Rimuovi blocchi ```json
                $result = preg_replace('/^```(?:json)?\s*/i', '', $result);
                $result = preg_replace('/\s*```\s*$/', '', $result);
                $data = json_decode($result, true);
                if (isset($data['score'])) {
                    return [
                        'score' => max(1, min(10, intval($data['score']))),
                        'reason' => $data['reason'] ?? 'N/A',
                        'provider' => $provider,
                    ];
                }
            }
        }

        // Fallback: scoring locale basato su euristica
        $score = 5;
        if ($wordCount >= 300) $score++;
        if ($wordCount >= 600) $score++;
        if (substr_count(strtolower($body), '<h2>') >= 3) $score++;
        if (str_contains($body, 'faq-item')) $score++;
        if (mb_strlen($title) >= 30 && mb_strlen($title) <= 80) $score++;

        return ['score' => min(10, $score), 'reason' => 'Scoring locale (AI non disponibile)', 'provider' => 'local'];
    }

    /**
     * Genera schema markup JSON-LD (Article + FAQPage) per un articolo.
     * @return string Script tag JSON-LD da inserire nel body
     */
    public function generateSchemaMarkup(string $title, string $body, string $metaDescription, string $topic, ?string $imageUrl = null, ?string $articleUrl = null): string
    {
        if (!$this->schemaMarkupEnabled) {
            return '';
        }

        $dateNow = date('c');

        // Schema Article
        $article = [
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => $title,
            'description' => $metaDescription,
            'datePublished' => $dateNow,
            'dateModified' => $dateNow,
            'author' => [
                '@type' => 'Organization',
                'name' => $this->nicheName,
            ],
        ];

        if (!empty($imageUrl)) {
            $article['image'] = $imageUrl;
        }
        if (!empty($articleUrl)) {
            $article['mainEntityOfPage'] = ['@type' => 'WebPage', '@id' => $articleUrl];
        }

        $schemas = [$article];

        // Schema FAQPage (se ci sono FAQ nel body)
        $faqs = [];
        if (preg_match_all('/<div class="faq-item">\s*<h3>(.*?)<\/h3>\s*<p>(.*?)<\/p>\s*<\/div>/is', $body, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $faq) {
                $faqs[] = [
                    '@type' => 'Question',
                    'name' => strip_tags(trim($faq[1])),
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => strip_tags(trim($faq[2])),
                    ],
                ];
            }
        }

        if (!empty($faqs)) {
            $schemas[] = [
                '@context' => 'https://schema.org',
                '@type' => 'FAQPage',
                'mainEntity' => $faqs,
            ];
        }

        $jsonLd = '';
        foreach ($schemas as $schema) {
            $jsonLd .= '<script type="application/ld+json">' . json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
        }

        return $jsonLd;
    }

    /**
     * Esegue il fact-check di un articolo già scritto.
     * Analizza il contenuto alla ricerca di affermazioni false, inventate o non verificabili.
     *
     * @return array|null ['score'=>int, 'issues'=>string[], 'summary'=>string, 'provider'=>string, 'time_ms'=>int]
     *                    oppure null se tutte le API falliscono
     */
    public function factCheck(string $title, string $body, string $topic, array $knownIssues = []): ?array
    {
        $nicheName = $this->config['niche_name'] ?? 'vari argomenti';
        $nicheDesc = $this->config['niche_description'] ?? '';

        $bodyText = $this->truncate(strip_tags($body), 6000);

        $knownIssuesBlock = '';
        if (!empty($knownIssues)) {
            $knownIssuesBlock = "\n\nATTENZIONE - Errori già riscontrati frequentemente in articoli precedenti su questo sito:\n";
            foreach ($knownIssues as $ki) {
                $knownIssuesBlock .= "- {$ki}\n";
            }
            $knownIssuesBlock .= "Controlla con particolare attenzione se anche questo articolo incorre negli stessi errori.\n";
        }

        $prompt = <<<PROMPT
Sei un fact-checker specializzato in contenuti italiani sul tema: {$nicheName}.
{$nicheDesc}

Analizza il seguente articolo e identifica eventuali problemi di accuratezza.{$knownIssuesBlock}

Titolo: "{$title}"
Topic: "{$topic}"

Testo dell'articolo (estratto):
{$bodyText}

Cerca specificamente questi tipi di problemi (usa esattamente questi tag tipo):
- "citazione_falsa": citazioni testuali inventate attribuite a esperti, autori, scienziati (es. "secondo il Prof. X..." senza fonte)
- "opera_inventata": titoli di libri, articoli, studi, riviste scientifiche inventate o non verificabili
- "statistica_inventata": percentuali, numeri, statistiche presentate come certi senza fonte (es. "il 73% delle persone...")
- "dato_storico_errato": date, eventi storici o biografici palesemente errati o inventati
- "studio_inventato": studi scientifici attribuiti a istituzioni o università inesistenti o non verificabili
- "fatto_non_verificabile": affermazioni specifiche presentate come fatti certi su temi incerti o controversi
- "altro": qualsiasi altro problema di accuratezza non classificabile sopra

Assegna uno score da 1 a 10:
- 10: nessun problema, tutto verificabile o presentato con appropriata incertezza
- 8-9: qualche affermazione generica ma nessun problema grave
- 5-7: alcune affermazioni dubbie o specifiche non verificabili
- 1-4: molte informazioni inventate o false

Rispondi SOLO con un JSON valido (niente testo prima o dopo):
{
  "score": N,
  "issues": [
    {"text": "descrizione dettagliata del problema", "type": "tipo_dal_lista_sopra"}
  ],
  "summary": "valutazione breve in italiano (max 2 frasi)"
}

Se non ci sono problemi: {"score": 10, "issues": [], "summary": "Nessun problema rilevato."}
PROMPT;

        foreach ($this->getProviderOrder() as $provider) {
            $startTime = microtime(true);
            $response = $this->callProviderRaw($provider, $prompt, 1200);
            $timeMs = round((microtime(true) - $startTime) * 1000);

            if ($response !== null) {
                $response = trim($response);
                $response = preg_replace('/^```(?:json)?\s*/i', '', $response);
                $response = preg_replace('/\s*```\s*$/i', '', $response);
                $data = json_decode($response, true);

                if (isset($data['score'])) {
                    // Normalizza issues: supporta sia [{text, type}] che ["stringa"]
                    $issuesRaw = (array)($data['issues'] ?? []);
                    $issueTexts = [];
                    $issueTypes = [];
                    foreach ($issuesRaw as $iss) {
                        if (is_array($iss) && isset($iss['text'])) {
                            $issueTexts[] = $iss['text'];
                            $issueTypes[] = $iss['type'] ?? 'altro';
                        } elseif (is_string($iss) && $iss !== '') {
                            $issueTexts[] = $iss;
                            $issueTypes[] = 'altro';
                        }
                    }
                    return [
                        'score'       => max(1, min(10, intval($data['score']))),
                        'issues'      => $issueTexts,
                        'issue_types' => $issueTypes,
                        'summary'     => $data['summary'] ?? '',
                        'provider'    => $provider,
                        'time_ms'     => $timeMs,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Tronca un testo a N caratteri senza spezzare parole.
     */
    private function truncate(string $text, int $limit): string
    {
        $text = preg_replace('/\s+/', ' ', trim($text));
        if (mb_strlen($text) <= $limit) return $text;
        $t = mb_substr($text, 0, $limit);
        $lastSpace = mb_strrpos($t, ' ');
        return ($lastSpace !== false ? mb_substr($t, 0, $lastSpace) : $t) . '...';
    }

    /**
     * Esegue una chiamata API con dettagli debug.
     * @return array ['success' => bool, 'response' => ?string, 'error' => ?string, 'http_code' => int, 'url' => string]
     */
    public function callApiDebug(string $provider, string $prompt): array
    {
        $startTime = microtime(true);
        
        if ($provider === 'openai') {
            if ($this->openaiKey === 'YOUR_OPENAI_API_KEY' || empty($this->openaiKey)) {
                return ['success' => false, 'response' => null, 'error' => 'API key non configurata', 'http_code' => 0, 'url' => 'https://api.openai.com/v1/chat/completions'];
            }
            $url = 'https://api.openai.com/v1/chat/completions';
            $payload = json_encode([
                'model'    => $this->openaiModel,
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'temperature'  => 0.7,
                'max_tokens'   => 4000,
            ]);
            $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $this->openaiKey];
        } elseif ($provider === 'gemini') {
            if ($this->geminiKey === 'YOUR_GEMINI_API_KEY' || empty($this->geminiKey)) {
                return ['success' => false, 'response' => null, 'error' => 'API key non configurata', 'http_code' => 0, 'url' => 'https://generativelanguage.googleapis.com/v1beta/models/' . $this->geminiModel . ':generateContent'];
            }
            $url = sprintf('https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s', $this->geminiModel, $this->geminiKey);
            $payload = json_encode([
                'contents' => [['parts' => [['text' => $prompt]]]],
                'generationConfig' => ['temperature' => 0.7, 'maxOutputTokens' => 4000],
            ]);
            $headers = ['Content-Type: application/json'];
        } elseif ($provider === 'openrouter') {
            if (empty($this->openrouterKey)) {
                return ['success' => false, 'response' => null, 'error' => 'API key non configurata', 'http_code' => 0, 'url' => 'https://openrouter.ai/api/v1/chat/completions'];
            }
            $url = 'https://openrouter.ai/api/v1/chat/completions';
            $payload = json_encode([
                'model'    => $this->openrouterModel,
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'temperature'  => 0.7,
                'max_tokens'   => 4000,
            ]);
            $headers = [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->openrouterKey,
                'HTTP-Referer: ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'),
                'X-Title: AutoPilot RSS'
            ];
        } else {
            return ['success' => false, 'response' => null, 'error' => 'Provider sconosciuto', 'http_code' => 0, 'url' => ''];
        }

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
        $curlError = curl_error($ch);
        curl_close($ch);

        $timeMs = round((microtime(true) - $startTime) * 1000);

        if ($response === false) {
            return ['success' => false, 'response' => null, 'error' => 'CURL Error: ' . $curlError, 'http_code' => $httpCode, 'url' => $url, 'time_ms' => $timeMs];
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            return ['success' => false, 'response' => substr($response, 0, 1000), 'error' => 'HTTP Error ' . $httpCode, 'http_code' => $httpCode, 'url' => $url, 'time_ms' => $timeMs];
        }

        return ['success' => true, 'response' => $response, 'error' => null, 'http_code' => $httpCode, 'url' => $url, 'time_ms' => $timeMs];
    }
}
