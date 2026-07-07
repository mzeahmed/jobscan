document.querySelectorAll<HTMLButtonElement>('.filter-tab').forEach((tab) => {
    tab.addEventListener('click', function () {
        const group = this.closest('.filter-tabs');
        group?.querySelectorAll<HTMLButtonElement>('.filter-tab').forEach((t) => {
            t.classList.remove('filter-tab--active');
        });
        this.classList.add('filter-tab--active');
    });
});
