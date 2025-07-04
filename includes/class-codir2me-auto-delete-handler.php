<?php
/**
 * Classe responsável pela exclusão automática de arquivos do R2
 * quando anexos são removidos do WordPress
 *
 * @package Codirun_R2_Media_Static_CDN
 */

// Evitar acesso direto ao arquivo.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classe CODIR2ME_Auto_Delete_Handler
 *
 * Gerencia a exclusão automática de arquivos do R2 quando anexos
 * são removidos do WordPress.
 */
class CODIR2ME_Auto_Delete_Handler {
	/**
	 * Chave de acesso R2.
	 *
	 * @var string
	 */
	private $codir2me_access_key;

	/**
	 * Chave secreta R2.
	 *
	 * @var string
	 */
	private $codir2me_secret_key;

	/**
	 * Nome do bucket R2.
	 *
	 * @var string
	 */
	private $codir2me_bucket;

	/**
	 * Endpoint R2.
	 *
	 * @var string
	 */
	private $codir2me_endpoint;

	/**
	 * Se a exclusão automática está habilitada.
	 *
	 * @var bool
	 */
	private $auto_delete_enabled = false;

	/**
	 * Opção de exclusão de miniaturas.
	 *
	 * @var string
	 */
	private $auto_delete_thumbnail_option = 'none';

	/**
	 * Miniaturas selecionadas para exclusão.
	 *
	 * @var array
	 */
	private $auto_delete_selected_thumbnails = array();

	/**
	 * Instância do uploader.
	 *
	 * @var CODIR2ME_Uploader|null
	 */
	private $uploader = null;

	/**
	 * Construtor
	 */
	public function __construct() {
		// Carregar configurações básicas.
		$this->codir2me_access_key = get_option( 'codir2me_access_key', '' );
		$this->codir2me_secret_key = get_option( 'codir2me_secret_key', '' );
		$this->codir2me_bucket     = get_option( 'codir2me_bucket', '' );
		$this->codir2me_endpoint   = get_option( 'codir2me_endpoint', '' );

		// Verificar a configuração de exclusão automática.
		$this->auto_delete_enabled = get_option( 'codir2me_auto_delete_enabled', false );

		// Se não encontrou, tentar outras opções.
		if ( ! $this->auto_delete_enabled ) {
			$this->auto_delete_enabled = get_option( 'codir2me_enable_auto_delete', false );
		}

		if ( ! $this->auto_delete_enabled ) {
			$this->auto_delete_enabled = get_option( 'codir2me_auto_delete', false );
		}

		// Verificar em arrays de configurações.
		if ( ! $this->auto_delete_enabled ) {
			$images_settings = get_option( 'codir2me_images_settings' );
			if ( is_array( $images_settings ) ) {
				$this->auto_delete_enabled = isset( $images_settings['auto_delete_enabled'] ) ? (bool) $images_settings['auto_delete_enabled'] : false;
			}
		}

		if ( ! $this->auto_delete_enabled ) {
			$general_settings = get_option( 'codir2me_general_settings' );
			if ( is_array( $general_settings ) ) {
				$this->auto_delete_enabled = isset( $general_settings['auto_delete_enabled'] ) ? (bool) $general_settings['auto_delete_enabled'] : false;
			}
		}

		// Verificar se as configurações básicas estão disponíveis.
		if ( $this->codir2me_has_basic_configs() ) {
			$this->codir2me_init_uploader();
		}

		// Adicionar hooks se a exclusão automática estiver ativada.
		if ( $this->auto_delete_enabled ) {
			$this->codir2me_add_hooks();
		}
	}

	/**
	 * Verifica se as configurações básicas existem.
	 *
	 * @return bool
	 */
	private function codir2me_has_basic_configs() {
		return ! empty( $this->codir2me_access_key ) &&
				! empty( $this->codir2me_secret_key ) &&
				! empty( $this->codir2me_bucket ) &&
				! empty( $this->codir2me_endpoint );
	}

	/**
	 * Inicializa o uploader.
	 */
	private function codir2me_init_uploader() {
		if ( file_exists( CODIR2ME_CDN_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
			require_once CODIR2ME_CDN_PLUGIN_DIR . 'vendor/autoload.php';

			if ( class_exists( 'AsyncAws\S3\S3Client' ) ) {
				try {
					require_once CODIR2ME_CDN_INCLUDES_DIR . 'class-codir2me-uploader.php';
					$this->uploader = new CODIR2ME_Uploader(
						$this->codir2me_access_key,
						$this->codir2me_secret_key,
						$this->codir2me_bucket,
						$this->codir2me_endpoint
					);
				} catch ( Exception $e ) {
					/* translators: %s is the error message */
					codir2me_cdn_log( sprintf( __( 'R2 CDN - Erro ao inicializar uploader para exclusão automática: %s', 'codirun-codir2me-cdn' ), $e->getMessage() ) );
				}
			}
		}
	}

	/**
	 * Adiciona hooks do WordPress.
	 */
	private function codir2me_add_hooks() {
		// Hook para exclusão de anexo.
		add_action( 'delete_attachment', array( $this, 'codir2me_handle_attachment_deletion' ), 10, 1 );
	}

	/**
	 * Manipula a exclusão de um anexo.
	 *
	 * @param int $attachment_id ID do anexo.
	 */
	public function codir2me_handle_attachment_deletion( $attachment_id ) {
		$license_status = get_option( 'codir2me_license_status', 'inactive' );
		if ( 'active' !== $license_status ) {
			return;
		}

		// Verificar se a exclusão automática está ativada.
		if ( ! $this->auto_delete_enabled ) {
			return;
		}

		// Verificar se o uploader está disponível.
		if ( ! $this->uploader ) {
			if ( function_exists( 'codir2me_cdn_log' ) ) {
				codir2me_cdn_log( __( 'R2 CDN - Uploader não disponível para exclusão automática', 'codirun-codir2me-cdn' ) );
			}
			return;
		}

		// Verificar se é uma imagem.
		$mime_type = get_post_mime_type( $attachment_id );
		if ( 0 !== strpos( $mime_type, 'image/' ) ) {
			return;
		}

		// Obter informações da imagem.
		$file_path = get_attached_file( $attachment_id );
		if ( ! $file_path ) {
			return;
		}

		// Carregar configurações de exclusão.
		$thumbnail_option    = get_option( 'codir2me_auto_delete_thumbnail_option', 'all' );
		$selected_thumbnails = get_option( 'codir2me_auto_delete_selected_thumbnails', array() );

		// Usar função personalizada para obter caminho relativo.
		$relative_path = codir2me_get_relative_path( $file_path );
		$file_dir      = dirname( $relative_path );
		$file_name     = basename( $file_path );
		$file_base     = pathinfo( $file_name, PATHINFO_FILENAME );

		// Arrays para rastrear exclusões.
		$deleted_files = array(
			'original'   => false,
			'thumbnails' => array(),
		);

		$files_to_remove_from_list = array();

		try {
			// 1. SEMPRE excluir arquivo original (todas as opções incluem isso).
			$delete_result             = $this->uploader->codir2me_delete_object( $relative_path );
			$deleted_files['original'] = $delete_result;

			if ( $delete_result ) {
				$files_to_remove_from_list[] = $relative_path;

				// Excluir também versões WebP e AVIF do original.
				$webp_path = $file_dir . '/' . $file_base . '.webp';
				$avif_path = $file_dir . '/' . $file_base . '.avif';

				try {
					$this->uploader->codir2me_delete_object( $webp_path );
					$files_to_remove_from_list[] = $webp_path;
				} catch ( Exception $e ) {
					// WebP do original não existe ou falha na exclusão - ignorar silenciosamente.
					unset( $e ); // Evitar warning sobre variável não usada.
				}

				try {
					$this->uploader->codir2me_delete_object( $avif_path );
					$files_to_remove_from_list[] = $avif_path;
				} catch ( Exception $e ) {
					// AVIF do original não existe ou falha na exclusão - ignorar silenciosamente.
					unset( $e ); // Evitar warning sobre variável não usada.
				}
			}

			// 2. Processar miniaturas conforme configuração.
			if ( 'none' !== $thumbnail_option ) {
				$metadata = wp_get_attachment_metadata( $attachment_id );

				if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
					foreach ( $metadata['sizes'] as $size => $size_info ) {
						if ( ! isset( $size_info['file'] ) ) {
							continue;
						}

						// Verificar se deve excluir esta miniatura.
						$should_delete_thumbnail = false;

						if ( 'all' === $thumbnail_option ) {
							// Excluir todas as miniaturas.
							$should_delete_thumbnail = true;
						} elseif ( 'selected' === $thumbnail_option && in_array( $size, $selected_thumbnails, true ) ) {
							// Excluir apenas tamanhos selecionados.
							$should_delete_thumbnail = true;
						}
						// Se for 'none', não exclui nenhuma miniatura (já verificado na condição principal).

						if ( $should_delete_thumbnail ) {
							$thumb_path = $file_dir . '/' . $size_info['file'];

							try {
								$thumb_delete                         = $this->uploader->codir2me_delete_object( $thumb_path );
								$deleted_files['thumbnails'][ $size ] = $thumb_delete;

								if ( $thumb_delete ) {
									$files_to_remove_from_list[] = $thumb_path;

									// Excluir versões otimizadas das miniaturas.
									$thumb_base = pathinfo( $size_info['file'], PATHINFO_FILENAME );

									// WebP da miniatura.
									$thumb_webp_path = $file_dir . '/' . $thumb_base . '.webp';
									try {
										$this->uploader->codir2me_delete_object( $thumb_webp_path );
										$files_to_remove_from_list[] = $thumb_webp_path;
									} catch ( Exception $e ) {
										// WebP da miniatura não existe ou falha na exclusão - ignorar.
										unset( $e ); // Evitar warning sobre variável não usada.
									}

									// AVIF da miniatura.
									$thumb_avif_path = $file_dir . '/' . $thumb_base . '.avif';
									try {
										$this->uploader->codir2me_delete_object( $thumb_avif_path );
										$files_to_remove_from_list[] = $thumb_avif_path;
									} catch ( Exception $e ) {
										// AVIF da miniatura não existe ou falha na exclusão - ignorar.
										unset( $e ); // Evitar warning sobre variável não usada.
									}
								}
							} catch ( Exception $e ) {
								$deleted_files['thumbnails'][ $size ] = false;
								if ( function_exists( 'codir2me_cdn_log' ) ) {
									codir2me_cdn_log(
										sprintf(
											/* translators: %1$s é o tamanho da miniatura, %2$s é a mensagem de erro */
											__( 'R2 CDN - Erro ao excluir miniatura %1$s: %2$s', 'codirun-codir2me-cdn' ),
											$size,
											$e->getMessage()
										),
										'warning'
									);
								}
							}
						} elseif ( function_exists( 'codir2me_cdn_log' ) ) {
							// Log de miniatura mantida.
							codir2me_cdn_log(
								sprintf(
									/* translators: %s é o tamanho da miniatura mantida */
									__( 'R2 CDN - Miniatura %s mantida conforme configuração', 'codirun-codir2me-cdn' ),
									$size
								),
								'info'
							);
						}
					}
				}
			}

			// 3. Atualizar listas do plugin (remover apenas os arquivos que foram excluídos).
			if ( ! empty( $files_to_remove_from_list ) ) {
				$uploaded_images = get_option( 'codir2me_uploaded_images', array() );
				$uploaded_images = array_diff( $uploaded_images, $files_to_remove_from_list );
				update_option( 'codir2me_uploaded_images', $uploaded_images );
			}

			// Log de resultado.
			$original_status    = $deleted_files['original'] ? 'excluído' : 'falha';
			$thumbnails_deleted = count( array_filter( $deleted_files['thumbnails'] ) );
			$thumbnails_total   = count( $deleted_files['thumbnails'] );

			if ( function_exists( 'codir2me_cdn_log' ) ) {
				codir2me_cdn_log(
					sprintf(
						/* translators: %1$s é o status do original, %2$d é miniaturas excluídas, %3$d é total de miniaturas, %4$s é a opção de miniatura, %5$d é o ID do anexo */
						__( 'R2 CDN - Exclusão automática: Original %1$s, %2$d/%3$d miniaturas excluídas (modo: %4$s) - Anexo %5$d', 'codirun-codir2me-cdn' ),
						$original_status,
						$thumbnails_deleted,
						$thumbnails_total,
						$thumbnail_option,
						$attachment_id
					)
				);
			}
		} catch ( Exception $e ) {
			if ( function_exists( 'codir2me_cdn_log' ) ) {
				codir2me_cdn_log(
					sprintf(
						/* translators: %1$d é o ID do anexo, %2$s é a mensagem de erro */
						__( 'R2 CDN - Erro durante exclusão automática do anexo %1$d: %2$s', 'codirun-codir2me-cdn' ),
						$attachment_id,
						$e->getMessage()
					),
					'error'
				);
			}
		}
	}

	/**
	 * Método para obter as configurações de exclusão.
	 */
	public function codir2me_get_delete_settings() {
		return array(
			'auto_delete_enabled' => $this->auto_delete_enabled,
			'thumbnail_option'    => get_option( 'codir2me_auto_delete_thumbnail_option', 'all' ),
			'selected_thumbnails' => get_option( 'codir2me_auto_delete_selected_thumbnails', array() ),
		);
	}

	/**
	 * Método para definir as configurações de exclusão.
	 *
	 * @param string $thumbnail_option Opção de exclusão de miniaturas ('all', 'selected', 'none').
	 * @param array  $selected_thumbnails Array com os tamanhos de miniatura selecionados.
	 */
	public function codir2me_set_delete_settings( $thumbnail_option, $selected_thumbnails = array() ) {
		update_option( 'codir2me_auto_delete_thumbnail_option', $thumbnail_option );
		update_option( 'codir2me_auto_delete_selected_thumbnails', $selected_thumbnails );
	}

	/**
	 * Remove uma entrada da lista de imagens enviadas.
	 *
	 * @param string $relative_path Caminho relativo da imagem.
	 */
	private function codir2me_remove_from_uploaded_list( $relative_path ) {
		$uploaded_images = get_option( 'codir2me_uploaded_images', array() );

		if ( isset( $uploaded_images[ $relative_path ] ) ) {
			unset( $uploaded_images[ $relative_path ] );
			update_option( 'codir2me_uploaded_images', $uploaded_images );
		}
	}

	/**
	 * Remove uma miniatura específica da lista por tamanho.
	 *
	 * @param string $size Tamanho da miniatura.
	 * @param string $relative_path Caminho relativo da miniatura.
	 */
	private function codir2me_remove_from_thumbnails_by_size( $size, $relative_path ) {
		$uploaded_thumbnails = get_option( 'codir2me_uploaded_thumbnails', array() );

		foreach ( $uploaded_thumbnails as $thumb_key => $thumb_data ) {
			if ( isset( $thumb_data['size'] ) && $thumb_data['size'] === $size &&
				isset( $thumb_data['path'] ) && $thumb_data['path'] === $relative_path ) {
				unset( $uploaded_thumbnails[ $thumb_key ] );
			}
		}

		update_option( 'codir2me_uploaded_thumbnails', $uploaded_thumbnails );
	}
}
