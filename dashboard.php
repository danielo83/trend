<?php
require __DIR__ . '/dashboard/bootstrap.php';
require __DIR__ . '/dashboard/layout_head.php';
require __DIR__ . '/dashboard/layout_sidebar.php';

if ($tab === 'overview'):
    require __DIR__ . '/dashboard/tab_overview.php';
elseif ($tab === 'feed'):
    require __DIR__ . '/dashboard/tab_feed.php';
elseif ($tab === 'topics'):
    require __DIR__ . '/dashboard/tab_topics.php';
elseif ($tab === 'config'):
    require __DIR__ . '/dashboard/tab_config.php';
elseif ($tab === 'logs'):
    require __DIR__ . '/dashboard/tab_logs.php';
elseif ($tab === 'linkbuilding'):
    require __DIR__ . '/dashboard/tab_linkbuilding.php';
elseif ($tab === 'seo'):
    require __DIR__ . '/dashboard/tab_seo.php';
elseif ($tab === 'contenthub'):
    require __DIR__ . '/dashboard/tab_contenthub.php';
elseif ($tab === 'richresults'):
    require __DIR__ . '/dashboard/tab_richresults.php';
elseif ($tab === 'rewrite'):
    require __DIR__ . '/dashboard/tab_rewrite.php';
elseif ($tab === 'factcheck'):
    require __DIR__ . '/dashboard/tab_factcheck.php';
endif;

require __DIR__ . '/dashboard/layout_footer.php';
require __DIR__ . '/dashboard/js_all.php';
