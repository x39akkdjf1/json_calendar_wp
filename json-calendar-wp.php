<?php
/**
 * Plugin Name: JSON Calendar
 * Description: Fetches calendar entries from a JSON endpoint and displays them with the [json_calendar] shortcode.
 * Version: 1.6.0
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
	const CACHE_VERSION = '7';

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
		$url = esc_url_raw( $atts['url'] );
		if ( ! $url || ! wp_http_validate_url( $url ) ) return current_user_can( 'manage_options' ) ? '<p class="json-calendar-error">' . esc_html__( 'Configure a JSON endpoint under Settings → JSON Calendar.', 'json-calendar-wp' ) . '</p>' : '';

		$key = 'json_calendar_' . self::CACHE_VERSION . '_' . md5( $url );
		$data = get_transient( $key );
		if ( false === $data ) {
			$response = wp_safe_remote_get( $url, array( 'timeout' => 10, 'headers' => array( 'Accept' => 'application/json' ), 'user-agent' => 'JSON Calendar WordPress Plugin/1.6.0' ) );
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
		$output = '<style>#' . $id . ' .json-calendar-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:1rem;list-style:none;margin:0;padding:0}#' . $id . ' .json-calendar-entry{position:relative;min-width:0}#' . $id . ' .json-calendar-card{position:relative;width:100%;background:#fff;overflow:hidden}#' . $id . ' .json-calendar-image{display:block;width:100%;height:auto}#' . $id . ' .json-calendar-details{padding:1.25rem;background:#fff;color:#000}#' . $id . ' .json-calendar-title{margin:0 0 .7rem;font-size:1.35rem;line-height:1.2}#' . $id . ' .json-calendar-date,#' . $id . ' .json-calendar-time{margin:.25rem 0;color:#333;font-family:Arial,Helvetica,sans-serif;font-style:italic;font-size:.85rem}#' . $id . ' .json-calendar-description{margin:.8rem 0;line-height:1.45}#' . $id . '.json-calendar-next .json-calendar-list{display:block}#' . $id . '.json-calendar-next .json-calendar-image{width:100%;height:clamp(320px,40vw,620px);object-fit:cover;object-position:center}#' . $id . '.json-calendar-next .json-calendar-details{position:absolute;right:0;bottom:0;left:0;padding:0;background:transparent;color:#fff;text-align:right}#' . $id . '.json-calendar-next .wp-block-cover__inner-container{box-sizing:border-box;width:100%;padding:clamp(1rem,2vw,2.5rem);display:flex;flex-direction:column;align-items:flex-end;justify-content:flex-end;color:#fff}#' . $id . '.json-calendar-next .json-calendar-next-heading{display:block;margin:0;color:#fff;font-size:clamp(1.2rem,2.4vw,2.4rem);font-weight:400;line-height:1.1}#' . $id . '.json-calendar-next .json-calendar-title{margin:.2rem 0 0;color:#fff;font-size:clamp(2rem,4vw,4.5rem);font-weight:800;line-height:.95;letter-spacing:-.04em}#' . $id . '.json-calendar-archive .json-calendar-list{grid-template-columns:repeat(auto-fill,minmax(220px,1fr))}</style><div id="' . $id . '" class="json-calendar' . ( $is_next ? ' json-calendar-next' : ( $is_archive ? ' json-calendar-archive' : '' ) ) . '"><ul class="json-calendar-list">';

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) continue;
			$title = $this->value( $entry, array( 'title', 'name', 'summary' ), __( 'Untitled event', 'json-calendar-wp' ) );
			$image = $this->first_image( $entry );
			$output .= '<li class="json-calendar-entry"><div class="json-calendar-card">';
			if ( $is_next ) {
				if ( $image ) $output .= '<img class="json-calendar-image" src="' . esc_url( $image ) . '" alt="' . esc_attr( $title ) . '" loading="lazy" />';
				$output .= '<div class="json-calendar-details"><div class="wp-block-cover__inner-container has-global-padding is-layout-constrained wp-block-cover-is-layout-constrained"><span class="json-calendar-next-heading">' . esc_html( get_option( self::OPTION_NEXT_HEADING, __( 'Next up', 'json-calendar-wp' ) ) ) . '</span><h1 class="json-calendar-title">' . esc_html( $title ) . '</h1></div></div>';
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
}

new JSON_Calendar_WP();
