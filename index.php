<?php
$pageTitle = 'BreadBreak | Bakery Ordering and Inventory Management System';

// ---------------------------------------------------------------------------
// Best Sellers — ranked live from the orders stored in the database.
// Falls back to a curated list when the database is unavailable or empty.
// ---------------------------------------------------------------------------
$bestSellers = [];
$bestSellersLive = false;

try {
    require_once __DIR__ . '/config/database.php';
    $pdo = getDatabaseConnection();

    $statement = $pdo->query("
        SELECT
            i.id AS product_id,
            i.name,
            i.description,
            c.name AS category_name,
            MIN(v.price) AS starting_price,
            SUM(oi.quantity) AS units_sold,
            COUNT(DISTINCT oi.order_id) AS orders_count,
            (
                SELECT v2.id
                FROM inventory_item_variants v2
                WHERE v2.inventory_item_id = i.id
                  AND v2.availability = 'available'
                  AND v2.quantity > 0
                ORDER BY v2.price ASC, v2.id ASC
                LIMIT 1
            ) AS default_variant_id
        FROM order_items oi
        INNER JOIN orders o ON o.id = oi.order_id AND o.status <> 'cancelled'
        INNER JOIN inventory_item_variants v ON v.id = oi.variant_id
        INNER JOIN inventory_items i ON i.id = v.inventory_item_id
        INNER JOIN menu_categories c ON c.id = i.category_id
        GROUP BY i.id, i.name, i.description, c.name
        ORDER BY units_sold DESC, orders_count DESC, i.name ASC
        LIMIT 6
    ");

    foreach ($statement->fetchAll() as $row) {
        $row['starting_price'] = (float) $row['starting_price'];
        $row['units_sold'] = (int) $row['units_sold'];
        $row['image'] = '/BreadBreak/api/product-image.php?id=' . (int) $row['product_id'];
        $bestSellers[] = $row;
    }

    // Pull the service sizes / variants for each best seller so shoppers can see
    // whether it comes as pcs, tub, inch, solo/partner/family, etc.
    if ($bestSellers) {
        $bestSellerIds = array_map('intval', array_column($bestSellers, 'product_id'));
        $bestSellerPlaceholders = implode(',', array_fill(0, count($bestSellerIds), '?'));
        $variantStatement = $pdo->prepare(
            "SELECT inventory_item_id, service_size, price
             FROM inventory_item_variants
             WHERE inventory_item_id IN ($bestSellerPlaceholders)
               AND availability = 'available'
               AND quantity > 0
             ORDER BY price ASC, id ASC"
        );
        $variantStatement->execute($bestSellerIds);
        $bestSellerVariants = [];
        foreach ($variantStatement->fetchAll() as $variantRow) {
            $bestSellerVariants[(int) $variantRow['inventory_item_id']][] = [
                'service_size' => (string) $variantRow['service_size'],
                'price' => (float) $variantRow['price'],
            ];
        }
        foreach ($bestSellers as &$bestSellerRow) {
            $bestSellerRow['variants'] = $bestSellerVariants[(int) $bestSellerRow['product_id']] ?? [];
        }
        unset($bestSellerRow);
    }

    $bestSellersLive = count($bestSellers) > 0;
} catch (Throwable $exception) {
    $bestSellers = [];
}

if (!$bestSellers) {
    $bestSellers = [
        [
            'name' => 'Chocolate Cake',
            'description' => 'Moist chocolate layers with rich frosting, baked fresh daily.',
            'category_name' => 'Decadent Cakes',
            'starting_price' => 450.00,
            'units_sold' => 0,
            'image' => '/BreadBreak/assets/breadbreak_png/SliceCake.png',
        ],
        [
            'name' => 'Chocolate Chip Cookies',
            'description' => 'Chewy-centered cookies loaded with chocolate chips.',
            'category_name' => 'Cookies',
            'starting_price' => 120.00,
            'units_sold' => 0,
            'image' => '/BreadBreak/assets/breadbreak_png/cookies.png',
        ],
        [
            'name' => 'Brownies',
            'description' => 'Dense, fudgy brownies with a crackly top.',
            'category_name' => 'Pastries',
            'starting_price' => 150.00,
            'units_sold' => 0,
            'image' => '/BreadBreak/assets/breadbreak_png/Brownies.png',
        ],
        [
            'name' => 'Cream Cheese Garlic Bites',
            'description' => 'Soft bread bites topped with creamy garlic cheese.',
            'category_name' => 'Bite-sized Breads',
            'starting_price' => 89.00,
            'units_sold' => 0,
            'image' => '/BreadBreak/assets/breadbreak_png/CreamCheeseGarlicBites.png',
        ],
        [
            'name' => 'Pastry Platter',
            'description' => 'Assorted pastries perfect for sharing.',
            'category_name' => 'Pastries',
            'starting_price' => 200.00,
            'units_sold' => 0,
            'image' => '/BreadBreak/assets/breadbreak_png/Pastry.png',
        ],
    ];
}

// Every card needs the variant the "Order now" button adds to the cart.
foreach ($bestSellers as &$bestSellerRow) {
    $bestSellerRow['default_variant_id'] = (int) ($bestSellerRow['default_variant_id'] ?? 0);
    $bestSellerRow['starting_price'] = (float) ($bestSellerRow['starting_price'] ?? 0);
    $bestSellerRow['units_sold'] = (int) ($bestSellerRow['units_sold'] ?? 0);
    $bestSellerRow['variants'] = is_array($bestSellerRow['variants'] ?? null) ? $bestSellerRow['variants'] : [];
}
unset($bestSellerRow);

$heroSpotlight = $bestSellers[0];
$heroSpotlightSold = (int) ($heroSpotlight['units_sold'] ?? 0);
$headPreloads = ['/BreadBreak/assets/images/hero-bakery.jpg'];
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
                <?php if (!empty($heroSpotlight['default_variant_id'])): ?>
                <form class="hero-actions" method="POST" action="/BreadBreak/cart.php">
                    <input type="hidden" name="action" value="add" />
                    <input type="hidden" name="variant_id" value="<?php echo (int) $heroSpotlight['default_variant_id']; ?>" />
                    <input type="hidden" name="next" value="login" />
                    <button class="btn btn-primary" type="submit"><i class="fa-solid fa-bag-shopping"></i> Order Now</button>
                    <a href="/BreadBreak/menu.php" class="btn btn-secondary">Explore Menu</a>
                </form>
                <?php else: ?>
                <div class="hero-actions">
                    <a href="/BreadBreak/menu.php" class="btn btn-primary">Order Now</a>
                    <a href="/BreadBreak/menu.php" class="btn btn-secondary">Explore Menu</a>
                </div>
                <?php endif; ?>
                <ul class="hero-meta" aria-label="BreadBreak highlights">
                    <li><i class="fa-solid fa-bread-slice"></i> Fresh daily</li>
                    <li><i class="fa-solid fa-truck-fast"></i> Pickup &amp; delivery</li>
                    <li><i class="fa-solid fa-people-roof"></i> Family favorites</li>
                </ul>
            </div>

            <div class="hero-visual" aria-label="Bakery product showcase">
                <div class="hero-spotlight">
                    <span class="spotlight-badge<?php echo $heroSpotlightSold > 0 ? '' : ' is-featured'; ?>">
                        <i class="fa-solid fa-crown"></i>
                        <?php echo $heroSpotlightSold > 0 ? '#1 Best Seller' : 'Featured Pick'; ?>
                    </span>
                    <div class="spotlight-image">
                        <img src="<?php echo htmlspecialchars($heroSpotlight['image']); ?>" alt="<?php echo htmlspecialchars($heroSpotlight['name']); ?>" />
                    </div>
                    <div class="spotlight-info">
                        <span class="spotlight-category"><?php echo htmlspecialchars($heroSpotlight['category_name']); ?></span>
                        <strong><?php echo htmlspecialchars($heroSpotlight['name']); ?></strong>
                        <div class="spotlight-meta">
                            <span class="price"><span class="price-from">from</span> ₱<?php echo number_format($heroSpotlight['starting_price'], 2); ?></span>
                            <?php if ($heroSpotlightSold > 0): ?>
                            <span class="spotlight-sold"><i class="fa-solid fa-fire"></i> <?php echo $heroSpotlightSold; ?> sold</span>
                            <?php else: ?>
                            <span class="spotlight-sold"><i class="fa-solid fa-clock"></i> Freshly baked</span>
                            <?php endif; ?>
                        </div>
                        <?php
                            $spotlightVariants = $heroSpotlight['variants'] ?? [];
                            $spotlightVariantLabels = array_slice($spotlightVariants, 0, 3);
                        ?>
                        <?php if ($spotlightVariantLabels): ?>
                        <ul class="spotlight-variants" aria-label="Available sizes and variants">
                            <?php foreach ($spotlightVariantLabels as $spotlightVariant): ?>
                            <li><?php echo htmlspecialchars($spotlightVariant['service_size']); ?></li>
                            <?php endforeach; ?>
                            <?php if (count($spotlightVariants) > 3): ?>
                            <li class="is-more">+<?php echo count($spotlightVariants) - 3; ?> more</li>
                            <?php endif; ?>
                        </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="bestsellers section">
        <div class="container">
            <div class="section-heading center">
                <span class="eyebrow">Customer Favorites</span>
                <h2>Best Sellers</h2>
                <p class="section-subtitle">
                    <?php echo $bestSellersLive
                        ? 'These picks are ranked straight from our order records — the list updates automatically as new orders come in.'
                        : 'A few of our kitchen’s most-loved treats, baked fresh every day.'; ?>
                </p>
                <span class="bs-live">
                    <i class="fa-solid <?php echo $bestSellersLive ? 'fa-bolt' : 'fa-heart'; ?>"></i>
                    <?php echo $bestSellersLive ? 'Updates automatically with every order' : 'Handpicked by our bakers'; ?>
                </span>
            </div>

            <div class="bs-slider" data-bs-slider>
                <button class="bs-nav bs-prev" type="button" aria-label="Previous best sellers"><i class="fa-solid fa-chevron-left"></i></button>
                <div class="bs-viewport">
                    <div class="bs-track">
                        <?php foreach ($bestSellers as $bsIndex => $product): ?>
                        <?php
                            $bsRank = $bsIndex + 1;
                            $bsSold = (int) $product['units_sold'];
                            $bsLabel = $bsRank === 1
                                ? ($bsSold > 0 ? 'Best Seller' : 'Featured')
                                : '#' . $bsRank;
                        ?>
                        <article class="bs-card">
                            <span class="bs-rank rank-<?php echo $bsRank; ?><?php echo $bsRank > 3 ? ' rank-more' : ''; ?>">
                                <?php if ($bsRank === 1): ?><i class="fa-solid fa-crown"></i><?php endif; ?>
                                <?php echo $bsLabel; ?>
                            </span>
                            <div class="bs-image">
                                <img src="<?php echo htmlspecialchars($product['image']); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>" loading="lazy" />
                            </div>
                            <div class="bs-body">
                                <span class="bs-category"><?php echo htmlspecialchars($product['category_name']); ?></span>
                                <h3><?php echo htmlspecialchars($product['name']); ?></h3>
                                <p class="bs-desc"><?php echo htmlspecialchars($product['description']); ?></p>
                                <div class="bs-meta">
                                    <span class="price"><span class="price-from">from</span> ₱<?php echo number_format($product['starting_price'], 2); ?></span>
                                    <?php if ($bsSold > 0): ?>
                                    <span class="bs-sold"><i class="fa-solid fa-fire"></i> <?php echo $bsSold; ?> sold</span>
                                    <?php else: ?>
                                    <span class="bs-sold bs-fresh"><i class="fa-solid fa-clock"></i> Freshly baked</span>
                                    <?php endif; ?>
                                </div>

                                <div class="bs-variants">
                                    <span class="bs-variants-title"><i class="fa-solid fa-ruler-combined"></i> Service Size &amp; Variants</span>
                                    <?php $bsVariantList = $product['variants'] ?? []; ?>
                                    <?php if ($bsVariantList): ?>
                                    <ul class="bs-variant-list">
                                        <?php foreach (array_slice($bsVariantList, 0, 4) as $bsVariant): ?>
                                        <li>
                                            <span><?php echo htmlspecialchars($bsVariant['service_size']); ?></span>
                                            <em>₱<?php echo number_format($bsVariant['price'], 2); ?></em>
                                        </li>
                                        <?php endforeach; ?>
                                        <?php if (count($bsVariantList) > 4): ?>
                                        <li class="is-more">+<?php echo count($bsVariantList) - 4; ?> more</li>
                                        <?php endif; ?>
                                    </ul>
                                    <?php else: ?>
                                    <p class="bs-variant-none">Sizes are listed on the Shop page.</p>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($product['default_variant_id'])): ?>
                                <form class="bs-order-form" method="POST" action="/BreadBreak/cart.php">
                                    <input type="hidden" name="action" value="add" />
                                    <input type="hidden" name="variant_id" value="<?php echo (int) $product['default_variant_id']; ?>" />
                                    <input type="hidden" name="next" value="login" />
                                    <button class="bs-order" type="submit">Order now <i class="fa-solid fa-arrow-right"></i></button>
                                </form>
                                <?php else: ?>
                                <a href="/BreadBreak/menu.php" class="bs-order">Order now <i class="fa-solid fa-arrow-right"></i></a>
                                <?php endif; ?>
                            </div>
                        </article>
                        <?php endforeach; ?>
                    </div>
                </div>
                <button class="bs-nav bs-next" type="button" aria-label="Next best sellers"><i class="fa-solid fa-chevron-right"></i></button>
            </div>

            <div class="bs-dots" aria-hidden="true"></div>

            <div class="bs-footer-action">
                <a href="/BreadBreak/menu.php" class="btn btn-primary">Explore Full Menu</a>
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

    <section class="cta-section home-cta">
        <div class="container cta-box">
            <div>
                <span class="eyebrow eyebrow-light">Fresh Flavors Await</span>
                <h2>Craving Something Fresh?</h2>
                <p>Order your favorite BreadBreak treats today.</p>
            </div>
            <a href="/BreadBreak/menu.php" class="btn btn-primary">Start Ordering</a>
        </div>
    </section>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var slider = document.querySelector('[data-bs-slider]');
    if (!slider) return;

    var track = slider.querySelector('.bs-track');
    var viewport = slider.querySelector('.bs-viewport');
    var prevButton = slider.querySelector('.bs-prev');
    var nextButton = slider.querySelector('.bs-next');
    var dotsWrap = document.querySelector('.bs-dots');
    var cards = Array.prototype.slice.call(track.children);
    if (!cards.length) return;

    var index = 0;
    var timer = null;
    var builtFor = -1;
    var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function gap() {
        return parseFloat(window.getComputedStyle(track).gap) || 0;
    }

    function step() {
        return cards[0].getBoundingClientRect().width + gap();
    }

    function perView() {
        return Math.max(1, Math.round((viewport.clientWidth + gap()) / step()));
    }

    function maxIndex() {
        return Math.max(0, cards.length - perView());
    }

    function updateDots() {
        if (!dotsWrap) return;
        Array.prototype.forEach.call(dotsWrap.children, function (dot, dotIndex) {
            dot.classList.toggle('is-active', dotIndex === index);
        });
    }

    function buildDots(max) {
        if (!dotsWrap) return;
        if (builtFor !== max) {
            builtFor = max;
            dotsWrap.innerHTML = '';
            for (var i = 0; i <= max; i++) {
                (function (dotIndex) {
                    var dot = document.createElement('button');
                    dot.type = 'button';
                    dot.className = 'bs-dot';
                    dot.setAttribute('aria-label', 'Go to slide ' + (dotIndex + 1));
                    dot.addEventListener('click', function () {
                        goTo(dotIndex);
                        restart();
                    });
                    dotsWrap.appendChild(dot);
                })(i);
            }
        }
        updateDots();
    }

    function apply() {
        var max = maxIndex();
        if (index > max) index = max;
        if (index < 0) index = 0;
        track.style.transform = 'translateX(' + (-index * step()) + 'px)';

        var showNav = max > 0;
        prevButton.classList.toggle('is-hidden', !showNav);
        nextButton.classList.toggle('is-hidden', !showNav);
        if (dotsWrap) dotsWrap.classList.toggle('is-hidden', !showNav);
        buildDots(max);
    }

    function goTo(target) {
        var max = maxIndex();
        if (target > max) target = 0;
        if (target < 0) target = max;
        index = target;
        apply();
    }

    function stop() {
        if (timer) {
            window.clearInterval(timer);
            timer = null;
        }
    }

    function start() {
        if (reduceMotion) return;
        stop();
        timer = window.setInterval(function () {
            goTo(index + 1);
        }, 4000);
    }

    function restart() {
        stop();
        start();
    }

    prevButton.addEventListener('click', function () { goTo(index - 1); restart(); });
    nextButton.addEventListener('click', function () { goTo(index + 1); restart(); });

    slider.addEventListener('mouseenter', stop);
    slider.addEventListener('mouseleave', start);
    slider.addEventListener('focusin', stop);
    slider.addEventListener('focusout', start);
    document.addEventListener('visibilitychange', function () {
        document.hidden ? stop() : start();
    });

    var resizeTimer;
    window.addEventListener('resize', function () {
        window.clearTimeout(resizeTimer);
        resizeTimer = window.setTimeout(function () {
            builtFor = -1;
            apply();
        }, 150);
    });

    apply();
    start();
});
</script>
