<?php
/**
 * Licenses Admin
 *
 * @namespace UsabilityDynamics
 *
 */
namespace UsabilityDynamics\UD_API {

  if( !class_exists( 'UsabilityDynamics\UD_API\Admin' ) ) {

    /**
     * 
     * @author: peshkov@UD
     */
    class Admin extends Scaffold {
    
      /**
       *
       */
      public static $version = '1.0.0';
      
      /**
       *
       */
      private $api_url;
      
      /**
       * Don't ever change this, as it will mess with the data stored of which products are activated, etc.
       *
       */
      private $token;
      
      /**
       *
       */
      private $api;

      /**
       *
       */
      public $ui;
      
      /**
       *
       */
      private $installed_products = array();
      
      /**
       *
       */
      private $pending_products = array();
      
      /**
       *
       */
      private $more_products = array();
      
      /**
       *
       */
      public function __construct( $args = array() ) {
        parent::__construct( $args );
        
        //echo "<pre>"; print_r( $args ); echo "</pre>"; die();
        
        //** Set UD API URL. Can be defined custom one in wp-config.php */
        $this->api_url = defined( 'UD_API_URL' ) ? trailingslashit( UD_API_URL ) : 'http://usabilitydynamics.com/';
        
        //** Don't ever change this, as it will mess with the data stored of which products are activated, etc. */
        $this->token = 'udl_' . $this->plugin;
        
        //** API */
        $this->api = new API( array_merge( $args, array(
          'api_url' => $this->api_url,
          'token' => $this->token,
        ) ) );
        
        //** UI */
        $this->ui = new UI( array_merge( $args, array(
          'token' => $this->token,
          'screens' => array_filter( array(
            'licenses' => __( 'Licenses', $this->domain ),
            'more_products' => !empty( $args[ 'products' ] ) ? __( 'More Products', $this->domain ) : false,
          ) ),
        ) ) );
        
        $path = dirname( dirname( __DIR__ ) );
        $this->screens_path = trailingslashit( $path . '/static/templates' );
        $this->assets_url = trailingslashit( plugin_dir_url( $path . '/readme.md' ) . 'static' );
        
        //** Load the updaters. */
        add_action( 'admin_init', array( $this, 'load_updater_instances' ) );
        
        //** Check Activation Statuses */
        add_action( 'plugins_loaded', array( $this, 'check_activation_status' ), 11 );
        
        //** Add Licenses page */
        $menu_hook = is_multisite() ? 'network_admin_menu' : 'admin_menu';
        add_action( $menu_hook, array( $this, 'register_licenses_screen' ), 100 );
        
        //** Admin Notices Filter */
        add_filter( 'ud:errors:admin_notices', array( $this, 'maybe_remove_notices' ) );
        add_filter( 'ud:messages:admin_notices', array( $this, 'maybe_remove_notices' ) );
      }
      
      /**
       * Register the admin screen.
       *
       * @access public
       * @since   1.0.0
       * @return   void
       */
      public function register_licenses_screen () {
        $args = $this->args;
        $screen = !empty( $args[ 'screen' ] ) ? $args[ 'screen' ] : false;
        $this->screen_type = !empty( $screen[ 'parent' ] ) ? 'submenu' : 'menu';
        $this->icon_url = !empty( $screen[ 'icon_url' ] ) ? $screen[ 'icon_url' ] : '';
        $this->position = !empty( $screen[ 'position' ] ) ? $screen[ 'position' ] : 66;
        $this->menu_title = !empty( $screen[ 'menu_title' ] ) ? $screen[ 'menu_title' ] : __( 'Licenses', $this->domain );
        $this->page_title = !empty( $screen[ 'page_title' ] ) ? $screen[ 'page_title' ] : __( 'Licenses', $this->domain );
        $this->menu_slug = $this->plugin . '_' . sanitize_key( $this->page_title );
        
        switch( $this->screen_type ) {
          case 'menu':
            global $menu;
            $this->hook = add_menu_page( $this->page_title, $this->menu_title, 'manage_options', $this->menu_slug, array( $this, 'settings_screen' ), $this->icon_url, $this->position );
            break;
          case 'submenu':
            global $submenu;
            $this->hook = add_submenu_page( $screen[ 'parent' ], $this->page_title, $this->menu_title, 'manage_options', $this->menu_slug, array( $this, 'settings_screen' ) );
            break;
        }
        
        //** Set url for licenses page */
        $licenses_link = isset( $screen[ 'parent' ] ) && ( strpos( $screen[ 'parent' ], '?' ) !== false || strpos( $screen[ 'parent' ], '.php' ) !== false ) ? $screen[ 'parent' ] : 'admin.php';
        $licenses_link = add_query_arg( array(
          'page' => $this->menu_slug,
        ), network_admin_url( $licenses_link ) );
        
        update_option( $this->token . '-url', $licenses_link );
        
        add_action( 'load-' . $this->hook, array( $this, 'process_request' ) );
        add_action( 'admin_print_styles-' . $this->hook, array( $this, 'enqueue_styles' ) );
        add_action( 'admin_print_scripts-' . $this->hook, array( $this, 'enqueue_scripts' ) );
      }
      
      /**
       * Load the main management screen.
       *
       * @access public
       * @since   1.0.0
       * @return   void
       */
      public function settings_screen () {
        
        $this->ui->get_header();
        
        $screen = $this->ui->get_current_screen();
        $this->ensure_keys_are_actually_active();
        
        switch ( $screen ) {
          //** Products screen. */
          case 'more_products':
            $this->more_products = $this->get_more_products();
            require_once( $this->screens_path . 'screen-more.php' );
            break;
          //** Licenses screen. */
          case 'license':
          default:
            $this->installed_products = $this->get_detected_products();
            $this->pending_products = $this->get_pending_products();
            require_once( $this->screens_path . 'screen-manage.php' );
          break;
        }

        $this->ui->get_footer();
      }
      
      /**
       * Process the action for the admin screen.
       * @since  1.0.0
       * @return  void
       */
      public function process_request () {
      
        $notices_hook = is_multisite() ? 'network_admin_notices' : 'admin_notices';
        add_action( $notices_hook, array( $this, 'admin_notices' ) );
      
        $supported_actions = array( 'activate-products', 'deactivate-product' );
        if ( !isset( $_REQUEST['action'] ) || !in_array( $_REQUEST['action'], $supported_actions ) || !check_admin_referer( 'bulk-' . 'licenses' ) ) {
          return null;
        }
        
        $response = false;
        $status = 'false';
        $type = $_REQUEST['action'];

        switch ( $type ) {
          case 'activate-products':
            $products = array();
            if ( isset( $_POST[ 'products' ] ) && 0 < count( $_POST[ 'products' ] ) ) {
              foreach ( $_POST[ 'products' ] as $k => $v ) {
                if ( !empty( $v[ 'license_key' ] ) && !empty( $v[ 'activation_email' ] ) ) {
                  $products[$k] = $v;
                }
              }
            }
            if ( 0 < count( $products ) ) {
              //echo "<pre>"; print_r( $products ); echo "</pre>"; die();
              $response = $this->activate_products( $products );
            } else {
              $response = false;
              $type = 'no-license-keys';
            }
          break;

          case 'deactivate-product':
            if ( isset( $_GET['filepath'] ) && ( '' != $_GET['filepath'] ) ) {
              $response = $this->deactivate_product( $_GET['filepath'] );
            }
          break;

          default:
          break;
        }

        if ( $response == true ) {
          $status = 'true';
        }
        
        $redirect_url = \UsabilityDynamics\Utility::current_url( array( 'type' => urlencode( $type ), 'status' => urlencode( $status ) ), array( 'action', 'filepath', '_wpnonce' ) );
        wp_safe_redirect( $redirect_url );
        exit;
      }
      
      /**
       * Enqueue admin styles.
       * @access  public
       * @since   1.0.0
       * @return  void
       */
      public function enqueue_styles () {
        wp_enqueue_style( 'lib-ud-api-client-admin', esc_url( $this->assets_url . 'css/admin.css' ), array(), '1.0.0', 'all' );
      }
      
      /**
       * Enqueue admin scripts.
       *
       * @access  public
       * @since   1.0.0
       * @return  void
       */
      public function enqueue_scripts () {
        wp_enqueue_script( 'post' );
      }
      
      /**
       * Run checks against the API to ensure the product keys are actually active on UsabilityDynamics. If not, deactivate them locally as well.
       *
       * @access public
       * @since  1.0.0
       * @return void
       */
      public function ensure_keys_are_actually_active () {
        $already_active = (array)$this->get_activated_products();
        $products = $this->get_detected_products();
        if ( 0 < count( $already_active ) ) {
          foreach ( $already_active as $k => $v ) {
            $deactivate = true;
            if ( !empty( $already_active[ $k ][2] ) ) {
              //** Get license and activation email  */
              $data = base64_decode( $already_active[ $k ][2] );
              $data = explode( '::', $data );
              $license_key = isset( $data[0] ) ? trim( $data[0] ) : '';
              $activation_email = isset( $data[1] ) ? trim( $data[1] ) : '';

              //** Do request */
              $response = $this->api->status( array(
                'product_id' 	=> $already_active[ $k ][0],
                'instance' 		=> $already_active[ $k ][1],
                'email'       => trim($activation_email),
                'licence_key' => trim($license_key),
              ), false, false );
              
              //** Do not deactivate if cannot reach UD */
              if ( $response === false ) {
                continue;
              }
              if( is_array( $response ) && !empty( $response[ 'status_check' ] ) && $response[ 'status_check' ] == 'active' ) {
                $deactivate = false;
              }
            }
            if( $deactivate ) {
              $this->deactivate_product( $k, true );
            }
          }
        }
      }
      
      /**
       * Activate a given array of products.
       *
       * @since    1.0.0
       * @param    array   $products  Array of products ( filepath => key )
       * @return boolean
       */
      protected function activate_products ( $products ) {
        $response = true;
        $errors = false;
        //** Get out if we have incorrect data. */
        if ( !is_array( $products ) || ( 0 >= count( $products ) ) ) { 
          return false; 
        }
        $key = $this->token . '-activated';
        $has_update = false;
        $already_active = $this->get_activated_products();
        $product_keys = $this->get_detected_products();
        foreach ( $products as $k => $v ) {
          //echo "<pre>"; print_r( $product_keys[ $k ] ); echo "</pre>"; die();
          if( empty( $product_keys[ $k ] ) ) {
            continue;
          }
          //** Perform API "activation" request. */
          $activate = $this->api->activate( array(
            'product_id'        => $product_keys[ $k ][ 'product_id' ],
            'instance'          => $product_keys[ $k ][ 'instance_key' ],
            'software_version'  => $product_keys[ $k ][ 'product_version' ],
            'licence_key'       => trim($v[ 'license_key' ]),
            'email'             => trim($v[ 'activation_email' ]),
          ), $product_keys[ $k ] );
          if ( false !== $activate ) {
            // key: base file, 0: product id, 1: instance_key, 2: hashed license and mail.
            $hash = base64_encode( $v[ 'license_key' ] . '::' . $v[ 'activation_email' ] );
            $already_active[$k] = array( $product_keys[$k]['product_id'], $product_keys[$k]['instance_key'], $hash );
            $has_update = true;
          } else {
            $errors = true;
          }
        }

        //** Store the error log. */
        $this->api->store_error_log();

        if ( $has_update && !update_option( $key, $already_active ) ) {
          $response = false;
        } elseif( $errors ) {
          $response = false;
        }
        return $response;
      }
      
      /**
       * Deactivate a given product key.
       *
       * @since    1.0.0
       * @param    string $filename File name of the to deactivate plugin licence
       * @param    bool $local_only Deactivate the product locally without pinging UsabilityDynamics.
       * @return   boolean          Whether or not the deactivation was successful.
       */
      protected function deactivate_product ( $filename, $local_only = false ) {
        $response = false;
        $already_active = $this->get_activated_products();
        $products = $this->get_detected_products();
        if ( 0 < count( $already_active ) ) {
          $deactivated = true;
          if ( !empty( $products[ $filename ] ) && !empty( $already_active[ $filename ][2] ) && false == $local_only ) {
            //** Get license and activation email  */
            $data = base64_decode( $already_active[ $filename ][2] );
            $data = explode( '::', $data );
            $license_key = isset( $data[0] ) ? $data[0] : '';
            $activation_email = isset( $data[1] ) ? $data[1] : '';
            //** Do request */
            $deactivated = $this->api->deactivate( array(
              'product_id' 	=> $already_active[ $filename ][0],
              'instance' 		=> $already_active[ $filename ][1],
              'email'       => $activation_email,
              'licence_key' => $license_key,
            ), $products[ $filename ] );
            $deactivated = ( false !== $deactivated ) ? true : false;
          }
          if ( $deactivated ) {
            unset( $already_active[ $filename ] );
            $response = update_option( $this->token . '-activated', $already_active );
          } else {
            $this->api->store_error_log();
          }
        }
        return $response;
      }
      
      /**
       * Load an instance of the updater class for each activated UsabilityDynamics Product.
       * @access public
       * @since  1.0.0
       * @return void
       */
      public function load_updater_instances () {
        $products = $this->get_detected_products();
        $activated_products = $this->get_activated_products();
        if ( 0 < count( $products ) ) {
          foreach ( $products as $k => $v ) {
            if ( isset( $v['product_id'] ) && isset( $v['instance_key'] ) ) {
              //** Maybe Get license and activation email  */
              $api_key = '';
              $activation_email = '';
              if( !empty( $activated_products[ $k ][2] ) ) {
                $data = base64_decode( $activated_products[ $k ][2] );
                $data = explode( '::', $data );
                $api_key = isset( $data[0] ) ? $data[0] : '';
                $activation_email = isset( $data[1] ) ? $data[1] : '';
              }
              //echo "<pre>"; print_r( $v ); echo "</pre>"; die();
              new Update_Checker( array(
                'upgrade_url' => $this->api_url,
                'plugin_name' => $v[ 'product_name' ],
                'plugin_file' => $v[ 'product_file_path' ],
                'product_id' => $v[ 'product_id' ],
                'api_key' => $api_key,
                'activation_email' => $activation_email,
                'renew_license_url' => trailingslashit( $this->api_url ) . 'my-account',
                'instance' => $v[ 'instance_key' ],
                'software_version' => $v[ 'product_version' ],
                'text_domain' => $this->domain,
              ), $v[ 'errors_callback' ] );
            }
          }
        }
      }
      
      /**
       * Detect which products have been activated.
       *
       * @access public
       * @since   1.0.0
       * @return   void
       */
      protected function get_activated_products () {
        $response = array();
        $response = get_option( $this->token . '-activated', array() );
        if ( ! is_array( $response ) ) $response = array();
        return $response;
      }
      
      /**
       * Get a list of UsabilityDynamics products ( plugins ) found on this installation.
       *
       * @access public
       * @since   1.0.0
       * @return   void
       */
      protected function get_detected_products () {
        //** Check if get_plugins() function exists */
        if ( ! function_exists( 'get_plugins' ) ) {
          require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $response = array();
        $products = get_plugins();
        if ( is_array( $products ) && ( 0 < count( $products ) ) ) {
          $reference_list = $this->get_product_reference_list();
          //echo "<pre>"; print_r( $reference_list ); echo "</pre>"; die();
          $activated_products = $this->get_activated_products();
          if ( is_array( $reference_list ) && ( 0 < count( $reference_list ) ) ) {
            foreach ( $products as $k => $v ) {
              if ( in_array( $k, array_keys( $reference_list ) ) ) {
                $status = 'inactive';
                if ( in_array( $k, array_keys( $activated_products ) ) ) { 
                  $status = 'active'; 
                }
                $response[$k] = array( 
                  'product_name' => $v['Name'], 
                  'product_version' => $v['Version'], 
                  'instance_key' => $reference_list[$k]['instance_key'], 
                  'product_id' => $reference_list[$k]['product_id'],
                  'product_status' => $status, 
                  'product_file_path' => $k,
                  'errors_callback' => isset( $reference_list[$k]['errors_callback'] ) ? $reference_list[$k]['errors_callback'] : false,
                );
              }
            }
          }
        }
        return $response;
      }
      
      /**
       * Get a list of UsabilityDynamics plugins or themes which are available for purchasing and downloading.
       *
       * @since   1.0.0
       * @return mixed
       */
      protected function get_more_products() {
        $more_products = array();
        $trnst = get_transient( $this->token . "-more-a" );
        //** If we do not have cache ( transient ), do request to get the list of all available products */
        if( !$trnst || !is_array( $trnst ) ) {
          $target_url = !empty( $this->args[ 'products' ] ) ? $this->args[ 'products' ] : false;
          if( !$target_url ) {
            return $more_products;
          }
          $request = wp_remote_get( $target_url );
          if( is_wp_error( $request ) || wp_remote_retrieve_response_code( $request ) != 200 ) {
            return $more_products;
          } else {
            $response = wp_remote_retrieve_body( $request );
            $response = @json_decode( $response, true );
            if( empty( $response ) || !is_array( $response ) ) {
              return $more_products;
            } else {
              $locale = get_locale();
              $products = !empty( $response[ $locale ][ 'products' ] ) ? 
                $response[ $locale ][ 'products' ] : ( !empty( $response[ 'en_US' ][ 'products' ] ) ? $response[ 'en_US' ][ 'products' ] : false );
              if( empty( $products ) || !is_array( $products ) ) {
                return $more_products;
              }
              foreach( $products as $product ) {
                $product = wp_parse_args( $product, array(
                  'name' => '',
                  'description' => '',
                  'icon' => '',
                  'url' => '',
                  'type' => 'plugin',
                  'product_id' => '',
                  'referrer' => false,
                  'order' => 10,
                ) );
                if( !empty( $product[ 'referrer' ] ) && $product[ 'referrer' ] == $this->name ) {
                  $more_products[] = $product;
                }
              }
              //** Sort the list */
              usort( $more_products, create_function( '$a,$b', 'if ($a[\'order\'] == $b[\'order\']) { return 0; } return ($a[\'order\'] < $b[\'order\']) ? -1 : 1;' ) );
              //** Set transient for one day */
              set_transient( $this->token . "-more", $more_products, (60 * 60 * 24) );
            }
          }
        } else {
          $more_products = $trnst;
        }
        
        //** Determine if plugin from the list is already installed and activated */
        //** There is not check condition for 'theme' */
        if( !empty( $more_products ) ) {
          $reference_list = $this->get_product_reference_list();
          if( !empty( $reference_list ) && is_array( $reference_list ) ) {
            foreach( $more_products as $k => $product ) {
              foreach( $reference_list as $reference ) {
                if( $reference[ 'product_id' ] == $product[ 'product_id' ] ) {
                  unset( $more_products[ $k ] );
                }
              }
            }
          }
        }
        //echo "<pre>"; print_r( $more_products ); echo "</pre>"; die();
        return $more_products;
      }
      
      /**
       * Get a list of products from UsabilityDynamics.
       *
       * @access public
       * @since   1.0.0
       * @return   void
       */
      protected function get_product_reference_list () {
        global $_ud_license_updater;
        //echo "<pre>"; print_r( $_ud_license_updater ); echo "</pre>"; die();
        $response = array();
        if( 
          isset( $_ud_license_updater[ $this->plugin ] ) 
          && is_callable( array( $_ud_license_updater[ $this->plugin ], 'get_products' ) ) 
        ) {
          $response = $_ud_license_updater[ $this->plugin ]->get_products();
        }
        return $response;
      }
      
      /**
       * Get an array of products that haven't yet been activated.
       *
       * @access public
       * @since   1.0.0
       * @return  array Products awaiting activation.
       */
      protected function get_pending_products () {
        $response = array();
        $products = $this->installed_products;
        if ( is_array( $products ) && ( 0 < count( $products ) ) ) {
          $activated_products = $this->get_activated_products();
          if ( is_array( $activated_products ) && ( 0 <= count( $activated_products ) ) ) {
            foreach ( $products as $k => $v ) {
              if ( !in_array( $k, array_keys( $activated_products ) ) ) {
                $response[$k] = array( 'product_name' => $v['product_name'] );
              }
            }
          }
        }
        //echo "<pre>"; print_r( $response ); echo "</pre>"; die();
        return $response;
      }
      
      /**
       * Determine, if there are licenses that are not yet activated.
       * @access  public
       * @since   1.0.0
       * @return  void
       */
      public function check_activation_status () {
        $licenses_link = get_option( $this->token . '-url', '' );
        //echo "<pre>"; print_r( $this ); echo "</pre>"; die();
        $products = $this->get_detected_products();
        //echo "<pre>"; print_r( $products ); echo "</pre>"; die();
        $messages = array();
        if ( 0 < count( $products ) ) {
          foreach ( $products as $k => $v ) {
            if ( isset( $v['product_status'] ) && 'inactive' == $v['product_status'] ) {
              if( !empty( $licenses_link ) ) {
                $message = sprintf( __( '%s License is not active. To get started, activate it <a href="%s">here</a>.', $this->domain ), $v['product_name'], $licenses_link );
              } else {
                $message = sprintf( __( '%s License is not active.', $this->domain ), $v['product_name'] );
              }
              if( !empty( $v[ 'errors_callback' ] ) && is_callable( $v[ 'errors_callback' ] ) ) {
                call_user_func( $v[ 'errors_callback' ], $message );
              } else {
                $messages[] = $message;
              }
            }
          }
        }
        if( !empty( $messages ) ) {
          $this->messages = $messages;
        }
      }
      
      /**
       * Remove specific plugins notices from licenses page
       *
       * @param $notices
       * @author peshkov@UD
       */
      public function maybe_remove_notices( $notices ) {
        global $current_screen;
        if( $current_screen->id == $this->hook ) {
          $notices = array();
        }
        return $notices;
      }
      
      /**
       * Admin notices
       */
      public function admin_notices() {
        
        //** Step 1. Look for default messages */
        $messages = $this->messages;
        if( !empty( $messages ) && is_array( $messages ) ) {
          foreach( $messages as $message ) {
            echo '<div class="error fade"><p>' . $message . '</p></div>';
          }
        }
        
        //** Step 2. Look for status messages */
        $message = '';
        $response = '';

        if ( isset( $_GET['status'] ) && in_array( $_GET['status'], array( 'true', 'false' ) ) && isset( $_GET['type'] ) ) {
          $classes = array( 'true' => 'updated', 'false' => 'error' );
          $request_errors = $this->api->get_error_log();

          //echo "<pre>"; var_dump( $request_errors ); echo "</pre>"; die();
          
          switch ( $_GET['type'] ) {
            case 'no-license-keys':
              $message = __( 'No license keys were specified for activation.', $this->domain );
            break;

            case 'deactivate-product':
              if ( 'true' == $_GET['status'] && empty( $request_errors ) ) {
                $message = __( 'Product deactivated successfully.', $this->domain );
              } else {
                $message = __( 'There was an error while deactivating the product.', $this->domain );
              }
            break;

            default:
              if ( 'true' == $_GET['status'] && empty( $request_errors ) ) {
                $message = __( 'Products activated successfully.', $this->domain );
              } else {
                $message = __( 'There was an error and not all products were activated.', $this->domain );
              }
            break;
          }

          $response = '<div class="' . esc_attr( $classes[$_GET['status']] ) . ' fade">' . "\n";
          $response .= wpautop( $message );
          $response .= '</div>' . "\n";

          // Cater for API request error logs.
          if ( is_array( $request_errors ) && ( 0 < count( $request_errors ) ) ) {
            $message = '';

            foreach ( $request_errors as $k => $v ) {
              $message .= wpautop( $v );
            }

            $response .= '<div class="error fade">' . "\n";
            $response .= make_clickable( $message );
            $response .= '</div>' . "\n";

            // Clear the error log.
            $this->api->clear_error_log();
          }

          if ( '' != $response ) {
            echo $response;
          }
        }        
        
      }
      
    }
  
  }
  
}