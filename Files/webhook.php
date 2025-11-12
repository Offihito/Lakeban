<?php
require_once 'stripe-php/init.php';
require_once 'webhookconfig.php'; // Konfigürasyon dosyasını dahil et
\Stripe\Stripe::setApiKey(STRIPE_API_KEY);

$payload = @file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];
$event = null;

try {
    $event = \Stripe\Webhook::constructEvent(
        $payload,
        $sig_header,
        STRIPE_WEBHOOK_SECRET
    );
} catch (\UnexpectedValueException $e) {
    // Geçersiz payload
    http_response_code(400);
    exit();
} catch (\Stripe\Exception\SignatureVerificationException $e) {
    // Geçersiz imza
    http_response_code(400);
    exit();
}

// Webhook olaylarını işleme
switch ($event->type) {
    case 'invoice.payment_succeeded':
        $subscriptionId = $event->data->object->subscription;
        $pdo = new PDO('mysql:host=localhost;dbname=lakebanc_Database', 'lakebanc_Offihito', 'P4QG(m2jkWXN');
        $stmt = $pdo->prepare('UPDATE stripe_subscriptions SET status = ? WHERE stripe_subscription_id = ?');
        $stmt->execute(['active', $subscriptionId]);
        break;
    case 'invoice.payment_failed':
        // Ödeme başarısız, durumu güncelle
        break;
}

http_response_code(200);