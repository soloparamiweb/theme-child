<?php

class TTW_WP_Integrator {

    public function handle_import( array $data ): int|WP_Error {
        $dest = get_option( 'ttw_default_destination', 'blog' );
        return $dest === 'buddypress'
            ? $this->post_to_buddypress( $data )
            : $this->post_to_blog( $data );
    }

    /* ── Blog ── */
    private function post_to_blog( array $data ): int|WP_Error {
        // Categoría: primera letra mayúscula, resto minúsculas
        $cat_name = mb_strtolower( $data['group_name'] );
        $cat_name = mb_strtoupper( mb_substr( $cat_name, 0, 1 ) ) . mb_substr( $cat_name, 1 );

        $existing = get_term_by( 'name', $cat_name, 'category' );
        if ( $existing ) {
            $cat_id     = (int) $existing->term_id;
            $is_new_cat = false;
        } else {
            $cat_id     = (int) wp_create_category( $cat_name );
            $is_new_cat = true;
        }

        if ( $this->blog_link_exists( $data['link'] ?? '' ) ) {
            return new WP_Error( 'duplicate', 'Entrada con este enlace ya existe.' );
        }

        $post_id = wp_insert_post([
            'post_title'    => TTW_AI_Processor::extract_title_from_html( $data['content'] ) ?: wp_strip_all_tags( $data['group_name'] ),
            'post_content'  => $data['content'],
            'post_status'   => 'draft',
            'post_category' => [ $cat_id ],
            'post_author'   => 1,
        ]);

        if ( is_wp_error( $post_id ) || ! $post_id ) {
            return new WP_Error( 'insert_failed', 'No se pudo crear la entrada.' );
        }

        update_post_meta( $post_id, '_ttw_link', $data['link'] ?? '' );

        // Adjuntar imagen si viene
        if ( ! empty( $data['image_url'] ) ) {
            $this->attach_image_to_post( $data['image_url'], $post_id, $data['title'] );
        }

        // Extraer URL base de imagen principal para excluir del carrusel (sin parámetros)
        $main_image_base = '';
        if ( ! empty( $data['image_url'] ) ) {
            $parsed = parse_url( $data['image_url'] );
            $main_image_base = $parsed['scheme'] . '://' . $parsed['host'] . ($parsed['port'] ? ':' . $parsed['port'] : '') . $parsed['path'];
            $main_image_base = preg_replace( '/\?.*$/', '', $main_image_base );
        }

        // Adjuntar imágenes del carrusel (excluyendo la imagen destacada)
        if ( ! empty( $data['extra_images'] ) && is_array( $data['extra_images'] ) ) {
            $carousel_urls = array_filter( $data['extra_images'], function( $img_url ) use ( $main_image_base ) {
                if ( empty( $main_image_base ) ) return true;
                if ( empty( $img_url ) ) return false;
                $parsed = parse_url( $img_url );
                $img_base = $parsed['scheme'] . '://' . $parsed['host'] . ($parsed['port'] ? ':' . $parsed['port'] : '') . $parsed['path'];
                $img_base = preg_replace( '/\?.*$/', '', $img_base );
                return $img_base !== $main_image_base;
            } );
            if ( ! empty( $carousel_urls ) ) {
                $carousel_html = $this->attach_carousel_images( $carousel_urls, $post_id, $data['title'] ?? $data['group_name'] );
                if ( ! empty( $carousel_html ) ) {
                    wp_update_post( [
                        'ID'           => $post_id,
                        'post_content' => $data['content'] . "\n\n" . $carousel_html,
                    ] );
                }
            }
        }

        TTW_Queue_Manager::log_activity( $is_new_cat ? 'group_created' : 'group_updated', $data['group_name'], 'blog', $cat_id, $post_id );
        return $post_id;
    }

    /* ── BuddyPress ── */
    private function post_to_buddypress( array $data ): int|WP_Error {
        if ( ! function_exists( 'groups_create_group' ) || ! function_exists( 'groups_record_activity' ) ) {
            return new WP_Error( 'no_bp', 'BuddyPress no está activo.' );
        }

        $slug     = sanitize_title( $data['group_name'] );
        $group_id = (int) BP_Groups_Group::get_id_from_slug( $slug );
        $is_new   = false;

        if ( ! $group_id ) {
            $group_id = (int) groups_create_group([
                'creator_id'   => 1,
                'name'         => $data['group_name'],
                'description'  => 'Grupo importado automáticamente desde Telegram',
                'slug'         => groups_check_slug( $slug ),
                'status'       => 'public',
                'enable_forum' => 0,
            ]);
            $is_new = true;

            if ( ! $group_id ) {
                return new WP_Error( 'bp_group_failed', 'No se pudo crear el grupo BuddyPress para: ' . $data['group_name'] );
            }
        }

        if ( $this->bp_link_exists( $group_id, $data['link'] ?? '' ) ) {
            return new WP_Error( 'duplicate', 'Actividad con este enlace ya existe en el grupo.' );
        }

        // Extraer URL base de imagen principal para excluir del carrusel (sin parámetros)
        $main_image_base = '';
        if ( ! empty( $data['image_url'] ) ) {
            $parsed = parse_url( $data['image_url'] );
            $main_image_base = $parsed['scheme'] . '://' . $parsed['host'] . ($parsed['port'] ? ':' . $parsed['port'] : '') . $parsed['path'];
            $main_image_base = preg_replace( '/\?.*$/', '', $main_image_base );
        }

        // Subir imágenes del carrusel primero (excluyendo la imagen destacada)
        $carousel_html = '';
        if ( ! empty( $data['extra_images'] ) && is_array( $data['extra_images'] ) ) {
            $carousel_urls = array_filter( $data['extra_images'], function( $img_url ) use ( $main_image_base ) {
                if ( empty( $main_image_base ) ) return true;
                if ( empty( $img_url ) ) return false;
                $parsed = parse_url( $img_url );
                $img_base = $parsed['scheme'] . '://' . $parsed['host'] . ($parsed['port'] ? ':' . $parsed['port'] : '') . $parsed['path'];
                $img_base = preg_replace( '/\?.*$/', '', $img_base );
                return $img_base !== $main_image_base;
            } );
            if ( ! empty( $carousel_urls ) ) {
                $carousel_html = $this->attach_carousel_images( $carousel_urls, 0, $data['title'] ?? $data['group_name'] );
            }
        }

        // Construir contenido con imagen si existe
        $content = $data['content'];
        if ( ! empty( $data['image_url'] ) ) {
            // Imagen centrada con !important para forzar sobre cualquier CSS del tema
            $img_html = '<p style="text-align:center!important;clear:both!important;margin:0 0 20px 0!important;float:none!important;display:block!important">'
                      . '<img src="' . esc_url( $data['image_url'] ) . '"'
                      . ' alt="' . esc_attr( $data['group_name'] ) . '"'
                      . ' style="display:block!important;float:none!important;margin:0 auto!important;max-width:100%!important;height:auto!important;border-radius:6px" />'
                      . '</p>'
                      . '<div style="clear:both;height:1px;overflow:hidden;margin-bottom:16px"></div>';
            $content = $img_html . $content;
        }
        if ( ! empty( $carousel_html ) ) {
            $content .= "\n\n" . $carousel_html;
        }

        // Sanear HTML para BuddyPress (elimina h2/h3/h4 y tags no permitidos)
        $content = wp_kses( $content, TTW_AI_Processor::BP_ALLOWED );

        $activity_id = (int) groups_record_activity([
            'content'   => $content,
            'component' => 'groups',
            'type'      => 'activity_update',
            'item_id'   => $group_id,
            'user_id'   => 1,
        ]);

        if ( ! $activity_id ) {
            return new WP_Error( 'bp_activity_failed', 'No se pudo publicar la actividad en el grupo.' );
        }

        TTW_Queue_Manager::log_activity( $is_new ? 'group_created' : 'group_updated', $data['group_name'], 'buddypress', $group_id, $activity_id );
        return $activity_id;
    }

    /* ── Imagen: descargar y adjuntar al post ── */
    private function attach_image_to_post( string $image_url, int $post_id, string $title ): void {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url( $image_url, 15 );
        if ( is_wp_error( $tmp ) ) return;

        $ext      = pathinfo( parse_url( $image_url, PHP_URL_PATH ), PATHINFO_EXTENSION ) ?: 'jpg';
        $ext      = in_array( strtolower($ext), ['jpg','jpeg','png','webp','gif'] ) ? strtolower($ext) : 'jpg';
        $filename = sanitize_file_name( $title . '.' . $ext );

        $file_array = [
            'name'     => $filename,
            'tmp_name' => $tmp,
        ];

        $attach_id = media_handle_sideload( $file_array, $post_id, $title );

        if ( is_wp_error( $attach_id ) ) {
            @unlink( $tmp );
            return;
        }

        // Establecer como imagen destacada
        set_post_thumbnail( $post_id, $attach_id );
    }

    /* ── Carrusel: descargar imágenes y subir a la librería de medios ── */
    private function attach_carousel_images( array $image_urls, int $post_id, string $title ): string {
        if ( empty( $image_urls ) ) return '';

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $html = '';
        foreach ( $image_urls as $i => $url ) {
            $tmp = download_url( $url, 15 );
            if ( is_wp_error( $tmp ) ) continue;

            $ext      = pathinfo( parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) ?: 'jpg';
            $ext      = in_array( strtolower( $ext ), [ 'jpg', 'jpeg', 'png', 'webp', 'gif' ] ) ? strtolower( $ext ) : 'jpg';
            $filename = sanitize_file_name( $title . '-imagen-' . ( $i + 1 ) . '.' . $ext );

            $file_array = [
                'name'     => $filename,
                'tmp_name' => $tmp,
            ];

            $attach_id = media_handle_sideload( $file_array, $post_id, $title . ' - Imagen ' . ( $i + 1 ) );

            if ( is_wp_error( $attach_id ) ) {
                @unlink( $tmp );
                continue;
            }

            $src = wp_get_attachment_url( $attach_id );
            if ( $src ) {
                $html .= '<figure class="ttw-gallery-item"><img src="' . esc_url( $src ) . '" alt="' . esc_attr( $title . ' - Imagen ' . ( $i + 1 ) ) . '" /></figure>';
            }
        }

        if ( $html ) {
            $html = '<div class="ttw-gallery-grid">' . $html . '</div>';
        }

        return $html;
    }

    /* ── Deduplicación ── */
    private function blog_link_exists( string $link ): bool {
        if ( empty( $link ) ) return false;
        return ! empty( get_posts([
            'post_type'      => 'post',
            'post_status'    => 'any',
            'meta_key'       => '_ttw_link',
            'meta_value'     => $link,
            'numberposts'    => 1,
            'fields'         => 'ids',
        ]) );
    }

    private function bp_link_exists( int $group_id, string $link ): bool {
        if ( empty( $link ) || ! function_exists( 'bp_activity_get' ) ) return false;
        $r = bp_activity_get([
            'filter'       => [ 'item_id' => $group_id, 'component' => 'groups' ],
            'search_terms' => $link,
            'per_page'     => 1,
        ]);
        return ! empty( $r['activities'] );
    }
}

/**
 * Clase de compatibilidad incluida directamente para evitar errores
 * en instalaciones que tienen versiones antiguas de otros archivos.
 */
if ( ! class_exists( 'TTW_Group_Mapper' ) ) {
    class TTW_Group_Mapper {
        public static function get_destination( string $name ): array {
            $mappings = get_option( 'ttw_group_mappings', [] );
            if ( isset( $mappings[ $name ] ) ) {
                return [
                    'type'      => $mappings[ $name ]['type'],
                    'target_id' => (int) $mappings[ $name ]['target_id'],
                ];
            }
            return [
                'type'      => get_option( 'ttw_default_destination', 'blog' ),
                'target_id' => 0,
            ];
        }
        public static function save_mapping( string $group, string $type, int $id ): void {
            $m = get_option( 'ttw_group_mappings', [] );
            $m[ $group ] = [ 'type' => $type, 'target_id' => $id ];
            update_option( 'ttw_group_mappings', $m );
        }
    }
}
