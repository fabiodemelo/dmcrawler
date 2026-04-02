<?php $current_page = basename($_SERVER['PHP_SELF']); ?>
<nav class="navbar navbar-expand-lg">
    <div class="container-fluid">
        <a class="navbar-brand" href="index.php">demelos<span>.</span></a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item">
                    <a class="nav-link <?= $current_page === 'index.php' ? 'active' : '' ?>" href="index.php"><i class="fas fa-chart-line"></i>Dashboard</a>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?= in_array($current_page, ['add_website.php','view_emails.php']) ? 'active' : '' ?>" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="fas fa-database"></i>Data</a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item <?= $current_page === 'add_website.php' ? 'active' : '' ?>" href="add_website.php"><i class="fas fa-globe me-2"></i>Domains</a></li>
                        <li><a class="dropdown-item <?= $current_page === 'view_emails.php' ? 'active' : '' ?>" href="view_emails.php"><i class="fas fa-envelope me-2"></i>Emails</a></li>
                    </ul>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?= in_array($current_page, ['campaigns.php','keywords.php','keyword_groups.php','locations.php','location_groups.php']) ? 'active' : '' ?>" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="fas fa-bullhorn"></i>Campaigns</a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item <?= $current_page === 'campaigns.php' ? 'active' : '' ?>" href="campaigns.php"><i class="fas fa-bullhorn me-2"></i>Campaigns</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item <?= $current_page === 'keywords.php' ? 'active' : '' ?>" href="keywords.php"><i class="fas fa-key me-2"></i>Keywords</a></li>
                        <li><a class="dropdown-item <?= $current_page === 'keyword_groups.php' ? 'active' : '' ?>" href="keyword_groups.php"><i class="fas fa-layer-group me-2"></i>KW Groups</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item <?= $current_page === 'locations.php' ? 'active' : '' ?>" href="locations.php"><i class="fas fa-map-marker-alt me-2"></i>Locations</a></li>
                        <li><a class="dropdown-item <?= $current_page === 'location_groups.php' ? 'active' : '' ?>" href="location_groups.php"><i class="fas fa-layer-group me-2"></i>Loc Groups</a></li>
                    </ul>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $current_page === 'engines.php' ? 'active' : '' ?>" href="engines.php"><i class="fas fa-search"></i>Engines</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $current_page === 'metrics.php' ? 'active' : '' ?>" href="metrics.php"><i class="fas fa-chart-bar"></i>Metrics</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $current_page === 'live.php' ? 'active' : '' ?>" href="live.php"><i class="fas fa-satellite-dish"></i>Live</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $current_page === 'settings.php' ? 'active' : '' ?>" href="settings.php"><i class="fas fa-cog"></i>Settings</a>
                </li>
            </ul>
            <ul class="navbar-nav">
                <li class="nav-item">
                    <a class="nav-link" href="logout.php"><i class="fas fa-sign-out-alt"></i>Logout</a>
                </li>
            </ul>
        </div>
    </div>
</nav>
<style>
.navbar .dropdown-menu {
    background: #1e293b;
    border: 1px solid #334155;
    border-radius: 8px;
    padding: 6px 0;
}
.navbar .dropdown-item {
    color: #94a3b8;
    padding: 6px 16px;
    font-size: 0.85rem;
}
.navbar .dropdown-item:hover, .navbar .dropdown-item:focus {
    background: rgba(79,70,229,0.15);
    color: #e2e8f0;
}
.navbar .dropdown-item.active {
    background: rgba(79,70,229,0.25);
    color: #fff;
}
.navbar .dropdown-divider {
    border-color: #334155;
    margin: 4px 0;
}
</style>
