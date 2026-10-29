<?php
session_set_cookie_params([
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
]);
session_start();

require_once __DIR__ . '/config/database.php';

$subscriptionName = '';
$subscriptionError = '';
$isSubscribed = false;
$isSubmitted = $_SERVER['REQUEST_METHOD'] === 'POST';

if (empty($_SESSION['newsletter_csrf'])) {
    $_SESSION['newsletter_csrf'] = bin2hex(random_bytes(32));
}

if ($isSubmitted) {
    $nameInput = $_POST['name'] ?? null;
    $emailInput = $_POST['email'] ?? null;
    $validInputTypes = is_string($nameInput) && is_string($emailInput);
    $subscriptionName = is_string($nameInput) ? trim($nameInput) : '';
    $email = is_string($emailInput) ? trim($emailInput) : '';
    $csrfToken = $_POST['csrf_token'] ?? '';
    $consentGiven = ($_POST['consent'] ?? null) === '1';

    if (!is_string($csrfToken) || !hash_equals($_SESSION['newsletter_csrf'], $csrfToken)) {
        $subscriptionError = 'Je sessie is verlopen. Vernieuw de pagina en probeer het opnieuw.';
    } elseif (!empty($_POST['website'])) {
        $isSubscribed = true;
    } elseif (!$validInputTypes || $subscriptionName === '' || mb_strlen($subscriptionName) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254 || !$consentGiven) {
        $subscriptionError = 'Controleer je naam, e-mailadres en toestemming.';
    } else {
        try {
            $database = newsletterDatabase();
            $statement = $database->prepare(
                'INSERT INTO newsletter_subscribers (name, email, consent_at) VALUES (:name, :email, :consent_at)'
            );
            $statement->execute([
                'name' => $subscriptionName,
                'email' => $email,
                'consent_at' => gmdate('Y-m-d H:i:s'),
            ]);
            $isSubscribed = true;
            try {
                syncNewsletterSubscriberFile($database);
            } catch (Throwable $exception) {
                error_log('Newsletter subscriber file sync error: ' . $exception->getMessage());
            }
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                $subscriptionError = 'Dit e-mailadres is al ingeschreven.';
            } else {
                error_log('Newsletter database error: ' . $exception->getMessage());
                $subscriptionError = 'Inschrijven is nu niet mogelijk. Probeer het later opnieuw.';
            }
        } catch (RuntimeException $exception) {
            error_log('Newsletter configuration error: ' . $exception->getMessage());
            $subscriptionError = 'Inschrijven is nu niet mogelijk. Probeer het later opnieuw.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="nl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nieuwsbrief | LevelUp Hardware</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
</head>

<body>
<?php require_once 'header.php'; ?>

<div class="title2">
    <h1>Nieuwsbrief</h1>
    <h2>Schrijf je in voor de nieuwsbrief!</h2>
</div>

<main class="newsletter-page">
    <?php if ($isSubscribed): ?>
        <p class="form-message success" role="status">
            <?= htmlspecialchars($subscriptionName, ENT_QUOTES, 'UTF-8') ?>, je bent ingeschreven voor de nieuwsbrief.
        </p>
    <?php else: ?>
        <p class="newsletter-intro">Ontvang nieuws over nieuwe hardware, aanbiedingen en gaming.</p>
        <?php if ($subscriptionError !== ''): ?>
            <p class="form-message error" role="alert"><?= htmlspecialchars($subscriptionError, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <button class="primary-button" id="openNewsletterModal" type="button">Schrijf je in voor de nieuwsbrief</button>
    <?php endif; ?>
</main>

<dialog class="newsletter-modal" id="newsletterModal" aria-labelledby="newsletterModalTitle">
    <div class="modal-heading">
        <div>
            <p class="modal-eyebrow">LEVELUP UPDATES</p>
            <h2 id="newsletterModalTitle">Blijf op de hoogte</h2>
        </div>
        <button class="modal-close" type="button" aria-label="Venster sluiten">&times;</button>
    </div>
    <p>Schrijf je in voor nieuws, hardwaretips en aanbiedingen.</p>
    <form class="newsletter-form" method="POST" action="nieuwsbrief.php">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['newsletter_csrf'], ENT_QUOTES, 'UTF-8') ?>">
        <div class="form-trap" aria-hidden="true">
            <label for="website">Laat dit veld leeg</label>
            <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
        </div>
        <label for="name">Naam</label>
        <input type="text" id="name" name="name" maxlength="100" autocomplete="name" required>
        <label for="email">E-mailadres</label>
        <input type="email" id="email" name="email" maxlength="254" placeholder="jouw@email.nl" autocomplete="email" required>
        <label class="newsletter-consent" for="consent">
            <input type="checkbox" id="consent" name="consent" value="1" required>
            <span>Ik geef toestemming om mijn gegevens te gebruiken voor de nieuwsbrief.</span>
        </label>
        <button class="primary-button" type="submit">Aanmelden</button>
    </form>
</dialog>
</body>
</html>