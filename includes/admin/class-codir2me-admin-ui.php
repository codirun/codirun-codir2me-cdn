<?php
/**
 * Classe principal que gerencia a interface do usuário para o admin do plugin
 *
 * @package Codirun_R2_Media_Static_CDN
 */

// Evitar acesso direto ao arquivo.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Carregar classes auxiliares.
require_once CODIR2ME_CDN_INCLUDES_DIR . 'admin/class-codir2me-admin-ui-general.php';
require_once CODIR2ME_CDN_INCLUDES_DIR . 'admin/class-codir2me-admin-ui-static.php';
require_once CODIR2ME_CDN_INCLUDES_DIR . 'admin/class-codir2me-admin-ui-images.php';
require_once CODIR2ME_CDN_INCLUDES_DIR . 'admin/class-codir2me-admin-ui-thumbnails.php';
require_once CODIR2ME_CDN_INCLUDES_DIR . 'admin/class-codir2me-admin-ui-utils.php';
require_once CODIR2ME_CDN_INCLUDES_DIR . 'admin/class-codir2me-admin-ui-maintenance.php';
require_once CODIR2ME_CDN_INCLUDES_DIR . 'admin/class-codir2me-admin-ui-delete.php';
require_once CODIR2ME_CDN_INCLUDES_DIR . 'admin/class-codir2me-admin-ui-scanner.php';
require_once CODIR2ME_CDN_INCLUDES_DIR . 'admin/class-codir2me-admin-ui-optimizer.php';

/**
 * Classe principal que gerencia a interface do usuário para o admin do plugin.
 */
class CODIR2ME_Admin_UI {
	/**
	 * Instância da classe de administração.
	 *
	 * @var codir2me_Admin
	 */
	private $admin;

	/**
	 * Instância da aba geral.
	 *
	 * @var CODIR2ME_Admin_UI_General
	 */
	private $general_tab;

	/**
	 * Instância da aba de arquivos estáticos.
	 *
	 * @var CODIR2ME_Admin_UI_Static
	 */
	private $static_tab;

	/**
	 * Instância da aba de imagens.
	 *
	 * @var CODIR2ME_Admin_UI_Images
	 */
	private $images_tab;

	/**
	 * Instância da classe de miniaturas.
	 *
	 * @var CODIR2ME_Admin_UI_Thumbnails
	 */
	private $thumbnails;

	/**
	 * Instância da classe de utilitários.
	 *
	 * @var CODIR2ME_Admin_UI_Utils
	 */
	private $utils;

	/**
	 * Instância da aba de manutenção.
	 *
	 * @var CODIR2ME_Admin_UI_Maintenance
	 */
	private $maintenance_tab;

	/**
	 * Instância da aba de exclusão.
	 *
	 * @var CODIR2ME_Admin_UI_Delete
	 */
	private $delete_tab;

	/**
	 * Instância da aba de escaneamento.
	 *
	 * @var CODIR2ME_Admin_UI_Scanner
	 */
	private $scanner_tab;

	/**
	 * Instância da aba de otimização.
	 *
	 * @var CODIR2ME_Admin_UI_Optimizer
	 */
	private $optimizer_tab;

	/**
	 * Construtor
	 *
	 * @param codir2me_Admin $admin Instância da classe de administração.
	 */
	public function __construct( $admin ) {
		$this->admin = $admin;

		// Inicializar componentes UI.
		$this->general_tab     = new CODIR2ME_Admin_UI_General( $admin );
		$this->static_tab      = new CODIR2ME_Admin_UI_Static( $admin );
		$this->images_tab      = new CODIR2ME_Admin_UI_Images( $admin );
		$this->thumbnails      = new CODIR2ME_Admin_UI_Thumbnails( $admin );
		$this->utils           = new CODIR2ME_Admin_UI_Utils( $admin );
		$this->maintenance_tab = new CODIR2ME_Admin_UI_Maintenance( $admin );
		$this->delete_tab      = new CODIR2ME_Admin_UI_Delete( $admin );
		$this->scanner_tab     = new CODIR2ME_Admin_UI_Scanner( $admin );
		$this->optimizer_tab   = new CODIR2ME_Admin_UI_Optimizer( $admin );

		// Registrar estilos e scripts.
		add_action( 'admin_enqueue_scripts', array( $this, 'codir2me_register_admin_assets' ) );
		add_action( 'current_screen', array( $this, 'codir2me_render_help_tab' ) );
	}

	/**
	 * Registra e carrega os arquivos CSS e JS necessários
	 *
	 * @param string $hook Hook atual da página administrativa.
	 */
	public function codir2me_register_admin_assets( $hook ) {
		if ( false !== strpos( $hook, 'codirun-codir2me-cdn' ) ) {
			// Verificar permissões.
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			// CSS e JS globais sempre carregados.
			wp_register_style(
				'codir2me-cdn-tabs-style',
				CODIR2ME_CDN_PLUGIN_URL . 'assets/css/tabs.css',
				array(),
				CODIR2ME_CDN_VERSION
			);
			wp_enqueue_style( 'codir2me-cdn-tabs-style' );

			wp_register_script(
				'codir2me-cdn-tabs-script',
				CODIR2ME_CDN_PLUGIN_URL . 'assets/js/tabs.js',
				array( 'jquery' ),
				CODIR2ME_CDN_VERSION,
				true
			);
			wp_enqueue_script( 'codir2me-cdn-tabs-script' );

			// Determinar aba atual baseado na página ou parâmetro.
			$current_tab = $this->codir2me_determine_current_tab_from_hook( $hook );

			// Carregar CSS específico da aba.
			$this->codir2me_load_tab_specific_assets( $current_tab );

			$auto_continue        = false;
			$auto_continue_images = false;

			// Verificar nonce de upload de arquivos.
			if ( isset( $_GET['_wpnonce_upload'], $_GET['auto_continue'] ) && '1' === $_GET['auto_continue'] ) {
				if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce_upload'] ) ), 'codir2me_cdn_batch_upload' ) ) {
					$auto_continue = true;
				}
			}

			// Verificar nonce de upload de imagens.
			if ( isset( $_GET['_wpnonce_upload_images'], $_GET['auto_continue_images'] ) && '1' === $_GET['auto_continue_images'] ) {
				if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce_upload_images'] ) ), 'codir2me_cdn_batch_upload_images' ) ) {
					$auto_continue_images = true;
				}
			}

			// Configurar variáveis JavaScript se necessário.
			if ( $auto_continue || $auto_continue_images ) {
				wp_localize_script(
					'codir2me-cdn-tabs-script',
					'codir2me_auto_continue',
					array(
						'auto_continue'        => $auto_continue,
						'auto_continue_images' => $auto_continue_images,
					)
				);
			}
		}
	}

	/**
	 * Determina a aba atual baseado no hook da página e parâmetros
	 *
	 * @param string $hook Hook da página administrativa.
	 * @return string A aba atual
	 */
	private function codir2me_determine_current_tab_from_hook( $hook ) {
		// Primeiro, verificar se é uma página específica baseada no hook.
		$hook_to_tab_map = array(
			'toplevel_page_codirun-codir2me-cdn' => 'general',
			'codirun-r2-media-static-cdn_page_codirun-codir2me-cdn-static' => 'static',
			'codirun-r2-media-static-cdn_page_codirun-codir2me-cdn-images' => 'images',
			'codirun-r2-media-static-cdn_page_codirun-codir2me-cdn-optimization' => 'optimization',
			'codirun-r2-media-static-cdn_page_codirun-codir2me-cdn-reprocess' => 'reprocess',
			'codirun-r2-media-static-cdn_page_codirun-codir2me-cdn-maintenance' => 'maintenance',
			'codirun-r2-media-static-cdn_page_codirun-codir2me-cdn-delete' => 'delete',
			'codirun-r2-media-static-cdn_page_codirun-codir2me-cdn-scanner' => 'scanner',
		);

		// Se o hook corresponde a uma página específica, retornar a aba correspondente.
		if ( isset( $hook_to_tab_map[ $hook ] ) ) {
			return $hook_to_tab_map[ $hook ];
		}

		// Caso contrário, verificar o parâmetro tab (para sistema de tabs em uma página única).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab navigation doesn't require nonce verification
		if ( isset( $_GET['tab'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab parameter is safely validated
			$tab = sanitize_text_field( wp_unslash( $_GET['tab'] ) );

			// Lista de abas válidas.
			$valid_tabs = array(
				'general',
				'static',
				'images',
				'thumbnails',
				'utils',
				'maintenance',
				'delete',
				'scanner',
				'scanner-r2',
				'optimization',
				'reprocess',
			);

			if ( in_array( $tab, $valid_tabs, true ) ) {
				return $tab;
			}
		}

		// Padrão: aba geral.
		return 'general';
	}

	/**
	 * Carrega os assets específicos da aba atual
	 *
	 * @param string $current_tab A aba atual.
	 */
	private function codir2me_load_tab_specific_assets( $current_tab ) {
		// Mapeamento de abas para arquivos CSS apenas.
		$tab_assets_map = array(
			'general'      => array(
				'css' => 'admin-general-styles.css',
			),
			'static'       => array(
				'css' => 'admin-static.css',
			),
			'images'       => array(
				'css' => 'admin-styles.css',
			),
			'thumbnails'   => array(
				'css' => 'admin-styles.css',
			),
			'utils'        => array(
				'css' => 'admin-styles.css',
			),
			'maintenance'  => array(
				'css' => 'admin-styles.css',
			),
			'delete'       => array(
				'css' => 'admin-ui-delete.css',
			),
			'scanner'      => array(
				'css' => 'scanner-styles.css',
			),
			'scanner-r2'   => array(
				'css' => 'scanner-styles.css',
			),
			'optimization' => array(
				'css' => 'optimizer-admin.css',
			),
			'reprocess'    => array(
				'css' => 'reprocessor.css',
			),
		);

		// Verificar se existe configuração para esta aba.
		if ( ! isset( $tab_assets_map[ $current_tab ] ) ) {
			return;
		}

		$assets = $tab_assets_map[ $current_tab ];

		// Carregar APENAS CSS específico.
		if ( ! empty( $assets['css'] ) ) {
			$css_handle = 'codir2me-' . $current_tab . '-css';

			wp_register_style(
				$css_handle,
				CODIR2ME_CDN_PLUGIN_URL . 'assets/css/' . $assets['css'],
				array(),
				CODIR2ME_CDN_VERSION
			);
			wp_enqueue_style( $css_handle );
		}
	}

	/**
	 * Determina qual aba está ativa atualmente
	 *
	 * @return string A aba atual
	 */
	private function codir2me_get_current_tab() {
		$default_tab = 'general';

		// Verificar se há parâmetro tab na URL.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab navigation doesn't require nonce verification
		if ( ! isset( $_GET['tab'] ) ) {
			return $default_tab;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab parameter is safely validated against whitelist
		$tab = sanitize_text_field( wp_unslash( $_GET['tab'] ) );

		// Lista de abas válidas.
		$valid_tabs = array(
			'general',
			'static',
			'images',
			'thumbnails',
			'utils',
			'maintenance',
			'delete',
			'scanner',
			'scanner-r2',
			'optimization',
			'reprocess',
		);

		return in_array( $tab, $valid_tabs, true ) ? $tab : $default_tab;
	}

	/**
	 * Carrega o CSS específico da aba atual
	 *
	 * @param string $current_tab A aba atual.
	 */
	private function codir2me_load_tab_specific_css( $current_tab ) {
		// Mapeamento de abas para arquivos CSS.
		$tab_css_map = array(
			'general'      => 'admin-general-styles.css',
			'static'       => 'admin-static.css',
			'images'       => 'admin-styles.css',
			'thumbnails'   => 'admin-styles.css',
			'utils'        => 'admin-styles.css',
			'maintenance'  => 'admin-styles.css',
			'delete'       => 'admin-ui-delete.css',
			'scanner'      => 'scanner-styles.css',
			'scanner-r2'   => 'scanner-styles.css',
			'optimization' => 'optimizer-admin.css',
			'reprocess'    => 'reprocessor.css',
		);

		// Verificar se existe CSS específico para esta aba.
		if ( isset( $tab_css_map[ $current_tab ] ) ) {
			$css_file = $tab_css_map[ $current_tab ];
			$handle   = 'codir2me-' . str_replace( '.css', '', $css_file );

			wp_register_style(
				$handle,
				CODIR2ME_CDN_PLUGIN_URL . 'assets/css/' . $css_file,
				array(),
				CODIR2ME_CDN_VERSION
			);
			wp_enqueue_style( $handle );
		}
	}

	/**
	 * Renderiza a aba de ajuda no painel de administração.
	 */
	public function codir2me_render_help_tab() {
		$screen = get_current_screen();

		// Verificar se estamos na página do plugin.
		if ( ! isset( $screen->id ) || false === strpos( $screen->id, 'toplevel_page_codirun-codir2me-cdn' ) ) {
			return;
		}

		// Adicionar aba de ajuda para o modo de depuração.
		$screen->add_help_tab(
			array(
				'id'      => 'codir2me_debug_mode_help',
				'title'   => esc_html__( 'Modo de Depuração', 'codirun-codir2me-cdn' ),
				'content' => '
                <h2>' . esc_html__( 'Modo de Depuração', 'codirun-codir2me-cdn' ) . '</h2>
                <p>' . esc_html__( 'O modo de depuração permite rastrear operações do plugin para solução de problemas.', 'codirun-codir2me-cdn' ) . '</p>
                
                <h3>' . esc_html__( 'Como Ativar o Modo de Depuração', 'codirun-codir2me-cdn' ) . '</h3>
                <ol>
                    <li>' . sprintf(
							/* translators: %1$s: nome do menu, %2$s: nome do submenu */
					esc_html__( 'Acesse <strong>%1$s</strong> > <strong>%2$s</strong>', 'codirun-codir2me-cdn' ),
					esc_html__( 'Codirun R2 Media & Static CDN', 'codirun-codir2me-cdn' ),
					esc_html__( 'Configurações Gerais', 'codirun-codir2me-cdn' )
				) . '</li>
                    <li>' . sprintf(
							/* translators: %s: nome da seção */
					esc_html__( 'Vá para a seção <strong>%s</strong>', 'codirun-codir2me-cdn' ),
					esc_html__( 'Configurações Avançadas', 'codirun-codir2me-cdn' )
				) . '</li>
                    <li>' . sprintf(
							/* translators: %s: nome da opção */
					esc_html__( 'Marque a opção <strong>%s</strong>', 'codirun-codir2me-cdn' ),
					esc_html__( 'Ativar Modo de Depuração', 'codirun-codir2me-cdn' )
				) . '</li>
                    <li>' . sprintf(
							/* translators: %s: texto do botão */
					esc_html__( 'Clique em <strong>%s</strong>', 'codirun-codir2me-cdn' ),
					esc_html__( 'Salvar Configurações', 'codirun-codir2me-cdn' )
				) . '</li>
                </ol>
                
                <p><strong>' . esc_html__( 'Nota:', 'codirun-codir2me-cdn' ) . '</strong> ' . esc_html__( 'O modo de depuração pode gerar arquivos de log grandes e afetar ligeiramente o desempenho do seu site. Recomendamos ativá-lo apenas quando solicitado pelo suporte e desativá-lo após a resolução do problema.', 'codirun-codir2me-cdn' ) . '</p>
                
                <h3>' . esc_html__( 'Localização dos Logs de Depuração', 'codirun-codir2me-cdn' ) . '</h3>
                <p>' . esc_html__( 'Os logs de depuração são salvos em:', 'codirun-codir2me-cdn' ) . '</p>
                <pre>wp-content/uploads/codirun-codir2me-cdn-logs/debug.log</pre>
                <p>' . esc_html__( 'Você pode baixar este arquivo e enviá-lo para o suporte quando solicitado.', 'codirun-codir2me-cdn' ) . '</p>
                
                <h3>' . esc_html__( 'Gerenciamento de Logs', 'codirun-codir2me-cdn' ) . '</h3>
                <p>' . esc_html__( 'Quando o modo de depuração está ativado, você pode:', 'codirun-codir2me-cdn' ) . '</p>
                <ul>
                    <li><strong>' . esc_html__( 'Baixar Logs:', 'codirun-codir2me-cdn' ) . '</strong> ' . esc_html__( 'Clique no botão "Baixar Log" para baixar o arquivo de log atual.', 'codirun-codir2me-cdn' ) . '</li>
                    <li><strong>' . esc_html__( 'Limpar Logs:', 'codirun-codir2me-cdn' ) . '</strong> ' . esc_html__( 'Clique no botão "Limpar Log" para resetar o arquivo de log.', 'codirun-codir2me-cdn' ) . '</li>
                    <li><strong>' . esc_html__( 'Limpeza Automática:', 'codirun-codir2me-cdn' ) . '</strong> ' . esc_html__( 'A opção "Limpar logs ao desativar o plugin" excluirá automaticamente os logs quando o plugin for desativado.', 'codirun-codir2me-cdn' ) . '</li>
                </ul>
            ',
			)
		);

		// Adicionar barra lateral de ajuda.
		$screen->set_help_sidebar(
			'
            <p><strong>' . esc_html__( 'Links Úteis:', 'codirun-codir2me-cdn' ) . '</strong></p>
            <p><a href="https://codirun.com/suporte" target="_blank">' . esc_html__( 'Suporte Técnico', 'codirun-codir2me-cdn' ) . '</a></p>.
            <p><a href="https://codirun.com/docs/r2-cdn" target="_blank">' . esc_html__( 'Documentação', 'codirun-codir2me-cdn' ) . '</a></p>.
        '
		);
	}

	/**
	 * Cria um nonce para verificação de segurança
	 *
	 * @param string $action Nome da ação para o nonce.
	 * @return string HTML para o campo nonce
	 */
	public function codir2me_get_nonce_field( $action ) {
		return wp_nonce_field( $action, '_wpnonce', true, false );
	}

	/**
	 * Adiciona nonce a um URL para redirecionamento seguro
	 *
	 * @param string $url URL base.
	 * @param string $action Nome da ação para o nonce.
	 * @return string URL com nonce adicionado
	 */
	public function codir2me_add_nonce_to_url( $url, $action ) {
		return wp_nonce_url( $url, $action );
	}

	/**
	 * Renderiza a página de administração
	 *
	 * @param bool   $asyncaws_sdk_available Indica se o AWS SDK está disponível.
	 * @param string $active_tab Aba ativa atual.
	 * @param bool   $license_active Se a licença está ativa.
	 * @param bool   $is_premium_tab Se a aba atual é premium.
	 */
	public function codir2me_render_admin_page( $asyncaws_sdk_available, $active_tab, $license_active = false, $is_premium_tab = false ) {
		$plugin = $this->admin->codir2me_get_plugin();

		// Usar métodos seguros para obter manipuladores.
		$assets_handler = $plugin->codir2me_get_assets_handler() ? $plugin->codir2me_get_assets_handler() : new CODIR2ME_Assets_Handler( home_url() );
		$images_handler = $plugin->codir2me_get_images_handler() ? $plugin->codir2me_get_images_handler() : new CODIR2ME_Images_Handler( home_url() );

		// Obter listas de arquivos com fallback.
		$uploaded_files  = $assets_handler->codir2me_get_uploaded_files() ? $assets_handler->codir2me_get_uploaded_files() : array();
		$pending_files   = $assets_handler->codir2me_get_pending_files() ? $assets_handler->codir2me_get_pending_files() : array();
		$uploaded_images = $images_handler->codir2me_get_uploaded_images() ? $images_handler->codir2me_get_uploaded_images() : array();
		$pending_images  = $images_handler->codir2me_get_pending_images() ? $images_handler->codir2me_get_pending_images() : array();

		// Verificar status dos uploads.
		$upload_in_progress        = ! empty( $pending_files );
		$images_upload_in_progress = ! empty( $pending_images );

		// Verificar status do upload.
		$upload_status        = $this->admin->codir2me_get_upload_status( 'static' );
		$images_upload_status = $this->admin->codir2me_get_upload_status( 'images' );

		// Contar imagens do site.
		$images_count      = $this->admin->codir2me_count_total_images();
		$total_images      = $images_count['total_images'];
		$total_image_files = $images_count['total_files'];

		// Extrair valores de status para facilitar acesso.
		$current_batch   = isset( $upload_status['current_batch'] ) ? $upload_status['current_batch'] : 0;
		$total_batches   = isset( $upload_status['total_batches'] ) ? $upload_status['total_batches'] : 0;
		$total_files     = isset( $upload_status['total_files'] ) ? $upload_status['total_files'] : 0;
		$processed_files = isset( $upload_status['processed_files'] ) ? $upload_status['processed_files'] : 0;

		$images_current_batch   = isset( $images_upload_status['current_batch'] ) ? $images_upload_status['current_batch'] : 0;
		$images_total_batches   = isset( $images_upload_status['total_batches'] ) ? $images_upload_status['total_batches'] : 0;
		$images_total_files     = isset( $images_upload_status['total_files'] ) ? $images_upload_status['total_files'] : 0;
		$images_processed_files = isset( $images_upload_status['processed_files'] ) ? $images_upload_status['processed_files'] : 0;

		// Obter informações sobre tamanhos de miniaturas disponíveis.
		$thumbnail_sizes = $this->thumbnails->codir2me_get_thumbnail_sizes_info();

		// Renderizar o cabeçalho e navegação.
		$this->codir2me_render_header( $active_tab );

		// Verificar se é uma aba premium e se a licença está inativa.
		if ( $is_premium_tab && ! $license_active ) {
			$this->codir2me_render_premium_locked_screen( $active_tab );
			echo '</div>'; // Fechar o wrapper .wrap.
			return;
		}

		// Renderizar a aba ativa.
		if ( 'general' === $active_tab ) {
			$this->general_tab->codir2me_render( $asyncaws_sdk_available );
		} elseif ( 'static' === $active_tab ) {
			$this->static_tab->codir2me_render(
				$asyncaws_sdk_available,
				$upload_in_progress,
				$upload_status,
				$uploaded_files,
				$current_batch,
				$total_batches,
				$total_files,
				$processed_files
			);
		} elseif ( 'images' === $active_tab ) {
			$this->images_tab->codir2me_render(
				$asyncaws_sdk_available,
				$images_upload_in_progress,
				$images_upload_status,
				$uploaded_images,
				$thumbnail_sizes,
				$images_count,
				$total_images,
				$total_image_files,
				$images_current_batch,
				$images_total_batches,
				$images_total_files,
				$images_processed_files
			);
		} elseif ( 'maintenance' === $active_tab ) {
			$this->maintenance_tab->codir2me_render();
		} elseif ( 'delete' === $active_tab ) {
			// Inicializar a classe de exclusão no momento de renderizar.
			$delete_tab = new CODIR2ME_Admin_UI_Delete( $this->admin );
			$delete_tab->codir2me_render();
		} elseif ( 'scanner' === $active_tab ) {
			// Nova aba do escaneador.
			$this->scanner_tab->codir2me_render();
		} elseif ( 'optimization' === $active_tab ) {
			// Aba de otimização de imagens.
			$this->optimizer_tab->codir2me_render();
		} elseif ( 'reprocess' === $active_tab ) {
			// Aba de reprocessamento de imagens.
			$this->admin->codir2me_get_reprocessor()->codir2me_render();
		} elseif ( 'license' === $active_tab ) {
			// Carregar e renderizar a página de licenciamento.
			require_once CODIR2ME_CDN_INCLUDES_DIR . 'admin/class-codir2me-admin-ui-license.php';
			$license_ui = new CODIR2ME_Admin_UI_License( $this->admin );
			$license_ui->codir2me_render();
		}

		echo '</div>'; // Fechar o wrapper .wrap.
	}

	/**
	 * Renderiza o cabeçalho da página de administração com abas adaptáveis
	 *
	 * @param string $active_tab Aba ativa atual.
	 */
	private function codir2me_render_header( $active_tab ) {
		?>
		<div class="wrap codir2me-cdn-admin">
			<div class="codir2me-plugin-header">
				<img 
					src="<?php echo esc_url( CODIR2ME_CDN_PLUGIN_URL . 'assets/images/logo.webp' ); ?>" 
					alt="<?php echo esc_attr__( 'Cloudflare R2 CDN Logo', 'codirun-codir2me-cdn' ); ?>" 
					class="codir2me-plugin-logo"
				>
				<span class="codir2me-version">
				<?php
					/* translators: %s: versão do plugin */
					printf( esc_html__( 'v%s', 'codirun-codir2me-cdn' ), esc_html( CODIR2ME_CDN_VERSION ) );
				?>
				</span>
			</div>
			
			<div class="codir2me-tabs-container">
				<ul class="codir2me-tabs codir2me-tabs--scrollable">
					<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=codirun-codir2me-cdn' ) ); ?>" class="<?php echo 'general' === $active_tab ? 'active' : ''; ?>"><?php esc_html_e( 'Configurações Gerais', 'codirun-codir2me-cdn' ); ?></a></li>
					<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=codirun-codir2me-cdn-static' ) ); ?>" class="<?php echo 'static' === $active_tab ? 'active' : ''; ?>"><?php esc_html_e( 'Arquivos Estáticos', 'codirun-codir2me-cdn' ); ?></a></li>
					<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=codirun-codir2me-cdn-images' ) ); ?>" class="<?php echo 'images' === $active_tab ? 'active' : ''; ?>"><?php esc_html_e( 'Imagens', 'codirun-codir2me-cdn' ); ?></a></li>
					<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=codirun-codir2me-cdn-optimization' ) ); ?>" class="<?php echo 'optimization' === $active_tab ? 'active' : ''; ?>"><?php esc_html_e( 'Otimização', 'codirun-codir2me-cdn' ); ?></a></li>
					<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=codirun-codir2me-cdn-reprocess' ) ); ?>" class="<?php echo 'reprocess' === $active_tab ? 'active' : ''; ?>"><?php esc_html_e( 'Reprocessar Imagens', 'codirun-codir2me-cdn' ); ?></a></li>
					<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=codirun-codir2me-cdn-maintenance' ) ); ?>" class="<?php echo 'maintenance' === $active_tab ? 'active' : ''; ?>"><?php esc_html_e( 'Manutenção', 'codirun-codir2me-cdn' ); ?></a></li>
					<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=codirun-codir2me-cdn-scanner' ) ); ?>" class="<?php echo 'scanner' === $active_tab ? 'active' : ''; ?>"><?php esc_html_e( 'Escaneamento R2', 'codirun-codir2me-cdn' ); ?></a></li>
					<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=codirun-codir2me-cdn-delete' ) ); ?>" class="<?php echo 'delete' === $active_tab ? 'active' : ''; ?>"><?php esc_html_e( 'Excluir Arquivos', 'codirun-codir2me-cdn' ); ?></a></li>
					<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=codirun-codir2me-cdn-license' ) ); ?>" class="<?php echo 'license' === $active_tab ? 'active' : ''; ?>"><?php esc_html_e( 'Licença', 'codirun-codir2me-cdn' ); ?></a></li>
				</ul>
			</div>
		<?php
	}

	/**
	 * Renderiza a tela de bloqueio para recursos premium
	 *
	 * @param string $tab A aba atual.
	 */
	private function codir2me_render_premium_locked_screen( $tab ) {
		$feature_info = array(
			'optimization' => array(
				'name'        => esc_html__( 'Otimização de Imagens', 'codirun-codir2me-cdn' ),
				'description' => esc_html__( 'Esta funcionalidade premium permite comprimir e converter automaticamente suas imagens para formatos modernos, reduzindo o tamanho dos arquivos sem perder qualidade.', 'codirun-codir2me-cdn' ),
				'benefits'    => array(
					esc_html__( 'Compressão de imagens para reduzir o tamanho sem perder qualidade', 'codirun-codir2me-cdn' ),
					esc_html__( 'Conversão automática para formatos modernos como WebP e AVIF', 'codirun-codir2me-cdn' ),
					esc_html__( 'Configurações personalizáveis de qualidade e compressão', 'codirun-codir2me-cdn' ),
					esc_html__( 'Economize largura de banda e acelere o carregamento do seu site', 'codirun-codir2me-cdn' ),
				),
			),
			'reprocess'    => array(
				'name'        => esc_html__( 'Reprocessar Imagens', 'codirun-codir2me-cdn' ),
				'description' => esc_html__( 'Esta funcionalidade premium permite reprocessar imagens existentes com novas configurações de otimização sem precisar fazer novo upload de tudo.', 'codirun-codir2me-cdn' ),
				'benefits'    => array(
					esc_html__( 'Atualize facilmente imagens existentes para novos formatos', 'codirun-codir2me-cdn' ),
					esc_html__( 'Aplique diferentes configurações de otimização a imagens já enviadas', 'codirun-codir2me-cdn' ),
					esc_html__( 'Economize tempo evitando o reenvio completo de todas as imagens', 'codirun-codir2me-cdn' ),
					esc_html__( 'Mantenha seu site atualizado com os formatos de imagem mais recentes', 'codirun-codir2me-cdn' ),
				),
			),
			'maintenance'  => array(
				'name'        => esc_html__( 'Manutenção Avançada', 'codirun-codir2me-cdn' ),
				'description' => esc_html__( 'Esta funcionalidade premium oferece ferramentas avançadas para manutenção e gerenciamento do seu bucket R2.', 'codirun-codir2me-cdn' ),
				'benefits'    => array(
					esc_html__( 'Resolva inconsistências entre os registros locais e os arquivos reais no R2', 'codirun-codir2me-cdn' ),
					esc_html__( 'Reconstrua estatísticas e metadados para corrigir problemas de contagem', 'codirun-codir2me-cdn' ),
					esc_html__( 'Limpe registros de imagens que não existem mais na biblioteca de mídia', 'codirun-codir2me-cdn' ),
					esc_html__( 'Marque facilmente todas as imagens como enviadas para corrigir problemas de status', 'codirun-codir2me-cdn' ),
				),
			),
			'scanner'      => array(
				'name'        => esc_html__( 'Escaneamento R2', 'codirun-codir2me-cdn' ),
				'description' => esc_html__( 'Esta funcionalidade premium permite escanear seu bucket R2 para identificar e sincronizar arquivos com o WordPress.', 'codirun-codir2me-cdn' ),
				'benefits'    => array(
					esc_html__( 'Identifique arquivos no R2 que não estão registrados no WordPress', 'codirun-codir2me-cdn' ),
					esc_html__( 'Sincronize automaticamente arquivos entre as plataformas', 'codirun-codir2me-cdn' ),
					esc_html__( 'Corrija inconsistências entre o WordPress e o R2', 'codirun-codir2me-cdn' ),
					esc_html__( 'Importe arquivos existentes no R2 para o WordPress', 'codirun-codir2me-cdn' ),
				),
			),
			'delete'       => array(
				'name'        => esc_html__( 'Excluir Arquivos', 'codirun-codir2me-cdn' ),
				'description' => esc_html__( 'Esta funcionalidade premium permite gerenciar e excluir arquivos do seu bucket R2 diretamente da interface do WordPress.', 'codirun-codir2me-cdn' ),
				'benefits'    => array(
					esc_html__( 'Configure a exclusão automática de imagens no R2 quando removidas do WordPress', 'codirun-codir2me-cdn' ),
					esc_html__( 'Limpe seu bucket R2 para economizar espaço', 'codirun-codir2me-cdn' ),
					esc_html__( 'Controle precisamente quais miniaturas devem ser excluídas automaticamente', 'codirun-codir2me-cdn' ),
					esc_html__( 'Mantenha seu bucket R2 organizado e otimizado, evitando acúmulo de arquivos não utilizados', 'codirun-codir2me-cdn' ),
				),
			),
		);

		$info = isset( $feature_info[ $tab ] ) ? $feature_info[ $tab ] : array(
			'name'        => esc_html__( 'Funcionalidade Premium', 'codirun-codir2me-cdn' ),
			'description' => esc_html__( 'Esta é uma funcionalidade premium disponível apenas para usuários com licença ativa.', 'codirun-codir2me-cdn' ),
			'benefits'    => array(
				esc_html__( 'Acesso a recursos avançados', 'codirun-codir2me-cdn' ),
				esc_html__( 'Otimização de desempenho', 'codirun-codir2me-cdn' ),
				esc_html__( 'Economia de tempo com automação', 'codirun-codir2me-cdn' ),
				esc_html__( 'Suporte técnico prioritário', 'codirun-codir2me-cdn' ),
			),
		);

		// Obter o nome da funcionalidade para o botão.
		$feature_name = isset( $info['name'] ) ? $info['name'] : esc_html__( 'Funcionalidade Premium', 'codirun-codir2me-cdn' );
		?>
		<div class="codir2me-tab-content">
			<div class="codir2me-premium-locked">
			
				<div class="codir2me-premium-icon">
					<span class="dashicons dashicons-lock"></span>
				</div>
				
				<h2><?php echo esc_html( $info['name'] ); ?></h2>

				<div class="notice notice-warning">
				<p>
					<span class="dashicons dashicons-lock" style="color: #f56e28; margin-right: 10px;"></span>
					<strong><?php esc_html_e( 'Funcionalidade Premium:', 'codirun-codir2me-cdn' ); ?></strong> <?php esc_html_e( 'Esta funcionalidade requer uma licença ativa.', 'codirun-codir2me-cdn' ); ?> 
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=codirun-codir2me-cdn-license' ) ); ?>"><?php esc_html_e( 'Ativar licença', 'codirun-codir2me-cdn' ); ?></a>
				</p>
				</div>
				
				<p class="codir2me-premium-description">
					<?php echo esc_html( $info['description'] ); ?>
				</p>
				
				<div class="codir2me-premium-features-preview">
					<h3><?php esc_html_e( 'O que você ganha com esta funcionalidade:', 'codirun-codir2me-cdn' ); ?></h3>
					<ul>
						<?php foreach ( $info['benefits'] as $benefit ) : ?>
						<li><span class="dashicons dashicons-yes"></span> <?php echo esc_html( $benefit ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
				
				<div class="codir2me-premium-cta">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=codirun-codir2me-cdn-license' ) ); ?>" class="button button-primary button-hero">
						<span class="dashicons dashicons-unlock"></span>
						<?php
						/* translators: %s: nome da funcionalidade */
						printf( esc_html__( 'Desbloquear %s', 'codirun-codir2me-cdn' ), esc_html( $feature_name ) );
						?>
					</a>
					<p class="codir2me-premium-note">
						<?php esc_html_e( 'Adquira uma licença para desbloquear todas as funcionalidades premium', 'codirun-codir2me-cdn' ); ?>
					</p>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Obtém o tipo MIME de um arquivo
	 *
	 * @param string $file Caminho do arquivo.
	 * @return string Tipo MIME do arquivo
	 */
	private function codir2me_get_mime_type( $file ) {
		$ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );

		$mime_types = array(
			'js'    => 'application/javascript',
			'css'   => 'text/css',
			'svg'   => 'image/svg+xml',
			'woff'  => 'font/woff',
			'woff2' => 'font/woff2',
			'ttf'   => 'font/ttf',
			'eot'   => 'application/vnd.ms-fontobject',
			'jpg'   => 'image/jpeg',
			'jpeg'  => 'image/jpeg',
			'png'   => 'image/png',
			'gif'   => 'image/gif',
			'webp'  => 'image/webp',
		);

		return isset( $mime_types[ $ext ] ) ? $mime_types[ $ext ] : 'application/octet-stream';
	}

	/**
	 * Exclui um objeto do R2
	 *
	 * @param string $object_key Chave do objeto a ser excluído.
	 * @return bool Sucesso ou falha
	 * @throws Exception Se ocorrer um erro durante a exclusão.
	 */
	public function codir2me_delete_object( $object_key ) {
		try {
			$s3_client = $this->codir2me_get_s3_client();

			// Excluir objeto do R2.
			$s3_client->deleteObject(
				array(
					'Bucket' => $this->bucket,
					'Key'    => $object_key,
				)
			);

			return true;

		} catch ( Exception $e ) {
			throw new Exception(
				sprintf(
					/* translators: %s: mensagem de erro */
					esc_html__( 'Erro ao excluir objeto do R2: %s', 'codirun-codir2me-cdn' ),
					esc_html( $e->getMessage() )
				)
			);
		}
	}

	/**
	 * Exclui vários objetos do R2 de uma vez
	 *
	 * @param array $object_keys Array de chaves de objetos a serem excluídos.
	 * @return array Array com objetos excluídos e erros
	 */
	public function codir2me_delete_objects( $object_keys ) {
		try {
			$s3_client = $this->codir2me_get_s3_client();

			// Preparar objetos para exclusão em lote.
			$objects = array();
			foreach ( $object_keys as $key ) {
				$objects[] = array( 'Key' => $key );
			}

			// Excluir objetos do R2.
			$result = $s3_client->deleteObjects(
				array(
					'Bucket' => $this->bucket,
					'Delete' => array(
						'Objects' => $objects,
					),
				)
			);

			return array(
				'deleted' => isset( $result['Deleted'] ) ? count( $result['Deleted'] ) : 0,
				'errors'  => isset( $result['Errors'] ) ? $result['Errors'] : array(),
			);

		} catch ( Exception $e ) {
			return array(
				'deleted' => 0,
				'errors'  => array( array( 'Message' => $e->getMessage() ) ),
			);
		}
	}
}
