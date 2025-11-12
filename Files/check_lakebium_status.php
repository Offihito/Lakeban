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
    error_log("Database connection error in cron job: " . $e->getMessage());
    exit(1);
}

// Stripe configuration
require_once __DIR__ . '/stripe-php/init.php';
\Stripe\Stripe::setApiKey('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');

// Fetch subscriptions that are active or set to cancel at period end
try {
    $stmt = $db->prepare("
        SELECT user_id, stripe_subscription_id, end_date, status, cancel_at_period_end 
        FROM lakebium 
        WHERE status = 'active' OR cancel_at_period_end = 1
    ");
    $stmt->execute();
    $subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($subscriptions as $subscription) {
        $user_id = $subscription['user_id'];
        $stripe_subscription_id = $subscription['stripe_subscription_id'];
        $end_date = $subscription['end_date'];
        $status = $subscription['status'];
        $cancel_at_period_end = $subscription['cancel_at_period_end'];

        // Check if subscription is expired based on end_date
        $is_expired = $end_date && strtotime($end_date) < time();

        // Check Stripe subscription status
        try {
            $stripe_subscription = \Stripe\Subscription::retrieve($stripe_subscription_id);
            $stripe_status = $stripe_subscription->status;

            // Update database if Stripe status is canceled or expired
            if ($stripe_status === 'canceled' || $is_expired) {
                $db->beginTransaction();
                $stmt = $db->prepare("
                    UPDATE lakebium 
                    SET status = 'cancelled', 
                        cancel_at_period_end = 0,
                        updated_at = NOW()
                    WHERE stripe_subscription_id = ? AND user_id = ?
                ");
                $stmt->execute([$stripe_subscription_id, $user_id]);
                
                // Remove Lakebium badge (badge_id = 5)
                $stmt = $db->prepare("
                    DELETE FROM user_badges 
                    WHERE user_id = ? AND badge_id = 5
                ");
                $stmt->execute([$user_id]);
                
                $db->commit();
                error_log("Subscription expired or canceled, badge removed: user_id=$user_id, subscription_id=$stripe_subscription_id");
            }
        } catch (\Stripe\Exception\InvalidRequestError $e) {
            error_log("Stripe invalid request error for subscription_id=$stripe_subscription_id: " . $e->getMessage());
            // If subscription doesn't exist in Stripe, mark as cancelled in database
            if (strpos($e->getMessage(), 'No such subscription') !== false) {
                $db->beginTransaction();
                $stmt = $db->prepare("
                    UPDATE lakebium 
                    SET status = 'cancelled', 
                        cancel_at_period_end = 0,
                        updated_at = NOW()
                    WHERE stripe_subscription_id = ? AND user_id = ?
                ");
                $stmt->execute([$stripe_subscription_id, $user_id]);
                
                // Remove Lakebium badge
                $stmt = $db->prepare("
                    DELETE FROM user_badges 
                    WHERE user_id = ? AND badge_id = 5
                ");
                $stmt->execute([$user_id]);
                
                $db->commit();
                error_log("Non-existent subscription in Stripe, marked as cancelled and badge removed: user_id=$user_id, subscription_id=$stripe_subscription_id");
            }
        } catch (\Stripe\Exception\ApiErrorException $e) {
            error_log("Stripe API error for subscription_id=$stripe_subscription_id: " . $e->getMessage());
            continue; // Skip to next subscription
        }
    }
} catch (PDOException $e) {
    error_log("Database error in cron job: " . $e->getMessage());
    exit(1);
}

exit(0);
?>