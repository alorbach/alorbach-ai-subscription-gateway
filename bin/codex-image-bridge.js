#!/usr/bin/env node
'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const http = require('http');
const { spawnSync } = require('child_process');

const mode = process.argv[2] || 'check';
const codexBinary = process.env.ALORBACH_CODEX_BINARY || 'codex';
const codexHome = process.env.CODEX_HOME || path.join(os.homedir(), '.codex');
const authPath = path.join(codexHome, 'auth.json');
const generatedImagesDir = path.join(codexHome, 'generated_images');

function readStdin() {
	return new Promise((resolve) => {
		let chunks = '';
		process.stdin.setEncoding('utf8');
		process.stdin.on('data', (chunk) => {
			chunks += chunk;
		});
		process.stdin.on('end', () => resolve(chunks));
		process.stdin.resume();
	});
}

function resolveCodexBinary() {
	if (process.platform !== 'win32' || /[\\/]/.test(codexBinary)) {
		return codexBinary;
	}

	const lookup = spawnSync('where.exe', [codexBinary], {
		encoding: 'utf8',
		shell: false,
	});
	if (lookup.status === 0) {
		const matches = (lookup.stdout || '')
			.split(/\r?\n/)
			.map((line) => line.trim())
			.filter(Boolean);
		const preferred = matches.find((line) => /\.exe$/i.test(line))
			|| matches.find((line) => /\.(cmd|bat)$/i.test(line))
			|| matches[0];
		if (preferred) {
			return preferred;
		}
	}

	return codexBinary;
}

function emitAndExit(payload, code = 0) {
	process.stdout.write(JSON.stringify(payload));
	process.exit(code);
}

function runCodex(args, options = {}) {
	return spawnSync(resolveCodexBinary(), args, {
		encoding: 'utf8',
		shell: false,
		...options,
	});
}

function checkPrerequisites() {
	const version = runCodex(['--version']);
	if (version.error) {
		return {
			success: false,
			message: 'Codex CLI is not installed or not on PATH.',
			details: { error: version.error.message || String(version.error) },
		};
	}
	if (version.status !== 0) {
		return {
			success: false,
			message: 'Codex CLI was found, but `codex --version` failed.',
			details: { stderr: (version.stderr || '').trim() },
		};
	}

	const login = runCodex(['login', 'status']);
	const loginText = `${login.stdout || ''}\n${login.stderr || ''}`;
	if (login.error) {
		return {
			success: false,
			message: 'Codex CLI is installed, but login status could not be checked.',
			details: { error: login.error.message || String(login.error) },
		};
	}
	if (login.status !== 0 || !/logged in/i.test(loginText)) {
		return {
			success: false,
			message: 'Codex CLI is not logged in. Run `codex login` in this local user account first.',
			details: { stdout: (login.stdout || '').trim(), stderr: (login.stderr || '').trim() },
		};
	}
	if (!fs.existsSync(authPath)) {
		return {
			success: false,
			message: 'Codex CLI login looks incomplete because the local auth file was not found.',
			details: { auth_path: authPath },
		};
	}

	return {
		success: true,
		message: 'Local Codex CLI is installed and logged in.',
		details: {
			codex_home: codexHome,
			auth_path: authPath,
			generated_images_dir: generatedImagesDir,
			version: (version.stdout || version.stderr || '').trim(),
			login_status: (login.stdout || login.stderr || '').trim(),
		},
	};
}

function listGeneratedImages(dir) {
	const results = [];
	if (!fs.existsSync(dir)) {
		return results;
	}

	const stack = [dir];
	while (stack.length > 0) {
		const current = stack.pop();
		let entries = [];
		try {
			entries = fs.readdirSync(current, { withFileTypes: true });
		} catch (error) {
			continue;
		}

		for (const entry of entries) {
			const fullPath = path.join(current, entry.name);
			if (entry.isDirectory()) {
				stack.push(fullPath);
				continue;
			}
			if (!/\.(png|jpe?g|webp)$/i.test(entry.name)) {
				continue;
			}
			try {
				const stat = fs.statSync(fullPath);
				results.push({ path: fullPath, mtimeMs: stat.mtimeMs });
			} catch (error) {
				// Ignore unreadable files.
			}
		}
	}

	return results;
}

function detectMimeType(imagePath) {
	const extension = path.extname(String(imagePath || '')).toLowerCase();
	if (extension === '.jpg' || extension === '.jpeg') {
		return 'image/jpeg';
	}
	if (extension === '.webp') {
		return 'image/webp';
	}
	return 'image/png';
}

function readPngDimensions(buffer) {
	if (!Buffer.isBuffer(buffer) || buffer.length < 24) {
		return null;
	}
	if (buffer.readUInt32BE(0) !== 0x89504e47) {
		return null;
	}
	if (buffer.toString('ascii', 12, 16) !== 'IHDR') {
		return null;
	}
	return {
		width: buffer.readUInt32BE(16),
		height: buffer.readUInt32BE(20),
	};
}

function readJpegDimensions(buffer) {
	if (!Buffer.isBuffer(buffer) || buffer.length < 4 || buffer[0] !== 0xff || buffer[1] !== 0xd8) {
		return null;
	}
	let offset = 2;
	while (offset + 9 < buffer.length) {
		if (buffer[offset] !== 0xff) {
			offset += 1;
			continue;
		}
		const marker = buffer[offset + 1];
		const length = buffer.readUInt16BE(offset + 2);
		if (length < 2 || offset + 2 + length > buffer.length) {
			return null;
		}
		if ((marker >= 0xc0 && marker <= 0xc3) || (marker >= 0xc5 && marker <= 0xc7) || (marker >= 0xc9 && marker <= 0xcb) || (marker >= 0xcd && marker <= 0xcf)) {
			return {
				height: buffer.readUInt16BE(offset + 5),
				width: buffer.readUInt16BE(offset + 7),
			};
		}
		offset += 2 + length;
	}
	return null;
}

function readWebpDimensions(buffer) {
	if (!Buffer.isBuffer(buffer) || buffer.length < 30) {
		return null;
	}
	if (buffer.toString('ascii', 0, 4) !== 'RIFF' || buffer.toString('ascii', 8, 12) !== 'WEBP') {
		return null;
	}
	const chunk = buffer.toString('ascii', 12, 16);
	if (chunk === 'VP8X' && buffer.length >= 30) {
		const width = 1 + buffer.readUIntLE(24, 3);
		const height = 1 + buffer.readUIntLE(27, 3);
		return { width, height };
	}
	return null;
}

function getImageMetadata(imagePath) {
	try {
		const buffer = fs.readFileSync(imagePath);
		const stat = fs.statSync(imagePath);
		let dimensions = readPngDimensions(buffer);
		if (!dimensions) {
			dimensions = readJpegDimensions(buffer);
		}
		if (!dimensions) {
			dimensions = readWebpDimensions(buffer);
		}
		return {
			mime_type: detectMimeType(imagePath),
			bytes: stat.size,
			width: dimensions ? dimensions.width : null,
			height: dimensions ? dimensions.height : null,
		};
	} catch (error) {
		return null;
	}
}

function detectNewImage(before, after) {
	const known = new Set(before.map((item) => item.path.toLowerCase()));
	return after
		.filter((item) => !known.has(item.path.toLowerCase()))
		.sort((a, b) => b.mtimeMs - a.mtimeMs);
}

function normalizeQuality(payload) {
	const quality = String(payload.quality || '').trim().toLowerCase();
	if (quality === 'medium' || quality === 'high') {
		return quality;
	}
	return 'high';
}

function normalizeTokenCount(rawValue) {
	const digits = String(rawValue || '').replace(/[^\d]/g, '');
	if (!digits) {
		return 0;
	}
	const parsed = Number.parseInt(digits, 10);
	return Number.isFinite(parsed) ? parsed : 0;
}

function parseUsage(stdout, stderr) {
	const combined = `${stdout || ''}\n${stderr || ''}`;
	const patterns = [
		/tokens used\s*[:\-]?\s*([\d,]+)/i,
		/tokens used[\s\S]{0,80}?([\d,]+)/i,
	];

	for (const pattern of patterns) {
		const match = combined.match(pattern);
		if (!match || !match[1]) {
			continue;
		}
		const totalTokens = normalizeTokenCount(match[1]);
		if (totalTokens > 0) {
			return { total_tokens: totalTokens };
		}
	}

	return null;
}

function summarizeRunOutput(stdout, stderr) {
	const summary = [];
	const combined = `${stdout || ''}\n${stderr || ''}`;
	const lines = combined
		.split(/\r?\n/)
		.map((line) => line.trim())
		.filter(Boolean);

	for (const line of lines) {
		if (!/(generated|tokens used|image generated|completed)/i.test(line)) {
			continue;
		}
		if (summary.includes(line)) {
			continue;
		}
		summary.push(line);
		if (summary.length >= 4) {
			break;
		}
	}

	return summary;
}

function buildPrompt(payload) {
	const prompt = String(payload.prompt || '').trim();
	const size = String(payload.size || '1024x1024').trim();
	const quality = normalizeQuality(payload);
	const format = String(payload.output_format || 'png').trim();

	return [
		'Generate exactly one image using your built-in image generation tool.',
		'If your image tool supports model selection, prefer gpt-image-2 or a better current image model for this request.',
		`User prompt: ${prompt}`,
		`Requested size: ${size}`,
		`Preferred quality: ${quality}.`,
		`Preferred output format: ${format}`,
		'After the image has been generated, reply with a short plain-text confirmation only.',
	].join('\n');
}

function handleGenerate(payload) {
	const status = checkPrerequisites();
	if (!status.success) {
		return status;
	}

	const prompt = String(payload.prompt || '').trim();
	const referenceImages = Array.isArray(payload.reference_images) ? payload.reference_images : [];
	if (!prompt) {
		return { success: false, message: 'No image prompt was provided to the Codex image bridge.' };
	}
	if (referenceImages.length > 0) {
		return { success: false, message: 'Reference-image edits are not supported by the local Codex CLI bridge yet.' };
	}

	fs.mkdirSync(generatedImagesDir, { recursive: true });
	const before = listGeneratedImages(generatedImagesDir);

	const tempDir = fs.mkdtempSync(path.join(os.tmpdir(), 'alorbach-codex-image-'));
	const outputFile = path.join(tempDir, 'last-message.txt');
	const promptText = buildPrompt(payload);
	const args = [
		'exec',
		'--skip-git-repo-check',
		'--ephemeral',
		'--dangerously-bypass-approvals-and-sandbox',
		'--cd',
		tempDir,
		'--output-last-message',
		outputFile,
		promptText,
	];

	const run = runCodex(args, { cwd: tempDir });
	const after = listGeneratedImages(generatedImagesDir);
	const newImages = detectNewImage(before, after);
	const stdout = (run.stdout || '').trim();
	const stderr = (run.stderr || '').trim();
	const usage = parseUsage(stdout, stderr);
	const outputSummary = summarizeRunOutput(stdout, stderr);
	let responseText = '';
	if (fs.existsSync(outputFile)) {
		try {
			responseText = fs.readFileSync(outputFile, 'utf8').trim();
		} catch (error) {
			responseText = '';
		}
	}

	if (run.error) {
		return {
			success: false,
			message: 'Codex CLI could not be executed for image generation.',
			details: { error: run.error.message || String(run.error) },
		};
	}

	if (run.status !== 0) {
		return {
			success: false,
			message: 'Codex CLI image generation failed.',
			details: {
				stdout: stdout,
				stderr: stderr,
				response_text: responseText,
			},
		};
	}

	if (newImages.length === 0) {
		return {
			success: false,
			message: 'Codex CLI completed, but no new generated image file was detected in the local Codex image directory.',
			details: {
				generated_images_dir: generatedImagesDir,
				stdout: stdout,
				stderr: stderr,
				response_text: responseText,
			},
		};
	}

	const result = {
		success: true,
		message: 'Image generated through the local Codex CLI bridge.',
		images: [newImages[0].path],
		response_text: responseText,
		internal_prompt: promptText,
		details: {
			generated_images_dir: generatedImagesDir,
			image_path: newImages[0].path,
			output_summary: outputSummary,
		},
	};
	const imageMetadata = getImageMetadata(newImages[0].path);
	if (imageMetadata) {
		result.details.image_metadata = imageMetadata;
	}

	if (usage) {
		result.usage = usage;
	}

	return result;
}

function attachInlineImages(result) {
	if (!result || !result.success || !Array.isArray(result.images)) {
		return result;
	}

	const imageData = [];
	for (const imagePath of result.images) {
		if (!imagePath || !fs.existsSync(imagePath)) {
			continue;
		}
		try {
			const bytes = fs.readFileSync(imagePath);
			imageData.push({
				path: imagePath,
				b64_json: bytes.toString('base64'),
			});
		} catch (error) {
			// Ignore unreadable host files.
		}
	}

	if (imageData.length > 0) {
		result.image_data = imageData;
	}

	return result;
}

function startServer() {
	const port = Number(process.env.ALORBACH_CODEX_BRIDGE_PORT || 8765);
	const server = http.createServer(async (req, res) => {
		if (req.method !== 'POST') {
			res.writeHead(405, { 'Content-Type': 'application/json' });
			res.end(JSON.stringify({ success: false, message: 'Method not allowed.' }));
			return;
		}

		let body = '';
		req.setEncoding('utf8');
		req.on('data', (chunk) => {
			body += chunk;
		});
		req.on('end', () => {
			let input = {};
			try {
				input = body.trim() ? JSON.parse(body) : {};
			} catch (error) {
				res.writeHead(400, { 'Content-Type': 'application/json' });
				res.end(JSON.stringify({ success: false, message: 'Bridge input was not valid JSON.' }));
				return;
			}

			const routeMode = String(input.mode || '');
			let result;
			if (routeMode === 'check') {
				result = checkPrerequisites();
			} else if (routeMode === 'generate') {
				const payload = input && typeof input === 'object' && input.payload ? input.payload : {};
				result = attachInlineImages(handleGenerate(payload));
			} else {
				result = { success: false, message: `Unknown bridge mode: ${routeMode}` };
			}

			res.writeHead(result.success ? 200 : 500, { 'Content-Type': 'application/json' });
			res.end(JSON.stringify(result));
		});
	});

	server.listen(port, '0.0.0.0', () => {
		process.stdout.write(`Codex image bridge server listening on port ${port}\n`);
	});
}

async function main() {
	if (mode === 'serve') {
		startServer();
		return;
	}

	const stdin = await readStdin();
	let input = {};
	try {
		input = stdin.trim() ? JSON.parse(stdin) : {};
	} catch (error) {
		emitAndExit({ success: false, message: 'Bridge input was not valid JSON.' }, 1);
	}

	if (mode === 'check') {
		const status = checkPrerequisites();
		emitAndExit(status, status.success ? 0 : 1);
		return;
	}

	if (mode !== 'generate') {
		emitAndExit({ success: false, message: `Unknown bridge mode: ${mode}` }, 1);
		return;
	}

	const payload = input && typeof input === 'object' && input.payload ? input.payload : {};
	const result = handleGenerate(payload);
	emitAndExit(result, result.success ? 0 : 1);
}

main().catch((error) => {
	emitAndExit({
		success: false,
		message: error && error.message ? error.message : 'Unexpected Codex image bridge failure.',
	}, 1);
});
