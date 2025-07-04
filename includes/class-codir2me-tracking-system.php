<?php
/**
 * Sistema de Tracking Universal para Plugins Codirun - VERSÃO R2 CDN
 *
 * @package Codirun_R2_Media_Static_CDN
 * @since   1.0.0
 */

// Evitar acesso direto.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gera API Key diretamente (sem depender de classes)
 */
function codir2me_tracking_generate_api_key() {
	// Valores fixos para geração da chave API (copiados da classe CODIR2ME_API_Security).
	$seed_values   = array( 74, 102, 56, 103, 53, 72, 50, 75, 50, 120, 101, 57, 117, 69, 113, 104, 79, 102, 77, 102, 74, 75, 77, 84, 69, 56, 71, 120, 70, 67, 73, 75 );
	$offset_values = array( -32, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0 );
	$control_chars = array(
		0  => 'h',
		1  => 'f',
		2  => '8',
		3  => 'g',
		4  => '5',
		5  => 'H',
		6  => '2',
		7  => 'K',
		8  => '2',
		9  => 'x',
		10 => 'e',
		11 => '9',
		12 => 'u',
		13 => 'E',
		14 => 'q',
		15 => 'h',
		16 => 'O',
		17 => 'f',
		18 => 'M',
		19 => 'f',
		20 => 'J',
		21 => 'K',
		22 => 'M',
		23 => 'T',
		24 => 'E',
		25 => '8',
		26 => 'G',
		27 => 'x',
		28 => 'F',
		29 => 'C',
		30 => 'I',
		31 => 'K',
	);

	// Obter dados do ambiente.
	$environment_data = array(
		'wordpress_version' => substr( get_bloginfo( 'version' ), 0, 1 ),
		'php_version'       => substr( PHP_VERSION, 0, 1 ),
		'operating_system'  => substr( PHP_OS, 0, 1 ),
		'database_host'     => defined( 'DB_HOST' ) ? substr( DB_HOST, 0, 1 ) : 'l',
		'database_name'     => defined( 'DB_NAME' ) ? substr( DB_NAME, 0, 1 ) : 'o',
		'plugin_basename'   => 'codirun-codir2me-cdn.php',
	);

	$site_url                   = wp_parse_url( home_url(), PHP_URL_HOST );
	$domain_parts               = explode( '.', $site_url );
	$environment_data['domain'] = isset( $domain_parts[0] ) ? $domain_parts[0] : '';

	$key_array  = array();
	$seed_count = count( $seed_values );

	for ( $i = 0; $i < $seed_count; $i++ ) {
		$seed_value = $seed_values[ $i ];

		// Calcular valor do ambiente.
		$env_value = 0;
		switch ( $i % 8 ) {
			case 0:
				$env_value = codir2me_tracking_string_to_value( $environment_data['wordpress_version'] );
				break;
			case 1:
				$env_value = codir2me_tracking_string_to_value( $environment_data['php_version'] );
				break;
			case 2:
				$env_value = codir2me_tracking_string_to_value( $environment_data['operating_system'] );
				break;
			case 3:
				$env_value = codir2me_tracking_string_to_value( $environment_data['database_host'] );
				break;
			case 4:
				$env_value = codir2me_tracking_string_to_value( $environment_data['database_name'] );
				break;
			case 5:
				$env_value = codir2me_tracking_string_to_value( $environment_data['plugin_basename'] );
				break;
			case 6:
				$env_value = codir2me_tracking_string_to_value( $environment_data['domain'] );
				break;
			case 7:
				$env_value = 42;
				break;
		}

		$offset_value    = $offset_values[ $i ];
		$character_code  = ( ( $seed_value + $env_value + $offset_value ) % 95 ) + 32;
		$key_array[ $i ] = chr( $character_code );
	}

	// Aplicar correções.
	foreach ( $control_chars as $index => $control_char ) {
		if ( isset( $key_array[ $index ] ) && $key_array[ $index ] !== $control_char ) {
			$original_value           = ord( $key_array[ $index ] );
			$target_value             = ord( $control_char );
			$adjustment               = $target_value - $original_value;
			$key_array[ $index ]      = $control_char;
			$offset_values[ $index ] += $adjustment;
		}
	}

	return implode( '', $key_array );
}

/**
 * Converte uma string em valor numérico.
 *
 * @param string $text String para converter.
 * @return int Valor numérico da string.
 */
function codir2me_tracking_string_to_value( $text ) {
	if ( empty( $text ) ) {
		return 0;
	}
	$character = $text[0];
	return ( ord( $character ) % 16 );
}

/**
 * Envia ping para o Worker do sistema de licenças
 *
 * @since 1.0.0
 * @param string $status Status do plugin ('ativo' ou 'inativo').
 * @param array  $plugin_config Configurações do plugin.
 * @param bool   $immediate Se deve tentar enviar imediatamente.
 * @return void
 */
function codir2me_tracking_send_ping( $status, $plugin_config, $immediate = true ) {
	// Verificar se é admin e tem permissão.
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	// Validação do status.
	if ( ! in_array( $status, array( 'ativo', 'inativo' ), true ) ) {
		return;
	}

	// Validar configuração do plugin.
	if ( ! is_array( $plugin_config ) || empty( $plugin_config['name'] ) ) {
		return;
	}

	// Prepara dados para envio.
	$data = array(
		'action'         => 'track_plugin',
		'site_url'       => home_url(),
		'plugin_name'    => $plugin_config['name'],
		'plugin_slug'    => isset( $plugin_config['slug'] ) ? $plugin_config['slug'] : '',
		'status'         => $status,
		'plugin_version' => isset( $plugin_config['version'] ) ? $plugin_config['version'] : '1.0.0',
		'wp_version'     => get_bloginfo( 'version' ),
		'php_version'    => PHP_VERSION,
		'timestamp'      => time(),
	);

	if ( $immediate ) {
		// Tentativa imediata (não bloqueia ativação).
		$success = codir2me_tracking_send_request( $data, false );

		if ( ! $success ) {
			// Se falhou, agenda para tentar no dia seguinte.
			codir2me_tracking_schedule_retry( $data );
		}
	} else {
		// Tentativa com retry (usado pelos hooks agendados).
		codir2me_tracking_send_request( $data, true );
	}
}

/**
 * Envia requisição para o Worker usando a mesma API Key das licenças
 *
 * @since 1.0.0
 * @param array $data Dados para enviar.
 * @param bool  $blocking Se deve bloquear até resposta.
 * @return bool Sucesso ou falha.
 */
function codir2me_tracking_send_request( $data, $blocking = false ) {
	// Obter URL e API key da infraestrutura existente.
	$api_url = '';
	$api_key = '';

	// Verificar se existe o gerenciador de licenças.
	if ( class_exists( 'CODIR2ME_License_Manager' ) ) {
		// Usar a mesma instância global.
		global $codir2me_license_manager;

		if ( isset( $codir2me_license_manager ) ) {
			$license_manager = $codir2me_license_manager;
		} else {
			$license_manager = new CODIR2ME_License_Manager();
		}

		// Usar reflexão para acessar propriedades privadas.
		try {
			$reflection = new ReflectionClass( $license_manager );

			if ( $reflection->hasProperty( 'api_url' ) ) {
				$api_url_property = $reflection->getProperty( 'api_url' );
				$api_url_property->setAccessible( true );
				$api_url = rtrim( $api_url_property->getValue( $license_manager ), '/' );
			}

			if ( $reflection->hasProperty( 'api_key' ) ) {
				$api_key_property = $reflection->getProperty( 'api_key' );
				$api_key_property->setAccessible( true );
				$api_key = $api_key_property->getValue( $license_manager );
			}
		} catch ( Exception $e ) {
			// Ignorar erro silenciosamente.
			unset( $e );
		}
	}

	// Fallback para configurações se não conseguir acessar.
	if ( empty( $api_url ) ) {
		$api_url = 'https://r2cdn.codirun.com';
	}

	if ( empty( $api_key ) ) {
		// Tentar a classe de segurança da API primeiro.
		if ( class_exists( 'CODIR2ME_API_Security' ) ) {
			try {
				$api_key = CODIR2ME_API_Security::codir2me_get_api_key();
			} catch ( Exception $e ) {
				// Ignorar erro e usar fallback.
				unset( $e );
			}
		}

		// Se ainda estiver vazio, gerar diretamente.
		if ( empty( $api_key ) ) {
			$api_key = codir2me_tracking_generate_api_key();
		}
	}

	if ( empty( $api_key ) ) {
		return false;
	}

	// URL completa para tracking.
	$tracking_url = $api_url . '/plugin-tracking';

	// Configurações da requisição.
	$args = array(
		'method'    => 'POST',
		'timeout'   => $blocking ? 30 : 15,
		'blocking'  => $blocking,
		'headers'   => array(
			'Content-Type'  => 'application/json',
			'Authorization' => 'Bearer ' . $api_key,
			'User-Agent'    => 'CodirunR2CDN/' . ( isset( $data['plugin_version'] ) ? $data['plugin_version'] : '1.0.0' ),
		),
		'body'      => wp_json_encode( $data ),
		'sslverify' => true,
	);

	// Envia requisição.
	$response = wp_remote_post( $tracking_url, $args );

	// Verifica código de resposta.
	if ( is_wp_error( $response ) ) {
		return false;
	}

	$response_code = wp_remote_retrieve_response_code( $response );
	if ( $response_code >= 200 && $response_code < 300 ) {
		return true;
	}

	return false;
}

/**
 * Agenda retry para o dia seguinte
 *
 * @since 1.0.0
 * @param array $data Dados para reenviar.
 * @return void
 */
function codir2me_tracking_schedule_retry( $data ) {
	// Salva dados para retry.
	$pending_pings   = get_option( 'codir2me_tracking_pending_pings', array() );
	$pending_pings[] = array(
		'data'      => $data,
		'attempts'  => 0,
		'scheduled' => time(),
		'next_try'  => strtotime( '+1 day' ), // Próxima tentativa no dia seguinte.
	);

	update_option( 'codir2me_tracking_pending_pings', $pending_pings );

	// Agenda evento cron diário se não existir.
	if ( ! wp_next_scheduled( 'codir2me_tracking_retry_event' ) ) {
		wp_schedule_event( strtotime( '+1 day' ), 'daily', 'codir2me_tracking_retry_event' );
	}
}

/**
 * Processa pings pendentes - executa uma vez por dia
 *
 * @since 1.0.0
 * @return void
 */
function codir2me_tracking_process_pending_pings() {
	$pending_pings = get_option( 'codir2me_tracking_pending_pings', array() );

	if ( empty( $pending_pings ) ) {
		return;
	}

	$successful_pings = array();
	$remaining_pings  = array();
	$current_time     = time();

	foreach ( $pending_pings as $ping ) {
		// Só processa se chegou a hora da próxima tentativa.
		if ( $current_time < $ping['next_try'] ) {
			$remaining_pings[] = $ping;
			continue;
		}

		++$ping['attempts'];

		// Máximo 7 tentativas (uma semana).
		if ( $ping['attempts'] > 7 ) {
			// Descarta este ping após 7 dias tentando.
			continue;
		}

		// Tenta enviar.
		$success = codir2me_tracking_send_request( $ping['data'], true );

		if ( $success ) {
			$successful_pings[] = $ping;
			// Remove da lista - sucesso!
		} else {
			// Agenda nova tentativa para o próximo dia.
			$ping['next_try']  = strtotime( '+1 day', $current_time );
			$remaining_pings[] = $ping;
		}

		// Pausa entre requisições para não sobrecarregar.
		sleep( 2 );
	}

	// Atualiza lista de pings pendentes.
	update_option( 'codir2me_tracking_pending_pings', $remaining_pings );

	// Se não há mais pings pendentes, cancela o cron.
	if ( empty( $remaining_pings ) ) {
		$timestamp = wp_next_scheduled( 'codir2me_tracking_retry_event' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'codir2me_tracking_retry_event' );
		}
	}
}

/**
 * Registra hooks de ativação com tracking
 *
 * @since 1.0.0
 * @param string $plugin_file Arquivo principal do plugin (__FILE__).
 * @param array  $plugin_config Configurações do plugin.
 * @return void
 */
function codir2me_tracking_register_activation_hooks( $plugin_file, $plugin_config ) {
	// Hook de ativação.
	register_activation_hook(
		$plugin_file,
		function () use ( $plugin_config ) {
			// Verificar nonce se vier de formulário admin.
			if ( isset( $_REQUEST['_wpnonce'] ) ) {
				$nonce = sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) );
				if ( ! wp_verify_nonce( $nonce, 'activate-plugin_' . plugin_basename( $plugin_file ) ) ) {
					return;
				}
			}

			codir2me_tracking_send_ping( 'ativo', $plugin_config );
		}
	);

	// Hook de desativação.
	register_deactivation_hook(
		$plugin_file,
		function () use ( $plugin_config ) {
			// Verificar nonce se vier de formulário admin.
			if ( isset( $_REQUEST['_wpnonce'] ) ) {
				$nonce = sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) );
				if ( ! wp_verify_nonce( $nonce, 'deactivate-plugin_' . plugin_basename( $plugin_file ) ) ) {
					return;
				}
			}

			codir2me_tracking_send_ping( 'inativo', $plugin_config );

			// Limpa pings pendentes deste plugin ao desativar.
			codir2me_tracking_cleanup_plugin_pings( $plugin_config['name'] );
		}
	);

	// Hooks adicionais para garantir que funcione.
	add_action(
		'activated_plugin',
		function ( $plugin ) use ( $plugin_file, $plugin_config ) {
			if ( plugin_basename( $plugin_file ) === $plugin ) {
				if ( ! current_user_can( 'activate_plugins' ) ) {
					return;
				}
				codir2me_tracking_send_ping( 'ativo', $plugin_config );
			}
		},
		10,
		1
	);

	add_action(
		'deactivated_plugin',
		function ( $plugin ) use ( $plugin_file, $plugin_config ) {
			if ( plugin_basename( $plugin_file ) === $plugin ) {
				if ( ! current_user_can( 'activate_plugins' ) ) {
					return;
				}
				codir2me_tracking_send_ping( 'inativo', $plugin_config );
			}
		},
		10,
		1
	);

	// Registra hook para processar pings pendentes.
	add_action( 'codir2me_tracking_retry_event', 'codir2me_tracking_process_pending_pings' );
}

/**
 * Limpa pings pendentes de um plugin específico
 *
 * @since 1.0.0
 * @param string $plugin_name Nome do plugin.
 * @return void
 */
function codir2me_tracking_cleanup_plugin_pings( $plugin_name ) {
	$pending_pings = get_option( 'codir2me_tracking_pending_pings', array() );

	if ( empty( $pending_pings ) ) {
		return;
	}

	$remaining_pings = array();
	foreach ( $pending_pings as $ping ) {
		if ( $ping['data']['plugin_name'] !== $plugin_name ) {
			$remaining_pings[] = $ping;
		}
	}

	update_option( 'codir2me_tracking_pending_pings', $remaining_pings );
}

/**
 * Obtém informações do plugin automaticamente
 *
 * @since 1.0.0
 * @param string $plugin_file Arquivo principal do plugin (__FILE__).
 * @return array Configurações do plugin.
 */
function codir2me_tracking_get_plugin_info( $plugin_file ) {
	// Tenta pegar informações do cabeçalho do plugin.
	if ( ! function_exists( 'get_plugin_data' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$plugin_data = get_plugin_data( $plugin_file );

	return array(
		'name'    => isset( $plugin_data['Name'] ) ? $plugin_data['Name'] : 'Plugin Desconhecido',
		'version' => isset( $plugin_data['Version'] ) ? $plugin_data['Version'] : '1.0.0',
		'slug'    => plugin_basename( $plugin_file ),
	);
}

/**
 * Função simplificada para configurar tracking automaticamente
 *
 * @since 1.0.0
 * @param string $plugin_file Arquivo principal do plugin (__FILE__).
 * @param array  $custom_config Configurações customizadas (opcional).
 * @return void
 */
function codir2me_tracking_setup( $plugin_file, $custom_config = array() ) {
	// Obter informações do plugin.
	$plugin_info = codir2me_tracking_get_plugin_info( $plugin_file );

	// Mesclar com configurações customizadas.
	$plugin_config = array_merge( $plugin_info, $custom_config );

	// Registrar hooks.
	codir2me_tracking_register_activation_hooks( $plugin_file, $plugin_config );
}

/**
 * Força envio de pings pendentes (função administrativa)
 *
 * @since 1.0.0
 * @return array Resultado do processamento.
 */
function codir2me_tracking_force_send_pending() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return array( 'error' => 'Permissão negada' );
	}

	$pending_pings = get_option( 'codir2me_tracking_pending_pings', array() );

	if ( empty( $pending_pings ) ) {
		return array( 'message' => 'Nenhum ping pendente' );
	}

	$count_before = count( $pending_pings );
	codir2me_tracking_process_pending_pings();
	$pending_pings_after = get_option( 'codir2me_tracking_pending_pings', array() );
	$count_after         = count( $pending_pings_after );

	return array(
		'processed' => $count_before - $count_after,
		'remaining' => $count_after,
	);
}
