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

	function apiFetch(endpoint, options) {
		var url = restUrl.replace(/\/$/, '') + endpoint;
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
	}

	function clearError(container) {
		var el = container.querySelector('.alorbach-demo-error');
		if (el) el.style.display = 'none';
	}

	function openLightbox(src) {
		var lb = document.getElementById('alorbach-demo-lightbox');
		if (!lb) {
			lb = document.createElement('div');
			lb.id = 'alorbach-demo-lightbox';
			lb.className = 'alorbach-demo-lightbox';
			lb.innerHTML = '<div class="alorbach-demo-lightbox-backdrop"></div><img class="alorbach-demo-lightbox-img" src="" alt="">';
			lb.querySelector('.alorbach-demo-lightbox-backdrop').addEventListener('click', closeLightbox);
			document.addEventListener('keydown', function lightboxKey(e) {
				if (e.key === 'Escape') { closeLightbox(); document.removeEventListener('keydown', lightboxKey); }
			});
			document.body.appendChild(lb);
		}
		lb.querySelector('.alorbach-demo-lightbox-img').src = src;
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
		var maxTokensInput = container.querySelector('.alorbach-demo-max-tokens');

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
			var maxTokens = (maxTokensInput && parseInt(maxTokensInput.value, 10)) || 1024;
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

		getModels(container).then(function (models) {
			var img = models.image || {};
			var wrap = container.querySelector('.alorbach-demo-size-wrap');
			if (wrap) wrap.style.display = '';
			if (img.options && img.options.length && sizeSelect) {
				sizeSelect.innerHTML = '';
				img.options.forEach(function (opt) {
					var o = document.createElement('option');
					o.value = opt;
					o.textContent = opt;
					if (opt === img.default) o.selected = true;
					sizeSelect.appendChild(o);
				});
			}
			container.dataset.size = img.default || '1024x1024';
			var q = img.quality || {};
			if (qualityWrap) qualityWrap.style.display = q.allow_select ? '' : 'none';
			if (q.allow_select && q.options && q.options.length && qualitySelect) {
				qualitySelect.innerHTML = '';
				q.options.forEach(function (opt) {
					var o = document.createElement('option');
					o.value = opt;
					o.textContent = opt.charAt(0).toUpperCase() + opt.slice(1);
					if (opt === q.default) o.selected = true;
					qualitySelect.appendChild(o);
				});
			}
			container.dataset.quality = q.default || 'medium';
			refreshImageCost();
		});

		function refreshImageCost() {
			var size = (sizeSelect && sizeSelect.value) || container.dataset.size || '1024x1024';
			var quality = (qualitySelect && qualitySelect.value) || container.dataset.quality || 'medium';
			var n = (nInput && parseInt(nInput.value, 10)) || 1;
			n = Math.min(10, Math.max(1, n));
			updateCostEstimate(container, 'image', { type: 'image', size: size, quality: quality, n: n }, container.querySelector('.alorbach-demo-cost'));
		}

		if (sizeSelect) sizeSelect.addEventListener('change', refreshImageCost);
		if (qualitySelect) qualitySelect.addEventListener('change', refreshImageCost);
		if (nInput) nInput.addEventListener('change', refreshImageCost);
		if (nInput) nInput.addEventListener('input', refreshImageCost);

		function generate() {
			var prompt = (promptEl && promptEl.value || '').trim();
			if (!prompt) return;
			clearError(container);
			var size = (sizeSelect && sizeSelect.value) || container.dataset.size || '1024x1024';
			var quality = (qualitySelect && qualitySelect.value) || container.dataset.quality || 'medium';
			var n = (nInput && parseInt(nInput.value, 10)) || 1;
			n = Math.min(10, Math.max(1, n));
			container.classList.add('alorbach-demo-loading');
			if (genBtn) genBtn.disabled = true;
			if (resultEl) resultEl.innerHTML = '';
			var usageEl = container.querySelector('.alorbach-demo-usage');
			if (usageEl) { usageEl.textContent = ''; usageEl.style.display = 'none'; }

			var body = { prompt: prompt, size: size, n: n };
			if (qualityWrap && qualityWrap.style.display !== 'none') body.quality = quality;

			apiFetch('/images', {
				method: 'POST',
				body: body,
			}).then(function (r) {
				if (!r.ok) return r.json().then(function (d) { throw d; });
				return r.json();
			}).then(function (data) {
				if (resultEl && data.data && data.data.length) {
					var baseName = (prompt || 'image').replace(/[^a-zA-Z0-9-_]/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, '').slice(0, 40) || 'image';
					data.data.forEach(function (item, idx) {
						var url = item.url || (item.b64_json ? 'data:image/png;base64,' + item.b64_json : '');
						if (url) {
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
						}
					});
					// Lightbox: click image to expand
					resultEl.querySelectorAll('.alorbach-demo-image-item img').forEach(function (imgEl) {
						imgEl.addEventListener('click', function () { openLightbox(imgEl.src); });
						imgEl.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openLightbox(imgEl.src); } });
					});
				}
				var usageEl = container.querySelector('.alorbach-demo-usage');
				if (usageEl) {
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
		var genBtn = container.querySelector('.alorbach-demo-generate');
		var resultEl = container.querySelector('.alorbach-demo-videos');

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
			refreshVideoCost();
		});

		function refreshVideoCost() {
			var model = (modelSelect && modelSelect.value) || container.dataset.model || 'sora-2';
			updateCostEstimate(container, 'video', { type: 'video', model: model }, container.querySelector('.alorbach-demo-cost'));
		}

		if (modelSelect) modelSelect.addEventListener('change', refreshVideoCost);

		function generate() {
			var prompt = (promptEl && promptEl.value || '').trim();
			if (!prompt) return;
			clearError(container);
			var model = (modelSelect && modelSelect.value) || container.dataset.model || 'sora-2';
			container.classList.add('alorbach-demo-loading');
			if (genBtn) genBtn.disabled = true;
			if (resultEl) resultEl.innerHTML = '';
			var usageEl = container.querySelector('.alorbach-demo-usage');
			if (usageEl) { usageEl.textContent = ''; usageEl.style.display = 'none'; }

			apiFetch('/video', {
				method: 'POST',
				body: { prompt: prompt, model: model },
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
