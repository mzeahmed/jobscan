document.querySelectorAll(".filter-tab").forEach(function(tab) {
    tab.addEventListener("click", function() {
        var group = this.closest(".filter-tabs");
        group === null || group === void 0 ? void 0 : group.querySelectorAll(".filter-tab").forEach(function(t) {
            t.classList.remove("filter-tab--active");
        });
        this.classList.add("filter-tab--active");
    });
});
