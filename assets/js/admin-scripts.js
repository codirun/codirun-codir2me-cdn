/**
 * Scripts do painel administrativo do plugin R2 Static & Media CDN
 *
 * @param {Object} $ - jQuery object
 */

/* global jQuery, ajaxurl, codir2me_admin_vars, codir2me_i18n, codir2me_delete_vars, navigator, document, confirm, alert, console, window */

(function ($) {
	'use strict';

	// Função que obtém uma string traduzida priorizando codir2me_i18n,.
	// mas com fallback para codir2me_admin_vars.i18n.
	function getTranslation(key) {
		// Primeiro, tentar buscar de codir2me_i18n.
		if (typeof window.codir2me_i18n !== 'undefined' && window.codir2me_i18n[key]) {
			return window.codir2me_i18n[key];
		}

		// Segundo, tentar buscar de codir2me_admin_vars.i18n.
		if (
			typeof window.codir2me_admin_vars !== 'undefined' &&
			window.codir2me_admin_vars.i18n &&
			window.codir2me_admin_vars.i18n[key]
		) {
			return window.codir2me_admin_vars.i18n[key];
		}

		// Se não encontrou, retornar chave original ou mensagem padrão.
		/* eslint-disable no-console */
		console.warn('String "' + key + '" não encontrada nas traduções.');
		/* eslint-enable no-console */

		// Valores padrão para algumas strings importantes.
		const defaults = {
			apply_preset_values: 'Aplicar Valores do Nível Selecionado',
			apply_preset_description:
				'Isso aplicará os valores predefinidos do nível selecionado às configurações avançadas.',
			hide_advanced_settings: 'Esconder Configurações Avançadas',
			show_advanced_settings: 'Mostrar Configurações Avançadas',
			values_applied: 'Valores aplicados!',
			confirm_reset_stats:
				'Tem certeza que deseja redefinir todas as estatísticas de otimização? Esta ação não pode ser desfeita.',
			confirm_clear_logs:
				'Tem certeza que deseja limpar todos os logs? Esta ação não pode ser desfeita.',
			active: 'Ativo',
			inactive: 'Inativo',
			order_changed_notice:
				'Ordem alterada. Clique em "Salvar Prioridade de Formatos" para aplicar as mudanças.',
			auto_upload_thumbnails_enabled:
				'Upload automático de miniaturas ativado:',
			auto_upload_thumbnails_description:
				'As miniaturas selecionadas serão enviadas automaticamente para o R2 quando novas imagens forem adicionadas.',
			enable_cdn_first:
				'Ative o CDN de imagens primeiro para usar esta opção.',
			change_thumbnail_option:
				'Mude a opção "Tamanhos de Miniatura" para ativar esta função.',
		};

		return defaults[key] || key;
	}

	// Detector de dispositivos touch.
	window.isTouchDevice = function () {
		return (
			'ontouchstart' in window ||
			navigator.maxTouchPoints > 0 ||
			navigator.msMaxTouchPoints > 0
		);
	};

	// Adicionar classe ao body quando for dispositivo touch.
	$(document).ready(function () {
		if (window.isTouchDevice()) {
			$('body').addClass('touch-device');
		}
	});

	// Quando o documento estiver pronto.
	$(document).ready(function () {
		// ==================================================.
		// Funções para o Modo de Depuração.
		// ==================================================.

		// Manipulador para o modo de depuração.
		function handleDebugMode() {
			const isDebugEnabled = $('#codir2me-debug-mode').is(':checked');
			const logSettingsSection = $('.codir2me-log-settings');
			const debugStatus = $('.codir2me-debug-status');

			if (isDebugEnabled) {
				logSettingsSection.slideDown(300);
				debugStatus
					.text(getTranslation('active'))
					.removeClass('inactive')
					.addClass('active');
			} else {
				logSettingsSection.slideUp(300);
				debugStatus
					.text(getTranslation('inactive'))
					.removeClass('active')
					.addClass('inactive');
			}
		}

		// Inicializar o estado da seção de depuração.
		if ($('#codir2me-debug-mode').length) {
			handleDebugMode();

			// Atualizar quando o status do modo de depuração mudar.
			$('#codir2me-debug-mode').on('change', handleDebugMode);
		}

		// Confirmação antes de limpar logs.
		$('.codir2me-clear-log-btn').on('click', function (e) {
			// Usar a chave correta para a tradução.
			const confirmMessage = getTranslation('confirm_clear_logs');
			/* eslint-disable no-alert */
			if (!confirm(confirmMessage)) {
				e.preventDefault();
			}
			/* eslint-enable no-alert */
		});

		// Teste de conexão R2 com registro detalhado.
		$('#codir2me-test-connection-debug').on('click', function (e) {
			e.preventDefault();

			const $button = $(this);
			const $spinner = $button.next('.spinner');
			const $result = $('.codir2me-connection-test-result');

			// Mostrar spinner e desabilitar botão.
			$spinner.addClass('is-active');
			$button.prop('disabled', true);

			$.ajax({
				url: ajaxurl,
				type: 'POST',
				data: {
					action: 'codir2me_test_connection_debug',
					nonce: codir2me_admin_vars.nonce,
				},
				success(response) {
					if (response.success) {
						$result.html(
							'<div class="notice notice-success inline"><p>' +
								response.data.message +
								'</p></div>'
						);

						if (response.data.log_details) {
							$result.append(
								'<div class="codir2me-log-details"><h4>' +
									getTranslation('log_details') +
									'</h4><pre>' +
									response.data.log_details +
									'</pre></div>'
							);
						}
					} else {
						$result.html(
							'<div class="notice notice-error inline"><p>' +
								response.data.message +
								'</p></div>'
						);

						if (response.data.error_details) {
							$result.append(
								'<div class="codir2me-error-details"><h4>' +
									getTranslation('error_details') +
									'</h4><pre>' +
									response.data.error_details +
									'</pre></div>'
							);
						}
					}
				},
				error() {
					$result.html(
						'<div class="notice notice-error inline"><p>' +
							getTranslation('connection_error') +
							'</p></div>'
					);
				},
				complete() {
					$spinner.removeClass('is-active');
					$button.prop('disabled', false);
				},
			});
		});

		// Visualização de logs.
		if ($('.codir2me-log-preview').length) {
			// Rolar para o final do visualizador.
			const logViewer = document.querySelector('.codir2me-log-preview');
			if (logViewer) {
				logViewer.scrollTop = logViewer.scrollHeight;
			}

			// Carregar mais logs.
			$('.codir2me-load-more-log').on('click', function (e) {
				e.preventDefault();

				const $this = $(this);
				const $logPreview = $('.codir2me-log-preview');
				const $loadingIcon = $this.find('.dashicons-update');

				$loadingIcon.addClass('codir2me-rotating');

				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'codir2me_load_more_log',
						nonce: codir2me_admin_vars.nonce,
						offset: $this.data('offset'),
					},
					success(response) {
						if (response.success) {
							// Adicionar mais conteúdo do log.
							$logPreview.append(response.data.content);

							// Atualizar o offset para o próximo carregamento.
							$this.data('offset', response.data.new_offset);

							// Esconder o botão se não houver mais conteúdo.
							if (!response.data.has_more) {
								$this.hide();
							}

							// Rolar para o final do visualizador.
							logViewer.scrollTop = logViewer.scrollHeight;
						} else {
							/* eslint-disable no-alert */
							alert(
								getTranslation('load_more_logs_error_msg') +
									' ' +
									response.data.message
							);
							/* eslint-enable no-alert */
						}
					},
					error() {
						/* eslint-disable no-alert */
						alert(getTranslation('load_more_logs_error'));
						/* eslint-enable no-alert */
					},
					complete() {
						$loadingIcon.removeClass('codir2me-rotating');
					},
				});
			});
		}

		// Atualização automática de log.
		let refreshInterval;
		if (
			$('.codir2me-log-auto-refresh').length &&
			$('.codir2me-log-preview').length
		) {
			$('.codir2me-log-auto-refresh').on('change', function () {
				if ($(this).is(':checked')) {
					// Ativar atualização automática a cada 10 segundos.
					refreshInterval = setInterval(function () {
						refreshLogPreview();
					}, 10000);
				} else {
					// Desativar atualização automática.
					clearInterval(refreshInterval);
				}
			});

			function refreshLogPreview() {
				const $logPreview = $('.codir2me-log-preview');

				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'codir2me_refresh_log',
						nonce: codir2me_admin_vars.nonce,
					},
					success(response) {
						if (response.success) {
							// Atualizar o conteúdo do log.
							$logPreview.html(response.data.content);

							// Atualizar informações de tamanho.
							$('.codir2me-log-size').text(response.data.size);

							// Rolar para o final do visualizador.
							const logViewer = document.querySelector(
								'.codir2me-log-preview'
							);
							logViewer.scrollTop = logViewer.scrollHeight;
						}
					},
				});
			}
		}

		// ==================================================.
		// Funcionalidades do plugin existentes.
		// ==================================================.

		// Controlar exibição da seção de miniaturas.
		$('input[name="codir2me_thumbnail_option"]').on('change', function () {
			if ($(this).val() === 'selected') {
				$('#codir2me-thumbnail-sizes').slideDown(300);
			} else {
				$('#codir2me-thumbnail-sizes').slideUp(300);
			}
		});

		// Auto upload.
		if (
			$('.codir2me-upload-progress').length > 0 &&
			window.location.href.indexOf('auto_continue') > -1
		) {
			const progressBar = $('.codir2me-progress-inner');
			let currentWidth =
				(progressBar.width() / progressBar.parent().width()) * 100;

			// Atualizar a barra de progresso com animação até enviar o próximo lote.
			const interval = setInterval(function () {
				currentWidth += 0.5;
				if (currentWidth > 100) {
					clearInterval(interval);
				}
				progressBar.css('width', Math.min(currentWidth, 100) + '%');
			}, 100);

			// Enviar o próximo lote após 3 segundos.
			setTimeout(function () {
				$('.codir2me-continue-form button[type="submit"]').trigger(
					'click'
				);
			}, 3000);
		}

		// Selecionar/desselecionar todos os tamanhos de miniaturas.
		$('#codir2me-select-all-thumbnails').on('click', function (e) {
			e.preventDefault();
			$('.codir2me-thumbnail-size input[type="checkbox"]').prop(
				'checked',
				true
			);
		});

		$('#codir2me-deselect-all-thumbnails').on('click', function (e) {
			e.preventDefault();
			$('.codir2me-thumbnail-size input[type="checkbox"]').prop(
				'checked',
				false
			);
		});

		// Mostrar aviso quando o upload automático de miniaturas estiver ativado.
		$('input[name="codir2me_auto_upload_thumbnails"]').on(
			'change',
			function () {
				if ($(this).is(':checked')) {
					if ($('.codir2me-auto-upload-warning').length === 0) {
						$(
							'<div class="codir2me-auto-upload-warning" style="margin-top: 10px; padding: 10px; background-color: #f8f8f8; border-left: 4px solid #46b450;"><p><strong>' +
								getTranslation(
									'auto_upload_thumbnails_enabled'
								) +
								'</strong> ' +
								getTranslation(
									'auto_upload_thumbnails_description'
								) +
								'</p></div>'
						).insertAfter($(this).closest('td'));
					}
				} else {
					$('.codir2me-auto-upload-warning').fadeOut(
						300,
						function () {
							$(this).remove();
						}
					);
				}
			}
		);

		// Verificar dependências entre opções.
		function checkOptionDependencies() {
			// Se o CDN estiver desativado, desabilitar upload automático de miniaturas.
			if (
				!$('input[name="codir2me_is_images_cdn_active"]').is(':checked')
			) {
				$('input[name="codir2me_auto_upload_thumbnails"]').prop(
					'disabled',
					true
				);
				$('input[name="codir2me_auto_upload_thumbnails"]')
					.closest('tr')
					.addClass('disabled-option');

				if ($('.cdn-disabled-warning').length === 0) {
					$(
						'<p class="cdn-disabled-warning" style="color: #dc3232;"><small>' +
							getTranslation('enable_cdn_first') +
							'</small></p>'
					).insertAfter(
						$('input[name="codir2me_auto_upload_thumbnails"]').next(
							'.description'
						)
					);
				}
			} else {
				$('input[name="codir2me_auto_upload_thumbnails"]').prop(
					'disabled',
					false
				);
				$('input[name="codir2me_auto_upload_thumbnails"]')
					.closest('tr')
					.removeClass('disabled-option');
				$('.cdn-disabled-warning').remove();
			}

			// Desabilitar a seleção de miniaturas se a opção for "nenhuma" ou "todas".
			if (
				$('input[name="codir2me_thumbnail_option"]:checked').val() ===
				'none'
			) {
				$('input[name="codir2me_auto_upload_thumbnails"]').prop(
					'disabled',
					true
				);
				$('input[name="codir2me_auto_upload_thumbnails"]')
					.closest('tr')
					.addClass('disabled-option');

				if ($('.thumbnail-none-warning').length === 0) {
					$(
						'<p class="thumbnail-none-warning" style="color: #dc3232;"><small>' +
							getTranslation('change_thumbnail_option') +
							'</small></p>'
					).insertAfter(
						$('input[name="codir2me_auto_upload_thumbnails"]').next(
							'.description'
						)
					);
				}
			} else {
				$('.thumbnail-none-warning').remove();

				// Revalidar com a condição do CDN.
				if (
					$('input[name="codir2me_is_images_cdn_active"]').is(
						':checked'
					)
				) {
					$('input[name="codir2me_auto_upload_thumbnails"]').prop(
						'disabled',
						false
					);
					$('input[name="codir2me_auto_upload_thumbnails"]')
						.closest('tr')
						.removeClass('disabled-option');
				}
			}
		}

		// Verificar ao carregar a página.
		checkOptionDependencies();

		// Verificar quando o status do CDN mudar.
		$('input[name="codir2me_is_images_cdn_active"]').on(
			'change',
			function () {
				checkOptionDependencies();
			}
		);

		// Verificar quando a opção de miniaturas mudar.
		$('input[name="codir2me_thumbnail_option"]').on('change', function () {
			checkOptionDependencies();
		});

		// ==================================================.
		// Scripts para a aba de otimização.
		// ==================================================.
		if (window.location.href.indexOf('codirun-codir2me-cdn-optimization') > -1) {
			// Esconder configurações avançadas inicialmente.
			$('.codir2me-advanced-settings').hide();

			// Atualizar valores dos sliders.
			$('.codir2me-range-slider').on('input', function () {
				$(this).next('.codir2me-range-value').text($(this).val());
			});

			// Destacar nível de otimização selecionado sem alterar valores avançados.
			$('input[name="codir2me_optimization_level"]').change(function () {
				$('.codir2me-optimization-level').removeClass('selected');
				$(this)
					.closest('.codir2me-optimization-level')
					.addClass('selected');

				// NÃO chamar updateOptimizationSettings() aqui para preservar os valores personalizados.
			});

			// Mostrar/esconder configurações avançadas - evento de clique para o botão.
			$('#codir2me-toggle-advanced').on('click', function () {
				$('.codir2me-advanced-settings').toggle();

				if ($('.codir2me-advanced-settings:first').is(':visible')) {
					$(this).text(getTranslation('hide_advanced_settings'));
				} else {
					$(this).text(getTranslation('show_advanced_settings'));
				}
			});

			// SOLUÇÃO: Diretamente remover o atributo disabled do botão AVIF quando a otimização for ativada.
			$('input[name="codir2me_enable_optimization"]').change(function () {
				const isEnabled = $(this).is(':checked');

				if (isEnabled) {
					// Remover explicitamente o atributo disabled do botão AVIF.
					$('input[name="codir2me_enable_avif"]').prop(
						'disabled',
						false
					);
					// Remover classe visual de desabilitado.
					$('input[name="codir2me_enable_avif"]')
						.closest('.codir2me-format-option')
						.removeClass('disabled-option');

					// Habilitar outros botões relacionados.
					$('input[name="codir2me_enable_webp"]').prop(
						'disabled',
						false
					);
					$('input[name="codir2me_keep_original"]').prop(
						'disabled',
						false
					);
					$('input[name="codir2me_optimization_level"]').prop(
						'disabled',
						false
					);

					// Remover a classe disabled-option de todos os elementos relevantes.
					$(
						'.codir2me-next-gen-formats, .codir2me-optimization-level'
					).removeClass('disabled-option');
				} else {
					// Desabilitar todos os controles quando a otimização estiver desativada.
					$('input[name="codir2me_enable_webp"]').prop(
						'disabled',
						true
					);
					$('input[name="codir2me_enable_avif"]').prop(
						'disabled',
						true
					);
					$('input[name="codir2me_keep_original"]').prop(
						'disabled',
						true
					);
					$('input[name="codir2me_optimization_level"]').prop(
						'disabled',
						true
					);

					// Adicionar a classe disabled-option.
					$(
						'.codir2me-next-gen-formats, .codir2me-optimization-level'
					).addClass('disabled-option');
				}

				// Atualizar estados dos sliders baseados no estado de otimização e WebP/AVIF.
				updateSliderStates();
			});

			// Função para atualizar o estado dos sliders.
			function updateSliderStates() {
				const optimizationEnabled = $(
					'input[name="codir2me_enable_optimization"]'
				).is(':checked');
				const webpEnabled = $('input[name="codir2me_enable_webp"]').is(
					':checked'
				);
				const avifEnabled = $('input[name="codir2me_enable_avif"]').is(
					':checked'
				);

				$('#codir2me-webp-quality').prop(
					'disabled',
					!optimizationEnabled || !webpEnabled
				);
				$('#codir2me-avif-quality').prop(
					'disabled',
					!optimizationEnabled || !avifEnabled
				);
				$('#codir2me-jpeg-quality').prop(
					'disabled',
					!optimizationEnabled
				);
				$('#codir2me-png-compression').prop(
					'disabled',
					!optimizationEnabled
				);
			}

			// Atualizar estados dos sliders quando WebP e AVIF mudarem.
			$('input[name="codir2me_enable_webp"]').change(updateSliderStates);
			$('input[name="codir2me_enable_avif"]').change(updateSliderStates);

			// Garantir que os estados iniciais sejam aplicados corretamente.
			// Solução para o problema: forçar a habilitação do botão AVIF se a otimização estiver ativada.
			if (
				$('input[name="codir2me_enable_optimization"]').is(':checked')
			) {
				$('input[name="codir2me_enable_avif"]').prop('disabled', false);
				$('input[name="codir2me_enable_avif"]')
					.closest('.codir2me-format-option')
					.removeClass('disabled-option');
			}

			// Atualizar os sliders na inicialização.
			updateSliderStates();

			// Adicionar botão para aplicar presets de configuração.
			if (!$('.codir2me-preset-controls').length) {
				$('.codir2me-optimization-levels').after(
					'<div class="codir2me-preset-controls" style="margin-top: 15px;">' +
						'<button type="button" class="button button-secondary codir2me-apply-preset">' +
						getTranslation('apply_preset_values') +
						'</button>' + // Use a função getTranslation.
						'<p class="description">' +
						getTranslation('apply_preset_description') +
						'</p>' +
						'</div>'
				);

				// Adicionar handler para o botão.
				$('.codir2me-apply-preset').on('click', function () {
					const level = $(
						'input[name="codir2me_optimization_level"]:checked'
					).val();
					if (level) {
						updateOptimizationSettings(level);

						// Mostrar feedback visual.
						const $button = $(this);
						const originalText = $button.text();
						$button
							.text(getTranslation('values_applied'))
							.addClass('button-primary');

						setTimeout(function () {
							$button
								.text(originalText)
								.removeClass('button-primary');
						}, 1500);

						// Mostrar as configurações avançadas automaticamente.
						if (
							!$('.codir2me-advanced-settings:first').is(
								':visible'
							)
						) {
							$('#codir2me-toggle-advanced').trigger('click');
						}
					}
				});
			}

			// Confirmar redefinição de estatísticas.
			$('#codir2meResetStatsForm').submit(function (e) {
				/* eslint-disable no-alert */
				if (!confirm(getTranslation('confirm_reset_stats'))) {
					e.preventDefault();
				}
				/* eslint-enable no-alert */
			});

			// Função para atualizar as configurações avançadas com base no nível selecionado.
			// Essa função agora só é chamada pelo botão "Aplicar valores", não automaticamente.
			function updateOptimizationSettings(level) {
				const settings = {
					light: {
						jpeg_quality: 90,
						png_compression: 5,
						webp_quality: 85,
						avif_quality: 80,
					},
					balanced: {
						jpeg_quality: 85,
						png_compression: 7,
						webp_quality: 80,
						avif_quality: 75,
					},
					aggressive: {
						jpeg_quality: 65,
						png_compression: 9,
						webp_quality: 70,
						avif_quality: 60,
					},
				};

				if (settings[level]) {
					$('#codir2me-jpeg-quality')
						.val(settings[level].jpeg_quality)
						.trigger('input');
					$('#codir2me-png-compression')
						.val(settings[level].png_compression)
						.trigger('input');
					$('#codir2me-webp-quality')
						.val(settings[level].webp_quality)
						.trigger('input');
					$('#codir2me-avif-quality')
						.val(settings[level].avif_quality)
						.trigger('input');
				}
			}
		}

		// ==================================================.
		// Scripts para a aba de exclusão.
		// ==================================================.
		if (
			window.location.href.indexOf('tab=delete') > -1 &&
			typeof window.codir2me_delete_vars !== 'undefined'
		) {
			// Função para verificar se algum checkbox está marcado.
			function checkIfAnyChecked() {
				let anyChecked = false;
				$(
					'input[name="codir2me_delete_static"], input[name="codir2me_delete_all_images"], input[name="codir2me_delete_original_images"], input[name="codir2me_delete_all_thumbnails"]'
				).each(function () {
					if ($(this).is(':checked')) {
						anyChecked = true;
						return false; // Break the loop.
					}
				});

				$('#codir2meDeleteButton').prop('disabled', !anyChecked);
			}

			// Desabilitar inicialmente o botão de exclusão.
			$('#codir2meDeleteButton').prop('disabled', true);

			// Verificar quando qualquer checkbox é alterado.
			$('input[type="checkbox"]').on('change', function () {
				checkIfAnyChecked();

				// Se "Excluir todas as imagens" for marcado, desabilitar outros checkboxes de imagens.
				if ($(this).attr('id') === 'deleteAllImages') {
					if ($(this).is(':checked')) {
						$('.image-group-checkbox').prop('disabled', true);
						$('.image-options').addClass('disabled-option');
					} else {
						$('.image-group-checkbox').prop('disabled', false);
						$('.image-options').removeClass('disabled-option');
					}
				}
			});

			// Adicionar confirmação ao formulário de exclusão.
			$('#codir2meDeleteForm').on('submit', function (e) {
				let confirmMsg =
					'Tem certeza que deseja excluir os itens selecionados?';
				if (
					typeof window.codir2me_delete_vars !== 'undefined' &&
					window.codir2me_delete_vars.i18n &&
					window.codir2me_delete_vars.i18n.confirm_delete_batch
				) {
					confirmMsg = window.codir2me_delete_vars.i18n.confirm_delete_batch;
				}

				/* eslint-disable no-alert */
				if (!confirm(confirmMsg)) {
					e.preventDefault();
				}
				/* eslint-enable no-alert */
			});

			// Auto-continuar o processo de exclusão.
			if (
				$('.codir2me-continue-form').length > 0 &&
				window.location.href.indexOf('auto_continue') > -1 &&
				window.location.href.indexOf('manual_continue') === -1
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

		// Melhor suporte para drag & drop em dispositivos móveis.
		if ($.fn.sortable && $('.codir2me-formats-container').length) {
			// Configuração aprimorada para dispositivos móveis.
			$('.codir2me-formats-container').sortable({
				items: '.codir2me-format-option',
				handle: '.codir2me-format-drag-handle',
				axis: 'y',
				cursor: 'move',
				forcePlaceholderSize: true,
				placeholder: 'ui-sortable-placeholder',
				tolerance: 'pointer', // Melhor para dispositivos touch.

				// Eventos touch aprimorados.
				start(event, ui) {
					// Destacar item que está sendo arrastado.
					ui.item.addClass('ui-being-dragged');
					// Ajustar altura do placeholder para corresponder ao item.
					ui.placeholder.height(ui.item.height());
				},

				stop(event, ui) {
					ui.item.removeClass('ui-being-dragged');
				},

				update(event, ui) {
					// Atualizar a ordem dos formatos após o drag & drop.
					updateFormatOrder();
				},
			});

			// Adicionar feedback visual ao tocar.
			$('.codir2me-format-drag-handle')
				.on('touchstart', function () {
					$(this)
						.closest('.codir2me-format-option')
						.addClass('touch-active');
				})
				.on('touchend', function () {
					$(this)
						.closest('.codir2me-format-option')
						.removeClass('touch-active');
				});

			// Função para atualizar a ordem dos formatos após drag & drop.
			function updateFormatOrder() {
				// Atualizar os valores ocultos de ordem.
				$('.codir2me-format-option').each(function (index) {
					// Armazenar o formato.
					const format = $(this)
						.find('input[name="codir2me_format_order[]"]')
						.val();

					// Atualizar o campo hidden para refletir a nova ordem.
					$(this)
						.find('input[name="codir2me_format_order[]"]')
						.val(format);

					// Exibir feedback visual da nova posição.
					$(this)
						.find('.codir2me-format-position')
						.text(index + 1);
				});

				// Adicionar indicação visual de mudança salva.
				$('.codir2me-format-priority').addClass('order-changed');
				$(
					'<div class="format-order-notice">' +
						getTranslation('order_changed_notice') +
						'</div>'
				)
					.insertAfter('.codir2me-formats-container')
					.fadeIn(300)
					.delay(5000)
					.fadeOut(300, function () {
						$(this).remove();
					});
			}
		}

		// Efeito de rotação para ícones de carregamento.
		$('<style>')
			.text(
				'@keyframes codir2me-rotate { from { transform: rotate(0deg); } to { transform: rotate(360deg); } } .codir2me-rotating { animation: codir2me-rotate 2s linear infinite; }'
			)
			.appendTo('head');
	});
})(jQuery);