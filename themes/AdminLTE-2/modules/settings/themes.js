$(document).ready(function()
{
	$('.main > br').remove();

	// remove original logo link
	$('#logo_link').parents('tr').remove();

	// remove original background wrapper
	$('#bg_wrapper').parents('tr').remove();

	// add multiform attribute for image upload
	$('form[action="?m=settings&p=themes"]').attr('enctype','multipart/form-data');

	// add theme width option
	$('.main #theme').parents('tr').after('\
	<tr>\
		<td align="right">\
			<label for="themeNavWidth" class="mb-0">'+langConsts['LANG_theme']+' Nav Width:</label>\
			<small class="currentNavWidth text-muted d-block"></small>\
		</td>\
		<td align="left">\
			<div class="form-group mb-0">\
				<input type="range" class="form-control-range" min="250" max="350" value="'+localStorage.getItem('themeNavWidth')+'" id="themeNavWidth" name="themeNavWidth">\
			</div>\
		</td>\
		<td>\
			<div class="image-tip" data-toggle="tooltip" data-html="true" title="Sets '+langConsts['LANG_theme']+' Navigation Width for all Users"><i class="far fa-question-circle"></i></div>\
		</td>\
	</tr>\
	');

	// add theme serverstats option
	var themeServerstatsOptions = {'remove' : langConsts['LANG_off'], 'activate' : langConsts['LANG_on']}
	var themeServerstatsOptionsOut = ''
	for(var key in themeServerstatsOptions)
	{
		if(key==localStorage.getItem('themeServerstats'))
		{
			themeServerstatsOptionsOut += '<option value="'+key+'" selected>'+themeServerstatsOptions[key]+'</option>';
		}else
		{
			themeServerstatsOptionsOut += '<option value="'+key+'">'+themeServerstatsOptions[key]+'</option>';
		}
	}


	if(localStorage.getItem('themeServerstats')=='activate')
	{
		// load themeServerstatsNum settings from db
		var themeServerstatsNum = 10;
		$.ajax({
			cache: false,
			async: false,
			type: 'GET',
			url: 'themes/AdminLTE-2/dist/php/settings.php?m=global&p=themeServerstats&v=displayNum',
			success: function(data)
			{
				themeServerstatsNum = data;
			}
		});

		var themeServerstatsDisplayNum = '\
		<tr>\
			<td align="right">\
				<label for="themeServerstatsNum" class="mb-0">Server Player Stats Num:</label>\
				<small class="text-muted d-block">Displays last <span id="themeServerstatsNumber">'+themeServerstatsNum+'</span> measurements</small>\
			</td>\
			<td align="left">\
				<div class="form-group mb-0">\
					<input type="range" class="form-control-range" min="1" max="20" value="'+themeServerstatsNum+'" id="themeServerstatsNum" name="themeServerstatsNum">\
				</div>\
			</td>\
			<td>\
				<div class="image-tip" data-toggle="tooltip" data-html="true" title="Displays last n measurements in Playercharts on Dashboard"><i class="far fa-question-circle"></i></div>\
			</td>\
		</tr>\
		'
	}

	$('.main #theme').parents('tr').after('\
	<tr>\
		<td align="right">\
			<label for="themeServerstats" class="mb-0">Server Player Stats:</label>\
			<small class="text-muted d-block">Displays custom Server Boxes on Dashboard</small>\
		</td>\
		<td align="left">\
			<div class="form-group mb-0">\
				<select id="themeServerstats" name="themeServerstats" class="form-control">\
					' + themeServerstatsOptionsOut + '\
				</select>\
			</div>\
		</td>\
		<td>\
			<div class="image-tip" data-toggle="tooltip" data-html="true" title="Activates user stats for each server, also installs a running cronjob on first server (1/5min check each server)"><i class="far fa-question-circle"></i></div>\
		</td>\
	</tr>\
	' + themeServerstatsDisplayNum + '\
	');


	// display current themeNavWidth
	$('.currentNavWidth').text('Current Width: '+localStorage.getItem('themeNavWidth')+'px');

	// themeNavWidth slide
	$(document).on('input', '#themeNavWidth', function()
	{
		// call setNavWidth function from main.js
		setNavWidth($(this).val());

		// change currentNavWidth text
		$('.currentNavWidth').text('Current Width: ' + $(this).val() + 'px');
	});

	// themeNavWidth change
	$('#themeNavWidth').change(function()
	{
		$.ajax({
			cache: false,
			async: true,
			type: 'GET',
			url: 'themes/AdminLTE-2/dist/php/settings.php?m=settings&p=themeNavWidth&v='+$(this).val(),
			start: function()
			{
				toastr.info(langConsts['LANG_theme']+' Nav Width change initiated');
			},
			success: function(themeNavWidth)
			{
				// reseset themeNavWidth cache
				localStorage.setItem('themeNavWidth', themeNavWidth);

				// call setNavWidth function from main.js
				setNavWidth(themeNavWidth);

				// display current themeNavWidth
				$('.currentNavWidth').text('Current Width: '+themeNavWidth+'px');

				toastr.success('Successfully changed '+langConsts['LANG_theme']+' Nav Width to ' + themeNavWidth + 'px');
			}
		});
	});

	// themeServerstats change
	$('#themeServerstats').change(function()
	{
		// disable form button
		$('[name="update_settings"]').attr('disabled', 'disabled');

		// message
		toastr.info('themeServerstats change initiated');

		var themeServerstatsVal = $(this).val();

		$.ajax({
			cache: false,
			async: true,
			type: 'GET',
			url: 'themes/AdminLTE-2/dist/php/settings.php?m=settings&p=themeServerstats&v=' + themeServerstatsVal,
			success: function(html)
			{
				// message
				toastr.success('Successfully ' + themeServerstatsVal + 'd themeServerstats');

				// set localStorage cache
				localStorage.setItem('themeServerstats', themeServerstatsVal);
			},
			error: function(error)
			{
				toastr.error('Error: ' + error);
			}
		}).done(function()
		{
			// enable form button
			$('[name="update_settings"]').removeAttr('disabled');
		});
	});


	// themeServerstatsNum slide
	$(document).on('input', '#themeServerstatsNum', function()
	{
		// change themeServerstatsNumber text
		$('#themeServerstatsNumber').text($(this).val());
	});

	// themeServerstatsNum change
	$('#themeServerstatsNum').change(function()
	{
		// disable form button
		$('[name="update_settings"]').attr('disabled', 'disabled');

		// message
		toastr.info('themeServerstatsNum change initiated');

		var themeServerstatsNumVal = $(this).val();

		$.ajax({
			cache: false,
			async: true,
			type: 'GET',
			url: 'themes/AdminLTE-2/dist/php/settings.php?m=settings&p=themeServerstats&v=setNum&num=' + themeServerstatsNumVal,
			success: function(html)
			{
				// message
				toastr.success('Successfully set themeServerstatsNum');
			},
			error: function(error)
			{
				toastr.error('Error: ' + error);
			}
		}).done(function()
		{
			// enable form button
			$('[name="update_settings"]').removeAttr('disabled');
		});
	});
});
