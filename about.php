<?php
$pageTitle = 'About Us | BreadBreak';
?>

<?php include __DIR__ . '/includes/header.php'; ?>

<main class="page-shell about-page">
    <section class="page-hero">
        <div class="container narrow text-center">
            <span class="eyebrow">About BreadBreak</span>
            <h1>Freshly Baked, Made With Love</h1>
            <p>BreadBreak is a bakery and online ordering platform that brings freshly baked breads, pastries, cookies, and cakes closer to customers.</p>
            <p>From everyday treats to desserts for special occasions, BreadBreak offers a variety of freshly baked favorites made for sharing.</p>
        </div>
    </section>

    <section class="section">
        <div class="container">
            <div class="about-showcase">
                <div class="about-copy">
                    <span class="eyebrow">About BreadBreak</span>
                    <h2>Fresh bakery favorites for every day.</h2>
                    <p>BreadBreak was created to make enjoying quality bakery goods easier, more convenient, and more joyful. We bring together a warm range of breads, pastries, cookies, and cakes that fit everyday moments and special celebrations alike.</p>
                    <p>Our goal is simple: serve freshly baked products that feel comforting, satisfying, and worth sharing with family and friends.</p>
                </div>

                <div class="about-image-card">
                    <img src="/BreadBreak/assets/breadbreak_png/Pastry.png" alt="BreadBreak pastry assortment" />
                </div>
            </div>
        </div>
    </section>

    <section class="section alt-bg">
        <div class="container">
            <div class="section-heading center">
                <span class="eyebrow">What We Offer</span>
                <h2>What We Offer</h2>
            </div>

            <div class="offer-grid">
                <article class="info-card">
                    <div class="info-icon">
                        <i class="fa-solid fa-bread-slice"></i>
                    </div>
                    <h3>Freshly Baked</h3>
                    <p>Enjoy freshly baked breads, pastries, cookies, and cakes.</p>
                </article>

                <article class="info-card">
                    <div class="info-icon">
                        <i class="fa-solid fa-layer-group"></i>
                    </div>
                    <h3>Wide Selection</h3>
                    <p>Choose from a variety of bakery favorites and different service sizes.</p>
                </article>

                <article class="info-card">
                    <div class="info-icon">
                        <i class="fa-solid fa-cart-shopping"></i>
                    </div>
                    <h3>Easy Ordering</h3>
                    <p>Browse the BreadBreak menu and conveniently place your order online.</p>
                </article>
            </div>
        </div>
    </section>

    <section class="section">
        <div class="container">
            <div class="location-showcase">
                <div class="location-image-wrap">
                    <img src="/BreadBreak/assets/breadbreak_png/breadbreak_location.png" alt="BreadBreak location map" />
                </div>

                <div class="location-copy">
                    <span class="eyebrow">Visit BreadBreak</span>
                    <h2>Come Visit Us</h2>
                    <p>Drop by BreadBreak and enjoy our freshly baked treats in person.</p>

                    <div class="location-item">
                        <i class="fa-solid fa-location-dot"></i>
                        <span>Estrella Village, Guiguinto, Bulacan Branch</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

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

    <section class="cta-section">
        <div class="container">
            <div class="cta-box about-cta">
                <div>
                    <span class="eyebrow eyebrow-light">Find Your Favorite Treat</span>
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
