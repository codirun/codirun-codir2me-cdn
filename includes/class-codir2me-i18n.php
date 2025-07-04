<?php
/**
 * Define the internationalization functionality
 *
 * Loads and defines the internationalization files for this plugin
 * so that it is ready for translation.
 *
 * @since      1.0.0
 *
 * @package    Codirun_R2_Media_Static_CDN
 * @subpackage Codirun_R2_Media_Static_CDN/includes
 */

// Prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Define the internationalization functionality.
 *
 * Loads and defines the internationalization files for this plugin
 * so that it is ready for translation.
 *
 * @since      1.0.0
 * @package    Codirun_R2_Media_Static_CDN
 * @subpackage Codirun_R2_Media_Static_CDN/includes
 */
class CODIR2ME_I18n {

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 */
	public function __construct() {
		// Adicionar suporte para internacionalização de JavaScript.
		add_action( 'admin_enqueue_scripts', array( $this, 'codir2me_localize_admin_scripts' ), 20 );
	}

	/**
	 * Localiza scripts do admin com strings traduzíveis.
	 *
	 * @param string $hook Hook da tela atual no admin.
	 * @since 1.0.0
	 */
	public function codir2me_localize_admin_scripts( $hook ) {
		// Verificar se estamos em uma página do plugin.
		if ( strpos( $hook, 'codirun-codir2me-cdn' ) === false ) {
			return;
		}

		// Strings traduzíveis para JavaScript.
		$i18n_strings = array(
			// Status e mensagens gerais.
			'active'                             => __( 'Ativo', 'codirun-codir2me-cdn' ),
			'inactive'                           => __( 'Inativo', 'codirun-codir2me-cdn' ),

			// Confirmações.
			'confirm_clear_logs'                 => __( 'Tem certeza que deseja limpar todos os logs? Esta ação não pode ser desfeita.', 'codirun-codir2me-cdn' ),
			'confirm_reset_stats'                => __( 'Tem certeza que deseja redefinir todas as estatísticas de otimização? Esta ação não pode ser desfeita.', 'codirun-codir2me-cdn' ),
			'confirm_delete_batch'               => __( 'ATENÇÃO: Esta ação excluirá permanentemente os arquivos selecionados do seu bucket R2. O processo será executado em lotes.\n\nDeseja continuar?', 'codirun-codir2me-cdn' ),
			'confirm_stop_reprocess'             => __( 'Tem certeza que deseja parar o processo de reprocessamento? O progresso será perdido e você terá que recomeçar.', 'codirun-codir2me-cdn' ),

			// Logs e depuração.
			'log_details'                        => __( 'Detalhes do Log:', 'codirun-codir2me-cdn' ),
			'error_details'                      => __( 'Detalhes do Erro:', 'codirun-codir2me-cdn' ),
			'connection_error'                   => __( 'Erro de conexão. Por favor, tente novamente.', 'codirun-codir2me-cdn' ),
			'load_more_logs_error'               => __( 'Erro ao carregar mais logs:', 'codirun-codir2me-cdn' ),
			'load_more_logs_error_msg'           => __( 'Erro ao carregar mais logs. Por favor, tente novamente.', 'codirun-codir2me-cdn' ),

			// Upload automático.
			'auto_upload_thumbnails_enabled'     => __( 'Upload automático de miniaturas ativado:', 'codirun-codir2me-cdn' ),
			'auto_upload_thumbnails_description' => __( 'As miniaturas selecionadas serão enviadas automaticamente para o R2 quando novas imagens forem adicionadas.', 'codirun-codir2me-cdn' ),
			'enable_cdn_first'                   => __( 'Ative o CDN de imagens primeiro para usar esta opção.', 'codirun-codir2me-cdn' ),
			'change_thumbnail_option'            => __( 'Mude a opção "Tamanhos de Miniatura" para ativar esta função.', 'codirun-codir2me-cdn' ),

			// Otimização.
			'show_advanced_settings'             => __( 'Mostrar Configurações Avançadas', 'codirun-codir2me-cdn' ),
			'hide_advanced_settings'             => __( 'Ocultar Configurações Avançadas', 'codirun-codir2me-cdn' ),
			'apply_preset_values'                => __( 'Aplicar Valores do Nível Selecionado', 'codirun-codir2me-cdn' ),
			'apply_preset_description'           => __( 'Isso aplicará os valores predefinidos do nível selecionado às configurações avançadas.', 'codirun-codir2me-cdn' ),
			'values_applied'                     => __( 'Valores aplicados!', 'codirun-codir2me-cdn' ),
			'processing_image'                   => __( 'Processando imagem, aguarde...', 'codirun-codir2me-cdn' ),
			'error_label'                        => __( 'Erro:', 'codirun-codir2me-cdn' ),
			'error_processing_request'           => __( 'Erro ao processar a requisição. Por favor, tente novamente.', 'codirun-codir2me-cdn' ),

			// Formatos.
			'order_changed_notice'               => __( 'Ordem alterada. Clique em "Salvar Prioridade de Formatos" para aplicar as mudanças.', 'codirun-codir2me-cdn' ),

			// Reprocessamento.
			'confirm_stop_title'                 => __( 'Confirmar Interrupção', 'codirun-codir2me-cdn' ),
			'confirm_stop_message'               => __( 'Tem certeza que deseja parar o processo de reprocessamento? O processo será cancelado e você precisará recomeçar do início se quiser continuar.', 'codirun-codir2me-cdn' ),
			'confirm_button'                     => __( 'Sim, Parar Reprocessamento', 'codirun-codir2me-cdn' ),
			'cancel_button'                      => __( 'Não, Continuar Processando', 'codirun-codir2me-cdn' ),
			'stop_canceled'                      => __( 'Operação cancelada. O reprocessamento continua.', 'codirun-codir2me-cdn' ),
			'loading_images'                     => __( 'Carregando imagens processadas recentemente...', 'codirun-codir2me-cdn' ),
			'no_images_selected'                 => __( 'Nenhuma imagem selecionada. Clique em "Selecionar Imagens" para escolher quais imagens processar.', 'codirun-codir2me-cdn' ),
			/* translators: %d: número de imagens selecionadas */
			'x_images_selected'                  => __( '%d imagens selecionadas', 'codirun-codir2me-cdn' ),
			'remove'                             => __( 'Remover', 'codirun-codir2me-cdn' ),
			'select_images_title'                => __( 'Selecionar Imagens para Upload', 'codirun-codir2me-cdn' ),
			'select_images_button'               => __( 'Selecionar Imagens', 'codirun-codir2me-cdn' ),
			'error_loading_preview'              => __( 'Erro ao carregar visualização das imagens.', 'codirun-codir2me-cdn' ),
			'error_loading_preview_retry'        => __( 'Erro ao carregar visualização das imagens. Tente novamente.', 'codirun-codir2me-cdn' ),
			'paused'                             => __( 'Pausado', 'codirun-codir2me-cdn' ),
			'running'                            => __( 'Executando', 'codirun-codir2me-cdn' ),
		);

		// Localizar o script de administração.
		wp_localize_script(
			'codir2me-admin-scripts', // O handle do seu script principal.
			'codir2me_i18n',          // Nome da variável JavaScript global.
			$i18n_strings       // Array de strings traduzíveis.
		);
	}
}
