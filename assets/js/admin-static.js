/**
 * Scripts para a aba de arquivos estáticos do CDN R2
 *
 * @package
 */

jQuery(document).ready(function ($) {
	// Filtro de arquivos.
	$('#codir2me-filter-files').on('input', function () {
		const filter = $(this).val().toLowerCase();
		let count = 0;

		$('.codir2me-file-row').each(function () {
			const $row = $(this);
			const name = $row.data('name').toLowerCase();
			const path = $row.data('path').toLowerCase();
			const type = $row.data('type').toLowerCase();

			if (
				name.indexOf(filter) > -1 ||
				path.indexOf(filter) > -1 ||
				type.indexOf(filter) > -1
			) {
				$row.show();
				count++;
			} else {
				$row.hide();
			}
		});

		$('#codir2me-filtered-count').text(count);
	});

	// Botão de reenvio de arquivo.
	$('.codir2me-resync-file').on('click', function () {
		const $button = $(this);
		const path = $button.data('path');

		$button
			.prop('disabled', true)
			.find('.dashicons')
			.removeClass('dashicons-update')
			.addClass('dashicons-update codir2me-rotating');

		// Fazer requisição AJAX para reenviar o arquivo.
		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'codir2me_resync_file',
				nonce: codir2me.nonce,
				file_path: path,
			},
			success(response) {
				if (response.success) {
					// Atualizar a data na tabela.
					$button
						.closest('tr')
						.find('.codir2me-file-date')
						.text(response.data.date);

					// Adicionar feedback de sucesso.
					$button
						.closest('td')
						.append(
							'<span class="codir2me-success-msg">' +
								codir2me.updated +
								'</span>'
						);

					// Remover a mensagem após 3 segundos.
					setTimeout(function () {
						$button
							.closest('td')
							.find('.codir2me-success-msg')
							.fadeOut(300, function () {
								$(this).remove();
							});
					}, 3000);
				} else {
					// Adicionar feedback de erro.
					$button
						.closest('td')
						.append(
							'<span class="codir2me-error-msg">' +
								codir2me.error +
								' ' +
								response.data.message +
								'</span>'
						);

					// Remover a mensagem após 5 segundos.
					setTimeout(function () {
						$button
							.closest('td')
							.find('.codir2me-error-msg')
							.fadeOut(300, function () {
								$(this).remove();
							});
					}, 5000);
				}
			},
			error() {
				// Adicionar feedback de erro.
				$button
					.closest('td')
					.append(
						'<span class="codir2me-error-msg">' +
							codir2me.connectionError +
							'</span>'
					);

				// Remover a mensagem após 5 segundos.
				setTimeout(function () {
					$button
						.closest('td')
						.find('.codir2me-error-msg')
						.fadeOut(300, function () {
							$(this).remove();
						});
				}, 5000);
			},
			complete() {
				$button
					.prop('disabled', false)
					.find('.dashicons')
					.removeClass('codir2me-rotating dashicons-update')
					.addClass('dashicons-update');
			},
		});
	});

	// Função para copiar a configuração CORS.
	$('.codir2me-copy-cors-config').click(function () {
		const corsConfig = $(this).prev('pre').text();

		// Usar a Clipboard API moderna se disponível.
		if (navigator.clipboard && window.isSecureContext) {
			navigator.clipboard
				.writeText(corsConfig)
				.then(function () {
					// Mudar o texto do botão temporariamente.
					const $button = $('.codir2me-copy-cors-config');
					const originalText = $button.html();
					$button.html(
						'<span class="dashicons dashicons-yes"></span> ' +
							codir2me.copied
					);

					// Restaurar após 2 segundos.
					setTimeout(function () {
						$button.html(originalText);
					}, 2000);
				})
				.catch(function (error) {
					console.error(codir2me.copyError, error);

					// Fallback para o método antigo.
					fallbackCopyTextToClipboard(corsConfig);
				});
		} else {
			// Fallback para o método antigo.
			fallbackCopyTextToClipboard(corsConfig);
		}
	});

	// Método de fallback para navegadores antigos.
	function fallbackCopyTextToClipboard(text) {
		const textArea = document.createElement('textarea');
		textArea.value = text;

		// Tornar a área de texto invisível.
		textArea.style.position = 'fixed';
		textArea.style.top = 0;
		textArea.style.left = 0;
		textArea.style.width = '2em';
		textArea.style.height = '2em';
		textArea.style.padding = 0;
		textArea.style.border = 'none';
		textArea.style.outline = 'none';
		textArea.style.boxShadow = 'none';
		textArea.style.background = 'transparent';

		document.body.appendChild(textArea);
		textArea.focus();
		textArea.select();

		try {
			const successful = document.execCommand('copy');
			const $button = $('.codir2me-copy-cors-config');
			const originalText = $button.html();

			if (successful) {
				$button.html(
					'<span class="dashicons dashicons-yes"></span> ' +
						codir2me.copied
				);

				// Restaurar após 2 segundos.
				setTimeout(function () {
					$button.html(originalText);
				}, 2000);
			}
		} catch (err) {
			console.error(codir2me.fallbackError, err);
		}

		document.body.removeChild(textArea);
	}
});
