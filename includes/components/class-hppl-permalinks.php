<?php
/**
 * Permalinks component.
 *
 * @package HivePress\Components
 */

namespace HivePress\Components;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Adds category and region segments to HivePress object permalinks.
 *
 * The class and file names are both prefixed because HivePress globs
 * `includes/components/*.php` across every extension and loads one class per
 * file name, so an unprefixed name silently loses to any other plugin shipping
 * the same one.
 *
 * WHY THIS DOES NOT REUSE THE TAXONOMIES' OWN REWRITE TAGS
 *
 * The obvious implementation is to drop `%hp_listing_category%` into the
 * listing permalink structure and let WordPress fill it, because registering a
 * taxonomy already defines that tag. It was written that way first and is
 * wrong for two reasons, both of which bite a live site:
 *
 * 1. The tag's regex belongs to the taxonomy, not to us. WordPress compiles it
 *    as `([^/]+)` unless the taxonomy was registered with
 *    `rewrite['hierarchical']` (class-wp-taxonomy.php:507-511, WP 7.1), and
 *    HivePress registers none of its taxonomies that way. A single segment can
 *    then never hold `parent/child`, so nested categories and the
 *    country/state/city region paths that four community topics asked for are
 *    impossible. Widening the tag to `(.+?)` would rewrite the taxonomy's OWN
 *    archive rules at the same time, changing URLs nobody asked us to touch.
 * 2. Filling a taxonomy tag makes the term part of the query. WordPress then
 *    requires the object to actually hold that term, so a listing whose
 *    category was edited stops answering on its old address instead of
 *    redirecting, and an object with no term at all cannot resolve.
 *
 * So this registers its own tags, one per post type and taxonomy pair, whose
 * regex is ours to choose and whose captured value is thrown away in
 * `drop_segment_vars()`. The segments are decorative: the object slug at the
 * end of the path is what identifies the object. That is what lets an address
 * carrying a stale or plain wrong category still find its object and redirect
 * to the current address, rather than 404.
 */
final class Hppl_Permalinks extends Component {

	/**
	 * Slug used when an object has no term in a taxonomy that is in its URL.
	 *
	 * A URL segment, not copy: translating it would change every address on the
	 * site, so it deliberately does not go through a translation function. A
	 * community topic reported malformed addresses with empty path segments
	 * from a snippet that simply omitted the missing term, which is what this
	 * placeholder exists to prevent. Sites wanting a different word can use the
	 * `hivepress/v1/permalinks/placeholder_slug` filter.
	 *
	 * @var string
	 */
	const PLACEHOLDER = 'other';

	/**
	 * Cached supported post types, keyed by name.
	 *
	 * @var array<string, array<string>>|null
	 */
	protected $supported;

	/**
	 * Cached settings.
	 *
	 * @var array<string, mixed>|null
	 */
	protected $settings;

	/**
	 * Class constructor.
	 *
	 * @param array<string, mixed> $args Component arguments.
	 */
	public function __construct( $args = [] ) {
		/*
		 * Priority 100, because the structures can only be amended once every post type and
		 * taxonomy exists. HivePress registers both on init at the default priority 10
		 * (hivepress/includes/components/class-admin.php:44-47, core 1.7.31), and the Geolocation
		 * extension's region taxonomies are added in the same pass, so by 100 `taxonomy_exists()`
		 * gives the real answer rather than the answer of a plugin that has not spoken yet.
		 */
		add_action( 'init', [ $this, 'amend_permalink_structures' ], 100 );

		// Fill this plugin's own tags when object links are generated.
		add_filter( 'post_type_link', [ $this, 'fill_permalink_tags' ], 10, 2 );

		// Throw away the decorative segments once a URL has matched.
		add_filter( 'request', [ $this, 'drop_segment_vars' ] );

		// Optionally keep HivePress addresses at the site root.
		add_filter( 'register_post_type_args', [ $this, 'set_permalink_front' ], 20, 2 );
		add_filter( 'register_taxonomy_args', [ $this, 'set_permalink_front' ], 20, 2 );

		// Give blog posts a folder of their own, leaving HivePress addresses where they are.
		add_action( 'generate_rewrite_rules', [ $this, 'add_blog_prefix_rules' ] );
		add_filter( 'post_link', [ $this, 'set_blog_prefix_link' ], 10, 2 );

		if ( is_admin() ) {

			// Add the options to the Permalinks page, saving first.
			add_action( 'admin_init', [ $this, 'add_permalink_settings' ] );
		} else {
			/*
			 * Send old and mistyped addresses to the current ones, at priority 9 so this runs
			 * ahead of WordPress's own redirect_canonical (priority 10, default-filters.php).
			 * Measured on a test site: core's 404 guessing fired first and sent a listing address
			 * to an unrelated WooCommerce product that happened to share part of the slug.
			 */
			add_action( 'template_redirect', [ $this, 'redirect_object_urls' ], 9 );

			/*
			 * WordPress runs its OWN old-slug redirect at template_redirect, and it takes the first
			 * match it finds with no check for a second. Declining to guess in this plugin's own
			 * handler therefore was not enough: core would guess anyway, on an address that only
			 * matched a rule because of the segments added here. This makes both paths agree.
			 */
			add_filter( 'old_slug_redirect_post_id', [ $this, 'block_ambiguous_old_slug' ] );
		}

		parent::__construct( $args );
	}

	/*
	 * ---------------------------------------------------------------------------------------------
	 * What this plugin can act on.
	 * ---------------------------------------------------------------------------------------------
	 */

	/**
	 * Gets the HivePress post types whose addresses can carry extra segments,
	 * each mapped to the taxonomies available as segments.
	 *
	 * Discovered rather than hard-coded, so a site running Requests or a future
	 * HivePress object type is covered without this plugin naming it. Only
	 * public hierarchical taxonomies qualify: HivePress registers its select
	 * attributes as private taxonomies too (condition, availability, type and
	 * so on), and putting a private attribute in a public address would leak
	 * data the site owner chose not to publish.
	 *
	 * @return array<string, array<string>>
	 */
	public function get_supported_types() {
		if ( is_array( $this->supported ) ) {
			return $this->supported;
		}

		global $wp_rewrite;

		$this->supported = [];

		foreach ( get_post_types( [ 'public' => true ], 'objects' ) as $post_type ) {

			// HivePress objects only. This plugin has no business rewriting another plugin's URLs,
			// and the community topic that asked for a blanket sweep across every registered post
			// type was answered by HivePress with a warning that it is higher maintenance and
			// blind to whatever registers next.
			if ( 0 !== strpos( $post_type->name, 'hp_' ) ) {
				continue;
			}

			// No permalink structure means nothing to amend, which is the case on a site still set
			// to plain permalinks.
			if ( ! isset( $wp_rewrite->extra_permastructs[ $post_type->name ]['struct'] ) ) {
				continue;
			}

			$taxonomies = [];

			foreach ( get_object_taxonomies( $post_type->name, 'objects' ) as $taxonomy ) {
				if ( $taxonomy->public && $taxonomy->hierarchical ) {
					$taxonomies[] = $taxonomy->name;
				}
			}

			if ( $taxonomies ) {
				$this->supported[ $post_type->name ] = $taxonomies;
			}
		}

		/**
		 * Filters the post types whose permalinks can carry extra segments.
		 *
		 * @param {array} $types Post types mapped to available taxonomy names.
		 * @return {array} Post types mapped to available taxonomy names.
		 */
		$this->supported = (array) apply_filters( 'hivepress/v1/permalinks/types', $this->supported );

		return $this->supported;
	}

	/**
	 * Gets every setting this plugin stores.
	 *
	 * All of them live in ONE autoloaded option rather than one option each.
	 * That is not tidiness: a setting the owner has never touched is deleted
	 * rather than stored, and `get_option()` on an option that does not exist
	 * is a real SELECT, saved only by the `notoptions` cache, which is not
	 * persistent unless the site has an object cache drop-in. With an option
	 * per post type this component was adding five or more queries to every
	 * single request on a site that had configured nothing at all. One
	 * autoloaded array is already in `alloptions`, so it costs nothing.
	 *
	 * @return array<string, mixed>
	 */
	protected function get_settings() {
		if ( ! is_array( $this->settings ) ) {
			$this->settings = (array) get_option( HPPL_OPTION_PREFIX . 'settings', [] );
		}

		return $this->settings;
	}

	/**
	 * Gets one setting.
	 *
	 * @param string $key Setting name.
	 * @param mixed  $default_value Value to use when the setting is unset.
	 * @return mixed
	 */
	protected function get_setting( $key, $default_value = null ) {
		$settings = $this->get_settings();

		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default_value;
	}

	/**
	 * Stores the settings.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @return void
	 */
	protected function update_settings( $settings ) {
		$this->settings = $settings;

		update_option( HPPL_OPTION_PREFIX . 'settings', $settings );
	}

	/**
	 * Gets the structures a post type can be given, as ordered taxonomy lists.
	 *
	 * Every single taxonomy, then every ordered pair, because two community
	 * topics wanted the same two segments in opposite orders: one asked for the
	 * region before the category, another for the category first. Pairs are as
	 * far as this goes on purpose; a third segment is what the nested option is
	 * for, since a region hierarchy already spells out country, state and city.
	 *
	 * @param string $post_type Post type name.
	 * @return array<string, array<string>>
	 */
	public function get_available_structures( $post_type ) {
		$taxonomies = hp\get_array_value( $this->get_supported_types(), $post_type, [] );

		$structures = [];

		foreach ( $taxonomies as $first ) {
			$structures[ $first ] = [ $first ];

			foreach ( $taxonomies as $second ) {
				if ( $first !== $second ) {
					$structures[ $first . ',' . $second ] = [ $first, $second ];
				}
			}
		}

		return $structures;
	}

	/**
	 * Gets the structure a post type is currently set to use.
	 *
	 * Validated against what is available right now rather than trusted from
	 * storage, which is what makes the plugin notice that an extension has been
	 * switched off. A site that put the region in its listing addresses and
	 * then deactivated HivePress Geolocation no longer has a region taxonomy,
	 * so the stored value stops being an available structure and this returns
	 * the remaining segments, or none at all. The addresses rebuild themselves
	 * on the next request and the old ones redirect.
	 *
	 * @param string $post_type Post type name.
	 * @return array<string>
	 */
	public function get_structure( $post_type ) {
		$structures = (array) $this->get_setting( 'structures', [] );

		$stored = (string) hp\get_array_value( $structures, $post_type );

		if ( ! $stored ) {
			return [];
		}

		$available = hp\get_array_value( $this->get_supported_types(), $post_type, [] );

		$structure = [];

		foreach ( explode( ',', $stored ) as $taxonomy ) {
			$taxonomy = trim( $taxonomy );

			// Both tests matter. A taxonomy can be registered but no longer attached to this post
			// type, and it can be attached but no longer exist at all.
			if ( in_array( $taxonomy, $available, true ) && taxonomy_exists( $taxonomy ) && ! in_array( $taxonomy, $structure, true ) ) {
				$structure[] = $taxonomy;
			}
		}

		return $structure;
	}

	/**
	 * Checks whether a post type spells out the full path of nested terms.
	 *
	 * @param string $post_type Post type name.
	 * @return bool
	 */
	public function is_nested( $post_type ) {
		$nested = (array) $this->get_setting( 'nested', [] );

		return (bool) hp\get_array_value( $nested, $post_type );
	}

	/**
	 * Gets this plugin's own rewrite tag for a post type and taxonomy pair.
	 *
	 * @param string $post_type Post type name.
	 * @param string $taxonomy Taxonomy name.
	 * @return string
	 */
	protected function get_tag( $post_type, $taxonomy ) {
		return '%hppl_' . $post_type . '_' . $taxonomy . '%';
	}

	/*
	 * ---------------------------------------------------------------------------------------------
	 * Building the addresses.
	 * ---------------------------------------------------------------------------------------------
	 */

	/**
	 * Inserts the configured segments into every supported permalink structure.
	 *
	 * The structures are edited in place rather than through the registration
	 * filters so that this can be re-run within one request: when the
	 * Permalinks page saves, the post types were already registered on init
	 * with the old values, and WordPress flushes the stored rules from the
	 * in-memory structures later in that same request
	 * (wp-admin/options-permalink.php:212, WP 7.1). Re-running this after
	 * saving is what makes the flush store the right rules first time, rather
	 * than a request later.
	 *
	 * Stripping this plugin's tags before adding them back keeps it idempotent.
	 *
	 * @return void
	 */
	public function amend_permalink_structures() {
		global $wp_rewrite;

		/*
		 * Nothing to do on a site set to plain permalinks, and it must return BEFORE the fingerprint
		 * below rather than merely finding no post types. WP_Post_Type::set_props() keeps a post
		 * type's rewrite arguments when `is_admin() || get_option( 'permalink_structure' )`, so on a
		 * plain-permalink site the structures exist in wp-admin and do not exist on the front end.
		 * The two contexts then computed different fingerprints, each overwrote the other's, and
		 * every single request wrote an autoloaded option - which empties the whole alloptions cache
		 * with it. An idle plugin on a plain-permalink site was doing that forever.
		 */
		if ( ! get_option( 'permalink_structure' ) ) {
			return;
		}

		$fingerprint = [];

		foreach ( array_keys( $this->get_supported_types() ) as $post_type ) {
			$struct = $this->amend_permalink_structure( $post_type );

			if ( null !== $struct ) {

				// The nested flag belongs in here as well as the structure. It changes the rewrite tag's
				// REGEX rather than the structure string, so on its own it left the fingerprint identical
				// and the stored rules stale - and a stale rule here means every address 404s.
				$fingerprint[ $post_type ] = $struct . ( $this->is_nested( $post_type ) ? '|nested' : '' );
			}
		}

		/*
		 * The stored rewrite rules only rebuild when something deletes them, so a structure that
		 * changed anywhere other than the Permalinks page would otherwise leave every address
		 * answering 404 until somebody happened to press Save Changes - which is exactly what two
		 * community topics spent days on. Every one of these changes the fingerprint and so
		 * repairs itself on the next request: a base slug edited on the HivePress side, Geolocation
		 * switched off, regions turned off in its settings, an import restoring the options, this
		 * plugin reactivated, or an update that changes how the rules are generated.
		 *
		 * The plugin version is part of the fingerprint for that last case: an update can change
		 * the generated rules without changing any structure string, so salting with the version
		 * makes every update rebuild exactly once.
		 */
		$fingerprint = HPPL_VERSION
			. '|' . ( $this->has_front_removal() ? 'nofront' : 'front' )
			. '|blog:' . $this->get_blog_prefix()
			. '|' . wp_json_encode( $fingerprint );

		if ( get_option( HPPL_OPTION_PREFIX . 'fingerprint' ) !== $fingerprint ) {
			update_option( HPPL_OPTION_PREFIX . 'fingerprint', $fingerprint );

			delete_option( 'rewrite_rules' );
		}
	}

	/**
	 * Inserts the configured segments into one permalink structure.
	 *
	 * @param string $post_type Post type name.
	 * @return string|null The amended structure, or null if there is none.
	 */
	protected function amend_permalink_structure( $post_type ) {
		global $wp_rewrite;

		if ( ! isset( $wp_rewrite->extra_permastructs[ $post_type ]['struct'] ) ) {
			return null;
		}

		$struct = $wp_rewrite->extra_permastructs[ $post_type ]['struct'];

		// Strip this plugin's tags, so re-running never doubles them up.
		$struct = (string) preg_replace( '#%hppl_[a-z0-9_-]+%/#', '', $struct );

		$structure = $this->get_structure( $post_type );

		$nested = $this->is_nested( $post_type );

		$segments = '';

		foreach ( $structure as $taxonomy ) {
			$tag = $this->get_tag( $post_type, $taxonomy );

			/*
			 * The regex is this plugin's to choose precisely because the tag is its own. `(.+?)` is
			 * non-greedy and the object slug after it is `([^/]+)`, which cannot hold a slash, so
			 * even a nested path splits correctly: for /listing/a/b/c the engine backtracks until
			 * the segment takes "a/b" and the slug takes "c". With two nested segments the split
			 * between them is genuinely ambiguous, and that is harmless here because the captured
			 * values are thrown away unread - the slug identifies the object.
			 */
			add_rewrite_tag( $tag, $nested ? '(.+?)' : '([^/]+)', 'hppl_' . $post_type . '_' . $taxonomy . '=' );

			$segments .= $tag . '/';
		}

		if ( $segments ) {
			$struct = str_replace( '%' . $post_type . '%', $segments . '%' . $post_type . '%', $struct );
		}

		$wp_rewrite->extra_permastructs[ $post_type ]['struct'] = $struct;

		/*
		 * With extra tags in place, WordPress's own rule generation would also "walk" the structure
		 * and emit rules for each shorter prefix of it (class-wp-rewrite.php:948, WP 7.1), mapping
		 * /base/{segment}/ to an archive query. Measured on a test site, that served a category
		 * archive with a 200 for ANY second segment, so an address carrying an out-of-date category
		 * quietly showed a category page instead of redirecting to the object. Switching the walk
		 * off removes those rules; the shorter addresses then fall through to the redirect handler
		 * below, which sends them to the object they name. Left at the WordPress default when this
		 * plugin adds nothing, so an idle plugin changes no rules at all.
		 */
		$wp_rewrite->extra_permastructs[ $post_type ]['walk_dirs'] = '' === $segments;

		return $struct;
	}

	/*
	 * ---------------------------------------------------------------------------------------------
	 * Giving blog posts a folder of their own.
	 * ---------------------------------------------------------------------------------------------
	 */

	/**
	 * Gets the folder blog posts are served from, or an empty string when off.
	 *
	 * @return string
	 */
	public function get_blog_prefix() {
		if ( ! $this->get_setting( 'blog_prefix' ) || $this->has_front() ) {
			return '';
		}

		$slug = sanitize_title( (string) $this->get_setting( 'blog_prefix_slug', 'blog' ) );

		return $slug ? $slug : 'blog';
	}

	/**
	 * Adds the rules that serve blog posts from their folder.
	 *
	 * WHY THIS RATHER THAN A PERMALINK FRONT
	 *
	 * The obvious way to get /blog/ in front of posts is to set the site's own
	 * permalink structure to `/blog/%postname%/`. WordPress treats whatever
	 * precedes the first tag there as the site's FRONT and prepends it to
	 * everything registered with the default `with_front`, so every listing,
	 * vendor, category and tag address gains /blog/ as well. The usual answer to
	 * that is to strip `with_front` back off every HivePress object, and on the
	 * community topic where both approaches were worked through, HivePress
	 * preferred this one: adding the folder to posts only is less to maintain
	 * and cannot be outflanked by whatever registers the next post type. They
	 * also noted the other approach can leave the prefix on ordinary pages.
	 *
	 * The rules are prepended so they are tested before WordPress's own, and the
	 * specific ones come before the plain one, or /blog/my-post/feed/ would be
	 * read as a post called "feed".
	 *
	 * @param \WP_Rewrite $wp_rewrite Rewrite instance.
	 * @return void
	 */
	public function add_blog_prefix_rules( $wp_rewrite ) {
		$prefix = $this->get_blog_prefix();

		if ( ! $prefix ) {
			return;
		}

		$prefix = preg_quote( $prefix, '#' );

		$rules = [
			$prefix . '/([^/]+)/feed/(feed|rdf|rss|rss2|atom)/?$' => 'index.php?name=$matches[1]&feed=$matches[2]',
			$prefix . '/([^/]+)/(feed|rdf|rss|rss2|atom)/?$' => 'index.php?name=$matches[1]&feed=$matches[2]',
			$prefix . '/([^/]+)/page/?([0-9]{1,})/?$' => 'index.php?name=$matches[1]&paged=$matches[2]',
			$prefix . '/([^/]+)/comment-page-([0-9]{1,})/?$' => 'index.php?name=$matches[1]&cpage=$matches[2]',
			$prefix . '/([^/]+)/embed/?$'             => 'index.php?name=$matches[1]&embed=true',
			$prefix . '/([^/]+)/?$'                   => 'index.php?name=$matches[1]',
		];

		$wp_rewrite->rules = $rules + $wp_rewrite->rules;
	}

	/**
	 * Puts the folder into a blog post's address.
	 *
	 * Only the plain `post` type, so pages, HivePress objects and every other
	 * post type are left exactly as they were.
	 *
	 * @param string   $post_link Post URL.
	 * @param \WP_Post $post Post object.
	 * @return string
	 */
	public function set_blog_prefix_link( $post_link, $post ) {
		$prefix = $this->get_blog_prefix();

		if ( ! $prefix || ! $post instanceof \WP_Post || 'post' !== $post->post_type ) {
			return $post_link;
		}

		// A draft has no usable slug yet, and WordPress gives it a query-string address.
		if ( ! $post->post_name || false !== strpos( $post_link, '?' ) ) {
			return $post_link;
		}

		return home_url( '/' . $prefix . '/' . $post->post_name . '/' );
	}

	/**
	 * Sends an unprefixed blog post address to its prefixed one.
	 *
	 * WordPress's own post rules are untouched, so the old address still
	 * resolves and one post would answer at two addresses. Duplicate content is
	 * the opposite of what somebody turning on an SEO option wants.
	 *
	 * @return void
	 */
	protected function redirect_prefixed_post() {
		if ( get_query_var( 'page' ) || get_query_var( 'cpage' ) || get_query_var( 'paged' ) ) {
			return;
		}

		$post = get_queried_object();

		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$permalink = get_permalink( $post );

		if ( ! $permalink || false !== strpos( $permalink, '?' ) ) {
			return;
		}

		$requested = $this->get_requested_path();

		$canonical = $this->normalise_path( (string) wp_parse_url( $permalink, PHP_URL_PATH ) );

		if ( ! $requested || ! $canonical || $requested === $canonical ) {
			return;
		}

		// Only the post's own address, never an endpoint hanging off it.
		if ( basename( $requested ) !== rawurldecode( $post->post_name ) ) {
			return;
		}

		$this->redirect_to( $permalink );
	}

	/**
	 * Replaces this plugin's tags in an object permalink.
	 *
	 * WordPress substitutes only the post type's own tag when it builds a
	 * custom post type link (wp-includes/link-template.php), so every other tag
	 * in the structure is this plugin's to fill.
	 *
	 * @param string   $post_link Object URL.
	 * @param \WP_Post $post Post object.
	 * @return string
	 */
	public function fill_permalink_tags( $post_link, $post ) {
		if ( false === strpos( $post_link, '%hppl_' ) ) {
			return $post_link;
		}

		$structure = $this->get_structure( $post->post_type );

		$nested = $this->is_nested( $post->post_type );

		foreach ( $structure as $taxonomy ) {
			$post_link = str_replace(
				$this->get_tag( $post->post_type, $taxonomy ),
				$this->get_term_path( $post, $taxonomy, $nested ),
				$post_link
			);
		}

		// Belt and braces: a tag left unfilled would ship a literal "%hppl_...%" into a live
		// address. That can only happen if the structure changed between the rules being built and
		// this running, and an ugly address is still better than a broken one.
		return (string) preg_replace( '#%hppl_[a-z0-9_-]+%/#', '', $post_link );
	}

	/**
	 * Gets the URL path for an object's term in one taxonomy.
	 *
	 * The deepest term wins. Every community topic that asked for a region in
	 * the address wanted the city rather than the country it sits inside, and
	 * the snippets that shipped in those topics all took the first term the
	 * database happened to return, which is why they gave the wrong answer as
	 * soon as a listing was filed under a nested term.
	 *
	 * @param \WP_Post $post Post object.
	 * @param string   $taxonomy Taxonomy name.
	 * @param bool     $nested Whether to spell out the ancestors.
	 * @return string
	 */
	protected function get_term_path( $post, $taxonomy, $nested ) {
		$path = '';

		$terms = get_the_terms( $post, $taxonomy );

		if ( is_array( $terms ) && $terms ) {
			$term = null;

			$depth = -1;

			foreach ( $terms as $candidate ) {
				$ancestors = get_ancestors( $candidate->term_id, $taxonomy, 'taxonomy' );

				$candidate_depth = count( $ancestors );

				// Deepest wins; the lowest term ID breaks a tie, so the same object always produces
				// the same address rather than one that depends on query order.
				if ( $candidate_depth > $depth || ( $candidate_depth === $depth && $term && $candidate->term_id < $term->term_id ) ) {
					$term  = $candidate;
					$depth = $candidate_depth;
				}
			}

			/**
			 * Filters the term chosen to represent an object in its permalink.
			 *
			 * @param {WP_Term} $term Chosen term.
			 * @param {array} $terms All terms the object holds.
			 * @param {string} $taxonomy Taxonomy name.
			 * @param {WP_Post} $post Post object.
			 * @return {WP_Term} Chosen term.
			 */
			$filtered = apply_filters( 'hivepress/v1/permalinks/term', $term, $terms, $taxonomy, $post );

			if ( $filtered instanceof \WP_Term ) {
				$term = $filtered;
			}

			if ( $term ) {
				$slugs = [ $term->slug ];

				if ( $nested ) {
					foreach ( get_ancestors( $term->term_id, $taxonomy, 'taxonomy' ) as $ancestor_id ) {
						$ancestor = get_term( $ancestor_id, $taxonomy );

						if ( $ancestor instanceof \WP_Term ) {

							// get_ancestors() returns nearest parent first, so each one goes in front.
							array_unshift( $slugs, $ancestor->slug );
						}
					}
				}

				$path = implode( '/', $slugs );
			}
		}

		if ( ! $path ) {
			/**
			 * Filters the slug used when an object has no term in a taxonomy that is in its address.
			 *
			 * @param {string} $slug Placeholder slug.
			 * @param {string} $taxonomy Taxonomy name.
			 * @param {WP_Post} $post Post object.
			 * @return {string} Placeholder slug.
			 */
			$path = sanitize_title( apply_filters( 'hivepress/v1/permalinks/placeholder_slug', self::PLACEHOLDER, $taxonomy, $post ) );
		}

		return $path ? $path : self::PLACEHOLDER;
	}

	/**
	 * Throws away the decorative segments once a URL has matched.
	 *
	 * The rules built from an amended structure set this plugin's own query
	 * vars alongside the object slug. Nothing reads them: they exist only so
	 * the rule can match, and leaving them in the query would hand WordPress a
	 * variable it does not recognise.
	 *
	 * @param array<string, mixed> $query_vars Request query vars.
	 * @return array<string, mixed>
	 */
	public function drop_segment_vars( $query_vars ) {
		foreach ( array_keys( $query_vars ) as $name ) {
			if ( is_string( $name ) && 0 === strpos( $name, 'hppl_' ) ) {
				unset( $query_vars[ $name ] );
			}
		}

		return $query_vars;
	}

	/*
	 * ---------------------------------------------------------------------------------------------
	 * Keeping HivePress addresses at the site root.
	 * ---------------------------------------------------------------------------------------------
	 */

	/**
	 * Checks whether the front prefix is being removed from HivePress addresses.
	 *
	 * @return bool
	 */
	public function has_front_removal() {
		return (bool) $this->get_setting( 'no_front' );
	}

	/**
	 * Checks whether the site's permalink structure has a front prefix at all.
	 *
	 * A structure such as /blog/%postname%/ gives WordPress a front of "/blog",
	 * which it prepends to every post type and taxonomy registered with the
	 * default `with_front`. On the usual /%postname%/ structure the front is
	 * just "/" and there is nothing to remove, so the option is not offered.
	 *
	 * @return bool
	 */
	public function has_front() {
		global $wp_rewrite;

		return (bool) trim( (string) $wp_rewrite->front, '/' );
	}

	/**
	 * Keeps HivePress post types and taxonomies at the site root.
	 *
	 * A site that gives its blog posts a prefix - /blog/%postname%/ - finds
	 * that prefix on everything HivePress registers as well: /blog/listing/...,
	 * /blog/listing-category/..., /blog/vendor/... A community topic reported
	 * exactly that, and HivePress's own advice there was to fix it per object
	 * type rather than by sweeping every registered post type on the site,
	 * because a blanket filter is more to maintain and cannot know what another
	 * plugin registers next. This only ever touches names beginning "hp_".
	 *
	 * @param array<string, mixed> $args Registration arguments.
	 * @param string               $type Post type or taxonomy name.
	 * @return array<string, mixed>
	 */
	public function set_permalink_front( $args, $type ) {
		if ( 0 !== strpos( $type, 'hp_' ) || ! $this->has_front_removal() ) {
			return $args;
		}

		// Nothing to do for anything registered without rewriting.
		if ( empty( $args['rewrite'] ) ) {
			return $args;
		}

		if ( ! is_array( $args['rewrite'] ) ) {
			$args['rewrite'] = [];
		}

		$args['rewrite']['with_front'] = false;

		return $args;
	}

	/*
	 * ---------------------------------------------------------------------------------------------
	 * Sending old addresses to the current ones.
	 * ---------------------------------------------------------------------------------------------
	 */

	/**
	 * Sends old and mistyped object addresses to the current ones.
	 *
	 * Permanent redirects, so search engines move their index to the new
	 * address rather than splitting it across duplicates. Three cases:
	 *
	 * - A request that resolved an object at a path that is not its current
	 *   one, for example the address from before a segment was switched on, or
	 *   one holding a category the object has since been moved out of.
	 * - A 404 whose last segment is a published object's slug, which is what an
	 *   old address looks like once the number of segments stops matching any
	 *   rule.
	 * - A 404 whose last segment is a slug the object used to have. This is the
	 *   one confirmed data-loss bug in the community topics: with a custom
	 *   structure in place, editing a listing's title changed its slug and
	 *   every indexed address for it died with "Nothing Found", because
	 *   WordPress's own old-slug redirect only runs when the request still
	 *   matched a rule well enough to set a name to look up
	 *   (wp-includes/query.php:1075-1076, WP 7.1), and a stale address with the
	 *   wrong number of segments never gets that far.
	 *
	 * @return void
	 */
	public function redirect_object_urls() {
		if ( is_preview() || is_feed() || is_embed() || is_robots() ) {
			return;
		}

		if ( is_404() ) {
			$this->redirect_missed_object();

			return;
		}

		if ( $this->get_blog_prefix() && is_singular( 'post' ) ) {
			$this->redirect_prefixed_post();

			return;
		}

		$post_types = array_keys( $this->get_supported_types() );

		if ( ! $post_types || ! is_singular( $post_types ) ) {
			return;
		}

		// Leave paged and commented views alone; their addresses legitimately extend the object's.
		if ( get_query_var( 'page' ) || get_query_var( 'cpage' ) ) {
			return;
		}

		$post = get_queried_object();

		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$permalink = get_permalink( $post );

		// Plain permalinks carry the object in a query string, so there is no path to canonicalise.
		if ( ! $permalink || false !== strpos( $permalink, '?' ) ) {
			return;
		}

		$requested = $this->get_requested_path();

		$canonical = $this->normalise_path( (string) wp_parse_url( $permalink, PHP_URL_PATH ) );

		if ( ! $requested || ! $canonical || $requested === $canonical ) {
			return;
		}

		// Only a plain object address in the wrong hierarchy is redirected. Anything that extends
		// the canonical path is an endpoint belonging to something else and is left alone.
		// The stored slug is percent-encoded for anything non-ASCII, and the requested path has been
		// decoded, so the slug is decoded too rather than compared across the two forms.
		if ( basename( $requested ) !== rawurldecode( $post->post_name ) ) {
			return;
		}

		$this->redirect_to( $permalink );
	}

	/**
	 * Redirects a 404 to the object its last segment names.
	 *
	 * @return void
	 */
	protected function redirect_missed_object() {
		$requested = $this->get_requested_path();

		if ( ! $requested ) {
			return;
		}

		$post_type = $this->get_post_type_by_path( $requested );

		if ( ! $post_type ) {
			return;
		}

		$slug = sanitize_title( basename( $requested ) );

		if ( ! $slug ) {
			return;
		}

		$post = $this->find_object_by_slug( $post_type, $slug );

		if ( ! $post ) {
			return;
		}

		$permalink = get_permalink( $post );

		if ( ! $permalink ) {
			return;
		}

		/*
		 * The loop guard, and it is not theoretical. If the object's own address 404s too - a
		 * misconfiguration, or rules that have not rebuilt yet - then redirecting to it would land
		 * on this same handler, which would compute the same target and redirect again for as long
		 * as the browser allowed. Comparing the paths first means the second request stops here.
		 */
		if ( $this->normalise_path( (string) wp_parse_url( $permalink, PHP_URL_PATH ) ) === $requested ) {
			return;
		}

		$this->redirect_to( $permalink );
	}

	/**
	 * Finds a published object by its current slug, then by an old one.
	 *
	 * @param string $post_type Post type name.
	 * @param string $slug Object slug.
	 * @return \WP_Post|null
	 */
	protected function find_object_by_slug( $post_type, $slug ) {
		$posts = get_posts(
			[
				'post_type'        => $post_type,
				'name'             => $slug,
				'post_status'      => 'publish',
				'numberposts'      => 1,
				'suppress_filters' => false,
			]
		);

		if ( $posts ) {
			return $posts[0];
		}

		/*
		 * No object holds that slug now, so it may be one that was renamed. WordPress records the
		 * previous slug of a published post in "_wp_old_slug" post meta whenever the slug changes
		 * (wp-includes/post.php:7580-7589, WP 7.1), which is what makes an address survive a title
		 * edit. A meta query is the only way to search it.
		 *
		 * Two objects really can share an old slug, because WordPress only keeps the CURRENT slug
		 * unique: rename "Bike" to "Red Bike" and the slug "bike" is free again for the next object,
		 * which may later be renamed in its turn. So this asks for two and refuses to choose. A
		 * wrong guess here would be a PERMANENT redirect handing one object's search ranking to
		 * another, which nothing can undo; a 404 is honest and recoverable. Oldest first, so the
		 * single-match case returns the object that actually held the slug when the address was
		 * indexed rather than whichever the database happened to return.
		 */
		$posts = $this->find_objects_by_old_slug( $post_type, $slug );

		return 1 === count( $posts ) ? $posts[0] : null;
	}

	/**
	 * Finds the published objects that used to hold a slug.
	 *
	 * Asks for two, because the only thing the callers need to know is whether
	 * exactly one object owns the slug or more than one does.
	 *
	 * @param string $post_type Post type name.
	 * @param string $slug Object slug.
	 * @return array<int, \WP_Post>
	 */
	protected function find_objects_by_old_slug( $post_type, $slug ) {
		return (array) get_posts(
			[
				'post_type'           => $post_type,
				'post_status'         => 'publish',
				'numberposts'         => 2,
				'orderby'             => 'ID',
				'order'               => 'ASC',
				'suppress_filters'    => false,
				'ignore_sticky_posts' => true,

				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the only index into _wp_old_slug, and this runs on a 404 that would otherwise be a dead end.
				'meta_query'          => [
					[
						'key'   => '_wp_old_slug',
						'value' => $slug,
					],
				],
			]
		);
	}

	/**
	 * Stops WordPress redirecting an old slug that more than one object held.
	 *
	 * WordPress keeps only the CURRENT slug unique, so renaming "Bike" to "Red
	 * Bike" frees "bike" for the next object, which may be renamed in its turn -
	 * and both then carry "bike" in their `_wp_old_slug` meta. Core's
	 * `wp_old_slug_redirect()` takes whichever it finds first
	 * (wp-includes/query.php, WP 7.1) and sends a PERMANENT redirect, which
	 * would hand one object's search ranking to another with no way to undo it.
	 *
	 * A 404 is honest and recoverable, so an ambiguous slug gets one. Scoped to
	 * the post types this plugin builds addresses for: how WordPress treats
	 * ordinary posts is not this plugin's business to change.
	 *
	 * @param int $post_id Post ID core intends to redirect to.
	 * @return int
	 */
	public function block_ambiguous_old_slug( $post_id ) {
		if ( ! $post_id ) {
			return $post_id;
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || ! isset( $this->get_supported_types()[ $post->post_type ] ) ) {
			return $post_id;
		}

		$slug = (string) get_query_var( 'name' );

		if ( ! $slug ) {
			return $post_id;
		}

		return count( $this->find_objects_by_old_slug( $post->post_type, $slug ) ) > 1 ? 0 : $post_id;
	}

	/**
	 * Gets the requested path, without the site's own directory or a trailing slash.
	 *
	 * @return string
	 */
	protected function get_requested_path() {
		$requested = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		if ( ! $requested ) {
			return '';
		}

		return $this->normalise_path( (string) wp_parse_url( $requested, PHP_URL_PATH ) );
	}

	/**
	 * Puts a path into the one form every comparison in this class uses.
	 *
	 * Both halves matter and getting them out of step is what made the first
	 * version of this loop.
	 *
	 * WordPress stores a non-ASCII slug percent-encoded (sanitize_title_with_dashes
	 * runs it through utf8_uri_encode, wp-includes/formatting.php, WP 7.1) and
	 * get_permalink() inserts that stored form verbatim, while the path a browser
	 * sends is percent-encoded too but is decoded by rawurldecode() on the way in
	 * here. Comparing one against the other, a listing under a category called
	 * "Bicicletas" or any non-Latin name could never equal its own canonical
	 * address, so the redirect fired on the address the visitor was already on and
	 * the browser gave up with a redirect loop. Decoding BOTH sides is what makes
	 * the equality guard mean what it says.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	protected function normalise_path( $path ) {
		return untrailingslashit( rawurldecode( $path ) );
	}

	/**
	 * Works out which post type a path belongs to, by its base.
	 *
	 * The base is read from the live permalink structure rather than assumed,
	 * so a site that renamed "listing" to "ads" on the Permalinks page is
	 * handled without this plugin knowing anything about it. Every snippet in
	 * the community topics hard-coded its base, and one of their authors said
	 * outright that changing the base in settings silently broke every address.
	 *
	 * The longest matching base wins, so a site whose vendor base sits inside
	 * its listing base cannot be misattributed.
	 *
	 * @param string $path Requested path.
	 * @return string
	 */
	protected function get_post_type_by_path( $path ) {
		global $wp_rewrite;

		$path = trim( $path, '/' );

		// Strip the site's own directory, so a subdirectory install compares like for like.
		$home = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );

		if ( $home && 0 === strpos( $path . '/', $home . '/' ) ) {
			$path = trim( substr( $path, strlen( $home ) ), '/' );
		}

		$found = '';

		$length = 0;

		foreach ( array_keys( $this->get_supported_types() ) as $post_type ) {
			if ( ! isset( $wp_rewrite->extra_permastructs[ $post_type ]['struct'] ) ) {
				continue;
			}

			$struct = $wp_rewrite->extra_permastructs[ $post_type ]['struct'];

			$position = strpos( $struct, '%' );

			if ( false === $position ) {
				continue;
			}

			$base = trim( substr( $struct, 0, $position ), '/' );

			// A structure with no base at all would match every path on the site.
			if ( ! $base ) {
				continue;
			}

			if ( 0 === strpos( $path . '/', $base . '/' ) && strlen( $base ) > $length ) {
				$found  = $post_type;
				$length = strlen( $base );
			}
		}

		// The base on its own is the archive, not a missing object.
		if ( $found && trim( $path, '/' ) === trim( substr( $path, 0, $length ), '/' ) ) {
			return '';
		}

		return $found;
	}

	/**
	 * Sends a permanent redirect, keeping any query string.
	 *
	 * @param string $target Target URL.
	 * @return void
	 */
	protected function redirect_to( $target ) {
		$requested = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		// Tracking parameters and the like survive the move.
		$query = (string) wp_parse_url( $requested, PHP_URL_QUERY );

		if ( $query ) {
			$target .= ( false === strpos( $target, '?' ) ? '?' : '&' ) . $query;
		}

		wp_safe_redirect( $target, 301 );

		exit;
	}

	/*
	 * ---------------------------------------------------------------------------------------------
	 * The Permalinks page.
	 * ---------------------------------------------------------------------------------------------
	 */

	/**
	 * Adds this plugin's options to the Permalinks page, saving first.
	 *
	 * Saving happens on admin_init, before options-permalink.php processes its
	 * own POST and flushes the stored rules (wp-admin/options-permalink.php:212,
	 * WP 7.1), so that flush already sees the new values. WordPress runs no
	 * settings API saving on this page, which is why the fields are read from
	 * the POST by hand; HivePress saves its own base slugs the same way
	 * (hivepress/includes/components/class-admin.php:382-455, core 1.7.31).
	 *
	 * @return void
	 */
	public function add_permalink_settings() {
		global $pagenow;

		if ( 'options-permalink.php' !== $pagenow ) {
			return;
		}

		$this->save_permalink_settings();

		add_settings_section(
			'hppl_permalinks',
			esc_html__( 'HivePress URLs', 'permalinks-for-hivepress' ),
			[ $this, 'render_settings_section' ],
			'permalink'
		);

		foreach ( array_keys( $this->get_supported_types() ) as $post_type ) {
			$this->add_type_settings( $post_type );
		}

		if ( ! $this->has_front() ) {
			add_settings_field(
				HPPL_OPTION_PREFIX . 'blog_prefix',
				esc_html__( 'Blog posts', 'permalinks-for-hivepress' ),
				[ $this, 'render_settings_field' ],
				'permalink',
				'hppl_permalinks',
				[
					'name'        => HPPL_OPTION_PREFIX . 'blog_prefix',
					'type'        => 'checkbox',
					'current'     => (bool) $this->get_setting( 'blog_prefix' ),
					'caption'     => esc_html__( 'Put your blog posts in a folder of their own', 'permalinks-for-hivepress' ),
					'description' => esc_html__( 'Gives ordinary WordPress posts an address such as /blog/my-post/ while leaving every HivePress address exactly where it is. This is the way round HivePress recommend, because putting a prefix in the permalink structure above would place it in front of your listings and categories as well.', 'permalinks-for-hivepress' ),
					'text'        => [
						'name'        => HPPL_OPTION_PREFIX . 'blog_prefix_slug',
						'current'     => (string) $this->get_setting( 'blog_prefix_slug', 'blog' ),
						'label'       => esc_html__( 'Folder name', 'permalinks-for-hivepress' ),
						'description' => esc_html__( 'Use a different word here if you prefer, such as news or articles. Whichever you choose, the addresses your posts had before are redirected to the new ones.', 'permalinks-for-hivepress' ),
					],
				]
			);
		}

		if ( $this->has_front() ) {
			add_settings_field(
				HPPL_OPTION_PREFIX . 'no_front',
				esc_html__( 'Prefix', 'permalinks-for-hivepress' ),
				[ $this, 'render_settings_field' ],
				'permalink',
				'hppl_permalinks',
				[
					'name'        => HPPL_OPTION_PREFIX . 'no_front',
					'type'        => 'checkbox',
					'current'     => $this->has_front_removal(),
					'caption'     => esc_html__( 'Keep HivePress addresses at the top level', 'permalinks-for-hivepress' ),
					'description' => sprintf(
						/* translators: %s: the prefix taken from the site's permalink structure, such as /blog/. */
						esc_html__( 'Your permalink structure adds %s to the front of every address, including HivePress ones. Tick this to leave HivePress addresses without it.', 'permalinks-for-hivepress' ),
						'<code>/' . esc_html( trim( (string) $GLOBALS['wp_rewrite']->front, '/' ) ) . '/</code>'
					),
				]
			);
		}

		add_settings_field(
			HPPL_OPTION_PREFIX . 'delete_data',
			esc_html__( 'Removing the plugin', 'permalinks-for-hivepress' ),
			[ $this, 'render_settings_field' ],
			'permalink',
			'hppl_permalinks',
			[
				'name'        => HPPL_OPTION_PREFIX . 'delete_data',
				'type'        => 'checkbox',
				'current'     => (bool) $this->get_setting( 'delete_data' ),
				'caption'     => esc_html__( 'Delete all data when this plugin is deleted', 'permalinks-for-hivepress' ),
				'description' => esc_html__( 'Your settings above are kept if you delete this plugin, even though the WordPress delete screen warns that data will be removed. Tick this box only if you want the settings deleted for good. This cannot be undone.', 'permalinks-for-hivepress' ),
			]
		);
	}

	/**
	 * Adds the two controls for one post type.
	 *
	 * @param string $post_type Post type name.
	 * @return void
	 */
	protected function add_type_settings( $post_type ) {
		$object = get_post_type_object( $post_type );

		if ( ! $object ) {
			return;
		}

		$label = $object->labels->singular_name;

		$options = [
			'' => sprintf(
				/* translators: %s: an example address with no extra segments. */
				esc_html__( 'Default (%s)', 'permalinks-for-hivepress' ),
				$this->get_example_path( $post_type, [] )
			),
		];

		foreach ( $this->get_available_structures( $post_type ) as $key => $structure ) {
			$options[ $key ] = sprintf(
				/* translators: 1: the segments listed in order, such as "Category, region", 2: an example address. */
				esc_html__( '%1$s (%2$s)', 'permalinks-for-hivepress' ),
				$this->get_structure_label( $structure ),
				$this->get_example_path( $post_type, $structure )
			);
		}

		add_settings_field(
			HPPL_OPTION_PREFIX . $post_type . '_structure',
			esc_html( $label ),
			[ $this, 'render_settings_field' ],
			'permalink',
			'hppl_permalinks',
			[
				'name'        => HPPL_OPTION_PREFIX . $post_type . '_structure',
				'type'        => 'select',
				'options'     => $options,
				'current'     => implode( ',', $this->get_structure( $post_type ) ),
				'description' => sprintf(
					/* translators: %s: the singular name of the object type, lowercased, such as "listing". */
					esc_html__( 'Choose what appears in the address of each %s. Whichever you pick, the old addresses keep working.', 'permalinks-for-hivepress' ),
					esc_html( $this->lowercase( $label ) )
				),
				'nested'      => [
					'name'        => HPPL_OPTION_PREFIX . $post_type . '_nested',
					'type'        => 'checkbox',
					'current'     => $this->is_nested( $post_type ),
					'caption'     => esc_html__( 'Spell out nested terms in full', 'permalinks-for-hivepress' ),
					'description' => esc_html__( 'A term inside another one brings its parents with it, so a city shows as country/region/city rather than just the city.', 'permalinks-for-hivepress' ),
				],
			]
		);
	}

	/**
	 * Builds the human-readable name of a structure, such as "Category, region".
	 *
	 * Taken from the taxonomies' own labels, so a site that renamed them, or
	 * translated them, reads correctly here without this plugin knowing the
	 * names of anything.
	 *
	 * @param array<string> $structure Taxonomy names in order.
	 * @return string
	 */
	protected function get_structure_label( $structure ) {
		$labels = [];

		foreach ( $structure as $index => $taxonomy ) {
			$object = get_taxonomy( $taxonomy );

			$label = $object ? $object->labels->singular_name : $taxonomy;

			$labels[] = 0 === $index ? $label : $this->lowercase( $label );
		}

		return implode( ', ', $labels );
	}

	/**
	 * Lowercases the first letter of a label for use mid-sentence.
	 *
	 * Only the first character, so an acronym or a proper noun further along is
	 * left as the site owner wrote it.
	 *
	 * @param string $label Label.
	 * @return string
	 */
	protected function lowercase( $label ) {
		if ( ! $label ) {
			return $label;
		}

		if ( ! function_exists( 'mb_substr' ) ) {
			return lcfirst( $label );
		}

		$first = mb_substr( $label, 0, 1 );

		// Only lowercase a letter the site itself capitalised. A label that already starts lowercase,
		// or starts with a character that has no case at all, is left exactly as it was written.
		if ( mb_strtoupper( $first ) !== $first ) {
			return $label;
		}

		return mb_strtolower( $first ) . mb_substr( $label, 1 );
	}

	/**
	 * Builds an example address for one structure.
	 *
	 * @param string        $post_type Post type name.
	 * @param array<string> $structure Taxonomy names in order.
	 * @return string
	 */
	protected function get_example_path( $post_type, $structure ) {
		global $wp_rewrite;

		$base = '';

		if ( isset( $wp_rewrite->extra_permastructs[ $post_type ]['struct'] ) ) {
			$struct = $wp_rewrite->extra_permastructs[ $post_type ]['struct'];

			$position = strpos( $struct, '%' );

			if ( false !== $position ) {
				$base = trim( substr( $struct, 0, $position ), '/' );
			}
		}

		$path = '/' . ( $base ? $base . '/' : '' );

		foreach ( $structure as $taxonomy ) {
			$object = get_taxonomy( $taxonomy );

			$path .= sanitize_title( $object ? $object->labels->singular_name : $taxonomy ) . '/';
		}

		$object = get_post_type_object( $post_type );

		return $path . sanitize_title( $object ? $object->labels->singular_name : $post_type ) . '-name/';
	}

	/**
	 * Saves the options posted from the Permalinks page.
	 *
	 * @return void
	 */
	protected function save_permalink_settings() {

		// The hidden marker distinguishes "unticked" from "not our form", which a checkbox alone
		// cannot: an unticked box posts nothing at all.
		if ( ! isset( $_POST[ HPPL_OPTION_PREFIX . 'settings' ] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// The Permalinks form's own nonce (wp-admin/options-permalink.php:220, WP 7.1).
		check_admin_referer( 'update-permalink' );

		/*
		 * "no_front" is saved whether or not its field was on screen. It is only RENDERED while the
		 * site's permalink structure has a front to remove, and gating the save on the same test
		 * meant that an owner who ticked it and then simplified their permalink structure could
		 * never untick it: the field vanished, the save loop stopped clearing it, and the stored
		 * "1" went on forcing with_front off for good, reappearing the moment a front came back.
		 */
		$checkboxes = [ 'delete_data', 'no_front', 'blog_prefix' ];

		$settings = $this->get_settings();

		$settings['structures'] = [];
		$settings['nested']     = [];

		foreach ( array_keys( $this->get_supported_types() ) as $post_type ) {
			$name = HPPL_OPTION_PREFIX . $post_type . '_structure';

			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
			$value = isset( $_POST[ $name ] ) ? sanitize_text_field( wp_unslash( $_POST[ $name ] ) ) : '';

			/*
			 * Validated against the structures actually on offer rather than merely sanitised. The
			 * value ends up inside a rewrite rule, so an arbitrary string from the request would be
			 * an arbitrary string in the site's routing; an allow-list of keys this screen just
			 * generated cannot be talked into anything else.
			 */
			if ( $value && array_key_exists( $value, $this->get_available_structures( $post_type ) ) ) {
				$settings['structures'][ $post_type ] = $value;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
			if ( isset( $_POST[ HPPL_OPTION_PREFIX . $post_type . '_nested' ] ) ) {
				$settings['nested'][ $post_type ] = true;
			}
		}

		foreach ( $checkboxes as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
			$settings[ $key ] = isset( $_POST[ HPPL_OPTION_PREFIX . $key ] );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
		$slug = isset( $_POST[ HPPL_OPTION_PREFIX . 'blog_prefix_slug' ] ) ? sanitize_title( wp_unslash( $_POST[ HPPL_OPTION_PREFIX . 'blog_prefix_slug' ] ) ) : '';

		$settings['blog_prefix_slug'] = $slug ? $slug : 'blog';

		$this->update_settings( $settings );

		// Re-amend with the new values so the flush later in this request stores the right rules
		// straight away, rather than a request later.
		$this->amend_permalink_structures();
	}

	/**
	 * Renders the section description.
	 *
	 * @return void
	 */
	public function render_settings_section() {
		echo '<input type="hidden" name="' . esc_attr( HPPL_OPTION_PREFIX ) . 'settings" value="1" />';

		echo '<p>' . esc_html__( 'Add the category or region to HivePress web addresses to help with SEO. Old addresses keep working: visitors and search engines are sent to the new address automatically, including after you rename something.', 'permalinks-for-hivepress' ) . '</p>';

		if ( ! get_option( 'permalink_structure' ) ) {
			echo '<p>' . esc_html__( 'These options need pretty permalinks, so choose any permalink structure other than "Plain" above first.', 'permalinks-for-hivepress' ) . '</p>';
		}

		if ( ! $this->get_supported_types() ) {
			echo '<p>' . esc_html__( 'No HivePress content types with categories were found on this site yet.', 'permalinks-for-hivepress' ) . '</p>';
		}
	}

	/**
	 * Renders one field on the Permalinks page.
	 *
	 * @param array<string, mixed> $args Field arguments.
	 * @return void
	 */
	public function render_settings_field( $args ) {
		$name = $args['name'];

		if ( 'select' === $args['type'] ) {
			$current = (string) $args['current'];

			// A stored structure that is no longer on offer, because an extension was switched off,
			// falls back to the default rather than showing a value the site is not using.
			if ( ! array_key_exists( $current, $args['options'] ) ) {
				$current = '';
			}

			echo '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( $name ) . '">';

			foreach ( $args['options'] as $value => $label ) {
				echo '<option value="' . esc_attr( $value ) . '"' . selected( $current, $value, false ) . '>' . esc_html( $label ) . '</option>';
			}

			echo '</select>';
		} else {
			$this->render_checkbox( $name, (string) $args['caption'], (bool) $args['current'] );
		}

		if ( ! empty( $args['description'] ) ) {
			echo '<p class="description">' . wp_kses( $args['description'], [ 'code' => [] ] ) . '</p>';
		}

		// A select can carry its own follow-up checkbox, so the two controls that belong to one
		// object type stay in one row rather than being split across the table.
		if ( ! empty( $args['nested'] ) ) {
			echo '<p style="margin-top:.75em;">';

			$this->render_checkbox( $args['nested']['name'], (string) $args['nested']['caption'], (bool) $args['nested']['current'] );

			echo '</p>';

			echo '<p class="description">' . esc_html( $args['nested']['description'] ) . '</p>';
		}

		// A checkbox can carry a text field, for the one option that needs a word as well as a yes.
		if ( ! empty( $args['text'] ) ) {
			$text = $args['text'];

			echo '<p style="margin-top:.75em;"><label for="' . esc_attr( $text['name'] ) . '">' . esc_html( $text['label'] ) . ' </label>';

			echo '<input name="' . esc_attr( $text['name'] ) . '" id="' . esc_attr( $text['name'] ) . '" type="text" class="regular-text code" value="' . esc_attr( $text['current'] ) . '" />';

			echo '</p>';

			echo '<p class="description">' . esc_html( $text['description'] ) . '</p>';
		}
	}

	/**
	 * Renders one checkbox with its caption.
	 *
	 * @param string $name Field name.
	 * @param string $caption Caption.
	 * @param bool   $current Whether it is ticked.
	 * @return void
	 */
	protected function render_checkbox( $name, $caption, $current ) {
		echo '<label for="' . esc_attr( $name ) . '"><input name="' . esc_attr( $name ) . '" id="' . esc_attr( $name ) . '" type="checkbox" value="1"';

		checked( $current );

		echo ' /> ' . esc_html( $caption ) . '</label>';
	}
}
