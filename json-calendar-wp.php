<?php
/**
 * Plugin Name: JSON Calendar
 * Description: Fetches calendar entries from a JSON endpoint and displays them with the [json_calendar] shortcode.
 * Version: 1.7.0
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
	const CACHE_VERSION = '10';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'init', array( $this, 'register_event_route' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render_event_page' ) );
		add_filter( 'query_vars', array( $this, 'add_event_query_var' ) );
		add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );
	}

	public function add_settings_page() {
		add_options_page( __( 'JSON Calendar', 'json-calendar-wp' ), __( 'JSON Calendar', 'json-calendar-wp' ), 'manage_options', 'json-calendar-wp', array( $this, 'render_settings_page' ) );
	}

	public function register_settings() {
		register_setting( 'json_calendar_wp_settings', self::OPTION_ENDPOINT, array( 'type' => 'string', 'sanitize_callback' => 'esc_url_raw', 'default' => '' ) );
		register_setting( 'json_calendar_wp_settings', self::OPTION_NEXT_HEADING, array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => __( 'Next up', 'json-calendar-wp' ) ) );
	}

	public function register_event_route() {
		add_rewrite_tag( '%json_calendar_event%', '([^&]+)' );
		add_rewrite_rule( '^json-calendar-event/([^/]+)/?$', 'index.php?json_calendar_event=$matches[1]', 'top' );

		if ( ! get_option( 'json_calendar_wp_rewrites_flushed', false ) ) {
			flush_rewrite_rules();
			update_option( 'json_calendar_wp_rewrites_flushed', true );
		}
	}

	public function add_event_query_var( $vars ) {
		$vars[] = 'json_calendar_event';
		return $vars;
	}

	public function maybe_render_event_page() {
		$slug = get_query_var( 'json_calendar_event' );
		if ( empty( $slug ) ) {
			return;
		}

		$entry = $this->find_event_by_slug( $slug );
		if ( ! is_array( $entry ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
			include get_404_template();
			exit;
		}

		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
		echo $this->render_event_detail_page( $entry );
		exit;
	}

	public function render_settings_page() {
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'JSON Calendar', 'json-calendar-wp' ); ?></h1>
			<p><?php echo esc_html__( 'Configure the JSON endpoint and the headline used by the next-event view.', 'json-calendar-wp' ); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields( 'json_calendar_wp_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="json_calendar_wp_endpoint"><?php echo esc_html__( 'JSON endpoint URL', 'json-calendar-wp' ); ?></label></th><td><input type="url" class="regular-text" id="json_calendar_wp_endpoint" name="<?php echo esc_attr( self::OPTION_ENDPOINT ); ?>" value="<?php echo esc_attr( get_option( self::OPTION_ENDPOINT, '' ) ); ?>" required /></td></tr>
					<tr><th scope="row"><label for="json_calendar_wp_next_heading"><?php echo esc_html__( 'Next-event headline', 'json-calendar-wp' ); ?></label></th><td><input type="text" class="regular-text" id="json_calendar_wp_next_heading" name="<?php echo esc_attr( self::OPTION_NEXT_HEADING ); ?>" value="<?php echo esc_attr( get_option( self::OPTION_NEXT_HEADING, __( 'Next up', 'json-calendar-wp' ) ) ); ?>" /></td></tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	public function render_shortcode( $atts ) {
		$atts = shortcode_atts( array( 'url' => get_option( self::OPTION_ENDPOINT, '' ), 'limit' => 0, 'next' => 'false', 'archive' => 'false', 'mode' => '' ), $atts, self::SHORTCODE );
		$url = esc_url_raw( $atts['url'] );
		if ( ! $url || ! wp_http_validate_url( $url ) ) return current_user_can( 'manage_options' ) ? '<p class="json-calendar-error">' . esc_html__( 'Configure a JSON endpoint under Settings → JSON Calendar.', 'json-calendar-wp' ) . '</p>' : '';

		$key = 'json_calendar_' . self::CACHE_VERSION . '_' . md5( $url );
		$data = get_transient( $key );
		if ( false === $data ) {
			$response = wp_safe_remote_get( $url, array( 'timeout' => 10, 'headers' => array( 'Accept' => 'application/json' ), 'user-agent' => 'JSON Calendar WordPress Plugin/1.7.0' ) );
			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) return '<p class="json-calendar-error">' . esc_html__( 'Calendar entries are temporarily unavailable.', 'json-calendar-wp' ) . '</p>';
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( JSON_ERROR_NONE !== json_last_error() ) return '<p class="json-calendar-error">' . esc_html__( 'The calendar endpoint returned invalid JSON.', 'json-calendar-wp' ) . '</p>';
			set_transient( $key, $data, 15 * MINUTE_IN_SECONDS );
		}

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
		$output = '<style>#' . $id . ' .json-calendar-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:1rem;list-style:none;margin:0;padding:0}#' . $id . ' .json-calendar-entry{position:relative;min-width:0}#' . $id . ' .json-calendar-card{position:relative;width:100%;background:#fff;overflow:hidden}#' . $id . ' .json-calendar-image{display:block;width:100%;height:auto}#' . $id . ' .json-calendar-details{padding:1.25rem;background:#fff;color:#000}#' . $id . ' .json-calendar-title{margin:0 0 .7rem;font-size:1.35rem;line-height:1.2}#' . $id . ' .json-calendar-date,#' . $id . ' .json-calendar-time{margin:.25rem 0;color:#333;font-family:Arial,Helvetica,sans-serif;font-style:italic;font-size:.85rem}#' . $id . ' .json-calendar-description{margin:.8rem 0;line-height:1.45}#' . $id . '.json-calendar-next .json-calendar-list{display:block}#' . $id . '.json-calendar-next .json-calendar-entry{width:100%;max-width:1700px;margin:0 auto}#' . $id . '.json-calendar-next .json-calendar-card{width:100%;height:clamp(500px,41.176vw,700px);min-height:500px;max-height:700px;background:#111}#' . $id . '.json-calendar-next .json-calendar-image{display:block;width:100%;height:100%;opacity:.8;object-fit:cover;object-position:center}#' . $id . '.json-calendar-next .json-calendar-details{position:absolute;right:4%;bottom:4%;left:4%;padding:0;background:transparent;color:#fff;text-align:right}#' . $id . '.json-calendar-next .wp-block-cover__inner-container{box-sizing:border-box!important;width:100%!important;max-width:none!important;margin:0!important;padding:0!important;display:flex;flex-direction:column;align-items:flex-end;justify-content:flex-end;color:#fff;text-align:right}#' . $id . '.json-calendar-next .wp-block-cover__inner-container>*{max-width:none!important;margin-left:0!important;margin-right:0!important;text-align:right}#' . $id . '.json-calendar-next .json-calendar-next-heading,#' . $id . '.json-calendar-next .json-calendar-title,#' . $id . '.json-calendar-next .json-calendar-more{text-shadow:none;text-align:right}#' . $id . '.json-calendar-next .json-calendar-next-heading{display:block;margin:0;color:#fff;font-size:clamp(1.2rem,2.4vw,2.4rem);font-weight:400;line-height:1.1;text-transform:none}#' . $id . '.json-calendar-next .json-calendar-title{margin:.15rem 0 0;color:#fff;font-size:clamp(1.5rem,3.2vw,3.5rem);font-weight:800;line-height:.98;letter-spacing:-.035em;text-decoration:none}#' . $id . '.json-calendar-next .json-calendar-more{display:block;margin:.3rem 0 0;color:#fff;font-size:clamp(.9rem,1.4vw,1.2rem);font-weight:400;line-height:1.1;text-decoration:none}#' . $id . '.json-calendar-next .json-calendar-more:hover,#' . $id . '.json-calendar-next .json-calendar-more:focus{opacity:.8}#' . $id . '.json-calendar-archive .json-calendar-list{grid-template-columns:repeat(auto-fill,minmax(220px,1fr))}@media (max-width:700px){#' . $id . '.json-calendar-next .json-calendar-card{height:700px;min-height:700px;max-height:700px}}@media (min-width:1700px){#' . $id . '.json-calendar-next .json-calendar-card{height:700px}}</style><div id="' . $id . '" class="json-calendar' . ( $is_next ? ' json-calendar-next' : ( $is_archive ? ' json-calendar-archive' : '' ) ) . '"><ul class="json-calendar-list">';

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) continue;
			$title = $this->value( $entry, array( 'title', 'name', 'summary' ), __( 'Untitled event', 'json-calendar-wp' ) );
			$image = $this->first_image( $entry );
			$output .= '<li class="json-calendar-entry"><div class="json-calendar-card">';
			if ( $is_next ) {
				if ( $image ) $output .= '<img class="json-calendar-image" src="' . esc_url( $image ) . '" alt="' . esc_attr( $title ) . '" loading="lazy" />';
				$event_url = esc_url( $this->get_event_url( $entry ) );
				$output .= '<div class="json-calendar-details"><div class="wp-block-cover__inner-container has-global-padding is-layout-constrained wp-block-cover-is-layout-constrained"><span class="json-calendar-next-heading">' . esc_html( get_option( self::OPTION_NEXT_HEADING, __( 'Next up', 'json-calendar-wp' ) ) ) . '</span><h1 class="json-calendar-title">' . esc_html( $title ) . '</h1><a class="json-calendar-more" href="' . $event_url . '">' . esc_html__( 'Mehr', 'json-calendar-wp' ) . '</a></div></div>';
			} else {
				if ( $image ) $output .= '<img class="json-calendar-image" src="' . esc_url( $image ) . '" alt="' . esc_attr( $title ) . '" loading="lazy" />';
				$output .= '<div class="json-calendar-details"><h2 class="json-calendar-title">' . esc_html( $title ) . '</h2>';
				$date = $this->value( $entry, array( 'date', 'start_date', 'start', 'datetime' ) ); $date_end = $this->value( $entry, array( 'date_end', 'end_date', 'end' ) ); $time_start = $this->value( $entry, array( 'time_start' ) ); $time_end = $this->value( $entry, array( 'time_end' ) ); $description = $this->value( $entry, array( 'description', 'details', 'content' ) );
				if ( $date ) { $output .= '<div class="json-calendar-date">' . esc_html( $this->format_date_only( $date ) ); if ( $date_end && $date_end !== $date ) $output .= ' – ' . esc_html( $this->format_date_only( $date_end ) ); $output .= '</div>'; }
				if ( $time_start || $time_end ) $output .= '<div class="json-calendar-time">' . esc_html( $time_start . ( $time_end ? ' – ' . $time_end : '' ) ) . '</div>';
				if ( $description ) $output .= '<p class="json-calendar-description">' . wp_kses_post( $description ) . '</p>';
				$output .= '</div>';
			}
			$output .= '</div></li>';
		}
		return $output . '</ul></div>';
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
	private function filter_upcoming( $entries ) { $today = $this->today(); $result = array_filter( $entries, function( $entry ) use ( $today ) { $start = $this->timestamp( $entry, array( 'date', 'start_date', 'start', 'datetime' ) ); $end = $this->timestamp( $entry, array( 'date_end', 'end_date', 'end' ) ); return ( false !== $start && $start >= $today ) || ( false !== $end && $end >= $today ); } ); return $this->sort_entries( $result ); }
	private function filter_past( $entries ) { $today = $this->today(); $result = array_filter( $entries, function( $entry ) use ( $today ) { $end = $this->timestamp( $entry, array( 'date_end', 'end_date', 'end' ) ); $start = $this->timestamp( $entry, array( 'date', 'start_date', 'start', 'datetime' ) ); return false !== $end ? $end < $today : ( false !== $start && $start < $today ); } ); return $this->sort_entries( $result, true ); }
	private function sort_entries( $entries, $reverse = false ) { usort( $entries, function( $a, $b ) use ( $reverse ) { $x = $this->timestamp( $a, array( 'date', 'start_date', 'start', 'datetime', 'date_end', 'end_date', 'end' ) ); $y = $this->timestamp( $b, array( 'date', 'start_date', 'start', 'datetime', 'date_end', 'end_date', 'end' ) ); $r = ( false === $x || false === $y ) ? 0 : ( $x <=> $y ); return $reverse ? -$r : $r; } ); return array_values( $entries ); }
	private function timestamp( $entry, $keys ) { $value = $this->value( $entry, $keys ); return $value ? strtotime( $value ) : false; }
	private function first_image( $entry ) { $image = isset( $entry['image'] ) ? $entry['image'] : ''; if ( is_array( $image ) ) $image = reset( $image ); if ( ! is_scalar( $image ) ) return ''; $image = trim( (string) $image ); if ( $image && ! preg_match( '#^https?://#i', $image ) ) $image = 'https://' . $image; return $image && wp_http_validate_url( $image ) ? $image : ''; }
	private function value( $entry, $keys, $default = '' ) { foreach ( $keys as $key ) if ( isset( $entry[ $key ] ) && is_scalar( $entry[ $key ] ) && '' !== (string) $entry[ $key ] ) return (string) $entry[ $key ]; return $default; }
	private function format_date_only( $date ) { $timestamp = strtotime( $date ); return $timestamp ? wp_date( get_option( 'date_format' ), $timestamp ) : $date; }

	private function get_event_url( $entry ) {
		$slug = $this->get_event_slug( $entry );
		return home_url( '/json-calendar-event/' . rawurlencode( $slug ) . '/' );
	}

	private function get_event_slug( $entry ) {
		$reference = $this->value( $entry, array( 'reference', 'id', 'slug', 'uid' ) );
		if ( $reference ) {
			return sanitize_title( (string) $reference );
		}

		$title = $this->value( $entry, array( 'title', 'name', 'summary' ), 'event' );
		$date = $this->value( $entry, array( 'date', 'start_date', 'start', 'datetime' ) );
		$seed = trim( $title . ' ' . $date );
		if ( '' === $seed ) {
			$seed = md5( wp_json_encode( $entry ) );
		}

		return sanitize_title( $seed );
	}

	private function find_event_by_slug( $slug ) {
		$url = get_option( self::OPTION_ENDPOINT, '' );
		if ( empty( $url ) || ! wp_http_validate_url( $url ) ) {
			return null;
		}

		$cache_key = 'json_calendar_' . self::CACHE_VERSION . '_' . md5( $url );
		$data = get_transient( $cache_key );
		if ( false === $data ) {
			$response = wp_safe_remote_get( $url, array( 'timeout' => 10, 'headers' => array( 'Accept' => 'application/json' ), 'user-agent' => 'JSON Calendar WordPress Plugin/1.7.0' ) );
			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
				return null;
			}
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				return null;
			}
			set_transient( $cache_key, $data, 15 * MINUTE_IN_SECONDS );
		}

		foreach ( $this->get_entries( $data ) as $entry ) {
			if ( is_array( $entry ) && $slug === $this->get_event_slug( $entry ) ) {
				return $entry;
			}
		}

		return null;
	}

	private function render_event_detail_page( $entry ) {
		$title = $this->value( $entry, array( 'title', 'name', 'summary' ), __( 'Untitled event', 'json-calendar-wp' ) );
		$description = $this->value( $entry, array( 'description', 'details', 'content' ) );
		$date = $this->value( $entry, array( 'date', 'start_date', 'start', 'datetime' ) );
		$date_end = $this->value( $entry, array( 'date_end', 'end_date', 'end' ) );
		$time_start = $this->value( $entry, array( 'time_start' ) );
		$time_end = $this->value( $entry, array( 'time_end' ) );
		$image = $this->first_image( $entry );

		$meta = array();
		if ( $date ) {
			$meta[] = esc_html( $this->format_date_only( $date ) );
			if ( $date_end && $date_end !== $date ) {
				$meta[] = esc_html( '– ' . $this->format_date_only( $date_end ) );
			}
		}
		if ( $time_start || $time_end ) {
			$meta[] = esc_html( trim( $time_start . ( $time_end ? ' – ' . $time_end : '' ) ) );
		}
		$meta_html = $meta ? '<p class="json-calendar-meta">' . implode( ' · ', $meta ) . '</p>' : '';
		$description_html = $description ? '<div class="json-calendar-description">' . wp_kses_post( $description ) . '</div>' : '';
		$image_html = $image ? '<img class="json-calendar-event-image" src="' . esc_url( $image ) . '" alt="' . esc_attr( $title ) . '" />' : '';

		return '<!doctype html><html ' . language_attributes() . '><head><meta charset="' . esc_attr( get_option( 'blog_charset' ) ) . '" /><meta name="viewport" content="width=device-width, initial-scale=1" /><title>' . esc_html( $title ) . '</title>' . wp_head() . '<style>body{margin:0;padding:0;background:#f5f5f5;color:#111;font-family:Arial,Helvetica,sans-serif}.json-calendar-event-page{max-width:1100px;margin:0 auto;padding:48px 24px 72px}.json-calendar-event-page .json-calendar-event-card{background:#fff;overflow:hidden;border-radius:12px;box-shadow:0 12px 32px rgba(0,0,0,.08)}.json-calendar-event-page .json-calendar-event-image{display:block;width:100%;height:auto;max-height:560px;object-fit:cover}.json-calendar-event-page .json-calendar-event-content{padding:32px 28px 40px}.json-calendar-event-page h1{margin:0 0 12px;font-size:clamp(2rem,4vw,4rem);line-height:1.06;letter-spacing:-.04em}.json-calendar-event-page .json-calendar-meta{margin:0 0 20px;color:#444;font-size:1rem;line-height:1.5}.json-calendar-event-page .json-calendar-description{font-size:1.05rem;line-height:1.7;color:#1d1d1d}.json-calendar-event-page .json-calendar-description p{margin:0 0 1em}.json-calendar-event-page .json-calendar-description p:last-child{margin-bottom:0}</style></head><body>' . wp_body_open( '' ) . '<main class="json-calendar-event-page"><article class="json-calendar-event-card">' . $image_html . '<div class="json-calendar-event-content"><h1>' . esc_html( $title ) . '</h1>' . $meta_html . $description_html . '</div></article></main>' . wp_footer() . '</body></html>';
	}
}

new JSON_Calendar_WP();
