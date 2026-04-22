/**
 * Demo pages shared JavaScript.
 *
 * @package Alorbach\AIGateway
 */

(function () {
	'use strict';

	var config = window.alorbachDemo || {};
	var restUrl = config.restUrl || '';
	var nonce = config.nonce || '';
	var lightboxState = {
		items: [],
		index: 0,
		keyHandlerBound: false
	};

	function buildApiUrl(endpoint) {
		var base = restUrl.replace(/\/$/, '');
		// When using index.php?rest_route=..., endpoint query params must use & not ?
		// Otherwise rest_route value gets corrupted (e.g. /me/estimate?type=image)
		return base + (base.indexOf('?') !== -1 && endpoint.indexOf('?') !== -1
			? endpoint.replace('?', '&')
			: endpoint);
	}

	function apiFetch(endpoint, options) {
		var url = buildApiUrl(endpoint);
		var opts = Object.assign({
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': nonce,
			},
			credentials: 'same-origin',
		}, options || {});
		if (opts.body && typeof opts.body === 'object' && !(opts.body instanceof FormData) && !(opts.body instanceof Blob)) {
			opts.body = JSON.stringify(opts.body);
		}
		return fetch(url, opts);
	}

	function getBalance(container) {
		apiFetch('/me/balance').then(function (r) { return r.json(); }).then(function (data) {
			if (data.balance_credits !== undefined) {
				var el = container.querySelector('.alorbach-demo-balance');
				if (el) {
					var txt = data.balance_credits.toFixed(2) + ' ' + (config.creditsLabel || 'Credits');
					if (data.balance_usd !== undefined) txt += ' ($' + data.balance_usd.toFixed(2) + ')';
					el.textContent = txt;
				}
			}
		}).catch(function () {});
	}

	function getModels(container) {
		return apiFetch('/me/models').then(function (r) { return r.json(); });
	}

	function updateCostEstimate(container, type, params, costEl) {
		if (!costEl) return;
		var wrap = costEl.parentElement && costEl.parentElement.classList && costEl.parentElement.classList.contains('alorbach-demo-cost-wrap') ? costEl.parentElement : null;
		var qs = Object.keys(params).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); }).join('&');
		apiFetch('/me/estimate?' + qs).then(function (r) { return r.json(); }).then(function (data) {
			if (data.cost_credits !== undefined) {
				var credits = data.cost_credits.toFixed(2) + ' ' + (config.creditsLabel || 'Credits');
				var usd = data.cost_usd !== undefined ? ' ($' + data.cost_usd.toFixed(2) + ')' : '';
				costEl.textContent = (config.costLabel || 'Cost: ') + credits + usd;
				costEl.style.display = '';
				if (wrap) wrap.style.display = '';
			}
		}).catch(function () {
			costEl.style.display = 'none';
			if (wrap) wrap.style.display = 'none';
		});
	}

	function handleError(err, container) {
		var msg = err.message || (err.error && err.error.message) || (err.code || '') + ' ' + (err.data && err.data.message || '') || 'Request failed';
		var el = container.querySelector('.alorbach-demo-error');
		if (el) {
			el.textContent = msg;
			el.style.display = 'block';
		}
		if (err.data && err.data.debug) {
			console.log('[alorbach-video] Debug:', err.data.debug);
		}
	}

	function clearError(container) {
		var el = container.querySelector('.alorbach-demo-error');
		if (el) el.style.display = 'none';
	}

	function downloadFileName(item, fallback) {
		if (item && item.downloadName) {
			return item.downloadName;
		}
		return fallback || 'image.png';
	}

	function updateLightboxView() {
		var lb = document.getElementById('alorbach-demo-lightbox');
		var item = lightboxState.items[lightboxState.index];
		if (!lb || !item) return;
		lb.querySelector('.alorbach-demo-lightbox-img').src = item.src;
		lb.querySelector('.alorbach-demo-lightbox-img').alt = item.alt || '';
		lb.querySelector('.alorbach-demo-lightbox-download').href = item.src;
		lb.querySelector('.alorbach-demo-lightbox-download').download = downloadFileName(item, 'image-' + (lightboxState.index + 1) + '.png');
		lb.querySelector('.alorbach-demo-lightbox-count').textContent = (lightboxState.index + 1) + ' / ' + lightboxState.items.length;
		lb.querySelector('.alorbach-demo-lightbox-prev').disabled = lightboxState.items.length <= 1;
		lb.querySelector('.alorbach-demo-lightbox-next').disabled = lightboxState.items.length <= 1;
	}

	function navigateLightbox(direction) {
		if (!lightboxState.items.length) return;
		var total = lightboxState.items.length;
		lightboxState.index = (lightboxState.index + direction + total) % total;
		updateLightboxView();
	}

	function ensureLightbox() {
		var lb = document.getElementById('alorbach-demo-lightbox');
		if (lb) {
			return lb;
		}
		lb = document.createElement('div');
		lb.id = 'alorbach-demo-lightbox';
		lb.className = 'alorbach-demo-lightbox';
		lb.innerHTML = '<div class="alorbach-demo-lightbox-backdrop"></div><div class="alorbach-demo-lightbox-shell"><button type="button" class="alorbach-demo-lightbox-nav alorbach-demo-lightbox-prev" aria-label="Previous image">‹</button><img class="alorbach-demo-lightbox-img" src="" alt=""><button type="button" class="alorbach-demo-lightbox-nav alorbach-demo-lightbox-next" aria-label="Next image">›</button><div class="alorbach-demo-lightbox-toolbar"><span class="alorbach-demo-lightbox-count"></span><a class="alorbach-demo-lightbox-download button button-primary" href="" download>Download</a></div></div>';
		lb.querySelector('.alorbach-demo-lightbox-backdrop').addEventListener('click', closeLightbox);
		lb.querySelector('.alorbach-demo-lightbox-prev').addEventListener('click', function () { navigateLightbox(-1); });
		lb.querySelector('.alorbach-demo-lightbox-next').addEventListener('click', function () { navigateLightbox(1); });
		if (!lightboxState.keyHandlerBound) {
			document.addEventListener('keydown', function (e) {
				if (!lb.classList.contains('open')) return;
				if (e.key === 'Escape') {
					closeLightbox();
				} else if (e.key === 'ArrowLeft') {
					e.preventDefault();
					navigateLightbox(-1);
				} else if (e.key === 'ArrowRight') {
					e.preventDefault();
					navigateLightbox(1);
				}
			});
			lightboxState.keyHandlerBound = true;
		}
		document.body.appendChild(lb);
		return lb;
	}

	function openLightbox(items, index) {
		var galleryItems = Array.isArray(items) ? items.filter(function (item) { return item && item.src; }) : [];
		if (!galleryItems.length) return;
		lightboxState.items = galleryItems;
		lightboxState.index = Math.max(0, Math.min(index || 0, galleryItems.length - 1));
		var lb = ensureLightbox();
		updateLightboxView();
		lb.classList.add('open');
		document.body.style.overflow = 'hidden';
	}

	function closeLightbox() {
		var lb = document.getElementById('alorbach-demo-lightbox');
		if (lb) {
			lb.classList.remove('open');
			document.body.style.overflow = '';
		}
	}

	// Chat demo
	function initChat(container) {
		if (!container) return;
		getBalance(container);
		var messagesEl = container.querySelector('.alorbach-demo-messages');
		var inputEl = container.querySelector('.alorbach-demo-input');
		var sendBtn = container.querySelector('.alorbach-demo-send');
		var modelSelect = container.querySelector('.alorbach-demo-model-select');
		var maxTokensSelect = container.querySelector('.alorbach-demo-max-tokens');

		getModels(container).then(function (models) {
			var text = models.text || {};
			var wrap = container.querySelector('.alorbach-demo-model-wrap');
			if (wrap) wrap.style.display = text.allow_select ? '' : 'none';
			if (text.allow_select && text.options && text.options.length) {
				modelSelect.innerHTML = '';
				text.options.forEach(function (opt) {
					var o = document.createElement('option');
					o.value = opt;
					o.textContent = opt;
					if (opt === text.default) o.selected = true;
					modelSelect.appendChild(o);
				});
			}
			container.dataset.model = text.default || 'gpt-4.1-mini';
			var mt = text.max_tokens || {};
			if (maxTokensSelect && mt.options && mt.options.length) {
				maxTokensSelect.innerHTML = '';
				mt.options.forEach(function (val) {
					var o = document.createElement('option');
					o.value = val;
					o.textContent = val;
					if (val === mt.default) o.selected = true;
					maxTokensSelect.appendChild(o);
				});
			} else if (maxTokensSelect && !maxTokensSelect.options.length) {
				[256, 512, 1024, 2048, 4096, 8192].forEach(function (val) {
					var o = document.createElement('option');
					o.value = String(val);
					o.textContent = String(val);
					if (val === 1024) o.selected = true;
					maxTokensSelect.appendChild(o);
				});
			}
		});

			function appendMessage(role, content, usageInfo) {
			var div = document.createElement('div');
			div.className = 'alorbach-demo-message ' + role;
			var html = '<div class="role">' + role + '</div><div class="content">' + (content || '').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '<br>') + '</div>';
			if (usageInfo) html += '<div class="alorbach-demo-usage">' + usageInfo + '</div>';
			div.innerHTML = html;
			messagesEl.appendChild(div);
			messagesEl.scrollTop = messagesEl.scrollHeight;
		}

		function send() {
			var text = (inputEl && inputEl.value || '').trim();
			if (!text) return;
			clearError(container);
			var model = (modelSelect && modelSelect.value) || container.dataset.model || 'gpt-4.1-mini';
			var maxTokens = (maxTokensSelect && parseInt(maxTokensSelect.value, 10)) || 1024;
			var messages = [];
			var msgEls = container.querySelectorAll('.alorbach-demo-message');
			msgEls.forEach(function (m) {
				var role = m.classList.contains('user') ? 'user' : 'assistant';
				var content = (m.querySelector('.content') || m).textContent || '';
				if (content) messages.push({ role: role, content: content });
			});
			messages.push({ role: 'user', content: text });
			appendMessage('user', text);
			if (inputEl) inputEl.value = '';
			container.classList.add('alorbach-demo-loading');
			if (sendBtn) sendBtn.disabled = true;

			apiFetch('/chat', {
				method: 'POST',
				body: { messages: messages, model: model, max_tokens: maxTokens },
			}).then(function (r) {
				if (!r.ok) return r.json().then(function (d) { throw d; });
				return r.json();
			}).then(function (data) {
				var content = data.choices && data.choices[0] && data.choices[0].message && data.choices[0].message.content || '';
				var usageParts = [];
				if (data.usage) {
					var pt = data.usage.prompt_tokens || 0;
					var ct = data.usage.completion_tokens || 0;
					if (pt || ct) usageParts.push((pt + ct) + ' tokens');
				}
				if (data.cost_credits !== undefined) {
					var costTxt = data.cost_credits.toFixed(2) + ' ' + (config.creditsLabel || 'Credits');
					if (data.cost_usd !== undefined) costTxt += ' ($' + data.cost_usd.toFixed(2) + ')';
					usageParts.push(costTxt);
				}
				var usageInfo = usageParts.length ? usageParts.join(' | ') : '';
				appendMessage('assistant', content, usageInfo);
				getBalance(container);
			}).catch(function (err) {
				handleError(err, container);
			}).finally(function () {
				container.classList.remove('alorbach-demo-loading');
				if (sendBtn) sendBtn.disabled = false;
			});
		}

		if (sendBtn) sendBtn.addEventListener('click', send);
		if (inputEl) inputEl.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); } });
	}

	// Image demo
	function initImage(container) {
		if (!container) return;
		getBalance(container);
		var promptEl = container.querySelector('.alorbach-demo-prompt');
		var sizeSelect = container.querySelector('.alorbach-demo-size-select');
		var nInput = container.querySelector('.alorbach-demo-n');
		var genBtn = container.querySelector('.alorbach-demo-generate');
		var resultEl = container.querySelector('.alorbach-demo-images');

		var qualitySelect = container.querySelector('.alorbach-demo-quality-select');
		var qualityWrap = container.querySelector('.alorbach-demo-quality-wrap');

		var modelSelect = container.querySelector('.alorbach-demo-model-select');
		var modelWrap = container.querySelector('.alorbach-demo-model-wrap');
		var progressCard = container.querySelector('.alorbach-demo-progress-card');
		var progressTitle = container.querySelector('.alorbach-demo-progress-title');
		var progressMode = container.querySelector('.alorbach-demo-progress-mode');
		var progressStage = container.querySelector('.alorbach-demo-progress-stage');
		var progressValue = container.querySelector('.alorbach-demo-progress-value');
		var progressBar = container.querySelector('.alorbach-demo-progress-bar');
		var progressFill = container.querySelector('.alorbach-demo-progress-fill');
		var previewRail = container.querySelector('.alorbach-demo-preview-rail');
		var previewImages = container.querySelector('.alorbach-demo-preview-images');
		var labels = config.imageProgressLabels || {};
		var progressTimer = null;
		var pollTimer = null;
		var currentProgress = 0;
		var imageConfig = null;
		var activeJobId = null;
		var streamSettled = false;

		function isCodexImageModel(model) {
			return String(model || '').indexOf('codex-image-') === 0;
		}

		function getAllowedQualityOptions(model, qualityConfig) {
			var options = (qualityConfig && qualityConfig.options && qualityConfig.options.length)
				? qualityConfig.options.slice()
				: ['low', 'medium', 'high'];
			if (isCodexImageModel(model)) {
				options = options.filter(function (opt) { return opt !== 'low'; });
			}
			return options.length ? options : ['medium', 'high'];
		}

		function getDefaultQualityForModel(model, qualityConfig) {
			var options = getAllowedQualityOptions(model, qualityConfig);
			if (isCodexImageModel(model)) {
				return options.indexOf('high') !== -1 ? 'high' : options[0];
			}
			var configuredDefault = (qualityConfig && qualityConfig.default) || 'medium';
			return options.indexOf(configuredDefault) !== -1 ? configuredDefault : options[0];
		}

		function syncQualityOptions() {
			if (!qualitySelect) return;
			var q = imageConfig && imageConfig.quality ? imageConfig.quality : {};
			var model = (modelSelect && modelSelect.value) || container.dataset.model || '';
			var options = getAllowedQualityOptions(model, q);
			var desired = (qualitySelect.value || container.dataset.quality || '').toLowerCase();
			var fallback = getDefaultQualityForModel(model, q);
			if (options.indexOf(desired) === -1) desired = fallback;
			qualitySelect.innerHTML = '';
			options.forEach(function (opt) {
				var o = document.createElement('option');
				o.value = opt;
				o.textContent = opt.charAt(0).toUpperCase() + opt.slice(1);
				if (opt === desired) o.selected = true;
				qualitySelect.appendChild(o);
			});
			container.dataset.quality = desired;
		}

		function syncQualityVisibility() {
			if (!qualityWrap) return;
			var q = imageConfig && imageConfig.quality ? imageConfig.quality : {};
			var canSelectQuality = !!q.allow_select;
			qualityWrap.style.display = canSelectQuality ? '' : 'none';
		}

		getModels(container).then(function (models) {
			var img = models.image || {};
			imageConfig = img;
			var sizeOpts = img.size || {};
			var modelOpts = img.model || {};
			var q = img.quality || {};

			var wrap = container.querySelector('.alorbach-demo-size-wrap');
			if (wrap) wrap.style.display = (sizeOpts.options && sizeOpts.options.length) ? '' : 'none';
			if (sizeOpts.options && sizeOpts.options.length && sizeSelect) {
				sizeSelect.innerHTML = '';
				sizeOpts.options.forEach(function (opt) {
					var o = document.createElement('option');
					o.value = opt;
					o.textContent = opt;
					if (opt === sizeOpts.default) o.selected = true;
					sizeSelect.appendChild(o);
				});
			}
			container.dataset.size = sizeOpts.default || '1024x1024';

			if (modelWrap) modelWrap.style.display = (modelOpts.allow_select && modelOpts.options && modelOpts.options.length) ? '' : 'none';
			if (modelOpts.allow_select && modelOpts.options && modelOpts.options.length && modelSelect) {
				modelSelect.innerHTML = '';
				modelOpts.options.forEach(function (opt) {
					var o = document.createElement('option');
					o.value = opt;
					o.textContent = opt;
					if (opt === modelOpts.default) o.selected = true;
					modelSelect.appendChild(o);
				});
			}
			container.dataset.model = modelOpts.default || 'dall-e-3';

			container.dataset.quality = getDefaultQualityForModel(container.dataset.model, q);
			syncQualityOptions();
			syncQualityVisibility();
			refreshImageCost();
		});

		function refreshImageCost() {
			var size = (sizeSelect && sizeSelect.value) || container.dataset.size || '1024x1024';
			var quality = (qualitySelect && qualitySelect.value) || container.dataset.quality || 'medium';
			var model = (modelSelect && modelSelect.value) || container.dataset.model || 'dall-e-3';
			var n = (nInput && parseInt(nInput.value, 10)) || 1;
			n = Math.min(10, Math.max(1, n));
			var estimateParams = { type: 'image', size: size, model: model, n: n };
			if (qualityWrap && qualityWrap.style.display !== 'none') estimateParams.quality = quality;
			updateCostEstimate(container, 'image', estimateParams, container.querySelector('.alorbach-demo-cost'));
		}

		if (sizeSelect) sizeSelect.addEventListener('change', refreshImageCost);
		if (modelSelect) modelSelect.addEventListener('change', function () {
			syncQualityOptions();
			syncQualityVisibility();
			refreshImageCost();
		});
		if (qualitySelect) qualitySelect.addEventListener('change', function () {
			container.dataset.quality = qualitySelect.value || container.dataset.quality || 'medium';
			refreshImageCost();
		});
		if (nInput) nInput.addEventListener('change', refreshImageCost);
		if (nInput) nInput.addEventListener('input', refreshImageCost);

		function stageLabel(stage) {
			return labels[stage] || stage || (labels.queued || 'Queued');
		}

		function setProgress(percent, stage, mode, showCard) {
			var safePercent = Math.max(0, Math.min(100, percent || 0));
			currentProgress = safePercent;
			if (progressCard) progressCard.style.display = showCard === false ? 'none' : '';
			if (progressTitle) progressTitle.textContent = labels.generating || 'Generating image...';
			if (progressMode) progressMode.textContent = mode === 'provider' ? (labels.provider || 'Provider-backed progress updates.') : (labels.estimated || 'Estimated progress based on generation stage.');
			if (progressStage) progressStage.textContent = stageLabel(stage);
			if (progressValue) progressValue.textContent = safePercent + '%';
			if (progressBar) progressBar.setAttribute('aria-valuenow', String(safePercent));
			if (progressFill) progressFill.style.width = safePercent + '%';
		}

		function resetProgress() {
			if (progressTimer) {
				window.clearInterval(progressTimer);
				progressTimer = null;
			}
			if (pollTimer) {
				window.clearTimeout(pollTimer);
				pollTimer = null;
			}
			currentProgress = 0;
			activeJobId = null;
			streamSettled = false;
			setProgress(0, 'queued', 'estimated', false);
			if (previewRail) previewRail.style.display = 'none';
			if (previewImages) previewImages.innerHTML = '';
		}

		function beginEstimatedProgress() {
			var steps = [
				{ percent: 10, stage: 'queued' },
				{ percent: 35, stage: 'drafting' },
				{ percent: 65, stage: 'refining' },
				{ percent: 90, stage: 'finalizing' }
			];
			var index = 0;
			setProgress(steps[0].percent, steps[0].stage, 'estimated', true);
			if (progressTimer) window.clearInterval(progressTimer);
			progressTimer = window.setInterval(function () {
				if (index >= steps.length - 1) return;
				index += 1;
				setProgress(steps[index].percent, steps[index].stage, 'estimated', true);
			}, 1400);
		}

		function providerProgressFromJob(job) {
			var previewCount = (job.preview_images || []).length;
			var stage = job.progress_stage || 'queued';
			var percent = job.progress_percent || 10;

			if (job.status === 'completed') {
				return { percent: 100, stage: 'completed' };
			}

			if (job.status === 'queued') {
				return { percent: 10, stage: 'queued' };
			}

			if (previewCount >= 3) {
				return { percent: 90, stage: 'finalizing' };
			}

			if (previewCount === 2) {
				return { percent: 75, stage: 'refining' };
			}

			if (previewCount === 1) {
				return { percent: 55, stage: 'drafting' };
			}

			if (job.status === 'in_progress') {
				return { percent: 35, stage: 'drafting' };
			}

			return { percent: percent, stage: stage };
		}

		function renderPreviewRail(images, prompt) {
			if (!previewRail || !previewImages) return;
			previewImages.innerHTML = '';
			if (!images || !images.length) {
				previewRail.style.display = 'none';
				return;
			}
			previewRail.style.display = '';
			var galleryItems = [];
			images.forEach(function (item, idx) {
				var url = item.url || (item.b64_json ? 'data:image/png;base64,' + item.b64_json : '');
				if (!url) return;
				var fileName = ((prompt || 'preview').replace(/[^a-zA-Z0-9-_]/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, '').slice(0, 40) || 'preview') + '-preview-' + (idx + 1) + '.png';
				galleryItems.push({
					src: url,
					alt: (prompt || 'Preview') + ' ' + (idx + 1),
					downloadName: fileName
				});
				var img = document.createElement('img');
				img.src = url;
				img.alt = (prompt || 'Preview') + ' ' + (idx + 1);
				img.setAttribute('tabindex', '0');
				img.className = 'alorbach-demo-preview-image';
				img.addEventListener('click', function () { openLightbox(galleryItems, idx); });
				img.addEventListener('keydown', function (e) {
					if (e.key === 'Enter' || e.key === ' ') {
						e.preventDefault();
						openLightbox(galleryItems, idx);
					}
				});
				previewImages.appendChild(img);
			});
		}

		function renderFinalImages(items, prompt) {
			if (!resultEl) return;
			resultEl.innerHTML = '';
			if (!items || !items.length) return;
			var baseName = (prompt || 'image').replace(/[^a-zA-Z0-9-_]/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, '').slice(0, 40) || 'image';
			var galleryItems = [];
			items.forEach(function (item, idx) {
				var url = item.url || (item.b64_json ? 'data:image/png;base64,' + item.b64_json : '');
				if (!url) return;
				galleryItems.push({
					src: url,
					alt: prompt,
					downloadName: baseName + '-' + (idx + 1) + '.png'
				});
				var itemWrap = document.createElement('div');
				itemWrap.className = 'alorbach-demo-image-item';
				var img = document.createElement('img');
				img.src = url;
				img.alt = prompt;
				img.setAttribute('tabindex', '0');
				var actions = document.createElement('div');
				actions.className = 'alorbach-demo-image-actions';
				var downloadLink = document.createElement('a');
				downloadLink.href = url;
				downloadLink.download = baseName + '-' + (idx + 1) + '.png';
				downloadLink.className = 'alorbach-demo-download';
				downloadLink.textContent = 'Download';
				actions.appendChild(downloadLink);
				itemWrap.appendChild(img);
				itemWrap.appendChild(actions);
				resultEl.appendChild(itemWrap);
			});
			resultEl.querySelectorAll('.alorbach-demo-image-item img').forEach(function (imgEl) {
				var index = Array.prototype.indexOf.call(resultEl.querySelectorAll('.alorbach-demo-image-item img'), imgEl);
				imgEl.addEventListener('click', function () { openLightbox(galleryItems, index); });
				imgEl.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openLightbox(galleryItems, index); } });
			});
		}

		function renderUsage(data) {
			var usageEl = container.querySelector('.alorbach-demo-usage');
			if (!usageEl) return;
			var usageParts = [];
			if (data.usage && (data.usage.total_tokens || data.usage.output_tokens)) {
				var tok = data.usage.total_tokens || (data.usage.output_tokens || 0);
				if (tok) usageParts.push(tok + ' tokens');
			}
			if (data.cost_credits !== undefined) {
				var costTxt = data.cost_credits.toFixed(2) + ' ' + (config.creditsLabel || 'Credits');
				if (data.cost_usd !== undefined) costTxt += ' ($' + data.cost_usd.toFixed(2) + ')';
				usageParts.push(costTxt);
			}
			usageEl.textContent = usageParts.length ? usageParts.join(' | ') : '';
			usageEl.style.display = usageParts.length ? '' : 'none';
		}

		function finishGenerate() {
			if (progressTimer) {
				window.clearInterval(progressTimer);
				progressTimer = null;
			}
			if (pollTimer) {
				window.clearTimeout(pollTimer);
				pollTimer = null;
			}
			container.classList.remove('alorbach-demo-loading');
			if (genBtn) genBtn.disabled = false;
			activeJobId = null;
			streamSettled = true;
		}

		function applyJobUpdate(job, prompt) {
			var progressState = job.progress_mode === 'provider'
				? providerProgressFromJob(job)
				: { percent: job.progress_percent || 10, stage: job.progress_stage || 'queued' };

			if (job.progress_mode !== 'estimated' && progressTimer) {
				window.clearInterval(progressTimer);
				progressTimer = null;
			}

			renderPreviewRail(job.preview_images || [], prompt);
			setProgress(progressState.percent, progressState.stage, job.progress_mode || 'estimated', true);
		}

		function pollJob(jobId, prompt) {
			if (!jobId || activeJobId !== jobId) {
				return;
			}
			apiFetch('/images/jobs/' + encodeURIComponent(jobId)).then(function (r) {
				if (!r.ok) return r.json().then(function (d) { throw d; });
				return r.json();
			}).then(function (job) {
				if (activeJobId !== jobId) {
					return;
				}
				applyJobUpdate(job, prompt);

				if (job.status === 'completed') {
					setProgress(100, 'completed', job.progress_mode || 'estimated', true);
					renderFinalImages(job.final_images || [], prompt);
					renderUsage(job);
					getBalance(container);
					finishGenerate();
					return;
				}
				if (job.status === 'failed') {
					throw { message: job.error || 'Image generation failed' };
				}

				pollTimer = window.setTimeout(function () {
					pollJob(jobId, prompt);
				}, job.progress_mode === 'provider' ? 450 : 1200);
			}).catch(function (err) {
				if (activeJobId !== jobId) {
					return;
				}
				finishGenerate();
				handleError(err, container);
			});
		}

		function streamJob(jobId, prompt) {
			var endpoint = buildApiUrl('/images/jobs/' + encodeURIComponent(jobId) + '/stream');
			var buffer = '';
			activeJobId = jobId;
			streamSettled = false;

			if (pollTimer) {
				window.clearTimeout(pollTimer);
				pollTimer = null;
			}

			pollTimer = window.setTimeout(function () {
				if (container.classList.contains('alorbach-demo-loading') && activeJobId === jobId) {
					pollJob(jobId, prompt);
				}
			}, 350);

			function handlePayload(payload) {
				if (!payload) return;
				streamSettled = true;
				applyJobUpdate(payload, prompt);

				if (payload.status === 'completed') {
					setProgress(100, 'completed', payload.progress_mode || 'provider', true);
					renderFinalImages(payload.final_images || [], prompt);
					renderUsage(payload);
					getBalance(container);
					finishGenerate();
					return true;
				}

				if (payload.status === 'failed') {
					finishGenerate();
					handleError({ message: payload.error || 'Image generation failed' }, container);
					return true;
				}

				return false;
			}

			function consumeStreamChunk(chunk) {
				buffer += chunk;
				var boundaryMatch = buffer.match(/\r?\n\r?\n/);
				while (boundaryMatch) {
					var boundary = boundaryMatch.index;
					var separatorLength = boundaryMatch[0].length;
					var block = buffer.slice(0, boundary);
					buffer = buffer.slice(boundary + separatorLength);

					var eventName = 'message';
					var dataLines = [];
					block.split(/\r?\n/).forEach(function (line) {
						if (line.indexOf('event:') === 0) {
							eventName = line.slice(6).trim();
						} else if (line.indexOf('data:') === 0) {
							dataLines.push(line.slice(5).trim());
						}
					});

					if (dataLines.length) {
						var payload = null;
						try {
							payload = JSON.parse(dataLines.join('\n'));
						} catch (e) {}
						if (payload && (eventName === 'job' || eventName === 'done' || eventName === 'error')) {
							handlePayload(payload);
						}
					}

					boundaryMatch = buffer.match(/\r?\n\r?\n/);
				}
			}

			fetch(endpoint, {
				method: 'GET',
				headers: {
					'X-WP-Nonce': nonce
				},
				credentials: 'same-origin'
			}).then(function (response) {
				if (!response.ok) {
					throw new Error('Stream request failed');
				}
				if (!response.body || !response.body.getReader) {
					throw new Error('Streaming not supported');
				}

				var reader = response.body.getReader();
				var decoder = new TextDecoder();

				function readNext() {
					reader.read().then(function (result) {
						if (result.done) {
							if (!container.classList.contains('alorbach-demo-loading') || activeJobId !== jobId) {
								return;
							}
							if (!streamSettled) {
								pollJob(jobId, prompt);
							}
							return;
						}
						consumeStreamChunk(decoder.decode(result.value, { stream: true }));
						if (container.classList.contains('alorbach-demo-loading')) {
							readNext();
						}
					}).catch(function () {
						if (container.classList.contains('alorbach-demo-loading')) {
							pollJob(jobId, prompt);
						}
					});
				}

				readNext();
			}).catch(function () {
				pollJob(jobId, prompt);
			});
		}

		function generate() {
			var prompt = (promptEl && promptEl.value || '').trim();
			if (!prompt) return;
			clearError(container);
			var size = (sizeSelect && sizeSelect.value) || container.dataset.size || '1024x1024';
			var quality = (qualitySelect && qualitySelect.value) || container.dataset.quality || 'medium';
			var model = (modelSelect && modelSelect.value) || container.dataset.model || 'dall-e-3';
			var n = (nInput && parseInt(nInput.value, 10)) || 1;
			n = Math.min(10, Math.max(1, n));
			container.classList.add('alorbach-demo-loading');
			if (genBtn) genBtn.disabled = true;
			if (resultEl) resultEl.innerHTML = '';
			resetProgress();
			activeJobId = null;
			streamSettled = false;
			var usageEl = container.querySelector('.alorbach-demo-usage');
			if (usageEl) { usageEl.textContent = ''; usageEl.style.display = 'none'; }

			var body = { prompt: prompt, size: size, n: n };
			if (modelWrap && modelWrap.style.display !== 'none') body.model = model;
			if (qualityWrap && qualityWrap.style.display !== 'none') body.quality = quality;

			var supportsJobs = !imageConfig || imageConfig.supports_progress !== false;
			var jobsEndpoint = '/images/jobs';

			if (supportsJobs) {
				setProgress(10, 'queued', 'estimated', true);
				apiFetch(jobsEndpoint, {
					method: 'POST',
					body: body,
				}).then(function (r) {
					if (!r.ok) return r.json().then(function (d) { throw d; });
					return r.json();
				}).then(function (job) {
					activeJobId = job.job_id || null;
					if (job.progress_mode === 'estimated') {
						beginEstimatedProgress();
					} else if (progressTimer) {
						window.clearInterval(progressTimer);
						progressTimer = null;
					}
					if (job.progress_mode === 'provider') {
						var providerState = providerProgressFromJob(job);
						setProgress(providerState.percent, providerState.stage, 'provider', true);
						streamJob(job.job_id, prompt);
					} else {
						setProgress(job.progress_percent || 10, job.progress_stage || 'queued', job.progress_mode || 'estimated', true);
						pollJob(job.job_id, prompt);
					}
					renderPreviewRail(job.preview_images || [], prompt);
				}).catch(function (err) {
					var status = err && err.data && err.data.status;
					var shouldFallback = status === 404 || err.code === 'rest_no_route';
					if (!shouldFallback) {
						handleError(err, container);
						finishGenerate();
						return;
					}
					beginEstimatedProgress();
					apiFetch('/images', {
						method: 'POST',
						body: body,
					}).then(function (r) {
						if (!r.ok) return r.json().then(function (d) { throw d; });
						return r.json();
					}).then(function (data) {
						setProgress(100, 'completed', 'estimated', true);
						renderFinalImages(data.data || [], prompt);
						renderUsage(data);
						getBalance(container);
					}).catch(function (err) {
						handleError(err, container);
					}).finally(function () {
						finishGenerate();
					});
				});
				return;
			}

			beginEstimatedProgress();
			apiFetch('/images', {
				method: 'POST',
				body: body,
			}).then(function (r) {
				if (!r.ok) return r.json().then(function (d) { throw d; });
				return r.json();
			}).then(function (data) {
				setProgress(100, 'completed', 'estimated', true);
				renderFinalImages(data.data || [], prompt);
				renderUsage(data);
				getBalance(container);
			}).catch(function (err) {
				handleError(err, container);
			}).finally(function () {
				finishGenerate();
			});
		}

		if (genBtn) genBtn.addEventListener('click', generate);
	}

	// Transcribe demo
	function initTranscribe(container) {
		if (!container) return;
		getBalance(container);
		var dropzone = container.querySelector('.alorbach-demo-dropzone');
		var fileInput = container.querySelector('.alorbach-demo-file-input');
		var promptEl = container.querySelector('.alorbach-demo-instructions');
		var modelSelect = container.querySelector('.alorbach-demo-model-select');
		var transcribeBtn = container.querySelector('.alorbach-demo-transcribe-btn');
		var resultEl = container.querySelector('.alorbach-demo-result');
		var fileInfo = container.querySelector('.alorbach-demo-file-info');

		var selectedFile = null;
		var duration = 0;

		getModels(container).then(function (models) {
			var audio = models.audio || {};
			var wrap = container.querySelector('.alorbach-demo-model-wrap');
			if (wrap) wrap.style.display = audio.allow_select ? '' : 'none';
			if (audio.allow_select && audio.options && audio.options.length) {
				modelSelect.innerHTML = '';
				audio.options.forEach(function (opt) {
					var o = document.createElement('option');
					o.value = opt;
					o.textContent = opt;
					if (opt === audio.default) o.selected = true;
					modelSelect.appendChild(o);
				});
			}
			container.dataset.model = audio.default || 'whisper-1';
		});

		function refreshAudioCost() {
			var costEl = container.querySelector('.alorbach-demo-cost');
			if (!costEl) return;
			var wrap = costEl.parentElement && costEl.parentElement.classList && costEl.parentElement.classList.contains('alorbach-demo-cost-wrap') ? costEl.parentElement : null;
			if (!selectedFile || !duration) {
				costEl.textContent = '';
				costEl.style.display = 'none';
				if (wrap) wrap.style.display = 'none';
				return;
			}
			var model = (modelSelect && modelSelect.value) || container.dataset.model || 'whisper-1';
			updateCostEstimate(container, 'audio', { type: 'audio', duration_seconds: duration, model: model }, costEl);
		}

		if (modelSelect) modelSelect.addEventListener('change', refreshAudioCost);

		function readDuration(file, cb) {
			var url = URL.createObjectURL(file);
			var audio = new Audio();
			audio.addEventListener('loadedmetadata', function () {
				duration = Math.ceil(audio.duration);
				URL.revokeObjectURL(url);
				cb(duration);
			});
			audio.addEventListener('error', function () {
				URL.revokeObjectURL(url);
				cb(0);
			});
			audio.src = url;
		}

		function fileToBase64(file, cb) {
			var reader = new FileReader();
			reader.onload = function () {
				var b64 = reader.result.split(',')[1];
				cb(b64 || '');
			};
			reader.onerror = function () { cb(''); };
			reader.readAsDataURL(file);
		}

		function onFileSelect(file) {
			selectedFile = file;
			if (fileInfo) fileInfo.textContent = file.name + ' (' + (file.size / 1024).toFixed(1) + ' KB)';
			readDuration(file, function (d) {
				duration = d || 0;
				if (fileInfo && d) fileInfo.textContent += ' ~' + d + 's';
				refreshAudioCost();
			});
		}

		if (dropzone) {
			dropzone.addEventListener('click', function () { if (fileInput) fileInput.click(); });
			dropzone.addEventListener('dragover', function (e) { e.preventDefault(); dropzone.classList.add('dragover'); });
			dropzone.addEventListener('dragleave', function () { dropzone.classList.remove('dragover'); });
			dropzone.addEventListener('drop', function (e) {
				e.preventDefault();
				dropzone.classList.remove('dragover');
				if (e.dataTransfer.files.length) onFileSelect(e.dataTransfer.files[0]);
			});
		}
		if (fileInput) fileInput.addEventListener('change', function () {
			if (fileInput.files.length) onFileSelect(fileInput.files[0]);
		});

		function transcribe() {
			if (!selectedFile) return;
			clearError(container);
			var model = (modelSelect && modelSelect.value) || container.dataset.model || 'whisper-1';
			var prompt = (promptEl && promptEl.value || '').trim();
			container.classList.add('alorbach-demo-loading');
			if (transcribeBtn) transcribeBtn.disabled = true;
			if (resultEl) resultEl.textContent = '';
			var usageEl = container.querySelector('.alorbach-demo-usage');
			if (usageEl) { usageEl.textContent = ''; usageEl.style.display = 'none'; }

			fileToBase64(selectedFile, function (b64) {
				if (!b64) {
					handleError({ message: 'Could not read file' }, container);
					container.classList.remove('alorbach-demo-loading');
					if (transcribeBtn) transcribeBtn.disabled = false;
					return;
				}
				readDuration(selectedFile, function (d) {
					var body = { audio_base64: b64, duration_seconds: d || 1, model: model };
					if (prompt) body.prompt = prompt;
					apiFetch('/transcribe', {
						method: 'POST',
						body: body,
					}).then(function (r) {
						if (!r.ok) return r.json().then(function (d) { throw d; });
						return r.json();
					}).then(function (data) {
						if (resultEl) resultEl.textContent = data.text || '';
						var usageEl = container.querySelector('.alorbach-demo-usage');
						if (usageEl) {
							var usageParts = [];
							if (data.duration_seconds) usageParts.push(data.duration_seconds + 's');
							if (data.cost_credits !== undefined) {
								var costTxt = data.cost_credits.toFixed(2) + ' ' + (config.creditsLabel || 'Credits');
								if (data.cost_usd !== undefined) costTxt += ' ($' + data.cost_usd.toFixed(2) + ')';
								usageParts.push(costTxt);
							}
							usageEl.textContent = usageParts.length ? usageParts.join(' | ') : '';
							usageEl.style.display = usageParts.length ? '' : 'none';
						}
						getBalance(container);
					}).catch(function (err) {
						handleError(err, container);
					}).finally(function () {
						container.classList.remove('alorbach-demo-loading');
						if (transcribeBtn) transcribeBtn.disabled = false;
					});
				});
			});
		}

		if (transcribeBtn) transcribeBtn.addEventListener('click', transcribe);
	}

	// Video demo
	function initVideo(container) {
		if (!container) return;
		getBalance(container);
		var promptEl = container.querySelector('.alorbach-demo-prompt');
		var modelSelect = container.querySelector('.alorbach-demo-model-select');
		var sizeSelect = container.querySelector('.alorbach-demo-size-select');
		var durationSelect = container.querySelector('.alorbach-demo-duration-select');
		var genBtn = container.querySelector('.alorbach-demo-generate');
		var resultEl = container.querySelector('.alorbach-demo-videos');

		// sora-2 supports 720p only; sora-2-pro supports all
		function getSizeOptionsForModel(model) {
			var all = ['1280x720', '720x1280', '1920x1080', '1080x1920', '1024x1792', '1792x1024'];
			if (!model || model.indexOf('sora-2-pro') !== -1) return all;
			return ['1280x720', '720x1280'];
		}

		function updateSizeOptions() {
			var model = (modelSelect && modelSelect.value) || container.dataset.model || 'sora-2';
			var opts = getSizeOptionsForModel(model);
			var current = (sizeSelect && sizeSelect.value) || container.dataset.size || '1280x720';
			if (sizeSelect) {
				sizeSelect.innerHTML = '';
				opts.forEach(function (opt) {
					var o = document.createElement('option');
					o.value = opt;
					o.textContent = opt;
					if (opt === current || (opts.indexOf(current) === -1 && opt === opts[0])) o.selected = true;
					sizeSelect.appendChild(o);
				});
			}
		}

		getModels(container).then(function (models) {
			var video = models.video || {};
			var wrap = container.querySelector('.alorbach-demo-model-wrap');
			if (wrap) wrap.style.display = video.allow_select ? '' : 'none';
			if (video.allow_select && video.options && video.options.length && modelSelect) {
				modelSelect.innerHTML = '';
				video.options.forEach(function (opt) {
					var o = document.createElement('option');
					o.value = opt;
					o.textContent = opt;
					if (opt === video.default) o.selected = true;
					modelSelect.appendChild(o);
				});
			}
			container.dataset.model = video.default || 'sora-2';

			var sizeOpts = video.size || {};
			if (sizeOpts.options && sizeOpts.options.length && sizeSelect) {
				sizeSelect.innerHTML = '';
				sizeOpts.options.forEach(function (opt) {
					var o = document.createElement('option');
					o.value = opt;
					o.textContent = opt;
					if (opt === (sizeOpts.default || '1280x720')) o.selected = true;
					sizeSelect.appendChild(o);
				});
			}
			container.dataset.size = sizeOpts.default || '1280x720';

			var durOpts = video.duration || {};
			if (durOpts.options && durOpts.options.length && durationSelect) {
				durationSelect.innerHTML = '';
				durOpts.options.forEach(function (opt) {
					var o = document.createElement('option');
					o.value = opt;
					o.textContent = opt + 's';
					if (opt === (durOpts.default || '8')) o.selected = true;
					durationSelect.appendChild(o);
				});
			}
			container.dataset.duration = durOpts.default || '8';

			updateSizeOptions();
			refreshVideoCost();
		});

		function refreshVideoCost() {
			var model = (modelSelect && modelSelect.value) || container.dataset.model || 'sora-2';
			var size = (sizeSelect && sizeSelect.value) || container.dataset.size || '1280x720';
			var duration = (durationSelect && durationSelect.value) || container.dataset.duration || '8';
			updateCostEstimate(container, 'video', { type: 'video', model: model, size: size, duration_seconds: parseInt(duration, 10) }, container.querySelector('.alorbach-demo-cost'));
		}

		if (modelSelect) modelSelect.addEventListener('change', function () { updateSizeOptions(); refreshVideoCost(); });
		if (sizeSelect) sizeSelect.addEventListener('change', refreshVideoCost);
		if (durationSelect) durationSelect.addEventListener('change', refreshVideoCost);

		function generate() {
			var prompt = (promptEl && promptEl.value || '').trim();
			if (!prompt) return;
			clearError(container);
			var model = (modelSelect && modelSelect.value) || container.dataset.model || 'sora-2';
			var size = (sizeSelect && sizeSelect.value) || container.dataset.size || '1280x720';
			var duration = parseInt((durationSelect && durationSelect.value) || container.dataset.duration || '8', 10);
			container.classList.add('alorbach-demo-loading');
			if (genBtn) genBtn.disabled = true;
			if (resultEl) resultEl.innerHTML = '';
			var usageEl = container.querySelector('.alorbach-demo-usage');
			if (usageEl) { usageEl.textContent = ''; usageEl.style.display = 'none'; }

			apiFetch('/video', {
				method: 'POST',
				body: { prompt: prompt, model: model, size: size, duration_seconds: duration },
			}).then(function (r) {
				if (!r.ok) return r.json().then(function (d) { throw d; });
				return r.json();
			}).then(function (data) {
				if (resultEl && data.data && data.data.length) {
					var baseName = (prompt || 'video').replace(/[^a-zA-Z0-9-_]/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, '').slice(0, 40) || 'video';
					data.data.forEach(function (item, idx) {
						var url = item.url || '';
						if (url) {
							var itemWrap = document.createElement('div');
							itemWrap.className = 'alorbach-demo-video-item';
							var videoEl = document.createElement('video');
							videoEl.src = url;
							videoEl.controls = true;
							videoEl.preload = 'metadata';
							var actions = document.createElement('div');
							actions.className = 'alorbach-demo-video-actions';
							var downloadLink = document.createElement('a');
							downloadLink.href = url;
							downloadLink.download = baseName + '-' + (idx + 1) + '.mp4';
							downloadLink.className = 'alorbach-demo-download';
							downloadLink.textContent = 'Download';
							actions.appendChild(downloadLink);
							itemWrap.appendChild(videoEl);
							itemWrap.appendChild(actions);
							resultEl.appendChild(itemWrap);
						}
					});
				}
				var usageEl = container.querySelector('.alorbach-demo-usage');
				if (usageEl) {
					var usageParts = [];
					if (data.cost_credits !== undefined) {
						var costTxt = data.cost_credits.toFixed(2) + ' ' + (config.creditsLabel || 'Credits');
						if (data.cost_usd !== undefined) costTxt += ' ($' + data.cost_usd.toFixed(2) + ')';
						usageParts.push(costTxt);
					}
					usageEl.textContent = usageParts.length ? usageParts.join(' | ') : '';
					usageEl.style.display = usageParts.length ? '' : 'none';
				}
				getBalance(container);
			}).catch(function (err) {
				handleError(err, container);
			}).finally(function () {
				container.classList.remove('alorbach-demo-loading');
				if (genBtn) genBtn.disabled = false;
			});
		}

		if (genBtn) genBtn.addEventListener('click', generate);
	}

	// Init on DOM ready
	function init() {
		var chat = document.querySelector('.alorbach-demo-chat');
		var image = document.querySelector('.alorbach-demo-image');
		var transcribe = document.querySelector('.alorbach-demo-transcribe');
		var video = document.querySelector('.alorbach-demo-video');
		if (chat) initChat(chat);
		if (image) initImage(image);
		if (transcribe) initTranscribe(transcribe);
		if (video) initVideo(video);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
