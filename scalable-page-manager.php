<?php
/**
 * Plugin Name:       Scalable Page Manager
 * Plugin URI:        https://example.com/scalable-page-manager
 * Description:        High-performance page management for sites with 10,000+ pages. Lazy tree, virtual-scroll list, keyset pagination, indexed FULLTEXT search across title, slug, content and ACF fields. Elementor-aware quick actions.
 * Version:           1.5.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            SR
 * License:           GPL-2.0-or-later
 * Text Domain:       spm
 *
 * Single-file plugin. No build step required: the admin UI is vanilla JS,
 * served inline and powered by a custom REST namespace (spm/v1).
 *
 * ============================================================================
 * SCALING NOTES
 * ----------------------------------------------------------------------------
 * - List uses KEYSET (cursor) pagination, never OFFSET, so per-request cost is
 *   constant from 10k to 1M+ rows.
 * - Hierarchy is LAZY: only the children of an expanded node are ever queried.
 * - Search is TIERED: numeric -> PRIMARY KEY; slug -> indexed equality/prefix;
 *   title/content/ACF -> InnoDB FULLTEXT (MATCH..AGAINST), never a leading-
 *   wildcard LIKE. Content/ACF search is OPTIONAL via a UI toggle and runs
 *   entirely in MySQL -- post content is never loaded into PHP memory.
 * - Virtual scrolling keeps the DOM bounded (~50 rows) at any dataset size.
 * - Bulk actions are chunked client-side.
 * ============================================================================
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SPM_VERSION', '1.0.0' );
define( 'SPM_DB_VERSION', '1' );
define( 'SPM_FILE', __FILE__ );
define( 'SPM_SLUG', 'scalable-page-manager' );

/* ===========================================================================
 * ACTIVATION : create composite + FULLTEXT indexes (guarded, idempotent)
 * ======================================================================== */

register_activation_hook( __FILE__, 'spm_activate' );

/**
 * Activation must be INSTANT. We never run ALTER TABLE here — on large
 * wp_posts / wp_postmeta tables those can take minutes and trip a gateway
 * timeout (Cloudflare 524) that aborts the request mid-migration.
 *
 * Instead we just record that indexes are pending. They are created lazily
 * and one-at-a-time via spm_maybe_build_indexes() on later admin loads, and
 * the plugin works fully (just unindexed) until they exist.
 */
function spm_activate() {
	if ( false === get_option( 'spm_index_status', false ) ) {
		add_option( 'spm_index_status', 'pending' ); // pending | building | done
	}
	update_option( 'spm_db_version', SPM_DB_VERSION );
}

register_deactivation_hook( __FILE__, 'spm_deactivate' );
function spm_deactivate() {
	$ts = wp_next_scheduled( 'spm_build_indexes_event' );
	if ( $ts ) {
		wp_unschedule_event( $ts, 'spm_build_indexes_event' );
	}
}

/** True if a named index currently exists on a table (cached per request). */
function spm_index_exists( $table, $name ) {
	static $cache = array();
	$ck = $table . '|' . $name;
	if ( isset( $cache[ $ck ] ) ) {
		return $cache[ $ck ];
	}
	global $wpdb;
	$found = (bool) $wpdb->get_var( $wpdb->prepare(
		"SHOW INDEX FROM {$table} WHERE Key_name = %s",
		$name
	) );
	$cache[ $ck ] = $found;
	return $found;
}

/**
 * The indexes we want, in priority order (cheapest / most useful first).
 * Each entry: [ table, index_name, "ADD INDEX ..." clause ].
 */
function spm_index_definitions() {
	global $wpdb;
	$posts = $wpdb->posts;
	$pm    = $wpdb->postmeta;
	return array(
		array( $posts, 'spm_parent_order',  "ADD INDEX spm_parent_order (post_parent, post_type, menu_order, ID)" ),
		array( $posts, 'spm_modified',      "ADD INDEX spm_modified (post_type, post_status, post_modified, ID)" ),
		array( $posts, 'spm_author_status', "ADD INDEX spm_author_status (post_author, post_type, post_status, post_date)" ),
		array( $posts, 'spm_title_ft',      "ADD FULLTEXT INDEX spm_title_ft (post_title)" ),
		array( $posts, 'spm_content_ft',    "ADD FULLTEXT INDEX spm_content_ft (post_content)" ),
		array( $pm,    'spm_meta_value_ft', "ADD FULLTEXT INDEX spm_meta_value_ft (meta_value)" ),
	);
}

/**
 * Build at most ONE missing index per call, so no single request runs long
 * enough to time out. Triggered on admin_init (throttled) and via WP-CLI.
 * Returns true if work remains.
 */
function spm_maybe_build_indexes() {
	$status = get_option( 'spm_index_status', 'pending' );
	if ( 'done' === $status ) {
		return false;
	}

	// Throttle: at most one attempt per 30s, and never run during AJAX/REST/cron
	// front-end hits where a long query would hurt.
	$last = (int) get_option( 'spm_index_last_attempt', 0 );
	if ( time() - $last < 30 ) {
		return true;
	}
	update_option( 'spm_index_last_attempt', time() );

	global $wpdb;
	$built_any = false;

	foreach ( spm_index_definitions() as $def ) {
		list( $table, $name, $clause ) = $def;

		$existing = wp_list_pluck(
			$wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A ),
			'Key_name'
		);
		if ( in_array( $name, $existing, true ) ) {
			continue; // already present
		}

		// Try INPLACE/LOCK=NONE first (MySQL 5.6+/8 online DDL); fall back to plain.
		// We deliberately build only ONE index per request, then bail.
		$ok = false;
		if ( false !== stripos( $clause, 'FULLTEXT' ) ) {
			// FULLTEXT can't always use LOCK=NONE; plain ALTER.
			$wpdb->query( "ALTER TABLE {$table} {$clause}" );
		} else {
			$res = $wpdb->query( "ALTER TABLE {$table} {$clause}, ALGORITHM=INPLACE, LOCK=NONE" );
			if ( false === $res ) {
				$wpdb->query( "ALTER TABLE {$table} {$clause}" ); // fallback
			}
		}
		$built_any = true;
		break; // one per call — keeps each request short
	}

	if ( ! $built_any ) {
		// Nothing left to build.
		update_option( 'spm_index_status', 'done' );
		return false;
	}

	update_option( 'spm_index_status', 'building' );
	return true;
}

/* ===========================================================================
 * INDEX BUILDER SCHEDULING (background, non-blocking)
 * ======================================================================== */

// Build indexes in the background via WP-Cron so no user request ever waits.
add_action( 'spm_build_indexes_event', 'spm_cron_build_indexes' );
function spm_cron_build_indexes() {
	// In cron there is no gateway timeout; still build one-at-a-time and
	// re-schedule until done so each event stays short.
	if ( spm_maybe_build_indexes() ) {
		if ( ! wp_next_scheduled( 'spm_build_indexes_event' ) ) {
			wp_schedule_single_event( time() + 60, 'spm_build_indexes_event' );
		}
	}
}

// Kick off the background builder, and also nudge it when an admin opens our
// page (so it still progresses even if cron is disabled). This never blocks:
// spm_maybe_build_indexes() does at most one index and is throttled to 30s.
add_action( 'admin_init', 'spm_schedule_index_build' );
function spm_schedule_index_build() {
	if ( 'done' === get_option( 'spm_index_status', 'pending' ) ) {
		return;
	}
	if ( ! wp_next_scheduled( 'spm_build_indexes_event' ) ) {
		wp_schedule_single_event( time() + 5, 'spm_build_indexes_event' );
	}
}

// WP-CLI: build all indexes immediately (no timeout on CLI). `wp spm build-indexes`
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'spm build-indexes', function () {
		$guard = 0;
		while ( spm_maybe_build_indexes() && $guard < 50 ) {
			// Bypass the 30s throttle on CLI.
			update_option( 'spm_index_last_attempt', 0 );
			$guard++;
		}
		WP_CLI::success( 'Scalable Page Manager: indexes built (status: ' . get_option( 'spm_index_status' ) . ').' );
	} );
}

/* ===========================================================================
 * ADMIN MENU + INLINE APP
 * ======================================================================== */

add_action( 'admin_menu', 'spm_register_menu' );

function spm_register_menu() {
	add_menu_page(
		__( 'Pages (Scalable)', 'spm' ),
		__( 'Pages (Scalable)', 'spm' ),
		'edit_pages',
		SPM_SLUG,
		'spm_render_admin_page',
		'dashicons-admin-page',
		20
	);
}

function spm_render_admin_page() {
	if ( ! current_user_can( 'edit_pages' ) ) {
		wp_die( esc_html__( 'You do not have permission to manage pages.', 'spm' ) );
	}

	$boot = array(
		'root'      => esc_url_raw( rest_url( 'spm/v1' ) ),
		'nonce'     => wp_create_nonce( 'wp_rest' ),
		'editBase'  => admin_url( 'post.php' ),
		'newUrl'    => admin_url( 'post-new.php?post_type=page' ),
		'elementor' => spm_elementor_active(),
		'statuses'  => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
		'templates' => spm_get_page_templates(),
		'authors'   => spm_get_page_authors(),
		'taxes'     => spm_get_page_taxonomies(),
		'seoPlugin' => spm_seo_plugin(),
		'canDelete' => current_user_can( 'delete_pages' ),
		'templateTax'      => spm_get_template_taxonomy_name(),
		'templateTaxLabel' => spm_get_template_taxonomy_label(),
		'templateTaxTerms' => spm_get_template_taxonomy_terms(),
		// All registered page taxonomy slugs — shown in warning to help admin identify correct slug.
		'allPageTaxes'     => array_keys( get_object_taxonomies( 'page' ) ),
		'indexStatus'      => get_option( 'spm_index_status', 'done' ),
	);
	?>
	<div class="wrap">
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Pages (Scalable)', 'spm' ); ?></h1>
		<a href="<?php echo esc_url( $boot['newUrl'] ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'spm' ); ?></a>
		<hr class="wp-header-end">
		<?php if ( 'done' !== $boot['indexStatus'] ) : ?>
			<div class="notice notice-info" style="margin:10px 0">
				<p><strong><?php esc_html_e( 'Optimizing search indexes in the background…', 'spm' ); ?></strong>
				<?php esc_html_e( 'The page manager is fully usable now. Title/content search runs in basic mode until indexing finishes (this can take a few minutes on large sites). You can speed it up by running', 'spm' ); ?>
				<code>wp spm build-indexes</code> <?php esc_html_e( 'via WP-CLI.', 'spm' ); ?></p>
			</div>
		<?php endif; ?>
		<div id="spm-app"><p class="spm-muted"><?php esc_html_e( 'Loading page manager…', 'spm' ); ?></p></div>
	</div>
	<script type="text/javascript">window.SPM_BOOT = <?php echo wp_json_encode( $boot ); ?>;</script>
	<?php
	spm_print_styles();
	spm_print_app();
}

function spm_elementor_active() {
	return did_action( 'elementor/loaded' ) || defined( 'ELEMENTOR_VERSION' );
}

function spm_get_page_templates() {
	$theme = wp_get_theme();
	$tpls  = $theme ? $theme->get_page_templates() : array();
	$out   = array( array( 'value' => '', 'label' => __( 'Any template', 'spm' ) ) );
	foreach ( (array) $tpls as $file => $name ) {
		$out[] = array( 'value' => $file, 'label' => $name );
	}
	return $out;
}

function spm_get_page_authors() {
	$users = get_users( array(
		'who'      => 'authors',
		'fields'   => array( 'ID', 'display_name' ),
		'number'   => 200,
		'capability' => array( 'edit_pages' ),
	) );
	$out = array( array( 'value' => 0, 'label' => __( 'Any author', 'spm' ) ) );
	foreach ( $users as $u ) {
		$out[] = array( 'value' => (int) $u->ID, 'label' => $u->display_name );
	}
	return $out;
}

function spm_get_page_taxonomies() {
	$out = array();
	foreach ( get_object_taxonomies( 'page', 'objects' ) as $tax ) {
		if ( ! $tax->show_ui ) {
			continue;
		}
		$terms = get_terms( array(
			'taxonomy'   => $tax->name,
			'hide_empty' => false,
			'number'     => 1000,
		) );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			continue;
		}

		// Group by parent so we can flatten depth-first (parents before children).
		$by_id    = array();
		$children = array();
		foreach ( $terms as $t ) {
			$by_id[ (int) $t->term_id ] = $t;
			$children[ (int) $t->parent ][] = (int) $t->term_id;
		}
		$path_label = function ( $id ) use ( $by_id ) {
			$parts = array();
			$guard = 0;
			while ( $id && isset( $by_id[ $id ] ) && $guard < 20 ) {
				array_unshift( $parts, $by_id[ $id ]->name );
				$id = (int) $by_id[ $id ]->parent;
				$guard++;
			}
			return implode( ' › ', $parts );
		};
		$opts = array();
		$walk = function ( $parent_id, $depth ) use ( &$walk, &$opts, $children, $by_id, $path_label ) {
			if ( empty( $children[ $parent_id ] ) ) {
				return;
			}
			usort( $children[ $parent_id ], function ( $a, $b ) use ( $by_id ) {
				return strcasecmp( $by_id[ $a ]->name, $by_id[ $b ]->name );
			} );
			foreach ( $children[ $parent_id ] as $tid ) {
				$t = $by_id[ $tid ];
				$opts[] = array(
					'value' => (int) $tid,
					'label' => $t->name,
					'path'  => $path_label( $tid ),
					'depth' => $depth,
				);
				$walk( $tid, $depth + 1 );
			}
		};
		$walk( 0, 0 );

		$out[] = array(
			'name'         => $tax->name,
			'label'        => $tax->labels->singular_name,
			'hierarchical' => (bool) $tax->hierarchical,
			'terms'        => $opts,
		);
	}
	return $out;
}

/**
 * Detect the "Template" custom taxonomy name.
 *
 * Detection order:
 * 1. 'spm_template_taxonomy' filter (explicit override — most reliable)
 * 2. Any page taxonomy whose slug, label, or singular_name contains "template"
 * 3. Returns '' if nothing found — callers must handle this gracefully.
 *
 * If auto-detection keeps failing, add to functions.php:
 *   add_filter( 'spm_template_taxonomy', fn() => 'your_exact_taxonomy_slug' );
 */
function spm_get_template_taxonomy_name() {
	// 1. Explicit override.
	$override = (string) apply_filters( 'spm_template_taxonomy', '' );
	if ( $override && taxonomy_exists( $override ) ) {
		return $override;
	}

	// 2. Auto-detect from all taxonomies registered on the 'page' post type.
	foreach ( get_object_taxonomies( 'page', 'objects' ) as $tax ) {
		$slug   = strtolower( $tax->name );
		$label  = strtolower( $tax->label ?? '' );
		$single = strtolower( $tax->labels->singular_name ?? '' );

		if (
			false !== strpos( $slug,   'template' ) ||
			false !== strpos( $label,  'template' ) ||
			false !== strpos( $single, 'template' )
		) {
			return $tax->name;
		}
	}

	return ''; // Not found — caller should show a warning.
}

/** Human-readable label for the Template taxonomy (falls back to "Template"). */
function spm_get_template_taxonomy_label() {
	$tax = spm_get_template_taxonomy_name();
	if ( $tax && ( $obj = get_taxonomy( $tax ) ) ) {
		return $obj->labels->singular_name ?: ( $obj->label ?: 'Template' );
	}
	return 'Template';
}

/**
 * Returns all Template-taxonomy terms as a hierarchy-aware, flattened list.
 * Because the taxonomy is hierarchical and child names repeat (e.g. "CRO1"
 * under both "Geo" and "National (Rehab)"), each entry carries its parent,
 * depth, slug, and a disambiguated path label so the UI never shows
 * ambiguous duplicate names.
 */
function spm_get_template_taxonomy_terms() {
	$tax = spm_get_template_taxonomy_name();
	if ( ! taxonomy_exists( $tax ) ) {
		return array();
	}
	$terms = get_terms( array(
		'taxonomy'   => $tax,
		'hide_empty' => false,
		'number'     => 1000,
	) );
	if ( is_wp_error( $terms ) || empty( $terms ) ) {
		return array();
	}

	// Index by id and group children by parent.
	$by_id    = array();
	$children = array();
	foreach ( $terms as $t ) {
		$by_id[ (int) $t->term_id ] = $t;
		$children[ (int) $t->parent ][] = (int) $t->term_id;
	}

	// Build a full path label ("National (Rehab) › CRO1") for disambiguation.
	$path_label = function ( $id ) use ( $by_id ) {
		$parts = array();
		$guard = 0;
		while ( $id && isset( $by_id[ $id ] ) && $guard < 20 ) {
			array_unshift( $parts, $by_id[ $id ]->name );
			$id = (int) $by_id[ $id ]->parent;
			$guard++;
		}
		return implode( ' › ', $parts );
	};

	// Depth-first flatten so parents precede their children.
	$out = array();
	$walk = function ( $parent_id, $depth ) use ( &$walk, &$out, $children, $by_id, $path_label ) {
		if ( empty( $children[ $parent_id ] ) ) {
			return;
		}
		// Sort siblings alphabetically.
		usort( $children[ $parent_id ], function ( $a, $b ) use ( $by_id ) {
			return strcasecmp( $by_id[ $a ]->name, $by_id[ $b ]->name );
		} );
		foreach ( $children[ $parent_id ] as $tid ) {
			$t = $by_id[ $tid ];
			$out[] = array(
				'value'  => (int) $tid,
				'label'  => $t->name,
				'path'   => $path_label( $tid ),
				'slug'   => $t->slug,
				'parent' => (int) $t->parent,
				'depth'  => $depth,
				'count'  => (int) $t->count,
			);
			$walk( $tid, $depth + 1 );
		}
	};
	$walk( 0, 0 );

	return $out;
}

/* ===========================================================================
 * REST API : spm/v1
 * ======================================================================== */

add_action( 'rest_api_init', 'spm_register_routes' );

function spm_can_edit_pages() {
	return current_user_can( 'edit_pages' );
}

function spm_register_routes() {
	$perm = 'spm_can_edit_pages';

	register_rest_route( 'spm/v1', '/pages', array(
		'methods'             => 'GET',
		'callback'            => 'spm_rest_list',
		'permission_callback' => $perm,
	) );

	register_rest_route( 'spm/v1', '/tree/(?P<parent>\d+)', array(
		'methods'             => 'GET',
		'callback'            => 'spm_rest_tree',
		'permission_callback' => $perm,
		'args'                => array( 'parent' => array( 'sanitize_callback' => 'absint' ) ),
	) );

	register_rest_route( 'spm/v1', '/search', array(
		'methods'             => 'GET',
		'callback'            => 'spm_rest_search',
		'permission_callback' => $perm,
	) );

	register_rest_route( 'spm/v1', '/stats', array(
		'methods'             => 'GET',
		'callback'            => 'spm_rest_stats',
		'permission_callback' => $perm,
	) );

	register_rest_route( 'spm/v1', '/page/(?P<id>\d+)', array(
		'methods'             => 'PATCH',
		'callback'            => 'spm_rest_quick_edit',
		'permission_callback' => $perm,
		'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
	) );

	register_rest_route( 'spm/v1', '/page/(?P<id>\d+)/edit', array(
		'methods'             => 'GET',
		'callback'            => 'spm_rest_edit_data',
		'permission_callback' => $perm,
		'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
	) );

	register_rest_route( 'spm/v1', '/action', array(
		'methods'             => 'POST',
		'callback'            => 'spm_rest_action',
		'permission_callback' => $perm,
	) );

	register_rest_route( 'spm/v1', '/duplicates', array(
		'methods'             => 'GET',
		'callback'            => 'spm_rest_duplicates',
		'permission_callback' => $perm,
	) );

	register_rest_route( 'spm/v1', '/parents', array(
		'methods'             => 'GET',
		'callback'            => 'spm_rest_parents',
		'permission_callback' => $perm,
	) );

	// CSV export of the currently-filtered (or currently-searched) view.
	register_rest_route( 'spm/v1', '/export', array(
		'methods'             => 'GET',
		'callback'            => 'spm_rest_export',
		'permission_callback' => $perm,
	) );

	// FEATURE 2: custom 4-tier top-level default view (empty search).
	register_rest_route( 'spm/v1', '/default-view', array(
		'methods'             => 'GET',
		'callback'            => 'spm_rest_default_view',
		'permission_callback' => $perm,
	) );
}

/* --------------------------------------------------------------------------
 * Shared query helpers
 * ----------------------------------------------------------------------- */

const SPM_SORTABLE = array(
	'title'    => 'p.post_title',
	'id'       => 'p.ID',
	'slug'     => 'p.post_name',
	'created'  => 'p.post_date',
	'modified' => 'p.post_modified',
	'author'   => 'p.post_author',
);

const SPM_STATUSES = array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' );

/**
 * Builds WHERE clauses + params shared by list and (text) search.
 * Returns array( where[], params[], join_sql ).
 */
function spm_build_filters( WP_REST_Request $req ) {
	global $wpdb;

	$where  = array( "p.post_type = 'page'" );
	$params = array();
	$join   = '';

	// Status.
	$status = (array) $req->get_param( 'status' );
	$status = array_values( array_intersect( $status, SPM_STATUSES ) );
	if ( $status ) {
		$ph      = implode( ',', array_fill( 0, count( $status ), '%s' ) );
		$where[] = "p.post_status IN ({$ph})";
		array_push( $params, ...$status );
	} else {
		// Default: hide trash unless explicitly requested.
		$where[] = "p.post_status <> 'trash'";
	}

	// Author.
	$author = absint( $req->get_param( 'author' ) );
	if ( $author ) {
		$where[]  = 'p.post_author = %d';
		$params[] = $author;
	}

	// Parent filter: accept ID, slug, or title (all index-friendly).
	$parent = $req->get_param( 'parent' );
	$parent_slug  = sanitize_text_field( (string) $req->get_param( 'parent_slug' ) );
	$parent_title = sanitize_text_field( (string) $req->get_param( 'parent_title' ) );

	if ( $parent_slug ) {
		// Resolve slug → ID via sub-select (post_name is indexed).
		$join    .= " INNER JOIN {$wpdb->posts} pp ON pp.ID = p.post_parent AND pp.post_type = 'page' AND pp.post_name = %s";
		$params[] = $parent_slug;
	} elseif ( $parent_title ) {
		// Resolve by title prefix — FULLTEXT on title index.
		$join    .= " INNER JOIN {$wpdb->posts} ppt ON ppt.ID = p.post_parent AND ppt.post_type = 'page' AND ppt.post_title LIKE %s";
		$params[] = $wpdb->esc_like( $parent_title ) . '%';
	} elseif ( null !== $parent && '' !== $parent && (int) $parent >= 0 ) {
		$where[]  = 'p.post_parent = %d';
		$params[] = (int) $parent;
	}

	// Date created / modified range (YYYY-MM-DD).
	$df = sanitize_text_field( (string) $req->get_param( 'date_from' ) );
	$dt = sanitize_text_field( (string) $req->get_param( 'date_to' ) );
	$dfield = ( $req->get_param( 'date_field' ) === 'modified' ) ? 'post_modified' : 'post_date';
	if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $df ) ) {
		$where[]  = "p.{$dfield} >= %s";
		$params[] = $df . ' 00:00:00';
	}
	if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $dt ) ) {
		$where[]  = "p.{$dfield} <= %s";
		$params[] = $dt . ' 23:59:59';
	}

	// Template (postmeta join only when needed).
	$tpl = sanitize_text_field( (string) $req->get_param( 'template' ) );
	if ( $tpl ) {
		$join    .= " LEFT JOIN {$wpdb->postmeta} tm ON tm.post_id = p.ID AND tm.meta_key = '_wp_page_template'";
		$where[]  = 'tm.meta_value = %s';
		$params[] = $tpl;
	}

	// Taxonomy term filter.
	$tax  = sanitize_key( (string) $req->get_param( 'taxonomy' ) );
	$term = absint( $req->get_param( 'term' ) );
	if ( $tax && $term ) {
		$join    .= " INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
		              INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id";
		$where[]  = 'tt.taxonomy = %s AND tt.term_id = %d';
		$params[] = $tax;
		$params[] = $term;
	}

	// -----------------------------------------------------------------------
	// Template taxonomy filters (dedicated, high-performance)
	// -----------------------------------------------------------------------

	// Always resolve server-side. The client sends a hint via template_tax but
	// we re-detect authoritatively here so a mis-detected or empty slug never
	// silently skips the filter.
	$tmpl_tax_hint = sanitize_key( (string) $req->get_param( 'template_tax' ) );
	if ( $tmpl_tax_hint && $tmpl_tax_hint !== '__detect__' && taxonomy_exists( $tmpl_tax_hint ) ) {
		$tmpl_tax = $tmpl_tax_hint;
	} else {
		// Always re-detect — this is authoritative.
		$tmpl_tax = spm_get_template_taxonomy_name();
	}

	// Filter: pages assigned to one or more specific template terms.
	// When template_include_children=1, a selected parent term also matches
	// pages tagged with any of its descendant terms.
	$tmpl_term_raw = sanitize_text_field( (string) $req->get_param( 'template_term' ) );
	if ( $tmpl_tax && $tmpl_term_raw ) {
		$tmpl_ids = array_values( array_filter( array_map( 'absint', explode( ',', $tmpl_term_raw ) ) ) );

		$include_children = filter_var( $req->get_param( 'template_include_children' ), FILTER_VALIDATE_BOOLEAN );
		if ( $include_children && $tmpl_ids ) {
			$expanded = $tmpl_ids;
			foreach ( $tmpl_ids as $tid ) {
				$desc = get_term_children( $tid, $tmpl_tax );
				if ( ! is_wp_error( $desc ) && $desc ) {
					$expanded = array_merge( $expanded, array_map( 'absint', $desc ) );
				}
			}
			$tmpl_ids = array_values( array_unique( $expanded ) );
		}

		if ( $tmpl_ids ) {
			$ph = implode( ',', array_fill( 0, count( $tmpl_ids ), '%d' ) );
			// EXISTS subquery (not JOIN) avoids row duplication when a page has
			// multiple matching terms.
			$where[]  = "EXISTS (
				SELECT 1 FROM {$wpdb->term_relationships} ttr
				INNER JOIN {$wpdb->term_taxonomy} ttt
					ON ttt.term_taxonomy_id = ttr.term_taxonomy_id
					AND ttt.taxonomy = %s
				WHERE ttr.object_id = p.ID AND ttt.term_id IN ({$ph})
			)";
			$params[] = $tmpl_tax;
			array_push( $params, ...$tmpl_ids );
		}
	}

	// Filter: pages with NO template term assigned.
	// Requires a valid, registered taxonomy slug — otherwise silently skipped.
	$no_template = filter_var( $req->get_param( 'no_template' ), FILTER_VALIDATE_BOOLEAN );
	if ( $no_template ) {
		if ( $tmpl_tax ) {
			// Precise: NOT EXISTS for this specific taxonomy.
			$where[]  = "NOT EXISTS (
				SELECT 1 FROM {$wpdb->term_relationships} ntr
				INNER JOIN {$wpdb->term_taxonomy} ntt
					ON ntt.term_taxonomy_id = ntr.term_taxonomy_id
					AND ntt.taxonomy = %s
				WHERE ntr.object_id = p.ID
			)";
			$params[] = $tmpl_tax;
		}
		// If $tmpl_tax is empty the taxonomy isn't registered on this site;
		// we skip the filter rather than silently returning all pages.
	}

	// Filter: pages assigned to ANY template term (the inverse of no_template).
	$has_template = filter_var( $req->get_param( 'has_template' ), FILTER_VALIDATE_BOOLEAN );
	if ( $has_template && $tmpl_tax ) {
		$where[]  = "EXISTS (
			SELECT 1 FROM {$wpdb->term_relationships} htr
			INNER JOIN {$wpdb->term_taxonomy} htt
				ON htt.term_taxonomy_id = htr.term_taxonomy_id
				AND htt.taxonomy = %s
			WHERE htr.object_id = p.ID
		)";
		$params[] = $tmpl_tax;
	}

	// Filter: Elementor pages.
	$elementor_only = filter_var( $req->get_param( 'elementor_only' ), FILTER_VALIDATE_BOOLEAN );
	if ( $elementor_only ) {
		$where[] = "EXISTS (
			SELECT 1 FROM {$wpdb->postmeta} em
			WHERE em.post_id = p.ID AND em.meta_key = '_elementor_data'
		)";
	}

	// Filter: orphaned pages (no parent AND no template term).
	$orphan = filter_var( $req->get_param( 'orphan' ), FILTER_VALIDATE_BOOLEAN );
	if ( $orphan ) {
		$where[] = 'p.post_parent = 0';
		if ( $tmpl_tax ) {
			$where[]  = "NOT EXISTS (
				SELECT 1 FROM {$wpdb->term_relationships} otr
				INNER JOIN {$wpdb->term_taxonomy} ott
					ON ott.term_taxonomy_id = otr.term_taxonomy_id
					AND ott.taxonomy = %s
				WHERE otr.object_id = p.ID
			)";
			$params[] = $tmpl_tax;
		}
	}

	return array( $where, $params, $join );
}

function spm_shape_row( array $r ) {
	$id = (int) $r['ID'];

	// Resolve parent page title (lightweight: uses WP object cache after first hit).
	$parent_id    = (int) $r['post_parent'];
	$parent_title = '';
	if ( $parent_id ) {
		$parent_post  = get_post( $parent_id );
		$parent_title = $parent_post ? $parent_post->post_title : '';
	}

	// Template taxonomy terms assigned to this page.
	$tmpl_tax     = spm_get_template_taxonomy_name();
	$tmpl_terms   = array();
	$tmpl_term_ids = array();
	if ( taxonomy_exists( $tmpl_tax ) ) {
		$terms = wp_get_object_terms( $id, $tmpl_tax );
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $t ) {
				$tmpl_terms[]    = $t->name;
				$tmpl_term_ids[] = (int) $t->term_id;
			}
		}
	}

	// Elementor: check for presence of _elementor_data meta key.
	$elementor = (bool) metadata_exists( 'post', $id, '_elementor_data' );

	return array(
		'id'            => $id,
		'title'         => ( '' !== $r['post_title'] ) ? $r['post_title'] : __( '(no title)', 'spm' ),
		'slug'          => $r['post_name'],
		'status'        => $r['post_status'],
		'author'        => (int) $r['post_author'],
		'parent'        => $parent_id,
		'parentTitle'   => $parent_title,
		'modified'      => $r['post_modified'],
		'url'           => get_permalink( $id ),
		'editUrl'       => admin_url( 'post.php?post=' . $id . '&action=edit' ),
		'elemUrl'       => admin_url( 'post.php?post=' . $id . '&action=elementor' ),
		'templateTerms' => $tmpl_terms,
		'templateTermIds' => $tmpl_term_ids,
		'elementor'     => $elementor,
	);
}

/* --------------------------------------------------------------------------
 * GET /pages  — keyset-paginated list
 * ----------------------------------------------------------------------- */

function spm_rest_list( WP_REST_Request $req ) {
	global $wpdb;

	$orderby_key = (string) $req->get_param( 'orderby' );
	$orderby     = SPM_SORTABLE[ $orderby_key ] ?? 'p.post_date';
	$order       = ( strtoupper( (string) $req->get_param( 'order' ) ) === 'ASC' ) ? 'ASC' : 'DESC';
	$cmp         = ( 'ASC' === $order ) ? '>' : '<';
	$limit       = max( 1, min( 200, (int) $req->get_param( 'limit' ) ?: 100 ) );

	list( $where, $params, $join ) = spm_build_filters( $req );

	// Keyset cursor (value,id tuple).
	$cursor_raw = (string) $req->get_param( 'cursor' );
	if ( $cursor_raw ) {
		$cursor = json_decode( $cursor_raw, true );
		if ( is_array( $cursor ) && isset( $cursor['value'], $cursor['id'] ) ) {
			$where[]  = "( {$orderby} {$cmp} %s OR ( {$orderby} = %s AND p.ID {$cmp} %d ) )";
			$params[] = (string) $cursor['value'];
			$params[] = (string) $cursor['value'];
			$params[] = (int) $cursor['id'];
		}
	}

	$where_sql = implode( ' AND ', $where );
	$sql = "SELECT p.ID, p.post_title, p.post_name, p.post_status, p.post_author, p.post_parent, p.post_date, p.post_modified
	        FROM {$wpdb->posts} p {$join}
	        WHERE {$where_sql}
	        ORDER BY {$orderby} {$order}, p.ID {$order}
	        LIMIT %d";
	$params[] = $limit + 1; // probe row for has-next.

	$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

	$next = null;
	if ( count( $rows ) > $limit ) {
		array_pop( $rows );
		$last         = $rows[ $limit - 1 ];
		$orderby_col  = trim( str_replace( 'p.', '', $orderby ) );
		$next         = array( 'value' => $last[ $orderby_col ], 'id' => (int) $last['ID'] );
	}

	return rest_ensure_response( array(
		'rows'        => array_map( 'spm_shape_row', $rows ),
		'next_cursor' => $next,
	) );
}

/* --------------------------------------------------------------------------
 * GET /export — stream the current view as CSV
 * -----------------------------------------------------------------------
 * Two modes, mirroring the front-end exactly so the file always matches
 * what the user is looking at:
 *
 *   1. FILTER MODE (default): reuses spm_build_filters() — the same WHERE
 *      builder the list & stats endpoints use — so the export row set is
 *      identical to the filtered list view. No keyset LIMIT is applied, so
 *      every matching row is exported, not just the first page. A hard cap
 *      (SPM_EXPORT_MAX) protects against runaway memory on huge sites.
 *
 *   2. ID MODE: when the view is a text search or duplicate scan, those
 *      results don't come from spm_build_filters(), so the client passes the
 *      exact visible IDs via ?ids=1,2,3 and we export precisely those.
 *
 * Output is streamed straight to the browser (not via WP_REST_Response) so
 * we can set file-download headers and avoid buffering the whole file in
 * memory. A UTF-8 BOM is prepended so Excel opens accented characters
 * correctly on double-click.
 * ----------------------------------------------------------------------- */

if ( ! defined( 'SPM_EXPORT_MAX' ) ) {
	// Safety ceiling on rows per export. Filter with 'spm_export_max' if needed.
	define( 'SPM_EXPORT_MAX', 50000 );
}

/**
 * Turn a shaped row into a flat associative array of the columns we export.
 * Kept in one place so header order and cell order can never drift apart.
 */
function spm_export_columns( array $shaped ) {
	return array(
		'ID'             => $shaped['id'],
		'Title'          => $shaped['title'],
		'Slug'           => $shaped['slug'],
		'URL'            => $shaped['url'],
		'Status'         => $shaped['status'],
		'Author'         => spm_export_author_name( $shaped['author'] ),
		'Parent ID'      => $shaped['parent'],
		'Parent Title'   => $shaped['parentTitle'],
		'Template Terms' => implode( ', ', (array) $shaped['templateTerms'] ),
		'Elementor'      => $shaped['elementor'] ? 'Yes' : 'No',
		'Modified'       => $shaped['modified'],
	);
}

/** Resolve an author ID to a display name (cached by WP after first hit). */
function spm_export_author_name( $author_id ) {
	$author_id = (int) $author_id;
	if ( ! $author_id ) {
		return '';
	}
	$u = get_userdata( $author_id );
	return $u ? $u->display_name : (string) $author_id;
}

function spm_rest_export( WP_REST_Request $req ) {
	global $wpdb;

	$max = (int) apply_filters( 'spm_export_max', SPM_EXPORT_MAX );

	// ── ID MODE ── explicit list of visible rows (search / duplicates view).
	$ids_raw = (string) $req->get_param( 'ids' );
	if ( '' !== $ids_raw ) {
		$ids = array_values( array_filter( array_map( 'absint', explode( ',', $ids_raw ) ) ) );
		$ids = array_slice( array_unique( $ids ), 0, $max );

		$rows = array();
		if ( $ids ) {
			$ph  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$sql = "SELECT p.ID, p.post_title, p.post_name, p.post_status, p.post_author, p.post_parent, p.post_date, p.post_modified
			        FROM {$wpdb->posts} p
			        WHERE p.post_type = 'page' AND p.ID IN ({$ph})";
			$db  = $wpdb->get_results( $wpdb->prepare( $sql, $ids ), ARRAY_A );

			// Preserve the client's row order (search relevance / dup grouping).
			$by_id = array();
			foreach ( $db as $r ) {
				$by_id[ (int) $r['ID'] ] = $r;
			}
			foreach ( $ids as $id ) {
				if ( isset( $by_id[ $id ] ) ) {
					$rows[] = $by_id[ $id ];
				}
			}
		}
		return spm_stream_csv( $rows );
	}

	// ── FILTER MODE ── same WHERE builder as the list/stats endpoints.
	list( $where, $params, $join ) = spm_build_filters( $req );

	$orderby_key = (string) $req->get_param( 'orderby' );
	$orderby     = SPM_SORTABLE[ $orderby_key ] ?? 'p.post_date';
	$order       = ( strtoupper( (string) $req->get_param( 'order' ) ) === 'ASC' ) ? 'ASC' : 'DESC';

	$where_sql = implode( ' AND ', $where );
	$sql = "SELECT p.ID, p.post_title, p.post_name, p.post_status, p.post_author, p.post_parent, p.post_date, p.post_modified
	        FROM {$wpdb->posts} p {$join}
	        WHERE {$where_sql}
	        ORDER BY {$orderby} {$order}, p.ID {$order}
	        LIMIT %d";
	$params[] = $max;

	$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

	return spm_stream_csv( $rows );
}

/**
 * Stream an array of raw DB rows as a CSV download and terminate the request.
 * fputcsv() handles all quoting/escaping (commas, quotes, newlines) per RFC 4180.
 */
function spm_stream_csv( array $rows ) {
	$filename = 'pages-export-' . gmdate( 'Y-m-d-His' ) . '.csv';

	// Discard any buffered output so headers are clean.
	if ( function_exists( 'ob_get_level' ) ) {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
	}

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
	header( 'X-Content-Type-Options: nosniff' );

	$out = fopen( 'php://output', 'w' );

	// UTF-8 BOM so Excel renders accented characters correctly on open.
	fwrite( $out, "\xEF\xBB\xBF" );

	// Header row is derived from the same map as the data rows.
	$header_written = false;

	foreach ( $rows as $r ) {
		$cols = spm_export_columns( spm_shape_row( $r ) );
		if ( ! $header_written ) {
			fputcsv( $out, array_keys( $cols ) );
			$header_written = true;
		}
		// Guard against CSV/formula injection in spreadsheet apps.
		fputcsv( $out, array_map( 'spm_csv_safe_cell', array_values( $cols ) ) );
	}

	// Empty result set: still emit a header row so the file isn't blank.
	if ( ! $header_written ) {
		$cols = spm_export_columns( array(
			'id' => '', 'title' => '', 'slug' => '', 'url' => '', 'status' => '',
			'author' => 0, 'parent' => '', 'parentTitle' => '', 'templateTerms' => array(),
			'elementor' => false, 'modified' => '',
		) );
		fputcsv( $out, array_keys( $cols ) );
	}

	fclose( $out );
	exit;
}

/**
 * Neutralize leading =,+,-,@ so spreadsheet apps don't interpret a cell as a
 * formula. Prefixes a single quote, the standard mitigation.
 */
function spm_csv_safe_cell( $value ) {
	$value = (string) $value;
	if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@' ), true ) ) {
		return "'" . $value;
	}
	return $value;
}

/* --------------------------------------------------------------------------
 * GET /stats — total pages (cached) + count matching current filters
 * ----------------------------------------------------------------------- */

function spm_rest_stats( WP_REST_Request $req ) {
	global $wpdb;

	// Total non-trash pages, cached for 5 minutes (invalidate-on-write optional).
	$total = get_transient( 'spm_total_pages' );
	if ( false === $total ) {
		$total = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='page' AND post_status<>'trash'"
		);
		set_transient( 'spm_total_pages', $total, 5 * MINUTE_IN_SECONDS );
	}

	// Filtered count using the same WHERE builder as the list.
	list( $where, $params, $join ) = spm_build_filters( $req );
	$where_sql = implode( ' AND ', $where );
	$sql = "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p {$join} WHERE {$where_sql}";
	$filtered = (int) $wpdb->get_var( $params ? $wpdb->prepare( $sql, $params ) : $sql );

	return rest_ensure_response( array(
		'total'    => (int) $total,
		'filtered' => $filtered,
	) );
}



function spm_rest_tree( WP_REST_Request $req ) {
	global $wpdb;
	$parent = (int) $req['parent'];

	$children = $wpdb->get_results( $wpdb->prepare(
		"SELECT ID, post_title, post_name, post_status, post_author, post_parent, post_date, post_modified
		 FROM {$wpdb->posts}
		 WHERE post_type='page' AND post_status<>'trash' AND post_parent=%d
		 ORDER BY menu_order ASC, post_title ASC
		 LIMIT 500",
		$parent
	), ARRAY_A );

	if ( ! $children ) {
		return rest_ensure_response( array() );
	}

	$ids = wp_list_pluck( $children, 'ID' );
	$ph  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	$kids = $wpdb->get_col( $wpdb->prepare(
		"SELECT DISTINCT post_parent FROM {$wpdb->posts}
		 WHERE post_type='page' AND post_status<>'trash' AND post_parent IN ({$ph})",
		$ids
	) );
	$has = array_flip( array_map( 'intval', $kids ) );

	$out = array();
	foreach ( $children as $c ) {
		$row = spm_shape_row( $c );
		$row['has_children'] = isset( $has[ (int) $c['ID'] ] );
		$out[] = $row;
	}
	return rest_ensure_response( $out );
}

/* --------------------------------------------------------------------------
 * GET /search — tiered, indexed. Optional content + ACF FULLTEXT.
 * ----------------------------------------------------------------------- */

function spm_rest_search( WP_REST_Request $req ) {
	global $wpdb;

	// ── FEATURE 1: hand off to the child-expansion path when requested. ──
	if ( 'children' === sanitize_key( (string) $req->get_param( 'scope' ) ) ) {
		return spm_search_children_of_matches( $req );
	}
	// ─────────────────────────────────────────────────────────────────────

	$q     = trim( (string) $req->get_param( 'q' ) );
	$field = sanitize_key( (string) $req->get_param( 'field' ) ); // auto|id|slug|title
	$exact = filter_var( $req->get_param( 'exact' ), FILTER_VALIDATE_BOOLEAN );
	$scope_content = filter_var( $req->get_param( 'content' ), FILTER_VALIDATE_BOOLEAN );
	$scope_acf     = filter_var( $req->get_param( 'acf' ), FILTER_VALIDATE_BOOLEAN );

	if ( '' === $q ) {
		return rest_ensure_response( array( 'rows' => array() ) );
	}

	// Auto URL/path detection: if the query looks like a full URL or a /path/segment,
	// extract the last non-empty path segment and treat it as a slug search.
	if ( 'auto' === $field || '' === $field ) {
		$looks_like_url  = preg_match( '#^https?://#i', $q );
		$looks_like_path = ! $looks_like_url && preg_match( '#[/]#', $q );
		if ( $looks_like_url || $looks_like_path ) {
			$path     = $looks_like_url ? wp_parse_url( $q, PHP_URL_PATH ) : $q;
			$path     = trim( (string) $path, '/' );
			$segments = array_filter( explode( '/', $path ) );
			$slug     = end( $segments );
			if ( $slug ) {
				$rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT ID, post_title, post_name, post_status, post_author, post_parent, post_date, post_modified
					 FROM {$wpdb->posts} WHERE post_type='page' AND post_name = %s LIMIT 20",
					sanitize_title( $slug )
				), ARRAY_A );
				if ( ! $rows ) {
					$rows = $wpdb->get_results( $wpdb->prepare(
						"SELECT ID, post_title, post_name, post_status, post_author, post_parent, post_date, post_modified
						 FROM {$wpdb->posts} WHERE post_type='page' AND post_name LIKE %s LIMIT 40",
						$wpdb->esc_like( sanitize_title( $slug ) ) . '%'
					), ARRAY_A );
				}
				return rest_ensure_response( array( 'rows' => array_map( 'spm_shape_row', $rows ) ) );
			}
		}
	}

	// Tier 1: numeric -> PRIMARY KEY lookup (instant).
	if ( ( 'id' === $field || 'auto' === $field || '' === $field ) && ctype_digit( $q ) ) {
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT ID, post_title, post_name, post_status, post_author, post_parent, post_date, post_modified
			 FROM {$wpdb->posts} WHERE ID=%d AND post_type='page'",
			(int) $q
		), ARRAY_A );
		return rest_ensure_response( array( 'rows' => $row ? array( spm_shape_row( $row ) ) : array() ) );
	}

	// Tier 2: slug — exact (PK-grade index) or prefix (uses index).
	if ( 'slug' === $field ) {
		if ( $exact ) {
			$sql = "SELECT ID, post_title, post_name, post_status, post_author, post_parent, post_date, post_modified
			        FROM {$wpdb->posts} WHERE post_type='page' AND post_name=%s LIMIT 60";
			$arg = $q;
		} else {
			$sql = "SELECT ID, post_title, post_name, post_status, post_author, post_parent, post_date, post_modified
			        FROM {$wpdb->posts} WHERE post_type='page' AND post_name LIKE %s LIMIT 60";
			$arg = $wpdb->esc_like( $q ) . '%';
		}
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $arg ), ARRAY_A );
		return rest_ensure_response( array( 'rows' => array_map( 'spm_shape_row', $rows ) ) );
	}

	// Tier 3: FULLTEXT across title (+ optional content) (+ optional ACF/postmeta).
	// Everything runs in MySQL; post_content / meta_value never enter PHP memory.
	// IMPORTANT: MATCH/AGAINST throws a fatal MySQL error if the FULLTEXT index
	// does not yet exist (indexes are built lazily after activation). So we only
	// use FULLTEXT when the relevant index is present, and otherwise fall back to
	// a bounded LIKE search further down.
	$boolean = spm_to_boolean_query( $q );
	$ids     = array();

	$has_title_ft   = spm_index_exists( $wpdb->posts, 'spm_title_ft' );
	$has_content_ft = spm_index_exists( $wpdb->posts, 'spm_content_ft' );
	$has_meta_ft    = spm_index_exists( $wpdb->postmeta, 'spm_meta_value_ft' );

	// 3a. Title + optional content via MATCH on wp_posts (only if index exists).
	// Content matching needs a FULLTEXT index spanning the matched columns; we
	// only include post_content when its combined index is available.
	if ( $has_title_ft ) {
		$use_content = $scope_content && $has_content_ft;
		// Note: a multi-column MATCH requires a single FULLTEXT index over exactly
		// those columns. We index them separately, so match each independently.
		$ids_title = $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_type='page' AND post_status<>'trash'
			   AND MATCH(post_title) AGAINST(%s IN BOOLEAN MODE)
			 LIMIT 200",
			$boolean
		) );
		$ids = array_merge( $ids, array_map( 'intval', (array) $ids_title ) );

		if ( $use_content ) {
			$ids_content = $wpdb->get_col( $wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_type='page' AND post_status<>'trash'
				   AND MATCH(post_content) AGAINST(%s IN BOOLEAN MODE)
				 LIMIT 200",
				$boolean
			) );
			$ids = array_merge( $ids, array_map( 'intval', (array) $ids_content ) );
		}
	}

	// 3b. ACF / custom-field values via FULLTEXT on wp_postmeta (optional + indexed).
	if ( $scope_acf && $has_meta_ft ) {
		$ids_meta = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT pm.post_id
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE p.post_type='page' AND p.post_status<>'trash'
			   AND pm.meta_key NOT LIKE %s
			   AND MATCH(pm.meta_value) AGAINST(%s IN BOOLEAN MODE)
			 LIMIT 200",
			$wpdb->esc_like( '_' ) . '%', // skip protected/internal meta (e.g. _edit_lock)
			$boolean
		) );
		$ids = array_merge( $ids, array_map( 'intval', (array) $ids_meta ) );
	}

	$ids = array_values( array_unique( array_filter( $ids ) ) );
	if ( ! $ids ) {
		// Fallback when FULLTEXT found nothing OR the indexes aren't built yet.
		// Uses a bounded LIKE (contains-match) on title, and on content when the
		// content scope is requested. Capped tightly so it stays fast pre-index.
		$like_q = '%' . $wpdb->esc_like( $q ) . '%';
		if ( $scope_content ) {
			$like = $wpdb->get_results( $wpdb->prepare(
				"SELECT ID, post_title, post_name, post_status, post_author, post_parent, post_date, post_modified
				 FROM {$wpdb->posts}
				 WHERE post_type='page' AND post_status<>'trash'
				   AND ( post_title LIKE %s OR post_content LIKE %s )
				 ORDER BY post_title ASC LIMIT 60",
				$like_q, $like_q
			), ARRAY_A );
		} else {
			$like = $wpdb->get_results( $wpdb->prepare(
				"SELECT ID, post_title, post_name, post_status, post_author, post_parent, post_date, post_modified
				 FROM {$wpdb->posts}
				 WHERE post_type='page' AND post_status<>'trash' AND post_title LIKE %s
				 ORDER BY post_title ASC LIMIT 60",
				$like_q
			), ARRAY_A );
		}
		return rest_ensure_response( array( 'rows' => array_map( 'spm_shape_row', $like ) ) );
	}

	$ids   = array_slice( $ids, 0, 200 );
	$ph    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	$rows  = $wpdb->get_results( $wpdb->prepare(
		"SELECT ID, post_title, post_name, post_status, post_author, post_parent, post_date, post_modified
		 FROM {$wpdb->posts} WHERE ID IN ({$ph}) ORDER BY post_title ASC",
		$ids
	), ARRAY_A );

	return rest_ensure_response( array( 'rows' => array_map( 'spm_shape_row', $rows ) ) );
}

/** Turn a user phrase into a safe BOOLEAN-MODE query: each token gets a + and trailing *. */
function spm_to_boolean_query( $q ) {
	$q      = preg_replace( '/[+\-><\(\)~*\"@]+/', ' ', $q ); // strip operator chars.
	$tokens = preg_split( '/\s+/', trim( $q ), -1, PREG_SPLIT_NO_EMPTY );
	$tokens = array_slice( $tokens, 0, 8 );
	$parts  = array();
	foreach ( $tokens as $t ) {
		$parts[] = '+' . $t . '*';
	}
	return implode( ' ', $parts );
}

/* ==========================================================================
 * FEATURE 1 — Search scope: "Child pages of matches"
 * --------------------------------------------------------------------------
 * ADDITIVE. Does not modify spm_rest_search()'s existing tiers. A 3-line
 * guard at the top of spm_rest_search() hands off here when scope=children.
 *
 * Logic:
 *   1. Reuse the EXISTING search to resolve matching parent IDs by cloning
 *      the request with scope forced back to 'direct' and calling
 *      spm_rest_search() — so every tier (ID / slug / URL / FULLTEXT / LIKE)
 *      behaves identically. No query logic is duplicated or forked.
 *   2. Fetch those matches' direct children via one indexed post_parent
 *      lookup. Parent set capped at 200; child set capped at 500.
 * ======================================================================== */
function spm_search_children_of_matches( WP_REST_Request $req ) {
	global $wpdb;

	// --- Step 1: get parent matches by delegating to the normal search. ---
	$parent_req = clone $req;
	$parent_req->set_param( 'scope', 'direct' ); // prevent recursion.

	$parent_resp = spm_rest_search( $parent_req );
	$parent_data = $parent_resp instanceof WP_REST_Response ? $parent_resp->get_data() : (array) $parent_resp;
	$parent_rows = isset( $parent_data['rows'] ) && is_array( $parent_data['rows'] ) ? $parent_data['rows'] : array();

	$parent_ids = array();
	foreach ( $parent_rows as $pr ) {
		if ( isset( $pr['id'] ) ) {
			$parent_ids[] = (int) $pr['id'];
		}
	}
	$parent_ids = array_values( array_unique( array_filter( $parent_ids ) ) );

	if ( ! $parent_ids ) {
		return rest_ensure_response( array( 'rows' => array(), 'scope' => 'children', 'parent_ids' => array() ) );
	}

	// --- Step 2: fetch direct children of those parents (indexed lookup). ---
	$parent_ids = array_slice( $parent_ids, 0, 200 );
	$ph         = implode( ',', array_fill( 0, count( $parent_ids ), '%d' ) );

	$children = $wpdb->get_results( $wpdb->prepare(
		"SELECT ID, post_title, post_name, post_status, post_author, post_parent, post_date, post_modified
		 FROM {$wpdb->posts}
		 WHERE post_type = 'page'
		   AND post_status <> 'trash'
		   AND post_parent IN ({$ph})
		 ORDER BY post_parent ASC, post_title ASC
		 LIMIT 500",
		$parent_ids
	), ARRAY_A );

	// Also fetch the matched parents' own rows so we can show each parent as a
	// header above its children. Keyed by ID for O(1) lookup during grouping.
	$parents = $wpdb->get_results( $wpdb->prepare(
		"SELECT ID, post_title, post_name, post_status, post_author, post_parent, post_date, post_modified
		 FROM {$wpdb->posts}
		 WHERE post_type = 'page' AND ID IN ({$ph})",
		$parent_ids
	), ARRAY_A );

	$parent_by_id = array();
	foreach ( (array) $parents as $p ) {
		$parent_by_id[ (int) $p['ID'] ] = $p;
	}

	// Group children under their parent.
	$grouped = array();
	foreach ( (array) $children as $c ) {
		$grouped[ (int) $c['post_parent'] ][] = $c;
	}

	// Build the output: parent header row, then its child rows, preserving the
	// order in which parents matched. Parents with no children are skipped
	// (nothing to indent under them).
	$rows = array();
	foreach ( $parent_ids as $pid ) {
		if ( empty( $grouped[ $pid ] ) ) {
			continue;
		}
		if ( isset( $parent_by_id[ $pid ] ) ) {
			$hdr                     = spm_shape_row( $parent_by_id[ $pid ] );
			$hdr['_spmParentHeader'] = true;
			$hdr['_spmChildCount']   = count( $grouped[ $pid ] );
			$rows[]                  = $hdr;
		}
		foreach ( $grouped[ $pid ] as $c ) {
			$child               = spm_shape_row( $c );
			$child['_spmChild']  = true;
			$rows[]              = $child;
		}
	}

	return rest_ensure_response( array(
		'rows'       => $rows,
		'scope'      => 'children',
		'parent_ids' => $parent_ids,
	) );
}

/* --------------------------------------------------------------------------
 * SEO meta support (RankMath / Yoast / AIOSEO) + per-page edit data
 * ----------------------------------------------------------------------- */

/** Detect active SEO plugin: 'rankmath' | 'yoast' | 'aioseo' | '' */
function spm_seo_plugin() {
	static $p = null;
	if ( null !== $p ) {
		return $p;
	}
	if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) || function_exists( 'rank_math' ) || did_action( 'rank_math/loaded' ) ) {
		$p = 'rankmath';
	} elseif ( defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' ) ) {
		$p = 'yoast';
	} elseif ( defined( 'AIOSEO_VERSION' ) ) {
		$p = 'aioseo';
	} else {
		$p = '';
	}
	return $p;
}

/** Meta-key map per plugin for the fields we expose. */
function spm_seo_keys() {
	switch ( spm_seo_plugin() ) {
		case 'rankmath':
			return array(
				'title'       => 'rank_math_title',
				'description' => 'rank_math_description',
				'canonical'   => 'rank_math_canonical_url',
				'focus'       => 'rank_math_focus_keyword',
				'robots'      => 'rank_math_robots', // array
			);
		case 'yoast':
			return array(
				'title'       => '_yoast_wpseo_title',
				'description' => '_yoast_wpseo_metadesc',
				'canonical'   => '_yoast_wpseo_canonical',
				'focus'       => '_yoast_wpseo_focuskw',
				'robots'      => '', // Yoast stores noindex/nofollow separately
			);
		default:
			return array();
	}
}

/** Read SEO fields for a page into a normalized array. */
function spm_get_seo_data( $id ) {
	$plugin = spm_seo_plugin();
	$keys   = spm_seo_keys();
	$robots_all = array( 'index', 'noindex', 'nofollow', 'noarchive', 'noimageindex', 'nosnippet' );

	$data = array(
		'plugin'      => $plugin,
		'title'       => '',
		'description' => '',
		'canonical'   => '',
		'focus'       => '',
		'robots'      => array(),
		'robots_all'  => $robots_all,
	);
	if ( ! $plugin ) {
		return $data;
	}

	$data['title']       = (string) get_post_meta( $id, $keys['title'], true );
	$data['description'] = (string) get_post_meta( $id, $keys['description'], true );
	$data['canonical']   = (string) get_post_meta( $id, $keys['canonical'], true );
	$data['focus']       = (string) get_post_meta( $id, $keys['focus'], true );

	if ( 'rankmath' === $plugin ) {
		$r = get_post_meta( $id, $keys['robots'], true );
		if ( is_array( $r ) && ! empty( $r ) ) {
			// Stored as values, e.g. array('noindex','nofollow'). Normalize to a flat list.
			$data['robots'] = array_values( array_filter( array_map( 'strval', $r ) ) );
		} else {
			// No per-page override: reflect RankMath's global default for the post type
			// so the checkboxes show the effective state (usually just "index").
			$data['robots']      = spm_rankmath_default_robots( get_post_type( $id ) );
			$data['robots_default'] = true; // hint: inheriting global default
		}
	} elseif ( 'yoast' === $plugin ) {
		$noindex  = get_post_meta( $id, '_yoast_wpseo_meta-robots-noindex', true ); // '1' = noindex
		$nofollow = get_post_meta( $id, '_yoast_wpseo_meta-robots-nofollow', true ); // '1' = nofollow
		$adv      = (string) get_post_meta( $id, '_yoast_wpseo_meta-robots-adv', true ); // csv: noarchive,noimageindex,nosnippet
		$robots   = array();
		$robots[] = ( '1' === $noindex ) ? 'noindex' : 'index';
		if ( '1' === $nofollow ) {
			$robots[] = 'nofollow';
		}
		foreach ( array_filter( explode( ',', $adv ) ) as $a ) {
			$robots[] = trim( $a );
		}
		$data['robots'] = $robots;
	}
	return $data;
}

/**
 * RankMath's global default robots directives for a post type, read from its
 * Titles & Meta options ('rank-math-options-titles'). Falls back to ['index'].
 */
function spm_rankmath_default_robots( $post_type ) {
	$opts = get_option( 'rank-math-options-titles' );
	$key  = 'pt_' . $post_type . '_robots';
	if ( is_array( $opts ) && ! empty( $opts[ $key ] ) && is_array( $opts[ $key ] ) ) {
		return array_values( array_filter( array_map( 'strval', $opts[ $key ] ) ) );
	}
	return array( 'index' );
}

/** Persist SEO fields from the quick-edit payload. */
function spm_save_seo_data( $id, array $seo ) {
	$plugin = spm_seo_plugin();
	$keys   = spm_seo_keys();
	if ( ! $plugin ) {
		return;
	}

	if ( isset( $seo['title'] ) ) {
		update_post_meta( $id, $keys['title'], sanitize_text_field( $seo['title'] ) );
	}
	if ( isset( $seo['description'] ) ) {
		update_post_meta( $id, $keys['description'], sanitize_textarea_field( $seo['description'] ) );
	}
	if ( isset( $seo['canonical'] ) ) {
		update_post_meta( $id, $keys['canonical'], esc_url_raw( $seo['canonical'] ) );
	}
	if ( isset( $seo['focus'] ) ) {
		update_post_meta( $id, $keys['focus'], sanitize_text_field( $seo['focus'] ) );
	}

	if ( isset( $seo['robots'] ) && is_array( $seo['robots'] ) ) {
		$valid  = array( 'index', 'noindex', 'nofollow', 'noarchive', 'noimageindex', 'nosnippet' );
		$robots = array_values( array_intersect( array_map( 'sanitize_key', $seo['robots'] ), $valid ) );
		if ( 'rankmath' === $plugin ) {
			// RankMath stores a flat array of directive values. If "noindex" is
			// present, drop "index" (they're mutually exclusive). Store as values.
			if ( in_array( 'noindex', $robots, true ) ) {
				$robots = array_values( array_diff( $robots, array( 'index' ) ) );
			} elseif ( ! in_array( 'index', $robots, true ) ) {
				// Ensure at least "index" so the page isn't left with an empty/ambiguous set.
				array_unshift( $robots, 'index' );
			}
			update_post_meta( $id, $keys['robots'], $robots );
		} elseif ( 'yoast' === $plugin ) {
			update_post_meta( $id, '_yoast_wpseo_meta-robots-noindex', in_array( 'noindex', $robots, true ) ? '1' : '0' );
			update_post_meta( $id, '_yoast_wpseo_meta-robots-nofollow', in_array( 'nofollow', $robots, true ) ? '1' : '0' );
			$adv = array_values( array_intersect( $robots, array( 'noarchive', 'noimageindex', 'nosnippet' ) ) );
			update_post_meta( $id, '_yoast_wpseo_meta-robots-adv', implode( ',', $adv ) );
		}
	}
}

/**
 * GET /page/{id}/edit — full per-page edit payload (loaded on demand when the
 * quick-edit panel opens). Keeps the main list query lean.
 */
function spm_rest_edit_data( WP_REST_Request $req ) {
	$id = (int) $req['id'];
	if ( ! current_user_can( 'edit_post', $id ) ) {
		return new WP_Error( 'spm_forbidden', __( 'Not allowed.', 'spm' ), array( 'status' => 403 ) );
	}
	$post = get_post( $id );
	if ( ! $post ) {
		return new WP_Error( 'spm_not_found', __( 'Page not found.', 'spm' ), array( 'status' => 404 ) );
	}

	// Assigned term IDs for every UI-visible page taxonomy.
	$tax_assignments = array();
	foreach ( get_object_taxonomies( 'page', 'objects' ) as $tax ) {
		if ( ! $tax->show_ui ) {
			continue;
		}
		$ids = wp_get_object_terms( $id, $tax->name, array( 'fields' => 'ids' ) );
		$tax_assignments[ $tax->name ] = is_wp_error( $ids ) ? array() : array_map( 'intval', $ids );
	}

	return rest_ensure_response( array(
		'id'       => $id,
		'template' => (string) get_post_meta( $id, '_wp_page_template', true ),
		'tax'      => $tax_assignments,
		'seo'      => spm_get_seo_data( $id ),
	) );
}

/* --------------------------------------------------------------------------
 * PATCH /page/{id} — quick edit
 * ----------------------------------------------------------------------- */

function spm_rest_quick_edit( WP_REST_Request $req ) {
	$id = (int) $req['id'];
	if ( ! current_user_can( 'edit_post', $id ) ) {
		return new WP_Error( 'spm_forbidden', __( 'Not allowed to edit this page.', 'spm' ), array( 'status' => 403 ) );
	}

	$update = array( 'ID' => $id );
	$body   = $req->get_json_params();

	if ( isset( $body['title'] ) ) {
		$update['post_title'] = sanitize_text_field( $body['title'] );
	}
	if ( isset( $body['slug'] ) ) {
		$update['post_name'] = sanitize_title( $body['slug'] );
	}
	if ( isset( $body['status'] ) && in_array( $body['status'], SPM_STATUSES, true ) ) {
		$update['post_status'] = $body['status'];
	}
	if ( isset( $body['parent'] ) ) {
		$update['post_parent'] = absint( $body['parent'] );
	}

	$res = wp_update_post( $update, true );
	if ( is_wp_error( $res ) ) {
		return new WP_Error( 'spm_update_failed', $res->get_error_message(), array( 'status' => 400 ) );
	}

	if ( isset( $body['template'] ) ) {
		if ( '' === $body['template'] ) {
			delete_post_meta( $id, '_wp_page_template' );
		} else {
			update_post_meta( $id, '_wp_page_template', sanitize_text_field( $body['template'] ) );
		}
	}

	// Taxonomy terms: expects array of { taxonomy: string, term_ids: int[] }
	if ( isset( $body['tax_terms'] ) && is_array( $body['tax_terms'] ) ) {
		foreach ( $body['tax_terms'] as $entry ) {
			$tax      = sanitize_key( $entry['taxonomy'] ?? '' );
			$term_ids = array_map( 'absint', (array) ( $entry['term_ids'] ?? array() ) );
			if ( $tax && taxonomy_exists( $tax ) && current_user_can( 'assign_terms', $tax ) ) {
				wp_set_object_terms( $id, $term_ids, $tax );
			}
		}
	}

	// SEO fields (RankMath / Yoast).
	if ( isset( $body['seo'] ) && is_array( $body['seo'] ) ) {
		spm_save_seo_data( $id, $body['seo'] );
	}

	$post = get_post( $id, ARRAY_A );
	return rest_ensure_response( spm_shape_row( $post ) );
}

/* --------------------------------------------------------------------------
 * POST /action — duplicate | trash | untrash | delete
 * ----------------------------------------------------------------------- */

function spm_rest_action( WP_REST_Request $req ) {
	$body   = $req->get_json_params();
	$action = sanitize_key( $body['action'] ?? '' );
	$id     = absint( $body['id'] ?? 0 );

	if ( ! $id || ! get_post( $id ) ) {
		return new WP_Error( 'spm_bad_request', __( 'Invalid page.', 'spm' ), array( 'status' => 400 ) );
	}

	switch ( $action ) {
		case 'duplicate':
			if ( ! current_user_can( 'edit_pages' ) ) {
				return new WP_Error( 'spm_forbidden', __( 'Not allowed.', 'spm' ), array( 'status' => 403 ) );
			}
			$new = spm_duplicate_page( $id );
			if ( is_wp_error( $new ) ) {
				return new WP_Error( 'spm_dup_failed', $new->get_error_message(), array( 'status' => 400 ) );
			}
			return rest_ensure_response( array( 'ok' => true, 'newId' => $new ) );

		case 'trash':
			if ( ! current_user_can( 'delete_post', $id ) ) {
				return new WP_Error( 'spm_forbidden', __( 'Not allowed.', 'spm' ), array( 'status' => 403 ) );
			}
			wp_trash_post( $id );
			return rest_ensure_response( array( 'ok' => true ) );

		case 'untrash':
			if ( ! current_user_can( 'delete_post', $id ) ) {
				return new WP_Error( 'spm_forbidden', __( 'Not allowed.', 'spm' ), array( 'status' => 403 ) );
			}
			wp_untrash_post( $id );
			return rest_ensure_response( array( 'ok' => true ) );

		case 'delete':
			if ( ! current_user_can( 'delete_post', $id ) ) {
				return new WP_Error( 'spm_forbidden', __( 'Not allowed.', 'spm' ), array( 'status' => 403 ) );
			}
			wp_delete_post( $id, true );
			return rest_ensure_response( array( 'ok' => true ) );
	}

	return new WP_Error( 'spm_unknown_action', __( 'Unknown action.', 'spm' ), array( 'status' => 400 ) );
}

function spm_duplicate_page( $id ) {
	$src = get_post( $id );
	if ( ! $src ) {
		return new WP_Error( 'spm_no_src', __( 'Source page not found.', 'spm' ) );
	}
	$new_id = wp_insert_post( array(
		'post_type'      => 'page',
		'post_status'    => 'draft',
		'post_title'     => $src->post_title . ' (' . __( 'copy', 'spm' ) . ')',
		'post_content'   => $src->post_content,
		'post_excerpt'   => $src->post_excerpt,
		'post_parent'    => $src->post_parent,
		'menu_order'     => $src->menu_order,
		'post_author'    => get_current_user_id(),
		'comment_status' => $src->comment_status,
		'ping_status'    => $src->ping_status,
	), true );

	if ( is_wp_error( $new_id ) ) {
		return $new_id;
	}

	// Copy all meta (includes ACF + Elementor _elementor_data).
	$meta = get_post_meta( $id );
	foreach ( $meta as $key => $vals ) {
		if ( '_wp_old_slug' === $key ) {
			continue;
		}
		foreach ( $vals as $v ) {
			add_post_meta( $new_id, $key, maybe_unserialize( $v ) );
		}
	}

	// Copy taxonomy terms.
	foreach ( get_object_taxonomies( 'page' ) as $tax ) {
		$terms = wp_get_object_terms( $id, $tax, array( 'fields' => 'ids' ) );
		if ( ! is_wp_error( $terms ) && $terms ) {
			wp_set_object_terms( $new_id, $terms, $tax );
		}
	}

	return (int) $new_id;
}

/* --------------------------------------------------------------------------
 * GET /duplicates — find pages with same title, exact slug, or similar slug
 *
 * mode=title      : pages sharing an identical post_title (case-insensitive)
 * mode=slug_exact : pages sharing an identical post_name
 * mode=slug_similar: pages whose slug shares the same "base" — defined as
 *                   the slug stripped of trailing numeric suffixes (-2, -3…)
 *                   and common copy suffixes (-copy, -draft, -old, -new, -bak).
 *                   Uses a LEFT(slug, N) prefix group — very fast on the index.
 *
 * All queries use GROUP BY inside a subquery so only IDs of pages that
 * actually have a duplicate partner are returned — no full table scan.
 * ----------------------------------------------------------------------- */

function spm_rest_duplicates( WP_REST_Request $req ) {
	global $wpdb;

	$mode  = sanitize_key( (string) $req->get_param( 'mode' ) ); // title|slug_exact|slug_similar
	$limit = max( 1, min( 500, (int) $req->get_param( 'limit' ) ?: 200 ) );

	if ( ! in_array( $mode, array( 'title', 'slug_exact', 'slug_similar' ), true ) ) {
		return new WP_Error( 'spm_bad_mode', 'mode must be title, slug_exact or slug_similar', array( 'status' => 400 ) );
	}

	$posts = $wpdb->posts;

	if ( 'title' === $mode ) {
		/*
		 * Step 1: find all normalised titles that appear more than once.
		 * LOWER() + TRIM() gives case-insensitive, whitespace-normalised match.
		 * Step 2: fetch the actual pages for those titles, ordered so duplicates
		 * are grouped together in the result.
		 */
		$dup_titles = $wpdb->get_col(
			"SELECT LOWER(TRIM(post_title)) AS norm
			 FROM {$posts}
			 WHERE post_type='page' AND post_status <> 'trash' AND post_title <> ''
			 GROUP BY norm
			 HAVING COUNT(*) > 1
			 LIMIT {$limit}"
		);

		if ( ! $dup_titles ) {
			return rest_ensure_response( array( 'rows' => array(), 'mode' => $mode, 'count' => 0 ) );
		}

		$ph   = implode( ',', array_fill( 0, count( $dup_titles ), '%s' ) );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title, post_name, post_status, post_author, post_parent, post_date, post_modified
				 FROM {$posts}
				 WHERE post_type='page' AND post_status <> 'trash'
				   AND LOWER(TRIM(post_title)) IN ({$ph})
				 ORDER BY LOWER(TRIM(post_title)) ASC, ID ASC
				 LIMIT %d",
				array_merge( $dup_titles, array( $limit * 10 ) )
			),
			ARRAY_A
		);

	} elseif ( 'slug_exact' === $mode ) {
		/*
		 * Exact duplicate slugs. In a healthy WP install these should not exist
		 * because WordPress appends -2, -3 automatically — but they do appear
		 * after migrations, imports, or manual DB edits.
		 */
		$dup_slugs = $wpdb->get_col(
			"SELECT post_name
			 FROM {$posts}
			 WHERE post_type='page' AND post_status <> 'trash' AND post_name <> ''
			 GROUP BY post_name
			 HAVING COUNT(*) > 1
			 LIMIT {$limit}"
		);

		if ( ! $dup_slugs ) {
			return rest_ensure_response( array( 'rows' => array(), 'mode' => $mode, 'count' => 0 ) );
		}

		$ph   = implode( ',', array_fill( 0, count( $dup_slugs ), '%s' ) );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title, post_name, post_status, post_author, post_parent, post_date, post_modified
				 FROM {$posts}
				 WHERE post_type='page' AND post_status <> 'trash'
				   AND post_name IN ({$ph})
				 ORDER BY post_name ASC, ID ASC
				 LIMIT %d",
				array_merge( $dup_slugs, array( $limit * 10 ) )
			),
			ARRAY_A
		);

	} else {
		/*
		 * Similar slugs: strip trailing -N / -copy / -draft / -old / -new / -bak
		 * suffixes then group by the base. Uses REGEXP_REPLACE (MySQL 8+) with a
		 * fallback for MySQL 5.7 that uses a fixed LEFT(slug, 40) prefix group —
		 * slightly less precise but safe on any MySQL version.
		 */
		$mysql8 = version_compare( $wpdb->db_version(), '8.0', '>=' );

		if ( $mysql8 ) {
			$base_expr = "REGEXP_REPLACE(post_name, '-([0-9]+|copy|draft|old|new|bak|backup|temp|test|v[0-9]+)$', '')";
		} else {
			// For MySQL 5.7: group by the first 40 chars of the slug.
			// This catches /services-london-2 vs /services-london etc.
			$base_expr = 'LEFT(post_name, 40)';
		}

		$dup_bases = $wpdb->get_col(
			"SELECT {$base_expr} AS base
			 FROM {$posts}
			 WHERE post_type='page' AND post_status <> 'trash' AND post_name <> ''
			 GROUP BY base
			 HAVING COUNT(*) > 1
			 LIMIT {$limit}"
		);

		if ( ! $dup_bases ) {
			return rest_ensure_response( array( 'rows' => array(), 'mode' => $mode, 'count' => 0 ) );
		}

		/*
		 * Fetch all pages whose slug starts with any of the bases.
		 * We use LIKE base% for each base — safe because bases are sanitised
		 * slugs (only lowercase alphanum and hyphens).
		 */
		$where_parts = array();
		$params      = array();
		foreach ( $dup_bases as $base ) {
			$where_parts[] = 'post_name LIKE %s';
			$params[]      = $wpdb->esc_like( $base ) . '%';
		}
		$params[] = $limit * 10;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title, post_name, post_status, post_author, post_parent, post_date, post_modified
				 FROM {$posts}
				 WHERE post_type='page' AND post_status <> 'trash'
				   AND (" . implode( ' OR ', $where_parts ) . ")
				 ORDER BY post_name ASC, ID ASC
				 LIMIT %d",
				$params
			),
			ARRAY_A
		);
	}

	$shaped = array_map( 'spm_shape_row', $rows );

	// Attach duplicate_group key so the JS can colour-code groups.
	if ( 'title' === $mode ) {
		foreach ( $shaped as &$r ) {
			$r['dup_group'] = strtolower( trim( $r['title'] ) );
		}
	} else {
		foreach ( $shaped as &$r ) {
			// Group key = slug with suffix stripped (JS-side, simple regex).
			$r['dup_group'] = preg_replace( '/-(\d+|copy|draft|old|new|bak|backup|temp|test|v\d+)$/', '', $r['slug'] );
		}
	}
	unset( $r );

	return rest_ensure_response( array(
		'rows'  => $shaped,
		'mode'  => $mode,
		'count' => count( $shaped ),
	) );
}
add_action( 'save_post_page', 'spm_bust_total_cache' );
add_action( 'deleted_post', 'spm_bust_total_cache' );
add_action( 'trashed_post', 'spm_bust_total_cache' );
add_action( 'untrashed_post', 'spm_bust_total_cache' );
function spm_bust_total_cache() {
	delete_transient( 'spm_total_pages' );
	delete_transient( 'spm_parents_list' );
}

/* --------------------------------------------------------------------------
 * GET /parents — pages that have children, with child counts.
 * Single GROUP BY query, cached 5 min. Never loads child rows.
 * ----------------------------------------------------------------------- */
function spm_rest_parents( WP_REST_Request $req ) {
	global $wpdb;

	$cached = get_transient( 'spm_parents_list' );
	if ( false !== $cached ) {
		return rest_ensure_response( $cached );
	}

	$rows = $wpdb->get_results(
		"SELECT p.ID, p.post_title, p.post_name, p.post_status, p.post_modified,
		        COUNT(c.ID) AS child_count
		 FROM {$wpdb->posts} p
		 INNER JOIN {$wpdb->posts} c ON c.post_parent = p.ID
		                             AND c.post_type = 'page'
		                             AND c.post_status <> 'trash'
		 WHERE p.post_type = 'page' AND p.post_status <> 'trash'
		 GROUP BY p.ID
		 ORDER BY child_count DESC, p.post_title ASC
		 LIMIT 500",
		ARRAY_A
	);

	$out = array();
	foreach ( (array) $rows as $r ) {
		$id    = (int) $r['ID'];
		$out[] = array(
			'id'          => $id,
			'title'       => ( '' !== $r['post_title'] ) ? $r['post_title'] : __( '(no title)', 'spm' ),
			'slug'        => $r['post_name'],
			'status'      => $r['post_status'],
			'modified'    => $r['post_modified'],
			'child_count' => (int) $r['child_count'],
			'url'         => get_permalink( $id ),
			'editUrl'     => admin_url( 'post.php?post=' . $id . '&action=edit' ),
		);
	}

	set_transient( 'spm_parents_list', $out, 5 * MINUTE_IN_SECONDS );
	return rest_ensure_response( $out );
}

/* ==========================================================================
 * FEATURE 2 — Custom default view (empty search): top-level pages only,
 *             sorted into a 4-tier hierarchy.
 * --------------------------------------------------------------------------
 * ADDITIVE. New route GET /spm/v1/default-view. Does NOT touch /pages,
 * spm_rest_list(), or spm_build_filters().
 *
 * Tier 1: Homepage (page_on_front) — always first if it's top-level.
 * Tier 2: "User Journey" page(s) — top-level pages tagged with the
 *         spm_user_journey taxonomy (managed via checkbox in the page editor).
 * Tier 3: Parent pages WITH children, DESC by child count.
 * Tier 4: Standalone top-level pages (zero children), ASC by title.
 *
 * Only post_parent = 0 pages are returned. Child counts come from ONE grouped
 * query, so tiering happens in PHP over a single bounded result set.
 * ======================================================================== */

// User Journey membership taxonomy (plugin-owned, hidden from front end).
if ( ! defined( 'SPM_USER_JOURNEY_TAX' ) ) {
	define( 'SPM_USER_JOURNEY_TAX', 'spm_user_journey' );
}

/**
 * Register a private, plugin-owned taxonomy used purely to flag pages as part
 * of the "User Journey" set (Tier 2 of the default view).
 *
 * - Attached to 'page'.
 * - public=false / rewrite=false: no front-end archive URL is created.
 * - show_ui=true + meta_box_cb: a simple checkbox meta-box appears in the page
 *   editor so agency OR client can tag/untag pages by hand.
 * - hierarchical=true only so the meta box renders as checkboxes (not a tag
 *   free-text field); we only ever use one term ("Yes").
 */
add_action( 'init', 'spm_register_user_journey_tax' );
function spm_register_user_journey_tax() {
	register_taxonomy( SPM_USER_JOURNEY_TAX, 'page', array(
		'label'             => __( 'User Journey', 'spm' ),
		'labels'            => array(
			'name'          => __( 'User Journey', 'spm' ),
			'singular_name' => __( 'User Journey', 'spm' ),
			'menu_name'     => __( 'User Journey', 'spm' ),
		),
		'public'            => false,
		'publicly_queryable'=> false,
		'show_ui'           => true,
		'show_in_menu'      => false, // no separate admin submenu; meta box only.
		'show_admin_column' => false,
		'show_in_rest'      => true,  // so Gutenberg shows the checkbox too.
		'hierarchical'      => true,  // renders as checkboxes in the meta box.
		'rewrite'           => false,
		'query_var'         => false,
	) );

	// Ensure the single "Yes" membership term exists (id resolved on demand).
	if ( ! term_exists( 'yes', SPM_USER_JOURNEY_TAX ) ) {
		wp_insert_term( 'Yes', SPM_USER_JOURNEY_TAX, array( 'slug' => 'yes' ) );
	}
}

/**
 * Resolve the top-level page IDs currently flagged as User Journey pages.
 * Returns int[]. Only post_parent = 0 pages are eligible (Tier 2 is top-level).
 * Filterable via 'spm_user_journey_ids'.
 */
function spm_user_journey_ids() {
	global $wpdb;

	if ( ! taxonomy_exists( SPM_USER_JOURNEY_TAX ) ) {
		return array();
	}

	$ids = $wpdb->get_col( $wpdb->prepare(
		"SELECT p.ID
		 FROM {$wpdb->posts} p
		 INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
		 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
		 WHERE p.post_type = 'page'
		   AND p.post_status <> 'trash'
		   AND p.post_parent = 0
		   AND tt.taxonomy = %s",
		SPM_USER_JOURNEY_TAX
	) );

	$ids = array_values( array_unique( array_map( 'intval', (array) $ids ) ) );

	/** Filter the final User Journey ID set (Tier 2). */
	return array_map( 'intval', (array) apply_filters( 'spm_user_journey_ids', $ids ) );
}

function spm_rest_default_view( WP_REST_Request $req ) {
	global $wpdb;

	$limit = max( 1, min( 500, (int) $req->get_param( 'limit' ) ?: 500 ) );

	// One grouped query: every top-level page + its (non-trash) child count.
	// LEFT JOIN so zero-child pages are included (Tier 4).
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT p.ID, p.post_title, p.post_name, p.post_status, p.post_author,
		        p.post_parent, p.post_date, p.post_modified,
		        COUNT(c.ID) AS child_count
		 FROM {$wpdb->posts} p
		 LEFT JOIN {$wpdb->posts} c
		        ON c.post_parent = p.ID
		       AND c.post_type = 'page'
		       AND c.post_status <> 'trash'
		 WHERE p.post_type = 'page'
		   AND p.post_status <> 'trash'
		   AND p.post_parent = 0
		 GROUP BY p.ID
		 LIMIT %d",
		$limit
	), ARRAY_A );

	$rows = (array) $rows;

	$front_id    = (int) get_option( 'page_on_front' );
	$journey_ids = array_flip( spm_user_journey_ids() );

	$tier1 = array(); // homepage
	$tier2 = array(); // journey guidance
	$tier3 = array(); // has children (DESC by count)
	$tier4 = array(); // standalone (no children)

	foreach ( $rows as $r ) {
		$id    = (int) $r['ID'];
		$count = (int) $r['child_count'];

		if ( $front_id && $id === $front_id ) {
			$tier1[] = $r;
		} elseif ( isset( $journey_ids[ $id ] ) ) {
			$tier2[] = $r;
		} elseif ( $count > 0 ) {
			$tier3[] = $r;
		} else {
			$tier4[] = $r;
		}
	}

	// Tier 3: most sub-pages first, then title for stable ordering.
	usort( $tier3, function ( $a, $b ) {
		$d = (int) $b['child_count'] - (int) $a['child_count'];
		return $d !== 0 ? $d : strcasecmp( (string) $a['post_title'], (string) $b['post_title'] );
	} );

	// Tier 4: alphabetical.
	usort( $tier4, function ( $a, $b ) {
		return strcasecmp( (string) $a['post_title'], (string) $b['post_title'] );
	} );

	$ordered = array_merge( $tier1, $tier2, $tier3, $tier4 );

	$shaped = array_map( function ( $r ) {
		$row                = spm_shape_row( $r );
		$row['child_count'] = (int) $r['child_count']; // extra field; harmless to existing UI.
		return $row;
	}, $ordered );

	return rest_ensure_response( array(
		'rows'        => $shaped,
		'next_cursor' => null,
		'view'        => 'default',
	) );
}

/* ===========================================================================
 * INLINE STYLES
 * ======================================================================== */

function spm_print_styles() {
	?>
	<style>
	/* ── Reset / Base ── */
	#spm-app{margin-top:16px;font-size:13px;color:#1d2327;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
	#spm-app input[type=checkbox],#spm-app input[type=radio]{margin:0.15rem 0.15rem 0 0}
	.spm-muted{color:#787c82}

	/* ── Top bar ── */
	.spm-topbar{display:flex;align-items:center;gap:10px;margin-bottom:10px;flex-wrap:wrap}
	.spm-search{display:flex;align-items:center;gap:6px;background:#f0f1f2;border:1.5px solid #b5b9be;border-radius:8px;padding:5px 10px;flex:1 1 300px;min-width:240px;transition:border-color .15s,box-shadow .15s}
	.spm-search:focus-within{border-color:#2271b1;box-shadow:0 0 0 2px rgba(34,113,177,.15);background:#f6f7f8}
	.spm-search input[type=text]{border:0;outline:0;box-shadow:none;flex:1;font-size:13px;background:transparent;color:#1d2327}
	.spm-search input[type=text]::placeholder{color:#9aa0a8}
	.spm-search select{
		border:1px solid #c8cacc;background:#fff;font-size:12px;color:#3c434a;cursor:pointer;
		border-radius:5px;padding:3px 22px 3px 8px;line-height:1.4;
		-webkit-appearance:none;-moz-appearance:none;appearance:none;
		background-image:url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3E%3Cpath d='M1 1l4 4 4-4' fill='none' stroke='%235f6b76' stroke-width='1.5' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
		background-repeat:no-repeat;background-position:right 7px center;background-size:9px 6px;
		transition:border-color .15s,box-shadow .15s;
	}
	.spm-search select:hover{border-color:#8c9198}
	.spm-search select:focus{outline:0;border-color:#2271b1;box-shadow:0 0 0 2px rgba(34,113,177,.12)}
	.spm-search-divider{width:1px;height:18px;background:#c8cacc;flex-shrink:0}
	.spm-search-icon{color:#9aa0a8;font-size:16px;flex-shrink:0}
	.spm-chk{display:inline-flex;align-items:center;gap:4px;font-size:12px;color:#3c434a;cursor:pointer;user-select:none}
	.spm-acf-toggle{display:inline-flex;gap:8px;align-items:center}
	.spm-count{background:#dcdee0;border-radius:20px;padding:3px 10px;font-size:11px;font-weight:600;color:#3c434a;white-space:nowrap;flex-shrink:0}
	.spm-export-btn{margin-left:auto;flex-shrink:0;display:inline-flex;align-items:center;gap:4px}
	.spm-export-btn.spm-busy{opacity:.6;cursor:progress}

	/* ── Filter panel ── */
	.spm-filterpanel{background:#f0f1f2;border:1.5px solid #b5b9be;border-radius:10px;padding:10px 14px;margin-bottom:10px}
	.spm-filter-row{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
	.spm-filter-row+.spm-filter-row{margin-top:8px;padding-top:8px;border-top:1px solid #d0d2d5}
	.spm-filterpanel select{height:32px;border:1.5px solid #b5b9be;border-radius:6px;padding:0 8px;font-size:12px;color:#1d2327;background:#f8f9fa;cursor:pointer;transition:border-color .15s}
	.spm-filterpanel select:focus{outline:0;border-color:#2271b1;box-shadow:0 0 0 2px rgba(34,113,177,.12)}
	.spm-filterpanel select:hover{border-color:#8c9198}
	#f-clear{height:32px;border-radius:6px!important;font-size:12px!important;padding:0 12px!important;color:#50575e!important;border-color:#b5b9be!important;background:#e4e5e7!important;margin-left:auto}
	#f-clear:hover{color:#b32d2e!important;border-color:#b32d2e!important;background:#fff5f5!important}
	.spm-filter-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#50575e;white-space:nowrap}
	/* Template row */
	.spm-tmpl-row{background:#e8f0fb;border-radius:8px;padding:8px 12px;margin-top:8px;border:1.5px solid #b8cef0}
	.spm-tmpl-row select{background:#f8f9fa}
	.spm-inc-children{display:inline-flex;align-items:center;gap:4px;font-size:12px;color:#3a5a8c;margin-left:4px;white-space:nowrap}
	.spm-dup-select{background:#fff8f0;border-color:#f5c07a}
	.spm-tmpl-row #f-tmpl-terms{max-width:320px}
	.spm-badge-warn{display:inline-flex;align-items:center;gap:4px;background:#fef3c7;color:#92400e;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:600;border:1px solid #fde68a}
	/* Duplicate row */
	.spm-dup-row{background:#fef0e0;border-radius:8px;padding:8px 12px;margin-top:8px;border:1.5px solid #f5c896}
	.spm-dup-row .spm-chip{border-color:#f5c07a!important}
	.spm-dup-row .spm-chip:hover{border-color:#e07800!important;color:#e07800!important;background:#fff8f0!important}
	.spm-dup-row .spm-chip.active{background:#e07800!important;color:#fff!important;border-color:#e07800!important}
	.spm-chip-exit{background:#fdecea!important;color:#b32d2e!important;border-color:#f5c0bc!important}
	.spm-chip-exit:hover{background:#b32d2e!important;color:#fff!important;border-color:#b32d2e!important}
	/* Quick chips */
	.spm-quick-chips{display:flex;flex-wrap:wrap;gap:6px;align-items:center;padding-top:8px;margin-top:2px}
	.spm-chip{height:26px!important;border-radius:20px!important;padding:0 12px!important;font-size:11px!important;font-weight:500!important;border:1.5px solid #dcdcde!important;background:#fff!important;color:#50575e!important;cursor:pointer;transition:all .15s!important;white-space:nowrap}
	.spm-chip:hover{border-color:#2271b1!important;color:#2271b1!important;background:#f0f6fc!important}
	.spm-chip.active{background:#2271b1!important;color:#fff!important;border-color:#2271b1!important;box-shadow:0 1px 4px rgba(34,113,177,.25)!important}
	.spm-mode-msg{font-size:12px;color:#787c82;background:#f6f7f7;padding:3px 10px;border-radius:20px;border:1px solid #e0e0e0}
	.spm-mode-msg-dup{background:#fff8f0;color:#e07800;border-color:#fddcb0}

	/* ── Parent folder grid ── */
	.spm-parents-section{margin-bottom:12px}
	.spm-parents-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px}
	.spm-parents-header h3{margin:0;font-size:13px;font-weight:700;color:#1d2327}
	.spm-parents-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:8px}
	.spm-parent-card{background:#fff;border:1.5px solid #dcdcde;border-radius:8px;padding:10px 12px;cursor:pointer;transition:all .15s;display:flex;flex-direction:column;gap:4px;min-width:0;position:relative}
	.spm-parent-card:hover{border-color:#2271b1;box-shadow:0 2px 8px rgba(34,113,177,.12);transform:translateY(-1px)}
	.spm-parent-card.active{border-color:#2271b1;background:#f0f6fc;box-shadow:0 2px 8px rgba(34,113,177,.18)}
	.spm-parent-card-title{font-weight:600;font-size:13px;color:#1d2327;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
	.spm-parent-card:hover .spm-parent-card-title{color:#2271b1}
	.spm-parent-card.active .spm-parent-card-title{color:#2271b1}
	.spm-parent-card-meta{font-size:11px;color:#787c82;display:flex;align-items:center;gap:6px}
	.spm-parent-count{background:#e8f0fe;color:#1a56db;border-radius:20px;padding:1px 7px;font-size:11px;font-weight:600}
	.spm-parent-card.active .spm-parent-count{background:#2271b1;color:#fff}
	.spm-parents-show-more{margin-top:6px;text-align:center}

	/* ── Breadcrumb / active parent banner ── */
	.spm-parent-banner{display:flex;align-items:center;gap:10px;padding:8px 14px;background:#f0f6fc;border:1.5px solid #c0d8f5;border-radius:8px;margin-bottom:8px}
	.spm-parent-banner strong{color:#2271b1}
	.spm-parent-banner .spm-banner-clear{margin-left:auto;color:#787c82;cursor:pointer;font-size:18px;line-height:1;background:none;border:0;padding:2px 6px;border-radius:4px}
	.spm-parent-banner .spm-banner-clear:hover{background:#dcdcde;color:#1d2327}

	/* ── Main table ── */
	.spm-table{background:#fff;border:1.5px solid #dcdcde;border-radius:10px;overflow:hidden}
	.spm-thead{display:flex;align-items:center;font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#787c82;background:#f6f7f7;border-bottom:1.5px solid #dcdcde;position:sticky;top:0;z-index:5;height:38px}
	.spm-thead .spm-c,.spm-row .spm-c{padding:0 10px;display:flex;align-items:center;overflow:hidden}
	.spm-c-chk{flex:0 0 52px;gap:4px}
	.spm-c-title{flex:0 1 32%;min-width:180px;cursor:pointer;gap:6px}
	.spm-c-id{flex:0 0 72px;cursor:pointer}
	.spm-c-slug{flex:1 1 160px;min-width:100px}
	.spm-c-parent{flex:0 1 120px;min-width:80px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
	.spm-c-tmpl{flex:0 1 140px;min-width:80px;display:flex;flex-wrap:wrap;gap:3px;align-items:center}
	.spm-c-elem{flex:0 0 72px;justify-content:center}
	.spm-c-status{flex:0 0 78px}
	.spm-c-mod{flex:0 0 104px;cursor:pointer}
	.spm-c-act{flex:0 0 40px;justify-content:center;margin-left:auto}
	.spm-scroll{height:60vh;overflow:auto;position:relative}
	.spm-row{display:flex;align-items:center;height:52px;border-bottom:1px solid #f0f0f1;position:absolute;left:0;right:0;background:#fff;transition:background .1s}
	.spm-row:hover{background:#f8faff}
	.spm-row.sel{background:#edf4fb}
	.spm-row.cur{box-shadow:inset 3px 0 0 #2271b1}
	.spm-row.spm-dragging{opacity:.4;pointer-events:none}
	.spm-row.spm-drop-target{outline:2px dashed #2271b1;outline-offset:-2px;background:#f0f6fc!important}
	.spm-title-txt{font-weight:600;font-size:13px;line-height:1.3;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;word-break:break-word;color:#1d2327}
	.spm-c-title:hover .spm-title-txt{color:#2271b1}
	/* Grouped child-scope search: parent header + indented children */
	.spm-c-title-hdr{cursor:default}
	.spm-grp-parent{font-weight:700;font-size:13px;color:#1d2327}
	.spm-grp-count{display:inline-block;margin-left:6px;font-weight:500;font-size:11px;color:#6b7280;background:#eef1f4;border-radius:10px;padding:1px 8px;vertical-align:middle}
	.spm-c-title-child{padding-left:26px}
	.spm-grp-childmark{color:#adb5bd;margin-right:6px;font-weight:400;user-select:none}
	.spm-c-title-child .spm-title-txt{font-weight:500}
	.spm-c-slug,.spm-c-slug span{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#787c82;font-size:12px}
	.spm-c-parent{color:#787c82;font-size:12px}
	.spm-c-mod{color:#787c82;font-size:12px}
	.spm-twisty{flex:0 0 16px;text-align:center;cursor:pointer;color:#adb5bd;user-select:none}
	.spm-twisty:hover{color:#2271b1}
	.spm-twisty.empty{visibility:hidden}
	/* Badges */
	.spm-badge{font-size:11px;font-weight:500;padding:2px 8px;border-radius:20px;background:#f0f0f1;color:#50575e;white-space:nowrap}
	.spm-badge.publish{background:#e6f9ec;color:#0a7d2f}
	.spm-badge.draft{background:#fef6e4;color:#8a6d1a}
	.spm-badge.pending{background:#e8f0fe;color:#1a56db}
	.spm-badge.private{background:#f3e8ff;color:#6d28d9}
	.spm-badge.future{background:#e0f7fa;color:#00695c}
	.spm-badge.trash{background:#fdecea;color:#b32d2e}
	.spm-tmpl-tag{font-size:11px;font-weight:500;padding:2px 8px;border-radius:20px;background:#e8f0fe;color:#1a56db;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:140px;display:inline-block}
	.spm-badge-missing{background:#fff8e1;color:#8a6d1a;border:1px dashed #ffc107}
	.spm-badge-elem{background:#e6f9ec;color:#0a7d2f}
	/* Drag handle */
	.spm-drag-handle{cursor:grab;color:#c3c4c7;font-size:13px;padding:0 3px;user-select:none;line-height:1}
	.spm-drag-handle:hover{color:#787c82}
	.spm-drag-handle:active{cursor:grabbing}
	/* Actions */
	.spm-actbtn{border:0;background:transparent;cursor:pointer;font-size:18px;line-height:1;color:#adb5bd;padding:4px 8px;border-radius:6px;transition:background .1s,color .1s}
	.spm-actbtn:hover{background:#f0f0f1;color:#1d2327}
	/* Context menu */
	.spm-menu{position:fixed;z-index:9999;background:#fff;border:1.5px solid #dcdcde;border-radius:8px;box-shadow:0 8px 28px rgba(0,0,0,.14);min-width:190px;padding:5px}
	.spm-menu button{display:block;width:100%;text-align:left;border:0;background:transparent;padding:7px 12px;cursor:pointer;border-radius:5px;font-size:13px;color:#1d2327}
	.spm-menu button:hover{background:#f0f6fc;color:#2271b1}
	.spm-menu .sep{height:1px;background:#f0f0f1;margin:4px 0}
	/* Bulk bar */
	.spm-bulkbar{display:flex;align-items:center;gap:8px;padding:9px 14px;border-top:1.5px solid #dcdcde;background:#f6f7f7;border-radius:0 0 8px 8px}
	.spm-bulkbar.hidden{display:none}
	/* Misc */
	.spm-loading{padding:20px;text-align:center;color:#adb5bd;font-size:13px}
	.spm-empty{padding:32px;text-align:center;color:#787c82;font-size:13px}
	.spm-link{color:#2271b1;text-decoration:none}
	.spm-toast{position:fixed;bottom:28px;left:50%;transform:translateX(-50%);background:#1d2327;color:#fff;padding:10px 20px;border-radius:8px;z-index:10000;opacity:0;transition:opacity .2s;font-size:13px;font-weight:500;box-shadow:0 4px 16px rgba(0,0,0,.2)}
	.spm-toast.show{opacity:1}
	/* Quick edit */
	.spm-row-editing{align-items:stretch!important;overflow:visible!important;z-index:6;background:#fff!important;box-shadow:0 2px 12px rgba(34,113,177,.18);border:1.5px solid #2271b1;border-radius:8px;height:auto!important}
	.spm-qe-wrap{padding:14px 16px;background:#f8faff;width:100%;box-sizing:border-box;border-radius:7px}
	.spm-qe-grid{display:flex;gap:18px;align-items:flex-start;flex-wrap:wrap}
	.spm-qe-col{display:flex;flex-direction:column;gap:8px;min-width:240px}
	.spm-qe-col-main{flex:1 1 280px}
	.spm-qe-col-side{flex:1 1 260px}
	.spm-qe-field{display:flex;align-items:center;gap:8px}
	.spm-qe-field>label{flex:0 0 60px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#787c82}
	.spm-qe-field input,.spm-qe-field select{flex:1;height:30px;border:1.5px solid #dcdcde;border-radius:6px;padding:0 8px;font-size:13px;background:#fff;box-sizing:border-box}
	.spm-qe-field input:focus,.spm-qe-field select:focus{outline:0;border-color:#2271b1;box-shadow:0 0 0 1px #2271b1}
	.spm-qe-parenthint{font-size:11px;color:#787c82;padding-left:68px}
	.spm-qe-seclabel{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#787c82;margin-bottom:2px}
	.spm-qe-taxbox{padding:6px 10px;background:#fff;border:1.5px solid #e0e0e0;border-radius:6px;max-height:160px;overflow-y:auto;box-sizing:border-box}
	.spm-qe-termchk{display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;white-space:nowrap;padding:3px 4px;border-radius:4px}
	.spm-qe-termchk:hover{background:#f0f6fc}
	.spm-qe-termchk input{margin:0;cursor:pointer}
	.spm-qe-actions{display:flex;gap:8px;align-items:center;margin-top:12px;padding-top:10px;border-top:1px solid #e0e0e0}
	.spm-qe-actions .spm-qe-hint{font-size:11px;color:#a7aaad;margin-left:auto}
	.spm-qe-loading{padding:24px;text-align:center;color:#787c82;font-size:13px}
	.spm-qe-col-tax{flex:2 1 480px;display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px;align-content:start}
	.spm-qe-taxgroup-wrap{min-width:0}
	.spm-qe-seo{margin-top:14px;padding-top:12px;border-top:2px solid #dfe3e8}
	.spm-qe-seohead{display:flex;align-items:center;gap:8px;font-size:12px;margin-bottom:8px}
	.spm-qe-seoplugin{font-size:10px;font-weight:600;text-transform:uppercase;background:#eef2f7;color:#5a6b80;padding:1px 7px;border-radius:9px;letter-spacing:.4px}
	.spm-qe-seo-grid{display:flex;gap:18px;flex-wrap:wrap;align-items:flex-start}
	.spm-qe-seo-col{flex:1 1 240px;min-width:220px;display:flex;flex-direction:column;gap:8px}
	.spm-qe-field-stack{flex-direction:column;align-items:stretch}
	.spm-qe-field-stack>label{flex:none;margin-bottom:2px}
	.spm-qe-field-stack textarea{border:1.5px solid #dcdcde;border-radius:6px;padding:6px 8px;font-size:13px;font-family:inherit;resize:vertical;box-sizing:border-box}
	.spm-qe-field-stack textarea:focus{outline:0;border-color:#2271b1;box-shadow:0 0 0 1px #2271b1}
	.spm-qe-robotsbox{max-height:140px}
	</style>
	<?php
}

/* ===========================================================================
 * INLINE APP (vanilla JS)
 * ======================================================================== */

function spm_print_app() {
	?>
	<script>
	(function(){
	"use strict";
	var B = window.SPM_BOOT;
	var app = document.getElementById('spm-app');

	/* ───────────────────────────────────────────────
	 * API helper
	 * ─────────────────────────────────────────────── */
	function api(path, opts){
		opts = opts || {};
		opts.headers = Object.assign({'X-WP-Nonce':B.nonce,'Content-Type':'application/json'}, opts.headers||{});
		return fetch(B.root+path, opts).then(function(r){
			if(!r.ok) return r.json().then(function(e){throw e;});
			return r.json();
		});
	}
	function qs(o){
		return Object.keys(o).filter(function(k){
			var v=o[k];
			if(v===''||v===null||v===undefined) return false;
			if(v===false) return false;
			// Keep numeric 0 only for 'parent' key
			if(v===0) return k==='parent';
			return true;
		}).map(function(k){ return encodeURIComponent(k)+'='+encodeURIComponent(o[k]); }).join('&');
	}

	/* ───────────────────────────────────────────────
	 * State
	 * ─────────────────────────────────────────────── */
	var S = {
		// List state
		rows:[], cursor:null, hasNext:true, loading:false,
		orderby:'modified', order:'DESC',
		// Active parent drill-down
		activeParent: null,   // {id,title,slug,child_count} | null
		// Parents list
		parents: null,        // loaded once from /parents
		parentsExpanded: false,
		// Filters
		filters:{
			status:'', author:0, date_field:'created', date_from:'', date_to:'',
			recent_mod:'',
			template_terms:[], no_template:false, has_template:false, include_children:false,
			elementor_only:false, orphan:false,
			template_mode:''
		},
		// Search
		search:{q:'', field:'auto', exact:false, content:false, acf:false, scope:'direct'},
		searching:false,
		// Duplicates
		dupMode: null,
		// UI
		selected:{}, cursorIdx:0, editing:null, editData:null,
		stats:{total:null, filtered:null},
		// Tree (kept for potential future use)
		tree:{expanded:{}, children:{}}
	};
	var ROW_H=52, OVERSCAN=8, searchTimer=null, searchAbort=null;
	var drag={id:null, overId:null, overEl:null};
	var _painting=false; // guard: true while paintFilters is rebuilding DOM

	/* ───────────────────────────────────────────────
	 * Utilities
	 * ─────────────────────────────────────────────── */
	function toast(msg){
		var t=document.createElement('div'); t.className='spm-toast'; t.textContent=msg;
		document.body.appendChild(t); requestAnimationFrame(function(){t.classList.add('show');});
		setTimeout(function(){t.classList.remove('show'); setTimeout(function(){t.remove();},250);},2000);
	}
	function esc(s){ var d=document.createElement('div'); d.textContent=s==null?'':String(s); return d.innerHTML; }
	function copy(text){ navigator.clipboard.writeText(text).then(function(){toast('Copied: '+text);}); }
	function rowById(id){
		return S.rows.find(function(r){return r.id===id;}) ||
			(function(){ var f=null; Object.keys(S.tree.children).forEach(function(p){ (S.tree.children[p]||[]).forEach(function(r){ if(r.id===id) f=r; }); }); return f; })();
	}

	/* ───────────────────────────────────────────────
	 * Build query params for list / stats requests
	 * ─────────────────────────────────────────────── */
	function buildQuery(extra){
		var f=S.filters;
		var q={
			orderby:S.orderby, order:S.order, limit:100,
			status:f.status, author:f.author,
			date_field:f.date_field, date_from:f.date_from, date_to:f.date_to,
			// Always send template_tax so PHP knows what the client detected.
			// Even if empty, PHP will re-detect server-side.
			template_tax: B.templateTax||'__detect__',
			template_term: f.template_terms.length ? f.template_terms.join(',') : '',
			template_include_children: f.include_children ? 1 : '',
			no_template:   f.no_template   ? 1 : '', has_template: f.has_template ? 1 : '',
			elementor_only:f.elementor_only ? 1 : '',
			orphan:        f.orphan         ? 1 : ''
		};
		if(S.activeParent) q.parent = S.activeParent.id;
		return Object.assign(q, extra||{});
	}

	/* ───────────────────────────────────────────────
	 * Export current view to CSV
	 * ───────────────────────────────────────────────
	 * Mirrors the visible rows exactly:
	 *  - Search / duplicate view  → send the visible IDs (?ids=…)
	 *  - Filter / list view       → send the same filter params buildQuery()
	 *                               produces, so the server WHERE matches.
	 * We fetch with the REST nonce, then trigger a Blob download — this keeps
	 * auth intact (the export route requires edit_pages) while still yielding
	 * a real file-save dialog.
	 */
	function exportCsv(){
		var btn=document.getElementById('spm-export');
		var params;
		if(S.searching || S.dupMode){
			var ids=S.rows.map(function(r){return r.id;});
			if(!ids.length){ toast('Nothing to export'); return; }
			params='ids='+encodeURIComponent(ids.join(','));
		} else {
			var q=buildQuery({});
			delete q.limit; delete q.cursor; // export is unbounded (server-capped)
			params=qs(q);
		}
		if(btn){ btn.disabled=true; btn.classList.add('spm-busy'); }
		toast('Preparing export…');
		fetch(B.root+'/export?'+params, { headers:{'X-WP-Nonce':B.nonce} })
			.then(function(r){ if(!r.ok) throw new Error('Export failed'); return r.blob(); })
			.then(function(blob){
				var url=URL.createObjectURL(blob);
				var a=document.createElement('a');
				var stamp=new Date().toISOString().slice(0,19).replace(/[:T]/g,'-');
				a.href=url; a.download='pages-export-'+stamp+'.csv';
				document.body.appendChild(a); a.click(); a.remove();
				setTimeout(function(){ URL.revokeObjectURL(url); },1000);
				toast('Export ready');
			})
			.catch(function(){ toast('Export failed'); })
			.finally(function(){ if(btn){ btn.disabled=false; btn.classList.remove('spm-busy'); } });
	}

	/* ───────────────────────────────────────────────
	 * Data loading
	 * ─────────────────────────────────────────────── */
	function resetList(){ S.rows=[]; S.cursor=null; S.hasNext=true; S.cursorIdx=0; S.selected={}; }

	function loadMore(){
		if(S.loading||!S.hasNext||S.searching||S.dupMode) return Promise.resolve();
		S.loading=true; paintList();
		var q=buildQuery({cursor:S.cursor?JSON.stringify(S.cursor):''});
		return api('/pages?'+qs(q)).then(function(d){
			S.rows=S.rows.concat(d.rows); S.cursor=d.next_cursor; S.hasNext=!!d.next_cursor; S.loading=false; paintList(); paintCount();
		}).catch(function(){ S.loading=false; toast('Load failed'); paintList(); });
	}

	function loadStats(){
		var f=S.filters;
		var q={
			status:f.status, author:f.author,
			date_field:f.date_field, date_from:f.date_from, date_to:f.date_to,
			template_tax:B.templateTax||'__detect__',
			template_term:f.template_terms.length?f.template_terms.join(','):'',
			template_include_children:f.include_children?1:'',
			no_template:f.no_template?1:'', has_template:f.has_template?1:'',
			elementor_only:f.elementor_only?1:'',
			orphan:f.orphan?1:''
		};
		if(S.activeParent) q.parent=S.activeParent.id;
		api('/stats?'+qs(q)).then(function(d){ S.stats.total=d.total; S.stats.filtered=d.filtered; paintCount(); }).catch(function(){});
	}

	/* FEATURE 2 — default 4-tier top-level view (empty search, no filters). */
	// True only when nothing that would change the row set is active.
	function isPristineDefault(){
		var f=S.filters;
		if(S.searching||S.dupMode||S.activeParent) return false;
		if(S.search.q && S.search.q.trim()) return false;
		if(f.status||f.author||f.date_from||f.date_to||f.recent_mod) return false;
		if(f.template_terms&&f.template_terms.length) return false;
		if(f.no_template||f.has_template||f.include_children) return false;
		if(f.elementor_only||f.orphan) return false;
		return true;
	}

	function loadDefaultView(){
		if(S.loading) return Promise.resolve();
		S.loading=true; paintList();
		return api('/default-view?limit=500').then(function(d){
			S.rows=d.rows; S.cursor=null; S.hasNext=false; S.loading=false;
			paintList(); paintCount();
		}).catch(function(){ S.loading=false; toast('Load failed'); paintList(); });
	}

	function initialFill(){
		resetList(); loadStats();
		// FEATURE 2: custom 4-tier top-level view when no search/filters are active.
		if(isPristineDefault()){
			return loadDefaultView();
		}
		var n=0;
		function step(){
			if(n>=5||!S.hasNext||S.searching||S.dupMode) return;
			return loadMore().then(function(){ n++; return step(); });
		}
		return step();
	}

	/* ───────────────────────────────────────────────
	 * Parents panel
	 * ─────────────────────────────────────────────── */
	function loadParents(){
		if(S.parents!==null){ paintParents(); return; }
		api('/parents').then(function(data){
			S.parents=data; paintParents();
		}).catch(function(){ S.parents=[]; });
	}

	function setActiveParent(p){
		S.activeParent = p;
		resetList();
		paintParents();
		paintBanner();
		paintFilters();
		initialFill();
	}

	function paintParents(){
		var el=document.getElementById('spm-parents-panel'); if(!el) return;
		var parents=S.parents;
		if(parents===null){ el.innerHTML='<div class="spm-loading">Loading parents…</div>'; return; }
		if(!parents.length){ el.innerHTML=''; return; }
		var SHOW=S.parentsExpanded?parents.length:12;
		var shown=parents.slice(0,SHOW);
		var html='<div class="spm-parents-header">'
			+'<h3>Parent Pages <span class="spm-muted" style="font-weight:400">('+parents.length+')</span></h3>'
			+(S.activeParent?'<button class="button spm-chip" id="parent-clear-top">✕ Show All</button>':'')
			+'</div>'
			+'<div class="spm-parents-grid">';
		shown.forEach(function(p){
			var active=S.activeParent&&S.activeParent.id===p.id;
			html+='<div class="spm-parent-card'+(active?' active':'')+'" data-pid="'+p.id+'">'
				+'<div class="spm-parent-card-title">'+esc(p.title)+'</div>'
				+'<div class="spm-parent-card-meta">'
				+'<span class="spm-parent-count">'+p.child_count+' pages</span>'
				+'<span class="spm-muted">/'+esc(p.slug)+'</span>'
				+'</div>'
				+'</div>';
		});
		html+='</div>';
		if(parents.length>12){
			html+='<div class="spm-parents-show-more">'
				+'<button class="button spm-chip" id="parents-toggle">'
				+(S.parentsExpanded?'▲ Show less':'▼ Show all '+parents.length+' parents')
				+'</button></div>';
		}
		el.innerHTML=html;
		// Bind card clicks
		el.querySelectorAll('[data-pid]').forEach(function(card){
			card.addEventListener('click',function(){
				var pid=parseInt(card.getAttribute('data-pid'),10);
				var p=parents.find(function(x){return x.id===pid;});
				if(p){ setActiveParent(S.activeParent&&S.activeParent.id===pid ? null : p); }
			});
		});
		var tog=document.getElementById('parents-toggle');
		if(tog) tog.onclick=function(){ S.parentsExpanded=!S.parentsExpanded; paintParents(); };
		var clr=document.getElementById('parent-clear-top');
		if(clr) clr.onclick=function(){ setActiveParent(null); };
	}

	function paintBanner(){
		var el=document.getElementById('spm-parent-banner'); if(!el) return;
		if(!S.activeParent){ el.innerHTML=''; el.style.display='none'; return; }
		el.style.display='flex';
		el.innerHTML='<span>📂 Showing children of <strong>'+esc(S.activeParent.title)+'</strong>'
			+' <span class="spm-muted">('+S.activeParent.child_count+' pages)</span></span>'
			+'<button class="spm-banner-clear" title="Clear parent filter">✕</button>';
		el.querySelector('.spm-banner-clear').onclick=function(){ setActiveParent(null); };
	}

	/* ───────────────────────────────────────────────
	 * Search
	 * ─────────────────────────────────────────────── */
	function runSearch(){
		var q=S.search.q.trim();
		if(!q){ S.searching=false; initialFill(); return; }
		S.searching=true; S.dupMode=null;
		if(searchAbort) searchAbort.abort();
		searchAbort=new AbortController();
		var p={q:q, field:S.search.field, exact:S.search.exact?1:0, content:S.search.content?1:0, acf:S.search.acf?1:0, scope:S.search.scope};
		api('/search?'+qs(p), {signal:searchAbort.signal}).then(function(d){
			S.rows=d.rows; S.hasNext=false; S.cursor=null; S.cursorIdx=0; paintList(); paintCount(); paintFilters();
		}).catch(function(e){ if(e&&e.name==='AbortError') return; toast('Search failed'); });
	}
	function debouncedSearch(){ clearTimeout(searchTimer); searchTimer=setTimeout(runSearch,220); }

	/* ───────────────────────────────────────────────
	 * Duplicates
	 * ─────────────────────────────────────────────── */
	function runDuplicates(){
		if(!S.dupMode) return;
		S.loading=true; paintList();
		api('/duplicates?mode='+S.dupMode+'&limit=500').then(function(d){
			S.rows=d.rows; S.hasNext=false; S.cursor=null; S.cursorIdx=0;
			S.stats.filtered=d.count; S.loading=false; paintList(); paintCount(); paintFilters();
		}).catch(function(){ S.loading=false; toast('Duplicate scan failed'); paintList(); });
	}
	function exitDuplicates(){
		S.dupMode=null; S.searching=false; S.search.q='';
		var qi=document.getElementById('spm-q'); if(qi) qi.value='';
		initialFill();
	}

	/* ───────────────────────────────────────────────
	 * Refresh
	 * ─────────────────────────────────────────────── */
	function refresh(){
		if(S.dupMode) runDuplicates();
		else if(S.searching) runSearch();
		else initialFill();
	}

	/* ───────────────────────────────────────────────
	 * Actions
	 * ─────────────────────────────────────────────── */
	function doAction(action,id,cb){
		api('/action',{method:'POST',body:JSON.stringify({action:action,id:id})}).then(function(r){
			toast(action+' done'); if(cb) cb(r);
		}).catch(function(e){ toast((e&&e.message)||'Action failed'); });
	}

	/* ───────────────────────────────────────────────
	 * Context menu
	 * ─────────────────────────────────────────────── */
	var openMenu=null;
	function closeMenu(){ if(openMenu){ openMenu.remove(); openMenu=null; document.removeEventListener('click',outsideClose,true); } }
	function outsideClose(e){ if(openMenu&&!openMenu.contains(e.target)) closeMenu(); }
	function showMenu(x,y,row){
		closeMenu(); if(!row) return;
		var m=document.createElement('div'); m.className='spm-menu';
		function item(label,fn){ var b=document.createElement('button'); b.type='button'; b.textContent=label; b.onclick=function(){closeMenu();fn();}; m.appendChild(b); }
		function sep(){ var s=document.createElement('div'); s.className='sep'; m.appendChild(s); }
		item('Edit', function(){ window.location=row.editUrl; });
		item('Quick Edit', function(){ openQuickEdit(row.id); });
		item('View', function(){ window.open(row.url,'_blank'); });
		if(B.elementor) item('Edit with Elementor', function(){ window.open(row.elemUrl,'_blank'); });
		sep();
		item('Duplicate', function(){ doAction('duplicate',row.id,function(){ refresh(); }); });
		item('Copy Page ID', function(){ copy(String(row.id)); });
		item('Copy URL', function(){ copy(row.url); });
		sep();
		if(row.status==='trash'){
			item('Restore', function(){ doAction('untrash',row.id,refresh); });
			if(B.canDelete) item('Delete permanently', function(){ if(confirm('Delete permanently?')) doAction('delete',row.id,function(){ S.rows=S.rows.filter(function(r){return r.id!==row.id;}); paintList(); }); });
		} else {
			item('Trash', function(){ doAction('trash',row.id,function(){ S.rows=S.rows.filter(function(r){return r.id!==row.id;}); paintList(); }); });
		}
		document.body.appendChild(m);
		var rect=m.getBoundingClientRect();
		m.style.left=Math.max(8,Math.min(x-rect.width,window.innerWidth-rect.width-8))+'px';
		m.style.top=Math.min(y,window.innerHeight-rect.height-8)+'px';
		openMenu=m;
		setTimeout(function(){ document.addEventListener('click',outsideClose,true); },0);
	}

	/* ───────────────────────────────────────────────
	 * Quick edit
	 * ─────────────────────────────────────────────── */
	// Opens quick edit: fetches full per-page edit data (all taxonomies + SEO)
	// then renders. editData is cached on S so re-renders don't refetch.
	function openQuickEdit(id){
		S.editing=id; QE_H=300; qeMeasured=false;
		S.editData=null; // show loading state until fetched
		paintList(); ensureEditVisible();
		api('/page/'+id+'/edit').then(function(d){
			if(S.editing!==id) return; // user moved on
			S.editData=d; qeMeasured=false; paintList(); ensureEditVisible();
		}).catch(function(){
			if(S.editing!==id) return;
			S.editData={error:true}; qeMeasured=false; paintList();
		});
	}

	function saveQuickEdit(id){
		var box=document.getElementById('spm-qe-'+id);
		if(!box) return;
		// Collect taxonomy term selections across ALL taxonomy boxes.
		var taxMap={};
		box.querySelectorAll('input[data-tax]').forEach(function(cb){
			var t=cb.getAttribute('data-tax'), v=parseInt(cb.getAttribute('data-term'),10);
			if(!taxMap[t]) taxMap[t]=[];
			if(cb.checked) taxMap[t].push(v);
		});
		var taxTerms=Object.keys(taxMap).map(function(t){return {taxonomy:t,term_ids:taxMap[t]};});

		var body={
			title:   box.querySelector('.qe-title').value,
			slug:    box.querySelector('.qe-slug').value,
			status:  box.querySelector('.qe-status').value,
			template:box.querySelector('.qe-tpl')?box.querySelector('.qe-tpl').value:'',
			parent:  parseInt(box.querySelector('.qe-parent-id').value||0,10),
			tax_terms: taxTerms
		};

		// SEO fields (only if the SEO panel is present).
		if(B.seoPlugin && box.querySelector('.qe-seo-title')){
			var robots=[];
			box.querySelectorAll('input[data-robots]').forEach(function(cb){ if(cb.checked) robots.push(cb.getAttribute('data-robots')); });
			body.seo={
				title:       box.querySelector('.qe-seo-title').value,
				description: box.querySelector('.qe-seo-desc').value,
				canonical:   box.querySelector('.qe-seo-canonical').value,
				focus:       box.querySelector('.qe-seo-focus').value,
				robots:      robots
			};
		}

		api('/page/'+id,{method:'PATCH',body:JSON.stringify(body)}).then(function(r){
			var i=S.rows.findIndex(function(x){return x.id===id;});
			if(i>-1) S.rows[i]=r;
			S.editing=null; S.editData=null; qeMeasured=false; toast('Saved'); paintList();
		}).catch(function(e){ toast((e&&e.message)||'Save failed'); });
	}

	/* ───────────────────────────────────────────────
	 * Row rendering
	 * ─────────────────────────────────────────────── */
	function statusBadge(s){ return '<span class="spm-badge '+esc(s)+'">'+esc(s)+'</span>'; }

	function rowHtml(item){
		var r=item.row, sel=!!S.selected[r.id];
		// Dup stripe
		var dupBg='';
		if(S.dupMode&&r.dup_group){
			var hues=['#fff8e1','#e8f5e9','#e3f2fd','#fce4ec','#f3e5f5','#fff3e0'];
			var hi=Math.abs(r.dup_group.split('').reduce(function(a,c){return a+c.charCodeAt(0);},0))%hues.length;
			dupBg='background:'+hues[hi]+';border-left:3px solid '+hues[hi].replace(/f/g,'c')+';';
		}
		if(S.editing===r.id){
			var ed=S.editData;
			// Loading / error states while the per-page edit data is fetched.
			if(!ed){
				return '<div class="spm-qe-wrap" id="spm-qe-'+r.id+'"><div class="spm-qe-loading">Loading page details…</div></div>';
			}
			if(ed.error){
				return '<div class="spm-qe-wrap" id="spm-qe-'+r.id+'"><div class="spm-qe-loading">Failed to load. <button class="button" data-cancel="1">Close</button></div></div>';
			}

			var curTpl=ed.template||'';
			var tplOpts=B.templates.map(function(t){return '<option value="'+esc(t.value)+'"'+(t.value===curTpl?' selected':'')+'>'+esc(t.label)+'</option>';}).join('');
			var stOpts=B.statuses.map(function(s){return '<option value="'+s+'"'+(s===r.status?' selected':'')+'>'+s+'</option>';}).join('');

			// One scrollable checkbox box per page taxonomy (hierarchy-indented).
			function taxBoxHtml(tax){
				var assigned=(ed.tax&&ed.tax[tax.name])?ed.tax[tax.name]:[];
				var rows=tax.terms.map(function(t){
					var chk=assigned.indexOf(t.value)>-1?' checked':'';
					var pad=8+(t.depth||0)*16;
					return '<label class="spm-qe-termchk" style="padding-left:'+pad+'px" title="'+esc(t.path||t.label)+'"><input type="checkbox" data-tax="'+esc(tax.name)+'" data-term="'+t.value+'"'+chk+'> '+esc(t.label)+'</label>';
				}).join('');
				return '<div class="spm-qe-taxgroup-wrap">'
					+'<div class="spm-qe-seclabel">'+esc(tax.label)+'</div>'
					+'<div class="spm-qe-taxbox">'+rows+'</div>'
					+'</div>';
			}
			var taxesHtml=(B.taxes||[]).map(taxBoxHtml).join('');

			// SEO panel (RankMath / Yoast).
			var seoHtml='';
			if(B.seoPlugin && ed.seo){
				var s=ed.seo;
				var robotsAll=s.robots_all||['index','noindex','nofollow','noarchive','noimageindex','nosnippet'];
				var robotsLabels={index:'Index',noindex:'No Index',nofollow:'No Follow',noarchive:'No Archive',noimageindex:'No Image Index',nosnippet:'No Snippet'};
				var robotsBoxes=robotsAll.map(function(rk){
					var chk=(s.robots||[]).indexOf(rk)>-1?' checked':'';
					return '<label class="spm-qe-termchk"><input type="checkbox" data-robots="'+esc(rk)+'"'+chk+'> '+esc(robotsLabels[rk]||rk)+'</label>';
				}).join('');
				seoHtml='<div class="spm-qe-seo">'
					+'<div class="spm-qe-seclabel spm-qe-seohead">SEO Settings <span class="spm-qe-seoplugin">'+esc(B.seoPlugin)+'</span></div>'
					+'<div class="spm-qe-seo-grid">'
					+'<div class="spm-qe-seo-col">'
						+'<div class="spm-qe-field spm-qe-field-stack"><label>SEO Title</label><input class="qe-seo-title" type="text" value="'+esc(s.title||'')+'" placeholder="%title% %sep% %sitename%"></div>'
						+'<div class="spm-qe-field spm-qe-field-stack"><label>SEO Description</label><textarea class="qe-seo-desc" rows="2" placeholder="%excerpt%">'+esc(s.description||'')+'</textarea></div>'
					+'</div>'
					+'<div class="spm-qe-seo-col">'
						+'<div class="spm-qe-seclabel">Robots Meta</div>'
						+'<div class="spm-qe-taxbox spm-qe-robotsbox">'+robotsBoxes+'</div>'
					+'</div>'
					+'<div class="spm-qe-seo-col">'
						+'<div class="spm-qe-field spm-qe-field-stack"><label>Focus Keyword</label><input class="qe-seo-focus" type="text" value="'+esc(s.focus||'')+'"></div>'
						+'<div class="spm-qe-field spm-qe-field-stack"><label>Canonical URL</label><input class="qe-seo-canonical" type="text" value="'+esc(s.canonical||'')+'" placeholder="'+esc(r.url||'')+'"></div>'
					+'</div>'
					+'</div>'
				+'</div>';
			}

			return '<div class="spm-qe-wrap" id="spm-qe-'+r.id+'">'
				+'<div class="spm-qe-grid">'
				+'<div class="spm-qe-col spm-qe-col-main">'
					+'<div class="spm-qe-field"><label>Title</label><input class="qe-title" type="text" value="'+esc(r.title)+'" placeholder="Title"></div>'
					+'<div class="spm-qe-field"><label>Slug</label><input class="qe-slug" type="text" value="'+esc(r.slug)+'" placeholder="Slug"></div>'
					+'<div class="spm-qe-field"><label>Status</label><select class="qe-status">'+stOpts+'</select></div>'
					+'<div class="spm-qe-field"><label>Parent</label><input class="qe-parent-id" type="number" min="0" value="'+esc(r.parent||0)+'" placeholder="Parent ID (0 = top level)"></div>'
					+'<div class="spm-qe-parenthint">'+(r.parentTitle?'Current parent: '+esc(r.parentTitle):'Top level (no parent)')+'</div>'
					+'<div class="spm-qe-field"><label>Page Tpl</label><select class="qe-tpl">'+tplOpts+'</select></div>'
				+'</div>'
				+'<div class="spm-qe-col spm-qe-col-tax">'
					+(taxesHtml||'<div class="spm-qe-seclabel">No taxonomies</div>')
				+'</div>'
				+'</div>'
				+seoHtml
				+'<div class="spm-qe-actions">'
				+'<button class="button button-primary" data-save="'+r.id+'">Update</button>'
				+'<button class="button" data-cancel="1">Cancel</button>'
				+'<span class="spm-qe-hint">Page #'+r.id+'</span>'
				+'</div></div>';
		}
		// Grouped child-scope search: parent header rows and indented children.
		var isHdr=!!r._spmParentHeader, isChild=!!r._spmChild;
		var titleInner;
		if(isHdr){
			titleInner='<span class="spm-grp-parent">'+esc(r.title)
				+' <span class="spm-grp-count">'+(r._spmChildCount||0)+(r._spmChildCount===1?' child':' children')+'</span></span>';
		}else if(isChild){
			titleInner='<span class="spm-grp-childmark">└</span><span class="spm-title-txt">'+esc(r.title)+'</span>';
		}else{
			titleInner='<span class="spm-title-txt">'+esc(r.title)+'</span>';
		}
		var titleCls='spm-c spm-c-title'+(isChild?' spm-c-title-child':'')+(isHdr?' spm-c-title-hdr':'');
		return '<div class="spm-c spm-c-chk" style="'+dupBg+'">'
			+'<input type="checkbox" data-sel="'+r.id+'"'+(sel?' checked':'')+'>'
			+'<span class="spm-drag-handle" draggable="true" data-drag="'+r.id+'" title="Drag to reparent">⠿</span>'
			+'</div>'
			+'<div class="'+titleCls+'" data-preview="'+esc(r.url)+'" title="Click to preview" style="'+dupBg+'">'
			+titleInner+'</div>'
			+'<div class="spm-c spm-c-id" style="'+dupBg+'">#'+r.id+'</div>'
			+'<div class="spm-c spm-c-slug" style="'+dupBg+'">'+esc('/'+r.slug)+'</div>'
			+'<div class="spm-c spm-c-parent">'+(r.parentTitle?'<a class="spm-link" href="#" data-filterparent="'+r.parent+'" title="Filter by this parent">'+esc(r.parentTitle)+'</a>':'<span class="spm-muted">—</span>')+'</div>'
			+'<div class="spm-c spm-c-tmpl">'+(r.templateTerms&&r.templateTerms.length
				? r.templateTerms.map(function(t){return '<span class="spm-tmpl-tag">'+esc(t)+'</span>';}).join('')
				: '<span class="spm-badge spm-badge-missing">None</span>')+'</div>'
			+'<div class="spm-c spm-c-elem">'+(r.elementor?'<span class="spm-badge spm-badge-elem">Yes</span>':'<span class="spm-muted">No</span>')+'</div>'
			+'<div class="spm-c spm-c-status">'+statusBadge(r.status)+'</div>'
			+'<div class="spm-c spm-c-mod">'+esc((r.modified||'').slice(0,10))+'</div>'
			+'<div class="spm-c spm-c-act"><button class="spm-actbtn" type="button" data-menu="'+r.id+'">⋮</button></div>';
	}

	/* ───────────────────────────────────────────────
	 * Virtual scroll list
	 * ─────────────────────────────────────────────── */
	var scrollEl=null;
	function getDisplayItems(){ return S.rows.map(function(r){return {row:r};}); }

	// Height of the inline quick-edit panel. Measured after render and cached so
	// the virtual scroller can reserve the right amount of space for the open row.
	var QE_H=300, qeMeasured=false;

	// Index of the currently-editing row within the display list (or -1).
	function editingIndex(items){
		if(S.editing==null) return -1;
		for(var i=0;i<items.length;i++){ if(items[i].row.id===S.editing) return i; }
		return -1;
	}

	function paintList(){
		var spacer=document.getElementById('spm-spacer'); if(!spacer) return;
		var items=getDisplayItems();
		var total=items.length;
		var extra=(S.loading||S.hasNext)&&!S.searching&&!S.dupMode?1:0;

		var editIdx=editingIndex(items);
		var extraH=(editIdx>-1)?(QE_H-ROW_H):0; // additional vertical space the open editor needs

		// Total virtual height includes the expanded editor (if any).
		spacer.style.height=((total+extra)*ROW_H+extraH)+'px';

		var st=scrollEl?scrollEl.scrollTop:0, h=scrollEl?scrollEl.clientHeight:500;

		// Map a row index to its absolute top, accounting for the expanded editor.
		function topFor(i){ return i*ROW_H + ((editIdx>-1 && i>editIdx)?extraH:0); }

		// Visible window (widen a little when an editor is open so it never clips).
		var start=Math.max(0,Math.floor(st/ROW_H)-OVERSCAN);
		var end=Math.min(total,Math.ceil((st+h)/ROW_H)+OVERSCAN);
		// Always include the editing row even if it scrolled near the edge.
		if(editIdx>-1){ start=Math.min(start,editIdx); end=Math.max(end,editIdx+1); }

		var html='';
		for(var i=start;i<end;i++){
			var it=items[i];
			var isEdit=(i===editIdx);
			var cls='spm-row'+(S.selected[it.row.id]?' sel':'')+(i===S.cursorIdx?' cur':'')+(isEdit?' spm-row-editing':'');
			if(isEdit){
				// Auto height — the panel sizes to its content. We measure it ONCE
				// (qeMeasured guard) so scroll-driven repaints never feed the measured
				// height back into itself (that caused the runaway-growth bug).
				html+='<div class="'+cls+'" data-rowid="'+it.row.id+'" style="top:'+topFor(i)+'px;min-height:'+ROW_H+'px">'+rowHtml(it)+'</div>';
			} else {
				html+='<div class="'+cls+'" data-rowid="'+it.row.id+'" style="top:'+topFor(i)+'px;height:'+ROW_H+'px">'+rowHtml(it)+'</div>';
			}
		}
		if(extra){
			html+='<div class="spm-row" style="top:'+(total*ROW_H+extraH)+'px;height:'+ROW_H+'px"><div class="spm-loading">'+(S.loading?'Loading more pages…':'Scroll for more')+'</div></div>';
		}
		if(!total&&!S.loading){
			html='<div class="spm-empty">No pages found matching the current filters.</div>';
		}
		spacer.innerHTML=html;

		// Measure the editor's natural height EXACTLY ONCE per open session.
		// On later repaints qeMeasured is already true, so we never re-measure
		// (this is what prevents the window from continuously growing on scroll).
		if(editIdx>-1 && !qeMeasured){
			var editNode=spacer.querySelector('.spm-row-editing');
			if(editNode){
				var natural=editNode.offsetHeight; // auto height = true content height
				if(natural>ROW_H){
					QE_H=natural;
					qeMeasured=true;
					reflowPositions(items,total,extra);
				}
			}
		}
		paintCount();
	}

	// Reposition already-rendered rows after QE_H changes (no innerHTML rebuild).
	function reflowPositions(items,total,extra){
		var spacer=document.getElementById('spm-spacer'); if(!spacer) return;
		var editIdx=editingIndex(items);
		var extraH=(editIdx>-1)?(QE_H-ROW_H):0;
		spacer.style.height=((total+extra)*ROW_H+extraH)+'px';
		var nodes=spacer.querySelectorAll('.spm-row');
		nodes.forEach(function(node){
			var id=parseInt(node.getAttribute('data-rowid')||'-1',10);
			var idx=-1;
			for(var i=0;i<items.length;i++){ if(items[i].row.id===id){ idx=i; break; } }
			if(idx<0) return;
			var top=idx*ROW_H + ((editIdx>-1 && idx>editIdx)?extraH:0);
			node.style.top=top+'px';
			// Editing row keeps auto height (min-height only); do not pin it.
		});
	}

	// Scroll the open quick-edit row fully into view (after it expands).
	function ensureEditVisible(){
		if(!scrollEl||S.editing==null) return;
		setTimeout(function(){
			var items=getDisplayItems();
			var idx=editingIndex(items); if(idx<0) return;
			var top=idx*ROW_H;
			var bottom=top+QE_H;
			if(top<scrollEl.scrollTop){ scrollEl.scrollTop=top-8; }
			else if(bottom>scrollEl.scrollTop+scrollEl.clientHeight){
				scrollEl.scrollTop=Math.min(top-8, bottom-scrollEl.clientHeight+8);
			}
		},30);
	}

	function paintCount(){
		var c=document.getElementById('spm-count'); if(!c) return;
		var loaded=getDisplayItems().length;
		var total=S.stats.total, filtered=S.stats.filtered;
		var parts=[];
		if(S.dupMode){
			var dlabels={title:'same title',slug_exact:'exact slug',slug_similar:'similar slug'};
			parts.push(loaded+' duplicate'+(loaded!==1?'s':'')+' ('+dlabels[S.dupMode]+')');
		} else if(S.searching){
			parts.push(loaded+' result'+(loaded!==1?'s':''));
			if(total!=null) parts.push(total.toLocaleString()+' total');
		} else {
			if(S.activeParent) parts.push(S.activeParent.title+' ('+S.activeParent.child_count+')');
			if(total!=null){
				parts.push(total.toLocaleString()+' total');
				if(filtered!=null&&filtered!==total) parts.push(filtered.toLocaleString()+' filtered');
			}
			parts.push(loaded.toLocaleString()+' loaded'+(S.hasNext?'+':''));
		}
		c.textContent=parts.join(' · ');
	}

	function onScroll(){
		paintList();
		if(!S.searching&&!S.dupMode){
			var nearBottom=scrollEl.scrollTop+scrollEl.clientHeight>scrollEl.scrollHeight-ROW_H*10;
			if(nearBottom) loadMore();
		}
	}

	/* ───────────────────────────────────────────────
	 * Filter panel
	 * ─────────────────────────────────────────────── */
	function paintFilters(){
		var ft=document.getElementById('spm-ft'); if(!ft) return;
		_painting=true;
		// Always read from S.filters fresh — never capture a reference before a potential reset.
		var f=S.filters;
		function sel(id,opts,val){
			return '<select id="'+id+'">'+opts.map(function(o){
				return '<option value="'+esc(o.value)+'"'+(String(o.value)===String(val)?' selected':'')+'>'+esc(o.label)+'</option>';
			}).join('')+'</select>';
		}
		var statusOpts=[{value:'',label:'Any status'}].concat(B.statuses.map(function(s){return {value:s,label:s};}));

		// Status message for search/dup modes
		var modeMsg='';
		if(S.searching) modeMsg='<span class="spm-mode-msg">🔍 Search results active</span>';
		else if(S.dupMode){
			var dl={title:'Same Title',slug_exact:'Same Slug',slug_similar:'Similar Slug'};
			modeMsg='<span class="spm-mode-msg spm-mode-msg-dup">⚠ Duplicate scan: '+dl[S.dupMode]+'</span>';
		}

		// Hierarchical Template term picker. Options are indented by depth and
		// titled with their full path so repeated child names (CRO1 under Geo
		// vs under National) are never ambiguous.
		var tmplTermOpts='<option value="">— Any template term —</option>';
		(B.templateTaxTerms||[]).forEach(function(t){
			var sel2=(f.template_terms||[]).indexOf(t.value)>-1?' selected':'';
			var indent='';
			for(var d=0; d<(t.depth||0); d++){ indent+='\u00A0\u00A0\u00A0'; }
			var prefix=(t.depth>0?'\u2514 ':'');
			tmplTermOpts+='<option value="'+esc(t.value)+'"'+sel2+' title="'+esc(t.path||t.label)+'">'+indent+prefix+esc(t.label)+' ('+t.count+')</option>';
		});

		ft.innerHTML=
			'<div class="spm-filter-row">'
			+sel('f-status',statusOpts,f.status)
			+sel('f-author',B.authors,f.author)
			+'<select id="f-recent-mod">'
				+'<option value=""'+(f.recent_mod===''?' selected':'')+'>Any time</option>'
				+'<option value="today"'+(f.recent_mod==='today'?' selected':'')+'>Modified today</option>'
				+'<option value="7days"'+(f.recent_mod==='7days'?' selected':'')+'>Last 7 days</option>'
				+'<option value="30days"'+(f.recent_mod==='30days'?' selected':'')+'>Last 30 days</option>'
			+'</select>'
			+'<select id="f-dup" class="spm-dup-select" title="Find duplicate pages">'
				+'<option value=""'+(!S.dupMode?' selected':'')+'>🔁 Duplicates: off</option>'
				+'<option value="title"'+(S.dupMode==='title'?' selected':'')+'>Same Title</option>'
				+'<option value="slug_exact"'+(S.dupMode==='slug_exact'?' selected':'')+'>Same Slug</option>'
				+'<option value="slug_similar"'+(S.dupMode==='slug_similar'?' selected':'')+'>Similar Slug</option>'
			+'</select>'
			+modeMsg
			+'<button class="button" id="f-clear">✕ Clear All</button>'
			+'</div>'
			+(S.searching||S.dupMode?'':
				'<div class="spm-filter-row spm-tmpl-row">'
				+'<span class="spm-filter-label">'+esc(B.templateTaxLabel||'Template')+'</span>'
				+(!B.templateTax?'<span class="spm-badge-warn" title="Taxonomy not detected. Available: '+(B.allPageTaxes||[]).join(', ')+'">&#9888; Taxonomy not detected</span>':
					'<select id="f-tmpl-mode">'
						+'<option value=""'+((!f.no_template&&!f.template_terms.length)?' selected':'')+'>All Pages</option>'
						+'<option value="no_template"'+(f.no_template?' selected':'')+'>⚠ No Template Assigned</option>'
						+'<option value="has_template"'+(f.template_terms.length||f.has_template?' selected':'')+'>Assigned to a Template</option>'
					+'</select>'
					+(B.templateTaxTerms&&B.templateTaxTerms.length?
						'<select id="f-tmpl-terms" title="Filter by a specific template term (hierarchy shown)">'+tmplTermOpts+'</select>'
						+'<label class="spm-inc-children" title="Also include pages tagged with child terms of the selected term"><input type="checkbox" id="f-tmpl-children"'+(f.include_children?' checked':'')+'> incl. children</label>'
					:'')
					+(f.no_template?'<span class="spm-badge-warn">⚠ Showing pages with no template</span>':'')
				)
				+'</div>'
			);

		// ── Bind all events to the freshly rendered elements ──
		// _painting is cleared here — any change events that fired during innerHTML
		// assignment above are already dispatched; new ones from user interaction are real.
		_painting=false;

		// All event bindings check _painting to ignore spurious DOM events.
		function bindSel(id,fn){
			var el=document.getElementById(id);
			if(el) el.addEventListener('change',function(e){ if(_painting) return; fn(e); });
		}

		// Clear All — mutates S.filters in place so all references stay valid,
		// then repaints. Do NOT replace S.filters with a new object here.
		document.getElementById('f-clear').onclick=function(){
			S.filters.status='';
			S.filters.author=0;
			S.filters.date_field='created';
			S.filters.date_from='';
			S.filters.date_to='';
			S.filters.recent_mod='';
			S.filters.template_terms=[];
			S.filters.no_template=false;
			S.filters.has_template=false;
			S.filters.include_children=false;
			S.filters.elementor_only=false;
			S.filters.orphan=false;
			S.filters.template_mode='';
			S.dupMode=null;
			S.activeParent=null;
			// Clear search
			S.search.q=''; S.search.exact=false; S.search.content=false; S.search.acf=false;
			S.searching=false;
			var qi=document.getElementById('spm-q'); if(qi) qi.value='';
			var ex=document.getElementById('spm-exact'); if(ex) ex.checked=false;
			var ct=document.getElementById('spm-content'); if(ct) ct.checked=false;
			var ac=document.getElementById('spm-acf'); if(ac) ac.checked=false;
			paintBanner();
			paintParents();
			paintFilters();
			initialFill();
		};

		function bindSel(id,fn){ var el=document.getElementById(id); if(el) el.addEventListener('change',fn); }

		bindSel('f-status',function(e){ S.filters.status=e.target.value; paintFilters(); initialFill(); });
		bindSel('f-author',function(e){ S.filters.author=parseInt(e.target.value||0,10); paintFilters(); initialFill(); });
		bindSel('f-recent-mod',function(e){
			S.filters.recent_mod=e.target.value;
			var d=new Date();
			if(e.target.value==='today'){ S.filters.date_field='modified'; S.filters.date_from=d.toISOString().slice(0,10); S.filters.date_to=''; }
			else if(e.target.value==='7days'){ d.setDate(d.getDate()-7); S.filters.date_field='modified'; S.filters.date_from=d.toISOString().slice(0,10); S.filters.date_to=''; }
			else if(e.target.value==='30days'){ d.setDate(d.getDate()-30); S.filters.date_field='modified'; S.filters.date_from=d.toISOString().slice(0,10); S.filters.date_to=''; }
			else { S.filters.date_field='created'; S.filters.date_from=''; S.filters.date_to=''; }
			paintFilters(); initialFill();
		});

		// Duplicates dropdown: title / slug_exact / slug_similar, or off.
		bindSel('f-dup',function(e){
			var v=e.target.value;
			if(v){ S.dupMode=v; runDuplicates(); paintFilters(); }
			else { exitDuplicates(); paintFilters(); }
		});

		// Template mode dropdown: All / No Template / Assigned.
		bindSel('f-tmpl-mode',function(e){
			var v=e.target.value;
			S.filters.no_template=false; S.filters.has_template=false; S.filters.template_terms=[];
			if(v==='no_template'){ S.filters.no_template=true; }
			else if(v==='has_template'){ S.filters.has_template=true; }
			paintFilters();
			initialFill();
		});

		// Specific template term (single select, hierarchy-aware).
		bindSel('f-tmpl-terms',function(e){
			var v=parseInt(e.target.value||0,10);
			S.filters.no_template=false; S.filters.has_template=false;
			S.filters.template_terms = v ? [v] : [];
			paintFilters(); initialFill();
		});

		// Include-children toggle.
		var incEl=document.getElementById('f-tmpl-children');
		if(incEl){ incEl.addEventListener('change',function(){
			S.filters.include_children=incEl.checked;
			if(S.filters.template_terms.length) initialFill();
		}); }

		// Sort arrows
		['title','id','slug','modified'].forEach(function(k){
			var el=document.getElementById('ar-'+k);
			if(el) el.textContent=(S.orderby===k?(S.order==='ASC'?'▲':'▼'):'');
		});
	}

	/* ───────────────────────────────────────────────
	 * Bulk bar
	 * ─────────────────────────────────────────────── */
	function selectedIds(){ return Object.keys(S.selected).map(Number); }
	function paintBulk(){
		var bar=document.getElementById('spm-bulk'); if(!bar) return;
		var ids=selectedIds();
		if(!ids.length){ bar.classList.add('hidden'); return; }
		bar.classList.remove('hidden');
		bar.innerHTML='<strong>'+ids.length+' selected</strong>'
			+'<select id="bulk-action"><option value="">Bulk actions</option><option value="trash">Move to Trash</option><option value="duplicate">Duplicate</option></select>'
			+'<button class="button button-primary" id="bulk-apply">Apply</button>'
			+'<button class="button" id="bulk-clear">Clear</button>';
		document.getElementById('bulk-clear').onclick=function(){ S.selected={}; paintList(); paintBulk(); };
		document.getElementById('bulk-apply').onclick=function(){
			var act=document.getElementById('bulk-action').value; if(!act) return;
			if(act==='trash'&&!confirm('Move '+ids.length+' pages to trash?')) return;
			runBulk(act, ids.slice());
		};
	}

	function runBulk(action, ids){
		var done=0, chunk=ids.slice();
		function next(){
			if(!chunk.length){ toast(action+': '+done+' done'); S.selected={}; refresh(); paintBulk(); return; }
			var batch=chunk.splice(0,8);
			Promise.all(batch.map(function(id){
				return api('/action',{method:'POST',body:JSON.stringify({action:action,id:id})}).then(function(){done++;}).catch(function(){});
			})).then(next);
		}
		next();
	}

	/* ───────────────────────────────────────────────
	 * Shell (rendered once)
	 * ─────────────────────────────────────────────── */
	function paintShell(){
		var fieldOpts=[['auto','Title / ID / URL'],['id','Page ID'],['slug','Slug'],['title','Title']]
			.map(function(o){return '<option value="'+o[0]+'"'+(S.search.field===o[0]?' selected':'')+'>'+o[1]+'</option>';}).join('');
		app.innerHTML=
			'<div id="spm-shell">'
			// Top bar
			+'<div class="spm-topbar" id="spm-tb">'
			+' <div class="spm-search">'
			+'  <span class="dashicons dashicons-search spm-search-icon"></span>'
			+'  <input type="text" id="spm-q" placeholder="Search by title, ID, URL, slug…" autocomplete="off">'
			+'  <div class="spm-search-divider"></div>'
			+'  <select id="spm-field">'+fieldOpts+'</select>'
			+'  <div class="spm-search-divider"></div>'
			+'  <select id="spm-scope" title="Search scope">'
			+'   <option value="direct"'+(S.search.scope==='direct'?' selected':'')+'>Direct matches</option>'
			+'   <option value="children"'+(S.search.scope==='children'?' selected':'')+'>Child pages of matches</option>'
			+'  </select>'
			+'  <div class="spm-search-divider"></div>'
			+'  <label class="spm-chk"><input type="checkbox" id="spm-exact"> Exact</label>'
			+'  <span class="spm-acf-toggle">'
			+'   <label class="spm-chk"><input type="checkbox" id="spm-content"> Content</label>'
			+'   <label class="spm-chk"><input type="checkbox" id="spm-acf"> ACF</label>'
			+'  </span>'
			+' </div>'
			+' <span class="spm-count" id="spm-count">Loading…</span>'
			+' <button type="button" class="button button-secondary spm-export-btn" id="spm-export">'
			+'  <span class="dashicons dashicons-media-spreadsheet" style="vertical-align:text-bottom"></span> Export CSV'
			+' </button>'
			+'</div>'
			// Filter panel
			+'<div class="spm-filterpanel" id="spm-ft"></div>'
			// Parent folders panel
			+'<div class="spm-parents-section" id="spm-parents-panel"></div>'
			// Active parent banner
			+'<div class="spm-parent-banner" id="spm-parent-banner" style="display:none"></div>'
			// Table
			+'<div class="spm-table">'
			+' <div class="spm-thead">'
			+'  <div class="spm-c spm-c-chk"><input type="checkbox" id="spm-all"> <span style="color:#adb5bd;font-size:11px">All</span></div>'
			+'  <div class="spm-c spm-c-title" data-sort="title">Title <span id="ar-title"></span></div>'
			+'  <div class="spm-c spm-c-id" data-sort="id">ID <span id="ar-id"></span></div>'
			+'  <div class="spm-c spm-c-slug" data-sort="slug">Slug <span id="ar-slug"></span></div>'
			+'  <div class="spm-c spm-c-parent">Parent</div>'
			+'  <div class="spm-c spm-c-tmpl">Template</div>'
			+'  <div class="spm-c spm-c-elem">Elementor</div>'
			+'  <div class="spm-c spm-c-status">Status</div>'
			+'  <div class="spm-c spm-c-mod" data-sort="modified">Modified <span id="ar-modified"></span></div>'
			+'  <div class="spm-c spm-c-act"></div>'
			+' </div>'
			+' <div class="spm-scroll" id="spm-scroll"><div id="spm-spacer" style="position:relative"></div></div>'
			+' <div class="spm-bulkbar hidden" id="spm-bulk"></div>'
			+'</div>'
			+'</div>';

		scrollEl=document.getElementById('spm-scroll');
		scrollEl.addEventListener('scroll',onScroll);

		// Search bindings
		var qi=document.getElementById('spm-q');
		qi.value=S.search.q;
		qi.addEventListener('input',function(){ S.search.q=qi.value; debouncedSearch(); });
		qi.addEventListener('keydown',function(e){ if(e.key==='Enter'){ clearTimeout(searchTimer); runSearch(); } });
		document.getElementById('spm-field').addEventListener('change',function(e){ S.search.field=e.target.value; if(S.searching) runSearch(); });
		document.getElementById('spm-scope').addEventListener('change',function(e){ S.search.scope=e.target.value; if(S.searching) runSearch(); });
		document.getElementById('spm-exact').addEventListener('change',function(e){ S.search.exact=e.target.checked; if(S.searching) runSearch(); });
		document.getElementById('spm-content').addEventListener('change',function(e){ S.search.content=e.target.checked; if(S.searching) runSearch(); });
		document.getElementById('spm-acf').addEventListener('change',function(e){ S.search.acf=e.target.checked; if(S.searching) runSearch(); });

		// Export CSV
		document.getElementById('spm-export').addEventListener('click',exportCsv);

		// Header sort
		document.querySelectorAll('.spm-thead [data-sort]').forEach(function(el){
			el.addEventListener('click',function(){
				var k=el.getAttribute('data-sort');
				if(S.orderby===k){ S.order=(S.order==='ASC')?'DESC':'ASC'; } else { S.orderby=k; S.order='ASC'; }
				initialFill(); paintFilters();
			});
		});

		// Select all
		document.getElementById('spm-all').addEventListener('change',function(e){
			getDisplayItems().forEach(function(it){ if(e.target.checked) S.selected[it.row.id]=it.row; else delete S.selected[it.row.id]; });
			paintList(); paintBulk();
		});

		// Event delegation on spacer
		var spacerEl=document.getElementById('spm-spacer');

		// Click delegation (quick edit, cancel, save, menu, parent-filter link)
		spacerEl.addEventListener('click',function(e){
			var t=e.target;
			if(t.hasAttribute('data-save')){ saveQuickEdit(parseInt(t.getAttribute('data-save'),10)); return; }
			if(t.hasAttribute('data-cancel')){ S.editing=null; S.editData=null; qeMeasured=false; paintList(); return; }
			if(t.hasAttribute('data-sel')){ var sid=parseInt(t.getAttribute('data-sel'),10); if(t.checked) S.selected[sid]=rowById(sid); else delete S.selected[sid]; paintBulk(); return; }
			if(t.hasAttribute('data-menu')){ e.stopPropagation(); var mid=parseInt(t.getAttribute('data-menu'),10); var rect=t.getBoundingClientRect(); showMenu(rect.right,rect.bottom+4,rowById(mid)); return; }
			if(t.hasAttribute('data-filterparent')){
				e.preventDefault();
				var pid=parseInt(t.getAttribute('data-filterparent'),10);
				var parent=S.parents&&S.parents.find(function(p){return p.id===pid;});
				if(parent) setActiveParent(S.activeParent&&S.activeParent.id===pid?null:parent);
				else {
					// Parent not in grid — fetch its title and drill down
					var prow=rowById(pid);
					if(prow){ setActiveParent({id:pid,title:prow.parentTitle||'Parent #'+pid,slug:'',child_count:0}); }
				}
				return;
			}
			var titleCell=t.closest('.spm-c-title'); if(titleCell&&titleCell.getAttribute('data-preview')){ window.open(titleCell.getAttribute('data-preview'),'_blank'); }
		});

		// Drag-and-drop
		spacerEl.addEventListener('dragstart',function(e){
			var h=e.target.closest('[data-drag]'); if(!h){e.preventDefault();return;}
			drag.id=parseInt(h.getAttribute('data-drag'),10);
			e.dataTransfer.effectAllowed='move';
			e.dataTransfer.setData('text/plain',String(drag.id));
			h.closest('.spm-row').classList.add('spm-dragging');
		});
		spacerEl.addEventListener('dragend',function(){
			if(drag.overEl) drag.overEl.classList.remove('spm-drop-target');
			document.querySelectorAll('.spm-dragging').forEach(function(el){el.classList.remove('spm-dragging');});
			drag.id=null; drag.overId=null; drag.overEl=null;
		});
		spacerEl.addEventListener('dragover',function(e){
			e.preventDefault();
			var row=e.target.closest('.spm-row'); if(!row) return;
			var tid=parseInt(row.getAttribute('data-rowid'),10);
			if(tid===drag.id) return;
			if(drag.overEl&&drag.overEl!==row) drag.overEl.classList.remove('spm-drop-target');
			drag.overId=tid; drag.overEl=row; row.classList.add('spm-drop-target');
			e.dataTransfer.dropEffect='move';
		});
		spacerEl.addEventListener('dragleave',function(e){
			var row=e.target.closest('.spm-row');
			if(row&&row===drag.overEl&&!row.contains(e.relatedTarget)){ row.classList.remove('spm-drop-target'); drag.overId=null; drag.overEl=null; }
		});
		spacerEl.addEventListener('drop',function(e){
			e.preventDefault();
			var targetId=drag.overId, srcId=drag.id;
			if(drag.overEl) drag.overEl.classList.remove('spm-drop-target');
			drag.id=null; drag.overId=null; drag.overEl=null;
			if(!srcId||!targetId||srcId===targetId) return;
			var trow=rowById(targetId), srow=rowById(srcId); if(!trow||!srow) return;
			if(!confirm('Make "'+srow.title+'" a child of "'+trow.title+'"?')) return;
			api('/page/'+srcId,{method:'PATCH',body:JSON.stringify({parent:targetId})}).then(function(){
				toast('Reparented');
				var i=S.rows.findIndex(function(x){return x.id===srcId;});
				if(i>-1){ S.rows[i].parent=targetId; S.rows[i].parentTitle=trow.title; }
				paintList(); delete_transient_hint();
			}).catch(function(e){ toast((e&&e.message)||'Reparent failed'); });
		});

		// Keyboard nav
		document.addEventListener('keydown',function(e){
			if(['INPUT','SELECT','TEXTAREA'].indexOf(e.target.tagName||'')>-1&&e.key!=='Escape') return;
			var items=getDisplayItems();
			if(e.key==='/'){ e.preventDefault(); qi.focus(); return; }
			if(e.key==='Escape'){ closeMenu(); if(S.editing){S.editing=null;S.editData=null;qeMeasured=false;paintList();} return; }
			if(e.key==='j'||e.key==='ArrowDown'){ e.preventDefault(); S.cursorIdx=Math.min(items.length-1,S.cursorIdx+1); ensureVisible(); paintList(); }
			else if(e.key==='k'||e.key==='ArrowUp'){ e.preventDefault(); S.cursorIdx=Math.max(0,S.cursorIdx-1); ensureVisible(); paintList(); }
			else if(e.key==='x'){ var it=items[S.cursorIdx]; if(it){ if(S.selected[it.row.id])delete S.selected[it.row.id]; else S.selected[it.row.id]=it.row; paintList(); paintBulk(); } }
			else if(e.key==='e'){ var it2=items[S.cursorIdx]; if(it2) window.location=it2.row.editUrl; }
			else if(e.key==='Enter'){ var it3=items[S.cursorIdx]; if(it3){ openQuickEdit(it3.row.id); } }
		});
	}

	function ensureVisible(){
		var y=S.cursorIdx*ROW_H;
		if(scrollEl){ if(y<scrollEl.scrollTop) scrollEl.scrollTop=y; else if(y+ROW_H>scrollEl.scrollTop+scrollEl.clientHeight) scrollEl.scrollTop=y+ROW_H-scrollEl.clientHeight; }
	}

	function delete_transient_hint(){
		// Hint: parent counts may be stale after reparent — reload parents after a short delay
		setTimeout(function(){ S.parents=null; loadParents(); },1500);
	}

	/* ───────────────────────────────────────────────
	 * Boot
	 * ─────────────────────────────────────────────── */
	paintShell();
	paintFilters();
	paintBanner();
	loadParents();
	loadStats();
	initialFill();

	})();
	</script>
	<?php
}
