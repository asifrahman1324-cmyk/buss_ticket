<?php
/**
 * includes/sidebar.php
 */
$user = current_user();
$role = $user['role'] ?? 'customer';
$currentPage = basename($_SERVER['PHP_SELF'] ?? '');

function nav_active(string $page, string $current): string
{
    return $page === $current ? 'active' : '';
}
?>
<nav class="app-sidebar" id="appSidebar">
    <div class="app-sidebar-brand">
        <span class="app-brand-mark" aria-hidden="true">
            <i class="bi bi-signpost-2-fill"></i>
        </span>
        <div class="app-brand-text">
            <span class="app-brand-name">ICT&nbsp;BD&nbsp;Bus</span>
            <span class="app-brand-sub">Ticketing System</span>
        </div>
    </div>

    <ul class="app-nav">
        <li><a class="<?= nav_active('dashboard.php', $currentPage) ?>" href="dashboard.php"><i class="bi bi-grid-1x2-fill"></i><span>Dashboard</span></a></li>

        <?php if ($role === 'admin'): ?>
        <li class="app-nav-label">Fleet &amp; Network</li>
        <li><a class="<?= nav_active('bus.php', $currentPage) ?>" href="bus.php"><i class="bi bi-bus-front-fill"></i><span>Bus Management</span></a></li>
        <li><a class="<?= nav_active('route.php', $currentPage) ?>" href="route.php"><i class="bi bi-signpost-split-fill"></i><span>Route Management</span></a></li>
        <li><a class="<?= nav_active('schedule.php', $currentPage) ?>" href="schedule.php"><i class="bi bi-calendar-week-fill"></i><span>Schedule Management</span></a></li>
        <?php endif; ?>

        <li class="app-nav-label">Tickets</li>
        <li><a class="<?= nav_active('booking.php', $currentPage) ?>" href="booking.php"><i class="bi bi-ticket-perforated-fill"></i><span>Ticket Booking</span></a></li>
        <li><a class="<?= nav_active('payment.php', $currentPage) ?>" href="payment.php"><i class="bi bi-credit-card-fill"></i><span>Payment</span></a></li>

        <?php if (in_array($role, ['admin', 'staff'], true)): ?>
        <li class="app-nav-label">Insights</li>
        <li><a class="<?= nav_active('reports.php', $currentPage) ?>" href="reports.php"><i class="bi bi-bar-chart-fill"></i><span>Reports</span></a></li>
        <?php endif; ?>
    </ul>

    <div class="app-sidebar-footer">
        <a href="logout.php"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a>
    </div>
</nav>