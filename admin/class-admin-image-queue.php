<?php
/**
 * Admin: image queue monitor.
 *
 * @package Alorbach\AIGateway\Admin
 */

namespace Alorbach\AIGateway\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Admin_Image_Queue
 */
class Admin_Image_Queue {

	/**
	 * Render image queue page.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'alorbach-ai-gateway' ) );
		}

		$rest_base = get_option( 'permalink_structure' )
			? rest_url( 'alorbach/v1/admin/image-jobs' )
			: home_url( '/index.php?rest_route=/alorbach/v1/admin/image-jobs' );
		$nonce     = wp_create_nonce( 'wp_rest' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Image Queue', 'alorbach-ai-gateway' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Monitor recent image jobs, active queue state, and per-job details. The list refreshes automatically.', 'alorbach-ai-gateway' ); ?></p>

			<div id="alorbach-image-queue-app" class="alorbach-image-queue">
				<div class="alorbach-image-queue__stats">
					<div class="alorbach-image-queue__stat"><span><?php esc_html_e( 'Recent Jobs', 'alorbach-ai-gateway' ); ?></span><strong data-stat="total">0</strong></div>
					<div class="alorbach-image-queue__stat"><span><?php esc_html_e( 'Queued', 'alorbach-ai-gateway' ); ?></span><strong data-stat="queued">0</strong></div>
					<div class="alorbach-image-queue__stat"><span><?php esc_html_e( 'In Progress', 'alorbach-ai-gateway' ); ?></span><strong data-stat="in_progress">0</strong></div>
					<div class="alorbach-image-queue__stat"><span><?php esc_html_e( 'Completed', 'alorbach-ai-gateway' ); ?></span><strong data-stat="completed">0</strong></div>
					<div class="alorbach-image-queue__stat"><span><?php esc_html_e( 'Failed', 'alorbach-ai-gateway' ); ?></span><strong data-stat="failed">0</strong></div>
				</div>

				<div class="alorbach-image-queue__status">
					<span data-role="status"><?php esc_html_e( 'Loading queue…', 'alorbach-ai-gateway' ); ?></span>
					<button type="button" class="button" data-role="refresh"><?php esc_html_e( 'Refresh', 'alorbach-ai-gateway' ); ?></button>
				</div>

				<div class="alorbach-image-queue__layout">
					<div class="alorbach-image-queue__panel">
						<table class="widefat striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Job', 'alorbach-ai-gateway' ); ?></th>
									<th><?php esc_html_e( 'User', 'alorbach-ai-gateway' ); ?></th>
									<th><?php esc_html_e( 'Model', 'alorbach-ai-gateway' ); ?></th>
									<th><?php esc_html_e( 'Status', 'alorbach-ai-gateway' ); ?></th>
									<th><?php esc_html_e( 'Progress', 'alorbach-ai-gateway' ); ?></th>
									<th><?php esc_html_e( 'Updated', 'alorbach-ai-gateway' ); ?></th>
								</tr>
							</thead>
							<tbody data-role="rows">
								<tr><td colspan="6"><?php esc_html_e( 'Loading…', 'alorbach-ai-gateway' ); ?></td></tr>
							</tbody>
						</table>
					</div>

					<div class="alorbach-image-queue__panel alorbach-image-queue__details" data-role="details">
						<h2><?php esc_html_e( 'Job Details', 'alorbach-ai-gateway' ); ?></h2>
						<p class="description"><?php esc_html_e( 'Select a job to inspect its request, timing, previews, final output, and any error state.', 'alorbach-ai-gateway' ); ?></p>
					</div>
				</div>
			</div>
		</div>

		<style>
			.alorbach-image-queue__stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(120px,1fr)); gap:12px; margin:16px 0; }
			.alorbach-image-queue__stat { background:#fff; border:1px solid #dcdcde; border-radius:8px; padding:12px; }
			.alorbach-image-queue__stat span { display:block; color:#50575e; margin-bottom:6px; }
			.alorbach-image-queue__stat strong { font-size:20px; }
			.alorbach-image-queue__status { display:flex; gap:12px; align-items:center; margin-bottom:12px; }
			.alorbach-image-queue__layout { display:grid; grid-template-columns: minmax(0, 1.5fr) minmax(320px, 1fr); gap:16px; align-items:start; }
			.alorbach-image-queue__panel { background:#fff; border:1px solid #dcdcde; border-radius:8px; padding:12px; }
			.alorbach-image-queue__row.is-selected { background:#eef4ff; }
			.alorbach-image-queue__row button { background:none; border:0; color:#2271b1; cursor:pointer; padding:0; text-align:left; }
			.alorbach-image-queue__meta { display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:10px; margin:12px 0; }
			.alorbach-image-queue__meta div { background:#f6f7f7; border-radius:6px; padding:10px; }
			.alorbach-image-queue__meta span { display:block; color:#50575e; margin-bottom:4px; }
			.alorbach-image-queue__prompt { white-space:pre-wrap; background:#f6f7f7; border-radius:6px; padding:10px; }
			.alorbach-image-queue__thumbs { display:flex; flex-wrap:wrap; gap:8px; margin-top:10px; }
			.alorbach-image-queue__thumbs img { width:88px; height:88px; object-fit:cover; border-radius:6px; border:1px solid #dcdcde; cursor:pointer; transition:transform .18s ease, box-shadow .18s ease, border-color .18s ease; }
			.alorbach-image-queue__thumbs img:hover, .alorbach-image-queue__thumbs img:focus { transform:translateY(-1px); box-shadow:0 10px 24px rgba(34, 113, 177, 0.18); border-color:#2271b1; outline:none; }
			.alorbach-image-queue__error { color:#b32d2e; }
			.alorbach-image-queue__lightbox { position:fixed; inset:0; z-index:100000; display:none; align-items:center; justify-content:center; padding:32px; background:rgba(15, 23, 42, 0.82); }
			.alorbach-image-queue__lightbox.is-open { display:flex; }
			.alorbach-image-queue__lightbox-backdrop { position:absolute; inset:0; }
			.alorbach-image-queue__lightbox img { position:relative; max-width:min(1200px, 92vw); max-height:90vh; border-radius:10px; box-shadow:0 24px 80px rgba(0,0,0,0.45); background:#fff; }
			@media (max-width: 960px) { .alorbach-image-queue__layout { grid-template-columns:1fr; } }
		</style>

		<script>
			(function () {
				var app = document.getElementById('alorbach-image-queue-app');
				if (!app) return;

				var restBase = <?php echo wp_json_encode( $rest_base ); ?>;
				var nonce = <?php echo wp_json_encode( $nonce ); ?>;
				var rowsEl = app.querySelector('[data-role="rows"]');
				var detailsEl = app.querySelector('[data-role="details"]');
				var statusEl = app.querySelector('[data-role="status"]');
				var refreshBtn = app.querySelector('[data-role="refresh"]');
				var selectedJobId = null;
				var refreshTimer = null;
				var lightboxEl = null;

				function api(path) {
					return fetch(restBase + path, {
						headers: { 'X-WP-Nonce': nonce },
						credentials: 'same-origin'
					}).then(function (response) {
						if (!response.ok) {
							return response.json().then(function (data) { throw data; });
						}
						return response.json();
					});
				}

				function formatDate(unixTs) {
					if (!unixTs) return '—';
					var date = new Date(unixTs * 1000);
					return date.toLocaleString();
				}

				function formatDuration(seconds) {
					if (seconds === null || seconds === undefined || seconds < 0) return '—';
					if (seconds < 60) return seconds + 's';
					var mins = Math.floor(seconds / 60);
					var secs = seconds % 60;
					return mins + 'm ' + secs + 's';
				}

				function escapeHtml(value) {
					return String(value || '').replace(/[&<>\"']/g, function (char) {
						return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '\"': '&quot;', '\'': '&#039;' })[char];
					});
				}

				function setStats(stats) {
					['total', 'queued', 'in_progress', 'completed', 'failed'].forEach(function (key) {
						var el = app.querySelector('[data-stat="' + key + '"]');
						if (el) el.textContent = String(stats[key] || 0);
					});
				}

				function ensureLightbox() {
					if (lightboxEl) return lightboxEl;
					lightboxEl = document.createElement('div');
					lightboxEl.className = 'alorbach-image-queue__lightbox';
					lightboxEl.innerHTML = '<div class="alorbach-image-queue__lightbox-backdrop"></div><img src="" alt="">';
					lightboxEl.querySelector('.alorbach-image-queue__lightbox-backdrop').addEventListener('click', closeLightbox);
					document.body.appendChild(lightboxEl);
					return lightboxEl;
				}

				function openLightbox(src, alt) {
					var lightbox = ensureLightbox();
					var image = lightbox.querySelector('img');
					image.src = src;
					image.alt = alt || '';
					lightbox.classList.add('is-open');
					document.body.style.overflow = 'hidden';
				}

				function closeLightbox() {
					if (!lightboxEl) return;
					lightboxEl.classList.remove('is-open');
					document.body.style.overflow = '';
				}

				function bindThumbClicks(scope) {
					Array.prototype.forEach.call(scope.querySelectorAll('[data-fullsize-src]'), function (thumb) {
						thumb.addEventListener('click', function () {
							openLightbox(thumb.getAttribute('data-fullsize-src'), thumb.getAttribute('alt') || '');
						});
						thumb.addEventListener('keydown', function (event) {
							if (event.key === 'Enter' || event.key === ' ') {
								event.preventDefault();
								openLightbox(thumb.getAttribute('data-fullsize-src'), thumb.getAttribute('alt') || '');
							}
						});
					});
				}

				function renderRows(payload) {
					var jobs = payload.jobs || [];
					setStats(payload.stats || {});
					if (!jobs.length) {
						rowsEl.innerHTML = '<tr><td colspan="6">No image jobs found.</td></tr>';
						detailsEl.innerHTML = '<h2>Job Details</h2><p class="description">Select a job to inspect its request, timing, previews, final output, and any error state.</p>';
						return;
					}

					rowsEl.innerHTML = jobs.map(function (job) {
						var selected = selectedJobId === job.job_id ? ' is-selected' : '';
						return '<tr class="alorbach-image-queue__row' + selected + '">' +
							'<td><button type="button" data-job-id="' + escapeHtml(job.job_id) + '">' + escapeHtml(job.job_id.slice(0, 8) + '…') + '</button></td>' +
							'<td>' + escapeHtml(job.user_label) + '</td>' +
							'<td>' + escapeHtml(job.model) + '</td>' +
							'<td>' + escapeHtml(job.status_label) + '</td>' +
							'<td>' + job.progress_percent + '%</td>' +
							'<td>' + escapeHtml(job.updated_at_label) + '</td>' +
							'</tr>';
					}).join('');

					Array.prototype.forEach.call(rowsEl.querySelectorAll('[data-job-id]'), function (button) {
						button.addEventListener('click', function () {
							selectedJobId = button.getAttribute('data-job-id');
							loadDetails(selectedJobId, true);
						});
					});

					if (!selectedJobId) {
						selectedJobId = jobs[0].job_id;
					}
				}

				function renderThumbs(items) {
					if (!items || !items.length) return '<p>None</p>';
					return '<div class="alorbach-image-queue__thumbs">' + items.slice(0, 4).map(function (item, index) {
						var src = item.url || (item.b64_json ? ('data:image/png;base64,' + item.b64_json) : '');
						if (!src) return '';
						return '<img src="' + escapeHtml(src) + '" data-fullsize-src="' + escapeHtml(src) + '" alt="Image ' + (index + 1) + '" tabindex="0">';
					}).join('') + '</div>';
				}

				function renderDetails(job) {
					detailsEl.innerHTML =
						'<h2>Job Details</h2>' +
						'<div class="alorbach-image-queue__meta">' +
							'<div><span>Status</span><strong>' + escapeHtml(job.status_label) + '</strong></div>' +
							'<div><span>Progress</span><strong>' + job.progress_percent + '%</strong></div>' +
							'<div><span>User</span><strong>' + escapeHtml(job.user_label) + '</strong></div>' +
							'<div><span>Model</span><strong>' + escapeHtml(job.model) + '</strong></div>' +
							'<div><span>Size</span><strong>' + escapeHtml(job.size) + '</strong></div>' +
							'<div><span>Quality</span><strong>' + escapeHtml(job.quality) + '</strong></div>' +
							'<div><span>Images</span><strong>' + job.n + '</strong></div>' +
							'<div><span>Mode</span><strong>' + escapeHtml(job.progress_mode) + '</strong></div>' +
							'<div><span>Created</span><strong>' + escapeHtml(job.created_at_label) + '</strong></div>' +
							'<div><span>Updated</span><strong>' + escapeHtml(job.updated_at_label) + '</strong></div>' +
							'<div><span>Runtime</span><strong>' + formatDuration(job.runtime_seconds) + '</strong></div>' +
							'<div><span>Credits</span><strong>' + escapeHtml(job.cost_credits_label) + '</strong></div>' +
						'</div>' +
						'<h3>Original Prompt</h3>' +
						'<div class="alorbach-image-queue__prompt">' + escapeHtml(job.original_prompt || job.prompt || '') + '</div>' +
						'<h3>Prompt</h3>' +
						'<div class="alorbach-image-queue__prompt">' + escapeHtml(job.prompt) + '</div>' +
						(job.error ? '<p class="alorbach-image-queue__error"><strong>Error:</strong> ' + escapeHtml(job.error) + '</p>' : '') +
						'<h3>Preview Frames</h3>' + renderThumbs(job.preview_images) +
						'<h3>Final Images</h3>' + renderThumbs(job.final_images);
					bindThumbClicks(detailsEl);
				}

				function loadDetails(jobId, rerenderRows) {
					statusEl.textContent = 'Loading job details…';
					api('/' + encodeURIComponent(jobId)).then(function (job) {
						renderDetails(job);
						statusEl.textContent = 'Queue updated ' + new Date().toLocaleTimeString();
						if (rerenderRows) {
							loadList();
						}
					}).catch(function () {
						statusEl.textContent = 'Failed to load job details.';
					});
				}

				function loadList() {
					statusEl.textContent = 'Loading queue…';
					api('').then(function (payload) {
						renderRows(payload);
						statusEl.textContent = 'Queue updated ' + new Date().toLocaleTimeString();
						if (selectedJobId) {
							return api('/' + encodeURIComponent(selectedJobId)).then(renderDetails);
						}
					}).catch(function () {
						rowsEl.innerHTML = '<tr><td colspan="6">Failed to load queue.</td></tr>';
						statusEl.textContent = 'Failed to load queue.';
					});
				}

				refreshBtn.addEventListener('click', loadList);
				document.addEventListener('keydown', function (event) {
					if (event.key === 'Escape') {
						closeLightbox();
					}
				});
				loadList();
				refreshTimer = window.setInterval(loadList, 5000);
				window.addEventListener('beforeunload', function () {
					if (refreshTimer) window.clearInterval(refreshTimer);
					closeLightbox();
				});
			}());
		</script>
		<?php
	}
}
