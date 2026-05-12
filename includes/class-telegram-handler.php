<?php

class TTW_Telegram_Handler {

    public function parse_update( array $update ): ?array {
        self::log_raw( $update );

        $msg = $update['message']
            ?? $update['channel_post']
            ?? $update['edited_message']
            ?? $update['edited_channel_post']
            ?? null;

        if ( ! $msg ) {
            self::log_event( 'no_message', 'Update sin mensaje. Keys: ' . implode( ', ', array_keys( $update ) ) );
            return null;
        }

        $text  = $msg['text'] ?? $msg['caption'] ?? '';
        $chat  = $msg['chat'] ?? [];
        $title = $chat['title'] ?? $chat['username'] ?? $chat['first_name'] ?? 'General';

        $link = self::extract_link( $text, $msg );

        if ( ! $link ) {
            self::log_event( 'no_link', "«{$title}» sin enlace. Texto: " . mb_substr( $text, 0, 100 ) );
            return null;
        }

        $author_profile = self::extract_author_profile( $link );
        
        // Extraer imágenes directamente del HTML de LinkedIn
        $images_data = self::scrape_linkedin_images( $link );

        self::log_event( 'queued', 
            "Capturado de «{$title}»: {$link}"
            . ( $images_data['main'] ? ' [img]' : '' )
            . ( count( $images_data['carousel'] ) > 0 ? ' [+' . count( $images_data['carousel'] ) . ' carrusel]' : '' )
        );

        return [
            'full_text'      => $text,
            'link'           => $link,
            'group_name'     => $title,
            'author_profile' => $author_profile,
            'image_url'      => $images_data['main'],
            'extra_images'   => $images_data['carousel'],
            'source'         => 'Telegram',
        ];
    }

    private static function extract_link( string $text, array $msg ): ?string {
        $any = (bool) get_option( 'ttw_any_link', false );

        $entities = $msg['entities'] ?? $msg['caption_entities'] ?? [];
        foreach ( $entities as $e ) {
            $url = '';
            if ( $e['type'] === 'text_link' ) {
                $url = $e['url'] ?? '';
            } elseif ( $e['type'] === 'url' ) {
                $url = mb_substr( $text, $e['offset'], $e['length'] );
            }
            if ( $url && ( $any || stripos( $url, 'linkedin.com' ) !== false ) ) {
                return rtrim( $url, '.,;:)\'">' );
            }
        }

        $pattern = $any ? '~https?://\S+~i' : '~https?://(www\.)?linkedin\.com/\S+~i';
        if ( preg_match( $pattern, $text, $m ) ) {
            return rtrim( $m[0], '.,;:)\'">' );
        }

        return null;
    }

    private static function extract_author_profile( string $url ): string {
        if ( preg_match( '~linkedin\.com/posts/([a-z0-9\-_%\.]+?)(?:_[a-z])~i', $url, $m ) ) {
            return 'https://www.linkedin.com/in/' . rtrim( $m[1], '_-.' );
        }
        return '';
    }

    /**
     * Extrae el ID único de una imagen de LinkedIn CDN para comparación
     */
    private static function get_linkedin_image_id( string $url ): string {
        if ( preg_match( '/\/dms\/image(?:\/v2)?\/([^\/]+)/i', $url, $m ) ) {
            return $m[1];
        }
        return $url;
    }

    /**
     * Extrae imágenes directamente del HTML de LinkedIn
     */
    private static function scrape_linkedin_images( string $url ): array {
        $result = [
            'main'     => '',
            'carousel' => [],
        ];

        if ( empty( $url ) ) {
            return $result;
        }

        $response = wp_remote_get( $url, [
            'timeout'    => 10,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'headers'    => [
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'es-ES,es;q=0.9,en;q=0.8',
            ],
        ]);

        if ( is_wp_error( $response ) ) {
            self::log_event( 'scrape_error', $response->get_error_message() );
            return $result;
        }

        $html = wp_remote_retrieve_body( $response );

        // Buscar og:image en meta tags
        $og_image = '';
        if ( preg_match( '/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m ) ) {
            $og_image = esc_url_raw( $m[1] );
            self::log_event( 'scrape_img', 'Extraída og:image' );
        } elseif ( preg_match( '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']/i', $html, $m ) ) {
            $og_image = esc_url_raw( $m[1] );
            self::log_event( 'scrape_img', 'Extraída og:image (alt)' );
        }

        // Detectar si og:image es un avatar
        $is_og_avatar = false;
        if ( $og_image ) {
            $avatar_patterns = [
                '/\/profile\//i',
                '/\/rms\-profile\-photo\//i',
                '/_aa\./i',
                '/_ac\./i',
                '/\?sz=/i',
                '/\/user\//i',
                '/dms\/image\/[A-Z]-AD/I',
                '/dms\/image\/profil/Ii',
            ];
            foreach ( $avatar_patterns as $pattern ) {
                if ( preg_match( $pattern, $og_image ) ) {
                    $is_og_avatar = true;
                    break;
                }
            }
            if ( $is_og_avatar ) {
                self::log_event( 'scrape_img', 'og:image era avatar: ' . $og_image );
                $og_image = '';
            }
        }

        $result['main'] = $og_image;

        // Recoger TODAS las imágenes del body y filtrar avatares
        preg_match_all( '/https:\/\/media\.licdn\.com\/dms\/image\/[^\s"\'<>]+/i', $html, $matches );
        $real_images = [];

        if ( ! empty( $matches[0] ) ) {
            $avatar_patterns = [
                '/\/profile\//i',
                '/\/rms\-profile\-photo\//i',
                '/_aa\./i',
                '/_ac\./i',
                '/\?sz=/i',
                '/\/user\//i',
                '/dms\/image\/[A-Z]-AD/I',
                '/dms\/image\/profil/Ii',
                '/\/v1\/[A-Z]+AD-/i',
                '/_s\./i',
                '/\/image\/D4E22AQ[A-Z0-9]+-profile/i',
            ];
            foreach ( $matches[0] as $img_url ) {
                $clean = esc_url_raw( $img_url );
                $is_avatar = false;
                foreach ( $avatar_patterns as $pattern ) {
                    if ( preg_match( $pattern, $clean ) ) {
                        $is_avatar = true;
                        break;
                    }
                }
                // Filtrar también por tamaño en URL si es posible (high-res indica imagen de post)
                if ( ! $is_avatar && strpos( $clean, 'feedshare-image' ) !== false ) {
                    // Es imagen de post, no avatar
                } elseif ( ! $is_avatar && strpos( $clean, 'high-res' ) !== false ) {
                    // Alta resolución, probablemente imagen de post
                } elseif ( ! $is_avatar && strpos( $clean, 'profile' ) === false ) {
                    // No tiene profile en URL, probablemente no es avatar
                }
                
                if ( ! $is_avatar && ! in_array( $clean, $real_images, true ) ) {
                    $real_images[] = $clean;
                }
            }
        }

        // Usar primera imagen real como principal si og:image falla
        if ( empty( $result['main'] ) && ! empty( $real_images ) ) {
            $result['main'] = array_shift( $real_images );
            self::log_event( 'scrape_img', 'Usada primera imagen real como principal' );
        }

        $result['carousel'] = array_slice( $real_images, 0, 6 );

        self::log_event( 'scrape_ok',
            ( $result['main'] ? '1 principal' : 'sin principal' )
            . ' + ' . count( $result['carousel'] ) . ' carrusel'
        );

        return $result;
    }

    public function parse_update_silent( array $update ): ?array {
        $msg = $update['message']
            ?? $update['channel_post']
            ?? $update['edited_message']
            ?? $update['edited_channel_post']
            ?? null;

        if ( ! $msg ) return null;

        $text  = $msg['text'] ?? $msg['caption'] ?? '';
        $chat  = $msg['chat'] ?? [];
        $title = $chat['title'] ?? $chat['username'] ?? $chat['first_name'] ?? 'General';
        $link  = self::extract_link( $text, $msg );
        
        if ( ! $link ) return null;

        $images_data = self::scrape_linkedin_images( $link );

        return [
            'full_text'      => $text,
            'link'           => $link,
            'group_name'     => $title,
            'author_profile' => self::extract_author_profile( $link ),
            'image_url'      => $images_data['main'],
            'extra_images'   => $images_data['carousel'],
            'source'         => 'Telegram',
        ];
    }

    /* ── Logging ── */

    public static function log_raw( array $update ): void {
        $logs   = get_option( 'ttw_raw_log', [] );
        $logs[] = [ 'time' => current_time( 'mysql' ), 'data' => $update ];
        update_option( 'ttw_raw_log', array_slice( $logs, -200 ) );
    }

    public static function log_event( string $type, string $msg ): void {
        $logs   = get_option( 'ttw_event_log', [] );
        $logs[] = [ 'time' => current_time( 'mysql' ), 'type' => $type, 'msg' => $msg ];
        update_option( 'ttw_event_log', array_slice( $logs, -100 ) );
    }

    public static function check_webhook( string $token ): array {
        if ( empty( $token ) ) return [ 'ok' => false, 'error' => 'Token vacío.' ];
        $r = wp_remote_get( "https://api.telegram.org/bot{$token}/getWebhookInfo", [ 'timeout' => 10 ] );
        if ( is_wp_error( $r ) ) return [ 'ok' => false, 'error' => $r->get_error_message() ];
        return json_decode( wp_remote_retrieve_body( $r ), true ) ?? [ 'ok' => false, 'error' => 'Sin respuesta.' ];
    }

    public static function register_webhook( string $token, string $url ): array {
        if ( empty( $token ) ) return [ 'ok' => false, 'description' => 'Token vacío.' ];
        $r = wp_remote_get(
            "https://api.telegram.org/bot{$token}/setWebhook?url=" . rawurlencode( $url ),
            [ 'timeout' => 10 ]
        );
        if ( is_wp_error( $r ) ) return [ 'ok' => false, 'description' => $r->get_error_message() ];
        return json_decode( wp_remote_retrieve_body( $r ), true ) ?? [ 'ok' => false, 'description' => 'Sin respuesta.' ];
    }
}
