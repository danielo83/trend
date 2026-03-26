?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AutoPilot - Pannello di Controllo</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #0f172a; color: #e2e8f0; }

        /* --- Mobile top bar --- */
        .mobile-topbar {
            display: none;
            position: fixed; top: 0; left: 0; right: 0; z-index: 1001;
            background: #1e293b; border-bottom: 1px solid #334155;
            padding: 12px 16px; align-items: center; justify-content: space-between;
        }
        .mobile-topbar h1 { font-size: 16px; color: #818cf8; }
        .hamburger {
            background: none; border: none; color: #e2e8f0; font-size: 24px;
            cursor: pointer; padding: 4px 8px; line-height: 1;
        }
        .sidebar-overlay {
            display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5);
            z-index: 1002;
        }
        .sidebar-overlay.open { display: block; }

        /* --- Sidebar --- */
        .sidebar {
            position: fixed; left: 0; top: 0; bottom: 0; width: 240px;
            background: #1e293b; border-right: 1px solid #334155; padding: 20px 0;
            z-index: 1003; transition: transform 0.25s ease;
            overflow-y: auto; -webkit-overflow-scrolling: touch;
        }
        .sidebar h1 { font-size: 18px; padding: 0 20px 20px; border-bottom: 1px solid #334155; color: #818cf8; }
        .sidebar nav { margin-top: 20px; }
        .sidebar a { display: block; padding: 10px 20px; color: #94a3b8; text-decoration: none; font-size: 14px; transition: all 0.2s; }
        .sidebar a:hover, .sidebar a.active { background: #334155; color: #e2e8f0; }
        .sidebar a.active { border-left: 3px solid #818cf8; }

        .main { margin-left: 240px; padding: 30px; min-height: 100vh; }

        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; flex-wrap: wrap; gap: 12px; }
        .header h2 { font-size: 24px; color: #f1f5f9; }

        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 30px; }
        .stat-card { background: #1e293b; border: 1px solid #334155; border-radius: 12px; padding: 20px; }
        .stat-card .label { font-size: 12px; text-transform: uppercase; color: #64748b; letter-spacing: 1px; }
        .stat-card .value { font-size: 32px; font-weight: 700; color: #f1f5f9; margin-top: 4px; }
        .stat-card .value.green { color: #4ade80; }
        .stat-card .value.blue { color: #60a5fa; }
        .stat-card .value.purple { color: #a78bfa; }
        .stat-card .value.orange { color: #fb923c; }

        .card { background: #1e293b; border: 1px solid #334155; border-radius: 12px; padding: 24px; margin-bottom: 20px; }
        .card h3 { font-size: 16px; margin-bottom: 16px; color: #f1f5f9; }

        .message { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; }
        .message.success { background: #065f46; color: #6ee7b7; border: 1px solid #059669; }
        .message.error { background: #7f1d1d; color: #fca5a5; border: 1px solid #dc2626; }
        .message.info { background: #1e3a5f; color: #93c5fd; border: 1px solid #3b82f6; }

        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #334155; }
        th { color: #64748b; font-size: 12px; text-transform: uppercase; letter-spacing: 1px; }
        td { color: #cbd5e1; }

        .badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; }
        .badge.completed { background: #065f46; color: #6ee7b7; }
        .badge.in_progress { background: #78350f; color: #fbbf24; }
        .badge.pending { background: #1e3a5f; color: #93c5fd; }
        .badge.skipped { background: #4a1d1d; color: #fca5a5; }
        .badge.links-yes { background: #065f46; color: #6ee7b7; }
        .badge.links-no { background: #1e293b; color: #64748b; }
        .badge.links-partial { background: #78350f; color: #fbbf24; }
        .link-indicator { display: inline-flex; align-items: center; gap: 4px; font-size: 11px; }
        .link-indicator .link-icon { font-size: 13px; }

        input[type="text"], input[type="number"], input[type="password"], textarea, select {
            width: 100%; padding: 10px 12px; background: #0f172a; border: 1px solid #334155;
            border-radius: 8px; color: #e2e8f0; font-size: 16px; font-family: inherit;
        }
        input:focus, textarea:focus, select:focus { outline: none; border-color: #818cf8; }
        textarea { resize: vertical; min-height: 120px; }

        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-size: 13px; color: #94a3b8; margin-bottom: 6px; }
        .form-group .hint { font-size: 11px; color: #64748b; margin-top: 4px; }

        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }

        .btn { display: inline-block; padding: 10px 20px; border-radius: 8px; border: none; cursor: pointer;
               font-size: 14px; font-weight: 600; transition: all 0.2s; text-decoration: none; }
        .btn-primary { background: #6366f1; color: white; }
        .btn-primary:hover { background: #4f46e5; }
        .btn-danger { background: #dc2626; color: white; }
        .btn-danger:hover { background: #b91c1c; }
        .btn-success { background: #059669; color: white; }
        .btn-success:hover { background: #047857; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }

        .log-output { background: #0f172a; border: 1px solid #334155; border-radius: 8px; padding: 16px;
                       font-family: 'Fira Code', 'Consolas', monospace; font-size: 12px; line-height: 1.6;
                       color: #94a3b8; max-height: 400px; overflow-y: auto; white-space: pre-wrap; }

        .feed-item { border-bottom: 1px solid #334155; padding: 16px 0; }
        .feed-item:last-child { border-bottom: none; }
        .feed-item h4 { color: #f1f5f9; margin-bottom: 6px; font-size: 15px; line-height: 1.4; }
        .feed-item .date { font-size: 12px; color: #64748b; margin-bottom: 8px; }
        .feed-item .content-preview { font-size: 13px; color: #94a3b8; max-height: 80px; overflow: hidden; }
        .feed-item .actions { margin-top: 8px; }

        .content-full { display: none; padding: 16px; background: #0f172a; border-radius: 8px; margin-top: 8px;
                         font-size: 13px; line-height: 1.6; }
        .content-full.show { display: block; }
        .content-full h2, .content-full h3 { color: #f1f5f9; margin: 12px 0 8px; }
        .content-full p { margin-bottom: 8px; }
        .content-full ul, .content-full ol { margin: 8px 0 8px 20px; }

        /* --- Responsive: tablet --- */
        @media (max-width: 900px) {
            .form-row { grid-template-columns: 1fr; }
            .stats { grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); }
            .stat-card .value { font-size: 26px; }
        }

        /* --- Responsive: mobile --- */
        @media (max-width: 768px) {
            .mobile-topbar { display: flex; }
            .sidebar {
                transform: translateX(-100%);
                width: min(240px, 85vw);
            }
            .sidebar.open {
                transform: translateX(0);
            }
            /* Touch targets sidebar: minimo 44px (Apple HIG) */
            .sidebar a { padding: 13px 20px; font-size: 15px; }
            .main {
                margin-left: 0;
                padding: 70px 14px 32px;
            }
            .header { flex-direction: column; align-items: flex-start; gap: 10px; margin-bottom: 20px; }
            .header h2 { font-size: 20px; }
            .header > div { display: flex; flex-wrap: wrap; gap: 8px; width: 100%; }
            .header > div .btn { flex: 1 1 auto; text-align: center; margin-right: 0 !important; min-height: 44px; display: flex; align-items: center; justify-content: center; }
            .header > div form { flex: 1 1 auto; display: flex; }
            .header > div form .btn { width: 100%; }
            /* Stat cards: 3 colonne su mobile per numeri più compatti */
            .stats { grid-template-columns: repeat(3, 1fr); gap: 8px; margin-bottom: 20px; }
            .stat-card { padding: 12px 8px; text-align: center; }
            .stat-card .value { font-size: 22px; }
            .stat-card .label { font-size: 10px; }
            .card { padding: 16px; border-radius: 10px; }
            .card h3 { font-size: 15px; }
            /* Prevenire zoom iOS su tutti gli input */
            input[type="text"], input[type="number"], input[type="password"], textarea, select { font-size: 16px !important; }
            table { font-size: 13px; display: block; overflow-x: auto; -webkit-overflow-scrolling: touch; }
            th, td { padding: 8px; white-space: nowrap; }
            .btn { padding: 10px 16px; font-size: 13px; min-height: 44px; }
            .btn-sm { padding: 8px 12px; min-height: 36px; }
            .feed-item .actions { display: flex; flex-wrap: wrap; gap: 6px; }
            /* cfg-tabs: scrollabili orizzontalmente su mobile */
            .cfg-tabs { flex-wrap: nowrap; overflow-x: auto; -webkit-overflow-scrolling: touch; padding-bottom: 6px; gap: 6px; }
            .cfg-tab { white-space: nowrap; font-size: 13px; padding: 10px 14px; flex-shrink: 0; min-height: 44px; }
            /* Message banner leggibile su mobile */
            .message { font-size: 13px; padding: 10px 14px; }
        }

        /* Schermi molto piccoli (< 390px) */
        @media (max-width: 390px) {
            .stats { grid-template-columns: repeat(2, 1fr); }
            .stat-card .value { font-size: 20px; }
            .main { padding: 66px 10px 28px; }
        }
    </style>
</head>
