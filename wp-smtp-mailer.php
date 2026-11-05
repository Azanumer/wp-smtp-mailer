<?php
/**
 * Plugin Name: SMTP Mailer
 * Plugin URI: https://github.com/Azanumer/wp-smtp-mailer
 * Description: Routes all WordPress mail through your own SMTP server (with TLS/SSL, auth and a one-click test email) so password resets and contact-form mail stop landing in spam — or never arriving at all.
 * Version: 1.0.0
 * Author: Azan Umer
 * Author URI: https://azanumer.cv
 * License: MIT
 * Text Domain: wp-smtp-mailer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WSM_OPTION', 'wsm_smtp_settings' );

/**
 * Default settings. Port 587 + TLS is the sane default for most hosts.
 */
function wsm_default_settings() {
    return array(
        'host'       => '',
        'port'       => 587,
        'encryption' => 'tls', // none | ssl | tls
        'auth'       => 1,
        'username'   => '',
        'password'   => '',
        'from_email' => '',
        'from_name'  => get_bloginfo( 'name' ),
    );
}

function wsm_get_settings() {
    $saved = get_option( WSM_OPTION, array() );
    if ( ! is_array( $saved ) ) {
        $saved = array();
    }
    return array_merge( wsm_default_settings(), $saved );
}

/**
 * Point PHPMailer at the configured SMTP server. Runs on every wp_mail().
 */
function wsm_configure_phpmailer( $phpmailer ) {
    $s = wsm_get_settings();

    if ( empty( $s['host'] ) ) {
        return; // Not configured yet — leave WordPress mail untouched.
    }

    $phpmailer->isSMTP();
    $phpmailer->Host       = $s['host'];
    $phpmailer->Port       = (int) $s['port'];
    $phpmailer->SMTPAuth   = (bool) $s['auth'];
    $phpmailer->SMTPSecure = ( 'none' === $s['encryption'] ) ? '' : $s['encryption'];

    if ( $phpmailer->SMTPAuth ) {
        $phpmailer->Username = $s['username'];
        $phpmailer->Password = $s['password'];
    }

    if ( ! empty( $s['from_email'] ) && is_email( $s['from_email'] ) ) {
        $phpmailer->setFrom( $s['from_email'], $s['from_name'] );
    }
}
add_action( 'phpmailer_init', 'wsm_configure_phpmailer' );

/**
 * Remember the outcome of the last send so the settings page can show it.
 * wp_mail_succeeded passes the mail data array; wp_mail_failed passes a
 * WP_Error whose error data IS the mail data array.
 */
function wsm_log_mail_result( $data, $success ) {
    if ( $data instanceof WP_Error ) {
        $data = $data->get_error_data();
    }
    if ( ! is_array( $data ) ) {
        $data = array();
    }
    $to = isset( $data['to'] ) ? $data['to'] : '';
    if ( is_array( $to ) ) {
        $to = implode( ', ', $to );
    }
    update_option( 'wsm_last_result', array(
        'time'    => current_time( 'mysql' ),
        'success' => (bool) $success,
        'to'      => $to,
        'subject' => isset( $data['subject'] ) ? $data['subject'] : '',
    ), false );
}

function wsm_log_mail_succeeded( $mail_data ) {
    wsm_log_mail_result( $mail_data, true );
}
add_action( 'wp_mail_succeeded', 'wsm_log_mail_succeeded' );

function wsm_log_mail_failed( $error ) {
    wsm_log_mail_result( $error, false );
}
add_action( 'wp_mail_failed', 'wsm_log_mail_failed' );

/* ------------------------------------------------------------------ */
/* Settings page                                                       */
/* ------------------------------------------------------------------ */

function wsm_register_settings() {
    register_setting( 'wsm_settings_group', WSM_OPTION, 'wsm_sanitize_settings' );
}
add_action( 'admin_init', 'wsm_register_settings' );

function wsm_sanitize_settings( $input ) {
    $out = wsm_default_settings();

    $out['host']       = isset( $input['host'] ) ? sanitize_text_field( $input['host'] ) : '';
    $out['port']       = isset( $input['port'] ) ? max( 1, min( 65535, (int) $input['port'] ) ) : 587;
    $out['encryption'] = ( isset( $input['encryption'] ) && in_array( $input['encryption'], array( 'none', 'ssl', 'tls' ), true ) )
        ? $input['encryption'] : 'tls';
    $out['auth']       = ! empty( $input['auth'] ) ? 1 : 0;
    $out['username']   = isset( $input['username'] ) ? sanitize_text_field( $input['username'] ) : '';
    // Keep the stored password when the field is left blank on save.
    if ( isset( $input['password'] ) && '' !== $input['password'] ) {
        $out['password'] = $input['password'];
    } else {
        $current          = wsm_get_settings();
        $out['password']  = $current['password'];
    }
    $out['from_email'] = isset( $input['from_email'] ) ? sanitize_email( $input['from_email'] ) : '';
    $out['from_name']  = isset( $input['from_name'] ) ? sanitize_text_field( $input['from_name'] ) : '';

    return $out;
}

function wsm_add_settings_page() {
    add_options_page(
        __( 'SMTP Mailer', 'wp-smtp-mailer' ),
        __( 'SMTP Mailer', 'wp-smtp-mailer' ),
        'manage_options',
        'wp-smtp-mailer',
        'wsm_render_settings_page'
    );
}
add_action( 'admin_menu', 'wsm_add_settings_page' );

/**
 * Handle the "Send test email" button before the page renders.
 */
function wsm_maybe_send_test_email() {
    if ( ! isset( $_POST['wsm_test_email'] ) ) {
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to do that.', 'wp-smtp-mailer' ) );
    }
    check_admin_referer( 'wsm_test_email' );

    $to      = sanitize_email( wp_unslash( $_POST['wsm_test_to'] ) );
    $sent    = false;
    $message = '';

    if ( ! is_email( $to ) ) {
        $message = __( 'Please enter a valid recipient email address.', 'wp-smtp-mailer' );
    } else {
        $sent = wp_mail(
            $to,
            sprintf( __( '[%s] SMTP test email', 'wp-smtp-mailer' ), get_bloginfo( 'name' ) ),
            __( 'If you are reading this, WordPress is sending mail through your SMTP server.', 'wp-smtp-mailer' )
        );
        $message = $sent
            ? __( 'Test email sent successfully.', 'wp-smtp-mailer' )
            : __( 'Test email failed. Check your SMTP host, port, encryption and credentials — and the "Last send" box below.', 'wp-smtp-mailer' );
    }

    add_settings_error( 'wsm_messages', 'wsm_test_email', $message, $sent ? 'updated' : 'error' );
}
add_action( 'admin_init', 'wsm_maybe_send_test_email' );

function wsm_render_settings_page() {
    $s    = wsm_get_settings();
    $last = get_option( 'wsm_last_result', false );
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'SMTP Mailer', 'wp-smtp-mailer' ); ?></h1>
        <?php settings_errors( 'wsm_messages' ); ?>
        <form method="post" action="options.php">
            <?php settings_fields( 'wsm_settings_group' ); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="wsm_host"><?php esc_html_e( 'SMTP Host', 'wp-smtp-mailer' ); ?></label></th>
                    <td><input type="text" id="wsm_host" name="<?php echo esc_attr( WSM_OPTION ); ?>[host]" value="<?php echo esc_attr( $s['host'] ); ?>" class="regular-text" placeholder="mail.example.com"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="wsm_port"><?php esc_html_e( 'SMTP Port', 'wp-smtp-mailer' ); ?></label></th>
                    <td><input type="number" id="wsm_port" name="<?php echo esc_attr( WSM_OPTION ); ?>[port]" value="<?php echo esc_attr( $s['port'] ); ?>" min="1" max="65535" class="small-text"> <span class="description"><?php esc_html_e( '587 for TLS, 465 for SSL, 25 for plain.', 'wp-smtp-mailer' ); ?></span></td>
                </tr>
                <tr>
                    <th scope="row"><label for="wsm_encryption"><?php esc_html_e( 'Encryption', 'wp-smtp-mailer' ); ?></label></th>
                    <td>
                        <select id="wsm_encryption" name="<?php echo esc_attr( WSM_OPTION ); ?>[encryption]">
                            <option value="tls" <?php selected( $s['encryption'], 'tls' ); ?>>TLS</option>
                            <option value="ssl" <?php selected( $s['encryption'], 'ssl' ); ?>>SSL</option>
                            <option value="none" <?php selected( $s['encryption'], 'none' ); ?>><?php esc_html_e( 'None', 'wp-smtp-mailer' ); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Authentication', 'wp-smtp-mailer' ); ?></th>
                    <td><label><input type="checkbox" name="<?php echo esc_attr( WSM_OPTION ); ?>[auth]" value="1" <?php checked( $s['auth'], 1 ); ?>> <?php esc_html_e( 'Use SMTP authentication', 'wp-smtp-mailer' ); ?></label></td>
                </tr>
                <tr>
                    <th scope="row"><label for="wsm_username"><?php esc_html_e( 'Username', 'wp-smtp-mailer' ); ?></label></th>
                    <td><input type="text" id="wsm_username" name="<?php echo esc_attr( WSM_OPTION ); ?>[username]" value="<?php echo esc_attr( $s['username'] ); ?>" class="regular-text" autocomplete="off"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="wsm_password"><?php esc_html_e( 'Password', 'wp-smtp-mailer' ); ?></label></th>
                    <td><input type="password" id="wsm_password" name="<?php echo esc_attr( WSM_OPTION ); ?>[password]" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo $s['password'] ? esc_attr__( '(saved — leave blank to keep)', 'wp-smtp-mailer' ) : ''; ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="wsm_from_email"><?php esc_html_e( 'From Email', 'wp-smtp-mailer' ); ?></label></th>
                    <td><input type="email" id="wsm_from_email" name="<?php echo esc_attr( WSM_OPTION ); ?>[from_email]" value="<?php echo esc_attr( $s['from_email'] ); ?>" class="regular-text" placeholder="wordpress@example.com"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="wsm_from_name"><?php esc_html_e( 'From Name', 'wp-smtp-mailer' ); ?></label></th>
                    <td><input type="text" id="wsm_from_name" name="<?php echo esc_attr( WSM_OPTION ); ?>[from_name]" value="<?php echo esc_attr( $s['from_name'] ); ?>" class="regular-text"></td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>

        <h2><?php esc_html_e( 'Send a test email', 'wp-smtp-mailer' ); ?></h2>
        <form method="post">
            <?php wp_nonce_field( 'wsm_test_email' ); ?>
            <p>
                <input type="email" name="wsm_test_to" value="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" class="regular-text" required>
                <input type="submit" name="wsm_test_email" class="button button-secondary" value="<?php esc_attr_e( 'Send Test Email', 'wp-smtp-mailer' ); ?>">
            </p>
        </form>

        <?php if ( $last ) : ?>
        <h2><?php esc_html_e( 'Last send', 'wp-smtp-mailer' ); ?></h2>
        <p>
            <?php echo $last['success'] ? esc_html__( 'Succeeded', 'wp-smtp-mailer' ) : esc_html__( 'Failed', 'wp-smtp-mailer' ); ?>
            &middot; <?php echo esc_html( $last['time'] ); ?>
            &middot; <?php echo esc_html( $last['to'] ); ?>
            &middot; <?php echo esc_html( $last['subject'] ); ?>
        </p>
        <?php endif; ?>
    </div>
    <?php
}
