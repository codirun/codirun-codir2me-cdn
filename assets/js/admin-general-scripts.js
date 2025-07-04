/**
 * JavaScript para o Admin UI General.
 *
 */

/* global jQuery, ajaxurl, codir2me_admin_vars, navigator, document */

jQuery(document).ready(function ($) {
	// Atualizar campos ocultos quando os toggles mudarem.
	$('#codir2me-debug-mode').change(function () {
		const isChecked = $(this).is(':checked');
		const statusElement = $('.codir2me-debug-status');

		if (isChecked) {
			statusElement
				.text(codir2me_admin_vars.debug_active)
				.removeClass('inactive')
				.addClass('active');
		} else {
			statusElement
				.text(codir2me_admin_vars.debug_inactive)
				.removeClass('active')
				.addClass('inactive');
		}
	});
	
	// Funcionalidade para mostrar/ocultar logs.
	$('#codir2me-toggle-logs').off('click').on('click', function(e) {
		e.preventDefault(); // Previne qualquer comportamento padrão
		e.stopPropagation(); // Para a propagação do evento
		
		const $button = $(this);
		const $logsContainer = $('#codir2me-logs-container');
		
		console.log('Clique detectado! Container visível:', $logsContainer.is(':visible'));
		
		if ($logsContainer.is(':visible')) {
			$logsContainer.slideUp(300);
			$button.text('Mostrar/Ocultar Logs');
			console.log('Fechando logs');
		} else {
			$logsContainer.slideDown(300);
			$button.text('Mostrar/Ocultar Logs');
			console.log('Abrindo logs');
			
			// Rolar para o final dos logs quando mostrar.
			setTimeout(function() {
				if ($logsContainer.length && $logsContainer[0].scrollHeight) {
					$logsContainer.scrollTop($logsContainer[0].scrollHeight);
				}
			}, 350);
		}
		
		return false; // Garante que não há outros eventos
	});

	// Funcionalidade para o botão de copiar informações do sistema.
	$('#codir2me-copy-system-info').click(function () {
		const systemInfo = document.getElementById('codir2me-system-info');

		// Usar a Clipboard API moderna se disponível.
		if (navigator.clipboard && window.isSecureContext) {
			navigator.clipboard
				.writeText(systemInfo.value)
				.then(function () {
					$('#codir2me-copy-success')
						.fadeIn(300)
						.delay(1500)
						.fadeOut(300);
				})
				.catch(function (error) {
					/* eslint-disable no-console */
					console.error(codir2me_admin_vars.copy_error, error);
					/* eslint-enable no-console */

					// Fallback para o método antigo.
					fallbackCopyTextToClipboard(systemInfo);
				});
		} else {
			// Fallback para o método antigo.
			fallbackCopyTextToClipboard(systemInfo);
		}
	});

	// Método de fallback para navegadores antigos.
	function fallbackCopyTextToClipboard(textArea) {
		textArea.select();
		try {
			const successful = document.execCommand('copy');
			if (successful) {
				$('#codir2me-copy-success')
					.fadeIn(300)
					.delay(1500)
					.fadeOut(300);
			}
		} catch (err) {
			/* eslint-disable no-console */
			console.error(codir2me_admin_vars.fallback_error, err);
			/* eslint-enable no-console */
		}
	}

	// Verificação de ambiente JavaScript.
	$('#codir2me-check-environment').click(function () {
		const $button = $(this);
		const $results = $('#codir2me-environment-check-results');

		// Mostrar área de resultados com spinner.
		$results.show();
		$button.prop('disabled', true);

		// Fazer requisição AJAX.
		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'codir2me_check_environment',
				nonce: $('#codir2me_env_check_nonce').val(),
			},
			success(response) {
				if (response.success) {
					codir2me_renderEnvironmentResults(response.data);
				} else {
					$results.html('<div class="notice notice-error inline"><p>' + codir2me_admin_vars.check_error + ' ' + response.data.message + '</p></div>');
				}
			},
			error() {
				$results.html('<div class="notice notice-error inline"><p>' + codir2me_admin_vars.ajax_error + '</p></div>');
			},
			complete: function () {
				$button.prop('disabled', false);
			},
		});
	});

	// Função para renderizar os resultados da verificação.
	function codir2me_renderEnvironmentResults(data) {
		const $results = $('#codir2me-environment-check-results');
		const statusClass =
			data.overall_status === 'success'
				? 'success'
				: data.overall_status === 'warning'
					? 'warning'
					: 'error';
		const statusIcon =
			data.overall_status === 'success'
				? 'yes'
				: data.overall_status === 'warning'
					? 'warning'
					: 'no';
		const statusText =
			data.overall_status === 'success'
				? codir2me_admin_vars.compatible
				: data.overall_status === 'warning'
					? codir2me_admin_vars.partially_compatible
					: codir2me_admin_vars.not_compatible;

		let html =
			'<div class="codir2me-environment-summary notice notice-' +
			statusClass +
			' inline">' +
			'<h3><span class="dashicons dashicons-' +
			statusIcon +
			'"></span> ' +
			codir2me_admin_vars.overall_status +
			' ' +
			statusText +
			'</h3>' +
			'</div>';

		// Detalhes das verificações.
		html += '<div class="codir2me-environment-details">';

		// PHP Version.
		let check = data.checks.php;
		html += codir2me_renderCheckItem(check);

		// WordPress Version.
		check = data.checks.wordpress;
		html += codir2me_renderCheckItem(check);

		// PHP Extensions.
		check = data.checks.extensions;
		html +=
			'<div class="codir2me-check-item">' +
			'<h4 class="codir2me-check-title ' +
			check.status +
			'">' +
			'<span class="dashicons dashicons-' +
			(check.status === 'success'
				? 'yes'
				: check.status === 'warning'
					? 'warning'
					: 'no') +
			'"></span> ' +
			check.title +
			'</h4>' +
			'<div class="codir2me-check-details">' +
			'<p>' +
			check.message +
			'</p>';

		if (check.status !== 'success') {
			if (check.missing && Object.keys(check.missing).length > 0) {
				html +=
					'<p><strong>' +
					codir2me_admin_vars.missing_extensions +
					'</strong></p><ul>';
				for (const ext in check.missing) {
					if (Object.prototype.hasOwnProperty.call(check.missing, ext)) {
						html +=
							'<li><code>' +
							ext +
							'</code>: ' +
							check.missing[ext] +
							'</li>';
					}
				}
				html += '</ul>';
			}

			if (
				check.optional_missing &&
				Object.keys(check.optional_missing).length > 0
			) {
				html +=
					'<p><strong>' +
					codir2me_admin_vars.missing_recommended +
					'</strong></p><ul>';
				for (const ext in check.optional_missing) {
					if (Object.prototype.hasOwnProperty.call(check.optional_missing, ext)) {
						html +=
							'<li><code>' +
							ext +
							'</code>: ' +
							check.optional_missing[ext] +
							'</li>';
					}
				}
				html += '</ul>';
			}
		}

		html += '</div></div>';

		// Directory Permissions.
		check = data.checks.permissions;
		html +=
			'<div class="codir2me-check-item">' +
			'<h4 class="codir2me-check-title ' +
			check.status +
			'">' +
			'<span class="dashicons dashicons-' +
			(check.status === 'success' ? 'yes' : 'no') +
			'"></span> ' +
			check.title +
			'</h4>' +
			'<div class="codir2me-check-details">' +
			'<p>' +
			check.message +
			'</p>';

		if (check.status !== 'success' && check.problem_dirs.length > 0) {
			html +=
				'<p><strong>' +
				codir2me_admin_vars.problem_dirs +
				'</strong></p><ul>';
			const problemDirsLen = check.problem_dirs.length;
			for (let i = 0; i < problemDirsLen; i++) {
				const dir = check.problem_dirs[i];
				html +=
					'<li><code>' + dir.path + '</code>: ' + dir.issue + '</li>';
			}
			html += '</ul>';
		}

		html += '</div></div>';

		// AWS SDK.
		check = data.checks.aws_sdk;
		html += codir2me_renderCheckItem(check);

		// R2 Connection.
		check = data.checks.codir2me_connection;
		html +=
			'<div class="codir2me-check-item">' +
			'<h4 class="codir2me-check-title ' +
			check.status +
			'">' +
			'<span class="dashicons dashicons-' +
			(check.status === 'success'
				? 'yes'
				: check.status === 'warning'
					? 'warning'
					: 'no') +
			'"></span> ' +
			check.title +
			'</h4>' +
			'<div class="codir2me-check-details">' +
			'<p>' +
			check.message +
			'</p>';

		if (check.status === 'error' && check.error_details) {
			html +=
				'<p><strong>' +
				codir2me_admin_vars.error_details +
				'</strong></p>' +
				'<p>' +
				codir2me_admin_vars.error_code +
				' ' +
				check.error_details.code +
				'</p>' +
				'<p>' +
				codir2me_admin_vars.error_message +
				' ' +
				check.error_details.message +
				'</p>';
		}

		html += '</div></div>';

		html += '</div>'; // Fecha codir2me-environment-details.

		// Recomendações.
		if (data.recommendations && data.recommendations.length > 0) {
			html +=
				'<div class="codir2me-environment-recommendations">' +
				'<h3>' +
				codir2me_admin_vars.recommendations +
				'</h3>' +
				'<ul>';

			const recLen = data.recommendations.length;
			for (let i = 0; i < recLen; i++) {
				html += '<li>' + data.recommendations[i] + '</li>';
			}

			html += '</ul></div>';
		}

		$results.html(html);
	}

	// Função auxiliar para renderizar um item de verificação simples.
	function codir2me_renderCheckItem(check) {
		return (
			'<div class="codir2me-check-item">' +
			'<h4 class="codir2me-check-title ' +
			check.status +
			'">' +
			'<span class="dashicons dashicons-' +
			(check.status === 'success'
				? 'yes'
				: check.status === 'warning'
					? 'warning'
					: 'no') +
			'"></span> ' +
			check.title +
			'</h4>' +
			'<div class="codir2me-check-details">' +
			'<p>' +
			check.message +
			'</p>' +
			(check.current
				? '<p>' +
					codir2me_admin_vars.current +
					' <strong>' +
					check.current +
					'</strong>' +
					(check.recommended
						? ' (' +
							codir2me_admin_vars.recommended +
							' ' +
							check.recommended +
							')'
						: '') +
					'</p>'
				: '') +
			'</div></div>'
		);
	}
});