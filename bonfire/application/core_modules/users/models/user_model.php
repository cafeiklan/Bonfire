<?php if (!defined('BASEPATH')) exit('No direct script access allowed');
/*
	Copyright (c) 2011 Lonnie Ezell

	Permission is hereby granted, free of charge, to any person obtaining a copy
	of this software and associated documentation files (the "Software"), to deal
	in the Software without restriction, including without limitation the rights
	to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
	copies of the Software, and to permit persons to whom the Software is
	furnished to do so, subject to the following conditions:
	
	The above copyright notice and this permission notice shall be included in
	all copies or substantial portions of the Software.
	
	THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
	IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
	FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
	AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
	LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
	OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
	THE SOFTWARE.
*/

/*
	Class: User_model
	
	The central way to access and perform CRUD on users.
*/
class User_model extends BF_Model {

	protected $table		= 'users';
	protected $soft_deletes	= true;
	protected $date_format	= 'datetime';
	protected $set_modified = FALSE;

	public function __construct() 
	{
		parent::__construct();
	}
	
	//--------------------------------------------------------------------
	
	/*
		Method: insert()
		
		Creates a new user in the database. 
		
		Required parameters sent in the $data array:
			- password
			- A unique email address
			
		If no _role_id_ is passed in the $data array, it
		will assign the default role from <Roles> model.
		
		Parameters:
			$data	- An array of user information.
		
		Returns:
			$id	- The ID of the new user.
	*/
	public function insert($data=array()) 
	{
		if (!$this->_function_check(FALSE, $data))
		{
			return FALSE;
		}
		
		if (!isset($data['password']) || empty($data['password']))
		{
			$this->error = 'No Password present.';
			return FALSE;
		}
		
		if (!isset($data['email']) || empty($data['email']))
		{
			$this->error = 'No Email given.';
			return FALSE;
		}
		
		// Is this a unique email?
		if ($this->is_unique('email', $data['email']) == FALSE)
		{
			$this->error = 'Email already exists.';
			return FALSE;
		}
	
		if (empty($data['username'])) 
		{
		  unset($data['username']);
		}
		
		// Display Name
		if (!isset($data['display_name']) || (isset($data['display_name']) && empty($data['display_name'])))
		{
			if ($this->settings_lib->item('auth.use_usernames') == 1 && !empty($data['username']))
			{
				$data['display_name'] = $data['username'];
			}
			else
			{
				$data['display_name'] = $data['email'];
			}
		}

		list($password, $salt) = $this->hash_password($data['password']);
		
		unset($data['password'], $data['pass_confirm'], $data['submit']);
		
		$data['password_hash'] = $password;
		$data['salt'] = $salt;
		
		// What's the default role?
		if (!isset($data['role_id']))
		{
			// We better have a guardian here
			if (!class_exists('Role_model'))
			{
				$this->load->model('roles/Role_model','role_model');
			}

			$data['role_id'] = $this->role_model->default_role_id();
		}
		
		$id = parent::insert($data);
		
		Events::trigger('after_create_user', $id);
		
		return $id;
	}
	
	//--------------------------------------------------------------------
	
	/*
		Method: update()
		
		Updates an existing user. Before saving, it will:
			- generate a new password/salt combo if both password and pass_confirm are passed in.
			- store the country code
			
		Parameters:
			$id		- An INT with the user's ID.
			$data	- An array of key/value pairs to update for the user.
			
		Returns: 
			true/FALSE
	*/
	public function update($id=null, $data=array()) 
	{	
		if ($id)
		{
			$trigger_data = array('user_id'=>$id, 'data'=>$data);
			Events::trigger('before_user_update', $trigger_data);
		}
		
		if (empty($data['pass_confirm']) && isset($data['password'])) 
		{
			unset($data['pass_confirm'], $data['password']);
		} 
		else if (!empty($data['password']) && !empty($data['pass_confirm']) && $data['password'] == $data['pass_confirm'])
		{
			list($password, $salt) = $this->hash_password($data['password']);
		
			unset($data['password'], $data['pass_confirm']);

			$data['password_hash'] = $password;
			$data['salt'] = $salt;
		}

		// Handle the country
		if (isset($data['iso']))
		{
			$data['country_iso'] = $data['iso'];
			unset($data['iso']);
		}

		$return = parent::update($id, $data);
		
		if ($return)
		{
			$trigger_data = array('user_id'=>$id, 'data'=>$data);
			Events::trigger('after_user_update', $trigger_data);
		}
		
		return $return;
	}
	
	//--------------------------------------------------------------------
	
	/*
		Method: find()
		
		Finds an individual user record. Also returns role information for 
		the user.
		
		Parameters:
			$id	- An INT with the user's ID.
			
		Returns:
			An object with the user's information.
	*/
	public function find($id=null) 
	{
		if (empty($this->selects))
		{
			$this->select($this->table .'.*, role_name');
		}
	
		$this->db->join('roles', 'roles.role_id = users.role_id', 'left');
	
		return parent::find($id);
	}
	
	//--------------------------------------------------------------------
	
	/*
		Method: find_all()
		
		Returns all user records, and their associated role information. 
		
		Parameters:
			$show_deleted	- If FALSE, will only return non-deleted users. If true, will
				return both deleted and non-deleted users.
				
		Returns:
			An array of objects with each user's information.
	*/
	public function find_all($show_deleted=FALSE) 
	{
		if (empty($this->selects))
		{
			$this->select($this->table .'.*, role_name');
		}
	
		if ($show_deleted === FALSE)
		{
			$this->db->where('users.deleted', 0);
		}
		
		$this->db->join('roles', 'roles.role_id = users.role_id', 'left');
		
		return parent::find_all();
	}
	
	//--------------------------------------------------------------------
	
	/*
		Method: find_by()
		
		Locates a single user based on a field/value match, with their role information.
		If the $field string is 'both', then it will attempt to find the user
		where their $value field matches either the username or email on record.
		
		Parameters:
			$field	- A string with the field to match.
			$value	- A string with the value to search for.
			
		Returns:
			An object with the user's info, or FALSE on failure.
	*/
	public function find_by($field=null, $value=null) 
	{
		$this->db->join('roles', 'roles.role_id = users.role_id', 'left');
		
		if (empty($this->selects))
		{
			$this->select($this->table .'.*, role_name');
		}
		
		if ($field == 'both')
		{
			$field = array(
				'username'	=> $value,
				'email'		=> $value
			);
			
			return parent::find_by($field, null, 'or');
		}
		
		return parent::find_by($field, $value);
	}
	
	//--------------------------------------------------------------------
	
	/*
		Method: count_by_roles()
		
		Returns the number of users that belong to each role.
		
		Returns:
			An array of objects representing the number in each role.
	*/
	public function count_by_roles() 
	{
		$prefix = $this->db->dbprefix;
	
		$sql = "SELECT role_name, COUNT(1) as count
				FROM {$prefix}users, {$prefix}roles
				WHERE {$prefix}users.role_id = {$prefix}roles.role_id
				GROUP BY {$prefix}users.role_id";
		
		$query = $this->db->query($sql);
		
		if ($query->num_rows())
		{
			return $query->result();
		}

		return FALSE;
	}
	
	//--------------------------------------------------------------------
	
	/*
		Method: count_all()
		
		Counts all users in the system. 
		
		Parameters:
			$get_deleted	- If FALSE, will only return active users. If true, 
				will return both deleted and active users.
				
		Returns: 
			An INT with the number of users found.
	*/
	public function count_all($get_deleted = FALSE) 
	{	
		if ($get_deleted)
		{
			// Get only the deleted users
			$this->db->where('users.deleted !=', 0);
		}
		else 
		{
			$this->db->where('users.deleted', 0);
		}
		
		return $this->db->count_all_results('users');
	}
	
	//--------------------------------------------------------------------
	
	/*
		Method: delete()
		
		Performs a standard delete, but also allows for purging of a record.
		
		Parameters:
			$id		- An INT with the record ID to delete.
			$purge	- If FALSE, will perform a soft-delete. If true, will permenantly
				delete the record.
				
		Returns:
			true/FALSE
	*/
	public function delete($id=0, $purge=FALSE) 
	{
		if ($purge === true)
		{
			// temporarily set the soft_deletes to true.
			$this->soft_deletes = FALSE;
		}
		
		return parent::delete($id);
	}
	
	//--------------------------------------------------------------------
	
	
	//--------------------------------------------------------------------
	// !AUTH HELPER METHODS
	//--------------------------------------------------------------------
	
	/*
		Method: hash_password()
		
		Generates a new salt and password hash for the given password.
		
		Parameters:
			$old	- The password to hash.
			
		Returns:
			An array with the hashed password and new salt.
	*/
	public function hash_password($old='') 
	{
		if (!function_exists('do_hash'))
		{
			$this->load->helper('security');
		}
	
		$salt = $this->generate_salt();
		$pass = do_hash($salt . $old);
		
		return array($pass, $salt);
	}
	
	//--------------------------------------------------------------------
	
	private function generate_salt() 
	{
		if (!function_exists('random_string'))
		{
			$this->load->helper('string');
		}
		
		return random_string('alnum', 7);
	}
	
	//--------------------------------------------------------------------
	
	
	//--------------------------------------------------------------------
	// !HMVC METHOD HELPERS
	//--------------------------------------------------------------------
	
	/*
		Method: get_login_attempts()
		
		Returns the most recent login attempts and their description.
		
		Parameters:
			$limit	- An INT which is the number of results to return.
			
		Returns:
			An array of objects with the login information.
	*/	
	public function get_login_attempts($limit=15) 
	{
		$this->db->limit($limit);
		$this->db->order_by('login', 'desc');
		$query = $this->db->get('login_attempts');
		
		if ($query->num_rows())
		{
			return $query->result();
		}
		
		return FALSE;
	}
	
	//--------------------------------------------------------------------
	
	//--------------------------------------------------------------------
	// !META METHODS
	//--------------------------------------------------------------------
	
	/*
		Method: save_meta_for()
		
		Saves one or more key/value pairs of additional meta information
		for a user.
		
		Examples:
			$data = array(
				'location'	=> 'That City, Katmandu',
				'interests'	=> 'My interests'
			);
			$this->user_model->save_meta_for($user_id, $data);
		
		Parameters:
			$user_id	- The ID of the user to save the meta for.
			$data		- An array of key/value pairs to save.
	*/
	public function save_meta_for($user_id=null, $data=array()) 
	{
		if (!is_numeric($user_id))
		{
			$this->error = 'Invalid User ID';
		}
		
		$this->table	= 'user_meta';
		$this->key		= 'meta_id';
		
		foreach ($data as $key => $value)
		{ 
			$this->db->where('user_id', $user_id);
			$this->db->where('meta_key', $key);
			$query = $this->db->get('user_meta');
				
			$obj = array(
				'user_id'		=> $user_id,
				'meta_key'		=> $key,
				'meta_value'	=> $value
			);
			
			if ($query->num_rows() == 0)
			{ 
				// Insert
				$this->db->insert('user_meta', $obj);
			}
			// Update
			else if ($query->num_rows() > 0)
			{
				$row = $query->row();
				$meta_id = $row->meta_id;
				
				$this->db->where('user_id', $user_id);
				$this->db->where('meta_key', $key);
				$this->db->set('meta_value', $value);
				$this->db->update('user_meta', $obj);
			}
			
		}
		
		// Reset our table info
		$this->table	= 'users';
		$this->key		= 'id';
	}
	
	//--------------------------------------------------------------------
	
	/*
		Method: find_meta_for()
		
		Retrieves all meta values defined for a user.
		
		Parameters:
			$user_id	- An INT with the user's ID to find the meta for.
			$fields		- An array of meta_key names to retrieve.
			
		Returns:
			A stdObject with the key/value pairs, or NULL.
	*/
	public function find_meta_for($user_id=null, $fields=null) 
	{
		if (!is_numeric($user_id))
		{
			$this->error = 'Invalid User ID';
		}
		
		$this->table	= 'user_meta';
		$this->key		= 'meta_id';
		
		// Limiting to certain fields? 
		if (is_array($fields))
		{
			$this->db->where_in('meta_key', $fields);
		}
		
		$this->db->where('user_id', $user_id);
		$query = $this->db->get('user_meta');
	
		if ($query->num_rows())
		{
			$rows = $query->result();
			
			$result = null;
			foreach ($rows as $row)
			{
				$key = $row->meta_key;
				$result->$key = $row->meta_value;
			}
		}
		else
		{
			$result = null;
		}
	
		// Reset our table info
		$this->table	= 'users';
		$this->key		= 'id';
		
		return $result;
	}

	//--------------------------------------------------------------------

	/*
		Method: find_user_and_meta ( $user_id=null)

		Locates a single user and joins there meta information based on a the user id match.

		Parameters:
			$user_id - Integer of User ID to fetch

		Returns:
			An object with the user's info and meta information, or FALSE on failure.
	*/
	public function find_user_and_meta ( $user_id=null)
	{
		if (!is_numeric($user_id))
		{
			$this->error = 'Invalid User ID';
		}

		$result = $this->find( $user_id );

		$this->db->where('user_id', $user_id);
		$query = $this->db->get('user_meta');

		if ($query->num_rows())
		{
			$rows = $query->result();

			foreach ($rows as $row)
			{
				$key = $row->meta_key;
				$result->$key = $row->meta_value;
			}
		}

		$query->free_result();
		return $result;

	}
		
	//--------------------------------------------------------------------
	
	//--------------------------------------------------------------------
	// !ACTIVATION
	//--------------------------------------------------------------------
	/*
		Method: 
			Count Inactive users.
		
		Parameters:
			@return int Inactive user count.
	*/
	public function count_inactive_users() 
	{
        $this->db->where('active',-1);
        return $this->count_all(FALSE);
    }
    /*
		Method: 
			Activate
			
		Accepts an activation code and validates is against a matching entry int eh database.
		
		There are some instances where we want to remove the activation hash yet leave the user 
		inactive (Admin Activation scenario), so leave_inactive handles this use case.
		
		Parameters:
			$code - The activation code to be verified
			$leaveInactive - Flag whether to remove the activate hash value, but leave active = 0
		
		Returns:
			User Id on success, FALSE on error
	*/
	public function activate($code = FALSE, $leave_inactive = FALSE) 
	{
	    	
		if ($code === FALSE) 
		{
	        $this->error = lang('us_err_no_activate_code');
			return FALSE;
	    }
	    $query = $this->db->select('id')
               	      ->where('activate_hash', $code)
               	      ->limit(1)
               	      ->get($this->table);
               	      
		if ($query->num_rows() !== 1) 
		{
		    $this->error = lang('us_err_no_matching_code');
	        return FALSE;
		}
	    $result = $query->row();
		$active = ($leave_inactive === FALSE) ? 1 : 0;
		if ($this->update($result->id, array('activate_hash' => '','active' => $active)))
		{
			return $result->id;
		}
	}
	/*
		Method: 
			Deactivate.
		
		This function is triggered during account set up to assure user is not active and, 
		if not supressed, generate an activation hash code. This function can be used to 
		deactivate accounts based on public view events.
		
		Parameters:
			$user_id - The username or email to match to deactivate
		
		Returns:
			@return $activate_hash on success, FALSE on error
		
	*/
	public function deactivate($user_id = FALSE, $login_type = 'email', $make_hash = true) 
	{
	    if ($user_id === FALSE) 
		{
	        return FALSE;
	    }
		// create a temp activation code.
        $activate_hash = '';
		if ($make_hash === true) 
		{
			$this->load->helpers(array('string', 'security'));
			$activate_hash = do_hash(random_string('alnum', 40) . time());
		}
		$this->db->update($this->table, array('active'=>0,'activate_hash' => $activate_hash), array($login_type => $user_id));
		
		return ($this->db->affected_rows() == 1) ? $activate_hash : FALSE;
	}
	
	/*
		Method: 
			Admin Activation.
		
		Admin specific activation function for admin approvals or re-activation.
		
		Parameters:
			@param  $userId	Int 	The user ID to activate
		
		Returns:
			@return TRUE on success, FALSE on error
		
	*/
	public function admin_activation($user_id = FALSE) 
	{
		
		if ($user_id === FALSE) 
		{
			$this->error = lang('us_err_no_id');
	        return FALSE;
	    }
		$query = $this->db->select('id')
               	      ->where('id', $user_id)
               	      ->limit(1)
               	      ->get($this->table);
               	      
		if ($query->num_rows() !== 1)
		{
		    $this->error = lang('us_err_no_matching_id');
	        return FALSE;
		}
		$result = $query->row();
		$this->update($result->id, array('activate_hash' => '','active' => 1));
		if ($this->db->affected_rows() > 0) 
		{
			return $result->id;
		} 
		else 
		{
			$this->error = lang('us_err_user_is_active');
			return FALSE;
		}
	}
	/*
		Method: 
			Admin Deactivation
		
		Admin only deactivation function.
		
		Parameters:
			$userId	Int 	The user ID to deactivate
		
		@Returns:
			TRUE on success, FALSE on error
	
	*/
	public function admin_deactivation($user_id = FALSE) 
	{
		if ($user_id === FALSE) 
		{
			$this->error = lang('us_err_no_id');
	        return FALSE;
	    }
		if ($this->deactivate($user_id, 'id', FALSE))
		{
			return $user_id;
		}
		else
		{
			$this->error = lang('us_err_user_is_inactive');
			return FALSE;
		}
	}

	//--------------------------------------------------------------------
	
}

// End User_model class