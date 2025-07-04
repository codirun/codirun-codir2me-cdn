/**
 * Scripts específicos para o escaneamento do bucket R2 com progresso em tempo real
 *
 * @param {Object} $ - jQuery object
 */

/* global jQuery, codir2me_scanner_vars, alert, confirm, window, URLSearchParams, Object, console */

(function ($) {
	'use strict';

	// Variáveis para armazenar o token de continuação e outros dados do escaneamento.
	let scanData = {
		continuationToken: null,
		isTruncated: false,
		totalFiles: 0,
		totalScanned: 0,
		pagesProcessed: 0,
		scanComplete: false,
		uniqueFiles: {},
		results: {
			files: [],
			total_size: 0,
			total_count: 0,
			static_files: {
				count: 0,
				size: 0,
				extensions: {},
			},
			images: {
				count: 0,
				size: 0,
				extensions: {},
				thumbnails: {
					count: 0,
					size: 0,
				},
				originals: {
					count: 0,
					size: 0,
				},
			},
		},
	};

	// Carregar textos traduzíveis dos valores localizados.
	const texts = codir2me_scanner_vars.texts || {};

	$(document).ready(function () {
		// Iniciar escaneamento se o método AJAX estiver selecionado e o formulário for enviado.
		$('#codir2meScanForm').on('submit', function (e) {
			if (
				$('input[name="codir2me_scan_method"]:checked').val() === 'ajax'
			) {
				e.preventDefault();
				startAjaxScan();
			}
		});

		// Selecionar todos os arquivos.
		$(document).on('click', '#codir2me-select-all', function () {
			$('.codir2me-file-checkbox input[type="checkbox"]:visible').prop(
				'checked',
				true
			);
		});

		// Desmarcar todos os arquivos.
		$(document).on('click', '#codir2me-deselect-all', function () {
			$('.codir2me-file-checkbox input[type="checkbox"]').prop(
				'checked',
				false
			);
		});

		// Filtrar arquivos.
		$(document).on('input', '#codir2me-filter-files', function () {
			const filter = $(this).val().toLowerCase();

			$('.codir2me-file-item').each(function () {
				const $item = $(this);
				if ($item.hasClass('codir2me-load-more-container')) {
					return; // Ignorar o botão "carregar mais".
				}

				const name = $item.data('name').toLowerCase();
				const ext = $item.data('ext').toLowerCase();

				if (name.indexOf(filter) > -1 || ext.indexOf(filter) > -1) {
					$item.show();
				} else {
					$item.hide();
				}
			});
		});

		// Carregar mais itens com layout e dados consistentes.
		$(document).on('click', '#codir2me-load-more-files', function () {
			const $button = $(this);
			const $loadMoreContainer = $('.codir2me-load-more-container');

			// Obter o scan_id da URL.
			const urlParams = new URLSearchParams(window.location.search);
			const scanId = urlParams.get('scan_id');
			
			// Armazenar o texto original do botão
			const originalText = $button.text();

			// Esconder o botão e mostrar indicador de carregamento.
			$button
				.prop('disabled', true)
				.html(
					'<span class="dashicons dashicons-update codir2me-rotating"></span> ' +
						(texts.loading || 'Carregando') +
						'...'
				);

			// Encontrar o número atual de itens exibidos.
			const currentItems = $('.codir2me-file-item').not(
				'.codir2me-load-more-container'
			).length;

			// Fazer requisição AJAX para carregar mais itens.
			$.ajax({
				url: codir2me_scanner_vars.ajax_url,
				type: 'POST',
				data: {
					action: 'codir2me_load_more_scan_files',
					nonce: codir2me_scanner_vars.scan_process_nonce,
					scan_id: scanId,
					offset: currentItems,
					limit: 300,
				},
				success(response) {
					if (response.success && response.data) {
						const files = response.data.files || [];
						const remaining = response.data.remaining || 0;

						// CORREÇÃO: Criar HTML com layout idêntico ao PHP.
						let html = '';
						for (let i = 0; i < files.length; i++) {
							const file = files[i];
							const fullKey = file.key || '';
							const fileName = basename(fullKey);
							const ext = escapeHtml(file.extension || '');
							const size = file.size || 0;
							const sizeKb = size > 0 ? Math.round(size / 1024 * 100) / 100 : 0;
							const sizeDisplay = sizeKb > 1024 ? Math.round(sizeKb / 1024 * 100) / 100 + ' MB' : sizeKb + ' KB';
							const lastModified = file.last_modified || '';
							const dateDisplay = formatDate(lastModified);
							
							// Gerar ID único para o checkbox.
							const uniqueId = 'file_' + i + '_' + Date.now();

							html += `
								<div class="codir2me-file-item" data-name="${escapeHtml(fileName)}" data-ext="${ext}">
									<label class="codir2me-file-checkbox">
										<input type="checkbox" name="codir2me_files_to_import[]" value="${escapeHtml(fullKey)}" id="${uniqueId}">
										<span class="dashicons dashicons-format-image"></span>
									</label>
									<div class="codir2me-file-details">
										<div class="codir2me-file-name" title="${escapeHtml(fileName)}">${escapeHtml(fileName)}</div>
										<div class="codir2me-file-meta">
											<span class="codir2me-file-type" style="font-weight: bold;">${ext.toUpperCase()}</span>
											<span class="codir2me-file-size">${sizeDisplay}</span>
											${dateDisplay ? `<span class="codir2me-file-date">${dateDisplay}</span>` : ''}
										</div>
									</div>
								</div>
							`;
						}

						// Inserir novos arquivos antes do botão "carregar mais".
						$(html).insertBefore($loadMoreContainer);

						// Atualizar texto do botão ou remover se não houver mais itens.
						if (remaining > 0) {
							// Verificar se texts.load_more_items existe antes de usar replace.
							let loadMoreText = texts.load_more_items || 'Carregar mais %d itens';
							if (typeof loadMoreText === 'string' && loadMoreText.includes('%d')) {
								loadMoreText = loadMoreText.replace('%d', remaining);
							} else {
								loadMoreText = 'Carregar mais ' + remaining + ' itens';
							}
							
							$button
								.html(loadMoreText)
								.prop('disabled', false);
						} else {
							$loadMoreContainer.remove();
						}

						// Reaplicar o filtro atual, se houver.
						$('#codir2me-filter-files').trigger('input');
					} else {
						// Melhor tratamento de erro.
						let errorMessage = texts.error_loading_files || 'Erro ao carregar mais arquivos';
						
						if (response && response.data && response.data.message) {
							errorMessage = response.data.message;
						}

						$button.html(errorMessage).prop('disabled', true);
						
						setTimeout(function() {
							const defaultText = texts.load_more_items || originalText || 'Carregar mais itens';
							$button.html(defaultText).prop('disabled', false);
						}, 3000);
					}
				},
				error(xhr, textStatus, errorThrown) {
					console.error('Erro AJAX carregar mais:', {
						status: xhr.status,
						statusText: xhr.statusText,
						textStatus: textStatus,
						errorThrown: errorThrown
					});

					const errorMessage = texts.connection_error || 'Erro de conexão';
					$button.html(errorMessage).prop('disabled', true);
					
					setTimeout(function() {
						$button.html(texts.load_more_items || 'Carregar mais itens').prop('disabled', false);
					}, 3000);
				}
			});
		});

		// FUNÇÕES AUXILIARES ADICIONAIS:.
		// Função basename para extrair apenas o nome do arquivo.
		function basename(path) {
			if (typeof path !== 'string') {
				return '';
			}
			return path.split('/').pop();
		}

		// Função escapeHtml já existente mas vou reforçar.
		function escapeHtml(str) {
			if (typeof str !== 'string') {
				return '';
			}
			const map = {
				'&': '&amp;',
				'<': '&lt;',
				'>': '&gt;',
				'"': '&quot;',
				"'": '&#039;'
			};
			return str.replace(/[&<>"']/g, function(m) { return map[m]; });
		}

		// Verificar seleção antes de enviar formulário de importação.
		$(document).on('submit', '#codir2me-import-form', function (e) {
			const selectedCount = $(
				'input[name="codir2me_files_to_import[]"]:checked'
			).length;

			if (selectedCount === 0) {
				e.preventDefault();
				alert(texts.no_files_selected || 'Nenhum arquivo selecionado');
				return false;
			}

			if (selectedCount > 50) {
				if (
					!confirm(
						(texts.many_files_selected || 'Você selecionou') +
							' ' +
							selectedCount +
							' ' +
							(texts.many_files_warning || 'arquivos. Continuar?')
					)
				) {
					e.preventDefault();
					return false;
				}
			}

			return true;
		});

		// Verificar se há uma notificação de erro ou sucesso na URL.
		const urlParams = new URLSearchParams(window.location.search);
		if (urlParams.has('error')) {
			let errorMsg = decodeURIComponent(urlParams.get('error'));
			if (errorMsg === 'no_files') {
				errorMsg = texts.no_files_selected || 'Nenhum arquivo selecionado';
			}
			$(
				'<div class="notice notice-error is-dismissible"><p>' +
					errorMsg +
					'</p></div>'
			).insertBefore('.codir2me-section:first');
		}

		if (urlParams.has('import') && urlParams.get('import') === 'success') {
			// Buscar os resultados da importação salvos temporariamente.
			$.ajax({
				url: codir2me_scanner_vars.ajax_url,
				type: 'POST',
				data: {
					action: 'codir2me_get_import_results',
					nonce: codir2me_scanner_vars.import_results_nonce,
				},
				success(response) {
					if (response.success && response.data) {
						const successCount = response.data.success_count || 0;
						const failedCount = response.data.failed_count || 0;

						// Exibir mensagem de sucesso.
						const successNotice = $(
							'<div class="notice notice-success is-dismissible"><p>' +
								(texts.import_complete || 'Importação concluída:') +
								' ' +
								successCount +
								' ' +
								(texts.files_imported || 'arquivos importados') +
								'</p></div>'
						);

						if (failedCount > 0) {
							successNotice.append(
								'<p>' +
									failedCount +
									' ' +
									(texts.files_not_imported || 'arquivos não puderam ser importados') +
									'</p>'
							);
						}

						successNotice.insertBefore('.codir2me-section:first');
					} else {
						let errorMessage = 'Erro ao obter resultados da importação';
						
						if (response && response.data) {
							if (typeof response.data === 'string') {
								errorMessage = response.data;
							} else if (typeof response.data === 'object' && response.data.message) {
								errorMessage = response.data.message;
							}
						}

						$(
							'<div class="notice notice-error is-dismissible"><p>' +
								errorMessage +
								'</p></div>'
						).insertBefore('.codir2me-section:first');
					}
				},
				error(xhr, textStatus, errorThrown) {
					console.error('Erro ao obter resultados da importação:', {
						status: xhr.status,
						statusText: xhr.statusText,
						textStatus: textStatus,
						errorThrown: errorThrown
					});
					
					$(
						'<div class="notice notice-error is-dismissible"><p>' +
							(texts.connection_error || 'Erro de conexão') +
							'</p></div>'
					).insertBefore('.codir2me-section:first');
				}
			});
		}

		// Verificar se há um scan_id na URL e atualizar o progresso.
		if (urlParams.has('scan_id')) {
			const scanId = urlParams.get('scan_id');
			updateScanProgress(scanId);
		}
	});

	// Funções auxiliares.
	function basename(path) {
		return path.split('/').pop();
	}

	function escapeHtml(str) {
		if (typeof str !== 'string') {
			return '';
		}
		const map = {
			'&': '&amp;',
			'<': '&lt;',
			'>': '&gt;',
			'"': '&quot;',
			"'": '&#039;',
		};
		return str.replace(/[&<>"']/g, function (m) {
			return map[m];
		});
	}

	function formatDate(dateString) {
		if (!dateString) {
			return '';
		}
		const date = new Date(dateString);
		if (isNaN(date.getTime())) {
			return dateString;
		}

		const day = date.getDate().toString().padStart(2, '0');
		const month = (date.getMonth() + 1).toString().padStart(2, '0');
		const year = date.getFullYear();

		return day + '/' + month + '/' + year;
	}

	// Função para iniciar o escaneamento AJAX com progresso em tempo real.
	function startAjaxScan() {
		// Resetar dados do escaneamento.
		scanData = {
			continuationToken: null,
			isTruncated: false,
			totalFiles: 0,
			totalScanned: 0,
			pagesProcessed: 0,
			scanComplete: false,
			uniqueFiles: {},
			results: {
				files: [],
				total_size: 0,
				total_count: 0,
				static_files: {
					count: 0,
					size: 0,
					extensions: {},
				},
				images: {
					count: 0,
					size: 0,
					extensions: {},
					thumbnails: {
						count: 0,
						size: 0,
					},
					originals: {
						count: 0,
						size: 0,
					},
				},
			},
		};

		// Mostrar a área de progresso.
		$('#codir2me-scan-progress-container').show();

		// Atualizar texto de status.
		$('#codir2me-progress-status').text(texts.starting_scan || 'Iniciando escaneamento...');

		// Chamar a primeira iteração do escaneamento.
		scanNextBatch();
	}

	// Fazer a importação
	function updateScanProgress(scanId) {
		if (
			$('#codir2me-scan-progress').length ||
			$('#codir2me-scanned-count').length
		) {
			// Função para verificar o status do escaneamento.
			function checkProgress() {
				let ajaxAction = 'codir2me_process_scan';
				let nonceField = 'scan_process_nonce';

				// Verificar se é um escaneamento de importação.
				if (scanId.indexOf('codir2meimport_') === 0) {
					ajaxAction = 'codir2me_process_import_scan';
					nonceField = 'import_scan_process_nonce';
				}

				$.ajax({
					url: codir2me_scanner_vars.ajax_url,
					type: 'POST',
					data: {
						action: ajaxAction,
						nonce: codir2me_scanner_vars[nonceField],
						scan_id: scanId,
					},
					success(response) {
						if (response.success && response.data) {
							const data = response.data;

							// Usar os valores vindos do servidor
							const pagesProcessed = data.pages_processed || 0;
							const totalScanned = data.total_scanned || 0;
							const totalFound = data.total_found || 0;

							// Atualizar páginas processadas
							$('#codir2me-pages-processed').text(pagesProcessed);

							// Tanto para importação quanto para escaneamento normal
							const displayScanned = pagesProcessed * 1000;
							$('#codir2me-scanned-count').text(displayScanned);
							$('#codir2me-files-scanned').text(displayScanned);

							// Arquivos encontrados: usar o valor real
							$('#codir2me-found-count').text(totalFound);
							$('#codir2me-files-found').text(totalFound);

							// Atualizar barra de progresso
							let progressPercentage = 0;
							if (pagesProcessed > 0) {
								// Para importação: estimar progresso baseado em páginas processadas.
								if (scanId.indexOf('codir2meimport_') === 0) {
									// Para importação, usar estimativa mais conservadora
									progressPercentage = Math.min(95, pagesProcessed * 10);
								} else {
									// Para escaneamento geral, usar estimativa baseada em páginas processadas
									progressPercentage = Math.min(95, pagesProcessed * 5);
								}
							}
							
							$('#codir2me-scan-progress').css('width', progressPercentage + '%');
							$('.codir2me-progress-inner').css('width', progressPercentage + '%');

							// Se completo, recarregar a página para mostrar resultados.
							if (data.status === 'complete') {
								$('#codir2me-progress-status').text(
									texts.scan_complete_loading || 'Escaneamento concluído, carregando resultados...'
								);
								$('#codir2me-scan-progress').css('width', '100%');
								$('.codir2me-progress-inner').css('width', '100%');

								// Recarregar a página após um breve atraso.
								setTimeout(function () {
									window.location.reload();
								}, 1500);
							} else if (data.status === 'error') {
								const errorMsg = data.message || data.error || 'Erro desconhecido';
								$('#codir2me-progress-status').text(
									(texts.error_prefix || 'Erro:') + ' ' + errorMsg
								);
								console.error('Erro no escaneamento:', data);
							} else {
								// Continuar verificando.
								setTimeout(checkProgress, 1000);
							}
						} else {
							// Melhor tratamento de erro na resposta.
							let errorMessage = texts.error_prefix || 'Erro';
							
							if (response && response.data) {
								if (typeof response.data === 'string') {
									errorMessage += ': ' + response.data;
								} else if (typeof response.data === 'object' && response.data.message) {
									errorMessage += ': ' + response.data.message;
								}
							} else {
								errorMessage += ': Resposta inválida do servidor';
							}

							console.error('Erro na resposta do progresso:', response);
							$('#codir2me-progress-status').text(errorMessage);
						}
					},
					error(xhr, textStatus, errorThrown) {
						console.error('Erro AJAX no progresso:', {
							status: xhr.status,
							statusText: xhr.statusText,
							textStatus: textStatus,
							errorThrown: errorThrown,
							responseText: xhr.responseText
						});
						
						$('#codir2me-progress-status').text(
							texts.connection_error || 'Erro de conexão'
						);
						setTimeout(checkProgress, 2000);
					},
				});
			}

			// Iniciar verificação de progresso.
			checkProgress();
		}
	}

	// Função para escanear o próximo lote com contadores em tempo real.
	function scanNextBatch() {
		// Atualizar texto de status.
		$('#codir2me-progress-status').text(texts.scanning_bucket || 'Escaneando bucket...');

		// Fazer requisição AJAX.
		$.ajax({
			url: codir2me_scanner_vars.ajax_url,
			type: 'POST',
			dataType: 'json',
			timeout: 60000,
			data: {
				action: 'codir2me_scan_bucket_progress',
				nonce: codir2me_scanner_vars.scanner_nonce,
				continuation_token: scanData.continuationToken,
			},
			success(response) {				
				if (response && response.success && response.data) {
					const data = response.data;

					// Atualizar variáveis de controle.
					scanData.continuationToken = data.continuation_token;
					scanData.isTruncated = data.is_truncated;
					scanData.pagesProcessed++;

					// Mesclar resultados com contadores corretos.
					mergeResults(data);

					// Atualizar UI com contagens corretas.
					// Páginas processadas: incrementa a cada requisição.
					$('#codir2me-pages-processed').text(
						scanData.pagesProcessed
					);

					// Arquivos escaneados: páginas * 1000 (ou total de arquivos processados).
					const totalScannedDisplay = scanData.pagesProcessed * 1000;
					$('#codir2me-files-scanned').text(totalScannedDisplay);

					// Arquivos encontrados: total real de arquivos únicos encontrados.
					$('#codir2me-files-found').text(
						scanData.results.total_count
					);

					// Calcular progresso aproximado.
					const progress = Math.min(
						95,
						Math.ceil(
							(scanData.pagesProcessed /
								Math.max(
									1,
									Math.ceil(
										scanData.results.total_count / 1000
									)
								) +
								0.1) *
								100
						)
					);
					$('#codir2me-progress-percentage').text(progress + '%');
					$('.codir2me-progress-inner').css('width', progress + '%');

					// Se ainda há mais dados, continuar escaneamento.
					if (data.is_truncated) {
						setTimeout(scanNextBatch, 500); // Pequeno delay entre requisições
					} else {
						scanData.scanComplete = true;
						$('#codir2me-progress-status').text(
							texts.scan_complete || 'Escaneamento concluído'
						);
						$('.codir2me-progress-inner').css('width', '100%');
						$('#codir2me-progress-percentage').text('100%');

						// Exibir resultados.
						displayScanResults();
					}
				} else {
					// Melhor tratamento de erro.
					let errorMessage = texts.error_prefix || 'Erro';
					
					console.error('Erro na resposta:', response);
					
					if (response && response.data) {
						if (typeof response.data === 'string') {
							errorMessage += ': ' + response.data;
						} else if (typeof response.data === 'object' && response.data.message) {
							errorMessage += ': ' + response.data.message;
						}
					} else if (response && typeof response === 'string') {
						errorMessage += ': ' + response;
					} else {
						errorMessage += ': Resposta inválida do servidor';
					}

					// Exibir erro.
					$('#codir2me-progress-status').text(errorMessage);
				}
			},
			error(xhr, textStatus, errorThrown) {
				console.error('Erro AJAX detalhado:', {
					status: xhr.status,
					statusText: xhr.statusText,
					textStatus: textStatus,
					errorThrown: errorThrown,
					responseText: xhr.responseText,
					continuationToken: scanData.continuationToken
				});

				let errorMessage = texts.connection_error || 'Erro de conexão';
				
				// Adicionar informações específicas do erro.
				if (xhr.status === 0) {
					errorMessage += ' (Falha de conectividade)';
				} else if (xhr.status >= 500) {
					errorMessage += ' (Erro interno do servidor: ' + xhr.status + ')';
				} else if (xhr.status >= 400) {
					errorMessage += ' (Erro de requisição: ' + xhr.status + ')';
				} else if (textStatus === 'timeout') {
					errorMessage += ' (Timeout)';
				} else if (textStatus === 'parsererror') {
					errorMessage += ' (Erro de formato na resposta)';
				}

				$('#codir2me-progress-status').text(errorMessage);

			},
		});
	}

	// Função para mesclar resultados evitando duplicações e atualizar contadores em tempo real.
	function mergeResults(pageResult) {
		// Iterar pelos arquivos da página atual.
		if (pageResult.files && Array.isArray(pageResult.files)) {
			const filesLength = pageResult.files.length;

			for (let i = 0; i < filesLength; i++) {
				const file = pageResult.files[i];
				const key = file.key;

				// Só processar se o arquivo não foi processado antes.
				if (!scanData.uniqueFiles[key]) {
					scanData.uniqueFiles[key] = true;

					// Adicionar à lista de arquivos.
					scanData.results.files.push(file);

					// Atualizar contadores totais.
					scanData.results.total_count++;
					scanData.results.total_size += file.size || 0;

					// Obter extensão do arquivo.
					const ext = (file.extension || '').toLowerCase();

					// Verificar se é um arquivo estático.
					const staticExtensions = [
						'js',
						'css',
						'svg',
						'woff',
						'woff2',
						'ttf',
						'eot',
					];
					const imageExtensions = [
						'jpg',
						'jpeg',
						'png',
						'gif',
						'webp',
						'avif',
					];

					if (staticExtensions.indexOf(ext) !== -1) {
						// Arquivo estático.
						scanData.results.static_files.count++;
						scanData.results.static_files.size += file.size || 0;

						// Contabilizar por extensão.
						if (!scanData.results.static_files.extensions[ext]) {
							scanData.results.static_files.extensions[ext] = {
								count: 0,
								size: 0,
							};
						}

						scanData.results.static_files.extensions[ext].count++;
						scanData.results.static_files.extensions[ext].size +=
							file.size || 0;
					} else if (imageExtensions.indexOf(ext) !== -1) {
						// Imagem.
						scanData.results.images.count++;
						scanData.results.images.size += file.size || 0;

						// Contabilizar por extensão.
						if (!scanData.results.images.extensions[ext]) {
							scanData.results.images.extensions[ext] = {
								count: 0,
								size: 0,
							};
						}

						scanData.results.images.extensions[ext].count++;
						scanData.results.images.extensions[ext].size +=
							file.size || 0;

						// Verificar se é uma miniatura.
						const filename = basename(file.key);
						const isThumbnail =
							/(-\d+x\d+\.[a-zA-Z]+$)|(-[a-zA-Z_]+\.[a-zA-Z]+$)/.test(
								filename
							);

						if (isThumbnail) {
							scanData.results.images.thumbnails.count++;
							scanData.results.images.thumbnails.size +=
								file.size || 0;
						} else {
							scanData.results.images.originals.count++;
							scanData.results.images.originals.size +=
								file.size || 0;
						}
					}
				}
			}
		}
	}

	function displayScanResults() {		
		// Salvar os dados do escaneamento no formato do escaneamento direto.
		$.ajax({
			url: codir2me_scanner_vars.ajax_url,
			type: 'POST',
			data: {
				action: 'codir2me_save_progressive_scan_results',
				scan_data: JSON.stringify(scanData.results),
				nonce: codir2me_scanner_vars.scanner_nonce,
			},
			success: function (response) {
				if (response.success) {
					// Recarregar a página para mostrar os resultados igual ao updateScanProgress faz.
					$('#codir2me-progress-status').text('Carregando resultados...');
					setTimeout(function () {
						window.location.reload();
					}, 1000);
				} else {
					console.error('Erro ao salvar resultados:', response);
				}
			},
			error: function () {
				// Se der erro, recarregar mesmo assim.
				console.error('Erro na requisição, recarregando página...');
				setTimeout(function () {
					window.location.reload();
				}, 1000);
			}
		});
	}

	// Funções auxiliares adicionais.
	function numberWithCommas(x) {
		return x.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
	}

	function formatBytes(bytes, decimals = 2) {
		if (bytes === 0) return '0 Bytes';

		const k = 1024;
		const dm = decimals < 0 ? 0 : decimals;
		const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];

		const i = Math.floor(Math.log(bytes) / Math.log(k));

		return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
	}
})(jQuery);