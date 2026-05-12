<?php
class TTW_WP_Integrator {
    private function attach_carousel_images(array $image_urls, int $post_id, string $title): string {
        if (empty($image_urls)) return '';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $html = '';
        foreach ($image_urls as $i => $url) {
            $tmp = download_url($url, 30);
            if (is_wp_error($tmp)) {
                if (class_exists('TTW_Telegram_Handler')) TTW_Telegram_Handler::log_event('carousel_download_error', $url . ' | ' . $tmp->get_error_message());
                continue;
            }
            $ext = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg';
            $filename = sanitize_file_name($title . '-imagen-' . ($i+1) . '.' . $ext);
            $attach_id = media_handle_sideload(['name'=>$filename,'tmp_name'=>$tmp], $post_id, $title . ' - Imagen ' . ($i+1));
            if (is_wp_error($attach_id)) {
                @unlink($tmp);
                if (class_exists('TTW_Telegram_Handler')) TTW_Telegram_Handler::log_event('carousel_sideload_error', $url . ' | ' . $attach_id->get_error_message());
                continue;
            }
            $src = wp_get_attachment_url($attach_id);
            if ($src) $html .= '<div class="ttw-gallery-item"><img src="' . esc_url($src) . '" alt="' . esc_attr($title . ' - Imagen ' . ($i+1)) . '" /></div>';
        }
        return $html ? '<div class="ttw-gallery-row">' . $html . '</div>' : '';
    }
}
