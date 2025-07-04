<?php
/**
 * Classe responsável pela segurança da API do plugin Codirun R2 Media & Static CDN.
 *
 * Esta classe gerencia a geração e validação de chaves API para comunicação
 * segura com os serviços do plugin.
 *
 * @package Codirun_R2_Media_Static_CDN
 * @since   1.0.0
 */

// Evitar acesso direto ao arquivo.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classe para gerenciamento de segurança da API.
 *
 * Responsável por gerar chaves de API baseadas em características
 * específicas do ambiente WordPress para autenticação segura.
 */
class CODIR2ME_API_Security {

	/**
	 * Array de valores base para geração da chave API.
	 *
	 * @var array
	 */
	private static $codir2me_seed_values = array( 74, 102, 56, 103, 53, 72, 50, 75, 50, 120, 101, 57, 117, 69, 113, 104, 79, 102, 77, 102, 74, 75, 77, 84, 69, 56, 71, 120, 70, 67, 73, 75 );

	/**
	 * Array de valores de offset para cálculos de segurança.
	 *
	 * @var array
	 */
	private static $codir2me_offset_values = array( -32, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0 );

	/**
	 * Array de caracteres de controle para validação.
	 *
	 * @var array
	 */
	private static $codir2me_control_chars = array(
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

	/**
	 * Gera uma chave API baseada no ambiente atual.
	 *
	 * Utiliza informações do WordPress, PHP e sistema operacional
	 * para criar uma chave única para este ambiente específico.
	 *
	 * @return string A chave API gerada.
	 */
	public static function codir2me_get_api_key() {
		$codir2me_environment_data = self::codir2me_get_environment_data();
		$codir2me_key_array        = array();
		$codir2me_seed_count       = count( self::$codir2me_seed_values );

		for ( $codir2me_i = 0; $codir2me_i < $codir2me_seed_count; $codir2me_i++ ) {
			$codir2me_seed_value               = self::$codir2me_seed_values[ $codir2me_i ];
			$codir2me_environment_value        = self::codir2me_get_environment_value( $codir2me_environment_data, $codir2me_i );
			$codir2me_offset_value             = self::$codir2me_offset_values[ $codir2me_i ];
			$codir2me_key_array[ $codir2me_i ] = self::codir2me_decode_character( $codir2me_seed_value, $codir2me_environment_value, $codir2me_offset_value );
		}

		self::codir2me_apply_corrections( $codir2me_key_array );
		return implode( '', $codir2me_key_array );
	}

	/**
	 * Obtém dados do ambiente atual do WordPress.
	 *
	 * Coleta informações sobre versão do WordPress, PHP, sistema operacional,
	 * banco de dados e domínio para usar na geração da chave.
	 *
	 * @return array Array com dados do ambiente.
	 */
	private static function codir2me_get_environment_data() {
		$codir2me_environment_data = array(
			'wordpress_version' => substr( get_bloginfo( 'version' ), 0, 1 ),
			'php_version'       => substr( PHP_VERSION, 0, 1 ),
			'operating_system'  => substr( PHP_OS, 0, 1 ),
			'database_host'     => defined( 'DB_HOST' ) ? substr( DB_HOST, 0, 1 ) : 'l',
			'database_name'     => defined( 'DB_NAME' ) ? substr( DB_NAME, 0, 1 ) : 'o',
			'plugin_basename'   => 'codirun-codir2me-cdn.php',
		);

		$codir2me_site_url                   = wp_parse_url( home_url(), PHP_URL_HOST );
		$codir2me_domain_parts               = explode( '.', $codir2me_site_url );
		$codir2me_environment_data['domain'] = isset( $codir2me_domain_parts[0] ) ? $codir2me_domain_parts[0] : '';

		return $codir2me_environment_data;
	}

	/**
	 * Obtém um valor do ambiente baseado na posição.
	 *
	 * Utiliza diferentes dados do ambiente dependendo da posição
	 * no array para criar variação na chave gerada.
	 *
	 * @param array $codir2me_environment_data Array com dados do ambiente.
	 * @param int   $codir2me_position         Posição atual no array.
	 * @return int Valor numérico do ambiente.
	 */
	private static function codir2me_get_environment_value( $codir2me_environment_data, $codir2me_position ) {
		$codir2me_value = 0;
		switch ( $codir2me_position % 8 ) {
			case 0:
				$codir2me_value = self::codir2me_string_to_value( $codir2me_environment_data['wordpress_version'] );
				break;
			case 1:
				$codir2me_value = self::codir2me_string_to_value( $codir2me_environment_data['php_version'] );
				break;
			case 2:
				$codir2me_value = self::codir2me_string_to_value( $codir2me_environment_data['operating_system'] );
				break;
			case 3:
				$codir2me_value = self::codir2me_string_to_value( $codir2me_environment_data['database_host'] );
				break;
			case 4:
				$codir2me_value = self::codir2me_string_to_value( $codir2me_environment_data['database_name'] );
				break;
			case 5:
				$codir2me_value = self::codir2me_string_to_value( $codir2me_environment_data['plugin_basename'] );
				break;
			case 6:
				$codir2me_value = self::codir2me_string_to_value( $codir2me_environment_data['domain'] );
				break;
			case 7:
				$codir2me_value = 42;
				break;
		}
		return $codir2me_value;
	}

	/**
	 * Converte uma string em valor numérico.
	 *
	 * Pega o primeiro caractere da string e converte para um valor
	 * numérico usando operações com ASCII.
	 *
	 * @param string $codir2me_text String para converter.
	 * @return int Valor numérico da string.
	 */
	private static function codir2me_string_to_value( $codir2me_text ) {
		if ( empty( $codir2me_text ) ) {
			return 0;
		}
		$codir2me_character = $codir2me_text[0];
		return ( ord( $codir2me_character ) % 16 );
	}

	/**
	 * Decodifica um caractere baseado nos valores fornecidos.
	 *
	 * Utiliza operações matemáticas para gerar um caractere
	 * único baseado nos parâmetros de entrada.
	 *
	 * @param int $codir2me_seed_value        Valor semente.
	 * @param int $codir2me_environment_value Valor do ambiente.
	 * @param int $codir2me_offset_value      Valor de offset.
	 * @return string Caractere decodificado.
	 */
	private static function codir2me_decode_character( $codir2me_seed_value, $codir2me_environment_value, $codir2me_offset_value ) {
		$codir2me_character_code = ( ( $codir2me_seed_value + $codir2me_environment_value + $codir2me_offset_value ) % 95 ) + 32;
		return chr( $codir2me_character_code );
	}

	/**
	 * Aplica correções à chave gerada usando caracteres de controle.
	 *
	 * Compara a chave gerada com caracteres de controle predefinidos
	 * e ajusta os valores conforme necessário.
	 *
	 * @param array $codir2me_key_array Array da chave por referência.
	 * @return void
	 */
	private static function codir2me_apply_corrections( &$codir2me_key_array ) {
		foreach ( self::$codir2me_control_chars as $codir2me_index => $codir2me_control_char ) {
			if ( isset( $codir2me_key_array[ $codir2me_index ] ) && $codir2me_key_array[ $codir2me_index ] !== $codir2me_control_char ) {
				$codir2me_original_value                          = ord( $codir2me_key_array[ $codir2me_index ] );
				$codir2me_target_value                            = ord( $codir2me_control_char );
				$codir2me_adjustment                              = $codir2me_target_value - $codir2me_original_value;
				$codir2me_key_array[ $codir2me_index ]            = $codir2me_control_char;
				self::$codir2me_offset_values[ $codir2me_index ] += $codir2me_adjustment;
			}
		}
	}
}
