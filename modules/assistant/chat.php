<?php
/*
 * Assistant chat page (customer facing). The questions go to ask.php.
 */

function exec_module()
{
	global $db, $settings;

	if (!isset($settings['assistant_enabled']) || $settings['assistant_enabled'] !== '1') {
		echo '<p>The assistant is not switched on for this panel.</p>';
		return 0;
	}
	if (empty($_SESSION['assistant_csrf'])) {
		$_SESSION['assistant_csrf'] = bin2hex(random_bytes(16));
	}
	$csrf = htmlspecialchars($_SESSION['assistant_csrf'], ENT_QUOTES, 'UTF-8');

	echo '<h2>Assistant</h2>';
	echo '<p>Ask about your game servers: is it running, any errors in the log, who joined. '
		. 'It can show a Start, Stop or Restart button, but it never presses it for you.</p>';
	echo '<div id="assistant-log" style="max-width:720px;min-height:200px;max-height:420px;overflow:auto;border:1px solid #888;padding:8px;margin-bottom:8px"></div>';
	echo '<form id="assistant-form" style="max-width:720px">';
	echo '<input type="text" id="assistant-input" maxlength="600" style="width:78%" autocomplete="off" placeholder="For example: why did my server stop?">';
	echo ' <button type="submit" id="assistant-send">Ask</button>';
	echo '</form>';
	?>
<script>
(function () {
	var log = document.getElementById('assistant-log');
	var form = document.getElementById('assistant-form');
	var input = document.getElementById('assistant-input');
	var send = document.getElementById('assistant-send');

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

	function addButtons(buttons) {
		buttons.forEach(function (button) {
			// Only links to the panel's own server pages are shown.
			if (button.url.indexOf('home.php?m=gamemanager&p=') !== 0) { return; }
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
		var waiting = addLine('Assistant', '...');
		var data = new URLSearchParams();
		data.set('question', question);
		data.set('csrf', '<?php echo $csrf; ?>');
		fetch('modules/assistant/ask.php', { method: 'POST', body: data, credentials: 'same-origin' })
			.then(function (response) { return response.json(); })
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
	return 1;
}
?>
