<?php

class TTW_Admin_Settings {

    public function __construct() {
        add_action( 'admin_menu',            [ $this, 'add_menus' ] );
        add_action( 'admin_init',            [ $this, 'register_settings' ] );
        add_action( 'admin_init',            [ $this, 'handle_delete_actions' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );

        foreach ( [
            'ttw_start'          => 'ajax_start',
            'ttw_stop'           => 'ajax_stop',
            'ttw_process_one'    => 'ajax_process_one',
            'ttw_get_stats'      => 'ajax_get_stats',
            'ttw_delete_queue'   => 'ajax_delete_queue',
            'ttw_register_hook'  => 'ajax_register_hook',
            'ttw_check_hook'     => 'ajax_check_hook',
            'ttw_test_openai'    => 'ajax_test_openai',
            'ttw_force_import'   => 'ajax_force_import',
            'ttw_clear_logs'     => 'ajax_clear_logs',
            'ttw_repair_db'      => 'ajax_repair_db',
            'ttw_db_status'      => 'ajax_db_status',
        ] as $action => $method ) {
            add_action( "wp_ajax_{$action}", [ $this, $method ] );
        }
    }

    /* ── Scripts ── */
    public function enqueue_scripts( string $hook ): void {
        // Comprobación robusta: por slug de página (no depende del título del menú ni del idioma)
        $ttw_slugs = [ 'ttw-settings', 'ttw-monitor', 'ttw-activity', 'ttw-content', 'ttw-diag' ];
        $current   = $_GET['page'] ?? '';
        if ( ! in_array( $current, $ttw_slugs, true ) ) return;

        wp_enqueue_script( 'ttw-admin', plugin_dir_url( dirname(__FILE__) ) . 'assets/admin.js', ['jquery'], '2.1.4', true );
        wp_localize_script( 'ttw-admin', 'TTW', [
            'nonce'    => wp_create_nonce( 'ttw_nonce' ),
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'max'      => TTW_Queue_Manager::MAX_PUBLISH,
        ]);
    }

    /* ── Menús ── */
    public function add_menus(): void {
        add_menu_page( 'Telegram AI', 'Telegram AI', 'manage_options', 'ttw-settings', [ $this, 'page_settings' ], 'dashicons-share-alt2' );
        add_submenu_page( 'ttw-settings', 'Monitor',    'Monitor',    'manage_options', 'ttw-monitor',  [ $this, 'page_monitor' ] );
        add_submenu_page( 'ttw-settings', 'Actividad',  'Actividad',  'manage_options', 'ttw-activity', [ $this, 'page_activity' ] );
        add_submenu_page( 'ttw-settings', 'Contenido',  'Contenido',  'manage_options', 'ttw-content',  [ $this, 'page_content' ] );
        add_submenu_page( 'ttw-settings', 'Diagnóstico','Diagnóstico','manage_options', 'ttw-diag',     [ $this, 'page_diag' ] );
    }

    /* ── Settings ── */
    public function register_settings(): void {
        foreach ( [ 'ttw_telegram_token', 'ttw_bot_username', 'ttw_openai_key', 'ttw_default_destination', 'ttw_any_link' ] as $opt ) {
            register_setting( 'ttw_group', $opt );
        }
    }

    /* ── Borrado con nonce ── */
    public function handle_delete_actions(): void {
        if ( empty( $_GET['ttw_action'] ) || ! check_admin_referer( 'ttw_delete' ) || ! current_user_can( 'manage_options' ) ) return;

        if ( $_GET['ttw_action'] === 'del_post' && ! empty( $_GET['id'] ) ) {
            wp_delete_post( (int) $_GET['id'], true );
            wp_safe_redirect( add_query_arg( 'msg', 'deleted', admin_url( 'admin.php?page=ttw-content' ) ) );
            exit;
        }
        if ( $_GET['ttw_action'] === 'del_queue' && ! empty( $_GET['id'] ) ) {
            global $wpdb;
            $wpdb->delete( $wpdb->prefix . 'ttw_queue', [ 'id' => (int) $_GET['id'] ] );
            wp_safe_redirect( add_query_arg( 'msg', 'deleted', admin_url( 'admin.php?page=ttw-monitor' ) ) );
            exit;
        }
        if ( $_GET['ttw_action'] === 'del_bp' && ! empty( $_GET['id'] ) ) {
            if ( function_exists( 'bp_activity_delete' ) ) bp_activity_delete( [ 'id' => (int) $_GET['id'] ] );
            wp_safe_redirect( add_query_arg( 'msg', 'deleted', admin_url( 'admin.php?page=ttw-content' ) ) );
            exit;
        }
    }

    /* ════════════════ AJAX ════════════════ */

    private function check_nonce(): void {
        if ( ! check_ajax_referer( 'ttw_nonce', 'nonce', false ) || ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Sin permisos.' );
        }
    }

    public function ajax_start(): void {
        $this->check_nonce();
        global $wpdb;
        // Liberar trabajos bloqueados
        $wpdb->query( "UPDATE {$wpdb->prefix}ttw_queue SET status='pending' WHERE status='processing' AND processed_at IS NULL" );
        update_option( 'ttw_process_stop',      0 );
        update_option( 'ttw_process_status',    'running' );
        update_option( 'ttw_session_published', 0 );
        update_option( 'ttw_last_run',          current_time( 'mysql' ) );
        wp_send_json_success( [ 'pending' => TTW_Queue_Manager::count_pending() ] );
    }

    public function ajax_stop(): void {
        $this->check_nonce();
        update_option( 'ttw_process_stop',   1 );
        update_option( 'ttw_process_status', 'idle' );
        update_option( 'ttw_last_finish',    current_time( 'mysql' ) );
        wp_send_json_success();
    }

    public function ajax_process_one(): void {
        $this->check_nonce();
        $result = TTW_Queue_Manager::process_one();
        if ( in_array( $result['status'], [ 'empty', 'limit', 'stopped' ], true ) ) {
            update_option( 'ttw_process_status', 'idle' );
            update_option( 'ttw_last_finish', current_time( 'mysql' ) );
        }
        wp_send_json_success( $result );
    }

    public function ajax_get_stats(): void {
        $this->check_nonce();
        wp_send_json_success( TTW_Queue_Manager::get_stats() );
    }

    public function ajax_delete_queue(): void {
        $this->check_nonce();
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'ttw_queue', [ 'id' => (int) ( $_POST['id'] ?? 0 ) ] );
        wp_send_json_success();
    }

    public function ajax_register_hook(): void {
        $this->check_nonce();
        $token = get_option( 'ttw_telegram_token', '' );
        $url   = rest_url( 'ttw/v1/webhook' );
        $r     = TTW_Telegram_Handler::register_webhook( $token, $url );
        wp_send_json_success( $r );
    }

    public function ajax_check_hook(): void {
        $this->check_nonce();
        wp_send_json_success( TTW_Telegram_Handler::check_webhook( get_option( 'ttw_telegram_token', '' ) ) );
    }

    public function ajax_test_openai(): void {
        $this->check_nonce();
        wp_send_json_success( TTW_AI_Processor::test_connection( get_option( 'ttw_openai_key', '' ) ) );
    }

    public function ajax_force_import(): void {
        $this->check_nonce();
        global $wpdb;

        // Opción: reset_dupes=1 borra trabajos fallidos/duplicados antes de reimportar
        if ( ! empty( $_POST['reset_dupes'] ) ) {
            $wpdb->query( "DELETE FROM {$wpdb->prefix}ttw_queue WHERE status IN ('failed','completed')" );
        }

        $raw   = get_option( 'ttw_raw_log', [] );
        $added = 0;
        $skipped = 0;

        foreach ( $raw as $entry ) {
            // Parsear sin efectos secundarios (sin llamar log_raw de nuevo)
            $handler = new TTW_Telegram_Handler();
            $data    = $handler->parse_update_silent( $entry['data'] );
            if ( ! $data ) continue;

            $result = TTW_Queue_Manager::add_job( $data );
            if ( $result === 'added' )     $added++;
            if ( $result === 'duplicate' ) $skipped++;
        }

        wp_send_json_success( [
            'added'   => $added,
            'skipped' => $skipped,
            'pending' => TTW_Queue_Manager::count_pending(),
            'message' => "Añadidos: {$added} | Duplicados: {$skipped} | Pendientes: " . TTW_Queue_Manager::count_pending(),
        ] );
    }

    public function ajax_db_status(): void {
        $this->check_nonce();
        global $wpdb;
        $q = $wpdb->prefix . 'ttw_queue';
        $l = $wpdb->prefix . 'ttw_log';

        $q_exists = $wpdb->get_var("SHOW TABLES LIKE '$q'") === $q;
        $l_exists = $wpdb->get_var("SHOW TABLES LIKE '$l'") === $l;

        $rows = [];
        if ( $q_exists ) {
            $jobs = $wpdb->get_results("SELECT id, content_hash, status, LEFT(telegram_data,80) as preview, created_at FROM $q ORDER BY id DESC LIMIT 10");
            foreach ($jobs as $j) {
                $rows[] = "[#{$j->id}] {$j->status} | hash:{$j->content_hash} | {$j->created_at} | {$j->preview}";
            }
        }

        wp_send_json_success([
            'queue_table'  => $q_exists ? "✅ existe ($q)" : "❌ NO existe ($q)",
            'log_table'    => $l_exists ? "✅ existe ($l)" : "❌ NO existe ($l)",
            'queue_rows'   => $q_exists ? (int)$wpdb->get_var("SELECT COUNT(*) FROM $q") : 0,
            'last_error'   => $wpdb->last_error ?: 'ninguno',
            'recent_jobs'  => $rows,
        ]);
    }

    public function ajax_repair_db(): void {
        $this->check_nonce();
        TTW_Queue_Manager::create_tables();
        wp_send_json_success( [ 'message' => '✅ Tablas reparadas/migradas correctamente.' ] );
    }

    public function ajax_clear_logs(): void {
        $this->check_nonce();
        update_option( 'ttw_raw_log',   [] );
        update_option( 'ttw_event_log', [] );
        wp_send_json_success();
    }

    /* ════════════════ PÁGINAS ════════════════ */

    public function page_settings(): void {
        $token   = get_option( 'ttw_telegram_token', '' );
        $hook_url = rest_url( 'ttw/v1/webhook' );
        ?>
        <div class="wrap">
            <h1>⚙️ Configuración — Telegram & IA</h1>
            <?php if ( isset($_GET['settings-updated']) ) echo '<div class="notice notice-success is-dismissible"><p>Guardado.</p></div>'; ?>
            <form method="post" action="options.php">
                <?php settings_fields( 'ttw_group' ); ?>
                <table class="form-table">
                    <tr>
                        <th>Telegram Bot Token</th>
                        <td><input type="text" name="ttw_telegram_token" value="<?php echo esc_attr($token); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th>Username del Bot (@)</th>
                        <td>
                            <input type="text" name="ttw_bot_username" value="<?php echo esc_attr( get_option('ttw_bot_username','') ); ?>" class="regular-text" placeholder="ej: MiBotTelegram">
                            <p class="description">El @username de tu bot. Necesario para añadirlo a grupos. Consúltalo en <a href="https://t.me/BotFather" target="_blank">@BotFather</a> → /mybots.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>OpenAI API Key</th>
                        <td><input type="password" name="ttw_openai_key" value="<?php echo esc_attr( get_option('ttw_openai_key','') ); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th>Destino por defecto</th>
                        <td>
                            <select name="ttw_default_destination">
                                <option value="blog"       <?php selected( get_option('ttw_default_destination'), 'blog' ); ?>>Blog (Categorías)</option>
                                <option value="buddypress" <?php selected( get_option('ttw_default_destination'), 'buddypress' ); ?>>BuddyPress (Grupos)</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Importar enlaces</th>
                        <td>
                            <label>
                                <input type="checkbox" name="ttw_any_link" value="1" <?php checked( get_option('ttw_any_link'), '1' ); ?>>
                                Importar <strong>cualquier enlace</strong> (no solo LinkedIn)
                            </label>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Guardar configuración'); ?>
            </form>

            <div class="card" style="max-width:650px;margin-top:20px;padding:16px">
                <h3 style="margin-top:0">🔗 Webhook de Telegram</h3>
                <?php if ( $token ): ?>
                <p>URL del webhook:<br><code style="word-break:break-all"><?php echo esc_html($hook_url); ?></code></p>
                <p>
                    <button id="ttw-reg-hook" class="button button-primary">🔗 Registrar / Actualizar webhook ahora</button>
                </p>
                <div id="ttw-reg-result" style="margin-top:8px;font-size:13px"></div>
                <?php else: ?>
                <p style="color:#d9534f">⚠ Guarda el Token primero.</p>
                <?php endif; ?>
            </div>

            <?php if ( $token && get_option('ttw_bot_username','') ): ?>
            <div class="card" style="max-width:650px;margin-top:16px;padding:16px">
                <h3 style="margin-top:0">👤 Añadir el bot a un grupo</h3>
                <p>Para que el bot reciba mensajes de un grupo de Telegram:</p>
                <ol>
                    <li>Abre el grupo en Telegram</li>
                    <li>Ve a <strong>Editar grupo → Añadir miembro</strong></li>
                    <li>Busca: <strong>@<?php echo esc_html( get_option('ttw_bot_username') ); ?></strong></li>
                    <li>Añádelo y dale permisos de administrador (para leer todos los mensajes)</li>
                </ol>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public function page_monitor(): void {
        global $wpdb;
        $stats    = TTW_Queue_Manager::get_stats();
        $jobs     = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}ttw_queue ORDER BY id DESC LIMIT 30" );
        $running  = $stats['process_status'] === 'running';
        ?>
        <div class="wrap">
            <h1>📊 Monitor</h1>
            <?php if ( isset($_GET['msg']) ) echo '<div class="notice notice-success is-dismissible"><p>Hecho.</p></div>'; ?>

            <div style="display:flex;gap:14px;flex-wrap:wrap;margin-bottom:18px">
                <?php
                $sc = $running ? '#f0ad4e' : '#888';
                $sl = $running ? '⚙️ Procesando' : '⏸ Parado';
                $this->card( 'Estado',            $sl,                               $sc );
                $this->card( 'Último inicio',     esc_html($stats['last_run']) );
                $this->card( 'Último fin',        esc_html($stats['last_finish']) );
                $this->card( 'Publicados (sesión)',  (string)$stats['session_published'], '#5cb85c' );
                ?>
            </div>
            <div style="display:flex;gap:14px;flex-wrap:wrap;margin-bottom:22px">
                <?php
                $this->card( '⏳ Pendientes',  (string)$stats['pending'],    '#f0ad4e', 'ttw-s-pending' );
                $this->card( '⚙️ Procesando',  (string)$stats['processing'], '#5bc0de', 'ttw-s-processing' );
                $this->card( '✅ Completados', (string)$stats['completed'],  '#5cb85c', 'ttw-s-completed' );
                $this->card( '❌ Fallidos',    (string)$stats['failed'],     '#d9534f', 'ttw-s-failed' );
                ?>
            </div>

            <div style="display:flex;gap:10px;align-items:center;margin-bottom:18px;flex-wrap:wrap">
                <button id="ttw-btn-start" class="button button-primary button-large" <?php echo $running?'disabled':''; ?>>▶ Iniciar proceso</button>
                <button id="ttw-btn-stop"  class="button button-secondary button-large" <?php echo !$running?'disabled':''; ?>>⏹ Detener</button>
                <span id="ttw-prog-label" style="font-size:13px;color:#555;margin-left:6px"></span>
                <button id="ttw-repair-db" class="button" style="margin-left:auto" title="Repara la tabla de cola si hay problemas de base de datos">🔧 Reparar BD</button>
                <span id="ttw-repair-out" style="font-size:12px;color:#5cb85c"></span>
            </div>

            <div id="ttw-prog-wrap" style="display:none;margin-bottom:18px">
                <div style="background:#e0e0e0;border-radius:4px;height:12px;max-width:480px;overflow:hidden">
                    <div id="ttw-prog-bar" style="background:#0073aa;height:100%;width:0%;transition:width .3s"></div>
                </div>
                <div id="ttw-prog-text" style="font-size:12px;color:#666;margin-top:4px">0 / <?php echo TTW_Queue_Manager::MAX_PUBLISH; ?></div>
            </div>

            <div id="ttw-log-wrap" style="background:#1e1e1e;color:#d4d4d4;font-family:monospace;font-size:12px;padding:12px;border-radius:6px;max-height:240px;overflow-y:auto;margin-bottom:22px;display:none">
                <div id="ttw-log-inner"></div>
            </div>

            <h2>Cola (últimas 30)</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr><th style="width:45px">ID</th><th>Grupo</th><th>Estado</th><th>Post ID</th><th>Error</th><th>Creado</th><th>Procesado</th><th style="width:70px">Borrar</th></tr></thead>
                <tbody>
                <?php foreach ( $jobs as $j ):
                    $d   = json_decode( $j->telegram_data, true );
                    $badge = match($j->status) {
                        'completed'  => '<span style="color:#5cb85c">✅ completado</span>',
                        'failed'     => '<span style="color:#d9534f">❌ fallido</span>',
                        'processing' => '<span style="color:#f0ad4e">⚙️ procesando</span>',
                        default      => '<span style="color:#888">⏳ pendiente</span>',
                    };
                    $del = wp_nonce_url( admin_url('admin.php?page=ttw-monitor&ttw_action=del_queue&id='.$j->id), 'ttw_delete' );
                ?>
                <tr>
                    <td><?php echo (int)$j->id; ?></td>
                    <td><?php echo esc_html($d['group_name']??'—'); ?></td>
                    <td><?php echo wp_kses_post($badge); ?></td>
                    <td><?php echo $j->wp_post_id ? '<a href="'.get_edit_post_link($j->wp_post_id).'">'.(int)$j->wp_post_id.'</a>' : '—'; ?></td>
                    <td style="white-space:normal;font-size:11px"><?php echo esc_html($j->error_message??''); ?></td>
                    <td><?php echo esc_html($j->created_at); ?></td>
                    <td><?php echo esc_html($j->processed_at??'—'); ?></td>
                    <td><a href="<?php echo esc_url($del); ?>" class="button button-small" onclick="return confirm('¿Borrar?')">Borrar</a></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function page_activity(): void {
        global $wpdb;
        $rows  = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}ttw_log ORDER BY id DESC LIMIT 100" );
        $stats = TTW_Queue_Manager::get_stats();
        ?>
        <div class="wrap">
            <h1>📋 Actividad de grupos</h1>
            <div style="display:flex;gap:14px;flex-wrap:wrap;margin-bottom:20px">
                <?php
                $this->card( '🆕 Grupos nuevos',       (string)$stats['new_groups'],    '#5cb85c' );
                $this->card( '🔄 Grupos actualizados', (string)$stats['updated_groups'], '#5bc0de' );
                ?>
            </div>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr><th>Evento</th><th>Grupo</th><th>Destino</th><th>ID destino</th><th>Post/Activity</th><th>Fecha</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r):
                    $b = $r->event_type === 'group_created'
                        ? '<span style="background:#5cb85c;color:#fff;padding:2px 6px;border-radius:3px;font-size:11px">NUEVO</span>'
                        : '<span style="background:#5bc0de;color:#fff;padding:2px 6px;border-radius:3px;font-size:11px">ACTUALIZADO</span>';
                ?>
                <tr>
                    <td><?php echo wp_kses_post($b); ?></td>
                    <td><?php echo esc_html($r->group_name); ?></td>
                    <td><?php echo esc_html($r->destination_type); ?></td>
                    <td><?php echo (int)$r->destination_id; ?></td>
                    <td><?php echo $r->wp_post_id ? '<a href="'.get_edit_post_link($r->wp_post_id).'">'.(int)$r->wp_post_id.'</a>' : '—'; ?></td>
                    <td><?php echo esc_html($r->created_at); ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function page_content(): void {
        if ( isset($_GET['msg']) ) echo '<div class="notice notice-success is-dismissible"><p>Hecho.</p></div>';
        $dest = get_option('ttw_default_destination','blog');
        if ( $dest === 'buddypress' && function_exists('bp_activity_get') ) {
            $this->content_bp();
        } else {
            $this->content_blog();
        }
    }

    private function content_blog(): void {
        $posts = get_posts(['post_status'=>['draft','publish'],'posts_per_page'=>50,'orderby'=>'date','order'=>'DESC']); ?>
        <div class="wrap"><h1>🗑️ Contenido — Blog</h1>
        <table class="wp-list-table widefat fixed striped">
            <thead><tr><th>ID</th><th>Título</th><th>Categoría</th><th>Estado</th><th>Fecha</th><th>Acción</th></tr></thead>
            <tbody>
            <?php foreach ($posts as $p):
                $cats = get_the_category($p->ID);
                $del  = wp_nonce_url(admin_url('admin.php?page=ttw-content&ttw_action=del_post&id='.$p->ID),'ttw_delete');
            ?>
            <tr>
                <td><?php echo $p->ID; ?></td>
                <td><a href="<?php echo get_edit_post_link($p->ID); ?>"><?php echo esc_html($p->post_title); ?></a></td>
                <td><?php echo esc_html($cats ? implode(', ',wp_list_pluck($cats,'name')) : '—'); ?></td>
                <td><?php echo esc_html($p->post_status); ?></td>
                <td><?php echo esc_html($p->post_date); ?></td>
                <td><a href="<?php echo esc_url($del); ?>" class="button button-small button-link-delete" onclick="return confirm('¿Eliminar?')">Eliminar</a></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php
    }

    private function content_bp(): void {
        $acts = bp_activity_get(['component'=>'groups','type'=>'activity_update','per_page'=>50,'show_hidden'=>true]); ?>
        <div class="wrap"><h1>🗑️ Contenido — BuddyPress</h1>
        <table class="wp-list-table widefat fixed striped">
            <thead><tr><th>ID</th><th>Grupo</th><th>Extracto</th><th>Fecha</th><th>Acción</th></tr></thead>
            <tbody>
            <?php foreach ($acts['activities'] as $a):
                $g   = groups_get_group($a->item_id);
                $del = wp_nonce_url(admin_url('admin.php?page=ttw-content&ttw_action=del_bp&id='.$a->id),'ttw_delete');
            ?>
            <tr>
                <td><?php echo (int)$a->id; ?></td>
                <td><?php echo esc_html($g->name??'—'); ?></td>
                <td><?php echo esc_html(wp_trim_words(strip_tags($a->content),15)); ?></td>
                <td><?php echo esc_html($a->date_recorded); ?></td>
                <td><a href="<?php echo esc_url($del); ?>" class="button button-small button-link-delete" onclick="return confirm('¿Eliminar?')">Eliminar</a></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php
    }

    public function page_diag(): void {
        $token    = get_option('ttw_telegram_token','');
        $hook_url = rest_url('ttw/v1/webhook');
        $events   = get_option('ttw_event_log',[]);
        $raw      = get_option('ttw_raw_log',[]);
        ?>
        <div class="wrap">
            <h1>🔍 Diagnóstico</h1>

            <!-- Webhook -->
            <div class="card" style="max-width:700px;margin-bottom:18px;padding:16px">
                <h2 style="margin-top:0">1. Webhook</h2>
                <p>URL: <code style="word-break:break-all"><?php echo esc_html($hook_url); ?></code></p>
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    <button id="ttw-reg-hook2" class="button button-primary">🔗 Registrar / Actualizar</button>
                    <button id="ttw-chk-hook"  class="button">🔄 Consultar estado</button>
                </div>
                <pre id="ttw-hook-out" style="background:#1e1e1e;color:#6fc;padding:10px;border-radius:4px;margin-top:10px;display:none;font-size:11px;max-height:180px;overflow:auto"></pre>
            </div>

            <!-- OpenAI -->
            <div class="card" style="max-width:700px;margin-bottom:18px;padding:16px">
                <h2 style="margin-top:0">2. Conexión OpenAI</h2>
                <?php if ( get_option('ttw_openai_key','') ): ?>
                <button id="ttw-test-ai" class="button button-primary">🤖 Probar gpt-4o</button>
                <div id="ttw-ai-out" style="margin-top:8px;font-size:13px;font-weight:600"></div>
                <?php else: ?>
                <p style="color:#d9534f">⚠ API Key de OpenAI no configurada.</p>
                <?php endif; ?>
            </div>

            <!-- Reimportar -->
            <div class="card" style="max-width:700px;margin-bottom:18px;padding:16px">
                <h2 style="margin-top:0">3. Reimportar desde log raw</h2>
                <p style="color:#666;font-size:13px">Si los mensajes aparecen en el log raw (sección 5) pero no en la cola, usa este botón para reintentarlo.</p>
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:8px">
                    <button id="ttw-force" class="button button-primary">🔁 Reimportar desde log</button>
                    <button id="ttw-force-reset" class="button button-secondary">🗑 Limpiar cola y reimportar</button>
                </div>
                <div id="ttw-force-out" style="margin-top:4px;font-size:13px;font-weight:600"></div>
            </div>

            <!-- Estado BD -->
            <div class="card" style="max-width:900px;margin-bottom:18px;padding:16px">
                <h2 style="margin-top:0">3b. Estado de la base de datos</h2>
                <p style="color:#666;font-size:13px">Verifica que las tablas existen y muestra los últimos trabajos en cola. Si las tablas no existen, usa "Reparar BD" en el Monitor.</p>
                <button id="ttw-db-status" class="button">🔍 Verificar BD ahora</button>
                <pre id="ttw-db-out" style="background:#1e1e1e;color:#6fc;padding:10px;border-radius:4px;margin-top:10px;display:none;font-size:11px;max-height:250px;overflow:auto;white-space:pre-wrap"></pre>
            </div>

            <!-- Log eventos -->
            <div class="card" style="max-width:900px;margin-bottom:18px;padding:16px">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
                    <h2 style="margin:0">4. Log de eventos (últimos 50)</h2>
                    <button id="ttw-clear-log" class="button button-small">🗑 Limpiar</button>
                </div>
                <?php if ( empty($events) ): ?>
                <p style="color:#999"><em>Sin eventos. Los mensajes de Telegram aparecerán aquí al llegar.</em></p>
                <?php else: ?>
                <table class="wp-list-table widefat fixed striped" style="font-size:12px">
                    <thead><tr><th style="width:130px">Fecha</th><th style="width:80px">Tipo</th><th>Mensaje</th></tr></thead>
                    <tbody>
                    <?php foreach (array_reverse($events) as $e):
                        $c = match($e['type']) { 'queued'=>'#5cb85c','no_link'=>'#f0ad4e','no_message'=>'#d9534f', default=>'#888' };
                    ?>
                    <tr>
                        <td><?php echo esc_html($e['time']); ?></td>
                        <td><span style="color:<?php echo esc_attr($c); ?>;font-weight:600"><?php echo esc_html($e['type']); ?></span></td>
                        <td style="white-space:normal;word-break:break-all"><?php echo esc_html($e['msg']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

            <!-- Raw JSON -->
            <div class="card" style="max-width:900px;padding:16px">
                <h2 style="margin-top:0">5. Updates raw de Telegram (últimos 20)</h2>
                <?php if ( empty($raw) ): ?>
                <p style="color:#999"><em>Sin datos.</em></p>
                <?php else: ?>
                <div style="background:#1e1e1e;color:#d4d4d4;font-family:monospace;font-size:11px;padding:12px;border-radius:6px;max-height:400px;overflow:auto">
                <?php foreach (array_reverse($raw) as $r): ?>
                    <div style="border-bottom:1px solid #333;padding:6px 0">
                        <span style="color:#888"><?php echo esc_html($r['time']); ?></span>
                        <pre style="margin:4px 0 0;white-space:pre-wrap;word-break:break-all"><?php echo esc_html(json_encode($r['data'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)); ?></pre>
                    </div>
                <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /* ── Helper tarjeta ── */
    private function card( string $label, string $value, string $color = '#444', string $id = '' ): void {
        $attr = $id ? ' id="'.esc_attr($id).'"' : '';
        echo '<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:14px 18px;min-width:130px;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,.08)">'
            .'<div'.$attr.' style="font-size:24px;font-weight:700;color:'.esc_attr($color).'">'.wp_kses_post($value).'</div>'
            .'<div style="font-size:11px;color:#666;margin-top:3px">'.esc_html($label).'</div>'
            .'</div>';
    }
}
