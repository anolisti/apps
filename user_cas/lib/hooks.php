<?php
/**
 * ownCloud - user_cas
 *
 * @author Sixto Martin <sixto.martin.garcia@gmail.com>
 * @copyright Sixto Martin Garcia. 2012
 * @copyright Leonis. 2014 <devteam@leonis.at>
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU AFFERO GENERAL PUBLIC LICENSE for more details.
 *
 * You should have received a copy of the GNU Affero General Public
 * License along with this library.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

/**
 * This class contains all hooks.
 */
class OC_USER_CAS_Hooks {

	static public function post_login($parameters) {

		$uid = $parameters['uid'];
		$casBackend = OC_USER_CAS::getInstance();
		$userDatabase = new OC_User_Database;

		if (phpCAS::isAuthenticated()) {
			// $cas_attributes may vary in name, therefore attributes are fetched to $attributes
			$cas_attributes = phpCAS::getAttributes();
			$cas_uid = phpCAS::getUser();

			// parameters 
			$attributes = array();


			if ($cas_uid == $uid) {
				OC_Log::write('cas','attr  \"'.implode(',',$cas_attributes).'\" for the user: '.$uid, OC_Log::DEBUG);


				if (array_key_exists($casBackend->displayNameMapping, $cas_attributes)) 
					$attributes['cas_name'] = $cas_attributes[$casBackend->displayNameMapping];	
				else 
					$attributes['cas_name'] = $cas_attributes['cn'];
                
				if (array_key_exists($casBackend->mailMapping, $cas_attributes)) 
					$attributes['cas_email'] = $cas_attributes[$casBackend->mailMapping];
				else 
					$attributes['cas_email'] = $cas_attributes['mail'];

				if (array_key_exists($casBackend->groupMapping, $cas_attributes)) {
					$attributes['cas_groups'] = $cas_attributes[$casBackend->groupMapping];
				}
				else if (!empty($casBackend->defaultGroup)) {
					$attributes['cas_groups'] = array($casBackend->defaultGroup);
					OC_Log::write('cas','Using default group "'.$casBackend->defaultGroup.'" for the user: '.$uid, OC_Log::DEBUG);
				}

				if (!$userDatabase->userExists($uid)) {
					// create users if they do not exist
					if (preg_match( '/[^a-zA-Z0-9 _\.@\-]/', $uid)) {
						OC_Log::write('cas','Invalid username "'.$uid.'", allowed chars "a-zA-Z0-9" and "_.@-" ',OC_Log::DEBUG);
						return false;
					}
					else {
						$random_password = OC_Util::generateRandomBytes(20);
						OC_Log::write('cas','Creating new user: '.$uid, OC_Log::DEBUG);
						$userDatabase->createUser($uid, $random_password);

						// after creating the user, fill the attributes
						if($userDatabase->userExists($uid)) 
							OC_USER_CAS_Hooks::update_user($uid,$attributes);
						
					}
				}

				// try to update user attributes
				if ($casBackend->updateUserData) 
					OC_USER_CAS_Hooks::update_user($cas_uid,$attributes);

				return true;
			}
		}
		return false;
	}

	static public function update_user($uid, $attributes) {

		$casBackend = OC_USER_CAS::getInstance();

		OC_Log::write('cas','Updating data of the user: '.$uid,OC_Log::DEBUG);
		OC_Log::write('cas','attr: '.implode(",",$attributes),OC_Log::DEBUG);

		if(isset($attributes['cas_email'])) {
			update_mail($uid, $attributes['cas_email']);
		}
		if (isset($attributes['cas_name'])) {
			update_name($uid, $attributes['cas_name']);
		}
		if (isset($attributes['cas_groups'])) {
			update_groups($uid, $attributes['cas_groups'], $casBackend->protectedGroups, false);
		}
	}


	static public function logout($parameters) {
		$casBackend = OC_USER_CAS::getInstance();

		if (phpCAS::isAuthenticated()) 
			phpCAS::logout();
		
		return true;
	}

}

function update_mail($uid, $email) {
	if ($email != OC_Preferences::getValue($uid, 'settings', 'email', '')) {
		OC_Preferences::setValue($uid, 'settings', 'email', $email);
		OC_Log::write('cas','Set email "'.$email.'" for the user: '.$uid, OC_Log::DEBUG);
	}
}

function update_name($uid, $name) {
		OC_Log::write('cas','Set Name -'.$name.'- for the user: '.$uid, OC_Log::DEBUG);
		$casBackend = OC_USER_CAS::getInstance();
		$casBackend->setDisplayName($uid, $name);
}

/**
* Gets an array of groups and will try to add the group to OC and then add the user to the groups.
* 
*/
function update_groups($uid, $groups, $protected_groups=array(), $just_created=false) {

	if(!$just_created) {
		$old_groups = OC_Group::getUserGroups($uid);
		foreach($old_groups as $group) {
			if(!in_array($group, $protected_groups) && !in_array($group, $groups)) {
				OC_Group::removeFromGroup($uid,$group);
				OC_Log::write('cas','Removed "'.$uid.'" from the group "'.$group.'"', OC_Log::DEBUG);
			}
		}
	}

	foreach($groups as $group) {
		if (preg_match( '/[^a-zA-Z0-9 _\.@\-]/', $group)) {
			OC_Log::write('cas','Invalid group "'.$group.'", allowed chars "a-zA-Z0-9" and "_.@-" ',OC_Log::DEBUG);
		}
		else {
			if (!OC_Group::inGroup($uid, $group)) {
				if (!OC_Group::groupExists($group)) {
					OC_Group::createGroup($group);
					OC_Log::write('cas','New group created: '.$group, OC_Log::DEBUG);
				}
				OC_Group::addToGroup($uid, $group);
				OC_Log::write('cas','Added "'.$uid.'" to the group "'.$group.'"', OC_Log::DEBUG);
			}
		}
	}
}
