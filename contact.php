<?php
$pageTitle = 'Contact | BreadBreak';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$contactSubjects = [
    'Order inquiry',
    'Delivery & pickup question',
    'Product availability',
    'Feedback',
    'Partnership',
    'Other',
];

$contactLen = static fn (string $value): int => function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
$contactCut = static fn (string $value, int $max): string => function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);

$contactErrors = [];
$contactOld = [
    'name'    => '',
    'email'   => '',
    'subject' => $contactSubjects[0],
    'message' => '',
];

// Signed-in customers get their details filled in automatically.
if (!empty($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'customer') {
    $contactOld['name']  = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
    $contactOld['email'] = (string) ($_SESSION['email'] ?? '');
}

$contactDirectionsUrl = 'https://www.google.com/maps/search/?api=1&query=Bread+Break+Estrella+Village+Guiguinto+Bulacan';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $contactOld['name']    = trim((string) ($_POST['name'] ?? ''));
    $contactOld['email']   = trim((string) ($_POST['email'] ?? ''));
    $contactOld['subject'] = trim((string) ($_POST['subject'] ?? ''));
    $contactOld['message'] = trim((string) ($_POST['message'] ?? ''));

    // Spam trap: bots fill the hidden field, humans never see it.
    $contactTrap = trim((string) ($_POST['website'] ?? ''));

    if ($contactOld['name'] === '') {
        $contactErrors['name'] = 'Please tell us your name.';
    } elseif ($contactLen($contactOld['name']) > 120) {
        $contactErrors['name'] = 'Please keep your name under 120 characters.';
    }

    if ($contactOld['email'] === '') {
        $contactErrors['email'] = 'Please enter your email so we can reply.';
    } elseif (!filter_var($contactOld['email'], FILTER_VALIDATE_EMAIL)) {
        $contactErrors['email'] = 'That email address does not look valid.';
    }

    if (!in_array($contactOld['subject'], $contactSubjects, true)) {
        $contactErrors['subject'] = 'Please choose what your message is about.';
    }

    if ($contactOld['message'] === '') {
        $contactErrors['message'] = 'Please write your message.';
    } elseif ($contactLen($contactOld['message']) < 10) {
        $contactErrors['message'] = 'A few more details, please (at least 10 characters).';
    } elseif ($contactLen($contactOld['message']) > 2000) {
        $contactErrors['message'] = 'Please keep your message under 2,000 characters.';
    }

    if (!$contactErrors && $contactTrap !== '') {
        // Honeypot tripped — pretend success, store nothing.
        $_SESSION['contact_notice'] = 'Thanks! Your message has been received.';
        header('Location: /BreadBreak/contact.php');
        exit;
    }

    if (!$contactErrors) {
        try {
            require_once __DIR__ . '/config/database.php';
            $contactPdo = getDatabaseConnection();
            $contactPdo->exec("CREATE TABLE IF NOT EXISTS contact_messages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(120) NOT NULL,
                email VARCHAR(190) NOT NULL,
                subject VARCHAR(120) NOT NULL,
                message TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $contactInsert = $contactPdo->prepare(
                'INSERT INTO contact_messages (name, email, subject, message) VALUES (:name, :email, :subject, :message)'
            );
            $contactInsert->execute([
                'name'    => $contactCut($contactOld['name'], 120),
                'email'   => $contactCut($contactOld['email'], 190),
                'subject' => $contactOld['subject'],
                'message' => $contactOld['message'],
            ]);

            $contactFirstName = explode(' ', $contactOld['name'])[0];
            $_SESSION['contact_notice'] = 'Salamat, ' . $contactFirstName . '! We received your message about “' . $contactOld['subject'] . '” and will reply to ' . $contactOld['email'] . ' within 24 hours.';
            header('Location: /BreadBreak/contact.php');
            exit;
        } catch (Throwable $contactDbException) {
            $contactErrors['form'] = 'We could not send your message just yet. Please try again in a moment.';
        }
    }
}

$contactNotice = (string) ($_SESSION['contact_notice'] ?? '');
unset($_SESSION['contact_notice']);
?>

<?php include __DIR__ . '/includes/header.php'; ?>

<main class="contact-page">

    <!-- ── Hero ─────────────────────────────────────────────────────────── -->
    <section class="banner-hero contact-hero">
        <div class="container">
            <div class="banner-hero-copy">
                <span class="eyebrow eyebrow-light">Contact Us</span>
                <h1>We’d love to hear from you.</h1>
                <p>
                    Reach out for orders, questions, and bakery inquiries — a cake for a special
                    day, a delivery concern, or simply what’s fresh from the oven today.
                </p>

                <div class="banner-hero-actions">
                    <a href="#message" class="btn btn-primary">
                        <i class="fa-solid fa-paper-plane"></i> Send a Message
                    </a>
                    <a href="tel:+639339180174" class="btn btn-secondary">
                        <i class="fa-solid fa-phone"></i> +63 933 918 0174
                    </a>
                </div>
            </div>

            <ul class="banner-stats" aria-label="BreadBreak at a glance">
                <li class="banner-stat">
                    <strong>7AM–8PM</strong>
                    <span>Open Monday to Sunday</span>
                </li>
                <li class="banner-stat">
                    <strong>30–45 min</strong>
                    <span>Order preparation</span>
                </li>
                <li class="banner-stat">
                    <strong>8 km</strong>
                    <span>Delivery coverage</span>
                </li>
                <li class="banner-stat">
                    <strong>24 hrs</strong>
                    <span>Typical reply time</span>
                </li>
            </ul>
        </div>
    </section>

    <!-- ── Contact details + message form ───────────────────────────────── -->
    <section class="section" id="message">
        <div class="container">
            <div class="contact-layout">

                <div class="contact-info">
                    <span class="eyebrow">Get in touch</span>
                    <h2>Reach us any way you like</h2>
                    <p>
                        Questions about an order, a delivery, or a cake for a special day? Send the
                        form a message or reach out through any channel below.
                    </p>

                    <div class="visit-info">
                        <div class="visit-info-item">
                            <i class="fa-solid fa-location-dot"></i>
                            <div>
                                <strong>Branch address</strong>
                                <span>Estrella Village, Guiguinto, Bulacan</span>
                            </div>
                        </div>

                        <div class="visit-info-item">
                            <i class="fa-solid fa-phone"></i>
                            <div>
                                <strong>Phone</strong>
                                <span><a href="tel:+639339180174">+63 933 918 0174</a></span>
                            </div>
                        </div>

                        <div class="visit-info-item">
                            <i class="fa-solid fa-envelope"></i>
                            <div>
                                <strong>Email</strong>
                                <span><a href="mailto:breadbreak@example.com">breadbreak@example.com</a></span>
                            </div>
                        </div>

                        <div class="visit-info-item">
                            <i class="fa-solid fa-clock"></i>
                            <div>
                                <strong>Operating hours</strong>
                                <span>7:00 AM – 8:00 PM, Monday to Sunday</span>
                            </div>
                        </div>
                    </div>

                    <div class="contact-social">
                        <a href="https://www.facebook.com/profile.php?id=100066227186528" target="_blank" rel="noopener noreferrer">
                            <i class="fa-brands fa-facebook-f"></i> Facebook
                        </a>
                        <a href="https://www.instagram.com/breadbreakph/" target="_blank" rel="noopener noreferrer">
                            <i class="fa-brands fa-instagram"></i> Instagram
                        </a>
                        <a href="https://www.tiktok.com/@breadbreakph" target="_blank" rel="noopener noreferrer">
                            <i class="fa-brands fa-tiktok"></i> TikTok
                        </a>
                    </div>
                </div>

                <div class="contact-form-card">
                    <h2>Send us a message</h2>
                    <p class="contact-form-intro">Fill this in and we’ll get back to you by email — usually within a day.</p>

                    <?php if ($contactNotice !== ''): ?>
                        <p class="form-notice is-success" role="status"><i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($contactNotice); ?></p>
                    <?php endif; ?>
                    <?php if (isset($contactErrors['form'])): ?>
                        <p class="form-notice is-error" role="alert"><i class="fa-solid fa-circle-exclamation"></i> <?php echo htmlspecialchars($contactErrors['form']); ?></p>
                    <?php endif; ?>

                    <form class="contact-form" method="POST" novalidate>
                        <div class="contact-field contact-trap" aria-hidden="true">
                            <label for="website">Leave this field empty</label>
                            <input type="text" id="website" name="website" tabindex="-1" autocomplete="off" />
                        </div>

                        <div class="contact-form-row">
                            <div class="contact-field<?php echo isset($contactErrors['name']) ? ' has-error' : ''; ?>">
                                <label for="contact-name">Your name <span class="req">*</span></label>
                                <input id="contact-name" name="name" type="text" maxlength="120" placeholder="Juan Dela Cruz" value="<?php echo htmlspecialchars($contactOld['name']); ?>" aria-invalid="<?php echo isset($contactErrors['name']) ? 'true' : 'false'; ?>" />
                                <span class="contact-field-error"><?php echo htmlspecialchars($contactErrors['name'] ?? ''); ?></span>
                            </div>

                            <div class="contact-field<?php echo isset($contactErrors['email']) ? ' has-error' : ''; ?>">
                                <label for="contact-email">Email address <span class="req">*</span></label>
                                <input id="contact-email" name="email" type="email" maxlength="190" placeholder="you@example.com" value="<?php echo htmlspecialchars($contactOld['email']); ?>" aria-invalid="<?php echo isset($contactErrors['email']) ? 'true' : 'false'; ?>" />
                                <span class="contact-field-error"><?php echo htmlspecialchars($contactErrors['email'] ?? ''); ?></span>
                            </div>
                        </div>

                        <div class="contact-field<?php echo isset($contactErrors['subject']) ? ' has-error' : ''; ?>">
                            <label for="contact-subject">What is this about? <span class="req">*</span></label>
                            <select id="contact-subject" name="subject" aria-invalid="<?php echo isset($contactErrors['subject']) ? 'true' : 'false'; ?>">
                                <?php foreach ($contactSubjects as $contactSubjectOption): ?>
                                <option value="<?php echo htmlspecialchars($contactSubjectOption); ?>"<?php echo $contactOld['subject'] === $contactSubjectOption ? ' selected' : ''; ?>><?php echo htmlspecialchars($contactSubjectOption); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span class="contact-field-error"><?php echo htmlspecialchars($contactErrors['subject'] ?? ''); ?></span>
                        </div>

                        <div class="contact-field<?php echo isset($contactErrors['message']) ? ' has-error' : ''; ?>">
                            <label for="contact-message">Message <span class="req">*</span></label>
                            <textarea id="contact-message" name="message" maxlength="2000" placeholder="Tell us how we can help — include your order number if you have one." aria-invalid="<?php echo isset($contactErrors['message']) ? 'true' : 'false'; ?>"><?php echo htmlspecialchars($contactOld['message']); ?></textarea>
                            <span class="contact-field-error"><?php echo htmlspecialchars($contactErrors['message'] ?? ''); ?></span>
                        </div>

                        <div class="contact-form-foot">
                            <p class="contact-form-note"><i class="fa-solid fa-shield-heart"></i> Your details are only used to reply to this message.</p>
                            <button class="btn btn-primary" type="submit"><i class="fa-solid fa-paper-plane"></i> Send Message</button>
                        </div>
                    </form>
                </div>

            </div>
        </div>
    </section>

    <!-- ── Map ──────────────────────────────────────────────────────────── -->
    <section class="section alt-bg">
        <div class="container">
            <div class="section-heading center">
                <span class="eyebrow">Visit BreadBreak</span>
                <h2>Find us in Guiguinto</h2>
                <p class="section-subtitle">Pick up your order at the branch — or have it delivered within 8 km.</p>
            </div>

            <div class="contact-map-wrap">
                <div class="visit-map">
                    <iframe
                        src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3856.89935785534!2d120.86967517519058!3d14.830905485683221!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x339653f14f7970b1%3A0x6472797c39ec3f75!2sBread%20Break!5e0!3m2!1sen!2sph!4v1789883477934!5m2!1sen!2sph"
                        loading="lazy"
                        allowfullscreen=""
                        referrerpolicy="strict-origin-when-cross-origin"
                        title="BreadBreak branch location on Google Maps">
                    </iframe>
                    <div class="visit-map-caption">
                        <span><i class="fa-solid fa-map-pin"></i> Estrella Village, Guiguinto, Bulacan</span>
                        <a href="<?php echo htmlspecialchars($contactDirectionsUrl); ?>" target="_blank" rel="noopener noreferrer">
                            Get directions <i class="fa-solid fa-arrow-up-right-from-square"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
