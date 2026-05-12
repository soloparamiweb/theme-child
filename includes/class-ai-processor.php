<?php

class TTW_AI_Processor {

    private string $key;

    const BP_ALLOWED = [
        'p'      => [ 'style' => [], 'class' => [] ],
        'br'     => [],
        'strong' => [ 'style' => [], 'class' => [] ],
        'em'     => [],
        'a'      => [ 'href' => [], 'target' => [], 'rel' => [], 'class' => [] ],
        'span'   => [ 'style' => [], 'class' => [] ],
        'div'    => [ 'style' => [], 'class' => [] ],
        'img'    => [ 'src' => [], 'alt' => [], 'style' => [], 'class' => [], 'width' => [], 'height' => [] ],
    ];

    public function __construct( string $key ) {
        $this->key = $key;
    }

    public function rewrite_content( array $data ): string|WP_Error {

        if ( empty( $this->key ) ) {
            return new WP_Error( 'no_key', 'Anthropic API Key no configurada.' );
        }

        $telegram_text  = trim( $data['full_text']      ?? '' );
        $link           = trim( $data['link']           ?? '' );
        $group_name     = trim( $data['group_name']     ?? '' );
        $author_profile = trim( $data['author_profile'] ?? '' );
        $image_url      = trim( $data['image_url']      ?? '' );
        $extra_images   = $data['extra_images']         ?? [];
        $dest           = get_option( 'ttw_default_destination', 'blog' );

        // Etiquetas según destino
        if ( $dest === 'buddypress' ) {
            $h  = '<p style="margin-top:20px;margin-bottom:4px"><strong style="font-size:1.05em;display:block">';
            $hc = '</strong></p>';
        } else {
            $h = '<h2>'; $hc = '</h2>';
        }

        $author_url   = $link;
        $author_block = '<p style="margin-top:16px"><a href="' . esc_url( $author_url )
            . '" target="_blank" rel="nofollow">Ver publicación original en LinkedIn</a></p>';
        $tags = $dest === 'buddypress' ? 'p, strong, em, a' : 'h2, p, strong, em, a';

        // Detectar si hay texto real o solo URL
        $has_text = ! empty( $telegram_text )
            && ! preg_match( '/^https?:\/\/\S+$/i', $telegram_text );

        // Extraer tema de la URL para cuando no hay texto
        $url_keywords = '';
        if ( ! $has_text ) {
            $path = parse_url( $link, PHP_URL_PATH );
            $url_keywords = urldecode( $path );
            $url_keywords = preg_replace( '/[\/\-_]+/', ' ', $url_keywords );
            $url_keywords = preg_replace( '/\b(activity|share|posts|ugcpost|www|linkedin|com|\d{10,})\b/i', '', $url_keywords );
            $url_keywords = trim( preg_replace( '/\s+/', ' ', $url_keywords ) );
        }

        // Extraer enlaces adicionales del texto original
        $extra_links = [];
        if ( $has_text ) {
            preg_match_all( '/https?:\/\/[^\s<>"\')]+/i', $telegram_text, $matches );
            foreach ( $matches[0] as $url ) {
                $url = rtrim( $url, '.,;:)\'">' );
                if ( $url !== $link && ! in_array( $url, $extra_links ) ) {
                    $extra_links[] = $url;
                }
            }
        }

        $context = $has_text
            ? "TEXTO DEL POST DE LINKEDIN:\n" . $telegram_text
            : "URL: " . $link . "\nTema inferido: " . $url_keywords;

        if ( ! empty( $extra_links ) ) {
            $context .= "\n\nENLACES ADICIONALES EN EL POST:\n" . implode( "\n", $extra_links );
        }

        $prompt =
            'Eres un redactor técnico para «' . $group_name . '». Escribe contenido específico, técnico y directo.' . "\n\n"
            . $context . "\n\n"
            . 'ESTRUCTURA OBLIGATORIA:' . "\n\n"

            . $h . '[Extrae el título del post original, o crea uno específico y técnico si solo tienes la URL]' . $hc . "\n\n"

            . $h . 'Resumen técnico' . $hc . "\n"
            . '<p>[Resume en 5-7 puntos técnicos concretos lo que dice el post. Usa <strong> para destacar nombres de herramientas, versiones, cifras, modelos. Sin filosofía, solo hechos. 100-120 palabras.]</p>' . "\n\n"

            . $h . 'Análisis de implicaciones' . $hc . "\n"
            . '<p>[Qué significa esto técnicamente. Cambios en workflows, nuevas posibilidades, limitaciones técnicas. Datos concretos, no generalidades. 80-100 palabras.]</p>' . "\n\n"

            . $h . 'Aplicación práctica' . $hc . "\n"
            . '<p>[Cómo se usa esto hoy. Ejemplos de implementación, casos de uso reales, comandos o configuraciones si aplica. 80-100 palabras.]</p>' . "\n\n"

            . $h . 'Contexto del sector' . $hc . "\n"
            . '<p>[Por qué importa ahora. Tendencias relacionadas, comparación con alternativas, posicionamiento en el mercado. 60-80 palabras.]</p>' . "\n\n"

            . $author_block . "\n\n"

            . 'REGLAS CRÍTICAS:' . "\n"
            . '— Sé TÉCNICO: menciona versiones, modelos, nombres específicos de herramientas, comandos, configuraciones.' . "\n"
            . '— Nada de filosofía ni frases motivacionales. Directo a los hechos.' . "\n"
            . '— Usa <strong> para destacar: nombres propios, herramientas, versiones, cifras clave.' . "\n"
            . '— Títulos H2 únicos y específicos — no genéricos.' . "\n"
            . '— ENLACES: Si mencionas herramientas, frameworks o empresas conocidas, enlázalos con <a href="..." target="_blank" rel="nofollow">texto</a>. Ej: <a href="https://www.python.org" target="_blank" rel="nofollow">Python</a>, <a href="https://github.com" target="_blank" rel="nofollow">GitHub</a>, <a href="https://openai.com" target="_blank" rel="nofollow">OpenAI</a>.' . "\n"
            . '— Devuelve SOLO HTML con: ' . $tags . '. Sin markdown, sin ```.';

        $r = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
            'timeout' => 60,
            'headers' => [
                'x-api-key'         => $this->key,
                'anthropic-version' => '2023-06-01',
                'Content-Type'      => 'application/json',
            ],
            'body' => wp_json_encode( [
                'model'      => 'claude-sonnet-4-20250514',
                'max_tokens' => 2000,
                'messages'   => [
                    [
                        'role'    => 'user',
                        'content' => 'Eres un redactor técnico especializado en IA y tecnología. '
                            . 'Escribes en español de España con un estilo directo, técnico y preciso. '
                            . 'Mencionas herramientas, versiones, modelos y datos específicos. '
                            . 'Nunca usas lenguaje filosófico ni motivacional. '
                            . 'Enlaza herramientas y empresas con <a href="..." target="_blank" rel="nofollow">texto</a>. '
                            . 'Devuelves ÚNICAMENTE HTML limpio con etiquetas ' . $tags . '.'
                            . "\n\n" . $prompt,
                    ],
                ],
            ] ),
        ] );

        if ( is_wp_error( $r ) ) {
            return new WP_Error( 'net', $r->get_error_message() );
        }

        $code = (int) wp_remote_retrieve_response_code( $r );
        $body = json_decode( wp_remote_retrieve_body( $r ), true );

        if ( $code !== 200 ) {
            $error_msg = $body['error']['message'] ?? 'sin detalle';
            return new WP_Error( 'api', 'Anthropic error ' . $code . ': ' . $error_msg );
        }

        // Extraer contenido de la respuesta de Claude
        $content_blocks = $body['content'] ?? [];
        $html = '';
        foreach ( $content_blocks as $block ) {
            if ( $block['type'] === 'text' ) {
                $html .= $block['text'];
            }
        }

        if ( empty( $html ) ) {
            return new WP_Error( 'empty', 'Claude no devolvió contenido.' );
        }

        $html = self::clean_output( $html, $dest );

        // Aplicar auto-linker a términos conocidos (herramientas, frameworks, empresas)
        $html = TTW_Auto_Linker::add_links( $html );

        // Adjuntar enlaces adicionales encontrados en el texto original
        if ( ! empty( $extra_links ) ) {
            $html .= "\n\n<p><strong>Enlaces de interés:</strong></p>\n";
            foreach ( $extra_links as $extra_link ) {
                $html .= '<p style="margin-bottom:6px"><a href="' . esc_url( $extra_link ) . '" target="_blank" rel="nofollow">' . esc_html( $extra_link ) . '</a></p>' . "\n";
            }
        }

        return $html;
    }

    private static function clean_output( string $html, string $dest = 'blog' ): string {
        $html = preg_replace( '/```(?:html)?\s*/i', '', $html );
        $html = preg_replace( '/```/', '', $html );
        $html = preg_replace( '/<\/?(html|head|body)[^>]*>/i', '', $html );
        $html = preg_replace( '/^#{1,6}\s+.+$/m', '', $html );
        $html = preg_replace( '/(<\/h2>|<\/p>)\s*(<[hp2])/i', "$1\n\n$2", $html );

        if ( $dest === 'buddypress' ) {
            $html = preg_replace(
                '/<h[2-4][^>]*>(.*?)<\/h[2-4]>/is',
                '<p style="margin-top:20px;margin-bottom:4px"><strong style="font-size:1.05em;display:block">$1</strong></p>',
                $html
            );
            $html = preg_replace( '/<p(?![^>]*style)>/', '<p style="margin-bottom:12px">', $html );
            $html = wp_kses( $html, self::BP_ALLOWED );
            // Re-aplicar auto-linker después de wp_kses (que puede haber stripping previo)
            $html = TTW_Auto_Linker::add_links( $html );
        }

        return trim( $html );
    }

    public static function extract_title_from_html( string $html ): string {
        if ( preg_match( '/<h2[^>]*>(.*?)<\/h2>/is', $html, $m ) ) {
            return wp_strip_all_tags( $m[1] );
        }
        if ( preg_match( '/<strong[^>]*>(.*?)<\/strong>/is', $html, $m ) ) {
            return wp_strip_all_tags( $m[1] );
        }
        return '';
    }

    public static function test_connection( string $key ): array {
        if ( empty( $key ) ) return [ 'ok' => false, 'message' => 'API Key vacía.' ];
        
        $r = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
            'timeout' => 15,
            'headers' => [
                'x-api-key'         => $key,
                'anthropic-version' => '2023-06-01',
                'Content-Type'      => 'application/json',
            ],
            'body' => wp_json_encode( [
                'model'      => 'claude-sonnet-4-20250514',
                'max_tokens' => 50,
                'messages'   => [
                    [ 'role' => 'user', 'content' => 'Responde solo: OK' ],
                ],
            ] ),
        ] );

        if ( is_wp_error( $r ) ) return [ 'ok' => false, 'message' => $r->get_error_message() ];
        
        $code = (int) wp_remote_retrieve_response_code( $r );
        $body = json_decode( wp_remote_retrieve_body( $r ), true );
        
        if ( $code === 200 ) {
            return [ 'ok' => true, 'message' => '✅ Conexión correcta con Claude Sonnet 4.' ];
        }
        
        $error_msg = $body['error']['message'] ?? 'sin detalle';
        return [ 'ok' => false, 'message' => '❌ Error ' . $code . ': ' . $error_msg ];
    }
}
