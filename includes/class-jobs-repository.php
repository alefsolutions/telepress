<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Telepilot_Jobs_Repository {
	public static function table_name() {
		global $wpdb;

		return $wpdb->prefix . 'telepilot_jobs';
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
			priority SMALLINT UNSIGNED NOT NULL DEFAULT 50,
			stale_after DATETIME NULL,
			job_group VARCHAR(191) NULL,
			is_replaceable TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
			attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			last_error TEXT NULL,
			available_at DATETIME NOT NULL,
			locked_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY update_id (update_id),
			KEY status_priority_available_at (status, priority, available_at),
			KEY command_name (command_name),
			KEY job_group (job_group),
			KEY placeholder_chat_id (placeholder_chat_id)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	public static function enqueue( $update_id, $transport, $payload, $command_name = '', $placeholder = array(), $options = array() ) {
		global $wpdb;

		$now        = current_time( 'mysql', true );
		$table_name = self::table_name();
		$priority   = isset( $options['priority'] ) ? max( 1, min( 999, absint( $options['priority'] ) ) ) : 50;
		$inserted   = $wpdb->insert(
			$table_name,
			array(
				'update_id'              => absint( $update_id ),
				'transport'              => sanitize_key( $transport ),
				'status'                 => 'pending',
				'payload'                => wp_json_encode( $payload ),
				'placeholder_chat_id'    => ! empty( $placeholder['chat_id'] ) ? (string) $placeholder['chat_id'] : null,
				'placeholder_message_id' => ! empty( $placeholder['message_id'] ) ? (int) $placeholder['message_id'] : null,
				'command_name'           => sanitize_text_field( $command_name ),
				'priority'               => $priority,
				'stale_after'            => ! empty( $options['stale_after'] ) ? (string) $options['stale_after'] : null,
				'job_group'              => ! empty( $options['job_group'] ) ? sanitize_text_field( (string) $options['job_group'] ) : null,
				'is_replaceable'         => ! empty( $options['is_replaceable'] ) ? 1 : 0,
				'attempts'               => 0,
				'available_at'           => $now,
				'created_at'             => $now,
				'updated_at'             => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			if ( false !== strpos( strtolower( $wpdb->last_error ), 'duplicate' ) ) {
				return 'duplicate';
			}

			return new WP_Error( 'telepilot_job_enqueue_failed', $wpdb->last_error ? $wpdb->last_error : __( 'Unable to enqueue Telegram job.', 'wp-telepilot' ) );
		}

		return (int) $wpdb->insert_id;
	}

	public static function claim_pending_jobs( $limit = 5, $lock_timeout = 180 ) {
		global $wpdb;

		$limit = max( 1, absint( $limit ) );
		$now   = current_time( 'mysql', true );

		self::release_abandoned_jobs( $lock_timeout );
		self::expire_stale_jobs();

		$jobs = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table_name() . ' WHERE status = %s AND available_at <= %s ORDER BY priority ASC, available_at ASC, id ASC LIMIT %d',
				'pending',
				$now,
				$limit * 4
			),
			ARRAY_A
		);

		if ( empty( $jobs ) ) {
			return array();
		}

		$claimed          = array();
		$claimed_chat_ids = array();

		foreach ( $jobs as $job ) {
			if ( count( $claimed ) >= $limit ) {
				break;
			}

			$chat_id = ! empty( $job['placeholder_chat_id'] ) ? (string) $job['placeholder_chat_id'] : '';

			if ( '' !== $chat_id && in_array( $chat_id, $claimed_chat_ids, true ) ) {
				continue;
			}

			if ( '' !== $chat_id && self::has_processing_job_for_chat( $chat_id, (int) $job['id'] ) ) {
				continue;
			}

			$updated = $wpdb->update(
				self::table_name(),
				array(
					'status'     => 'processing',
					'locked_at'  => $now,
					'updated_at' => $now,
				),
				array(
					'id'     => (int) $job['id'],
					'status' => 'pending',
				),
				array( '%s', '%s', '%s' ),
				array( '%d', '%s' )
			);

			if ( $updated ) {
				$job['status']    = 'processing';
				$job['locked_at'] = $now;
				$claimed[]        = $job;

				if ( '' !== $chat_id ) {
					$claimed_chat_ids[] = $chat_id;
				}
			}
		}

		return $claimed;
	}

	public static function supersede_replaceable_jobs( $current_job_id, $chat_id, $job_group, $current_placeholder_message_id = 0 ) {
		global $wpdb;

		$current_job_id = absint( $current_job_id );
		$chat_id        = (string) $chat_id;
		$job_group      = sanitize_text_field( (string) $job_group );

		if ( ! $current_job_id || '' === $chat_id || '' === $job_group ) {
			return array();
		}

		$jobs = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table_name() . ' WHERE id <> %d AND status = %s AND is_replaceable = 1 AND placeholder_chat_id = %s AND job_group = %s',
				$current_job_id,
				'pending',
				$chat_id,
				$job_group
			),
			ARRAY_A
		);

		if ( empty( $jobs ) ) {
			return array();
		}

		$now = current_time( 'mysql', true );

		foreach ( $jobs as $job ) {
			$wpdb->update(
				self::table_name(),
				array(
					'status'     => 'stale',
					'last_error' => __( 'Superseded by a newer request.', 'wp-telepilot' ),
					'locked_at'  => null,
					'updated_at' => $now,
				),
				array(
					'id'     => (int) $job['id'],
					'status' => 'pending',
				),
				array( '%s', '%s', '%s', '%s' ),
				array( '%d', '%s' )
			);
		}

		return $jobs;
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
		$delay    = $attempts >= 3 ? 0 : min( 300, $attempts * 15 );

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

	public static function status_counts() {
		global $wpdb;

		$rows = $wpdb->get_results(
			'SELECT status, COUNT(*) AS total FROM ' . self::table_name() . ' GROUP BY status',
			ARRAY_A
		);

		$counts = array(
			'pending'    => 0,
			'processing' => 0,
			'failed'     => 0,
			'complete'   => 0,
			'stale'      => 0,
		);

		foreach ( $rows as $row ) {
			$status = ! empty( $row['status'] ) ? sanitize_key( (string) $row['status'] ) : '';
			if ( isset( $counts[ $status ] ) ) {
				$counts[ $status ] = (int) $row['total'];
			}
		}

		return $counts;
	}

	public static function pending_count() {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . self::table_name() . ' WHERE status = %s',
				'pending'
			)
		);
	}

	private static function expire_stale_jobs() {
		global $wpdb;

		$now = current_time( 'mysql', true );

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table_name() . ' SET status = %s, last_error = %s, locked_at = NULL, updated_at = %s WHERE status = %s AND stale_after IS NOT NULL AND stale_after <= %s',
				'stale',
				__( 'Request expired before it could be processed.', 'wp-telepilot' ),
				$now,
				'pending',
				$now
			)
		);
	}

	private static function release_abandoned_jobs( $lock_timeout ) {
		global $wpdb;

		$timeout = max( 60, absint( $lock_timeout ) );
		$now     = current_time( 'mysql', true );
		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - $timeout );

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table_name() . ' SET status = %s, locked_at = NULL, available_at = %s, updated_at = %s, last_error = %s WHERE status = %s AND locked_at IS NOT NULL AND locked_at <= %s',
				'pending',
				$now,
				$now,
				__( 'Worker lock expired; request re-queued.', 'wp-telepilot' ),
				'processing',
				$cutoff
			)
		);
	}

	private static function has_processing_job_for_chat( $chat_id, $exclude_job_id = 0 ) {
		global $wpdb;

		$sql = 'SELECT COUNT(*) FROM ' . self::table_name() . ' WHERE status = %s AND placeholder_chat_id = %s';
		$args = array( 'processing', (string) $chat_id );

		if ( $exclude_job_id > 0 ) {
			$sql   .= ' AND id <> %d';
			$args[] = (int) $exclude_job_id;
		}

		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) ) > 0;
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
