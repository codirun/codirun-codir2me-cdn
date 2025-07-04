<?php
/**
 * Classe responsável pelo processamento em segundo plano
 *
 * @package Codirun_R2_Media_Static_CDN
 */

// Evitar acesso direto ao arquivo.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classe responsável pelo processamento em segundo plano de imagens e arquivos.
 *
 * Esta classe gerencia o processamento em lotes de imagens e arquivos,
 * utilizando tanto AJAX em tempo real quanto cron como backup.
 */
class CODIR2ME_Background_Processor {

	/**
	 * Construtor da classe.
	 *
	 * Inicializa os hooks necessários para o processamento em segundo plano.
	 */
	public function __construct() {
		// Registrar intervalo personalizado para o cron.
		add_filter( 'cron_schedules', array( $this, 'codir2me_add_cron_interval' ) );
		add_filter( 'cron_schedules', array( $this, 'codir2me_add_fifteen_minute_cron_interval' ) );

		// Adicionar rota AJAX para obter miniaturas.
		add_action( 'wp_ajax_codir2me_get_image_thumbnail', array( $this, 'codir2me_ajax_get_image_thumbnail' ) );

		// Adicionar rota AJAX para processamento em tempo real.
		add_action( 'wp_ajax_codir2me_process_batch_realtime', array( $this, 'codir2me_ajax_process_batch_realtime' ) );
	}

	/**
	 * Manipulador AJAX para processamento em tempo real de lotes.
	 *
	 * Processa um lote de imagens em tempo real via AJAX, oferecendo
	 * resposta mais rápida que o cron tradicional.
	 */
	public function codir2me_ajax_process_batch_realtime() {
		// Verificar nonce.
		if ( ! check_ajax_referer( 'codir2me_realtime_processing', 'nonce', false ) ) {
			wp_die( esc_html__( 'Erro de segurança.', 'codirun-codir2me-cdn' ) );
		}

		// Verificar capacidade do usuário.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permissão insuficiente.', 'codirun-codir2me-cdn' ) );
		}

		// Processar próximo lote.
		$result = $this->codir2me_process_next_batch();

		// Retornar resultado como JSON.
		wp_send_json( $result );
	}

	/**
	 * Manipulador AJAX para obter miniaturas de imagens.
	 */
	public function codir2me_ajax_get_image_thumbnail() {
		// Verificar nonce.
		if ( ! check_ajax_referer( 'codir2me_reprocessor_nonce', 'nonce', false ) ) {
			wp_die( esc_html__( 'Erro de segurança.', 'codirun-codir2me-cdn' ) );
		}

		// Verificar ID da imagem.
		$attachment_id = isset( $_GET['id'] ) ? intval( $_GET['id'] ) : 0;
		if ( 0 >= $attachment_id ) {
			wp_die( esc_html__( 'ID de imagem inválido.', 'codirun-codir2me-cdn' ) );
		}

		// Obter URL da miniatura.
		$thumb_url = wp_get_attachment_image_url( $attachment_id, 'thumbnail' );
		if ( ! $thumb_url ) {
			wp_die( esc_html__( 'Miniatura não disponível.', 'codirun-codir2me-cdn' ) );
		}

		// Usar redirecionamento seguro.
		wp_safe_redirect( $thumb_url );
		exit;
	}

	/**
	 * Adiciona intervalos personalizados para o cron.
	 *
	 * @param array $schedules Agendamentos do WordPress.
	 * @return array Agendamentos atualizados.
	 */
	public function codir2me_add_cron_interval( $schedules ) {
		// Intervalo principal recomendado (15 minutos).
		$schedules['codir2me_fifteen_minutes'] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'A cada 15 minutos', 'codirun-codir2me-cdn' ),
		);

		// Intervalo alternativo mais frequente (5 minutos) - usar com cautela.
		$schedules['codir2me_five_minutes'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'A cada 5 minutos (usar com cautela)', 'codirun-codir2me-cdn' ),
		);

		// Permitir que o usuário personalize o intervalo via filtro.
		$custom_interval = apply_filters( 'codir2me_background_cron_interval', 15 * MINUTE_IN_SECONDS );

		if ( 15 * MINUTE_IN_SECONDS !== $custom_interval && MINUTE_IN_SECONDS <= $custom_interval ) {
			$schedules['codir2me_custom_interval'] = array(
				'interval' => $custom_interval,
				/* translators: %d: quantidade de segundos do intervalo personalizado */
				'display'  => sprintf( __( 'Intervalo personalizado (%d segundos)', 'codirun-codir2me-cdn' ), $custom_interval ),
			);
		}

		return $schedules;
	}

	/**
	 * Processa o próximo lote de imagens.
	 *
	 * @return array Resultado do processamento com status e informações.
	 */
	public function codir2me_process_next_batch() {
		$status = $this->codir2me_get_status();

		// Verificar se há processamento em andamento.
		if ( ! $status['in_progress'] ) {
			return array(
				'success' => false,
				'message' => __( 'Nenhum processamento em andamento.', 'codirun-codir2me-cdn' ),
			);
		}

		// Obter lista de imagens pendentes.
		$pending_images = get_option( 'codir2me_reprocessing_image_ids', array() );

		if ( empty( $pending_images ) ) {
			// Finalizar processamento.
			$this->codir2me_complete_processing();
			return array(
				'success'   => true,
				'completed' => true,
				'message'   => __( 'Processamento concluído com sucesso.', 'codirun-codir2me-cdn' ),
			);
		}

		// Processar lote.
		$batch_size      = max( 1, intval( $status['batch_size'] ) );
		$current_batch   = array_splice( $pending_images, 0, $batch_size );
		$processed_count = 0;
		$failed_count    = 0;

		foreach ( $current_batch as $image_id ) {
			$result = $this->codir2me_process_single_image( $image_id );
			if ( $result ) {
				++$processed_count;
			} else {
				++$failed_count;
				// Adicionar à lista de falhas.
				$status['failed_images'][] = $image_id;
			}
		}

		// Atualizar progresso.
		$status['processed_images'] += $processed_count;
		++$status['current_batch'];
		$status['last_run'] = time();

		// Atualizar opções.
		update_option( 'codir2me_reprocessing_image_ids', $pending_images );
		update_option( 'codir2me_reprocessing_status', $status );

		return array(
			'success'   => true,
			'processed' => $processed_count,
			'failed'    => $failed_count,
			'remaining' => count( $pending_images ),
			'progress'  => $this->codir2me_calculate_progress( $status ),
		);
	}

	/**
	 * Processa uma única imagem.
	 *
	 * @param int $image_id ID da imagem a ser processada.
	 * @return bool True se processada com sucesso, false caso contrário.
	 */
	private function codir2me_process_single_image( $image_id ) {
		try {
			// Verificar se a imagem existe.
			if ( ! wp_attachment_is_image( $image_id ) ) {
				return false;
			}

			// Simular processamento por enquanto.
			// Substitua por sua lógica real de processamento.
			$success = apply_filters( 'codir2me_process_image', true, $image_id );

			if ( $success ) {
				// Log de sucesso.
				codir2me_cdn_log(
					/* translators: %d: ID da imagem */
					sprintf( __( 'Imagem %d processada com sucesso.', 'codirun-codir2me-cdn' ), $image_id ),
					'info'
				);
				return true;
			}

			return false;

		} catch ( Exception $e ) {
			// Log de erro.
			codir2me_cdn_log(
				/* translators: %1$d: ID da imagem, %2$s: mensagem de erro */
				sprintf( __( 'Erro ao processar imagem %1$d: %2$s', 'codirun-codir2me-cdn' ), $image_id, $e->getMessage() ),
				'error'
			);
			return false;
		}
	}

	/**
	 * Método corrigido para adicionar intervalos de cron
	 * Este é o método que estava sendo chamado incorretamente
	 *
	 * @param array $schedules Agendamentos do WordPress.
	 * @return array Agendamentos atualizados.
	 */
	public function codir2me_add_fifteen_minute_cron_interval( $schedules ) {
		// Intervalo principal recomendado (15 minutos).
		$schedules['codir2me_fifteen_minutes'] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'A cada 15 minutos', 'codirun-codir2me-cdn' ),
		);

		// Intervalo alternativo mais frequente (5 minutos) - usar com cautela.
		$schedules['codir2me_five_minutes'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'A cada 5 minutos (usar com cautela)', 'codirun-codir2me-cdn' ),
		);

		// Intervalo mais longo (30 minutos).
		$schedules['codir2me_thirty_minutes'] = array(
			'interval' => 30 * MINUTE_IN_SECONDS,
			'display'  => __( 'A cada 30 minutos', 'codirun-codir2me-cdn' ),
		);

		// Permitir que o usuário personalize o intervalo via filtro.
		$custom_interval = apply_filters( 'codir2me_background_cron_interval', 15 * MINUTE_IN_SECONDS );

		if ( 15 * MINUTE_IN_SECONDS !== $custom_interval && MINUTE_IN_SECONDS <= $custom_interval ) {
			$schedules['codir2me_custom_interval'] = array(
				'interval' => $custom_interval,
				/* translators: %d: quantidade de segundos do intervalo personalizado */
				'display'  => sprintf( __( 'Intervalo personalizado (%d segundos)', 'codirun-codir2me-cdn' ), $custom_interval ),
			);
		}

		return $schedules;
	}

	/**
	 * Calcula o progresso atual do processamento.
	 *
	 * @param array $status Status atual do processamento.
	 * @return float Porcentagem de progresso (0-100).
	 */
	private function codir2me_calculate_progress( $status ) {
		if ( empty( $status['total_images'] ) || 0 >= $status['total_images'] ) {
			return 0;
		}

		$progress = ( $status['processed_images'] / $status['total_images'] ) * 100;
		return min( 100, max( 0, round( $progress, 2 ) ) );
	}

	/**
	 * Finaliza o processamento em segundo plano.
	 */
	private function codir2me_complete_processing() {
		// Cancelar eventos agendados.
		$this->codir2me_clean_up();

		// Atualizar status final.
		$status                 = $this->codir2me_get_status();
		$status['in_progress']  = false;
		$status['paused']       = false;
		$status['completed_at'] = time();

		update_option( 'codir2me_reprocessing_status', $status );

		// Log de conclusão.
		codir2me_cdn_log(
			/* translators: %d: número total de imagens processadas */
			sprintf( __( 'Processamento em segundo plano concluído. Total processado: %d imagens.', 'codirun-codir2me-cdn' ), $status['processed_images'] ),
			'info'
		);
	}

	/**
	 * Verifica o status atual do processamento.
	 *
	 * @return array Status do processamento.
	 */
	public function codir2me_get_status() {
		return get_option(
			'codir2me_reprocessing_status',
			array(
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
				'completed_at'     => 0,
				'failed_images'    => array(),
				'processed_list'   => array(),
			)
		);
	}

	/**
	 * Limpa o status atual e quaisquer tarefas em execução.
	 */
	public function codir2me_clean_up() {
		// Cancelar eventos agendados - verificar todos os intervalos possíveis.
		$intervals = array( 'codir2me_background_reprocessing_event', 'codir2me_fifteen_minutes', 'codir2me_five_minutes', 'codir2me_custom_interval' );

		foreach ( $intervals as $interval ) {
			$timestamp = wp_next_scheduled( $interval );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, $interval );
			}
		}

		// Limpar lista de imagens pendentes.
		delete_option( 'codir2me_reprocessing_image_ids' );

		// Atualizar status.
		$reprocessing_status                = get_option( 'codir2me_reprocessing_status', array() );
		$reprocessing_status['in_progress'] = false;
		$reprocessing_status['paused']      = false;
		update_option( 'codir2me_reprocessing_status', $reprocessing_status );
	}

	/**
	 * Inicia o processamento usando o intervalo recomendado.
	 *
	 * @param string $mode Modo de processamento ('realtime' ou 'cron').
	 * @return bool True se iniciado com sucesso.
	 */
	public function codir2me_start_processing( $mode = 'realtime' ) {
		if ( 'cron' === $mode ) {
			// Usar intervalo personalizável, padrão 15 minutos.
			$interval = apply_filters( 'codir2me_background_cron_schedule', 'codir2me_fifteen_minutes' );

			if ( ! wp_next_scheduled( 'codir2me_background_reprocessing_event' ) ) {
				wp_schedule_event( time(), $interval, 'codir2me_background_reprocessing_event' );
				return true;
			}
		}

		return false;
	}
}
