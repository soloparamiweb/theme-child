<?php
if (!defined('ABSPATH')) exit;
require_once plugin_dir_path(__FILE__) . 'includes/auto-linker.php';

class Telegram_To_WP_Pro {
    public function __construct() {
        add_action('wp_head', [$this, 'ttw_read_more_css']);
    }

    public function ttw_read_more_css(): void {
        echo '<style>
        span.activity-read-more { pointer-events: none !important; }
        span.activity-read-more a { pointer-events: all !important; cursor: pointer; }
        .ttw-gallery-row { display:flex; gap:12px; overflow-x:auto; margin:20px 0; padding-bottom:6px; }
        .ttw-gallery-row .ttw-gallery-item { flex:0 0 auto; width:min(320px, 80vw); margin:0; line-height:0; }
        .ttw-gallery-row .ttw-gallery-item img { width:100%; height:auto; border-radius:8px; object-fit:cover; display:block; }
        .ttw-content a, .activity-inner a, .entry-content a { color:#0b57d0 !important; text-decoration: underline; }
        </style>';
    }
}
new Telegram_To_WP_Pro();
