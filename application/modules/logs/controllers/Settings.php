<?php defined('BASEPATH') || exit('No direct script access allowed');

/**
 * Logs Admin Controller
 *
 * View the logs that have been generated by the system
 *
 * @package		Logs
 * @subpackage	Logs
 * @author		codauris
 * @link		http://codauris.tk
 */
 
class Settings extends Admin_Controller
{
    private $permissionContext = 'Settings.View';
    private $permissionManage = 'Logs.Manage';
    private $permissionView   = 'Logs.View';

    /**
     * Setup the permissions and load the language file.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

        $this->auth->restrict($this->permissionContext);
        $this->auth->restrict($this->permissionView);

        $this->lang->load('logs');

        Assets::add_module_js('logs', 'logs');
        Assets::add_module_css('logs', 'logs');

        // Logging enabled?
        Template::set('log_threshold', $this->config->item('log_threshold'));
        Template::set('toolbar_title', lang('logs_title'));

    }

    /**
     * List all log files and allow the user to change the log threshold.
     *
     * @return void
     */
    public function index()
    {
        // Load the file helper for use in delete_all and loading the filenames.
        $this->load->helper('file');
        $logPath = $this->config->item('log_path');

        if (isset($_POST['delete'])) {
		
			//Only for Demo mode disabled function
			if(constant("ENVIRONMENT")=='demo')
			{
				Template::set_message(lang('bf_demo_mode'), 'info');
			}
			else 
			{			
				$this->auth->restrict($this->permissionManage);

				$checked = $this->input->post('checked');
				$numChecked = count($checked);
				if (is_array($checked) && $numChecked) {
					$activity_text = lang('logs_act_deleted');
					foreach ($checked as $file) {
						@unlink($this->config->item('log_path') . $file);
						log_activity(
							$this->auth->user_id(),
							sprintf($activity_text, date('F j, Y', strtotime(str_replace('.php', '', str_replace('log-', '', $file)))), $this->input->ip_address()),
							'logs'
						);
					}
					Template::set_message(sprintf(lang('logs_deleted'), $numChecked), 'success');
				}
			}	
        } elseif (isset($_POST['delete_all'])) {
		
			//Only for Demo mode disabled function
			if(constant("ENVIRONMENT")=='demo')
			{
				Template::set_message(lang('bf_demo_mode'), 'info');
			}
			else 
			{			
				$this->auth->restrict($this->permissionManage);

				delete_files($logPath);

				// Restore the index.html file.
				@copy(APPPATH . '/index.html', "{$logPath}/index.html");

				log_activity(
					$this->auth->user_id(),
					sprintf(lang('logs_act_deleted_all'), $this->input->ip_address()),
					'logs'
				);
				Template::set_message(lang('logs_deleted_all_success'), 'success');
			}	
        }

        // Load the Log Files.
        $logs = get_filenames($logPath);
        arsort($logs);

        // Configure pagination.
        $this->load->library('pagination');
        $uriSegment = 5;
        $limit = 10;
        $offset = $this->uri->segment($uriSegment) ?: 0;

        $this->pager['base_url']    = site_url(SITE_AREA . '/settings/logs/index');
        $this->pager['per_page']    = $limit;
        $this->pager['total_rows']  = count($logs);
        $this->pager['uri_segment'] = $uriSegment;

        $this->pagination->initialize($this->pager);

        Template::set('logs', array_slice($logs, $offset, $limit));
        Template::render();
    }

    /**
     * Display the page which lets the user choose the logging threshold.
     *
     * @return void
     */
    public function settings()
    {
        $this->auth->restrict($this->permissionManage);

        Template::set('toolbar_title', lang('logs_title_settings'));
        Template::render();
    }

    /**
     * Save the logging threshold value.
     *
     * @return void
     */
    public function enable()
    {
        $this->auth->restrict($this->permissionManage);

        if (isset($_POST['save'])) {
		
			//Only for Demo mode disabled function
			if(constant("ENVIRONMENT")=='demo')
			{
				Template::set_message(lang('bf_demo_mode'), 'info');
			}
			else 
			{			
				$this->load->helper('config_file');

				if (write_config('config', array('log_threshold' => $_POST['log_threshold']))) {
					log_activity(
						(int) $this->auth->user_id(),
						sprintf(lang('logs_act_settings_modified'), $this->input->ip_address()),
						'logs'
					);
					Template::set_message(lang('logs_settings_modified_success'), 'success');
				} else {
					Template::set_message(lang('logs_settings_modified_failure'), 'error');
				}
			}	
        }

        redirect(SITE_AREA . '/settings/logs');
    }

    /**
     * Show the contents of a single log file.
     *
     * @param string $file The full name of the file to view (including extension).
     *
     * @return void
     */
    public function view($file = '')
    {
        if (empty($file)) {
            Template::set_message(lang('logs_view_empty'), 'error');
            redirect(SITE_AREA . '/settings/logs');
        }

        $path = $this->config->item('log_path') . $file;
        if (file_exists($path)) {
            Template::set('log_content', file($path));
        }

        Template::set('canDelete', $this->auth->has_permission($this->permissionManage));
        Template::set('log_file', $file);
        Template::set('log_file_pretty', date('F j, Y', strtotime(str_replace('.php', '', str_replace('log-', '', $file)))));
        Template::render();
    }
}
