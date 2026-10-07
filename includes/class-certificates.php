<?php
namespace JPCRM_Courses;

defined( 'ABSPATH' ) || exit;

final class Certificates {
	const MAX_BYTES = 5242880;

	public static function upload( $file ) {
		if ( ! $file || UPLOAD_ERR_NO_FILE === ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) { return null; }
		if ( ! CRM::can_edit() ) { throw new \RuntimeException( __( 'You cannot upload certificates.', 'jpcrm-courses' ) ); }
		if ( UPLOAD_ERR_OK !== $file['error'] || ! is_string( $file['tmp_name'] ?? null ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			throw new \InvalidArgumentException( __( 'Upload failed. Check your file and the server upload limit.', 'jpcrm-courses' ) );
		}
		return self::read_validated( $file['tmp_name'], $file['name'] );
	}

	/** Called only with a verified PHP upload path by the HTTP controller. */
	public static function read_validated( $path, $name ) {
		$size = filesize( $path );
		if ( ! $size || $size > self::MAX_BYTES ) { throw new \InvalidArgumentException( __( 'Certificates must be between 1 byte and 5 MB.', 'jpcrm-courses' ) ); }
		$name = sanitize_file_name( $name );
		$extension = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		$allowed = array( 'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png' );
		$mime = function_exists( 'finfo_open' ) ? ( new \finfo( FILEINFO_MIME_TYPE ) )->file( $path ) : '';
		if ( ! isset( $allowed[ $extension ] ) || $mime !== $allowed[ $extension ] ) {
			throw new \InvalidArgumentException( __( 'Upload a genuine PDF, JPEG or PNG certificate. PHP Fileinfo must be enabled.', 'jpcrm-courses' ) );
		}
		$bytes = file_get_contents( $path );
		if ( false === $bytes || strlen( $bytes ) !== $size || ( 'pdf' === $extension && 0 !== strpos( $bytes, '%PDF-' ) ) || ( 'pdf' !== $extension && ! @getimagesize( $path ) ) ) {
			throw new \InvalidArgumentException( __( 'The certificate is unreadable or has an invalid file signature.', 'jpcrm-courses' ) );
		}
		return array( 'filename' => mb_substr( pathinfo( $name, PATHINFO_FILENAME ), 0, 175 ) . '.' . $extension, 'mime' => $mime, 'file_size' => $size, 'data_base64' => base64_encode( $bytes ) );
	}

	public static function get( $record_id ) {
		global $wpdb;
		$record = Store::record( $record_id );
		if ( ! $record ) { throw new \RuntimeException( __( 'Certificate not found.', 'jpcrm-courses' ) ); }
		CRM::require_contact( $record['contact_id'] );
		$certificate = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Store::table( 'certificates' ) . ' WHERE id = %d', $record['certificate_id'] ), ARRAY_A );
		if ( ! $certificate ) { throw new \RuntimeException( __( 'Certificate not found.', 'jpcrm-courses' ) ); }
		return $certificate;
	}

	public static function url( $record_id, $download = false ) {
		return wp_nonce_url( add_query_arg( array( 'action' => 'jpcc_certificate', 'record_id' => $record_id, 'download' => $download ? 1 : 0 ), admin_url( 'admin-post.php' ) ), 'jpcc_certificate_' . $record_id );
	}
}
