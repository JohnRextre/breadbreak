<?php
$pageTitle = 'BreadBreak | Bakery Ordering and Inventory Management System';
?>

<?php include __DIR__ . '/includes/header.php'; ?>

<main>
    <section class="hero">
        <div class="container hero-content">
            <div class="hero-copy">
                <span class="eyebrow">Fresh from our oven</span>
                <h1>Freshly Baked, Made With Love</h1>
                <p>
                    Enjoy freshly baked breads, pastries, cakes, and sweet treats from BreadBreak.
                </p>
                <div class="hero-actions">
                    <a href="/BreadBreak/login.php" class="btn btn-primary">Order Now</a>
                    <a href="/BreadBreak/menu.php" class="btn btn-secondary">Explore Menu</a>
                </div>
                <ul class="hero-meta" aria-label="BreadBreak highlights">
                    <li>Fresh daily</li>
                    <li>Pickup & delivery</li>
                    <li>Family favorites</li>
                </ul>
            </div>

            <div class="hero-visual" aria-label="Bakery product showcase">
                <div class="image-card main-card">
                    <div class="image-badge">Best Seller</div>
                    <img src="/BreadBreak/assets/breadbreak_png/SliceCake.png" alt="Freshly baked cake" class="product-image-hero" />
                </div>
                <div class="image-card floating-card">
                    <span class="mini-label">Today’s Treat</span>
                    <strong>Chocolate Cake</strong>
                    <small>Freshly baked daily</small>
                </div>
            </div>
        </div>
    </section>

    <section class="benefits section alt-bg">
        <div class="container">
            <div class="section-heading center">
                <span class="eyebrow">Why BreadBreak</span>
                <h2>Why Choose BreadBreak</h2>
            </div>

            <div class="benefits-grid">
                <div class="benefit-item">
                    <div class="benefit-icon">
                        <i class="fa-solid fa-leaf"></i>
                    </div>
                    <h3>Freshly Baked</h3>
                    <p>Made fresh for every order.</p>
                </div>
                <div class="benefit-item">
                    <div class="benefit-icon">
                        <i class="fa-solid fa-star"></i>
                    </div>
                    <h3>Quality Ingredients</h3>
                    <p>Carefully selected ingredients for great taste.</p>
                </div>
                <div class="benefit-item">
                    <div class="benefit-icon">
                        <i class="fa-solid fa-basket-shopping"></i>
                    </div>
                    <h3>Easy Ordering</h3>
                    <p>Browse, order, and enjoy your favorites easily.</p>
                </div>
                <div class="benefit-item">
                    <div class="benefit-icon">
                        <i class="fa-solid fa-truck"></i>
                    </div>
                    <h3>Convenient Pickup / Delivery</h3>
                    <p>Choose the fulfillment option that works for you.</p>
                </div>
            </div>
        </div>
    </section>

    <section class="about section">
        <div class="container about-content">
            <div class="about-image">
                <img src="/BreadBreak/assets/breadbreak_png/Pastry.png" alt="BreadBreak pastry selection" class="product-image-about" />
            </div>
            <div class="about-copy">
                <span class="eyebrow">About BreadBreak</span>
                <h2>Made Fresh for Every Moment</h2>
                <p>
                    BreadBreak makes it easy to enjoy your favorite breads, pastries, cakes, and sweet treats.
                    Browse our selection and discover freshly baked products made for every occasion.
                </p>
                <a href="/BreadBreak/about.php" class="btn btn-primary">Learn More</a>
            </div>
        </div>
    </section>

    <section class="process section alt-bg">
        <div class="container">
            <div class="section-heading center">
                <span class="eyebrow">How It Works</span>
                <h2>Simple Ordering Process</h2>
            </div>

            <div class="steps-grid">
                <div class="step-item">
                    <span class="step-number">01</span>
                    <h3>Browse</h3>
                    <p>Explore our bakery products.</p>
                </div>
                <div class="step-item">
                    <span class="step-number">02</span>
                    <h3>Order</h3>
                    <p>Add your favorites to your cart and checkout.</p>
                </div>
                <div class="step-item">
                    <span class="step-number">03</span>
                    <h3>Enjoy</h3>
                    <p>Pick up or receive your order.</p>
                </div>
            </div>
        </div>
    </section>

    <section class="cta-section">
        <div class="container cta-box">
            <div>
                <span class="eyebrow">Fresh Flavors Await</span>
                <h2>Craving Something Fresh?</h2>
                <p>Order your favorite BreadBreak treats today.</p>
            </div>
            <a href="/BreadBreak/login.php" class="btn btn-primary">Start Ordering</a>
        </div>
    </section>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
