<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database connection
$db_host = 'localhost';
$db_user = 'lakebanc_Offihito';
$db_pass = 'P4QG(m2jkWXN';
$db_name = 'lakebanc_Database';

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_TIMEOUT => 5,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
];

try {
    $db = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass, $options);
} catch (PDOException $e) {
    error_log("Database connection error: " . $e->getMessage());
    die("Unable to connect to the database. Please try again later.");
}

// Fetch banned users
$stmt = $db->prepare("
    SELECT banned_users.*, users.username as banned_user_name, banned_by_user.username as banned_by_name 
    FROM banned_users 
    JOIN users ON banned_users.user_id = users.id 
    JOIN users as banned_by_user ON banned_users.banned_by = banned_by_user.id
");
$stmt->execute();
$banned_users = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Banned Users</title>
</head>
<body>
    <h1>Banned Users</h1>
    <table border="1">
        <thead>
            <tr>
                <th>User</th>
                <th>Banned By</th>
                <th>Reason</th>
                <th>Banned At</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($banned_users as $banned_user): ?>
                <tr>
                    <td><?php echo htmlspecialchars($banned_user['banned_user_name']); ?></td>
                    <td><?php echo htmlspecialchars($banned_user['banned_by_name']); ?></td>
                    <td><?php echo htmlspecialchars($banned_user['reason']); ?></td>
                    <td><?php echo htmlspecialchars($banned_user['banned_at']); ?></td>
                    <td>
                        <button onclick="unbanUser(<?php echo $banned_user['user_id']; ?>)">Unban</button>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
     <script>
    function unbanUser(userId) {
    fetch('unban_user.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `user_id=${userId}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('User unbanned successfully.');
            // Optionally, refresh the banned users list
            window.location.reload();
        } else {
            alert('Failed to unban user: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Failed to unban user. Please try again.');
    });
}
</script>
</body>
</html>