(function (Drupal, once) {
  Drupal.behaviors.regWhatWeDoAccess = {
    attach(context) {
      once('reg-wwd-access', '[data-reg-access-table]', context).forEach((table) => {
        const body = table.tBodies[0];
        const rows = Array.from(body.rows);
        const search = context.querySelector('[data-reg-access-search]') || document.querySelector('[data-reg-access-search]');
        if (search) {
          search.addEventListener('input', () => {
            const query = search.value.trim().toLocaleLowerCase();
            rows.forEach((row) => { row.hidden = !row.cells[0].textContent.toLocaleLowerCase().includes(query); });
          });
        }
        table.querySelectorAll('[data-reg-sort]').forEach((button) => {
          button.addEventListener('click', () => {
            const numeric = button.dataset.regSort === 'rate';
            const direction = button.dataset.direction === 'asc' ? 'desc' : 'asc';
            button.dataset.direction = direction;
            rows.sort((left, right) => {
              const a = numeric ? Number(left.cells[1].dataset.rate) : left.cells[0].textContent.trim();
              const b = numeric ? Number(right.cells[1].dataset.rate) : right.cells[0].textContent.trim();
              return (numeric ? a - b : a.localeCompare(b)) * (direction === 'asc' ? 1 : -1);
            }).forEach((row) => body.appendChild(row));
          });
        });
      });
    },
  };
})(Drupal, once);
