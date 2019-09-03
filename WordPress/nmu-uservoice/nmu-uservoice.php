<?php
/*
Plugin Name: New Music USA Uservoice
Description: Minimal integration for Uservoice
Version: 1.0
Author: New Music USA
*/

require_once('vendor/autoload.php');

define('NMU_UV_SUBDOMAIN', 'xxx');
define('NMU_UV_SSOKEY', 'xxx');
define('NMU_UV_APIKEY', 'xxx');
define('NMU_UV_APISECRET', 'xxx');

/**
 * template function to display UV widget initialized with an SSO token from UV to ensure
 * correct attribution on UV tickets with logged in users
 */
function nmu_uservoice_footer_script() {
	?>
		<script>
		UserVoice=window.UserVoice||[];(function(){var uv=document.createElement('script');uv.type='text/javascript';uv.async=true;uv.src='//widget.uservoice.com/pABISWPTUv9zXZjsKYqAA.js';var s=document.getElementsByTagName('script')[0];s.parentNode.insertBefore(uv,s)})();

		UserVoice.push(['set', {
		  accent_color: '#26a9e0',
		  trigger_color: 'white',
		  trigger_background_color: '#26a9e0',
		  smartvote_enabled: false,
		  post_suggestion_enabled: false
		}]);

		<?php if (is_user_logged_in()) { ?>
			<?php $current_user = wp_get_current_user(); $sso_token = nmu_uservoice_get_sso_token($current_user); ?>
			UserVoice.push(['set', {sso: "<?php echo $sso_token ?>"}]);
			UserVoice.push(['identify', {
				email: '<?php echo $current_user->user_email ?>',
				name: '<?php echo get_profile_fullname(get_profile_for_user($current_user->ID)) ?>', 
				id: '<?php echo $current_user->ID ?>'
			}]);
		<?php } ?>

		UserVoice.push(['addTrigger', {mode: 'contact', trigger_position: 'bottom-right' }]);
		</script>
	<?php
}
add_action('wp_footer', 'nmu_uservoice_footer_script');

/**
 * request SSO token from UV based on user's email address; token cached for length of its life
 * to avoid network calls on every page load
 * @param WP_User object
 * @return string|null the SSO token from UV, null on failure
 */
function nmu_uservoice_get_sso_token($user) {
	$sso_token = get_transient('nm_uservoice_ssotoken_' . $user->ID);

	if (!$sso_token) {
		$token_expiration = 60*60;
		$sso_token = \UserVoice\SSO::generate_token(NMU_UV_SUBDOMAIN, NMU_UV_SSOKEY, array(
	        'email' => $user->user_email
	    ), $token_expiration);
	    set_transient('nm_uservoice_ssotoken_' . $user->ID, $sso_token, $token_expiration);
	}

    return $sso_token;
}
