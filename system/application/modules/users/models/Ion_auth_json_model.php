<?php  if ( ! defined('BASEPATH')) exit('No direct script access allowed');
/**
* Name:  Ion Auth Model
*
* Version: 2.5.2
*
* Author:  Ben Edmunds
* 		   ben.edmunds@gmail.com
*	  	   @benedmunds
*
* Added Awesomeness: Phil Sturgeon
*
* Location: http://github.com/benedmunds/CodeIgniter-Ion-Auth
*
* Created:  10.01.2009
*
* Last Change: 3.22.13
*
* Changelog:
* * 3-22-13 - Additional entropy added - 52aa456eef8b60ad6754b31fbdcc77bb
*
* Description:  Modified auth system based on redux_auth with extensive customization.  This is basically what Redux Auth 2 should be.
* Original Author name has been kept but that does not mean that the method has not been modified.
*
* Requirements: PHP5 or above
*
*/
require_once APPPATH.'modules/users/models/Ion_auth_model.php';

class Ion_auth_json_model extends Ion_auth_model
{
	public $db;

	function __construct()
	{
		parent::__construct();

		$this->db = new Nyankod\JsonFileDB(SITE_PATH.'users/');
	}

	/**
	 * users
	 *
	 * @return object Users
	 * @author Ben Edmunds
	 **/
	public function users($groups = NULL)
	{
		$this->trigger_events('users');

		$this->db->setTable('users');
		$result = $this->db->selectAll();

		return $result;
	}

	/**
	 * user
	 *
	 * @return object
	 * @author Ben Edmunds
	 **/
	public function user($id = NULL)
	{
		$this->trigger_events('user');

		//if no id was passed use the current users id
		$id || $id = $this->session->userdata('user_id');

		$this->db->setTable('users');
		$result = $this->db->select('id', $id);

		return $result;
	}

	/**
	 * update
	 *
	 * @return bool
	 * @author Phil Sturgeon
	 **/
	public function update($id, array $data)
	{
		$this->trigger_events('pre_update_user');

		$user = $this->user($id);

		if (array_key_exists($this->identity_column, $data) && $this->identity_check($data[$this->identity_column]) && $user[$this->identity_column] !== $data[$this->identity_column])
		{
			$this->set_error('account_creation_duplicate_'.$this->identity_column);

			$this->trigger_events(array('post_update_user', 'post_update_user_unsuccessful'));
			$this->set_error('update_unsuccessful');

			return FALSE;
		}

		if (array_key_exists('username', $data) || array_key_exists('password', $data) || array_key_exists('email', $data))
		{
			if (array_key_exists('password', $data))
			{
				if( ! empty($data['password']))
				{
					$data['password'] = $this->hash_password($data['password'], $user['salt']);
				}
				else
				{
					// unset password so it doesn't effect database entry if no password passed
					unset($data['password']);
				}
			}
		}

		$this->trigger_events('extra_where');

		$this->db->setTable('users');

		if (! $this->db->update('id', $user['id'], $data))
		{
			$this->trigger_events(array('post_update_user', 'post_update_user_unsuccessful'));
			$this->set_error('update_unsuccessful');
			return FALSE;
		}

		$this->trigger_events(array('post_update_user', 'post_update_user_successful'));
		$this->set_message('update_successful');
		return TRUE;
	}

	/**
	 * login
	 *
	 * @return bool
	 * @author Mathew
	 **/
	public function login($identity, $password, $remember=FALSE)
	{
		$this->trigger_events('pre_login');

		if (empty($identity) || empty($password))
		{
			$this->set_error('login_unsuccessful');
			return FALSE;
		}

		$this->trigger_events('extra_where');

		$this->db->setTable('users');
		$query = $this->db->select($this->identity_column, $identity);

		if($this->is_time_locked_out($identity))
		{
			//Hash something anyway, just to take up time
			$this->hash_password($password);

			$this->trigger_events('post_login_unsuccessful');
			$this->set_error('login_timeout');

			return FALSE;
		}

		if (count($query) >= 1)
		{
			$user = $query;

			$password = $this->hash_password_db($user['id'], $password);

			if ($password === TRUE)
			{
				if ($user['active'] == 0)
				{
					$this->trigger_events('post_login_unsuccessful');
					$this->set_error('login_unsuccessful_not_active');

					return FALSE;
				}

				$this->set_session($user);

				$this->update_last_login($user['id']);

				// $this->clear_login_attempts($identity);

				if ($remember && $this->config->item('remember_users', 'ion_auth'))
				{
					$this->remember_user($user['id']);
				}

				$this->trigger_events(array('post_login', 'post_login_successful'));
				$this->set_message('login_successful');

				return TRUE;
			}
		}

		//Hash something anyway, just to take up time
		$this->hash_password($password);

		$this->increase_login_attempts($identity);

		$this->trigger_events('post_login_unsuccessful');
		$this->set_error('login_unsuccessful');

		return FALSE;
	}

	/**
	 * remember_user
	 *
	 * @return bool
	 * @author Ben Edmunds
	 **/
	public function remember_user($id)
	{
		$this->trigger_events('pre_remember_user');

		if (!$id)
		{
			return FALSE;
		}

		$user = $this->user($id);

		$salt = $this->salt();

		$this->db->setTable('users');
		$user = $this->db->select('id', $id);
		$user = array_merge($user, array('remember_code' => $salt));

		if ($this->db->update('id', $id, $user) > -1)
		{
			// if the user_expire is set to zero we'll set the expiration two years from now.
			if($this->config->item('user_expire', 'ion_auth') === 0)
			{
				$expire = (60*60*24*365*2);
			}
			// otherwise use what is set
			else
			{
				$expire = $this->config->item('user_expire', 'ion_auth');
			}

			set_cookie(array(
			    'name'   => $this->config->item('identity_cookie_name', 'ion_auth'),
			    'value'  => $user->{$this->identity_column},
			    'expire' => $expire
			));

			set_cookie(array(
			    'name'   => $this->config->item('remember_cookie_name', 'ion_auth'),
			    'value'  => $salt,
			    'expire' => $expire
			));

			$this->trigger_events(array('post_remember_user', 'remember_user_successful'));
			return TRUE;
		}

		$this->trigger_events(array('post_remember_user', 'remember_user_unsuccessful'));
		return FALSE;
	}

	/**
	 * set_session
	 *
	 * @return bool
	 * @author jrmadsen67
	 **/
	public function set_session($user)
	{

		$this->trigger_events('pre_set_session');

		$session_data = array(
		    'identity'             => $user[$this->identity_column],
		    'id' 	               => $user['id'],
		    'username'             => $user['username'],
		    'email'                => $user['email'],
		    SITE_SLUG.'_username'  => $user['username'],
		    SITE_SLUG.'_email'     => $user['email'],
		    'user_id'              => $user['id'], //everyone likes to overwrite id so we'll use user_id
		    'old_last_login'       => $user['last_login']
		);

		$this->session->set_userdata($session_data);

		$this->trigger_events('post_set_session');

		return TRUE;
	}

	/**
	 * update_last_login
	 *
	 * @return bool
	 * @author Ben Edmunds
	 **/
	public function update_last_login($id)
	{
		$this->trigger_events('update_last_login');

		$this->load->helper('date');

		$this->trigger_events('extra_where');

		$this->db->setTable('users');
		$user = $this->db->select('id', $id);
		$user = array_merge($user, array('last_login' => time()));
		return $this->db->update('id', $id, $user);
	}

	/**
	 * This function takes a password and validates it
	 * against an entry in the users table.
	 *
	 * @return void
	 * @author Mathew
	 **/
	public function hash_password_db($id, $password, $use_sha1_override=FALSE)
	{
		if (empty($id) || empty($password))
		{
			return FALSE;
		}

		$this->trigger_events('extra_where');

		$query = $this->db->select('id', $id);

		$hash_password_db = $query;

		if (count($query) < 1)
		{
			return FALSE;
		}

		// bcrypt
		if ($use_sha1_override === FALSE && $this->hash_method == 'bcrypt')
		{
			if ($this->bcrypt->verify($password,$hash_password_db['password']))
			{
				return TRUE;
			}

			return FALSE;
		}

		// sha1
		if ($this->store_salt)
		{
			$db_password = sha1($password . $hash_password_db['salt']);
		}
		else
		{
			$salt = substr($hash_password_db['password'], 0, $this->salt_length);

			$db_password =  $salt . substr(sha1($salt . $password), 0, -$this->salt_length);
		}

		if($db_password == $hash_password_db['password'])
		{
			return TRUE;
		}
		else
		{
			return FALSE;
		}
	}

	/**
	 * Identity check
	 *
	 * @return bool
	 * @author Mathew
	 **/
	public function identity_check($identity = '')
	{
		$this->trigger_events('identity_check');

		if (empty($identity))
		{
			return FALSE;
		}

		$this->db->setTable('users');
		return $this->db->select($this->identity_column, $identity);
	}


	/**
	 * groups
	 *
	 * @return object
	 * @author Ben Edmunds
	 **/
	public function groups()
	{
		$this->trigger_events('groups');

		$this->db->setTable('groups');
		$result = $this->db->selectAll();

		return $result;
	}

	/**
	 * get_users_groups
	 *
	 * @return array
	 * @author Ben Edmunds
	 **/
	public function get_users_groups($id=FALSE)
	{
		$this->trigger_events('get_users_group');

		//if no id was passed use the current users id
		$id || $id = $this->session->userdata('user_id');

		$groups = array();

		$this->db->setTable('users');
		$user = $this->db->select('id', $id);
		if($user){
			foreach ($user['groups'] as $group) {
				$this->db->setTable('groups');
				$groups[] = $this->db->select('id', $group);
			}
		}

		return $groups;
	}

	/**
	 * register
	 *
	 * @return bool
	 * @author Mathew
	 **/
	public function register($username, $password, $email, $additional_data = array(), $groups = array())
	{
		$this->trigger_events('pre_register');

		$manual_activation = $this->config->item('manual_activation', 'ion_auth');

		if ($this->identity_column == 'email' && $this->email_check($email))
		{
			$this->set_error('account_creation_duplicate_email');
			return FALSE;
		}
		elseif ($this->identity_column == 'username' && $this->username_check($username))
		{
			$this->set_error('account_creation_duplicate_username');
			return FALSE;
		}

		// If username is taken, use username1 or username2, etc.
		if ($this->identity_column != 'username')
		{
			$original_username = $username;
			for($i = 0; $this->username_check($username); $i++)
			{
				if($i > 0)
				{
					$username = $original_username . $i;
				}
			}
		}


		// IP Address
		$ip_address = $this->_prepare_ip($this->input->ip_address());
		$salt       = $this->store_salt ? $this->salt() : FALSE;
		$password   = $this->hash_password($password, $salt);

		$this->db->setTable('groups');
		$default_group = $this->db->select('name', $this->config->item('default_group', 'ion_auth'));
		
		$this->db->setTable('users');

		// Users table.
		$data = array(
			'id'		 => $this->db->generate_id(),
		    'username'   => $username,
		    'password'   => $password,
		    'email'      => $email,
		    'ip_address' => $ip_address,
		    'created_on' => time(),
		    'last_login' => time(),
		    'active'     => ($manual_activation === false ? 1 : 0),
		    'groups'	 => array($default_group['id'])
		);

		if ($this->store_salt)
		{
			$data['salt'] = $salt;
		}
		else
		{
			$data['salt'] = null;
		}

		$user_data = array_merge($data, $additional_data);

		$this->trigger_events('extra_set');

		$this->db->insert($user_data);

		$id = $data['id'];

		$this->trigger_events('post_register');

		return (isset($id)) ? $id : FALSE;
	}

	/**
	* delete_user
	*
	* @return bool
	* @author Phil Sturgeon
	**/
	public function delete_user($id)
	{
		$this->trigger_events('pre_delete_user');

		// delete user from users table should be placed after remove from group
		$this->db->setTable('users');

		// if user does not exist in database then it returns FALSE else removes the user from groups
		if (! $this->db->delete('id', $id))
		{
			$this->trigger_events(array('post_delete_user', 'post_delete_user_unsuccessful'));
			$this->set_error('delete_unsuccessful');
			return FALSE;
		}

		$this->trigger_events(array('post_delete_user', 'post_delete_user_successful'));
		$this->set_message('delete_successful');
		return TRUE;
	}

	/**
	 * create_group
	 *
	 * @author aditya menon
	*/
	public function create_group($group_name = FALSE, $group_description = '', $additional_data = array())
	{
		// bail if the group name was not passed
		if(!$group_name)
		{
			$this->set_error('group_name_required');
			return FALSE;
		}

		$this->db->setTable("groups");

		// bail if the group name already exists
		$existing_group = $this->db->select('name', $group_name);
		if(count($existing_group) !== 0)
		{
			$this->set_error('group_already_exists');
			return FALSE;
		}

		$data = array(
			'id'			=> $this->db->generate_id(),
			'name'			=> $group_name, 
			'description'	=> $group_description
			);

		$this->trigger_events('extra_group_set');

		// insert the new group
		$this->db->insert($data);
		$group_id = $data['id'];

		// report success
		$this->set_message('group_creation_successful');
		// return the brand new group id
		return $group_id;
	}

	/**
	* delete_group
	*
	* @return bool
	* @author aditya menon
	**/
	public function delete_group($group_id = FALSE)
	{
		// bail if mandatory param not set
		if(!$group_id || empty($group_id))
		{
			return FALSE;
		}

		$this->trigger_events('pre_delete_group');

		// remove all users from this group
		$this->db->setTable('groups');
		if (! $this->db->delete('id', $group_id))
		{
			$this->trigger_events(array('post_delete_group', 'post_delete_group_unsuccessful'));
			$this->set_error('group_delete_unsuccessful');
			return FALSE;
		}

		$this->trigger_events(array('post_delete_group', 'post_delete_group_successful'));
		$this->set_message('group_delete_successful');
		return TRUE;
	}

	/**
	 * Checks username
	 *
	 * @return bool
	 * @author Mathew
	 **/
	public function username_check($username = '')
	{
		$this->trigger_events('username_check');

		if (empty($username))
		{
			return FALSE;
		}

		$this->db->setTable('users');

		return $this->db->select('username', $username);
	}

	/**
	 * Checks email
	 *
	 * @return bool
	 * @author Mathew
	 **/
	public function email_check($email = '')
	{
		$this->trigger_events('email_check');

		if (empty($email))
		{
			return FALSE;
		}

		$this->db->setTable('users');
		
		return $this->db->select('email', $email);
	}

}