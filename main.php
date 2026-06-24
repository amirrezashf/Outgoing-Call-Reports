<?php
/**
 * Plugin Name: Outgoing Call Reports
 * Plugin URI: https://github.com/amirrezashf/outgoing-call-reports
 * Description: Adds a private outgoing call reporting system to WordPress with dashboard submission, custom post type, call status tracking, custom columns, and role-based access control.
 * Version: 1.0.0
 * Author: Amirreza Shayesteh Far
 * Author URI: https://amirrezaa.ir/
 * License: GPL v2 or later
 * Text Domain: outgoing-call-reports
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OCR_Outgoing_Call_Reports {

	const VERSION   = '1.0.0';
	const POST_TYPE = 'outgoing_calls';
	const NONCE     = 'ocr_submit_outgoing_call_nonce';
	const ACTION    = 'ocr_submit_outgoing_call';

	public function __construct() {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'wp_dashboard_setup', array( $this, 'register_dashboard_widget' ) );
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'handle_ajax_submit' ) );

		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'add_custom_columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'render_custom_columns' ), 10, 2 );
		add_filter( 'manage_edit-' . self::POST_TYPE . '_sortable_columns', array( $this, 'sortable_columns' ) );
		add_filter( 'post_row_actions', array( $this, 'remove_row_actions' ), 10, 2 );

		add_action( 'admin_init', array( $this, 'block_direct_edit_pages' ) );
		add_action( 'admin_init', array( $this, 'backfill_missing_call_status' ) );
	}

	public static function activate() {
		self::add_capabilities();
		flush_rewrite_rules();
	}

	public static function deactivate() {
		flush_rewrite_rules();
	}

	private static function get_capabilities() {
		return array(
			'manage_outgoing_calls',
			'publish_outgoing_calls',
			'edit_outgoing_calls',
			'edit_others_outgoing_calls',
			'delete_outgoing_calls',
			'delete_others_outgoing_calls',
			'read_private_outgoing_calls',
			'delete_private_outgoing_calls',
			'delete_published_outgoing_calls',
		);
	}

	private static function add_capabilities() {
		$roles = array( 'administrator', 'editor' );

		foreach ( $roles as $role_name ) {
			$role = get_role( $role_name );

			if ( ! $role ) {
				continue;
			}

			foreach ( self::get_capabilities() as $capability ) {
				$role->add_cap( $capability );
			}
		}
	}

	public function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels' => array(
					'name'          => 'گزارش تماس خروجی',
					'singular_name' => 'گزارش تماس',
					'menu_name'     => 'گزارش تماس خروجی',
					'all_items'     => 'همه گزارش‌های تماس',
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => current_user_can( 'manage_outgoing_calls' ),
				'menu_icon'       => 'dashicons-phone',
				'supports'        => array( 'title', 'editor' ),
				'capability_type' => 'outgoing_call',
				'map_meta_cap'    => true,
				'capabilities'    => array(
					'publish_posts'         => 'publish_outgoing_calls',
					'edit_posts'            => 'edit_outgoing_calls',
					'edit_others_posts'     => 'edit_others_outgoing_calls',
					'delete_posts'          => 'delete_outgoing_calls',
					'delete_others_posts'   => 'delete_others_outgoing_calls',
					'read_private_posts'    => 'read_private_outgoing_calls',
					'delete_private_posts'  => 'delete_private_outgoing_calls',
					'delete_published_posts'=> 'delete_published_outgoing_calls',
				),
			)
		);
	}

	public function backfill_missing_call_status() {
		if ( ! current_user_can( 'manage_outgoing_calls' ) ) {
			return;
		}

		$query = new WP_Query(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => '_call_status',
						'compare' => 'NOT EXISTS',
					),
				),
				'no_found_rows'  => true,
			)
		);

		if ( empty( $query->posts ) ) {
			return;
		}

		foreach ( $query->posts as $post_id ) {
			update_post_meta( $post_id, '_call_status', 'نامشخص' );
		}
	}

	public function add_custom_columns( $columns ) {
		$columns['request_type'] = 'نوع تماس';
		$columns['call_status']  = 'وضعیت تماس';
		$columns['description']  = 'توضیحات';
		$columns['submitted_by'] = 'ارسال شده توسط';

		return $columns;
	}

	public function render_custom_columns( $column, $post_id ) {
		if ( 'request_type' === $column ) {
			$value = get_post_meta( $post_id, '_request_type', true );
			echo esc_html( $value ? $value : 'نامشخص' );
			return;
		}

		if ( 'call_status' === $column ) {
			$value = get_post_meta( $post_id, '_call_status', true );
			echo esc_html( $value ? $value : 'نامشخص' );
			return;
		}

		if ( 'description' === $column ) {
			$content = get_post_field( 'post_content', $post_id );
			echo esc_html( wp_trim_words( $content, 80 ) );
			return;
		}

		if ( 'submitted_by' === $column ) {
			$value = get_post_meta( $post_id, '_submitted_by', true );
			echo esc_html( $value ? $value : 'نامشخص' );
		}
	}

	public function sortable_columns( $columns ) {
		$columns['request_type'] = 'request_type';
		$columns['call_status']  = 'call_status';
		$columns['submitted_by'] = 'submitted_by';

		return $columns;
	}

	public function remove_row_actions( $actions, $post ) {
		if ( self::POST_TYPE === $post->post_type ) {
			unset( $actions['edit'], $actions['inline hide-if-no-js'] );
		}

		return $actions;
	}

	public function block_direct_edit_pages() {
		if (
			isset( $_GET['post'], $_GET['action'] ) &&
			'edit' === $_GET['action'] &&
			self::POST_TYPE === get_post_type( absint( $_GET['post'] ) )
		) {
			wp_safe_redirect( admin_url( 'edit.php?post_type=' . self::POST_TYPE ) );
			exit;
		}

		if (
			isset( $_GET['post_type'] ) &&
			self::POST_TYPE === $_GET['post_type'] &&
			isset( $_SERVER['REQUEST_URI'] ) &&
			false !== strpos( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), 'post-new.php' )
		) {
			wp_safe_redirect( admin_url( 'edit.php?post_type=' . self::POST_TYPE ) );
			exit;
		}
	}

	public function register_dashboard_widget() {
		if ( ! current_user_can( 'publish_outgoing_calls' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'ocr_outgoing_calls_widget',
			'گزارش تماس خروجی',
			array( $this, 'render_dashboard_widget' )
		);
	}

	public function render_dashboard_widget() {
		?>
		<form id="ocr-outgoing-calls-form">
			<p>
				<label for="ocr-call-title">عنوان (حداکثر ۵۰ کاراکتر):</label>
				<input type="text" id="ocr-call-title" name="title" maxlength="50" style="width:100%;" required>
			</p>

			<p>
				<label for="ocr-call-description">توضیحات (حداکثر ۵۰۰ کاراکتر):</label>
				<textarea id="ocr-call-description" name="description" maxlength="500" style="width:100%;" rows="5" required></textarea>
			</p>

			<p>
				<label>نوع تماس:</label>
				<?php $this->render_radio_list( 'request_type', $this->get_request_types() ); ?>
			</p>

			<p>
				<label>وضعیت تماس:</label>
				<?php $this->render_radio_list( 'call_status', $this->get_call_statuses() ); ?>
			</p>

			<button type="submit" class="button button-primary">ارسال گزارش</button>
			<div id="ocr-call-message" style="margin-top:10px;"></div>
		</form>

		<script>
		(function($){
			$('#ocr-outgoing-calls-form').on('submit', function(e){
				e.preventDefault();

				var message = $('#ocr-call-message');
				message.text('در حال ارسال...');

				$.post(ajaxurl, {
					action: '<?php echo esc_js( self::ACTION ); ?>',
					title: $('#ocr-call-title').val(),
					description: $('#ocr-call-description').val(),
					request_type: $('input[name="request_type"]:checked').val(),
					call_status: $('input[name="call_status"]:checked').val(),
					_ajax_nonce: '<?php echo esc_js( wp_create_nonce( self::NONCE ) ); ?>'
				}, function(response){
					if (response.success) {
						message.text('گزارش با موفقیت ثبت شد.');
						$('#ocr-outgoing-calls-form')[0].reset();
					} else {
						message.text('خطا: ' + (response.data && response.data.message ? response.data.message : 'خطا در ثبت گزارش.'));
					}
				}).fail(function(){
					message.text('خطا در ارسال گزارش.');
				});
			});
		})(jQuery);
		</script>
		<?php
	}

	private function render_radio_list( $name, $items ) {
		echo '<ul style="list-style:none;padding:0;margin:8px 0;">';

		foreach ( $items as $item ) {
			echo '<li style="margin-bottom:6px;">';
			echo '<label>';
			echo '<input type="radio" name="' . esc_attr( $name ) . '" value="' . esc_attr( $item ) . '" required> ';
			echo esc_html( $item );
			echo '</label>';
			echo '</li>';
		}

		echo '</ul>';
	}

	private function get_request_types() {
		return array(
			'محصول مجدد موجود شده',
			'ریکاوری سبد خرید پرداخت نشده',
			'پیشنهاد محصولات و خدمات',
			'امور منابع انسانی و استخدام',
			'خریداری که سوال پیش از خرید داشته و ثبت سفارش نکرده',
			'یوزری که داخل سایت ثبت نام داشته و ثبت سفارش نکرده',
			'یوزر قدیمی / هپی کال',
			'یوزر پر خرید / هپی کال',
			'پیگیری تمدید سفارش',
			'سایر موارد',
		);
	}

	private function get_call_statuses() {
		return array(
			'تماس برقرار نشد (خاموش یا بدون آنتن یا اختلال تلفن)',
			'شماره اشغال بود (ممکنه خود کاربر تماس بگیره)',
			'تماس با موفقیت برقرار شد',
		);
	}

	public function handle_ajax_submit() {
		check_ajax_referer( self::NONCE );

		if ( ! current_user_can( 'publish_outgoing_calls' ) ) {
			wp_send_json_error(
				array(
					'message' => 'دسترسی لازم برای ثبت گزارش را ندارید.',
				)
			);
		}

		$title        = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$description  = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';
		$request_type = isset( $_POST['request_type'] ) ? sanitize_text_field( wp_unslash( $_POST['request_type'] ) ) : '';
		$call_status  = isset( $_POST['call_status'] ) ? sanitize_text_field( wp_unslash( $_POST['call_status'] ) ) : '';

		if ( '' === $title ) {
			wp_send_json_error( array( 'message' => 'عنوان الزامی است.' ) );
		}

		if ( '' === $description ) {
			wp_send_json_error( array( 'message' => 'توضیحات الزامی است.' ) );
		}

		if ( '' === $request_type ) {
			wp_send_json_error( array( 'message' => 'انتخاب نوع تماس الزامی است.' ) );
		}

		if ( '' === $call_status ) {
			wp_send_json_error( array( 'message' => 'انتخاب وضعیت تماس الزامی است.' ) );
		}

		if ( ! in_array( $request_type, $this->get_request_types(), true ) ) {
			wp_send_json_error( array( 'message' => 'نوع تماس معتبر نیست.' ) );
		}

		if ( ! in_array( $call_status, $this->get_call_statuses(), true ) ) {
			wp_send_json_error( array( 'message' => 'وضعیت تماس معتبر نیست.' ) );
		}

		$current_user = wp_get_current_user();

		$post_id = wp_insert_post(
			array(
				'post_type'    => self::POST_TYPE,
				'post_title'   => mb_substr( $title, 0, 50 ),
				'post_content' => mb_substr( $description, 0, 500 ),
				'post_status'  => 'publish',
				'post_author'  => $current_user->ID,
				'meta_input'   => array(
					'_submitted_by' => $current_user->display_name,
					'_request_type' => $request_type,
					'_call_status'  => $call_status,
				),
			)
		);

		if ( $post_id && ! is_wp_error( $post_id ) ) {
			wp_send_json_success( array( 'message' => 'گزارش با موفقیت ثبت شد.' ) );
		}

		wp_send_json_error( array( 'message' => 'خطایی در ثبت گزارش رخ داد.' ) );
	}
}

register_activation_hook( __FILE__, array( 'OCR_Outgoing_Call_Reports', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'OCR_Outgoing_Call_Reports', 'deactivate' ) );

new OCR_Outgoing_Call_Reports();
