<?php

/**
 * ownCloud
 *
 * @author Saša Tomić
 * @copyright 2012 Saša Tomić <tomic80@gmail.com>
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

class OC_User_drupal extends OC_User_Backend {
	protected $drupal_db_host;
	protected $drupal_db_user;
	protected $drupal_db_password;
	protected $drupal_db_name;
	protected $drupal_db_prefix;
	protected $db;
	protected $db_conn;

	function __construct() {
		$this->db_conn = false;
		$this->drupal_db_host = OC_Appconfig::getValue('user_drupal', 'drupal_db_host','localhost');
		$this->drupal_db_user = OC_Appconfig::getValue('user_drupal', 'drupal_db_user','');
		$this->drupal_db_password = OC_Appconfig::getValue('user_drupal', 'drupal_db_password','');
		$this->drupal_db_name = OC_Appconfig::getValue('user_drupal', 'drupal_db_name','');
		$this->drupal_db_prefix = OC_Appconfig::getValue('user_drupal', 'drupal_db_prefix','');
    $this->drupal_global_group = OC_Appconfig::getValue('user_drupal', 'drupal_global_group','drupal user');

    if(empty($this->drupal_db_host)) $this->drupal_db_host=OC_Config::getValue( "dbhost", "localhost" );
    if(empty($this->drupal_db_name)) $this->drupal_db_name=OC_Config::getValue( "dbname", "owncloud" );
    if(empty($this->drupal_db_user)) $this->drupal_db_user=OC_Config::getValue( "dbuser", "" );
    if(empty($this->drupal_db_password)) $this->drupal_db_password=OC_Config::getValue( "dbpassword", "" );

		$errorlevel = error_reporting();
		error_reporting($errorlevel & ~E_WARNING);
		$this->db = new mysqli($this->drupal_db_host, $this->drupal_db_user, $this->drupal_db_password, $this->drupal_db_name);
		error_reporting($errorlevel);
		if ($this->db->connect_errno) {
			OC_Log::write('OC_User_drupal',
					'OC_User_drupal, Failed to connect to drupal database: ' . $this->db->connect_error,
					OC_Log::ERROR);
			return false;
		}
		$this->db_conn = true;
		$this->drupal_db_prefix = $this->db->real_escape_string($this->drupal_db_prefix);
	}

	/**
	 * @brief Set email address
	 * @param $uid The username
	 */
	private function setEmail($uid) {
		if (!$this->db_conn) {
			return false;
		}

		$q = 'SELECT mail FROM '. $this->drupal_db_prefix .'users WHERE name = "'. $this->db->real_escape_string($uid) .'" AND status = 1';
		$result = $this->db->query($q);
		$email = $result->fetch_assoc();
		$email = $email['mail'];
		OC_Preferences::setValue($uid, 'settings', 'email', $email);
	}

	/**
	 * @brief Check if the password is correct
	 * @param $uid The username
	 * @param $password The password
	 * @returns true/false
	 */
	public function checkPassword($uid, $password){
		if (!$this->db_conn) {
			return false;
		}

		$query = 'SELECT name FROM '. $this->drupal_db_prefix .'users WHERE name = "' . $this->db->real_escape_string($uid) . '" AND status = 1';
		$query .= ' AND pass = "' . md5($this->db->real_escape_string($password)) . '"';
		$result = $this->db->query($query);
		$row = $result->fetch_assoc();

		if ($row) {
      if($this->drupal_global_group!=''){
        if(!OC_Group::groupExists($this->drupal_global_group)){
          OC_Group::createGroup($this->drupal_global_group);
        }					
        
        if( OC_Group::inGroup( $uid, $this->drupal_global_group )){
          // Do nothing					
        }
        else{
          OC_Group::addToGroup( $uid, $this->drupal_global_group );
        }
      }
			$this->setEmail($uid);
			return $row['name'];
		}
		return false;
	}

	/**
	 * @brief Get a list of all users
	 * @returns array with all enabled uids
	 *
	 * Get a list of all users
	 */
	public function getUsers($search = '', $limit = NULL, $offset = NULL) {
		$users = array();
    $drupal_users = array();
    $start=0;

		if ($this->db->connect_errno) {
			OC_Log::write('OC_User_drupal',
					'OC_User_drupal, Failed to connect to drupal database: ' . $this->db->connect_error,
					OC_Log::ERROR);
			return $users;
		}
		if (!$this->db_conn) {
			return $users;
		}

		$q = 'SELECT name FROM '. $this->drupal_db_prefix .'users WHERE status = 1';
		$result = $this->db->query($q);
		if (!$result) {
			OC_Log::write('OC_User_drupal',
					'OC_User_drupal, Failed query to drupal database, check your Drupal 6.x DB configuration',
					OC_Log::ERROR);
			return $users;
		}
		elseif ($result->num_rows==0) {
			OC_Log::write('OC_User_drupal',
					'OC_User_drupal, Failed query to drupal database, check your Drupal 6.x DB configuration',
					OC_Log::ERROR);
			return $users;
		}

		while ($row = $result->fetch_assoc()) {
			if(!empty($row['name'])) {
				$drupal_users[] = $row['name'];
			}
		}
		sort($drupal_users);
    $nb_users=sizeof($drupal_users);
    $fin = $nb_users;
    if($search==''){
      if($offset!=NULL) $start=$offset;
      if($limit!=NULL) $fin=$start+$limit; 
    }
    if($fin>$nb_users) $fin=$nb_users;
      //echo $limit.'/'.$offset.'*';
    for($i=$start ; $i<$fin ; $i++){
      if($search=='' || strpos($drupal_users[$i],$search)>-1){
        $users[] = $drupal_users[$i];
      }
    }

		return $users;
	}

	/**
	 * @brief check if a user exists
	 * @param string $uid the username
	 * @return boolean
	 */
	public function userExists($uid) {
		if (!$this->db_conn) {
			return false;
		}

		$q = 'SELECT name FROM '. $this->drupal_db_prefix .'users WHERE name = "'. $this->db->real_escape_string($uid) .'" AND status = 1';
		$result = $this->db->query($q);
		return $result->num_rows > 0;
	}
}
