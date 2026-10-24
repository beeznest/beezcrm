(function () {
	var board = document.getElementById('crm-board');
	if (!board) {
		return;
	}

	var dragged = null;

	function field(form, name, value) {
		var input = document.createElement('input');
		input.type = 'hidden';
		input.name = name;
		input.value = value;
		form.appendChild(input);
	}

	board.querySelectorAll('.crm-card').forEach(function (card) {
		card.addEventListener('dragstart', function () {
			dragged = card;
		});
	});

	board.querySelectorAll('.crm-column').forEach(function (column) {
		column.addEventListener('dragover', function (event) {
			event.preventDefault();
			column.classList.add('crm-drop');
		});
		column.addEventListener('dragleave', function () {
			column.classList.remove('crm-drop');
		});
		column.addEventListener('drop', function (event) {
			event.preventDefault();
			column.classList.remove('crm-drop');
			if (!dragged) {
				return;
			}
			if (column.getAttribute('data-lost') === '1') {
				window.alert(board.getAttribute('data-lost-message') || '');
				return;
			}
			if (dragged.getAttribute('data-stage') === column.getAttribute('data-stage')) {
				return;
			}
			var form = document.createElement('form');
			form.method = 'POST';
			form.action = board.getAttribute('data-url') || '';
			field(form, 'token', board.getAttribute('data-token') || '');
			field(form, 'action', 'move');
			field(form, 'id', dragged.getAttribute('data-id') || '');
			field(form, 'stageid', column.getAttribute('data-stage') || '');
			field(form, 'pipelineid', board.getAttribute('data-pipeline') || '');
			field(form, 'userid', board.getAttribute('data-owner') || '');
			field(form, 'catid', board.getAttribute('data-category') || '');
			document.body.appendChild(form);
			form.submit();
		});
	});
}());
