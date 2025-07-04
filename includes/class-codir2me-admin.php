<?php
/**
 * Classe que gerencia a interface administrativa do plugin
 *
 * @package Codirun_R2_Media_Static_CDN
 */

// Evitar acesso direto ao arquivo.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classe responsável por gerenciar a interface administrativa do plugin
 */
class CODIR2ME_Admin {
	/**
	 * Instância do plugin principal
	 *
	 * @var object
	 */
	private $plugin;

	/**
	 * Aba ativa atual
	 *
	 * @var string
	 */
	private $active_tab = 'general';

	/**
	 * Instância da classe de UI
	 *
	 * @var CODIR2ME_Admin_UI
	 */
	private $ui;

	/**
	 * Instância da classe de UI de escaneamento
	 *
	 * @var CODIR2ME_Admin_UI_Scanner
	 */
	private $scanner_ui;

	/**
	 * Instância da classe de UI de otimização
	 *
	 * @var CODIR2ME_Admin_UI_Optimizer
	 */
	private $optimizer_ui;

	/**
	 * Instância da classe de reprocessamento
	 *
	 * @var CODIR2ME_Image_Reprocessor
	 */
	private $reprocessor;

	/**
	 * Instância do verificador de ambiente
	 *
	 * @var CODIR2ME_Environment_Checker
	 */
	private $environment_checker;

	/**
	 * Instância da aba de manutenção
	 *
	 * @var CODIR2ME_Admin_UI_Maintenance
	 */
	private $maintenance_tab;

	/**
	 * Array com recursos premium
	 *
	 * @var array
	 */
	private $premium_features = array(
		'delete'       => true,
		'optimization' => true,
		'scanner'      => true,
		'reprocess'    => true,
		'maintenance'  => true,
	);

	/**
	 * Construtor da classe
	 *
	 * @param object $plugin Instância do plugin principal.
	 */
	public function __construct( $plugin ) {
		$this->plugin = $plugin;

		// Carregar a classe de UI.
		require_once CODIR2ME_CDN_INCLUDES_DIR . '/admin/class-codir2me-admin-ui.php';
		$this->ui = new CODIR2ME_Admin_UI( $this );

		// Carregar a classe de UI de escaneamento.
		require_once CODIR2ME_CDN_INCLUDES_DIR . '/admin/class-codir2me-admin-ui-scanner.php';
		$this->scanner_ui = new CODIR2ME_Admin_UI_Scanner( $this );

		// Carregar a classe de UI de otimização.
		require_once CODIR2ME_CDN_INCLUDES_DIR . '/admin/class-codir2me-admin-ui-optimizer.php';
		$this->optimizer_ui = new CODIR2ME_Admin_UI_Optimizer( $this );

		// Carregar a classe de UI de manutenção.
		require_once CODIR2ME_CDN_INCLUDES_DIR . '/admin/class-codir2me-admin-ui-maintenance.php';
		$this->maintenance_tab = new CODIR2ME_Admin_UI_Maintenance( $this );

		// Carregar a classe de reprocessamento.
		require_once CODIR2ME_CDN_INCLUDES_DIR . 'class-codir2me-image-reprocessor.php';
		$this->reprocessor = new CODIR2ME_Image_Reprocessor( $this );

		// Inicializar o verificador de ambiente.
		require_once CODIR2ME_CDN_INCLUDES_DIR . 'class-codir2me-environment-checker.php';
		$this->environment_checker = new CODIR2ME_Environment_Checker();

		// Hooks para registro de menus e configurações.
		add_action( 'admin_menu', array( $this, 'codir2me_add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'codir2me_register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'codir2me_enqueue_admin_scripts' ) );

		// Adicionar logs.
		add_action( 'admin_post_codir2me_download_log', array( $this, 'codir2me_download_log_action' ) );
		add_action( 'admin_post_codir2me_clear_log', array( $this, 'codir2me_clear_log_action' ) );

		// Adicionar hooks de processamento em lotes.
		add_action( 'admin_post_codir2me_scan_files', array( $this, 'codir2me_scan_files_action' ) );
		add_action( 'admin_post_codir2me_process_batch', array( $this, 'codir2me_process_batch_action' ) );
		add_action( 'admin_post_codir2me_scan_images', array( $this, 'codir2me_scan_images_action' ) );
		add_action( 'admin_post_codir2me_process_images_batch', array( $this, 'codir2me_process_images_batch_action' ) );

		// Adicionar avisos de upload completo.
		add_action( 'admin_notices', array( $this, 'codir2me_upload_complete_notice' ) );

		// Adicionar filtro para links de plugins.
		add_filter( 'plugin_action_links_' . plugin_basename( CODIR2ME_CDN_PLUGIN_DIR . 'codirun-codir2me-cdn.php' ), array( $this, 'codir2me_add_settings_link' ) );

		// Adicionar botão de parar.
		add_action( 'admin_post_codir2me_cancel_upload', array( $this, 'codir2me_cancel_upload_action' ) );
		add_action( 'admin_post_codir2me_cancel_delete', array( $this, 'codir2me_cancel_delete_action' ) );

		// Adicionar ação para limpeza de arquivos duplicados.
		add_action( 'admin_post_codir2me_codir2me_cleanup_duplicate_files', array( $this, 'codir2me_cleanup_duplicate_files' ) );

		// No método __construct da classe codir2me_Admin, adicione:.
		add_action( 'admin_post_codir2me_clear_uploaded_files', array( $this, 'codir2me_clear_uploaded_files_action' ) );
		add_action( 'admin_post_codir2me_codir2me_cleanup_duplicate_files', array( $this, 'codir2me_cleanup_duplicate_files' ) );

		// Adicionar endpoint AJAX para reenvio de arquivos.
		add_action( 'wp_ajax_codir2me_resync_file', array( $this, 'codir2me_ajax_resync_file' ) );

		// Adicionar hook específico para o estilo do ícone do menu.
		add_action( 'admin_enqueue_scripts', array( $this, 'codir2me_enqueue_menu_icon_style' ) );

		// Adicionar handler para configurações de reprocessamento.
		add_action( 'admin_post_codir2me_update_reprocessing_settings', array( $this->reprocessor, 'codir2me_handle_update_reprocessing_settings' ) );
	}

	/**
	 * Registra e carrega o estilo específico para o ícone do menu
	 *
	 * @return void
	 */
	public function codir2me_enqueue_menu_icon_style() {
		// Registrar e carregar o CSS para o ícone do menu.
		// Esta função será executada em todas as páginas admin.
		wp_register_style(
			'codir2me-menu-icon-style',
			CODIR2ME_CDN_PLUGIN_URL . 'assets/css/admin-menu-icon.css',
			array(), // Sem dependências.
			CODIR2ME_CDN_VERSION
		);

		// Carregar em todas as páginas admin já que o menu aparece em todas elas.
		wp_enqueue_style( 'codir2me-menu-icon-style' );
	}

	/**
	 * Corrige o problema com o carregamento de CSS baseado na aba atual
	 *
	 * @param string $hook Hook da página atual.
	 */
	public function codir2me_enqueue_admin_scripts( $hook ) {
		// Verificar se estamos em uma página do plugin.
		if ( false === strpos( $hook, 'codirun-codir2me-cdn' ) ) {
			return;
		}

		// Detectar a aba diretamente do hook ao invés de usar codir2me_determine_current_admin_tab().
		$tab = 'general';

		// Mapeamento direto do hook para aba.
		$hook_to_tab_map = array(
			'toplevel_page_codirun-codir2me-cdn' => 'general',
			'codirun-r2-media-static-cdn_page_codirun-codir2me-cdn-static' => 'static',
			'codirun-r2-media-static-cdn_page_codirun-codir2me-cdn-images' => 'images',
			'codirun-r2-media-static-cdn_page_codirun-codir2me-cdn-optimization' => 'optimization',
			'codirun-r2-media-static-cdn_page_codirun-codir2me-cdn-reprocess' => 'reprocess',
			'codirun-r2-media-static-cdn_page_codirun-codir2me-cdn-maintenance' => 'maintenance',
			'codirun-r2-media-static-cdn_page_codirun-codir2me-cdn-delete' => 'delete',
			'codirun-r2-media-static-cdn_page_codirun-codir2me-cdn-scanner' => 'scanner',
			'codirun-r2-media-static-cdn_page_codirun-codir2me-cdn-license' => 'license',
		);

		if ( isset( $hook_to_tab_map[ $hook ] ) ) {
			$tab = $hook_to_tab_map[ $hook ];
		}

		// Estilo principal do admin (somente nas páginas do plugin).
		wp_enqueue_style(
			'codir2me-admin-styles',
			CODIR2ME_CDN_PLUGIN_URL . 'assets/css/admin-styles.css',
			array(),
			CODIR2ME_CDN_VERSION
		);

		// Strings de tradução completas para JavaScript.
		$i18n_strings = array(
			// Strings existentes.
			'confirm_stop_title'                 => esc_html__( 'Confirmar Interrupção', 'codirun-codir2me-cdn' ),
			'confirm_stop_message'               => esc_html__( 'Tem certeza que deseja parar o processo de reprocessamento? O processo será cancelado e você precisará recomeçar do início se quiser continuar.', 'codirun-codir2me-cdn' ),
			'confirm_button'                     => esc_html__( 'Sim, Parar Reprocessamento', 'codirun-codir2me-cdn' ),
			'cancel_button'                      => esc_html__( 'Não, Continuar Processando', 'codirun-codir2me-cdn' ),
			'stop_canceled'                      => esc_html__( 'Operação cancelada. O reprocessamento continua.', 'codirun-codir2me-cdn' ),
			'loading_images'                     => esc_html__( 'Carregando imagens processadas recentemente...', 'codirun-codir2me-cdn' ),
			'no_images_selected'                 => esc_html__( 'Nenhuma imagem selecionada. Clique em "Selecionar Imagens" para escolher quais imagens processar.', 'codirun-codir2me-cdn' ),
			/* translators: %d: número de imagens selecionadas */
			'x_images_selected'                  => esc_html__( '%d imagens selecionadas', 'codirun-codir2me-cdn' ),

			// Strings relacionadas à otimização que estavam faltando.
			'apply_preset_values'                => esc_html__( 'Aplicar Valores do Nível Selecionado', 'codirun-codir2me-cdn' ),
			'apply_preset_description'           => esc_html__( 'Isso aplicará os valores predefinidos do nível selecionado às configurações avançadas.', 'codirun-codir2me-cdn' ),
			'hide_advanced_settings'             => esc_html__( 'Esconder Configurações Avançadas', 'codirun-codir2me-cdn' ),
			'show_advanced_settings'             => esc_html__( 'Mostrar Configurações Avançadas', 'codirun-codir2me-cdn' ),
			'values_applied'                     => esc_html__( 'Valores aplicados!', 'codirun-codir2me-cdn' ),
			'confirm_reset_stats'                => esc_html__( 'Tem certeza que deseja redefinir todas as estatísticas de otimização? Esta ação não pode ser desfeita.', 'codirun-codir2me-cdn' ),

			// Chave e tradução para limpeza de logs.
			'confirm_clear_logs'                 => esc_html__( 'Tem certeza que deseja limpar todos os logs? Esta ação não pode ser desfeita.', 'codirun-codir2me-cdn' ),

			// Traduções para status ativo/inativo.
			'active'                             => esc_html__( 'Ativo', 'codirun-codir2me-cdn' ),
			'inactive'                           => esc_html__( 'Inativo', 'codirun-codir2me-cdn' ),

			'order_changed_notice'               => esc_html__( 'Ordem alterada. Clique em "Salvar Prioridade de Formatos" para aplicar as mudanças.', 'codirun-codir2me-cdn' ),
			'log_details'                        => esc_html__( 'Detalhes do Log:', 'codirun-codir2me-cdn' ),
			'error_details'                      => esc_html__( 'Detalhes do Erro:', 'codirun-codir2me-cdn' ),
			'connection_error'                   => esc_html__( 'Erro de conexão. Por favor, tente novamente.', 'codirun-codir2me-cdn' ),
			'load_more_logs_error'               => esc_html__( 'Erro ao carregar mais logs. Por favor, tente novamente.', 'codirun-codir2me-cdn' ),
			'load_more_logs_error_msg'           => esc_html__( 'Erro ao carregar mais logs:', 'codirun-codir2me-cdn' ),

			// Strings para upload automático.
			'auto_upload_thumbnails_enabled'     => esc_html__( 'Upload automático de miniaturas ativado:', 'codirun-codir2me-cdn' ),
			'auto_upload_thumbnails_description' => esc_html__( 'As miniaturas selecionadas serão enviadas automaticamente para o R2 quando novas imagens forem adicionadas.', 'codirun-codir2me-cdn' ),
			'enable_cdn_first'                   => esc_html__( 'Ative o CDN de imagens primeiro para usar esta opção.', 'codirun-codir2me-cdn' ),
			'change_thumbnail_option'            => esc_html__( 'Mude a opção "Tamanhos de Miniatura" para ativar esta função.', 'codirun-codir2me-cdn' ),

			// Strings para reprocessamento.
			'remove'                             => esc_html__( 'Remover', 'codirun-codir2me-cdn' ),
			'select_images_title'                => esc_html__( 'Selecionar Imagens para Upload', 'codirun-codir2me-cdn' ),
			'select_images_button'               => esc_html__( 'Selecionar Imagens', 'codirun-codir2me-cdn' ),
			'error_loading_preview'              => esc_html__( 'Erro ao carregar visualização das imagens.', 'codirun-codir2me-cdn' ),
			'error_loading_preview_retry'        => esc_html__( 'Erro ao carregar visualização das imagens. Tente novamente.', 'codirun-codir2me-cdn' ),
			'paused'                             => esc_html__( 'Pausado', 'codirun-codir2me-cdn' ),
			'running'                            => esc_html__( 'Executando', 'codirun-codir2me-cdn' ),
		);

		// Scripts gerais para todas as páginas do plugin.
		wp_enqueue_script(
			'codir2me-admin-scripts',
			CODIR2ME_CDN_PLUGIN_URL . 'assets/js/admin-scripts.js',
			array( 'jquery' ),
			CODIR2ME_CDN_VERSION,
			true
		);

		// Usar wp_localize_script com dados mais completos.
		wp_localize_script(
			'codir2me-admin-scripts',
			'codir2me_admin_vars',
			array(
				'nonce'    => wp_create_nonce( 'codir2me_admin_nonce' ),
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'ajaxurl'  => admin_url( 'admin-ajax.php' ),
				'tab'      => $tab,
				'i18n'     => $i18n_strings,
			)
		);

		// Carregar scripts específicos da página.
		if ( 'reprocess' === $tab ) {
			wp_enqueue_media();

			wp_enqueue_script(
				'codir2me-reprocessor-scripts',
				CODIR2ME_CDN_PLUGIN_URL . 'assets/js/reprocessor.js',
				array( 'jquery', 'codir2me-admin-scripts', 'wp-util', 'media-upload', 'media-views' ),
				CODIR2ME_CDN_VERSION,
				true
			);

			// Verificar se codir2me_reprocessor_vars não está definido para evitar conflitos.
			if ( ! wp_script_is( 'codir2me-reprocessor-scripts', 'localized' ) ) {
				wp_localize_script(
					'codir2me-reprocessor-scripts',
					'codir2me_reprocessor_vars',
					array_merge(
						$i18n_strings,
						array(
							'nonce'                       => wp_create_nonce( 'codir2me_reprocessor_nonce' ),
							'ajaxurl'                     => admin_url( 'admin-ajax.php' ),
							'error_loading_preview_retry' => esc_html__( 'Erro ao carregar visualização. Tente novamente.', 'codirun-codir2me-cdn' ),
						)
					)
				);
			}
		}

		if ( 'scanner' === $tab ) {
			wp_enqueue_script(
				'codir2me-scanner-scripts',
				CODIR2ME_CDN_PLUGIN_URL . 'assets/js/scanner-scripts.js',
				array( 'jquery', 'codir2me-admin-scripts' ),
				CODIR2ME_CDN_VERSION,
				true
			);

			wp_localize_script(
				'codir2me-scanner-scripts',
				'codir2me_scanner_vars',
				array_merge(
					$i18n_strings,
					array(
						'scanner_nonce'             => wp_create_nonce( 'codir2me_scanner_ajax_nonce' ),
						'ajaxurl'                   => admin_url( 'admin-ajax.php' ),
						'ajax_url'                  => admin_url( 'admin-ajax.php' ),
						'scan_process_nonce'        => wp_create_nonce( 'codir2me_scan_process' ),
						'import_scan_process_nonce' => wp_create_nonce( 'codir2me_import_scan_process' ),
						'import_results_nonce'      => wp_create_nonce( 'codir2me_import_results_nonce' ),
					)
				)
			);
		}

		if ( 'delete' === $tab ) {
			wp_enqueue_script(
				'codir2me-delete-scripts',
				CODIR2ME_CDN_PLUGIN_URL . 'assets/js/admin-ui-delete.js',
				array( 'jquery', 'codir2me-admin-scripts' ),
				CODIR2ME_CDN_VERSION,
				true
			);

			wp_localize_script(
				'codir2me-delete-scripts',
				'codir2me_delete_vars',
				array_merge(
					$i18n_strings,
					array(
						'nonce'                     => wp_create_nonce( 'codir2me_delete_nonce' ),
						'ajax_url'                  => admin_url( 'admin-ajax.php' ),
						'ajaxurl'                   => admin_url( 'admin-ajax.php' ),
						'quick_delete_nonce'        => wp_create_nonce( 'codir2me_quick_delete_batch_nonce' ),
						'background_deletion_nonce' => wp_create_nonce( 'codir2me_background_deletion_nonce' ),
						'redirect_url'              => admin_url( 'admin.php?page=codirun-codir2me-cdn-delete&quick_delete_complete=1&count=' ),
						'error_url'                 => admin_url( 'admin.php?page=codirun-codir2me-cdn-delete&quick_delete_error=' ),
						'page_url'                  => admin_url( 'admin.php?page=codirun-codir2me-cdn-delete' ),
					)
				)
			);
		}

		if ( 'static' === $tab ) {
			wp_enqueue_script(
				'codir2me-static-scripts',
				CODIR2ME_CDN_PLUGIN_URL . 'assets/js/admin-static.js',
				array( 'jquery', 'codir2me-admin-scripts' ),
				CODIR2ME_CDN_VERSION,
				true
			);

			wp_localize_script(
				'codir2me-static-scripts',
				'codir2me_static_vars',
				array_merge(
					$i18n_strings,
					array(
						'nonce'   => wp_create_nonce( 'codir2me_static_nonce' ),
						'ajaxurl' => admin_url( 'admin-ajax.php' ),
					)
				)
			);
			wp_localize_script(
				'codir2me-static-scripts',
				'codir2me',
				array(
					'nonce'           => wp_create_nonce( 'codir2me_resync_file_nonce' ),
					'ajaxurl'         => admin_url( 'admin-ajax.php' ),
					'updated'         => __( 'Atualizado!', 'codirun-codir2me-cdn' ),
					'error'           => __( 'Erro:', 'codirun-codir2me-cdn' ),
					'connectionError' => __( 'Erro de conexão. Tente novamente.', 'codirun-codir2me-cdn' ),
					'processing'      => __( 'Processando...', 'codirun-codir2me-cdn' ),
					'success'         => __( 'Sucesso!', 'codirun-codir2me-cdn' ),
					'failed'          => __( 'Falhou!', 'codirun-codir2me-cdn' ),
					'copied'          => __( 'Copiado!', 'codirun-codir2me-cdn' ),
				)
			);
		}

		if ( 'general' === $tab ) {
			wp_enqueue_script(
				'codir2me-general-scripts',
				CODIR2ME_CDN_PLUGIN_URL . 'assets/js/admin-general-scripts.js',
				array( 'jquery', 'codir2me-admin-scripts' ),
				CODIR2ME_CDN_VERSION,
				true
			);

			wp_localize_script(
				'codir2me-general-scripts',
				'codir2me_general_vars',
				array_merge(
					$i18n_strings,
					array(
						'nonce'   => wp_create_nonce( 'codir2me_general_nonce' ),
						'ajaxurl' => admin_url( 'admin-ajax.php' ),
					)
				)
			);

		}
	}

	/**
	 * Determina a aba atual baseado na página ou no parâmetro tab
	 *
	 * @return string A aba atual
	 */
	private function codir2me_determine_current_admin_tab() {
		// PRIMEIRO: Verificar se temos uma aba definida pela página atual (definida nos métodos admin_page_*).
		if ( ! empty( $this->active_tab ) ) {
			return $this->active_tab;
		}

		// SEGUNDO: Tentar detectar pela tela atual.
		$current_screen = get_current_screen();
		if ( isset( $current_screen->id ) ) {
			$page_id = $current_screen->id;

			// Mapeamento específico por página.
			$page_to_tab_map = array(
				'toplevel_page_codirun-codir2me-cdn' => 'general',
				'codirun-r2-media-static-cdn_page_codirun-codir2me-cdn-static' => 'static',
				'codirun-r2-media-static-cdn_page_codirun-codir2me-cdn-images' => 'images',
				'codirun-r2-media-static-cdn_page_codirun-codir2me-cdn-optimization' => 'optimization',
				'codirun-r2-media-static-cdn_page_codirun-codir2me-cdn-reprocess' => 'reprocess',
				'codirun-r2-media-static-cdn_page_codirun-codir2me-cdn-maintenance' => 'maintenance',
				'codirun-r2-media-static-cdn_page_codirun-codir2me-cdn-delete' => 'delete',
				'codirun-r2-media-static-cdn_page_codirun-codir2me-cdn-scanner' => 'scanner',
				'codirun-r2-media-static-cdn_page_codirun-codir2me-cdn-license' => 'license',
			);

			if ( isset( $page_to_tab_map[ $page_id ] ) ) {
				return $page_to_tab_map[ $page_id ];
			}
		}

		// TERCEIRO: Verificar pelo parâmetro page na URL.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab navigation doesn't require nonce verification
		if ( isset( $_GET['page'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab parameter is safely validated against whitelist
			$page = sanitize_text_field( wp_unslash( $_GET['page'] ) );

			$page_to_tab_map = array(
				'codirun-codir2me-cdn'              => 'general',
				'codirun-codir2me-cdn-static'       => 'static',
				'codirun-codir2me-cdn-images'       => 'images',
				'codirun-codir2me-cdn-optimization' => 'optimization',
				'codirun-codir2me-cdn-reprocess'    => 'reprocess',
				'codirun-codir2me-cdn-maintenance'  => 'maintenance',
				'codirun-codir2me-cdn-delete'       => 'delete',
				'codirun-codir2me-cdn-scanner'      => 'scanner',
				'codirun-codir2me-cdn-license'      => 'license',
			);

			if ( isset( $page_to_tab_map[ $page ] ) ) {
				return $page_to_tab_map[ $page ];
			}
		}

		// QUARTO: Fallback para parâmetro tab (sistema antigo).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Page parameter check for admin highlighting
		if ( isset( $_GET['tab'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Safe page parameter validation
			$requested_tab = sanitize_text_field( wp_unslash( $_GET['tab'] ) );

			$valid_tabs = array(
				'general',
				'static',
				'images',
				'thumbnails',
				'optimization',
				'maintenance',
				'delete',
				'scanner',
				'scanner-r2',
				'reprocess',
				'license',
			);

			if ( in_array( $requested_tab, $valid_tabs, true ) ) {
				return $requested_tab;
			}
		}

		return 'general';
	}

	/**
	 * Chama a função de limpeza de arquivos duplicados
	 */
	public function codir2me_cleanup_duplicate_files() {
		if ( null === $this->maintenance_tab ) {
			// Se por algum motivo a tab de manutenção não estiver inicializada, inicialize-a agora.
			require_once CODIR2ME_CDN_INCLUDES_DIR . '/admin/class-codir2me-admin-ui-maintenance.php';
			$this->maintenance_tab = new CODIR2ME_Admin_UI_Maintenance( $this );
		}

		$this->maintenance_tab->codir2me_cleanup_duplicate_files();
	}

	/**
	 * Adiciona o menu de administração do plugin
	 */
	public function codir2me_add_admin_menu() {
		// Menu principal.
		$main_page = add_menu_page(
			__( 'Codirun R2 Media & Static CDN', 'codirun-codir2me-cdn' ),
			__( 'Codirun R2 Media & Static CDN', 'codirun-codir2me-cdn' ),
			'manage_options',
			'codirun-codir2me-cdn',
			array( $this, 'codir2me_admin_page' ),
			CODIR2ME_CDN_PLUGIN_URL . 'assets/images/icon.png',
			81
		);

		// Submenus para cada aba.
		add_submenu_page(
			'codirun-codir2me-cdn',
			__( 'Configurações Gerais', 'codirun-codir2me-cdn' ),
			__( 'Configurações', 'codirun-codir2me-cdn' ),
			'manage_options',
			'codirun-codir2me-cdn',
			array( $this, 'codir2me_admin_page' )
		);

		add_submenu_page(
			'codirun-codir2me-cdn',
			__( 'Arquivos Estáticos', 'codirun-codir2me-cdn' ),
			__( 'Arquivos Estáticos', 'codirun-codir2me-cdn' ),
			'manage_options',
			'codirun-codir2me-cdn-static',
			array( $this, 'codir2me_admin_page_static' )
		);

		add_submenu_page(
			'codirun-codir2me-cdn',
			__( 'Imagens', 'codirun-codir2me-cdn' ),
			__( 'Imagens', 'codirun-codir2me-cdn' ),
			'manage_options',
			'codirun-codir2me-cdn-images',
			array( $this, 'codir2me_admin_page_images' )
		);

		add_submenu_page(
			'codirun-codir2me-cdn',
			__( 'Otimização de Imagens', 'codirun-codir2me-cdn' ),
			__( 'Otimização', 'codirun-codir2me-cdn' ),
			'manage_options',
			'codirun-codir2me-cdn-optimization',
			array( $this, 'codir2me_admin_page_optimization' )
		);

		add_submenu_page(
			'codirun-codir2me-cdn',
			__( 'Reprocessar Imagens', 'codirun-codir2me-cdn' ),
			__( 'Reprocessar Imagens', 'codirun-codir2me-cdn' ),
			'manage_options',
			'codirun-codir2me-cdn-reprocess',
			array( $this, 'codir2me_admin_page_reprocess' )
		);

		add_submenu_page(
			'codirun-codir2me-cdn',
			__( 'Manutenção', 'codirun-codir2me-cdn' ),
			__( 'Manutenção', 'codirun-codir2me-cdn' ),
			'manage_options',
			'codirun-codir2me-cdn-maintenance',
			array( $this, 'codir2me_admin_page_maintenance' )
		);

		add_submenu_page(
			'codirun-codir2me-cdn',
			__( 'Escaneamento R2', 'codirun-codir2me-cdn' ),
			__( 'Escaneamento R2', 'codirun-codir2me-cdn' ),
			'manage_options',
			'codirun-codir2me-cdn-scanner',
			array( $this, 'codir2me_admin_page_scanner' )
		);

		add_submenu_page(
			'codirun-codir2me-cdn',
			__( 'Excluir Arquivos', 'codirun-codir2me-cdn' ),
			__( 'Excluir Arquivos', 'codirun-codir2me-cdn' ),
			'manage_options',
			'codirun-codir2me-cdn-delete',
			array( $this, 'codir2me_admin_page_delete' )
		);

		add_submenu_page(
			'codirun-codir2me-cdn',
			__( 'Licença', 'codirun-codir2me-cdn' ),
			__( 'Licença', 'codirun-codir2me-cdn' ),
			'manage_options',
			'codirun-codir2me-cdn-license',
			array( $this, 'codir2me_admin_page_license' )
		);

		// Hook para destacar o submenu correto.
		add_action( 'admin_head', array( $this, 'codir2me_highlight_current_submenu' ) );
	}

	/**
	 * Função para destacar o submenu atual corretamente
	 */
	public function codir2me_highlight_current_submenu() {
		global $codir2me_submenu_file, $plugin_page;

		// Verificar se estamos em uma página do plugin.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Safe parameter validation
		if ( isset( $_GET['page'] ) && strpos( sanitize_text_field( wp_unslash( $_GET['page'] ) ), 'codirun-codir2me-cdn' ) !== false ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Specific case where nonce not required
			$current_page = sanitize_text_field( wp_unslash( $_GET['page'] ) );

			// Mapear as páginas para destacar o submenu correto.
			$submenu_mapping = array(
				'codirun-codir2me-cdn-static'       => 'codirun-codir2me-cdn-static',
				'codirun-codir2me-cdn-images'       => 'codirun-codir2me-cdn-images',
				'codirun-codir2me-cdn-optimization' => 'codirun-codir2me-cdn-optimization',
				'codirun-codir2me-cdn-reprocess'    => 'codirun-codir2me-cdn-reprocess',
				'codirun-codir2me-cdn-maintenance'  => 'codirun-codir2me-cdn-maintenance',
				'codirun-codir2me-cdn-scanner'      => 'codirun-codir2me-cdn-scanner',
				'codirun-codir2me-cdn-delete'       => 'codirun-codir2me-cdn-delete',
				'codirun-codir2me-cdn-license'      => 'codirun-codir2me-cdn-license',
			);

			if ( isset( $submenu_mapping[ $current_page ] ) ) {
				$codir2me_submenu_file = $submenu_mapping[ $current_page ];
			}
		}
	}

	/**
	 * Renderiza a página administrativa da aba de arquivos estáticos
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function codir2me_admin_page_static() {
		$this->active_tab = 'static';
		$this->codir2me_admin_page();
	}

	/**
	 * Renderiza a página administrativa da aba de imagens
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function codir2me_admin_page_images() {
		$this->active_tab = 'images';
		$this->codir2me_admin_page();
	}

	/**
	 * Renderiza a página administrativa da aba de otimização
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function codir2me_admin_page_optimization() {
		$this->active_tab = 'optimization';
		$this->codir2me_admin_page();
	}

	/**
	 * Renderiza a página administrativa da aba de reprocessamento
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function codir2me_admin_page_reprocess() {
		$this->active_tab = 'reprocess';
		$this->codir2me_admin_page();
	}

	/**
	 * Renderiza a página administrativa da aba de manutenção
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function codir2me_admin_page_maintenance() {
		$this->active_tab = 'maintenance';
		$this->codir2me_admin_page();
	}

	/**
	 * Renderiza a página administrativa da aba de escaneamento
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function codir2me_admin_page_scanner() {
		$this->active_tab = 'scanner';
		$this->codir2me_admin_page();
	}

	/**
	 * Renderiza a página administrativa da aba de exclusão
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function codir2me_admin_page_delete() {
		$this->active_tab = 'delete';
		$this->codir2me_admin_page();
	}

	/**
	 * Renderiza a página administrativa da aba de licença
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function codir2me_admin_page_license() {
		$this->active_tab = 'license';
		$this->codir2me_admin_page();
	}

	/**
	 * Adiciona link de configurações na lista de plugins
	 *
	 * @param array $links Array de links existentes.
	 * @return array Array de links modificado.
	 */
	public function codir2me_add_settings_link( $links ) {
		$settings_link = '<a href="' . admin_url( 'admin.php?page=codirun-codir2me-cdn' ) . '">' . __( 'Configurações', 'codirun-codir2me-cdn' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Ação para baixar o arquivo de log
	 */
	public function codir2me_download_log_action() {
		// Verificar permissões.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Acesso negado', 'codirun-codir2me-cdn' ) );
		}

		// Verificar nonce.
		check_admin_referer( 'codir2me_download_log' );

		// Inicializar WP_Filesystem.
		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();

		$log_file = CODIR2ME_CDN_LOGS_DIR . 'debug.log';

		if ( $wp_filesystem->exists( $log_file ) ) {
			$file_content = $wp_filesystem->get_contents( $log_file );

			if ( false === $file_content ) {
				$redirect_url = $this->ui->codir2me_add_nonce_to_url(
					admin_url( 'admin.php?page=codirun-codir2me-cdn&log_error=1' ),
					'codir2me_admin_notices'
				);
				wp_safe_redirect( $redirect_url );
				exit;
			}

			// Configurar headers para download.
			header( 'Content-Description: File Transfer' );
			header( 'Content-Type: text/plain' );
			header( 'Content-Disposition: attachment; filename="codir2me-cdn-debug-' . gmdate( 'Y-m-d' ) . '.log"' );
			header( 'Expires: 0' );
			header( 'Cache-Control: must-revalidate' );
			header( 'Pragma: public' );
			header( 'Content-Length: ' . strlen( $file_content ) );

			// Enviar o conteúdo do arquivo.
			echo esc_html( $file_content );
			exit;
		} else {
			$redirect_url = $this->ui->codir2me_add_nonce_to_url(
				admin_url( 'admin.php?page=codirun-codir2me-cdn&log_error=1' ),
				'codir2me_admin_notices'
			);
			wp_safe_redirect( $redirect_url );
			exit;
		}
	}

	/**
	 * Ação para limpar o arquivo de log
	 */
	public function codir2me_clear_log_action() {
		// Verificar permissões primeiro.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Acesso negado', 'codirun-codir2me-cdn' ) );
		}

		// Verificação de nonce mais flexível.
		$nonce_verified = false;

		// Verificar nonce via GET (para links).
		if ( isset( $_GET['_wpnonce'] ) ) {
			$nonce          = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) );
			$nonce_verified = wp_verify_nonce( $nonce, 'codir2me_clear_log' );
		}

		// Verificar nonce via POST (para formulários).
		if ( ! $nonce_verified && isset( $_POST['_wpnonce'] ) ) {
			$nonce          = sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) );
			$nonce_verified = wp_verify_nonce( $nonce, 'codir2me_clear_log' );
		}

		// Se ainda não verificado, usar a função padrão como fallback.
		if ( ! $nonce_verified ) {
			check_admin_referer( 'codir2me_clear_log' );
		}

		// Log da ação.
		if ( function_exists( 'codir2me_cdn_log' ) ) {
			codir2me_cdn_log( __( 'Log limpo por solicitação do administrador', 'codirun-codir2me-cdn' ), 'info' );
		}

		// Limpar log usando a função global corrigida.
		codir2me_cdn_clear_logs();

		// CORREÇÃO: Redirecionar para a página correta.
		$redirect_url = add_query_arg(
			array(
				'page'        => 'codirun-codir2me-cdn',
				'log_cleared' => '1',
				'_wpnonce'    => wp_create_nonce( 'codir2me_admin_notices' ),
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Registra as configurações do plugin
	 */
	public function codir2me_register_settings() {
		// Registrar configurações de conexão separadamente.
		$this->codir2me_register_connection_settings();

		// Configurações de depuração.
		register_setting(
			'codir2me_general_settings',
			'codir2me_debug_mode',
			array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => array( $this, 'codir2me_sanitize_boolean_option' ),
			)
		);

		register_setting(
			'codir2me_general_settings',
			'codir2me_clean_logs_on_deactivate',
			array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => array( $this, 'codir2me_sanitize_boolean_option' ),
			)
		);

		// Registrar configuração para desabilitar CDN no admin.
		register_setting(
			'codir2me_images_settings',
			'codir2me_disable_cdn_admin',
			array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => array( $this, 'codir2me_sanitize_checkbox' ),
			)
		);

		// Configurações de arquivos estáticos.
		register_setting(
			'codir2me_static_settings',
			'codir2me_is_cdn_active',
			array(
				'sanitize_callback' => array( $this, 'codir2me_sanitize_boolean_option' ),
			)
		);
		register_setting(
			'codir2me_static_settings',
			'codir2me_batch_size',
			array(
				'default'           => 50,
				'sanitize_callback' => 'absint',
			)
		);

		// Configurações de imagens.
		register_setting(
			'codir2me_images_settings',
			'codir2me_is_images_cdn_active',
			array(
				'sanitize_callback' => array( $this, 'codir2me_sanitize_boolean_option' ),
			)
		);
		register_setting(
			'codir2me_images_settings',
			'codir2me_images_batch_size',
			array(
				'default'           => 20,
				'sanitize_callback' => 'absint',
			)
		);
		register_setting(
			'codir2me_images_settings',
			'codir2me_thumbnail_option',
			array(
				'default'           => 'all',
				'sanitize_callback' => 'sanitize_text_field',
			)
		);
		register_setting(
			'codir2me_images_settings',
			'codir2me_selected_thumbnails',
			array(
				'default'           => array(),
				'sanitize_callback' => array( $this, 'codir2me_sanitize_selected_thumbnails' ),
			)
		);

		// Novas configurações para upload automático.
		register_setting(
			'codir2me_static_settings',
			'codir2me_upload_on_update',
			array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => array( $this, 'codir2me_sanitize_boolean_option' ),
			)
		);

		register_setting(
			'codir2me_static_settings',
			'codir2me_enable_versioning',
			array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => array( $this, 'codir2me_sanitize_boolean_option' ),
			)
		);

		// Nova configuração para upload automático de miniaturas.
		// Modificando para usar booleano explícito.
		register_setting(
			'codir2me_images_settings',
			'codir2me_auto_upload_thumbnails',
			array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => array( $this, 'codir2me_sanitize_boolean_option' ),
			)
		);

		// Configurações de otimização de imagens.
		register_setting(
			'codir2me_optimization_settings',
			'codir2me_image_optimization_options',
			array(
				'type'              => 'array',
				'default'           => array(
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
				),
				'sanitize_callback' => array( $this, 'codir2me_sanitize_optimization_options' ),
			)
		);
	}

	/**
	 * Função de sanitização para checkbox (se não existir ainda)
	 *
	 * @param mixed $input Valor a ser sanitizado.
	 * @return bool
	 */
	public function codir2me_sanitize_checkbox( $input ) {
		return (bool) $input;
	}

	/**
	 * Registra configurações de conexão com o R2
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function codir2me_register_connection_settings() {
		// Registrar group específico para configurações de conexão.
		register_setting(
			'codir2me_connection_settings',
			'codir2me_access_key',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
				'show_in_rest'      => false,
			)
		);

		register_setting(
			'codir2me_connection_settings',
			'codir2me_secret_key',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
				'show_in_rest'      => false,
			)
		);

		register_setting(
			'codir2me_connection_settings',
			'codir2me_bucket',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
				'show_in_rest'      => false,
			)
		);

		register_setting(
			'codir2me_connection_settings',
			'codir2me_endpoint',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'esc_url_raw',
				'default'           => '',
				'show_in_rest'      => false,
			)
		);

		register_setting(
			'codir2me_connection_settings',
			'codir2me_cdn_url',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'esc_url_raw',
				'default'           => '',
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Função AJAX para reenvio de arquivos
	 */
	public function codir2me_ajax_resync_file() {
		// Verificar nonce.
		if ( ! check_ajax_referer( 'codir2me_resync_file_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Erro de segurança. Por favor, atualize a página.', 'codirun-codir2me-cdn' ) ) );
		}

		// Verificar permissões.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permissão negada.', 'codirun-codir2me-cdn' ) ) );
		}

		// Obter caminho do arquivo.
		$file_path = isset( $_POST['file_path'] ) ? sanitize_text_field( wp_unslash( $_POST['file_path'] ) ) : '';
		if ( empty( $file_path ) ) {
			wp_send_json_error( array( 'message' => __( 'Caminho de arquivo inválido.', 'codirun-codir2me-cdn' ) ) );
		}

		// Determinar o caminho absoluto usando funções WordPress.
		$absolute_path = '';

		// 1. Tentar uploads primeiro (mais comum).
		$upload_dir           = wp_upload_dir();
		$upload_baseurl       = $upload_dir['baseurl'];
		$upload_path_from_url = str_replace( site_url(), '', $upload_baseurl );
		$upload_path_from_url = trim( $upload_path_from_url, '/' );

		if ( strpos( $file_path, $upload_path_from_url ) === 0 ) {
			// É um arquivo de upload.
			$relative_upload_path = str_replace( $upload_path_from_url . '/', '', $file_path );
			$absolute_path        = wp_normalize_path( $upload_dir['basedir'] . '/' . $relative_upload_path );
		} elseif ( strpos( $file_path, 'uploads/' ) === 0 ) {
			// Fallback para uploads/ (compatibilidade).
			$relative_upload_path = str_replace( 'uploads/', '', $file_path );
			$absolute_path        = wp_normalize_path( $upload_dir['basedir'] . '/' . $relative_upload_path );
		} else {
			// 2. Para outros arquivos (themes, plugins, etc.).
			$content_url           = content_url();
			$content_path_from_url = str_replace( site_url(), '', $content_url );
			$content_path_from_url = trim( $content_path_from_url, '/' );

			// Verificar se o arquivo está dentro do content.
			if ( strpos( $file_path, $content_path_from_url ) === 0 ) {
				// Caminho já inclui a estrutura completa.
				$relative_content_path = str_replace( $content_path_from_url . '/', '', $file_path );
				$absolute_path         = wp_normalize_path( WP_CONTENT_DIR . '/' . $relative_content_path );
			} elseif ( strpos( $file_path, 'wp-content/' ) === 0 ) {
				// Fallback para wp-content/ (compatibilidade).
				$relative_content_path = str_replace( 'wp-content/', '', $file_path );
				$absolute_path         = wp_normalize_path( WP_CONTENT_DIR . '/' . $relative_content_path );
			} elseif ( strpos( $file_path, 'themes/' ) === 0 || strpos( $file_path, 'plugins/' ) === 0 ) {
				// Caminhos relativos diretos.
				$absolute_path = wp_normalize_path( WP_CONTENT_DIR . '/' . $file_path );
			} else {
				// Último recurso: usar o caminho como fornecido.
				$absolute_path = wp_normalize_path( get_home_path() . $file_path );
			}
		}

		// Verificar se conseguimos determinar um caminho válido.
		if ( empty( $absolute_path ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Não foi possível determinar o caminho do arquivo.', 'codirun-codir2me-cdn' ),
				)
			);
			return;
		}

		// Verificar se o arquivo existe no servidor usando WP_Filesystem.
		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once wp_normalize_path( get_home_path() . 'wp-admin/includes/file.php' );
		}
		WP_Filesystem();

		if ( ! $wp_filesystem->exists( $absolute_path ) ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						// translators: %s será substituído pelo nome ou caminho do arquivo não encontrado.
						__( 'Arquivo não encontrado: %s', 'codirun-codir2me-cdn' ),
						$file_path
					),
				)
			);
			return;
		}

		try {
			// Obter o uploader.
			$uploader = $this->plugin->codir2me_get_uploader();
			if ( ! $uploader ) {
				wp_send_json_error( array( 'message' => __( 'Uploader não disponível.', 'codirun-codir2me-cdn' ) ) );
			}

			// Fazer upload do arquivo.
			$uploader->codir2me_upload_file( $absolute_path, $file_path );

			// Atualizar timestamp.
			$upload_timestamps               = get_option( 'codir2me_file_upload_timestamps', array() );
			$upload_timestamps[ $file_path ] = time();
			update_option( 'codir2me_file_upload_timestamps', $upload_timestamps );

			// Registrar no log, se disponível.
			if ( function_exists( 'codir2me_cdn_log' ) ) {
				codir2me_cdn_log( __( 'Arquivo reenviado manualmente:', 'codirun-codir2me-cdn' ) . " {$file_path}", 'info' );
			}

			// Retornar sucesso com a nova data.
			wp_send_json_success(
				array(
					'date' => date_i18n( 'd/m/Y H:i', $upload_timestamps[ $file_path ] ),
				)
			);

		} catch ( Exception $e ) {
			// Registrar erro no log, se disponível.
			if ( function_exists( 'codir2me_cdn_log' ) ) {
				codir2me_cdn_log( __( 'Erro ao reenviar arquivo', 'codirun-codir2me-cdn' ) . " {$file_path}: " . $e->getMessage(), 'error' );
			}
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}

		wp_die();
	}

	/**
	 * Adicionar este método para sanitização adequada de booleanos
	 *
	 * @param mixed $input Valor de entrada.
	 * @return bool Valor booleano sanitizado.
	 */
	public function codir2me_sanitize_boolean_option( $input ) {
		// Certifica-se de que o valor é convertido corretamente para booleano.
		return (bool) $input;
	}

	/**
	 * Sanitiza os tamanhos de miniatura selecionados
	 *
	 * @param mixed $input Array de entrada.
	 * @return array Array sanitizado.
	 */
	public function codir2me_sanitize_selected_thumbnails( $input ) {
		if ( ! is_array( $input ) ) {
			return array();
		}

		$sanitized = array();
		foreach ( $input as $value ) {
			$sanitized[] = sanitize_text_field( $value );
		}

		return $sanitized;
	}

	/**
	 * Sanitiza as opções de otimização
	 * Versão corrigida para preservar melhor as configurações existentes
	 *
	 * @param array $input Valores de entrada.
	 * @return array Valores sanitizados.
	 */
	public function codir2me_sanitize_optimization_options( $input ) {
		// Se receber null, recuperar as configurações existentes (não usar padrões).
		if ( null === $input ) {
			$existing_options = get_option( 'codir2me_image_optimization_options', array() );
			if ( ! empty( $existing_options ) && is_array( $existing_options ) ) {
				return $existing_options;
			}

			// Se realmente não houver configurações existentes, usar padrões.
			return array(
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
		}

		// Obter as configurações existentes para mesclá-las com os novos valores.
		$existing_options = get_option( 'codir2me_image_optimization_options', array() );

		// Garantir que $existing_options seja um array.
		if ( ! is_array( $existing_options ) ) {
			$existing_options = array();
		}

		// Mesclar os novos valores com os existentes, mantendo os valores existentes como fallback.
		$output = wp_parse_args( $input, $existing_options );

		// Se os checkboxes não estiverem marcados, eles não estarão presentes no POST.
		// Portanto, precisamos verificá-los explicitamente.
		$output['enable_optimization']    = isset( $input['enable_optimization'] ) ? (bool) $input['enable_optimization'] : false;
		$output['enable_webp_conversion'] = isset( $input['enable_webp_conversion'] ) ? (bool) $input['enable_webp_conversion'] : false;
		$output['enable_avif_conversion'] = isset( $input['enable_avif_conversion'] ) ? (bool) $input['enable_avif_conversion'] : false;
		$output['keep_original']          = isset( $input['keep_original'] ) ? (bool) $input['keep_original'] : true;

		// Sincronizar com os formatos do painel de prioridade de formatos.
		update_option( 'codir2me_format_webp_enabled', $output['enable_webp_conversion'] );
		update_option( 'codir2me_format_avif_enabled', $output['enable_avif_conversion'] );

		// Validar nível de otimização.
		$valid_levels = array( 'light', 'balanced', 'aggressive' );
		if ( ! in_array( $output['optimization_level'], $valid_levels, true ) ) {
			$output['optimization_level'] = 'balanced';
		}

		// Validar qualidade JPEG (1-100).
		$output['jpeg_quality'] = intval( $output['jpeg_quality'] );
		$output['jpeg_quality'] = max( 1, min( 100, $output['jpeg_quality'] ) );

		// Validar compressão PNG (0-9).
		$output['png_compression'] = intval( $output['png_compression'] );
		$output['png_compression'] = max( 0, min( 9, $output['png_compression'] ) );

		// Validar qualidade WebP (1-100).
		$output['webp_quality'] = intval( $output['webp_quality'] );
		$output['webp_quality'] = max( 1, min( 100, $output['webp_quality'] ) );

		// Validar qualidade AVIF (1-100).
		$output['avif_quality'] = intval( $output['avif_quality'] );
		$output['avif_quality'] = max( 1, min( 100, $output['avif_quality'] ) );

		// Validar elemento HTML.
		$valid_elements = array( 'picture', 'img' );
		if ( ! in_array( $output['html_element'], $valid_elements, true ) ) {
			$output['html_element'] = 'picture';
		}

		return $output;
	}

	/**
	 * Renderiza a página de administração do plugin
	 * Versão corrigida com verificação de nonce
	 */
	public function codir2me_admin_page() {
		// Verificar capacidades.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Verificar se o AWS SDK está disponível.
		$asyncaws_sdk_available = false;
		if ( file_exists( CODIR2ME_CDN_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
			require_once CODIR2ME_CDN_PLUGIN_DIR . 'vendor/autoload.php';
			$asyncaws_sdk_available = class_exists( 'AsyncAws\S3\S3Client' );
		}

		// Definir a guia ativa - se não foi definida pelos métodos específicos, usar padrão ou parâmetro GET.
		if ( empty( $this->active_tab ) ) {
			$this->active_tab = 'general';

			// Verificação de nonce para parâmetros GET (manter compatibilidade com links diretos).
			$nonce_verified = false;
			if ( isset( $_GET['_wpnonce'] ) ) {
				$nonce          = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) );
				$nonce_verified = wp_verify_nonce( $nonce, 'codir2me_admin_tab' );
			}

			// Configuração para permitir redirecionamento interno sem nonce.
			$http_referer         = isset( $_SERVER['HTTP_REFERER'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
			$is_internal_redirect = ! empty( $http_referer ) && 0 === strpos( $http_referer, admin_url() );

			if ( isset( $_GET['tab'] ) && ( $nonce_verified || $is_internal_redirect ) ) {
				$this->active_tab = sanitize_text_field( wp_unslash( $_GET['tab'] ) );
			}
		}

		// Verificar licença para recursos premium.
		$license_active = 'active' === get_option( 'codir2me_license_status', 'inactive' );
		$is_premium_tab = isset( $this->premium_features[ $this->active_tab ] ) && $this->premium_features[ $this->active_tab ];

		// Chamar o método de renderização de UI com informação da licença.
		$this->ui->codir2me_render_admin_page( $asyncaws_sdk_available, $this->active_tab, $license_active, $is_premium_tab );
	}

	/**
	 * Renderiza a página de administração
	 *
	 * @param bool   $asyncaws_sdk_available SDK disponível.
	 * @param string $active_tab Aba ativa.
	 */
	public function codir2me_render_admin_page( $asyncaws_sdk_available, $active_tab ) {
		// Verificar acesso à aba selecionada.
		$active_tab = apply_filters( 'codir2me_admin_tab_access', $active_tab );

		// Continuar com a renderização normal.
		$this->ui->codir2me_render_admin_page( $asyncaws_sdk_available, $active_tab );
	}

	/**
	 * Função corrigida para escanear arquivos estáticos
	 */
	public function codir2me_scan_files_action() {
		// Verificar nonce.
		if ( ! isset( $_POST['codir2me_scan_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['codir2me_scan_nonce'] ) ), 'codir2me_scan_files' ) ) {
			if ( function_exists( 'codir2me_cdn_log' ) ) {
				codir2me_cdn_log( __( 'Falha na verificação do nonce para escaneamento de arquivos estáticos', 'codirun-codir2me-cdn' ), 'error' );
			}
			wp_safe_redirect( admin_url( 'admin.php?page=codirun-codir2me-cdn-static&nonce_error=1' ) );
			exit;
		}

		// Verificar permissões.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Acesso negado', 'codirun-codir2me-cdn' ) );
		}

		$assets_handler = $this->plugin->codir2me_get_assets_handler();

		// Escanear arquivos.
		$assets_handler->codir2me_scan_files();

		// Criar URL com nonce e manter a aba correta.
		$redirect_url = add_query_arg(
			array(
				'page'          => 'codirun-codir2me-cdn-static',
				'auto_continue' => '1',
				'_wpnonce'      => wp_create_nonce( 'codir2me_cdn_batch_upload' ),
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Função corrigida para processar lotes de arquivos estáticos
	 */
	public function codir2me_process_batch_action() {
		// Verificar nonce.
		if ( ! isset( $_POST['codir2me_batch_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['codir2me_batch_nonce'] ) ), 'codir2me_process_batch' ) ) {
			if ( function_exists( 'codir2me_cdn_log' ) ) {
				codir2me_cdn_log( __( 'Falha na verificação do nonce para processamento de lote de arquivos estáticos', 'codirun-codir2me-cdn' ), 'error' );
			}
			wp_safe_redirect( admin_url( 'admin.php?page=codirun-codir2me-cdn-static&nonce_error=1' ) );
			exit;
		}

		// Verificar permissões.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Acesso negado', 'codirun-codir2me-cdn' ) );
		}

		$assets_handler = $this->plugin->codir2me_get_assets_handler();
		$uploader       = $this->plugin->codir2me_get_uploader();
		$batch_size     = $this->plugin->codir2me_get_batch_size();

		try {
			// Processar um lote.
			$result = $assets_handler->codir2me_process_batch( $uploader, $batch_size );

			if ( $result['complete'] ) {
				// Processo concluído.
				$redirect_url = add_query_arg(
					array(
						'page'            => 'codirun-codir2me-cdn-static',
						'upload_complete' => '1',
						'_wpnonce'        => wp_create_nonce( 'codir2me_cdn_batch_upload' ),
					),
					admin_url( 'admin.php' )
				);
			} else {
				// Redirecionar para processar o próximo lote.
				$redirect_url = add_query_arg(
					array(
						'page'          => 'codirun-codir2me-cdn-static',
						'auto_continue' => '1',
						'_wpnonce'      => wp_create_nonce( 'codir2me_cdn_batch_upload' ),
					),
					admin_url( 'admin.php' )
				);
			}

			wp_safe_redirect( $redirect_url );
		} catch ( Exception $e ) {
			// Em caso de erro, salvar mensagem e redirecionar.
			update_option( 'codir2me_upload_error', $e->getMessage() );

			$redirect_url = add_query_arg(
				array(
					'page'          => 'codirun-codir2me-cdn-static',
					'auto_continue' => '1',
					'_wpnonce'      => wp_create_nonce( 'codir2me_cdn_batch_upload' ),
				),
				admin_url( 'admin.php' )
			);

			wp_safe_redirect( $redirect_url );
		}

		exit;
	}

	/**
	 * Função corrigida para escanear imagens
	 */
	public function codir2me_scan_images_action() {
		// Verificar nonce.
		if ( ! isset( $_POST['codir2me_scan_images_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['codir2me_scan_images_nonce'] ) ), 'codir2me_scan_images' ) ) {
			if ( function_exists( 'codir2me_cdn_log' ) ) {
				codir2me_cdn_log( __( 'Falha na verificação do nonce para escaneamento de imagens', 'codirun-codir2me-cdn' ), 'error' );
			}
			wp_safe_redirect( admin_url( 'admin.php?page=codirun-codir2me-cdn-images&nonce_error=1' ) );
			exit;
		}

		// Verificar permissões.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Acesso negado', 'codirun-codir2me-cdn' ) );
		}

		$images_handler = $this->plugin->codir2me_get_images_handler();

		// Escanear imagens.
		$images_handler->codir2me_scan_images();

		// Criar URL com nonce e manter a aba correta.
		$redirect_url = add_query_arg(
			array(
				'page'                   => 'codirun-codir2me-cdn-images',
				'auto_continue_images'   => '1',
				'_wpnonce_upload_images' => wp_create_nonce( 'codir2me_cdn_batch_upload_images' ),
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Função corrigida para processar lotes de imagens
	 */
	public function codir2me_process_images_batch_action() {
		// Verificar nonce.
		if ( ! isset( $_POST['codir2me_images_batch_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['codir2me_images_batch_nonce'] ) ), 'codir2me_process_images_batch' ) ) {
			if ( function_exists( 'codir2me_cdn_log' ) ) {
				codir2me_cdn_log( __( 'Falha na verificação do nonce para processamento de lote de imagens', 'codirun-codir2me-cdn' ), 'error' );
			}
			wp_safe_redirect( admin_url( 'admin.php?page=codirun-codir2me-cdn-images&nonce_error=1' ) );
			exit;
		}

		// Verificar permissões.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Acesso negado', 'codirun-codir2me-cdn' ) );
		}

		$images_handler = $this->plugin->codir2me_get_images_handler();
		$uploader       = $this->plugin->codir2me_get_uploader();
		$batch_size     = $this->plugin->codir2me_get_batch_size();

		try {
			// Processar um lote.
			$result = $images_handler->codir2me_process_batch( $uploader, $batch_size );

			if ( $result['complete'] ) {
				// Processo concluído.
				$redirect_url = add_query_arg(
					array(
						'page'                   => 'codirun-codir2me-cdn-images',
						'auto_continue_images'   => '1',
						'_wpnonce_upload_images' => wp_create_nonce( 'codir2me_cdn_batch_upload_images' ),
					),
					admin_url( 'admin.php' )
				);
			} else {
				// Redirecionar para processar o próximo lote.
				$redirect_url = add_query_arg(
					array(
						'page'                   => 'codirun-codir2me-cdn-images',
						'auto_continue_images'   => '1',
						'_wpnonce_upload_images' => wp_create_nonce( 'codir2me_cdn_batch_upload_images' ),
					),
					admin_url( 'admin.php' )
				);
			}

			wp_safe_redirect( $redirect_url );
		} catch ( Exception $e ) {
			// Em caso de erro, salvar mensagem e redirecionar.
			update_option( 'codir2me_images_upload_error', $e->getMessage() );

			$redirect_url = add_query_arg(
				array(
					'page'                => 'codirun-codir2me-cdn-images',
					'images_upload_error' => '1',
					'_wpnonce'            => wp_create_nonce( 'codir2me_cdn_batch_images_upload' ),
				),
				admin_url( 'admin.php' )
			);

			wp_safe_redirect( $redirect_url );
		}

		exit;
	}

	/**
	 * Limpa a lista de arquivos enviados
	 */
	public function codir2me_clear_uploaded_files_action() {
		// Verificar nonce com condição mais flexível.
		if ( ! isset( $_POST['codir2me_clear_files_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['codir2me_clear_files_nonce'] ) ), 'codir2me_clear_uploaded_files' ) ) {
			// Adicionar log para depuração.
			if ( function_exists( 'codir2me_cdn_log' ) ) {
				codir2me_cdn_log( __( 'Falha na verificação do nonce para limpeza de arquivos enviados', 'codirun-codir2me-cdn' ), 'error' );
			}

			// Redirecionar para a página com mensagem de erro.
			wp_safe_redirect( admin_url( 'admin.php?page=codirun-codir2me-cdn-static&nonce_error=1' ) );
			exit;
		}

		// Verificar permissões.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Acesso negado', 'codirun-codir2me-cdn' ) );
		}

		// Registrar no log.
		if ( function_exists( 'codir2me_cdn_log' ) ) {
			codir2me_cdn_log( __( 'Limpando lista de arquivos enviados por solicitação do administrador', 'codirun-codir2me-cdn' ), 'info' );
		}

		// Limpar a lista de arquivos enviados.
		update_option( 'codir2me_uploaded_files', array() );

		// Criar nonce para o redirecionamento.
		$nonce = wp_create_nonce( 'codir2me_admin_notices' );

		// Redirecionar de volta para a página de arquivos estáticos.
		wp_safe_redirect( admin_url( 'admin.php?page=codirun-codir2me-cdn-static&files_cleared=1&_wpnonce=' . $nonce ) );
		exit;
	}

	/**
	 * Mostra avisos de upload completo e outras notificações
	 */
	public function codir2me_upload_complete_notice() {
		// Detectar aba baseado no parâmetro page correto.
		$tab = 'general';

		if ( isset( $_GET['page'] ) ) {
			$page = sanitize_text_field( wp_unslash( $_GET['page'] ) );

			// Mapear page para tab - usando apenas os valores corretos.
			switch ( $page ) {
				case 'codirun-codir2me-cdn':
					$tab = 'general';
					break;
				case 'codirun-codir2me-cdn-static':
					$tab = 'static';
					break;
				case 'codirun-codir2me-cdn-images':
					$tab = 'images';
					break;
				case 'codirun-codir2me-cdn-optimization':
					$tab = 'optimization';
					break;
				case 'codirun-codir2me-cdn-reprocess':
					$tab = 'reprocess';
					break;
				case 'codirun-codir2me-cdn-maintenance':
					$tab = 'maintenance';
					break;
				case 'codirun-codir2me-cdn-delete':
					$tab = 'delete';
					break;
				case 'codirun-codir2me-cdn-scanner':
					$tab = 'scanner';
					break;
				case 'codirun-codir2me-cdn-license':
					$tab = 'license';
					break;
			}
		}

		// Verificação de nonce.
		$nonce_verified = false;
		if ( isset( $_GET['_wpnonce'] ) ) {
			$nonce          = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) );
			$nonce_verified = wp_verify_nonce( $nonce, 'codir2me_admin_notices' );
		}

		// Verificação de permissão do usuário.
		$user_can_manage = current_user_can( 'manage_options' );

		// Permitir exibir notificações se: usuário tem permissão E (nonce válido OU está numa página do plugin).
		$is_plugin_page   = isset( $_GET['page'] ) && strpos( sanitize_text_field( wp_unslash( $_GET['page'] ) ), 'codirun-codir2me-cdn' ) === 0;
		$can_show_notices = $user_can_manage && ( $nonce_verified || $is_plugin_page );

		if ( $can_show_notices ) {
			// Para logs - na aba general.
			if ( isset( $_GET['log_cleared'] ) && '1' === $_GET['log_cleared'] && 'general' === $tab ) {
				?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Arquivo de log limpo com sucesso!', 'codirun-codir2me-cdn' ); ?></p>
				</div>
				<?php
			}

			if ( isset( $_GET['log_error'] ) && '1' === $_GET['log_error'] && 'general' === $tab ) {
				?>
				<div class="notice notice-error is-dismissible">
					<p><?php esc_html_e( 'O arquivo de log não foi encontrado.', 'codirun-codir2me-cdn' ); ?></p>
				</div>
				<?php
			}

			// Para arquivos estáticos - na aba static.
			if ( isset( $_GET['upload_complete'] ) && '1' === $_GET['upload_complete'] && 'static' === $tab ) {
				?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Upload de arquivos estáticos concluído com sucesso! Todos os arquivos foram enviados para o Cloudflare R2.', 'codirun-codir2me-cdn' ); ?></p>
				</div>
				<?php
			}

			if ( isset( $_GET['upload_error'] ) && '1' === $_GET['upload_error'] && 'static' === $tab ) {
				$error_message = get_option( 'codir2me_upload_error', __( 'Erro desconhecido', 'codirun-codir2me-cdn' ) );
				?>
				<div class="notice notice-error is-dismissible">
					<p>
					<?php
					/* translators: %s: mensagem de erro */
					printf( esc_html__( 'Erro ao enviar arquivos estáticos: %s', 'codirun-codir2me-cdn' ), esc_html( $error_message ) );
					?>
					</p>
				</div>
				<?php
				delete_option( 'codir2me_upload_error' );
			}

			// Para imagens - na aba images.
			if ( isset( $_GET['upload_complete'] ) && '1' === $_GET['upload_complete'] && 'images' === $tab ) {
				?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Upload de imagens concluído com sucesso! Todas as imagens foram enviadas para o Cloudflare R2.', 'codirun-codir2me-cdn' ); ?></p>
				</div>
				<?php
			}

			if ( isset( $_GET['upload_error'] ) && '1' === $_GET['upload_error'] && 'images' === $tab ) {
				$error_message = get_option( 'codir2me_images_upload_error', __( 'Erro desconhecido', 'codirun-codir2me-cdn' ) );
				?>
				<div class="notice notice-error is-dismissible">
					<p>
					<?php
						/* translators: %s: mensagem de erro */
						printf( esc_html__( 'Erro ao enviar imagens: %s', 'codirun-codir2me-cdn' ), esc_html( $error_message ) );
					?>
					</p>
				</div>
				<?php
				delete_option( 'codir2me_images_upload_error' );
			}

			// Para cache de miniaturas.
			if ( isset( $_GET['cache_cleared'] ) && '1' === $_GET['cache_cleared'] && 'images' === $tab ) {
				?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Estatísticas de miniaturas recalculadas com sucesso! Os dados agora estão atualizados.', 'codirun-codir2me-cdn' ); ?></p>
				</div>
				<?php
			}

			// Para notificação de auto upload.
			if ( isset( $_GET['auto_continue'] ) && '1' === $_GET['auto_continue'] && isset( $_GET['_wpnonce_upload'] ) ) {
				$upload_nonce = sanitize_text_field( wp_unslash( $_GET['_wpnonce_upload'] ) );
				if ( wp_verify_nonce( $upload_nonce, 'codir2me_cdn_batch_upload' ) ) {
					?>
					<div class="notice notice-info is-dismissible">
						<p><?php esc_html_e( 'Upload em lotes iniciado automaticamente. Aguarde...', 'codirun-codir2me-cdn' ); ?></p>
					</div>
					<?php
				}
			}

			// Para notificação de auto upload de imagens.
			if ( isset( $_GET['auto_continue_images'] ) && '1' === $_GET['auto_continue_images'] && isset( $_GET['_wpnonce_upload_images'] ) ) {
				$images_nonce = sanitize_text_field( wp_unslash( $_GET['_wpnonce_upload_images'] ) );
				if ( wp_verify_nonce( $images_nonce, 'codir2me_cdn_batch_upload_images' ) ) {
					?>
					<div class="notice notice-info is-dismissible">
						<p><?php esc_html_e( 'Upload de imagens em lotes iniciado automaticamente. Aguarde...', 'codirun-codir2me-cdn' ); ?></p>
					</div>
					<?php
				}
			}

			// Para notificação de arquivos limpos.
			if ( isset( $_GET['files_cleared'] ) && '1' === $_GET['files_cleared'] && 'static' === $tab ) {
				?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Lista de arquivos enviados limpa com sucesso!', 'codirun-codir2me-cdn' ); ?></p>
				</div>
				<?php
			}

			// Para erros de nonce.
			if ( isset( $_GET['nonce_error'] ) && '1' === $_GET['nonce_error'] ) {
				?>
				<div class="notice notice-error is-dismissible">
					<p><?php esc_html_e( 'Erro de segurança. Por favor, tente novamente.', 'codirun-codir2me-cdn' ); ?></p>
				</div>
				<?php
			}

			// Para notificação de exclusão automática.
			if ( isset( $_GET['auto_delete_saved'] ) && '1' === $_GET['auto_delete_saved'] && 'delete' === $tab ) {
				?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Configurações de exclusão automática salvas com sucesso!', 'codirun-codir2me-cdn' ); ?></p>
				</div>
				<?php
			}
		}
	}

	/**
	 * Obtém o status do processo de upload
	 *
	 * @param string $type Tipo de upload (static ou images).
	 * @return array Status do upload.
	 */
	public function codir2me_get_upload_status( $type = 'static' ) {
		$option_name = ( 'static' === $type ) ? 'codir2me_upload_status' : 'codir2me_images_upload_status';
		$status      = get_option( $option_name, array() );

		$defaults = array(
			'current_batch'   => 0,
			'total_batches'   => 0,
			'total_files'     => 0,
			'processed_files' => 0,
			'start_time'      => 0,
		);

		return wp_parse_args( $status, $defaults );
	}

	/**
	 * Função corrigida para cancelar upload
	 */
	public function codir2me_cancel_upload_action() {
		// Verificar nonce.
		if ( ! isset( $_POST['codir2me_cancel_upload_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['codir2me_cancel_upload_nonce'] ) ), 'codir2me_cancel_upload' ) ) {
			wp_die( esc_html__( 'Verificação de segurança falhou', 'codirun-codir2me-cdn' ) );
		}

		// Verificar permissões.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Acesso negado', 'codirun-codir2me-cdn' ) );
		}

		// Verificar qual tipo de upload cancelar.
		$upload_type = isset( $_POST['upload_type'] ) ? sanitize_text_field( wp_unslash( $_POST['upload_type'] ) ) : 'static';

		if ( 'static' === $upload_type ) {
			// Limpar dados de arquivos estáticos.
			delete_option( 'codir2me_pending_files' );
			delete_option( 'codir2me_upload_status' );
			delete_option( 'codir2me_upload_error' );

			// Redirecionar para aba static.
			$redirect_url = add_query_arg(
				array(
					'page'            => 'codirun-codir2me-cdn-static',
					'upload_canceled' => '1',
					'_wpnonce'        => wp_create_nonce( 'codir2me_cdn_batch_upload' ),
				),
				admin_url( 'admin.php' )
			);
		} else {
			// Limpar dados de imagens.
			delete_option( 'codir2me_pending_images' );
			delete_option( 'codir2me_images_upload_status' );
			delete_option( 'codir2me_images_upload_error' );

			// Redirecionar para aba images.
			$redirect_url = add_query_arg(
				array(
					'page'            => 'codirun-codir2me-cdn-images',
					'upload_canceled' => '1',
					'_wpnonce'        => wp_create_nonce( 'codir2me_cdn_batch_images_upload' ),
				),
				admin_url( 'admin.php' )
			);
		}

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Adicione esta função para registrar o nonce que usaremos
	 */
	public function codir2me_admin_init() {
		// Registrar configurações separadas.
		$this->codir2me_register_settings();

		// Registrar o nonce para processamento em lote de imagens.
		add_action(
			'admin_footer',
			function () {
				wp_nonce_field( 'codir2me_cdn_batch_images_upload', '_wpnonce', false );
			}
		);
	}

	/**
	 * Função corrigida para cancelar exclusão
	 */
	public function codir2me_cancel_delete_action() {
		// Verificar nonce.
		if ( ! isset( $_POST['codir2me_cancel_delete_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['codir2me_cancel_delete_nonce'] ) ), 'codir2me_cancel_delete' ) ) {
			wp_die( esc_html__( 'Verificação de segurança falhou', 'codirun-codir2me-cdn' ) );
		}

		// Verificar permissões.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Acesso negado', 'codirun-codir2me-cdn' ) );
		}

		// Limpar dados de exclusão.
		delete_option( 'codir2me_delete_in_progress' );
		delete_option( 'codir2me_items_to_delete' );
		delete_option( 'codir2me_delete_status' );

		// Cancelar evento cron se estiver sendo executado em segundo plano.
		$timestamp = wp_next_scheduled( 'codir2me_background_deletion_event' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'codir2me_background_deletion_event' );
		}

		// Registrar no log se a função estiver disponível.
		if ( function_exists( 'codir2me_cdn_log' ) ) {
			codir2me_cdn_log( esc_html__( 'Processo de exclusão cancelado pelo usuário', 'codirun-codir2me-cdn' ), 'info' );
		}

		// Redirecionar para aba delete.
		$redirect_url = add_query_arg(
			array(
				'page'            => 'codirun-codir2me-cdn-delete',
				'delete_canceled' => '1',
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Conta o total de imagens no site e estima o tamanho total
	 *
	 * @return array Informações sobre as imagens.
	 */
	public function codir2me_count_total_images() {
		$args = array(
			'post_type'      => 'attachment',
			'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ),
			'post_status'    => 'inherit',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		);

		$query        = new WP_Query( $args );
		$total_images = $query->found_posts;

		// Estimar número total de arquivos de imagem (incluindo tamanhos).
		$total_image_files = 0;
		$sample_size       = min( 20, count( $query->posts ) );
		$total_size_bytes  = 0;

		if ( $sample_size > 0 ) {
			$sample_ids        = array_slice( $query->posts, 0, $sample_size );
			$total_sizes       = 0;
			$sample_size_bytes = 0;

			foreach ( $sample_ids as $attachment_id ) {
				$metadata                  = wp_get_attachment_metadata( $attachment_id );
				$file_path                 = get_attached_file( $attachment_id );
				$file_count_for_attachment = 0;

				// Contar tamanho da imagem original.
				if ( file_exists( $file_path ) ) {
					$sample_size_bytes += filesize( $file_path );
					++$file_count_for_attachment;
				}

				// Contar tamanhos adicionais.
				if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
					$upload_dir = wp_upload_dir();
					$dir_path   = trailingslashit( $upload_dir['basedir'] ) . trailingslashit( dirname( $metadata['file'] ) );

					foreach ( $metadata['sizes'] as $size => $size_info ) {
						$thumb_path = $dir_path . $size_info['file'];
						if ( file_exists( $thumb_path ) ) {
							$sample_size_bytes += filesize( $thumb_path );
							++$file_count_for_attachment;
						}
					}
				}

				$total_sizes += $file_count_for_attachment;
			}

			if ( $sample_size > 0 && $total_sizes > 0 ) {
				$avg_sizes_per_image = $total_sizes / $sample_size;
				$total_image_files   = ceil( $total_images * $avg_sizes_per_image );

				// Estimar o tamanho total baseado na amostra.
				$avg_size_per_file = $sample_size_bytes / $total_sizes;
				$total_size_bytes  = $avg_size_per_file * $total_image_files;
			} else {
				$total_image_files = $total_images;
			}
		} else {
			$total_image_files = $total_images;
		}

		// Converter bytes para MB.
		$total_size_mb = round( $total_size_bytes / ( 1024 * 1024 ), 2 );

		return array(
			'total_images'  => $total_images,
			'total_files'   => $total_image_files,
			'total_size_mb' => $total_size_mb,
		);
	}

	/**
	 * Retorna a instância do plugin
	 *
	 * @return object Instância do plugin.
	 */
	public function codir2me_get_plugin() {
		return $this->plugin;
	}

	/**
	 * Retorna a instância do reprocessador
	 *
	 * @return CODIR2ME_Image_Reprocessor Instância do reprocessador.
	 */
	public function codir2me_get_reprocessor() {
		return $this->reprocessor;
	}

	/**
	 * Retorna a instância do verificador de ambiente
	 *
	 * @return CODIR2ME_Environment_Checker Instância do verificador de ambiente.
	 */
	public function codir2me_get_environment_checker() {
		return $this->environment_checker;
	}
}
