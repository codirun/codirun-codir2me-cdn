<?php
/**
 * Classe responsável por gerenciar a interface de usuário para o escaneamento do bucket R2.
 *
 * @package Codirun_R2_Media_Static_CDN
 */

// Evitar acesso direto ao arquivo.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classe responsável pela interface de usuário do scanner R2.
 */
class CODIR2ME_Admin_UI_Scanner {
	/**
	 * Instância da classe de administração.
	 *
	 * @var codir2me_Admin
	 */
	private $admin;

	/**
	 * Construtor.
	 *
	 * @param codir2me_Admin $admin Instância da classe de administração.
	 */
	public function __construct( $admin ) {
		$this->admin = $admin;

		// Hooks para processamento de formulários e AJAX.
		add_action( 'admin_post_codir2me_start_file_scan', array( $this, 'codir2me_start_async_file_scan' ) );
		add_action( 'wp_ajax_codir2me_process_scan', array( $this, 'codir2me_ajax_process_scan' ) );
		add_action( 'wp_ajax_codir2me_scan_bucket_progress', array( $this, 'codir2me_ajax_scan_bucket_progress' ) );
		add_action( 'wp_ajax_codir2me_scan_files_ajax', array( $this, 'codir2me_ajax_scan_files' ) );
		add_action( 'wp_ajax_codir2me_import_files_ajax', array( $this, 'codir2me_ajax_import_files' ) );
		add_action( 'admin_post_codir2me_import_files', array( $this, 'codir2me_handle_file_import' ) );
		add_action( 'wp_ajax_codir2me_load_more_scan_files', array( $this, 'codir2me_ajax_load_more_scan_files' ) );
		add_action( 'wp_ajax_codir2me_save_progressive_scan_results', array( $this, 'codir2me_handle_save_progressive_scan_results' ) );

		// Adicionar hook para obter resultados de importação.
		add_action( 'wp_ajax_codir2me_get_import_results', array( $this, 'codir2me_ajax_get_import_results' ) );

		// Hook para progresso de importação em tempo real.
		add_action( 'wp_ajax_codir2me_process_import_scan', array( $this, 'codir2me_ajax_process_import_scan' ) );
	}

	/**
	 * Manipulador para o endpoint AJAX de escaneamento progressivo.
	 *
	 * @throws Exception Se ocorrer erro durante o escaneamento ou validação.
	 */
	public function codir2me_ajax_scan_bucket_progress() {
		// Interceptar e limpar QUALQUER output antes do JSON.
		ob_start();

		// Hook para capturar erros fatais.
		register_shutdown_function(
			function () {
				$error = error_get_last();
				if ( $error && in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
					// Limpar output e enviar erro em JSON.
					if ( ob_get_level() ) {
						ob_end_clean();
					}

					// Definir headers JSON.
					if ( ! headers_sent() ) {
						header( 'Content-Type: application/json' );
					}

					echo wp_json_encode(
						array(
							'success' => false,
							'data'    => array( 'message' => 'ERRO FATAL PHP: ' . $error['message'] . ' no arquivo ' . $error['file'] . ' linha ' . $error['line'] ),
						)
					);
					exit;
				}
			}
		);

		try {
			// Verificar se é realmente uma requisição AJAX.
			if ( ! wp_doing_ajax() ) {
				throw new Exception( 'Esta função só pode ser chamada via AJAX' );
			}

			// Verificar nonce.
			if ( ! check_ajax_referer( 'codir2me_scanner_ajax_nonce', 'nonce', false ) ) {
				throw new Exception( 'Erro de segurança - nonce inválido' );
			}

			// Verificar permissões.
			if ( ! current_user_can( 'manage_options' ) ) {
				throw new Exception( 'Usuário sem permissão' );
			}

			// Verificar se as configurações R2 existem.
			$config = array(
				'access_key' => get_option( 'codir2me_access_key' ),
				'secret_key' => get_option( 'codir2me_secret_key' ),
				'bucket'     => get_option( 'codir2me_bucket' ),
				'endpoint'   => get_option( 'codir2me_endpoint' ),
			);

			foreach ( $config as $key => $value ) {
				if ( empty( $value ) ) {
					throw new Exception( "Configuração '$key' não está definida. Configure na aba Configurações." );
				}
			}

			// Verificar se o arquivo da classe existe.
			$scanner_file = CODIR2ME_CDN_INCLUDES_DIR . 'class-codir2me-scanner.php';
			if ( ! file_exists( $scanner_file ) ) {
				throw new Exception( 'Arquivo da classe Scanner não encontrado: ' . $scanner_file );
			}

			// Incluir classe (sem output).
			require_once $scanner_file;

			if ( ! class_exists( 'CODIR2ME_Scanner' ) ) {
				throw new Exception( 'Classe CODIR2ME_Scanner não foi carregada' );
			}

			// Criar scanner.
			$scanner = new CODIR2ME_Scanner(
				$config['access_key'],
				$config['secret_key'],
				$config['bucket'],
				$config['endpoint']
			);

			// Obter token de continuação.
			$continuation_token = isset( $_POST['continuation_token'] ) ?
				sanitize_text_field( wp_unslash( $_POST['continuation_token'] ) ) : null;

			// Executar escaneamento.
			$page_result = $scanner->codir2me_scan_bucket( 1000, $continuation_token );

			// Validar resultado.
			if ( ! is_array( $page_result ) ) {
				throw new Exception( 'Scanner retornou resultado inválido: ' . gettype( $page_result ) );
			}

			$required_fields = array( 'files', 'total_count', 'is_truncated' );
			foreach ( $required_fields as $field ) {
				if ( ! isset( $page_result[ $field ] ) ) {
					throw new Exception( "Campo '$field' ausente no resultado do scanner" );
				}
			}

			// Preparar resposta limpa.
			$response_data = array(
				'files'              => $page_result['files'],
				'total_count'        => intval( $page_result['total_count'] ),
				'continuation_token' => $page_result['continuation_token'] ?? null,
				'is_truncated'       => $page_result['is_truncated'],
			);

			// Limpar output buffer (evitar HTML indevido).
			if ( ob_get_level() ) {
				ob_end_clean();
			}

			// Definir headers JSON.
			if ( ! headers_sent() ) {
				header( 'Content-Type: application/json' );
			}

			// Enviar resposta JSON limpa.
			wp_send_json_success( $response_data );

		} catch ( Exception $e ) {
			// Limpar output buffer.
			if ( ob_get_level() ) {
				ob_end_clean();
			}

			// Definir headers JSON.
			if ( ! headers_sent() ) {
				header( 'Content-Type: application/json' );
			}

			wp_send_json_error(
				array(
					'message' => esc_html( $e->getMessage() ),
					'type'    => 'exception',
				)
			);
		}
	}

	/**
	 * Handler AJAX para salvar resultados do escaneamento progressivo.
	 */
	public function codir2me_handle_save_progressive_scan_results() {
		// Verificar nonce.
		if ( ! check_ajax_referer( 'codir2me_scanner_ajax_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Erro de segurança. Nonce inválido.', 'codirun-codir2me-cdn' ) ) );
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permissão negada.', 'codirun-codir2me-cdn' ) ) );
			return;
		}

		// Obter dados do escaneamento.
		$scan_data_json = isset( $_POST['scan_data'] ) ? sanitize_text_field( wp_unslash( $_POST['scan_data'] ) ) : '';

		if ( empty( $scan_data_json ) ) {
			wp_send_json_error( array( 'message' => __( 'Dados do escaneamento não fornecidos.', 'codirun-codir2me-cdn' ) ) );
			return;
		}

		$scan_data = json_decode( $scan_data_json, true );

		if ( ! $scan_data ) {
			wp_send_json_error( array( 'message' => __( 'Dados do escaneamento inválidos.', 'codirun-codir2me-cdn' ) ) );
			return;
		}

		try {
			// Processar extensões dos arquivos para o formato correto e adicionar tipo.
			$static_extensions = array();
			$image_extensions  = array();
			$image_exts        = array( 'jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff', 'tif', 'webp', 'avif' );
			$processed_files   = array();

			if ( isset( $scan_data['files'] ) && is_array( $scan_data['files'] ) ) {
				foreach ( $scan_data['files'] as $file ) {
					$extension = isset( $file['extension'] ) ? strtolower( $file['extension'] ) : '';
					$size      = isset( $file['size'] ) ? intval( $file['size'] ) : 0;

					// Determinar tipo do arquivo.
					$is_image  = in_array( $extension, $image_exts, true );
					$file_type = $is_image ? 'image' : 'static';

					// Adicionar tipo ao arquivo.
					$file['type']      = $file_type;
					$processed_files[] = $file;

					if ( empty( $extension ) ) {
						continue;
					}

					if ( $is_image ) {
						if ( ! isset( $image_extensions[ $extension ] ) ) {
							$image_extensions[ $extension ] = array(
								'count' => 0,
								'size'  => 0,
							);
						}
						++$image_extensions[ $extension ]['count'];
						$image_extensions[ $extension ]['size'] += $size;
					} else {
						if ( ! isset( $static_extensions[ $extension ] ) ) {
							$static_extensions[ $extension ] = array(
								'count' => 0,
								'size'  => 0,
							);
						}
						++$static_extensions[ $extension ]['count'];
						$static_extensions[ $extension ]['size'] += $size;
					}
				}
			}

			// Converter para o formato que codir2me_render_scan_results_section() espera.
			$formatted_results = array(
				'total_count'  => isset( $scan_data['total_count'] ) ? $scan_data['total_count'] : 0,
				'total_size'   => isset( $scan_data['total_size'] ) ? $scan_data['total_size'] : 0,
				'static_files' => array(
					'count'      => isset( $scan_data['static_files']['count'] ) ? $scan_data['static_files']['count'] : 0,
					'size'       => isset( $scan_data['static_files']['size'] ) ? $scan_data['static_files']['size'] : 0,
					'extensions' => $static_extensions,
				),
				'images'       => array(
					'count'      => isset( $scan_data['images']['count'] ) ? $scan_data['images']['count'] : 0,
					'size'       => isset( $scan_data['images']['size'] ) ? $scan_data['images']['size'] : 0,
					'extensions' => $image_extensions,
					'originals'  => array(
						'count' => isset( $scan_data['images']['originals']['count'] ) ? $scan_data['images']['originals']['count'] : 0,
						'size'  => isset( $scan_data['images']['originals']['size'] ) ? $scan_data['images']['originals']['size'] : 0,
					),
					'thumbnails' => array(
						'count' => isset( $scan_data['images']['thumbnails']['count'] ) ? $scan_data['images']['thumbnails']['count'] : 0,
						'size'  => isset( $scan_data['images']['thumbnails']['size'] ) ? $scan_data['images']['thumbnails']['size'] : 0,
					),
				),
				'files'        => $processed_files,
			);

			// Salvar temporariamente os resultados para exibição.
			set_transient( 'codir2me_progressive_scan_results_' . get_current_user_id(), $formatted_results, 300 ); // 5 minutos

			wp_send_json_success( array( 'message' => 'Resultados salvos com sucesso' ) );

		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Manipulador AJAX para progresso de importação em tempo real - CORRIGIDO.
	 *
	 * @throws Exception Se ocorrer erro durante o processamento.
	 */
	public function codir2me_ajax_process_import_scan() {
		// Verificar nonce.
		if ( ! check_ajax_referer( 'codir2me_import_scan_process', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Erro de segurança. Por favor, atualize a página.', 'codirun-codir2me-cdn' ) ) );
		}

		// Verificar permissões.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permissão negada.', 'codirun-codir2me-cdn' ) ) );
		}

		// Obter ID do escaneamento.
		$scan_id = isset( $_POST['scan_id'] ) ? sanitize_text_field( wp_unslash( $_POST['scan_id'] ) ) : '';
		if ( empty( $scan_id ) ) {
			wp_send_json_error( array( 'message' => __( 'ID de escaneamento inválido.', 'codirun-codir2me-cdn' ) ) );
		}

		// Obter dados do escaneamento.
		$scan_data = get_option( 'codir2me_import_scan_' . $scan_id );
		if ( ! $scan_data ) {
			wp_send_json_error( array( 'message' => __( 'Escaneamento não encontrado.', 'codirun-codir2me-cdn' ) ) );
		}

		// Verificar se já está completo.
		if ( 'complete' === $scan_data['status'] ) {
			wp_send_json_success(
				array(
					'status'          => 'complete',
					'total_found'     => $scan_data['total_found'],
					'total_scanned'   => $scan_data['total_scanned'],
					'pages_processed' => $scan_data['pages_processed'],
				)
			);
			wp_die();
		}

		try {
			// Obter scanner.
			require_once CODIR2ME_CDN_INCLUDES_DIR . 'class-codir2me-scanner.php';
			$scanner = new CODIR2ME_Scanner(
				get_option( 'codir2me_access_key' ),
				get_option( 'codir2me_secret_key' ),
				get_option( 'codir2me_bucket' ),
				get_option( 'codir2me_endpoint' )
			);

			// Obter formato do escaneamento.
			$format = isset( $scan_data['format'] ) ? $scan_data['format'] : 'all';

			// Inicializar contadores se necessário.
			if ( ! isset( $scan_data['total_scanned'] ) ) {
				$scan_data['total_scanned'] = 0;
			}
			if ( ! isset( $scan_data['total_found'] ) ) {
				$scan_data['total_found'] = 0;
			}
			if ( ! isset( $scan_data['pages_processed'] ) ) {
				$scan_data['pages_processed'] = 0;
			}

			// Processar próxima página.
			$page_result = $this->codir2me_scan_files_by_format_page( $scanner, $format, $scan_data['continuation_token'] );

			if ( $page_result ) {
				// Contar arquivos realmente processados.
				$files_in_page = isset( $page_result['files'] ) ? count( $page_result['files'] ) : 0;

				// Adicionar arquivos únicos à lista.
				if ( isset( $page_result['files'] ) && is_array( $page_result['files'] ) ) {
					$unique_files_added = 0;

					foreach ( $page_result['files'] as $file ) {
						$key = $file['key'];

						// Verificar se já existe na lista de arquivos.
						$exists = false;
						if ( isset( $scan_data['files'] ) && is_array( $scan_data['files'] ) ) {
							foreach ( $scan_data['files'] as $existing_file ) {
								if ( $existing_file['key'] === $key ) {
									$exists = true;
									break;
								}
							}
						}

						if ( ! $exists ) {
							$scan_data['files'][] = $file;
							++$unique_files_added;
						}
					}

					// CORREÇÃO: Atualizar contadores corretamente.
					$scan_data['total_scanned'] += $files_in_page;
					$scan_data['total_found']    = count( $scan_data['files'] );
				}

				// Atualizar outros dados.
				++$scan_data['pages_processed'];
				$scan_data['continuation_token'] = $page_result['continuation_token'];

				// Verificar se há mais páginas.
				if ( ! $page_result['is_truncated'] ) {
					$scan_data['status']   = 'complete';
					$scan_data['end_time'] = time();
				}

				// Salvar progresso.
				update_option( 'codir2me_import_scan_' . $scan_id, $scan_data );

				// Retornar dados de progresso.
				wp_send_json_success(
					array(
						'status'          => $scan_data['status'],
						'total_found'     => $scan_data['total_found'],
						'total_scanned'   => $scan_data['total_scanned'],
						'pages_processed' => $scan_data['pages_processed'],
						'continuation'    => $scan_data['continuation_token'],
					)
				);
			} else {
				throw new Exception( __( 'Erro ao processar página do escaneamento de importação.', 'codirun-codir2me-cdn' ) );
			}
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Função auxiliar para escanear arquivos por formato com paginação.
	 *
	 * @param CODIR2ME_Scanner $scanner O objeto scanner.
	 * @param string           $format O formato a buscar.
	 * @param string|null      $continuation_token Token de continuação.
	 * @return array|null Resultado da página ou null se erro.
	 */
	private function codir2me_scan_files_by_format_page( $scanner, $format, $continuation_token = null ) {
		try {
			// Usar o método codir2me_scan_bucket para obter uma página de 1000 itens.
			$page_result = $scanner->codir2me_scan_bucket( 1000, $continuation_token );

			if ( ! $page_result ) {
				return null;
			}

			// Filtrar arquivos por formato.
			$filtered_files      = array();
			$standard_extensions = array( 'jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff', 'tif' );

			if ( isset( $page_result['files'] ) && is_array( $page_result['files'] ) ) {
				foreach ( $page_result['files'] as $file ) {
					$extension      = strtolower( pathinfo( $file['key'], PATHINFO_EXTENSION ) );
					$should_include = false;

					switch ( $format ) {
						case 'all':
							// Incluir TODAS as imagens (padrão + WebP + AVIF).
							if ( in_array( $extension, $standard_extensions, true ) ||
								'webp' === $extension ||
								'avif' === $extension ) {
								$should_include = true;
							}
							break;

						case 'original':
							// Apenas formatos padrão (JPG, PNG, GIF).
							if ( in_array( $extension, $standard_extensions, true ) ) {
								$should_include = true;
							}
							break;

						case 'webp':
							// Incluir arquivos .webp E arquivos com -webp. no nome.
							if ( 'webp' === $extension || strpos( $file['key'], '-webp.' ) !== false ) {
								$should_include = true;
							}
							break;

						case 'avif':
							// Incluir arquivos .avif E arquivos com -avif. no nome.
							if ( 'avif' === $extension || strpos( $file['key'], '-avif.' ) !== false ) {
								$should_include = true;
							}
							break;
					}

					if ( $should_include ) {
						// Garantir que a extensão está definida.
						if ( ! isset( $file['extension'] ) ) {
							$file['extension'] = $extension;
						}
						$filtered_files[] = $file;
					}
				}
			}

			return array(
				'files'              => $filtered_files,
				'continuation_token' => isset( $page_result['continuation_token'] ) ? $page_result['continuation_token'] : null,
				'is_truncated'       => isset( $page_result['is_truncated'] ) ? $page_result['is_truncated'] : false,
			);

		} catch ( Exception $e ) {
			// Log do erro.
			if ( function_exists( 'codir2me_cdn_log' ) ) {
				codir2me_cdn_log( 'Erro no codir2me_scan_files_by_format_page: ' . $e->getMessage(), 'error' );
			}
			return null;
		}
	}

	/**
	 * Manipulador AJAX para carregar mais arquivos de um escaneamento.
	 */
	public function codir2me_ajax_load_more_scan_files() {
		// Verificar nonce.
		if ( ! check_ajax_referer( 'codir2me_scan_process', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Erro de segurança. Por favor, atualize a página.', 'codirun-codir2me-cdn' ) ) );
		}

		// Verificar permissões.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permissão negada.', 'codirun-codir2me-cdn' ) ) );
		}

		// Obter parâmetros da requisição.
		$scan_id = isset( $_POST['scan_id'] ) ? sanitize_text_field( wp_unslash( $_POST['scan_id'] ) ) : '';
		$offset  = isset( $_POST['offset'] ) ? intval( $_POST['offset'] ) : 0;
		$limit   = isset( $_POST['limit'] ) ? intval( $_POST['limit'] ) : 100;

		if ( empty( $scan_id ) ) {
			wp_send_json_error( array( 'message' => __( 'ID de escaneamento inválido ou não fornecido.', 'codirun-codir2me-cdn' ) ) );
		}

		// Obter dados do escaneamento (tentar ambos os tipos).
		$scan_data = get_option( 'codir2me_scan_in_progress_' . $scan_id );
		if ( ! $scan_data ) {
			$scan_data = get_option( 'codir2me_import_scan_' . $scan_id );
		}

		if ( ! $scan_data || ! isset( $scan_data['files'] ) || ! is_array( $scan_data['files'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Dados de escaneamento não encontrados ou inválidos.', 'codirun-codir2me-cdn' ) ) );
		}

		// Verificar se o offset é válido.
		if ( $offset >= count( $scan_data['files'] ) ) {
			wp_send_json_success(
				array(
					'files'     => array(),
					'remaining' => 0,
				)
			);
			return;
		}

		// Obter o próximo lote de arquivos.
		$files     = array_slice( $scan_data['files'], $offset, $limit );
		$remaining = count( $scan_data['files'] ) - ( $offset + count( $files ) );

		// Adicionar informações auxiliares de depuração, se estiver ativo.
		if ( get_option( 'codir2me_debug_mode', false ) && function_exists( 'codir2me_cdn_log' ) ) {
			codir2me_cdn_log(
				sprintf(
					// translators: %1$s é o ID ou nome do escaneamento; %2$d é o offset; %3$d é o limite; %4$d é o total de arquivos; %5$d é o número de arquivos já carregados; %6$d é o número restante.
					__( 'Carregando mais arquivos do escaneamento %1$s. Offset: %2$d, Limite: %3$d, Total de arquivos: %4$d, Arquivos carregados: %5$d, Restantes: %6$d', 'codirun-codir2me-cdn' ),
					$scan_id,
					$offset,
					$limit,
					count( $scan_data['files'] ),
					count( $files ),
					$remaining
				),
				'info'
			);
		}

		// Retornar os arquivos e informações sobre os restantes.
		wp_send_json_success(
			array(
				'files'     => $files,
				'remaining' => $remaining,
			)
		);
	}

	/**
	 * Manipulador para o endpoint AJAX de escaneamento de arquivos.
	 */
	public function codir2me_ajax_scan_files() {
		// Verificar nonce.
		if ( ! check_ajax_referer( 'codir2me_scan_action', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Erro de segurança. Por favor, atualize a página.', 'codirun-codir2me-cdn' ) ) );
		}

		// Obter formato.
		$format = isset( $_POST['format'] ) ? sanitize_text_field( wp_unslash( $_POST['format'] ) ) : 'all';

		try {
			// Obter scanner.
			require_once CODIR2ME_CDN_INCLUDES_DIR . 'class-codir2me-scanner.php';
			$scanner = new CODIR2ME_Scanner(
				get_option( 'codir2me_access_key' ),
				get_option( 'codir2me_secret_key' ),
				get_option( 'codir2me_bucket' ),
				get_option( 'codir2me_endpoint' )
			);

			// Listar arquivos por formato.
			$files = $scanner->codir2me_list_files_by_format( $format );

			// Retornar resultados.
			wp_send_json_success(
				array(
					'files' => $files,
					'count' => count( $files ),
				)
			);
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}

		wp_die();
	}

	/**
	 * Manipulador para o endpoint AJAX de importação de arquivos.
	 */
	public function codir2me_ajax_import_files() {
		// Verificar nonce.
		if ( ! check_ajax_referer( 'codir2me_import_action', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Erro de segurança. Por favor, atualize a página.', 'codirun-codir2me-cdn' ) ) );
		}

		// Obter arquivos para importar.
		$files = isset( $_POST['files'] ) && is_array( $_POST['files'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['files'] ) ) : array();

		if ( empty( $files ) ) {
			wp_send_json_error( array( 'message' => __( 'Nenhum arquivo selecionado para importação.', 'codirun-codir2me-cdn' ) ) );
		}

		try {
			// Obter scanner.
			require_once CODIR2ME_CDN_INCLUDES_DIR . 'class-codir2me-scanner.php';
			$scanner = new CODIR2ME_Scanner(
				get_option( 'codir2me_access_key' ),
				get_option( 'codir2me_secret_key' ),
				get_option( 'codir2me_bucket' ),
				get_option( 'codir2me_endpoint' )
			);

			// Importar arquivos.
			$results = $scanner->codir2me_batch_import_files( $files );

			// Retornar resultados.
			wp_send_json_success(
				array(
					'success'       => $results['success'],
					'failed'        => $results['failed'],
					'total_success' => count( $results['success'] ),
					'total_failed'  => count( $results['failed'] ),
				)
			);
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}

		wp_die();
	}

	/**
	 * Endpoint AJAX para obter resultados de importação.
	 */
	public function codir2me_ajax_get_import_results() {
		// Verificar nonce.
		if ( ! check_ajax_referer( 'codir2me_import_results_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Erro de segurança.', 'codirun-codir2me-cdn' ) ) );
		}

		// Obter resultados salvos.
		$results = get_option( 'codir2me_import_results', array() );

		// Contar sucessos e falhas.
		$success_count = isset( $results['success'] ) ? count( $results['success'] ) : 0;
		$failed_count  = isset( $results['failed'] ) ? count( $results['failed'] ) : 0;

		// Limpar opção após recuperar (opcional).
		delete_option( 'codir2me_import_results' );

		// Enviar resultados.
		wp_send_json_success(
			array(
				'success_count' => $success_count,
				'failed_count'  => $failed_count,
			)
		);

		wp_die();
	}

	/**
	 * Renderiza a aba de escaneamento.
	 */
	public function codir2me_render() {
		// Verificar se o AWS SDK está disponível.
		$asyncaws_sdk_available = false;
		if ( file_exists( CODIR2ME_CDN_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
			require_once CODIR2ME_CDN_PLUGIN_DIR . 'vendor/autoload.php';
			$asyncaws_sdk_available = class_exists( 'AsyncAws\S3\S3Client' );
		}

		// Inicializar as variáveis para resultados.
		$scan_results       = null;
		$comparison_results = null;
		$sync_results       = null;
		$import_results     = null;
		$scan_id            = null;
		$scan_data          = null;

		// Verificar se estamos em uma página de resultados de escaneamento específica.
		if ( isset( $_GET['scan_id'] ) ) {
			$scan_id   = sanitize_text_field( wp_unslash( $_GET['scan_id'] ) );
			$scan_data = get_option( 'codir2me_scan_in_progress_' . $scan_id );

			// Se não encontrar, tentar buscar dados de importação.
			if ( ! $scan_data ) {
				$scan_data = get_option( 'codir2me_import_scan_' . $scan_id );
			}
		}

		// Processar solicitação de escaneamento direto.
		if ( isset( $_POST['codir2me_start_scan'] ) && check_admin_referer( 'codir2me_scanner_action' ) ) {
			$scan_results = $this->codir2me_do_scan();
		}

		// Processar solicitação de sincronização.
		if ( isset( $_POST['codir2me_sync_records'] ) && check_admin_referer( 'codir2me_scanner_action' ) ) {
			if ( isset( $_POST['scan_data'] ) ) {
				$scan_data_json     = json_decode( sanitize_text_field( wp_unslash( $_POST['scan_data'] ) ), true );
				$comparison_results = $this->codir2me_do_comparison( $scan_data_json );

				// Verificar se devemos sincronizar.
				$add_missing        = isset( $_POST['codir2me_add_missing'] ) && '1' === $_POST['codir2me_add_missing'];
				$remove_nonexistent = isset( $_POST['codir2me_remove_nonexistent'] ) && '1' === $_POST['codir2me_remove_nonexistent'];

				if ( $add_missing || $remove_nonexistent ) {
					$sync_results = $this->codir2me_do_sync( $comparison_results, $add_missing, $remove_nonexistent );
				}
			}
		}

		// Processar importação de arquivos se for o caso.
		if ( isset( $_POST['codir2me_import_files'] ) && isset( $_POST['codir2me_files_to_import'] ) && check_admin_referer( 'codir2me_import_action', 'codir2me_import_nonce' ) ) {
			$import_results = $this->codir2me_process_file_import( true ); // true para retornar resultados em vez de exibir.
		}

		// Mostrar interface principal.
		$this->codir2me_render_main_interface( $asyncaws_sdk_available, $scan_results, $comparison_results, $sync_results, $import_results, $scan_id, $scan_data );
	}

	/**
	 * Renderiza a interface principal.
	 *
	 * @param bool   $asyncaws_sdk_available Indica se o SDK AsyncAws está disponível.
	 * @param array  $scan_results           Resultados do escaneamento.
	 * @param array  $comparison_results     Resultados da comparação.
	 * @param array  $sync_results           Resultados da sincronização.
	 * @param array  $import_results         Resultados da importação.
	 * @param string $scan_id               ID do escaneamento.
	 * @param array  $scan_data              Dados do escaneamento.
	 */
	private function codir2me_render_main_interface( $asyncaws_sdk_available, $scan_results, $comparison_results, $sync_results, $import_results = null, $scan_id = null, $scan_data = null ) {
		// Verificar se há resultados de escaneamento progressivo salvos.
		$progressive_results = get_transient( 'codir2me_progressive_scan_results_' . get_current_user_id() );
		if ( $progressive_results && ! $scan_results ) {
			$scan_results = $progressive_results;
			// Limpar os resultados salvos após usar.
			delete_transient( 'codir2me_progressive_scan_results_' . get_current_user_id() );
		}
		?>
		<div class="codir2me-tab-content">
			<div class="codir2me-flex-container">
				<div class="codir2me-main-column">
					<?php if ( $import_results ) : ?>
						<div class="codir2me-section">
							<h2><?php esc_html_e( 'Resultados da Importação', 'codirun-codir2me-cdn' ); ?></h2>
							<?php $this->codir2me_display_import_results( $import_results ); ?>
						</div>
					<?php endif; ?>
					
					<?php
					// Sempre mostrar a seção de importação.
					$this->codir2me_render_import_section();
					?>
					
					<?php if ( $scan_id && $scan_data ) : ?>
						<div class="codir2me-section">
							<h2><?php esc_html_e( 'Resultados da Busca no R2', 'codirun-codir2me-cdn' ); ?></h2>
							<?php $this->codir2me_render_scan_progress_or_results( $scan_id, $scan_data ); ?>
						</div>
					<?php endif; ?>
					
					<?php
					// Sempre mostrar a seção de escaneamento, mesmo durante a importação.
					$this->codir2me_render_scanner_section( $asyncaws_sdk_available );
					?>
					
					<?php if ( $scan_results ) : ?>
						<?php $this->codir2me_render_scan_results_section( $scan_results, $comparison_results, $sync_results ); ?>
					<?php endif; ?>
				</div>
				
				<?php $this->codir2me_render_sidebar(); ?>
			</div>
		</div>
		
		<?php
	}

	/**
	 * Renderiza o progresso ou resultados do escaneamento com progresso em tempo real.
	 *
	 * @param string $scan_id   ID do escaneamento.
	 * @param array  $scan_data Dados do escaneamento.
	 */
	private function codir2me_render_scan_progress_or_results( $scan_id, $scan_data ) {
		if ( 'starting' === $scan_data['status'] || 'scanning' === $scan_data['status'] ) {
			// Exibir progresso do escaneamento com contadores em tempo real.
			?>
			<div class="codir2me-scan-progress">
				<h3><?php esc_html_e( 'Escaneando o bucket R2...', 'codirun-codir2me-cdn' ); ?></h3>
				
				<div class="codir2me-progress-bar-container">
					<div class="codir2me-progress-bar">
						<div class="codir2me-progress-inner" id="codir2me-scan-progress" style="width: 0%"></div>
					</div>
				</div>
				
				<div class="codir2me-progress-details">
					<p>
						<span class="codir2me-progress-label"><?php esc_html_e( 'Páginas processadas:', 'codirun-codir2me-cdn' ); ?></span>
						<span class="codir2me-progress-value" id="codir2me-pages-processed"><?php echo intval( isset( $scan_data['pages_processed'] ) ? $scan_data['pages_processed'] : 0 ); ?></span>
					</p>
					<p>
						<span class="codir2me-progress-label"><?php esc_html_e( 'Arquivos escaneados:', 'codirun-codir2me-cdn' ); ?></span>
						<span class="codir2me-progress-value" id="codir2me-scanned-count"><?php echo intval( isset( $scan_data['total_scanned'] ) ? $scan_data['total_scanned'] : 0 ); ?></span>
					</p>
					<p>
						<span class="codir2me-progress-label"><?php esc_html_e( 'Imagens encontradas:', 'codirun-codir2me-cdn' ); ?></span>
						<span class="codir2me-progress-value" id="codir2me-found-count"><?php echo intval( isset( $scan_data['total_found'] ) ? $scan_data['total_found'] : 0 ); ?></span>
					</p>
				</div>
				
				<p class="codir2me-progress-status" id="codir2me-progress-status"><?php esc_html_e( 'Escaneamento em andamento...', 'codirun-codir2me-cdn' ); ?></p>
					   
			</div>

			<?php
		} elseif ( 'complete' === $scan_data['status'] ) {
			$filtered_files = $this->codir2me_filter_image_files( $scan_data['files'], $scan_data );

			?>
			<div class="codir2me-scan-results">
				<div class="codir2me-results-summary">
					<h3><?php esc_html_e( 'Busca concluída!', 'codirun-codir2me-cdn' ); ?></h3>
					<p>
						<span class="dashicons dashicons-yes-alt"></span>
						<?php
						// Verificar se end_time e start_time existem.
						$end_time   = isset( $scan_data['end_time'] ) ? $scan_data['end_time'] : time();
						$start_time = isset( $scan_data['start_time'] ) ? $scan_data['start_time'] : time();
						$duration   = max( 0, $end_time - $start_time );

						printf(
							// translators: %1$d é o número de imagens, %2$d é o tempo em segundos.
							esc_html__( 'Foram encontradas %1$d imagens em %2$d segundos.', 'codirun-codir2me-cdn' ),
							esc_html( count( $filtered_files ) ),
							esc_html( $duration )
						);
						?>
					</p>
				</div>
				
				<?php if ( empty( $filtered_files ) ) : ?>
					<div class="notice notice-warning">
						<p><?php esc_html_e( 'Nenhuma imagem foi encontrada com os critérios selecionados. Tente buscar com outro formato ou verifique se há arquivos no seu bucket.', 'codirun-codir2me-cdn' ); ?></p>
					</div>
				<?php else : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="codir2me-import-form">
						<?php wp_nonce_field( 'codir2me_import_action', 'codir2me_import_nonce' ); ?>
						<input type="hidden" name="action" value="codir2me_import_files">
						<input type="hidden" name="scan_id" value="<?php echo esc_attr( $scan_id ); ?>">
						
						<div class="codir2me-bulk-actions">
							<button type="button" class="button" id="codir2me-select-all"><?php esc_html_e( 'Selecionar Todos', 'codirun-codir2me-cdn' ); ?></button>
							<button type="button" class="button" id="codir2me-deselect-all"><?php esc_html_e( 'Desmarcar Todos', 'codirun-codir2me-cdn' ); ?></button>
							<div class="codir2me-search-filter">
								<input type="text" id="codir2me-filter-files" placeholder="<?php esc_attr_e( 'Filtrar por nome ou extensão...', 'codirun-codir2me-cdn' ); ?>" class="regular-text">
							</div>
						</div>
						
						<div class="codir2me-files-grid">
							<?php
							// Usar arquivos filtrados em vez de todos os arquivos.
							if ( ! empty( $filtered_files ) ) {
								// Ordenar arquivos por data de modificação (mais recentes primeiro).
								usort(
									$filtered_files,
									function ( $a, $b ) {
										$time_a = isset( $a['last_modified'] ) ? strtotime( $a['last_modified'] ) : 0;
										$time_b = isset( $b['last_modified'] ) ? strtotime( $b['last_modified'] ) : 0;
										return $time_b - $time_a;
									}
								);

								$total_files     = count( $filtered_files );
								$initial_display = min( 100, $total_files );

								for ( $i = 0; $i < $initial_display; $i++ ) :
									if ( isset( $filtered_files[ $i ] ) ) {
										$file          = $filtered_files[ $i ];
										$extension     = isset( $file['extension'] ) ? $file['extension'] : '';
										$size          = isset( $file['size'] ) ? $file['size'] : 0;
										$size_kb       = $size > 0 ? round( $size / 1024, 2 ) : 0;
										$size_display  = $size_kb > 1024 ? round( $size_kb / 1024, 2 ) . ' MB' : $size_kb . ' KB';
										$last_modified = isset( $file['last_modified'] ) ? $file['last_modified'] : '';
										?>
										<div class="codir2me-file-item" data-name="<?php echo esc_attr( basename( $file['key'] ) ); ?>" data-ext="<?php echo esc_attr( $extension ); ?>">
											<label class="codir2me-file-checkbox">
												<input type="checkbox" name="codir2me_files_to_import[]" value="<?php echo esc_attr( $file['key'] ); ?>">
												<span class="dashicons dashicons-format-image"></span>
											</label>
											<div class="codir2me-file-details">
												<div class="codir2me-file-name" title="<?php echo esc_attr( basename( $file['key'] ) ); ?>"><?php echo esc_html( basename( $file['key'] ) ); ?></div>
												<div class="codir2me-file-meta">
													<span class="codir2me-file-type" style="font-weight: bold;"><?php echo esc_html( strtoupper( $extension ) ); ?></span>
													<span class="codir2me-file-size"><?php echo esc_html( $size_display ); ?></span>
													<?php if ( ! empty( $last_modified ) ) : ?>
														<span class="codir2me-file-date"><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $last_modified ) ) ); ?></span>
													<?php endif; ?>
												</div>
											</div>
										</div>
										<?php
									}
								endfor;

								if ( $total_files > $initial_display ) :
									?>
									<div class="codir2me-file-item codir2me-load-more-container">
										<button type="button" id="codir2me-load-more-files" class="button button-secondary">
											<?php
											printf(
												// translators: %d é o número de itens restantes a serem carregados.
												esc_html__( 'Carregar mais itens (%d restantes)', 'codirun-codir2me-cdn' ),
												esc_html( $total_files - $initial_display )
											);
											?>
										</button>
									</div>
									<?php
								endif;
							} else {
								echo '<div class="notice notice-error"><p>' . esc_html__( 'Nenhuma imagem encontrada com os critérios selecionados.', 'codirun-codir2me-cdn' ) . '</p></div>';
							}
							?>
						</div>
						
						<div class="codir2me-import-button-container">
							<button type="submit" name="codir2me_import_files" class="button button-primary" id="codir2me-import-selected">
								<span class="dashicons dashicons-download"></span>
								<?php esc_html_e( 'Importar Arquivos Selecionados', 'codirun-codir2me-cdn' ); ?>
							</button>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=codirun-codir2me-cdn-scanner' ) ); ?>" class="button" id="codir2me-new-search">
								<span class="dashicons dashicons-search"></span>
								<?php esc_html_e( 'Nova Busca', 'codirun-codir2me-cdn' ); ?>
							</a>
						</div>
					</form>
				
				<?php endif; ?>
			</div>
			<?php
		} elseif ( 'error' === $scan_data['status'] ) {
			// Exibir erro de escaneamento.
			?>
			<div class="notice notice-error">
				<p><?php esc_html_e( 'Ocorreu um erro durante o escaneamento:', 'codirun-codir2me-cdn' ); ?> <?php echo isset( $scan_data['error'] ) ? esc_html( $scan_data['error'] ) : esc_html__( 'Erro desconhecido', 'codirun-codir2me-cdn' ); ?></p>
				<p>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=codirun-codir2me-cdn-scanner' ) ); ?>" class="button button-secondary">
						<span class="dashicons dashicons-arrow-left-alt"></span>
						<?php esc_html_e( 'Voltar e tentar novamente', 'codirun-codir2me-cdn' ); ?>
					</a>
				</p>
			</div>
			<?php
		}
	}

	/**
	 * Filtra arquivos para exibir apenas imagens conforme o formato selecionado.
	 *
	 * @param array $files Lista de arquivos do escaneamento.
	 * @param array $scan_data Dados do escaneamento (inclui formato selecionado).
	 * @return array Lista filtrada de imagens.
	 */
	private function codir2me_filter_image_files( $files, $scan_data ) {
		if ( ! is_array( $files ) || empty( $files ) ) {
			return array();
		}

		// Obter formato selecionado.
		$format = isset( $scan_data['format'] ) ? $scan_data['format'] : 'all';

		// Definir extensões de imagem.
		$image_extensions = array( 'jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff', 'tif' );
		$webp_extensions  = array( 'webp' );
		$avif_extensions  = array( 'avif' );

		$filtered_files = array();

		foreach ( $files as $file ) {
			if ( ! isset( $file['key'] ) ) {
				continue;
			}

			// Extrair extensão.
			$extension      = strtolower( pathinfo( $file['key'], PATHINFO_EXTENSION ) );
			$should_include = false;

			// Aplicar filtro conforme formato selecionado.
			switch ( $format ) {
				case 'all':
					// Todas as Imagens (Todos os formatos).
					if ( in_array( $extension, $image_extensions, true ) ||
						in_array( $extension, $webp_extensions, true ) ||
						in_array( $extension, $avif_extensions, true ) ) {
						$should_include = true;
					}
					break;

				case 'original':
					// Formatos Padrão (JPG, PNG, GIF).
					// Incluir apenas formatos padrão e excluir conversões.
					if ( in_array( $extension, $image_extensions, true ) ) {
						// Verificar se não é uma conversão WebP/AVIF.
						if ( ! preg_match( '/-(webp|avif)\./', $file['key'] ) ) {
							$should_include = true;
						}
					}
					break;

				case 'webp':
					// WebP - incluir arquivos .webp E arquivos convertidos com -webp. no nome.
					if ( in_array( $extension, $webp_extensions, true ) ||
						strpos( $file['key'], '-webp.' ) !== false ) {
						$should_include = true;
					}
					break;

				case 'avif':
					// AVIF - incluir arquivos .avif E arquivos convertidos com -avif. no nome.
					if ( in_array( $extension, $avif_extensions, true ) ||
						strpos( $file['key'], '-avif.' ) !== false ) {
						$should_include = true;
					}
					break;
			}

			if ( $should_include ) {
				// Garantir que a extensão está definida.
				if ( ! isset( $file['extension'] ) ) {
					$file['extension'] = $extension;
				}
				$filtered_files[] = $file;
			}
		}

		return $filtered_files;
	}

	/**
	 * Renderiza a seção de importação de arquivos com progresso em tempo real.
	 */
	private function codir2me_render_import_section() {
		?>
		<div class="codir2me-section">
			<h2><?php esc_html_e( 'Importação de Arquivos R2', 'codirun-codir2me-cdn' ); ?></h2>
			<p><?php esc_html_e( 'Esta ferramenta permite importar imagens armazenadas no R2 diretamente para a biblioteca de mídia do WordPress.', 'codirun-codir2me-cdn' ); ?></p>
			
			<div class="codir2me-cdn-status-cards">
				<div class="codir2me-status-card">
					<div class="codir2me-status-icon">
						<span class="dashicons dashicons-cloud-upload"></span>
					</div>
					<div class="codir2me-status-details">
						<h3><?php esc_html_e( 'Importação R2', 'codirun-codir2me-cdn' ); ?></h3>
						<p class="codir2me-status-description"><?php esc_html_e( 'Importe suas imagens do bucket R2 diretamente para o WordPress', 'codirun-codir2me-cdn' ); ?></p>
					</div>
				</div>
			</div>
			
			<div class="codir2me-import-filters">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'codir2me_scan_action', 'codir2me_scan_nonce' ); ?>
					<input type="hidden" name="action" value="codir2me_start_file_scan">
					
					<h3><?php esc_html_e( 'Filtrar por Formato', 'codirun-codir2me-cdn' ); ?></h3>
					<div class="codir2me-filter-options">
						<label>
							<input type="radio" name="codir2me_import_format" value="all" checked>
							<?php esc_html_e( 'Todas as Imagens (Todos os formatos)', 'codirun-codir2me-cdn' ); ?>
						</label>
						<label>
							<input type="radio" name="codir2me_import_format" value="original">
							<?php esc_html_e( 'Formatos Padrão (JPG, PNG, GIF)', 'codirun-codir2me-cdn' ); ?>
						</label>
						<label>
							<input type="radio" name="codir2me_import_format" value="webp">
							<?php esc_html_e( 'Apenas WebP', 'codirun-codir2me-cdn' ); ?>
						</label>
						<label>
							<input type="radio" name="codir2me_import_format" value="avif">
							<?php esc_html_e( 'Apenas AVIF', 'codirun-codir2me-cdn' ); ?>
						</label>
					</div>
					
					<div class="codir2me-import-actions">
						<button type="submit" class="button button-primary">
							<span class="dashicons dashicons-search"></span>
							<?php esc_html_e( 'Buscar Arquivos no R2', 'codirun-codir2me-cdn' ); ?>
						</button>
					</div>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Renderiza a seção de escaneamento de bucket com progresso em tempo real.
	 *
	 * @param bool $asyncaws_sdk_available Indica se o SDK AsyncAws está disponível.
	 */
	private function codir2me_render_scanner_section( $asyncaws_sdk_available ) {
		?>
		<div class="codir2me-section">
			<h2><?php esc_html_e( 'Escaneamento do Bucket R2', 'codirun-codir2me-cdn' ); ?></h2>
			<p><?php esc_html_e( 'Esta ferramenta analisa os arquivos armazenados no seu bucket R2 e compara com os registros locais do WordPress.', 'codirun-codir2me-cdn' ); ?></p>
			
			<div class="codir2me-cdn-status-cards">
				<div class="codir2me-status-card">
					<div class="codir2me-status-icon">
						<span class="dashicons dashicons-search"></span>
					</div>
					<div class="codir2me-status-details">
						<h3><?php esc_html_e( 'Escaneamento de Bucket', 'codirun-codir2me-cdn' ); ?></h3>
						<p class="codir2me-status-description"><?php esc_html_e( 'Analise e sincronize seu bucket R2 com o WordPress', 'codirun-codir2me-cdn' ); ?></p>
					</div>
				</div>
			</div>
			
			<?php if ( ! $asyncaws_sdk_available ) : ?>
				<div class="notice notice-error">
					<p><?php esc_html_e( 'AWS SDK não encontrado. Por favor, instale o SDK para usar esta funcionalidade.', 'codirun-codir2me-cdn' ); ?></p>
				</div>
			<?php else : ?>
				<form method="post" action="" id="codir2meScanForm">
					<?php wp_nonce_field( 'codir2me_scanner_action' ); ?>
					
					<table class="form-table">
						<tr>
							<th><?php esc_html_e( 'Método de Escaneamento', 'codirun-codir2me-cdn' ); ?></th>
							<td>
								<label>
									<input type="radio" name="codir2me_scan_method" value="ajax" checked>
									<?php esc_html_e( 'Progressivo (recomendado para buckets grandes)', 'codirun-codir2me-cdn' ); ?>
								</label>
								<br><br>
								<label>
									<input type="radio" name="codir2me_scan_method" value="direct">
									<?php esc_html_e( 'Direto (mais rápido para buckets pequenos)', 'codirun-codir2me-cdn' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'O método progressivo mostra o progresso em tempo real, mas pode levar mais tempo. O direto é mais rápido, mas pode causar timeout em buckets grandes.', 'codirun-codir2me-cdn' ); ?></p>
							</td>
						</tr>
					</table>
					
					<p class="submit">
						<input type="submit" name="codir2me_start_scan" class="button button-primary" value="<?php esc_attr_e( 'Iniciar Escaneamento', 'codirun-codir2me-cdn' ); ?>">
					</p>
				</form>
				
				<!-- Área para exibição do progresso do escaneamento AJAX com contadores em tempo real -->
				<div id="codir2me-scan-progress-container" style="display: none;">
					<h3><?php esc_html_e( 'Escaneamento em Progresso', 'codirun-codir2me-cdn' ); ?></h3>
					<div class="codir2me-progress-bar-container">
						<div class="codir2me-progress-bar">
							<div class="codir2me-progress-inner" style="width: 0%;"></div>
						</div>
						<div class="codir2me-progress-text">
							<span id="codir2me-progress-percentage">0%</span> - 
							<span id="codir2me-progress-status"><?php esc_html_e( 'Iniciando escaneamento...', 'codirun-codir2me-cdn' ); ?></span>
						</div>
					</div>
					<div id="codir2me-progress-details" class="codir2me-progress-details">
						<p><?php esc_html_e( 'Páginas processadas:', 'codirun-codir2me-cdn' ); ?> <span id="codir2me-pages-processed">0</span></p>
						<p><?php esc_html_e( 'Arquivos escaneados:', 'codirun-codir2me-cdn' ); ?> <span id="codir2me-files-scanned">0</span></p>
						<p><?php esc_html_e( 'Arquivos encontrados:', 'codirun-codir2me-cdn' ); ?> <span id="codir2me-files-found">0</span></p>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Inicia uma busca assíncrona de arquivos com progresso em tempo real.
	 */
	public function codir2me_start_async_file_scan() {
		// Verificar nonce.
		if ( ! check_admin_referer( 'codir2me_scan_action', 'codir2me_scan_nonce' ) ) {
			// Log para depuração.
			if ( function_exists( 'codir2me_cdn_log' ) ) {
				codir2me_cdn_log( __( 'Falha na verificação do nonce para início de escaneamento', 'codirun-codir2me-cdn' ), 'error' );
			}

			wp_safe_redirect(
				add_query_arg(
					'error',
					rawurlencode( 'security_error' ),
					admin_url( 'admin.php?page=codirun-codir2me-cdn-scanner' )
				)
			);
			exit;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Acesso negado', 'codirun-codir2me-cdn' ) );
		}

		// Obter formato selecionado.
		$format = isset( $_POST['codir2me_import_format'] ) ? sanitize_text_field( wp_unslash( $_POST['codir2me_import_format'] ) ) : 'all';

		// Validar formato.
		$valid_formats = array( 'all', 'original', 'webp', 'avif' );
		if ( ! in_array( $format, $valid_formats, true ) ) {
			$format = 'all';
		}

		// Iniciar o processo de escaneamento assíncrono.
		$scan_id   = uniqid( 'codir2meimport_' );
		$scan_data = array(
			'id'                 => $scan_id,
			'format'             => $format,
			'status'             => 'starting',
			'total_scanned'      => 0,
			'total_found'        => 0,
			'pages_processed'    => 0,
			'start_time'         => time(),
			'continuation_token' => null,
			'files'              => array(),
		);

		// Salvar dados iniciais do escaneamento para importação.
		update_option( 'codir2me_import_scan_' . $scan_id, $scan_data );

		// Log do início do escaneamento.
		if ( get_option( 'codir2me_debug_mode', false ) && function_exists( 'codir2me_cdn_log' ) ) {
			codir2me_cdn_log(
				sprintf(
					'Iniciando escaneamento de importação assíncrono %s com formato: %s',
					$scan_id,
					$format
				),
				'info'
			);
		}

		// Redirecionar para a página de resultados.
		wp_safe_redirect( admin_url( 'admin.php?page=codirun-codir2me-cdn-scanner&scan_id=' . $scan_id ) );
		exit;
	}

	/**
	 * Processa a busca de arquivos via AJAX com progresso em tempo real.
	 *
	 * @throws Exception Se ocorrer erro durante o processamento.
	 */
	public function codir2me_ajax_process_scan() {
		// Verificar nonce.
		if ( ! check_ajax_referer( 'codir2me_scan_process', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Erro de segurança. Por favor, atualize a página.', 'codirun-codir2me-cdn' ) ) );
		}

		// Verificar permissões.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permissão negada.', 'codirun-codir2me-cdn' ) ) );
		}

		// Obter ID do escaneamento.
		$scan_id = isset( $_POST['scan_id'] ) ? sanitize_text_field( wp_unslash( $_POST['scan_id'] ) ) : '';
		if ( empty( $scan_id ) ) {
			wp_send_json_error( array( 'message' => __( 'ID de escaneamento inválido.', 'codirun-codir2me-cdn' ) ) );
		}

		// Obter dados do escaneamento - tentar ambos os tipos.
		$scan_data = get_option( 'codir2me_scan_in_progress_' . $scan_id );
		if ( ! $scan_data ) {
			$scan_data = get_option( 'codir2me_import_scan_' . $scan_id );
		}

		if ( ! $scan_data ) {
			wp_send_json_error( array( 'message' => __( 'Escaneamento não encontrado.', 'codirun-codir2me-cdn' ) ) );
		}

		// Verificar se já está completo.
		if ( 'complete' === $scan_data['status'] ) {
			wp_send_json_success(
				array(
					'status'          => 'complete',
					'total_found'     => isset( $scan_data['total_found'] ) ? $scan_data['total_found'] : 0,
					'total_scanned'   => isset( $scan_data['total_scanned'] ) ? $scan_data['total_scanned'] : 0,
					'pages_processed' => isset( $scan_data['pages_processed'] ) ? $scan_data['pages_processed'] : 0,
				)
			);
			wp_die();
		}

		try {
			// Obter scanner.
			require_once CODIR2ME_CDN_INCLUDES_DIR . 'class-codir2me-scanner.php';
			$scanner = new CODIR2ME_Scanner(
				get_option( 'codir2me_access_key' ),
				get_option( 'codir2me_secret_key' ),
				get_option( 'codir2me_bucket' ),
				get_option( 'codir2me_endpoint' )
			);

			// Inicializar se necessário.
			if ( ! isset( $scan_data['total_scanned'] ) ) {
				$scan_data['total_scanned'] = 0;
			}
			if ( ! isset( $scan_data['total_found'] ) ) {
				$scan_data['total_found'] = 0;
			}
			if ( ! isset( $scan_data['pages_processed'] ) ) {
				$scan_data['pages_processed'] = 0;
			}

			// Processar próxima página.
			if ( strpos( $scan_id, 'codir2meimport_' ) === 0 ) {
				// É um escaneamento de importação - usar método específico.
				$format      = isset( $scan_data['format'] ) ? $scan_data['format'] : 'all';
				$page_result = $this->codir2me_scan_files_by_format_page( $scanner, $format, $scan_data['continuation_token'] );
			} else {
				// É um escaneamento geral.
				$page_result = $scanner->codir2me_scan_bucket( 1000, $scan_data['continuation_token'] );
			}

			if ( $page_result ) {
				// Contar arquivos realmente processados.
				$files_in_page = isset( $page_result['files'] ) ? count( $page_result['files'] ) : 0;

				// Adicionar arquivos únicos.
				if ( isset( $page_result['files'] ) && is_array( $page_result['files'] ) ) {
					$unique_files_added = 0;

					foreach ( $page_result['files'] as $file ) {
						$key = $file['key'];

						// Verificar se já existe.
						$exists = false;
						if ( isset( $scan_data['files'] ) && is_array( $scan_data['files'] ) ) {
							foreach ( $scan_data['files'] as $existing_file ) {
								if ( $existing_file['key'] === $key ) {
									$exists = true;
									break;
								}
							}
						}
						if ( ! $exists ) {
							$scan_data['files'][] = $file;
							++$unique_files_added;
						}
					}

					// Atualizar contadores corretamente.
					$scan_data['total_scanned'] += $files_in_page;
					$scan_data['total_found']    = count( $scan_data['files'] );
				}

				// Atualizar token de continuação.
				$scan_data['continuation_token'] = $page_result['continuation_token'];
				++$scan_data['pages_processed'];

				// Verificar se há mais páginas.
				if ( ! $page_result['is_truncated'] ) {
					$scan_data['status'] = 'complete';
				}

				// Salvar progresso.
				if ( strpos( $scan_id, 'codir2meimport_' ) === 0 ) {
					update_option( 'codir2me_import_scan_' . $scan_id, $scan_data );
				} else {
					update_option( 'codir2me_scan_in_progress_' . $scan_id, $scan_data );
				}

				// Retornar dados de progresso.
				wp_send_json_success(
					array(
						'status'          => $scan_data['status'],
						'total_found'     => $scan_data['total_found'],
						'total_scanned'   => $scan_data['total_scanned'],
						'pages_processed' => $scan_data['pages_processed'],
						'continuation'    => $scan_data['continuation_token'],
					)
				);
			} else {
				throw new Exception( __( 'Erro ao processar página do escaneamento.', 'codirun-codir2me-cdn' ) );
			}
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Manipulador para importação de arquivos.
	 *
	 * @throws Exception Quando o resultado da importação é inválido ou ocorre falha inesperada durante o processo de importação.
	 */
	public function codir2me_handle_file_import() {
		// Verificar nonce.
		if ( ! isset( $_POST['codir2me_import_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['codir2me_import_nonce'] ) ), 'codir2me_import_action' ) ) {
			// Log para depuração.
			if ( function_exists( 'codir2me_cdn_log' ) ) {
				codir2me_cdn_log( __( 'Erro na verificação do nonce ao importar arquivos.', 'codirun-codir2me-cdn' ), 'error' );
			}

			// Redirecionar para a aba scanner com mensagem de erro E nonce.
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'     => 'codirun-codir2me-cdn-scanner',
						'error'    => 'security_error',
						'_wpnonce' => wp_create_nonce( 'codir2me_admin_nonce' ),
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Acesso negado', 'codirun-codir2me-cdn' ) );
		}

		// Verificar se existem arquivos para importar.
		if ( ! isset( $_POST['codir2me_files_to_import'] ) || ! is_array( $_POST['codir2me_files_to_import'] ) || empty( $_POST['codir2me_files_to_import'] ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'     => 'codirun-codir2me-cdn-scanner',
						'error'    => 'no_files',
						'_wpnonce' => wp_create_nonce( 'codir2me_admin_nonce' ),
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		// Sanitizar lista de arquivos.
		$files_to_import = array_map( 'sanitize_text_field', wp_unslash( $_POST['codir2me_files_to_import'] ) );

		// Remover duplicatas da lista de arquivos.
		$files_to_import = array_unique( $files_to_import );

		// Validar que não estamos tentando importar muitos arquivos de uma vez.
		if ( count( $files_to_import ) > 100 ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'     => 'codirun-codir2me-cdn-scanner',
						'error'    => 'too_many_files',
						'_wpnonce' => wp_create_nonce( 'codir2me_admin_nonce' ),
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		// Log para depuração.
		if ( function_exists( 'codir2me_cdn_log' ) ) {
			codir2me_cdn_log(
				sprintf(
					// translators: %d é o número de arquivos.
					__( 'Iniciando importação de %d arquivos únicos do R2.', 'codirun-codir2me-cdn' ),
					count( $files_to_import )
				),
				'info'
			);
		}

		try {
			// Obter scanner.
			require_once CODIR2ME_CDN_INCLUDES_DIR . 'class-codir2me-scanner.php';
			$scanner = new CODIR2ME_Scanner(
				get_option( 'codir2me_access_key' ),
				get_option( 'codir2me_secret_key' ),
				get_option( 'codir2me_bucket' ),
				get_option( 'codir2me_endpoint' )
			);

			// Importar arquivos em lote.
			$results = $scanner->codir2me_batch_import_files( $files_to_import );

			// Validar resultados antes de salvar.
			if ( ! is_array( $results ) || ! isset( $results['success'] ) || ! isset( $results['failed'] ) ) {
				throw new Exception( __( 'Resultado de importação inválido.', 'codirun-codir2me-cdn' ) );
			}

			// Armazenar resultados temporariamente.
			update_option( 'codir2me_import_results', $results, false );

			// Log do resultado.
			if ( function_exists( 'codir2me_cdn_log' ) ) {
				codir2me_cdn_log(
					sprintf(
						// translators: %1$d é o número de itens importados com sucesso e %2$d de falhas.
						__( 'Importação concluída: %1$d arquivos importados com sucesso, %2$d falhas.', 'codirun-codir2me-cdn' ),
						count( $results['success'] ),
						count( $results['failed'] )
					),
					'info'
				);
			}

			// CORREÇÃO: Redirecionar com sucesso mantendo a aba scanner e incluindo nonce.
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'     => 'codirun-codir2me-cdn-scanner',
						'import'   => 'success',
						'_wpnonce' => wp_create_nonce( 'codir2me_admin_nonce' ),
					),
					admin_url( 'admin.php' )
				)
			);
			exit;

		} catch ( Exception $e ) {
			// Log do erro.
			if ( function_exists( 'codir2me_cdn_log' ) ) {
				codir2me_cdn_log(
					__( 'Erro ao importar arquivos do R2: ', 'codirun-codir2me-cdn' ) . $e->getMessage(),
					'error'
				);
			}

			// CORREÇÃO: Redirecionar com erro mantendo na aba scanner e incluindo nonce.
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'     => 'codirun-codir2me-cdn-scanner',
						'error'    => rawurlencode( $e->getMessage() ),
						'_wpnonce' => wp_create_nonce( 'codir2me_admin_nonce' ),
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}
	}

	/**
	 * Processa a importação de arquivos diretamente.
	 *
	 * @param bool $return_results Se verdadeiro, retorna os resultados em vez de exibi-los.
	 * @return array|void Resultados da importação se $return_results for verdadeiro.
	 */
	private function codir2me_process_file_import( $return_results = false ) {
		// Verificar se existem arquivos para importar.
		if ( ! isset( $_POST['codir2me_files_to_import'] ) || ! is_array( $_POST['codir2me_files_to_import'] ) || empty( $_POST['codir2me_files_to_import'] ) ) {
			if ( $return_results ) {
				return array(
					'success' => array(),
					'failed'  => array( 'error' => __( 'Nenhum arquivo selecionado para importação.', 'codirun-codir2me-cdn' ) ),
				);
			}

			?>
			<div class="notice notice-warning">
				<p><?php esc_html_e( 'Nenhum arquivo selecionado para importação.', 'codirun-codir2me-cdn' ); ?></p>
			</div>
			<?php
			return;
		}

		// Verificar nonce (necessário mesmo em funções privadas que processam dados de formulário).
		check_admin_referer( 'codir2me_import_action', 'codir2me_import_nonce' );

		// Sanitizar lista de arquivos.
		$files_to_import = array_map( 'sanitize_text_field', wp_unslash( $_POST['codir2me_files_to_import'] ) );

		try {
			// Obter scanner.
			require_once CODIR2ME_CDN_INCLUDES_DIR . 'class-codir2me-scanner.php';
			$scanner = new CODIR2ME_Scanner(
				get_option( 'codir2me_access_key' ),
				get_option( 'codir2me_secret_key' ),
				get_option( 'codir2me_bucket' ),
				get_option( 'codir2me_endpoint' )
			);

			// Importar arquivos em lote.
			$results = $scanner->codir2me_batch_import_files( $files_to_import );

			if ( $return_results ) {
				return $results;
			}

			$this->codir2me_display_import_results( $results );
		} catch ( Exception $e ) {
			if ( $return_results ) {
				return array(
					'success' => array(),
					'failed'  => array( 'error' => $e->getMessage() ),
				);
			}

			?>
			<div class="notice notice-error">
				<p><?php esc_html_e( 'Erro durante a importação:', 'codirun-codir2me-cdn' ); ?> <?php echo esc_html( $e->getMessage() ); ?></p>
			</div>
			<?php
		}
	}

	/**
	 * Exibe os resultados da importação.
	 *
	 * @param array $results Resultados da importação.
	 */
	private function codir2me_display_import_results( $results ) {
		?>
		<div class="notice notice-success">
			<p>
				<?php
				printf(
					// translators: %d é o número de arquivos importados com sucesso.
					esc_html__( 'Importação concluída! %d arquivos importados com sucesso.', 'codirun-codir2me-cdn' ),
					count( $results['success'] )
				);
				?>
			</p>
		</div>
		
		<?php if ( ! empty( $results['failed'] ) ) : ?>
			<div class="notice notice-warning">
				<p><?php esc_html_e( 'Alguns arquivos não puderam ser importados:', 'codirun-codir2me-cdn' ); ?></p>
				<ul>				
					<?php foreach ( $results['failed'] as $file => $error ) : ?>
						<li><?php echo esc_html( basename( $file ) ); ?>: <?php echo esc_html( $error ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>
		
		<div class="codir2me-import-results">
			<h3><?php esc_html_e( 'Arquivos Importados', 'codirun-codir2me-cdn' ); ?></h3>
			<div class="codir2me-imported-files">
				<?php
				foreach ( $results['success'] as $file => $attachment_id ) :
					$attachment_url    = wp_get_attachment_url( $attachment_id );
					$attachment_thumb  = wp_get_attachment_image_url( $attachment_id, 'thumbnail' );
					$codir2me_is_image = wp_attachment_is_image( $attachment_id );
					?>
					<div class="codir2me-imported-file">
						<?php if ( $codir2me_is_image && $attachment_thumb ) : ?>
							<?php echo wp_get_attachment_image( $attachment_id, 'thumbnail', false, array( 'alt' => esc_attr( basename( $file ) ) ) ); ?>
						<?php else : ?>
							<span class="dashicons dashicons-media-default"></span>
						<?php endif; ?>
						<div class="codir2me-imported-file-details">
							<div><?php echo esc_html( basename( $file ) ); ?></div>
							<a href="<?php echo esc_url( get_edit_post_link( $attachment_id ) ); ?>" target="_blank"><?php esc_html_e( 'Editar na biblioteca', 'codirun-codir2me-cdn' ); ?></a>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Renderiza os resultados de escaneamento em forma de seção.
	 * Melhorar o layout dos resultados de escaneamento.
	 *
	 * @param array $scan_results       Resultados do escaneamento.
	 * @param array $comparison_results Resultados da comparação.
	 * @param array $sync_results       Resultados da sincronização.
	 */
	private function codir2me_render_scan_results_section( $scan_results, $comparison_results = null, $sync_results = null ) {
		?>
		<div class="codir2me-section" id="codir2me-scan-results">
			<h3><?php esc_html_e( 'Resultados do Escaneamento', 'codirun-codir2me-cdn' ); ?></h3>
			
			<div class="codir2me-scan-summary-cards">
				<div class="codir2me-summary-card">
					<div class="codir2me-summary-icon">
						<span class="dashicons dashicons-media-document"></span>
					</div>
					<div class="codir2me-summary-details">
						<h4><?php esc_html_e( 'Total de Arquivos', 'codirun-codir2me-cdn' ); ?></h4>
						<p class="codir2me-summary-count"><?php echo number_format( $scan_results['total_count'] ); ?></p>
						<p class="codir2me-summary-subtext">
							<?php
							printf(
								// translators: %s é o tamanho total formatado.
								esc_html__( '%s de espaço utilizado', 'codirun-codir2me-cdn' ),
								esc_html( $this->codir2me_format_bytes( $scan_results['total_size'] ) )
							);
							?>
						</p>
					</div>
				</div>
				
				<div class="codir2me-summary-card">
					<div class="codir2me-summary-icon">
						<span class="dashicons dashicons-media-code"></span>
					</div>
					<div class="codir2me-summary-details">
						<h4><?php esc_html_e( 'Arquivos Estáticos', 'codirun-codir2me-cdn' ); ?></h4>
						<p class="codir2me-summary-count"><?php echo number_format( $scan_results['static_files']['count'] ); ?></p>
						<p class="codir2me-summary-subtext"><?php echo esc_html( $this->codir2me_format_bytes( $scan_results['static_files']['size'] ) ); ?></p>
					</div>
				</div>
				
				<div class="codir2me-summary-card">
					<div class="codir2me-summary-icon">
						<span class="dashicons dashicons-format-image"></span>
					</div>
					<div class="codir2me-summary-details">
						<h4><?php esc_html_e( 'Imagens', 'codirun-codir2me-cdn' ); ?></h4>
						<p class="codir2me-summary-count"><?php echo number_format( $scan_results['images']['count'] ); ?></p>
						<p class="codir2me-summary-subtext"><?php echo esc_html( $this->codir2me_format_bytes( $scan_results['images']['size'] ) ); ?></p>
					</div>
				</div>
			</div>
			
			<div class="codir2me-scan-details">
				<div class="codir2me-scan-details-column">
					<h4><?php esc_html_e( 'Detalhes de Arquivos Estáticos', 'codirun-codir2me-cdn' ); ?></h4>
					<div class="codir2me-details-card">
						<table class="codir2me-details-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Extensão', 'codirun-codir2me-cdn' ); ?></th>
									<th><?php esc_html_e( 'Quantidade', 'codirun-codir2me-cdn' ); ?></th>
									<th><?php esc_html_e( 'Tamanho', 'codirun-codir2me-cdn' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php if ( isset( $scan_results['static_files']['extensions'] ) && is_array( $scan_results['static_files']['extensions'] ) ) : ?>
									<?php foreach ( $scan_results['static_files']['extensions'] as $ext => $info ) : ?>
									<tr>
										<td><span class="codir2me-ext-badge">.<?php echo esc_html( $ext ); ?></span></td>
										<td><?php echo number_format( $info['count'] ); ?></td>
										<td><?php echo esc_html( $this->codir2me_format_bytes( $info['size'] ) ); ?></td>
									</tr>
									<?php endforeach; ?>
								<?php else : ?>
									<tr>
										<td colspan="3"><?php esc_html_e( 'Nenhum arquivo estático encontrado', 'codirun-codir2me-cdn' ); ?></td>
									</tr>
								<?php endif; ?>
							</tbody>
						</table>
					</div>
				</div>
				
				<div class="codir2me-scan-details-column">
					<h4><?php esc_html_e( 'Detalhes de Imagens', 'codirun-codir2me-cdn' ); ?></h4>
					<div class="codir2me-details-card">
						<div class="codir2me-image-summary">
							<div class="codir2me-image-stat">
								<span class="codir2me-stat-label"><?php esc_html_e( 'Originais:', 'codirun-codir2me-cdn' ); ?></span>
								<span class="codir2me-stat-value"><?php echo number_format( $scan_results['images']['originals']['count'] ); ?></span>
								<span class="codir2me-stat-subtext"><?php echo esc_html( $this->codir2me_format_bytes( $scan_results['images']['originals']['size'] ) ); ?></span>
							</div>
							<div class="codir2me-image-stat">
								<span class="codir2me-stat-label"><?php esc_html_e( 'Miniaturas:', 'codirun-codir2me-cdn' ); ?></span>
								<span class="codir2me-stat-value"><?php echo number_format( $scan_results['images']['thumbnails']['count'] ); ?></span>
								<span class="codir2me-stat-subtext"><?php echo esc_html( $this->codir2me_format_bytes( $scan_results['images']['thumbnails']['size'] ) ); ?></span>
							</div>
						</div>
						
						<table class="codir2me-details-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Formato', 'codirun-codir2me-cdn' ); ?></th>
									<th><?php esc_html_e( 'Quantidade', 'codirun-codir2me-cdn' ); ?></th>
									<th><?php esc_html_e( 'Tamanho', 'codirun-codir2me-cdn' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php if ( isset( $scan_results['images']['extensions'] ) && is_array( $scan_results['images']['extensions'] ) ) : ?>
									<?php foreach ( $scan_results['images']['extensions'] as $ext => $info ) : ?>
									<tr>
										<td><span class="codir2me-ext-badge codir2me-ext-image">.<?php echo esc_html( $ext ); ?></span></td>
										<td><?php echo number_format( $info['count'] ); ?></td>
										<td><?php echo esc_html( $this->codir2me_format_bytes( $info['size'] ) ); ?></td>
									</tr>
									<?php endforeach; ?>
								<?php else : ?>
									<tr>
										<td colspan="3"><?php esc_html_e( 'Nenhuma imagem encontrada', 'codirun-codir2me-cdn' ); ?></td>
									</tr>
								<?php endif; ?>
							</tbody>
						</table>
					</div>
				</div>
			</div>
			
			<?php if ( ! $comparison_results ) : ?>
			<form method="post" action="" id="codir2meSyncForm">
				<?php wp_nonce_field( 'codir2me_scanner_action' ); ?>
				<input type="hidden" name="scan_data" value="<?php echo esc_attr( wp_json_encode( $scan_results ) ); ?>">
				
				<div class="codir2me-action-card">
					<h4><?php esc_html_e( 'Sincronizar Registros', 'codirun-codir2me-cdn' ); ?></h4>
					<p><?php esc_html_e( 'Compare os arquivos no R2 com os registros locais do WordPress:', 'codirun-codir2me-cdn' ); ?></p>
					
					<div class="codir2me-sync-options">
						<label class="codir2me-checkbox-label">
							<input type="checkbox" name="codir2me_add_missing" value="1" checked> 
							<span class="codir2me-checkbox-text"><?php esc_html_e( 'Adicionar arquivos encontrados no R2 aos registros locais', 'codirun-codir2me-cdn' ); ?></span>
						</label>
						
						<label class="codir2me-checkbox-label">
							<input type="checkbox" name="codir2me_remove_nonexistent" value="1" checked> 
							<span class="codir2me-checkbox-text"><?php esc_html_e( 'Remover dos registros locais arquivos que não existem no R2', 'codirun-codir2me-cdn' ); ?></span>
						</label>
					</div>
					
					<div class="codir2me-warning-message">
						<span class="dashicons dashicons-info"></span> 
						<?php esc_html_e( 'É recomendado manter ambas as opções marcadas para garantir uma sincronização completa.', 'codirun-codir2me-cdn' ); ?>
					</div>
					
					<div class="codir2me-action-buttons">
						<input type="submit" name="codir2me_sync_records" class="button button-primary" value="<?php esc_attr_e( 'Comparar e Sincronizar', 'codirun-codir2me-cdn' ); ?>">
					</div>
				</div>
			</form>
			<?php endif; ?>
			
			<?php if ( $comparison_results ) : ?>
			<div class="codir2me-comparison-results">
				<h4><?php esc_html_e( 'Resultados da Comparação', 'codirun-codir2me-cdn' ); ?></h4>
				
				<div class="codir2me-comparison-summary-cards">
					<div class="codir2me-summary-card">
						<div class="codir2me-summary-icon">
							<span class="dashicons dashicons-cloud"></span>
						</div>
						<div class="codir2me-summary-details">
							<h4><?php esc_html_e( 'Arquivos no R2', 'codirun-codir2me-cdn' ); ?></h4>
							<p class="codir2me-summary-count"><?php echo number_format( $comparison_results['codir2me_total'] ); ?></p>
						</div>
					</div>
					
					<div class="codir2me-summary-card">
						<div class="codir2me-summary-icon">
							<span class="dashicons dashicons-wordpress"></span>
						</div>
						<div class="codir2me-summary-details">
							<h4><?php esc_html_e( 'Registros Locais', 'codirun-codir2me-cdn' ); ?></h4>
							<p class="codir2me-summary-count"><?php echo number_format( $comparison_results['local_total'] ); ?></p>
						</div>
					</div>
				</div>
			</div>
						
			<div class="codir2me-comparison-details">
					<div class="codir2me-details-card">
						<h5><?php esc_html_e( 'Arquivos Estáticos', 'codirun-codir2me-cdn' ); ?></h5>
						<div class="codir2me-comparison-stat-row">
							<div class="codir2me-comparison-stat">
								<span class="codir2me-stat-label"><?php esc_html_e( 'No R2:', 'codirun-codir2me-cdn' ); ?></span>
								<span class="codir2me-stat-value"><?php echo number_format( $comparison_results['static_files']['codir2me_total'] ); ?></span>
							</div>
							<div class="codir2me-comparison-stat">
								<span class="codir2me-stat-label"><?php esc_html_e( 'Nos registros:', 'codirun-codir2me-cdn' ); ?></span>
								<span class="codir2me-stat-value"><?php echo number_format( $comparison_results['static_files']['local_total'] ); ?></span>
							</div>
						</div>
						<div class="codir2me-comparison-diff">
							<div class="codir2me-diff-item codir2me-diff-add">
								<span class="dashicons dashicons-plus-alt"></span>
								<span class="codir2me-diff-label"><?php esc_html_e( 'No R2 não registrados localmente:', 'codirun-codir2me-cdn' ); ?></span>
								<span class="codir2me-diff-count"><?php echo number_format( $comparison_results['static_files']['in_codir2me_not_in_local']['count'] ); ?></span>
							</div>
							<div class="codir2me-diff-item codir2me-diff-remove">
								<span class="dashicons dashicons-minus"></span>
								<span class="codir2me-diff-label"><?php esc_html_e( 'Nos registros não encontrados no R2:', 'codirun-codir2me-cdn' ); ?></span>
								<span class="codir2me-diff-count"><?php echo number_format( $comparison_results['static_files']['in_local_not_in_codir2me']['count'] ); ?></span>
							</div>
						</div>
					</div>
					
					<div class="codir2me-details-card">
						<h5><?php esc_html_e( 'Imagens', 'codirun-codir2me-cdn' ); ?></h5>
						<div class="codir2me-comparison-stat-row">
							<div class="codir2me-comparison-stat">
								<span class="codir2me-stat-label"><?php esc_html_e( 'No R2:', 'codirun-codir2me-cdn' ); ?></span>
								<span class="codir2me-stat-value"><?php echo number_format( $comparison_results['images']['codir2me_total'] ); ?></span>
							</div>
							<div class="codir2me-comparison-stat">
								<span class="codir2me-stat-label"><?php esc_html_e( 'Nos registros:', 'codirun-codir2me-cdn' ); ?></span>
								<span class="codir2me-stat-value"><?php echo number_format( $comparison_results['images']['local_total'] ); ?></span>
							</div>
						</div>
						<div class="codir2me-comparison-diff">
							<div class="codir2me-diff-item codir2me-diff-add">
								<span class="dashicons dashicons-plus-alt"></span>
								<span class="codir2me-diff-label"><?php esc_html_e( 'No R2 não registradas localmente:', 'codirun-codir2me-cdn' ); ?></span>
								<span class="codir2me-diff-count"><?php echo number_format( $comparison_results['images']['in_codir2me_not_in_local']['count'] ); ?></span>
							</div>
							<div class="codir2me-diff-item codir2me-diff-remove">
								<span class="dashicons dashicons-minus"></span>
								<span class="codir2me-diff-label"><?php esc_html_e( 'Nos registros não encontradas no R2:', 'codirun-codir2me-cdn' ); ?></span>
								<span class="codir2me-diff-count"><?php echo number_format( $comparison_results['images']['in_local_not_in_codir2me']['count'] ); ?></span>
							</div>
						</div>
					</div>
				</div>
			</div>
			<?php endif; ?>
			
			<?php if ( $sync_results ) : ?>
			<div class="codir2me-sync-results">
				<h4><?php esc_html_e( 'Resultados da Sincronização', 'codirun-codir2me-cdn' ); ?></h4>
				
				<div class="codir2me-sync-summary-cards">
					<div class="codir2me-summary-card">
						<div class="codir2me-summary-icon codir2me-icon-success">
							<span class="dashicons dashicons-yes-alt"></span>
						</div>
						<div class="codir2me-summary-details">
							<h4><?php esc_html_e( 'Sincronização Completa', 'codirun-codir2me-cdn' ); ?></h4>
							<p class="codir2me-summary-subtext"><?php esc_html_e( 'Os registros foram sincronizados com sucesso!', 'codirun-codir2me-cdn' ); ?></p>
						</div>
					</div>
				</div>
				
				<div class="codir2me-sync-details">
					<div class="codir2me-details-card">
						<h5><?php esc_html_e( 'Arquivos Estáticos', 'codirun-codir2me-cdn' ); ?></h5>
						<div class="codir2me-sync-stat-row">
							<div class="codir2me-sync-stat codir2me-stat-added">
								<span class="dashicons dashicons-plus-alt"></span>
								<span class="codir2me-stat-label"><?php esc_html_e( 'Adicionados:', 'codirun-codir2me-cdn' ); ?></span>
								<span class="codir2me-stat-value"><?php echo number_format( $sync_results['static_files']['added'] ); ?></span>
							</div>
							<div class="codir2me-sync-stat codir2me-stat-removed">
								<span class="dashicons dashicons-minus"></span>
								<span class="codir2me-stat-label"><?php esc_html_e( 'Removidos:', 'codirun-codir2me-cdn' ); ?></span>
								<span class="codir2me-stat-value"><?php echo number_format( $sync_results['static_files']['removed'] ); ?></span>
							</div>
						</div>
					</div>
					
					<div class="codir2me-details-card">
						<h5><?php esc_html_e( 'Imagens', 'codirun-codir2me-cdn' ); ?></h5>
						<div class="codir2me-sync-stat-row">
							<div class="codir2me-sync-stat codir2me-stat-added">
								<span class="dashicons dashicons-plus-alt"></span>
								<span class="codir2me-stat-label"><?php esc_html_e( 'Adicionadas:', 'codirun-codir2me-cdn' ); ?></span>
								<span class="codir2me-stat-value"><?php echo number_format( $sync_results['images']['added'] ); ?></span>
							</div>
							<div class="codir2me-sync-stat codir2me-stat-removed">
								<span class="dashicons dashicons-minus"></span>
								<span class="codir2me-stat-label"><?php esc_html_e( 'Removidas:', 'codirun-codir2me-cdn' ); ?></span>
								<span class="codir2me-stat-value"><?php echo number_format( $sync_results['images']['removed'] ); ?></span>
							</div>
						</div>
					</div>
				</div>
				
				<div class="codir2me-sync-success-message">
					<p><?php esc_html_e( 'O plugin agora tem informações atualizadas sobre os arquivos armazenados no R2.', 'codirun-codir2me-cdn' ); ?></p>
					<p><a href="<?php echo esc_url( esc_url( admin_url( 'admin.php?page=codirun-codir2me-cdn-scanner' ) ) ); ?>" class="button button-primary"><?php esc_html_e( 'Recarregar Página', 'codirun-codir2me-cdn' ); ?></a></p>
				</div>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Renderiza a barra lateral.
	 */
	private function codir2me_render_sidebar() {
		?>
		<div class="codir2me-sidebar">
			<div class="codir2me-sidebar-widget">
				<h3><?php esc_html_e( 'Sobre a Importação', 'codirun-codir2me-cdn' ); ?></h3>
				<div class="codir2me-widget-content">
					<p><?php esc_html_e( 'A ferramenta de importação permite trazer de volta para o WordPress imagens que você já tem no R2, mas que não estão na biblioteca de mídia.', 'codirun-codir2me-cdn' ); ?></p>
					<p><?php esc_html_e( 'Use esta função quando:', 'codirun-codir2me-cdn' ); ?></p>
					<ul class="codir2me-tips-list">
						<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Você perdeu imagens do WordPress mas elas ainda existem no R2', 'codirun-codir2me-cdn' ); ?></li>
						<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Deseja importar imagens de outro site que usa o mesmo bucket', 'codirun-codir2me-cdn' ); ?></li>
						<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Precisa recuperar arquivos após uma migração', 'codirun-codir2me-cdn' ); ?></li>
					</ul>
				</div>
			</div>
			
			<div class="codir2me-sidebar-widget">
				<h3><?php esc_html_e( 'Sobre o Escaneamento', 'codirun-codir2me-cdn' ); ?></h3>
				<div class="codir2me-widget-content">
					<p><?php esc_html_e( 'Esta ferramenta permite escanear diretamente o bucket do Cloudflare R2 para listar e contar todos os arquivos armazenados.', 'codirun-codir2me-cdn' ); ?></p>
					<p><?php esc_html_e( 'Use esta ferramenta para:', 'codirun-codir2me-cdn' ); ?></p>
					<ul class="codir2me-tips-list">
						<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Verificar o número real de arquivos no R2', 'codirun-codir2me-cdn' ); ?></li>
						<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Comparar com os registros locais do WordPress', 'codirun-codir2me-cdn' ); ?></li>
						<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Sincronizar quando os registros estiverem desatualizados', 'codirun-codir2me-cdn' ); ?></li>
						<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Detectar arquivos órfãos no bucket', 'codirun-codir2me-cdn' ); ?></li>
					</ul>
				</div>
			</div>
			
			<div class="codir2me-sidebar-widget">
				<h3><?php esc_html_e( 'Dicas para Buckets Grandes', 'codirun-codir2me-cdn' ); ?></h3>
				<div class="codir2me-widget-content">
					<ul class="codir2me-tips-list">
						<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Para buckets com muitos arquivos, use o método progressivo', 'codirun-codir2me-cdn' ); ?></li>
						<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Filtrar por formato reduz o tempo de busca', 'codirun-codir2me-cdn' ); ?></li>
						<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Para importar muitas imagens, faça em lotes menores', 'codirun-codir2me-cdn' ); ?></li>
						<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Use o filtro de arquivos para encontrar imagens específicas', 'codirun-codir2me-cdn' ); ?></li>
					</ul>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Executa o escaneamento completo (método direto).
	 *
	 * @return array Resultados do escaneamento.
	 */
	private function codir2me_do_scan() {
		// Verificar nonce.
		if ( ! check_admin_referer( 'codir2me_scanner_action' ) ) {
			add_action(
				'admin_notices',
				function () {
					?>
			<div class="notice notice-error is-dismissible">
					<p><?php esc_html_e( 'Erro de segurança. Nonce inválido.', 'codirun-codir2me-cdn' ); ?></p>
			</div>
					<?php
				}
			);
			return null;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			add_action(
				'admin_notices',
				function () {
					?>
			<div class="notice notice-error is-dismissible">
					<p><?php esc_html_e( 'Permissão negada.', 'codirun-codir2me-cdn' ); ?></p>
			</div>
					<?php
				}
			);
			return null;
		}

		try {
			require_once CODIR2ME_CDN_INCLUDES_DIR . 'class-codir2me-scanner.php';
			$scanner = new CODIR2ME_Scanner(
				get_option( 'codir2me_access_key' ),
				get_option( 'codir2me_secret_key' ),
				get_option( 'codir2me_bucket' ),
				get_option( 'codir2me_endpoint' )
			);

			// Verificar se existe o parâmetro do método.
			if ( isset( $_POST['codir2me_scan_method'] ) ) {
				$scan_method = sanitize_text_field( wp_unslash( $_POST['codir2me_scan_method'] ) );

				// Se o método for AJAX, criar escaneamento progressivo.
				if ( 'ajax' === $scan_method ) {
					// Criar ID único para o escaneamento.
					$scan_id   = uniqid( 'codir2mescan_' );
					$scan_data = array(
						'id'                 => $scan_id,
						'status'             => 'starting',
						'total_scanned'      => 0,
						'total_found'        => 0,
						'pages_processed'    => 0,
						'start_time'         => time(),
						'continuation_token' => null,
						'files'              => array(),
					);

					// Salvar dados iniciais do escaneamento.
					update_option( 'codir2me_scan_in_progress_' . $scan_id, $scan_data );

					// Redirecionar para mostrar progresso.
					wp_safe_redirect( admin_url( 'admin.php?page=codirun-codir2me-cdn-scanner&scan_id=' . $scan_id ) );
					exit;
				}
			}

			// Log do início do escaneamento direto.
			if ( get_option( 'codir2me_debug_mode', false ) && function_exists( 'codir2me_cdn_log' ) ) {
				codir2me_cdn_log( 'Iniciando escaneamento direto do bucket', 'info' );
			}

			// Usar o método otimizado com controle de duplicação.
			$result = $scanner->codir2me_scan_complete( 1000, null );

			// Log do resultado.
			if ( get_option( 'codir2me_debug_mode', false ) && function_exists( 'codir2me_cdn_log' ) ) {
				codir2me_cdn_log(
					sprintf(
						'Escaneamento direto concluído: %d arquivos encontrados',
						$result['total_count']
					),
					'info'
				);
			}

			return $result;

		} catch ( Exception $e ) {
			// Log do erro.
			if ( function_exists( 'codir2me_cdn_log' ) ) {
				codir2me_cdn_log( 'Erro no escaneamento direto: ' . $e->getMessage(), 'error' );
			}

			add_action(
				'admin_notices',
				function () use ( $e ) {
					?>
			<div class="notice notice-error is-dismissible">
					<p><?php esc_html_e( 'Erro ao escanear bucket R2:', 'codirun-codir2me-cdn' ); ?> <?php echo esc_html( $e->getMessage() ); ?></p>
			</div>
					<?php
				}
			);

			return null;
		}
	}

	/**
	 * Compara os resultados do escaneamento com os registros locais.
	 *
	 * @param array $scan_results Resultados do escaneamento.
	 * @return array Resultados da comparação.
	 */
	private function codir2me_do_comparison( $scan_results ) {
		try {
			require_once CODIR2ME_CDN_INCLUDES_DIR . 'class-codir2me-scanner.php';
			$scanner = new CODIR2ME_Scanner(
				get_option( 'codir2me_access_key' ),
				get_option( 'codir2me_secret_key' ),
				get_option( 'codir2me_bucket' ),
				get_option( 'codir2me_endpoint' )
			);

			// Comparar com registros locais.
			return $scanner->codir2me_compare_with_local_records( $scan_results );

		} catch ( Exception $e ) {
			add_action(
				'admin_notices',
				function () use ( $e ) {
					?>
				<div class="notice notice-error is-dismissible">
					<p><?php esc_html_e( 'Erro ao comparar arquivos:', 'codirun-codir2me-cdn' ); ?> <?php echo esc_html( $e->getMessage() ); ?></p>
				</div>
					<?php
				}
			);

			return null;
		}
	}

	/**
	 * Sincroniza os registros locais com base nos resultados da comparação.
	 *
	 * @param array $comparison_results Resultados da comparação.
	 * @param bool  $add_missing        Se deve adicionar arquivos ausentes.
	 * @param bool  $remove_nonexistent Se deve remover arquivos inexistentes.
	 * @return array Resultados da sincronização.
	 */
	private function codir2me_do_sync( $comparison_results, $add_missing, $remove_nonexistent ) {
		try {
			require_once CODIR2ME_CDN_INCLUDES_DIR . 'class-codir2me-scanner.php';
			$scanner = new CODIR2ME_Scanner(
				get_option( 'codir2me_access_key' ),
				get_option( 'codir2me_secret_key' ),
				get_option( 'codir2me_bucket' ),
				get_option( 'codir2me_endpoint' )
			);

			// Sincronizar registros - utilizando a versão otimizada.
			$sync_results = $scanner->codir2me_sync_local_records( $comparison_results, $add_missing, $remove_nonexistent );

			// Forçar atualização dos contadores de estatísticas após a sincronização.
			if ( $sync_results['static_files']['added'] > 0 || $sync_results['static_files']['removed'] > 0 ||
				$sync_results['images']['added'] > 0 || $sync_results['images']['removed'] > 0 ) {

				// Recalcular contadores de imagens.
				$uploaded_images = get_option( 'codir2me_uploaded_images', array() );
				$original_count  = 0;
				$thumbnail_count = 0;

				foreach ( $uploaded_images as $path ) {
					// Verificar se é uma miniatura com base no nome do arquivo.
					if ( preg_match( '/-\d+x\d+\.[a-zA-Z]+$/', $path ) ||
						preg_match( '/-[a-zA-Z_]+\.[a-zA-Z]+$/', $path ) ) {
						++$thumbnail_count;
					} else {
						++$original_count;
					}
				}

				// Atualizar contadores.
				update_option( 'codir2me_original_images_count', $original_count );
				update_option( 'codir2me_thumbnail_images_count', $thumbnail_count );

				// Atualizar opção que indica a conclusão do upload (se relevante).
				$args = array(
					'post_type'      => 'attachment',
					'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ),
					'post_status'    => 'inherit',
					'posts_per_page' => -1,
					'fields'         => 'ids',
				);

				$query               = new WP_Query( $args );
				$total_media_library = count( $query->posts );

				if ( $original_count >= $total_media_library ) {
					update_option( 'codir2me_all_images_sent', true );
					update_option( 'codir2me_missing_images_count', 0 );
				} else {
					update_option( 'codir2me_all_images_sent', false );
					update_option( 'codir2me_missing_images_count', $total_media_library - $original_count );
				}
			}

			return $sync_results;

		} catch ( Exception $e ) {
			add_action(
				'admin_notices',
				function () use ( $e ) {
					?>
				<div class="notice notice-error is-dismissible">
					<p><?php esc_html_e( 'Erro ao sincronizar registros:', 'codirun-codir2me-cdn' ); ?> <?php echo esc_html( $e->getMessage() ); ?></p>
				</div>
					<?php
				}
			);

			return null;
		}
	}

	/**
	 * Função auxiliar para formatar bytes.
	 *
	 * @param int $bytes     Valor em bytes.
	 * @param int $precision Precisão decimal.
	 * @return string Valor formatado.
	 */
	private function codir2me_format_bytes( $bytes, $precision = 2 ) {
		$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );

		$bytes = max( $bytes, 0 );
		$pow   = floor( ( $bytes ? log( $bytes ) : 0 ) / log( 1024 ) );
		$pow   = min( $pow, count( $units ) - 1 );

		$bytes /= pow( 1024, $pow );

		return round( $bytes, $precision ) . ' ' . $units[ $pow ];
	}
}
