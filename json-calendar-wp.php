<?php
/**
 * Plugin Name: JSON Calendar
 * Description: Fetches calendar entries from a JSON endpoint, syncs them into real WordPress posts, and displays them with the [json_calendar] shortcode.
 * Version: 2.0.0
 * Author: x39akkdjf1
 * License: GPL-2.0-or-later
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class JSON_Calendar_WP {
	const OPTION_ENDPOINT = 'json_calendar_wp_endpoint';
	const OPTION_NEXT_HEADING = 'json_calendar_wp_next_heading';
	const OPTION_LAST_SYNC = 'json_calendar_wp_last_sync';
	const OPTION_LAST_SYNC_COUNT = 'json_calendar_wp_last_sync_count';
	const OPTION_LAST_SYNC_TRASHED = 'json_calendar_wp_last_sync_trashed';
	const OPTION_LAST_SYNC_ERROR = 'json_calendar_wp_last_sync_error';
	const OPTION_SCHEDULE_LOCK = 'json_calendar_wp_schedule_lock';
	const SHORTCODE = 'json_calendar';
	const META_SHORTCODE = 'json_calendar_meta';
	const CACHE_VERSION = '13';
	const POST_TYPE = 'json_calendar_event';
	const REWRITE_SLUG = 'json-calendar-event';
	const CRON_HOOK = 'json_calendar_wp_sync_events';
	const CRON_SCHEDULE = 'json_calendar_15_minutes';

	// These keys stay underscore-prefixed so sync bookkeeping stays out of the classic Custom Fields UI.
	// Elementor Dynamic Tags and ACF can still read them directly as normal post meta values.
	const META_REFERENCE = '_json_calendar_reference';
	const META_IMAGE_URL = '_json_calendar_image_url';
	const META_DATE = '_json_calendar_date';
	const META_DATE_END = '_json_calendar_date_end';
	const META_TIME_START = '_json_calendar_time_start';
	const META_TIME_END = '_json_calendar_time_end';
	const META_DESCRIPTION = '_json_calendar_description';
	const META_SOURCE_URL = '_json_calendar_source_url';
	const META_IMAGE_ID = '_json_calendar_image_id';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter( 'cron_schedules', array( $this, 'add_cron_schedule' ) );
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'init', array( $this, 'ensure_cron_schedule' ) );
		add_action( self::CRON_HOOK, array( $this, 'run_scheduled_sync' ) );
		add_action( 'admin_post_json_calendar_wp_sync_now', array( $this, 'handle_manual_sync' ) );
		add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );
		add_shortcode( self::META_SHORTCODE, array( $this, 'render_event_meta_shortcode' ) );
	}

	public static function activate() {
		$plugin = new self();
		$plugin->register_post_type();
		$plugin->ensure_cron_schedule();
		flush_rewrite_rules();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		flush_rewrite_rules();
	}

	public function add_settings_page() {
		add_options_page( __( 'JSON Calendar', 'json-calendar-wp' ), __( 'JSON Calendar', 'json-calendar-wp' ), 'manage_options', 'json-calendar-wp', array( $this, 'render_settings_page' ) );
	}

	public function register_settings() {
		register_setting( 'json_calendar_wp_settings', self::OPTION_ENDPOINT, array( 'type' => 'string', 'sanitize_callback' => 'esc_url_raw', 'default' => '' ) );
		register_setting( 'json_calendar_wp_settings', self::OPTION_NEXT_HEADING, array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => __( 'Next up', 'json-calendar-wp' ) ) );
	}

	public function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels' => array(
					'name' => esc_html__( 'Calendar Events', 'json-calendar-wp' ),
					'singular_name' => esc_html__( 'Calendar Event', 'json-calendar-wp' ),
				),
				'public' => true,
				'show_in_rest' => true,
				'has_archive' => false,
				'rewrite' => array(
					'slug' => self::REWRITE_SLUG,
					'with_front' => false,
				),
				'supports' => array( 'title', 'editor', 'thumbnail' ),
			)
		);
	}

	public function add_cron_schedule( $schedules ) {
		if ( ! isset( $schedules[ self::CRON_SCHEDULE ] ) ) {
			$schedules[ self::CRON_SCHEDULE ] = array(
				'interval' => 15 * MINUTE_IN_SECONDS,
				'display' => esc_html__( 'Every 15 Minutes', 'json-calendar-wp' ),
			);
		}

		return $schedules;
	}

	public function ensure_cron_schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			$now = time();
			$lock = absint( get_option( self::OPTION_SCHEDULE_LOCK, 0 ) );
			if ( $lock && $lock > ( $now - MINUTE_IN_SECONDS ) ) {
				return;
			}
			if ( ! add_option( self::OPTION_SCHEDULE_LOCK, $now, '', false ) ) {
				update_option( self::OPTION_SCHEDULE_LOCK, $now, false );
			}
			if ( wp_next_scheduled( self::CRON_HOOK ) ) {
				delete_option( self::OPTION_SCHEDULE_LOCK );
				return;
			}
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::CRON_SCHEDULE, self::CRON_HOOK );
			delete_option( self::OPTION_SCHEDULE_LOCK );
		}
	}

	public function run_scheduled_sync() {
		$endpoint = get_option( self::OPTION_ENDPOINT, '' );
		if ( $endpoint ) {
			$this->sync_endpoint( $endpoint, true, true );
		}
	}

	public function handle_manual_sync() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to sync calendar events.', 'json-calendar-wp' ) );
		}

		check_admin_referer( 'json_calendar_wp_sync_now' );

		$endpoint = get_option( self::OPTION_ENDPOINT, '' );
		if ( ! $endpoint ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page' => 'json-calendar-wp',
						'json_calendar_sync' => 'error',
						'message' => __( 'Save a JSON endpoint URL before running a manual sync.', 'json-calendar-wp' ),
					),
					admin_url( 'options-general.php' )
				)
			);
			exit;
		}

		$result = $this->sync_endpoint( $endpoint, true, true );
		$args = array( 'page' => 'json-calendar-wp' );

		if ( is_wp_error( $result ) ) {
			$args['json_calendar_sync'] = 'error';
			$args['message'] = $result->get_error_message();
		} elseif ( ! empty( $result['errors'] ) ) {
			$args['json_calendar_sync'] = 'error';
			$args['message'] = implode( ' ', array_unique( $result['errors'] ) );
			$args['count'] = isset( $result['count'] ) ? absint( $result['count'] ) : 0;
			$args['trashed'] = isset( $result['trashed'] ) ? absint( $result['trashed'] ) : 0;
		} else {
			$args['json_calendar_sync'] = 'success';
			$args['count'] = isset( $result['count'] ) ? absint( $result['count'] ) : 0;
			$args['trashed'] = isset( $result['trashed'] ) ? absint( $result['trashed'] ) : 0;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'options-general.php' ) ) );
		exit;
	}

	public function render_settings_page() {
		$last_sync = absint( get_option( self::OPTION_LAST_SYNC, 0 ) );
		$last_count = get_option( self::OPTION_LAST_SYNC_COUNT, null );
		$last_trashed = get_option( self::OPTION_LAST_SYNC_TRASHED, null );
		$last_error = (string) get_option( self::OPTION_LAST_SYNC_ERROR, '' );
		?>
		<div class="wrap"><h1><?php echo esc_html__( 'JSON Calendar', 'json-calendar-wp' ); ?></h1>
		<p><?php echo esc_html__( 'Configure the JSON endpoint and the headline used by the next-event view.', 'json-calendar-wp' ); ?></p>
		<?php $this->render_sync_notice(); ?>
		<form method="post" action="options.php"><?php settings_fields( 'json_calendar_wp_settings' ); ?>
		<table class="form-table" role="presentation">
		<tr><th scope="row"><label for="json_calendar_wp_endpoint"><?php echo esc_html__( 'JSON endpoint URL', 'json-calendar-wp' ); ?></label></th><td><input type="url" class="regular-text" id="json_calendar_wp_endpoint" name="<?php echo esc_attr( self::OPTION_ENDPOINT ); ?>" value="<?php echo esc_attr( get_option( self::OPTION_ENDPOINT, '' ) ); ?>" required /></td></tr>
		<tr><th scope="row"><label for="json_calendar_wp_next_heading"><?php echo esc_html__( 'Next-event headline', 'json-calendar-wp' ); ?></label></th><td><input type="text" class="regular-text" id="json_calendar_wp_next_heading" name="<?php echo esc_attr( self::OPTION_NEXT_HEADING ); ?>" value="<?php echo esc_attr( get_option( self::OPTION_NEXT_HEADING, __( 'Next up', 'json-calendar-wp' ) ) ); ?>" /></td></tr>
		</table><?php submit_button(); ?></form>
		<h2><?php echo esc_html__( 'Synchronization', 'json-calendar-wp' ); ?></h2>
		<p><?php
		if ( $last_sync ) {
			/* translators: 1: last sync date/time, 2: synced event count, 3: removed event count. */
			echo esc_html( sprintf( __( 'Last sync: %1$s · %2$d synced, %3$d removed.', 'json-calendar-wp' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_sync ), absint( $last_count ), absint( $last_trashed ) ) );
		} else {
			echo esc_html__( 'Last sync: not yet run.', 'json-calendar-wp' );
		}
		?></p>
		<?php if ( $last_error ) : ?>
			<p><strong><?php echo esc_html__( 'Last sync error:', 'json-calendar-wp' ); ?></strong> <?php echo esc_html( $last_error ); ?></p>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="json_calendar_wp_sync_now" />
			<?php wp_nonce_field( 'json_calendar_wp_sync_now' ); ?>
			<?php submit_button( __( 'Sync now', 'json-calendar-wp' ), 'secondary', 'submit', false ); ?>
		</form></div>
		<?php
	}

	private function render_sync_notice() {
		if ( empty( $_GET['json_calendar_sync'] ) ) {
			return;
		}

		$state = sanitize_key( wp_unslash( $_GET['json_calendar_sync'] ) );
		$message = '';
		$class = 'notice notice-info';

		if ( 'success' === $state ) {
			$count = isset( $_GET['count'] ) ? absint( wp_unslash( $_GET['count'] ) ) : 0;
			$trashed = isset( $_GET['trashed'] ) ? absint( wp_unslash( $_GET['trashed'] ) ) : 0;
			$message = sprintf( __( 'Calendar sync completed. %1$d synced, %2$d removed.', 'json-calendar-wp' ), $count, $trashed );
			$class = 'notice notice-success';
		} elseif ( 'error' === $state ) {
			$message = isset( $_GET['message'] ) ? wp_strip_all_tags( wp_unslash( $_GET['message'] ) ) : __( 'Calendar sync failed.', 'json-calendar-wp' );
			$class = 'notice notice-error';
		}

		if ( $message ) {
			printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
		}
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
		$event_urls = $this->get_event_urls( $entries, $atts['url'] );

		$id = esc_attr( wp_unique_id( 'json-calendar-' ) );
		$output = '<style>#' . $id . ' .json-calendar-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:1rem;list-style:none;margin:0;padding:0}#' . $id . ' .json-calendar-entry{position:relative;min-width:0}#' . $id . ' .json-calendar-card{position:relative;width:100%;background:#fff;overflow:hidden}#' . $id . ' .json-calendar-image{display:block;width:100%;height:auto}#' . $id . ' .json-calendar-details{padding:1.25rem;background:#fff;color:#000}#' . $id . ' .json-calendar-title{margin:0 0 .7rem;font-size:1.35rem;line-height:1.2}#' . $id . ' .json-calendar-date,#' . $id . ' .json-calendar-time{margin:.25rem 0;color:#333;font-family:Arial,Helvetica,sans-serif;font-style:italic;font-size:.85rem}#' . $id . ' .json-calendar-description{margin:.8rem 0;line-height:1.45}#' . $id . '.json-calendar-next .json-calendar-list{display:block}#' . $id . '.json-calendar-next .json-calendar-entry{width:100%;max-width:1700px;margin:0 auto}#' . $id . '.json-calendar-next .json-calendar-card{width:100%;height:clamp(500px,41.176vw,700px);min-height:500px;max-height:700px;background:#111}#' . $id . '.json-calendar-next .json-calendar-image{display:block;width:100%;height:100%;opacity:.8;object-fit:cover;object-position:center}#' . $id . '.json-calendar-next .json-calendar-details{position:absolute;right:4%;bottom:4%;left:4%;padding:0;background:transparent;color:#fff;text-align:right}#' . $id . '.json-calendar-next .wp-block-cover__inner-container{box-sizing:border-box!important;width:100%!important;max-width:none!important;margin:0!important;padding:0!important;display:flex;flex-direction:column;align-items:flex-end;justify-content:flex-end;color:#fff;text-align:right}#' . $id . '.json-calendar-next .wp-block-cover__inner-container>*{max-width:none!important;margin-left:0!important;margin-right:0!important;text-align:right}#' . $id . '.json-calendar-next .json-calendar-next-heading,#' . $id . '.json-calendar-next .json-calendar-title,#' . $id . '.json-calendar-next .json-calendar-more{text-shadow:none;text-align:right}#' . $id . '.json-calendar-next .json-calendar-next-heading{display:block;margin:0;color:#fff;font-size:clamp(1.2rem,2.4vw,2.4rem);font-weight:400;line-height:1.1}#' . $id . '.json-calendar-next .json-calendar-title{margin:.15rem 0 0;color:#fff;font-size:clamp(1.5rem,3.2vw,3.5rem);font-weight:800;line-height:.98;letter-spacing:-.035em;text-decoration:none}#' . $id . '.json-calendar-next .json-calendar-more{display:block;margin:.3rem 0 0;color:#fff;font-size:clamp(.9rem,1.4vw,1.2rem);font-weight:400;line-height:1.1;text-decoration:none}#' . $id . '.json-calendar-archive .json-calendar-list{grid-template-columns:repeat(auto-fill,minmax(220px,1fr))}@media (max-width:700px){#' . $id . '.json-calendar-next .json-calendar-card{height:700px;min-height:700px;max-height:700px}}</style><div id="' . $id . '" class="json-calendar' . ( $is_next ? ' json-calendar-next' : ( $is_archive ? ' json-calendar-archive' : '' ) ) . '"><ul class="json-calendar-list">';

		foreach ( $entries as $entry ) {
			$title = $this->value( $entry, array( 'title', 'name', 'summary' ), __( 'Untitled event', 'json-calendar-wp' ) );
			$image = $this->first_image( $entry );
			$output .= '<li class="json-calendar-entry"><div class="json-calendar-card">';
			if ( $is_next ) {
				$event_url = $this->get_event_reference( $entry );
				$event_url = isset( $event_urls[ $event_url ] ) ? $event_urls[ $event_url ] : '';
				if ( $image ) $output .= '<img class="json-calendar-image" src="' . esc_url( $image ) . '" alt="' . esc_attr( $title ) . '" loading="lazy" />';
				$output .= '<div class="json-calendar-details"><div class="wp-block-cover__inner-container"><span class="json-calendar-next-heading">' . esc_html( get_option( self::OPTION_NEXT_HEADING, __( 'Next up', 'json-calendar-wp' ) ) ) . '</span><h1 class="json-calendar-title">' . esc_html( $title ) . '</h1>' . ( $event_url ? '<a class="json-calendar-more" href="' . esc_url( $event_url ) . '">' . esc_html__( 'Mehr', 'json-calendar-wp' ) . '</a>' : '<span class="json-calendar-more">' . esc_html__( 'Mehr', 'json-calendar-wp' ) . '</span>' ) . '</div></div>';
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

	public function render_event_meta_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'field' => '',
				'label' => '',
				'class' => '',
			),
			$atts,
			self::META_SHORTCODE
		);

		$field = sanitize_key( $atts['field'] );
		if ( '' === $field ) {
			return '';
		}

		$post_id = $this->get_current_event_post_id();
		if ( ! $post_id ) {
			return '';
		}

		$output = '';

		switch ( $field ) {
			case 'title':
				$output = esc_html( get_the_title( $post_id ) );
				break;

			case 'date':
				$output = esc_html( $this->format_date_only( (string) get_post_meta( $post_id, self::META_DATE, true ) ) );
				break;

			case 'date_end':
				$output = esc_html( $this->format_date_only( (string) get_post_meta( $post_id, self::META_DATE_END, true ) ) );
				break;

			case 'time_start':
				$output = esc_html( (string) get_post_meta( $post_id, self::META_TIME_START, true ) );
				break;

			case 'time_end':
				$output = esc_html( (string) get_post_meta( $post_id, self::META_TIME_END, true ) );
				break;

			case 'time':
				$start = (string) get_post_meta( $post_id, self::META_TIME_START, true );
				$end = (string) get_post_meta( $post_id, self::META_TIME_END, true );

				if ( '' !== $start && '' !== $end ) {
					$output = esc_html( $start . ' – ' . $end );
				} elseif ( '' !== $start || '' !== $end ) {
					$output = esc_html( '' !== $start ? $start : $end );
				}
				break;

			case 'description':
				$output = wp_kses_post( (string) get_post_meta( $post_id, self::META_DESCRIPTION, true ) );
				break;

			case 'image':
				$image_url = (string) get_post_meta( $post_id, self::META_IMAGE_URL, true );

				if ( '' !== $image_url ) {
					$alt_text = get_the_title( $post_id );
					if ( '' === $alt_text ) {
						$alt_text = __( 'Event image', 'json-calendar-wp' );
					}

					$this->enqueue_shortcode_styles();

					$output = sprintf(
						'<img class="json-calendar-meta-image" src="%1$s" alt="%2$s" loading="lazy" decoding="async" />',
						esc_url( $image_url ),
						esc_attr( $alt_text )
					);
				}
				break;

			case 'reference':
				$output = esc_html( (string) get_post_meta( $post_id, self::META_REFERENCE, true ) );
				break;
		}

		if ( '' === $output ) {
			return '';
		}

		$label = sanitize_text_field( $atts['label'] );
		$class = $this->sanitize_shortcode_class( $atts['class'] );
		if ( '' === $label && '' === $class ) {
			return $output;
		}

		$classes = array(
			'json-calendar-meta',
			sanitize_html_class( 'json-calendar-meta-' . $field ),
		);

		if ( '' !== $class ) {
			$classes[] = $class;
		}

		return sprintf(
			'<div class="%1$s">%2$s%3$s</div>',
			esc_attr( implode( ' ', array_filter( $classes ) ) ),
			'' !== $label ? '<span class="json-calendar-meta-label">' . esc_html( $label ) . '</span> ' : '',
			$output
		);
	}

	private function sync_endpoint( $url, $force = false, $update_status = true ) {
		$data = $this->fetch_data( $url, $force );
		if ( is_wp_error( $data ) ) {
			if ( $update_status ) {
				update_option( self::OPTION_LAST_SYNC_ERROR, $data->get_error_message(), false );
			}
			return $data;
		}

		return $this->sync_entries( $url, $this->get_entries( $data ), $update_status );
	}

	private function sync_entries( $url, $entries, $update_status = true ) {
		$url = esc_url_raw( $url );
		$entries = is_array( $entries ) ? $entries : array();
		$seen = array();
		$processed_post_ids = array();
		$errors = array();
		$existing_posts = $this->get_event_post_map( $url );

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$reference = $this->get_event_reference( $entry );
			if ( ! $reference ) {
				continue;
			}

			$seen[ $reference ] = true;
			$save_result = $this->upsert_event_post( $entry, $url, $reference, $existing_posts );
			if ( is_wp_error( $save_result ) ) {
				$errors[] = $save_result->get_error_message();
				continue;
			}

			$post_id = isset( $save_result['post_id'] ) ? absint( $save_result['post_id'] ) : 0;
			if ( ! empty( $save_result['error'] ) ) {
				$errors[] = (string) $save_result['error'];
			}

			if ( $post_id ) {
				$processed_post_ids[ $post_id ] = true;
			}
		}

		$count = count( $processed_post_ids );
		$trashed = $this->trash_missing_events( $url, array_keys( $seen ) );
		$result = array( 'count' => $count, 'synced' => $count, 'trashed' => $trashed, 'errors' => array_values( array_unique( $errors ) ) );

		if ( $update_status ) {
			update_option( self::OPTION_LAST_SYNC, current_time( 'timestamp' ), false );
			update_option( self::OPTION_LAST_SYNC_COUNT, $result['count'], false );
			update_option( self::OPTION_LAST_SYNC_TRASHED, $result['trashed'], false );
			if ( $result['errors'] ) {
				update_option( self::OPTION_LAST_SYNC_ERROR, implode( ' ', $result['errors'] ), false );
			} else {
				delete_option( self::OPTION_LAST_SYNC_ERROR );
			}
		}

		return $result;
	}

	private function upsert_event_post( $entry, $url, $reference, &$existing_posts ) {
		$title = sanitize_text_field( $this->value( $entry, array( 'title', 'name', 'summary' ), __( 'Untitled event', 'json-calendar-wp' ) ) );
		$image = $this->first_image( $entry );
		$date = $this->value( $entry, array( 'date', 'start_date', 'start', 'datetime' ) );
		$end = $this->value( $entry, array( 'date_end', 'end_date', 'end' ) );
		$time_start = $this->value( $entry, array( 'time_start' ) );
		$time_end = $this->value( $entry, array( 'time_end' ) );
		$description = $this->value( $entry, array( 'description', 'details', 'content' ) );
		$post_content = $description ? wp_kses_post( $description ) : '';
		$post_id = isset( $existing_posts[ $reference ] ) ? absint( $existing_posts[ $reference ] ) : 0;
		$previous_image = $post_id ? (string) get_post_meta( $post_id, self::META_IMAGE_URL, true ) : '';
		$postarr = array(
			'post_title' => $title,
			'post_content' => $post_content,
			'post_status' => 'publish',
			'post_type' => self::POST_TYPE,
			'post_name' => $reference,
		);

		if ( $post_id ) {
			if ( 'trash' === get_post_status( $post_id ) ) {
				wp_untrash_post( $post_id );
			}
			$postarr['ID'] = $post_id;
			$post_id = wp_update_post( wp_slash( $postarr ), true );
		} else {
			$post_id = wp_insert_post( wp_slash( $postarr ), true );
		}

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		if ( ! $post_id ) {
			return new WP_Error( 'sync_failed', __( 'A calendar event could not be saved.', 'json-calendar-wp' ) );
		}

		$existing_posts[ $reference ] = (int) $post_id;
		wp_update_post( wp_slash( array( 'ID' => $post_id, 'post_name' => $reference ) ) );

		update_post_meta( $post_id, self::META_REFERENCE, $reference );
		update_post_meta( $post_id, self::META_DATE, $date );
		update_post_meta( $post_id, self::META_DATE_END, $end );
		update_post_meta( $post_id, self::META_TIME_START, $time_start );
		update_post_meta( $post_id, self::META_TIME_END, $time_end );
		update_post_meta( $post_id, self::META_DESCRIPTION, $description );
		update_post_meta( $post_id, self::META_SOURCE_URL, $url );

		$error_message = '';
		if ( $image ) {
			$image_result = $this->sync_featured_image( $post_id, $image, $previous_image );
			if ( is_wp_error( $image_result ) ) {
				$error_message = $image_result->get_error_message();
				update_post_meta( $post_id, self::META_IMAGE_URL, $previous_image );
			} else {
				update_post_meta( $post_id, self::META_IMAGE_URL, $image );
			}
		} else {
			$attachment_id = absint( get_post_meta( $post_id, self::META_IMAGE_ID, true ) );
			update_post_meta( $post_id, self::META_IMAGE_URL, '' );
			delete_post_thumbnail( $post_id );
			delete_post_meta( $post_id, self::META_IMAGE_ID );
			if ( $attachment_id ) {
				$this->delete_attachment_if_exclusive( $attachment_id, $post_id );
			}
		}

		return array(
			'post_id' => (int) $post_id,
			'error' => $error_message,
		);
	}

	private function sync_featured_image( $post_id, $image_url, $previous_image ) {
		$previous_attachment_id = absint( get_post_meta( $post_id, self::META_IMAGE_ID, true ) );
		if ( $previous_attachment_id && $image_url === $previous_image && get_post( $previous_attachment_id ) ) {
			if ( get_post_thumbnail_id( $post_id ) !== $previous_attachment_id ) {
				set_post_thumbnail( $post_id, $previous_attachment_id );
			}
			return true;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = media_sideload_image( $image_url, $post_id, null, 'id' );
		if ( is_wp_error( $attachment_id ) ) {
			return new WP_Error( 'sync_failed', __( 'A calendar event image could not be downloaded.', 'json-calendar-wp' ) );
		}

		update_post_meta( $post_id, self::META_IMAGE_ID, $attachment_id );
		set_post_thumbnail( $post_id, $attachment_id );
		if ( $previous_attachment_id && $previous_attachment_id !== $attachment_id ) {
			$this->delete_attachment_if_exclusive( $previous_attachment_id, $post_id );
		}
		return true;
	}

	private function delete_attachment_if_exclusive( $attachment_id, $post_id ) {
		$related_posts = get_posts(
			array(
				'post_type' => self::POST_TYPE,
				'post_status' => array( 'publish', 'draft', 'pending', 'future', 'private', 'trash' ),
				'numberposts' => 1,
				'fields' => 'ids',
				'post__not_in' => array( $post_id ),
				'suppress_filters' => true,
				'meta_query' => array(
					array(
						'key' => self::META_IMAGE_ID,
						'value' => $attachment_id,
					),
				),
			)
		);

		if ( ! $related_posts ) {
			wp_delete_attachment( $attachment_id, true );
		}
	}

	private function get_event_post_map( $url ) {
		global $wpdb;

		$url = esc_url_raw( $url );
		if ( ! $url ) {
			return array();
		}

		$statuses = array( 'publish', 'draft', 'pending', 'future', 'private', 'trash' );
		$status_placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$query_args = array_merge(
			array(
				self::META_SOURCE_URL,
				self::META_REFERENCE,
				self::POST_TYPE,
			),
			$statuses,
			array( $url )
		);
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT posts.ID AS post_id, reference.meta_value AS reference
				FROM {$wpdb->posts} AS posts
				INNER JOIN {$wpdb->postmeta} AS source ON posts.ID = source.post_id AND source.meta_key = %s
				INNER JOIN {$wpdb->postmeta} AS reference ON posts.ID = reference.post_id AND reference.meta_key = %s
				WHERE posts.post_type = %s
				AND posts.post_status IN ({$status_placeholders})
				AND source.meta_value = %s",
				$query_args
			),
			ARRAY_A
		);
		$map = array();

		foreach ( $rows as $row ) {
			if ( isset( $row['reference'], $row['post_id'] ) ) {
				$map[ (string) $row['reference'] ] = absint( $row['post_id'] );
			}
		}

		return $map;
	}

	private function trash_missing_events( $url, $seen ) {
		global $wpdb;

		$url = esc_url_raw( $url );
		if ( ! $url ) {
			return 0;
		}

		$seen_lookup = array_fill_keys( $seen, true );
		$statuses = array( 'publish', 'draft', 'pending', 'future', 'private' );
		$status_placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$query_args = array_merge(
			array(
				self::META_SOURCE_URL,
				self::META_REFERENCE,
				self::POST_TYPE,
			),
			$statuses,
			array( $url )
		);
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT posts.ID AS post_id, reference.meta_value AS reference
				FROM {$wpdb->posts} AS posts
				INNER JOIN {$wpdb->postmeta} AS source ON posts.ID = source.post_id AND source.meta_key = %s
				INNER JOIN {$wpdb->postmeta} AS reference ON posts.ID = reference.post_id AND reference.meta_key = %s
				WHERE posts.post_type = %s
				AND posts.post_status IN ({$status_placeholders})
				AND source.meta_value = %s",
				$query_args
			),
			ARRAY_A
		);
		$count = 0;

		foreach ( $results as $row ) {
			$post_id = isset( $row['post_id'] ) ? absint( $row['post_id'] ) : 0;
			$reference = isset( $row['reference'] ) ? (string) $row['reference'] : '';
			if ( ! isset( $seen_lookup[ $reference ] ) ) {
				wp_trash_post( $post_id );
				++$count;
			}
		}

		return $count;
	}

	private function fetch_data( $url, $force = false ) {
		$url = esc_url_raw( $url );
		if ( ! $url || ! wp_http_validate_url( $url ) ) return new WP_Error( 'invalid_endpoint', __( 'Configure a valid JSON endpoint under Settings → JSON Calendar.', 'json-calendar-wp' ) );
		$key = 'json_calendar_' . self::CACHE_VERSION . '_' . md5( $url );
		if ( ! $force ) {
			$data = get_transient( $key );
			if ( false !== $data ) return $data;
		}
		$response = wp_safe_remote_get( $url, array( 'timeout' => 10, 'headers' => array( 'Accept' => 'application/json' ), 'user-agent' => 'JSON Calendar WordPress Plugin/2.0.0' ) );
		$response_code = (int) wp_remote_retrieve_response_code( $response );
		if ( is_wp_error( $response ) || $response_code < 200 || $response_code >= 300 ) return new WP_Error( 'endpoint_unavailable', __( 'Calendar entries are temporarily unavailable.', 'json-calendar-wp' ) );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( JSON_ERROR_NONE !== json_last_error() ) return new WP_Error( 'invalid_json', __( 'The calendar endpoint returned invalid JSON.', 'json-calendar-wp' ) );
		if ( ! is_array( $data ) ) return new WP_Error( 'invalid_json', __( 'The calendar endpoint returned invalid JSON.', 'json-calendar-wp' ) );
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

	private function get_event_reference( $entry ) {
		$reference = $this->value( $entry, array( 'reference', 'id', 'slug', 'uid' ) );
		if ( $reference ) return sanitize_title( $reference );
		$title = $this->value( $entry, array( 'title', 'name', 'summary' ), 'event' );
		$date = $this->value( $entry, array( 'date', 'start_date', 'start', 'datetime' ) );
		$seed = trim( $title . ' ' . $date );
		if ( '' === $seed ) $seed = md5( wp_json_encode( $entry ) );
		return sanitize_title( $seed );
	}

	private function get_event_urls( $entries, $source_url = '' ) {
		$urls = array();
		$source_url = esc_url_raw( $source_url );
		$post_map = $source_url ? $this->get_event_post_map( $source_url ) : array();

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$reference = $this->get_event_reference( $entry );
			$post_id = isset( $post_map[ $reference ] ) ? absint( $post_map[ $reference ] ) : 0;
			if ( ! $post_id && ! $source_url ) {
				$post_id = $this->find_event_post_id( $reference, array( 'publish' ) );
			}
			$urls[ $reference ] = $post_id ? get_permalink( $post_id ) : '';
		}

		return $urls;
	}

	private function find_event_post_id( $reference, $post_status = array( 'publish' ), $source_url = '' ) {
		$meta_query = array(
			array(
				'key' => self::META_REFERENCE,
				'value' => sanitize_title( $reference ),
			),
		);

		if ( $source_url ) {
			$meta_query[] = array(
				'key' => self::META_SOURCE_URL,
				'value' => esc_url_raw( $source_url ),
			);
		}

		$posts = get_posts(
			array(
				'post_type' => self::POST_TYPE,
				'post_status' => $post_status,
				'numberposts' => 1,
				'fields' => 'ids',
				'suppress_filters' => true,
				'meta_query' => $meta_query,
			)
		);

		return $posts ? (int) reset( $posts ) : 0;
	}

	private function get_current_event_post_id() {
		$post_id = get_queried_object_id();
		$queried_object = get_queried_object();
		$is_event_preview = $this->is_site_editor_shortcode_preview( $queried_object );

		if ( $is_event_preview && $queried_object instanceof WP_Post ) {
			return (int) $queried_object->ID;
		}

		if ( ! is_singular( self::POST_TYPE ) ) {
			return 0;
		}

		if ( $queried_object instanceof WP_Post && self::POST_TYPE === $queried_object->post_type ) {
			return (int) $queried_object->ID;
		}

		if ( $post_id && self::POST_TYPE === get_post_type( $post_id ) && is_singular( self::POST_TYPE ) ) {
			return (int) $post_id;
		}

		if ( is_singular( self::POST_TYPE ) ) {
			$post = get_post();
			if ( $post && self::POST_TYPE === get_post_type( $post ) ) {
				return (int) $post->ID;
			}
		}

		return 0;
	}

	private function sanitize_shortcode_class( $class ) {
		if ( ! is_scalar( $class ) ) {
			return '';
		}

		$classes = preg_split( '/\s+/', trim( (string) $class ) );
		$classes = array_map( 'sanitize_html_class', array_filter( $classes ) );
		$classes = array_filter( array_unique( $classes ) );

		return implode( ' ', $classes );
	}

	private function enqueue_shortcode_styles() {
		static $inline_style_added = false;

		$handle = 'json-calendar-wp-shortcode';

		if ( ! wp_style_is( $handle, 'registered' ) ) {
			wp_register_style( $handle, false, array(), '2.0.0' );
		}

		wp_enqueue_style( $handle );

		if ( ! $inline_style_added ) {
			wp_add_inline_style( $handle, '.json-calendar-meta-image{display:block;max-width:100%;height:auto;}' );
			$inline_style_added = true;
		}
	}

	private function is_site_editor_shortcode_preview( $queried_object ) {
		if ( ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}

		if ( ! ( $queried_object instanceof WP_Post ) || self::POST_TYPE !== $queried_object->post_type ) {
			return false;
		}

		if ( ! current_user_can( 'edit_theme_options' ) || ! current_user_can( 'edit_post', $queried_object->ID ) ) {
			return false;
		}

		$request_path = '';

		if ( isset( $_REQUEST['rest_route'] ) ) {
			$request_path = '/' . ltrim( (string) wp_unslash( $_REQUEST['rest_route'] ), '/' );
		} elseif ( isset( $_SERVER['REQUEST_URI'] ) ) {
			$request_path = (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH );
		}

		$preview_post_id = 0;

		if ( isset( $_REQUEST['context'] ) && is_array( $_REQUEST['context'] ) ) {
			if ( isset( $_REQUEST['context']['postId'] ) ) {
				$preview_post_id = absint( wp_unslash( $_REQUEST['context']['postId'] ) );
			} elseif ( isset( $_REQUEST['context']['post_id'] ) ) {
				$preview_post_id = absint( wp_unslash( $_REQUEST['context']['post_id'] ) );
			}
		}

		if ( ! $preview_post_id && isset( $_REQUEST['post_id'] ) ) {
			$preview_post_id = absint( wp_unslash( $_REQUEST['post_id'] ) );
		}

		$is_shortcode_renderer = in_array(
			untrailingslashit( $request_path ),
			array(
				'/wp/v2/block-renderer/core/shortcode',
				'/wp-json/wp/v2/block-renderer/core/shortcode',
				'/index.php/wp-json/wp/v2/block-renderer/core/shortcode',
			),
			true
		);

		return $is_shortcode_renderer
			&& $preview_post_id
			&& $preview_post_id === (int) $queried_object->ID;
	}
}

register_activation_hook( __FILE__, array( 'JSON_Calendar_WP', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'JSON_Calendar_WP', 'deactivate' ) );

new JSON_Calendar_WP();
