<?php
/**
 * Classe que gerencia a aba "Arquivos Estáticos" da UI de administração
 *
 * @package Codirun_R2_Media_Static_CDN
 */

// Evitar acesso direto ao arquivo.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classe CODIR2ME_Admin_UI_Static
 *
 * Gerencia a interface de usuário para a aba de arquivos estáticos
 */
class CODIR2ME_Admin_UI_Static {
	/**
	 * Instância da classe de administração.
	 *
	 * @var codir2me_Admin
	 */
	private $admin;

	/**
	 * Construtor
	 *
	 * @param codir2me_Admin $admin Instância da classe de administração.
	 */
	public function __construct( $admin ) {
		$this->admin = $admin;
	}

	/**
	 * Renderiza a aba de arquivos estáticos
	 *
	 * @param bool  $asyncaws_sdk_available Indica se o AWS SDK está disponível.
	 * @param bool  $upload_in_progress Indica se o upload está em andamento.
	 * @param array $upload_status Status do upload.
	 * @param array $uploaded_files Lista de arquivos enviados.
	 * @param int   $current_batch Lote atual.
	 * @param int   $total_batches Total de lotes.
	 * @param int   $total_files Total de arquivos.
	 * @param int   $processed_files Arquivos processados.
	 */
	public function codir2me_render( $asyncaws_sdk_available, $upload_in_progress, $upload_status, $uploaded_files, $current_batch, $total_batches, $total_files, $processed_files ) {
		?>
		<div class="codir2me-tab-content">
			<div class="codir2me-flex-container">
				<div class="codir2me-main-column">
					<?php
					$this->codir2me_render_status_section( $uploaded_files );
					$this->codir2me_render_settings_section();

					if ( ! $asyncaws_sdk_available ) {
						$this->codir2me_render_sdk_warning();
					} else {
						$this->codir2me_render_upload_section( $upload_in_progress, $upload_status, $total_files, $processed_files, $current_batch, $total_batches );

						if ( ! empty( $uploaded_files ) ) {
							$this->codir2me_render_uploaded_files_section( $uploaded_files );
						}
					}
					?>
				</div>
				
				<?php $this->codir2me_render_sidebar(); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Renderiza a seção de configurações
	 */
	private function codir2me_render_settings_section() {
		?>
		<div class="codir2me-section">
			<h2><?php esc_html_e( 'Configurações de Arquivos Estáticos', 'codirun-codir2me-cdn' ); ?></h2>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'codir2me_static_settings' );
				do_settings_sections( 'codir2me_static_settings' );
				?>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Status do CDN', 'codirun-codir2me-cdn' ); ?></th>
						<td>
							<label class="codir2me-toggle-switch">
								<input type="checkbox" name="codir2me_is_cdn_active" value="1" <?php checked( get_option( 'codir2me_is_cdn_active', false ) ); ?> />
								<span class="codir2me-toggle-slider"></span>
							</label>
							<span class="description"><?php esc_html_e( 'Ativar CDN para arquivos JS, CSS, SVG e fontes', 'codirun-codir2me-cdn' ); ?></span>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Tamanho do Lote', 'codirun-codir2me-cdn' ); ?></th>
						<td>
							<input type="number" name="codir2me_batch_size" value="<?php echo esc_attr( get_option( 'codir2me_batch_size', 50 ) ); ?>" min="1" max="100" class="small-text" />
							<p class="description"><?php esc_html_e( 'Número de arquivos enviados por lote (recomendado: 50)', 'codirun-codir2me-cdn' ); ?></p>
						</td>
					</tr>
					
					<!-- CONFIGURAÇÃO PRINCIPAL -->
					<tr>
						<th><?php esc_html_e( 'Re-envio Automático', 'codirun-codir2me-cdn' ); ?></th>
						<td>
							<label class="codir2me-toggle-switch">
								<input type="checkbox" name="codir2me_upload_on_update" value="1" <?php checked( get_option( 'codir2me_upload_on_update', false ) ); ?> id="codir2me_upload_on_update" />
								<span class="codir2me-toggle-slider"></span>
							</label>
							<span class="description"><?php esc_html_e( 'Re-enviar automaticamente arquivos quando plugins/temas forem atualizados', 'codirun-codir2me-cdn' ); ?></span>
						</td>
					</tr>
					
					<!-- CONFIGURAÇÃO ADICIONAL -->
					<tr class="codir2me-advanced-option">
						<th><?php esc_html_e( 'Versionamento de Arquivos', 'codirun-codir2me-cdn' ); ?></th>
						<td>
							<label class="codir2me-toggle-switch">
								<input type="checkbox" name="codir2me_enable_versioning" value="1" <?php checked( get_option( 'codir2me_enable_versioning', false ) ); ?> />
								<span class="codir2me-toggle-slider"></span>
							</label>
							<span class="description"><?php esc_html_e( 'Adicionar parâmetro de versão às URLs para garantir que os visitantes recebam as versões mais recentes', 'codirun-codir2me-cdn' ); ?></span>
						</td>
					</tr>
				</table>
												
				<?php submit_button( __( 'Salvar Configurações', 'codirun-codir2me-cdn' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Renderiza a seção de status do CDN
	 *
	 * @param array $uploaded_files Lista de arquivos enviados.
	 */
	private function codir2me_render_status_section( $uploaded_files ) {
		?>
		<div class="codir2me-section">
			<h2><?php esc_html_e( 'Status do CDN', 'codirun-codir2me-cdn' ); ?></h2>
			<div class="codir2me-cdn-status-cards">
				<div class="codir2me-status-card">
					<div class="codir2me-status-icon <?php echo get_option( 'codir2me_is_cdn_active' ) ? 'active' : 'inactive'; ?>">
						<span class="dashicons <?php echo get_option( 'codir2me_is_cdn_active' ) ? 'dashicons-yes-alt' : 'dashicons-no-alt'; ?>"></span>
					</div>
					<div class="codir2me-status-details">
						<h3><?php esc_html_e( 'Status atual', 'codirun-codir2me-cdn' ); ?></h3>
						<p class="codir2me-status <?php echo get_option( 'codir2me_is_cdn_active' ) ? 'active' : 'inactive'; ?>">
							<?php echo get_option( 'codir2me_is_cdn_active' ) ? esc_html__( 'Ativo', 'codirun-codir2me-cdn' ) : esc_html__( 'Inativo', 'codirun-codir2me-cdn' ); ?>
						</p>
					</div>
				</div>
				
				<div class="codir2me-status-card">
					<div class="codir2me-status-icon">
						<span class="dashicons dashicons-media-code"></span>
					</div>
					<div class="codir2me-status-details">
						<h3><?php esc_html_e( 'Arquivos Enviados', 'codirun-codir2me-cdn' ); ?></h3>
						<p class="codir2me-status-count"><?php echo count( $uploaded_files ); ?></p>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Renderiza o aviso de SDK não encontrado
	 */
	private function codir2me_render_sdk_warning() {
		?>
		<div class="codir2me-section codir2me-aws-sdk-warning">
			<h3><span class="dashicons dashicons-warning"></span> <?php esc_html_e( 'AWS SDK não encontrado!', 'codirun-codir2me-cdn' ); ?></h3>
			<p><?php esc_html_e( 'O plugin precisa do AWS SDK para PHP para funcionar corretamente. Por favor, instale o SDK manualmente seguindo as instruções na aba Configurações Gerais.', 'codirun-codir2me-cdn' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Renderiza a seção de arquivos enviados com interface avançada
	 *
	 * @param array $uploaded_files Lista de arquivos enviados.
	 */
	private function codir2me_render_uploaded_files_section( $uploaded_files ) {
		// Obter timestamps de upload.
		$upload_timestamps = get_option( 'codir2me_file_upload_timestamps', array() );

		// Organizar arquivos por tipo.
		$files_by_type = array(
			'js'    => array(),
			'css'   => array(),
			'font'  => array(),
			'svg'   => array(),
			'other' => array(),
		);

		// Verificar parâmetro show_all com sanitização e validação.
		$show_all = false;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display parameter doesn't require nonce verification
		if ( isset( $_GET['show_all'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Safe parameter validation
			$show_all_param = sanitize_text_field( wp_unslash( $_GET['show_all'] ) );
			$show_all       = '1' === $show_all_param;
		}
		$files_to_show = $show_all ? $uploaded_files : array_slice( $uploaded_files, 0, 100 );
		foreach ( $files_to_show as $file ) {
			// Verificar se o arquivo é uma string ou um array.
			$file_path = '';
			if ( is_array( $file ) && isset( $file['relative_path'] ) ) {
				$file_path = $file['relative_path'];
			} elseif ( is_string( $file ) ) {
				$file_path = $file;
			} else {
				// Pular este item se não for possível determinar o caminho.
				continue;
			}

			// Agora podemos usar pathinfo de forma segura.
			$ext = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );

			// Determinar o tipo.
			if ( 'js' === $ext ) {
				$type = 'js';
			} elseif ( 'css' === $ext ) {
				$type = 'css';
			} elseif ( in_array( $ext, array( 'woff', 'woff2', 'ttf', 'eot' ), true ) ) {
				$type = 'font';
			} elseif ( 'svg' === $ext ) {
				$type = 'svg';
			} else {
				$type = 'other';
			}

			// Obter data de última atualização.
			$timestamp = isset( $upload_timestamps[ $file_path ] ) ? $upload_timestamps[ $file_path ] : 0;
			$date      = $timestamp ? gmdate( 'd/m/Y H:i', $timestamp ) : __( 'Desconhecida', 'codirun-codir2me-cdn' );

			// Armazenar informações do arquivo.
			$files_by_type[ $type ][] = array(
				'path'      => $file_path,
				'name'      => basename( $file_path ),
				'date'      => $date,
				'timestamp' => $timestamp,
			);
		}

		// Contagem total.
		$total_files = count( $uploaded_files );

		?>
		<div class="codir2me-section">
			<h3><?php esc_html_e( 'Arquivos Enviados', 'codirun-codir2me-cdn' ); ?></h3>
			
			<!-- Adicionar o botão de limpar lista aqui -->
			<div class="codir2me-actions" style="margin-bottom: 15px;">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Tem certeza que deseja limpar a lista de arquivos enviados? Esta ação não excluirá os arquivos do R2, apenas limpará o registro local.', 'codirun-codir2me-cdn' ) ); ?>');">
					<?php wp_nonce_field( 'codir2me_clear_uploaded_files', 'codir2me_clear_files_nonce' ); ?>
					<input type="hidden" name="action" value="codir2me_clear_uploaded_files">
					<button type="submit" class="button button-secondary" style="color: #fff; background-color: #d63638; border-color: #d63638;">
						<span class="dashicons dashicons-trash" style="margin-top: 3px;"></span>
						<?php esc_html_e( 'Limpar Lista de Arquivos Enviados', 'codirun-codir2me-cdn' ); ?>
					</button>
				</form>
				
				<div class="custom-notice notice-info" style="margin: 10px 0 15px; background: #f0f6fc; border-left: 4px solid #0073aa; padding: 10px 15px;">
					<p>
						<span class="dashicons dashicons-info" style="color: #0073aa; margin-right: 5px;"></span>
						<strong><?php esc_html_e( 'Observação:', 'codirun-codir2me-cdn' ); ?></strong>
						<?php esc_html_e( 'Esta ação apenas limpa o registro local de arquivos enviados para o R2. Os arquivos continuarão armazenados no Cloudflare R2.', 'codirun-codir2me-cdn' ); ?>
						<?php
						printf(
							/* translators: %s: URL da página de exclusão de arquivos */
							esc_html__( 'Para excluir os arquivos do bucket R2, utilize a aba %s.', 'codirun-codir2me-cdn' ),
							'<a href="' . esc_url( admin_url( 'admin.php?page=codirun-codir2me-cdn-delete' ) ) . '">' . esc_html__( 'Excluir Arquivos', 'codirun-codir2me-cdn' ) . '</a>'
						);
						?>
					</p>
				</div>
			</div>
			
			<!-- Resumo dos arquivos -->
			<div class="codir2me-files-summary">
				<div class="codir2me-summary-cards">
					<div class="codir2me-summary-card">
						<div class="codir2me-summary-icon">
							<span class="dashicons dashicons-media-code"></span>
						</div>
						<div class="codir2me-summary-details">
							<h4><?php esc_html_e( 'Total de Arquivos', 'codirun-codir2me-cdn' ); ?></h4>
							<p class="codir2me-summary-count"><?php echo esc_html( $total_files ); ?></p>
						</div>
					</div>
					
					<div class="codir2me-summary-card">
						<div class="codir2me-summary-icon js-icon">
							<span class="dashicons dashicons-media-text"></span>
						</div>
						<div class="codir2me-summary-details">
							<h4><?php esc_html_e( 'JavaScript', 'codirun-codir2me-cdn' ); ?></h4>
							<p class="codir2me-summary-count"><?php echo esc_html( count( $files_by_type['js'] ) ); ?></p>
						</div>
					</div>
					
					<div class="codir2me-summary-card">
						<div class="codir2me-summary-icon css-icon">
							<span class="dashicons dashicons-admin-customizer"></span>
						</div>
						<div class="codir2me-summary-details">
							<h4><?php esc_html_e( 'CSS', 'codirun-codir2me-cdn' ); ?></h4>
							<p class="codir2me-summary-count"><?php echo esc_html( count( $files_by_type['css'] ) ); ?></p>
						</div>
					</div>
					
					<div class="codir2me-summary-card">
						<div class="codir2me-summary-icon font-icon">
							<span class="dashicons dashicons-editor-textcolor"></span>
						</div>
						<div class="codir2me-summary-details">
							<h4><?php esc_html_e( 'Fontes', 'codirun-codir2me-cdn' ); ?></h4>
							<p class="codir2me-summary-count"><?php echo esc_html( count( $files_by_type['font'] ) ); ?></p>
						</div>
					</div>
					
					<div class="codir2me-summary-card">
						<div class="codir2me-summary-icon svg-icon">
							<span class="dashicons dashicons-format-image"></span>
						</div>
						<div class="codir2me-summary-details">
							<h4><?php esc_html_e( 'SVG', 'codirun-codir2me-cdn' ); ?></h4>
							<p class="codir2me-summary-count"><?php echo esc_html( count( $files_by_type['svg'] ) ); ?></p>
						</div>
					</div>
				</div>
			</div>
			
			<!-- Tabela de arquivos avançada -->
			<div class="codir2me-files-advanced-list">
				<div class="codir2me-files-filter">
					<input type="text" id="codir2me-filter-files" placeholder="<?php esc_attr_e( 'Filtrar arquivos...', 'codirun-codir2me-cdn' ); ?>" class="regular-text">
					<div class="codir2me-files-count">
						<?php
						printf(
							/* translators: %1$s: número de arquivos filtrados, %2$s: número total de arquivos */
							esc_html__( 'Mostrando %1$s de %2$s arquivos', 'codirun-codir2me-cdn' ),
							'<span id="codir2me-filtered-count">' . esc_html( $total_files ) . '</span>',
							esc_html( $total_files )
						);
						?>
					</div>
				</div>
				
				<div class="codir2me-files-table-container">
					<table class="codir2me-files-table widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Nome do Arquivo', 'codirun-codir2me-cdn' ); ?></th>
								<th><?php esc_html_e( 'Tipo', 'codirun-codir2me-cdn' ); ?></th>
								<th><?php esc_html_e( 'Caminho', 'codirun-codir2me-cdn' ); ?></th>
								<th><?php esc_html_e( 'Última Atualização', 'codirun-codir2me-cdn' ); ?></th>
								<th><?php esc_html_e( 'Ações', 'codirun-codir2me-cdn' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php
							// Combinar todos os arquivos em uma lista.
							$all_files = array();
							foreach ( $files_by_type as $type => $files ) {
								foreach ( $files as $file ) {
									$file['type'] = $type;
									$all_files[]  = $file;
								}
							}

							// Ordenar por data de atualização (mais recentes primeiro).
							usort(
								$all_files,
								function ( $a, $b ) {
									return $b['timestamp'] - $a['timestamp'];
								}
							);

							foreach ( $all_files as $file ) :
								// Determinar ícone e classe com base no tipo.
								$icon       = 'dashicons-media-default';
								$type_name  = __( 'Outro', 'codirun-codir2me-cdn' );
								$type_class = '';

								switch ( $file['type'] ) {
									case 'js':
										$icon       = 'dashicons-media-code';
										$type_name  = __( 'JavaScript', 'codirun-codir2me-cdn' );
										$type_class = 'file-type-js';
										break;
									case 'css':
										$icon       = 'dashicons-admin-customizer';
										$type_name  = __( 'CSS', 'codirun-codir2me-cdn' );
										$type_class = 'file-type-css';
										break;
									case 'font':
										$icon       = 'dashicons-editor-textcolor';
										$type_name  = __( 'Fonte', 'codirun-codir2me-cdn' );
										$type_class = 'file-type-font';
										break;
									case 'svg':
										$icon       = 'dashicons-format-image';
										$type_name  = __( 'SVG', 'codirun-codir2me-cdn' );
										$type_class = 'file-type-svg';
										break;
								}
								?>
							<tr class="codir2me-file-row" data-path="<?php echo esc_attr( $file['path'] ); ?>" data-name="<?php echo esc_attr( $file['name'] ); ?>" data-type="<?php echo esc_attr( $file['type'] ); ?>">
								<td class="codir2me-file-name">
									<span class="dashicons <?php echo esc_attr( $icon ); ?>"></span>
									<?php echo esc_html( $file['name'] ); ?>
								</td>
								<td class="codir2me-file-type">
									<span class="codir2me-type-badge <?php echo esc_attr( $type_class ); ?>"><?php echo esc_html( $type_name ); ?></span>
								</td>
								<td class="codir2me-file-path">
									<div class="codir2me-path-preview" title="<?php echo esc_attr( $file['path'] ); ?>">
										<?php echo esc_html( $file['path'] ); ?>
									</div>
								</td>
								<td class="codir2me-file-date">
									<?php echo esc_html( $file['date'] ); ?>
								</td>
								<td class="codir2me-file-actions">
									<button type="button" class="button button-small codir2me-resync-file" data-path="<?php echo esc_attr( $file['path'] ); ?>">
										<span class="dashicons dashicons-update"></span>
										<?php esc_html_e( 'Reenviar', 'codirun-codir2me-cdn' ); ?>
									</button>
								</td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
				
		<?php if ( count( $uploaded_files ) > 100 ) : ?>
			<div style="text-align: center; margin: 20px 0;">
				<?php if ( $show_all ) : ?>
					<p><strong><?php printf( 'Mostrando todos os %d arquivos', count( $uploaded_files ) ); ?></strong></p>
					<a href="<?php echo esc_url( remove_query_arg( 'show_all' ) ); ?>" class="button button-secondary">
						Mostrar apenas 100 arquivos
					</a>
				<?php else : ?>
					<p><strong><?php printf( 'Mostrando 100 de %d arquivos', count( $uploaded_files ) ); ?></strong></p>
					<a href="<?php echo esc_url( add_query_arg( 'show_all', '1' ) ); ?>" class="button button-secondary">
						Ver todos os arquivos (pode ser lento)
					</a>
				<?php endif; ?>
			</div>
		<?php endif; ?>
			
		</div>
		<?php
	}

	/**
	 * Renderiza a seção de upload
	 *
	 * @param bool  $upload_in_progress Indica se o upload está em andamento.
	 * @param array $upload_status Status do upload.
	 * @param int   $total_files Total de arquivos.
	 * @param int   $processed_files Arquivos processados.
	 * @param int   $current_batch Lote atual.
	 * @param int   $total_batches Total de lotes.
	 */
	private function codir2me_render_upload_section( $upload_in_progress, $upload_status, $total_files, $processed_files, $current_batch, $total_batches ) {
		?>
		<div class="codir2me-section">
			<h2><?php esc_html_e( 'Sincronização de Arquivos Estáticos', 'codirun-codir2me-cdn' ); ?></h2>
			
			<?php if ( $upload_in_progress ) : ?>
			<div class="codir2me-upload-progress">
				<h3><?php esc_html_e( 'Upload em Andamento', 'codirun-codir2me-cdn' ); ?></h3>
				<div class="codir2me-progress-details">
					<p>
						<span class="codir2me-progress-label"><?php esc_html_e( 'Arquivos processados:', 'codirun-codir2me-cdn' ); ?></span>
						<span class="codir2me-progress-value"><?php echo esc_html( $processed_files ); ?> <?php esc_html_e( 'de', 'codirun-codir2me-cdn' ); ?> <?php echo esc_html( $total_files ); ?></span>
					</p>
					<p>
						<span class="codir2me-progress-label"><?php esc_html_e( 'Lotes processados:', 'codirun-codir2me-cdn' ); ?></span>
						<span class="codir2me-progress-value"><?php echo esc_html( $current_batch ); ?> <?php esc_html_e( 'de', 'codirun-codir2me-cdn' ); ?> <?php echo esc_html( $total_batches ); ?></span>
					</p>
				</div>
				
				<div class="codir2me-progress-bar">
					<div class="codir2me-progress-inner" style="width: <?php echo esc_attr( ( $total_files > 0 ) ? ( $processed_files / $total_files * 100 ) : 0 ); ?>%;"></div>
				</div>
				
				<p class="codir2me-progress-warning"><?php esc_html_e( 'Por favor, não feche esta página até que o processo termine.', 'codirun-codir2me-cdn' ); ?></p>
			
			<div class="codir2me-forms-wrapper" style="display: flex; gap: 10px; flex-wrap: wrap;">				
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="codir2me-continue-form">
					<?php wp_nonce_field( 'codir2me_process_batch', 'codir2me_batch_nonce' ); ?>
					<input type="hidden" name="action" value="codir2me_process_batch">
					<button type="submit" name="codir2me_process_batch" class="button button-primary">
						<span class="dashicons dashicons-controls-play"></span>
						<?php esc_html_e( 'Continuar Upload (Próximo Lote)', 'codirun-codir2me-cdn' ); ?>
					</button>
				</form>
				
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'codir2me_cancel_upload', 'codir2me_cancel_upload_nonce' ); ?>
					<input type="hidden" name="action" value="codir2me_cancel_upload">
					<input type="hidden" name="upload_type" value="static">
					<button type="submit" name="cancel_upload" class="button button-secondary" style="background-color: #f56e28; color: white; border-color: #d65b25;">
						<span class="dashicons dashicons-no-alt"></span>
						<?php esc_html_e( 'Parar Upload', 'codirun-codir2me-cdn' ); ?>
					</button>
				</form>
			</div>
				
				<p class="codir2me-note"><span class="dashicons dashicons-info"></span> <?php esc_html_e( 'Se o processo parou, clique em "Continuar Upload" para retomar.', 'codirun-codir2me-cdn' ); ?></p>
			</div>
			<?php else : ?>
			<div class="codir2me-upload-start">
				<p><?php esc_html_e( 'Isso irá escanear seu site em busca de arquivos JS, CSS, SVG, e fontes (WOFF, WOFF2, TTF, EOT) e enviá-los para o R2 em lotes.', 'codirun-codir2me-cdn' ); ?></p>
				<p><?php esc_html_e( 'O processo é executado em etapas para evitar sobrecarga do servidor.', 'codirun-codir2me-cdn' ); ?></p>
				
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="codir2me-start-form">
					<?php wp_nonce_field( 'codir2me_scan_files', 'codir2me_scan_nonce' ); ?>
					<input type="hidden" name="action" value="codir2me_scan_files">
					<button type="submit" name="codir2me_scan_files" class="button button-primary">
						<span class="dashicons dashicons-cloud-upload"></span>
						<?php esc_html_e( 'Iniciar Upload de Arquivos Estáticos', 'codirun-codir2me-cdn' ); ?>
					</button>
				</form>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Renderiza a barra lateral
	 */
	private function codir2me_render_sidebar() {
		// Obter o domínio do site atual para o exemplo de configuração CORS.
		$site_url   = get_site_url();
		$parsed_url = wp_parse_url( $site_url );
		$domain     = isset( $parsed_url['host'] ) ? $parsed_url['host'] : 'seusite.com';

		// Criar versões com e sem www. para o exemplo.
		$domain_with_www    = ( 0 === strpos( $domain, 'www.' ) ) ? $domain : 'www.' . $domain;
		$domain_without_www = str_replace( 'www.', '', $domain );

		// Preparar o código JSON para a configuração CORS.
		$cors_config = wp_json_encode(
			array(
				array(
					'AllowedOrigins' => array(
						'https://' . $domain_without_www,
						'https://' . $domain_with_www,
					),
					'AllowedMethods' => array(
						'GET',
						'HEAD',
					),
					'AllowedHeaders' => array(
						'*',
					),
					'MaxAgeSeconds'  => 3600,
				),
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);
		?>
		<div class="codir2me-sidebar">
			<div class="codir2me-sidebar-widget">
				<h3><?php esc_html_e( 'Sobre o CDN de Arquivos Estáticos', 'codirun-codir2me-cdn' ); ?></h3>
				<div class="codir2me-widget-content">
					<p><?php esc_html_e( 'O CDN de Arquivos Estáticos otimiza o carregamento do seu site, transferindo arquivos como JavaScript, CSS, SVG e fontes para o Cloudflare R2.', 'codirun-codir2me-cdn' ); ?></p>
					<p><?php esc_html_e( 'Benefícios:', 'codirun-codir2me-cdn' ); ?></p>
					<ul>
						<li><?php esc_html_e( 'Maior velocidade de carregamento', 'codirun-codir2me-cdn' ); ?></li>
						<li><?php esc_html_e( 'Menor carga no servidor principal', 'codirun-codir2me-cdn' ); ?></li>
						<li><?php esc_html_e( 'Melhor distribuição geográfica de conteúdo', 'codirun-codir2me-cdn' ); ?></li>
					</ul>
				</div>
			</div>
			
			<div class="codir2me-sidebar-widget">
				<h3><?php esc_html_e( 'Dicas de Uso', 'codirun-codir2me-cdn' ); ?></h3>
				<div class="codir2me-widget-content">
					<ul class="codir2me-tips-list">
						<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Ative o CDN após concluir o upload inicial', 'codirun-codir2me-cdn' ); ?></li>
						<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Execute a sincronização após instalar novos temas ou plugins', 'codirun-codir2me-cdn' ); ?></li>
						<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Use lotes menores se encontrar timeout durante o upload', 'codirun-codir2me-cdn' ); ?></li>
					</ul>
				</div>
			</div>
			
			<!-- Nova seção para configuração CORS -->
			<div class="codir2me-sidebar-widget">
				<h3><?php esc_html_e( 'Configuração CORS (Importante)', 'codirun-codir2me-cdn' ); ?></h3>
				<div class="codir2me-widget-content">
					<p>
						<span class="dashicons dashicons-warning" style="color: #f56e28;"></span>
						<strong><?php esc_html_e( 'Para que arquivos como fontes funcionem corretamente, você precisa configurar a política CORS do seu bucket R2:', 'codirun-codir2me-cdn' ); ?></strong>
					</p>
					<ol class="codir2me-cors-steps">
						<li><?php esc_html_e( 'Acesse o painel do Cloudflare', 'codirun-codir2me-cdn' ); ?></li>
						<li><?php esc_html_e( 'Navegue até R2 > Seu Bucket > Configurações', 'codirun-codir2me-cdn' ); ?></li>
						<li><?php esc_html_e( 'Na seção "CORS", adicione a seguinte configuração:', 'codirun-codir2me-cdn' ); ?></li>
					</ol>
					
					<div class="codir2me-code-block">
						<pre><?php echo esc_html( $cors_config ); ?></pre>
						<button type="button" class="button button-small codir2me-copy-cors-config">
							<span class="dashicons dashicons-clipboard"></span>
							<?php esc_html_e( 'Copiar', 'codirun-codir2me-cdn' ); ?>
						</button>
					</div>
					
					<p class="codir2me-cors-note">
					<?php
						printf(
							/* translators: %1$s e %2$s são os domínios do site */
							esc_html__( 'Substitua %1$s e %2$s pelos seus domínios se forem diferentes dos mostrados acima.', 'codirun-codir2me-cdn' ),
							'<code>https://' . esc_html( $domain_without_www ) . '</code>',
							'<code>https://' . esc_html( $domain_with_www ) . '</code>'
						);
					?>
					</p>
					
					<div class="codir2me-cors-info">
						<p><?php esc_html_e( 'A configuração CORS (Cross-Origin Resource Sharing) é necessária para que seu navegador possa carregar recursos como fontes a partir do domínio do R2, que é diferente do domínio do seu site.', 'codirun-codir2me-cdn' ); ?></p>
					</div>
				</div>
			</div>
			
		</div>

		<?php
	}
}
