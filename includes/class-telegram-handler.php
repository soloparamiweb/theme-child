<?php
class TTW_Telegram_Handler {
    private static function is_avatar_url(string $url): bool {
        $patterns = ['/\/profile\//i','/rms\-profile\-photo/i','/_aa\./i','/_ac\./i','/\?sz=/i','/\/user\//i','/D4E22AQ.*-profile/i'];
        foreach ($patterns as $p) if (preg_match($p, $url)) return true;
        return false;
    }

    private static function is_post_image_url(string $url): bool {
        return (bool) preg_match('/feedshare|carousel|article|high-res|dms\/image\//i', $url);
    }

    public static function scrape_linkedin_images(string $url): array {
        $result = ['main'=>'', 'carousel'=>[]];
        if (empty($url)) return $result;
        $response = wp_remote_get($url, ['timeout'=>10]);
        if (is_wp_error($response)) return $result;
        $html = wp_remote_retrieve_body($response);
        preg_match_all('/https:\/\/media\.licdn\.com\/dms\/image\/[^\s"\'<>]+/i', $html, $matches);
        $real_images = [];
        foreach (($matches[0] ?? []) as $img) {
            $clean = esc_url_raw($img);
            if (self::is_avatar_url($clean)) continue;
            if (!self::is_post_image_url($clean)) continue;
            if (!in_array($clean, $real_images, true)) $real_images[] = $clean;
        }
        if (!empty($real_images)) {
            $result['main'] = array_shift($real_images);
            $result['carousel'] = array_slice($real_images, 0, 6);
        }
        return $result;
    }
}
