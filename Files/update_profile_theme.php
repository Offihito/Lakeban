<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$user_id = $_SESSION['user_id'];

$profile_header_color = isset($_POST['profile_header_color']) ? $_POST['profile_header_color'] : null;
$profile_text_color = isset($_POST['profile_text_color']) ? $_POST['profile_text_color'] : null;
$profile_button_color = isset($_POST['profile_button_color']) ? $_POST['profile_button_color'] : null;
$snow_effect = isset($_POST['snow_effect']) ? 1 : 0;

if ($profile_header_color && $profile_text_color && $profile_button_color) {
    $stmt = $conn->prepare("UPDATE user_profiles SET profile_header_color = ?, profile_text_color = ?, profile_button_color = ?, snow_effect = ? WHERE user_id = ?");
    $stmt->bind_param("sssii", $profile_header_color, $profile_text_color, $profile_button_color, $snow_effect, $user_id);
    $stmt->execute();
    $stmt->close();
}

header("Location: profile-page.php?username=" . $_SESSION['username']);
exit;
?>