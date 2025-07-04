<?php
/**
 * Gerenciador de licenças para o plugin Codirun R2 Media & Static CDN.
 *
 * @package Codirun_R2_Media_Static_CDN
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once CODIR2ME_CDN_INCLUDES_DIR . 'class-codir2me-api-security.php';

/**
 * Classe responsável pelo gerenciamento de licenças do plugin.
 */
class CODIR2ME_License_Manager {
	/**
	 * URL da API para verificação de licenças.
	 *
	 * @var string
	 */
	private $api_url = 'https://r2cdn.codirun.com/';

	/**
	 * Chave da API para autenticação.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Tempo de cache em segundos.
	 *
	 * @var int
	 */
	private $cache_time = 86400;

	/**
	 * Tempo de cooldown para mudança de domínio em segundos.
	 *
	 * @var int
	 */
	private $domain_change_cooldown = 604800;

	/**
	 * Construtor da classe.
	 *
	 * Inicializa o gerenciador de licenças e configura os hooks necessários.
	 */
	public function __construct() {
		$this->codir2me_initialize_api_key();
		$license_expiry = get_option( 'codir2me_license_expiry', 0 );
		if ( $license_expiry && ! is_numeric( $license_expiry ) ) {
			if ( is_string( $license_expiry ) && strtotime( $license_expiry ) ) {
				update_option( 'codir2me_license_expiry', intval( strtotime( $license_expiry ) ) );
				codir2me_cdn_log( esc_html__( 'codir2me_License_Manager: Valor de expiração corrigido de string para timestamp', 'codirun-codir2me-cdn' ) );
			} else {
				update_option( 'codir2me_license_expiry', 0 );
				codir2me_cdn_log( esc_html__( 'codir2me_License_Manager: Valor de expiração inválido encontrado e redefinido para 0', 'codirun-codir2me-cdn' ) );
			}
		}

		add_action( 'admin_init', array( $this, 'codir2me_schedule_license_check' ) );
		add_action( 'codir2me_daily_license_check', array( $this, 'codir2me_check_license' ) );
		add_filter( 'codir2me_can_access_premium_feature', array( $this, 'codir2me_can_access_premium_feature' ), 10, 2 );

		add_action( 'wp_ajax_codir2me_activate_license', array( $this, 'codir2me_ajax_activate_license' ) );
		add_action( 'wp_ajax_codir2me_deactivate_license', array( $this, 'codir2me_ajax_deactivate_license' ) );
		add_action( 'wp_ajax_codir2me_change_domain', array( $this, 'codir2me_ajax_change_domain' ) );

		add_action( 'admin_post_codir2me_force_license_check', array( $this, 'codir2me_force_license_check' ) );

		$this->codir2me_check_license_expiration();
	}

	/**
	 * Inicializa a chave da API.
	 *
	 * @return void
	 */
	private function codir2me_initialize_api_key() {
		$this->api_key = CODIR2ME_API_Security::codir2me_get_api_key();
		if ( empty( $this->api_key ) ) {
			codir2me_cdn_log( esc_html__( 'codir2me_License_Manager: Falha ao obter chave da API', 'codirun-codir2me-cdn' ) );
		}
	}

	/**
	 * Força uma verificação de licença.
	 *
	 * @return void
	 */
	public function codir2me_force_license_check() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Acesso negado', 'codirun-codir2me-cdn' ) );
		}

		check_admin_referer( 'codir2me_force_license_check' );

		codir2me_cdn_log( esc_html__( 'Iniciando verificação forçada de licença', 'codirun-codir2me-cdn' ) );

		delete_option( 'codir2me_license_last_check' );

		wp_cache_delete( 'codir2me_license_status', 'options' );
		wp_cache_delete( 'codir2me_license_expiry', 'options' );
		wp_cache_delete( 'codir2me_license_domain', 'options' );
		wp_cache_delete( 'codir2me_license_key', 'options' );
		wp_cache_delete( 'codir2me_license_email', 'options' );
		wp_cache_delete( 'codir2me_domain_last_change', 'options' );
		wp_cache_delete( 'codir2me_license_is_trial', 'options' );

		$license_key   = get_option( 'codir2me_license_key', '' );
		$license_email = get_option( 'codir2me_license_email', '' );

		// Limpar transient do cache da licença.
		delete_transient( 'codir2me_license_data_' . $license_key );

		$site_url       = home_url();
		$current_domain = wp_parse_url( $site_url, PHP_URL_HOST );

		$args = array(
			'timeout' => 30,
			'body'    => wp_json_encode(
				array(
					'email'  => $license_email,
					'key'    => $license_key,
					'domain' => $current_domain,
				)
			),
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $this->api_key,
			),
		);

		codir2me_cdn_log( esc_html__( 'Verificação forçada: Enviando requisição para API de verificação de licença', 'codirun-codir2me-cdn' ) );

		// Fazer requisição para verificar licença.
		$response = wp_remote_post( $this->api_url . 'verify', $args );

		if ( is_wp_error( $response ) ) {
			codir2me_cdn_log( esc_html__( 'Verificação forçada: Erro na requisição', 'codirun-codir2me-cdn' ) . ' - ' . $response->get_error_message() );
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'                 => 'codirun-codir2me-cdn-license',
						'check_forced'         => '0',
						'codir2me_check_nonce' => wp_create_nonce( 'codir2me_check_action' ),
						't'                    => time(),
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$result        = json_decode( $response_body, true );

		codir2me_cdn_log( esc_html__( 'Verificação forçada: Resposta API - Código:', 'codirun-codir2me-cdn' ) . ' ' . $response_code . ', ' . esc_html__( 'Corpo:', 'codirun-codir2me-cdn' ) . ' ' . $response_body );

		// Se a licença for válida, atualizar todos os dados.
		if ( 200 === $response_code && isset( $result['valid'] ) && $result['valid'] ) {
			// Atualizar status da licença.
			update_option( 'codir2me_license_status', 'active' );
			update_option( 'codir2me_license_domain', $current_domain );
			update_option( 'codir2me_license_last_check', time() );

			// MODIFICAÇÃO: Verificar se lastDomainChange é 0 ou null.
			$last_domain_change = get_option( 'codir2me_domain_last_change', 0 );
			if ( 0 === $last_domain_change || null === $last_domain_change ) {
				// Se for 0 ou null, definir para um timestamp no passado para permitir a troca imediata.
				$past_timestamp = time() - 604800; // 7 dias atrás.
				update_option( 'codir2me_domain_last_change', $past_timestamp );
				codir2me_cdn_log( esc_html__( 'Verificação forçada: Corrigindo lastDomainChange de 0 para', 'codirun-codir2me-cdn' ) . ' ' . $past_timestamp );
			}

			// Atualizar dados com base na resposta da API.
			$updated = $this->codir2me_update_local_data_from_api( $result );

			// Registrar sucesso.
			codir2me_cdn_log( esc_html__( 'Verificação forçada: Licença validada com sucesso', 'codirun-codir2me-cdn' ) . ( $updated ? ' e dados atualizados' : '' ) );
			wp_safe_redirect( admin_url( 'admin.php?page=codirun-codir2me-cdn-license&check_forced=1&t=' . time() ) );
			exit;
		} else {
			// Licença inválida ou expirada.
			if ( 403 === $response_code && isset( $result['expired'] ) && $result['expired'] ) {
				update_option( 'codir2me_license_status', 'expired' );

				// Se houver data de expiração, salvar.
				if ( isset( $result['expiryTimestamp'] ) ) {
					$expiry_timestamp = $result['expiryTimestamp'];
					if ( strlen( (string) $expiry_timestamp ) > 10 ) {
						$expiry_timestamp = floor( $expiry_timestamp / 1000 );
					}
					update_option( 'codir2me_license_expiry', intval( $expiry_timestamp ) );
				}
			} else {
				update_option( 'codir2me_license_status', 'inactive' );
			}

			update_option( 'codir2me_license_last_check', time() );
			codir2me_cdn_log( esc_html__( 'Verificação forçada: Licença inválida ou expirada. Código:', 'codirun-codir2me-cdn' ) . ' ' . $response_code );

			// Também chamar verificação normal para garantir consistência.
			$this->codir2me_check_license();

			wp_safe_redirect(
				add_query_arg(
					array(
						'page'                 => 'codirun-codir2me-cdn-license',
						'check_forced'         => '1',
						'codir2me_check_nonce' => wp_create_nonce( 'codir2me_check_action' ),
						't'                    => time(),
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}
	}

	/**
	 * Verifica se a licença expirou e atualiza o status.
	 *
	 * @return void
	 */
	private function codir2me_check_license_expiration() {
		$license_status       = get_option( 'codir2me_license_status', 'inactive' );
		$license_expiry       = intval( get_option( 'codir2me_license_expiry', 0 ) );
		$is_trial             = get_option( 'codir2me_license_is_trial', false );
		$cancel_at_period_end = get_option( 'codir2me_license_cancel_at_period_end', false );

		// Se a licença estiver ativa e tivermos uma data de expiração.
		if ( 'active' === $license_status && $license_expiry > 0 ) {
			// Verificar se a licença expirou.
			if ( time() > $license_expiry ) {
				// Atualizar status para expirado.
				update_option( 'codir2me_license_status', 'expired' );

				// Adicionar notificação no admin.
				add_action(
					'admin_notices',
					function () use ( $is_trial, $cancel_at_period_end ) {
						if ( $is_trial ) {
							?>
						<div class="notice notice-error">
							<p><strong><?php esc_html_e( 'Período de Teste Encerrado:', 'codirun-codir2me-cdn' ); ?></strong> <?php esc_html_e( 'Seu período de teste do Codirun R2 Media & Static CDN terminou.', 'codirun-codir2me-cdn' ); ?> 
							<?php if ( $cancel_at_period_end ) : ?>
								<?php esc_html_e( 'Sua assinatura foi cancelada e não será renovada.', 'codirun-codir2me-cdn' ); ?>
							<?php else : ?>
								<?php esc_html_e( 'A cobrança será realizada automaticamente para ativar sua licença completa.', 'codirun-codir2me-cdn' ); ?>
							<?php endif; ?>
							</p>
						</div>
							<?php
						} else {
							?>
						<div class="notice notice-error">
							<p><strong><?php esc_html_e( 'Licença Expirada:', 'codirun-codir2me-cdn' ); ?></strong> <?php esc_html_e( 'Sua licença do Codirun R2 Media & Static CDN expirou.', 'codirun-codir2me-cdn' ); ?> 
							<?php if ( $cancel_at_period_end ) : ?>
								<?php esc_html_e( 'Conforme solicitado, sua assinatura não será renovada.', 'codirun-codir2me-cdn' ); ?>
							<?php else : ?>
								<?php esc_html_e( 'A renovação automática está sendo processada. Se precisar de ajuda, entre em contato com nosso suporte em', 'codirun-codir2me-cdn' ); ?> <a href="mailto:suporte@codirun.com">suporte@codirun.com</a>.
							<?php endif; ?>
							</p>
						</div>
							<?php
						}
					}
				);
			}

			// Verificar se está próximo de expirar (7 dias).
			$days_until_expiry = ( $license_expiry - time() ) / 86400;
		}
	}

	/**
	 * Verifica se o usuário pode acessar uma funcionalidade premium.
	 *
	 * @param bool   $can_access Valor padrão.
	 * @param string $feature Nome da funcionalidade.
	 * @return bool Se o usuário pode acessar.
	 */
	public function codir2me_can_access_premium_feature( $can_access, $feature ) {
		// Verificar se a licença está ativa.
		$license_status = get_option( 'codir2me_license_status', 'inactive' );

		// Se a licença não estiver ativa, usar o valor padrão.
		if ( 'active' !== $license_status ) {
			return $can_access;
		}

		// Verificar funcionalidades específicas se necessário.
		$premium_features = array( 'delete', 'optimization', 'scanner', 'reprocess' );
		if ( in_array( $feature, $premium_features, true ) ) {
			return true;
		}

		return $can_access;
	}

	/**
	 * Agenda verificação diária de licença.
	 *
	 * @return void
	 */
	public function codir2me_schedule_license_check() {
		if ( ! wp_next_scheduled( 'codir2me_daily_license_check' ) ) {
			wp_schedule_event( time(), 'daily', 'codir2me_daily_license_check' );
		}
	}

	/**
	 * Verifica se a licença é válida.
	 *
	 * @return bool True se a licença for válida, false caso contrário.
	 */
	public function codir2me_check_license() {
		$license_key    = get_option( 'codir2me_license_key', '' );
		$license_email  = get_option( 'codir2me_license_email', '' );
		$license_status = get_option( 'codir2me_license_status', 'inactive' );
		$license_domain = get_option( 'codir2me_license_domain', '' );

		// Se não houver licença, não verificar.
		if ( empty( $license_key ) || empty( $license_email ) ) {
			codir2me_cdn_log( esc_html__( 'Verificação de licença: Licença ou email não fornecidos', 'codirun-codir2me-cdn' ) );
			return false;
		}

		// Obter domínio atual.
		$site_url       = home_url();
		$current_domain = wp_parse_url( $site_url, PHP_URL_HOST );

		// Verificar se o domínio atual é o mesmo que o domínio registrado.
		if ( ! empty( $license_domain ) && $license_domain !== $current_domain && 'active' === $license_status ) {
			codir2me_cdn_log( esc_html__( 'Verificação de licença: Domínio alterado de', 'codirun-codir2me-cdn' ) . ' ' . $license_domain . ' ' . esc_html__( 'para', 'codirun-codir2me-cdn' ) . ' ' . $current_domain );
			update_option( 'codir2me_license_status', 'inactive' );
			$license_status = 'inactive';

			// Adicionar aviso no admin.
			add_action(
				'admin_notices',
				function () use ( $license_domain, $current_domain ) {
					?>
				<div class="notice notice-error">
					<p><strong><?php esc_html_e( 'Licença Desativada:', 'codirun-codir2me-cdn' ); ?></strong> 
					<?php
						/* translators: %1$s is the current domain, %2$s is the registered domain */
						printf( esc_html__( 'A licença foi desativada porque o domínio atual (%1$s) é diferente do domínio registrado (%2$s). Por favor, ative a licença novamente ou troque o domínio registrado.', 'codirun-codir2me-cdn' ), esc_html( $current_domain ), esc_html( $license_domain ) );
					?>
								</p>
				</div>
					<?php
				}
			);

			return false;
		}

		// Verificar se lastDomainChange é 0 ou null e corrigir se necessário.
		$last_domain_change = get_option( 'codir2me_domain_last_change', 0 );
		if ( 0 === $last_domain_change || null === $last_domain_change ) {
			// Se for 0 ou null, definir para um timestamp no passado para permitir a troca imediata.
			$past_timestamp = time() - 604800; // 7 dias atrás.
			update_option( 'codir2me_domain_last_change', $past_timestamp );
			codir2me_cdn_log( esc_html__( 'Verificação de licença: Corrigindo lastDomainChange de 0 para', 'codirun-codir2me-cdn' ) . ' ' . $past_timestamp );
		}

		// Preparar requisição para API - Forçar verificação sempre quando chamado manualmente.
		$args = array(
			'timeout' => 30,
			'body'    => wp_json_encode(
				array(
					'email'  => $license_email,
					'key'    => $license_key,
					'domain' => $current_domain,
				)
			),
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $this->api_key,
			),
		);

		codir2me_cdn_log( esc_html__( 'Verificação de licença: Iniciando verificação para', 'codirun-codir2me-cdn' ) . ' ' . $license_email . ', ' . esc_html__( 'domínio', 'codirun-codir2me-cdn' ) . ' ' . $current_domain );

		// Fazer requisição para verificar licença.
		$response = wp_remote_post( $this->api_url . 'verify', $args );

		if ( is_wp_error( $response ) ) {
			codir2me_cdn_log( esc_html__( 'Verificação de licença: Erro de conexão', 'codirun-codir2me-cdn' ) . ' - ' . $response->get_error_message() );
			// Erro de conexão - manter status atual para não interromper o site.
			update_option( 'codir2me_license_last_check', time() );
			return ( 'active' === $license_status );
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$result        = json_decode( $response_body, true );

		codir2me_cdn_log( esc_html__( 'Verificação de licença: Resposta recebida - Código:', 'codirun-codir2me-cdn' ) . ' ' . $response_code . ', ' . esc_html__( 'Corpo:', 'codirun-codir2me-cdn' ) . ' ' . $response_body );

		// Verificar expiração.
		if ( 403 === $response_code && isset( $result['expired'] ) && $result['expired'] ) {
			codir2me_cdn_log( esc_html__( 'Verificação de licença: Licença expirada', 'codirun-codir2me-cdn' ) );
			update_option( 'codir2me_license_status', 'expired' );
			update_option( 'codir2me_license_last_check', time() );

			// Se houver data de expiração, salvar.
			if ( isset( $result['expiryDate'] ) ) {
				$expiry_timestamp = strtotime( $result['expiryDate'] );
				if ( false !== $expiry_timestamp ) {
					update_option( 'codir2me_license_expiry', intval( $expiry_timestamp ) );
					codir2me_cdn_log( esc_html__( 'Verificação de licença: Data de expiração atualizada para', 'codirun-codir2me-cdn' ) . ' ' . gmdate( 'Y-m-d H:i:s', $expiry_timestamp ) );
				}
			}

			return false;
		}

		// Se a licença for válida.
		if ( 200 === $response_code ) {
			codir2me_cdn_log( esc_html__( 'Verificação de licença: Licença válida', 'codirun-codir2me-cdn' ) );
			update_option( 'codir2me_license_status', 'active' );
			update_option( 'codir2me_license_domain', $current_domain );
			update_option( 'codir2me_license_last_check', time() );

			// Atualizar os dados locais com a resposta da API.
			$this->codir2me_update_local_data_from_api( $result );

			return true;
		} else {
			// Licença inválida.
			codir2me_cdn_log( esc_html__( 'Verificação de licença: Licença inválida - Código:', 'codirun-codir2me-cdn' ) . ' ' . $response_code );
			update_option( 'codir2me_license_status', 'inactive' );
			update_option( 'codir2me_license_last_check', time() );
			return false;
		}
	}

	/**
	 * Ativa uma licença via AJAX.
	 *
	 * @return void
	 */
	public function codir2me_ajax_activate_license() {
		// Verificar nonce obrigatoriamente.
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'codir2me_license_nonce' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Erro de segurança. Por favor, recarregue a página e tente novamente.', 'codirun-codir2me-cdn' ) ) );
		}

		// Verificar permissões obrigatoriamente.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Você não tem permissão para realizar esta ação.', 'codirun-codir2me-cdn' ) ) );
		}

		// Obter chave de licença e email.
		$license_key   = isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '';
		$license_email = isset( $_POST['license_email'] ) ? sanitize_email( wp_unslash( $_POST['license_email'] ) ) : '';

		if ( empty( $license_key ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Por favor, forneça uma chave de licença válida.', 'codirun-codir2me-cdn' ) ) );
		}

		if ( empty( $license_email ) || ! is_email( $license_email ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Por favor, forneça um email válido.', 'codirun-codir2me-cdn' ) ) );
		}

		// Obter domínio atual.
		$site_url = home_url();
		$domain   = wp_parse_url( $site_url, PHP_URL_HOST );

		// Preparar requisição para API.
		$args = array(
			'timeout' => 15,
			'body'    => wp_json_encode(
				array(
					'email'  => $license_email,
					'key'    => $license_key,
					'domain' => $domain,
				)
			),
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $this->api_key,
			),
		);

		// Fazer requisição para ativar licença.
		$response = wp_remote_post( $this->api_url . 'activate', $args );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Erro de conexão:', 'codirun-codir2me-cdn' ) . ' ' . $response->get_error_message() ) );
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$result        = json_decode( $response_body, true );

		// Verificar expiração.
		if ( 403 === $response_code && isset( $result['expired'] ) && $result['expired'] ) {
			$expiry_date = isset( $result['expiryDate'] ) ? gmdate( 'd/m/Y', strtotime( $result['expiryDate'] ) ) : esc_html__( 'desconhecida', 'codirun-codir2me-cdn' );
			/* translators: %s is the expiry date */
			wp_send_json_error( array( 'message' => sprintf( esc_html__( 'Licença expirada. Data de expiração: %s', 'codirun-codir2me-cdn' ), $expiry_date ) ) );
		}

		if ( 200 === $response_code ) {
			// Salvar dados da licença.
			update_option( 'codir2me_license_key', $license_key );
			update_option( 'codir2me_license_email', $license_email );
			update_option( 'codir2me_license_status', 'active' );
			update_option( 'codir2me_license_domain', $domain );
			update_option( 'codir2me_license_last_check', time() );

			$past_timestamp = time() - 604800;
			update_option( 'codir2me_domain_last_change', $past_timestamp );
			codir2me_cdn_log( esc_html__( 'Ativação de licença: Inicializando lastDomainChange com timestamp no passado:', 'codirun-codir2me-cdn' ) . ' ' . $past_timestamp );

			// Verificar se a licença está em período de teste.
			$is_trial = isset( $result['onTrial'] ) ? (bool) $result['onTrial'] : false;
			update_option( 'codir2me_license_is_trial', $is_trial );
			codir2me_cdn_log( esc_html__( 'Ativação de licença: Status de trial', 'codirun-codir2me-cdn' ) . ' - ' . ( $is_trial ? esc_html__( 'Sim', 'codirun-codir2me-cdn' ) : esc_html__( 'Não', 'codirun-codir2me-cdn' ) ) );

			// Atualizar data de expiração, se disponível.
			if ( isset( $result['expires'] ) ) {
				// Assumir que o valor já está em segundos.
				$expiry_timestamp = intval( $result['expires'] );
				codir2me_cdn_log( esc_html__( 'Processado timestamp:', 'codirun-codir2me-cdn' ) . ' ' . $result['expires'] . ' -> ' . $expiry_timestamp );

				// Verificar se a conversão foi bem-sucedida.
				if ( $expiry_timestamp > time() ) {
					update_option( 'codir2me_license_expiry', $expiry_timestamp );
					codir2me_cdn_log( esc_html__( 'Salvando data de expiração:', 'codirun-codir2me-cdn' ) . ' ' . gmdate( 'Y-m-d H:i:s', $expiry_timestamp ) . ' (' . esc_html__( 'timestamp:', 'codirun-codir2me-cdn' ) . ' ' . $expiry_timestamp . ')' );
				} else {
					// Se for um período de teste, definir para 7 dias em vez de 1 ano.
					$is_trial = isset( $result['onTrial'] ) ? (bool) $result['onTrial'] : false;
					if ( $is_trial ) {
						$default_expiry = time() + ( 7 * 24 * 60 * 60 ); // 7 dias para período de teste.
					} else {
						$default_expiry = time() + ( 365 * 24 * 60 * 60 ); // 1 ano para licença normal.
					}
					update_option( 'codir2me_license_expiry', $default_expiry );
					codir2me_cdn_log( esc_html__( 'Usando data de expiração', 'codirun-codir2me-cdn' ) . ' ' . ( $is_trial ? esc_html__( 'de teste (7 dias)', 'codirun-codir2me-cdn' ) : esc_html__( 'padrão (1 ano)', 'codirun-codir2me-cdn' ) ) . ': ' . gmdate( 'Y-m-d H:i:s', $default_expiry ) );
				}
			}

			wp_send_json_success( array( 'message' => esc_html__( 'Licença ativada com sucesso!', 'codirun-codir2me-cdn' ) ) );
		} else {
			$error_message = isset( $result['error'] ) ? $result['error'] : esc_html__( 'Erro ao ativar licença. Código:', 'codirun-codir2me-cdn' ) . ' ' . $response_code;
			wp_send_json_error( array( 'message' => $error_message ) );
		}
	}

	/**
	 * Desativa uma licença via AJAX.
	 *
	 * @return void
	 */
	public function codir2me_ajax_deactivate_license() {
		// CORREÇÃO: Verificar nonce obrigatoriamente ANTES de qualquer processamento.
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'codir2me_license_nonce' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Erro de segurança. Por favor, recarregue a página e tente novamente.', 'codirun-codir2me-cdn' ) ) );
		}

		// Verificar permissões obrigatoriamente.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Você não tem permissão para realizar esta ação.', 'codirun-codir2me-cdn' ) ) );
		}

		// Obter dados atuais da licença para log.
		$license_key    = get_option( 'codir2me_license_key', '' );
		$license_email  = get_option( 'codir2me_license_email', '' );
		$license_domain = get_option( 'codir2me_license_domain', '' );

		// Log para depuração.
		codir2me_cdn_log( esc_html__( 'Desativando licença: email=', 'codirun-codir2me-cdn' ) . $license_email . ', ' . esc_html__( 'domínio=', 'codirun-codir2me-cdn' ) . $license_domain );

		// Limpar dados da licença localmente.
		update_option( 'codir2me_license_status', 'inactive' );
		delete_option( 'codir2me_license_domain' );
		update_option( 'codir2me_license_expiry', 0 );
		update_option( 'codir2me_domain_last_change', 0 );
		delete_option( 'codir2me_license_is_trial' );

		wp_send_json_success( array( 'message' => esc_html__( 'Licença desativada com sucesso!', 'codirun-codir2me-cdn' ) ) );
	}

	/**
	 * Muda o domínio da licença e desativa a licença no site atual.
	 *
	 * @return void
	 */
	public function codir2me_ajax_change_domain() {
		// Verificar nonce obrigatoriamente.
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'codir2me_license_nonce' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Erro de segurança. Por favor, recarregue a página e tente novamente.', 'codirun-codir2me-cdn' ) ) );
		}

		// Verificar permissões obrigatoriamente.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Você não tem permissão para realizar esta ação.', 'codirun-codir2me-cdn' ) ) );
		}

		// Verificar status da licença.
		$license_status = get_option( 'codir2me_license_status', 'inactive' );
		if ( 'active' !== $license_status ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Sua licença não está ativa. Por favor, ative sua licença primeiro.', 'codirun-codir2me-cdn' ) ) );
		}

		// Verificar se a licença expirou.
		$license_expiry = intval( get_option( 'codir2me_license_expiry', 0 ) );
		if ( $license_expiry > 0 && time() > $license_expiry ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Sua licença expirou. Por favor, renove sua licença para continuar.', 'codirun-codir2me-cdn' ) ) );
		}

		// Verificar cooldown.
		$last_change = intval( get_option( 'codir2me_domain_last_change', 0 ) );
		$time_passed = time() - $last_change;

		if ( $time_passed < $this->domain_change_cooldown ) {
			$days_remaining = ceil( ( $this->domain_change_cooldown - $time_passed ) / 86400 );
			/* translators: %d is the number of days remaining before domain change is allowed */
			wp_send_json_error( array( 'message' => sprintf( esc_html__( 'Você precisa esperar mais %d dias para mudar o domínio novamente.', 'codirun-codir2me-cdn' ), $days_remaining ) ) );
		}

		// Obter dados da licença.
		$license_key    = get_option( 'codir2me_license_key', '' );
		$license_email  = get_option( 'codir2me_license_email', '' );
		$current_domain = get_option( 'codir2me_license_domain', '' );

		if ( empty( $license_key ) || empty( $license_email ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Nenhuma licença ativa para mudar de domínio.', 'codirun-codir2me-cdn' ) ) );
		}

		// Obter novo domínio.
		$new_domain = isset( $_POST['new_domain'] ) ? sanitize_text_field( wp_unslash( $_POST['new_domain'] ) ) : '';

		if ( empty( $new_domain ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Por favor, forneça um novo domínio válido.', 'codirun-codir2me-cdn' ) ) );
		}

		// Se o domínio atual não estiver definido, use o domínio do site.
		if ( empty( $current_domain ) ) {
			$current_domain = wp_parse_url( home_url(), PHP_URL_HOST );
		}

		// Preparar requisição para API.
		$args = array(
			'timeout' => 15,
			'body'    => wp_json_encode(
				array(
					'email'         => $license_email,
					'key'           => $license_key,
					'currentDomain' => $current_domain,
					'newDomain'     => $new_domain,
				)
			),
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $this->api_key,
			),
		);

		// Fazer requisição para mudar o domínio.
		$response = wp_remote_post( $this->api_url . 'change-domain', $args );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Erro de conexão:', 'codirun-codir2me-cdn' ) . ' ' . $response->get_error_message() ) );
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$result        = json_decode( $response_body, true );

		// Verificar erro de período de espera (429 Too Many Requests).
		if ( 429 === $response_code ) {
			$error_message = isset( $result['error'] ) ? $result['error'] : esc_html__( 'Você precisa esperar mais tempo para trocar de domínio.', 'codirun-codir2me-cdn' );
			wp_send_json_error( array( 'message' => $error_message ) );
		}

		// Verificar se a licença expirou.
		if ( 403 === $response_code && isset( $result['expired'] ) && $result['expired'] ) {
			$expiry_date = isset( $result['expiryDate'] ) ? gmdate( 'd/m/Y', strtotime( $result['expiryDate'] ) ) : esc_html__( 'desconhecida', 'codirun-codir2me-cdn' );
			/* translators: %s is the expiry date */
			wp_send_json_error( array( 'message' => sprintf( esc_html__( 'Licença expirada. Data de expiração: %s', 'codirun-codir2me-cdn' ), $expiry_date ) ) );
		}

		// Verificar outros erros.
		if ( 200 !== $response_code ) {
			$error_message = isset( $result['error'] ) ? $result['error'] : esc_html__( 'Erro ao trocar domínio. Tente novamente mais tarde.', 'codirun-codir2me-cdn' );
			wp_send_json_error( array( 'message' => $error_message ) );
		}

		// Guardar a data de expiração atual para preservá-la.
		$current_expiry = intval( get_option( 'codir2me_license_expiry', 0 ) );

		// Desativar a licença no site atual antes de atualizar o domínio.
		update_option( 'codir2me_license_status', 'inactive' );

		// Atualizar dados locais.
		update_option( 'codir2me_license_domain', $new_domain );
		update_option( 'codir2me_domain_last_change', time() );

		// Preservar a data de expiração.
		if ( $current_expiry > 0 ) {
			update_option( 'codir2me_license_expiry', $current_expiry );
		} elseif ( isset( $result['expires'] ) ) {
			// Se não tiver data de expiração salva, usar a da resposta.
			// Assumir que já está em segundos.
			$expiry_timestamp = intval( $result['expires'] );
			if ( $expiry_timestamp > time() ) {
				update_option( 'codir2me_license_expiry', $expiry_timestamp );
			} else {
				// Definir uma data padrão (1 ano a partir de hoje).
				$default_expiry = time() + ( 365 * 24 * 60 * 60 );
				update_option( 'codir2me_license_expiry', $default_expiry );
			}
		}

		// Mensagem de sucesso.
		/* translators: %s is the new domain */
		$success_message = sprintf( esc_html__( 'Domínio alterado com sucesso para %s. Licença desativada neste site.', 'codirun-codir2me-cdn' ), $new_domain );

		wp_send_json_success( array( 'message' => $success_message ) );
	}

	/**
	 * Atualiza os dados locais com base na resposta da API.
	 * Versão modificada que trata corretamente o cancelamento do cancelamento.
	 *
	 * @param array $response_data Dados recebidos da API.
	 * @return bool Sucesso da atualização.
	 */
	private function codir2me_update_local_data_from_api( $response_data ) {
		if ( empty( $response_data ) ) {
			return false;
		}

		$updated = false;

		// Atualizar status do período de teste.
		if ( isset( $response_data['onTrial'] ) ) {
			$is_trial = (bool) $response_data['onTrial'];
			update_option( 'codir2me_license_is_trial', $is_trial );
			codir2me_cdn_log( 'Atualizando status de trial para: ' . ( $is_trial ? 'SIM' : 'NÃO' ) );
			$updated = true;
		}

		// Atualizar data de expiração.
		if ( isset( $response_data['expires'] ) ) {
			$expiry_timestamp = intval( $response_data['expires'] );
			if ( $expiry_timestamp > time() ) {
				update_option( 'codir2me_license_expiry', $expiry_timestamp );
				codir2me_cdn_log( 'Atualizando data de expiração para: ' . gmdate( 'Y-m-d H:i:s', $expiry_timestamp ) );
				$updated = true;
			}
		}

		// Verificar explicitamente se cancellationDate está definido como NULL.
		// para tratar o caso de cancelamento do cancelamento.
		if ( array_key_exists( 'cancellationDate', $response_data ) ) {
			// Se for explicitamente null, limpar a data de cancelamento.
			if ( null === $response_data['cancellationDate'] ) {
				codir2me_cdn_log( 'Cancelamento do cancelamento detectado. Limpando data de cancelamento.' );
				update_option( 'codir2me_license_cancellation_date', 0 );
				update_option( 'codir2me_license_cancel_requested_at', 0 );
				update_option( 'codir2me_license_cancellation_reason', '' );
				update_option( 'codir2me_license_cancel_at_period_end', false );
				$updated = true;
			} elseif ( $response_data['cancellationDate'] > 0 ) {
				$cancellation_timestamp = intval( $response_data['cancellationDate'] );
				update_option( 'codir2me_license_cancellation_date', $cancellation_timestamp );
				codir2me_cdn_log( 'Atualizando data de cancelamento para: ' . gmdate( 'Y-m-d H:i:s', $cancellation_timestamp ) );
				$updated = true;
			}
		}

		// Atualizar data da solicitação de cancelamento.
		if ( isset( $response_data['cancelRequestedAt'] ) ) {
			if ( null === $response_data['cancelRequestedAt'] ) {
				update_option( 'codir2me_license_cancel_requested_at', 0 );
				$updated = true;
			} elseif ( $response_data['cancelRequestedAt'] > 0 ) {
				$cancel_requested_timestamp = intval( $response_data['cancelRequestedAt'] );
				update_option( 'codir2me_license_cancel_requested_at', $cancel_requested_timestamp );
				codir2me_cdn_log( 'Atualizando data da solicitação de cancelamento para: ' . gmdate( 'Y-m-d H:i:s', $cancel_requested_timestamp ) );
				$updated = true;
			}
		}

		// Atualizar motivo do cancelamento.
		if ( isset( $response_data['cancellationReason'] ) ) {
			if ( null === $response_data['cancellationReason'] ) {
				update_option( 'codir2me_license_cancellation_reason', '' );
				$updated = true;
			} elseif ( ! empty( $response_data['cancellationReason'] ) ) {
				update_option( 'codir2me_license_cancellation_reason', $response_data['cancellationReason'] );
				codir2me_cdn_log( 'Atualizando motivo de cancelamento para: ' . $response_data['cancellationReason'] );
				$updated = true;
			}
		}

		// Atualizar status do cancelamento no final do período.
		if ( isset( $response_data['cancelAtPeriodEnd'] ) ) {
			$cancel_at_period_end = (bool) $response_data['cancelAtPeriodEnd'];
			update_option( 'codir2me_license_cancel_at_period_end', $cancel_at_period_end );
			$updated = true;
		}

		if ( $updated ) {
			// Registrar timestamp da última atualização.
			update_option( 'codir2me_license_last_update', time() );

			// Armazenar dados completos em cache por 1 hora.
			$license_key = get_option( 'codir2me_license_key', '' );
			if ( ! empty( $license_key ) ) {
				set_transient( 'codir2me_license_data_' . $license_key, wp_json_encode( $response_data ), 3600 );
			}
		}

		return $updated;
	}

	/**
	 * Método para forçar atualização de dados da licença.
	 *
	 * @return bool Sucesso da operação.
	 */
	public function codir2me_force_update_license_data() {
		$license_key    = get_option( 'codir2me_license_key', '' );
		$license_email  = get_option( 'codir2me_license_email', '' );
		$license_domain = get_option( 'codir2me_license_domain', '' );

		if ( empty( $license_key ) || empty( $license_email ) || empty( $license_domain ) ) {
			codir2me_cdn_log( 'Dados insuficientes para forçar atualização da licença' );
			return false;
		}

		// Preparar requisição para API.
		$args = array(
			'timeout' => 15,
			'body'    => wp_json_encode(
				array(
					'email'  => $license_email,
					'key'    => $license_key,
					'domain' => $license_domain,
				)
			),
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $this->api_key,
			),
		);

		// Fazer requisição para verificar licença.
		$response = wp_remote_post( $this->api_url . 'verify', $args );

		if ( is_wp_error( $response ) ) {
			codir2me_cdn_log( 'Erro ao conectar com API para atualização: ' . $response->get_error_message() );
			return false;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$result        = json_decode( $response_body, true );

		codir2me_cdn_log( 'Resposta da API para atualização forçada: ' . $response_body );

		// Se a licença for válida, atualizar os dados locais.
		if ( 200 === $response_code && isset( $result['valid'] ) && $result['valid'] ) {
			$updated = $this->codir2me_update_local_data_from_api( $result );

			if ( $updated ) {
				codir2me_cdn_log( 'Dados da licença atualizados com sucesso' );

				// Também atualizar o status da licença se necessário.
				if ( 'active' !== get_option( 'codir2me_license_status' ) ) {
					update_option( 'codir2me_license_status', 'active' );
				}
			}

			return $updated;
		}

		codir2me_cdn_log( 'Não foi possível atualizar dados da licença. Código: ' . $response_code );
		return false;
	}

	/**
	 * Retorna o tempo restante em dias até poder mudar o domínio novamente.
	 *
	 * @return int Número de dias restantes.
	 */
	public function codir2me_get_domain_change_cooldown_days() {
		$last_change = intval( get_option( 'codir2me_domain_last_change', 0 ) );
		$time_passed = time() - $last_change;

		if ( $time_passed >= $this->domain_change_cooldown ) {
			return 0;
		}

		return ceil( ( $this->domain_change_cooldown - $time_passed ) / 86400 );
	}

	/**
	 * Método para obter os dias até a expiração da licença.
	 * Com prioridade para a data de cancelamento (cancellationDate).
	 *
	 * @return int Dias até a expiração ou cancelamento (0 se já expirou ou não há data).
	 */
	public function codir2me_get_days_until_expiry() {
		$license_status = get_option( 'codir2me_license_status', 'inactive' );

		// Verificar primeiro se existe uma data de cancelamento (cancel_at da Stripe).
		$cancellation_date = intval( get_option( 'codir2me_license_cancellation_date', 0, false ) );

		// Se não houver data de cancelamento, usar a data de expiração padrão.
		$license_expiry = intval( get_option( 'codir2me_license_expiry', 0, false ) );

		// Determinar qual data usar (cancelamento tem prioridade).
		$date_to_use = ( $cancellation_date > 0 ) ? $cancellation_date : $license_expiry;
		$date_type   = ( $cancellation_date > 0 ) ? 'cancelamento' : 'expiração';

		// Log para depuração.
		codir2me_cdn_log( esc_html__( 'Status da licença:', 'codirun-codir2me-cdn' ) . ' ' . $license_status );

		if ( $cancellation_date > 0 ) {
			codir2me_cdn_log( esc_html__( 'Data de cancelamento:', 'codirun-codir2me-cdn' ) . ' ' . gmdate( 'Y-m-d H:i:s', $cancellation_date ) . ' (' . esc_html__( 'timestamp:', 'codirun-codir2me-cdn' ) . ' ' . $cancellation_date . ')' );
		}

		codir2me_cdn_log( esc_html__( 'Data de expiração:', 'codirun-codir2me-cdn' ) . ' ' . gmdate( 'Y-m-d H:i:s', $license_expiry ) . ' (' . esc_html__( 'timestamp:', 'codirun-codir2me-cdn' ) . ' ' . $license_expiry . ')' );
		codir2me_cdn_log( esc_html__( 'Usando data de:', 'codirun-codir2me-cdn' ) . ' ' . $date_type );

		// Se a licença não estiver ativa ou não houver data válida.
		if ( 'active' !== $license_status || $date_to_use <= 0 ) {
			// Se a licença estiver ativa mas não tiver data, definir uma data padrão.
			if ( 'active' === $license_status && $date_to_use <= 0 ) {
				$date_to_use = time() + ( 365 * 24 * 60 * 60 ); // 1 ano a partir de agora.
				update_option( 'codir2me_license_expiry', $date_to_use );
				codir2me_cdn_log( esc_html__( 'Definida data de expiração padrão:', 'codirun-codir2me-cdn' ) . ' ' . gmdate( 'Y-m-d H:i:s', $date_to_use ) );
			} else {
				return 0;
			}
		}

		// NOVO: Arredondar para o início do dia (meia-noite).
		$date = new DateTime( '@' . $date_to_use );
		$date->setTime( 0, 0, 0 ); // Define como meia-noite.
		$date_to_use = $date->getTimestamp();
		codir2me_cdn_log( esc_html__( 'Timestamp arredondado para meia-noite:', 'codirun-codir2me-cdn' ) . ' ' . $date_to_use . ' (' . gmdate( 'Y-m-d H:i:s', $date_to_use ) . ')' );

		// Também arredondar o timestamp atual para meia-noite para uma comparação justa.
		$now   = time();
		$today = new DateTime( '@' . $now );
		$today->setTime( 0, 0, 0 );
		$now = $today->getTimestamp();

		// Cálculo dos dias restantes.
		$time_diff = $date_to_use - $now;
		$days      = ceil( $time_diff / 86400 ); // Arredondar para cima para não mostrar 0 no último dia.

		// Log do cálculo para depuração.
		codir2me_cdn_log( esc_html__( 'Cálculo de dias:', 'codirun-codir2me-cdn' ) . ' (' . $date_to_use . ' - ' . $now . ') / 86400 = ' . $days );

		return max( 0, $days );
	}

	/**
	 * Formata uma data com base no idioma da licença.
	 *
	 * @param int $timestamp Timestamp da data.
	 * @return string Data formatada.
	 */
	public function codir2me_format_date_by_language( $timestamp ) {
		// Verificar o idioma atual da licença.
		$language = get_option( 'codir2me_license_language', '' );

		// Se não tiver o idioma salvo nas opções, usar o idioma da licença.
		if ( empty( $language ) ) {
			// Tentar obter das opções da licença.
			$language = get_option( 'codir2me_license_language', false );

			// Se ainda não tiver, usar o idioma do WordPress como fallback.
			if ( ! $language ) {
				$language = ( 0 === strpos( get_locale(), 'en_' ) ) ? 'en_US' : 'pt_BR';
			}
		}

		// Formato específico para cada idioma.
		if ( 'en_US' === $language ) {
			// Formato manual em inglês: "Month Day, Year".
			$months_en = array(
				1  => 'January',
				2  => 'February',
				3  => 'March',
				4  => 'April',
				5  => 'May',
				6  => 'June',
				7  => 'July',
				8  => 'August',
				9  => 'September',
				10 => 'October',
				11 => 'November',
				12 => 'December',
			);

			$month_num = intval( gmdate( 'n', $timestamp ) );
			$day       = intval( gmdate( 'j', $timestamp ) );
			$year      = gmdate( 'Y', $timestamp );

			return $months_en[ $month_num ] . ' ' . $day . ', ' . $year;
		} else {
			// Formato em português: "dia de Mês, Ano".
			return date_i18n( 'j \d\e F, Y', $timestamp );
		}
	}

	/**
	 * Método corrigido para retornar a data de expiração ou cancelamento da licença formatada.
	 *
	 * @return string Data formatada ou string vazia se não houver data.
	 */
	public function codir2me_get_expiry_date_formatted() {
		$license_status = get_option( 'codir2me_license_status', 'inactive' );

		// Verificar primeiro se existe uma data de cancelamento (cancel_at da Stripe).
		$cancellation_date = intval( get_option( 'codir2me_license_cancellation_date', 0, false ) );

		// Se não houver data de cancelamento, usar a data de expiração padrão.
		$license_expiry = intval( get_option( 'codir2me_license_expiry', 0, false ) );

		// Determinar qual data usar (cancelamento tem prioridade).
		$date_to_use = ( $cancellation_date > 0 ) ? $cancellation_date : $license_expiry;
		$date_type   = ( $cancellation_date > 0 ) ? 'cancelamento' : 'expiração';

		// Use um texto adequado para o log.
		codir2me_cdn_log( esc_html__( 'Formatando data de', 'codirun-codir2me-cdn' ) . ' ' . $date_type . ': ' . gmdate( 'Y-m-d H:i:s', $date_to_use ) . ' (' . esc_html__( 'timestamp:', 'codirun-codir2me-cdn' ) . ' ' . $date_to_use . ')' );

		if ( $date_to_use <= 0 ) {
			return '';
		}

		// Isso remove as horas, minutos e segundos do timestamp.
		$date = new DateTime( '@' . $date_to_use );
		$date->setTime( 0, 0, 0 ); // Define como meia-noite.
		$date_to_use = $date->getTimestamp();
		codir2me_cdn_log( esc_html__( 'Timestamp arredondado para meia-noite:', 'codirun-codir2me-cdn' ) . ' ' . $date_to_use . ' (' . gmdate( 'Y-m-d H:i:s', $date_to_use ) . ')' );

		// Usar o método público para formatar a data.
		return $this->codir2me_format_date_by_language( $date_to_use );
	}
}
