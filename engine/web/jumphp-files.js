// JumPHP "remote" games (a games site: one shared engine, games loaded file by file). Used by jumphp.js and
// jumphp-worker.js when the page says window.JUMPHP_GAME.remote = {manifest: 'manifest.json'}; a normal build
// (php-web.data) never loads this file.
//
// The engine folder (bin/jumphp runtime:build) holds php-web.mjs / .wasm, these scripts and jumphp-vendor.pack:
// the engine's PHP code and its Composer dependencies, unpacked to /game/vendor. The game's own files come one
// by one from the manifest (docs/remote.md):
//   {"base": "files/", "autoload": {"App\\": ["src/"]},
//    "files": [{"path": "config/game.yaml", "hash": "3fa2c9...", "size": 412, "url": "optional absolute url"}]}
// Every download is kept in Cache Storage under its content hash: the engine once for all the games of the
// site, a game file again only when it changed.

const ENGINE_CACHE = 'jumphp-engine';
const FILES_CACHE = 'jumphp-files';
const PARALLEL = 8;

async function openCache(name) {
	try { return await caches.open(name); } catch (e) { return null; }   // private mode, http
}

async function cached(cache, url) {
	const hit = cache && await cache.match(url).catch(() => null);
	if (hit) return new Uint8Array(await hit.arrayBuffer());
	const response = await fetch(url);
	if (!response.ok) throw new Error(`${url}: HTTP ${response.status}`);
	if (cache) { try { await cache.put(url, response.clone()); } catch (e) { console.warn('[cache]', e); } }
	return new Uint8Array(await response.arrayBuffer());
}

// a path of the game, never outside /game
function safePath(path) {
	const parts = String(path).split('/').filter((p) => p !== '' && p !== '.');
	if (!parts.length || parts.some((p) => p === '..' || p.includes('\0'))) throw new Error('bad path in the manifest: ' + path);
	return parts.join('/');
}

function write(FS, path, bytes) {
	const slash = path.lastIndexOf('/');
	if (slash > 0) FS.mkdirTree(path.slice(0, slash));
	FS.writeFile(path, bytes);
}

// jumphp-vendor.pack: "JPK1", the length of a JSON header (uint32, little endian), the header
// {"files": [[path, offset, size], ...]} (offsets from the end of the header), then the bytes
function unpack(FS, bytes, dir) {
	const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
	if (String.fromCharCode(...bytes.subarray(0, 4)) !== 'JPK1') throw new Error('not a JumPHP pack');
	const length = view.getUint32(4, true);
	const header = JSON.parse(new TextDecoder().decode(bytes.subarray(8, 8 + length)));
	const start = 8 + length;
	for (const [path, offset, size] of header.files) {
		write(FS, `${dir}/${safePath(path)}`, bytes.subarray(start + offset, start + offset + size));
	}
	return header.files.length;
}

export async function fetchManifest(url) {
	const response = await fetch(url, { cache: 'no-store' });
	if (!response.ok) throw new Error(`${url}: HTTP ${response.status}`);
	return response.json();
}

/**
 * Writes the engine's PHP code and the game's files into /game.
 * engine: URL of the engine folder; version: its version.json; manifest (+ manifestUrl, for relative URLs).
 */
export async function installRemote({ FS, engine, version, manifest, manifestUrl, onProgress = () => {} }) {
	const engineCache = await openCache(ENGINE_CACHE);
	const filesCache = await openCache(FILES_CACHE);

	const packUrl = new URL('jumphp-vendor.pack' + (version['jumphp-vendor.pack'] ? `?v=${version['jumphp-vendor.pack']}` : ''), engine).href;
	unpack(FS, await cached(engineCache, packUrl), '/game/vendor');
	// Composer's autoloader of the engine, plus the game's namespaces (composer.json "autoload": "psr-4")
	FS.writeFile('/game/vendor/autoload.json', JSON.stringify(manifest.autoload || { 'App\\': ['src/'] }));
	FS.writeFile('/game/vendor/autoload.php', `<?php
// written by jumphp-files.js: the engine (jumphp-vendor.pack) + the game's PSR-4 namespaces
$loader = require __DIR__ . '/autoload_engine.php';
foreach (json_decode((string)file_get_contents(__DIR__ . '/autoload.json'), true) as $prefix => $dirs) {
    $loader->addPsr4($prefix, array_map(static fn (string $dir): string => '/game/' . trim($dir, '/'), (array)$dirs), true);
}
return $loader;
`);

	const base = new URL(manifest.base || 'files/', manifestUrl);
	const files = manifest.files || [];
	const urls = new Set();
	let done = 0;
	const queue = files.slice();
	const worker = async () => {
		for (let file; (file = queue.shift());) {
			const path = safePath(file.path);
			const url = file.url ? new URL(file.url, manifestUrl).href
				: new URL(path.split('/').map(encodeURIComponent).join('/') + (file.hash ? `?v=${file.hash}` : ''), base).href;
			urls.add(url);
			write(FS, `/game/${path}`, await cached(file.hash ? filesCache : null, url));
			onProgress(++done / files.length);
		}
	};
	await Promise.all(Array.from({ length: Math.min(PARALLEL, files.length) }, worker));
	FS.mkdirTree('/game/var');

	// older versions of this game's files leave the cache
	if (filesCache) {
		try {
			for (const request of await filesCache.keys()) {
				if (request.url.startsWith(base.href) && !urls.has(request.url)) await filesCache.delete(request);
			}
		} catch (e) { /* not important */ }
	}
	return files.length;
}
