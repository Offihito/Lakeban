<?php
// Mevcut assign_role.php'deki POST işlemleri ve veri çekme
$permission_categories = [
    'Management' => [
        'manage_channels' => 'Kanal Yönetimi',
        'manage_roles' => 'Rol Yönetimi',
        'manage_server' => 'Sunucu Yönetimi',
    ],
    'Moderation' => [
        'kick' => 'Kullanıcıyı Kickle',
        'ban' => 'Kullanıcıyı Banla',
        'manage_messages' => 'Mesaj Yönetimi',
    ],
    'General' => [
        'view_channels' => 'Kanalları Görüntüle',
        'send_messages' => 'Mesaj Gönder',
        'attach_files' => 'Dosya Ekle',
    ],
    'Special' => [
        'administrator' => 'Yönetici'
    ]
];

$predefined_colors = [
    '#3CB371', '#5865F2', '#ED4245', '#FAA61A', '#57F287', '#FEE75C', '#EB459E', '#00B0F4'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_role'])) {
        $role_name = $_POST['role_name'];
        $permissions = isset($_POST['permissions']) ? $_POST['permissions'] : [];
        $color = $_POST['color'];
        $category_id = empty($_POST['category_id']) ? null : $_POST['category_id'];

        $stmt = $db->prepare("SELECT MAX(importance) AS max_importance FROM roles WHERE server_id = ? AND (category_id = ? OR (category_id IS NULL AND ? IS NULL))");
        $stmt->execute([$server_id, $category_id, $category_id]);
        $max_importance = $stmt->fetchColumn();
        $importance = ($max_importance !== null) ? $max_importance + 1 : 0;

        $stmt = $db->prepare("INSERT INTO roles (name, permissions, color, server_id, category_id, importance) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$role_name, json_encode($permissions), $color, $server_id, $category_id, $importance]);
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
        $_SESSION['feedback'] = ['type' => 'success', 'message' => 'Kategori başarıyla oluşturuldu.'];

    } elseif (isset($_POST['update_roles_order'])) {
        $ordered_items = json_decode($_POST['ordered_items'], true);
        try {
            $db->beginTransaction();
            foreach ($ordered_items as $cat_index => $category_data) {
                $category_id_for_roles = ($category_data['type'] === 'category' && $category_data['id'] !== 'uncategorized') ? (int)$category_data['id'] : null;
                if ($category_data['type'] === 'category' && $category_data['id'] !== 'uncategorized') {
                    $stmt = $db->prepare("UPDATE role_categories SET importance = ? WHERE id = ? AND server_id = ?");
                    $stmt->execute([$cat_index, $category_id_for_roles, $server_id]);
                }
                if (isset($category_data['roles']) && is_array($category_data['roles'])) {
                    foreach ($category_data['roles'] as $role_index => $role_id) {
                        $role_id_int = (int)$role_id;
                        $stmt = $db->prepare("UPDATE roles SET importance = ?, category_id = ? WHERE id = ? AND server_id = ?");
                        $stmt->execute([$role_index, $category_id_for_roles, $role_id_int, $server_id]);
                    }
                }
            }
            $db->commit();
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
        $_SESSION['feedback'] = ['type' => 'success', 'message' => 'Rol başarıyla güncellendi.'];

    } elseif (isset($_POST['delete_role'])) {
        $role_id = $_POST['role_id'];
        $stmt = $db->prepare("DELETE FROM roles WHERE id = ? AND server_id = ?");
        $stmt->execute([$role_id, $server_id]);
        $stmt = $db->prepare("DELETE FROM user_roles WHERE role_id = ?");
        $stmt->execute([$role_id]);
        $_SESSION['feedback'] = ['type' => 'success', 'message' => 'Rol başarıyla silindi.'];

    } elseif (isset($_POST['delete_category'])) {
        $category_id = $_POST['category_id'];
        $stmt = $db->prepare("UPDATE roles SET category_id = NULL WHERE category_id = ? AND server_id = ?");
        $stmt->execute([$category_id, $server_id]);
        $stmt = $db->prepare("DELETE FROM role_categories WHERE id = ? AND server_id = ?");
        $stmt->execute([$category_id, $server_id]);
        $_SESSION['feedback'] = ['type' => 'success', 'message' => 'Kategori başarıyla silindi.'];
    }

    if (!isset($_GET['ajax'])) {
        header("Location: assign_role?id=" . $server_id);
        exit;
    } else {
        echo json_encode(['success' => true, 'message' => $_SESSION['feedback']['message']]);
        exit;
    }
}

$stmt = $db->prepare("SELECT * FROM role_categories WHERE server_id = ? ORDER BY importance ASC, id ASC");
$stmt->execute([$server_id]);
$categories = $stmt->fetchAll();

$stmt = $db->prepare("SELECT r.*, rc.name AS category_name, rc.color AS category_color FROM roles r LEFT JOIN role_categories rc ON r.category_id = rc.id WHERE r.server_id = ? ORDER BY COALESCE(rc.importance, 999999) ASC, r.importance ASC, r.id ASC");
$stmt->execute([$server_id]);
$roles = $stmt->fetchAll();

$categorized_roles = [];
foreach ($roles as $role) {
    $categoryId = $role['category_id'] ?: 'uncategorized';
    if (!isset($categorized_roles[$categoryId])) {
        $categorized_roles[$categoryId] = [];
    }
    $categorized_roles[$categoryId][] = $role;
}

usort($categories, function($a, $b) {
    return $a['importance'] - $b['importance'];
});

$feedback = '';
if (isset($_SESSION['feedback'])) {
    $feedback_type = $_SESSION['feedback']['type'];
    $feedback_message = htmlspecialchars($_SESSION['feedback']['message'], ENT_QUOTES, 'UTF-8');
    $feedback = "<div class='bg-{$feedback_type}-500 text-white p-3 rounded mb-4'>{$feedback_message}</div>";
    unset($_SESSION['feedback']);
}
?>

<div class="inner-sidebar flex flex-col">
    <div class="p-4 border-b border-gray-800">
        <h2 class="font-semibold text-lg">Roller</h2>
        <button id="create-new-role" class="nav-item w-full text-left mt-2">
            <i class="fas fa-plus w-5 text-center"></i>
            <span>Yeni Rol Oluştur</span>
        </button>
        <button id="create-new-category" class="nav-item w-full text-left mt-2">
            <i class="fas fa-folder-plus w-5 text-center"></i>
            <span>Kategori Oluştur</span>
        </button>
    </div>
    <div class="flex-1 p-2 overflow-y-auto">
        <?php echo $feedback; ?>
        <ul id="roles-categories-list" class="space-y-2">
            <?php foreach ($categories as $category):
                $current_category_roles = isset($categorized_roles[$category['id']]) ? $categorized_roles[$category['id']] : [];
                usort($current_category_roles, function($a, $b) { return $a['importance'] - $b['importance']; });
            ?>
                <li class="category-group" data-category-id="<?php echo $category['id']; ?>">
                    <div class="category-item-header flex items-center justify-between" style="color: <?php echo htmlspecialchars($category['color'], ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="flex items-center gap-2">
                            <i class="fas fa-chevron-down w-3 text-center category-toggle-icon"></i>
                            <i class="fas fa-folder w-5 text-center"></i>
                            <span><?php echo htmlspecialchars($category['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <button class="delete-category btn btn-danger text-xs ml-auto p-1" data-category-id="<?php echo $category['id']; ?>">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                    </div>
                    <ul class="role-sortable-list pl-4 space-y-1 category-content" data-category-id="<?php echo $category['id']; ?>">
                        <?php foreach ($current_category_roles as $role): ?>
                            <li class="role-item nav-item" data-role-id="<?php echo $role['id']; ?>" style="color: <?php echo htmlspecialchars($role['color'], ENT_QUOTES, 'UTF-8'); ?>">
                                <i class="fas fa-user-tag w-5 text-center"></i>
                                <span><?php echo htmlspecialchars($role['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </li>
            <?php endforeach; ?>
            <li class="category-group" data-category-id="uncategorized">
                <div class="category-item-header flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <i class="fas fa-chevron-down w-3 text-center category-toggle-icon"></i>
                        <i class="fas fa-folder-open w-5 text-center"></i>
                        <span>Kategorisiz</span>
                    </div>
                </div>
                <ul class="role-sortable-list pl-4 space-y-1 category-content" data-category-id="uncategorized">
                    <?php
                    $uncategorized_roles_list = isset($categorized_roles['uncategorized']) ? $categorized_roles['uncategorized'] : [];
                    usort($uncategorized_roles_list, function($a, $b) { return $a['importance'] - $b['importance']; });
                    foreach ($uncategorized_roles_list as $role): ?>
                        <li class="role-item nav-item" data-role-id="<?php echo $role['id']; ?>" style="color: <?php echo htmlspecialchars($role['color'], ENT_QUOTES, 'UTF-8'); ?>">
                            <i class="fas fa-user-tag w-5 text-center"></i>
                            <span><?php echo htmlspecialchars($role['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </li>
        </ul>
        <form method="POST" action="assign_role?id=<?php echo $server_id; ?>" id="update-roles-order-form" class="mt-4 px-2">
            <input type="hidden" name="update_roles_order" value="1">
            <input type="hidden" name="ordered_items" id="ordered_items" value="">
            <button type="submit" class="btn btn-primary w-full">
                <i class="fas fa-save"></i> Sıralamayı Kaydet
            </button>
        </form>
    </div>
</div>

<div class="flex-1 flex flex-col overflow-y-auto p-6">
    <div class="max-w-3xl mx-auto w-full">
        <h2 class="text-xl font-semibold mb-6">Rol Yönetimi</h2>
        <div id="create-role-form" class="hidden bg-secondary-bg rounded-lg p-6 mb-6">
            <h3 class="text-lg font-semibold mb-4">Yeni Rol Oluştur</h3>
            <form method="POST" action="assign_role?id=<?php echo $server_id; ?>">
                <div class="form-section mb-4">
                    <label for="role_name" class="form-label">Rol İsmi</label>
                    <input type="text" name="role_name" id="role_name" class="form-input" placeholder="Rol İsmi" required>
                </div>
                <div class="form-section mb-4">
                    <label for="category_id_create" class="form-label">Kategori</label>
                    <select name="category_id" id="category_id_create" class="form-input">
                        <option value="">Kategorisiz</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?php echo $category['id']; ?>"><?php echo htmlspecialchars($category['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-section mb-4">
                    <label class="form-label">İzinler</label>
                    <?php foreach ($permission_categories as $category => $permissions): ?>
                        <div class="mb-4">
                            <h4 class="text-sm font-medium mb-2"><?php echo $category; ?></h4>
                            <div class="flex flex-wrap gap-4">
                                <?php foreach ($permissions as $key => $label): ?>
                                    <label class="inline-flex items-center">
                                        <span class="switch">
                                            <input type="checkbox" name="permissions[]" value="<?php echo $key; ?>">
                                            <span class="slider"></span>
                                        </span>
                                        <span class="ml-2 text-sm" title="<?php echo $key === 'administrator' ? 'Bu izin tüm yetkileri verir!' : $label; ?>">
                                            <?php echo $label; ?>
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
                <div class="form-section mb-4">
                    <label class="form-label">Rol Rengi</label>
                    <div class="flex flex-wrap gap-2 mb-2" id="create-role-color-options">
                        <?php foreach ($predefined_colors as $color): ?>
                            <div class="color-option" style="background-color: <?php echo $color; ?>" data-color="<?php echo $color; ?>"></div>
                        <?php endforeach; ?>
                    </div>
                    <input type="color" name="color" id="color" class="form-input h-10 w-16 p-1" value="#3CB371">
                </div>
                <div class="form-section">
                    <button type="submit" name="create_role" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Rol Oluştur
                    </button>
                    <button type="button" id="cancel-create-role" class="btn btn-secondary ml-2">
                        <i class="fas fa-times"></i> İptal
                    </button>
                </div>
            </form>
        </div>

        <div id="create-category-form" class="hidden bg-secondary-bg rounded-lg p-6 mb-6">
            <h3 class="text-lg font-semibold mb-4">Yeni Kategori Oluştur</h3>
            <form method="POST" action="assign_role?id=<?php echo $server_id; ?>">
                <div class="form-section mb-4">
                    <label for="category_name" class="form-label">Kategori İsmi</label>
                    <input type="text" name="category_name" id="category_name" class="form-input" placeholder="Kategori İsmi" required>
                </div>
                <div class="form-section mb-4">
                    <label class="form-label">Kategori Rengi</label>
                    <div class="flex flex-wrap gap-2 mb-2" id="create-category-color-options">
                        <?php foreach ($predefined_colors as $color): ?>
                            <div class="color-option" style="background-color: <?php echo $color; ?>" data-color="<?php echo $color; ?>"></div>
                        <?php endforeach; ?>
                    </div>
                    <input type="color" name="category_color" id="category_color" class="form-input h-10 w-16 p-1" value="#3CB371">
                </div>
                <div class="form-section">
                    <button type="submit" name="create_category" class="btn btn-primary">
                        <i class="fas fa-folder-plus"></i> Kategori Oluştur
                    </button>
                    <button type="button" id="cancel-create-category" class="btn btn-secondary ml-2">
                        <i class="fas fa-times"></i> İptal
                    </button>
                </div>
            </form>
        </div>

        <div id="role-details" class="bg-secondary-bg rounded-lg p-6 mb-6 hidden">
            <h3 class="text-lg font-semibold mb-4">Rol Detayları</h3>
            <form method="POST" action="assign_role?id=<?php echo $server_id; ?>">
                <input type="hidden" name="role_id" id="role_id">
                <div class="form-section mb-4">
                    <label for="role_name_edit" class="form-label">Rol İsmi</label>
                    <input type="text" name="role_name" id="role_name_edit" class="form-input" required>
                </div>
                <div class="form-section mb-4">
                    <label for="category_id_edit" class="form-label">Kategori</label>
                    <select name="category_id" id="category_id_edit" class="form-input">
                        <option value="">Kategorisiz</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?php echo $category['id']; ?>"><?php echo htmlspecialchars($category['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-section mb-4">
                    <label class="form-label">İzinler</label>
                    <?php foreach ($permission_categories as $category => $permissions): ?>
                        <div class="mb-4">
                            <h4 class="text-sm font-medium mb-2"><?php echo $category; ?></h4>
                            <div class="flex flex-wrap gap-4">
                                <?php foreach ($permissions as $key => $label): ?>
                                    <label class="inline-flex items-center">
                                        <span class="switch">
                                            <input type="checkbox" name="permissions[]" value="<?php echo $key; ?>">
                                            <span class="slider"></span>
                                        </span>
                                        <span class="ml-2 text-sm" title="<?php echo $key === 'administrator' ? 'Bu izin tüm yetkileri verir!' : $label; ?>">
                                            <?php echo $label; ?>
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
                <div class="form-section mb-4">
                    <label class="form-label">Rol Rengi</label>
                    <div class="flex flex-wrap gap-2 mb-2" id="edit-role-color-options">
                        <?php foreach ($predefined_colors as $color): ?>
                            <div class="color-option" style="background-color: <?php echo $color; ?>" data-color="<?php echo $color; ?>"></div>
                        <?php endforeach; ?>
                    </div>
                    <input type="color" name="color" id="color_edit" class="form-input h-10 w-16 p-1">
                </div>
                <div class="form-section">
                    <button type="submit" name="edit_role" class="btn btn-primary">
                        <i class="fas fa-save"></i> Kaydet
                    </button>
                    <button type="button" id="delete-role" class="btn btn-danger ml-2">
                        <i class="fas fa-trash-alt"></i> Rolü Sil
                    </button>
                </div>
            </form>
        </div>

        <div id="user-management" class="bg-secondary-bg rounded-lg p-6 mb-6 hidden">
            <h3 class="text-lg font-semibold mb-4">Role Sahip Kullanıcılar</h3>
            <div class="mt-4">
                <input type="text" id="user-search" class="form-input w-full" placeholder="Kullanıcı Ara...">
            </div>
            <div id="users-with-role" class="space-y-2 mt-4 max-h-60 overflow-y-auto"></div>
            <div class="mt-4 border-t border-gray-700 pt-4">
                <h4 class="text-sm font-medium mb-2">Kullanıcı Ekle</h4>
                <input type="text" id="add-user-search" class="form-input w-full mb-2" placeholder="Kullanıcı Ara...">
                <label class="inline-flex items-center mb-2">
                    <input type="checkbox" id="select-all-users" class="form-checkbox">
                    <span class="ml-2 text-sm">Tümünü Seç</span>
                </label>
                <div id="add-user-list" class="space-y-2 max-h-60 overflow-y-auto"></div>
                <button id="add-users-to-role" class="btn btn-primary mt-2">
                    <i class="fas fa-plus"></i> Seçilenleri Ekle
                </button>
            </div>
        </div>
    </div>
</div>