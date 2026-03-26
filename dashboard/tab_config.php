    <div class="header">
        <h2>Configurazione</h2>
    </div>

    <style>
        .cfg-tabs { display:flex; gap:6px; margin-bottom:24px; flex-wrap:wrap; }
        .cfg-tab { padding:8px 18px; border-radius:8px; border:1px solid #334155; background:#1e293b; color:#94a3b8; font-size:14px; font-weight:500; cursor:pointer; transition:all .15s; }
        .cfg-tab:hover { border-color:#818cf8; color:#c7d2fe; }
        .cfg-tab.active { background:#4f46e5; border-color:#4f46e5; color:#fff; }
        .cfg-panel { display:none; }
        .cfg-panel.active { display:block; }
    </style>

    <div class="cfg-tabs">
        <button type="button" class="cfg-tab active" onclick="showCfgTab('ai')">🤖 AI &amp; Provider</button>
        <button type="button" class="cfg-tab" onclick="showCfgTab('contenuto')">📝 Contenuto</button>
        <button type="button" class="cfg-tab" onclick="showCfgTab('immagini')">🖼️ Immagini</button>
        <button type="button" class="cfg-tab" onclick="showCfgTab('pubblicazione')">🚀 Pubblicazione &amp; SEO</button>
        <button type="button" class="cfg-tab" onclick="showCfgTab('prompt')">💬 Prompt AI</button>
    </div>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <input type="hidden" name="action" value="save_config">

        <!-- TAB: AI & Provider -->
        <div class="cfg-panel active" id="cfg-ai">

        <div class="card">
            <h3>API Keys</h3>
            <p style="font-size:12px; color:#64748b; margin-bottom:12px;">Le chiavi sono salvate nel file .env protetto. Inserisci una nuova chiave per aggiornarla, oppure lascia vuoto per mantenere quella attuale.</p>
            <div class="form-row">
                <div class="form-group">
                    <label>OpenAI API Key (primario) - Attuale: <?= maskKey($config['openai_api_key']) ?></label>
                    <input type="password" name="openai_api_key" value="" placeholder="Inserisci nuova chiave per aggiornare...">
                </div>
                <div class="form-group">
                    <label>Gemini API Key (fallback) - Attuale: <?= maskKey($config['gemini_api_key']) ?></label>
                    <input type="password" name="gemini_api_key" value="" placeholder="Inserisci nuova chiave per aggiornare...">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>OpenRouter API Key (fallback) - Attuale: <?= maskKey($config['openrouter_api_key']) ?></label>
                    <input type="password" name="openrouter_api_key" value="" placeholder="Inserisci nuova chiave per aggiornare...">
                    <div class="hint">Ottienila da <strong>openrouter.ai/keys</strong></div>
                </div>
                <div class="form-group">
                    <label>fal.ai API Key (immagini) - Attuale: <?= maskKey($config['fal_api_key']) ?></label>
                    <input type="password" name="fal_api_key" value="" placeholder="Inserisci nuova chiave per aggiornare...">
                    <div class="hint">Ottienila da <strong>fal.ai/dashboard/keys</strong></div>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Cron Token (sicurezza cron URL) - Attuale: <?= maskKey(EnvLoader::get('CRON_TOKEN')) ?></label>
                    <input type="password" name="cron_token" value="" placeholder="Inserisci token per aggiornare...">
                    <div class="hint">Token segreto per chiamare <strong>cron.php?token=...</strong> via URL. Usalo per hosting che supportano cron via HTTP (es. cPanel, Plesk).</div>
                </div>
            </div>

            <div class="form-group" style="margin-bottom:20px;">
                <label>Provider predefinito per generazione articoli</label>
                <select name="default_provider" style="max-width:400px;">
                    <?php $currentProvider = $config['default_provider'] ?? 'openai'; ?>
                    <option value="openai" <?= $currentProvider === 'openai' ? 'selected' : '' ?>>OpenAI (GPT)</option>
                    <option value="gemini" <?= $currentProvider === 'gemini' ? 'selected' : '' ?>>Google Gemini</option>
                    <option value="openrouter" <?= $currentProvider === 'openrouter' ? 'selected' : '' ?>>OpenRouter</option>
                </select>
                <div class="hint">Il provider scelto viene usato per primo. Gli altri fungono da fallback automatico in caso di errore.</div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Modello OpenAI</label>
                    <input type="text" name="openai_model" value="<?= htmlspecialchars($config['openai_model']) ?>">
                </div>
                <div class="form-group">
                    <label>Modello Gemini</label>
                    <input type="text" name="gemini_model" value="<?= htmlspecialchars($config['gemini_model']) ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Modello OpenRouter</label>
                    <input type="text" name="openrouter_model" value="<?= htmlspecialchars($config['openrouter_model'] ?? 'openai/gpt-4o-mini') ?>" placeholder="es: openai/gpt-4o-mini, xiaomi/mimo-v2-omni, minimax/minimax-m2.7">
                    <div class="hint">Inserisci il nome completo del modello. Esempi: openai/gpt-4o-mini, anthropic/claude-3.5-sonnet, xiaomi/mimo-v2-omni, minimax/minimax-m2.7, google/gemini-2.0-flash. <a href="https://openrouter.ai/models" target="_blank" style="color:#818cf8;">Vedi tutti i modelli disponibili</a></div>
                </div>
                <div class="form-group"></div>
            </div>
        </div>

        <button type="submit" class="btn btn-primary">Salva Configurazione</button>
        </div><!-- /cfg-ai -->

        <!-- TAB: Contenuto -->
        <div class="cfg-panel" id="cfg-contenuto">

        <div class="card">
            <h3>Nicchia / Argomento</h3>
            <p style="font-size:12px; color:#64748b; margin-bottom:12px;">Definisci l'argomento principale del sistema. Viene usato per filtrare automaticamente i topic non pertinenti e come contesto per la generazione.</p>
            <div class="form-row">
                <div class="form-group">
                    <label>Nome Nicchia</label>
                    <input type="text" name="niche_name" value="<?= htmlspecialchars($config['niche_name'] ?? 'Sogni e Dormire') ?>" placeholder="Es: Sogni e Dormire, Cucina Italiana, Fitness...">
                </div>
            </div>
            <div class="form-group">
                <label>Descrizione Nicchia (usata dall'AI per verificare la pertinenza dei topic)</label>
                <textarea name="niche_description" rows="3" placeholder="Elenca i temi pertinenti separati da virgola..."><?= htmlspecialchars($config['niche_description'] ?? '') ?></textarea>
                <div class="hint">Elenca i temi separati da virgola. Es: "sogni, interpretazione dei sogni, dormire, qualità del sonno, smorfia napoletana"</div>
            </div>
        </div>

        <div class="card">
            <h3>Sorgente Keyword</h3>
            <div class="form-group">
                <label>Modalita di recupero keyword</label>
                <select name="keyword_source" id="keyword_source" onchange="toggleKeywordSource()">
                    <option value="google" <?= ($config['keyword_source'] ?? 'google') === 'google' ? 'selected' : '' ?>>Google Autocomplete (semi di ricerca)</option>
                    <option value="manual" <?= ($config['keyword_source'] ?? 'google') === 'manual' ? 'selected' : '' ?>>Keyword manuali (lista personalizzata)</option>
                </select>
                <div class="hint">Google Autocomplete genera keyword automaticamente dai semi. Le keyword manuali ti permettono di specificare esattamente gli argomenti da trattare.</div>
            </div>

            <div id="google_keywords_section" style="<?= ($config['keyword_source'] ?? 'google') === 'manual' ? 'display:none' : '' ?>">
                <div class="form-group">
                    <label>Semi di ricerca (un seme per riga, usati come prefisso per Google Autocomplete)</label>
                    <textarea name="semi_ricerca" rows="12"><?= htmlspecialchars(implode("\n", $config['semi_ricerca'])) ?></textarea>
                    <div class="hint">Es: "sognare di ", "significato sogno ", "cosa significa sognare "</div>
                </div>
            </div>

            <div id="manual_keywords_section" style="<?= ($config['keyword_source'] ?? 'google') !== 'manual' ? 'display:none' : '' ?>">
                <div class="form-group">
                    <label>Keyword personalizzate (una per riga, verranno usate direttamente come topic per gli articoli)</label>
                    <textarea name="manual_keywords" rows="12"><?= htmlspecialchars(implode("\n", $config['manual_keywords'] ?? [])) ?></textarea>
                    <div class="hint">Es: "significato sognare di volare", "come curare l'insonnia cronica", "smorfia napoletana numero 33"</div>
                </div>
            </div>
        </div>

        <div class="card">
            <h3>Parametri Generazione</h3>
            <div class="form-row">
                <div class="form-group">
                    <label>Max articoli per esecuzione</label>
                    <input type="number" name="max_articles_per_run" value="<?= $config['max_articles_per_run'] ?>" min="1" max="20">
                </div>
                <div class="form-group">
                    <label>Max item nel feed RSS</label>
                    <input type="number" name="max_feed_items" value="<?= $config['max_feed_items'] ?>" min="10" max="500">
                </div>
            </div>
        </div>

        <button type="submit" class="btn btn-primary">Salva Configurazione</button>
        </div><!-- /cfg-contenuto -->

        <!-- TAB: Immagini -->
        <div class="cfg-panel" id="cfg-immagini">

        <div class="card">
            <h3>Generazione Immagini (fal.ai)</h3>
            <p style="font-size:12px; color:#64748b; margin-bottom:12px;">Genera automaticamente immagini AI per ogni articolo tramite le API di fal.ai. Richiede una API Key valida.</p>

            <div class="form-row">
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="fal_enabled" value="1" <?= !empty($config['fal_enabled']) ? 'checked' : '' ?> style="width:auto;margin-right:6px;">
                        Abilita immagine featured
                    </label>
                    <div class="hint">Genera un'immagine di copertina per ogni articolo</div>
                </div>
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="fal_inline_enabled" value="1" <?= !empty($config['fal_inline_enabled']) ? 'checked' : '' ?> style="width:auto;margin-right:6px;">
                        Abilita immagini inline
                    </label>
                    <div class="hint">Inserisce immagini nel corpo dell'articolo ogni N sezioni H2</div>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Modello fal.ai</label>
                    <select name="fal_model_id">
                        <?php
                        $falModels = [
                            'fal-ai/flux/schnell'          => 'Flux Schnell (~$0.003/img)',
                            'fal-ai/flux/dev'              => 'Flux Dev (~$0.025/img)',
                            'fal-ai/flux-pro'              => 'Flux Pro (~$0.05/img)',
                            'fal-ai/flux-2-pro'            => 'Flux 2 Pro (~$0.03/img)',
                            'fal-ai/gpt-image-1.5'         => 'GPT Image 1.5 - OpenAI ($0.011-$0.19/img)',
                            'fal-ai/gpt-image-1/text-to-image' => 'GPT Image 1 - OpenAI ($0.011-$0.25/img)',
                            'fal-ai/gpt-image-1-mini'      => 'GPT Image 1 Mini - OpenAI',
                            'xai/grok-imagine-image'       => 'Grok Imagine - xAI Aurora',
                        ];
                        $currentModel = $config['fal_model_id'] ?? 'fal-ai/flux/schnell';
                        foreach ($falModels as $modelVal => $modelLabel): ?>
                            <option value="<?= htmlspecialchars($modelVal) ?>" <?= $currentModel === $modelVal ? 'selected' : '' ?>><?= htmlspecialchars($modelLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Formato output</label>
                    <select name="fal_output_format">
                        <?php $currentFormat = $config['fal_output_format'] ?? 'jpeg'; ?>
                        <option value="jpeg" <?= $currentFormat === 'jpeg' ? 'selected' : '' ?>>JPEG</option>
                        <option value="png" <?= $currentFormat === 'png' ? 'selected' : '' ?>>PNG</option>
                        <option value="webp" <?= $currentFormat === 'webp' ? 'selected' : '' ?>>WebP</option>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Dimensione immagine featured</label>
                    <select name="fal_image_size" id="fal_image_size">
                        <?php
                        $currentModel = $config['fal_model_id'] ?? 'fal-ai/flux/schnell';
                        $isGPTImage = str_contains($currentModel, 'gpt-image');
                        
                        if ($isGPTImage):
                            // Opzioni GPT Image
                            $gptSizes = ImageGenerator::getGPTImageSizes();
                            $currentSize = $config['fal_image_size'] ?? '1024x1024';
                            foreach ($gptSizes as $sizeVal => $sizeLabel): ?>
                                <option value="<?= htmlspecialchars($sizeVal) ?>" <?= $currentSize === $sizeVal ? 'selected' : '' ?>><?= htmlspecialchars($sizeLabel) ?></option>
                            <?php endforeach;
                        else:
                            // Opzioni Flux
                            $fluxSizes = ImageGenerator::getFluxSizes();
                            $currentSize = $config['fal_image_size'] ?? 'landscape_16_9';
                            foreach ($fluxSizes as $sizeVal => $sizeLabel): ?>
                                <option value="<?= htmlspecialchars($sizeVal) ?>" <?= $currentSize === $sizeVal ? 'selected' : '' ?>><?= htmlspecialchars($sizeLabel) ?></option>
                            <?php endforeach;
                        endif; ?>
                    </select>
                    <div class="hint" id="size_hint">
                        <?php if ($isGPTImage): ?>
                            1024x1024 = Square (più economico), 1536x1024 = Landscape, 1024x1536 = Portrait
                        <?php else: ?>
                            Seleziona il formato adatto al tuo contenuto
                        <?php endif; ?>
                    </div>
                </div>
                <div class="form-group">
                    <label>Qualita'</label>
                    <select name="fal_quality">
                        <?php $currentQuality = $config['fal_quality'] ?? ''; ?>
                        <option value="" <?= $currentQuality === '' ? 'selected' : '' ?>>Default (nessuna)</option>
                        <option value="low" <?= $currentQuality === 'low' ? 'selected' : '' ?>>Low</option>
                        <option value="medium" <?= $currentQuality === 'medium' ? 'selected' : '' ?>>Medium</option>
                        <option value="high" <?= $currentQuality === 'high' ? 'selected' : '' ?>>High</option>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Dimensione immagini inline</label>
                    <select name="fal_inline_size" id="fal_inline_size">
                        <?php
                        // Usa la stessa logica del modello selezionato
                        $isGPTImageInline = str_contains($currentModel, 'gpt-image');
                        
                        if ($isGPTImageInline):
                            // Opzioni GPT Image
                            $gptSizesInline = ImageGenerator::getGPTImageSizes();
                            $currentInlineSize = $config['fal_inline_size'] ?? '1024x1024';
                            foreach ($gptSizesInline as $sizeVal => $sizeLabel): ?>
                                <option value="<?= htmlspecialchars($sizeVal) ?>" <?= $currentInlineSize === $sizeVal ? 'selected' : '' ?>><?= htmlspecialchars($sizeLabel) ?></option>
                            <?php endforeach;
                        else:
                            // Opzioni Flux
                            $fluxSizesInline = ImageGenerator::getFluxSizes();
                            $currentInlineSize = $config['fal_inline_size'] ?? 'landscape_16_9';
                            foreach ($fluxSizesInline as $sizeVal => $sizeLabel): ?>
                                <option value="<?= htmlspecialchars($sizeVal) ?>" <?= $currentInlineSize === $sizeVal ? 'selected' : '' ?>><?= htmlspecialchars($sizeLabel) ?></option>
                            <?php endforeach;
                        endif; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Inserisci immagine inline ogni N sezioni H2</label>
                    <input type="number" name="fal_inline_interval" value="<?= $config['fal_inline_interval'] ?? 3 ?>" min="1" max="10">
                </div>
            </div>

            <div class="form-group">
                <label>Prompt immagine featured. Usa <code style="color:#818cf8;">[title]</code> e <code style="color:#818cf8;">[keyword]</code> come segnaposto.</label>
                <textarea name="fal_prompt_template" rows="4" style="font-size:12px; line-height:1.5;"><?= htmlspecialchars($config['fal_prompt_template'] ?? ImageGenerator::defaultPrompt()) ?></textarea>
            </div>

            <div class="form-group">
                <label>Prompt immagini inline. Usa <code style="color:#818cf8;">[context]</code>, <code style="color:#818cf8;">[title]</code> e <code style="color:#818cf8;">[keyword]</code>.</label>
                <textarea name="fal_inline_prompt_template" rows="4" style="font-size:12px; line-height:1.5;"><?= htmlspecialchars($config['fal_inline_prompt_template'] ?? ImageGenerator::defaultInlinePrompt()) ?></textarea>
            </div>
        </div>

        <button type="submit" class="btn btn-primary">Salva Configurazione</button>
        </div><!-- /cfg-immagini -->

        <!-- TAB: Pubblicazione & SEO -->
        <div class="cfg-panel" id="cfg-pubblicazione">

        <div class="card">
            <h3>Feed Social Media (Auto-posting)</h3>
            <p style="font-size:12px; color:#64748b; margin-bottom:12px;">Genera feed RSS ottimizzati per Facebook e X/Twitter con copy generato dall'AI.</p>
            
            <div class="form-row">
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="social_feeds_enabled" value="1" <?= !empty($config['social_feeds_enabled']) ? 'checked' : '' ?> style="width:auto;margin-right:6px;">
                        Abilita feed social
                    </label>
                    <div class="hint">Crea feed separati per Facebook e X/Twitter con copy ottimizzato</div>
                </div>
            </div>
            
            <div class="form-group">
                <label>URL del sito (WordPress)</label>
                <input type="text" name="social_site_url" value="<?= htmlspecialchars($config['social_site_url'] ?? $config['feed_link'] ?? 'https://example.com') ?>" placeholder="https://tuo-sito.com">
                <div class="hint">URL base del tuo sito WordPress dove verranno pubblicati gli articoli</div>
            </div>
            
            <div class="form-group">
                <label>Struttura Permalink</label>
                <select name="social_permalink_structure">
                    <?php 
                    $currentStructure = $config['social_permalink_structure'] ?? '/%year%/%monthnum%/%day%/%postname%/';
                    $structures = [
                        '/%year%/%monthnum%/%day%/%postname%/' => 'Giorno e nome (WordPress default) - /2026/03/19/titolo-articolo/',
                        '/%year%/%monthnum%/%postname%/'       => 'Mese e nome - /2026/03/titolo-articolo/',
                        '/%postname%/'                         => 'Nome articolo - /titolo-articolo/',
                        '/archives/%post_id%'                  => 'Numerico - /archives/123/',
                    ];
                    foreach ($structures as $value => $label): 
                    ?>
                        <option value="<?= htmlspecialchars($value) ?>" <?= $currentStructure === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="hint">Seleziona la stessa struttura permalink usata in WordPress (Impostazioni → Permalink)</div>
            </div>
            
            <div class="form-group">
                <label>Prompt per Facebook Copy (max 400 caratteri)</label>
                <textarea name="facebook_prompt" rows="8" style="font-size:12px; line-height:1.5;"><?= htmlspecialchars($config['facebook_prompt'] ?? SocialFeedBuilder::defaultFacebookPrompt()) ?></textarea>
                <div class="hint">Usa <code>[title]</code> per il titolo dell'articolo. Il copy sarà generato dall'AI.</div>
            </div>
            
            <div class="form-group">
                <label>Prompt per X/Twitter Copy (max 250 caratteri)</label>
                <textarea name="twitter_prompt" rows="8" style="font-size:12px; line-height:1.5;"><?= htmlspecialchars($config['twitter_prompt'] ?? SocialFeedBuilder::defaultTwitterPrompt()) ?></textarea>
                <div class="hint">Usa <code>[title]</code> per il titolo dell'articolo. Il copy sarà generato dall'AI.</div>
            </div>
            
            <?php if (!empty($config['social_feeds_enabled'])): ?>
            <div style="margin-top:15px;padding:10px;background:#0f172a;border-radius:8px;">
                <strong style="color:#818cf8;">Feed disponibili:</strong><br>
                <a href="data/feed-facebook.xml" target="_blank" style="color:#60a5fa;">📘 Feed Facebook</a><br>
                <a href="data/feed-twitter.xml" target="_blank" style="color:#60a5fa;">🐦 Feed X/Twitter</a>
            </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <h3>Pubblicazione WordPress (REST API)</h3>
            <p style="font-size:12px; color:#64748b; margin-bottom:12px;">Pubblica automaticamente o manualmente gli articoli su WordPress tramite REST API. Richiede <strong>Application Passwords</strong> (WordPress 5.6+).</p>

            <div class="form-row">
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="wp_enabled" value="1" <?= !empty($config['wp_enabled']) ? 'checked' : '' ?> style="width:auto;margin-right:6px;">
                        Abilita pubblicazione WordPress
                    </label>
                    <div class="hint">Attiva la possibilita' di pubblicare articoli su WordPress</div>
                </div>
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="wp_auto_publish" value="1" <?= !empty($config['wp_auto_publish']) ? 'checked' : '' ?> style="width:auto;margin-right:6px;">
                        Pubblicazione automatica
                    </label>
                    <div class="hint">Pubblica automaticamente ogni articolo appena generato (durante esecuzione cron o manuale)</div>
                </div>
            </div>

            <div class="form-group">
                <label>URL Sito WordPress</label>
                <input type="text" name="wp_site_url" value="<?= htmlspecialchars($config['wp_site_url'] ?? '') ?>" placeholder="https://tuo-sito.com">
                <div class="hint">URL base del sito WordPress (senza slash finale). Es: https://www.smorfeo.it</div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Username WordPress</label>
                    <input type="text" name="wp_username" value="<?= htmlspecialchars($config['wp_username'] ?? '') ?>" placeholder="admin">
                    <div class="hint">L'utente WordPress con permessi di pubblicazione</div>
                </div>
                <div class="form-group">
                    <label>Application Password - Attuale: <?= maskKey($config['wp_app_password'] ?? '') ?></label>
                    <input type="password" name="wp_app_password" value="" placeholder="Inserisci nuova password per aggiornare...">
                    <div class="hint">Generala da WordPress: Utenti → Profilo → Application Passwords</div>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Stato Post</label>
                    <select name="wp_post_status">
                        <?php
                        $wpStatus = $config['wp_post_status'] ?? 'draft';
                        $statuses = ['draft' => 'Bozza (draft)', 'publish' => 'Pubblicato (publish)', 'pending' => 'In attesa di revisione (pending)'];
                        foreach ($statuses as $val => $label): ?>
                            <option value="<?= $val ?>" <?= $wpStatus === $val ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="hint">Stato con cui vengono creati i post su WordPress</div>
                </div>
                <div class="form-group">
                    <label>Categoria di fallback (opzionale)</label>
                    <input type="text" name="wp_category" value="<?= htmlspecialchars($config['wp_category'] ?? '') ?>" placeholder="Lascia vuoto per auto-detect AI" id="wpCategoryInput">
                    <div class="hint">Il sistema sceglie automaticamente la categoria piu' pertinente tramite AI, analizzando titolo e argomento dell'articolo. Se specifichi un valore qui, verra' usato solo come fallback quando l'AI non e' disponibile.</div>
                </div>
            </div>

            <div style="margin-top:15px;display:flex;gap:10px;align-items:center;">
                <button type="button" class="btn btn-primary btn-sm" onclick="testWpConnection()">Testa Connessione</button>
                <span id="wpTestResult" style="font-size:13px;"></span>
            </div>
        </div>

        <div class="card">
            <h3>Link Building (SEO)</h3>
            <p style="font-size:12px; color:#64748b; margin-bottom:12px;">Inserisce automaticamente link interni verso altri articoli del sito e opzionalmente link esterni verso fonti autorevoli nei nuovi articoli generati. Richiede WordPress abilitato.</p>

            <div class="form-row">
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="link_internal_enabled" value="1" <?= !empty($config['link_internal_enabled']) ? 'checked' : '' ?> style="width:auto;margin-right:6px;">
                        Abilita link interni
                    </label>
                    <div class="hint">Inserisce link verso altri articoli del sito WordPress nei nuovi contenuti generati</div>
                </div>
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="link_external_enabled" value="1" <?= !empty($config['link_external_enabled']) ? 'checked' : '' ?> style="width:auto;margin-right:6px;">
                        Abilita link esterni
                    </label>
                    <div class="hint">Permette all'AI di inserire link verso fonti autorevoli esterne</div>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Max link interni per articolo</label>
                    <input type="number" name="link_max_internal" value="<?= $config['link_max_internal'] ?? 5 ?>" min="1" max="10">
                </div>
                <div class="form-group">
                    <label>Max link esterni per articolo</label>
                    <input type="number" name="link_max_external" value="<?= $config['link_max_external'] ?? 2 ?>" min="0" max="5">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Durata cache lista articoli</label>
                    <select name="link_cache_ttl">
                        <?php $currentTtl = $config['link_cache_ttl'] ?? 21600;
                        $ttlOptions = [3600 => '1 ora', 10800 => '3 ore', 21600 => '6 ore', 43200 => '12 ore', 86400 => '24 ore'];
                        foreach ($ttlOptions as $val => $label): ?>
                            <option value="<?= $val ?>" <?= $currentTtl == $val ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="hint">La lista dei post WordPress viene cachata localmente per evitare chiamate API ripetute</div>
                </div>
                <div class="form-group">
                    <?php
                    require_once __DIR__ . '/src/LinkBuilder.php';
                    require_once __DIR__ . '/src/SmartLinkBuilder.php';
                    $lbInfo = (new SmartLinkBuilder($config))->getCacheInfo();
                    ?>
                    <label>Stato cache</label>
                    <div style="padding:10px;background:#0f172a;border:1px solid #334155;border-radius:8px;font-size:13px;">
                        <span id="linkCacheCount"><?= $lbInfo['count'] ?></span> articoli in cache
                        <?php if ($lbInfo['fetched_at']): ?>
                            <br><span style="color:#64748b;font-size:11px;">Ultimo aggiornamento: <?= date('d/m/Y H:i', $lbInfo['fetched_at']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div style="margin-top:15px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                <button type="button" class="btn btn-primary btn-sm" onclick="refreshLinkCache()">Aggiorna cache articoli</button>
                <button type="button" class="btn btn-sm" style="background:#d97706;color:white;" onclick="relinkBulk()">Applica link building ai vecchi articoli</button>
                <span id="linkCacheResult" style="font-size:13px;"></span>
            </div>
        </div>

        <div class="card">
            <h3>Feed RSS</h3>
            <div class="form-row">
                <div class="form-group">
                    <label>Titolo Feed</label>
                    <input type="text" name="feed_title" value="<?= htmlspecialchars($config['feed_title']) ?>">
                </div>
                <div class="form-group">
                    <label>Link Feed</label>
                    <input type="text" name="feed_link" value="<?= htmlspecialchars($config['feed_link']) ?>">
                </div>
            </div>
        </div>

        <button type="submit" class="btn btn-primary">Salva Configurazione</button>
        </div><!-- /cfg-pubblicazione -->

        <!-- TAB: Prompt AI -->
        <div class="cfg-panel" id="cfg-prompt">

        <div class="card">
            <h3>Prompt Generazione Titolo (SEO / GEO / Google Discover)</h3>
            <p style="font-size:12px; color:#64748b; margin-bottom:12px;">Il titolo viene <strong>sempre</strong> rigenerato con una chiamata AI dedicata dopo la creazione dell'articolo, ottimizzato per SEO, GEO e Google Discover. Personalizza il prompt qui sotto oppure lascia vuoto per usare quello di default.</p>
            <div class="form-group">
                <label>Prompt per il titolo. Usa <code style="color:#818cf8;">[keyword]</code> come segnaposto. L'AI deve rispondere SOLO con il testo del titolo.</label>
                <textarea name="title_prompt_template" rows="10" style="font-size:12px; line-height:1.5;" placeholder="Lascia vuoto per usare il prompt di default (ottimizzato SEO/GEO/Discover)..."><?= htmlspecialchars($config['title_prompt_template'] ?? '') ?></textarea>
                <div class="hint">Se vuoto, il sistema usa un prompt interno ottimizzato per SEO (keyword prominente, 50-60 char), GEO (chiarezza per AI generativa), e Google Discover (curiosita' + autorita'). <button type="button" class="btn btn-sm" style="padding:2px 8px;font-size:11px;margin-left:8px;" onclick="document.querySelector('textarea[name=title_prompt_template]').value = <?= htmlspecialchars(json_encode(ContentGenerator::defaultTitlePrompt()), ENT_QUOTES) ?>">Carica prompt di default</button></div>
            </div>
        </div>

        <div class="card">
            <h3>Prompt di Generazione Articolo</h3>
            <div class="form-group">
                <label>Template del prompt inviato all'AI. Usa <code style="color:#818cf8;">[keyword]</code> come segnaposto per il topic trovato.</label>
                <textarea name="prompt_template" rows="20" style="font-size:12px; line-height:1.5;"><?= htmlspecialchars($config['prompt_template'] ?? ContentGenerator::defaultPrompt()) ?></textarea>
                <div class="hint">Ogni occorrenza di [keyword] verra' sostituita con il topic di ricerca trovato da Google Autocomplete.</div>
            </div>
        </div>

        <button type="submit" class="btn btn-primary">Salva Configurazione</button>
        </div><!-- /cfg-prompt -->

    </form>

