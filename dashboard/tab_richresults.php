    <?php
    // Rich Results Generator
    require_once __DIR__ . '/../src/RichResultsGenerator.php';
    
    // Test schema markup
    $testArticle = [
        'title' => 'Esempio Articolo',
        'meta_description' => 'Descrizione di esempio per testare lo schema markup',
        'url' => 'https://example.com/articolo-di-test',
        'published_at' => date('c'),
        'site_name' => 'Sito di Test',
        'site_url' => 'https://example.com',
        'author_name' => 'Autore Test',
        'category' => 'Categoria',
        'word_count' => 1500,
        'faqs' => [
            ['question' => 'Domanda 1?', 'answer' => 'Risposta 1'],
            ['question' => 'Domanda 2?', 'answer' => 'Risposta 2'],
        ],
        'featured_image' => [
            'url' => 'https://example.com/image.jpg',
            'width' => 1200,
            'height' => 630,
        ],
    ];
    
    $testSchema = RichResultsGenerator::generateFullMarkup($testArticle);
    ?>
    
    <div class="header">
        <h2>⭐ Rich Results & Schema Markup</h2>
    </div>
    
    <!-- Info -->
    <div class="card" style="background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);">
        <h3>🎯 Schema Markup Automatico</h3>
        <p style="color:#94a3b8;margin-bottom:15px;">
            Il sistema genera automaticamente schema markup avanzato per ogni articolo pubblicato:
        </p>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:15px;">
            <div style="padding:15px;background:#0f172a;border-radius:8px;">
                <div style="font-size:24px;margin-bottom:8px;">📰</div>
                <strong style="color:#f1f5f9;">Article</strong>
                <p style="font-size:12px;color:#64748b;margin:5px 0 0 0;">Markup completo con author, publisher, date</p>
            </div>
            <div style="padding:15px;background:#0f172a;border-radius:8px;">
                <div style="font-size:24px;margin-bottom:8px;">❓</div>
                <strong style="color:#f1f5f9;">FAQPage</strong>
                <p style="font-size:12px;color:#64748b;margin:5px 0 0 0;">Per sezione FAQ</p>
            </div>
            <div style="padding:15px;background:#0f172a;border-radius:8px;">
                <div style="font-size:24px;margin-bottom:8px;">📋</div>
                <strong style="color:#f1f5f9;">HowTo</strong>
                <p style="font-size:12px;color:#64748b;margin:5px 0 0 0;">Per guide passo-passo</p>
            </div>
            <div style="padding:15px;background:#0f172a;border-radius:8px;">
                <div style="font-size:24px;margin-bottom:8px;">🗣️</div>
                <strong style="color:#f1f5f9;">Speakable</strong>
                <p style="font-size:12px;color:#64748b;margin:5px 0 0 0;">Per voice search</p>
            </div>
        </div>
    </div>
    
    <!-- Esempio Schema -->
    <div class="card">
        <h3>📝 Esempio Schema Markup Generato</h3>
        <p style="color:#64748b;font-size:12px;margin-bottom:10px;">
            Questo è un esempio del markup che viene generato automaticamente per ogni articolo:
        </p>
        <pre style="background:#0f172a;padding:15px;border-radius:8px;overflow-x:auto;font-size:11px;color:#94a3b8;"><?= htmlspecialchars($testSchema) ?></pre>
    </div>
    
    <!-- Validazione -->
    <div class="card">
        <h3>✅ Validazione Schema</h3>
        <p style="color:#94a3b8;margin-bottom:15px;">
            Usa questi strumenti per validare lo schema markup:
        </p>
        <div style="display:flex;gap:15px;flex-wrap:wrap;">
            <a href="https://search.google.com/test/rich-results" target="_blank" class="btn btn-primary" style="text-decoration:none;">
                🔍 Google Rich Results Test
            </a>
            <a href="https://validator.schema.org/" target="_blank" class="btn btn-primary" style="text-decoration:none;">
                📋 Schema Markup Validator
            </a>
        </div>
    </div>

