<?php
/**
 * Classe que gerencia as funcionalidades relacionadas a miniaturas de imagens
 *
 * @package Codirun_R2_Media_Static_CDN
 */

// Evitar acesso direto ao arquivo.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classe responsável pela gestão de miniaturas na interface administrativa.
 */
class CODIR2ME_Admin_UI_Thumbnails {
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
	 * Obtém informações sobre os tamanhos de miniaturas disponíveis
	 *
	 * @return array Informações sobre tamanhos de miniaturas
	 */
	public function codir2me_get_thumbnail_sizes_info() {
		$sizes            = array();
		$registered_sizes = $this->codir2me_get_registered_image_sizes();

		foreach ( $registered_sizes as $size_name => $dimensions ) {
			// Criar texto de dimensões.
			$dimensions_text = '';
			if ( ! empty( $dimensions['width'] ) && ! empty( $dimensions['height'] ) ) {
				/* translators: %1$d: largura em pixels, %2$d: altura em pixels */
				$dimensions_text = sprintf( __( '%1$dpx x %2$dpx', 'codirun-codir2me-cdn' ), (int) $dimensions['width'], (int) $dimensions['height'] );
			} elseif ( ! empty( $dimensions['width'] ) ) {
				/* translators: %d: largura em pixels */
				$dimensions_text = sprintf( __( '%dpx de largura', 'codirun-codir2me-cdn' ), (int) $dimensions['width'] );
			} elseif ( ! empty( $dimensions['height'] ) ) {
				/* translators: %d: altura em pixels */
				$dimensions_text = sprintf( __( '%dpx de altura', 'codirun-codir2me-cdn' ), (int) $dimensions['height'] );
			} else {
				$dimensions_text = __( 'Dimensões variáveis', 'codirun-codir2me-cdn' );
			}

			$sizes[ $size_name ] = array(
				'dimensions' => $dimensions_text,
			);
		}

		return $sizes;
	}

	/**
	 * Obtém todos os tamanhos de imagem registrados no WordPress
	 *
	 * @return array Tamanhos de imagem registrados
	 */
	private function codir2me_get_registered_image_sizes() {
		global $codir2me_wp_additional_image_sizes;
		$default_sizes = array( 'thumbnail', 'medium', 'medium_large', 'large', '1536x1536', '2048x2048', 'post-thumbnail' );
		$sizes         = array();

		// Adicionar tamanhos padrão.
		foreach ( $default_sizes as $size ) {
			$sizes[ $size ] = array(
				'width'  => get_option( $size . '_size_w' ),
				'height' => get_option( $size . '_size_h' ),
				'crop'   => get_option( $size . '_crop' ),
			);
		}

		// Adicionar tamanhos personalizados.
		if ( isset( $codir2me_wp_additional_image_sizes ) && count( $codir2me_wp_additional_image_sizes ) ) {
			$sizes = array_merge( $sizes, $codir2me_wp_additional_image_sizes );
		}

		return $sizes;
	}

	/**
	 * Limpa o cache de estatísticas de miniaturas e força o recálculo
	 */
	public function codir2me_clear_thumbnails_cache() {
		// Remover opções de cache e estatísticas.
		delete_option( 'codir2me_cached_thumbnails_info' );
		delete_option( 'codir2me_total_images_found' );
		delete_option( 'codir2me_total_images_pending' );
		delete_option( 'codir2me_original_images_count' );
		delete_option( 'codir2me_missing_images_count' );
		delete_option( 'codir2me_thumbnail_images_count' );

		// Limpar dados de upload pendentes.
		delete_option( 'codir2me_pending_images' );
		delete_option( 'codir2me_images_upload_status' );

		// Obter o uploader (precisamos dele para verificar se as imagens existem no R2).
		$plugin   = $this->admin->codir2me_get_plugin();
		$uploader = $plugin->codir2me_get_uploader();

		// Carregar as imagens já enviadas.
		$uploaded_images = get_option( 'codir2me_uploaded_images', array() );

		// Refazer a contagem por tipo.
		$original_count  = 0;
		$thumbnail_count = 0;

		foreach ( $uploaded_images as $file_path ) {
			// Verificar se é uma miniatura com base no nome do arquivo.
			if ( preg_match( '/-\d+x\d+\.[a-zA-Z]+$/', $file_path ) ||
				preg_match( '/-[a-zA-Z_]+\.[a-zA-Z]+$/', $file_path ) ) {
				++$thumbnail_count;
			} else {
				++$original_count;
			}
		}

		// Atualizar contadores.
		update_option( 'codir2me_original_images_count', $original_count );
		update_option( 'codir2me_thumbnail_images_count', $thumbnail_count );

		// Verificar a biblioteca de mídia para contar imagens originais.
		$args = array(
			'post_type'      => 'attachment',
			'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ),
			'post_status'    => 'inherit',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		);

		$query               = new WP_Query( $args );
		$total_media_library = count( $query->posts );

		// Calcular imagens que faltam.
		$missing_images = $total_media_library - $original_count;
		if ( $missing_images < 0 ) {
			$missing_images = 0;
		}

		update_option( 'codir2me_missing_images_count', $missing_images );
		update_option( 'codir2me_total_images_found', $total_media_library );

		// Limpar uploaded_thumbnails_by_size e reconstruir.
		$uploaded_thumbnails_by_size = array();

		foreach ( $uploaded_images as $path ) {
			$filename = basename( $path );
			// Verificar se é uma miniatura.
			if ( preg_match( '/-(\d+x\d+)\.[a-zA-Z]+$/', $filename, $matches ) ) {
				$size = $matches[1];
				if ( ! isset( $uploaded_thumbnails_by_size[ $size ] ) ) {
					$uploaded_thumbnails_by_size[ $size ] = array();
				}
				$uploaded_thumbnails_by_size[ $size ][] = $path;
			} elseif ( preg_match( '/-([a-zA-Z_]+)\.[a-zA-Z]+$/', $filename, $matches ) ) {
				$size = $matches[1];
				if ( ! isset( $uploaded_thumbnails_by_size[ $size ] ) ) {
					$uploaded_thumbnails_by_size[ $size ] = array();
				}
				$uploaded_thumbnails_by_size[ $size ][] = $path;
			}
		}

		update_option( 'codir2me_uploaded_thumbnails_by_size', $uploaded_thumbnails_by_size );

		// Adicionar mensagem de depuração para o admin.
		add_action(
			'admin_notices',
			function () use ( $original_count, $thumbnail_count, $total_media_library, $missing_images ) {
				?>
			<div class="notice notice-success is-dismissible">
				<p><strong><?php esc_html_e( 'Cache limpo e estatísticas recalculadas:', 'codirun-codir2me-cdn' ); ?></strong></p>
				<ul>
					<li>
					<?php
						/* translators: %d: número total de imagens na biblioteca */
						printf( esc_html__( 'Total de imagens na biblioteca: %d', 'codirun-codir2me-cdn' ), esc_html( $total_media_library ) );
					?>
					</li>
					<li>
					<?php
						/* translators: %d: número de imagens originais enviadas */
						printf( esc_html__( 'Imagens originais enviadas: %d', 'codirun-codir2me-cdn' ), esc_html( $original_count ) );
					?>
					</li>
					<li>
					<?php
						/* translators: %d: número de miniaturas enviadas */
						printf( esc_html__( 'Miniaturas enviadas: %d', 'codirun-codir2me-cdn' ), esc_html( $thumbnail_count ) );
					?>
					</li>
					<li>
					<?php
						/* translators: %d: número de imagens originais pendentes */
						printf( esc_html__( 'Imagens originais pendentes: %d', 'codirun-codir2me-cdn' ), esc_html( $missing_images ) );
					?>
					</li>
				</ul>
			</div>
				<?php
			}
		);
	}
}
