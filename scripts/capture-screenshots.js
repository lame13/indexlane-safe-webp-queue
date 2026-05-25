#!/usr/bin/env node
'use strict';

const crypto = require('crypto');
const fs = require('fs');
const http = require('http');
const net = require('net');
const path = require('path');

const baseUrl = process.env.ILSWQ_BASE_URL || 'http://127.0.0.1:8070';
const debugHost = process.env.ILSWQ_CHROME_HOST || '127.0.0.1';
const debugPort = parseInt(process.env.ILSWQ_CHROME_PORT || '9223', 10);
const outputDir = process.env.ILSWQ_SCREENSHOT_DIR || process.cwd();
const username = process.env.ILSWQ_WP_USER || 'ilswqadmin';
const password = process.env.ILSWQ_WP_PASS || 'password';

function requestJson(method, requestPath) {
	return new Promise((resolve, reject) => {
		const request = http.request(
			{
				host: debugHost,
				port: debugPort,
				path: requestPath,
				method,
			},
			(response) => {
				let body = '';
				response.setEncoding('utf8');
				response.on('data', (chunk) => {
					body += chunk;
				});
				response.on('end', () => {
					if (response.statusCode < 200 || response.statusCode >= 300) {
						reject(new Error(`Chrome endpoint failed: ${response.statusCode} ${body}`));
						return;
					}

					try {
						resolve(JSON.parse(body));
					} catch (error) {
						reject(error);
					}
				});
			}
		);
		request.on('error', reject);
		request.end();
	});
}

class CdpClient {
	constructor(webSocketUrl) {
		const parsed = new URL(webSocketUrl);
		this.host = parsed.hostname;
		this.port = parseInt(parsed.port, 10);
		this.path = parsed.pathname + parsed.search;
		this.nextId = 1;
		this.pending = new Map();
		this.buffer = Buffer.alloc(0);
		this.connected = false;
	}

	connect() {
		return new Promise((resolve, reject) => {
			this.socket = net.createConnection({ host: this.host, port: this.port }, () => {
				const key = crypto.randomBytes(16).toString('base64');
				const headers = [
					`GET ${this.path} HTTP/1.1`,
					`Host: ${this.host}:${this.port}`,
					'Upgrade: websocket',
					'Connection: Upgrade',
					`Sec-WebSocket-Key: ${key}`,
					'Sec-WebSocket-Version: 13',
					'\r\n',
				].join('\r\n');
				this.socket.write(headers);
			});

			this.socket.on('data', (chunk) => {
				this.buffer = Buffer.concat([this.buffer, chunk]);

				if (!this.connected) {
					const headerEnd = this.buffer.indexOf('\r\n\r\n');
					if (headerEnd === -1) {
						return;
					}

					const header = this.buffer.slice(0, headerEnd).toString('utf8');
					if (!header.includes(' 101 ')) {
						reject(new Error(`WebSocket handshake failed: ${header}`));
						return;
					}

					this.connected = true;
					this.buffer = this.buffer.slice(headerEnd + 4);
					resolve();
				}

				this.readFrames();
			});

			this.socket.on('error', reject);
		});
	}

	readFrames() {
		while (this.buffer.length >= 2) {
			const first = this.buffer[0];
			const second = this.buffer[1];
			const opcode = first & 0x0f;
			const masked = (second & 0x80) !== 0;
			let length = second & 0x7f;
			let offset = 2;

			if (length === 126) {
				if (this.buffer.length < offset + 2) {
					return;
				}
				length = this.buffer.readUInt16BE(offset);
				offset += 2;
			} else if (length === 127) {
				if (this.buffer.length < offset + 8) {
					return;
				}
				const high = this.buffer.readUInt32BE(offset);
				const low = this.buffer.readUInt32BE(offset + 4);
				length = high * 4294967296 + low;
				offset += 8;
			}

			let mask;
			if (masked) {
				if (this.buffer.length < offset + 4) {
					return;
				}
				mask = this.buffer.slice(offset, offset + 4);
				offset += 4;
			}

			if (this.buffer.length < offset + length) {
				return;
			}

			let payload = this.buffer.slice(offset, offset + length);
			this.buffer = this.buffer.slice(offset + length);

			if (masked && mask) {
				payload = Buffer.from(payload.map((byte, index) => byte ^ mask[index % 4]));
			}

			if (opcode === 1) {
				this.handleMessage(payload.toString('utf8'));
			} else if (opcode === 8) {
				this.socket.end();
				return;
			}
		}
	}

	handleMessage(message) {
		let payload;
		try {
			payload = JSON.parse(message);
		} catch (error) {
			return;
		}

		if (!payload.id || !this.pending.has(payload.id)) {
			return;
		}

		const { resolve, reject, timeout } = this.pending.get(payload.id);
		clearTimeout(timeout);
		this.pending.delete(payload.id);

		if (payload.error) {
			reject(new Error(payload.error.message || 'CDP command failed'));
			return;
		}

		resolve(payload.result);
	}

	send(method, params = {}) {
		const id = this.nextId++;
		const payload = JSON.stringify({ id, method, params });
		const frame = this.createFrame(payload);

		return new Promise((resolve, reject) => {
			const timeout = setTimeout(() => {
				this.pending.delete(id);
				reject(new Error(`CDP command timed out: ${method}`));
			}, 30000);

			this.pending.set(id, { resolve, reject, timeout });
			this.socket.write(frame);
		});
	}

	createFrame(payload) {
		const data = Buffer.from(payload, 'utf8');
		const mask = crypto.randomBytes(4);
		let header;

		if (data.length < 126) {
			header = Buffer.alloc(2);
			header[0] = 0x81;
			header[1] = 0x80 | data.length;
		} else if (data.length < 65536) {
			header = Buffer.alloc(4);
			header[0] = 0x81;
			header[1] = 0x80 | 126;
			header.writeUInt16BE(data.length, 2);
		} else {
			header = Buffer.alloc(10);
			header[0] = 0x81;
			header[1] = 0x80 | 127;
			header.writeUInt32BE(0, 2);
			header.writeUInt32BE(data.length, 6);
		}

		const masked = Buffer.alloc(data.length);
		for (let index = 0; index < data.length; index++) {
			masked[index] = data[index] ^ mask[index % 4];
		}

		return Buffer.concat([header, mask, masked]);
	}

	close() {
		if (this.socket) {
			this.socket.end();
		}
	}
}

async function evaluate(client, expression) {
	return client.send('Runtime.evaluate', {
		expression,
		awaitPromise: true,
		returnByValue: true,
	});
}

async function waitFor(client, expression, timeoutMs = 20000) {
	const started = Date.now();
	while (Date.now() - started < timeoutMs) {
		const result = await evaluate(client, expression);
		if (result.result && result.result.value) {
			return;
		}
		await new Promise((resolve) => setTimeout(resolve, 250));
	}

	throw new Error(`Timed out waiting for: ${expression}`);
}

async function screenshot(client, fileName) {
	const result = await client.send('Page.captureScreenshot', {
		format: 'png',
		fromSurface: true,
		captureBeyondViewport: false,
	});
	fs.writeFileSync(path.join(outputDir, fileName), Buffer.from(result.data, 'base64'));
}

async function navigate(client, url) {
	await client.send('Page.navigate', { url });
	await waitFor(client, 'document.readyState === "complete"', 30000);
}

(async () => {
	const target = await requestJson('PUT', `/json/new?${encodeURIComponent('about:blank')}`);
	const client = new CdpClient(target.webSocketDebuggerUrl);
	await client.connect();

	try {
		await client.send('Page.enable');
		await client.send('Runtime.enable');
		await client.send('Emulation.setDeviceMetricsOverride', {
			width: 1440,
			height: 960,
			deviceScaleFactor: 1,
			mobile: false,
		});

		await navigate(client, `${baseUrl}/wp-login.php`);
		await waitFor(client, '!!document.querySelector("#loginform")');
		await evaluate(
			client,
			`document.querySelector('#user_login').value = ${JSON.stringify(username)};
document.querySelector('#user_pass').value = ${JSON.stringify(password)};
document.querySelector('#wp-submit').click();
true;`
		);
		await waitFor(client, 'location.href.indexOf("/wp-admin/") !== -1 || !!document.querySelector("#wpadminbar")', 30000);

		await navigate(client, `${baseUrl}/wp-admin/tools.php?page=indexlane-safe-webp-queue`);
		await waitFor(client, '!!document.querySelector("#ilswq-scan")');
		await evaluate(client, 'window.scrollTo(0, 0); true;');
		await screenshot(client, 'screenshot-1.png');

		await evaluate(client, 'document.querySelector("#ilswq-scan").click(); true;');
		await waitFor(
			client,
			`document.querySelector('#ilswq-notice') &&
document.querySelector('#ilswq-notice').textContent.indexOf('Scan complete') !== -1 &&
document.querySelectorAll('#ilswq-results-body tr:not(.ilswq-empty-row)').length > 0`,
			30000
		);
		await evaluate(client, 'document.querySelector(".ilswq-report-panel").scrollIntoView(); true;');
		await screenshot(client, 'screenshot-2.png');

		await evaluate(client, 'document.querySelector("#ilswq-convert").click(); true;');
		await waitFor(
			client,
			`document.querySelector('#ilswq-notice') &&
document.querySelector('#ilswq-notice').textContent.indexOf('Conversion queue complete') !== -1 &&
document.querySelectorAll('.ilswq-status.is-converted, .ilswq-status.is-needs-review').length > 0`,
			60000
		);
		await evaluate(client, 'document.querySelector(".ilswq-report-panel").scrollIntoView(); true;');
		await screenshot(client, 'screenshot-3.png');

		await evaluate(
			client,
			`document.querySelector('[name="serve_webp"]').checked = true;
document.querySelector('[name="auto_uploads"]').checked = true;
window.scrollTo(0, 0);
true;`
		);
		await screenshot(client, 'screenshot-4.png');
	} finally {
		client.close();
	}
})().catch((error) => {
	console.error(error.stack || error.message);
	process.exit(1);
});
