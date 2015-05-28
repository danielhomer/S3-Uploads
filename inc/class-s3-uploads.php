<?php

class S3_Uploads {

	private static $instance;
	private $bucket;
	private $bucket_url;
	private $key;
	private $secret;

	public $original_upload_dir;

	/**
	 * 
	 * @return S3_Uploads
	 */
	public static function get_instance() {

		$s3_uploads_bucket = self::get_var( 'S3_UPLOADS_BUCKET' ) ;
		$s3_uploads_key = self::get_var( 'S3_UPLOADS_KEY' );
		$s3_uploads_secret = self::get_var( 'S3_UPLOADS_SECRET' );
		$s3_uploads_bucket_url = self::get_var( 'S3_UPLOADS_BUCKET_URL' );
		$s3_uploads_region = self::get_var( 'S3_UPLOADS_REGION' );
		$s3_uploads_bucket_suffix = self::get_var( 'S3_UPLOADS_BUCKET_SUFFIX' );

		if ( $s3_uploads_bucket_suffix ) {
			$s3_uploads_bucket = $s3_uploads_bucket . '/' . $s3_uploads_bucket_suffix;
		}

		if ( ! self::$instance ) {
			self::$instance = new S3_Uploads( 
				$s3_uploads_bucket, 
				$s3_uploads_key, 
				$s3_uploads_secret, 
				$s3_uploads_bucket_url,
				$s3_uploads_region
				);
		}

		return self::$instance;

	}

	private static function get_var( $name, $try_options = true ) {

		$result = null;

		if ( getenv( $name ) ) {
			$result = getenv( $name );
		}

		if ( defined( $name ) ) {
			$result = constant( $name );
		}

		if ( $try_options ) {
			$result = get_option( strtolower( $name ), null );
		}

		$result = apply_filters( strtolower( $name ), $result );

		return $result;

	}

	public function __construct( $bucket, $key, $secret, $bucket_url = null, $region = null ) {

		$this->bucket = $bucket;
		$this->key = $key;
		$this->secret = $secret;
		$this->bucket_url = $bucket_url;
		$this->region = $region;

		if ( defined( 'S3_UPLOADS_USE_LOCAL' ) && S3_UPLOADS_USE_LOCAL ) {
			require_once dirname( __FILE__ ) . '/class-s3-uploads-local-stream-wrapper.php';
			stream_wrapper_register( 's3', 'S3_Uploads_Local_Stream_Wrapper', STREAM_IS_URL );
		} else {
			$s3 = $this->s3();
			S3_Uploads_Stream_Wrapper::register( $s3 );
			stream_context_set_option( stream_context_get_default(), 's3', 'ACL', Aws\S3\Enum\CannedAcl::PUBLIC_READ );
		}

		stream_context_set_option( stream_context_get_default(), 's3', 'seekable', true );
	}

	public function filter_upload_dir( $dirs ) {

		$this->original_upload_dir = $dirs;

		$dirs['path']    = str_replace( WP_CONTENT_DIR, 's3://' . $this->bucket, $dirs['path'] );
		$dirs['basedir'] = str_replace( WP_CONTENT_DIR, 's3://' . $this->bucket, $dirs['basedir'] );

		if ( ! defined( 'S3_UPLOADS_DISABLE_REPLACE_UPLOAD_URL' ) || ! S3_UPLOADS_DISABLE_REPLACE_UPLOAD_URL ) {

			if ( defined( 'S3_UPLOADS_USE_LOCAL' ) && S3_UPLOADS_USE_LOCAL ) {
				$dirs['url']     = str_replace( 's3://' . $this->bucket, $dirs['baseurl'] . '/s3/' . $this->bucket, $dirs['path'] );
				$dirs['baseurl'] = str_replace( 's3://' . $this->bucket, $dirs['baseurl'] . '/s3/' . $this->bucket, $dirs['basedir'] );

			} else {
				$dirs['url']     = str_replace( 's3://' . $this->bucket, $this->get_s3_url(), $dirs['path'] );
				$dirs['baseurl'] = str_replace( 's3://' . $this->bucket, $this->get_s3_url(), $dirs['basedir'] );
			}
		}


		return $dirs;
	}

	public function get_s3_url( $include_protocol = true ) {
		if ( $this->bucket_url ) {
			return $this->bucket_url;
		}

		$bucket = strtok( $this->bucket, '/' );
		$path   = substr( $this->bucket, strlen( $bucket ) );

		$domain_name = self::get_var( 's3_uploads_domain_name' );

		if ( ! $domain_name ) {
			$domain_name = $bucket . '.s3.amazonaws.com';
		}

		if ( $include_protocol ) {
			$protocol = is_ssl() ? 'https://' : 'http://';
		} else {
			$protocol = '';
		}

		return $protocol . $domain_name . $path;
	}

	public function get_original_upload_dir() {

		if ( empty( $this->original_upload_dir ) )
			wp_upload_dir();

		return $this->original_upload_dir;
	}
	
	/**
	 * @return Aws\S3\S3Client
	 */
	public function s3() {

		require_once dirname( __FILE__ ) . '/aws-sdk/aws-autoloader.php';
		require_once dirname( __FILE__ ) . '/class-s3-uploads-stream-wrapper.php';

		if ( ! empty( $this->s3 ) )
			return $this->s3;

		$params = array( 'key' => $this->key, 'secret' => $this->secret );

		$this->s3 = Aws\Common\Aws::factory( $params )->get( 's3' );

		return $this->s3;
	}

	public function filter_editors( $editors ) {

		if ( ( $position = array_search( 'WP_Image_Editor_Imagick', $editors ) ) !== false ) {
			unset($editors[$position]);
		}

		return $editors;
	}

	/**
	 * Copy the file from /tmp to an s3 dir so handle_sideload doesn't fail due to 
	 * trying to do a rename() on the file cross streams. This is somewhat of a hack
	 * to work around the core issue https://core.trac.wordpress.org/ticket/29257
	 *
	 * @param array File array
	 * @return array
	 */
	public function filter_sideload_move_temp_file_to_s3( array $file ) {
		$upload_dir = wp_upload_dir();
		$new_path = $upload_dir['basedir'] . '/tmp/' . basename( $file['tmp_name'] );

		copy( $file['tmp_name'], $new_path );
		unlink( $file['tmp_name'] );
		$file['tmp_name'] = $new_path;

		return $file;
	}

	public function add_settings() {

		register_setting(
			'media',
			's3_uploads_bucket',
			array( $this, 'sanitize_option' )
			);

		register_setting(
			'media',
			's3_uploads_key',
			array( $this, 'sanitize_option' )
			);

		register_setting(
			'media',
			's3_uploads_secret',
			array( $this, 'sanitize_option' )
			);

		register_setting(
			'media',
			's3_uploads_domain_name',
			array( $this, 'sanitize_domain_name' )
			);

		add_settings_section( 
			's3_uploads_section', 
			'S3 Uploads', 
			false,
			'media'
			);

		add_settings_field(
			's3_uploads_bucket',
			'Bucket Name',
			array( $this, 'field_callback' ),
			'media',
			's3_uploads_section',
			array( 's3_uploads_bucket')
			);

		add_settings_field(
			's3_uploads_key',
			'Access Key ID',
			array( $this, 'field_callback' ),
			'media',
			's3_uploads_section',
			array( 's3_uploads_key')
			);

		add_settings_field(
			's3_uploads_secret',
			'Access Key Secret',
			array( $this, 'field_callback' ),
			'media',
			's3_uploads_section',
			array( 's3_uploads_secret')
			);

		add_settings_field(
			's3_uploads_domain_name',
			'Domain Root',
			array( $this, 'field_callback' ),
			'media',
			's3_uploads_section',
			array( 's3_uploads_domain_name')
			);

	}

	public function field_callback( array $args ) {
		
		$id = isset( $args[0] ) ? $args[0] : false;
		
		if ( ! $id ) {
			return;
		}

		if ( ! $option = self::get_var( strtoupper( $id ), false ) ) {
			
			$option = get_option( $id, '' );

			if ( ! $option && $id == 's3_uploads_domain_name' ) {
				$attr = sprintf( ' placeholder="%s"', $this->get_s3_url( false ) );
			}

			if ( $id == 's3_uploads_bucket' ) {
				$s3 = $this->s3();

				try {
					$results = $s3->listBuckets();
				} catch ( Exception $e ) {
					echo $e->getMessage();
					return;
				}

				if ( ! isset( $results['Buckets'] ) ) {
					return;
				}

				printf( '<select name=%s>', $id );
				echo '<option value="">--Select a bucket--</option>';

				foreach ( $results['Buckets'] as $bucket ) {
					if ( strpos( $bucket['Name'], '.') ) {
						continue; // We can't deal with buckets with dots in the name at the moment
					}

					if ( $bucket['Name'] === $option ) {
						$attr = ' selected="selected"';
					} else {
						$attr = '';
					}

					printf( '<option value="%s"%s>%s</option>', $bucket['Name'], $attr, $bucket['Name'] );
				}

				echo '</select>';

				if ( $suffix = self::get_var( 'S3_UPLOADS_BUCKET_SUFFIX' ) ) {
					printf( '<code>/%s</code>', $suffix );
				}

				return;
			}

			if ( $id == 's3_uploads_secret' || $id == 's3_uploads_key' ) {
				$type = 'password';
			} else {
				$type = 'text';
			}

			printf( '<input class="widefat" type="%s" id="%s" name="%s" value="%s"%s />', $type, $id, $id, $option, $attr );

			if ( $id = 's3_uploads_domain_name' ) {
				echo ' <span class="description">(Without http://)</span>';
			}

		} else {

			if ( $id == 's3_uploads_secret' || $id == 's3_uploads_key' ) {
				$option = 'Hidden for security';
			}

			printf( '<code>%s</code> <span class="description">(Set via constant, environment variable or filter)</span>', $option );

		}

	}

	public function sanitize_option( $value ) {
		
		return esc_attr( $value );

	}

	public function sanitize_domain_name( $value ) {

		$value = rtrim( $value, '/' );

		$value = filter_var( $value, FILTER_SANITIZE_URL );

		return $value;

	}

}