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
<div class="staff-promos">
<!-- ── Hero ── -->
<section class="welcome-block dashboard-welcome staff-hero">
    <div>
        <span class="staff-dash-kicker"><i class="fa-solid fa-bullhorn"></i> BreadMoments · Store Promotions</span>
        <h2>Promote your bakery products</h2>
        <p>Pick a product, add a photo — the admin reviews every promotion before it appears in BreadMoments.</p>
    </div>
    <div class="hero-chips">
        <span class="dashboard-date"><i class="fa-solid fa-shield-halved"></i> Admin review first</span>
        <a class="dashboard-date dashboard-date-link" href="<?php echo BASE_URL; ?>/staff/promotions.php">
            <i class="fa-solid fa-shop"></i> Shop Promotions (<?php echo count($myPromos); ?>) <i class="fa-solid fa-arrow-right"></i>
        </a>
    </div>
</section>

<?php if ($notice !== ''): ?>
<div class="admin-notice <?php echo $noticeIsError ? 'danger' : 'success'; ?>" role="<?php echo $noticeIsError ? 'alert' : 'status'; ?>">
    <i class="fa-solid <?php echo $noticeIsError ? 'fa-circle-exclamation' : 'fa-circle-check'; ?>"></i>
    <?php echo htmlspecialchars(preg_replace('/^Error:\s*/i', '', $notice)); ?>
</div>
<?php endif; ?>

<!-- ── How it works ── -->
<section class="panel moments-panel">
    <div class="panel-heading moments-heading">
        <div class="moments-heading-copy">
            <span class="moments-heading-icon"><i class="fa-solid fa-route"></i></span>
            <div>
                <h3>How it works</h3>
                <p class="panel-subtitle">Four simple steps, from draft to live post.</p>
            </div>
        </div>
    </div>
    <ol class="moments-steps">
        <li class="is-brown">
            <span class="moments-step-index">1</span>
            <span class="moments-step-copy"><strong>You submit</strong><small>Pick a product, add a photo and write a caption.</small></span>
        </li>
        <li class="is-blue">
            <span class="moments-step-index">2</span>
            <span class="moments-step-copy"><strong>Admin reviews</strong><small>Every promotion is checked before it goes live.</small></span>
        </li>
        <li class="is-green">
            <span class="moments-step-index">3</span>
            <span class="moments-step-copy"><strong>Approved &rarr; live</strong><small>Shown in BreadMoments as a store promotion.</small></span>
        </li>
        <li class="is-red">
            <span class="moments-step-index">4</span>
            <span class="moments-step-copy"><strong>Rejected &rarr; revise</strong><small>Use the admin&rsquo;s note, then send it back.</small></span>
        </li>
    </ol>
</section>

<section class="panel moments-panel">
    <div class="panel-heading moments-heading">
        <div class="moments-heading-copy">
            <span class="moments-heading-icon"><i class="fa-solid fa-pen-to-square"></i></span>
            <div>
                <h3><?php echo $isResubmit ? 'Revise your promotion' : 'New store promotion'; ?></h3>
                <p class="panel-subtitle"><?php echo $isResubmit ? 'Fix it based on the note, then send it back for review.' : 'It will be sent to the admin for review first.'; ?></p>
            </div>
        </div>
        <?php if ($isResubmit): ?>
        <a class="admin-button secondary moments-pill" href="<?php echo BASE_URL; ?>/staff/moments.php"><i class="fa-solid fa-xmark"></i> Cancel</a>
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
                <small class="mod-hint">Only products currently on the menu are listed.</small>
                <?php if (isset($errors['product_id'])): ?><small class="field-error"><?php echo htmlspecialchars($errors['product_id']); ?></small><?php endif; ?>
            </div>

            <div class="photo-field promo-photo-field" data-promo-upload>
                <label for="promo-photo">Promo photo</label>
                <label class="photo-drop promo-drop" for="promo-photo">
                    <img class="promo-drop-preview" data-promo-photo-preview alt="" hidden />
                    <span class="promo-drop-copy" data-promo-photo-copy>
                        <strong>Click to choose a photo</strong>
                        <small>JPG / PNG / WEBP, up to 8MB</small>
                    </span>
                    <span class="photo-filename" data-promo-photo-name>No photo selected</span>
                </label>
                <input id="promo-photo" class="sr-only" name="photo" type="file" accept="image/jpeg,image/png,image/webp" data-promo-photo-input <?php echo $isResubmit ? '' : 'required'; ?> />
                <div class="promo-photo-actions" hidden data-promo-photo-meta>
                    <button class="promo-photo-remove" type="button" data-promo-photo-remove><i class="fa-solid fa-xmark"></i> Remove photo</button>
                </div>
                <?php if ($isResubmit): ?><small class="mod-hint">Leave it empty to keep the current photo.</small><?php endif; ?>
                <?php if (isset($errors['photo'])): ?><small class="field-error"><?php echo htmlspecialchars($errors['photo']); ?></small><?php endif; ?>
            </div>
        </div>

        <div class="form-field promo-field">
            <label for="promo-title">Title <span class="promo-count" data-promo-count="promo-title">0/140</span></label>
            <input id="promo-title" name="title" type="text" maxlength="140" required placeholder="Halimbawa: Fresh Pandesal — Bagong luto tuwing umaga!" value="<?php echo htmlspecialchars($old['title']); ?>" />
            <?php if (isset($errors['title'])): ?><small class="field-error"><?php echo htmlspecialchars($errors['title']); ?></small><?php endif; ?>
        </div>

        <div class="form-field promo-field">
            <label for="promo-body">Caption <span class="promo-count" data-promo-count="promo-body">0/2000</span></label>
            <textarea id="promo-body" name="body" required maxlength="2000" placeholder="Ano ang espesyal sa produktong ito? Presyo, oras, promo…"><?php echo htmlspecialchars($old['body']); ?></textarea>
            <?php if (isset($errors['body'])): ?><small class="field-error"><?php echo htmlspecialchars($errors['body']); ?></small><?php endif; ?>
        </div>

        <div class="modal-actions">
            <button class="admin-button primary" type="submit">
                <i class="fa-solid fa-paper-plane"></i> <?php echo $isResubmit ? 'Save &amp; send for review' : 'Submit for review'; ?>
            </button>
        </div>
    </form>
</section>

</div>

<?php require __DIR__ . '/../includes/staff_footer.php'; ?>
