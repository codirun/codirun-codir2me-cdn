<?php
/**
 * Classe responsável pela interface de administração da aba de manutenção
 *
 * @package Codirun_R2_Media_Static_CDN
 */

// Evitar acesso direto ao arquivo.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classe CODIR2ME_Admin_UI_Maintenance
 *
 * Gerencia a interface de administração da aba de manutenção do plugin
 */
class CODIR2ME_Admin_UI_Maintenance {
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
	 * Renderiza a aba de manutenção
	 */
	public function codir2me_render() {
		// Processar formulário se enviado.
		if ( isset( $_POST['codir2me_fix_submit'] ) && check_admin_referer( 'codir2me_maintenance_action' ) ) {
			$this->codir2me_process_form();
		}

		// Obter estatísticas atuais.
		$stats = $this->codir2me_get_current_stats();

		?>
		<div class="codir2me-tab-content">
			<div class="codir2me-flex-container">
				<div class="codir2me-main-column">
					<div class="codir2me-section">
						<h2><?php esc_html_e( 'Estatísticas Atuais', 'codirun-codir2me-cdn' ); ?></h2>
						
						<div class="codir2me-cdn-status-cards">
							<div class="codir2me-status-card">
								<div class="codir2me-status-icon">
									<span class="dashicons dashicons-format-image"></span>
								</div>
								<div class="codir2me-status-details">
									<h3><?php esc_html_e( 'Imagens na Biblioteca', 'codirun-codir2me-cdn' ); ?></h3>
									<p class="codir2me-status-count"><?php echo esc_html( $stats['total_in_library'] ); ?></p>
								</div>
							</div>
							
							<div class="codir2me-status-card">
								<div class="codir2me-status-icon">
									<span class="dashicons dashicons-cloud-upload"></span>
								</div>
								<div class="codir2me-status-details">
									<h3><?php esc_html_e( 'Enviadas para R2', 'codirun-codir2me-cdn' ); ?></h3>
									<p class="codir2me-status-count"><?php echo esc_html( $stats['total_uploaded'] ); ?></p>
								</div>
							</div>
							
							<div class="codir2me-status-card">
								<div class="codir2me-status-icon <?php echo ( 0 === $stats['missing_count'] || $stats['all_images_sent'] ) ? 'active' : 'inactive'; ?>">
									<span class="dashicons <?php echo ( 0 === $stats['missing_count'] || $stats['all_images_sent'] ) ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>"></span>
								</div>
								<div class="codir2me-status-details">
									<h3><?php esc_html_e( 'Imagens Faltando', 'codirun-codir2me-cdn' ); ?></h3>
									<p class="codir2me-status-count">
									<?php echo esc_html( ( 0 === $stats['missing_count'] || $stats['all_images_sent'] ) ? '0' : $stats['missing'] ); ?>
									<?php if ( $stats['missing'] !== $stats['missing_count'] && 0 === $stats['missing_count'] ) : ?>
									<span style="font-size: 12px; color: #46b450; display: block;"><?php esc_html_e( '(Todas enviadas)', 'codirun-codir2me-cdn' ); ?></span>
									<?php endif; ?>
									</p>
								</div>
							</div>
							
						</div>
						
						<table class="widefat" style="margin-top: 20px;">
							<tr>
								<th><?php esc_html_e( 'Total de imagens na biblioteca', 'codirun-codir2me-cdn' ); ?></th>
								<td><?php echo esc_html( $stats['total_in_library'] ); ?></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Total de arquivos enviados para R2', 'codirun-codir2me-cdn' ); ?></th>
								<td><?php echo esc_html( $stats['total_uploaded'] ); ?></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Imagens marcadas como faltando', 'codirun-codir2me-cdn' ); ?></th>
								<td><?php echo esc_html( $stats['missing'] ); ?></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Contadores atuais', 'codirun-codir2me-cdn' ); ?></th>
								<td>
								<?php
									printf(
										/* translators: %1$s is original count, %2$s is missing count */
										esc_html__( 'Original: %1$s, Faltando: %2$s', 'codirun-codir2me-cdn' ),
										esc_html( $stats['original_count'] ),
										esc_html( $stats['missing_count'] )
									);
								?>
									</td>
							</tr>
						</table>
						<?php if ( $stats['all_images_sent'] || 0 === $stats['missing_count'] ) : ?>
							<div style="margin-top: 20px; padding: 10px 15px; background-color: #ecf7ed; border-left: 4px solid #46b450; border-radius: 3px;">
								<p><strong>✅ <?php esc_html_e( 'Status:', 'codirun-codir2me-cdn' ); ?></strong> <?php esc_html_e( 'Todas as imagens foram marcadas como enviadas. O sistema não mostrará imagens pendentes.', 'codirun-codir2me-cdn' ); ?></p>
							</div>
						<?php elseif ( $stats['missing'] > 0 ) : ?>
							<div style="margin-top: 20px; padding: 10px 15px; background-color: #fff8e5; border-left: 4px solid #ffb900; border-radius: 3px;">
								<p><strong>⚠️ <?php esc_html_e( 'Status:', 'codirun-codir2me-cdn' ); ?></strong> 
								<?php
									/* translators: %s is the number of missing images */
									printf( esc_html__( 'O sistema ainda mostra que faltam %s imagens para enviar. Use as ferramentas de correção abaixo para resolver o problema.', 'codirun-codir2me-cdn' ), esc_html( $stats['missing'] ) );
								?>
												</p>
							</div>
						<?php endif; ?>
					</div>
					
						<div class="codir2me-section">
						<h2><?php esc_html_e( 'Limpar Arquivos Temporários Duplicados', 'codirun-codir2me-cdn' ); ?></h2>
						<p><?php esc_html_e( 'Esta ferramenta remove arquivos AVIF e WebP duplicados que podem ter sido criados durante o reprocessamento de imagens.', 'codirun-codir2me-cdn' ); ?></p>
						
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'codir2me_codir2me_cleanup_duplicate_files', 'codir2me_cleanup_nonce' ); ?>
							<input type="hidden" name="action" value="codir2me_codir2me_cleanup_duplicate_files">
							<button type="submit" class="button button-primary">
								<span class="dashicons dashicons-trash"></span>
								<?php esc_html_e( 'Limpar Arquivos Duplicados', 'codirun-codir2me-cdn' ); ?>
							</button>
						</form>
						
						<?php
						if ( isset( $_GET['duplicate_cleanup'] ) && '1' === $_GET['duplicate_cleanup'] ) :
							$cleanup_result = get_option(
								'codir2me_duplicate_cleanup_result',
								array(
									'count' => 0,
									'time'  => 0,
								)
							);
							?>
						<div class="notice notice-success inline" style="margin-top: 15px;">
							<p>
								<?php
									/* translators: %s is the number of files */
									printf( esc_html__( '%s arquivos duplicados foram removidos com sucesso!', 'codirun-codir2me-cdn' ), esc_html( $cleanup_result['count'] ) );
								?>
								<?php if ( isset( $cleanup_result['size'] ) && $cleanup_result['size'] > 0 ) : ?>
								(
									<?php
									printf(
										/* translators: %s is the size in bytes */
										esc_html__( '%s liberados', 'codirun-codir2me-cdn' ),
										esc_html( size_format( $cleanup_result['size'] ) )
									);
									?>
									)
								<?php endif; ?>
								<small>(<?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $cleanup_result['time'] ) ); ?>)</small>
							</p>
						</div>
						<?php endif; ?>
					</div>
					
					<div class="codir2me-section">
						<h2><?php esc_html_e( 'Ferramentas de Manutenção', 'codirun-codir2me-cdn' ); ?></h2>
						
						<form method="post" action="">
							<?php wp_nonce_field( 'codir2me_maintenance_action' ); ?>
							
							<div style="margin-bottom: 15px;">
								<h3><?php esc_html_e( 'Correção de Contagem', 'codirun-codir2me-cdn' ); ?></h3>
								<p><?php esc_html_e( 'Estas opções ajudam a corrigir problemas quando o sistema mostra que faltam imagens, mesmo depois de enviar tudo.', 'codirun-codir2me-cdn' ); ?></p>
								
								<label>
									<input type="checkbox" name="codir2me_mark_complete" value="1" checked>
									<?php esc_html_e( 'Marcar todas as imagens originais como enviadas', 'codirun-codir2me-cdn' ); ?>
								</label><br><br>
								
								<label>
									<input type="checkbox" name="codir2me_clear_pending" value="1" checked>
									<?php esc_html_e( 'Limpar lista de uploads pendentes', 'codirun-codir2me-cdn' ); ?>
								</label><br><br>
								
								<label>
									<input type="checkbox" name="codir2me_mark_all_formats" value="1" checked>
									<?php esc_html_e( 'Marcar todas as miniaturas, WebP e AVIF como enviadas', 'codirun-codir2me-cdn' ); ?>
									<p class="description" style="margin-left: 25px; color: #666; font-style: italic;">
										<?php esc_html_e( 'Use esta opção para incluir todos os formatos de imagem e miniaturas. Útil quando você já enviou suas imagens convertidas para o R2.', 'codirun-codir2me-cdn' ); ?>
									</p>
								</label>
							</div>
						
							<div style="margin-bottom: 15px;">
								<h3><?php esc_html_e( 'Reconstrução de Dados', 'codirun-codir2me-cdn' ); ?></h3>
								<p><?php esc_html_e( 'Estas opções recriam estatísticas e metadados do plugin.', 'codirun-codir2me-cdn' ); ?></p>
								
								<label>
									<input type="checkbox" name="codir2me_rebuild_stats" value="1" checked>
									<?php esc_html_e( 'Reconstruir estatísticas de miniaturas', 'codirun-codir2me-cdn' ); ?>
								</label><br><br>
								
								<label>
									<input type="checkbox" name="codir2me_clear_cache" value="1" checked>
									<?php esc_html_e( 'Limpar cache de estatísticas', 'codirun-codir2me-cdn' ); ?>
								</label><br><br>
								
								<label>
									<input type="checkbox" name="codir2me_cleanup_nonexistent" value="1" checked>
									<?php esc_html_e( 'Limpar registros de imagens inexistentes (remover do R2 registros que não existem mais na biblioteca)', 'codirun-codir2me-cdn' ); ?>
								</label>
							</div>
							
							<div style="margin-top: 20px;">
								<input type="submit" name="codir2me_fix_submit" class="button button-primary" value="<?php esc_attr_e( 'Aplicar Correções', 'codirun-codir2me-cdn' ); ?>">
							</div>
						</form>
					</div>
				</div>
				
				<?php $this->codir2me_render_sidebar(); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Limpa arquivos AVIF e WebP duplicados
	 */
	public function codir2me_cleanup_duplicate_files() {
		// Verificar nonce.
		check_admin_referer( 'codir2me_codir2me_cleanup_duplicate_files', 'codir2me_cleanup_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Acesso negado', 'codirun-codir2me-cdn' ) );
		}

		// Diretório de upload usando wp_upload_dir().
		$upload_dir = wp_upload_dir();
		$base_dir   = $upload_dir['basedir'];

		// Padrão melhorado para encontrar arquivos com prefixo hexadecimal.
		$duplicate_pattern = '/^([0-9a-f]{12}_.*\.(avif|webp))$/i';

		// Armazenar arquivos encontrados por nome base.
		$found_files   = array();
		$deleted_count = 0;
		$deleted_size  = 0;

		// Obter instância do sistema de arquivos do WordPress.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		global $wp_filesystem;
		WP_Filesystem();

		// Função recursiva para varrer diretórios.
		$scan_for_duplicates = function ( $dir, &$found_files, &$deleted_count, &$deleted_size, $duplicate_pattern ) use ( &$scan_for_duplicates, $wp_filesystem ) {
			$files = scandir( $dir );

			foreach ( $files as $file ) {
				if ( '.' === $file || '..' === $file ) {
					continue;
				}

				$path = $dir . '/' . $file;

				if ( is_dir( $path ) ) {
					// Recursivamente verificar subdiretórios.
					$scan_for_duplicates( $path, $found_files, $deleted_count, $deleted_size, $duplicate_pattern );
				} elseif ( preg_match( $duplicate_pattern, $file ) ) {
					// Extrair o nome base do arquivo (sem o prefixo hexadecimal).
					$base_name = preg_replace( '/^[0-9a-f]{12}_/', '', $file );

					// Verificar se o arquivo base (sem prefixo) existe no mesmo diretório.
					$original_path = $dir . '/' . $base_name;

					if ( file_exists( $original_path ) ) {
						// Se o arquivo original existe, podemos excluir o duplicado com prefixo.
						$file_size = filesize( $path );

						if ( $wp_filesystem->delete( $path, false, 'f' ) ) {
							++$deleted_count;
							$deleted_size += $file_size;

							if ( function_exists( 'codir2me_cdn_log' ) ) {
								codir2me_cdn_log(
									sprintf(
										/* translators: %1$s is the file path, %2$s is the file size */
										__( 'Arquivo duplicado removido: %1$s (Tamanho: %2$s)', 'codirun-codir2me-cdn' ),
										esc_html( $path ),
										esc_html( size_format( $file_size ) )
									),
									'info'
								);
							}
						}
					} else {
						// Se o arquivo original não existe, registrar para verificação.
						$dir_name = basename( $dir );
						$key      = $dir_name . '/' . $base_name;

						if ( ! isset( $found_files[ $key ] ) ) {
							$found_files[ $key ] = array();
						}

						$found_files[ $key ][] = array(
							'path' => $path,
							'time' => filemtime( $path ),
							'size' => filesize( $path ),
						);
					}
				}
			}
		};

		// Iniciar varredura do diretório de uploads.
		$scan_for_duplicates( $base_dir, $found_files, $deleted_count, $deleted_size, $duplicate_pattern );

		// Para cada conjunto de arquivos duplicados onde o original não existe,.
		// manter apenas o mais recente e excluir os demais.
		foreach ( $found_files as $base_key => $file_info_array ) {
			$file_count = count( $file_info_array );

			if ( $file_count > 0 ) {
				// Ordenar por data de modificação (mais recente primeiro).
				usort(
					$file_info_array,
					function ( $a, $b ) {
						return $b['time'] - $a['time'];
					}
				);

				// Se tivermos mais de um arquivo, manter o primeiro (mais recente) e excluir os demais.
				if ( $file_count > 1 ) {
					// Renomear o mais recente para o nome base (sem prefixo).
					$newest_file = $file_info_array[0];
					$dir_path    = dirname( $newest_file['path'] );
					$base_name   = basename( $newest_file['path'] );
					$base_name   = preg_replace( '/^[0-9a-f]{12}_/', '', $base_name );
					$target_path = $dir_path . '/' . $base_name;

					// Tentar renomear o arquivo mais recente para o nome base.
					if ( ! file_exists( $target_path ) && $wp_filesystem->move( $newest_file['path'], $target_path ) ) {
						if ( function_exists( 'codir2me_cdn_log' ) ) {
							codir2me_cdn_log(
								sprintf(
									/* translators: %1$s is the source path, %2$s is the destination path */
									__( 'Arquivo mais recente renomeado: %1$s -> %2$s', 'codirun-codir2me-cdn' ),
									esc_html( $newest_file['path'] ),
									esc_html( $target_path )
								),
								'info'
							);
						}
					}

					// Excluir todos os outros arquivos duplicados.
					for ( $i = 1; $i < $file_count; $i++ ) {
						if ( file_exists( $file_info_array[ $i ]['path'] ) ) {
							$file_size = $file_info_array[ $i ]['size'];

							if ( $wp_filesystem->delete( $file_info_array[ $i ]['path'], false, 'f' ) ) {
								++$deleted_count;
								$deleted_size += $file_size;

								if ( function_exists( 'codir2me_cdn_log' ) ) {
									codir2me_cdn_log(
										sprintf(
											/* translators: %1$s is the file path, %2$s is the file size */
											__( 'Arquivo duplicado removido: %1$s (Tamanho: %2$s)', 'codirun-codir2me-cdn' ),
											esc_html( $file_info_array[ $i ]['path'] ),
											esc_html( size_format( $file_size ) )
										),
										'info'
									);
								}
							}
						}
					}
				}
			}
		}

		// Atualizar opção para exibir resultado.
		update_option(
			'codir2me_duplicate_cleanup_result',
			array(
				'time'  => time(),
				'count' => $deleted_count,
				'size'  => $deleted_size,
			)
		);

		// Redirecionar de volta.
		wp_safe_redirect( admin_url( 'admin.php?page=codirun-codir2me-cdn-maintenance&duplicate_cleanup=1' ) );
		exit;
	}

	/**
	 * Renderiza a barra lateral
	 */
	private function codir2me_render_sidebar() {
		?>
		<div class="codir2me-sidebar">
			<div class="codir2me-sidebar-widget">
				<h3><?php esc_html_e( 'Sobre a Manutenção', 'codirun-codir2me-cdn' ); ?></h3>
				<div class="codir2me-widget-content">
					<p><?php esc_html_e( 'Esta seção oferece ferramentas para manutenção e correção do plugin Codirun R2 Media & Static CDN.', 'codirun-codir2me-cdn' ); ?></p>
					<p><?php esc_html_e( 'Use estas ferramentas quando:', 'codirun-codir2me-cdn' ); ?></p>
					<ul class="codir2me-tips-list">
						<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Encontrar problemas de contagem de imagens', 'codirun-codir2me-cdn' ); ?></li>
						<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Após grandes migrações ou restaurações', 'codirun-codir2me-cdn' ); ?></li>
						<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Para limpar dados inconsistentes', 'codirun-codir2me-cdn' ); ?></li>
						<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Quando tudo já foi enviado mas o sistema mostra pendências', 'codirun-codir2me-cdn' ); ?></li>
					</ul>
				</div>
			</div>
			
			<div class="codir2me-sidebar-widget">
				<h3><?php esc_html_e( 'Casos de Uso', 'codirun-codir2me-cdn' ); ?></h3>
				<div class="codir2me-widget-content">
					<p><strong><?php esc_html_e( 'Problema de Contagem:', 'codirun-codir2me-cdn' ); ?></strong> <?php esc_html_e( 'Quando o sistema mostra que faltam imagens mesmo após enviar tudo.', 'codirun-codir2me-cdn' ); ?></p>
					<p><strong><?php esc_html_e( 'Solução:', 'codirun-codir2me-cdn' ); ?></strong> <?php esc_html_e( 'Marque "Marcar todas as imagens como enviadas" e "Limpar lista de uploads pendentes".', 'codirun-codir2me-cdn' ); ?></p>
					<p><strong><?php esc_html_e( 'Estatísticas Incorretas:', 'codirun-codir2me-cdn' ); ?></strong> <?php esc_html_e( 'Quando os números de miniaturas estão errados.', 'codirun-codir2me-cdn' ); ?></p>
					<p><strong><?php esc_html_e( 'Solução:', 'codirun-codir2me-cdn' ); ?></strong> <?php esc_html_e( 'Marque "Reconstruir estatísticas de miniaturas" e "Limpar cache".', 'codirun-codir2me-cdn' ); ?></p>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Processa o formulário
	 */
	private function codir2me_process_form() {
		if ( ! check_admin_referer( 'codir2me_maintenance_action' ) ) {
			return;
		}

		// Obter todas as imagens da biblioteca.
		$total_images = $this->codir2me_count_images();

		// Marcar todas como enviadas se selecionado.
		if ( isset( $_POST['codir2me_mark_complete'] ) && '1' === $_POST['codir2me_mark_complete'] ) {
			update_option( 'codir2me_all_images_sent', 'true' );
			update_option( 'codir2me_original_images_count', $total_images );
			update_option( 'codir2me_missing_images_count', 0 );
		}

		// Marcar todas as miniaturas e formatos alternativos como enviados.
		if ( isset( $_POST['codir2me_mark_all_formats'] ) && '1' === $_POST['codir2me_mark_all_formats'] ) {
			$this->codir2me_mark_all_image_formats_as_sent();
		}

		// Limpar pendências se selecionado.
		if ( isset( $_POST['codir2me_clear_pending'] ) && '1' === $_POST['codir2me_clear_pending'] ) {
			delete_option( 'codir2me_pending_images' );
			delete_option( 'codir2me_images_upload_status' );
		}

		// Reconstruir estatísticas se selecionado.
		if ( isset( $_POST['codir2me_rebuild_stats'] ) && '1' === $_POST['codir2me_rebuild_stats'] ) {
			$this->codir2me_rebuild_thumbnails();
		}

		// Limpar cache se selecionado.
		if ( isset( $_POST['codir2me_clear_cache'] ) && '1' === $_POST['codir2me_clear_cache'] ) {
			delete_option( 'codir2me_cached_thumbnails_info' );
		}

		// Limpar registros de imagens inexistentes se selecionado.
		if ( isset( $_POST['codir2me_cleanup_nonexistent'] ) && '1' === $_POST['codir2me_cleanup_nonexistent'] ) {
			$cleaned = $this->codir2me_cleanup_nonexistent_images();
			update_option( 'codir2me_cleanup_results', $cleaned );
		}

		// Mostrar notificação.
		add_action(
			'admin_notices',
			function () {
				?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'As correções de manutenção foram aplicadas com sucesso!', 'codirun-codir2me-cdn' ); ?></p>
			</div>
				<?php
			}
		);
	}

	/**
	 * Limpa registros de imagens que não existem mais na biblioteca de mídia do WordPress
	 *
	 * @return array Estatísticas de limpeza
	 */
	private function codir2me_cleanup_nonexistent_images() {
		// Obter todas as imagens enviadas.
		$uploaded_images             = get_option( 'codir2me_uploaded_images', array() );
		$uploaded_thumbnails_by_size = get_option( 'codir2me_uploaded_thumbnails_by_size', array() );

		// Preparar arrays para os resultados.
		$existing_images = array();
		$removed_images  = array();

		// Obter os IDs de todas as imagens na biblioteca de mídia.
		$args = array(
			'post_type'      => 'attachment',
			'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif' ),
			'post_status'    => 'inherit',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		);

		$query          = new WP_Query( $args );
		$attachment_ids = $query->posts;

		// Obter informações dos diretórios de upload usando wp_upload_dir().
		$upload_dir      = wp_upload_dir();
		$upload_base_dir = trailingslashit( $upload_dir['basedir'] );

		// Primeiro vamos mapear todos os arquivos de imagem que existem na biblioteca.
		$valid_files = array();

		foreach ( $attachment_ids as $attachment_id ) {
			// Arquivo original.
			$file_path = get_attached_file( $attachment_id );
			if ( $file_path && file_exists( $file_path ) ) {
				// Usar função personalizada para obter caminho relativo.
				$relative_path                 = codir2me_get_relative_path( $file_path );
				$valid_files[ $relative_path ] = true;
			}

			// Miniaturas.
			$metadata = wp_get_attachment_metadata( $attachment_id );
			if ( isset( $metadata['file'] ) && isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
				$file_dir = dirname( $metadata['file'] );

				foreach ( $metadata['sizes'] as $size => $size_info ) {
					if ( isset( $size_info['file'] ) ) {
						$thumb_path = $upload_base_dir . trailingslashit( $file_dir ) . $size_info['file'];
						// Usar função personalizada para obter caminho relativo.
						$relative_thumb_path = codir2me_get_relative_path( $thumb_path );

						if ( file_exists( $thumb_path ) ) {
							$valid_files[ $relative_thumb_path ] = true;
						}
					}
				}
			}
		}

		// Verificar cada imagem registrada contra arquivos válidos.
		foreach ( $uploaded_images as $image_path ) {
			if ( isset( $valid_files[ $image_path ] ) ) {
				// A imagem existe, manter no registro.
				$existing_images[] = $image_path;
			} else {
				// A imagem não existe mais, remover do registro.
				$removed_images[] = $image_path;
			}
		}

		// Atualizar o registro de imagens enviadas.
		update_option( 'codir2me_uploaded_images', $existing_images );

		// Também limpar a lista de miniaturas por tamanho.
		foreach ( $uploaded_thumbnails_by_size as $size => $thumbnails ) {
			$uploaded_thumbnails_by_size[ $size ] = array_values( array_intersect( $thumbnails, $existing_images ) );

			// Remover tamanhos vazios.
			if ( empty( $uploaded_thumbnails_by_size[ $size ] ) ) {
				unset( $uploaded_thumbnails_by_size[ $size ] );
			}
		}

		update_option( 'codir2me_uploaded_thumbnails_by_size', $uploaded_thumbnails_by_size );

		// Atualizar contadores.
		$original_count  = 0;
		$thumbnail_count = 0;

		foreach ( $existing_images as $path ) {
			$filename = basename( $path );
			if ( preg_match( '/-\d+x\d+\.[a-zA-Z]+$/', $filename ) || preg_match( '/-[a-zA-Z_]+\.[a-zA-Z]+$/', $filename ) ) {
				++$thumbnail_count;
			} else {
				++$original_count;
			}
		}

		update_option( 'codir2me_original_images_count', $original_count );
		update_option( 'codir2me_thumbnail_images_count', $thumbnail_count );

		// Se temos menos imagens originais que na biblioteca, atualizar o contador de faltantes.
		$total_media_library = count( $attachment_ids );

		if ( $original_count >= $total_media_library ) {
			update_option( 'codir2me_all_images_sent', true );
			update_option( 'codir2me_missing_images_count', 0 );
		} else {
			update_option( 'codir2me_missing_images_count', $total_media_library - $original_count );
		}

		// Retornar estatísticas de limpeza.
		return array(
			'total_before' => count( $uploaded_images ),
			'total_after'  => count( $existing_images ),
			'removed'      => count( $removed_images ),
			'removed_list' => $removed_images,
		);
	}

	/**
	 * Contar imagens na biblioteca
	 *
	 * @return int Número de imagens na biblioteca
	 */
	private function codir2me_count_images() {
		$args = array(
			'post_type'      => 'attachment',
			'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ),
			'post_status'    => 'inherit',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		);

		$query = new WP_Query( $args );
		return count( $query->posts );
	}

	/**
	 * Reconstruir estatísticas de miniaturas
	 */
	private function codir2me_rebuild_thumbnails() {
		// Obter as imagens enviadas.
		$uploaded_images = get_option( 'codir2me_uploaded_images', array() );

		// Preparar array de miniaturas por tamanho.
		$thumbnails_by_size = array();

		// Processar cada imagem.
		foreach ( $uploaded_images as $path ) {
			$filename = basename( $path );

			// Verificar se é uma miniatura.
			if ( preg_match( '/-(\d+x\d+)\.[a-zA-Z]+$/', $filename, $matches ) ) {
				$size = $matches[1];
				if ( ! isset( $thumbnails_by_size[ $size ] ) ) {
					$thumbnails_by_size[ $size ] = array();
				}
				$thumbnails_by_size[ $size ][] = $path;
			} elseif ( preg_match( '/-([a-zA-Z_]+)\.[a-zA-Z]+$/', $filename, $matches ) ) {
				$size = $matches[1];
				if ( ! isset( $thumbnails_by_size[ $size ] ) ) {
					$thumbnails_by_size[ $size ] = array();
				}
				$thumbnails_by_size[ $size ][] = $path;
			}
		}

		// Salvar miniaturas por tamanho.
		update_option( 'codir2me_uploaded_thumbnails_by_size', $thumbnails_by_size );
	}

	/**
	 * Obter estatísticas atuais
	 *
	 * @return array Estatísticas do sistema
	 */
	private function codir2me_get_current_stats() {
		// Contar total na biblioteca de mídia.
		$total_in_library = $this->codir2me_count_images();

		// Obter estatísticas salvas.
		$original_count = get_option( 'codir2me_original_images_count', 0 );
		$missing_count  = get_option( 'codir2me_missing_images_count', 0 );

		// Verificar se todas as imagens foram marcadas como enviadas.
		$all_images_sent = get_option( 'codir2me_all_images_sent', false );

		// Obter imagens enviadas.
		$uploaded_images = get_option( 'codir2me_uploaded_images', array() );
		$total_uploaded  = count( $uploaded_images );

		// Contar originais e miniaturas.
		$originals_uploaded  = 0;
		$thumbnails_uploaded = 0;

		foreach ( $uploaded_images as $path ) {
			$filename     = basename( $path );
			$is_thumbnail = preg_match( '/-\d+x\d+\.[a-zA-Z]+$/', $filename ) ||
							preg_match( '/-[a-zA-Z_]+\.[a-zA-Z]+$/', $filename );

			if ( $is_thumbnail ) {
				++$thumbnails_uploaded;
			} else {
				++$originals_uploaded;
			}
		}

		// Usar o valor salvo de missing_count se todas as imagens foram marcadas como enviadas.
		$missing = ( $all_images_sent || 0 === $missing_count ) ? 0 : ( $total_in_library - $originals_uploaded );

		// Verificar inconsistência.
		$inconsistent = ( $originals_uploaded !== $original_count ) ||
						( $total_in_library - $originals_uploaded !== $missing_count );

		// Verificar pendentes.
		$pending_images = get_option( 'codir2me_pending_images', array() );
		$has_pending    = ! empty( $pending_images );
		$pending_count  = count( $pending_images );

		// Tamanhos de miniaturas.
		$thumbnail_sizes = get_option( 'codir2me_uploaded_thumbnails_by_size', array() );

		return array(
			'total_in_library'    => $total_in_library,
			'total_uploaded'      => $total_uploaded,
			'originals_uploaded'  => $originals_uploaded,
			'thumbnails_uploaded' => $thumbnails_uploaded,
			'missing'             => $missing, // Usar o valor corrigido.
			'original_count'      => $original_count,
			'missing_count'       => $missing_count,
			'inconsistent'        => $inconsistent,
			'has_pending'         => $has_pending,
			'pending_count'       => $pending_count,
			'thumbnail_sizes'     => $thumbnail_sizes,
			'all_images_sent'     => $all_images_sent,
		);
	}

	/**
	 * Marca todas as miniaturas e formatos alternativos como enviados
	 */
	private function codir2me_mark_all_image_formats_as_sent() {
		// Obter opções de otimização para saber quais formatos estão habilitados.
		$options     = get_option( 'codir2me_image_optimization_options', array() );
		$enable_webp = isset( $options['enable_webp_conversion'] ) ? (bool) $options['enable_webp_conversion'] : false;
		$enable_avif = isset( $options['enable_avif_conversion'] ) ? (bool) $options['enable_avif_conversion'] : false;

		// Obter a lista atual de arquivos registrados.
		$uploaded_images             = get_option( 'codir2me_uploaded_images', array() );
		$uploaded_thumbnails_by_size = get_option( 'codir2me_uploaded_thumbnails_by_size', array() );

		// Obter todas as imagens da biblioteca e suas miniaturas.
		$args = array(
			'post_type'      => 'attachment',
			'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif' ),
			'post_status'    => 'inherit',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		);

		$query             = new WP_Query( $args );
		$additional_images = array();
		$thumbnail_count   = 0;
		$webp_count        = 0;
		$avif_count        = 0;

		foreach ( $query->posts as $attachment_id ) {
			$metadata = wp_get_attachment_metadata( $attachment_id );

			if ( ! isset( $metadata['file'] ) ) {
				continue;
			}

			// Processar miniaturas.
			if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
				$upload_dir = wp_upload_dir();
				$base_dir   = trailingslashit( $upload_dir['basedir'] );
				$file_dir   = dirname( $metadata['file'] );

				foreach ( $metadata['sizes'] as $size => $size_info ) {
					if ( ! isset( $size_info['file'] ) ) {
						continue;
					}

					// Caminho completo e relativo da miniatura.
					$thumb_path          = $base_dir . trailingslashit( $file_dir ) . $size_info['file'];
					$thumb_relative_path = codir2me_get_relative_path( $thumb_path );

					if ( ! in_array( $thumb_relative_path, $uploaded_images, true ) ) {
						$additional_images[] = $thumb_relative_path;
						++$thumbnail_count;

						// Adicionar à lista por tamanho.
						if ( ! isset( $uploaded_thumbnails_by_size[ $size ] ) ) {
							$uploaded_thumbnails_by_size[ $size ] = array();
						}

						if ( ! in_array( $thumb_relative_path, $uploaded_thumbnails_by_size[ $size ], true ) ) {
							$uploaded_thumbnails_by_size[ $size ][] = $thumb_relative_path;
						}
					}

					// WebP equivalente (se ativado).
					if ( $enable_webp ) {
						$webp_file          = str_replace(
							array( '.jpg', '.jpeg', '.png', '.gif' ),
							'.webp',
							$size_info['file']
						);
						$webp_path          = $base_dir . trailingslashit( $file_dir ) . $webp_file;
						$webp_relative_path = codir2me_get_relative_path( $webp_path );

						if ( ! in_array( $webp_relative_path, $uploaded_images, true ) ) {
							$additional_images[] = $webp_relative_path;
							++$webp_count;
						}
					}

					// AVIF equivalente (se ativado).
					if ( $enable_avif ) {
						$avif_file          = str_replace(
							array( '.jpg', '.jpeg', '.png', '.gif', '.webp' ),
							'.avif',
							$size_info['file']
						);
						$avif_path          = $base_dir . trailingslashit( $file_dir ) . $avif_file;
						$avif_relative_path = codir2me_get_relative_path( $avif_path );

						if ( ! in_array( $avif_relative_path, $uploaded_images, true ) ) {
							$additional_images[] = $avif_relative_path;
							++$avif_count;
						}
					}
				}

				// WebP e AVIF para imagem original.
				$original_file_path = get_attached_file( $attachment_id );
				if ( $original_file_path ) {
					$original_relative_path = codir2me_get_relative_path( $original_file_path );

					if ( $enable_webp ) {
						$original_webp_path          = str_replace(
							array( '.jpg', '.jpeg', '.png', '.gif' ),
							'.webp',
							$original_file_path
						);
						$original_webp_relative_path = codir2me_get_relative_path( $original_webp_path );

						if ( ! in_array( $original_webp_relative_path, $uploaded_images, true ) ) {
							$additional_images[] = $original_webp_relative_path;
							++$webp_count;
						}
					}

					if ( $enable_avif ) {
						$original_avif_path          = str_replace(
							array( '.jpg', '.jpeg', '.png', '.gif', '.webp' ),
							'.avif',
							$original_file_path
						);
						$original_avif_relative_path = codir2me_get_relative_path( $original_avif_path );

						if ( ! in_array( $original_avif_relative_path, $uploaded_images, true ) ) {
							$additional_images[] = $original_avif_relative_path;
							++$avif_count;
						}
					}
				}
			}
		}

		// Mesclar as listas.
		$all_images = array_merge( $uploaded_images, $additional_images );
		$all_images = array_unique( $all_images );

		// Atualizar as opções.
		update_option( 'codir2me_uploaded_images', $all_images );
		update_option( 'codir2me_uploaded_thumbnails_by_size', $uploaded_thumbnails_by_size );

		// Atualizar contadores.
		$original_count      = get_option( 'codir2me_original_images_count', 0 );
		$new_thumbnail_count = get_option( 'codir2me_thumbnail_images_count', 0 ) + $thumbnail_count;
		update_option( 'codir2me_thumbnail_images_count', $new_thumbnail_count );

		// Registrar no log.
		if ( function_exists( 'codir2me_cdn_log' ) ) {
			codir2me_cdn_log(
				sprintf(
					/* translators: %1$d é o número de miniaturas; %2$d é o número de arquivos WebP; %3$d é o número de arquivos AVIF. */
					__( 'Manutenção: Marcadas %1$d miniaturas, %2$d arquivos WebP e %3$d arquivos AVIF como enviados.', 'codirun-codir2me-cdn' ),
					$thumbnail_count,
					$webp_count,
					$avif_count
				),
				'info'
			);
		}
	}
}
