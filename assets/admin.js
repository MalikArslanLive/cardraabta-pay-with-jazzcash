document.querySelectorAll(".crj-history .crj-view-details").forEach(function(button) {
    button.addEventListener("click", function() {
        var target = document.getElementById(button.getAttribute("aria-controls"));
        if (!target) return;
        var expanded = button.getAttribute("aria-expanded") == "true";
        target.hidden = expanded;
        button.setAttribute("aria-expanded", expanded ? "false" : "true");
        button.textContent = expanded ? "View Details" : "Hide Details";
    });
});
