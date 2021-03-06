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

		if ( ! self::$instance ) {
			self::$instance = new S3_Uploads( S3_UPLOADS_BUCKET, S3_UPLOADS_KEY, S3_UPLOADS_SECRET, defined( 'S3_UPLOADS_BUCKET_URL' ) ? S3_UPLOADS_BUCKET_URL : null, defined( 'S3_UPLOADS_REGION' ) ? S3_UPLOADS_REGION : null );
		}

		return self::$instance;
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

	public function get_s3_url() {
		if ( $this->bucket_url ) {
			return $this->bucket_url;
		}

		$bucket = strtok( $this->bucket, '/' );
		$path   = substr( $this->bucket, strlen( $bucket ) );

		return 'https://' . $bucket . '.s3.amazonaws.com' . $path;
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

		if ( $this->region ) {
			$params['signature'] = 'v4';
			$params['region'] = $this->region;
		}

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
}