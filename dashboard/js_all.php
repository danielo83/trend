<script>
// --- Config sub-tabs ---
function showCfgTab(name) {
    document.querySelectorAll('.cfg-panel').forEach(function(p) { p.classList.remove('active'); });
    document.querySelectorAll('.cfg-tab').forEach(function(t) { t.classList.remove('active'); });
    var panel = document.getElementById('cfg-' + name);
    if (panel) panel.classList.add('active');
    event.currentTarget.classList.add('active');
}

// --- Keyword source toggle ---
function toggleKeywordSource() {
    var source = document.getElementById('keyword_source').value;
    document.getElementById('google_keywords_section').style.display = source === 'google' ? '' : 'none';
    document.getElementById('manual_keywords_section').style.display = source === 'manual' ? '' : 'none';
}

// --- Mobile sidebar toggle ---
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('open');
}
function closeSidebar() {
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('sidebarOverlay').classList.remove('open');
}
// Close sidebar on nav link click (mobile)
document.querySelectorAll('.sidebar a').forEach(function(a) {
    a.addEventListener('click', closeSidebar);
});

function toggleContent(idx) {
    const el = document.getElementById('content-' + idx);
    el.classList.toggle('show');
}

// --- Selezione multipla articoli feed ---
function filterRecs(priority) {
    document.querySelectorAll('.hub-rec-item').forEach(el => {
        el.style.display = (priority === 'all' || el.dataset.priority === priority) ? '' : 'none';
    });
    ['all', 'CRITICAL', 'HIGH', 'MEDIUM'].forEach(p => {
        const btn = document.getElementById('recfilter-' + p);
        if (!btn) return;
        if (p === priority) {
            btn.style.background = '#6366f1'; btn.style.color = 'white'; btn.style.border = '';
        } else {
            btn.style.background = '#1e293b'; btn.style.color = '#94a3b8'; btn.style.border = '1px solid #334155';
        }
    });
}

function filterFeed(type) {
    const items = document.querySelectorAll('.feed-item[data-published]');
    items.forEach(item => {
        const pub = item.dataset.published === '1';
        if (type === 'all' || (type === 'published' && pub) || (type === 'unpublished' && !pub)) {
            item.style.display = '';
        } else {
            item.style.display = 'none';
        }
    });
    ['all', 'published', 'unpublished'].forEach(t => {
        const btn = document.getElementById('filter-' + t);
        if (!btn) return;
        if (t === type) {
            btn.style.background = '#6366f1'; btn.style.color = 'white'; btn.style.border = '';
        } else {
            btn.style.background = '#1e293b'; btn.style.color = '#94a3b8'; btn.style.border = '1px solid #334155';
        }
    });
}

function toggleSelectAll(checkbox) {
    const items = document.querySelectorAll('.item-checkbox');
    items.forEach(item => item.checked = checkbox.checked);
    updateBulkUI();
}

function updateBulkUI() {
    const checkboxes = document.querySelectorAll('.item-checkbox');
    const checked = document.querySelectorAll('.item-checkbox:checked');
    const count = checked.length;
    const total = checkboxes.length;

    const btn = document.getElementById('bulkDeleteBtn');
    const countSpan = document.getElementById('deleteCount');
    const selectedInfo = document.getElementById('selectedCount');
    const selectAllCheckbox = document.getElementById('selectAll');

    if (btn) {
        btn.style.display = count > 0 ? 'inline-block' : 'none';
    }
    if (countSpan) {
        countSpan.textContent = count;
    }
    if (selectedInfo) {
        selectedInfo.textContent = count > 0 ? count + ' di ' + total + ' selezionati' : '';
    }
    if (selectAllCheckbox) {
        selectAllCheckbox.checked = count === total && total > 0;
        selectAllCheckbox.indeterminate = count > 0 && count < total;
    }
}

function confirmBulkDelete() {
    const count = document.querySelectorAll('.item-checkbox:checked').length;
    if (count === 0) {
        alert('Seleziona almeno un articolo da eliminare.');
        return false;
    }
    return confirm('Eliminare ' + count + ' articoli dal feed?');
}

// --- Editor articoli feed ---
function openEditor(idx) {
    // Chiudi altri editor aperti
    document.querySelectorAll('.edit-panel').forEach(function(el) { el.style.display = 'none'; });
    document.getElementById('editor-' + idx).style.display = 'block';
    // Chiudi la preview del contenuto se aperta
    var contentFull = document.getElementById('content-' + idx);
    if (contentFull) contentFull.classList.remove('show');
}

function closeEditor(idx) {
    document.getElementById('editor-' + idx).style.display = 'none';
}

function saveEdit(idx) {
    var titleInput = document.getElementById('edit-title-' + idx);
    var contentInput = document.getElementById('edit-content-' + idx);
    var statusEl = document.getElementById('edit-status-' + idx);

    var newTitle = titleInput.value.trim();
    var newContent = contentInput.value.trim();

    if (!newTitle || !newContent) {
        statusEl.textContent = 'Titolo e contenuto non possono essere vuoti.';
        statusEl.style.color = '#fca5a5';
        return;
    }

    statusEl.textContent = 'Salvataggio...';
    statusEl.style.color = '#94a3b8';

    var formData = new FormData();
    formData.append('csrf_token', '<?= htmlspecialchars($csrfToken) ?>');
    formData.append('action', 'edit_feed_item');
    formData.append('item_index', idx);
    formData.append('new_title', newTitle);
    formData.append('new_content', newContent);

    fetch('dashboard.php', { method: 'POST', body: formData })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.success) {
                statusEl.textContent = 'Salvato!';
                statusEl.style.color = '#4ade80';

                // Aggiorna UI senza ricaricare la pagina
                var titleDisplay = document.getElementById('title-display-' + idx);
                if (titleDisplay) titleDisplay.textContent = newTitle;

                var preview = document.getElementById('preview-' + idx);
                if (preview) {
                    var tmp = document.createElement('div');
                    tmp.innerHTML = newContent;
                    var text = tmp.textContent || tmp.innerText || '';
                    preview.textContent = text.substring(0, 200) + '...';
                }

                var contentFull = document.getElementById('content-' + idx);
                if (contentFull) contentFull.innerHTML = newContent;

                setTimeout(function() { closeEditor(idx); }, 800);
            } else {
                statusEl.textContent = 'Errore: ' + data.message;
                statusEl.style.color = '#fca5a5';
            }
        })
        .catch(function(err) {
            statusEl.textContent = 'Errore di rete: ' + err.message;
            statusEl.style.color = '#fca5a5';
        });
}

// --- WordPress Test Connection ---
function testWpConnection() {
    var resultEl = document.getElementById('wpTestResult');
    resultEl.textContent = 'Connessione in corso...';
    resultEl.style.color = '#94a3b8';

    var formData = new FormData();
    formData.append('csrf_token', '<?= htmlspecialchars($csrfToken) ?>');
    formData.append('action', 'wp_test_connection');

    fetch('dashboard.php', { method: 'POST', body: formData })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.success) {
                resultEl.textContent = data.message;
                resultEl.style.color = '#4ade80';
            } else {
                resultEl.textContent = data.message;
                resultEl.style.color = '#fca5a5';
            }
        })
        .catch(function(err) {
            resultEl.textContent = 'Errore di rete: ' + err.message;
            resultEl.style.color = '#fca5a5';
        });
}

// --- Link Building ---
function refreshLinkCache() {
    var resultEl = document.getElementById('linkCacheResult');
    resultEl.textContent = 'Aggiornamento cache...';
    resultEl.style.color = '#94a3b8';

    var formData = new FormData();
    formData.append('csrf_token', '<?= htmlspecialchars($csrfToken) ?>');
    formData.append('action', 'refresh_link_cache');

    fetch('dashboard.php', { method: 'POST', body: formData })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.success) {
                resultEl.textContent = data.message;
                resultEl.style.color = '#4ade80';
                var countEl = document.getElementById('linkCacheCount');
                if (countEl) countEl.textContent = data.count;
            } else {
                resultEl.textContent = data.message;
                resultEl.style.color = '#fca5a5';
            }
        })
        .catch(function(err) {
            resultEl.textContent = 'Errore: ' + err.message;
            resultEl.style.color = '#fca5a5';
        });
}

function relinkBulk() {
    var maxPosts = prompt('Quanti articoli vuoi processare? (max 50)', '10');
    if (!maxPosts) return;
    maxPosts = parseInt(maxPosts);
    if (isNaN(maxPosts) || maxPosts < 1) return;

    var resultEl = document.getElementById('linkCacheResult');
    resultEl.textContent = 'Elaborazione in corso... (puo\' richiedere qualche minuto)';
    resultEl.style.color = '#fbbf24';

    var formData = new FormData();
    formData.append('csrf_token', '<?= htmlspecialchars($csrfToken) ?>');
    formData.append('action', 'relink_wp_bulk');
    formData.append('max_posts', maxPosts);

    fetch('dashboard.php', { method: 'POST', body: formData })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.success) {
                resultEl.textContent = data.message;
                resultEl.style.color = '#4ade80';
            } else {
                resultEl.textContent = data.message;
                resultEl.style.color = '#fca5a5';
            }
        })
        .catch(function(err) {
            resultEl.textContent = 'Errore: ' + err.message;
            resultEl.style.color = '#fca5a5';
        });
}

// --- Link Building Tab ---
function lbRefreshCache() {
    var statusBar = document.getElementById('lbStatusBar');
    statusBar.style.display = 'block';
    statusBar.style.background = '#1e3a5f';
    statusBar.style.color = '#93c5fd';
    statusBar.textContent = 'Aggiornamento cache articoli...';

    var formData = new FormData();
    formData.append('csrf_token', '<?= htmlspecialchars($csrfToken) ?>');
    formData.append('action', 'refresh_link_cache');

    fetch('dashboard.php', { method: 'POST', body: formData })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.success) {
                statusBar.style.background = '#064e3b';
                statusBar.style.color = '#4ade80';
                statusBar.textContent = data.message + ' Ricarica la pagina per vedere la lista aggiornata.';
                var countEl = document.getElementById('lbCacheCount');
                if (countEl) countEl.textContent = data.count;
            } else {
                statusBar.style.background = '#7f1d1d';
                statusBar.style.color = '#fca5a5';
                statusBar.textContent = data.message;
            }
        })
        .catch(function(err) {
            statusBar.style.background = '#7f1d1d';
            statusBar.style.color = '#fca5a5';
            statusBar.textContent = 'Errore: ' + err.message;
        });
}

function lbToggleSelectAll(master) {
    var checkboxes = document.querySelectorAll('.lb-checkbox');
    checkboxes.forEach(function(cb) { cb.checked = master.checked; });
    lbUpdateBulkUI();
}

function lbUpdateBulkUI() {
    var checkboxes = document.querySelectorAll('.lb-checkbox');
    var checked = document.querySelectorAll('.lb-checkbox:checked');
    var count = checked.length;
    var countEl = document.getElementById('lbSelectedCount');
    var bulkBtn = document.getElementById('lbBulkBtn');
    var bulkCount = document.getElementById('lbBulkCount');

    if (count > 0) {
        countEl.textContent = count + ' di ' + checkboxes.length + ' selezionati in questa pagina';
        bulkBtn.style.display = 'inline-block';
        bulkCount.textContent = count;
    } else {
        countEl.textContent = '';
        bulkBtn.style.display = 'none';
    }

    var selectAll = document.getElementById('lbSelectAll');
    if (selectAll) {
        selectAll.checked = count === checkboxes.length && count > 0;
    }
}

function lbRelinkSingle(postId, btn) {
    if (!confirm('Applicare il link building a questo articolo?')) return;

    var originalText = btn.textContent;
    btn.textContent = 'Elaborazione...';
    btn.disabled = true;
    btn.style.opacity = '0.6';
    var statusEl = document.getElementById('lb-status-' + postId);
    statusEl.textContent = '';

    var formData = new FormData();
    formData.append('csrf_token', '<?= htmlspecialchars($csrfToken) ?>');
    formData.append('action', 'relink_wp_article');
    formData.append('wp_post_id', postId);

    fetch('dashboard.php', { method: 'POST', body: formData })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.success) {
                btn.textContent = '✓ Completato';
                btn.style.background = '#065f46';
                btn.style.color = '#4ade80';
                statusEl.textContent = data.message;
                statusEl.style.color = '#4ade80';
                // Aggiorna il badge dei link
                if (typeof data.internal_links !== 'undefined') {
                    lbUpdateLinkBadge(postId, data.internal_links, data.external_links || 0);
                }
            } else {
                btn.textContent = originalText;
                btn.disabled = false;
                btn.style.opacity = '1';
                statusEl.textContent = data.message;
                statusEl.style.color = '#fca5a5';
            }
        })
        .catch(function(err) {
            btn.textContent = originalText;
            btn.disabled = false;
            btn.style.opacity = '1';
            statusEl.textContent = 'Errore: ' + err.message;
            statusEl.style.color = '#fca5a5';
        });
}

function lbRelinkSelected() {
    var checked = document.querySelectorAll('.lb-checkbox:checked');
    if (checked.length === 0) return;
    if (!confirm('Applicare il link building a ' + checked.length + ' articoli? Questa operazione puo\' richiedere diversi minuti.')) return;

    var postIds = [];
    checked.forEach(function(cb) { postIds.push(parseInt(cb.value)); });

    var statusBar = document.getElementById('lbStatusBar');
    statusBar.style.display = 'block';
    statusBar.style.background = '#1e3a5f';
    statusBar.style.color = '#fbbf24';
    statusBar.textContent = 'Elaborazione di ' + postIds.length + ' articoli in corso... (puo\' richiedere qualche minuto)';

    var bulkBtn = document.getElementById('lbBulkBtn');
    bulkBtn.disabled = true;
    bulkBtn.style.opacity = '0.6';

    var formData = new FormData();
    formData.append('csrf_token', '<?= htmlspecialchars($csrfToken) ?>');
    formData.append('action', 'relink_wp_selected');
    formData.append('post_ids', JSON.stringify(postIds));

    fetch('dashboard.php', { method: 'POST', body: formData })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            bulkBtn.disabled = false;
            bulkBtn.style.opacity = '1';
            if (data.success) {
                statusBar.style.background = '#064e3b';
                statusBar.style.color = '#4ade80';
                statusBar.textContent = data.message;
                // Segna gli articoli processati e aggiorna badge
                checked.forEach(function(cb) {
                    var item = document.getElementById('lb-item-' + cb.value);
                    if (item) {
                        var btn = item.querySelector('.btn-primary');
                        if (btn && data.processed > 0) {
                            btn.textContent = '✓ Completato';
                            btn.style.background = '#065f46';
                            btn.style.color = '#4ade80';
                        }
                    }
                });
                // Re-verifica i link dopo il bulk
                setTimeout(function() { lbCheckAllLinks(); }, 1000);
            } else {
                statusBar.style.background = '#7f1d1d';
                statusBar.style.color = '#fca5a5';
                statusBar.textContent = data.message;
            }
        })
        .catch(function(err) {
            bulkBtn.disabled = false;
            bulkBtn.style.opacity = '1';
            statusBar.style.background = '#7f1d1d';
            statusBar.style.color = '#fca5a5';
            statusBar.textContent = 'Errore: ' + err.message;
        });
}

// --- Link Building: Check links status on page load ---
function lbUpdateLinkBadge(postId, internal, external) {
    var badge = document.getElementById('lb-links-badge-' + postId);
    if (!badge) return;
    if (internal < 0) {
        badge.className = 'badge links-no';
        badge.innerHTML = '<span class="link-indicator">⚠️ Errore verifica</span>';
        return;
    }
    if (internal > 0) {
        badge.className = 'badge links-yes';
        badge.innerHTML = '<span class="link-indicator">🔗 ' + internal + ' interni / ' + external + ' esterni</span>';
        // Cambia il bottone se ha già link
        var btn = document.getElementById('lb-btn-' + postId);
        if (btn && !btn.disabled) {
            btn.textContent = 'Aggiorna Link (' + internal + ')';
            btn.style.background = '#065f46';
        }
    } else {
        badge.className = 'badge links-no';
        badge.innerHTML = '<span class="link-indicator">Nessun link</span>';
    }
}

function lbCheckAllLinks() {
    var checkboxes = document.querySelectorAll('.lb-checkbox');
    if (checkboxes.length === 0) return;

    var postIds = [];
    checkboxes.forEach(function(cb) { postIds.push(parseInt(cb.value)); });

    // Con la paginazione abbiamo max ~20 articoli per pagina.
    // Facciamo 2 batch da 10 in sequenza per non sovraccaricare.
    var batchSize = 10;
    var batches = [];
    for (var i = 0; i < postIds.length; i += batchSize) {
        batches.push(postIds.slice(i, i + batchSize));
    }

    function processBatch(index) {
        if (index >= batches.length) return;
        var batch = batches[index];

        var formData = new FormData();
        formData.append('csrf_token', '<?= htmlspecialchars($csrfToken) ?>');
        formData.append('action', 'check_wp_links');
        formData.append('post_ids', JSON.stringify(batch));

        fetch('dashboard.php', { method: 'POST', body: formData })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data.success && data.results) {
                    for (var pid in data.results) {
                        lbUpdateLinkBadge(pid, data.results[pid].internal, data.results[pid].external);
                    }
                }
                // Processa il batch successivo dopo 500ms
                setTimeout(function() { processBatch(index + 1); }, 500);
            })
            .catch(function() {
                batch.forEach(function(pid) {
                    lbUpdateLinkBadge(pid, -1, -1);
                });
                setTimeout(function() { processBatch(index + 1); }, 500);
            });
    }

    processBatch(0);
}

// Auto-check links when link building tab is visible
if (document.querySelector('.lb-checkbox')) {
    lbCheckAllLinks();
}

// --- WordPress Publish Article ---
function publishToWP(index, btn) {
    if (!confirm('Pubblicare questo articolo su WordPress?')) return;

    var originalText = btn.textContent;
    btn.textContent = 'Pubblicazione...';
    btn.disabled = true;
    btn.style.opacity = '0.6';

    var formData = new FormData();
    formData.append('csrf_token', '<?= htmlspecialchars($csrfToken) ?>');
    formData.append('action', 'wp_publish_article');
    formData.append('item_index', index);

    fetch('dashboard.php', { method: 'POST', body: formData })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.success) {
                // Sostituisci il pulsante con lo stato "Pubblicato"
                btn.textContent = 'Pubblicato su WP';
                btn.style.background = '#065f46';
                btn.style.color = '#4ade80';
                btn.style.cursor = 'default';
                btn.style.opacity = '0.9';
                btn.disabled = true;
                btn.onclick = null;
                if (data.post_url) {
                    var link = document.createElement('a');
                    link.href = data.post_url;
                    link.target = '_blank';
                    link.textContent = 'Vedi post';
                    link.style.cssText = 'color:#4ade80;font-size:12px;text-decoration:underline;';
                    btn.parentNode.appendChild(link);
                }
            } else {
                btn.textContent = 'Errore';
                btn.style.background = '#991b1b';
                alert('Errore: ' + data.message);
                setTimeout(function() {
                    btn.textContent = originalText;
                    btn.style.background = '#059669';
                    btn.style.opacity = '1';
                    btn.disabled = false;
                }, 3000);
            }
        })
        .catch(function(err) {
            btn.textContent = 'Errore';
            btn.style.background = '#991b1b';
            alert('Errore di rete: ' + err.message);
            setTimeout(function() {
                btn.textContent = originalText;
                btn.style.background = '#059669';
                btn.style.opacity = '1';
                btn.disabled = false;
            }, 3000);
        });
}

// Gestione cambio modello fal.ai per aggiornare le opzioni dimensioni
document.addEventListener('DOMContentLoaded', function() {
    const modelSelect = document.querySelector('select[name="fal_model_id"]');
    const sizeSelect = document.getElementById('fal_image_size');
    const inlineSizeSelect = document.querySelector('select[name="fal_inline_size"]');
    const sizeHint = document.getElementById('size_hint');

    function updateSizeOptions(isGPTImage, targetSelect) {
        targetSelect.innerHTML = '';

        if (isGPTImage) {
            const gptSizes = {
                '1024x1024': '1024x1024 (Square - Min crediti)',
                '1536x1024': '1536x1024 (Landscape - Orizzontale)',
                '1024x1536': '1024x1536 (Portrait - Verticale)'
            };
            for (const [value, label] of Object.entries(gptSizes)) {
                const option = document.createElement('option');
                option.value = value;
                option.textContent = label;
                if (value === '1024x1024') option.selected = true;
                targetSelect.appendChild(option);
            }
        } else {
            const fluxSizes = {
                'landscape_16_9': 'Landscape 16:9 (Panoramico)',
                'landscape_4_3': 'Landscape 4:3 (Classico)',
                'square': 'Square 1:1 (Quadrato)',
                'portrait_4_3': 'Portrait 4:3 (Verticale)',
                'portrait_16_9': 'Portrait 16:9 (Stories)'
            };
            for (const [value, label] of Object.entries(fluxSizes)) {
                const option = document.createElement('option');
                option.value = value;
                option.textContent = label;
                if (value === 'landscape_16_9') option.selected = true;
                targetSelect.appendChild(option);
            }
        }
    }

    if (modelSelect) {
        modelSelect.addEventListener('change', function() {
            const isGPTImage = this.value.includes('gpt-image');
            if (sizeSelect) updateSizeOptions(isGPTImage, sizeSelect);
            if (inlineSizeSelect) updateSizeOptions(isGPTImage, inlineSizeSelect);
            if (sizeHint) {
                sizeHint.textContent = isGPTImage
                    ? '1024x1024 = Square (più economico), 1536x1024 = Landscape, 1024x1536 = Portrait'
                    : 'Seleziona il formato adatto al tuo contenuto';
            }
        });
    }
});

// ============================================================
// RISCRITTURA - Selezione, filtri, avvio e polling
// ============================================================

var rwPolling = null;
var rwOffset = 0;
var rwActiveIds = []; // ID dei post in fase di riscrittura

// --- Filtri ---
function rwApplyFilters() {
    var search = document.getElementById('rwSearchInput') ? document.getElementById('rwSearchInput').value : '';
    var cat = document.getElementById('rwFilterCat') ? document.getElementById('rwFilterCat').value : '0';
    var status = document.getElementById('rwFilterStatus') ? document.getElementById('rwFilterStatus').value : 'all';
    var url = '?tab=rewrite';
    if (cat !== '0') url += '&rwcat=' + encodeURIComponent(cat);
    if (status !== 'all') url += '&rwstatus=' + encodeURIComponent(status);
    if (search) url += '&rwsearch=' + encodeURIComponent(search);
    window.location.href = url;
}

// Enter key nella ricerca
(function() {
    var searchInput = document.getElementById('rwSearchInput');
    if (searchInput) {
        searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') { e.preventDefault(); rwApplyFilters(); }
        });
    }
})();

// --- Selezione ---
function rwToggleSelectAll(checkbox) {
    document.querySelectorAll('.rw-checkbox').forEach(function(cb) { cb.checked = checkbox.checked; });
    rwUpdateBulkUI();
}

function rwUpdateBulkUI() {
    var all = document.querySelectorAll('.rw-checkbox');
    var checked = document.querySelectorAll('.rw-checkbox:checked');
    var count = checked.length;
    var total = all.length;

    var btn = document.getElementById('rwBulkBtn');
    var countSpan = document.getElementById('rwBulkCount');
    var info = document.getElementById('rwSelectedCount');
    var selectAll = document.getElementById('rwSelectAll');

    if (btn) btn.style.display = count > 0 ? 'inline-block' : 'none';
    if (countSpan) countSpan.textContent = count;
    if (info) info.textContent = count > 0 ? count + ' di ' + total + ' selezionati' : '';
    if (selectAll) {
        selectAll.checked = count === total && total > 0;
        selectAll.indeterminate = count > 0 && count < total;
    }
}

// --- Aggiorna Cache ---
function rwRefreshCache() {
    // Riusa il sistema del LinkBuilder per aggiornare la cache
    var btn = event.target;
    btn.disabled = true;
    btn.textContent = 'Aggiornamento...';
    fetch('dashboard.php?tab=rewrite&action=refresh_rw_cache&csrf_token=<?= $csrfToken ?>')
        .then(function(r) { return r.text(); })
        .then(function() { location.reload(); })
        .catch(function() { btn.disabled = false; btn.textContent = 'Aggiorna Cache'; });
}

// --- Riscrittura singolo articolo ---
function rwRewriteSingle(postId, btn) {
    if (!confirm('Vuoi riscrivere questo articolo?\n\nPuoi riscriverlo anche se è già stato riscritto in precedenza. Il permalink resterà invariato.')) return;

    var newImages = document.getElementById('rwNewImages') ? (document.getElementById('rwNewImages').checked ? '1' : '') : '';
    var params = 'action=start&ids=' + postId;
    if (newImages) params += '&new_images=1';

    btn.disabled = true;
    btn.textContent = 'Riscrittura...';
    btn.style.opacity = '0.6';

    var statusEl = document.getElementById('rw-status-' + postId);
    if (statusEl) { statusEl.textContent = 'In corso...'; statusEl.style.color = '#fbbf24'; }

    rwShowProgress();
    rwActiveIds = [postId];
    rwOffset = 0;

    fetch('rewrite_stream.php?' + params)
        .then(function() {
            setTimeout(function() { rwPolling = setInterval(rwPoll, 800); }, 1000);
        })
        .catch(function(err) {
            rwAddLog('Errore: ' + err, 'error');
            btn.disabled = false; btn.textContent = 'Riscrivi'; btn.style.opacity = '1';
        });
}

// --- Riscrittura selezionati ---
function rwStartSelected() {
    var checked = document.querySelectorAll('.rw-checkbox:checked');
    if (checked.length === 0) return;

    var ids = [];
    checked.forEach(function(cb) { ids.push(cb.value); });

    if (!confirm('Vuoi riscrivere ' + ids.length + ' articoli selezionati?')) return;

    var newImages = document.getElementById('rwNewImages') ? (document.getElementById('rwNewImages').checked ? '1' : '') : '';
    var params = 'action=start&ids=' + ids.join(',');
    if (newImages) params += '&new_images=1';

    // Disabilita tutti i pulsanti
    document.querySelectorAll('.rw-checkbox:checked').forEach(function(cb) {
        var pid = cb.value;
        var btn = document.getElementById('rw-btn-' + pid);
        if (btn) { btn.disabled = true; btn.textContent = 'In coda...'; btn.style.opacity = '0.6'; }
        var st = document.getElementById('rw-status-' + pid);
        if (st) { st.textContent = 'In coda'; st.style.color = '#fbbf24'; }
    });

    var bulkBtn = document.getElementById('rwBulkBtn');
    if (bulkBtn) { bulkBtn.disabled = true; bulkBtn.textContent = 'In esecuzione...'; bulkBtn.style.opacity = '0.6'; }

    rwShowProgress();
    rwActiveIds = ids;
    rwOffset = 0;

    fetch('rewrite_stream.php?' + params)
        .then(function() {
            setTimeout(function() { rwPolling = setInterval(rwPoll, 800); }, 1000);
        })
        .catch(function(err) {
            rwAddLog('Errore: ' + err, 'error');
            rwResetAllBtns();
        });
}

// --- Progress UI ---
function rwShowProgress() {
    var card = document.getElementById('rwProgressCard');
    if (card) { card.style.display = ''; card.scrollIntoView({behavior:'smooth', block:'nearest'}); }
    var log = document.getElementById('rwLogContainer');
    if (log) log.innerHTML = '';
    var summary = document.getElementById('rwSummary');
    if (summary) summary.style.display = 'none';
    var bar = document.getElementById('rwProgressBar');
    if (bar) bar.style.width = '0%';
    var pct = document.getElementById('rwProgressPercent');
    if (pct) pct.textContent = '0%';
}

function rwPoll() {
    fetch('rewrite_stream.php?action=poll&offset=' + rwOffset)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            rwOffset = data.offset;

            data.lines.forEach(function(line) {
                var event = line.event || line.type || '';

                if (event === 'log') {
                    rwAddLog(line.message || '', line.type || 'info');

                    // Aggiorna badge nella lista quando un post viene completato o fallisce
                    var msg = line.message || '';
                    var successMatch = msg.match(/Post (\d+) aggiornato/);
                    var failMatch = msg.match(/fallito per ID (\d+)/);
                    if (successMatch) rwUpdateItemStatus(successMatch[1], 'completed');
                    if (failMatch) rwUpdateItemStatus(failMatch[1], 'failed');
                } else if (event === 'section') {
                    rwAddLog('--- ' + (line.title || '') + ' ---', 'step');
                } else if (event === 'summary') {
                    var summaryEl = document.getElementById('rwSummary');
                    if (summaryEl) {
                        summaryEl.style.display = '';
                        summaryEl.innerHTML = '<div style="display:flex;gap:24px;flex-wrap:wrap;">'
                            + '<div><span style="color:#64748b;">Totale:</span> <strong style="color:#e2e8f0;">' + (line.total || 0) + '</strong></div>'
                            + '<div><span style="color:#64748b;">Successo:</span> <strong style="color:#34d399;">' + (line.success || 0) + '</strong></div>'
                            + '<div><span style="color:#64748b;">Falliti:</span> <strong style="color:#f87171;">' + (line.failed || 0) + '</strong></div>'
                            + '</div>';
                    }
                    var bar = document.getElementById('rwProgressBar');
                    if (bar) bar.style.width = '100%';
                    var pctEl = document.getElementById('rwProgressPercent');
                    if (pctEl) pctEl.textContent = '100%';
                }
            });

            if (data.done) {
                clearInterval(rwPolling);
                rwPolling = null;
                rwAddLog('Riscrittura completata!', 'success');
                rwResetAllBtns();
            }
        })
        .catch(function(err) {
            // Errore di rete, riprova
        });
}

function rwUpdateItemStatus(postId, status) {
    var btn = document.getElementById('rw-btn-' + postId);
    var st = document.getElementById('rw-status-' + postId);
    if (status === 'completed') {
        if (btn) { btn.textContent = 'Riscritto'; btn.style.background = '#059669'; btn.disabled = true; }
        if (st) { st.textContent = 'Completato'; st.style.color = '#34d399'; }
    } else {
        if (btn) { btn.textContent = 'Fallito'; btn.style.background = '#dc2626'; }
        if (st) { st.textContent = 'Errore'; st.style.color = '#f87171'; }
    }
}

function rwAddLog(message, type) {
    var container = document.getElementById('rwLogContainer');
    if (!container) return;

    var colors = {
        'success': '#34d399', 'error': '#f87171', 'warning': '#fbbf24',
        'step': '#818cf8', 'detail': '#94a3b8', 'info': '#94a3b8'
    };

    var div = document.createElement('div');
    div.style.color = colors[type] || '#94a3b8';
    div.style.padding = '2px 0';
    div.textContent = message;
    container.appendChild(div);
    container.scrollTop = container.scrollHeight;
}

function rwResetAllBtns() {
    rwActiveIds.forEach(function(pid) {
        var btn = document.getElementById('rw-btn-' + pid);
        if (btn && !btn.disabled) {
            btn.textContent = 'Riscrivi';
            btn.style.opacity = '1';
            btn.disabled = false;
        }
    });
    var bulkBtn = document.getElementById('rwBulkBtn');
    if (bulkBtn) { bulkBtn.disabled = false; bulkBtn.style.opacity = '1'; bulkBtn.textContent = 'Riscrivi selezionati (' + document.querySelectorAll('.rw-checkbox:checked').length + ')'; }
    rwActiveIds = [];
}

function rwResetLog() {
    if (!confirm('Sei sicuro? Questo resettera\' il log delle riscritture e permettera\' di riscrivere nuovamente tutti i post.')) return;

    fetch('dashboard.php?tab=rewrite&action=reset_rewrite_log&csrf_token=<?= $csrfToken ?>')
        .then(function(r) { return r.text(); })
        .then(function() { location.reload(); });
}

// ============================================================
// GENERAZIONE ARTICOLO ASINCRONA (Content Hub)
// ============================================================
var genPolling = null;
var genOffset = 0;

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.generate-btn').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            var keyword = this.getAttribute('data-keyword');
            var topic = this.getAttribute('data-topic');
            var index = this.getAttribute('data-index');
            startGeneration(keyword, topic, index, this);
        });
    });
});

function startGeneration(keyword, topic, index, btn) {
    if (!keyword) return;
    
    var container = document.getElementById('generate-container-' + index) || btn.closest('.generate-container');
    var progressDiv = container.querySelector('.generate-progress');
    var logsDiv = container.querySelector('.progress-logs');
    var fillDiv = container.querySelector('.progress-fill');
    
    // Disabilita bottone e mostra progresso
    btn.disabled = true;
    btn.textContent = 'Generazione in corso...';
    progressDiv.style.display = 'block';
    logsDiv.innerHTML = '';
    genOffset = 0;
    
    // Avvia polling
    if (genPolling) clearInterval(genPolling);
    genPolling = setInterval(function() { pollGeneration(index, btn, logsDiv, fillDiv, progressDiv); }, 800);
    
    // Avvia generazione
    var url = 'generate_stream.php?action=start&keyword=' + encodeURIComponent(keyword) + '&topic=' + encodeURIComponent(topic);
    fetch(url, { credentials: 'same-origin' })
        .then(function(r) { 
            if (!r.ok) {
                throw new Error('HTTP ' + r.status + ': ' + r.statusText);
            }
            return r.text(); 
        })
        .catch(function(err) {
            logsDiv.innerHTML += '<div style="color:#f87171;">Errore: ' + err.message + '</div>';
            btn.disabled = false;
            btn.textContent = '✨ Riprova';
            if (genPolling) clearInterval(genPolling);
        });
}

function pollGeneration(index, btn, logsDiv, fillDiv, progressDiv) {
    fetch('generate_stream.php?action=poll&offset=' + genOffset, { credentials: 'same-origin' })
        .then(function(r) { 
            if (!r.ok) {
                throw new Error('HTTP ' + r.status);
            }
            return r.json(); 
        })
        .then(function(data) {
            if (data.error) {
                logsDiv.innerHTML += '<div style="color:#f87171;padding:2px 0;">Errore: ' + data.error + '</div>';
                clearInterval(genPolling);
                genPolling = null;
                btn.disabled = false;
                btn.textContent = '✨ Riprova';
                return;
            }
            if (data.lines && data.lines.length > 0) {
                data.lines.forEach(function(line) {
                    var event = line.event || '';
                    if (event === 'log') {
                        var type = line.type || 'info';
                        var colors = { 'success': '#34d399', 'error': '#f87171', 'warning': '#fbbf24', 'detail': '#94a3b8', 'info': '#94a3b8' };
                        var color = colors[type] || '#94a3b8';
                        logsDiv.innerHTML += '<div style="color:' + color + ';padding:2px 0;">' + (line.message || '') + '</div>';
                        logsDiv.scrollTop = logsDiv.scrollHeight;
                    } else if (event === 'section') {
                        logsDiv.innerHTML += '<div style="color:#818cf8;font-weight:600;margin-top:8px;padding:2px 0;">▶ ' + (line.title || '') + '</div>';
                        logsDiv.scrollTop = logsDiv.scrollHeight;
                    } else if (event === 'summary') {
                        logsDiv.innerHTML += '<div style="color:#34d399;font-weight:600;margin-top:8px;padding:2px 0;">✓ Completato: ' + (line.title || '') + '</div>';
                        if (line.wp_url) {
                            logsDiv.innerHTML += '<div style="color:#34d399;padding:2px 0;"><a href="' + line.wp_url + '" target="_blank" style="color:#34d399;">Visualizza articolo</a></div>';
                        }
                        logsDiv.scrollTop = logsDiv.scrollHeight;
                    }
                });
                genOffset = data.offset;
                
                // Aggiorna progress bar
                var progress = Math.min(100, (genOffset / 30) * 100);
                fillDiv.style.width = progress + '%';
            }
            
            if (data.done) {
                clearInterval(genPolling);
                genPolling = null;
                fillDiv.style.width = '100%';
                btn.textContent = 'Completato!';
                btn.style.background = '#059669';
                setTimeout(function() {
                    location.reload();
                }, 2000);
            }
        })
        .catch(function(err) {
            // Errore di rete, riprova silenziosamente per 5 volte poi mostra errore
            if (!window.genErrorCount) window.genErrorCount = 0;
            window.genErrorCount++;
            if (window.genErrorCount > 5) {
                logsDiv.innerHTML += '<div style="color:#f87171;padding:2px 0;">Errore di connessione. Ricarica la pagina e riprova.</div>';
                clearInterval(genPolling);
                genPolling = null;
                btn.disabled = false;
                btn.textContent = '✨ Riprova';
            }
        });
}

// ============================================================
// FACT CHECK
// ============================================================
var fcPolling = null;
var fcOffset  = 0;
var fcActiveIds = [];

function fcApplyFilters() {
    var search = document.getElementById('fcSearchInput') ? document.getElementById('fcSearchInput').value : '';
    var cat    = document.getElementById('fcFilterCat')    ? document.getElementById('fcFilterCat').value    : '0';
    var status = document.getElementById('fcFilterStatus') ? document.getElementById('fcFilterStatus').value : 'all';
    var url = '?tab=factcheck';
    if (cat !== '0') url += '&fccat=' + encodeURIComponent(cat);
    if (status !== 'all') url += '&fcstatus=' + encodeURIComponent(status);
    if (search) url += '&fcsearch=' + encodeURIComponent(search);
    window.location.href = url;
}
(function() {
    var si = document.getElementById('fcSearchInput');
    if (si) si.addEventListener('keydown', function(e) { if (e.key === 'Enter') { e.preventDefault(); fcApplyFilters(); } });
})();

function fcToggleSelectAll(cb) {
    document.querySelectorAll('.fc-checkbox').forEach(function(c) { c.checked = cb.checked; });
    fcUpdateBulkUI();
}
function fcUpdateBulkUI() {
    var all     = document.querySelectorAll('.fc-checkbox');
    var checked = document.querySelectorAll('.fc-checkbox:checked');
    var count   = checked.length;
    var btn     = document.getElementById('fcBulkBtn');
    var countSp = document.getElementById('fcBulkCount');
    var info    = document.getElementById('fcSelectedCount');
    var selAll  = document.getElementById('fcSelectAll');
    if (btn)    btn.style.display = count > 0 ? 'inline-block' : 'none';
    if (countSp) countSp.textContent = count;
    if (info)   info.textContent = count > 0 ? count + ' di ' + all.length + ' selezionati' : '';
    if (selAll) { selAll.checked = count === all.length && all.length > 0; selAll.indeterminate = count > 0 && count < all.length; }
}

function fcCheckSingle(postId, btn) {
    btn.disabled = true; btn.textContent = 'Verifica...'; btn.style.opacity = '0.6';
    var statusEl = document.getElementById('fc-status-' + postId);
    if (statusEl) { statusEl.textContent = 'In corso...'; statusEl.style.color = '#fbbf24'; }
    fcShowProgress();
    fcActiveIds = [postId];
    fcOffset = 0;
    fetch('factcheck_stream.php?action=start&ids=' + postId)
        .then(function() { setTimeout(function() { fcPolling = setInterval(fcPoll, 800); }, 1000); })
        .catch(function(err) { fcAddLog('Errore: ' + err, 'error'); btn.disabled = false; btn.textContent = 'Verifica'; btn.style.opacity = '1'; });
}

function fcStartSelected() {
    var checked = document.querySelectorAll('.fc-checkbox:checked');
    if (checked.length === 0) return;
    var ids = [];
    checked.forEach(function(cb) { ids.push(cb.value); });
    if (!confirm('Vuoi verificare ' + ids.length + ' articoli selezionati?')) return;
    checked.forEach(function(cb) {
        var pid = cb.value;
        var btn = document.getElementById('fc-btn-' + pid);
        if (btn) { btn.disabled = true; btn.textContent = 'In coda...'; btn.style.opacity = '0.6'; }
        var st = document.getElementById('fc-status-' + pid);
        if (st) { st.textContent = 'In coda'; st.style.color = '#fbbf24'; }
    });
    var bulkBtn = document.getElementById('fcBulkBtn');
    if (bulkBtn) { bulkBtn.disabled = true; bulkBtn.style.opacity = '0.6'; bulkBtn.textContent = 'In esecuzione...'; }
    fcShowProgress();
    fcActiveIds = ids;
    fcOffset = 0;
    fetch('factcheck_stream.php?action=start&ids=' + ids.join(','))
        .then(function() { setTimeout(function() { fcPolling = setInterval(fcPoll, 800); }, 1000); })
        .catch(function(err) { fcAddLog('Errore: ' + err, 'error'); fcResetAllBtns(); });
}

function fcShowProgress() {
    var card = document.getElementById('fcProgressCard');
    if (card) { card.style.display = ''; card.scrollIntoView({behavior:'smooth', block:'nearest'}); }
    var log = document.getElementById('fcLogContainer');
    if (log) log.innerHTML = '';
    var summary = document.getElementById('fcSummary');
    if (summary) summary.style.display = 'none';
    var bar = document.getElementById('fcProgressBar');
    if (bar) bar.style.width = '0%';
    var pct = document.getElementById('fcProgressPercent');
    if (pct) pct.textContent = '0%';
}

function fcPoll() {
    fetch('factcheck_stream.php?action=poll&offset=' + fcOffset)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            fcOffset = data.offset;
            data.lines.forEach(function(line) {
                var ev = line.event || '';
                if (ev === 'log') {
                    fcAddLog(line.message || '', line.type || 'info');
                } else if (ev === 'section') {
                    fcAddLog('--- ' + (line.title || '') + ' ---', 'step');
                } else if (ev === 'result') {
                    fcUpdateItemStatus(line.post_id, line.status, line.score, line.issues);
                } else if (ev === 'summary') {
                    var summaryEl = document.getElementById('fcSummary');
                    if (summaryEl) {
                        summaryEl.style.display = '';
                        var issuesTxt = line.issues > 0 ? '<strong style="color:#fca5a5;">' + line.issues + ' con problemi</strong>' : '<strong style="color:#34d399;">nessun problema</strong>';
                        summaryEl.innerHTML = '<div style="display:flex;gap:24px;flex-wrap:wrap;">'
                            + '<div><span style="color:#64748b;">Totale:</span> <strong style="color:#e2e8f0;">' + (line.total || 0) + '</strong></div>'
                            + '<div><span style="color:#64748b;">Verificati:</span> <strong style="color:#34d399;">' + (line.success || 0) + '</strong></div>'
                            + '<div><span style="color:#64748b;">Risultato:</span> ' + issuesTxt + '</div>'
                            + '<div><span style="color:#64748b;">Falliti:</span> <strong style="color:#f87171;">' + (line.failed || 0) + '</strong></div>'
                            + '</div>';
                    }
                    var bar = document.getElementById('fcProgressBar');
                    if (bar) bar.style.width = '100%';
                    var pct = document.getElementById('fcProgressPercent');
                    if (pct) pct.textContent = '100%';
                }
            });
            if (data.done) {
                clearInterval(fcPolling); fcPolling = null;
                fcAddLog('Fact-check completato!', 'success');
                fcResetAllBtns();
            }
        })
        .catch(function() {});
}

function fcUpdateItemStatus(postId, status, score, issuesCount) {
    var btn = document.getElementById('fc-btn-' + postId);
    var st  = document.getElementById('fc-status-' + postId);
    var item = document.getElementById('fc-item-' + postId);
    if (status === 'clean') {
        if (btn) { btn.textContent = 'Verificato'; btn.style.background = '#059669'; btn.disabled = false; btn.style.opacity = '1'; }
        if (st)  { st.textContent = 'OK ' + score + '/10'; st.style.color = '#34d399'; }
    } else if (status === 'issues_found') {
        if (btn) { btn.textContent = 'Ri-verifica'; btn.style.background = '#b45309'; btn.disabled = false; btn.style.opacity = '1'; }
        if (st)  { st.textContent = '⚠ ' + issuesCount + ' problemi — score ' + score + '/10'; st.style.color = '#fca5a5'; }
    } else {
        if (btn) { btn.textContent = 'Fallito'; btn.style.background = '#dc2626'; btn.disabled = false; btn.style.opacity = '1'; }
        if (st)  { st.textContent = 'Errore'; st.style.color = '#f87171'; }
    }
}

function fcAddLog(message, type) {
    var container = document.getElementById('fcLogContainer');
    if (!container) return;
    var colors = { 'success':'#34d399','error':'#f87171','warning':'#fbbf24','step':'#818cf8','detail':'#94a3b8','info':'#94a3b8' };
    var div = document.createElement('div');
    div.style.color   = colors[type] || '#94a3b8';
    div.style.padding = '2px 0';
    div.textContent   = message;
    container.appendChild(div);
    container.scrollTop = container.scrollHeight;
}

function fcResetAllBtns() {
    fcActiveIds.forEach(function(pid) {
        var btn = document.getElementById('fc-btn-' + pid);
        if (btn && btn.textContent === 'Verifica...' ) { btn.textContent = 'Verifica'; btn.style.opacity = '1'; btn.disabled = false; }
    });
    var bulkBtn = document.getElementById('fcBulkBtn');
    if (bulkBtn) { bulkBtn.disabled = false; bulkBtn.style.opacity = '1'; bulkBtn.textContent = 'Verifica selezionati (' + document.querySelectorAll('.fc-checkbox:checked').length + ')'; }
    fcActiveIds = [];
}

function fcGoToRewrite(postId, title, issues) {
    if (issues && issues.length > 0) {
        try {
            localStorage.setItem('fc_rewrite_issues', JSON.stringify({ postId: postId, title: title, issues: issues }));
        } catch(e) {}
    }
    window.location.href = '?tab=rewrite&rwsearch=' + encodeURIComponent(title);
}

function rwInitFcBanner() {
    try {
        var stored = localStorage.getItem('fc_rewrite_issues');
        if (!stored) return;
        var data = JSON.parse(stored);
        if (!data || !data.issues || data.issues.length === 0) return;
        var banner = document.getElementById('rwFcErrorBanner');
        var titleEl = document.getElementById('rwFcArticleTitle');
        var listEl = document.getElementById('rwFcErrorList');
        if (!banner || !listEl) return;
        if (titleEl) titleEl.textContent = data.title || '';
        listEl.innerHTML = '';
        data.issues.forEach(function(issue) {
            var li = document.createElement('li');
            li.style.cssText = 'margin-bottom:6px;display:flex;align-items:flex-start;gap:6px;color:#fca5a5;font-size:13px;';
            li.innerHTML = '<span style="flex-shrink:0;color:#f59e0b;">•</span><span>' + issue.replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</span>';
            listEl.appendChild(li);
        });
        banner.style.display = '';
        banner.style.cssText = 'padding:16px;border-left:3px solid #b45309;background:#1c1408;border-radius:8px;margin-bottom:16px;';
    } catch(e) {}
}

function rwDismissFcBanner() {
    try { localStorage.removeItem('fc_rewrite_issues'); } catch(e) {}
    var banner = document.getElementById('rwFcErrorBanner');
    if (banner) banner.style.display = 'none';
}

function fcResetLog() {
    if (!confirm('Resettare il log dei fact-check? I post potranno essere riverificati.')) return;
    fetch('dashboard.php?tab=factcheck&action=reset_factcheck_log&csrf_token=<?= $csrfToken ?>')
        .then(function(r) { return r.text(); })
        .then(function() { location.reload(); });
}

// --- Esegui Custom ---
function openCustomRun() {
    document.getElementById('customRunModal').style.display = 'flex';
    document.getElementById('customTopicInput').focus();
}
function closeCustomRun() {
    document.getElementById('customRunModal').style.display = 'none';
    document.getElementById('customTopicInput').value = '';
}
function startCustomRun() {
    var topic = document.getElementById('customTopicInput').value.trim();
    if (!topic) { alert('Inserisci un topic.'); return; }
    window.location.href = 'run.php?custom_topic=' + encodeURIComponent(topic);
}
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeCustomRun();
});
</script>

<!-- Modal Esegui Custom -->
<div id="customRunModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;align-items:center;justify-content:center;padding:16px;">
    <div style="background:#1e293b;border:1px solid #334155;border-radius:16px;padding:24px;width:100%;max-width:500px;">
        <h3 style="color:#818cf8;margin-bottom:8px;">🎯 Esegui Custom</h3>
        <p style="color:#64748b;font-size:13px;margin-bottom:20px;">Specifica un topic unico per questa singola esecuzione. Le fasi 1 e 2 (keyword e filtro) vengono saltate.</p>
        <input id="customTopicInput" type="text" placeholder="Es: significato sognare di volare"
            style="width:100%;padding:12px;background:#0f172a;border:1px solid #334155;border-radius:8px;color:#e2e8f0;font-size:16px;margin-bottom:16px;"
            onkeydown="if(event.key==='Enter') startCustomRun()">
        <div style="display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap;">
            <button type="button" class="btn btn-sm" style="background:#334155;color:#e2e8f0;min-height:44px;" onclick="closeCustomRun()">Annulla</button>
            <button type="button" class="btn btn-primary" style="min-height:44px;" onclick="startCustomRun()">▶️ Avvia</button>
        </div>
    </div>
</div>

<script>
// Inizializza banner errori factcheck nel tab rewrite
(function() {
    if (document.getElementById('rwFcErrorBanner')) {
        rwInitFcBanner();
    }
})();
</script>

</body>
</html>
