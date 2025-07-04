<?php
/**
 * Classe responsável pelo sistema de atualização via GitHub Releases
 * 
 * @package Codirun_R2_Media_Static_CDN
 * @since 1.0.5
 */

// Evitar acesso direto ao arquivo.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classe CODIR2ME_GitHub_Updater
 * 
 * Gerencia atualizações do plugin através do GitHub Releases
 */
class CODIR2ME_GitHub_Updater {
	
	/**
	 * Usuário do GitHub (seu nome de usuário)
	 * 
	 * @var string
	 */
	private $github_username;
	
	/**
	 * Nome do repositório no GitHub
	 * 
	 * @var string
	 */
	private $github_repo;
	
	/**
	 * Slug do plugin
	 * 
	 * @var string
	 */
	private $plugin_slug;
	
	/**
	 * Versão atual do plugin
	 * 
	 * @var string
	 */
	private $current_version;
	
	/**
	 * Arquivo principal do plugin
	 * 
	 * @var string
	 */
	private $plugin_file;
	
	/**
	 * Token do GitHub (opcional, para repositórios privados)
	 * 
	 * @var string
	 */
	private $github_token;
	
	/**
	 * Construtor da classe
	 * 
	 * @param string $github_username Nome do usuário no GitHub.
	 * @param string $github_repo Nome do repositório.
	 * @param string $plugin_file Arquivo principal do plugin.
	 * @param string $current_version Versão atual do plugin.
	 * @param string $github_token Token do GitHub (opcional).
	 */
	public function __construct( $github_username, $github_repo, $plugin_file, $current_version, $github_token = '' ) {
		$this->github_username = $github_username;
		$this->github_repo = $github_repo;
		$this->plugin_file = $plugin_file;
		$this->current_version = $current_version;
		$this->plugin_slug = plugin_basename( $plugin_file );
		$this->github_token = $github_token;
		
		$this->codir2me_init_hooks();
	}
	
	/**
	 * Inicializa os hooks necessários
	 */
	private function codir2me_init_hooks() {
		// Hook para verificar atualizações.
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'codir2me_check_for_updates' ) );
		
		// Hook para fornecer informações do plugin.
		add_filter( 'plugins_api', array( $this, 'codir2me_plugin_info' ), 10, 3 );
		
		// Hook para após a atualização.
		add_action( 'upgrader_process_complete', array( $this, 'codir2me_after_update' ), 10, 2 );
		
		// Verificação diária automática.
		add_action( 'wp', array( $this, 'codir2me_schedule_update_check' ) );
		add_action( 'codir2me_daily_update_check', array( $this, 'codir2me_force_update_check' ) );
	}
	
	/**
	 * Verifica se há atualizações disponíveis
	 * 
	 * @param object $transient Transient do WordPress.
	 * @return object Transient modificado
	 */
	public function codir2me_check_for_updates( $transient ) {
		// Verificar nonce para operações admin.
		if ( is_admin() && isset( $_GET['action'] ) && 'update-selected' === $_GET['action'] ) {
			if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'bulk-update' ) ) {
				return $transient;
			}
		}
		
		if ( empty( $transient->checked ) ) {
			return $transient;
		}
		
		// Verificar se nosso plugin está na lista.
		if ( ! isset( $transient->checked[ $this->plugin_slug ] ) ) {
			return $transient;
		}
		
		// Obter informações da última versão.
		$latest_version_info = $this->codir2me_get_latest_version_info();
		
		if ( $latest_version_info && version_compare( $this->current_version, $latest_version_info['version'], '<' ) ) {
			// Há uma atualização disponível.
			$transient->response[ $this->plugin_slug ] = (object) array(
				'slug' => dirname( $this->plugin_slug ),
				'new_version' => $latest_version_info['version'],
				'url' => $latest_version_info['details_url'],
				'package' => $latest_version_info['download_url'],
				'requires' => '6.0',
				'tested' => get_bloginfo( 'version' ),
				'requires_php' => '8.2',
				'compatibility' => array(),
				'upgrade_notice' => $latest_version_info['upgrade_notice']
			);
			
			// Log da atualização encontrada.
			codir2me_cdn_log( 
				sprintf( 
					'Atualização encontrada: v%s -> v%s', 
					$this->current_version, 
					$latest_version_info['version'] 
				), 
				'info' 
			);
		}
		
		return $transient;
	}
	
	/**
	 * Obtém informações da última versão do GitHub
	 * 
	 * @return array|false Informações da versão ou false se não encontrar
	 */
	private function codir2me_get_latest_version_info() {
		// Verificar cache primeiro.
		$cache_key = 'codir2me_github_update_' . md5( $this->github_username . $this->github_repo );
		$cached_info = get_transient( $cache_key );
		
		if ( false !== $cached_info ) {
			return $cached_info;
		}
		
		// URL da API do GitHub.
		$api_url = sprintf(
			'https://api.github.com/repos/%s/%s/releases/latest',
			$this->github_username,
			$this->github_repo
		);
		
		// Preparar headers da requisição.
		$headers = array(
			'Accept' => 'application/vnd.github.v3+json',
			'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' )
		);
		
		// Adicionar token se fornecido (para repositórios privados).
		if ( ! empty( $this->github_token ) ) {
			$headers['Authorization'] = 'token ' . $this->github_token;
		}
		
		$args = array(
			'headers' => $headers,
			'timeout' => 15,
			'sslverify' => true
		);
		
		$response = wp_remote_get( $api_url, $args );
		
		if ( is_wp_error( $response ) ) {
			codir2me_cdn_log( 'Erro ao verificar atualização no GitHub: ' . $response->get_error_message(), 'error' );
			return false;
		}
		
		$response_code = wp_remote_retrieve_response_code( $response );
		
		if ( 200 !== $response_code ) {
			codir2me_cdn_log( 'GitHub API retornou código: ' . $response_code, 'error' );
			return false;
		}
		
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		
		if ( ! isset( $data['tag_name'] ) ) {
			codir2me_cdn_log( 'Resposta inválida da API do GitHub', 'error' );
			return false;
		}
		
		// Processar dados da versão.
		$version = ltrim( $data['tag_name'], 'v' ); // Remove 'v' do início se existir.
		$download_url = '';
		
		// Procurar pelo arquivo ZIP nos assets.
		if ( isset( $data['assets'] ) && is_array( $data['assets'] ) ) {
			foreach ( $data['assets'] as $asset ) {
				if ( isset( $asset['name'] ) && strpos( $asset['name'], '.zip' ) !== false ) {
					$download_url = $asset['browser_download_url'];
					break;
				}
			}
		}
		
		// Se não encontrou ZIP nos assets, usar o zipball_url.
		if ( empty( $download_url ) ) {
			$download_url = $data['zipball_url'];
		}
		
		$version_info = array(
			'version' => $version,
			'download_url' => $download_url,
			'details_url' => $data['html_url'],
			'upgrade_notice' => isset( $data['body'] ) ? wp_strip_all_tags( $data['body'] ) : '',
			'last_updated' => $data['published_at']
		);
		
		// Cachear por 12 horas.
		set_transient( $cache_key, $version_info, 12 * HOUR_IN_SECONDS );
		
		return $version_info;
	}
	
	/**
	 * Fornece informações detalhadas do plugin
	 * 
	 * @param false|object|array $result Resultado da API.
	 * @param string $action Ação da API.
	 * @param object $args Argumentos da API.
	 * @return false|object|array Resultado modificado
	 */
	public function codir2me_plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}
		
		if ( ! isset( $args->slug ) || $args->slug !== dirname( $this->plugin_slug ) ) {
			return $result;
		}
		
		$latest_version_info = $this->codir2me_get_latest_version_info();
		
		if ( ! $latest_version_info ) {
			return $result;
		}
		
		$plugin_info = new stdClass();
		$plugin_info->name = 'Codirun R2 Media & Static CDN';
		$plugin_info->slug = dirname( $this->plugin_slug );
		$plugin_info->version = $latest_version_info['version'];
		$plugin_info->author = '<a href="https://codirun.com">Codirun</a>';
		$plugin_info->homepage = 'https://codirun.com/r2cdn';
		$plugin_info->short_description = 'Uploads static files (JS, CSS, SVG, fonts) and images to Cloudflare R2, changing their URLs to point to the CDN.';
		$plugin_info->sections = array(
			'description' => 'Uploads static files (JS, CSS, SVG, fonts) and images to Cloudflare R2, changing their URLs to point to the CDN.',
			'changelog' => $latest_version_info['upgrade_notice']
		);
		$plugin_info->download_link = $latest_version_info['download_url'];
		$plugin_info->requires = '6.0';
		$plugin_info->tested = get_bloginfo( 'version' );
		$plugin_info->requires_php = '8.2';
		$plugin_info->last_updated = $latest_version_info['last_updated'];
		
		return $plugin_info;
	}
	
	/**
	 * Agenda verificação diária de atualizações
	 */
	public function codir2me_schedule_update_check() {
		if ( ! wp_next_scheduled( 'codir2me_daily_update_check' ) ) {
			wp_schedule_event( time(), 'daily', 'codir2me_daily_update_check' );
		}
	}
	
	/**
	 * Força verificação de atualização (limpa cache)
	 */
	public function codir2me_force_update_check() {
		$cache_key = 'codir2me_github_update_' . md5( $this->github_username . $this->github_repo );
		delete_transient( $cache_key );
		
		// Força WordPress a verificar atualizações.
		wp_clean_plugins_cache();
		delete_site_transient( 'update_plugins' );
	}
	
	/**
	 * Executado após a atualização do plugin
	 * 
	 * @param WP_Upgrader $upgrader Instância do upgrader.
	 * @param array $hook_extra Informações extras sobre a atualização.
	 */
	public function codir2me_after_update( $upgrader, $hook_extra ) {
		if ( ! isset( $hook_extra['type'] ) || 'plugin' !== $hook_extra['type'] ) {
			return;
		}
		
		if ( ! isset( $hook_extra['action'] ) || 'update' !== $hook_extra['action'] ) {
			return;
		}
		
		if ( ! isset( $hook_extra['plugins'] ) ) {
			return;
		}
		
		// Verificar se nosso plugin foi atualizado.
		if ( in_array( $this->plugin_slug, $hook_extra['plugins'], true ) ) {
			// Limpar cache após atualização.
			$cache_key = 'codir2me_github_update_' . md5( $this->github_username . $this->github_repo );
			delete_transient( $cache_key );
			
			// Log da atualização concluída.
			codir2me_cdn_log( 
				sprintf( 
					'Plugin atualizado com sucesso via GitHub. Nova versão instalada.' 
				), 
				'info' 
			);
		}
	}
}