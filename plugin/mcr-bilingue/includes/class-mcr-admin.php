<?php
/**
 * Bilingual editing in wp-admin.
 *
 * A side-by-side panel, mirroring how the source artifact's back office worked:
 * French on the left as written, English beside it.
 *
 * @package MCR_Bilingue
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin UI for translations.
 */
final class MCR_Admin {

	/**
	 * Singleton.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Nonce action.
	 */
	private const NONCE = 'mcrb_save_translation';

	/**
	 * Singleton accessor.
	 */
	public static function instance(): self {
		return self::$instance ??= new self();
	}

	/**
	 * Private constructor.
	 */
	private function __construct() {}

	/**
	 * Hook up.
	 */
	public function init(): void {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post', array( $this, 'save_post' ), 10, 2 );

		add_action( 'product_cat_edit_form_fields', array( $this, 'term_fields' ), 10, 2 );
		add_action( 'edited_product_cat', array( $this, 'save_term' ) );
		add_action( 'edit_term', array( $this, 'save_term' ) );

		add_filter( 'manage_product_posts_columns', array( $this, 'add_column' ) );
		add_action( 'manage_product_posts_custom_column', array( $this, 'render_column' ), 10, 2 );

		add_action( 'admin_enqueue_scripts', array( $this, 'styles' ) );
	}

	/**
	 * Which post types get the panel.
	 *
	 * @return string[]
	 */
	private function post_types(): array {
		return apply_filters( 'mcrb_translatable_post_types', array( 'product', 'page', 'post' ) );
	}

	/**
	 * Register the meta box.
	 */
	public function add_meta_box(): void {
		foreach ( $this->post_types() as $post_type ) {
			add_meta_box(
				'mcrb-translation',
				__( 'English translation', 'mcr-bilingue' ),
				array( $this, 'render_meta_box' ),
				$post_type,
				'normal',
				'high'
			);
		}
	}

	/**
	 * Render the translation panel.
	 *
	 * @param WP_Post $post Post being edited.
	 */
	public function render_meta_box( WP_Post $post ): void {
		wp_nonce_field( self::NONCE, 'mcrb_nonce' );

		$languages = MCR_Languages::instance()->languages();
		$default   = MCR_Languages::instance()->default_code();

		echo '<p class="description">';
		esc_html_e( 'Leave a field empty to fall back to the French text. Stock, price and SKU are shared — there is only one product record.', 'mcr-bilingue' );
		echo '</p>';

		foreach ( $languages as $code => $language ) {
			if ( $code === $default ) {
				continue;
			}

			foreach ( MCR_Translator::post_fields() as $field => $label ) {
				$value  = MCR_Translator::get_post_field( $post->ID, $field, $code );
				$source = $this->source_value( $post, $field );
				$id     = 'mcrb-' . $code . '-' . $field;

				echo '<div class="mcrb-field">';
				printf(
					'<label for="%s"><strong>%s</strong> <span class="mcrb-lang">%s</span></label>',
					esc_attr( $id ),
					esc_html( $label ),
					esc_html( $language['label'] )
				);

				if ( '' !== trim( $source ) ) {
					printf(
						'<p class="mcrb-source"><span>%s</span> %s</p>',
						esc_html__( 'French:', 'mcr-bilingue' ),
						esc_html( wp_trim_words( wp_strip_all_tags( $source ), 30 ) )
					);
				}

				if ( 'content' === $field ) {
					wp_editor( $value, $id, array(
						'textarea_name' => 'mcrb[' . $code . '][' . $field . ']',
						'textarea_rows' => 8,
						'media_buttons' => false,
						'teeny'         => true,
					) );
				} elseif ( 'excerpt' === $field ) {
					printf(
						'<textarea id="%s" name="mcrb[%s][%s]" rows="3" class="widefat">%s</textarea>',
						esc_attr( $id ),
						esc_attr( $code ),
						esc_attr( $field ),
						esc_textarea( $value )
					);
				} else {
					printf(
						'<input type="text" id="%s" name="mcrb[%s][%s]" value="%s" class="widefat">',
						esc_attr( $id ),
						esc_attr( $code ),
						esc_attr( $field ),
						esc_attr( $value )
					);
				}

				echo '</div>';
			}
		}
	}

	/**
	 * The French text for a field, shown as context.
	 *
	 * @param WP_Post $post  Post.
	 * @param string  $field Field key.
	 */
	private function source_value( WP_Post $post, string $field ): string {
		return match ( $field ) {
			'title'   => $post->post_title,
			'excerpt' => $post->post_excerpt,
			'content' => $post->post_content,
			default   => '',
		};
	}

	/**
	 * Persist submitted translations.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 */
	public function save_post( int $post_id, $post ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! isset( $_POST['mcrb_nonce'] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( (string) $_POST['mcrb_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( ! isset( $_POST['mcrb'] ) || ! is_array( $_POST['mcrb'] ) ) {
			return;
		}

		// Unslashed per field below; each setter sanitizes for its own type.
		$submitted = wp_unslash( $_POST['mcrb'] ); // phpcs:ignore WordPress.Security.ValidationSanitization.InputNotSanitized

		foreach ( $submitted as $language => $fields ) {
			$language = sanitize_key( (string) $language );

			if ( ! MCR_Languages::instance()->is_valid( $language ) || ! is_array( $fields ) ) {
				continue;
			}

			foreach ( $fields as $field => $value ) {
				$field = sanitize_key( (string) $field );

				if ( ! array_key_exists( $field, MCR_Translator::post_fields() ) ) {
					continue;
				}

				MCR_Translator::set_post_field( $post_id, $field, $language, (string) $value );
			}
		}
	}

	/**
	 * Translation fields on the category edit screen.
	 *
	 * @param WP_Term $term     Term.
	 * @param string  $taxonomy Taxonomy.
	 */
	public function term_fields( $term, $taxonomy = '' ): void {
		if ( ! $term instanceof WP_Term ) {
			return;
		}

		wp_nonce_field( self::NONCE, 'mcrb_nonce' );

		$default = MCR_Languages::instance()->default_code();

		foreach ( MCR_Languages::instance()->languages() as $code => $language ) {
			if ( $code === $default ) {
				continue;
			}

			foreach ( MCR_Translator::term_fields() as $field => $label ) {
				$value = MCR_Translator::get_term_field( $term->term_id, $field, $code );
				$id    = 'mcrb-term-' . $code . '-' . $field;
				?>
				<tr class="form-field">
					<th scope="row">
						<label for="<?php echo esc_attr( $id ); ?>">
							<?php
							printf(
								/* translators: 1: field label, 2: language label. */
								esc_html__( '%1$s (%2$s)', 'mcr-bilingue' ),
								esc_html( $label ),
								esc_html( $language['label'] )
							);
							?>
						</label>
					</th>
					<td>
						<?php if ( 'description' === $field ) : ?>
							<textarea id="<?php echo esc_attr( $id ); ?>"
							          name="mcrb_term[<?php echo esc_attr( $code ); ?>][<?php echo esc_attr( $field ); ?>]"
							          rows="4" cols="40"><?php echo esc_textarea( $value ); ?></textarea>
						<?php else : ?>
							<input type="text" id="<?php echo esc_attr( $id ); ?>"
							       name="mcrb_term[<?php echo esc_attr( $code ); ?>][<?php echo esc_attr( $field ); ?>]"
							       value="<?php echo esc_attr( $value ); ?>" size="40">
						<?php endif; ?>

						<?php if ( 'slug' === $field ) : ?>
							<p class="description">
								<?php esc_html_e( 'Used in the English URL. Leave empty to reuse the French slug.', 'mcr-bilingue' ); ?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
				<?php
			}
		}
	}

	/**
	 * Persist term translations.
	 *
	 * @param int $term_id Term ID.
	 */
	public function save_term( int $term_id ): void {
		if ( ! isset( $_POST['mcrb_nonce'] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( (string) $_POST['mcrb_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_categories' ) ) {
			return;
		}

		if ( ! isset( $_POST['mcrb_term'] ) || ! is_array( $_POST['mcrb_term'] ) ) {
			return;
		}

		$submitted = wp_unslash( $_POST['mcrb_term'] ); // phpcs:ignore WordPress.Security.ValidationSanitization.InputNotSanitized

		foreach ( $submitted as $language => $fields ) {
			$language = sanitize_key( (string) $language );

			if ( ! MCR_Languages::instance()->is_valid( $language ) || ! is_array( $fields ) ) {
				continue;
			}

			foreach ( $fields as $field => $value ) {
				$field = sanitize_key( (string) $field );

				if ( ! array_key_exists( $field, MCR_Translator::term_fields() ) ) {
					continue;
				}

				MCR_Translator::set_term_field( $term_id, $field, $language, (string) $value );
			}
		}
	}

	/**
	 * Add a translation-status column to the products list.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public function add_column( array $columns ): array {
		$columns['mcrb_status'] = __( 'EN', 'mcr-bilingue' );
		return $columns;
	}

	/**
	 * Render the translation-status column.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 */
	public function render_column( string $column, int $post_id ): void {
		if ( 'mcrb_status' !== $column ) {
			return;
		}

		$percent = MCR_Translator::post_completeness( $post_id, 'en' );

		$state = 100 === $percent ? 'done' : ( 0 === $percent ? 'none' : 'partial' );

		printf(
			'<span class="mcrb-pill mcrb-pill--%s">%s</span>',
			esc_attr( $state ),
			100 === $percent ? esc_html__( 'Complete', 'mcr-bilingue' ) : esc_html( $percent . '%' )
		);
	}

	/**
	 * Minimal admin styling.
	 *
	 * @param string $hook Current admin page.
	 */
	public function styles( string $hook ): void {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php', 'edit.php', 'term.php', 'edit-tags.php' ), true ) ) {
			return;
		}

		$css = '
			.mcrb-field{margin-bottom:18px}
			.mcrb-field label{display:block;margin-bottom:5px}
			.mcrb-lang{display:inline-block;margin-left:6px;padding:1px 7px;border-radius:9px;
				background:#2271b1;color:#fff;font-size:11px;font-weight:600;vertical-align:middle}
			.mcrb-source{margin:0 0 6px;padding:7px 10px;background:#f6f7f7;border-left:3px solid #c3c4c7;
				color:#50575e;font-size:12px}
			.mcrb-source span{font-weight:600}
			.mcrb-pill{display:inline-block;padding:2px 9px;border-radius:9px;font-size:11px;font-weight:600}
			.mcrb-pill--done{background:#edfaef;color:#00450c}
			.mcrb-pill--partial{background:#fcf9e8;color:#674600}
			.mcrb-pill--none{background:#f6f7f7;color:#787c82}
			.column-mcrb_status{width:80px}
		';

		wp_register_style( 'mcrb-admin', false, array(), MCRB_VERSION );
		wp_enqueue_style( 'mcrb-admin' );
		wp_add_inline_style( 'mcrb-admin', $css );
	}
}
