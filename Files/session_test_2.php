<?php
session_start();
echo 'Son Aktivite: ' . date('Y-m-d H:i:s', $_SESSION['_last_activity']);
echo '<br>Oturum Süresi: ' . (time() - $_SESSION['_last_activity']) . ' saniye';