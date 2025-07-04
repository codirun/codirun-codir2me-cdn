<?php
/**
 * Classe responsável por escanear e sincronizar arquivos no Cloudflare R2
 *
 * @package Codirun_R2_Media_Static_CDN
 */

// Evitar acesso direto ao arquivo.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classe CODIR2ME_Scanner
 *
 * Responsável por escanear e sincronizar arquivos no Cloudflare R2.
 */
class CODIR2ME_Scanner {
	/**
	 * Chave de acesso R2.
	 *
	 * @var string
	 */
	private $access_key;

	/**
	 * Chave secreta R2.
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
	 * URL do endpoint R2.
	 *
	 * @var string
	 */
	private $endpoint;

	/**
	 * Cliente S3 para comunicação com R2.
	 *
	 * @var AsyncAws\S3\S3Client|null
	 */
	private $s3_client = null;

	/**
	 * Construtor
	 *
	 * @param string $access_key Chave de acesso R2.
	 * @param string $secret_key Chave secreta R2.
	 * @param string $bucket Nome do bucket R2.
	 * @param string $endpoint URL do endpoint R2.
	 */
	public function __construct( $access_key, $secret_key, $bucket, $endpoint ) {
		$this->access_key = $access_key;
		$this->secret_key = $secret_key;
		$this->bucket     = $bucket;
		$this->endpoint   = $endpoint;
	}

	/**
	 * Inicializa o cliente S3 para comunicação com o R2
	 *
	 * @return \AsyncAws\S3\S3Client
	 * @throws Exception Se o AsyncAws SDK não estiver disponível.
	 */
	private function codir2me_get_s3_client() {
		if ( null === $this->s3_client ) {
			if ( ! file_exists( CODIR2ME_CDN_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
				throw new Exception( esc_html__( 'AsyncAws SDK não disponível. Por favor, instale o SDK manualmente.', 'codirun-codir2me-cdn' ) );
			}

			require_once CODIR2ME_CDN_PLUGIN_DIR . 'vendor/autoload.php';

			if ( ! class_exists( 'AsyncAws\S3\S3Client' ) ) {
				throw new Exception( esc_html__( 'Classe AsyncAws\S3\S3Client não encontrada. Verifique a instalação do AsyncAws SDK.', 'codirun-codir2me-cdn' ) );
			}

			// Configuração corrigida para AsyncAws com Cloudflare R2.
			$config = array(
				'region'            => 'auto',
				'endpoint'          => $this->endpoint,
				'accessKeyId'       => $this->access_key,
				'accessKeySecret'   => $this->secret_key,
				'pathStyleEndpoint' => true,
			);

			// Log de depuração se ativo.
			if ( get_option( 'codir2me_debug_mode', false ) && function_exists( 'codir2me_cdn_log' ) ) {
				codir2me_cdn_log( 'Inicializando cliente AsyncAws S3 com endpoint: ' . $this->endpoint, 'debug' );
			}

			try {
				$this->s3_client = new AsyncAws\S3\S3Client( $config );
			} catch ( Exception $e ) {
				// Log do erro.
				if ( function_exists( 'codir2me_cdn_log' ) ) {
					codir2me_cdn_log( 'Erro ao inicializar cliente S3: ' . $e->getMessage(), 'error' );
				}
				throw new Exception(
					sprintf(
						/* translators: %s é a mensagem de erro original */
						esc_html__( 'Erro ao inicializar cliente S3: %s', 'codirun-codir2me-cdn' ),
						esc_html( $e->getMessage() )
					)
				);
			}
		}

		return $this->s3_client;
	}

	/**
	 * Escaneia o bucket R2 de forma paginada com logs detalhados
	 * VERSÃO CORRIGIDA - Incluindo extensão dos arquivos
	 *
	 * @param int         $max_keys Número máximo de chaves por página (padrão 1000).
	 * @param string|null $continuation_token Token de continuação para paginação.
	 * @return array|false Array com resultados ou false em caso de erro.
	 * @throws Exception Se houver erro na comunicação com o R2.
	 */
	public function codir2me_scan_bucket( $max_keys = 1000, $continuation_token = null ) {
		// Log inicial.
		if ( get_option( 'codir2me_debug_mode', false ) && function_exists( 'codir2me_cdn_log' ) ) {
			codir2me_cdn_log( '[R2-API] Iniciando busca no bucket: ' . $this->bucket, 'info' );
			codir2me_cdn_log( '[R2-API] Parâmetros: max_keys=' . $max_keys . ', token=' . ( $continuation_token ? 'presente' : 'null' ), 'debug' );
		}

		try {
			// Montar parâmetros da requisição.
			$params = array(
				'Bucket'  => $this->bucket,
				'MaxKeys' => min( $max_keys, 1000 ),
			);

			if ( $continuation_token ) {
				$params['ContinuationToken'] = $continuation_token;
			}

			// Log dos parâmetros.
			if ( get_option( 'codir2me_debug_mode', false ) && function_exists( 'codir2me_cdn_log' ) ) {
				codir2me_cdn_log( '[R2-API] Endpoint: ' . $this->endpoint, 'debug' );
			}

			// Obter cliente S3.
			$s3_client = $this->codir2me_get_s3_client();

			if ( ! $s3_client ) {
				if ( get_option( 'codir2me_debug_mode', false ) && function_exists( 'codir2me_cdn_log' ) ) {
					codir2me_cdn_log( '[R2-API] ERRO: Falha ao criar cliente S3', 'error' );
				}
				throw new Exception( 'Falha ao criar cliente S3' );
			}

			// Log antes da chamada à API.
			if ( get_option( 'codir2me_debug_mode', false ) && function_exists( 'codir2me_cdn_log' ) ) {
				codir2me_cdn_log( '[R2-API] Fazendo chamada listObjectsV2 para Cloudflare R2', 'info' );
			}

			$start_time = microtime( true );

			// Fazer chamada para a API do R2.
			$result = $s3_client->listObjectsV2( $params );

			$end_time       = microtime( true );
			$execution_time = round( ( $end_time - $start_time ) * 1000, 2 );

			// Log do tempo de execução.
			if ( get_option( 'codir2me_debug_mode', false ) && function_exists( 'codir2me_cdn_log' ) ) {
				codir2me_cdn_log( '[R2-API] Resposta recebida em ' . $execution_time . 'ms', 'info' );
			}

			// Verificar se obtivemos resultado.
			if ( ! $result ) {
				if ( get_option( 'codir2me_debug_mode', false ) && function_exists( 'codir2me_cdn_log' ) ) {
					codir2me_cdn_log( '[R2-API] ERRO: Resposta vazia do R2', 'error' );
				}
				throw new Exception( 'Resposta vazia do R2' );
			}

			// Extrair dados do resultado.
			$contents                = $result->getContents();
			$is_truncated            = $result->getIsTruncated();
			$next_continuation_token = $result->getNextContinuationToken();

			// CORREÇÃO: Verificar se contents é um Generator e converter para array.
			$contents_array = array();
			if ( $contents ) {
				if ( is_array( $contents ) ) {
					$contents_array = $contents;
				} elseif ( $contents instanceof \Generator || is_iterable( $contents ) ) {
					// Converter Generator ou iterável para array.
					foreach ( $contents as $item ) {
						$contents_array[] = $item;
					}
				}
			}

			// Log dos metadados da resposta.
			if ( get_option( 'codir2me_debug_mode', false ) && function_exists( 'codir2me_cdn_log' ) ) {
				$contents_count = count( $contents_array );
				codir2me_cdn_log( '[R2-API] Arquivos retornados: ' . $contents_count, 'info' );
				codir2me_cdn_log( '[R2-API] Mais páginas disponíveis: ' . ( $is_truncated ? 'SIM' : 'NÃO' ), 'debug' );
				codir2me_cdn_log( '[R2-API] Token para próxima página: ' . ( $next_continuation_token ? 'presente' : 'nenhum' ), 'debug' );
			}

			// Inicializar arrays para armazenar resultados.
			$files      = array();
			$total_size = 0;

			// Processar os objetos retornados.
			if ( ! empty( $contents_array ) ) {
				$processed_count = 0;
				foreach ( $contents_array as $object ) {
					try {
						$key           = $object->getKey();
						$size          = $object->getSize();
						$last_modified = $object->getLastModified();

						// CORREÇÃO: Extrair extensão do arquivo.
						$extension = strtolower( pathinfo( $key, PATHINFO_EXTENSION ) );

						// Adicionar arquivo à lista.
						$files[] = array(
							'key'           => $key,
							'size'          => $size,
							'last_modified' => $last_modified ? $last_modified->format( 'Y-m-d H:i:s' ) : '',
							'extension'     => $extension,
						);

						$total_size += $size;
						++$processed_count;

					} catch ( Exception $e ) {
						if ( get_option( 'codir2me_debug_mode', false ) && function_exists( 'codir2me_cdn_log' ) ) {
							codir2me_cdn_log( '[R2-API] ERRO processando objeto: ' . $e->getMessage(), 'error' );
						}
						continue;
					}
				}

				if ( get_option( 'codir2me_debug_mode', false ) && function_exists( 'codir2me_cdn_log' ) ) {
					codir2me_cdn_log( '[R2-API] Objetos processados com sucesso: ' . $processed_count, 'info' );
				}
			} elseif ( get_option( 'codir2me_debug_mode', false ) && function_exists( 'codir2me_cdn_log' ) ) {
					codir2me_cdn_log( '[R2-API] Nenhum conteúdo retornado', 'debug' );
			}

			// Montar resultado final.
			$scan_result = array(
				'files'              => $files,
				'total_count'        => count( $files ),
				'total_size'         => $total_size,
				'is_truncated'       => $is_truncated,
				'continuation_token' => $next_continuation_token,
			);

			// Log do resultado final.
			if ( get_option( 'codir2me_debug_mode', false ) && function_exists( 'codir2me_cdn_log' ) ) {
				codir2me_cdn_log( '[R2-API] Resultado final: ' . count( $files ) . ' arquivos, ' . round( $total_size / 1024 / 1024, 2 ) . ' MB', 'info' );
			}

			return $scan_result;

		} catch ( Exception $e ) {
			// Log detalhado do erro.
			if ( get_option( 'codir2me_debug_mode', false ) && function_exists( 'codir2me_cdn_log' ) ) {
				codir2me_cdn_log( '[R2-API] ERRO na comunicação com R2: ' . $e->getMessage(), 'error' );

				// Log informações adicionais dependendo do tipo de erro.
				if ( strpos( $e->getMessage(), 'credentials' ) !== false ) {
					codir2me_cdn_log( '[R2-API] Possível problema: Credenciais inválidas (Access Key/Secret Key)', 'error' );
				} elseif ( strpos( $e->getMessage(), 'bucket' ) !== false ) {
					codir2me_cdn_log( '[R2-API] Possível problema: Nome do bucket incorreto ou bucket não existe', 'error' );
				} elseif ( strpos( $e->getMessage(), 'endpoint' ) !== false || strpos( $e->getMessage(), 'host' ) !== false ) {
					codir2me_cdn_log( '[R2-API] Possível problema: Endpoint incorreto ou inacessível', 'error' );
				} elseif ( strpos( $e->getMessage(), 'timeout' ) !== false ) {
					codir2me_cdn_log( '[R2-API] Possível problema: Timeout na conexão com R2', 'error' );
				} elseif ( strpos( $e->getMessage(), 'SSL' ) !== false || strpos( $e->getMessage(), 'certificate' ) !== false ) {
					codir2me_cdn_log( '[R2-API] Possível problema: Erro de certificado SSL', 'error' );
				}
			}

			// Re-lançar a exceção para que seja tratada pela camada superior.
			throw new Exception(
				sprintf(
					/* translators: %s é a mensagem de erro original */
					esc_html__( 'Erro ao escanear o bucket R2: %s', 'codirun-codir2me-cdn' ),
					esc_html( $e->getMessage() )
				)
			);
		}
	}

	/**
	 * Lista arquivos no bucket R2 filtrados por formato
	 * VERSÃO CORRIGIDA - Melhor controle de memória e evita duplicações
	 *
	 * @param string $format Formato a ser filtrado ('original', 'webp', 'avif' ou 'all').
	 * @param array  $progress_data Referência para acompanhar o progresso (opcional).
	 * @return array Lista de arquivos que correspondem ao formato.
	 * @throws Exception Se houver erro na comunicação com o R2.
	 */
	public function codir2me_list_files_by_format( $format = 'all', &$progress_data = null ) {
		try {
			$filtered_files     = array();
			$continuation_token = null;
			$is_truncated       = true;
			$total_scanned      = 0;
			$start_time         = time();

			// Array para evitar duplicações.
			$processed_keys = array();

			// Definir extensões de imagem originais.
			$image_extensions = array( 'jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff', 'tif' );

			// Inicializar dados de progresso se fornecidos.
			if ( null !== $progress_data ) {
				$progress_data['status']        = 'scanning';
				$progress_data['total_scanned'] = 0;
				$progress_data['total_found']   = 0;
			}

			// Log inicial.
			if ( get_option( 'codir2me_debug_mode', false ) && function_exists( 'codir2me_cdn_log' ) ) {
				codir2me_cdn_log( 'Iniciando codir2me_list_files_by_format com formato: ' . $format, 'debug' );
			}

			// Continuar a escanear enquanto houver mais resultados.
			while ( $is_truncated ) {
				// Verificar se a operação está demorando muito.
				if ( 270 < ( time() - $start_time ) ) { // 4.5 minutos - LINHA 419 CORRIGIDA
					throw new Exception( esc_html__( 'Operação excedeu o tempo limite. Por favor, tente novamente ou use lotes menores.', 'codirun-codir2me-cdn' ) );
				}

				// Buscar um lote de arquivos.
				$params = array(
					'Bucket'  => $this->bucket,
					'MaxKeys' => 1000, // 1000 é o máximo que a API S3 permite por chamada.
				);

				if ( $continuation_token ) {
					$params['ContinuationToken'] = $continuation_token;
				}

				$s3_client = $this->codir2me_get_s3_client();
				$objects   = $s3_client->listObjectsV2( $params );

				// Processar os objetos deste lote.
				$contents = $objects->getContents();
				if ( $contents && is_iterable( $contents ) ) {
					foreach ( $contents as $object ) {
						$key               = $object->getKey();
						$extension         = strtolower( pathinfo( $key, PATHINFO_EXTENSION ) );
						$size              = $object->getSize();
						$last_modified     = $object->getLastModified();
						$last_modified_str = $last_modified ? $last_modified->format( 'Y-m-d H:i:s' ) : '';

						// Verificar se já foi processado.
						if ( isset( $processed_keys[ $key ] ) ) {
							continue;
						}

						// Marcar como processado.
						$processed_keys[ $key ] = true;
						++$total_scanned;

						// Atualizar progresso.
						if ( null !== $progress_data ) {
							$progress_data['total_scanned'] = $total_scanned;
						}

						// Filtrar por formato.
						$should_include = false;

						if ( 'all' === $format ) {
							// Incluir apenas imagens em "todos os formatos".
							if ( in_array( $extension, $image_extensions, true ) || 'webp' === $extension || 'avif' === $extension ) {
								$should_include = true;
							}
						} elseif ( 'original' === $format && in_array( $extension, $image_extensions, true ) ) {
							// "Formato Original" - apenas imagens que não são WebP ou AVIF.
							$should_include = true;
						} elseif ( ( 'webp' === $format && 'webp' === $extension ) ||
								( 'avif' === $format && 'avif' === $extension ) ) {
							// Formatos específicos.
							$should_include = true;
						}

						if ( $should_include ) {
							$filtered_files[] = array(
								'key'           => $key,
								'size'          => $size,
								'last_modified' => $last_modified_str,
								'extension'     => $extension,
							);
						}

						// Atualizar total encontrado.
						if ( null !== $progress_data ) {
							$progress_data['total_found'] = count( $filtered_files );
						}

						// Liberar memória a cada 5000 arquivos processados.
						if ( 0 === $total_scanned % 5000 && function_exists( 'gc_collect_cycles' ) ) {
							gc_collect_cycles();
						}
					}
				}

				// Verificar se há mais arquivos para buscar.
				$is_truncated = $objects->getIsTruncated();
				if ( $is_truncated ) {
					$continuation_token = $objects->getNextContinuationToken();
				}
			}

			// Finalizar progresso.
			if ( null !== $progress_data ) {
				$progress_data['status'] = 'complete';
			}

			// Log final.
			if ( get_option( 'codir2me_debug_mode', false ) && function_exists( 'codir2me_cdn_log' ) ) {
				codir2me_cdn_log( 'codir2me_list_files_by_format concluído. Total encontrado: ' . count( $filtered_files ) . ' de ' . $total_scanned . ' escaneados', 'debug' );
			}

			return $filtered_files;

		} catch ( Exception $e ) {
			// Atualizar status em caso de erro.
			if ( null !== $progress_data ) {
				$progress_data['status'] = 'error';
				$progress_data['error']  = $e->getMessage();
			}

			// Log do erro.
			if ( function_exists( 'codir2me_cdn_log' ) ) {
				codir2me_cdn_log( 'Erro no codir2me_list_files_by_format: ' . $e->getMessage(), 'error' );
			}

			throw new Exception(
				sprintf(
				/* translators: %s é a mensagem de erro original */
					esc_html__( 'Erro ao listar arquivos por formato: %s', 'codirun-codir2me-cdn' ),
					esc_html( $e->getMessage() )
				)
			);
		}
	}

	/**
	 * Importa um arquivo do R2 para a biblioteca de mídia do WordPress
	 *
	 * @param string $codir2me_key Chave do objeto no R2 (caminho).
	 * @param string $filename Nome de arquivo local (opcional).
	 * @return int|WP_Error ID do attachment criado ou erro.
	 * @throws Exception Se houver erro na importação.
	 */
	public function codir2me_import_file_to_wordpress( $codir2me_key, $filename = '' ) {
		try {
			$s3_client = $this->codir2me_get_s3_client();

			// Criar diretório temporário.
			$upload_dir = wp_upload_dir();
			$temp_dir   = $upload_dir['basedir'] . '/codir2me_import_temp';
			if ( ! file_exists( $temp_dir ) ) {
				wp_mkdir_p( $temp_dir );
			}

			// Definir nome de arquivo se não fornecido.
			if ( empty( $filename ) ) {
				$filename = basename( $codir2me_key );
			}

			$temp_file = $temp_dir . '/' . $filename;

			// Log de depuração.
			if ( get_option( 'codir2me_debug_mode', false ) && function_exists( 'codir2me_cdn_log' ) ) {
				codir2me_cdn_log( 'Importando arquivo: ' . $codir2me_key, 'debug' );
			}

			// Baixar o objeto do R2.
			$result = $s3_client->getObject(
				array(
					'Bucket' => $this->bucket,
					'Key'    => $codir2me_key,
				)
			);

			// Obter o conteúdo do arquivo - AsyncAws compatível.
			$body = $result->getBody();

			// Para AsyncAws, precisamos ler o stream de forma diferente.
			$file_content = '';
			if ( is_resource( $body ) ) {
				// Se for um resource, ler com stream_get_contents.
				$file_content = stream_get_contents( $body );
			} elseif ( method_exists( $body, '__toString' ) ) {
				// Se tiver método __toString.
				$file_content = (string) $body;
			} elseif ( is_string( $body ) ) {
				// Se já for uma string.
				$file_content = $body;
			} elseif ( method_exists( $body, 'getContents' ) ) {
				$file_content = $body->getContents();
			} elseif ( method_exists( $body, 'read' ) ) {
				// Ler em chunks.
				$file_content = '';
				while ( ! $body->eof() ) {
					$file_content .= $body->read( 8192 );
				}
			} else {
				throw new Exception( esc_html__( 'Não foi possível ler o conteúdo do arquivo do R2', 'codirun-codir2me-cdn' ) );
			}

			// Verificar se o conteúdo foi obtido.
			if ( empty( $file_content ) ) {
				throw new Exception( esc_html__( 'Arquivo baixado está vazio ou corrompido', 'codirun-codir2me-cdn' ) );
			}

			// Inicializar WP_Filesystem.
			global $wp_filesystem;
			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			WP_Filesystem();

			// Salvar o conteúdo no arquivo temporário usando WP_Filesystem.
			if ( ! $wp_filesystem->put_contents( $temp_file, $file_content, 0644 ) ) {
				throw new Exception( esc_html__( 'Erro ao salvar arquivo temporário', 'codirun-codir2me-cdn' ) );
			}

			// Obter mime type.
			$mime_type = $this->codir2me_get_mime_type( $filename );

			// Preparar arquivo para importação.
			$upload = array(
				'name'     => $filename,
				'type'     => $mime_type,
				'tmp_name' => $temp_file,
				'error'    => 0,
				'size'     => strlen( $file_content ),
			);

			// Adicionar à biblioteca de mídia.
			$attachment_id = media_handle_sideload( $upload, 0 );

			// Limpar arquivo temporário.
			$wp_filesystem->delete( $temp_file );

			if ( is_wp_error( $attachment_id ) ) {
				throw new Exception( $attachment_id->get_error_message() );
			}

			// Log de sucesso.
			if ( get_option( 'codir2me_debug_mode', false ) && function_exists( 'codir2me_cdn_log' ) ) {
				codir2me_cdn_log( 'Arquivo importado com sucesso. ID: ' . $attachment_id, 'debug' );
			}

			return $attachment_id;

		} catch ( Exception $e ) {
			// Limpar arquivo temporário em caso de erro.
			if ( isset( $temp_file ) && file_exists( $temp_file ) ) {
				global $wp_filesystem;
				if ( ! function_exists( 'WP_Filesystem' ) ) {
					require_once ABSPATH . 'wp-admin/includes/file.php';
				}
				WP_Filesystem();
				$wp_filesystem->delete( $temp_file );
			}

			// Log do erro.
			if ( function_exists( 'codir2me_cdn_log' ) ) {
				codir2me_cdn_log( 'Erro ao importar arquivo ' . $codir2me_key . ': ' . $e->getMessage(), 'error' );
			}

			throw new Exception(
				sprintf(
				/* translators: %s é a mensagem de erro original */
					esc_html__( 'Erro ao importar arquivo: %s', 'codirun-codir2me-cdn' ),
					esc_html( $e->getMessage() )
				)
			);
		}
	}

	/**
	 * Determina o tipo MIME com base na extensão do arquivo
	 *
	 * @param string $filename Nome do arquivo.
	 * @return string Tipo MIME.
	 */
	private function codir2me_get_mime_type( $filename ) {
		$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

		$mime_types = array(
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
			'gif'  => 'image/gif',
			'webp' => 'image/webp',
			'avif' => 'image/avif',
			'svg'  => 'image/svg+xml',
			'pdf'  => 'application/pdf',
			'zip'  => 'application/zip',
			'doc'  => 'application/msword',
			'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'xls'  => 'application/vnd.ms-excel',
			'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'ppt'  => 'application/vnd.ms-powerpoint',
			'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
		);

		return isset( $mime_types[ $ext ] ) ? $mime_types[ $ext ] : 'application/octet-stream';
	}

	/**
	 * Importa múltiplos arquivos do R2 para o WordPress
	 *
	 * @param array $codir2me_keys Lista de chaves de objetos no R2.
	 * @return array Resultados da importação (sucessos e falhas).
	 */
	public function codir2me_batch_import_files( $codir2me_keys ) {
		$results = array(
			'success' => array(),
			'failed'  => array(),
		);

		foreach ( $codir2me_keys as $key ) {
			try {
				$attachment_id              = $this->codir2me_import_file_to_wordpress( $key );
				$results['success'][ $key ] = $attachment_id;
			} catch ( Exception $e ) {
				$results['failed'][ $key ] = $e->getMessage();
			}
		}

		return $results;
	}

	/**
	 * Realiza um escaneamento completo do bucket R2, processando todas as páginas
	 *
	 * @param int      $page_size Tamanho de cada página (padrão 1000).
	 * @param callable $progress_callback Função de callback para atualizar o progresso.
	 * @return array Informações completas sobre os arquivos no bucket.
	 * @throws Exception Se houver erro na comunicação com o R2.
	 */
	public function codir2me_scan_complete( $page_size = 1000, $progress_callback = null ) {
		$continuation_token = null;
		$is_truncated       = true;
		$complete_result    = array(
			'files'           => array(),
			'total_size'      => 0,
			'total_count'     => 0,
			'static_files'    => array(
				'count'      => 0,
				'size'       => 0,
				'extensions' => array(),
			),
			'images'          => array(
				'count'      => 0,
				'size'       => 0,
				'extensions' => array(),
				'thumbnails' => array(
					'count' => 0,
					'size'  => 0,
				),
				'originals'  => array(
					'count' => 0,
					'size'  => 0,
				),
			),
			'pages_processed' => 0,
		);

		$start_time = time();

		// Array para evitar processamento duplicado.
		$processed_keys = array();

		// Log inicial.
		if ( get_option( 'codir2me_debug_mode', false ) && function_exists( 'codir2me_cdn_log' ) ) {
			codir2me_cdn_log( 'Iniciando codir2me_scan_complete corrigido para AsyncAws', 'debug' );
		}

		// Usar array para rastrear arquivos únicos.
		while ( $is_truncated ) {
			// Verificar se a operação está demorando muito.
			if ( 270 < ( time() - $start_time ) ) { // 4.5 minutos.
				throw new Exception( esc_html__( 'Operação excedeu o tempo limite. Por favor, tente novamente ou use lotes menores.', 'codirun-codir2me-cdn' ) );
			}

			$page_result = $this->codir2me_scan_bucket( $page_size, $continuation_token );
			++$complete_result['pages_processed'];

			// Processar apenas arquivos únicos.
			if ( isset( $page_result['files'] ) && is_array( $page_result['files'] ) ) {
				foreach ( $page_result['files'] as $file ) {
					$key = $file['key'];

					// Verificar se já foi processado.
					if ( isset( $processed_keys[ $key ] ) ) {
						if ( get_option( 'codir2me_debug_mode', false ) && function_exists( 'codir2me_cdn_log' ) ) {
							codir2me_cdn_log( 'Arquivo duplicado ignorado no codir2me_scan_complete: ' . $key, 'debug' );
						}
						continue;
					}

					// Marcar como processado.
					$processed_keys[ $key ] = true;

					// Adicionar à lista de arquivos únicos.
					$complete_result['files'][] = $file;
				}
			}

			// Verificar se há mais páginas.
			$continuation_token = $page_result['continuation_token'];
			$is_truncated       = $page_result['is_truncated'];

			// Chamar callback de progresso, se fornecido.
			if ( is_callable( $progress_callback ) ) {
				call_user_func( $progress_callback, $complete_result['pages_processed'], count( $complete_result['files'] ) );
			}

			// Liberar memória a cada 10 páginas.
			if ( 0 === $complete_result['pages_processed'] % 10 && function_exists( 'gc_collect_cycles' ) ) {
				gc_collect_cycles();
			}

			// Se não houver mais páginas, sair do loop.
			if ( ! $is_truncated ) {
				break;
			}
		}

		// Recalcular todas as estatísticas baseado no array final de arquivos únicos.
		$this->codir2me_recalculate_totals( $complete_result );

		// Log final.
		if ( get_option( 'codir2me_debug_mode', false ) && function_exists( 'codir2me_cdn_log' ) ) {
			codir2me_cdn_log( 'codir2me_scan_complete finalizado. Total real de arquivos únicos: ' . $complete_result['total_count'], 'debug' );
		}

		// Adicionar tipo aos arquivos para compatibilidade com codir2me_compare_with_local_records.
		$image_exts_for_type = array( 'jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff', 'tif', 'webp', 'avif' );
		foreach ( $complete_result['files'] as &$file ) {
			$extension    = isset( $file['extension'] ) ? strtolower( $file['extension'] ) : '';
			$file['type'] = in_array( $extension, $image_exts_for_type, true ) ? 'image' : 'static';
		}
		unset( $file );

		// Log para debug.
		if ( get_option( 'codir2me_debug_mode', false ) && function_exists( 'codir2me_cdn_log' ) ) {
			codir2me_cdn_log( 'Tipos adicionados aos arquivos do escaneamento direto', 'debug' );
		}

		return $complete_result;
	}

	/**
	 * Recalcula os totais baseado no array completo de arquivos
	 * Evita contagem duplicada ao processar páginas
	 *
	 * @param array &$complete_result Referência para o resultado completo.
	 */
	private function codir2me_recalculate_totals( &$complete_result ) {
		// Resetar todos os contadores para zero.
		$complete_result['total_size']  = 0;
		$complete_result['total_count'] = 0;

		$complete_result['static_files'] = array(
			'count'      => 0,
			'size'       => 0,
			'extensions' => array(),
		);

		$complete_result['images'] = array(
			'count'      => 0,
			'size'       => 0,
			'extensions' => array(),
			'thumbnails' => array(
				'count' => 0,
				'size'  => 0,
			),
			'originals'  => array(
				'count' => 0,
				'size'  => 0,
			),
		);

		// Definir extensões.
		$static_extensions = array( 'js', 'css', 'svg', 'woff', 'woff2', 'ttf', 'eot' );
		$image_extensions  = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif' );

		// Log de depuração.
		if ( get_option( 'codir2me_debug_mode', false ) && function_exists( 'codir2me_cdn_log' ) ) {
			codir2me_cdn_log( 'Recalculando totais para ' . count( $complete_result['files'] ) . ' arquivos únicos', 'debug' );
		}

		// Reprocessar todos os arquivos para obter contagens corretas.
		foreach ( $complete_result['files'] as $file ) {
			$size = isset( $file['size'] ) ? $file['size'] : 0;
			$ext  = isset( $file['extension'] ) ? $file['extension'] : '';

			// Contagem total.
			++$complete_result['total_count'];
			$complete_result['total_size'] += $size;

			if ( in_array( $ext, $static_extensions, true ) ) {
				// Arquivo estático.
				++$complete_result['static_files']['count'];
				$complete_result['static_files']['size'] += $size;

				// Contabilizar por extensão.
				if ( ! isset( $complete_result['static_files']['extensions'][ $ext ] ) ) {
					$complete_result['static_files']['extensions'][ $ext ] = array(
						'count' => 0,
						'size'  => 0,
					);
				}

				++$complete_result['static_files']['extensions'][ $ext ]['count'];
				$complete_result['static_files']['extensions'][ $ext ]['size'] += $size;

			} elseif ( in_array( $ext, $image_extensions, true ) ) {
				// Imagem.
				++$complete_result['images']['count'];
				$complete_result['images']['size'] += $size;

				// Contabilizar por extensão.
				if ( ! isset( $complete_result['images']['extensions'][ $ext ] ) ) {
					$complete_result['images']['extensions'][ $ext ] = array(
						'count' => 0,
						'size'  => 0,
					);
				}

				++$complete_result['images']['extensions'][ $ext ]['count'];
				$complete_result['images']['extensions'][ $ext ]['size'] += $size;

				// Verificar se é uma miniatura.
				$filename     = basename( $file['key'] );
				$is_thumbnail = preg_match( '/-\d+x\d+\.[a-zA-Z]+$/', $filename ) ||
							preg_match( '/-[a-zA-Z_]+\.[a-zA-Z]+$/', $filename );

				if ( $is_thumbnail ) {
					++$complete_result['images']['thumbnails']['count'];
					$complete_result['images']['thumbnails']['size'] += $size;
				} else {
					++$complete_result['images']['originals']['count'];
					$complete_result['images']['originals']['size'] += $size;
				}
			}
		}

		// Log final de depuração.
		if ( get_option( 'codir2me_debug_mode', false ) && function_exists( 'codir2me_cdn_log' ) ) {
			codir2me_cdn_log(
				sprintf(
					'Recálculo concluído: Total=%d, Estáticos=%d, Imagens=%d',
					$complete_result['total_count'],
					$complete_result['static_files']['count'],
					$complete_result['images']['count']
				),
				'debug'
			);
		}
	}

	/**
	 * Compara os arquivos escaneados do R2 com os registros locais do WordPress
	 *
	 * @param array $codir2me_files Array de arquivos do R2 obtido pelo método codir2me_scan_complete().
	 * @return array Informações sobre a comparação.
	 */
	public function codir2me_compare_with_local_records( $codir2me_files ) {
		// Obter arquivos registrados localmente.
		$uploaded_files  = get_option( 'codir2me_uploaded_files', array() );
		$uploaded_images = get_option( 'codir2me_uploaded_images', array() );

		// Criar conjuntos para comparação eficiente.
		$codir2me_file_keys = array();
		foreach ( $codir2me_files['files'] as $file ) {
			$codir2me_file_keys[ $file['key'] ] = $file;
		}

		$local_file_keys = array();
		foreach ( $uploaded_files as $file ) {
			$local_file_keys[ $file ] = true;
		}

		$local_image_keys = array();
		foreach ( $uploaded_images as $image ) {
			$local_image_keys[ $image ] = true;
		}

		// Encontrar diferenças.
		$files_in_codir2me_not_in_local  = array();
		$files_in_local_not_in_codir2me  = array();
		$images_in_codir2me_not_in_local = array();
		$images_in_local_not_in_codir2me = array();

		// Verificar arquivos no R2 que não estão nos registros locais.
		foreach ( $codir2me_file_keys as $key => $file ) {
			if ( 'static' === $file['type'] && ! isset( $local_file_keys[ $key ] ) ) {
				$files_in_codir2me_not_in_local[] = $key;
			} elseif ( 'image' === $file['type'] && ! isset( $local_image_keys[ $key ] ) ) {
				$images_in_codir2me_not_in_local[] = $key;
			}
		}

		// Verificar arquivos nos registros locais que não estão no R2.
		foreach ( $local_file_keys as $key => $value ) {
			if ( ! isset( $codir2me_file_keys[ $key ] ) ) {
				$files_in_local_not_in_codir2me[] = $key;
			}
		}

		foreach ( $local_image_keys as $key => $value ) {
			if ( ! isset( $codir2me_file_keys[ $key ] ) ) {
				$images_in_local_not_in_codir2me[] = $key;
			}
		}

		return array(
			'codir2me_total' => count( $codir2me_files['files'] ),
			'local_total'    => count( $uploaded_files ) + count( $uploaded_images ),
			'static_files'   => array(
				'codir2me_total'           => $codir2me_files['static_files']['count'],
				'local_total'              => count( $uploaded_files ),
				'in_codir2me_not_in_local' => array(
					'count' => count( $files_in_codir2me_not_in_local ),
					'files' => $files_in_codir2me_not_in_local,
				),
				'in_local_not_in_codir2me' => array(
					'count' => count( $files_in_local_not_in_codir2me ),
					'files' => $files_in_local_not_in_codir2me,
				),
			),
			'images'         => array(
				'codir2me_total'           => $codir2me_files['images']['count'],
				'local_total'              => count( $uploaded_images ),
				'in_codir2me_not_in_local' => array(
					'count' => count( $images_in_codir2me_not_in_local ),
					'files' => $images_in_codir2me_not_in_local,
				),
				'in_local_not_in_codir2me' => array(
					'count' => count( $images_in_local_not_in_codir2me ),
					'files' => $images_in_local_not_in_codir2me,
				),
			),
		);
	}

	/**
	 * Sincroniza os registros locais com os arquivos reais no R2
	 * Otimizado para lidar com grandes quantidades de arquivos
	 *
	 * @param array $comparison_results Resultados da comparação obtida pelo método codir2me_compare_with_local_records().
	 * @param bool  $add_missing Adicionar arquivos faltantes aos registros locais.
	 * @param bool  $remove_nonexistent Remover dos registros locais arquivos que não existem no R2.
	 * @return array Informações sobre a sincronização.
	 * @throws Exception Se houver erro durante a sincronização.
	 */
	public function codir2me_sync_local_records( $comparison_results, $add_missing = true, $remove_nonexistent = true ) {
		$start_time = time();

		// Obter as listas atuais.
		$uploaded_files              = get_option( 'codir2me_uploaded_files', array() );
		$uploaded_images             = get_option( 'codir2me_uploaded_images', array() );
		$uploaded_thumbnails_by_size = get_option( 'codir2me_uploaded_thumbnails_by_size', array() );

		// Inicializar contadores de alterações.
		$changes = array(
			'static_files' => array(
				'added'   => 0,
				'removed' => 0,
			),
			'images'       => array(
				'added'   => 0,
				'removed' => 0,
			),
		);

		// Usar arrays associativos para busca mais eficiente.
		$uploaded_files_assoc  = array_flip( $uploaded_files );
		$uploaded_images_assoc = array_flip( $uploaded_images );

		// Adicionar arquivos faltantes aos registros locais.
		if ( $add_missing ) {
			// Verificar tempo limite.
			if ( 270 < ( time() - $start_time ) ) { // 4.5 minutos.
				throw new Exception( esc_html__( 'Operação excedeu o tempo limite durante a adição de arquivos.', 'codirun-codir2me-cdn' ) );
			}

			// Adicionar arquivos estáticos em massa.
			if ( isset( $comparison_results['static_files']['in_codir2me_not_in_local']['files'] ) ) {
				$new_static_files = array();
				foreach ( $comparison_results['static_files']['in_codir2me_not_in_local']['files'] as $file ) {
					if ( ! isset( $uploaded_files_assoc[ $file ] ) ) {
						$new_static_files[] = $file;
						++$changes['static_files']['added'];
					}
				}

				// Adicionar todos os novos arquivos de uma vez.
				if ( ! empty( $new_static_files ) ) {
					$uploaded_files = array_merge( $uploaded_files, $new_static_files );
				}
			}

			// Adicionar imagens em massa.
			if ( isset( $comparison_results['images']['in_codir2me_not_in_local']['files'] ) ) {
				$new_images = array();
				foreach ( $comparison_results['images']['in_codir2me_not_in_local']['files'] as $image ) {
					if ( ! isset( $uploaded_images_assoc[ $image ] ) ) {
						$new_images[] = $image;
						++$changes['images']['added'];

						// Verificar se é uma miniatura e catalogar por tamanho.
						$filename = basename( $image );
						if ( preg_match( '/-(\d+x\d+)\.[a-zA-Z]+$/', $filename, $matches ) ) {
							$size = $matches[1];
							if ( ! isset( $uploaded_thumbnails_by_size[ $size ] ) ) {
								$uploaded_thumbnails_by_size[ $size ] = array();
							}
							$uploaded_thumbnails_by_size[ $size ][] = $image;
						} elseif ( preg_match( '/-([a-zA-Z_]+)\.[a-zA-Z]+$/', $filename, $matches ) ) {
							$size = $matches[1];
							if ( ! isset( $uploaded_thumbnails_by_size[ $size ] ) ) {
								$uploaded_thumbnails_by_size[ $size ] = array();
							}
							$uploaded_thumbnails_by_size[ $size ][] = $image;
						}
					}
				}

				// Adicionar todas as novas imagens de uma vez.
				if ( ! empty( $new_images ) ) {
					$uploaded_images = array_merge( $uploaded_images, $new_images );
				}
			}
		}

		// Remover dos registros locais arquivos que não existem no R2.
		if ( $remove_nonexistent ) {
			// Verificar tempo limite.
			if ( 270 < ( time() - $start_time ) ) { // 4.5 minutos
				throw new Exception( esc_html__( 'Operação excedeu o tempo limite durante a remoção de arquivos.', 'codirun-codir2me-cdn' ) );
			}

			// Preparar arrays para armazenar o que será removido.
			$static_files_to_remove = array();
			$images_to_remove       = array();

			// Identificar arquivos estáticos a remover.
			if ( isset( $comparison_results['static_files']['in_local_not_in_codir2me']['files'] ) ) {
				foreach ( $comparison_results['static_files']['in_local_not_in_codir2me']['files'] as $file ) {
					if ( isset( $uploaded_files_assoc[ $file ] ) ) {
						$static_files_to_remove[] = $file;
						++$changes['static_files']['removed'];
					}
				}
			}

			// Identificar imagens a remover.
			if ( isset( $comparison_results['images']['in_local_not_in_codir2me']['files'] ) ) {
				foreach ( $comparison_results['images']['in_local_not_in_codir2me']['files'] as $image ) {
					if ( isset( $uploaded_images_assoc[ $image ] ) ) {
						$images_to_remove[] = $image;
						++$changes['images']['removed'];

						// Remover também da lista por tamanho.
						$filename = basename( $image );
						if ( preg_match( '/-(\d+x\d+)\.[a-zA-Z]+$/', $filename, $matches ) ) {
							$size = $matches[1];
							if ( isset( $uploaded_thumbnails_by_size[ $size ] ) ) {
								$uploaded_thumbnails_by_size[ $size ] = array_diff( $uploaded_thumbnails_by_size[ $size ], array( $image ) );
								if ( empty( $uploaded_thumbnails_by_size[ $size ] ) ) {
									unset( $uploaded_thumbnails_by_size[ $size ] );
								}
							}
						} elseif ( preg_match( '/-([a-zA-Z_]+)\.[a-zA-Z]+$/', $filename, $matches ) ) {
							$size = $matches[1];
							if ( isset( $uploaded_thumbnails_by_size[ $size ] ) ) {
								$uploaded_thumbnails_by_size[ $size ] = array_diff( $uploaded_thumbnails_by_size[ $size ], array( $image ) );
								if ( empty( $uploaded_thumbnails_by_size[ $size ] ) ) {
									unset( $uploaded_thumbnails_by_size[ $size ] );
								}
							}
						}
					}
				}
			}

			// Executar remoção em massa usando array_diff.
			if ( ! empty( $static_files_to_remove ) ) {
				$uploaded_files = array_values( array_diff( $uploaded_files, $static_files_to_remove ) );
			}

			if ( ! empty( $images_to_remove ) ) {
				$uploaded_images = array_values( array_diff( $uploaded_images, $images_to_remove ) );
			}
		}

		// Garantir que os arrays estejam indexados corretamente.
		$uploaded_files  = array_values( $uploaded_files );
		$uploaded_images = array_values( $uploaded_images );

		// Remover duplicatas (caso existam).
		$uploaded_files  = array_unique( $uploaded_files );
		$uploaded_images = array_unique( $uploaded_images );

		// Salvar alterações.
		update_option( 'codir2me_uploaded_files', $uploaded_files );
		update_option( 'codir2me_uploaded_images', $uploaded_images );
		update_option( 'codir2me_uploaded_thumbnails_by_size', $uploaded_thumbnails_by_size );

		// Atualizar contadores.
		$original_count  = 0;
		$thumbnail_count = 0;

		foreach ( $uploaded_images as $path ) {
			$filename = basename( $path );
			if ( preg_match( '/-\d+x\d+\.[a-zA-Z]+$/', $filename ) || preg_match( '/-[a-zA-Z_]+\.[a-zA-Z]+$/', $filename ) ) {
				++$thumbnail_count;
			} else {
				++$original_count;
			}
		}

		update_option( 'codir2me_original_images_count', $original_count );
		update_option( 'codir2me_thumbnail_images_count', $thumbnail_count );

		// Se todos os originais foram enviados, marcar como concluído.
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
			update_option( 'codir2me_missing_images_count', $total_media_library - $original_count );
		}

		// Log da sincronização.
		if ( get_option( 'codir2me_debug_mode', false ) && function_exists( 'codir2me_cdn_log' ) ) {
			codir2me_cdn_log(
				sprintf(
					'Sincronização concluída. Arquivos: +%d/-%d, Imagens: +%d/-%d',
					$changes['static_files']['added'],
					$changes['static_files']['removed'],
					$changes['images']['added'],
					$changes['images']['removed']
				),
				'info'
			);
		}

		return $changes;
	}
}
