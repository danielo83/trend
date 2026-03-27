    <div class="header">
        <h2>Gestione Feed RSS</h2>
        <div>
            <?php if (file_exists($config['feed_path'])): ?>
                <a href="feed.php" target="_blank" class="btn btn-primary btn-sm">Apri Feed XML</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="stats">
        <div class="stat-card">
            <div class="label">Item nel Feed</div>
            <div class="value blue"><?= $feedCount ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Max Item</div>
            <div class="value purple"><?= $config['max_feed_items'] ?></div>
        </div>
    </div>

    <div class="card">
        <h3>Contenuti del Feed</h3>
        <?php if (empty($feedItems)): ?>
            <p style="color: #64748b;">Il feed e' vuoto.</p>
        <?php else: ?>
            <?php
                $countPublished   = count(array_filter($feedItems, fn($i) => !empty($i['wp_post_id'])));
                $countUnpublished = count($feedItems) - $countPublished;
            ?>
            <div style="display:flex;gap:8px;margin-bottom:16px;">
                <button type="button" onclick="filterFeed('all')" id="filter-all" class="btn btn-sm" style="background:#6366f1;color:white;">Tutti (<?= count($feedItems) ?>)</button>
                <button type="button" onclick="filterFeed('published')" id="filter-published" class="btn btn-sm" style="background:#1e293b;color:#94a3b8;border:1px solid #334155;">Pubblicati (<?= $countPublished ?>)</button>
                <button type="button" onclick="filterFeed('unpublished')" id="filter-unpublished" class="btn btn-sm" style="background:#1e293b;color:#94a3b8;border:1px solid #334155;">Non pubblicati (<?= $countUnpublished ?>)</button>
            </div>
            <form method="post" id="bulkDeleteForm" onsubmit="return confirmBulkDelete()">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="delete_feed_items_bulk">
                <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;padding:12px;background:#0f172a;border-radius:8px;border:1px solid #334155;">
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;color:#94a3b8;font-size:13px;">
                        <input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)" style="width:16px;height:16px;accent-color:#6366f1;">
                        Seleziona tutti
                    </label>
                    <span id="selectedCount" style="color:#64748b;font-size:13px;"></span>
                    <button type="submit" class="btn btn-danger btn-sm" id="bulkDeleteBtn" style="display:none;margin-left:auto;">
                        Elimina selezionati (<span id="deleteCount">0</span>)
                    </button>
                </div>

                <?php foreach ($feedItems as $idx => $item): ?>
                    <div class="feed-item" id="feed-item-<?= $idx ?>" data-published="<?= !empty($item['wp_post_id']) ? '1' : '0' ?>">
                        <div style="display:flex;align-items:flex-start;gap:10px;">
                            <input type="checkbox" name="selected_items[]" value="<?= $idx ?>" class="item-checkbox" onchange="updateBulkUI()" style="width:16px;height:16px;margin-top:3px;accent-color:#6366f1;flex-shrink:0;">
                            <div style="flex:1;min-width:0;">
                                <?php if (!empty($item['image'])): ?>
                                    <div style="margin-bottom:10px;">
                                        <img src="<?= htmlspecialchars($item['image']) ?>" alt="<?= htmlspecialchars($item['title']) ?>" style="max-width:100%;max-height:200px;border-radius:8px;object-fit:cover;">
                                    </div>
                                <?php endif; ?>
                                <h4 id="title-display-<?= $idx ?>"><?= htmlspecialchars($item['title']) ?></h4>
                                <div class="date">
                                    <?= htmlspecialchars($item['pubDate']) ?>
                                    <?php
                                        $wpUrl = $config['wp_site_url'] ?? '';
                                        $iLinks = countInternalLinks($item['content'], $wpUrl);
                                        $eLinks = countExternalLinks($item['content'], $wpUrl);
                                    ?>
                                    <?php if ($iLinks > 0): ?>
                                        <span class="badge links-yes" style="margin-left:8px;" title="<?= $iLinks ?> link interni, <?= $eLinks ?> esterni">🔗 <?= $iLinks ?> interni / <?= $eLinks ?> esterni</span>
                                    <?php else: ?>
                                        <span class="badge links-no" style="margin-left:8px;" title="Nessun link building applicato">Nessun link</span>
                                    <?php endif; ?>
                                    <?php if (!empty($item['wp_post_id'])): ?>
                                        <span class="badge completed" style="margin-left:4px;">WP #<?= $item['wp_post_id'] ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="content-preview" id="preview-<?= $idx ?>"><?= mb_substr(strip_tags($item['content']), 0, 200) ?>...</div>
                                <div class="actions" style="display:flex;gap:8px;flex-wrap:wrap;">
                                    <button type="button" class="btn btn-primary btn-sm" onclick="toggleContent(<?= $idx ?>)">Mostra/Nascondi</button>
                                    <button type="button" class="btn btn-sm" style="background:#d97706;color:white;" onclick="openEditor(<?= $idx ?>)">Modifica</button>
                                    <?php if (!empty($config['wp_enabled'])): ?>
                                        <?php if (!empty($item['wp_post_id'])): ?>
                                        <span class="btn btn-sm" style="background:#065f46;color:#4ade80;cursor:default;opacity:0.9;" id="wp-btn-<?= $idx ?>">Pubblicato su WP</span>
                                        <a href="<?= htmlspecialchars($item['wp_post_url']) ?>" target="_blank" style="color:#4ade80;font-size:12px;text-decoration:underline;">Vedi post</a>
                                        <?php else: ?>
                                        <button type="button" class="btn btn-sm" style="background:#059669;color:white;" onclick="publishToWP(<?= $idx ?>, this)" id="wp-btn-<?= $idx ?>">Pubblica su WP</button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                                <div class="content-full" id="content-<?= $idx ?>"><?= $item['content'] ?></div>

                                <!-- Editor inline -->
                                <div class="edit-panel" id="editor-<?= $idx ?>" style="display:none;margin-top:12px;padding:16px;background:#0f172a;border:1px solid #334155;border-radius:8px;">
                                    <div style="margin-bottom:10px;">
                                        <label style="display:block;font-size:12px;color:#94a3b8;margin-bottom:4px;">Titolo</label>
                                        <input type="text" id="edit-title-<?= $idx ?>" value="<?= htmlspecialchars($item['title']) ?>" style="width:100%;padding:8px 12px;background:#1e293b;border:1px solid #475569;border-radius:6px;color:#f1f5f9;font-size:14px;">
                                    </div>
                                    <div style="margin-bottom:10px;">
                                        <label style="display:block;font-size:12px;color:#94a3b8;margin-bottom:4px;">Contenuto (HTML)</label>
                                        <textarea id="edit-content-<?= $idx ?>" rows="18" style="width:100%;padding:8px 12px;background:#1e293b;border:1px solid #475569;border-radius:6px;color:#f1f5f9;font-family:'Fira Code',Consolas,monospace;font-size:12px;line-height:1.5;resize:vertical;"><?= htmlspecialchars($item['content']) ?></textarea>
                                    </div>
                                    <div style="display:flex;gap:8px;">
                                        <button type="button" class="btn btn-sm" style="background:#059669;color:white;" onclick="saveEdit(<?= $idx ?>)">Salva</button>
                                        <button type="button" class="btn btn-sm" style="background:#475569;color:white;" onclick="closeEditor(<?= $idx ?>)">Annulla</button>
                                        <span id="edit-status-<?= $idx ?>" style="font-size:12px;line-height:28px;margin-left:8px;"></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </form>
        <?php endif; ?>
    </div>

