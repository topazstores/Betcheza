<?php defined('BASEPATH') || exit('No direct script access allowed');

/**
 * Users Admin Controller
 *
 * Provides front-end functions for users, including access to login and logout.
 *
 * @package		Users
 * @subpackage	Users
 * @author		codauris
 * @link		http://codauris.tk
 */
 
class Users extends Front_Controller
{
    /** @var array Site's settings to be passed to the view. */
    private $siteSettings;

    /**
     * Setup the required libraries etc.
     *
     * @retun void
     */
    public function __construct()
    {
        parent::__construct();

        $this->load->helper('form');
        $this->load->library('form_validation');

        $this->load->model('users/user_model');
		$this->load->model('tips/tips_model');
		$this->load->model('countries/countries_model');

        $this->load->library('users/auth');
		
		$language = $this->input->cookie('language');
		$this->lang->load('users',$language);
		$this->lang->load('tips/tips',$language);
		
    }

    // -------------------------------------------------------------------------
    // Authentication (Login/Logout)
    // -------------------------------------------------------------------------

    /**
     * Present the login view and allow the user to login.
     *
     * @return void
     */
    public function login()
    {
        // If the user is already logged in, go home.
        if ($this->auth->is_logged_in() !== false) {
            Template::redirect('/');
        }

        // Try to login.
        if (isset($_POST['log-me-in'])
            && true === $this->auth->login(
                $this->input->post('username'),
                $this->input->post('password'),
                $this->input->post('remember_me') == '1'
            )
        ) {
            log_activity(
                $this->auth->user_id(),
                lang('us_log_logged') . ': ' . $this->input->ip_address(),
                'users'
            );

            // Now redirect. (If this ever changes to render something, note that
            // auth->login() currently doesn't attempt to fix `$this->current_user`
            // for the current page load).

            // If the site is configured to use role-based login destinations and
            // the login destination has been set...
            if ($this->settings_lib->item('auth.do_login_redirect')
                && ! empty($this->auth->login_destination)
            ) {
                Template::redirect($this->auth->login_destination);
            }

            // If possible, send the user to the requested page.
            if (! empty($this->requested_page)) {
                Template::redirect($this->requested_page);
            }

            // If there is nowhere else to go, go home.
            Template::redirect('/');
        }
		
		Assets::add_module_js('users', 'users.js');
		Assets::add_js('plugins/validate/jquery.validate.min.js');
		Assets::add_js('plugins/validate/additional-methods.min.js');
        // Prompt the user to login.
        Template::set('page_title', lang('us_login'));
        Template::render('login');
    }

    /**
     * Log out, destroy the session, and cleanup, then redirect to the home page.
     *
     * @return void
     */
    public function logout()
    {
        if (isset($this->current_user->id)) {
            // Login session is valid. Log the Activity.
            log_activity(
                $this->current_user->id,
                lang('us_log_logged_out') . ': ' . $this->input->ip_address(),
                'users'
            );
        }

        // Always clear browser data (don't silently ignore user requests).
        $this->auth->logout();
        Template::redirect('/');
    }

    // -------------------------------------------------------------------------
    // User Management (Register/Update Profile)
    // -------------------------------------------------------------------------

    /**
     * Allow a user to edit their own profile information.
     *
     * @return void
     */
       public function edit_profile()
    {
        // Make sure the user is logged in.
        $this->auth->restrict();
        $this->set_current_user();

		$this->load->model('tipsters/tipsters_model');
		$this->load->model('countries/countries_model');

        if (isset($_POST['save'])) {
		
			//Only for Demo mode disabled function
			if(constant("ENVIRONMENT")=='demo')
			{
				Template::set_message(lang('bf_demo_mode'), 'info');
			}
			else 
			{			
				$user_id = $this->current_user->id;
				if ($this->saveUser('update', $user_id)) {


					Template::set_message(lang('us_profile_updated_success'), 'success');

					// Redirect to make sure any language changes are picked up.
					Template::redirect('/tipsters/edit_profile');
				}

				Template::set_message(lang('us_profile_updated_error'), 'error');
			}	
        }

        // Get the current user information.
        $user = $this->user_model->find($this->current_user->id);

        // Generate password hint messages.
        $this->user_model->password_hints();

        Template::set('user', $user);
		Template::set('countries', $this->countries_model->get_countries_select());
        Template::set_block('sidebar_left','tipsters/sidebar_left');
       
        Template::set_view('edit_profile');
         Template::render('two_col_left3');
    }

	
    /**
     * Display the registration form for the user and manage the registration process.
     *
     * The redirect URLs for success (Login) and failure (register) can be overridden
     * by including a hidden field in the form for each, named 'register_url' and
     * 'login_url' respectively.
     *
     * @return void
     */
    public function register()
    {
        // Are users allowed to register?
        if (! $this->settings_lib->item('auth.allow_register')) {
            Template::set_message(lang('us_register_disabled'), 'error');
            Template::redirect('/');
        }

        $register_url = $this->input->post('register_url') ?: REGISTER_URL;
        $login_url    = $this->input->post('login_url') ?: LOGIN_URL;

        $this->load->model('roles/role_model');
        $this->load->helper('date');


        if (isset($_POST['register'])) {
            if ($userId = $this->saveUser('insert', 0)) {
                // User Activation
                $activation = $this->user_model->set_activation($userId);
                $message = $activation['message'];
                $error   = $activation['error'];

                Template::set_message($message, $error ? 'error' : 'success');

                log_activity($userId, lang('us_log_register'), 'users');
                Template::redirect($login_url);

            }

            Template::set_message(lang('us_registration_fail'), 'error');
            // Don't redirect because validation errors will be lost.
        }
		
		Assets::add_module_js('users', 'users.js');
		Assets::add_js('plugins/validate/jquery.validate.min.js');
		Assets::add_js('plugins/validate/additional-methods.min.js');
		
        // Generate password hint messages.
        $this->user_model->password_hints();
		Template::set('countries', $this->countries_model->get_countries_select());
        Template::set_view('users/register');
        Template::set('page_title', 'Register');
        Template::render('register');
    }

    // -------------------------------------------------------------------------
    // Password Management
    // -------------------------------------------------------------------------

    /**
     * Allow a user to request the reset of a forgotten password. An email is sent
     * with a special temporary link that is only valid for 24 hours. This link
     * takes the user to reset_password().
     *
     * @return void
     */
    public function forgot_password()
    {
        // If the user is logged in, go home.
        if ($this->auth->is_logged_in() !== false) {
            Template::redirect('/');
        }

        if (isset($_POST['send'])) {
            // Validate the form to ensure a valid email was entered.
            $this->form_validation->set_rules('email', 'lang:bf_email', 'required|trim|valid_email');
            if ($this->form_validation->run() !== false) {
                // Validation passed. Does the user actually exist?
                $user = $this->user_model->find_by('email', $this->input->post('email'));
                if ($user === false) {
                    // No user found with the entered email address.
                    Template::set_message(lang('us_invalid_email'), 'error');
                } else {
                    // User exists, create a hash to confirm the reset request.
                    $this->load->helper('string');
                    $hash = sha1(random_string('alnum', 40) . $this->input->post('email'));

                    // Save the hash to the db for later retrieval.
                    $this->user_model->update_where(
                        'email',
                        $this->input->post('email'),
                        array('reset_hash' => $hash, 'reset_by' => strtotime("+24 hours"))
                    );

                    // Create the link to reset the password.
                    $pass_link = site_url('reset_password/' . str_replace('@', ':', $this->input->post('email')) . "/{$hash}");

                    // Now send the email
                    $this->load->library('emailer/emailer');
                    $data = array(
                        'to'      => $this->input->post('email'),
                        'subject' => lang('us_reset_pass_subject'),
                        'message' => $this->load->view(
                            '_emails/forgot_password',
                            array('link' => $pass_link),
                            true
                        ),
                     );

                    if ($this->emailer->send($data)) {
                        Template::set_message(lang('us_reset_pass_message'), 'success');
						Template::redirect(LOGIN_URL);
                    } else {
                        Template::set_message(lang('us_reset_pass_error') . $this->emailer->error, 'error');
                    }
                }
            }
        }

        Template::set_view('users/forgot_password');
        Template::set('page_title', 'Password Reset');
        Template::render('forgot_password');
    }

    /**
     * Allows the user to create a new password for their account. At the moment,
     * the only way to get here is to go through the forgot_password() process,
     * which creates a unique code that is only valid for 24 hours.
     *
     * Since 0.7 this method is also reached via the force_password_reset security
     * features.
     *
     * @param string $email The email address to check against.
     * @param string $code  A randomly generated alphanumeric code. (Generated by
     * forgot_password()).
     *
     * @return void
     */
    public function reset_password($email = '', $code = '')
    {
        // If the user is logged in, go home.
        if ($this->auth->is_logged_in() !== false) {
            Template::redirect('/');
        }

        // Bonfire may have stored the email and code in the session.
        if (empty($code) && $this->session->userdata('pass_check')) {
            $code = $this->session->userdata('pass_check');
        }

        if (empty($email) && $this->session->userdata('email')) {
            $email = $this->session->userdata('email');
        }

        // If there is no code/email, then it's not a valid request.
        if (empty($code) || empty($email)) {
            Template::set_message(lang('us_reset_invalid_email'), 'error');
            Template::redirect(LOGIN_URL);
        }

            // Handle the form
        if (isset($_POST['set_password'])) {
                $this->form_validation->set_rules('password', 'lang:bf_password', 'required|max_length[120]|valid_password');
                $this->form_validation->set_rules('pass_confirm', 'lang:bf_password_confirm', 'required|matches[password]');

            if ($this->form_validation->run() !== false) {
                // The user model will create the password hash.
                $data = array(
                    'password'   => $this->input->post('password'),
                                  'reset_by'    => 0,
                                  'reset_hash'  => '',
                    'force_password_reset' => 0,
                );

                if ($this->user_model->update($this->input->post('user_id'), $data)) {
                    log_activity($this->input->post('user_id'), lang('us_log_reset'), 'users');

                    Template::set_message(lang('us_reset_password_success'), 'success');
                    Template::redirect(LOGIN_URL);
                }

                if (! empty($this->user_model->error)) {
                    Template::set_message(sprintf(lang('us_reset_password_error'), $this->user_model->error), 'error');
                }
            }
        }

        // Check the code against the database
        $email = str_replace(':', '@', $email);
        $user = $this->user_model->find_by(
            array(
                'email'       => $email,
                'reset_hash'  => $code,
                'reset_by >=' => time(),
            )
        );

        // $user will be an Object if a single result was returned.
        if (! is_object($user)) {
            Template::set_message(lang('us_reset_invalid_email'), 'error');
            Template::redirect(LOGIN_URL);
        }

        // At this point, it is a valid request....
        Template::set('user', $user);

        Template::set_view('users/reset_password');
        Template::render('reset_password');
    }

    //--------------------------------------------------------------------------
    // ACTIVATION METHODS
    //--------------------------------------------------------------------------

    /**
     * Activate user.
     *
     * Checks a passed activation code and, if verified, enables the user account.
     * If the code fails, an error is generated.
     *
     * @param  integer $user_id The user's ID.
     *
     * @return void
     */
    public function activate($user_id = null)
    {
        if (isset($_POST['activate'])) {
            $this->form_validation->set_rules('code', 'Verification Code', 'required|trim');
            if ($this->form_validation->run()) {
                $code = $this->input->post('code');
                $activated = $this->user_model->activate($user_id, $code);
                if ($activated) {
                    $user_id = $activated;

                    // Now send the email.
                    $this->load->library('emailer/emailer');
                    $email_message_data = array(
                        'title' => $this->settings_lib->item('site.title'),
                        'link'  => site_url(LOGIN_URL),
                    );
                    $data = array(
                        'to'      => $this->user_model->find($user_id)->email,
                        'subject' => lang('us_account_active'),
                        'message' => $this->load->view('_emails/activated', $email_message_data, true),
                    );

                    if ($this->emailer->send($data)) {
                        Template::set_message(lang('us_account_active'), 'success');
						Template::redirect('login');
                    } else {
                        Template::set_message(lang('us_err_no_email'). $this->emailer->error, 'error');
                    }

                    Template::redirect('activate');
                }

                if (! empty($this->user_model->error)) {
                    Template::set_message($this->user_model->error . '. ' . lang('us_err_activate_code'), 'error');
                }
            }
        }

        Template::set_view('users/activate');
        Template::set('page_title', 'Account Activation');
        Template::render('activate');
    }

    /**
     * Allow a user to request another activation code. If the email address matches
     * an existing account, the code is resent.
     *
     * @return void
     */
    public function resend_activation()
    {
        if (isset($_POST['send'])) {
            $this->form_validation->set_rules('email', 'lang:bf_email', 'required|trim|valid_email');

            if ($this->form_validation->run()) {
                // Form validated. Does the user actually exist?
                $user = $this->user_model->find_by('email', $_POST['email']);
                if ($user === false) {
                    Template::set_message('Cannot find that email in our records.', 'error');
                } else {
                    $activation = $this->user_model->set_activation($user->id);
                    $message = $activation['message'];
                    $error = $activation['error'];

                    Template::set_message($message, $error ? 'error' : 'success');
					Template::redirect('activate');
                }
            }
			
        }

        Template::set_view('users/resend_activation');
        Template::set('page_title', 'Activate Account');
        Template::render('resend_activation');
    }

    // -------------------------------------------------------------------------
    // Private Methods
    // -------------------------------------------------------------------------

    /**
     * Save the user.
     *
     * @param  string  $type            The type of operation ('insert' or 'update').
     * @param  integer $id              The id of the user (ignored on insert).
     * @param  array   $metaFields      Array of meta fields for the user.
     *
     * @return boolean/integer The id of the inserted user or true on successful
     * update. False if the insert/update failed.
     */
    private function saveUser($type = 'insert', $id = 0)
    {
        $extraUniqueRule = '';

        if ($type != 'insert') {
            if ($id == 0) {
                $id = $this->current_user->id;
            }
            $_POST['id'] = $id;

            // Security check to ensure the posted id is the current user's id.
            if ($_POST['id'] != $this->current_user->id) {
                $this->form_validation->set_message('email', 'lang:us_invalid_userid');
                return false;
            }

            $extraUniqueRule = ',users.id';
        }

        $this->form_validation->set_rules($this->user_model->get_validation_rules($type));

        $usernameRequired = '';
        if ($this->settings_lib->item('auth.login_type') == 'username'
            || $this->settings_lib->item('auth.use_usernames')
        ) {
            $usernameRequired = 'required|';
        }

        $this->form_validation->set_rules('username', 'lang:bf_username', "{$usernameRequired}trim|max_length[30]|unique[users.username{$extraUniqueRule}]");
        $this->form_validation->set_rules('email', 'lang:bf_email', "required|trim|valid_email|max_length[254]|unique[users.email{$extraUniqueRule}]");

        // If a value has been entered for the password, pass_confirm is required.
        // Otherwise, the pass_confirm field could be left blank and the form validation
        // would still pass.
        if ($type != 'insert' && $this->input->post('password')) {
            $this->form_validation->set_rules('pass_confirm', 'lang:bf_password_confirm', "required|matches[password]");
        }

        $userIsAdmin = isset($this->current_user) && $this->current_user->role_id == 1;


        // Setting the payload for Events system.
        $payload = array('user_id' => $id, 'data' => $this->input->post());

        // Event "before_user_validation" to run before the form validation.
        Events::trigger('before_user_validation', $payload);

        if ($this->form_validation->run() === false) {
            return false;
        }



        $result = false;
		
		
		 // Compile our core user elements to save.
		 $data = $this->user_model->prep_data($this->input->post());

        if ($type == 'insert') {

            $activationMethod = $this->settings_lib->item('auth.user_activation_method');
            if ($activationMethod == 0) {
                // No activation method, so automatically activate the user.
                $data['active'] = 1;
            }

            $id = $this->user_model->insert($data);
            if (is_numeric($id)) {
                $result = $id;
            }
        } else {
		
				if($_FILES['avatar']) 
				{ 
					$avatar = $this->save_avatar(); 
					$data['avatar'] = !empty($avatar['upload_data']['file_name'])?$avatar['upload_data']['file_name']:$this->input->post('current_avatar'); 
				} 
				else 
				{ 
					$data['avatar'] = $this->input->post('current_avatar'); 
				}
			
            $result = $this->user_model->update($id, $data);
        }

        // Trigger event after saving the user.
        Events::trigger('save_user', $payload);

        return $result;
    }

	
	private function save_avatar()
	{                

		 $config['upload_path'] = './uploads/tipsters/'; //Make SURE that you chmod this directory to 777!
		 $config['allowed_types'] = 'gif|jpg|png';
		 $config['max_size']    = '0'; // 0 = no limit on file size (this also depends on your PHP configuration)
		 $config['remove_spaces']=TRUE; //Remove spaces from the file name
 
		 $this->load->library('upload', $config);
 
		if ( ! $this->upload->do_upload('avatar'))
		{
				$data['error']= array('error' => $this->upload->display_errors());
				log_message('error',$data['error']);
				}
				else
					{
					$data = array('upload_data' => $this->upload->data());
 
					}
			return $data;
	}	

	
}
/* End of file /bonfire/modules/users/controllers/users.php */
