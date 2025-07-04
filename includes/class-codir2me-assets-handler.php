<?php
/**
 * Classe responsável por manipular arquivos estáticos
 *
 * @package Codirun_R2_Media_Static_CDN
 */

// Evitar acesso direto ao arquivo.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classe responsável por manipular arquivos estáticos
 */
class CODIR2ME_Assets_Handler {
	/**
	 * URL do CDN
	 *
	 * @var string
	 */
	private $codir2me_cdn_url;

	/**
	 * Lista de arquivos já enviados
	 *
	 * @var array
	 */
	private $uploaded_files = array();

	/**
	 * Lista de arquivos pendentes para envio
	 *
	 * @var array
	 */
	private $pending_files = array();

	/**
	 * Status se o manipulador está ativo
	 *
	 * @var bool
	 */
	private $is_active = false;

	/**
	 * Construtor da classe
	 *
	 * @param string $codir2me_cdn_url URL do CDN.
	 */
	public function __construct( $codir2me_cdn_url ) {
		$this->codir2me_cdn_url = $codir2me_cdn_url;
		$this->codir2me_load_files_list();
	}

	/**
	 * Define se o manipulador está ativo
	 *
	 * @param bool $active Se o manipulador deve estar ativo.
	 * @return void
	 */
	public function codir2me_set_active( $active ) {
		$this->is_active = (bool) $active;
	}

	/**
	 * Obtém o status ativo do manipulador
	 *
	 * @return bool True se o manipulador estiver ativo, False caso contrário.
	 */
	public function codir2me_is_active() {
		return $this->is_active;
	}

	/**
	 * Carrega a lista de arquivos já enviados e pendentes
	 *
	 * @return void
	 */
	private function codir2me_load_files_list() {
		// Carregar a lista de arquivos já enviados.
		$uploaded = get_option( 'codir2me_uploaded_files' );
		if ( $uploaded ) {
			$this->uploaded_files = $uploaded;
		}

		// Carregar a lista de arquivos pendentes.
		$pending = get_option( 'codir2me_pending_files' );
		if ( $pending ) {
			$this->pending_files = $pending;
		}
	}

	/**
	 * Inicializa os filtros de URL
	 *
	 * @return void
	 */
	public function codir2me_init_url_filters() {
		if ( $this->is_active ) {
			add_filter( 'style_loader_src', array( $this, 'codir2me_modify_asset_url' ), 10, 2 );
			add_filter( 'script_loader_src', array( $this, 'codir2me_modify_asset_url' ), 10, 2 );

			// Adicionar filtro para modificar URL de fontes e SVG no conteúdo.
			add_filter( 'the_content', array( $this, 'codir2me_modify_content_urls' ) );
			add_filter( 'widget_text_content', array( $this, 'codir2me_modify_content_urls' ) );

			// Modificar URL de arquivos de fonte no CSS.
			add_filter( 'wp_get_custom_css', array( $this, 'codir2me_modify_content_urls' ) );
		}
	}

	/**
	 * Modifica a URL dos assets para usar o CDN
	 *
	 * @param string $src URL do asset.
	 * @param string $handle Handle do asset (requerido pelo filtro do WordPress, mas não utilizado).
	 * @return string URL modificada ou original.
	 */
	public function codir2me_modify_asset_url( $src, $handle = '' ) {
		// Verificação de nonce para requisições administrativas.
		if ( is_admin() && isset( $_REQUEST['_wpnonce'] ) ) {
			if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ), 'codir2me_admin_action' ) ) {
				return $src;
			}
		}

		// Verificação simples para usar o parâmetro.
		if ( empty( $handle ) ) {
			$handle = '';
		}
		// Verificar se é um arquivo local (não externo).
		if ( strpos( $src, home_url() ) !== false ) {
			// Verificar se não tem parâmetros php ou ajax.
			if ( strpos( $src, '.php' ) === false && strpos( $src, 'ajax' ) === false ) {
				// Extrair o caminho relativo do arquivo.
				$parsed_url = wp_parse_url( $src );
				$path       = ltrim( $parsed_url['path'], '/' );

				// Se este arquivo já foi enviado para o R2.
				if ( in_array( $path, $this->uploaded_files, true ) ) {
					// Substituir a URL pelo CDN.
					$cdn_url = rtrim( $this->codir2me_cdn_url, '/' ) . '/' . $path;

					// Preservar query string se existir.
					if ( isset( $parsed_url['query'] ) ) {
						$cdn_url .= '?' . $parsed_url['query'];
					}

					return $cdn_url;
				}
			}
		}

		return $src;
	}

	/**
	 * Modifica URLs no conteúdo para usar o CDN
	 *
	 * @param string $content Conteúdo HTML.
	 * @return string Conteúdo com URLs modificadas.
	 */
	public function codir2me_modify_content_urls( $content ) {
		if ( ! $this->is_active ) {
			return $content;
		}

		// Verificar se há permissão para modificar conteúdo.
		if ( ! current_user_can( 'edit_posts' ) && is_admin() ) {
			return $content;
		}

		$home_url = home_url();
		$cdn_url  = rtrim( $this->codir2me_cdn_url, '/' );

		// Modificar URLs de fontes e outros assets estáticos.
		$content = preg_replace_callback(
			'/url\([\'"]?(' . preg_quote( $home_url, '/' ) . '[^\'")]*\.(woff2?|ttf|otf|eot|svg))[\'"]?\)/i',
			function ( $matches ) use ( $cdn_url, $home_url ) {
				$url  = $matches[1];
				$path = str_replace( $home_url, '', $url );
				$path = ltrim( $path, '/' );

				if ( in_array( $path, $this->uploaded_files, true ) ) {
					return str_replace( $matches[1], $cdn_url . '/' . $path, $matches[0] );
				}

				return $matches[0];
			},
			$content
		);

		return $content;
	}

	/**
	 * Obtém a lista de arquivos enviados
	 *
	 * @return array Lista de arquivos já enviados.
	 */
	public function codir2me_get_uploaded_files() {
		return $this->uploaded_files;
	}

	/**
	 * Obtém a lista de arquivos pendentes
	 *
	 * @return array Lista de arquivos pendentes.
	 */
	public function codir2me_get_pending_files() {
		return $this->pending_files;
	}

	/**
	 * Adiciona um arquivo à lista de enviados
	 *
	 * @param string $file_path Caminho do arquivo.
	 * @return void
	 */
	public function codir2me_add_uploaded_file( $file_path ) {
		if ( ! in_array( $file_path, $this->uploaded_files, true ) ) {
			$this->uploaded_files[] = $file_path;
			update_option( 'codir2me_uploaded_files', $this->uploaded_files );
		}
	}

	/**
	 * Remove um arquivo da lista de enviados
	 *
	 * @param string $file_path Caminho do arquivo.
	 * @return void
	 */
	public function codir2me_remove_uploaded_file( $file_path ) {
		$key = array_search( $file_path, $this->uploaded_files, true );
		if ( false !== $key ) {
			unset( $this->uploaded_files[ $key ] );
			$this->uploaded_files = array_values( $this->uploaded_files );
			update_option( 'codir2me_uploaded_files', $this->uploaded_files );
		}
	}

	/**
	 * Adiciona um arquivo à lista de pendentes
	 *
	 * @param string $file_path Caminho do arquivo.
	 * @return void
	 */
	public function codir2me_add_pending_file( $file_path ) {
		// Verificar permissões antes de adicionar arquivo pendente.
		if ( ! current_user_can( 'manage_options' ) && is_admin() ) {
			return;
		}

		if ( ! in_array( $file_path, $this->pending_files, true ) ) {
			$this->pending_files[] = $file_path;
			update_option( 'codir2me_pending_files', $this->pending_files );
		}
	}

	/**
	 * Remove um arquivo da lista de pendentes
	 *
	 * @param string $file_path Caminho do arquivo.
	 * @return void
	 */
	public function codir2me_remove_pending_file( $file_path ) {
		// Verificar permissões antes de remover arquivo pendente.
		if ( ! current_user_can( 'manage_options' ) && is_admin() ) {
			return;
		}

		$key = array_search( $file_path, $this->pending_files, true );
		if ( false !== $key ) {
			unset( $this->pending_files[ $key ] );
			$this->pending_files = array_values( $this->pending_files );
			update_option( 'codir2me_pending_files', $this->pending_files );
		}
	}

	/**
	 * Limpa todas as listas de arquivos
	 *
	 * @return void
	 */
	public function codir2me_clear_all_files() {
		// Verificar permissões antes de limpar arquivos.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$this->uploaded_files = array();
		$this->pending_files  = array();

		update_option( 'codir2me_uploaded_files', array() );
		update_option( 'codir2me_pending_files', array() );
	}

	/**
	 * Obtém estatísticas dos arquivos
	 *
	 * @return array Array com estatísticas dos arquivos.
	 */
	public function codir2me_get_file_stats() {
		return array(
			'uploaded_count' => count( $this->uploaded_files ),
			'pending_count'  => count( $this->pending_files ),
			'total_count'    => count( $this->uploaded_files ) + count( $this->pending_files ),
		);
	}

	/**
	 * Escaneia arquivos estáticos no site
	 *
	 * @return array Status do upload com informações sobre os arquivos encontrados.
	 */
	public function codir2me_scan_files() {
		if ( function_exists( 'codir2me_cdn_log' ) ) {
			codir2me_cdn_log( __( 'Iniciando escaneamento de arquivos estáticos', 'codirun-codir2me-cdn' ) );
		}

		// Verificar permissões.
		if ( ! current_user_can( 'manage_options' ) ) {
			return array();
		}

		// Obter lista de arquivos já enviados para evitar duplicação.
		$this->uploaded_files = get_option( 'codir2me_uploaded_files', array() );
		$uploaded_files_set   = array_flip( $this->uploaded_files ); // Para busca rápida.

		// Limpar arquivos pendentes e status anterior.
		$this->pending_files = array();

		// Escanear diretórios seguros.
		$this->codir2me_scan_safe_directories();

		// Filtrar arquivos já enviados da lista de pendentes.
		$filtered_pending = array();
		foreach ( $this->pending_files as $file_info ) {
			// Verificar se o arquivo já foi enviado.
			if ( ! isset( $uploaded_files_set[ $file_info['relative_path'] ] ) ) {
				$filtered_pending[] = $file_info;
			}
		}

		// Atualizar lista de pendentes apenas com arquivos não enviados.
		$this->pending_files = $filtered_pending;

		// Salvar a lista de arquivos pendentes.
		update_option( 'codir2me_pending_files', $this->pending_files );

		// Calcular e salvar status de upload.
		$total_files   = count( $this->pending_files );
		$batch_size    = get_option( 'codir2me_batch_size', 50 );
		$total_batches = ceil( $total_files / $batch_size );

		$upload_status = array(
			'total_files'     => $total_files,
			'processed_files' => 0,
			'total_batches'   => $total_batches,
			'current_batch'   => 0,
			'start_time'      => time(),
		);

		update_option( 'codir2me_upload_status', $upload_status );

		if ( function_exists( 'codir2me_cdn_log' ) ) {
			$already_uploaded = count( $this->uploaded_files );
			/* translators: %1$d: arquivos pendentes, %2$d: arquivos já enviados */
			codir2me_cdn_log( sprintf( __( 'Escaneamento concluído. %1$d arquivos pendentes (%2$d já enviados)', 'codirun-codir2me-cdn' ), $total_files, $already_uploaded ) );
		}

		return $upload_status;
	}

	/**
	 * Escaneia diretórios seguros em busca de arquivos estáticos
	 *
	 * @return void
	 */
	private function codir2me_scan_safe_directories() {
		// Obter diretórios seguros para escanear.
		$directories_to_scan = array(
			get_theme_root(),           // Diretório de temas.
			WP_PLUGIN_DIR,              // Diretório de plugins.
		);

		// Adicionar diretório de uploads apenas se for seguro.
		$upload_dir = wp_upload_dir();
		if ( isset( $upload_dir['basedir'] ) && is_dir( $upload_dir['basedir'] ) ) {
			$directories_to_scan[] = $upload_dir['basedir'];
		}

		foreach ( $directories_to_scan as $dir ) {
			if ( is_dir( $dir ) && is_readable( $dir ) ) {
				$this->codir2me_scan_directory( $dir );
			}
		}
	}

	/**
	 * Escaneia um diretório específico em busca de arquivos estáticos
	 *
	 * @param string $dir Caminho do diretório a ser escaneado.
	 * @return void
	 */
	private function codir2me_scan_directory( $dir ) {
		// Verificar se o diretório existe e é legível.
		if ( ! is_dir( $dir ) || ! is_readable( $dir ) ) {
			return;
		}

		$files = scandir( $dir );
		if ( false === $files ) {
			return;
		}

		foreach ( $files as $file ) {
			if ( '.' === $file || '..' === $file ) {
				continue;
			}

			$path = $dir . '/' . $file;

			if ( is_dir( $path ) ) {
				// Ignorar diretórios de cache, uploads e outros não relevantes.
				$ignore_dirs = array( 'cache', 'uploads', 'tmp', 'temp', 'node_modules', '.git', '.svn' );
				if ( ! in_array( $file, $ignore_dirs, true ) ) {
					$this->codir2me_scan_directory( $path );
				}
			} else {
				// Verificar se é um arquivo suportado.
				$ext            = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
				$supported_exts = array( 'js', 'css', 'svg', 'woff', 'woff2', 'ttf', 'eot' );

				if ( in_array( $ext, $supported_exts, true ) ) {
					// Para JS e CSS, verificar o conteúdo do arquivo.
					if ( in_array( $ext, array( 'js', 'css' ), true ) ) {
						$file_content = $this->codir2me_get_file_content( $path );

						// Se não conseguir obter o conteúdo ou contiver referências a PHP ou AJAX, pular.
						if ( false === $file_content ||
							strpos( $file_content, '.php' ) !== false ||
							strpos( $file_content, 'ajax' ) !== false ) {
							continue;
						}
					}

					// Obter caminho relativo.
					$relative_path = $this->codir2me_get_relative_path( $path );

					// Verificar se o arquivo já foi enviado anteriormente.
					if ( ! in_array( $relative_path, $this->uploaded_files, true ) ) {
						// Adicionar à lista de arquivos pendentes.
						$this->pending_files[] = array(
							'full_path'     => $path,
							'relative_path' => $relative_path,
						);
					}
				}
			}
		}
	}

	/**
	 * Obtém o caminho relativo de um arquivo
	 *
	 * @param string $full_path Caminho completo do arquivo.
	 * @return string Caminho relativo.
	 */
	private function codir2me_get_relative_path( $full_path ) {
		$home_path     = get_home_path();
		$relative_path = str_replace( $home_path, '', $full_path );
		return ltrim( $relative_path, '/' );
	}

	/**
	 * Obtém o conteúdo de um arquivo de forma segura
	 *
	 * @param string $path Caminho do arquivo.
	 * @return string|false Conteúdo do arquivo ou false em caso de erro.
	 */
	private function codir2me_get_file_content( $path ) {
		// Verificar se é um arquivo válido e seguro.
		if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
			return false;
		}

		// Verificar tamanho do arquivo (máximo 1MB para análise).
		if ( filesize( $path ) > 1048576 ) {
			return false;
		}

		// Usar WP_Filesystem para ler o arquivo.
		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();

		if ( $wp_filesystem && $wp_filesystem->exists( $path ) ) {
			return $wp_filesystem->get_contents( $path );
		}

		return false;
	}

	/**
	 * Processa um lote de arquivos para upload
	 *
	 * @param object $uploader Instância do uploader.
	 * @param int    $batch_size Tamanho do lote a ser processado.
	 * @return array Resultado do processamento do lote.
	 */
	public function codir2me_process_batch( $uploader, $batch_size ) {
		// Log do tamanho do lote para debug.
		if ( function_exists( 'codir2me_cdn_log' ) ) {
			/* translators: %d: tamanho do lote configurado */
			codir2me_cdn_log( sprintf( __( 'Iniciando processamento de lote de arquivos estáticos. Tamanho do lote: %d', 'codirun-codir2me-cdn' ), $batch_size ) );
		}

		// Obter status de upload atual.
		$upload_status = get_option(
			'codir2me_upload_status',
			array(
				'total_files'     => 0,
				'processed_files' => 0,
				'total_batches'   => 0,
				'current_batch'   => 0,
				'start_time'      => time(),
			)
		);

		// Verificar se há arquivos pendentes.
		if ( empty( $this->pending_files ) ) {
			// Processo concluído.
			delete_option( 'codir2me_pending_files' );
			delete_option( 'codir2me_upload_status' );

			if ( function_exists( 'codir2me_cdn_log' ) ) {
				codir2me_cdn_log( __( 'Processamento de arquivos estáticos concluído - nenhum arquivo pendente', 'codirun-codir2me-cdn' ) );
			}

			return array(
				'complete'  => true,
				'processed' => 0,
			);
		}

		// Obter lista atual de arquivos enviados para verificação em tempo real.
		$this->uploaded_files = get_option( 'codir2me_uploaded_files', array() );
		$uploaded_files_set   = array_flip( $this->uploaded_files );

		// Garantir que o batch_size seja respeitado.
		$batch_size = max( 1, intval( $batch_size ) );

		// Processar apenas o número exato de arquivos definido no batch_size.
		$files_to_process = array_slice( $this->pending_files, 0, $batch_size );
		$processed        = 0;

		foreach ( $files_to_process as $file_info ) {
			try {
				// Verificar novamente se o arquivo já foi enviado (pode ter sido enviado em outro processo).
				if ( isset( $uploaded_files_set[ $file_info['relative_path'] ] ) ) {
					if ( function_exists( 'codir2me_cdn_log' ) ) {
						/* translators: %s: caminho relativo do arquivo */
						codir2me_cdn_log( sprintf( __( 'Arquivo já enviado, pulando: %s', 'codirun-codir2me-cdn' ), $file_info['relative_path'] ), 'debug' );
					}
					$this->codir2me_remove_pending_file_by_path( $file_info['relative_path'] );
					++$processed;
					continue;
				}

				// Fazer upload do arquivo.
				$upload_result = $uploader->codir2me_upload_file( $file_info['full_path'], $file_info['relative_path'] );

				if ( $upload_result ) {
					// Adicionar arquivo à lista de enviados imediatamente.
					if ( ! in_array( $file_info['relative_path'], $this->uploaded_files, true ) ) {
						$this->uploaded_files[]                            = $file_info['relative_path'];
						$uploaded_files_set[ $file_info['relative_path'] ] = true; // Atualizar set também.
						update_option( 'codir2me_uploaded_files', $this->uploaded_files );
					}

					// Remover da lista de pendentes.
					$this->codir2me_remove_pending_file_by_path( $file_info['relative_path'] );

					++$processed;

					if ( function_exists( 'codir2me_cdn_log' ) ) {
						/* translators: %s: caminho relativo do arquivo */
						codir2me_cdn_log( sprintf( __( 'Arquivo enviado com sucesso: %s', 'codirun-codir2me-cdn' ), $file_info['relative_path'] ), 'debug' );
					}
				} elseif ( function_exists( 'codir2me_cdn_log' ) ) {
						/* translators: %s: caminho relativo do arquivo */
						codir2me_cdn_log( sprintf( __( 'Falha no upload do arquivo: %s', 'codirun-codir2me-cdn' ), $file_info['relative_path'] ), 'error' );
				}
			} catch ( Exception $e ) {
				if ( function_exists( 'codir2me_cdn_log' ) ) {
					codir2me_cdn_log(
						sprintf(
						/* translators: %1$s: nome do arquivo, %2$s: mensagem de erro */
							__( 'Erro ao enviar arquivo %1$s: %2$s', 'codirun-codir2me-cdn' ),
							isset( $file_info['relative_path'] ) ? $file_info['relative_path'] : 'desconhecido',
							$e->getMessage()
						)
					);
				}
			}
		}

		// Atualizar status de upload.
		$upload_status['processed_files'] += $processed;
		++$upload_status['current_batch'];
		update_option( 'codir2me_upload_status', $upload_status );

		// Verificar se ainda há arquivos pendentes.
		$this->codir2me_load_files_list();
		$complete = empty( $this->pending_files );

		if ( function_exists( 'codir2me_cdn_log' ) ) {
			$remaining = count( $this->pending_files );
			/* translators: %1$d: arquivos processados neste lote, %2$d: arquivos restantes */
			codir2me_cdn_log( sprintf( __( 'Lote processado: %1$d arquivos. Restam: %2$d arquivos', 'codirun-codir2me-cdn' ), $processed, $remaining ) );
		}

		return array(
			'complete'  => $complete,
			'processed' => $processed,
		);
	}

	/**
	 * Remove um arquivo da lista de pendentes pelo caminho relativo
	 *
	 * @param string $relative_path Caminho relativo do arquivo.
	 * @return void
	 */
	private function codir2me_remove_pending_file_by_path( $relative_path ) {
		foreach ( $this->pending_files as $key => $file_info ) {
			if ( isset( $file_info['relative_path'] ) && $file_info['relative_path'] === $relative_path ) {
				unset( $this->pending_files[ $key ] );
				break;
			}
		}

		// Reindexar array.
		$this->pending_files = array_values( $this->pending_files );

		// Salvar alterações.
		update_option( 'codir2me_pending_files', $this->pending_files );
	}
}
