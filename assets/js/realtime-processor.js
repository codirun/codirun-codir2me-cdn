/**
 * JavaScript para a página de reprocessamento em background do plugin R2 Static & Media CDN
 *
 */

/* global jQuery, ajaxurl, codir2me_ajax, codir2me_has_pending */

jQuery(document).ready(function ($) {
	// Processar em tempo real quando o usuário estiver na página.
	function processRealtimeBatch() {
		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'codir2me_process_batch_realtime',
				nonce: codir2me_ajax.nonce,
			},
			success(response) {
				if (response.success && !response.completed) {
					// Continuar processando.
					setTimeout(processRealtimeBatch, 1000); // 1 segundo entre lotes.
				}
			},
		});
	}

	// Iniciar se há processamento pendente.
	if (typeof codir2me_has_pending !== 'undefined' && codir2me_has_pending) {
		processRealtimeBatch();
	}
});