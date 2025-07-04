<?php
/**
 * Classe que gerencia a aba "Excluir Arquivos" da UI de administração
 *
 * @package Codirun_R2_Media_Static_CDN
 * @subpackage Admin
 * @since 1.0.0
 */

// Evitar acesso direto ao arquivo.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classe CODIR2ME_Admin_UI_Delete
 *
 * Gerencia todas as funcionalidades relacionadas à exclusão de arquivos
 * do bucket R2, incluindo exclusão manual, automática e em lotes.
 *
 * @since 1.0.0
 */
class CODIR2ME_Admin_UI_Delete {

	/**
	 * Instância da classe de administração principal
	 *
	 * @since 1.0.0
	 * @var object
	 */
	private $admin;

	/**
	 * Construtor da classe
	 *
	 * @since 1.0.0
	 * @param object $admin Instância da classe de administração principal.
	 */
	public function __construct( $admin ) {
		$this->admin = $admin;
		$this->codir2me_init_hooks();
	}

	/**
	 * Inicializa os hooks do WordPress
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function codir2me_init_hooks() {
		// Hooks para processar ações de exclusão em lotes.
		add_action( 'admin_init', array( $this, 'codir2me_handle_form_processing' ), 5 );
		add_action( 'admin_post_codir2me_start_delete_batch', array( $this, 'codir2me_start_delete_batch_action' ) );
		add_action( 'admin_post_codir2me_process_delete_batch', array( $this, 'codir2me_process_delete_batch_action' ) );

		// Hooks para processamento em segundo plano.
		add_action( 'admin_post_codir2me_pause_background_deletion', array( $this, 'codir2me_pause_background_deletion' ) );
		add_action( 'admin_post_codir2me_resume_background_deletion', array( $this, 'codir2me_resume_background_deletion' ) );
		add_action( 'codir2me_background_deletion_event', array( $this, 'codir2me_process_background_deletion_batch' ) );

		// Hooks para exclusão rápida.
		add_action( 'admin_post_codir2me_quick_delete_start', array( $this, 'codir2me_start_quick_delete' ) );

		// Hooks AJAX.
		add_action( 'wp_ajax_codir2me_process_quick_delete_batch', array( $this, 'codir2me_ajax_process_quick_delete_batch' ) );
		add_action( 'wp_ajax_codir2me_check_background_deletion_status', array( $this, 'codir2me_ajax_check_background_deletion_status' ) );
		add_action( 'wp_ajax_codir2me_check_bucket_status', array( $this, 'codir2me_ajax_check_bucket_status' ) );

		// Inicializar buffer de saída.
		add_action( 'init', array( $this, 'codir2me_init_output_buffer' ) );
	}

	/**
	 * Inicializa o buffer de saída se necessário
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function codir2me_init_output_buffer() {
		if ( ! headers_sent() ) {
			ob_start();
		}
	}

	/**
	 * Cria e configura o cliente S3 para Cloudflare R2
	 *
	 * @since 1.0.0
	 * @throws Exception Se as configurações estiverem incompletas ou SDK não disponível.
	 * @return AsyncAws\S3\S3Client Cliente S3 configurado.
	 */
	private function codir2me_create_s3_client() {
		// Verificar se o AsyncAws SDK está disponível.
		if ( ! file_exists( CODIR2ME_CDN_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
			throw new Exception( esc_html__( 'AsyncAws SDK não encontrado. Por favor, instale o SDK primeiro.', 'codirun-codir2me-cdn' ) );
		}

		require_once CODIR2ME_CDN_PLUGIN_DIR . 'vendor/autoload.php';

		if ( ! class_exists( 'AsyncAws\S3\S3Client' ) ) {
			throw new Exception( esc_html__( 'Classe AsyncAws\S3\S3Client não encontrada.', 'codirun-codir2me-cdn' ) );
		}

		// Obter configurações de conexão.
		$access_key = get_option( 'codir2me_access_key' );
		$secret_key = get_option( 'codir2me_secret_key' );
		$endpoint   = get_option( 'codir2me_endpoint' );

		// Verificar se todas as configurações estão presentes.
		if ( ! $access_key || ! $secret_key || ! $endpoint ) {
			throw new Exception( esc_html__( 'Configurações do R2 incompletas. Verifique as credenciais.', 'codirun-codir2me-cdn' ) );
		}

		// Configuração para Cloudflare R2.
		$config = array(
			'region'            => 'auto',
			'endpoint'          => $endpoint,
			'accessKeyId'       => $access_key,
			'accessKeySecret'   => $secret_key,
			'pathStyleEndpoint' => true,
		);

		return new AsyncAws\S3\S3Client( $config );
	}

	/**
	 * AJAX: Verifica o status do bucket antes de iniciar exclusão
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function codir2me_ajax_check_bucket_status() {
		// Limpar qualquer output anterior.
		if ( ob_get_length() ) {
			ob_clean();
		}

		try {
			// Verificar nonce e permissões.
			if ( ! check_ajax_referer( 'codir2me_quick_delete_batch_nonce', 'nonce', false ) ) {
				wp_send_json_error(
					array(
						'message' => esc_html__( 'Falha na verificação de segurança.', 'codirun-codir2me-cdn' ),
					)
				);
				return;
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error(
					array(
						'message' => esc_html__( 'Sem permissão.', 'codirun-codir2me-cdn' ),
					)
				);
				return;
			}

			// Verificar configurações.
			$bucket = get_option( 'codir2me_bucket' );
			if ( ! $bucket ) {
				wp_send_json_error(
					array(
						'message' => esc_html__( 'Configurações R2 incompletas.', 'codirun-codir2me-cdn' ),
					)
				);
				return;
			}

			// Criar cliente e testar conexão.
			$s3_client = $this->codir2me_create_s3_client();

			// Teste simples para verificar se há objetos.
			$list_result = $s3_client->listObjectsV2(
				array(
					'Bucket'  => $bucket,
					'MaxKeys' => 1,
				)
			);

			// Verificar se há objetos no bucket.
			$contents    = $list_result->getContents();
			$has_objects = false;

			if ( $contents && is_iterable( $contents ) ) {
				foreach ( $contents as $object ) {
					$has_objects = true;
					break;
				}
			}

			wp_send_json_success(
				array(
					'has_objects'  => $has_objects,
					'bucket_empty' => ! $has_objects,
					'message'      => $has_objects
						? esc_html__( 'Bucket contém arquivos para excluir.', 'codirun-codir2me-cdn' )
						: esc_html__( 'Bucket está vazio - nada para excluir.', 'codirun-codir2me-cdn' ),
				)
			);

		} catch ( Exception $e ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: mensagem de erro */
						esc_html__( 'Erro ao verificar bucket: %s', 'codirun-codir2me-cdn' ),
						esc_html( $e->getMessage() )
					),
				)
			);
		}
	}

	/**
	 * Processa formulários antes de qualquer output
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function codir2me_handle_form_processing() {
		// Só processar no admin.
		if ( ! is_admin() ) {
			return;
		}

		// Só processar se há dados POST.
		if ( empty( $_POST ) ) {
			return;
		}

		// Verificar nonce ANTES de processar qualquer POST data.
		$valid_nonce = false;

		// Verificar diferentes tipos de nonce que podem existir.
		if ( isset( $_POST['_wpnonce'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) );

			// Verificar contra os nonces válidos para diferentes ações.
			if ( wp_verify_nonce( $nonce, 'codir2me_delete_action' ) ||
			wp_verify_nonce( $nonce, 'codir2me_auto_delete_settings' ) ||
			wp_verify_nonce( $nonce, 'codir2me_maintenance_action' ) ) {
				$valid_nonce = true;
			}
		}

		// Só processar se nonce for válido.
		if ( ! $valid_nonce ) {
			return;
		}

		// Verificar permissões.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Processar ações de formulário ANTES de qualquer output.
		$this->codir2me_process_form_actions();
	}

	/**
	 * AJAX: Processa um lote de exclusão rápida
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function codir2me_ajax_process_quick_delete_batch() {
		// Limpar qualquer output anterior.
		if ( ob_get_length() ) {
			ob_clean();
		}

		try {
			// Verificar nonce e permissões.
			if ( ! check_ajax_referer( 'codir2me_quick_delete_batch_nonce', 'nonce', false ) ) {
				wp_send_json_error(
					array(
						'message' => esc_html__( 'Falha na verificação de segurança.', 'codirun-codir2me-cdn' ),
					)
				);
				return;
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error(
					array(
						'message' => esc_html__( 'Sem permissão.', 'codirun-codir2me-cdn' ),
					)
				);
				return;
			}

			// Obter configurações.
			$bucket = get_option( 'codir2me_bucket' );
			if ( ! $bucket ) {
				wp_send_json_error(
					array(
						'message' => esc_html__( 'Configurações R2 incompletas.', 'codirun-codir2me-cdn' ),
					)
				);
				return;
			}

			// Criar cliente S3.
			$s3_client = $this->codir2me_create_s3_client();

			// Obter status atual da exclusão.
			$status = get_option(
				'codir2me_quick_delete_status',
				array(
					'in_progress'        => true,
					'deleted_count'      => 0,
					'continuation_token' => null,
					'start_time'         => time(),
				)
			);

			// Configurar parâmetros para listar objetos.
			$batch_size = 500;

			$list_params = array(
				'Bucket'  => $bucket,
				'MaxKeys' => $batch_size,
			);

			if ( ! empty( $status['continuation_token'] ) ) {
				$list_params['ContinuationToken'] = $status['continuation_token'];
			}

			// Listar objetos no bucket.
			$list_result = $s3_client->listObjectsV2( $list_params );
			$contents    = $list_result->getContents();

			// Preparar objetos para exclusão.
			$objects_to_delete = array();
			$objects_found     = false;

			if ( $contents && is_iterable( $contents ) ) {
				foreach ( $contents as $object ) {
					$key = $object->getKey();
					if ( ! empty( $key ) ) {
						$objects_to_delete[] = array( 'Key' => $key );
						$objects_found       = true;

						// Limitar ao tamanho do lote configurado.
						if ( count( $objects_to_delete ) >= $batch_size ) {
							break;
						}
					}
				}
			}

			// Excluir objetos se encontrados.
			if ( $objects_found && ! empty( $objects_to_delete ) ) {
				$s3_client->deleteObjects(
					array(
						'Bucket' => $bucket,
						'Delete' => array(
							'Objects' => $objects_to_delete,
							'Quiet'   => true,
						),
					)
				);

				$status['deleted_count'] += count( $objects_to_delete );
			}

			// Verificar se há mais objetos para processar.
			if ( $list_result->getIsTruncated() && $objects_found ) {
				// Ainda há mais objetos.
				$status['continuation_token'] = $list_result->getNextContinuationToken();
				$status['in_progress']        = true;

				update_option( 'codir2me_quick_delete_status', $status );

				// Calcular progresso estimado.
				$progress = min( 90, ( $status['deleted_count'] / max( $batch_size, $status['deleted_count'] ) ) * 100 );

				wp_send_json_success(
					array(
						'complete'      => false,
						'deleted_count' => $status['deleted_count'],
						'status'        => sprintf(
							/* translators: %1$d: número de arquivos excluídos */
							esc_html__( 'Processando... %1$d arquivos excluídos', 'codirun-codir2me-cdn' ),
							$status['deleted_count']
						),
						'progress'      => $progress,
					)
				);

			} else {
				// Processo completo.
				$status['in_progress'] = false;
				update_option( 'codir2me_quick_delete_status', $status );

				// Limpar listas de arquivos do WordPress.
				$this->codir2me_clear_wordpress_file_lists();

				wp_send_json_success(
					array(
						'complete'      => true,
						'deleted_count' => $status['deleted_count'],
						'status'        => $status['deleted_count'] > 0
							? sprintf(
								/* translators: %d: número de arquivos excluídos */
								esc_html__( 'Exclusão completa! %d arquivos excluídos.', 'codirun-codir2me-cdn' ),
								$status['deleted_count']
							)
							: esc_html__( 'Bucket estava vazio.', 'codirun-codir2me-cdn' ),
						'progress'      => 100,
					)
				);
			}
		} catch ( Exception $e ) {
			// Marcar processo como não em andamento.
			$status                = get_option( 'codir2me_quick_delete_status', array() );
			$status['in_progress'] = false;
			$status['error']       = $e->getMessage();
			update_option( 'codir2me_quick_delete_status', $status );

			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: mensagem de erro */
						esc_html__( 'Erro na exclusão: %s', 'codirun-codir2me-cdn' ),
						esc_html( $e->getMessage() )
					),
				)
			);
		}
	}

	/**
	 * Limpa as listas de arquivos armazenadas no WordPress
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function codir2me_clear_wordpress_file_lists() {
		update_option( 'codir2me_uploaded_files', array() );
		update_option( 'codir2me_uploaded_images', array() );
		update_option( 'codir2me_uploaded_thumbnails_by_size', array() );
		update_option( 'codir2me_original_images_count', 0 );
		update_option( 'codir2me_thumbnail_images_count', 0 );
		update_option( 'codir2me_missing_images_count', 0 );
		update_option( 'codir2me_all_images_sent', false );
	}

	/**
	 * Inicia o processo de exclusão rápida
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function codir2me_start_quick_delete() {
		// Verificar nonce e permissões.
		if ( ! check_admin_referer( 'codir2me_quick_delete', 'codir2me_quick_delete_nonce' ) ) {
			wp_die( esc_html__( 'Falha na verificação de segurança.', 'codirun-codir2me-cdn' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Você não tem permissão para executar esta ação.', 'codirun-codir2me-cdn' ) );
		}

		// Verificar configurações.
		try {
			$this->codir2me_create_s3_client();
		} catch ( Exception $e ) {
			wp_safe_redirect( admin_url( 'admin.php?page=codirun-codir2me-cdn-delete&error=missing_config' ) );
			exit;
		}

		// Inicializar status de exclusão.
		update_option(
			'codir2me_quick_delete_status',
			array(
				'in_progress'        => true,
				'deleted_count'      => 0,
				'continuation_token' => null,
				'start_time'         => time(),
				'error'              => null,
			)
		);

		// Redirecionar para a página de progresso com nonce correto.
		$nonce = wp_create_nonce( 'codir2me_quick_delete_nonce' );

		$url = add_query_arg(
			array(
				'quick_delete_started' => '1',
				'_wpnonce'             => $nonce,
			),
			admin_url( 'admin.php?page=codirun-codir2me-cdn-delete' )
		);

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Renderiza a interface da aba de exclusão
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function codir2me_render() {
		// Mostrar notificações.
		$this->codir2me_display_notifications();

		// Obter estatísticas atuais.
		$stats = $this->codir2me_get_current_stats();

		// Obter configurações atuais.
		$auto_delete_enabled             = get_option( 'codir2me_auto_delete_enabled', false );
		$auto_delete_thumbnail_option    = get_option( 'codir2me_auto_delete_thumbnail_option', 'all' );
		$auto_delete_selected_thumbnails = get_option( 'codir2me_auto_delete_selected_thumbnails', array() );

		// Verificar se existe processo de exclusão em andamento.
		$delete_in_progress = get_option( 'codir2me_delete_in_progress', false );
		$delete_status      = get_option( 'codir2me_delete_status', array() );

		?>
		<div class="codir2me-tab-content">
			<div class="codir2me-flex-container">
				<div class="codir2me-main-column">
					
					<?php $this->codir2me_render_status_cards( $stats, $auto_delete_enabled ); ?>
					<?php $this->codir2me_render_auto_delete_settings( $auto_delete_enabled, $auto_delete_thumbnail_option, $auto_delete_selected_thumbnails ); ?>
					<?php $this->codir2me_render_manual_deletion_section( $stats, $delete_in_progress, $delete_status ); ?>
					<?php $this->codir2me_render_quick_delete_section(); ?>
					
				</div>
				<?php $this->codir2me_render_sidebar(); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Processa as ações dos formulários COM VERIFICAÇÃO DE NONCE
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function codir2me_process_form_actions() {
		// Verificar se existe dados POST antes de processar.
		if ( empty( $_POST ) ) {
			return;
		}

		// Verificar e processar recálculo de estatísticas.
		if ( isset( $_POST['codir2me_recalculate_stats'] ) ) {
			// Verificar nonce específico para esta ação.
			if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'codir2me_maintenance_action' ) ) {
				wp_die( esc_html__( 'Falha na verificação de segurança.', 'codirun-codir2me-cdn' ) );
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Você não tem permissão para executar esta ação.', 'codirun-codir2me-cdn' ) );
			}

			$this->codir2me_recalculate_thumbnail_stats();
			return;
		}

		// Verificar e processar formulário de exclusão.
		if ( isset( $_POST['codir2me_delete_submit'] ) ) {
			// Verificar nonce específico para esta ação.
			if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'codir2me_delete_action' ) ) {
				wp_die( esc_html__( 'Falha na verificação de segurança.', 'codirun-codir2me-cdn' ) );
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Você não tem permissão para executar esta ação.', 'codirun-codir2me-cdn' ) );
			}

			$this->codir2me_process_delete_form();
			return;
		}

		// Verificar e processar configurações de exclusão automática.
		if ( isset( $_POST['codir2me_auto_delete_settings_submit'] ) ) {
			// Verificar nonce específico para esta ação.
			if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'codir2me_auto_delete_settings' ) ) {
				wp_die( esc_html__( 'Falha na verificação de segurança.', 'codirun-codir2me-cdn' ) );
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Você não tem permissão para executar esta ação.', 'codirun-codir2me-cdn' ) );
			}

			$this->codir2me_process_auto_delete_settings();
			return;
		}
	}

	/**
	 * Exibe notificações do sistema
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function codir2me_display_notifications() {
		// Verificar nonce para as mensagens que vieram via GET.
		if ( isset( $_GET['_wpnonce'] ) && ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'codir2me_quick_delete_batch_nonce' ) ) {
			// Nonce inválido, não mostrar notificações relacionadas.
			return;
		}

		// Mensagem de exclusão rápida concluída.
		if ( isset( $_GET['quick_delete_complete'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['quick_delete_complete'] ) ) ) {
			$count = isset( $_GET['count'] ) ? intval( wp_unslash( $_GET['count'] ) ) : 0;
			?>
			<div class="notice notice-success is-dismissible">
				<p>
					<strong><?php esc_html_e( 'Exclusão rápida concluída!', 'codirun-codir2me-cdn' ); ?></strong>
					<?php
					/* translators: %d: número de arquivos excluídos */
					printf( esc_html__( '%d arquivos foram excluídos permanentemente do seu bucket R2.', 'codirun-codir2me-cdn' ), esc_html( $count ) );
					?>
				</p>
			</div>
			<?php
		}

		// Mensagem de erro na exclusão rápida.
		if ( isset( $_GET['quick_delete_error'] ) ) {
			$error_message = sanitize_text_field( wp_unslash( $_GET['quick_delete_error'] ) );
			?>
			<div class="notice notice-error is-dismissible">
				<p>
					<strong><?php esc_html_e( 'Erro na exclusão rápida:', 'codirun-codir2me-cdn' ); ?></strong>
					<?php echo esc_html( $error_message ); ?>
				</p>
			</div>
			<?php
		}

		// Mensagem de erro por falta de configuração.
		if ( isset( $_GET['error'] ) && 'missing_config' === sanitize_text_field( wp_unslash( $_GET['error'] ) ) ) {
			?>
			<div class="notice notice-error is-dismissible">
				<p>
					<?php esc_html_e( 'As configurações de conexão com o R2 estão incompletas. Por favor, configure suas credenciais na aba Configurações Gerais.', 'codirun-codir2me-cdn' ); ?>
				</p>
			</div>
			<?php
		}
	}

	/**
	 * Renderiza os cartões de status
	 *
	 * @since 1.0.0
	 * @param array $stats Estatísticas atuais.
	 * @param bool  $auto_delete_enabled Se a exclusão automática está ativada.
	 * @return void
	 */
	private function codir2me_render_status_cards( $stats, $auto_delete_enabled ) {
		?>
		<div class="codir2me-section">
			<h2><?php esc_html_e( 'Status Atual do R2', 'codirun-codir2me-cdn' ); ?></h2>
			<div class="codir2me-cdn-status-cards">
				<div class="codir2me-status-card">
					<div class="codir2me-status-icon">
						<span class="dashicons dashicons-media-code"></span>
					</div>
					<div class="codir2me-status-details">
						<h3><?php esc_html_e( 'Arquivos Estáticos Enviados', 'codirun-codir2me-cdn' ); ?></h3>
						<p class="codir2me-status-count"><?php echo esc_html( $stats['total_static_files'] ); ?></p>
					</div>
				</div>
				
				<div class="codir2me-status-card">
					<div class="codir2me-status-icon">
						<span class="dashicons dashicons-format-image"></span>
					</div>
					<div class="codir2me-status-details">
						<h3><?php esc_html_e( 'Imagens Enviadas', 'codirun-codir2me-cdn' ); ?></h3>
						<p class="codir2me-status-count"><?php echo esc_html( $stats['total_images'] ); ?></p>
					</div>
				</div>

				<div class="codir2me-status-card <?php echo $auto_delete_enabled ? 'auto-upload' : ''; ?>">
					<div class="codir2me-status-icon <?php echo $auto_delete_enabled ? 'active' : 'inactive'; ?>">
						<span class="dashicons <?php echo $auto_delete_enabled ? 'dashicons-yes-alt' : 'dashicons-no-alt'; ?>"></span>
					</div>
					<div class="codir2me-status-details">
						<h3><?php esc_html_e( 'Exclusão Automática', 'codirun-codir2me-cdn' ); ?></h3>
						<p class="codir2me-status <?php echo $auto_delete_enabled ? 'active' : 'inactive'; ?>">
							<?php echo $auto_delete_enabled ? esc_html__( 'Ativada', 'codirun-codir2me-cdn' ) : esc_html__( 'Desativada', 'codirun-codir2me-cdn' ); ?>
						</p>
					</div>
				</div>
			</div>
			
			<div class="codir2me-warning-text" style="margin-top: 15px;">
				<span class="dashicons dashicons-warning"></span> 
				<?php esc_html_e( 'A exclusão de arquivos no R2 é permanente. Se você excluir os arquivos, precisará fazer o upload novamente para restaurá-los.', 'codirun-codir2me-cdn' ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Renderiza a seção de configurações de exclusão automática
	 *
	 * @since 1.0.0
	 * @param bool   $auto_delete_enabled Estado da exclusão automática.
	 * @param string $auto_delete_thumbnail_option Opção de miniaturas.
	 * @param array  $auto_delete_selected_thumbnails Miniaturas selecionadas.
	 * @return void
	 */
	private function codir2me_render_auto_delete_settings( $auto_delete_enabled, $auto_delete_thumbnail_option, $auto_delete_selected_thumbnails ) {
		?>
		<div class="codir2me-section">
			<h2><?php esc_html_e( 'Exclusão Automática', 'codirun-codir2me-cdn' ); ?></h2>
			<p><?php esc_html_e( 'Configure como as imagens devem ser excluídas automaticamente do R2 quando forem removidas do WordPress.', 'codirun-codir2me-cdn' ); ?></p>
			
			<form method="post" action="" id="codir2meAutoDeleteSettings">
				<?php wp_nonce_field( 'codir2me_auto_delete_settings' ); ?>
				
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Status da Exclusão Automática', 'codirun-codir2me-cdn' ); ?></th>
						<td>
							<label class="codir2me-toggle-switch">
								<input type="checkbox" name="codir2me_auto_delete_enabled" value="1" <?php checked( $auto_delete_enabled ); ?> />
								<span class="codir2me-toggle-slider"></span>
							</label>
							<span class="description"><?php esc_html_e( 'Excluir automaticamente do R2 quando uma imagem for excluída do WordPress', 'codirun-codir2me-cdn' ); ?></span>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Opções de Miniaturas', 'codirun-codir2me-cdn' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( 'Opções de Exclusão de Miniaturas', 'codirun-codir2me-cdn' ); ?></legend>
								<label>
									<input type="radio" name="codir2me_auto_delete_thumbnail_option" value="all" <?php checked( $auto_delete_thumbnail_option, 'all' ); ?> />
									<?php esc_html_e( 'Excluir a imagem original e todas as suas miniaturas', 'codirun-codir2me-cdn' ); ?>
									<p class="description" style="margin-left: 24px;"><?php esc_html_e( 'Quando uma imagem for excluída do WordPress, remove tanto a imagem original quanto todas as suas miniaturas do R2.', 'codirun-codir2me-cdn' ); ?></p>
								</label>
								<br>
								<label>
									<input type="radio" name="codir2me_auto_delete_thumbnail_option" value="selected" <?php checked( $auto_delete_thumbnail_option, 'selected' ); ?> />
									<?php esc_html_e( 'Excluir a imagem original e apenas tamanhos específicos de miniaturas', 'codirun-codir2me-cdn' ); ?>
									<p class="description" style="margin-left: 24px;"><?php esc_html_e( 'Quando uma imagem for excluída do WordPress, remove a imagem original e apenas os tamanhos de miniatura selecionados abaixo.', 'codirun-codir2me-cdn' ); ?></p>
								</label>
								<br>
								<label>
									<input type="radio" name="codir2me_auto_delete_thumbnail_option" value="none" <?php checked( $auto_delete_thumbnail_option, 'none' ); ?> />
									<?php esc_html_e( 'Excluir apenas a imagem original, manter todas as miniaturas', 'codirun-codir2me-cdn' ); ?>
									<p class="description" style="margin-left: 24px;"><?php esc_html_e( 'Quando uma imagem for excluída do WordPress, remove apenas a imagem original do R2, mantendo todas as miniaturas.', 'codirun-codir2me-cdn' ); ?></p>
								</label>
							</fieldset>
							
							<div id="codir2me-auto-delete-thumbnail-sizes" class="codir2me-thumbnail-sizes" style="<?php echo ( 'selected' === $auto_delete_thumbnail_option ) ? 'display:block;' : 'display:none;'; ?>">
								<div class="codir2me-thumbnail-actions">
									<button id="codir2me-auto-select-all-thumbnails" class="button button-secondary"><?php esc_html_e( 'Selecionar Todos', 'codirun-codir2me-cdn' ); ?></button>
									<button id="codir2me-auto-deselect-all-thumbnails" class="button button-secondary"><?php esc_html_e( 'Desmarcar Todos', 'codirun-codir2me-cdn' ); ?></button>
								</div>
								<div class="codir2me-thumbnail-list">
									<?php
									// Obter informações sobre tamanhos de miniaturas.
									if ( file_exists( CODIR2ME_CDN_INCLUDES_DIR . 'admin/class-codir2me-admin-ui-thumbnails.php' ) ) {
										require_once CODIR2ME_CDN_INCLUDES_DIR . 'admin/class-codir2me-admin-ui-thumbnails.php';
										$thumbnails      = new CODIR2ME_Admin_UI_Thumbnails( $this->admin );
										$thumbnail_sizes = $thumbnails->codir2me_get_thumbnail_sizes_info();

										foreach ( $thumbnail_sizes as $size_name => $size_info ) :
											?>
										<div class="codir2me-thumbnail-size">
											<label>
												<input type="checkbox" name="codir2me_auto_delete_selected_thumbnails[]" value="<?php echo esc_attr( $size_name ); ?>" <?php checked( in_array( $size_name, $auto_delete_selected_thumbnails, true ) ); ?> />
												<strong><?php echo esc_html( $size_name ); ?></strong>
												(<?php echo esc_html( $size_info['dimensions'] ); ?>)
											</label>
										</div>
											<?php
										endforeach;
									}
									?>
								</div>
							</div>
						</td>
					</tr>
				</table>
				
				<p class="submit">
					<input type="submit" name="codir2me_auto_delete_settings_submit" class="button button-primary" value="<?php esc_attr_e( 'Salvar Configurações de Exclusão Automática', 'codirun-codir2me-cdn' ); ?>">
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Renderiza a seção de exclusão manual
	 *
	 * @since 1.0.0
	 * @param array $stats Estatísticas atuais.
	 * @param bool  $delete_in_progress Se há exclusão em andamento.
	 * @param array $delete_status Status da exclusão.
	 * @return void
	 */
	private function codir2me_render_manual_deletion_section( $stats, $delete_in_progress, $delete_status ) {
		?>
		<div class="codir2me-section">
			<h2><?php esc_html_e( 'Exclusão Manual de Arquivos', 'codirun-codir2me-cdn' ); ?></h2>
			<p><?php esc_html_e( 'Selecione arquivos específicos para excluir manualmente do seu bucket R2.', 'codirun-codir2me-cdn' ); ?></p>
			
			<?php if ( $delete_in_progress ) : ?>
				<?php $this->codir2me_render_deletion_progress( $delete_status ); ?>
			<?php else : ?>
				<?php $this->codir2me_render_manual_deletion_form( $stats ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Renderiza o progresso da exclusão em andamento
	 *
	 * @since 1.0.0
	 * @param array $delete_status Status da exclusão.
	 * @return void
	 */
	private function codir2me_render_deletion_progress( $delete_status ) {
		$total_items      = isset( $delete_status['total_items'] ) ? $delete_status['total_items'] : 0;
		$processed_items  = isset( $delete_status['processed_items'] ) ? $delete_status['processed_items'] : 0;
		$current_batch    = isset( $delete_status['current_batch'] ) ? $delete_status['current_batch'] : 0;
		$total_batches    = isset( $delete_status['total_batches'] ) ? $delete_status['total_batches'] : 0;
		$background_mode  = isset( $delete_status['background_mode'] ) ? $delete_status['background_mode'] : false;
		$is_paused        = isset( $delete_status['paused'] ) ? $delete_status['paused'] : false;
		$progress_percent = ( $total_items > 0 ) ? ( $processed_items / $total_items * 100 ) : 0;
		?>
		<div class="codir2me-upload-progress">
			<h3><?php esc_html_e( 'Exclusão em Andamento', 'codirun-codir2me-cdn' ); ?></h3>
			
			<div class="codir2me-progress-details">
				<p>
					<span class="codir2me-progress-label"><?php esc_html_e( 'Arquivos processados:', 'codirun-codir2me-cdn' ); ?></span>
					<span class="codir2me-progress-value"><?php echo esc_html( $processed_items ); ?> <?php esc_html_e( 'de', 'codirun-codir2me-cdn' ); ?> <?php echo esc_html( $total_items ); ?></span>
				</p>
				<p>
					<span class="codir2me-progress-label"><?php esc_html_e( 'Lotes processados:', 'codirun-codir2me-cdn' ); ?></span>
					<span class="codir2me-progress-value"><?php echo esc_html( $current_batch ); ?> <?php esc_html_e( 'de', 'codirun-codir2me-cdn' ); ?> <?php echo esc_html( $total_batches ); ?></span>
				</p>
				<?php if ( $background_mode ) : ?>
				<p>
					<span class="codir2me-progress-label"><?php esc_html_e( 'Modo de processamento:', 'codirun-codir2me-cdn' ); ?></span>
					<span class="codir2me-progress-value"><?php esc_html_e( 'Em segundo plano', 'codirun-codir2me-cdn' ); ?> <?php echo $is_paused ? esc_html__( '(Pausado)', 'codirun-codir2me-cdn' ) : esc_html__( '(Ativo)', 'codirun-codir2me-cdn' ); ?></span>
				</p>
				<?php endif; ?>
			</div>
			
			<div class="codir2me-progress-bar">
				<div class="codir2me-progress-inner" style="width: <?php echo esc_attr( $progress_percent ); ?>%;"></div>
			</div>
			
			<?php $this->codir2me_render_progress_controls( $background_mode, $is_paused ); ?>
		</div>
		<?php
	}

	/**
	 * Renderiza os controles de progresso
	 *
	 * @since 1.0.0
	 * @param bool $background_mode Se está em modo segundo plano.
	 * @param bool $is_paused Se está pausado.
	 * @return void
	 */
	private function codir2me_render_progress_controls( $background_mode, $is_paused ) {
		if ( $background_mode ) {
			?>
			<p class="codir2me-progress-info">
			<?php if ( $is_paused ) : ?>
					<span class="dashicons dashicons-controls-pause"></span> <?php esc_html_e( 'O processo está pausado. Clique em "Retomar" para continuar a exclusão.', 'codirun-codir2me-cdn' ); ?>
				<?php else : ?>
					<span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'O processo está rodando em segundo plano. Você pode fechar esta página.', 'codirun-codir2me-cdn' ); ?>
				<?php endif; ?>
			</p>
			
			<?php } else { ?>
			<p class="codir2me-progress-warning"><?php esc_html_e( 'Por favor, não feche esta página até que o processo termine.', 'codirun-codir2me-cdn' ); ?></p>
			
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="codir2me-continue-form">
				<?php wp_nonce_field( 'codir2me_process_delete_batch', 'codir2me_delete_batch_nonce' ); ?>
				<input type="hidden" name="action" value="codir2me_process_delete_batch">
				<button type="submit" name="process_delete_batch" class="button button-primary">
				<span class="dashicons dashicons-controls-play"></span>
					<?php esc_html_e( 'Continuar Exclusão (Próximo Lote)', 'codirun-codir2me-cdn' ); ?>
				</button>
				</form>
				<?php } ?>
		
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'codir2me_cancel_delete', 'codir2me_cancel_delete_nonce' ); ?>
			<input type="hidden" name="action" value="codir2me_cancel_delete">
			<button type="submit" name="cancel_delete" class="button button-secondary" style="background-color: #f56e28; color: white; border-color: #d65b25;">
			<span class="dashicons dashicons-no-alt"></span>
				<?php esc_html_e( 'Parar Exclusão', 'codirun-codir2me-cdn' ); ?>
			</button>
			</form>
		
		<p class="codir2me-note">
		<span class="dashicons dashicons-info"></span> 
			<?php esc_html_e( 'A qualquer momento você pode verificar o progresso voltando a esta página.', 'codirun-codir2me-cdn' ); ?>
		</p>
		<?php
	}

	/**
	 * Renderiza o formulário de exclusão manual
	 *
	 * @since 1.0.0
	 * @param array $stats Estatísticas atuais.
	 * @return void
	 */
	private function codir2me_render_manual_deletion_form( $stats ) {
		?>
		<form method="post" action="" id="codir2meDeleteForm">
			<?php wp_nonce_field( 'codir2me_delete_action' ); ?>
			
			<!-- Configuração de lote -->
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Tamanho do Lote', 'codirun-codir2me-cdn' ); ?></th>
					<td>
						<input type="number" name="codir2me_delete_batch_size" value="<?php echo esc_attr( get_option( 'codir2me_delete_batch_size', 50 ) ); ?>" class="small-text" min="1" max="500" />
						<p class="description"><?php esc_html_e( 'Número de arquivos a serem excluídos por lote (recomendado: 50)', 'codirun-codir2me-cdn' ); ?></p>
					</td>
				</tr>
				
				<tr>
					<th><?php esc_html_e( 'Modo de Processamento', 'codirun-codir2me-cdn' ); ?></th>
					<td>
						<label>
							<input type="radio" name="codir2me_delete_mode" value="immediate" checked>
							<?php esc_html_e( 'Processamento imediato (manter página aberta)', 'codirun-codir2me-cdn' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Os arquivos serão excluídos imediatamente, mas você precisará manter esta página aberta.', 'codirun-codir2me-cdn' ); ?></p>
						
						<br><br>
						
						<label>
							<input type="radio" name="codir2me_delete_mode" value="background">
							<?php esc_html_e( 'Processamento em segundo plano', 'codirun-codir2me-cdn' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'A exclusão acontecerá em segundo plano, permitindo que você continue usando o WordPress.', 'codirun-codir2me-cdn' ); ?></p>
					</td>
				</tr>
			</table>
			
			<!-- Exclusão de Arquivos Estáticos -->
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Arquivos Estáticos', 'codirun-codir2me-cdn' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="codir2me_delete_static" value="1">
							<?php
							/* translators: %s: número de arquivos estáticos */
							printf( esc_html__( 'Excluir todos os arquivos estáticos (%s arquivos)', 'codirun-codir2me-cdn' ), esc_html( $stats['total_static_files'] ) );
							?>
						</label>
						<p class="description"><?php esc_html_e( 'Esta opção irá excluir todos os arquivos JS, CSS, SVG e fontes do seu bucket R2.', 'codirun-codir2me-cdn' ); ?></p>
					</td>
				</tr>
			</table>
			
			<!-- Exclusão de Imagens -->
			<h2><?php esc_html_e( 'Exclusão Manual de Imagens', 'codirun-codir2me-cdn' ); ?></h2>
			
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Todas as Imagens', 'codirun-codir2me-cdn' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="codir2me_delete_all_images" value="1" id="deleteAllImages">
							<?php
							/* translators: %s: número total de imagens */
							printf( esc_html__( 'Excluir todas as imagens (%s arquivos)', 'codirun-codir2me-cdn' ), esc_html( $stats['total_images'] ) );
							?>
						</label>
						<p class="description"><?php esc_html_e( 'Esta opção irá excluir todas as imagens originais e miniaturas do seu bucket R2.', 'codirun-codir2me-cdn' ); ?></p>
					</td>
				</tr>
				
				<tr class="image-options">
					<th><?php esc_html_e( 'Apenas Imagens Originais', 'codirun-codir2me-cdn' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="codir2me_delete_original_images" value="1" class="image-group-checkbox">
							<?php
							/* translators: %s: número de imagens originais */
							printf( esc_html__( 'Excluir apenas imagens originais (%s arquivos)', 'codirun-codir2me-cdn' ), esc_html( $stats['original_images'] ) );
							?>
						</label>
						<p class="description"><?php esc_html_e( 'Esta opção irá excluir apenas as imagens originais, mantendo as miniaturas.', 'codirun-codir2me-cdn' ); ?></p>
					</td>
				</tr>
				
				<tr class="image-options">
					<th><?php esc_html_e( 'Todas as Miniaturas', 'codirun-codir2me-cdn' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="codir2me_delete_all_thumbnails" value="1" class="image-group-checkbox">
							<?php
							/* translators: %s: número de miniaturas */
							printf( esc_html__( 'Excluir todas as miniaturas (%s arquivos)', 'codirun-codir2me-cdn' ), esc_html( $stats['thumbnails'] ) );
							?>
						</label>
						<p class="description"><?php esc_html_e( 'Esta opção irá excluir todas as miniaturas, mantendo as imagens originais.', 'codirun-codir2me-cdn' ); ?></p>
					</td>
				</tr>
			</table>
			
			<div class="codir2me-delete-button-container" style="margin-top: 20px;">
				<input type="submit" name="codir2me_delete_submit" class="button button-primary" value="<?php esc_attr_e( 'Excluir Arquivos Selecionados', 'codirun-codir2me-cdn' ); ?>" id="codir2meDeleteButton">
			</div>
		</form>
		<?php
	}

	/**
	 * Renderiza a seção de exclusão rápida
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function codir2me_render_quick_delete_section() {
		?>
		<div class="codir2me-quick-delete-section">
			<h3><?php esc_html_e( 'Exclusão Rápida', 'codirun-codir2me-cdn' ); ?></h3>
			<p><?php esc_html_e( 'Esta opção excluirá TODOS os arquivos do seu bucket R2 sem possibilidade de desfazer.', 'codirun-codir2me-cdn' ); ?></p>
			
			<?php
			$quick_delete_started = false;

			if (
				isset( $_GET['quick_delete_started'], $_GET['_wpnonce'] ) &&
				'1' === sanitize_text_field( wp_unslash( $_GET['quick_delete_started'] ) ) &&
				wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'codir2me_quick_delete_nonce' )
			) {
				$quick_delete_started = true;
			}
			?>
			
			<?php if ( $quick_delete_started ) : ?>
				<?php $this->codir2me_render_quick_delete_progress(); ?>
			<?php else : ?>
				<?php $this->codir2me_render_quick_delete_form(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Renderiza o progresso da exclusão rápida
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function codir2me_render_quick_delete_progress() {
		?>
		<div class="codir2me-quick-delete-progress">
			<h4><?php esc_html_e( 'Exclusão em Andamento', 'codirun-codir2me-cdn' ); ?></h4>
			<div class="codir2me-progress-bar-container">
				<div class="codir2me-progress-bar">
					<div class="codir2me-progress-inner" id="codir2me-quick-delete-progress" style="width: 0%;"></div>
				</div>
			</div>
			
			<div class="codir2me-progress-details">
				<p>
					<span class="codir2me-progress-label"><?php esc_html_e( 'Arquivos processados:', 'codirun-codir2me-cdn' ); ?></span>
					<span class="codir2me-progress-value" id="codir2me-deleted-count">0</span>
				</p>
				<p>
					<span class="codir2me-progress-label"><?php esc_html_e( 'Status:', 'codirun-codir2me-cdn' ); ?></span>
					<span class="codir2me-progress-value" id="codir2me-quick-delete-status"><?php esc_html_e( 'Iniciando exclusão...', 'codirun-codir2me-cdn' ); ?></span>
				</p>
			</div>
			
			<p class="codir2me-progress-warning"><?php esc_html_e( 'Por favor, não feche esta página até que o processo termine.', 'codirun-codir2me-cdn' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Renderiza o formulário de exclusão rápida
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function codir2me_render_quick_delete_form() {
		?>
		<div class="codir2me-warning-box">
			<span class="dashicons dashicons-warning"></span>
			<strong><?php esc_html_e( 'ATENÇÃO:', 'codirun-codir2me-cdn' ); ?></strong> 
			<?php esc_html_e( 'Esta ação é irreversível e excluirá todos os arquivos do seu bucket R2. Use com extremo cuidado.', 'codirun-codir2me-cdn' ); ?>
		</div>
		
		<div class="codir2me-forms-wrapper" style="display: flex; gap: 10px; flex-wrap: wrap;">
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="codir2meQuickDeleteForm">
			<?php wp_nonce_field( 'codir2me_quick_delete', 'codir2me_quick_delete_nonce' ); ?>
			<input type="hidden" name="action" value="codir2me_quick_delete_start">
			
			<div class="codir2me-confirmation-checkbox">
				<label>
					<input type="checkbox" id="confirm-quick-delete" required>
					<?php esc_html_e( 'Estou ciente de que esta ação excluirá TODOS os arquivos do bucket R2 e não pode ser desfeita.', 'codirun-codir2me-cdn' ); ?>
				</label>
			</div>
			
			<div class="codir2me-quick-delete-button-container">
				<button type="submit" class="button button-danger">
					<span class="dashicons dashicons-trash"></span>
					<?php esc_html_e( 'Excluir Tudo Agora', 'codirun-codir2me-cdn' ); ?>
				</button>
			</div>
		</form>
		</div>
		<?php
	}

	/**
	 * Renderiza a barra lateral com dicas e informações
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function codir2me_render_sidebar() {
		?>
		<div class="codir2me-sidebar">
			<div class="codir2me-sidebar-widget">
				<h3><?php esc_html_e( 'Exclusão Automática', 'codirun-codir2me-cdn' ); ?></h3>
				<div class="codir2me-widget-content">
					<p><?php esc_html_e( 'A exclusão automática remove os arquivos do R2 quando você exclui a imagem correspondente no WordPress.', 'codirun-codir2me-cdn' ); ?></p>
					<p><strong><?php esc_html_e( 'Benefícios:', 'codirun-codir2me-cdn' ); ?></strong></p>
					<ul class="codir2me-tips-list">
						<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Mantém seu bucket R2 sincronizado', 'codirun-codir2me-cdn' ); ?></li>
						<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Evita acúmulo de arquivos não utilizados', 'codirun-codir2me-cdn' ); ?></li>
						<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Economiza espaço de armazenamento', 'codirun-codir2me-cdn' ); ?></li>
					</ul>
				</div>
			</div>
			
			<div class="codir2me-sidebar-widget">
				<h3><?php esc_html_e( 'Exclusão Manual', 'codirun-codir2me-cdn' ); ?></h3>
				<div class="codir2me-widget-content">
					<p><?php esc_html_e( 'Use a exclusão manual para limpar grupos específicos de arquivos do seu bucket R2.', 'codirun-codir2me-cdn' ); ?></p>
					<p><strong><?php esc_html_e( 'Casos de uso:', 'codirun-codir2me-cdn' ); ?></strong></p>
					<ul class="codir2me-tips-list">
						<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Remover arquivos de um tema antigo', 'codirun-codir2me-cdn' ); ?></li>
						<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Limpar arquivos de plugins desinstalados', 'codirun-codir2me-cdn' ); ?></li>
						<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Remover miniaturas específicas', 'codirun-codir2me-cdn' ); ?></li>
						<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Fazer uma limpeza completa antes de começar de novo', 'codirun-codir2me-cdn' ); ?></li>
					</ul>
				</div>
			</div>

			<div class="codir2me-sidebar-widget">
				<h3><?php esc_html_e( 'Dicas Importantes', 'codirun-codir2me-cdn' ); ?></h3>
				<div class="codir2me-widget-content">
					<p><strong><?php esc_html_e( 'Antes de excluir:', 'codirun-codir2me-cdn' ); ?></strong></p>
					<ul class="codir2me-tips-list">
						<li><span class="dashicons dashicons-warning"></span> <?php esc_html_e( 'Desative o CDN nas abas respectivas', 'codirun-codir2me-cdn' ); ?></li>
						<li><span class="dashicons dashicons-warning"></span> <?php esc_html_e( 'Considere fazer um backup do seu site', 'codirun-codir2me-cdn' ); ?></li>
						<li><span class="dashicons dashicons-warning"></span> <?php esc_html_e( 'A exclusão é permanente e não pode ser desfeita', 'codirun-codir2me-cdn' ); ?></li>
					</ul>
					<p><strong><?php esc_html_e( 'Durante a exclusão em lotes:', 'codirun-codir2me-cdn' ); ?></strong></p>
					<ul class="codir2me-tips-list">
						<li><span class="dashicons dashicons-info"></span> <?php esc_html_e( 'Não feche a página até que o processo termine', 'codirun-codir2me-cdn' ); ?></li>
						<li><span class="dashicons dashicons-info"></span> <?php esc_html_e( 'Se o processo parar, clique em "Continuar Exclusão"', 'codirun-codir2me-cdn' ); ?></li>
						<li><span class="dashicons dashicons-info"></span> <?php esc_html_e( 'Para sites com muitas imagens, use lotes menores', 'codirun-codir2me-cdn' ); ?></li>
					</ul>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX: Verifica o status da exclusão em segundo plano - VERSÃO SIMPLES
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function codir2me_ajax_check_background_deletion_status() {
		// Verificar nonce e permissões.
		check_ajax_referer( 'codir2me_background_deletion_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Acesso negado', 'codirun-codir2me-cdn' ) ) );
		}

		// Obter status atual.
		$delete_in_progress = get_option( 'codir2me_delete_in_progress', false );
		$delete_status      = get_option( 'codir2me_delete_status', array() );

		// CORREÇÃO SIMPLES: Verificar se o processo terminou mas não foi atualizado.
		$items_to_delete = get_option( 'codir2me_items_to_delete', array() );
		if ( empty( $items_to_delete ) && $delete_in_progress ) {
			$this->codir2me_complete_deletion_process();
			$delete_in_progress = false;

			// Marcar como concluído.
			$delete_status['in_progress'] = false;
			$delete_status['completed']   = true;
			update_option( 'codir2me_delete_status', $delete_status );
			delete_option( 'codir2me_delete_in_progress' );
		}

		// CORREÇÃO SIMPLES: Se status diz que está concluído, garantir que in_progress seja false.
		if ( isset( $delete_status['completed'] ) && $delete_status['completed'] ) {
			$delete_in_progress = false;
		}

		// Cancelar cron se processo não está mais em andamento.
		if ( ! $delete_in_progress ) {
			$timestamp = wp_next_scheduled( 'codir2me_background_deletion_event' );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, 'codir2me_background_deletion_event' );
			}
		}

		$response = array(
			'in_progress'     => $delete_in_progress,
			'processed_items' => isset( $delete_status['processed_items'] ) ? $delete_status['processed_items'] : 0,
			'total_items'     => isset( $delete_status['total_items'] ) ? $delete_status['total_items'] : 0,
			'current_batch'   => isset( $delete_status['current_batch'] ) ? $delete_status['current_batch'] : 0,
			'total_batches'   => isset( $delete_status['total_batches'] ) ? $delete_status['total_batches'] : 0,
			'paused'          => isset( $delete_status['paused'] ) ? $delete_status['paused'] : false,
			'background_mode' => isset( $delete_status['background_mode'] ) ? $delete_status['background_mode'] : false,
			'completed'       => isset( $delete_status['completed'] ) ? $delete_status['completed'] : false,
		);

		wp_send_json_success( $response );
	}

	/**
	 * Pausa o processo de exclusão em segundo plano
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function codir2me_pause_background_deletion() {
		// Verificar nonce e permissões.
		check_admin_referer( 'codir2me_pause_background_deletion', 'codir2me_pause_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Você não tem permissão para executar esta ação.', 'codirun-codir2me-cdn' ) );
		}

		$delete_status           = get_option( 'codir2me_delete_status', array() );
		$delete_status['paused'] = true;
		update_option( 'codir2me_delete_status', $delete_status );

		// Redirecionar de volta.
		wp_safe_redirect( admin_url( 'admin.php?page=codirun-codir2me-cdn-delete&delete_paused=1' ) );
		exit;
	}

	/**
	 * Retoma o processo de exclusão em segundo plano
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function codir2me_resume_background_deletion() {
		// Verificar nonce e permissões.
		check_admin_referer( 'codir2me_resume_background_deletion', 'codir2me_resume_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Você não tem permissão para executar esta ação.', 'codirun-codir2me-cdn' ) );
		}

		$delete_status           = get_option( 'codir2me_delete_status', array() );
		$delete_status['paused'] = false;
		update_option( 'codir2me_delete_status', $delete_status );

		// Redirecionar de volta.
		wp_safe_redirect( admin_url( 'admin.php?page=codirun-codir2me-cdn-delete&delete_resumed=1' ) );
		exit;
	}

	/**
	 * Inicia o processo de exclusão em lotes
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function codir2me_start_delete_batch_action() {
		wp_safe_redirect( admin_url( 'admin.php?page=codirun-codir2me-cdn-delete&auto_continue=1' ) );
		exit;
	}

	/**
	 * Processa um lote de exclusão manual
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function codir2me_process_delete_batch_action() {
		// Verificar nonce e permissões.
		check_admin_referer( 'codir2me_process_delete_batch', 'codir2me_delete_batch_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Você não tem permissão para executar esta ação.', 'codirun-codir2me-cdn' ) );
		}

		// Obter itens a serem excluídos.
		$items_to_delete = get_option( 'codir2me_items_to_delete', array() );
		$delete_status   = get_option( 'codir2me_delete_status', array() );
		$batch_size      = get_option( 'codir2me_delete_batch_size', 50 );

		// Verificar se ainda há itens para processar.
		if ( empty( $items_to_delete ) ) {
			$this->codir2me_complete_deletion_process();
			wp_safe_redirect( admin_url( 'admin.php?page=codirun-codir2me-cdn-delete&delete_complete=1' ) );
			exit;
		}

		try {
			// Criar cliente S3.
			$s3_client = $this->codir2me_create_s3_client();
			$bucket    = get_option( 'codir2me_bucket' );

			// Processar um lote de itens.
			$batch            = array_slice( $items_to_delete, 0, $batch_size );
			$processed        = 0;
			$deleted_counters = array(
				'static'          => isset( $delete_status['deleted_counts']['static'] ) ? $delete_status['deleted_counts']['static'] : 0,
				'original_images' => isset( $delete_status['deleted_counts']['original_images'] ) ? $delete_status['deleted_counts']['original_images'] : 0,
				'thumbnails'      => isset( $delete_status['deleted_counts']['thumbnails'] ) ? $delete_status['deleted_counts']['thumbnails'] : 0,
			);

			foreach ( $batch as $item ) {
				$key  = $item['key'];
				$type = $item['type'];

				try {
					// Excluir o objeto do R2.
					$s3_client->deleteObject(
						array(
							'Bucket' => $bucket,
							'Key'    => $key,
						)
					);

					// Incrementar contadores.
					if ( 'static' === $type ) {
						++$deleted_counters['static'];
					} elseif ( 'original_image' === $type ) {
						++$deleted_counters['original_images'];
					} elseif ( 'thumbnail' === $type || 'image' === $type ) {
						// Verificar se é uma miniatura.
						$filename     = basename( $key );
						$is_thumbnail = preg_match( '/-\d+x\d+\.[a-zA-Z]+$/', $filename ) ||
										preg_match( '/-[a-zA-Z_]+\.[a-zA-Z]+$/', $filename );

						if ( $is_thumbnail ) {
							++$deleted_counters['thumbnails'];
						} else {
							++$deleted_counters['original_images'];
						}
					}
				} catch ( Exception $e ) {
					// Log do erro usando sistema de logs do plugin.
					codir2me_cdn_log( 'Erro ao excluir ' . $key . ': ' . $e->getMessage(), 'error' );
				}

				++$processed;
			}

			// Remover os itens processados da lista.
			$items_to_delete = array_slice( $items_to_delete, $processed );
			update_option( 'codir2me_items_to_delete', $items_to_delete );

			// Atualizar o status.
			$delete_status['processed_items'] += $processed;
			++$delete_status['current_batch'];
			$delete_status['deleted_counts'] = $deleted_counters;
			update_option( 'codir2me_delete_status', $delete_status );

			// Verificar se todos os itens foram processados.
			if ( empty( $items_to_delete ) ) {
				$this->codir2me_complete_deletion_process();
				wp_safe_redirect( admin_url( 'admin.php?page=codirun-codir2me-cdn-delete&delete_complete=1' ) );
			} else {
				// Redirecionar para o próximo lote.
				wp_safe_redirect( admin_url( 'admin.php?page=codirun-codir2me-cdn-delete&auto_continue=1' ) );
			}
		} catch ( Exception $e ) {
			// Em caso de erro, registrar e redirecionar.
			codir2me_cdn_log( 'Erro ao excluir lote: ' . $e->getMessage(), 'error' );
			update_option( 'codir2me_delete_error', $e->getMessage() );
			wp_safe_redirect( admin_url( 'admin.php?page=codirun-codir2me-cdn-delete&delete_error=1' ) );
		}

		exit;
	}

	/**
	 * Processa o formulário de exclusão manual COM VERIFICAÇÃO DE NONCE COMPLETA
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function codir2me_process_delete_form() {
		// Verificar se algo foi selecionado para exclusão - COM VERIFICAÇÃO SEGURA.
		$something_selected = false;
		$delete_type        = '';

		// Verificação corrigida do nonce.
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'codir2me_delete_action' ) ) {
			wp_die( esc_html__( 'Ação não autorizada.', 'codirun-codir2me-cdn' ) );
		}

		if ( isset( $_POST['codir2me_delete_static'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['codir2me_delete_static'] ) ) ) {
			$something_selected = true;
			$delete_type        = 'static';
		}
		if ( isset( $_POST['codir2me_delete_all_images'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['codir2me_delete_all_images'] ) ) ) {
			$something_selected = true;
			$delete_type        = 'all_images';
		}
		if ( isset( $_POST['codir2me_delete_original_images'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['codir2me_delete_original_images'] ) ) ) {
			$something_selected = true;
			$delete_type        = 'original_images';
		}
		if ( isset( $_POST['codir2me_delete_all_thumbnails'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['codir2me_delete_all_thumbnails'] ) ) ) {
			$something_selected = true;
			$delete_type        = 'all_thumbnails';
		}

		if ( ! $something_selected ) {
			add_action(
				'admin_notices',
				function () {
					?>
				<div class="notice notice-warning is-dismissible">
					<p><?php esc_html_e( 'Nenhum arquivo foi selecionado para exclusão. Por favor, selecione pelo menos um tipo de arquivo.', 'codirun-codir2me-cdn' ); ?></p>
				</div>
					<?php
				}
			);
			return;
		}

		// Verificar configurações.
		try {
			$this->codir2me_create_s3_client();
		} catch ( Exception $e ) {
			add_action(
				'admin_notices',
				function () use ( $e ) {
					?>
				<div class="notice notice-error is-dismissible">
					<p><?php esc_html_e( 'Erro de configuração:', 'codirun-codir2me-cdn' ); ?> <?php echo esc_html( $e->getMessage() ); ?></p>
				</div>
					<?php
				}
			);
			return;
		}

		// Obter tamanho do lote com validação segura.
		$batch_size = 50; // Default.
		if ( isset( $_POST['codir2me_delete_batch_size'] ) ) {
			$batch_size = intval( wp_unslash( $_POST['codir2me_delete_batch_size'] ) );
			if ( $batch_size < 1 ) {
				$batch_size = 50;
			}
		}

		// Atualizar o tamanho do lote para uso futuro.
		update_option( 'codir2me_delete_batch_size', $batch_size );

		// Preparar os itens a serem excluídos.
		$items_to_delete = $this->codir2me_prepare_items_for_deletion( $delete_type );

		// Verificar o modo de exclusão com validação segura.
		$delete_mode = 'immediate'; // Default.
		if ( isset( $_POST['codir2me_delete_mode'] ) ) {
			$delete_mode = sanitize_text_field( wp_unslash( $_POST['codir2me_delete_mode'] ) );
		}

		// Salvar os itens a serem excluídos.
		update_option( 'codir2me_items_to_delete', $items_to_delete );

		if ( 'background' === $delete_mode ) {
			// Configurar para exclusão em segundo plano.
			$this->codir2me_setup_background_deletion( $items_to_delete, $delete_type, $batch_size );
			wp_safe_redirect( admin_url( 'admin.php?page=codirun-codir2me-cdn-delete&background_setup=1' ) );
			exit;
		} else {
			// Configurar para exclusão imediata.
			$total_items   = count( $items_to_delete );
			$total_batches = ceil( $total_items / $batch_size );

			$delete_status = array(
				'type'            => $delete_type,
				'total_items'     => $total_items,
				'processed_items' => 0,
				'total_batches'   => $total_batches,
				'current_batch'   => 0,
				'start_time'      => time(),
				'background_mode' => false,
				'deleted_counts'  => array(
					'static'          => 0,
					'original_images' => 0,
					'thumbnails'      => 0,
				),
			);

			update_option( 'codir2me_delete_status', $delete_status );
			update_option( 'codir2me_delete_in_progress', true );

			// Redirecionar para iniciar o processamento.
			wp_safe_redirect( admin_url( 'admin-post.php?action=codir2me_start_delete_batch' ) );
			exit;
		}
	}

	/**
	 * Prepara os itens para exclusão com base no tipo selecionado
	 *
	 * @since 1.0.0
	 * @param string $delete_type Tipo de exclusão.
	 * @return array Lista de itens para exclusão.
	 */
	private function codir2me_prepare_items_for_deletion( $delete_type ) {
		$items_to_delete = array();

		if ( 'static' === $delete_type ) {
			// Arquivos estáticos.
			$uploaded_files = get_option( 'codir2me_uploaded_files', array() );
			foreach ( $uploaded_files as $file_path ) {
				$items_to_delete[] = array(
					'key'  => $file_path,
					'type' => 'static',
				);
			}
		} elseif ( 'all_images' === $delete_type ) {
			// Todas as imagens.
			$uploaded_images = get_option( 'codir2me_uploaded_images', array() );
			foreach ( $uploaded_images as $image_path ) {
				$items_to_delete[] = array(
					'key'  => $image_path,
					'type' => 'image',
				);
			}
		} elseif ( 'original_images' === $delete_type ) {
			// Apenas imagens originais.
			$uploaded_images = get_option( 'codir2me_uploaded_images', array() );
			foreach ( $uploaded_images as $image_path ) {
				$filename     = basename( $image_path );
				$is_thumbnail = preg_match( '/-\d+x\d+\.[a-zA-Z]+$/', $filename ) ||
								preg_match( '/-[a-zA-Z_]+\.[a-zA-Z]+$/', $filename );

				if ( ! $is_thumbnail ) {
					$items_to_delete[] = array(
						'key'  => $image_path,
						'type' => 'original_image',
					);
				}
			}
		} elseif ( 'all_thumbnails' === $delete_type ) {
			// Todas as miniaturas.
			$uploaded_images = get_option( 'codir2me_uploaded_images', array() );
			foreach ( $uploaded_images as $image_path ) {
				$filename     = basename( $image_path );
				$is_thumbnail = preg_match( '/-\d+x\d+\.[a-zA-Z]+$/', $filename ) ||
								preg_match( '/-[a-zA-Z_]+\.[a-zA-Z]+$/', $filename );

				if ( $is_thumbnail ) {
					$items_to_delete[] = array(
						'key'  => $image_path,
						'type' => 'thumbnail',
					);
				}
			}
		}

		return $items_to_delete;
	}

	/**
	 * Configura o processamento em segundo plano otimizado
	 *
	 * @param array  $items_to_delete Lista de itens.
	 * @param string $delete_type Tipo de exclusão.
	 * @param int    $batch_size Tamanho do lote (fixo em 1000).
	 * @return void
	 */
	private function codir2me_setup_background_deletion( $items_to_delete, $delete_type, $batch_size = 1000 ) {
		// Forçar batch_size para 1000 (máximo do R2).
		$batch_size = 1000;

		$total_items   = count( $items_to_delete );
		$total_batches = ceil( $total_items / $batch_size );

		$delete_status = array(
			'type'            => $delete_type,
			'total_items'     => $total_items,
			'processed_items' => 0,
			'total_batches'   => $total_batches,
			'current_batch'   => 0,
			'start_time'      => time(),
			'background_mode' => true,
			'paused'          => false,
			'batch_size'      => $batch_size,
			'last_execution'  => 0,
			'deleted_counts'  => array(
				'static'          => 0,
				'original_images' => 0,
				'thumbnails'      => 0,
			),
		);

		update_option( 'codir2me_delete_status', $delete_status );
		update_option( 'codir2me_delete_in_progress', true );
		update_option( 'codir2me_items_to_delete', $items_to_delete );

		// Definir delay para o primeiro lote (10 segundos por padrão).
		$first_batch_delay = apply_filters( 'codir2me_first_batch_delay', 10 );
		$first_batch_time  = time() + $first_batch_delay;

		// Registrar o primeiro evento com delay de 10 segundos.
		if ( ! wp_next_scheduled( 'codir2me_background_deletion_event' ) ) {
			wp_schedule_single_event( $first_batch_time, 'codir2me_background_deletion_event' );
		}

		// Log para debug.
		codir2me_cdn_log(
			sprintf(
				'Iniciando exclusão em segundo plano: %d itens em %d lotes de %d arquivos. Primeiro lote em %d segundos.',
				$total_items,
				$total_batches,
				$batch_size,
				$first_batch_delay
			),
			'info'
		);
	}

	/**
	 * Processa um lote otimizado em segundo plano
	 *
	 * @return void
	 */
	public function codir2me_process_background_deletion_batch() {
		// Verificar se existe processo em andamento.
		if ( ! get_option( 'codir2me_delete_in_progress', false ) ) {
			$this->codir2me_cleanup_cron_event();
			return;
		}

		// Verificar se está pausado.
		$delete_status = get_option( 'codir2me_delete_status', array() );
		if ( isset( $delete_status['paused'] ) && $delete_status['paused'] ) {
			return;
		}

		// Controle de execução para evitar sobreposição.
		$last_execution = isset( $delete_status['last_execution'] ) ? $delete_status['last_execution'] : 0;
		$current_time   = time();

		// Se a última execução foi há menos de 10 minutos, pular.
		if ( ( $current_time - $last_execution ) < 600 ) {
			codir2me_cdn_log( 'Pulando execução - muito próxima da anterior', 'debug' );
			return;
		}

		// Obter itens para processar.
		$items_to_delete = get_option( 'codir2me_items_to_delete', array() );
		$batch_size      = 1000;

		if ( empty( $items_to_delete ) ) {
			codir2me_cdn_log( 'Nenhum item restante para exclusão - finalizando processo', 'info' );
			// CORREÇÃO SIMPLES: Finalizar processo corretamente.
			$this->codir2me_complete_deletion_process();
			$this->codir2me_cleanup_cron_event();

			// Marcar como concluído no status.
			$delete_status['in_progress'] = false;
			$delete_status['completed']   = true;
			update_option( 'codir2me_delete_status', $delete_status );

			// Limpar flag principal.
			delete_option( 'codir2me_delete_in_progress' );
			return;
		}

		$batch_start_time  = microtime( true );
		$actual_batch_size = min( $batch_size, count( $items_to_delete ) );

		codir2me_cdn_log(
			sprintf(
				'=== INICIANDO LOTE DE EXCLUSÃO ===
Lote atual: %d
Tamanho do lote: %d arquivos
Itens restantes: %d
Memória atual: %s',
				( $delete_status['current_batch'] ?? 0 ) + 1,
				$actual_batch_size,
				count( $items_to_delete ),
				size_format( memory_get_usage() )
			),
			'info'
		);

		try {
			// Aumentar limites para processamento pesado.
			if ( function_exists( 'wp_raise_memory_limit' ) ) {
				wp_raise_memory_limit( 'admin' );
			}

			$s3_setup_start = microtime( true );

			// Criar cliente S3.
			$s3_client = $this->codir2me_create_s3_client();
			$bucket    = get_option( 'codir2me_bucket' );

			$s3_setup_time = ( microtime( true ) - $s3_setup_start ) * 1000;
			codir2me_cdn_log( sprintf( 'Cliente S3 criado em %.2fms', $s3_setup_time ), 'debug' );

			// Processar lote de 1000 arquivos.
			$batch = array_slice( $items_to_delete, 0, $batch_size );

			$preparation_start = microtime( true );
			$objects_to_delete = array();
			foreach ( $batch as $item ) {
				$objects_to_delete[] = array( 'Key' => $item['key'] );
			}
			$preparation_time = ( microtime( true ) - $preparation_start ) * 1000;

			codir2me_cdn_log(
				sprintf(
					'Lote preparado: %d objetos em %.2fms',
					count( $batch ),
					$preparation_time
				),
				'debug'
			);

			// Excluir objetos em massa (máximo 1000 por requisição R2).
			if ( ! empty( $objects_to_delete ) ) {
				$deletion_start = microtime( true );

				$delete_result = $s3_client->deleteObjects(
					array(
						'Bucket' => $bucket,
						'Delete' => array(
							'Objects' => $objects_to_delete,
							'Quiet'   => false,
						),
					)
				);

				// CORREÇÃO: Acessar os dados corretamente do objeto AsyncAws.
				try {
					// Aguardar a conclusão da operação.
					$resolve_start = microtime( true );
					$response      = $delete_result->resolve();
					$resolve_time  = ( microtime( true ) - $resolve_start ) * 1000;

					$deletion_time = ( microtime( true ) - $deletion_start ) * 1000;

					codir2me_cdn_log(
						sprintf(
							'Exclusão AWS concluída: %.2fms total (resolve: %.2fms)',
							$deletion_time,
							$resolve_time
						),
						'debug'
					);

					// Log de erros se houver - acessando corretamente o array response.
					if ( isset( $response['Errors'] ) && ! empty( $response['Errors'] ) ) {
						$error_count = count( $response['Errors'] );
						codir2me_cdn_log(
							sprintf( 'ATENÇÃO: %d erros na exclusão do lote', $error_count ),
							'warning'
						);

						foreach ( $response['Errors'] as $error ) {
							codir2me_cdn_log(
								sprintf( 'Erro ao excluir %s: %s', $error['Key'], $error['Message'] ),
								'warning'
							);
						}
					}

					// Log de sucessos se houver.
					if ( isset( $response['Deleted'] ) && ! empty( $response['Deleted'] ) ) {
						$success_count = count( $response['Deleted'] );
						codir2me_cdn_log(
							sprintf( 'Sucessos: %d arquivos excluídos corretamente', $success_count ),
							'debug'
						);
					}
				} catch ( Exception $resolve_error ) {
					codir2me_cdn_log( 'Erro ao resolver resultado da exclusão: ' . $resolve_error->getMessage(), 'warning' );
				}
			}

			$processed_count = count( $batch );

			// Atualizar listas.
			$update_start    = microtime( true );
			$items_to_delete = array_slice( $items_to_delete, $processed_count );
			update_option( 'codir2me_items_to_delete', $items_to_delete );

			// Atualizar status.
			$delete_status['processed_items'] += $processed_count;
			++$delete_status['current_batch'];
			$delete_status['last_execution'] = $current_time;

			// CORREÇÃO SIMPLES: Verificar se terminou e marcar corretamente.
			if ( empty( $items_to_delete ) ) {
				codir2me_cdn_log( 'Lote final processado - marcando como concluído', 'info' );
				$delete_status['in_progress'] = false;
				$delete_status['completed']   = true;

				// Finalizar processo.
				$this->codir2me_complete_deletion_process();
				$this->codir2me_cleanup_cron_event();

				// Limpar flag principal.
				delete_option( 'codir2me_delete_in_progress' );
			}

			update_option( 'codir2me_delete_status', $delete_status );
			$update_time = ( microtime( true ) - $update_start ) * 1000;

			$total_batch_time         = ( microtime( true ) - $batch_start_time ) * 1000;
			$items_per_second         = $processed_count / ( $total_batch_time / 1000 );
			$remaining_items          = count( $items_to_delete );
			$estimated_remaining_time = $remaining_items > 0 ? ( $remaining_items / $items_per_second ) : 0;

			codir2me_cdn_log(
				sprintf(
					'=== LOTE CONCLUÍDO ===
Arquivos processados: %d
Tempo total do lote: %.2fms
Velocidade: %.1f arquivos/segundo
Atualização BD: %.2fms
Progresso: %d/%d (%.1f%%)
Restam: %d arquivos
Tempo estimado restante: %.1f minutos
Memória pico: %s
Status: %s',
					$processed_count,
					$total_batch_time,
					$items_per_second,
					$update_time,
					$delete_status['processed_items'],
					$delete_status['total_items'],
					( $delete_status['processed_items'] / $delete_status['total_items'] * 100 ),
					$remaining_items,
					$estimated_remaining_time / 60,
					size_format( memory_get_peak_usage() ),
					empty( $items_to_delete ) ? 'CONCLUÍDO' : 'EM ANDAMENTO'
				),
				'info'
			);

		} catch ( Exception $e ) {
			$error_time = ( microtime( true ) - $batch_start_time ) * 1000;
			codir2me_cdn_log(
				sprintf(
					'ERRO no processamento em segundo plano após %.2fms: %s',
					$error_time,
					$e->getMessage()
				),
				'error'
			);

			// Atualizar status com erro.
			$delete_status                   = get_option( 'codir2me_delete_status', array() );
			$delete_status['last_error']     = $e->getMessage();
			$delete_status['last_execution'] = time();
			update_option( 'codir2me_delete_status', $delete_status );
		}
	}

	/**
	 * Limpa o evento cron quando não é mais necessário
	 *
	 * @return void
	 */
	private function codir2me_cleanup_cron_event() {
		$timestamp = wp_next_scheduled( 'codir2me_background_deletion_event' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'codir2me_background_deletion_event' );
			codir2me_cdn_log( 'Evento cron de exclusão removido', 'info' );
		}
	}

	/**
	 * Adiciona intervalo personalizado de 15 minutos
	 * Adicione esta função na classe principal ou no arquivo de background processor
	 *
	 * @param array $schedules Lista de intervalos de agendamento existentes do WordPress.
	 * @return array Lista de intervalos atualizada com o novo agendamento.
	 */
	public function codir2me_add_fifteen_minute_cron_interval( $schedules ) {
		$schedules['codir2me_fifteen_minutes'] = array(
			'interval' => 900,
			'display'  => __( 'A cada 15 minutos', 'codirun-codir2me-cdn' ),
		);
		return $schedules;
	}

	/**
	 * Função otimizada para estatísticas de progresso em tempo real
	 *
	 * @return array Status atual da exclusão
	 */
	public function codir2me_get_deletion_progress() {
		$delete_status   = get_option( 'codir2me_delete_status', array() );
		$items_remaining = count( get_option( 'codir2me_items_to_delete', array() ) );

		if ( empty( $delete_status ) ) {
			return array(
				'in_progress' => false,
				'progress'    => 0,
				'message'     => __( 'Nenhuma exclusão em andamento', 'codirun-codir2me-cdn' ),
			);
		}

		$total_items     = isset( $delete_status['total_items'] ) ? $delete_status['total_items'] : 0;
		$processed_items = isset( $delete_status['processed_items'] ) ? $delete_status['processed_items'] : 0;

		$progress_percent = $total_items > 0 ? ( $processed_items / $total_items ) * 100 : 0;

		// Calcular tempo estimado baseado no intervalo de 15 minutos.
		$batches_remaining = ceil( $items_remaining / 1000 );
		$minutes_remaining = $batches_remaining * 15;

		return array(
			'in_progress'       => get_option( 'codir2me_delete_in_progress', false ),
			'progress'          => round( $progress_percent, 2 ),
			'total_items'       => $total_items,
			'processed_items'   => $processed_items,
			'items_remaining'   => $items_remaining,
			'batches_remaining' => $batches_remaining,
			'estimated_minutes' => $minutes_remaining,
			'paused'            => isset( $delete_status['paused'] ) ? $delete_status['paused'] : false,
			'last_execution'    => isset( $delete_status['last_execution'] ) ? $delete_status['last_execution'] : 0,
			'message'           => $this->codir2me_get_progress_message( $delete_status, $items_remaining, $minutes_remaining ),
		);
	}

	/**
	 * Gera mensagem de progresso humanizada
	 *
	 * @param array $delete_status Status da exclusão.
	 * @param int   $items_remaining Itens restantes.
	 * @param int   $minutes_remaining Minutos estimados.
	 * @return string Mensagem de progresso
	 */
	private function codir2me_get_progress_message( $delete_status, $items_remaining, $minutes_remaining ) {
		if ( isset( $delete_status['paused'] ) && $delete_status['paused'] ) {
			return __( 'Exclusão pausada', 'codirun-codir2me-cdn' );
		}

		if ( $items_remaining <= 0 ) {
			return __( 'Exclusão concluída', 'codirun-codir2me-cdn' );
		}

		if ( $minutes_remaining <= 15 ) {
			return __( 'Último lote - conclusão em até 15 minutos', 'codirun-codir2me-cdn' );
		}

		$hours = floor( $minutes_remaining / 60 );
		$mins  = $minutes_remaining % 60;

		if ( $hours > 0 ) {
			return sprintf(
				// translators: %1$d: horas, %2$d: minutos, %3$d: quantidade de arquivos restantes.
				__( 'Tempo estimado: %1$dh %2$dmin (%3$d arquivos restantes)', 'codirun-codir2me-cdn' ),
				$hours,
				$mins,
				$items_remaining
			);
		} else {
			return sprintf(
				// translators: %1$d: minutos restantes, %2$d: quantidade de arquivos restantes.
				__( 'Tempo estimado: %1$d minutos (%2$d arquivos restantes)', 'codirun-codir2me-cdn' ),
				$minutes_remaining,
				$items_remaining
			);
		}
	}

	/**
	 * Completa o processo de exclusão
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function codir2me_complete_deletion_process() {
		$delete_status = get_option( 'codir2me_delete_status', array() );
		$delete_type   = isset( $delete_status['type'] ) ? $delete_status['type'] : '';

		// Atualizar listas com base no tipo de exclusão.
		if ( 'static' === $delete_type ) {
			update_option( 'codir2me_uploaded_files', array() );
		} elseif ( 'all_images' === $delete_type ) {
			$this->codir2me_clear_wordpress_file_lists();
		} elseif ( 'original_images' === $delete_type ) {
			$this->codir2me_remove_original_images_from_lists();
		} elseif ( 'all_thumbnails' === $delete_type ) {
			$this->codir2me_remove_thumbnails_from_lists();
		}

		$this->codir2me_recalculate_thumbnail_stats();

		// Limpar status de exclusão.
		delete_option( 'codir2me_delete_in_progress' );
		delete_option( 'codir2me_items_to_delete' );
	}

	/**
	 * Remove apenas imagens originais das listas
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function codir2me_remove_original_images_from_lists() {
		$uploaded_images  = get_option( 'codir2me_uploaded_images', array() );
		$remaining_images = array();

		foreach ( $uploaded_images as $image_path ) {
			$filename     = basename( $image_path );
			$is_thumbnail = preg_match( '/-\d+x\d+\.[a-zA-Z]+$/', $filename ) ||
							preg_match( '/-[a-zA-Z_]+\.[a-zA-Z]+$/', $filename );

			if ( $is_thumbnail ) {
				$remaining_images[] = $image_path;
			}
		}

		update_option( 'codir2me_uploaded_images', $remaining_images );
		update_option( 'codir2me_original_images_count', 0 );
		update_option( 'codir2me_all_images_sent', false );
	}

	/**
	 * Remove apenas miniaturas das listas
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function codir2me_remove_thumbnails_from_lists() {
		$uploaded_images  = get_option( 'codir2me_uploaded_images', array() );
		$remaining_images = array();

		foreach ( $uploaded_images as $image_path ) {
			$filename     = basename( $image_path );
			$is_thumbnail = preg_match( '/-\d+x\d+\.[a-zA-Z]+$/', $filename ) ||
							preg_match( '/-[a-zA-Z_]+\.[a-zA-Z]+$/', $filename );

			if ( ! $is_thumbnail ) {
				$remaining_images[] = $image_path;
			}
		}

		update_option( 'codir2me_uploaded_images', $remaining_images );
		update_option( 'codir2me_uploaded_thumbnails_by_size', array() );
	}

	/**
	 * Processa as configurações de exclusão automática COM VERIFICAÇÃO SEGURA
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function codir2me_process_auto_delete_settings() {
		// Verifica o nonce antes de qualquer processamento.
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'codir2me_auto_delete_settings' ) ) {
			wp_die( esc_html__( 'Falha na verificação de segurança. Tente novamente.', 'codirun-codir2me-cdn' ) );
		}

		// Atualizar configuração de exclusão automática - verificação segura.
		$auto_delete_enabled = false;
		if ( isset( $_POST['codir2me_auto_delete_enabled'] ) ) {
			$auto_delete_enabled = true;
		}
		update_option( 'codir2me_auto_delete_enabled', $auto_delete_enabled );

		// Atualizar opção de miniaturas - verificação segura.
		$auto_delete_thumbnail_option = 'all'; // Default.
		if ( isset( $_POST['codir2me_auto_delete_thumbnail_option'] ) ) {
			$auto_delete_thumbnail_option = sanitize_text_field( wp_unslash( $_POST['codir2me_auto_delete_thumbnail_option'] ) );
		}
		update_option( 'codir2me_auto_delete_thumbnail_option', $auto_delete_thumbnail_option );

		// Salvar miniaturas selecionadas - verificação segura.
		$selected_thumbnails = array();
		if ( isset( $_POST['codir2me_auto_delete_selected_thumbnails'] ) && is_array( $_POST['codir2me_auto_delete_selected_thumbnails'] ) ) {
			$sanitized_array = array_map( 'sanitize_text_field', wp_unslash( $_POST['codir2me_auto_delete_selected_thumbnails'] ) );
			foreach ( $sanitized_array as $size ) {
				$selected_thumbnails[] = $size;
			}
		}
		update_option( 'codir2me_auto_delete_selected_thumbnails', $selected_thumbnails );

		// Mostrar notificação.
		add_action(
			'admin_notices',
			function () use ( $auto_delete_enabled ) {
				?>
			<div class="notice notice-success is-dismissible">
				<p>
						<?php esc_html_e( 'Configurações de exclusão automática atualizadas com sucesso!', 'codirun-codir2me-cdn' ); ?> 
						<?php esc_html_e( 'A exclusão automática está agora', 'codirun-codir2me-cdn' ); ?> 
						<?php echo $auto_delete_enabled ? '<strong>' . esc_html__( 'ativada', 'codirun-codir2me-cdn' ) . '</strong>' : '<strong>' . esc_html__( 'desativada', 'codirun-codir2me-cdn' ) . '</strong>'; ?>.
				</p>
			</div>
				<?php
			}
		);
	}

	/**
	 * Obtém as estatísticas atuais de arquivos COM CORREÇÃO DE TIPO
	 *
	 * @since 1.0.0
	 * @return array Estatísticas atuais.
	 */
	private function codir2me_get_current_stats() {
		// Contar arquivos estáticos.
		$uploaded_files = get_option( 'codir2me_uploaded_files', array() );
		if ( ! is_array( $uploaded_files ) ) {
			$uploaded_files = array();
			update_option( 'codir2me_uploaded_files', $uploaded_files );
		}
		$total_static_files = count( $uploaded_files );

		// Contar imagens - CORREÇÃO: garantir que seja array.
		$uploaded_images = get_option( 'codir2me_uploaded_images', array() );
		if ( ! is_array( $uploaded_images ) ) {
			$uploaded_images = array();
			update_option( 'codir2me_uploaded_images', $uploaded_images );
		}
		$total_images = count( $uploaded_images );

		// Contar originais e miniaturas.
		$original_images    = 0;
		$thumbnails         = 0;
		$thumbnails_by_size = array();

		// Obter as miniaturas organizadas por tamanho.
		$uploaded_thumbnails_by_size = get_option( 'codir2me_uploaded_thumbnails_by_size', array() );
		if ( ! is_array( $uploaded_thumbnails_by_size ) ) {
			$uploaded_thumbnails_by_size = array();
			update_option( 'codir2me_uploaded_thumbnails_by_size', $uploaded_thumbnails_by_size );
		}

		if ( ! empty( $uploaded_thumbnails_by_size ) ) {
			$thumbnails_by_size = $uploaded_thumbnails_by_size;

			// Contar miniaturas.
			foreach ( $thumbnails_by_size as $size => $paths ) {
				if ( is_array( $paths ) ) {
					$thumbnails += count( $paths );
				}
			}

			// Contar originais.
			foreach ( $uploaded_images as $path ) {
				if ( ! is_string( $path ) ) {
					continue;
				}

				$filename     = basename( $path );
				$is_thumbnail = preg_match( '/-\d+x\d+\.[a-zA-Z]+$/', $filename ) ||
								preg_match( '/-[a-zA-Z_]+\.[a-zA-Z]+$/', $filename );

				if ( ! $is_thumbnail ) {
					++$original_images;
				}
			}
		} else {
			// Reconstruir dados a partir dos caminhos de arquivo.
			foreach ( $uploaded_images as $path ) {
				if ( ! is_string( $path ) ) {
					continue;
				}

				$filename     = basename( $path );
				$is_thumbnail = false;

				if ( preg_match( '/-(\d+x\d+)\.[a-zA-Z]+$/', $filename, $matches ) ) {
					$size         = $matches[1];
					$is_thumbnail = true;

					if ( ! isset( $thumbnails_by_size[ $size ] ) ) {
						$thumbnails_by_size[ $size ] = array();
					}

					$thumbnails_by_size[ $size ][] = $path;
					++$thumbnails;
				} elseif ( preg_match( '/-([a-zA-Z_]+)\.[a-zA-Z]+$/', $filename, $matches ) ) {
					$size         = $matches[1];
					$is_thumbnail = true;

					if ( ! isset( $thumbnails_by_size[ $size ] ) ) {
						$thumbnails_by_size[ $size ] = array();
					}

					$thumbnails_by_size[ $size ][] = $path;
					++$thumbnails;
				}

				if ( ! $is_thumbnail ) {
					++$original_images;
				}
			}

			// Atualizar opções para uso futuro.
			update_option( 'codir2me_uploaded_thumbnails_by_size', $thumbnails_by_size );
			update_option( 'codir2me_original_images_count', $original_images );
			update_option( 'codir2me_thumbnail_images_count', $thumbnails );
		}

		return array(
			'total_static_files' => $total_static_files,
			'total_images'       => $total_images,
			'original_images'    => $original_images,
			'thumbnails'         => $thumbnails,
			'thumbnails_by_size' => $thumbnails_by_size,
		);
	}

	/**
	 * Validar e corrigir opções corrompidas
	 *
	 * Adicione esta função na mesma classe para uso futuro
	 */
	private function codir2me_validate_and_fix_options() {
		// Lista de opções que devem ser arrays.
		$array_options = array(
			'codir2me_uploaded_files',
			'codir2me_uploaded_images',
			'codir2me_uploaded_thumbnails_by_size',
			'codir2me_items_to_delete',
			'codir2me_pending_images',
		);

		foreach ( $array_options as $option_name ) {
			$value = get_option( $option_name, array() );
			if ( ! is_array( $value ) ) {
				codir2me_cdn_log(
					sprintf( 'Corrigindo opção corrompida: %s (era %s)', $option_name, gettype( $value ) ),
					'warning'
				);
				update_option( $option_name, array() );
			}
		}

		// Lista de opções que devem ser números.
		$numeric_options = array(
			'codir2me_original_images_count'  => 0,
			'codir2me_thumbnail_images_count' => 0,
			'codir2me_missing_images_count'   => 0,
		);

		foreach ( $numeric_options as $option_name => $default ) {
			$value = get_option( $option_name, $default );
			if ( ! is_numeric( $value ) ) {
				codir2me_cdn_log(
					sprintf( 'Corrigindo contador corrompido: %s (era %s)', $option_name, gettype( $value ) ),
					'warning'
				);
				update_option( $option_name, $default );
			}
		}
	}

	/**
	 * Recalcula as estatísticas de miniaturas.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function codir2me_recalculate_thumbnail_stats() {
		$uploaded_images    = get_option( 'codir2me_uploaded_images', array() );
		$thumbnails_by_size = array();
		$original_count     = 0;
		$thumbnail_count    = 0;

		foreach ( $uploaded_images as $path ) {
			$filename     = basename( $path );
			$is_thumbnail = false;

			if ( preg_match( '/-(\d+x\d+)\.[a-zA-Z]+$/', $filename, $matches ) ) {
				$size         = $matches[1];
				$is_thumbnail = true;
				++$thumbnail_count;

				if ( ! isset( $thumbnails_by_size[ $size ] ) ) {
					$thumbnails_by_size[ $size ] = array();
				}

				$thumbnails_by_size[ $size ][] = $path;
			} elseif ( preg_match( '/-([a-zA-Z_]+)\.[a-zA-Z]+$/', $filename, $matches ) ) {
				$size         = $matches[1];
				$is_thumbnail = true;
				++$thumbnail_count;

				if ( ! isset( $thumbnails_by_size[ $size ] ) ) {
					$thumbnails_by_size[ $size ] = array();
				}

				$thumbnails_by_size[ $size ][] = $path;
			}

			if ( ! $is_thumbnail ) {
				++$original_count;
			}
		}

		update_option( 'codir2me_uploaded_thumbnails_by_size', $thumbnails_by_size );
		update_option( 'codir2me_original_images_count', $original_count );
		update_option( 'codir2me_thumbnail_images_count', $thumbnail_count );
	}
}
?>
