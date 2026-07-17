<?php
/**
 * Admin UI: dashboard (status + manual runs) and settings.
 *
 * @package SmokePurer_Sync
 */

defined( 'ABSPATH' ) || exit;

class SPS_Admin {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_sps_save_settings', array( $this, 'handle_save' ) );
		add_action( 'admin_post_sps_run_now', array( $this, 'handle_run_now' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_notices', array( $this, 'health_notice' ) );
	}

	/**
	 * Show a warning in wp-admin if the stock sync has failed or gone stale, so a
	 * silent stoppage is noticed without opening the dashboard. Purely internal -
	 * it reads the recorded run status and makes no external calls.
	 */
	public function health_notice() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! SPS_Settings::get( 'enabled', true ) ) {
			return;
		}
		// Don't duplicate the warning on our own dashboard (it's shown there already).
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && false !== strpos( (string) $screen->id, 'smokepurer-sync' ) ) {
			return;
		}

		$runs = SPS_Logger::get_runs();
		if ( empty( $runs['stock'] ) ) {
			return; // Never run yet - don't nag a fresh install.
		}
		$run       = $runs['stock'];
		$interval  = max( 60, (int) SPS_Settings::get( 'stock_interval', 300 ) );
		$stale_sec = max( 900, ( $interval * 3 ) + 60 );
		$age       = time() - (int) $run['timestamp'];

		$message = '';
		if ( 'error' === ( $run['status'] ?? '' ) ) {
			$message = sprintf( 'The last stock sync failed: %s', (string) ( $run['message'] ?? '' ) );
		} elseif ( $age > $stale_sec ) {
			$message = sprintf(
				'Stock has not synced for %s - the scheduler may have stopped, so stock levels could be out of date.',
				human_time_diff( (int) $run['timestamp'], time() )
			);
		}
		if ( '' === $message ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p><strong>SmokePurer Sync:</strong> %s &nbsp;<a href="%s">View status</a></p></div>',
			esc_html( $message ),
			esc_url( admin_url( 'admin.php?page=smokepurer-sync' ) )
		);
	}

	public function menu() {
		add_submenu_page(
			'woocommerce',
			'SmokePurer Sync',
			'SmokePurer Sync',
			'manage_woocommerce',
			'smokepurer-sync',
			array( $this, 'render' )
		);
	}

	public function assets( $hook ) {
		if ( false === strpos( (string) $hook, 'smokepurer-sync' ) ) {
			return;
		}
		wp_enqueue_style( 'sps-admin', SPS_PLUGIN_URL . 'assets/admin.css', array(), SPS_VERSION );
	}

	public function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard'; // phpcs:ignore WordPress.Security.NonceVerification
		echo '<div class="wrap sps-wrap">';
		echo '<h1>SmokePurer Sync</h1>';
		$this->tabs( $tab );
		if ( 'settings' === $tab ) {
			$this->render_settings();
		} else {
			$this->render_dashboard();
		}
		echo '</div>';
	}

	private function tabs( $active ) {
		$tabs = array(
			'dashboard' => 'Dashboard',
			'settings'  => 'Settings',
		);
		echo '<h2 class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			$url   = admin_url( 'admin.php?page=smokepurer-sync&tab=' . $key );
			$class = 'nav-tab' . ( $active === $key ? ' nav-tab-active' : '' );
			printf( '<a href="%s" class="%s">%s</a>', esc_url( $url ), esc_attr( $class ), esc_html( $label ) );
		}
		echo '</h2>';
	}

	/* --------------------------------------------------------------------- */
	/* Dashboard                                                              */
	/* --------------------------------------------------------------------- */

	private function render_dashboard() {
		$runs = SPS_Logger::get_runs();

		echo '<h2>Job status</h2>';
		echo '<table class="widefat striped sps-table"><thead><tr>';
		echo '<th>Job</th><th>Last result</th><th>When</th><th>Next run</th><th>Details</th><th></th>';
		echo '</tr></thead><tbody>';

		$jobs = array(
			'stock'     => array( 'Stock sync', SPS_HOOK_STOCK ),
			'catalogue' => array( 'Catalogue import', SPS_HOOK_CATALOGUE ),
			'reconcile' => array( 'Reconcile (retire)', SPS_HOOK_RECONCILE ),
		);

		foreach ( $jobs as $key => $info ) {
			list( $label, $hook ) = $info;
			$run    = isset( $runs[ $key ] ) ? $runs[ $key ] : null;
			$status = $run ? $run['status'] : 'never';
			$when   = $run ? $this->ago( $run['timestamp'] ) : '—';
			$msg    = $run ? $run['message'] : 'Has not run yet.';
			$next   = $this->next_run( $hook );

			printf(
				'<tr><td><strong>%s</strong></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( $label ),
				$this->status_badge( $status ),
				esc_html( $when ),
				esc_html( $next ),
				esc_html( $msg ),
				$this->run_now_button( $key )
			);
		}
		echo '</tbody></table>';

		// Catalogue progress (if mid-cycle).
		if ( 'running' === get_option( 'sps_cat_state', '' ) ) {
			$processed = (int) get_option( 'sps_cat_processed', 0 );
			$total     = (int) get_option( 'sps_cat_total', 0 );
			printf( '<p class="sps-progress">Catalogue import in progress: %d / %d product groups processed.</p>', (int) $processed, (int) $total );
		}

		// Circuit-breaker baselines.
		$lastgood = get_option( SPS_Feed_Client::LASTGOOD_OPTION, array() );
		if ( is_array( $lastgood ) && $lastgood ) {
			echo '<h2>Feed baselines (circuit-breaker)</h2>';
			echo '<table class="widefat striped sps-table"><thead><tr><th>Feed</th><th>Last good row count</th><th>When</th></tr></thead><tbody>';
			foreach ( $lastgood as $feed => $data ) {
				printf(
					'<tr><td>%s</td><td>%s</td><td>%s</td></tr>',
					esc_html( $feed ),
					esc_html( number_format_i18n( (int) ( $data['rows'] ?? 0 ) ) ),
					esc_html( isset( $data['time'] ) ? $this->ago( (int) $data['time'] ) : '—' )
				);
			}
			echo '</tbody></table>';
		}

		$markup = (float) SPS_Settings::get( 'markup_percent', 0 );
		if ( $markup <= 0 ) {
			echo '<div class="notice notice-warning inline"><p><strong>Heads up:</strong> markup is 0% — products would import at the trade (cost) price. Set a markup in Settings before publishing.</p></div>';
		}

		echo '<p class="description">Detailed logs: <strong>WooCommerce → Status → Logs</strong> (source <code>smokepurer-sync</code>). Scheduled runs: <strong>WooCommerce → Status → Scheduled Actions</strong> (group <code>smokepurer-sync</code>).</p>';
	}

	private function run_now_button( $job ) {
		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=sps_run_now&job=' . $job ),
			'sps_run_now_' . $job
		);
		return sprintf( '<a href="%s" class="button button-secondary">Run now</a>', esc_url( $url ) );
	}

	private function status_badge( $status ) {
		$colors = array(
			'ok'      => '#008a20',
			'warning' => '#996800',
			'error'   => '#c00',
			'never'   => '#787c82',
		);
		$color = isset( $colors[ $status ] ) ? $colors[ $status ] : '#787c82';
		return sprintf( '<span class="sps-badge" style="background:%s">%s</span>', esc_attr( $color ), esc_html( ucfirst( $status ) ) );
	}

	private function next_run( $hook ) {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return '—';
		}
		$ts = as_next_scheduled_action( $hook, array(), 'smokepurer-sync' );
		if ( is_int( $ts ) && $ts > 0 ) {
			return 'in ' . human_time_diff( time(), $ts );
		}
		if ( true === $ts ) {
			return 'pending';
		}
		return 'not scheduled';
	}

	private function ago( $timestamp ) {
		$timestamp = (int) $timestamp;
		if ( $timestamp <= 0 ) {
			return '—';
		}
		return human_time_diff( $timestamp, time() ) . ' ago';
	}

	/* --------------------------------------------------------------------- */
	/* Settings                                                               */
	/* --------------------------------------------------------------------- */

	private function render_settings() {
		$s = SPS_Settings::all();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="sps_save_settings" />
			<?php wp_nonce_field( 'sps_save_settings' ); ?>

			<h2>Pricing</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="markup_percent">Markup %</label></th>
					<td>
						<input name="markup_percent" id="markup_percent" type="number" step="0.1" min="0" value="<?php echo esc_attr( $s['markup_percent'] ); ?>" class="small-text" /> %
						<p class="description">Applied to the trade price. e.g. 100 = double the cost. The result is written as the regular price and interpreted per your WooCommerce Tax settings (inclusive/exclusive of VAT).</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="rounding">Price rounding</label></th>
					<td>
						<?php $this->select( 'rounding', $s['rounding'], array(
							'charm99'     => 'Charm .99 (e.g. 8.99)',
							'charm95'     => 'Charm .95 (e.g. 8.95)',
							'nearest_5p'  => 'Nearest 5p',
							'nearest_10p' => 'Nearest 10p',
							'none'        => 'None (exact)',
						) ); ?>
					</td>
				</tr>
				<tr>
					<th scope="row">Margin guard</th>
					<td><label><input type="checkbox" name="min_margin_guard" value="1" <?php checked( $s['min_margin_guard'] ); ?> /> Never publish a retail price at or below the trade price.</label></td>
				</tr>
			</table>

			<h2>Product build</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="new_product_status">New products</label></th>
					<td>
						<?php $this->select( 'new_product_status', $s['new_product_status'], array(
							'draft'   => 'Create as Draft (review before publishing — recommended)',
							'publish' => 'Publish immediately',
						) ); ?>
						<p class="description">Draft lets you review category/compliance (age-restricted &amp; TPD products) before a new SKU goes live.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="attribute_label">Variation attribute label</label></th>
					<td><input name="attribute_label" id="attribute_label" type="text" value="<?php echo esc_attr( $s['attribute_label'] ); ?>" class="regular-text" />
						<p class="description">The feed only gives a bare variation value (e.g. "20MG"); this is the attribute name it is shown under.</p></td>
				</tr>
				<tr>
					<th scope="row">Images</th>
					<td><label><input type="checkbox" name="import_images" value="1" <?php checked( $s['import_images'] ); ?> /> Sideload product images (de-duplicated by source URL).</label></td>
				</tr>
				<tr>
					<th scope="row"><label for="image_throttle_ms">Image download throttle</label></th>
					<td>
						<input name="image_throttle_ms" id="image_throttle_ms" type="number" min="0" step="10" value="<?php echo esc_attr( $s['image_throttle_ms'] ); ?>" class="small-text" /> ms pause before each image download
						&nbsp;·&nbsp;
						<input name="image_retries" id="image_retries" type="number" min="0" max="10" value="<?php echo esc_attr( $s['image_retries'] ); ?>" class="small-text" /> retries
						&nbsp;·&nbsp;
						<input name="image_retry_backoff_ms" id="image_retry_backoff_ms" type="number" min="0" step="100" value="<?php echo esc_attr( $s['image_retry_backoff_ms'] ); ?>" class="small-text" /> ms backoff
						<p class="description">Slows the image import to avoid the supplier's server rate-limiting a big burst. Failed downloads retry with an exponential backoff; a genuine 404 is not retried. Set throttle to 0 to disable.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Categories</th>
					<td>
						<label><input type="checkbox" name="assign_categories" value="1" <?php checked( $s['assign_categories'] ); ?> /> Assign products to categories.</label>
						<p class="description">Untick to import products with <strong>no category</strong> (left unassigned). The mapping and fallback below are then ignored.</p>
						<label><input type="checkbox" name="auto_create_categories" value="1" <?php checked( $s['auto_create_categories'] ); ?> /> Auto-create mapped categories that don't exist yet.</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="category_fallback">Fallback category</label></th>
					<td><input name="category_fallback" id="category_fallback" type="text" value="<?php echo esc_attr( $s['category_fallback'] ); ?>" class="regular-text" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="category_map">Category mapping</label></th>
					<td>
						<textarea name="category_map" id="category_map" rows="8" class="large-text code" placeholder="Eliquid Nic Salt = Nic Salts&#10;Open Pod - Pod Kits = Pod Kits"><?php echo esc_textarea( $this->map_to_text( $s['category_map'] ) ); ?></textarea>
						<p class="description">One per line: <code>Feed Type = WooCommerce Category</code>. Matching ignores case and the "e-liquid/eliquid" spelling variants.</p>
					</td>
				</tr>
			</table>

			<h2>Ongoing updates</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Keep updating on re-imports</th>
					<td>
						<p class="description" style="margin-top:0">Newly discovered products always import in full. For products <strong>already imported</strong>, tick what should keep syncing from the feed on later runs.</p>
						<?php
						$eu = isset( $s['existing_updates'] ) && is_array( $s['existing_updates'] ) ? $s['existing_updates'] : array();
						foreach ( SPS_Settings::existing_update_fields() as $key => $label ) {
							printf(
								'<label style="display:inline-block;min-width:240px;margin:2px 0"><input type="checkbox" name="sync_%1$s" value="1" %2$s /> %3$s</label>',
								esc_attr( $key ),
								checked( ! empty( $eu[ $key ] ), true, false ),
								esc_html( $label )
							);
						}
						?>
						<p class="description">Anything unticked is left untouched once a product exists — including your own manual edits. <strong>Stock always syncs</strong> separately via the 5-minute job.</p>
					</td>
				</tr>
			</table>

			<h2>Retirement</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="retire_action">When a SKU is disabled</label></th>
					<td>
						<?php $this->select( 'retire_action', $s['retire_action'], array(
							'draft'      => 'Set product to Draft (hide from shop)',
							'outofstock' => 'Set Out of stock (keep visible)',
						) ); ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="retire_max_percent">Retirement safety cap</label></th>
					<td><input name="retire_max_percent" id="retire_max_percent" type="number" min="1" max="100" value="<?php echo esc_attr( $s['retire_max_percent'] ); ?>" class="small-text" /> %
						<p class="description">Abort a reconcile run if the disabled feed would retire more than this share of the catalogue in one go.</p></td>
				</tr>
			</table>

			<h2>Schedules &amp; safety</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="stock_interval">Stock sync every</label></th>
					<td><input name="stock_interval" id="stock_interval" type="number" min="60" step="60" value="<?php echo esc_attr( $s['stock_interval'] ); ?>" class="small-text" /> seconds <span class="description">(min 60; SmokePurer recommends 300 = 5 min)</span></td>
				</tr>
				<tr>
					<th scope="row"><label for="catalogue_interval">Catalogue import every</label></th>
					<td><input name="catalogue_interval" id="catalogue_interval" type="number" min="900" step="60" value="<?php echo esc_attr( $s['catalogue_interval'] ); ?>" class="small-text" /> seconds <span class="description">(min 900 = 15 min)</span></td>
				</tr>
				<tr>
					<th scope="row"><label for="reconcile_interval">Reconcile every</label></th>
					<td><input name="reconcile_interval" id="reconcile_interval" type="number" min="3600" step="600" value="<?php echo esc_attr( $s['reconcile_interval'] ); ?>" class="small-text" /> seconds</td>
				</tr>
				<tr>
					<th scope="row"><label for="breaker_percent">Circuit-breaker</label></th>
					<td>Abort a run if a snapshot feed shrinks by more than
						<input name="breaker_percent" id="breaker_percent" type="number" min="1" max="99" value="<?php echo esc_attr( $s['breaker_percent'] ); ?>" class="small-text" /> % vs the last good pull.</td>
				</tr>
				<tr>
					<th scope="row">Enabled</th>
					<td><label><input type="checkbox" name="enabled" value="1" <?php checked( $s['enabled'] ); ?> /> Run scheduled jobs.</label></td>
				</tr>
			</table>

			<h2>Feed URLs</h2>
			<table class="form-table" role="presentation">
				<?php
				$feeds = array(
					'feed_descriptions' => 'Product descriptions (catalogue)',
					'feed_coming_soon'  => 'Coming-soon / pre-orders',
					'feed_quantity'     => 'Quantity (stock)',
					'feed_images'       => 'Images',
					'feed_weights'      => 'Weights',
					'feed_tags'         => 'Tags (Sale / End of Line)',
					'feed_disabled_7d'  => 'Disabled — last 7 days',
				);
				foreach ( $feeds as $key => $label ) {
					printf(
						'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input name="%1$s" id="%1$s" type="url" value="%3$s" class="large-text code" /></td></tr>',
						esc_attr( $key ),
						esc_html( $label ),
						esc_attr( $s[ $key ] )
					);
				}
				?>
			</table>

			<?php submit_button( 'Save settings' ); ?>
		</form>
		<?php
	}

	private function select( $name, $current, array $options ) {
		printf( '<select name="%s" id="%s">', esc_attr( $name ), esc_attr( $name ) );
		foreach ( $options as $value => $label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $value ),
				selected( $current, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	/* --------------------------------------------------------------------- */
	/* Handlers                                                               */
	/* --------------------------------------------------------------------- */

	public function handle_save() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		check_admin_referer( 'sps_save_settings' );

		$in = wp_unslash( $_POST );

		$values = array(
			'enabled'                => ! empty( $in['enabled'] ),
			'markup_percent'         => isset( $in['markup_percent'] ) ? max( 0, (float) $in['markup_percent'] ) : 0,
			'rounding'               => isset( $in['rounding'] ) ? sanitize_key( $in['rounding'] ) : 'charm99',
			'min_margin_guard'       => ! empty( $in['min_margin_guard'] ),
			'new_product_status'     => ( isset( $in['new_product_status'] ) && 'publish' === $in['new_product_status'] ) ? 'publish' : 'draft',
			'attribute_label'        => isset( $in['attribute_label'] ) ? sanitize_text_field( $in['attribute_label'] ) : 'Options',
			'import_images'          => ! empty( $in['import_images'] ),
			'image_throttle_ms'      => isset( $in['image_throttle_ms'] ) ? min( 60000, max( 0, (int) $in['image_throttle_ms'] ) ) : 200,
			'image_retries'          => isset( $in['image_retries'] ) ? min( 10, max( 0, (int) $in['image_retries'] ) ) : 2,
			'image_retry_backoff_ms' => isset( $in['image_retry_backoff_ms'] ) ? min( 30000, max( 0, (int) $in['image_retry_backoff_ms'] ) ) : 1000,
			'assign_categories'      => ! empty( $in['assign_categories'] ),
			'auto_create_categories' => ! empty( $in['auto_create_categories'] ),
			'existing_updates'       => $this->collect_existing_updates( $in ),
			'category_fallback'      => isset( $in['category_fallback'] ) ? sanitize_text_field( $in['category_fallback'] ) : 'Uncategorised',
			'category_map'           => isset( $in['category_map'] ) ? $this->text_to_map( $in['category_map'] ) : array(),
			'retire_action'          => ( isset( $in['retire_action'] ) && 'outofstock' === $in['retire_action'] ) ? 'outofstock' : 'draft',
			'retire_max_percent'     => isset( $in['retire_max_percent'] ) ? min( 100, max( 1, (int) $in['retire_max_percent'] ) ) : 20,
			'stock_interval'         => isset( $in['stock_interval'] ) ? max( 60, (int) $in['stock_interval'] ) : 300,
			'catalogue_interval'     => isset( $in['catalogue_interval'] ) ? max( 900, (int) $in['catalogue_interval'] ) : 3600,
			'reconcile_interval'     => isset( $in['reconcile_interval'] ) ? max( 3600, (int) $in['reconcile_interval'] ) : DAY_IN_SECONDS,
			'breaker_percent'        => isset( $in['breaker_percent'] ) ? min( 99, max( 1, (int) $in['breaker_percent'] ) ) : 15,
		);

		foreach ( array_keys( SPS_Settings::defaults() ) as $feed_key ) {
			if ( 0 === strpos( $feed_key, 'feed_' ) && isset( $in[ $feed_key ] ) ) {
				$values[ $feed_key ] = esc_url_raw( trim( (string) $in[ $feed_key ] ) );
			}
		}

		SPS_Settings::update( $values );
		SPS_Plugin::reschedule();

		wp_safe_redirect( add_query_arg( array( 'page' => 'smokepurer-sync', 'tab' => 'settings', 'updated' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_run_now() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		$job = isset( $_GET['job'] ) ? sanitize_key( wp_unslash( $_GET['job'] ) ) : '';
		check_admin_referer( 'sps_run_now_' . $job );

		$hooks = array(
			'stock'     => SPS_HOOK_STOCK,
			'catalogue' => SPS_HOOK_CATALOGUE,
			'reconcile' => SPS_HOOK_RECONCILE,
		);
		if ( isset( $hooks[ $job ] ) ) {
			if ( function_exists( 'as_enqueue_async_action' ) ) {
				as_enqueue_async_action( $hooks[ $job ], array(), 'smokepurer-sync' );
			} else {
				do_action( $hooks[ $job ] );
			}
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'smokepurer-sync', 'tab' => 'dashboard', 'queued' => $job ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/* --------------------------------------------------------------------- */
	/* Category-map serialisation helpers                                     */
	/* --------------------------------------------------------------------- */

	private function collect_existing_updates( $in ) {
		$out = array();
		foreach ( array_keys( SPS_Settings::existing_update_fields() ) as $key ) {
			$out[ $key ] = ! empty( $in[ 'sync_' . $key ] );
		}
		return $out;
	}

	private function map_to_text( $map ) {
		if ( ! is_array( $map ) ) {
			return '';
		}
		$lines = array();
		foreach ( $map as $type => $cat ) {
			$lines[] = $type . ' = ' . $cat;
		}
		return implode( "\n", $lines );
	}

	private function text_to_map( $text ) {
		$map   = array();
		$lines = preg_split( '/\r\n|\r|\n/', (string) $text );
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line || false === strpos( $line, '=' ) ) {
				continue;
			}
			list( $type, $cat ) = array_map( 'trim', explode( '=', $line, 2 ) );
			if ( '' !== $type && '' !== $cat ) {
				$map[ sanitize_text_field( $type ) ] = sanitize_text_field( $cat );
			}
		}
		return $map;
	}
}
