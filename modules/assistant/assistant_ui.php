<?php
/*
 * The chat box used by both pages (a server's page and the Admin page).
 * All text from the model is put on the page as plain text, never as HTML.
 * Buttons the model offers are a link to the panel's own server pages, a password reset or a
 * file change (both ask the person to confirm first), or a patch download for administrators.
 */

function assistant_render_chat($scope_token, $heading, $intro, $placeholder, $ai_name = 'AI')
{
	if (empty($_SESSION['assistant_csrf'])) {
		$_SESSION['assistant_csrf'] = bin2hex(random_bytes(16));
	}
	$csrf = htmlspecialchars($_SESSION['assistant_csrf'], ENT_QUOTES, 'UTF-8');
	$scope = htmlspecialchars($scope_token, ENT_QUOTES, 'UTF-8');

	echo '<h2>' . htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') . '</h2>';
	echo '<p>' . htmlspecialchars($intro, ENT_QUOTES, 'UTF-8') . '</p>';
	echo '<div id="assistant-log" style="max-width:720px;min-height:200px;max-height:420px;overflow:auto;border:1px solid #888;padding:8px;margin-bottom:8px"></div>';
	echo '<form id="assistant-form" style="max-width:720px">';
	echo '<input type="text" id="assistant-input" maxlength="600" style="width:78%" autocomplete="off" placeholder="' . htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8') . '">';
	echo ' <button type="submit" id="assistant-send">Ask</button>';
	echo '</form>';
	?>
<script>
(function () {
	var aiName = <?php echo json_encode((string)$ai_name, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
	var log = document.getElementById('assistant-log');
	var form = document.getElementById('assistant-form');
	var input = document.getElementById('assistant-input');
	var send = document.getElementById('assistant-send');
	var csrf = '<?php echo $csrf; ?>';
	var scope = '<?php echo $scope; ?>';

	function addLine(who, text) {
		var p = document.createElement('p');
		p.style.margin = '4px 0';
		var b = document.createElement('b');
		b.textContent = who + ': ';
		p.appendChild(b);
		p.appendChild(document.createTextNode(text));
		log.appendChild(p);
		log.scrollTop = log.scrollHeight;
		return p;
	}

	function post(url, fields) {
		var data = new URLSearchParams();
		Object.keys(fields).forEach(function (k) { data.set(k, fields[k]); });
		data.set('csrf', csrf);
		data.set('scope', scope);
		return fetch(url, { method: 'POST', body: data, credentials: 'same-origin' }).then(function (r) { return r.json(); });
	}

	function resetButton(button) {
		var el = document.createElement('button');
		el.type = 'button';
		el.textContent = button.label;
		el.className = 'monitorbutton';
		el.style.margin = '4px 8px 4px 0';
		el.addEventListener('click', function () {
			if (!window.confirm(button.label + '?\nThe old password stops working straight away.')) { return; }
			el.disabled = true;
			post('modules/assistant/confirm.php', { what: button.what, db_id: button.db_id || 0, home_id: button.home_id || 0 })
				.then(function (reply) {
					var line = addLine('Panel', reply.message || '');
					if (reply.ok && reply.password) {
						line.appendChild(document.createElement('br'));
						line.appendChild(document.createTextNode('New password: '));
						var code = document.createElement('code');
						code.textContent = reply.password;
						code.style.userSelect = 'all';
						line.appendChild(code);
						line.appendChild(document.createElement('br'));
						line.appendChild(document.createTextNode('Copy it now. This message goes away when you leave the page.'));
					}
				})
				.catch(function () { addLine('Panel', 'Something went wrong. Nothing was changed.'); });
		});
		log.appendChild(el);
	}

	function actionButton(label, onClick) {
		var el = document.createElement('button');
		el.type = 'button';
		el.textContent = label;
		el.className = 'monitorbutton';
		el.style.margin = '4px 8px 4px 0';
		el.addEventListener('click', function () { onClick(el); });
		return el;
	}

	// A file change the assistant proposed: show the exact difference, then Apply / Discard (and Undo afterwards).
	function proposalButton(button) {
		var el = actionButton(button.label, function (self) {
			self.disabled = true;
			post('modules/assistant/confirm.php', { what: 'proposal_view', id: button.id }).then(function (view) {
				var box = document.createElement('div');
				box.style.cssText = 'border:1px solid #888;padding:6px;margin:6px 0';
				if (!view.ok) {
					box.textContent = view.message || 'That change was not found.';
					log.appendChild(box);
					return;
				}
				var head = document.createElement('b');
				head.textContent = (view.is_new ? 'New file: ' : 'Change to: ') + view.target + (view.status !== 'pending' ? ' (' + view.status + ')' : '');
				box.appendChild(head);
				if (view.note) {
					var note = document.createElement('div');
					note.textContent = view.note;
					box.appendChild(note);
				}
				var pre = document.createElement('pre');
				pre.style.cssText = 'max-height:260px;overflow:auto;white-space:pre-wrap;margin:6px 0';
				pre.textContent = view.diff || '(no difference)';
				box.appendChild(pre);
				var say = function (reply) { addLine('Panel', reply.message || ''); return reply; };
				if (view.status === 'pending') {
					box.appendChild(actionButton('Apply this change', function (apply) {
						if (!window.confirm('Write this change to ' + view.target + '?')) { return; }
						apply.disabled = true;
						post('modules/assistant/confirm.php', { what: 'proposal_apply', id: button.id }).then(say).then(function (reply) {
							if (reply.ok && reply.undo) {
								log.appendChild(actionButton('Undo that change', function (undo) {
									if (!window.confirm('Put ' + view.target + ' back the way it was?')) { return; }
									undo.disabled = true;
									post('modules/assistant/confirm.php', { what: 'proposal_undo', id: button.id }).then(say);
								}));
							}
						}).catch(function () { addLine('Panel', 'Something went wrong. Nothing was changed.'); });
					}));
					box.appendChild(actionButton('Discard', function (discard) {
						discard.disabled = true;
						post('modules/assistant/confirm.php', { what: 'proposal_discard', id: button.id }).then(say);
					}));
				}
				log.appendChild(box);
				log.scrollTop = log.scrollHeight;
			}).catch(function () { addLine('Panel', 'Something went wrong.'); });
		});
		log.appendChild(el);
	}

	function addButtons(buttons) {
		buttons.forEach(function (button) {
			if (button.type === 'reset') {
				resetButton(button);
				return;
			}
			if (button.type === 'proposal') {
				proposalButton(button);
				return;
			}
			if (button.type === 'patch') {
				// Administrators only: the file is checked again on the server.
				var dl = document.createElement('a');
				dl.href = 'modules/assistant/patch.php?id=' + encodeURIComponent(button.id);
				dl.textContent = button.label;
				dl.className = 'monitorbutton';
				dl.style.display = 'inline-block';
				dl.style.margin = '4px 8px 4px 0';
				log.appendChild(dl);
				return;
			}
			// Only links to the panel's own server pages are shown.
			if (!button.url || button.url.indexOf('home.php?m=gamemanager&p=') !== 0) { return; }
			var a = document.createElement('a');
			a.href = button.url;
			a.textContent = button.label;
			a.className = 'monitorbutton';
			a.style.display = 'inline-block';
			a.style.margin = '4px 8px 4px 0';
			log.appendChild(a);
		});
		log.scrollTop = log.scrollHeight;
	}

	form.addEventListener('submit', function (event) {
		event.preventDefault();
		var question = input.value.trim();
		if (question === '') { return; }
		addLine('You', question);
		input.value = '';
		send.disabled = true;
		var waiting = addLine(aiName, '...');
		post('modules/assistant/ask.php', { question: question })
			.then(function (reply) {
				waiting.lastChild.textContent = reply.text || '';
				addButtons(reply.buttons || []);
			})
			.catch(function () { waiting.lastChild.textContent = 'Something went wrong. Please try again.'; })
			.then(function () { send.disabled = false; input.focus(); });
	});
})();
</script>
	<?php
}
?>
