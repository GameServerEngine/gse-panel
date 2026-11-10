$(function() {
	var methodToRows = {
		download_zip: ['#scm-row-url', '#scm-row-path'],
		post_script: ['#scm-row-post-script'],
		config_edit: ['#scm-row-path', '#scm-row-config-edit-rule'],
		server_bot: ['#scm-row-url', '#scm-row-path', '.scm-row-server-bot']
	};
	var allRows = [
		'#scm-row-url',
		'#scm-row-path',
		'#scm-row-post-script',
		'#scm-row-config-edit-rule',
		'.scm-row-server-bot'
	];
	var $method = $('#scm-install-method');
	var $help = $('#scm-install-method-help');

	function applyContentTypeUi() {
		if ($method.length === 0) return;
		var value = $method.val();
		var shown = methodToRows[value] || [];
		for (var i = 0; i < allRows.length; i++) {
			$(allRows[i]).hide();
		}
		for (var j = 0; j < shown.length; j++) {
			$(shown[j]).show();
		}
		var selectedOption = $method.find('option:selected');
		var helpText = selectedOption.data('help') || '';
		$help.text(helpText);
		$('#scm-path-label').text(value === 'config_edit' ? 'Config Target Path' : (value === 'server_bot' ? 'Download / Install Folder (optional)' : 'Target Path / Extract Path (optional)'));
	}

	$method.on('change', applyContentTypeUi);
	applyContentTypeUi();

	var $userSelect = $('#scm-user-addon-select');
	var $userServerBotRows = $('.scm-user-server-bot-row');
	var $userBotArguments = $('#scm-user-bot-arguments');

	function updateUserServerBotUi() {
		if ($userSelect.length === 0) return;
		var $selected = $userSelect.find('option:selected');
		var isServerBot = String($selected.data('installMethod') || '') === 'server_bot';
		$userServerBotRows.toggle(isServerBot);
		if (isServerBot && !$userBotArguments.val()) {
			$userBotArguments.val(String($selected.data('botArguments') || ''));
		}
	}

	if ($userSelect.length) {
		$userSelect.on('change', function() {
			$userBotArguments.val(String($(this).find('option:selected').data('botArguments') || ''));
			updateUserServerBotUi();
		});
		updateUserServerBotUi();
	}
});
