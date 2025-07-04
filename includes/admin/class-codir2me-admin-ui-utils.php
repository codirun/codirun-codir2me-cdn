<?php
/**
 * Classe de utilitários para a interface de administração
 *
 * @package Codirun_R2_Media_Static_CDN
 */

// Evitar acesso direto ao arquivo.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classe utilitária para funcionalidades da interface de administração
 */
class CODIR2ME_Admin_UI_Utils {
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
	 * Formata um tamanho em bytes para uma representação legível
	 *
	 * @param int $bytes Tamanho em bytes.
	 * @param int $precision Precisão decimal.
	 * @return string Tamanho formatado
	 */
	public function codir2me_format_file_size( $bytes, $precision = 2 ) {
		$units = array(
			__( 'B', 'codirun-codir2me-cdn' ),
			__( 'KB', 'codirun-codir2me-cdn' ),
			__( 'MB', 'codirun-codir2me-cdn' ),
			__( 'GB', 'codirun-codir2me-cdn' ),
			__( 'TB', 'codirun-codir2me-cdn' ),
		);

		$bytes = max( $bytes, 0 );
		$pow   = floor( ( $bytes ? log( $bytes ) : 0 ) / log( 1024 ) );
		$pow   = min( $pow, count( $units ) - 1 );

		$bytes /= pow( 1024, $pow );

		return round( $bytes, $precision ) . ' ' . $units[ $pow ];
	}

	/**
	 * Calcula o progresso de upload
	 *
	 * @param int $processed Número de itens processados.
	 * @param int $total Número total de itens.
	 * @return int Porcentagem de progresso
	 */
	public function codir2me_calculate_progress( $processed, $total ) {
		if ( $total <= 0 ) {
			return 0;
		}

		return min( 100, round( ( $processed / $total ) * 100 ) );
	}

	/**
	 * Calcula o tempo estimado restante
	 *
	 * @param int $start_time Timestamp de início.
	 * @param int $processed Número de itens processados.
	 * @param int $total Número total de itens.
	 * @return string Tempo estimado formatado
	 */
	public function codir2me_calculate_eta( $start_time, $processed, $total ) {
		if ( $processed <= 0 || $total <= 0 ) {
			return __( 'Calculando...', 'codirun-codir2me-cdn' );
		}

		$elapsed = time() - $start_time;
		$rate    = $processed / $elapsed; // Itens por segundo.

		if ( $rate <= 0 ) {
			return __( 'Calculando...', 'codirun-codir2me-cdn' );
		}

		$remaining_items = $total - $processed;
		$eta_seconds     = $remaining_items / $rate;

		// Formatar o tempo estimado.
		if ( $eta_seconds < 60 ) {
			/* translators: %d: número de segundos restantes */
			return sprintf( __( '%d segundos', 'codirun-codir2me-cdn' ), round( $eta_seconds ) );
		} elseif ( $eta_seconds < 3600 ) {
			/* translators: %d: número de minutos restantes */
			return sprintf( __( '%d minutos', 'codirun-codir2me-cdn' ), round( $eta_seconds / 60 ) );
		} else {
			$hours   = floor( $eta_seconds / 3600 );
			$minutes = round( ( $eta_seconds % 3600 ) / 60 );
			/* translators: %1$d: horas, %2$d: minutos */
			return sprintf( __( '%1$dh %2$dm', 'codirun-codir2me-cdn' ), $hours, $minutes );
		}
	}

	/**
	 * Sanitiza um nome de arquivo
	 *
	 * @param string $filename Nome do arquivo.
	 * @return string Nome sanitizado
	 */
	public function codir2me_sanitize_filename( $filename ) {
		// Remover caracteres especiais.
		$filename = preg_replace( '/[^a-zA-Z0-9\-_.\/]/', '', $filename );
		// Remover múltiplos pontos.
		$filename = preg_replace( '/\.+/', '.', $filename );
		// Remover espaços múltiplos.
		$filename = preg_replace( '/\s+/', '_', $filename );

		return $filename;
	}

	/**
	 * Verifica se uma URL é externa
	 *
	 * @param string $url URL para verificar.
	 * @return bool True se for externa, False caso contrário
	 */
	public function codir2me_is_external_url( $url ) {
		$home_url    = home_url();
		$home_domain = wp_parse_url( $home_url, PHP_URL_HOST );
		$url_domain  = wp_parse_url( $url, PHP_URL_HOST );

		return ( $url_domain !== $home_domain );
	}

	/**
	 * Obtém a extensão de um arquivo
	 *
	 * @param string $filename Nome do arquivo.
	 * @return string Extensão do arquivo
	 */
	public function codir2me_get_file_extension( $filename ) {
		return strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
	}

	/**
	 * Determina o tipo MIME de um arquivo com base em sua extensão
	 *
	 * @param string $filename Nome do arquivo.
	 * @return string Tipo MIME
	 */
	public function codir2me_get_mime_type_from_extension( $filename ) {
		$ext = $this->codir2me_get_file_extension( $filename );

		$mime_types = array(
			'js'    => 'application/javascript',
			'css'   => 'text/css',
			'svg'   => 'image/svg+xml',
			'woff'  => 'font/woff',
			'woff2' => 'font/woff2',
			'ttf'   => 'font/ttf',
			'eot'   => 'application/vnd.ms-fontobject',
			'jpg'   => 'image/jpeg',
			'jpeg'  => 'image/jpeg',
			'png'   => 'image/png',
			'gif'   => 'image/gif',
			'webp'  => 'image/webp',
			'avif'  => 'image/avif',
		);

		return isset( $mime_types[ $ext ] ) ? $mime_types[ $ext ] : 'application/octet-stream';
	}

	/**
	 * Verifica se um arquivo é de mídia estática
	 *
	 * @param string $filename Nome do arquivo.
	 * @return bool True se for mídia estática, False caso contrário
	 */
	public function codir2me_is_static_media_file( $filename ) {
		$ext         = $this->codir2me_get_file_extension( $filename );
		$static_exts = array( 'js', 'css', 'svg', 'woff', 'woff2', 'ttf', 'eot' );

		return in_array( $ext, $static_exts, true );
	}

	/**
	 * Verifica se um arquivo é uma imagem
	 *
	 * @param string $filename Nome do arquivo.
	 * @return bool True se for uma imagem, False caso contrário
	 */
	public function codir2me_is_image_file( $filename ) {
		$ext        = $this->codir2me_get_file_extension( $filename );
		$image_exts = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif' );

		return in_array( $ext, $image_exts, true );
	}

	/**
	 * Converte um caminho absoluto para relativo
	 * CORREÇÃO: Usa função helper segura
	 *
	 * @param string $path Caminho absoluto.
	 * @return string Caminho relativo
	 */
	public function codir2me_absolute_to_relative_path( $path ) {
		return codir2me_get_relative_path( $path );
	}

	/**
	 * Gera um slug a partir de um texto
	 *
	 * @param string $text Texto.
	 * @return string Slug
	 */
	public function codir2me_generate_slug( $text ) {
		// Remover acentos.
		$text = remove_accents( $text );
		// Converter para minúsculas.
		$text = strtolower( $text );
		// Remover caracteres especiais.
		$text = preg_replace( '/[^a-z0-9\-]/', '-', $text );
		// Remover hífens múltiplos.
		$text = preg_replace( '/-+/', '-', $text );
		// Remover hífens do início e fim.
		$text = trim( $text, '-' );

		return $text;
	}

	/**
	 * Renderiza um componente de alerta
	 *
	 * @param string $message Mensagem.
	 * @param string $type Tipo (success, warning, error, info).
	 * @param bool   $is_dismissible Se o alerta pode ser fechado.
	 */
	public function codir2me_render_alert( $message, $type = 'info', $is_dismissible = true ) {
		$class = 'notice notice-' . $type;
		if ( $is_dismissible ) {
			$class .= ' is-dismissible';
		}
		?>
		<div class="<?php echo esc_attr( $class ); ?>">
			<p><?php echo wp_kses_post( $message ); ?></p>
		</div>
		<?php
	}

	/**
	 * Renderiza um botão de ajuda com tooltip
	 *
	 * @param string $text Texto do tooltip.
	 */
	public function codir2me_render_help_tooltip( $text ) {
		?>
		<span class="codir2me-help-tooltip" title="<?php echo esc_attr( $text ); ?>">
			<span class="dashicons dashicons-editor-help"></span>
		</span>
		<?php
	}

	/**
	 * Gera um token aleatório
	 *
	 * @param int $length Comprimento do token.
	 * @return string Token
	 */
	public function codir2me_generate_random_token( $length = 16 ) {
		return bin2hex( random_bytes( $length / 2 ) );
	}

	/**
	 * Verifica se o ambiente está em modo de debug
	 *
	 * @return bool True se estiver em modo de debug, False caso contrário
	 */
	public function codir2me_is_debug_mode() {
		return ( defined( 'WP_DEBUG' ) && WP_DEBUG );
	}

	/**
	 * Obtém a versão do WordPress
	 *
	 * @return string Versão do WordPress
	 */
	public function codir2me_get_wordpress_version() {
		global $wp_version;
		return $wp_version;
	}

	/**
	 * Obtém informações do sistema
	 *
	 * @return array Informações do sistema
	 */
	public function codir2me_get_system_info() {
		global $wpdb;

		return array(
			'wordpress_version'      => $this->codir2me_get_wordpress_version(),
			'php_version'            => phpversion(),
			'mysql_version'          => $wpdb->db_version(),
			'server_software'        => isset( $_SERVER['SERVER_SOFTWARE'] )
				? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) )
				: '',
			'memory_limit'           => ini_get( 'memory_limit' ),
			'max_execution_time'     => ini_get( 'max_execution_time' ),
			'post_max_size'          => ini_get( 'post_max_size' ),
			'upload_max_filesize'    => ini_get( 'upload_max_filesize' ),
			'codir2me_is_debug_mode' => $this->codir2me_is_debug_mode(),
			'is_multisite'           => is_multisite(),
		);
	}
}
