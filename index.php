<?php

/**
 * AutoPilot - Frontend pubblico
 * Pagina di lettura articoli generati dal feed RSS
 */

// --- Protezione con password ---
session_start();

$config = require __DIR__ . '/config.php';

$dashboardHash = $config['dashboard_password_hash'] ?? EnvLoader::get('DASHBOARD_PASSWORD');

if (empty($_SESSION['index_auth'])) {
    if (isset($_POST['index_password']) && password_verify($_POST['index_password'], $dashboardHash)) {
        $_SESSION['index_auth'] = true;
        session_regenerate_id(true);
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
    ?><!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accesso richiesto</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #0f172a; color: #e2e8f0; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .login-box { background: #1e293b; border: 1px solid #334155; border-radius: 16px; padding: 40px; width: 100%; max-width: 400px; }
        .login-box h1 { font-size: 22px; color: #818cf8; margin-bottom: 8px; }
        .login-box p { font-size: 14px; color: #64748b; margin-bottom: 24px; }
        .login-box input { width: 100%; padding: 12px; background: #0f172a; border: 1px solid #334155; border-radius: 8px; color: #e2e8f0; font-size: 16px; margin-bottom: 16px; }
        .login-box input:focus { outline: none; border-color: #818cf8; }
        .login-box button { width: 100%; padding: 12px; background: #6366f1; color: white; border: none; border-radius: 8px; font-size: 16px; font-weight: 600; cursor: pointer; }
        .login-box button:hover { background: #4f46e5; }
        .error { color: #fca5a5; font-size: 13px; margin-bottom: 12px; }
    </style>
</head>
<body>
    <div class="login-box">
        <h1>AutoPilot</h1>
        <p>Accesso richiesto</p>
        <?php if (isset($_POST['index_password'])): ?>
            <div class="error">Password non valida.</div>
        <?php endif; ?>
        <form method="post">
            <input type="password" name="index_password" placeholder="Password" autofocus required>
            <button type="submit">Accedi</button>
        </form>
    </div>
</body>
</html><?php
    exit;
}

require __DIR__ . '/src/RSSFeedBuilder.php';

$feedBuilder = new RSSFeedBuilder($config);
$articles = $feedBuilder->getItems();
$siteName = $config['niche_name'] ?? 'AutoPilot';
$siteDesc = $config['feed_description'] ?? '';

// Paginazione
$perPage = 10;
$totalArticles = count($articles);
$totalPages = max(1, ceil($totalArticles / $perPage));
$currentPage = max(1, min($totalPages, intval($_GET['page'] ?? 1)));
$offset = ($currentPage - 1) * $perPage;
$articlesPage = array_slice($articles, $offset, $perPage);

// Singolo articolo
$viewArticle = null;
if (isset($_GET['article'])) {
    $idx = intval($_GET['article']);
    if (isset($articles[$idx])) {
        $viewArticle = $articles[$idx];
    }
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php if ($viewArticle): ?>
        <title><?= htmlspecialchars($viewArticle['title']) ?> - <?= htmlspecialchars($siteName) ?></title>
        <meta name="description" content="<?= htmlspecialchars(mb_substr(strip_tags($viewArticle['content']), 0, 155)) ?>">
    <?php else: ?>
        <title><?= htmlspecialchars($siteName) ?></title>
        <meta name="description" content="<?= htmlspecialchars($siteDesc) ?>">
    <?php endif; ?>
    <link rel="alternate" type="application/rss+xml" title="<?= htmlspecialchars($siteName) ?> RSS" href="feed.php">
    <style>
        :root {
            --bg: #fafafa;
            --bg-card: #ffffff;
            --text: #1a1a2e;
            --text-light: #555;
            --text-muted: #888;
            --accent: #4f46e5;
            --accent-hover: #4338ca;
            --border: #e5e7eb;
            --shadow: 0 1px 3px rgba(0,0,0,0.06), 0 1px 2px rgba(0,0,0,0.04);
            --shadow-hover: 0 4px 12px rgba(0,0,0,0.08);
            --radius: 12px;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0f172a;
                --bg-card: #1e293b;
                --text: #e2e8f0;
                --text-light: #94a3b8;
                --text-muted: #64748b;
                --accent: #818cf8;
                --accent-hover: #6366f1;
                --border: #334155;
                --shadow: 0 1px 3px rgba(0,0,0,0.3);
                --shadow-hover: 0 4px 12px rgba(0,0,0,0.4);
            }
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Georgia', 'Times New Roman', serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.8;
            -webkit-font-smoothing: antialiased;
        }

        .container {
            max-width: 780px;
            margin: 0 auto;
            padding: 0 24px;
        }

        /* Header */
        .site-header {
            padding: 40px 0 32px;
            border-bottom: 1px solid var(--border);
            margin-bottom: 40px;
        }

        .site-header h1 {
            font-size: 28px;
            font-weight: 700;
            color: var(--text);
            letter-spacing: -0.5px;
        }

        .site-header h1 a {
            color: inherit;
            text-decoration: none;
        }

        .site-header p {
            font-size: 15px;
            color: var(--text-muted);
            margin-top: 6px;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }

        .rss-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            font-size: 13px;
            color: var(--accent);
            text-decoration: none;
            margin-top: 10px;
        }
        .rss-link:hover { text-decoration: underline; }
        .rss-icon { width: 14px; height: 14px; fill: var(--accent); }

        /* Lista articoli */
        .article-list { list-style: none; }

        .article-item {
            padding: 28px 0;
            border-bottom: 1px solid var(--border);
        }

        .article-item:last-child { border-bottom: none; }

        .article-item h2 {
            font-size: 22px;
            font-weight: 600;
            line-height: 1.3;
            margin-bottom: 8px;
        }

        .article-item h2 a {
            color: var(--text);
            text-decoration: none;
            transition: color 0.2s;
        }

        .article-item h2 a:hover {
            color: var(--accent);
        }

        .article-date {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            font-size: 13px;
            color: var(--text-muted);
            margin-bottom: 10px;
        }

        .article-excerpt {
            font-size: 16px;
            color: var(--text-light);
            line-height: 1.7;
        }

        .read-more {
            display: inline-block;
            margin-top: 12px;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            font-size: 14px;
            color: var(--accent);
            text-decoration: none;
            font-weight: 500;
        }
        .read-more:hover { text-decoration: underline; }

        /* Articolo singolo */
        .article-full { padding: 10px 0 60px; }

        .article-full .back {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            font-size: 14px;
            color: var(--accent);
            text-decoration: none;
            margin-bottom: 32px;
        }
        .article-full .back:hover { text-decoration: underline; }

        .article-full h1 {
            font-size: 32px;
            font-weight: 700;
            line-height: 1.25;
            margin-bottom: 12px;
            letter-spacing: -0.5px;
        }

        .article-full .meta {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            font-size: 14px;
            color: var(--text-muted);
            margin-bottom: 36px;
            padding-bottom: 24px;
            border-bottom: 1px solid var(--border);
        }

        .article-featured-image {
            margin: 0 0 32px;
            border-radius: var(--radius);
            overflow: hidden;
        }

        .article-featured-image img {
            width: 100%;
            height: auto;
            display: block;
        }

        .article-item .thumb {
            width: 100%;
            max-height: 200px;
            object-fit: cover;
            border-radius: var(--radius);
            margin-bottom: 12px;
        }

        .article-inline-image {
            margin: 30px 0;
            text-align: center;
        }

        .article-inline-image img {
            max-width: 100%;
            height: auto;
            border-radius: 8px;
        }

        .article-body {
            font-size: 18px;
            line-height: 1.85;
        }

        .article-body h2 {
            font-size: 24px;
            font-weight: 700;
            margin: 40px 0 16px;
            letter-spacing: -0.3px;
        }

        .article-body h3 {
            font-size: 20px;
            font-weight: 600;
            margin: 32px 0 12px;
        }

        .article-body p {
            margin-bottom: 18px;
            color: var(--text-light);
        }

        .article-body p:first-child {
            font-size: 19px;
            color: var(--text);
            font-style: italic;
            border-left: 3px solid var(--accent);
            padding-left: 20px;
            margin-bottom: 28px;
        }

        .article-body strong {
            color: var(--text);
            font-weight: 600;
        }

        .article-body ul, .article-body ol {
            margin: 16px 0 16px 28px;
            color: var(--text-light);
        }

        .article-body li {
            margin-bottom: 8px;
        }

        .article-body .faq-item {
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 20px 24px;
            margin-bottom: 16px;
        }

        .article-body .faq-item h3 {
            font-size: 17px;
            margin: 0 0 8px;
            color: var(--text);
        }

        .article-body .faq-item p {
            margin: 0;
            font-size: 16px;
        }

        /* Paginazione */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            padding: 40px 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }

        .pagination a, .pagination span {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 40px;
            height: 40px;
            padding: 0 12px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.2s;
        }

        .pagination a {
            background: var(--bg-card);
            color: var(--text-light);
            border: 1px solid var(--border);
        }

        .pagination a:hover {
            background: var(--accent);
            color: white;
            border-color: var(--accent);
        }

        .pagination .current {
            background: var(--accent);
            color: white;
            border: 1px solid var(--accent);
        }

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 80px 20px;
            color: var(--text-muted);
        }

        .empty-state h2 {
            font-size: 22px;
            margin-bottom: 12px;
            color: var(--text-light);
        }

        .empty-state p {
            font-size: 16px;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }

        /* Footer */
        .site-footer {
            padding: 24px 0;
            border-top: 1px solid var(--border);
            margin-top: 20px;
            text-align: center;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            font-size: 13px;
            color: var(--text-muted);
        }

        /* Responsive */
        @media (max-width: 600px) {
            .container { padding: 0 16px; }
            .site-header { padding: 24px 0 20px; margin-bottom: 24px; }
            .site-header h1 { font-size: 22px; }
            .article-item h2 { font-size: 19px; }
            .article-item { padding: 20px 0; }
            .article-full h1 { font-size: 26px; }
            .article-body { font-size: 16px; }
            .article-body h2 { font-size: 21px; }
            .article-body h3 { font-size: 18px; }
            .article-body p:first-child { font-size: 17px; }
        }
    </style>
</head>
<body>

<div class="container">

    <header class="site-header">
        <h1><a href="index.php"><?= htmlspecialchars($siteName) ?></a></h1>
        <?php if ($siteDesc): ?>
            <p><?= htmlspecialchars($siteDesc) ?></p>
        <?php endif; ?>
        <a href="feed.php" class="rss-link">
            <svg class="rss-icon" viewBox="0 0 24 24"><circle cx="6.18" cy="17.82" r="2.18"/><path d="M4 4.44v2.83c7.03 0 12.73 5.7 12.73 12.73h2.83c0-8.59-6.97-15.56-15.56-15.56z"/><path d="M4 10.1v2.83c3.9 0 7.07 3.17 7.07 7.07h2.83c0-5.47-4.43-9.9-9.9-9.9z"/></svg>
            Feed RSS
        </a>
    </header>

    <?php if ($viewArticle): ?>

        <article class="article-full">
            <a href="index.php" class="back">&larr; Torna alla lista</a>

            <h1><?= htmlspecialchars($viewArticle['title']) ?></h1>

            <?php if (!empty($viewArticle['pubDate'])): ?>
                <div class="meta">
                    Pubblicato il <?= date('d/m/Y \a\l\l\e H:i', strtotime($viewArticle['pubDate'])) ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($viewArticle['image'])): ?>
                <div class="article-featured-image">
                    <img src="<?= htmlspecialchars($viewArticle['image']) ?>" alt="<?= htmlspecialchars($viewArticle['title']) ?>" loading="lazy">
                </div>
            <?php endif; ?>

            <div class="article-body">
                <?= strip_tags($viewArticle['content'], '<h1><h2><h3><h4><h5><h6><p><ul><ol><li><a><strong><em><b><i><br><img><blockquote><table><thead><tbody><tr><th><td><figure><figcaption><div><span><hr>') ?>
            </div>
        </article>

    <?php elseif (empty($articles)): ?>

        <div class="empty-state">
            <h2>Nessun articolo ancora disponibile</h2>
            <p>Gli articoli verranno pubblicati automaticamente. Torna a trovarci!</p>
        </div>

    <?php else: ?>

        <div class="article-list">
            <?php foreach ($articlesPage as $i => $article):
                $globalIndex = $offset + $i;
                $excerpt = mb_substr(strip_tags($article['content']), 0, 250) . '...';
                $dateFormatted = !empty($article['pubDate']) ? date('d/m/Y', strtotime($article['pubDate'])) : '';
            ?>
                <div class="article-item">
                    <?php if (!empty($article['image'])): ?>
                        <a href="?article=<?= $globalIndex ?>"><img src="<?= htmlspecialchars($article['image']) ?>" alt="<?= htmlspecialchars($article['title']) ?>" class="thumb" loading="lazy"></a>
                    <?php endif; ?>
                    <h2><a href="?article=<?= $globalIndex ?>"><?= htmlspecialchars($article['title']) ?></a></h2>
                    <?php if ($dateFormatted): ?>
                        <div class="article-date"><?= $dateFormatted ?></div>
                    <?php endif; ?>
                    <p class="article-excerpt"><?= htmlspecialchars($excerpt) ?></p>
                    <a href="?article=<?= $globalIndex ?>" class="read-more">Leggi l'articolo &rarr;</a>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($totalPages > 1): ?>
            <nav class="pagination">
                <?php if ($currentPage > 1): ?>
                    <a href="?page=<?= $currentPage - 1 ?>">&laquo; Prec</a>
                <?php endif; ?>

                <?php
                $start = max(1, $currentPage - 2);
                $end = min($totalPages, $currentPage + 2);
                for ($p = $start; $p <= $end; $p++):
                ?>
                    <?php if ($p === $currentPage): ?>
                        <span class="current"><?= $p ?></span>
                    <?php else: ?>
                        <a href="?page=<?= $p ?>"><?= $p ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($currentPage < $totalPages): ?>
                    <a href="?page=<?= $currentPage + 1 ?>">Succ &raquo;</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>

    <?php endif; ?>

    <footer class="site-footer">
        &copy; <?= date('Y') ?> <?= htmlspecialchars($siteName) ?>
    </footer>

</div>

</body>
</html>
