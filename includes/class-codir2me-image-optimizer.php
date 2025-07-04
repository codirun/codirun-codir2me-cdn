<?php
/**
 * Classe responsável pela otimização de imagens antes do upload para o R2
 * Versão aprimorada com suporte a WebP e AVIF e salvamento local
 *
 * @package Codirun_R2_Media_Static_CDN
 */

// Evitar acesso direto ao arquivo.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classe para otimização de imagens com suporte a WebP e AVIF.
 */
class CODIR2ME_Image_Optimizer {
	/**
	 * Opções de otimização.
	 *
	 * @var array
	 */
	private $options;

	/**
	 * Status da otimização.
	 *
	 * @var bool
	 */
	private $enable_optimization;

	/**
	 * Nível de otimização.
	 *
	 * @var string
	 */
	private $optimization_level;

	/**
	 * Qualidade JPEG.
	 *
	 * @var int
	 */
	private $jpeg_quality;

	/**
	 * Compressão PNG.
	 *
	 * @var int
	 */
	private $png_compression;

	/**
	 * Qualidade WebP.
	 *
	 * @var int
	 */
	private $webp_quality;

	/**
	 * Qualidade AVIF.
	 *
	 * @var int
	 */
	private $avif_quality;

	/**
	 * Status da conversão WebP.
	 *
	 * @var bool
	 */
	private $enable_webp_conversion;

	/**
	 * Status da conversão AVIF.
	 *
	 * @var bool
	 */
	private $enable_avif_conversion;

	/**
	 * Manter arquivo original.
	 *
	 * @var bool
	 */
	private $keep_original;

	/**
	 * Elemento HTML usado.
	 *
	 * @var string
	 */
	private $html_element;

	/**
	 * Salvar arquivos convertidos localmente.
	 *
	 * @var bool
	 */
	private $save_converted_locally;

	/**
	 * Status dos filtros adicionados.
	 *
	 * @var bool
	 */
	private $filters_added = false;

	/**
	 * Construtor.
	 *
	 * @param array $custom_options Opções personalizadas para sobrescrever as do banco de dados.
	 */
	public function __construct( $custom_options = null ) {
		// Carregar opções de otimização (do banco ou personalizadas).
		$this->options = null !== $custom_options
			? $custom_options
			: get_option( 'codir2me_image_optimization_options', array() );

		// Definir valores padrão.
		$this->enable_optimization = isset( $this->options['enable_optimization'] )
			? (bool) $this->options['enable_optimization']
			: false;

		$this->optimization_level = isset( $this->options['optimization_level'] )
			? $this->options['optimization_level']
			: 'balanced';

		$this->jpeg_quality = isset( $this->options['jpeg_quality'] )
			? intval( $this->options['jpeg_quality'] )
			: 85;

		$this->png_compression = isset( $this->options['png_compression'] )
			? intval( $this->options['png_compression'] )
			: 7;

		$this->webp_quality = isset( $this->options['webp_quality'] )
			? intval( $this->options['webp_quality'] )
			: 80;

		$this->avif_quality = isset( $this->options['avif_quality'] )
			? intval( $this->options['avif_quality'] )
			: 75;

		$this->enable_webp_conversion = isset( $this->options['enable_webp_conversion'] )
			? (bool) $this->options['enable_webp_conversion']
			: false;

		$this->enable_avif_conversion = isset( $this->options['enable_avif_conversion'] )
			? (bool) $this->options['enable_avif_conversion']
			: false;

		$this->keep_original = isset( $this->options['keep_original'] )
			? (bool) $this->options['keep_original']
			: true;

		$this->html_element = isset( $this->options['html_element'] )
			? $this->options['html_element']
			: 'picture';

		// Nova opção para salvar localmente.
		$this->save_converted_locally = isset( $this->options['save_converted_locally'] )
			? (bool) $this->options['save_converted_locally']
			: false;

		// Adicionar hooks apenas se a otimização estiver ativada.
		if ( $this->enable_optimization ) {
			$this->codir2me_add_hooks();
		}

		// Registrar para as alterações de opções.
		add_action( 'update_option_codir2me_image_optimization_options', array( $this, 'codir2me_on_options_updated' ), 10, 2 );
	}

	/**
	 * Adiciona hooks para o processamento de imagens.
	 */
	private function codir2me_add_hooks() {
		// Já temos filtros adicionados?.
		if ( $this->filters_added ) {
			return;
		}

		// Interceptar o upload das imagens para otimizar antes de enviar para o R2.
		if ( $this->enable_optimization ) {
			// Usar prioridade 10 para o filtro.
			add_filter( 'codir2me_pre_upload_image', array( $this, 'codir2me_optimize_image' ), 10, 2 );
		}

		// Adicionar ação para processar uploads em massa.
		add_action( 'codir2me_before_batch_process', array( $this, 'codir2me_prepare_batch_optimization' ) );

		// Filtrar o conteúdo para substituir as tags de imagem.
		if ( $this->enable_optimization ) {
			// Alterar o HTML para usar o elemento picture quando possível.
			add_filter( 'the_content', array( $this, 'codir2me_replace_image_tags_with_picture' ), 999 );
			add_filter( 'post_thumbnail_html', array( $this, 'codir2me_replace_image_tags_with_picture' ), 999 );

			// Modificar o HTML dos widgets.
			add_filter( 'widget_text_content', array( $this, 'codir2me_replace_image_tags_with_picture' ), 999 );
		}

		// Marcar que adicionamos os filtros.
		$this->filters_added = true;
	}

	/**
	 * Remove hooks quando a otimização é desativada.
	 */
	private function codir2me_remove_hooks() {
		// Já removemos filtros?.
		if ( ! $this->filters_added ) {
			return;
		}

		// Remover filtro de otimização.
		remove_filter( 'codir2me_pre_upload_image', array( $this, 'codir2me_optimize_image' ), 10 );

		// Remover filtros de conteúdo.
		remove_filter( 'the_content', array( $this, 'codir2me_replace_image_tags_with_picture' ), 999 );
		remove_filter( 'post_thumbnail_html', array( $this, 'codir2me_replace_image_tags_with_picture' ), 999 );
		remove_filter( 'widget_text_content', array( $this, 'codir2me_replace_image_tags_with_picture' ), 999 );

		// Remover outros filtros que possam ter sido adicionados.
		$this->codir2me_remove_picture_filters();

		// Marcar que removemos os filtros.
		$this->filters_added = false;
	}

	/**
	 * Remove filtros específicos de picture.
	 */
	private function codir2me_remove_picture_filters() {
		// Woocommerce.
		if ( class_exists( 'WooCommerce' ) ) {
			remove_filter( 'woocommerce_single_product_image_thumbnail_html', array( $this, 'codir2me_replace_image_tags_with_picture' ), 999 );
			remove_filter( 'woocommerce_product_get_image', array( $this, 'codir2me_replace_image_tags_with_picture' ), 999 );
		}

		// Outros filtros comuns.
		remove_filter( 'wp_get_attachment_image', array( $this, 'codir2me_replace_image_tags_with_picture' ), 999 );
		remove_filter( 'get_image_tag', array( $this, 'codir2me_replace_image_tags_with_picture' ), 999 );
	}

	/**
	 * Manipula as alterações nas opções de otimização.
	 *
	 * @param mixed $old_value Valor antigo das opções.
	 * @param mixed $new_value Novo valor das opções.
	 */
	public function codir2me_on_options_updated( $old_value, $new_value ) {
		// Verificar se a otimização foi ativada/desativada.
		$old_enabled = isset( $old_value['enable_optimization'] ) ? (bool) $old_value['enable_optimization'] : false;
		$new_enabled = isset( $new_value['enable_optimization'] ) ? (bool) $new_value['enable_optimization'] : false;

		// Se mudou o estado de ativação.
		if ( $old_enabled !== $new_enabled ) {
			// Atualizar a propriedade.
			$this->enable_optimization = $new_enabled;

			// Adicionar ou remover hooks.
			if ( $new_enabled ) {
				$this->codir2me_add_hooks();
			} else {
				$this->codir2me_remove_hooks();
			}
		}

		// Atualizar outras propriedades.
		if ( $new_value ) {
			$this->options                = $new_value;
			$this->optimization_level     = isset( $new_value['optimization_level'] ) ? $new_value['optimization_level'] : 'balanced';
			$this->jpeg_quality           = isset( $new_value['jpeg_quality'] ) ? intval( $new_value['jpeg_quality'] ) : 85;
			$this->png_compression        = isset( $new_value['png_compression'] ) ? intval( $new_value['png_compression'] ) : 7;
			$this->webp_quality           = isset( $new_value['webp_quality'] ) ? intval( $new_value['webp_quality'] ) : 80;
			$this->avif_quality           = isset( $new_value['avif_quality'] ) ? intval( $new_value['avif_quality'] ) : 75;
			$this->enable_webp_conversion = isset( $new_value['enable_webp_conversion'] ) ? (bool) $new_value['enable_webp_conversion'] : false;
			$this->enable_avif_conversion = isset( $new_value['enable_avif_conversion'] ) ? (bool) $new_value['enable_avif_conversion'] : false;
			$this->keep_original          = isset( $new_value['keep_original'] ) ? (bool) $new_value['keep_original'] : true;
			$this->html_element           = isset( $new_value['html_element'] ) ? $new_value['html_element'] : 'picture';
			$this->save_converted_locally = isset( $new_value['save_converted_locally'] ) ? (bool) $new_value['save_converted_locally'] : false;
		}
	}

	/**
	 * Registra as configurações no WordPress.
	 */
	public function codir2me_register_settings() {
		register_setting(
			'codir2me_optimization_settings',
			'codir2me_image_optimization_options',
			array(
				'sanitize_callback' => array( $this, 'codir2me_sanitize_options' ),
			)
		);
	}

	/**
	 * Sanitiza as opções de otimização.
	 *
	 * @param array $input Valores de entrada.
	 * @return array Valores sanitizados.
	 */
	public function codir2me_sanitize_options( $input ) {
		$output = array();

		// Status da otimização.
		$output['enable_optimization'] = isset( $input['enable_optimization'] )
			? (bool) $input['enable_optimization']
			: false;

		// Nível de otimização.
		$valid_levels                 = array( 'light', 'balanced', 'aggressive' );
		$output['optimization_level'] = isset( $input['optimization_level'] ) && in_array( $input['optimization_level'], $valid_levels, true )
			? $input['optimization_level']
			: 'balanced';

		// Qualidade JPEG.
		$output['jpeg_quality'] = isset( $input['jpeg_quality'] )
			? intval( $input['jpeg_quality'] )
			: 85;

		if ( 1 > $output['jpeg_quality'] ) {
			$output['jpeg_quality'] = 1;
		}
		if ( 100 < $output['jpeg_quality'] ) {
			$output['jpeg_quality'] = 100;
		}

		// Compressão PNG.
		$output['png_compression'] = isset( $input['png_compression'] )
			? intval( $input['png_compression'] )
			: 7;

		if ( 0 > $output['png_compression'] ) {
			$output['png_compression'] = 0;
		}
		if ( 9 < $output['png_compression'] ) {
			$output['png_compression'] = 9;
		}

		// Qualidade WebP.
		$output['webp_quality'] = isset( $input['webp_quality'] )
			? intval( $input['webp_quality'] )
			: 80;

		if ( 1 > $output['webp_quality'] ) {
			$output['webp_quality'] = 1;
		}
		if ( 100 < $output['webp_quality'] ) {
			$output['webp_quality'] = 100;
		}

		// Qualidade AVIF.
		$output['avif_quality'] = isset( $input['avif_quality'] )
			? intval( $input['avif_quality'] )
			: 75;

		if ( 1 > $output['avif_quality'] ) {
			$output['avif_quality'] = 1;
		}
		if ( 100 < $output['avif_quality'] ) {
			$output['avif_quality'] = 100;
		}

		// Habilitar conversão para WebP.
		$output['enable_webp_conversion'] = isset( $input['enable_webp_conversion'] )
			? (bool) $input['enable_webp_conversion']
			: false;

		// Habilitar conversão para AVIF.
		$output['enable_avif_conversion'] = isset( $input['enable_avif_conversion'] )
			? (bool) $input['enable_avif_conversion']
			: false;

		// Manter original após conversão.
		$output['keep_original'] = isset( $input['keep_original'] )
			? (bool) $input['keep_original']
			: true;

		// Elemento HTML para uso.
		$output['html_element'] = isset( $input['html_element'] ) && in_array( $input['html_element'], array( 'picture', 'img' ), true )
			? $input['html_element']
			: 'picture';

		// Nova opção: Salvar localmente.
		$output['save_converted_locally'] = isset( $input['save_converted_locally'] )
			? (bool) $input['save_converted_locally']
			: false;

		return $output;
	}

	/**
	 * Otimiza uma imagem.
	 * Versão melhorada para lidar com todos os tipos de imagens e salvamento local.
	 *
	 * @param string $file_path Caminho do arquivo de imagem.
	 * @param string $relative_path Caminho relativo no R2.
	 * @return array Dados do arquivo otimizado.
	 */
	public function codir2me_optimize_image( $file_path, $relative_path ) {
		$license_status = get_option( 'codir2me_license_status', 'inactive' );
		if ( 'active' !== $license_status ) {
			return array(
				'file_path'     => $file_path,
				'relative_path' => $relative_path,
				'optimized'     => false,
				'reason'        => 'Licença inativa',
			);
		}

		// Verificação crítica: se file_path não for uma string.
		if ( ! is_string( $file_path ) ) {
			// Registra o erro no log do seu plugin sem usar funções de depuração.
			codir2me_cdn_log(
				sprintf(
				/* translators: %s é a representação string do parâmetro incorreto */
					__( 'R2 CDN - Erro: codir2me_optimize_image recebeu um parâmetro file_path não-string: %s', 'codirun-codir2me-cdn' ),
					wp_json_encode( $file_path ) // Usando wp_json_encode para transformar em uma string.
				)
			);

			return array(
				'file_path'     => '',
				'relative_path' => is_string( $relative_path ) ? $relative_path : '',
				'optimized'     => false,
				'reason'        => __( 'Caminho do arquivo inválido', 'codirun-codir2me-cdn' ),
			);
		}

		// Se a otimização estiver desativada, retornar sem alterações.
		if ( ! $this->enable_optimization ) {
			return array(
				'file_path'     => $file_path,
				'relative_path' => $relative_path,
				'optimized'     => false,
				'reason'        => __( 'Otimização desativada', 'codirun-codir2me-cdn' ),
			);
		}

		// Verificar se é uma imagem.
		if ( ! $this->codir2me_is_image( $file_path ) ) {
			return array(
				'file_path'     => $file_path,
				'relative_path' => $relative_path,
				'optimized'     => false,
				'reason'        => __( 'Arquivo não é uma imagem', 'codirun-codir2me-cdn' ),
			);
		}

		// Verificar se as extensões necessárias estão disponíveis.
		if ( ! $this->codir2me_check_optimization_requirements()['any_met'] ) {
			return array(
				'file_path'     => $file_path,
				'relative_path' => $relative_path,
				'optimized'     => false,
				'error'         => __( 'Requisitos para otimização não estão disponíveis', 'codirun-codir2me-cdn' ),
			);
		}

		// Obter informações do arquivo original.
		$original_size = filesize( $file_path );

		// Usar função mais confiável para determinar o tipo de imagem.
		$image_info = getimagesize( $file_path );
		if ( false === $image_info ) {
			return array(
				'file_path'     => $file_path,
				'relative_path' => $relative_path,
				'optimized'     => false,
				'error'         => __( 'Não foi possível obter informações da imagem', 'codirun-codir2me-cdn' ),
			);
		}

		$mime_type = $image_info['mime'];

		// Criar cópias temporárias no diretório de uploads.
		$upload_dir = wp_upload_dir();
		$temp_dir   = $upload_dir['basedir'] . '/codir2me_temp';

		// Verificar e criar diretório temporário se necessário.
		if ( ! file_exists( $temp_dir ) ) {
			wp_mkdir_p( $temp_dir );
		}

		// Nome de arquivo temporário para a versão otimizada.
		$optimized_file = $temp_dir . '/' . uniqid() . '_opt_' . basename( $file_path );

		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();

		$source_content = $wp_filesystem->get_contents( $file_path );
		if ( false === $source_content || ! $wp_filesystem->put_contents( $optimized_file, $source_content, 0644 ) ) {
			return array(
				'file_path'     => $file_path,
				'relative_path' => $relative_path,
				'optimized'     => false,
				'error'         => __( 'Erro ao criar arquivo temporário', 'codirun-codir2me-cdn' ),
			);
		}

		// Otimizar a imagem com base no tipo.
		$optimization_result = false;

		try {
			switch ( $mime_type ) {
				case 'image/jpeg':
					$optimization_result = $this->codir2me_optimize_jpeg( $optimized_file );
					break;

				case 'image/png':
					$optimization_result = $this->codir2me_optimize_png( $optimized_file );
					break;

				case 'image/webp':
					$optimization_result = $this->codir2me_optimize_webp( $optimized_file );
					break;

				case 'image/gif':
					// GIFs geralmente não são otimizados, apenas manter o original.
					$optimization_result = true;
					break;
			}
		} catch ( Exception $e ) {
			// Se houver erro, limpar o arquivo temporário e retornar o original.
			if ( $wp_filesystem->exists( $optimized_file ) ) {
				$wp_filesystem->delete( $optimized_file );
			}

			return array(
				'file_path'     => $file_path,
				'relative_path' => $relative_path,
				'optimized'     => false,
				'error'         => sprintf(
					/* translators: %s é a mensagem de erro da exceção */
					__( 'Erro durante otimização: %s', 'codirun-codir2me-cdn' ),
					$e->getMessage()
				),
			);
		}

		// Verificar se o arquivo foi realmente otimizado.
		$new_size      = filesize( $optimized_file );
		$saved_bytes   = $original_size - $new_size;
		$percent_saved = ( $saved_bytes / $original_size ) * 100;

		// Se o tamanho não foi reduzido, usar o original.
		if ( $new_size >= $original_size ) {
			if ( $wp_filesystem->exists( $optimized_file ) ) {
				$wp_filesystem->delete( $optimized_file );
			}

			$optimized_file = $file_path;
			$new_size       = $original_size;
			$saved_bytes    = 0;
			$percent_saved  = 0;
		}

		// Inicializar resultados.
		$result = array(
			'file_path'     => $optimized_file,
			'relative_path' => $relative_path,
			'optimized'     => ( $new_size < $original_size ),
			'original_size' => $original_size,
			'new_size'      => $new_size,
			'saved_bytes'   => $saved_bytes,
			'percent_saved' => $percent_saved,
			'original_path' => $file_path, // Guarda o caminho original para referência.
		);

		// Detecção inteligente para miniaturas.
		$is_thumbnail = false;
		$filename     = basename( $file_path );
		if ( preg_match( '/-(\d+x\d+)\.[a-zA-Z]+$/', $filename ) || preg_match( '/-([a-zA-Z_]+)\.[a-zA-Z]+$/', $filename ) ) {
			$is_thumbnail           = true;
			$result['is_thumbnail'] = true;
		}

		// Processar WebP se habilitado (e se não for já um WebP).
		$webp_file          = null;
		$webp_relative_path = null;

		if ( $this->enable_webp_conversion && function_exists( 'imagewebp' ) && 'image/webp' !== $mime_type ) {
			$webp_file = $this->codir2me_convert_to_webp( $file_path );

			if ( $webp_file && file_exists( $webp_file ) && 0 < filesize( $webp_file ) ) {
				$webp_size          = filesize( $webp_file );
				$webp_relative_path = $this->codir2me_get_webp_path( $relative_path );

				$result['webp_file']          = $webp_file;
				$result['webp_relative_path'] = $webp_relative_path;
				$result['webp_size']          = $webp_size;
				$result['webp_percent_saved'] = ( ( $original_size - $webp_size ) / $original_size ) * 100;

				// Salvar localmente se configurado.
				if ( $this->save_converted_locally ) {
					$this->codir2me_save_locally( $webp_file, $webp_relative_path );
				}
			}
		}

		// Processar AVIF se habilitado (e se não for já um AVIF).
		$avif_file          = null;
		$avif_relative_path = null;

		if ( $this->enable_avif_conversion && function_exists( 'imageavif' ) && 'image/avif' !== $mime_type ) {
			$avif_file = $this->codir2me_convert_to_avif( $file_path );

			if ( $avif_file && file_exists( $avif_file ) && 0 < filesize( $avif_file ) ) {
				$avif_size          = filesize( $avif_file );
				$avif_relative_path = $this->codir2me_get_avif_path( $relative_path );

				$result['avif_file']          = $avif_file;
				$result['avif_relative_path'] = $avif_relative_path;
				$result['avif_size']          = $avif_size;
				$result['avif_percent_saved'] = ( ( $original_size - $avif_size ) / $original_size ) * 100;

				// Salvar localmente se configurado.
				if ( $this->save_converted_locally ) {
					$this->codir2me_save_locally( $avif_file, $avif_relative_path );
				}
			}
		}

		// Decidir o que fazer com o original.
		// Se não quisermos manter o original E temos pelo menos uma conversão bem-sucedida.
		if ( ! $this->keep_original &&
			( ( isset( $webp_file ) && isset( $webp_size ) && 0 < filesize( $webp_file ) && $webp_size < $original_size ) ||
			( isset( $avif_file ) && isset( $avif_size ) && 0 < filesize( $avif_file ) && $avif_size < $original_size ) ) ) {

			// Adicionado flag para indicar que o original não deve ser enviado.
			$result['skip_original'] = true;

			// Escolher o formato mais otimizado como resultado principal.
			if ( isset( $avif_file ) && 0 < filesize( $avif_file ) &&
				( ( ! isset( $webp_file ) ) || ( ! isset( $webp_size ) ) ||
				( isset( $avif_size ) && isset( $webp_size ) && $avif_size < $webp_size ) ) ) {

				$result['primary_format']        = 'avif';
				$result['primary_file']          = $avif_file;
				$result['primary_relative_path'] = $avif_relative_path;
			} elseif ( isset( $webp_file ) && 0 < filesize( $webp_file ) ) {
				$result['primary_format']        = 'webp';
				$result['primary_file']          = $webp_file;
				$result['primary_relative_path'] = $webp_relative_path;
			}
		} else {
			$result['primary_format'] = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
		}

		// Atualizar as estatísticas.
		$this->codir2me_update_stats( $result );

		return $result;
	}

	/**
	 * Salva uma imagem localmente no servidor.
	 *
	 * @param string $source_file Caminho do arquivo fonte.
	 * @param string $relative_path Caminho relativo para salvar.
	 * @return bool Sucesso ou falha.
	 */
	private function codir2me_save_locally( $source_file, $relative_path ) {
		$upload_dir = wp_upload_dir();

		// Salvar no diretório de uploads do WordPress.
		$target_file = $upload_dir['basedir'] . '/codirun-codir2me-cdn/' . $relative_path;

		// Verificar se o diretório de destino existe.
		$target_dir = dirname( $target_file );
		if ( ! file_exists( $target_dir ) ) {
			wp_mkdir_p( $target_dir );
		}

		// Inicializar WP_Filesystem para operações de arquivo seguras.
		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();

		// Usar WP_Filesystem para copiar o arquivo.
		$source_content = $wp_filesystem->get_contents( $source_file );
		if ( false === $source_content ) {
			codir2me_cdn_log(
				sprintf(
				/* translators: %s é o caminho do arquivo fonte que falhou ao ser lido */
					__( 'R2 CDN - Erro ao ler arquivo fonte: %s', 'codirun-codir2me-cdn' ),
					$source_file
				)
			);
			return false;
		}

		// Salvar usando WP_Filesystem.
		if ( $wp_filesystem->put_contents( $target_file, $source_content, 0644 ) ) {
			codir2me_cdn_log(
				sprintf(
				/* translators: %s é o caminho do arquivo salvo */
					__( 'R2 CDN - Arquivo convertido salvo localmente: %s', 'codirun-codir2me-cdn' ),
					$target_file
				)
			);
			return true;
		} else {
			codir2me_cdn_log(
				sprintf(
				/* translators: %s é o caminho do arquivo que falhou ao ser salvo */
					__( 'R2 CDN - Erro ao salvar arquivo localmente: %s', 'codirun-codir2me-cdn' ),
					$target_file
				)
			);
			return false;
		}
	}

	/**
	 * Otimiza uma imagem JPEG.
	 *
	 * @param string $file_path Caminho do arquivo.
	 * @return bool Sucesso ou falha.
	 */
	private function codir2me_optimize_jpeg( $file_path ) {
		// Determinar a qualidade com base no nível de otimização.
		$quality = $this->jpeg_quality;

		switch ( $this->optimization_level ) {
			case 'light':
				$quality = 90;
				break;

			case 'balanced':
				$quality = 85;
				break;

			case 'aggressive':
				$quality = 65;
				break;
		}

		try {
			// Tentar usar GD.
			if ( extension_loaded( 'gd' ) && function_exists( 'imagecreatefromjpeg' ) ) {
				$image = imagecreatefromjpeg( $file_path );

				if ( ! $image ) {
					return false;
				}

				$result = imagejpeg( $image, $file_path, $quality );
				imagedestroy( $image );

				return $result;
			}

			// Fallback para Imagick.
			if ( extension_loaded( 'imagick' ) && class_exists( 'Imagick' ) ) {
				$image = new Imagick( $file_path );

				// Otimização avançada para nível agressivo.
				if ( 'aggressive' === $this->optimization_level ) {
					// Remover todos os perfis de cor e metadados.
					$image->stripImage();
				}

				$image->setImageCompression( Imagick::COMPRESSION_JPEG );
				$image->setImageCompressionQuality( $quality );

				// Remover metadados EXIF para economizar mais espaço.
				$image->stripImage();

				$result = $image->writeImage( $file_path );
				$image->clear();
				$image->destroy();

				return $result;
			}

			return false;
		} catch ( Exception $e ) {
			codir2me_cdn_log(
				sprintf(
				/* translators: %s é a mensagem de erro da exceção */
					__( 'R2 CDN - Erro ao otimizar JPEG: %s', 'codirun-codir2me-cdn' ),
					$e->getMessage()
				)
			);
			return false;
		}
	}

	/**
	 * Otimiza uma imagem PNG.
	 *
	 * @param string $file_path Caminho do arquivo.
	 * @return bool Sucesso ou falha.
	 */
	private function codir2me_optimize_png( $file_path ) {
		$compression = $this->png_compression;

		// Ajustar nível de compressão baseado no nível de otimização.
		switch ( $this->optimization_level ) {
			case 'light':
				$compression = min( 5, $compression );
				break;

			case 'balanced':
				// Usar configuração padrão.
				break;

			case 'aggressive':
				$compression = 9; // Máxima compressão sempre para nível agressivo.
				break;
		}

		try {
			// Tentar usar GD.
			if ( extension_loaded( 'gd' ) && function_exists( 'imagecreatefrompng' ) ) {
				$image = imagecreatefrompng( $file_path );

				if ( ! $image ) {
					return false;
				}

				// Preservar transparência.
				imagealphablending( $image, false );
				imagesavealpha( $image, true );

				$result = imagepng( $image, $file_path, $compression );
				imagedestroy( $image );

				return $result;
			}

			// Fallback para Imagick.
			if ( extension_loaded( 'imagick' ) && class_exists( 'Imagick' ) ) {
				$image = new Imagick( $file_path );

				// Obter tamanho original do arquivo.
				$original_size = filesize( $file_path );

				// Compressão.
				$image->setImageCompression( Imagick::COMPRESSION_ZIP );
				$image->setCompressionQuality( $compression * 10 ); // 0-90 para PNG.

				// Remover metadados desnecessários.
				$image->stripImage();

				$result = $image->writeImage( $file_path );
				$image->clear();
				$image->destroy();

				return $result;
			}

			return false;
		} catch ( Exception $e ) {
			codir2me_cdn_log(
				sprintf(
				/* translators: %s é a mensagem de erro da exceção */
					__( 'R2 CDN - Erro ao otimizar PNG: %s', 'codirun-codir2me-cdn' ),
					$e->getMessage()
				)
			);
			return false;
		}
	}

	/**
	 * Otimiza uma imagem WebP.
	 *
	 * @param string $file_path Caminho do arquivo.
	 * @return bool Sucesso ou falha.
	 */
	private function codir2me_optimize_webp( $file_path ) {
		$quality = $this->webp_quality;

		// Ajustar qualidade com base no nível de otimização.
		switch ( $this->optimization_level ) {
			case 'light':
				$quality = max( 85, $quality );
				break;

			case 'aggressive':
				$quality = min( 70, $quality );
				break;
		}

		try {
			// Tentar usar GD.
			if ( extension_loaded( 'gd' ) && function_exists( 'imagecreatefromwebp' ) ) {
				$image = imagecreatefromwebp( $file_path );

				if ( ! $image ) {
					return false;
				}

				$result = imagewebp( $image, $file_path, $quality );
				imagedestroy( $image );

				return $result;
			}

			// Fallback para Imagick.
			if ( extension_loaded( 'imagick' ) && class_exists( 'Imagick' ) ) {
				$image = new Imagick( $file_path );
				$image->setImageCompressionQuality( $quality );

				// Remover metadados.
				$image->stripImage();

				$result = $image->writeImage( $file_path );
				$image->clear();
				$image->destroy();

				return $result;
			}

			return false;
		} catch ( Exception $e ) {
			codir2me_cdn_log(
				sprintf(
				/* translators: %s é a mensagem de erro da exceção */
					__( 'R2 CDN - Erro ao otimizar WebP: %s', 'codirun-codir2me-cdn' ),
					$e->getMessage()
				)
			);
			return false;
		}
	}

	/**
	 * Converte uma imagem para o formato WebP.
	 * Versão corrigida para lidar com PNGs com paleta de cores.
	 *
	 * @param string $source_file Arquivo fonte.
	 * @return string|false Caminho do arquivo WebP gerado ou False em caso de falha.
	 */
	private function codir2me_convert_to_webp( $source_file ) {
		// Verificar se a função está disponível.
		if ( ! function_exists( 'imagewebp' ) ) {
			codir2me_cdn_log( __( 'R2 CDN - Função imagewebp não está disponível', 'codirun-codir2me-cdn' ) );
			return false;
		}

		$quality = $this->webp_quality;

		// No modo agressivo, reduzir qualidade para arquivos grandes.
		if ( 'aggressive' === $this->optimization_level ) {
			$file_size = filesize( $source_file );
			if ( 1024 * 1024 < $file_size ) { // > 1MB.
				$quality = min( 65, $quality );
			}
		}

		$webp_file = $this->codir2me_get_webp_filename( $source_file );

		try {
			// Usar getimagesize em vez de exif_imagetype para maior compatibilidade.
			$image_info = getimagesize( $source_file );
			if ( false === $image_info ) {
				codir2me_cdn_log( __( 'R2 CDN - Não foi possível obter informações da imagem para WebP', 'codirun-codir2me-cdn' ) );
				return false;
			}

			$image = null;

			switch ( $image_info[2] ) {
				case IMAGETYPE_JPEG:
					$image = imagecreatefromjpeg( $source_file );
					break;

				case IMAGETYPE_PNG:
					$image = imagecreatefrompng( $source_file );

					// Correção para PNGs com paleta de cores.
					if ( $image ) {
						// Verificar se a imagem é true color, se não for, convertê-la.
						if ( ! imageistruecolor( $image ) ) {
							$width            = imagesx( $image );
							$height           = imagesy( $image );
							$true_color_image = imagecreatetruecolor( $width, $height );

							// Preservar transparência.
							imagealphablending( $true_color_image, false );
							imagesavealpha( $true_color_image, true );

							// Colocar fundo transparente.
							$transparent = imagecolorallocatealpha( $true_color_image, 0, 0, 0, 127 );
							imagefilledrectangle( $true_color_image, 0, 0, $width, $height, $transparent );

							// Copiar a imagem original para o novo canvas.
							imagecopy( $true_color_image, $image, 0, 0, 0, 0, $width, $height );
							imagedestroy( $image );
							$image = $true_color_image;
						}

						// Garantir que a transparência está preservada.
						imagealphablending( $image, false );
						imagesavealpha( $image, true );
					}
					break;

				case IMAGETYPE_GIF:
					$image = imagecreatefromgif( $source_file );

					// Converter GIF para truecolor para WebP.
					if ( $image ) {
						$width            = imagesx( $image );
						$height           = imagesy( $image );
						$true_color_image = imagecreatetruecolor( $width, $height );

						// Configurar transparência.
						$transparent = imagecolorallocatealpha( $true_color_image, 0, 0, 0, 127 );
						imagefilledrectangle( $true_color_image, 0, 0, $width, $height, $transparent );
						imagealphablending( $true_color_image, false );
						imagesavealpha( $true_color_image, true );

						// Copiar a imagem original.
						imagecopy( $true_color_image, $image, 0, 0, 0, 0, $width, $height );
						imagedestroy( $image );
						$image = $true_color_image;
					}
					break;

				default:
					return false;
			}

			if ( ! $image ) {
				codir2me_cdn_log( __( 'R2 CDN - Falha ao criar imagem para conversão WebP', 'codirun-codir2me-cdn' ) );
				return false;
			}

			// Verificar se podemos criar arquivos WebP.
			if ( ! function_exists( 'imagewebp' ) ) {
				imagedestroy( $image );
				codir2me_cdn_log( __( 'R2 CDN - Função imagewebp não disponível', 'codirun-codir2me-cdn' ) );
				return false;
			}

			// Criar WebP.
			$result = imagewebp( $image, $webp_file, $quality );
			imagedestroy( $image );

			if ( ! $result ) {
				codir2me_cdn_log( __( 'R2 CDN - Falha ao salvar arquivo WebP', 'codirun-codir2me-cdn' ) );
				return false;
			}

			// Verificar se o arquivo WebP foi criado e tem tamanho válido.
			if ( ! file_exists( $webp_file ) || 0 === filesize( $webp_file ) ) {
				codir2me_cdn_log( __( 'R2 CDN - Arquivo WebP criado tem tamanho zero ou não existe', 'codirun-codir2me-cdn' ) );
				if ( file_exists( $webp_file ) ) {
					wp_delete_file( $webp_file );
				}
				return false;
			}

			codir2me_cdn_log(
				sprintf(
				/* translators: %d é o tamanho do arquivo em bytes */
					__( 'R2 CDN - Conversão para WebP bem-sucedida, tamanho: %d bytes', 'codirun-codir2me-cdn' ),
					filesize( $webp_file )
				)
			);
			return $webp_file;

		} catch ( Exception $e ) {
			codir2me_cdn_log(
				sprintf(
				/* translators: %s é a mensagem de erro da exceção */
					__( 'R2 CDN - Erro ao converter para WebP: %s', 'codirun-codir2me-cdn' ),
					$e->getMessage()
				)
			);
			if ( isset( $image ) && $image ) {
				imagedestroy( $image );
			}
			if ( file_exists( $webp_file ) ) {
				wp_delete_file( $webp_file );
			}
			return false;
		}
	}

	/**
	 * Converte uma imagem para o formato AVIF.
	 * Versão corrigida para garantir maior compatibilidade.
	 *
	 * @param string $source_file Arquivo fonte.
	 * @return string|false Caminho do arquivo AVIF gerado ou False em caso de falha.
	 */
	private function codir2me_convert_to_avif( $source_file ) {
		// Verificar se a função está disponível.
		if ( ! function_exists( 'imageavif' ) ) {
			codir2me_cdn_log( __( 'R2 CDN - Função imageavif não está disponível', 'codirun-codir2me-cdn' ) );
			return false;
		}

		codir2me_cdn_log(
			sprintf(
			/* translators: %s é o caminho do arquivo fonte */
				__( 'R2 CDN - Iniciando conversão para AVIF: %s', 'codirun-codir2me-cdn' ),
				$source_file
			)
		);

		$quality = $this->avif_quality;

		// No modo agressivo, reduzir qualidade para arquivos grandes.
		if ( 'aggressive' === $this->optimization_level ) {
			$file_size = filesize( $source_file );
			if ( 1024 * 1024 < $file_size ) { // > 1MB.
				$quality = min( 60, $quality );
			}
		}

		$avif_file = $this->codir2me_get_avif_filename( $source_file );
		codir2me_cdn_log(
			sprintf(
			/* translators: %s é o caminho do arquivo de destino */
				__( 'R2 CDN - Arquivo AVIF de destino: %s', 'codirun-codir2me-cdn' ),
				$avif_file
			)
		);

		try {
			// Obter informações da imagem de forma segura.
			$image_info = getimagesize( $source_file );
			if ( false === $image_info ) {
				codir2me_cdn_log( __( 'R2 CDN - Não foi possível obter informações da imagem para AVIF', 'codirun-codir2me-cdn' ) );
				return false;
			}

			$image_type = $image_info[2]; // IMAGETYPE_XXX constante.
			codir2me_cdn_log(
				sprintf(
				/* translators: %d é o tipo numérico da imagem */
					__( 'R2 CDN - Tipo de imagem detectado: %d', 'codirun-codir2me-cdn' ),
					$image_type
				)
			);

			$image = null;

			// Usar mime_type ou o tipo numérico conforme disponível.
			$mime_type = isset( $image_info['mime'] ) ? $image_info['mime'] : '';

			// Carregar a imagem com base no tipo.
			if ( 'image/jpeg' === $mime_type || IMAGETYPE_JPEG === $image_type ) {
				codir2me_cdn_log( __( 'R2 CDN - Carregando imagem JPEG', 'codirun-codir2me-cdn' ) );
				$image = imagecreatefromjpeg( $source_file );
			} elseif ( 'image/png' === $mime_type || IMAGETYPE_PNG === $image_type ) {
				codir2me_cdn_log( __( 'R2 CDN - Carregando imagem PNG', 'codirun-codir2me-cdn' ) );
				$image = imagecreatefrompng( $source_file );

				// Correção para PNGs com paleta de cores.
				if ( $image ) {
					// Verificar se a imagem é true color, se não for, convertê-la.
					if ( ! imageistruecolor( $image ) ) {
						$width            = imagesx( $image );
						$height           = imagesy( $image );
						$true_color_image = imagecreatetruecolor( $width, $height );

						// Preservar transparência.
						imagealphablending( $true_color_image, false );
						imagesavealpha( $true_color_image, true );

						// Fundo transparente.
						$transparent = imagecolorallocatealpha( $true_color_image, 0, 0, 0, 127 );
						imagefilledrectangle( $true_color_image, 0, 0, $width, $height, $transparent );

						// Copiar a imagem original.
						imagecopy( $true_color_image, $image, 0, 0, 0, 0, $width, $height );
						imagedestroy( $image );
						$image = $true_color_image;
					}

					// Garantir que a transparência está preservada.
					imagealphablending( $image, false );
					imagesavealpha( $image, true );
				}
			} elseif ( 'image/gif' === $mime_type || IMAGETYPE_GIF === $image_type ) {
				codir2me_cdn_log( __( 'R2 CDN - Carregando imagem GIF', 'codirun-codir2me-cdn' ) );
				$image = imagecreatefromgif( $source_file );

				// Converter GIF para truecolor para AVIF.
				if ( $image ) {
					$width            = imagesx( $image );
					$height           = imagesy( $image );
					$true_color_image = imagecreatetruecolor( $width, $height );

					// Verificar se o GIF tem uma cor transparente definida.
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
						imagealphablending( $true_color_image, false );
						imagesavealpha( $true_color_image, true );
					} else {
						// Sem transparência, fundo branco.
						$white = imagecolorallocate( $true_color_image, 255, 255, 255 );
						imagefilledrectangle( $true_color_image, 0, 0, $width, $height, $white );
					}

					// Copiar a imagem original.
					imagecopy( $true_color_image, $image, 0, 0, 0, 0, $width, $height );
					imagedestroy( $image );
					$image = $true_color_image;
				}
			} elseif ( 'image/webp' === $mime_type || ( defined( 'IMAGETYPE_WEBP' ) && IMAGETYPE_WEBP === $image_type ) ) {
				codir2me_cdn_log( __( 'R2 CDN - Carregando imagem WebP', 'codirun-codir2me-cdn' ) );
				if ( function_exists( 'imagecreatefromwebp' ) ) {
					$image = imagecreatefromwebp( $source_file );
				} else {
					codir2me_cdn_log( __( 'R2 CDN - Função imagecreatefromwebp não disponível', 'codirun-codir2me-cdn' ) );
					return false;
				}
			} else {
				codir2me_cdn_log(
					sprintf(
					/* translators: %s é o tipo de imagem não suportado */
						__( 'R2 CDN - Tipo de imagem não suportado: %s', 'codirun-codir2me-cdn' ),
						( $mime_type ? $mime_type : $image_type )
					)
				);
				return false;
			}

			// Verificar se a imagem foi carregada com sucesso.
			if ( ! $image ) {
				codir2me_cdn_log( __( 'R2 CDN - Falha ao criar imagem para conversão AVIF', 'codirun-codir2me-cdn' ) );
				return false;
			}

			// Garantir que a imagem está no modo true color (necessário para AVIF).
			if ( function_exists( 'imagepalettetotruecolor' ) && ! imageistruecolor( $image ) ) {
				imagepalettetotruecolor( $image );
			}

			codir2me_cdn_log(
				sprintf(
				/* translators: %d é o valor de qualidade */
					__( 'R2 CDN - Salvando imagem AVIF com qualidade: %d', 'codirun-codir2me-cdn' ),
					$quality
				)
			);

			// Verificar se a função imageavif existe.
			if ( ! function_exists( 'imageavif' ) ) {
				codir2me_cdn_log( __( 'R2 CDN - Função imageavif não disponível no momento da conversão', 'codirun-codir2me-cdn' ) );
				imagedestroy( $image );
				return false;
			}

			// Tentar salvar como AVIF.
			$result = false;

			// Definir constantes de qualidade para AVIF se disponíveis.
			if ( defined( 'IMAGEAVIF_QUALITY_DEFAULT' ) ) {
				$result = imageavif( $image, $avif_file, $quality, IMAGEAVIF_QUALITY_DEFAULT );
			} else {
				$result = imageavif( $image, $avif_file, $quality );
			}

			// Liberar memória.
			imagedestroy( $image );

			// Verificar o resultado.
			if ( ! $result ) {
				codir2me_cdn_log( __( 'R2 CDN - Falha ao salvar arquivo AVIF', 'codirun-codir2me-cdn' ) );
				return false;
			}

			// Verificar se o arquivo foi criado e tem tamanho válido.
			if ( ! file_exists( $avif_file ) || 0 === filesize( $avif_file ) ) {
				codir2me_cdn_log( __( 'R2 CDN - Arquivo AVIF não foi criado corretamente', 'codirun-codir2me-cdn' ) );
				if ( file_exists( $avif_file ) ) {
					wp_delete_file( $avif_file );
				}
				return false;
			}

			codir2me_cdn_log(
				sprintf(
				/* translators: %d é o tamanho do arquivo em bytes */
					__( 'R2 CDN - Conversão AVIF bem-sucedida: %d bytes', 'codirun-codir2me-cdn' ),
					filesize( $avif_file )
				)
			);
			return $avif_file;

		} catch ( Exception $e ) {
			codir2me_cdn_log(
				sprintf(
				/* translators: %s é a mensagem de erro da exceção */
					__( 'R2 CDN - Erro ao converter para AVIF: %s', 'codirun-codir2me-cdn' ),
					$e->getMessage()
				)
			);
			if ( isset( $image ) && $image ) {
				imagedestroy( $image );
			}
			if ( file_exists( $avif_file ) ) {
				wp_delete_file( $avif_file );
			}
			return false;
		}
	}

	/**
	 * Gera o nome de arquivo WebP para um arquivo de imagem.
	 *
	 * @param string $file_path Caminho do arquivo original.
	 * @return string Caminho do arquivo WebP.
	 */
	private function codir2me_get_webp_filename( $file_path ) {
		$path_info = pathinfo( $file_path );

		$upload_dir = wp_upload_dir();
		$temp_dir   = $upload_dir['basedir'] . '/codir2me_temp';

		return $temp_dir . '/' . uniqid() . '_' . $path_info['filename'] . '.webp';
	}

	/**
	 * Gera o nome de arquivo AVIF para um arquivo de imagem.
	 *
	 * @param string $file_path Caminho do arquivo original.
	 * @return string Caminho do arquivo AVIF.
	 */
	private function codir2me_get_avif_filename( $file_path ) {
		$path_info = pathinfo( $file_path );

		$upload_dir = wp_upload_dir();
		$temp_dir   = $upload_dir['basedir'] . '/codir2me_temp';

		return $temp_dir . '/' . uniqid() . '_' . $path_info['filename'] . '.avif';
	}

	/**
	 * Converte um caminho relativo para o formato WebP.
	 *
	 * @param string $relative_path Caminho relativo.
	 * @return string Caminho relativo WebP.
	 */
	private function codir2me_get_webp_path( $relative_path ) {
		$path_info = pathinfo( $relative_path );
		return $path_info['dirname'] . '/' . $path_info['filename'] . '.webp';
	}

	/**
	 * Converte um caminho relativo para o formato AVIF.
	 *
	 * @param string $relative_path Caminho relativo.
	 * @return string Caminho relativo AVIF.
	 */
	private function codir2me_get_avif_path( $relative_path ) {
		$path_info = pathinfo( $relative_path );
		return $path_info['dirname'] . '/' . $path_info['filename'] . '.avif';
	}

	/**
	 * Verifica se o arquivo é uma imagem com melhor tratamento de erros.
	 *
	 * @param string $file_path Caminho do arquivo.
	 * @return bool True se for uma imagem, False caso contrário.
	 */
	private function codir2me_is_image( $file_path ) {
		// Verificar se o caminho é uma string válida.
		if ( ! is_string( $file_path ) || empty( $file_path ) ) {
			// Registra o erro no log do seu plugin sem usar funções de depuração.
			codir2me_cdn_log(
				sprintf(
				/* translators: %s é a representação string do parâmetro inválido */
					__( 'R2 CDN - codir2me_is_image recebeu um parâmetro inválido: %s', 'codirun-codir2me-cdn' ),
					wp_json_encode( $file_path ) // Usando wp_json_encode para transformar em uma string.
				)
			);
			return false;
		}

		// Verificar se o arquivo existe.
		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();

		if ( ! $wp_filesystem->exists( $file_path ) ) {
			codir2me_cdn_log(
				sprintf(
				/* translators: %s é o caminho do arquivo que não existe */
					__( 'R2 CDN - Arquivo não existe: %s', 'codirun-codir2me-cdn' ),
					$file_path
				)
			);
			return false;
		}

		// Primeiro tente usar getimagesize que é mais confiável que exif_imagetype.
		$image_info = getimagesize( $file_path );
		if ( false !== $image_info ) {
			$mime_type   = $image_info['mime'];
			$valid_types = array(
				'image/jpeg',
				'image/png',
				'image/gif',
				'image/webp',
				'image/avif',
			);

			return in_array( $mime_type, $valid_types, true );
		}

		// Backup: usar extensão do arquivo se getimagesize falhar.
		$ext        = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
		$image_exts = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif' );

		return in_array( $ext, $image_exts, true );
	}

	/**
	 * Verifica se os requisitos para otimização estão disponíveis.
	 *
	 * @return array Status dos requisitos.
	 */
	private function codir2me_check_optimization_requirements() {
		$gd_available      = extension_loaded( 'gd' ) && function_exists( 'imagecreatefromjpeg' );
		$imagick_available = extension_loaded( 'imagick' ) && class_exists( 'Imagick' );
		$webp_available    = $gd_available && function_exists( 'imagewebp' );
		$avif_available    = $gd_available && function_exists( 'imageavif' );

		return array(
			'gd'      => $gd_available,
			'imagick' => $imagick_available,
			'webp'    => $webp_available,
			'avif'    => $avif_available,
			'any_met' => ( $gd_available || $imagick_available ),
			'all_met' => ( $gd_available && $imagick_available && $webp_available && $avif_available ),
		);
	}

	/**
	 * Prepara a otimização em lote.
	 * Método chamado antes de processar um lote de imagens.
	 */
	public function codir2me_prepare_batch_optimization() {
		// Verificar se as extensões necessárias estão disponíveis.
		if ( ! $this->codir2me_check_optimization_requirements()['any_met'] ) {
			// Registrar aviso no log.
			codir2me_cdn_log( __( 'R2 CDN - Aviso: Requisitos para otimização de imagens não estão disponíveis.', 'codirun-codir2me-cdn' ) );
		}
	}

	/**
	 * Aplica as configurações de formato de imagem definidas pelo usuário.
	 * Versão melhorada para verificar se a otimização está ativa e se as imagens existem.
	 *
	 * @param string $content Conteúdo HTML a ser modificado.
	 * @return string Conteúdo HTML com modificações.
	 */
	public function codir2me_replace_image_tags_with_picture( $content ) {
		$license_status = get_option( 'codir2me_license_status', 'inactive' );
		if ( 'active' !== $license_status ) {
			return $content;
		}

		// Se a otimização não estiver ativada, retornar o conteúdo original.
		if ( ! $this->enable_optimization ) {
			return $content;
		}

		// Se não estiver utilizando elemento picture, retornar o conteúdo original.
		if ( 'picture' !== $this->html_element ) {
			return $content;
		}

		// Verificar se há suporte para WebP ou AVIF.
		$requirements = $this->codir2me_check_optimization_requirements();
		if ( ! $requirements['webp'] && ! $requirements['avif'] ) {
			return $content;
		}

		// Obter configurações de formato.
		$format_order = get_option( 'codir2me_format_order', array( 'avif', 'webp', 'original' ) );
		$avif_enabled = get_option( 'codir2me_format_avif_enabled', $this->enable_avif_conversion );
		$webp_enabled = get_option( 'codir2me_format_webp_enabled', $this->enable_webp_conversion );

		// Verificar o status do CDN de imagens.
		$cdn_active = get_option( 'codir2me_is_images_cdn_active', false );
		if ( ! $cdn_active ) {
			return $content;
		}

		// Padrão para encontrar tags de imagem.
		$pattern = '/<img([^>]*)src=[\'"]([^\'"]+)[\'"]([^>]*)>/i';

		// Substituir tags de imagem pelo elemento picture apenas se for uma URL do R2 CDN.
		return preg_replace_callback(
			$pattern,
			function ( $matches ) use ( $requirements, $format_order, $avif_enabled, $webp_enabled ) {
				$before_src = $matches[1];
				$src        = $matches[2];
				$after_src  = $matches[3];

				// Verificar se é uma imagem do R2 CDN.
				$site_url         = site_url();
				$codir2me_cdn_url = get_option( 'codir2me_cdn_url', $site_url );

				// Se não for URL do CDN, retornar a tag original.
				if ( false === strpos( $src, $codir2me_cdn_url ) ) {
					return $matches[0];
				}

				// Verificar se é uma extensão de imagem suportada.
				$extension            = strtolower( pathinfo( $src, PATHINFO_EXTENSION ) );
				$supported_extensions = array( 'jpg', 'jpeg', 'png', 'gif' );

				if ( ! in_array( $extension, $supported_extensions, true ) ) {
					return $matches[0];
				}

				// Construir caminhos para WebP e AVIF.
				$webp_src = substr( $src, 0, strrpos( $src, '.' ) ) . '.webp';
				$avif_src = substr( $src, 0, strrpos( $src, '.' ) ) . '.avif';

				// Obter caminhos relativos para verificar se os arquivos existem.
				$relative_path      = str_replace( $codir2me_cdn_url . '/', '', $src );
				$webp_relative_path = str_replace( $codir2me_cdn_url . '/', '', $webp_src );
				$avif_relative_path = str_replace( $codir2me_cdn_url . '/', '', $avif_src );

				// Verificar se os arquivos existem no registro de uploads do R2.
				$uploaded_images = get_option( 'codir2me_uploaded_images', array() );
				$webp_exists     = in_array( $webp_relative_path, $uploaded_images, true );
				$avif_exists     = in_array( $avif_relative_path, $uploaded_images, true );

				// Se nenhuma versão está disponível, retornar a tag original.
				if ( ! $webp_exists && ! $avif_exists ) {
					return $matches[0];
				}

				// Construir o elemento picture.
				$picture = '<picture>';

				// Adicionar sources na ordem definida pelo usuário.
				foreach ( $format_order as $format ) {
					if ( 'avif' === $format && $avif_enabled && $avif_exists && $requirements['avif'] ) {
						$picture .= "\n  " . '<source srcset="' . esc_attr( $avif_src ) . '" type="image/avif">';
					} elseif ( 'webp' === $format && $webp_enabled && $webp_exists && $requirements['webp'] ) {
						$picture .= "\n  " . '<source srcset="' . esc_attr( $webp_src ) . '" type="image/webp">';
					}
				}

				// Adicionar a imagem original como fallback.
				$picture .= "\n  " . '<img' . $before_src . 'src="' . esc_attr( $src ) . '"' . $after_src . '>';
				$picture .= "\n" . '</picture>';

				return $picture;
			},
			$content
		);
	}

	/**
	 * Reprocessa uma imagem com as configurações atuais.
	 *
	 * @param string $file_path Caminho do arquivo de imagem.
	 * @param string $relative_path Caminho relativo no R2.
	 * @param bool   $force_webp Forçar geração de WebP.
	 * @param bool   $force_avif Forçar geração de AVIF.
	 * @param bool   $codir2me_save_locally Salvar versões WebP/AVIF localmente.
	 * @return array Dados do arquivo reprocessado.
	 */
	public function codir2me_reprocess_image( $file_path, $relative_path, $force_webp = false, $force_avif = false, $codir2me_save_locally = false ) {
		// Configuração temporária para forçar formatos específicos.
		$original_webp_setting         = $this->enable_webp_conversion;
		$original_avif_setting         = $this->enable_avif_conversion;
		$original_save_locally_setting = $this->save_converted_locally;

		// Aplicar configurações temporárias.
		if ( $force_webp ) {
			$this->enable_webp_conversion = true;
		}

		if ( $force_avif ) {
			$this->enable_avif_conversion = true;
		}

		// Configurar salvar localmente se especificado.
		$this->save_converted_locally = $codir2me_save_locally;

		// Chamar o método de otimização normal.
		$result = $this->codir2me_optimize_image( $file_path, $relative_path );

		// Restaurar configurações originais.
		$this->enable_webp_conversion = $original_webp_setting;
		$this->enable_avif_conversion = $original_avif_setting;
		$this->save_converted_locally = $original_save_locally_setting;

		// Limpar arquivos temporários.
		if ( isset( $temp_webp_file ) && file_exists( $temp_webp_file ) ) {
			wp_delete_file( $temp_webp_file );
		}
		if ( isset( $temp_avif_file ) && file_exists( $temp_avif_file ) ) {
			wp_delete_file( $temp_avif_file );
		}

		return $result;
	}

	/**
	 * Função de callback para substituir uma tag de imagem pelo elemento picture.
	 *
	 * @param array $matches Matches da expressão regular.
	 * @return string Tag HTML substituída.
	 */
	private function codir2me_replace_image_with_picture( $matches ) {
		$before_src = $matches[1];
		$src        = $matches[2];
		$after_src  = $matches[3];

		// Verificar se é uma imagem do site.
		$site_url         = site_url();
		$codir2me_cdn_url = get_option( 'codir2me_cdn_url', $site_url );

		// Verificar se é uma URL do nosso CDN.
		$is_codir2me_cdn_url = ( 0 === strpos( $src, $codir2me_cdn_url ) );

		// Se não for URL do site ou do CDN, retornar a tag original.
		if ( ! $is_codir2me_cdn_url && 0 !== strpos( $src, $site_url ) ) {
			return $matches[0];
		}

		// Verificar se é uma extensão de imagem suportada.
		$extension            = strtolower( pathinfo( $src, PATHINFO_EXTENSION ) );
		$supported_extensions = array( 'jpg', 'jpeg', 'png', 'gif' );

		if ( ! in_array( $extension, $supported_extensions, true ) ) {
			return $matches[0];
		}

		// Extrair atributos da tag img.
		$attributes = $before_src . $after_src;
		$alt_match  = array();
		preg_match( '/alt=[\'"]([^\'"]*)[\'"]/', $attributes, $alt_match );
		$alt = ! empty( $alt_match ) ? $alt_match[1] : '';

		// Construir caminhos para WebP e AVIF.
		$webp_src = substr( $src, 0, strrpos( $src, '.' ) ) . '.webp';
		$avif_src = substr( $src, 0, strrpos( $src, '.' ) ) . '.avif';

		// Obter caminhos relativos para verificar se os arquivos existem.
		$relative_path      = str_replace( $codir2me_cdn_url . '/', '', $src );
		$webp_relative_path = str_replace( $codir2me_cdn_url . '/', '', $webp_src );
		$avif_relative_path = str_replace( $codir2me_cdn_url . '/', '', $avif_src );

		// Verificar se os arquivos existem no registro de uploads do R2.
		$uploaded_images = get_option( 'codir2me_uploaded_images', array() );
		$webp_exists     = in_array( $webp_relative_path, $uploaded_images, true );
		$avif_exists     = in_array( $avif_relative_path, $uploaded_images, true );

		// Construir o elemento picture.
		$picture = '<picture>';

		// Adicionar source para AVIF se estiver habilitado e o arquivo existir.
		if ( $this->enable_avif_conversion && $avif_exists ) {
			$picture .= "\n  " . '<source srcset="' . esc_attr( $avif_src ) . '" type="image/avif">';
		}

		// Adicionar source para WebP se estiver habilitado e o arquivo existir.
		if ( $this->enable_webp_conversion && $webp_exists ) {
			$picture .= "\n  " . '<source srcset="' . esc_attr( $webp_src ) . '" type="image/webp">';
		}

		// Adicionar a imagem original como fallback.
		$picture .= "\n  " . '<img' . $before_src . 'src="' . esc_attr( $src ) . '"' . $after_src . '>';
		$picture .= "\n" . '</picture>';

		return $picture;
	}

	/**
	 * Obtém estatísticas de otimização.
	 *
	 * @return array Estatísticas de otimização.
	 */
	public function codir2me_get_optimization_stats() {
		$stats = get_option(
			'codir2me_optimization_stats',
			array(
				'total_processed'   => 0,
				'total_optimized'   => 0,
				'total_bytes_saved' => 0,
				'webp_converted'    => 0,
				'webp_bytes_saved'  => 0,
				'avif_converted'    => 0,
				'avif_bytes_saved'  => 0,
				'last_processed'    => 0,
			)
		);

		return $stats;
	}

	/**
	 * Atualiza as estatísticas de otimização.
	 *
	 * @param array $optimization_result Resultado da otimização.
	 */
	public function codir2me_update_stats( $optimization_result ) {
		$stats = $this->codir2me_get_optimization_stats();

		// Incrementar contador de processados.
		++$stats['total_processed'];

		// Se foi otimizado com sucesso.
		if ( isset( $optimization_result['optimized'] ) && $optimization_result['optimized'] ) {
			++$stats['total_optimized'];
			$stats['total_bytes_saved'] += $optimization_result['saved_bytes'];

			// Se foi gerado WebP.
			if ( isset( $optimization_result['webp_file'] ) ) {
				++$stats['webp_converted'];
				$stats['webp_bytes_saved'] += ( $optimization_result['original_size'] - $optimization_result['webp_size'] );
			}

			// Se foi gerado AVIF.
			if ( isset( $optimization_result['avif_file'] ) ) {
				++$stats['avif_converted'];
				$stats['avif_bytes_saved'] += ( $optimization_result['original_size'] - $optimization_result['avif_size'] );
			}
		}

		$stats['last_processed'] = time();

		update_option( 'codir2me_optimization_stats', $stats );
	}

	/**
	 * Redefine as estatísticas de otimização.
	 */
	public function codir2me_reset_stats() {
		$default_stats = array(
			'total_processed'   => 0,
			'total_optimized'   => 0,
			'total_bytes_saved' => 0,
			'webp_converted'    => 0,
			'webp_bytes_saved'  => 0,
			'avif_converted'    => 0,
			'avif_bytes_saved'  => 0,
			'last_processed'    => 0,
		);

		update_option( 'codir2me_optimization_stats', $default_stats );
	}

	/**
	 * Limpa arquivos temporários gerados durante a otimização.
	 *
	 * @param array $optimization_result Resultado da otimização.
	 */
	private function codir2me_cleanup_temp_files( $optimization_result ) {
		try {
			global $wp_filesystem;
			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			WP_Filesystem();

			// Registrar no log para depuração.
			if ( function_exists( 'codir2me_cdn_log' ) ) {
				codir2me_cdn_log( __( 'Limpando arquivos temporários após upload', 'codirun-codir2me-cdn' ), 'info' );
			}

			// Não exclua o arquivo original.
			$original_path = isset( $optimization_result['original_path'] ) ? $optimization_result['original_path'] : null;

			// Se o arquivo otimizado não é o original e está em uma pasta temporária.
			if ( isset( $optimization_result['file_path'] ) &&
				$optimization_result['file_path'] !== $original_path &&
				( false !== strpos( $optimization_result['file_path'], 'codir2me_temp' ) ||
				false !== strpos( $optimization_result['file_path'], 'tmp' ) ) ) {

				if ( $wp_filesystem->exists( $optimization_result['file_path'] ) ) {
					if ( function_exists( 'codir2me_cdn_log' ) ) {
						codir2me_cdn_log(
							sprintf(
							/* translators: %s é o caminho do arquivo otimizado temporário */
								__( 'Removendo arquivo otimizado temporário: %s', 'codirun-codir2me-cdn' ),
								$optimization_result['file_path']
							),
							'info'
						);
					}
					$wp_filesystem->delete( $optimization_result['file_path'] );
				}
			}

			// Limpar arquivo WebP temporário.
			if ( isset( $optimization_result['webp_file'] ) && $wp_filesystem->exists( $optimization_result['webp_file'] ) ) {
				// Verificar se está em diretório temporário.
				if ( false !== strpos( $optimization_result['webp_file'], 'codir2me_temp' ) ||
					false !== strpos( $optimization_result['webp_file'], 'tmp' ) ) {

					if ( function_exists( 'codir2me_cdn_log' ) ) {
						codir2me_cdn_log(
							sprintf(
							/* translators: %s é o caminho do arquivo WebP temporário */
								__( 'Removendo arquivo WebP temporário: %s', 'codirun-codir2me-cdn' ),
								$optimization_result['webp_file']
							),
							'info'
						);
					}
					$wp_filesystem->delete( $optimization_result['webp_file'] );
				}
			}

			// Limpar arquivo AVIF temporário.
			if ( isset( $optimization_result['avif_file'] ) && $wp_filesystem->exists( $optimization_result['avif_file'] ) ) {
				// Verificar se está em diretório temporário.
				if ( false !== strpos( $optimization_result['avif_file'], 'codir2me_temp' ) ||
					false !== strpos( $optimization_result['avif_file'], 'tmp' ) ) {

					if ( function_exists( 'codir2me_cdn_log' ) ) {
						codir2me_cdn_log(
							sprintf(
							/* translators: %s é o caminho do arquivo AVIF temporário */
								__( 'Removendo arquivo AVIF temporário: %s', 'codirun-codir2me-cdn' ),
								$optimization_result['avif_file']
							),
							'info'
						);
					}
					$wp_filesystem->delete( $optimization_result['avif_file'] );
				}
			}
		} catch ( Exception $e ) {
			if ( function_exists( 'codir2me_cdn_log' ) ) {
				codir2me_cdn_log(
					sprintf(
					/* translators: %s é a mensagem de erro */
						__( 'Erro ao limpar arquivos temporários: %s', 'codirun-codir2me-cdn' ),
						$e->getMessage()
					),
					'error'
				);
			}
		}
	}

	/**
	 * Limpa todos os arquivos temporários do diretório temp.
	 * Função adicional para limpeza geral.
	 */
	public function codir2me_cleanup_all_temp_files() {
		try {
			// Inicializar WP_Filesystem.
			global $wp_filesystem;
			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			WP_Filesystem();

			$upload_dir = wp_upload_dir();
			$temp_dir   = $upload_dir['basedir'] . '/codir2me_temp';

			if ( $wp_filesystem->exists( $temp_dir ) ) {
				// Listar todos os arquivos no diretório temporário.
				$temp_files = $wp_filesystem->dirlist( $temp_dir );

				if ( is_array( $temp_files ) ) {
					foreach ( $temp_files as $file_name => $file_info ) {
						if ( 'f' === $file_info['type'] ) { // Apenas arquivos, não diretórios.
							$file_path = $temp_dir . '/' . $file_name;

							// Verificar se o arquivo é antigo (mais de 1 hora).
							$file_time    = $file_info['lastmodunix'];
							$current_time = time();

							if ( 3600 < ( $current_time - $file_time ) ) { // 1 hora = 3600 segundos.
								$wp_filesystem->delete( $file_path );

								if ( function_exists( 'codir2me_cdn_log' ) ) {
									codir2me_cdn_log(
										sprintf(
										/* translators: %s é o nome do arquivo temporário removido */
											__( 'Arquivo temporário antigo removido: %s', 'codirun-codir2me-cdn' ),
											$file_name
										),
										'info'
									);
								}
							}
						}
					}
				}

				// Se o diretório estiver vazio, removê-lo.
				$remaining_files = $wp_filesystem->dirlist( $temp_dir );
				if ( empty( $remaining_files ) ) {
					$wp_filesystem->rmdir( $temp_dir );

					if ( function_exists( 'codir2me_cdn_log' ) ) {
						codir2me_cdn_log( __( 'Diretório temporário vazio removido', 'codirun-codir2me-cdn' ), 'info' );
					}
				}
			}
		} catch ( Exception $e ) {
			if ( function_exists( 'codir2me_cdn_log' ) ) {
				codir2me_cdn_log(
					sprintf(
					/* translators: %s é a mensagem de erro */
						__( 'Erro ao limpar arquivos temporários gerais: %s', 'codirun-codir2me-cdn' ),
						$e->getMessage()
					),
					'error'
				);
			}
		}
	}

	/**
	 * Hook para limpeza automática de arquivos temporários.
	 * Deve ser chamado periodicamente.
	 */
	public function codir2me_schedule_temp_cleanup() {
		// Agendar limpeza diária de arquivos temporários.
		if ( ! wp_next_scheduled( 'codir2me_codir2me_cleanup_temp_files' ) ) {
			wp_schedule_event( time(), 'daily', 'codir2me_codir2me_cleanup_temp_files' );
		}

		// Adicionar ação para o evento agendado.
		add_action( 'codir2me_codir2me_cleanup_temp_files', array( $this, 'codir2me_cleanup_all_temp_files' ) );
	}

	/**
	 * Remove o agendamento de limpeza quando o plugin for desativado.
	 */
	public function codir2me_unschedule_temp_cleanup() {
		$timestamp = wp_next_scheduled( 'codir2me_codir2me_cleanup_temp_files' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'codir2me_codir2me_cleanup_temp_files' );
		}
	}

	/**
	 * Função para criar diretório temporário seguro.
	 *
	 * @return string Caminho do diretório temporário.
	 */
	private function codir2me_ensure_temp_directory() {
		$upload_dir = wp_upload_dir();
		$temp_dir   = $upload_dir['basedir'] . '/codir2me_temp';

		// Usar WP_Filesystem para criar diretório.
		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();

		if ( ! $wp_filesystem->exists( $temp_dir ) ) {
			$wp_filesystem->mkdir( $temp_dir, 0755, true );

			// Criar arquivo .htaccess para proteção.
			$htaccess_content = "Order Allow,Deny\nDeny from all\n";
			$wp_filesystem->put_contents( $temp_dir . '/.htaccess', $htaccess_content, 0644 );

			// Criar index.php para proteção.
			$wp_filesystem->put_contents( $temp_dir . '/index.php', '<?php // Silence is golden', 0644 );
		}

		return $temp_dir;
	}
}
