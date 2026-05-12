<?php

class TTW_Queue_Manager {

    const MAX_PUBLISH = 30;

    public static function create_tables(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $table   = $wpdb->prefix . 'ttw_queue';

        // Sin UNIQUE KEY en content_hash — deduplicación por SELECT previo
        $sql_queue = "CREATE TABLE $table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            telegram_data longtext NOT NULL,
            content_hash varchar(64) NOT NULL DEFAULT '',
            status varchar(20) DEFAULT 'pending' NOT NULL,
            wp_post_id bigint(20) DEFAULT NULL,
            error_message text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            processed_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY content_hash (content_hash),
            KEY status (status)
        ) $charset;";

        $sql_log = "CREATE TABLE {$wpdb->prefix}ttw_log (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            event_type varchar(30) NOT NULL,
            group_name varchar(255) NOT NULL,
            destination_type varchar(20) NOT NULL,
            destination_id bigint(20) DEFAULT 0,
            wp_post_id bigint(20) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY (id),
            KEY group_name (group_name(100))
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql_queue );
        dbDelta( $sql_log );

        // Migración: convertir UNIQUE KEY en KEY normal si existe de versiones anteriores
        $unique = $wpdb->get_results( "SHOW INDEX FROM $table WHERE Key_name = 'content_hash' AND Non_unique = 0" );
        if ( ! empty( $unique ) ) {
            $wpdb->query( "ALTER TABLE $table DROP INDEX content_hash" );
            $wpdb->query( "ALTER TABLE $table ADD KEY content_hash (content_hash)" );
        }

        // Migración: añadir content_hash si no existe (tablas muy antiguas)
        $col = $wpdb->get_col( "SHOW COLUMNS FROM $table LIKE 'content_hash'" );
        if ( empty( $col ) ) {
            $wpdb->query( "ALTER TABLE $table ADD COLUMN content_hash varchar(64) NOT NULL DEFAULT '' AFTER telegram_data, ADD KEY content_hash (content_hash)" );
        }
    }

    /**
     * Añade un trabajo a la cola.
     * Rechaza duplicados por hash SHA-256 del enlace.
     * Devuelve 'added', 'duplicate', o 'error'.
     */
    public static function add_job( array $data ): string {
        global $wpdb;
        $table = $wpdb->prefix . 'ttw_queue';

        // Asegurar que la tabla y columnas existen
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) !== $table ) {
            self::create_tables();
        }

        // Asegurar que content_hash existe como columna
        $cols = $wpdb->get_col( "SHOW COLUMNS FROM $table LIKE 'content_hash'" );
        if ( empty( $cols ) ) {
            $wpdb->query( "ALTER TABLE $table ADD COLUMN content_hash varchar(64) NOT NULL DEFAULT '' AFTER telegram_data" );
            $wpdb->query( "ALTER TABLE $table ADD KEY content_hash (content_hash)" );
            TTW_Telegram_Handler::log_event( 'db_fix', 'Columna content_hash añadida a ' . $table );
        }

        $hash = hash( 'sha256', trim( $data['link'] ?? $data['full_text'] ?? '' ) );

        $exists = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE content_hash = %s", $hash )
        );
        if ( $exists > 0 ) return 'duplicate';

        $result = $wpdb->insert(
            $table,
            [ 'telegram_data' => wp_json_encode( $data ), 'content_hash' => $hash, 'status' => 'pending' ],
            [ '%s', '%s', '%s' ]
        );

        if ( ! $result ) {
            TTW_Telegram_Handler::log_event( 'db_error', 'add_job falló «' . ($data['group_name']??'') . '»: ' . $wpdb->last_error . ' | SQL: ' . $wpdb->last_query );
            return 'error';
        }

        return 'added';
    }

    /**
     * Procesa UN trabajo de la cola.
     * Llamado en bucle desde AJAX — nunca hace timeout.
     */
    public static function process_one(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'ttw_queue';

        if ( (int) get_option( 'ttw_process_stop', 0 ) === 1 ) {
            update_option( 'ttw_process_status', 'idle' );
            return [ 'status' => 'stopped', 'message' => 'Detenido por el usuario.' ];
        }

        // Liberar trabajos bloqueados en "processing" (sesión anterior rota)
        $wpdb->query( "UPDATE $table SET status='pending' WHERE status='processing' AND processed_at IS NULL" );

        $session = (int) get_option( 'ttw_session_published', 0 );
        if ( $session >= self::MAX_PUBLISH ) {
            update_option( 'ttw_process_status', 'idle' );
            return [ 'status' => 'limit', 'message' => 'Límite de ' . self::MAX_PUBLISH . ' alcanzado.' ];
        }

        $job = $wpdb->get_row( "SELECT * FROM $table WHERE status='pending' ORDER BY id ASC LIMIT 1" );
        if ( ! $job ) {
            update_option( 'ttw_process_status', 'idle' );
            return [ 'status' => 'empty', 'message' => 'Cola vacía.' ];
        }

        $wpdb->update( $table, [ 'status' => 'processing' ], [ 'id' => $job->id ] );

        // Ampliar tiempo de ejecución si el servidor lo permite
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 120 );
        }

        $data = json_decode( $job->telegram_data, true );

        // Llamada a OpenAI con manejo de error fatal
        try {
            $ai     = new TTW_AI_Processor( get_option( 'ttw_openai_key', '' ) );
            $result = $ai->rewrite_content( $data );
        } catch ( \Throwable $e ) {
            $wpdb->update( $table, [ 'status' => 'failed', 'error_message' => 'PHP fatal: ' . $e->getMessage(), 'processed_at' => current_time('mysql') ], [ 'id' => $job->id ] );
            return [ 'status' => 'failed', 'message' => 'PHP fatal: ' . $e->getMessage(), 'job_id' => $job->id ];
        }

        if ( is_wp_error( $result ) ) {
            $wpdb->update( $table, [ 'status' => 'failed', 'error_message' => $result->get_error_message(), 'processed_at' => current_time('mysql') ], [ 'id' => $job->id ] );
            return [ 'status' => 'failed', 'message' => $result->get_error_message(), 'job_id' => $job->id ];
        }

        $data['content'] = $result;
        $data['title']   = 'Novedad en: ' . $data['group_name'];

        try {
            $integrator = new TTW_WP_Integrator();
            $import     = $integrator->handle_import( $data );
        } catch ( \Throwable $e ) {
            $wpdb->update( $table, [ 'status' => 'failed', 'error_message' => 'PHP fatal integrator: ' . $e->getMessage(), 'processed_at' => current_time('mysql') ], [ 'id' => $job->id ] );
            return [ 'status' => 'failed', 'message' => 'PHP fatal integrator: ' . $e->getMessage(), 'job_id' => $job->id ];
        }

        if ( is_wp_error( $import ) ) {
            $wpdb->update( $table, [ 'status' => 'failed', 'error_message' => $import->get_error_message(), 'processed_at' => current_time('mysql') ], [ 'id' => $job->id ] );
            return [ 'status' => 'failed', 'message' => $import->get_error_message(), 'job_id' => $job->id ];
        }

        $post_id = (int) $import;
        $wpdb->update( $table, [ 'status' => 'completed', 'wp_post_id' => $post_id, 'processed_at' => current_time('mysql') ], [ 'id' => $job->id ] );
        update_option( 'ttw_session_published', $session + 1 );

        return [
            'status'     => 'ok',
            'job_id'     => (int) $job->id,
            'post_id'    => $post_id,
            'group_name' => $data['group_name'],
            'published'  => $session + 1,
            'remaining'  => self::count_pending() - 1,
        ];
    }

    public static function count_pending(): int {
        global $wpdb;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ttw_queue WHERE status='pending'" );
    }

    public static function log_activity( string $type, string $group, string $dest_type, int $dest_id, ?int $post_id ): void {
        global $wpdb;
        $l = $wpdb->prefix . 'ttw_log';
        // Crear tabla si no existe
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$l'" ) !== $l ) {
            self::create_tables();
        }
        $wpdb->insert( $l, [
            'event_type' => $type, 'group_name' => $group,
            'destination_type' => $dest_type, 'destination_id' => $dest_id, 'wp_post_id' => $post_id,
        ], [ '%s','%s','%s','%d','%d' ] );
    }

    public static function get_stats(): array {
        global $wpdb;
        $q = $wpdb->prefix . 'ttw_queue';
        $l = $wpdb->prefix . 'ttw_log';

        // Verificar existencia de tablas antes de consultar
        $q_ok = $wpdb->get_var( "SHOW TABLES LIKE '$q'" ) === $q;
        $l_ok = $wpdb->get_var( "SHOW TABLES LIKE '$l'" ) === $l;

        // Si faltan tablas, crearlas automáticamente
        if ( ! $q_ok || ! $l_ok ) {
            self::create_tables();
            $q_ok = true;
            $l_ok = true;
        }

        return [
            'pending'           => $q_ok ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM $q WHERE status='pending'" ) : 0,
            'processing'        => $q_ok ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM $q WHERE status='processing'" ) : 0,
            'completed'         => $q_ok ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM $q WHERE status='completed'" ) : 0,
            'failed'            => $q_ok ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM $q WHERE status='failed'" ) : 0,
            'new_groups'        => $l_ok ? (int) $wpdb->get_var( "SELECT COUNT(DISTINCT group_name) FROM $l WHERE event_type='group_created'" ) : 0,
            'updated_groups'    => $l_ok ? (int) $wpdb->get_var( "SELECT COUNT(DISTINCT group_name) FROM $l WHERE event_type='group_updated'" ) : 0,
            'process_status'    => get_option( 'ttw_process_status', 'idle' ),
            'last_run'          => get_option( 'ttw_last_run', '—' ),
            'last_finish'       => get_option( 'ttw_last_finish', '—' ),
            'session_published' => (int) get_option( 'ttw_session_published', 0 ),
        ];
    }
}
