<?php
/**
 * Shop Promotions for the staff.
 *
 * The staff's promotion history lives here, kept apart from the submission
 * flow in staff/moments.php: this page only lists what has been sent to
 * BreadMoments, with a quick search and sort. Filtering happens in the
 * browser (staff.js) so typing never costs a round-trip.
 */
require_once __DIR__ . '/../includes/auth.php';
requireRole('staff');
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/moments.php';

$pdo = momentDb();
$pageTitle = 'Shop Promotions';
$activePage = 'promotions';

$staffId = (int) ($_SESSION['user_id'] ?? 0);

/* ── Everything this staff member submitted ─────────────────────────────── */
$myPromos = [];
if ($pdo) {
    $myPromos = $pdo->query("
        SELECT m.id, m.title, m.body, m.created_at, m.moderated_at, m.moderation_status, m.moderation_reason,
               pi.name AS product_name,
               (SELECT p.id FROM bread_moment_photos p WHERE p.moment_id = m.id ORDER BY p.position ASC, p.id ASC LIMIT 1) AS photo_id
        FROM bread_moments m
        LEFT JOIN inventory_items pi ON pi.id = m.product_id
        WHERE m.user_id = {$staffId} AND m.post_type = 'promotion'
        ORDER BY m.created_at DESC
    ")->fetchAll();
}

/* ── Quick counts for the summary cards ─────────────────────────────────── */
$promoStats = ['total' => count($myPromos), 'live' => 0, 'pending' => 0, 'rejected' => 0];
foreach ($myPromos as $promoRow) {
    $promoStatus = (string) $promoRow['moderation_status'];
    if ($promoStatus === 'visible') {
        $promoStats['live']++;
    } elseif ($promoStatus === 'pending') {
        $promoStats['pending']++;
    } else {
        $promoStats['rejected']++;
    }
}

require __DIR__ . '/../includes/staff_header.php';
?>
<div class="staff-promos shop-promotions">
<!-- ── Hero ── -->
<section class="welcome-block dashboard-welcome staff-hero">
    <div>
        <span class="staff-dash-kicker"><i class="fa-solid fa-shop"></i> Shop Promotions · Your campaigns</span>
        <h2>Your shop promotions</h2>
        <p>Everything you have sent to BreadMoments and where it stands today — live, in review, or waiting for a revision.</p>
    </div>
    <div class="hero-chips">
        <span class="dashboard-date"><i class="fa-solid fa-circle-check"></i> <?php echo $promoStats['live']; ?> live</span>
        <a class="dashboard-date dashboard-date-link" href="<?php echo BASE_URL; ?>/staff/moments.php">
            <i class="fa-solid fa-plus"></i> New promotion <i class="fa-solid fa-arrow-right"></i>
        </a>
    </div>
</section>

<!-- ── Promotion summary ── -->
<section class="summary-grid staff-summary-grid moments-stats" aria-label="Promotion summary">
    <article class="summary-card staff-stat is-brown">
        <div class="summary-top">
            <span>Total Promotions</span>
            <span class="summary-icon"><i class="fa-solid fa-bullhorn"></i></span>
        </div>
        <div class="summary-value"><?php echo $promoStats['total']; ?></div>
        <small class="staff-stat-note">Everything you have submitted</small>
    </article>
    <article class="summary-card staff-stat is-green">
        <div class="summary-top">
            <span>Live</span>
            <span class="summary-icon"><i class="fa-solid fa-circle-check"></i></span>
        </div>
        <div class="summary-value"><?php echo $promoStats['live']; ?></div>
        <small class="staff-stat-note">Published in BreadMoments</small>
    </article>
    <article class="summary-card staff-stat is-orange">
        <div class="summary-top">
            <span>In Review</span>
            <span class="summary-icon"><i class="fa-solid fa-hourglass-half"></i></span>
        </div>
        <div class="summary-value"><?php echo $promoStats['pending']; ?></div>
        <small class="staff-stat-note">Waiting for the admin</small>
    </article>
    <article class="summary-card staff-stat is-red">
        <div class="summary-top">
            <span>Needs Revision</span>
            <span class="summary-icon"><i class="fa-solid fa-rotate-left"></i></span>
        </div>
        <div class="summary-value"><?php echo $promoStats['rejected']; ?></div>
        <small class="staff-stat-note">Revise and resubmit</small>
    </article>
</section>

<!-- ── The list ── -->
<section class="panel moments-panel">
    <div class="panel-heading moments-heading">
        <div class="moments-heading-copy">
            <span class="moments-heading-icon"><i class="fa-solid fa-layer-group"></i></span>
            <div>
                <h3>My promotions</h3>
                <p class="panel-subtitle">Everything you submitted, with the current review status.</p>
            </div>
        </div>
    </div>

    <?php if (!$myPromos): ?>
        <div class="empty-state">
            <i class="fa-solid fa-bullhorn" aria-hidden="true"></i>
            <p>You have not submitted a promotion yet — send your first one to BreadMoments.</p>
            <a class="admin-button primary" href="<?php echo BASE_URL; ?>/staff/moments.php"><i class="fa-solid fa-plus"></i> Create a promotion</a>
        </div>
    <?php else: ?>
        <div class="promo-toolbar">
            <div class="promo-search">
                <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                <input type="search" id="promo-search" placeholder="Search title or product…" aria-label="Search promotions" data-promo-search />
            </div>
            <label class="promo-sort" for="promo-sort">
                <span>Sort</span>
                <select id="promo-sort" data-promo-sort>
                    <option value="newest">Newest first</option>
                    <option value="oldest">Oldest first</option>
                    <option value="status">Status</option>
                    <option value="title">Title A–Z</option>
                </select>
            </label>
            <span class="promo-results" data-promo-results aria-live="polite"></span>
        </div>

        <div class="promo-list" data-promo-list>
            <?php foreach ($myPromos as $row): ?>
            <?php [$pillLabel, $pillClass] = momentStatusLabel((string) $row['moderation_status']); ?>
            <article class="mod-card is-<?php echo $pillClass; ?>"
                     data-title="<?php echo htmlspecialchars($row['title']); ?>"
                     data-product="<?php echo htmlspecialchars((string) ($row['product_name'] ?? '')); ?>"
                     data-status="<?php echo $pillClass; ?>"
                     data-time="<?php echo (int) strtotime($row['created_at']); ?>">
                <span class="mod-thumb">
                    <?php if ($row['photo_id']): ?>
                    <img src="/BreadBreak/api/moment-image.php?id=<?php echo (int) $row['photo_id']; ?>" alt="" loading="lazy" />
                    <?php else: ?>
                    <i class="fa-solid fa-bullhorn" aria-hidden="true"></i>
                    <?php endif; ?>
                </span>
                <div class="mod-body">
                    <div class="mod-head">
                        <strong class="mod-title"><?php echo htmlspecialchars($row['title']); ?></strong>
                        <span class="mod-status promo">Promotion</span>
                        <span class="mod-status <?php echo $pillClass; ?>"><?php echo $pillLabel; ?></span>
                    </div>
                    <p class="mod-meta">
                        <?php if (!empty($row['product_name'])): ?>
                        <i class="fa-solid fa-bread-slice"></i> <?php echo htmlspecialchars($row['product_name']); ?> ·
                        <?php endif; ?>
                        <i class="fa-solid fa-clock"></i> submitted <?php echo htmlspecialchars(momentTimeAgo($row['created_at'])); ?>
                    </p>
                    <?php if (!empty($row['moderation_reason'])): ?>
                    <p class="mod-feedback <?php echo $row['moderation_status'] === 'pending' ? 'is-old' : ''; ?>">
                        <i class="fa-solid fa-message"></i>
                        <?php echo $row['moderation_status'] === 'pending' ? 'Your previous note from the admin:' : 'Admin’s message:'; ?>
                        <?php echo htmlspecialchars($row['moderation_reason']); ?>
                    </p>
                    <?php endif; ?>
                </div>
                <div class="mod-actions">
                    <?php if ($row['moderation_status'] === 'visible'): ?>
                    <a class="admin-button secondary" href="<?php echo BASE_URL; ?>/moment.php?id=<?php echo (int) $row['id']; ?>">
                        <i class="fa-solid fa-arrow-up-right-from-square"></i> View live post
                    </a>
                    <?php else: ?>
                    <a class="admin-button primary" href="<?php echo BASE_URL; ?>/staff/moments.php?edit=<?php echo (int) $row['id']; ?>">
                        <i class="fa-solid fa-pen"></i> Edit &amp; resubmit
                    </a>
                    <?php endif; ?>
                </div>
            </article>
            <?php endforeach; ?>

            <p class="promo-noresults" data-promo-noresults hidden>
                <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                No promotion matches your search. <button type="button" data-promo-clear>Clear search</button>
            </p>
        </div>
    <?php endif; ?>
</section>
</div>

<?php require __DIR__ . '/../includes/staff_footer.php'; ?>
