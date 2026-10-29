$(function() {
	function replaceTemplate(template, values) {
		var output = String(template || '');
		$.each(values, function(key, value) {
			output = output.split(key).join(value);
		});
		return output;
	}

	var methodToRows = {
		download_zip: ['#scm-row-url', '#scm-row-path'],
		steam_workshop: ['#scm-row-workshop-xml-info'],
		post_script: ['#scm-row-post-script'],
		config_edit: ['#scm-row-path', '#scm-row-config-edit-rule'],
		server_bot: ['#scm-row-url', '#scm-row-path', '.scm-row-server-bot']
	};
	var allRows = [
		'#scm-row-url',
		'#scm-row-path',
		'#scm-row-workshop-xml-info',
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
	var $userWorkshopRows = $('.scm-user-workshop-row');
	var $userServerBotRows = $('.scm-user-server-bot-row');
	var $userWorkshopId = $('#scm-user-workshop-id');
	var $userPreview = $('#scm-user-target-path-preview');
	var $userBotArguments = $('#scm-user-bot-arguments');

	function updateUserWorkshopUi() {
		if ($userSelect.length === 0) return;
		var $selected = $userSelect.find('option:selected');
		var installMethod = String($selected.data('installMethod') || '');
		var isWorkshop = installMethod === 'steam_workshop';
		var isServerBot = installMethod === 'server_bot';
		$userWorkshopRows.toggle(isWorkshop);
		$userServerBotRows.toggle(isServerBot);
		if (isServerBot && !$userBotArguments.val()) {
			$userBotArguments.val(String($selected.data('botArguments') || ''));
		}
		if (!isWorkshop) {
			return;
		}

		if (!$userWorkshopId.val()) {
			$userWorkshopId.val(String($selected.data('workshopItemId') || ''));
		}
		var workshopId = $.trim($userWorkshopId.val());
		var workshopAppId = String($userPreview.data('workshopAppId') || '');
		var folderName = workshopId ? '@' + workshopId : '@{WORKSHOP_ID}';
		var targetTemplate = String($userPreview.data('targetTemplate') || $userPreview.text() || '');
		var previewValues = {
			'{SERVER_ROOT}': String($userPreview.data('serverRoot') || ''),
			'{GAME_ROOT}': String($userPreview.data('gameRoot') || ''),
			'{WORKSHOP_ID}': workshopId || '{WORKSHOP_ID}',
			'{WORKSHOP_APP_ID}': workshopAppId || '{WORKSHOP_APP_ID}',
			'{STEAM_APP_ID}': String($userPreview.data('steamAppId') || '{STEAM_APP_ID}'),
			'{FOLDER_NAME}': folderName,
			'{MOD_FOLDER}': folderName
		};
		$userPreview.text(replaceTemplate(targetTemplate, previewValues));
	}

	if ($userSelect.length) {
		$userSelect.on('change', function() {
			$userWorkshopId.val(String($(this).find('option:selected').data('workshopItemId') || ''));
			$userBotArguments.val(String($(this).find('option:selected').data('botArguments') || ''));
			updateUserWorkshopUi();
		});
		$userWorkshopId.on('input', updateUserWorkshopUi);
		updateUserWorkshopUi();
	}
});
