<?php
/**
 * Classe responsável por gerenciar o reprocessamento e reenvio de imagens para o R2.
 *
 * @package Codirun_R2_Media_Static_CDN
 */

// Evitar acesso direto ao arquivo.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classe CODIR2ME_Image_Reprocessor.
 *
 * Responsável por gerenciar o reprocessamento e reenvio de imagens para o R2.
 */
class CODIR2ME_Image_Reprocessor {
	/**
	 * Instância da classe de administração.
	 *
	 * @var object
	 */
	private $admin;

	/**
	 * Instância do uploader.
	 *
	 * @var object
	 */
	private $uploader;

	/**
	 * Instância do plugin principal.
	 *
	 * @var object
	 */
	private $plugin;

	/**
	 * Instância do processador em segundo plano.
	 *
	 * @var object
	 */
	private $background_processor;

	/**
	 * Registro de imagens processadas.
	 *
	 * @var array
	 */
	private $processed_images_registry;

	/**
	 * Arquivos enviados no lote atual.
	 *
	 * @var array
	 */
	private $batch_uploaded_files = array();

	/**
	 * Imagens processadas no lote atual.
	 *
	 * @var array
	 */
	private $current_batch_processed = array();

	/**
	 * Construtor.
	 *
	 * @param object $admin Instância da classe de administração.
	 */
	public function __construct( $admin ) {
		$this->admin  = $admin;
		$this->plugin = $admin->codir2me_get_plugin();

		// Inicializar opções padrão.
		$this->codir2me_initialize_default_options();

		// Inicializar o uploader.
		$this->uploader = $this->plugin->codir2me_get_uploader();

		// Inicializar o processador em segundo plano.
		require_once CODIR2ME_CDN_INCLUDES_DIR . 'class-codir2me-background-processor.php';
		$this->background_processor = new CODIR2ME_Background_Processor();

		// Adicionar hooks para AJAX.
		add_action( 'wp_ajax_codir2me_reprocess_image', array( $this, 'ajax_reprocess_image' ) );
		add_action( 'wp_ajax_codir2me_reprocess_all_images', array( $this, 'ajax_reprocess_all_images' ) );
		add_action( 'wp_ajax_codir2me_get_image_status', array( $this, 'ajax_get_image_status' ) );
		add_action( 'wp_ajax_codir2me_get_background_status', array( $this, 'codir2me_ajax_get_background_status' ) );
		add_action( 'wp_ajax_codir2me_get_selected_images_preview', array( $this, 'codir2me_ajax_get_selected_images_preview' ) );

		// Adicionar hooks para processamento em lotes.
		add_action( 'admin_post_codir2me_start_reprocessing', array( $this, 'codir2me_start_reprocessing' ) );
		add_action( 'admin_post_codir2me_start_background_reprocessing', array( $this, 'codir2me_start_background_reprocessing' ) );
		add_action( 'admin_post_codir2me_cancel_reprocessing', array( $this, 'codir2me_cancel_reprocessing' ) );
		add_action( 'admin_post_codir2me_pause_reprocessing', array( $this, 'codir2me_pause_reprocessing' ) );
		add_action( 'admin_post_codir2me_update_reprocessing_settings', array( $this, 'codir2me_update_reprocessing_settings' ) );
		add_action( 'admin_post_codir2me_process_manual_upload', array( $this, 'codir2me_process_manual_upload' ) );
		add_action( 'admin_post_codir2me_process_reprocessing_batch', array( $this, 'codir2me_process_reprocessing_batch' ) );
		add_action( 'wp_ajax_codir2me_get_local_thumbnail', array( $this, 'ajax_get_local_thumbnail' ) );

		// Hook para o processo em segundo plano.
		add_action( 'codir2me_background_reprocessing_event', array( $this, 'codir2me_process_background_batch' ) );

		// Adicionar intervalo personalizado para o cron.
		// add_filter( 'cron_schedules', array( $this, 'codir2me_add_custom_cron_interval' ) );.

		// Hook para notificações.
		add_action( 'admin_notices', array( $this, 'codir2me_reprocessing_notices' ) );
	}

	/**
	 * Inicializa o registro de imagens processadas.
	 */
	private function codir2me_init_processed_images_registry() {
		$this->processed_images_registry = get_option( 'codir2me_reprocessed_images_registry', array() );

		// Converter formato antigo se necessário.
		if ( ! is_array( $this->processed_images_registry ) ) {
			$this->processed_images_registry = array();
		}

		if ( function_exists( 'codir2me_cdn_log' ) ) {
			/* translators: %d: número total de registros processados */
			codir2me_cdn_log( sprintf( esc_html__( 'Registro de imagens processadas inicializado. Total de registros: %d', 'codirun-codir2me-cdn' ), count( $this->processed_images_registry ) ), 'info' );
		}
	}

	/**
	 * Marca uma imagem como processada no registro.
	 * Usado apenas para rastreamento e relatórios, não para decisões de pular.
	 *
	 * @param int    $image_id  ID da imagem.
	 * @param string $path      Caminho da imagem.
	 * @param bool   $with_webp Se foi criada versão WebP.
	 * @param bool   $with_avif Se foi criada versão AVIF.
	 */
	private function codir2me_mark_image_processed( $image_id, $path, $with_webp = false, $with_avif = false ) {
		// Inicializar registro se necessário.
		if ( ! isset( $this->processed_images_registry ) ) {
			$this->codir2me_init_processed_images_registry();
		}

		// Adicionar ao registro.
		$this->processed_images_registry[ $image_id ] = array(
			'path' => $path,
			'webp' => $with_webp,
			'avif' => $with_avif,
			'time' => time(),
		);

		// Adicionar ao registro de lote atual para evitar processamento duplicado.
		$this->current_batch_processed[ $image_id ] = true;

		// Salvar a cada 10 imagens para não sobrecarregar o banco de dados.
		if ( 0 === count( $this->processed_images_registry ) % 10 ) {
			update_option( 'codir2me_reprocessed_images_registry', $this->processed_images_registry );
		}
	}

	/**
	 * Verifica e valida nonce para processamento de formulários.
	 *
	 * @param string $action Action do nonce.
	 * @param string $name Nome do campo nonce.
	 * @return bool True se válido.
	 */
	private function codir2me_verify_nonce( $action, $name ) {
		return isset( $_POST[ $name ] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ $name ] ) ), $action );
	}

	/**
	 * Verifica e valida nonce para requisições AJAX.
	 *
	 * @param string $action Action do nonce.
	 * @param string $name Nome do campo nonce.
	 * @return bool True se válido.
	 */
	private function codir2me_verify_ajax_nonce( $action, $name ) {
		return isset( $_POST[ $name ] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ $name ] ) ), $action );
	}

	/**
	 * Obtém parâmetro GET de forma segura com verificação de nonce.
	 *
	 * @param string $key Chave do parâmetro.
	 * @param string $fallback_value Valor padrão.
	 * @return string Valor sanitizado.
	 */
	private function codir2me_get_safe_get_param( $key, $fallback_value = '' ) {
		// Primeiro, verificar se temos um nonce válido para requisições GET administrativas.
		$nonce_verified = false;

		// Verificar se existe um nonce na URL (para links administrativos).
		if ( isset( $_GET['_wpnonce'] ) ) {
			$nonce_verified = wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'codir2me_admin_nonce' );
		}

		// Para páginas administrativas básicas (como mudança de abas), permitir sem nonce.
		// mas apenas para parâmetros seguros conhecidos.
		$safe_params = array(
			'page',
			'tab',
			'section',
			'action',
			'error',
			'success',
			'settings_saved',
			'reprocessing_complete',
			'background_started',
			'reprocessing_paused',
			'reprocessing_canceled',
			'auto_continue',
		);

		$is_safe_param = in_array( $key, $safe_params, true );
		$is_admin_page = is_admin() && current_user_can( 'manage_options' );

		// Se não é um parâmetro seguro conhecido e não tem nonce, retornar fallback.
		if ( ! $is_safe_param && ! $nonce_verified ) {
			return $fallback_value;
		}

		// Se é uma página administrativa e um parâmetro seguro OU se o nonce foi verificado.
		if ( ( $is_admin_page && $is_safe_param ) || $nonce_verified ) {
			$value = filter_input( INPUT_GET, $key, FILTER_SANITIZE_FULL_SPECIAL_CHARS );

			// Se filter_input retornar null ou false, tentar $_GET como fallback.
			if ( null === $value || false === $value ) {
				$value = isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
			}

			return ! empty( $value ) ? $value : $fallback_value;
		}

		return $fallback_value;
	}

	/**
	 * Verifica se uma imagem já foi processada no lote atual.
	 * Esta função ajuda a prevenir o processamento duplicado durante a execução do lote.
	 *
	 * @param int $image_id ID da imagem.
	 * @return bool True se a imagem já foi processada no lote atual.
	 */
	private function codir2me_is_processed_in_current_batch( $image_id ) {
		return isset( $this->current_batch_processed[ $image_id ] ) && $this->current_batch_processed[ $image_id ];
	}

	/**
	 * Verifica se uma imagem já foi processada.
	 * Agora apenas usado para fins de relatório, não para decidir pular imagens.
	 *
	 * @param int  $image_id      ID da imagem.
	 * @param bool $require_webp  Se requer formato WebP.
	 * @param bool $require_avif  Se requer formato AVIF.
	 * @return bool True se a imagem foi processada.
	 */
	private function codir2me_is_image_processed( $image_id, $require_webp = false, $require_avif = false ) {
		// Para reprocessamento, sempre retornamos falso para forçar o reenvio.
		// Isso garante que nenhuma imagem seja pulada no modo de reprocessamento manual e normal.
		$is_manual_mode = isset( $GLOBALS['codir2me_reprocessing_manual_mode'] ) && $GLOBALS['codir2me_reprocessing_manual_mode'];

		if ( $is_manual_mode ) {
			return false; // Nunca pular imagens no modo manual.
		}

		// Verificar primeiro se está no lote atual para evitar duplicação.
		if ( $this->codir2me_is_processed_in_current_batch( $image_id ) ) {
			return true;
		}

		// Inicializar registro se necessário.
		if ( ! isset( $this->processed_images_registry ) ) {
			$this->codir2me_init_processed_images_registry();
		}

		// Verificar se a imagem está no registro.
		if ( ! isset( $this->processed_images_registry[ $image_id ] ) ) {
			return false;
		}

		// Se não requer formato específico, retornar true.
		if ( ! $require_webp && ! $require_avif ) {
			return true;
		}

		// Verificar requisitos de formato.
		$record = $this->processed_images_registry[ $image_id ];

		if ( $require_webp && ! $record['webp'] ) {
			return false;
		}

		if ( $require_avif && ! $record['avif'] ) {
			return false;
		}

		return true;
	}

	/**
	 * Salva o registro de imagens processadas.
	 */
	private function codir2me_save_processed_images_registry() {
		// Inicializar registro se necessário.
		if ( ! isset( $this->processed_images_registry ) ) {
			$this->codir2me_init_processed_images_registry();
		}

		update_option( 'codir2me_reprocessed_images_registry', $this->processed_images_registry );

		if ( function_exists( 'codir2me_cdn_log' ) ) {
			/* translators: %d: número total de registros processados */
			codir2me_cdn_log( sprintf( esc_html__( 'Registro de imagens processadas salvo. Total de registros: %d', 'codirun-codir2me-cdn' ), count( $this->processed_images_registry ) ), 'info' );
		}
	}

	/**
	 * Obtém o intervalo ideal para processamento em background.
	 * Baseado no tamanho do lote e configurações do sistema.
	 *
	 * @return string Nome do intervalo de cron.
	 */
	private function codir2me_get_optimal_cron_interval() {
		$batch_size   = get_option( 'codir2me_reprocessing_batch_size', 20 );
		$total_images = get_option( 'codir2me_reprocessing_image_ids', array() );
		$images_count = is_array( $total_images ) ? count( $total_images ) : 0;

		// Se há muitas imagens (>1000), usar intervalo maior para reduzir carga.
		if ( $images_count > 1000 || $batch_size > 30 ) {
			return 'codir2me_thirty_minutes';
		}

		// Para quantidade moderada (100-1000), usar 15 minutos.
		if ( $images_count > 100 ) {
			return 'codir2me_fifteen_minutes';
		}

		// Para poucos arquivos (<100), usar 5 minutos.
		return 'codir2me_five_minutes';
	}

	/**
	 * Inicia o reprocessamento em segundo plano.
	 */
	public function codir2me_start_background_reprocessing() {
		// Verificar nonce.
		check_admin_referer( 'codir2me_start_background_reprocessing', 'codir2me_start_background_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Acesso negado', 'codirun-codir2me-cdn' ) );
		}

		// Cancelar qualquer trabalho anterior em andamento.
		$this->codir2me_cleanup_previous_process();

		// Obter todas as imagens.
		$args = array(
			'post_type'      => 'attachment',
			'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ),
			'post_status'    => 'inherit',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		);

		$query        = new WP_Query( $args );
		$total_images = count( $query->posts );

		if ( 0 === $total_images ) {
			// Redirecionar com mensagem de erro.
			$redirect_url = add_query_arg(
				array(
					'page'     => 'codirun-codir2me-cdn-reprocess',
					'error'    => 'no_images',
					'_wpnonce' => wp_create_nonce( 'codir2me_admin_nonce' ),
				),
				admin_url( 'admin.php' )
			);
			wp_safe_redirect( $redirect_url );
			exit;
		}

		// Salvar lista de IDs para processamento.
		update_option( 'codir2me_reprocessing_image_ids', $query->posts );

		// Obter configurações.
		$batch_size = get_option( 'codir2me_reprocessing_batch_size', 20 );

		// Usar as configurações salvas em vez dos checkboxes.
		$force_webp = get_option( 'codir2me_force_webp', false );
		$force_avif = get_option( 'codir2me_force_avif', false );

		// Calcular total de lotes.
		$total_batches = ceil( $total_images / $batch_size );

		// Calcular tempo estimado total (15 minutos por lote).
		$estimated_total_minutes     = $total_batches * 15;
		$estimated_hours             = floor( $estimated_total_minutes / 60 );
		$estimated_minutes_remainder = $estimated_total_minutes % 60;

		// Definir delay para o primeiro lote (10 segundos por padrão).
		$first_batch_delay = apply_filters( 'codir2me_first_batch_delay', 10 );
		$first_batch_time  = time() + $first_batch_delay;

		// Inicializar status.
		$reprocessing_status = array(
			'in_progress'             => true,
			'background_mode'         => true,
			'paused'                  => false,
			'total_images'            => $total_images,
			'processed_images'        => 0,
			'total_batches'           => $total_batches,
			'current_batch'           => 0,
			'batch_size'              => $batch_size,
			'start_time'              => time(),
			'last_run'                => 0,
			'next_run'                => $first_batch_time,
			'first_batch_scheduled'   => $first_batch_time,
			'interval_minutes'        => 15,
			'estimated_total_minutes' => $estimated_total_minutes,
			'failed_images'           => array(),
			'processed_list'          => array(),
		);

		update_option( 'codir2me_reprocessing_status', $reprocessing_status );

		// Agendar o primeiro lote com delay.
		if ( ! wp_next_scheduled( 'codir2me_background_reprocessing_event' ) ) {
			wp_schedule_single_event( $first_batch_time, 'codir2me_background_reprocessing_event' );

			codir2me_cdn_log(
				sprintf(
					// translators: %s é a data/hora agendada do primeiro lote, %d é o atraso em segundos.
					esc_html__( 'Primeiro lote agendado para: %1$s (em %2$d segundos)', 'codirun-codir2me-cdn' ),
					wp_date( 'Y-m-d H:i:s', $first_batch_time ),
					$first_batch_delay
				),
				'info'
			);
		}

		// Log inicial.
		codir2me_cdn_log(
			sprintf(
				esc_html__( "=== PROCESSAMENTO EM SEGUNDO PLANO INICIADO ===\nTotal de imagens: %1\$d\nTotal de lotes: %2\$d\nTamanho do lote: %3\$d\nPrimeiro lote em: %4\$d segundos\nIntervalo entre lotes: 15 minutos\nTempo estimado total: %5\$s\nWebP: %6\$s | AVIF: %7\$s", 'codirun-codir2me-cdn' ),
				$total_images,
				$total_batches,
				$batch_size,
				$first_batch_delay,
				$estimated_hours > 0
					// translators: %d total de imagens, %d total de lotes, %d tamanho do lote, %d atraso do primeiro lote em segundos, %s tempo estimado total formatado, %s indica se WebP está ativado (SIM/NÃO), %s indica se AVIF está ativado (SIM/NÃO).
					? sprintf( esc_html__( '%1$dh %2$dmin', 'codirun-codir2me-cdn' ), $estimated_hours, $estimated_minutes_remainder ) : sprintf( esc_html__( '%d minutos', 'codirun-codir2me-cdn' ), $estimated_minutes_remainder ),
				$force_webp ? esc_html__( 'SIM', 'codirun-codir2me-cdn' ) : esc_html__( 'NÃO', 'codirun-codir2me-cdn' ),
				$force_avif ? esc_html__( 'SIM', 'codirun-codir2me-cdn' ) : esc_html__( 'NÃO', 'codirun-codir2me-cdn' )
			),
			'info'
		);

		// Redirecionar de volta para a página.
		$redirect_url = add_query_arg(
			array(
				'page'               => 'codirun-codir2me-cdn-reprocess',
				'background_started' => '1',
				'first_batch_delay'  => $first_batch_delay,
				'_wpnonce'           => wp_create_nonce( 'codir2me_admin_nonce' ),
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Processa um lote em segundo plano
	 */
	public function codir2me_process_background_batch() {
		$batch_start_time = microtime( true );

		// Limpar o registro de arquivos enviados no lote atual.
		$this->batch_uploaded_files = array();
		// Limpar o registro de imagens processadas no lote atual.
		$this->current_batch_processed = array();

		// Inicializar registro de imagens processadas.
		$this->codir2me_init_processed_images_registry();

		// Verificar status atual.
		$reprocessing_status = get_option( 'codir2me_reprocessing_status', array() );

		// Verificar se o processo ainda está em andamento e não está pausado.
		if ( ! isset( $reprocessing_status['in_progress'] ) ||
			! $reprocessing_status['in_progress'] ||
			( isset( $reprocessing_status['paused'] ) && $reprocessing_status['paused'] ) ) {

			codir2me_cdn_log( esc_html__( 'Processo de reprocessamento não está ativo - cancelando execução', 'codirun-codir2me-cdn' ), 'debug' );
			return;
		}

		// Registrar timestamp do último processamento e calcular próxima execução.
		$current_time         = time();
		$last_run             = isset( $reprocessing_status['last_run'] ) ? $reprocessing_status['last_run'] : $current_time;
		$current_batch_number = ( $reprocessing_status['current_batch'] ?? 0 ) + 1;

		// Determinar se é o primeiro lote.
		$is_first_batch = ( 1 === $current_batch_number );

		$reprocessing_status['last_run'] = $current_time;

		// Log do cronômetro.
		$time_since_last = $current_time - $last_run;

		if ( $is_first_batch ) {
			$start_time = isset( $reprocessing_status['start_time'] ) ? $reprocessing_status['start_time'] : $current_time;
			$delay_time = $current_time - $start_time;

			codir2me_cdn_log(
				sprintf(
				// translators: %d será substituído pelo tempo de atraso em segundos.
					esc_html__( "=== EXECUTANDO PRIMEIRO LOTE ===\nDelay desde o início: %d segundos\nExecução agendada funcionou corretamente", 'codirun-codir2me-cdn' ),
					$delay_time
				),
				'info'
			);
		} else {
			codir2me_cdn_log(
				sprintf(
					// translators: %d é o número do lote atual, %s é a data da última execução, %d é o tempo desde a última execução em segundos.
					esc_html__( "=== EXECUTANDO LOTE %1\$d ===\nÚltima execução: %2\$s (%3\$d segundos atrás)", 'codirun-codir2me-cdn' ),
					$current_batch_number,
					wp_date( 'Y-m-d H:i:s', $last_run ),
					$time_since_last
				),
				'info'
			);
		}

		// Obter IDs das imagens.
		$image_ids = get_option( 'codir2me_reprocessing_image_ids', array() );

		// Verificar se ainda há imagens para processar.
		if ( empty( $image_ids ) ) {
			codir2me_cdn_log( esc_html__( 'Nenhuma imagem restante para reprocessar - finalizando processo', 'codirun-codir2me-cdn' ), 'info' );

			// Processo concluído.
			$reprocessing_status['in_progress']  = false;
			$reprocessing_status['completed_at'] = $current_time;
			update_option( 'codir2me_reprocessing_status', $reprocessing_status );

			// Cancelar o evento agendado.
			$timestamp = wp_next_scheduled( 'codir2me_background_reprocessing_event' );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, 'codir2me_background_reprocessing_event' );
				codir2me_cdn_log( esc_html__( 'Evento cron cancelado - processo concluído', 'codirun-codir2me-cdn' ), 'debug' );
			}

			// Limpeza final.
			$this->codir2me_cleanup_temp_files();
			$this->codir2me_save_processed_images_registry();

			$total_time    = $current_time - $reprocessing_status['start_time'];
			$total_hours   = floor( $total_time / 3600 );
			$total_minutes = floor( ( $total_time % 3600 ) / 60 );

			codir2me_cdn_log(
				sprintf(
					// translators: %1$s é o tempo total formatado, %2$d é o número de imagens processadas, %3$d é o número de lotes executados.
					esc_html__( "=== PROCESSO DE REPROCESSAMENTO CONCLUÍDO ===\nTempo total: %1\$s\nImagens processadas: %2\$d\nLotes executados: %3\$d", 'codirun-codir2me-cdn' ),
					$total_hours > 0
						// translators: %1$d é o número de horas, %2$d é o número de minutos.
						? sprintf( esc_html__( '%1$d h %2$d min', 'codirun-codir2me-cdn' ), $total_hours, $total_minutes )
						// translators: %1$d é o número de minutos.
						: sprintf( esc_html__( '%1$d minutos', 'codirun-codir2me-cdn' ), $total_minutes ),
					$reprocessing_status['processed_images'],
					$reprocessing_status['current_batch']
				),
				'info'
			);
			return;
		}

		// Obter configurações.
		$batch_size = isset( $reprocessing_status['batch_size'] ) ? $reprocessing_status['batch_size'] : 20;
		$force_webp = get_option( 'codir2me_force_webp', false );
		$force_avif = get_option( 'codir2me_force_avif', false );

		$actual_batch_size        = min( $batch_size, count( $image_ids ) );
		$remaining_batches        = ceil( count( $image_ids ) / $batch_size );
		$estimated_remaining_time = $remaining_batches * 15; // 15 minutos por lote

		// Calcular próxima execução.
		$next_run                        = $current_time + 900; // +15 minutos
		$reprocessing_status['next_run'] = $next_run;

		codir2me_cdn_log(
			sprintf(
				// translators: %1$d lote atual, %2$d total de lotes, %3$d tamanho do lote, %4$d imagens restantes, %5$d lotes restantes, %6$d minutos restantes, %7$s próxima execução, %8$s WebP ativado, %9$s AVIF ativado, %10$s uso de memória atual, %11$d tempo limite do PHP.
				esc_html__( "=== INICIANDO LOTE DE REPROCESSAMENTO ===\n\tLote atual: %1\$d/%2\$d\n\tTamanho do lote: %3\$d imagens\n\tImagens restantes: %4\$d\n\tLotes restantes: %5\$d\n\tTempo estimado restante: %6\$d minutos\n\tPróxima execução: %7\$s (em 15 minutos)\n\tWebP: %8\$s | AVIF: %9\$s\n\tMemória atual: %10\$s\n\tTempo limite PHP: %11\$ds", 'codirun-codir2me-cdn' ),
				$current_batch_number,
				$reprocessing_status['total_batches'],
				$actual_batch_size,
				count( $image_ids ),
				$remaining_batches - 1, // -1 porque estamos contando o atual.
				$estimated_remaining_time,
				wp_date( 'Y-m-d H:i:s', $next_run ),
				$force_webp ? 'SIM' : 'NÃO',
				$force_avif ? 'SIM' : 'NÃO',
				size_format( memory_get_usage() ),
				ini_get( 'max_execution_time' )
			),
			'info'
		);

		// Obter lote atual.
		$batch = array_slice( $image_ids, 0, $batch_size );

		// Processar o lote.
		$processed      = 0;
		$errors         = 0;
		$failed_ids     = array();
		$processed_list = isset( $reprocessing_status['processed_list'] ) ? $reprocessing_status['processed_list'] : array();

		// Carregar otimizador com configurações personalizadas.
		$optimizer_setup_start = microtime( true );
		require_once CODIR2ME_CDN_INCLUDES_DIR . 'class-codir2me-image-optimizer.php';

		// Criar configurações personalizadas para o otimizador.
		$optimizer_options = array(
			'enable_optimization'    => true,
			'enable_webp_conversion' => $force_webp,
			'enable_avif_conversion' => $force_avif,
			'optimization_level'     => 'balanced',
			'keep_original'          => true,
		);

		// Criar instância do otimizador com as configurações personalizadas.
		$optimizer            = new CODIR2ME_Image_Optimizer( $optimizer_options );
		$uploader             = $this->plugin->codir2me_get_uploader();
		$optimizer_setup_time = ( microtime( true ) - $optimizer_setup_start ) * 1000;

		codir2me_cdn_log(
			sprintf(
				// translators: %.2f é o tempo de configuração do otimizador em milissegundos.
				esc_html__( 'Otimizador configurado em %.2fms', 'codirun-codir2me-cdn' ),
				$optimizer_setup_time
			),
			'debug'
		);

		// Processar cada imagem no lote.
		$processing_start = microtime( true );
		foreach ( $batch as $image_id ) {
			$image_start_time = microtime( true );

			try {
				// Verificar se a imagem já foi processada neste lote.
				if ( $this->codir2me_is_processed_in_current_batch( $image_id ) ) {
					codir2me_cdn_log(
						sprintf(
						// translators: %d é o ID da imagem que já foi processada.
							esc_html__( 'Imagem %d já processada neste lote, pulando.', 'codirun-codir2me-cdn' ),
							$image_id
						),
						'info'
					);
					continue;
				}

				// Obter o caminho do arquivo.
				$file_path = get_attached_file( $image_id );

				if ( ! file_exists( $file_path ) ) {
					codir2me_cdn_log(
						sprintf(
						// translators: %d é o ID da imagem que não foi encontrada.
							esc_html__( 'Imagem ID %d não encontrada - pulando', 'codirun-codir2me-cdn' ),
							$image_id
						),
						'warning'
					);
					$failed_ids[] = $image_id;
					++$errors;
					continue;
				}

				// Caminho relativo para o R2.
				$relative_path = codir2me_get_relative_path( $file_path );

				$image_size = filesize( $file_path );
				codir2me_cdn_log(
					sprintf(
						// translators: %d é o ID da imagem, %s é o nome do arquivo, %s é o tamanho da imagem formatado.
						esc_html__( 'Processando imagem ID %1$d (%2$s - %3$s)', 'codirun-codir2me-cdn' ),
						$image_id,
						basename( $file_path ),
						size_format( $image_size )
					),
					'debug'
				);

				// Usar o método existente que já funciona - baseado no código atual.
				$upload_start = microtime( true );

				// Reprocessar a imagem.
				$result = $optimizer->codir2me_reprocess_image(
					$file_path,
					$relative_path,
					$force_webp,
					$force_avif,
					false // Não salvar localmente.
				);

				$upload_result = false;

				// Fazer upload para o R2.
				if ( is_array( $result ) ) {
					// Fazer upload do arquivo original.
					$upload_result = $uploader->codir2me_upload_file( $result, $relative_path );

					if ( $upload_result ) {
						// Adicionar à lista de arquivos enviados neste lote.
						$this->batch_uploaded_files[] = $relative_path;

						// Atualizar a lista de imagens enviadas.
						$uploaded_images = get_option( 'codir2me_uploaded_images', array() );
						if ( ! in_array( $relative_path, $uploaded_images, true ) ) {
							$uploaded_images[] = $relative_path;
						}

						// Adicionar versões WebP e AVIF à lista se existirem.
						$webp_created = false;
						$avif_created = false;

						if ( $force_webp && isset( $result['webp_relative_path'] ) ) {
							$webp_upload = $uploader->codir2me_upload_file( $result, $result['webp_relative_path'] );

							if ( $webp_upload && ! in_array( $result['webp_relative_path'], $uploaded_images, true ) ) {
								$uploaded_images[] = $result['webp_relative_path'];
							}
							$this->batch_uploaded_files[] = $result['webp_relative_path'];
							$webp_created                 = true;
						}

						if ( $force_avif && isset( $result['avif_relative_path'] ) ) {
							$avif_upload = $uploader->codir2me_upload_file( $result, $result['avif_relative_path'] );

							if ( $avif_upload && ! in_array( $result['avif_relative_path'], $uploaded_images, true ) ) {
								$uploaded_images[] = $result['avif_relative_path'];
							}
							$this->batch_uploaded_files[] = $result['avif_relative_path'];
							$avif_created                 = true;
						}

						update_option( 'codir2me_uploaded_images', $uploaded_images );

						// Marcar como processada no registro persistente.
						$this->codir2me_mark_image_processed( $image_id, $relative_path, $webp_created, $avif_created );

						// Adicionar à lista de processados no lote atual.
						$this->current_batch_processed[] = $image_id;

						// Processar miniaturas se a opção estiver ativada.
						$thumbnail_results = $this->codir2me_reprocess_thumbnails( $image_id, $optimizer, $uploader, $force_webp, $force_avif );

						if ( function_exists( 'codir2me_cdn_log' ) ) {
							codir2me_cdn_log(
								sprintf(
									// translators: %1$d miniaturas processadas, %2$d com erro, %3$d puladas.
									esc_html__( 'Miniaturas processadas: %1$d processadas, %2$d erros, %3$d puladas', 'codirun-codir2me-cdn' ),
									$thumbnail_results['processed'],
									$thumbnail_results['errors'],
									$thumbnail_results['skipped']
								),
								'info'
							);
						}
					}
				}

				$upload_time = ( microtime( true ) - $upload_start ) * 1000;

				if ( $upload_result ) {
					++$processed;
					$processed_list[] = $image_id;

					$image_total_time = ( microtime( true ) - $image_start_time ) * 1000;

					codir2me_cdn_log(
						sprintf(
							// translators: %d é o ID da imagem, %.2f é o tempo total de processamento em milissegundos, %.2f é o tempo de upload em milissegundos.
							esc_html__( '✓ Imagem ID %1$d processada em %2$.2fms (upload: %3$.2fms)', 'codirun-codir2me-cdn' ),
							$image_id,
							$image_total_time,
							$upload_time
						),
						'debug'
					);
				} else {
					++$errors;
					$failed_ids[]     = $image_id;
					$image_total_time = ( microtime( true ) - $image_start_time ) * 1000;

					codir2me_cdn_log(
						sprintf(
							// translators: %d é o ID da imagem, %.2f é o tempo total de processamento em milissegundos, %s é o tipo do resultado retornado.
							esc_html__( '✗ Falha ao processar imagem ID %1$d em %2$.2fms (resultado: %3$s)', 'codirun-codir2me-cdn' ),
							$image_id,
							$image_total_time,
							is_array( $result ) ? 'array' : gettype( $result )
						),
						'warning'
					);
				}
			} catch ( Exception $e ) {
				++$errors;
				$failed_ids[]     = $image_id;
				$image_total_time = ( microtime( true ) - $image_start_time ) * 1000;

				codir2me_cdn_log(
					sprintf(
						// translators: %d é o ID da imagem, %.2f é o tempo total de processamento em milissegundos, %s é a mensagem da exceção.
						esc_html__( '✗ ERRO ao processar imagem ID %1$d em %2$.2fms: %3$s', 'codirun-codir2me-cdn' ),
						$image_id,
						$image_total_time,
						$e->getMessage()
					),
					'error'
				);
			}
		}

		$processing_time = ( microtime( true ) - $processing_start ) * 1000;

		// Atualizar status.
		$status_update_start                      = microtime( true );
		$reprocessing_status['processed_images'] += $processed;
		++$reprocessing_status['current_batch'];
		$reprocessing_status['processed_list'] = $processed_list;

		// Adicionar IDs que falharam.
		if ( ! empty( $failed_ids ) ) {
			if ( ! isset( $reprocessing_status['failed_images'] ) ) {
				$reprocessing_status['failed_images'] = array();
			}
			$reprocessing_status['failed_images'] = array_merge( $reprocessing_status['failed_images'], $failed_ids );
		}

		// Remover imagens processadas da lista.
		$remaining_ids = array_slice( $image_ids, count( $batch ) );
		update_option( 'codir2me_reprocessing_image_ids', $remaining_ids );

		codir2me_cdn_log(
			sprintf(
				// translators: %d é o número de imagens processadas no lote, %d é o número de imagens restantes na fila.
				esc_html__( 'Lote processado: %1$d imagens. Restam: %2$d imagens na fila', 'codirun-codir2me-cdn' ),
				count( $batch ),
				count( $remaining_ids )
			),
			'info'
		);

		// Verificar se o processo está concluído.
		if ( empty( $remaining_ids ) ) {
			$reprocessing_status['in_progress']  = false;
			$reprocessing_status['completed_at'] = $current_time;
			update_option( 'codir2me_reprocessing_status', $reprocessing_status );

			// Cancelar o evento agendado.
			$timestamp = wp_next_scheduled( 'codir2me_background_reprocessing_event' );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, 'codir2me_background_reprocessing_event' );
				codir2me_cdn_log( esc_html__( 'Evento cron cancelado - processo concluído', 'codirun-codir2me-cdn' ), 'debug' );
			}

			// Limpeza final.
			$this->codir2me_cleanup_temp_files();
			$this->codir2me_save_processed_images_registry();

			codir2me_cdn_log( esc_html__( 'Processo de reprocessamento em segundo plano concluído', 'codirun-codir2me-cdn' ), 'info' );
		} else {
			// Agendar próximo lote para 15 minutos.
			if ( ! wp_next_scheduled( 'codir2me_background_reprocessing_event' ) ) {
				wp_schedule_single_event( $next_run, 'codir2me_background_reprocessing_event' );
			}

			update_option( 'codir2me_reprocessing_status', $reprocessing_status );
		}

		$status_update_time          = ( microtime( true ) - $status_update_start ) * 1000;
		$total_batch_time            = ( microtime( true ) - $batch_start_time ) * 1000;
		$images_per_second           = $processed > 0 ? ( $processed / ( $total_batch_time / 1000 ) ) : 0;
		$remaining_count             = count( $remaining_ids );
		$estimated_remaining_batches = ceil( $remaining_count / $batch_size );
		$estimated_remaining_minutes = $estimated_remaining_batches * 15;

		codir2me_cdn_log(
			sprintf(
				// translators: %d e %.2f representam contagens e tempos em milissegundos, %s é data/hora formatada, e %f porcentagem.
				esc_html__( "=== LOTE DE REPROCESSAMENTO CONCLUÍDO ===\nImagens processadas: %1\$d/%2\$d\nSucessos: %3\$d | Erros: %4\$d\nTempo processamento: %5\$.2fms\nTempo total do lote: %6\$.2fms\nVelocidade: %7\$.1f imagens/segundo\nAtualização BD: %8\$.2fms\nProgresso: %9\$d/%10\$d (%11\$.1f%%)\nRestam: %12\$d imagens (%13\$d lotes)\nPróxima execução: %14\$s (em 15 minutos)\nTempo estimado restante: %15\$d minutos\nMemória pico: %16\$s", 'codirun-codir2me-cdn' ),
				count( $batch ),
				$actual_batch_size,
				$processed,
				$errors,
				$processing_time,
				$total_batch_time,
				$images_per_second,
				$status_update_time,
				$reprocessing_status['processed_images'],
				$reprocessing_status['total_images'],
				$reprocessing_status['total_images'] > 0 ? ( $reprocessing_status['processed_images'] / $reprocessing_status['total_images'] * 100 ) : 0,
				$remaining_count,
				$estimated_remaining_batches,
				wp_date( 'Y-m-d H:i:s', $next_run ),
				$estimated_remaining_minutes,
				size_format( memory_get_peak_usage() )
			),
			'info'
		);
	}


	/**
	 * Adiciona intervalo personalizado para o cron - apenas 15 minutos.
	 *
	 * @param array $schedules Array de intervalos de cron.
	 * @return array Array modificado de intervalos de cron.
	 */
	public function codir2me_add_custom_cron_interval( $schedules ) {
		// Intervalo fixo de 15 minutos.
		$schedules['codir2me_fifteen_minutes'] = array(
			'interval' => 900, // 15 minutos (15 * 60 segundos).
			'display'  => esc_html__( 'A cada 15 minutos', 'codirun-codir2me-cdn' ),
		);

		codir2me_cdn_log( esc_html__( 'Intervalo de cron de 15 minutos registrado', 'codirun-codir2me-cdn' ), 'debug' );

		return $schedules;
	}

	/**
	 * Limpa qualquer processo anterior em andamento.
	 */
	private function codir2me_cleanup_previous_process() {
		// Verificar se há um processo em andamento.
		$reprocessing_status = get_option( 'codir2me_reprocessing_status', array() );

		if ( isset( $reprocessing_status['in_progress'] ) && $reprocessing_status['in_progress'] ) {
			// Backup do estado atual para possível recuperação.
			update_option( 'codir2me_previous_reprocessing_status', $reprocessing_status );
			update_option( 'codir2me_previous_reprocessing_image_ids', get_option( 'codir2me_reprocessing_image_ids', array() ) );

			// Cancelar TODOS os eventos agendados possíveis.
			$events_to_cancel = array(
				'codir2me_background_reprocessing_event',
				'codir2me_background_processing',
				'codir2me_process_batch',
			);

			foreach ( $events_to_cancel as $event_name ) {
				// Cancelar múltiplas instâncias do mesmo evento.
				$timestamp = wp_next_scheduled( $event_name );
				while ( $timestamp ) {
					wp_unschedule_event( $timestamp, $event_name );
					codir2me_cdn_log(
						sprintf(
							// translators: %s é o nome do evento cancelado, %d é o timestamp do evento.
							esc_html__( 'Evento cancelado: %1$s (timestamp: %2$d)', 'codirun-codir2me-cdn' ),
							$event_name,
							$timestamp
						),
						'debug'
					);
					$timestamp = wp_next_scheduled( $event_name );
				}
			}
			codir2me_cdn_log( esc_html__( 'Processo anterior cancelado e backup criado', 'codirun-codir2me-cdn' ), 'info' );
		}

		// Limpar dados antigos.
		delete_option( 'codir2me_reprocessing_image_ids' );

		// Reset do status.
		$clean_status = array(
			'in_progress'      => false,
			'background_mode'  => false,
			'paused'           => false,
			'total_images'     => 0,
			'processed_images' => 0,
			'total_batches'    => 0,
			'current_batch'    => 0,
			'batch_size'       => 20,
			'start_time'       => 0,
			'last_run'         => 0,
			'failed_images'    => array(),
			'processed_list'   => array(),
		);

		update_option( 'codir2me_reprocessing_status', $clean_status );

		codir2me_cdn_log( esc_html__( 'Limpeza de processo anterior concluída', 'codirun-codir2me-cdn' ), 'debug' );
	}

	/**
	 * Inicia o reprocessamento de imagens (modo normal).
	 */
	public function codir2me_start_reprocessing() {
		// Verificar nonce.
		check_admin_referer( 'codir2me_start_reprocessing', 'codir2me_start_reprocessing_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Acesso negado', 'codirun-codir2me-cdn' ) );
		}

		// Cancelar qualquer trabalho anterior em andamento.
		$this->codir2me_cleanup_previous_process();

		// Obter todas as imagens.
		$args = array(
			'post_type'      => 'attachment',
			'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ),
			'post_status'    => 'inherit',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		);

		$query        = new WP_Query( $args );
		$total_images = count( $query->posts );

		if ( empty( $query->posts ) ) {
			$redirect_url = add_query_arg(
				array(
					'page'     => 'codirun-codir2me-cdn-reprocess',
					'error'    => 'no_images',
					'_wpnonce' => wp_create_nonce( 'codir2me_admin_nonce' ),
				),
				admin_url( 'admin.php' )
			);
			wp_safe_redirect( $redirect_url );
			exit;
		}

		// Salvar lista de IDs para processamento.
		update_option( 'codir2me_reprocessing_image_ids', $query->posts );

		// Obter configurações.
		$batch_size = get_option( 'codir2me_reprocessing_batch_size', 20 );

		// Usar as configurações salvas em vez dos checkboxes.
		$force_webp = get_option( 'codir2me_force_webp', false );
		$force_avif = get_option( 'codir2me_force_avif', false );

		// Calcular total de lotes.
		$total_batches = ceil( $total_images / $batch_size );

		// Inicializar status.
		$reprocessing_status = array(
			'in_progress'      => true,
			'background_mode'  => false,
			'paused'           => false,
			'total_images'     => $total_images,
			'processed_images' => 0,
			'total_batches'    => $total_batches,
			'current_batch'    => 0,
			'batch_size'       => $batch_size,
			'start_time'       => time(),
			'last_run'         => time(),
			'failed_images'    => array(),
			'processed_list'   => array(),
		);

		update_option( 'codir2me_reprocessing_status', $reprocessing_status );

		// Registrar no log para depuração.
		if ( function_exists( 'codir2me_cdn_log' ) ) {
			/* translators: %1$d: número total de imagens; %2$d: número total de lotes */
			codir2me_cdn_log( sprintf( esc_html__( 'Iniciando reprocessamento de imagens no modo normal. Total de imagens: %1$d, Lotes: %2$d', 'codirun-codir2me-cdn' ), $total_images, $total_batches ), 'info' );
		}

		// Redirecionar para iniciar processamento.
		$redirect_url = add_query_arg(
			array(
				'page'          => 'codirun-codir2me-cdn-reprocess',
				'auto_continue' => '1',
				'_wpnonce'      => wp_create_nonce( 'codir2me_admin_nonce' ),
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Processa um lote no reprocessamento de imagens.
	 */
	public function codir2me_process_reprocessing_batch() {
		// Verificar flag de processamento.
		static $is_processing = false;
		if ( $is_processing ) {
			if ( function_exists( 'codir2me_cdn_log' ) ) {
				codir2me_cdn_log( esc_html__( 'Evitando processamento duplicado', 'codirun-codir2me-cdn' ), 'info' );
			}
			$redirect_url = add_query_arg(
				array(
					'page'     => 'codirun-codir2me-cdn-reprocess',
					'_wpnonce' => wp_create_nonce( 'codir2me_admin_nonce' ),
				),
				admin_url( 'admin.php' )
			);
			wp_safe_redirect( $redirect_url );
			exit;
		}
		$is_processing = true;

		// Verificar nonce.
		check_admin_referer( 'codir2me_process_reprocessing_batch', 'codir2me_reprocessing_batch_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Acesso negado', 'codirun-codir2me-cdn' ) );
		}

		// Verificar auto_continue tanto em GET quanto em POST.
		$is_auto_continue = ( isset( $_GET['auto_continue'] ) && '1' === $_GET['auto_continue'] ) ||
						( isset( $_POST['auto_continue'] ) && '1' === $_POST['auto_continue'] );

		// Se NÃO é auto_continue = primeira chamada (clique do botão).
		if ( ! $is_auto_continue ) {
			// Verificar e remover status pausado.
			$reprocessing_status = get_option( 'codir2me_reprocessing_status', array() );

			if ( isset( $reprocessing_status['paused'] ) && $reprocessing_status['paused'] ) {
				$reprocessing_status['paused'] = false;
				unset( $reprocessing_status['paused_at_time'] );
				update_option( 'codir2me_reprocessing_status', $reprocessing_status );

				if ( function_exists( 'codir2me_cdn_log' ) ) {
					codir2me_cdn_log( esc_html__( 'Reprocessamento retomado pelo usuário', 'codirun-codir2me-cdn' ), 'info' );

					// Reagendar cron se necessário.
					codir2me_cdn_log( esc_html__( 'Verificando se há evento de cron agendado...', 'codirun-codir2me-cdn' ), 'debug' );

					if ( ! wp_next_scheduled( 'codir2me_background_reprocessing_event' ) ) {
						codir2me_cdn_log( esc_html__( 'Nenhum evento de cron agendado, agendando novo evento', 'codirun-codir2me-cdn' ), 'debug' );

						// Agendar novo evento para 60 segundos.
						$next_run = time() + 60;
						wp_schedule_single_event( $next_run, 'codir2me_background_reprocessing_event' );

						codir2me_cdn_log(
							sprintf(
								// translators: %s será substituído pela data e hora do novo evento agendado.
								esc_html__( 'Novo evento agendado para %s (60 segundos)', 'codirun-codir2me-cdn' ),
								wp_date( 'Y-m-d H:i:s', $next_run )
							),
							'info'
						);
					}
				}
			}

			// Preparar redirecionamento com auto_continue=1.
			$redirect_url = add_query_arg(
				array(
					'page'          => 'codirun-codir2me-cdn-reprocess',
					'auto_continue' => '1',
					'_wpnonce'      => wp_create_nonce( 'codir2me_admin_nonce' ),
				),
				admin_url( 'admin.php' )
			);

			$is_processing = false;
			wp_safe_redirect( $redirect_url );
			exit;
		}

		// Verificar se há imagens para processar.
		$image_ids           = get_option( 'codir2me_reprocessing_image_ids', array() );
		$reprocessing_status = get_option( 'codir2me_reprocessing_status', array() );

		// Se não há imagens, finalizar.
		if ( empty( $image_ids ) ) {
			$reprocessing_status['in_progress']  = false;
			$reprocessing_status['completed_at'] = time();
			update_option( 'codir2me_reprocessing_status', $reprocessing_status );
			delete_option( 'codir2me_reprocessing_image_ids' );

			$redirect_url = add_query_arg(
				array(
					'page'      => 'codirun-codir2me-cdn-reprocess',
					'completed' => '1',
					'_wpnonce'  => wp_create_nonce( 'codir2me_admin_nonce' ),
				),
				admin_url( 'admin.php' )
			);

			$is_processing = false;
			wp_safe_redirect( $redirect_url );
			exit;
		}

		// Processar lote.
		$batch_size = isset( $reprocessing_status['batch_size'] ) ? $reprocessing_status['batch_size'] : 20;
		$batch      = array_slice( $image_ids, 0, $batch_size );

		$processed      = 0;
		$errors         = 0;
		$failed_ids     = array();
		$processed_list = array();

		// Obter configurações de otimização.
		$force_webp = get_option( 'codir2me_force_webp', false );
		$force_avif = get_option( 'codir2me_force_avif', false );

		// Processar cada imagem do lote.
		foreach ( $batch as $image_id ) {
			$image_start_time = microtime( true );

			try {
				// Verificar se a imagem já foi processada neste lote.
				if ( $this->codir2me_is_processed_in_current_batch( $image_id ) ) {
					continue;
				}

				// Obter o caminho do arquivo.
				$file_path = get_attached_file( $image_id );

				if ( ! file_exists( $file_path ) ) {
					$failed_ids[] = $image_id;
					++$errors;
					continue;
				}

				// Caminho relativo para o R2.
				$relative_path = codir2me_get_relative_path( $file_path );

				// Carregar otimizador com configurações personalizadas.
				require_once CODIR2ME_CDN_INCLUDES_DIR . 'class-codir2me-image-optimizer.php';

				// Criar configurações personalizadas para o otimizador.
				$optimizer_options = array(
					'enable_optimization'    => true,
					'enable_webp_conversion' => $force_webp,
					'enable_avif_conversion' => $force_avif,
					'optimization_level'     => 'balanced',
					'keep_original'          => true,
				);

				// Criar instância do otimizador com as configurações personalizadas.
				$optimizer = new CODIR2ME_Image_Optimizer( $optimizer_options );
				$uploader  = $this->plugin->codir2me_get_uploader();

				$upload_start = microtime( true );

				// Reprocessar a imagem.
				$result = $optimizer->codir2me_reprocess_image(
					$file_path,
					$relative_path,
					$force_webp,
					$force_avif,
					false // Não salvar localmente.
				);

				$upload_result = false;

				// Fazer upload para o R2.
				if ( is_array( $result ) ) {
					// Fazer upload do arquivo original.
					$upload_result = $uploader->codir2me_upload_file( $result, $relative_path );

					if ( $upload_result ) {
						// Adicionar à lista de arquivos enviados neste lote.
						$this->batch_uploaded_files[] = $relative_path;

						// Atualizar a lista de imagens enviadas.
						$uploaded_images = get_option( 'codir2me_uploaded_images', array() );
						if ( ! in_array( $relative_path, $uploaded_images, true ) ) {
							$uploaded_images[] = $relative_path;
						}

						// Adicionar versões WebP e AVIF à lista se existirem.
						$webp_created = false;
						$avif_created = false;

						if ( $force_webp && isset( $result['webp_relative_path'] ) ) {
							$webp_upload = $uploader->codir2me_upload_file( $result, $result['webp_relative_path'] );

							if ( $webp_upload && ! in_array( $result['webp_relative_path'], $uploaded_images, true ) ) {
								$uploaded_images[] = $result['webp_relative_path'];
							}
							$this->batch_uploaded_files[] = $result['webp_relative_path'];
							$webp_created                 = true;
						}

						if ( $force_avif && isset( $result['avif_relative_path'] ) ) {
							$avif_upload = $uploader->codir2me_upload_file( $result, $result['avif_relative_path'] );

							if ( $avif_upload && ! in_array( $result['avif_relative_path'], $uploaded_images, true ) ) {
								$uploaded_images[] = $result['avif_relative_path'];
							}
							$this->batch_uploaded_files[] = $result['avif_relative_path'];
							$avif_created                 = true;
						}

						update_option( 'codir2me_uploaded_images', $uploaded_images );

						// Marcar como processada no registro persistente.
						$this->codir2me_mark_image_processed( $image_id, $relative_path, $webp_created, $avif_created );

						// Adicionar à lista de processados no lote atual.
						$this->current_batch_processed[] = $image_id;

						// Processar miniaturas se a opção estiver ativada.
						$thumbnail_results = $this->codir2me_reprocess_thumbnails( $image_id, $optimizer, $uploader, $force_webp, $force_avif );

						if ( function_exists( 'codir2me_cdn_log' ) ) {
							codir2me_cdn_log(
								sprintf(
									// translators: %1$d miniaturas processadas, %2$d com erro, %3$d puladas.
									esc_html__( 'Miniaturas processadas: %1$d processadas, %2$d erros, %3$d puladas', 'codirun-codir2me-cdn' ),
									$thumbnail_results['processed'],
									$thumbnail_results['errors'],
									$thumbnail_results['skipped']
								),
								'info'
							);
						}
					}
				}

				if ( $upload_result ) {
					++$processed;
					$processed_list[] = $image_id;
				} else {
					++$errors;
					$failed_ids[] = $image_id;
				}
			} catch ( Exception $e ) {
				++$errors;
				$failed_ids[] = $image_id;
				if ( function_exists( 'codir2me_cdn_log' ) ) {
					codir2me_cdn_log(
						sprintf(
							// translators: %d é o ID da imagem, %s é a mensagem de erro.
							esc_html__( 'Erro ao processar imagem ID %1$d: %2$s', 'codirun-codir2me-cdn' ),
							$image_id,
							$e->getMessage()
						),
						'error'
					);
				}
			}
		}

		// Atualizar status.
		$reprocessing_status['processed_images'] = ( $reprocessing_status['processed_images'] ?? 0 ) + $processed;
		$reprocessing_status['current_batch']    = ( $reprocessing_status['current_batch'] ?? 0 ) + 1;
		$reprocessing_status['processed_list']   = isset( $reprocessing_status['processed_list'] ) ? $reprocessing_status['processed_list'] : array();
		$reprocessing_status['processed_list']   = array_merge( $reprocessing_status['processed_list'], $processed_list );

		// Adicionar IDs que falharam.
		if ( ! empty( $failed_ids ) ) {
			if ( ! isset( $reprocessing_status['failed_images'] ) ) {
				$reprocessing_status['failed_images'] = array();
			}
			$reprocessing_status['failed_images'] = array_merge( $reprocessing_status['failed_images'], $failed_ids );
		}

		update_option( 'codir2me_reprocessing_status', $reprocessing_status );

		// Remover imagens processadas da fila.
		$remaining_ids = array_slice( $image_ids, count( $batch ) );
		update_option( 'codir2me_reprocessing_image_ids', $remaining_ids );

		// Log do progresso.
		if ( function_exists( 'codir2me_cdn_log' ) ) {

			codir2me_cdn_log(
				sprintf(
					// translators: %d é o número de sucessos, %d é o número de erros, %d é a quantidade de imagens restantes.
					esc_html__( 'Lote processado: %1$d sucessos, %2$d erros. Restam: %3$d imagens', 'codirun-codir2me-cdn' ),
					$processed,
					$errors,
					count( $remaining_ids )
				),
				'info'
			);
		}

		// Verificar se há mais imagens.
		if ( ! empty( $remaining_ids ) ) {
			// Há mais imagens, redirecionar para continuar.
			$redirect_url = add_query_arg(
				array(
					'page'          => 'codirun-codir2me-cdn-reprocess',
					'auto_continue' => '1',
					'_wpnonce'      => wp_create_nonce( 'codir2me_admin_nonce' ),
				),
				admin_url( 'admin.php' )
			);

			$is_processing = false;
			wp_safe_redirect( $redirect_url );
			exit;
		} else {
			// Processo concluído.
			$reprocessing_status['in_progress']  = false;
			$reprocessing_status['completed_at'] = time();
			update_option( 'codir2me_reprocessing_status', $reprocessing_status );
			delete_option( 'codir2me_reprocessing_image_ids' );

			if ( function_exists( 'codir2me_cdn_log' ) ) {
				codir2me_cdn_log( esc_html__( 'Processo de reprocessamento concluído com sucesso', 'codirun-codir2me-cdn' ), 'info' );
			}

			$redirect_url = add_query_arg(
				array(
					'page'      => 'codirun-codir2me-cdn-reprocess',
					'completed' => '1',
					'_wpnonce'  => wp_create_nonce( 'codir2me_admin_nonce' ),
				),
				admin_url( 'admin.php' )
			);

			$is_processing = false;
			wp_safe_redirect( $redirect_url );
			exit;
		}
	}

	/**
	 * Pausa o processo de reprocessamento.
	 */
	public function codir2me_pause_reprocessing() {
		// Verificar nonce.
		check_admin_referer( 'codir2me_pause_reprocessing', 'codir2me_pause_reprocessing_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Acesso negado', 'codirun-codir2me-cdn' ) );
		}

		// Obter status atual.
		$reprocessing_status = get_option( 'codir2me_reprocessing_status', array() );

		// Verificar se o processo ainda está em andamento.
		if ( ! isset( $reprocessing_status['in_progress'] ) || ! $reprocessing_status['in_progress'] ) {
			$redirect_url = add_query_arg(
				array(
					'page'     => 'codirun-codir2me-cdn-reprocess',
					'error'    => 'not_in_progress',
					'_wpnonce' => wp_create_nonce( 'codir2me_admin_nonce' ),
				),
				admin_url( 'admin.php' )
			);
			wp_safe_redirect( $redirect_url );
			exit;
		}

		// Marcar como pausado.
		$reprocessing_status['paused']         = true;
		$reprocessing_status['paused_at_time'] = time();
		update_option( 'codir2me_reprocessing_status', $reprocessing_status );

		// Se estiver em modo background, desagendar o evento.
		if ( isset( $reprocessing_status['background_mode'] ) && $reprocessing_status['background_mode'] ) {
			$timestamp = wp_next_scheduled( 'codir2me_background_reprocessing_event' );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, 'codir2me_background_reprocessing_event' );
			}
		}

		// Salvar o registro de imagens processadas.
		$this->codir2me_save_processed_images_registry();

		// Registrar ação no log.
		if ( function_exists( 'codir2me_cdn_log' ) ) {
			codir2me_cdn_log( esc_html__( 'Reprocessamento pausado pelo usuário', 'codirun-codir2me-cdn' ), 'info' );
		}

		// Limpar arquivos temporários.
		$this->codir2me_cleanup_temp_files();

		// Redirecionar.
		$redirect_url = add_query_arg(
			array(
				'page'                => 'codirun-codir2me-cdn-reprocess',
				'reprocessing_paused' => '1',
				'_wpnonce'            => wp_create_nonce( 'codir2me_admin_nonce' ),
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Cancela o processo de reprocessamento.
	 */
	public function codir2me_cancel_reprocessing() {
		// Verificar nonce.
		check_admin_referer( 'codir2me_cancel_reprocessing', 'codir2me_cancel_reprocessing_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Acesso negado', 'codirun-codir2me-cdn' ) );
		}

		// Limpar dados de reprocessamento.
		delete_option( 'codir2me_reprocessing_image_ids' );

		// Atualizar status.
		$reprocessing_status = get_option( 'codir2me_reprocessing_status', array() );

		// Registrar no log.
		if ( function_exists( 'codir2me_cdn_log' ) ) {
			$processed_count = isset( $reprocessing_status['processed_images'] ) ? $reprocessing_status['processed_images'] : 0;
			/* translators: %d: número de imagens processadas antes do cancelamento */
			codir2me_cdn_log( sprintf( esc_html__( 'Processo de reprocessamento cancelado pelo usuário após processar %d imagens', 'codirun-codir2me-cdn' ), $processed_count ), 'info' );
		}

		// Manter o registro persistente intacto, apenas marcar o status como finalizado.
		$reprocessing_status['in_progress']      = false;
		$reprocessing_status['paused']           = false;
		$reprocessing_status['ended_at']         = time();
		$reprocessing_status['canceled_by_user'] = true;
		update_option( 'codir2me_reprocessing_status', $reprocessing_status );

		// Cancelar o evento agendado se estiver em modo background.
		if ( isset( $reprocessing_status['background_mode'] ) && $reprocessing_status['background_mode'] ) {
			$timestamp = wp_next_scheduled( 'codir2me_background_reprocessing_event' );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, 'codir2me_background_reprocessing_event' );
			}
		}

		// Redirecionar.
		$redirect_url = add_query_arg(
			array(
				'page'                  => 'codirun-codir2me-cdn-reprocess',
				'reprocessing_canceled' => '1',
				'_wpnonce'              => wp_create_nonce( 'codir2me_admin_nonce' ),
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Processa o upload manual de imagens selecionadas.
	 */
	public function codir2me_process_manual_upload() {
		// Verificar nonce.
		check_admin_referer( 'codir2me_process_manual_upload', 'codir2me_manual_upload_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Acesso negado', 'codirun-codir2me-cdn' ) );
		}

		// Verificar se há imagens selecionadas com validação de nonce.
		if ( ! $this->codir2me_verify_nonce( 'codir2me_process_manual_upload', 'codir2me_manual_upload_nonce' ) ||
			! isset( $_POST['selected_images'] ) ||
			empty( $_POST['selected_images'] ) ) {

			$redirect_url = add_query_arg(
				array(
					'page'     => 'codirun-codir2me-cdn-reprocess',
					'error'    => 'no_images',
					'_wpnonce' => wp_create_nonce( 'codir2me_admin_nonce' ),
				),
				admin_url( 'admin.php' )
			);
			wp_safe_redirect( $redirect_url );
			exit;
		}

		// Obter IDs de imagens selecionadas.
		$image_ids = array_map( 'intval', explode( ',', sanitize_text_field( wp_unslash( $_POST['selected_images'] ) ) ) );

		if ( empty( $image_ids ) ) {
			$redirect_url = add_query_arg(
				array(
					'page'     => 'codirun-codir2me-cdn-reprocess',
					'error'    => 'invalid_images',
					'_wpnonce' => wp_create_nonce( 'codir2me_admin_nonce' ),
				),
				admin_url( 'admin.php' )
			);
			wp_safe_redirect( $redirect_url );
			exit;
		}

		// Limpar qualquer processo anterior em andamento.
		$this->codir2me_cleanup_previous_process();

		// Obter configurações com validação de nonce.
		$batch_size = get_option( 'codir2me_reprocessing_batch_size', 20 );
		$force_webp = false;
		$force_avif = false;

		// Usar configurações globais em vez do POST.
		$force_webp = get_option( 'codir2me_force_webp', false );
		$force_avif = get_option( 'codir2me_force_avif', false );

		// Salvar configurações para processamento.
		update_option( 'codir2me_force_webp', $force_webp );
		update_option( 'codir2me_force_avif', $force_avif );

		// Calcular total de lotes.
		$total_images  = count( $image_ids );
		$total_batches = ceil( $total_images / $batch_size );

		// Inicializar status.
		$reprocessing_status = array(
			'in_progress'      => true,
			'background_mode'  => false,
			'paused'           => false,
			'total_images'     => $total_images,
			'processed_images' => 0,
			'total_batches'    => $total_batches,
			'current_batch'    => 0,
			'batch_size'       => $batch_size,
			'start_time'       => time(),
			'last_run'         => time(),
			'failed_images'    => array(),
			'processed_list'   => array(),
			'is_manual'        => true,
		);

		update_option( 'codir2me_reprocessing_status', $reprocessing_status );

		// Salvar lista de IDs para processamento.
		update_option( 'codir2me_reprocessing_image_ids', $image_ids );

		// Registrar no log para depuração.
		if ( function_exists( 'codir2me_cdn_log' ) ) {
			/* translators: %1$d: número total de imagens; %2$d: número total de lotes para upload */
			codir2me_cdn_log( sprintf( esc_html__( 'Iniciando upload manual de imagens. Total de imagens: %1$d, Lotes: %2$d', 'codirun-codir2me-cdn' ), $total_images, $total_batches ), 'info' );
		}

		// Definir o modo como manual para garantir que todas as imagens sejam processadas.
		$GLOBALS['codir2me_reprocessing_manual_mode'] = true;

		$redirect_url = add_query_arg(
			array(
				'page'          => 'codirun-codir2me-cdn-reprocess',
				'auto_continue' => '1',
				'_wpnonce'      => wp_create_nonce( 'codir2me_admin_nonce' ),
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Reprocessa as miniaturas de uma imagem.
	 *
	 * @param int    $attachment_id ID da imagem.
	 * @param object $optimizer     Instância do otimizador.
	 * @param object $uploader      Instância do uploader.
	 * @param bool   $force_webp    Se deve forçar WebP.
	 * @param bool   $force_avif    Se deve forçar AVIF.
	 * @return array Resultados do processamento.
	 */
	private function codir2me_reprocess_thumbnails( $attachment_id, $optimizer, $uploader, $force_webp, $force_avif ) {
		$results = array(
			'processed' => 0,
			'errors'    => 0,
			'skipped'   => 0,
		);

		// Obter metadados da imagem.
		$metadata = wp_get_attachment_metadata( $attachment_id );

		if ( ! $metadata || ! isset( $metadata['sizes'] ) ) {
			return $results;
		}

		// Obter diretório da imagem.
		$upload_dir = wp_upload_dir();
		$file_dir   = dirname( $metadata['file'] );
		$base_dir   = $upload_dir['basedir'];

		// Obter lista de imagens já enviadas para verificação rápida.
		$uploaded_images = get_option( 'codir2me_uploaded_images', array() );

		// Registrar miniaturas já processadas nesta execução para evitar duplicatas.
		static $processed_thumbs = array();

		// Verificar se o reprocessamento de miniaturas está ativado.
		$reprocess_thumbnails = get_option( 'codir2me_reprocess_thumbnails', false );
		if ( ! $reprocess_thumbnails ) {
			return $results;
		}

		foreach ( $metadata['sizes'] as $size => $size_info ) {
			// Caminho completo da miniatura.
			$thumb_path          = $base_dir . '/' . trailingslashit( $file_dir ) . $size_info['file'];
			$thumb_relative_path = codir2me_get_relative_path( $thumb_path );

			// Criar chave única para esta miniatura.
			$thumb_key = md5( $thumb_relative_path );

			// Verificar se esta miniatura já foi processada nesta execução.
			if ( isset( $processed_thumbs[ $thumb_key ] ) ) {
				++$results['skipped'];
				continue;
			}

			// Marcar esta miniatura como processada.
			$processed_thumbs[ $thumb_key ] = true;

			// Verificar se o arquivo existe.
			if ( ! file_exists( $thumb_path ) ) {
				++$results['errors'];
				continue;
			}

			try {
				// Obter configurações de otimização para miniaturas.
				$optimization_options = get_option( 'codir2me_image_optimization_options', array() );
				$convert_thumbnails   = get_option( 'codir2me_convert_thumbnails_option', false );
				$enable_webp          = isset( $optimization_options['enable_webp_conversion'] ) ? (bool) $optimization_options['enable_webp_conversion'] : false;
				$enable_avif          = isset( $optimization_options['enable_avif_conversion'] ) ? (bool) $optimization_options['enable_avif_conversion'] : false;

				// Verificar se deve aplicar conversões nas miniaturas.
				$apply_webp = $convert_thumbnails && $enable_webp && $force_webp;
				$apply_avif = $convert_thumbnails && $enable_avif && $force_avif;

				// Reprocessar a miniatura considerando as configurações.
				$result = $optimizer->codir2me_reprocess_image(
					$thumb_path,
					$thumb_relative_path,
					$apply_webp,
					$apply_avif,
					false // Não salvar localmente.
				);

				// Enviar para o R2.
				if ( is_array( $result ) ) {
					// Fazer upload para o R2.
					$upload_result = $uploader->codir2me_upload_file( $result, $thumb_relative_path );

					if ( $upload_result ) {
						// Atualizar a lista de imagens enviadas.
						if ( ! in_array( $thumb_relative_path, $uploaded_images, true ) ) {
							$uploaded_images[] = $thumb_relative_path;
						}

						// Adicionar versões WebP e AVIF à lista se existirem.
						if ( $force_webp && isset( $result['webp_relative_path'] ) ) {
							$webp_upload = $uploader->codir2me_upload_file( $result, $result['webp_relative_path'] );

							if ( $webp_upload && ! in_array( $result['webp_relative_path'], $uploaded_images, true ) ) {
								$uploaded_images[] = $result['webp_relative_path'];
							}
						}

						if ( $force_avif && isset( $result['avif_relative_path'] ) ) {
							$avif_upload = $uploader->codir2me_upload_file( $result, $result['avif_relative_path'] );

							if ( $avif_upload && ! in_array( $result['avif_relative_path'], $uploaded_images, true ) ) {
								$uploaded_images[] = $result['avif_relative_path'];
							}
						}

						++$results['processed'];

						if ( function_exists( 'codir2me_cdn_log' ) ) {
							/* translators: %s: caminho relativo da miniatura processada */
							codir2me_cdn_log( sprintf( esc_html__( 'Miniatura %s processada com sucesso.', 'codirun-codir2me-cdn' ), $thumb_relative_path ), 'info' );
						}
					} else {
						++$results['errors'];
					}
				} else {
					++$results['errors'];
				}
			} catch ( Exception $e ) {
				++$results['errors'];

				if ( function_exists( 'codir2me_cdn_log' ) ) {
					/* translators: %1$s: caminho relativo da miniatura; %2$s: mensagem de erro */
					codir2me_cdn_log( sprintf( esc_html__( 'Erro ao processar miniatura %1$s: %2$s', 'codirun-codir2me-cdn' ), $thumb_relative_path, $e->getMessage() ), 'error' );
				}
			}
		}

		// Atualizar a lista de imagens enviadas.
		update_option( 'codir2me_uploaded_images', $uploaded_images );

		return $results;
	}

	/**
	 * Limpa arquivos temporários criados durante o processamento.
	 */
	private function codir2me_cleanup_temp_files() {
		// Diretório temporário.
		$temp_dir = sys_get_temp_dir() . '/codir2me_temp';

		// Verificar se o diretório existe.
		if ( ! file_exists( $temp_dir ) ) {
			return;
		}

		// Obter arquivos temporários com mais de 10 minutos.
		$files = glob( "{$temp_dir}/*" );

		if ( ! is_array( $files ) ) {
			return;
		}

		$now = time();

		foreach ( $files as $file ) {
			// Pular diretórios.
			if ( is_dir( $file ) ) {
				continue;
			}

			// Verificar tempo de modificação.
			$mod_time = filemtime( $file );
			if ( $now - $mod_time > 600 ) { // 10 minutos.
				// Registrar limpeza no log.
				if ( function_exists( 'codir2me_cdn_log' ) ) {
					/* translators: %s: nome do arquivo temporário removido */
					codir2me_cdn_log( sprintf( esc_html__( 'Removendo arquivo temporário: %s', 'codirun-codir2me-cdn' ), basename( $file ) ), 'info' );
				}

				// Remover arquivo.
				wp_delete_file( $file );
			}
		}
	}

	/**
	 * Função AJAX para obter o status do processamento em segundo plano.
	 */
	public function codir2me_ajax_get_background_status() {
		// Verificar nonce para AJAX.
		if ( ! $this->codir2me_verify_ajax_nonce( 'codir2me_reprocessor_nonce', 'nonce' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Erro de segurança.', 'codirun-codir2me-cdn' ) ) );
			return;
		}

		// Verificar permissões.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Permissão negada.', 'codirun-codir2me-cdn' ) ) );
			return;
		}

		// Obter status atual.
		$reprocessing_status = get_option( 'codir2me_reprocessing_status', array() );

		$response = array(
			'in_progress'      => isset( $reprocessing_status['in_progress'] ) ? $reprocessing_status['in_progress'] : false,
			'paused'           => isset( $reprocessing_status['paused'] ) ? $reprocessing_status['paused'] : false,
			'total_images'     => isset( $reprocessing_status['total_images'] ) ? $reprocessing_status['total_images'] : 0,
			'processed_images' => isset( $reprocessing_status['processed_images'] ) ? $reprocessing_status['processed_images'] : 0,
			'current_batch'    => isset( $reprocessing_status['current_batch'] ) ? $reprocessing_status['current_batch'] : 0,
			'total_batches'    => isset( $reprocessing_status['total_batches'] ) ? $reprocessing_status['total_batches'] : 0,
			'last_run'         => isset( $reprocessing_status['last_run'] ) ? $reprocessing_status['last_run'] : 0,
			'failed_count'     => isset( $reprocessing_status['failed_images'] ) ? count( $reprocessing_status['failed_images'] ) : 0,
			'batch_size'       => isset( $reprocessing_status['batch_size'] ) ? $reprocessing_status['batch_size'] : 20,
		);

		wp_send_json_success( $response );
	}


	/**
	 * Manipula a seleção de imagens via AJAX.
	 */
	public function codir2me_ajax_get_selected_images_preview() {
		// Verificar nonce.
		check_ajax_referer( 'codir2me_reprocessor_nonce', 'nonce' );

		// Verificar permissões.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Permissão negada.', 'codirun-codir2me-cdn' ) ) );
			return;
		}

		// Verificar se há IDs.
		if ( ! isset( $_POST['ids'] ) || empty( $_POST['ids'] ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Nenhuma imagem selecionada.', 'codirun-codir2me-cdn' ) ) );
			return;
		}

		// Processar IDs.
		$image_ids    = array_map( 'intval', explode( ',', sanitize_text_field( wp_unslash( $_POST['ids'] ) ) ) );
		$preview_data = array();

		foreach ( $image_ids as $id ) {
			if ( wp_attachment_is_image( $id ) ) {
				// Construir thumbnail manualmente para garantir URL local.
				$thumbnail_html = $this->codir2me_build_local_thumbnail( $id );

				$image_data = array(
					'id'             => $id,
					'title'          => get_the_title( $id ),
					'thumbnail_html' => $thumbnail_html,
					'full'           => wp_get_attachment_url( $id ),
					'dimensions'     => '',
					'size'           => '',
				);

				// Obter dimensões.
				$metadata = wp_get_attachment_metadata( $id );
				if ( isset( $metadata['width'] ) && isset( $metadata['height'] ) ) {
					$image_data['dimensions'] = $metadata['width'] . 'x' . $metadata['height'];
				}

				// Obter tamanho do arquivo.
				$file_path = get_attached_file( $id );
				if ( file_exists( $file_path ) ) {
					$image_data['size'] = size_format( filesize( $file_path ) );
				}

				$preview_data[] = $image_data;
			}
		}

		wp_send_json_success(
			array(
				'images' => $preview_data,
				'count'  => count( $preview_data ),
			)
		);
	}

	/**
	 * Constrói thumbnail local manualmente
	 *
	 * @param int $attachment_id ID do anexo da imagem.
	 * @return string HTML da imagem.
	 */
	private function codir2me_build_local_thumbnail( $attachment_id ) {
		$metadata = wp_get_attachment_metadata( $attachment_id );

		if ( ! $metadata || empty( $metadata['file'] ) ) {
			return '';
		}

		// Tentar usar thumbnail se existir.
		if ( ! empty( $metadata['sizes']['thumbnail'] ) ) {
			return wp_get_attachment_image(
				$attachment_id,
				'thumbnail',
				false,
				array(
					'class' => 'codir2me-selected-thumbnail',
					'alt'   => get_the_title( $attachment_id ),
				)
			);
		}

		// Fallback: usar imagem original.
		return wp_get_attachment_image(
			$attachment_id,
			'full',
			false,
			array(
				'class' => 'codir2me-selected-thumbnail',
				'style' => 'width: 150px; height: auto;',
				'alt'   => get_the_title( $attachment_id ),
			)
		);
	}

	/**
	 * Salva as configurações de reprocessamento.
	 */
	public function codir2me_update_reprocessing_settings() {
		// Verificar nonce.
		check_admin_referer( 'codir2me_update_reprocessing_settings', 'codir2me_reprocessing_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Acesso negado', 'codirun-codir2me-cdn' ) );
		}

		// Atualizar tamanho do lote com validação adicional.
		if ( isset( $_POST['codir2me_reprocessing_batch_size'] ) ) {
			$batch_size = intval( $_POST['codir2me_reprocessing_batch_size'] );
			$batch_size = max( 1, min( 50, $batch_size ) ); // Limitar entre 1 e 50.
			update_option( 'codir2me_reprocessing_batch_size', $batch_size );
		}

		// Processar opções de conversão.
		$force_webp           = isset( $_POST['codir2me_force_webp'] ) ? true : false;
		$force_avif           = isset( $_POST['codir2me_force_avif'] ) ? true : false;
		$reprocess_thumbnails = isset( $_POST['codir2me_reprocess_thumbnails'] ) ? true : false;

		// Salvar as opções SEM validação que force mudanças.
		update_option( 'codir2me_force_webp', $force_webp );
		update_option( 'codir2me_force_avif', $force_avif );
		update_option( 'codir2me_reprocess_thumbnails', $reprocess_thumbnails );

		// Registrar no log para depuração.
		if ( function_exists( 'codir2me_cdn_log' ) ) {
			$saved_batch_size = get_option( 'codir2me_reprocessing_batch_size', 20 );

			codir2me_cdn_log(
				sprintf(
					// translators: %1$s WebP (ativado/desativado), %2$s AVIF (ativado/desativado), %3$s Miniaturas (ativado/desativado), %4$d Tamanho do lote.
					esc_html__( 'Configurações de reprocessamento atualizadas: WebP=%1$s, AVIF=%2$s, Miniaturas=%3$s, Tamanho do lote=%4$d', 'codirun-codir2me-cdn' ),
					$force_webp ? esc_html__( 'ativado', 'codirun-codir2me-cdn' ) : esc_html__( 'desativado', 'codirun-codir2me-cdn' ),
					$force_avif ? esc_html__( 'ativado', 'codirun-codir2me-cdn' ) : esc_html__( 'desativado', 'codirun-codir2me-cdn' ),
					$reprocess_thumbnails ? esc_html__( 'ativado', 'codirun-codir2me-cdn' ) : esc_html__( 'desativado', 'codirun-codir2me-cdn' ),
					$saved_batch_size
				),
				'info'
			);
		}

		// Redirecionar de volta para a página com mensagem de sucesso.
		$redirect_url = add_query_arg(
			array(
				'page'           => 'codirun-codir2me-cdn-reprocess',
				'settings_saved' => '1',
				'_wpnonce'       => wp_create_nonce( 'codir2me_admin_nonce' ),
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Exibe notificações relacionadas ao reprocessamento de imagens.
	 */
	public function codir2me_reprocessing_notices() {
		// Verificar se estamos na página do plugin.
		$screen = get_current_screen();
		if ( ! isset( $screen->id ) || false === strpos( $screen->id, 'codirun-codir2me-cdn' ) ) {
			return;
		}

		// Obter parâmetros GET de forma segura - GET não precisa de nonce - CORRIGIDO para não gerar warnings.
		$reprocessing_complete = $this->codir2me_get_safe_get_param( 'reprocessing_complete' );
		$background_started    = $this->codir2me_get_safe_get_param( 'background_started' );
		$reprocessing_paused   = $this->codir2me_get_safe_get_param( 'reprocessing_paused' );
		$reprocessing_canceled = $this->codir2me_get_safe_get_param( 'reprocessing_canceled' );
		$settings_saved        = $this->codir2me_get_safe_get_param( 'settings_saved' );
		$error                 = $this->codir2me_get_safe_get_param( 'error' );

		// Notificação de reprocessamento concluído.
		if ( '1' === $reprocessing_complete ) {
			?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'Reprocessamento de imagens concluído com sucesso!', 'codirun-codir2me-cdn' ); ?></p>
			</div>
			<?php
		}

		// Notificação de reprocessamento iniciado.
		if ( '1' === $background_started ) {
			?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'Reprocessamento de imagens iniciado com sucesso! O processo está sendo executado em segundo plano.', 'codirun-codir2me-cdn' ); ?></p>
			</div>
			<?php
		}

		// Notificação de reprocessamento pausado.
		if ( '1' === $reprocessing_paused ) {
			?>
			<div class="notice notice-warning is-dismissible">
				<p><?php esc_html_e( 'Reprocessamento de imagens pausado. Você pode retomar o processo a qualquer momento clicando em "Continuar Reprocessamento".', 'codirun-codir2me-cdn' ); ?></p>
			</div>
			<?php
		}

		// Notificação de reprocessamento cancelado.
		if ( '1' === $reprocessing_canceled ) {
			?>
			<div class="notice notice-info is-dismissible">
				<p><?php esc_html_e( 'Reprocessamento de imagens cancelado com sucesso.', 'codirun-codir2me-cdn' ); ?></p>
			</div>
			<?php
		}

		// Notificação de configurações salvas.
		if ( '1' === $settings_saved ) {
			?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'Configurações de reprocessamento salvas com sucesso!', 'codirun-codir2me-cdn' ); ?></p>
			</div>
			<?php
		}

		// Notificação de erro - processo não em andamento.
		if ( 'not_in_progress' === $error ) {
			?>
			<div class="notice notice-error is-dismissible">
				<p><?php esc_html_e( 'Não há nenhum processo de reprocessamento em andamento.', 'codirun-codir2me-cdn' ); ?></p>
			</div>
			<?php
		}

		// Notificação de erro - falha ao inicializar uploader.
		if ( 'uploader_init' === $error ) {
			?>
			<div class="notice notice-error is-dismissible">
				<p><?php esc_html_e( 'Erro ao inicializar o uploader. Verifique as configurações de conexão com o R2.', 'codirun-codir2me-cdn' ); ?></p>
			</div>
			<?php
		}

		// Notificação de erro - nenhuma imagem selecionada.
		if ( 'no_images' === $error ) {
			?>
			<div class="notice notice-error is-dismissible">
				<p><?php esc_html_e( 'Nenhuma imagem foi selecionada para processamento.', 'codirun-codir2me-cdn' ); ?></p>
			</div>
			<?php
		}

		// Notificação de erro - imagens inválidas.
		if ( 'invalid_images' === $error ) {
			?>
			<div class="notice notice-error is-dismissible">
				<p><?php esc_html_e( 'Os IDs de imagem fornecidos são inválidos.', 'codirun-codir2me-cdn' ); ?></p>
			</div>
			<?php
		}
	}

	/**
	 * Renderiza a interface de reprocessamento.
	 */
	public function codir2me_render() {
		// Verificar permissões.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Você não tem permissão para acessar esta página.', 'codirun-codir2me-cdn' ) );
		}

		// Verificar se o AWS SDK está disponível.
		$asyncaws_sdk_available = false;
		if ( file_exists( CODIR2ME_CDN_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
			require_once CODIR2ME_CDN_PLUGIN_DIR . 'vendor/autoload.php';
			$asyncaws_sdk_available = class_exists( 'AsyncAws\S3\S3Client' );
		}

		// Verificar se há um reprocessamento em andamento.
		$reprocessing_in_progress = false;
		$reprocessing_status      = get_option( 'codir2me_reprocessing_status', array() );

		if ( ! empty( $reprocessing_status ) && isset( $reprocessing_status['in_progress'] ) && $reprocessing_status['in_progress'] ) {
			$reprocessing_in_progress = true;
		}

		// Extrair valores de status para facilitar acesso.
		$processed_images = isset( $reprocessing_status['processed_images'] ) ? $reprocessing_status['processed_images'] : 0;
		$total_images     = isset( $reprocessing_status['total_images'] ) ? $reprocessing_status['total_images'] : 0;
		$percentage       = ( $total_images > 0 ) ? round( ( $processed_images / $total_images ) * 100, 1 ) : 0;
		$current_batch    = isset( $reprocessing_status['current_batch'] ) ? $reprocessing_status['current_batch'] : 0;
		$total_batches    = isset( $reprocessing_status['total_batches'] ) ? $reprocessing_status['total_batches'] : 0;
		$batch_size       = isset( $reprocessing_status['batch_size'] ) ? $reprocessing_status['batch_size'] : get_option( 'codir2me_reprocessing_batch_size', 20 );
		$background_mode  = isset( $reprocessing_status['background_mode'] ) ? $reprocessing_status['background_mode'] : false;

		// Verificar se está pausado.
		$is_paused = isset( $reprocessing_status['paused'] ) && $reprocessing_status['paused'];

		?>
		<div class="codir2me-tab-content">
			<div class="codir2me-flex-container">
				<div class="codir2me-main-column">
					<div class="codir2me-section">
						<h2><?php esc_html_e( 'Reprocessamento de Imagens', 'codirun-codir2me-cdn' ); ?></h2>
						<p><?php esc_html_e( 'Essa ferramenta permite reenviar imagens para o R2, forçando a otimização e aplicando suas configurações atuais.', 'codirun-codir2me-cdn' ); ?></p>
						
						<?php if ( ! $asyncaws_sdk_available ) : ?>
							<div class="notice notice-error">
								<p><?php esc_html_e( 'AWS SDK não encontrado. Por favor, instale o SDK para usar esta funcionalidade.', 'codirun-codir2me-cdn' ); ?></p>
							</div>
						<?php else : ?>
						
						<!-- Configurações de Reprocessamento -->
							<?php if ( ! $reprocessing_in_progress || $is_paused ) : ?>
							<div class="codir2me-reprocessing-settings">
								<h3><?php esc_html_e( 'Configurações de Reprocessamento', 'codirun-codir2me-cdn' ); ?></h3>
								
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<?php wp_nonce_field( 'codir2me_update_reprocessing_settings', 'codir2me_reprocessing_nonce' ); ?>
									<input type="hidden" name="action" value="codir2me_update_reprocessing_settings">
									
									<table class="form-table">
										<tr>
											<th><?php esc_html_e( 'Tamanho do Lote', 'codirun-codir2me-cdn' ); ?></th>
											<td>
												<input type="number" name="codir2me_reprocessing_batch_size" value="<?php echo esc_attr( get_option( 'codir2me_reprocessing_batch_size', 20 ) ); ?>" class="small-text" min="1" max="50" />
												<p class="description"><?php esc_html_e( 'Número de imagens reprocessadas por lote (recomendado: 20)', 'codirun-codir2me-cdn' ); ?></p>
											</td>
										</tr>
										<tr>
											<th><?php esc_html_e( 'Opções de Conversão', 'codirun-codir2me-cdn' ); ?></th>
											<td>
												<div class="codir2me-conversion-options">
													<div class="codir2me-format-option" style="margin-bottom: 15px;">
														<label class="codir2me-toggle-switch">
															<input type="checkbox" name="codir2me_force_webp" value="1" <?php checked( get_option( 'codir2me_force_webp', false ) ); ?> />
															<span class="codir2me-toggle-slider"></span>
														</label>
														<span class="codir2me-format-label">
															<strong><?php esc_html_e( 'Forçar conversão para WebP', 'codirun-codir2me-cdn' ); ?></strong>
														</span>
														<span class="description"><?php esc_html_e( 'Gera versões WebP das imagens durante o reprocessamento', 'codirun-codir2me-cdn' ); ?></span>
													</div>
													
													<div class="codir2me-format-option" style="margin-bottom: 15px;">
														<label class="codir2me-toggle-switch">
															<input type="checkbox" name="codir2me_force_avif" value="1" <?php checked( get_option( 'codir2me_force_avif', false ) ); ?> />
															<span class="codir2me-toggle-slider"></span>
														</label>
														<span class="codir2me-format-label">
															<strong><?php esc_html_e( 'Forçar conversão para AVIF', 'codirun-codir2me-cdn' ); ?></strong>
														</span>
														<span class="description"><?php esc_html_e( 'Gera versões AVIF das imagens durante o reprocessamento', 'codirun-codir2me-cdn' ); ?></span>
													</div>
													
													<div class="codir2me-format-option" style="margin-bottom: 15px;">
														<label class="codir2me-toggle-switch">
															<input type="checkbox" name="codir2me_reprocess_thumbnails" value="1" <?php checked( get_option( 'codir2me_reprocess_thumbnails', false ) ); ?> />
															<span class="codir2me-toggle-slider"></span>
														</label>
														<span class="codir2me-format-label">
															<strong><?php esc_html_e( 'Reprocessar Miniaturas', 'codirun-codir2me-cdn' ); ?></strong>
														</span>
														<span class="description"><?php esc_html_e( 'Aplica as conversões WebP/AVIF também nas miniaturas durante o reprocessamento', 'codirun-codir2me-cdn' ); ?></span>
													</div>
												</div>
												
												<div class="codir2me-format-info">
													<p><?php esc_html_e( 'Selecione os formatos de imagem que deseja forçar durante o reprocessamento. As conversões só serão aplicadas se as respectivas opções estiverem ativadas nas configurações de otimização.', 'codirun-codir2me-cdn' ); ?></p>
												</div>
											</td>
										</tr>
									</table>
									<?php submit_button( __( 'Salvar Configurações', 'codirun-codir2me-cdn' ) ); ?>
								</form>
							</div>
						<?php endif; ?>
	
						<!-- Reprocessamento em Lote -->
						<div class="codir2me-reprocess-all-section">
							<h3 style="margin-top: 20px;"><?php esc_html_e( 'Reprocessar Todas as Imagens', 'codirun-codir2me-cdn' ); ?></h3>
							
							<?php if ( $reprocessing_in_progress ) : ?>
								<!-- Interface de progresso -->
								<div class="codir2me-upload-progress">
									<h3>
									<?php
										echo $background_mode ?
											esc_html__( 'Reprocessamento em Segundo Plano', 'codirun-codir2me-cdn' ) :
											esc_html__( 'Reprocessamento em Andamento', 'codirun-codir2me-cdn' );
									?>
									</h3>
									
									<div class="codir2me-progress-details">
										<p>
											<span class="codir2me-progress-label"><?php esc_html_e( 'Status:', 'codirun-codir2me-cdn' ); ?></span>
											<span class="codir2me-progress-value status-indicator <?php echo $is_paused ? 'paused' : 'running'; ?>">
												<?php echo $is_paused ? esc_html__( 'Pausado', 'codirun-codir2me-cdn' ) : esc_html__( 'Executando', 'codirun-codir2me-cdn' ); ?>
											</span>
										</p>
										<p>
											<span class="codir2me-progress-label"><?php esc_html_e( 'Imagens processadas:', 'codirun-codir2me-cdn' ); ?></span>
											<span class="codir2me-progress-value"><?php echo esc_html( $processed_images ); ?> <?php esc_html_e( 'de', 'codirun-codir2me-cdn' ); ?> <?php echo esc_html( $total_images ); ?></span>
										</p>
										<p>
											<span class="codir2me-progress-label"><?php esc_html_e( 'Progresso geral:', 'codirun-codir2me-cdn' ); ?></span>
											<span class="codir2me-progress-value"><?php echo esc_html( ( $total_images > 0 ) ? round( ( $processed_images / $total_images ) * 100, 1 ) : 0 ); ?>%</span>
										</p>
									</div>
									
									<div class="codir2me-progress-bar">
										<div class="codir2me-progress-inner" style="width: <?php echo esc_attr( ( $total_images > 0 ) ? ( $processed_images / $total_images * 100 ) : 0 ); ?>%;"></div>
									</div>
									
									<?php if ( $background_mode ) : ?>
										<p class="codir2me-progress-info"><?php esc_html_e( 'O reprocessamento está ocorrendo em segundo plano. Você pode fechar esta página e o processo continuará.', 'codirun-codir2me-cdn' ); ?></p>
									<?php else : ?>
										<p class="codir2me-progress-warning"><?php esc_html_e( 'Por favor, não feche esta página até que o processo termine.', 'codirun-codir2me-cdn' ); ?></p>
									<?php endif; ?>
									
									<div class="codir2me-progress-actions">
										<?php if ( $is_paused ) : ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="codir2me-continue-form" style="display: inline-block; margin-right: 10px;">
											<?php wp_nonce_field( 'codir2me_process_reprocessing_batch', 'codir2me_reprocessing_batch_nonce' ); ?>
											<input type="hidden" name="action" value="codir2me_process_reprocessing_batch">
											<button type="submit" name="codir2me_process_reprocessing_batch" class="button button-primary">
												<span class="dashicons dashicons-controls-play"></span>
												<?php esc_html_e( 'Continuar Reprocessamento', 'codirun-codir2me-cdn' ); ?>
											</button>
										</form>
										<?php else : ?>
										
											<?php if ( ! $background_mode ) : ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="codir2me-continue-form" style="display: inline-block; margin-right: 10px;">
												<?php wp_nonce_field( 'codir2me_process_reprocessing_batch', 'codir2me_reprocessing_batch_nonce' ); ?>
											<input type="hidden" name="action" value="codir2me_process_reprocessing_batch">
											<button type="submit" name="codir2me_process_reprocessing_batch" class="button button-primary">
												<span class="dashicons dashicons-controls-play"></span>
												<?php esc_html_e( 'Continuar Reprocessamento (Próximo Lote)', 'codirun-codir2me-cdn' ); ?>
											</button>
										</form>
										<?php endif; ?>
										
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: inline-block; margin-right: 10px;">
											<?php wp_nonce_field( 'codir2me_pause_reprocessing', 'codir2me_pause_reprocessing_nonce' ); ?>
											<input type="hidden" name="action" value="codir2me_pause_reprocessing">
											<button type="submit" name="codir2me_pause_reprocessing" class="button button-secondary">
												<span class="dashicons dashicons-controls-pause"></span>
												<?php esc_html_e( 'Pausar Reprocessamento', 'codirun-codir2me-cdn' ); ?>
											</button>
										</form>
										<?php endif; ?>
										
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: inline-block;" class="codir2me-stop-form">
											<?php wp_nonce_field( 'codir2me_cancel_reprocessing', 'codir2me_cancel_reprocessing_nonce' ); ?>
											<input type="hidden" name="action" value="codir2me_cancel_reprocessing">
											<button type="submit" name="codir2me_cancel_reprocessing" class="button button-secondary codir2me-stop-button" style="background-color: #f56e28; color: white; border-color: #d65b25;">
												<span class="dashicons dashicons-no-alt"></span>
												<?php esc_html_e( 'Parar Reprocessamento', 'codirun-codir2me-cdn' ); ?>
											</button>
										</form>
									</div>
								</div>
							<?php else : ?>
								<!-- Interface de início simplificada -->
								<div class="codir2me-upload-start">
									<p><?php esc_html_e( 'Reprocesse todas as imagens da sua biblioteca de mídia no R2, aplicando as configurações atuais de otimização.', 'codirun-codir2me-cdn' ); ?></p>
									<p><?php esc_html_e( 'Você pode escolher entre duas opções de processamento:', 'codirun-codir2me-cdn' ); ?></p>
									
									<div class="codir2me-processing-options">
										<div class="codir2me-process-option">
											<h4>
												<span class="dashicons dashicons-update"></span>
												<?php esc_html_e( 'Processamento em Segundo Plano', 'codirun-codir2me-cdn' ); ?>
											</h4>
											<p><?php esc_html_e( 'O processamento ocorre automaticamente mesmo quando você fecha a página. Ideal para grandes bibliotecas de imagens.', 'codirun-codir2me-cdn' ); ?></p>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="codir2me-start-form">
												<?php wp_nonce_field( 'codir2me_start_background_reprocessing', 'codir2me_start_background_nonce' ); ?>
												<input type="hidden" name="action" value="codir2me_start_background_reprocessing">
												<button type="submit" name="codir2me_start_background_reprocessing" class="button button-primary">
													<span class="dashicons dashicons-cloud-upload"></span>
													<?php esc_html_e( 'Iniciar Processamento em Segundo Plano', 'codirun-codir2me-cdn' ); ?>
												</button>
											</form>
										</div>
										
										<div class="codir2me-process-option">
											<h4>
												<span class="dashicons dashicons-visibility"></span>
												<?php esc_html_e( 'Processamento Normal', 'codirun-codir2me-cdn' ); ?>
											</h4>
											<p><?php esc_html_e( 'Acompanhe o progresso em tempo real. Você precisa manter esta página aberta durante todo o processamento.', 'codirun-codir2me-cdn' ); ?></p>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="codir2me-start-form">
												<?php wp_nonce_field( 'codir2me_start_reprocessing', 'codir2me_start_reprocessing_nonce' ); ?>
												<input type="hidden" name="action" value="codir2me_start_reprocessing">
												<button type="submit" name="codir2me_start_reprocessing" class="button button-primary">
													<span class="dashicons dashicons-update"></span>
													<?php esc_html_e( 'Iniciar Processamento Normal', 'codirun-codir2me-cdn' ); ?>
												</button>
											</form>
										</div>
									</div>
								</div>
							<?php endif; ?>
						</div>
						
						<!-- Upload Manual de Imagens (mantido) -->
						<div class="codir2me-manual-upload-section">
							<h3><?php esc_html_e( 'Upload Manual de Imagens', 'codirun-codir2me-cdn' ); ?></h3>
							<p><?php esc_html_e( 'Selecione imagens específicas para enviar ao R2, em vez de processar toda a biblioteca.', 'codirun-codir2me-cdn' ); ?></p>
							
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="codir2me-manual-upload-form">
								<?php wp_nonce_field( 'codir2me_process_manual_upload', 'codir2me_manual_upload_nonce' ); ?>
								<input type="hidden" name="action" value="codir2me_process_manual_upload">
								<input type="hidden" name="selected_images" id="selected-images-input" value="">
								
								<div class="codir2me-manual-upload-options">
									<div class="codir2me-manual-select-container">
										<button type="button" id="codir2me-select-images" class="button button-secondary">
											<span class="dashicons dashicons-images-alt"></span>
											<?php esc_html_e( 'Selecionar Imagens', 'codirun-codir2me-cdn' ); ?>
										</button>
										<span id="selected-images-count"><?php esc_html_e( '0 imagens selecionadas', 'codirun-codir2me-cdn' ); ?></span>
									</div>
									
									<div class="codir2me-manual-info">
										<p class="description">
											<span class="dashicons dashicons-info"></span>
											<?php esc_html_e( 'As imagens selecionadas serão processadas usando as configurações de conversão definidas acima (WebP, AVIF e Miniaturas).', 'codirun-codir2me-cdn' ); ?>
										</p>
									</div>
								</div>
								
								<div id="selected-images-preview" class="codir2me-selected-images-preview">
									<p class="no-images-selected"><?php esc_html_e( 'Nenhuma imagem selecionada. Clique em "Selecionar Imagens" para escolher quais imagens processar.', 'codirun-codir2me-cdn' ); ?></p>
								</div>
								
								<p class="submit">
									<button type="submit" id="start-manual-upload" class="button button-primary" disabled>
										<span class="dashicons dashicons-cloud-upload"></span>
										<?php esc_html_e( 'Iniciar Upload das Imagens Selecionadas', 'codirun-codir2me-cdn' ); ?>
									</button>
								</p>
							</form>
						</div>

						<?php endif; ?>
					</div>
				</div>
				
				<?php $this->codir2me_render_sidebar(); ?>
			</div>
		</div>       
		<?php
	}

	/**
	 * Renderiza a barra lateral.
	 */
	private function codir2me_render_sidebar() {
		?>
		<div class="codir2me-sidebar">
			<div class="codir2me-sidebar-widget">
				<h3><?php esc_html_e( 'Sobre o Reprocessamento', 'codirun-codir2me-cdn' ); ?></h3>
				<div class="codir2me-widget-content">
					<p><?php esc_html_e( 'Esta ferramenta permite reenviar imagens para o Cloudflare R2, aplicando as configurações atuais de otimização.', 'codirun-codir2me-cdn' ); ?></p>
					<p><?php esc_html_e( 'Utilize quando:', 'codirun-codir2me-cdn' ); ?></p>
					<ul class="codir2me-tips-list">
						<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Alterou as configurações de otimização', 'codirun-codir2me-cdn' ); ?></li>
						<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Ativou novos formatos (WebP/AVIF)', 'codirun-codir2me-cdn' ); ?></li>
						<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Imagens não aparecem no site', 'codirun-codir2me-cdn' ); ?></li>
						<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Perceber problemas com imagens específicas', 'codirun-codir2me-cdn' ); ?></li>
					</ul>
				</div>
			</div>
			
			<div class="codir2me-sidebar-widget">
				<h3><?php esc_html_e( 'Modos de Processamento', 'codirun-codir2me-cdn' ); ?></h3>
				<div class="codir2me-widget-content">
					<p><strong><?php esc_html_e( 'Processamento em Segundo Plano:', 'codirun-codir2me-cdn' ); ?></strong></p>
					<ul class="codir2me-tips-list">
						<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Continua executando mesmo ao fechar a página', 'codirun-codir2me-cdn' ); ?></li>
						<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Ideal para sites com muitas imagens', 'codirun-codir2me-cdn' ); ?></li>
						<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Menor impacto no desempenho do site', 'codirun-codir2me-cdn' ); ?></li>
						<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Pode ser pausado e retomado a qualquer momento', 'codirun-codir2me-cdn' ); ?></li>
					</ul>
					<p><strong><?php esc_html_e( 'Processamento Normal:', 'codirun-codir2me-cdn' ); ?></strong></p>
					<ul class="codir2me-tips-list">
						<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Exibe atualizações em tempo real', 'codirun-codir2me-cdn' ); ?></li>
						<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Você precisa manter a página aberta', 'codirun-codir2me-cdn' ); ?></li>
						<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Melhor para conjuntos menores de imagens', 'codirun-codir2me-cdn' ); ?></li>
						<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Mais adequado para monitoramento direto', 'codirun-codir2me-cdn' ); ?></li>
					</ul>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Processa a atualização das configurações de reprocessamento
	 */
	public function codir2me_handle_update_reprocessing_settings() {
		// Verificar nonce.
		if ( ! isset( $_POST['codir2me_reprocessing_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['codir2me_reprocessing_nonce'] ) ), 'codir2me_update_reprocessing_settings' ) ) {
			wp_die( esc_html__( 'Falha na verificação de segurança.', 'codirun-codir2me-cdn' ) );
		}

		// Verificar permissões.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Você não tem permissão para executar esta ação.', 'codirun-codir2me-cdn' ) );
		}

		// Processar tamanho do lote.
		if ( isset( $_POST['codir2me_reprocessing_batch_size'] ) ) {
			$batch_size = absint( $_POST['codir2me_reprocessing_batch_size'] );
			$batch_size = max( 1, min( 50, $batch_size ) );
			update_option( 'codir2me_reprocessing_batch_size', $batch_size );
		}

		// Processar opções de conversão.
		$force_webp           = isset( $_POST['codir2me_force_webp'] ) ? 1 : 0;
		$force_avif           = isset( $_POST['codir2me_force_avif'] ) ? 1 : 0;
		$reprocess_thumbnails = isset( $_POST['codir2me_reprocess_thumbnails'] ) ? 1 : 0;

		// Validar configuração de miniaturas.
		if ( $reprocess_thumbnails && ! $force_webp && ! $force_avif ) {
			// Se tentou ativar miniaturas sem conversão, desativar miniaturas.
			$reprocess_thumbnails = 0;

			// Adicionar aviso na próxima página.
			set_transient( 'codir2me_reprocess_warning', 'thumbnails_without_conversion', 30 );
		}

		// Salvar as opções.
		update_option( 'codir2me_force_webp', $force_webp );
		update_option( 'codir2me_force_avif', $force_avif );
		update_option( 'codir2me_reprocess_thumbnails', $reprocess_thumbnails );

		// Redirecionar com sucesso.
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'             => 'codirun-codir2me-cdn-reprocess',
					'settings_updated' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Inicializa opções padrão.
	 */
	private function codir2me_initialize_default_options() {
		// Configurar opções padrão para o tamanho do lote se não existir.
		if ( false === get_option( 'codir2me_reprocessing_batch_size' ) ) {
			update_option( 'codir2me_reprocessing_batch_size', 20 );
		}

		// Configurar opções padrão para conversão WebP e AVIF se não existirem.
		if ( false === get_option( 'codir2me_force_webp' ) ) {
			update_option( 'codir2me_force_webp', false );
		}

		if ( false === get_option( 'codir2me_force_avif' ) ) {
			update_option( 'codir2me_force_avif', false );
		}

		// Configurar opção padrão para reprocessamento de miniaturas se não existir.
		if ( false === get_option( 'codir2me_reprocess_thumbnails' ) ) {
			update_option( 'codir2me_reprocess_thumbnails', false );
		}
	}
}
