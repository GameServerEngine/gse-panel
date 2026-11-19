/*
 * Live pieces for the game manager pages. No page reloads.
 *   GseLiveLog(options)    keeps a log box up to date (log.php).
 *   GseStatusPoll(options) polls a JSON status and leaves the page once (start page).
 * Log text is only ever put in with textContent, never as HTML, so a game log cannot inject markup.
 */
(function (w) {
	'use strict';

	// Whole-line colors. The first list that matches wins, so bad beats everything.
	var LINE_RULES = [
		['log-bad',  /error|fail(ed|ure)?|fatal|exception|denied|cannot|can't|unable|crash|kicked|banned/i],
		['log-warn', /warn(ing)?|deprecated|timeout|timed out|retry|missing/i],
		['log-good', /connected|started|success|loaded|ready|is now running|logged in/i],
		['log-info', /initializing|steam|battleye|hive/i]
	];

	function lineClass(line) {
		for (var i = 0; i < LINE_RULES.length; i++) {
			if (LINE_RULES[i][1].test(line)) { return LINE_RULES[i][0]; }
		}
		return '';
	}

	// Fills box with the text. Plain lines are grouped into one text node to stay fast.
	function highlight(box, text) {
		var lines = text.split('\n');
		var fragment = document.createDocumentFragment();
		var plain = [];

		function flushPlain() {
			if (plain.length) {
				fragment.appendChild(document.createTextNode(plain.join('\n') + '\n'));
				plain = [];
			}
		}

		for (var i = 0; i < lines.length; i++) {
			var cls = lineClass(lines[i]);
			if (cls === '') {
				plain.push(lines[i]);
				continue;
			}
			flushPlain();
			var span = document.createElement('span');
			span.className = cls;
			span.textContent = lines[i] + '\n';
			fragment.appendChild(span);
		}
		flushPlain();
		box.textContent = '';
		box.appendChild(fragment);
	}

	function isAtBottom(box) {
		return (box.scrollTop + box.clientHeight) >= (box.scrollHeight - 24);
	}

	// options: box, url, statusEl, fileEl, initialText, interval (ms)
	function GseLiveLog(options) {
		var box = options.box;
		var baseDelay = options.interval || 4000;
		var delay = baseDelay;
		var lastText = null;

		function showState(state) {
			if (!options.statusEl) { return; }
			var labels = {running: 'Server is running', stopped: 'Server is starting / not running', error: 'Log unavailable'};
			var colors = {running: 'good', stopped: 'warn', error: 'bad'};
			options.statusEl.textContent = labels[state] || labels.error;
			options.statusEl.className = 'gse-badge gse-badge-' + (colors[state] || 'bad');
		}

		function show(text, state, file) {
			showState(state);
			if (options.fileEl && file !== null) {
				options.fileEl.textContent = file === '' ? '' : 'Showing: ' + file;
			}
			// On an error keep the last good log on screen and only change the badge.
			if (state === 'error' || text === lastText) { return; }
			var stick = isAtBottom(box);
			highlight(box, text);
			lastText = text;
			if (stick) { box.scrollTop = box.scrollHeight; }
		}

		function schedule() {
			w.setTimeout(poll, delay);
		}

		function poll() {
			if (document.hidden) { schedule(); return; }
			w.fetch(options.url, {cache: 'no-store', credentials: 'same-origin'})
				.then(function (response) {
					var state = response.headers.get('X-Log-State');
					if (!response.ok || !state) { throw new Error('bad answer'); }
					var file = response.headers.get('X-Log-File');
					return response.text().then(function (text) {
						show(text, state, file === null ? null : decodeURIComponent(file));
					});
				})
				.then(function () { delay = baseDelay; })
				.catch(function () { delay = Math.min(delay * 2, 60000); })
				.then(schedule);
		}

		document.addEventListener('visibilitychange', function () {
			if (!document.hidden) { delay = baseDelay; poll(); }
		});

		show(options.initialText, options.initialState, options.initialFile);
		box.scrollTop = box.scrollHeight;
		schedule();
	}

	function setStatusLine(el, answer) {
		var kinds = {online: ['success', 'alert alert-success'], stop: ['failure', 'alert alert-danger'], waiting: ['note', '']};
		var kind = kinds[answer.state] || kinds.waiting;
		var p = document.createElement('p');
		p.className = (kind[0] + ' ' + kind[1]).trim();
		p.textContent = answer.message;
		el.textContent = '';
		el.appendChild(p);
	}

	// options: el, url (poll url), leaveUrl, retry, state, delay
	function GseStatusPoll(options) {
		var retry = options.retry;
		var failures = 0;

		function leaveLater(seconds) {
			w.setTimeout(function () { w.location.href = options.leaveUrl; }, seconds * 1000);
		}

		function poll() {
			if (document.hidden) { w.setTimeout(poll, 3000); return; }
			w.fetch(options.url + '&retry=' + retry, {cache: 'no-store', credentials: 'same-origin'})
				.then(function (response) {
					if (!response.ok) { throw new Error('bad answer'); }
					return response.json();
				})
				.then(function (answer) {
					failures = 0;
					setStatusLine(options.el, answer);
					retry = answer.next_retry;
					if (answer.state === 'waiting') { w.setTimeout(poll, 3000); } else { leaveLater(answer.delay); }
				})
				.catch(function () {
					failures++;
					w.setTimeout(poll, Math.min(3000 * Math.pow(2, failures), 60000));
				});
		}

		if (options.state === 'waiting') { w.setTimeout(poll, 3000); } else { leaveLater(options.delay); }
	}

	w.GseLiveLog = GseLiveLog;
	w.GseStatusPoll = GseStatusPoll;
	w.GseHighlight = highlight;
}(window));
