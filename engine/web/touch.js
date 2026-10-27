// On-screen controls for phones and tablets: a d-pad and A / B / START buttons that send real keyboard
// events, so the game (SDL) receives exactly the keys of its config/input.yaml.
// Shown only on touch screens. Keys of the buttons: game.yaml `touch: {a: Space, b: Escape, start: Return}`.
(function () {
	'use strict';

	// key name -> [keyCode, key, code]: SDL's emscripten port reads keyCode
	const KEYS = {
		Left: [37, 'ArrowLeft', 'ArrowLeft'], Up: [38, 'ArrowUp', 'ArrowUp'],
		Right: [39, 'ArrowRight', 'ArrowRight'], Down: [40, 'ArrowDown', 'ArrowDown'],
		Space: [32, ' ', 'Space'], Return: [13, 'Enter', 'Enter'], Escape: [27, 'Escape', 'Escape'],
		Backspace: [8, 'Backspace', 'Backspace'], Tab: [9, 'Tab', 'Tab'], P: [80, 'p', 'KeyP'],
	};
	for (let c = 65; c <= 90; c++) {
		const l = String.fromCharCode(c);
		KEYS[l] ??= [c, l.toLowerCase(), 'Key' + l];
	}
	const held = {};

	function send(type, name) {
		const k = KEYS[name];
		if (!k) return;
		const e = new KeyboardEvent(type, { key: k[1], code: k[2], bubbles: true, cancelable: true });
		Object.defineProperty(e, 'keyCode', { get: () => k[0] });
		Object.defineProperty(e, 'which', { get: () => k[0] });
		(document.getElementById('canvas') || window).dispatchEvent(e);
	}

	function key(name, down) {
		held[name] = (held[name] || 0) + (down ? 1 : -1);
		if (down && held[name] === 1) send('keydown', name);
		if (!down && held[name] <= 0) { held[name] = 0; send('keyup', name); }
	}

	function button(label, name, style) {
		const b = document.createElement('div');
		b.textContent = label;
		b.style.cssText = 'position:fixed;z-index:6;display:flex;align-items:center;justify-content:center;user-select:none;'
			+ 'background:rgba(255,255,255,.18);border:2px solid rgba(255,255,255,.45);color:#fff;font:bold 16px monospace;'
			+ 'touch-action:none;-webkit-user-select:none;' + style;
		const down = (e) => { e.preventDefault(); b.style.background = 'rgba(255,255,255,.4)'; key(name, true); };
		const up = (e) => { e.preventDefault(); b.style.background = 'rgba(255,255,255,.18)'; key(name, false); };
		b.addEventListener('pointerdown', down);
		b.addEventListener('pointerup', up);
		b.addEventListener('pointercancel', up);
		b.addEventListener('pointerleave', (e) => { if (e.buttons) up(e); });
		document.body.appendChild(b);
		return b;
	}

	function start() {
		if (!matchMedia('(pointer: coarse)').matches) return;
		const cfg = Object.assign({ a: 'Space', b: 'Escape', start: 'Return' }, (window.JUMPHP_GAME || {}).touch || {});
		const s = 'width:56px;height:56px;border-radius:12px;';
		button('◀', 'Left', s + 'left:16px;bottom:78px;');
		button('▶', 'Right', s + 'left:136px;bottom:78px;');
		button('▲', 'Up', s + 'left:76px;bottom:138px;');
		button('▼', 'Down', s + 'left:76px;bottom:18px;');
		const r = 'width:68px;height:68px;border-radius:50%;';
		button('A', cfg.a, r + 'right:20px;bottom:40px;');
		button('B', cfg.b, r + 'right:104px;bottom:24px;');
		button('START', cfg.start, 'padding:6px 12px;border-radius:8px;left:50%;transform:translateX(-50%);bottom:12px;font-size:12px;');
	}

	window.JumPHPTouch = { start, key };
})();
