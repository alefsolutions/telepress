<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TelePress_Jobs_Repository {
	public static function table_name() {
		global $wpdb;

		return $wpdb->prefix . 'telepress_jobs';
	}

	public static function create_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			update_id BIGINT UNSIGNED NOT NULL,
			transport VARCHAR(20) NOT NULL DEFAULT 'webhook',
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			payload LONGTEXT NOT NULL,
			placeholder_chat_id VARCHAR(64) NULL,
			placeholder_message_id BIGINT UNSIGNED NULL,
			command_name VARCHAR(64) NULL,
			attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			last_error TEXT NULL,
			available_at DATETIME NOT NULL,
			locked_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY update_id (update_id),
			KEY status_available_at (status, available_at),
			KEY command_name (command_name)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	public static function enqueue( $update_id, $transport, $payload, $command_name = '', $placeholder = array() ) {
		global $wpdb;

		$now        = current_time( 'mysql', true );
		$table_name = self::table_name();
		$inserted   = $wpdb->insert(
			$table_name,
			array(
				'update_id'               => absint( $update_id ),
				'transport'               => sanitize_key( $transport ),
				'status'                  => 'pending',
				'payload'                 => wp_json_encode( $payload ),
				'placeholder_chat_id'     => ! empty( $placeholder['chat_id'] ) ? (string) $placeholder['chat_id'] : null,
				'placeholder_message_id'  => ! empty( $placeholder['message_id'] ) ? (int) $placeholder['message_id'] : null,
				'command_name'            => sanitize_text_field( $command_name ),
				'attempts'                => 0,
				'available_at'            => $now,
				'created_at'              => $now,
				'updated_at'              => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			if ( false !== strpos( strtolower( $wpdb->last_error ), 'duplicate' ) ) {
				return 'duplicate';
			}

			return new WP_Error( 'telepress_job_enqueue_failed', $wpdb->last_error ? $wpdb->last_error : __( 'Unable to enqueue Telegram job.', 'telepress' ) );
		}

		return (int) $wpdb->insert_id;
	}

	public static function claim_pending_jobs( $limit = 5 ) {
		global $wpdb;

		$limit = max( 1, absint( $limit ) );
		$now   = current_time( 'mysql', true );
		$jobs  = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table_name() . ' WHERE status = %s AND available_at <= %s ORDER BY id ASC LIMIT %d',
				'pending',
				$now,
				$limit
			),
			ARRAY_A
		);

		if ( empty( $jobs ) ) {
			return array();
		}

		$claimed = array();

		foreach ( $jobs as $job ) {
			$updated = $wpdb->update(
				self::table_name(),
				array(
					'status'    => 'processing',
					'locked_at' => $now,
					'updated_at'=> $now,
				),
				array(
					'id'     => (int) $job['id'],
					'status' => 'pending',
				),
				array( '%s', '%s', '%s' ),
				array( '%d', '%s' )
			);

			if ( $updated ) {
				$job['status'] = 'processing';
				$job['locked_at'] = $now;
				$claimed[] = $job;
			}
		}

		return $claimed;
	}

	public static function mark_complete( $job_id ) {
		self::update_status( $job_id, 'complete', '' );
	}

	public static function mark_failed( $job_id, $error_message ) {
		global $wpdb;

		$job = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT attempts FROM ' . self::table_name() . ' WHERE id = %d LIMIT 1',
				$job_id
			),
			ARRAY_A
		);

		$attempts = ! empty( $job['attempts'] ) ? (int) $job['attempts'] + 1 : 1;
		$status   = $attempts >= 3 ? 'failed' : 'pending';
		$delay    = $attempts >= 3 ? 0 : min( 300, $attempts * 30 );

		$wpdb->update(
			self::table_name(),
			array(
				'status'       => $status,
				'attempts'     => $attempts,
				'last_error'   => sanitize_text_field( $error_message ),
				'locked_at'    => null,
				'available_at' => gmdate( 'Y-m-d H:i:s', time() + $delay ),
				'updated_at'   => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $job_id ),
			array( '%s', '%d', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	private static function update_status( $job_id, $status, $error_message ) {
		global $wpdb;

		$wpdb->update(
			self::table_name(),
			array(
				'status'     => sanitize_key( $status ),
				'last_error' => $error_message ? sanitize_text_field( $error_message ) : '',
				'locked_at'  => null,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $job_id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}
}
