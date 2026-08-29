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
                    <a href="/BreadBreak/shop.php" class="btn btn-primary">Order Now</a>
                    <a href="#menu" class="btn btn-secondary">Explore Menu</a>
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
                    <div class="product-illustration bread-illustration">
                        <span class="emoji">🥖</span>
                    </div>
                </div>
                <div class="image-card floating-card">
                    <span class="mini-label">Today’s Treat</span>
                    <strong>Ube Cheese</strong>
                    <small>Freshly baked</small>
                </div>
            </div>
        </div>
    </section>

    <section class="categories section" id="menu">
        <div class="container">
            <div class="section-heading center">
                <span class="eyebrow">Explore Our Menu</span>
                <h2>Explore Our Menu</h2>
                <p>Find your favorite freshly baked treats.</p>
            </div>

            <div class="category-grid">
                <a href="/BreadBreak/shop.php" class="category-card">
                    <div class="category-icon">🥖</div>
                    <h3>Bread</h3>
                </a>
                <a href="/BreadBreak/shop.php" class="category-card">
                    <div class="category-icon">🥐</div>
                    <h3>Pastries</h3>
                </a>
                <a href="/BreadBreak/shop.php" class="category-card">
                    <div class="category-icon">🍰</div>
                    <h3>Cakes</h3>
                </a>
                <a href="/BreadBreak/shop.php" class="category-card">
                    <div class="category-icon">🍪</div>
                    <h3>Cookies</h3>
                </a>
                <a href="/BreadBreak/shop.php" class="category-card">
                    <div class="category-icon">🍫</div>
                    <h3>Brownies</h3>
                </a>
            </div>
        </div>
    </section>

    <section class="featured section">
        <div class="container">
            <div class="section-heading center">
                <span class="eyebrow">Our Favorites</span>
                <h2>Our Best Sellers</h2>
            </div>

            <div class="product-grid">
                <?php
                $products = [
                    ['name' => 'Classic Pandesal', 'description' => 'Soft, warm, and perfectly golden for everyday breakfasts.', 'price' => '₱50', 'emoji' => '🥖'],
                    ['name' => 'Cheese Bread', 'description' => 'Buttery bread with a rich, savory cheese finish.', 'price' => '₱85', 'emoji' => '🧀'],
                    ['name' => 'Chocolate Cake', 'description' => 'Moist cakes layered with indulgent chocolate goodness.', 'price' => '₱450', 'emoji' => '🍫'],
                    ['name' => 'Chocolate Chip Cookies', 'description' => 'Crisp edges, chewy centers, and loaded with chocolate chips.', 'price' => '₱120', 'emoji' => '🍪'],
                    ['name' => 'Ube Cheese Pandesal', 'description' => 'A vibrant local favorite with creamy cheese and ube flavor.', 'price' => '₱100', 'emoji' => '💜'],
                    ['name' => 'Fudge Brownies', 'description' => 'Rich, dense, and decadent with every bite.', 'price' => '₱150', 'emoji' => '🍰'],
                ];

                foreach ($products as $product):
                    echo '<article class="product-card">';
                    echo '  <div class="product-image" aria-label="' . htmlspecialchars($product['name']) . ' product image"><span>' . $product['emoji'] . '</span></div>';
                    echo '  <div class="product-content">';
                    echo '    <h3>' . htmlspecialchars($product['name']) . '</h3>';
                    echo '    <p>' . htmlspecialchars($product['description']) . '</p>';
                    echo '    <div class="product-meta">';
                    echo '      <span class="price">' . htmlspecialchars($product['price']) . '</span>';
                    echo '      <button type="button" class="add-to-cart" data-product="' . htmlspecialchars($product['name']) . '">Add to Cart</button>';
                    echo '    </div>';
                    echo '  </div>';
                    echo '</article>';
                endforeach;
                ?>
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
                    <div class="benefit-icon">🌾</div>
                    <h3>Freshly Baked</h3>
                    <p>Made fresh for every order.</p>
                </div>
                <div class="benefit-item">
                    <div class="benefit-icon">🥣</div>
                    <h3>Quality Ingredients</h3>
                    <p>Carefully selected ingredients for great taste.</p>
                </div>
                <div class="benefit-item">
                    <div class="benefit-icon">🛒</div>
                    <h3>Easy Ordering</h3>
                    <p>Browse, order, and enjoy your favorites easily.</p>
                </div>
                <div class="benefit-item">
                    <div class="benefit-icon">🚚</div>
                    <h3>Convenient Pickup / Delivery</h3>
                    <p>Choose the fulfillment option that works for you.</p>
                </div>
            </div>
        </div>
    </section>

    <section class="about section">
        <div class="container about-content">
            <div class="about-image">
                <div class="image-placeholder large">
                    <span>🥐</span>
                </div>
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
            <a href="/BreadBreak/shop.php" class="btn btn-primary">Start Ordering</a>
        </div>
    </section>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
