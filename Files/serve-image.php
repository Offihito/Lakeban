<?php
$file = 'uploads/' . $_GET['file'];

if (file_exists($file) && preg_match('/\.(jpe?g|png|gif)$/i', $file)) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file);
    finfo_close($finfo);

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($file));
    
    // EXIF korunur çünkü dosya olduğu gibi okunuyor
    readfile($file);
    exit;
}
http_response_code(404);
echo "Resim bulunamadı.";
?>