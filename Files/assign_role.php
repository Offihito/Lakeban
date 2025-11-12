<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'database/db_connection.php';

// Kullanıcının tema ayarlarını al
$currentTheme = 'dark';
$currentCustomColor = '#663399';
$currentSecondaryColor = '#3CB371';

try {
    $theme_stmt = $db->prepare("SELECT theme, custom_color, secondary_color FROM users WHERE id = ?");
    $theme_stmt->execute([$_SESSION['user_id']]);
    $userTheme = $theme_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($userTheme) {
        $currentTheme = $userTheme['theme'] ?? 'dark';
        $currentCustomColor = $userTheme['custom_color'] ?? '#663399';
        $currentSecondaryColor = $userTheme['secondary_color'] ?? '#3CB371';
    }
} catch (PDOException $e) {
    error_log("Theme settings error: " . $e->getMessage());
}

// Session check
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit;
}

// Get server ID from URL
if (!isset($_GET['id'])) {
    die("Sunucu ID'si eksik.");
}

$server_id = $_GET['id'];

// Check if the user is the owner or has manage_roles permission
$stmt = $db->prepare("SELECT * FROM servers WHERE id = ? AND (owner_id = ? OR id IN (SELECT server_id FROM user_roles WHERE user_id = ? AND role_id IN (SELECT id FROM roles WHERE permissions LIKE '%manage_roles%')))");
$stmt->execute([$server_id, $_SESSION['user_id'], $_SESSION['user_id']]);
if ($stmt->rowCount() === 0) {
    header("Location: sayfabulunamadı");
    exit();
}

// Fetch server details
$stmt = $db->prepare("SELECT * FROM servers WHERE id = ?");
$stmt->execute([$server_id]);
$server = $stmt->fetch();

// Define permission categories and descriptions
$permission_categories = [
    'Management' => [
        'manage_channels' => ['label' => 'Kanal Yönetimi', 'desc' => 'Kanalları oluştur, düzenle veya sil.'],
        'manage_roles' => ['label' => 'Rol Yönetimi', 'desc' => 'Rolleri oluştur, düzenle veya sil.'],
        'manage_server' => ['label' => 'Sunucu Yönetimi', 'desc' => 'Sunucu ayarlarını değiştir.'],
    ],
    'Moderation' => [
        'kick' => ['label' => 'Kullanıcıyı Kickle', 'desc' => 'Kullanıcıları sunucudan çıkar.'],
        'ban' => ['label' => 'Kullanıcıyı Banla', 'desc' => 'Kullanıcıları sunucudan yasakla.'],
        'manage_messages' => ['label' => 'Mesaj Yönetimi', 'desc' => 'Mesajları sil veya sabitle.'],
    ],
    'General' => [
        'view_channels' => ['label' => 'Kanalları Görüntüle', 'desc' => 'Kanalları görüntüle.'],
        'send_messages' => ['label' => 'Mesaj Gönder', 'desc' => 'Kanallara mesaj gönder.'],
        'attach_files' => ['label' => 'Dosya Ekle', 'desc' => 'Dosya ve medya yükle.'],
    ],
    'Special' => [
        'administrator' => ['label' => 'Yönetici', 'desc' => 'Tüm yetkileri verir!']
    ]
];

// Predefined role colors
$predefined_colors = [
    '#3CB371', '#5865F2', '#ED4245', '#FAA61A',
    '#57F287', '#FEE75C', '#EB459E', '#00B0F4'
];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
      if (isset($_POST['create_role'])) {
            $role_name = trim($_POST['role_name'] ?? '');
            $permissions = $_POST['permissions'] ?? [];
            $color = $_POST['color'] ?? '#3CB371';
            $category_id = empty($_POST['category_id']) ? null : (int)$_POST['category_id'];

            if (empty($role_name)) {
                throw new Exception('Rol ismi boş olamaz.');
            }

            // Find the highest importance value and add 1
            $stmt = $db->prepare("SELECT MAX(importance) AS max_importance FROM roles WHERE server_id = ? AND (category_id = ? OR (category_id IS NULL AND ? IS NULL))");
            $stmt->execute([$server_id, $category_id, $category_id]);
            $max_importance = $stmt->fetchColumn();
            $importance = ($max_importance !== null) ? $max_importance + 1 : 0;

            $stmt = $db->prepare("INSERT INTO roles (name, permissions, color, server_id, category_id, importance) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$role_name, json_encode($permissions), $color, $server_id, $category_id, $importance]);

            // Audit log entry
            $role_id = $db->lastInsertId();
            $stmt = $db->prepare("INSERT INTO role_audit_log (role_id, user_id, action, details, server_id) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$role_id, $_SESSION['user_id'], 'create_role', json_encode([
                'name' => $role_name,
                'permissions' => $permissions,
                'color' => $color,
                'category_id' => $category_id
            ]), $server_id]);

            $_SESSION['feedback'] = ['type' => 'success', 'message' => 'Rol başarıyla oluşturuldu.'];


    } elseif (isset($_POST['create_category'])) {
        $category_name = $_POST['category_name'];
        $category_color = $_POST['category_color'];

        $stmt = $db->prepare("SELECT MAX(importance) AS max_importance FROM role_categories WHERE server_id = ?");
        $stmt->execute([$server_id]);
        $max_importance = $stmt->fetchColumn();
        $importance = ($max_importance !== null) ? $max_importance + 1 : 0;

        $stmt = $db->prepare("INSERT INTO role_categories (name, color, server_id, importance) VALUES (?, ?, ?, ?)");
        $stmt->execute([$category_name, $category_color, $server_id, $importance]);

        // Audit log entry
        $stmt = $db->prepare("INSERT INTO role_audit_log (user_id, action, details, server_id) VALUES (?, ?, ?, ?)");
        $stmt->execute([$_SESSION['user_id'], 'create_category', json_encode(['name' => $category_name, 'color' => $category_color]), $server_id]);

        $_SESSION['feedback'] = ['type' => 'success', 'message' => 'Kategori başarıyla oluşturuldu.'];

    } elseif (isset($_POST['update_roles_order'])) {
        $ordered_items = json_decode($_POST['ordered_items'], true);

        try {
            $db->beginTransaction();
            foreach ($ordered_items as $cat_index => $category_data) {
                $category_id_for_roles = null;
                if ($category_data['type'] === 'category' && $category_data['id'] !== 'uncategorized') {
                    $category_id_for_roles = (int)$category_data['id'];
                    $stmt = $db->prepare("UPDATE role_categories SET importance = ? WHERE id = ? AND server_id = ?");
                    $stmt->execute([$cat_index, $category_id_for_roles, $server_id]);
                }

                if (isset($category_data['roles']) && is_array($category_data['roles'])) {
                    // Assign higher importance to roles higher in the list
                    $num_roles = count($category_data['roles']);
                    foreach ($category_data['roles'] as $role_index => $role_id) {
                        $role_id_int = (int)$role_id;
                        // Importance starts from num_roles - role_index to make top roles have higher importance
                        $importance = $num_roles - $role_index - 1;
                        $stmt = $db->prepare("UPDATE roles SET importance = ?, category_id = ? WHERE id = ? AND server_id = ?");
                        $stmt->execute([$importance, $category_id_for_roles, $role_id_int, $server_id]);
                    }
                }
            }
            $db->commit();

            // Audit log entry
            $stmt = $db->prepare("INSERT INTO role_audit_log (user_id, action, details, server_id) VALUES (?, ?, ?, ?)");
            $stmt->execute([$_SESSION['user_id'], 'update_roles_order', json_encode($ordered_items), $server_id]);

            $_SESSION['feedback'] = ['type' => 'success', 'message' => 'Sıralama başarıyla kaydedildi.'];
        } catch (PDOException $e) {
            $db->rollBack();
            error_log("Database error updating order: " . $e->getMessage());
            $_SESSION['feedback'] = ['type' => 'error', 'message' => 'Sıralama kaydedilirken bir hata oluştu: ' . $e->getMessage()];
        }
    } elseif (isset($_POST['edit_role'])) {
        $role_id = $_POST['role_id'];
        $role_name = $_POST['role_name'];
        $permissions = isset($_POST['permissions']) ? $_POST['permissions'] : [];
        $color = $_POST['color'];
        $category_id = empty($_POST['category_id']) ? null : $_POST['category_id'];

        $stmt = $db->prepare("UPDATE roles SET name = ?, permissions = ?, color = ?, category_id = ? WHERE id = ? AND server_id = ?");
        $stmt->execute([$role_name, json_encode($permissions), $color, $category_id, $role_id, $server_id]);

        // Audit log entry
        $stmt = $db->prepare("INSERT INTO role_audit_log (role_id, user_id, action, details, server_id) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$role_id, $_SESSION['user_id'], 'edit_role', json_encode(['name' => $role_name, 'permissions' => $permissions, 'color' => $color, 'category_id' => $category_id]), $server_id]);

        $_SESSION['feedback'] = ['type' => 'success', 'message' => 'Rol başarıyla güncellendi.'];

    } elseif (isset($_POST['delete_role'])) {
        $role_id = $_POST['role_id'];
        $stmt = $db->prepare("SELECT name FROM roles WHERE id = ? AND server_id = ?");
        $stmt->execute([$role_id, $server_id]);
        $role_name = $stmt->fetchColumn();

        $stmt = $db->prepare("DELETE FROM roles WHERE id = ? AND server_id = ?");
        $stmt->execute([$role_id, $server_id]);
        $stmt = $db->prepare("DELETE FROM user_roles WHERE role_id = ?");
        $stmt->execute([$role_id]);

        // Audit log entry
        $stmt = $db->prepare("INSERT INTO role_audit_log (role_id, user_id, action, details, server_id) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$role_id, $_SESSION['user_id'], 'delete_role', json_encode(['name' => $role_name]), $server_id]);

        $_SESSION['feedback'] = ['type' => 'success', 'message' => 'Rol başarıyla silindi.'];

    } elseif (isset($_POST['delete_category'])) {
        $category_id = $_POST['category_id'];
        $stmt = $db->prepare("SELECT name FROM role_categories WHERE id = ? AND server_id = ?");
        $stmt->execute([$category_id, $server_id]);
        $category_name = $stmt->fetchColumn();

        $stmt = $db->prepare("UPDATE roles SET category_id = NULL WHERE category_id = ? AND server_id = ?");
        $stmt->execute([$category_id, $server_id]);
        $stmt = $db->prepare("DELETE FROM role_categories WHERE id = ? AND server_id = ?");
        $stmt->execute([$category_id, $server_id]);

        // Audit log entry
        $stmt = $db->prepare("INSERT INTO role_audit_log (user_id, action, details, server_id) VALUES (?, ?, ?, ?)");
        $stmt->execute([$_SESSION['user_id'], 'delete_category', json_encode(['name' => $category_name]), $server_id]);

        $_SESSION['feedback'] = ['type' => 'success', 'message' => 'Kategori başarıyla silindi.'];
    }

    header("Location: assign_role?id=" . $server_id);
    exit;
}

// Fetch role categories
$stmt = $db->prepare("SELECT * FROM role_categories WHERE server_id = ? ORDER BY importance ASC, id ASC");
$stmt->execute([$server_id]);
$categories = $stmt->fetchAll();

// Fetch roles
$stmt = $db->prepare("SELECT r.*, rc.name AS category_name, rc.color AS category_color FROM roles r LEFT JOIN role_categories rc ON r.category_id = rc.id WHERE r.server_id = ? ORDER BY COALESCE(rc.importance, 999999) ASC, r.importance ASC, r.id ASC");
$stmt->execute([$server_id]);
$roles = $stmt->fetchAll();

// Group roles by category
$categorized_roles = [];
foreach ($roles as $role) {
    $categoryId = $role['category_id'] ?: 'uncategorized';
    if (!isset($categorized_roles[$categoryId])) {
        $categorized_roles[$categoryId] = [];
    }
    $categorized_roles[$categoryId][] = $role;
}

// Sort categories
usort($categories, function($a, $b) {
    return $a['importance'] - $b['importance'];
});

// Display feedback
$feedback = '';
if (isset($_SESSION['feedback'])) {
    $feedback_type = $_SESSION['feedback']['type'];
    $feedback_message = htmlspecialchars($_SESSION['feedback']['message'], ENT_QUOTES, 'UTF-8');
    $feedback = "<div class='bg-{$feedback_type}-500 text-white p-3 rounded-lg mb-4 shadow-md'>{$feedback_message}</div>";
    unset($_SESSION['feedback']);
}

// Language settings
$default_lang = 'tr';
if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
    $browser_lang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);
    $supported_languages = ['tr', 'en', 'fr', 'de', 'ru'];
    if (in_array($browser_lang, $supported_languages)) {
        $default_lang = $browser_lang;
    }
}

if (isset($_GET['lang'])) {
    $_SESSION['lang'] = $_GET['lang'];
} elseif (!isset($_SESSION['lang'])) {
    $_SESSION['lang'] = $default_lang;
}

$lang = $_SESSION['lang'];

function loadLanguage($lang) {
    $langFile = __DIR__ . '/languages/' . $lang . '.json';
    return file_exists($langFile) ? json_decode(file_get_contents($langFile), true) : [];
}

$translations = loadLanguage($lang);
?>

<!DOCTYPE html>
<html lang="tr" class="<?php echo $currentTheme; ?>-theme" style="--custom-background-color: <?php echo $currentCustomColor; ?>; --custom-secondary-color: <?php echo $currentSecondaryColor; ?>;">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $translations['server_settings']['title_two']; ?> - <?php echo htmlspecialchars($server['name'], ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="icon" type="image/x-icon" href="/icon.ico">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://code.jquery.com/ui/1.12.1/jquery-ui.min.js"></script>
    <link rel="stylesheet" href="https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css">
    <style>
        :root {
            --primary-bg: #1a1b1e;
            --secondary-bg: #2d2f34;
            --accent-color: #3CB371;
            --text-primary: #ffffff;
            --text-secondary: #b9bbbe;
            --danger-color: #ed4245;
            --success-color: #3ba55c;
            --warning-color: #faa61a;
            --scrollbar-thumb: #202225;
            --scrollbar-track: #2e3338;
        }

        /* Light Theme */
        .light-theme {
            --primary-bg: #F2F3F5;
            --secondary-bg: #FFFFFF;
            --text-primary: #2E3338;
            --text-secondary: #4F5660;
            --scrollbar-thumb: #c1c3c7;
            --scrollbar-track: #F2F3F5;
        }

        /* Custom Theme */
        .custom-theme {
            --primary-bg: color-mix(in srgb, var(--custom-background-color) 90%, var(--custom-secondary-color) 10%);
            --secondary-bg: color-mix(in srgb, var(--custom-background-color) 80%, var(--custom-secondary-color) 20%);
            --text-primary: #ffffff;
            --text-secondary: color-mix(in srgb, var(--custom-background-color) 40%, white);
            --scrollbar-thumb: color-mix(in srgb, var(--custom-background-color) 60%, var(--custom-secondary-color) 40%);
            --scrollbar-track: color-mix(in srgb, var(--custom-background-color) 70%, var(--custom-secondary-color) 30%);
            --accent-color: var(--custom-secondary-color);
        }

        body {
            background-color: var(--primary-bg);
            color: var(--text-primary);
            font-family: 'Inter', sans-serif;
            -webkit-font-smoothing: antialiased;
            overflow-x: hidden;
        }

        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        ::-webkit-scrollbar-thumb {
            background-color: var(--scrollbar-thumb);
            border-radius: 4px;
        }
        ::-webkit-scrollbar-track {
            background-color: var(--scrollbar-track);
        }

        #movesidebar {
            position: absolute;
            height: 100vh;
            width: 20%;
            background-color: var(--secondary-bg);
            border-right: 1px solid rgba(0, 0, 0, 0.2);
            flex-shrink: 0;
            left: 0;
        }
        #rolesidebar {
            position: absolute;
            height: 100vh;
            width: 20%;
            background-color: var(--secondary-bg);
            border-right: 1px solid rgba(0, 0, 0, 0.2);
            flex-shrink: 0;
            left: 20%;
        }
        #main-content {
            position: absolute;
            height: 100vh;
            width: 60%;
            margin-left: 40%;
            left: 0;
            background-color: var(--primary-bg);
        }

        .nav-item, .role-item, .category-item-header {
            padding: 8px 12px;
            margin: 4px 8px;
            border-radius: 6px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--text-secondary);
            transition: all 0.2s ease;
            cursor: grab;
        }

        .nav-item:hover, .role-item:hover, .category-item-header:hover {
            background-color: rgba(79, 84, 92, 0.3);
            color: var(--text-primary);
            transform: translateX(4px);
        }

        .nav-item.active, .role-item.active, .category-item-header.active {
            background-color: var(--accent-color);
            color: var(--text-primary);
            font-weight: 500;
        }

        .form-input, select.form-input {
            background-color: rgba(0, 0, 0, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 6px;
            padding: 10px;
            width: 100%;
            color: var(--text-primary);
            font-size: 14px;
            transition: border-color 0.2s ease;
        }

        .form-input:focus {
            border-color: var(--accent-color);
            outline: none;
        }

        .btn {
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            border: none;
            transition: background-color 0.2s ease, transform 0.1s ease;
        }

        .btn:hover {
            transform: translateY(-1px);
        }

        .btn-primary {
            background-color: var(--accent-color);
            color: white;
        }

        .btn-danger {
            background-color: var(--danger-color);
            color: white;
        }

        .btn-secondary {
            background-color: rgba(79, 84, 92, 0.5);
            color: var(--text-primary);
        }

        .switch {
            position: relative;
            display: inline-block;
            width: 40px;
            height: 20px;
        }

        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(79, 84, 92, 0.6);
            transition: background-color 0.2s ease;
            border-radius: 10px;
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 16px;
            width: 16px;
            left: 2px;
            bottom: 2px;
            background-color: white;
            transition: transform 0.2s ease;
            border-radius: 50%;
        }

        input:checked + .slider {
            background-color: var(--accent-color);
        }

        input:checked + .slider:before {
            transform: translateX(20px);
        }

        .color-option {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            cursor: pointer;
            border: 2px solid transparent;
            transition: border-color 0.2s ease, transform 0.2s ease;
        }

        .color-option:hover {
            transform: scale(1.1);
        }

        .color-option.selected {
            border-color: var(--text-primary);
        }

        .user-item {
            display: flex;
            align-items: center;
            padding: 8px 12px;
            background-color: rgba(0, 0, 0, 0.15);
            border-radius: 6px;
            margin-bottom: 6px;
            transition: background-color 0.2s ease;
        }

        .user-item:hover {
            background-color: rgba(0, 0, 0, 0.25);
        }

        .ui-sortable-placeholder {
            background-color: rgba(79, 84, 92, 0.2) !important;
            border: 1px dashed rgba(79, 84, 92, 0.6) !important;
            visibility: visible !important;
            height: 36px;
            border-radius: 6px;
            margin: 4px 8px;
        }

        .ui-sortable-helper {
            background-color: var(--secondary-bg) !important;
            border: 1px solid rgba(79, 84, 92, 0.8) !important;
            border-radius: 6px;
            opacity: 0.9 !important;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.4);
            z-index: 9999 !important;
            cursor: grabbing !important;
        }

        .category-content.hidden {
            display: none;
        }

        .role-sortable-list {
            min-height: 40px;
            padding: 6px 0;
            background-color: rgba(0, 0, 0, 0.05);
            border-radius: 6px;
            margin: 6px 0;
            overflow: visible;
        }

        .ui-sortable-list-hover {
            outline: 2px dashed var(--accent-color) !important;
            background-color: rgba(60, 179, 113, 0.1) !important;
        }

        .tooltip {
            position: relative;
        }

        .tooltip:hover:after {
            content: attr(data-tooltip);
            position: absolute;
            background-color: var(--secondary-bg);
            color: var(--text-primary);
            padding: 6px 10px;
            border-radius: 6px;
            font-size: 12px;
            z-index: 10;
            top: -32px;
            left: 50%;
            transform: translateX(-50%);
            white-space: nowrap;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
        }

        .form-section {
            background-color: rgba(0, 0, 0, 0.1);
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 12px;
        }

        .form-label {
            font-size: 13px;
            font-weight: 500;
            color: var(--text-secondary);
            margin-bottom: 6px;
            display: block;
        }

        .form-input {
            background-color: rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(0, 0, 0, 0.3);
            border-radius: 3px;
            padding: 8px 10px;
            width: 100%;
            color: var(--text-primary);
            font-size: 14px;
            transition: border-color 0.2s ease;
        }

        .form-input:hover {
            border-color: rgba(0, 0, 0, 0.5);
        }

        .form-input:focus {
            border-color: var(--accent-color);
            outline: none;
        }

        .form-input:disabled, .form-textarea:disabled {
            background-color: rgba(0, 0, 0, 0.3);
            cursor: not-allowed;
        }

        .form-textarea {
            min-height: 80px;
            resize: vertical;
        }

        .btn:disabled {
            background-color: rgba(79, 84, 92, 0.3);
            cursor: not-allowed;
        }

        .switch input:disabled + .slider {
            background-color: rgba(79, 84, 92, 0.3);
            cursor: not-allowed;
        }

        .container__68f37 {
            display: flex;
            gap: 20px;
            align-items: flex-start;
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid rgba(79, 84, 92, 0.3);
        }

        .container__68f37:last-of-type {
            border-bottom: none;
        }

        .column__68f37 {
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .column__68f37.left-column {
            border-right: 1px solid rgba(79, 84, 92, 0.3);
            padding-right: 20px;
        }

        .h5_b717a1 {
            font-size: 1.125rem;
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .eyebrow_b717a1 {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            color: var(--text-secondary);
            letter-spacing: 0.5px;
        }

        .title__68f37 {
            color: var(--text-primary);
        }

        .text__68f37 {
            font-size: 0.875rem;
            line-height: 1.5;
            color: var(--text-secondary);
            margin-bottom: 8px;
        }

        .anchor_edefb8 {
            color: var(--accent-color);
            text-decoration: none;
        }

        .anchor_edefb8:hover {
            text-decoration: underline;
        }

        .spinner {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        @media (max-width: 768px) {
            #movesidebar {
                width: 100%;
                left: -100%;
                transition: left 0.3s ease-in-out;
                height: 100vh;
                z-index: 10;
            }
            #rolesidebar {
                width: 100%;
                left: -100%;
                transition: left 0.3s ease-in-out;
                height: 100vh;
                z-index: 10;
            }
            #main-content {
                position: relative;
                height: auto;
                width: 100%;
                margin-left: 0;
                padding: 1rem;
                transition: margin-left 0.3s ease-in-out;
            }
            .container__68f37 {
                flex-direction: column;
                gap: 0;
                padding-bottom: 10px;
            }
            .column__68f37.left-column {
                border-right: none;
                border-bottom: 1px solid rgba(79, 84, 92, 0.3);
                padding-right: 0;
                padding-bottom: 20px;
                margin-bottom: 20px;
            }
            .form-section.flex {
                flex-direction: column;
                align-items: center;
            }
            .form-section.flex > div {
                width: 100%;
                margin-bottom: 1rem;
            }
            .form-section.flex > div:last-child {
                margin-bottom: 0;
            }
            .nav-item, .role-item, .category-item-header {
                font-size: 13px;
                padding: 6px 10px;
            }
            .btn {
                padding: 6px 12px;
                font-size: 13px;
            }
        }
    </style>
</head>
<body class="flex h-screen">
    <div id="movesidebar" class="flex flex-col">
        <div class="p-4 border-b border-gray-800">
            <h1 class="font-semibold text-lg"><?php echo $translations['server_settings']['server_setting']; ?></h1>
            <p class="text-xs text-gray-400 mt-1 truncate"><?php echo htmlspecialchars($server['name'], ENT_QUOTES, 'UTF-8'); ?></p>
        </div>
        
        <nav class="flex-1 p-2 overflow-y-auto">
            <div class="space-y-1">
                <a href="server_settings?id=<?php echo $server_id; ?>" class="nav-item">
                    <i class="fas fa-cog w-5 text-center"></i>
                    <span><?php echo $translations['server_settings']['general']; ?></span>
                </a>
                <a href="server_emojis?id=<?php echo $server_id; ?>" class="nav-item">
                    <i class="fas fa-smile w-5 text-center"></i>
                    <span><?php echo $translations['server_settings']['emojis']; ?></span>
                </a>
                <a href="server_stickers?id=<?php echo $server_id; ?>" class="nav-item">
                    <i class="fas fa-sticky-note w-5 text-center"></i>
                    <span><?php echo $translations['server_settings']['stickers']; ?></span>
                </a>
                <a href="assign_role?id=<?php echo $server_id; ?>" class="nav-item active">
                    <i class="fas fa-user-tag w-5 text-center"></i>
                    <span><?php echo $translations['server_settings']['roles']; ?></span>
                </a>
                <a href="audit_log?id=<?php echo $server_id; ?>" class="nav-item">
                    <i class="fas fa-history w-5 text-center"></i>
                    <span><?php echo $translations['server_settings']['audit_log']; ?></span>
                </a>
                <a href="server_url?id=<?php echo $server_id; ?>" class="nav-item">
                    <i class="fas fa-link w-5 text-center"></i>
                    <span><?php echo $translations['server_settings']['server_url']; ?></span>
                </a>
                <a href="unban_users?id=<?php echo $server_id; ?>" class="nav-item">
                    <i class="fas fa-shield-alt w-5 text-center"></i>
                    <span><?php echo $translations['server_settings']['moderation']; ?></span>
                </a>
                <a href="server_category?id=<?php echo $server_id; ?>" class="nav-item">
                    <i class="fas fa-users w-5 text-center"></i>
                    <span><?php echo $translations['server_settings']['community']; ?></span>
                </a>
                <h3 class="text-xs font-bold text-gray-500 uppercase px-4 mt-4 mb-2">Bot Yönetimi</h3>
                <a href="create_bot?id=<?php echo $server_id; ?>" class="nav-item">
                    <i class="fas fa-robot w-5 text-center"></i>
                    <span><?php echo $translations['server_settings']['create_bot']; ?></span>
                </a>
                <a href="manage_bots?id=<?php echo $server_id; ?>" class="nav-item">
                    <i class="fas fa-cogs w-5 text-center"></i>
                    <span><?php echo $translations['server_settings']['manage_bots']; ?></span>
                </a>
            </div>
        </nav>

        <div class="p-2 border-t border-gray-800">
            <a href="server?id=<?php echo $server_id; ?>" class="nav-item">
                <i class="fas fa-arrow-left w-5 text-center"></i>
                <span><?php echo $translations['server_settings']['back_server']; ?></span>
            </a>
        </div>
    </div>

    <div id="rolesidebar" class="flex flex-col">
        <div class="p-4 border-b border-gray-800">
            <h2 class="font-semibold text-base"><?php echo $translations['server_settings']['roles']; ?></h2>
            <button id="create-new-role" class="nav-item w-full text-left mt-2 text-sm">
                <i class="fas fa-plus w-4"></i>
                <span>Yeni Rol Oluştur</span>
            </button>
            <button id="create-new-category" class="nav-item w-full text-left mt-2 text-sm">
                <i class="fas fa-folder-plus w-4"></i>
                <span>Kategori Oluştur</span>
            </button>
        </div>
        <div class="flex-1 p-3 overflow-y-auto">
            <?php echo $feedback; ?>
            <ul id="roles-categories-list" class="space-y-3">
                <?php foreach ($categories as $category): 
                    $current_category_roles = isset($categorized_roles[$category['id']]) ? $categorized_roles[$category['id']] : [];
                    usort($current_category_roles, function($a, $b) { return $b['importance'] - $a['importance']; });
                ?>
                    <li class="category-group" data-category-id="<?php echo $category['id']; ?>">
                        <div class="category-item-header flex items-center justify-between text-sm" style="color: <?php echo htmlspecialchars($category['color'], ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="flex items-center gap-2">
                                <i class="fas fa-chevron-down w-3 category-toggle-icon"></i>
                                <i class="fas fa-folder w-4"></i>
                                <span><?php echo htmlspecialchars($category['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                            <button class="delete-category btn btn-danger text-xs p-1" data-category-id="<?php echo $category['id']; ?>">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                        </div>
                        <ul class="role-sortable-list pl-4 space-y-2 category-content" data-category-id="<?php echo $category['id']; ?>">
                            <?php foreach ($current_category_roles as $role): ?>
                                <li class="role-item nav-item text-sm" data-role-id="<?php echo $role['id']; ?>" style="color: <?php echo htmlspecialchars($role['color'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <i class="fas fa-user-tag w-4"></i>
                                    <span><?php echo htmlspecialchars($role['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </li>
                <?php endforeach; ?>
                <li class="category-group" data-category-id="uncategorized">
                    <div class="category-item-header flex items-center justify-between text-sm">
                        <div class="flex items-center gap-2">
                            <i class="fas fa-chevron-down w-3 category-toggle-icon"></i>
                            <i class="fas fa-folder-open w-4"></i>
                            <span>Kategorisiz</span>
                        </div>
                    </div>
                    <ul class="role-sortable-list pl-4 space-y-2 category-content" data-category-id="uncategorized">
                        <?php if (isset($categorized_roles['uncategorized'])): 
                            $uncategorized_roles = $categorized_roles['uncategorized'];
                            usort($uncategorized_roles, function($a, $b) { return $b['importance'] - $a['importance']; });
                            foreach ($uncategorized_roles as $role): ?>
                                <li class="role-item nav-item text-sm" data-role-id="<?php echo $role['id']; ?>" style="color: <?php echo htmlspecialchars($role['color'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <i class="fas fa-user-tag w-4"></i>
                                    <span><?php echo htmlspecialchars($role['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </li>
            </ul>
            <form id="update-roles-order-form" method="POST" action="assign_role?id=<?php echo $server_id; ?>">
                <input type="hidden" name="update_roles_order" value="1">
                <input type="hidden" id="ordered_items" name="ordered_items" value="">
                <button type="submit" class="btn btn-primary w-full mt-4">
                    <i class="fas fa-save"></i> Sıralamayı Kaydet
                </button>
            </form>
        </div>
    </div>

    <div id="main-content" class="overflow-y-auto p-6">
        <div id="create-role-form" class="hidden">
            <h3 class="text-base font-semibold mb-4">Yeni Rol Oluştur</h3>
            <form method="POST" action="assign_role?id=<?php echo $server_id; ?>">
                <div class="form-section">
                    <label for="role_name" class="form-label">Rol İsmi</label>
                    <input type="text" name="role_name" id="role_name" class="form-input" required>
                </div>
                <div class="form-section">
                    <label for="category_id_create" class="form-label">Kategori</label>
                    <select name="category_id" id="category_id_create" class="form-input">
                        <option value="">Kategorisiz</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?php echo $category['id']; ?>"><?php echo htmlspecialchars($category['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-section">
                    <input type="text" id="permissions-search-create" class="form-input w-full" placeholder="İzinleri Ara...">
                </div>
                <div class="form-section">
                    <label class="form-label">İzinler</label>
                    <?php foreach ($permission_categories as $category => $permissions): ?>
                        <div class="mb-4 permission-category">
                            <h4 class="text-sm font-medium mb-2"><?php echo $category; ?></h4>
                            <div class="flex flex-wrap gap-4">
                                <?php foreach ($permissions as $key => $info): ?>
                                    <label class="inline-flex items-center tooltip text-sm" data-tooltip="<?php echo htmlspecialchars($info['desc'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <span class="switch">
                                            <input type="checkbox" name="permissions[]" value="<?php echo $key; ?>">
                                            <span class="slider"></span>
                                        </span>
                                        <span class="ml-2">
                                            <?php echo $info['label']; ?>
                                            <?php if ($key === 'administrator'): ?>
                                                <i class="fas fa-exclamation-triangle text-yellow-500 ml-1"></i>
                                            <?php endif; ?>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="form-section">
                    <label class="form-label">Rol Rengi</label>
                    <div class="flex flex-wrap gap-3 mb-2" id="create-role-color-options">
                        <?php foreach ($predefined_colors as $color): ?>
                            <div class="color-option <?php echo $color === '#3CB371' ? 'selected' : ''; ?>" style="background-color: <?php echo $color; ?>" data-color="<?php echo $color; ?>"></div>
                        <?php endforeach; ?>
                    </div>
                    <input type="color" name="color" id="color" class="form-input h-10 w-16 p-1" value="#3CB371">
                </div>
                <div class="form-section flex gap-2">
                    <button type="submit" name="create_role" class="btn btn-primary flex-1">
                        <i class="fas fa-plus"></i> Rol Oluştur
                    </button>
                    <button type="button" id="cancel-create-role" class="btn btn-secondary flex-1">
                        <i class="fas fa-times"></i> İptal
                    </button>
                </div>
            </form>
        </div>

        <div id="create-category-form" class="hidden">
            <h3 class="text-base font-semibold mb-4">Yeni Kategori Oluştur</h3>
            <form method="POST" action="assign_role?id=<?php echo $server_id; ?>">
                <div class="form-section">
                    <label for="category_name" class="form-label">Kategori İsmi</label>
                    <input type="text" name="category_name" id="category_name" class="form-input" required>
                </div>
                <div class="form-section">
                    <label class="form-label">Kategori Rengi</label>
                    <div class="flex flex-wrap gap-3 mb-2" id="create-category-color-options">
                        <?php foreach ($predefined_colors as $color): ?>
                            <div class="color-option <?php echo $color === '#3CB371' ? 'selected' : ''; ?>" style="background-color: <?php echo $color; ?>" data-color="<?php echo $color; ?>"></div>
                        <?php endforeach; ?>
                    </div>
                    <input type="color" name="category_color" id="category_color" class="form-input h-10 w-16 p-1" value="#3CB371">
                </div>
                <div class="form-section flex gap-2">
                    <button type="submit" name="create_category" class="btn btn-primary flex-1">
                        <i class="fas fa-folder-plus"></i> Kategori Oluştur
                    </button>
                    <button type="button" id="cancel-create-category" class="btn btn-secondary flex-1">
                        <i class="fas fa-times"></i> İptal
                    </button>
                </div>
            </form>
        </div>

        <div id="role-details" class="hidden">
            <h3 class="text-base font-semibold mb-4">Rol Detayları</h3>
            <form method="POST" action="assign_role?id=<?php echo $server_id; ?>">
                <input type="hidden" name="role_id" id="role_id">
                <div class="form-section">
                    <label for="role_name_edit" class="form-label">Rol İsmi</label>
                    <input type="text" name="role_name" id="role_name_edit" class="form-input" required>
                </div>
                <div class="form-section">
                    <label for="category_id_edit" class="form-label">Kategori</label>
                    <select name="category_id" id="category_id_edit" class="form-input">
                        <option value="">Kategorisiz</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?php echo $category['id']; ?>"><?php echo htmlspecialchars($category['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-section">
                    <input type="text" id="permissions-search-edit" class="form-input w-full" placeholder="İzinleri Ara...">
                </div>
                <div class="form-section">
                    <label class="form-label">İzinler</label>
                    <?php foreach ($permission_categories as $category => $permissions): ?>
                        <div class="mb-4 permission-category">
                            <h4 class="text-sm font-medium mb-2"><?php echo $category; ?></h4>
                            <div class="flex flex-wrap gap-4">
                                <?php foreach ($permissions as $key => $info): ?>
                                    <label class="inline-flex items-center tooltip text-sm" data-tooltip="<?php echo htmlspecialchars($info['desc'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <span class="switch">
                                            <input type="checkbox" name="permissions[]" value="<?php echo $key; ?>">
                                            <span class="slider"></span>
                                        </span>
                                        <span class="ml-2">
                                            <?php echo $info['label']; ?>
                                            <?php if ($key === 'administrator'): ?>
                                                <i class="fas fa-exclamation-triangle text-yellow-500 ml-1"></i>
                                            <?php endif; ?>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="form-section">
                    <label class="form-label">Rol Rengi</label>
                    <div class="flex flex-wrap gap-3 mb-2" id="edit-role-color-options">
                        <?php foreach ($predefined_colors as $color): ?>
                            <div class="color-option" style="background-color: <?php echo $color; ?>" data-color="<?php echo $color; ?>"></div>
                        <?php endforeach; ?>
                    </div>
                    <input type="color" name="color" id="color_edit" class="form-input h-10 w-16 p-1">
                </div>
                <div class="form-section flex gap-2">
                    <button type="submit" name="edit_role" class="btn btn-primary flex-1">
                        <i class="fas fa-save"></i> Kaydet
                    </button>
                    <button type="button" id="delete-role" class="btn btn-danger flex-1">
                        <i class="fas fa-trash-alt"></i> Rolü Sil
                    </button>
                    <button type="button" id="clone-role" class="btn btn-secondary flex-1">
                        <i class="fas fa-copy"></i> Rolü Klonla
                    </button>
                </div>
            </form>
        </div>

        <div id="user-management" class="hidden">
            <h3 class="text-base font-semibold mb-4">Role Sahip Kullanıcılar</h3>
            <div class="form-section">
                <input type="text" id="user-search" class="form-input w-full" placeholder="Kullanıcı Ara...">
                <label class="inline-flex items-center mt-2 text-sm">
                    <input type="checkbox" id="select-all-users-with-role" class="form-checkbox">
                    <span class="ml-2">Tümünü Seç</span>
                </label>
            </div>
            <div id="users-with-role" class="space-y-2 mt-4 max-h-60 overflow-y-auto"></div>
            <div class="mt-4 border-t border-gray-700 pt-4">
                <h4 class="text-sm font-medium mb-2">Kullanıcı Ekle</h4>
                <input type="text" id="add-user-search" class="form-input w-full mb-2" placeholder="Kullanıcı Ara...">
                <label class="inline-flex items-center mb-2 text-sm">
                    <input type="checkbox" id="select-all-users" class="form-checkbox">
                    <span class="ml-2">Tümünü Seç</span>
                </label>
                <div id="add-user-list" class="space-y-2 max-h-60 overflow-y-auto"></div>
                <div class="flex gap-2 mt-2">
                    <button id="add-users-to-role" class="btn btn-primary flex-1 text-sm">
                        <i class="fas fa-plus"></i> Seçilenleri Ekle
                    </button>
                    <button id="remove-selected-users" class="btn btn-danger flex-1 text-sm">
                        <i class="fas fa-times"></i> Seçilenleri Kaldır
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        $(function() {
            // Initial role order update
            updateRoleOrder();
            console.log("Initial role order updated.");

            // Initialize category sortable
            $("#roles-categories-list").sortable({
                items: "> .category-group",
                handle: ".category-item-header",
                axis: "y",
                placeholder: "ui-sortable-placeholder",
                forcePlaceholderSize: true,
                update: function(event, ui) {
                    updateRoleOrder();
                    console.log("Category order updated.");
                    $("#update-roles-order-form").submit();
                }
            });

            // Initialize role sortable
            $(".role-sortable-list").sortable({
                items: "> .role-item",
                connectWith: ".role-sortable-list",
                axis: "y",
                placeholder: "ui-sortable-placeholder",
                forcePlaceholderSize: true,
                tolerance: "pointer",
                cursorAt: { left: 5, top: 5 },
                helper: function(event, ui) {
                    var $clone = ui.clone();
                    $clone.css('z-index', 99999);
                    $clone.addClass('ui-sortable-helper');
                    return $clone;
                },
                start: function(event, ui) {
                    ui.helper.css('z-index', 99999);
                    console.log("Role dragging started.");
                },
                over: function(event, ui) {
                    $(this).addClass('ui-sortable-list-hover');
                },
                out: function(event, ui) {
                    $(this).removeClass('ui-sortable-list-hover');
                },
                update: function(event, ui) {
                    updateRoleOrder();
                    console.log("Role order updated.");
                    $("#update-roles-order-form").submit();
                }
            }).disableSelection();

            // Update role order function
            function updateRoleOrder() {
                var orderedItems = [];
                $("#roles-categories-list > .category-group").each(function() {
                    var categoryId = $(this).data("category-id");
                    var categoryIdToSend = (categoryId === 'uncategorized') ? null : categoryId;
                    var categoryType = (categoryId === 'uncategorized') ? 'uncategorized_group' : 'category';
                    var rolesInThisCategory = [];

                    $(this).find(".role-sortable-list .role-item").each(function() {
                        rolesInThisCategory.push($(this).data("role-id"));
                    });

                    orderedItems.push({
                        type: categoryType,
                        id: categoryIdToSend,
                        roles: rolesInThisCategory
                    });
                });
                $("#ordered_items").val(JSON.stringify(orderedItems));
                console.log("Ordered items:", orderedItems);
            }

            // Toggle category
            $(document).on("click", ".category-item-header", function(e) {
                if ($(e.target).closest('.delete-category').length) return;
                var categoryContent = $(this).next(".category-content");
                var toggleIcon = $(this).find(".category-toggle-icon");
                categoryContent.slideToggle(150, function() {
                    toggleIcon.toggleClass("fa-chevron-right fa-chevron-down");
                });
            });

            // Show create role form
            $("#create-new-role").on("click", function() {
                $("#create-role-form").slideDown(200).removeClass("hidden");
                $("#create-category-form, #role-details, #user-management").slideUp(200).addClass("hidden");
                $(".role-item, .category-item-header").removeClass("active");
                $('#color').val('<?php echo $predefined_colors[0]; ?>');
                $('#create-role-color-options .color-option').removeClass('selected');
                $(`#create-role-color-options .color-option[data-color='<?php echo $predefined_colors[0]; ?>']`).addClass('selected');
                $('#category_id_create').val('');
                $("#permissions-search-create").val('');
                $("#create-role-form .permission-category").show();
            });

            // Cancel create role
            $("#cancel-create-role").on("click", function() {
                $("#create-role-form").slideUp(200).addClass("hidden");
            });

            // Show create category form
            $("#create-new-category").on("click", function() {
                $("#create-category-form").slideDown(200).removeClass("hidden");
                $("#create-role-form, #role-details, #user-management").slideUp(200).addClass("hidden");
                $(".role-item, .category-item-header").removeClass("active");
                $('#category_color').val('<?php echo $predefined_colors[0]; ?>');
                $(`#create-category-color-options .color-option`).removeClass('selected');
                $(`#create-category-color-options .color-option[data-color='<?php echo $predefined_colors[0]; ?>']`).addClass('selected');
            });

            // Cancel create category
            $("#cancel-create-category").on("click", function() {
                $("#create-category-form").slideUp(200).addClass("hidden");
            });

            // Role details
            $(document).on("click", ".role-item", function() {
                var roleId = $(this).data("role-id");
                $(".role-item, .category-item-header").removeClass("active");
                $(this).addClass("active");

                $.ajax({
                    url: "get_role.php?id=" + roleId,
                    type: "GET",
                    dataType: "json",
                    success: function(data) {
                        if (data.error) {
                            console.error("Server error:", data.error);
                            alert("Rol bilgileri alınamadı: " + data.error);
                            return;
                        }

                        $("#role_id").val(data.id);
                        $("#role_name_edit").val(data.name);
                        $("#category_id_edit").val(data.category_id || "");
                        $("#color_edit").val(data.color);

                        $('#edit-role-color-options .color-option').removeClass('selected');
                        $('#edit-role-color-options .color-option[data-color="' + data.color + '"]').addClass('selected');

                        $("#role-details input[name='permissions[]']").prop("checked", false);
                        if (Array.isArray(data.permissions)) {
                            data.permissions.forEach(function(perm) {
                                const checkbox = $("#role-details input[name='permissions[]'][value='" + perm + "']");
                                if (checkbox.length) {
                                    checkbox.prop("checked", true);
                                } else {
                                    console.warn("Checkbox not found for permission:", perm);
                                }
                            });
                        } else {
                            console.warn("Permissions is not an array:", data.permissions);
                        }

                        $("#permissions-search-edit").val('');
                        $("#role-details .permission-category").show();
                        $("#create-role-form, #create-category-form").slideUp(200).addClass("hidden");
                        $("#role-details, #user-management").slideDown(200).removeClass("hidden");
                        loadUserManagement(roleId);
                    },
                    error: function(xhr, status, error) {
                        console.error("AJAX error:", status, error);
                        alert("Rol bilgileri alınamadı. Lütfen tekrar deneyin.");
                    }
                });
            });

            // Color picker
            $(document).on("click", ".color-option", function() {
                var color = $(this).data("color");
                $(this).closest(".form-section").find("input[type='color']").val(color);
                $(this).closest(".flex").find(".color-option").removeClass("selected");
                $(this).addClass("selected");
            });

            // Permissions search
            function filterPermissions(searchInput, container) {
                $(searchInput).on("input", function() {
                    var searchTerm = $(this).val().toLowerCase();
                    $(container + " .permission-category").each(function() {
                        var category = $(this).find("h4").text().toLowerCase();
                        var permissions = $(this).find("label");
                        var visiblePermissions = false;
                        permissions.each(function() {
                            var permText = $(this).text().toLowerCase();
                            if (permText.includes(searchTerm) || category.includes(searchTerm)) {
                                $(this).show();
                                visiblePermissions = true;
                            } else {
                                $(this).hide();
                            }
                        });
                        $(this).toggle(visiblePermissions);
                    });
                });
            }

            filterPermissions("#permissions-search-create", "#create-role-form");
            filterPermissions("#permissions-search-edit", "#role-details");

            // Load user management
            function loadUserManagement(roleId) {
                $.ajax({
                    url: "get_users_with_role.php?role_id=" + roleId + "&server_id=<?php echo $server_id; ?>",
                    type: "GET",
                    dataType: "json",
                    success: function(users) {
                        var html = "";
                        if (users.length > 0) {
                            users.forEach(function(user) {
                                html += `
                                    <div class="user-item">
                                        <input type="checkbox" class="remove-user-checkbox" value="${user.id}">
                                        <span class="ml-2">${user.username}</span>
                                        <button class="remove-user btn btn-danger text-xs p-1 ml-auto" data-user-id="${user.id}">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                `;
                            });
                        } else {
                            html = "<p class='text-sm text-gray-400'>Bu role sahip kullanıcı bulunmamaktadır.</p>";
                        }
                        $("#users-with-role").html(html);
                        $("#select-all-users-with-role").prop("checked", false);
                    },
                    error: function(xhr, status, error) {
                        console.error("Error fetching users:", status, error);
                        $("#users-with-role").html("<p class='text-sm text-red-400'>Kullanıcılar yüklenirken hata oluştu.</p>");
                    }
                });

                $.ajax({
                    url: "get_users_without_role.php?role_id=" + roleId + "&server_id=<?php echo $server_id; ?>",
                    type: "GET",
                    dataType: "json",
                    success: function(users) {
                        var html = "";
                        if (users.length > 0) {
                            users.forEach(function(user) {
                                html += `
                                    <div class="user-item">
                                        <input type="checkbox" class="add-user-checkbox" value="${user.id}">
                                        <span class="ml-2">${user.username}</span>
                                    </div>
                                `;
                            });
                        } else {
                            html = "<p class='text-sm text-gray-400'>Eklenebilecek kullanıcı bulunmamaktadır.</p>";
                        }
                        $("#add-user-list").html(html);
                        $("#select-all-users").prop("checked", false);
                    },
                    error: function(xhr, status, error) {
                        console.error("Error fetching users:", status, error);
                        $("#add-user-list").html("<p class='text-sm text-red-400'>Kullanıcılar yüklenirken hata oluştu.</p>");
                    }
                });
            }

            // User search
            $("#user-search").on("input", function() {
                var searchTerm = $(this).val().toLowerCase();
                $("#users-with-role .user-item").each(function() {
                    var username = $(this).find("span").text().toLowerCase();
                    $(this).toggle(username.includes(searchTerm));
                });
            });

            $("#add-user-search").on("input", function() {
                var searchTerm = $(this).val().toLowerCase();
                $("#add-user-list .user-item").each(function() {
                    var username = $(this).find("span").text().toLowerCase();
                    $(this).toggle(username.includes(searchTerm));
                });
            });

            // Select all users with role
            $("#select-all-users-with-role").on("change", function() {
                $("#users-with-role .remove-user-checkbox").prop("checked", $(this).is(":checked"));
            });

            // Select all users
            $("#select-all-users").on("change", function() {
                $("#add-user-list .add-user-checkbox").prop("checked", $(this).is(":checked"));
            });

            // Remove user from role
            $(document).on("click", ".remove-user", function() {
                if (!confirm("Bu kullanıcıyı rolden kaldırmak istediğinizden emin misiniz?")) return;
                var userId = $(this).data("user-id");
                var roleId = $("#role_id").val();
                $.post("remove_user_from_role.php", { user_id: userId, role_id: roleId, server_id: <?php echo $server_id; ?> }, function(response) {
                    if (response.success) {
                        loadUserManagement(roleId);
                    } else {
                        alert("Kullanıcı rolden kaldırılamadı: " + response.message);
                    }
                }, 'json').fail(function(xhr, status, error) {
                    console.error("Error removing user:", status, error);
                    alert("Kullanıcıyı rolden kaldırırken hata oluştu.");
                });
            });

            // Remove selected users from role
            $("#remove-selected-users").on("click", function() {
                var selectedUsers = $("#users-with-role .remove-user-checkbox:checked").map(function() {
                    return $(this).val();
                }).get();
                var roleId = $("#role_id").val();

                if (selectedUsers.length === 0) {
                    alert("Lütfen kaldırılacak kullanıcıları seçin.");
                    return;
                }

                if (confirm("Seçilen kullanıcıları rolden kaldırmak istediğinizden emin misiniz?")) {
                    $.post("remove_users_from_role.php", { user_ids: selectedUsers, role_id: roleId, server_id: <?php echo $server_id; ?> }, function(response) {
                        if (response.success) {
                            loadUserManagement(roleId);
                        } else {
                            alert("Kullanıcılar rolden kaldırılamadı: " + response.message);
                        }
                    }, 'json').fail(function(xhr, status, error) {
                        console.error("Error removing users:", status, error);
                        alert("Kullanıcıları rolden kaldırırken hata oluştu.");
                    });
                }
            });

            // Add users to role
            $("#add-users-to-role").on("click", function() {
                var selectedUsers = $("#add-user-list .add-user-checkbox:checked").map(function() {
                    return $(this).val();
                }).get();
                var roleId = $("#role_id").val();

                if (selectedUsers.length === 0) {
                    alert("Lütfen eklenecek kullanıcıları seçin.");
                    return;
                }

                $.post("add_users_to_role.php", { user_ids: selectedUsers, role_id: roleId, server_id: <?php echo $server_id; ?> }, function(response) {
                    if (response.success) {
                        loadUserManagement(roleId);
                    } else {
                        alert("Kullanıcılar role eklenemedi: " + response.message);
                    }
                }, 'json').fail(function(xhr, status, error) {
                    console.error("Error adding users:", status, error);
                    alert("Kullanıcıları role eklerken hata oluştu.");
                });
            });

            // Delete role
            $(document).on("click", "#delete-role", function() {
                if (confirm("Bu rolü silmek istediğinizden emin misiniz?")) {
                    var roleId = $("#role_id").val();
                    $.post("assign_role.php?id=<?php echo $server_id; ?>", { delete_role: 1, role_id: roleId }, function() {
                        location.reload();
                    }).fail(function(xhr, status, error) {
                        console.error("Error deleting role:", status, error);
                        alert("Rol silinirken hata oluştu.");
                    });
                }
            });

            // Clone role
            $(document).on("click", "#clone-role", function() {
                var roleId = $("#role_id").val();
                $.ajax({
                    url: "get_role.php?id=" + roleId,
                    type: "GET",
                    dataType: "json",
                    success: function(data) {
                        if (data.error) {
                            alert("Rol bilgileri alınamadı: " + data.error);
                            return;
                        }
                        $("#create-role-form").slideDown(200).removeClass("hidden");
                        $("#role-details, #create-category-form, #user-management").slideUp(200).addClass("hidden");
                        $("#role_name").val(data.name + " (Kopya)");
                        $("#category_id_create").val(data.category_id || "");
                        $("#color").val(data.color);
                        $('#create-role-color-options .color-option').removeClass('selected');
                        $(`#create-role-color-options .color-option[data-color="${data.color}"]`).addClass('selected');
                        $("#create-role-form input[name='permissions[]']").prop("checked", false);
                        if (Array.isArray(data.permissions)) {
                            data.permissions.forEach(function(perm) {
                                $("#create-role-form input[name='permissions[]'][value='" + perm + "']").prop("checked", true);
                            });
                        }
                        $("#permissions-search-create").val('');
                        $("#create-role-form .permission-category").show();
                    },
                    error: function(xhr, status, error) {
                        console.error("Error cloning role:", status, error);
                        alert("Rol kopyalanırken hata oluştu.");
                    }
                });
            });

            // Delete category
            $(document).on("click", ".delete-category", function() {
                if (confirm("Bu kategoriyi silmek istediğinizden emin misiniz? Altındaki roller kategorisiz olacak.")) {
                    var categoryId = $(this).data("category-id");
                    $.post("assign_role.php?id=<?php echo $server_id; ?>", { delete_category: 1, category_id: categoryId }, function() {
                        location.reload();
                    }).fail(function(xhr, status, error) {
                        console.error("Error deleting category:", status, error);
                        alert("Kategori silinirken hata oluştu.");
                    });
                }
            });

            // Mobil Kaydırma hareketi
            const movesidebar = document.getElementById("movesidebar");
            const rolesidebar = document.getElementById("rolesidebar");
            const mainContent = document.getElementById("main-content");

            let startX, endX;

            if (window.innerWidth <= 768) {
                document.addEventListener("touchstart", (e) => {
                    startX = e.touches[0].clientX;
                });

                document.addEventListener("touchend", (e) => {
                    endX = e.changedTouches[0].clientX;
                    handleSwipe();
                });

                function handleSwipe() {
                    const deltaX = startX - endX;
                    if (Math.abs(deltaX) < 100) return;
                    if (deltaX > 100) {
                        movesidebar.style.left = "-100%";
                        rolesidebar.style.left = "-100%";
                    } else if (deltaX < -100) {
                        movesidebar.style.left = "0";
                        rolesidebar.style.left = "0";
                    }
                }
            }
        });
    </script>
</body>
</html>