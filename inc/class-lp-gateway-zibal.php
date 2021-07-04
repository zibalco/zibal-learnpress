<?php
/**
 * Zibal payment gateway class.
 *
 * @author   Yahya Kangi
 * @link 	 https://zibal.ir
 * @package  LearnPress/Zibal/Classes
 * @version  1.1
 */
// session_start();

// Prevent loading this file directly
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'LP_Gateway_Zibal' ) ) {
	/**
	 * Class LP_Gateway_Zibal
	 */
	class LP_Gateway_Zibal extends LP_Gateway_Abstract {

		/**
		 * @var array
		 */
		private $form_data = array();
		
		/**
		 * @var string
		 */
		private $startPay = 'https://gateway.zibal.ir/start/';
		
		/**
		 * @var string
		 */
		private $verifyUrl = 'https://gateway.zibal.ir/v1/verify/';
		
		/**
		 * @var string
		 */
		private $restPaymentRequestUrl = 'https://gateway.zibal.ir/v1/request/';
		
		/**
		 * @var string
		 */
		private $restPaymentVerification = 'https://gateway.zibal.ir/v1/verify/';
		
		/**
		 * @var string
		 */
		private $soap = false;
		
		/**
		 * @var string
		 */
		private $merchant = null;

		/**
		 * @var array|null
		 */
		protected $settings = null;

		/**
		 * @var null
		 */
		protected $order = null;

		/**
		 * @var null
		 */
		protected $posted = null;

		/**
		 *
		 * @var string
		 */
		protected $trackId = null;

		/**
		 * LP_Gateway_Zibal constructor.
		 */
		public function __construct() {
			$this->id = 'zibal';

			$this->method_title       =  __( 'Zibal', 'learnpress-zibal' );;
			$this->method_description = __( 'Make a payment with Zibal.', 'learnpress-zibal' );
			$this->icon               = '';

			// Get settings
			$this->title       = LP()->settings->get( "{$this->id}.title", $this->method_title );
			$this->description = LP()->settings->get( "{$this->id}.description", $this->method_description );

			$settings = LP()->settings;

			// Add default values for fresh installs
			if ( $settings->get( "{$this->id}.enable" ) ) {
				$this->settings                     = array();
				$this->settings['merchant']        = $settings->get( "{$this->id}.merchant" );
			}
			
			$this->merchant = $this->settings['merchant'];
			
			if ( did_action( 'learn_press/zibal-add-on/loaded' ) ) {
				return;
			}

			// check payment gateway enable
			add_filter( 'learn-press/payment-gateway/' . $this->id . '/available', array(
				$this,
				'zibal_available'
			), 10, 2 );

			do_action( 'learn_press/zibal-add-on/loaded' );

			parent::__construct();
			
			// web hook
			if ( did_action( 'init' ) ) {
				$this->register_web_hook();
			} else {
				add_action( 'init', array( $this, 'register_web_hook' ) );
			}
			add_action( 'learn_press_web_hooks_processed', array( $this, 'web_hook_process_zibal' ) );
			
			add_action("learn-press/before-checkout-order-review", array( $this, 'error_message' ));
		}
		
		/**
		 * Register web hook.
		 *
		 * @return array
		 */
		public function register_web_hook() {
			learn_press_register_web_hook( 'zibal', 'learn_press_zibal' );
		}
			
		/**
		 * Admin payment settings.
		 *
		 * @return array
		 */
		public function get_settings() {

			return apply_filters( 'learn-press/gateway-payment/zibal/settings',
				array(
					array(
						'title'   => __( 'Enable', 'learnpress-zibal' ),
						'id'      => '[enable]',
						'default' => 'no',
						'type'    => 'yes-no'
					),
					array(
						'type'       => 'text',
						'title'      => __( 'Title', 'learnpress-zibal' ),
						'default'    => __( 'Zibal', 'learnpress-zibal' ),
						'id'         => '[title]',
						'class'      => 'regular-text',
						'visibility' => array(
							'state'       => 'show',
							'conditional' => array(
								array(
									'field'   => '[enable]',
									'compare' => '=',
									'value'   => 'yes'
								)
							)
						)
					),
					array(
						'type'       => 'textarea',
						'title'      => __( 'Description', 'learnpress-zibal' ),
						'default'    => __( 'Pay with Zibal', 'learnpress-zibal' ),
						'id'         => '[description]',
						'editor'     => array(
							'textarea_rows' => 5
						),
						'css'        => 'height: 100px;',
						'visibility' => array(
							'state'       => 'show',
							'conditional' => array(
								array(
									'field'   => '[enable]',
									'compare' => '=',
									'value'   => 'yes'
								)
							)
						)
					),
					array(
						'title'      => __( 'Merchant ID', 'learnpress-zibal' ),
						'id'         => '[merchant]',
						'type'       => 'text',
						'visibility' => array(
							'state'       => 'show',
							'conditional' => array(
								array(
									'field'   => '[enable]',
									'compare' => '=',
									'value'   => 'yes'
								)
							)
						)
					)
				)
			);
		}

		/**
		 * Payment form.
		 */
		public function get_payment_form() {
			ob_start();
			$template = learn_press_locate_template( 'form.php', learn_press_template_path() . '/addons/zibal-payment/', LP_ADDON_ZIBAL_PAYMENT_TEMPLATE );
			include $template;

			return ob_get_clean();
		}

		/**
		 * Error message.
		 *
		 * @return array
		 */
		public function error_message() {
			if(!isset($_SESSION))
				session_start();
			if(isset($_SESSION['zibal_error']) && intval($_SESSION['zibal_error']) === 1) {
				$_SESSION['zibal_error'] = 0;
				$template = learn_press_locate_template( 'payment-error.php', learn_press_template_path() . '/addons/zibal-payment/', LP_ADDON_ZIBAL_PAYMENT_TEMPLATE );
				include $template;
			}
		}
		
		/**
		 * @return mixed
		 */
		public function get_icon() {
			if ( empty( $this->icon ) ) {
				$this->icon = LP_ADDON_ZIBAL_PAYMENT_URL . 'assets/images/zibal.png';
			}

			return parent::get_icon();
		}

		/**
		 * Check gateway available.
		 *
		 * @return bool
		 */
		public function zibal_available() {

			if ( LP()->settings->get( "{$this->id}.enable" ) != 'yes' ) {
				return false;
			}

			return true;
		}
		
		/**
		 * Get form data.
		 *
		 * @return array
		 */
		public function get_form_data() {
			if ( $this->order ) {
				$user            = learn_press_get_current_user();
				$currency_code = learn_press_get_currency()  ;
				if ($currency_code == 'IRR') {
					$amount = $this->order->order_total;
				} else {
					$amount = $this->order->order_total * 10;
				}

				$this->form_data = array(
					'amount'      => $amount,
					'currency'    => strtolower( learn_press_get_currency() ),
					'token'       => $this->token,
					'description' => sprintf( __("Charge for %s","learnpress-zibal"), $user->get_data( 'email' ) ),
					'customer'    => array(
						'name'          => $user->get_data( 'display_name' ),
						'billing_email' => $user->get_data( 'email' ),
					),
					'errors'      => isset( $this->posted['form_errors'] ) ? $this->posted['form_errors'] : ''
				);
			}

			return $this->form_data;
		}
		
		/**
		 * Validate form fields.
		 *
		 * @return bool
		 * @throws Exception
		 * @throws string
		 */
		public function validate_fields() {
			$posted        = learn_press_get_request( 'learn-press-zibal' );
			$email   = !empty( $posted['email'] ) ? $posted['email'] : "";
			$mobile  = !empty( $posted['mobile'] ) ? $posted['mobile'] : "";
			$error_message = array();
			if ( !empty( $email ) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
				$error_message[] = __( 'Invalid email format.', 'learnpress-zibal' );
			}
			if ( !empty( $mobile ) && !preg_match("/^(09)(\d{9})$/", $mobile)) {
				$error_message[] = __( 'Invalid mobile format.', 'learnpress-zibal' );
			}
			
			if ( $error = sizeof( $error_message ) ) {
				throw new Exception( sprintf( '<div>%s</div>', join( '</div><div>', $error_message ) ), 8000 );
			}
			$this->posted = $posted;

			return $error ? false : true;
		}
		
		/**
		 * Zibal payment process.
		 *
		 * @param $order
		 *
		 * @return array
		 * @throws string
		 */
		public function process_payment( $order ) {
			$this->order = learn_press_get_order( $order );
			$zibal = $this->get_zibal_trackId();
			$gateway_url = $this->startPay.$this->trackId;
			$json = array(
				'result'   => $zibal ? 'success' : 'fail',
				'redirect'   => $zibal ? $gateway_url : ''
			);

			return $json;
		}

		
		/**
		 * Get Zibal trackId.
		 *
		 * @return bool|object
		 * @throws string
		 */
		public function get_zibal_trackId() {
			if ( $this->get_form_data() ) {
				$checkout = LP()->checkout();
				$data = [
					'merchant' => $this->merchant,
					'amount' => $this->form_data['amount'],
					'description' => $this->form_data['description'],
					// 'Email' => (!empty($this->posted['email'])) ? $this->posted['email'] : "",
					'mobile' => (!empty($this->posted['mobile'])) ? $this->posted['mobile'] : "",
					'callbackUrl' => get_site_url() . '/?' . learn_press_get_web_hook( 'zibal' ) . '=1&order_id='.$this->order->get_id(),
				];
								
				$result = $this->rest_payment_request($data);
				
				if($result->result == 100) {
					$this->trackId = $result->trackId;
					return true;
				}
			}
			return false;
		}

		/**
		 * Handle a web hook
		 *
		 */
		public function web_hook_process_zibal() {
			$request = $_REQUEST;
			if(isset($request['learn_press_zibal']) && intval($request['learn_press_zibal']) === 1) {
				if ($request['status'] == '2') {
					$order = LP_Order::instance( $request['order_id'] );
					$currency_code = learn_press_get_currency();
					if ($currency_code == 'IRR') {
						$amount = $order->order_total;
					} else {
						$amount = $order->order_total * 10;
					}	
					
					$data = array(
						'merchant' => $this->merchant,
						'trackId' => $_GET['trackId'],
					);

					$result = $this->rest_payment_verification($data);

					if($result->result == 100 || $result->result == 201) {
						if($amount == $result->amount) {
							$this->trackId = intval($_GET['trackId']);
							$this->payment_status_completed($order , $request);
							wp_redirect(esc_url( $this->get_return_url( $order ) ));
							exit();
						}
					}
				}
				
				if(!isset($_SESSION))
					session_start();
				$_SESSION['zibal_error'] = 1;
				
				wp_redirect(esc_url( learn_press_get_page_link( 'checkout' ) ));
				exit();
			}
		}
		
		/**
		 * Zibal REST payment request
		 *
		 */ 
		public function rest_payment_request($data) {
			$jsonData = json_encode($data);
			$ch = curl_init($this->restPaymentRequestUrl);
			curl_setopt($ch, CURLOPT_USERAGENT, 'Zibal Rest Api v1');
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
			curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_HTTPHEADER, array(
				'Content-Type: application/json',
				'Content-Length: ' . strlen($jsonData)
			));
			$result = curl_exec($ch);
			$err = curl_error($ch);
			$result = json_decode($result);
			curl_close($ch);
			if ($err) {
				$result = (object) array("success"=>0);
			}
			return $result;
		}
		
		/**
		 * Zibal REST payment verification
		 *
		 */ 
		public function rest_payment_verification($data) {
			$jsonData = json_encode($data);
			$ch = curl_init($this->restPaymentVerification);
			curl_setopt($ch, CURLOPT_USERAGENT, 'Zibal Rest Api v1');
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
			curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_HTTPHEADER, array(
				'Content-Type: application/json',
				'Content-Length: ' . strlen($jsonData)
			));
			$result = curl_exec($ch);
			$err = curl_error($ch);
			$result = json_decode($result);
			curl_close($ch);
			if ($err) {
				$result = (object) array("success"=>0);
			}
			return $result;
		}
		
		/**
		 * Handle a completed payment
		 *
		 * @param LP_Order
		 * @param request
		 */
		protected function payment_status_completed( $order, $request ) {

			// order status is already completed
			if ( $order->has_status( 'completed' ) ) {
				exit;
			}

			$this->payment_complete( $order, ( !empty( $request['trackId'] ) ? $request['trackId'] : '' ), __( 'Payment has been successfully completed', 'learnpress-zibal' ) );
			update_post_meta( $order->get_id(), '_zibal_trackId', $request['trackId'] );
			update_post_meta( $order->get_id(), '_zibal_trackId', $request['trackId'] );
		}

		/**
		 * Handle a pending payment
		 *
		 * @param  LP_Order
		 * @param  request
		 */
		protected function payment_status_pending( $order, $request ) {
			$this->payment_status_completed( $order, $request );
		}

		/**
		 * @param        LP_Order
		 * @param string $txn_id
		 * @param string $note - not use
		 */
		public function payment_complete( $order, $trans_id = '', $note = '' ) {
			$order->payment_complete( $trans_id );
		}
	}
}