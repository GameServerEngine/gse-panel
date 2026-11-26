$('document').ready(function(){
	$.getJSON("home.php?m=notifications&p=inbox&type=cleared", function(data){
		if(data.length > 0)
		{
			$("head").append('<link rel="stylesheet" href="modules/notifications/notification.css">');
			$("body").append('<div id="notification">You have ' + data.length + ' pending notifications.<br><a href="?m=notifications&p=inbox&list=true" >Read Notifications</a></div>');
		}
	}).fail(function(){
		console.log('Failed reading JSON for notifications');
	});
});
