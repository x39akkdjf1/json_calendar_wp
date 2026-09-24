<?php
/**
 * Plugin Name: JSON Calendar
 * Description: Fetches calendar entries from a JSON endpoint and displays them with the [json_calendar] shortcode.
 * Version: 1.5.1
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
	const CACHE_VERSION = '5';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );
	}

	public function add_settings_page() {
		add_options_page( __( 'JSON Calendar', 'json-calendar-wp' ), __( 'JSON Calendar', 'json-calendar-wp' ), 'manage_options', 'json-calendar-wp', array( $this, 'render_settings_page' ) );
	}

	public function register_settings() {
		register_setting( 'json_calendar_wp_settings', self::OPTION_ENDPOINT, array( 'type' => 'string', 'sanitize_callback' => 'esc_url_raw', 'default' => '' ) );
		register_setting( 'json_calendar_wp_settings', self::OPTION_NEXT_HEADING, array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => __( 'Next up', 'json-calendar-wp' ) ) );
	}

	public function render_settings_page() {
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'JSON Calendar', 'json-calendar-wp' ); ?></h1>
			<p><?php echo esc_html__( 'Configure the JSON endpoint and the heading used by the next-event shortcode.', 'json-calendar-wp' ); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields( 'json_calendar_wp_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="json_calendar_wp_endpoint"><?php echo esc_html__( 'JSON endpoint URL', 'json-calendar-wp' ); ?></label></th><td><input type="url" class="regular-text" id="json_calendar_wp_endpoint" name="<?php echo esc_attr( self::OPTION_ENDPOINT ); ?>" value="<?php echo esc_attr( get_option( self::OPTION_ENDPOINT, '' ) ); ?>" placeholder="https://example.com/kalender/199.json" required /></td></tr>
					<tr><th scope="row"><label for="json_calendar_wp_next_heading"><?php echo esc_html__( 'Next-event headline', 'json-calendar-wp' ); ?></label></th><td><input type="text" class="regular-text" id="json_calendar_wp_next_heading" name="<?php echo esc_attr( self::OPTION_NEXT_HEADING ); ?>" value="<?php echo esc_attr( get_option( self::OPTION_NEXT_HEADING, __( 'Next up', 'json-calendar-wp' ) ) ); ?>" /><p class="description"><?php echo esc_html__( 'Shown overlapping the left side when using [json_calendar next="true"].', 'json-calendar-wp' ); ?></p></td></tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	public function render_shortcode( $atts ) {
		$atts = shortcode_atts( array( 'url' => get_option( self::OPTION_ENDPOINT, '' ), 'limit' => 0, 'next' => 'false', 'archive' => 'false', 'mode' => '' ), $atts, self::SHORTCODE );
		$url = esc_url_raw( $atts['url'] );
		if ( empty( $url ) || ! wp_http_validate_url( $url ) ) return current_user_can( 'manage_options' ) ? '<p class="json-calendar-error">' . esc_html__( 'Configure a JSON endpoint under Settings → JSON Calendar.', 'json-calendar-wp' ) . '</p>' : '';

		$cache_key = 'json_calendar_' . self::CACHE_VERSION . '_' . md5( $url );
		$data = get_transient( $cache_key );
		if ( false === $data ) {
			$response = wp_safe_remote_get( $url, array( 'timeout' => 10, 'headers' => array( 'Accept' => 'application/json' ), 'user-agent' => 'JSON Calendar WordPress Plugin/1.5.1' ) );
			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) return '<p class="json-calendar-error">' . esc_html__( 'Calendar entries are temporarily unavailable.', 'json-calendar-wp' ) . '</p>';
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( JSON_ERROR_NONE !== json_last_error() ) return '<p class="json-calendar-error">' . esc_html__( 'The calendar endpoint returned invalid JSON.', 'json-calendar-wp' ) . '</p>';
			set_transient( $cache_key, $data, 15 * MINUTE_IN_SECONDS );
		}

		$mode = strtolower( (string) $atts['mode'] );
		$is_next = ( 'true' === strtolower( (string) $atts['next'] ) || '1' === (string) $atts['next'] || 'next' === $mode );
		$is_archive = ( 'true' === strtolower( (string) $atts['archive'] ) || '1' === (string) $atts['archive'] || 'archive' === $mode );
		$entries = $is_archive ? $this->filter_past_entries( $this->get_entries( $data ) ) : $this->filter_future_entries( $this->get_entries( $data ) );
		if ( $is_next ) $entries = array_slice( $entries, 0, 1 );
		if ( empty( $entries ) ) return '<p class="json-calendar-empty">' . esc_html( $is_archive ? __( 'No past calendar entries found.', 'json-calendar-wp' ) : __( 'No upcoming calendar entries found.', 'json-calendar-wp' ) ) . '</p>';

		$limit = absint( $atts['limit'] );
		if ( $limit > 0 && ! $is_next ) $entries = array_slice( $entries, 0, $limit );
		$instance = wp_unique_id( 'json-calendar-' );
		$id = esc_attr( $instance );
		$classes = $is_next ? ' json-calendar-next' : ( $is_archive ? ' json-calendar-archive' : '' );

		$output = '<style>
			#' . $id . ' .json-calendar-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:1rem;list-style:none;margin:0;padding:0}
			#' . $id . ' .json-calendar-entry{position:relative;min-width:0;outline:none}
			#' . $id . ' .json-calendar-card{position:relative;width:100%;background:#fff;overflow:hidden}
			#' . $id . ' .json-calendar-image{display:block;width:100%;height:auto;object-fit:contain}
			#' . $id . ' .json-calendar-details{padding:1.25rem;color:#000;background:#fff}
			#' . $id . ' .json-calendar-title{margin:0 0 .7rem;color:#000;font-size:1.35rem;line-height:1.2}
			#' . $id . ' .json-calendar-date,#' . $id . ' .json-calendar-time{margin:.25rem 0;color:#333;font-family:Arial,Helvetica,sans-serif;font-style:italic;font-size:.85rem}
			#' . $id . ' .json-calendar-description{margin:.8rem 0;color:#000;font-family:inherit;font-size:1rem;line-height:1.45}
			#' . $id . '.json-calendar-next .json-calendar-list{display:block}
			#' . $id . '.json-calendar-next .json-calendar-entry{width:100%;max-width:none}
			#' . $id . '.json-calendar-next .json-calendar-card{background:#111}
			#' . $id . '.json-calendar-next .json-calendar-image{display:block;width:100%;height:clamp(300px,62vw,760px);object-fit:cover;object-position:center}
			#' . $id . '.json-calendar-next .json-calendar-details{position:absolute;left:0;bottom:0;max-width:min(75%,700px);padding:1rem 1.5rem 1.25rem;background:#fff;color:#000}
			#' . $id . '.json-calendar-next .json-calendar-title{margin:0;font-family:Arial,Helvetica,sans-serif;font-size:clamp(1.5rem,4vw,3.5rem);font-weight:800;line-height:1.02;letter-spacing:-.035em;text-transform:none}
			#' . $id . '.json-calendar-next .json-calendar-next-heading{position:absolute;z-index:2;left:0;top:1.5rem;margin:0;padding:.25rem .65rem;background:#fff;color:#000;font-family:Arial,Helvetica,sans-serif;font-size:clamp(2rem,7vw,6rem);font-weight:900;line-height:.9;letter-spacing:-.06em;text-transform:uppercase}
			#' . $id . '.json-calendar-next .json-calendar-next-heading + .json-calendar-image{margin-top:0}
		</style><div id="' . $id . '" class="json-calendar' . esc_attr( $classes ) . '"><ul class="json-calendar-list">';

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) continue;
			$title = $this->value( $entry, array( 'title', 'name', 'summary' ), __( 'Untitled event', 'json-calendar-wp' ) );
			$date = $this->value( $entry, array( 'date', 'start_date' ) );
			$date_end = $this->value( $entry, array( 'date_end', 'end_date' ) );
			$time_start = $this->value( $entry, array( 'time_start' ) );
			$time_end = $this->value( $entry, array( 'time_end' ) );
			$description = $this->value( $entry, array( 'description', 'details', 'content' ) );
			$image = $this->first_image( $entry );
			$start_fallback = $this->date_time( $entry, 'date', 'time_start', array( 'start', 'datetime' ) );

			$output .= '<li class="json-calendar-entry"><div class="json-calendar-card">';
			if ( $is_next ) {
				$output .= '<h1 class="json-calendar-next-heading">' . esc_html( get_option( self::OPTION_NEXT_HEADING, __( 'Next up', 'json-calendar-wp' ) ) ) . '</h1>';
			}
			if ( $image ) {
				$output .= '<img class="json-calendar-image" src="' . esc_url( $image ) . '" alt="' . esc_attr( $title ) . '" loading="lazy" />';
			}
			$output .= '<div class="json-calendar-details"><h2 class="json-calendar-title">' . esc_html( $title ) . '</h2>';
			if ( ! $is_next ) {
				if ( $date || $date_end || $start_fallback ) { $output .= '<div class="json-calendar-date">' . esc_html( $date ? $this->format_date_only( $date ) : $this->format_date( $start_fallback ) ); if ( $date_end && $date_end !== $date ) $output .= ' – ' . esc_html( $this->format_date_only( $date_end ) ); $output .= '</div>'; }
				if ( $time_start || $time_end ) { $output .= '<div class="json-calendar-time">' . esc_html( $time_start ); if ( $time_end ) $output .= ' – ' . esc_html( $time_end ); $output .= '</div>'; }
				if ( $description ) $output .= '<p class="json-calendar-description">' . wp_kses_post( $description ) . '</p>';
			}
			$output .= '</div></div></li>';
		}
		return $output . '</ul></div>';
	}

	private function get_entries( $data ) {
		if ( ! is_array( $data ) ) return array();
		if ( isset( $data['entries'] ) && is_array( $data['entries'] ) ) return $this->get_entries( $data['entries'] );
		if ( isset( $data['events'] ) && is_array( $data['events'] ) ) return $this->get_entries( $data['events'] );
		if ( $this->is_event( $data ) ) return array( $data );
		$entries = array();
		foreach ( $data as $reference => $entry ) if ( is_array( $entry ) ) foreach ( $this->get_entries( $entry ) as $nested ) { if ( empty( $nested['reference'] ) ) $nested['reference'] = (string) $reference; $entries[] = $nested; }
		return $entries;
	}

	private function is_event( $value ) { return is_array( $value ) && ( isset( $value['title'] ) || isset( $value['date'] ) || isset( $value['reference'] ) ); }
	private function filter_future_entries( $entries ) { return $this->sort_entries( array_filter( $entries, function( $entry ) { $start = $this->get_entry_start_timestamp( $entry ); $end = $this->get_entry_end_timestamp( $entry ); $today = strtotime( wp_date( 'Y-m-d', current_time( 'timestamp' ) ) ); return ( false !== $start && $start >= $today ) || ( false !== $end && $end >= $today ); } ) ); }
	private function filter_past_entries( $entries ) { return $this->sort_entries( array_filter( $entries, function( $entry ) { $end = $this->get_entry_end_timestamp( $entry ); $start = $this->get_entry_start_timestamp( $entry ); $today = strtotime( wp_date( 'Y-m-d', current_time( 'timestamp' ) ) ); return false !== $end ? $end < $today : ( false !== $start && $start < $today ); } ), true ); }
	private function sort_entries( $entries, $reverse = false ) { usort( $entries, function( $a, $b ) use ( $reverse ) { $left = $this->get_entry_start_timestamp( $a ); $right = $this->get_entry_start_timestamp( $b ); if ( false === $left ) $left = $this->get_entry_end_timestamp( $a ); if ( false === $right ) $right = $this->get_entry_end_timestamp( $b ); $result = ( false === $left || false === $right ) ? 0 : ( $left <=> $right ); return $reverse ? -$result : $result; } ); return array_values( $entries ); }
	private function get_entry_start_timestamp( $entry ) { $value = $this->value( $entry, array( 'date', 'start_date', 'start', 'datetime' ) ); return $value ? strtotime( $value ) : false; }
	private function get_entry_end_timestamp( $entry ) { $value = $this->value( $entry, array( 'date_end', 'end_date', 'end' ) ); return $value ? strtotime( $value ) : false; }
	private function date_time( $entry, $date_key, $time_key, $fallback_keys ) { $date = $this->value( $entry, array( $date_key ) ); $time = $this->value( $entry, array( $time_key ) ); return $date ? trim( $date . ( $time ? ' ' . $time : '' ) ) : $this->value( $entry, $fallback_keys ); }
	private function first_image( $entry ) { $image = isset( $entry['image'] ) ? $entry['image'] : ''; if ( is_array( $image ) ) $image = reset( $image ); return is_scalar( $image ) ? $this->normalise_url( $image ) : ''; }
	private function normalise_url( $url ) { $url = trim( (string) $url ); if ( $url && ! preg_match( '#^https?://#i', $url ) ) $url = 'https://' . $url; return $url && wp_http_validate_url( $url ) ? $url : ''; }
	private function value( $entry, $keys, $default = '' ) { foreach ( $keys as $key ) if ( isset( $entry[ $key ] ) && is_scalar( $entry[ $key ] ) && '' !== (string) $entry[ $key ] ) return (string) $entry[ $key ]; return $default; }
	private function format_date_only( $date ) { $timestamp = strtotime( $date ); return $timestamp ? wp_date( get_option( 'date_format' ), $timestamp ) : $date; }
	private function format_date( $date ) { $timestamp = strtotime( $date ); return $timestamp ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ) : $date; }
}

new JSON_Calendar_WP();
