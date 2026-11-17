/**
 * BD Smart Address: front end.
 * Watches #billing_address_1 / #shipping_address_1, asks the REST endpoint for a
 * cleaned address + district + thana, shows the refined address above the field
 * and fills the District and Thana fields.
 */
(function () {
	'use strict';

	var C = window.BDSA;
	var $ = window.jQuery;
	if (!C || !$ || !window.fetch) {
		return;
	}

	var PREFIXES = ['billing', 'shipping'];
	var T = C.i18n || {};
	var applying = 0; // >0 while WE change a field, so it is not mistaken for a manual edit
	var states = {};

	/* ---------- helpers ---------- */

	function norm(s) {
		return String(s || '')
			.toLowerCase()
			.replace(/['’`‌‍]/g, '')
			.replace(/[^a-z0-9ঀ-৿]+/g, ' ')
			.trim();
	}
	function sq(s) {
		return norm(s).replace(/ /g, '');
	}
	function el(id) {
		return document.getElementById(id);
	}
	function st(p) {
		if (!states[p]) {
			states[p] = { timer: 0, last: '', seq: 0, ctrl: null, box: null, touchedState: false, touchedCity: false, listDi: -2 };
		}
		return states[p];
	}

	var byVariant = {};
	var byName = {};
	var allThanaCache = null;
	C.geo.forEach(function (d, i) {
		byName[d.n] = i;
		d.v.forEach(function (v) {
			if (v && !(v in byVariant)) {
				byVariant[v] = i;
			}
		});
	});

	function allThanas() {
		if (allThanaCache) {
			return allThanaCache;
		}
		var seen = {};
		var list = [];
		C.geo.forEach(function (d) {
			d.t.forEach(function (t) {
				if (!seen[t[0]]) {
					seen[t[0]] = 1;
					list.push(t);
				}
			});
		});
		list.sort(function (a, b) {
			return a[0].localeCompare(b[0]);
		});
		allThanaCache = list;
		return list;
	}

	function bnDigits(s) {
		return String(s).replace(/\d/g, function (d) {
			return '০১২৩৪৫৬৭৮৯'.charAt(+d);
		});
	}
	function money(n) {
		var v = Math.round(Number(n) * 100) / 100;
		return '৳' + bnDigits(v % 1 === 0 ? String(v) : v.toFixed(2));
	}
	function refreshTotals() {
		// WooCommerce debounces this, so calling it after several field changes is cheap.
		$(document.body).trigger('update_checkout');
	}

	function isBD(p) {
		var c = el(p + '_country');
		return !c || !c.value || c.value === 'BD';
	}

	function districtFromSelect(sel) {
		if (!sel || !sel.value) {
			return null;
		}
		var opt = sel.options ? sel.options[sel.selectedIndex] : null;
		var text = opt ? opt.text : sel.value;
		var di = byVariant[sq(text)];
		return di === undefined ? null : di;
	}

	function setValue(node, value) {
		applying++;
		try {
			$(node).val(value).trigger('change');
		} finally {
			applying--;
		}
	}

	/* ---------- district / thana fields ---------- */

	function applyDistrict(p, di) {
		var sel = el(p + '_state');
		if (!sel) {
			return false;
		}
		if (sel.tagName === 'SELECT') {
			for (var i = 0; i < sel.options.length; i++) {
				var o = sel.options[i];
				if (o.value && byVariant[sq(o.text)] === di) {
					if (sel.value !== o.value) {
						setValue(sel, o.value);
					}
					return true;
				}
			}
			return false;
		}
		setValue(sel, C.geo[di].n);
		return true;
	}

	function thanaIsSelect(p) {
		var city = el(p + '_city');
		return !!(city && city.tagName === 'SELECT' && C.thanaDropdown);
	}

	function rebuildThana(p, di) {
		if (!thanaIsSelect(p)) {
			return;
		}
		var city = el(p + '_city');
		var keep = city.value;
		var list = di === null || di === undefined ? allThanas() : C.geo[di].t;
		var frag = document.createDocumentFragment();
		var ph = document.createElement('option');
		ph.value = '';
		ph.textContent = T.select || '';
		frag.appendChild(ph);
		var found = false;
		list.forEach(function (t) {
			var o = document.createElement('option');
			o.value = t[0];
			o.textContent = t[1] ? t[1] : t[0];
			if (t[0] === keep) {
				found = true;
			}
			frag.appendChild(o);
		});
		applying++;
		try {
			city.innerHTML = '';
			city.appendChild(frag);
			city.value = found ? keep : '';
			$(city).trigger('change.select2');
		} finally {
			applying--;
		}
		st(p).listDi = di === null || di === undefined ? -1 : di;
	}

	function applyThana(p, di, name) {
		var city = el(p + '_city');
		if (!city) {
			return;
		}
		if (thanaIsSelect(p)) {
			rebuildThana(p, di);
			var has = false;
			for (var i = 0; i < city.options.length; i++) {
				if (city.options[i].value === name) {
					has = true;
					break;
				}
			}
			if (!has) {
				return;
			}
		}
		setValue(city, name);
	}

	/* ---------- both addresses go with the order ---------- */

	// The address the customer typed and the cleaned-up one travel in two hidden fields, so the
	// shop owner sees both on the order. (The visible field is never changed behind the customer's back.)
	function saveBoth(p, typed, refined) {
		if (p !== 'billing') {
			return;
		}
		var input = el('billing_address_1');
		var form = input && input.closest ? input.closest('form') : null;
		if (!form) {
			return;
		}
		[['bdsa_typed_address', typed], ['bdsa_refined_address', refined]].forEach(function (f) {
			var h = form.querySelector('input[name="' + f[0] + '"]');
			if (!h) {
				h = document.createElement('input');
				h.type = 'hidden';
				h.name = f[0];
				form.appendChild(h);
			}
			h.value = (f[1] || '').replace(/\s+/g, ' ').trim();
		});
	}

	/* ---------- refined address box ---------- */

	function ensureBox(p) {
		var s = st(p);
		var input = el(p + '_address_1');
		if (!input) {
			return null;
		}
		if (!s.box) {
			var box = document.createElement('div');
			box.className = 'bdsa-box';
			box.setAttribute('role', 'status');
			box.setAttribute('aria-live', 'polite');
			box.hidden = true;
			s.box = box;
		}
		if (!document.body.contains(s.box)) {
			var row = input.closest('.form-row') || input;
			row.parentNode.insertBefore(s.box, row);
		}
		return s.box;
	}

	function hideBox(p) {
		var s = st(p);
		if (s.box) {
			s.box.hidden = true;
		}
	}

	function showLoading(p) {
		if (!C.showBox) {
			return;
		}
		var box = ensureBox(p);
		if (!box) {
			return;
		}
		box.className = 'bdsa-box is-loading';
		box.textContent = T.loading || '...';
		box.hidden = false;
	}

	function showResult(p, res, typed) {
		var box = ensureBox(p);
		if (!box) {
			return;
		}
		var input = el(p + '_address_1');
		var refined = (res.refined || '').trim();
		var shown = (res.full || refined).trim(); // "address, thana, district"
		var same = !shown || norm(shown) === norm(typed);
		saveBoth(p, st(p).userTyped || typed, shown);

		if (!C.showBox || (same && !res.district)) {
			box.hidden = true;
			return;
		}

		box.className = 'bdsa-box';
		box.textContent = '';

		if (!same) {
			var title = document.createElement('div');
			title.className = 'bdsa-title';
			title.textContent = T.title || '';
			box.appendChild(title);

			var text = document.createElement('div');
			text.className = 'bdsa-refined';
			text.textContent = shown; // textContent: never interpret server text as HTML
			box.appendChild(text);

			// The button tidies only the street part; district and thana have their own fields.
			if (refined && norm(refined) !== norm(typed)) {
				var actions = document.createElement('div');
				actions.className = 'bdsa-actions';
				var use = document.createElement('button');
				use.type = 'button';
				use.className = 'bdsa-use';
				use.textContent = T.use || 'OK';
				use.addEventListener('click', function () {
					input.value = refined;
					st(p).last = refined.replace(/\s+/g, ' ').trim();
					input.dispatchEvent(new Event('change', { bubbles: true }));
					box.hidden = true;
				});
				actions.appendChild(use);
				box.appendChild(actions);
			}
		}

		if (res.district) {
			var meta = document.createElement('div');
			meta.className = 'bdsa-meta';
			var parts = [(T.district || '') + ': ' + res.district.b];
			if (res.thana) {
				parts.push((T.thana || '') + ': ' + res.thana.b);
			}
			meta.textContent = parts.join(' · ') + ' — ' + (T.auto || '');
			box.appendChild(meta);
		}

		if (res.delivery && typeof res.delivery.fee !== 'undefined') {
			var del = document.createElement('div');
			del.className = 'bdsa-delivery';
			var strong = document.createElement('strong');
			strong.textContent = money(res.delivery.fee);
			del.appendChild(document.createTextNode((T.delivery || '') + ': ' + (res.delivery.label || '') + ' — '));
			del.appendChild(strong);
			box.appendChild(del);
		}

		var close = document.createElement('button');
		close.type = 'button';
		close.className = 'bdsa-close';
		close.setAttribute('aria-label', T.close || 'Close');
		close.textContent = '×';
		close.addEventListener('click', function () {
			box.hidden = true;
		});
		box.appendChild(close);

		box.hidden = false;
	}

	/* ---------- main flow ---------- */

	function currentValue(p) {
		var input = el(p + '_address_1');
		return input ? input.value.replace(/\s+/g, ' ').trim() : '';
	}

	function applyResult(p, res) {
		var s = st(p);
		var di = res.district && byName[res.district.n] !== undefined ? byName[res.district.n] : null;
		if (di === null || !isBD(p)) {
			return;
		}
		// A field the customer chose by hand is left alone. An empty field is always filled.
		var stateEl = el(p + '_state');
		if (!s.touchedState || (stateEl && !stateEl.value)) {
			applyDistrict(p, di);
		}
		var nowDi = districtFromSelect(el(p + '_state'));
		if (nowDi === di) {
			var cityEl = el(p + '_city');
			if (res.thana && (!s.touchedCity || (cityEl && !cityEl.value))) {
				applyThana(p, di, res.thana.n);
			} else if (!res.thana) {
				rebuildThana(p, di);
			}
		}
		refreshTotals();
	}

	function run(p) {
		var input = el(p + '_address_1');
		if (!input) {
			return;
		}
		var s = st(p);
		var v = currentValue(p);
		if (v.length < C.minChars) {
			hideBox(p);
			s.last = '';
			return;
		}
		if (v === s.last) {
			return;
		}
		s.last = v;
		var token = ++s.seq;
		if (s.ctrl && s.ctrl.abort) {
			s.ctrl.abort();
		}
		s.ctrl = window.AbortController ? new AbortController() : null;
		showLoading(p);

		fetch(C.rest, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
			body: JSON.stringify({ address: v }),
			credentials: 'omit',
			signal: s.ctrl ? s.ctrl.signal : undefined
		})
			.then(function (r) {
				return r.ok ? r.json() : null;
			})
			.then(function (res) {
				if (token !== s.seq) {
					return;
				}
				if (!res || !res.ok) {
					hideBox(p);
					return;
				}
				if (currentValue(p) !== v) {
					return; // user kept typing
				}
				applyResult(p, res);
				showResult(p, res, v);
			})
			.catch(function () {
				if (token === s.seq) {
					hideBox(p);
				}
			});
	}

	function schedule(p) {
		var s = st(p);
		clearTimeout(s.timer);
		if (currentValue(p).length < C.minChars) {
			hideBox(p);
			return;
		}
		s.timer = setTimeout(function () {
			run(p);
		}, C.debounce);
	}

	function prefixOf(id, suffix) {
		var m = new RegExp('^(billing|shipping)_' + suffix + '$').exec(id || '');
		return m ? m[1] : null;
	}

	document.addEventListener('input', function (e) {
		var p = e.target && prefixOf(e.target.id, 'address_1');
		if (p) {
			st(p).userTyped = currentValue(p);
			saveBoth(p, st(p).userTyped, ''); // old suggestion no longer matches what is typed
			schedule(p);
		}
	});
	document.addEventListener('change', function (e) {
		var p = e.target && prefixOf(e.target.id, 'address_1');
		if (p) {
			clearTimeout(st(p).timer);
			run(p);
		}
	});
	document.addEventListener('focusout', function (e) {
		var p = e.target && prefixOf(e.target.id, 'address_1');
		if (p) {
			clearTimeout(st(p).timer);
			run(p);
		}
	});

	// Manual edits: never overwrite a field the customer chose by hand.
	// WooCommerce itself fires programmatic "change" events on these fields while the page loads
	// (and when it swaps the state field), so only a real user action counts: a native event
	// (originalEvent) or a select2 selection.
	function onStateChange(e) {
		if (applying) {
			return;
		}
		var p = prefixOf(this.id, 'state');
		if (!p) {
			return;
		}
		if (e.type === 'select2:select' || e.originalEvent) {
			st(p).touchedState = true;
		}
		rebuildThana(p, districtFromSelect(this));
	}
	function onCityChange(e) {
		if (applying) {
			return;
		}
		var p = prefixOf(this.id, 'city');
		if (p && (e.type === 'select2:select' || e.originalEvent)) {
			st(p).touchedCity = true;
		}
	}
	$(document).on('change select2:select', '#billing_state, #shipping_state', onStateChange);
	$(document).on('change select2:select', '#billing_city, #shipping_city', onCityChange);

	// Keep the thana list in step with the selected district (page load, saved addresses, checkout refresh).
	function syncThana() {
		PREFIXES.forEach(function (p) {
			if (!thanaIsSelect(p)) {
				return;
			}
			var di = districtFromSelect(el(p + '_state'));
			var want = di === null ? -1 : di;
			if (st(p).listDi !== want) {
				rebuildThana(p, di);
			}
		});
	}
	$(document.body).on('updated_checkout', syncThana);
	$(syncThana);
})();
