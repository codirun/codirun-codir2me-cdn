<?php
/**
 * Classe principal do plugin R2 Static & Media CDN
 *
 * @package Codirun_R2_Media_Static_CDN
 */

// Evitar acesso direto ao arquivo.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classe principal do plugin R2 Static & Media CDN
 *
 * Gerencia todas as funcionalidades principais do plugin incluindo
 * configurações, manipuladores de assets e imagens, upload e otimização.
 *
 * @package Codirun_R2_Media_Static_CDN
 * @since   1.0.0
 */
class CODIR2ME_Static_Media_CDN {
	/**
	 * Chave de acesso do Cloudflare R2.
	 *
	 * @var string
	 */
	private $codir2me_access_key;

	/**
	 * Chave secreta do Cloudflare R2.
	 *
	 * @var string
	 */
	private $codir2me_secret_key;

	/**
	 * Nome do bucket do Cloudflare R2.
	 *
	 * @var string
	 */
	private $codir2me_bucket;

	/**
	 * Endpoint do Cloudflare R2.
	 *
	 * @var string
	 */
	private $codir2me_endpoint;

	/**
	 * URL do CDN configurada.
	 *
	 * @var string
	 */
	private $codir2me_cdn_url;

	/**
	 * Indica se o plugin está pronto para uso.
	 *
	 * @var bool
	 */
	private $codir2me_is_ready = false;

	/**
	 * Indica se o CDN de imagens está ativo.
	 *
	 * @var bool
	 */
	private $codir2me_is_images_active = false;

	/**
	 * Indica se o upload automático de miniaturas está ativo.
	 *
	 * @var bool
	 */
	private $auto_upload_thumbnails = false;

	/**
	 * Tamanho do lote para processamento em lotes.
	 *
	 * @var int
	 */
	private $batch_size = 50;

	/**
	 * Indica se a otimização de imagens está habilitada.
	 *
	 * @var bool
	 */
	private $enable_optimization = false;

	/**
	 * Instância do administrador do plugin.
	 *
	 * @var CODIR2ME_Admin
	 */
	private $admin;

	/**
	 * Instância do manipulador de assets.
	 *
	 * @var CODIR2ME_Assets_Handler
	 */
	private $assets_handler;

	/**
	 * Instância do manipulador de imagens.
	 *
	 * @var CODIR2ME_Images_Handler
	 */
	private $images_handler;

	/**
	 * Instância do uploader.
	 *
	 * @var CODIR2ME_Uploader
	 */
	private $uploader;

	/**
	 * Instância do otimizador de imagens.
	 *
	 * @var CODIR2ME_Image_Optimizer
	 */
	private $image_optimizer;

	/**
	 * Verifica e corrige problemas de persistência de opções
	 */
	private function codir2me_fix_options_persistence() {
		// Verificar se as opções de otimização existem.
		$optimization_options = get_option( 'codir2me_image_optimization_options', null );

		// Se as opções não existirem ou forem corrompidas, criar com valores padrão.
		if ( null === $optimization_options || ! is_array( $optimization_options ) ) {
			$default_options = array(
				'enable_optimization'    => false,
				'optimization_level'     => 'balanced',
				'jpeg_quality'           => 85,
				'png_compression'        => 7,
				'webp_quality'           => 80,
				'avif_quality'           => 75,
				'enable_webp_conversion' => false,
				'enable_avif_conversion' => false,
				'keep_original'          => true,
				'html_element'           => 'picture',
			);

			update_option( 'codir2me_image_optimization_options', $default_options );
			$optimization_options = $default_options;
		} else {
			// Verificar e reparar campos específicos que possam estar faltando.
			$default_fields = array(
				'enable_optimization'    => false,
				'optimization_level'     => 'balanced',
				'jpeg_quality'           => 85,
				'png_compression'        => 7,
				'webp_quality'           => 80,
				'avif_quality'           => 75,
				'enable_webp_conversion' => false,
				'enable_avif_conversion' => false,
				'keep_original'          => true,
				'html_element'           => 'picture',
			);

			$updated = false;
			foreach ( $default_fields as $field => $default_value ) {
				if ( ! isset( $optimization_options[ $field ] ) ) {
					$optimization_options[ $field ] = $default_value;
					$updated                        = true;
				}
			}

			if ( $updated ) {
				update_option( 'codir2me_image_optimization_options', $optimization_options );
			}
		}

		// Garantir que as opções codir2me_format_* estejam em sincronia.
		$webp_enabled = isset( $optimization_options['enable_webp_conversion'] )
			? (bool) $optimization_options['enable_webp_conversion']
			: false;

		$avif_enabled = isset( $optimization_options['enable_avif_conversion'] )
			? (bool) $optimization_options['enable_avif_conversion']
			: false;

		// Verificar se as opções de formato existem.
		if ( false === get_option( 'codir2me_format_order' ) ) {
			update_option( 'codir2me_format_order', array( 'avif', 'webp', 'original' ) );
		}

		// Sempre atualizar os estados dos formatos para garantir sincronia.
		update_option( 'codir2me_format_webp_enabled', $webp_enabled );
		update_option( 'codir2me_format_avif_enabled', $avif_enabled );
	}

	/**
	 * Inicializa o plugin
	 */
	public function codir2me_init() {
		// Carregar configurações.
		$this->codir2me_load_settings();

		// Corrigir problemas de persistência de opções.
		$this->codir2me_fix_options_persistence();

		// Verificar se as configurações básicas existem.
		if ( $this->codir2me_has_basic_configs() ) {
			// Inicializar componentes.
			$this->codir2me_init_components();

			// Verificar se o plugin está pronto.
			$this->codir2me_check_if_ready();

			// Adicionar filtros e ações do plugin.
			$this->codir2me_add_hooks();
		} else {
			// Inicializar apenas o admin para mostrar a página de configurações.
			$this->admin = new CODIR2ME_Admin( $this );

			// Adicionar uma notificação sobre configurações incompletas.
			add_action( 'admin_notices', array( $this, 'codir2me_show_config_notice' ) );
		}

		// Inicializar o otimizador de imagens.
		$this->codir2me_init_image_optimizer();
	}

	/**
	 * Verifica se as configurações básicas foram definidas
	 *
	 * @return bool True se as configurações básicas existem, False caso contrário.
	 */
	private function codir2me_has_basic_configs() {
		return ! empty( $this->codir2me_access_key ) &&
				! empty( $this->codir2me_secret_key ) &&
				! empty( $this->codir2me_bucket ) &&
				! empty( $this->codir2me_endpoint );
	}

	/**
	 * Exibe uma notificação quando as configurações estão incompletas
	 */
	public function codir2me_show_config_notice() {
		// Verificar se estamos na página do plugin.
		$screen = get_current_screen();
		if ( ! isset( $screen->id ) || false === strpos( $screen->id, 'codirun-codir2me-cdn' ) ) {
			return;
		}

		if ( ! $this->codir2me_has_basic_configs() ) {
			?>
			<div class="notice notice-warning is-dismissible">
				<p><?php esc_html_e( 'Por favor, configure suas credenciais do Cloudflare R2 nas configurações do plugin R2 Static & Media CDN para começar a usar o serviço.', 'codirun-codir2me-cdn' ); ?></p>
			</div>
			<?php
		}
	}

	/**
	 * Carrega as configurações do plugin
	 */
	private function codir2me_load_settings() {
		$this->codir2me_access_key    = get_option( 'codir2me_access_key' );
		$this->codir2me_secret_key    = get_option( 'codir2me_secret_key' );
		$this->codir2me_bucket        = get_option( 'codir2me_bucket' );
		$this->codir2me_endpoint      = get_option( 'codir2me_endpoint' );
		$this->codir2me_cdn_url       = get_option( 'codir2me_cdn_url' );
		$this->batch_size             = get_option( 'codir2me_batch_size', 50 );
		$this->auto_upload_thumbnails = get_option( 'codir2me_auto_upload_thumbnails', false );

		// Verificar se a otimização de imagens está ativada.
		$optimization_options      = get_option( 'codir2me_image_optimization_options', array() );
		$this->enable_optimization = isset( $optimization_options['enable_optimization'] ) ? (bool) $optimization_options['enable_optimization'] : false;
	}

	/**
	 * Inicializa os componentes do plugin
	 */
	private function codir2me_init_components() {
		try {
			// Se não tiver configurações básicas, usar valores padrão.
			$codir2me_cdn_url = ! empty( $this->codir2me_cdn_url ) ? $this->codir2me_cdn_url : home_url();

			// Inicializar manipuladores com valores padrão.
			$this->assets_handler = new CODIR2ME_Assets_Handler( $codir2me_cdn_url );
			$this->images_handler = new CODIR2ME_Images_Handler( $codir2me_cdn_url );

			// Configurar o upload automático de miniaturas.
			if ( $this->images_handler ) {
				$this->images_handler->codir2me_set_auto_upload_thumbnails( $this->auto_upload_thumbnails );
			}

			// Inicializar o uploader apenas se as configurações básicas existirem.
			if ( $this->codir2me_has_basic_configs() ) {
				$this->uploader = new CODIR2ME_Uploader(
					$this->codir2me_access_key,
					$this->codir2me_secret_key,
					$this->codir2me_bucket,
					$this->codir2me_endpoint
				);
			}

			// Inicializar o admin após todos os outros componentes.
			$this->admin = new CODIR2ME_Admin( $this );

		} catch ( Exception $e ) {
			// Registrar erro no log.
			codir2me_cdn_log(
				sprintf(
				/* translators: %s é a mensagem de erro original */
					esc_html__( 'R2 Static & Media CDN - Erro na inicialização: %s', 'codirun-codir2me-cdn' ),
					$e->getMessage()
				)
			);

			// Inicializar com manipuladores vazios.
			$this->assets_handler = new CODIR2ME_Assets_Handler( home_url() );
			$this->images_handler = new CODIR2ME_Images_Handler( home_url() );
			$this->admin          = new CODIR2ME_Admin( $this );
		}
	}

	/**
	 * Verifica se o plugin está pronto para uso
	 */
	private function codir2me_check_if_ready() {
		// Verificar nonce para operações administrativas.
		if ( is_admin() && isset( $_REQUEST['_wpnonce'] ) ) {
			if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ), 'codir2me_admin_action' ) ) {
				return;
			}
		}

		$this->codir2me_is_ready = get_option( 'codir2me_is_cdn_active' ) &&
						$this->codir2me_has_basic_configs() &&
						$this->codir2me_cdn_url;

		$this->codir2me_is_images_active = get_option( 'codir2me_is_images_cdn_active' ) &&
								$this->codir2me_has_basic_configs() &&
								$this->codir2me_cdn_url;

		if ( $this->codir2me_is_ready && $this->assets_handler ) {
			$this->assets_handler->codir2me_set_active( true );
		}

		if ( $this->codir2me_is_images_active && $this->images_handler ) {
			$this->images_handler->codir2me_set_active( true );
		}
	}

	/**
	 * Adiciona hooks para funcionalidades do plugin
	 */
	private function codir2me_add_hooks() {
		// Verificar se o admin foi inicializado.
		if ( $this->admin ) {
			// Link de configurações na página de plugins.
			add_filter(
				'plugin_action_links_' . plugin_basename( CODIR2ME_CDN_PLUGIN_DIR . 'codirun-codir2me-cdn.php' ),
				array( $this->admin, 'codir2me_add_settings_link' )
			);
		}

		// Iniciar modificação de URLs.
		add_action( 'wp_loaded', array( $this, 'codir2me_maybe_modify_asset_urls' ) );

		// Adicionar hook para verificação de alterações na configuração de upload automático.
		add_action( 'update_option_codir2me_auto_upload_thumbnails', array( $this, 'codir2me_handle_auto_upload_thumbnails_change' ), 10, 2 );

		// Carregar Dashicons no admin para a ferramenta de verificação de ambiente.
		add_action( 'admin_enqueue_scripts', array( $this, 'codir2me_enqueue_dashicons' ) );
	}

	/**
	 * Inicializa o otimizador de imagens
	 */
	private function codir2me_init_image_optimizer() {
		// Verificar se a classe existe.
		if ( ! class_exists( 'codir2me_Image_Optimizer' ) ) {
			require_once CODIR2ME_CDN_INCLUDES_DIR . 'class-codirun-codir2me-cdn.php';
		}

		// Inicializar o otimizador.
		$this->image_optimizer = new CODIR2ME_Image_Optimizer();

		// Registrar configurações.
		add_action( 'admin_init', array( $this->image_optimizer, 'codir2me_register_settings' ) );

		// Adicionar hooks para otimização.
		if ( $this->enable_optimization ) {
			// Aplicar otimização antes do upload para o R2.
			add_filter( 'codir2me_pre_upload_image', array( $this, 'codir2me_optimize_image_before_upload' ), 10, 2 );
		}
	}

	/**
	 * Carrega os Dashicons no admin para a ferramenta de verificação de ambiente
	 */
	public function codir2me_enqueue_dashicons() {
		wp_enqueue_style( 'dashicons' );
	}

	/**
	 * Manipula alterações na configuração de upload automático de miniaturas
	 *
	 * @param mixed $old_value Valor antigo.
	 * @param mixed $new_value Novo valor.
	 */
	public function codir2me_handle_auto_upload_thumbnails_change( $old_value, $new_value ) {
		// Verificar nonce se houver requisição.
		if ( is_admin() && isset( $_REQUEST['option_page'] ) &&
			'codir2me_images_settings' === $_REQUEST['option_page'] &&
			( ! isset( $_REQUEST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ), 'codir2me_images_settings-options' ) ) ) {
			return;
		}

		if ( $this->images_handler ) {
			$this->images_handler->codir2me_set_auto_upload_thumbnails( $new_value );
		}

		// Registrar a alteração.
		$this->auto_upload_thumbnails = $new_value;

		// Redirecionar para mostrar uma notificação (opcional).
		if ( is_admin() && isset( $_REQUEST['option_page'] ) && 'codir2me_images_settings' === $_REQUEST['option_page'] ) {
			add_filter(
				'wp_redirect',
				function ( $location ) {
					return add_query_arg( 'auto_upload_changed', '1', $location );
				}
			);
		}
	}

	/**
	 * Modifica as URLs dos assets para apontar para o CDN
	 */
	public function codir2me_maybe_modify_asset_urls() {
		if ( $this->codir2me_is_ready && $this->assets_handler ) {
			$this->assets_handler->codir2me_init_url_filters();
		}

		if ( $this->codir2me_is_images_active && $this->images_handler ) {
			$this->images_handler->codir2me_init_url_filters();
		}
	}

	/**
	 * Simplificação drástica do método codir2me_optimize_image_before_upload
	 * Atua apenas como proxy para o otimizador, evitando recursão
	 *
	 * @param string $file_path     Caminho completo do arquivo de imagem.
	 * @param string $relative_path Caminho relativo do arquivo.
	 * @return array Resultado da otimização com informações sobre o processo.
	 */
	public function codir2me_optimize_image_before_upload( $file_path, $relative_path ) {
		// Verificar se é um resultado de otimização anterior para evitar recursão.
		if ( is_array( $file_path ) && isset( $file_path['optimized'] ) ) {
			codir2me_cdn_log( esc_html__( 'R2 CDN - Evitando recursão: resultado de otimização detectado', 'codirun-codir2me-cdn' ) );
			return $file_path;
		}

		// Verificar se o input é válido.
		if ( ! is_string( $file_path ) || empty( $file_path ) || ! file_exists( $file_path ) ) {
			// Substituir print_r por uma versão segura para logs.
			$debug_info = is_string( $file_path ) ? $file_path : wp_json_encode( $file_path );
			codir2me_cdn_log(
				sprintf(
				/* translators: %s é o valor do parâmetro de entrada */
					esc_html__( 'R2 CDN - Parâmetro de otimização inválido: %s', 'codirun-codir2me-cdn' ),
					$debug_info
				)
			);
			return array(
				'file_path'     => is_string( $file_path ) ? $file_path : '',
				'relative_path' => $relative_path,
				'optimized'     => false,
				'reason'        => esc_html__( 'Parâmetro inválido', 'codirun-codir2me-cdn' ),
			);
		}

		// Verificar se o otimizador está disponível.
		if ( ! $this->image_optimizer ) {
			codir2me_cdn_log( esc_html__( 'R2 CDN - Otimizador não disponível', 'codirun-codir2me-cdn' ) );
			return array(
				'file_path'     => $file_path,
				'relative_path' => $relative_path,
				'optimized'     => false,
				'reason'        => esc_html__( 'Otimizador não disponível', 'codirun-codir2me-cdn' ),
			);
		}

		// Verificar se a otimização está ativada.
		if ( ! $this->enable_optimization ) {
			codir2me_cdn_log( esc_html__( 'R2 CDN - Otimização desativada globalmente', 'codirun-codir2me-cdn' ) );
			return array(
				'file_path'     => $file_path,
				'relative_path' => $relative_path,
				'optimized'     => false,
				'reason'        => esc_html__( 'Otimização desativada', 'codirun-codir2me-cdn' ),
			);
		}

		// IMPORTANTE: Remover o filtro temporariamente para evitar recursão infinita.
		remove_filter( 'codir2me_pre_upload_image', array( $this, 'codir2me_optimize_image_before_upload' ), 10 );

		try {
			// Chamar diretamente o otimizador.
			$result = $this->image_optimizer->codir2me_optimize_image( $file_path, $relative_path );

			// Atualizar estatísticas.
			if ( is_array( $result ) && method_exists( $this->image_optimizer, 'codir2me_update_stats' ) ) {
				$this->image_optimizer->codir2me_update_stats( $result );
			}

			// Retornar o resultado da otimização.
			return $result;
		} catch ( Exception $e ) {
			codir2me_cdn_log(
				sprintf(
				/* translators: %s é a mensagem de erro original */
					esc_html__( 'R2 CDN - Erro durante otimização: %s', 'codirun-codir2me-cdn' ),
					$e->getMessage()
				)
			);
			return array(
				'file_path'     => $file_path,
				'relative_path' => $relative_path,
				'optimized'     => false,
				'error'         => $e->getMessage(),
			);
		} finally {
			// Re-adicionar o filtro após a execução.
			add_filter( 'codir2me_pre_upload_image', array( $this, 'codir2me_optimize_image_before_upload' ), 10, 2 );
		}
	}

	/**
	 * Verifica se a otimização de imagens está ativada
	 *
	 * @return bool True se a otimização estiver ativada, False caso contrário.
	 */
	public function codir2me_is_optimization_enabled() {
		return $this->enable_optimization;
	}

	/**
	 * Obtém a instância do otimizador de imagens
	 *
	 * @return CODIR2ME_Image_Optimizer|null Instância do otimizador ou null.
	 */
	public function codir2me_get_image_optimizer() {
		return $this->image_optimizer;
	}

	/**
	 * Obtém a instância do uploader
	 *
	 * @return CODIR2ME_Uploader|null Instância do uploader ou null se não configurado.
	 */
	public function codir2me_get_uploader() {
		if ( ! $this->uploader && $this->codir2me_has_basic_configs() ) {
			// Inicializar o uploader sob demanda, caso ainda não tenha sido inicializado.
			try {
				$this->uploader = new CODIR2ME_Uploader(
					$this->codir2me_access_key,
					$this->codir2me_secret_key,
					$this->codir2me_bucket,
					$this->codir2me_endpoint
				);
			} catch ( Exception $e ) {
				codir2me_cdn_log(
					sprintf(
					/* translators: %s é a mensagem de erro original */
						esc_html__( 'R2 Static & Media CDN - Erro ao criar uploader: %s', 'codirun-codir2me-cdn' ),
						$e->getMessage()
					)
				);
				return null;
			}
		}

		return $this->uploader;
	}

	/**
	 * Obtém a instância do manipulador de assets
	 *
	 * @return CODIR2ME_Assets_Handler Instância do manipulador de assets.
	 */
	public function codir2me_get_assets_handler() {
		if ( ! $this->assets_handler ) {
			$cdn_url              = ! empty( $this->codir2me_cdn_url ) ? $this->codir2me_cdn_url : home_url();
			$this->assets_handler = new CODIR2ME_Assets_Handler( $cdn_url );
		}
		return $this->assets_handler;
	}

	/**
	 * Obtém a instância do manipulador de imagens
	 *
	 * @return CODIR2ME_Images_Handler Instância do manipulador de imagens.
	 */
	public function codir2me_get_images_handler() {
		if ( ! $this->images_handler ) {
			$cdn_url              = ! empty( $this->codir2me_cdn_url ) ? $this->codir2me_cdn_url : home_url();
			$this->images_handler = new CODIR2ME_Images_Handler( $cdn_url );
		}
		return $this->images_handler;
	}

	/**
	 * Verifica se o plugin está pronto para uso
	 *
	 * @return bool True se o plugin estiver pronto, False caso contrário.
	 */
	public function codir2me_is_ready() {
		return $this->codir2me_is_ready;
	}

	/**
	 * Verifica se o CDN de imagens está ativo
	 *
	 * @return bool True se o CDN de imagens estiver ativo, False caso contrário.
	 */
	public function codir2me_is_images_active() {
		return $this->codir2me_is_images_active;
	}

	/**
	 * Verifica se o upload automático de miniaturas está habilitado
	 *
	 * @return bool True se o upload automático estiver habilitado, False caso contrário.
	 */
	public function codir2me_is_auto_upload_thumbnails_enabled() {
		return $this->auto_upload_thumbnails;
	}

	/**
	 * Obtém o tamanho do lote para processamento
	 *
	 * @return int Tamanho do lote configurado.
	 */
	public function codir2me_get_batch_size() {
		return $this->batch_size;
	}

	/**
	 * Obtém a URL do CDN configurada
	 *
	 * @return string URL do CDN ou string vazia se não configurada.
	 */
	public function codir2me_get_cdn_url() {
		return $this->codir2me_cdn_url;
	}
}
