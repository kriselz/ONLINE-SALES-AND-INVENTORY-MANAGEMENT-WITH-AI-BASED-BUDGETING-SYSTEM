<?php
$riderNavigation = [
    'available' => ['availdel.php', 'local_shipping', 'Available Deliveries'],
    'active' => ['activedel.php', 'delivery_dining', 'Active Deliveries'],
    'history' => ['delhisto.php', 'history', 'Delivered History'],
    'profile' => ['rider_profile.php', 'person_outline', 'Profile'],
];
?>
<header class="rider-appbar">
    <div class="rider-appbar-brand">
        <img class="rider-appbar-logo" src="../user/images/logo.png" alt="Josefina Farmacia logo">
        <div class="rider-appbar-copy">
            <span class="rider-appbar-title">Josefina Farmacia</span>
            <span class="rider-appbar-tagline">Quality and Affordable Branded &amp; Generic Medicines</span>
        </div>
    </div>
    <div class="rider-appbar-account">
        <div class="rider-appbar-avatar">
            <?php if (!empty($riderLayoutProfile['profile_image'])): ?>
                <img src="<?php echo htmlspecialchars($riderLayoutProfile['profile_image'], ENT_QUOTES, 'UTF-8'); ?>" alt="">
            <?php else: ?>
                <?php echo htmlspecialchars($riderLayoutInitials ?: 'R', ENT_QUOTES, 'UTF-8'); ?>
            <?php endif; ?>
        </div>
        <div class="rider-appbar-user">
            <strong><?php echo htmlspecialchars($riderLayoutDisplayName, ENT_QUOTES, 'UTF-8'); ?></strong>
            <span><?php echo htmlspecialchars($riderLayoutProfile['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
    </div>
</header>
<aside class="rider-sidebar">
    <div class="rider-brand">
        <div class="rider-brand-title">
            <span class="rider-brand-badge">
                <?php if ($riderUseBrandLogo): ?>
                    <img src="../user/images/logo.png" alt="Josefina Farmacia logo">
                <?php else: ?>
                    <span class="material-icons-outlined" aria-hidden="true">two_wheeler</span>
                <?php endif; ?>
            </span>
            <div class="rider-brand-copy">Rider<span>Delivery workspace</span></div>
        </div>
        <button class="sidebar-toggle" type="button" aria-label="Toggle rider navigation" aria-expanded="true">
            <span class="material-icons-outlined" aria-hidden="true">menu</span>
        </button>
    </div>
    <nav class="rider-nav" aria-label="Rider navigation">
        <?php foreach ($riderNavigation as $page => [$href, $icon, $label]): ?>
            <a class="<?php echo $riderActivePage === $page ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($href, ENT_QUOTES, 'UTF-8'); ?>"><span class="material-icons-outlined" aria-hidden="true"><?php echo htmlspecialchars($icon, ENT_QUOTES, 'UTF-8'); ?></span><span class="nav-label"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></span></a>
        <?php endforeach; ?>
        <a class="logout-link" href="rider_logout.php"><span class="material-icons-outlined" aria-hidden="true">logout</span><span class="nav-label">Log out</span></a>
    </nav>
</aside>
<script>
(function () {
    document.querySelectorAll('.logout-link').forEach(function (link) {
        link.addEventListener('click', function (event) {
            if (!window.confirm('Are you sure you want to log out?')) {
                event.preventDefault();
            }
        });
    });

    var sidebar = document.querySelector('.rider-sidebar');
    var sidebarToggle = document.querySelector('.sidebar-toggle');
    if (!sidebar || !sidebarToggle) return;

    sidebarToggle.addEventListener('click', function () {
        var collapsed = sidebar.classList.toggle('collapsed');
        document.body.classList.toggle('sidebar-collapsed', collapsed);
        sidebarToggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    });
})();
</script>
