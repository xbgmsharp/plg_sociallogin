<?php
/**
 * Main Plugin File
 * Does all the magic!
 *
 * @package                     Social Login
 * @version                     0.7.0
 *
 * @author                      xbgmsharp@gmail.com
 * @link                        https://github.com/xbgmsharp/plg_sociallogin
 * @copyright           	Copyright © 2012 xbgmsharp All Rights Reserved
 * @license                     http://www.gnu.org/licenses/gpl-2.0.html GNU/GPL
 */

/*defined( '_JEXEC' ) or die( 'Restricted access' );
jimport('joomla.plugin.plugin');
jimport('joomla.user.user');
jimport('joomla.user.helper');
*/
class plgSystemSocialLogin extends JPlugin
{
	function plgSystemSocialLogin(&$subject, $config)
	{
		parent::__construct($subject,$config);
	}

	function onAfterInitialise()
	{
		// just startup
		$mainframe =& JFactory::getApplication();
		$option = JRequest::getCmd('option');
		//global $mybaseurl;

		if((isset($_GET['token']))||(isset($_POST['token'])))
		{
			if (isset($_GET['token']))
			{
				$token = $_GET['token'];
			}
			else
			{
				$token = $_POST['token'];
			}
			$db =& JFactory::getDBO();
			$query = "SELECT * FROM #__sociallogin WHERE propname='key'";
			$db->setQuery($query);
			$row = $db->loadObject();
			$apiKey = $row->propvalue;
			$post_data = array('token' => $token,
					 'apiKey' => $apiKey,
					 'format' => 'json');

			//	if ($this->params->def('usecurl', 1)) { // J2.5
			if ($this->params->get('usecurl') == 1) // J1.5
			{
				$curl = curl_init();
				curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
				curl_setopt($curl, CURLOPT_URL, 'https://rpxnow.com/api/v2/auth_info/?token='.$token.'&&apiKey='.$apiKey.'&&format=json');
				curl_setopt($curl, CURLOPT_POST, true);
				curl_setopt($curl, CURLOPT_POSTFIELDS, $post_data);
				curl_setopt($curl, CURLOPT_HEADER, false);
				curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
				$raw_json = curl_exec($curl);
				curl_close($curl);
			}
			else
			{
				$raw_json = file_get_contents("https://rpxnow.com/api/v2/auth_info/?token=".$token."&&apiKey=".$apiKey."&&format=json");
			}

			// parse the json response into an associative array
			$auth_info = json_decode($raw_json, true);

			print_r($auth_info);
			// process the auth_info response
			if ($auth_info['stat'] == 'ok')
			{
				$db =& JFactory ::getDBO();
				$rpxid = 'rpx'.md5($auth_info['profile']['identifier']);
				$query = "SELECT userid FROM #__sociallogin_mapping WHERE rpxid='".$rpxid."'";
				$db->setQuery($query);
				$userid = $db->loadResult();

				$newuser = true;
				if (isset($userid))
				{
					print "Existing User\n<br/>";
					$user =& JFactory::getUser($userid);
					if ($user->id == $userid)
					{
						$newuser = false;
					}
					else
					{
						// possible if previous registered, but meanwhile removed
						// we have a userid without user...remove from the rpx_mapping
						$query = "DELETE FROM #__sociallogin_mapping WHERE userid='".$userid."'";
						$db->setQuery($query);
						$db->query();
					}
				}

				if ($newuser == true)
				{
					print "New User\n<br/>";
					$instance = JUser::getInstance();
					jimport('joomla.application.component.helper');
					$config = JComponentHelper::getParams('com_users');
					$defaultUserGroup = $config->get('new_usertype', 2);

					// $host = JFactory::getURI()->getHost();
					// $domain = substr($host,4); // strips the www.
					print "Set Email\n<br/>";
					$email = "";
					if ($this->params->get('fakemail') == 0)
					{
						if (isset($auth_info['profile']['email']))
						{
							$email = $auth_info['profile']['email'];
						}
						else if (isset($auth_info['profile']['name']['email']))
						{
							$email = $auth_info['profile']['email'];
						}
						else
						{
							$email = str_replace(" ","_",$userName)."@".$domain;
						}
					}
					else
					{
						$email = str_replace(" ","_",$userName)."@".$domain;
					}
					//$pwd = JUserHelper::genRandomPassword();

					print "Set displayName & Username\n<br/>";
					$displayName = "";
					$preferredUsername = "";
					if (isset($auth_info['profile']['displayName']))
					{
						$displayName = $auth_info['profile']['displayName'];
					}
					else if (isset($auth_info['profile']['name']['displayName']))
					{
						$displayName = $auth_info['profile']['name']['displayName'];
					}
					if (isset($auth_info['profile']['preferredUsername']))
					{
						$preferredUsername = $auth_info['profile']['preferredUsername'];
					}
					else if (isset($auth_info['profile']['name']['preferredUsername']))
					{
						$preferredUsername = $auth_info['profile']['name']['preferredUsername'];
					}

					print "Set Formated Name\n<br/>";

					/* Set prefer displayName if Formated Name */
					if (isset($auth_info['profile']['name']['formatted']))
					{
						$displayName = $auth_info['profile']['name']['formatted'];
					}

					print "Check if username exist\n<br/>";
					// if username already exists, just add an index to it
					$nameexists = true;
					$index = 0;
					$userName = $preferredUsername;
					while ($nameexists == true)
					{
						if (JUserHelper::getUserId($userName) != 0)
						{
							$index++;
							$userName = $preferredUsername.$index;
						}
						else
						{
							$nameexists = false;
						}
					}
					// need to fix will be overwrite
					$instance->set('username', $userName);

					print "Set value to instance\n<br/>";
					// Set value to Instance
					$instance->set('id' , 0);
					$instance->set('name' , $displayName);
					$instance->set('username' , $preferredUsername);
					//$instance->set('password_clear' , $pwd);
					$instance->set('email' , $email); // Result should contain an email (check)
					$instance->set('usertype' , 'deprecated');
					$instance->set('groups' , array($defaultUserGroup));

					print "Set Parameters\n<br/>";
					// Force Parameters
					$instance->setParam("language", "es-ES");
					$instance->setParam("timezone", "Europe\/Madrid");

					print "Save user in DB\n<br/>";
					// Save user
					if (!$instance->save())
					{
						echo "Error not a new user\n<br/>";
						JError::raiseWarning(500, $instance->getError());
						JError::raiseError(500, $instance->getError());
						// updating value
						if (!$instance->save(true))
						{
							echo "Again Error not a new user\n<br/>";
							JError::raiseWarning(500, $instance->getError());
							JError::raiseError(500, $instance->getError());
						}
						//return false;
					}
					else
					{
						// Add mapping from easy find
						$servicename = "";
						if (isset($auth_info['profile']['providerName']))
						{
							$servicename = $auth_info['profile']['providerName'];
						}
						$query = "INSERT INTO #__sociallogin_mapping (userid, rpxid, servicename) VALUES ('".$instance->get('id')."','".$rpxid."','".$servicename."')";
						$db->setQuery($query);
						if (!$db->query())
						{
							JError::raiseError(500, $db->stderror());
						}
					}

					// Maybe an option and as a function
					/*
					// check if the community builder tables are there
					$query = "SHOW TABLES LIKE '%__comprofiler'";
					$db->setQuery($query);
					$tableexists = $db->loadResult();
					if (isset($tableexists))
					{
						$cbquery = "INSERT IGNORE INTO #__comprofiler(id,user_id) VALUES ('".$user->get('id')."','".$user->get('id')."')";
						$db->setQuery($cbquery);
						if (!$db->query())
						{
							JError::raiseError(500, $db->stderror());
						}
					}
					*/

					print "User Save with ID: ". $instance->get('id') ."\n<br/>";
					$user =& $instance;

				} // End new user

				// Only if the user is valid (existing or new)
				if (isset($user) and (intval($user->get('id')) != 0))
				{
					print_r($auth_info);

					$instance = JUser::getInstance();
					/* Force an overwrite of some user details info */
					if (!$instance->load(intval($user->get('id'))))
					{
						// User does not Exist this is bad if we did get to this point without user
						JError::raiseError(500, JText::_('User does not Exist'));
						return false;
					}

					if (isset($auth_info['profile']['name']['formatted']))
					{
						//$instance->load(intval($user->get('id')));
						$instance->setParam("language", "es-ES");
						$instance->setParam("timezone", "Europe/Madrid");
						$instance->save(true);
						$displayName = $auth_info['profile']['name']['formatted'];
						$sqluser = "UPDATE #__users SET `name` = '". $displayName. "' WHERE `id` = '". $user->get('id') ."';";
						$db->setQuery($sqluser);
						if (!$db->query($sqluser))
						{
							JERROR::raiseError(500, $db->stderr());
						}
						echo "End update user settings\n<br/>";
					}
					/* End Force an overwrite */

					echo "Check Appointment Booking Pro2\n<br/>";
					// check if the Appointment Booking Pro2 tables are there
					$query = "SHOW TABLES LIKE '%__sv_apptpro2_requests'";
					$db->setQuery($query);
					$tableexists = $db->loadResult();
					if (isset($tableexists))
					{
						/* Sync old ABPRO request to new register user */
						// Maybe Improve request all in one.
						$sqlrequest = "SELECT id_requests,user_id,phone FROM #__sv_apptpro2_requests WHERE email = '".$user->get('email')."';";
						$db->setQuery($sqlrequest);
						$db->query($sqlrequest);
						$reqresult = $db->loadAssocList();
						foreach ($reqresult as $req)
						{
							if ($req[user_id] != $user->get('id'))
							{
								//print_r($req);
								$sqlupdate = "UPDATE #__sv_apptpro2_requests SET `user_id` = '".intval($user->get('id'))."', `name`= '".$user->get('name')."' WHERE `id_requests` = ".$req[id_requests].";";
								$db->setQuery($sqlupdate);
								if (!$db->query($sqlupdate))
								{
								   JError::raiseError(500, $db->stderr());
								}
							}
							if ($req[phone][0] == 6) { $mobile = $req[phone]; }
							if ($req[phone][0] == 9) { $phone = $req[phone]; }
						}
					}
					/* End Appointment Booking Pro2 */

					echo "Check VirtueMart2 Pro2\n<br/>";
					// check if the VirtueMart2 tables are there
					$query = "SHOW TABLES LIKE '%__todo'";
					$db->setQuery($query);
					$tableexists = $db->loadResult();
					if (isset($tableexists))
					{
						/* Force VirtueMart default setting */
						$hash_secret = "VirtueMartIsCool";
						$timestamp = time();
						$fields = array();
						$fields['user_info_id'] = md5(uniqid($hash_secret));
						$fields['user_id'] =  intval($user->get('id'));
						$fields['address_type_name'] =  '-default-';
						$fields['cdate'] =  $timestamp;
						$fields['mdate'] =  $timestamp;
						$fields['perms'] =  "shopper";
						$fields['email'] = $user->get('email');
						$fields['country'] = 'ES';
						$fields['last_name'] = $auth_info['profile']['name']['familyName'];
						$fields['first_name'] = $auth_info['profile']['name']['givenName'];
						$fields['phone'] = $phone;
						$fields['mobile'] = $mobile;
						$fields['shopper_group_id'] = 5;
						$fields['bank_account_type'] = "Checking";
						$fields['vendor_id'] = 1;
						$auth_provider = $auth_info['profile']['providerName'];
						//print_r($fields);

						// User details info
						$check = "SELECT COUNT(user_info_id) AS num_rows FROM #__virtuemart_userinfos WHERE user_id='" . $fields['virtuemart_user_id'] . "'";
						$db->setQuery($check);
						$db->query($check);
						if (intval($db->loadResult()) == 0) {
							//echo "Doing Insert\n";
							$vmquery = "INSERT INTO #__virtuemart_userinfos ";
							$vmquery .= "(user_info_id,user_id,address_type,address_type_name,company,title,last_name,first_name,middle_name,phone_1,phone_2,fax,address_1,address_2,city,state,country,zip,user_email,cdate,mdate,perms,bank_account_nr,bank_name,bank_sort_code,bank_iban,bank_account_holder,bank_account_type)";
							$vmquery .= " VALUES ";
							$vmquery .= "('".$fields['user_info_id']."', '".$fields['user_id']."', 'BT', NULL, NULL, NULL, '".$fields['last_name']."', '".$fields['first_name']."', NULL, '".$fields['phone']."', '".$fields['mobile']."', NULL, '', NULL, '', '', 'ESP', '', '".$fields['email']."', '".$fields['cdate']."', '".$fields['mdate']."', 'shopper', '', '". $auth_provider ."', '', '', '', 'Checking')";
						} else {
							//echo "Doing Update\n";
							$vmquery = "UPDATE #__virtuemart_userinfos SET `bank_name` = '".$auth_provider."', `mdate` = '".time()."', `perms` = '".$fields['perms']."', `user_email` = '".$fields['email']."', `phone_2` = '".$fields['mobile']."'";
							$vmquery .= " WHERE user_id=".$fields['user_id']." AND address_type='BT'";
						}
						$db->setQuery($vmquery);
						if (!$db->query($vmquery)) {
						   JERROR::raiseError(500, $db->stderr());
						}

						// User - Vendor - relationship
						$check = "SELECT COUNT(user_id) AS num_rows FROM #__vm_auth_user_vendor WHERE vendor_id='".$fields['vendor_id']."' AND user_id='" .$fields['user_id']. "'";
						$db->setQuery($check);
						$db->query($check);
						if(intval($db->loadResult()) == 0) {
							//echo "Doing Insert\n";
							$vmq = "INSERT INTO #__vm_auth_user_vendor (user_id,vendor_id)";
							$vmq .= " VALUES ";
							$vmq .= "('" . $fields['user_id'] . "','" . $fields['vendor_id'] . "') ";
						} else {
							//echo "Doing Update\n";
							$vmq = "UPDATE #__vm_auth_user_vendor set ";
							$vmq .= "vendor_id='".$fields['vendor_id']."' ";
							$vmq .= "WHERE user_id='" . $fields['user_id'] . "'";
						}
						$db->setQuery($vmq);
						if (!$db->query($vmq)) {
						   JERROR::raiseError(500, $db->stderr());
						}

						// User - Shopper - ShopperGroup - relationship
						$check = "SELECT COUNT(user_id) AS num_rows FROM #__vm_shopper_vendor_xref WHERE vendor_id='".$fields['vendor_id']."' AND user_id='" . $fields['user_id'] . "'";
						$db->setQuery($check);
						$db->query($check);
						if(intval($db->loadResult()) == 0) {
							//echo "Doing Insert\n";
							$vmq  = "INSERT INTO #__vm_shopper_vendor_xref ";
							$vmq .= "(user_id,vendor_id,shopper_group_id,customer_number) ";
							$vmq .= "VALUES ('" . $fields['user_id'] . "', '" . $fields['vendor_id'] . "','".$fields['shopper_group_id']."', '".$fileds['customer_number']."')";
						} else {
							//echo "Doing Update\n";
							$vmq = "UPDATE #__vm_shopper_vendor_xref SET ";
							$vmq .= "shopper_group_id='".$fields['shopper_group_id']."' ";
							$vmq .= ",vendor_id ='".$fields['vendor_id']."' ";
							$vmq .= "WHERE user_id='" . $fields['user_id'] . "' ";
						}
						$db->setQuery($vmq);
						if (!$db->query($vmq))
						{
							JERROR::raiseError(500, $db->stderr());
						}
					}
					/* End VirtueMart2 */

					// If the user is blocked, redirect with an error
					if ($instance->get('block') == 1)
					{
						JError::raiseWarning(500, JText::_('JERROR_NOLOGIN_BLOCKED'));
						//return false;
					}

					// Mark the user as logged in
					$instance->set('guest', 0);

					// Register the needed session variables
					$session = JFactory::getSession();
					$session->set('user', $instance);

					$db = JFactory::getDBO();

					// Check to see the the session already exists.
					$app = JFactory::getApplication();
					$app->checkSession();

					// Update the user related fields for the Joomla sessions table.
					$db->setQuery(
						'UPDATE '.$db->quoteName('#__session') .
						' SET '.$db->quoteName('guest').' = '.$db->quote($instance->get('guest')).',' .
						'       '.$db->quoteName('username').' = '.$db->quote($instance->get('username')).',' .
						'       '.$db->quoteName('userid').' = '.(int) $instance->get('id') .
						' WHERE '.$db->quoteName('session_id').' = '.$db->quote($session->getId())
					);
					$db->query();

					// Hit the user last visit field
					$instance->setLastVisit();

					// Redirect
					$returnURL = $this->getReturnURL();
					$mainframe->redirect($returnURL);

				} // End valid users
			} // End Valid Auth
		} // End Token
	}

	function getReturnURL()
	{
		if($itemid =  $this->params->get('login'))
		{
			$menu =& JSite::getMenu();
			$item = $menu->getItem($itemid);
			$url = JRoute::_($item->link.'&Itemid='.$itemid, false);
		}
		else
		{
			// stay on the same page
			$uri = JFactory::getURI();
			$url = $uri->current();
			$url .= '?';
			$paramarray = $uri->getQuery(true);
			foreach ($paramarray as $paramname => $paramvalue)
			{
				if ($paramname != 'token')
				{
					$url .= $paramname;
					$url .='=';
					$url .= $paramvalue;
					$url .= '&&';
				}
			}
			//$url = $uri->toString(array('path', 'query', 'fragment'));
		}
		return $url;
	}
}

?>
