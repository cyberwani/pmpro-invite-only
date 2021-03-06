<?php
/*
Plugin Name: PMPro Invite Only
Plugin URI: http://www.paidmembershipspro.com/add-ons/pmpro-invite-only/
Description: Users must have an invite code to sign up for certain levels. Users are given an invite code to share.
Version: .1
Author: Stranger Studios
Author URI: http://www.strangerstudios.com
*/

/*
	Set an array with the level ids of the levels which should require invite codes and generate them.
	
	e.g.
	global $pmproio_invite_levels;
	$pmproio_invite_levels = array(1,2,3);
*/

//check if a level id requires an invite code or should generate one
function pmproio_isInviteLevel($level_id)
{
	global $pmproio_invite_levels;
	
	return in_array($level_id, $pmproio_invite_levels);		
}

/*
	Add an invite code field to checkout
*/
function pmproio_pmpro_checkout_boxes()
{
	global $pmpro_level, $current_user;	
	if(pmproio_isInviteLevel($pmpro_level->id))
	{		
		if(!empty($_REQUEST['invite_code']))
			$invite_code = $_REQUEST['invite_code'];
		elseif(!empty($_SESSION['invite_code']))
			$invite_code = $_SESSION['invite_code'];
		elseif(is_user_logged_in())
			$invite_code = $current_user->pmpro_invite_code_at_signup;
		else
			$invite_code = "";
	?>
	<table class="pmpro_checkout top1em" width="100%" cellpadding="0" cellspacing="0" border="0">
		<thead>
			<tr>
				<th><?php _e('Invite Code', 'pmpro');?></th>
			</tr>
		</thead>
		<tbody>
			<tr>
				<td>
					<label for="invite_code"><?php _e('Invite Code', 'pmpro');?></label>
					<input id="invite_code" name="invite_code" type="text" class="input <?php echo pmpro_getClassForField("invite_code");?>" size="20" value="<?php echo esc_attr($invite_code);?>" />
					<span class="pmpro_asterisk"> *</span>
				</td>
			</tr>
		</tbody>
	</table>
	<?php
	}
}
add_action('pmpro_checkout_boxes', 'pmproio_pmpro_checkout_boxes');

/*
	Require the invite code
*/
function pmproio_pmpro_registration_checks($okay)
{	
	global $pmpro_level, $pmproio_invite_levels;	
	if(pmproio_isInviteLevel($pmpro_level->id))
	{
		global $pmpro_msg, $pmpro_msgt, $pmpro_error_fields, $wpdb;
		
		//get invite code
		$invite_code = $_REQUEST['invite_code'];
		
		//is it real?
		$real = $wpdb->get_var("SELECT user_id FROM $wpdb->usermeta WHERE meta_key = 'pmpro_invite_code' AND meta_value = '" . esc_sql($invite_code) . "' LIMIT 1");
		
		//make sure the user for that invite code has a membership level
		if(!empty($real))
		{
			$real = pmpro_hasMembershipLevel($pmpro_invite_levels, $real);
		}
		
		if(empty($invite_code) || empty($real))
		{
			pmpro_setMessage(__("An invite code is required for this level. Please enter a valid invite code.", "pmpro"), "pmpro_error");
			$pmpro_error_fields[] = "invite_code";		
		}
	}
	
	return $okay;
}
add_filter("pmpro_registration_checks", "pmproio_pmpro_registration_checks");

/*
	Generate an invite code for new users.
*/
//on level change
function pmproio_pmpro_after_change_membership_level($level_id, $user_id)
{
	//does this level give out invite codes?
	if(pmproio_isInviteLevel($level_id))
	{
		global $wpdb;
		
		//already have one?
		$old_code = get_user_meta($user_id, "pmpro_invite_code", true);
		
		if(empty($old_code))
		{
			//generate a new code
			while(empty($code))
			{
				$scramble = md5(AUTH_KEY . $user_id . time() . SECURE_AUTH_KEY);			
				$code = substr($scramble, 0, 10);
				$check = $wpdb->get_var("SELECT meta_value FROM $wpdb->usermeta WHERE meta_key = 'pmpro_invite_code' AND meta_value = '" . esc_sql($code) . "' LIMIT 1");				
				if($check || is_numeric($code))
					$code = NULL;
			}
			
			update_user_meta($user_id, "pmpro_invite_code", $code);
		}		
	}		
}
add_action("pmpro_after_change_membership_level", "pmproio_pmpro_after_change_membership_level", 10, 2);

//at checkout
function pmproio_pmpro_after_checkout($user_id)
{
	//get level
	$level_id = intval($_REQUEST['level']);
	
	if(pmproio_isInviteLevel($level_id))
	{	
		//generate a code/etc
		pmproio_pmpro_after_change_membership_level($level_id, $user_id);	
		
		//update code used
		if(!empty($_REQUEST['invite_code']))
			update_user_meta($user_id, "pmpro_invite_code_at_signup", $_REQUEST['invite_code']);
	}
	
	//delete any session var
	if(isset($_SESSION['invite_code']))
		unset($_SESSION['invite_code']);
}
add_action("pmpro_after_checkout", "pmproio_pmpro_after_checkout", 10, 2);

/*
	Save invite code while at PayPal
*/
function pmproio_pmpro_paypalexpress_session_vars()
{	
	if(!empty($_REQUEST['invite_code']))
		$_SESSION['invite_code'] = $_REQUEST['invite_code'];
}
add_action("pmpro_paypalexpress_session_vars", "pmproio_pmpro_paypalexpress_session_vars");

/*
	Save invite code used when a user is created for PayPal and other offsite gateways.
	We are abusing the pmpro_wp_new_user_notification filter which runs after the user is created.
*/
function pmproio_pmpro_wp_new_user_notification($notify, $user_id)
{
	if(!empty($_REQUEST['invite_code']))
	{
		update_user_meta($user_id, "pmpro_invite_code_at_signup", $_REQUEST['invite_code']);
	}
	
	return $notify;
}
add_filter('pmpro_wp_new_user_notification', 'pmproio_pmpro_wp_new_user_notification', 10, 2);

/*
	Show a user's invite code on the confirmation page
*/
function pmproio_pmpro_confirmation_message($message)
{
	global $current_user, $wpdb;
	
	$invite_code = $current_user->pmpro_invite_code;
			
	if(!empty($invite_code))
	{
		$message .= "<div class=\"pmpro_content_message\"><p>Give this invite code to others to use at checkout: <strong>" . $invite_code . "</strong></p></div>";
	}
	return $message;
}
add_filter("pmpro_confirmation_message", "pmproio_pmpro_confirmation_message");

/*
	Show invite code fields on edit profile page for admins.
*/
function pmproio_show_extra_profile_fields($user)
{	
?>
	<h3><?php _e('Invite Codes', 'pmpro');?></h3>
 
	<table class="form-table">
 
		<tr>
			<th><?php _e('Invite Code', 'pmpro');?></th>			
			<td>
				<input type="text" name="invite_code" value="<?php echo esc_attr($user->pmpro_invite_code);?>" />
			</td>
		</tr>
		
		<tr>
			<th><?php _e('Invite Code Used at Signup', 'pmpro');?></th>
			<td>
				<?php 
					$invite_code_used = $user->pmpro_invite_code_at_signup;
					if(empty($invite_code_used))
						echo "N/A";
					else
						echo $invite_code_used;
				?>
			</td>
		</tr>
		
	</table>
<?php
}
add_action( 'show_user_profile', 'pmproio_show_extra_profile_fields' );
add_action( 'edit_user_profile', 'pmproio_show_extra_profile_fields' );

function pmproio_save_extra_profile_fields( $user_id ) 
{
	if ( !current_user_can( 'edit_user', $user_id ) )
		return false;
 
	if(!empty($_POST['invite_code']))
		update_user_meta($user_id, "pmpro_invite_code", $_POST['invite_code']);
}
add_action( 'personal_options_update', 'pmproio_save_extra_profile_fields' );
add_action( 'edit_user_profile_update', 'pmproio_save_extra_profile_fields' );