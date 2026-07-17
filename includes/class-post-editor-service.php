<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Telepilot_Post_Editor_Service {
	const TOKEN_PREFIX      = 'telepilot_post_editor_';
	const DEFAULT_EXPIRY    = 900;
	const HANDLER_ACTION    = 'telepilot_post_editor';

	public function create_edit_link( $post_id, WP_User $wp_user, $telegram_user_id = '', $expiration = self::DEFAULT_EXPIRY ) {
		$post = get_post( $post_id );

		if ( ! $post || 'post' !== $post->post_type ) {
			return new WP_Error( 'telepilot_post_not_found', __( 'Post not found.', 'wp-telepilot' ) );
		}

		if ( ! user_can( $wp_user, 'edit_post', $post_id ) ) {
			return new WP_Error( 'telepilot_post_edit_denied', __( 'You do not have permission to edit that post.', 'wp-telepilot' ) );
		}

		$expiration = max( 300, absint( $expiration ) );
		$expires_at = time() + $expiration;
		$token      = $this->generate_token();

		set_transient(
			self::TOKEN_PREFIX . $token,
			array(
				'post_id'          => (int) $post_id,
				'wp_user_id'       => (int) $wp_user->ID,
				'telegram_user_id' => (string) $telegram_user_id,
				'created_at'       => time(),
				'expires_at'       => $expires_at,
			),
			$expiration
		);

		return array(
			'token'      => $token,
			'url'        => $this->get_handler_url( $token ),
			'expires_at' => $expires_at,
			'post'       => $post,
		);
	}

	public function handle_request() {
		nocache_headers();

		$token   = $this->get_request_token();
		$session = $this->get_session( $token );

		if ( empty( $token ) || empty( $session ) ) {
			$this->render_error_page(
				__( 'Link Expired', 'wp-telepilot' ),
				__( 'That post editor link is invalid, expired, or has already been used. Generate a fresh link from Telegram and try again.', 'wp-telepilot' ),
				410
			);
		}

		$post = get_post( (int) $session['post_id'] );
		$user = get_user_by( 'id', (int) $session['wp_user_id'] );

		if ( ! $post || 'post' !== $post->post_type || ! $user instanceof WP_User || ! user_can( $user, 'edit_post', $post->ID ) ) {
			$this->delete_session( $token );
			$this->render_error_page(
				__( 'Editor Unavailable', 'wp-telepilot' ),
				__( 'That post can no longer be edited with this link.', 'wp-telepilot' ),
				403
			);
		}

		if ( 'POST' === strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) ) {
			$this->handle_save_request( $token, $session, $post, $user );
		}

		$this->render_editor_page( $post, $token );
	}

	private function handle_save_request( $token, $session, $post, $user ) {
		check_admin_referer( 'telepilot_post_editor_' . $token );

		$title   = isset( $_POST['telepilot_post_title'] ) ? sanitize_text_field( wp_unslash( $_POST['telepilot_post_title'] ) ) : $post->post_title;
		$excerpt = isset( $_POST['telepilot_post_excerpt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['telepilot_post_excerpt'] ) ) : $post->post_excerpt;
		$content = isset( $_POST['telepilot_post_content'] ) ? wp_unslash( $_POST['telepilot_post_content'] ) : $post->post_content;

		if ( ! user_can( $user, 'unfiltered_html' ) ) {
			$content = wp_kses_post( $content );
		}

		$before_state = array(
			'title'          => (string) $post->post_title,
			'excerpt_length' => strlen( (string) $post->post_excerpt ),
			'content_length' => strlen( (string) $post->post_content ),
			'status'         => (string) $post->post_status,
		);

		wp_set_current_user( $user->ID );

		$result = wp_update_post(
			array(
				'ID'           => (int) $post->ID,
				'post_title'   => $title,
				'post_excerpt' => $excerpt,
				'post_content' => $content,
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			$post->post_title   = $title;
			$post->post_excerpt = $excerpt;
			$post->post_content = $content;
			$this->render_editor_page( $post, $token, $result->get_error_message(), 'error' );
		}

		$updated_post = get_post( $post->ID );
		$this->delete_session( $token );

		Telepilot_Audit_Log_Repository::log(
			array(
				'wp_user_id'       => $user->ID,
				'telegram_user_id' => ! empty( $session['telegram_user_id'] ) ? (string) $session['telegram_user_id'] : null,
				'action_name'      => 'post_browser_editor_saved',
				'resource_type'    => 'post',
				'resource_id'      => (string) $post->ID,
				'before_state'     => $before_state,
				'after_state'      => array(
					'title'          => (string) $updated_post->post_title,
					'excerpt_length' => strlen( (string) $updated_post->post_excerpt ),
					'content_length' => strlen( (string) $updated_post->post_content ),
					'status'         => (string) $updated_post->post_status,
				),
			)
		);

		$this->render_success_page( $updated_post );
	}

	private function render_editor_page( $post, $token, $notice = '', $notice_type = 'info' ) {
		$preview_url  = get_permalink( $post );
		$status_label = ucfirst( (string) $post->post_status );
		$title        = get_the_title( $post );

		status_header( 200 );
		?>
		<!doctype html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php echo esc_html( sprintf( __( 'Edit Post: %s', 'wp-telepilot' ), $title ) ); ?></title>
			<style>
				body{margin:0;background:linear-gradient(180deg,#f8fbfd 0%,#eef4f7 100%);color:#1f2933;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
				.shell{max-width:1040px;margin:0 auto;padding:32px 20px 48px}
				.card{background:#fff;border:1px solid #d5dde3;border-radius:28px;box-shadow:0 24px 70px rgba(15,23,42,.06);padding:28px}
				.hero{margin-bottom:20px;padding:28px;border-radius:28px;background:linear-gradient(135deg,#1f2933,#111827);color:#fff}
				.kicker{margin:0 0 10px;font-size:11px;font-weight:800;letter-spacing:.22em;text-transform:uppercase;color:rgba(255,255,255,.7)}
				h1{margin:0;font-size:34px;line-height:1.1}
				p{line-height:1.7}
				.meta{display:flex;flex-wrap:wrap;gap:10px;margin-top:16px}
				.pill{display:inline-flex;align-items:center;justify-content:center;padding:8px 12px;border-radius:999px;font-size:11px;font-weight:800;letter-spacing:.16em;text-transform:uppercase;background:#ecfeff;color:#0f766e}
				.notice{margin-bottom:18px;padding:14px 16px;border-radius:18px;border:1px solid #d5dde3;background:#f8fbff}
				.notice.error{background:#fef3f2;border-color:#f3c1bc;color:#b42318}
				.grid{display:grid;gap:18px}
				label{display:block;font-weight:700;margin-bottom:8px}
				input[type=text],textarea{width:100%;border:1px solid #d5dde3;border-radius:18px;background:#f3f7f9;padding:14px 16px;color:#1f2933;font:inherit}
				textarea.content{min-height:420px;line-height:1.7}
				textarea.excerpt{min-height:120px}
				.actions{display:flex;flex-wrap:wrap;gap:12px;margin-top:24px}
				button,a.button{display:inline-flex;align-items:center;justify-content:center;padding:12px 18px;border-radius:999px;border:0;text-decoration:none;font-weight:700;cursor:pointer}
				button.primary{background:linear-gradient(135deg,#0ea5d3,#0b88af);color:#fff}
				a.secondary{background:#fff;color:#1f2933;border:1px solid #d5dde3}
				.help{margin-top:20px;color:#52606d}
			</style>
		</head>
		<body>
			<div class="shell">
				<section class="hero">
					<p class="kicker"><?php esc_html_e( 'WP Telepilot Long-Form Editor', 'wp-telepilot' ); ?></p>
					<h1><?php echo esc_html( $title ); ?></h1>
					<div class="meta">
						<span class="pill"><?php echo esc_html( sprintf( __( 'Post [%d]', 'wp-telepilot' ), $post->ID ) ); ?></span>
						<span class="pill"><?php echo esc_html( $status_label ); ?></span>
					</div>
					<p><?php esc_html_e( 'This secure browser editor is designed for longer post changes that are awkward to make in Telegram chat. Save here, then continue your workflow back in Telegram.', 'wp-telepilot' ); ?></p>
				</section>

				<section class="card">
					<?php if ( '' !== $notice ) : ?>
						<div class="notice <?php echo 'error' === $notice_type ? 'error' : ''; ?>"><?php echo esc_html( $notice ); ?></div>
					<?php endif; ?>

					<form method="post">
						<?php wp_nonce_field( 'telepilot_post_editor_' . $token ); ?>
						<input type="hidden" name="telepilot_post_editor_token" value="<?php echo esc_attr( $token ); ?>">
						<div class="grid">
							<div>
								<label for="telepilot-post-title"><?php esc_html_e( 'Title', 'wp-telepilot' ); ?></label>
								<input id="telepilot-post-title" type="text" name="telepilot_post_title" value="<?php echo esc_attr( $post->post_title ); ?>">
							</div>
							<div>
								<label for="telepilot-post-excerpt"><?php esc_html_e( 'Excerpt', 'wp-telepilot' ); ?></label>
								<textarea id="telepilot-post-excerpt" class="excerpt" name="telepilot_post_excerpt"><?php echo esc_textarea( $post->post_excerpt ); ?></textarea>
							</div>
							<div>
								<label for="telepilot-post-content"><?php esc_html_e( 'Content', 'wp-telepilot' ); ?></label>
								<textarea id="telepilot-post-content" class="content" name="telepilot_post_content"><?php echo esc_textarea( $post->post_content ); ?></textarea>
							</div>
						</div>

						<div class="actions">
							<button class="primary" type="submit"><?php esc_html_e( 'Save Changes', 'wp-telepilot' ); ?></button>
							<?php if ( 'publish' === $post->post_status && $preview_url ) : ?>
								<a class="button secondary" href="<?php echo esc_url( $preview_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Preview Post', 'wp-telepilot' ); ?></a>
							<?php endif; ?>
						</div>
					</form>

					<p class="help"><?php esc_html_e( 'This link expires automatically and is intended only for this editing session. After saving, return to Telegram to publish, review, or continue managing the post.', 'wp-telepilot' ); ?></p>
				</section>
			</div>
		</body>
		</html>
		<?php
		exit;
	}

	private function render_success_page( $post ) {
		$preview_url = get_permalink( $post );

		status_header( 200 );
		?>
		<!doctype html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php esc_html_e( 'Post Saved', 'wp-telepilot' ); ?></title>
			<style>
				body{margin:0;background:linear-gradient(180deg,#f8fbfd 0%,#eef4f7 100%);color:#1f2933;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
				.shell{max-width:760px;margin:0 auto;padding:40px 20px}
				.card{background:#fff;border:1px solid #d5dde3;border-radius:28px;box-shadow:0 24px 70px rgba(15,23,42,.06);padding:32px}
				.badge{display:inline-flex;align-items:center;justify-content:center;padding:8px 14px;border-radius:999px;background:#ecfeff;color:#0f766e;font-size:11px;font-weight:800;letter-spacing:.18em;text-transform:uppercase}
				h1{margin:16px 0 12px;font-size:34px;line-height:1.1}
				p{line-height:1.8}
				.actions{display:flex;flex-wrap:wrap;gap:12px;margin-top:24px}
				a.button{display:inline-flex;align-items:center;justify-content:center;padding:12px 18px;border-radius:999px;text-decoration:none;font-weight:700}
				a.primary{background:linear-gradient(135deg,#0ea5d3,#0b88af);color:#fff}
				a.secondary{background:#fff;color:#1f2933;border:1px solid #d5dde3}
			</style>
		</head>
		<body>
			<div class="shell">
				<div class="card">
					<span class="badge"><?php esc_html_e( 'Saved', 'wp-telepilot' ); ?></span>
					<h1><?php esc_html_e( 'Post updated successfully', 'wp-telepilot' ); ?></h1>
					<p><?php esc_html_e( 'Your long-form changes were saved. You can now return to Telegram to keep working with WP Telepilot.', 'wp-telepilot' ); ?></p>
					<div class="actions">
						<?php if ( 'publish' === $post->post_status && $preview_url ) : ?>
							<a class="button primary" href="<?php echo esc_url( $preview_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Preview Post', 'wp-telepilot' ); ?></a>
						<?php endif; ?>
						<a class="button secondary" href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open Site', 'wp-telepilot' ); ?></a>
					</div>
				</div>
			</div>
		</body>
		</html>
		<?php
		exit;
	}

	private function render_error_page( $title, $message, $status_code = 403 ) {
		status_header( (int) $status_code );
		?>
		<!doctype html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php echo esc_html( $title ); ?></title>
			<style>
				body{margin:0;background:#f8fbfd;color:#1f2933;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
				.shell{max-width:760px;margin:0 auto;padding:48px 20px}
				.card{background:#fff;border:1px solid #f3c1bc;border-radius:28px;box-shadow:0 24px 70px rgba(15,23,42,.06);padding:32px}
				h1{margin:0 0 12px;font-size:34px;line-height:1.1;color:#b42318}
				p{line-height:1.8}
			</style>
		</head>
		<body>
			<div class="shell">
				<div class="card">
					<h1><?php echo esc_html( $title ); ?></h1>
					<p><?php echo esc_html( $message ); ?></p>
				</div>
			</div>
		</body>
		</html>
		<?php
		exit;
	}

	private function get_request_token() {
		if ( isset( $_POST['telepilot_post_editor_token'] ) ) {
			return $this->normalize_token( wp_unslash( $_POST['telepilot_post_editor_token'] ) );
		}

		if ( isset( $_GET['token'] ) ) {
			return $this->normalize_token( wp_unslash( $_GET['token'] ) );
		}

		return '';
	}

	private function get_session( $token ) {
		if ( '' === $token ) {
			return array();
		}

		$session = get_transient( self::TOKEN_PREFIX . $token );

		return is_array( $session ) ? $session : array();
	}

	private function delete_session( $token ) {
		if ( '' === $token ) {
			return;
		}

		delete_transient( self::TOKEN_PREFIX . $token );
	}

	private function get_handler_url( $token ) {
		return add_query_arg(
			array(
				'action' => self::HANDLER_ACTION,
				'token'  => rawurlencode( $token ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	private function generate_token() {
		return $this->normalize_token( wp_generate_password( 24, false, false ) );
	}

	private function normalize_token( $token ) {
		return sanitize_key( (string) $token );
	}
}
