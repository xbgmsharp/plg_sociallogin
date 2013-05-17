<?php
/**
 * Main Plugin File
 * Does all the magic!
 *
 * @package                     Social Login
 * @version                     1.1.0
 *
 * @author                      xbgmsharp@gmail.com
 * @link                        https://github.com/xbgmsharp/plg_sociallogin
 * @copyright           	Copyright © 2012 xbgmsharp All Rights Reserved
 * @license                     http://www.gnu.org/licenses/gpl-2.0.html GNU/GPL
 */

defined( '_JEXEC' ) or ( 'Restricted access' ); // no direct access allowed to this file
jimport('joomla.plugin.plugin'); // import Joomla! plugin library

class plgSystemSocialLogin extends JPlugin
{
	function plgSystemSocialLogin(&$subject, $config)
	{
		parent::__construct($subject,$config);
	}

	function onAfterInitialise()
	{
		// return if current page is an administrator page
		if (JFactory::getApplication()->isAdmin()) {
			return;
		}

		if((isset($_GET['token']))||(isset($_POST['token'])))
		{
			$token = $_GET['token'] ? $_GET['token'] : $_POST['token'];
			$apiKey = $this->params->get('apikey');
			$post_data = array('token' => $token, 'apiKey' => $apiKey, 'format' => 'json');

			if ($this->params->get('usecurl') == 1)
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

			//print_r($auth_info);
			// process the auth_info response
			if ($auth_info['stat'] == 'ok')
			{
				// Check if user exist in the mapping table
				$db =& JFactory ::getDBO();
				$rpxid = 'rpx'.md5($auth_info['profile']['identifier']);
				$query = "SELECT userid FROM #__sociallogin_mapping WHERE rpxid='".$rpxid."'";
				$db->setQuery($query);
				$userid = $db->loadResult();

				$newuser = true;
				if (isset($userid))
				{
					//print "Existing User\n<br/>";
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
				else
				{
					//print "Migrated User\n<br/>";
					// like me you did a migration to J2.5
                                        if (isset($auth_info['profile']['email']))
                                        {
                                                $email = $auth_info['profile']['email'];
                                        }
                                        else if (isset($auth_info['profile']['name']['email']))
                                        {
						$email = $auth_info['profile']['email'];
					}
					// Check if a user exist with this email
	                                $query = "SELECT id FROM #__users WHERE email='".$email."'";
	                                $db->setQuery($query);
	                                $userid = $db->loadResult();
					if ($userid != 0)
					{
                                                $servicename = "";
                                                if (isset($auth_info['profile']['providerName']))
                                                {
                                                        $servicename = $auth_info['profile']['providerName'];
                                                }
						// Existing user with no mapping...add it
                                                $query = "INSERT INTO #__sociallogin_mapping (userid, rpxid, servicename) VALUES ('".$userid."','".$rpxid."','".$servicename."')";
                                                $db->setQuery($query);
                                                if (!$db->query())
                                                {
                                                        JError::raiseError(500, $db->stderror());
                                                }
						$newuser = false;
					}
				}

				if ($newuser == true)
				{
					//print "New User\n<br/>";
					$instance = JUser::getInstance();
					jimport('joomla.application.component.helper');
					$config = JComponentHelper::getParams('com_users');
					$defaultUserGroup = $config->get('new_usertype', 2);

					//print "Set Email\n<br/>";
					$email = "";
					if (isset($auth_info['profile']['email']))
					{
						$email = $auth_info['profile']['email'];
					}
					else if (isset($auth_info['profile']['name']['email']))
					{
						$email = $auth_info['profile']['email'];
					}

					//print "Set displayName & Username\n<br/>";
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

					//print "Set Formated Name\n<br/>";
					/* Set prefer displayName if Formated Name */
					if (isset($auth_info['profile']['name']['formatted']))
					{
						$displayName = $auth_info['profile']['name']['formatted'];
					}

					//print "Check if username exist\n<br/>";
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

					//print "Set value to instance\n<br/>";
					// Set value to Instance
					$instance->set('id' , 		0);
					$instance->set('name' , 	$displayName);
					$instance->set('username' , 	$preferredUsername);
					$instance->set('email' , 	$email);
					$instance->set('usertype' , 	'');
					$instance->set('groups' , 	array($defaultUserGroup));

					//echo "Set Parameters\n<br/>";
					// Force override parameters
					if ( $this->params->get('language') != "")
					{
						$instance->setParam("language", $this->params->get('language'));
					}
					if ( $this->params->get('timezone') != "")
					{
						$instance->setParam("timezone", $this->params->get('timezone'));
					}

					//print "Save user in DB\n<br/>";
					// Save user
					if (!$instance->save())
					{
						echo "Error creating new user\n<br/>";
						JError::raiseError(500, $instance->getError());
						// updating value
						if (!$instance->save(true))
						{
							echo "Again Error not a new user\n<br/>";
							JError::raiseError(500, $instance->getError());
						}
					}
					else
					{
						// Add mapping for fast find
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

					print "User Save with ID: ". $instance->get('id') ."\n<br/>";
					$user =& $instance;

				} // End new user

				// Only if the user is valid (existing or new)
				if (isset($user) and (intval($user->get('id')) != 0))
				{
					//print_r($auth_info);
					$instance = JUser::getInstance();
					if (!$instance->load(intval($user->get('id'))))
					{
						// User does not Exist this is bad if we did get to this point without user
						JError::raiseError(500, JText::_('User does not Exist'));
						return false;
					}

					/* Update service provide To be remove */
                                        $servicename = "";
                                        if (isset($auth_info['profile']['providerName']))
                                        {
                                                $servicename = $auth_info['profile']['providerName'];
                                        }
					// Update provider name
                                        $query = "UPDATE #__sociallogin_mapping SET `servicename`='".$servicename."' WHERE `userid`='". $user->get('id') ."';";
                                        $db->setQuery($query);
                                        if (!$db->query())
                                        {
						JError::raiseError(500, $db->stderror());
                                        }

					/* Force an overwrite of some user details info */
					//echo "Update user settings\n<br/>";
					if (isset($auth_info['profile']['name']['formatted']))
					{
						//$instance->load(intval($user->get('id')));
	                                        // Force override parameters
	                                        if ( $this->params->get('language') != "")
	                                        {
	                                                $instance->setParam("language", $this->params->get('language'));
	                                        }
	                                        if ( $this->params->get('timezone') != "")
	                                        {
							$instance->setParam("timezone", $this->params->get('timezone'));
						}
						$instance->save(true);
						$displayName = $auth_info['profile']['name']['formatted'];
						$sqluser = "UPDATE #__users SET `name` = '". $displayName. "' WHERE `id` = '". $user->get('id') ."';";
						$db->setQuery($sqluser);
						if (!$db->query($sqluser))
						{
							JERROR::raiseError(500, $db->stderr());
						}
					}
					/* End Force an overwrite */

					// Maybe an option and as a function
					/*
					// Check if the community builder tables are there
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

					//echo "Check Appointment Booking Pro2\n<br/>";
					/* Check if the Appointment Booking Pro2 tables are there */
					$query = "SHOW TABLES LIKE '%__sv_apptpro2_requests'";
					$db->setQuery($query);
					$tableexists = $db->loadResult();
					if (isset($tableexists))
					{
						/* Sync old ABPRO request to new register user */
						// Maybe Improve request all in one.
						// UPDATE #__sv_apptpro2_requests SET user_id='".intval($user->get('id'))."', name='".mysql_real_escape_string($user->get('name'))."' WHERE email='".$user->get('email')."';
						$sqlrequest = "SELECT id_requests,user_id,phone FROM #__sv_apptpro2_requests WHERE email = '".$user->get('email')."';";
						$db->setQuery($sqlrequest);
						$db->query($sqlrequest);
						$reqresult = $db->loadAssocList();
						foreach ($reqresult as $req)
						{
							if ($req[user_id] != $user->get('id'))
							{
								$fields['user_id'] = $user->get('id');
								$fields['name'] = $user->get('name');
								//print_r($req);
								$dbfields = explode(",", "user_id,name");
								$sqlupdate = "UPDATE #__sv_apptpro2_requests SET ".$this->dbSet($dbfields, $fields)." WHERE `id_requests` = ".$req[id_requests].";";
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

					//echo "Check VirtueMart2 Pro2\n<br/>";
					/* Check if the VirtueMart2 tables are there */
					$query = "SHOW TABLES LIKE '%__virtuemart_userinfos'";
					$db->setQuery($query);
					$tableexists = $db->loadResult();
					if (isset($tableexists))
					{
						/* Force VirtueMart default setting */
						$fields = array();
						$fields['virtuemart_user_id'] = intval($user->get('id'));
						$fields['address_type'] =  'BT';
						$fields['name'] = $auth_info['profile']['name']['givenName'];
						$fields['last_name'] = $auth_info['profile']['name']['familyName'];
						$fields['first_name'] = $auth_info['profile']['name']['givenName'];
						$fields['phone_1'] = $phone ? $phone : '';
						$fields['phone_2'] = $mobile ? $mobile : '';
						$fields['virtuemart_state_id'] = 330; // Barcelona
						$fields['virtuemart_country_id'] = 195; // Spain
						$fields['modified_on'] = date("Y-m-d H:i:s");
						$fields['modified_by'] = 588; // Joomla Admin user_id
						//print_r($fields);

						// Customer Information - User details info
						$check = "SELECT COUNT(virtuemart_user_id) AS num_rows FROM #__virtuemart_userinfos WHERE `virtuemart_user_id`='" . $fields['virtuemart_user_id'] . "'";
						$db->setQuery($check);
						$db->query($check);
						$vmquery = "";
						if (intval($db->loadResult()) == 0) {
							//echo "Doing Insert\n";
							$fields['created_on'] = date("Y-m-d H:i:s");
							$fields['created_by'] = 588;
							$dbfields = explode(",", "virtuemart_user_id,address_type,name,last_name,first_name,phone_1,phone_2,virtuemart_state_id,virtuemart_country_id,created_on,created_by,modified_on,modified_by");
							$vmquery  = "INSERT INTO #__virtuemart_userinfos SET ".$this->dbSet($dbfields, $fields).";";
						} else {
							//echo "Doing Update\n";
							// Should we override all entries?
							$dbfields = explode(",", "virtuemart_user_id,address_type,name,last_name,first_name,phone_1,phone_2,virtuemart_state_id,virtuemart_country_id,modified_on,modified_by");
							$vmquery  = "UPDATE #__virtuemart_userinfos SET ".$this->dbSet($dbfields, $fields)." WHERE `virtuemart_user_id`='" .$fields['virtuemart_user_id']. "'";
						}
						$db->setQuery($vmquery);
						if (!$db->query($vmquery))
						{
						   JERROR::raiseError(500, $db->stderr());
						}

						// Holds the unique user data
						// Unset value will the DB default
						$fields = array();
						$fields['virtuemart_user_id'] = intval($user->get('id'));
						$fields['customer_number'] = md5($user->get('username'));
						$fields['virtuemart_paymentmethod_id'] = 0;
						$fields['virtuemart_shipmentmethod_id'] = 0;
						$fields['modified_on'] = date("Y-m-d H:i:s");
						$fields['modified_by'] = 588;

						$check = "SELECT COUNT(virtuemart_user_id) AS num_rows FROM #__virtuemart_vmusers WHERE `virtuemart_user_id`='" .$fields['virtuemart_user_id']. "'";
						$db->setQuery($check);
						$db->query($check);
						$vmquery = "";
						if(intval($db->loadResult()) == 0) {
							//echo "Doing Insert\n";
							$fields['created_on'] = date("Y-m-d H:i:s");
							$fields['created_by'] = 588;
							$dbfields = explode(",", "virtuemart_user_id,customer_number,virtuemart_paymentmethod_id,virtuemart_shipmentmethod_id,created_on,created_by,modified_on,modified_by");
							$vmquery = "INSERT INTO #__virtuemart_vmusers SET ".$this->dbSet($dbfields, $fields).";";
						} else {
							//echo "Doing Update\n";
							// Should we override all entries?
							$dbfields = explode(",", "virtuemart_user_id,customer_number,virtuemart_paymentmethod_id,virtuemart_shipmentmethod_id,modified_on,modified_by");
							$vmquery = "UPDATE #__virtuemart_vmusers SET ".$this->dbSet($dbfields, $fields)." WHERE `virtuemart_user_id`='" .$fields['virtuemart_user_id']. "'";
						}
						$db->setQuery($vmquery);
						if (!$db->query($vmquery))
						{
							JERROR::raiseError(500, $db->stderr());
						}

						// xref table for users to shopper group
						$fields = array();
						$fields['virtuemart_user_id'] = intval($user->get('id'));
						$fields['virtuemart_shoppergroup_id'] = 2; // Shopper group

						$check = "SELECT COUNT(virtuemart_user_id) AS num_rows FROM #__virtuemart_vmuser_shoppergroups WHERE `virtuemart_user_id`='" . $fields['virtuemart_user_id'] . "'";
						$db->setQuery($check);
						$db->query($check);
						$vmquery = "";
						if(intval($db->loadResult()) == 0) {
							//echo "Doing Insert\n";
							$dbfields = explode(",", "virtuemart_user_id,virtuemart_shoppergroup_id");
							$vmquery = "INSERT INTO #__virtuemart_vmuser_shoppergroups SET ".$this->dbSet($dbfields, $fields).";";
						} else {
							//echo "Doing Update\n";
							// Should we override all entries?
							$dbfields = explode(",", "virtuemart_user_id,virtuemart_shoppergroup_id");
							$vmquery = "UPDATE #__virtuemart_vmuser_shoppergroups SET ".$this->dbSet($dbfields, $fields)." WHERE `virtuemart_user_id`='" . $fields['virtuemart_user_id'] . "'";
						}
						$db->setQuery($vmquery);
						if (!$db->query($vmquery))
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
					JFactory::getApplication()->redirect($returnURL);

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

	function dbSet($fields, $data = array())
	{
		if (!$data) $data = &$_POST;
		$set='';
		foreach ($fields as $field)
		{
			if (isset($data[$field]))
			{
				$set.="`$field`='".mysql_real_escape_string($data[$field])."', ";
			}
		}
		return substr($set, 0, -2);
	}
}

?>
