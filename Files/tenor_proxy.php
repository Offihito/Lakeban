<?php
header('Content-Type: application/json');

$apiKey = 'AIzaSyBXKEN0w8eMMdj-LAHmvYdoHVIADunht5c'; // Tenor API anahtarınızı buraya ekleyin
$query = isset($_GET['query']) ? urlencode($_GET['query']) : '';
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;

if (empty($query)) {
    echo json_encode(['success' => false, 'error' => 'Arama terimi gerekli']);
    exit;
}

$url = "https://tenor.googleapis.com/v2/search?q=$query&key=$apiKey&limit=$limit";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 200) {
    echo $response;
} else {
    echo json_encode(['success' => false, 'error' => 'Tenor API hatası', 'status' => $httpCode]);
}
?>