<?php
/**
 * Plugin Name: Codirun R2 Media & Static CDN
 * Plugin URI: https://codirun.com/r2cdn
 * Description: Uploads static files (JS, CSS, SVG, fonts) and images to Cloudflare R2, changing their URLs to point to the CDN.
 * Version: 1.0.4
 * Author: Codirun
 * Author URI: https://codirun.com
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: codirun-codir2me-cdn
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.2
 *
 * @package Codirun_R2_Media_Static_CDN
 */

// Evitar acesso direto ao arquivo.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Verificação de versão do WordPress.
if ( version_compare( get_bloginfo( 'version' ), '6.0', '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			?>
		<div class="notice notice-error">
			<p><?php esc_html_e( 'O plugin Codirun R2 Media & Static CDN requer WordPress 6.0 ou superior. Por favor, atualize o WordPress para usar este plugin.', 'codirun-codir2me-cdn' ); ?></p>
		</div>
			<?php
		}
	);

	// Desativar plugin.
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	deactivate_plugins( plugin_basename( __FILE__ ) );
	return;
}

// Verificação de versão do PHP.
if ( version_compare( PHP_VERSION, '8.2', '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			?>
		<div class="notice notice-error">
			<p><?php esc_html_e( 'O plugin Codirun R2 Media & Static CDN requer PHP 8.2 ou superior. Por favor, entre em contato com seu provedor de hospedagem para atualizar sua versão do PHP.', 'codirun-codir2me-cdn' ); ?></p>
		</div>
			<?php
		}
	);

	// Desativar plugin.
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	deactivate_plugins( plugin_basename( __FILE__ ) );
	return;
}

// Definir constantes do plugin.
define( 'CODIR2ME_CDN_VERSION', '1.0.4' );
define( 'CODIR2ME_CDN_PLUGIN_FILE', __FILE__ );
define( 'CODIR2ME_CDN_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CODIR2ME_CDN_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CODIR2ME_CDN_INCLUDES_DIR', CODIR2ME_CDN_PLUGIN_DIR . 'includes/' );
define( 'CODIR2ME_CDN_ASSETS_DIR', CODIR2ME_CDN_PLUGIN_DIR . 'assets/' );
define( 'CODIR2ME_MAX_BATCH_SIZE', 1000 );
define( 'CODIR2ME_CRON_INTERVAL', 900 );
define( 'CODIR2ME_MEMORY_LIMIT', '512M' );

// Usar wp_upload_dir() para diretório de logs.
$upload_dir = wp_upload_dir();
define( 'CODIR2ME_CDN_LOGS_DIR', $upload_dir['basedir'] . '/codirun-codir2me-cdn-logs/' );

/**
 * Função para registrar logs de depuração
 * Usando WP_Filesystem em vez de file_put_contents direto
 *
 * @param string $message Mensagem a ser registrada.
 * @param string $type Tipo de log (debug, info, error).
 * @return bool Sucesso ou falha.
 */
function codir2me_cdn_log( $message, $type = 'debug' ) {
	// Verificar se o modo de depuração está ativado.
	if ( ! get_option( 'codir2me_debug_mode', false ) ) {
		return false;
	}

	// Inicializar WP_Filesystem.
	global $wp_filesystem;
	if ( ! function_exists( 'WP_Filesystem' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	WP_Filesystem();

	// Certificar que o diretório de logs existe.
	if ( ! $wp_filesystem->exists( CODIR2ME_CDN_LOGS_DIR ) ) {
		$wp_filesystem->mkdir( CODIR2ME_CDN_LOGS_DIR, 0755, true );

		// Adicionar arquivo .htaccess para proteção usando WP_Filesystem.
		$htaccess_content = "Order Allow,Deny\nDeny from all\n";
		$wp_filesystem->put_contents( CODIR2ME_CDN_LOGS_DIR . '.htaccess', $htaccess_content, 0644 );

		// Adicionar index.php vazio para proteção usando WP_Filesystem.
		$wp_filesystem->put_contents( CODIR2ME_CDN_LOGS_DIR . 'index.php', '<?php // Silence is golden', 0644 );
	}

	// Formatar a mensagem.
	$timestamp = current_time( 'Y-m-d H:i:s' );
	$log_entry = "[{$timestamp}] [{$type}] {$message}\n";

	// Escrever no arquivo de log usando WP_Filesystem.
	$log_file         = CODIR2ME_CDN_LOGS_DIR . 'debug.log';
	$existing_content = '';

	if ( $wp_filesystem->exists( $log_file ) ) {
		$existing_content = $wp_filesystem->get_contents( $log_file );
	}

	return $wp_filesystem->put_contents( $log_file, $existing_content . $log_entry, 0644 );
}

/**
 * Função para limpar logs de depuração
 * Usando WP_Filesystem
 *
 * @return bool Sucesso ou falha.
 */
function codir2me_cdn_clear_logs() {
	global $wp_filesystem;
	if ( ! function_exists( 'WP_Filesystem' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	WP_Filesystem();

	$log_file = CODIR2ME_CDN_LOGS_DIR . 'debug.log';
	if ( $wp_filesystem->exists( $log_file ) ) {
		return $wp_filesystem->delete( $log_file );
	}
	return true;
}

/**
 * Função para obter caminho relativo de forma segura
 *
 * @param string $file_path Caminho completo do arquivo.
 * @return string Caminho relativo.
 */
function codir2me_get_relative_path( $file_path ) {
	// Normalizar caminhos.
	$file_path = wp_normalize_path( $file_path );

	// Obter informações dos diretórios.
	$upload_dir     = wp_upload_dir();
	$upload_basedir = wp_normalize_path( $upload_dir['basedir'] );

	// Para arquivos de upload.
	if ( strpos( $file_path, $upload_basedir ) === 0 ) {
		$relative_file_path = substr( $file_path, strlen( $upload_basedir ) );
		$relative_file_path = ltrim( $relative_file_path, '/' );

		$upload_baseurl = $upload_dir['baseurl'];
		$parsed_url     = wp_parse_url( $upload_baseurl );

		if ( isset( $parsed_url['path'] ) ) {
			$uploads_structure = ltrim( $parsed_url['path'], '/' );
			return $uploads_structure . '/' . $relative_file_path;
		}

		return $relative_file_path;
	}

	// Para arquivos de tema.
	$theme_root = wp_normalize_path( get_theme_root() );
	if ( strpos( $file_path, $theme_root ) === 0 ) {
		$relative_file_path = substr( $file_path, strlen( $theme_root ) );
		$relative_file_path = ltrim( $relative_file_path, '/' );

		$content_url = content_url();
		$parsed_url  = wp_parse_url( $content_url );

		if ( isset( $parsed_url['path'] ) ) {
			$content_structure = ltrim( $parsed_url['path'], '/' );
			return $content_structure . '/themes/' . $relative_file_path;
		}

		return 'wp-content/themes/' . $relative_file_path;
	}

	// Para arquivos de plugin.
	$plugin_dir = wp_normalize_path( WP_PLUGIN_DIR );
	if ( strpos( $file_path, $plugin_dir ) === 0 ) {
		$relative_file_path = substr( $file_path, strlen( $plugin_dir ) );
		$relative_file_path = ltrim( $relative_file_path, '/' );

		$content_url = content_url();
		$parsed_url  = wp_parse_url( $content_url );

		if ( isset( $parsed_url['path'] ) ) {
			$content_structure = ltrim( $parsed_url['path'], '/' );
			return $content_structure . '/plugins/' . $relative_file_path;
		}

		return 'wp-content/plugins/' . $relative_file_path;
	}

	// Para outros arquivos do wp-content.
	$content_dir = wp_normalize_path( WP_CONTENT_DIR );
	if ( strpos( $file_path, $content_dir ) === 0 ) {
		$relative_file_path = substr( $file_path, strlen( $content_dir ) );
		$relative_file_path = ltrim( $relative_file_path, '/' );

		$content_url = content_url();
		$parsed_url  = wp_parse_url( $content_url );

		if ( isset( $parsed_url['path'] ) ) {
			$content_structure = ltrim( $parsed_url['path'], '/' );
			return $content_structure . '/' . $relative_file_path;
		}

		return $relative_file_path;
	}

	// Se não conseguiu identificar, retornar o arquivo como está.
	return $file_path;
}

/**
 * Função para obter diretório de uploads do plugin
 * Usando wp_upload_dir()
 *
 * @return string Caminho do diretório de uploads do plugin.
 */
function codir2me_get_uploads_dir() {
	$upload_dir = wp_upload_dir();
	return $upload_dir['basedir'] . '/codirun-codir2me-cdn/';
}

/**
 * Função para obter URL do diretório de uploads do plugin
 * Usando wp_upload_dir()
 *
 * @return string URL do diretório de uploads do plugin.
 */
function codir2me_get_uploads_url() {
	$upload_dir = wp_upload_dir();
	return $upload_dir['baseurl'] . '/codirun-codir2me-cdn/';
}

/**
 * Manipulador de erros PHP para registro em log de depuração.
 *
 * @param int    $errno   Nível do erro.
 * @param string $errstr  Mensagem de erro.
 * @param string $errfile Arquivo onde ocorreu o erro.
 * @param int    $errline Linha onde ocorreu o erro.
 * @return bool False para continuar com o manipulador padrão.
 */
function codir2me_php_error_handler( $errno, $errstr, $errfile, $errline ) {
	if ( get_option( 'codir2me_debug_mode', false ) ) {
		$error_type = __( 'PHP ERROR', 'codirun-codir2me-cdn' );
		switch ( $errno ) {
			case E_ERROR:
				$error_type = __( 'Fatal Error', 'codirun-codir2me-cdn' );
				break;
			case E_WARNING:
				$error_type = __( 'Warning', 'codirun-codir2me-cdn' );
				break;
			case E_NOTICE:
				$error_type = __( 'Notice', 'codirun-codir2me-cdn' );
				break;
			case E_DEPRECATED:
				$error_type = __( 'Deprecated', 'codirun-codir2me-cdn' );
				break;
		}

		codir2me_cdn_log( "[$error_type] $errstr in $errfile on line $errline", 'error' );
	}

	// Continuar com o manipulador de erros padrão.
	return false;
}

// Adicionar ação para registrar erros do PHP no log de depuração.
add_action( 'php_error_handler', 'codir2me_php_error_handler', 10, 4 );
add_action( 'upgrader_process_complete', 'codir2me_handle_update_complete', 10, 2 );

// Carregar classes na ordem correta.
require_once CODIR2ME_CDN_INCLUDES_DIR . 'class-codir2me-uploader.php';
require_once CODIR2ME_CDN_INCLUDES_DIR . 'class-codir2me-assets-handler.php';
require_once CODIR2ME_CDN_INCLUDES_DIR . 'class-codir2me-images-handler.php';
require_once CODIR2ME_CDN_INCLUDES_DIR . 'class-codir2me-admin.php';
require_once CODIR2ME_CDN_INCLUDES_DIR . 'class-codir2me-static-media-cdn.php';
require_once CODIR2ME_CDN_INCLUDES_DIR . 'class-codir2me-auto-delete-handler.php';
require_once CODIR2ME_CDN_INCLUDES_DIR . 'class-codir2me-image-optimizer.php';
require_once CODIR2ME_CDN_INCLUDES_DIR . 'class-codir2me-image-reprocessor.php';
require_once CODIR2ME_CDN_INCLUDES_DIR . 'class-codir2me-i18n.php';
require_once CODIR2ME_CDN_INCLUDES_DIR . 'class-codir2me-github-updater.php';
require_once CODIR2ME_CDN_INCLUDES_DIR . 'admin/class-codir2me-admin-ui.php';
require_once CODIR2ME_CDN_INCLUDES_DIR . 'admin/class-codir2me-admin-ui-delete.php';
require_once CODIR2ME_CDN_INCLUDES_DIR . 'admin/class-codir2me-admin-ui-optimizer.php';
require_once CODIR2ME_CDN_INCLUDES_DIR . 'admin/class-codir2me-admin-ui-license.php';
require_once CODIR2ME_CDN_INCLUDES_DIR . 'admin/class-codir2me-admin-ui-general.php';

/**
 * Inicializar o plugin principal.
 *
 * @return void
 */
function codir2me_static_media_cdn_init() {
	$codir2me_i18n = new CODIR2ME_I18n();

	if ( ! wp_next_scheduled( 'codir2me_background_reprocessing_event' ) && get_option( 'codir2me_reprocessing_status', false ) ) {
		$reprocessing_status = get_option( 'codir2me_reprocessing_status', array() );

		if ( isset( $reprocessing_status['in_progress'] ) &&
			$reprocessing_status['in_progress'] &&
			isset( $reprocessing_status['background_mode'] ) &&
			$reprocessing_status['background_mode'] &&
			( ! isset( $reprocessing_status['paused'] ) || ! $reprocessing_status['paused'] ) ) {

			wp_schedule_event( time(), 'codir2me_fifteen_minutes', 'codir2me_background_reprocessing_event' );
		}
	}

	// Inicializar o manipulador de exclusão automática.
	$auto_delete_handler = new CODIR2ME_Auto_Delete_Handler();

	// Inicializar o plugin primeiro, antes de usá-lo.
	$plugin = new CODIR2ME_Static_Media_CDN();
	$plugin->codir2me_init();

	// Armazenar a instância em uma variável global para acesso posterior.
	global $codir2me_static_media_cdn;
	$codir2me_static_media_cdn = $plugin;

	// Verificar se todas as classes foram carregadas corretamente.
	if (
		class_exists( 'CODIR2ME_Uploader' ) &&
		class_exists( 'CODIR2ME_Assets_Handler' ) &&
		class_exists( 'CODIR2ME_Images_Handler' ) &&
		class_exists( 'CODIR2ME_Admin_UI' ) &&
		class_exists( 'CODIR2ME_Admin' ) &&
		class_exists( 'CODIR2ME_Static_Media_CDN' ) &&
		class_exists( 'CODIR2ME_Auto_Delete_Handler' ) &&
		class_exists( 'CODIR2ME_Image_Optimizer' ) &&
		class_exists( 'CODIR2ME_Admin_UI_Optimizer' ) &&
		class_exists( 'CODIR2ME_Image_Reprocessor' ) &&
		class_exists( 'CODIR2ME_Admin_UI_General' )
	) {
		// A instância já foi criada e inicializada acima.
		if ( ! get_transient( 'codir2me_classes_loaded_logged' ) ) {
			codir2me_cdn_log( __( 'R2 Static & Media CDN: Todas as classes foram carregadas com sucesso.', 'codirun-codir2me-cdn' ), 'info' );
			set_transient( 'codir2me_classes_loaded_logged', true, HOUR_IN_SECONDS );
		}
	} else {
		// Adicionar mensagem de erro ao log.
		codir2me_cdn_log( __( 'R2 Static & Media CDN: Falha ao carregar uma ou mais classes do plugin.', 'codirun-codir2me-cdn' ), 'error' );
	}
}
add_action( 'init', 'codir2me_static_media_cdn_init' );

/**
 * Inicializar o gerenciador de licenças.
 *
 * @return void
 */
function codir2me_license_manager_init() {
	require_once CODIR2ME_CDN_INCLUDES_DIR . 'class-codir2me-license-manager.php';
	global $codir2me_license_manager;
	$codir2me_license_manager = new CODIR2ME_License_Manager();
}
add_action( 'plugins_loaded', 'codir2me_license_manager_init', 11 );
add_action( 'plugins_loaded', 'codir2me_github_updater_init', 12 );

// Fornece acesso à instância global do plugin.
add_filter(
	'codir2me_cdn_get_instance',
	function ( $instance ) {
		global $codir2me_static_media_cdn;
		if ( isset( $codir2me_static_media_cdn ) && is_object( $codir2me_static_media_cdn ) ) {
			return $codir2me_static_media_cdn;
		}
		return $instance;
	}
);

// Ações de ativação e desativação.
register_activation_hook( __FILE__, 'codir2me_static_media_cdn_activate' );
register_deactivation_hook( __FILE__, 'codir2me_static_media_cdn_deactivate' );

// Hook direto de ativação para tracking.
register_activation_hook(
	__FILE__,
	function () {
		// Incluir tracking.
		require_once CODIR2ME_CDN_INCLUDES_DIR . 'class-codir2me-tracking-system.php';

		// Enviar ping direto.
		$data = array(
			'action'         => 'track_plugin',
			'site_url'       => home_url(),
			'plugin_name'    => 'Codirun R2 Media & Static CDN',
			'plugin_slug'    => 'codirun-codir2me-cdn',
			'status'         => 'ativo',
			'plugin_version' => CODIR2ME_CDN_VERSION,
			'wp_version'     => get_bloginfo( 'version' ),
			'php_version'    => PHP_VERSION,
			'timestamp'      => time(),
		);

		codir2me_tracking_send_request( $data, false );
	}
);

// Hook direto de desativação para tracking.
register_deactivation_hook(
	__FILE__,
	function () {
		// Incluir tracking.
		require_once CODIR2ME_CDN_INCLUDES_DIR . 'class-codir2me-tracking-system.php';

		// Enviar ping direto.
		$data = array(
			'action'         => 'track_plugin',
			'site_url'       => home_url(),
			'plugin_name'    => 'Codirun R2 Media & Static CDN',
			'plugin_slug'    => 'codirun-codir2me-cdn',
			'status'         => 'inativo',
			'plugin_version' => CODIR2ME_CDN_VERSION,
			'wp_version'     => get_bloginfo( 'version' ),
			'php_version'    => PHP_VERSION,
			'timestamp'      => time(),
		);

		codir2me_tracking_send_request( $data, false );
	}
);

/**
 * Inicializar o sistema de atualização via GitHub
 * 
 * @return void
 */
function codir2me_github_updater_init() {
	// Verificar se não estamos em ambiente de desenvolvimento.
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG && isset( $_GET['disable_updates'] ) ) {
		return;
	}
	
	// Configurações do GitHub
	$github_username = 'codirun';    // SUBSTITUA pelo seu usuário do GitHub.
	$github_repo = 'r2cdn';           // SUBSTITUA pelo nome do seu repositório.
	$github_token = '';                         // Deixe vazio se repositório for público.
	
	// Inicializar o updater.
	new CODIR2ME_GitHub_Updater(
		$github_username,
		$github_repo,
		__FILE__,
		CODIR2ME_CDN_VERSION,
		$github_token
	);
	
	// Log da inicialização.
	codir2me_cdn_log( 'Sistema de atualização GitHub inicializado', 'info' );
}

/**
 * Função executada na ativação do plugin.
 *
 * @return void
 */
function codir2me_static_media_cdn_activate() {
	// Iniciar output buffering para capturar qualquer saída indesejada.
	ob_start();

	// Inicializar WP_Filesystem.
	global $wp_filesystem;
	if ( ! function_exists( 'WP_Filesystem' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	WP_Filesystem();

	// Criar os diretórios necessários.
	$directories = array(
		CODIR2ME_CDN_PLUGIN_DIR . 'vendor',
		CODIR2ME_CDN_INCLUDES_DIR,
		CODIR2ME_CDN_ASSETS_DIR,
		CODIR2ME_CDN_ASSETS_DIR . 'css',
		CODIR2ME_CDN_ASSETS_DIR . 'js',
		CODIR2ME_CDN_ASSETS_DIR . 'images',
	);

	foreach ( $directories as $dir ) {
		if ( ! $wp_filesystem->exists( $dir ) ) {
			$wp_filesystem->mkdir( $dir, 0755, true );
		}
	}

	// Criar diretório de logs usando WP_Filesystem.
	if ( ! $wp_filesystem->exists( CODIR2ME_CDN_LOGS_DIR ) ) {
		$wp_filesystem->mkdir( CODIR2ME_CDN_LOGS_DIR, 0755, true );

		// Adicionar arquivo .htaccess para proteção usando WP_Filesystem.
		$htaccess_content = "Order Allow,Deny\nDeny from all\n";
		$wp_filesystem->put_contents( CODIR2ME_CDN_LOGS_DIR . '.htaccess', $htaccess_content, 0644 );

		// Adicionar index.php vazio para proteção usando WP_Filesystem.
		$wp_filesystem->put_contents( CODIR2ME_CDN_LOGS_DIR . 'index.php', '<?php // Silence is golden', 0644 );
	}

	// Limpar status de reprocessamento em segundo plano.
	delete_option( 'codir2me_reprocessing_image_ids' );
	$reprocessing_status                = get_option( 'codir2me_reprocessing_status', array() );
	$reprocessing_status['in_progress'] = false;
	$reprocessing_status['paused']      = false;
	update_option( 'codir2me_reprocessing_status', $reprocessing_status );

	// Cancelar quaisquer eventos de reprocessamento agendados.
	$timestamp = wp_next_scheduled( 'codir2me_background_reprocessing_event' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'codir2me_background_reprocessing_event' );
	}

	// Limpar qualquer resíduo de uploads anteriores.
	delete_option( 'codir2me_pending_files' );
	delete_option( 'codir2me_upload_status' );
	delete_option( 'codir2me_upload_error' );
	delete_option( 'codir2me_pending_images' );
	delete_option( 'codir2me_images_upload_status' );
	delete_option( 'codir2me_images_upload_error' );

	// Inicializar opções de rastreamento de miniaturas.
	if ( ! get_option( 'codir2me_uploaded_thumbnails_by_size' ) ) {
		add_option( 'codir2me_uploaded_thumbnails_by_size', array() );
	}

	// Inicializar cache de estatísticas de miniaturas.
	if ( ! get_option( 'codir2me_cached_thumbnails_info' ) ) {
		add_option( 'codir2me_cached_thumbnails_info', array() );
	}

	// Remover opção antiga do AWS SDK e adicionar nova do AsyncAws.
	delete_option( 'codir2me_assets_need_aws_sdk' );
	add_option( 'codir2me_assets_need_asyncaws_sdk', true );

	// Definir opções padrão para seleção de miniaturas se não existirem.
	if ( ! get_option( 'codir2me_thumbnail_option' ) ) {
		add_option( 'codir2me_thumbnail_option', 'all' );
	}

	if ( ! get_option( 'codir2me_selected_thumbnails' ) ) {
		add_option( 'codir2me_selected_thumbnails', array() );
	}

	// Inicializar opções de upload automático.
	if ( ! get_option( 'codir2me_auto_upload_static' ) ) {
		add_option( 'codir2me_auto_upload_static', false );
	}

	if ( ! get_option( 'codir2me_auto_upload_frequency' ) ) {
		add_option( 'codir2me_auto_upload_frequency', 'daily' );
	}

	if ( ! get_option( 'codir2me_upload_on_update' ) ) {
		add_option( 'codir2me_upload_on_update', false );
	}

	if ( ! get_option( 'codir2me_enable_versioning' ) ) {
		add_option( 'codir2me_enable_versioning', false );
	}

	if ( ! get_option( 'codir2me_file_upload_timestamps' ) ) {
		add_option( 'codir2me_file_upload_timestamps', array() );
	}

	// Nova configuração para upload automático de miniaturas.
	if ( ! get_option( 'codir2me_auto_upload_thumbnails' ) ) {
		add_option( 'codir2me_auto_upload_thumbnails', false );
	}

	// Migrar configurações do plugin antigo, se existir.
	if ( get_option( 'codir2me_access_key' ) && ! get_option( 'codir2me_is_images_cdn_active' ) ) {
		add_option( 'codir2me_is_images_cdn_active', false );
	}

	// Inicializar opções de otimização se não existirem.
	if ( ! get_option( 'codir2me_image_optimization_options' ) ) {
		add_option(
			'codir2me_image_optimization_options',
			array(
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
			)
		);
	}

	// Inicializar estatísticas de otimização.
	if ( ! get_option( 'codir2me_optimization_stats' ) ) {
		add_option(
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
	}

	// Verificar versão ao atualizar.
	update_option( 'CODIR2ME_CDN_VERSION', CODIR2ME_CDN_VERSION );

	// Inicializar configurações do modo de depuração.
	if ( ! get_option( 'codir2me_debug_mode' ) ) {
		add_option( 'codir2me_debug_mode', false );
	}

	if ( ! get_option( 'codir2me_clean_logs_on_deactivate' ) ) {
		add_option( 'codir2me_clean_logs_on_deactivate', false );
	}

	// Limpar qualquer output capturado.
	ob_end_clean();
}

/**
 * Limpar agendamentos do sistema de atualização
 * 
 * @return void
 */
function codir2me_cleanup_updater_schedules() {
	// Limpar agendamento de verificação diária.
	wp_clear_scheduled_hook( 'codir2me_daily_update_check' );
	
	// Limpar transients de cache.
	$cache_pattern = 'codir2me_github_update_';
	global $wpdb;
	
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( '_transient_' . $cache_pattern ) . '%'
		)
	);
	
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( '_transient_timeout_' . $cache_pattern ) . '%'
		)
	);
}

/**
 * Função executada na desativação do plugin.
 *
 * @return void
 */
function codir2me_static_media_cdn_deactivate() {
	// Limpar tarefas de reprocessamento em segundo plano.
	$timestamp = wp_next_scheduled( 'codir2me_background_reprocessing_event' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'codir2me_background_reprocessing_event' );
	}

	// Limpar tarefas de exclusão em segundo plano.
	$timestamp = wp_next_scheduled( 'codir2me_background_deletion_event' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'codir2me_background_deletion_event' );
	}

	// Limpar tarefas cron, caso existam.
	$timestamp = wp_next_scheduled( 'codir2me_cdn_cleanup_event' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'codir2me_cdn_cleanup_event' );
	}

	// Limpar tarefa de upload automático.
	$timestamp = wp_next_scheduled( 'codir2me_auto_upload_cron' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'codir2me_auto_upload_cron' );
	}

	// Limpar logs se a opção estiver ativada.
	if ( get_option( 'codir2me_clean_logs_on_deactivate', false ) ) {
		codir2me_cdn_clear_logs();
	}

	codir2me_cleanup_resend_cron();
	codir2me_cleanup_updater_schedules();
}

/**
 * Exibir aviso sobre a necessidade do AsyncAws SDK.
 *
 * @return void
 */
function codir2me_static_media_sdk_notice() {
	// Verificar se estamos na página do plugin.
	$screen = get_current_screen();
	if ( ! isset( $screen->id ) || strpos( $screen->id, 'codirun-codir2me-cdn' ) === false ) {
		return;
	}

	if ( get_option( 'codir2me_assets_need_asyncaws_sdk' ) ) {
		?>
		<div class="notice notice-warning is-dismissible">
			<p><?php esc_html_e( 'O plugin R2 Static & Media CDN agora usa AsyncAws S3 para melhor performance. Por favor, instale o AsyncAws S3 executando:', 'codirun-codir2me-cdn' ); ?></p>
			<code>composer require async-aws/s3</code>
			<p><?php esc_html_e( 'Ou baixe e extraia manualmente na pasta vendor/ do plugin.', 'codirun-codir2me-cdn' ); ?></p>
		</div>
		<?php
	}
}

/**
 * Verificar acesso a funcionalidades premium.
 *
 * @param string $tab Nome da aba/funcionalidade.
 * @return string Nome da aba (inalterado se tiver acesso).
 */
function codir2me_check_premium_access( $tab ) {
	if ( 'delete' === $tab || 'optimization' === $tab || 'scanner' === $tab || 'reprocess' === $tab ) {
		// Verificar se o usuário tem acesso à funcionalidade.
		$has_access = apply_filters( 'codir2me_can_access_premium_feature', false, $tab );

		if ( ! $has_access ) {
			// Redirecionar para a aba de licenciamento (em vez da página).
			wp_safe_redirect( admin_url( 'admin.php?page=codirun-codir2me-cdn-license&error=premium_feature' ) );
			exit;
		}
	}

	return $tab;
}
add_filter( 'codir2me_admin_tab_access', 'codir2me_check_premium_access', 10, 1 );

/**
 * Verificar disponibilidade do AsyncAws SDK e remover notificação se disponível.
 *
 * @return void
 */
function codir2me_static_media_check_sdk_availability() {
	if ( get_option( 'codir2me_assets_need_asyncaws_sdk' ) ) {
		if ( file_exists( CODIR2ME_CDN_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
			require_once CODIR2ME_CDN_PLUGIN_DIR . 'vendor/autoload.php';
			if ( class_exists( 'AsyncAws\S3\S3Client' ) ) {
				delete_option( 'codir2me_assets_need_asyncaws_sdk' );
			}
		}
	}
}

/**
 * Adicionar suporte para MIME type AVIF.
 *
 * @return void
 */
function codir2me_add_avif_mime() {
	add_filter(
		'mime_types',
		function ( $mimes ) {
			$mimes['avif'] = 'image/avif';
			return $mimes;
		}
	);

	add_filter(
		'upload_mimes',
		function ( $types ) {
			$types['avif'] = 'image/avif';
			return $types;
		}
	);
}

/**
 * Configurar cabeçalhos corretos para arquivos AVIF.
 *
 * @return void
 */
function codir2me_setup_avif_headers() {
	add_filter(
		'wp_headers',
		function ( $headers ) {
			global $wp;
			$current_url = home_url( add_query_arg( array(), $wp->request ) );

			if ( strpos( $current_url, '.avif' ) !== false ) {
				$headers['Content-Type']                = 'image/avif';
				$headers['Access-Control-Allow-Origin'] = '*';
			}

			return $headers;
		}
	);
}

/**
 * Executa quando atualização de plugin/tema é concluída
 *
 * @param WP_Upgrader $upgrader Instância do upgrader do WordPress.
 * @param array       $hook_extra Array com informações extras sobre a atualização.
 * @return void
 */
function codir2me_handle_update_complete( $upgrader, $hook_extra ) {
	// Verificar se CDN está ativo.
	if ( ! get_option( 'codir2me_is_cdn_active', false ) ) {
		return;
	}

	$paths_to_clear = array();

	// Plugin atualizado.
	if ( isset( $hook_extra['type'] ) && 'plugin' === $hook_extra['type'] ) {
		if ( isset( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
			// Múltiplos plugins.
			foreach ( $hook_extra['plugins'] as $plugin ) {
				$plugin_folder = dirname( $plugin );
				// Usar WP_PLUGIN_DIR para obter o caminho correto.
				$plugin_path      = str_replace( wp_normalize_path( WP_PLUGIN_DIR ), '', wp_normalize_path( WP_PLUGIN_DIR . '/' . $plugin_folder ) );
				$paths_to_clear[] = ltrim( $plugin_path, '/' );
			}
		} elseif ( isset( $hook_extra['plugin'] ) ) {
			// Plugin único.
			$plugin_folder    = dirname( $hook_extra['plugin'] );
			$plugin_path      = str_replace( wp_normalize_path( WP_PLUGIN_DIR ), '', wp_normalize_path( WP_PLUGIN_DIR . '/' . $plugin_folder ) );
			$paths_to_clear[] = ltrim( $plugin_path, '/' );
		}
	}

	// Tema atualizado.
	if ( isset( $hook_extra['type'] ) && 'theme' === $hook_extra['type'] ) {
		if ( isset( $hook_extra['themes'] ) && is_array( $hook_extra['themes'] ) ) {
			// Múltiplos temas.
			foreach ( $hook_extra['themes'] as $theme ) {
				// Usar get_theme_root() para obter o caminho correto.
				$theme_path       = str_replace( wp_normalize_path( get_theme_root() ), '', wp_normalize_path( get_theme_root() . '/' . $theme ) );
				$paths_to_clear[] = ltrim( $theme_path, '/' );
			}
		} elseif ( isset( $hook_extra['theme'] ) ) {
			// Tema único.
			$theme_path       = str_replace( wp_normalize_path( get_theme_root() ), '', wp_normalize_path( get_theme_root() . '/' . $hook_extra['theme'] ) );
			$paths_to_clear[] = ltrim( $theme_path, '/' );
		}
	}

	// Sempre limpar cache se houver caminhos para limpar.
	if ( ! empty( $paths_to_clear ) ) {
		// Log da atualização detectada.
		$paths_str = implode( ', ', $paths_to_clear );
		codir2me_cdn_log(
			sprintf( 'Atualização detectada para os caminhos: %s', $paths_str ),
			'info'
		);

		// Limpar cache primeiro.
		codir2me_clear_specific_cache( $paths_to_clear );

		// Se "Re-envio Automático" estiver ativo, agendar re-envio.
		if ( get_option( 'codir2me_upload_on_update', false ) ) {
			// Salvar caminhos para processar depois.
			update_option( 'codir2me_pending_resend_paths', $paths_to_clear );

			// Cancelar qualquer evento agendado anteriormente.
			$timestamp = wp_next_scheduled( 'codir2me_background_resend_event' );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, 'codir2me_background_resend_event' );
			}

			// Agendar para 30 segundos (não bloqueia a atualização).
			wp_schedule_single_event( time() + 30, 'codir2me_background_resend_event' );

			codir2me_cdn_log(
				sprintf( 'Agendado re-envio em segundo plano para os caminhos: %s', $paths_str ),
				'info'
			);
		} else {
			codir2me_cdn_log(
				'Re-envio automático está desativado. Apenas o cache foi limpo.',
				'info'
			);
		}
	}
}

/**
 * Remove apenas os arquivos dos caminhos especificados do cache
 *
 * @param array $paths_to_clear Array com os caminhos que devem ter seus arquivos removidos do cache.
 * @return void
 */
function codir2me_clear_specific_cache( $paths_to_clear ) {
	$uploaded_files    = get_option( 'codir2me_uploaded_files', array() );
	$upload_timestamps = get_option( 'codir2me_upload_timestamps', array() );

	if ( empty( $uploaded_files ) ) {
		codir2me_cdn_log( 'Cache limpo seletivamente: nenhum arquivo na lista para limpar', 'info' );
		return;
	}

	$files_removed  = 0;
	$original_count = count( $uploaded_files );

	// Log dos caminhos que serão limpos.
	$paths_str = implode( ', ', $paths_to_clear );
	codir2me_cdn_log( "Iniciando limpeza seletiva de cache para os caminhos: {$paths_str}", 'info' );

	// Filtrar arquivos - remover apenas os dos caminhos atualizados.
	$uploaded_files = array_filter(
		$uploaded_files,
		function ( $file_path ) use ( $paths_to_clear, &$files_removed, &$upload_timestamps ) {

			foreach ( $paths_to_clear as $path ) {
				// Normalizar caminhos para comparação.
				$normalized_path = ltrim( str_replace( '\\', '/', $path ), '/' );
				$normalized_file = ltrim( str_replace( '\\', '/', $file_path ), '/' );

				// Verificar se o arquivo está no caminho que deve ser limpo.
				if ( strpos( $normalized_file, $normalized_path ) === 0 ) {
					// Arquivo está no caminho atualizado - remover.
					unset( $upload_timestamps[ $file_path ] );
					$files_removed++;

					// Log de debug para cada arquivo removido.
					codir2me_cdn_log( "Removendo do cache: {$file_path} (caminho: {$path})", 'debug' );

					return false; // Remove do array.
				}
			}

			return true; // Mantém no array.
		}
	);

	// Reindexar array.
	$uploaded_files = array_values( $uploaded_files );

	// Salvar apenas se algo foi removido.
	if ( $files_removed > 0 ) {
		// Atualizar as opções.
		update_option( 'codir2me_uploaded_files', $uploaded_files );
		update_option( 'codir2me_upload_timestamps', $upload_timestamps );

		// Incrementar versão.
		$version = get_option( 'codir2me_assets_version', 1 );
		update_option( 'codir2me_assets_version', $version + 1 );

		// Log detalhado.
		codir2me_cdn_log(
			sprintf(
				'Cache limpo seletivamente: %d arquivos removidos dos caminhos: %s (total anterior: %d, total atual: %d)',
				$files_removed,
				$paths_str,
				$original_count,
				count( $uploaded_files )
			),
			'info'
		);

		// Forçar flush do cache de opções do WordPress.
		wp_cache_delete( 'codir2me_uploaded_files', 'options' );
		wp_cache_delete( 'codir2me_upload_timestamps', 'options' );

	} else {
		codir2me_cdn_log(
			sprintf(
				'Cache limpo seletivamente: nenhum arquivo encontrado nos caminhos especificados: %s',
				$paths_str
			),
			'info'
		);
	}
}

/**
 * Executa o re-envio em segundo plano (via cron)
 */
function codir2me_background_resend_files() {
	// Obter caminhos pendentes.
	$paths_to_clear = get_option( 'codir2me_pending_resend_paths', array() );
	if ( empty( $paths_to_clear ) ) {
		codir2me_cdn_log( 'Re-envio em segundo plano: nenhum caminho pendente', 'info' );
		return;
	}

	codir2me_cdn_log(
		sprintf( 'Iniciando re-envio em segundo plano para: %s', implode( ', ', $paths_to_clear ) ),
		'info'
	);

	// Aumentar limite de memória temporariamente.
	wp_raise_memory_limit( 'admin' );

	try {
		codir2me_resend_updated_files_background( $paths_to_clear );
	} catch ( Exception $e ) {
		codir2me_cdn_log(
			/* translators: %s: mensagem de erro */
			sprintf( 'Erro no re-envio em segundo plano: %s', $e->getMessage() ),
			'error'
		);
	}
}

/**
 * Re-envia arquivos em lotes com controle de tempo
 *
 * @param array $paths_to_clear Array com os caminhos que devem ser reenviados.
 * @return void
 */
function codir2me_resend_updated_files_background( $paths_to_clear ) {
	// Obter instância do plugin.
	global $codir2me_static_media_cdn;
	if ( ! $codir2me_static_media_cdn ) {
		codir2me_cdn_log( 'Instância do plugin não disponível para re-envio em segundo plano', 'error' );
		return;
	}

	$uploader = $codir2me_static_media_cdn->codir2me_get_uploader();
	if ( ! $uploader ) {
		codir2me_cdn_log( 'Uploader não disponível para re-envio em segundo plano', 'error' );
		return;
	}

	// Controle de tempo de execução.
	$start_time         = time();
	$max_execution_time = 45; // 45 segundos máximo por execução.

	// Controle de lote.
	$batch_size        = 50; // Reduzido para 50 arquivos por lote.
	$files_processed   = 0;
	$files_resent      = 0;
	$files_skipped     = 0;
	$total_files_found = 0;

	// Obter lista de arquivos já enviados atualizada após a limpeza do cache.
	$uploaded_files     = get_option( 'codir2me_uploaded_files', array() );
	$uploaded_files_set = array_flip( $uploaded_files ); // Para busca rápida.

	// Primeiro, contar total de arquivos para log.
	foreach ( $paths_to_clear as $path ) {
		$full_path = ABSPATH . $path;
		if ( is_dir( $full_path ) ) {
			$files              = codir2me_scan_directory_for_static_files( $full_path );
			$total_files_found += count( $files );
		}
	}

	codir2me_cdn_log(
		sprintf( 'Total de arquivos encontrados para re-envio: %d (processando em lotes de %d)', $total_files_found, $batch_size ),
		'info'
	);

	// Se não encontrou arquivos, limpar e sair.
	if ( 0 === $total_files_found ) {
		delete_option( 'codir2me_pending_resend_paths' );
		codir2me_cdn_log( 'Re-envio em segundo plano concluído. 0 arquivos encontrados.', 'info' );
		return;
	}

	// Obter posição atual do processamento.
	$current_position   = get_option( 'codir2me_resend_position', 0 );
	$current_path_index = get_option( 'codir2me_resend_path_index', 0 );

	// Processar arquivos com controle de tempo.
	$total_paths = count( $paths_to_clear );
	for ( $path_index = $current_path_index; $path_index < $total_paths; $path_index++ ) {
		$path      = $paths_to_clear[ $path_index ];
		$full_path = ABSPATH . $path;

		if ( ! is_dir( $full_path ) ) {
			continue;
		}

		$files       = codir2me_scan_directory_for_static_files( $full_path );
		$total_files = count( $files );

		// Processar arquivos a partir da posição atual.
		for ( $i = ( $path_index === $current_path_index ? $current_position : 0 ); $i < $total_files; $i++ ) {
			// Verificar se o tempo limite foi atingido.
			if ( ( time() - $start_time ) >= $max_execution_time ) {
				// Salvar posição atual e reagendar.
				update_option( 'codir2me_resend_position', $i );
				update_option( 'codir2me_resend_path_index', $path_index );

				codir2me_cdn_log(
					sprintf(
						'Tempo limite atingido (%ds). Processados %d arquivos (%d enviados, %d pulados). Reagendando...',
						$max_execution_time,
						$files_processed,
						$files_resent,
						$files_skipped
					),
					'info'
				);

				// Reagendar para continuar em 2 minutos.
				wp_schedule_single_event( time() + 120, 'codir2me_background_resend_event' );
				return;
			}

			// Verificar se atingiu o limite do lote.
			if ( $files_processed >= $batch_size ) {
				// Salvar posição atual e reagendar.
				update_option( 'codir2me_resend_position', $i );
				update_option( 'codir2me_resend_path_index', $path_index );

				codir2me_cdn_log(
					sprintf(
						'Lote de %d arquivos processado (%d enviados, %d pulados). Reagendando próximo lote em 2 minutos...',
						$batch_size,
						$files_resent,
						$files_skipped
					),
					'info'
				);

				// Reagendar próximo lote em 2 minutos.
				wp_schedule_single_event( time() + 120, 'codir2me_background_resend_event' );
				return;
			}

			$file_info = $files[ $i ];

			// Re-obter lista atualizada de arquivos enviados.
			$current_uploaded_files = get_option( 'codir2me_uploaded_files', array() );
			$current_uploaded_set   = array_flip( $current_uploaded_files );

			// Verificar se o arquivo já foi enviado.
			if ( isset( $current_uploaded_set[ $file_info['relative_path'] ] ) ) {
				// Arquivo já foi enviado, apenas atualizar timestamp.
				$upload_timestamps                                = get_option( 'codir2me_file_upload_timestamps', array() );
				$upload_timestamps[ $file_info['relative_path'] ] = time();
				update_option( 'codir2me_file_upload_timestamps', $upload_timestamps );

				++$files_skipped;
				++$files_processed;
				continue;
			}

			try {
				// Re-enviar arquivo.
				$uploader->codir2me_upload_file( $file_info['full_path'], $file_info['relative_path'] );

				// Atualizar registros.
				$current_uploaded_files = get_option( 'codir2me_uploaded_files', array() );
				if ( ! in_array( $file_info['relative_path'], $current_uploaded_files, true ) ) {
					$current_uploaded_files[] = $file_info['relative_path'];
					update_option( 'codir2me_uploaded_files', $current_uploaded_files );
				}

				$upload_timestamps                                = get_option( 'codir2me_file_upload_timestamps', array() );
				$upload_timestamps[ $file_info['relative_path'] ] = time();
				update_option( 'codir2me_file_upload_timestamps', $upload_timestamps );

				++$files_resent;
				++$files_processed;

				// Pausa menor entre uploads.
				usleep( 100000 ); // 0.1 segundos.

			} catch ( Exception $e ) {
				codir2me_cdn_log(
					sprintf( 'Erro ao re-enviar %s: %s', $file_info['relative_path'], $e->getMessage() ),
					'error'
				);
				++$files_processed;
			}
		}

		// Reset position for next path.
		if ( $path_index > $current_path_index ) {
			update_option( 'codir2me_resend_position', 0 );
		}
	}

	// Se chegou aqui, todos os arquivos foram processados.
	delete_option( 'codir2me_pending_resend_paths' );
	delete_option( 'codir2me_resend_position' );
	delete_option( 'codir2me_resend_path_index' );

	codir2me_cdn_log(
		sprintf( 'Re-envio em segundo plano concluído. %d arquivos reenviados, %d arquivos pulados', $files_resent, $files_skipped ),
		'info'
	);
}

/**
 * Escaneia diretório específico para arquivos estáticos
 *
 * @param string $dir Caminho do diretório a ser escaneado.
 * @return array Array com lista de arquivos encontrados.
 */
function codir2me_scan_directory_for_static_files( $dir ) {
	$files          = array();
	$supported_exts = array( 'js', 'css', 'svg', 'woff', 'woff2', 'ttf', 'eot' );

	if ( ! is_dir( $dir ) || ! is_readable( $dir ) ) {
		return $files;
	}

	// Usar scandir recursivo.
	try {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $iterator as $file ) {
			if ( $file->isFile() ) {
				$ext = strtolower( $file->getExtension() );

				if ( in_array( $ext, $supported_exts, true ) ) {
					$full_path = $file->getPathname();

					// Para JS e CSS, verificar se não é arquivo dinâmico.
					if ( in_array( $ext, array( 'js', 'css' ), true ) ) {
						// Verificação básica de conteúdo usando WP_Filesystem.
						global $wp_filesystem;
						if ( ! function_exists( 'WP_Filesystem' ) ) {
							require_once wp_normalize_path( get_home_path() . 'wp-admin/includes/file.php' );
						}
						WP_Filesystem();

						$sample = '';
						if ( $wp_filesystem->exists( $full_path ) && $wp_filesystem->is_readable( $full_path ) ) {
							// Ler apenas os primeiros 500 bytes para verificação.
							$content = $wp_filesystem->get_contents( $full_path );
							if ( false !== $content ) {
								$sample = substr( $content, 0, 500 );
							}
						}

						if ( ! empty( $sample ) && ( strpos( $sample, '.php' ) !== false || strpos( $sample, 'ajax' ) !== false ) ) {
							continue; // Pular arquivos dinâmicos.
						}
					}

					$relative_path = codir2me_get_relative_path( $full_path );

					$files[] = array(
						'full_path'     => $full_path,
						'relative_path' => $relative_path,
					);
				}
			}
		}
	} catch ( Exception $e ) {
		codir2me_cdn_log(
			/* translators: %1$s: caminho do diretório, %2$s: mensagem de erro */
			sprintf( 'Erro ao escanear diretório %1$s: %2$s', $dir, $e->getMessage() ),
			'error'
		);
	}

	return $files;
}

/**
 * Adiciona versão às URLs de arquivos estáticos
 *
 * @param string $src URL do arquivo estático.
 * @param string $handle Handle do arquivo (não utilizado na implementação atual).
 * @return string URL com versão adicionada.
 */
function codir2me_add_version_to_static_urls( $src, $handle = '' ) {
	// Suprimir warning sobre parâmetro não utilizado.
	unset( $handle );

	// Verificar se versionamento está ativo.
	if ( ! get_option( 'codir2me_enable_versioning', false ) ) {
		return $src;
	}

	// Verificar se é URL do CDN.
	$cdn_url = get_option( 'codir2me_cdn_url', '' );
	if ( empty( $cdn_url ) || strpos( $src, $cdn_url ) === false ) {
		return $src;
	}

	// Obter versão atual.
	$version = get_option( 'codir2me_assets_version', 1 );

	// Adicionar parâmetro de versão.
	$separator = ( strpos( $src, '?' ) !== false ) ? '&' : '?';
	return $src . $separator . 'v=' . $version;
}

/**
 * Valida e corrige inconsistências na lista de arquivos enviados
 * Esta função deve ser chamada antes do escaneamento para garantir dados consistentes
 *
 * @return array Estatísticas da validação
 */
function codir2me_validate_and_fix_uploaded_files_list() {
	$uploaded_files    = get_option( 'codir2me_uploaded_files', array() );
	$upload_timestamps = get_option( 'codir2me_upload_timestamps', array() );

	$stats = array(
		'total_files'        => count( $uploaded_files ),
		'files_removed'      => 0,
		'duplicates_found'   => 0,
		'missing_timestamps' => 0,
	);

	if ( empty( $uploaded_files ) ) {
		return $stats;
	}

	// Remover duplicatas.
	$unique_files              = array_unique( $uploaded_files );
	$stats['duplicates_found'] = count( $uploaded_files ) - count( $unique_files );

	if ( $stats['duplicates_found'] > 0 ) {
		$uploaded_files = array_values( $unique_files );
		codir2me_cdn_log(
			sprintf( 'Removidas %d duplicatas da lista de arquivos enviados', $stats['duplicates_found'] ),
			'info'
		);
	}

	// Verificar se arquivos ainda existem no sistema de arquivos.
	$valid_files = array();
	foreach ( $uploaded_files as $file_path ) {
		$full_path = ABSPATH . $file_path;

		if ( file_exists( $full_path ) ) {
			$valid_files[] = $file_path;

			// Verificar se há timestamp, se não criar um.
			if ( ! isset( $upload_timestamps[ $file_path ] ) ) {
				$upload_timestamps[ $file_path ] = time();
				++$stats['missing_timestamps'];
			}
		} else {
			++$stats['files_removed'];
			// Remover timestamp de arquivo que não existe mais.
			unset( $upload_timestamps[ $file_path ] );
		}
	}

	// Remover timestamps órfãos (sem arquivo correspondente).
	$orphaned_timestamps = 0;
	foreach ( $upload_timestamps as $file_path => $timestamp ) {
		if ( ! in_array( $file_path, $valid_files, true ) ) {
			unset( $upload_timestamps[ $file_path ] );
			++$orphaned_timestamps;
		}
	}

	// Salvar apenas se houve alterações.
	$total_changes = $stats['files_removed'] + $stats['duplicates_found'] + $stats['missing_timestamps'] + $orphaned_timestamps;

	if ( $total_changes > 0 ) {
		update_option( 'codir2me_uploaded_files', $valid_files );
		update_option( 'codir2me_upload_timestamps', $upload_timestamps );

		codir2me_cdn_log(
			sprintf(
				'Lista de arquivos validada: %d arquivos removidos (não existem), %d duplicatas removidas, %d timestamps corrigidos, %d timestamps órfãos removidos',
				$stats['files_removed'],
				$stats['duplicates_found'],
				$stats['missing_timestamps'],
				$orphaned_timestamps
			),
			'info'
		);
	}

	$stats['final_count'] = count( $valid_files );
	return $stats;
}

/**
 * Executa o escaneamento de arquivos estáticos com validação prévia
 * Esta é uma versão melhorada que valida os dados antes do escaneamento
 *
 * @return array Resultado do escaneamento
 */
function codir2me_scan_static_files_with_validation() {
	// Validar lista de arquivos antes do escaneamento.
	$validation_stats = codir2me_validate_and_fix_uploaded_files_list();

	codir2me_cdn_log( 'Iniciando escaneamento de arquivos estáticos', 'debug' );

	// Executar escaneamento normal.
	$scan_result = codir2me_scan_static_files();

	// Adicionar estatísticas de validação ao resultado.
	if ( isset( $scan_result['stats'] ) ) {
		$scan_result['stats']['validation'] = $validation_stats;
	}

	return $scan_result;
}

/**
 * Limpar eventos cron na desativação
 */
function codir2me_cleanup_resend_cron() {
	$timestamp = wp_next_scheduled( 'codir2me_background_resend_event' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'codir2me_background_resend_event' );
	}
	delete_option( 'codir2me_pending_resend_paths' );
}

// Configura tracking após o WordPress estar pronto.
add_action(
	'init',
	function () {
		// Verificar se funções de tracking já foram carregadas.
		if ( ! function_exists( 'codir2me_tracking_setup' ) ) {
			// Incluir o sistema de tracking universal.
			require_once CODIR2ME_CDN_INCLUDES_DIR . 'class-codir2me-tracking-system.php';
		}

		// Configurar tracking para o R2 CDN.
		codir2me_tracking_setup(
			CODIR2ME_CDN_PLUGIN_FILE,
			array(
				'name' => 'Codirun R2 Media & Static CDN',
				'slug' => 'codirun-codir2me-cdn',
			)
		);
	},
	11
); // Prioridade 11 para executar depois das traduções.

// Adicionar aviso sobre o AsyncAws SDK.
add_action( 'admin_notices', 'codir2me_static_media_sdk_notice' );

// Verificar e remover a notificação SDK quando o AsyncAws estiver disponível.
add_action( 'admin_init', 'codir2me_static_media_check_sdk_availability' );

// Adicionar suporte para MIME type AVIF.
add_action( 'init', 'codir2me_add_avif_mime' );

// Adicionar cabeçalhos corretos para AVIF.
add_action( 'wp_loaded', 'codir2me_setup_avif_headers' );

// Registrar o evento cron.
add_action( 'codir2me_background_resend_event', 'codir2me_background_resend_files' );

// Aplicar versionamento aos scripts e estilos.
add_filter( 'script_loader_src', 'codir2me_add_version_to_static_urls', 10, 2 );
add_filter( 'style_loader_src', 'codir2me_add_version_to_static_urls', 10, 2 );

// Limpar na desativação (se não existir ainda).
register_deactivation_hook( __FILE__, 'codir2me_cleanup_resend_cron' );