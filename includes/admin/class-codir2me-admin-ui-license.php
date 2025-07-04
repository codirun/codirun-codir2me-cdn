<?php
/**
 * Classe que gerencia a interface de licenciamento para recursos premium
 *
 * @package Codirun_R2_Media_Static_CDN
 */

// Evitar acesso direto ao arquivo.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classe responsável por gerenciar a interface de licenciamento para recursos premium.
 */
class CODIR2ME_Admin_UI_License {

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
	 * Verifica o idioma atual do WordPress para determinar qual moeda exibir
	 *
	 * @return array Informações de preço baseadas no idioma.
	 */
	private function codir2me_get_price_by_locale() {
		// Obter o idioma atual do WordPress.
		$locale = get_locale();

		// Definir valores padrão (Português Brasil - Real).
		$price_info = array(
			'currency_symbol'   => 'R$',
			'price_amount'      => '97',
			'period_text'       => '/ano',
			'trial_text'        => '7 DIAS GRÁTIS',
			'trial_description' => 'Ative hoje e teste todos os recursos premium por 7 dias completos. A cobrança só acontecerá após esse período — se não estiver satisfeito, cancele antes e não será cobrado.',
			'button_text'       => 'Iniciar Avaliação',
			'stripe_link'       => 'https://codirun.com/comprar/codir2me',
		);

		// Se o idioma for inglês, mudar para Dólar.
		if ( 0 === strpos( $locale, 'en_' ) ) {
			$price_info = array(
				'currency_symbol'   => '$',
				'price_amount'      => '19',
				'period_text'       => '/year',
				'trial_text'        => '7 DAYS FREE',
				'trial_description' => 'Activate today and try all premium features for a full 7 days. Charges will only occur after this period — if you\'re not satisfied, cancel beforehand and you won\'t be charged.',
				'button_text'       => 'Start Free Trial',
				'stripe_link'       => 'https://codirun.com/checkout/codir2me',
			);
		}

		return $price_info;
	}

	/**
	 * Registra e enfileira os scripts e estilos necessários para a página de licença
	 */
	public function codir2me_enqueue_license_assets() {
		// Registrar e enfileirar CSS.
		wp_register_style(
			'codir2me-license-style',
			CODIR2ME_CDN_PLUGIN_URL . 'assets/css/license-admin.css',
			array(),
			CODIR2ME_CDN_VERSION
		);
		wp_enqueue_style( 'codir2me-license-style' );

		// Registrar e enfileirar JavaScript.
		wp_register_script(
			'codir2me-license-script',
			CODIR2ME_CDN_PLUGIN_URL . 'assets/js/license-admin.js',
			array( 'jquery' ),
			CODIR2ME_CDN_VERSION,
			true
		);

		// Adicionar variáveis para o script.
		wp_localize_script(
			'codir2me-license-script',
			'codir2me_license_vars',
			array(
				'ajaxurl'               => admin_url( 'admin-ajax.php' ),
				'confirm_deactivate'    => __( 'Tem certeza que deseja desativar sua licença? Você perderá acesso aos recursos premium.', 'codirun-codir2me-cdn' ),
				'confirm_domain_change' => __( 'Tem certeza que deseja mudar o domínio da licença para', 'codirun-codir2me-cdn' ) . ' %s? ' . __( 'Isso só pode ser feito uma vez por semana.', 'codirun-codir2me-cdn' ),
				'error_empty_license'   => __( 'Por favor, insira uma chave de licença válida.', 'codirun-codir2me-cdn' ),
				'error_invalid_email'   => __( 'Por favor, insira um email válido.', 'codirun-codir2me-cdn' ),
				'error_empty_domain'    => __( 'Por favor, insira um domínio válido.', 'codirun-codir2me-cdn' ),
				'error_server'          => __( 'Ocorreu um erro ao conectar com o servidor. Por favor, tente novamente.', 'codirun-codir2me-cdn' ),
			)
		);

		wp_enqueue_script( 'codir2me-license-script' );
	}

	/**
	 * Renderiza a aba de licenciamento
	 */
	public function codir2me_render() {
		$this->codir2me_enqueue_license_assets();
		// Verificar mensagens de erro.
		$error_message = '';
		if ( isset( $_GET['error'] ) && isset( $_GET['codir2me_error_nonce'] ) && wp_verify_nonce( sanitize_key( $_GET['codir2me_error_nonce'] ), 'codir2me_error_action' ) && 'premium_feature' === $_GET['error'] ) {
			$error_message = __( 'Você tentou acessar uma funcionalidade premium. Por favor, adquira uma licença para ter acesso.', 'codirun-codir2me-cdn' );
		}

		// Obter dados da licença.
		$license_key    = get_option( 'codir2me_license_key', '' );
		$license_email  = get_option( 'codir2me_license_email', '' );
		$license_status = get_option( 'codir2me_license_status', 'inactive' );
		$license_domain = get_option( 'codir2me_license_domain', '' );
		$license_expiry = intval( get_option( 'codir2me_license_expiry', 0 ) );

		// Verificar se está em período de teste.
		$is_trial = false;
		if ( class_exists( 'codir2me_License_Manager' ) ) {
			global $codir2me_license_manager;
			if ( $codir2me_license_manager ) {
				$is_trial = get_option( 'codir2me_license_is_trial', false );
			}
		}

		// Verificar se a licença está ativa.
		$is_license_active = ( 'active' === $license_status );

		// Obter informações sobre o período de espera para troca de domínio.
		$cooldown_days     = 0;
		$expiry_date       = '';
		$days_until_expiry = 0;

		if ( $is_license_active && class_exists( 'codir2me_License_Manager' ) ) {
			global $codir2me_license_manager;
			if ( $codir2me_license_manager ) {
				$cooldown_days = $codir2me_license_manager->codir2me_get_domain_change_cooldown_days();

				// Obter data de expiração formatada e dias restantes.
				$expiry_date       = $codir2me_license_manager->codir2me_get_expiry_date_formatted();
				$days_until_expiry = $codir2me_license_manager->codir2me_get_days_until_expiry();

				// Debug para testar valores.
				codir2me_cdn_log( __( 'Status da licença:', 'codirun-codir2me-cdn' ) . ' ' . $license_status );
				codir2me_cdn_log( __( 'Data de expiração:', 'codirun-codir2me-cdn' ) . ' ' . gmdate( 'Y-m-d H:i:s', $license_expiry ) . ' (' . __( 'timestamp:', 'codirun-codir2me-cdn' ) . ' ' . $license_expiry . ')' );
			}
		}

		// Obter informações de preço com base no idioma.
		$price_info = $this->codir2me_get_price_by_locale();

		?>
		<div class="codir2me-tab-content">
			<div class="codir2me-flex-container">
				<div class="codir2me-main-column">
					
					<?php if ( ! empty( $error_message ) ) : ?>
					<div class="notice notice-error">
						<p><?php echo esc_html( $error_message ); ?></p>
					</div>
					<?php endif; ?>

					<!-- Seção de gerenciamento de licença -->
					<div class="codir2me-section codir2me-license-management">
						<h2><?php esc_html_e( 'Gerenciamento de Licença', 'codirun-codir2me-cdn' ); ?></h2>
						
						<?php if ( ! $is_license_active ) : ?>
						
						<div class="codir2me-license-status inactive">
							<span class="dashicons dashicons-lock"></span>
							<h3><?php esc_html_e( 'Licença Inativa', 'codirun-codir2me-cdn' ); ?></h3>
							<p><?php esc_html_e( 'Sua licença não está ativa. Por favor, ative sua licença para acessar recursos premium.', 'codirun-codir2me-cdn' ); ?></p>
						</div>
						
						<form id="codir2me-activate-license-form" class="codir2me-license-form">
							<?php wp_nonce_field( 'codir2me_license_nonce', 'codir2me_license_nonce' ); ?>
							<p>
								<label for="codir2me_license_key"><?php esc_html_e( 'Chave de Licença:', 'codirun-codir2me-cdn' ); ?></label>
								<input type="text" id="codir2me_license_key" name="codir2me_license_key" class="regular-text" placeholder="<?php esc_attr_e( 'Insira sua chave de licença', 'codirun-codir2me-cdn' ); ?>" value="<?php echo esc_attr( $license_key ); ?>">
							</p>
							
							<p>
								<label for="codir2me_license_email"><?php esc_html_e( 'Email da Licença:', 'codirun-codir2me-cdn' ); ?></label>
								<input type="email" id="codir2me_license_email" name="codir2me_license_email" class="regular-text" placeholder="<?php esc_attr_e( 'Insira o email utilizado na compra', 'codirun-codir2me-cdn' ); ?>" value="<?php echo esc_attr( $license_email ); ?>">
							</p>
							
							<p class="codir2me-license-note">
								<span class="dashicons dashicons-info"></span>
								<?php esc_html_e( 'Você receberá sua chave de licença por e-mail após a compra.', 'codirun-codir2me-cdn' ); ?>
							</p>
							
							<div class="codir2me-license-actions">
								<button type="submit" class="button button-primary" id="codir2me-activate-license-btn">
									<span class="dashicons dashicons-yes"></span>
									<?php esc_html_e( 'Ativar Licença', 'codirun-codir2me-cdn' ); ?>
								</button>
								<div class="codir2me-license-spinner" style="display: none;"></div>
							</div>
							
							<div id="codir2me-license-message" class="codir2me-license-message" style="display: none;"></div>
						</form>
						
						<?php else : ?>
						
						<div class="codir2me-license-status active">
							<span class="dashicons dashicons-yes-alt"></span>
							<h3><?php esc_html_e( 'Licença Ativa', 'codirun-codir2me-cdn' ); ?></h3>
							<p><?php esc_html_e( 'Parabéns! Sua licença está ativa e você tem acesso completo a todos os recursos premium.', 'codirun-codir2me-cdn' ); ?></p>
						</div>
						
						<div class="codir2me-license-details">
						<p><strong><?php esc_html_e( 'Chave de Licença:', 'codirun-codir2me-cdn' ); ?></strong> <?php echo esc_html( substr( $license_key, 0, 5 ) . '••••••••••••••••' . substr( $license_key, -5 ) ); ?></p>
						<p><strong><?php esc_html_e( 'Email da Licença:', 'codirun-codir2me-cdn' ); ?></strong> <?php echo esc_html( $license_email ); ?></p>
						<p><strong><?php esc_html_e( 'Status:', 'codirun-codir2me-cdn' ); ?></strong> 
								<span class="codir2me-status-active"><?php esc_html_e( 'Ativo', 'codirun-codir2me-cdn' ); ?></span>
								<?php if ( $is_trial ) : ?>
									<span class="codir2me-status-trial"><?php esc_html_e( '(Período de Teste)', 'codirun-codir2me-cdn' ); ?></span>
								<?php endif; ?>
								</p>
						<p><strong><?php esc_html_e( 'Domínio Ativo:', 'codirun-codir2me-cdn' ); ?></strong> <?php echo esc_html( $license_domain ? $license_domain : wp_parse_url( home_url(), PHP_URL_HOST ) ); ?></p>
						
						
							<?php
							// Atualizar em tempo real - forçar leitura direta do banco.
							if ( class_exists( 'codir2me_License_Manager' ) ) {
								global $codir2me_license_manager;
								if ( $codir2me_license_manager ) {
									// Recarregar os dados a cada exibição.
									wp_cache_delete( 'codir2me_license_expiry', 'options' );
									$expiry_date       = $codir2me_license_manager->codir2me_get_expiry_date_formatted();
									$days_until_expiry = $codir2me_license_manager->codir2me_get_days_until_expiry();
								}
							}

							// Definir a variável de cancelamento aqui, antes de usá-la nas condições.
							$license_cancellation_date = intval( get_option( 'codir2me_license_cancellation_date', 0 ) );
							$cancel_at_period_end      = get_option( 'codir2me_license_cancel_at_period_end', false );

							// Mostrar informações de expiração somente se estiverem disponíveis.
							if ( ! empty( $expiry_date ) ) :
								?>
							<p><strong><?php esc_html_e( 'Data de Expiração:', 'codirun-codir2me-cdn' ); ?></strong> 
								<?php echo esc_html( $expiry_date ); ?>
								<?php if ( $is_trial && 0 >= $license_cancellation_date && ! $cancel_at_period_end ) : ?>
									<span class="codir2me-trial-note"><?php esc_html_e( '(Após o período de teste, sua licença será estendida para 1 ano)', 'codirun-codir2me-cdn' ); ?></span>
								<?php elseif ( 0 < $license_cancellation_date || $cancel_at_period_end ) : ?>
									<span class="codir2me-trial-note codir2me-cancel-note"><?php esc_html_e( '(A licença não será renovada devido ao cancelamento programado)', 'codirun-codir2me-cdn' ); ?></span>
								<?php endif; ?>
							</p>
								<?php
						endif;

							// Sempre tentar mostrar os dias restantes.
							if ( null !== $days_until_expiry ) :
								?>
							<p><strong><?php esc_html_e( 'Tempo Restante:', 'codirun-codir2me-cdn' ); ?></strong> 
								<span class="codir2me-license-remaining <?php echo ( $is_trial && 0 >= $license_cancellation_date ) ? 'codir2me-trial-remaining' : ( ( 0 < $license_cancellation_date ) ? 'codir2me-cancellation-remaining' : '' ); ?>">
									<?php echo esc_html( $days_until_expiry ); ?> <?php esc_html_e( 'dias', 'codirun-codir2me-cdn' ); ?>
									<?php if ( $is_trial && 0 >= $license_cancellation_date && ! $cancel_at_period_end ) : ?>
										<?php esc_html_e( ' (período de teste)', 'codirun-codir2me-cdn' ); ?>
									<?php elseif ( 0 < $license_cancellation_date || $cancel_at_period_end ) : ?>
										<?php esc_html_e( ' (até o cancelamento)', 'codirun-codir2me-cdn' ); ?>
									<?php endif; ?>
								</span>
							</p>
								<?php
						endif;
							?>
							<?php
							// Exibir informações de cancelamento se a licença estiver programada para cancelamento.
							$license_cancellation_date      = intval( get_option( 'codir2me_license_cancellation_date', 0 ) );
							$license_cancellation_requested = intval( get_option( 'codir2me_license_cancel_requested_at', 0 ) );
							$cancellation_reason            = get_option( 'codir2me_license_cancellation_reason', '' );

							if ( 0 < $license_cancellation_date && $license_cancellation_date > time() ) :
								// Formatar a data de cancelamento de acordo com o idioma.
								if ( class_exists( 'codir2me_License_Manager' ) ) {
									global $codir2me_license_manager;
									if ( $codir2me_license_manager && method_exists( $codir2me_license_manager, 'codir2me_format_date_by_language' ) ) {
										$cancellation_formatted = $codir2me_license_manager->codir2me_format_date_by_language( $license_cancellation_date );
									} else {
										// Fallback para o formato tradicional.
										$cancellation_formatted = date_i18n( 'j \d\e F, Y', $license_cancellation_date );
									}
								} else {
									// Fallback para o formato tradicional.
									$cancellation_formatted = date_i18n( 'j \d\e F, Y', $license_cancellation_date );
								}
								?>
							<div class="codir2me-license-cancellation-notice">
								<p><strong><?php esc_html_e( 'Licença Programada para Cancelamento:', 'codirun-codir2me-cdn' ); ?></strong> 
									<span class="codir2me-status-cancelling"><?php echo esc_html( $cancellation_formatted ); ?></span>
								</p>
								<?php if ( ! empty( $cancellation_reason ) ) : ?>
									<p><strong><?php esc_html_e( 'Motivo:', 'codirun-codir2me-cdn' ); ?></strong> 
										<?php echo esc_html( $cancellation_reason ); ?>
									</p>
								<?php endif; ?>
								<?php if ( 0 < $license_cancellation_requested ) : ?>
									<p>
									<?php
										printf(
											/* translators: %s: data formatada */
											esc_html__( 'Cancelamento solicitado em %s.', 'codirun-codir2me-cdn' ),
											esc_html( date_i18n( 'j \d\e F, Y', $license_cancellation_requested ) )
										);
									?>
										</small></p>
								<?php endif; ?>
								<p class="codir2me-cancellation-note">
									<?php esc_html_e( 'Sua licença continuará funcionando normalmente até a data de cancelamento.', 'codirun-codir2me-cdn' ); ?>
								</p>
							</div>
						<?php endif; ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top: 15px;">
							<input type="hidden" name="action" value="codir2me_force_license_check">
							<?php wp_nonce_field( 'codir2me_force_license_check' ); ?>
							<button type="submit" class="button"><?php esc_html_e( 'Verificar licença agora', 'codirun-codir2me-cdn' ); ?></button>
						</form>

							<?php if ( isset( $_GET['check_forced'] ) && isset( $_GET['codir2me_check_nonce'] ) && wp_verify_nonce( sanitize_key( $_GET['codir2me_check_nonce'] ), 'codir2me_check_action' ) && '1' === $_GET['check_forced'] ) : ?>
						<div id="codir2me-license-verify-result" class="codir2me-custom-notice codir2me-notice-success">
							<p>
								<span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Licença verificada com sucesso! Os detalhes foram atualizados.', 'codirun-codir2me-cdn' ); ?>
							</p>
						</div>

						<?php endif; ?>
						</div>
						<div class="codir2me-license-actions-container">
							<!-- Formulário para trocar de domínio -->
							<div class="codir2me-domain-change-box">
								<h4><span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Trocar Domínio da Licença', 'codirun-codir2me-cdn' ); ?></h4>
								
								<?php if ( 0 < $cooldown_days ) : ?>
									<div class="codir2me-cooldown-notice">
										<span class="dashicons dashicons-clock"></span>
										<div class="codir2me-cooldown-text">
											<p>
											<?php
												printf(
													esc_html(
													/* translators: %d: número de dias de espera */
														_n( 'Você precisa esperar mais %d dia para poder trocar o domínio novamente.', 'Você precisa esperar mais %d dias para poder trocar o domínio novamente.', $cooldown_days, 'codirun-codir2me-cdn' )
													),
													esc_html( $cooldown_days )
												);
											?>
												</p>
											<?php
											// Calcular e mostrar a data exata de quando poderá trocar o domínio.
											$last_change = intval( get_option( 'codir2me_domain_last_change', 0 ) );
											if ( 0 < $last_change ) {
												// Adicionar 7 dias (em segundos) à data da última alteração.
												$next_available_time = $last_change + 604800;

												// Formatar a data no estilo "dia, mês, ano".
												$next_change_date = date_i18n( 'j \d\e F, Y', $next_available_time );

												echo '<p class="codir2me-next-change-date">' . esc_html__( 'Próxima troca disponível em:', 'codirun-codir2me-cdn' ) . ' <strong>' . esc_html( $next_change_date ) . '</strong></p>';
											}
											?>
										</div>
									</div>
								<?php else : ?>
									<form id="codir2me-change-domain-form" class="codir2me-domain-form">
										<?php wp_nonce_field( 'codir2me_license_nonce', 'codir2me_domain_nonce' ); ?>
										<div class="codir2me-form-row">
											<label for="codir2me_new_domain"><?php esc_html_e( 'Novo Domínio:', 'codirun-codir2me-cdn' ); ?></label>
											<input type="text" id="codir2me_new_domain" name="codir2me_new_domain" class="regular-text" placeholder="exemplo.com">
										</div>
										<p class="description codir2me-domain-description"><?php esc_html_e( 'Você só pode trocar de domínio uma vez por semana. Use com cuidado.', 'codirun-codir2me-cdn' ); ?></p>
										
										<div class="codir2me-button-row">
											<button type="submit" class="button button-secondary" id="codir2me-change-domain-btn">
												<span class="dashicons dashicons-update"></span>
												<?php esc_html_e( 'Trocar Domínio', 'codirun-codir2me-cdn' ); ?>
											</button>
											<div class="codir2me-domain-spinner" style="display: none;"></div>
										</div>
										
										<div id="codir2me-domain-message" class="codir2me-license-message" style="display: none;"></div>
									</form>
								<?php endif; ?>
							</div>
							
							<!-- Botão para desativar licença -->
							<div class="codir2me-deactivate-box">
								<h4><span class="dashicons dashicons-no-alt"></span> <?php esc_html_e( 'Desativar Licença', 'codirun-codir2me-cdn' ); ?></h4>
								<form id="codir2me-deactivate-license-form" class="codir2me-license-form">
									<?php wp_nonce_field( 'codir2me_license_nonce', 'codir2me_license_nonce' ); ?>
									<p class="description codir2me-deactivate-description"><?php esc_html_e( 'Desative a licença para poder usá-la em outro site.', 'codirun-codir2me-cdn' ); ?></p>
									<div class="codir2me-button-row">
										<button type="button" class="button button-secondary" id="codir2me-deactivate-license-btn">
											<span class="dashicons dashicons-no-alt"></span>
											<?php esc_html_e( 'Desativar Licença', 'codirun-codir2me-cdn' ); ?>
										</button>
										<div class="codir2me-license-spinner" style="display: none;"></div>
										<div id="codir2me-license-message" class="codir2me-license-message" style="display: none;"></div>
									</div>
								</form>
							</div>
						</div>
						
						<?php endif; ?>
					</div>

					<?php if ( ! $is_license_active ) : ?>
					<!-- Seção de informações sobre as funcionalidades premium -->
					<div class="codir2me-section codir2me-premium-features">
						<h2><?php esc_html_e( 'Recursos Premium do Cloudflare R2 Media & Static', 'codirun-codir2me-cdn' ); ?></h2>
						
						<div class="codir2me-premium-header">
							<img
								src="<?php echo esc_url( CODIR2ME_CDN_PLUGIN_URL . 'assets/images/premium.png' ); ?>"  
								alt="<?php echo esc_attr__( 'Premium', 'codirun-codir2me-cdn' ); ?>"  
								class="codir2me-premium-badge"
							>
							<div class="codir2me-premium-description">
								<h3><?php esc_html_e( 'Desbloqueie recursos avançados para seu CDN', 'codirun-codir2me-cdn' ); ?></h3>
								<p><?php esc_html_e( 'Eleve seu site a um novo patamar com recursos avançados de otimização, gerenciamento e desempenho!', 'codirun-codir2me-cdn' ); ?></p>
							</div>
						</div>
						
						<div class="codir2me-features-grid">
							<div class="codir2me-feature-card">
								<span class="dashicons dashicons-admin-appearance"></span>
								<h4><?php esc_html_e( 'Otimização de Imagens', 'codirun-codir2me-cdn' ); ?></h4>
								<p><?php esc_html_e( 'Comprima e converta automaticamente suas imagens para formatos modernos como WebP e AVIF, reduzindo drasticamente o tamanho dos arquivos sem perder qualidade.', 'codirun-codir2me-cdn' ); ?></p>
							</div>
							
							<div class="codir2me-feature-card">
								<span class="dashicons dashicons-update"></span>
								<h4><?php esc_html_e( 'Reprocessamento de Imagens', 'codirun-codir2me-cdn' ); ?></h4>
								<p><?php esc_html_e( 'Atualize facilmente as imagens existentes com novos formatos ou configurações de otimização sem precisar reenviar tudo manualmente.', 'codirun-codir2me-cdn' ); ?></p>
							</div>
							
							<div class="codir2me-feature-card">
								<span class="dashicons dashicons-search"></span>
								<h4><?php esc_html_e( 'Escaneamento R2', 'codirun-codir2me-cdn' ); ?></h4>
								<p><?php esc_html_e( 'Escaneie seu bucket R2 para sincronizar perfeitamente com o WordPress, garantindo que todos os arquivos estejam registrados corretamente.', 'codirun-codir2me-cdn' ); ?></p>
							</div>
							
							<div class="codir2me-feature-card">
								<span class="dashicons dashicons-trash"></span>
								<h4><?php esc_html_e( 'Gerenciamento de Arquivos', 'codirun-codir2me-cdn' ); ?></h4>
								<p><?php esc_html_e( 'Exclua facilmente arquivos antigos ou não utilizados diretamente do seu bucket R2, mantendo seu armazenamento limpo e eficiente.', 'codirun-codir2me-cdn' ); ?></p>
							</div>
						</div>
						
						<div class="codir2me-pricing-premium">
						<div class="codir2me-pricing-card">
							<!-- Faixa de destaque -->
							<div class="codir2me-pricing-badge"><?php echo esc_html( $price_info['trial_text'] ); ?></div>
							
							<!-- Cabeçalho com preço -->
							<div class="codir2me-pricing-header">
								<h3><?php esc_html_e( 'Plano Premium', 'codirun-codir2me-cdn' ); ?></h3>
								<div class="codir2me-price-container">
									<div class="codir2me-price"><?php echo esc_html( $price_info['currency_symbol'] . $price_info['price_amount'] ); ?></div>
									<div class="codir2me-price-period"><?php echo esc_html( $price_info['period_text'] ); ?></div>
								</div>
							</div>
							
							<!-- Conteúdo principal -->
							<div class="codir2me-pricing-body">
								<!-- Seção de teste gratuito -->
								<div class="codir2me-trial-section">
									<h4>
										<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">.
											<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path>
											<polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline>
											<line x1="12" y1="22.08" x2="12" y2="12"></line>
										</svg>
										<?php echo esc_html( $price_info['trial_text'] ); ?>
									</h4>
									<p><?php echo esc_html( $price_info['trial_description'] ); ?></p>
								</div>
								
								<!-- Lista de recursos -->
								<ul class="codir2me-pricing-features">
									<li><span style="font-weight: 700;"><?php esc_html_e( 'Otimização de Imagens', 'codirun-codir2me-cdn' ); ?></span> - <?php esc_html_e( 'Comprima e converta automaticamente para WebP/AVIF', 'codirun-codir2me-cdn' ); ?></li>
									<li><span style="font-weight: 700;"><?php esc_html_e( 'Reprocessamento de Imagens', 'codirun-codir2me-cdn' ); ?></span> - <?php esc_html_e( 'Atualize facilmente formatos existentes', 'codirun-codir2me-cdn' ); ?></li>
									<li><span style="font-weight: 700;"><?php esc_html_e( 'Escaneamento R2', 'codirun-codir2me-cdn' ); ?></span> - <?php esc_html_e( 'Sincronize seu bucket com WordPress', 'codirun-codir2me-cdn' ); ?></li>
									<li><span style="font-weight: 700;"><?php esc_html_e( 'Gerenciamento de Arquivos', 'codirun-codir2me-cdn' ); ?></span> - <?php esc_html_e( 'Exclua arquivos não utilizados do R2', 'codirun-codir2me-cdn' ); ?></li>
									<li><span style="font-weight: 700;"><?php esc_html_e( 'Atualizações gratuitas', 'codirun-codir2me-cdn' ); ?></span> - <?php esc_html_e( 'Acesso a novas funcionalidades', 'codirun-codir2me-cdn' ); ?></li>
									<li><span style="font-weight: 700;"><?php esc_html_e( 'Suporte técnico prioritário', 'codirun-codir2me-cdn' ); ?></span> - <?php esc_html_e( 'Por 1 ano completo', 'codirun-codir2me-cdn' ); ?></li>
									<li><span style="font-weight: 700;"><?php esc_html_e( 'Licença para 1 site', 'codirun-codir2me-cdn' ); ?></span> - <?php esc_html_e( 'Use em um domínio por vez (transferível)', 'codirun-codir2me-cdn' ); ?></li>
								</ul>
								
								<!-- Botão de Compra da Stripe -->
								<a href="<?php echo esc_url( $price_info['stripe_link'] ); ?>" class="codir2me-buy-button" target="_blank">
									<span class="dashicons dashicons-cart"></span>
									<?php echo esc_html( $price_info['button_text'] ); ?>
								</a>
								
								<!-- Informações de pagamento e garantia -->
								<div class="codir2me-payment-info">
									<span class="dashicons dashicons-lock"></span>
									<?php esc_html_e( 'Pagamento seguro processado via Stripe', 'codirun-codir2me-cdn' ); ?>
								</div>
								
								<div class="codir2me-money-back">
									<span class="dashicons dashicons-yes-alt"></span>
									<?php esc_html_e( 'Garantia de reembolso total nos primeiros 30 dias', 'codirun-codir2me-cdn' ); ?>
								</div>
							</div>
						</div>
					</div>
					</div>
					<?php endif; ?>
					
					<!-- Seção de perguntas frequentes -->
					<div class="codir2me-section codir2me-license-faq">
						<h2><?php esc_html_e( 'Perguntas Frequentes', 'codirun-codir2me-cdn' ); ?></h2>
						
						<div class="codir2me-accordion">
							<div class="codir2me-accordion-item">
								<div class="codir2me-accordion-header">
									<h4><?php esc_html_e( 'Como funciona a licença?', 'codirun-codir2me-cdn' ); ?></h4>
									<span class="dashicons dashicons-arrow-down-alt2"></span>
								</div>
								<div class="codir2me-accordion-content">
									<p><?php esc_html_e( 'Nossa licença é válida por 1 ano e permite que você use todos os recursos premium em um único site WordPress. Após a compra, você receberá uma chave de licença por e-mail que deve ser inserida nesta página para ativar os recursos.', 'codirun-codir2me-cdn' ); ?></p>
								</div>
							</div>
							
							<div class="codir2me-accordion-item">
								<div class="codir2me-accordion-header">
									<h4><?php esc_html_e( 'Posso usar a licença em mais de um site?', 'codirun-codir2me-cdn' ); ?></h4>
									<span class="dashicons dashicons-arrow-down-alt2"></span>
								</div>
								<div class="codir2me-accordion-content">
									<p><?php esc_html_e( 'Cada licença é válida para um site WordPress por vez. No entanto, você pode mudar o domínio da licença uma vez por semana, o que permite transferir sua licença de um site para outro quando necessário.', 'codirun-codir2me-cdn' ); ?></p>
								</div>
							</div>
							
							<div class="codir2me-accordion-item">
								<div class="codir2me-accordion-header">
									<h4><?php esc_html_e( 'Como renovar minha licença?', 'codirun-codir2me-cdn' ); ?></h4>
									<span class="dashicons dashicons-arrow-down-alt2"></span>
								</div>
								<div class="codir2me-accordion-content">
									<p><?php esc_html_e( 'Você receberá um e-mail antes da expiração da sua licença com instruções para renovação. A renovação mantém o mesmo preço e garante acesso contínuo a todos os recursos premium e atualizações futuras.', 'codirun-codir2me-cdn' ); ?></p>
								</div>
							</div>
							
							<div class="codir2me-accordion-item">
								<div class="codir2me-accordion-header">
									<h4><?php esc_html_e( 'O que acontece quando minha licença expirar?', 'codirun-codir2me-cdn' ); ?></h4>
									<span class="dashicons dashicons-arrow-down-alt2"></span>
								</div>
								<div class="codir2me-accordion-content">
									<p><?php esc_html_e( 'Quando sua licença expirar, você perderá acesso aos recursos premium. No entanto, todas as imagens e arquivos que já foram otimizados e enviados para o R2 continuarão funcionando normalmente. Você só perderá a capacidade de usar as funcionalidades premium para novos arquivos.', 'codirun-codir2me-cdn' ); ?></p>
								</div>
							</div>
							
							<div class="codir2me-accordion-item">
								<div class="codir2me-accordion-header">
									<h4><?php esc_html_e( 'Posso obter um reembolso?', 'codirun-codir2me-cdn' ); ?></h4>
									<span class="dashicons dashicons-arrow-down-alt2"></span>
								</div>
								<div class="codir2me-accordion-content">
									<p><?php esc_html_e( 'Oferecemos uma garantia de reembolso de 30 dias. Se você não estiver satisfeito com os recursos premium por qualquer motivo, entre em contato com nosso suporte dentro desse período para solicitar um reembolso completo.', 'codirun-codir2me-cdn' ); ?></p>
								</div>
							</div>
						</div>
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
				<h3><?php esc_html_e( 'Suporte Premium', 'codirun-codir2me-cdn' ); ?></h3>
				<div class="codir2me-widget-content">
					<p><?php esc_html_e( 'Com a licença premium, você tem acesso ao nosso suporte técnico dedicado.', 'codirun-codir2me-cdn' ); ?></p>
					<p><?php esc_html_e( 'Nossos especialistas estão prontos para ajudar com:', 'codirun-codir2me-cdn' ); ?></p>
					<ul class="codir2me-tips-list">
						<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Configuração do plugin', 'codirun-codir2me-cdn' ); ?></li>
						<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Otimização de imagens', 'codirun-codir2me-cdn' ); ?></li>
						<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Integração com Cloudflare R2', 'codirun-codir2me-cdn' ); ?></li>
						<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Solução de problemas técnicos', 'codirun-codir2me-cdn' ); ?></li>
					</ul>
						<?php $email = ( 'en_US' === get_locale() ) ? 'support@codirun.com' : 'suporte@codirun.com'; ?><a href="mailto:<?php echo esc_attr( $email ); ?>" class="button button-secondary" style="margin-top: 10px;">
						<span class="dashicons dashicons-email-alt"></span>
						<?php esc_html_e( 'Contatar Suporte', 'codirun-codir2me-cdn' ); ?>
					</a>
				</div>
			</div>
			
			<div class="codir2me-sidebar-widget">
				<h3><?php esc_html_e( 'Recursos Úteis', 'codirun-codir2me-cdn' ); ?></h3>
				<div class="codir2me-widget-content">
					<ul class="codir2me-tips-list">
						<li><span class="dashicons dashicons-media-document"></span> <a href="https://codirun.com/docs/r2-cdn/" target="_blank"><?php esc_html_e( 'Documentação Completa', 'codirun-codir2me-cdn' ); ?></a></li>
						<li><span class="dashicons dashicons-welcome-learn-more"></span> <a href="https://codirun.com/docs/r2-cdn/faq" target="_blank"><?php esc_html_e( 'Perguntas Frequentes', 'codirun-codir2me-cdn' ); ?></a></li>
						<li><span class="dashicons dashicons-update"></span> <a href="https://codirun.com/docs/r2-cdn/changelog" target="_blank"><?php esc_html_e( 'Notas de Atualização', 'codirun-codir2me-cdn' ); ?></a></li>
						<li><span class="dashicons dashicons-email"></span> <a href="mailto:<?php echo esc_attr( $email ); ?>"><?php esc_html_e( 'Suporte Técnico', 'codirun-codir2me-cdn' ); ?></a></li>

					</ul>
				</div>
			</div>
			
			<div class="codir2me-sidebar-widget">
				<h3><?php esc_html_e( 'Por que Escolher Nossa Solução?', 'codirun-codir2me-cdn' ); ?></h3>
				<div class="codir2me-widget-content">
					<p><?php esc_html_e( 'O Codirun R2 Media & Static CDN foi desenvolvido com foco em:', 'codirun-codir2me-cdn' ); ?></p>
					<ul class="codir2me-tips-list">
						<li><span class="dashicons dashicons-performance"></span> <strong><?php esc_html_e( 'Desempenho:', 'codirun-codir2me-cdn' ); ?></strong> <?php esc_html_e( 'Carregamento de páginas até 3x mais rápido', 'codirun-codir2me-cdn' ); ?></li>
						<li><span class="dashicons dashicons-shield"></span> <strong><?php esc_html_e( 'Segurança:', 'codirun-codir2me-cdn' ); ?></strong> <?php esc_html_e( 'Proteção adicional com a rede Cloudflare', 'codirun-codir2me-cdn' ); ?></li>
						<li><span class="dashicons dashicons-money"></span> <strong><?php esc_html_e( 'Economia:', 'codirun-codir2me-cdn' ); ?></strong> <?php esc_html_e( 'Redução de custos com servidor e largura de banda', 'codirun-codir2me-cdn' ); ?></li>
						<li><span class="dashicons dashicons-admin-tools"></span> <strong><?php esc_html_e( 'Facilidade:', 'codirun-codir2me-cdn' ); ?></strong> <?php esc_html_e( 'Interface amigável e configuração simples', 'codirun-codir2me-cdn' ); ?></li>
					</ul>
				</div>
			</div>
		</div>
		<?php
	}
}
