<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Telepilot_Rate_Limiter {
	public function check( $identity, $limit_per_minute ) {
		$limit_per_minute = max( 1, absint( $limit_per_minute ) );
		$key              = $this->build_key( $identity );

		if ( '' === $key ) {
			return true;
		}

		$current = (int) get_transient( $key );

		if ( $current >= $limit_per_minute ) {
			return Telepilot_Telegram_Response_Builder::error(
				__( 'You are sending commands too quickly. Please wait a moment and try again.', 'wp-telepilot' ),
				array(
					'code'  => 'telepilot_rate_limited',
					'limit' => $limit_per_minute,
				)
			);
		}

		set_transient( $key, $current + 1, MINUTE_IN_SECONDS );

		return true;
	}

	private function build_key( $identity ) {
		if ( ! empty( $identity['telegram_user_id'] ) ) {
			return 'telepilot_rate_' . md5( (string) $identity['telegram_user_id'] ) . '_' . gmdate( 'YmdHi' );
		}

		if ( ! empty( $identity['chat_id'] ) ) {
			return 'telepilot_rate_chat_' . md5( (string) $identity['chat_id'] ) . '_' . gmdate( 'YmdHi' );
		}

		return '';
	}
}
