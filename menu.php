<?php
$pageTitle = 'Menu | BreadBreak | Bakery Ordering and Inventory Management System';
?>

<?php include __DIR__ . '/includes/header.php'; ?>

<main>
    <!-- Breadcrumb -->
    <section class="breadcrumb-section">
        <div class="container">
            <nav class="breadcrumb" aria-label="Breadcrumb">
                <a href="/BreadBreak/index.php">Home</a>
                <i class="fa-solid fa-chevron-right"></i>
                <span>Menu</span>
            </nav>
        </div>
    </section>

    <!-- Page Hero / Introduction -->
    <section class="menu-hero section">
        <div class="container">
            <div class="section-heading center">
                <span class="eyebrow">Explore Our Menu</span>
                <h1>Explore Our Menu</h1>
                <p>Find your favorite freshly baked treats.</p>
            </div>
        </div>
    </section>

    <!-- Search Bar -->
    <section class="search-section">
        <div class="container">
            <div class="search-box">
                <i class="fa-solid fa-search"></i>
                <input 
                    type="text" 
                    id="productSearch" 
                    class="search-input" 
                    placeholder="Search bakery items..." 
                    aria-label="Search products"
                />
            </div>
        </div>
    </section>

    <!-- Category Navigation -->
    <section class="categories-nav section">
        <div class="container">
            <div class="category-filters">
                <button class="filter-btn active" data-filter="all">All Items</button>
                <button class="filter-btn" data-filter="bite-sized-breads">Bite-sized Breads</button>
                <button class="filter-btn" data-filter="big-breads">Big Breads</button>
                <button class="filter-btn" data-filter="palm-sized-cookies">Palm-sized Cookies</button>
                <button class="filter-btn" data-filter="cookies">Cookies</button>
                <button class="filter-btn" data-filter="pastries">Pastries</button>
                <button class="filter-btn" data-filter="crinkles">Crinkles</button>
                <button class="filter-btn" data-filter="decadent-cakes">Decadent Cakes</button>
                <button class="filter-btn" data-filter="round-cakes">Round Cakes</button>
            </div>
        </div>
    </section>

    <!-- Best Sellers Section -->
    <section class="featured section">
        <div class="container">
            <div class="section-heading center">
                <span class="eyebrow">Our Favorites</span>
                <h2>Our Best Sellers</h2>
                <p>Customer favorites, freshly baked for you.</p>
            </div>

            <div class="product-grid" id="bestSellersGrid">
                <!-- Best sellers will be populated by JavaScript from database -->
                <div class="loading-placeholder">
                    <p>Loading best sellers...</p>
                </div>
            </div>
        </div>
    </section>

    <!-- All Products Section -->
    <section class="all-products section alt-bg">
        <div class="container">
            <div class="section-heading center">
                <h2>Browse All Products</h2>
            </div>

            <div class="product-grid" id="productsGrid">
                <!-- Products will be populated by JavaScript from database -->
                <div class="loading-placeholder">
                    <p>Loading products...</p>
                </div>
            </div>

            <div id="noResults" class="no-results" style="display: none;">
                <p>No products found. Try adjusting your search or filter.</p>
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

// Initialize menu on page load
document.addEventListener('DOMContentLoaded', function() {
    loadProducts();
    attachEventListeners();
});

// Attach event listeners to filter buttons and search
function attachEventListeners() {
    // Filter buttons
    document.querySelectorAll('.filter-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            // Update active button
            document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
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
}

// Load products from database
function loadProducts() {
    // For now, fetch from a simplified endpoint
    // This will be integrated with your actual database query
    fetch('/BreadBreak/api/products.php')
        .then(response => response.json())
        .then(data => {
            allProducts = data.products || [];
            displayProducts(allProducts);
        })
        .catch(error => {
            console.log('Note: Using placeholder products. API endpoint not yet configured.');
            loadPlaceholderProducts();
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
    const searchTerm = document.getElementById('productSearch').value.toLowerCase();
    
    let filtered = allProducts.filter(product => {
        const matchCategory = currentFilter === 'all' || product.category === currentFilter;
        const matchSearch = product.name.toLowerCase().includes(searchTerm) || 
                           product.description.toLowerCase().includes(searchTerm);
        return matchCategory && matchSearch;
    });

    displayProducts(filtered);
}

// Display products in grid
function displayProducts(products) {
    const grid = document.getElementById('productsGrid');
    const noResults = document.getElementById('noResults');
    
    if (!grid) return;

    if (products.length === 0) {
        grid.innerHTML = '';
        noResults.style.display = 'block';
        return;
    }

    noResults.style.display = 'none';
    
    grid.innerHTML = products.map(product => {
        const photoUrl = product.photo || '/BreadBreak/assets/breadbreak_png/breadbreak_logo.png';
        const priceDisplay = product.price ? `₱${product.price.toFixed(2)}` : 'Price TBD';
        const variantsText = product.variants > 1 ? `${product.variants} sizes` : '1 size';
        
        return `
            <article class="product-card" data-product-id="${product.id}">
                <div class="product-image">
                    <img src="${photoUrl}" alt="${product.name}" />
                </div>
                <div class="product-content">
                    <span class="product-category">${product.category.replace(/-/g, ' ')}</span>
                    <h3>${product.name}</h3>
                    <p class="product-description">${product.description}</p>
                    <div class="product-meta">
                        <div>
                            <div class="price">${priceDisplay}</div>
                            <div class="variants-count">${variantsText}</div>
                        </div>
                        <a href="#" class="btn-view-item">View Item</a>
                    </div>
                </div>
            </article>
        `;
    }).join('');

    // Update best sellers (first 6 products are best sellers)
    const bestSellersGrid = document.getElementById('bestSellersGrid');
    if (bestSellersGrid && currentFilter === 'all') {
        bestSellersGrid.innerHTML = products.slice(0, 6).map(product => {
            const photoUrl = product.photo || '/BreadBreak/assets/breadbreak_png/breadbreak_logo.png';
            const priceDisplay = product.price ? `₱${product.price.toFixed(2)}` : 'Price TBD';
            const variantsText = product.variants > 1 ? `${product.variants} sizes` : '1 size';
            
            return `
                <article class="product-card" data-product-id="${product.id}">
                    <div class="product-image">
                        <img src="${photoUrl}" alt="${product.name}" />
                    </div>
                    <div class="product-content">
                        <span class="product-category">${product.category.replace(/-/g, ' ')}</span>
                        <h3>${product.name}</h3>
                        <p class="product-description">${product.description}</p>
                        <div class="product-meta">
                            <div>
                                <div class="price">${priceDisplay}</div>
                                <div class="variants-count">${variantsText}</div>
                            </div>
                            <a href="#" class="btn-view-item">View Item</a>
                        </div>
                    </div>
                </article>
            `;
        }).join('');
    }
}
</script>
