/**
 * JavaScript para a página de reprocessamento do plugin R2 Static & Media CDN
 *
 */

/* global jQuery, wp, codir2me_reprocessor_vars, confirm, console, window */

jQuery(document).ready(function ($) {
	// Verificar se já foi inicializado para evitar duplicação.
	if (window.codir2me_reprocessor_initialized) {
		return;
	}
	window.codir2me_reprocessor_initialized = true;

	// Verificar se as variáveis do reprocessador existem.
	if (typeof codir2me_reprocessor_vars === 'undefined') {
		/* eslint-disable no-console */
		console.error(
			'codir2me_reprocessor_vars não está definido. Verifique se o script foi carregado corretamente.'
		);
		/* eslint-enable no-console */
		return;
	}

	// Usar as strings de tradução.
	const i18n = codir2me_reprocessor_vars;

	// Variáveis para controle.
	let selectedImages = [];
	let mediaFrame = null;

	// Função para inicializar o seletor de mídia do WordPress apenas uma vez.
	function initMediaFrame() {
		if (mediaFrame === null) {
			mediaFrame = wp.media({
				title: i18n.select_images_title,
				button: {
					text: i18n.select_images_button,
				},
				multiple: true,
				library: {
					type: 'image',
				},
			});

			// Quando as imagens forem selecionadas na galeria de mídia.
			mediaFrame.on('select', function () {
				const selection = mediaFrame.state().get('selection');
				const ids = selection.pluck('id');

				// Atualizar a lista de IDs selecionados.
				selectedImages = ids;
				$('#selected-images-input').val(ids.join(','));
				$('#selected-images-count').text(
					i18n.x_images_selected.replace('%d', ids.length)
				);

				// Atualizar estado do botão de envio.
				$('#start-manual-upload').prop('disabled', ids.length === 0);

				// Obter visualização das imagens selecionadas.
				if (ids.length > 0) {
					$.ajax({
						url: i18n.ajaxurl,
						type: 'POST',
						data: {
							action: 'codir2me_get_selected_images_preview',
							nonce: i18n.nonce,
							ids: ids.join(','),
						},
						success(response) {
							if (response.success) {
								renderSelectedImagesPreview(response.data.images);
							} else {
								$('#selected-images-preview').html('<p class="no-images-selected">' + (response.data.message || i18n.error_loading_preview) + '</p>');
							}
						},
						error() {
							$('#selected-images-preview').html('<p class="no-images-selected">' + i18n.error_loading_preview_retry + '</p>');
						},
					});
				} else {
					$('#selected-images-preview').html(
						'<p class="no-images-selected">' +
							i18n.no_images_selected +
							'</p>'
					);
				}
			});
		}
		return mediaFrame;
	}

	// Quando o botão de selecionar imagens for clicado.
	$(document)
		.off('click.codir2me', '#codir2me-select-images')
		.on('click.codir2me', '#codir2me-select-images', function (e) {
			e.preventDefault();
			e.stopPropagation();
			initMediaFrame().open();
		});

	// Função para renderizar a visualização das imagens selecionadas.
	function renderSelectedImagesPreview(images) {
		if (images.length === 0) {
			$('#selected-images-preview').html(
				'<p class="no-images-selected">' +
					i18n.no_images_selected +
					'</p>'
			);
			return;
		}

		let html = '';

		images.forEach(function (image) {
			html += `
					<div class="codir2me-selected-image-item" data-id="${image.id}">
						${image.thumbnail_html}
						<div class="codir2me-selected-image-info">
							<div class="codir2me-selected-image-title" title="${image.title}">
								${truncateText(image.title, 20)}
							</div>
							<div class="codir2me-selected-image-meta">
								${image.dimensions ? image.dimensions + ' - ' : ''}${image.size}
							</div>
						</div>
						<div class="codir2me-selected-image-remove" title="${i18n.remove}" data-id="${image.id}">×</div>
					</div>
					`;
		});

		$('#selected-images-preview').html(html);

		// Adicionar manipulador para remover imagens usando namespace.
		$(document)
			.off('click.codir2me', '.codir2me-selected-image-remove')
			.on(
				'click.codir2me',
				'.codir2me-selected-image-remove',
				function () {
					const id = $(this).data('id');
					removeSelectedImage(id);
				}
			);
	}

	// Função para remover uma imagem da seleção.
	function removeSelectedImage(id) {
		selectedImages = selectedImages.filter((item) => item != id);
		$('#selected-images-input').val(selectedImages.join(','));
		$('#selected-images-count').text(
			i18n.x_images_selected.replace('%d', selectedImages.length)
		);
		$('#start-manual-upload').prop('disabled', selectedImages.length === 0);

		if (selectedImages.length > 0) {
			$(`.codir2me-selected-image-item[data-id="${id}"]`).fadeOut(
				300,
				function () {
					$(this).remove();
				}
			);
		} else {
			$('#selected-images-preview').html(
				'<p class="no-images-selected">' +
					i18n.no_images_selected +
					'</p>'
			);
		}
	}

	// Verificar se auto_continue está na URL.
	const urlParams = new URLSearchParams(window.location.search);
	let hasTriggeredContinue = false; // Flag para evitar múltiplas submissões.

	if (
		urlParams.has('auto_continue') &&
		urlParams.get('auto_continue') == '1' &&
		!hasTriggeredContinue
	) {
		hasTriggeredContinue = true; // Marcar como já acionado.

		// Adicionar campo auto_continue no formulário
		const $form = $('form.codir2me-continue-form');
		
		if ($form.length) {
			// Verificar se o campo já existe
			if ($form.find('input[name="auto_continue"]').length === 0) {
				// Adicionar campo hidden com auto_continue=1
				$form.append('<input type="hidden" name="auto_continue" value="1">');
			}
		}

		// Dar tempo para a página carregar completamente antes de continuar o processamento.
		setTimeout(function () {
			// Desabilitar o botão para evitar cliques múltiplos.
			const $button = $(
				'form.codir2me-continue-form button[name="codir2me_process_reprocessing_batch"]'
			);
			if ($button.length && !$button.prop('disabled')) {
				$button.prop('disabled', true);
				$button.closest('form').submit();
			}
		}, 1500); // 1,5 segundos de atraso para garantir que a página carregou.
	}

	// Função auxiliar para truncar texto.
	function truncateText(text, maxLength) {
		if (text.length <= maxLength) {
			return text;
		}
		return text.substr(0, maxLength) + '...';
	}

	// Confirmação para o botão Parar.
	$(document)
		.off('submit.codir2me', '.codir2me-stop-form')
		.on('submit.codir2me', '.codir2me-stop-form', function (e) {
			// Sempre prevenir o envio padrão primeiro.
			e.preventDefault();
			e.stopPropagation();

			const $form = $(this);
			const $button = $form.find('button[type="submit"]');

			// Verificar se já está processando para evitar múltiplos cliques.
			if ($button.hasClass('processing')) {
				return false;
			}

			// Marcar como processando.
			$button.addClass('processing');

			// Mostrar confirmação.
			/* eslint-disable no-alert */
			const confirmed = confirm(i18n.confirm_stop_message);
			/* eslint-enable no-alert */

			if (confirmed) {
				// Usuário confirmou - enviar o formulário.
				// Remover todos os event listeners para evitar loop.
				$form.off('submit.codir2me');

				// Desabilitar o botão para evitar múltiplos envios.
				$button.prop('disabled', true).text('Parando...');

				// Enviar o formulário usando o método nativo.
				$form.get(0).submit();
			} else {
				// Usuário cancelou - remover a classe de processamento.
				$button.removeClass('processing');
			}

			return false;
		});

	// Verifica se estamos em modo background e se o reprocessamento está em andamento.
	if ($('.codir2me-progress-bar').length) {
		// Função para atualizar o status do processamento em segundo plano.
		function updateBackgroundStatus() {
			$.ajax({
				url: i18n.ajaxurl,
				type: 'POST',
				data: {
					action: 'codir2me_get_background_status',
					nonce: i18n.nonce,
				},
				success(response) {
					if (response.success) {
						const data = response.data;

						// Atualizar progresso.
						$('.codir2me-progress-value:contains("de")').text(`${data.processed_images} de ${data.total_images}`);

						// Atualizar porcentagem.
						const percent = data.total_images > 0 ? (data.processed_images / data.total_images * 100) : 0;
						$('.codir2me-progress-value:contains("%")').text(`${Math.round(percent * 10) / 10}%`);

						// Atualizar barra de progresso.
						$('.codir2me-progress-inner').css('width', `${percent}%`);

						// Atualizar status (pausado/executando).
						if (data.paused) {
							$('.status-indicator').removeClass('running').addClass('paused').text(i18n.paused);
						} else {
							$('.status-indicator').removeClass('paused').addClass('running').text(i18n.running);
						}

						// Se o processo terminou, recarregar a página.
						if (!data.in_progress) {
							window.location.reload();
						}
					}
				}
			});
		}

		// Atualizar status a cada 5 segundos se o modo background estiver ativo.
		if ($('.status-indicator.running').length) {
			setInterval(updateBackgroundStatus, 5000);
		}
		
		// Função para mostrar avisos contextuais
		function codir2me_showReprocessingNotice(type, message) {
			// Remover avisos existentes
			$('.codir2me-reprocessing-notice').remove();
			
			const noticeClass = type === 'error' ? 'notice-error' : 'notice-info';
			const notice = $('<div class="notice ' + noticeClass + ' codir2me-reprocessing-notice" style="margin: 15px 0;"><p>' + message + '</p></div>');
			
			$('.codir2me-conversion-options').after(notice);
			
			// Auto-remover após 5 segundos se for info.
			if (type === 'info') {
				setTimeout(function() {
					notice.fadeOut(300, function() {
						$(this).remove();
					});
				}, 5000);
			}
		}
						
		// Validação do formulário antes do envio
		$('form').on('submit', function(e) {
			const $form = $(this);
			
			// Verificar se é o formulário de configurações
			if ($form.find('input[name="action"][value="codir2me_update_reprocessing_settings"]').length) {
				const $forceWebp = $form.find('input[name="codir2me_force_webp"]');
				const $forceAvif = $form.find('input[name="codir2me_force_avif"]');
				const $reprocessThumbnails = $form.find('input[name="codir2me_reprocess_thumbnails"]');
				
				// Se miniaturas estiver marcada mas nenhuma conversão, mostrar aviso
				if ($reprocessThumbnails.is(':checked') && !$forceWebp.is(':checked') && !$forceAvif.is(':checked')) {
					e.preventDefault();
					codir2me_showReprocessingNotice(
						'error', 
						'Para reprocessar miniaturas, você deve selecionar pelo menos uma opção de conversão (WebP ou AVIF).'
					);
					
					// Focar no primeiro campo de conversão
					$forceWebp.focus();
					return false;
				}
			}
		});
		
		// Melhorar feedback visual dos toggle switches
		$('.codir2me-conversion-options .codir2me-toggle-switch').on('click', function() {
			const $toggle = $(this);
			
			// Adicionar classe de animação
			$toggle.addClass('codir2me-toggle-animating');
			
			setTimeout(function() {
				$toggle.removeClass('codir2me-toggle-animating');
			}, 300);
		});		
	}
});