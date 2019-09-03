<?php
/*
Plugin Name: NMU Mandrill
Description: lightweight intergration of the Mandrill transactional email service
Author: New Music USA
Version: 1.0
*/

require 'Mandrill.php';

if (!defined('MANDRILL_KEY')) {
	define('MANDRILL_KEY', 'xxx');
}

if (!defined('MANDRILL_FROM_EMAIL')) {
	define('MANDRILL_FROM_EMAIL', 'info@newmusicusa.org');
}

if (!defined('MANDRILL_FROM_NAME')) {
	define('MANDRILL_FROM_NAME', 'New Music USA');
}

define('MANDRILL_TEMPLATE', 'nmu-basic');

/**
 * convenience function that returns an instance of the Mandrill class for interacting
 * with their API as needed
 */
function nm_mandrill_get_instance() {
	$mandrill = new Mandrill(MANDRILL_KEY);
	return $mandrill;
}

/**
 * convenience function that creates a starter message struct with our particular defaults
 * from there you can override and then add message body, recipients and further settings
 *
 * @param bool use click tracking for message? defaults to false
 */
function nm_mandrill_get_default_message($clickTracking = false) {
	$message = [
		'track_opens' => true,
		'track_clicks' => $clickTracking,
		'preserve_recipients' => false,
		'auto_html' => true,
		'auto_text' => true
	];

	return $message;
}

/**
 * our redefinition of the native wp_mail() function
 */
if (!function_exists('wp_mail')) {
	function wp_mail($to, $subject, $message_body, $headers = '', $attachments = []) {
		$mandrill = nm_mandrill_get_instance();

		if (!isset($mandrill)) {
			error_log("Mandrill connection failed, using fallback to send mail.");
			$outcome = nm_mandrill_mail_fallback($to, $subject, $message_body, $headers, $attachments);
			return $outcome;
		}

		$message = nm_mandrill_get_default_message();

		$message['subject'] = $subject;
		
		//user-supplied headers
		if (empty($headers)) {
            $message['headers'] = [];
        } else {
            if (!is_array($headers)) {
	            $tempheaders = explode("\n", str_replace("\r\n", "\n", $headers));
            } else {
	            $tempheaders = $headers;
            }
            $message['headers'] = array();

            // If it's actually got contents
            if (!empty($tempheaders)) {
	            // Iterate through the raw headers
	            foreach ((array) $tempheaders as $header) {
		            if (strpos($header, ':') === false ) continue;

		            list($name, $content) = explode(':', trim($header), 2);

		            $name = trim($name);
		            $content = trim($content);

		            switch (strtolower($name)) {
			            case 'from':
				            if ( strpos($content, '<' ) !== false ) {
					            // So... making my life hard again?
					            $from_name = substr( $content, 0, strpos( $content, '<' ) - 1 );
					            $from_name = str_replace( '"', '', $from_name );
					            $from_name = trim( $from_name );

					            $from_email = substr( $content, strpos( $content, '<' ) + 1 );
					            $from_email = str_replace( '>', '', $from_email );
					            $from_email = trim( $from_email );
				            } else {
					            $from_name  = '';
					            $from_email = trim( $content );
				            }
				            $message['from_email'] = $from_email;
				            $message['from_name'] = $from_name;						            
				            break; 
			            case 'bcc':
			                //Mandrill's API only accept one BCC address. Other addresses will be silently discarded
			                $bcc = array_merge( (array) $bcc, explode( ',', $content ) );
			                $message['bcc_address'] = $bcc[0];
				            break;       
			            case 'reply-to':
				            $message['headers'][trim( $name )] = trim( $content );
				            break;
			            case 'importance':
			            case 'x-priority':
			            case 'x-msmail-priority':
			            	if ( !$message['important'] ) $message['important'] = ( strpos(strtolower($content),'high') !== false ) ? true : false;
			            	break;
			            default:
			                if (substr($name,0,2) == 'x-') {
					            $message['headers'][trim($name)] = trim($content);
					        }
				            break;
		            }
	            }
            }
        }

		//to
		if (!is_array($to)) 
			$to = explode(',', $to);
        
        $processed_to = array();
        
        foreach ($to as $recipient) {
        	$recipient_name = '';
        	if (preg_match( '/(.*)<(.+)>/', $recipient, $matches)) {
				if (count($matches) == 3) {
					$recipient_name = $matches[1];
					$recipient = $matches[2];
				}
			}
			$processed_to[] = ['email' => $recipient, 'name' => $recipient_name, 'type' => 'to'];    
        }

        $message['to'] = $processed_to;

		//from
		if (empty($message['from_email']))
			$message['from_email'] = MANDRILL_FROM_EMAIL;

		if (empty($message['from_name']))
			$message['from_name'] = MANDRILL_FROM_NAME;

		//attachments
		if (count($attachments)) {
			foreach ($attachments as $path) {
				$att_struct = nm_mandrill_process_attachment($path);
				if (is_array($att_struct))
					$message['attachments'][] = $att_struct;
			}
		}

		//tags
		$trace = debug_backtrace();
		$level = 6;        
		$function = $trace[$level]['function'];

        $tags = array();
		if ('include' == $function || 'require' == $function) {
			$file = basename($trace[$level]['args'][0]);
			$tags[] = "wp_{$file}";
		} else {
			if (isset($trace[$level]['class']))
				$function = $trace[$level]['class'].$trace[$level]['type'].$function;
			$tags[] = $function;
		}
		$message['tags'] = $tags;

		//make sure url's in message are not surround by angle brackets (messes with html parsing)
		$message_body = preg_replace('/<(https?:\/\/[^*]+)>/', '$1', $message_body);

		$template_content = [
			['name' => 'main', 'content' => nl2br($message_body)]
		];

		try {
			$outcome = $mandrill->messages->sendTemplate(MANDRILL_TEMPLATE, $template_content, $message);

			if ($outcome[0]['status'] == 'sent' || $outcome[0]['status'] == 'queued') {
				return true;
			} else {
				return false;
			}
		} catch (Exception $e) {
			error_log("Mandrill exception while sending mail for " . implode(',', $message['tags']) . " " . get_class($e) . ": " . $e->getMessage());

			$use_fallback = [
				'Mandrill_HttpError',
				'Mandrill_Invalid_Key',
				'Mandrill_PaymentRequired',
				'Mandrill_ServiceUnavailable'
			];

			if (in_array(get_class($e), $use_fallback)) {
				$outcome = nm_mandrill_mail_fallback($to, $subject, $message_body, $headers, $attachments);
				return $outcome;
			}
			
			return false;
		}
	}
}

/**
 * helper function that packages up attachment into data structure required by Mandrill API
 *
 * @param string path to the file
 * @return array|bool array with attachment data or false if file can't be found
 */
function nm_mandrill_process_attachment($path) {
	$struct = array();
     
    if (!@is_file($path))
    	return false;

    $filename = basename($path);
    
    $file_buffer  = file_get_contents($path);
    $file_buffer  = chunk_split(base64_encode($file_buffer), 76, "\n");
    
    $mime_type = '';
	if ( function_exists('finfo_open') && function_exists('finfo_file') ) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $path);
    } elseif ( function_exists('mime_content_type') ) {
        $mime_type = mime_content_type($path);
    }

    if (!empty($mime_type)) 
    	$struct['type'] = $mime_type;
    
    $struct['name'] = $filename;
    $struct['content'] = $file_buffer;

    return $struct;
}

/**
 * copy of the default wp_mail() function to use as fallback
 *
 * @param string|array $to          Array or comma-separated list of email addresses to send message.
 * @param string       $subject     Email subject
 * @param string       $message     Message contents
 * @param string|array $headers     Optional. Additional headers.
 * @param string|array $attachments Optional. Files to attach.
 * @return bool Whether the email contents were sent successfully.
 */
function nm_mandrill_mail_fallback($to, $subject, $message, $headers = '', $attachments = []) {
	// Compact the input, apply the filters, and extract them back out

	/**
	 * Filters the wp_mail() arguments.
	 *
	 * @since 2.2.0
	 *
	 * @param array $args A compacted array of wp_mail() arguments, including the "to" email,
	 *                    subject, message, headers, and attachments values.
	 */
	$atts = apply_filters( 'wp_mail', compact( 'to', 'subject', 'message', 'headers', 'attachments' ) );

	if ( isset( $atts['to'] ) ) {
		$to = $atts['to'];
	}

	if ( !is_array( $to ) ) {
		$to = explode( ',', $to );
	}

	if ( isset( $atts['subject'] ) ) {
		$subject = $atts['subject'];
	}

	if ( isset( $atts['message'] ) ) {
		$message = $atts['message'];
	}

	if ( isset( $atts['headers'] ) ) {
		$headers = $atts['headers'];
	}

	if ( isset( $atts['attachments'] ) ) {
		$attachments = $atts['attachments'];
	}

	if ( ! is_array( $attachments ) ) {
		$attachments = explode( "\n", str_replace( "\r\n", "\n", $attachments ) );
	}
	global $phpmailer;

	// (Re)create it, if it's gone missing
	if ( ! ( $phpmailer instanceof PHPMailer ) ) {
		require_once ABSPATH . WPINC . '/class-phpmailer.php';
		require_once ABSPATH . WPINC . '/class-smtp.php';
		$phpmailer = new PHPMailer( true );
	}

	// Headers
	$cc = $bcc = $reply_to = array();

	if ( empty( $headers ) ) {
		$headers = array();
	} else {
		if ( !is_array( $headers ) ) {
			// Explode the headers out, so this function can take both
			// string headers and an array of headers.
			$tempheaders = explode( "\n", str_replace( "\r\n", "\n", $headers ) );
		} else {
			$tempheaders = $headers;
		}
		$headers = array();

		// If it's actually got contents
		if ( !empty( $tempheaders ) ) {
			// Iterate through the raw headers
			foreach ( (array) $tempheaders as $header ) {
				if ( strpos($header, ':') === false ) {
					if ( false !== stripos( $header, 'boundary=' ) ) {
						$parts = preg_split('/boundary=/i', trim( $header ) );
						$boundary = trim( str_replace( array( "'", '"' ), '', $parts[1] ) );
					}
					continue;
				}
				// Explode them out
				list( $name, $content ) = explode( ':', trim( $header ), 2 );

				// Cleanup crew
				$name    = trim( $name    );
				$content = trim( $content );

				switch ( strtolower( $name ) ) {
					// Mainly for legacy -- process a From: header if it's there
					case 'from':
						$bracket_pos = strpos( $content, '<' );
						if ( $bracket_pos !== false ) {
							// Text before the bracketed email is the "From" name.
							if ( $bracket_pos > 0 ) {
								$from_name = substr( $content, 0, $bracket_pos - 1 );
								$from_name = str_replace( '"', '', $from_name );
								$from_name = trim( $from_name );
							}

							$from_email = substr( $content, $bracket_pos + 1 );
							$from_email = str_replace( '>', '', $from_email );
							$from_email = trim( $from_email );

						// Avoid setting an empty $from_email.
						} elseif ( '' !== trim( $content ) ) {
							$from_email = trim( $content );
						}
						break;
					case 'content-type':
						if ( strpos( $content, ';' ) !== false ) {
							list( $type, $charset_content ) = explode( ';', $content );
							$content_type = trim( $type );
							if ( false !== stripos( $charset_content, 'charset=' ) ) {
								$charset = trim( str_replace( array( 'charset=', '"' ), '', $charset_content ) );
							} elseif ( false !== stripos( $charset_content, 'boundary=' ) ) {
								$boundary = trim( str_replace( array( 'BOUNDARY=', 'boundary=', '"' ), '', $charset_content ) );
								$charset = '';
							}

						// Avoid setting an empty $content_type.
						} elseif ( '' !== trim( $content ) ) {
							$content_type = trim( $content );
						}
						break;
					case 'cc':
						$cc = array_merge( (array) $cc, explode( ',', $content ) );
						break;
					case 'bcc':
						$bcc = array_merge( (array) $bcc, explode( ',', $content ) );
						break;
					case 'reply-to':
						$reply_to = array_merge( (array) $reply_to, explode( ',', $content ) );
						break;
					default:
						// Add it to our grand headers array
						$headers[trim( $name )] = trim( $content );
						break;
				}
			}
		}
	}

	// Empty out the values that may be set
	$phpmailer->clearAllRecipients();
	$phpmailer->clearAttachments();
	$phpmailer->clearCustomHeaders();
	$phpmailer->clearReplyTos();

	// From email and name
	// If we don't have a name from the input headers
	if ( !isset( $from_name ) )
		$from_name = 'WordPress';

	/* If we don't have an email from the input headers default to wordpress@$sitename
	 * Some hosts will block outgoing mail from this address if it doesn't exist but
	 * there's no easy alternative. Defaulting to admin_email might appear to be another
	 * option but some hosts may refuse to relay mail from an unknown domain. See
	 * https://core.trac.wordpress.org/ticket/5007.
	 */

	if ( !isset( $from_email ) ) {
		// Get the site domain and get rid of www.
		$sitename = strtolower( $_SERVER['SERVER_NAME'] );
		if ( substr( $sitename, 0, 4 ) == 'www.' ) {
			$sitename = substr( $sitename, 4 );
		}

		$from_email = 'wordpress@' . $sitename;
	}

	/**
	 * Filters the email address to send from.
	 *
	 * @since 2.2.0
	 *
	 * @param string $from_email Email address to send from.
	 */
	$from_email = apply_filters( 'wp_mail_from', $from_email );

	/**
	 * Filters the name to associate with the "from" email address.
	 *
	 * @since 2.3.0
	 *
	 * @param string $from_name Name associated with the "from" email address.
	 */
	$from_name = apply_filters( 'wp_mail_from_name', $from_name );

	try {
		$phpmailer->setFrom( $from_email, $from_name, false );
	} catch ( phpmailerException $e ) {
		$mail_error_data = compact( 'to', 'subject', 'message', 'headers', 'attachments' );
		$mail_error_data['phpmailer_exception_code'] = $e->getCode();

		/** This filter is documented in wp-includes/pluggable.php */
		do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', $e->getMessage(), $mail_error_data ) );

		return false;
	}

	// Set mail's subject and body
	$phpmailer->Subject = $subject;
	$phpmailer->Body    = $message;

	// Set destination addresses, using appropriate methods for handling addresses
	$address_headers = compact( 'to', 'cc', 'bcc', 'reply_to' );

	foreach ( $address_headers as $address_header => $addresses ) {
		if ( empty( $addresses ) ) {
			continue;
		}

		foreach ( (array) $addresses as $address ) {
			try {
				// Break $recipient into name and address parts if in the format "Foo <bar@baz.com>"
				$recipient_name = '';

				if ( preg_match( '/(.*)<(.+)>/', $address, $matches ) ) {
					if ( count( $matches ) == 3 ) {
						$recipient_name = $matches[1];
						$address        = $matches[2];
					}
				}

				switch ( $address_header ) {
					case 'to':
						$phpmailer->addAddress( $address, $recipient_name );
						break;
					case 'cc':
						$phpmailer->addCc( $address, $recipient_name );
						break;
					case 'bcc':
						$phpmailer->addBcc( $address, $recipient_name );
						break;
					case 'reply_to':
						$phpmailer->addReplyTo( $address, $recipient_name );
						break;
				}
			} catch ( phpmailerException $e ) {
				continue;
			}
		}
	}

	// Set to use PHP's mail()
	$phpmailer->isMail();

	// Set Content-Type and charset
	// If we don't have a content-type from the input headers
	if ( !isset( $content_type ) )
		$content_type = 'text/plain';

	/**
	 * Filters the wp_mail() content type.
	 *
	 * @since 2.3.0
	 *
	 * @param string $content_type Default wp_mail() content type.
	 */
	$content_type = apply_filters( 'wp_mail_content_type', $content_type );

	$phpmailer->ContentType = $content_type;

	// Set whether it's plaintext, depending on $content_type
	if ( 'text/html' == $content_type )
		$phpmailer->isHTML( true );

	// If we don't have a charset from the input headers
	if ( !isset( $charset ) )
		$charset = get_bloginfo( 'charset' );

	// Set the content-type and charset

	/**
	 * Filters the default wp_mail() charset.
	 *
	 * @since 2.3.0
	 *
	 * @param string $charset Default email charset.
	 */
	$phpmailer->CharSet = apply_filters( 'wp_mail_charset', $charset );

	// Set custom headers
	if ( !empty( $headers ) ) {
		foreach ( (array) $headers as $name => $content ) {
			$phpmailer->addCustomHeader( sprintf( '%1$s: %2$s', $name, $content ) );
		}

		if ( false !== stripos( $content_type, 'multipart' ) && ! empty($boundary) )
			$phpmailer->addCustomHeader( sprintf( "Content-Type: %s;\n\t boundary=\"%s\"", $content_type, $boundary ) );
	}

	if ( !empty( $attachments ) ) {
		foreach ( $attachments as $attachment ) {
			try {
				$phpmailer->addAttachment($attachment);
			} catch ( phpmailerException $e ) {
				continue;
			}
		}
	}

	/**
	 * Fires after PHPMailer is initialized.
	 *
	 * @since 2.2.0
	 *
	 * @param PHPMailer $phpmailer The PHPMailer instance (passed by reference).
	 */
	do_action_ref_array( 'phpmailer_init', array( &$phpmailer ) );

	// Send!
	try {
		return $phpmailer->send();
	} catch ( phpmailerException $e ) {

		$mail_error_data = compact( 'to', 'subject', 'message', 'headers', 'attachments' );
		$mail_error_data['phpmailer_exception_code'] = $e->getCode();

		/**
		 * Fires after a phpmailerException is caught.
		 *
		 * @since 4.4.0
		 *
		 * @param WP_Error $error A WP_Error object with the phpmailerException message, and an array
		 *                        containing the mail recipient, subject, message, headers, and attachments.
		 */
		do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', $e->getMessage(), $mail_error_data ) );

		return false;
	}
}

function nm_mandrill_options_page() {
	add_submenu_page(
		'options-general.php',
		'Mandrill',
		'Mandrill',
		'manage_options',
		'nm_mandrill',
		'nm_mandrill_options_page_content'
	);
}
if (function_exists('add_action')) {
add_action('admin_menu', 'nm_mandrill_options_page');
}

function nm_mandrill_options_page_content() {
	?>
		<div class="wrap">
			<h2>Mandrill Settings</h2>
			<p>Mandrill API Key: <?php echo MANDRILL_KEY ?></p>
			<p>Default <strong>From</strong> Name: <?php echo MANDRILL_FROM_NAME ?></p>
			<p>Default <strong>From</strong> Address: <?php echo MANDRILL_FROM_EMAIL ?></p>
			<p>Default template: <?php echo MANDRILL_TEMPLATE ?></p>
			<p>Default message settings:
				<ul>
					<?php foreach (nm_mandrill_get_default_message() as $k => $v) { ?>
						<li><?php echo $k; ?>: <?php echo $v ?></li>
					<?php } ?>
				</ul>
			</p>
			<form id="test_message">
				<h3>Send Test Message</h3>
				<div id="status"></div>
				<table>
					<tr>
						<td><label for="to">To</label></td>
						<td><input type="text" name="to" class="regular-text"></td>
					</tr>
					<tr>
						<td><label for="subject">Subject</label></td>
						<td><input type="text" name="subject" class="regular-text"></td>
					</tr>
					<tr>
						<td><label for="message">Message</label></td>
						<td><textarea name="message" rows='10' cols='80'></textarea><br>Message can include html.</td>
					</tr>
					<tr>
						<td><input type="submit" value="Send Test" class="button-primary"></td>
					</tr>
				</table>
			</form>
			<script type="text/javascript">
				jQuery(function($){
					$('#test_message').submit(function(e) {
						e.preventDefault();

						if ($('input[name="to"]').val() && $('input[name="subject"]').val() && $('textarea[name="message"]').val()) {
							$('#status').html("<div class='notice notice-info'><p>Working...</p></div>");
							$.ajax({
								url: ajaxurl,
								data: $(this).serialize() + '&action=mandrill_test',
								dataType: 'json'
							}).done(function(resp) {
								if (resp.success) {
									$("#status").html("<div class='notice notice-success'><p>Message sent</p></div>");
								} else {
									$("#status").html("<div class='notice notice-error'><p>" + resp.data.error + "</p></div>");
								}
							}).fail(function(resp) {
								$("#status").html("<div class='notice notice-error'><p>Error making request to server, please try again.</p></div>");
							});
						} else {
							$("#status").html("<div class='notice notice-warning'><p>All fields required.</p></div>");
						}
					});
				});
			</script>
		</div>
	<?php
}

function nm_mandrill_send_test() {
	$to = $_REQUEST['to'];
	if (filter_var($to, FILTER_VALIDATE_EMAIL)) {
		$subject = $_REQUEST['subject'];
		$message_body = $_REQUEST['message'];

		$message_body = preg_replace('/<(https?:\/\/[^*]+)>/', '$1', $message_body);

		$message = [
			'to' => [ ['email' => $to, 'type' => 'to'] ],
			'from_email' => MANDRILL_FROM_EMAIL,
			'from_name' => MANDRILL_FROM_NAME,
			'subject' => $subject,
			'html' => nl2br($message_body),
			'tags' => ['test-message']
		];

		$mandrill = nm_mandrill_get_instance();
		try {
			$outcome = $mandrill->messages->send(array_merge($message, nm_mandrill_get_default_message()));

			if ($outcome[0]['status'] == 'rejected' || $outcome[0]['status'] == 'invalid') {
				wp_send_json_error(['error' => $outcome[0]['status'] . " " . $outcome[0]['reject_reason']]);
			} else {
				wp_send_json_success();
			}
		} catch (Exception $e) {
			wp_send_json_error(['error' => $e->getMessage()]);
		}
	} else {
		wp_send_json_error(['error' => 'Invalid email address']);
	}
}
if (function_exists('add_action')) {
add_action('wp_ajax_mandrill_test', 'nm_mandrill_send_test');
}