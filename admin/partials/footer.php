            </div><!-- /p-4 -->
        </div><!-- /col-lg-10 -->
    </div><!-- /row -->
</div><!-- /container-fluid -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* Show/Hide Password Toggle */
function togglePassword(fieldId, btn) {
    var field = document.getElementById(fieldId);
    if (!field) return;
    var icon = btn.querySelector('i');
    if (field.type === 'password') {
        field.type = 'text';
        if (icon) { icon.classList.remove('bi-eye'); icon.classList.add('bi-eye-slash'); }
        btn.title = 'Hide Password';
    } else {
        field.type = 'password';
        if (icon) { icon.classList.remove('bi-eye-slash'); icon.classList.add('bi-eye'); }
        btn.title = 'Show Password';
    }
}

/* Ensure all Bootstrap Dropdowns are properly initialized */
document.addEventListener('DOMContentLoaded', function () {
    if (typeof bootstrap !== 'undefined' && bootstrap.Dropdown) {
        var dropdownElementList = [].slice.call(document.querySelectorAll('[data-bs-toggle="dropdown"]'));
        dropdownElementList.forEach(function (dropdownToggleEl) {
            bootstrap.Dropdown.getOrCreateInstance(dropdownToggleEl);
        });
    }
});
</script>
</body>
</html>
