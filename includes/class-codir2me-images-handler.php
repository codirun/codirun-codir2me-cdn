<?php
/**
 * Classe responsável por manipular imagens
 *
 * @package Codirun_R2_Media_Static_CDN
 */

// Evitar acesso direto ao arquivo.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classe para manipular imagens e suas URLs no CDN.
 */
class CODIR2ME_Images_Handler {
	/**
	 * URL do CDN
	 *
	 * @var string
	 */
	private $codir2me_cdn_url;

	/**
	 * Lista de imagens já enviadas
	 *
	 * @var array
	 */
	private $uploaded_images = array();

	/**
	 * Lista de imagens pendentes para envio
	 *
	 * @var array
	 */
	private $pending_images = array();

	/**
	 * Se o CDN está ativo
	 *
	 * @var bool
	 */
	private $is_active = false;

	/**
	 * Opção de miniaturas (all, selected, none)
	 *
	 * @var string
	 */
	private $thumbnail_option = 'all';

	/**
	 * Miniaturas selecionadas
	 *
	 * @var array
	 */
	private $selected_thumbnails = array();

	/**
	 * Rastrear miniaturas por tamanho
	 *
	 * @var array
	 */
	private $uploaded_thumbnails_by_size = array();

	/**
	 * Nova opção para envio automático de miniaturas
	 *
	 * @var bool
	 */
	private $auto_upload_thumbnails = false;

	/**
	 * Construtor da classe
	 *
	 * @param string $codir2me_cdn_url URL do CDN.
	 */
	public function __construct( $codir2me_cdn_url ) {
		$this->codir2me_cdn_url = $codir2me_cdn_url;
		$this->codir2me_load_images_list();

		// Carregar configurações de miniaturas.
		$this->thumbnail_option    = get_option( 'codir2me_thumbnail_option', 'all' );
		$this->selected_thumbnails = get_option( 'codir2me_selected_thumbnails', array() );

		// Carregar configuração de envio automático das miniaturas.
		$this->auto_upload_thumbnails = get_option( 'codir2me_auto_upload_thumbnails', false );

		// Adicionar hook para processamento de imagens.
		add_action( 'add_attachment', array( $this, 'codir2me_process_new_attachment' ) );

		// Adicionar hook para processar miniaturas após serem geradas.
		add_action( 'wp_generate_attachment_metadata', array( $this, 'codir2me_process_new_attachment_thumbnails' ), 10, 2 );
	}

	/**
	 * Carrega a lista de imagens já enviadas e pendentes
	 */
	private function codir2me_load_images_list() {
		// Carregar a lista de imagens já enviadas.
		$uploaded_images = get_option( 'codir2me_uploaded_images' );
		if ( $uploaded_images ) {
			$this->uploaded_images = $uploaded_images;
		}

		// Carregar a lista de imagens pendentes.
		$pending_images = get_option( 'codir2me_pending_images' );
		if ( $pending_images ) {
			$this->pending_images = $pending_images;
		}

		// Carregar lista de miniaturas por tamanho.
		$uploaded_thumbnails_by_size = get_option( 'codir2me_uploaded_thumbnails_by_size' );
		if ( $uploaded_thumbnails_by_size ) {
			$this->uploaded_thumbnails_by_size = $uploaded_thumbnails_by_size;
		}
	}

	/**
	 * Define se o CDN está ativo
	 *
	 * @param bool $is_active Se está ativo.
	 */
	public function codir2me_set_active( $is_active ) {
		$this->is_active = $is_active;
	}

	/**
	 * Configura a opção de upload automático de miniaturas
	 *
	 * @param bool $auto_upload Se deve fazer upload automático.
	 */
	public function codir2me_set_auto_upload_thumbnails( $auto_upload ) {
		$this->auto_upload_thumbnails = $auto_upload;
	}

	/**
	 * Inicializa os filtros de URL
	 */
	public function codir2me_init_url_filters() {
		if ( $this->is_active && $this->codir2me_should_apply_cdn() ) {
			// Modificar URLs de imagens.
			add_filter( 'wp_get_attachment_image_src', array( $this, 'codir2me_modify_attachment_image_src' ), 10, 4 );
			add_filter( 'wp_get_attachment_url', array( $this, 'codir2me_modify_image_url' ), 10, 2 );
			add_filter( 'wp_calculate_image_srcset', array( $this, 'codir2me_modify_image_srcset' ), 10, 5 );

			// Modificar URLs em conteúdo.
			add_filter( 'the_content', array( $this, 'codir2me_modify_image_urls_in_content' ), 10 );
			add_filter( 'post_thumbnail_html', array( $this, 'codir2me_replace_images_with_picture' ), 10 );
		}
	}

	/**
	 * Verifica se deve aplicar o CDN baseado nas configurações
	 *
	 * @return bool True se deve aplicar CDN, False caso contrário
	 */
	private function codir2me_should_apply_cdn() {
		// Verificar se está no admin e se a opção de desabilitar no admin está ativa.
		if ( is_admin() && get_option( 'codir2me_disable_cdn_admin' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Modifica o src de uma imagem de anexo
	 *
	 * @param array        $image Array com dados da imagem.
	 * @param int          $attachment_id ID do anexo.
	 * @param string|array $size Tamanho da imagem (não utilizado na implementação atual).
	 * @param bool         $icon Se é um ícone (não utilizado na implementação atual).
	 * @return array Array modificado.
	 * @throws Exception Quando houver erro na modificação da URL.
	 */
	public function codir2me_modify_attachment_image_src( $image, $attachment_id, $size, $icon ) {
		// Suprimir warnings sobre parâmetros não utilizados.
		unset( $size, $icon );

		if ( $image && isset( $image[0] ) ) {
			$image[0] = $this->codir2me_modify_image_url( $image[0], $attachment_id );
		}
		return $image;
	}

	/**
	 * Substitui tags de imagem por elementos picture para suporte a WebP/AVIF
	 *
	 * @param string $html HTML da imagem.
	 * @return string HTML modificado.
	 */
	public function codir2me_replace_images_with_picture( $html ) {
		// Padrão para encontrar tags img.
		$pattern = '/<img([^>]*)src=[\'"]([^\'"]+)[\'"]([^>]*)>/i';

		return preg_replace_callback( $pattern, array( $this, 'codir2me_replace_single_image_with_picture' ), $html );
	}

	/**
	 * Callback para substituir uma única imagem por elemento picture
	 *
	 * @param array $matches Matches da regex.
	 * @return string Tag HTML modificada.
	 */
	private function codir2me_replace_single_image_with_picture( $matches ) {
		$before_src = $matches[1];
		$src        = $matches[2];
		$after_src  = $matches[3];

		// Verificar se é uma URL do CDN.
		if ( false === strpos( $src, $this->codir2me_cdn_url ) ) {
			return $matches[0];
		}

		// Construir caminhos para WebP e AVIF.
		$webp_src = substr( $src, 0, strrpos( $src, '.' ) ) . '.webp';
		$avif_src = substr( $src, 0, strrpos( $src, '.' ) ) . '.avif';

		// Obter caminhos relativos para verificar se os arquivos existem.
		$relative_path      = str_replace( $this->codir2me_cdn_url . '/', '', $src );
		$webp_relative_path = str_replace( $this->codir2me_cdn_url . '/', '', $webp_src );
		$avif_relative_path = str_replace( $this->codir2me_cdn_url . '/', '', $avif_src );

		// Verificar se os arquivos existem no registro de uploads do R2.
		$webp_exists = in_array( $webp_relative_path, $this->uploaded_images, true );
		$avif_exists = in_array( $avif_relative_path, $this->uploaded_images, true );

		// Se nenhuma versão WebP/AVIF existe, retornar a tag original.
		if ( ! $avif_exists && ! $webp_exists ) {
			return $matches[0];
		}

		// Extrair atributos importantes da tag img original.
		$attributes = array();

		// Extrair alt.
		if ( preg_match( '/alt=[\'"]([^\'"]*)[\'"]/', $before_src . $after_src, $alt_match ) ) {
			$attributes['alt'] = $alt_match[1];
		}

		// Extrair class.
		if ( preg_match( '/class=[\'"]([^\'"]*)[\'"]/', $before_src . $after_src, $class_match ) ) {
			$attributes['class'] = $class_match[1];
		}

		// Extrair outros atributos.
		if ( preg_match( '/width=[\'"]([^\'"]*)[\'"]/', $before_src . $after_src, $width_match ) ) {
			$attributes['width'] = $width_match[1];
		}

		if ( preg_match( '/height=[\'"]([^\'"]*)[\'"]/', $before_src . $after_src, $height_match ) ) {
			$attributes['height'] = $height_match[1];
		}

		// Construir o elemento picture.
		$picture = '<picture>';

		if ( $avif_exists ) {
			$picture .= "\n  " . '<source srcset="' . esc_attr( $avif_src ) . '" type="image/avif">';
		}

		if ( $webp_exists ) {
			$picture .= "\n  " . '<source srcset="' . esc_attr( $webp_src ) . '" type="image/webp">';
		}

		// Construir a tag img com atributos.
		$img_attributes        = array();
		$img_attributes['src'] = esc_attr( $src );

		if ( isset( $attributes['alt'] ) ) {
			$img_attributes['alt'] = esc_attr( $attributes['alt'] );
		}

		if ( isset( $attributes['class'] ) ) {
			$img_attributes['class'] = esc_attr( $attributes['class'] );
		}

		if ( isset( $attributes['width'] ) ) {
			$img_attributes['width'] = esc_attr( $attributes['width'] );
		}

		if ( isset( $attributes['height'] ) ) {
			$img_attributes['height'] = esc_attr( $attributes['height'] );
		}

		$img_tag = '<img';
		foreach ( $img_attributes as $key => $value ) {
			$img_tag .= ' ' . $key . '="' . $value . '"';
		}
		$img_tag .= '>';

		$picture .= "\n  " . $img_tag;
		$picture .= "\n" . '</picture>';

		return $picture;
	}

	/**
	 * Modifica a URL de uma imagem para usar o CDN
	 *
	 * @param string $url URL da imagem.
	 * @param int    $attachment_id ID do anexo.
	 * @return string URL modificada.
	 */
	public function codir2me_modify_image_url( $url, $attachment_id ) {
		// Suprimir warning sobre parâmetro não utilizado.
		unset( $attachment_id );

		// Verificar se é uma URL do site.
		if ( false !== strpos( $url, home_url() ) ) {
			// Extrair o caminho relativo.
			$parsed_url = wp_parse_url( $url );
			$path       = ltrim( $parsed_url['path'], '/' );

			// Se esta imagem já foi enviada para o R2.
			if ( in_array( $path, $this->uploaded_images, true ) ) {
				// Substituir a URL pelo CDN.
				$cdn_path = trailingslashit( $this->codir2me_cdn_url ) . $path;

				// Preservar qualquer query string.
				if ( isset( $parsed_url['query'] ) ) {
					$cdn_path .= '?' . $parsed_url['query'];
				}

				return $cdn_path;
			}
		}

		return $url;
	}

	/**
	 * Modifica o srcset de imagens para usar o CDN
	 *
	 * @param array $sources Array de sources do srcset.
	 * @return array Sources modificadas.
	 */
	public function codir2me_modify_image_srcset( $sources ) {
		if ( ! is_array( $sources ) ) {
			return $sources;
		}

		foreach ( $sources as &$source ) {
			if ( isset( $source['url'] ) ) {
				// Verificar se é uma URL do site.
				if ( false !== strpos( $source['url'], home_url() ) ) {
					// Extrair o caminho relativo.
					$parsed_url = wp_parse_url( $source['url'] );
					$path       = ltrim( $parsed_url['path'], '/' );

					// Se esta imagem já foi enviada para o R2.
					if ( in_array( $path, $this->uploaded_images, true ) ) {
						// Substituir a URL pelo CDN.
						$source['url'] = trailingslashit( $this->codir2me_cdn_url ) . $path;

						// Preservar qualquer query string.
						if ( isset( $parsed_url['query'] ) ) {
							$source['url'] .= '?' . $parsed_url['query'];
						}
					}
				}
			}
		}

		return $sources;
	}

	/**
	 * Modifica URLs de imagens dentro do conteúdo
	 *
	 * @param string $content Conteúdo HTML.
	 * @return string Conteúdo modificado.
	 */
	public function codir2me_modify_image_urls_in_content( $content ) {
		// Padrão para encontrar imagens no conteúdo.
		$pattern = '/<img[^>]+src=([\'"])((?:http:\/\/|https:\/\/)[^\'">]+)([\'"])/i';

		$content = preg_replace_callback( $pattern, array( $this, 'codir2me_replace_image_url_in_content' ), $content );

		return $content;
	}

	/**
	 * Callback para substituir URL de imagem no conteúdo
	 *
	 * @param array $matches Matches da regex.
	 * @return string Tag modificada.
	 */
	private function codir2me_replace_image_url_in_content( $matches ) {
		$url = $matches[2];

		// Verificar se é uma URL do site.
		if ( false !== strpos( $url, home_url() ) ) {
			// Extrair o caminho relativo.
			$parsed_url = wp_parse_url( $url );
			$path       = ltrim( $parsed_url['path'], '/' );

			// Se esta imagem já foi enviada para o R2.
			if ( in_array( $path, $this->uploaded_images, true ) ) {
				// Substituir a URL pelo CDN.
				$cdn_path = trailingslashit( $this->codir2me_cdn_url ) . $path;

				// Preservar qualquer query string.
				if ( isset( $parsed_url['query'] ) ) {
					$cdn_path .= '?' . $parsed_url['query'];
				}

				return str_replace( $matches[2], $cdn_path, $matches[0] );
			}
		}

		return $matches[0];
	}

	/**
	 * Método corrigido para processar novos anexos
	 *
	 * @param int $attachment_id ID do anexo.
	 * @throws Exception Quando houver erro.
	 */
	public function codir2me_process_new_attachment( $attachment_id ) {
		// Log para debug.
		if ( function_exists( 'codir2me_cdn_log' ) ) {
			codir2me_cdn_log(
				sprintf(
					/* translators: %s é o ID do anexo */
					esc_html__( 'Processando novo anexo ID: %s', 'codirun-codir2me-cdn' ),
					$attachment_id
				),
				'debug'
			);
		}

		// IMPORTANTE: Não depender de $this->is_active aqui,.
		// pois pode não estar configurado corretamente ainda.
		// Verificar diretamente nas configurações.
		$images_cdn_active = get_option( 'codir2me_is_images_cdn_active', false );

		if ( ! $images_cdn_active ) {
			if ( function_exists( 'codir2me_cdn_log' ) ) {
				codir2me_cdn_log(
					sprintf(
						/* translators: %s é o ID do anexo */
						esc_html__( 'CDN de imagens não está ativo. Pulando anexo ID: %s', 'codirun-codir2me-cdn' ),
						$attachment_id
					),
					'debug'
				);
			}
			return;
		}

		// Verificar se é uma imagem.
		$mime_type = get_post_mime_type( $attachment_id );
		if ( ! $mime_type || 0 !== strpos( $mime_type, 'image/' ) ) {
			if ( function_exists( 'codir2me_cdn_log' ) ) {
				codir2me_cdn_log(
					sprintf(
						/* translators: %1$s é o ID do anexo, %2$s é o tipo MIME */
						esc_html__( 'Anexo ID %1$s não é uma imagem (MIME: %2$s). Pulando.', 'codirun-codir2me-cdn' ),
						$attachment_id,
						$mime_type
					),
					'debug'
				);
			}
			return;
		}

		// Obter o caminho da imagem.
		$file_path = get_attached_file( $attachment_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			if ( function_exists( 'codir2me_cdn_log' ) ) {
				codir2me_cdn_log(
					sprintf(
						/* translators: %1$s é o ID do anexo, %2$s é o caminho do arquivo */
						esc_html__( 'Arquivo não encontrado para anexo ID %1$s (caminho: %2$s)', 'codirun-codir2me-cdn' ),
						$attachment_id,
						$file_path ? $file_path : 'NULL'
					),
					'error'
				);
			}
			return;
		}

		// Obter caminho relativo.
		$relative_path = codir2me_get_relative_path( $file_path );

		// Verificar se já foi enviada.
		if ( in_array( $relative_path, $this->uploaded_images, true ) ) {
			if ( function_exists( 'codir2me_cdn_log' ) ) {
				codir2me_cdn_log(
					sprintf(
						/* translators: %1$s é o ID do anexo, %2$s é o caminho relativo */
						esc_html__( 'Imagem do anexo ID %1$s já foi enviada (%2$s). Pulando.', 'codirun-codir2me-cdn' ),
						$attachment_id,
						$relative_path
					),
					'debug'
				);
			}
			return;
		}

		// Verificar configurações do R2 - todas devem estar preenchidas.
		$r2_configs = array(
			'access_key' => get_option( 'codir2me_access_key' ),
			'secret_key' => get_option( 'codir2me_secret_key' ),
			'bucket'     => get_option( 'codir2me_bucket' ),
			'endpoint'   => get_option( 'codir2me_endpoint' ),
		);

		// Verificar se todas as configurações estão definidas.
		foreach ( $r2_configs as $config_key => $config_value ) {
			if ( empty( $config_value ) ) {
				if ( function_exists( 'codir2me_cdn_log' ) ) {
					codir2me_cdn_log(
						sprintf(
							/* translators: %1$s é a chave de configuração, %2$s é o ID do anexo */
							esc_html__( 'Configuração R2 "%1$s" vazia para processamento do anexo %2$s', 'codirun-codir2me-cdn' ),
							$config_key,
							$attachment_id
						),
						'warning'
					);
				}
				return;
			}
		}

		// Tentar obter o uploader.
		try {
			require_once CODIR2ME_CDN_INCLUDES_DIR . 'class-codir2me-uploader.php';
			$uploader = new CODIR2ME_Uploader(
				$r2_configs['access_key'],
				$r2_configs['secret_key'],
				$r2_configs['bucket'],
				$r2_configs['endpoint']
			);

			if ( ! $uploader ) {
				throw new Exception( esc_html__( 'Não foi possível criar instância do uploader', 'codirun-codir2me-cdn' ) );
			}
		} catch ( Exception $e ) {
			if ( function_exists( 'codir2me_cdn_log' ) ) {
				codir2me_cdn_log(
					sprintf(
						/* translators: %1$s é o ID do anexo, %2$s é a mensagem de erro */
						esc_html__( 'Erro ao inicializar uploader para anexo %1$s: %2$s', 'codirun-codir2me-cdn' ),
						$attachment_id,
						$e->getMessage()
					),
					'error'
				);
			}
			return;
		}

		// Fazer upload da imagem original.
		try {
			if ( function_exists( 'codir2me_cdn_log' ) ) {
				codir2me_cdn_log(
					sprintf(
						/* translators: %1$s é o ID do anexo, %2$s é o caminho relativo */
						esc_html__( 'Iniciando upload da imagem original - Anexo ID: %1$s, Caminho: %2$s', 'codirun-codir2me-cdn' ),
						$attachment_id,
						$relative_path
					),
					'info'
				);
			}

			// Obter configurações de otimização.
			$optimization_options = get_option( 'codir2me_image_optimization_options', array() );
			$enable_optimization  = isset( $optimization_options['enable_optimization'] ) ? (bool) $optimization_options['enable_optimization'] : false;
			$enable_webp          = isset( $optimization_options['enable_webp_conversion'] ) ? (bool) $optimization_options['enable_webp_conversion'] : false;
			$enable_avif          = isset( $optimization_options['enable_avif_conversion'] ) ? (bool) $optimization_options['enable_avif_conversion'] : false;

			if ( $enable_optimization && ( $enable_webp || $enable_avif ) ) {
				// Usar suas novas funções de conversão.
				$conversion_result = $this->codir2me_images_process_conversions(
					$file_path,
					$relative_path,
					$enable_webp,
					$enable_avif
				);

				// Upload da imagem original.
				$upload_success = $uploader->codir2me_upload_file( $file_path, $relative_path );

				// Upload das versões convertidas se a original foi enviada com sucesso.
				if ( $upload_success && $conversion_result['success'] ) {
					$this->codir2me_images_upload_conversions( $conversion_result, $uploader );
				}
			} else {
				// Upload normal sem conversões.
				$upload_success = $uploader->codir2me_upload_file( $file_path, $relative_path );
			}

			if ( $upload_success ) {
				// Adicionar à lista de imagens enviadas.
				if ( ! in_array( $relative_path, $this->uploaded_images, true ) ) {
					$this->uploaded_images[] = $relative_path;
					update_option( 'codir2me_uploaded_images', $this->uploaded_images );
				}

				if ( function_exists( 'codir2me_cdn_log' ) ) {
					codir2me_cdn_log(
						sprintf(
							/* translators: %1$s é o caminho relativo, %2$s é o ID do anexo */
							esc_html__( '✅ SUCESSO: Upload da imagem original: %1$s (Anexo ID: %2$s)', 'codirun-codir2me-cdn' ),
							$relative_path,
							$attachment_id
						),
						'info'
					);
				}
			} elseif ( function_exists( 'codir2me_cdn_log' ) ) {
					codir2me_cdn_log(
						sprintf(
							/* translators: %1$s é o caminho relativo, %2$s é o ID do anexo */
							esc_html__( '❌ FALHA: Upload da imagem original: %1$s (Anexo ID: %2$s)', 'codirun-codir2me-cdn' ),
							$relative_path,
							$attachment_id
						),
						'error'
					);
			}
		} catch ( Exception $e ) {
			if ( function_exists( 'codir2me_cdn_log' ) ) {
				codir2me_cdn_log(
					sprintf(
						/* translators: %1$s é o ID do anexo, %2$s é a mensagem de erro */
						esc_html__( '❌ EXCEÇÃO: Erro no upload do anexo %1$s: %2$s', 'codirun-codir2me-cdn' ),
						$attachment_id,
						$e->getMessage()
					),
					'error'
				);
			}
		}
	}

	/**
	 * Método corrigido para processar miniaturas após serem geradas
	 *
	 * @param array $metadata Metadados do anexo.
	 * @param int   $attachment_id ID do anexo.
	 * @return array Metadados inalterados.
	 */
	public function codir2me_process_new_attachment_thumbnails( $metadata, $attachment_id ) {
		// Verificar se o CDN de imagens está ativo.
		$images_cdn_active      = get_option( 'codir2me_is_images_cdn_active', false );
		$auto_upload_thumbnails = get_option( 'codir2me_auto_upload_thumbnails', false );

		if ( ! $images_cdn_active || ! $auto_upload_thumbnails ) {
			if ( function_exists( 'codir2me_cdn_log' ) ) {
				codir2me_cdn_log(
					sprintf(
						/* translators: %1$s é o ID do anexo, %2$s é o status do CDN, %3$s é o status do upload automático */
						esc_html__( 'Upload de miniaturas desabilitado para anexo %1$s (CDN: %2$s, Auto: %3$s)', 'codirun-codir2me-cdn' ),
						$attachment_id,
						$images_cdn_active ? 'ativo' : 'inativo',
						$auto_upload_thumbnails ? 'sim' : 'não'
					),
					'debug'
				);
			}
			return $metadata;
		}

		// Verificar se é uma imagem.
		$mime_type = get_post_mime_type( $attachment_id );
		if ( 0 !== strpos( $mime_type, 'image/' ) ) {
			return $metadata;
		}

		// Verificar configurações do R2.
		$r2_configs = array(
			'access_key' => get_option( 'codir2me_access_key' ),
			'secret_key' => get_option( 'codir2me_secret_key' ),
			'bucket'     => get_option( 'codir2me_bucket' ),
			'endpoint'   => get_option( 'codir2me_endpoint' ),
		);

		// Verificar se todas as configurações estão definidas.
		foreach ( $r2_configs as $config_value ) {
			if ( empty( $config_value ) ) {
				return $metadata;
			}
		}

		// Obter o uploader.
		try {
			require_once CODIR2ME_CDN_INCLUDES_DIR . 'class-codir2me-uploader.php';
			$uploader = new CODIR2ME_Uploader(
				$r2_configs['access_key'],
				$r2_configs['secret_key'],
				$r2_configs['bucket'],
				$r2_configs['endpoint']
			);

			// Processar miniaturas.
			$result = $this->codir2me_upload_attachment_thumbnails( $attachment_id, $uploader );

			// Salvar a lista atualizada de imagens enviadas.
			update_option( 'codir2me_uploaded_images', $this->uploaded_images );
			update_option( 'codir2me_uploaded_thumbnails_by_size', $this->uploaded_thumbnails_by_size );

			if ( function_exists( 'codir2me_cdn_log' ) ) {
				codir2me_cdn_log(
					sprintf(
						/* translators: %1$s é o ID do anexo, %2$s é o número de miniaturas processadas */
						esc_html__( 'Miniaturas processadas para anexo %1$s. Resultado: %2$s', 'codirun-codir2me-cdn' ),
						$attachment_id,
						is_array( $result ) ? count( $result ) . ' miniaturas' : 'erro'
					),
					'info'
				);
			}
		} catch ( Exception $e ) {
			if ( function_exists( 'codir2me_cdn_log' ) ) {
				codir2me_cdn_log(
					sprintf(
						/* translators: %1$s é o ID do anexo, %2$s é a mensagem de erro */
						esc_html__( 'Erro ao processar miniaturas do anexo %1$s: %2$s', 'codirun-codir2me-cdn' ),
						$attachment_id,
						$e->getMessage()
					),
					'error'
				);
			}
		}

		return $metadata;
	}

	/**
	 * Método para upload de miniaturas de anexos
	 *
	 * @param int               $attachment_id ID do anexo.
	 * @param CODIR2ME_Uploader $uploader Instância do uploader.
	 * @return array Resultado do upload.
	 */
	private function codir2me_upload_attachment_thumbnails( $attachment_id, $uploader ) {
		$result = array(
			'success'    => true,
			'thumbnails' => array(),
			'errors'     => array(),
		);

		// Verificar se o upload de miniaturas está habilitado.
		if ( 'none' !== $this->thumbnail_option ) {
			$metadata = wp_get_attachment_metadata( $attachment_id );
			if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
				$upload_dir = wp_upload_dir();
				$base_dir   = trailingslashit( $upload_dir['basedir'] );
				$file_dir   = dirname( $metadata['file'] );

				foreach ( $metadata['sizes'] as $size => $size_info ) {
					// CORREÇÃO CRÍTICA: Verificar se este tamanho deve ser enviado.
					if ( 'selected' === $this->thumbnail_option ) {
						// Garantir que selected_thumbnails é um array.
						$selected_thumbnails = is_array( $this->selected_thumbnails ) ? $this->selected_thumbnails : array();

						if ( ! in_array( $size, $selected_thumbnails, true ) ) {
							continue;
						}
					}

					// Caminho completo da miniatura.
					$thumb_path = $base_dir . trailingslashit( $file_dir ) . $size_info['file'];

					// Usar função helper para obter caminho relativo.
					$thumb_relative_path = codir2me_get_relative_path( $thumb_path );

					// Verificar se o arquivo existe.
					if ( file_exists( $thumb_path ) ) {
						try {
							// Obter configurações de otimização para miniaturas.
							$optimization_options = get_option( 'codir2me_image_optimization_options', array() );
							$convert_thumbnails   = get_option( 'codir2me_convert_thumbnails_option', false );
							$enable_webp          = isset( $optimization_options['enable_webp_conversion'] ) ? (bool) $optimization_options['enable_webp_conversion'] : false;
							$enable_avif          = isset( $optimization_options['enable_avif_conversion'] ) ? (bool) $optimization_options['enable_avif_conversion'] : false;

							// Upload da miniatura original.
							$upload_success = $uploader->codir2me_upload_file( $thumb_path, $thumb_relative_path );

							// Se a conversão de miniaturas estiver ativada, converter e fazer upload.
							if ( $upload_success && $convert_thumbnails && ( $enable_webp || $enable_avif ) ) {
								// Converter para WebP se habilitado.
								if ( $enable_webp ) {
									$webp_file = $this->codir2me_images_convert_to_webp( $thumb_path );
									if ( $webp_file ) {
										$webp_relative_path = substr( $thumb_relative_path, 0, strrpos( $thumb_relative_path, '.' ) ) . '.webp';
										$webp_upload        = $uploader->codir2me_upload_file( $webp_file, $webp_relative_path );

										if ( $webp_upload ) {
											// Adicionar à lista de imagens enviadas.
											if ( ! in_array( $webp_relative_path, $this->uploaded_images, true ) ) {
												$this->uploaded_images[] = $webp_relative_path;
											}
										}

										// Limpar arquivo temporário.
										wp_delete_file( $webp_file );
									}
								}

								// Converter para AVIF se habilitado.
								if ( $enable_avif ) {
									$avif_file = $this->codir2me_images_convert_to_avif( $thumb_path );
									if ( $avif_file ) {
										$avif_relative_path = substr( $thumb_relative_path, 0, strrpos( $thumb_relative_path, '.' ) ) . '.avif';
										$avif_upload        = $uploader->codir2me_upload_file( $avif_file, $avif_relative_path );

										if ( $avif_upload ) {
											// Adicionar à lista de imagens enviadas.
											if ( ! in_array( $avif_relative_path, $this->uploaded_images, true ) ) {
												$this->uploaded_images[] = $avif_relative_path;
											}
										}

										// Limpar arquivo temporário.
										wp_delete_file( $avif_file );
									}
								}
							}

							if ( $upload_success ) {
								// Adicionar à lista de imagens enviadas.
								if ( ! in_array( $thumb_relative_path, $this->uploaded_images, true ) ) {
									$this->uploaded_images[] = $thumb_relative_path;
								}

								// Verificar se uploaded_thumbnails_by_size[$size] é array antes de usar in_array.
								if ( ! isset( $this->uploaded_thumbnails_by_size[ $size ] ) ) {
									$this->uploaded_thumbnails_by_size[ $size ] = array();
								}

								// Garantir que é um array antes de usar in_array.
								if ( ! is_array( $this->uploaded_thumbnails_by_size[ $size ] ) ) {
									$this->uploaded_thumbnails_by_size[ $size ] = array();
								}

								if ( ! in_array( $thumb_relative_path, $this->uploaded_thumbnails_by_size[ $size ], true ) ) {
									$this->uploaded_thumbnails_by_size[ $size ][] = $thumb_relative_path;
								}

								$result['thumbnails'][ $size ] = __( 'enviado', 'codirun-codir2me-cdn' );
							} else {
								$result['thumbnails'][ $size ] = __( 'falha no upload', 'codirun-codir2me-cdn' );
								$result['errors'][]            = sprintf(
									/* translators: %s: nome do tamanho da miniatura */
									__( 'Falha ao enviar miniatura %s', 'codirun-codir2me-cdn' ),
									$size
								);
							}
						} catch ( Exception $e ) {
							$result['thumbnails'][ $size ] = __( 'erro:', 'codirun-codir2me-cdn' ) . ' ' . $e->getMessage();
							$result['errors'][]            = sprintf(
								/* translators: %s: nome do tamanho da miniatura */
								__( 'Erro ao enviar miniatura %s:', 'codirun-codir2me-cdn' ),
								$size
							) . ' ' . $e->getMessage();
						}
					} else {
						$result['thumbnails'][ $size ] = __( 'arquivo não encontrado', 'codirun-codir2me-cdn' );
					}
				}
			} else {
				$result['thumbnails'] = __( 'metadados não disponíveis', 'codirun-codir2me-cdn' );
			}
		} else {
			$result['thumbnails'] = __( 'desativado nas configurações', 'codirun-codir2me-cdn' );
		}

		return $result;
	}

	/**
	 * Retorna a lista de imagens enviadas
	 *
	 * @return array Lista de imagens enviadas.
	 */
	public function codir2me_get_uploaded_images() {
		return $this->uploaded_images;
	}

	/**
	 * Retorna a lista de imagens pendentes
	 *
	 * @return array Lista de imagens pendentes.
	 */
	public function codir2me_get_pending_images() {
		return $this->pending_images;
	}

	/**
	 * Retorna a configuração de miniaturas
	 *
	 * @return string Configuração atual.
	 */
	public function codir2me_get_thumbnail_option() {
		return $this->thumbnail_option;
	}

	/**
	 * Define a configuração de miniaturas
	 *
	 * @param string $option Opção (all, selected, none).
	 */
	public function codir2me_set_thumbnail_option( $option ) {
		$this->thumbnail_option = $option;
	}

	/**
	 * Retorna as miniaturas selecionadas
	 *
	 * @return array Miniaturas selecionadas.
	 */
	public function codir2me_get_selected_thumbnails() {
		return $this->selected_thumbnails;
	}

	/**
	 * Define as miniaturas selecionadas
	 *
	 * @param array $thumbnails Array de tamanhos selecionados.
	 */
	public function codir2me_set_selected_thumbnails( $thumbnails ) {
		$this->selected_thumbnails = $thumbnails;
	}

	/**
	 * Escaneia imagens da biblioteca de mídia
	 */
	public function codir2me_scan_images() {
		if ( function_exists( 'codir2me_cdn_log' ) ) {
			codir2me_cdn_log( __( 'Iniciando escaneamento de imagens', 'codirun-codir2me-cdn' ) );
		}

		// Limpar imagens pendentes e status anterior.
		$this->pending_images = array();

		// Criar uma lista de caminhos relativos das imagens já enviadas para consulta rápida.
		$uploaded_paths = array();
		foreach ( $this->uploaded_images as $path ) {
			$uploaded_paths[ $path ] = true;
		}

		// Obter todas as imagens da biblioteca de mídia.
		$args = array(
			'post_type'      => 'attachment',
			'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ),
			'post_status'    => 'inherit',
			'posts_per_page' => -1,
		);

		$query         = new WP_Query( $args );
		$total_found   = 0;
		$total_pending = 0;

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();

				$attachment_id = get_the_ID();
				++$total_found;

				// Obter o caminho da imagem original a partir da biblioteca de mídia.
				$file_path = get_attached_file( $attachment_id );

				// Verificar se o arquivo existe.
				if ( file_exists( $file_path ) ) {
					// CORREÇÃO: Usar função helper para obter caminho relativo.
					$relative_path = codir2me_get_relative_path( $file_path );

					// Verificar se a imagem já foi enviada anteriormente usando o array de consulta rápida.
					if ( ! isset( $uploaded_paths[ $relative_path ] ) ) {
						// Adicionar à lista de imagens pendentes.
						$this->pending_images[] = array(
							'id'            => $attachment_id,
							'full_path'     => $file_path,
							'relative_path' => $relative_path,
							'type'          => 'original',
						);
						++$total_pending;
					}

					// Adicionar as miniaturas conforme configuração.
					if ( 'none' !== $this->thumbnail_option ) {
						$metadata = wp_get_attachment_metadata( $attachment_id );
						if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
							$upload_dir = wp_upload_dir();
							$base_dir   = trailingslashit( $upload_dir['basedir'] );
							$file_dir   = dirname( $metadata['file'] );

							foreach ( $metadata['sizes'] as $size => $size_info ) {
								// Verificar se este tamanho deve ser enviado.
								if ( 'selected' === $this->thumbnail_option && ! in_array( $size, $this->selected_thumbnails, true ) ) {
									continue;
								}

								// Caminho completo da miniatura.
								$thumb_path = $base_dir . trailingslashit( $file_dir ) . $size_info['file'];

								// CORREÇÃO: Usar função helper para obter caminho relativo.
								$thumb_relative_path = codir2me_get_relative_path( $thumb_path );

								// Verificar se o arquivo existe.
								if ( file_exists( $thumb_path ) ) {
									// Verificar se esta miniatura já foi enviada usando o array de consulta rápida.
									if ( ! isset( $uploaded_paths[ $thumb_relative_path ] ) ) {
										$this->pending_images[] = array(
											'id'        => $attachment_id,
											'full_path' => $thumb_path,
											'relative_path' => $thumb_relative_path,
											'type'      => 'thumbnail',
											'size'      => $size,
										);
										++$total_pending;
									}
								}
							}
						}
					}
				}
			}

			wp_reset_postdata();
		}

		// Registrar estatísticas.
		update_option( 'codir2me_total_images_found', $total_found );
		update_option( 'codir2me_total_images_pending', $total_pending );

		// Salvar a lista de imagens pendentes.
		update_option( 'codir2me_pending_images', $this->pending_images );

		// Calcular e salvar status de upload.
		$total_files   = count( $this->pending_images );
		$batch_size    = get_option( 'codir2me_images_batch_size', 20 );
		$total_batches = ceil( $total_files / $batch_size );

		$upload_status = array(
			'total_files'     => $total_files,
			'processed_files' => 0,
			'total_batches'   => $total_batches,
			'current_batch'   => 0,
			'start_time'      => time(),
		);

		update_option( 'codir2me_images_upload_status', $upload_status );

		if ( function_exists( 'codir2me_cdn_log' ) ) {
			/* translators: %1$d is the number of images found, %2$d is the number of images pending */
			codir2me_cdn_log( sprintf( __( 'Escaneamento de imagens concluído. Encontradas %1$d imagens, %2$d pendentes.', 'codirun-codir2me-cdn' ), $total_found, $total_pending ) );
		}

		return $upload_status;
	}

	/**
	 * Processa um lote de imagens para upload
	 *
	 * @param CODIR2ME_Uploader $uploader Instância do uploader.
	 * @param int               $batch_size Tamanho do lote.
	 * @return array Resultado do processamento.
	 */
	public function codir2me_process_batch( $uploader, $batch_size ) {
		// Log do tamanho do lote para debug.
		if ( function_exists( 'codir2me_cdn_log' ) ) {
			/* translators: %d: tamanho do lote configurado */
			codir2me_cdn_log( sprintf( __( 'Iniciando processamento de lote de imagens. Tamanho do lote: %d', 'codirun-codir2me-cdn' ), $batch_size ) );
		}

		// Obter status de upload atual.
		$upload_status = get_option(
			'codir2me_images_upload_status',
			array(
				'total_files'     => 0,
				'processed_files' => 0,
				'total_batches'   => 0,
				'current_batch'   => 0,
				'start_time'      => time(),
			)
		);

		// Verificar se há imagens pendentes.
		if ( empty( $this->pending_images ) ) {
			// Processo concluído.
			delete_option( 'codir2me_pending_images' );
			delete_option( 'codir2me_images_upload_status' );

			if ( function_exists( 'codir2me_cdn_log' ) ) {
				codir2me_cdn_log( __( 'Processamento de imagens concluído - nenhuma imagem pendente', 'codirun-codir2me-cdn' ) );
			}

			return array(
				'complete'  => true,
				'processed' => 0,
			);
		}

		// Garantir que o batch_size seja respeitado.
		$batch_size = max( 1, intval( $batch_size ) );

		// Processar apenas o número exato de imagens definido no batch_size.
		$batch     = array_slice( $this->pending_images, 0, $batch_size );
		$processed = 0;

		if ( function_exists( 'codir2me_cdn_log' ) ) {
			/* translators: %1$d: número de imagens no lote atual, %2$d: total de imagens pendentes */
			codir2me_cdn_log( sprintf( __( 'Processando %1$d imagens de %2$d pendentes', 'codirun-codir2me-cdn' ), count( $batch ), count( $this->pending_images ) ) );
		}

		foreach ( $batch as $file_info ) {
			$file_path     = $file_info['full_path'];
			$relative_path = $file_info['relative_path'];

			// Verificar se o arquivo existe.
			if ( file_exists( $file_path ) ) {
				$license_status = get_option( 'codir2me_license_status', 'inactive' );
				// Obter configurações de otimização.
				$optimization_options = get_option( 'codir2me_image_optimization_options', array() );
				$enable_optimization  = isset( $optimization_options['enable_optimization'] ) ? (bool) $optimization_options['enable_optimization'] : false;
				$enable_webp          = isset( $optimization_options['enable_webp_conversion'] ) ? (bool) $optimization_options['enable_webp_conversion'] : false;
				$enable_avif          = isset( $optimization_options['enable_avif_conversion'] ) ? (bool) $optimization_options['enable_avif_conversion'] : false;

				if ( 'active' !== $license_status ) {
					$enable_optimization = false;
					$enable_webp         = false;
					$enable_avif         = false;
				}

				if ( function_exists( 'codir2me_cdn_log' ) ) {
					/* translators: %s: caminho relativo do arquivo de imagem */
					codir2me_cdn_log( sprintf( __( 'Processando imagem: %s', 'codirun-codir2me-cdn' ), $relative_path ) );
				}

				try {
					if ( $enable_optimization && ( $enable_webp || $enable_avif ) ) {
						// Usar suas novas funções de conversão.
						$conversion_result = $this->codir2me_images_process_conversions(
							$file_path,
							$relative_path,
							$enable_webp,
							$enable_avif
						);

						// Upload da imagem original.
						$upload_success = $uploader->codir2me_upload_file( $file_path, $relative_path );

						// Upload das versões convertidas se a original foi enviada com sucesso.
						if ( $upload_success && $conversion_result['success'] ) {
							$upload_conversions_result = $this->codir2me_images_upload_conversions( $conversion_result, $uploader );

							if ( function_exists( 'codir2me_cdn_log' ) ) {
								codir2me_cdn_log(
									sprintf(
										'Upload de conversões - WebP: %s, AVIF: %s',
										isset( $conversion_result['webp_file'] ) ? __( 'Sim', 'codirun-codir2me-cdn' ) : __( 'Não', 'codirun-codir2me-cdn' ),
										isset( $conversion_result['avif_file'] ) ? __( 'Sim', 'codirun-codir2me-cdn' ) : __( 'Não', 'codirun-codir2me-cdn' )
									)
								);
							}
						}
					} else {
						// Upload simples sem conversão.
						$upload_success = $uploader->codir2me_upload_file( $file_path, $relative_path );
					}

					if ( $upload_success ) {
						// Adicionar à lista de imagens enviadas.
						$this->uploaded_images[] = $relative_path;

						// Registrar miniatura por tamanho se for uma miniatura.
						if ( 'thumbnail' === $file_info['type'] && isset( $file_info['size'] ) ) {
							// Garantir que uploaded_thumbnails_by_size[$size] seja sempre um array.
							if ( ! isset( $this->uploaded_thumbnails_by_size[ $file_info['size'] ] ) ) {
								$this->uploaded_thumbnails_by_size[ $file_info['size'] ] = array();
							}

							// Garantir que é array (compatibilidade com o resto do plugin).
							if ( ! is_array( $this->uploaded_thumbnails_by_size[ $file_info['size'] ] ) ) {
								$this->uploaded_thumbnails_by_size[ $file_info['size'] ] = array();
							}

							// Adicionar o arquivo à lista (ao invés de incrementar contador).
							if ( ! in_array( $relative_path, $this->uploaded_thumbnails_by_size[ $file_info['size'] ], true ) ) {
								$this->uploaded_thumbnails_by_size[ $file_info['size'] ][] = $relative_path;
							}
						}

						++$processed;

						if ( function_exists( 'codir2me_cdn_log' ) ) {
							/* translators: %s: caminho relativo do arquivo enviado com sucesso */
							codir2me_cdn_log( sprintf( __( 'Upload bem-sucedido: %s', 'codirun-codir2me-cdn' ), $relative_path ), 'info' );
						}
					} elseif ( function_exists( 'codir2me_cdn_log' ) ) {
							/* translators: %s: caminho relativo do arquivo que falhou no upload */
							codir2me_cdn_log( sprintf( __( 'Falha no upload: %s', 'codirun-codir2me-cdn' ), $relative_path ), 'error' );
					}
				} catch ( Exception $e ) {
					if ( function_exists( 'codir2me_cdn_log' ) ) {
						/* translators: %1$s: caminho relativo do arquivo, %2$s: mensagem de erro */
						codir2me_cdn_log( sprintf( __( 'Erro ao processar %1$s: %2$s', 'codirun-codir2me-cdn' ), $relative_path, $e->getMessage() ), 'error' );
					}
				}
			} elseif ( function_exists( 'codir2me_cdn_log' ) ) {
					/* translators: %s: caminho do arquivo que não foi encontrado */
					codir2me_cdn_log( sprintf( __( 'Arquivo não encontrado: %s', 'codirun-codir2me-cdn' ), $file_path ), 'warning' );
			}

			// Verificar se já processamos o número máximo do lote.
			if ( $processed >= $batch_size ) {
				if ( function_exists( 'codir2me_cdn_log' ) ) {
					/* translators: %1$d: número de imagens processadas, %2$d: tamanho do lote */
					codir2me_cdn_log( sprintf( __( 'Lote concluído: %1$d/%2$d imagens processadas', 'codirun-codir2me-cdn' ), $processed, $batch_size ) );
				}
				break;
			}
		}

		// CORREÇÃO: Remover apenas as imagens processadas da lista de pendentes.
		$this->pending_images = array_slice( $this->pending_images, $processed );

		// Salvar listas atualizadas.
		update_option( 'codir2me_uploaded_images', $this->uploaded_images );
		update_option( 'codir2me_pending_images', $this->pending_images );
		update_option( 'codir2me_uploaded_thumbnails_by_size', $this->uploaded_thumbnails_by_size );

		// Atualizar status do upload.
		$upload_status['processed_files'] += $processed;
		++$upload_status['current_batch'];

		// Calcular se o processo está completo.
		$remaining_files = count( $this->pending_images );
		$is_complete     = ( 0 === $remaining_files );

		if ( $is_complete ) {
			// Marcar como concluído.
			delete_option( 'codir2me_pending_images' );
			delete_option( 'codir2me_images_upload_status' );

			if ( function_exists( 'codir2me_cdn_log' ) ) {
				/* translators: %d: total de imagens processadas */
				codir2me_cdn_log( sprintf( __( 'Processo de upload de imagens concluído. Total processado: %d imagens', 'codirun-codir2me-cdn' ), $upload_status['processed_files'] ) );
			}
		} else {
			// Salvar status atualizado.
			update_option( 'codir2me_images_upload_status', $upload_status );

			if ( function_exists( 'codir2me_cdn_log' ) ) {
				/* translators: %1$d: imagens processadas neste lote, %2$d: imagens restantes */
				codir2me_cdn_log( sprintf( __( 'Lote processado: %1$d imagens. Restam: %2$d imagens', 'codirun-codir2me-cdn' ), $processed, $remaining_files ) );
			}
		}

		return array(
			'complete'  => $is_complete,
			'processed' => $processed,
			'remaining' => $remaining_files,
		);
	}

	/**
	 * Converte uma imagem para WebP (específico da aba de imagens)
	 *
	 * @param string $source_path Caminho da imagem original.
	 * @param int    $quality Qualidade da conversão (1-100).
	 * @return string|false Caminho do arquivo WebP criado ou false em caso de erro
	 */
	private function codir2me_images_convert_to_webp( $source_path, $quality = 80 ) {
		if ( ! function_exists( 'imagewebp' ) || ! file_exists( $source_path ) ) {
			return false;
		}

		// Obter nível de otimização das configurações.
		$optimization_options = get_option( 'codir2me_image_optimization_options', array() );
		$optimization_level   = isset( $optimization_options['optimization_level'] ) ? $optimization_options['optimization_level'] : 'balanced';

		// Ajustar qualidade baseado no nível de otimização.
		switch ( $optimization_level ) {
			case 'light':
				$quality = max( 85, $quality );
				break;
			case 'balanced':
				// Usar qualidade configurada.
				break;
			case 'aggressive':
				$quality = min( 70, $quality );
				// Para arquivos grandes, reduzir ainda mais.
				if ( file_exists( $source_path ) ) {
					$file_size = filesize( $source_path );
					if ( $file_size > 1024 * 1024 ) {
						$quality = min( 65, $quality );
					}
				}
				break;
		}

		$extension = strtolower( pathinfo( $source_path, PATHINFO_EXTENSION ) );
		$image     = null;

		// Carregar imagem baseada no tipo.
		switch ( $extension ) {
			case 'jpg':
			case 'jpeg':
				$image = imagecreatefromjpeg( $source_path );
				break;
			case 'png':
				$image = imagecreatefrompng( $source_path );
				break;
			case 'gif':
				$image = imagecreatefromgif( $source_path );
				break;
			default:
				return false;
		}

		if ( ! $image ) {
			return false;
		}

		// Verificar se a imagem precisa ser convertida para truecolor (necessário para WebP).
		if ( ! imageistruecolor( $image ) ) {
			$width            = imagesx( $image );
			$height           = imagesy( $image );
			$true_color_image = imagecreatetruecolor( $width, $height );

			// Configurar transparência para PNGs e GIFs.
			if ( 'png' === $extension || 'gif' === $extension ) {
				// Preservar transparência.
				imagealphablending( $true_color_image, false );
				imagesavealpha( $true_color_image, true );

				// Para GIF, verificar se tem cor transparente definida.
				if ( 'gif' === $extension ) {
					$transparent_index = imagecolortransparent( $image );
					if ( -1 !== $transparent_index ) {
						// Obter a cor transparente e configurá-la no novo canvas.
						$transparent_color     = imagecolorsforindex( $image, $transparent_index );
						$transparent_color_new = imagecolorallocatealpha(
							$true_color_image,
							$transparent_color['red'],
							$transparent_color['green'],
							$transparent_color['blue'],
							127
						);

						// Preencher o fundo com transparência.
						imagefilledrectangle( $true_color_image, 0, 0, $width, $height, $transparent_color_new );
					} else {
						// Sem transparência, criar fundo transparente.
						$transparent = imagecolorallocatealpha( $true_color_image, 0, 0, 0, 127 );
						imagefilledrectangle( $true_color_image, 0, 0, $width, $height, $transparent );
					}
				} else {
					// Para PNG, criar fundo transparente.
					$transparent = imagecolorallocatealpha( $true_color_image, 0, 0, 0, 127 );
					imagefilledrectangle( $true_color_image, 0, 0, $width, $height, $transparent );
				}
			} else {
				// Para JPEG, usar fundo branco.
				$white = imagecolorallocate( $true_color_image, 255, 255, 255 );
				imagefilledrectangle( $true_color_image, 0, 0, $width, $height, $white );
			}

			// Copiar a imagem original para o novo canvas.
			imagecopy( $true_color_image, $image, 0, 0, 0, 0, $width, $height );
			imagedestroy( $image );
			$image = $true_color_image;

			// Para PNG, garantir que a transparência está preservada.
			if ( 'png' === $extension ) {
				imagealphablending( $image, false );
				imagesavealpha( $image, true );
			}
		}

		// Criar arquivo WebP temporário.
		$webp_path = wp_tempnam() . '.webp';

		// Converter para WebP.
		$success = imagewebp( $image, $webp_path, $quality );

		// Limpar memória.
		imagedestroy( $image );

		// Log da qualidade aplicada para debug.
		if ( function_exists( 'codir2me_cdn_log' ) ) {
			codir2me_cdn_log(
				sprintf(
					'WebP: Nível %s - Qualidade aplicada: %d%%',
					$optimization_level,
					$quality
				),
				'info'
			);
		}

		return $success ? $webp_path : false;
	}

	/**
	 * Converte uma imagem para AVIF (específico da aba de imagens)
	 *
	 * @param string $source_path Caminho da imagem original.
	 * @param int    $quality Qualidade da conversão (1-100).
	 * @return string|false Caminho do arquivo AVIF criado ou false em caso de erro
	 */
	private function codir2me_images_convert_to_avif( $source_path, $quality = 75 ) {
		if ( ! function_exists( 'imageavif' ) || ! file_exists( $source_path ) ) {
			return false;
		}

		// Obter nível de otimização das configurações.
		$optimization_options = get_option( 'codir2me_image_optimization_options', array() );
		$optimization_level   = isset( $optimization_options['optimization_level'] ) ? $optimization_options['optimization_level'] : 'balanced';

		// Ajustar qualidade baseado no nível de otimização.
		switch ( $optimization_level ) {
			case 'light':
				$quality = max( 80, $quality );
				break;
			case 'balanced':
				// Usar qualidade configurada.
				break;
			case 'aggressive':
				$quality = min( 65, $quality ); // Máximo 65% para aggressive
				// Para arquivos grandes, reduzir ainda mais.
				if ( file_exists( $source_path ) ) {
					$file_size = filesize( $source_path );
					if ( $file_size > 1024 * 1024 ) { // > 1MB
						$quality = min( 60, $quality );
					}
				}
				break;
		}

		$extension = strtolower( pathinfo( $source_path, PATHINFO_EXTENSION ) );
		$image     = null;

		// Carregar imagem baseada no tipo.
		switch ( $extension ) {
			case 'jpg':
			case 'jpeg':
				$image = imagecreatefromjpeg( $source_path );
				break;
			case 'png':
				$image = imagecreatefrompng( $source_path );
				break;
			case 'gif':
				$image = imagecreatefromgif( $source_path );
				break;
			default:
				return false;
		}

		if ( ! $image ) {
			return false;
		}

		// Garantir que a imagem está no modo true color (necessário para AVIF).
		if ( ! imageistruecolor( $image ) ) {
			$width            = imagesx( $image );
			$height           = imagesy( $image );
			$true_color_image = imagecreatetruecolor( $width, $height );

			// Configurar transparência para PNGs e GIFs.
			if ( 'png' === $extension || 'gif' === $extension ) {
				// Preservar transparência.
				imagealphablending( $true_color_image, false );
				imagesavealpha( $true_color_image, true );

				// Para GIF, verificar se tem cor transparente definida.
				if ( 'gif' === $extension ) {
					$transparent_index = imagecolortransparent( $image );
					if ( -1 !== $transparent_index ) {
						// Obter a cor transparente e configurá-la no novo canvas.
						$transparent_color     = imagecolorsforindex( $image, $transparent_index );
						$transparent_color_new = imagecolorallocatealpha(
							$true_color_image,
							$transparent_color['red'],
							$transparent_color['green'],
							$transparent_color['blue'],
							127
						);

						// Preencher o fundo com transparência.
						imagefilledrectangle( $true_color_image, 0, 0, $width, $height, $transparent_color_new );
					} else {
						// Sem transparência, criar fundo transparente.
						$transparent = imagecolorallocatealpha( $true_color_image, 0, 0, 0, 127 );
						imagefilledrectangle( $true_color_image, 0, 0, $width, $height, $transparent );
					}
				} else {
					// Para PNG, criar fundo transparente.
					$transparent = imagecolorallocatealpha( $true_color_image, 0, 0, 0, 127 );
					imagefilledrectangle( $true_color_image, 0, 0, $width, $height, $transparent );
				}
			} else {
				// Para JPEG, usar fundo branco.
				$white = imagecolorallocate( $true_color_image, 255, 255, 255 );
				imagefilledrectangle( $true_color_image, 0, 0, $width, $height, $white );
			}

			// Copiar a imagem original para o novo canvas.
			imagecopy( $true_color_image, $image, 0, 0, 0, 0, $width, $height );
			imagedestroy( $image );
			$image = $true_color_image;

			// Para PNG, garantir que a transparência está preservada.
			if ( 'png' === $extension ) {
				imagealphablending( $image, false );
				imagesavealpha( $image, true );
			}
		}

		// Verificar novamente se a imagem está em truecolor após a conversão.
		if ( function_exists( 'imagepalettetotruecolor' ) && ! imageistruecolor( $image ) ) {
			imagepalettetotruecolor( $image );
		}

		// Criar arquivo AVIF temporário.
		$avif_path = wp_tempnam() . '.avif';

		// Converter para AVIF.
		$success = imageavif( $image, $avif_path, $quality );

		// Limpar memória.
		imagedestroy( $image );

		// Log da qualidade aplicada para debug.
		if ( function_exists( 'codir2me_cdn_log' ) ) {
			codir2me_cdn_log(
				sprintf(
					'AVIF: Nível %s - Qualidade aplicada: %d%%',
					$optimization_level,
					$quality
				),
				'info'
			);
		}

		return $success ? $avif_path : false;
	}

	/**
	 * Processa conversões WebP/AVIF para uma imagem (específico da aba de imagens)
	 *
	 * @param string $file_path Caminho da imagem original.
	 * @param string $relative_path Caminho relativo para o R2.
	 * @param bool   $enable_webp Se deve gerar WebP.
	 * @param bool   $enable_avif Se deve gerar AVIF.
	 * @return array Resultado com caminhos dos arquivos gerados
	 */
	private function codir2me_images_process_conversions( $file_path, $relative_path, $enable_webp = false, $enable_avif = false ) {
		$license_status = get_option( 'codir2me_license_status', 'inactive' );
		if ( 'active' !== $license_status ) {
			return array(
				'success'            => true,
				'original_file'      => $file_path,
				'webp_file'          => null,
				'avif_file'          => null,
				'webp_relative_path' => null,
				'avif_relative_path' => null,
				'errors'             => array(),
			);
		}

		$result = array(
			'success'            => true,
			'original_file'      => $file_path,
			'webp_file'          => null,
			'avif_file'          => null,
			'webp_relative_path' => null,
			'avif_relative_path' => null,
			'errors'             => array(),
		);

		// Obter configurações de qualidade base.
		$webp_quality = get_option( 'codir2me_image_webp_quality', 80 );
		$avif_quality = get_option( 'codir2me_image_avif_quality', 75 );

		// Obter nível de otimização.
		$optimization_options = get_option( 'codir2me_image_optimization_options', array() );
		$optimization_level   = isset( $optimization_options['optimization_level'] ) ? $optimization_options['optimization_level'] : 'balanced';

		// Log do nível de otimização aplicado.
		if ( function_exists( 'codir2me_cdn_log' ) ) {
			codir2me_cdn_log(
				sprintf(
					'Processando conversões com nível de otimização: %s (WebP: %s, AVIF: %s)',
					$optimization_level,
					$enable_webp ? 'SIM' : 'NÃO',
					$enable_avif ? 'SIM' : 'NÃO'
				),
				'info'
			);
		}

		// Converter para WebP se solicitado.
		if ( $enable_webp ) {
			$webp_file = $this->codir2me_images_convert_to_webp( $file_path, $webp_quality );
			if ( $webp_file ) {
				$result['webp_file']          = $webp_file;
				$result['webp_relative_path'] = substr( $relative_path, 0, strrpos( $relative_path, '.' ) ) . '.webp';
			} else {
				$result['errors'][] = esc_html__( 'Falha na conversão para WebP', 'codirun-codir2me-cdn' );
			}
		}

		// Converter para AVIF se solicitado.
		if ( $enable_avif ) {
			$avif_file = $this->codir2me_images_convert_to_avif( $file_path, $avif_quality );
			if ( $avif_file ) {
				$result['avif_file']          = $avif_file;
				$result['avif_relative_path'] = substr( $relative_path, 0, strrpos( $relative_path, '.' ) ) . '.avif';
			} else {
				$result['errors'][] = esc_html__( 'Falha na conversão para AVIF', 'codirun-codir2me-cdn' );
			}
		}

		return $result;
	}

	/**
	 * Faz upload das versões convertidas (WebP/AVIF) para o R2
	 *
	 * @param array  $conversion_result Resultado das conversões.
	 * @param object $uploader Instância do uploader.
	 * @return bool Sucesso do upload
	 */
	private function codir2me_images_upload_conversions( $conversion_result, $uploader ) {
		$upload_success  = true;
		$uploaded_images = get_option( 'codir2me_uploaded_images', array() );

		// Upload da versão WebP.
		if ( isset( $conversion_result['webp_file'] ) && $conversion_result['webp_file'] ) {
			$webp_upload = $uploader->codir2me_upload_file(
				$conversion_result['webp_file'],
				$conversion_result['webp_relative_path']
			);

			if ( $webp_upload ) {
				// Adicionar à lista de imagens enviadas.
				if ( ! in_array( $conversion_result['webp_relative_path'], $uploaded_images, true ) ) {
					$uploaded_images[] = $conversion_result['webp_relative_path'];
				}
			} else {
				$upload_success = false;
			}

			// Limpar arquivo temporário.
			wp_delete_file( $conversion_result['webp_file'] );
		}

		// Upload da versão AVIF.
		if ( isset( $conversion_result['avif_file'] ) && $conversion_result['avif_file'] ) {
			$avif_upload = $uploader->codir2me_upload_file(
				$conversion_result['avif_file'],
				$conversion_result['avif_relative_path']
			);

			if ( $avif_upload ) {
				// Adicionar à lista de imagens enviadas.
				if ( ! in_array( $conversion_result['avif_relative_path'], $uploaded_images, true ) ) {
					$uploaded_images[] = $conversion_result['avif_relative_path'];
				}
			} else {
				$upload_success = false;
			}

			// Limpar arquivo temporário.
			wp_delete_file( $conversion_result['avif_file'] );
		}

		// Atualizar lista de imagens enviadas.
		if ( ! empty( $uploaded_images ) ) {
			update_option( 'codir2me_uploaded_images', array_unique( $uploaded_images ) );
		}

		return $upload_success;
	}
}
