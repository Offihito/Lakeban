<?php
session_start();

// Log cancellation
error_log("Payment cancelled for user_id: " . ($_SESSION['user_id'] ?? 'unknown'));

// Redirect to lakebium.php with cancellation message
header('Location: /lakebium.php?error=payment_cancelled');
exit;
?>