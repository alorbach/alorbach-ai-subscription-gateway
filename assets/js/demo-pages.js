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

	function isAllowSelectEnabled(flag) {
		return flag === true || flag === 1 || flag === '1';
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

	function isLocalCodexModel(model) {
		model = String(model || '');
		return model.indexOf('codex-local:') === 0 || model.indexOf('model-relay:') === 0 || model === 'local-asr' || model.indexOf('local-asr:') === 0;
	}

	function getLocalCodexStorageKey(origin) {
		return 'alorbachAiBridgeToken:' + String(origin || window.location.origin);
	}

	function getLegacyLocalCodexStorageKey(origin) {
		return 'alorbachLocalCodexToken:' + String(origin || window.location.origin);
	}

	function localCodexFetch(url, options) {
		var opts = Object.assign({
			mode: 'cors',
			cache: 'no-store',
			headers: {
				'Content-Type': 'application/json'
			}
		}, options || {});
		if (opts.body && typeof opts.body === 'object') {
			opts.body = JSON.stringify(opts.body);
		}
		return fetch(url, opts);
	}

	function localCodexReadJson(response) {
		return response.json().catch(function () {
			return {};
		});
	}

	function readFileDataUrl(file) {
		return new Promise(function (resolve, reject) {
			var reader = new FileReader();
			reader.onload = function () { resolve(String(reader.result || '')); };
			reader.onerror = function () { reject(new Error('Could not read reference image.')); };
			reader.readAsDataURL(file);
		});
	}

	function getLocalCodexErrorMessage(err, fallback) {
		if (!err) {
			return fallback || 'AI Model Relay failed.';
		}
		return err.message || (err.error && err.error.message) || (err.data && err.data.message) || err.code || fallback || 'AI Model Relay failed.';
	}

	function withLocalCodexContext(err, bridge) {
		var normalized = (err && typeof err === 'object') ? err : { message: String(err || 'AI Model Relay failed.') };
		normalized.localCodex = true;
		if (bridge) {
			normalized.bridgeUrl = bridge.bridgeUrl || bridge.bridge_url || normalized.bridgeUrl || '';
			normalized.origin = bridge.origin || normalized.origin || '';
		}
		normalized.message = getLocalCodexErrorMessage(normalized);
		return normalized;
	}

	function isLocalCodexPairingError(err) {
		var msg = getLocalCodexErrorMessage(err, '').toLowerCase();
		var code = String((err && err.code) || (err && err.error) || '').toLowerCase();
		var status = err && err.data && err.data.status;
		return msg.indexOf('not paired') !== -1
			|| msg.indexOf('pairing') !== -1
			|| msg.indexOf('bridge token') !== -1
			|| code.indexOf('pair') !== -1
			|| code.indexOf('token') !== -1
			|| ((status === 401 || status === 403) && (msg.indexOf('codex') !== -1 || msg.indexOf('relay') !== -1 || msg.indexOf('bridge') !== -1));
	}

	function isLocalCodexBridgeReachabilityError(err) {
		var msg = getLocalCodexErrorMessage(err, '').toLowerCase();
		var code = String((err && err.code) || (err && err.error) || '').toLowerCase();
		var category = String((err && err.category) || '').toLowerCase();
		if (isLocalCodexPairingError(err)) {
			return true;
		}
		if (code.indexOf('codex_') === 0 || category === 'rate_limit' || category === 'output_detection' || category === 'codex_cli') {
			return false;
		}
		if (err && err.debug_help) {
			return false;
		}
		return msg.indexOf('not running') !== -1
			|| msg.indexOf('not reachable') !== -1
			|| msg.indexOf('failed to fetch') !== -1
			|| msg.indexOf('networkerror') !== -1
			|| code.indexOf('network') !== -1;
	}

	function getLocalCodexBridge(localConfig, forcePairing) {
		var bridgeUrl = String(localConfig.bridge_url || 'http://127.0.0.1:8765').replace(/\/$/, '');
		var origin = localConfig.origin || window.location.origin;
		var storageKey = getLocalCodexStorageKey(origin);
		var legacyStorageKey = getLegacyLocalCodexStorageKey(origin);
		if (forcePairing && window.localStorage) {
			window.localStorage.removeItem(storageKey);
			window.localStorage.removeItem(legacyStorageKey);
		}
		var token = window.localStorage ? window.localStorage.getItem(storageKey) : '';
		if (!token && window.localStorage) {
			token = window.localStorage.getItem(legacyStorageKey) || '';
			if (token) window.localStorage.setItem(storageKey, token);
		}
		return {
			bridgeUrl: bridgeUrl,
			origin: origin,
			storageKey: storageKey,
			token: token
		};
	}

	function saveLocalCodexToken(bridge, token) {
		bridge.token = token;
		if (window.localStorage) {
			window.localStorage.setItem(bridge.storageKey, token);
		}
		return bridge;
	}

	function getLocalCodexStatus(localConfig) {
		var bridge = getLocalCodexBridge(localConfig, false);
		return localCodexFetch(bridge.bridgeUrl + '/v1/status', { method: 'GET' }).then(function (statusResponse) {
			if (!statusResponse.ok) {
				return localCodexReadJson(statusResponse).then(function (d) {
					throw withLocalCodexContext(d, bridge);
				});
			}
			return localCodexReadJson(statusResponse).then(function (statusData) {
				return {
					state: bridge.token ? 'connected' : 'unpaired',
					bridge: bridge,
					status: statusData,
					message: bridge.token ? 'AI Model Relay connected.' : 'AI Model Relay is installed, but this WordPress origin is not paired.'
				};
			});
		}).catch(function (err) {
			return {
				state: 'offline',
				bridge: bridge,
				error: withLocalCodexContext(err, bridge),
				message: 'AI Model Relay is not reachable at ' + bridge.bridgeUrl + '.'
			};
		});
	}

	function pairLocalCodexBridge(bridge) {
		var pairingCode = window.prompt('Enter the pairing code shown in the AI Model Relay tray app.');
		if (!pairingCode) {
			throw withLocalCodexContext({ message: 'AI Model Relay pairing was cancelled.' }, bridge);
		}
		return localCodexFetch(bridge.bridgeUrl + '/v1/pair', {
			method: 'POST',
			body: { origin: bridge.origin, pairing_code: pairingCode }
		}).then(function (pairResponse) {
			if (!pairResponse.ok) {
				return localCodexReadJson(pairResponse).then(function (d) {
					throw withLocalCodexContext(d, bridge);
				});
			}
			return localCodexReadJson(pairResponse);
		}).then(function (pairData) {
			if (!pairData.token) {
				throw withLocalCodexContext({ message: 'AI Model Relay did not return a pairing token.' }, bridge);
			}
			return saveLocalCodexToken(bridge, pairData.token);
		});
	}

	function getLocalCodexConfig() {
		return apiFetch('/ai-bridge/config').then(function (r) {
			if (!r.ok) return localCodexReadJson(r).then(function (d) { throw withLocalCodexContext(d); });
			return r.json();
		});
	}

	function ensureLocalCodexPairing(localConfig, options) {
		var opts = options || {};
		var bridge = getLocalCodexBridge(localConfig, !!opts.forcePairing);

		return localCodexFetch(bridge.bridgeUrl + '/v1/status', { method: 'GET' }).then(function (statusResponse) {
			if (!statusResponse.ok) {
				return localCodexReadJson(statusResponse).then(function (d) {
					throw withLocalCodexContext({ message: d.message || 'AI Model Relay tray app is not ready.' }, bridge);
				});
			}
			if (bridge.token && !opts.forcePairing) {
				return bridge;
			}
			return pairLocalCodexBridge(bridge);
		}).catch(function (err) {
			throw withLocalCodexContext(err, bridge);
		});
	}

	function localCodexExecute(type, payload, options) {
		var localConfig;
		var opts = options || {};

		function runWithBridge(bridge) {
			var job;
			return apiFetch('/ai-bridge/jobs', {
				method: 'POST',
				body: { type: type, payload: payload }
			}).then(function (r) {
				if (!r.ok) return localCodexReadJson(r).then(function (d) { throw withLocalCodexContext(d, bridge); });
				return r.json();
			}).then(function (createdJob) {
				job = createdJob;
				var model = String(job.payload && job.payload.model || '');
				var legacy = model.indexOf('codex-local:') === 0;
				var legacyEndpoints = { chat: '/v1/chat', image: '/v1/images', transcribe: '/v1/transcribe' };
				var relayEndpoints = { chat: '/v1/relay/jobs/chat', image: '/v1/relay/jobs/images', video: '/v1/relay/jobs/videos', transcribe: '/v1/relay/jobs/transcribe' };
				var endpoint = legacy && legacyEndpoints[type] ? legacyEndpoints[type] : relayEndpoints[type];
				if (!endpoint) throw withLocalCodexContext({ message: 'AI Model Relay does not support this job type.' }, bridge);
				return localCodexFetch(bridge.bridgeUrl + endpoint, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-Alorbach-Bridge-Token': bridge.token,
						'X-Alorbach-Request-Id': job.request_id
					},
					body: {
						job_token: job.job_token,
						request_hash: job.request_hash,
						request_id: job.request_id,
						payload: job.payload
					}
				});
			}).then(function (bridgeResponse) {
				if (!bridgeResponse.ok) {
					return localCodexReadJson(bridgeResponse).then(function (d) { throw withLocalCodexContext(d, bridge); });
				}
				return bridgeResponse.json();
			}).then(function (bridgeResult) {
				if (!bridgeResult.success) {
					throw bridgeResult;
				}
				return apiFetch('/ai-bridge/jobs/' + encodeURIComponent(job.job_id) + '/complete', {
					method: 'POST',
					body: {
						job_token: job.job_token,
						request_hash: job.request_hash,
						result: bridgeResult
					}
				}).then(function (r) {
					if (!r.ok) return localCodexReadJson(r).then(function (d) { throw withLocalCodexContext(d, bridge); });
					return r.json();
				});
			}).catch(function (err) {
				if (job && job.job_id) {
					apiFetch('/ai-bridge/jobs/' + encodeURIComponent(job.job_id) + '/fail', {
						method: 'POST',
						body: {
							job_token: job.job_token,
							request_hash: job.request_hash,
							message: err.message || 'AI Model Relay failed.'
						}
					}).catch(function () {});
				}
				throw withLocalCodexContext(err, bridge);
			});
		}

		function attempt(forcePairing, allowReconnect) {
			return (localConfig ? Promise.resolve(localConfig) : getLocalCodexConfig().then(function (cfg) {
				localConfig = cfg;
				return localConfig;
			})).then(function (cfg) {
				return ensureLocalCodexPairing(cfg, { forcePairing: forcePairing });
			}).then(function (bridge) {
				return runWithBridge(bridge);
			}).catch(function (err) {
				var normalized = withLocalCodexContext(err, getLocalCodexBridge(localConfig || {}, false));
				if (allowReconnect && isLocalCodexPairingError(normalized)) {
					return attempt(true, false);
				}
				throw normalized;
			});
		}

		return attempt(!!opts.forcePairing, opts.retryPairing !== false);
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
		return apiFetch('/me/models').then(function (r) { return r.json(); }).then(function (models) {
			return discoverAiBridgeModels(models).catch(function () { return models; });
		});
	}

	function addDiscoveredModel(models, type, id, label) {
		var parent = models[type] || {};
		var section = type === 'image' ? (parent.model || {}) : parent;
		section.options = Array.isArray(section.options) ? section.options : [];
		section.labels = section.labels || {};
		if (section.options.indexOf(id) === -1) section.options.push(id);
		section.labels[id] = label || id;
		if (type === 'image') {
			parent.model = section;
			models[type] = parent;
		} else {
			models[type] = section;
		}
	}

	function removeUndiscoveredRelayModels(models) {
		['text', 'image', 'audio', 'video'].forEach(function (type) {
			var parent = models[type] || {};
			var section = type === 'image' ? (parent.model || {}) : parent;
			section.options = (section.options || []).filter(function (id) {
				id = String(id || '');
				return id.indexOf('model-relay:') !== 0 && id !== 'local-asr' && id.indexOf('local-asr:') !== 0;
			});
			if (type === 'image') {
				parent.model = section;
				models[type] = parent;
			} else {
				models[type] = section;
			}
		});
		return models;
	}

	function relayModelType(id) {
		if (id === 'local-asr' || id.indexOf('local-asr:') === 0 || id.indexOf('model-relay:local-asr:') === 0) return 'audio';
		if (/:image$/.test(id)) return 'image';
		if (/:video$/.test(id)) return 'video';
		return 'text';
	}

	function isSupportedDiscoveredRelayModel(id) {
		return /^model-relay:(codex|grok-cli|cursor-cli):[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/.test(id)
			|| /^model-relay:local-asr:[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/.test(id)
			|| /^local-asr(?::[A-Za-z0-9][A-Za-z0-9._-]{0,127})?$/.test(id);
	}

	function relayBackendFromModel(id) {
		var match = String(id || '').match(/^model-relay:([^:]+):/);
		return match ? match[1] : (String(id || '').indexOf('local-asr') === 0 ? 'local-asr' : '');
	}

	function discoverAiBridgeModels(models) {
		models = removeUndiscoveredRelayModels(models);
		return getLocalCodexConfig().then(function (cfg) {
			var bridge = getLocalCodexBridge(cfg, false);
			if (!bridge.token) return models;
			var headers = { 'Content-Type': 'application/json', 'X-Alorbach-Bridge-Token': bridge.token };
			return Promise.all([
				localCodexFetch(bridge.bridgeUrl + '/v1/relay/models', { method: 'GET', headers: headers }),
				localCodexFetch(bridge.bridgeUrl + '/v1/relay/capabilities', { method: 'GET', headers: headers })
			]).then(function (responses) {
				if (!responses[0].ok || !responses[1].ok) return models;
				return Promise.all(responses.map(localCodexReadJson)).then(function (payloads) {
					var modelPayload = payloads[0] || {};
					var capabilities = payloads[1] || {};
					var ready = {};
					var backends = capabilities.backends || capabilities.backend_status || [];
					if (Array.isArray(backends)) {
						backends.forEach(function (backend) {
							if (backend && (backend.id || backend.name)) ready[backend.id || backend.name] = backend.ready === true || backend.available === true;
						});
					} else if (backends && typeof backends === 'object') {
						Object.keys(backends).forEach(function (id) {
							var state = backends[id];
							ready[id] = state === true || (state && (state.ready === true || state.available === true));
						});
					}
					var relayIds = (modelPayload.models && modelPayload.models.relay) || [];
					var audioIds = (modelPayload.models && modelPayload.models.audio) || [];
					var ids = relayIds.concat(audioIds).filter(function (id, index, all) { return all.indexOf(id) === index; });
					ids.forEach(function (entry) {
						var id = String(entry && typeof entry === 'object' ? entry.id || '' : entry || '');
						var backend = relayBackendFromModel(id);
						if (!isSupportedDiscoveredRelayModel(id) || ready[backend] !== true) return;
						var label = id;
						if (id.indexOf('model-relay:grok-cli:') === 0) label = 'Grok CLI - ' + id.split(':').pop() + (/:video$/.test(id) ? ' (experimental)' : '');
						if (id.indexOf('model-relay:cursor-cli:') === 0) label = 'Cursor Agent - ' + id.split(':').pop();
						if (id.indexOf('model-relay:codex:') === 0) label = 'Codex CLI - ' + id.split(':').pop();
						if (backend === 'local-asr') label = 'Local ASR - ' + id.split(':').pop();
						addDiscoveredModel(models, relayModelType(id), id, label);
					});
					return models;
				});
			});
		});
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

	function getLocalCodexStatusHelper(container) {
		var helper = container.querySelector('.alorbach-local-codex-status');
		var formCard;
		var actionRow;
		if (helper) {
			return helper;
		}
		formCard = container.querySelector('.alorbach-demo-form-card') || container;
		actionRow = formCard.querySelector('.alorbach-demo-action-row');
		helper = document.createElement('div');
		helper.className = 'alorbach-local-codex-status is-hidden';
		helper.setAttribute('aria-live', 'polite');
		helper.innerHTML = '<span class="alorbach-local-codex-dot" aria-hidden="true"></span><span class="alorbach-local-codex-copy"><strong class="alorbach-local-codex-title">AI Model Relay</strong><span class="alorbach-local-codex-detail">Checking relay status...</span></span><button type="button" class="button alorbach-local-codex-reconnect">Reconnect</button>';
		if (actionRow && actionRow.parentNode) {
			actionRow.parentNode.insertBefore(helper, actionRow);
		} else {
			formCard.appendChild(helper);
		}
		return helper;
	}

	function setLocalCodexStatus(container, state, title, detail) {
		var helper = getLocalCodexStatusHelper(container);
		helper.className = 'alorbach-local-codex-status is-' + state;
		helper.querySelector('.alorbach-local-codex-title').textContent = title || 'AI Model Relay';
		helper.querySelector('.alorbach-local-codex-detail').textContent = detail || '';
		return helper;
	}

	function hideLocalCodexStatus(container) {
		var helper = container.querySelector('.alorbach-local-codex-status');
		if (helper) {
			helper.className = 'alorbach-local-codex-status is-hidden';
		}
	}

	function refreshLocalCodexStatus(container, selectedModel) {
		if (!isLocalCodexModel(selectedModel)) {
			hideLocalCodexStatus(container);
			return Promise.resolve(null);
		}

		setLocalCodexStatus(container, 'checking', 'AI Model Relay', 'Checking relay status...');
		return getLocalCodexConfig().then(function (cfg) {
			return getLocalCodexStatus(cfg);
		}).then(function (status) {
			if (status.state === 'connected') {
				setLocalCodexStatus(container, 'connected', 'AI Model Relay connected', 'Local relay is reachable and this browser has a pairing token.');
			} else if (status.state === 'unpaired') {
				setLocalCodexStatus(container, 'unpaired', 'AI Model Relay pairing needed', status.message);
			} else {
				setLocalCodexStatus(container, 'offline', 'AI Model Relay not reachable', status.message);
			}
			return status;
		}).catch(function (err) {
			setLocalCodexStatus(container, 'offline', 'AI Model Relay unavailable', getLocalCodexErrorMessage(err, 'AI Model Relay configuration is unavailable.'));
			return null;
		});
	}

	function bindLocalCodexStatus(container, getSelectedModel) {
		var helper = getLocalCodexStatusHelper(container);
		var reconnectBtn = helper.querySelector('.alorbach-local-codex-reconnect');

		function selectedModel() {
			return getSelectedModel ? getSelectedModel() : '';
		}

		if (reconnectBtn) {
			reconnectBtn.addEventListener('click', function () {
				if (!isLocalCodexModel(selectedModel())) {
					hideLocalCodexStatus(container);
					return;
				}
				reconnectBtn.disabled = true;
				setLocalCodexStatus(container, 'checking', 'Reconnecting AI Model Relay', 'Checking the tray app and refreshing this origin pairing...');
				getLocalCodexConfig().then(function (cfg) {
					return ensureLocalCodexPairing(cfg, { forcePairing: true });
				}).then(function () {
					setLocalCodexStatus(container, 'connected', 'AI Model Relay connected', 'Pairing token refreshed for this WordPress origin.');
				}).catch(function (err) {
					var msg = getLocalCodexErrorMessage(err, 'Could not reconnect to AI Model Relay.');
					var state = isLocalCodexPairingError(err) ? 'unpaired' : 'offline';
					setLocalCodexStatus(container, state, state === 'unpaired' ? 'AI Model Relay pairing needed' : 'AI Model Relay not reachable', msg);
				}).finally(function () {
					reconnectBtn.disabled = false;
				});
			});
		}

		return refreshLocalCodexStatus(container, selectedModel());
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

		function currentChatModel() {
			return (modelSelect && modelSelect.value) || container.dataset.model || 'gpt-4.1-mini';
		}

		getModels(container).then(function (models) {
			var text = models.text || {};
			var canSelectTextModel = isAllowSelectEnabled(text.allow_select);
			var wrap = container.querySelector('.alorbach-demo-model-wrap');
			if (wrap) wrap.style.display = canSelectTextModel ? '' : 'none';
			if (canSelectTextModel && text.options && text.options.length) {
				modelSelect.innerHTML = '';
				var textLabels = text.labels || {};
				text.options.forEach(function (opt) {
					var o = document.createElement('option');
					o.value = opt;
					o.textContent = textLabels[opt] || opt;
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
			refreshLocalCodexStatus(container, currentChatModel());
		});

		bindLocalCodexStatus(container, currentChatModel);
		if (modelSelect) {
			modelSelect.addEventListener('change', function () {
				container.dataset.model = modelSelect.value || container.dataset.model || 'gpt-4.1-mini';
				refreshLocalCodexStatus(container, currentChatModel());
			});
		}

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
			var model = currentChatModel();
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

			var request = isLocalCodexModel(model)
				? localCodexExecute('chat', { messages: messages, model: model, max_tokens: maxTokens })
				: apiFetch('/chat', {
					method: 'POST',
					body: { messages: messages, model: model, max_tokens: maxTokens },
				}).then(function (r) {
					if (!r.ok) return r.json().then(function (d) { throw d; });
					return r.json();
				});

			request.then(function (data) {
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
				if (err && err.localCodex && isLocalCodexBridgeReachabilityError(err)) {
					refreshLocalCodexStatus(container, currentChatModel());
				}
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
		var referenceInput = container.querySelector('.alorbach-demo-image-reference');
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

		function currentImageModel() {
			return (modelSelect && modelSelect.value) || container.dataset.model || 'dall-e-3';
		}

		function isCodexImageModel(model) {
			return String(model || '').indexOf('codex-image-') === 0 || model === 'codex-local:image';
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
			var model = currentImageModel();
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
			var canSelectQuality = isAllowSelectEnabled(q.allow_select);
			qualityWrap.style.display = canSelectQuality ? '' : 'none';
		}

		getModels(container).then(function (models) {
			var img = models.image || {};
			imageConfig = img;
			var sizeOpts = img.size || {};
			var modelOpts = img.model || {};
			var q = img.quality || {};

			var canSelectSize = isAllowSelectEnabled(sizeOpts.allow_select);
			var wrap = container.querySelector('.alorbach-demo-size-wrap');
			if (wrap) wrap.style.display = (canSelectSize && sizeOpts.options && sizeOpts.options.length) ? '' : 'none';
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

			var canSelectImageModel = isAllowSelectEnabled(modelOpts.allow_select);
			if (modelWrap) modelWrap.style.display = (canSelectImageModel && modelOpts.options && modelOpts.options.length) ? '' : 'none';
			if (canSelectImageModel && modelOpts.options && modelOpts.options.length && modelSelect) {
				modelSelect.innerHTML = '';
				var modelLabels = modelOpts.labels || {};
				modelOpts.options.forEach(function (opt) {
					var o = document.createElement('option');
					o.value = opt;
					o.textContent = modelLabels[opt] || opt;
					if (opt === modelOpts.default) o.selected = true;
					modelSelect.appendChild(o);
				});
			}
			container.dataset.model = modelOpts.default || 'dall-e-3';

			container.dataset.quality = getDefaultQualityForModel(container.dataset.model, q);
			syncQualityOptions();
			syncQualityVisibility();
			refreshLocalCodexStatus(container, currentImageModel());
			refreshImageCost();
		});

		function refreshImageCost() {
			var size = (sizeSelect && sizeSelect.value) || container.dataset.size || '1024x1024';
			var quality = (qualitySelect && qualitySelect.value) || container.dataset.quality || 'medium';
			var model = currentImageModel();
			var n = (nInput && parseInt(nInput.value, 10)) || 1;
			n = Math.min(10, Math.max(1, n));
			var estimateParams = { type: 'image', size: size, model: model, n: n };
			if (qualityWrap && qualityWrap.style.display !== 'none') estimateParams.quality = quality;
			updateCostEstimate(container, 'image', estimateParams, container.querySelector('.alorbach-demo-cost'));
		}

		if (sizeSelect) sizeSelect.addEventListener('change', refreshImageCost);
		if (modelSelect) modelSelect.addEventListener('change', function () {
			container.dataset.model = modelSelect.value || container.dataset.model || 'dall-e-3';
			syncQualityOptions();
			syncQualityVisibility();
			refreshLocalCodexStatus(container, currentImageModel());
			refreshImageCost();
		});
		if (qualitySelect) qualitySelect.addEventListener('change', function () {
			container.dataset.quality = qualitySelect.value || container.dataset.quality || 'medium';
			refreshImageCost();
		});
		if (nInput) nInput.addEventListener('change', refreshImageCost);
		if (nInput) nInput.addEventListener('input', refreshImageCost);
		bindLocalCodexStatus(container, currentImageModel);

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
			var model = currentImageModel();
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

			if (isLocalCodexModel(model)) {
				body.model = model;
				body.n = 1;
				beginEstimatedProgress();
				var referencePromise = referenceInput && referenceInput.files && referenceInput.files[0]
					? readFileDataUrl(referenceInput.files[0]).then(function (dataUrl) { body.reference_images = [dataUrl]; })
					: Promise.resolve();
				referencePromise.then(function () { return localCodexExecute('image', body); }).then(function (data) {
					setProgress(100, 'completed', 'estimated', true);
					renderFinalImages(data.data || [], prompt);
					renderUsage(data);
					getBalance(container);
				}).catch(function (err) {
					handleError(err, container);
					if (err && err.localCodex && isLocalCodexBridgeReachabilityError(err)) {
						refreshLocalCodexStatus(container, currentImageModel());
					}
				}).finally(function () {
					finishGenerate();
				});
				return;
			}

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
			var canSelectAudioModel = isAllowSelectEnabled(audio.allow_select);
			var wrap = container.querySelector('.alorbach-demo-model-wrap');
			if (wrap) wrap.style.display = canSelectAudioModel ? '' : 'none';
			if (canSelectAudioModel && audio.options && audio.options.length) {
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
					var request = isLocalCodexModel(model) ? localCodexExecute('transcribe', body) : apiFetch('/transcribe', {
						method: 'POST',
						body: body,
					}).then(function (r) {
						if (!r.ok) return r.json().then(function (d) { throw d; });
						return r.json();
					});
					request.then(function (data) {
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
		var referenceInput = container.querySelector('.alorbach-demo-video-reference');

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
			var canSelectVideoModel = isAllowSelectEnabled(video.allow_select);
			var wrap = container.querySelector('.alorbach-demo-model-wrap');
			if (wrap) wrap.style.display = canSelectVideoModel ? '' : 'none';
			if (canSelectVideoModel && video.options && video.options.length && modelSelect) {
				modelSelect.innerHTML = '';
				video.options.forEach(function (opt) {
					var o = document.createElement('option');
					o.value = opt;
					o.textContent = (video.labels && video.labels[opt]) || opt;
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

			var body = { prompt: prompt, model: model, size: size, duration_seconds: duration };
			var referencePromise = referenceInput && referenceInput.files && referenceInput.files[0]
				? readFileDataUrl(referenceInput.files[0]).then(function (dataUrl) { body.input_reference = dataUrl; })
				: Promise.resolve();
			referencePromise.then(function () {
				if (isLocalCodexModel(model)) return localCodexExecute('video', body);
				return apiFetch('/video', {
				method: 'POST',
				body: body,
			}).then(function (r) {
				if (!r.ok) return r.json().then(function (d) { throw d; });
				return r.json();
			});
			}).then(function (data) {
				if (resultEl && data.data && data.data.length) {
					var baseName = (prompt || 'video').replace(/[^a-zA-Z0-9-_]/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, '').slice(0, 40) || 'video';
					data.data.forEach(function (item, idx) {
						var url = item.url || '';
						if (!url && item.b64_video) {
							var binary = window.atob(item.b64_video);
							var bytes = new Uint8Array(binary.length);
							for (var byteIndex = 0; byteIndex < binary.length; byteIndex++) bytes[byteIndex] = binary.charCodeAt(byteIndex);
							url = window.URL.createObjectURL(new Blob([bytes], { type: item.mime_type || 'video/mp4' }));
						}
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
