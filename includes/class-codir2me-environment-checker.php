<?php
/**
 * Classe responsável pela verificação de compatibilidade do ambiente
 * VERSÃO CORRIGIDA PARA ASYNCAWS
 *
 * @package Codirun_R2_Media_Static_CDN
 */

// Evitar acesso direto ao arquivo.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classe para verificação de compatibilidade do ambiente
 */
class CODIR2ME_Environment_Checker {
	/**
	 * Versão mínima recomendada do PHP.
	 *
	 * @var string
	 */
	private $min_php_version = '8.2.0';

	/**
	 * Versão mínima recomendada do WordPress.
	 *
	 * @var string
	 */
	private $min_wp_version = '6.0.0';

	/**
	 * Extensões PHP necessárias.
	 *
	 * @var array
	 */
	private $required_extensions = array(
		'curl'     => 'Necessário para comunicação com a API do Cloudflare',
		'json'     => 'Necessário para processamento de respostas da API',
		'gd'       => 'Recomendado para otimização de imagens',
		'mbstring' => 'Necessário para manipulação de strings multibyte',
		'xml'      => 'Necessário para a AWS SDK',
	);

	/**
	 * Chave de acesso do R2.
	 *
	 * @var string
	 */
	private $codir2me_access_key;

	/**
	 * Chave secreta do R2.
	 *
	 * @var string
	 */
	private $codir2me_secret_key;

	/**
	 * Nome do bucket R2.
	 *
	 * @var string
	 */
	private $codir2me_bucket;

	/**
	 * Endpoint do R2.
	 *
	 * @var string
	 */
	private $codir2me_endpoint;

	/**
	 * Construtor
	 */
	public function __construct() {
		// Carregar configurações do R2.
		$this->codir2me_access_key = get_option( 'codir2me_access_key' );
		$this->codir2me_secret_key = get_option( 'codir2me_secret_key' );
		$this->codir2me_bucket     = get_option( 'codir2me_bucket' );
		$this->codir2me_endpoint   = get_option( 'codir2me_endpoint' );

		// Adicionar hooks AJAX.
		add_action( 'wp_ajax_codir2me_check_environment', array( $this, 'codir2me_ajax_check_environment' ) );
	}

	/**
	 * Verificação de ambiente via AJAX
	 */
	public function codir2me_ajax_check_environment() {
		// Verificar nonce.
		check_ajax_referer( 'codir2me_env_check_nonce', 'nonce' );

		// Verificar permissões.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permissão negada.', 'codirun-codir2me-cdn' ) ) );
		}

		// Executar verificações.
		$result = $this->codir2me_run_environment_checks();

		// Retornar resultado.
		wp_send_json_success( $result );
	}

	/**
	 * Executa todas as verificações de ambiente
	 *
	 * @return array Resultados das verificações.
	 */
	public function codir2me_run_environment_checks() {
		$results = array(
			'overall_status' => 'success',
			'checks'         => array(),
		);

		// Verificar versão do PHP.
		$php_check                = $this->codir2me_check_php_version();
		$results['checks']['php'] = $php_check;
		if ( 'error' === $php_check['status'] ) {
			$results['overall_status'] = 'error';
		} elseif ( 'warning' === $php_check['status'] && 'error' !== $results['overall_status'] ) {
			$results['overall_status'] = 'warning';
		}

		// Verificar versão do WordPress.
		$wp_check                       = $this->codir2me_check_wp_version();
		$results['checks']['wordpress'] = $wp_check;
		if ( 'error' === $wp_check['status'] ) {
			$results['overall_status'] = 'error';
		} elseif ( 'warning' === $wp_check['status'] && 'error' !== $results['overall_status'] ) {
			$results['overall_status'] = 'warning';
		}

		// Verificar extensões PHP.
		$extensions_check                = $this->codir2me_check_php_extensions();
		$results['checks']['extensions'] = $extensions_check;
		if ( 'error' === $extensions_check['status'] ) {
			$results['overall_status'] = 'error';
		} elseif ( 'warning' === $extensions_check['status'] && 'error' !== $results['overall_status'] ) {
			$results['overall_status'] = 'warning';
		}

		// Verificar permissões de diretório.
		$permissions_check                = $this->codir2me_check_directory_permissions();
		$results['checks']['permissions'] = $permissions_check;
		if ( 'error' === $permissions_check['status'] ) {
			$results['overall_status'] = 'error';
		} elseif ( 'warning' === $permissions_check['status'] && 'error' !== $results['overall_status'] ) {
			$results['overall_status'] = 'warning';
		}

		// Verificar AsyncAws SDK.
		$asyncaws_sdk_check           = $this->codir2me_check_aws_sdk();
		$results['checks']['aws_sdk'] = $asyncaws_sdk_check;
		if ( 'error' === $asyncaws_sdk_check['status'] ) {
			$results['overall_status'] = 'error';
		} elseif ( 'warning' === $asyncaws_sdk_check['status'] && 'error' !== $results['overall_status'] ) {
			$results['overall_status'] = 'warning';
		}

		// Verificar comunicação com o R2.
		$codir2me_check                           = $this->codir2me_check_connection();
		$results['checks']['codir2me_connection'] = $codir2me_check;
		if ( 'error' === $codir2me_check['status'] ) {
			$results['overall_status'] = 'error';
		} elseif ( 'warning' === $codir2me_check['status'] && 'error' !== $results['overall_status'] ) {
			$results['overall_status'] = 'warning';
		}

		// Adicionar recomendações gerais com base nos resultados.
		$results['recommendations'] = $this->codir2me_generate_recommendations( $results );

		return $results;
	}

	/**
	 * Verificar a versão do PHP
	 *
	 * @return array Resultado da verificação.
	 */
	private function codir2me_check_php_version() {
		$current_php = phpversion();
		$result      = array(
			'title'       => __( 'Versão do PHP', 'codirun-codir2me-cdn' ),
			'current'     => $current_php,
			'recommended' => $this->min_php_version,
			'status'      => 'success',
			'message'     => __( 'Sua versão do PHP é compatível.', 'codirun-codir2me-cdn' ),
		);

		if ( version_compare( $current_php, $this->min_php_version, '<' ) ) {
			$result['status'] = 'error';

			/* translators: %1$s: versão atual do PHP, %2$s: versão mínima recomendada do PHP */
			$translated_string = __( 'A versão atual do PHP (%1$s) é menor que a mínima recomendada (%2$s).', 'codirun-codir2me-cdn' );

			$result['message'] = sprintf( $translated_string, $current_php, $this->min_php_version );
		}

		return $result;
	}

	/**
	 * Verificar a versão do WordPress
	 *
	 * @return array Resultado da verificação.
	 */
	private function codir2me_check_wp_version() {
		global $wp_version;

		$result = array(
			'title'       => __( 'Versão do WordPress', 'codirun-codir2me-cdn' ),
			'current'     => $wp_version,
			'recommended' => $this->min_wp_version,
			'status'      => 'success',
			'message'     => __( 'Sua versão do WordPress é compatível.', 'codirun-codir2me-cdn' ),
		);

		if ( version_compare( $wp_version, $this->min_wp_version, '<' ) ) {
			$result['status'] = 'error';
			/* translators: %1$s: versão atual do WordPress, %2$s: versão mínima recomendada do WordPress */
			$result['message'] = sprintf( __( 'A versão atual do WordPress (%1$s) é menor que a mínima recomendada (%2$s).', 'codirun-codir2me-cdn' ), $wp_version, $this->min_wp_version );
		}

		return $result;
	}

	/**
	 * Verificar extensões PHP necessárias
	 *
	 * @return array Resultado da verificação.
	 */
	private function codir2me_check_php_extensions() {
		$missing          = array();
		$optional_missing = array();

		foreach ( $this->required_extensions as $ext => $desc ) {
			if ( ! extension_loaded( $ext ) ) {
				// Considerar GD como opcional (warning em vez de error).
				if ( 'gd' === $ext ) {
					$optional_missing[ $ext ] = $desc;
				} else {
					$missing[ $ext ] = $desc;
				}
			}
		}

		$result = array(
			'title'            => __( 'Extensões PHP', 'codirun-codir2me-cdn' ),
			'missing'          => $missing,
			'optional_missing' => $optional_missing,
			'status'           => 'success',
			'message'          => __( 'Todas as extensões PHP necessárias estão instaladas.', 'codirun-codir2me-cdn' ),
		);

		if ( ! empty( $missing ) ) {
			$result['status']  = 'error';
			$result['message'] = __( 'Extensões PHP necessárias estão faltando.', 'codirun-codir2me-cdn' );
		} elseif ( ! empty( $optional_missing ) ) {
			$result['status']  = 'warning';
			$result['message'] = __( 'Extensões PHP recomendadas estão faltando.', 'codirun-codir2me-cdn' );
		}

		return $result;
	}

	/**
	 * Verificar permissões de diretório
	 *
	 * @return array Resultado da verificação.
	 */
	private function codir2me_check_directory_permissions() {
		global $wp_filesystem;

		// Inicializar o WP_Filesystem.
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		// Configurar o WP_Filesystem.
		$access_type = get_filesystem_method();
		if ( 'direct' === $access_type ) {
			// O sistema de arquivos pode ser acessado diretamente.
			$creds = request_filesystem_credentials( admin_url(), '', false, false, array() );

			// Inicializar o WP_Filesystem.
			if ( ! WP_Filesystem( $creds ) ) {
				// Se a inicialização falhar, usar o método direto como fallback.
				WP_Filesystem();
			}
		} else {
			// Se não for acesso direto, usar o método padrão.
			WP_Filesystem();
		}

		$upload_dir = wp_upload_dir();

		// CORREÇÃO: Usar apenas diretório de uploads (não pasta do plugin).
		$log_dir            = $upload_dir['basedir'] . '/codirun-codir2me-cdn-logs/';
		$plugin_uploads_dir = $upload_dir['basedir'] . '/codirun-codir2me-cdn/';

		$problem_dirs = array();

		// Verificar diretório de uploads.
		if ( ! $wp_filesystem->is_writable( $upload_dir['basedir'] ) ) {
			$problem_dirs[] = array(
				'path'  => $upload_dir['basedir'],
				'issue' => __( 'Não tem permissão de escrita', 'codirun-codir2me-cdn' ),
			);
		}

		// Verificar ou criar diretório de logs.
		if ( ! $wp_filesystem->exists( $log_dir ) ) {
			if ( ! $wp_filesystem->mkdir( $log_dir, 0755, true ) ) {
				$problem_dirs[] = array(
					'path'  => $log_dir,
					'issue' => __( 'Não foi possível criar o diretório', 'codirun-codir2me-cdn' ),
				);
			}
		} elseif ( ! $wp_filesystem->is_writable( $log_dir ) ) {
			$problem_dirs[] = array(
				'path'  => $log_dir,
				'issue' => __( 'Não tem permissão de escrita', 'codirun-codir2me-cdn' ),
			);
		}

		// Verificar ou criar diretório de uploads do plugin usando WP_Filesystem.
		if ( ! $wp_filesystem->exists( $plugin_uploads_dir ) ) {
			if ( ! $wp_filesystem->mkdir( $plugin_uploads_dir, 0755, true ) ) {
				$problem_dirs[] = array(
					'path'  => $plugin_uploads_dir,
					'issue' => __( 'Não foi possível criar o diretório', 'codirun-codir2me-cdn' ),
				);
			}
		} elseif ( ! $wp_filesystem->is_writable( $plugin_uploads_dir ) ) {
			$problem_dirs[] = array(
				'path'  => $plugin_uploads_dir,
				'issue' => __( 'Não tem permissão de escrita', 'codirun-codir2me-cdn' ),
			);
		}

		$result = array(
			'title'        => __( 'Permissões de Diretório', 'codirun-codir2me-cdn' ),
			'problem_dirs' => $problem_dirs,
			'status'       => empty( $problem_dirs ) ? 'success' : 'error',
			'message'      => empty( $problem_dirs )
				? __( 'Todas as permissões de diretório estão corretas.', 'codirun-codir2me-cdn' )
				: __( 'Existem problemas com as permissões de diretório.', 'codirun-codir2me-cdn' ),
		);

		return $result;
	}

	/**
	 * Verificar AsyncAws SDK
	 *
	 * @return array Resultado da verificação.
	 */
	private function codir2me_check_aws_sdk() {
		$sdk_path      = CODIR2ME_CDN_PLUGIN_DIR . 'vendor/autoload.php';
		$has_sdk       = file_exists( $sdk_path );
		$has_s3_client = false;

		if ( $has_sdk ) {
			require_once $sdk_path;
			$has_s3_client = class_exists( 'AsyncAws\S3\S3Client' );
		}

		$result = array(
			'title'         => __( 'AsyncAws SDK', 'codirun-codir2me-cdn' ),
			'has_sdk'       => $has_sdk,
			'has_s3_client' => $has_s3_client,
			'status'        => ( $has_sdk && $has_s3_client ) ? 'success' : 'error',
			'message'       => ( $has_sdk && $has_s3_client )
				? __( 'AsyncAws SDK está instalado corretamente.', 'codirun-codir2me-cdn' )
				: __( 'AsyncAws SDK não está instalado ou está incompleto.', 'codirun-codir2me-cdn' ),
		);

		return $result;
	}

	/**
	 * Verificar conexão com o R2 - VERSÃO CORRIGIDA PARA ASYNCAWS
	 *
	 * @return array Resultado da verificação.
	 */
	private function codir2me_check_connection() {
		$result = array(
			'title'      => __( 'Conexão com o R2', 'codirun-codir2me-cdn' ),
			'configured' => false,
			'connection' => false,
			'status'     => 'warning',
			'message'    => __( 'Configurações do R2 não encontradas.', 'codirun-codir2me-cdn' ),
		);

		// Verificar se as configurações do R2 estão definidas.
		if ( empty( $this->codir2me_access_key ) || empty( $this->codir2me_secret_key ) ||
			empty( $this->codir2me_bucket ) || empty( $this->codir2me_endpoint ) ) {
			return $result;
		}

		$result['configured'] = true;
		$result['message']    = __( 'Testando conexão com o R2...', 'codirun-codir2me-cdn' );

		// Verificar se o AsyncAws SDK está disponível.
		if ( ! file_exists( CODIR2ME_CDN_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
			$result['status']  = 'error';
			$result['message'] = __( 'AsyncAws SDK não encontrado. Não é possível testar a conexão com o R2.', 'codirun-codir2me-cdn' );
			return $result;
		}

		require_once CODIR2ME_CDN_PLUGIN_DIR . 'vendor/autoload.php';

		if ( ! class_exists( 'AsyncAws\S3\S3Client' ) ) {
			$result['status']  = 'error';
			$result['message'] = __( 'Classe AsyncAws\S3\S3Client não encontrada. Verifique a instalação do AsyncAws SDK.', 'codirun-codir2me-cdn' );
			return $result;
		}

		// Testar conexão com o R2.
		try {
			// CORREÇÃO: Configuração correta para AsyncAws.
			$s3_client = new AsyncAws\S3\S3Client(
				array(
					'region'            => 'auto',
					'endpoint'          => $this->codir2me_endpoint,
					'accessKeyId'       => $this->codir2me_access_key,
					'accessKeySecret'   => $this->codir2me_secret_key,
					'pathStyleEndpoint' => true,  // Essencial para Cloudflare R2.
				)
			);

			// Testar listagem de objetos.
			$objects = $s3_client->listObjectsV2(
				array(
					'Bucket'  => $this->codir2me_bucket,
					'MaxKeys' => 1,
				)
			);

			// AsyncAws não precisa de resolve() explícito.
			// Verificamos se conseguimos acessar os dados básicos.
			$contents = $objects->getContents();

			$result['connection'] = true;
			$result['status']     = 'success';
			$result['message']    = __( 'Conexão com o R2 estabelecida com sucesso!', 'codirun-codir2me-cdn' );

		} catch ( AsyncAws\Core\Exception\Http\HttpException $e ) {
			$result['status'] = 'error';
			/* translators: %s: mensagem de erro */
			$result['message']       = sprintf( __( 'Falha na conexão HTTP com R2: %s', 'codirun-codir2me-cdn' ), $e->getMessage() );
			$result['error_details'] = array(
				'code'    => $e->getCode(),
				'message' => $e->getMessage(),
			);
		} catch ( AsyncAws\Core\Exception\InvalidArgument $e ) {
			$result['status'] = 'error';
			/* translators: %s: mensagem de erro */
			$result['message'] = sprintf( __( 'Configurações inválidas: %s', 'codirun-codir2me-cdn' ), $e->getMessage() );
		} catch ( Exception $e ) {
			$result['status'] = 'error';
			/* translators: %s: mensagem de erro */
			$result['message'] = sprintf( __( 'Erro inesperado: %s', 'codirun-codir2me-cdn' ), $e->getMessage() );
		}

		return $result;
	}

	/**
	 * Gera recomendações com base nos resultados das verificações
	 *
	 * @param array $check_results Resultados das verificações.
	 * @return array Recomendações.
	 */
	private function codir2me_generate_recommendations( $check_results ) {
		$recommendations = array();

		// Verificar PHP.
		if ( 'error' === $check_results['checks']['php']['status'] ) {
			/* translators: %s: versão mínima do PHP recomendada */
			$recommendations[] = sprintf( __( 'Atualize o PHP para a versão %s ou superior.', 'codirun-codir2me-cdn' ), $this->min_php_version );
		}

		// Verificar WordPress.
		if ( 'error' === $check_results['checks']['wordpress']['status'] ) {
			/* translators: %s: versão mínima do WordPress recomendada */
			$recommendations[] = sprintf( __( 'Atualize o WordPress para a versão %s ou superior.', 'codirun-codir2me-cdn' ), $this->min_wp_version );
		}

		// Verificar extensões PHP.
		if ( 'error' === $check_results['checks']['extensions']['status'] ||
			'warning' === $check_results['checks']['extensions']['status'] ) {

			if ( ! empty( $check_results['checks']['extensions']['missing'] ) ) {
				foreach ( $check_results['checks']['extensions']['missing'] as $ext => $desc ) {
					/* translators: %1$s: nome da extensão PHP, %2$s: descrição da necessidade */
					$recommendations[] = sprintf( __( 'Instale a extensão PHP <strong>%1$s</strong>: %2$s', 'codirun-codir2me-cdn' ), $ext, $desc );
				}
			}

			if ( ! empty( $check_results['checks']['extensions']['optional_missing'] ) ) {
				foreach ( $check_results['checks']['extensions']['optional_missing'] as $ext => $desc ) {
					/* translators: %1$s: nome da extensão PHP, %2$s: descrição da necessidade */
					$recommendations[] = sprintf( __( 'Recomendado: Instale a extensão PHP <strong>%1$s</strong>: %2$s', 'codirun-codir2me-cdn' ), $ext, $desc );
				}
			}
		}

		// Verificar permissões de diretório.
		if ( 'error' === $check_results['checks']['permissions']['status'] ) {
			foreach ( $check_results['checks']['permissions']['problem_dirs'] as $dir ) {
				/* translators: %1$s: caminho do diretório, %2$s: descrição do problema */
				$recommendations[] = sprintf( __( 'Ajuste as permissões para o diretório <code>%1$s</code>: %2$s', 'codirun-codir2me-cdn' ), $dir['path'], $dir['issue'] );
			}
		}

		// Verificar AsyncAws SDK.
		if ( 'error' === $check_results['checks']['aws_sdk']['status'] ) {
			$recommendations[] = __( 'Instale o AsyncAws SDK para PHP executando: composer require async-aws/s3', 'codirun-codir2me-cdn' );
		}

		// Verificar conexão R2.
		if ( 'error' === $check_results['checks']['codir2me_connection']['status'] ) {
			if ( $check_results['checks']['codir2me_connection']['configured'] ) {
				$recommendations[] = __( 'Verifique suas credenciais e configurações do R2. A conexão falhou.', 'codirun-codir2me-cdn' );
			} else {
				$recommendations[] = __( 'Configure suas credenciais do Cloudflare R2 nas configurações do plugin.', 'codirun-codir2me-cdn' );
			}
		} elseif ( 'warning' === $check_results['checks']['codir2me_connection']['status'] ) {
			$recommendations[] = __( 'Configure suas credenciais do Cloudflare R2 nas configurações do plugin.', 'codirun-codir2me-cdn' );
		}

		return $recommendations;
	}
}
