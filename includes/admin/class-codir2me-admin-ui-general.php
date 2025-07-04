<?php
/**
 * Classe que gerencia a interface de administração das configurações gerais
 * Atualizada para funcionar com AsyncAws S3 Client
 *
 * @package Codirun_R2_Media_Static_CDN
 */

// Evitar acesso direto ao arquivo.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classe responsável pela interface de administração das configurações gerais.
 */
class CODIR2ME_Admin_UI_General {
	/**
	 * Instância da classe de administração.
	 *
	 * @var codir2me_Admin
	 */
	private $admin;

	/**
	 * Construtor.
	 *
	 * @param codir2me_Admin $admin Instância da classe de administração.
	 */
	public function __construct( $admin ) {
		$this->admin = $admin;
	}

	/**
	 * Registra e carrega os scripts e estilos necessários.
	 */
	private function codir2me_enqueue_assets() {
		// Registra e carrega o CSS.
		wp_enqueue_style(
			'codir2me-admin-general-styles',
			CODIR2ME_CDN_PLUGIN_URL . 'assets/css/admin-general-styles.css',
			array(),
			CODIR2ME_CDN_VERSION
		);

		// Registra e carrega o JavaScript.
		wp_register_script(
			'codir2me-admin-general-scripts',
			CODIR2ME_CDN_PLUGIN_URL . 'assets/js/admin-general-scripts.js',
			array( 'jquery' ),
			CODIR2ME_CDN_VERSION,
			true
		);

		// Tradução para scripts.
		$translation_array = array(
			'debug_active'         => __( 'Ativo', 'codirun-codir2me-cdn' ),
			'debug_inactive'       => __( 'Inativo', 'codirun-codir2me-cdn' ),
			'copy_error'           => __( 'Erro ao copiar texto:', 'codirun-codir2me-cdn' ),
			'fallback_error'       => __( 'Erro ao executar fallback de cópia:', 'codirun-codir2me-cdn' ),
			'check_error'          => __( 'Erro ao verificar ambiente:', 'codirun-codir2me-cdn' ),
			'ajax_error'           => __( 'Erro ao processar a requisição. Por favor, tente novamente.', 'codirun-codir2me-cdn' ),
			'compatible'           => __( 'Compatível', 'codirun-codir2me-cdn' ),
			'partially_compatible' => __( 'Parcialmente Compatível', 'codirun-codir2me-cdn' ),
			'not_compatible'       => __( 'Não Compatível', 'codirun-codir2me-cdn' ),
			'overall_status'       => __( 'Status Geral:', 'codirun-codir2me-cdn' ),
			'missing_extensions'   => __( 'Extensões necessárias faltando:', 'codirun-codir2me-cdn' ),
			'missing_recommended'  => __( 'Extensões recomendadas faltando:', 'codirun-codir2me-cdn' ),
			'problem_dirs'         => __( 'Diretórios com problemas:', 'codirun-codir2me-cdn' ),
			'error_details'        => __( 'Detalhes do erro:', 'codirun-codir2me-cdn' ),
			'error_code'           => __( 'Código:', 'codirun-codir2me-cdn' ),
			'error_message'        => __( 'Mensagem:', 'codirun-codir2me-cdn' ),
			'recommendations'      => __( 'Recomendações:', 'codirun-codir2me-cdn' ),
			'current'              => __( 'Atual:', 'codirun-codir2me-cdn' ),
			'recommended'          => __( 'Recomendado:', 'codirun-codir2me-cdn' ),
		);
		wp_localize_script( 'codir2me-admin-general-scripts', 'codir2me_admin_vars', $translation_array );

		// Carrega o script.
		wp_enqueue_script( 'codir2me-admin-general-scripts' );
	}

	/**
	 * Renderiza a interface principal das configurações gerais.
	 */
	public function codir2me_render() {
		// Carrega os scripts e estilos.
		$this->codir2me_enqueue_assets();

		// Processar teste de conexão se formulário enviado.
		if ( isset( $_POST['codir2me_test_connection'] ) && check_admin_referer( 'codir2me_test_connection_action', 'codir2me_test_connection_nonce' ) ) {
			$connection_result = $this->codir2me_test_connection();
		}

		$this->codir2me_handle_connection_settings_form();
		$this->codir2me_handle_advanced_settings_form();

		?>
		
		<div class="codir2me-tab-content">
		<!-- Seção de Configurações de Conexão com layout flexível -->
		<div class="codir2me-flex-container">
			<div class="codir2me-main-column">
				<div class="codir2me-section">
					<h2><?php esc_html_e( 'Configurações de Conexão com o R2', 'codirun-codir2me-cdn' ); ?></h2>
					<form method="post" action="" id="codir2me-connection-form">
						<?php
						// ALTERAÇÃO: Usar group específico apenas para configurações de conexão.
						wp_nonce_field( 'codir2me_connection_action', 'codir2me_connection_nonce', false );
						?>
						<table class="form-table">
							<tr>
								<th><?php esc_html_e( 'R2 Access Key', 'codirun-codir2me-cdn' ); ?></th>
								<td><input type="text" name="codir2me_access_key" value="<?php echo esc_attr( get_option( 'codir2me_access_key' ) ); ?>" class="regular-text" /></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'R2 Secret Key', 'codirun-codir2me-cdn' ); ?></th>
								<td><input type="password" name="codir2me_secret_key" value="<?php echo esc_attr( get_option( 'codir2me_secret_key' ) ); ?>" class="regular-text" /></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'R2 Bucket', 'codirun-codir2me-cdn' ); ?></th>
								<td><input type="text" name="codir2me_bucket" value="<?php echo esc_attr( get_option( 'codir2me_bucket' ) ); ?>" class="regular-text" /></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'R2 Endpoint URL', 'codirun-codir2me-cdn' ); ?></th>
								<td><input type="text" name="codir2me_endpoint" value="<?php echo esc_attr( get_option( 'codir2me_endpoint' ) ); ?>" class="regular-text" placeholder="https://xxxxx.r2.cloudflarestorage.com" /></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'CDN URL', 'codirun-codir2me-cdn' ); ?></th>
								<td><input type="text" name="codir2me_cdn_url" value="<?php echo esc_attr( get_option( 'codir2me_cdn_url' ) ); ?>" class="regular-text" placeholder="https://cdn.seudominio.com" /></td>
							</tr>
						</table>
						<?php
						submit_button(
							__( 'Salvar Configurações de Conexão', 'codirun-codir2me-cdn' ),
							'primary',
							'submit_connection',
							true,
							array( 'id' => 'submit-connection-form' )
						);
						?>
					</form>

					<!-- Teste de Conexão R2 -->
					<div class="codir2me-section" style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #f0f0f0;">
						<h3><?php esc_html_e( 'Teste de Conexão R2', 'codirun-codir2me-cdn' ); ?></h3>
						<form method="post" action="" id="codir2me-connection-test-form">
							<?php wp_nonce_field( 'codir2me_test_connection_action', 'codir2me_test_connection_nonce', false ); ?>
							<input type="submit" name="codir2me_test_connection" class="button button-secondary" value="<?php esc_attr_e( 'Testar Conexão com R2', 'codirun-codir2me-cdn' ); ?>">
						</form>

						<?php if ( isset( $connection_result ) ) : ?>
							<div class="codir2me-connection-result" style="margin-top: 15px; padding: 10px; border-radius: 5px; 
								background-color: <?php echo $connection_result['success'] ? '#e7f4e4' : '#f4e4e4'; ?>">
								<strong><?php esc_html_e( 'Resultado do Teste:', 'codirun-codir2me-cdn' ); ?></strong>
								<p style="color: <?php echo $connection_result['success'] ? 'green' : 'red'; ?>">
									<?php echo esc_html( $connection_result['message'] ); ?>
								</p>
								<?php if ( ! $connection_result['success'] && ! empty( $connection_result['details'] ) ) : ?>
									<details>
										<summary><?php esc_html_e( 'Detalhes do Erro', 'codirun-codir2me-cdn' ); ?></summary>
										<pre style="max-width: 100%; max-height: 300px; overflow: auto; white-space: pre-wrap; word-wrap: break-word; padding: 10px; background-color: #f5f5f5; border: 1px solid #ccc; font-family: monospace;">
											<?php echo esc_html( $connection_result['details'] ); ?></pre>
									</details>
								<?php endif; ?>
							</div>
						<?php endif; ?>
					</div>
				</div>
				
				<!-- Seção de verificação de compatibilidade -->
				<?php $this->codir2me_render_environment_check_section(); ?>
				
				<!-- ALTERAÇÃO: Seção de Configurações Avançadas agora com formulário separado -->
				<div class="codir2me-section" style="margin-top: 20px;">
					<h2><?php esc_html_e( 'Configurações Avançadas', 'codirun-codir2me-cdn' ); ?></h2>
					
					<!-- NOVO: Formulário separado para configurações avançadas -->
					<form method="post" action="options.php" id="codir2me-advanced-settings-form">
						<?php
						settings_fields( 'codir2me_general_settings' );

						// Manter os valores das configurações de conexão.
						$codir2me_access_key = get_option( 'codir2me_access_key' );
						$codir2me_secret_key = get_option( 'codir2me_secret_key' );
						$codir2me_bucket     = get_option( 'codir2me_bucket' );
						$codir2me_endpoint   = get_option( 'codir2me_endpoint' );
						$codir2me_cdn_url    = get_option( 'codir2me_cdn_url' );
						?>
						
						<input type="hidden" name="codir2me_access_key" value="<?php echo esc_attr( $codir2me_access_key ); ?>">
						<input type="hidden" name="codir2me_secret_key" value="<?php echo esc_attr( $codir2me_secret_key ); ?>">
						<input type="hidden" name="codir2me_bucket" value="<?php echo esc_attr( $codir2me_bucket ); ?>">
						<input type="hidden" name="codir2me_endpoint" value="<?php echo esc_attr( $codir2me_endpoint ); ?>">
						<input type="hidden" name="codir2me_cdn_url" value="<?php echo esc_attr( $codir2me_cdn_url ); ?>">
						
						<table class="form-table">
							<tr>
								<th><?php esc_html_e( 'Modo de Depuração', 'codirun-codir2me-cdn' ); ?></th>
								<td>
									<label class="codir2me-toggle-switch">
										<input type="checkbox" id="codir2me-debug-mode" name="codir2me_debug_mode" value="1" <?php checked( get_option( 'codir2me_debug_mode', false ) ); ?> />
										<span class="codir2me-toggle-slider"></span>
									</label>
									<span class="codir2me-debug-status <?php echo get_option( 'codir2me_debug_mode', false ) ? 'active' : 'inactive'; ?>">
										<?php echo get_option( 'codir2me_debug_mode', false ) ? esc_html__( 'Ativo', 'codirun-codir2me-cdn' ) : esc_html__( 'Inativo', 'codirun-codir2me-cdn' ); ?>
									</span>
									<p class="description" style="margin-top: 10px;"><?php esc_html_e( 'Ativar o modo de depuração para gerar logs detalhados.', 'codirun-codir2me-cdn' ); ?></p>
								</td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Limpeza de Logs', 'codirun-codir2me-cdn' ); ?></th>
								<td>
									<label class="codir2me-toggle-switch">
										<input type="checkbox" name="codir2me_clean_logs_on_deactivate" value="1" <?php checked( get_option( 'codir2me_clean_logs_on_deactivate', false ) ); ?> />
										<span class="codir2me-toggle-slider"></span>
									</label>
									<span class="description"><?php esc_html_e( 'Limpar logs ao desativar o plugin.', 'codirun-codir2me-cdn' ); ?></span>
								</td>
							</tr>
							<?php if ( get_option( 'codir2me_debug_mode', false ) && file_exists( CODIR2ME_CDN_LOGS_DIR . 'debug.log' ) ) : ?>
							<tr class="codir2me-log-settings">
								<th><?php esc_html_e( 'Arquivo de Log', 'codirun-codir2me-cdn' ); ?></th>
								<td>
									<div class="codir2me-log-info">
										<code>
										<?php
										$upload_dir = wp_upload_dir();
										$log_path   = str_replace( $upload_dir['basedir'], $upload_dir['baseurl'], CODIR2ME_CDN_LOGS_DIR . 'debug.log' );
										$log_path   = str_replace( home_url(), '', $log_path );
										echo esc_html( $log_path );
										?>
										</code>
										<span class="codir2me-log-size"><?php echo esc_html( size_format( filesize( CODIR2ME_CDN_LOGS_DIR . 'debug.log' ) ) ); ?></span>
									</div>
									<div class="codir2me-log-actions">
										<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=codir2me_download_log' ), 'codir2me_download_log' ) ); ?>" class="button">
											<span class="dashicons dashicons-download"></span>
											<?php esc_html_e( 'Baixar Log', 'codirun-codir2me-cdn' ); ?>
										</a>
										<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=codir2me_clear_log' ), 'codir2me_clear_log' ) ); ?>" class="button codir2me-clear-log-btn">
											<span class="dashicons dashicons-trash"></span>
											<?php esc_html_e( 'Limpar Log', 'codirun-codir2me-cdn' ); ?>
										</a>
									</div>
									
									<?php
									if ( filesize( CODIR2ME_CDN_LOGS_DIR . 'debug.log' ) < 1048576 ) :
										// Mostrar prévia se o arquivo for menor que 1MB.
										?>
									<div class="codir2me-log-preview-container" style="margin-top: 20px;">
										<h4><?php esc_html_e( 'Prévia do Log', 'codirun-codir2me-cdn' ); ?></h4>
										<div class="codir2me-log-viewer" style="font-family: monospace;">
											<pre class="codir2me-log-preview">
											<?php
												$log_content = $this->codir2me_get_log_content_safely();
											if ( $log_content ) {
												// Colorir diferentes tipos de log.
												$log_content = preg_replace( '/\[(.*?)\] \[debug\]/', '<span class="log-timestamp">[$1]</span> <span class="log-debug">[debug]</span>', $log_content );
												$log_content = preg_replace( '/\[(.*?)\] \[info\]/', '<span class="log-timestamp">[$1]</span> <span class="log-info">[info]</span>', $log_content );
												$log_content = preg_replace( '/\[(.*?)\] \[error\]/', '<span class="log-timestamp">[$1]</span> <span class="log-error">[error]</span>', $log_content );

												// Escapar, mas permitir HTML seguro.
												echo wp_kses(
													$log_content,
													array(
														'span' => array(
															'class' => array(),
														),
													)
												);
											} else {
												echo esc_html__( 'Nenhum log encontrado ou arquivo vazio.', 'codirun-codir2me-cdn' );
											}
											?>
											</pre>
										</div>
									</div>
								<?php endif; ?>

								</td>
							</tr>
							<?php endif; ?>
						</table>
						<?php submit_button( __( 'Salvar Configurações Avançadas', 'codirun-codir2me-cdn' ), 'primary', 'submit', false ); ?>
					</form>
				</div>
				
				<?php $this->codir2me_render_system_info_section(); ?>
				
				<!-- Aviso sobre AsyncAws SDK -->
				<?php if ( ! $this->codir2me_is_asyncaws_sdk_available() ) : ?>
				<div class="codir2me-aws-sdk-warning">
					<div class="notice notice-warning">
						<p><strong><?php esc_html_e( 'AsyncAws S3 SDK Necessário', 'codirun-codir2me-cdn' ); ?></strong></p>
						<p><?php esc_html_e( 'O plugin R2 Static & Media CDN agora usa o AsyncAws S3 Client para melhor performance. Por favor, instale o SDK executando:', 'codirun-codir2me-cdn' ); ?></p>
						<code>composer require async-aws/s3</code>
						<p><?php esc_html_e( 'Ou baixe e extraia manualmente na pasta vendor/ do plugin.', 'codirun-codir2me-cdn' ); ?></p>
					</div>
				</div>
				<?php endif; ?>
			
			</div>
			
			<div class="codir2me-sidebar">
				<!-- Widget de informações sobre conexão R2 -->
				<div class="codir2me-sidebar-widget">
					<h3><?php esc_html_e( 'Sobre as Configurações de Conexão', 'codirun-codir2me-cdn' ); ?></h3>
					<div class="codir2me-widget-content">
						<p><?php esc_html_e( 'O Cloudflare R2 é um serviço de armazenamento de objetos que substitui o Amazon S3 tradicional, oferecendo uma alternativa sem taxas de saída.', 'codirun-codir2me-cdn' ); ?></p>
						
						<p><strong><?php esc_html_e( 'Campos obrigatórios:', 'codirun-codir2me-cdn' ); ?></strong></p>
						<ul class="codir2me-tips-list">
							<li><span class="dashicons dashicons-admin-network"></span> <strong><?php esc_html_e( 'R2 Access Key:', 'codirun-codir2me-cdn' ); ?></strong> <?php esc_html_e( 'Credencial de acesso ao seu bucket R2.', 'codirun-codir2me-cdn' ); ?></li>
							<li><span class="dashicons dashicons-lock"></span> <strong><?php esc_html_e( 'R2 Secret Key:', 'codirun-codir2me-cdn' ); ?></strong> <?php esc_html_e( 'Chave secreta para autenticação segura.', 'codirun-codir2me-cdn' ); ?></li>
							<li><span class="dashicons dashicons-database"></span> <strong><?php esc_html_e( 'R2 Bucket:', 'codirun-codir2me-cdn' ); ?></strong> <?php esc_html_e( 'Nome do seu bucket no Cloudflare R2.', 'codirun-codir2me-cdn' ); ?></li>
							<li><span class="dashicons dashicons-admin-site"></span> <strong><?php esc_html_e( 'R2 Endpoint URL:', 'codirun-codir2me-cdn' ); ?></strong> <?php esc_html_e( 'URL do endpoint fornecido pelo Cloudflare.', 'codirun-codir2me-cdn' ); ?></li>
							<li><span class="dashicons dashicons-admin-links"></span> <strong><?php esc_html_e( 'CDN URL:', 'codirun-codir2me-cdn' ); ?></strong> <?php esc_html_e( 'URL personalizada para entregar seus arquivos.', 'codirun-codir2me-cdn' ); ?></li>
						</ul>
						
						<p><strong><?php esc_html_e( 'Como obter suas credenciais:', 'codirun-codir2me-cdn' ); ?></strong></p>
						<ol class="codir2me-instructions-list">
							<li><?php esc_html_e( 'Acesse o painel do Cloudflare.', 'codirun-codir2me-cdn' ); ?></li>
							<li><?php esc_html_e( 'Navegue até R2 > Gerenciar Buckets.', 'codirun-codir2me-cdn' ); ?></li>
							<li><?php esc_html_e( 'Clique em "Gerenciar API Tokens".', 'codirun-codir2me-cdn' ); ?></li>
							<li><?php esc_html_e( 'Crie ou utilize um token existente.', 'codirun-codir2me-cdn' ); ?></li>
						</ol>
					</div>
				</div>
				
				<!-- Widget de informações sobre verificação de compatibilidade -->
				<div class="codir2me-sidebar-widget" style="margin-top: 20px;">
					<h3><?php esc_html_e( 'Sobre a Verificação de Compatibilidade', 'codirun-codir2me-cdn' ); ?></h3>
					<div class="codir2me-widget-content">
						<p><?php esc_html_e( 'A Verificação de Compatibilidade ajuda a identificar problemas potenciais que podem afetar o funcionamento adequado do plugin.', 'codirun-codir2me-cdn' ); ?></p>
						
						<p><strong><?php esc_html_e( 'Itens verificados:', 'codirun-codir2me-cdn' ); ?></strong></p>
						<ul class="codir2me-tips-list">
							<li><span class="dashicons dashicons-admin-tools"></span> <strong><?php esc_html_e( 'Versão do PHP:', 'codirun-codir2me-cdn' ); ?></strong> <?php esc_html_e( 'Verifica se seu PHP atende aos requisitos mínimos.', 'codirun-codir2me-cdn' ); ?></li>
							<li><span class="dashicons dashicons-wordpress"></span> <strong><?php esc_html_e( 'Versão do WordPress:', 'codirun-codir2me-cdn' ); ?></strong> <?php esc_html_e( 'Confirma compatibilidade com sua instalação.', 'codirun-codir2me-cdn' ); ?></li>
							<li><span class="dashicons dashicons-admin-plugins"></span> <strong><?php esc_html_e( 'Extensões PHP:', 'codirun-codir2me-cdn' ); ?></strong> <?php esc_html_e( 'Verifica se todas as extensões necessárias estão instaladas.', 'codirun-codir2me-cdn' ); ?></li>
							<li><span class="dashicons dashicons-lock"></span> <strong><?php esc_html_e( 'Permissões:', 'codirun-codir2me-cdn' ); ?></strong> <?php esc_html_e( 'Verifica permissões de diretórios essenciais.', 'codirun-codir2me-cdn' ); ?></li>
							<li><span class="dashicons dashicons-admin-site"></span> <strong><?php esc_html_e( 'AsyncAws SDK:', 'codirun-codir2me-cdn' ); ?></strong> <?php esc_html_e( 'Confirma a instalação correta do AsyncAws S3.', 'codirun-codir2me-cdn' ); ?></li>
							<li><span class="dashicons dashicons-external"></span> <strong><?php esc_html_e( 'Conexão R2:', 'codirun-codir2me-cdn' ); ?></strong> <?php esc_html_e( 'Testa a comunicação com o Cloudflare R2.', 'codirun-codir2me-cdn' ); ?></li>
						</ul>
						
						<p><strong><?php esc_html_e( 'Quando usar:', 'codirun-codir2me-cdn' ); ?></strong></p>
						<ul class="codir2me-tips-list">
							<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Após a instalação para verificar a compatibilidade.', 'codirun-codir2me-cdn' ); ?></li>
							<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Quando enfrentar problemas com o plugin.', 'codirun-codir2me-cdn' ); ?></li>
							<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Após atualizar seu WordPress ou PHP.', 'codirun-codir2me-cdn' ); ?></li>
							<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Ao migrar para um novo servidor.', 'codirun-codir2me-cdn' ); ?></li>
						</ul>
					</div>
				</div>
				
				<!-- Widget de informações sobre logs -->
				<div class="codir2me-sidebar-widget" style="margin-top: 20px;">
					<h3><?php esc_html_e( 'Sobre o Modo de Depuração', 'codirun-codir2me-cdn' ); ?></h3>
					<div class="codir2me-widget-content">
						<p><?php esc_html_e( 'O Modo de Depuração permite rastrear operações e identificar problemas no funcionamento do plugin.', 'codirun-codir2me-cdn' ); ?></p>
						
						<p><strong><?php esc_html_e( 'Quando usar:', 'codirun-codir2me-cdn' ); ?></strong></p>
						<ul class="codir2me-tips-list">
							<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Apenas quando solicitado pelo suporte técnico.', 'codirun-codir2me-cdn' ); ?></li>
							<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Para investigar problemas específicos.', 'codirun-codir2me-cdn' ); ?></li>
							<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Temporariamente durante a resolução de problemas.', 'codirun-codir2me-cdn' ); ?></li>
						</ul>
						
						<p><strong><?php esc_html_e( 'Informações importantes:', 'codirun-codir2me-cdn' ); ?></strong></p>
						<ul class="codir2me-tips-list">
							<li><span class="dashicons dashicons-warning"></span> <?php esc_html_e( 'Pode afetar o desempenho do site.', 'codirun-codir2me-cdn' ); ?></li>
							<li><span class="dashicons dashicons-warning"></span> <?php esc_html_e( 'Gera arquivos de log que ocupam espaço.', 'codirun-codir2me-cdn' ); ?></li>
							<li><span class="dashicons dashicons-warning"></span> <?php esc_html_e( 'Recomendamos desativar após resolver problemas.', 'codirun-codir2me-cdn' ); ?></li>
						</ul>
						
						<p><?php esc_html_e( 'Os logs são salvos em:', 'codirun-codir2me-cdn' ); ?> <code>wp-content/uploads/codirun-codir2me-cdn-logs/debug.log</code></p>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Manipula o formulário das configurações avançadas separadamente
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function codir2me_handle_advanced_settings_form() {
		// Verificar se o formulário de configurações avançadas foi enviado.
		if ( ! isset( $_POST['submit_advanced'] ) ) {
			return;
		}

		// Verificar nonce específico para configurações avançadas.
		if ( ! isset( $_POST['codir2me_advanced_settings_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['codir2me_advanced_settings_nonce'] ) ), 'codir2me_advanced_settings_action' ) ) {
			add_action(
				'admin_notices',
				function () {
					?>
				<div class="notice notice-error is-dismissible">
					<p><?php esc_html_e( 'Erro de segurança ao salvar as configurações avançadas. Por favor, tente novamente.', 'codirun-codir2me-cdn' ); ?></p>
				</div>
					<?php
				}
			);
			return;
		}

		// Verificar permissões.
		if ( ! current_user_can( 'manage_options' ) ) {
			add_action(
				'admin_notices',
				function () {
					?>
				<div class="notice notice-error is-dismissible">
					<p><?php esc_html_e( 'Você não tem permissão para alterar essas configurações.', 'codirun-codir2me-cdn' ); ?></p>
				</div>
					<?php
				}
			);
			return;
		}

		// Processar e salvar configurações avançadas.
		$debug_mode = isset( $_POST['codir2me_debug_mode'] ) ? true : false;
		$clean_logs = isset( $_POST['codir2me_clean_logs_on_deactivate'] ) ? true : false;

		// Salvar as configurações usando update_option para evitar conflito.
		update_option( 'codir2me_debug_mode', $debug_mode );
		update_option( 'codir2me_clean_logs_on_deactivate', $clean_logs );

		// Registrar no log se o modo de depuração estiver ativo.
		if ( $debug_mode && function_exists( 'codir2me_cdn_log' ) ) {
			codir2me_cdn_log( __( 'Configurações avançadas salvas com sucesso.', 'codirun-codir2me-cdn' ), 'info' );
		}

		// Mostrar mensagem de sucesso.
		add_action(
			'admin_notices',
			function () {
				?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'Configurações avançadas salvas com sucesso!', 'codirun-codir2me-cdn' ); ?></p>
			</div>
				<?php
			}
		);
	}


	/**
	 * Renderiza a seção de verificação de compatibilidade.
	 */
	private function codir2me_render_environment_check_section() {
		?>
		<div class="codir2me-section" style="margin-top: 20px;">
			<h2><?php esc_html_e( 'Verificação de Compatibilidade', 'codirun-codir2me-cdn' ); ?></h2>
			<p><?php esc_html_e( 'Esta ferramenta verifica se seu ambiente atende a todos os requisitos necessários para o funcionamento adequado do plugin.', 'codirun-codir2me-cdn' ); ?></p>
			
			<div class="codir2me-environment-check-container">
				<button id="codir2me-check-environment" class="button button-primary">
					<span class="dashicons dashicons-desktop"></span>
					<?php esc_html_e( 'Verificar Ambiente', 'codirun-codir2me-cdn' ); ?>
				</button>
				
				<div id="codir2me-environment-check-results" style="display: none; margin-top: 20px;">
					<!-- Resultados serão inseridos aqui via JavaScript -->
					<div class="codir2me-check-loading">
						<span class="spinner is-active"></span>
						<p><?php esc_html_e( 'Verificando ambiente, aguarde...', 'codirun-codir2me-cdn' ); ?></p>
					</div>
				</div>
			</div>
			
			<?php wp_nonce_field( 'codir2me_env_check_nonce', 'codir2me_env_check_nonce', false ); ?>
					
		</div>
		<?php
	}

	/**
	 * Renderiza a seção de informações do sistema.
	 */
	private function codir2me_render_system_info_section() {
		?>
		<div class="codir2me-section">
			<h2><?php esc_html_e( 'Informações do Sistema', 'codirun-codir2me-cdn' ); ?></h2>
			<p><?php esc_html_e( 'Estas informações são úteis para o suporte técnico. Caso tenha algum problema, por favor copie e compartilhe estas informações.', 'codirun-codir2me-cdn' ); ?></p>
			
			<div class="codir2me-system-info-container">
				<button id="codir2me-copy-system-info" class="button button-secondary">
					<span class="dashicons dashicons-clipboard"></span>
					<?php esc_html_e( 'Copiar Informações do Sistema', 'codirun-codir2me-cdn' ); ?>
				</button>
				<span id="codir2me-copy-success" style="display: none; margin-left: 10px; color: green;">
					<span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Copiado!', 'codirun-codir2me-cdn' ); ?>
				</span>
				
				<div class="codir2me-system-info-panel" style="margin-top: 15px;">
					<textarea id="codir2me-system-info" readonly style="width: 100%; height: 200px; font-family: monospace; font-size: 12px;"><?php echo esc_textarea( $this->codir2me_get_system_info() ); ?></textarea>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Manipula o formulário das configurações de conexão separadamente
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function codir2me_handle_connection_settings_form() {
		// Verificar se o formulário de configurações de conexão foi enviado.
		if ( ! isset( $_POST['submit_connection'] ) ) {
			return;
		}

		// Verificar nonce específico para configurações de conexão.
		if ( ! isset( $_POST['codir2me_connection_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['codir2me_connection_nonce'] ) ), 'codir2me_connection_action' ) ) {
			add_action(
				'admin_notices',
				function () {
					?>
				<div class="notice notice-error is-dismissible">
				<p><?php esc_html_e( 'Erro de segurança ao salvar as configurações de conexão. Por favor, tente novamente.', 'codirun-codir2me-cdn' ); ?></p>
				</div>
					<?php
				}
			);
			return;
		}

		// Verificar permissões.
		if ( ! current_user_can( 'manage_options' ) ) {
			add_action(
				'admin_notices',
				function () {
					?>
				<div class="notice notice-error is-dismissible">
				<p><?php esc_html_e( 'Você não tem permissão para alterar essas configurações.', 'codirun-codir2me-cdn' ); ?></p>
				</div>
					<?php
				}
			);
			return;
		}

		// Processar e salvar configurações de conexão.
		$access_key = isset( $_POST['codir2me_access_key'] ) ? sanitize_text_field( wp_unslash( $_POST['codir2me_access_key'] ) ) : '';
		$secret_key = isset( $_POST['codir2me_secret_key'] ) ? sanitize_text_field( wp_unslash( $_POST['codir2me_secret_key'] ) ) : '';
		$bucket     = isset( $_POST['codir2me_bucket'] ) ? sanitize_text_field( wp_unslash( $_POST['codir2me_bucket'] ) ) : '';
		$endpoint   = isset( $_POST['codir2me_endpoint'] ) ? esc_url_raw( wp_unslash( $_POST['codir2me_endpoint'] ) ) : '';
		$cdn_url    = isset( $_POST['codir2me_cdn_url'] ) ? esc_url_raw( wp_unslash( $_POST['codir2me_cdn_url'] ) ) : '';

		// Salvar as configurações.
		update_option( 'codir2me_access_key', $access_key );
		update_option( 'codir2me_secret_key', $secret_key );
		update_option( 'codir2me_bucket', $bucket );
		update_option( 'codir2me_endpoint', $endpoint );
		update_option( 'codir2me_cdn_url', $cdn_url );

		// Registrar no log se o modo de depuração estiver ativo.
		if ( get_option( 'codir2me_debug_mode' ) && function_exists( 'codir2me_cdn_log' ) ) {
			codir2me_cdn_log( __( 'Configurações de conexão salvas com sucesso.', 'codirun-codir2me-cdn' ), 'info' );
		}

		// Mostrar mensagem de sucesso.
		add_action(
			'admin_notices',
			function () {
				?>
			<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( 'Configurações de conexão salvas com sucesso!', 'codirun-codir2me-cdn' ); ?></p>
			</div>
				<?php
			}
		);
	}

	/**
	 * Obtém o conteúdo do log de forma segura usando WP_Filesystem.
	 *
	 * @return string|false Conteúdo do log ou false em caso de erro.
	 */
	private function codir2me_get_log_content_safely() {
		global $wp_filesystem;

		// Inicializar WP_Filesystem se necessário.
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();

		$log_file = CODIR2ME_CDN_LOGS_DIR . 'debug.log';

		if ( $wp_filesystem->exists( $log_file ) ) {
			return $wp_filesystem->get_contents( $log_file );
		}

		return false;
	}

	/**
	 * Coleta informações do sistema.
	 *
	 * @return string Informações do sistema formatadas.
	 */
	private function codir2me_get_system_info() {
		global $wpdb;

		// Informações do WordPress.
		$wordpress_info = array(
			__( 'Site URL', 'codirun-codir2me-cdn' )      => site_url(),
			__( 'Home URL', 'codirun-codir2me-cdn' )      => home_url(),
			__( 'WordPress Versão', 'codirun-codir2me-cdn' ) => get_bloginfo( 'version' ),
			__( 'Multisite', 'codirun-codir2me-cdn' )     => is_multisite() ? __( 'Sim', 'codirun-codir2me-cdn' ) : __( 'Não', 'codirun-codir2me-cdn' ),
			__( 'Modo de Depuração', 'codirun-codir2me-cdn' ) => ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? __( 'Ativado', 'codirun-codir2me-cdn' ) : __( 'Desativado', 'codirun-codir2me-cdn' ),
			__( 'Idioma', 'codirun-codir2me-cdn' )        => get_locale(),
			__( 'Limite de Memória', 'codirun-codir2me-cdn' ) => WP_MEMORY_LIMIT,
			__( 'Versão do PHP', 'codirun-codir2me-cdn' ) => phpversion(),
			__( 'Versão do MySQL', 'codirun-codir2me-cdn' ) => $wpdb->db_version(),
			__( 'Servidor Web', 'codirun-codir2me-cdn' )  => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : __( 'Desconhecido', 'codirun-codir2me-cdn' ),
		);

		// Informações do Plugin.
		$plugin_info = array(
			__( 'Versão do Plugin', 'codirun-codir2me-cdn' ) => CODIR2ME_CDN_VERSION,
			__( 'CDN Ativo', 'codirun-codir2me-cdn' )   => get_option( 'codir2me_is_cdn_active' ) ? __( 'Sim', 'codirun-codir2me-cdn' ) : __( 'Não', 'codirun-codir2me-cdn' ),
			__( 'CDN de Imagens Ativo', 'codirun-codir2me-cdn' ) => get_option( 'codir2me_is_images_cdn_active' ) ? __( 'Sim', 'codirun-codir2me-cdn' ) : __( 'Não', 'codirun-codir2me-cdn' ),
			__( 'Upload Auto. Miniaturas', 'codirun-codir2me-cdn' ) => get_option( 'codir2me_auto_upload_thumbnails' ) ? __( 'Sim', 'codirun-codir2me-cdn' ) : __( 'Não', 'codirun-codir2me-cdn' ),
			__( 'CDN URL', 'codirun-codir2me-cdn' )     => get_option( 'codir2me_cdn_url', __( 'Não configurado', 'codirun-codir2me-cdn' ) ),
			__( 'R2 Endpoint', 'codirun-codir2me-cdn' ) => get_option( 'codir2me_endpoint', __( 'Não configurado', 'codirun-codir2me-cdn' ) ),
			__( 'Tamanho do Lote (Estáticos)', 'codirun-codir2me-cdn' ) => get_option( 'codir2me_batch_size', '50' ),
			__( 'Tamanho do Lote (Imagens)', 'codirun-codir2me-cdn' ) => get_option( 'codir2me_images_batch_size', '20' ),
			__( 'Opção de Miniaturas', 'codirun-codir2me-cdn' ) => get_option( 'codir2me_thumbnail_option', 'all' ),
			__( 'Modo de Depuração', 'codirun-codir2me-cdn' ) => get_option( 'codir2me_debug_mode', false ) ? __( 'Ativado', 'codirun-codir2me-cdn' ) : __( 'Desativado', 'codirun-codir2me-cdn' ),
			__( 'SDK Usado', 'codirun-codir2me-cdn' )   => $this->codir2me_is_asyncaws_sdk_available() ? __( 'AsyncAws S3', 'codirun-codir2me-cdn' ) : __( 'Não disponível', 'codirun-codir2me-cdn' ),
		);

		// Otimização de imagens.
		$opt_options       = get_option( 'codir2me_image_optimization_options', array() );
		$optimization_info = array(
			__( 'Otimização Ativa', 'codirun-codir2me-cdn' ) => isset( $opt_options['enable_optimization'] ) && $opt_options['enable_optimization'] ? __( 'Sim', 'codirun-codir2me-cdn' ) : __( 'Não', 'codirun-codir2me-cdn' ),
			__( 'Conversão WebP', 'codirun-codir2me-cdn' ) => isset( $opt_options['enable_webp_conversion'] ) && $opt_options['enable_webp_conversion'] ? __( 'Sim', 'codirun-codir2me-cdn' ) : __( 'Não', 'codirun-codir2me-cdn' ),
			__( 'Conversão AVIF', 'codirun-codir2me-cdn' ) => isset( $opt_options['enable_avif_conversion'] ) && $opt_options['enable_avif_conversion'] ? __( 'Sim', 'codirun-codir2me-cdn' ) : __( 'Não', 'codirun-codir2me-cdn' ),
			__( 'Nível de Otimização', 'codirun-codir2me-cdn' ) => isset( $opt_options['optimization_level'] ) ? $opt_options['optimization_level'] : 'balanced',
		);

		// Plugins ativos.
		$active_plugins = get_option( 'active_plugins' );
		$plugins_list   = array();

		foreach ( $active_plugins as $plugin ) {
			$plugin_path    = trailingslashit( WP_CONTENT_DIR ) . 'plugins/' . $plugin;
			$plugin_data    = get_plugin_data( $plugin_path );
			$plugins_list[] = $plugin_data['Name'] . ' ' . $plugin_data['Version'];
		}

		// Formatar a saída.
		$output = '### ' . __( 'INFORMAÇÕES DO SISTEMA', 'codirun-codir2me-cdn' ) . " ###\n\n";

		$output .= '-- ' . __( 'WordPress', 'codirun-codir2me-cdn' ) . " --\n";
		foreach ( $wordpress_info as $key => $value ) {
			$output .= $key . ': ' . $value . "\n";
		}

		$output .= "\n-- " . __( 'Plugin R2 CDN', 'codirun-codir2me-cdn' ) . " --\n";
		foreach ( $plugin_info as $key => $value ) {
			$output .= $key . ': ' . $value . "\n";
		}

		$output .= "\n-- " . __( 'Otimização de Imagens', 'codirun-codir2me-cdn' ) . " --\n";
		foreach ( $optimization_info as $key => $value ) {
			$output .= $key . ': ' . $value . "\n";
		}

		$output .= "\n-- " . __( 'Plugins Ativos', 'codirun-codir2me-cdn' ) . " --\n";
		$output .= implode( "\n", $plugins_list );

		$output .= "\n\n### " . __( 'FIM DAS INFORMAÇÕES', 'codirun-codir2me-cdn' ) . ' ###';

		return $output;
	}

	/**
	 * Verifica se o AsyncAws SDK está disponível.
	 *
	 * @return bool True se disponível, False caso contrário.
	 */
	private function codir2me_is_asyncaws_sdk_available() {
		try {
			if ( ! file_exists( CODIR2ME_CDN_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
				return false;
			}

			require_once CODIR2ME_CDN_PLUGIN_DIR . 'vendor/autoload.php';

			return class_exists( 'AsyncAws\S3\S3Client' ) ||
					file_exists( CODIR2ME_CDN_PLUGIN_DIR . 'vendor/async-aws/s3/src/S3Client.php' );
		} catch ( Exception $e ) {
			return false;
		}
	}


	/**
	 * Testa a conexão com o R2
	 *
	 * @return array Resultado do teste
	 */
	private function codir2me_test_connection() {
		try {
			// Obter configurações.
			$access_key = get_option( 'codir2me_access_key' );
			$secret_key = get_option( 'codir2me_secret_key' );
			$bucket     = get_option( 'codir2me_bucket' );
			$endpoint   = get_option( 'codir2me_endpoint' );

			// Validar se as configurações existem.
			if ( empty( $access_key ) || empty( $secret_key ) || empty( $bucket ) || empty( $endpoint ) ) {
				return array(
					'success' => false,
					'message' => __( 'Configure todas as credenciais do R2 antes de testar a conexão.', 'codirun-codir2me-cdn' ),
				);
			}

			// Configurar cliente S3.
			$s3_client = new \AsyncAws\S3\S3Client(
				array(
					'endpoint'        => $endpoint,
					'accessKeyId'     => $access_key,
					'accessKeySecret' => $secret_key,
					'region'          => 'auto',
				)
			);

			// Testar conexão listando objetos do bucket.
			$response = $s3_client->listObjectsV2(
				array(
					'Bucket'  => $bucket,
					'MaxKeys' => 1,
				)
			);

			// Aguardar resposta.
			$result = $response->resolve();

			return array(
				'success' => true,
				'message' => __( 'Conexão com R2 realizada com sucesso!', 'codirun-codir2me-cdn' ),
			);

		} catch ( \AsyncAws\S3\Exception\NoSuchBucketException $e ) {
			return array(
				'success' => false,
				'message' => __( 'Erro: O bucket especificado não existe.', 'codirun-codir2me-cdn' ),
				/* translators: %s: Nome do bucket de armazenamento */
				'details' => sprintf( __( 'Bucket: %s', 'codirun-codir2me-cdn' ), $bucket ),
			);
		} catch ( \AsyncAws\S3\Exception\S3Exception $e ) {
			$error_code = $e->getAwsCode();
			switch ( $error_code ) {
				case 'InvalidAccessKeyId':
					$message = __( 'Erro: Access Key ID inválido.', 'codirun-codir2me-cdn' );
					break;
				case 'SignatureDoesNotMatch':
					$message = __( 'Erro: Secret Access Key inválido.', 'codirun-codir2me-cdn' );
					break;
				case 'AccessDenied':
					$message = __( 'Erro: Acesso negado. Verifique as permissões.', 'codirun-codir2me-cdn' );
					break;
				default:
					/* translators: %s: Mensagem de erro retornada pelo serviço S3 */
					$message = sprintf( __( 'Erro S3: %s', 'codirun-codir2me-cdn' ), $e->getAwsMessage() );
			}

			return array(
				'success' => false,
				'message' => $message,
				/* translators: %s: Código do erro retornado */
				'details' => sprintf( __( 'Código: %s', 'codirun-codir2me-cdn' ), $error_code ),
			);
		} catch ( \Exception $e ) {
			return array(
				'success' => false,
				'message' => __( 'Erro de conexão com R2.', 'codirun-codir2me-cdn' ),
				'details' => $e->getMessage(),
			);
		}
	}
}