<?php

/**
 * Jambo a contact form plugin for Habari
 *
 * @package jambo
 *
 * @todo document the functions.
 * @todo use AJAX to submit form, fallback on default if no AJAX.
 * @todo allow "custom fields" to be added by user.
 */

// require_once 'jambohandler.php';

class JamboFormUI extends Plugin
{
	public function get_jambo_form( ) {
		// borrow default values from the comment forms
		$commenter_name = '';
		$commenter_email = '';
		$commenter_url = '';
		$commenter_content = '';
		$user = User::identify();
		if ( isset( $_SESSION['comment'] ) ) {
			$details = Session::get_set( 'comment' );
			$commenter_name = $details['name'];
			$commenter_email = $details['email'];
			$commenter_url = $details['url'];
			$commenter_content = $details['content'];
		}
		elseif ( $user->loggedin ) {
			$commenter_name = $user->displayname;
			$commenter_email = $user->email;
			$commenter_url = Site::get_url( 'habari' );
		}

		// Now start the form.
		$form = new FormUI( 'jambo' );
// 		$form->set_option( 'form_action', URL::get( 'submit_feedback', array( 'id' => $this->id ) ) );

		// Create the Name field
		$form->append(
			'text',
			'jambo_name',
			'null:null',
			_t( 'Name' ),
			'formcontrol_text'
		)->add_validator( 'validate_required', _t( 'Your Name is required.' ) )
		->id = 'jambo_name';
		$form->jambo_name->tabindex = 1;
		$form->jambo_name->value = $commenter_name;

		// Create the Email field
		$form->append(
			'text',
			'jambo_email',
			'null:null',
			_t( 'Email' ),
			'formcontrol_text'
		)->add_validator( 'validate_email', _t( 'Your Email must be a valid address.' ) )
		->id = 'jambo_email';
		$form->jambo_email->tabindex = 2;
		$form->jambo_email->caption = _t( 'Email' );
		$form->jambo_email->value = $commenter_email;

		// Create the Message field
		$form->append(
			'text',
			'jambo_message',
			'null:null',
			_t( 'Message', 'jambo' ),
			'formcontrol_textarea'
		)->add_validator( 'validate_required', _t( 'Your message cannot be blank.', 'jambo' ) )
		->id = 'jambo_message';
		$form->jambo_message->tabindex = 4;

		// Create the Submit button
		$form->append( 'submit', 'jambo_submit', _t( 'Submit' ), 'formcontrol_submit' );
		$form->jambo_submit->tabindex = 5;

		// Set up form processing
		$form->on_success( array($this, 'process_jambo') );
		// Return the form object
		return $form;
	}

	function process_jambo( $form )
	{
		// why isn't this being run?
Utils::debug( $form ); die();
		// get the values and the stored options.

		$email = array();
		$email['sent'] =           false;
		$email['valid'] =          true;
		$email['send_to'] =        Jambo::get( 'send_to' );
		$email['subject_prefix'] = Jambo::get( 'subject_prefix' );
		$email['name'] =           $this->handler_vars['name'];
		$email['email'] =          $this->handler_vars['email'];
		$email['subject'] =        $this->handler_vars['subject'];
		$email['message'] =        $this->handler_vars['message'];
		$email['osa'] =            $this->handler_vars['osa'];
		$email['osa_time'] =       $this->handler_vars['osa_time'];
		
		$email['headers'] = "MIME-Version: 1.0\r\n" .
			"From: {$email['name']} <{$email['email']}>\r\n" .
			"Content-Type: text/plain; charset=\"utf-8\"\r\n";
		
		$email = Plugins::filter( 'jambo_email', $email, $this->handler_vars );
		
		if ( $email['valid'] ) {
			$email['sent'] = Utils::mail( $email['send_to'], $email['subject_prefix'] . $email['subject'], $email['message'], $email['headers'] );
		}
	}

	public function set_priorities()
	{
		return array(
			'filter_post_content_out' => 11
			);
	}
	
	public function configure()
	{
		$ui = new FormUI( 'jambo' );

		// Add a text control for the address you want the email sent to
		$send_to = $ui->append( 'text', 'send_to', 'option:jambo__send_to', _t( 'Where To Send Email: ' ) );
		$send_to->add_validator( 'validate_required' );

		// Add a text control for the prefix to the subject field
		$subject_prefix = $ui->append( 'text', 'subject', 'option:jambo__subject', _t( 'Subject: ' ) );
		$subject_prefix->add_validator( 'validate_required' );

		$ui->append( 'submit', 'save', 'Save' );
		return $ui;;
	}
	
	public function filter_post_content_out( $content )
	{
		$content = str_ireplace( array('<!-- jambo -->', '<!-- contactform -->'), $this->get_jambo_form()->get(), $content );
		return $content;
	}
	
	public function filter_jambo_email( $email, $handlervars )
	{
		if ( ! $this->verify_OSA( $handlervars['osa'], $handlervars['osa_time'] ) ) {
			ob_end_clean();
			header('HTTP/1.1 403 Forbidden');
			die(_t('<h1>The selected action is forbidden.</h1><p>You are submitting the form too fast and look like a spam bot.</p>'));
		}
		
		if( $email['valid'] !== false ) {
			$comment = new Comment( array(
				'name' => $email['name'],
				'email' => $email['email'],
				'content' => $email['message'],
				'ip' => sprintf("%u", ip2long( $_SERVER['REMOTE_ADDR'] ) ),
				'post_id' => ( isset( $post ) ? $post->id : 0 ),
			) );

			$handlervars['ccode'] = $handlervars['jcode'];
			$_SESSION['comments_allowed'][] = $handlervars['ccode'];
			Plugins::act('comment_insert_before', $comment);

			if( Comment::STATUS_SPAM == $comment->status ) {
				ob_end_clean();
				header('HTTP/1.1 403 Forbidden');
				die(_t('<h1>The selected action is forbidden.</h1><p>Your attempted contact appears to be spam. If it wasn\'t, return to the previous page and try again.</p>'));
			}
		}

		return $email;
	}
	
	private function get_OSA( $time ) {
		$osa = 'osa_' . substr( md5( $time . Options::get( 'GUID' ) . self::VERSION ), 0, 10 );
		$osa = Plugins::filter('jambo_OSA', $osa, $time);
		return $osa;
	}

	private function verify_OSA( $osa, $time ) {
		if ( $osa == $this->get_OSA( $time ) ) {
			if ( ( time() > ($time + 5) ) && ( time() < ($time + 5*60) ) ) {
				return true;
			}
		}
		return false;
	}

	private function OSA( $vars ) {
		if ( array_key_exists( 'osa', $vars ) && array_key_exists( 'osa_time', $vars ) ) {
			$osa = $vars['osa'];
			$time = $vars['osa_time'];
		}
		else {
			$time = time();
			$osa = $this->get_OSA( $time );
		}
		return "<input type=\"hidden\" name=\"osa\" value=\"$osa\" />\n<input type=\"hidden\" name=\"osa_time\" value=\"$time\" />\n";
	}

}

?>
