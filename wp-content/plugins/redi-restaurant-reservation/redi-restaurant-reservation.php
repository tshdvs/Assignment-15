<?php
/*
  Plugin Name: ReDi Restaurant Reservation
  Plugin URI: http://reservationdiary.eu/eng/reservation-wordpress-plugin/
  Description: ReDi Reservation plugin for Restaurants
  Version: 15.0422
  Author: reservationdiary.eu
  Author URI: http://reservationdiary.eu/
  Text Domain: redi-restaurant-reservation
  Domain Path: /lang

 */
if ( ! defined( 'REDI_RESTAURANT_PLUGIN_URL' ) ) {
	define( 'REDI_RESTAURANT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'REDI_RESTAURANT_TEMPLATE' ) ) {
	define( 'REDI_RESTAURANT_TEMPLATE', plugin_dir_path( __FILE__ ) . 'templates' . DIRECTORY_SEPARATOR );
}
if ( ! defined( 'REDI_RESTAURANT_DEBUG' ) ) {
	define( 'REDI_RESTAURANT_DEBUG', false );
}
if ( ! defined( 'ID' ) ) {
	define( 'ID', 'ID' );
}
require_once( 'redi.php' );


if ( ! class_exists( 'ReDiRestaurantReservation' ) ) {
	if ( ! class_exists( 'Report' ) ) {
		class Report {
			const Full = 'Full';
			const None = 'None';
			const Single = 'Single';
		}
	}
	if ( ! class_exists( 'EmailFrom' ) ) {
		class EmailFrom {
			const ReDi = 'ReDi';
			const WordPress = 'WordPress';
			const Disabled = 'Disabled';
		}
	}
	if ( ! class_exists( 'EmailContentType' ) ) {
		class EmailContentType {
			const Canceled = 'Canceled';
			const Confirmed = 'Confirmed';
		}
	}
	if ( ! class_exists( 'AlternativeTime' ) ) {
		class AlternativeTime {
			const AlternativeTimeBlocks = 1;
			const AlternativeTimeByShiftStartTime = 2;
			const AlternativeTimeByDay = 3;
		}
	}

	class ReDiRestaurantReservation {

		public $version = '15.0422';
		/**
		 * @var string The options string name for this plugin
		 */
		private $optionsName = 'wp_redi_restaurant_options';
		private $apiKeyOptionName = 'wp_redi_restaurant_options_ApiKey';
		private static $name = 'REDI_RESTAURANT';
		private $options = array();
		private $ApiKey;
		private $redi;
		private $weekday = array( 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' );

		public function error_handler( $errno, $errstr, $errfile, $errline ) {
			if ( in_array( 'redi-restaurant-reservation', explode( DIRECTORY_SEPARATOR, $errfile ) ) ) {
				$this->display_errors( array( 'Error' => array( $errline . ':' . $errstr ) ), false );
			}
		}

		public function exception_handler( $exception ) {
			if ( in_array( 'redi-restaurant-reservation', explode( DIRECTORY_SEPARATOR, $exception->getFile() ) ) ) {
				$this->display_errors( array(
					'Error' => $exception->getMessage()
				), false );
			}
		}

		function filter_timeout_time() {
			return 60; //new number of seconds default 5
		}

		function __destruct() {
			restore_error_handler();
			restore_exception_handler();
		}

		public function __construct() {
			$this->_name = self::$name;
			set_exception_handler( array( $this, 'exception_handler' ) );
			set_error_handler( array( $this, 'error_handler' ) );
			//Initialize the options
			$this->get_options();

			$this->ApiKey = isset( $this->options[ ID ] ) ? $this->options[ ID ] : null;

			$this->redi = new Redi( $this->ApiKey );
			//Actions
			add_action( 'init', array( $this, 'init_sessions' ) );
			add_action( 'admin_menu', array( $this, 'redi_restaurant_admin_menu_link_new' ) );

			$this->page_title = 'Reservation';
			$this->content    = '[redirestaurant]';
			$this->page_name  = $this->_name;
			$this->page_id    = '0';

			register_activation_hook( __FILE__, array( $this, 'activate' ) );
			register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );
			register_uninstall_hook( __FILE__, array( $this, 'uninstall' ) );

			add_action( 'wp_ajax_nopriv_redi_restaurant-submit', array( $this, 'redi_restaurant_ajax' ) );
			add_action( 'wp_ajax_redi_restaurant-submit', array( $this, 'redi_restaurant_ajax' ) );
			add_filter( 'http_request_timeout', array( $this, 'filter_timeout_time' ) );
			add_action( 'http_api_curl', array($this, 'my_http_api_curl'), 100, 1 );
			add_filter( 'http_request_args', array($this, 'my_http_request_args'), 100, 1 );
			add_shortcode( 'redirestaurant', array( $this, 'shortcode' ) );
		}

		function my_http_request_args ( $r )
		{
			$r['timeout'] = 60; # new timeout
			return $r;
		}

		function my_http_api_curl ( $handle )
		{
			curl_setopt( $handle, CURLOPT_CONNECTTIMEOUT, 60 ); # new timeout
			curl_setopt( $handle, CURLOPT_TIMEOUT, 60 ); # new timeout
		}

		function language_files( $mofile, $domain ) {

			if ( $domain === 'redi-restaurant-reservation' ) {

				$full_file    = WP_PLUGIN_DIR . '/redi-restaurant-reservation/lang/' . $domain . '-' . get_locale() . '.mo';
				$generic_file = WP_PLUGIN_DIR . '/redi-restaurant-reservation/lang/' . $domain . '-' . substr( get_locale(),
						0, 2 ) . '.mo';
				if ( file_exists( $full_file ) ) {
					return $full_file;
				}
				if ( file_exists( $generic_file ) ) {
					return $generic_file;
				}
			}

			return $mofile;
		}

		function ReDiRestaurantReservation() {
			$this->__construct();
		}

		//TODO: better version check
		function plugin_get_version() {
			$plugin_data    = get_plugin_data( __FILE__ );
			$plugin_version = $plugin_data['Version'];

			return $plugin_version;
		}

		/**
		 * Retrieves the plugin options from the database.
		 * @return array
		 */
		function get_options() {
			if ( ! $options = get_option( $this->optionsName ) ) {
				update_option( $this->optionsName, $options );
			}
			$this->options = $options;
		}

		private function register() {
			//Gets email and sitename from config
			$new_account = $this->redi->createUser( array( 'Email' => get_option( 'admin_email' ) ) );

			if ( isset( $new_account[ ID ] ) && ! empty( $new_account[ ID ] ) ) {
				$this->ApiKey = $this->options[ ID ] = $new_account[ ID ];
				$this->redi->setApiKey( $this->options[ ID ] );
				$place = $this->redi->createPlace( array(
					'place' => array(
						'Name'                     => get_bloginfo( 'name' ),//get from site name
						'City'                     => 'city',
						'Country'                  => 'country',
						'Address'                  => 'Address line 1',
						'Email'                    => get_option( 'admin_email' ),
						'EmailCC'                  => '',
						'Phone'                    => '[areacode] [number]',
						'WebAddress'               => get_option( 'siteurl' ),
						'Lang'                     => str_replace( '_', '-', get_locale() ),
						'MinTimeBeforeReservation' => 24, // hour
						'DescriptionShort'         => get_option( 'blogdescription' ),
						'DescriptionFull'          => '',
						'Catalog'                  => true
					)
				) );

				if ( isset( $place['Error'] ) ) {
					return $place;
				}

				$placeID = (int) $place[ ID ];

				$category = $this->redi->createCategory( $placeID,
					array( 'category' => array( 'Name' => 'Restaurant' ) ) );

				if ( isset( $category['Error'] ) ) {
					return $category;
				}

				$categoryID = (int) $category[ ID ];
				$service    = $this->redi->createService( $categoryID,
					array( 'service' => array( 'Name' => 'Person', 'Quantity' => 10 ) ) );

				if ( isset( $service['Error'] ) ) {
					return $service;
				}

				foreach ( $this->weekday as $value ) {
					$times[ $value ] = array( 'OpenTime' => '12:00', 'CloseTime' => '00:00' );
				}
				$this->redi->setServiceTime( $categoryID, $times );

				$this->saveAdminOptions();
			}

			return $new_account;
		}

		/**
		 * Saves the admin options to the database.
		 */
		function saveAdminOptions() {
			return update_option( $this->optionsName, $this->options );
		}

		function display_errors( $errors, $admin = false, $action = '' ) {
			if ( isset( $errors['Error'] ) && is_array( $errors ) ) {
				foreach ( (array) $errors['Error'] as $error ) {
					echo '<div class="error redi-reservation-alert-error redi-reservation-alert"><p>' . $error . '</p></div>';
					$this->pixel(
						array(
							'type'       => 'error',
							'message'    => $error,
							'actionName' => $action
						)
					);
				}
			}
			//WP-errors
			if ( isset( $errors['Wp-Error'] ) ) {

				foreach ( (array) $errors['Wp-Error'] as $error_key => $error ) {
					foreach ( (array) $error as $err ) {
						if ( $admin ) {
							echo '<div class="error"><p>' . $error_key . ' : ' . $err . '</p></div>';
						}
						$this->pixel(
							array(
								'type'         => 'Wp-Error',
								'wp_error_key' => $error_key,
								'message'      => $err,
								'actionName'   => $action,
								'loadTime'     => $errors['request_time']
							)
						);
					}
				}
			}
		}

        function redi_restaurant_admin_upcoming(){
            $iframe_url ='http://upcoming.reservationdiary.eu/Entry/'.$this->ApiKey;
            require_once( REDI_RESTAURANT_TEMPLATE . 'iframe.php' );
        }

        function redi_restaurant_admin_test(){
            $iframe_url = get_option( 'siteurl' ).'/reservation';
            require_once( REDI_RESTAURANT_TEMPLATE . 'iframe.php' );
        }

        function redi_restaurant_admin_reservations(){
            $iframe_url = $this->redi->getReservationUrl(str_replace( '_', '-', get_locale() ));
            require_once( REDI_RESTAURANT_TEMPLATE . 'iframe.php' );
        }

		/**
		 * Adds settings/options page
		 */
		function redi_restaurant_admin_options_page() {
			$errors = array();

			if ( $this->ApiKey == null ) { /// TODO: move to install

				$this->display_errors( $this->register(), true, 'register' );
			}

			if ( $this->ApiKey == null ) {

				$errors['Error'] = array(
					__( 'ReDi Restaurant Reservation plugin could not get an API key from the reservationdiary.eu server when it activated.' .
					    '<br/> You can try to fix this by going to the ReDi Restaurant Reservation "options" page. ' .
					    '<br/>This will cause ReDi Restaurant Reservation plugin to retry fetching an API key for you. ' .
					    '<br/>If you keep seeing this error it usually means that server where you host your web site can\'t connect to our reservationdiary.eu server. ' .
					    '<br/>You can try asking your WordPress host to allow your WordPress server to connect to api.reservationdiary.eu' .
					    '<br/>In case you can not solve this problem yourself, please contact us directly by <a href="mailto:info@reservationdiary.eu">info@reservationdiary.eu</a>',
						'redi-restaurant-reservation' )
				);
				$this->display_errors( $errors, true, 'Failed to register' );
				die;
			}
			$places = $this->redi->getPlaces();

			if ( isset( $places['Error'] ) ) {
				$this->display_errors( $places, true, 'getPlaces' );
				die;
			}
			$placeID = $places[0]->ID;

			$categories = $this->redi->getPlaceCategories( $placeID );

			$categoryID = $categories[0]->ID;

			if ( isset( $_POST['action'] ) && $_POST['action'] == 'cancel' ) {
				if ( isset( $_POST['id'] ) ) {
					$params = array(
						'ID'          => urlencode( self::GetPost( 'id' ) ),
						'Lang'        => str_replace( '_', '-', get_locale() ),
						'Reason'      => urlencode( mb_substr( self::GetPost( 'Reason' ), 0, 250 ) ),
						'CurrentTime' => urlencode( date( 'Y-m-d H:i', current_time( 'timestamp' ) ) ),
						'Version'     => urlencode( self::plugin_get_version() )
					);

					if ( isset( $this->options['EmailFrom'] ) && $this->options['EmailFrom'] == EmailFrom::Disabled ||
					     isset( $this->options['EmailFrom'] ) && $this->options['EmailFrom'] == EmailFrom::WordPress
					) {
						$params['DontNotifyClient'] = 'true';
					}
					$cancel = $this->redi->cancelReservation( $params );
					if ( isset( $this->options['EmailFrom'] ) && $this->options['EmailFrom'] == EmailFrom::WordPress && ! isset( $cancel['Error'] ) ) {
						//call api for content
						$emailContent = $this->redi->getEmailContent(
							(int) $cancel['ID'],
							EmailContentType::Canceled,
							array(
								'Lang' => str_replace( '_', '-', self::GetPost( 'lang' ) )
							)
						);

						//send
						if ( ! isset( $emailContent['Error'] ) ) {
							wp_mail( $emailContent['To'], $emailContent['Subject'], $emailContent['Body'], array(
								'Content-Type: text/html; charset=UTF-8',
								'From: ' . get_option( 'blogname' ) . ' <' . get_option( 'admin_email' ) . '>' . "\r\n"
							) );
						}
					}
					if ( isset( $cancel['Error'] ) ) {
						$errors[] = $cancel['Error'];
					} else {
						$cancel_success = __( 'Reservation has been successfully canceled.',
							'redi-restaurant-reservation' );
					}

				} else {
					$errors[] = __( 'Reservation number is required', 'redi-restaurant-reservation' );
				}
			}
			$settings_saved = false;
			if ( isset( $_POST['submit'] ) ) {
				$form_valid               = true;
				$services                 = (int) self::GetPost( 'services' );
				$minPersons               = (int) self::GetPost( 'MinPersons' );
				$maxPersons               = (int) self::GetPost( 'MaxPersons' );
				$largeGroupsMessage       = self::GetPost( 'LargeGroupsMessage' );
				$emailFrom                = self::GetPost( 'EmailFrom' );
				$report                   = self::GetPost( 'Report', Report::Full );
				$maxTime                  = self::GetPost( 'MaxTime' );
				$thanks                   = self::GetPost( 'Thanks', 0 );
				$timepicker               = self::GetPost( 'TimePicker' );
				$alternativeTimeStep      = self::GetPost( 'AlternativeTimeStep', 30 );
				$MinTimeBeforeReservation = self::GetPost( 'MinTimeBeforeReservation' );
				$dateFormat               = self::GetPost( 'DateFormat' );
				$calendar                 = self::GetPost( 'Calendar' );
				$hidesteps                = self::GetPost( 'Hidesteps' );
				$timeshiftmode            = self::GetPost( 'TimeShiftMode' );

				//validation
				if ( $minPersons > $maxPersons ) {
					$errors[]   = __( 'Min Persons should be lower than Max Persons', 'redi-restaurant-reservation' );
					$form_valid = false;
				}

				$reservationTime = (int) self::GetPost( 'ReservationTime' );
				if ( $reservationTime <= 0 ) {
					$errors[]   = __( 'Reservation time should be greater than 0', 'redi-restaurant-reservation' );
					$form_valid = false;
				}
				$place = array(
					'place' => array(
						'Name'                     => self::GetPost( 'Name' ),
						'City'                     => self::GetPost( 'City' ),
						'Country'                  => self::GetPost( 'Country' ),
						'Address'                  => self::GetPost( 'Address' ),
						'Email'                    => self::GetPost( 'Email' ),
						'EmailCC'                  => self::GetPost( 'EmailCC' ),
						'Phone'                    => self::GetPost( 'Phone' ),
						'WebAddress'               => self::GetPost( 'WebAddress' ),
						'Lang'                     => self::GetPost( 'Lang' ),
						'DescriptionShort'         => self::GetPost( 'DescriptionShort' ),
						'DescriptionFull'          => self::GetPost( 'DescriptionFull' ),
						'MinTimeBeforeReservation' => self::GetPost( 'MinTimeBeforeReservation' ),
						'Catalog'                  => (int) self::GetPost( 'Catalog' ),
						'DateFormat'               => self::GetPost( 'DateFormat' ),
						'MaxTimeBeforeReservation' => $maxTime,
						'Version'                  => $this->version
					)
				);

				if ( empty( $place['place']['Country'] ) ) {
					$errors[]   = __( 'Country is required', 'redi-restaurant-reservation' );
					$form_valid = false;
				}


				for ( $i = 1; $i != CUSTOM_FIELDS; $i ++ ) {
					$field_name     = 'field_' . $i . '_name';
					$field_type     = 'field_' . $i . '_type';
					$field_required = 'field_' . $i . '_required';
					$field_message  = 'field_' . $i . '_message';

					if ( isset( $_POST[ $field_name ] ) ) {
						$this->options[ $field_name ] = $$field_name = self::GetPost( $field_name );
					}
					if ( isset( $_POST[ $field_type ] ) ) {
						$this->options[ $field_type ] = $$field_type = self::GetPost( $field_type );
					}
					$this->options[ $field_required ] = $$field_required = ( self::GetPost( $field_required ) === 'on' );
					if ( isset( $_POST[ $field_message ] ) ) {
						$this->options[ $field_message ] = $$field_message = self::GetPost( $field_message );
					}
				}

				if ( $form_valid ) {
					$settings_saved = true;
					$serviceTimes   = self::GetServiceTimes();

					$this->options['Thanks']                   = $thanks;
					$this->options['TimePicker']               = $timepicker;
					$this->options['AlternativeTimeStep']      = $alternativeTimeStep;
					$this->options['services']                 = $services;
					$this->options['MinTimeBeforeReservation'] = $MinTimeBeforeReservation;
					$this->options['DateFormat']               = $dateFormat;
					$this->options['ReservationTime']          = $reservationTime;
					$this->options['Hidesteps']                = $hidesteps;
					$this->options['MinPersons']               = $minPersons;
					$this->options['MaxPersons']               = $maxPersons;
					$this->options['LargeGroupsMessage']       = $largeGroupsMessage;
					$this->options['EmailFrom']                = $emailFrom;
					$this->options['Report']                   = $report;
					$this->options['MaxTime']                  = $maxTime;
					$this->options['Calendar']                 = $calendar;
					$this->options['TimeShiftMode']            = $timeshiftmode;

					$placeID    = self::GetPost( 'Place' );
					$categories = $this->redi->getPlaceCategories( $placeID );
					if ( isset( $categories['Error'] ) ) {
						$errors[]       = $categories['Error'];
						$settings_saved = false;
					}
					$categoryID                 = $categories[0]->ID;
					$this->options['OpenTime']  = self::GetPost( 'OpenTime' );
					$this->options['CloseTime'] = self::GetPost( 'CloseTime' );

					$getServices = $this->redi->getServices( $categoryID );
					if ( isset( $getServices['Error'] ) ) {
						$errors[]       = $getServices['Error'];
						$settings_saved = false;
					}
					if ( count( $getServices ) != $services ) {
						if ( count( $getServices ) > $services ) {
							//delete
							$diff = count( $getServices ) - $services;
							$ids  = array();

							$removeServices = array_slice( $getServices, 0, $diff );
							foreach ( $removeServices AS $service ) {
								$ids[] = $service->ID;
							}

							$cancel = $this->redi->deleteServices( $ids );
							if ( isset( $cancel['Error'] ) ) {
								$errors[]       = $cancel['Error'];
								$settings_saved = false;
							}
							$cancel = array();
						} else {
							//add
							$diff = $services - count( $getServices );

							$cancel = $this->redi->createService( $categoryID,
								array(
									'service' => array(
										'Name'     => 'Person',
										'Quantity' => $diff
									)
								) );
							if ( isset( $cancel['Error'] ) ) {
								$errors[]       = $cancel['Error'];
								$settings_saved = false;
							}
							$cancel = array();
						}
					}

					$this->saveAdminOptions();

					if ( is_array( $serviceTimes ) && count( $serviceTimes ) ) {
						$cancel = $this->redi->setServiceTime( $categoryID, $serviceTimes );
						if ( isset( $cancel['Error'] ) ) {
							$errors[]       = $cancel['Error'];
							$settings_saved = false;
						}
						$cancel = array();
					}
					$cancel = $this->redi->setPlace( $placeID, $place );
					if ( isset( $cancel['Error'] ) ) {
						$errors[]       = $cancel['Error'];
						$settings_saved = false;
					}
					$cancel = array();
				}

				$places = $this->redi->getPlaces();
				if ( isset( $places['Error'] ) ) {
					$errors[]       = $places['Error'];
					$settings_saved = false;
				}
			}

			$this->options = get_option( $this->optionsName );

			if ( $settings_saved || ! isset( $_POST['submit'] ) ) {
				$thanks        = $this->GetOption( 'Thanks', 0 );
				$calendar      = $this->GetOption( 'Calendar', 'hide' );
				$hidesteps     = $this->GetOption( 'Hidesteps', 'false' );
				$timeshiftmode = $this->GetOption( 'TimeShiftMode', 'normal' );
				$timepicker    = $this->GetOption( 'TimePicker' );

				$minPersons          = $this->GetOption( 'MinPersons', 1 );
				$maxPersons          = $this->GetOption( 'MaxPersons', 10 );
				$alternativeTimeStep = $this->GetOption( 'AlternativeTimeStep', 30 );
				$largeGroupsMessage  = $this->GetOption( 'LargeGroupsMessage', '' );
				$emailFrom           = $this->GetOption( 'EmailFrom', EmailFrom::ReDi );
				$report              = $this->GetOption( 'Report', Report::Full );
				$maxTime             = $this->GetOption( 'MaxTime', 1 );

				$getServices = $this->redi->getServices( $categoryID );
				if ( isset( $getServices['Error'] ) ) {
					$errors[] = $getServices['Error'];
				}

				$reservationTime = $this->getReservationTime();

				for ( $i = 1; $i != CUSTOM_FIELDS; $i ++ ) {
					$field_name     = 'field_' . $i . '_name';
					$field_type     = 'field_' . $i . '_type';
					$field_required = 'field_' . $i . '_required';
					$field_message  = 'field_' . $i . '_message';

					$$field_name     = $this->GetOption( $field_name );
					$$field_type     = $this->GetOption( $field_type );
					$$field_required = $this->GetOption( $field_required );
					$$field_message  = $this->GetOption( $field_message );
				}
			}

			if ( ! $settings_saved && isset( $_POST['submit'] ) ) {
				$timepicker          = self::GetPost( 'TimePicker' );
				$alternativeTimeStep = self::GetPost( 'AlternativeTimeStep' );
			}

			require_once( REDI_RESTAURANT_TEMPLATE . 'admin.php' );
			require_once( REDI_RESTAURANT_TEMPLATE . 'basicpackage.php' );
		}

		private function GetOption( $name, $default = null ) {
			return isset( $this->options[ $name ] ) ? $this->options[ $name ] : $default;
		}

		private static function GetPost( $name, $default = null, $post = null ) {
			if ( $post ) {
				return isset( $post[ $name ] ) ? $post[ $name ] : $default;
			}

			return isset( $_POST[ $name ] ) ? $_POST[ $name ] : $default;
		}

		function GetServiceTimes() {
			$serviceTimes = array();
			foreach ( $_POST['OpenTime'] as $key => $value ) {
				if ( self::set_and_not_empty( $value ) ) {
					$serviceTimes[ $key ]['OpenTime'] = $value;
				}
			}
			foreach ( $_POST['CloseTime'] as $key => $value ) {
				if ( self::set_and_not_empty( $value ) ) {
					$serviceTimes[ $key ]['CloseTime'] = $value;
				}
			}

			return $serviceTimes;
		}

		function ajaxed_admin_page( $placeID, $categoryID, $settings_saved = false ) {
			require_once( plugin_dir_path( __FILE__ ) . 'languages.php' );
			$places      = $this->redi->getPlaces();
			$getServices = $this->redi->getServices( $categoryID );

			if ( ! isset( $_POST['submit'] ) || $settings_saved ) {

				$serviceTimes = $this->redi->getServiceTime( $categoryID ); //goes to template 'admin'
				$serviceTimes = json_decode( json_encode( $serviceTimes ), true );
				$place        = $this->redi->getPlace( $placeID ); //goes to template 'admin'

			} else {
				$place        = array(
					'Name'                     => self::GetPost( 'Name' ),
					'City'                     => self::GetPost( 'City' ),
					'Country'                  => self::GetPost( 'Country' ),
					'Address'                  => self::GetPost( 'Address' ),
					'Email'                    => self::GetPost( 'Email' ),
					'EmailCC'                  => self::GetPost( 'EmailCC' ),
					'Phone'                    => self::GetPost( 'Phone' ),
					'WebAddress'               => self::GetPost( 'WebAddress' ),
					'Lang'                     => self::GetPost( 'Lang' ),
					'DescriptionShort'         => self::GetPost( 'DescriptionShort' ),
					'DescriptionFull'          => self::GetPost( 'DescriptionFull' ),
					'MinTimeBeforeReservation' => self::GetPost( 'MinTimeBeforeReservation' ),
					'Catalog'                  => (int) self::GetPost( 'Catalog' ),
					'DateFormat'               => self::GetPost( 'DateFormat' )
				);
				$serviceTimes = self::GetServiceTimes();
			}
			require_once( 'countrylist.php' );
			require_once( REDI_RESTAURANT_TEMPLATE . 'admin_ajaxed.php' );
		}

		function init_sessions() {
			if ( ! session_id() ) {
				session_start();
			}

			if ( function_exists( 'load_plugin_textdomain' ) ) {
				add_filter( 'load_textdomain_mofile', array( $this, 'language_files' ), 10, 2 );
				load_plugin_textdomain( 'redi-restaurant-reservation', false, 'redi-restaurant-reservation/lang' );
				load_plugin_textdomain( 'redi-restaurant-reservation-errors', false,
					'redi-restaurant-reservation/lang' );
			}

		}

		function redi_restaurant_admin_menu_link_new() {
			$icon = 'dashicons-groups';

            if($this->ApiKey) {
	            add_menu_page(
                    __('ReDi Reservations', 'redi-restaurant-reservation'),
                    __('ReDi Reservations', 'redi-restaurant-reservation'),
                    'edit_posts',
                    'redi-restaurant-reservation-reservations',
                    array(&$this, 'redi_restaurant_admin_reservations'),
                    $icon); // where in menu it will be located, 26 is after comments
                add_submenu_page(
                    'redi-restaurant-reservation-reservations',
                    __('Settings', 'redi-restaurant-reservation'),
                    __('Settings', 'redi-restaurant-reservation'),
                    'edit_posts',
                    'redi-restaurant-reservation-settings',
                    array(&$this, 'redi_restaurant_admin_options_page'));

                add_submenu_page(
                    'redi-restaurant-reservation-reservations',
                    __('Settings $','redi-restaurant-reservation'),
                    __('Settings $','redi-restaurant-reservation'),
                    'edit_posts',
                    'redi-restaurant-reservation-settings&sm=basic',
                    array( &$this, 'redi_restaurant_admin_options_page' ));
                add_submenu_page(
                    'redi-restaurant-reservation-reservations',
                    __('Test reservation','redi-restaurant-reservation'),
                    __('Test reservation','redi-restaurant-reservation'),
                    'edit_posts',
                    'redi-restaurant-reservation-test',
                    array( &$this, 'redi_restaurant_admin_test' ));
                add_submenu_page(
                    'redi-restaurant-reservation-reservations',
                    __('Upcoming (Tablet PC)','redi-restaurant-reservation'),
                    __('Upcoming (Tablet PC)','redi-restaurant-reservation'),
                    'edit_posts',
                    'redi_restaurant_admin_upcoming',
                    array( &$this, 'redi_restaurant_admin_upcoming' ));
			} else {
                add_menu_page(
                    __('ReDi Reservations', 'redi-restaurant-reservation'),
                    __('ReDi Reservations', 'redi-restaurant-reservation'),
                    'edit_posts'
                    ,
                    'redi-restaurant-reservation-manage-options',
                    array(&$this, 'redi_restaurant_admin_options_page'),
                    $icon, 26); // where in menu it will be located, 26 is after comments
            }
		}

		static function install() {
			//register is here
		}

		public function activate() {
			delete_option( $this->_name . '_page_title' );
			add_option( $this->_name . '_page_title', $this->page_title, '', 'yes' );

			delete_option( $this->_name . '_page_name' );
			add_option( $this->_name . '_page_name', $this->page_name, '', 'yes' );

			delete_option( $this->_name . '_page_id' );
			add_option( $this->_name . '_page_id', $this->page_id, '', 'yes' );

			$the_page = get_page_by_title( $this->page_title );

			if ( ! $the_page ) {
				// Create post object
				$_p                   = array();
				$_p['post_title']     = $this->page_title;
				$_p['post_content']   = $this->content;
				$_p['post_status']    = 'publish';
				$_p['post_type']      = 'page';
				$_p['comment_status'] = 'closed';
				$_p['ping_status']    = 'closed';
				$_p['post_category']  = array( 1 ); // the default 'Uncategorized'
				// Insert the post into the database
				$this->page_id = wp_insert_post( $_p );
			} else {
				// the plugin may have been previously active and the page may just be trashed...
				$this->page_id = $the_page->ID;

				//make sure the page is not trashed...
				$the_page->post_status = 'publish';
				$this->page_id         = wp_update_post( $the_page );
			}

			delete_option( $this->_name . '_page_id' );
			add_option( $this->_name . '_page_id', $this->page_id );

			if ( $this->ApiKey == null ) // TODO: move to install
			{
				$this->register();
			}

		}

		private static function set_and_not_empty( $value ) {
			return ( isset( $value ) && ! empty( $value ) );
		}

		public function deactivate() {
			$this->deletePage();
			$this->deleteOptions();
		}

		public static function uninstall() {
			self::deletePage( true );
			self::deleteOptions();
		}

		private function deletePage( $hard = false ) {
			$id = get_option( self::$name . '_page_id' );
			if ( $id && $hard == true ) {
				wp_delete_post( $id, true );
			} elseif ( $id && $hard == false ) {
				wp_delete_post( $id );
			}
		}

		private function deleteOptions() {
			delete_option( self::$name . '_page_title' );
			delete_option( self::$name . '_page_name' );
			delete_option( self::$name . '_page_id' );
		}

		function getCalendarDateFormat( $format ) {
			switch ( $format ) {
				case 'MM-dd-yyyy':
					return 'mm-dd-yy';

				case 'dd-MM-yyyy':
					return 'dd-mm-yy';

				case 'yyyy.MM.dd':
					return 'yy.mm.dd';

				case 'MM.dd.yyyy':
					return 'mm.dd.yy';

				case 'dd.MM.yyyy':
					return 'dd.mm.yy';

				case 'yyyy/MM/dd':
					return 'yy/mm/dd';

				case 'MM/dd/yyyy':
					return 'mm/dd/yy';

				case 'dd/MM/yyyy':
					return 'dd/mm/yy';
			}

			return 'yy-mm-dd';
		}

		function getPHPDateFormat( $format ) {
			switch ( $format ) {
				case 'MM-dd-yyyy':
					return 'm-d-Y';

				case 'dd-MM-yyyy':
					return 'd-m-Y';

				case 'yyyy.MM.dd':
					return 'Y.m.d';

				case 'MM.dd.yyyy':
					return 'm.d.Y';

				case 'dd.MM.yyyy':
					return 'd.m.Y';

				case 'yyyy/MM/dd':
					return 'Y/m/d';

				case 'MM/dd/yyyy':
					return 'm/d/Y';

				case 'dd/MM/yyyy':
					return 'd/m/Y';
			}

			return 'Y-m-d';
		}

		public function shortcode( $atts ) {
			if ( is_array( $atts ) && is_array( $this->options ) ) {
				$this->options = array_merge( $this->options, $atts );
			}
			ob_start();
			wp_enqueue_script( 'jquery' );
			wp_register_style( 'jquery_ui', null, array( 'jquery' ) );
			wp_enqueue_style( 'jquery_ui' );

			wp_register_style( 'jquery-ui-custom-style',
				REDI_RESTAURANT_PLUGIN_URL . '/css/custom-theme/jquery-ui-1.8.18.custom.css' );
			wp_enqueue_style( 'jquery-ui-custom-style' );
			wp_enqueue_script( 'jquery-ui-datepicker' );
			wp_register_script( 'datetimepicker',
				REDI_RESTAURANT_PLUGIN_URL . '/lib/datetimepicker/js/jquery-ui-timepicker-addon.js',
				array( 'jquery', 'jquery-ui-core', 'jquery-ui-slider', 'jquery-ui-datepicker' ) );
			wp_enqueue_script( 'datetimepicker' );

			wp_register_script( 'datetimepicker-lang',
				REDI_RESTAURANT_PLUGIN_URL . '/lib/datetimepicker/js/jquery.ui.i18n.all.min.js' );
			wp_enqueue_script( 'datetimepicker-lang' );

			wp_register_script( 'timepicker-lang',
				REDI_RESTAURANT_PLUGIN_URL . '/lib/timepicker/i18n/jquery-ui-timepicker.all.lang.js' );
			wp_enqueue_script( 'timepicker-lang' );

			//	wp_register_script( 'date.format', REDI_RESTAURANT_PLUGIN_URL . 'js/date.format.js' );

			wp_register_script( 'restaurant', REDI_RESTAURANT_PLUGIN_URL . 'js/restaurant.js', array(
				//		'date.format',
				'jquery'
			) );

			wp_localize_script( 'restaurant',
				'redi_restaurant_reservation',
				array( // URL to wp-admin/admin-ajax.php to process the request
					'ajaxurl'        => admin_url( 'admin-ajax.php' ),
					'id_missing'     => __( 'Reservation number can\'t be empty', 'redi-restaurant-reservation' ),
					'name_missing'   => __( 'Name can\'t be empty', 'redi-restaurant-reservation' ),
					'email_missing'  => __( 'Email can\'t be empty', 'redi-restaurant-reservation' ),
					'phone_missing'  => __( 'Phone can\'t be empty', 'redi-restaurant-reservation' ),
					'reason_missing' => __( 'Reason can\'t be empty', 'redi-restaurant-reservation' ),
					'next'           => __( 'Next', 'redi-restaurant-reservation' ),
				) );
			wp_enqueue_script( 'restaurant' );

			wp_register_style( 'redi-restaurant', REDI_RESTAURANT_PLUGIN_URL . '/css/restaurant.css' );
			wp_enqueue_style( 'redi-restaurant' );

			$apiKeyId = (int) $this->GetOption( 'apikeyid' );

			if ( $apiKeyId ) {
				$this->ApiKey = $this->GetOption( 'apikey' . $apiKeyId, $this->ApiKey );

				$check = get_option( $this->apiKeyOptionName . $apiKeyId );
				if ( $check != $this->ApiKey ) { // update only if changed
					//Save Key if newed
					update_option( $this->apiKeyOptionName . $apiKeyId, $this->ApiKey );
				}
				$this->redi->setApiKey( $this->ApiKey );
			}
			if ( $this->ApiKey == null ) {
				$this->display_errors( array(
					'Error' => __( 'Online reservation service is not available at this time. Try again later or contact us directly.',
						'redi-restaurant-reservation' )
				), false, 'Frontend No ApiKey' );

				return;
			}

			if ( isset( $_GET['jquery_fail'] ) && $_GET['jquery_fail'] === 'true' ) {
				$this->display_errors( array(
					'Error' => __( 'Plugin failed to properly load javascript file, please check that jQuery is loaded and no javascript errors present.',
						'redi-restaurant-reservation' )
				), false, 'Frontend No ApiKey' );
				$this->pixel( array(
					'type'       => 'js_fail',
					'actionName' => 'shortcode',
				) );
			}
			//places
			$places = $this->redi->getPlaces();
			if ( isset( $places['Error'] ) ) {
				$this->display_errors( $places, false, 'getPlaces' );

				return;
			}

			if ( isset( $this->options['placeid'] ) ) {
				$places = array( (object) array( 'ID' => $this->options['placeid'] ) );
			}
			$placeID = $places[0]->ID;

			$categories = $this->redi->getPlaceCategories( $placeID );
			if ( isset( $categories['Error'] ) ) {
				$this->display_errors( $categories, false, 'getPlaceCategories' );

				return;
			}

			$categoryID               = $categories[0]->ID;
			$time_format              = get_option( 'time_format' );

			$date_format_setting      = $this->GetOption( 'DateFormat' );
			$date_format              = $this->getPHPDateFormat( $date_format_setting );
			$calendar_date_format     = $this->getCalendarDateFormat( $date_format_setting );
			$MinTimeBeforeReservation = (int) ( $this->GetOption( 'MinTimeBeforeReservation' ) > 0 ? $this->GetOption( 'MinTimeBeforeReservation' ) : 0 ) + 1;
			$reservationStartTime     = strtotime( '+' . $MinTimeBeforeReservation . ' hour',
				current_time( 'timestamp' ) );
			$startDate                = date( $date_format, $reservationStartTime );
			$startDateISO             = date( 'Y-m-d', $reservationStartTime );
			$startTime                = mktime( date( 'G', $reservationStartTime ), 0, 0, 0, 0, 0 );

			$minPersons         = $this->GetOption( 'MinPersons', 1 );
			$maxPersons         = $this->GetOption( 'MaxPersons', 10 );
			$largeGroupsMessage = $this->GetOption( 'LargeGroupsMessage', '' );
			$emailFrom          = $this->GetOption( 'EmailFrom', EmailFrom::ReDi );
			$report             = $this->GetOption( 'Report', Report::Full );
			$maxTime            = $this->GetOption( 'MaxTime', 1 );
			$thanks             = $this->GetOption( 'Thanks' );

			$timepicker        = $this->GetOption( 'timepicker', $this->GetOption( 'TimePicker' ) );
			$time_format_hours = self::dropdown_time_format();
			$calendar          = $this->GetOption( 'calendar',
				$this->GetOption( 'Calendar' ) ); // first admin settings then shortcode

			for ( $i = 1; $i != CUSTOM_FIELDS; $i ++ ) {
				$field_name     = 'field_' . $i . '_name';
				$field_type     = 'field_' . $i . '_type';
				$field_required = 'field_' . $i . '_required';
				$field_message  = 'field_' . $i . '_message';

				if ( isset( $this->options[ $field_name ] ) ) {
					if ( isset( $this->options[ $field_name ] ) ) {
						$$field_name = $this->options[ $field_name ];
					}
				}

				if ( isset( $this->options[ $field_type ] ) ) {
					$$field_type = $this->options[ $field_type ];
				}

				if ( isset( $this->options[ $field_required ] ) ) {
					$$field_required = $this->options[ $field_required ];
				}

				if ( isset( $this->options[ $field_message ] ) ) {
					$$field_message = $this->options[ $field_message ];
				}
			}
			$hide_clock    = false;
			$persons       = (int) $this->GetOption( 'MinPersons' );
			$all_busy      = false;
			$hidesteps     = false; // this settings only for 'byshifts' mode
			$timeshiftmode = $this->GetOption( 'timeshiftmode', $this->GetOption( 'TimeShiftMode' ) );
			if ( $timeshiftmode === 'byshifts' ) {
				$hidesteps = $this->GetOption( 'hidesteps',
						$this->GetOption( 'Hidesteps' ) ) == 'true'; // first admin settings then shortcode
				//pre call
				$categories = $this->redi->getPlaceCategories( $placeID );
				$categoryID = $categories[0]->ID;
				$step1      = self::object_to_array(
					$this->step1( $categoryID,
						array(
							'startDateISO' => $startDateISO,
							'startTime'    => '0:00',
							'persons'      => $persons,
							'lang'         => get_locale()
						)
					)
				);
				$hide_clock = true;
			}

			$js_locale         = get_locale();
			$datepicker_locale = substr( $js_locale, 0, 2 );


			$dates = $this->redi->getBlockingDates( str_replace( '_', '-', get_locale() ), $categoryID, array(
				'StartTime' => date( 'Y-m-d' ),
				'EndTime'   => date( 'Y-m-d', strtotime('+3 month') ) // + 3 months
			) );


			$disabled_dates = array();
			$enabled_dates = array();

			foreach($dates as $date) {
				if($date->Blocked) {
					$disabled_dates[] = "'".$date->Date."'";
				}else {
					$enabled_dates[] = "'".$date->Date."'";
				}
			}

			$enabled_dates = implode( ',', $enabled_dates );
			$disabled_dates = implode( ',', $disabled_dates );

			$time_format_s = explode( ':', $time_format );

			$timepicker_time_format = ( isset( $time_format_s[0] ) && in_array( $time_format_s[0],
					array( 'g', 'h' ) ) ) ? 'h:mm tt' : 'HH:mm';
			$buttons_time_format    = ( isset( $time_format_s[0] ) && in_array( $time_format_s[0],
					array( 'g', 'h' ) ) ) ? 'h:MM TT' : 'HH:MM';
			if ( function_exists( 'qtrans_convertTimeFormat' ) || function_exists( 'ppqtrans_convertTimeFormat' ) ) {// time format from qTranslate and qTranslate Plus
				global $q_config;
				$format              = $q_config['time_format'][ $q_config['language'] ];
				$buttons_time_format = self::convert_to_js_format( $format );
			}

			require_once( REDI_RESTAURANT_TEMPLATE . 'frontend.php' );
			$out = ob_get_contents();

			ob_end_clean();

			return $out;
		}

		function convert_to_js_format( $format ) {
			$convert = array(
//Day 	    --- 	---
//Week 	    --- 	---
//Month 	--- 	---
//Year 	    --- 	---
//Time 	    --- 	---

//%I 	Two digit representation of the hour in 12-hour format 	01 through 12
//hh 	Hours; leading zero for single-digit hours (12-hour clock).
				'I' => 'hh',
//%l (lower-case 'L') 	Hour in 12-hour format, with a space preceding single digits 	1 through 12
//h 	Hours; no leading zero for single-digit hours (12-hour clock).
				'l' => 'h',
//%k 	Two digit representation of the hour in 24-hour format, with a space preceding single digits 	0 through 23
//H 	Hours; no leading zero for single-digit hours (24-hour clock).
				'k' => 'H',
//%H 	Two digit representation of the hour in 24-hour format 	00 through 23
//HH 	Hours; leading zero for single-digit hours (24-hour clock).
				'H' => 'HH',
//%M 	Two digit representation of the minute 	00 through 59
//MM 	Minutes; leading zero for single-digit minutes.
				'M' => 'MM',
//%P 	lower-case 'am' or 'pm' based on the given time 	Example: am for 00:31, pm for 22:23
//tt 	Lowercase, two-character time marker string: am or pm.
				'P' => 'tt',
//%p 	UPPER-CASE 'AM' or 'PM' based on the given time 	Example: AM for 00:31, PM for 22:23
//TT 	Uppercase, two-character time marker string: AM or PM.
				'p' => 'TT',
			);

			$result = '';
			foreach ( str_split( $format ) as $char ) {
				if ( $char == '%' ) {
					$result .= '';
				} elseif ( array_key_exists( $char, $convert ) ) {
					$result .= $convert[ $char ];
				} else {
					$result .= $char;
				}
			}

			return $result;
		}

		function dropdown_time_format() {
			$wp_time_format       = get_option( 'time_format' );
			$wp_time_format_array = str_split( $wp_time_format );
			foreach ( $wp_time_format_array as $index => $format_char ) // some users have G \h i \m\i\n
			{
				if ( $format_char === '\\' ) {
					$wp_time_format_array[ $index ] = '';
					if ( isset( $wp_time_format_array[ $index + 1 ] ) ) {
						$wp_time_format_array[ $index + 1 ] = '';
					}
				}
			}
			$wp_time_format     = implode( '', $wp_time_format_array );
			$is_am_pm           = strpos( $wp_time_format, 'g' );
			$is_am_pm_lead_zero = strpos( $wp_time_format, 'h' );

			$is_24           = strpos( $wp_time_format, 'G' );
			$is_24_lead_zero = strpos( $wp_time_format, 'H' );

			if ( $is_am_pm !== false || $is_am_pm_lead_zero !== false ) {
				$a       = stripos( $wp_time_format, 'a' );
				$am_text = '';
				if ( $a !== false ) {
					$am_text = $wp_time_format[ $a ];
				}
				if ( $is_am_pm !== false ) {
					return $wp_time_format[ $is_am_pm ] . ' ' . $am_text;
				}
				if ( $is_am_pm_lead_zero !== false ) {
					return $wp_time_format[ $is_am_pm_lead_zero ] . ' ' . $am_text;
				}
			}
			if ( $is_24 !== false ) {
				return $wp_time_format[ $is_24 ];
			}
			if ( $is_24_lead_zero !== false ) {
				return $wp_time_format[ $is_24_lead_zero ];
			}

			return 'H'; //if no time format found use 24 h with lead zero
		}

		private static function format_time( $start_time, $language, $format ) {

			global $q_config;
			if ( function_exists( 'qtrans_convertTimeFormat' ) ) {// time format from qTranslate
				$format = $q_config['time_format'][ $language ];

				return qtrans_strftime( $format, strtotime( $start_time ) );
			} elseif ( function_exists( 'ppqtrans_convertTimeFormat' ) ) { //and qTranslate Plus
				$format = $q_config['time_format'][ $language ];

				return ppqtrans_strftime( $format, strtotime( $start_time ) );
			}

			return date( $format, strtotime( $start_time ) );
		}

		private static function load_time_format( $lang, $default_time_format ) {
			$filename = plugin_dir_path( __FILE__ ) . 'time_format.json';

			if ( file_exists( $filename ) ) {
				$json = json_decode( file_get_contents( $filename ), true );
				if(array_key_exists($lang, $json)) {
					return $json[$lang];
				}
			}
			return $default_time_format;
		}

		private function step1( $categoryID, $post, $placeID = null ) {
			global $q_config;
			$loc = get_locale();
			if ( isset( $post['lang'] ) ) {
				$loc = $post['lang'];
			}
			$time_lang   = null;
			$time_format = get_option( 'time_format' );
			if ( isset( $q_config['language'] ) ) { //if q_translate
				$time_lang = $q_config['language'];
				foreach ( $q_config['locale'] as $key => $val ) {
					if ( $loc == $val ) {
						$time_lang = $key;
					}
				}
			} else { // load time format from file
				$time_format = self::load_time_format( $loc, $time_format );
			}

			$timeshiftmode = self::GetPost( 'timeshiftmode',
				$this->GetOption( 'timeshiftmode', $this->GetOption( 'TimeShiftMode' ) ) );
			// convert date to array
			$date = date_parse( self::GetPost( 'startDateISO', null, $post ) . ' ' . self::GetPost( 'startTime',
					date( 'H:i', current_time( 'timestamp' ) ), $post ) );

			if ( $date['error_count'] > 0 ) {
				echo json_encode(
                    array_merge($date['errors'],
                    array('Error' => __( 'Selected date or time is not valid.', 'redi-restaurant-reservation' ))
				) );
				die;
			}

			$startTimeStr = $date['year'] . '-' . $date['month'] . '-' . $date['day'] . ' ' . $date['hour'] . ':' . $date['minute'];

			$persons = (int) $post['persons'];
			// convert to int
			$startTimeInt = strtotime( $startTimeStr, 0 );

			// calculate end time
			$endTimeInt = strtotime( '+' . $this->getReservationTime( $persons ) . 'minutes', $startTimeInt );

			// format to ISO
			$startTimeISO   = date( 'Y-m-d H:i', $startTimeInt );
			$endTimeISO     = date( 'Y-m-d H:i', $endTimeInt );
			$currentTimeISO = date( 'Y-m-d H:i', current_time( 'timestamp' ) );

			if ( $timeshiftmode === 'byshifts' ) {
				$StartTime = gmdate( 'Y-m-d 00:00', strtotime( $post['startDateISO'] ) ); //CalendarDate + 00:00
				$EndTime   = gmdate( 'Y-m-d 00:00',
					strtotime( '+1 day', strtotime( $post['startDateISO'] ) ) ); //CalendarDate + 1day + 00:00
				$params    = array(
					'StartTime'           => urlencode( $StartTime ),
					'EndTime'             => urlencode( $EndTime ),
					'Quantity'            => $persons,
					'Lang'                => str_replace( '_', '-', $post['lang'] ),
					'CurrentTime'         => urlencode( $currentTimeISO ),
					'AlternativeTimeStep' => self::getAlternativeTimeStep( $persons )
				);
				if ( isset( $post['alternatives'] ) ) {
					$params['Alternatives'] = $post['alternatives'];
				}
				$params = apply_filters( 'redi-reservation-pre-query', $params );
				$alternativeTime = AlternativeTime::AlternativeTimeByDay;

				switch ( $alternativeTime ) {
					case AlternativeTime::AlternativeTimeBlocks:
						$query = $this->redi->query( $categoryID, $params );
						break;

					case AlternativeTime::AlternativeTimeByShiftStartTime:
						$query = $this->redi->availabilityByShifts( $categoryID, $params );
						break;

					case AlternativeTime::AlternativeTimeByDay:
						$params['ReservationDuration'] = $this->getReservationTime( $persons );
						$query                         = $this->redi->availabilityByDay( $categoryID, $params );
						break;
				}
			} else {
				$categories = $this->redi->getPlaceCategories( $placeID );
				if ( isset( $categories['Error'] ) ) {
					$categories['Error'] = __( $categories['Error'], 'redi-restaurant-reservation-errors' );
					echo json_encode( $categories );
					die;
				}

				$params   = array(
					'StartTime'           => urlencode( $startTimeISO ),
					'EndTime'             => urlencode( $endTimeISO ),
					'Quantity'            => $persons,
					'Alternatives'        => 2,
					'Lang'                => str_replace( '_', '-', $post['lang'] ),
					'CurrentTime'         => urlencode( $currentTimeISO ),
					'AlternativeTimeStep' => self::getAlternativeTimeStep( $persons )
				);
				$category = $categories[0];

				$query = $this->redi->query( $category->ID, $params );
			}

			if ( isset( $query['Error'] ) ) {
				return $query;
			}
			unset( $query['debug'] );

			if ( $timeshiftmode === 'byshifts' ) {
				$query['alternativeTime'] = $alternativeTime;
				switch ( $alternativeTime ) {
					case AlternativeTime::AlternativeTimeBlocks: // pass thought
					case AlternativeTime::AlternativeTimeByShiftStartTime:
						foreach ( $query as $q ) {
							$q->Select       = ( $startTimeISO == $q->StartTime && $q->Available );
							$q->StartTimeISO = $q->StartTime;
							$q->StartTime    = self::format_time( $q->StartTime, $time_lang, $time_format );
							$q->EndTime      = date( $time_format, strtotime( $q->EndTime ) );
						}
						break;
					case AlternativeTime::AlternativeTimeByDay:
						foreach ( $query as $q2 ) {
							if ( isset( $q2->Availability ) ) {
								foreach ( $q2->Availability as $q ) {
									$q->Select       = ( $startTimeISO == $q->StartTime && $q->Available );
									$q->StartTimeISO = $q->StartTime;
									$q->StartTime    = self::format_time( $q->StartTime, $time_lang, $time_format );
									$q->EndTime      = date( $time_format, strtotime( $q->EndTime ) );
								}
							}
						}
						break;
				}
			} else {
				foreach ( $query as $q ) {
					$q->Select       = ( $startTimeISO == $q->StartTime && $q->Available );
					$q->StartTimeISO = $q->StartTime;
					$q->StartTime    = self::format_time( $q->StartTime, $time_lang, $time_format );
					$q->EndTime      = date( $time_format, strtotime( $q->EndTime ) );
				}
			}

			return $query;
		}

		function redi_restaurant_ajax() {

			$apiKeyId = $this->GetPost( 'apikeyid' );
			if ( $apiKeyId ) {
				$this->ApiKey = get_option( $this->apiKeyOptionName . $apiKeyId );
				$this->redi->setApiKey( $this->ApiKey );
			}

			if ( isset( $_POST['placeID'] ) ) {
				$placeID    = (int) self::GetPost( 'placeID' );
				$categories = $this->redi->getPlaceCategories( $placeID );
				if ( isset( $categories['Error'] ) ) {
					echo json_encode( $categories );
					die;
				}
				$categoryID = $categories[0]->ID;

			}
			switch ( $_POST['get'] ) {
				case 'step1':
					echo json_encode( $this->step1( $categoryID, $_POST, $placeID ) );
					break;

				case 'step2':

					$persons = (int) self::GetPost( 'persons' );
					// convert to int
					$startTimeStr = self::GetPost( 'startTime' );

					// convert to int
					$startTimeInt = strtotime( $startTimeStr, 0 );

					// calculate end time
					$endTimeInt = strtotime( '+' . $this->getReservationTime( $persons ) . 'minutes', $startTimeInt );

					// format to ISO
					$startTimeISO   = date( 'Y-m-d H:i', $startTimeInt );
					$endTimeISO     = date( 'Y-m-d H:i', $endTimeInt );
					$currentTimeISO = date( 'Y-m-d H:i', current_time( 'timestamp' ) );

					$params = array(
						'StartTime'           => urlencode( $startTimeISO ),
						'EndTime'             => urlencode( $endTimeISO ),
						'Quantity'            => $persons,
						'Alternatives'        => 0,
						'Lang'                => str_replace( '_', '-', self::GetPost( 'lang' ) ),
						'CurrentTime'         => urlencode( $currentTimeISO ),
						'AlternativeTimeStep' => self::getAlternativeTimeStep( $persons ),
					);

					$params['ReservationDuration'] = $this->getReservationTime( $persons );
					$query                         = $this->redi->query( $categoryID, $params );

					$time_format = get_option( 'time_format' );

					if ( isset( $query['Error'] ) ) {
						echo json_encode( $query );
						die;
					}

					unset( $query['debug'] );

					foreach ( $query as $q ) {
						$q->Select       = ( $startTimeISO == $q->StartTime && $q->Available );
						$q->StartTimeISO = $q->StartTime;
						$q->StartTime    = date( $time_format, strtotime( $q->StartTime ) );
						$q->EndTime      = date( $time_format, strtotime( $q->EndTime ) );
					}

					echo json_encode( $query[0] );
					break;

				case 'step3':
					$persons      = (int) self::GetPost( 'persons' );
					$startTimeStr = self::GetPost( 'startTime' );

					// convert to int
					$startTimeInt = strtotime( $startTimeStr, 0 );

					// calculate end time
					$endTimeInt = strtotime( '+' . $this->getReservationTime( $persons ) . 'minutes', $startTimeInt );

					// format to ISO
					$startTimeISO   = date( 'Y-m-d H:i', $startTimeInt );
					$endTimeISO     = date( 'Y-m-d H:i', $endTimeInt );
					$currentTimeISO = date( 'Y-m-d H:i', current_time( 'timestamp' ) );
					$comment        = '';
					for ( $i = 1; $i != CUSTOM_FIELDS; $i ++ ) {
						if ( isset( $_POST[ 'field_' . $i ] ) ) {

							$field_type = 'field_' . $i . '_type';

							if ( isset( $this->options[ $field_type ] ) && $this->options[ $field_type ] === 'checkbox' ) {
								$comment .= $this->options[ 'field_' . $i . '_name' ] . ': ';
								$comment .= ( self::GetPost( 'field_' . $i ) === 'on' ) ? __( 'Yes',
									'redi-restaurant-reservation' ) : __( 'No', 'redi-restaurant-reservation' );
								$comment .= '<br/>';
							} else {
								if ( ! empty( $_POST[ 'field_' . $i ] ) ) {
									$comment .= $this->options[ 'field_' . $i . '_name' ] . ': ';
									$comment .= self::GetPost( 'field_' . $i ) . '<br/>';
								}
							}
						}
					}
					if ( ! empty( $comment ) ) {
						$comment .= '<br/>';
					}
					$comment .= mb_substr( self::GetPost( 'UserComments', '' ), 0, 250 );

					$params = array(
						'reservation' => array(
							'StartTime'    => $startTimeISO,
							'EndTime'      => $endTimeISO,
							'Quantity'     => $persons,
							'UserName'     => self::GetPost( 'UserName' ),
							'UserEmail'    => self::GetPost( 'UserEmail' ),
							'UserComments' => $comment,
							'UserPhone'    => self::GetPost( 'UserPhone' ),
							'Name'         => 'Person',
							'Lang'         => str_replace( '_', '-', self::GetPost( 'lang' ) ),
							'CurrentTime'  => $currentTimeISO,
							'Version'      => $this->version
						)
					);
					if ( isset( $this->options['EmailFrom'] ) && $this->options['EmailFrom'] == EmailFrom::Disabled ||
					     isset( $this->options['EmailFrom'] ) && $this->options['EmailFrom'] == EmailFrom::WordPress
					) {
						$params['reservation']['DontNotifyClient'] = 'true';
					}
					$reservation = $this->redi->createReservation( $categoryID, $params );

					if ( isset( $this->options['EmailFrom'] ) && $this->options['EmailFrom'] == EmailFrom::WordPress && ! isset( $reservation['Error'] ) ) {
						//call api for content
						$emailContent = $this->redi->getEmailContent(
							(int) $reservation['ID'],
							EmailContentType::Confirmed,
							array(
								'Lang' => str_replace( '_', '-', self::GetPost( 'lang' ) )
							)
						);

						//send
						if ( ! isset( $emailContent['Error'] ) ) {
							wp_mail( $emailContent['To'], $emailContent['Subject'], $emailContent['Body'], array(
								'Content-Type: text/html; charset=UTF-8',
								'From: ' . get_option( 'blogname' ) . ' <' . get_option( 'admin_email' ) . '>' . "\r\n"
							) );
						}
					}
					echo json_encode( $reservation );
					break;

				case 'get_place':
					self::ajaxed_admin_page( $placeID, $categoryID, true );
					break;

				case 'cancel':
					$params = array(
						'ID'          => urlencode( self::GetPost( 'ID' ) ),
						'Email'       => urlencode( self::GetPost( 'Email' ) ),
						'Reason'      => urlencode( mb_substr( self::GetPost( 'Reason' ), 0, 250 ) ),
						'Lang'        => str_replace( '_', '-', self::GetPost( 'lang' ) ),
						'CurrentTime' => urlencode( date( 'Y-m-d H:i', current_time( 'timestamp' ) ) ),
						'Version'     => urlencode( self::plugin_get_version() )
					);
					if ( isset( $this->options['EmailFrom'] ) && $this->options['EmailFrom'] == EmailFrom::Disabled ||
					     isset( $this->options['EmailFrom'] ) && $this->options['EmailFrom'] == EmailFrom::WordPress
					) {
						$params['DontNotifyClient'] = 'true';
					}

					$cancel = $this->redi->cancelReservationByClient( $params );

					if ( isset( $this->options['EmailFrom'] ) && $this->options['EmailFrom'] == EmailFrom::WordPress && ! isset( $cancel['Error'] ) ) {
						//call api for content
						$emailContent = $this->redi->getEmailContent(
							(int) $cancel['ID'],
							EmailContentType::Canceled,
							array(
								'Lang' => str_replace( '_', '-', self::GetPost( 'lang' ) )
							)
						);

						//send
						if ( ! isset( $emailContent['Error'] ) ) {
							wp_mail( $emailContent['To'], $emailContent['Subject'], $emailContent['Body'], array(
								'Content-Type: text/html; charset=UTF-8',
								'From: ' . get_option( 'blogname' ) . ' <' . get_option( 'admin_email' ) . '>' . "\r\n"
							) );
						}
					}
					echo json_encode( $cancel );

					break;
			}

			die;
		}

		private function getAlternativeTimeStep( $persons = 0 ) {
			$filename = plugin_dir_path( __FILE__ ) . 'alternativetimestep.json';

			if ( file_exists( $filename ) && $persons ) {
				$json = json_decode( file_get_contents( $filename ), true );
				if ( $json !== null ) {
					if ( array_key_exists( $persons, $json ) ) {
						return (int) $json[ $persons ];
					}
				}
			}

			if ( isset( $this->options['AlternativeTimeStep'] ) && $this->options['AlternativeTimeStep'] > 0 ) {
				return (int) $this->options['AlternativeTimeStep'];
			}

			return 30;
		}

		private function getReservationTime( $persons = 0 ) {
			$filename = plugin_dir_path( __FILE__ ) . 'reservationtime.json';

			if ( file_exists( $filename ) && $persons ) {
				$json = json_decode( file_get_contents( $filename ), true );
				if ( $json !== null ) {
					if ( array_key_exists( $persons, $json ) ) {
						return (int) $json[ $persons ];
					}
				}
			}

			if ( isset( $this->options['ReservationTime'] ) && $this->options['ReservationTime'] > 0 ) {
				return (int) $this->options['ReservationTime'];
			}

			return 3 * 60;
		}

		private function object_to_array( $object ) {
			return json_decode( json_encode( $object ), true );
		}

		private function pixel( $params ) {
			global $wp_version;
			$params          = array_merge( $params, array(

				'contact'       => get_option( 'admin_email' ),
				'pluginVersion' => $this->version,
				'web'           => get_option( 'siteurl' ),
				'APIKEY'        => $this->ApiKey,
				'php'           => PHP_VERSION,
				'theme'         => get_option( 'current_theme' ),
				'wp_version'    => $wp_version,
				'lang'          => get_locale()
			) );
			$encoding_params = null;
			if ( version_compare( PHP_VERSION, '5.4.0' ) >= 0 ) {
				$params_encoded = base64_encode( json_encode( $params, $encoding_params ) );
			} else {
				$params_encoded = base64_encode( json_encode( $params ) );

			}
			echo '<img src="http://tp.reservationdiary.eu/?parameters=' . $params_encoded . '">';
		}
	}

}
new ReDiRestaurantReservation();

register_activation_hook( __FILE__, array( 'ReDiRestaurantReservation', 'install' ) );