<?php
$pageTitle = 'BreadMoments | BreadBreak';

require_once __DIR__ . '/includes/moments.php';

$momentPdo = momentDb();
$momentDbError = momentDbError();

/* ── Hardcoded quick starters (tap to insert into the text field) ─────── */
$momentStarters = [
    ['label' => 'Menu Highlights',    'text' => 'Menu Highlights: '],
    ['label' => 'Overall Rating',     'text' => 'Overall Rating: 5/5 — '],
    ['label' => 'Wait Time Reality',  'text' => 'Wait Time Reality: Ordered at __:__, received after ___ minutes. '],
    ['label' => 'Value for Money',    'text' => 'Value for Money: ₱___ for ___ — worth it because '],
    ['label' => 'Must-Try in Menu',   'text' => 'Must-Try in Menu: '],
    ['label' => 'My Honest Review',   'text' => 'My Honest Review: '],
    ['label' => 'Photo Spots',        'text' => 'Photo Spots: '],
    ['label' => 'Freshness Check',    'text' => 'Freshness Check: '],
    ['label' => 'Would Order Again',  'text' => 'Would Order Again: '],
    ['label' => 'Sweetness Level',    'text' => 'Sweetness Level: ___ (too sweet / just right / not sweet enough) '],
    ['label' => 'Pasalubong Pick',    'text' => 'Pasalubong Pick: '],
    ['label' => 'Packaging Check',    'text' => 'Packaging Check: '],
    ['label' => 'First Bite Reaction','text' => 'First Bite Reaction: '],
];

/* ── Posting tips + public notes ──────────────────────────────────────── */
$momentTips = [
    'Share your honest thoughts or experience.',
    'Don’t reveal private personal details (e.g. full names, phone numbers).',
    'Avoid sensitive content about religion or politics.',
    'Posts with advertisements or inappropriate content (e.g. violence, pornography) will be removed.',
    'Your review will appear publicly.',
];

$momentNotes = [
    'Your profile name (first name only) is shown with your post.',
    'Your photos are displayed inside the post.',
    'You can edit or delete your post anytime.',
];

/* ── Photo upload reader (shared by create + edit) ────────────────────── */
function momentReadUploads($files, int $slots, array &$errors): array
{
    $photos = [];
    if (!is_array($files) || (int) ($files['error'][0] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return $photos;
    }
    $count = count($files['name']);
    if ($count > $slots || $slots <= 0) {
        $errors['photos'] = 'You can attach up to ' . MOMENT_MAX_PHOTOS . ' photos per post.';
        return $photos;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $allowedMimes = ['image/jpeg' => 1, 'image/png' => 1, 'image/webp' => 1];
    for ($index = 0; $index < $count; $index++) {
        $errorCode = (int) $files['error'][$index];
        if ($errorCode === UPLOAD_ERR_NO_FILE) continue;
        if ($errorCode === UPLOAD_ERR_INI_SIZE || $errorCode === UPLOAD_ERR_FORM_SIZE) {
            $errors['photos'] = 'One of your photos is too large — the limit is 8MB per photo.';
            return $photos;
        }
        $tmp = (string) $files['tmp_name'][$index];
        if ($errorCode !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
            $errors['photos'] = 'That photo could not be read. Please try another one.';
            return $photos;
        }
        if ((int) $files['size'][$index] > MOMENT_MAX_BYTES) {
            $errors['photos'] = $files['name'][$index] . ' is larger than 8MB — please pick a smaller photo.';
            return $photos;
        }
        $mime = $finfo->file($tmp);
        if (!isset($allowedMimes[$mime])) {
            $errors['photos'] = 'Only JPG, PNG, and WEBP photos are allowed.';
            return $photos;
        }
        $contents = file_get_contents($tmp);
        if ($contents === false) {
            $errors['photos'] = 'That photo could not be read. Please try another one.';
            return $photos;
        }
        $photos[] = ['data' => $contents, 'mime' => $mime];
    }
    return $photos;
}

function momentNormalizeTopics(string $raw): array
{
    return array_values(array_filter(array_map(static function (string $topic): string {
        $topic = strtolower(trim($topic));
        $topic = (string) preg_replace('/[^a-z0-9\- ]/', '', $topic);
        $topic = (string) preg_replace('/\s+/', '-', $topic);
        $topic = (string) preg_replace('/-+/', '-', $topic);
        return trim($topic, '-');
    }, explode(',', $raw))));
}

/* ── Who is posting ───────────────────────────────────────────────────── */
$momentUserId = (int) ($_SESSION['user_id'] ?? 0);
$momentIsSignedIn = $momentUserId > 0;
$momentFirstName = trim((string) ($_SESSION['first_name'] ?? ''));

// Guests who asked to create or edit a post sign in first.
if (!$momentIsSignedIn && (isset($_GET['create']) || isset($_GET['edit']))) {
    header('Location: /BreadBreak/login.php?redirect=moments');
    exit;
}

/* ── Filters ──────────────────────────────────────────────────────────── */
$momentFilterCat = (int) ($_GET['cat'] ?? 0);
$momentFilterTopic = strtolower(trim((string) ($_GET['topic'] ?? '')));
$momentFilterTopic = (string) preg_replace('/[^a-z0-9\-]/', '', $momentFilterTopic);
$momentSearch = trim((string) ($_GET['q'] ?? ''));
$momentSearch = (string) preg_replace('/[<>]/', '', mb_substr($momentSearch, 0, 60));
$momentTab = (string) ($_GET['tab'] ?? 'foryou');
if (!in_array($momentTab, ['foryou', 'top'], true)) $momentTab = 'foryou';

$momentFilterQuery = [];
if ($momentFilterCat) $momentFilterQuery['cat'] = $momentFilterCat;
if ($momentFilterTopic) $momentFilterQuery['topic'] = $momentFilterTopic;
if ($momentSearch !== '') $momentFilterQuery['q'] = $momentSearch;
if ($momentTab !== 'foryou') $momentFilterQuery['tab'] = $momentTab;
$momentFilterUrl = '/BreadBreak/moments.php' . ($momentFilterQuery ? '?' . http_build_query($momentFilterQuery) : '');

// Feed link builder that keeps the active filters unless overridden (null clears).
$momentHref = function (array $overrides = []) use ($momentFilterCat, $momentFilterTopic, $momentSearch, $momentTab) {
    $params = [
        'cat'   => $momentFilterCat ?: null,
        'topic' => $momentFilterTopic ?: null,
        'q'     => $momentSearch !== '' ? $momentSearch : null,
        'tab'   => $momentTab !== 'foryou' ? $momentTab : null,
    ];
    foreach ($overrides as $momentParamKey => $momentParamValue) {
        $params[$momentParamKey] = $momentParamValue;
    }
    $params = array_filter($params, static fn ($momentParamValue) => $momentParamValue !== null && $momentParamValue !== '');
    return '/BreadBreak/moments.php' . ($params ? '?' . http_build_query($params) : '');
};

/* ── Form state (create + edit) ───────────────────────────────────────── */
$momentErrors = [];
$momentOld = [
    'title'       => '',
    'body'        => '',
    'category_id' => '',
    'topics'      => '',
];
$momentPhotosNote = '';
$momentEditId = 0;
$momentEditKeep = '';
$momentNext = 'feed';

/* ── POST: create / edit ──────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$momentIsSignedIn) {
        header('Location: /BreadBreak/login.php?redirect=moments');
        exit;
    }

    $momentAction = (string) ($_POST['action'] ?? '');

    if ($momentAction === 'create' || $momentAction === 'edit') {
        $isEdit = $momentAction === 'edit';
        $momentEditId = $isEdit ? (int) ($_POST['moment_id'] ?? 0) : 0;
        $momentEditKeep = $isEdit ? (string) ($_POST['keep_photos'] ?? '') : '';
        $momentNext = ($_POST['next'] ?? '') === 'moment' ? 'moment' : 'feed';

        $momentOld['title']       = trim((string) ($_POST['title'] ?? ''));
        $momentOld['body']        = trim((string) ($_POST['body'] ?? ''));
        $momentOld['category_id'] = (string) (int) ($_POST['category_id'] ?? 0);
        $momentOld['topics']      = trim((string) ($_POST['topics'] ?? ''));
        $momentTrap               = trim((string) ($_POST['website'] ?? ''));

        // Honeypot — bots fill the hidden field, humans never see it.
        if ($momentTrap !== '') {
            $_SESSION['moments_notice'] = 'Your BreadMoment is live — thanks for sharing!';
            header('Location: ' . $momentFilterUrl);
            exit;
        }

        if ($momentOld['title'] === '') {
            $momentErrors['title'] = 'Give your post a title.';
        } elseif (strlen($momentOld['title']) > 140) {
            $momentErrors['title'] = 'Keep the title under 140 characters.';
        }

        if ($momentOld['body'] === '') {
            $momentErrors['body'] = 'Tell us about your moment.';
        } elseif (strlen($momentOld['body']) < 10) {
            $momentErrors['body'] = 'A few more details, please (at least 10 characters).';
        } elseif (strlen($momentOld['body']) > 2000) {
            $momentErrors['body'] = 'Please keep it under 2,000 characters.';
        }

        $momentTopicList = momentNormalizeTopics($momentOld['topics']);
        if (count($momentTopicList) > MOMENT_MAX_TOPICS) {
            $momentErrors['topics'] = 'You can add up to ' . MOMENT_MAX_TOPICS . ' topics.';
        }

        $momentOwnedPost = null;
        $momentKeptIds = array_values(array_unique(array_filter(array_map('intval', explode(',', $momentEditKeep)))));

        if (!$momentPdo) {
            $momentErrors['form'] = $momentDbError ?: 'We could not save your post just yet. Please try again.';
        } else {
            $momentCategoryOk = false;
            if ($momentOld['category_id'] !== '') {
                $momentCategoryCheck = $momentPdo->prepare('SELECT id FROM menu_categories WHERE id = :id LIMIT 1');
                $momentCategoryCheck->execute(['id' => (int) $momentOld['category_id']]);
                $momentCategoryOk = (bool) $momentCategoryCheck->fetch();
            }
            if (!$momentCategoryOk) {
                $momentErrors['category_id'] = 'Select the menu category where this was baked.';
            }

            if ($isEdit) {
                $momentOwnStatement = $momentPdo->prepare('SELECT id, post_type FROM bread_moments WHERE id = :id AND user_id = :user_id LIMIT 1');
                $momentOwnStatement->execute(['id' => $momentEditId, 'user_id' => $momentUserId]);
                $momentOwnedPost = $momentOwnStatement->fetch() ?: null;
                if (!$momentOwnedPost) {
                    $momentErrors['form'] = 'You can only edit your own posts.';
                } elseif (($momentOwnedPost['post_type'] ?? 'moment') === 'promotion') {
                    // Store promotions live in the staff portal — resend them there.
                    header('Location: /BreadBreak/staff/moments.php?edit=' . $momentEditId);
                    exit;
                } elseif ($momentKeptIds) {
                    $momentKeepStatement = $momentPdo->prepare(
                        'SELECT id FROM bread_moment_photos WHERE moment_id = :moment_id AND id IN (' . implode(',', $momentKeptIds) . ')'
                    );
                    $momentKeepStatement->execute(['moment_id' => $momentEditId]);
                    $momentKeptIds = array_map('intval', array_column($momentKeepStatement->fetchAll(), 'id'));
                }
            }
        }

        // Photos — up to 4 total (kept + new), finfo-validated JPG / PNG / WEBP.
        $momentSlots = MOMENT_MAX_PHOTOS - ($isEdit ? count($momentKeptIds) : 0);
        $momentPhotos = momentReadUploads($_FILES['photos'] ?? null, $momentSlots, $momentErrors);

        if (!$momentErrors) {
            try {
                $momentPdo->beginTransaction();

                if ($isEdit) {
                    $momentUpdate = $momentPdo->prepare(
                        'UPDATE bread_moments SET category_id = :category_id, title = :title, body = :body, topics = :topics WHERE id = :id AND user_id = :user_id AND post_type = \'moment\''
                    );
                    $momentUpdate->execute([
                        'category_id' => $momentOld['category_id'] === '' ? null : (int) $momentOld['category_id'],
                        'title'       => $momentOld['title'],
                        'body'        => $momentOld['body'],
                        'topics'      => implode(',', $momentTopicList),
                        'id'          => $momentEditId,
                        'user_id'     => $momentUserId,
                    ]);

                    $momentDeleteSql = $momentKeptIds
                        ? 'DELETE FROM bread_moment_photos WHERE moment_id = :moment_id AND id NOT IN (' . implode(',', $momentKeptIds) . ')'
                        : 'DELETE FROM bread_moment_photos WHERE moment_id = :moment_id';
                    $momentPhotoDelete = $momentPdo->prepare($momentDeleteSql);
                    $momentPhotoDelete->execute(['moment_id' => $momentEditId]);

                    // Re-number the survivors so the first photo stays first.
                    $momentSurvivorStatement = $momentPdo->prepare('SELECT id FROM bread_moment_photos WHERE moment_id = :moment_id ORDER BY position ASC, id ASC');
                    $momentSurvivorStatement->execute(['moment_id' => $momentEditId]);
                    $momentSurvivors = array_column($momentSurvivorStatement->fetchAll(), 'id');
                    $momentPositionUpdate = $momentPdo->prepare('UPDATE bread_moment_photos SET position = :position WHERE id = :id');
                    foreach ($momentSurvivors as $momentPosition => $momentSurvivorId) {
                        $momentPositionUpdate->execute(['position' => $momentPosition, 'id' => (int) $momentSurvivorId]);
                    }

                    $momentTargetId = $momentEditId;
                    $momentNewPosition = count($momentSurvivors);
                    $momentNoticeText = 'Your BreadMoment was updated.';
                } else {
                    $momentInsert = $momentPdo->prepare(
                        'INSERT INTO bread_moments (user_id, category_id, title, body, topics) VALUES (:user_id, :category_id, :title, :body, :topics)'
                    );
                    $momentInsert->execute([
                        'user_id'     => $momentUserId,
                        'category_id' => $momentOld['category_id'] === '' ? null : (int) $momentOld['category_id'],
                        'title'       => $momentOld['title'],
                        'body'        => $momentOld['body'],
                        'topics'      => implode(',', $momentTopicList),
                    ]);
                    $momentTargetId = (int) $momentPdo->lastInsertId();
                    $momentNewPosition = 0;
                    $momentNoticeText = 'Your BreadMoment is live — thanks for sharing, ' . $momentFirstName . '!';
                }

                if ($momentPhotos) {
                    $momentPhotoInsert = $momentPdo->prepare(
                        'INSERT INTO bread_moment_photos (moment_id, photo_data, photo_mime, position) VALUES (:moment_id, :photo_data, :photo_mime, :position)'
                    );
                    foreach ($momentPhotos as $momentOffset => $momentPhoto) {
                        $momentPhotoInsert->execute([
                            'moment_id'  => $momentTargetId,
                            'photo_data' => $momentPhoto['data'],
                            'photo_mime' => $momentPhoto['mime'],
                            'position'   => $momentNewPosition + $momentOffset,
                        ]);
                    }
                }

                $momentPdo->commit();

                $_SESSION['moments_notice'] = $momentNoticeText;
                if ($isEdit && $momentNext === 'moment') {
                    header('Location: /BreadBreak/moment.php?id=' . $momentTargetId);
                } else {
                    header('Location: ' . $momentFilterUrl);
                }
                exit;
            } catch (Throwable $momentSaveException) {
                if ($momentPdo->inTransaction()) $momentPdo->rollBack();
                $momentErrors['form'] = 'We could not save your post just yet. Please try again.';
            }
        }

        // Something was wrong — keep the input and reopen the composer on the way back.
        if ($momentErrors) {
            $momentPhotosNote = $momentPhotos ? 'Please re-select your photos after fixing the errors below.' : '';
            $_SESSION['moments_form'] = [
                'errors'      => $momentErrors,
                'old'         => $momentOld,
                'photos_note' => $momentPhotosNote,
                'edit_id'     => $isEdit ? $momentEditId : 0,
                'keep'        => $isEdit ? $momentEditKeep : '',
                'next'        => $momentNext,
            ];
            header('Location: ' . $momentFilterUrl);
            exit;
        }
    }
}

/* ── Notices restored after redirect ──────────────────────────────────── */
$momentNotice = (string) ($_SESSION['moments_notice'] ?? '');
unset($_SESSION['moments_notice']);

$momentRestoredEdit = false;
if (!empty($_SESSION['moments_form']) && is_array($_SESSION['moments_form'])) {
    $momentSavedForm = $_SESSION['moments_form'];
    $momentErrors = is_array($momentSavedForm['errors'] ?? null) ? $momentSavedForm['errors'] : [];
    $momentOld = array_merge($momentOld, is_array($momentSavedForm['old'] ?? null) ? $momentSavedForm['old'] : []);
    $momentPhotosNote = (string) ($momentSavedForm['photos_note'] ?? '');
    $momentEditId = (int) ($momentSavedForm['edit_id'] ?? 0);
    $momentEditKeep = (string) ($momentSavedForm['keep'] ?? '');
    $momentNext = ($momentSavedForm['next'] ?? '') === 'moment' ? 'moment' : 'feed';
    $momentRestoredEdit = $momentEditId > 0;
}
unset($_SESSION['moments_form']);

/* ── Fresh ?edit=ID — pre-fill the composer from the owner's post ─────── */
$momentEditPhotos = [];
if (!$momentRestoredEdit && $momentIsSignedIn && isset($_GET['edit'])) {
    $momentEditStatement = $momentPdo
        ? $momentPdo->prepare('SELECT id, title, body, topics, category_id, post_type FROM bread_moments WHERE id = :id AND user_id = :user_id LIMIT 1')
        : null;
    if ($momentEditStatement) {
        $momentEditStatement->execute(['id' => (int) $_GET['edit'], 'user_id' => $momentUserId]);
        $momentEditRow = $momentEditStatement->fetch() ?: null;
    } else {
        $momentEditRow = null;
    }

    if (!$momentEditRow) {
        $_SESSION['moments_notice'] = 'You can only edit your own posts.';
        header('Location: /BreadBreak/moments.php');
        exit;
    }

    if (($momentEditRow['post_type'] ?? 'moment') === 'promotion') {
        // Store promotions are revised from the staff portal, not this composer.
        header('Location: /BreadBreak/staff/moments.php?edit=' . (int) $momentEditRow['id']);
        exit;
    }

    $momentEditId = (int) $momentEditRow['id'];
    $momentOld['title'] = (string) $momentEditRow['title'];
    $momentOld['body'] = (string) $momentEditRow['body'];
    $momentOld['category_id'] = (string) (int) $momentEditRow['category_id'];
    $momentOld['topics'] = (string) $momentEditRow['topics'];
    $momentNext = ($_GET['next'] ?? '') === 'moment' ? 'moment' : 'feed';

    if ($momentPdo) {
        $momentEditPhotoStatement = $momentPdo->prepare('SELECT id FROM bread_moment_photos WHERE moment_id = :moment_id ORDER BY position ASC, id ASC');
        $momentEditPhotoStatement->execute(['moment_id' => $momentEditId]);
        foreach ($momentEditPhotoStatement->fetchAll() as $momentEditPhotoRow) {
            $momentEditPhotos[] = (int) $momentEditPhotoRow['id'];
        }
    }
    $momentEditKeep = $momentEditId ? implode(',', $momentEditPhotos) : '';
}

if ($momentRestoredEdit && $momentPdo && $momentEditId) {
    $momentEditPhotoStatement = $momentPdo->prepare('SELECT id FROM bread_moment_photos WHERE moment_id = :moment_id ORDER BY position ASC, id ASC');
    $momentEditPhotoStatement->execute(['moment_id' => $momentEditId]);
    foreach ($momentEditPhotoStatement->fetchAll() as $momentEditPhotoRow) {
        $momentEditPhotos[] = (int) $momentEditPhotoRow['id'];
    }
}
$momentKeepIdList = array_filter(array_map('intval', explode(',', $momentEditKeep)));

/* ── Feed data ────────────────────────────────────────────────────────── */
$momentCategories = [];
$momentPosts = [];
$momentFirstPhotos = [];
$momentTopicCloud = [];
$momentTotalPosts = 0;

if ($momentPdo) {
    $momentCategoryStatement = $momentPdo->query("
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
    foreach ($momentCategoryStatement->fetchAll() as $momentCategoryRow) {
        if ((int) $momentCategoryRow['product_count'] < 1) continue;
        $momentCategories[] = [
            'id'   => (int) $momentCategoryRow['id'],
            'name' => (string) $momentCategoryRow['name'],
        ];
    }

    $momentWhere = [];
    $momentParams = [];
    // Only approved posts ever appear in the public feed.
    $momentWhere[] = "m.moderation_status = 'visible'";
    if ($momentFilterCat) {
        $momentWhere[] = 'm.category_id = :cat';
        $momentParams['cat'] = $momentFilterCat;
    }
    if ($momentFilterTopic) {
        $momentWhere[] = 'FIND_IN_SET(:topic, m.topics)';
        $momentParams['topic'] = $momentFilterTopic;
    }
    if ($momentSearch !== '') {
        $momentWhere[] = '(m.title LIKE :q1 OR m.body LIKE :q2 OR m.topics LIKE :q3 OR u.first_name LIKE :q4 OR pi.name LIKE :q5)';
        $momentParams['q1'] = '%' . $momentSearch . '%';
        $momentParams['q2'] = $momentParams['q1'];
        $momentParams['q3'] = $momentParams['q1'];
        $momentParams['q4'] = $momentParams['q1'];
        $momentParams['q5'] = $momentParams['q1'];
    }
    $momentWhereSql = ' WHERE ' . implode(' AND ', $momentWhere);

    // The count needs the joins too because search matches author and product names.
    $momentCountStatement = $momentPdo->prepare('SELECT COUNT(*) FROM bread_moments m JOIN users u ON u.id = m.user_id LEFT JOIN inventory_items pi ON pi.id = m.product_id' . $momentWhereSql);
    $momentCountStatement->execute($momentParams);
    $momentTotalPosts = (int) $momentCountStatement->fetchColumn();

    // "For you" stays chronological; "Top posts" ranks by likes + comments + views.
    $momentOrderSql = $momentTab === 'top'
        ? 'ORDER BY ((SELECT COUNT(*) FROM bread_moment_comments cc WHERE cc.moment_id = m.id)
                 + (SELECT COUNT(*) FROM bread_moment_likes l WHERE l.moment_id = m.id)
                 + m.views_count) DESC, m.created_at DESC, m.id DESC'
        : 'ORDER BY m.created_at DESC, m.id DESC';

    $momentPostStatement = $momentPdo->prepare("
        SELECT m.id, m.title, m.views_count, m.created_at, m.post_type, m.product_id,
               u.first_name, u.profile_data, u.profile_mime, pi.name AS product_name
        FROM bread_moments m
        JOIN users u ON u.id = m.user_id
        LEFT JOIN inventory_items pi ON pi.id = m.product_id
        $momentWhereSql
        $momentOrderSql
        LIMIT 30
    ");
    $momentPostStatement->execute($momentParams);
    $momentPosts = $momentPostStatement->fetchAll();

    if ($momentPosts) {
        $momentPostIds = implode(',', array_map('intval', array_column($momentPosts, 'id')));
        foreach ($momentPdo->query("SELECT moment_id, MIN(id) AS photo_id FROM bread_moment_photos WHERE moment_id IN ($momentPostIds) GROUP BY moment_id") as $momentPhotoRow) {
            $momentFirstPhotos[(int) $momentPhotoRow['moment_id']] = (int) $momentPhotoRow['photo_id'];
        }
    }

    $momentTopicCounts = [];
    foreach ($momentPdo->query("SELECT topics FROM bread_moments WHERE topics <> '' AND moderation_status = 'visible' ORDER BY created_at DESC LIMIT 60") as $momentTopicRow) {
        foreach (explode(',', (string) $momentTopicRow['topics']) as $momentTopicName) {
            $momentTopicName = trim($momentTopicName);
            if ($momentTopicName === '') continue;
            $momentTopicCounts[$momentTopicName] = ($momentTopicCounts[$momentTopicName] ?? 0) + 1;
        }
    }
    arsort($momentTopicCounts);
    $momentTopicCloud = array_slice(array_keys($momentTopicCounts), 0, 10);
}

$momentAutoOpen = isset($_GET['create']) || $momentErrors || !empty($_GET['tips']) || $momentEditId > 0;
$momentAutoTips = $momentErrors || !empty($_GET['tips']);
$momentIsEdit = $momentEditId > 0;
?>

<?php include __DIR__ . '/includes/header.php'; ?>

<main class="moments-page">

    <!-- ── Head ───────────────────────────────────────────────────────── -->
    <section class="moments-head">
        <div class="container moments-head-inner">
            <div class="moments-head-copy">
                <span class="eyebrow">BreadMoments</span>
                <h1>Fresh takes from our community</h1>
                <p>Post what you ordered — photos, your honest review, and the menu moments worth sharing.</p>
            </div>
            <div class="moments-actions">
                <button type="button" class="btn btn-secondary" data-open-moment="tips">
                    <i class="fa-solid fa-circle-info"></i> Posting Tips
                </button>
                <?php if ($momentIsSignedIn): ?>
                <?php if ($momentIsEdit): ?>
                <a href="/BreadBreak/moments.php?create=1" class="btn btn-primary">
                    <i class="fa-solid fa-plus"></i> Create Post
                </a>
                <?php else: ?>
                <button type="button" class="btn btn-primary" data-open-moment>
                    <i class="fa-solid fa-plus"></i> Create Post
                </button>
                <?php endif; ?>
                <?php else: ?>
                <a href="/BreadBreak/login.php?redirect=moments" class="btn btn-primary">
                    <i class="fa-solid fa-plus"></i> Create Post
                </a>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- ── Feed ───────────────────────────────────────────────────────── -->
    <section class="section moments-section">
        <div class="container">

            <?php if ($momentNotice !== ''): ?>
                <p class="form-notice is-success" role="status"><i class="fa-solid fa-circle-check"></i> <?php echo momentEscape($momentNotice); ?></p>
            <?php endif; ?>
            <?php if ($momentDbError !== '' && !$momentPdo): ?>
                <p class="form-notice is-error" role="alert"><i class="fa-solid fa-circle-exclamation"></i> <?php echo momentEscape($momentDbError); ?></p>
            <?php endif; ?>

            <!-- ── Tabs: For you / Top posts + search ── -->
            <div class="moments-bar">
                <div class="moments-tabs" role="tablist" aria-label="Feed type">
                    <a role="tab" aria-selected="<?php echo $momentTab === 'foryou' ? 'true' : 'false'; ?>"
                       class="moments-tab<?php echo $momentTab === 'foryou' ? ' is-active' : ''; ?>"
                       href="<?php echo momentEscape($momentHref(['tab' => 'foryou'])); ?>">
                        <i class="fa-solid fa-wand-magic-sparkles"></i> For you
                    </a>
                    <a role="tab" aria-selected="<?php echo $momentTab === 'top' ? 'true' : 'false'; ?>"
                       class="moments-tab<?php echo $momentTab === 'top' ? ' is-active' : ''; ?>"
                       href="<?php echo momentEscape($momentHref(['tab' => 'top'])); ?>">
                        <i class="fa-solid fa-fire-flame-curved"></i> Top posts
                    </a>
                </div>
                <form class="moments-search" method="get" action="/BreadBreak/moments.php" role="search">
                    <?php if ($momentFilterCat): ?><input type="hidden" name="cat" value="<?php echo (int) $momentFilterCat; ?>" /><?php endif; ?>
                    <?php if ($momentFilterTopic): ?><input type="hidden" name="topic" value="<?php echo momentEscape($momentFilterTopic); ?>" /><?php endif; ?>
                    <?php if ($momentTab !== 'foryou'): ?><input type="hidden" name="tab" value="<?php echo momentEscape($momentTab); ?>" /><?php endif; ?>
                    <input type="search" name="q" maxlength="60" value="<?php echo momentEscape($momentSearch); ?>"
                           placeholder="Search posts, people, topics…" aria-label="Search BreadMoments" />
                    <button type="submit" aria-label="Search"><i class="fa-solid fa-magnifying-glass"></i></button>
                </form>
            </div>

            <div class="moments-toolbar">
                <div class="moments-chips">
                    <a href="<?php echo momentEscape($momentHref(['cat' => null])); ?>" class="shop-filter-chip<?php echo !$momentFilterCat ? ' is-active' : ''; ?>">All Moments</a>
                    <?php foreach ($momentCategories as $momentCategory): ?>
                    <a href="<?php echo momentEscape($momentHref(['cat' => $momentCategory['id']])); ?>"
                       class="shop-filter-chip<?php echo $momentFilterCat === $momentCategory['id'] ? ' is-active' : ''; ?>"><?php echo momentEscape($momentCategory['name']); ?></a>
                    <?php endforeach; ?>
                </div>
                <span class="moments-total"><?php echo (int) $momentTotalPosts; ?> post<?php echo $momentTotalPosts === 1 ? '' : 's'; ?><?php echo $momentTab === 'top' ? ' · ranked' : ''; ?></span>
            </div>

            <?php if ($momentFilterTopic || $momentSearch !== ''): ?>
            <p class="moment-filter-note">
                <span>
                    <?php if ($momentFilterTopic): ?><i class="fa-solid fa-hashtag"></i> Showing posts tagged <strong>#<?php echo momentEscape($momentFilterTopic); ?></strong><?php endif; ?>
                    <?php if ($momentFilterTopic && $momentSearch !== ''): ?> &nbsp;·&nbsp; <?php endif; ?>
                    <?php if ($momentSearch !== ''): ?><i class="fa-solid fa-magnifying-glass"></i> Results for <strong>“<?php echo momentEscape($momentSearch); ?>”</strong><?php endif; ?>
                </span>
                <a href="<?php echo momentEscape($momentHref(['topic' => null, 'q' => null])); ?>" title="Clear filters"><i class="fa-solid fa-xmark"></i> Clear</a>
            </p>
            <?php endif; ?>

            <?php if ($momentTopicCloud): ?>
            <div class="moments-topics-bar">
                <span class="moments-topics-label"><i class="fa-solid fa-hashtag"></i> Popular topics</span>
                <?php foreach ($momentTopicCloud as $momentCloudTopic): ?>
                <a href="<?php echo momentEscape($momentHref(['topic' => $momentCloudTopic])); ?>"
                   class="<?php echo $momentFilterTopic === $momentCloudTopic ? 'is-active' : ''; ?>">#<?php echo momentEscape($momentCloudTopic); ?></a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if (!$momentPosts): ?>
                <div class="shop-empty">
                    <i class="fa-solid fa-camera-retro"></i>
                    <p><?php echo ($momentFilterCat || $momentFilterTopic || $momentSearch !== '') ? 'No posts match this filter yet.' : 'No BreadMoments yet — be the first to share what you ordered.'; ?></p>
                    <?php if ($momentIsSignedIn): ?>
                    <button type="button" class="btn btn-primary" data-open-moment><i class="fa-solid fa-plus"></i> Create the first post</button>
                    <?php else: ?>
                    <a href="/BreadBreak/login.php?redirect=moments" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Create the first post</a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="moments-grid">
                    <?php foreach ($momentPosts as $momentPost):
                        $momentCardPhoto = $momentFirstPhotos[$momentPost['id']] ?? 0;
                        $momentPostInitial = strtoupper(substr(trim((string) $momentPost['first_name']), 0, 1)) ?: '?';
                        $momentCardPromo = ($momentPost['post_type'] ?? 'moment') === 'promotion';
                    ?>
                    <a class="moment-card<?php echo $momentCardPromo ? ' is-promotion' : ''; ?>" href="/BreadBreak/moment.php?id=<?php echo (int) $momentPost['id']; ?>">
                        <span class="moment-card-media">
                            <?php if ($momentCardPromo): ?>
                            <span class="moment-promo-badge"><i class="fa-solid fa-bullhorn"></i> Promotion</span>
                            <?php endif; ?>
                            <?php if ($momentCardPhoto): ?>
                            <img src="/BreadBreak/api/moment-image.php?id=<?php echo (int) $momentCardPhoto; ?>"
                                 alt="<?php echo momentEscape($momentPost['title']); ?>"
                                 loading="lazy" />
                            <?php else: ?>
                            <i class="fa-solid fa-bread-slice" aria-hidden="true"></i>
                            <?php endif; ?>
                        </span>
                        <span class="moment-card-title"><?php echo momentEscape($momentPost['title']); ?></span>
                        <?php if ($momentCardPromo && !empty($momentPost['product_name'])): ?>
                        <span class="moment-promo-product"><i class="fa-solid fa-star"></i> <?php echo momentEscape($momentPost['product_name']); ?></span>
                        <?php endif; ?>
                        <span class="moment-card-foot">
                            <?php if ($momentCardPromo): ?>
                            <span class="moment-avatar moment-avatar-store" aria-hidden="true"><i class="fa-solid fa-store"></i></span>
                            <span class="moment-author"><?php echo momentEscape(MOMENT_STORE_NAME); ?></span>
                            <span class="moment-posted-by">Posted by store</span>
                            <?php elseif (!empty($momentPost['profile_data']) && !empty($momentPost['profile_mime'])): ?>
                            <span class="moment-avatar"><img src="data:<?php echo momentEscape($momentPost['profile_mime']); ?>;base64,<?php echo base64_encode($momentPost['profile_data']); ?>" alt="<?php echo momentEscape($momentPost['first_name']); ?>" /></span>
                            <span class="moment-author"><?php echo momentEscape($momentPost['first_name']); ?></span>
                            <?php else: ?>
                            <span class="moment-avatar" aria-hidden="true"><?php echo momentEscape($momentPostInitial); ?></span>
                            <span class="moment-author"><?php echo momentEscape($momentPost['first_name']); ?></span>
                            <?php endif; ?>
                            <span class="moment-views" title="Views"><i class="fa-solid fa-eye"></i> <?php echo (int) $momentPost['views_count']; ?></span>
                        </span>
                    </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>
</main>

<!-- ── Create / edit post modal ───────────────────────────────────────── -->
<div class="moment-modal-backdrop" id="momentBackdrop" hidden></div>
<div class="moment-modal" id="momentModal" role="dialog" aria-modal="true" aria-labelledby="momentModalTitle" hidden>
    <div class="moment-modal-head">
        <div>
            <span class="eyebrow">BreadMoments</span>
            <h2 id="momentModalTitle"><?php echo $momentIsEdit ? 'Edit post' : 'Create post'; ?></h2>
        </div>
        <div class="moment-modal-tools">
            <button type="button" class="moment-tips-toggle" id="momentTipsToggle" aria-expanded="false" aria-controls="momentTips">
                <i class="fa-solid fa-circle-info"></i> Tips
            </button>
            <button type="button" class="moment-modal-close" id="momentModalClose" aria-label="Close">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
    </div>

    <!-- Tips -->
    <div class="moment-tips" id="momentTips" hidden>
        <p class="moment-tips-flag"><i class="fa-solid fa-circle-info"></i> <strong>Your post will be shared publicly.</strong></p>

        <h3><i class="fa-solid fa-lightbulb"></i> Tips for creating posts</h3>
        <ul class="moment-tips-list">
            <?php foreach ($momentTips as $momentTip): ?>
            <li><?php echo momentEscape($momentTip); ?></li>
            <?php endforeach; ?>
        </ul>

        <h3><i class="fa-solid fa-user-shield"></i> Good to know</h3>
        <ul class="moment-tips-list">
            <?php foreach ($momentNotes as $momentNote): ?>
            <li><?php echo momentEscape($momentNote); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>

    <?php if ($momentIsSignedIn): ?>
    <form class="moment-form" method="POST" enctype="multipart/form-data" id="momentForm">
        <input type="hidden" name="action" value="<?php echo $momentIsEdit ? 'edit' : 'create'; ?>" />
        <?php if ($momentIsEdit): ?>
        <input type="hidden" name="moment_id" value="<?php echo (int) $momentEditId; ?>" />
        <input type="hidden" name="next" value="<?php echo $momentNext === 'moment' ? 'moment' : 'feed'; ?>" />
        <input type="hidden" id="momentKeepPhotos" name="keep_photos" value="<?php echo momentEscape($momentEditKeep); ?>" />
        <?php endif; ?>
        <div class="moment-trap" aria-hidden="true">
            <label for="moment-website">Leave this field empty</label>
            <input type="text" id="moment-website" name="website" tabindex="-1" autocomplete="off" />
        </div>

        <!-- Photos -->
        <div class="moment-field">
            <span class="moment-label"><?php echo $momentIsEdit ? 'Photos' : 'Upload photos'; ?></span>
            <div class="moment-photo-actions">
                <button type="button" class="moment-photo-btn" id="momentCameraBtn">
                    <i class="fa-solid fa-camera"></i> Camera
                </button>
                <button type="button" class="moment-photo-btn" id="momentGalleryBtn">
                    <i class="fa-solid fa-images"></i> Photo gallery
                </button>
                <span class="moment-photo-hint">JPG, PNG or WEBP · up to <?php echo MOMENT_MAX_PHOTOS; ?> photos · 8MB each</span>
            </div>
            <input type="file" id="momentPhotos" name="photos[]"
                   accept="image/jpeg,image/png,image/webp" multiple hidden />
            <div class="moment-previews" id="momentExisting">
                <?php foreach ($momentEditPhotos as $momentEditPhotoId): ?>
                    <?php if ($momentKeepIdList && !in_array($momentEditPhotoId, $momentKeepIdList, true)) continue; ?>
                    <div class="moment-preview" data-photo-id="<?php echo (int) $momentEditPhotoId; ?>">
                        <img src="/BreadBreak/api/moment-image.php?id=<?php echo (int) $momentEditPhotoId; ?>" alt="Current photo" />
                        <button type="button" class="moment-preview-remove" data-remove-existing aria-label="Remove this photo">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="moment-previews" id="momentPreviews"></div>
            <span class="moment-photo-count" id="momentPhotoCount"></span>
            <?php if ($momentPhotosNote !== ''): ?>
                <span class="moment-field-error"><?php echo momentEscape($momentPhotosNote); ?></span>
            <?php endif; ?>
            <span class="moment-field-error" id="momentPhotoError"><?php echo isset($momentErrors['photos']) ? momentEscape($momentErrors['photos']) : ''; ?></span>
        </div>

        <!-- Title -->
        <div class="moment-field<?php echo isset($momentErrors['title']) ? ' has-error' : ''; ?>">
            <label for="moment-title">Title <span class="req">*</span></label>
            <input id="moment-title" name="title" type="text" maxlength="140"
                   placeholder="What stood out about the menu?"
                   value="<?php echo momentEscape($momentOld['title']); ?>"
                   aria-invalid="<?php echo isset($momentErrors['title']) ? 'true' : 'false'; ?>" />
            <span class="moment-field-error"><?php echo isset($momentErrors['title']) ? momentEscape($momentErrors['title']) : ''; ?></span>
        </div>

        <!-- Text -->
        <div class="moment-field<?php echo isset($momentErrors['body']) ? ' has-error' : ''; ?>">
            <label for="moment-body">Your moment <span class="req">*</span></label>
            <textarea id="moment-body" name="body" maxlength="2000"
                      placeholder="Recommend your fave in menu&#10;Describe the ambiance or service&#10;Tell us if the meal was worth it"
                      aria-invalid="<?php echo isset($momentErrors['body']) ? 'true' : 'false'; ?>"><?php echo momentEscape($momentOld['body']); ?></textarea>
            <span class="moment-field-error"><?php echo isset($momentErrors['body']) ? momentEscape($momentErrors['body']) : ''; ?></span>

            <div class="moment-starters">
                <span class="moment-starters-label"><i class="fa-solid fa-wand-magic-sparkles"></i> Quick starters — tap to add</span>
                <div class="moment-starter-chips">
                    <?php foreach ($momentStarters as $momentStarter): ?>
                    <button type="button" class="moment-starter" data-starter="<?php echo momentEscape($momentStarter['text']); ?>">
                        <?php echo momentEscape($momentStarter['label']); ?>
                    </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Category -->
        <div class="moment-field<?php echo isset($momentErrors['category_id']) ? ' has-error' : ''; ?>">
            <label for="moment-category">Baked in menu category <span class="req">*</span></label>
            <select id="moment-category" name="category_id"
                    aria-invalid="<?php echo isset($momentErrors['category_id']) ? 'true' : 'false'; ?>">
                <option value="">Select a menu category</option>
                <?php foreach ($momentCategories as $momentCategory): ?>
                <option value="<?php echo (int) $momentCategory['id']; ?>"<?php echo $momentOld['category_id'] === (string) $momentCategory['id'] ? ' selected' : ''; ?>>
                    <?php echo momentEscape($momentCategory['name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
            <span class="moment-field-error"><?php echo isset($momentErrors['category_id']) ? momentEscape($momentErrors['category_id']) : ''; ?></span>
        </div>

        <!-- Topics -->
        <div class="moment-field<?php echo isset($momentErrors['topics']) ? ' has-error' : ''; ?>">
            <label for="moment-topic-input"><span class="req-hash">#</span> Add a topic <span class="moment-optional">(Optional)</span></label>
            <div class="moment-topic-row">
                <input id="moment-topic-input" type="text" maxlength="32" placeholder="e.g. must-try, ambiance, pasalubong" />
                <button type="button" class="moment-topic-add" id="momentTopicAdd"><i class="fa-solid fa-plus"></i> Add</button>
            </div>
            <input type="hidden" id="momentTopics" name="topics" value="<?php echo momentEscape($momentOld['topics']); ?>" />
            <div class="moment-topic-chips" id="momentTopicChips"></div>
            <span class="moment-field-error" id="momentTopicError"><?php echo isset($momentErrors['topics']) ? momentEscape($momentErrors['topics']) : ''; ?></span>
        </div>

        <?php if (isset($momentErrors['form'])): ?>
        <p class="form-notice is-error" role="alert"><i class="fa-solid fa-circle-exclamation"></i> <?php echo momentEscape($momentErrors['form']); ?></p>
        <?php endif; ?>

        <div class="moment-form-foot">
            <p class="moment-public-note"><i class="fa-solid fa-globe"></i> Your post will be shared publicly.</p>
            <button class="btn btn-primary" type="submit">
                <i class="fa-solid fa-paper-plane"></i> <?php echo $momentIsEdit ? 'Save changes' : 'Share my post'; ?>
            </button>
        </div>
    </form>
    <?php else: ?>
    <div class="moment-signin">
        <i class="fa-solid fa-camera-retro"></i>
        <h3>Share your BreadMoment</h3>
        <p>Sign in to upload photos, pick the menu category, and post your review — it only takes a moment.</p>
        <a href="/BreadBreak/login.php?redirect=moments" class="btn btn-primary"><i class="fa-solid fa-right-to-bracket"></i> Sign in to post</a>
    </div>
    <?php endif; ?>
</div>

<script>
(function () {
    var modal = document.getElementById('momentModal');
    var backdrop = document.getElementById('momentBackdrop');
    var tips = document.getElementById('momentTips');
    var tipsToggle = document.getElementById('momentTipsToggle');

    function setTips(open) {
        tips.hidden = !open;
        tipsToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        tipsToggle.classList.toggle('is-on', open);
    }

    function openModal(showTips) {
        modal.hidden = false;
        backdrop.hidden = false;
        document.body.style.overflow = 'hidden';
        setTips(!!showTips);
        var first = modal.querySelector('#moment-title, #momentModalClose');
        if (first) first.focus();
    }

    function closeModal() {
        modal.hidden = true;
        backdrop.hidden = true;
        document.body.style.overflow = '';
    }

    Array.prototype.forEach.call(document.querySelectorAll('[data-open-moment]'), function (trigger) {
        trigger.addEventListener('click', function () {
            openModal(trigger.getAttribute('data-open-moment') === 'tips');
        });
    });

    document.getElementById('momentModalClose').addEventListener('click', closeModal);
    backdrop.addEventListener('click', closeModal);
    tipsToggle.addEventListener('click', function () { setTips(tips.hidden); });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !modal.hidden) closeModal();
    });

    /* ── Photos: Camera / gallery picker + previews (edit aware) ── */
    var photoInput = document.getElementById('momentPhotos');
    var existingWrap = document.getElementById('momentExisting');
    var previewWrap = document.getElementById('momentPreviews');
    var photoError = document.getElementById('momentPhotoError');
    var photoCount = document.getElementById('momentPhotoCount');
    var keepField = document.getElementById('momentKeepPhotos');
    var chosenPhotos = [];
    var MAX_PHOTOS = <?php echo MOMENT_MAX_PHOTOS; ?>;
    var MAX_BYTES = <?php echo MOMENT_MAX_BYTES; ?>;
    var MAX_TOPICS = <?php echo MOMENT_MAX_TOPICS; ?>;
    var ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    function keptCount() {
        return existingWrap ? existingWrap.querySelectorAll('[data-photo-id]').length : 0;
    }

    function syncKeep() {
        if (!keepField) return;
        keepField.value = Array.prototype.map.call(existingWrap.querySelectorAll('[data-photo-id]'), function (node) {
            return node.getAttribute('data-photo-id');
        }).join(',');
    }

    function syncPhotoInput() {
        if (typeof DataTransfer === 'undefined') return;
        var transfer = new DataTransfer();
        chosenPhotos.forEach(function (file) { transfer.items.add(file); });
        photoInput.files = transfer.files;
    }

    function renderPhotoPreviews() {
        previewWrap.innerHTML = '';
        chosenPhotos.forEach(function (file, index) {
            var holder = document.createElement('div');
            holder.className = 'moment-preview';

            var image = document.createElement('img');
            image.alt = file.name;
            image.src = URL.createObjectURL(file);
            image.onload = function () { URL.revokeObjectURL(image.src); };

            var remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'moment-preview-remove';
            remove.setAttribute('aria-label', 'Remove photo ' + (index + 1));
            remove.innerHTML = '<i class="fa-solid fa-xmark"></i>';
            remove.addEventListener('click', function () {
                chosenPhotos.splice(index, 1);
                syncPhotoInput();
                renderPhotoPreviews();
            });

            holder.appendChild(image);
            holder.appendChild(remove);
            previewWrap.appendChild(holder);
        });
        var total = chosenPhotos.length + keptCount();
        photoCount.textContent = total
            ? total + '/' + MAX_PHOTOS + ' photo' + (total === 1 ? '' : 's') + ' attached'
            : '';
    }

    // ✕ on a photo that already lives in the database (edit mode).
    if (existingWrap) {
    Array.prototype.forEach.call(existingWrap.querySelectorAll('[data-remove-existing]'), function (removeButton) {
        removeButton.addEventListener('click', function () {
            var holder = removeButton.closest('.moment-preview');
            if (holder) holder.remove();
            syncKeep();
            photoError.textContent = '';
            renderPhotoPreviews();
        });
    });
    }

    if (photoInput) {
    document.getElementById('momentCameraBtn').addEventListener('click', function () {
        photoInput.setAttribute('capture', 'environment');
        photoInput.click();
    });
    document.getElementById('momentGalleryBtn').addEventListener('click', function () {
        photoInput.removeAttribute('capture');
        photoInput.click();
    });

    photoInput.addEventListener('change', function () {
        photoError.textContent = '';
        var slots = MAX_PHOTOS - keptCount();
        Array.prototype.forEach.call(photoInput.files, function (file) {
            if (slots <= 0 || chosenPhotos.length >= slots) {
                photoError.textContent = 'You can attach up to ' + MAX_PHOTOS + ' photos per post.';
                return;
            }
            if (file.size > MAX_BYTES) {
                photoError.textContent = file.name + ' is larger than 8MB — please pick a smaller photo.';
                return;
            }
            if (file.type && ALLOWED_TYPES.indexOf(file.type) === -1) {
                photoError.textContent = 'Only JPG, PNG, and WEBP photos are allowed.';
                return;
            }
            var isDuplicate = chosenPhotos.some(function (existing) {
                return existing.name === file.name && existing.size === file.size && existing.lastModified === file.lastModified;
            });
            if (!isDuplicate) chosenPhotos.push(file);
        });
        photoInput.value = '';
        syncPhotoInput();
        renderPhotoPreviews();
    });

    renderPhotoPreviews();
    }

    /* ── Quick starters → insert into the text field ── */
    var bodyField = document.getElementById('moment-body');
    Array.prototype.forEach.call(document.querySelectorAll('[data-starter]'), function (chip) {
        chip.addEventListener('click', function () {
            var text = chip.getAttribute('data-starter');
            var start = bodyField.selectionStart === null ? bodyField.value.length : bodyField.selectionStart;
            var end = bodyField.selectionEnd === null ? bodyField.value.length : bodyField.selectionEnd;
            var before = bodyField.value.slice(0, start);
            var insert = (before && !/\n\s*$/.test(before) ? '\n' : '') + text;
            bodyField.value = before + insert + bodyField.value.slice(end);
            var caret = (before + insert).length;
            bodyField.focus();
            bodyField.setSelectionRange(caret, caret);
        });
    });

    /* ── Topics: #add a topic ── */
    var topicInput = document.getElementById('moment-topic-input');
    var topicsField = document.getElementById('momentTopics');
    var topicChips = document.getElementById('momentTopicChips');
    var topicError = document.getElementById('momentTopicError');

    function topicList() {
        return topicsField.value ? topicsField.value.split(',').filter(Boolean) : [];
    }

    function renderTopics() {
        var list = topicList();
        topicChips.innerHTML = '';
        list.forEach(function (topic, index) {
            var chip = document.createElement('span');
            chip.className = 'moment-topic-chip';
            chip.innerHTML = '#' + topic + '<button type="button" aria-label="Remove #' + topic + '"><i class="fa-solid fa-xmark"></i></button>';
            chip.querySelector('button').addEventListener('click', function () {
                var current = topicList();
                current.splice(index, 1);
                topicsField.value = current.join(',');
                renderTopics();
            });
            topicChips.appendChild(chip);
        });
    }

    function addTopic() {
        var cleaned = topicInput.value.replace(/#/g, '').trim().toLowerCase();
        cleaned = cleaned.replace(/[^a-z0-9\- ]/g, '').replace(/\s+/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, '');
        topicInput.value = '';
        if (!cleaned) return;
        var list = topicList();
        if (list.indexOf(cleaned) !== -1) {
            topicError.textContent = 'That topic is already added.';
            return;
        }
        if (list.length >= MAX_TOPICS) {
            topicError.textContent = 'You can add up to ' + MAX_TOPICS + ' topics.';
            return;
        }
        topicError.textContent = '';
        list.push(cleaned);
        topicsField.value = list.join(',');
        renderTopics();
    }

    if (topicInput) {
    document.getElementById('momentTopicAdd').addEventListener('click', addTopic);
    topicInput.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' || event.key === ',') {
            event.preventDefault();
            addTopic();
        }
    });
    renderTopics();
    }

    /* ── Auto-open (error, ?create=1, ?tips=1, or while editing) ── */
    <?php if ($momentAutoOpen): ?>
    openModal(<?php echo $momentAutoTips ? 'true' : 'false'; ?>);
    <?php endif; ?>
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
