document.addEventListener("DOMContentLoaded", function () {
    const rows = document.querySelectorAll(".timesheet-row");
    rows.forEach(row => {
        const approveBtn = row.querySelector(".approve-btn");
        const rejectBtn = row.querySelector(".reject-btn");

        approveBtn.addEventListener("click", function () {
            const id = row.dataset.id;
            fetch(`/timesheets/approve.php?id=${id}&action=approve`)
                .then(res => res.json())
                .then(data => alert("Approved"));
        });

        rejectBtn.addEventListener("click", function () {
            const id = row.dataset.id;
            fetch(`/timesheets/approve.php?id=${id}&action=reject`)
                .then(res => res.json())
                .then(data => alert("Rejected"));
        });
    });
});
