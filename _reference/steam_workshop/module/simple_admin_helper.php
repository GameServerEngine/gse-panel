<?php
function steam_workshop_simple_admin_script()
{
?>
<script>
document.addEventListener('DOMContentLoaded', function () {
	const advanced = ['_regex_','_mods_backreference_index_','_variable_','_place_after_','_mod_string_','_string_separator_','_filepath_'];
	const rows = Array.from(document.querySelectorAll('tr'));
	const hide = [];
	rows.forEach(row => {
		const t = row.innerText || '';
		if (advanced.some(a => t.indexOf(a) !== -1)) {
			row.style.display = 'none';
			hide.push(row);
		}
	});
	if (hide.length) {
		const btn = document.createElement('button');
		btn.type = 'button';
		btn.className = 'button';
		btn.textContent = 'Show Advanced Options';
		btn.onclick = function () {
			const show = hide[0].style.display === 'none';
			hide.forEach(r => r.style.display = show ? '' : 'none');
			btn.textContent = show ? 'Hide Advanced Options' : 'Show Advanced Options';
		};
		const table = document.querySelector('table');
		if (table && table.parentNode) table.parentNode.insertBefore(btn, table);
	}
});
</script>
<?php
}
?>
