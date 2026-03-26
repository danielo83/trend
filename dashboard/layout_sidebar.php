<body>

<div class="mobile-topbar">
    <h1>AutoPilot</h1>
    <button class="hamburger" onclick="toggleSidebar()" aria-label="Menu">&#9776;</button>
</div>
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<div class="sidebar" id="sidebar">
    <h1>AutoPilot</h1>
    <div style="padding: 0 20px 15px; font-size: 11px; color: #64748b; border-bottom: 1px solid #334155; margin-bottom: 5px;">
        Nicchia: <strong style="color: #818cf8;"><?= htmlspecialchars($config['niche_name'] ?? 'Non configurata') ?></strong>
    </div>
    <nav>
        <a href="?tab=overview" class="<?= $tab === 'overview' ? 'active' : '' ?>">Panoramica</a>
        <a href="?tab=feed" class="<?= $tab === 'feed' ? 'active' : '' ?>">Gestione Feed</a>
        <a href="?tab=topics" class="<?= $tab === 'topics' ? 'active' : '' ?>">Topic Elaborati</a>
        <a href="?tab=config" class="<?= $tab === 'config' ? 'active' : '' ?>">Configurazione</a>
        <a href="?tab=logs" class="<?= $tab === 'logs' ? 'active' : '' ?>">Log</a>
        <a href="?tab=linkbuilding" class="<?= $tab === 'linkbuilding' ? 'active' : '' ?>">Link Building</a>
        <a href="?tab=seo" class="<?= $tab === 'seo' ? 'active' : '' ?>">SEO Analytics</a>
        <a href="?tab=contenthub" class="<?= $tab === 'contenthub' ? 'active' : '' ?>">🏛️ Content Hub</a>
        <a href="?tab=richresults" class="<?= $tab === 'richresults' ? 'active' : '' ?>">⭐ Rich Results</a>
        <a href="?tab=rewrite" class="<?= $tab === 'rewrite' ? 'active' : '' ?>">Riscrittura</a>
        <a href="?tab=factcheck" class="<?= $tab === 'factcheck' ? 'active' : '' ?>">Fact Check</a>
        <a href="?logout=1" style="margin-top:20px; border-top:1px solid #334155; padding-top:20px; color:#f87171;">Esci</a>
    </nav>
</div>

<div class="main">

<?php if (!empty($message)): ?>
    <div class="message <?= htmlspecialchars($messageType) ?>"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

