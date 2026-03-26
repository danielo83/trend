<?php

class AutocompleteFetcher
{
    private array $semi;
    private string $lang;
    private string $country;
    private int $delay;

    public function __construct(array $config)
    {
        $this->semi    = $config['semi_ricerca'];
        $this->lang    = $config['autocomplete_lang'];
        $this->country = $config['autocomplete_country'];
        $this->delay   = $config['autocomplete_delay'];
    }

    /**
     * Recupera tutti i suggerimenti da Google Autocomplete per ogni seme.
     * @return array Lista di suggerimenti unici e validi
     */
    public function fetch(): array
    {
        $tutti_suggerimenti = [];

        // Mescola i semi ad ogni esecuzione per variare la priorità
        $semi = $this->semi;
        shuffle($semi);

        foreach ($semi as $seme) {
            $suggerimenti = $this->fetchPerSeme($seme);
            foreach ($suggerimenti as $s) {
                $s = trim($s);
                // Filtra suggerimenti non validi
                if (!$this->isValidSuggestion($s)) {
                    continue;
                }
                $key = mb_strtolower($s);
                if (!isset($tutti_suggerimenti[$key])) {
                    $tutti_suggerimenti[$key] = $s;
                }
            }
            usleep($this->delay);
        }

        // Mescola anche i risultati per non favorire sempre lo stesso seme
        $result = array_values($tutti_suggerimenti);
        shuffle($result);

        return $result;
    }

    /**
     * Verifica che un suggerimento sia valido e completo.
     * Filtra frasi troncate, troppo corte, o che sono solo il seme stesso.
     */
    private function isValidSuggestion(string $suggestion): bool
    {
        $s = mb_strtolower(trim($suggestion));

        // Troppo corto (meno di 3 parole → probabilmente incompleto)
        $wordCount = count(preg_split('/\s+/', $s));
        if ($wordCount < 3) {
            return false;
        }

        // Finisce con una preposizione/articolo/congiunzione → frase troncata
        $troncati = [' di', ' per', ' il', ' la', ' le', ' lo', ' gli', ' un', ' una',
                     ' alle', ' ai', ' al', ' del', ' della', ' delle', ' dei', ' che',
                     ' come', ' con', ' su', ' in', ' a', ' da', ' e', ' o', ' ma'];
        foreach ($troncati as $suffix) {
            if (mb_substr($s, -mb_strlen($suffix)) === $suffix) {
                return false;
            }
        }

        // È uguale a uno dei semi (nessun completamento da Google)
        foreach ($this->semi as $seme) {
            if ($s === mb_strtolower(trim($seme))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Chiama Google Suggest per un singolo seme.
     */
    private function fetchPerSeme(string $seme): array
    {
        $url = sprintf(
            'https://suggestqueries.google.com/complete/search?output=firefox&hl=%s&gl=%s&q=%s',
            urlencode($this->lang),
            urlencode($this->country),
            urlencode($seme)
        );

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($response === false || $httpCode !== 200) {
            return [];
        }

        $data = json_decode($response, true);

        if ($data === null || json_last_error() !== JSON_ERROR_NONE) {
            return [];
        }

        if (!isset($data[1]) || !is_array($data[1])) {
            return [];
        }

        return array_map('strip_tags', $data[1]);
    }
}
