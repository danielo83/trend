<?php

// Carica variabili d'ambiente dal file .env (chiavi API al sicuro)
require_once __DIR__ . '/src/EnvLoader.php';
EnvLoader::load(__DIR__ . '/.env');

// Configurazione di default
$defaults = [
    // --- API Keys (lette dal file .env, MAI salvate in chiaro) ---
    'openai_api_key'  => EnvLoader::get('OPENAI_API_KEY'),
    'gemini_api_key'  => EnvLoader::get('GEMINI_API_KEY'),
    'openrouter_api_key' => EnvLoader::get('OPENROUTER_API_KEY'),
    'fal_api_key'     => EnvLoader::get('FAL_API_KEY'),
    'wp_app_password' => EnvLoader::get('WP_APP_PASSWORD'),

    // --- Nicchia / Argomento principale ---
    'niche_name' => 'Sogni e Dormire',
    'niche_description' => 'sogni, interpretazione dei sogni, dormire, qualità del sonno, insonnia, riposo, routine notturna, smorfia napoletana, significato dei numeri nella tradizione popolare',

    // --- Sorgente keyword ---
    // 'google' = Google Autocomplete (usa i semi di ricerca come prefisso)
    // 'manual' = Lista di keyword personalizzate (inserite a mano)
    'keyword_source' => 'google',
    'manual_keywords' => [],

    // --- Semi di ricerca per Google Autocomplete ---
    'semi_ricerca' => [
        "sognare di ",
        "sognare un ",
        "significato sogno ",
        "cosa significa sognare ",
        "interpretazione sogno ",
        "sogno ricorrente ",
        "perché sogno ",
        "incubo ",
        "non riesco a dormire ",
        "perchè mi sveglio alle ",
        "svegliarsi stanchi ",
        "come addormentarsi ",
        "tisana per dormire ",
        "melatonina per ",
        "posizione migliore per dormire ",
        "quante ore bisogna dormire ",
        "cosa mangiare per dormire ",
    ],

    // --- Parametri generazione ---
    'max_articles_per_run' => 3,
    'max_feed_items'       => 50,
    'openai_model'         => 'gpt-4o-mini',
    'gemini_model'         => 'gemini-2.0-flash',
    'openrouter_model'     => 'openai/gpt-4o-mini',

    // --- Provider predefinito per generazione articoli ---
    'default_provider'     => 'openai',  // 'openai', 'gemini', 'openrouter'

    // --- Prompt personalizzati per categoria ---
    // Lascia vuoto per usare i prompt predefiniti. Usa [keyword] come placeholder per il topic.
    // 'prompt_sogni'  => '',   // Prompt per articoli sui sogni
    // 'prompt_sonno'  => '',   // Prompt per articoli sul sonno
    // 'prompt_smorfia' => '',  // Prompt per articoli sulla smorfia

    // --- Social Media Feeds ---
    'social_feeds_enabled' => true,
    'social_site_url'      => 'https://example.com',  // URL base del sito WordPress
    'social_permalink_structure' => '/%year%/%monthnum%/%day%/%postname%/',  // Struttura permalink WordPress
    'facebook_prompt'      => 'Scrivi un copy accattivante per Facebook per promuovere questo articolo.\n\nREGOLE:\n- Massimo 400 caratteri (escluso il link)\n- Tono conversazionale e coinvolgente\n- Usa emoji pertinenti (max 2-3)\n- Includi una call to action\n- Non usare hashtag\n\nArticolo: "[title]"\n\nRispondi SOLO con il testo del copy.',
    'twitter_prompt'       => 'Scrivi un tweet per X/Twitter per promuovere questo articolo.\n\nREGOLE:\n- Massimo 250 caratteri (escluso il link)\n- Tono diretto e incisivo\n- Usa max 1-2 emoji pertinenti\n- Crea curiosità o poni una domanda\n- Non usare hashtag\n\nArticolo: "[title]"\n\nRispondi SOLO con il testo del tweet.',

    // --- Generazione Immagini (fal.ai) ---
    'fal_enabled'                => true,
    'fal_model_id'               => 'fal-ai/flux/schnell',
    'fal_image_size'             => 'landscape_16_9',
    'fal_output_format'          => 'jpeg',
    'fal_quality'                => '',
    'fal_inline_enabled'         => false,
    'fal_inline_interval'        => 3,
    'fal_inline_size'            => 'landscape_16_9',

    // --- Percorsi ---
    'base_dir'    => __DIR__,
    'feed_path'   => __DIR__ . '/data/feed.xml',
    'db_path'     => __DIR__ . '/data/history.sqlite',
    'log_path'    => __DIR__ . '/logs/trend.log',
    'lock_path'   => __DIR__ . '/data/.lock',
    'images_dir'  => __DIR__ . '/data/images',
    'images_url'  => 'data/images',

    // --- WordPress Publishing ---
    'wp_enabled'        => false,
    'wp_site_url'       => '',
    'wp_username'       => '',
    'wp_app_password'   => '',
    'wp_post_status'    => 'draft',
    'wp_category'       => '',
    'wp_auto_publish'   => false,

    // --- Link Building (SEO) ---
    'link_internal_enabled' => false,
    'link_external_enabled' => false,
    'link_max_internal'     => 5,
    'link_max_external'     => 2,
    'link_cache_ttl'        => 21600,  // 6 ore in secondi

    // --- Qualità e Scoring ---
    'min_quality_score'    => 6,      // Punteggio minimo (1-10) per accettare un articolo generato
    'api_max_retries'      => 3,      // Retry con exponential backoff per chiamate API
    'schema_markup_enabled' => true,  // Genera schema JSON-LD (Article + FAQPage) nei dati articolo (non nel body WP)

    // --- Deduplicazione Topic ---
    'topic_similarity_threshold' => 0.7,  // Soglia Jaccard (0.0 = accetta tutto, 1.0 = solo duplicati esatti)
    'embedding_dedup_enabled' => false,    // Usa embeddings OpenAI per dedup semantica (richiede openai_api_key)

    // --- Scheduling Intelligente ---
    'smart_scheduling_enabled' => false,    // Varia il numero di articoli per fascia oraria
    'smart_scheduling_peak_hours' => [8, 9, 10, 12, 13, 18, 19, 20, 21],  // Ore di punta (più articoli)
    'smart_scheduling_peak_articles' => 3,   // Articoli nelle ore di punta
    'smart_scheduling_offpeak_articles' => 1, // Articoli nelle ore non di punta

    // --- Feed RSS ---
    'feed_title'       => 'AutoPilot RSS Feed',
    'feed_description' => 'Articoli generati automaticamente dai trend di ricerca',
    'feed_link'        => 'https://example.com/feed.xml',
    'feed_language'    => 'it',

    // --- Google Autocomplete ---
    'autocomplete_lang'    => 'it',
    'autocomplete_country' => 'it',
    'autocomplete_delay'   => 500000,
];

// Sovrascrivi con impostazioni salvate dal pannello di controllo
$settingsPath = __DIR__ . '/data/settings.json';
if (file_exists($settingsPath)) {
    $saved = json_decode(file_get_contents($settingsPath), true);
    if (is_array($saved)) {
        // Sovrascrivi solo le chiavi non sensibili
        foreach ($saved as $key => $value) {
            if ($key !== 'openai_api_key' && $key !== 'gemini_api_key' && $key !== 'kimi_api_key' && $key !== 'fal_api_key' && $key !== 'wp_app_password' && $value !== '') {
                $defaults[$key] = $value;
            }
        }
    }
}

// Le API key vengono sempre dal .env
$defaults['openai_api_key'] = EnvLoader::get('OPENAI_API_KEY');
$defaults['gemini_api_key'] = EnvLoader::get('GEMINI_API_KEY');
$defaults['openrouter_api_key'] = EnvLoader::get('OPENROUTER_API_KEY');
$defaults['fal_api_key']    = EnvLoader::get('FAL_API_KEY');
$defaults['wp_app_password'] = EnvLoader::get('WP_APP_PASSWORD');

return $defaults;
