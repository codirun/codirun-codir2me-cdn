<?php
/**
 * Classe que gerencia a aba "Imagens" da UI de administração
 *
 * @package Codirun_R2_Media_Static_CDN
 */

// Evitar acesso direto ao arquivo.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classe responsável pela interface de administração da aba de imagens.
 */
class CODIR2ME_Admin_UI_Images {
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
	 * Renderiza a aba de imagens
	 *
	 * @param bool  $asyncaws_sdk_available Indica se o AWS SDK está disponível.
	 * @param bool  $images_upload_in_progress Indica se o upload está em andamento.
	 * @param array $images_upload_status Status do upload de imagens.
	 * @param array $uploaded_images Lista de imagens enviadas.
	 * @param array $thumbnail_sizes Informações sobre tamanhos de miniaturas.
	 * @param array $images_count Contagem total de imagens.
	 * @param int   $total_images Total de imagens originais.
	 * @param int   $total_image_files Total de arquivos de imagem (incluindo miniaturas).
	 * @param int   $images_current_batch Lote atual.
	 * @param int   $images_total_batches Total de lotes.
	 * @param int   $images_total_files Total de arquivos de imagem.
	 * @param int   $images_processed_files Arquivos de imagem processados.
	 */
	public function codir2me_render( $asyncaws_sdk_available, $images_upload_in_progress, $images_upload_status, $uploaded_images, $thumbnail_sizes, $images_count, $total_images, $total_image_files, $images_current_batch, $images_total_batches, $images_total_files, $images_processed_files ) {
		?>
		<div class="codir2me-tab-content">
		<div class="codir2me-flex-container">
			<div class="codir2me-main-column">
				<?php
				$this->codir2me_render_status_section( $uploaded_images, $total_images, $total_image_files );
				$this->codir2me_render_settings_section( $thumbnail_sizes );

				if ( ! $asyncaws_sdk_available ) {
					$this->codir2me_render_sdk_warning();
				} else {
					$this->codir2me_render_upload_section(
						$images_upload_in_progress,
						$images_upload_status,
						$images_total_files,
						$images_processed_files,
						$images_current_batch,
						$images_total_batches
					);
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
	 *
	 * @param array $thumbnail_sizes Informações sobre tamanhos de miniaturas.
	 */
	private function codir2me_render_settings_section( $thumbnail_sizes ) {
		$thumbnail_option       = get_option( 'codir2me_thumbnail_option', 'all' );
		$selected_thumbnails    = get_option( 'codir2me_selected_thumbnails', array() );
		$auto_upload_thumbnails = get_option( 'codir2me_auto_upload_thumbnails', false );

		?>
		<div class="codir2me-section">
			<h2><?php esc_html_e( 'Configurações de Imagens', 'codirun-codir2me-cdn' ); ?></h2>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'codir2me_images_settings' );
				do_settings_sections( 'codir2me_images_settings' );
				?>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Status do CDN de Imagens', 'codirun-codir2me-cdn' ); ?></th>
						<td>
							<label class="codir2me-toggle-switch">
								<input type="checkbox" name="codir2me_is_images_cdn_active" value="1" <?php checked( get_option( 'codir2me_is_images_cdn_active' ) ); ?> />
								<span class="codir2me-toggle-slider"></span>
							</label>
							<span class="description"><?php esc_html_e( 'Ativar CDN para imagens', 'codirun-codir2me-cdn' ); ?></span>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Desabilitar CDN no Admin', 'codirun-codir2me-cdn' ); ?></th>
						<td>
							<label class="codir2me-toggle-switch">
								<input type="checkbox" name="codir2me_disable_cdn_admin" value="1" <?php checked( get_option( 'codir2me_disable_cdn_admin' ) ); ?> />
								<span class="codir2me-toggle-slider"></span>
							</label>
							<span class="description"><?php esc_html_e( 'Desabilitar CDN apenas na área administrativa (wp-admin)', 'codirun-codir2me-cdn' ); ?></span>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Upload Automático de Miniaturas', 'codirun-codir2me-cdn' ); ?></th>
						<td>
							<label class="codir2me-toggle-switch">
								<input type="checkbox" name="codir2me_auto_upload_thumbnails" value="1" <?php checked( $auto_upload_thumbnails ); ?> />
								<span class="codir2me-toggle-slider"></span>
							</label>
							<span class="description"><?php esc_html_e( 'Enviar miniaturas automaticamente para o R2 quando novas imagens forem adicionadas', 'codirun-codir2me-cdn' ); ?></span>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Tamanho do Lote', 'codirun-codir2me-cdn' ); ?></th>
						<td>
							<input type="number" name="codir2me_images_batch_size" value="<?php echo esc_attr( get_option( 'codir2me_images_batch_size', 20 ) ); ?>" class="small-text" min="1" max="50" />
							<p class="description"><?php esc_html_e( 'Número de imagens enviadas por lote (recomendado: 20)', 'codirun-codir2me-cdn' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Tamanhos de Miniatura', 'codirun-codir2me-cdn' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( 'Tamanhos de Miniatura', 'codirun-codir2me-cdn' ); ?></legend>
								<label>
									<input type="radio" name="codir2me_thumbnail_option" value="all" <?php checked( $thumbnail_option, 'all' ); ?> />
									<?php esc_html_e( 'Enviar todas as miniaturas', 'codirun-codir2me-cdn' ); ?>
								</label>
								<br>
								<label>
									<input type="radio" name="codir2me_thumbnail_option" value="selected" <?php checked( $thumbnail_option, 'selected' ); ?> />
									<?php esc_html_e( 'Selecionar tamanhos específicos', 'codirun-codir2me-cdn' ); ?>
								</label>
								<br>
								<label>
									<input type="radio" name="codir2me_thumbnail_option" value="none" <?php checked( $thumbnail_option, 'none' ); ?> />
									<?php esc_html_e( 'Apenas imagens originais', 'codirun-codir2me-cdn' ); ?>
								</label>
							</fieldset>
							
							<div id="codir2me-thumbnail-sizes" class="codir2me-thumbnail-sizes" style="<?php echo ( 'selected' === $thumbnail_option ) ? 'display:block;' : 'display:none;'; ?>">
								<div class="codir2me-thumbnail-actions">
									<button id="codir2me-select-all-thumbnails" class="button button-secondary"><?php esc_html_e( 'Selecionar Todos', 'codirun-codir2me-cdn' ); ?></button>
									<button id="codir2me-deselect-all-thumbnails" class="button button-secondary"><?php esc_html_e( 'Desmarcar Todos', 'codirun-codir2me-cdn' ); ?></button>
								</div>
								<div class="codir2me-thumbnail-list">
									<?php foreach ( $thumbnail_sizes as $size_name => $size_info ) : ?>
									<div class="codir2me-thumbnail-size">
										<label>
											<input type="checkbox" name="codir2me_selected_thumbnails[]" value="<?php echo esc_attr( $size_name ); ?>" <?php checked( in_array( $size_name, $selected_thumbnails, true ) ); ?> />
											<strong><?php echo esc_html( $size_name ); ?></strong>
											(<?php echo esc_html( $size_info['dimensions'] ); ?>)
										</label>
									</div>
									<?php endforeach; ?>
								</div>
							</div>
						</td>
					</tr>
				</table>
				<div class="codir2me-auto-upload-warning" style="margin-top: 15px; padding: 10px; background-color: #fff8e5; border-left: 4px solid #ffb900;">
					<p><strong><?php esc_html_e( 'Nota sobre o Upload Automático de Miniaturas:', 'codirun-codir2me-cdn' ); ?></strong> <?php esc_html_e( 'Quando ativado, o sistema enviará automaticamente as miniaturas conforme as configurações acima para o R2. As imagens originais já são enviadas automaticamente.', 'codirun-codir2me-cdn' ); ?></p>
				</div>
				<?php submit_button( __( 'Salvar Configurações', 'codirun-codir2me-cdn' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Renderiza a seção de status do CDN de imagens
	 *
	 * @param array $uploaded_images Lista de imagens enviadas.
	 * @param int   $total_images Total de imagens.
	 * @param int   $total_image_files Total de arquivos de imagem.
	 */
	private function codir2me_render_status_section( $uploaded_images, $total_images, $total_image_files ) {
		// Verificar se você já enviou todos os arquivos.
		$all_sent = get_option( 'codir2me_all_images_sent', false );

		// Se você já enviou tudo OU tem 0 imagens faltando.
		if ( $all_sent || count( $uploaded_images ) >= $total_image_files ) {
			$original_images_uploaded = $total_images;
			$missing_images           = 0;

			// Marcar que todas as imagens foram enviadas.
			update_option( 'codir2me_all_images_sent', true );
			update_option( 'codir2me_original_images_count', $original_images_uploaded );
			update_option( 'codir2me_missing_images_count', $missing_images );
		} else {
			// Contar imagens originais enviadas e identificar quais ainda faltam enviar.
			$original_images_uploaded = 0;
			$original_images_paths    = array(); // Armazenar caminhos de originais enviados.

			foreach ( $uploaded_images as $path ) {
				// Verificar se é uma imagem original (não tem tamanho no nome do arquivo).
				$filename     = basename( $path );
				$is_thumbnail = preg_match( '/-\d+x\d+\.[a-zA-Z]+$/', $filename ) || preg_match( '/-[a-zA-Z_]+\.[a-zA-Z]+$/', $filename );
				if ( ! $is_thumbnail ) {
					++$original_images_uploaded;
					$original_images_paths[] = $path; // Guardar o caminho da imagem original.
				}
			}

			// Verificar na biblioteca de mídia quais imagens faltam enviar.
			$args = array(
				'post_type'      => 'attachment',
				'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ),
				'post_status'    => 'inherit',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			);

			$query          = new WP_Query( $args );
			$missing_images = 0;

			foreach ( $query->posts as $attachment_id ) {
				$file_path = get_attached_file( $attachment_id );
				if ( file_exists( $file_path ) ) {
					// Usar função personalizada para obter caminho relativo.
					$relative_path = codir2me_get_relative_path( $file_path );
					if ( ! in_array( $relative_path, $original_images_paths, true ) ) {
						++$missing_images;
					}
				}
			}

			// Correção: total_images deve ser igual ao número total na biblioteca.
			$total_images = $original_images_uploaded + $missing_images;

			// Atualizar a opção para uso em outras partes do plugin.
			update_option( 'codir2me_original_images_count', $original_images_uploaded );
			update_option( 'codir2me_missing_images_count', $missing_images );
		}

		$auto_upload_thumbnails = get_option( 'codir2me_auto_upload_thumbnails', false ) ? __( 'Ativado', 'codirun-codir2me-cdn' ) : __( 'Desativado', 'codirun-codir2me-cdn' );

		?>
		<div class="codir2me-section">
			<h2><?php esc_html_e( 'Status do CDN de Imagens', 'codirun-codir2me-cdn' ); ?></h2>
			<div class="codir2me-cdn-status-cards">
				<div class="codir2me-status-card">
					<div class="codir2me-status-icon <?php echo get_option( 'codir2me_is_images_cdn_active' ) ? 'active' : 'inactive'; ?>">
						<span class="dashicons <?php echo get_option( 'codir2me_is_images_cdn_active' ) ? 'dashicons-yes-alt' : 'dashicons-no-alt'; ?>"></span>
					</div>
					<div class="codir2me-status-details">
						<h3><?php esc_html_e( 'Status atual', 'codirun-codir2me-cdn' ); ?></h3>
						<p class="codir2me-status <?php echo get_option( 'codir2me_is_images_cdn_active' ) ? 'active' : 'inactive'; ?>">
							<?php echo get_option( 'codir2me_is_images_cdn_active' ) ? esc_html__( 'Ativo', 'codirun-codir2me-cdn' ) : esc_html__( 'Inativo', 'codirun-codir2me-cdn' ); ?>
						</p>
					</div>
				</div>
				
				<div class="codir2me-status-card">
					<div class="codir2me-status-icon">
						<span class="dashicons dashicons-images-alt2"></span>
					</div>
					<div class="codir2me-status-details">
						<h3><?php esc_html_e( 'Imagens Enviadas', 'codirun-codir2me-cdn' ); ?></h3>
						<p class="codir2me-status-count"><?php echo count( $uploaded_images ); ?></p>
					</div>
				</div>
				
				<div class="codir2me-status-card">
					<div class="codir2me-status-icon <?php echo get_option( 'codir2me_auto_upload_thumbnails' ) ? 'active' : 'inactive'; ?>">
						<span class="dashicons dashicons-images-alt"></span>
					</div>
					<div class="codir2me-status-details">
						<h3><?php esc_html_e( 'Upload de Miniaturas', 'codirun-codir2me-cdn' ); ?></h3>
						<p class="codir2me-status <?php echo get_option( 'codir2me_auto_upload_thumbnails' ) ? 'active' : 'inactive'; ?>">
							<?php echo esc_html( $auto_upload_thumbnails ); ?>
						</p>
					</div>
				</div>
			</div>
			
			<div class="codir2me-upload-info">
				<div class="codir2me-info-card">
					<h4><span class="dashicons dashicons-format-image"></span> <?php esc_html_e( 'Total de Imagens', 'codirun-codir2me-cdn' ); ?></h4>
					<p class="codir2me-info-value">
						<?php echo esc_html( $total_images ); ?>
						<?php if ( $original_images_uploaded > 0 && $missing_images > 0 ) : ?>
							<small>(<?php echo esc_html( $original_images_uploaded ); ?> <?php esc_html_e( 'enviadas', 'codirun-codir2me-cdn' ); ?>, <?php esc_html_e( 'falta', 'codirun-codir2me-cdn' ); ?> <?php echo esc_html( $missing_images ); ?>)</small>
						<?php else : ?>
							<small>(<?php esc_html_e( 'Todas as imagens foram enviadas', 'codirun-codir2me-cdn' ); ?>)</small>
						<?php endif; ?>
					</p>
				</div>
				
				<div class="codir2me-info-card">
					<h4><span class="dashicons dashicons-images-alt"></span> <?php esc_html_e( 'Incluindo Miniaturas', 'codirun-codir2me-cdn' ); ?></h4>
					<p class="codir2me-info-value">
						<?php echo esc_html( $total_image_files ); ?>
						<?php if ( count( $uploaded_images ) > 0 && count( $uploaded_images ) < $total_image_files ) : ?>
							<small>(<?php echo count( $uploaded_images ); ?> <?php esc_html_e( 'enviadas', 'codirun-codir2me-cdn' ); ?>, <?php esc_html_e( 'falta', 'codirun-codir2me-cdn' ); ?> <?php echo esc_html( $total_image_files ) - count( $uploaded_images ); ?>)</small>
						<?php else : ?>
							<small>(<?php esc_html_e( 'Todas as miniaturas foram enviadas', 'codirun-codir2me-cdn' ); ?>)</small>
						<?php endif; ?>
					</p>
				</div>
				
				<div class="codir2me-info-card">
					<h4><span class="dashicons dashicons-cloud-upload"></span> <?php esc_html_e( 'Enviados para R2', 'codirun-codir2me-cdn' ); ?></h4>
					<p class="codir2me-info-value"><?php echo count( $uploaded_images ); ?></p>
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
	 * Renderiza a seção de upload de imagens
	 *
	 * @param bool  $images_upload_in_progress Indica se o upload está em andamento.
	 * @param array $images_upload_status Status do upload de imagens.
	 * @param int   $images_total_files Total de arquivos de imagem.
	 * @param int   $images_processed_files Arquivos de imagem processados.
	 * @param int   $images_current_batch Lote atual.
	 * @param int   $images_total_batches Total de lotes.
	 */
	private function codir2me_render_upload_section( $images_upload_in_progress, $images_upload_status, $images_total_files, $images_processed_files, $images_current_batch, $images_total_batches ) {
		?>
		<div class="codir2me-section">
			<h2><?php esc_html_e( 'Upload de Imagens para R2', 'codirun-codir2me-cdn' ); ?></h2>
			
			<?php if ( $images_upload_in_progress ) : ?>
			<div class="codir2me-upload-progress">
				<h3><?php esc_html_e( 'Upload em Andamento', 'codirun-codir2me-cdn' ); ?></h3>
				<div class="codir2me-progress-details">
					<p>
						<span class="codir2me-progress-label"><?php esc_html_e( 'Imagens processadas:', 'codirun-codir2me-cdn' ); ?></span>
						<span class="codir2me-progress-value"><?php echo esc_html( $images_processed_files ); ?> <?php esc_html_e( 'de', 'codirun-codir2me-cdn' ); ?> <?php echo esc_html( $images_total_files ); ?></span>
					</p>
					<p>
						<span class="codir2me-progress-label"><?php esc_html_e( 'Lotes processados:', 'codirun-codir2me-cdn' ); ?></span>
						<span class="codir2me-progress-value"><?php echo esc_html( $images_current_batch ); ?> <?php esc_html_e( 'de', 'codirun-codir2me-cdn' ); ?> <?php echo esc_html( $images_total_batches ); ?></span>
					</p>
				</div>
				
				<div class="codir2me-progress-bar">
					<div class="codir2me-progress-inner" style="width: <?php echo esc_attr( ( $images_total_files > 0 ) ? ( $images_processed_files / $images_total_files * 100 ) : 0 ); ?>%;"></div>
				</div>
				
				<p class="codir2me-progress-warning"><?php esc_html_e( 'Por favor, não feche esta página até que o processo termine.', 'codirun-codir2me-cdn' ); ?></p>
				
			<div class="codir2me-forms-wrapper" style="display: flex; gap: 10px; flex-wrap: wrap;">	
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="codir2me-continue-form">
					<?php wp_nonce_field( 'codir2me_process_images_batch', 'codir2me_images_batch_nonce' ); ?>
					<input type="hidden" name="action" value="codir2me_process_images_batch">
					<button type="submit" name="process_images_batch" class="button button-primary">
						<span class="dashicons dashicons-controls-play"></span>
						<?php esc_html_e( 'Continuar Upload (Próximo Lote)', 'codirun-codir2me-cdn' ); ?>
					</button>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'codir2me_cancel_upload', 'codir2me_cancel_upload_nonce' ); ?>
					<input type="hidden" name="action" value="codir2me_cancel_upload">
					<input type="hidden" name="upload_type" value="images">
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
				<p><?php esc_html_e( 'Isso irá fazer upload de todas as imagens da sua biblioteca de mídia para o Cloudflare R2.', 'codirun-codir2me-cdn' ); ?></p>
				<p><?php esc_html_e( 'O processo é executado em etapas para evitar sobrecarga do servidor. Use as opções acima para selecionar quais tamanhos de miniaturas enviar.', 'codirun-codir2me-cdn' ); ?></p>
				
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="codir2me-start-form">
					<?php wp_nonce_field( 'codir2me_scan_images', 'codir2me_scan_images_nonce' ); ?>
					<input type="hidden" name="action" value="codir2me_scan_images">
					<button type="submit" name="codir2me_scan_images" class="button button-primary">
						<span class="dashicons dashicons-cloud-upload"></span>
						<?php esc_html_e( 'Iniciar Upload de Imagens', 'codirun-codir2me-cdn' ); ?>
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
		?>
		<div class="codir2me-sidebar">
			<div class="codir2me-sidebar-widget">
				<h3><?php esc_html_e( 'Sobre o CDN de Imagens', 'codirun-codir2me-cdn' ); ?></h3>
				<div class="codir2me-widget-content">
					<p><?php esc_html_e( 'O CDN de Imagens otimiza o carregamento de todas as imagens do seu site, transferindo-as para o Cloudflare R2.', 'codirun-codir2me-cdn' ); ?></p>
					<p><?php esc_html_e( 'Benefícios:', 'codirun-codir2me-cdn' ); ?></p>
					<ul>
						<li><?php esc_html_e( 'Carregamento mais rápido de imagens', 'codirun-codir2me-cdn' ); ?></li>
						<li><?php esc_html_e( 'Redução significativa da carga no servidor', 'codirun-codir2me-cdn' ); ?></li>
						<li><?php esc_html_e( 'Economia de largura de banda', 'codirun-codir2me-cdn' ); ?></li>
						<li><?php esc_html_e( 'Melhor experiência para visitantes globais', 'codirun-codir2me-cdn' ); ?></li>
					</ul>
				</div>
			</div>
			
			<div class="codir2me-sidebar-widget">
				<h3><?php esc_html_e( 'Dicas de Uso', 'codirun-codir2me-cdn' ); ?></h3>
				<div class="codir2me-widget-content">
					<ul class="codir2me-tips-list">
						<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Comece com um lote pequeno para testar a configuração', 'codirun-codir2me-cdn' ); ?></li>
						<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Reduza o número de miniaturas para economizar espaço', 'codirun-codir2me-cdn' ); ?></li>
						<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Para sites grandes, faça o upload em partes ao longo do tempo', 'codirun-codir2me-cdn' ); ?></li>
						<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Ative o CDN apenas após concluir o upload de todas as imagens', 'codirun-codir2me-cdn' ); ?></li>
					</ul>
				</div>
			</div>
			
			<div class="codir2me-sidebar-widget">
				<h3><?php esc_html_e( 'Upload Automático de Miniaturas', 'codirun-codir2me-cdn' ); ?></h3>
				<div class="codir2me-widget-content">
					<p><?php esc_html_e( 'Quando o upload automático de miniaturas está ativado, todos os tamanhos de miniaturas configurados serão enviados automaticamente para o R2 quando novas imagens forem adicionadas à biblioteca de mídia.', 'codirun-codir2me-cdn' ); ?></p>
					<p><?php esc_html_e( 'Por padrão, apenas as imagens originais são enviadas automaticamente. Ativando essa opção, você garante que as miniaturas também sejam enviadas.', 'codirun-codir2me-cdn' ); ?></p>
				</div>
			</div>
		</div>
		<?php
	}
}
