<?php

/**
 * Handler class for the Jambo contact form plugin for Habari
 *
 * @package jambo
 */

class JamboHandler extends ActionHandler
{
	
	public function act_send()
	{
		$email = array();
		$email['sent']=           false;
		$email['valid']=          true;
		$email['send_to']=        Jambo::get( 'send_to' );
		$email['subject_prefix']= Jambo::get( 'subject_prefix' );
		$email['name']=           $this->handler_vars['name'];
		$email['email']=          $this->handler_vars['email'];
		$email['subject']=        $this->handler_vars['subject'];
		$email['message']=        $this->handler_vars['message'];
		$email['osa']=            $this->handler_vars['osa'];
		$email['osa_time']=       $this->handler_vars['osa_time'];
		
		$email['headers']= "MIME-Version: 1.0\r\n" .
			"From: {$email['name']} <{$email['email']}>\r\n" .
			"Content-Type: text/plain; charset=\"utf-8\"\r\n";
		
		$email = Plugins::filter( 'jambo_email', $email, $this->handler_vars );
		
		if ( $email['valid'] ) {
			$email['sent']= mail( $email['send_to'], $email['subject_prefix'] . $email['subject'], $email['message'], $email['headers'] );
			$this->remember_contactor( $email );
		}
		foreach ( $email as $key => $value ) {
			Session::add_to_set( 'jambo_email', $value, $key );
		}
		Utils::redirect( $_SERVER['HTTP_REFERER'] . '#jambo' );
	}
	
	// set a cookie like in comments.
	private function remember_contactor( $email )
	{
		$cookie = 'comment_' . Options::get('GUID');
		if ( ( ! User::identify()->loggedin ) 
			&& ( ! isset( $_COOKIE[$cookie] ) ) 
			&& ( ! empty( $email['name'] ) || ! empty( $email['email'] ) ) )
		{
			$cookie_content = $email['name'] . '#' . $email['email'] . '#' . '';
			$site_url = Site::get_path('base');
			if ( empty( $site_url ) ) {
				$site_url = rtrim( $_SERVER['SCRIPT_NAME'], 'index.php' );
			}
			else {
				$site_url = '/' . $site_url . '/';
			}
			setcookie( $cookie, $cookie_content, time() + 31536000, $site_url );
		}
	}
}

?>
