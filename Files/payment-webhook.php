<?php
// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

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
    die();
}

// Stripe configuration
require_once __DIR__ . '/stripe-php/init.php';
\Stripe\Stripe::setApiKey('your_stripe_secret_key');

// Webhook secret
$endpoint_secret = 'your_webhook_secret';

// Handle webhook
$payload = @file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];

try {
    $event = \Stripe\Webhook::constructEvent(
        $payload,
        $sig_header,
        $endpoint_secret
    );
} catch (\UnexpectedValueException $e) {
    error_log("Invalid payload: " . $e->getMessage());
    http_response_code(400);
    exit();
} catch (\Stripe\Exception\SignatureVerificationException $e) {
    error_log("Invalid signature: " . $e->getMessage());
    http_response_code(400);
    exit();
}

switch ($event->type) {
    case 'checkout.session.completed':
        $session = $event->data->object;
        $user_id = $session->metadata->user_id;
        $subscription_id = $session->metadata->subscription_id;
        
        try {
            $db->beginTransaction();
            
            // Update subscription status
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

            // Grant Lakebium badge
            $stmt = $db->prepare("
                INSERT IGNORE INTO user_badges (user_id, badge_id, created_at)
                VALUES (?, 5, NOW())
            ");
            $stmt->execute([$user_id]);
            
            $db->commit();
            error_log("Subscription activated and badge granted for user_id: $user_id");
        } catch (PDOException $e) {
            $db->rollBack();
            error_log("Database error in webhook: " . $e->getMessage());
            http_response_code(500);
            exit();
        }
        break;

    case 'invoice.payment_failed':
        $invoice = $event->data->object;
        if (isset($invoice->subscription)) {
            try {
                $stmt = $db->prepare("
                    UPDATE lakebium 
                    SET status = 'cancelled',
                        updated_at = NOW()
                    WHERE stripe_subscription_id = ?
                ");
                $stmt->execute([$invoice->subscription]);
                error_log("Subscription cancelled due to payment failure: " . $invoice->subscription);
            } catch (PDOException $e) {
                error_log("Database error in payment failure: " . $e->getMessage());
                http_response_code(500);
                exit();
            }
        }
        break;

    case 'customer.subscription.deleted':
        $subscription = $event->data->object;
        try {
            $stmt = $db->prepare("
                UPDATE lakebium 
                SET status = 'cancelled',
                    updated_at = NOW()
                WHERE stripe_subscription_id = ?
            ");
            $stmt->execute([$subscription->id]);
            error_log("Subscription cancelled: " . $subscription->id);
        } catch (PDOException $e) {
            error_log("Database error in subscription deletion: " . $e->getMessage());
            http_response_code(500);
            exit();
        }
        break;
}

http_response_code(200);
?>