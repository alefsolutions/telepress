<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Telepilot_Deactivator {
	public static function deactivate() {
		wp_clear_scheduled_hook( 'telepilot_daily_maintenance' );
		wp_clear_scheduled_hook( 'telepilot_poll_updates' );
		wp_clear_scheduled_hook( 'telepilot_process_jobs' );
		delete_transient( Telepilot_Telegram_Service::POLL_LOCK_TRANSIENT );

		$settings = get_option( 'telepilot_settings', array() );
		$token    = isset( $settings['bot_token'] ) ? (string) $settings['bot_token'] : '';

		if ( '' !== $token ) {
			$client = new Telepilot_Telegram_Client( $token );
			$client->delete_webhook();
		}
	}
}
