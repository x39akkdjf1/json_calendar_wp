<?php
/**
 * Plugin Name: JSON Calendar
 * Description: Fetches calendar entries from a JSON endpoint and displays them with the [json_calendar] shortcode.
 * Version: 1.9.0
 * Author: x39akkdjf1
 * License: GPL-2.0-or-later
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class JSON_Calendar_WP {
	const OPTION_ENDPOINT = 'json_calendar_wp_endpoint';
	const OPTION_NEXT_HEADING = 'json_calendar_wp_next_heading';
	const SHORTCODE = 'json_calendar';
	const CACHE_VERSION = '12';
	const QUERY_VAR = 'json_calendar_event';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'init', array( $this, 'register_event_route' ) );
		add_filter( 'query_vars', array( $this, 'add_event_query_var' ) );
		add_action( 'template_redirect', array( $this, 'prepare_event_template' ), 1 );
		add_filter( 'template_include', array( $this, 'load_event_template' ), 999 );
		add_filter( 'redirect_canonical', array( $this, 'disable_event_canonical_redirect' ), 10, 2 );
		add_filter( 'body_class', array( $this, 'add_event_body_class' ) );
		add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );
	}

	public static function activate() {
		$plugin = new self();
		$plugin->register_event_route();
		flush_rewrite_rules();
	}

	public static function deactivate() {
		flush_rewrite_rules();
	}

	public function add_settings_page() {
		add_options_page( __( 'JSON Calendar', 'json-calendar-wp' ), __( 'JSON Calendar', 'json-calendar-wp' ), 'manage_options', 'json-calendar-wp', array( $this, 'render_settings_page' ) );
	}

	public function register_settings() {
		register_setting( 'json_calendar_wp_settings', self::OPTION_ENDPOINT, array( 'type' => 'string', 'sanitize_callback' => 'esc_url_raw', 'default' => '' ) );
		register_setting( 'json_calendar_wp_settings', self::OPTION_NEXT_HEADING, array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => __( 'Next up', 'json-calendar-wp' ) ) );
	}

	public function register_event_route() {
		add_rewrite_tag( '%' . self::QUERY_VAR . '%', '([^&]+)' );
		add_rewrite_rule( '^json-calendar-event/([^/]+)/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
	}

	public function add_event_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	public function disable_event_canonical_redirect( $redirect, $requested_url ) {
		return get_query_var( self::QUERY_VAR ) ? false : $redirect;
	}

	public function add_event_body_class( $classes ) {
		if ( get_query_var( self::QUERY_VAR ) ) {
			$classes[] = 'json-calendar-event-page';
		}
		return $classes;
	}

	public function load_event_template( $template ) {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return $template;
		}

		$theme_template = locate_template( array( 'single-post.php', 'single.php', 'index.php' ), false, false );
		return $theme_template ? $theme_template : $template;
	}

	public function prepare_event_template() {
		$slug = get_query_var( self::QUERY_VAR );
		if ( ! $slug ) {
			return;
		}

		$entry = $this->find_event_by_slug( sanitize_title( $slug ) );
		if ( ! is_array( $entry ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			return;
		}

		$event_post = $this->event_as_post( $entry );

		global $wp_query, $post;
		$post = $event_post;
		$wp_query->posts = array( $event_post );
		$wp_query->post = $event_post;
		$wp_query->post_count = 1;
		$wp_query->current_post = -1;
		$wp_query->found_posts = 1;
		$wp_query->max_num_pages = 1;
		$wp_query->queried_object = $event_post;
		$wp_query->queried_object_id = $event_post->ID;
		$wp_query->is_404 = false;
		$wp_query->is_single = true;
		$wp_query->is_singular = true;
		$wp_query->is_page = false;
		$wp_query->is_home = false;
		$wp_query->is_archive = false;
		$wp_query->is_post_type_archive = false;
		setup_postdata( $event_post );
	}

	public function render_settings_page() {
		?>
		<div class="wrap"><h1><?php echo esc_html__( 'JSON Calendar', 'json-calendar-wp' ); ?></h1>
		<p><?php echo esc_html__( 'Configure the JSON endpoint and the headline used by the next-event view.', 'json-calendar-wp' ); ?></p>
		<form method="post" action="options.php"><?php settings_fields( 'json_calendar_wp_settings' ); ?>
		<table class="form-table" role="presentation">
		<tr><th scope="row"><label for="json_calendar_wp_endpoint"><?php echo esc_html__( 'JSON endpoint URL', 'json-calendar-wp' ); ?></label></th><td><input type="url" class="regular-text" id="json_calendar_wp_endpoint" name="<?php echo esc_attr( self::OPTION_ENDPOINT ); ?>" value="<?php echo esc_attr( get_option( self::OPTION_ENDPOINT, '' ) ); ?>" required /></td></tr>
		<tr><th scope="row"><label for="json_calendar_wp_next_heading"><?php echo esc_html__( 'Next-event headline', 'json-calendar-wp' ); ?></label></th><td><input type="text" class="regular-text" id="json_calendar_wp_next_heading" name="<?php echo esc_attr( self::OPTION_NEXT_HEADING ); ?>" value="<?php echo esc_attr( get_option( self::OPTION_NEXT_HEADING, __( 'Next up', 'json-calendar-wp' ) ) ); ?>" /></td></tr>
		</table><?php submit_button(); ?></form></div>
		<?php
	}

	public function render_shortcode( $atts ) {
		$atts = shortcode_atts( array( 'url' => get_option( self::OPTION_ENDPOINT, '' ), 'limit' => 0, 'next' => 'false', 'archive' => 'false', 'mode' => '' ), $atts, self::SHORTCODE );
		$data = $this->fetch_data( $atts['url'] );
		if ( is_wp_error( $data ) ) return current_user_can( 'manage_options' ) ? '<p class="json-calendar-error">' . esc_html( $data->get_error_message() ) . '</p>' : '';

		$mode = strtolower( (string) $atts['mode'] );
		$is_next = in_array( strtolower( (string) $atts['next'] ), array( 'true', '1', 'yes' ), true ) || 'next' === $mode;
		$is_archive = in_array( strtolower( (string) $atts['archive'] ), array( 'true', '1', 'yes' ), true ) || 'archive' === $mode;
		$entries = $this->get_entries( $data );
		$entries = $is_archive ? $this->filter_past( $entries ) : $this->filter_upcoming( $entries );
		if ( $is_next ) $entries = array_slice( $entries, 0, 1 );
		if ( ! $entries ) return '<p class="json-calendar-empty">' . esc_html( $is_archive ? __( 'No past calendar entries found.', 'json-calendar-wp' ) : __( 'No upcoming calendar entries found.', 'json-calendar-wp' ) ) . '</p>';
		$limit = absint( $atts['limit'] );
		if ( $limit && ! $is_next ) $entries = array_slice( $entries, 0, $limit );

		$id = esc_attr( wp_unique_id( 'json-calendar-' ) );
		$output = '<style>#' . $id . ' .json-calendar-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:1rem;list-style:none;margin:0;padding:0}#' . $id . ' .json-calendar-entry{position:relative;min-width:0}#' . $id . ' .json-calendar-card{position:relative;width:100%;background:#fff;overflow:hidden}#' . $id . ' .json-calendar-image{display:block;width:100%;height:auto}#' . $id . ' .json-calendar-details{padding:1.25rem;background:#fff;color:#000}#' . $id . ' .json-calendar-title{margin:0 0 .7rem;font-size:1.35rem;line-height:1.2}#' . $id . ' .json-calendar-date,#' . $id . ' .json-calendar-time{margin:.25rem 0;color:#333;font-family:Arial,Helvetica,sans-serif;font-style:italic;font-size:.85rem}#' . $id . ' .json-calendar-description{margin:.8rem 0;line-height:1.45}#' . $id . '.json-calendar-next .json-calendar-list{display:block}#' . $id . '.json-calendar-next .json-calendar-entry{width:100%;max-width:1700px;margin:0 auto}#' . $id . '.json-calendar-next .json-calendar-card{width:100%;height:clamp(500px,41.176vw,700px);min-height:500px;max-height:700px;background:#111}#' . $id . '.json-calendar-next .json-calendar-image{display:block;width:100%;height:100%;opacity:.8;object-fit:cover;object-position:center}#' . $id . '.json-calendar-next .json-calendar-details{position:absolute;right:4%;bottom:4%;left:4%;padding:0;background:transparent;color:#fff;text-align:right}#' . $id . '.json-calendar-next .wp-block-cover__inner-container{box-sizing:border-box!important;width:100%!important;max-width:none!important;margin:0!important;padding:0!important;display:flex;flex-direction:column;align-items:flex-end;justify-content:flex-end;color:#fff;text-align:right}#' . $id . '.json-calendar-next .wp-block-cover__inner-container>*{max-width:none!important;margin-left:0!important;margin-right:0!important;text-align:right}#' . $id . '.json-calendar-next .json-calendar-next-heading,#' . $id . '.json-calendar-next .json-calendar-title,#' . $id . '.json-calendar-next .json-calendar-more{text-shadow:none;text-align:right}#' . $id . '.json-calendar-next .json-calendar-next-heading{display:block;margin:0;color:#fff;font-size:clamp(1.2rem,2.4vw,2.4rem);font-weight:400;line-height:1.1}#' . $id . '.json-calendar-next .json-calendar-title{margin:.15rem 0 0;color:#fff;font-size:clamp(1.5rem,3.2vw,3.5rem);font-weight:800;line-height:.98;letter-spacing:-.035em;text-decoration:none}#' . $id . '.json-calendar-next .json-calendar-more{display:block;margin:.3rem 0 0;color:#fff;font-size:clamp(.9rem,1.4vw,1.2rem);font-weight:400;line-height:1.1;text-decoration:none}#' . $id . '.json-calendar-archive .json-calendar-list{grid-template-columns:repeat(auto-fill,minmax(220px,1fr))}@media (max-width:700px){#' . $id . '.json-calendar-next .json-calendar-card{height:700px;min-height:700px;max-height:700px}}</style><div id="' . $id . '" class="json-calendar' . ( $is_next ? ' json-calendar-next' : ( $is_archive ? ' json-calendar-archive' : '' ) ) . '"><ul class="json-calendar-list">';

		foreach ( $entries as $entry ) {
			$title = $this->value( $entry, array( 'title', 'name', 'summary' ), __( 'Untitled event', 'json-calendar-wp' ) );
			$image = $this->first_image( $entry );
			$output .= '<li class="json-calendar-entry"><div class="json-calendar-card">';
			if ( $is_next ) {
				if ( $image ) $output .= '<img class="json-calendar-image" src="' . esc_url( $image ) . '" alt="' . esc_attr( $title ) . '" loading="lazy" />';
				$output .= '<div class="json-calendar-details"><div class="wp-block-cover__inner-container"><span class="json-calendar-next-heading">' . esc_html( get_option( self::OPTION_NEXT_HEADING, __( 'Next up', 'json-calendar-wp' ) ) ) . '</span><h1 class="json-calendar-title">' . esc_html( $title ) . '</h1><a class="json-calendar-more" href="' . esc_url( $this->get_event_url( $entry ) ) . '">' . esc_html__( 'Mehr', 'json-calendar-wp' ) . '</a></div></div>';
			} else {
				if ( $image ) $output .= '<img class="json-calendar-image" src="' . esc_url( $image ) . '" alt="' . esc_attr( $title ) . '" loading="lazy" />';
				$output .= '<div class="json-calendar-details"><h2 class="json-calendar-title">' . esc_html( $title ) . '</h2>';
				$date = $this->value( $entry, array( 'date', 'start_date', 'start', 'datetime' ) ); $end = $this->value( $entry, array( 'date_end', 'end_date', 'end' ) ); $time_start = $this->value( $entry, array( 'time_start' ) ); $time_end = $this->value( $entry, array( 'time_end' ) ); $description = $this->value( $entry, array( 'description', 'details', 'content' ) );
				if ( $date ) $output .= '<div class="json-calendar-date">' . esc_html( $this->format_date_only( $date ) . ( $end && $end !== $date ? ' – ' . $this->format_date_only( $end ) : '' ) ) . '</div>';
				if ( $time_start || $time_end ) $output .= '<div class="json-calendar-time">' . esc_html( $time_start . ( $time_end ? ' – ' . $time_end : '' ) ) . '</div>';
				if ( $description ) $output .= '<p class="json-calendar-description">' . wp_kses_post( $description ) . '</p>';
				$output .= '</div>';
			}
			$output .= '</div></li>';
		}
		return $output . '</ul></div>';
	}

	private function fetch_data( $url ) {
		$url = esc_url_raw( $url );
		if ( ! $url || ! wp_http_validate_url( $url ) ) return new WP_Error( 'invalid_endpoint', __( 'Configure a valid JSON endpoint under Settings → JSON Calendar.', 'json-calendar-wp' ) );
		$key = 'json_calendar_' . self::CACHE_VERSION . '_' . md5( $url );
		$data = get_transient( $key );
		if ( false !== $data ) return $data;
		$response = wp_safe_remote_get( $url, array( 'timeout' => 10, 'headers' => array( 'Accept' => 'application/json' ), 'user-agent' => 'JSON Calendar WordPress Plugin/1.9.0' ) );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) return new WP_Error( 'endpoint_unavailable', __( 'Calendar entries are temporarily unavailable.', 'json-calendar-wp' ) );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( JSON_ERROR_NONE !== json_last_error() ) return new WP_Error( 'invalid_json', __( 'The calendar endpoint returned invalid JSON.', 'json-calendar-wp' ) );
		set_transient( $key, $data, 15 * MINUTE_IN_SECONDS );
		return $data;
	}

	private function get_entries( $data ) {
		if ( ! is_array( $data ) ) return array();
		if ( isset( $data['entries'] ) && is_array( $data['entries'] ) ) return $this->get_entries( $data['entries'] );
		if ( isset( $data['events'] ) && is_array( $data['events'] ) ) return $this->get_entries( $data['events'] );
		if ( $this->is_event( $data ) ) return array( $data );
		$entries = array();
		foreach ( $data as $reference => $value ) if ( is_array( $value ) ) foreach ( $this->get_entries( $value ) as $entry ) { if ( empty( $entry['reference'] ) ) $entry['reference'] = (string) $reference; $entries[] = $entry; }
		return $entries;
	}

	private function is_event( $value ) { return is_array( $value ) && ( isset( $value['title'] ) || isset( $value['date'] ) || isset( $value['reference'] ) ); }
	private function today() { return strtotime( wp_date( 'Y-m-d', current_time( 'timestamp' ) ) ); }
	private function timestamp( $entry, $keys ) { $value = $this->value( $entry, $keys ); return $value ? strtotime( $value ) : false; }
	private function filter_upcoming( $entries ) { $today = $this->today(); $result = array_filter( $entries, function( $entry ) use ( $today ) { $start = $this->timestamp( $entry, array( 'date', 'start_date', 'start', 'datetime' ) ); $end = $this->timestamp( $entry, array( 'date_end', 'end_date', 'end' ) ); return ( false !== $start && $start >= $today ) || ( false !== $end && $end >= $today ); } ); return $this->sort_entries( $result ); }
	private function filter_past( $entries ) { $today = $this->today(); $result = array_filter( $entries, function( $entry ) use ( $today ) { $end = $this->timestamp( $entry, array( 'date_end', 'end_date', 'end' ) ); $start = $this->timestamp( $entry, array( 'date', 'start_date', 'start', 'datetime' ) ); return false !== $end ? $end < $today : ( false !== $start && $start < $today ); } ); return $this->sort_entries( $result, true ); }
	private function sort_entries( $entries, $reverse = false ) { usort( $entries, function( $a, $b ) use ( $reverse ) { $x = $this->timestamp( $a, array( 'date', 'start_date', 'start', 'datetime', 'date_end', 'end_date', 'end' ) ); $y = $this->timestamp( $b, array( 'date', 'start_date', 'start', 'datetime', 'date_end', 'end_date', 'end' ) ); $r = ( false === $x || false === $y ) ? 0 : ( $x <=> $y ); return $reverse ? -$r : $r; } ); return array_values( $entries ); }
	private function first_image( $entry ) { $image = isset( $entry['image'] ) ? $entry['image'] : ''; if ( is_array( $image ) ) $image = reset( $image ); if ( ! is_scalar( $image ) ) return ''; $image = trim( (string) $image ); if ( $image && ! preg_match( '#^https?://#i', $image ) ) $image = 'https://' . $image; return $image && wp_http_validate_url( $image ) ? $image : ''; }
	private function value( $entry, $keys, $default = '' ) { foreach ( $keys as $key ) if ( isset( $entry[ $key ] ) && is_scalar( $entry[ $key ] ) && '' !== (string) $entry[ $key ] ) return (string) $entry[ $key ]; return $default; }
	private function format_date_only( $date ) { $timestamp = strtotime( $date ); return $timestamp ? wp_date( get_option( 'date_format' ), $timestamp ) : $date; }

	private function get_event_slug( $entry ) {
		$reference = $this->value( $entry, array( 'reference', 'id', 'slug', 'uid' ) );
		if ( $reference ) return sanitize_title( $reference );
		$title = $this->value( $entry, array( 'title', 'name', 'summary' ), 'event' );
		$date = $this->value( $entry, array( 'date', 'start_date', 'start', 'datetime' ) );
		$seed = trim( $title . ' ' . $date );
		if ( '' === $seed ) $seed = md5( wp_json_encode( $entry ) );
		return sanitize_title( $seed );
	}

	private function get_event_url( $entry ) {
		return home_url( '/json-calendar-event/' . rawurlencode( $this->get_event_slug( $entry ) ) . '/' );
	}

	private function find_event_by_slug( $slug ) {
		$data = $this->fetch_data( get_option( self::OPTION_ENDPOINT, '' ) );
		if ( is_wp_error( $data ) ) return null;
		foreach ( $this->get_entries( $data ) as $entry ) {
			if ( is_array( $entry ) && $slug === $this->get_event_slug( $entry ) ) return $entry;
		}
		return null;
	}

	private function event_as_post( $entry ) {
		$title = $this->value( $entry, array( 'title', 'name', 'summary' ), __( 'Untitled event', 'json-calendar-wp' ) );
		$image = $this->first_image( $entry );
		$date = $this->value( $entry, array( 'date', 'start_date', 'start', 'datetime' ) );
		$end = $this->value( $entry, array( 'date_end', 'end_date', 'end' ) );
		$time_start = $this->value( $entry, array( 'time_start' ) );
		$time_end = $this->value( $entry, array( 'time_end' ) );
		$description = $this->value( $entry, array( 'description', 'details', 'content' ) );
		$meta = $date ? '<p class="json-calendar-meta">' . esc_html( $this->format_date_only( $date ) . ( $end && $end !== $date ? ' – ' . $this->format_date_only( $end ) : '' ) . ( $time_start || $time_end ? ' · ' . trim( $time_start . ( $time_end ? ' – ' . $time_end : '' ) ) : '' ) ) . '</p>' : '';
		$content = ( $image ? '<p class="json-calendar-entry-image"><img src="' . esc_url( $image ) . '" alt="' . esc_attr( $title ) . '" /></p>' : '' ) . $meta . ( $description ? wp_kses_post( $description ) : '' );

		$post = new stdClass();
		$post->ID = 0;
		$post->post_author = 0;
		$post->post_date = current_time( 'mysql' );
		$post->post_date_gmt = current_time( 'mysql', true );
		$post->post_content = $content;
		$post->post_title = $title;
		$post->post_excerpt = wp_trim_words( wp_strip_all_tags( $description ), 55 );
		$post->post_status = 'publish';
		$post->comment_status = 'closed';
		$post->ping_status = 'closed';
		$post->post_name = $this->get_event_slug( $entry );
		$post->post_type = 'post';
		$post->filter = 'raw';
		return new WP_Post( $post );
	}
}

register_activation_hook( __FILE__, array( 'JSON_Calendar_WP', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'JSON_Calendar_WP', 'deactivate' ) );

new JSON_Calendar_WP();
