$(function(){
	var tr = $('#translation'),
		post_failed = tr.attr('data-post_failed'),
		select_server = tr.attr('data-select_server'),
		select_server_option = tr.attr('data-select_server_option'),
		BASE = 'home.php?m=util&type=cleared',
		csrfToken = '',
		config = null,
		agentsOnline = 0;

	// ----- Navigation: category list + one visible tool. Links also work without JavaScript. -----
	var oldTabs = {'#tabs-1': 'ping', '#tabs-2': 'sourcemod', '#tabs-3': 'amx', '#tabs-4': 'steamid'};

	function showTool(tool, push){
		if($('#tool-' + tool).length === 0){
			return;
		}
		$('.util-tool').attr('hidden', 'hidden');
		$('#tool-' + tool).removeAttr('hidden');
		$('.util-nav a').removeClass('active').removeAttr('aria-current');
		$('.util-nav a[data-tool="' + tool + '"]').addClass('active').attr('aria-current', 'page');
		if(push && window.history && history.pushState){
			history.pushState({tool: tool}, '', '?m=util&tool=' + tool);
		}
	}

	$('.util-nav a').on('click', function(e){
		e.preventDefault();
		showTool($(this).attr('data-tool'), true);
	});
	window.addEventListener('popstate', function(){
		var m = /[?&]tool=([a-z_]+)/.exec(location.search);
		showTool(m ? m[1] : 'ping', false);
	});
	if(oldTabs[location.hash]){
		showTool(oldTabs[location.hash], false);
	}

	// ----- Agents: only the agents this user may use. Loaded separately so offline agents do not slow the page. -----
	function fillAgents(data){
		var $selects = $('.util-agent');
		$selects.empty();
		for(var i = 0; i < data.length; ++i){
			var opt = $('<option>').val(data[i]['id']).text(data[i]['name']);
			if(data[i]['status'] !== 1){
				opt.prop('disabled', true);
			}else{
				++agentsOnline;
			}
			$selects.append(opt);
		}
		$selects.each(function(){
			var firstOnline = $(this).find('option:not(:disabled)').first();
			$(this).val(firstOnline.length ? firstOnline.val() : '');
		});
	}

	$.getJSON(BASE + '&p=agents', function(data){
		$('#loading_agents').removeClass('show').addClass('hide');
		fillAgents(data);
		if(agentsOnline === 0){
			$('#agents_offline').removeClass('hide').addClass('show');
			// Hide the add admin forms if all agents are offline.
			$("#addadmin_form").addClass('hide');
			$("#all_servers_offline").removeClass('hide').addClass('show');
		}
	}).fail(function(){
		$('#loading_agents').removeClass('show').addClass('hide');
		$('#loading_failed').removeClass('hide').addClass('show');
	});

	// ----- Config: CSRF token and the user's own game servers -----
	function fillServers(){
		$('.util-servers').each(function(){
			var $s = $(this);
			for(var i = 0; i < config.servers.length; ++i){
				$s.append($('<option>').val(i).text(config.servers[i].label));
			}
		});
	}

	$.getJSON(BASE + '&p=diag&action=config', function(data){
		config = data;
		csrfToken = data.csrf;
		fillServers();
	});

	// Picking one of the user's own game servers. Ping: it only selects the agent that runs that machine.
	// Port check: it fills the hidden agent, address and port that the server checks again.
	$('.util-servers').on('change', function(){
		var $form = $(this).closest('form');
		var srv = (config && $(this).val() !== '') ? config.servers[parseInt($(this).val(), 10)] : null;
		if(srv){
			$form.find('.util-agent').val(String(srv.agent));
		}
		$form.find('.util-agent-value').val(srv ? srv.agent : '');
		$form.find('.util-server-ip').val(srv ? srv.ip : '');
		$form.find('.util-server-port').val(srv ? srv.port : '');
	});

	// ----- Running a tool -----
	// The theme already styles div.success, div.warning and div.failure as message boxes.
	function box(kind, text, role){
		var theme = {good: 'success', warn: 'warning', bad: 'failure'};
		return $('<div>').addClass(theme[kind]).attr('role', role || null).text(text);
	}

	// Which colour a result gets. Anything not listed counts as a problem.
	function outcomeKind(outcome){
		var good = ['ok', 'done'];
		var warn = ['partial'];
		return good.indexOf(outcome) >= 0 ? 'good' : (warn.indexOf(outcome) >= 0 ? 'warn' : 'bad');
	}

	function renderDetails($out, res){
		if(res.rows && res.rows.length){
			var $dl = $('<dl class="util-rows">');
			$.each(res.rows, function(_, r){ $dl.append($('<dt>').text(r[0])).append($('<dd>').text(r[1])); });
			$out.append($dl);
		}
		if(res.output){
			$out.append($('<pre>').text(res.output));
		}
		if(res.note){
			$out.append($('<p class="util-hint">').text(res.note));
		}
	}

	function renderResult($out, res, agentName){
		$out.empty().removeClass('util-busy');
		if(!res.ok){
			$out.append(box('bad', res.message, 'alert'));
			return;
		}
		$out.append(box(outcomeKind(res.outcome), res.summary));
		renderDetails($out, res);
		var s = res.source;
		$out.append($('<p class="util-source">').text('Source agent: ' + agentName + ' | Target: ' + s.target + (s.ip && s.ip !== s.target ? ' (' + s.ip + ')' : '')
			+ (s.port ? ' | Port: ' + s.port : '') + ' | Duration: ' + s.duration_ms + ' ms'));
	}

	// Checks in the browser. The server checks everything again.
	function formError($form){
		if(!$form.find('.util-agent, .util-agent-value').val()){
			return $form.find('.util-servers[required]').length ? 'Choose one of your game servers.' : tr.attr('data-agent_invalid');
		}
		return '';
	}

	$('.util-diag-form').on('submit', function(e){
		e.preventDefault();
		var $form = $(this);
		var $out = $('#' + $form.attr('data-tool') + '-result');
		var error = formError($form);
		if(error !== ''){
			$out.empty().append(box('bad', error, 'alert')).focus();
			return;
		}
		var agentName = $form.find('.util-agent option:selected').text() || $form.find('.util-servers option:selected').text();
		var $button = $form.find('.util-run').prop('disabled', true);
		$out.empty().addClass('util-busy').append($('<p>').text('Running...')).attr('aria-busy', 'true');
		$.ajax({url: BASE + '&p=diag&action=run', method: 'POST', data: $form.serialize(), headers: {'X-CSRF-Token': csrfToken}, dataType: 'json'})
			.done(function(res){ renderResult($out, res, agentName); })
			.fail(function(xhr){
				var res = xhr.responseJSON || {ok: false, message: post_failed};
				renderResult($out, res, agentName);
			})
			.always(function(){ $button.prop('disabled', false); $out.attr('aria-busy', 'false').focus(); });
	});

	// ----- Sourcemod Admins -----
	$.getJSON("home.php?m=util&p=addadmin_helper&type=cleared", function(gs){
		if(gs.length === 0){
			$("#no_servers").removeClass('hide').addClass('show');
		}else{
			var games = "";
			$("#add_admin").removeClass('hide').addClass('show');
			
			games += "<label for=\"gameserver_id\">" + select_server + "</label>\r\n"
			games += "<select id=\"gameserver_id\" name=\"gameserver_id\">\r\n"
			games += "<option value=\"0\" selected>" + select_server_option + "</option>\r\n"
			
			for(var i = 0; i<gs.length; ++i){
				games += "\t<option value=\"" + gs[i]['home_id'] + "\">" + gs[i]['home_name'] +"</option>\r\n";
			}
			
			games += "</select>";
			$("#games").html(games);
			
			$("#gameserver_id").change(function(){
				var home = $("#gameserver_id").val();
				var gameserver_id = $("#gameserver_id").val();
				
				// although the disabled attribute is put on the option, set all the values to 0 if it's somehow chosen again via the form being edited
				if(gameserver_id == 0){
					$("#remote_server_id").val(0);
					$("#gameserver_name").val(0);
					$("#gameserver_ip").val(0);
					$("#gameserver_port").val(0);
				}else{
					for(var i = 0; i<gs.length; ++i){
						if(home === gs[i]['home_id']){
							$("option[value='0']").attr("disabled", "disabled");
							$("#remote_server_id").val(gs[i]['remote_server_id']);
							$("#gameserver_name").val(gs[i]['game_name']);
							$("#gameserver_ip").val(gs[i]['ip']);
							$("#gameserver_port").val(gs[i]['port']);
							break;
						}
					}
				}
			});
		}
	}); // ./end Sourcemod Admins
		
	$("#sourcemod_perms").change(function(){
		var sourcemod_perms = $("#sourcemod_perms").val();
		
		if(sourcemod_perms === 'root'){
			$("#sourcemod_flagList").removeClass('show').addClass('hide');
			$('.item input[type="checkbox"]').prop('checked', false);
		}else{
			$("#sourcemod_flagList").removeClass('hide').addClass('show');
		}
	});
	
	// Process the sourcemod admin form on submission.
	$("#addadmin_form").on("submit", function(e){
		$("#addadmin_form button").prop({disabled:true});
		
		var errors = 0;
		var remoteId = $("#remote_server_id").val();
		var gameName = $("#gameserver_name").val();
		var gameIp = $("#gameserver_ip").val();
		var gamePort = $("#gameserver_port").val();
		var addSteamid = $("#addSteamid").val();
		var immunity = $("#immunity").val();
		
		// Set the message divs back to defaults.
		$("#invalid_server").removeClass('show').addClass('hide');
		$("#invalid_steamid_admin").removeClass('show').addClass('hide');
		$("#invalid_response_admin").removeClass('show').addClass('hide');
		$("#invalid_immunity").removeClass('show').addClass('hide');
		$("#addadmin_response").removeClass('show').addClass('hide');
		
		if(remoteId.length === 0 || gameName.length === 0 || gameIp.length === 0 || gamePort.length === 0){
			$("#invalid_server").removeClass('hide').addClass('show');
			++errors;
		}
		
		if(!(addSteamid.match(/^STEAM_[01]:[01]:\d+$/))){
			$("#invalid_steamid_admin").removeClass('hide').addClass('show');
			++errors;
		}
		
		if(immunity.length > 2 || isNaN(immunity)){
			$("#invalid_immunity").removeClass('hide').addClass('show');
			++errors;
		}
		
		if(errors === 0){
			$.post("home.php?m=util&p=addadmin_helper&type=cleared", $("#addadmin_form").serialize(), function(postCommand){
				$("#addadmin_response").removeClass('hide').addClass('show').html(postCommand);
				$("#addadmin_form button").prop({disabled:false});
			}).fail(function(){
				$("#invalid_response_admin").removeClass('hide').addClass('show');
				$("#addadmin_form button").prop({disabled:false});
			});
		}else{
			$("#addadmin_form button").prop({disabled:false});
		}
		
		e.preventDefault();
	}); // add_admin form handling
	
	// ----- AMX mod Admins -----
	$.getJSON("home.php?m=util&p=amx_addadmin_helper&type=cleared", function(gs){
		if(gs.length === 0){
			$("#amx_no_servers").removeClass('hide').addClass('show');
		}else{
			var games = "";
			$("#amx_add_admin").removeClass('hide').addClass('show');
			
			games += "<label for=\"amx_gameserver_id\">" + select_server + "</label>\r\n"
			games += "<select id=\"amx_gameserver_id\" name=\"amx_gameserver_id\">\r\n"
			games += "<option value=\"0\" selected>" + select_server_option + "</option>\r\n"
			
			for(var i = 0; i<gs.length; ++i){
				games += "\t<option value=\"" + gs[i]['home_id'] + "\">" + gs[i]['home_name'] +"</option>\r\n";
			}
			
			games += "</select>";
			$("#amx_games").html(games);
			
			$("#amx_gameserver_id").change(function(){
				var home = $("#amx_gameserver_id").val();
				var gameserver_id = $("#amx_gameserver_id").val();
				
				// although the disabled attribute is put on the option, set all the values to 0 if it's somehow chosen again via the form being edited
				if(gameserver_id == 0){
					$("#amx_remote_server_id").val(0);
					$("#amx_gameserver_name").val(0);
					$("#amx_gameserver_ip").val(0);
					$("#amx_gameserver_port").val(0);
				}else{
					for(var i = 0; i<gs.length; ++i){
						if(home === gs[i]['home_id']){
							$("option[value='0']").attr("disabled", "disabled");
							$("#amx_remote_server_id").val(gs[i]['remote_server_id']);
							$("#amx_gameserver_name").val(gs[i]['game_name']);
							$("#amx_gameserver_ip").val(gs[i]['ip']);
							$("#amx_gameserver_port").val(gs[i]['port']);
							break;
						}
					}
				}
			});
		}
	}); // ./end AMX mod Admins
	
	$("#amx_mod_perms").change(function(){
		var amx_mod_perms = $("#amx_mod_perms").val();
		
		if(amx_mod_perms === 'root'){
			$("#amx_mod_flagList").removeClass('show').addClass('hide');
			$('.amx_item input[type="checkbox"]').prop('checked', false);
		}else{
			$("#amx_mod_flagList").removeClass('hide').addClass('show');
		}
	});
	
	$("#amx_login_type").change(function(){
		var amx_login_type = $("#amx_login_type").val();
		
		if(amx_login_type === 'amx_login_steamid'){
			$("#amx_login_nick_pass").removeClass('show').addClass('hide');
			$("#amx_Nickname").prop('required',false);
			$("#amx_Password").prop('required',false);
			$("#amx_Steamid").prop('required',true);
			$("#amx_login_steamid").removeClass('hide').addClass('show');
		}else{
			$("#amx_login_steamid").removeClass('show').addClass('hide');
			$("#amx_Nickname").prop('required',true);
			$("#amx_Password").prop('required',true);
			$("#amx_Steamid").prop('required',false);
			$("#amx_login_nick_pass").removeClass('hide').addClass('show');
		}
	});
	
	// Process the amx mod admin form on submission.
	$("#amx_addadmin_form").on("submit", function(e){
		$("#amx_addadmin_form button").prop({disabled:true});
		
		var errors = 0;
		var remoteId = $("#amx_remote_server_id").val();
		var gameName = $("#amx_gameserver_name").val();
		var gameIp = $("#amx_gameserver_ip").val();
		var gamePort = $("#amx_gameserver_port").val();
		var login_type = $("#amx_login_type").val();
		
		// Set the message divs back to defaults.
		$("#amx_invalid_server").removeClass('show').addClass('hide');
		$("#amx_invalid_nickname_admin").removeClass('show').addClass('hide');
		$("#amx_invalid_password_admin").removeClass('show').addClass('hide');
		$("#amx_invalid_steamid_admin").removeClass('show').addClass('hide');
		$("#amx_invalid_response_admin").removeClass('show').addClass('hide');
		$("#amx_addadmin_response").removeClass('show').addClass('hide');
		
		if(login_type == 'amx_login_steamid')
		{
			var Steamid = $("#amx_Steamid").val();
			if(!(Steamid.match(/^STEAM_[01]:[01]:\d+$/))){
				$("#amx_invalid_steamid_admin").removeClass('hide').addClass('show');
				++errors;
			}
		}
		else
		{
			var Nickname = $("#amx_Nickname").val();
			var Password = $("#amx_Password").val();
			if(!(Nickname.match(/^[^\s][A-zÀ-ÿ0-9 !@)(,}/|\.:?;{#$%&*+=-]{1,28}[^\s]$/))){
				$("#amx_invalid_nickname_admin").removeClass('hide').addClass('show');
				++errors;
			}
			if(!(Password.match(/^[^\s][A-zÀ-ÿ0-9 !@)(,}/|\.:?;{#$%&*+=-]{1,28}[^\s]$/))){
				$("#amx_invalid_password_admin").removeClass('hide').addClass('show');
				++errors;
			}
		}
		
		if(remoteId.length === 0 || gameName.length === 0 || gameIp.length === 0 || gamePort.length === 0){
			$("#amx_invalid_server").removeClass('hide').addClass('show');
			++errors;
		}
		
		if(errors === 0){
			$.post("home.php?m=util&p=amx_addadmin_helper&type=cleared", $("#amx_addadmin_form").serialize(), function(postCommand){
				$("#amx_addadmin_response").removeClass('hide').addClass('show').html(postCommand);
				$("#amx_addadmin_form button").prop({disabled:false});
			}).fail(function(){
				$("#amx_invalid_response_admin").removeClass('hide').addClass('show');
				$("#amx_addadmin_form button").prop({disabled:false});
			});
		}else{
			$("#amx_addadmin_form button").prop({disabled:false});
		}
		e.preventDefault();
	}); // add_admin form handling
	
	// ----- Steam Converter -----
	$("#steam_converter").on("submit", function(e){
		$("#steam_converter button").prop({disabled:true});
		$.post("home.php?m=util&p=steamid_converter&type=cleared", $("#steam_converter").serialize(), function(steam_data){
			var json = $.parseJSON(steam_data);
			
			if(json.length === 0){
				$("#steam_info").removeClass('show').addClass('hide');
				$("#invalid_steamid").removeClass('hide').addClass('show');
				$("#invalid_response").removeClass('show').addClass('hide');
				
				$("#steam_converter button").prop({disabled:false});
			}else{
				$("#steam_info").removeClass('hide').addClass('show');
				$("#invalid_steamid").removeClass('show').addClass('hide');
				$("#invalid_response").removeClass('show').addClass('hide');
				
				$("#steamLink").html('<b>Profile Link:</b> ' + json.steamProfile);
				$("#steamId").html('<b>Legacy ID:</b> ' + json.steamId);
				$("#steamId3").html('<b>SteamID3:</b> ' + json.steamId3);
				$("#steamId64").html('<b>SteamID64:</b> ' + json.communityId);
				
				$("#steam_converter button").prop({disabled:false});
			}
		}).fail(function(){
			$("#invalid_steamid").removeClass('show').addClass('hide');
			$("#steam_info").removeClass('show').addClass('hide');
			$("#invalid_response").removeClass('hide').addClass('show');
		});
		e.preventDefault();
	}); // ./end steam_converter form handling.
	
	$("#your-address").click(function(){
		$(".util-tool:not([hidden]) input[name='host']").val($("#your-address").text());
	});
});