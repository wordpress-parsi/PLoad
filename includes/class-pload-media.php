<?php
/**
 * Media library integration: the Pload column with its buttons and
 * details, the row action, the bulk action and the per-image exclusion.
 */

defined( 'ABSPATH' ) || exit;

final class Pload_Media {

	const CAP   = 'manage_options';
	const NONCE = 'pload_media';

	public function __construct() {
		add_filter( 'manage_upload_columns', array( $this, 'column' ) );
		add_action( 'manage_media_custom_column', array( $this, 'column_value' ), 10, 2 );
		add_filter( 'bulk_actions-upload', array( $this, 'bulk_action' ) );
		add_filter( 'handle_bulk_actions-upload', array( $this, 'handle_bulk' ), 10, 3 );
		add_filter( 'attachment_fields_to_edit', array( $this, 'attachment_fields' ), 10, 2 );
		add_filter( 'attachment_fields_to_save', array( $this, 'attachment_field_save' ), 10, 2 );
		add_action( 'wp_ajax_pload_media', array( $this, 'ajax' ) );
		add_action( 'admin_notices', array( $this, 'now_notice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	public function assets( $hook ) {
		if ( ! current_user_can( self::CAP ) || ! in_array( $hook, array( 'upload.php', 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		wp_enqueue_style( 'pload-media', PLOAD_URL . 'assets/media.css', array(), PLOAD_VERSION );
		wp_enqueue_script( 'pload-media', PLOAD_URL . 'assets/media.js', array(), PLOAD_VERSION, true );
		wp_localize_script(
			'pload-media',
			'ploadMedia',
			array(
				'ajax'    => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'working' => __( 'Working…', 'pload' ),
				'failed'  => __( 'Something went wrong. Reload the page and try again.', 'pload' ),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Column
	 * ------------------------------------------------------------------- */

	public function column( $cols ) {
		if ( current_user_can( self::CAP ) ) {
			$cols['pload'] = 'Pload';
		}
		return $cols;
	}

	public function column_value( $col, $id ) {
		if ( 'pload' === $col ) {
			self::cell( (int) $id );
		}
	}

	/**
	 * Status, one main button and the per-size details of one attachment.
	 * The same markup is returned over AJAX after a button is used.
	 */
	public static function cell( $id ) {
		$state = Pload_Converter::state_of( $id );
		if ( 'other' === $state ) {
			echo '<span class="pload-mc-muted">—</span>';
			return;
		}

		echo '<div class="pload-mc" data-id="' . esc_attr( (string) $id ) . '">';

		$detail = get_post_meta( $id, Pload_Converter::META, true );
		$detail = is_array( $detail ) ? $detail : array();
		$sizes  = isset( $detail['sizes'] ) && is_array( $detail['sizes'] ) ? $detail['sizes'] : array();
		$n      = 0;
		$in     = 0;
		$out    = 0;
		foreach ( $sizes as $rec ) {
			if ( isset( $rec['s'] ) && 'ok' === $rec['s'] ) {
				$n++;
				$in  += (int) $rec['in'];
				$out += (int) $rec['out'];
			}
		}

		switch ( $state ) {
			case 'excluded':
				echo '<p class="pload-mc-status">' . esc_html__( 'Excluded', 'pload' ) . '</p>';
				self::button( 'include', __( 'Include again', 'pload' ), false );
				break;

			case 'waiting':
				echo '<p class="pload-mc-status pload-mc-muted">' . esc_html__( 'Not optimized yet', 'pload' ) . '</p>';
				self::button( 'optimize', __( 'Optimize', 'pload' ), true );
				break;

			case 'failed':
				echo '<p class="pload-mc-status pload-mc-bad">' . esc_html__( 'Failed', 'pload' ) . '</p>';
				if ( ! empty( $detail['err'] ) ) {
					echo '<p class="pload-mc-note" dir="ltr">' . esc_html( $detail['err'] ) . '</p>';
				}
				self::button( 'optimize', __( 'Try again', 'pload' ), true );
				break;

			default: // current, outdated
				if ( $n ) {
					echo '<p class="pload-mc-status pload-mc-ok"><b><bdi>' . esc_html( size_format( $out, 1 ) ) . '</bdi></b> · ' . esc_html(
						sprintf(
							/* translators: %d: percent saved */
							__( '%d%% smaller', 'pload' ),
							(int) round( ( 1 - $out / max( 1, $in ) ) * 100 )
						)
					) . '</p>';
					echo '<p class="pload-mc-note">' . sprintf(
						/* translators: 1: original size, 2: number of image sizes */
						esc_html( _n( 'from %1$s, %2$d size', 'from %1$s, %2$d sizes', $n, 'pload' ) ),
						'<bdi>' . esc_html( size_format( $in, 1 ) ) . '</bdi>',
						(int) $n
					) . '</p>';
				} else {
					echo '<p class="pload-mc-status pload-mc-muted">' . esc_html__( 'No gain, kept original', 'pload' ) . '</p>';
				}
				if ( 'outdated' === $state ) {
					echo '<p class="pload-mc-note pload-mc-warn">' . esc_html__( 'Made with older settings', 'pload' ) . '</p>';
					self::button( 'optimize', __( 'Apply new settings', 'pload' ), true );
				}
		}

		if ( $sizes ) {
			self::details( $id, $sizes );
		}
		if ( 'excluded' !== $state ) {
			self::button( 'exclude', __( 'Don’t optimize', 'pload' ), false, 'link' );
		}
		echo '<span class="pload-mc-msg" aria-live="polite"></span>';
		echo '</div>';
	}

	private static function button( $op, $label, $primary, $style = '' ) {
		$cls = 'link' === $style ? 'button-link pload-mc-link' : 'button button-small' . ( $primary ? ' button-primary' : '' );
		echo '<button type="button" class="' . esc_attr( $cls ) . ' pload-mc-btn" data-op="' . esc_attr( $op ) . '">' . esc_html( $label ) . '</button>';
	}

	/** Expandable list: one line per image size. */
	private static function details( $id, $sizes ) {
		$meta  = wp_get_attachment_metadata( $id );
		$dims  = array();
		if ( is_array( $meta ) ) {
			$dims['full'] = isset( $meta['width'], $meta['height'] ) ? $meta['width'] . '×' . $meta['height'] : '';
			if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
				foreach ( $meta['sizes'] as $name => $s ) {
					$dims[ $name ] = $s['width'] . '×' . $s['height'];
				}
			}
		}

		echo '<details class="pload-mc-details"><summary>' . esc_html__( 'Details', 'pload' ) . '</summary><table><tbody>';
		foreach ( $sizes as $key => $rec ) {
			$name = isset( $dims[ $key ] ) && $dims[ $key ] ? $dims[ $key ] : $key;
			$s    = isset( $rec['s'] ) ? $rec['s'] : '';
			$html = '';
			$text = '';
			switch ( $s ) {
				case 'ok':
					$html = sprintf(
						/* translators: 1: original size, 2: WebP size, 3: percent saved */
						esc_html__( '%1$s → %2$s (−%3$d%%)', 'pload' ),
						'<bdi>' . esc_html( size_format( (int) $rec['in'], 1 ) ) . '</bdi>',
						'<bdi>' . esc_html( size_format( (int) $rec['out'], 1 ) ) . '</bdi>',
						(int) round( ( 1 - (int) $rec['out'] / max( 1, (int) $rec['in'] ) ) * 100 )
					);
					break;
				case 'larger':
					$text = __( 'WebP was not smaller; original kept', 'pload' );
					break;
				case 'big':
					$text = __( 'Too large for this server', 'pload' );
					break;
				case 'missing':
					$text = __( 'File missing', 'pload' );
					break;
				case 'error':
					$text = __( 'Failed', 'pload' ) . ( ! empty( $rec['e'] ) ? ': ' . $rec['e'] : '' );
					break;
				default:
					$text = '—';
			}
			echo '<tr class="is-' . esc_attr( $s ) . '"><th scope="row"><bdi>' . esc_html( $name ) . '</bdi></th><td>' . ( $html ? $html : esc_html( $text ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $html is built above from esc_html__() and esc_html() parts only.
		}
		echo '</tbody></table></details>';
	}

	/* ---------------------------------------------------------------------
	 * Actions
	 * ------------------------------------------------------------------- */

	/** One button from the column or the attachment details. */
	public function ajax() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( null, 403 );
		}
		check_ajax_referer( self::NONCE );
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
			wp_send_json_error( null, 400 );
		}

		$msg = '';
		if ( 'optimize' === $op ) {
			$r   = Pload_Queue::process_one( $id );
			$msg = self::result_text( $r );
		} elseif ( 'exclude' === $op ) {
			Pload_Converter::exclude( $id );
			Pload_Logger::log( 'info', sprintf( 'Attachment #%d excluded; its WebP files were removed.', $id ) );
		} elseif ( 'include' === $op ) {
			Pload_Converter::include_again( $id );
			Pload_Logger::log( 'info', sprintf( 'Attachment #%d included again.', $id ) );
			$msg = __( 'Put first in line.', 'pload' );
		} else {
			wp_send_json_error( null, 400 );
		}
		wp_cache_delete( 'counts', 'pload' );

		ob_start();
		self::cell( $id );
		wp_send_json_success(
			array(
				'html' => ob_get_clean(),
				'msg'  => $msg,
			)
		);
	}

	private static function result_text( $r ) {
		switch ( $r['result'] ) {
			case 'done':
				return $r['files'] ? '' : __( 'Already up to date.', 'pload' );
			case 'partial':
				return __( 'Part of this image was converted; the rest is first in line.', 'pload' );
			case 'queued':
				return __( 'Another step is running right now, so this image was put first in line.', 'pload' );
			case 'excluded':
				return __( 'This image is excluded (“Don’t optimize”).', 'pload' );
		}
		return __( 'Run the server test first (Media → Pload).', 'pload' );
	}

	public function bulk_action( $actions ) {
		if ( current_user_can( self::CAP ) ) {
			$actions['pload_now'] = __( 'Optimize now (Pload)', 'pload' );
		}
		return $actions;
	}

	public function handle_bulk( $redirect, $action, $ids ) {
		if ( 'pload_now' !== $action || ! current_user_can( self::CAP ) ) {
			return $redirect;
		}
		$ids = array_values(
			array_filter(
				array_map( 'intval', (array) $ids ),
				function ( $id ) {
					return in_array( Pload_Converter::state_of( $id ), array( 'waiting', 'failed', 'outdated' ), true );
				}
			)
		);
		if ( $ids ) {
			Pload_Queue::enqueue_front( $ids );
			Pload_Logger::log( 'info', sprintf( '%d images put first in line from the media library.', count( $ids ) ) );
		}
		set_transient( 'pload_now_' . get_current_user_id(), array( 'result' => 'bulk', 'files' => count( $ids ) ), MINUTE_IN_SECONDS * 5 );
		return $redirect;
	}

	/** Result of the bulk action. */
	public function now_notice() {
		$key = 'pload_now_' . get_current_user_id();
		$r   = get_transient( $key );
		if ( ! is_array( $r ) ) {
			return;
		}
		delete_transient( $key );
		$type = 'success';
		if ( 'bulk' === $r['result'] ) {
			/* translators: %s: number of images */
			$msg = sprintf( _n( 'Pload: %s image put first in line.', 'Pload: %s images put first in line.', $r['files'], 'pload' ), number_format_i18n( $r['files'] ) );
			if ( 'running' !== Pload_Queue::state() ) {
				$msg .= ' ' . __( 'Optimization is not running; start or resume it on the Pload page.', 'pload' );
				$type = 'warning';
			}
		} else {
			return;
		}
		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
	}

	/* ---------------------------------------------------------------------
	 * Attachment details (edit screen and media modal)
	 * ------------------------------------------------------------------- */

	public function attachment_fields( $fields, $post ) {
		if ( ! current_user_can( self::CAP ) || ! in_array( get_post_mime_type( $post ), Pload_Converter::MIMES, true ) ) {
			return $fields;
		}
		ob_start();
		self::cell( (int) $post->ID );
		$fields['pload_status'] = array(
			'label' => 'Pload',
			'input' => 'html',
			'html'  => ob_get_clean(),
		);
		return $fields;
	}

	/** Kept for forms saved by older Pload versions (the checkbox field). */
	public function attachment_field_save( $post, $attachment ) {
		if ( empty( $attachment['pload_present'] ) || ! current_user_can( self::CAP ) ) {
			return $post;
		}
		$id      = (int) $post['ID'];
		$want    = ! empty( $attachment['pload_exclude'] );
		$current = (bool) get_post_meta( $id, Pload_Converter::EXCLUDE, true );
		if ( $want && ! $current ) {
			Pload_Converter::exclude( $id );
		} elseif ( ! $want && $current ) {
			Pload_Converter::include_again( $id );
		}
		return $post;
	}
}
