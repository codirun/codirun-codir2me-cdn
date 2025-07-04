<?php
/**
 * Uninstall plugin
 *
 * @package Codirun_R2_Media_Static_CDN
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Lista de opções básicas para remover.
$options = array(
	'codir2me_access_key',
	'codir2me_secret_key',
	'codir2me_bucket',
	'codir2me_endpoint',
	'codir2me_cdn_url',
	'codir2me_is_cdn_active',
	'codir2me_is_images_cdn_active',
	'codir2me_batch_size',
	'codir2me_images_batch_size',
	'codir2me_thumbnail_option',
	'codir2me_selected_thumbnails',
	'codir2me_auto_upload_static',
	'codir2me_auto_upload_frequency',
	'codir2me_upload_on_update',
	'codir2me_enable_versioning',
	'codir2me_auto_upload_thumbnails',
	'codir2me_image_optimization_options',
	'codir2me_optimization_stats',
	'codir2me_license_key',
	'codir2me_license_email',
	'codir2me_license_status',
	'codir2me_license_domain',
	'codir2me_license_expiry',
	'codir2me_license_last_check',
	'codir2me_format_order',
	'codir2me_format_webp_enabled',
	'codir2me_format_avif_enabled',
);

// Remover todas as opções.
foreach ( $options as $option ) {
	delete_option( $option );
}

// Remover opções de listagem.
delete_option( 'codir2me_uploaded_files' );
delete_option( 'codir2me_uploaded_images' );
delete_option( 'codir2me_uploaded_thumbnails_by_size' );
delete_option( 'codir2me_pending_files' );
delete_option( 'codir2me_pending_images' );

// Inicializar WP_Filesystem.
global $wp_filesystem;
if ( ! function_exists( 'WP_Filesystem' ) ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
}
WP_Filesystem();

// Obter diretório de uploads de forma correta.
$upload_dir = wp_upload_dir();

// Limpar arquivos temporários.
$temp_dir = $upload_dir['basedir'] . '/codir2me_temp';
if ( $wp_filesystem->exists( $temp_dir ) ) {
	// Limpar arquivos temporários no diretório.
	$files = glob( $temp_dir . '/*' );
	foreach ( $files as $file ) {
		if ( $wp_filesystem->is_file( $file ) ) {
			$wp_filesystem->delete( $file );
		}
	}

	// Tentar remover o diretório.
	$wp_filesystem->rmdir( $temp_dir );
}

// Limpar diretório de logs usando wp_upload_dir().
$log_dir = $upload_dir['basedir'] . '/codirun-codir2me-cdn-logs/';
if ( $wp_filesystem->exists( $log_dir ) ) {
	// Limpar arquivos de log.
	$log_files = glob( $log_dir . '/*' );
	foreach ( $log_files as $file ) {
		if ( $wp_filesystem->is_file( $file ) ) {
			$wp_filesystem->delete( $file );
		}
	}

	// Tentar remover o diretório.
	$wp_filesystem->rmdir( $log_dir );
}
