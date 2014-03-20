<?php
/**
 * @package One
 */

if ( ! function_exists( 'TTF_One_Builder_Save' ) ) :
/**
 * Defines the functionality for the HTML Builder.
 *
 * @since 1.0.
 */
class TTF_One_Builder_Save {
	/**
	 * The one instance of TTF_One_Builder_Save.
	 *
	 * @since 1.0.
	 *
	 * @var   TTF_One_Builder_Save
	 */
	private static $instance;

	/**
	 * A variable for tracking the current section being processed.
	 *
	 * @since 1.0.
	 *
	 * @var   int
	 */
	private $_current_section_number = 0;

	/**
	 * Instantiate or return the one TTF_One_Builder_Save instance.
	 *
	 * @since  1.0.
	 *
	 * @return TTF_One_Builder_Save
	 */
	public static function instance() {
		if ( is_null( self::$instance ) )
			self::$instance = new self();

		return self::$instance;
	}

	/**
	 * Initiate actions.
	 *
	 * @since  1.0.
	 *
	 * @return TTF_One_Builder_Save
	 */
	public function __construct() {
		add_action( 'save_post', array( $this, 'save_post' ), 10, 2 );

		// Combine the input into the post's content
		add_filter( 'wp_insert_post_data', array( $this, 'wp_insert_post_data' ), 30, 2 );
	}

	/**
	 * Save the gallery IDs and order.
	 *
	 * @since  1.0.
	 *
	 * @param  int        $post_id    The ID of the current post.
	 * @param  WP_Post    $post       The post object for the current post.
	 * @return void
	 */
	public function save_post( $post_id, $post ) {
		// Don't do anything during autosave
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Only check permissions for pages since it can only run on pages
		if ( ! current_user_can( 'edit_page', $post_id ) ) {
			return;
		}

		// Run the product builder routine maybe
		if ( isset( $_POST[ 'ttf-one-builder-nonce' ] ) && wp_verify_nonce( $_POST[ 'ttf-one-builder-nonce' ], 'save' ) ) {
			// Process and save data
			$sanitized_sections = $this->prepare_data();
			update_post_meta( $post_id, '_ttf-one-sections', $sanitized_sections );

			// Save the value of the hide/show header variable
			if ( isset( $_POST['ttf-one-hide-header'] ) ) {
				$value       = $_POST['ttf-one-hide-header'];
				$clean_value = ( in_array( $value, array( 0, 1 ) ) ) ? (int) $value : 0;

				// Only save it if necessary
				if ( 1 === $clean_value ) {
					update_post_meta( $post_id, '_ttf-one-hide-header', 1 );
				} else {
					delete_post_meta( $post_id, '_ttf-one-hide-header' );
				}
			} else {
				delete_post_meta( $post_id, '_ttf-one-hide-header' );
			}
		}
	}

	/**
	 * Validate and sanitize the builder section data.
	 *
	 * @since  1.0.
	 *
	 * @return array    Array of cleaned section data.
	 */
	public function prepare_data() {
		$sections = array();

		foreach ( ttf_one_get_sections() as $section ) {
			$sections[] = call_user_func( $section['save_callback'] );
		}

		return $sections;
	}

	/**
	 * Interpret the order input into meaningful order data.
	 *
	 * @since  1.0.
	 *
	 * @param  string    $input    The order string.
	 * @return array               Array of order values.
	 */
	public function process_order( $input ) {
		$input = str_replace( 'ttf-one-section-', '', $input );
		return explode( ',', $input );
	}

	/**
	 * On post save, use a theme template to generate content from metadata.
	 *
	 * @since  1.0.
	 *
	 * @param  array    $data       The processed post data.
	 * @param  array    $postarr    The raw post data.
	 * @return array                Modified post data.
	 */
	public function wp_insert_post_data( $data, $postarr ) {
		$product_submit   = ( isset( $_POST[ 'ttf-one-builder-nonce' ] ) && wp_verify_nonce( $_POST[ 'ttf-one-builder-nonce' ], 'save' ) );

		if ( ! $product_submit ) {
			return $data;
		}

		// Don't do anything during autosave
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return $data;
		}

		// Only check permissions for pages since it can only run on pages
		if ( ! current_user_can( 'edit_page', get_the_ID() ) ) {
			return $data;
		}

		// Verify that the page template param is set
		if ( ! isset( $_POST['page_template'] ) || ! in_array( $_POST['page_template'], array( 'product.php', 'slideshow.php' ) ) ) {
			return $data;
		}

		// Run the product builder routine maybe
		$sanitized_sections = $this->prepare_data( 'product' );

		// The data has been deleted and can be removed
		if ( empty( $sanitized_sections ) ) {
			$data['post_content'] = '';
			return $data;
		}

		// Remove editor image constraints while rendering section data.
		add_filter( 'editor_max_image_size', array( &$this, 'remove_image_constraints' ) );

		// Start the output buffer to collect the contents of the templates
		ob_start();

		global $ttf_one_sanitized_sections;
		$ttf_one_sanitized_sections = $sanitized_sections;

		// Verify that the section counter is reset
		$this->_current_section_number = 0;

		// For each sections, render it using the template
		foreach ( $sanitized_sections as $section ) {
			global $ttf_one_section_data;
			$ttf_one_section_data = $section;

			// Get the template for the section
			get_template_part( '_section', $section['section-type'] );

			// Note the change in section number
			$this->_current_section_number++;

			// Cleanup the global
			unset( $GLOBALS['ttf_one_section_data'] );
		}

		// Cleanup the global
		unset( $GLOBALS['ttf_one_sanitized_sections'] );

		// Reset the counter
		$this->_current_section_number = 0;

		// Get the rendered templates from the output buffer
		$post_content = ob_get_clean();

		// Allow constraints again after builder data processing is complete.
		remove_filter( 'editor_max_image_size', array( &$this, 'remove_image_constraints' ) );

		// Sanitize and set the content
		$data['post_content'] = sanitize_post_field( 'post_content', $post_content, get_the_ID(), 'db' );

		return $data;
	}

	/**
	 * Allows image size to be saved regardless of the content width variable.
	 *
	 * @since  1.0.
	 *
	 * @param  array    $dimensions    The default dimensions.
	 * @return array                   The modified dimensions.
	 */
	public function remove_image_constraints( $dimensions ) {
		return array( 9999, 9999 );
	}

	/**
	 * Get the next section's data.
	 *
	 * @since  1.0.
	 *
	 * @return array    The next section's data.
	 */
	public function get_next_section_data() {
		global $ttf_one_sanitized_sections;

		// Get the next section number
		$section_to_get = $this->_current_section_number + 1;

		// If the section does not exist, the current section is the last section
		if ( isset( $ttf_one_sanitized_sections[ $section_to_get ] ) ) {
			return $ttf_one_sanitized_sections[ $section_to_get ];
		} else {
			return array();
		}
	}

	/**
	 * Get the previous section's data.
	 *
	 * @since  1.0.
	 *
	 * @return array    The previous section's data.
	 */
	public function get_prev_section_data() {
		global $ttf_one_sanitized_sections;

		// Get the next section number
		$section_to_get = $this->_current_section_number - 1;

		// If the section does not exist, the current section is the last section
		if ( isset( $ttf_one_sanitized_sections[ $section_to_get ] ) ) {
			return $ttf_one_sanitized_sections[ $section_to_get ];
		} else {
			return array();
		}
	}

	/**
	 * Prepare the classes need for a section.
	 *
	 * Includes the name of the current section type, the next section type and the previous section type. It will also
	 * denote if a section is the first or last section.
	 *
	 * @since  1.0.
	 *
	 * @return string
	 */
	public function section_classes() {
		global $ttf_one_sanitized_sections;

		// Get the current section type
		$current = ( isset( $ttf_one_sanitized_sections[ $this->_current_section_number ]['section-type'] ) ) ? $ttf_one_sanitized_sections[ $this->_current_section_number ]['section-type'] : '';

		// Get the next section's type
		$next_data = $this->get_next_section_data();
		$next = ( ! empty( $next_data ) && isset( $next_data['section-type'] ) ) ? 'next-' . $next_data['section-type'] : 'last';

		// Get the previous section's type
		$prev_data = $this->get_prev_section_data();
		$prev = ( ! empty( $prev_data ) && isset( $prev_data['section-type'] ) ) ? 'prev-' . $prev_data['section-type'] : 'first';

		// Return the values as a single string
		return $prev . ' ' . $current . ' ' . $next;
	}

	/**
	 * Duplicate of "the_content" with custom filter name for generating content in builder templates.
	 *
	 * @since  1.0.
	 *
	 * @param  string    $content    The original content.
	 * @return void
	 */
	public function the_builder_content( $content ) {
		$content = apply_filters( 'ttf_one_the_builder_content', $content );
		$content = str_replace( ']]>', ']]&gt;', $content );
		echo $content;
	}

	/**
	 * Get the order for a feature section.
	 *
	 * @since  1.0.
	 *
	 * @param  array    $data    The section data.
	 * @return array             The desired order.
	 */
	public function get_featured_section_order( $data ) {
		$order = array(
			'image' => 'left',
			'text'  => 'right',
		);

		if ( isset( $data['order'] ) ) {
			if ( isset( $data['order'][0] ) && false !== strpos( $data['order'][0], 'text' ) ) {
				$order = array(
					'image' => 'right',
					'text'  => 'left',
				);
			}
		}

		return $order;
	}
}
endif;

/**
 * Instantiate or return the one TTF_One_Builder_Save instance.
 *
 * @since  1.0.
 *
 * @return TTF_One_Builder_Save
 */
function ttf_one_get_builder_save() {
	return TTF_One_Builder_Save::instance();
}

add_action( 'admin_init', 'ttf_one_get_builder_save' );