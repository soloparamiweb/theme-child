/* Telegram & AI Content Curator Pro — Admin JS 2.0.0 */
jQuery(function ($) {

    if ( typeof TTW === 'undefined' ) { console.error('TTW no definido'); return; }

    var nonce    = TTW.nonce;
    var ajax_url = TTW.ajax_url;
    var MAX      = parseInt(TTW.max, 10) || 30;

    function post(action, data, cb) {
        data = $.extend({ action: action, nonce: nonce }, data || {});
        $.post(ajax_url, data).done(function(r){ cb(null, r); }).fail(function(x){ cb('Error '+x.status); });
    }

    /* ══════════════ MONITOR ══════════════ */
    var $start = $('#ttw-btn-start');
    var $stop  = $('#ttw-btn-stop');

    if ( $start.length ) {
        var running   = false;
        var published = 0;

        $start.on('click', function() {
            post('ttw_start', {}, function(err, r) {
                if (err || !r.success) { alert('Error: '+(err||r.data)); return; }
                running = true; published = 0;
                $start.prop('disabled',true); $stop.prop('disabled',false);
                $('#ttw-prog-wrap').show(); $('#ttw-log-wrap').show(); $('#ttw-log-inner').html('');
                setBar(0); setLabel('Iniciando… Pendientes: '+r.data.pending);
                addLog('▶ Iniciado. Pendientes: '+r.data.pending, 'info');
                next();
            });
        });

        $stop.on('click', function() {
            post('ttw_stop', {}, function() {
                running = false;
                addLog('⏹ Señal de parada enviada.', 'warn');
            });
        });

        function next() {
            if (!running) return;
            post('ttw_process_one', {}, function(err, r) {
                if (err) { addLog('❌ '+err, 'error'); done('error'); return; }
                var d = r.data;
                if (d.status === 'ok') {
                    published = d.published; setBar(published);
                    setLabel(published+' / '+MAX+' publicados');
                    addLog('✅ ['+d.job_id+'] '+d.group_name+' → #'+d.post_id+' (quedan: '+Math.max(0,d.remaining)+')', 'ok');
                    setTimeout(next, 500);
                } else if (d.status === 'failed') {
                    addLog('⚠ ['+d.job_id+'] '+d.message, 'error');
                    setTimeout(next, 500);
                } else if (d.status === 'empty')   { addLog('📭 Cola vacía.',           'info'); done('empty'); }
                  else if (d.status === 'limit')   { addLog('🏁 Límite alcanzado.',     'warn'); done('limit'); }
                  else if (d.status === 'stopped') { addLog('⏹ Detenido.',              'warn'); done('stopped'); }
            });
        }

        function done(r) {
            running = false;
            $start.prop('disabled',false); $stop.prop('disabled',true);
            var m = {empty:'✔ Cola vacía.',limit:'✔ Límite alcanzado.',stopped:'✔ Detenido.',error:'✖ Error.'};
            setLabel(m[r]||'✔ Finalizado.');
            refreshStats();
        }

        function setBar(n) {
            var p = Math.min(Math.round(n/MAX*100),100);
            $('#ttw-prog-bar').css('width',p+'%');
            $('#ttw-prog-text').text(n+' / '+MAX+' publicaciones');
        }
        function setLabel(t) { $('#ttw-prog-label').text(t); }
        function addLog(msg, type) {
            var c = {ok:'#6fc',error:'#f88',warn:'#fd7',info:'#8cf'}[type]||'#d4d4d4';
            var t = new Date().toLocaleTimeString('es-ES');
            $('#ttw-log-inner').append($('<div>').css('color',c).text('['+t+'] '+msg));
            var w = document.getElementById('ttw-log-wrap');
            if (w) w.scrollTop = w.scrollHeight;
        }
        function refreshStats() {
            post('ttw_get_stats', {}, function(err, r) {
                if (err||!r.success) return;
                var d = r.data;
                $('#ttw-s-pending').text(d.pending);
                $('#ttw-s-processing').text(d.processing);
                $('#ttw-s-completed').text(d.completed);
                $('#ttw-s-failed').text(d.failed);
            });
        }
    }

    /* ══════════════ CONFIGURACIÓN — Registrar webhook ══════════════ */
    $('#ttw-reg-hook').on('click', function() {
        var $b = $(this), $r = $('#ttw-reg-result');
        $b.prop('disabled',true).text('Registrando...');
        post('ttw_register_hook', {}, function(err, r) {
            $b.prop('disabled',false).text('🔗 Registrar / Actualizar webhook ahora');
            if (err) { $r.css('color','#d9534f').text(err); return; }
            var ok = r.data && r.data.ok;
            $r.css('color', ok?'#5cb85c':'#d9534f').text(r.data.description||JSON.stringify(r.data));
        });
    });

    /* ══════════════ DIAGNÓSTICO ══════════════ */

    // Registrar webhook (botón en diag)
    $('#ttw-reg-hook2').on('click', function() {
        var $b = $(this), $o = $('#ttw-hook-out');
        $b.prop('disabled',true).text('Registrando...');
        $o.show().text('...');
        post('ttw_register_hook', {}, function(err, r) {
            $b.prop('disabled',false).text('🔗 Registrar / Actualizar');
            $o.text(JSON.stringify(err||r.data, null, 2));
        });
    });

    // Consultar estado webhook
    $('#ttw-chk-hook').on('click', function() {
        var $b = $(this), $o = $('#ttw-hook-out');
        $b.prop('disabled',true).text('Consultando...');
        $o.show().text('...');
        post('ttw_check_hook', {}, function(err, r) {
            $b.prop('disabled',false).text('🔄 Consultar estado');
            $o.text(JSON.stringify(err||r.data, null, 2));
        });
    });

    // Test OpenAI
    $('#ttw-test-ai').on('click', function() {
        var $b = $(this), $o = $('#ttw-ai-out');
        $b.prop('disabled',true).text('Probando...');
        $o.css('color','#888').text('Conectando...');
        post('ttw_test_openai', {}, function(err, r) {
            $b.prop('disabled',false).text('🤖 Probar gpt-4o');
            if (err) { $o.css('color','#d9534f').text(err); return; }
            $o.css('color', r.data.ok?'#5cb85c':'#d9534f').text(r.data.message);
        });
    });

    // Reimportar desde raw log
    $('#ttw-force').on('click', function() {
        var $b = $(this), $o = $('#ttw-force-out');
        $b.prop('disabled',true).text('Procesando...');
        post('ttw_force_import', {}, function(err, r) {
            $b.prop('disabled',false).text('🔁 Reimportar desde log');
            if (err) { $o.css('color','#d9534f').text(err); return; }
            var c = r.data.added > 0 ? '#5cb85c' : '#f0ad4e';
            $o.css('color',c).text(r.data.message || 'Añadidos: '+r.data.added+' | Pendientes: '+r.data.pending);
        });
    });

    // Limpiar cola y reimportar (borra completados/fallidos antes)
    $('#ttw-force-reset').on('click', function() {
        if (!confirm('¿Borrar trabajos completados y fallidos de la cola antes de reimportar?')) return;
        var $b = $(this), $o = $('#ttw-force-out');
        $b.prop('disabled',true).text('Limpiando...');
        post('ttw_force_import', {reset_dupes: 1}, function(err, r) {
            $b.prop('disabled',false).text('🗑 Limpiar cola y reimportar');
            if (err) { $o.css('color','#d9534f').text(err); return; }
            var c = r.data.added > 0 ? '#5cb85c' : '#f0ad4e';
            $o.css('color',c).text(r.data.message || 'Añadidos: '+r.data.added+' | Pendientes: '+r.data.pending);
        });
    });

    // Estado BD
    $('#ttw-db-status').on('click', function() {
        var $b = $(this), $o = $('#ttw-db-out');
        $b.prop('disabled',true).text('Verificando...');
        $o.show().text('...');
        post('ttw_db_status', {}, function(err, r) {
            $b.prop('disabled',false).text('🔍 Verificar BD ahora');
            if (err) { $o.text(err); return; }
            var d = r.data;
            var out = 'Tabla cola: ' + d.queue_table + '\n'
                    + 'Tabla log:  ' + d.log_table + '\n'
                    + 'Filas en cola: ' + d.queue_rows + '\n'
                    + 'Último error BD: ' + d.last_error + '\n\n'
                    + 'Últimos 10 trabajos:\n'
                    + (d.recent_jobs.length ? d.recent_jobs.join('\n') : '(ninguno)');
            $o.text(out);
        });
    });

    // Limpiar logs
    $('#ttw-clear-log').on('click', function() {
        if (!confirm('¿Limpiar todos los logs?')) return;
        post('ttw_clear_logs', {}, function() { location.reload(); });
    });

    // Reparar BD
    $('#ttw-repair-db').on('click', function() {
        var $b = $(this), $o = $('#ttw-repair-out');
        $b.prop('disabled',true).text('Reparando...');
        post('ttw_repair_db', {}, function(err, r) {
            $b.prop('disabled',false).text('🔧 Reparar BD');
            if (err) { $o.css('color','#d9534f').text(err); return; }
            $o.css('color','#5cb85c').text(r.data.message);
            setTimeout(function(){ $o.text(''); }, 4000);
        });
    });

});