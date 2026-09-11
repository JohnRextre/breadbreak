<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (($_SESSION['role'] ?? '') === 'customer' && !defined('CUSTOMER_MENU_DASHBOARD')) {
    header('Location: /BreadBreak/customer/menu_dashboard.php');
    exit;
}
$pageTitle = 'Menu | BreadBreak | Bakery Ordering and Inventory Management System';
$isCustomerDashboard = session_status() === PHP_SESSION_ACTIVE && (($_SESSION['role'] ?? '') === 'customer');
?>

<?php include __DIR__ . '/includes/header.php'; ?>

<main>
    <?php if (!$isCustomerDashboard): ?><section class="breadcrumb-section">
        <div class="container">
            <nav class="breadcrumb" aria-label="Breadcrumb">
                <a href="/BreadBreak/index.php">Home</a>
                <i class="fa-solid fa-chevron-right"></i>
                <span>Menu</span>
            </nav>
        </div>
    </section><?php endif; ?>

    <section class="catalog-section section">
        <div class="container catalog-layout">
            <aside class="catalog-sidebar">
                <div class="catalog-sidebar-heading"><span class="eyebrow">Shop</span><h2>Menu Category</h2></div>
                <nav class="catalog-categories" aria-label="Product categories">
                    <button class="catalog-category active" data-filter="all" type="button">All Items</button>
                    <button class="catalog-category" data-filter="bite-sized-breads" type="button">Bite-sized Breads</button>
                    <button class="catalog-category" data-filter="big-breads" type="button">Big Breads</button>
                    <button class="catalog-category" data-filter="palm-sized-cookies" type="button">Palm-sized Cookies</button>
                    <button class="catalog-category" data-filter="cookies" type="button">Cookies</button>
                    <button class="catalog-category" data-filter="pastries" type="button">Pastries</button>
                    <button class="catalog-category" data-filter="crinkles" type="button">Crinkles</button>
                    <button class="catalog-category" data-filter="decadent-cakes" type="button">Decadent Cakes</button>
                    <button class="catalog-category" data-filter="round-cakes" type="button">Round Cakes</button>
                </nav>
            </aside>
            <div class="catalog-content">
                <div class="catalog-heading"><div><span class="eyebrow">Fresh from the oven</span><h2>Browse Products</h2><p>Choose a service size, then add your favorites to your cart.</p></div><a class="catalog-cart-link" href="/BreadBreak/cart.php"><i class="fa-solid fa-cart-shopping"></i> View Cart</a></div>
                <div class="catalog-toolbar"><div class="catalog-search"><i class="fa-solid fa-magnifying-glass"></i><label class="sr-only" for="productSearch">Search products</label><input type="search" id="productSearch" class="search-input" placeholder="Search products..." /></div><label class="catalog-select">Sort by<select id="sortProducts"><option value="name-asc">Name (A-Z)</option><option value="name-desc">Name (Z-A)</option><option value="price-asc">Price (Low to High)</option><option value="price-desc">Price (High to Low)</option></select></label><label class="catalog-select">Show<select id="showProducts"><option value="10">10</option><option value="25">25</option><option value="50">50</option><option value="all">All</option></select></label><div class="catalog-view-toggle" role="group" aria-label="Product view"><button class="catalog-view-button" type="button" data-catalog-view="list" aria-label="List view" title="List view"><i class="fa-solid fa-list"></i></button><button class="catalog-view-button is-active" type="button" data-catalog-view="grid" aria-label="Grid view" title="Grid view"><i class="fa-solid fa-table-cells-large"></i></button></div></div>
                <div class="catalog-results-meta" id="catalogResultsMeta">Loading products...</div>
                <div class="product-grid catalog-product-grid" id="productsGrid"><div class="loading-placeholder"><p>Loading products...</p></div></div>
                <div id="noResults" class="no-results" style="display: none;"><p>No products found. Try adjusting your search or category.</p></div>
            </div>
        </div>
    </section>
</main>

<style>
.breadcrumb-section {
    padding: 1.5rem 0;
    background: var(--cream);
    border-bottom: 1px solid var(--border);
}

.breadcrumb {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.95rem;
    color: var(--muted);
}

.breadcrumb a {
    color: var(--accent);
    font-weight: 500;
    transition: color 0.2s ease;
}

.breadcrumb a:hover {
    color: var(--brown-700);
}

.breadcrumb i {
    font-size: 0.7rem;
    color: var(--muted);
}

.menu-hero {
    background: linear-gradient(135deg, var(--cream) 0%, var(--paper) 100%);
}

.search-section {
    padding: 2rem 0;
    background: var(--cream);
    border-bottom: 1px solid var(--border);
}

.search-box {
    position: relative;
    display: flex;
    align-items: center;
}

.search-box i {
    position: absolute;
    left: 1rem;
    color: var(--muted);
    pointer-events: none;
}

.search-input {
    width: 100%;
    max-width: 500px;
    padding: 0.75rem 1rem 0.75rem 2.8rem;
    border: 1px solid var(--border);
    border-radius: 12px;
    background: white;
    font-size: 1rem;
    font-family: inherit;
    color: var(--text);
    transition: border-color 0.2s ease;
}

.search-input:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(201, 121, 64, 0.1);
}

.categories-nav {
    padding: 2rem 0;
    background: white;
    border-bottom: 1px solid var(--border);
}

.category-filters {
    display: flex;
    flex-wrap: wrap;
    gap: 0.8rem;
    justify-content: center;
}

.filter-btn {
    padding: 0.65rem 1.2rem;
    border: 1px solid var(--border);
    background: white;
    color: var(--text);
    border-radius: 20px;
    font-size: 0.95rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s ease;
}

.filter-btn:hover {
    border-color: var(--accent);
    color: var(--accent);
}

.filter-btn.active {
    background: var(--accent);
    color: white;
    border-color: var(--accent);
}

.loading-placeholder,
.no-results {
    grid-column: 1 / -1;
    text-align: center;
    padding: 3rem 1rem;
    color: var(--muted);
}

.no-results p {
    font-size: 1.1rem;
    margin: 0;
}

.product-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 2rem;
    margin-top: 2rem;
}

@media (max-width: 768px) {
    .product-grid {
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        gap: 1.5rem;
    }

    .category-filters {
        justify-content: flex-start;
        overflow-x: auto;
        padding-bottom: 0.5rem;
    }

    .search-input {
        max-width: 100%;
    }
}

@media (max-width: 480px) {
    .product-grid {
        grid-template-columns: 1fr;
    }

    .breadcrumb {
        font-size: 0.85rem;
    }
}

.product-card {
    background: white;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: var(--shadow-soft);
    transition: all 0.3s ease;
    display: flex;
    flex-direction: column;
}

.product-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-mid);
}

.product-image {
    width: 100%;
    aspect-ratio: 1;
    background: var(--paper);
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
}

.product-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.product-content {
    padding: 1.2rem;
    flex-grow: 1;
    display: flex;
    flex-direction: column;
}

.product-content h3 {
    font-size: 1.1rem;
    margin: 0 0 0.3rem 0;
    color: var(--brown-900);
}

.product-category {
    font-size: 0.8rem;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-bottom: 0.5rem;
}

.product-description {
    font-size: 0.9rem;
    color: var(--muted);
    margin: 0 0 0.8rem 0;
    flex-grow: 1;
}

.product-meta {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.8rem;
    padding-top: 0.8rem;
    border-top: 1px solid var(--border);
}

.price {
    font-size: 1.2rem;
    font-weight: 700;
    color: var(--accent);
}

.price-range {
    font-size: 0.9rem;
    color: var(--muted);
}

.variants-count {
    font-size: 0.85rem;
    color: var(--muted);
}

.btn-view-item {
    padding: 0.6rem 1rem;
    background: var(--accent);
    color: white;
    border: none;
    border-radius: 8px;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.btn-view-item:hover {
    background: var(--brown-700);
}

.out-of-stock {
    background: var(--muted);
    color: white;
    cursor: not-allowed;
    opacity: 0.6;
}

.out-of-stock:hover {
    background: var(--muted);
}
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>

<script>
// Product data will be fetched from the API/database
let allProducts = [];
let currentFilter = 'all';
let currentView = 'grid';

// Initialize menu on page load
document.addEventListener('DOMContentLoaded', function() {
    loadProducts();
    attachEventListeners();
});

// Attach event listeners to filter buttons and search
function attachEventListeners() {
    // Filter buttons
    document.querySelectorAll('.catalog-category').forEach(btn => {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.catalog-category').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            currentFilter = this.dataset.filter;
            filterProducts();
        });
    });

    // Search input
    const searchInput = document.getElementById('productSearch');
    if (searchInput) {
        searchInput.addEventListener('input', filterProducts);
    }
    document.getElementById('sortProducts').addEventListener('change', filterProducts);
    document.getElementById('showProducts').addEventListener('change', filterProducts);
    document.querySelectorAll('[data-catalog-view]').forEach(function (button) { button.addEventListener('click', function () { currentView = button.dataset.catalogView; document.querySelectorAll('[data-catalog-view]').forEach(function (toggle) { toggle.classList.toggle('is-active', toggle.dataset.catalogView === currentView); }); document.getElementById('productsGrid').classList.toggle('list-view', currentView === 'list'); }); });
}

// Load products from database
function loadProducts() {
    // For now, fetch from a simplified endpoint
    // This will be integrated with your actual database query
    fetch('/BreadBreak/api/products.php')
        .then(response => response.json())
        .then(data => {
            allProducts = data.products || [];
            filterProducts();
        })
        .catch(error => {
            console.error('Unable to load products.', error);
            allProducts = [];
            displayProducts(allProducts);
        });
}

// Load placeholder products for demonstration
function loadPlaceholderProducts() {
    allProducts = [
        {
            id: 1,
            name: 'Classic Pandesal',
            category: 'bite-sized-breads',
            description: 'Soft, warm, and perfectly golden for everyday breakfasts.',
            price: 50,
            variants: 1,
            photo: null
        },
        {
            id: 2,
            name: 'Cheese Bread',
            category: 'big-breads',
            description: 'Buttery bread with a rich, savory cheese finish.',
            price: 85,
            variants: 1,
            photo: null
        },
        {
            id: 3,
            name: 'Chocolate Cake',
            category: 'decadent-cakes',
            description: 'Moist cakes layered with indulgent chocolate goodness.',
            price: 450,
            variants: 3,
            photo: '/BreadBreak/assets/breadbreak_png/SliceCake.png'
        },
        {
            id: 4,
            name: 'Chocolate Chip Cookies',
            category: 'cookies',
            description: 'Crisp edges, chewy centers, and loaded with chocolate chips.',
            price: 120,
            variants: 1,
            photo: '/BreadBreak/assets/breadbreak_png/cookies.png'
        },
        {
            id: 5,
            name: 'Brownies',
            category: 'cookies',
            description: 'Rich, dense, and decadent with every bite.',
            price: 150,
            variants: 2,
            photo: '/BreadBreak/assets/breadbreak_png/Brownies.png'
        },
        {
            id: 6,
            name: 'Pastry Platter',
            category: 'pastries',
            description: 'Assorted pastries with fresh cream and fruit fillings.',
            price: 200,
            variants: 1,
            photo: '/BreadBreak/assets/breadbreak_png/Pastry.png'
        },
        {
            id: 7,
            name: 'Cream Cheese Garlic Bites',
            category: 'bite-sized-breads',
            description: 'Savory bites with cream cheese and aromatic garlic.',
            price: 75,
            variants: 1,
            photo: '/BreadBreak/assets/breadbreak_png/CreamCheeseGarlicBites.png'
        }
    ];
    displayProducts(allProducts);
}

// Filter and search products
function filterProducts() {
    const searchTerm = document.getElementById('productSearch').value.toLowerCase().trim();
    let filtered = allProducts.filter(product => {
        const matchCategory = currentFilter === 'all' || product.category === currentFilter;
        const matchSearch = product.name.toLowerCase().includes(searchTerm) || 
                           product.description.toLowerCase().includes(searchTerm);
        return matchCategory && matchSearch;
    });
    const sort = document.getElementById('sortProducts').value;
    filtered.sort(function (left, right) {
        if (sort === 'price-asc' || sort === 'price-desc') { const difference = Number(left.variants[0].price) - Number(right.variants[0].price); return sort === 'price-asc' ? difference : -difference; }
        const comparison = left.name.localeCompare(right.name);
        return sort === 'name-desc' ? -comparison : comparison;
    });
    const show = document.getElementById('showProducts').value;
    displayProducts(show === 'all' ? filtered : filtered.slice(0, Number(show)), filtered.length);
}

function escapeHtml(value) {
    return String(value).replace(/[&<>'"]/g, function (character) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' })[character]; });
}

function productCard(product) {
    const photoUrl = product.photo || '/BreadBreak/assets/breadbreak_png/breadbreak_logo.png';
    const variants = product.variants || [];
    const options = variants.map(function (variant) { return '<option value="' + variant.id + '">' + escapeHtml(variant.service_size) + ' · ₱' + Number(variant.price).toFixed(2) + ' (' + variant.quantity + ' left)</option>'; }).join('');
    return '<article class="product-card"><div class="product-image"><img src="' + escapeHtml(photoUrl) + '" alt="' + escapeHtml(product.name) + '" /></div><div class="product-content"><span class="product-category">' + escapeHtml(product.categoryName || product.category.replace(/-/g, ' ')) + '</span><h3>' + escapeHtml(product.name) + '</h3><p class="product-description">' + escapeHtml(product.description) + '</p><div class="product-meta"><div><div class="price">₱' + Number(variants[0].price).toFixed(2) + '</div><div class="variants-count">' + variants.length + ' service size' + (variants.length === 1 ? '' : 's') + '</div></div></div><div class="product-cart-controls"><label class="sr-only" for="variant-' + product.id + '">Choose service size</label><select id="variant-' + product.id + '" class="variant-select">' + options + '</select><form method="POST" action="/BreadBreak/cart.php"><input type="hidden" name="action" value="add" /><input class="selected-variant" type="hidden" name="variant_id" value="' + variants[0].id + '" /><button class="add-to-cart" type="submit"><i class="fa-solid fa-cart-plus"></i> Add to Cart</button></form></div></div></article>';
}

// Display products in grid
function displayProducts(products, totalResults) {
    const grid = document.getElementById('productsGrid');
    const noResults = document.getElementById('noResults');
    
    if (!grid) return;

    if (products.length === 0) {
        grid.innerHTML = '';
        noResults.style.display = 'block';
        document.getElementById('catalogResultsMeta').textContent = '0 products';
        return;
    }

    noResults.style.display = 'none';
    
    grid.classList.toggle('list-view', currentView === 'list');
    grid.innerHTML = products.map(productCard).join('');
    document.getElementById('catalogResultsMeta').textContent = 'Showing ' + products.length + ' of ' + (totalResults || products.length) + ' products';
    grid.querySelectorAll('.variant-select').forEach(function (select) { select.addEventListener('change', function () { select.closest('.product-cart-controls').querySelector('.selected-variant').value = select.value; }); });

}
</script>
