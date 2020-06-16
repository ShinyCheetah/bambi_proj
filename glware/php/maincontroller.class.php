<?php
	class glwGodObject {
	
		private $action = '';
		public $params = array();

		public $work_dir = '';

		public $current_user = false;
		public $fileuploader = false;
		public $fileanalyzer = false;
		public $chatbackend = false;
		public $formprocessor = false;
		public $yahelper = false;
		public $adminviewer = false;

		public $yadisk_links_cache = array();

		private $log_level = 3;
		public $table_prefix = '';
		private $database = '';

		private $log_file_path = '';
		private $log_events = array();

		public $cant_create_table = 1005;
		private $nominations = array(
			'national_sell' => array('public_name' => "Национальная торговая компания", "public" => true),
			'national_production' => array('public_name' => "Национальная производственная компания", "public" => true),
			'salon' => array('public_name' => "Салон года", "public" => true),
			'network' => array('public_name' => "Сеть года", "public" => true),
			'debut' => array('public_name' => "Дебют года", "public" => true),
			'innovation' => array('public_name' => "Инновация года", "public" => true),
			'marketnetwork' => array('public_name' => "Маркетинговый проект года", "public" => true),
			'advertising' => array('public_name' => "Рекламный проект года", "public" => true),
			'privatelabel' => array('public_name' => "Частная торговая марка (Коллекция)", "public" => true),
			'service' => array('public_name' => "Частная торговая марка (Коллекция)", "public" => true),
			'jury_selected' => array('public_name' => "Выбран жюри", 'public' => false)
		);

		private $components_info = array(
			"yahelper" => array(
				"filename" => "yahelper.class.php",
				"classname" => "glwYaHelper",
				"propertyname" => "yahelper"
			),
			"formprocessor" => array(
				"filename" => "formprocessor.class.php",
				"classname" => "glwFormProcessor",
				"propertyname" => "formprocessor"

			),
			"fileuploader" => array(
				"filename" => "fileuploader.class.php",
				"classname" => "glwFileUploader",
				"propertyname" => "fileuploader"

			),
			"fileanalyzer" => array(
				"filename" => "fileanalyzer.class.php",
				"classname" => "glwFileAnalyzer",
				"propertyname" => "fileanalyzer"
			)
		);
		
		private $required_classes = array(
			'fileuploader', 'fileanalyzer', 'chatbackend', 'formprocessor', 'adminviewer'
		);

		public function __construct($modx, $config) {
			$this->modx = $modx;

			$user = $modx->getUser();
			$userGroup = $modx->getObject('modUserGroup', $user->get('primary_group'));
			$userPrimaryGroup = $userGroup->get('name');
			$profile = $modx->getObject('modUserProfile', array('internalkey' => $user->get('id')));
			$this->current_user = array_merge($user->toArray(), $profile->toArray());

			$this->work_dir = $config['work_dir'] != '' ? ($config['work_dir'] . '/php') : dirname(__FILE__);
			$this->log_file_path = $config['log_file_path'] != '' ? $config['log_level_path'] : (dirname(dirname($this->work_dir)) . '/log_file');
			$this->table_prefix = $modx->config[xPDO::OPT_TABLE_PREFIX] . "glw_";

			if (!empty($config['params'])) {
				$this->params = $config['params'];
			}

			$dsn_parts = explode(';', $this->modx->config[xPDO::OPT_CONNECTIONS][0]['dsn']);
			foreach ($dsn_parts as $part) {
				if (strpos($part, 'dbname') !== FALSE) {
					$left_right = explode('=', $part);
					$this->database = $left_right[1];
				}	
			}
					
			$this->action = array_key_exists('action', $config) ? $config['action'] : '';
			$this->log("Action registered - " . $this->action);
		}


		public function log_flush() {
			if (empty($this->log_events)) {
				return;
			}

			$fh = fopen($this->log_file_path, 'a');
			
			fwrite($fh, "========================\n");
			fwrite($fh, "glware session init\n");
			fwrite($fh, "========================\n");

			foreach ($this->log_events as $event) {
				$string = '<' . $event['time'] . '>; ('
					. $event['level'] . ')  ' . $event['message'] . "\n";

				fwrite($fh, $string);
			}
			fclose($fh);
		}

		public function log($message, $level = 3) {
			$this->log_events[] = array(
				'time' => date('H:m:i'),
				'level' => (integer)$level,
				'message' => $message
			);
		}


		public function table_exists($table_name) {
			$res = $this->modx->query("select * from information_schema.tables where "
			. "table_schema = '" . $this->database . "' and table_name = '"
			. $this->table_prefix . "{$table_name}'");
			
			$res = is_object($res) ? $res->fetch(PDO::FETCH_ASSOC) : false;
			
			return (boolean)$res;
		}


		public function init() {
			$class_path = $class_name = '';
			$installed = false;

			if ($this->table_exists('nomination_list')) {
				$result = $this->modx->query("SELECT * FROM " . $this->table_prefix . "nomination_list");

				if ($result->fetch(PDO::FETCH_ASSOC)) {
					$installed = true;
				}
			}

			if (!$installed) {
				$this->install();
			}

			if (!$this->action) {
				return array('message' => 'no action found');
			}

			if (!($this->modx instanceof modX)) {
				return array('message' => 'can\'t connect to the main system API');
			}

			foreach ($this->required_classes as $class) {
				$class_path = $this->work_dir . '/' . strtolower($class) . '.class.php';
				$class_name = 'glw' . $class;
				if (!file_exists($class_path)) {
					return array('message' => 'Required classes error: ' .  $class_path);

					break;
				}
			}

			$this->user = $this->modx->user;
			$this->user_type = '';
		}


		private function install() {
			$this->log("Goldlornet Ware is not installed yet. Trying to setup tables;");
			$tp = $this->table_prefix;

			$create_nominations = "CREATE table IF NOT EXISTS {$tp}nomination_list (
				id INT AUTO_INCREMENT,
				code VARCHAR(60) NOT NULL,
				public_name VARCHAR(150) NULL,
				PRIMARY KEY (id)
			)";
			$create_request_table = "CREATE table IF NOT EXISTS {$tp}user_formit_request (
				id INT AUTO_INCREMENT,
				formit_hash VARCHAR(255) NOT NULL,
				user_id INT NOT NULL,
				date INT NOT NULL,
				PRIMARY KEY (id)
			)";
			$create_request_nominations_table = "CREATE table IF NOT EXISTS {$tp}attendee_nomination_links (
				id INT AUTO_INCREMENT,
				request_id INT NOT NULL,
				nomination_id INT NOT NULL,
				PRIMARY KEY (id)
			)";
			$create_voting_table = "CREATE table IF NOT EXISTS {$tp}voting_results (
				id INT AUTO_INCREMENT,
				process_id INT NOT NULL,
				request_id INT NOT NULL,
				nomination_id INT NOT NULL,
				PRIMARY KEY (id)
			)";

			$create_voting2_table = "CREATE table IF NOT EXISTS {$tp}voting_process (
				id INT AUTO_INCREMENT,
				judge_id INT NOT NULL,
				year VARCHAR(4) NOT NULL,
				person_of_year VARCHAR (255) NULL,
				PRIMARY KEY (id)
			)";

			$create_yalinks_cache = "CREATE table IF NOT EXISTS {$tp}yalinks_cache (
				id INT AUTO_INCREMENT,
				request_id INT NOT NULL,
				resource_type VARCHAR (30) NOT NULL,
				cached_link VARCHAR (255) NOT NULL,
				PRIMARY KEY (id)
			)";

			$this->modx->query($create_nominations);
			$err = $this->modx->errorInfo();

			if ($err[1] == $this->cant_create_table) {
				$this->log("Unfortunately, not allowed to create tables on this server programmatically. Instead you should fulfill this preparations manually");
			} else {
				$insert_nominations = "INSERT into {$tp}nomination_list (code, public_name) VALUES ";
				$values = array();
				foreach ($this->nominations as $code => $nomination) {
					$values[] = "('{$code}', '{$nomination['public_name']}')";
				}

				$this->modx->query($insert_nominations . implode(',', $values));
		
				$this->modx->query($create_request_table);
				$this->modx->query($create_request_nominations_table);
				$this->modx->query($create_voting_table);
				$this->modx->query($create_voting2_table);
			}
		}

		public function load_component($name) {
			$classname = $this->components_info[$name]['classname'];
			$path = $this->components_info[$name]['filename'];
			$propertyname = $this->components_info[$name]['propertyname'];
			$result = false;

			if (property_exists($this, $propertyname) && $this->$propertyname instanceof $classname) {
				$result = true;
			} else if (class_exists($classname)) {
				$this->$propertyname = new $classname($this);
				$result = true;
			} else if (file_exists($this->work_dir . "/" . $path)) {
				include_once $this->work_dir . "/" . $path;
				$this->$propertyname = new $classname($this);
				$result = true;
			}

			if (!$result) {
				$this->log("Component loader: can not find " . $name . "; Given data for search: " . print_r(array_merge(array(
					'type of class' => $this->$propertyname,
				), $this->components_info[$name]), true));
			}
		}


		public function handle() {
			$output = array();

			$this->load_component("formprocessor");
			$this->load_component("fileuploader");
			$this->load_component("fileanalyzer");
			$this->load_component("yahelper");
	
			switch ($this->action) {
				case 'ya_init':
					$output = $this->yahelper->check_token();	
					break;

				case 'ya_receive':
					$output = $this->yahelper->recieve_token();
					break;

				case 'request':
					$request_id = $this->formprocessor->create_request();
					if ($output != 0) {
						$output = $this->yahelper->upload($request_id);
					}

					break;

				case 'files':
					$output = $this->fileuploader->upload();
					break;

				case 'get_gallery':
					$this->fileuploader->prepare_buffer();
					$output = array();
					break;

				case 'request_table':
				case 'judge_voting':
				case 'judges_activity':
				case 'voting_summary':
					$output = $this->formprocessor->get_table($this->action);
					break;				

				case 'vote':
					$output = $this->formprocessor->vote();
					break;

				case 'chat':
					include_once $this->work_dir . "/chatbackend.class.php";
					$this->chat = new glwChatBackend($this);

					$output = $this->chat->run();
					break;
				default:
					$output = array("success" => false, "message" => "Invalid request");
			}
			
			$this->log_flush();
			return $output;
		}


		public function get_user_file_link($user_id, $type) {
			$link = "";
	
			if (isset($this->yadisk_links_cache[$user_id]) && isset($this->yadisk_links_cache[$user_id][$type])) {
				$link = $this->yadisk_links_cache[$user_id][$type];
			} else {
				$last_request_id = $this->formprocessor->get_user_last_requests($user_id);
				$tp = $this->table_prefix;
				$result = $this->modx->query("SELECT * FROM {$tp}yalinks_cache WHERE request_id = " . intval($last_request_id) . " AND resource_type = '" . $type . "'");
				
				while (is_object($result) && $row = $result->fetch(PDO::FETCH_ASSOC)) {
					$link = $row["cached_link"];
				}

				if (!isset($this->yadisk_links_cache[$user_id])) {
					$this->yadisk_links_cache[$user_id] = array();
				}

				$this->yadisk_links_cache[$user_id][$type] = $link ? $link : false;
			}

			return $link;
		}
	}
