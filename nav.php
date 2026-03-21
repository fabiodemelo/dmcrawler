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
                <li class="nav-item">
                    <a class="nav-link <?= $current_page === 'add_website.php' ? 'active' : '' ?>" href="add_website.php"><i class="fas fa-globe"></i>Domains</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $current_page === 'keywords.php' ? 'active' : '' ?>" href="keywords.php"><i class="fas fa-key"></i>Keywords</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $current_page === 'locations.php' ? 'active' : '' ?>" href="locations.php"><i class="fas fa-map-marker-alt"></i>Locations</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $current_page === 'engines.php' ? 'active' : '' ?>" href="engines.php"><i class="fas fa-search"></i>Engines</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $current_page === 'view_emails.php' ? 'active' : '' ?>" href="view_emails.php"><i class="fas fa-envelope"></i>Emails</a>
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
