// On-screen controls for phones and tablets: a d-pad and A / B / START buttons that send real keyboard
// events, so the game (SDL) receives exactly the keys of its config/input.yaml.
// Shown only on touch screens. Keys of the buttons: game.yaml `touch: {a: Space, b: Escape, start: Return}`.
// Options (all optional, the defaults keep the classic d-pad):
//   pad: stick       a floating joystick instead of the d-pad: touch anywhere on the left half of the screen and
//                    slide (8 directions, diagonals included: walk + jump with one thumb)
//   select: P        a 4th small button (SELECT) next to START
//   labels: {a: JUMP, b: FIRE, start: START, select: PAUSE}   texts of the buttons
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

	// floating joystick: the left half of the screen; the touch point is the centre, the slide gives the directions
	function stick() {
		const DEAD = 18, MAX = 50;
		const zone = document.createElement('div');
		zone.style.cssText = 'position:fixed;z-index:5;left:0;top:0;width:45%;height:100%;touch-action:none;-webkit-user-select:none;user-select:none;';
		const base = document.createElement('div');
		base.style.cssText = 'position:fixed;z-index:5;width:120px;height:120px;margin:-60px 0 0 -60px;border-radius:50%;pointer-events:none;'
			+ 'border:2px solid rgba(255,255,255,.35);background:rgba(255,255,255,.08);left:90px;top:calc(100% - 110px);';
		const knob = document.createElement('div');
		knob.style.cssText = 'position:absolute;width:52px;height:52px;left:34px;top:34px;border-radius:50%;'
			+ 'background:rgba(255,255,255,.35);border:2px solid rgba(255,255,255,.6);';
		base.appendChild(knob);
		document.body.appendChild(zone);
		document.body.appendChild(base);
		let id = null, cx = 0, cy = 0, dirs = {};
		const setDirs = (next) => {
			for (const d of ['Left', 'Right', 'Up', 'Down']) {
				if (!!next[d] !== !!dirs[d]) key(d, !!next[d]);
			}
			dirs = next;
		};
		const move = (x, y) => {
			let dx = x - cx, dy = y - cy;
			const len = Math.hypot(dx, dy);
			if (len > MAX) { dx = dx * MAX / len; dy = dy * MAX / len; }
			knob.style.transform = `translate(${dx}px,${dy}px)`;
			const next = {};
			if (len > DEAD) {
				const a = Math.atan2(dy, dx) * 180 / Math.PI; // 8 sectors of 45 degrees
				if (a > -67.5 && a < 67.5) next.Right = true;
				if (a > 112.5 || a < -112.5) next.Left = true;
				if (a < -22.5 && a > -157.5) next.Up = true;
				if (a > 22.5 && a < 157.5) next.Down = true;
			}
			setDirs(next);
		};
		const end = (e) => {
			if (e.pointerId !== id) return;
			e.preventDefault();
			id = null;
			knob.style.transform = '';
			base.style.left = '90px';
			base.style.top = 'calc(100% - 110px)';
			setDirs({});
		};
		zone.addEventListener('pointerdown', (e) => {
			if (id !== null) return;
			e.preventDefault();
			id = e.pointerId;
			zone.setPointerCapture?.(id);
			cx = e.clientX; cy = e.clientY;
			base.style.left = cx + 'px';
			base.style.top = cy + 'px';
			move(cx, cy);
		});
		zone.addEventListener('pointermove', (e) => { if (e.pointerId === id) { e.preventDefault(); move(e.clientX, e.clientY); } });
		zone.addEventListener('pointerup', end);
		zone.addEventListener('pointercancel', end);
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
		const labels = Object.assign({ a: 'A', b: 'B', start: 'START', select: 'SELECT' }, cfg.labels || {});
		if (cfg.pad === 'stick') {
			stick();
		} else {
			const s = 'width:56px;height:56px;border-radius:12px;';
			button('◀', 'Left', s + 'left:16px;bottom:78px;');
			button('▶', 'Right', s + 'left:136px;bottom:78px;');
			button('▲', 'Up', s + 'left:76px;bottom:138px;');
			button('▼', 'Down', s + 'left:76px;bottom:18px;');
		}
		const r = 'width:68px;height:68px;border-radius:50%;';
		const small = labels.a.length > 2 || labels.b.length > 2 ? 'font-size:12px;' : '';
		button(labels.a, cfg.a, r + small + 'right:20px;bottom:40px;');
		button(labels.b, cfg.b, r + small + 'right:104px;bottom:24px;');
		const pill = 'padding:6px 12px;border-radius:8px;bottom:12px;font-size:12px;';
		if (cfg.select) {
			button(labels.select, cfg.select, pill + 'left:50%;transform:translateX(-110%);');
			button(labels.start, cfg.start, pill + 'left:50%;transform:translateX(10%);');
		} else {
			button(labels.start, cfg.start, pill + 'left:50%;transform:translateX(-50%);');
		}
		// the SOUND / FULLSCREEN toolbar would sit under the A / B buttons: move it to the top
		const toolbar = document.getElementById('toolbar');
		if (toolbar) { toolbar.style.bottom = 'auto'; toolbar.style.top = '8px'; }
	}

	window.JumPHPTouch = { start, key };
})();
