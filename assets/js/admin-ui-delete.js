/**
 * Scripts para a aba de exclusão do plugin R2 CDN
 * Versão de produção otimizada para repositório WordPress
 *
 * @param $
 * @package
 * @since 1.0.0
 */

(function ($) {
	'use strict';

	$(document).ready(function () {
		// Inicializar componentes da interface.
		initializeFormValidation();
		initializeAutoDeleteSettings();
		initializeQuickDelete();
		initializeBackgroundDeletion();
		initializeManualContinuation();
	});

	/**
	 * Inicializa a validação dos formulários
	 *
	 * @since 1.0.0
	 */
	function initializeFormValidation() {
		// Confirmação do formulário de exclusão rápida.
		$('#codir2meQuickDeleteForm').on('submit', function (e) {
			if (!$('#confirm-quick-delete').is(':checked')) {
				e.preventDefault();
				alert(codir2me_delete_vars.i18n.confirm_checkbox_required);
				return false;
			}

			if (!confirm(codir2me_delete_vars.i18n.confirm_delete_warning)) {
				e.preventDefault();
				return false;
			}

			return true;
		});

		// Validação de checkboxes para exclusão manual.
		initializeCheckboxValidation();
	}

	/**
	 * Inicializa a validação de checkboxes
	 *
	 * @since 1.0.0
	 */
	function initializeCheckboxValidation() {
		const deleteButton = $('#codir2meDeleteButton');
		const checkboxes =
			'input[name="codir2me_delete_static"], input[name="codir2me_delete_all_images"], input[name="codir2me_delete_original_images"], input[name="codir2me_delete_all_thumbnails"]';

		// Desabilitar botão inicialmente.
		deleteButton.prop('disabled', true);

		// Verificar se algum checkbox está marcado.
		function checkIfAnyChecked() {
			let anyChecked = false;
			$(checkboxes).each(function () {
				if ($(this).is(':checked')) {
					anyChecked = true;
					return false;
				}
			});
			deleteButton.prop('disabled', !anyChecked);
		}

		// Adicionar evento de mudança.
		$('input[type="checkbox"]').on('change', function () {
			checkIfAnyChecked();

			// Lógica para "Excluir todas as imagens".
			if ('deleteAllImages' === $(this).attr('id')) {
				if ($(this).is(':checked')) {
					$('.image-group-checkbox').prop('disabled', true);
					$('.image-options').addClass('disabled-option');
				} else {
					$('.image-group-checkbox').prop('disabled', false);
					$('.image-options').removeClass('disabled-option');
				}
			}
		});
	}

	/**
	 * Inicializa as configurações de exclusão automática
	 *
	 * @since 1.0.0
	 */
	function initializeAutoDeleteSettings() {
		const autoDeleteEnabled = $(
			'input[name="codir2me_auto_delete_enabled"]'
		);
		const thumbnailOptions = $(
			'input[name="codir2me_auto_delete_thumbnail_option"]'
		);
		const thumbnailSizes = $('#codir2me-auto-delete-thumbnail-sizes');
		const selectAllBtn = $('#codir2me-auto-select-all-thumbnails');
		const deselectAllBtn = $('#codir2me-auto-deselect-all-thumbnails');

		// Atualizar estado das opções.
		function updateThumbnailOptionsState() {
			const isEnabled = autoDeleteEnabled.is(':checked');

			thumbnailOptions.prop('disabled', !isEnabled);

			if (!isEnabled) {
				thumbnailOptions
					.closest('fieldset')
					.addClass('disabled-fieldset');
				thumbnailSizes.slideUp(300);
				selectAllBtn.add(deselectAllBtn).prop('disabled', true);
				thumbnailSizes
					.find('input[type="checkbox"]')
					.prop('disabled', true);
			} else {
				thumbnailOptions
					.closest('fieldset')
					.removeClass('disabled-fieldset');
				selectAllBtn.add(deselectAllBtn).prop('disabled', false);
				thumbnailSizes
					.find('input[type="checkbox"]')
					.prop('disabled', false);

				if ('selected' === thumbnailOptions.filter(':checked').val()) {
					thumbnailSizes.slideDown(300);
				}
			}
		}

		// Executar inicialmente.
		updateThumbnailOptionsState();

		// Eventos.
		autoDeleteEnabled.on('change', updateThumbnailOptionsState);

		thumbnailOptions.on('change', function () {
			if (
				'selected' === $(this).val() &&
				autoDeleteEnabled.is(':checked')
			) {
				thumbnailSizes.slideDown(300);
			} else {
				thumbnailSizes.slideUp(300);
			}
		});

		// Botões de seleção.
		selectAllBtn.on('click', function (e) {
			e.preventDefault();
			thumbnailSizes
				.find('input[type="checkbox"]:not(:disabled)')
				.prop('checked', true);
		});

		deselectAllBtn.on('click', function (e) {
			e.preventDefault();
			thumbnailSizes
				.find('input[type="checkbox"]:not(:disabled)')
				.prop('checked', false);
		});
	}

	/**
	 * Inicializa o processo de exclusão rápida
	 *
	 * @since 1.0.0
	 */
	function initializeQuickDelete() {
		if ($('.codir2me-quick-delete-progress').length > 0) {
			setTimeout(processQuickDeleteBatch, 1000);
		}
	}

	/**
	 * Processa lotes de exclusão rápida
	 *
	 * @since 1.0.0
	 */
	function processQuickDeleteBatch() {
		// Primeiro verificar o status do bucket.
		$.ajax({
			url: codir2me_delete_vars.ajax_url,
			type: 'POST',
			dataType: 'json',
			timeout: 30000,
			data: {
				action: 'codir2me_check_bucket_status',
				nonce: codir2me_delete_vars.quick_delete_nonce,
			},
			success(response) {
				if (response.success && response.data) {
					if (response.data.bucket_empty) {
						// Bucket vazio.
						updateQuickDeleteStatus(
							'Bucket está vazio - nada para excluir',
							0,
							100
						);
						setTimeout(function () {
							window.location.href =
								codir2me_delete_vars.redirect_url + '0';
						}, 2000);
						return;
					}

					// Há arquivos - iniciar exclusão.
					updateQuickDeleteStatus('Iniciando exclusão...', 0, 0);
					setTimeout(startActualDeletion, 1000);
				} else {
					handleQuickDeleteError(
						'Erro ao verificar bucket: ' +
							(response.data
								? response.data.message
								: 'Erro desconhecido')
					);
				}
			},
			error(jqXHR) {
				console.warn(
					'Erro na verificação do bucket - tentando exclusão direta:',
					jqXHR.status
				);
				updateQuickDeleteStatus(
					'Erro na verificação - tentando exclusão...',
					0,
					0
				);
				setTimeout(startActualDeletion, 2000);
			},
		});
	}

	/**
	 * Corrigir também a função startActualDeletion para melhorar o tratamento de erros:
	 */
	function startActualDeletion() {
		$.ajax({
			url: codir2me_delete_vars.ajax_url,
			type: 'POST',
			dataType: 'json',
			timeout: 60000,
			data: {
				action: 'codir2me_process_quick_delete_batch',
				nonce: codir2me_delete_vars.quick_delete_nonce,
			},
			success(response) {
				if (response.success && response.data) {
					const data = response.data;

					updateQuickDeleteStatus(
						data.status || 'Processando...',
						data.deleted_count || 0,
						data.progress || 0
					);

					if (data.complete) {
						setTimeout(function () {
							// Criar URL de redirecionamento correta.
							const redirectUrl = codir2me_delete_vars.redirect_url + (data.deleted_count || 0);
							window.location.href = redirectUrl;
						}, 2000);
					} else {
						setTimeout(startActualDeletion, 2000);
					}
				} else {
					const errorMessage = 'Erro na exclusão: ' + 
						(response.data && response.data.message ? response.data.message : 'Erro desconhecido');
					handleQuickDeleteError(errorMessage);
				}
			},
			error(jqXHR, textStatus) {
				console.error('AJAX Error Details:', {
					status: jqXHR.status,
					statusText: jqXHR.statusText,
					textStatus: textStatus,
					responseText: jqXHR.responseText
				});
				
				let errorMsg = 'Erro de comunicação com o servidor';
				
				if (jqXHR.status === 200 && textStatus === 'parsererror') {
					errorMsg = 'Erro de formato na resposta do servidor (parsererror)';
				} else if (jqXHR.status === 0) {
					errorMsg = 'Falha de conectividade';
				} else if (jqXHR.status >= 500) {
					errorMsg = 'Erro interno do servidor (' + jqXHR.status + ')';
				} else if (jqXHR.status >= 400) {
					errorMsg = 'Erro de requisição (' + jqXHR.status + ')';
				}
				
				handleQuickDeleteError(errorMsg);
			},
		});
	}

	/**
	 * Atualiza o status da exclusão rápida
	 *
	 * @since 1.0.0
	 * @param {string} status   Mensagem de status
	 * @param {number} count    Número de arquivos processados
	 * @param {number} progress Progresso em porcentagem
	 */
	function updateQuickDeleteStatus(status, count, progress) {
		$('#codir2me-deleted-count').text(count);
		$('#codir2me-quick-delete-progress').css('width', progress + '%');
		$('#codir2me-quick-delete-status').text(status);
	}

	/**
	 * Trata erros da exclusão rápida
	 *
	 * @since 1.0.0
	 * @param {string} message Mensagem de erro
	 */
	function handleQuickDeleteError(message) {
		console.error('Erro na exclusão rápida:', message);
		updateQuickDeleteStatus('Erro: ' + message, 0, 0);

		setTimeout(function () {
			// Corrigir o redirecionamento para não usar concatenação direta.
			const errorUrl = codir2me_delete_vars.page_url + '&quick_delete_error=' + encodeURIComponent(message);
			window.location.href = errorUrl;
		}, 3000);
	}

	/**
	 * Inicializa o monitoramento de exclusão em segundo plano
	 *
	 * @since 1.0.0
	 */
	function initializeBackgroundDeletion() {
		if (
			$('.codir2me-upload-progress').length > 0 &&
			$('.status-indicator.running').length > 0
		) {
			checkBackgroundDeletionStatus();
		}
	}

	/**
	 * Verifica o status da exclusão em segundo plano
	 *
	 * @since 1.0.0
	 */
	function checkBackgroundDeletionStatus() {
		$.ajax({
			url: codir2me_delete_vars.ajax_url,
			type: 'POST',
			data: {
				action: 'codir2me_check_background_deletion_status',
				nonce: codir2me_delete_vars.background_deletion_nonce,
			},
			success(response) {
				if (response.success && response.data) {
					updateBackgroundDeletionUI(response.data);

					// CORREÇÃO SIMPLES: Detectar se terminou e redirecionar.
					if (!response.data.in_progress || response.data.completed) {
						handleBackgroundDeletionComplete();
					} else {
						setTimeout(checkBackgroundDeletionStatus, 5000);
					}
				} else {
					setTimeout(checkBackgroundDeletionStatus, 10000);
				}
			},
			error() {
				setTimeout(checkBackgroundDeletionStatus, 10000);
			},
		});
	}

	/**
	 * Atualiza a interface da exclusão em segundo plano
	 *
	 * @since 1.0.0
	 * @param {Object} data Dados do status
	 */
	function updateBackgroundDeletionUI(data) {
		// Atualizar contadores.
		$('.codir2me-progress-value:contains("de")').each(function () {
			$(this).text(data.processed_items + ' de ' + data.total_items);
		});

		// Atualizar barra de progresso.
		const progressPercent =
			data.total_items > 0
				? (data.processed_items / data.total_items) * 100
				: 0;
		$('.codir2me-progress-inner').css('width', progressPercent + '%');

		// Atualizar status.
		const statusIndicator = $('.status-indicator');
		if (data.paused) {
			statusIndicator
				.removeClass('running')
				.addClass('paused')
				.text(codir2me_delete_vars.i18n.status_paused);
		} else {
			statusIndicator
				.removeClass('paused')
				.addClass('running')
				.text(codir2me_delete_vars.i18n.status_running);
		}
	}

	/**
	 * Trata a conclusão da exclusão em segundo plano - VERSÃO SIMPLES
	 *
	 * @since 1.0.0
	 */
	function handleBackgroundDeletionComplete() {
		// Mostrar mensagem de sucesso por 2 segundos.
		$('.codir2me-progress-info').html(
			'<span class="dashicons dashicons-yes"></span> ' +
			'Processo concluído! Redirecionando...'
		);

		$('.codir2me-progress-inner').css('width', '100%');

		// CORREÇÃO SIMPLES: Redirecionar para limpar a tela.
		setTimeout(function () {
			// Remove todos os parâmetros de progresso da URL.
			const cleanUrl = window.location.pathname + 
				window.location.search
					.replace(/[&?]delete_[^&]*/g, '')
					.replace(/[&?]auto_continue[^&]*/g, '')
					.replace(/^\?$/, '')
					.replace(/^&/, '?');
			
			window.location.href = cleanUrl || window.location.pathname;
		}, 2000);
	}

	/**
	 * Inicializa a continuação manual de processos
	 *
	 * @since 1.0.0
	 */
	function initializeManualContinuation() {
		if (
			codir2me_delete_vars.auto_continue &&
			$('form.codir2me-continue-form').length > 0
		) {
			setTimeout(function () {
				$(
					'form.codir2me-continue-form button[name="process_delete_batch"]'
				)
					.closest('form')
					.submit();
			}, 3000);
		}
	}

	/**
	 * Obtém mensagem de erro AJAX padronizada
	 *
	 * @since 1.0.0
	 * @param {Object} jqXHR      Objeto XMLHttpRequest
	 * @param {string} textStatus Status do texto
	 * @return {string} Mensagem de erro
	 */
	function getAjaxErrorMessage(jqXHR, textStatus) {
		if (500 === jqXHR.status) {
			return 'Erro interno do servidor (500). Verifique os logs do servidor.';
		} else if (400 === jqXHR.status) {
			return 'Erro de configuração (400). Verifique suas credenciais R2.';
		} else if (403 === jqXHR.status) {
			return 'Acesso negado (403). Verifique as permissões.';
		} else if (0 === jqXHR.status) {
			return 'Sem conexão com o servidor.';
		}
		return textStatus + ' (' + jqXHR.status + ')';
	}
})(jQuery);
