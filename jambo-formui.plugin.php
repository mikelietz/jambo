<?php

/**
 * Jambo a contact form plugin for Habari
 *
 * @package jambo
 *
 * @todo use AJAX to submit form, fallback on default if no AJAX.
 * @todo allow "custom fields" to be added by user.
 * @todo redo the hook and make it easy to add other formui comment stuff.
 */

// require_once 'jambohandler.php';

class JamboFormUI extends Plugin
{	
	const VERSION = '2.0';
	
	/**
	 * Create the default email form
	 */
	public function get_jambo_form( ) 
	{
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
		$form->append( 'text', 'jambo_name', 'null:null', _t( 'Name' ) )
				->add_validator( 'validate_required', _t( 'Your Name is required.' ) )
				->id = 'jambo_name';
		$form->jambo_name->tabindex = 1;
		$form->jambo_name->value = $commenter_name;

		// Create the Email field
		$form->append( 'text', 'jambo_email', 'null:null', _t( 'Email' ) )
				->add_validator( 'validate_email', _t( 'Your Email must be a valid address.' ) )
				->id = 'jambo_email';
		$form->jambo_email->tabindex = 2;
		$form->jambo_email->caption = _t( 'Email' );
		$form->jambo_email->value = $commenter_email;

		// Create the Subject field
		$form->append( 'text', 'jambo_subject', 'null:null', _t( 'Subject', 'jambo' ) )
				->id = 'jambo_subject';
		$form->jambo_subject->tabindex = 3;

		// Create the Message field
		$form->append( 'textarea', 'jambo_message', 'null:null', _t( 'Message', 'jambo' ) )
				->add_validator( 'validate_required', _t( 'Your message cannot be blank.', 'jambo' ) )
				->id = 'jambo_message';
		$form->jambo_message->tabindex = 4;

		// Create the Submit button
		$form->append( 'submit', 'jambo_submit', _t( 'Submit' ) );
		$form->jambo_submit->tabindex = 5;

		// Allow other plugins and theme authors to modify and customise this form easily.
		Plugins::act( 'form_jambo', $form, $this );
		
		// Create hidden OSA fields
		self::OSA( $form );
		
		// Set up form processing
		$form->on_success( array( $this, 'process_jambo' ) );
		// Return the form object
		return $form;
	}

	/**
	 * Process the submitted form and send the email
	 * 
	 * @param type $form
	 * @return String 
	 */
	function process_jambo( $form )
	{
		// get the values and the stored options.
		$email = array();
		$email['sent'] =		false;
		$email['send_to'] =	Options::get( 'jambo__send_to' );
		$email['name'] = $form->jambo_name->value;
		$email['email'] = $form->jambo_email->value;
		$email['subject'] = Options::get( 'jambo__subject' ) . ' ' . $form->jambo_subject;
		$email['message'] = $form->jambo_message->value;
		$email['success_msg'] = Options::get ( 'jambo__success_msg','Thank you contacting me. I\'ll get back to you as soon as possible.' );
		
		// Utils::mail expects an array
		$email['headers'] = array( 'MIME-Version' => '1.0',
			'From' => "{$email['name']} <{$email['email']}>",
			'Content-Type' => 'text/plain; charset="utf-8"' );

 		$email = Plugins::filter( 'jambo_email', $email, $form->osa->value, $form->osa_time->value );
		
		$email['sent'] = Utils::mail( $email['send_to'], $email['subject'], $email['message'], $email['headers'] );

		return '<p class="jambo-confirmation">' .$email ['success_msg']  .'</p>';
	}

	public function set_priorities()
	{
		return array(
			'filter_post_content_out' => 11
			);
	}
	
	/**
	 * The plugin configuration form.
	 */
	public function configure()
	{
		$ui = new FormUI( 'jambo' );

		// Add a text control for the address you want the email sent to
		$send_to = $ui->append( 'text', 'send_to', 'option:jambo__send_to', _t( 'Where To Send Email: ' ) );
		$send_to->add_validator( 'validate_required' );

		// Add a text control for the prefix to the subject field
		$subject_prefix = $ui->append( 'text', 'subject', 'option:jambo__subject', _t( 'Subject Prefix: ' ) );
		$subject_prefix->add_validator( 'validate_required' );
		
		// Add a text control for the prefix to the success message
		$success_msg = $ui->append( 'textarea', 'success_msg', 'option:jambo__success_msg', _t( 'Success Message: ' ) );
		
		$ui->append( 'submit', 'save', 'Save' );
		return $ui;
	}
	
	/**
	 * Replace <!-- jambo --> or <!-- contactform --> in the post content with 
	 * the Jambo email form.
	 */
	public function filter_post_content_out( $content )
	{
		$content = str_ireplace( array('<!-- jambo -->', '<!-- contactform -->'), $this->get_jambo_form()->get(), $content );
		return $content;
	}
	
	/**
	 * Verify the submitted form has been submitted by a real user and also pass
	 * it through Spam Checker plugin.  The spam checking part will only be 
	 * effective if the plugin is enabled.
	 */
	public function filter_jambo_email( $email, $osa, $time )
	{
		if ( ! self::verify_OSA( $osa, $time ) ) {
			ob_end_clean();
			header( 'HTTP/1.1 403 Forbidden' );
			die( '<h1>' . _t( 'The selected action is forbidden.' ) . '</h1><p>' . _t( 'You are submitting the form too fast and look like a spam bot.' ) . '</p>' );
		}
		
		// If we've got this far, I think we can be certain we have a valid email address and the comment has probably been manually submitted.
		$comment = new Comment( array(
			'name' => $email['name'],
			'email' => $email['email'],
			'content' => $email['message'],
			'ip' => sprintf( "%u", ip2long( Utils::get_ip() ) ),
			'post_id' => ( isset( $post ) ? $post->id : 0 ),
		) );

		// Run the message through the Spam Filter plugin, if it's enabled.
		Plugins::act( 'comment_insert_before', $comment );

		if ( Comment::STATUS_SPAM == $comment->status ) {
			ob_end_clean();
			header( 'HTTP/1.1 403 Forbidden' );
			die( '<h1>' . _t( 'The selected action is forbidden.' ) . '</h1><p>' . _t( 'Your attempted contact appears to be spam. If it wasn\'t, return to the previous page and try again.' ) . '</p>' );
		}

		return $email;
	}
	
	/**
	 * Create the OSA based on the time string submitted and the plugin version
	 */
	private static function get_OSA( $time )
	{
		$osa = 'osa_' . substr( md5( $time . Options::get( 'GUID' ) . self::VERSION ), 0, 10 );
		$osa = Plugins::filter( 'jambo_OSA', $osa, $time );
		return $osa;
	}

	/**
	 * Verify that the OSA and time passed are valid.
	 */
	private static function verify_OSA( $osa, $time )
	{
		if ( $osa == self::get_OSA( $time ) ) {
			if ( ( time() > ( $time + 5 ) ) && ( time() < ( $time + 5*60 ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Add the OSA fields to the form.
	 */
	private static function OSA( $form ) 
	{
		$time = time();
		$osa = self::get_OSA( $time );
		$form->append( 'hidden', 'osa', 'null:null' )->value = $osa;
		$form->append( 'hidden', 'osa_time', 'null:null' )->value = $time;
		return $form;
	}
}

?>
