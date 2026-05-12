<?php
/**
 * Plugin Name: Telegram & AI Content Curator Pro
 * Plugin URI: https://paramiweb.com
 * Description: Importa enlaces de Telegram, los procesa con IA y publica en WordPress Blog o BuddyPress.
 * Version: 2.2.1
 * Author: Lorenzo
 * Requires PHP: 8.2
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── Compatibilidad: TTW_Group_Mapper definida aquí primero, antes de cualquier include ──
if ( ! class_exists( 'TTW_Group_Mapper' ) ) {
    class TTW_Group_Mapper {
        public static function get_destination( string $name ): array {
            $m = get_option( 'ttw_group_mappings', [] );
            if ( isset( $m[ $name ] ) ) {
                return [ 'type' => $m[$name]['type'], 'target_id' => (int) $m[$name]['target_id'] ];
            }
            return [ 'type' => get_option( 'ttw_default_destination', 'blog' ), 'target_id' => 0 ];
        }
        public static function save_mapping( string $g, string $t, int $id ): void {
            $m = get_option( 'ttw_group_mappings', [] );
            $m[$g] = [ 'type' => $t, 'target_id' => $id ];
            update_option( 'ttw_group_mappings', $m );
        }
    }
}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-telegram-handler.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-queue-manager.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-ai-processor.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/auto-linker.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-wp-integrator.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-admin-settings.php';

class Telegram_To_WP_Pro {

    public function __construct() {
        register_activation_hook( __FILE__, [ $this, 'activate' ] );
        register_deactivation_hook( __FILE__, [ $this, 'deactivate' ] );

        if ( is_admin() ) {
            new TTW_Admin_Settings();
            add_action( 'admin_init', [ $this, 'handle_admin_actions' ] );
        }

        add_action( 'rest_api_init', [ $this, 'register_webhook_endpoint' ] );
        add_action( 'wp_head', [ $this, 'ttw_read_more_css' ] );
    }

    public function activate(): void {
        TTW_Queue_Manager::create_tables();
        wp_clear_scheduled_hook( 'ttw_process_queue_cron' );
        wp_clear_scheduled_hook( 'ttw_purge_log_cron' );
        update_option( 'ttw_process_status',   'idle' );
        update_option( 'ttw_process_stop',      0 );
        update_option( 'ttw_session_published', 0 );
    }

    public function deactivate(): void {
        wp_clear_scheduled_hook( 'ttw_process_queue_cron' );
        wp_clear_scheduled_hook( 'ttw_purge_log_cron' );
    }

    public function handle_admin_actions(): void {
        if ( empty( $_GET['ttw_action'] ) || ! current_user_can( 'manage_options' ) ) return;
        if ( $_GET['ttw_action'] === 'delete_bp_activity'
            && ! empty( $_GET['activity_id'] )
            && check_admin_referer( 'ttw_delete_action' ) ) {
            if ( function_exists( 'bp_activity_delete' ) ) {
                bp_activity_delete( [ 'id' => (int) $_GET['activity_id'] ] );
            }
            wp_safe_redirect( add_query_arg( 'deleted', '1', admin_url( 'admin.php?page=ttw-content' ) ) );
            exit;
        }
    }

    public function register_webhook_endpoint(): void {
        $args = [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_webhook' ],
            'permission_callback' => '__return_true',
        ];
        register_rest_route( 'ttw/v1', '/webhook',          $args );
        register_rest_route( 'ttw/v1', '/incoming-message', $args );
    }

    public function handle_webhook( WP_REST_Request $request ): WP_REST_Response {
        $params = $request->get_json_params();
        if ( empty( $params ) ) return new WP_REST_Response( [ 'status' => 'no_data' ], 200 );
        $handler = new TTW_Telegram_Handler();
        $data    = $handler->parse_update( $params );
        if ( $data ) {
            $result = TTW_Queue_Manager::add_job( $data );
            TTW_Telegram_Handler::log_event( 'add_job_' . $result, $result . ' — «' . ( $data['group_name'] ?? '' ) . '»' );
        }
        return new WP_REST_Response( [ 'status' => 'ok' ], 200 );
    }



    public function ttw_read_more_css(): void {
        echo '<style>
        span.activity-read-more { pointer-events: none !important; }
        span.activity-read-more a { pointer-events: all !important; cursor: pointer; }
        .ttw-gallery-grid { display: grid; grid-template-columns: repeat(auto-fill,minmax(200px,1fr)); gap: 12px; margin: 20px 0; }
        .ttw-gallery-grid .ttw-gallery-item { margin: 0; line-height: 0; }
        .ttw-gallery-grid .ttw-gallery-item img { width: 100%; height: auto; border-radius: 8px; object-fit: cover; }
        </style>
        <script>
        jQuery(function($){$(document).on("click",".activity-read-more a",function(e){e.stopPropagation();var h=$(this).attr("href");if(h&&h!=="#")window.location.href=h})});
        </script>';
    }
}

new Telegram_To_WP_Pro();
