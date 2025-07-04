<?php
/**
 * Classe responsável pelo upload de arquivos para o Cloudflare R2
 * Versão corrigida para AsyncAws S3 Client
 *
 * @package Codirun_R2_Media_Static_CDN
 */

// Evitar acesso direto ao arquivo.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classe CODIR2ME_Uploader
 *
 * Responsável pelo upload de arquivos para o Cloudflare R2 usando AsyncAws S3 Client.
 */
class CODIR2ME_Uploader {
	/**
	 * Chave de acesso do R2.
	 *
	 * @var string
	 */
	private $access_key;

	/**
	 * Chave secreta do R2.
	 *
	 * @var string
	 */
	private $secret_key;

	/**
	 * Nome do bucket R2.
	 *
	 * @var string
	 */
	private $bucket;

	/**
	 * Endpoint do R2.
	 *
	 * @var string
	 */
	private $endpoint;

	/**
	 * Cliente S3 AsyncAws.
	 *
	 * @var \AsyncAws\S3\S3Client|null
	 */
	private $s3_client = null;

	/**
	 * Construtor da classe.
	 *
	 * @param string $access_key Chave de acesso do R2.
	 * @param string $secret_key Chave secreta do R2.
	 * @param string $bucket Nome do bucket R2.
	 * @param string $endpoint Endpoint do R2.
	 */
	public function __construct( $access_key, $secret_key, $bucket, $endpoint ) {
		$this->access_key = $access_key;
		$this->secret_key = $secret_key;
		$this->bucket     = $bucket;
		$this->endpoint   = $endpoint;
	}

	/**
	 * Obtém o cliente S3 AsyncAws para comunicação com o R2.
	 *
	 * @return \AsyncAws\S3\S3Client
	 * @throws Exception Se o AsyncAws S3 não estiver disponível.
	 */
	public function codir2me_get_s3_client() {
		if ( null === $this->s3_client ) {
			if ( ! file_exists( CODIR2ME_CDN_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
				throw new Exception( esc_html__( 'AsyncAws S3 não disponível. Por favor, instale usando: composer require async-aws/s3', 'codirun-codir2me-cdn' ) );
			}

			require_once CODIR2ME_CDN_PLUGIN_DIR . 'vendor/autoload.php';

			if ( ! class_exists( 'AsyncAws\S3\S3Client' ) ) {
				throw new Exception( esc_html__( 'Classe AsyncAws\S3\S3Client não encontrada. Verifique a instalação do AsyncAws S3.', 'codirun-codir2me-cdn' ) );
			}

			// CORREÇÃO: Configuração padronizada para Cloudflare R2 com AsyncAws.
			$this->s3_client = new AsyncAws\S3\S3Client(
				array(
					'region'            => 'auto',
					'endpoint'          => $this->endpoint,
					'accessKeyId'       => $this->access_key,
					'accessKeySecret'   => $this->secret_key,
					'pathStyleEndpoint' => true,
				)
			);

			// Log da inicialização.
			if ( function_exists( 'codir2me_cdn_log' ) ) {
				codir2me_cdn_log(
					esc_html__( 'Cliente AsyncAws S3 inicializado com sucesso.', 'codirun-codir2me-cdn' ),
					'info'
				);
			}
		}

		return $this->s3_client;
	}

	/**
	 * Obtém o nome do bucket R2.
	 *
	 * @return string Nome do bucket.
	 */
	public function codir2me_get_bucket_name() {
		return $this->bucket;
	}

	/**
	 * Verifica se o AsyncAws S3 está pronto para uso.
	 *
	 * @return bool
	 */
	public function codir2me_is_sdk_ready() {
		try {
			return file_exists( CODIR2ME_CDN_PLUGIN_DIR . 'vendor/autoload.php' ) &&
					( class_exists( 'AsyncAws\S3\S3Client' ) ||
					( file_exists( CODIR2ME_CDN_PLUGIN_DIR . 'vendor/async-aws/s3/src/S3Client.php' ) ) );
		} catch ( Exception $e ) {
			// Log do erro se necessário.
			if ( function_exists( 'codir2me_cdn_log' ) ) {
				codir2me_cdn_log(
					sprintf(
						/* translators: %s é a mensagem de erro */
						esc_html__( 'Erro ao verificar SDK: %s', 'codirun-codir2me-cdn' ),
						esc_html( $e->getMessage() )
					),
					'error'
				);
			}
			return false;
		}
	}

	/**
	 * Obtém informações sobre o SDK instalado.
	 *
	 * @return array Informações do SDK.
	 */
	public function codir2me_get_sdk_info() {
		$info = array(
			'type'         => 'async-aws',
			'available'    => $this->codir2me_is_sdk_ready(),
			'class_exists' => class_exists( 'AsyncAws\S3\S3Client' ),
			'version'      => 'unknown',
		);

		// Tentar obter a versão.
		try {
			global $wp_filesystem;
			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			WP_Filesystem();

			$composer_file = CODIR2ME_CDN_PLUGIN_DIR . 'vendor/async-aws/s3/composer.json';
			if ( $wp_filesystem->exists( $composer_file ) ) {
				$composer_content = $wp_filesystem->get_contents( $composer_file );
				if ( false !== $composer_content ) {
					$composer_data = json_decode( $composer_content, true );
					if ( isset( $composer_data['version'] ) ) {
						$info['version'] = $composer_data['version'];
					}
				}
			}
		} catch ( Exception $e ) {
			// Versão permanece como 'unknown'.
			if ( function_exists( 'codir2me_cdn_log' ) ) {
				codir2me_cdn_log(
					sprintf(
						/* translators: %s é a mensagem de erro */
						esc_html__( 'Erro ao obter versão do SDK: %s', 'codirun-codir2me-cdn' ),
						esc_html( $e->getMessage() )
					),
					'error'
				);
			}
		}

		return $info;
	}

	/**
	 * Faz upload de um arquivo para o R2.
	 *
	 * @param mixed  $file_data Caminho do arquivo ou resultado de otimização.
	 * @param string $relative_path Caminho relativo no R2.
	 * @return bool Sucesso ou falha.
	 */
	public function codir2me_upload_file( $file_data, $relative_path ) {
		// Validar parâmetros de entrada.
		if ( ( ! is_string( $file_data ) && ! is_array( $file_data ) ) || empty( $relative_path ) ) {
			return false;
		}

		try {
			// Verificar se é um array (resultado do otimizador) ou string (caminho simples).
			if ( is_array( $file_data ) ) {
				// Verificar se contém resultado de otimização.
				if ( isset( $file_data['optimized_file'] ) ) {
					$upload_result = $this->codir2me_handle_optimization_result( $file_data );
				} else {
					// Assumir que é um array com file_path.
					$file_path     = isset( $file_data['file_path'] ) ? $file_data['file_path'] : $file_data;
					$upload_result = $this->codir2me_handle_regular_file( $file_path, $relative_path );
				}
			} else {
				// É um caminho de arquivo simples.
				$upload_result = $this->codir2me_handle_regular_file( $file_data, $relative_path );
			}

			return $upload_result;

		} catch ( Exception $e ) {
			return false;
		}
	}

	/**
	 * Processa resultado de otimização com uploads múltiplos.
	 *
	 * @param array $optimization_result Resultado da otimização.
	 * @return bool Sucesso ou falha.
	 */
	private function codir2me_handle_optimization_result( $optimization_result ) {
		// Verificar se há arquivos para upload.
		$uploads = array();

		// Arquivo original otimizado.
		if ( isset( $optimization_result['optimized_file'] ) && file_exists( $optimization_result['optimized_file'] ) ) {
			$uploads[] = array(
				'file_path'     => $optimization_result['optimized_file'],
				'relative_path' => $optimization_result['relative_path'],
			);
		}

		// Versão WebP.
		if ( isset( $optimization_result['webp_file'] ) && file_exists( $optimization_result['webp_file'] ) ) {
			$uploads[] = array(
				'file_path'     => $optimization_result['webp_file'],
				'relative_path' => $optimization_result['webp_relative_path'],
			);
		}

		// Versão AVIF.
		if ( isset( $optimization_result['avif_file'] ) && file_exists( $optimization_result['avif_file'] ) ) {
			$uploads[] = array(
				'file_path'     => $optimization_result['avif_file'],
				'relative_path' => $optimization_result['avif_relative_path'],
			);
		}

		// Realizar todos os uploads.
		$all_success = true;
		foreach ( $uploads as $upload ) {
			try {
				$success = $this->codir2me_do_direct_upload( $upload['file_path'], $upload['relative_path'] );

				if ( ! $success ) {
					codir2me_cdn_log(
						sprintf(
							/* translators: %s é o caminho relativo do arquivo */
							esc_html__( 'R2 CDN - Falha ao fazer upload de: %s', 'codirun-codir2me-cdn' ),
							esc_html( $upload['relative_path'] )
						)
					);
					$all_success = false;
				} else {
					codir2me_cdn_log(
						sprintf(
							/* translators: %s é o caminho relativo do arquivo */
							esc_html__( 'R2 CDN - Upload bem-sucedido de: %s', 'codirun-codir2me-cdn' ),
							esc_html( $upload['relative_path'] )
						)
					);
				}
			} catch ( Exception $e ) {
				codir2me_cdn_log(
					sprintf(
						/* translators: %1$s é o caminho relativo do arquivo, %2$s é a mensagem de erro */
						esc_html__( 'R2 CDN - Exceção ao fazer upload de: %1$s - %2$s', 'codirun-codir2me-cdn' ),
						esc_html( $upload['relative_path'] ),
						esc_html( $e->getMessage() )
					)
				);
				$all_success = false;
			}
		}

		// Limpar arquivos temporários.
		$this->codir2me_cleanup_temp_files( $optimization_result );

		return $all_success;
	}

	/**
	 * Processa arquivo regular.
	 *
	 * @param string $file_path Caminho do arquivo.
	 * @param string $relative_path Caminho relativo.
	 * @return bool Sucesso ou falha.
	 */
	private function codir2me_handle_regular_file( $file_path, $relative_path ) {
		// Verificar se o arquivo existe.
		if ( ! is_string( $file_path ) || ! file_exists( $file_path ) ) {
			if ( function_exists( 'codir2me_cdn_log' ) ) {
				codir2me_cdn_log(
					sprintf(
						/* translators: %s é o tipo do parâmetro inválido */
						esc_html__( 'Arquivo não encontrado ou parâmetro inválido: %s', 'codirun-codir2me-cdn' ),
						( is_string( $file_path ) ? esc_html( $file_path ) : esc_html( gettype( $file_path ) ) )
					),
					'error'
				);
			}
			return false;
		}

		// Verificar se é uma imagem e se a otimização está ativada.
		$enable_optimization = get_option( 'codir2me_enable_optimization', false );
		$file_ext            = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
		$image_extensions    = array( 'jpg', 'jpeg', 'png', 'webp' );

		if ( $enable_optimization && in_array( $file_ext, $image_extensions, true ) ) {
			// Passar para otimização.
			require_once CODIR2ME_CDN_INCLUDES_DIR . 'class-codir2me-image-optimizer.php';
			$optimizer = new CODIR2ME_Image_Optimizer();

			if ( $optimizer && $optimizer->codir2me_is_optimization_enabled() ) {
				// Otimizar e obter resultado.
				$optimization_result = $optimizer->codir2me_process_image( $file_path, $relative_path );

				if ( is_array( $optimization_result ) ) {
					return $this->codir2me_handle_optimization_result( $optimization_result );
				}
			}
		}

		// Upload direto sem otimização.
		return $this->codir2me_do_direct_upload( $file_path, $relative_path );
	}

	/**
	 * Faz upload direto de um arquivo usando AsyncAws.
	 *
	 * @param string $file_path Caminho do arquivo.
	 * @param string $relative_path Caminho relativo no R2.
	 * @return bool Sucesso ou falha.
	 * @throws Exception Se houver erro na exclusão.
	 */
	private function codir2me_do_direct_upload( $file_path, $relative_path ) {
		try {
			// Verificar se o arquivo existe antes do upload.
			if ( ! file_exists( $file_path ) ) {
				return false;
			}

			// Obter o cliente S3 usando AsyncAws.
			$s3_client = $this->codir2me_get_s3_client();

			// Obter tipo MIME.
			$mime_type = $this->codir2me_get_mime_type( $file_path );

			// Ler o conteúdo do arquivo usando WP_Filesystem.
			global $wp_filesystem;
			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			WP_Filesystem();

			$file_content = $wp_filesystem->get_contents( $file_path );
			if ( false === $file_content ) {
				throw new Exception( esc_html__( 'Não foi possível ler o arquivo.', 'codirun-codir2me-cdn' ) );
			}

			// Limpar barras duplas do caminho relativo.
			$clean_relative_path = ltrim( str_replace( '//', '/', $relative_path ), '/' );

			// Upload para o R2 usando AsyncAws.
			$input = array(
				'Bucket'      => $this->bucket,
				'Key'         => $clean_relative_path,
				'Body'        => $file_content,
				'ContentType' => $mime_type,
			);

			$result = $s3_client->putObject( $input );

			// AsyncAws retorna um Result object, aguardar a conclusão.
			$result->resolve();

			if ( function_exists( 'codir2me_cdn_log' ) ) {
				codir2me_cdn_log(
					sprintf(
						/* translators: %s é o caminho relativo do arquivo */
						esc_html__( 'Upload direto concluído com sucesso para: %s', 'codirun-codir2me-cdn' ),
						esc_html( $clean_relative_path )
					),
					'info'
				);
			}

			return true;

		} catch ( Exception $e ) {
			if ( function_exists( 'codir2me_cdn_log' ) ) {
				codir2me_cdn_log(
					sprintf(
						/* translators: %s é a mensagem de erro */
						esc_html__( 'Erro ao fazer upload direto: %s', 'codirun-codir2me-cdn' ),
						esc_html( $e->getMessage() )
					),
					'error'
				);
			}

			return false;
		}
	}

	/**
	 * Lista objetos no bucket (para compatibilidade).
	 *
	 * @param array $params Parâmetros da listagem.
	 * @return array Resultado da listagem.
	 * @throws Exception Se houver erro na listagem.
	 */
	public function codir2me_list_objects( $params = array() ) {
		try {
			$s3_client = $this->codir2me_get_s3_client();

			$default_params = array(
				'Bucket'  => $this->bucket,
				'MaxKeys' => 1000,
			);

			$params = wp_parse_args( $params, $default_params );

			$result = $s3_client->listObjectsV2( $params );

			// Aguardar a conclusão e converter para array.
			$objects = $result->resolve();

			return array(
				'success' => true,
				'objects' => $objects,
			);

		} catch ( Exception $e ) {
			return array(
				'success' => false,
				'error'   => $e->getMessage(),
			);
		}
	}

	/**
	 * Obtém o tipo MIME de um arquivo.
	 *
	 * @param string $file_path Caminho do arquivo.
	 * @return string Tipo MIME.
	 */
	private function codir2me_get_mime_type( $file_path ) {
		// Usar wp_check_filetype do WordPress.
		$filetype = wp_check_filetype( $file_path );

		// Se o WordPress não conseguir determinar, usar fallback.
		if ( ! empty( $filetype['type'] ) ) {
			return $filetype['type'];
		}

		// Fallback baseado na extensão.
		$extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );

		$mime_types = array(
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
			'gif'  => 'image/gif',
			'webp' => 'image/webp',
			'avif' => 'image/avif',
			'svg'  => 'image/svg+xml',
			'css'  => 'text/css',
			'js'   => 'application/javascript',
			'pdf'  => 'application/pdf',
			'ico'  => 'image/x-icon',
		);

		return isset( $mime_types[ $extension ] ) ? $mime_types[ $extension ] : 'application/octet-stream';
	}

	/**
	 * Limpa arquivos temporários após o upload.
	 *
	 * @param array $optimization_result Resultado da otimização.
	 */
	private function codir2me_cleanup_temp_files( $optimization_result ) {
		$temp_files = array();

		// Adicionar arquivos temporários à lista de limpeza.
		if ( isset( $optimization_result['optimized_file'] ) && strpos( $optimization_result['optimized_file'], wp_get_upload_dir()['basedir'] . '/temp' ) !== false ) {
			$temp_files[] = $optimization_result['optimized_file'];
		}

		if ( isset( $optimization_result['webp_file'] ) && strpos( $optimization_result['webp_file'], wp_get_upload_dir()['basedir'] . '/temp' ) !== false ) {
			$temp_files[] = $optimization_result['webp_file'];
		}

		if ( isset( $optimization_result['avif_file'] ) && strpos( $optimization_result['avif_file'], wp_get_upload_dir()['basedir'] . '/temp' ) !== false ) {
			$temp_files[] = $optimization_result['avif_file'];
		}

		// Limpar arquivos.
		foreach ( $temp_files as $temp_file ) {
			if ( file_exists( $temp_file ) ) {
				wp_delete_file( $temp_file );
			}
		}
	}

	/**
	 * Manipula o upload de arquivos otimizados (método de compatibilidade).
	 *
	 * @param array  $optimization_result Resultado da otimização.
	 * @param string $relative_path Caminho relativo no R2.
	 * @return bool Sucesso ou falha.
	 */
	private function codir2me_handle_optimized_upload( $optimization_result, $relative_path ) {
		$uploaded_files = array();

		try {
			// Upload do arquivo otimizado principal.
			if ( isset( $optimization_result['optimized_file'] ) && file_exists( $optimization_result['optimized_file'] ) ) {
				$uploaded_files[] = $this->codir2me_do_direct_upload( $optimization_result['optimized_file'], $relative_path );
			}

			// Upload do arquivo AVIF se disponível.
			if ( isset( $optimization_result['avif_file'] ) && file_exists( $optimization_result['avif_file'] ) ) {
				$avif_relative_path = preg_replace( '/\.(jpe?g|png|webp)$/i', '.avif', $relative_path );
				$uploaded_files[]   = $this->codir2me_do_direct_upload( $optimization_result['avif_file'], $avif_relative_path );
			}

			// Upload do arquivo WebP se disponível.
			if ( isset( $optimization_result['webp_file'] ) && file_exists( $optimization_result['webp_file'] ) ) {
				$webp_relative_path = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $relative_path );
				$uploaded_files[]   = $this->codir2me_do_direct_upload( $optimization_result['webp_file'], $webp_relative_path );
			}

			// Limpar arquivos temporários.
			$this->codir2me_cleanup_temp_files( $optimization_result );

			// Retornar verdadeiro se pelo menos um arquivo foi enviado.
			return in_array( true, $uploaded_files, true );

		} catch ( Exception $e ) {
			if ( function_exists( 'codir2me_cdn_log' ) ) {
				codir2me_cdn_log(
					sprintf(
						/* translators: %s é a mensagem de erro */
						esc_html__( 'Erro durante upload otimizado: %s', 'codirun-codir2me-cdn' ),
						esc_html( $e->getMessage() )
					),
					'error'
				);
			}

			// Limpar arquivos temporários mesmo em caso de erro.
			$this->codir2me_cleanup_temp_files( $optimization_result );

			return false;
		}
	}

	/**
	 * Exclui um objeto do bucket R2.
	 *
	 * @param string $object_key Chave do objeto a ser excluído.
	 * @return bool Sucesso ou falha.
	 * @throws Exception Se houver erro na exclusão.
	 */
	public function codir2me_delete_object( $object_key ) {
		try {
			$s3_client = $this->codir2me_get_s3_client();

			// CORREÇÃO: Limpar barras duplas do caminho.
			$clean_object_key = ltrim( str_replace( '//', '/', $object_key ), '/' );

			$result = $s3_client->deleteObject(
				array(
					'Bucket' => $this->bucket,
					'Key'    => $clean_object_key,
				)
			);

			// Aguardar a conclusão da operação.
			$result->resolve();

			if ( function_exists( 'codir2me_cdn_log' ) ) {
				codir2me_cdn_log(
					sprintf(
						/* translators: %s é a chave do objeto excluído */
						esc_html__( 'Objeto excluído com sucesso: %s', 'codirun-codir2me-cdn' ),
						esc_html( $clean_object_key )
					),
					'info'
				);
			}

			return true;

		} catch ( Exception $e ) {
			if ( function_exists( 'codir2me_cdn_log' ) ) {
				codir2me_cdn_log(
					sprintf(
						/* translators: %1$s é a chave do objeto, %2$s é a mensagem de erro */
						esc_html__( 'Erro ao excluir objeto %1$s: %2$s', 'codirun-codir2me-cdn' ),
						esc_html( $object_key ),
						esc_html( $e->getMessage() )
					),
					'error'
				);
			}
			return false;
		}
	}

	/**
	 * Exclui múltiplos objetos do bucket R2 em lote.
	 *
	 * @param array $object_keys Array de chaves dos objetos a serem excluídos.
	 * @return array Resultado da exclusão em lote.
	 * @throws Exception Se houver erro na exclusão.
	 */
	public function codir2me_delete_objects_batch( $object_keys ) {
		if ( empty( $object_keys ) || ! is_array( $object_keys ) ) {
			return array(
				'success' => false,
				'error'   => esc_html__( 'Lista de objetos vazia ou inválida.', 'codirun-codir2me-cdn' ),
			);
		}

		try {
			$s3_client = $this->codir2me_get_s3_client();

			// Preparar lista de objetos para exclusão.
			$objects_to_delete = array();
			foreach ( $object_keys as $key ) {
				// CORREÇÃO: Limpar barras duplas do caminho.
				$clean_key           = ltrim( str_replace( '//', '/', $key ), '/' );
				$objects_to_delete[] = array( 'Key' => $clean_key );
			}

			// Executar exclusão em lote.
			$result = $s3_client->deleteObjects(
				array(
					'Bucket' => $this->bucket,
					'Delete' => array(
						'Objects' => $objects_to_delete,
						'Quiet'   => false,
					),
				)
			);

			// Aguardar a conclusão da operação.
			$response = $result->resolve();

			// Processar resultado.
			$deleted_objects = isset( $response['Deleted'] ) ? $response['Deleted'] : array();
			$failed_objects  = isset( $response['Errors'] ) ? $response['Errors'] : array();

			if ( function_exists( 'codir2me_cdn_log' ) ) {
				codir2me_cdn_log(
					sprintf(
						/* translators: %1$d é o número de objetos excluídos, %2$d é o número de falhas */
						esc_html__( 'Exclusão em lote concluída: %1$d objetos excluídos, %2$d falhas', 'codirun-codir2me-cdn' ),
						count( $deleted_objects ),
						count( $failed_objects )
					),
					'info'
				);
			}

			return array(
				'success' => true,
				'deleted' => $deleted_objects,
				'failed'  => $failed_objects,
			);

		} catch ( Exception $e ) {
			if ( function_exists( 'codir2me_cdn_log' ) ) {
				codir2me_cdn_log(
					sprintf(
						/* translators: %s é a mensagem de erro */
						esc_html__( 'Erro na exclusão em lote: %s', 'codirun-codir2me-cdn' ),
						esc_html( $e->getMessage() )
					),
					'error'
				);
			}

			return array(
				'success' => false,
				'error'   => $e->getMessage(),
			);
		}
	}

	/**
	 * Verifica se um objeto existe no bucket R2.
	 *
	 * @param string $object_key Chave do objeto.
	 * @return bool True se existe, False caso contrário.
	 * @throws Exception Se houver erro na verificação.
	 */
	public function codir2me_object_exists( $object_key ) {
		try {
			$s3_client = $this->codir2me_get_s3_client();

			// CORREÇÃO: Limpar barras duplas do caminho.
			$clean_object_key = ltrim( str_replace( '//', '/', $object_key ), '/' );

			$result = $s3_client->headObject(
				array(
					'Bucket' => $this->bucket,
					'Key'    => $clean_object_key,
				)
			);

			// Se não lançar exceção, o objeto existe.
			$result->resolve();
			return true;

		} catch ( Exception $e ) {
			// Se a exceção for 404 (não encontrado), o objeto não existe.
			if ( method_exists( $e, 'getStatusCode' ) && 404 === $e->getStatusCode() ) {
				return false;
			}

			// Para outros erros, relançar a exceção.
			throw $e;
		}
	}

	/**
	 * Obtém informações sobre um objeto no bucket R2.
	 *
	 * @param string $object_key Chave do objeto.
	 * @return array|false Informações do objeto ou false se não encontrado.
	 * @throws Exception Se houver erro na consulta.
	 */
	public function codir2me_get_object_info( $object_key ) {
		try {
			$s3_client = $this->codir2me_get_s3_client();

			// CORREÇÃO: Limpar barras duplas do caminho.
			$clean_object_key = ltrim( str_replace( '//', '/', $object_key ), '/' );

			$result = $s3_client->headObject(
				array(
					'Bucket' => $this->bucket,
					'Key'    => $clean_object_key,
				)
			);

			$response = $result->resolve();

			return array(
				'key'           => $clean_object_key,
				'size'          => isset( $response['ContentLength'] ) ? $response['ContentLength'] : 0,
				'last_modified' => isset( $response['LastModified'] ) ? $response['LastModified'] : null,
				'etag'          => isset( $response['ETag'] ) ? trim( $response['ETag'], '"' ) : null,
				'content_type'  => isset( $response['ContentType'] ) ? $response['ContentType'] : null,
			);

		} catch ( Exception $e ) {
			// Se a exceção for 404 (não encontrado), retornar false.
			if ( method_exists( $e, 'getStatusCode' ) && 404 === $e->getStatusCode() ) {
				return false;
			}

			// Para outros erros, relançar a exceção.
			throw $e;
		}
	}

	/**
	 * Copia um objeto dentro do bucket R2.
	 *
	 * @param string $source_key Chave do objeto origem.
	 * @param string $destination_key Chave do objeto destino.
	 * @return bool Sucesso ou falha.
	 * @throws Exception Se houver erro na cópia.
	 */
	public function codir2me_copy_object( $source_key, $destination_key ) {
		try {
			$s3_client = $this->codir2me_get_s3_client();

			// CORREÇÃO: Limpar barras duplas dos caminhos.
			$clean_source_key      = ltrim( str_replace( '//', '/', $source_key ), '/' );
			$clean_destination_key = ltrim( str_replace( '//', '/', $destination_key ), '/' );

			$result = $s3_client->copyObject(
				array(
					'Bucket'     => $this->bucket,
					'Key'        => $clean_destination_key,
					'CopySource' => $this->bucket . '/' . $clean_source_key,
				)
			);

			// Aguardar a conclusão da operação.
			$result->resolve();

			if ( function_exists( 'codir2me_cdn_log' ) ) {
				codir2me_cdn_log(
					sprintf(
						/* translators: %1$s é a chave origem, %2$s é a chave destino */
						esc_html__( 'Objeto copiado com sucesso de %1$s para %2$s', 'codirun-codir2me-cdn' ),
						esc_html( $clean_source_key ),
						esc_html( $clean_destination_key )
					),
					'info'
				);
			}

			return true;

		} catch ( Exception $e ) {
			if ( function_exists( 'codir2me_cdn_log' ) ) {
				codir2me_cdn_log(
					sprintf(
						/* translators: %1$s é a chave origem, %2$s é a chave destino, %3$s é a mensagem de erro */
						esc_html__( 'Erro ao copiar objeto de %1$s para %2$s: %3$s', 'codirun-codir2me-cdn' ),
						esc_html( $source_key ),
						esc_html( $destination_key ),
						esc_html( $e->getMessage() )
					),
					'error'
				);
			}
			return false;
		}
	}

	/**
	 * Obtém uma URL pré-assinada para download de um objeto.
	 *
	 * @param string $object_key Chave do objeto.
	 * @param int    $expires_in Tempo de expiração em segundos (padrão: 1 hora).
	 * @return string|false URL pré-assinada ou false em caso de erro.
	 * @throws Exception Se houver erro na geração da URL.
	 */
	public function codir2me_get_presigned_url( $object_key, $expires_in = 3600 ) {
		try {
			$s3_client = $this->codir2me_get_s3_client();

			// CORREÇÃO: Limpar barras duplas do caminho.
			$clean_object_key = ltrim( str_replace( '//', '/', $object_key ), '/' );

			$command = $s3_client->getObject(
				array(
					'Bucket' => $this->bucket,
					'Key'    => $clean_object_key,
				)
			);

			// Gerar URL pré-assinada.
			$presigned_request = $s3_client->presign( $command, '+' . $expires_in . ' seconds' );

			return (string) $presigned_request->getUri();

		} catch ( Exception $e ) {
			if ( function_exists( 'codir2me_cdn_log' ) ) {
				codir2me_cdn_log(
					sprintf(
						/* translators: %1$s é a chave do objeto, %2$s é a mensagem de erro */
						esc_html__( 'Erro ao gerar URL pré-assinada para %1$s: %2$s', 'codirun-codir2me-cdn' ),
						esc_html( $object_key ),
						esc_html( $e->getMessage() )
					),
					'error'
				);
			}
			return false;
		}
	}
}
