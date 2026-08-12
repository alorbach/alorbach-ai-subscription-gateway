/**
 * Shared browser-side AI Model Relay catalog discovery.
 *
 * The relay is user-owned and may expose different backends/models per
 * machine, so this module deliberately has no model catalog of its own.
 *
 * @package Alorbach\AIGateway
 */
(function (window) {
	'use strict';

	function readJson(response) {
		return response.json().catch(function () { return {}; });
	}

	function errorFromResponse(response, data, fallback) {
		var serverMessage = data && (data.message || data.error);
		var pairingFailure = response.status === 401 || response.status === 403
			? /pair|origin|token|auth/i.test(String(serverMessage || ''))
			: false;
		var error = new Error(pairingFailure
			? 'AI Model Relay is not paired with this WordPress origin. Pair it in the browser before importing models.'
			: (serverMessage || fallback || ('AI Model Relay request failed (' + response.status + ').')));
		error.code = pairingFailure ? 'relay_not_paired' : ((data && data.code) || 'relay_request_failed');
		error.status = response.status;
		error.data = data || {};
		return error;
	}

	function jsonRequest(url, options) {
		var opts = Object.assign({
			method: 'GET',
			mode: 'cors',
			cache: 'no-store',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json' }
		}, options || {});
		if (opts.body && typeof opts.body === 'object') opts.body = JSON.stringify(opts.body);
		return fetch(url, opts).then(function (response) {
			return readJson(response).then(function (data) {
				if (!response.ok) throw errorFromResponse(response, data);
				return data;
			});
		}).catch(function (error) {
			if (error && error.code) throw error;
			var wrapped = new Error('AI Model Relay is not reachable.');
			wrapped.code = 'relay_unreachable';
			wrapped.cause = error;
			throw wrapped;
		});
	}

	function storageKey(origin) {
		return 'alorbachAiBridgeToken:' + String(origin || window.location.origin);
	}

	function getBridge(config) {
		var origin = config.origin || window.location.origin;
		var key = storageKey(origin);
		var legacyKey = 'alorbachLocalCodexToken:' + String(origin);
		var token = window.localStorage ? window.localStorage.getItem(key) : '';
		if (!token && window.localStorage) {
			token = window.localStorage.getItem(legacyKey) || '';
			if (token) window.localStorage.setItem(key, token);
		}
		return {
			bridgeUrl: String(config.bridge_url || 'http://127.0.0.1:8765').replace(/\/$/, ''),
			origin: origin,
			token: token
		};
	}

	function pair(bridge, pairingCode) {
		pairingCode = String(pairingCode || '').trim();
		if (!pairingCode) {
			var cancelled = new Error('AI Model Relay pairing was cancelled.');
			cancelled.code = 'relay_pairing_cancelled';
			return Promise.reject(cancelled);
		}
		return jsonRequest(bridge.bridgeUrl + '/v1/pair', {
			method: 'POST',
			body: { origin: bridge.origin, pairing_code: pairingCode }
		}).then(function (data) {
			var token = String(data && data.token || '');
			if (!token) {
				var error = new Error('AI Model Relay did not return a pairing token.');
				error.code = 'relay_pairing_failed';
				throw error;
			}
			if (window.localStorage) window.localStorage.setItem(storageKey(bridge.origin), token);
			bridge.token = token;
			return bridge;
		});
	}

	function ensurePairedBridge(options, bridge) {
		if (bridge.token) return Promise.resolve(bridge);
		if (typeof options.requestPairing !== 'function') {
			var pairingError = new Error('AI Model Relay is not paired with this WordPress origin. Pair it in the browser before importing models.');
			pairingError.code = 'relay_not_paired';
			return Promise.reject(pairingError);
		}
		return Promise.resolve(options.requestPairing(bridge)).then(function (pairingCode) {
			return pair(bridge, pairingCode);
		});
	}

	function pairFromConfig(options, pairingCode) {
		options = options || {};
		return loadConfig(options).then(function (config) {
			return pair(getBridge(config || {}), pairingCode);
		});
	}

	function loadConfig(options) {
		if (typeof options.getConfig === 'function') return options.getConfig();
		if (!options.configUrl) return Promise.reject(new Error('AI Model Relay configuration is unavailable.'));
		return jsonRequest(options.configUrl, { headers: options.wpHeaders || { 'Content-Type': 'application/json' } });
	}

	function isSupportedModelId(id) {
		id = String(id || '');
		return /^model-relay:[a-z0-9][a-z0-9-]{0,63}:[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/.test(id)
			|| /^local-asr(?::[A-Za-z0-9][A-Za-z0-9._-]{0,127})?$/.test(id);
	}

	function backendFromModel(id) {
		var match = String(id || '').match(/^model-relay:([^:]+):/);
		return match ? match[1] : (String(id || '').indexOf('local-asr') === 0 ? 'local-asr' : '');
	}

	function typeFromRecord(record, id) {
		var declared = String((record && (record.type || record.kind || record.media_type)) || '').toLowerCase();
		if (declared === 'text' || declared === 'image' || declared === 'video' || declared === 'audio') return declared;
		var capabilities = record && record.capabilities;
		if (Array.isArray(capabilities)) {
			if (capabilities.indexOf('text_to_image') !== -1) return 'image';
			if (capabilities.indexOf('text_to_video') !== -1) return 'video';
			if (capabilities.indexOf('audio_to_text') !== -1 || capabilities.indexOf('text_to_audio') !== -1) return 'audio';
		}
		id = String(id || '');
		if (id === 'local-asr' || id.indexOf('local-asr:') === 0 || id.indexOf('model-relay:local-asr:') === 0) return 'audio';
		if (/:image$/.test(id)) return 'image';
		if (/:video$/.test(id)) return 'video';
		return 'text';
	}

	function capabilityKeys(record, type) {
		if (record && Array.isArray(record.capabilities) && record.capabilities.length) return record.capabilities;
		return type === 'image' ? [ 'text_to_image' ] : type === 'video' ? [ 'text_to_video' ] : type === 'audio' ? [ 'audio_to_text' ] : [ 'text_to_text' ];
	}

	function readyBackends(capabilityPayload) {
		var ready = {};
		var backends = capabilityPayload && (capabilityPayload.backends || capabilityPayload.backend_status) || [];
		if (Array.isArray(backends)) {
			backends.forEach(function (backend) {
				if (!backend || !(backend.id || backend.name)) return;
				ready[backend.id || backend.name] = backend.ready === true || backend.available === true;
			});
		} else if (backends && typeof backends === 'object') {
			Object.keys(backends).forEach(function (id) {
				var state = backends[id];
				ready[id] = state === true || !!(state && (state.ready === true || state.available === true));
			});
		}
		return ready;
	}

	function modelRecords(modelPayload) {
		var modelGroups = modelPayload && modelPayload.models || {};
		var groups = [];
		if (modelPayload && Array.isArray(modelPayload.backends)) groups = groups.concat(modelPayload.backends);
		[ 'relay', 'audio' ].forEach(function (group) {
			if (Array.isArray(modelGroups[group])) groups = groups.concat(modelGroups[group]);
		});
		return groups;
	}

	function modelLabel(record, id, backend) {
		if (record && (record.label || record.name)) return String(record.label || record.name);
		var slug = id.split(':').pop();
		if (backend === 'grok-cli') return 'Grok CLI - ' + slug + (/:video$/.test(id) ? ' (experimental)' : '');
		if (backend === 'cursor-cli') return 'Cursor Agent - ' + slug;
		if (backend === 'codex-cli' || backend === 'codex') return 'Codex CLI - ' + slug;
		if (backend === 'local-asr') return 'Local ASR - ' + slug;
		return id;
	}

	function discover(options) {
		options = options || {};
		return loadConfig(options).then(function (config) {
			var bridge = getBridge(config || {});
			return ensurePairedBridge(options, bridge).then(function (pairedBridge) {
				var headers = Object.assign({ 'Content-Type': 'application/json', 'X-Alorbach-Bridge-Token': pairedBridge.token }, options.relayHeaders || {});
				return Promise.all([
					jsonRequest(pairedBridge.bridgeUrl + '/v1/relay/models', { headers: headers }),
					jsonRequest(pairedBridge.bridgeUrl + '/v1/relay/capabilities', { headers: headers })
				]).then(function (payloads) {
					var ready = readyBackends(payloads[1]);
					var items = [];
					modelRecords(payloads[0]).forEach(function (record) {
						var id = String(record && typeof record === 'object' ? (record.id || record.model || '') : record || '');
						if (!isSupportedModelId(id)) return;
						var backend = String((record && (record.backend || record.backend_id || record.provider)) || backendFromModel(id));
						if (ready[backend] !== true) return;
						var type = typeFromRecord(record, id);
						if (items.some(function (item) { return item.id === id; })) return;
						items.push({
							'id': id,
							'provider': 'ai_bridge',
							'type': type,
							'label': modelLabel(record, id, backend),
							'capabilities': capabilityKeys(record, type)
						});
					});
					if (!items.length) {
						var emptyError = new Error('AI Model Relay returned no models from ready backends.');
						emptyError.code = 'relay_no_ready_models';
						throw emptyError;
					}
					return { items: items, bridge: pairedBridge, capabilities: payloads[1] };
				});
			});
		});
	}

	function removeDiscoveredModels(models) {
		[ 'text', 'image', 'audio', 'video' ].forEach(function (type) {
			var parent = models[type] || {};
			var section = type === 'image' ? (parent.model || {}) : parent;
			section.options = (section.options || []).filter(function (id) {
				id = String(id || '');
				return id.indexOf('model-relay:') !== 0
					&& id !== 'local-asr' && id.indexOf('local-asr:') !== 0
					&& id !== 'codex-local' && id.indexOf('codex-local:') !== 0;
			});
			if (type === 'image') parent.model = section;
			models[type] = section === parent ? section : parent;
		});
		return models;
	}

	function addDiscoveredModel(models, type, item) {
		var parent = models[type] || {};
		var section = type === 'image' ? (parent.model || {}) : parent;
		section.options = Array.isArray(section.options) ? section.options : [];
		section.labels = section.labels || {};
		if (section.options.indexOf(item.id) === -1) section.options.push(item.id);
		section.labels[item.id] = item.label || item.id;
		if (type === 'image') {
			parent.model = section;
			models[type] = parent;
		} else {
			models[type] = section;
		}
	}

	function discoverModels(models, options) {
		var clean = removeDiscoveredModels(models || {});
		return discover(options).then(function (result) {
			result.items.forEach(function (item) { addDiscoveredModel(clean, item.type, item); });
			return { models: clean, items: result.items, bridge: result.bridge, capabilities: result.capabilities };
		});
	}

	window.alorbachAiModelRelay = {
		discover: discover,
		discoverModels: discoverModels,
		pair: pair,
		pairFromConfig: pairFromConfig,
		removeDiscoveredModels: removeDiscoveredModels,
		isSupportedModelId: isSupportedModelId,
		modelType: typeFromRecord
	};
}(window));
