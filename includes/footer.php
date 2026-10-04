    </main><!-- /.main-content -->
</div><!-- /.main-wrapper -->

    <!-- Main JS -->
    <script src="<?= APP_URL ?>/assets/js/script.js"></script>

    <!-- Notification Bell Script -->
    <script>
    (function() {
        const bell     = document.getElementById('notifBell');
        const dropdown = document.getElementById('notifDropdown');
        const badge    = document.getElementById('notifBadge');
        if (!bell || !dropdown) return;

        bell.addEventListener('click', function(e) {
            e.stopPropagation();
            const isOpen = dropdown.style.display !== 'none';
            dropdown.style.display = isOpen ? 'none' : 'block';
            if (!isOpen) {
                // Mark as read after opening
                setTimeout(() => markAllRead(false), 1500);
            }
        });

        document.addEventListener('click', function(e) {
            if (!dropdown.contains(e.target) && e.target !== bell) {
                dropdown.style.display = 'none';
            }
        });
    })();

    function markAllRead(reload) {
        fetch('<?= APP_URL ?>/notifications_api.php?action=mark_read', {method:'GET',credentials:'same-origin'})
            .then(r => r.json())
            .then(() => {
                const badge = document.getElementById('notifBadge');
                if (badge) badge.style.display = 'none';
                // Fade out unread dots
                document.querySelectorAll('.notif-item').forEach(el => {
                    el.style.background = 'transparent';
                });
                if (reload === true) location.reload();
            })
            .catch(() => {});
    }
    </script>
</body>
</html>
