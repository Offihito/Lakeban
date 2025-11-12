<?php
// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

// Session settings
ini_set('session.gc_maxlifetime', 2592000);
session_set_cookie_params(2592000);
session_start();

// Database connection
define('DB_HOST', 'localhost');
define('DB_USER', 'lakebanc_Offihito');
define('DB_PASS', 'P4QG(m2jkWXN');
define('DB_NAME', 'lakebanc_Database');

try {
    $db = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    error_log("Database connection error: " . $e->getMessage());
    http_response_code(500);
    die("Veritabanı bağlantı hatası. Lütfen tekrar deneyin.");
}

// Stripe configuration
require_once __DIR__ . '/stripe-php/init.php';
\Stripe\Stripe::setApiKey('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');

// Verify checkout session
$session_id = $_GET['session_id'] ?? null;

if (!$session_id) {
    error_log("No session_id provided in payment-success.php");
    header('Location: /lakebium.php?error=no_session_id');
    exit;
}

try {
    // Retrieve the Checkout Session from Stripe
    $session = \Stripe\Checkout\Session::retrieve($session_id, [
        'expand' => ['subscription']
    ]);

    // Check if metadata exists
    if (!isset($session->metadata) || !isset($session->metadata->user_id) || !isset($session->metadata->subscription_id)) {
        error_log("Missing metadata in session: session_id=$session_id");
        header('Location: /lakebium.php?error=missing_metadata');
        exit;
    }

    $user_id = $session->metadata->user_id;
    $subscription_id = $session->metadata->subscription_id;

    // Verify user is logged in
    if (!isset($_SESSION['user_id']) || $_SESSION['user_id'] != $user_id) {
        error_log("Session user_id mismatch or not logged in: session_user_id=$user_id, session_user_id=" . ($_SESSION['user_id'] ?? 'none'));
        header('Location: /directmessages?redirect=/payment-success.php?session_id=' . urlencode($session_id));
        exit;
    }

    // Check subscription status
    $stmt = $db->prepare("SELECT status FROM lakebium WHERE id = ? AND user_id = ?");
    $stmt->execute([$subscription_id, $user_id]);
    $subscription = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$subscription) {
        error_log("Subscription not found: subscription_id=$subscription_id, user_id=$user_id");
        header('Location: /lakebium.php?error=subscription_not_found');
        exit;
    }

    if ($subscription['status'] === 'active') {
        // Subscription already processed by webhook
        error_log("Subscription already active for session_id=$session_id, user_id=$user_id");
        header('Location: /lakebium.php?success=subscription_active');
        exit;
    }

    // Fallback: Update subscription status if not already active
    $db->beginTransaction();
    $stmt = $db->prepare("
        UPDATE lakebium 
        SET status = 'active',
            stripe_subscription_id = ?,
            updated_at = NOW()
        WHERE id = ? AND user_id = ?
    ");
    $stmt->execute([
        $session->subscription ?? $session->id,
        $subscription_id,
        $user_id
    ]);

    // Grant Lakebium badge if not already granted
    $stmt = $db->prepare("
        INSERT IGNORE INTO user_badges (user_id, badge_id, created_at)
        VALUES (?, 5, NOW())
    ");
    $stmt->execute([$user_id]);

    $db->commit();
    error_log("Payment success processed for session_id=$session_id, user_id=$user_id");

    header('Location: /lakebium.php?success=payment_completed');
    exit;

} catch (\Stripe\Exception\ApiErrorException $e) {
    error_log("Stripe API error in payment-success.php: " . $e->getMessage());
    header('Location: /lakebium.php?error=stripe_error&message=' . urlencode($e->getMessage()));
    exit;
} catch (PDOException $e) {
    $db->rollBack();
    error_log("Database error in payment-success.php: " . $e->getMessage());
    header('Location: /lakebium.php?error=database_error');
    exit;
}
?>