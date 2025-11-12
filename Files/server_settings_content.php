<?php
// Mevcut server_settings.php'deki POST işlemleri ve veri çekme
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_server'])) {
        $stmt = $db->prepare("DELETE FROM servers WHERE id = ?");
        $stmt->execute([$server_id]);
        header("Location: directmessages");
        exit;
    } else {
        $new_server_name = trim($_POST['server_name']);
        $new_server_description = trim($_POST['server_description']);
        $new_server_avatar = $_FILES['server_pp'] ?? ['name' => '', 'error' => UPLOAD_ERR_NO_FILE];
        $new_server_banner = $_FILES['server_banner'] ?? ['name' => '', 'error' => UPLOAD_ERR_NO_FILE];
        $new_invite_background = $_FILES['invite_background'] ?? ['name' => '', 'error' => UPLOAD_ERR_NO_FILE];

        if (empty($new_server_name)) {
            $error = "Server name cannot be empty";
        } elseif (strlen($new_server_name) > 100) {
            $error = "Server name must be less than 100 characters";
        } else {
            $stmt = $db->prepare("UPDATE servers SET name = ? WHERE id = ?");
            $stmt->execute([$new_server_name, $server_id]);
        }

        if (isset($new_server_description)) {
            $stmt = $db->prepare("UPDATE servers SET description = ? WHERE id = ?");
            $stmt->execute([$new_server_description, $server_id]);
        }

        function handleFileUpload($file, $fieldName, $maxSize = 500000, $allowedTypes = ['jpg', 'jpeg', 'png', 'gif']) {
            global $db, $server_id, $server, $error;
            if ($file['error'] === UPLOAD_ERR_NO_FILE) return true;
            if (!empty($file['name'])) {
                if ($file['error'] !== UPLOAD_ERR_OK) {
                    $error = "Dosya yükleme hatası: " . $file['error'];
                    return false;
                }
                $check = getimagesize($file['tmp_name']);
                if ($check === false) {
                    $error = "Dosya bir resim değil.";
                    return false;
                }
                if ($file['size'] > $maxSize) {
                    $error = "Dosya çok büyük. Maksimum boyut " . ($maxSize/1000) . "KB.";
                    return false;
                }
                $imageFileType = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if (!in_array($imageFileType, $allowedTypes)) {
                    $error = "Üzgünüz, sadece " . implode(", ", $allowedTypes) . " dosyalarına izin verilir.";
                    return false;
                }
                $target_dir = "uploads/";
                if (!file_exists($target_dir)) mkdir($target_dir, 0777, true);
                $target_file = $target_dir . uniqid() . '_' . basename($file['name']);
                if (move_uploaded_file($file['tmp_name'], $target_file)) {
                    $stmt = $db->prepare("UPDATE servers SET $fieldName = ? WHERE id = ?");
                    $stmt->execute([$target_file, $server_id]);
                    if (!empty($server[$fieldName]) && file_exists($server[$fieldName])) {
                        unlink($server[$fieldName]);
                    }
                    return true;
                } else {
                    $error = "Üzgünüz, dosyanızı yüklerken bir hata oluştu.";
                    return false;
                }
            }
            return true;
        }

        if (isset($_POST['delete_pp'])) {
            if (!empty($server['profile_picture']) && file_exists($server['profile_picture'])) {
                unlink($server['profile_picture']);
            }
            $stmt = $db->prepare("UPDATE servers SET profile_picture = NULL WHERE id = ?");
            $stmt->execute([$server_id]);
            $server['profile_picture'] = NULL;
        }
        if (isset($_POST['delete_banner'])) {
            if (!empty($server['banner']) && file_exists($server['banner'])) {
                unlink($server['banner']);
            }
            $stmt = $db->prepare("UPDATE servers SET banner = NULL WHERE id = ?");
            $stmt->execute([$server_id]);
            $server['banner'] = NULL;
        }
        if (isset($_POST['delete_invite_background'])) {
            if (!empty($server['invite_background']) && file_exists($server['invite_background'])) {
                unlink($server['invite_background']);
            }
            $stmt = $db->prepare("UPDATE servers SET invite_background = NULL WHERE id = ?");
            $stmt->execute([$server_id]);
            $server['invite_background'] = NULL;
        }

        handleFileUpload($new_server_avatar, 'profile_picture');
        handleFileUpload($new_server_banner, 'banner', 1000000);
        handleFileUpload($new_invite_background, 'invite_background', 2000000);

        $show_in_community = isset($_POST['show_in_community']) ? 1 : 0;
        $stmt = $db->prepare("UPDATE servers SET show_in_community = ? WHERE id = ?");
        $stmt->execute([$show_in_community, $server_id]);

        $stmt = $db->prepare("SELECT * FROM servers WHERE id = ?");
        $stmt->execute([$server_id]);
        $server = $stmt->fetch();

        if (!isset($error)) {
            if (!isset($_GET['ajax'])) {
                header("Location: server_settings?id=" . $server_id . "&success=1");
                exit;
            } else {
                echo json_encode(['success' => true, 'message' => 'Ayarlar başarıyla kaydedildi!']);
                exit;
            }
        }
    }
}

$stmt = $db->prepare("SELECT * FROM roles WHERE id IN (SELECT role_id FROM user_roles WHERE server_id = ?)");
$stmt->execute([$server_id]);
$roles = $stmt->fetchAll();
?>

<div class="max-w-3xl mx-auto">
    <?php if (isset($error)): ?>
        <div class="bg-red-900/50 text-red-200 p-3 rounded mb-4 text-sm">
            <i class="fas fa-exclamation-circle mr-2"></i>
            <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php elseif (isset($_GET['success']) && $_GET['success'] == 1): ?>
        <div class="bg-green-900/50 text-green-200 p-3 rounded mb-4 text-sm">
            <i class="fas fa-check-circle mr-2"></i>
            Ayarlar başarıyla kaydedildi!
        </div>
    <?php endif; ?>

    <h2 class="text-xl font-semibold mb-6">Sunucu Ayarları</h2>
    
    <form method="POST" action="server_settings?id=<?php echo $server_id; ?>" enctype="multipart/form-data">
        <div class="form-section">
            <label for="server_name" class="form-label">Sunucu İsmi</label>
            <input type="text" name="server_name" id="server_name" class="form-input" 
                value="<?php echo htmlspecialchars($server['name'], ENT_QUOTES, 'UTF-8'); ?>" required>
        </div>

        <div class="form-section">
            <label for="server_description" class="form-label">Sunucu Açıklaması</label>
            <textarea name="server_description" id="server_description" class="form-input form-textarea"><?php echo htmlspecialchars($server['description'], ENT_QUOTES, 'UTF-8'); ?></textarea>
        </div>

        <div class="form-section">
            <label class="form-label">Sunucu Profil Resmi</label>
            <div class="upload-area" id="pp-upload-area">
                <input type="file" name="server_pp" id="server_pp" class="hidden" accept="image/*">
                <div class="flex flex-col items-center">
                    <?php if (!empty($server['profile_picture'])): ?>
                        <img src="<?php echo htmlspecialchars($server['profile_picture'], ENT_QUOTES, 'UTF-8'); ?>" 
                             alt="Mevcut Profil Resmi" 
                             class="w-16 h-16 rounded-full mb-2 object-cover" id="current_pp_preview">
                    <?php else: ?>
                        <div class="w-16 h-16 rounded-full bg-gray-700 flex items-center justify-center mb-2" id="default_pp_icon">
                            <i class="fas fa-user-circle text-3xl text-gray-500"></i>
                        </div>
                    <?php endif; ?>
                    <div class="text-xs text-gray-400 mb-2">PNG, JPG veya GIF (Max. 500KB)</div>
                    <div class="flex gap-2">
                        <button type="button" class="btn btn-secondary text-xs upload-button">
                            <i class="fas fa-upload text-xs"></i>
                            <span>Resim Yükle</span>
                        </button>
                        <?php if (!empty($server['profile_picture'])): ?>
                        <button type="submit" name="delete_pp" class="btn btn-danger text-xs">
                            <i class="fas fa-trash-alt text-xs"></i>
                            <span>Sil</span>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="form-section">
            <label class="form-label">Sunucu Bannerı</label>
            <div class="upload-area" id="banner-upload-area">
                <input type="file" name="server_banner" id="server_banner" class="hidden" accept="image/*">
                <div class="flex flex-col items-center">
                    <?php if (!empty($server['banner'])): ?>
                        <img src="<?php echo htmlspecialchars($server['banner'], ENT_QUOTES, 'UTF-8'); ?>" 
                             alt="Mevcut Banner" 
                             class="w-full h-24 rounded mb-2 object-cover" id="current_banner_preview">
                    <?php else: ?>
                        <div class="w-full h-24 rounded bg-gray-700 flex items-center justify-center mb-2" id="default_banner_icon">
                            <i class="fas fa-image text-2xl text-gray-500"></i>
                        </div>
                    <?php endif; ?>
                    <div class="text-xs text-gray-400 mb-2">PNG, JPG veya GIF (Max. 1MB)</div>
                    <div class="flex gap-2">
                        <button type="button" class="btn btn-secondary text-xs upload-button">
                            <i class="fas fa-upload text-xs"></i>
                            <span>Banner Yükle</span>
                        </button>
                        <?php if (!empty($server['banner'])): ?>
                        <button type="submit" name="delete_banner" class="btn btn-danger text-xs">
                            <i class="fas fa-trash-alt text-xs"></i>
                            <span>Sil</span>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="form-section">
            <label class="form-label">Davet Arka Planı</label>
            <div class="upload-area" id="invite-background-upload-area">
                <input type="file" name="invite_background" id="invite_background" class="hidden" accept="image/*">
                <div class="flex flex-col items-center">
                    <?php if (!empty($server['invite_background'])): ?>
                        <img src="<?php echo htmlspecialchars($server['invite_background'], ENT_QUOTES, 'UTF-8'); ?>" 
                             alt="Mevcut Davet Arka Planı" 
                             class="w-full h-24 rounded mb-2 object-cover" id="current_invite_background_preview">
                    <?php else: ?>
                        <div class="w-full h-24 rounded bg-gray-700 flex items-center justify-center mb-2" id="default_invite_background_icon">
                            <i class="fas fa-image text-2xl text-gray-500"></i>
                        </div>
                    <?php endif; ?>
                    <div class="text-xs text-gray-400 mb-2">PNG, JPG veya GIF (Max. 2MB)(Önerilen1920x1080)</div>
                    <div class="flex gap-2">
                        <button type="button" class="btn btn-secondary text-xs upload-button">
                            <i class="fas fa-upload text-xs"></i>
                            <span>Arka Plan Yükle</span>
                        </button>
                        <?php if (!empty($server['invite_background'])): ?>
                        <button type="submit" name="delete_invite_background" class="btn btn-danger text-xs">
                            <i class="fas fa-trash-alt text-xs"></i>
                            <span>Sil</span>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="form-section">
            <label class="flex items-center justify-between p-3 bg-gray-800/30 rounded">
                <div>
                    <div class="font-medium text-sm">Topluluk Sayfasında Göster</div>
                    <div class="text-xs text-gray-400">Sunucunuz topluluk sayfasında listelenecek</div>
                </div>
                <label class="switch">
                    <input type="checkbox" name="show_in_community" <?php echo $server['show_in_community'] ? 'checked' : ''; ?>>
                    <span class="slider"></span>
                </label>
            </label>
        </div>

        <div class="form-section">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i>
                <span>Değişiklikleri Kaydet</span>
            </button>
        </div>
    </form>

    <div class="mt-10 border-t border-gray-800 pt-6">
        <h3 class="text-lg font-semibold text-red-400 mb-4">Tehlikeli Bölge</h3>
        <div class="danger-zone p-4 rounded">
            <div class="flex justify-between items-center">
                <div>
                    <h4 class="font-medium">Sunucuyu Sil</h4>
                    <p class="text-xs text-gray-400">Bu işlem geri alınamaz</p>
                </div>
                <button type="button" id="delete-server-btn" class="btn btn-danger text-sm">
                    <i class="fas fa-trash-alt"></i>
                    <span>Sunucuyu Sil</span>
                </button>
            </div>
        </div>
    </div>
</div>

<div id="confirmation-modal" class="fixed inset-0 flex items-center justify-center hidden z-50">
    <div class="modal-overlay absolute inset-0"></div>
    <div class="modal-content relative p-6 max-w-md w-full mx-4">
        <div class="text-center mb-6">
            <div class="w-12 h-12 bg-red-900/30 rounded-full flex items-center justify-center mx-auto mb-3">
                <i class="fas fa-exclamation-triangle text-red-400"></i>
            </div>
            <h3 class="font-semibold mb-1">Sunucuyu Silmek İstediğinize Emin Misiniz?</h3>
            <p class="text-sm text-gray-400">
                Bu işlem geri alınamaz. Sunucu ve tüm içeriği kalıcı olarak silinecektir.
            </p>
        </div>
        <div class="flex justify-end gap-2">
            <button type="button" id="cancel-delete-btn" class="btn btn-secondary">
                <span>İptal</span>
            </button>
            <form id="delete-server-form" method="POST" action="server_settings?id=<?php echo $server_id; ?>">
                <input type="hidden" name="delete_server" value="1">
                <button type="submit" class="btn btn-danger">
                    <span>Sil</span>
                </button>
            </form>
        </div>
    </div>
</div>