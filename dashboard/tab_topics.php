    <div class="header">
        <h2>Topic Elaborati</h2>
        <form method="post" onsubmit="return confirm('Cancellare tutto lo storico? I topic potranno essere rielaborati.')">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" value="clear_history">
            <button type="submit" class="btn btn-danger btn-sm">Cancella Storico</button>
        </form>
    </div>

    <div class="card">
        <?php if (empty($recentTopics)): ?>
            <p style="color: #64748b;">Nessun topic nello storico.</p>
        <?php else: ?>
            <table>
                <thead><tr><th>Topic</th><th>Stato</th><th>Data Creazione</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($recentTopics as $t): ?>
                    <tr>
                        <td><?= htmlspecialchars($t['topic']) ?></td>
                        <td><span class="badge <?= htmlspecialchars($t['status']) ?>"><?= htmlspecialchars($t['status']) ?></span></td>
                        <td><?= htmlspecialchars($t['created_at']) ?></td>
                        <td>
                            <form method="post" style="margin:0;" onsubmit="return confirm('Eliminare questo topic?')">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="action" value="delete_topic">
                                <input type="hidden" name="topic_id" value="<?= intval($t['id']) ?>">
                                <button type="submit" class="btn btn-danger btn-sm" title="Elimina">✕</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

