<?php
/**
 * Make search find products by their English names too.
 *
 * Without this, an English-speaking customer searching "palm oil" finds
 * nothing, because only "huile de palme" is in post_title.
 *
 * @package MCR_Bilingue
 */

defined( 'ABSPATH' ) || exit;

/**
 * Extends the search query across translated meta.
 */
final class MCR_Search {

	/**
	 * Singleton.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

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
		add_filter( 'posts_join', array( $this, 'join_meta' ), 10, 2 );
		add_filter( 'posts_where', array( $this, 'search_meta' ), 10, 2 );
		add_filter( 'posts_distinct', array( $this, 'distinct' ), 10, 2 );
	}

	/**
	 * Whether to extend this query.
	 *
	 * @param WP_Query $query Query.
	 */
	private function applies( $query ): bool {
		return $query instanceof WP_Query
			&& $query->is_search()
			&& $query->is_main_query()
			&& ! is_admin();
	}

	/**
	 * Join the postmeta table.
	 *
	 * @param string   $join  JOIN clause.
	 * @param WP_Query $query Query.
	 */
	public function join_meta( string $join, $query ): string {
		global $wpdb;

		if ( ! $this->applies( $query ) ) {
			return $join;
		}

		$join .= " LEFT JOIN {$wpdb->postmeta} AS mcrb_meta ON ( {$wpdb->posts}.ID = mcrb_meta.post_id AND mcrb_meta.meta_key LIKE '" . esc_sql( MCR_Translator::META_PREFIX ) . "%' ) ";

		return $join;
	}

	/**
	 * Widen the WHERE clause to include translated values.
	 *
	 * @param string   $where WHERE clause.
	 * @param WP_Query $query Query.
	 */
	public function search_meta( string $where, $query ): string {
		global $wpdb;

		if ( ! $this->applies( $query ) ) {
			return $where;
		}

		$terms = $query->get( 's' );
		if ( ! is_string( $terms ) || '' === trim( $terms ) ) {
			return $where;
		}

		$like = '%' . $wpdb->esc_like( $terms ) . '%';

		/*
		 * Graft an OR onto WordPress's own title/content search rather than
		 * rebuilding it, so stopwords, sentence handling and any other plugin's
		 * changes to the clause survive.
		 */
		$where = preg_replace(
			"#\({$wpdb->posts}.post_title LIKE ([^)]+)\)#",
			"({$wpdb->posts}.post_title LIKE $1) OR (mcrb_meta.meta_value LIKE %s)",
			$where,
			1
		);

		if ( null === $where ) {
			return (string) $where;
		}

		// The placeholder above is filled here, once, safely.
		return (string) $wpdb->prepare( $where, $like ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Avoid duplicate rows from the meta join.
	 *
	 * @param string   $distinct DISTINCT clause.
	 * @param WP_Query $query    Query.
	 */
	public function distinct( string $distinct, $query ): string {
		return $this->applies( $query ) ? 'DISTINCT' : $distinct;
	}
}
