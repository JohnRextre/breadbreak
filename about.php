<?php
$pageTitle = 'About Us | BreadBreak';

// Live numbers for the hero stat strip (falls back gracefully if the DB is unreachable).
$aboutMenuCount = 0;
try {
    require_once __DIR__ . '/config/database.php';
    $aboutPdo = getDatabaseConnection();
    $aboutMenuCount = (int) $aboutPdo->query("
        SELECT COUNT(*) FROM (
            SELECT c.id
            FROM menu_categories c
            JOIN inventory_items i ON i.category_id = c.id
            JOIN inventory_item_variants v
                  ON v.inventory_item_id = i.id
                 AND v.availability = 'available'
                 AND v.quantity > 0
            GROUP BY c.id
        ) AS stocked_menus
    ")->fetchColumn();
} catch (Throwable $aboutDbException) {
    $aboutMenuCount = 0;
}

$aboutDirectionsUrl = 'https://www.google.com/maps/search/?api=1&query=Bread+Break+Estrella+Village+Guiguinto+Bulacan';
?>

<?php include __DIR__ . '/includes/header.php'; ?>

<main class="about-page">

    <!-- ── Hero ─────────────────────────────────────────────────────────── -->
    <section class="banner-hero">
        <div class="container">
            <div class="banner-hero-copy">
                <span class="eyebrow eyebrow-light">About BreadBreak</span>
                <h1>Freshly baked in Guiguinto, made with love</h1>
                <p>
                    BreadBreak is a neighborhood bakery and an online ordering platform in one —
                    freshly baked breads, pastries, cookies, and cakes, ready for pickup or delivery
                    straight to your door.
                </p>

                <div class="banner-hero-actions">
                    <a href="/BreadBreak/menu.php" class="btn btn-primary">
                        <i class="fa-solid fa-bag-shopping"></i> Explore the Menu
                    </a>
                    <a href="#visit" class="btn btn-secondary">Visit the Bakery</a>
                </div>
            </div>

            <ul class="banner-stats" aria-label="BreadBreak at a glance">
                <li class="banner-stat">
                    <strong><?php echo $aboutMenuCount > 0 ? $aboutMenuCount : 'Fresh'; ?></strong>
                    <span><?php echo $aboutMenuCount > 0 ? 'Menus in the shop' : 'Menus daily'; ?></span>
                </li>
                <li class="banner-stat">
                    <strong>7AM–8PM</strong>
                    <span>Open Monday to Sunday</span>
                </li>
                <li class="banner-stat">
                    <strong>30–45 min</strong>
                    <span>Order preparation</span>
                </li>
                <li class="banner-stat">
                    <strong>8 km</strong>
                    <span>Delivery coverage</span>
                </li>
            </ul>
        </div>
    </section>

    <!-- ── Story ────────────────────────────────────────────────────────── -->
    <section class="section">
        <div class="container">
            <div class="about-showcase">
                <div class="about-copy">
                    <span class="eyebrow">Our Story</span>
                    <h2>Fresh bakery favorites for every day.</h2>
                    <p>
                        BreadBreak was created to make enjoying quality bakery goods easier, more
                        convenient, and more joyful. We bring together a warm range of breads,
                        pastries, cookies, and cakes that fit everyday moments and special
                        celebrations alike.
                    </p>
                    <p>
                        Our goal is simple: serve freshly baked products that feel comforting,
                        satisfying, and worth sharing with family and friends.
                    </p>

                    <div class="about-values">
                        <span><i class="fa-solid fa-wheat-awn"></i> Baked fresh daily</span>
                        <span><i class="fa-solid fa-heart"></i> Made for sharing</span>
                        <span><i class="fa-solid fa-mug-hot"></i> Comfort in every bite</span>
                    </div>
                </div>

                <div class="about-image-card">
                    <img src="/BreadBreak/assets/breadbreak_png/Pastry.png" alt="BreadBreak pastry assortment" />
                    <div class="story-badge">
                        <i class="fa-solid fa-store"></i>
                        <div>
                            <strong>Estrella Village, Guiguinto</strong>
                            <span>Bulacan branch · 7AM–8PM daily</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ── What we offer ────────────────────────────────────────────────── -->
    <section class="section alt-bg">
        <div class="container">
            <div class="section-heading center">
                <span class="eyebrow">Fresh from our oven</span>
                <h2>What We Offer</h2>
                <p class="section-subtitle">Everyday treats and celebration cakes — all baked in one kitchen.</p>
            </div>

            <div class="offer-grid">
                <a class="offer-card" href="/BreadBreak/menu.php">
                    <div class="offer-card-media">
                        <img src="/BreadBreak/assets/breadbreak_png/Pastry.png" alt="Freshly baked breads and pastries" />
                        <span class="offer-card-tag"><i class="fa-solid fa-bread-slice"></i> Baked daily</span>
                    </div>
                    <div class="offer-card-body">
                        <h3>Freshly Baked</h3>
                        <p>Breads, pastries, cookies, and cakes that come straight out of our oven every day.</p>
                        <span class="offer-card-link">Explore the menu <i class="fa-solid fa-arrow-right"></i></span>
                    </div>
                </a>

                <a class="offer-card" href="/BreadBreak/menu.php">
                    <div class="offer-card-media">
                        <img src="/BreadBreak/assets/breadbreak_png/SliceCake.png" alt="BreadBreak cakes and slices" />
                        <span class="offer-card-tag"><i class="fa-solid fa-layer-group"></i> Solo · Partner · Family</span>
                    </div>
                    <div class="offer-card-body">
                        <h3>Wide Selection</h3>
                        <p>Choose from a variety of bakery favorites, flavors, and service sizes for any appetite.</p>
                        <span class="offer-card-link">See all menus <i class="fa-solid fa-arrow-right"></i></span>
                    </div>
                </a>

                <a class="offer-card" href="/BreadBreak/menu.php">
                    <div class="offer-card-media">
                        <img src="/BreadBreak/assets/breadbreak_png/Brownies.png" alt="Order BreadBreak online" />
                        <span class="offer-card-tag"><i class="fa-solid fa-truck-fast"></i> Pickup &amp; delivery</span>
                    </div>
                    <div class="offer-card-body">
                        <h3>Easy Ordering</h3>
                        <p>Browse the menu, add your favorites to your bag, and check out in just a few taps.</p>
                        <span class="offer-card-link">Start an order <i class="fa-solid fa-arrow-right"></i></span>
                    </div>
                </a>
            </div>
        </div>
    </section>

    <!-- ── Visit us / map ───────────────────────────────────────────────── -->
    <section class="section" id="visit">
        <div class="container">
            <div class="location-showcase">
                <div class="visit-map">
                    <iframe
                        src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3856.89935785534!2d120.86967517519058!3d14.830905485683221!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x339653f14f7970b1%3A0x6472797c39ec3f75!2sBread%20Break!5e0!3m2!1sen!2sph!4v1789883477934!5m2!1sen!2sph"
                        loading="lazy"
                        allowfullscreen=""
                        referrerpolicy="strict-origin-when-cross-origin"
                        title="BreadBreak branch location on Google Maps">
                    </iframe>
                    <div class="visit-map-caption">
                        <span><i class="fa-solid fa-map-pin"></i> Estrella Village, Guiguinto, Bulacan</span>
                        <a href="<?php echo htmlspecialchars($aboutDirectionsUrl); ?>" target="_blank" rel="noopener noreferrer">
                            Get directions <i class="fa-solid fa-arrow-up-right-from-square"></i>
                        </a>
                    </div>
                </div>

                <div class="location-copy">
                    <span class="eyebrow">Visit BreadBreak</span>
                    <h2>Come Visit Us</h2>
                    <p>
                        Drop by the branch and enjoy our freshly baked treats in person — or reserve
                        your favorites for pickup while checking out online.
                    </p>

                    <div class="visit-info">
                        <div class="visit-info-item">
                            <i class="fa-solid fa-location-dot"></i>
                            <div>
                                <strong>Estrella Village Branch</strong>
                                <span>Estrella Village, Guiguinto, Bulacan</span>
                            </div>
                        </div>
                        <div class="visit-info-item">
                            <i class="fa-solid fa-clock"></i>
                            <div>
                                <strong>Open every day</strong>
                                <span>7:00 AM – 8:00 PM, Monday to Sunday</span>
                            </div>
                        </div>
                        <div class="visit-info-item">
                            <i class="fa-solid fa-bell-concierge"></i>
                            <div>
                                <strong>Preparation time</strong>
                                <span>Ready in 30–45 minutes after payment</span>
                            </div>
                        </div>
                    </div>

                    <div class="visit-actions">
                        <a href="<?php echo htmlspecialchars($aboutDirectionsUrl); ?>" class="btn btn-primary" target="_blank" rel="noopener noreferrer">
                            <i class="fa-solid fa-diamond-turn-right"></i> Get Directions
                        </a>
                        <a href="/BreadBreak/menu.php" class="btn btn-secondary">Order for Pickup</a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ── Why choose us ────────────────────────────────────────────────── -->
    <section class="section alt-bg">
        <div class="container">
            <div class="section-heading center">
                <span class="eyebrow">Our Promise</span>
                <h2>Why Choose BreadBreak?</h2>
            </div>

            <div class="feature-grid">
                <article class="feature-card">
                    <div class="feature-icon">
                        <i class="fa-solid fa-sun"></i>
                    </div>
                    <h3>Fresh Daily</h3>
                    <p>Freshly baked favorites prepared for your day.</p>
                </article>

                <article class="feature-card">
                    <div class="feature-icon">
                        <i class="fa-solid fa-truck-fast"></i>
                    </div>
                    <h3>Pickup &amp; Delivery</h3>
                    <p>Convenient options for getting your favorite treats.</p>
                </article>

                <article class="feature-card">
                    <div class="feature-icon">
                        <i class="fa-solid fa-people-group"></i>
                    </div>
                    <h3>Family Favorites</h3>
                    <p>Bakery treats made for sharing with family and friends.</p>
                </article>
            </div>
        </div>
    </section>

    <!-- ── CTA ──────────────────────────────────────────────────────────── -->
    <section class="cta-section">
        <div class="container">
            <div class="cta-box about-cta">
                <div>
                    <span class="eyebrow eyebrow-light">Fresh from the oven</span>
                    <h2>Find Your Favorite Treat</h2>
                    <p>Explore our menu and discover something freshly baked for you.</p>
                </div>

                <div class="cta-actions">
                    <a href="/BreadBreak/menu.php" class="btn btn-primary">Explore Menu</a>
                    <a href="/BreadBreak/login.php" class="btn btn-secondary">Order Now</a>
                </div>
            </div>
        </div>
    </section>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
