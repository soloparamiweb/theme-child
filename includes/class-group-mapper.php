<?php

class TTW_Group_Mapper {

    /**
     * Obtiene el destino configurado para un grupo de Telegram específico.
     * Devuelve un array con el tipo (blog/buddypress) y el ID del destino.
     */
    public static function get_destination( string $telegram_group_name ): array {
        // Recuperamos los mapeos guardados en la base de datos
        $mappings = get_option( 'ttw_group_mappings', [] );

        // Si el grupo tiene una regla específica, la usamos
        if ( isset( $mappings[ $telegram_group_name ] ) ) {
            return [
                'type' => $mappings[ $telegram_group_name ]['type'],
                'target_id' => (int) $mappings[ $telegram_group_name ]['target_id']
            ];
        }

        // Si no hay regla, usamos el comportamiento por defecto
        return [
            'type'      => get_option( 'ttw_default_destination', 'blog' ),
            'target_id' => 0 // 0 indica que se debe crear dinámicamente o usar el default
        ];
    }

    /**
     * Guarda una nueva regla de mapeo
     */
    public static function save_mapping( string $telegram_group, string $type, int $target_id ): void {
        $mappings = get_option( 'ttw_group_mappings', [] );
        
        $mappings[ $telegram_group ] = [
            'type'      => $type,
            'target_id' => $target_id
        ];

        update_option( 'ttw_group_mappings', $mappings );
    }
}