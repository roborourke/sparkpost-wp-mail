<?php
/**
 * Plugin Name:  SparkPost wp_mail Drop-In
 * Plugin URI:   https://github.com/sanchothefat/sparkpost-wp-mail
 * Description:  Drop-in replacement for wp_mail using the SparkPost API.
 * Version:      0.0.2
 * Author:       Daniel Bachhuber, Robert O'Rourke
 * Author URI:   https://github.com/sanchothefat
 * License:      GPL-3.0+
 * License URI:  http://www.gnu.org/licenses/gpl-3.0.html
 */

/**
 * Override WordPress' default wp_mail function with one that sends email
 * using SparkPost's API.
 *
 * Note that this function requires the SPARKPOST_API_KEY constant to be defined
 * in order for it to work. The easiest place to define this is in wp-config.
 *
 * @since  0.0.1
 * @access public
 * @todo   Add support for attachments
 * @param  string $to
 * @param  string $subject
 * @param  string $message
 * @param  mixed  $headers
 * @param  array  $attachments
 * @return bool true if mail has been sent, false if it failed
 */
function wp_mail( $to, $subject, $message, $headers = '', $attachments = array() ) {
	// Return early if our API key hasn't been defined.
	if ( ! defined( 'SPARKPOST_API_KEY' ) ) {
		return false;
	}

	// Compact the input, apply the filters, and extract them back out
	extract( apply_filters( 'wp_mail', compact( 'to', 'subject', 'message', 'headers', 'attachments' ) ) );

	// Get the site domain and get rid of www.
	$sitename = strtolower( parse_url( site_url(), PHP_URL_HOST ) );
	if ( 'www.' === substr( $sitename, 0, 4 ) ) {
		$sitename = substr( $sitename, 4 );
	}

	$from_email = 'wordpress@' . $sitename;

	$message_args = array(
		// Email
		'recipients'         => array( // json array
			array(
				'address'           => array( // string|json object
					'email'     => $to, // string
					'name'      => null, // string
					'header_to' => null, // string
				),
				'return_path'       => null, // string    Elite only
				'tags'              => array(), // json array
				'metadata'          => null, // json object
				'substitution_data' => null, // json object
			),
		),
		'content'            => array( // json object
			// 'html'          => '', // string - html OR text must be supplied, html takes precedence
			// 'text'          => '', // string
			'subject'       => $subject, // string
			'from'          => array( // string|json object
				'email' => $from_email,
				'name'  => get_bloginfo( 'name' ),
			),
			'reply_to'      => null, // string
			'headers'       => null, // json obect
			'attachments'   => array(), // json array
			// 'inline_images' => array(), // json array - requires HTML
		),

		// Options
		'options'            => array( // json object
			'start_time'       => 'now', // string  YYYY-MM-DDTHH:MM:SS+-HH:MM
			'open_tracking'    => false, // bool
			'click_tracking'   => false, // bool
			'transactional'    => false, // bool
			'sandbox'          => false, // bool
			'skip_suppression' => false, // bool
			'inline_css'       => false, // bool
		),

		// SparkPost defaults
		'headers'            => array(
			'Content-type'  => 'application/json',
			'Authorization' => SPARKPOST_API_KEY,
			'User-Agent'    => 'sparkpost-wp-mail',
		),
		'description'        => null, // string
		'campaign_id'        => null, // string
		'metadata'           => null, // json object
		'substitution_data'  => null, // json object
		'return_path'        => null, // string    Elite only
		'template_id'        => null, // string
		'use_draft_template' => false, // bool
	);

	// Set up message headers if we have any to send.
	if ( ! empty( $headers ) ) {
		$message_args = _sparkpost_wp_mail_headers( $headers, $message_args );
	}

	// Set mail body depending on existing content type headers
	if ( isset( $message_args['content']['headers']['x-content-type'] ) &&
		false !== strpos( $message_args['content']['headers']['x-content-type'], 'text/html' )
	) {
		$message_args['content']['html'] = $message;
		$message_args['content']['inline_images'] = array();
	} else {
		$message_args['content']['text'] = $message;
	}

	$message_args = apply_filters( 'sparkpost_wp_mail_pre_message_args', $message_args );

	// Make sure our recipients value is an array so we can manipulate it for the API.
	if ( ! is_array( $message_args['recipients'] ) ) {
		$message_args['recipients'] = explode( ',', $message_args['recipients'] );
	}

	// Sneaky support for multiple to addresses.
	$processed_to = array();
	foreach ( (array) $message_args['recipients'] as $email ) {
		if ( is_array( $email ) ) {
			$processed_to[] = $email;
		} else {
			$user = get_user_by( 'email', $email );
			if ( $user ) {
				$processed_to[] = array(
					'address' => array(
						'email' => $email,
						'name'  => $user->get( 'display_name' ),
					),
				);
			} else {
				$processed_to[] = array(
					'address' => array(
						'email' => $email,
					),
				);
			}
		}
	}
	$message_args['recipients'] = $processed_to;

	// Attachments
	foreach ( (array) $attachments as $attachment ) {
		$message_args['content']['attachments'][] = array(
			'type' => mime_content_type( $attachment ),
			'name' => basename( $attachment ),
			'data' => 'data:' . mime_content_type( $attachment ) . ';base64,' . base64_encode( file_get_contents( $attachment ) ),
		);
	}

	// Default filters we should still apply.
	$message_args['content']['from']['email'] = apply_filters( 'wp_mail_from', $message_args['content']['from']['email'] );
	$message_args['content']['from']['name']  = apply_filters( 'wp_mail_from_name', $message_args['content']['from']['name'] );

	// Allow user to override message args before they're sent to SparkPost.
	$message_args = apply_filters( 'sparkpost_wp_mail_message_args', $message_args );

	$request_args = array(
		'headers' => $message_args['headers'],
		'body'    => wp_json_encode( $message_args ),
	);

	$request_url = 'https://api.sparkpost.com/api/v1/transmissions';
	$response    = wp_remote_post( $request_url, $request_args );
	if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
		return false;
	}

	return true;
}

/**
 * Handle email headers before they're sent to the SparkPost API.
 *
 * @since  0.0.2
 * @access private
 * @todo   Improve BCC handling
 * @param  mixed $headers
 * @param  array $message_args
 * @return array $message_args
 */
function _sparkpost_wp_mail_headers( $headers, $message_args ) {
	if ( ! is_array( $message_args ) ) {
		return $message_args;
	}

	// Prepare the passed headers.
	if ( ! is_array( $headers ) ) {
		$headers = explode( "\n", str_replace( "\r\n", "\n", $headers ) );
	}

	// Bail if we don't have any headers to work with.
	if ( empty( $headers ) ) {
		return $message_args;
	}

	foreach ( (array) $headers as $index => $header ) {

		if ( false === strpos( $header, ':' ) ) {
			continue;
		}

		// Explode them out
		list( $name, $content ) = explode( ':', trim( $header ), 2 );

		// Cleanup crew
		$name    = trim( $name );
		$content = trim( $content );

		switch ( strtolower( $name ) ) {

			// SparkPost handles these separately
			case 'subject':
			case 'from':
			case 'to':
			case 'reply-to':
				unset( $headers[ $index ] );
				break;

			case 'cc':
				$cc           = explode( ',', $content );
				$processed_cc = array();
				foreach ( (array) $cc as $email ) {
					$processed_cc[] = array(
						'email' => trim( $email ),
						'type'  => 'cc',
					);
				}
				$message_args['content']['headers']['cc'] = implode( ';', array_merge( $message_args['content']['headers']['cc'], $processed_cc ) );
				break;

			case 'bcc':
				$bcc           = explode( ',', $content );
				$processed_bcc = array();
				foreach ( (array) $bcc as $email ) {
					$processed_bcc[] = array(
						'email' => trim( $email ),
						'type'  => 'bcc',
					);
				}
				$message_args['content']['headers']['bcc'] = implode( ';', array_merge( $message_args['content']['headers']['bcc'], $processed_bcc ) );
				break;

			case 'content-type':
				$message_args['content']['headers'][ 'x-' . trim( strtolower( $name ) ) ] = trim( $content );
				break;

			default:
				if ( 'x-' === substr( $name, 0, 2 ) ) {
					$message_args['content']['headers'][ trim( $name ) ] = trim( $content );
				}
				break;
		}
	}
	return apply_filters( 'sparkpost_wp_mail_headers', $message_args );
}
