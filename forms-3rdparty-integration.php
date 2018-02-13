<?php
/*
Plugin Name: Forms: 3rd-Party Integration Bpmonline
Description: Send plugin Forms Submissions (Gravity, CF7) to Bpmonline
Author: Eugene Podkovka
Version: 1.0.6
*/

//declare to instantiate
Forms3rdPartyIntegrationBpmonline::$instance = new Forms3rdPartyIntegrationBpmonline;

// handle plugin upgrades
// http://codex.wordpress.org/Function_Reference/register_activation_hook#Examples
/*include_once dirname( __FILE__ ) . '/upgrade.php';
$ugrader = new Forms3rdPartyIntegrationBpmonlineUpgrade();
$ugrader->register(__FILE__);*/

class Forms3rdPartyIntegrationBpmonline {

	#region =============== CONSTANTS AND VARIABLE NAMES ===============

	const pluginPageTitle = 'Forms: Bpmonline Integration';

	const pluginPageShortTitle = 'Bpmonline integration setup';

	/**
	 * Admin - role capability to view the options page
	 * @var string
	 */
	const adminOptionsCapability = 'manage_options';

	/**
	 * Version of current plugin -- match it to the comment
	 * @var string
	 */
	const pluginVersion = '1.0.6';


	/**
	 * Self-reference to plugin name
	 * @var string
	 */
	private $N;

	/**
	 * Namespace the given key
	 * @param string $key the key to namespace
	 * @return the namespaced key
	 */
	public function N($key = false) {
		// nothing provided, return namespace
		if( ! $key || empty($key) ) { return $this->N; }
		return sprintf('%s_%s', $this->N, $key);
	}

	/**
	 * Parameter index for mapping - administrative label (reminder)
	 */
	const PARAM_LBL = 'lbl';
	/**
	 * Parameter index for mapping - source plugin (i.e. GravityForms, CF7, etc)
	 */
	const PARAM_SRC = 'src';
	/**
	 * Parameter index for mapping - 3rdparty destination
	 */
	const PARAM_3RD = '3rd';

	/**
	 * How long (seconds) before considering timeout
	 */
	const DEFAULT_TIMEOUT = 10;

	/**
	 * Singleton
	 * @var object
	 */
	public static $instance = null;

	#endregion =============== CONSTANTS AND VARIABLE NAMES ===============


	#region =============== CONSTRUCTOR and INIT (admin, regular) ===============

	// php5 constructor must come first for 'strict standards' -- http://wordpress.org/support/topic/redefining-already-defined-constructor-for-class-wp_widget

	function __construct() {
		$this->N = __CLASS__;

		add_action( 'admin_menu', array( &$this, 'admin_init' ), 20 ); // late, so it'll attach menus farther down
		add_action( 'init', array( &$this, 'init' ) ); // want to run late, but can't because it misses CF7 onsend?
	} // function

	function Forms3rdPartyIntegrationBpmonline() {
		$this->__construct();
	} // function

	function admin_init() {
		# perform your code here
		//add_action('admin_menu', array(&$this, 'config_page'));

		//add plugin entry settings link
		add_filter( 'plugin_action_links', array(&$this, 'plugin_action_links'), 10, 2 );

		//needs a registered page in order for the above link to work?
		#$pageName = add_options_page("Custom Shortcodes - ABT Options", "Shortcodes -ABT", self::adminOptionsCapability, 'abt-shortcodes-config', array(&$this, 'submenu_config'));
		if ( function_exists('add_submenu_page') ){

			// autoattach to cf7, gravityforms
			$subpagesOf = apply_filters($this->N('declare_subpages'), array());
			foreach($subpagesOf as $plugin) {
				$page = add_submenu_page(/*'plugins.php'*/$plugin, __(self::pluginPageTitle), __(self::pluginPageShortTitle), self::adminOptionsCapability, basename(__FILE__,'.php').'-config', array(&$this, 'submenu_config'));
				//add admin stylesheet, etc
				add_action('admin_print_styles-' . $page, array(&$this, 'add_admin_headers'));
			}


			//register options
			$default_options = array(
				'debug' => array('email'=>get_bloginfo('admin_email'), 'separator'=>', ', 'mode' => array(), 'sender' => '')
				, 0 => array(
					'name'=>'Service 1'
					, 'url'=>plugins_url('3rd-parties/service_test.php', __FILE__)
					, 'success'=>''
					, 'failure'=>''
					, 'forms' => array()
					, 'timeout' => self::DEFAULT_TIMEOUT // timeout in seconds
					, 'mapping' => array(
						array(self::PARAM_LBL=>'The submitter name',self::PARAM_SRC=>'your-name', self::PARAM_3RD=>'name')
						, array(self::PARAM_LBL=>'The email address', self::PARAM_SRC=>'your-email', self::PARAM_3RD=>'email')
					)
				)
			);
			add_option( $this->N('settings'), $default_options );
		}

	} // function


	/**
	 * General init
	 * Add scripts and styles
	 * but save the enqueue for when the shortcode actually called?
	 */
	function init(){
		// needed here because both admin and before-send functions require v()
		/// TODO: more intelligently include...
		include_once('includes.php');

		#wp_register_script('jquery-flip', plugins_url('jquery.flip.min.js', __FILE__), array('jquery'), self::pluginVersion, true);
		#wp_register_style('sponsor-flip', plugins_url('styles.css', __FILE__), array(), self::pluginVersion, 'all');
		#
		#if( !is_admin() ){
		#	/*
		#	add_action('wp_print_header_scripts', array(&$this, 'add_headers'), 1);
		#	add_action('wp_print_footer_scripts', array(&$this, 'add_footers'), 1);
		#	*/
		#	wp_enqueue_script('jquery-flip');
		#	wp_enqueue_script('sponsor-flip-init');
		#	wp_enqueue_style('sponsor-flip');
		#}


		// allow extensions; remember to check !is_admin
		do_action($this->N('init'), false);

		if(!is_admin()){
			add_action('wp_enqueue_scripts', array(&$this, 'addBpmCookiesGenerator'));
			add_action('wp_footer', array(&$this, 'addBpmonlineMappingScript'));
		}

	}

	#endregion =============== CONSTRUCTOR and INIT (admin, regular) ===============

	#region =============== HEADER/FOOTER -- scripts and styles ===============

	function addBpmCookiesGenerator(){
		wp_enqueue_script( 'track-cookies-bpmonline', 'https://webtracking-v01.bpmonline.com/JS/track-cookies.js');
	}

	function addBpmonlineMappingScript() {
		$services = $this->get_services();
		?>
        <script type="text/javascript">
            var mapping = [];
            <?php
            foreach($_REQUEST as $fieldName => $fieldValue){
                foreach ($services as $serviceValue){
                    if(isset($serviceValue['mapping'])){
                        foreach ($serviceValue['mapping'] as $mapValues){
                            if($fieldName == $mapValues[self::PARAM_3RD]){
                                ?>
                                mapping.push(
                                    {
                                        key: '<?php echo $mapValues[self::PARAM_SRC]?>',
                                        value: '<?php echo $fieldValue?>'
                                    }
                                );
                                <?php
                            }
                        }
                    }
                }
            }
            ?>
            for(var index in mapping){
                $( 'input[Name='+mapping[index].key+']' ).val(mapping[index].value);
            }
        </script>
		<?php
	}

	/**
	 * Add admin header stuff
	 * @see http://codex.wordpress.org/Function_Reference/wp_enqueue_script#Load_scripts_only_on_plugin_pages
	 */
	function add_admin_headers(){

		wp_enqueue_script($this->N('admin'), plugins_url('plugin.admin.js', __FILE__), array('jquery', 'jquery-ui-sortable'), self::pluginVersion, true);
		wp_localize_script($this->N('admin'), $this->N('admin'), array(
			'N' => $this->N()
		));

		$stylesToAdd = array(
			basename(__FILE__,'.php') => 'plugin.admin.css'	//add a stylesheet with the key matching the filename
		);

		// Have to manually add to in_footer
		// Check if script is done, if not, then add to footer
		foreach($stylesToAdd as $handle => $stylesheet){
			wp_enqueue_style(
				$handle									//id
				, plugins_url($stylesheet, __FILE__)	//file
				, array()								//dependencies
				, self::pluginVersion					//version
				, 'all'									//media
			);
		}
	}//---	function add_admin_headers

	/**
	 * Only add scripts and stuff if shortcode found on page
	 * TODO: figure out how this works -- global $wpdb not correct
	 * @source http://shibashake.com/wordpress-theme/wp_enqueue_script-after-wp_head
	 * @source http://old.nabble.com/wp-_-enqueue-_-script%28%29-not-working-while-in-the-Loop-td26818198.html
	 */
	function add_headers() {
		//ignore the examples below
		return;

		if(is_admin()) return;

		$stylesToAdd = array();

		// Have to manually add to in_footer
		// Check if script is done, if not, then add to footer
		foreach($stylesToAdd as $style){
			if (!in_array($style, $wp_styles->done) && !in_array($style, $wp_styles->in_footer)) {
				$wp_styles->in_header[] = $style;
			}
		}
	}//--	function add_headers

	/**
	 * Only add scripts and stuff if shortcode found on page
	 * @see http://scribu.net/wordpress/optimal-script-loading.html
	 */
	function add_footers() {
		if(is_admin()){
			wp_enqueue_script($this->N('admin'));
			return;
		}

		$scriptsToAdd = array( );

		// Have to manually add to in_footer
		// Check if script is done, if not, then add to footer
		foreach($scriptsToAdd as $script){
			if (!in_array($script, $wp_scripts->done) && !in_array($script, $wp_scripts->in_footer)) {
				$wp_scripts->in_footer[] = $script;
			}
		}
	}

	#endregion =============== HEADER/FOOTER -- scripts and styles ===============

	#region =============== Administrative Settings ========

	private $_settings;
	private $_services;
	/**
	 * Return the plugin settings
	 */
	function get_settings($stashed = true){
		// TODO: if this ever changes, make sure to correspondingly fix 'upgrade.php'

		if( $stashed && isset($this->_settings) ) return $this->_settings;

		$this->_settings = get_option($this->N('settings'));
		// but we only want the actual settings, not the services
		$this->_settings = $this->_settings['debug'];

		return $this->_settings;
	}//---	get_settings
	/**
	 * Return the service configurations
	 */
	function get_services($stashed = true) {
		if( $stashed && isset($this->_services) ) return $this->_services;

		$this->_services = get_option($this->N('settings'));
		// but we only want service listing, not the settings
		// TODO: this will go away once we move to custom post type like CF7
		unset($this->_services['debug']);

		return $this->_services;
	}
	function save_services($services) {
		$settings = $this->get_settings(false);
		$merged = array('debug' => $settings) + (array)$services;
		update_option($this->N('settings'), $merged);
		$this->_services = $services; // replace stash
	}
	function save_settings($settings) {
		$services = $this->get_services(false);
		$merged = array('debug' => $settings) + (array)$services;
		update_option($this->N('settings'), $merged);
		$this->_settings = $settings; // replace stash
	}

	/**
	 * The submenu page
	 */
	function submenu_config(){
		wp_enqueue_script($this->N('admin'));
		include_once('plugin-ui.php');
	}

	/**
	 * HOOK - Add the "Settings" link to the plugin list entry
	 * @param $links
	 * @param $file
	 */
	function plugin_action_links( $links, $file ) {
		if ( $file != plugin_basename( __FILE__ ) )
			return $links;

		$url = $this->plugin_admin_url( array( 'page' => basename(__FILE__, '.php').'-config' ) );

		$settings_link = '<a title="Capability ' . self::adminOptionsCapability . ' required" href="' . esc_attr( $url ) . '">'
			. esc_html( __( 'Settings', $this->N ) ) . '</a>';

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Copied from Contact Form 7, for adding the plugin link
	 * @param unknown_type $query
	 */
	function plugin_admin_url( $query = array() ) {
		global $plugin_page;

		if ( ! isset( $query['page'] ) )
			$query['page'] = $plugin_page;

		$path = 'admin.php';

		if ( $query = build_query( $query ) )
			$path .= '?' . $query;

		$url = admin_url( $path );

		return esc_url_raw( $url );
	}

	/**
	 * Helper to render a select list of available forms
	 * @param array $forms list of  forms from functions like wpcf7_contact_forms()
	 * @param array $eid entry id - for multiple lists on page
	 * @param array $selected ids of selected fields
	 * @param string $field optionally specify the field name
	 */
	public function form_select_input($forms, $eid, $selected, $field = 'forms'){
		?>
		<select class="multiple" multiple="multiple" id="<?php echo $field, '-', $eid?>" name="<?php echo "{$this->N}[$eid][$field][]"?>">
			<?php
			foreach($forms as $f){
				$form_id = $f['id'];

				$form_title = $f['title'];	// as serialized option data

				?>
				<option <?php if($selected && in_array($form_id, $selected)): ?>selected="selected" <?php endif; ?>value="<?php echo esc_attr( $form_id );?>"><?php echo esc_html( $form_title ); ?></option>
				<?php
			}//	foreach
			?>
		</select>
		<?php
	}//--	end function form_select_input

	/**
	 * Helper to render the `value=$expected checked=?` part of an $input (checkbox, radio, option)
	 */
	public function selected_input($current, $expected, $type) {
		if(in_array($expected, $current)) return " value='$expected' $type='$type'";
		return " value='$expected'";
	}
	/**
	 * Helper to render the `value=$expected checked=?` part of an $input (checkbox, radio, option)
	 */
	public function selected_input_array($array, $currentKey, $expected, $type) {
		return isset($array[$currentKey])
			? $this->selected_input($array[$currentKey], $expected, $type)
			: $this->selected_input(array(), $expected, $type);
	}
	#endregion =============== Administrative Settings ========


	/**
	 * Prepare the service post with numerical placeholder, see github issue #43
	 * @param $post the service submission
	 *
	 * @see https://github.com/zaus/forms-3rdparty-integration/issues/43
	 */
	function placeholder_separator($post) {
		$new = array(); // add results to new so we don't pollute the enumerator
		// find the arrays and reformat keys with index

		###_log(__FUNCTION__ . '@' . __LINE__, $post);

		foreach($post as $f => $v) {
			// do we have a placeholder to fix for an array (iss #43)
			if(is_array($v) && false !== strpos($f, '%i')) {
				// for each item in the submission array,
				// get its numerical index and replace the
				// placeholder in the destination field

				foreach($v as $i => $p) {
					$k = str_replace('%i', $i, $f);
					$new[$k] = $p;
				}

				unset($post[$f]); // now remove original, since we need to reattach under a different key
			}
		}

		###_log(__FUNCTION__  . '@' . __LINE__, $new);

		return array_merge($post, $new);
	}

	/**
	 * Callback to perform before Form (i.e. Contact-Form-7, Gravity Forms) fires
	 * @param $form the plugin form
	 * @param $submission alias to submission data - in GF it's $_POST, in CF7 it's $cf7->posted_data; initially a flag (if not provided) to only formulate the submission once
	 *
	 * @see http://www.alexhager.at/how-to-integrate-salesforce-in-contact-form-7/
	 */
	function before_send($form, $submission = false){
		###_log(__LINE__.':'.__FILE__, '	begin before_send', $form);

		//get field mappings and settings
		$services = $this->get_services();

		// unlikely, but skip handling if nothing to do
		if(empty($services)) return $form;

		$debug = $this->get_settings();

		//loop services
		foreach($services as $sid => $service):
			$use_this_form = $this->use_form($form, $service, $sid);
			if(!$use_this_form) continue;

			// only get the submission once, now that we know we're going to use this service/form
			if(false === $submission) $submission = apply_filters($this->N('get_submission'), array(), $form, $service);

			// now we can conditionally check whether use the service based upon submission data
			$use_this_form = apply_filters($this->N('use_submission'), $use_this_form, $submission, $sid);
			if( !$use_this_form ) continue;


			// populate the 3rdparty post args
			$sendResult = $this->send($submission, $form, $service, $sid, $debug);
			if($sendResult === self::RET_SEND_STOP) break;
			elseif($sendResult === self::RET_SEND_SKIP) continue;

			$response = $sendResult['response'];
			$post_args = $sendResult['post_args'];

			$form = $this->handle_results($submission, $response, $post_args, $form, $service, $sid, $debug);
		endforeach;	//-- loop services

		###_log(__LINE__.':'.__FILE__, '	finished before_send', $form);

		// some plugins expected usage is as filter, so return (modified?) form
		return $form;
	}//---	end function before_send

	const RET_SEND_SKIP = -1;
	const RET_SEND_STOP = -2;
	const RET_SEND_OKAY = 1;

	/**
	 * Check for the given service if we're supposed to use it with this form
	 * @param $form
	 * @param $service
	 * @param $sid
	 * @return bool whether to skip or not
	 */
	public function use_form($form, $service, $sid) {
		//check if we're supposed to use this service
		if( !isset($service['forms']) || empty($service['forms']) ) return false; // nothing provided

		// it's more like "use_this_service", actually...
		$use_this_form = apply_filters($this->N('use_form'), false, $form, $sid, $service['forms']);

		###_log('are we using this form?', $use_this_form ? "YES" : "NO", $sid, $service);

		return $use_this_form;
	}

	/**
	 * Create and perform the 3rdparty submission
	 * @param $submission user input submission
	 * @param $form the plugin form source
	 * @param $service current service being sent
	 * @param $sid service id
	 * @param $debug debug settings
	 * @return array|int either [response, post_args] or an interrupt value like @see RET_SEND_SKIP
	 */
	public function send($submission, $form, $service, $sid, $debug) {
		$post = array();

		$service['separator'] = $debug['separator']; // alias here for reporting

		//find mapping
		foreach($service['mapping'] as $mid => $mapping){
			$third = $mapping[self::PARAM_3RD];

			//is this static or dynamic (userinput)?
			if(v($mapping['val'])){
				$input = $mapping[self::PARAM_SRC];
			}
			else {
				//check if we have that field in post data
				if( !isset($submission[ $mapping[self::PARAM_SRC] ]) ) continue;

				$input = $submission[ $mapping[self::PARAM_SRC] ];
			}

			//allow multiple values to attach to same entry
			if( isset( $post[ $third ] ) ){
				###echo "multiple @$mid - $fsrc, $third :=\n";

				if(!is_array($post[$third])) {
					$post[$third] = array($post[$third]);
				}
				$post[$third] []= $input;
			}
			else {
				$post[$third] = $input;
			}
		}// foreach mapping

		//extract special tags;
		$post = apply_filters($this->N('service_filter_post_'.$sid), $post, $service, $form, $submission);
		$post = apply_filters($this->N('service_filter_post'), $post, $service, $form, $sid, $submission);

		// fix for multiple values
		switch($service['separator']) {
			case '[#]':
				// don't do anything to include numerical index (default behavior of `http_build_query`)
				break;
			case '[%]':
				// see github issue #43
				$post = $this->placeholder_separator($post);
				break;
			case '[]':
				// must build as querystring then strip `#` out of `[#]=`
				$post = http_build_query($post);
				$post = preg_replace('/%5B[0-9]+%5D=/', '%5B%5D=', $post);
				break;
			default:
				// otherwise, find the arrays and implode
				foreach($post as $f => &$v) {
					###_log('checking array', $f, $v, is_array($v) ? 'array' : 'notarray');

					if(is_array($v)) $v = implode($service['separator'], $v);
				}
				break;
		}

		###_log(__LINE__.':'.__FILE__, '	sending post to '.$service['url'], $post);

		$formId = "";
		$formFieldsData = array();
		foreach ($post as $key => $value) {
			if($key === 'formId'){
				$formId = $value;
			}else{
				$formFieldsData[] = array(
					"name" => $key,
					"value" => $value
				);
			}
		}
		if(isset($_COOKIE[bpmRef])) {
			$formFieldsData[] = array(
				"name"  => "BpmRef",
				"value" => $_COOKIE["bpmRef"]
			);
		}
		if(isset($_COOKIE[bpmHref])) {
			$formFieldsData[] = array(
				"name"  => "BpmHref",
				"value" => $_COOKIE["bpmHref"]
			);
		}
		if(isset($_COOKIE[bpmTrackingId])) {
			$formFieldsData[] = array(
				"name"  => "BpmSessionId",
				"value" => $_COOKIE["bpmTrackingId"]
			);
		}
		$formData = new stdClass();
		$formData->formData = new stdClass();
		$formData->formData->formId = $formId;
		$formData->formData->formFieldsData = $formFieldsData;
		$formDataStr = json_encode($formData);

		// change args sent to remote post -- add headers, etc: http://codex.wordpress.org/Function_Reference/wp_remote_post
		// optionally, return an array with 'response_bypass' set to skip the wp_remote_post in favor of whatever you did in the hook
		$post_args = apply_filters($this->N('service_filter_args')
			, array(
				'timeout' => empty($service['timeout']) ? self::DEFAULT_TIMEOUT : $service['timeout']
			    ,'body'=>$formDataStr
                ,'sslverify'=>0
                ,'headers'=>'Content-Type:application/json'
			)
			, $service
			, $form
		);

		//remote call

		// once more conditionally check whether use the service based upon (mapped) submission data
		if(false === $post_args) return self::RET_SEND_SKIP;
		// optional bypass -- replace with a SOAP call, etc
		elseif(isset($post_args['response_bypass'])) {
			$response = $post_args['response_bypass'];
		}
		else {
			//@see http://planetozh.com/blog/2009/08/how-to-make-http-requests-with-wordpress/

			$response = wp_remote_post(
			// allow hooks to modify the URL with submission, like send as url-encoded XML, etc
				apply_filters($this->N('service_filter_url'), $service['url'], $post_args),
				$post_args
			);
		}

		###pbug(__LINE__.':'.__FILE__, '	response from '.$service['url'], $response);
		### _log(__LINE__.':'.__FILE__, '	response from '.$service['url'], $submission, $post_args, $response);

		return array('response' => $response, 'post_args' => $post_args);
	}//--	fn	send

	/**
	 * Interpret and respond accordingly to the post results
	 *
	 * @param $submission
	 * @param $response
	 * @param $post_args
	 * @param $form
	 * @param $service
	 * @param $sid
	 * @param $debug
	 */
	public function handle_results($submission, $response, $post_args, $form, $service, $sid, $debug) {
		$can_hook = true;
		//if something went wrong with the remote-request "physically", warn
		if (!is_array($response)) {	//new occurrence of WP_Error?????
			$response_array = array('safe_message'=>'error object', 'object'=>$response);
			$form = $this->on_response_failure($form, $debug, $service, $post_args, $response_array);
			$can_hook = false;
		}
		elseif(!$response
			|| !isset($response['response'])
			|| !isset($response['response']['code'])
			|| ! apply_filters($this->N('is_success'), 200 <= $response['response']['code'] && $response['response']['code'] < 400, $response, $service)
		) {
			$response['safe_message'] = 'physical request failure';
			$form = $this->on_response_failure($form, $debug, $service, $post_args, $response);
			$can_hook = false;
		}
		//otherwise, check for a success "condition" if given
		elseif(!empty($service['success'])) {
			if(strpos($response['body'], $service['success']) === false){
				$failMessage = array(
					'reason'=>'Could not locate success clause within response'
				, 'safe_message' => 'Success Clause not found'
				, 'clause'=>$service['success']
				, 'response'=>$response['body']
				);
				$form = $this->on_response_failure($form, $debug, $service, $post_args, $failMessage);
				$can_hook = false;
			}
		}

		if($can_hook){
			###_log('performing hooks for:', $this->N.'_service_'.$sid);

			//hack for pass-by-reference
			//holder for callback return results
			$callback_results = array('success'=>false, 'errors'=>false, 'attach'=>'', 'message' => '');
			// TODO: use object?
			$param_ref = array();	foreach($callback_results as $k => &$v){ $param_ref[$k] = &$v; }

			//allow hooks
			do_action($this->N('service_a'.$sid), $response['body'], $param_ref);
			do_action($this->N('service'), $response['body'], $param_ref, $sid);

			###_log('after success', $form);

			//check for callback errors; if none, then attach stuff to message if requested
			if(!empty($callback_results['errors'])){
				$failMessage = array(
					'reason'=>'Service Callback Failure'
				, 'safe_message' => 'Service Callback Failure'
				, 'errors'=>$callback_results['errors']);
				$form = $this->on_response_failure($form, $debug, $service, $post_args, $failMessage);
			}
			else {
				###_log('checking for attachments', print_r($callback_results, true));
				$form = apply_filters($this->N('remote_success'), $form, $callback_results, $service);
			}
		}// can hook

		### _log(__FUNCTION__, $debug, strpos($debug['mode'], 'debug'));

		//forced debug contact; support legacy setting too
		if(isset($debug['mode']) && ($debug['mode'] == 'debug' || in_array('debug', $debug['mode'])) ) {
			// TMI with new WP_HTTP_Requests_Response object
			if( !is_a($response, 'WP_Error') && isset($response['http_response']) && is_object($response['http_response']) ) $response = $response['http_response'];

			$this->send_debug_message($debug, $service, $post_args, $response, $submission);
		}

		return $form;
	}

	/**
	 * How to send the debug message
	 * @param  string $debug      debug options -- 'email' and 'sender'
	 * @param  array $service    service options
	 * @param  array $post       details sent to 3rdparty
	 * @param  array|object $response   the response object
	 * @param  object $submission the form submission
	 * @return void             n/a
	 */
	private function send_debug_message($debug, $service, $post, $response, $submission){
		// allow hooks to bypass, if for example we're not getting debug emails or we want to use some fancy logging service
		$passthrough = apply_filters($this->N('debug_message'), true, $service, $post, $submission, $response, $debug);

		// not all hosting services allow arbitrary emails
		$sendAs = isset($debug['sender']) && !empty($debug['sender'])
			? $debug['sender']
			: get_bloginfo('admin_email'); //'From: "'.self::pluginPageTitle.' Debug" <'.$this->N.'-debug@' . str_replace('www.', '', $_SERVER['HTTP_HOST']) . '>';
		$recipients = isset($debug['email']) && !empty($debug['email'])
			? str_replace(';', ',', $debug['email'])
			: get_bloginfo('admin_email');

		// did the debug message send?
		if( !$passthrough || !wp_mail( $recipients
			, self::pluginPageTitle . " Debug: {$service['name']}"
			, "*** Service ***\n".print_r($service, true)."\n*** Post (Form) ***\n" . get_bloginfo('url') . $_SERVER['REQUEST_URI'] . "\n".print_r($submission, true)."\n*** Post (to Service) ***\n".print_r($post, true)."\n*** Response ***\n".print_r($response, true)
			, array($sendAs)
		) ) {
			///TODO: log? another email? what?
			error_log( sprintf("%s:%s	could not send F3P debug email (to: %s) for service %s", __LINE__, __FILE__, $recipients, $service['url']) );

			if(in_array('log', $debug['mode'])) {
				$log = array(
					'sendAs' => $sendAs,
					'recipients' => $recipients,
					'submission' => $submission,
					'post' => $post,
					'response' => $response
				);

				if(in_array('full', $debug['mode'])) $log['service'] = $service;

				$as_json = is_array($post['body']) && isset($post['body']['_json']) && $post['body']['_json'];
				$dump = $as_json
							? wp_json_encode($log, defined('JSON_PRETTY_PRINT') ? JSON_PRETTY_PRINT : 0)
							: print_r($log, true);
				error_log( __CLASS__ . ':: ' . $dump );
			}
		}
	}

	/**
	 * Add a javascript warning for failures; also send an email to debugging recipient with details
	 * parameters passed by reference mostly for efficiency, not actually changed (with the exception of $form)
	 *
	 * @param $form object CF7 plugin object - contains mail details etc
	 * @param $debug array this plugin "debug" option array
	 * @param $service array service settings
	 * @param $post_args array service post data
	 * @param $response mixed|object remote-request response
	 * @return mixed|object|void the updated $form
	 */
	private function on_response_failure($form, $debug, $service, $post_args, $response){
		// failure hooks; pass-by-value

		$form = apply_filters($this->N('remote_failure'), $form, $debug, $service, $post_args, $response);

		return $form;
	}//---	end function on_response_failure


	/**
	 * Email helper for `remote_failure` hooks
	 * @param array $service			service configuration
	 * @param array $debug				the debug settings (to get email)
	 * @param array $post				details sent to 3rdparty
	 * @param array $response 			remote-request response
	 * @param string $form_title		name of the form used
	 * @param string $form_recipient	email of the original form recipient, so we know who to follow up with
	 * @param debug_from_id				short identifier of the Form plugin which failed (like 'CF7' or 'GF', etc)
	 *
	 * @return true if the warning email sent, false otherwise
	 */
	public function send_service_error(&$service, &$debug, &$post, &$response, $form_title, $form_recipient, $debug_from_id) {
		$body = sprintf('There was an error when trying to integrate with the 3rd party service {%2$s} (%3$s).%1$s%1$s**FORM**%1$sTitle: %6$s%1$sIntended Recipient: %7$s%1$sSource: %8$s%1$s%1$s**SUBMISSION**%1$s%4$s%1$s%1$s**RAW RESPONSE**%1$s%5$s'
			, "\n"
			, $service['name']
			, $service['url']
			, print_r($post, true)
			, print_r($response, true)
			, $form_title
			, $form_recipient
			, get_bloginfo('url') . $_SERVER['REQUEST_URI']
			);
		$subject = sprintf('%s-3rdParty Integration Failure: %s'
			, $debug_from_id
			, $service['name']
			);
		$headers = array(
			sprintf('From: "%1$s-3rdparty Debug" <%1$s-3rdparty-debug@%2$s>'
				, $debug_from_id
				, str_replace('www.', '', $_SERVER['HTTP_HOST'])
				)
			);

		//log if couldn't send debug email
		if(wp_mail( $debug['email'], $subject, $body, $headers )) return true;

		###$form->additional_settings .= "\n".'on_sent_ok: \'alert("Could not send debug warning '.$service['name'].'");\'';
		error_log(__LINE__.':'.__FILE__ .'	response failed from '.$service['url'].', could not send warning email: ' . print_r($response, true));
		return false;
	}//--	fn	send_service_error


	/**
	 * Format the configured service failure message using the $response "safe message" and the plugin's original error message
	 * @param array $service			service configuration
	 * @param array $response			remote-request response
	 * @param string $original_message	the plugin's original failure message
	 * @return the newly formatted failure message
	 */
	public function format_failure_message(&$service, &$response, $original_message) {
		return sprintf(
				__($service['failure'], $this->N())
				, $original_message
				, __($response['safe_message'], $this->N())
				);
	}
}//end class

/*
// some servers need at least one 'sacrificial' `error_log` call to make _log call work???

error_log('f3p-after-declare:' . $_SERVER["REQUEST_URI"]);

if(!function_exists('_log')) {
function _log($args) {
	$args = func_get_args();
	error_log( print_r($args, true) );
}
}
*/
