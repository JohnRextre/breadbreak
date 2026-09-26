<?php
/**
 * BreadMoments for the staff — store promotions.
 *
 * The staff picks a product, uploads a photo and writes a caption; the
 * promotion goes to the admin for review first. Approved promotions go live
 * with a "Promotion / Posted by store" identity, rejected ones come back with
 * the admin's reason so the staff can revise and resubmit.
 */
require_once __DIR__ . '/../includes/auth.php';
requireRole('staff');
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/moments.php';

$pdo = momentDb();
$pageTitle = 'BreadMoments';
$activePage = 'moments';

$staffId = (int) ($_SESSION['user_id'] ?? 0);

$notice = (string) ($_SESSION['staff_moments_notice'] ?? '');
unset($_SESSION['staff_moments_notice']);

$errors = [];
$old = ['product_id' => '', 'title' => '', 'body' => ''];
$editRow = null;
$action = '';

/* ── Submit / resubmit a promotion (validation failures re-render) ───── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    $action   = (string) ($_POST['action'] ?? '');
    $momentId = (int) ($_POST['moment_id'] ?? 0);
    $old['product_id'] = (string) (int) ($_POST['product_id'] ?? 0);
    $old['title'] = trim((string) ($_POST['title'] ?? ''));
    $old['body']  = trim((string) ($_POST['body'] ?? ''));

    if (!in_array($action, ['create_promo', 'edit_promo'], true)) {
        $_SESSION['staff_moments_notice'] = 'Error: unknown action.';
        header('Location: ' . BASE_URL . '/staff/moments.php');
        exit;
    }

    // The product anchors the promotion — and gives it its menu category.
    $productStatement = $pdo->prepare('SELECT id, category_id, name FROM inventory_items WHERE id = :id LIMIT 1');
    $productStatement->execute(['id' => (int) $old['product_id']]);
    $product = $productStatement->fetch() ?: null;
    if (!$product) {
        $errors['product_id'] = 'Select the product you want to promote.';
    }

    if ($old['title'] === '') {
        $errors['title'] = 'Give the promotion a title.';
    } elseif (mb_strlen($old['title']) > 140) {
        $errors['title'] = 'Keep the title under 140 characters.';
    }

    if ($old['body'] === '') {
        $errors['body'] = 'Write a short caption for the promotion.';
    } elseif (mb_strlen($old['body']) < 10) {
        $errors['body'] = 'A few more details, please (at least 10 characters).';
    } elseif (mb_strlen($old['body']) > 2000) {
        $errors['body'] = 'Please keep it under 2,000 characters.';
    }

    $hasExistingPhoto = false;
    if ($action === 'edit_promo') {
        $own = $pdo->prepare("SELECT id FROM bread_moments WHERE id = :id AND user_id = :user_id AND post_type = 'promotion' LIMIT 1");
        $own->execute(['id' => $momentId, 'user_id' => $staffId]);
        if (!$own->fetch()) {
            $_SESSION['staff_moments_notice'] = 'Error: you can only revise your own promotions.';
            header('Location: ' . BASE_URL . '/staff/moments.php');
            exit;
        }
        $existingPhoto = $pdo->prepare('SELECT COUNT(*) FROM bread_moment_photos WHERE moment_id = :id');
        $existingPhoto->execute(['id' => $momentId]);
        $hasExistingPhoto = (bool) $existingPhoto->fetchColumn();
    } else {
        $momentId = 0;
    }

    // Photo — JPG / PNG / WEBP, up to 8MB, finfo-validated.
    $photo = null;
    if (!empty($_FILES['photo']['name']) && is_uploaded_file($_FILES['photo']['tmp_name'] ?? '')) {
        if ((int) ($_FILES['photo']['size'] ?? 0) > MOMENT_MAX_BYTES) {
            $errors['photo'] = 'The photo must be 8MB or smaller.';
        } else {
            try {
                $photoMime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['photo']['tmp_name']);
            } catch (Throwable $photoProbe) {
                $photoMime = '';
            }
            if (!in_array($photoMime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
                $errors['photo'] = 'Use a JPG, PNG or WEBP photo.';
            } else {
                $photo = ['data' => file_get_contents($_FILES['photo']['tmp_name']), 'mime' => $photoMime];
            }
        }
    }
    if (!$photo && !$hasExistingPhoto && !isset($errors['photo'])) {
        $errors['photo'] = 'Add a photo for the promotion.';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            if ($action === 'create_promo') {
                $insert = $pdo->prepare(
                    "INSERT INTO bread_moments (user_id, category_id, title, body, topics, post_type, product_id, moderation_status)
                     VALUES (:user_id, :category_id, :title, :body, '', 'promotion', :product_id, 'pending')"
                );
                $insert->execute([
                    'user_id'     => $staffId,
                    'category_id' => (int) $product['category_id'],
                    'title'       => $old['title'],
                    'body'        => $old['body'],
                    'product_id'  => (int) $product['id'],
                ]);
                $momentId = (int) $pdo->lastInsertId();
            } else {
                $update = $pdo->prepare(
                    "UPDATE bread_moments
                     SET category_id = :category_id, title = :title, body = :body,
                         product_id = :product_id, moderation_status = 'pending'
                     WHERE id = :id AND user_id = :user_id AND post_type = 'promotion'"
                );
                $update->execute([
                    'category_id' => (int) $product['category_id'],
                    'title'       => $old['title'],
                    'body'        => $old['body'],
                    'product_id'  => (int) $product['id'],
                    'id'          => $momentId,
                    'user_id'     => $staffId,
                ]);
                if ($photo) {
                    $pdo->prepare('DELETE FROM bread_moment_photos WHERE moment_id = :id')->execute(['id' => $momentId]);
                }
            }

            if ($photo) {
                $photoInsert = $pdo->prepare(
                    'INSERT INTO bread_moment_photos (moment_id, photo_data, photo_mime, position) VALUES (:moment_id, :data, :mime, 0)'
                );
                $photoInsert->execute(['moment_id' => $momentId, 'data' => $photo['data'], 'mime' => $photo['mime']]);
            }

            $pdo->commit();
            $_SESSION['staff_moments_notice'] = $action === 'create_promo'
                ? 'Promotion submitted for review — it goes live once the admin approves it.'
                : 'Revision submitted — the admin will review it again.';
            header('Location: ' . BASE_URL . '/staff/moments.php');
            exit;
        } catch (Throwable $promoException) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors['form'] = 'We could not save the promotion just now — please try again.';
        }
    }

    // Keep the form in edit mode when a revision failed validation.
    if ($action === 'edit_promo') {
        $editRow = ['id' => $momentId];
    }
}

/* ── Fresh ?edit=ID — pre-fill from one of the staff's promotions ────── */
if (!$errors && isset($_GET['edit']) && $pdo) {
    $editStatement = $pdo->prepare(
        "SELECT id, product_id, title, body, moderation_status
         FROM bread_moments
         WHERE id = :id AND user_id = :user_id AND post_type = 'promotion'
         LIMIT 1"
    );
    $editStatement->execute(['id' => (int) $_GET['edit'], 'user_id' => $staffId]);
    $editRow = $editStatement->fetch() ?: null;
    if (!$editRow) {
        $_SESSION['staff_moments_notice'] = 'Error: you can only revise your own promotions.';
        header('Location: ' . BASE_URL . '/staff/moments.php');
        exit;
    }
    if ($editRow['moderation_status'] === 'visible') {
        $_SESSION['staff_moments_notice'] = 'Error: that promotion is already live — ask the admin if it needs changing.';
        header('Location: ' . BASE_URL . '/staff/moments.php');
        exit;
    }
    $old = [
        'product_id' => (string) (int) $editRow['product_id'],
        'title'      => (string) $editRow['title'],
        'body'       => (string) $editRow['body'],
    ];
}

$isResubmit = $editRow !== null;

/* ── Product selector + the staff's promotion history ────────────────── */
$products = [];
$myPromos = [];
if ($pdo) {
    $products = $pdo->query("
        SELECT i.id, i.name, c.name AS category_name
        FROM inventory_items i
        JOIN menu_categories c ON c.id = i.category_id
        ORDER BY c.name ASC, i.name ASC
    ")->fetchAll();

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

$noticeIsError = str_starts_with($notice, 'Error:');

require __DIR__ . '/../includes/staff_header.php';
?>
<section class="page-intro">
    <div>
        <h2>BreadMoments promotions</h2>
        <p>Pick a product, add a photo — the admin reviews every promotion before it appears in BreadMoments.</p>
    </div>
</section>

<?php if ($notice !== ''): ?>
<div class="admin-notice <?php echo $noticeIsError ? 'danger' : 'success'; ?>" role="<?php echo $noticeIsError ? 'alert' : 'status'; ?>">
    <i class="fa-solid <?php echo $noticeIsError ? 'fa-circle-exclamation' : 'fa-circle-check'; ?>"></i>
    <?php echo htmlspecialchars(preg_replace('/^Error:\s*/i', '', $notice)); ?>
</div>
<?php endif; ?>

<ul class="mod-steps">
    <li><i class="fa-solid fa-paper-plane"></i> <span>You submit</span></li>
    <li><i class="fa-solid fa-magnifying-glass"></i> <span>Admin reviews</span></li>
    <li><i class="fa-solid fa-circle-check"></i> <span>Approved → goes live as a store promotion</span></li>
    <li><i class="fa-solid fa-rotate-left"></i> <span>Rejected → revise using the admin's note → resubmit</span></li>
</ul>

<section class="panel mod-panel">
    <div class="panel-heading">
        <div>
            <h3><?php echo $isResubmit ? 'Revise your promotion' : 'New store promotion'; ?></h3>
            <p class="panel-subtitle"><?php echo $isResubmit ? 'Fix it based on the note, then send it back for review.' : 'It will be sent to the admin for review first.'; ?></p>
        </div>
        <?php if ($isResubmit): ?>
        <a class="admin-button secondary" href="<?php echo BASE_URL; ?>/staff/moments.php"><i class="fa-solid fa-xmark"></i> Cancel</a>
        <?php endif; ?>
    </div>

    <?php if (isset($errors['form'])): ?>
    <div class="admin-notice danger" role="alert"><i class="fa-solid fa-circle-exclamation"></i> <?php echo htmlspecialchars($errors['form']); ?></div>
    <?php endif; ?>

    <form class="admin-form promo-form" method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="<?php echo $isResubmit ? 'edit_promo' : 'create_promo'; ?>" />
        <?php if ($isResubmit): ?>
        <input type="hidden" name="moment_id" value="<?php echo (int) ($editRow['id'] ?? 0); ?>" />
        <?php endif; ?>

        <div class="form-grid two">
            <div class="form-field">
                <label for="promo-product">Product to promote</label>
                <select id="promo-product" name="product_id" required>
                    <option value="">Select a product…</option>
                    <?php foreach ($products as $promoProduct): ?>
                    <option value="<?php echo (int) $promoProduct['id']; ?>" <?php echo $old['product_id'] === (string) $promoProduct['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($promoProduct['category_name'] . ' — ' . $promoProduct['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php if (isset($errors['product_id'])): ?><small class="field-error"><?php echo htmlspecialchars($errors['product_id']); ?></small><?php endif; ?>
            </div>

            <div class="form-field">
                <label for="promo-photo">Promo photo</label>
                <input id="promo-photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp" <?php echo $isResubmit ? '' : 'required'; ?> />
                <small class="mod-hint">JPG / PNG / WEBP, up to 8MB. <?php echo $isResubmit ? 'Leave it empty to keep the current photo.' : ''; ?></small>
                <?php if (isset($errors['photo'])): ?><small class="field-error"><?php echo htmlspecialchars($errors['photo']); ?></small><?php endif; ?>
            </div>
        </div>

        <div class="form-field">
            <label for="promo-title">Title</label>
            <input id="promo-title" name="title" type="text" maxlength="140" required placeholder="Halimbawa: Fresh Pandesal — Bagong luto tuwing umaga!" value="<?php echo htmlspecialchars($old['title']); ?>" />
            <?php if (isset($errors['title'])): ?><small class="field-error"><?php echo htmlspecialchars($errors['title']); ?></small><?php endif; ?>
        </div>

        <div class="form-field">
            <label for="promo-body">Caption</label>
            <textarea id="promo-body" name="body" required placeholder="Ano ang espesyal sa produktong ito? Presyo, oras, promo…"><?php echo htmlspecialchars($old['body']); ?></textarea>
            <?php if (isset($errors['body'])): ?><small class="field-error"><?php echo htmlspecialchars($errors['body']); ?></small><?php endif; ?>
        </div>

        <div class="modal-actions">
            <button class="admin-button primary" type="submit">
                <i class="fa-solid fa-paper-plane"></i> <?php echo $isResubmit ? 'Save &amp; send for review' : 'Submit for review'; ?>
            </button>
        </div>
    </form>
</section>

<section class="panel mod-panel">
    <div class="panel-heading">
        <div>
            <h3>My promotions</h3>
            <p class="panel-subtitle">Everything you submitted, with the current review status.</p>
        </div>
    </div>

    <?php if (!$myPromos): ?>
        <p class="empty-state">You have not submitted a promotion yet — use the form above to create your first one.</p>
    <?php else: ?>
        <?php foreach ($myPromos as $row): ?>
        <article class="mod-card">
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
                    <?php [$pillLabel, $pillClass] = momentStatusLabel((string) $row['moderation_status']); ?>
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
    <?php endif; ?>
</section>

<?php require __DIR__ . '/../includes/staff_footer.php'; ?>
