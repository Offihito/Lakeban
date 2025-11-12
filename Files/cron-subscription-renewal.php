<?php
require_once 'config2.php'; // Include database configuration

// Find subscriptions expiring soon
$stmt = $db->prepare("
    SELECT s.id, s.user_id, s.plan_type, u.email
    FROM subscriptions s
    JOIN users u ON s.user_id = u.id
    WHERE s.status = 'active' 
    AND s.end_date <= DATE_ADD(NOW(), INTERVAL 3 DAY)
    AND s.plan_type IN ('monthly', 'yearly')
");
$stmt->execute();
$subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($subscriptions as $sub) {
    $amount = $sub['plan_type'] === 'monthly' ? 35 : 336; // Adjust based on pricing
    $result = createPayoneerPayment($sub['plan_type'], $amount, $sub['user_id'], $db);
    
    if ($result['success']) {
        // Optionally send email with payment link
        $payment_url = $result['payment_url'];
        $to = $sub['email'];
        $subject = "LakeBan Subscription Renewal";
        $message = "Your subscription is expiring soon. Please complete your payment: $payment_url";
        mail($to, $subject, $message);
    }
}