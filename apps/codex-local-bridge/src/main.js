'use strict';

const { app, clipboard, Menu, nativeImage, shell, Tray } = require('electron');
const { startServer } = require('./server');
const codex = require('./codex');
const security = require('./security');

let tray = null;
let serverHandle = null;
let serverPort = 8765;
let currentPairingCode = '';

function buildMenu() {
	const status = codex.checkStatus();
	const pairedOrigins = Object.keys(security.getPairings());
	return Menu.buildFromTemplate([
		{ label: `Bridge: http://127.0.0.1:${serverPort}`, enabled: false },
		{ label: status.success ? 'Codex: logged in' : 'Codex: not logged in', enabled: false },
		{ label: `Pairing code: ${currentPairingCode || 'starting'}`, enabled: false },
		{ type: 'separator' },
		{
			label: 'Copy pairing code',
			click: () => clipboard.writeText(currentPairingCode || ''),
		},
		{
			label: 'Copy diagnostics',
			click: () => clipboard.writeText(JSON.stringify({
				port: serverPort,
				status,
				paired_origins: pairedOrigins,
				state_path: security.statePath,
			}, null, 2)),
		},
		{
			label: 'Launch on login',
			type: 'checkbox',
			checked: app.getLoginItemSettings().openAtLogin,
			click: (item) => app.setLoginItemSettings({ openAtLogin: item.checked }),
		},
		{
			label: 'Open Codex login help',
			click: () => shell.openExternal('https://help.openai.com/'),
		},
		{ type: 'separator' },
		{ label: pairedOrigins.length ? `Paired: ${pairedOrigins.join(', ')}` : 'No paired WordPress sites', enabled: false },
		{ type: 'separator' },
		{ label: 'Quit', click: () => app.quit() },
	]);
}

function refreshTray() {
	if (!tray) {
		return;
	}
	tray.setToolTip(`Alorbach Codex Bridge\nhttp://127.0.0.1:${serverPort}`);
	tray.setContextMenu(buildMenu());
}

async function boot() {
	const icon = nativeImage.createFromDataURL('data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAAKElEQVR4AWMYNmzYf2RgYGBg+P//PwM1ARMDlcGogaMGjhowasCoAQB2pQMe98LPUQAAAABJRU5ErkJggg==');
	tray = new Tray(icon);
	const result = await startServer();
	serverHandle = result.server;
	serverPort = result.port;
	currentPairingCode = result.pairingCode;
	refreshTray();
}

app.whenReady().then(boot);

app.on('before-quit', () => {
	if (serverHandle) {
		serverHandle.close();
	}
});

app.on('window-all-closed', (event) => {
	event.preventDefault();
});
