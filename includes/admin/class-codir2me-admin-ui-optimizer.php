<?php
/**
 * Classe que gerencia a interface de usuário para a otimização de imagens
 * Versão aprimorada com suporte a WebP e AVIF
 *
 * @package Codirun_R2_Media_Static_CDN
 */

// Evitar acesso direto ao arquivo.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classe responsável pela interface de usuário do otimizador de imagens.
 */
class CODIR2ME_Admin_UI_Optimizer {
	/**
	 * Instância da classe de administração.
	 *
	 * @var codir2me_Admin
	 */
	private $admin;

	/**
	 * Instância da classe otimizadora.
	 *
	 * @var CODIR2ME_Image_Optimizer
	 */
	private $optimizer;

	/**
	 * Construtor
	 *
	 * @param codir2me_Admin $admin Instância da classe de administração.
	 */
	public function __construct( $admin ) {
		$this->admin     = $admin;
		$this->optimizer = new CODIR2ME_Image_Optimizer();

		// Adicionar hook para processamento AJAX do teste de otimização.
		add_action( 'wp_ajax_codir2me_test_image_optimization', array( $this, 'ajax_test_image_optimization' ) );

		// Adicionar ação para configurações de formato.
		add_action( 'admin_init', array( $this, 'codir2me_register_format_settings' ) );
	}

	/**
	 * Determina a aba atual baseado na página ou no parâmetro tab
	 *
	 * @return string A aba atual
	 */
	private function codir2me_determine_current_tab() {
		// Verificar se estamos em uma página dedicada.
		$screen = get_current_screen();

		if ( isset( $screen->id ) ) {
			// Verificar se o ID da tela contém 'optimization'.
			if ( false !== strpos( $screen->id, 'optimization' ) ) {
				return 'optimization';
			}

			// Mapear outros IDs de tela para abas (se necessário).
			$screen_to_tab_map = array(
				'codirun-r2-media-static-cdn_page_codirun-codir2me-cdn-optimization' => 'optimization',
				'toplevel_page_codirun-codir2me-cdn-optimization'                     => 'optimization',
			);

			if ( isset( $screen_to_tab_map[ $screen->id ] ) ) {
				return $screen_to_tab_map[ $screen->id ];
			}
		}

		// Se não for uma página dedicada, verificar o parâmetro tab (sistema antigo).
		$current_tab = 'general';

		// Verificar se o nonce é válido.
		$nonce_verified = false;
		if ( isset( $_GET['_wpnonce'] ) ) {
			$nonce          = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) );
			$nonce_verified = wp_verify_nonce( $nonce, 'codir2me_admin_tab' );
		}

		// Verificar referência HTTP para redirecionamentos internos.
		$http_referer         = isset( $_SERVER['HTTP_REFERER'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
		$is_internal_redirect = ! empty( $http_referer ) && 0 === strpos( $http_referer, admin_url() );

		// Processar o parâmetro tab somente se verificações de segurança passarem.
		if ( isset( $_GET['tab'] ) && ( $nonce_verified || $is_internal_redirect ) ) {
			$current_tab = sanitize_text_field( wp_unslash( $_GET['tab'] ) );
		}

		return $current_tab;
	}

	/**
	 * Renderiza a aba de otimização de imagens
	 */
	public function codir2me_render() {
		// Processar formulário se enviado.
		if ( isset( $_POST['codir2me_optimization_submit'] ) && check_admin_referer( 'codir2me_optimization_action', 'codir2me_optimization_nonce' ) ) {
			$this->codir2me_process_form();
		}

		// Processar redefinição de estatísticas.
		if ( isset( $_POST['codir2me_reset_stats'] ) && check_admin_referer( 'codir2me_stats_reset', 'codir2me_stats_reset_nonce' ) ) {
			$this->optimizer->codir2me_reset_stats();

			add_action(
				'admin_notices',
				function () {
					?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Estatísticas de otimização redefinidas com sucesso!', 'codirun-codir2me-cdn' ); ?></p>
				</div>
					<?php
				}
			);
		}

		// Obter opções atuais.
		$options             = get_option( 'codir2me_image_optimization_options', array() );
		$enable_optimization = isset( $options['enable_optimization'] ) ? (bool) $options['enable_optimization'] : false;
		$level               = isset( $options['optimization_level'] ) ? $options['optimization_level'] : 'balanced';
		$jpeg_quality        = isset( $options['jpeg_quality'] ) ? intval( $options['jpeg_quality'] ) : 85;
		$png_compression     = isset( $options['png_compression'] ) ? intval( $options['png_compression'] ) : 7;
		$webp_quality        = isset( $options['webp_quality'] ) ? intval( $options['webp_quality'] ) : 80;
		$avif_quality        = isset( $options['avif_quality'] ) ? intval( $options['avif_quality'] ) : 75;
		$enable_webp         = isset( $options['enable_webp_conversion'] ) ? (bool) $options['enable_webp_conversion'] : false;
		$enable_avif         = isset( $options['enable_avif_conversion'] ) ? (bool) $options['enable_avif_conversion'] : false;
		$keep_original       = isset( $options['keep_original'] ) ? (bool) $options['keep_original'] : true;
		$html_element        = isset( $options['html_element'] ) ? $options['html_element'] : 'picture';

		// Obter estatísticas.
		$stats = $this->optimizer->codir2me_get_optimization_stats();

		// Verificar requisitos.
		$requirements_met = $this->codir2me_check_optimization_requirements();

		?>
		<div class="codir2me-tab-content">
			<div class="codir2me-flex-container">
				<div class="codir2me-main-column">
					<!-- Seção de Status -->
					<div class="codir2me-section">
						<h2><?php esc_html_e( 'Status da Otimização de Imagens', 'codirun-codir2me-cdn' ); ?></h2>
						
						<?php if ( ! $requirements_met['all_met'] ) : ?>
							<div class="codir2me-warning-text">
								<span class="dashicons dashicons-warning"></span>
								<strong><?php esc_html_e( 'Atenção:', 'codirun-codir2me-cdn' ); ?></strong> <?php esc_html_e( 'Alguns requisitos para otimização de imagens não estão disponíveis no seu servidor.', 'codirun-codir2me-cdn' ); ?>
							</div>
							
							<div class="codir2me-requirements-list">
								<p><strong><?php esc_html_e( 'Status dos requisitos:', 'codirun-codir2me-cdn' ); ?></strong></p>
								<ul>
									<li>
										<span class="codir2me-requirement-status <?php echo esc_attr( $requirements_met['gd'] ? 'met' : 'not-met' ); ?>">
											<span class="dashicons <?php echo esc_attr( $requirements_met['gd'] ? 'dashicons-yes-alt' : 'dashicons-no-alt' ); ?>"></span>
										</span>
										<?php esc_html_e( 'Extensão GD:', 'codirun-codir2me-cdn' ); ?> <?php echo esc_html( $requirements_met['gd'] ? __( 'Disponível', 'codirun-codir2me-cdn' ) : __( 'Não disponível', 'codirun-codir2me-cdn' ) ); ?>
									</li>
									<li>
										<span class="codir2me-requirement-status <?php echo esc_attr( $requirements_met['imagick'] ? 'met' : 'not-met' ); ?>">
											<span class="dashicons <?php echo esc_attr( $requirements_met['imagick'] ? 'dashicons-yes-alt' : 'dashicons-no-alt' ); ?>"></span>
										</span>
										<?php esc_html_e( 'Extensão Imagick:', 'codirun-codir2me-cdn' ); ?> <?php echo esc_html( $requirements_met['imagick'] ? __( 'Disponível', 'codirun-codir2me-cdn' ) : __( 'Não disponível', 'codirun-codir2me-cdn' ) ); ?>
									</li>
									<li>
										<span class="codir2me-requirement-status <?php echo esc_attr( $requirements_met['webp'] ? 'met' : 'not-met' ); ?>">
											<span class="dashicons <?php echo esc_attr( $requirements_met['webp'] ? 'dashicons-yes-alt' : 'dashicons-no-alt' ); ?>"></span>
										</span>
										<?php esc_html_e( 'Suporte a WebP:', 'codirun-codir2me-cdn' ); ?> <?php echo esc_html( $requirements_met['webp'] ? __( 'Disponível', 'codirun-codir2me-cdn' ) : __( 'Não disponível', 'codirun-codir2me-cdn' ) ); ?>
									</li>
									<li>
										<span class="codir2me-requirement-status <?php echo esc_attr( $requirements_met['avif'] ? 'met' : 'not-met' ); ?>">
											<span class="dashicons <?php echo esc_attr( $requirements_met['avif'] ? 'dashicons-yes-alt' : 'dashicons-no-alt' ); ?>"></span>
										</span>
										<?php esc_html_e( 'Suporte a AVIF:', 'codirun-codir2me-cdn' ); ?> <?php echo esc_html( $requirements_met['avif'] ? __( 'Disponível', 'codirun-codir2me-cdn' ) : __( 'Não disponível', 'codirun-codir2me-cdn' ) ); ?>
									</li>
								</ul>
								
								<?php if ( ! $requirements_met['any_met'] ) : ?>
									<p class="codir2me-critical-warning"><?php esc_html_e( 'A otimização de imagens requer pelo menos uma das extensões: GD ou Imagick. Por favor, contate seu provedor de hospedagem para ativar uma delas.', 'codirun-codir2me-cdn' ); ?></p>
								<?php endif; ?>
							</div>
						<?php endif; ?>
						
						<div class="codir2me-cdn-status-cards">
							<div class="codir2me-status-card">
								<div class="codir2me-status-icon <?php echo esc_attr( $enable_optimization ? 'active' : 'inactive' ); ?>">
									<span class="dashicons <?php echo esc_attr( $enable_optimization ? 'dashicons-yes-alt' : 'dashicons-no-alt' ); ?>"></span>
								</div>
								<div class="codir2me-status-details">
									<h3><?php esc_html_e( 'Status da Otimização', 'codirun-codir2me-cdn' ); ?></h3>
									<p class="codir2me-status <?php echo esc_attr( $enable_optimization ? 'active' : 'inactive' ); ?>">
										<?php echo esc_html( $enable_optimization ? __( 'Ativada', 'codirun-codir2me-cdn' ) : __( 'Desativada', 'codirun-codir2me-cdn' ) ); ?>
									</p>
								</div>
							</div>
							
							<div class="codir2me-status-card">
								<div class="codir2me-status-icon">
									<span class="dashicons dashicons-images-alt2"></span>
								</div>
								<div class="codir2me-status-details">
									<h3><?php esc_html_e( 'Imagens Otimizadas', 'codirun-codir2me-cdn' ); ?></h3>
									<p class="codir2me-status-count"><?php echo esc_html( number_format( $stats['total_optimized'] ) ); ?></p>
								</div>
							</div>
							
							<div class="codir2me-status-card">
								<div class="codir2me-status-icon">
									<span class="dashicons dashicons-saved"></span>
								</div>
								<div class="codir2me-status-details">
									<h3><?php esc_html_e( 'Espaço Economizado', 'codirun-codir2me-cdn' ); ?></h3>
									<p class="codir2me-status-count"><?php echo esc_html( $this->codir2me_format_bytes( $stats['total_bytes_saved'] ) ); ?></p>
								</div>
							</div>
						</div>
						
						<?php if ( 0 < $stats['total_optimized'] ) : ?>
						<div class="codir2me-optimization-stats">
							<h3><?php esc_html_e( 'Estatísticas Detalhadas', 'codirun-codir2me-cdn' ); ?></h3>
							<table class="widefat striped">
								<tbody>
									<tr>
										<td><strong><?php esc_html_e( 'Imagens Processadas:', 'codirun-codir2me-cdn' ); ?></strong></td>
										<td><?php echo esc_html( number_format( $stats['total_processed'] ) ); ?></td>
									</tr>
									<tr>
										<td><strong><?php esc_html_e( 'Imagens Otimizadas:', 'codirun-codir2me-cdn' ); ?></strong></td>
										<td><?php echo esc_html( number_format( $stats['total_optimized'] ) ); ?></td>
									</tr>
									<tr>
										<td><strong><?php esc_html_e( 'Taxa de Sucesso:', 'codirun-codir2me-cdn' ); ?></strong></td>
										<td><?php echo esc_html( 0 < $stats['total_processed'] ? round( ( $stats['total_optimized'] / $stats['total_processed'] ) * 100, 2 ) . '%' : '0%' ); ?></td>
									</tr>
									<tr>
										<td><strong><?php esc_html_e( 'Espaço Total Economizado:', 'codirun-codir2me-cdn' ); ?></strong></td>
										<td><?php echo esc_html( $this->codir2me_format_bytes( $stats['total_bytes_saved'] ) ); ?></td>
									</tr>
									<tr>
										<td><strong><?php esc_html_e( 'Imagens Convertidas para WebP:', 'codirun-codir2me-cdn' ); ?></strong></td>
										<td><?php echo esc_html( number_format( $stats['webp_converted'] ) ); ?></td>
									</tr>
									<tr>
										<td><strong><?php esc_html_e( 'Espaço Economizado com WebP:', 'codirun-codir2me-cdn' ); ?></strong></td>
										<td><?php echo esc_html( $this->codir2me_format_bytes( $stats['webp_bytes_saved'] ) ); ?></td>
									</tr>
									<?php if ( isset( $stats['avif_converted'] ) && 0 < $stats['avif_converted'] ) : ?>
									<tr>
										<td><strong><?php esc_html_e( 'Imagens Convertidas para AVIF:', 'codirun-codir2me-cdn' ); ?></strong></td>
										<td><?php echo esc_html( number_format( $stats['avif_converted'] ) ); ?></td>
									</tr>
									<tr>
										<td><strong><?php esc_html_e( 'Espaço Economizado com AVIF:', 'codirun-codir2me-cdn' ); ?></strong></td>
										<td><?php echo esc_html( $this->codir2me_format_bytes( $stats['avif_bytes_saved'] ) ); ?></td>
									</tr>
									<?php endif; ?>
									<?php if ( 0 < $stats['last_processed'] ) : ?>
									<tr>
										<td><strong><?php esc_html_e( 'Última Otimização:', 'codirun-codir2me-cdn' ); ?></strong></td>
										<td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $stats['last_processed'] ) ); ?></td>
									</tr>
									<?php endif; ?>
								</tbody>
							</table>
							
							<form method="post" action="" id="codir2meResetStatsForm">
								<?php wp_nonce_field( 'codir2me_stats_reset', 'codir2me_stats_reset_nonce' ); ?>
								<p class="submit">
									<input type="submit" name="codir2me_reset_stats" class="button button-secondary" value="<?php esc_attr_e( 'Redefinir Estatísticas', 'codirun-codir2me-cdn' ); ?>">
								</p>
							</form>
						</div>
						<?php endif; ?>
					</div>
					
					<!-- Seção de Configuração -->
					<div class="codir2me-section">
						<h2><?php esc_html_e( 'Configurações de Otimização', 'codirun-codir2me-cdn' ); ?></h2>
						
						<form method="post" action="" id="codir2meOptimizationForm">
							<?php wp_nonce_field( 'codir2me_optimization_action', 'codir2me_optimization_nonce' ); ?>
							
							<table class="form-table">
								<tr>
									<th><?php esc_html_e( 'Ativar Otimização', 'codirun-codir2me-cdn' ); ?></th>
									<td>
										<label class="codir2me-toggle-switch">
											<input type="checkbox" name="codir2me_enable_optimization" value="1" <?php checked( $enable_optimization ); ?> <?php disabled( ! $requirements_met['any_met'] ); ?> />
											<span class="codir2me-toggle-slider"></span>
										</label>
										<span class="description">
											<?php if ( $requirements_met['any_met'] ) : ?>
												<?php esc_html_e( 'Otimiza automaticamente as imagens antes de enviar para o R2', 'codirun-codir2me-cdn' ); ?>
											<?php else : ?>
												<?php esc_html_e( 'Não disponível - requisitos não atendidos', 'codirun-codir2me-cdn' ); ?>
											<?php endif; ?>
										</span>
									</td>
								</tr>
								
								<tr>
									<th><?php esc_html_e( 'Nível de Otimização', 'codirun-codir2me-cdn' ); ?></th>
									<td>
										<fieldset>
											<legend class="screen-reader-text"><?php esc_html_e( 'Nível de Otimização', 'codirun-codir2me-cdn' ); ?></legend>
											<div class="codir2me-optimization-levels">
												<div class="codir2me-optimization-level light <?php echo esc_attr( 'light' === $level ? 'selected' : '' ); ?>">
													<label>
														<input type="radio" name="codir2me_optimization_level" value="light" <?php checked( $level, 'light' ); ?> <?php disabled( ! $requirements_met['any_met'] ); ?> />
														<strong><?php esc_html_e( 'Leve', 'codirun-codir2me-cdn' ); ?></strong>
														<div class="level-desc"><?php esc_html_e( 'Compressão mínima com alta qualidade', 'codirun-codir2me-cdn' ); ?></div>
														<div class="level-indicator"><span style="width: 33%;"></span></div>
													</label>
												</div>
												
												<div class="codir2me-optimization-level balanced <?php echo esc_attr( 'balanced' === $level ? 'selected' : '' ); ?>">
													<label>
														<input type="radio" name="codir2me_optimization_level" value="balanced" <?php checked( $level, 'balanced' ); ?> <?php disabled( ! $requirements_met['any_met'] ); ?> />
														<strong><?php esc_html_e( 'Balanceado', 'codirun-codir2me-cdn' ); ?></strong>
														<div class="level-desc"><?php esc_html_e( 'Equilíbrio entre tamanho e qualidade', 'codirun-codir2me-cdn' ); ?></div>
														<div class="level-indicator"><span style="width: 66%;"></span></div>
													</label>
												</div>
												
												<div class="codir2me-optimization-level aggressive <?php echo esc_attr( 'aggressive' === $level ? 'selected' : '' ); ?>">
													<label>
														<input type="radio" name="codir2me_optimization_level" value="aggressive" <?php checked( $level, 'aggressive' ); ?> <?php disabled( ! $requirements_met['any_met'] ); ?> />
														<strong><?php esc_html_e( 'Agressivo', 'codirun-codir2me-cdn' ); ?></strong>
														<div class="level-desc"><?php esc_html_e( 'Máxima compressão possível', 'codirun-codir2me-cdn' ); ?></div>
														<div class="level-indicator"><span style="width: 100%;"></span></div>
													</label>
												</div>
											</div>
										</fieldset>
									</td>
								</tr>
								
								<tr>
									<th><?php esc_html_e( 'Formatos de Próxima Geração', 'codirun-codir2me-cdn' ); ?></th>
									<td>
										<div class="codir2me-next-gen-formats <?php echo esc_attr( ( ! $enable_optimization ) ? 'disabled-option' : '' ); ?>">
											<div class="codir2me-format-option">
												<label class="codir2me-toggle-switch">
													<input type="checkbox" name="codir2me_enable_webp" value="1" <?php checked( $enable_webp ); ?> <?php disabled( ! $requirements_met['webp'] || ! $enable_optimization ); ?> />
													<span class="codir2me-toggle-slider"></span>
												</label>
												<span class="codir2me-format-label">
													<strong>WebP</strong> <?php echo esc_html( ! $requirements_met['webp'] ? __( '(não suportado)', 'codirun-codir2me-cdn' ) : '' ); ?>
												</span>
												<span class="description"><?php esc_html_e( 'Gera versões WebP das imagens (~30% menor que JPEG/PNG)', 'codirun-codir2me-cdn' ); ?></span>
											</div>
											
											<div class="codir2me-format-option">
												<label class="codir2me-toggle-switch">
													<input type="checkbox" name="codir2me_enable_avif" value="1" 
														<?php checked( $enable_avif ); ?> 
														<?php disabled( ! $requirements_met['avif'] || ! $enable_optimization ); ?> />
													<span class="codir2me-toggle-slider"></span>
												</label>
												<span class="codir2me-format-label">
													<strong>AVIF</strong> <?php echo esc_html( ! $requirements_met['avif'] ? __( '(não suportado)', 'codirun-codir2me-cdn' ) : '' ); ?>
												</span>
												<span class="description"><?php esc_html_e( 'Gera versões AVIF das imagens (até 50% menor que WebP)', 'codirun-codir2me-cdn' ); ?></span>
											</div>
											
											<div class="codir2me-format-option">
												<label class="codir2me-toggle-switch">
													<input type="checkbox" 
															name="codir2me_convert_thumbnails" 
															value="1" 
															<?php checked( get_option( 'codir2me_convert_thumbnails_option', false ) ); ?>
															<?php disabled( ! $enable_optimization ); ?> />
													<span class="codir2me-toggle-slider"></span>
												</label>
												<span class="codir2me-format-label">
													<strong><?php esc_html_e( 'Converter Miniaturas', 'codirun-codir2me-cdn' ); ?></strong>
												</span>
												<span class="description"><?php esc_html_e( 'Aplica as conversões WebP/AVIF também nas miniaturas', 'codirun-codir2me-cdn' ); ?></span>
											</div>
										</div>

										<div class="codir2me-format-info">
											<p><?php esc_html_e( 'O plugin entregará automaticamente o melhor formato compatível com o navegador do visitante. Navegadores mais antigos receberão o formato original.', 'codirun-codir2me-cdn' ); ?></p>
										</div>

									</td>
								</tr>
								
								<tr>
									<th><?php esc_html_e( 'Manter Original', 'codirun-codir2me-cdn' ); ?></th>
									<td>
										<label class="codir2me-toggle-switch">
											<input type="checkbox" name="codir2me_keep_original" value="1" <?php checked( $keep_original ); ?> <?php disabled( ! $requirements_met['any_met'] ); ?> />
											<span class="codir2me-toggle-slider"></span>
										</label>
										<span class="description"><?php esc_html_e( 'Manter a versão original da imagem no R2 após a conversão (recomendado para compatibilidade)', 'codirun-codir2me-cdn' ); ?></span>
									</td>
								</tr>
								
								<tr>
									<th><?php esc_html_e( 'Configurações Avançadas', 'codirun-codir2me-cdn' ); ?></th>
									<td>
										<button type="button" class="button button-secondary" id="codir2me-toggle-advanced"><?php esc_html_e( 'Mostrar Configurações Avançadas', 'codirun-codir2me-cdn' ); ?></button>
									</td>
								</tr>
								
								<tr class="codir2me-advanced-settings" style="display: none;">
									<th><?php esc_html_e( 'Qualidade JPEG', 'codirun-codir2me-cdn' ); ?></th>
									<td>
										<input type="range" name="codir2me_jpeg_quality" min="1" max="100" value="<?php echo esc_attr( $jpeg_quality ); ?>" class="codir2me-range-slider" id="codir2me-jpeg-quality" <?php disabled( ! $requirements_met['any_met'] ); ?> />
										<span class="codir2me-range-value"><?php echo esc_html( $jpeg_quality ); ?></span>
										<p class="description"><?php esc_html_e( 'Valor entre 1 (menor tamanho) e 100 (melhor qualidade). Recomendado: 75-85', 'codirun-codir2me-cdn' ); ?></p>
									</td>
								</tr>
								
								<tr class="codir2me-advanced-settings" style="display: none;">
									<th><?php esc_html_e( 'Compressão PNG', 'codirun-codir2me-cdn' ); ?></th>
									<td>
										<input type="range" name="codir2me_png_compression" min="0" max="9" value="<?php echo esc_attr( $png_compression ); ?>" class="codir2me-range-slider" id="codir2me-png-compression" <?php disabled( ! $requirements_met['any_met'] ); ?> />
										<span class="codir2me-range-value"><?php echo esc_html( $png_compression ); ?></span>
										<p class="description"><?php esc_html_e( 'Valor entre 0 (sem compressão) e 9 (máxima compressão). Recomendado: 6-8', 'codirun-codir2me-cdn' ); ?></p>
									</td>
								</tr>
								
								<tr class="codir2me-advanced-settings" style="display: none;">
									<th><?php esc_html_e( 'Qualidade WebP', 'codirun-codir2me-cdn' ); ?></th>
									<td>
										<input type="range" name="codir2me_webp_quality" min="1" max="100" value="<?php echo esc_attr( $webp_quality ); ?>" class="codir2me-range-slider" id="codir2me-webp-quality" <?php disabled( ! $requirements_met['webp'] ); ?> />
										<span class="codir2me-range-value"><?php echo esc_html( $webp_quality ); ?></span>
										<p class="description"><?php esc_html_e( 'Valor entre 1 (menor tamanho) e 100 (melhor qualidade). Recomendado: 75-85', 'codirun-codir2me-cdn' ); ?></p>
									</td>
								</tr>
								
								<tr class="codir2me-advanced-settings" style="display: none;">
									<th><?php esc_html_e( 'Qualidade AVIF', 'codirun-codir2me-cdn' ); ?></th>
									<td>
										<input type="range" name="codir2me_avif_quality" min="1" max="100" value="<?php echo esc_attr( $avif_quality ); ?>" class="codir2me-range-slider" id="codir2me-avif-quality" <?php disabled( ! $requirements_met['avif'] ); ?> />
										<span class="codir2me-range-value"><?php echo esc_html( $avif_quality ); ?></span>
										<p class="description"><?php esc_html_e( 'Valor entre 1 (menor tamanho) e 100 (melhor qualidade). Recomendado: 60-75', 'codirun-codir2me-cdn' ); ?></p>
									</td>
								</tr>
								
								<tr class="codir2me-advanced-settings" style="display: none;">
									<th><?php esc_html_e( 'Elemento HTML', 'codirun-codir2me-cdn' ); ?></th>
									<td>
										<fieldset>
											<label>
												<input type="radio" name="codir2me_html_element" value="picture" <?php checked( $html_element, 'picture' ); ?> />
												<?php esc_html_e( 'Usar elemento &lt;picture&gt; (recomendado)', 'codirun-codir2me-cdn' ); ?>
											</label>
											<p class="description"><?php esc_html_e( 'Cria um elemento &lt;picture&gt; com várias fontes para compatibilidade máxima.', 'codirun-codir2me-cdn' ); ?></p>
											
											<label>
												<input type="radio" name="codir2me_html_element" value="img" <?php checked( $html_element, 'img' ); ?> />
												<?php esc_html_e( 'Usar apenas elemento &lt;img&gt;', 'codirun-codir2me-cdn' ); ?>
											</label>
											<p class="description"><?php esc_html_e( 'Mais simples, mas com menos compatibilidade para formatos modernos.', 'codirun-codir2me-cdn' ); ?></p>
										</fieldset>
									</td>
								</tr>
							</table>
							
							<?php if ( ! $requirements_met['any_met'] ) : ?>
								<div class="codir2me-warning-text" style="margin-top: 15px;">
									<span class="dashicons dashicons-warning"></span>
									<strong><?php esc_html_e( 'Importante:', 'codirun-codir2me-cdn' ); ?></strong> <?php esc_html_e( 'A otimização de imagens não está disponível porque seu servidor não possui os requisitos necessários.', 'codirun-codir2me-cdn' ); ?>
								</div>
							<?php endif; ?>
							
							<p class="submit">
								<input type="submit" name="codir2me_optimization_submit" class="button button-primary" value="<?php esc_attr_e( 'Salvar Configurações', 'codirun-codir2me-cdn' ); ?>" <?php disabled( ! $requirements_met['any_met'] ); ?>>
							</p>
						</form>
					</div>
					
					
				</div>
				
				<?php $this->codir2me_render_sidebar(); ?>
			</div>
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
				<h3><?php esc_html_e( 'Formatos de Imagem Modernos', 'codirun-codir2me-cdn' ); ?></h3>
				<div class="codir2me-widget-content">
					<p><?php esc_html_e( 'Utilizando formatos de próxima geração, você pode:', 'codirun-codir2me-cdn' ); ?></p>
					<ul class="codir2me-tips-list">
						<li><span class="dashicons dashicons-yes"></span> <strong>WebP:</strong> <?php esc_html_e( 'Reduzir ~30% vs. JPEG/PNG', 'codirun-codir2me-cdn' ); ?></li>
						<li><span class="dashicons dashicons-yes"></span> <strong>AVIF:</strong> <?php esc_html_e( 'Reduzir até 50% vs. WebP', 'codirun-codir2me-cdn' ); ?></li>
						<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Melhorar o desempenho do carregamento do site', 'codirun-codir2me-cdn' ); ?></li>
						<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Manter a compatibilidade com navegadores antigos', 'codirun-codir2me-cdn' ); ?></li>
					</ul>
					<div class="codir2me-browser-support">
						<p><strong><?php esc_html_e( 'Suporte em navegadores:', 'codirun-codir2me-cdn' ); ?></strong></p>
						<p>
							<span class="codir2me-browser-badge">WebP: Chrome, Firefox, Safari, Edge</span><br>
							<span class="codir2me-browser-badge">AVIF: Chrome, Firefox, Opera</span>
						</p>
					</div>
				</div>
			</div>
			
			<div class="codir2me-sidebar-widget">
				<h3><?php esc_html_e( 'Como funciona a entrega de imagens', 'codirun-codir2me-cdn' ); ?></h3>
				<div class="codir2me-widget-content">
					<p><?php esc_html_e( 'O plugin utiliza o elemento <code>&lt;picture&gt;</code> que permite:', 'codirun-codir2me-cdn' ); ?></p>
					<ol>
						<li><?php esc_html_e( 'Oferecer o formato mais otimizado primeiro (AVIF)', 'codirun-codir2me-cdn' ); ?></li>
						<li><?php esc_html_e( 'Recorrer ao segundo melhor formato (WebP) se necessário', 'codirun-codir2me-cdn' ); ?></li>
						<li><?php esc_html_e( 'Garantir compatibilidade usando a imagem original como fallback', 'codirun-codir2me-cdn' ); ?></li>
					</ol>
					<p><?php esc_html_e( 'Exemplo:', 'codirun-codir2me-cdn' ); ?></p>
					<pre style="font-size: 11px; background-color: #f9f9f9; padding: 10px; overflow: auto;">
&lt;picture&gt;
&lt;source srcset="imagem.avif" type="image/avif"&gt;
&lt;source srcset="imagem.webp" type="image/webp"&gt;
&lt;img src="imagem.jpg" alt="Descrição"&gt;
&lt;/picture&gt;</pre>
				</div>
			</div>
			
			<div class="codir2me-sidebar-widget">
				<h3><?php esc_html_e( 'Dicas de Otimização', 'codirun-codir2me-cdn' ); ?></h3>
				<div class="codir2me-widget-content">
					<ul class="codir2me-tips-list">
						<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Ative ambos WebP e AVIF para máxima economia', 'codirun-codir2me-cdn' ); ?></li>
						<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Use nível "Balanceado" para a maioria dos sites', 'codirun-codir2me-cdn' ); ?></li>
						<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Para e-commerce, use qualidade mais alta para imagens de produtos', 'codirun-codir2me-cdn' ); ?></li>
						<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Mantenha o formato original para garantir compatibilidade', 'codirun-codir2me-cdn' ); ?></li>
					</ul>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Processa o formulário de configurações.
	 * Versão que preserva as configurações avançadas.
	 */
	public function codir2me_process_form() {
		// Verificar o nonce.
		if (
			! isset( $_POST['codir2me_optimization_nonce'] ) ||
			! wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_POST['codir2me_optimization_nonce'] ) ),
				'codir2me_optimization_action'
			)
		) {
			add_action(
				'admin_notices',
				function () {
					?>
				<div class="notice notice-error is-dismissible">
					<p><?php esc_html_e( 'Erro de segurança ao salvar as configurações. Por favor, tente novamente.', 'codirun-codir2me-cdn' ); ?></p>
				</div>
					<?php
				}
			);
			return;
		}

		// Verificar se os requisitos estão disponíveis.
		if ( ! $this->codir2me_check_optimization_requirements()['any_met'] ) {
			add_action(
				'admin_notices',
				function () {
					?>
				<div class="notice notice-error is-dismissible">
					<p><?php esc_html_e( 'Não foi possível salvar as configurações de otimização. Os requisitos necessários não estão disponíveis no servidor.', 'codirun-codir2me-cdn' ); ?></p>
				</div>
					<?php
				}
			);
			return;
		}

		// Preparar opções com sanitização adequada - respeitando os valores exatos fornecidos pelo usuário.
		$options = array(
			'enable_optimization'    => isset( $_POST['codir2me_enable_optimization'] ) ? true : false,
			'optimization_level'     => isset( $_POST['codir2me_optimization_level'] ) ? sanitize_text_field( wp_unslash( $_POST['codir2me_optimization_level'] ) ) : 'balanced',
			'jpeg_quality'           => isset( $_POST['codir2me_jpeg_quality'] ) ? intval( $_POST['codir2me_jpeg_quality'] ) : 85,
			'png_compression'        => isset( $_POST['codir2me_png_compression'] ) ? intval( $_POST['codir2me_png_compression'] ) : 7,
			'webp_quality'           => isset( $_POST['codir2me_webp_quality'] ) ? intval( $_POST['codir2me_webp_quality'] ) : 80,
			'avif_quality'           => isset( $_POST['codir2me_avif_quality'] ) ? intval( $_POST['codir2me_avif_quality'] ) : 75,
			'enable_webp_conversion' => isset( $_POST['codir2me_enable_webp'] ) ? true : false,
			'enable_avif_conversion' => isset( $_POST['codir2me_enable_avif'] ) ? true : false,
			'keep_original'          => isset( $_POST['codir2me_keep_original'] ) ? true : false,
			'html_element'           => isset( $_POST['codir2me_html_element'] ) ? sanitize_text_field( wp_unslash( $_POST['codir2me_html_element'] ) ) : 'picture',
			'convert_thumbnails'     => isset( $_POST['codir2me_convert_thumbnails'] ) ? true : false,
		);

		// Validar valores sem modificá-los além do necessário.
		if ( ! in_array( $options['optimization_level'], array( 'light', 'balanced', 'aggressive' ), true ) ) {
			$options['optimization_level'] = 'balanced';
		}

		// Restringir para faixas válidas.
		$options['jpeg_quality']    = max( 1, min( 100, $options['jpeg_quality'] ) );
		$options['png_compression'] = max( 0, min( 9, $options['png_compression'] ) );
		$options['webp_quality']    = max( 1, min( 100, $options['webp_quality'] ) );
		$options['avif_quality']    = max( 1, min( 100, $options['avif_quality'] ) );

		// Salvar as configurações - evitando o problema de índice 'default'.
		$saved = false;
		try {
			// Método 1: Delete + Add (evita problemas com 'default').
			delete_option( 'codir2me_image_optimization_options' );
			$saved = add_option( 'codir2me_image_optimization_options', $options );
		} catch ( Exception $e ) {
			// Método 2: Fallback para update_option se add_option falhar.
			$saved = update_option( 'codir2me_image_optimization_options', $options );
		}

		// Atualizar estado de ativação do AVIF e WebP.
		update_option( 'codir2me_format_avif_enabled', $options['enable_avif_conversion'] );
		update_option( 'codir2me_format_webp_enabled', $options['enable_webp_conversion'] );

		$convert_thumbnails_value = isset( $_POST['codir2me_convert_thumbnails'] ) ? true : false;
		update_option( 'codir2me_convert_thumbnails_option', $convert_thumbnails_value );

		// Adicionar mensagem de sucesso.
		add_action(
			'admin_notices',
			function () {
				?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'Configurações de otimização de imagens salvas com sucesso!', 'codirun-codir2me-cdn' ); ?></p>
			</div>
				<?php
			}
		);

		return true;
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
	 * Registra configurações adicionais para o controle de formatos de imagem.
	 */
	public function codir2me_register_format_settings() {
		register_setting(
			'codir2me_optimization_settings',
			'codir2me_format_order',
			array(
				'type'              => 'array',
				'default'           => array( 'avif', 'webp', 'original' ),
				'sanitize_callback' => array( $this, 'codir2me_sanitize_format_order' ),
			)
		);

		register_setting(
			'codir2me_optimization_settings',
			'codir2me_format_avif_enabled',
			array(
				'type'              => 'boolean',
				'default'           => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
			)
		);

		register_setting(
			'codir2me_optimization_settings',
			'codir2me_format_webp_enabled',
			array(
				'type'              => 'boolean',
				'default'           => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
			)
		);
	}

	/**
	 * Sanitiza a ordem dos formatos.
	 *
	 * @param array $value Valor a ser sanitizado.
	 * @return array Valor sanitizado.
	 */
	public function codir2me_sanitize_format_order( $value ) {
		if ( ! is_array( $value ) ) {
			return array( 'avif', 'webp', 'original' );
		}

		// Verificar se todos os formatos estão presentes.
		$required_formats = array( 'avif', 'webp', 'original' );
		$sanitized        = array();

		// Sanitizar cada item do array.
		foreach ( $value as $format ) {
			$format = sanitize_text_field( $format );
			if ( in_array( $format, $required_formats, true ) ) {
				$sanitized[] = $format;
			}
		}

		// Adicionar formatos que faltam ao final.
		foreach ( $required_formats as $format ) {
			if ( ! in_array( $format, $sanitized, true ) ) {
				$sanitized[] = $format;
			}
		}

		return $sanitized;
	}

	/**
	 * Formata bytes em uma unidade legível.
	 *
	 * @param int $bytes Número de bytes.
	 * @param int $precision Precisão decimal.
	 * @return string Tamanho formatado.
	 */
	private function codir2me_format_bytes( $bytes, $precision = 2 ) {
		if ( 0 >= $bytes ) {
			return '0 B';
		}

		$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
		$pow   = floor( log( $bytes ) / log( 1024 ) );
		$pow   = min( $pow, count( $units ) - 1 );

		return round( $bytes / pow( 1024, $pow ), $precision ) . ' ' . $units[ $pow ];
	}
}
