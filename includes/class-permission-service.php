<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Telepilot_Permission_Service {
	public function user_can( $wp_user, $capability, $object_id = null ) {
		if ( ! ( $wp_user instanceof WP_User ) ) {
			return false;
		}

		if ( null === $object_id ) {
			return user_can( $wp_user, $capability );
		}

		return user_can( $wp_user, $capability, $object_id );
	}

	public function require_linked_user( $identity ) {
		if ( ! empty( $identity['wp_user'] ) && $identity['wp_user'] instanceof WP_User ) {
			return true;
		}

		return Telepilot_Telegram_Response_Builder::error(
			__( 'Your Telegram account is not linked to a WordPress user. Generate a code in your profile and send `/link CODE`.', 'telepilot' ),
			array(
				'code' => 'telepilot_link_required',
			)
		);
	}

	public function require_capability( $identity, $capability, $object_id = null ) {
		$link_result = $this->require_linked_user( $identity );

		if ( true !== $link_result ) {
			return $link_result;
		}

		if ( $this->user_can( $identity['wp_user'], $capability, $object_id ) ) {
			return true;
		}

		return Telepilot_Telegram_Response_Builder::error(
			__( 'You do not have permission to perform that action.', 'telepilot' ),
			array(
				'code'       => 'telepilot_capability_denied',
				'capability' => $capability,
			)
		);
	}

	public function require_private_chat( $identity ) {
		if ( ! empty( $identity['chat_type'] ) && 'private' === $identity['chat_type'] ) {
			return true;
		}

		return Telepilot_Telegram_Response_Builder::error(
			__( 'This action is only available in a private chat with your WP Telepilot bot.', 'telepilot' ),
			array(
				'code' => 'telepilot_private_chat_required',
			)
		);
	}
}
