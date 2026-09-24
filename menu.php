<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (($_SESSION['role'] ?? '') === 'customer' && !defined('CUSTOMER_MENU_DASHBOARD')) {
    header('Location: /BreadBreak/customer/menu_dashboard.php');
    exit;
}
$pageTitle = 'Shop | BreadBreak | Bakery Ordering and Inventory Management System';
$isCustomerDashboard = session_status() === PHP_SESSION_ACTIVE && (($_SESSION['role'] ?? '') === 'customer');

// Menu categories shown as the first step of the shop (image + name + product count).
$shopCategories = [];
try {
    require_once __DIR__ . '/config/database.php';
    $shopPdo = getDatabaseConnection();
    $categoryStatement = $shopPdo->query("
        SELECT c.id, c.name,
               COUNT(DISTINCT CASE WHEN v.id IS NOT NULL THEN i.id END) AS product_count
        FROM menu_categories c
        LEFT JOIN inventory_items i ON i.category_id = c.id
        LEFT JOIN inventory_item_variants v
               ON v.inventory_item_id = i.id
              AND v.availability = 'available'
              AND v.quantity > 0
        GROUP BY c.id, c.name
        ORDER BY c.name ASC
    ");
    foreach ($categoryStatement->fetchAll() as $categoryRow) {
        if ((int) $categoryRow['product_count'] < 1) continue;
        $shopCategories[] = [
            'id' => (int) $categoryRow['id'],
            'name' => (string) $categoryRow['name'],
            'count' => (int) $categoryRow['product_count'],
        ];
    }
} catch (Throwable $shopCategoryException) {
    $shopCategories = [];
}
?>

<?php include __DIR__ . '/includes/header.php'; ?>

<main>
    <?php if (!$isCustomerDashboard): ?><section class="breadcrumb-section">
        <div class="container">
            <nav class="breadcrumb" aria-label="Breadcrumb">
                <a href="/BreadBreak/index.php">Home</a>
                <i class="fa-solid fa-chevron-right"></i>
                <span>Shop</span>
            </nav>
        </div>
    </section><?php endif; ?>

    <section class="shop-banner">
        <div class="container shop-banner-inner">
            <div class="shop-banner-copy">
                <span class="eyebrow eyebrow-light">Fresh from the oven</span>
                <h1>Bakery Shop</h1>
                <p>Pick a menu, choose your service size and variant, then add it to your bag.</p>
            </div>
        </div>
    </section>

    <section class="catalog-section section">
        <div class="container">

            <div class="shop-toolbar">
                <div class="shop-search" id="shopSearch">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <label class="sr-only" for="productSearch">Search products</label>
                    <input type="search" id="productSearch" placeholder="Search breads, cakes, cookies..." autocomplete="off" />
                    <span class="shop-search-trail">
                        <span class="shop-search-count" id="searchCount" hidden></span>
                        <button class="shop-search-clear" id="searchClear" type="button" hidden aria-label="Clear search" title="Clear search">
                            <i class="fa-solid fa-circle-xmark"></i>
                        </button>
                    </span>
                </div>
                <button class="shop-filter-button" type="button" id="shopFilterToggle" aria-expanded="false" aria-controls="shopFilterPanel">
                    <i class="fa-solid fa-sliders"></i> Filter
                    <span class="shop-filter-count" id="filterCount" hidden>0</span>
                </button>
            </div>

            <!-- Live summary of what is currently filtering the shop -->
            <div class="shop-filter-status" id="filterStatus" hidden></div>

            <div class="shop-filter-panel" id="shopFilterPanel" hidden>
                <div class="shop-filter-group">
                    <span class="shop-filter-label">Menu</span>
                    <div class="shop-filter-chips" id="filterCategories"></div>
                </div>
                <div class="shop-filter-group">
                    <label class="shop-filter-label" for="sortProducts">Sort by</label>
                    <select id="sortProducts" class="shop-filter-select">
                        <option value="name-asc">Name (A-Z)</option>
                        <option value="name-desc">Name (Z-A)</option>
                        <option value="price-asc">Price (Low to High)</option>
                        <option value="price-desc">Price (High to Low)</option>
                    </select>
                </div>
                <button class="shop-filter-reset" type="button" id="filterReset">Reset</button>
            </div>

            <div class="shop-levels">

                <!-- Step 1: menus -->
                <section class="shop-level" data-level="categories">
                    <div class="shop-level-head">
                        <span class="eyebrow">Step 1</span>
                        <h2>Choose a Menu</h2>
                        <p>Pick a category to see everything baked inside it.</p>
                    </div>

                    <?php if ($shopCategories): ?>
                    <div class="shop-category-grid">
                        <?php foreach ($shopCategories as $shopCategory): ?>
                        <button class="shop-category-tile" type="button" data-category="<?php echo $shopCategory['id']; ?>">
                            <span class="shop-category-img">
                                <img src="/BreadBreak/api/category-image.php?id=<?php echo $shopCategory['id']; ?>" alt="" loading="lazy" />
                            </span>
                            <span class="shop-category-body">
                                <span class="shop-category-name"><?php echo htmlspecialchars($shopCategory['name']); ?></span>
                                <span class="shop-category-count"><?php echo $shopCategory['count']; ?> product<?php echo $shopCategory['count'] === 1 ? '' : 's'; ?></span>
                            </span>
                            <i class="fa-solid fa-chevron-right"></i>
                        </button>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="shop-empty"><i class="fa-solid fa-bread-slice"></i><p>No menus are available right now. Please check back soon.</p></div>
                    <?php endif; ?>
                </section>

                <!-- Step 2: products inside a menu -->
                <section class="shop-level" data-level="products" hidden>
                    <button class="shop-back" type="button" data-go="categories"><i class="fa-solid fa-arrow-left"></i> All Menus</button>
                    <div class="shop-level-head">
                        <span class="eyebrow">Step 2</span>
                        <h2 id="levelCategoryName">Menu</h2>
                        <p id="levelCategoryMeta"></p>
                    </div>
                    <div class="shop-product-list" id="productList"></div>
                </section>

                <!-- Step 3: product detail -->
                <section class="shop-level" data-level="detail" hidden>
                    <button class="shop-back" type="button" data-go="products"><i class="fa-solid fa-arrow-left"></i> Back to menu</button>
                    <div class="shop-detail" id="productDetail"></div>
                </section>

                <!-- Search results / browse-all list -->
                <section class="shop-level" data-level="results" hidden>
                    <div class="shop-level-head">
                        <span class="eyebrow" id="resultsEyebrow">Search</span>
                        <h2 id="resultsTitle">Results</h2>
                        <p id="resultsMeta"></p>
                    </div>
                    <div class="shop-product-list" id="resultsList"></div>
                </section>

            </div>
        </div>
    </section>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>

<script>
(function () {
    'use strict';

    var products = [];
    var categories = [];
    var currentCategory = null;
    var sortMode = 'name-asc';
    var searchTimer = null;
    var SORT_LABELS = {
        'name-asc': 'Name (A-Z)',
        'name-desc': 'Name (Z-A)',
        'price-asc': 'Price (Low to High)',
        'price-desc': 'Price (High to Low)'
    };
    var detailState = { product: null, variant: null, quantity: 1 };
    var favoriteIds = new Set();
    var signedIn = true;

    var levels = {};
    document.querySelectorAll('.shop-level').forEach(function (section) { levels[section.dataset.level] = section; });

    document.querySelectorAll('.shop-category-tile').forEach(function (tile) {
        categories.push({
            id: Number(tile.dataset.category),
            name: tile.querySelector('.shop-category-name').textContent.trim(),
            count: Number((tile.querySelector('.shop-category-count').textContent || '').replace(/\D/g, '')) || 0
        });
    });

    function escapeHtml(value) {
        return String(value == null ? '' : value).replace(/[&<>'"]/g, function (character) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' })[character];
        });
    }

    function peso(value) { return '\u20B1' + Number(value).toFixed(2); }

    function productPhoto(product) {
        return product.photo || ('/BreadBreak/api/product-image.php?id=' + product.id);
    }

    function minPrice(product) {
        return Math.min.apply(null, product.variants.map(function (variant) { return Number(variant.price); }));
    }

    function maxPrice(product) {
        return Math.max.apply(null, product.variants.map(function (variant) { return Number(variant.price); }));
    }

    function totalStock(variants) {
        return variants.reduce(function (sum, variant) { return sum + Number(variant.quantity || 0); }, 0);
    }

    function categoryName(product) {
        return product.categoryName || String(product.category || '').replace(/-/g, ' ');
    }

    function showLevel(name, shouldScroll) {
        Object.keys(levels).forEach(function (key) { levels[key].hidden = key !== name; });
        if (shouldScroll !== false) window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function sorted(list) {
        var copy = list.slice();
        copy.sort(function (left, right) {
            if (sortMode === 'price-asc' || sortMode === 'price-desc') {
                var difference = minPrice(left) - minPrice(right);
                return sortMode === 'price-asc' ? difference : -difference;
            }
            var comparison = left.name.localeCompare(right.name);
            return sortMode === 'name-desc' ? -comparison : comparison;
        });
        return copy;
    }

    function heartButton(product, large) {
        var isFavorite = favoriteIds.has(product.id);
        return '' +
            '<button class="shop-fav-button' + (large ? ' is-large' : '') + (isFavorite ? ' is-fav' : '') + '"' +
                ' type="button" data-fav="' + product.id + '"' +
                ' aria-pressed="' + (isFavorite ? 'true' : 'false') + '"' +
                ' aria-label="' + (isFavorite ? 'Remove from' : 'Save to') + ' My Favorites"' +
                ' title="' + (isFavorite ? 'Remove from My Favorites' : 'Save to My Favorites') + '">' +
                '<i class="' + (isFavorite ? 'fa-solid' : 'fa-regular') + ' fa-heart"></i>' +
            '</button>';
    }

    function productRow(product) {
        var from = minPrice(product);
        return '' +
            '<div class="shop-product-row">' +
                '<button class="shop-product-row-main" type="button" data-product="' + product.id + '">' +
                    '<span class="shop-product-thumb"><img src="' + productPhoto(product) + '" alt="" loading="lazy" /></span>' +
                    '<span class="shop-product-info">' +
                        '<span class="shop-product-name">' + escapeHtml(product.name) + '</span>' +
                        '<span class="shop-product-desc">' + escapeHtml(product.description || '') + '</span>' +
                        '<span class="shop-product-price">' + peso(from) +
                            (minPrice(product) !== maxPrice(product) ? '<em>starting price</em>' : '<em>' + product.variants.length + ' variant' + (product.variants.length === 1 ? '' : 's') + '</em>') +
                        '</span>' +
                    '</span>' +
                    '<i class="fa-solid fa-chevron-right"></i>' +
                '</button>' +
                heartButton(product, false) +
            '</div>';
    }

    function sortLabel(mode) { return SORT_LABELS[mode] || SORT_LABELS['name-asc']; }

    function emptyState(message, icon) {
        return '<div class="shop-empty"><i class="fa-solid ' + (icon || 'fa-cookie-bite') + '"></i><p>' + escapeHtml(message) + '</p></div>';
    }

    function renderList(container, list, emptyMessage, icon) {
        if (!products.length) { container.innerHTML = emptyState('Loading products, one moment…', 'fa-spinner'); return; }
        if (!list.length) { container.innerHTML = emptyState(emptyMessage, icon); return; }
        container.innerHTML = list.map(productRow).join('');
    }

    function matchingProducts(term) {
        var needle = term.toLowerCase();
        return products.filter(function (product) {
            return (product.name + ' ' + (product.description || '') + ' ' + categoryName(product)).toLowerCase().indexOf(needle) !== -1;
        });
    }

    function renderProducts() {
        var list = products.filter(function (product) { return product.category_id === currentCategory; });
        var category = categories.find(function (entry) { return entry.id === currentCategory; });
        document.getElementById('levelCategoryName').textContent = category ? category.name : 'Menu';
        document.getElementById('levelCategoryMeta').textContent = list.length
            ? list.length + ' product' + (list.length === 1 ? '' : 's') + ' available · ' + sortLabel(sortMode)
            : '';
        renderList(document.getElementById('productList'), sorted(list), 'No available products in this menu yet.', 'fa-bread-slice');
    }

    function renderResults(term) {
        var list = matchingProducts(term);
        document.getElementById('resultsEyebrow').textContent = 'Search';
        document.getElementById('resultsTitle').textContent = 'Results';
        document.getElementById('resultsMeta').textContent = list.length + ' match' + (list.length === 1 ? '' : 'es')
            + ' for "' + term + '" · ' + sortLabel(sortMode);
        renderList(document.getElementById('resultsList'), sorted(list), 'No products match your search.', 'fa-magnifying-glass');
    }

    // Every product, sorted — used when Sort is changed from Step 1 so the
    // choice always has a visible effect instead of silently doing nothing.
    function renderAllProducts() {
        document.getElementById('resultsEyebrow').textContent = 'Browse';
        document.getElementById('resultsTitle').textContent = 'All Products';
        document.getElementById('resultsMeta').textContent = products.length
            ? products.length + ' product' + (products.length === 1 ? '' : 's') + ' · sorted by ' + sortLabel(sortMode)
            : '';
        renderList(document.getElementById('resultsList'), sorted(products), 'No products available yet.', 'fa-bread-slice');
    }

    function variantChip(variant, index) {
        var stock = Number(variant.quantity || 0);
        return '' +
            '<button class="shop-variant-chip' + (index === 0 ? ' is-active' : '') + (stock < 5 ? ' is-low' : '') + '" type="button"' +
                ' role="radio" aria-checked="' + (index === 0 ? 'true' : 'false') + '"' +
                ' data-variant="' + variant.id + '" data-price="' + Number(variant.price) + '" data-stock="' + stock + '">' +
                '<span class="chip-size">' + escapeHtml(variant.service_size) + '</span>' +
                '<span class="chip-price">' + peso(variant.price) + '</span>' +
                '<span class="chip-stock">' + stock + ' left</span>' +
            '</button>';
    }

    function renderDetail(product) {
        detailState.product = product;
        detailState.quantity = 1;
        detailState.variant = product.variants[0];

        var stock = totalStock(product.variants);
        var from = minPrice(product);
        var detail = document.getElementById('productDetail');

        detail.innerHTML = '' +
            '<div class="shop-detail-media">' +
                '<img src="' + productPhoto(product) + '" alt="' + escapeHtml(product.name) + '" />' +
                heartButton(product, true) +
                (stock < 5 ? '<span class="shop-detail-flag"><i class="fa-solid fa-fire"></i> Only ' + stock + ' left</span>' : '') +
            '</div>' +
            '<div class="shop-detail-body">' +
                '<span class="shop-detail-category">' + escapeHtml(categoryName(product)) + '</span>' +
                '<h2>' + escapeHtml(product.name) + '</h2>' +
                '<p class="shop-detail-desc">' + escapeHtml(product.description || '') + '</p>' +

                '<div class="shop-detail-variants">' +
                    '<div class="shop-detail-variants-head">' +
                        '<span class="shop-detail-label"><i class="fa-solid fa-ruler-combined"></i> Service Size &amp; Variants</span>' +
                        '<span class="shop-detail-hint">Tap to choose</span>' +
                    '</div>' +
                    '<div class="shop-variant-chips" role="radiogroup" aria-label="Service size and variants">' +
                        product.variants.map(variantChip).join('') +
                    '</div>' +
                '</div>' +

                '<div class="shop-detail-buy">' +
                    '<div class="shop-detail-price">' +
                        '<span class="shop-detail-price-label">Price</span>' +
                        '<strong id="detailPrice">' + peso(from) + '</strong>' +
                    '</div>' +
                    '<div class="shop-qty" aria-label="Quantity">' +
                        '<button type="button" data-qty="-1" aria-label="Decrease quantity"><i class="fa-solid fa-minus"></i></button>' +
                        '<output id="detailQty">1</output>' +
                        '<button type="button" data-qty="1" aria-label="Increase quantity"><i class="fa-solid fa-plus"></i></button>' +
                    '</div>' +
                '</div>' +

                '<form method="POST" action="/BreadBreak/cart.php" class="shop-bag-form">' +
                    '<input type="hidden" name="action" value="add" />' +
                    '<input type="hidden" name="variant_id" id="detailVariant" value="' + detailState.variant.id + '" />' +
                    '<input type="hidden" name="quantity" id="detailQuantity" value="1" />' +
                    '<input type="hidden" name="next" value="cart" />' +
                    '<button class="shop-bag-button" type="submit"><i class="fa-solid fa-bag-shopping"></i> Add to My Bag</button>' +
                '</form>' +

                '<p class="shop-detail-note"><i class="fa-solid fa-truck-fast"></i> Store pickup or delivery available at checkout.</p>' +
            '</div>';
    }

    function openProduct(productId) {
        var product = products.find(function (entry) { return entry.id === productId; });
        if (!product) return;
        renderDetail(product);
        showLevel('detail');
        syncFilterUi();
        location.hash = '#/item/' + productId;
    }

    function openCategory(categoryId) {
        currentCategory = categoryId;
        renderProducts();
        showLevel('products');
        syncFilterUi();
        location.hash = '#/cat/' + categoryId;
    }

    function route() {
        var hash = location.hash || '';
        var itemMatch = hash.match(/^#\/item\/(\d+)$/);
        var categoryMatch = hash.match(/^#\/cat\/(\d+)$/);
        var searchValue = document.getElementById('productSearch').value.trim();

        if (searchValue) {
            renderResults(searchValue);
            showLevel('results');
        } else if (itemMatch) {
            var product = products.find(function (entry) { return entry.id === Number(itemMatch[1]); });
            if (product) { renderDetail(product); showLevel('detail'); }
            else { showLevel('categories'); }
        } else if (categoryMatch) {
            currentCategory = Number(categoryMatch[1]);
            renderProducts();
            showLevel('products');
        } else {
            showLevel('categories');
        }

        syncFilterUi();
    }

    // Keeps every piece of toolbar feedback in step with what is on screen:
    // live match counter, clear button, active menu chip, filter count, status bar.
    function syncFilterUi() {
        var searchValue = document.getElementById('productSearch').value.trim();
        var searchField = document.getElementById('shopSearch');
        var searchCount = document.getElementById('searchCount');
        var searchClear = document.getElementById('searchClear');
        var filterCount = document.getElementById('filterCount');
        var status = document.getElementById('filterStatus');
        if (!searchField || !status) return;

        searchField.classList.toggle('is-active', !!searchValue);
        if (searchClear) searchClear.hidden = !searchValue;
        if (searchCount) {
            searchCount.hidden = !searchValue;
            if (searchValue) {
                if (!products.length) searchCount.textContent = 'loading…';
                else {
                    var matches = matchingProducts(searchValue).length;
                    searchCount.textContent = matches + ' match' + (matches === 1 ? '' : 'es');
                }
            }
        }

        var chipWrap = document.getElementById('filterCategories');
        if (chipWrap) {
            chipWrap.querySelectorAll('[data-filter-category]').forEach(function (chip) {
                var isActive = !searchValue && Number(chip.dataset.filterCategory) === currentCategory;
                chip.classList.toggle('is-active', isActive);
                chip.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            });
        }

        var facets = [];
        if (searchValue) {
            facets.push({ type: 'search', label: 'Search: "' + searchValue + '"' });
        } else if (currentCategory) {
            var chosen = categories.find(function (entry) { return entry.id === currentCategory; });
            if (chosen) facets.push({ type: 'menu', label: 'Menu: ' + chosen.name });
        }
        if (sortMode !== 'name-asc') facets.push({ type: 'sort', label: 'Sort: ' + sortLabel(sortMode) });

        if (facets.length) {
            status.hidden = false;
            status.innerHTML = facets.map(function (facet) {
                return '<span class="shop-status-chip">' +
                    '<span>' + escapeHtml(facet.label) + '</span>' +
                    '<button type="button" data-clear-facet="' + facet.type + '" aria-label="Clear ' + facet.type + ' filter" title="Clear">' +
                        '<i class="fa-solid fa-xmark"></i>' +
                    '</button></span>';
            }).join('') + (facets.length > 1
                ? '<button type="button" class="shop-status-clear" data-clear-facet="all">Clear all</button>'
                : '');
        } else {
            status.hidden = true;
            status.innerHTML = '';
        }

        var activeCount = facets.length;
        if (filterCount) {
            filterCount.hidden = !activeCount;
            filterCount.textContent = activeCount;
        }
        var filterButton = document.getElementById('shopFilterToggle');
        if (filterButton) filterButton.classList.toggle('has-filters', !!activeCount);
    }

    function buildFilterChips() {
        var wrap = document.getElementById('filterCategories');
        wrap.innerHTML = categories.map(function (category) {
            return '<button class="shop-filter-chip" type="button" data-filter-category="' + category.id + '">' +
                escapeHtml(category.name) + ' <span>' + category.count + '</span></button>';
        }).join('');
        wrap.querySelectorAll('[data-filter-category]').forEach(function (chip) {
            chip.addEventListener('click', function () {
                document.getElementById('productSearch').value = '';
                document.getElementById('shopFilterPanel').hidden = true;
                document.getElementById('shopFilterToggle').setAttribute('aria-expanded', 'false');
                openCategory(Number(chip.dataset.filterCategory));
            });
        });
    }

    // ── Favorites (heart) ──────────────────────────────────────────────────────
    function syncFavoriteButtons(productId) {
        var isFavorite = favoriteIds.has(productId);
        document.querySelectorAll('[data-fav="' + productId + '"]').forEach(function (button) {
            button.classList.toggle('is-fav', isFavorite);
            button.setAttribute('aria-pressed', isFavorite ? 'true' : 'false');
            button.setAttribute('aria-label', (isFavorite ? 'Remove from' : 'Save to') + ' My Favorites');
            button.setAttribute('title', isFavorite ? 'Remove from My Favorites' : 'Save to My Favorites');
            var icon = button.querySelector('i');
            if (icon) icon.className = (isFavorite ? 'fa-solid' : 'fa-regular') + ' fa-heart';
        });
    }

    function toggleFavorite(button) {
        var productId = Number(button.dataset.fav);
        if (!productId) return;

        // Guests sign in first, then land straight on My Favorites.
        if (!signedIn) { window.location.href = '/BreadBreak/login.php?redirect=favorites'; return; }

        button.disabled = true;
        fetch('/BreadBreak/api/favorites.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: 'action=toggle&product_id=' + productId
        })
            .then(function (response) {
                return response.json().then(function (data) { return { status: response.status, data: data }; });
            })
            .then(function (result) {
                if (result.status === 401 || result.data.signed_in === false) {
                    window.location.href = '/BreadBreak/login.php?redirect=favorites';
                    return;
                }
                if (result.data.favorite === true) favoriteIds.add(productId);
                else favoriteIds.delete(productId);
                syncFavoriteButtons(productId);
                button.classList.add('is-pulse');
                window.setTimeout(function () { button.classList.remove('is-pulse'); }, 450);
            })
            .catch(function () {
                window.alert('We could not update your favorites just now. Please try again.');
            })
            .finally(function () { button.disabled = false; });
    }

    // ── Events ──
    document.addEventListener('click', function (event) {
        var facetClear = event.target.closest('[data-clear-facet]');
        if (facetClear) {
            var facet = facetClear.dataset.clearFacet;
            if (facet === 'search' || facet === 'all') document.getElementById('productSearch').value = '';
            if (facet === 'sort' || facet === 'all') {
                sortMode = 'name-asc';
                document.getElementById('sortProducts').value = 'name-asc';
            }
            if (facet === 'menu' || facet === 'all') {
                currentCategory = null;
                if (location.hash) location.hash = '';
            }
            route();
            return;
        }

        var tile = event.target.closest('.shop-category-tile');
        if (tile) { openCategory(Number(tile.dataset.category)); return; }

        var favoriteButton = event.target.closest('[data-fav]');
        if (favoriteButton) { toggleFavorite(favoriteButton); return; }

        var row = event.target.closest('.shop-product-row-main');
        if (row) { openProduct(Number(row.dataset.product)); return; }

        var back = event.target.closest('[data-go]');
        if (back) {
            var target = back.dataset.go;
            if (target === 'products' && currentCategory) { location.hash = '#/cat/' + currentCategory; }
            else { location.hash = ''; }
            return;
        }

        var chip = event.target.closest('.shop-variant-chip');
        if (chip && detailState.product) {
            document.querySelectorAll('.shop-variant-chip').forEach(function (entry) {
                entry.classList.toggle('is-active', entry === chip);
                entry.setAttribute('aria-checked', entry === chip ? 'true' : 'false');
            });
            detailState.variant = detailState.product.variants.find(function (variant) { return Number(variant.id) === Number(chip.dataset.variant); });
            if (!detailState.variant) return;
            document.getElementById('detailVariant').value = detailState.variant.id;
            document.getElementById('detailPrice').textContent = peso(detailState.variant.price);
            clampQuantity();
            return;
        }

        var qtyButton = event.target.closest('[data-qty]');
        if (qtyButton) {
            detailState.quantity += Number(qtyButton.dataset.qty);
            clampQuantity();
        }
    });

    function clampQuantity() {
        var max = detailState.variant ? Math.max(1, Number(detailState.variant.quantity || 1)) : 1;
        if (detailState.quantity < 1) detailState.quantity = 1;
        if (detailState.quantity > max) detailState.quantity = max;
        var output = document.getElementById('detailQty');
        var hidden = document.getElementById('detailQuantity');
        if (output) output.textContent = detailState.quantity;
        if (hidden) hidden.value = detailState.quantity;
        document.querySelectorAll('[data-qty="-1"]').forEach(function (button) { button.disabled = detailState.quantity <= 1; });
        document.querySelectorAll('[data-qty="1"]').forEach(function (button) { button.disabled = detailState.quantity >= max; });
    }

    var searchInput = document.getElementById('productSearch');
    searchInput.addEventListener('input', function () {
        window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(route, 140);
    });
    // The native clear (x) of type="search" fires "search", not "input".
    searchInput.addEventListener('search', function () {
        window.clearTimeout(searchTimer);
        route();
    });

    document.getElementById('searchClear').addEventListener('click', function () {
        window.clearTimeout(searchTimer);
        searchInput.value = '';
        searchInput.focus();
        route();
    });

    document.getElementById('shopFilterToggle').addEventListener('click', function () {
        var panel = document.getElementById('shopFilterPanel');
        panel.hidden = !panel.hidden;
        this.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true');
        if (!panel.hidden) {
            panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            syncFilterUi();
        }
    });

    document.getElementById('sortProducts').addEventListener('change', function () {
        sortMode = this.value;
        // On Step 1 there is no list to reorder — show every product sorted so
        // the choice is always visible instead of appearing to do nothing.
        if (!levels.categories.hidden) {
            renderAllProducts();
            showLevel('results');
            syncFilterUi();
            return;
        }
        route();
    });

    document.getElementById('filterReset').addEventListener('click', function () {
        sortMode = 'name-asc';
        document.getElementById('sortProducts').value = 'name-asc';
        document.getElementById('productSearch').value = '';
        currentCategory = null;
        if (location.hash) location.hash = '';
        route();
    });

    window.addEventListener('hashchange', route);

    // ── Load products, then honour whatever link brought us here ──
    fetch('/BreadBreak/api/products.php')
        .then(function (response) { return response.json(); })
        .then(function (data) {
            products = (data.products || []).filter(function (product) {
                return Array.isArray(product.variants) && product.variants.length > 0;
            });
            buildFilterChips();
            route(false);
        })
        .catch(function (error) {
            console.error('Unable to load products.', error);
            products = [];
            route(false);
        });

    // ── Saved hearts (never scrolls — it just refreshes the icons) ──
    fetch('/BreadBreak/api/favorites.php?action=list')
        .then(function (response) { return response.json(); })
        .then(function (data) {
            signedIn = data.signed_in !== false;
            favoriteIds = new Set((data.favorites || []).map(Number));
            if (products.length) route(false);
        })
        .catch(function () { /* keep hearts in their default state */ });

    route();
    buildFilterChips();
})();
</script>
