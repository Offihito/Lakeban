<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Varsayılan dil
$default_lang = 'tr'; // Varsayılan dil Türkçe

// Kullanıcının tarayıcı dilini al
if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
    $browser_lang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);
    // Desteklenen dilleri kontrol et
    $supported_languages = ['tr', 'en', 'fı', 'de', 'fr', 'ru'];
    if (in_array($browser_lang, $supported_languages)) {
        $default_lang = $browser_lang;
    }
}

// Dil seçeneğini kontrol et
if (isset($_GET['lang'])) {
    $_SESSION['lang'] = $_GET['lang'];
} else if (!isset($_SESSION['lang'])) {
    $_SESSION['lang'] = $default_lang; // Tarayıcı dilini varsayılan olarak ayarla
}

$lang = $_SESSION['lang'];

// Dil dosyalarını yükleme fonksiyonu
function loadLanguage($lang) {
    $langFile = __DIR__ . '/languages/' . $lang . '.json';
    if (file_exists($langFile)) {
        return json_decode(file_get_contents($langFile), true);
    }
    return [];
}

$translations = loadLanguage($lang);

require_once 'database/db_connection.php';

// Session check
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit;
}

$userId = $_SESSION['user_id'];

$stmt = $db->prepare("
    SELECT u.username, u.status, p.avatar_url
    FROM users u
    LEFT JOIN user_profiles p ON u.id = p.user_id
    WHERE u.id = ?
");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($user) {
    $_SESSION['username'] = $user['username'] ?? 'Bilinmeyen Kullanıcı';
    $_SESSION['avatar_url'] = $user['avatar_url'] ?? 'avatars/default-avatar.png';
    $_SESSION['status'] = $user['status'] ?? 'online';
    error_log("PHP Oturum: user_id=$userId, username={$_SESSION['username']}, avatar_url={$_SESSION['avatar_url']}, status={$_SESSION['status']}");
} else {
    error_log("Hata: user_id=$userId için kullanıcı bulunamadı");
    header("Location: login");
    exit;
}
// Functions
function getFriends($db, $userId, $includeHidden = false) {
    $query = "
        SELECT 
            u.id, 
            u.username, 
            u.status, -- Zaten status sütununu çekiyoruz
            up.avatar_url,
            CASE 
                WHEN TIMESTAMPDIFF(MINUTE, u.last_activity, CURRENT_TIMESTAMP) <= 2 THEN 1 
                ELSE 0 
            END as is_online,
            COALESCE((
                SELECT COUNT(*) 
                FROM messages1 
                WHERE sender_id = u.id AND receiver_id = ? AND read_status = FALSE
            ), 0) as unread_messages,
            MAX(m.created_at) as last_interaction
        FROM friends f
        JOIN users u ON (f.friend_id = u.id OR f.user_id = u.id)
        LEFT JOIN user_profiles up ON u.id = up.user_id
        LEFT JOIN messages1 m ON (m.sender_id = u.id OR m.receiver_id = u.id) 
            AND (m.sender_id = ? OR m.receiver_id = ?)
        WHERE (f.user_id = ? OR f.friend_id = ?) 
        AND u.id != ?
    ";
    if (!$includeHidden) {
        $query .= " AND NOT EXISTS (
            SELECT 1 
            FROM hidden_friends hf 
            WHERE hf.user_id = ? AND hf.friend_id = u.id
        )";
    }
    $query .= "
        GROUP BY u.id
        ORDER BY last_interaction DESC, is_online DESC, u.username ASC
    ";
    $stmt = $db->prepare($query);
    if ($includeHidden) {
        $stmt->execute([$userId, $userId, $userId, $userId, $userId, $userId]);
    } else {
        $stmt->execute([$userId, $userId, $userId, $userId, $userId, $userId, $userId]);
    }
    return $stmt->fetchAll();
}
function isFriendHidden($db, $userId, $friendId) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM hidden_friends WHERE user_id = ? AND friend_id = ?");
    $stmt->execute([$userId, $friendId]);
    return $stmt->fetchColumn() > 0;
}

function getGroupUnreadCount($db, $userId, $groupId) {
    $stmt = $db->prepare("
        SELECT COUNT(*) as unread_count
        FROM messages1 m
        WHERE m.group_id = :group_id
        AND m.id > (
            SELECT COALESCE(last_read_message_id, 0)
            FROM group_members
            WHERE user_id = :user_id
            AND group_id = :group_id
        )
        AND m.sender_id != :user_id
        AND m.created_at > (
            SELECT joined_at 
            FROM group_members 
            WHERE user_id = :user_id 
            AND group_id = :group_id
        )
    ");
    $stmt->execute([
        ':user_id' => $userId,
        ':group_id' => $groupId
    ]);
    return (int)$stmt->fetch()['unread_count'];
}

function getFriendRequests($db, $userId) {
    $stmt = $db->prepare("
        SELECT 
            u.id, 
            u.username, 
            up.avatar_url
        FROM friend_requests fr
        JOIN users u ON fr.sender_id = u.id
        LEFT JOIN user_profiles up ON u.id = up.user_id
        WHERE fr.receiver_id = ? AND fr.status = 'pending'
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function getFriendRequestsCount($db, $userId) {
    $stmt = $db->prepare("
        SELECT COUNT(*) as request_count
        FROM friend_requests
        WHERE receiver_id = ? AND status = 'pending'
    ");
    $stmt->execute([$userId]);
    $result = $stmt->fetch();
    return $result['request_count'];
}


// Grup oluşturma fonksiyonu
function createGroup($db, $name, $creatorId, $members) {
    try {
        $db->beginTransaction();
        
        // Grubu oluştur
        $stmt = $db->prepare("INSERT INTO groups (name, creator_id, avatar_url) VALUES (?, ?, ?)");
        $stmt->execute([$name, $creatorId, 'avatars/default-group-avatar.png']);
        $groupId = $db->lastInsertId();
        
        // Üyeleri ekle
        $stmt = $db->prepare("INSERT INTO group_members (group_id, user_id) VALUES (?, ?)");
        foreach($members as $memberId) {
            $stmt->execute([$groupId, $memberId]);
        }
        
        $db->commit();
        return $groupId;
    } catch(PDOException $e) {
        $db->rollBack();
        error_log("Group creation error: ".$e->getMessage());
        return false;
    }
}

// Kullanıcının gruplarını getir
function getGroups($db, $userId) {
    $stmt = $db->prepare("
        SELECT 
            g.id, 
            g.name,
            g.avatar_url,
            g.creator_id,
            (SELECT COUNT(*) FROM group_members WHERE group_id = g.id) as member_count,
            (g.creator_id = ?) as is_owner,
            MAX(m.created_at) as last_interaction
        FROM groups g
        JOIN group_members gm ON g.id = gm.group_id
        LEFT JOIN messages1 m ON m.group_id = g.id
        WHERE gm.user_id = ?
        GROUP BY g.id
        ORDER BY last_interaction DESC
    ");
    $stmt->execute([$userId, $userId]);
    return $stmt->fetchAll();
}
// Tema ayarlarını yükle
$defaultTheme = 'dark';
$defaultCustomColor = '#663399';
$defaultSecondaryColor = '#3CB371';

try {
    $userStmt = $db->prepare("SELECT theme, custom_color, secondary_color FROM users WHERE id = ?");
    $userStmt->execute([$userId]);
    $userData = $userStmt->fetch(PDO::FETCH_ASSOC);
    $currentTheme = $userData['theme'] ?? $defaultTheme;
    $currentCustomColor = $userData['custom_color'] ?? $defaultCustomColor;
    $currentSecondaryColor = $userData['secondary_color'] ?? $defaultSecondaryColor;
} catch (PDOException $e) {
    error_log("Tema ayarları alınırken hata: " . $e->getMessage());
    $currentTheme = $defaultTheme;
    $currentCustomColor = $defaultCustomColor;
    $currentSecondaryColor = $defaultSecondaryColor;
}
function getServers($db, $userId) {
    $stmt = $db->prepare("
        SELECT 
            s.id, 
            s.name, 
            s.description,
            s.profile_picture,
            sm.position,
            COALESCE((
                SELECT COUNT(DISTINCT c.id)
                FROM channels c
                LEFT JOIN user_read_messages urm ON urm.channel_id = c.id AND urm.user_id = ?
                LEFT JOIN (
                    SELECT channel_id, MAX(id) as latest_message_id
                    FROM messages1
                    GROUP BY channel_id
                ) latest_msg ON c.id = latest_msg.channel_id
                WHERE c.server_id = s.id
                    AND c.type IN ('text', 'announcement')
                    AND (c.restricted_to_role_id IS NULL 
                         OR c.restricted_to_role_id IN (
                             SELECT role_id FROM user_roles WHERE user_id = ? AND server_id = s.id
                         )
                         OR s.owner_id = ?)
                    AND (latest_msg.latest_message_id IS NOT NULL 
                         AND (urm.last_read_message_id IS NULL OR 
                              latest_msg.latest_message_id > urm.last_read_message_id))
            ), 0) AS unread_channel_count
        FROM servers s
        JOIN server_members sm ON s.id = sm.server_id
        WHERE sm.user_id = ?
        GROUP BY s.id, s.name, s.description, s.profile_picture, sm.position
        ORDER BY sm.position ASC
    ");
    $stmt->execute([$userId, $userId, $userId, $userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function blockFriend($db, $userId, $friendId) {
    $stmt = $db->prepare("INSERT INTO blocked_friends (user_id, blocked_user_id) VALUES (?, ?)");
    return $stmt->execute([$userId, $friendId]);
}

function unblockFriend($db, $userId, $friendId) {
    $stmt = $db->prepare("DELETE FROM blocked_friends WHERE user_id = ? AND blocked_user_id = ?");
    return $stmt->execute([$userId, $friendId]);
}

function getBlockedFriends($db, $userId) {
    $stmt = $db->prepare("
        SELECT 
            u.id, 
            u.username, 
            up.avatar_url
        FROM blocked_friends bf
        JOIN users u ON bf.blocked_user_id = u.id
        LEFT JOIN user_profiles up ON u.id = up.user_id
        WHERE bf.user_id = ?
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

// Gönderilen arkadaşlık isteklerini getir
function getSentFriendRequests($db, $userId) {
    $stmt = $db->prepare("
        SELECT 
            u.id, 
            u.username, 
            up.avatar_url
        FROM friend_requests fr
        JOIN users u ON fr.receiver_id = u.id
        LEFT JOIN user_profiles up ON u.id = up.user_id
        WHERE fr.sender_id = ? AND fr.status = 'pending'
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

// Kullanımı
$sent_requests = getSentFriendRequests($db, $_SESSION['user_id']);
// Get friends, friend requests, and servers
$allFriends = getFriends($db, $_SESSION['user_id'], true); // Include hidden friends for the friends list
$friends = getFriends($db, $_SESSION['user_id'], false); // Non-hidden friends for DM list
$friend_requests = getFriendRequests($db, $_SESSION['user_id']);
$servers = getServers($db, $_SESSION['user_id']);
$blocked_friends = getBlockedFriends($db, $_SESSION['user_id']);
?>
<!DOCTYPE html>
<html lang="tr" class="<?= htmlspecialchars($currentTheme) ?>-theme" style="<?= $currentTheme === 'custom' ? "--custom-background-color: " . htmlspecialchars($currentCustomColor) . "; --custom-secondary-color: " . htmlspecialchars($currentSecondaryColor) . ";" : '' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo $translations['Dm']; ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link rel="icon" type="image/x-icon" href="/icon.ico">
    <script src="https://cdn.jsdelivr.net/npm/twemoji@latest/dist/twemoji.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.0/Sortable.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/lucide@latest/dist/umd/lucide.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/socket.io/4.0.1/socket.io.js"></script>
    <audio id="notification-sound" src="/bildirim.mp3" preload="auto"></audio>
    <audio id="call-music-1" src="LakebanAssets/inanamıyorum.mp3" preload="auto"></audio>
<audio id="call-music-2" src="LakebanAssets/inanabiliyorum.mp3" preload="auto"></audio>
    <script src="https://cdn.jsdelivr.net/npm/emoji-mart@latest/dist/browser.js"></script> <!-- Emoji Mart Kütüphanesi -->
    <link rel="stylesheet" href="directmessages.css">
    <style>
                
      body {
    position: relative;
    width: 100%;
    height: 100vh;
    margin: 0;
    overflow: hidden; /* Adjust based on your needs */
}

        .light-theme body {
            background-color: #F2F3F5;
            color: #2E3338;
        }

        .dark-theme body {
            background-color: #1E1E1E;
            color: #ffffff;
        }

        .custom-theme body {
            background-color: color-mix(in srgb, var(--custom-background-color) 90%, var(--custom-secondary-color) 10%);
            color: #ffffff;
        }
      
        @media (min-width: 769px) {
            #movesidebar {
                right: 0%;
            }
            .custom-width {
                width: 79%; /* %70 genişlik */
            }
        }
        
/* Mobil cihazlar için stil ayarları */
/* Mobil cihazlar için stil ayarları */
@media (max-width: 768px) {
    
    .mobile-container {
        display: flex;
        flex-direction: column;
        height: 100vh;
        overflow: hidden;
    }

    .mobile-section {
        flex: 1;
        overflow-y: auto;
        padding: 1rem;
        box-sizing: border-box;
    }

    /* Left Panel */
    .w-60 {
        width: 100% !important; /* Sol paneli tam genişlik yap */
    }

    /* Friends List */
    .friends-list {
        height: calc(100vh - 120px); /* Yüksekliği ayarla */
        overflow-y: auto;
    }

    /* Chat Area */
    #chat-container {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        z-index: 1000;
        background-color: #36393f;
    }

    /* Chat Header */
    #chat-header {
        height: 60px;
        display: flex;
        align-items: center;
        padding: 0 16px;
    }
    
    /* Message Container */
    #message-container {
        height: calc(100vh - 120px); /* Mesaj alanını tam ekran yap */
        overflow-y: auto;
    }

    /* Input Area */
    #input-area {
        height: 60px;
        display: flex;
        align-items: center;
        padding: 0 16px;
    }

    /* Back Button */
    #back-to-friends {
        display: block; /* Geri düğmesini göster */
    }
    #group-info {
    display: block; /* Geri düğmesini göster */
    }
    
    /* İçerik genişlik ayarı */
    .consecutive-message .flex-1 {
        width: calc(100% - 20px) !important;
        margin-left: 0 !important;
        padding-left: 8px !important;
    }
    
    /* Message-Avatar */
    .message-avatar {
        margin-right: 12px;
    }
}
/* Mevcut stillere ekle */
.send-button {
    display: none; /* Masaüstünde varsayılan olarak gizle */
}

@media (max-width: 768px) {
    .send-button {
        display: flex !important; /* Mobilde göster */
    }
    
    /* Mobilde input alanını daha dar yap */
    .message-input {
        padding-right: 60px;
    }
    
    
    #movesidebar {
        width: 100%;
        right: 0;
        top: 0;
        position: absolute;
        height: 100vh;
        will-change: transform;
    }
    

    
    #top-menu {
        padding: 1px;
        justify-content: flex-start;
    }
    .message-and-forms-container .flex-1 {
        margin-left: 0px !important;
    }
}

/* Mevcut textarea stillerinin üzerine yazmamak için */
#input-area {
    position: relative;
}
#add-friend-modal-background {
    background-color: #181818; /*#2d2f34 - Eskisi*/
    border: 1px solid black;
}
#cancel-add-friend:hover {
    box-shadow: 0px 0px 20px #020202;
    transform: scale(1.05);
}
#confirm-add-friend:hover {
    box-shadow: 0px 0px 10px #3CB371;
    transform: scale(1.05);
}
#friend-username {
    background-color: #505050;
}
#create-server-modal {
    --primary-bg: #1a1b1e;
    --secondary-bg: #2d2f34;
    --accent-color: #3CB371;
    --text-primary: #ffffff;
    --text-secondary: #b9bbbe;
}

#create-server-modal .bg-\[\#2d2f34\] { /* Modal içeriği */
    background-color: var(--secondary-bg);
    border: 1px solid rgba(255, 255, 255, 0.1);
}

#create-server-modal .upload-area {
    border: 2px dashed rgba(255, 255, 255, 0.2);
    border-radius: 0.75rem;
    padding: 2rem;
    text-align: center;
    cursor: pointer;
    transition: all 0.3s ease;
}

#create-server-modal .upload-area:hover {
    border-color: var(--accent-color);
    background-color: rgba(60, 179, 113, 0.1);
}

#create-server-modal .form-input {
    background-color: var(--secondary-bg);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 0.5rem;
    padding: 0.75rem 1rem;
    color: var(--text-primary);
    transition: all 0.3s ease;
}

#create-server-modal .form-input:focus {
    border-color: var(--accent-color);
    outline: none;
    box-shadow: 0 0 0 2px rgba(60, 179, 113, 0.2);
}

#create-server-modal .btn {
    padding: 0.75rem 1.5rem;
    border-radius: 0.5rem;
    font-weight: 600;
    transition: all 0.3s ease;
}

#create-server-modal .btn-primary {
    background-color: var(--accent-color);
    color: white;
}

#create-server-modal .btn-primary:hover {
    background-color: #2E8B57;
    transform: translateY(-1px);
}

#create-server-modal .bg-gray-600 { /* İptal butonu */
    background-color: #36393f;
}

#create-server-modal .bg-gray-600:hover {
    background-color: #40444b;
}
/* Açık Tema için Değişkenler */
.light-theme #create-server-modal {
    --primary-bg: #F2F3F5; /* Açık gri */
    --secondary-bg: #FFFFFF; /* Beyaz */
    --accent-color: #5865F2; /* Mavi */
    --text-primary: #2E3338; /* Koyu gri */
    --text-secondary: #4F5660; /* Daha açık gri */
}


/* Modal İçeriği */
.light-theme #create-server-modal .modal-content {
    background-color: var(--secondary-bg);
    border: 1px solid rgba(0, 0, 0, 0.1); /* Açık tema için koyu kenarlık, koyu tema için daha şeffaf */
}

/* Dosya Yükleme Alanı */
.light-theme #create-server-modal .upload-area {
    border: 2px dashed rgba(0, 0, 0, 0.2); /* Açık tema için koyu, koyu tema için açık */
    border-radius: 0.75rem;
    padding: 2rem;
    text-align: center;
    cursor: pointer;
    transition: all 0.3s ease;
}

.light-theme #create-server-modal .upload-area:hover {
    border-color: var(--accent-color); /* #5865F2 */
    background-color: rgba(88, 101, 242, 0.1); /* Mavi ton */
}


/* Form Girdi Alanları */
.light-theme #create-server-modal .form-input {
    background-color: var(--secondary-bg);
    border: 1px solid rgba(0, 0, 0, 0.1); /* Açık tema için koyu kenarlık */
    border-radius: 0.5rem;
    padding: 0.75rem 1rem;
    color: var(--text-primary);
    transition: all 0.3s ease;
}

.light-theme #create-server-modal .form-input:focus {
    border-color: var(--accent-color); /* #5865F2 */
    outline: none;
    box-shadow: 0 0 0 2px rgba(88, 101, 242, 0.2);
}



/* Butonlar */
.light-theme #create-server-modal .btn {
    padding: 0.75rem 1.5rem;
    border-radius: 0.5rem;
    font-weight: 600;
    transition: all 0.3s ease;
}

.light-theme #create-server-modal .btn-primary {
    background-color: var(--accent-color); /* #5865F2 */
    color: #ffffff;
}

.light-theme #create-server-modal .btn-primary:hover {
    background-color: #4752C4; /* Daha koyu mavi */
    transform: translateY(-1px);
}



/* İptal Butonu */
.light-theme #create-server-modal .btn-cancel {
    background-color: #D1D5DB; /* Açık gri */
}

.light-theme #create-server-modal .btn-cancel:hover {
    background-color: #B0B3B8; /* Daha koyu gri */
}


/* Custom Tema için Değişkenler */
.custom-theme #create-server-modal {
    --primary-bg: color-mix(in srgb, var(--custom-background-color) 90%, #000000 10%); /* Ana renge biraz koyuluk */
    --secondary-bg: color-mix(in srgb, var(--custom-background-color) 70%, #1a1b1e 30%); /* Daha koyu ton */
    --accent-color: var(--custom-secondary-color); /* Kullanıcı tanımlı ikincil renk */
    --text-primary: #ffffff; /* Beyaz metin */
    --text-secondary: color-mix(in srgb, #ffffff 70%, var(--custom-secondary-color) 30%); /* Hafif renkli ikincil metin */
}

/* Modal İçeriği */
.custom-theme #create-server-modal .modal-content {
    background-color: var(--secondary-bg);
    border: 1px solid rgba(255, 255, 255, 0.1);
}

/* Dosya Yükleme Alanı */
.custom-theme #create-server-modal .upload-area {
    border: 2px dashed rgba(255, 255, 255, 0.2);
    border-radius: 0.75rem;
    padding: 2rem;
    text-align: center;
    cursor: pointer;
    transition: all 0.3s ease;
}
.custom-theme #create-server-modal .upload-area:hover {
    border-color: var(--accent-color);
    background-color: color-mix(in srgb, var(--custom-secondary-color) 10%, transparent 90%);
}

/* Form Girdi Alanları */
.custom-theme #create-server-modal .form-input {
    background-color: var(--secondary-bg);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 0.5rem;
    padding: 0.75rem 1rem;
    color: var(--text-primary);
    transition: all 0.3s ease;
}
.custom-theme #create-server-modal .form-input:focus {
    border-color: var(--accent-color);
    outline: none;
    box-shadow: 0 0 0 2px color-mix(in srgb, var(--custom-secondary-color) 20%, transparent 80%);
}

/* Butonlar */
.custom-theme #create-server-modal .btn {
    padding: 0.75rem 1.5rem;
    border-radius: 0.5rem;
    font-weight: 600;
    transition: all 0.3s ease;
}
.custom-theme #create-server-modal .btn-primary {
    background-color: var(--accent-color);
    color: #ffffff;
}
.custom-theme #create-server-modal .btn-primary:hover {
    background-color: color-mix(in srgb, var(--custom-secondary-color) 80%, #000000 20%); /* Daha koyu ton */
    transform: translateY(-1px);
}
.custom-theme #create-server-modal .btn-cancel {
    background-color: color-mix(in srgb, var(--custom-background-color) 50%, #1a1b1e 50%); /* Ana renge dayalı iptal butonu */
}
.custom-theme #create-server-modal .btn-cancel:hover {
    background-color: color-mix(in srgb, var(--custom-background-color) 60%, #2d2f34 40%); /* Hafif açık ton */
}


.message-container video {
    width: auto; /* Sabit genişlik kaldırıldı */
    height: auto; /* Sabit yükseklik kaldırıldı */
    max-width: 100%; /* Video, konteynerin genişliğini aşmasın */
    object-fit: contain; /* Doğal en-boy oranını koru */
    border-radius: 8px;
    margin: 8px 0;
    background: #000;
}

/* Aspect ratio kısıtlamasını kaldır */
.message-container video {
    aspect-ratio: auto; /* Videonun doğal en-boy oranını kullan */
}

/* Mobil cihazlar için */
@media (max-width: 768px) {
    .message-container video {
        max-width: 100%; /* Mobil cihazlarda da konteynere sığsın */
        height: auto;
    }
}

/* Mobil boyutlar */
@media (max-width: 768px) {
    .message-container img,
    .message-container video {
        max-width: 250px;
        max-height: 200px;
    }
}

/* Dosya linkleri için */
.message-container a[href*="download"] {
    display: inline-block;
    padding: 8px 16px;
    background: #5865f2;
    color: white;
    border-radius: 4px;
    margin: 8px 0;
    text-decoration: none;
    font-size: 14px;
}

.message-container a[href*="download"]:hover {
    background: #4752c4;
}
   @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @keyframes fadeOut {
            from { opacity: 1; transform: translateY(0); }
            to { opacity: 0; transform: translateY(10px); }
        }
        
        .typing-animation {
            display: inline-flex;
            align-items: center;
            height: 20px;
        }
        
 @keyframes typing-bounce {
  0%, 60%, 100% { transform: translateY(0); }
  30% { transform: translateY(-4px); }
}

.typing-animation span {
  display: inline-block;
  width: 8px;  /* Önceki 4px'ten büyük */
  height: 8px; /* Önceki 4px'ten büyük */
  background: #7289da;
  border-radius: 50%;
  margin: 0 3px; /* Noktalar arası boşluk artırıldı */
  animation: typing-bounce 1.4s infinite ease-in-out;
}

.typing-animation span:nth-child(2) {
  animation-delay: 0.2s;
}

.typing-animation span:nth-child(3) {
  animation-delay: 0.4s;
}
@keyframes slide-in {
  from {
    transform: translateX(-10px);
    opacity: 0;
  }
  to {
    transform: translateX(0);
    opacity: 1;
  }
}
       /* Özelleştirilmiş Scrollbar */
::-webkit-scrollbar {
  width: 8px;
  height: 8px;
}

::-webkit-scrollbar-track {
  background: #2a2a2a;
  border-radius: 10px;
}

::-webkit-scrollbar-thumb {
  background: var(--primary-gradient);
  border-radius: 10px;
  border: 2px solid #2a2a2a;
}

::-webkit-scrollbar-thumb:hover {
  background: var(--hover-gradient);
} 
.light-theme ::-webkit-scrollbar {
  width: 8px;
  height: 8px;
}
.light-theme ::-webkit-scrollbar-track {
  background: #E0E1E2; /* Açık gri track */
  border-radius: 10px;
}
.light-theme ::-webkit-scrollbar-thumb {
  background: var(--light-primary-gradient);
  border-radius: 10px;
  border: 2px solid #E0E1E2; /* Track ile uyumlu kenarlık */
}
.light-theme ::-webkit-scrollbar-thumb:hover {
  background: var(--light-hover-gradient);
}



/* Özel Tema için Scrollbar */
.custom-theme ::-webkit-scrollbar {
  width: 8px;
  height: 8px;
}
.custom-theme ::-webkit-scrollbar-track {
  background: color-mix(in srgb, var(--custom-background-color) 80%, #000000 20%); /* Ana renge biraz koyuluk */
  border-radius: 10px;
}
.custom-theme ::-webkit-scrollbar-thumb {
  background: linear-gradient(135deg, var(--custom-secondary-color) 0%, color-mix(in srgb, var(--custom-background-color) 50%, #000000 50%) 100%);
  border-radius: 10px;
  border: 2px solid color-mix(in srgb, var(--custom-background-color) 80%, #000000 20%);
}
.custom-theme ::-webkit-scrollbar-thumb:hover {
  background: linear-gradient(135deg, var(--custom-secondary-color) 0%, color-mix(in srgb, var(--custom-secondary-color) 80%, #ffffff 20%) 100%);
}
:root {
  --primary-gradient: linear-gradient(135deg, #3CB371 0%, #121212 100%);
  --hover-gradient: linear-gradient(135deg, #3CB371 0%, #121212 100%);
}




.friend-item:hover {
  transform: translateY(-3px);
  box-shadow: 0 8px 16px rgba(0,0,0,0.2);
  background: var(--hover-gradient);
}
/* Açık Tema için Hover Efekti */
.light-theme .friend-item:hover {
  transform: translateY(-3px);
  box-shadow: 0 8px 16px rgba(0, 0, 0, 0.15); /* Daha hafif gölge */
  background: var(--light-hover-gradient);
}



/* Özel Tema için Hover Efekti */
.custom-theme .friend-item:hover {
  transform: translateY(-3px);
  box-shadow: 0 8px 16px color-mix(in srgb, var(--custom-background-color) 30%, #000000 70%); /* Dinamik gölge */
  background: linear-gradient(135deg, var(--custom-secondary-color) 0%, color-mix(in srgb, var(--custom-background-color) 50%, #000000 50%) 100%);
}

/* Animasyonlar */
@keyframes slideIn {
  from { opacity: 0; transform: translateY(20px); }
  to { opacity: 1; transform: translateY(0); }
}

@keyframes slideInFromRight {
  0% {
    transform: translateX(100%);
  }
  100% {
    transform: translateX(0);
  }
}

@keyframes float {
  0% { transform: translateY(0px); }
  50% { transform: translateY(-5px); }
  100% { transform: translateY(0px); }
}

.chat-container {
  animation: slideInFromRight 0.6s cubic-bezier(0.23, 1, 0.32, 1) forwards;
}

.message-input {
  background: #363636;
  border-radius: 15px;
  transition: all 0.3s;
  border: 2px solid transparent;
}

.message-input:focus {
  border-color: #6366f1;
  box-shadow: 0 0 15px rgba(99,102,241,0.2);
}

/* File Upload */
.file-upload-label {
  border: 2px dashed #6366f1;
  transition: all 0.3s;
}

.file-upload-label:hover {
  background: rgba(99,102,241,0.1);
  transform: scale(1.02);
}
/* Mesaj Kabarcıkları */
.message-container {
  background: rgba(255,255,255,0.05);
  border-radius: 1rem;
  transition: all 0.3s ease;
}
/* Mevcut stillere ekle */
#file-preview-container {
    max-width: 300px;
    transition: all 0.3s ease;
}

#cancel-file {
    font-size: 18px;
    line-height: 1;
    cursor: pointer;
    transition: transform 0.2s;
}

#cancel-file:hover {
    transform: scale(1.1);
}

/* Özel stiller */
#file-preview-container {
    background: rgba(255,255,255,0.05);
    border-color: #ef4444; /* Kırmızı border */
}

.preview-image {
    max-width: 280px;
    max-height: 200px;
    border-radius: 6px;
    object-fit: contain;
}

.preview-video {
    max-width: 280px;
    max-height: 200px;
    border-radius: 6px;
}

.file-icon {
    font-size: 48px;
    color: #ef4444;
    margin: 10px 0;
}
.unread-badge {
  display: flex !important;
  position: absolute;
  top: -5px;
  right: -5px;
}
/* Yanıtlama Formu Stilleri */
.reply-message-form {
    background-color: #2f3136;
    border-left: 4px solid #7289da;
    padding: 12px;
    margin-top: 8px;
    border-radius: 6px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.reply-message-form textarea {
    width: 100%;
    background: #40444b !important;
    border: 1px solid #202225 !important;
    color: #dcddde;
    padding: 10px;
    border-radius: 5px;
    margin-bottom: 8px;
    resize: vertical;
    min-height: 80px;
}

/* Düzenleme Formu Stilleri */
.edit-message-form {
    background-color: #2f3136;
    border-left: 4px solid #3ba55d;
    padding: 12px;
    margin-top: 8px;
    border-radius: 6px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.edit-message-form textarea {
    width: 100%;
    background: #40444b !important;
    border: 1px solid #202225 !important;
    color: #dcddde;
    padding: 10px;
    border-radius: 5px;
    margin-bottom: 8px;
    resize: vertical;
    min-height: 100px;
}

/* Ortak Button Stilleri */
.reply-message-form button,
.edit-message-form button {
    transition: all 0.2s ease;
    padding: 8px 16px;
    border-radius: 4px;
    font-weight: 500;
}

.reply-message-form button[type="submit"],
.edit-message-form button[type="submit"] {
    background-color: #5865f2;
    color: white;
}

.reply-message-form button[type="submit"]:hover,
.edit-message-form button[type="submit"]:hover {
    background-color: #4752c4;
}

.reply-message-form .cancel-reply,
.edit-message-form .cancel-edit {
    background-color: #747f8d;
    color: white;
    margin-left: 8px;
}

.reply-message-form .cancel-reply:hover,
.edit-message-form .cancel-edit:hover {
    background-color: #5d6772;
}

/* Form Konteynır Pozisyonlaması */
.message-and-forms-container {
    position: relative;
    margin-bottom: 1.5rem;
}





/* Mobil Uyumluluk */
@media (max-width: 768px) {
    ::-webkit-scrollbar {
      display: none;
    }
    .reply-message-form,
    .edit-message-form {
        padding: 10px;
        margin-left: -10px;
        margin-right: -10px;
    }
    
    .reply-message-form textarea,
    .edit-message-form textarea {
        min-height: 60px;
    }
    
}


/* Tooltip için */
[data-tooltip] {
    position: relative;
    overflow: visible;
}

[data-tooltip]::after {
    content: attr(data-tooltip);
    position: absolute;
    bottom: 100%;
    left: 50%;
    transform: translateX(-50%);
    background: #1a1a1a;
    color: white;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    white-space: nowrap;
    opacity: 0;
    visibility: hidden;
    transition: all 0.2s ease;
    pointer-events: none;
    border: 1px solid #333;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
}

[data-tooltip]:hover::after {
    opacity: 1;
    visibility: visible;
    transform: translateX(-50%) translateY(-4px);
}
/* Responsive Tasarım */
@media (max-width: 640px) {
  #group-members-modal > div {
    width: 90%;
  }
  
  .group-member-item {
    padding: 10px;
    margin: 6px 0;
  }
  
  .member-avatar {
    width: 40px;
    height: 40px;
  }
  
  .group-member-item .text-white {
    font-size: 0.9rem;
  }
}
/* Mesaj konteynırı için temel stiller */
.message-and-forms-container {
    position: relative;
    margin-bottom: 0px; /* Normal mesajlar arası boşluk */
}

/* Stack'lenmiş mesajlar için özel stiller */
.consecutive-message {
    margin-top: 0px !important; /* Üst boşluğu azalt */
    padding: 3px 8px !important; /* Padding'i küçült */
    border-radius: 2px !important; /* Köşe yuvarlaklığı */
}

/* Stack'lenmiş mesaj konteynırları için margin ayarı */
.message-and-forms-container.consecutive-message-container {
    margin-bottom: 0px; /* Alttaki boşluğu azalt */
}

/* Avatarın tamamen gizlenmesi için */
.consecutive-message .message-avatar {
    display: none !important;
}

/* Mesaj içeriği hizalaması */
.consecutive-message .flex-1 {
    margin-left: 0 !important; /* Sol boşluğu kaldır */
}
/* Normal mesajlar için border */
.message-container {
    border-radius: 2px !important;
}

/* Stack'lenmiş mesajlar için border ve radius ayarları */
.consecutive-message {
    border: none !important;
    border-radius: 2px !important;
    box-shadow: none !important;
}

/* Üst üste gelen mesajlar arası geçiş */
.consecutive-message::after {
    content: '';
    display: block;
    height: 2px;
    background: transparent;
}

/* Hover efekt ayarı */
.consecutive-message:hover {
    border: none !important;
}
/* Üç nokta butonu için düzeltmeler */
.more-options-button {
    position: absolute !important;
    right: 8px;
    top: 8px;
    margin-left: 0 !important;
    background: Transparent !important;
    backdrop-filter: blur(2px);
    z-index: 1;
}

/* Hover durumunda boyut sabit */
.more-options-button:hover {
    transform: none !important;
    padding: 4px !important;
}

/* Açılır menü pozisyonu */
.options-menu {
    right: 8px !important;
    bottom: 100% !important;
    z-index: 1000;
}


/* Stack'lenmiş mesajlarda buton boyutu */
.consecutive-message .more-options-button {
    top: 2px !important;
    right: 2px !important;
    padding: 2px !important;
}
/* Stack'lenmiş mesajlar için hizalama düzeltmesi */
.consecutive-message {
    margin-left: 40px !important; /* Avatar genişliği kadar boşluk */
    width: calc(100% - 40px) !important; /* Genişlik ayarı */
}

.consecutive-message {
    margin-left: 0 !important;
    padding-left: 52px !important; /* Avatar genişliği (40px) + boşluk (12px) */
    position: relative;
    left: 4px; /* Hafif sağa kaydırma */
    width: calc(100% - 4px) !important;
}

/* Normal mesajlarla hizalama için */
.message-container:not(.consecutive-message) {
    margin-left: 4px !important;
}

/* İçerik genişlik ayarı */
.consecutive-message .flex-1 {
    width: calc(100% - 20px) !important;
    margin-left: 0 !important;
    padding-left: 4px !important;
}
/* Grup Üyeleri Modalı */
#group-members-modal .translate-x-full {
    transform: translateX(100%);
}

#group-members-modal .translate-x-0 {
    transform: translateX(0);
}

#cancel-poll {
    background-color: #4b5563;
}

#sumbit-poll {
    background-color: #5865f2;
}

#cancel-poll:hover {
    background-color: #6b7280;
}

#sumbit-poll:hover {
    background-color:#4752c4;
}

.group-member-item {
    @apply flex items-center p-2 hover:bg-gray-700 rounded cursor-pointer transition-colors;
}

.member-avatar {
    @apply w-10 h-10 rounded-full bg-gray-600 flex items-center justify-center overflow-hidden;
}

.admin-badge {
    @apply px-2 py-1 ml-2 text-xs bg-purple-500 rounded-full;
}
/* Grup Üyeleri Modalı */
#group-members-modal {
  background: rgba(0, 0, 0, 0.7);
  backdrop-filter: blur(5px);
}

#group-members-modal > div {
  background: #1a1a1a;
  box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
  width: 320px;
  border-left: 1px solid rgba(255, 255, 255, 0.1);
}

/* Başlık Çubuğu */
#group-members-modal h3 {
  font-size: 1.25rem;
  color: #3CB371;
  border-bottom: 1px solid rgba(255, 255, 255, 0.1);
  padding-bottom: 1rem;
  margin-bottom: 0;
}

/* Üye Listesi Konteynırı */
#group-members-list {
  padding: 0.5rem;
  background: linear-gradient(145deg, #121212, #1a1a1a);
}

/* Tekil Üye Öğesi */
.group-member-item {
  padding: 12px;
  margin: 8px 0;
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.03);
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  display: flex;
  align-items: center;
  gap: 12px;
  border: 1px solid rgba(255, 255, 255, 0.05);
}

.group-member-item:hover {
  background: rgba(60, 179, 113, 0.1);
  transform: translateX(5px);
  border-color: #3CB371;
}

/* Avatar Stili */
.member-avatar {
  width: 45px;
  height: 45px;
  border-radius: 50%;
  background: #2d2d2d;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  position: relative;
  border: 2px solid #3CB371;
}

.member-avatar img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.member-avatar span {
  color: #fff;
  font-weight: 600;
  font-size: 1.1rem;
}

/* Çevrimiçi Göstergesi */
.member-avatar::after {
  content: '';
  position: absolute;
  bottom: -2px;
  right: -2px;
  width: 12px;
  height: 12px;
  border-radius: 50%;
  border: 2px solid #1a1a1a;
  background: #3CB371;
}

.group-member-item[data-status="offline"] .member-avatar::after {
  background: #6b7280;
}

/* Kullanıcı Bilgileri */
.group-member-item > div:last-child {
  flex: 1;
}

.group-member-item .text-white {
  font-weight: 500;
  font-size: 0.95rem;
  color: #e5e7eb;
}

.group-member-item .text-gray-400 {
  font-size: 0.85rem;
  color: #9ca3af;
}

/* Yönetici Rozeti */
.admin-badge {
  background: linear-gradient(45deg, #3CB371, #2E8B57);
  color: white !important;
  padding: 2px 8px;
  border-radius: 4px;
  font-size: 0.7rem;
  margin-left: 8px;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

/* Responsive Tasarım */
@media (max-width: 640px) {
  #group-members-modal > div {
    width: 90%;
  }
  
  .group-member-item {
    padding: 10px;
    margin: 6px 0;
  }
  
  .member-avatar {
    width: 40px;
    height: 40px;
  }
  
  .group-member-item .text-white {
    font-size: 0.9rem;
  }
}
#friend-requests-section {
  position: absolute;
  top: 7vh; /* Dikeyde viewport yüksekliğine göre ayar */
  left: 20px; /* Sağ yerine SOL tarafa sabitle (909px mantıksız) */
  width: 97%; /* Cihaz genişliğine göre esnek */
  max-width: 2200px; /* Maksimum genişlik (büyük ekranlar için) */
  height: 87vh; /* Viewport yüksekliğinin %85'i */
  z-index: 10;
  background: #1a1a1a;
  border-left: 1px solid #333;
  overflow-y: auto; /* İçerik taşarsa scroll çıkar */

  /* Responsive için Media Queries */
  @media (max-width: 768px) { /* Tablet/Mobil için */
    width: 100%;
    left: 0;
    top: 50px; /* Header varsa alta itmek için */
    height: calc(100vh - 60px); /* Alt boşluk bırak */
  }

  @media (max-width: 480px) { /* Küçük mobil */
    top: 40px;
    height: calc(100vh - 50px);
  }
}
#blocked-friends-section {
  position: absolute;
  top: 7vh; /* Dikeyde viewport yüksekliğine göre ayar */
  left: 20px; /* Sağ yerine SOL tarafa sabitle (909px mantıksız) */
  width: 97%; /* Cihaz genişliğine göre esnek */
  max-width: 2200px; /* Maksimum genişlik (büyük ekranlar için) */
  height: 87vh; /* Viewport yüksekliğinin %85'i */
  z-index: 10;
  background: #1a1a1a;
  border-left: 1px solid #333;
  overflow-y: auto; /* İçerik taşarsa scroll çıkar */

  /* Responsive için Media Queries */
  @media (max-width: 768px) { /* Tablet/Mobil için */
    width: 100%;
    left: 0;
    top: 50px; /* Header varsa alta itmek için */
    height: calc(100vh - 60px); /* Alt boşluk bırak */
  }

  @media (max-width: 480px) { /* Küçük mobil */
    top: 40px;
    height: calc(100vh - 50px);
  }
}


/* Yanıtlanan mesaj stil */
.reply-message-container {
    border-left: 3px solid #3CB371;
    padding: 8px;
    margin: 8px 0;
    background: #2A2A2A;
    border-radius: 8px;
}

/* Dosya önizleme stilleri */
.preview-image {
    max-width: 300px;
    border-radius: 12px;
    margin: 8px 0;
    cursor: zoom-in;
    transition: transform 0.2s;
}

.preview-image:hover {
    transform: scale(1.03);
}

/* Emoji boyutu */
.emoji-mart-emoji {
    font-size: 1.2rem;
}

/* Yazıyor... animasyonu */
.typing-animation span {
    display: inline-block;
    width: 6px;
    height: 6px;
    margin-right: 2px;
    background: #3CB371;
    border-radius: 50%;
    animation: typing 1.4s infinite ease-in-out;
}

@keyframes typing {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-4px); }
}


#user-guide::before,
#user-guide::after {
    content: '';
    position: absolute;
    border-radius: 50%; /* Dairesel şekiller için */
    filter: blur(80px); /* Bulanıklık efekti */
    opacity: 0.3; /* Hafif saydamlık */
    z-index: 0; /* İçeriğin arkasında kalmasını sağlar */
}

#user-guide::before {
    width: 200px;
    height: 200px;
    background: linear-gradient(to right, #8b5cf6, #3b82f6); /* Mor ve mavi tonlarında gradient */
    top: -50px;
    left: -50px;
}

#user-guide::after {
    width: 250px;
    height: 250px;
    background: linear-gradient(to right, #ec4899, #f97316); /* Pembe ve turuncu tonlarında gradient */
    bottom: -80px;
    right: -80px;
}

/* İstediğiniz ek dekoratif öğeleri için */
.decorative-blob-1 {
    width: 150px;
    height: 150px;
    background-color: #3b82f6; /* Mavi */
    filter: blur(100px);
    opacity: 0.2;
    top: 30%;
    left: 10%;
}

.decorative-blob-2 {
    width: 120px;
    height: 120px;
    background-color: #8b5cf6; /* Mor */
    filter: blur(90px);
    opacity: 0.25;
    bottom: 20%;
    right: 5%;
}
/* Add Member Modal */
#add-member-modal {
    background: rgba(0, 0, 0, 0.7); /* Slightly darker overlay with blur */
    backdrop-filter: blur(5px);
    z-index: 10000;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: opacity 0.3s ease-in-out;
}

#add-member-modal:not(.hidden) {
    opacity: 1;
    pointer-events: auto;
}

#add-member-modal.hidden {
    opacity: 0;
    pointer-events: none;
}

#add-member-modal > div {
    background: linear-gradient(145deg, #1a1a1a, #2d2f34); /* Gradient background for depth */
    border-radius: 12px;
    padding: 1.5rem;
    width: 100%;
    max-width: 480px; /* Slightly wider for better content fit */
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
    border: 1px solid rgba(255, 255, 255, 0.1);
    transform: translateY(0);
    animation: slideIn 0.3s ease-out;
}

/* Modal Header */
#add-member-modal .flex.justify-between {
    align-items: center;
    margin-bottom: 1.5rem;
}

#add-member-modal h3 {
    font-size: 1.25rem;
    font-weight: 600;
    color: #3CB371; /* Accent color for consistency */
}

#add-member-modal .text-gray-400:hover {
    color: #fff;
    transform: scale(1.1);
    transition: all 0.2s ease;
}

/* Search Input */
#member-search {
    background: #2a2a2a;
    border: 1px solid rgba(255, 255, 255, 0.1);
    color: #dcddde;
    padding: 0.75rem 1rem;
    border-radius: 8px;
    transition: all 0.3s ease;
}

#member-search:focus {
    border-color: #3CB371;
    box-shadow: 0 0 0 2px rgba(60, 179, 113, 0.2);
    outline: none;
}

/* Friend Selection List */
#friend-selection {
    max-height: 200px; /* Slightly shorter for better UX */
    overflow-y: auto;
    padding: 0.5rem;
    background: #1a1a1a;
    border-radius: 8px;
    border: 1px solid rgba(255, 255, 255, 0.05);
}

#friend-selection label {
    display: flex;
    align-items: center;
    padding: 0.75rem;
    margin: 0.25rem 0;
    border-radius: 6px;
    transition: all 0.3s ease;
    cursor: pointer;
}

#friend-selection label:hover {
    background: rgba(60, 179, 113, 0.1);
    transform: translateX(3px);
}

#friend-selection input[type="checkbox"] {
    accent-color: #3CB371; /* Checkbox accent color */
    width: 16px;
    height: 16px;
    margin-right: 0.75rem;
}

#friend-selection .w-8 {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    overflow: hidden;
    background: #2d2d2d;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 1px solid #3CB371;
}

#friend-selection img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

#friend-selection span.text-white {
    font-size: 0.95rem;
    color: #e5e7eb;
}

/* Selected Members Section */
#selected-members {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-top: 1rem;
    padding: 0.5rem;
    background: rgba(255, 255, 255, 0.03);
    border-radius: 8px;
    border: 1px solid rgba(255, 255, 255, 0.05);
}

#selected-members > div {
    background: #3a3a3a;
    border-radius: 9999px;
    padding: 0.25rem 0.75rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.85rem;
    color: #fff;
}

#selected-members .w-5 {
    width: 20px;
    height: 20px;
    border-radius: 50%;
    overflow: hidden;
    background: #2d2d2d;
}

#selected-members button {
    transition: all 0.2s ease;
}

#selected-members button:hover {
    color: #ef4444;
    transform: scale(1.1);
}

/* Selected Count */
#selected-member-count {
    color: #3CB371;
    font-weight: 500;
}

/* Buttons */
#add-member-modal .btn {
    padding: 0.75rem 1.5rem;
    border-radius: 8px;
    font-weight: 600;
    transition: all 0.3s ease;
}

#add-member-modal .bg-gray-600 {
    background: #36393f;
}

#add-member-modal .bg-gray-600:hover {
    background: #40444b;
    transform: translateY(-1px);
}

#add-member-modal .bg-green-600 {
    background: #3CB371;
}

#add-member-modal .bg-green-600:hover {
    background: #2E8B57;
    transform: translateY(-1px);
}

#add-member-modal .disabled\:opacity-50:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

/* Message */
#add-member-message {
    transition: all 0.3s ease;
    border-radius: 6px;
}

#add-member-message.bg-green-500\/10 {
    background: rgba(60, 179, 113, 0.1);
    border: 1px solid #3CB371;
}

#add-member-message.bg-red-500\/10 {
    background: rgba(239, 68, 68, 0.1);
    border: 1px solid #ef4444;
}

/* Scrollbar */
#friend-selection.custom-scrollbar::-webkit-scrollbar {
    width: 6px;
}

#friend-selection.custom-scrollbar::-webkit-scrollbar-track {
    background: #2a2a2a;
    border-radius: 10px;
}

#friend-selection.custom-scrollbar::-webkit-scrollbar-thumb {
    background: #3CB371;
    border-radius: 10px;
}

/* Responsive Design */
@media (max-width: 640px) {
    #add-member-modal > div {
        width: 90%;
        padding: 1rem;
    }

    #add-member-modal h3 {
        font-size: 1.1rem;
    }

    #friend-selection {
        max-height: 180px;
    }

    #friend-selection label {
        padding: 0.5rem;
    }

    #selected-members {
        padding: 0.5rem;
    }

    #add-member-modal .btn {
        padding: 0.5rem 1rem;
        font-size: 0.9rem;
    }
}

/* Animation for Modal */
@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}
.hide-friend-btn {
    opacity: 0; /* Button is hidden by default */
    transition: opacity 0.2s ease; /* Smooth transition for visibility */
}

.friend-item1:hover .hide-friend-btn {
    opacity: 1; /* Show button when hovering over friend-item */
}
.server-item {
    position: relative;
    transition: transform 0.2s ease;
}

.server-item.dragging {
    opacity: 0.5;
    transform: scale(0.9);
}

.server-item.drag-over {
    border: 2px dashed #3CB371;
    background: rgba(60, 179, 113, 0.1);
}
/* Modal için ek stiller */
        .modal {
            transition: opacity 0.3s ease-in-out;
        }
        .modal-hidden {
            opacity: 0;
            pointer-events: none;
        }
        .modal-visible {
            opacity: 1;
            pointer-events: auto;
        }
#join-voice-call {
    margin-left:auto;
}

/* Sesli Arama Modalı için Ek Stiller */
#voice-call-modal {
    background: #161616;
    backdrop-filter: blur(5px);
}

#voice-call-modal > div {
    background: #161616;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
    border: none; /* Border removed */
}

.call-control-button {
    @apply w-14 h-14 rounded-full flex items-center justify-center text-white transition-all duration-200 focus:outline-none;
}

.call-control-button:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
}

.call-control-button.muted {
    background-color: #374151; /* Muted (gri) */
}

@keyframes connecting-pulse {
    0%, 100% { transform: scale(1); opacity: 0.5; }
    50% { transform: scale(1.2); opacity: 1; }
}

.call-connecting-dot {
    width: 8px;
    height: 8px;
    background-color: #3CB371;
    border-radius: 50%;
    animation: connecting-pulse 1.4s infinite ease-in-out;
}

/* Tooltip için (zaten varsa eklemeyin, yoksa ekleyin) */
[data-tooltip] {
    position: relative;
}

[data-tooltip]::after {
    content: attr(data-tooltip);
    position: absolute;
    bottom: 120%; /* Butonun üstünde görünmesi için */
    left: 50%;
    transform: translateX(-50%);
    background: #1a1a1a;
    color: white;
    padding: 6px 10px;
    border-radius: 6px;
    font-size: 13px;
    white-space: nowrap;
    opacity: 0;
    visibility: hidden;
    transition: all 0.2s ease;
    pointer-events: none;
    box-shadow: 0 4px 6px rgba(0,0,0,0.2);
}

[data-tooltip]:hover::after {
    opacity: 1;
    visibility: visible;
    transform: translateX(-50%) translateY(-5px);
}
/* Sohbet Konteyneri (örnek) */
.chat-container {
    flex-grow: 1; /* Sohbetin kalan alanı kaplamasını sağlar */
    display: flex;
    flex-direction: column;
    /* Diğer sohbet stilleri buraya gelecek */
    overflow-y: auto; /* Sohbet içeriği için kaydırma çubuğu */
}

/* Video Arama Modalı Stilleri */
.video-call-modal {
    display: none;
    position: relative;
    width: 100%;
    height: 200px;
    background-color: #161616; /* Updated background color */
    border-bottom: 1px solid #161616;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
    z-index: 10;
}

/* Modal aktif olduğunda */
.video-call-modal.active {
    display: block; /* Modalı görünür yapın */
}

/* Modal İçeriği (video ve kontroller) */
.modal-content {
    display: flex;
    justify-content: center;
    align-items: center;
    height: 100%;
    gap: 10px; /* Video öğeleri arasındaki boşluk */
}

.modal-content video {
    max-width: 45%; /* Videoların yan yana sığması için */
    height: auto;
    border-radius: 8px;
    background-color: black; /* Video yüklenmeden önce siyah arka plan */
}

.call-controls {
    position: absolute;
    bottom: 10px;
    left: 50%;
    transform: translateX(-50%);
    display: flex;
    gap: 10px;
}

.call-controls button {
    background-color: #43b581; /* Discord yeşili */
    color: white;
    border: none;
    padding: 8px 15px;
    border-radius: 5px;
    cursor: pointer;
    font-size: 14px;
}

.call-controls button:hover {
    background-color: #3ba55c;
}

/* Arka plan bulanıklığını veya overlay'i kaldırın */
/* Eğer modalınızın dışında bir arka plan karartma/bulanıklaştırma elemanı varsa, onu gizleyin veya kaldırın */
.modal-backdrop { /* Varsayımsal bir arka plan elemanı sınıfı */
    display: none;
}

/* Discord-like Call Control Buttons */
.call-control-button {
    width: 56px; /* Discord button size */
    height: 56px; /* Discord button size */
    border-radius: 50%; /* Make them perfectly round */
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 24px; /* Icon size */
    transition: all 0.2s ease-in-out;
    cursor: pointer;
    border: none;
    background-color: #4f545c; /* Default gray for non-active buttons */
    margin: 0 8px; /* Spacing between buttons */
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
}

.call-control-button:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 10px rgba(0, 0, 0, 0.3);
}

/* Specific button styles (e.g., mute/unmute, deafen/undeafen) */
.call-control-button.active {
    background-color: #3CB371; /* Green for active/unmuted */
}

.call-control-button.active:hover {
    background-color: #2E8B57;
}

/* Muted state (example for microphone) */
.call-control-button.muted {
    background-color: #ed4245; /* Red for muted */
}

.call-control-button.muted:hover {
    background-color: #cc3a3d;
}

/* End Call Button (typically red) */
.call-control-button.end-call {
    background-color: #ed4245; /* Discord red for disconnect */
}

.call-control-button.end-call:hover {
    background-color: #cc3a3d;
}

/* Icon styling inside buttons */
.call-control-button i {
    color: inherit; /* Inherit color from button */
}


/* Discord-like Call Control Buttons */
.call-control-button {
    width: 56px; /* Discord button size */
    height: 56px; /* Discord button size */
    border-radius: 50%; /* Make them perfectly round */
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 24px; /* Icon size */
    transition: all 0.2s ease-in-out;
    cursor: pointer;
    border: none;
    background-color: #4f545c; 
    margin: 0 8px; /* Spacing between buttons */
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
}

.call-control-button:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 10px rgba(0, 0, 0, 0.3);
}

/* Specific button styles (e.g., mute/unmute, deafen/undeafen) */
/* Eğer mikrofon veya ekran paylaşımı aktifse bu sınıfları JavaScript ile ekleyebilirsiniz */
.call-control-button.active {
    background-color: #3CB371; /* Green for active/unmuted */
}

.call-control-button.active:hover {
    background-color: #2E8B57;
}

/* Muted state (example for microphone) */
.call-control-button.muted {
    background-color: #ed4245; /* Red for muted */
}

.call-control-button.muted:hover {
    background-color: #cc3a3d;
}


.call-control-button.end-call {
    background-color: #ed4245 !important; /* Discord red for disconnect, !important ile Tailwind'i ezebilir */
}

.call-control-button.end-call:hover {
    background-color: #cc3a3d !important;
}

/* Icon styling inside buttons */
.call-control-button i {
    color: inherit; /* Inherit color from button */
}


.flex.items-center.justify-center.space-x-4.mt-4.bg-gray-900\/50.p-3.rounded-full {
    padding: 12px; 
    border-radius: 9999px; 
}

/* Bağlantı noktaları animasyonu için (opsiyonel, eğer kullanılıyorsa) */
@keyframes pulse {
    0% { transform: scale(1); opacity: 1; }
    50% { transform: scale(1.2); opacity: 0.7; }
    100% { transform: scale(1); opacity: 1; }
}

.call-connecting-dot {
    width: 8px;
    height: 8px;
    background-color: #94a3b8; /* Gray-400 */
    border-radius: 50%;
    animation: pulse 1.4s infinite ease-in-out;
}
.chat-area {
    background-color:#161616;
}
.search-result-item:hover {
    background-color: #4a5568;
}

.highlight {
    background-color: #667eea;
    color: white;
    padding: 0 2px;
    border-radius: 2px;
}
#pinned-messages-btn {
    margin-right:7px;
}
.youtube-preview {
    max-width: 400px;
    margin: 8px 0;
    border-radius: 8px;
    overflow: hidden;
}

.youtube-preview iframe {
    width: 100%;
    height: auto;
    aspect-ratio: 16/9;
}

.shiny-button {
  position: relative;
  cursor: pointer;
}

.shiny-button::before {
  content: '';
  position: absolute;
  top: 0;
  left: -60%;
  width: 50%;
  height: 100%;
  background: linear-gradient(
    120deg,
    rgba(60, 179, 113, 0) 0%,
    rgba(60, 179, 113, 0.5) 50%,
    rgba(60, 179, 113, 0) 100%
  );
  transform: skewX(-20deg);
  opacity: 0.8;
}

.shiny-button:hover::before {
  animation: shinyGreen 0.7s ease forwards;
  /* reset için */
  animation: none;
  animation: shinyGreen 0.7s ease forwards;
}

@keyframes shinyGreen {
  from {
    left: -50%;
  }
  to {
    left: 120%;
  }
}

@media (max-width: 768px) {
    .youtube-preview {
        max-width: 250px;
    }
    
    .shiny-button::before {
      content: '';
      position: absolute;
      top: 0;
      left: -60%;
      width: 20%;
      height: 100%;
      background: linear-gradient(
        120deg,
        rgba(60, 179, 113, 0) 0%,
        rgba(60, 179, 113, 0.5) 50%,
        rgba(60, 179, 113, 0) 100%
      );
      transform: skewX(-20deg);
      opacity: 0.8;
    }

    
    @keyframes shinyGreen {
      from {
        left: -50%;
        display: flex;
      }
      to {
        left: 100%;
        display: none;
      }
    }
}
#screen-share-container {
    background: #161616;
    padding: 16px;
    border-radius: 12px;
    box-shadow: 0 4px 10px rgba(0, 0, 0, 0.3);
}

#remote-screen {
    max-width: 100%;
    height: auto;
    background: #000;
    object-fit: contain;
}

.call-controls button {
    transition: all 0.2s ease-in-out;
}

.call-controls button:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 10px rgba(0, 0, 0, 0.3);
}

#mobile-profile {
    display: none;
}

/*mobil arkadaş ui sini pc de gösterme*/
#mobile-add-friend {
    display: none;
}

.reply-preview {
    background-color: rgba(65, 70, 100, 0.5);
}

@media (max-width: 768px) {
    #screen-share-container .flex {
        flex-direction: column;
        gap: 16px;
    }
    #local-screen, #remote-screen {
        width: 100%;
        max-height: 200px;
    }
    #user-profile {
        display: none;
    }
    #mobile-profile {
        position: fixed;
        display: flex;
        left: 0px;
        bottom: 0px;
        height: 8vh;
        width: 100%;
        z-index: 16;
        backdrop-filter: blur(10px);
        background: linear-gradient(to top, rgba(18,18,18,0.85), rgba(60,179,113, 0.15));
        background-size: 800% 800%;
        animation: waveGradient 2s ease infinite;
    }
    @keyframes waveGradient {
      0% {
        background-position: 0% 50%;
      }
      50% {
        background-position: 100% 50%;
      }
      100% {
        background-position: 0% 50%;
      }
    }
    .text-gradient {
        color: #ffffff;
    }
    .bg-nav-card {
        background-color: rgba(33,147,124,0.5);
    }
    .gradient-border {
        border-top: 2px solid;
        border-image: linear-gradient(135deg, #3CB371, #FFFFFF, #B0B0B0, #3CB371) 0.6;
        color: #C9DBD2;
   }
   .nav-shadow {
       box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
       border-radius: 16px;
       transition: box-shadow 0.5s ease;
   }
   .nav-shadow:hover {
       box-shadow: 0 2px 6px rgba(255, 255, 255, 0.7);
   }
   
    #left-panel {
        padding-bottom: 8vh;
    }
    #movesidebar {
        padding-bottom: 8vh;
    }
    #server-sidebar {
        padding-bottom: 8vh;
    }
    #communities {
        display: none;
    }
    #home {
        display: none;
    }
    #mobile-add-friend {
    display: flex;
    }
    .reply-preview {
        width: 80vh;
        height: 10vh;
    }
}


/* Ekran paylaşım container'ı için stil */
#screen-share-container {
    background: #161616;
    padding: 8px;
    border-radius: 12px;
    box-shadow: 0 4px 10px rgba(0, 0, 0, 0.3);
    width: 100%;
    max-width: 900px; /* Daha geniş bir maksimum genişlik */
    z-index: 10; /* Profillerin üstünde yer alır */
}

/* Videoların yatay düzeni */
#screen-share-container .flex {
    display: flex;
    flex-direction: row; /* Yatay düzen */
    justify-content: center;
    gap: 16px;
    flex-wrap: nowrap; /* Videoların alt alta geçmesini engelle */
}

/* Video boyutları */
#local-screen, #remote-screen {
    max-width: 400px; /* Maksimum genişlik */
    width: 100%;
    height: auto;
    background: #000;
    object-fit: contain;
    border-radius: 8px;
}

/* Ekran paylaşımı aktifken profilleri gizle */
#screen-share-container:not(.hidden) + #profiles-container,
#screen-share-container:not(.hidden) ~ #profiles-container {
    display: none; /* Profilleri gizle */
}

/* Mobil uyumluluk */
@media (max-width: 640px) {
    #voice-call-modal > div {
        padding: 1rem;
        max-width: 95%;
    }

    #screen-share-container .flex {
        flex-direction: column; /* Mobil cihazlarda dikey düzen */
        gap: 12px;
    }

    #local-screen, #remote-screen {
        max-width: 100%;
        max-height: 180px; /* Mobil cihazlarda daha küçük yükseklik */
    }

    #caller-avatar, #receiver-avatar {
        width: 60px;
        height: 60px;
    }

    #caller-username, #chat-username2 {
        font-size: 0.85rem;
    }

    .call-control-button {
        width: 40px;
        height: 40px;
        font-size: 18px;
    }

    #screen-share-status {
        font-size: 0.8rem;
    }
}

/* Bağlantı durumu için stil */
#screen-share-status {
    margin-top: 0.5rem;
    font-size: 0.9rem;
    text-align: center;
}
/* Ses Oynatıcı Stilleri */
.audio-player-container {
    background: #2a2a2a;
    border-radius: 12px;
    padding: 12px;
    display: flex;
    align-items: center;
    gap: 12px;
    max-width: 400px;
    width: 100%;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.audio-player-container:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
}

.audio-control {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: background-color 0.2s ease, transform 0.2s ease;
}

.audio-control:hover {
    transform: scale(1.05);
}

.audio-progress-bar {
    height: 6px;
    background: #4a5568;
    border-radius: 9999px;
    overflow: hidden;
    position: relative;
    cursor: pointer;
}

.audio-progress-bar .progress {
    height: 100%;
    background: linear-gradient(90deg, #3CB371, #2E8B57);
    transition: width 0.1s linear;
}

.volume-control {
    display: flex;
    align-items: center;
    gap: 8px;
}

.volume-slider {
    -webkit-appearance: none;
    width: 80px;
    height: 6px;
    background: #4a5568;
    border-radius: 9999px;
    outline: none;
    transition: background 0.2s ease;
}

.volume-slider::-webkit-slider-thumb {
    -webkit-appearance: none;
    width: 14px;
    height: 14px;
    background: #3CB371;
    border-radius: 50%;
    cursor: pointer;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
}

.volume-slider::-webkit-slider-thumb:hover {
    background: #2E8B57;
}

.volume-slider::-moz-range-thumb {
    width: 14px;
    height: 14px;
    background: #3CB371;
    border-radius: 50%;
    cursor: pointer;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
}

.volume-slider::-moz-range-thumb:hover {
    background: #2E8B57;
}

/* Mobil Uyumluluk */
@media (max-width: 768px) {
    .audio-player-container {
        max-width: 300px;
        padding: 10px;
    }

    .audio-control {
        width: 36px;
        height: 36px;
    }

    .volume-slider {
        width: 60px;
    }
}
#gif-results img {
    width: 100%;
    height: 100px;
    object-fit: cover;
    border-radius: 8px;
    cursor: pointer;
    transition: transform 0.2s ease-in-out;
}

#gif-results img:hover {
    transform: scale(1.05);
    border: 2px solid #3CB371;
}

#gif-modal {
    backdrop-filter: blur(5px);
}

#gif-modal .custom-scrollbar::-webkit-scrollbar {
    width: 6px;
}

#gif-modal .custom-scrollbar::-webkit-scrollbar-track {
    background: #2a2a2a;
}

#gif-modal .custom-scrollbar::-webkit-scrollbar-thumb {
    background: #3CB371;
    border-radius: 10px;
}
/* GIF Modal Custom Styles */
#gif-modal {
    backdrop-filter: blur(8px);
}

#gif-results img {
    width: 100%;
    height: 120px;
    object-fit: cover;
    border-radius: 8px;
    cursor: pointer;
}

/* Custom Scrollbar for GIF Results */
#gif-results.custom-scrollbar::-webkit-scrollbar {
    width: 6px;
}

#gif-results.custom-scrollbar::-webkit-scrollbar-track {
    background: #2a2a2a;
    border-radius: 10px;
}

#gif-results.custom-scrollbar::-webkit-scrollbar-thumb {
    background: #3CB371;
    border-radius: 10px;
}
@keyframes bouncing-card {
  0%   { transform: translateY(0px); }
  50%  { transform: translateY(-10px); }
  100% { transform: translateY(0px); }
}
.bouncing-card {
  animation: bouncing-card 2s ease-in-out infinite;
}
.image-gallery {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}



.image-gallery img:hover {
    transform: scale(1.05);
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .image-gallery img {
        max-width: 150px;
        max-height: 150px;
    }
    #notification-panel {
        left: 0vh !important;
        width: 45vh !important;
    }
}

/*Bildirim paneli animasyonları*/
@keyframes fadeSlideIn {
  0% {
    opacity: 0;
    transform: translateY(-200px);
  }
  100% {
    opacity: 1;
    transform: translateY(0px);
  }
}
@keyframes fadeSlideOut {
  0% {
    opacity: 1;
    transform: translateY(0px);
  }
  100% {
    opacity: 0;
    transform: translateY(-200px);
  }
}
.notific-anim-in {
  animation: fadeSlideIn 0.5s ease-out;
}
.notific-anim-out {
  animation: fadeSlideOut 0.5s ease-out forwards;
}
#notification-panel {
    background: rgba(42, 42, 42, 0.5);
    border-radius: 6px;
    perspective: 1500px;
    backdrop-filter: blur(4px);
    box-shadow: 0 10px 20px rgba(0, 0, 0, 0.3);
    border: 1px solid #3CB371;
    box-shadow: 0px 4px 12px 3px rgba(60, 179, 113, 0.4);
    left: 10vh; 
    width: 100vh; 
    right: 10vh;
}
#no-online-friends,
#no-online-friends * {
    outline: none !important; /* Mavi çerçeve (outline) kaldırma */
    user-select: none !important; /* Metin seçimini engelleme (isteğe bağlı) */
    -webkit-tap-highlight-color: transparent !important; /* Mobil cihazlarda dokunma vurgusunu kaldırma */
}

#no-online-friends:hover,
#no-online-friends *:hover {
    outline: none !important; /* Hover durumunda mavi çerçeve kaldırma */
}
.file-download-link {
    display: flex;
    align-items: center;
    padding: 12px !important;
    background: #2f3136 !important;
    border-radius: 8px !important;
    border: 1px solid #3b3f45;
    transition: all 0.2s ease;
    margin: 8px 0;
    width: fit-content;
    max-width: 300px;
}

.file-download-link:hover {
    background: #35383e !important;
    transform: translateY(-2px);
    box-shadow: 0 2px 8px rgba(0,0,0,0.2);
}

.file-download-link i {
    color: #5865f2;
    flex-shrink: 0;
}

.file-download-link .text-white {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 320px;
}

.file-download-link .text-gray-400 {
    opacity: 0.8;
}

@media (max-width: 768px) {
    .file-download-link {
        max-width: 90%;
    }
    .file-download-link .text-white {
        max-width: 180px;
    }
}
/* Hide scrollbar for Webkit browsers (Chrome, Safari) */
.message-input::-webkit-scrollbar {
    display: none;
}

/* Hide scrollbar for Firefox */
.message-input {
    scrollbar-width: none;
}

/* Hide scrollbar for Edge/IE */
.message-input {
    -ms-overflow-style: none;
}

/* Ensure textarea remains scrollable and styled */
.message-input {
    overflow-y: auto;
    resize: none;
    box-sizing: border-box;
}

/* Allow input-area to grow with textarea */
#input-area {
    display: flex;
    flex-direction: column;
    background-color: #161616;
    min-height: 48px; /* Matches original compact height with padding */
    padding: 8px; /* Minimal padding to match original look */
}

/* Ensure form grows with textarea */
#message-form {
    display: flex;
    align-items: flex-start; /* Align items to top to avoid stretching buttons */
    gap: 8px; /* Matches space-x-2 (8px) */
}
/* Ensure textarea drives the height */
.message-input {
    line-height: 1.5; /* Matches typical input line height */
}
/* Mobil cihazlar için mesaj giriş alanı optimizasyonu */
#mobile-status-modal {
    display: none;
}
@media (max-width: 768px) {
    #mobile-status-modal {
        display: flex;
    }
    #status-modal {
        z-index: 16;
        width: 50%;
        height: 50%;
    }
    #input-area {
        padding: 12px 8px; /* Daha fazla iç boşluk */
        background-color: #161616;
        position: relative;
        z-index: 10;
    }

    #message-form {
        position: relative;
        display: flex;
        align-items: flex-start; /* Butonlar yukarıda hizalanacak */
        flex-wrap: nowrap;
        gap: 8px; /* Butonlar ve textarea arasındaki boşluk */
    }



    /* Butonların yukarıda kalması için */
    #message-form button,
    #message-form label {
        position: relative;
        top: 0; /* Yukarıda sabit */
        margin-top: 4px; /* Hafif boşluk */
    }
    /* Gönder butonu */
    .send-button {
        display: flex !important;
        padding: 8px 16px;
        border-radius: 50px;
        background-color: #3CB371; /* Göze çarpan renk */
        color: white;
        font-size: 0.9rem;
        transition: all 0.2s ease;
    }

    .send-button:disabled {
        background-color: #4b5563;
        cursor: not-allowed;
    }

    /* Textarea genişledikçe butonların yukarıda kalması */
    #message-input:focus,
    #message-input:not(:placeholder-shown) {
        padding-bottom: 10px; /* Alt kısımda boşluk bırak */
    }
}

/* Genel stil düzeltmeleri */
#input-area {
    display: flex !important; /* Mobil ve masaüstünde görünür */
    align-items: flex-start;
    width: 100%;
    box-sizing: border-box;
}

#message-form {
    width: 100%;
    display: flex;
    align-items: flex-start;
    gap: 8px;
}

/* Textarea için otomatik genişleme */
#message-input {
    overflow-y: auto;
    white-space: pre-wrap;
    word-wrap: break-word;
}

/* Mobil cihazlarda butonların düzgün hizalanması */
@media (max-width: 768px) {
    #message-form button,
    #message-form label {
        flex-shrink: 0; /* Butonların küçülmesini engelle */
    }
}
#gif-button,
#poll-button ,
#emoji-button ,
#file-input {
    margin-top:5px;
}
@media (max-width: 768px) {
    #input-area {
        padding: 8px;
        background-color: #161616;
        position: relative;
    }

    #message-form {
        display: flex;
        align-items: center;
        gap: 8px;
        width: 350px;
    }

    .message-input {
        font-size: 1.1rem;
        min-height: 56px;
        padding: 12px;
        border-radius: 12px;
        width: calc(100% - 100px);
        background-color: #363636;
        color: #fff;
        resize: none;
        position: relative;
        z-index: 10;
    }
    #poll-button,
    #emoji-button,
    #gif-button {
        transition: opacity 0.3s ease;
        z-index: 11;
    }

    .send-button,
    label[for="file-input"] {
        z-index: 13;
    }
}
.message-input::placeholder {
    padding-top:4px;
    font-size: 0.9rem; /* Daha küçük font boyutu */
    line-height: 1.2; /* Satır yüksekliğini kontrol et */
    white-space: nowrap; /* Metnin satır atlamasını engelle */
}
#file-upload-progress {
    max-width: 300px;
    margin-top: 8px;
}

#progress-bar {
    transition: width 0.3s ease-in-out;
}

#file-upload-progress:not(.hidden) {
    animation: fadeIn 0.3s ease-in-out;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(5px); }
    to { opacity: 1; transform: translateY(0); }
}
/* Grup Oluşturma Modalı için Tema Desteği */
#create-group-modal {
    --primary-bg: #1a1b1e;
    --secondary-bg: #2d2f34;
    --accent-color: #3CB371;
    --text-primary: #ffffff;
    --text-secondary: #b9bbbe;
}

#create-group-modal .bg-black.bg-opacity-50 {
    background-color: rgba(0, 0, 0, 0.5);
}

#create-group-modal .modal-conten {
    background-color: var(--secondary-bg);
    border: 1px solid rgba(255, 255, 255, 0.1);
}

#create-group-modal .upload-area {
    border: 2px dashed rgba(255, 255, 255, 0.2);
    border-radius: 0.75rem;
    padding: 2rem;
    text-align: center;
    cursor: pointer;
    transition: all 0.3s ease;
}

#create-group-modal .upload-area:hover {
    border-color: var(--accent-color);
    background-color: rgba(60, 179, 113, 0.1);
}

#create-group-modal .form-input {
    background-color: var(--primary-bg);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 0.5rem;
    padding: 0.75rem 1rem;
    color: var(--text-primary);
    transition: all 0.3s ease;
}

#create-group-modal .form-input:focus {
    border-color: var(--accent-color);
    outline: none;
    box-shadow: 0 0 0 2px rgba(60, 179, 113, 0.2);
}

#create-group-modal .btn {
    padding: 0.75rem 1.5rem;
    border-radius: 0.5rem;
    font-weight: 600;
    transition: all 0.3s ease;
}

#create-group-modal .btn-primary {
    background-color: var(--accent-color);
    color: var(--text-primary);
}

#create-group-modal .btn-primary:hover {
    background-color: #2E8B57;
    transform: translateY(-1px);
}

#create-group-modal .btn-cancel {
    background-color: #36393f;
    color: var(--text-primary);
}

#create-group-modal .btn-cancel:hover {
    background-color: #40444b;
}

/* Karanlık Tema için */
.dark-theme #create-group-modal {
    --primary-bg: #1a1b1e;
    --secondary-bg: #181818; /* Karanlık tema için arka plan rengi */
    --accent-color: #3CB371;
    --text-primary: #ffffff;
    --text-secondary: #b9bbbe;
}

.dark-theme #create-group-modal .bg-black.bg-opacity-50 {
    background-color: rgba(0, 0, 0, 0.5);
}

.dark-theme #create-group-modal .modal-conten {
    background-color: var(--secondary-bg);
    border: 1px solid rgba(255, 255, 255, 0.1);
}

.dark-theme #create-group-modal .upload-area {
    border: 2px dashed rgba(255, 255, 255, 0.2);
}

.dark-theme #create-group-modal .upload-area:hover {
    border-color: var(--accent-color);
    background-color: rgba(60, 179, 113, 0.1);
}

.dark-theme #create-group-modal .form-input {
    background-color: var(--primary-bg);
    border: 1px solid rgba(255, 255, 255, 0.1);
    color: var(--text-primary);
}

.dark-theme #create-group-modal .form-input:focus {
    border-color: var(--accent-color);
    box-shadow: 0 0 0 2px rgba(60, 179, 113, 0.2);
}

.dark-theme #create-group-modal .btn-primary {
    background-color: var(--accent-color);
    color: var(--text-primary);
}

.dark-theme #create-group-modal .btn-primary:hover {
    background-color: #2E8B57;
}

.dark-theme #create-group-modal .btn-cancel {
    background-color: #36393f;
    color: var(--text-primary);
}

.dark-theme #create-group-modal .btn-cancel:hover {
    background-color: #40444b;
}

/* Açık Tema için */
.light-theme #create-group-modal {
    --primary-bg: #F2F3F5;
    --secondary-bg: #FFFFFF;
    --accent-color: #5865F2;
    --text-primary: #2E3338;
    --text-secondary: #4F5660;
}

.light-theme #create-group-modal .bg-black.bg-opacity-50 {
    background-color: rgba(0, 0, 0, 0.3);
}

.light-theme #create-group-modal .modal-conten {
    background-color: var(--secondary-bg);
    border: 1px solid rgba(0, 0, 0, 0.1);
}

.light-theme #create-group-modal .upload-area {
    border: 2px dashed rgba(0, 0, 0, 0.2);
}

.light-theme #create-group-modal .upload-area:hover {
    border-color: var(--accent-color);
    background-color: rgba(88, 101, 242, 0.1);
}

.light-theme #create-group-modal .form-input {
    background-color: var(--primary-bg);
    border: 1px solid rgba(0, 0, 0, 0.1);
    color: var(--text-primary);
}

.light-theme #create-group-modal .form-input:focus {
    border-color: var(--accent-color);
    box-shadow: 0 0 0 2px rgba(88, 101, 242, 0.2);
}

.light-theme #create-group-modal .btn-primary {
    background-color: var(--accent-color);
    color: var(--text-primary);
}

.light-theme #create-group-modal .btn-primary:hover {
    background-color: #4752C4;
}

.light-theme #create-group-modal .btn-cancel {
    background-color: #D1D5DB;
    color: var(--text-primary);
}

.light-theme #create-group-modal .btn-cancel:hover {
    background-color: #B0B3B8;
}

/* Özel Tema için */
.custom-theme #create-group-modal {
    --primary-bg: color-mix(in srgb, var(--custom-background-color) 90%, #000000 10%);
    --secondary-bg: color-mix(in srgb, var(--custom-background-color) 70%, #1a1b1e 30%);
    --accent-color: var(--custom-secondary-color);
    --text-primary: #ffffff;
    --text-secondary: color-mix(in srgb, #ffffff 70%, var(--custom-secondary-color) 30%);
}

.custom-theme #create-group-modal .bg-black.bg-opacity-50 {
    background-color: rgba(0, 0, 0, 0.5);
}

.custom-theme #create-group-modal .modal-conten {
    background-color: var(--secondary-bg);
    border: 1px solid rgba(255, 255, 255, 0.1);
}

.custom-theme #create-group-modal .upload-area {
    border: 2px dashed rgba(255, 255, 255, 0.2);
}

.custom-theme #create-group-modal .upload-area:hover {
    border-color: var(--accent-color);
    background-color: color-mix(in srgb, var(--custom-secondary-color) 10%, transparent 90%);
}

.custom-theme #create-group-modal .form-input {
    background-color: var(--primary-bg);
    border: 1px solid rgba(255, 255, 255, 0.1);
    color: var(--text-primary);
}

.custom-theme #create-group-modal .form-input:focus {
    border-color: var(--accent-color);
    box-shadow: 0 0 0 2px color-mix(in srgb, var(--custom-secondary-color) 20%, transparent 80%);
}

.custom-theme #create-group-modal .btn-primary {
    background-color: var(--accent-color);
    color: var(--text-primary);
}

.custom-theme #create-group-modal .btn-primary:hover {
    background-color: color-mix(in srgb, var(--custom-secondary-color) 80%, #000000 20%);
}

.custom-theme #create-group-modal .btn-cancel {
    background-color: color-mix(in srgb, var(--custom-background-color) 50%, #1a1b1e 50%);
    color: var(--text-primary);
}

.custom-theme #create-group-modal .btn-cancel:hover {
    background-color: color-mix(in srgb, var(--custom-background-color) 60%, #2d2f34 40%);
}
/* Anket Oluşturma Modal Stilleri */
#create-poll-modal {
    --modal-bg: #2f3136;
    --input-bg: #40444b;
    --accent-color: #5865f2;
    --text-primary: #ffffff;
    --text-secondary: #b9bbbe;
}

#create-poll-modal .bg-\[\#2f3136\] {
    background: var(--modal-bg);
    border-radius: 12px;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.3);
    transform: translateY(0);
    transition: transform 0.3s ease, opacity 0.3s ease;
}

#create-poll-modal.hidden {
    opacity: 0;
    pointer-events: none;
}

#create-poll-modal:not(.hidden) {
    opacity: 1;
    animation:  modalEntry 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
}

#create-poll-modal .form-input {
    background: var(--input-bg);
    border: none;
    color: var(--text-primary);
    transition: all 0.2s ease;
}

#create-poll-modal .form-input:focus {
    outline: none;
    box-shadow: 0 0 0 2px var(--accent-color);
}

#create-poll-modal .btn {
    padding: 0.75rem 1.5rem;
    font-weight: 500;
    transition: all 0.2s ease;
}

#create-poll-modal .btn:hover {
    transform: translateY(-1px);
}
/* Anket Konteynırı */
.poll-container {
    background: #2f3136;
    border-radius: 16px;
    padding: 1.25rem;
    margin: 0.75rem 0;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.25);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.poll-container:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 24px rgba(0, 0, 0, 0.3);
}

/* Anket Başlığı */
.poll-container p {
    font-size: 1.1rem;
    font-weight: 600;
    color: #ffffff;
    margin-bottom: 1rem;
    line-height: 1.4;
}

/* Anket Seçenekleri */
.poll-options {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.poll-option {
    position: relative;
    background: #40444b;
    border-radius: 10px;
    padding: 1rem;
    cursor: pointer;
    transition: background-color 0.2s ease, transform 0.15s ease;
    user-select: none;
}

.poll-option:hover {
    background: #4b5563;
    transform: translateX(4px);
}

.poll-option:active {
    transform: scale(0.98);
}

/* Seçilen Seçenek (Yeşil Vurgu) */
.poll-option.voted {
    background: #3ba55c;
    transform: scale(1.02);
    transition: background-color 0.3s ease, transform 0.3s ease;
}

.poll-option.voted:hover {
    background: #2d8f4a;
    transform: scale(1.02) translateX(4px);
}

.poll-option.voted .text-gray-400 {
    color: #e6f0e9;
}

/* Progress Bar */
.progress-bar {
    background: #36393f;
    height: 0.6rem;
    border-radius: 9999px;
    overflow: hidden;
    margin-top: 0.5rem;
    transition: background 0.2s ease;
}

.progress {
    background: linear-gradient(90deg, #5865f2, #7b8bff);
    height: 100%;
    border-radius: 9999px;
    transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);
}

.poll-option.voted .progress {
    background: linear-gradient(90deg, #3ba55c, #4cc774);
}

/* Oy Sayısı */
.poll-option .text-gray-400 {
    font-size: 0.85rem;
    color: #b9bbbe;
    transition: color 0.2s ease;
}

.poll-option:hover .text-gray-400 {
    color: #ffffff;
}

/* Erişilebilirlik */
.poll-option:focus {
    outline: none;
    box-shadow: 0 0 0 3px rgba(88, 101, 242, 0.3);
}
/* Mini Profile Modal Styles */
#mini-profile-modal {
    transition: opacity 0.4s ease, transform 0.4s ease;
    backdrop-filter: blur(5px);
}

#mini-profile-modal:not(.hidden) {
    opacity: 1;
    transform: scale(1) translateY(0);
    animation: slideIn 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

#mini-profile-modal.hidden {
    opacity: 0;
    transform: scale(0.9) translateY(-20px);
}

#mini-profile-modal .bg-\[\#1a1a1a\] {
    background-color: #1a1a1a;
    border-radius: 12px;
    box-shadow: 0 12px 24px rgba(0, 0, 0, 0.3);
    border: 1px solid rgba(255, 255, 255, 0.15);
    transition: transform 0.3s ease, box-shadow 0.3s ease;
}

#mini-profile-modal .bg-\[\#1a1a1a\]:hover {
    transform: translateY(-4px);
    box-shadow: 0 6px 24px rgba(0, 0, 0, 0.4);
}

/* Avatar Styles */
#mini-profile-modal .relative .w-20 {
    width: 80px;
    height: 80px;
    border: 3px solid #3CB371;
    box-shadow: 0 0 24px rgba(60, 179, 113, 0.3);
}

/* Status Indicator */
#mini-profile-status {
    width: 16px;
    height: 16px;
    border-radius: 50%;
    border: 2px solid #1a1a1a;
    bottom: 4px;
    right: 4px;
}

#mini-profile-status.status-online {
    background-color: #3ba55c;
    animation: pulse 2s infinite ease-in-out;
}

#mini-profile-status.status-offline {
    background-color: #747f8d;
}

/* Text Styles */
#mini-profile-title, #mini-profile-username {
    color: #3CB371;
    text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
}

#mini-profile-username {
    font-size: 1.25rem;
    font-weight: 700;
}

#mini-profile-status-text, #group-member-count, #group-creator {
    color: #909090;
}

/* Button Styles */
#view-full-profile {
    background-color: #3CB371;
    color: white;
    font-weight: 600;
    transition: all 0.2s ease;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
}

#view-full-profile:hover {
    background-color: #2E8B57;
    transform: scale(1.05);
}

#stats {
        display: none;
    }

/* Group Members List */
#group-members-list1 {
    max-height: 160px;
    overflow-y: auto;
}

#group-members-list1::-webkit-scrollbar {
    width: 6px;
}

#group-members-list1::-webkit-scrollbar-track {
    background: #2a2a2a;
    border-radius: 3px;
}

#group-members-list1::-webkit-scrollbar-thumb {
    background: #3CB371;
    border-radius: 3px;
}

/* Close Button */
#close-mini-profile {
    transition: color 0.2s ease;
}

#close-mini-profile:hover {
    color: #3CB371;
}

/* Animations */
@keyframes pulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.1); }
}

@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}
@media (max-width: 768px) {
    #message-container {
        flex: 1;
        overflow-y: auto;
        height: auto; /* Let flex control height */
    }
@media (max-width: 768px) {
    #input-area {
        min-height: 60px;
        height: auto;
        display: flex;
        align-items: flex-end;
        padding: 8px 16px;
        flex-shrink: 0;
    }
    .message-input {
        box-sizing: border-box;
        width: 100%;
        min-height: 48px;
        max-height: 120px; /* Reduced max height */
        overflow-y: auto;
        resize: none;
        line-height: 1.2; /* Tighter line spacing */
    }
    #message-form {
        align-items: flex-end;
    }
    #stats {
        display: flex;
    }
}
   /* Kenar çizgilerini tamamen kaldırma */
    #emoji-button,
    #gif-button,
    #poll-button,
    [for="file-input"],
    .send-button {
        border: none !important;
        margin: 0 !important;
    }
    
    /* Grup halinde yuvarlak köşeler */
    #emoji-button {
        border-top-left-radius: 0.5rem !important;
        border-bottom-left-radius: 0.5rem !important;
    }
    .send-button {
        border-top-right-radius: 0.5rem !important;
        border-bottom-right-radius: 0.5rem !important;
    }
    /* Avatar frame styling */
#mini-profile-avatar-container {
    position: relative;
    width: 80px;
    height: 80px;
}

#mini-profile-avatar {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    object-fit: cover;
}

#mini-profile-avatar-frame {
    position: absolute;
    top: 0;
    left: 0;
    width: 80px;
    height: 80px;
    object-fit: cover;
    pointer-events: none;
}
    </style>
   
</head>
<body>
<div id="main-content">
  <div class="flex h-screen">
     <!-- Server Sidebar (your provided code) -->
    <div id="server-sidebar" class="w-18 bg-gray-900 flex flex-col items-center px-2 py-3 space-y-2" style="background-color: #121212;">
        <a href="directmessages" class="w-12 h-12 rounded-full flex items-center justify-center hover:bg-indigo-500 cursor-pointer relative dm-link" style="background-color: #2A2A2A;">
            <i data-lucide="home" class="w-6 h-6 text-green-500"></i>
            <span id="unread-message-counter" class="absolute top-0 right-0 bg-red-500 text-white text-xs font-bold rounded-full w-5 h-5 flex items-center justify-center hidden">0</span>
        </a>
        <!-- Notification button and panel (unchanged) -->
        <div class="relative" id="notification-icon-container">
            <button id="notification-bell" class="w-12 h-12 bg-gray-700 rounded-full flex items-center justify-center hover:bg-indigo-500 cursor-pointer group" style="background-color: #2A2A2A;">
                <i data-lucide="bell" class="w-6 h-6 text-gray-400 group-hover:text-white"></i>
                <span id="notification-counter" class="absolute -top-1 -right-1 bg-red-500 text-white text-xs font-bold rounded-full w-4 h-4 flex items-center justify-center hidden">0</span>
            </button>
            <div id="notification-panel" draggable="true" class="hidden absolute shadow-lg z-30">
                <div class="p-3 border-b border-gray-700 flex justify-between items-center">
                    <h3 class="text-white font-semibold">Bildirimler</h3>
                    <button id="mark-all-read" class="text-sm text-blue-400 hover:underline">Tümünü Okundu Say</button>
                </div>
                <div id="notification-list" class="max-h-96 overflow-y-auto custom-scrollbar"></div>
            </div>
        </div>
        <div class="w-8 h-0.5 bg-gray-700 rounded-full mb-2"></div>
        <div id="server-list" class="flex-1 overflow-y-auto custom-scrollbar flex flex-col items-center space-y-2">
            <?php foreach ($servers as $server): ?>
            <div class="relative group server-item" data-server-id="<?php echo $server['id']; ?>" draggable="true">
                <div class="absolute -left-3 w-2 h-2 bg-white rounded-full scale-0 group-hover:scale-100 transition-transform"></div>
                <a href="server?id=<?php echo $server['id']; ?>" class="server-link">
                    <div class="w-12 h-12 bg-gray-700 rounded-3xl group-hover:rounded-2xl flex items-center justify-center hover:bg-indigo-500 cursor-pointer transition-all duration-200">
                        <?php if ($server['unread_channel_count'] > 0): ?>
                            <span class="absolute left-0 top-1/2 transform -translate-y-1/2 w-2 h-2 bg-white rounded-full"></span>
                        <?php endif; ?>
                        <?php if (!empty($server['profile_picture'])): ?>
                            <img src="<?php echo htmlspecialchars($server['profile_picture']); ?>" 
                                 alt="<?php echo htmlspecialchars($server['name']); ?>'s profile picture"
                                 class="w-full h-full object-cover rounded-3xl">
                        <?php else: ?>
                            <span class="text-white font-medium"><?php echo strtoupper(substr($server['name'], 0, 1)); ?></span>
                        <?php endif; ?>
                    </div>
                </a>
            </div>
            <?php endforeach; ?>
        </div>
            
         

            
            <!-- Sunucu Oluşturma Butonu -->
            <div class="w-12 h-12 bg-gray-700 rounded-full flex items-center justify-center hover:bg-green-500 cursor-pointer group" style="background-color: #2A2A2A;">
                <button onclick="openCreateServerModal()">
                    <i data-lucide="plus" class="w-6 h-6 text-green-500 group-hover:text-white"></i>
                </button>
            </div>
        </div>
<!-- Create Server Modal -->
<div id="create-server-modal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 modalentryanimation">
    <div class="bg-[#2d2f34] rounded-lg p-6 w-full max-w-md">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold"><?php echo $translations['CreateServer']; ?></h3>
            <button onclick="closeCreateServerModal()" class="text-gray-400 hover:text-white">
                <i data-lucide="x" class="w-6 h-6"></i>
            </button>
        </div>
        <form id="create-server-form" method="POST" enctype="multipart/form-data">
            <div class="mb-4">
                <label class="block text-sm font-medium mb-2"><?php echo $translations['ServerName']; ?></label>
                <input type="text" name="server_name" class="form-input w-full" required>
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium mb-2"><?php echo $translations['ServerDescription']; ?></label>
                <textarea name="server_description" class="form-input w-full"></textarea>
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium mb-2"><?php echo $translations['ServerProfile']; ?></label>
                <div class="upload-area cursor-pointer" id="modal-pp-upload">
                    <input type="file" name="server_pp" class="hidden" accept="image/*">
                    <i class="fas fa-upload text-2xl mb-2 text-gray-400"></i>
                    <div class="text-sm text-gray-400"><?php echo $translations['ServerFile']; ?></div>
                </div>
            </div>
            <div class="flex justify-end space-x-2">
                <button type="button" onclick="closeCreateServerModal()" class="btn bg-gray-600 hover:bg-gray-700"><?php echo $translations['Decline']; ?></button>
                <button type="submit" class="btn btn-primary"><?php echo $translations['Create']; ?></button>
            </div>
        </form>
    </div>
</div>

<!-- User Profile Mobile -->
        <div id="mobile-profile" class="p-2 gradient-border" style="user-select: none;">
            <div class="flex items-center">
                <div class="flex items-center flex-1 nav-shadow">
                    
                <button class="items-center absolute rounded p-1 text-gradient nav-shadow bg-nav-card" style="z-index: 17; left: 3%; bottom: 5px;" onclick="gotoDM()"><i data-lucide="home" class="w-10 h-8 items-center flex"></i>
                 <p style="font-size: 9px;">Anasayfa</p>
                </button>
                <button class="items-center absolute rounded p-1 text-gradient nav-shadow bg-nav-card" style="z-index: 17; left: 23%; bottom: 5px;" onclick="gotoCOMM()"><i data-lucide="users" class="w-10 h-8 items-center flex"></i>
                 <p style="font-size: 9px;">Topluluklar</p>
                </button>
                <button class="items-center absolute rounded p-1 text-gradient nav-shadow bg-nav-card" style="z-index: 17; left: 44%; bottom: 5px;" onclick="gotoLAKE()"><i data-lucide="message-square-diff" class="w-10 h-8 items-center flex"></i>
                 <p style="font-size: 9px;">Lakealt</p>
                </button>
                <div class="flex space-x-1">
                    <a href="/settings"class="absolute p-1 rounded text-gradient nav-shadow bg-nav-card" style="bottom: 5px; right: 10.5vh;">
                        <i data-lucide="settings" class="w-10 h-8"></i>
                        <p style="font-size: 9px; padding-left: 6px;">Ayarlar</p>
                    </a>
                </div>
                
                    <div class="absolute right-3" style:"bottom: 6px;">
                        <div class=" w-12 h-12 flex items-center justify-center bg-nav-card"style="border-radius: 20px;" onclick="gotoProfile()" >
                            <?php
                            $avatar_query = $db->prepare("SELECT avatar_url FROM user_profiles WHERE user_id = ?");
                            $avatar_query->execute([$_SESSION['user_id']]);
                            $avatar_result = $avatar_query->fetch();
                            $avatar_url = $avatar_result['avatar_url'] ?? '';

                            if (!empty($avatar_url)): ?>
                                <img src="<?php echo htmlspecialchars($avatar_url); ?>" 
                                     alt="Profile avatar"
                                     class="w-full h-full object-cover rounded-full">
                            <?php else: ?>
                                <span class="text-white text-sm font-medium">
                                    <?php echo strtoupper(substr($_SESSION['username'], 0, 1)); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="absolute bottom-0 right-0 w-8 h-8 rounded-full status-dot1 status-<?php echo htmlspecialchars($_SESSION['status'] ?? 'online'); ?>" style="border: 2px solid #121212;"></div>
                    </div>
                </div>
            
                <button id="mobile-status-modal" class="absolute rounded-full w-12 h-12 text-gradient nav-shadow bg-nav-card items-center bouncing-card" style="z-index: 15; right: 2%; bottom: 130%; padding-left: 2px;" onclick="openStatusModal()"><i data-lucide="zap" class="w-10 h-10 items-center flex"></i></button>
            </div>
        </div>
   <!-- Left Panel -->
<div id="left-panel" class="relative w-60 bg-gray-800 flex flex-col" style="background-color: #181818; user-select: none; z-index: 10;">
    <div class="flex-1 overflow-y-auto custom-scrollbar"> <!-- Scrollbar için overflow-y-auto ekledik -->
        <!-- Topluluklar, AnaSayfa, Güncellemeler gibi menü öğeleri -->
        <div class="p-2" style="user-select: none;">
            <div id="communities" class="flex items-center p-2 mt-1 shiny-button transition duration-500 rounded cursor-pointer">
                <i data-lucide="flag" class="w-5 h-5 text-gray-400 mr-2"></i>
                <span class="text-gray-400"><?php echo $translations['Communities']; ?></span>
            </div>
            <div id="home" class="flex items-center p-2 mt-1 shiny-button transition duration-500 rounded cursor-pointer">
                <i data-lucide="sticky-note" class="w-5 h-5 text-gray-400 mr-2"></i>
                <span class="text-gray-400"><?php echo $translations['Posts']; ?></span>
            </div>
            <div id="mobile-add-friend" class="add-friend-btn items-center p-2 mt-1 shiny-button transition duration-500 rounded cursor-pointer">
                <i data-lucide="user-plus" class="w-5 h-5 text-gray-400 mr-2"></i>
                <span class="text-gray-400">Arkadaş Ekle</span>
            </div>
            <div id="updates" class="flex items-center p-2 mt-1 rounded transition duration-500 cursor-pointer shiny-button">
                <i data-lucide="file-code" class="w-5 h-5 text-gray-400 mr-2"></i>
                <span class="text-gray-400"><?php echo $translations['Updates']; ?></span>
            </div>
            <div id="stats" class="flex items-center p-2 mt-1 shiny-button rounded transition duration-500 cursor-pointer">
                <i data-lucide="chart-column-increasing" class="w-5 h-5 text-gray-400 mr-2"></i>
                <span class="text-gray-400"><?php echo $translations['stats']['title']; ?></span>
            </div>
        </div>

        <!-- Direct Messages ve Friend List Birleşik Bölüm -->
        <div class="px-2 py-3">
            <div class="flex items-center justify-between px-2">
                <span class="text-gray-400 text-xs uppercase font-semibold"><?php echo $translations['Dm']; ?></span>
                <button onclick="openCreateGroupModal()" class="text-gray-400 hover:text-white">
            <i data-lucide="plus" class="w-4 h-4"></i>
                </button>
            </div>
            
   <!-- Friends List -->
<div class="friends-list space-y-1" style="overflow-x: hidden;">
    <?php
    // Grupları ve arkadaşları al
    $groups = getGroups($db, $_SESSION['user_id']);
    $friends = getFriends($db, $_SESSION['user_id'], false);

    // Grupları ve arkadaşları birleştir
    $combined_items = [];

    // Grupları ekle
    foreach ($groups as $group) {
        $unread = getGroupUnreadCount($db, $_SESSION['user_id'], $group['id']);
        $combined_items[] = [
            'type' => 'group',
            'id' => $group['id'],
            'name' => $group['name'],
            'avatar_url' => $group['avatar_url'],
            'member_count' => $group['member_count'],
            'is_owner' => $group['is_owner'],
            'unread' => $unread,
            'last_interaction' => $group['last_interaction'] ?? '1970-01-01 00:00:00',
        ];
    }

    // Arkadaşları ekle
  foreach ($friends as $friend) {
    $combined_items[] = [
        'type' => 'friend',
        'id' => $friend['id'],
        'name' => $friend['username'],
        'avatar_url' => $friend['avatar_url'],
        'is_online' => $friend['is_online'],
        'status' => $friend['status'],  // Durum alanını ekle
        'unread_messages' => $friend['unread_messages'],
        'last_interaction' => $friend['last_interaction'] ?? '1970-01-01 00:00:00',
    ];
}

    // last_interaction'a göre sırala (en son etkileşimden en eskiye)
    usort($combined_items, function ($a, $b) {
        return strcmp($b['last_interaction'], $a['last_interaction']);
    });

    // Birleştirilmiş listeyi göster
    foreach ($combined_items as $item):
    ?>
        <?php if ($item['type'] === 'group'): ?>
            <div class="group-item group relative flex items-center p-2 shiny-button cursor-pointer rounded"
                 data-group-id="<?= $item['id'] ?>" 
                 data-is-owner="<?= $item['is_owner'] ? 'true' : 'false' ?>">
                
                <!-- Avatar Container -->
                <div class="relative w-10 h-10 mr-2">
                    <?php if (!empty($item['avatar_url'])): ?>
                        <img src="<?= htmlspecialchars($item['avatar_url']) ?>" 
                             alt="<?= htmlspecialchars($item['name']) ?> avatarı"
                             class="w-full h-full rounded-full object-cover border-2 border-purple-500">
                    <?php else: ?>
                        <div class="w-full h-full rounded-full bg-purple-500 flex items-center justify-center">
                            <i data-lucide="users" class="w-4 h-4 text-white"></i>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="flex-1">
                    <div class="text-white text-sm"><?= htmlspecialchars($item['name']) ?></div>
                    <div class="text-gray-500 text-xs"><?= $item['member_count'] ?> <?php echo $translations['Member']; ?></div>
                </div>

                <?php if ($item['unread'] > 0): ?>
                    <div class="unread-count bg-red-500 text-white text-xs rounded-full w-5 h-5 flex items-center justify-center ml-2">
                        <?= $item['unread'] ?>
                    </div>
                <?php endif; ?>

                <?php if ($item['is_owner']): ?>
                    <!-- Transfer ownership button for owners -->
                    <div class="absolute right-2 opacity-0 group-hover:opacity-100 transition-opacity duration-300">
                        <button class="text-blue-400 hover:text-blue-300 p-1.5 rounded-md hover:bg-blue-500/20 transition-all"
        onclick="openTransferModal('<?= $item['id'] ?>', event)">
    <i data-lucide="key" class="w-4 h-4"></i>
</button>
                    </div>
                <?php else: ?>
                    <!-- Leave button for non-owners -->
                    <div class="absolute right-2 opacity-0 group-hover:opacity-100 transition-opacity duration-300">
                      <button class="text-rose-400 hover:text-rose-300 p-1.5 rounded-md hover:bg-rose-500/20 transition-all"
        onclick="openLeaveGroupModal('<?= $item['id'] ?>', event)">
    <i data-lucide="log-out" class="w-4 h-4"></i>
</button>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
           <div class="flex items-center p-2 hover:bg-gray-700 shiny-button transition duration-500 ease rounded cursor-pointer friend-item1" 
     data-friend-id="<?= htmlspecialchars($item['id']) ?>">
    <div class="profile-avatar">
        <?php if (!empty($item['avatar_url'])): ?>
            <img src="<?= htmlspecialchars($item['avatar_url']) ?>" 
                 alt="<?= htmlspecialchars($item['name']) ?>'s avatar">
        <?php else: ?>
            <span class="avatar-letter"><?= strtoupper(substr($item['name'], 0, 1)) ?></span>
        <?php endif; ?>
    </div>
    <div class="ml-2">
        <div class="text-gray-300 text-sm"><?= htmlspecialchars($item['name']) ?></div>
        <div class="text-gray-500 text-xs online-status">
            <?= $translations[ucfirst($item['status'])] ?? ucfirst($item['status']) ?>
        </div>
    </div>
    <div class="status-dot status-<?= htmlspecialchars($item['status']) ?> ml-auto"></div>
    <?php if ($item['unread_messages'] > 0): ?>
        <div class="unread-count bg-red-500 text-white text-xs rounded-full w-5 h-5 flex items-center justify-center ml-2">
            <?= htmlspecialchars($item['unread_messages']) ?>
        </div>
    <?php endif; ?>
    <button class="hide-friend-btn text-gray-400 hover:text-white p-1.5 rounded-md hover:bg-gray-700 transition-all"
        onclick="toggleFriendVisibility('<?= htmlspecialchars($item['id']) ?>', event)"
        data-tooltip="Gizle">
    <i data-lucide="eye-off" class="w-4 h-4"></i>
</button>
</div>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
</div>
</div>
<!-- User Profile -->
<div id="user-profile" style="background-color: #121212; padding: 8px; user-select: none; border-top: 1px solid #333333;">
    <div style="display: flex; align-items: center; justify-content: space-between;">
        <div style="display: flex; align-items: center; flex: 1;">
            <div style="position: relative;">
                <div style="width: 32px; height: 32px; background-color: #4f46e5; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                    <?php
                    // Ensure session username is set
                    $real_username = isset($_SESSION['username']) ? $_SESSION['username'] : 'Unknown User';

                    // Fetch avatar_url and display_username from user_profiles
                    $profile_query = $db->prepare("SELECT avatar_url, display_username FROM user_profiles WHERE user_id = ?");
                    $profile_query->execute([$_SESSION['user_id']]);
                    $profile_result = $profile_query->fetch();
                    $avatar_url = $profile_result['avatar_url'] ?? '';
                    $display_username = $profile_result['display_username'] ?? $real_username; // Fallback to real username

                    if (!empty($avatar_url)): ?>
                        <img src="<?php echo htmlspecialchars($avatar_url); ?>" 
                             alt="Profile avatar"
                             style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                    <?php else: ?>
                        <span style="color: #ffffff; font-size: 14px; font-weight: 500;">
                            <?php echo strtoupper(substr($real_username, 0, 1)); ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="status-dot1 status-<?php echo htmlspecialchars($_SESSION['status'] ?? 'online'); ?>" style="position: absolute; bottom: 0; right: 0; width: 14px; height: 14px; border-radius: 50%; border: 3px solid #121212;"></div>
            </div>
            <div style="margin-left: 8px;">
                <!-- Display display_username -->
                <div style="color: #ffffff; font-size: 14px; font-weight: 500;">
                    <?php echo htmlspecialchars($display_username); ?>
                </div>
                <!-- Status text with hover effect for real username -->
                <div class="status-text-container" style="position: relative; min-height: 16px;">
                    <div class="status-text" style="color: #9ca3af; font-size: 12px; transition: opacity 0.2s ease;">
                        <?php 
                        $status_text = [
                            'online' => $translations['Online'] ?? 'Çevrimiçi',
                            'idle' => $translations['Idle'] ?? 'Boşta',
                            'dnd' => $translations['DoNotDisturb'] ?? 'Rahatsız Etmeyin',
                            'offline' => $translations['Offline'] ?? 'Çevrimdışı'
                        ];
                        echo $status_text[$_SESSION['status'] ?? 'online'] ?? $translations['Online'];
                        ?>
                    </div>
                    <div class="real-username" style="color: #9ca3af; font-size: 12px; font-weight: 400; position: absolute; top: 0; left: 0; opacity: 0; transition: opacity 0.2s ease; z-index: 1;">
                        <?php echo '@' . htmlspecialchars($real_username); ?>
                    </div>
                </div>
            </div>
        </div>
        <div style="display: flex; gap: 4px;">
            <a href="/settings" style="color: #9ca3af; padding: 4px; border-radius: 4px;" onmouseover="this.style.color='#ffffff'; this.style.backgroundColor='#4b5563';" onmouseout="this.style.color='#9ca3af'; this.style.backgroundColor='transparent';">
                <i data-lucide="settings" style="width: 16px; height: 16px;"></i>
            </a>
            <a href="logout" style="color: #9ca3af; padding: 4px; border-radius: 4px;" onmouseover="this.style.color='#ffffff'; this.style.backgroundColor='#4b5563';" onmouseout="this.style.color='#9ca3af'; this.style.backgroundColor='transparent';">
                <i data-lucide="log-out" style="width: 16px; height: 16px;"></i>
            </a>
        </div>
    </div>
</div>


        </div>
<div id="status-modal" style="display: none; position: absolute; background-color: #2f3136; border: 1px solid #202225; border-radius: 4px; z-index: 1000; transform: translateY(-160px);">
    <div class="status-option" data-status="online" style="padding: 8px; cursor: pointer; color: #dcddde;"><?php echo $translations['Online'] ?? 'Çevrimiçi'; ?></div>
    <div class="status-option" data-status="idle" style="padding: 8px; cursor: pointer; color: #dcddde;"><?php echo $translations['Idle'] ?? 'Boşta'; ?></div>
    <div class="status-option" data-status="dnd" style="padding: 8px; cursor: pointer; color: #dcddde;"><?php echo $translations['DoNotDisturb'] ?? 'Rahatsız Etmeyin'; ?></div>
    <div class="status-option" data-status="offline" style="padding: 8px; cursor: pointer; color: #dcddde;"><?php echo $translations['Offline'] ?? 'Çevrimdışı'; ?></div>
</div>
  <!-- Main Content -->
    <div id="movesidebar" class="absolute h-full right-72 flex-1 flex flex-col transition-all duration-300 ease-in-out" style="background-color: #202020; z-index: 15;">
        <!-- Top Menu -->
        <div id="top-menu" class="h-12 bg-gray-700 border-b border-gray-900 flex items-center px-4" style="background-color: #181818; border-color: #2A2A2A; user-select: none;">
            <div id="left-top-buttons" class="flex items-center space-x-4">
                <button class="text-gray-400 hover:bg-gray-700 px-2 py-1 rounded transition duration-500" id="online-tab"><?php echo $translations['Online']; ?></button>
                <button class="text-gray-400 hover:bg-gray-700 px-2 py-1 rounded transition duration-500" id="all-tab"><?php echo $translations['All']; ?></button>
                <button class="text-gray-400 hover:bg-gray-700 px-2 py-1 rounded relative transition duration-500" id="pending-tab">
                    <?php echo $translations['Pending']; ?>
                    <?php if (getFriendRequestsCount($db, $_SESSION['user_id']) > 0): ?>
                        <span id="pending-request-count" class="bg-red-500 text-white text-xs rounded-full w-5 h-5 flex items-center justify-center absolute top-0 right-0 transform translate-x-1/2 -translate-y-1/2">
                            <?php echo getFriendRequestsCount($db, $_SESSION['user_id']); ?>
                        </span>
                    <?php endif; ?>
                </button>
                <button class="text-gray-400 hover:bg-gray-700 px-2 py-1 rounded transition duration-500" id="blocked-tab"><?php echo $translations['Blocked']; ?></button>
            </div>
            <button id="add-friend-btn" class="ml-auto bg-green-600 text-white px-4 py-1 rounded hover:bg-green-700 transition duration-500" style="z-index: 17;">
                <?php echo $translations['AddFriend']; ?>
            </button>
        </div>
        
      <!-- Friends List and Friend Requests -->
<div class="flex flex-1 flex-col md:flex-row gap-4 h-[calc(100vh-180px)] overflow-y-auto">
    <div id="friends-list-container" class="flex-1 p-4 flex flex-col ">
        <h2 class="text-white text-xl font-bold mb-4"><?php echo $translations['Friendlist']; ?></h2>
        
        <div class="onboarding-section p-6 rounded-lg shadow-xl mb-6 mx-auto mt-[-1rem]" style="max-width: 900px; background-color: #2A2A2A;">
            <div class="flex justify-between items-center cursor-pointer p-2 rounded-md transition-all duration-200 ease-in-out" 
                 onclick="toggleOnboarding()"
                 id="onboarding-header"
                 style="background-color: #2a2a2a;"> 
                <h2 class="text-[#11b980] text-2xl font-bold"><?php echo $translations['Explore-Lakeban']; ?></h2>
                <div class="flex items-center space-x-3">
                    <button class="text-red-500 hover:text-red-400 p-1 rounded-full hover:bg-gray-700 transition-colors" 
                            onclick="event.stopPropagation(); showDisableOnboardingWarning()">
                        <i data-lucide="x-circle" class="w-6 h-6"></i>
                    </button>
                    <i id="onboarding-toggle-icon" data-lucide="chevron-up" class="w-6 h-6 text-[#11b980] transition-transform duration-300"></i>
                </div>
            </div>
            
            <div id="onboarding-content" class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-6"> 
                <div class="card p-4 rounded-lg shadow-md transition-all duration-200 ease-in-out hover:shadow-lg hover:ring-2 hover:ring-[#11b980]/50 cursor-pointer flex items-center group" onclick="openAddFriendModal()" style="background-color: #3A3A3A;">
                    <div class="flex-shrink-0 mr-4">
                        <i data-lucide="user-plus" class="w-6 h-6 text-[#11b980]"></i>
                    </div>
                    <div class="flex-grow">
                        <h3 class="text-[#11b980] font-medium text-lg"><?php echo $translations['AddFriend']; ?></h3>
                        <p class="text-gray-400 text-sm"><?php echo $translations['Findnewfriends']; ?></p>
                    </div>
                    <div class="flex-shrink-0 ml-4">
                        <i data-lucide="arrow-right" class="w-5 h-5 text-gray-500 group-hover:text-[#11b980] transition-colors"></i>
                    </div>
                </div>

                <div class="card p-4 rounded-lg shadow-md transition-all duration-200 ease-in-out hover:shadow-lg hover:ring-2 hover:ring-[#11b980]/50 cursor-pointer flex items-center group" onclick="openCreateGroupModal()" style="background-color: #3A3A3A;">
                    <div class="flex-shrink-0 mr-4">
                        <i data-lucide="users" class="w-6 h-6 text-[#11b980]"></i>
                    </div>
                    <div class="flex-grow">
                        <h3 class="text-[#11b980] font-medium text-lg"><?php echo $translations['Createagroup']; ?></h3>
                        <p class="text-gray-400 text-sm"><?php echo $translations['Createwithfriends']; ?></p>
                    </div>
                    <div class="flex-shrink-0 ml-4">
                        <i data-lucide="arrow-right" class="w-5 h-5 text-gray-500 group-hover:text-[#11b980] transition-colors"></i>
                    </div>
                </div>

                <div class="card p-4 rounded-lg shadow-md transition-all duration-200 ease-in-out hover:shadow-lg hover:ring-2 hover:ring-[#11b980]/50 cursor-pointer flex items-center group" onclick="location.href='posts'" style="background-color: #3A3A3A;">
                    <div class="flex-shrink-0 mr-4">
                        <i data-lucide="clipboard-list" class="w-6 h-6 text-[#11b980]"></i>
                    </div>
                    <div class="flex-grow">
                        <h3 class="text-[#11b980] font-medium text-lg"><?php echo $translations['PostLakeban']; ?></h3>
                        <p class="text-gray-400 text-sm"><?php echo $translations['WeGood?']; ?></p>
                    </div>
                    <div class="flex-shrink-0 ml-4">
                        <i data-lucide="arrow-right" class="w-5 h-5 text-gray-500 group-hover:text-[#11b980] transition-colors"></i>
                    </div>
                </div>

                <div class="card p-4 rounded-lg shadow-md transition-all duration-200 ease-in-out hover:shadow-lg hover:ring-2 hover:ring-[#11b980]/50 cursor-pointer flex items-center group" onclick="location.href='topluluklar'" style="background-color: #3A3A3A;">
                    <div class="flex-shrink-0 mr-4">
                        <i data-lucide="compass" class="w-6 h-6 text-[#11b980]"></i>
                    </div>
                    <div class="flex-grow">
                        <h3 class="text-[#11b980] font-medium text-lg"><?php echo $translations['Explore-Lakeban']; ?></h3>
                        <p class="text-gray-400 text-sm"><?php echo $translations['Explorer']; ?></p>
                    </div>
                    <div class="flex-shrink-0 ml-4">
                        <i data-lucide="arrow-right" class="w-5 h-5 text-gray-500 group-hover:text-[#11b980] transition-colors"></i>
                    </div>
                </div>

                <div class="card p-4 rounded-lg shadow-md transition-all duration-200 ease-in-out hover:shadow-lg hover:ring-2 hover:ring-[#11b980]/50 cursor-pointer flex items-center group" onclick="location.href='settings'" style="background-color: #3A3A3A;">
                    <div class="flex-shrink-0 mr-4">
                        <i data-lucide="settings" class="w-6 h-6 text-[#11b980]"></i>
                    </div>
                    <div class="flex-grow">
                        <h3 class="text-[#11b980] font-medium text-lg"><?php echo $translations['Customize']; ?></h3>
                        <p class="text-gray-400 text-sm"><?php echo $translations['Customizer']; ?></p>
                    </div>
                    <div class="flex-shrink-0 ml-4">
                        <i data-lucide="arrow-right" class="w-5 h-5 text-gray-500 group-hover:text-[#11b980] transition-colors"></i>
                    </div>
                </div>

                <div class="card p-4 rounded-lg shadow-md transition-all duration-200 ease-in-out hover:shadow-lg hover:ring-2 hover:ring-[#11b980]/50 cursor-pointer flex items-center group" onclick="location.href='https://lakeban.com/join_server?code=Lakeban'" style="background-color: #3A3A3A;">
                    <div class="flex-shrink-0 mr-4">
                        <i data-lucide="globe" class="w-6 h-6 text-[#11b980]"></i>
                    </div>
                    <div class="flex-grow">
                        <h3 class="text-[#11b980] font-medium text-lg"><?php echo $translations['Testserver']; ?></h3>
                        <p class="text-gray-400 text-sm"><?php echo $translations['Testservers']; ?></p>
                    </div>
                    <div class="flex-shrink-0 ml-4">
                        <i data-lucide="arrow-right" class="w-5 h-5 text-gray-500 group-hover:text-[#11b980] transition-colors"></i>
                    </div>
                </div>
            </div>
        </div>
       <div id="no-online-friends" class="hidden text-center text-gray-400 text-lg mt-4">
            <img src="LakebanAssets/MascotNo.png" alt="No friends online" class="mx-auto mt-2" style="max-width: 500px; max-height:500px;">
    <p><?php echo $translations['Nofriend']; ?></p>
</div>
        <div id="friends-list" class="flex-1 overflow-y-auto space-y-2 pb-4">
<?php foreach ($allFriends as $friend): ?>
    <div class="bg-gray-800 rounded-lg p-3 flex items-center justify-between friend-item group" 
         data-friend-id="<?php echo htmlspecialchars($friend['id']); ?>" 
         style="background-color: #1a1a1a; min-width: 300px;"
         onclick="<?php echo isFriendHidden($db, $_SESSION['user_id'], $friend['id']) ? "toggleFriendVisibility(" . htmlspecialchars($friend['id']) . ")" : "(" . htmlspecialchars($friend['id']) . ", '" . htmlspecialchars($friend['username']) . "')"; ?>">
        <div class="flex items-center">
            <div class="relative">
                <div class="w-10 h-10 bg-gray-700 rounded-full flex items-center justify-center overflow-hidden">
                    <?php if (!empty($friend['avatar_url'])): ?>
                        <img src="<?php echo htmlspecialchars($friend['avatar_url']); ?>" 
                             alt="<?php echo htmlspecialchars($friend['username']); ?>'s avatar"
                             class="w-full h-full object-cover">
                    <?php else: ?>
                        <span class="text-white font-medium">
                            <?php echo strtoupper(substr($friend['username'], 0, 1)); ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="absolute bottom-0 right-0 w-3 h-3 status-dot status-<?php echo htmlspecialchars($friend['status']); ?> rounded-full border-2 border-gray-800"></div>
            </div>
            <div class="ml-3">
                <div class="text-white"><?php echo htmlspecialchars($friend['username']); ?></div>
                <div class="text-sm text-gray-400 online-status">
                    <?php 
                    // Durum metnini çevirilerle eşleştir
                    $statusText = [
                        'online' => $translations['Online'] ?? 'Çevrimiçi',
                        'idle' => $translations['Idle'] ?? 'Boşta',
                        'dnd' => $translations['DoNotDisturb'] ?? 'Rahatsız Etmeyin',
                        'offline' => $translations['Offline'] ?? 'Çevrimdışı'
                    ];
                    echo isset($statusText[$friend['status']]) ? $statusText[$friend['status']] : ($translations['Offline'] ?? 'Çevrimdışı');
                    ?>
                    <?php if (isFriendHidden($db, $_SESSION['user_id'], $friend['id'])): ?>
                        <span class="text-red-400 ml-1">[Gizli]</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="relative">
            <div class="text-gray-400 hover:text-white p-1 rounded hover:bg-gray-700 cursor-pointer" 
                 id="more-options-<?php echo htmlspecialchars($friend['id']); ?>" 
                 onclick="event.stopPropagation(); (<?php echo htmlspecialchars($friend['id']); ?>)">
                <i data-lucide="more-vertical" class="w-5 h-5"></i>
            </div>
            <div class="absolute right-0 mt-2 w-48 bg-gray-800 rounded-md shadow-lg hidden options-menu" 
                 id="options-menu-<?php echo htmlspecialchars($friend['id']); ?>">
                <button class="block w-full text-left px-4 py-2 text-sm text-gray-400 hover:bg-gray-700 hover:text-white" 
                        data-action="remove-friend" 
                        data-friend-id="<?php echo htmlspecialchars($friend['id']); ?>">
                    <?php echo $translations['Remove-Friend'] ?? 'Arkadaşı Kaldır'; ?>
                </button>
                <button class="block w-full text-left px-4 py-2 text-sm text-gray-400 hover:bg-gray-700 hover:text-white" 
                        data-action="block-friend" 
                        data-friend-id="<?php echo htmlspecialchars($friend['id']); ?>">
                    <?php echo $translations['Block-Friend'] ?? 'Arkadaşı Engelle'; ?>
                </button>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
        <div id="friend-count-info" class="text-center text-gray-400 text-sm mt-4">
            <?php echo $translations['Total']; ?> <?php echo count($friends); ?> <?php echo $translations['TotalFriends']; ?>
        </div>
    </div>
   <div id="create-group-modal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 modalentryanimation">
       <div class="modal-conten rounded-lg p-6 w-full max-w-md">
            <div class="flex justify-between items-center mb-4">
                   <h3 class="text-xl font-bold" style="color: var(--accent-color); border-left: 4px solid var(--accent-color); padding-left: 1rem;"><?php echo $translations['CreateGroup']; ?></h3>
                <button onclick="closeCreateGroupModal()" class="text-gray-400 hover:text-white">
                    <i data-lucide="x" class="w-6 h-6"></i>
                </button>
            </div>

            <div id="form-message" class="hidden mb-4 p-3 rounded text-sm"></div>

            <form id="create-group-form">
                <div class="mb-4">
                    <label class="block text-sm font-medium mb-2 text-gray-300"><?php echo $translations['GroupAvatar']; ?></label>
                    <div class="flex items-center gap-3">
                        <div id="group-avatar-preview" class="w-12 h-12 rounded-full bg-gray-600 flex items-center justify-center overflow-hidden">
                            <i data-lucide="image" class="w-6 h-6 text-gray-400"></i>
                        </div>
                        <input type="file" id="group-avatar" accept="image/*" class="hidden" onchange="previewGroupAvatar(this)">
                        <button type="button" onclick="document.getElementById('group-avatar').click()" class="btn bg-gray-600 hover:bg-gray-700 text-sm px-3 py-1">
                            <?php echo $translations['Select']; ?>
                        </button>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="block text-sm font-medium mb-2 text-gray-300"><?php echo $translations['GroupName']; ?></label>
                    <input type="text" id="group-name" class="form-input w-full bg-[#121212] border-[#2d2d2d] text-white" required>
                </div>

                <div class="mb-4">
                    <label class="block text-sm font-medium mb-2 text-gray-300"><?php echo $translations['SelectedMembers']; ?></label>
                    <div id="selected-members" class="flex flex-wrap gap-2 min-h-[40px] p-2 bg-[#121212] rounded border border-[#2d2d2d]"></div>
                    <div class="text-xs text-gray-400 mt-1">
                        <span id="selected-count">0</span> <?php echo $translations['Selected']; ?>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="block text-sm font-medium mb-2 text-gray-300"><?php echo $translations['SelectFriends']; ?></label>
                    <input type="text" id="friend-search" placeholder="<?php echo $translations['SearchFriends']; ?>" class="form-input w-full bg-[#121212] border-[#2d2d2d] text-white mb-2" onkeyup="filterFriends()">
                    <div class="flex justify-between mb-2">
                        <button type="button" onclick="selectAllFriends()" class="text-xs text-blue-400 hover:text-blue-300"><?php echo $translations['SelectAll']; ?></button>
                        <button type="button" onclick="deselectAllFriends()" class="text-xs text-red-400 hover:text-red-300"><?php echo $translations['Deselect']; ?></button>
                    </div>
                    <div id="friend-selection" class="max-h-60 overflow-y-auto border border-[#2d2d2d] rounded bg-[#121212]">
                        <?php foreach ($friends as $friend): ?>
                            <label class="flex items-center p-2 hover:bg-[#1a1a1a] cursor-pointer border-b border-[#2d2d2d] transition-all">
                                <input type="checkbox" value="<?php echo htmlspecialchars($friend['id']); ?>" data-username="<?php echo htmlspecialchars($friend['username']); ?>" data-avatar="<?php echo htmlspecialchars($friend['avatar_url']); ?>" class="mr-2" onchange="updateSelectedMembers()">
                                <div class="flex items-center">
                                    <div class="w-6 h-6 rounded-full bg-gray-600 flex items-center justify-center overflow-hidden mr-2">
                                        <?php if (!empty($friend['avatar_url'])): ?>
                                            <img src="<?php echo htmlspecialchars($friend['avatar_url']); ?>" class="w-full h-full object-cover">
                                        <?php else: ?>
                                            <span class="text-white text-xs"><?php echo strtoupper(substr($friend['username'], 0, 1)); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <span class="text-white text-sm"><?php echo htmlspecialchars($friend['username']); ?></span>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="flex justify-end space-x-2">
                    <button type="button" onclick="closeCreateGroupModal()" class="btn bg-gray-600 hover:bg-gray-700"><?php echo $translations['Decline']; ?></button>
                    <button type="submit" class="btn btn-primary flex items-center" id="create-group-button">
                        <span id="create-group-text"><?php echo $translations['Create']; ?></span>
                        <div id="create-group-spinner" class="hidden animate-spin ml-2">
                            <i data-lucide="loader-2" class="w-4 h-4"></i>
                        </div>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div id="disable-onboarding-modal" class="hidden fixed inset-0 bg-black bg-opacity-75 flex items-center justify-center z-[1000] modalentryanimation">
        <div class="rounded-lg p-6 w-full max-w-sm border border-red-600 shadow-lg" style="background-color: #181818">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-bold text-red-500 border-l-4 border-red-500 pl-4"><?php echo $translations['Warning']; ?></h3>
                <button onclick="closeDisableOnboardingWarning()" class="text-gray-400 hover:text-white">
                    <i data-lucide="x" class="w-6 h-6"></i>
                </button>
            </div>
            <p class="text-gray-300 mb-6">
            <?php echo $translations['CloseLakebanExplore']; ?>
            </p>
            <div class="flex justify-end space-x-2">
                <button type="button" onclick="closeDisableOnboardingWarning()" class="btn bg-gray-600 hover:bg-gray-700"><?php echo $translations['Decline']; ?></button>
                <button type="button" onclick="disableOnboardingPermanently()" class="btn bg-red-600 hover:bg-red-700 flex items-center">
                    <span id="disable-confirm-text"><?php echo $translations['Yes, Close']; ?></span>
                    <div id="disable-confirm-spinner" class="hidden animate-spin ml-2">
                        <i data-lucide="loader-2" class="w-4 h-4"></i>
                    </div>
                </button>
            </div>
        </div>
    </div>
</div>
<!-- Friend Requests Section -->
<div id="friend-requests-section" class="flex-1 p-4 border-l border-gray-600 overflow-y-auto custom-scrollbar">
    <h2 class="text-white text-xl font-bold mb-4"><?php echo $translations['FriendRequests']; ?></h2>
    
    <!-- Gelen İstekler -->
    <div id="friend-requests" class="space-y-2 max-h-[400px] overflow-y-auto">
        <?php foreach ($friend_requests as $request): ?>
        <div class="bg-gray-800 rounded-lg p-3 flex items-center justify-between">
            <div class="flex items-center">
                <div class="w-10 h-10 bg-gray-700 rounded-full flex items-center justify-center">
                    <?php if (!empty($request['avatar_url'])): ?>
                        <img src="<?php echo htmlspecialchars($request['avatar_url']); ?>" 
                             alt="<?php echo htmlspecialchars($request['username']); ?>'s avatar">
                    <?php else: ?>
                        <span class="text-white font-medium">
                            <?php echo strtoupper(substr($request['username'], 0, 1)); ?>
                        </span>
                    <?php endif; ?>
                </div>
                <span class="ml-3 text-white"><?php echo htmlspecialchars($request['username']); ?></span>
            </div>
            <div class="flex space-x-2">
                <button class="accept-request bg-green-500 text-white px-3 py-1 rounded hover:bg-green-600"
                        data-sender-id="<?php echo $request['id']; ?>">
                    <?php echo $translations['Accept']; ?>
                </button>
                <button class="reject-request bg-red-500 text-white px-3 py-1 rounded hover:bg-red-600"
                        data-sender-id="<?php echo $request['id']; ?>">
                    <?php echo $translations['Reject']; ?>
                </button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    
    <!-- Gönderilen İstekler -->
    <div id="sent-requests-section" class="mt-8">
        <h2 class="text-white text-xl font-bold mb-4"><?php echo $translations['SentRequests']; ?></h2>
        <div id="sent-requests" class="space-y-2 max-h-[400px] overflow-y-auto">
            <?php foreach ($sent_requests as $request): ?>
            <div class="bg-gray-800 rounded-lg p-3 flex items-center justify-between">
                <div class="flex items-center">
                    <div class="w-10 h-10 bg-gray-700 rounded-full flex items-center justify-center">
                        <?php if (!empty($request['avatar_url'])): ?>
                            <img src="<?php echo htmlspecialchars($request['avatar_url']); ?>" 
                                 alt="<?php echo htmlspecialchars($request['username']); ?>'s avatar">
                        <?php else: ?>
                            <span class="text-white font-medium">
                                <?php echo strtoupper(substr($request['username'], 0, 1)); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <span class="ml-3 text-white"><?php echo htmlspecialchars($request['username']); ?></span>
                </div>
                <div class="flex space-x-2">
                    <button class="cancel-request bg-gray-600 text-white px-3 py-1 rounded hover:bg-gray-700"
        data-receiver-id="<?php echo (int)$request['id']; ?>"> <!-- int'e cast et -->
    <?php echo $translations['Cancelsend']; ?>
</button>
                </div>
            </div>
            <?php endforeach; ?>
            
            <?php if (empty($sent_requests)): ?>
                <p class="text-gray-400 py-4 text-center"><?php echo $translations['SentRequest']; ?></p>
            <?php endif; ?>
        </div>
    </div>
</div>
    
    <!-- Blocked Friends -->
        <div id="blocked-friends-section" class="hidden flex-1 p-4 border-l border-gray-600 overflow-y-auto custom-scrollbar">
        <h2 class="text-white text-xl font-bold mb-4"><?php echo $translations['BlockedFriends']; ?></h2>
        <div id="blocked-friends" class="space-y-2 max-h-[400px] overflow-y-auto">
            <?php foreach ($blocked_friends as $blockedFriend): ?>
            <div class="bg-gray-800 rounded-lg p-3 flex items-center justify-between">
                <div class="flex items-center">
                    <div class="w-10 h-10 bg-gray-700 rounded-full flex items-center justify-center">
                        <?php if (!empty($blockedFriend['avatar_url'])): ?>
                            <img src="<?php echo htmlspecialchars($blockedFriend['avatar_url']); ?>" 
                                 alt="<?php echo htmlspecialchars($blockedFriend['username']); ?>'s avatar">
                        <?php else: ?>
                            <span class="text-white font-medium">
                                <?php echo strtoupper(substr($blockedFriend['username'], 0, 1)); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <span class="ml-3 text-white"><?php echo htmlspecialchars($blockedFriend['username']); ?></span>
                </div>
                <div class="flex space-x-2">
                    <button class="unblock-friend bg-green-500 text-white px-3 py-1 rounded hover:bg-green-600"
                            data-friend-id="<?php echo $blockedFriend['id']; ?>">
                        <?php echo $translations['Unblock']; ?>
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

 
        <!-- Add Friend Modal -->
        <div id="add-friend-modal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center modalentryanimation" style="user-select: none; z-index: 9999;">
          <div id="add-friend-modal-background" class="bg-gray-800 p-6 rounded-lg w-96">
                <h3 class="text-white text-xl font-bold mb-4"><?php echo $translations['AddFriend']; ?></h3>
                <input type="text" id="friend-username" 
                       class="w-full text-white px-3 py-2 rounded mb-4" 
                       placeholder="<?php echo $translations['EnterUser']; ?>">
                <div class="flex justify-end space-x-2">
                    <button id="cancel-add-friend" 
                            class="bg-gray-700 text-white px-4 py-2 rounded transition-all duration-500 ease-in-out">
                        <?php echo $translations['Cancelsend']; ?>
                    </button>
                    <button id="confirm-add-friend" 
                            class="bg-green-500 text-white px-4 py-2 rounded transition-all duration-500 ease-in-out">
                        <?php echo $translations['SendRequest']; ?>
                    </button>
                </div>
            </div>
        </div>
         <div id="incoming-call-modal" class="hidden fixed inset-0 bg-black bg-opacity-70 flex items-center justify-center z-[10000]">
    <div class="bg-[#181818] rounded-lg p-8 w-full max-w-sm text-center shadow-lg border border-gray-700">
        <h3 class="text-xl font-bold text-white mb-2">Gelen Arama</h3>
        
        <img id="incoming-caller-avatar" src="" alt="Arayan Avatarı" class="w-24 h-24 rounded-full border-4 border-gray-600 object-cover mx-auto mt-4">
        <p id="incoming-caller-username" class="text-lg text-white font-semibold mt-3"></p>
        <p class="text-sm text-gray-400">sizi arıyor...</p>

        <div class="flex justify-center space-x-6 mt-8">
            <button id="decline-call-btn" class="flex flex-col items-center text-red-500 hover:text-red-400 transition-colors">
                <div class="w-16 h-16 rounded-full bg-red-600 hover:bg-red-700 flex items-center justify-center">
                    <i data-lucide="phone-off" class="w-8 h-8 text-white"></i>
                </div>
                <span class="mt-2 text-sm">Reddet</span>
            </button>
            
            <button id="accept-call-btn" class="flex flex-col items-center text-green-500 hover:text-green-400 transition-colors">
                 <div class="w-16 h-16 rounded-full bg-green-600 hover:bg-green-700 flex items-center justify-center">
                    <i data-lucide="phone" class="w-8 h-8 text-white"></i>
                </div>
                <span class="mt-2 text-sm">Kabul Et</span>
            </button>
        </div>
    </div>
</div>
<div id="leave-group-modal" class="hidden" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.5); display: flex; align-items: center; justify-content: center; z-index: 50;">
    <div style="background-color: #141414ed; padding: 1.5rem; border-radius: 0.5rem; width: 100%; max-width: 28rem;">
        <h2 style="color: white; font-size: 1.125rem; font-weight: 600;"><?php echo $translations['LeaveGroup']; ?></h2>
        <p style="color: #a0aec0; margin-top: 0.5rem;"><?php echo $translations['ConfirmLeaveGroup']; ?></p>
        <div style="margin-top: 1rem; display: flex; justify-content: flex-end; gap: 0.5rem;">
            <button onclick="closeLeaveGroupModal()" style="padding: 0.5rem 1rem; background-color: #4a5568; color: white; border-radius: 0.25rem; cursor: pointer;"><?php echo $translations['Cancelsend']; ?></button>
            <button onclick="confirmLeaveGroup()" style="padding: 0.5rem 1rem; background-color: #e53e3e; color: white; border-radius: 0.25rem; cursor: pointer;"><?php echo $translations['LeaveGroup']; ?></button>
        </div>
    </div>
</div>
<div id="group-settings-modal" class="hidden fixed inset-0 bg-opacity-50 bg-black z-50 flex items-center justify-center modalentryanimation">
    <div class="w-full max-w-md rounded-lg shadow-lg p-6 flex flex-col" style="background-color: #202225;">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-white text-lg font-medium flex items-center">
                <i data-lucide="settings" class="w-5 h-5 mr-2"></i> <?php echo $translations['group_settings']['title'] ?? 'Grup Ayarları'; ?>
            </h2>
            <button onclick="closeGroupSettingsModal()" class="text-gray-400 hover:text-white">
                <i data-lucide="x" class="w-6 h-6"></i>
            </button>
        </div>
        <form id="group-settings-form" class="flex flex-col space-y-4">
            <div>
                <label class="text-white text-sm mb-1"><?php echo $translations['group_settings']['name'] ?? 'Grup Adı'; ?></label>
                <input id="group-name-input" type="text" class="form-input w-full bg-[#2a2a2a] text-white border border-gray-700 rounded p-2" required>
            </div>
            <div>
                <label class="text-white text-sm mb-1"><?php echo $translations['group_settings']['avatar'] ?? 'Grup Avatarı'; ?></label>
                <input id="group-avatar-input" type="file" accept="image/*" class="form-input w-full bg-[#2a2a2a] text-white border border-gray-700 rounded p-2">
            </div>
            <div id="group-settings-message" class="hidden text-sm p-2 rounded"></div>
            <div class="flex justify-between">
                <button id="cancel-group-settings" type="button" class="btn bg-gray-600 text-white"><?php echo $translations['cancel'] ?? 'İptal'; ?></button>
                <button id="submit-group-settings" type="submit" class="btn bg-green-600 text-white flex items-center">
                    <span id="submit-settings-text"><?php echo $translations['save'] ?? 'Kaydet'; ?></span>
                    <i id="submit-settings-spinner" class="hidden animate-spin ml-2" data-lucide="loader-2"></i>
                </button>
            </div>
        </form>
        <button id="delete-group-button" class="btn bg-red-600 text-white mt-4"><?php echo $translations['group_settings']['delete_group'] ?? 'Grubu Sil'; ?></button>
    </div>
</div>
<!-- Transfer Ownership Modal -->
<div id="transfer-ownership-modal" class="hidden" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.5); display: flex; align-items: center; justify-content: center; z-index: 50;">
    <div style="background-color: #141414ed; border-radius: 0.5rem; padding: 1.5rem; width: 100%; max-width: 28rem;">
        <h3 style="font-size: 1.25rem; font-weight: bold; margin-bottom: 1rem;"><?php echo $translations['Transfer-Ownership']; ?></h3>
        <p style="color: #a0aec0; margin-bottom: 1rem;"><?php echo $translations['Select-Ownership']; ?></p>
        <div id="member-list" style="max-height: 15rem; overflow-y: auto;">
            <!-- Members will be dynamically added here -->
        </div>
        <div style="display: flex; justify-content: flex-end; margin-top: 1rem;">
            <button id="cancel-transfer" style="background-color: #4a5568; color: white; padding: 0.5rem 1rem; border-radius: 0.25rem; margin-right: 0.5rem;"><?php echo $translations['Decline']; ?></button>
            <button id="confirm-transfer" style="background-color: #38a169; color: white; padding: 0.5rem 1rem; border-radius: 0.25rem;"><?php echo $translations['Transfer-quit']; ?></button>
        </div>
    </div>
</div>

        <!-- Chat Container -->
        <div id="chat-container" class="hidden absolute inset-y-0 right-0 custom-width bg-gray-700 flex flex-col" style="z-index: 20;">
            <div class="chat-area flex-1 ">
                <div id="chat-header" class="h-12 bg-gray-700 border-b border-gray-900 flex items-center px-4" style="background-color: #161616; border-color: #2A2A2A; border-bottom: 1px solid #515151;">

                    <button id="back-to-friends" class="text-gray-400 hover:text-white mr-4">
                        <i data-lucide="arrow-left" class="w-5 h-5"></i>
                    </button>
                    
        <!-- Sağ Taraf - Ses Kontrolleri -->
    <div class="flex items-center space-x-2">
    </div>     <!-- Kullanıcı adını tıklanabilir bağlantı olarak değiştirin -->
    
                    <a id="chat-username" href="#" class="text-white text-lg font-medium hover:underline"></a>
  <button id="group-members-btn" onclick="showGroupMembers()" class="ml-2 text-gray-400 hover:text-white p-1 rounded hover:bg-gray-600 transition-colors">
            <i data-lucide="users" class="w-4 h-4"></i>
        </button>
        <button id="group-settings-btn" class="text-gray-400 hover:text-white ml-2" onclick="openGroupSettingsModal(currentGroupId)">
    <i data-lucide="settings" class="w-5 h-5"></i>
</button>
    <button id="join-voice-call"  class="text-gray-400 hover:text-white mr-4">
              <i data-lucide="phone" class="w-5 h-5"></i>
            </button>
            <button id="pinned-messages-btn" class="text-gray-400 hover:text-white ml-2">
    <i data-lucide="pin" class="w-6 h-6"></i>
</button>
            <button id="open-search-btn" class="ml-2 text-gray-400 hover:text-white">
        <i data-lucide="search" class="w-5 h-5"></i>
    </button>
   <div id="pinned-messages-modal" class="hidden fixed inset-0 bg-opacity-50 bg-black z-50 flex items-center justify-center modalentryanimation">
    <div class="w-full max-w-3xl rounded-lg shadow-lg flex flex-col max-h-[80vh]" style="background-color: #202225;">
        <div class="flex items-center justify-between p-4 border-b border-gray-800">
            <h2 class="text-white text-lg font-medium flex items-center">
                <i data-lucide="pin" class="w-5 h-5 mr-2"></i> <?php echo $translations['pinned_messages_dm']['pinned_messages']; ?>
                <span id="pinned-count" class="ml-2 bg-indigo-500 text-white text-xs rounded-full px-2 py-1">0</span>
            </h2>
            <button onclick="closePinnedMessagesModal()" class="text-gray-400 hover:text-white">
                <i data-lucide="x" class="w-6 h-6"></i>
            </button>
        </div>
        <div id="pinned-messages-list" class="p-4 overflow-y-auto custom-scrollbar max-h-[60vh]">
            <div class="text-center text-gray-500 mt-8"><?php echo $translations['pinned_messages_dm']['pinned_loading']; ?></div>
        </div>
    </div>
</div>
<!-- Arama paneli -->
    <div id="search-panel" class="hidden absolute inset-0 z-20 flex flex-col" style="background-color: #1E1E1E;">
        <div class="flex-shrink-0 h-16 border-b border-gray-900 flex items-center px-4" style="background-color: #181818; border-bottom: 1px solid #515151;">
            <i data-lucide="search" class="w-5 h-5 text-gray-400 mr-3"></i>
            <input type="text" id="search-input" placeholder="<?php echo $translations['search_bar']['search_messages']; ?>" class="w-full bg-transparent text-white placeholder-gray-500 focus:outline-none">
            <button id="close-search-btn" class="text-gray-400 hover:text-white ml-3 p-2 rounded-full hover:bg-gray-700 transition-colors">
                <i data-lucide="x" class="w-5 h-5"></i>
            </button>
        </div>
        <div id="search-results-container" class="flex-1 overflow-y-auto p-4 custom-scrollbar">
            <div class="text-center text-gray-500 mt-8"><?php echo $translations['search_bar']['type_for_search']; ?></div>
        </div>
    </div>
                </div>
             

<div id="voice-call-modal" class="modal hidden w-full h-auto bg-[#161616] flex items-center justify-center text-white transition-all duration-300 transform opacity-0 scale-95">
    <div class="bg-[#161616] rounded-2xl p-4 max-w-4xl w-full flex flex-col items-center shadow-2xl relative">
        <!-- Profiller ve ekran paylaşımı için kapsayıcı -->
        <div class="relative w-full">
            <!-- Profiller -->
            <div id="profiles-container" class="flex items-center justify-center space-x-4 mb-2">
                <div class="flex flex-col items-center relative">
                    <?php
                    // Kullanıcının avatar URL'sini veritabanından çekin
                    $caller_avatar_query = $db->prepare("SELECT avatar_url FROM user_profiles WHERE user_id = ?");
                    $caller_avatar_query->execute([$_SESSION['user_id']]);
                    $caller_avatar_result = $caller_avatar_query->fetch();
                    $caller_avatar_url = $caller_avatar_result['avatar_url'] ?? 'avatars/default-avatar.png'; // Varsayılan avatar yolu
                    ?>
                    <img id="caller-avatar" src="<?php echo htmlspecialchars($caller_avatar_url); ?>" alt="Sizin Avatarınız" class="w-16 h-16 rounded-full border-2 border-gray-600 object-cover">
                    <span id="caller-username" class="mt-1 font-semibold text-sm"><?php echo htmlspecialchars($_SESSION['username']); ?></span>
                    <!-- Susturulmuş ikon (kendi tarafınız için) -->
                    <div id="caller-mute-icon" class="absolute bottom-0 right-0 bg-red-600 rounded-full p-1 hidden">
                        <i data-lucide="mic-off" class="w-4 h-4 text-white"></i>
                    </div>
                </div>

                <i data-lucide="arrow-right-left" class="w-6 h-6 text-gray-400"></i>

                <div class="flex flex-col items-center relative">
                    <img id="receiver-avatar" src="" alt="Aranan Kişinin Avatarı" class="w-16 h-16 rounded-full border-2 border-gray-600 object-cover">
                    <span id="chat-username2" class="mt-1 font-semibold text-sm"></span>
                    <!-- Susturulmuş ikon (karşı taraf için) -->
                    <div id="receiver-mute-icon" class="absolute bottom-0 right-0 bg-red-600 rounded-full p-1 hidden">
                        <i data-lucide="mic-off" class="w-4 h-4 text-white"></i>
                    </div>
                </div>
            </div>

            <!-- Ekran paylaşım container'ı -->
            <div id="screen-share-container" class="absolute bg-[#161616] top-0 left-0 w-full hidden">
                <div class="flex justify-center gap-4">
                    <!-- Yayıncının ekranı -->
                    <div class="relative flex-1">
                        <video id="local-screen" class="w-full max-w-[300px] rounded-lg" autoplay muted></video>
                        <span class="absolute bottom-2 left-2 text-white bg-black bg-opacity-50 px-2 py-1 rounded text-xs">Sen</span>
                    </div>
                    <!-- Karşı tarafın ekranı -->
                    <div class="relative flex-1">
                        <video id="remote-screen" class="w-full max-w-[300px] rounded-lg" autoplay></video>
                        <span class="absolute bottom-2 left-2 text-white bg-black bg-opacity-50 px-2 py-1 rounded text-xs">Karşı Taraf</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="text-center my-4">
            <p id="call-status" class="text-lg font-light text-gray-300">Bağlantı kuruluyor...</p>
            <div class="flex justify-center items-center space-x-1 mt-1">
                <span class="call-connecting-dot"></span>
                <span class="call-connecting-dot" style="animation-delay: 0.2s;"></span>
                <span class="call-connecting-dot" style="animation-delay: 0.4s;"></span>
            </div>
        </div>

        <div id="screen-share-status" class="text-gray-400 text-sm mt-1"></div>

        <div class="flex items-center justify-center space-x-3 mt-3 bg-gray-900/50 p-2 rounded-full">
            <button id="toggle-screen-share" class="call-control-button bg-gray-700 hover:bg-blue-600" data-tooltip="Ekran Paylaş">
                <i data-lucide="monitor-up" class="w-5 h-5"></i>
            </button>
            <button id="stop-screen-share" class="bg-red-500 text-white p-1 rounded text-sm" style="display: none;">Ekran Paylaşımını Durdur</button>
            <button id="toggle-mic" class="call-control-button bg-gray-700 hover:bg-gray-600" data-tooltip="Mikrofonu Kapat">
    <i data-lucide="mic" class="w-5 h-5"></i>
</button>
            <button id="leave-call" class="call-control-button bg-red-600 hover:bg-red-700" data-tooltip="Aramayı Sonlandır">
                <i data-lucide="phone-off" class="w-5 h-5"></i>
            </button>
        </div>
    </div>
</div>

                <div id="message-container" class="flex-1 overflow-y-auto" style="background-color: #1E1E1E; padding: 4px;">
                    <!-- Messages will be loaded here -->
                </div>
                <div id="file-upload-progress" class="hidden mt-2">
    <div class="text-sm text-gray-300 mb-1">Dosya yükleniyor...</div>
    <div class="w-full bg-gray-600 rounded-full h-2.5">
        <div id="progress-bar" class="bg-green-500 h-2.5 rounded-full" style="width: 0%"></div>
    </div>
    <div id="progress-percentage" class="text-sm text-gray-300 mt-1">0%</div>
</div>
              <div id="typing-indicator" style="display: none;">
</div>




<!-- Grup Üyeleri Modalı -->
<div id="group-members-modal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-[9999]">
    <div class="absolute right-0 h-full w-80 bg-[#2d2f34] transform transition-transform duration-300 translate-x-full">
        <div class="p-4 border-b border-gray-700 flex items-center justify-between">
            <h3 class="text-white text-lg font-semibold"><?php echo $translations['groups']['group_member']; ?></h3>
            <div class="flex items-center space-x-2">
                <!-- Üye Ekle Butonu -->
                <button onclick="openAddMemberModal()" class="text-green-400 hover:text-green-300 p-1.5 rounded-md hover:bg-green-500/20 transition-all">
                    <i data-lucide="user-plus" class="w-5 h-5"></i>
                </button>
                <button onclick="closeGroupMembers()" class="text-gray-400 hover:text-white">
                    <i data-lucide="x" class="w-6 h-6"></i>
                </button>
            </div>
        </div>
        <div id="group-members-list" class="p-4 overflow-y-auto h-[calc(100vh-60px)] custom-scrollbar">
            <!-- Üyeler buraya yüklenecek -->
        </div>
    </div>
</div>
<div id="mini-profile-modal" class="fixed inset-0 bg-black bg-opacity-50 backdrop-blur-sm hidden flex items-center justify-center z-50">
    <div class="bg-[#1a1a1a] rounded-lg p-6 w-full max-w-sm shadow-lg border border-gray-700">
        <!-- Modal Başlığı -->
        <div class="flex justify-between items-center mb-4">
            <h3 id="mini-profile-title" class="text-lg font-semibold text-white"></h3>
            <button id="close-mini-profile" class="text-gray-400 hover:text-white">
                <i data-lucide="x" class="w-5 h-5"></i>
            </button>
        </div>
        <!-- Profil/Grup Bilgileri -->
        <div id="mini-profile-content" class="text-center">
            <div id="mini-profile-avatar-container" class="relative w-20 h-20 mx-auto mb-4">
                <img id="mini-profile-avatar" src="avatars/default-avatar.png" class="w-full h-full rounded-full object-cover border-2 border-[#3CB371]">
                <img id="mini-profile-avatar-frame" src="" class="absolute top-0 left-0 w-full h-full object-cover hidden">
                <span id="mini-profile-status" class="status-dot status-offline absolute bottom-0 right-0 hidden"></span>
            </div>
            <h4 id="mini-profile-username" class="text-white text-xl font-semibold"></h4>
            <p id="mini-profile-status-text" class="text-gray-400 text-sm mt-1 hidden"></p>
            <!-- Grup Bilgileri -->
            <div id="group-info-section" class="hidden mt-4">
                <p id="group-member-count" class="text-gray-300 text-sm"></p>
                <p id="group-creator" class="text-gray-300 text-sm mt-1"></p>
                <div id="group-members-list1" class="mt-4 max-h-40 overflow-y-auto custom-scrollbar">
                    <!-- Üyeler dinamik olarak eklenecek -->
                </div>
            </div>
            <div class="mt-4">
                <button id="view-full-profile" class="bg-[#3CB371] text-white px-4 py-2 rounded-lg hover:bg-[#2E8B57] transition-all"></button>
            </div>
        </div>
    </div>
</div>
<div id="create-poll-modal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden">
    <div class="bg-[#2f3136] rounded-xl p-6 w-full max-w-md shadow-lg modalentryanimation">
        <h2 class="text-xl font-semibold text-white mb-4"><?php echo $translations['create_poll'] ?? 'Anket Oluştur'; ?></h2>
        <form id="poll-form">
            <div class="mb-4">
                <label class="block text-gray-300 text-sm mb-2" for="poll-question"><?php echo $translations['poll_question'] ?? 'Anket Sorusu'; ?></label>
                <input id="poll-question" type="text" class="form-input w-full mb-2 text-white border-none rounded-lg p-3 focus:ring-2 focus:ring-[#5865f2]" placeholder="<?php echo $translations['poll_question_placeholder'] ?? 'Sorunuzu buraya yazın...'; ?>" required style="background-color: #40444b">
            </div>
            <div id="poll-options" class="mb-4">
                <label class="block text-gray-300 text-sm mb-2"><?php echo $translations['poll_options'] ?? 'Seçenekler'; ?></label>
                <div class="poll-option-container">
                    <input type="text" class="form-input poll-option w-full mb-2 text-white border-none rounded-lg p-3 focus:ring-2 focus:ring-[#5865f2]" placeholder="<?php echo $translations['option'] ?? 'Seçenek'; ?> 1" required style="background-color: #40444b">
                </div>
                <div class="poll-option-container">
                    <input type="text" class="form-input poll-option w-full mb-2 text-white border-none rounded-lg p-3 focus:ring-2 focus:ring-[#5865f2]" placeholder="<?php echo $translations['option'] ?? 'Seçenek'; ?> 2" required style="background-color: #40444b">
                </div>
            </div>
            <button type="button" id="add-poll-option" class="flex items-center text-[#5865f2] hover:text-[#4752c4] mb-4">
                <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                <?php echo $translations['add_option'] ?? 'Seçenek Ekle'; ?>
            </button>
            <div class="flex justify-end space-x-2">
                <button type="button" id="cancel-poll" class="btn text-white rounded-lg px-4 py-2"><?php echo $translations['cancel'] ?? 'İptal'; ?></button>
                <button type="submit" id="sumbit-poll" class="btn text-white rounded-lg px-4 py-2"><?php echo $translations['create'] ?? 'Oluştur'; ?></button>
            </div>
        </form>
    </div>
</div>
<!-- Üye Ekleme Modalı -->
<div id="add-member-modal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[10000]">
    <div class="bg-[#2d2f34] rounded-lg p-6 w-full max-w-md">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-white"><?php echo $translations['groups']['add_member']; ?></h3>
            <button onclick="closeAddMemberModal()" class="text-gray-400 hover:text-white">
                <i data-lucide="x" class="w-6 h-6"></i>
            </button>
        </div>
        <div class="mb-4">
            <input type="text" id="member-search" placeholder="<?php echo $translations['groups']['search_friend']; ?>" class="w-full bg-gray-700 text-white rounded p-2 focus:outline-none focus:ring-2 focus:ring-green-500" oninput="filterFriendsForAddMember()">
        </div>
        <div id="friend-selection" class="max-h-64 overflow-y-auto custom-scrollbar">
            <?php foreach ($friends as $friend): ?>
                <label class="flex items-center p-2 hover:bg-gray-700 rounded cursor-pointer">
                    <input type="checkbox" value="<?php echo htmlspecialchars($friend['id']); ?>" data-username="<?php echo htmlspecialchars($friend['username']); ?>" data-avatar="<?php echo htmlspecialchars($friend['avatar_url'] ?? ''); ?>" class="mr-2 add-member-checkbox">
                    <div class="w-8 h-8 rounded-full bg-gray-600 flex items-center justify-center overflow-hidden mr-2">
                        <?php if (!empty($friend['avatar_url'])): ?>
                            <img src="<?php echo htmlspecialchars($friend['avatar_url']); ?>" class="w-full h-full object-cover">
                        <?php else: ?>
                            <span class="text-white text-sm"><?php echo strtoupper(substr($friend['username'], 0, 1)); ?></span>
                        <?php endif; ?>
                    </div>
                    <span class="text-white"><?php echo htmlspecialchars($friend['username']); ?></span>
                </label>
            <?php endforeach; ?>
        </div>
        <div class="mt-4">
            <p class="text-gray-400 text-sm"><?php echo $translations['groups']['chosen']; ?> <span id="selected-member-count">0</span></p>
            <div id="selected-members" class="flex flex-wrap gap-2 mt-2"></div>
        </div>
        <div class="flex justify-end space-x-2 mt-4">
            <button onclick="closeAddMemberModal()" class="btn bg-gray-600 hover:bg-gray-700 text-white"><?php echo $translations['Decline']; ?></button>
            <button id="add-members-button" class="btn bg-green-600 hover:bg-green-700 text-white disabled:opacity-50" disabled>
                <span id="add-members-text"><?php echo $translations['Add']; ?></span>
                <i id="add-members-spinner" class="w-4 h-4 hidden animate-spin" data-lucide="loader-circle"></i>
            </button>
        </div>
        <div id="add-member-message" class="hidden p-3 rounded text-sm mt-4"></div>
    </div>
</div>

<div id="file-preview-container" class="hidden absolute right-0 bottom-16 mr-4 bg-gray-800 rounded-lg shadow-xl z-50">
    <button id="cancel-file" class="absolute -top-2 -right-2 bg-red-500 text-white rounded-full w-6 h-6 flex items-center justify-center hover:bg-red-600 transition-all shadow-lg">×</button>
    <div class="p-3">
        <div id="preview-content" class="flex flex-col items-end gap-2"> <!-- Sağa yaslı içerik -->
            <!-- Önizleme buraya eklenecek -->
        </div>
        <span id="file-name" class="text-gray-300 text-xs mt-1 block text-right"></span>
    </div>
</div>
<div id="input-area" class="input-area flex items-start p-3" style="background-color: #161616;">
    <form id="message-form" class="flex items-center w-full gap-0">
        <!-- Emoji Button -->
        <button id="emoji-button" type="button" class="text-gray-400 hover:text-white p-2 hover:bg-gray-700 rounded-l-lg">
            <i data-lucide="smile" class="w-5 h-5"></i>
        </button>
        
        <!-- GIF Button -->
        <button type="button" id="gif-button" class="text-gray-400 hover:text-white p-2 hover:bg-gray-700">
            <i data-lucide="image" class="w-5 h-5"></i>
        </button>
        
        <!-- Poll Button -->
        <button id="poll-button" class="text-gray-400 hover:text-white p-2 hover:bg-gray-700">
            <i data-lucide="bar-chart-2" class="w-5 h-5"></i>
        </button>

        <!-- Message Input -->
        <textarea 
            id="message-input" 
            class="message-input flex-1 bg-[#363636] text-white p-3 focus:outline-none focus:ring-2 focus:ring-indigo-500 resize-none overflow-y-auto" 
            placeholder="<?php echo $translations['TypeMessage']; ?>" 
            maxlength="2000" 
            rows="1"
            style="min-height: 48px; max-height: 180px; height: 48px; white-space: pre-wrap; font-size: 1rem;"
        ></textarea>

        <!-- File Input -->
        <input type="file" id="file-input" multiple class="hidden">
        <label for="file-input" class="text-gray-400 hover:text-white p-2 hover:bg-gray-700">
            <i data-lucide="paperclip" class="w-5 h-5"></i>
        </label>
        
        <!-- Send Button -->
        <button type="submit" class="send-button text-white p-2 bg-[#3CB371] hover:bg-[#2E8B57] disabled:bg-gray-600 disabled:cursor-not-allowed rounded-r-lg">
           <i data-lucide="send" class="w-5 h-5"></i>
        </button>
    </form>
</div>
<div id="gif-modal" class="fixed inset-0 bg-black/70 flex items-center justify-center z-50 transition-opacity duration-300 hidden">
    <div class="bg-[#1a1a1a] rounded-xl p-6 w-full max-w-lg shadow-2xl border border-[#2d2f34] transform transition-all duration-300 scale-95" style="background: #1a1a1a;">
        <div class="flex justify-between items-center mb-6">
            <h3 class="text-xl font-semibold text-[#3CB371] tracking-tight" style="color: #3CB371">GIF Ara</h3>
            <button id="close-gif-modal" class="text-gray-400 hover:text-white transition-colors duration-200" aria-label="GIF Modalını Kapat">
                <i data-lucide="x" class="w-6 h-6"></i>
            </button>
        </div>
        <div class="relative mb-4">
            <input id="gif-search" type="text" class="w-full bg-[#2a2a2a] text-white p-3 rounded-lg border border-[#3a3a3a] focus:border-[#3CB371] focus:ring-2 focus:ring-[#3CB371]/30 outline-none transition-all duration-200" placeholder="GIF ara..." aria-label="GIF Arama" style="background-color: #2a2a2a;">
            <i data-lucide="search" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 w-5 h-5"></i>
        </div>
        <div id="gif-results" class="mt-4 grid grid-cols-2 sm:grid-cols-3 gap-3 max-h-80 overflow-y-auto p-2 rounded-lg bg-[#202225]/50 custom-scrollbar" style="background-color: #202225;"></div>
        <div id="gif-message" class="mt-3 text-center text-sm text-gray-400 hidden"></div>
    </div>
</div>
</div>
    <script>
    let currentFriendId = null;
    let isGroupChat = false;
    let currentGroupId = null;
    let typingIndicator = document.getElementById('typing-indicator');
    const ws = new WebSocket('wss://lakeban.com:8000'); // Tek WebSocket bağlantısı
        $(document).ready(function() {


            // Get necessary DOM elements
            const mainContent = document.querySelector('.flex.flex-1'); // Main content area with friends list
            const chatContainer = document.getElementById('chat-container');
            const backToFriends = document.getElementById('back-to-friends');
            const messageForm = document.getElementById('message-form');
            const inputArea = document.getElementById('input-area');
            const messageInput = document.getElementById('message-input');
            const sendButton = messageForm ? messageForm.querySelector('button[type="submit"]') : null;

            // Function to handle navigation
            function navigateTo(url) {
                window.location.href = url;
            }

            // Add click event listeners to each navigation item
            document.getElementById('communities').addEventListener('click', function() {
                navigateTo('topluluklar');
            });

            document.getElementById('home').addEventListener('click', function() {
                navigateTo('posts');
            });

            document.getElementById('updates').addEventListener('click', function() {
                navigateTo('changelog');
            });
            
            document.getElementById('stats').addEventListener('click', function() {
                navigateTo('stats');
            });

            // Add click event listeners to direct message items
            const directMessageItems = document.querySelectorAll('.flex.items-center.p-2.hover\\:bg-gray-700.rounded.cursor-pointer');
            directMessageItems.forEach(item => {
                item.addEventListener('click', function() {
                    const friendId = this.getAttribute('data-friend-id');
                    const usernameElement = this.querySelector('.text-gray-300');
                    
                    if (friendId && usernameElement) {
                        openChat(friendId, usernameElement.textContent.trim());
                    }
                });
            });
document.addEventListener('paste', async (e) => {
    // Sadece chat input aktifken çalışsın
    if (document.activeElement !== messageInput) return;

    const items = e.clipboardData.items;
    for (const item of items) {
        if (item.type.indexOf('image') !== -1) {
            e.preventDefault();
            
            const blob = item.getAsFile();
            const file = new File([blob], 'pasted-image.png', {
                type: 'image/png',
                lastModified: Date.now()
            });

            // Dosyayı inputa ekle
            const dataTransfer = new DataTransfer();
            dataTransfer.items.add(file);
            fileInput.files = dataTransfer.files;

            // Önizlemeyi göster
            document.getElementById('file-preview-container').classList.remove('hidden');
            const previewContent = document.getElementById('preview-content');
            previewContent.innerHTML = `<img src="${URL.createObjectURL(file)}" class="preview-image">`;

            // Otomatik gönderim için (isteğe bağlı)
            // messageForm.dispatchEvent(new Event('submit'));
        }
    }
});
// Tenor API Key (Kendi API anahtarınızı buraya ekleyin)
const TENOR_API_KEY = 'AIzaSyBXKEN0w8eMMdj-LAHmvYdoHVIADunht5c'; // Tenor'dan aldığınız API anahtarı
let selectedGifUrl = null;

// GIF Modalını Açma
document.getElementById('gif-button').addEventListener('click', () => {
    document.getElementById('gif-modal').classList.remove('hidden');
    document.getElementById('gif-search').focus();
});

// GIF Modalını Kapatma
document.getElementById('close-gif-modal').addEventListener('click', () => {
    document.getElementById('gif-modal').classList.add('hidden');
    document.getElementById('gif-search').value = '';
    document.getElementById('gif-results').innerHTML = '';
    document.getElementById('gif-message').classList.add('hidden');
});

// Modal dışına tıklama ile kapatma
document.getElementById('gif-modal').addEventListener('click', (e) => {
    if (e.target === e.currentTarget) {
        document.getElementById('gif-modal').classList.add('hidden');
        document.getElementById('gif-search').value = '';
        document.getElementById('gif-results').innerHTML = '';
        document.getElementById('gif-message').classList.add('hidden');
    }
});

// GIF Arama (Debounce ile)
let gifSearchTimer;
document.getElementById('gif-search').addEventListener('input', () => {
    clearTimeout(gifSearchTimer);
    gifSearchTimer = setTimeout(() => {
        const query = document.getElementById('gif-search').value.trim();
        if (query.length < 2) {
            document.getElementById('gif-results').innerHTML = '<div class="text-center text-gray-500">En az 2 karakter girin</div>';
            return;
        }
        searchGifs(query);
    }, 300);
});

async function searchGifs(query) {
    const gifResults = document.getElementById('gif-results');
    const gifMessage = document.getElementById('gif-message');
    
    gifResults.innerHTML = '<div class="text-center text-gray-500"><i data-lucide="loader-2" class="animate-spin inline-block"></i> Yükleniyor...</div>';

    try {
        const response = await fetch(`tenor_proxy.php?query=${encodeURIComponent(query)}&limit=20`);
        const data = await response.json();

        if (data.results && data.results.length > 0) {
            gifResults.innerHTML = '';
            data.results.forEach(gif => {
                const img = document.createElement('img');
                img.src = gif.media_formats.gif.url; // GIF önizleme URL'si
                img.alt = gif.title || 'GIF';
                img.classList.add('gif-result');
                img.addEventListener('click', () => {
                    selectedGifUrl = gif.media_formats.gif.url;
                    sendGifMessage();
                });
                gifResults.appendChild(img);
            });
        } else {
            gifResults.innerHTML = '<div class="text-center text-gray-500">GIF bulunamadı</div>';
        }
    } catch (error) {
        console.error('GIF arama hatası:', error);
        gifResults.innerHTML = '';
        gifMessage.textContent = 'GIF yüklenirken hata oluştu';
        gifMessage.classList.remove('hidden');
        gifMessage.classList.add('bg-red-500/10', 'text-red-400');
    }
}

// GIF Mesaj Gönderme
async function sendGifMessage() {
    if (!selectedGifUrl) return;

    const messageInput = document.getElementById('message-input');
    const sendButton = document.querySelector('.send-button');
    const fileInput = document.getElementById('file-input');

    if (isSubmitting) return;
    isSubmitting = true;

    try {
        sendButton.disabled = true;
        messageInput.disabled = true;
        fileInput.disabled = true;

        const formData = new FormData();
        formData.append('action', 'send_message');
        formData.append('message', selectedGifUrl); // GIF URL'sini mesaj olarak gönder
        formData.append('is_gif', 'true'); // GIF olduğunu belirt

        if (isGroupChat) {
            formData.append('group_id', currentGroupId);
        } else {
            formData.append('receiver_id', currentFriendId);
        }

        const response = await fetch('messages.php', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (data.success) {
            // Modal'ı kapat
            document.getElementById('gif-modal').classList.add('hidden');
            document.getElementById('gif-search').value = '';
            document.getElementById('gif-results').innerHTML = '';
            selectedGifUrl = null;

            // Mesajları yükle ve en aşağı kaydır
            await loadMessages();
            const messageContainer = document.getElementById('message-container');
            if (messageContainer) {
                messageContainer.scrollTop = messageContainer.scrollHeight;
            }

            // WebSocket bildirimi
            const wsData = {
                type: isGroupChat ? 'group-message' : 'direct-message',
                senderId: <?php echo $_SESSION['user_id']; ?>,
                message: selectedGifUrl,
                is_gif: true,
                files: []
            };

            if (isGroupChat) {
                wsData.groupId = currentGroupId;
            } else {
                wsData.receiverId = currentFriendId;
            }

            ws.send(JSON.stringify({
                ...wsData,
                type: 'message-sent',
                receiverId: String(currentFriendId)
            }));
        } else {
            throw new Error(data.message || 'GIF gönderilemedi');
        }
    } catch (error) {
        console.error('GIF gönderme hatası:', error);
        showTempNotification(error.message || 'GIF gönderilirken hata oluştu', 'error');
    } finally {
        isSubmitting = false;
        sendButton.disabled = false;
        messageInput.disabled = false;
        fileInput.disabled = false;
    }
}
// Yanıt modunda olduğumuzu belirten değişkenler
let isReplying = false;
let replyToMessageId = null;

// Ana input alanının üzerinde yanıt önizlemesini göstermek için bir div ekleme
const replyPreviewDiv = document.createElement('div');
replyPreviewDiv.className = 'reply-preview hidden p-2 rounded mb-2';
replyPreviewDiv.innerHTML = `
    <div class="flex items-center">
        <i data-lucide="reply" class="w-4 h-4 mr-2"></i>
        <span id="reply-username" class="text-white font-medium"></span>
        <span id="reply-text" class="ml-2 text-gray-300"></span>
        <button id="cancel-reply" class="absolute right-2 text-gray-400 hover:text-white">
            <i data-lucide="x" class="w-6 h-6"></i>
        </button>
    </div>
`;

// Ana input alanının bulunduğu formun üstüne bu div'i ekleyin
inputArea.parentNode.insertBefore(replyPreviewDiv, inputArea);



let isSubmitting = false;

document.getElementById('message-input').addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
        // Enter: Submit the form
        e.preventDefault();
        document.getElementById('message-form').dispatchEvent(new Event('submit'));
    } else if (e.key === 'Enter' && e.shiftKey) {
        // Shift+Enter: Add line break and adjust height
        e.preventDefault();
        const cursorPos = this.selectionStart;
        const textBefore = this.value.substring(0, cursorPos);
        const textAfter = this.value.substring(cursorPos);
        this.value = textBefore + '\n' + textAfter;
        this.selectionStart = this.selectionEnd = cursorPos + 1;
        // Adjust height dynamically
        this.style.height = '36px'; // Reset to minimum
        this.style.height = `${Math.min(this.scrollHeight, 120)}px`; // Grow up to max-height
    }
});

document.getElementById('message-input').addEventListener('input', function() {
    // Adjust height dynamically on input
    this.style.height = '36px'; // Reset to minimum
    this.style.height = `${Math.min(this.scrollHeight, 120)}px`; // Grow up to max-height
});

// Mesaj alındığında veya gönderildiğinde öğeyi en üste taşıyan fonksiyon
function moveToTop(itemId, itemType) {
    const friendsList = document.querySelector('.friends-list');
    const selector = itemType === 'group'
        ? `.group-item[data-group-id="${itemId}"]`
        : `.friend-item1[data-friend-id="${itemId}"]`;
    const item = friendsList.querySelector(selector);
    
    if (item) {
        friendsList.removeChild(item);
        friendsList.prepend(item);
    }
}


document.getElementById('message-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    if (isSubmitting) return;
    isSubmitting = true;
    const sendButton = document.querySelector('.send-button');
    const messageInput = document.getElementById('message-input');
    const fileInput = document.getElementById('file-input');
    const files = Array.from(fileInput.files);
    const progressContainer = document.getElementById('file-upload-progress');
    const progressBar = document.getElementById('progress-bar');
    const progressPercentage = document.getElementById('progress-percentage');
    try {
        // Lock button and inputs
        if (sendButton) sendButton.disabled = true;
        messageInput.disabled = true;
        fileInput.disabled = true;
        // Prepare form data
        const formData = new FormData();
        formData.append('action', 'send_message');
        formData.append('message', messageInput.value);
        if (isGroupChat) {
            formData.append('group_id', currentGroupId);
        } else {
            formData.append('receiver_id', currentFriendId);
        }
        // Add reply_to_message_id if in reply mode
        if (isReplying && replyToMessageId) {
            formData.append('reply_to_message_id', replyToMessageId);
        }
        // Add multiple files
        files.forEach((file, index) => {
            formData.append(`files[]`, file, file.name);
        });
        // Validations
        if (!formData.get('message') && files.length === 0) {
            throw new Error('Lütfen mesaj yazın veya dosya seçin');
        }
        if (files.length > 5) {
            throw new Error('Maksimum 5 dosya yükleyebilirsiniz');
        }
        // Show progress bar if files are being uploaded
        if (files.length > 0) {
            progressContainer.classList.remove('hidden');
            progressBar.style.width = '0%';
            progressPercentage.textContent = '0%';
        }
        // Use XMLHttpRequest for progress tracking
        const xhr = new XMLHttpRequest();
        // Track upload progress
        xhr.upload.addEventListener('progress', (event) => {
            if (event.lengthComputable) {
                const percentComplete = Math.round((event.loaded / event.total) * 100);
                progressBar.style.width = `${percentComplete}%`;
                progressPercentage.textContent = `${percentComplete}%`;
            }
        });
        // Handle response
        xhr.addEventListener('load', () => {
            progressContainer.classList.add('hidden'); // Hide progress bar
            try {
                const data = JSON.parse(xhr.responseText);
                if (!data.success || !data.message) {
                    throw new Error(data.message || 'Mesaj gönderilemedi');
                }
                // Validate message structure
                if (!data.message.id || !data.message.sender_id) {
                    console.error('Geçersiz mesaj verisi:', data.message);
                    throw new Error('Geçersiz mesaj verisi alındı');
                }
                // Create new message element
                const messageContainer = document.getElementById('message-container');
                const lastMessageElement = messageContainer.lastChild;
                const lastMessageData = lastMessageElement?.__messageData;
                // Check if the new message is consecutive
                const isConsecutive = lastMessageData &&
                    data.message.sender_id === lastMessageData.sender_id &&
                    (data.message.timestamp - lastMessageData.timestamp) <= 300;
                // Create and append the new message
                const newMessageElement = createMessageElement(data.message, isConsecutive ? lastMessageData : null);
                newMessageElement.__messageData = data.message;
                messageContainer.appendChild(newMessageElement);
                
                // İlgili öğeyi en üste taşı
                const itemId = isGroupChat ? currentGroupId : currentFriendId;
                const itemType = isGroupChat ? 'group' : 'friend';
                moveToTop(itemId, itemType);

                // WebSocket bildirimleri
                const wsData = {
                    type: isGroupChat ? 'group-message' : 'direct-message',
                    senderId: <?php echo $_SESSION['user_id']; ?>,
                    message: data.message,
                    files: data.files || []
                };
                if (isGroupChat) {
                    wsData.groupId = currentGroupId;
                } else {
                    wsData.receiverId = currentFriendId;
                }
                ws.send(JSON.stringify({
                    ...wsData,
                    type: 'message-sent',
                    receiverId: String(currentFriendId)
                }));
                // Scroll to the bottom
                messageContainer.scrollTop = messageContainer.scrollHeight;
                // Clear inputs and reset state
                messageInput.value = '';
                messageInput.style.height = '36px';
                fileInput.value = '';
                document.getElementById('file-preview-container').classList.add('hidden');
                // Reset reply mode
                if (isReplying) {
                    isReplying = false;
                    replyToMessageId = null;
                    replyPreviewDiv.classList.add('hidden');
                }
                // Update Lucide icons
                lucide.createIcons();
                showTempNotification('Mesaj ve/veya dosya gönderildi!', 'success');
            } catch (error) {
                console.error('Hata:', error);
                showTempNotification(error.message || 'Bir hata oluştu, lütfen tekrar deneyin', 'error');
            } finally {
                // Unlock button and inputs
                isSubmitting = false;
                if (sendButton) sendButton.disabled = false;
                messageInput.disabled = false;
                fileInput.disabled = false;
                messageInput.focus();
            }
        });
        // Handle errors
        xhr.addEventListener('error', () => {
            progressContainer.classList.add('hidden');
            showTempNotification('Dosya yüklenirken bir hata oluştu!', 'error');
            isSubmitting = false;
            if (sendButton) sendButton.disabled = false;
            messageInput.disabled = false;
            fileInput.disabled = false;
            messageInput.focus();
        });
        // Send request
        xhr.open('POST', 'messages.php', true);
        xhr.send(formData);
    } catch (error) {
        console.error('Hata:', error);
        showTempNotification(error.message || 'Bir hata oluştu, lütfen tekrar deneyin', 'error');
        isSubmitting = false;
        if (sendButton) sendButton.disabled = false;
        messageInput.disabled = false;
        fileInput.disabled = false;
        messageInput.focus();
    }
});

// 4. Dosya seçim dinleyicisini güncelleme
document.getElementById('file-input').addEventListener('change', function() {
    const sendButton = document.querySelector('.send-button');
    // 5. Sadece dosya varlığına göre kontrol
    sendButton.disabled = !(this.files.length > 0 || messageInput.value.trim() !== '');
});

        // Mesaj input yönetimi
if (messageInput && sendButton) {
    // Input değişikliklerini dinle
    messageInput.addEventListener('input', function() {
        // Buton durumunu güncelle (hem mesaj hem dosya kontrolü)
        const hasContent = this.value.trim() !== '' || document.getElementById('file-input').files.length > 0;
        
        // Çift durum kontrolü: İçerik varlığı + gönderim durumu
        sendButton.disabled = !hasContent || isSubmitting;
        
        // Textarea yüksekliğini dinamik ayarla
        this.style.height = 'auto';
        this.style.height = `${this.scrollHeight}px`;
    });

    // Klavye olaylarını yönet
    messageInput.addEventListener('keydown', (e) => {
        // Shift+Enter: Yeni satır ekle
        if (e.key === 'Enter' && e.shiftKey) {
            return; // Doğal davranışı koru
        }
        
        // Enter: Mesaj gönder
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault(); // Formun doğal davranışını engelle
            
            // Çift gönderim ve geçersiz durumları engelle
            if (isSubmitting || sendButton.disabled) return;
            
            // Submit event'ını tetikleme optimizasyonu
            const event = new Event('submit', { 
                bubbles: true,
                cancelable: true
            });
            
            // Formu manuel olarak tetikleme yerine direkt submit işlemi
            if (messageForm.dispatchEvent(event)) {
                messageForm.dispatchEvent(new Event('submit'));
            }
        }
    });
}

            // Function to load messages


// Mesajları yükleme fonksiyonu (düzenlenmiş versiyon)
let messagesHistory = []; // Tüm mesajları saklamak için dizi
let previousRenderedMessage = null; // Önceki render edilen mesaj

let currentPage = 1;
let loadingMessages = false;
let messageUpdateInterval = null;

function loadMessages(page = 1, initialLoad = false) {
    if ((!currentFriendId && !currentGroupId) || loadingMessages) return;
    loadingMessages = true;

    const container = document.getElementById('message-container');
    const isNearBottom = container.scrollHeight - container.clientHeight - container.scrollTop < 100;

    const formData = new FormData();
    formData.append('action', 'get_messages');
    formData.append('page', page);
    
    isGroupChat 
        ? formData.append('group_id', currentGroupId)
        : formData.append('friend_id', currentFriendId);

    fetch('messages.php', { method: 'POST', body: formData })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const sortedMessages = data.messages.sort((a, b) => a.timestamp - b.timestamp);
            const fragment = document.createDocumentFragment();
            let previousMessage = null;
            const messageIds = []; // Mesaj ID'lerini toplamak için dizi

            // Mesajları işle ve ID'leri topla
            sortedMessages.forEach(message => {
                const isConsecutive = previousMessage && 
                    message.sender_id === previousMessage.sender_id &&
                    (message.timestamp - previousMessage.timestamp) <= 300; // 300 saniye = 5 dakika

                const element = createMessageElement(message, isConsecutive ? previousMessage : null);
                element.__messageData = message; // Mesaj meta verisini sakla
                
                fragment.appendChild(element);
                messageIds.push(message.id); // Mesaj ID'sini listeye ekle
                previousMessage = message;
            });

            if (page === 1) {
                container.innerHTML = '';
                container.appendChild(fragment);
                if (initialLoad) container.scrollTop = container.scrollHeight;
            } else {
                // Eski mesajlar için ters stackleme kontrolü
                const firstExisting = container.firstChild;
                container.insertBefore(fragment, firstExisting);

                // Null check eklenmiş kısım
                if (firstExisting && firstExisting.__messageData && fragment.lastChild?.__messageData) {
                    const lastNewMessage = fragment.lastChild;
                    const timeDiff = firstExisting.__messageData.timestamp - lastNewMessage.__messageData.timestamp;
                    
                    if (timeDiff <= 300 && 
                        firstExisting.__messageData.sender_id === lastNewMessage.__messageData.sender_id) {
                        firstExisting.classList.add('consecutive-message');
                    }
                }
            }

            // Tüm mesajlar için reaksiyonları toplu olarak yükle
            loadReactions(messageIds);

            currentPage = page + 1;
        }
    })
    .catch(error => console.error('Error:', error))
    .finally(() => {
        loadingMessages = false;
        if (!initialLoad && isNearBottom) {
            container.scrollTop = container.scrollHeight;
        }
        lucide.createIcons();
    });
}

// Scroll event listener güncellemesi
document.getElementById('message-container').addEventListener('scroll', function() {
    if (this.scrollTop < 200 && !loadingMessages) {
        loadMessages(currentPage);
    }
});
const seenMessageIds = new Set();

// Bildirim sesini çalma fonksiyonu
function playNotificationSound(messageFriendId, messageGroupId, messageId) {
    // PHP ile kullanıcının seçtiği ses yolunu ve durumunu al
    const customSound = "<?php 
        $stmt = $db->prepare('SELECT notification_sound FROM users WHERE id = ?');
        $stmt->execute([$_SESSION['user_id']]);
        echo $stmt->fetchColumn() ?? '/bildirim.mp3';
    ?>";
    
    const userStatus = "<?php echo $_SESSION['status'] ?? 'online'; ?>"; // Kullanıcının durumunu al

    // Eğer kullanıcı DND modundaysa, bildirim sesini çalma
    if (userStatus === 'dnd') {
        seenMessageIds.add(messageId); // Mesaj ID'sini kaydet
        return; // Fonksiyondan çık
    }

    // Mesaj daha önce görülmediyse ve koşullara uyuyorsa ses çal
    if (!seenMessageIds.has(messageId) && (
        document.visibilityState === 'hidden' || 
        (messageFriendId && messageFriendId !== currentFriendId) || 
        (messageGroupId && messageGroupId !== currentGroupId)
    )) {
        try {
            const audio = new Audio(customSound);
            audio.play().catch(error => {
                console.error('Özel bildirim sesi çalınamadı:', error);
                const fallbackAudio = new Audio('/bildirim.mp3');
                fallbackAudio.play().catch(fallbackError => {
                    console.error('Varsayılan bildirim sesi çalınamadı:', fallbackError);
                });
            });
            seenMessageIds.add(messageId); // Mesaj ID'sini kaydet
        } catch (error) {
            console.error('Ses çalma hatası:', error);
            const fallbackAudio = new Audio('/bildirim.mp3');
            fallbackAudio.play().catch(fallbackError => {
                console.error('Varsayılan bildirim sesi çalınamadı:', fallbackError);
            });
            seenMessageIds.add(messageId); // Hata durumunda bile ID'yi kaydet
        }
    }
}
let notifiedMessageIds = new Set(); // Track notified message IDs

function checkNotif() {
    // Yeni mesajları kontrol etmek için AJAX isteği
    fetch('check_new_notif.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ user_id: currentUserId })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.new_messages && data.new_messages.length > 0) {
            data.new_messages.forEach(message => {
                // Only process messages that haven't been notified
                if (!notifiedMessageIds.has(message.id)) {
                    // Add to notified messages
                    notifiedMessageIds.add(message.id);

                    // Play notification sound
                    playNotificationSound(
                        message.friend_id || null, // Mesajın geldiği arkadaş ID'si
                        message.group_id || null,  // Mesajın geldiği grup ID'si
                        message.id                // Mesaj ID'si
                    );

                    // Check if running in Electron
                    if (window.electronAPI) {
                        // Send notification to Electron main process
                        window.electronAPI.showNotification(
                            `New Message from ${message.sender_username}`,
                            message.content || 'You have a new message!'
                        ).then(result => {
                            if (result.success) {
                                console.log(`Notification sent for message ID ${message.id}`);
                            } else {
                                console.error('Failed to send notification');
                            }
                        }).catch(error => {
                            console.error('Error sending notification:', error);
                        });
                    } else {
                        // Fallback for web: Show temporary browser notification
                        showTempNotification(`Yeni mesaj: ${message.sender_username}`);
                    }
                }
            });
        }
    })
    .catch(error => {
        console.error('Yeni mesaj kontrolü hatası:', error);
    });
}



// Her 5 saniyede bir kontrol et
setInterval(checkNotif, 5000);

// Clear notified message IDs when changing channels or friends
function clearNotifiedMessages() {
    notifiedMessageIds.clear();
    console.log('Notified message IDs cleared');
}

// Hook into existing channel/friend change functions
document.addEventListener('DOMContentLoaded', () => {
    // Assuming you have a function like changeChannel or changeFriend
    const originalChangeChannel = window.changeChannel || (() => {});
    window.changeChannel = function(...args) {
        clearNotifiedMessages();
        originalChangeChannel.apply(this, args);
    };

    const originalChangeFriend = window.changeFriend || (() => {});
    window.changeFriend = function(...args) {
        clearNotifiedMessages();
        originalChangeFriend.apply(this, args);
    };
});
function checkNewMessages() {
    if ((!currentFriendId && !currentGroupId)) {
        return;
    }

    const formData = new FormData();
    formData.append('action', 'get_messages');
    formData.append('page', 1);

    if (isGroupChat) {
        formData.append('group_id', currentGroupId);
    } else {
        formData.append('friend_id', currentFriendId);
    }

    fetch('messages.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP hatası! Durum: ${response.status}`);
        }
        return response.json();
    })
    .then(data => {
        if (!data.success || !data.messages) {
            console.warn('Mesajlar kontrol edilirken bir hata oluştu:', data.message || 'Bilinmeyen sunucu hatası');
            return;
        }

        const container = document.getElementById('message-container');
        const currentUserId = <?php echo $_SESSION['user_id'] ?? 'null'; ?>;

        const existingMessageElements = Array.from(container.children);
        const domMessageIds = new Set(existingMessageElements.map(el => el.dataset.messageId));
        const lastMessageElement = existingMessageElements[existingMessageElements.length - 1];
        const lastDomMessageId = lastMessageElement ? parseInt(lastMessageElement.dataset.messageId, 10) : 0;

        const serverMessages = data.messages.sort((a, b) => a.id - b.id);
        const serverMessageIds = new Set(serverMessages.map(message => message.id.toString()));

        // *** YENİ DÜZELTME BAŞLANGICI ***
        // Sunucudan gelen son sayfanın "sınırını" (en eski mesaj ID'sini) belirle.
        // Sadece bu sınırdan daha yeni olan mesajlar silme kontrolüne tabi tutulacak.
        const oldestServerMessageId = serverMessages.length > 0 ? serverMessages[0].id : 0;
        // *** YENİ DÜZELTME SONU ***

        let hasNewMessages = false;

        // --- Silinen Mesajları DOM'dan Kaldırma (Geliştirilmiş Kontrol ile) ---
        existingMessageElements.forEach(element => {
            const messageId = parseInt(element.dataset.messageId, 10);
            
            // YALNIZCA, ID'si en son sayfa aralığında olan AMA sunucudan gelmeyen mesajları sil.
            // Bu, eski sayfalardaki mesajlara dokunulmasını engeller.
            if (messageId && messageId >= oldestServerMessageId && !serverMessageIds.has(messageId.toString())) {
                element.remove();
            }
        });

        // --- Yalnızca Gerçekten Yeni Mesajları Ekleme ---
        let lastProcessedElement = lastMessageElement;
        serverMessages.forEach(message => {
            if (message.id > lastDomMessageId) {
                if (!domMessageIds.has(message.id.toString())) {
                    const lastProcessedMessageData = lastProcessedElement ? lastProcessedElement.__messageData : null;
                    const isConsecutive = lastProcessedMessageData &&
                        message.sender_id === lastProcessedMessageData.sender_id &&
                        (message.timestamp - lastProcessedMessageData.timestamp) <= 300;

                    const newElement = createMessageElement(message, isConsecutive ? lastProcessedMessageData : null);
                    newElement.__messageData = message;
                    
                    container.appendChild(newElement);
                    lastProcessedElement = newElement;
                    hasNewMessages = true;

                    if (message.sender_id !== currentUserId && !document.hasFocus()) {
                        playNotificationSound(message.friend_id, message.group_id, message.id);
                    }
                }
            }
        });
        
        if (hasNewMessages) {
            const isNearBottom = container.scrollHeight - container.clientHeight - container.scrollTop < 150;
            if (isNearBottom) {
                container.scrollTop = container.scrollHeight;
            }
            lucide.createIcons();
        }
    })
    .catch(error => {
        console.error('Yeni mesajlar kontrol edilirken kritik bir hata oluştu:', error);
    });
}
function playNotificationSoun(messageFriendId, messageGroupId) {
    // PHP ile kullanıcının seçtiği ses yolunu ve durumunu al
    const customSound = "<?php 
        $stmt = $db->prepare('SELECT notification_sound FROM users WHERE id = ?');
        $stmt->execute([$_SESSION['user_id']]);
        echo $stmt->fetchColumn() ?? '/bildirim.mp3';
    ?>";
    
    const userStatus = "<?php echo $_SESSION['status'] ?? 'online'; ?>"; // Kullanıcının durumunu al

    // Eğer kullanıcı DND modundaysa, bildirim sesini çalma
    if (userStatus === 'dnd') {
        return; // Fonksiyondan çık
    }

    // Mesaj koşullara uyuyorsa ses çal
    if (document.visibilityState === 'hidden' || 
        (messageFriendId && messageFriendId !== currentFriendId) || 
        (messageGroupId && messageGroupId !== currentGroupId)) {
        try {
            const audio = new Audio(customSound);
            audio.play().catch(error => {
                console.error('Özel bildirim sesi çalınamadı:', error);
                const fallbackAudio = new Audio('/bildirim.mp3');
                fallbackAudio.play().catch(fallbackError => {
                    console.error('Varsayılan bildirim sesi çalınamadı:', fallbackError);
                });
            });
        } catch (error) {
            console.error('Ses çalma hatası:', error);
            const fallbackAudio = new Audio('/bildirim.mp3');
            fallbackAudio.play().catch(fallbackError => {
                console.error('Varsayılan bildirim sesi çalınamadı:', fallbackError);
            });
        }
    }
}

function createMessageElement(message, previousMessage = null) {
    const isCurrentUser = message.sender_id == <?php echo $_SESSION['user_id']; ?>;
    const currentUserAvatar = '<?php echo $avatar_url ?? ""; ?>';
    const currentUsername = '<?php echo $display_username ?? $_SESSION['username']; ?>';

    // Stack mesaj kontrolü (5 dakika içinde aynı gönderen)
    const isConsecutive = previousMessage &&
                        message.sender_id === previousMessage.sender_id &&
                        (message.timestamp - previousMessage.timestamp) <= 300;

    // Mesaj container'ı
    const messageContainer = document.createElement('div');
    messageContainer.className = 'message-and-forms-container';
    messageContainer.dataset.messageId = message.id;
    messageContainer.dataset.senderId = message.sender_id;
    messageContainer.dataset.username = message.sender_display_username || message.sender_username || 'Unknown User';

    // Ana mesaj div'i
    const messageDiv = document.createElement('div');
    messageDiv.className = `flex items-start space-x-3 p-1 rounded-lg relative message-container ${isConsecutive ? 'consecutive-message' : ''}`;
    messageDiv.style.backgroundColor = '#1E1E1E';
    messageDiv.dataset.messageId = message.id;

    // Hover efektleri
    messageDiv.addEventListener('mouseenter', () => messageDiv.style.backgroundColor = '#333333');
    messageDiv.addEventListener('mouseleave', () => messageDiv.style.backgroundColor = '#1E1E1E');

const avatarDiv = document.createElement('div');
avatarDiv.className = 'message-avatar w-10 h-10 relative flex items-center justify-center';
avatarDiv.style.overflow = 'visible'; // Prevent clipping

if (!isConsecutive) {
    if (isCurrentUser) {
        message.sender_avatar = currentUserAvatar;
        message.sender_display_username = currentUsername;
    }
    if (message.sender_avatar) {
        // Avatar image
        const avatarImg = document.createElement('img');
        avatarImg.src = message.sender_avatar;
        avatarImg.alt = `${message.sender_display_username || message.sender_username}'s avatar`;
        avatarImg.className = 'w-10 h-10 object-cover rounded-full z-1';
        avatarDiv.appendChild(avatarImg);

        // Avatar frame
        if (message.sender_avatar_frame && !message.sender_avatar_frame.includes('default-frame.png')) {
            const frameImg = document.createElement('img');
            frameImg.src = message.sender_avatar_frame;
            frameImg.alt = `${message.sender_display_username || message.sender_username}'s avatar frame`;
            frameImg.className = 'absolute object-contain z-10 max-w-none overflow-visible';
            frameImg.style.width = '54px'; // Smaller size
            frameImg.style.height = '54px';
            frameImg.style.transform = 'translate(-50%, -50%)';
            frameImg.style.left = '50%';
            frameImg.style.top = '50%';
            avatarDiv.appendChild(frameImg);
        }
    } else {
        // Fallback for no avatar
        const avatarFallback = document.createElement('span');
        avatarFallback.className = 'text-white text-sm font-medium z-1';
        avatarFallback.textContent = (message.sender_display_username || message.sender_username || 'U').charAt(0).toUpperCase();
        avatarDiv.className += ' bg-indigo-500 rounded-full'; // Add blue background and rounded shape
        avatarDiv.appendChild(avatarFallback);

        // Avatar frame for fallback
        if (message.sender_avatar_frame && !message.sender_avatar_frame.includes('default-frame.png')) {
            const frameImg = document.createElement('img');
            frameImg.src = message.sender_avatar_frame;
            frameImg.alt = `${message.sender_display_username || message.sender_username}'s avatar frame`;
            frameImg.className = 'absolute object-contain z-10 max-w-none overflow-visible';
            frameImg.style.width = '54px'; // Smaller size
            frameImg.style.height = '54px';
            frameImg.style.transform = 'translate(-50%, -50%)';
            frameImg.style.left = '50%';
            frameImg.style.top = '50%';
            avatarDiv.appendChild(frameImg);
        }
    }
}
messageDiv.style.overflow = 'visible'; // Prevent clipping by parent
messageDiv.appendChild(avatarDiv);

    // Mesaj içerik container'ı
    const contentDiv = document.createElement('div');
    contentDiv.className = 'flex-1';

    // Kullanıcı adı ve zaman (sadece ilk mesajda göster)
    if (!isConsecutive) {
        const usernameTimestampDiv = document.createElement('div');
        usernameTimestampDiv.className = 'flex items-center';

        const usernameSpan = document.createElement('span');
        usernameSpan.className = 'text-white font-medium username-clickable'; // Added username-clickable class
        usernameSpan.textContent = message.sender_display_username || message.sender_username || 'Unknown User';

        // Add click event to open mini profile
        usernameSpan.addEventListener('click', (e) => {
            e.stopPropagation(); // Prevent triggering other click events
            openMiniProfileModal(message.sender_id, 'user');
        });

        // Accessibility enhancements
        usernameSpan.setAttribute('aria-label', '<?php echo $translations['ViewProfileOf'] ?? 'View profile of'; ?> ' + (message.sender_display_username || message.sender_username));

        const timestampSpan = document.createElement('span');
        timestampSpan.className = 'text-gray-400 text-xs ml-2';
        timestampSpan.textContent = formatDate(message.timestamp);

        usernameTimestampDiv.appendChild(usernameSpan);
        usernameTimestampDiv.appendChild(timestampSpan);
        contentDiv.appendChild(usernameTimestampDiv);
    }

    // Mesaj metni veya anket
    const messageTextDiv = document.createElement('div');
    messageTextDiv.className = `text-gray-300 text-sm ${isConsecutive ? 'mt-0' : 'mt-1'}`;

    // Anket kontrolü
    let isPoll = false;
    let pollData = {};
    function isValidJSON(str) {
        if (typeof str !== 'string' || !str.trim()) return false;
        try {
            JSON.parse(str);
            return true;
        } catch (e) {
            return false;
        }
    }

    if (isValidJSON(message.message_text)) {
        try {
            pollData = JSON.parse(message.message_text);
            if (
                pollData &&
                pollData.type === 'poll' &&
                typeof pollData.question === 'string' &&
                Array.isArray(pollData.options) &&
                Array.isArray(pollData.votes) &&
                pollData.options.length === pollData.votes.length
            ) {
                isPoll = true;
            }
        } catch (e) {
            console.warn('Mesaj JSON parse hatası:', e);
        }
    }

    if (isPoll) {
        const pollContainer = document.createElement('div');
        pollContainer.className = 'poll-container bg-[#2f3136] p-4 rounded-lg mt-2 w-full max-w-md';

        const pollQuestion = document.createElement('h3');
        pollQuestion.className = 'text-white font-semibold mb-3';
        pollQuestion.textContent = pollData.question || 'Anket Sorusu';
        pollContainer.appendChild(pollQuestion);

        // Toplam oy sayısını hesapla
        const totalVotes = pollData.votes.reduce((sum, count) => sum + (count || 0), 0);

        pollData.options.forEach((option, index) => {
            const pollOption = document.createElement('div');
            pollOption.className = 'poll-option flex items-center justify-between p-2 rounded hover:bg-[#36393f] cursor-pointer';
            pollOption.dataset.messageId = message.id;
            pollOption.dataset.optionIndex = index;

            const optionText = document.createElement('span');
            optionText.textContent = option || `Seçenek ${index + 1}`;
            pollOption.appendChild(optionText);

            const voteInfo = document.createElement('div');
            voteInfo.className = 'flex items-center space-x-2';

            // Oy yüzdesi
            const percentage = totalVotes > 0 ? ((pollData.votes[index] || 0) / totalVotes * 100).toFixed(1) : 0;
            const votePercentage = document.createElement('span');
            votePercentage.className = 'text-gray-400 text-sm';
            votePercentage.textContent = `${percentage}%`;
            voteInfo.appendChild(votePercentage);

            // Oy sayısı
            const voteCount = document.createElement('span');
            voteCount.className = 'text-gray-400 text-sm';
            voteCount.textContent = `${pollData.votes[index] || 0} oy`;
            voteInfo.appendChild(voteCount);

            pollOption.appendChild(voteInfo);
            pollContainer.appendChild(pollOption);
        });

        // Anket için oy verme işlevi
        pollContainer.querySelectorAll('.poll-option').forEach(option => {
            option.addEventListener('click', async () => {
                const messageId = option.dataset.messageId;
                const optionIndex = option.dataset.optionIndex;

                const formData = new FormData();
                formData.append('action', 'vote_poll');
                formData.append('message_id', messageId);
                formData.append('option_index', optionIndex);

                try {
                    const response = await fetch('vote_poll.php', {
                        method: 'POST',
                        body: formData
                    });
                    const data = await response.json();

                    if (data.success) {
                        // Anket verilerini güncelle
                        const newPollData = data.poll_data;
                        const newTotalVotes = newPollData.votes.reduce((sum, count) => sum + (count || 0), 0);
                        const options = pollContainer.querySelectorAll('.poll-option');
                        options.forEach((opt, idx) => {
                            const voteInfo = opt.querySelector('div');
                            const percentage = newTotalVotes > 0 ? ((newPollData.votes[idx] || 0) / newTotalVotes * 100).toFixed(1) : 0;
                            voteInfo.querySelector('.text-gray-400:first-child').textContent = `${percentage}%`;
                            voteInfo.querySelector('.text-gray-400:last-child').textContent = `${newPollData.votes[idx] || 0} oy`;
                        });
                    
                    } else {
                      
                    }
                } catch (error) {
                    console.error('Oylama hatası:', error);
                  
                }
            });
        });

        messageTextDiv.appendChild(pollContainer);
    } else {
        // Normal metin mesajı
        messageTextDiv.innerHTML = twemoji.parse(processMessageText(message.message_text));

        // Yanıtlanan mesaj varsa
        if (message.reply_to_message_id) {
            const replyContainer = document.createElement('div');
            replyContainer.className = 'reply-message-container bg-gray-700 p-2 rounded-lg mb-2';

            const replyAvatarDiv = document.createElement('div');
            replyAvatarDiv.className = 'w-8 h-8 rounded-full flex items-center justify-center bg-indigo-500';

            if (message.reply_to_avatar_url) {
                const replyAvatarImg = document.createElement('img');
                replyAvatarImg.src = message.reply_to_avatar_url;
                replyAvatarImg.className = 'w-full h-full object-cover rounded-full';
                replyAvatarDiv.appendChild(replyAvatarImg);
            } else {
                const replyAvatarFallback = document.createElement('span');
                replyAvatarFallback.className = 'text-white text-sm font-medium';
                replyAvatarFallback.textContent = (message.reply_to_username || 'U').charAt(0).toUpperCase();
                replyAvatarDiv.appendChild(replyAvatarFallback);
            }

            const replyContentDiv = document.createElement('div');
            replyContentDiv.className = 'ml-2';

            const replyUsernameSpan = document.createElement('span');
            replyUsernameSpan.className = 'text-white text-sm font-medium';
            replyUsernameSpan.textContent = message.reply_to_display_username || message.reply_to_username || 'Unknown User';
            replyContentDiv.appendChild(replyUsernameSpan);

            if (message.reply_to_message_text) {
                const replyMessageText = document.createElement('div');
                replyMessageText.className = 'text-gray-300 text-sm mt-1';
                replyMessageText.innerHTML = twemoji.parse(message.reply_to_message_text);
                replyContentDiv.appendChild(replyMessageText);
            }

            if (message.reply_to_file_url) {
                const attachmentsDiv = document.createElement('div');
                attachmentsDiv.className = 'flex flex-wrap mt-1';
                const attachmentUrl = message.reply_to_file_url;
                const fileType = inferFileType(attachmentUrl);

                if (fileType === 'image') {
                    const img = document.createElement('img');
                    img.src = attachmentUrl;
                    img.className = 'w-12 h-12 object-cover rounded-md mr-2 mb-2';
                    attachmentsDiv.appendChild(img);
                } else if (fileType === 'video') {
                    const icon = document.createElement('i');
                    icon.setAttribute('data-lucide', 'video');
                    icon.className = 'w-4 h-4 text-gray-400 mr-1';
                    attachmentsDiv.appendChild(icon);
                } else {
                    const icon = document.createElement('i');
                    icon.setAttribute('data-lucide', 'file');
                    icon.className = 'w-4 h-4 text-gray-400 mr-1';
                    attachmentsDiv.appendChild(icon);
                }
                replyContentDiv.appendChild(attachmentsDiv);
            }

            if (!message.reply_to_message_text && !message.reply_to_file_url) {
                const replyMessageText = document.createElement('div');
                replyMessageText.className = 'text-gray-300 text-sm mt-1 italic';
                replyMessageText.textContent = 'No message or file available';
                replyContentDiv.appendChild(replyMessageText);
                console.log(`Reply message ID ${message.reply_to_message_id} has no text or file:`, {
                    reply_to_message_text: message.reply_to_message_text,
                    reply_to_file_url: message.reply_to_file_url
                });
            }

            replyContainer.appendChild(replyAvatarDiv);
            replyContainer.appendChild(replyContentDiv);
            messageTextDiv.prepend(replyContainer);
        }

        // Dosya ekleri
        let files = [];
        if (message.file_url) {
            try {
                files = JSON.parse(message.file_url) || [];
                files = files.filter(file =>
                    file && typeof file === 'object' &&
                    typeof file.url === 'string' &&
                    typeof file.name === 'string' &&
                    typeof file.type === 'string'
                );
            } catch (e) {
                console.error('Dosya URL’si parse edilemedi:', message.file_url, e);
                files = [];
            }
        }

        const imageExtensions = ['jpg', 'jpeg', 'png', 'jfif', 'gif', 'webp'];
        const videoExtensions = ['mp4', 'webm', 'ogg', 'mov', 'avi'];
        const audioExtensions = ['mp3', 'wav', 'ogg', 'm4a'];

        const images = files.filter(file => imageExtensions.includes(file.name.split('.').pop()?.toLowerCase() || ''));

        if (images.length > 0) {
            const galleryDiv = document.createElement('div');
            galleryDiv.className = 'image-gallery flex flex-wrap gap-2 mt-2';
            images.forEach(image => {
                const img = document.createElement('img');
                img.src = image.url;
                img.alt = 'Uploaded image';
                img.className = 'max-w-[400px] h-auto rounded-lg cursor-pointer';
                img.style.maxHeight = '400px';
                img.crossOrigin = 'anonymous';
                img.addEventListener('click', () => {
                    const div = document.createElement('div');
                    div.className = 'absolute fixed top-0 left-0 w-full h-full bg-black bg-opacity-80 flex justify-center items-center';
                    div.style.zIndex = '9999';
                    const divImg = document.createElement('img');
                    divImg.src = image.url;
                    divImg.alt = 'Full View';
                    divImg.className = 'max-w-full max-h-full rounded-lg';
                    div.addEventListener('click', () => div.remove());
                    div.appendChild(divImg);
                    document.body.appendChild(div);
                });
                galleryDiv.appendChild(img);
            });
            messageTextDiv.appendChild(galleryDiv);
        }

        files.forEach(file => {
            const fileExtension = file.name.split('.').pop()?.toLowerCase() || '';
            const fileName = file.name || `file.${fileExtension}`;
            const fileType = file.type || '';

            if (videoExtensions.includes(fileExtension)) {
                const videoContainer = document.createElement('div');
                videoContainer.className = 'video-player-container relative bg-[#161616] rounded-lg overflow-hidden shadow-lg';
                videoContainer.style.maxWidth = '400px';
                videoContainer.style.width = '100%';
                videoContainer.style.height = 'auto';
                videoContainer.style.backgroundSize = 'cover';
                videoContainer.style.backgroundPosition = 'center';

                const thumbnail = document.createElement('div');
                thumbnail.className = 'thumbnail w-full h-auto bg-gray-800 rounded-lg relative';
                thumbnail.style.backgroundImage = 'url(/video_placeholder.png)';
                thumbnail.style.backgroundSize = 'cover';
                thumbnail.style.backgroundPosition = 'center';
                thumbnail.style.aspectRatio = '16/9';

                const loader = document.createElement('div');
                loader.className = 'absolute inset-0 flex items-center justify-center';
                loader.innerHTML = `<i data-lucide="loader-2" class="w-8 h-8 text-white animate-spin"></i>`;

                const video = document.createElement('video');
                video.className = 'w-full h-auto rounded-lg hidden';
                video.style.objectFit = 'contain';
                video.preload = 'metadata';
                video.innerHTML = `<source src="${file.url}" type="${fileType}">`;
                video.crossOrigin = 'anonymous';

                const controls = document.createElement('div');
                controls.className = 'video-controls absolute bottom-0 left-0 right-0 bg-black bg-opacity-70 p-2 flex items-center space-x-3 transition-opacity duration-200';
                controls.style.opacity = '0';
                controls.style.transform = 'translateY(100%)';

                videoContainer.addEventListener('mouseenter', () => {
                    if (!thumbnail.classList.contains('hidden')) return;
                    controls.style.opacity = '1';
                    controls.style.transform = 'translateY(0)';
                });
                videoContainer.addEventListener('mouseleave', () => {
                    if (!video.paused) {
                        controls.style.opacity = '0';
                        controls.style.transform = 'translateY(100%)';
                    }
                });

                const playPauseBtn = document.createElement('button');
                playPauseBtn.className = 'play-pause-btn bg-[#3CB371] hover:bg-[#2E8B57] w-8 h-8 rounded-full flex items-center justify-center transition-colors duration-200';
                playPauseBtn.innerHTML = `<i data-lucide="play" class="w-4 h-4 text-white"></i>`;
                playPauseBtn.setAttribute('data-playing', 'false');

                const progressContainer = document.createElement('div');
                progressContainer.className = 'flex-1 flex items-center';
                const progressBar = document.createElement('div');
                progressBar.className = 'bg-gray-600 h-1 rounded-full w-full relative cursor-pointer';
                const progress = document.createElement('div');
                progress.className = 'bg-[#3CB371] h-full rounded-full';
                progress.style.width = '0%';
                progressBar.appendChild(progress);

                const timeDisplay = document.createElement('div');
                timeDisplay.className = 'flex justify-between text-xs text-gray-300 w-24';
                const currentTime = document.createElement('span');
                currentTime.textContent = '0:00';
                const duration = document.createElement('span');
                duration.textContent = '0:00';
                timeDisplay.appendChild(currentTime);
                timeDisplay.appendChild(duration);

                const volumeControl = document.createElement('div');
                volumeControl.className = 'flex items-center space-x-1';
                const volumeIcon = document.createElement('i');
                volumeIcon.setAttribute('data-lucide', 'volume-2');
                volumeIcon.className = 'w-4 h-4 text-gray-300';
                const volumeSlider = document.createElement('input');
                volumeSlider.type = 'range';
                volumeSlider.min = '0';
                volumeSlider.max = '1';
                volumeSlider.step = '0.01';
                volumeSlider.value = '1';
                volumeSlider.className = 'w-12 h-1 bg-gray-600 rounded-full';
                volumeControl.appendChild(volumeIcon);
                volumeControl.appendChild(volumeSlider);

                const fullscreenBtn = document.createElement('button');
                fullscreenBtn.className = 'fullscreen-btn bg-gray-700 hover:bg-gray-600 w-8 h-8 rounded-full flex items-center justify-center transition-colors duration-200';
                fullscreenBtn.innerHTML = `<i data-lucide="maximize" class="w-4 h-4 text-white"></i>`;

                controls.appendChild(playPauseBtn);
                controls.appendChild(progressContainer);
                progressContainer.appendChild(progressBar);
                controls.appendChild(timeDisplay);
                controls.appendChild(volumeControl);
                controls.appendChild(fullscreenBtn);

                const playButton = document.createElement('div');
                playButton.className = 'play-button absolute inset-0 flex items-center justify-center opacity-90 hover:opacity-100 transition-opacity cursor-pointer';
                playButton.innerHTML = `
                    <div class="w-12 h-12 bg-black/50 rounded-full flex items-center justify-center">
                        <i data-lucide="play" class="w-6 h-6 text-white"></i>
                    </div>
                `;

                videoContainer.appendChild(thumbnail);
                videoContainer.appendChild(loader);
                videoContainer.appendChild(video);
                videoContainer.appendChild(controls);
                videoContainer.appendChild(playButton);
                messageTextDiv.appendChild(videoContainer);

                video.addEventListener('loadedmetadata', async () => {
                    try {
                        if (video.seekable.length === 0) {
                            throw new Error('Video is not seekable');
                        }
                        const seekTimes = [0.1, 1.0, 2.0];
                        let captured = false;
                        let thumbnailDataUrl = null;
                        for (const seekTime of seekTimes) {
                            if (captured) break;
                            try {
                                video.currentTime = seekTime;
                                await new Promise((resolve, reject) => {
                                    const timeout = setTimeout(() => reject(new Error('Seek timeout')), 2000);
                                    video.addEventListener('seeked', () => {
                                        clearTimeout(timeout);
                                        resolve();
                                    }, { once: true });
                                    video.addEventListener('error', () => {
                                        clearTimeout(timeout);
                                        reject(new Error('Seek error'));
                                    }, { once: true });
                                });
                                const canvas = document.createElement('canvas');
                                canvas.width = video.videoWidth;
                                canvas.height = video.videoHeight;
                                const ctx = canvas.getContext('2d');
                                ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
                                thumbnailDataUrl = canvas.toDataURL('image/jpeg', 0.8);
                                const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height).data;
                                const isBlank = Array.from(imageData).every((val, i) => i % 4 === 3 || val === 0);
                                if (!isBlank) {
                                    captured = true;
                                    thumbnail.style.backgroundImage = `url(${thumbnailDataUrl})`;
                                    thumbnail.style.aspectRatio = `${video.videoWidth}/${video.videoHeight}`;
                                    const blurCanvas = document.createElement('canvas');
                                    blurCanvas.width = video.videoWidth / 4;
                                    blurCanvas.height = video.videoHeight / 4;
                                    const blurCtx = blurCanvas.getContext('2d');
                                    blurCtx.drawImage(video, 0, 0, blurCanvas.width, blurCanvas.height);
                                    blurCtx.filter = 'blur(10px)';
                                    blurCtx.drawImage(blurCanvas, 0, 0);
                                    videoContainer.style.backgroundImage = `url(${blurCanvas.toDataURL('image/jpeg', 0.8)})`;
                                }
                            } catch (err) {
                                console.warn(`Failed to capture thumbnail at ${seekTime}s:`, err);
                                continue;
                            }
                        }
                        if (!captured) {
                            throw new Error('Could not capture a valid thumbnail at any seek time');
                        }
                        loader.remove();
                        lucide.createIcons();
                    } catch (error) {
                        console.error('Thumbnail generation failed:', error);
                        loader.remove();
                        thumbnail.style.backgroundImage = 'url(/video_placeholder.png)';
                        thumbnail.innerHTML = `<div class="text-red-400 p-2">Video önizlemesi oluşturulamadı.</div>`;
                        lucide.createIcons();
                    }
                });

                video.addEventListener('error', (err) => {
                    console.error('Video loading error:', err);
                    loader.remove();
                    thumbnail.style.backgroundImage = 'url(/video_placeholder.png)';
                    thumbnail.innerHTML = `<div class="text-red-400 p-2">Video yüklenemedi.</div>`;
                    lucide.createIcons();
                });

                playPauseBtn.addEventListener('click', togglePlayPause);
                playButton.addEventListener('click', togglePlayPause);

                function togglePlayPause() {
                    if (video.paused) {
                        video.className = 'w-full h-auto rounded-lg';
                        thumbnail.className = 'thumbnail hidden';
                        playButton.className = 'hidden';
                        video.play();
                        playPauseBtn.setAttribute('data-playing', 'true');
                        playPauseBtn.innerHTML = `<i data-lucide="pause" class="w-4 h-4 text-white"></i>`;
                        videoContainer.classList.remove('paused');
                        videoContainer.classList.add('playing');
                        controls.style.opacity = '1';
                        controls.style.transform = 'translateY(0)';
                    } else {
                        video.pause();
                        playPauseBtn.setAttribute('data-playing', 'false');
                        playPauseBtn.innerHTML = `<i data-lucide="play" class="w-4 h-4 text-white"></i>`;
                        videoContainer.classList.remove('playing');
                        videoContainer.classList.add('paused');
                        controls.style.opacity = '1';
                        controls.style.transform = 'translateY(0)';
                    }
                    lucide.createIcons();
                }

                video.addEventListener('timeupdate', () => {
                    const progressPercent = (video.currentTime / video.duration) * 100;
                    progress.style.width = `${progressPercent}%`;
                    currentTime.textContent = formatTime(video.currentTime);
                    duration.textContent = formatTime(video.duration);
                });

                progressBar.addEventListener('click', (e) => {
                    const rect = progressBar.getBoundingClientRect();
                    const pos = (e.clientX - rect.left) / rect.width;
                    video.currentTime = pos * video.duration;
                });

                volumeSlider.addEventListener('input', () => {
                    video.volume = volumeSlider.value;
                    volumeIcon.setAttribute('data-lucide', video.volume === 0 ? 'volume-x' : 'volume-2');
                    lucide.createIcons();
                });

                fullscreenBtn.addEventListener('click', () => {
                    if (!document.fullscreenElement) {
                        videoContainer.requestFullscreen().then(() => {
                            fullscreenBtn.innerHTML = `<i data-lucide="minimize" class="w-4 h-4 text-white"></i>`;
                            video.style.width = '100%';
                            video.style.height = '100%';
                            video.style.maxWidth = 'none';
                            video.style.maxHeight = 'none';
                            video.style.objectFit = 'contain';
                            lucide.createIcons();
                        });
                    } else {
                        document.exitFullscreen().then(() => {
                            fullscreenBtn.innerHTML = `<i data-lucide="maximize" class="w-4 h-4 text-white"></i>`;
                            video.style.width = '100%';
                            video.style.height = 'auto';
                            video.style.maxWidth = '400px';
                            video.style.maxHeight = 'none';
                            video.style.objectFit = '';
                            lucide.createIcons();
                        });
                    }
                });

                document.addEventListener('fullscreenchange', () => {
                    if (!document.fullscreenElement) {
                        fullscreenBtn.innerHTML = `<i data-lucide="maximize" class="w-4 h-4 text-white"></i>`;
                        video.style.width = '100%';
                        video.style.height = 'auto';
                        video.style.maxWidth = '400px';
                        video.style.maxHeight = 'none';
                        video.style.objectFit = '';
                        lucide.createIcons();
                    }
                });

                video.addEventListener('ended', () => {
                    playPauseBtn.setAttribute('data-playing', 'false');
                    playPauseBtn.innerHTML = `<i data-lucide="play" class="w-4 h-4 text-white"></i>`;
                    progress.style.width = '0%';
                    currentTime.textContent = '0:00';
                    video.className = 'w-full h-auto rounded-lg hidden';
                    thumbnail.className = 'thumbnail w-full h-auto bg-gray-800 rounded-lg relative';
                    thumbnail.style.aspectRatio = `${video.videoWidth}/${video.videoHeight}`;
                    playButton.className = 'play-button absolute inset-0 flex items-center justify-center opacity-90 hover:opacity-100 transition-opacity cursor-pointer';
                    videoContainer.classList.remove('playing');
                    videoContainer.classList.add('paused');
                    controls.style.opacity = '1';
                    controls.style.transform = 'translateY(0)';
                    lucide.createIcons();
                });
            } else if (audioExtensions.includes(fileExtension)) {
                const audioContainer = document.createElement('div');
                audioContainer.className = 'audio-player-container relative p-3 bg-gray-800 rounded-lg flex items-center space-x-3 w-full max-w-md';
                audioContainer.innerHTML = `
                    <button class="audio-control play-pause-btn bg-[#3CB371] hover:bg-[#2E8B57] w-10 h-10 rounded-full flex items-center justify-center transition-colors duration-200" data-playing="false">
                        <i data-lucide="play" class="w-5 h-5 text-white"></i>
                    </button>
                    <div class="flex-1">
                        <div class="audio-progress-bar bg-gray-600 h-1.5 rounded-full overflow-hidden">
                            <div class="progress bg-[#3CB371] h-full" style="width: 0%"></div>
                        </div>
                        <div class="flex justify-between text-xs text-gray-400 mt-1">
                            <span class="current-time">0:00</span>
                            <span class="duration">0:00</span>
                        </div>
                    </div>
                    <div class="volume-control flex items-center space-x-2">
                        <i data-lucide="volume-2" class="w-5 h-5 text-gray-300"></i>
                        <input type="range" min="0" max="1" step="0.01" value="1" class="volume-slider w-16 h-1 bg-gray-600 rounded-full">
                    </div>
                `;

                const audio = document.createElement('audio');
                audio.src = file.url;
                audio.preload = 'metadata';
                audio.crossOrigin = 'anonymous';

                const playPauseBtn = audioContainer.querySelector('.play-pause-btn');
                const progressBar = audioContainer.querySelector('.progress');
                const currentTime = audioContainer.querySelector('.current-time');
                const duration = audioContainer.querySelector('.duration');
                const volumeSlider = audioContainer.querySelector('.volume-slider');

                audio.addEventListener('loadedmetadata', () => {
                    duration.textContent = formatTime(audio.duration);
                });

                playPauseBtn.addEventListener('click', () => {
                    if (audio.paused) {
                        audio.play();
                        playPauseBtn.setAttribute('data-playing', 'true');
                        playPauseBtn.innerHTML = `<i data-lucide="pause" class="w-5 h-5 text-white"></i>`;
                    } else {
                        audio.pause();
                        playPauseBtn.setAttribute('data-playing', 'false');
                        playPauseBtn.innerHTML = `<i data-lucide="play" class="w-5 h-5 text-white"></i>`;
                    }
                    lucide.createIcons();
                });

                audio.addEventListener('timeupdate', () => {
                    const progressPercent = (audio.currentTime / audio.duration) * 100;
                    progressBar.style.width = `${progressPercent}%`;
                    currentTime.textContent = formatTime(audio.currentTime);
                });

                volumeSlider.addEventListener('input', () => {
                    audio.volume = volumeSlider.value;
                });

                audio.addEventListener('error', () => {
                    audioContainer.innerHTML = `<div class="text-red-400 p-2">Ses dosyası yüklenemedi.</div>`;
                });

                audio.addEventListener('ended', () => {
                    playPauseBtn.setAttribute('data-playing', 'false');
                    playPauseBtn.innerHTML = `<i data-lucide="play" class="w-5 h-5 text-white"></i>`;
                    progressBar.style.width = '0%';
                    currentTime.textContent = '0:00';
                    lucide.createIcons();
                });

                audioContainer.appendChild(audio);
                messageTextDiv.appendChild(audioContainer);
            } else if (!imageExtensions.includes(fileExtension)) {
                const fileLink = document.createElement('a');
                fileLink.href = file.url;
                fileLink.className = 'flex items-center p-3 bg-gray-800 rounded-lg hover:bg-gray-700 transition-colors file-download-link';
                const fileExtensionDisplay = fileExtension.toUpperCase();
                fileLink.innerHTML = `
                    <i data-lucide="file" class="w-6 h-6 mr-3 text-blue-400"></i>
                    <div class="flex flex-col">
                        <span class="text-sm font-medium text-white">${fileName}</span>
                        <span class="text-xs text-gray-400">${fileExtensionDisplay} File</span>
                    </div>
                `;
                fileLink.target = '_blank';
                messageTextDiv.appendChild(fileLink);
            }
        });
    }

    contentDiv.appendChild(messageTextDiv);

    const reactionsDiv = document.createElement('div');
    reactionsDiv.className = 'reactions flex space-x-2 mt-1';
    reactionsDiv.dataset.messageId = message.id;
    contentDiv.appendChild(reactionsDiv);

    messageDiv.appendChild(contentDiv);

    const moreOptionsButton = document.createElement('button');
    moreOptionsButton.className = 'more-options-button text-gray-400 hover:text-white p-1 rounded hover:bg-gray-700 ml-auto hidden';
    moreOptionsButton.innerHTML = '<i data-lucide="more-vertical" class="w-5 h-5"></i>';

    const optionsMenu = document.createElement('div');
    optionsMenu.className = 'options-menu absolute right-0 mt-2 w-48 bg-gray-800 rounded-md shadow-lg hidden';
    optionsMenu.innerHTML = `
        <button class="flex items-center w-full text-left px-4 py-2 text-sm text-gray-400 hover:bg-gray-700 hover:text-white" data-action="reply-message">
            <i data-lucide="reply" class="w-4 h-4 mr-2"></i> <?php echo $translations['Reply']; ?>
        </button>
        <button class="flex items-center w-full text-left px-4 py-2 text-sm text-gray-400 hover:bg-gray-700 hover:text-white" data-action="add-reaction">
            <i data-lucide="smile" class="w-4 h-4 mr-2"></i> Reaksiyon Ekle
        </button>
        <button class="flex items-center w-full text-left px-4 py-2 text-sm text-gray-400 hover:bg-gray-700 hover:text-white" data-action="pin-message" data-message-id="${message.id}">
            <i data-lucide="${message.is_pinned ? 'pin-off' : 'pin'}" class="w-4 h-4 mr-2"></i> ${message.is_pinned ? 'Unpin' : 'Pin'}
        </button>
        ${isCurrentUser ? `
            <button class="flex items-center w-full text-left px-4 py-2 text-sm text-gray-400 hover:bg-gray-700 hover:text-white" data-action="edit-message">
                <i data-lucide="edit" class="w-4 h-4 mr-2"></i> <?php echo $translations['Edit']; ?>
            </button>
            <button class="flex items-center w-full text-left px-4 py-2 text-sm text-gray-400 hover:bg-gray-700 hover:text-white" data-action="delete-message">
                <i data-lucide="trash-2" class="w-4 h-4 mr-2"></i> <?php echo $translations['Delete']; ?>
            </button>
        ` : ''}
    `;

    messageDiv.appendChild(moreOptionsButton);
    messageDiv.appendChild(optionsMenu);

    messageDiv.addEventListener('mouseenter', () => moreOptionsButton.classList.remove('hidden'));
    messageDiv.addEventListener('mouseleave', () => moreOptionsButton.classList.add('hidden'));

    optionsMenu.querySelectorAll('button').forEach(button => {
        button.dataset.messageId = message.id;
    });

    const editForm = isCurrentUser ? createEditForm(message.id, message.message_text) : null;
    messageContainer.appendChild(messageDiv);
    if (editForm) messageContainer.appendChild(editForm);

    // Inside createMessageElement function, update the style block
const style = document.createElement('style');
style.textContent = `
    .consecutive-message {
        margin-top: 2px;
        padding: 4px 8px !important;
        border-radius: 8px !important;
    }
    .consecutive-message .message-avatar {
        display: none !important;
    }
    .message-and-forms-container {
        position: relative;
        margin-bottom: 4px;
    }
    .reactions {
        display: flex;
        gap: 0.5rem;
        margin-top: 0;
    }
    .reaction {
        background-color: rgba(59, 130, 246, 1);
        color: white;
        font-size: 0.875rem;
        padding: 0.25rem 0.5rem;
        border-radius: 9999px;
        cursor: pointer;
        transition: background-color 0.2s;
    }
    .reaction:hover {
        background-color: rgba(55, 65, 81, 1);
    }
    .emoji-picker {
        z-index: 1000 !important;
    }
    .poll-container {
        border: 1px solid #3a3a3a;
    }
    .poll-option {
        transition: background-color 0.2s;
    }
    .poll-option:hover {
        background-color: #36393f;
    }
    /* TWEmoji styles for inline rendering */
    img.emoji {
        display: inline-block; /* Ensure emojis stay inline with text */
        height: 18px; /* Match text size */
        width: 18px;
        margin: 0 0.05em 0 0.1em; /* Minimal margins to align with text */
        vertical-align: -0.1em; /* Align vertically with text baseline */
        line-height: normal; /* Prevent line-height issues */
    }
`;
document.head.appendChild(style);

    lucide.createIcons();
    return messageContainer;
}
// Add global styles for clickable usernames (append this at the end of your script)
const globalStyle = document.createElement('style');
globalStyle.textContent = `
    .username-clickable {
        cursor: pointer;
    }
    .username-clickable:hover {
        text-decoration: underline;
        color: white;
    }
`;
document.head.appendChild(globalStyle);
// Dosya tipini belirlemek için yardımcı fonksiyon
function inferFileType(url) {
    const extension = url.split('.').pop().toLowerCase();
    const imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
    const videoExtensions = ['mp4', 'webm', 'ogg', 'mov'];
    if (imageExtensions.includes(extension)) return 'image';
    if (videoExtensions.includes(extension)) return 'video';
    return 'file';
}

// Helper function: Format time
function formatTime(seconds) {
    const minutes = Math.floor(seconds / 60);
    const secs = Math.floor(seconds % 60);
    return `${minutes}:${secs < 10 ? '0' : ''}${secs}`;
}
async function loadReactions(messageIds) {
    try {
        // Mesaj ID'lerini virgülle birleştir
        const messageIdsString = Array.isArray(messageIds) ? messageIds.join(',') : messageIds;
        const response = await fetch(`get_reactions.php?message_ids=${messageIdsString}`);
        const reactionsData = await response.json();

        if (reactionsData.error) {
            console.error(`Reaksiyonlar yüklenirken hata: ${reactionsData.error}`);
            return;
        }

        // Her mesaj ID'si için reaksiyonları işle
        Object.keys(reactionsData).forEach(messageId => {
            const reactionContainer = document.querySelector(`.reactions[data-message-id="${messageId}"]`);
            if (!reactionContainer) {
                console.warn(`Reaksiyon konteyneri bulunamadı: messageId=${messageId}`);
                return;
            }
            reactionContainer.innerHTML = ''; // Önceki reaksiyonları temizle

            const reactions = reactionsData[messageId];
            if (reactions.length === 0) {
                // Reaksiyon yoksa konteyneri gizle (isteğe bağlı)
                reactionContainer.style.display = 'none';
                return;
            }

            reactionContainer.style.display = 'flex'; // Konteyneri göster
            reactions.forEach(reaction => {
                const reactionElement = document.createElement('span');
                reactionElement.className = 'reaction inline-flex items-center bg-gray-700 text-white text-sm px-2 py-1 rounded cursor-pointer';
                reactionElement.innerHTML = `${reaction.emoji} ${reaction.count}`;
                reactionElement.dataset.emoji = reaction.emoji;
                reactionElement.dataset.messageId = messageId;
                reactionElement.addEventListener('click', () => toggleReaction(messageId, reaction.emoji));
                reactionContainer.appendChild(reactionElement);
            });
        });
    } catch (error) {
        console.error(`Reaksiyonlar yüklenirken hata:`, error);
    }
}

async function toggleReaction(messageId, emoji) {
    try {
        const formData = new FormData();
        formData.append('message_id', messageId);
        formData.append('emoji', emoji);
        
        const response = await fetch('add_reaction.php', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();
        
        if (result.success) {
            await loadReactions(messageId); // Reaksiyonları güncelle
        } else {
            console.error(`Reaksiyon eklenemedi (messageId=${messageId}):`, result.message);
            alert(result.message || 'Reaksiyon eklenemedi.');
        }
    } catch (error) {
        console.error(`Reaksiyon ekleme/kaldırma hatası (messageId=${messageId}):`, error);
        alert('Bir hata oluştu, lütfen tekrar deneyin.');
    }
}

function showEmojiPicker(messageId, buttonElement) {
    // Mevcut emoji picker'ı kapat
    const existingPicker = document.querySelector('.emoji-picker');
    if (existingPicker) existingPicker.remove();

    // Emoji picker container
    const emojiPicker = document.createElement('div');
    emojiPicker.className = 'emoji-picker absolute bg-gray-800 rounded-md shadow-lg p-2 z-10';
    emojiPicker.style.top = `420px`;
    emojiPicker.style.left = `300px`;

    // Emoji-mart picker'ı oluştur
    const picker = new EmojiMart.Picker({
        theme: 'dark',
        skinTonePosition: 'none',
        previewPosition: 'none',
        onEmojiSelect: async (emoji) => {
            await toggleReaction(messageId, emoji.native);
            emojiPicker.remove();
        }
    });

    emojiPicker.appendChild(picker);
    document.body.appendChild(emojiPicker);

    // Dışarı tıklayınca kapat
    const closePicker = (e) => {
        if (!emojiPicker.contains(e.target) && e.target !== buttonElement) {
            emojiPicker.remove();
            document.removeEventListener('click', closePicker);
        }
    };
    document.addEventListener('click', closePicker);
}

document.addEventListener('click', async (e) => {
    const button = e.target.closest('[data-action="add-reaction"]');
    if (button) {
        // En yakın message-container'ı bul
        const messageContainer = button.closest('.message-container');
        if (!messageContainer) {
            console.error('Hata: message-container bulunamadı.');
            return;
        }

        const messageId = messageContainer.dataset.messageId;
        if (!messageId) {
            console.error('Hata: Mesaj ID bulunamadı (message-container.dataset.messageId eksik).', messageContainer);
            return;
        }

        console.log(`Reaksiyon ekle butonuna tıklandı: messageId=${messageId}`);
        showEmojiPicker(messageId, button);
    }
});

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.message-container').forEach(container => {
        const messageId = container.dataset.messageId;
        if (messageId) {
            console.log(`Reaksiyonlar yükleniyor: messageId=${messageId}`);
            loadReactions(messageId);
        } else {
            console.warn('Mesaj ID eksik:', container);
        }
    });
});
// Yardımcı fonksiyonlar
function formatDate(timestamp) {
    const date = new Date(timestamp * 1000);
    const now = new Date();
    
    const timeOptions = { hour: '2-digit', minute: '2-digit' };
    const dateOptions = { year: 'numeric', month: 'short', day: 'numeric' };

    if (date.toDateString() === now.toDateString()) {
        return date.toLocaleTimeString(navigator.language, timeOptions);
    }
    
    const yesterday = new Date(now);
    yesterday.setDate(now.getDate() - 1);
    if (date.toDateString() === yesterday.toDateString()) {
        return `Yesterday ${date.toLocaleTimeString(navigator.language, timeOptions)}`;
    }
    
    return `${date.toLocaleDateString(navigator.language, dateOptions)} ${date.toLocaleTimeString(navigator.language, timeOptions)}`;
}

function processMessageText(messageText) {
    // Regular expressions for link detection
   const youtubeRegex = /(?:https?:\/\/)?(?:www\.)?(?:youtube\.com\/(?:[^\/\n\s]+\/\S+\/|(?:v|e(?:mbed)?)\/|\S*?[?&]v=|shorts\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})(?:[^\s]*)?/gi;
    const tenorRegex = /(https?:\/\/(?:media\.tenor\.com|tenor\.com)\/[^\s]+)/g;
    const imageRegex = /(https?:\/\/[^\s]+?\.(?:jpg|jpeg|png|gif|webp|bmp)(?:\?[^\s]*)?)/gi;
    const discordCdnRegex = /(https?:\/\/(?:cdn\.discordapp\.com|media\.discordapp\.net)\/attachments\/[^\s]+)/gi;
    const urlRegex = /(https?:\/\/[^\s<>"']+)/g;

    // First, preserve the original text for fallback
    let processedText = messageText;

    // Escape HTML
    processedText = processedText
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');

    // Replace newlines with <br> tags
    processedText = processedText.replace(/\n/g, '<br>');

    // Function to check if URL contains HTML tags (encoded or not)
    const containsHtmlTags = (url) => {
        return /%(3C|3E|3c|3e)|<|>|%(20)?(href|src)=/i.test(url) || 
               /<[a-z][\s\S]*>/i.test(decodeURIComponent(url));
    };

    // Process all URLs in the text
    const urlMatches = processedText.match(urlRegex) || [];
    const processedUrls = new Set();

    urlMatches.forEach(url => {
        if (processedUrls.has(url)) return;

        // Skip if URL is already processed
        if (processedText.includes(`href="${url}"`) || processedText.includes(`src="${url}"`)) {
            processedUrls.add(url);
            return;
        }

        try {
            const decodedUrl = decodeURIComponent(url);

            // Check for HTML tags in URL
            if (containsHtmlTags(url) || containsHtmlTags(decodedUrl)) {
                processedText = processedText.replace(
                    new RegExp(escapeRegExp(url), 'g'),
                    `<a href="${url}" target="_blank" class="text-blue-400 hover:underline">${url}</a>`
                );
                processedUrls.add(url);
                return;
            }

            // Process special URL types
            // 1. YouTube
          if (youtubeRegex.test(processedText)) {
        processedText = processedText.replace(youtubeRegex, (match, videoId) => {
            return `
                <div class="youtube-preview">
                    <iframe 
                        width="100%" 
                        height="200" 
                        src="https://www.youtube.com/embed/${videoId}" 
                        frameborder="0" 
                        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" 
                        allowfullscreen
                        loading="lazy">
                    </iframe>
                </div>
            `;
        });
    }

            // 2. Tenor GIFs
            if (tenorRegex.test(url)) {
                const cleanUrl = url.replace(/(\.gif).*$/, '$1');
                processedText = processedText.replace(
                    new RegExp(escapeRegExp(url), 'g'),
                    `<img src="${cleanUrl}" class="max-w-xs rounded-lg mt-2" alt="GIF" loading="lazy" onerror="this.style.display='none'">`
                );
                processedUrls.add(url);
                return;
            }

            // 3. Discord CDN
            if (discordCdnRegex.test(url)) {
                processedText = processedText.replace(
                    new RegExp(escapeRegExp(url), 'g'),
                    `<img src="${url}" class="max-w-xs rounded-lg mt-2" alt="Image" loading="lazy" onerror="this.style.display='none'">`
                );
                processedUrls.add(url);
                return;
            }

            // 4. Regular images
            if (imageRegex.test(url)) {
                processedText = processedText.replace(
                    new RegExp(escapeRegExp(url), 'g'),
                    `<img src="${url}" class="max-w-xs rounded-lg mt-2" alt="Image" loading="lazy" onerror="this.style.display='none'">`
                );
                processedUrls.add(url);
                return;
            }

            // 5. All other URLs
            processedText = processedText.replace(
                new RegExp(escapeRegExp(url), 'g'),
                `<a href="${url}" target="_blank" class="text-blue-400 hover:underline">${url}</a>`
            );
            processedUrls.add(url);

        } catch (e) {
            // Fallback for invalid URLs
            processedText = processedText.replace(
                new RegExp(escapeRegExp(url), 'g'),
                `<a href="${url}" target="_blank" class="text-blue-400 hover:underline">${url}</a>`
            );
            processedUrls.add(url);
        }
    });

    return processedText;
}

// Helper function to escape regex special characters
function escapeRegExp(string) {
    return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

// timeAgo function (reused from topluluklar (18).php)
function timeAgo(datetime) {
    const now = new Date();
    const ago = new Date(datetime);
    const diff = (now - ago) / 1000; // seconds

    if (diff < 60) return 'az önce';
    if (diff < 3600) return Math.floor(diff / 60) + ' dakika önce';
    if (diff < 86400) return Math.floor(diff / 3600) + ' saat önce';
    if (diff < 2592000) return Math.floor(diff / 86400) + ' gün önce';
    if (diff < 31536000) return Math.floor(diff / 2592000) + ' ay önce';
    return Math.floor(diff / 31536000) + ' yıl önce';
}

function createEditForm(messageId, text) {
    const form = document.createElement('div');
    form.className = 'edit-message-form hidden mt-2';
    form.dataset.messageId = messageId;
    form.innerHTML = `
        <form class="edit-form">
            <textarea name="edited_message_text" class="w-full bg-gray-700 text-white px-3 py-2 rounded">${text}</textarea>
            <div class="flex justify-end mt-2">
                <button type="submit" class="bg-blue-500 text-white px-4 py-2 rounded"><?php echo $translations['Save']; ?></button>
                <button type="button" class="cancel-edit bg-gray-600 text-white px-4 py-2 rounded ml-2"><?php echo $translations['Decline']; ?></button>
            </div>
        </form>
    `;
    return form;
}
document.addEventListener('click', function(event) {
    // Üç nokta butonuna tıklandığında
    if (event.target.closest('.more-options-button')) {
        const moreOptionsButton = event.target.closest('.more-options-button');
        const optionsMenu = moreOptionsButton.nextElementSibling;

        // Tüm diğer menüleri kapat
        document.querySelectorAll('.options-menu').forEach(menu => {
            if (menu !== optionsMenu) {
                menu.classList.add('hidden');
            }
        });

        // Seçili menüyü aç/kapat
        optionsMenu.classList.toggle('hidden');
    } else {
        // Menü dışında bir yere tıklandığında tüm menüleri kapat
        document.querySelectorAll('.options-menu').forEach(menu => {
            menu.classList.add('hidden');
        });
    }
});
// Yanıt butonuna tıklandığında ana input alanını yanıt moduna geçirme
document.addEventListener('click', function(event) {
    if (event.target.closest('[data-action="reply-message"]')) {
        const button = event.target.closest('[data-action="reply-message"]');
        const messageId = button.dataset.messageId;
        const messageContainer = button.closest('.message-and-forms-container'); // Changed to .message-and-forms-container
        const username = messageContainer.dataset.username || 'Unknown User'; // Use data-username
        const messageText = messageContainer.querySelector('.text-gray-300.text-sm')?.textContent || '';

        // Yanıt modunu aktif et
        isReplying = true;
        replyToMessageId = messageId;

        // Önizlemeyi güncelle
        document.getElementById('reply-username').textContent = username;
        document.getElementById('reply-text').textContent = messageText.substring(0, 50) + (messageText.length > 50 ? '...' : '');
        replyPreviewDiv.classList.remove('hidden');

        // Input alanına odaklan
        document.getElementById('message-input').focus();
    }
});

// Yanıt iptal butonuna tıklandığında
document.getElementById('cancel-reply').addEventListener('click', function() {
    isReplying = false;
    replyToMessageId = null;
    replyPreviewDiv.classList.add('hidden');
    document.getElementById('message-input').focus();
});
// Mesaj silme işlevi
let deletedMessageIds = []; // Silinen mesaj ID'lerini tutmak için dizi

document.addEventListener('click', (e) => {
    const deleteButton = e.target.closest('[data-action="delete-message"]');
    if (!deleteButton) return;

    const messageId = deleteButton.dataset.messageId;

    // Mesaj elementini bul
    const messageElement = document.querySelector(`[data-message-id="${messageId}"]`);
    if (!messageElement) {
        showTempNotification('Mesaj bulunamadı.', 'error');
        return;
    }

    // Mesaj silme işlemi başlat
    fetch('delete_message.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `message_id=${messageId}`
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP error! Status: ${response.status}`);
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            // Silinen mesajın ID'sini listeye ekle
            deletedMessageIds.push(messageId.toString());

            // Mesajı animasyonla kaldır
            messageElement.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
            messageElement.style.opacity = '0';
            messageElement.style.transform = 'translateX(-20px)';
            setTimeout(() => {
                messageElement.remove();
                showTempNotification('Mesaj başarıyla silindi.', 'success');
            }, 300);
        } else {
            showTempNotification(data.message || 'Mesaj silinirken bir hata oluştu.', 'error');
        }
    })
    .catch(error => {
        console.error('Mesaj silme hatası:', error);
        showTempNotification('Mesaj silinirken bir hata oluştu.', 'error');
    });
});


// Mesaj düzenleme formunu açma
document.addEventListener('click', function(event) {
    if (event.target.closest('[data-action="edit-message"]')) {
        const messageId = event.target.closest('[data-action="edit-message"]').dataset.messageId;
        const editForm = document.querySelector(`.edit-message-form[data-message-id="${messageId}"]`);
        if (editForm) {
            const textarea = editForm.querySelector('textarea');
            editForm.classList.toggle('hidden');
            textarea.focus(); 
            textarea.selectionStart = textarea.selectionEnd = textarea.value.length;
        }
    }
});

// Mesaj düzenleme formu gönderimi
document.addEventListener('submit', function(event) {
    if (event.target.closest('.edit-message-form')) {
        event.preventDefault();
        const form = event.target.closest('.edit-message-form');
        const messageId = form.dataset.messageId;
        const editedMessageText = form.querySelector('textarea[name="edited_message_text"]').value;

        fetch('messages.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=edit_message&message_id=${messageId}&new_message_text=${encodeURIComponent(editedMessageText)}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const messageTextDiv = document.querySelector(`[data-message-id="${messageId}"] .text-gray-300`);
                if (messageTextDiv) {
                    messageTextDiv.textContent = editedMessageText;
                    if (!messageTextDiv.querySelector('.edited-text')) {
                        const editedSpan = document.createElement('span');
                        editedSpan.className = 'edited-text text-gray-400 text-xs ml-2';
                        editedSpan.textContent = '(Düzenlendi)';
                        messageTextDiv.appendChild(editedSpan);
                    }
                }
                form.classList.add('hidden');
            } else {
                alert(data.message || 'Mesaj düzenlenirken bir hata oluştu.');
            }
        })
        .catch(error => {
            console.error('Hata:', error);
            alert('Mesaj düzenlenirken bir hata oluştu. Lütfen tekrar deneyin.');
        });
    }
});

// Mesaj düzenleme formunu iptal etme
document.addEventListener('click', function(event) {
    if (event.target.closest('.cancel-edit')) {
        event.preventDefault();
        const editForm = event.target.closest('.edit-message-form');
        editForm.classList.add('hidden');
    }
});
            function startMessageUpdateInterval() {
                if (messageUpdateInterval) {
                    clearInterval(messageUpdateInterval);
                }
                messageUpdateInterval = setInterval(checkNewMessages, 1000); // Her 5 saniyede bir yeni mesajları kontrol et
            }

            function stopMessageUpdateInterval() {
                if (messageUpdateInterval) {
                    clearInterval(messageUpdateInterval);
                    messageUpdateInterval = null;
                }
            }

            // Open chat and start checking for new messages
let currentChatName = '';

function openChat(id, name, type = 'friend') {
    // Önceki dinleyicileri temizle
    stopMessageUpdateInterval();
    
    // Değişkenleri sıfırla
    currentFriendId = null;
    currentGroupId = null;
    isGroupChat = false;
    
    // Yeni chat türünü ayarla
    if (type === 'group') {
        currentGroupId = id;
        isGroupChat = true;
    } else {
        currentFriendId = id;
    }
     // Grup üyeleri butonunun görünürlüğünü güncelle
    const groupMembersBtn = document.getElementById('group-members-btn');
    if (groupMembersBtn) {
        groupMembersBtn.style.display = isGroupChat ? 'block' : 'none';
    }
    
    // Grup ayarları butonunun görünürlüğünü güncelle
    const groupSettingsBtn = document.getElementById('group-settings-btn');
    if (groupSettingsBtn) {
        groupSettingsBtn.style.display = isGroupChat ? 'block' : 'none';
    }
    
     const typingIndicator = document.getElementById('typing-indicator');
    if(typingIndicator) {
        typingIndicator.style.display = 'none';
        typingIndicator.style.opacity = '0';
        typingIndicator.querySelector('.typing-username').textContent = '';
    }
    
    // UI güncellemeleri
    currentChatName = name;
    document.getElementById('chat-username').textContent = name;
    document.getElementById('input-area').classList.remove('hidden');
    document.getElementById('chat-container').classList.remove('hidden');
    
    // Mesaj geçmişini temizle
    document.getElementById('message-container').innerHTML = '';
    
    // İlk mesaj yüklemeyi yap
    loadMessages(); // Sayfa 1'den başla
    
    // Okundu bilgisi güncelle (Hem DM hem Grup için)
    const formData = new FormData();
    if (isGroupChat) {
        formData.append('action', 'mark_group_read');
        formData.append('group_id', id);
    } else {
        formData.append('action', 'mark_as_read');
        formData.append('friend_id', id);
    }

    fetch('messages.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if(data.success) {
            // Unread count'u UI'dan kaldır
            const selector = isGroupChat ? 
                `[data-group-id="${id}"] .unread-count` : 
                `[data-friend-id="${id}"] .unread-count`;
            
            const unreadElement = document.querySelector(selector);
            if(unreadElement) {
                unreadElement.remove();
            }
        }
    })
    .catch(error => console.error('Okundu güncelleme hatası:', error));
    
    // Mesaj kontrolünü başlat
    startMessageUpdateInterval();
}

// Grup seçim handler
document.querySelectorAll('.group-item').forEach(item => {
    item.addEventListener('click', function(event) {
        // Eğer tıklanan öğe bir buton veya butonun içindeki bir öğeyse, işlemi durdur
        if (event.target.closest('button')) return;

        const groupId = this.dataset.groupId;
        const groupName = this.querySelector('.text-sm').textContent;
        openChat(groupId, groupName, 'group');
    });
});

// Back button functionality
if (backToFriends) {
    backToFriends.addEventListener('click', () => {
        const chatContainer = document.getElementById('chat-container');
        if (chatContainer) chatContainer.classList.add('hidden');
        currentFriendId = null;
        currentGroupId = null;
        isGroupChat = false;
        // Grup üyeleri butonunu gizle
        const groupMembersBtn = document.getElementById('group-members-btn');
        if (groupMembersBtn) {
            groupMembersBtn.style.display = 'none';
        }
        stopMessageUpdateInterval();
    });
}

            // Load more messages when user scrolls to the top
            document.getElementById('message-container').addEventListener('scroll', function() {
                if (this.scrollTop === 0 && !loadingMessages) {
                    loadMessages(currentFriendId, currentPage);
                }
            });

          

            // Add Friend Modal
            const addFriendBtn = document.getElementById('add-friend-btn');
            const addFriendModal = document.getElementById('add-friend-modal');
            const cancelAddFriend = document.getElementById('cancel-add-friend');
            const confirmAddFriend = document.getElementById('confirm-add-friend');
            const mainAddFriendBtn = document.getElementById('add-friend-btn'); // Ana arayüzdeki 'Add Friend' butonu

            addFriendBtn.addEventListener('click', () => {
                addFriendModal.classList.remove('hidden');
                addFriendModal.classList.add('flex');
            });

            cancelAddFriend.addEventListener('click', () => {
                addFriendModal.classList.add('hidden');
                addFriendModal.classList.remove('flex');
            });

            document.getElementById('confirm-add-friend').addEventListener('click', async () => {
    const username = $('#friend-username').val().trim();
    
    if (!username) {
        alert('Please enter a username');
        return;
    }

    try {
        // 1. Kullanıcıyı bul
        const response = await fetch('find_user.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `action=find_user&username=${encodeURIComponent(username)}`
        });
        
        const data = await response.json(); // userData yerine data kullan
        
        if (!data.success) {
            throw new Error(data.message || 'User not found');
        }
        
        // 2. Kendi kendine ekleme kontrolü
        if (data.user_id == <?= $_SESSION['user_id'] ?>) {
            throw new Error('You cannot add yourself as a friend.');
        }

        // 3. Arkadaşlık isteği gönder
        const requestResponse = await fetch('find_user.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `action=send_request&receiver_id=${data.user_id}`
        });
        
        const requestData = await requestResponse.json();
        
        if (!requestData.success) {
            throw new Error(requestData.message || 'Failed to send friend request');
        }

        // 4. WebSocket bildirimi (ID'leri stringe çevir)
        ws.send(JSON.stringify({
            type: 'friend-request-sent',
            senderId: String(<?= $_SESSION['user_id'] ?>),
            receiverId: String(data.user_id)
        }));

        alert('Friend request sent successfully');
        $('#friend-username').val('');
        document.getElementById('add-friend-modal').classList.add('hidden');
        
    } catch (error) {
        console.error('Error:', error);
        alert(error.message || 'An error occurred');
    }
});

            // Friend request handlers
            $(document).ready(function() {
                $('.accept-request').click(function() {
                    const senderId = $(this).data('sender-id');
                    const requestElement = $(this).closest('.bg-gray-800');
                    
                    $.post('find_user.php', {
                        action: 'accept_request',
                        sender_id: senderId
                    })
                    .done(function(response) {
                        if (response.success) {
                             ws.send(JSON.stringify({
        type: 'friend-request-updated',
        senderId: senderId,
        receiverId: <?php echo $_SESSION['user_id']; ?>
      }));
                            requestElement.fadeOut();
                            location.reload(); // Refresh to show updated friends list
                        } else {
                            alert(response.message || 'Failed to accept friend request');
                        }
                    })
                    .fail(function() {
                        alert('Failed to accept friend request');
                    });
                });

                $('.reject-request').click(function() {
                    const senderId = $(this).data('sender-id');
                    const requestElement = $(this).closest('.bg-gray-800');
                    
                    $.post('find_user.php', {
                        action: 'reject_request',
                        sender_id: senderId
                    })
                    .done(function(response) {
                        if (response.success) {
                             ws.send(JSON.stringify({
        type: 'friend-request-updated',
        senderId: senderId,
        receiverId: <?php echo $_SESSION['user_id']; ?>
      }));
                            requestElement.fadeOut();
                            alert(response.message); // İsteğe bağlı olarak kullanıcıya bir mesaj gösterebilirsiniz
                        } else {
                            alert(response.message || 'Failed to reject friend request');
                        }
                    })
                    .fail(function() {
                        alert('Failed to reject friend request');
                    });
                });
            });

 function updateOnlineFriends() {
    fetch('get_friends_status.php')
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            // Hata kontrolü: data bir dizi değilse veya hata içeriyorsa
            if (!Array.isArray(data)) {
                console.error('Invalid data format:', data);
                if (data.error) {
                    console.error('Server error:', data.error);
                }
                return;
            }

            const activeTab = document.querySelector('#online-tab.text-green-500, #all-tab.text-green-500')?.id;
            const translations = <?php echo json_encode($translations); ?>;
            let onlineCount = 0;

            // Status translations mapping
            const statusTranslations = {
                'online': translations['Online'] || 'Online',
                'idle': translations['Idle'] || 'Idle',
                'dnd': translations['DoNotDisturb'] || 'Do Not Disturb', // Use translation for "dnd"
                'offline': translations['Offline'] || 'Offline'
            };

            // Tüm arkadaş öğelerini tara
            document.querySelectorAll('[data-friend-id]').forEach(element => {
                const friendId = element.dataset.friendId;
                const friendData = data.find(f => f.id == friendId);

                if (friendData) {
                    const isOnline = friendData.is_online === 1;
                    const status = friendData.status || 'offline';

                    // Durum noktası (status-dot) güncelleme
                    const statusDot = element.querySelector('.status-dot');
                    if (statusDot) {
                        statusDot.classList.remove('status-online', 'status-idle', 'status-dnd', 'status-offline');
                        statusDot.classList.add(`status-${status}`);
                    }

                    // Çevrimiçi/çevrimdışı metni güncelleme
                    const onlineStatusText = element.querySelector('.online-status');
                    if (onlineStatusText) {
                        onlineStatusText.textContent = statusTranslations[status] || status.charAt(0).toUpperCase() + status.slice(1);
                    }

                    // Online sekmesinde görünürlük kontrolü
                    if (element.closest('#friends-list-container')) {
                        if (activeTab === 'online-tab') {
                            element.style.display = isOnline ? 'flex' : 'none';
                        } else {
                            element.style.display = 'flex';
                        }
                    }

                    // DM listesi için her zaman görünür
                    if (element.closest('.friends-list')) {
                        element.style.display = 'flex';
                    }

                    if (isOnline) onlineCount++;
                }
            });

            // "No online" mesaj kontrolü
            const noOnlineMsg = document.getElementById('no-online-friends');
            if (noOnlineMsg) {
                noOnlineMsg.classList.toggle('hidden', !(activeTab === 'online-tab' && onlineCount === 0));
            }
        })
        .catch(error => {
            console.error('Error updating friends status:', error);
        });
}

    // Üç nokta menüsünü açma/kapama için yardımcı fonksiyon
    function toggleOptionsMenu(friendId) {
        const optionsMenu = document.getElementById(`options-menu-${friendId}`);
        if (optionsMenu) {
            document.querySelectorAll('.absolute.right-0.mt-2.w-48.bg-gray-800.rounded-md.shadow-lg').forEach(menu => {
                if (menu.id !== `options-menu-${friendId}`) {
                    menu.classList.add('hidden');
                }
            });
            optionsMenu.classList.toggle('hidden');
        }
    }

    // Her 5 saniyede bir kontrol et
    setInterval(() => {
        const activeTab = document.querySelector('#online-tab.text-green-500, #all-tab.text-green-500')?.id;
        if (activeTab === 'online-tab' || activeTab === 'all-tab') {
            updateOnlineFriends();
        }
    }, 5000);

    // DOM yüklendiğinde üç nokta menüsü olay dinleyicilerini ekle
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.friend-item').forEach(item => {
            const friendId = item.dataset.friendId;
            const moreOptionsBtn = document.getElementById(`more-options-${friendId}`);
            const optionsMenu = document.getElementById(`options-menu-${friendId}`);

            if (moreOptionsBtn && optionsMenu) {
                moreOptionsBtn.addEventListener('click', (event) => {
                    event.stopPropagation();
                    toggleOptionsMenu(friendId);
                });

                document.addEventListener('click', (event) => {
                    if (!optionsMenu.contains(event.target) && !moreOptionsBtn.contains(event.target)) {
                        optionsMenu.classList.add('hidden');
                    }
                });
            }
        });
    });
        });

      document.addEventListener('DOMContentLoaded', function() {
    const onlineTab = document.getElementById('online-tab');
    const allTab = document.getElementById('all-tab');
    const pendingTab = document.getElementById('pending-tab');
    const blockedTab = document.getElementById('blocked-tab');

    function filterFriends(filter) {
        const friends = document.querySelectorAll('.friend-item');
        let onlineCount = 0;

        friends.forEach(friend => {
            // `status-online`, `status-idle` veya `status-dnd` sınıflarını kontrol et
            const statusDot = friend.querySelector('.status-dot');
            const isOnline = statusDot && (
                statusDot.classList.contains('status-online') ||
                statusDot.classList.contains('status-idle') ||
                statusDot.classList.contains('status-dnd')
            );

            if (filter === 'online') {
                friend.style.display = isOnline ? 'flex' : 'none';
                if (isOnline) onlineCount++;
            } else if (filter === 'all') {
                friend.style.display = 'flex';
            } else if (filter === 'pending') {
                friend.style.display = 'none'; // Hide friends list in pending tab
            } else if (filter === 'blocked') {
                friend.style.display = 'none';
            }
        });

        // Show/hide friends list container based on the active tab
        if (filter === 'pending') {
            document.getElementById('friends-list-container').classList.add('hidden');
            document.getElementById('friend-requests-section').classList.remove('hidden');
            document.getElementById('blocked-friends-section').classList.add('hidden');
        } else if (filter === 'blocked') {
            document.getElementById('friends-list-container').classList.add('hidden');
            document.getElementById('friend-requests-section').classList.add('hidden');
            document.getElementById('blocked-friends-section').classList.remove('hidden');
        } else {
            document.getElementById('friends-list-container').classList.remove('hidden');
            document.getElementById('friend-requests-section').classList.add('hidden');
            document.getElementById('blocked-friends-section').classList.add('hidden');
        }

        // Show/hide "No one is online" message
        if (filter === 'online' && onlineCount === 0) {
            document.getElementById('no-online-friends').classList.remove('hidden');
        } else {
            document.getElementById('no-online-friends').classList.add('hidden');
        }

        // Reset tab styles
        onlineTab.classList.remove('text-green-500');
        allTab.classList.remove('text-green-500');
        pendingTab.classList.remove('text-green-500');
        blockedTab.classList.remove('text-green-500');

        // Apply active tab style
        if (filter === 'online') {
            onlineTab.classList.add('text-green-500');
        } else if (filter === 'all') {
            allTab.classList.add('text-green-500');
        } else if (filter === 'pending') {
            pendingTab.classList.add('text-green-500');
        } else if (filter === 'blocked') {
            blockedTab.classList.add('text-green-500');
        }
    }

    // Add click event listeners to the tabs
    onlineTab.addEventListener('click', () => filterFriends('online'));
    allTab.addEventListener('click', () => filterFriends('all'));
    pendingTab.addEventListener('click', () => filterFriends('pending'));
    blockedTab.addEventListener('click', () => filterFriends('blocked'));

    // Filter friends to show only online by default when the page loads
    filterFriends('online');
});

        document.addEventListener('click', function(event) {
            const target = event.target;

            // Üç nokta düğmesini veya üç nokta düğmesinin içindeki herhangi bir öğeyi kontrol et
            if (target.closest('.friend-item')) {
                const friendItem = target.closest('.friend-item');
                const friendId = friendItem.getAttribute('data-friend-id');
                const moreOptionsButton = document.getElementById(`more-options-${friendId}`);
                const optionsMenu = document.getElementById(`options-menu-${friendId}`);

                // Üç nokta düğmesine tıklandıysa
                if (moreOptionsButton && moreOptionsButton.contains(target)) {
                    // Tüm menüleri gizle
                    document.querySelectorAll('.friend-item .options-menu').forEach(menu => {
                        if (menu !== optionsMenu) {
                            menu.classList.add('hidden');
                        }
                    });

                    // Seçili menüyü göster/gizle
                    optionsMenu.classList.toggle('hidden');
                } else {
                    // Diğer tıklamalarda menüyü gizle
                    optionsMenu.classList.add('hidden');
                }
            }

            if (target.closest('[data-action="remove-friend"]')) {
                const friendId = target.closest('[data-action="remove-friend"]').getAttribute('data-friend-id');
                const friendItem = document.querySelector(`.friend-item[data-friend-id="${friendId}"]`);

                if (confirm('Are you sure you want to remove this friend?')) {
                    fetch('remove_friend.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `friend_id=${friendId}`
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            friendItem.remove();
                        } else {
                            alert(data.message || 'Failed to remove friend');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('Failed to remove friend. Please try again.');
                    });
                }
            }

            if (target.closest('[data-action="block-friend"]')) {
                const friendId = target.closest('[data-action="block-friend"]').getAttribute('data-friend-id');
                const friendItem = document.querySelector(`.friend-item[data-friend-id="${friendId}"]`);

                if (confirm('Are you sure you want to block this friend?')) {
                    fetch('block_friend.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `friend_id=${friendId}`
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            friendItem.remove();
                        } else {
                            alert(data.message || 'Failed to block friend');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('Failed to block friend. Please try again.');
                    });
                }
            }

            if (target.closest('.unblock-friend')) {
                const friendId = target.closest('.unblock-friend').dataset.friendId;
                const friendItem = target.closest('.bg-gray-800');

                fetch('unblock_friend.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `friend_id=${friendId}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        friendItem.remove();
                    } else {
                        alert(data.message || 'Failed to unblock friend');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Failed to unblock friend. Please try again.');
                });
            }
        });

document.addEventListener('DOMContentLoaded', function () {
    const emojiButton = document.getElementById('emoji-button');
    const messageInput = document.getElementById('message-input');
    let picker = null; // Emoji picker'ı tutacak değişken
    let isPickerOpen = false; // Picker'ın açık olup olmadığını kontrol etmek için

    // Emoji butonuna tıklandığında picker'ı aç/kapat
    emojiButton.addEventListener('click', function (event) {
        event.stopPropagation(); // Emoji butonuna tıklanmasının yayılmasını engelle
        if (isPickerOpen) {
            // Picker açıksa kapat
            picker.remove();
            picker = null;
            isPickerOpen = false;
        } else {
            // Picker kapalıysa aç
            picker = new EmojiMart.Picker({
                data: async () => {
                    const response = await fetch('https://cdn.jsdelivr.net/npm/@emoji-mart/data@latest/sets/14/twitter.json');
                    return response.json();
                },
                set: 'twitter', // Twemoji setini kullan
                onEmojiSelect: (emoji) => {
                    // Seçilen emoji'yi mesaj alanına ekle
                    messageInput.value += emoji.native;
                },
                theme: 'dark' // Karanlık tema
            });
            // Picker'ı mesaj giriş kutusunun sol üst köşesine tam olarak yerleştir
            const messageInputRect = messageInput.getBoundingClientRect();
            const pickerHeight = 350; // Picker'ın yüksekliği (yaklaşık değer)
            picker.style.position = 'absolute';
            picker.style.bottom = `${window.innerHeight - messageInputRect.top + 15}px`; // Mesaj giriş kutusunun üstüne
            picker.style.left = `${messageInputRect.left - 60}px`; // Mesaj giriş kutusunun sol kenarından 10 piksel sola kaydır
            picker.style.zIndex = 1000; // Üstte görünmesini sağla
            // Picker'ı body'ye ekle
            document.body.appendChild(picker);
            isPickerOpen = true;
        }
    });

    // Picker dışında bir yere tıklandığında picker'ı kapat
    document.addEventListener('click', function (event) {
        if (picker && !picker.contains(event.target) && event.target !== emojiButton) {
            picker.remove();
            picker = null;
            isPickerOpen = false;
        }
    });

    // Mesaj yazma alanına tıklandığında picker'ı kapat
    messageInput.addEventListener('click', function (event) {
        event.stopPropagation(); // Mesaj yazma alanına tıklanmasının yayılmasını engelle
        if (picker) {
            picker.remove();
            picker = null;
            isPickerOpen = false;
        }
    });
});


 
// Modal fonksiyonları
function openCreateServerModal() {
    document.getElementById('create-server-modal').classList.remove('hidden');
}

function closeCreateServerModal() {
    document.getElementById('create-server-modal').classList.add('hidden');
}

// Dosya yükleme için
document.getElementById('modal-pp-upload').addEventListener('click', () => {
    document.querySelector('#create-server-form input[type="file"]').click();
});

// Form Gönderimi
document.getElementById('create-group-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const submitButton = document.getElementById('create-group-button');
    const spinner = document.getElementById('create-group-spinner');
    const buttonText = document.getElementById('create-group-text');
    
    submitButton.disabled = true;
    spinner.classList.remove('hidden');
    buttonText.textContent = 'Oluşturuluyor...';
    
    const formData = new FormData();
    formData.append('name', document.getElementById('group-name').value);
    const avatarInput = document.getElementById('group-avatar');
    if (avatarInput.files[0]) {
        formData.append('avatar', avatarInput.files[0]);
    }
    const selectedMembers = Array.from(document.querySelectorAll('#friend-selection input[type="checkbox"]:checked'))
                              .map(checkbox => checkbox.value);
    formData.append('members', JSON.stringify(selectedMembers));
    
    try {
        const response = await fetch('create_group.php', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();
        if (result.success) {
            showMessage('Grup başarıyla oluşturuldu!', 'success');
            closeCreateGroupModal();
            location.reload();
        } else {
            showMessage(result.error || 'Grup oluşturulamadı', 'error');
        }
    } catch (error) {
        showMessage('Bir hata oluştu', 'error');
    } finally {
        submitButton.disabled = false;
        spinner.classList.add('hidden');
        buttonText.textContent = 'Oluştur';
    }
});
// Voice Call Logic
// Voice Call Logic
let localStream;
let peerConnection;
let typingTimeout;
const currentUserId = <?php echo $_SESSION['user_id']; ?>;
const currentUsername = '<?php echo $_SESSION['username']; ?>';
let isTypingAnimationActive = false;
let lastTypingUser = null;
let isWsConnected = false;



ws.addEventListener('open', () => {
  const authMessage = {
    type: 'auth',
    userId: String(<?php echo json_encode($_SESSION['user_id']); ?>),
    username: <?php echo json_encode($_SESSION['username'] ?? 'Bilinmeyen Kullanıcı'); ?>,
    avatarUrl: <?php echo json_encode($_SESSION['avatar_url'] ?? 'avatars/default-avatar.png'); ?>
  };
  console.log('📤 Gönderilen auth mesajı:', JSON.stringify(authMessage, null, 2));
  ws.send(JSON.stringify(authMessage));
});
// Heartbeat mekanizması: Her 30 saniyede bir mesaj gönder
  const heartbeatInterval = setInterval(() => {
    if (ws.readyState === WebSocket.OPEN) {
      ws.send(JSON.stringify({ type: 'heartbeat' }));
      console.log('Heartbeat mesajı gönderildi');
    }
  }, 30000); // server.js'deki pingInterval ile uyumlu (30 saniye)

  // Bağlantı kapandığında heartbeat'ı durdur
  ws.addEventListener('close', () => {
    console.log('Bağlantı kesildi');
    clearInterval(heartbeatInterval);
  });

  // Hata durumunda heartbeat'ı durdur (opsiyonel)
  ws.addEventListener('error', (error) => {
    console.error('WebSocket hatası:', error);
    clearInterval(heartbeatInterval);
  });
// WebSocket mesaj işleyiciyi güncelle
ws.addEventListener('message', (event) => {
  try {
    const data = JSON.parse(event.data);
    
    if (data.type === 'unread-update') {
      // Mevcut sohbet partnerinin ID'sini al
      const activeFriendId = currentFriendId ? parseInt(currentFriendId, 10) : null;
      
      // Sadece aktif olmayan arkadaşlar için UI güncelle
      const filteredCounts = Object.keys(data.counts).reduce((acc, key) => {
        const friendId = parseInt(key, 10);
        if (friendId !== activeFriendId) {
          acc[friendId] = data.counts[key];
        }
        return acc;
      }, {});

      updateUnreadCountsUI(filteredCounts, document.querySelector('.friends-list'));
    }
  } catch (error) {
    console.error('Mesaj işleme hatası:', error);
  }
});

// Add Friend Modal fonksiyonları
function openAddFriendModal() {
    const addFriendModal = document.getElementById('add-friend-modal');
    if (addFriendModal) {
        // 'hidden' sınıfını kaldır ve 'flex' sınıfını ekle
        addFriendModal.classList.remove('hidden');
        addFriendModal.classList.add('flex');
    }
}

function closeAddFriendModal() {
    const addFriendModal = document.getElementById('add-friend-modal');
    if (addFriendModal) {
        // 'flex' sınıfını kaldır ve 'hidden' sınıfını ekle
        addFriendModal.classList.remove('flex');
        addFriendModal.classList.add('hidden');
    }
    // Ayrıca, form alanlarını temizlemek isteyebilirsin
    const friendUsernameInput = document.getElementById('friend-username');
    if (friendUsernameInput) {
        friendUsernameInput.value = '';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    // Ana 'Add Friend' butonu için olay dinleyicisi (Eğer id="add-friend-btn" varsa)
    document.querySelectorAll('.add-friend-btn').forEach(btn => {
    btn.addEventListener('click', openAddFriendModal);
      });

    // Modal içindeki 'Cancel' butonu için olay dinleyicisi
    const cancelAddFriend = document.getElementById('cancel-add-friend');
    if (cancelAddFriend) {
        cancelAddFriend.addEventListener('click', closeAddFriendModal);
    }

    // Modal dışına tıklayınca kapatma
    const addFriendModal = document.getElementById('add-friend-modal');
    if (addFriendModal) {
        addFriendModal.addEventListener('click', function(event) {
            if (event.target === addFriendModal) {
                closeAddFriendModal();
            }
        });
    }

    // Onay butonunun işlevi (arkadaş ekleme isteği gönderme mantığı)
    const confirmAddFriend = document.getElementById('confirm-add-friend');
    if (confirmAddFriend) {
        confirmAddFriend.addEventListener('click', async () => {
            const username = document.getElementById('friend-username').value.trim(); // jQuery yerine düz JS
            
            if (!username) {
                alert('Please enter a username');
                return;
            }

            try {
                // 1. Kullanıcıyı bul
                const response = await fetch('find_user.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: `action=find_user&username=${encodeURIComponent(username)}`
                });
                
                const data = await response.json(); 
                
                if (!data.success) {
                    throw new Error(data.message || 'User not found');
                }
                
                // 2. Kendi kendine ekleme kontrolü
                // PHP değişkenini JavaScript'e doğru aktarmak için tırnak işaretlerini kullanın
                if (data.user_id == '<?= $_SESSION['user_id'] ?>') { 
                    throw new Error('You cannot add yourself as a friend.');
                }

                // 3. Arkadaşlık isteği gönder
                const requestResponse = await fetch('find_user.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: `action=send_request&receiver_id=${data.user_id}`
                });
                
                const requestData = await requestResponse.json();
                
                if (!requestData.success) {
                    throw new Error(requestData.message || 'Failed to send friend request');
                }

                // 4. WebSocket bildirimi (ID'leri stringe çevir)
                if (typeof ws !== 'undefined' && ws.readyState === WebSocket.OPEN) {
                    ws.send(JSON.stringify({
                        type: 'friend-request-sent',
                        senderId: String(<?= $_SESSION['user_id'] ?>),
                        receiverId: String(data.user_id)
                    }));
                } else {
                    console.warn("WebSocket connection not open. Friend request notification might not be real-time.");
                }

                alert('Friend request sent successfully');
                document.getElementById('friend-username').value = ''; // Inputu temizle
                closeAddFriendModal(); // İşlem bittikten sonra modalı kapat
                
            } catch (error) {
                console.error('Error:', error);
                alert(error.message || 'An error occurred');
            }
        });
    }
});
// Güncellenmiş UI fonksiyonu
function updateUnreadCountsUI(counts, container) {
  container.querySelectorAll('[data-friend-id]').forEach(element => {
    const friendId = parseInt(element.dataset.friendId, 10);
    const count = counts[friendId] || 0;
    
    const badge = element.querySelector('.unread-count');
    if (count > 0) {
      if (!badge) {
        const newBadge = document.createElement('div');
        newBadge.className = 'unread-count bg-red-500 text-white text-xs rounded-full w-5 h-5 flex items-center justify-center ml-2';
        newBadge.textContent = count;
        element.appendChild(newBadge);
      } else {
        badge.textContent = count;
      }
    } else if (badge) {
      badge.remove();
    }
  });
}

// WebSocket listener
ws.addEventListener('message', (event) => {
  try {
    const data = JSON.parse(event.data);
    
    if (data.type === 'pending-count') {
      updatePendingCountUI(data.count);
    } else if (data.type === 'friend-request-update' || data.type === 'friend-request-sent') {
      updatePendingCountUI(data.count);
      fetchPendingRequests(); // Refresh the pending requests list
    }
  } catch (error) {
    console.error('Mesaj işleme hatası:', error);
  }
});

// Update pending count UI
function updatePendingCountUI(count) {
  let countElement = document.getElementById('pending-request-count');
  
  if (!countElement) {
    countElement = document.createElement('span');
    countElement.id = 'pending-request-count';
    countElement.className = 'bg-red-500 text-white text-xs rounded-full w-5 h-5 flex items-center justify-center absolute top-0 right-0 transform translate-x-1/2 -translate-y-1/2 hidden';
    document.getElementById('pending-tab').appendChild(countElement);
  }

  if (count > 0) {
    countElement.textContent = count;
    countElement.classList.remove('hidden');
  } else {
    countElement.textContent = '0';
    countElement.classList.add('hidden');
  }
}

// Fetch pending requests
function fetchPendingRequests() {
  fetch('get_pending_requests.php')
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        updateRequestsUI(data.requests);
      } else {
        console.error('Failed to fetch requests:', data.error);
        showTempNotification('<?php echo $translations['ErrorLoadingRequests'] ?? 'İstekler yüklenemedi'; ?>: ' + data.error, 'error');
      }
    })
    .catch(error => {
      console.error('Error fetching pending requests:', error);
      showTempNotification('<?php echo $translations['ErrorLoadingRequests'] ?? 'İstekler yüklenemedi'; ?>', 'error');
    });
}

// Update the UI with fetched requests
function updateRequestsUI(requests) {
  const container = document.getElementById('friend-requests');
  container.innerHTML = '';

  if (requests.length === 0) {
    container.innerHTML = '<p class="text-gray-400 py-4 text-center"><?php echo $translations['NoPendingRequests'] ?? 'Bekleyen istek yok'; ?></p>';
    return;
  }

  requests.forEach(request => {
    const div = document.createElement('div');
    div.className = 'bg-gray-800 rounded-lg p-3 flex items-center justify-between';
    div.innerHTML = `
      <div class="flex items-center">
        <div class="w-10 h-10 bg-gray-700 rounded-full flex items-center justify-center">
          ${request.avatar_url ? 
            `<img src="${request.avatar_url}" alt="${request.username}'s avatar">` : 
            `<span class="text-white font-medium">${request.username.charAt(0).toUpperCase()}</span>`
          }
        </div>
        <span class="ml-3 text-white">${request.username}</span>
      </div>
      <div class="flex space-x-2">
        <button class="accept-request bg-green-500 text-white px-3 py-1 rounded hover:bg-green-600" data-sender-id="${request.id}">
          <?php echo $translations['Accept']; ?>
        </button>
        <button class="reject-request bg-red-500 text-white px-3 py-1 rounded hover:bg-red-600" data-sender-id="${request.id}">
          <?php echo $translations['Reject']; ?>
        </button>
      </div>
    `;
    container.appendChild(div);
  });

  // Attach jQuery handlers
  $('.accept-request').off('click').click(function() {
    const senderId = $(this).data('sender-id');
    const requestElement = $(this).closest('.bg-gray-800');
    
    $.post('find_user.php', {
      action: 'accept_request',
      sender_id: senderId
    })
    .done(function(response) {
      response = typeof response === 'string' ? JSON.parse(response) : response;
      if (response.success) {
        ws.send(JSON.stringify({
          type: 'friend-request-updated',
          senderId: String(senderId),
          receiverId: String(<?php echo $_SESSION['user_id']; ?>)
        }));
        requestElement.fadeOut(400, () => {
          location.reload(); // Reload page after accepting
        });
        showTempNotification('<?php echo $translations['RequestAccepted'] ?? 'İstek kabul edildi'; ?>', 'success');
      } else {
        showTempNotification(response.message || '<?php echo $translations['FailedToAccept'] ?? 'İstek kabul edilemedi'; ?>', 'error');
      }
    })
    .fail(function() {
      showTempNotification('<?php echo $translations['FailedToAccept'] ?? 'İstek kabul edilemedi'; ?>', 'error');
    });
  });

  $('.reject-request').off('click').click(function() {
    const senderId = $(this).data('sender-id');
    const requestElement = $(this).closest('.bg-gray-800');
    
    $.post('find_user.php', {
      action: 'reject_request',
      sender_id: senderId
    })
    .done(function(response) {
      response = typeof response === 'string' ? JSON.parse(response) : response;
      if (response.success) {
        ws.send(JSON.stringify({
          type: 'friend-request-updated',
          senderId: String(senderId),
          receiverId: String(<?php echo $_SESSION['user_id']; ?>)
        }));
        requestElement.fadeOut(400, () => {
          fetchPendingRequests(); // Refresh list instead of reloading
        });
        showTempNotification('<?php echo $translations['RequestRejected'] ?? 'İstek reddedildi'; ?>', 'success');
      } else {
        showTempNotification(response.message || '<?php echo $translations['FailedToReject'] ?? 'İstek reddedilemedi'; ?>', 'error');
      }
    })
    .fail(function() {
      showTempNotification('<?php echo $translations['FailedToReject'] ?? 'İstek reddedilemedi'; ?>', 'error');
    });
  });
}

// Add Friend Modal
const addFriendBtn = document.getElementById('add-friend-btn');
const addFriendModal = document.getElementById('add-friend-modal');
const cancelAddFriend = document.getElementById('cancel-add-friend');
const confirmAddFriend = document.getElementById('confirm-add-friend');

addFriendBtn.addEventListener('click', () => {
  addFriendModal.classList.remove('hidden');
  addFriendModal.classList.add('flex');
});

cancelAddFriend.addEventListener('click', () => {
  addFriendModal.classList.add('hidden');
  addFriendModal.classList.remove('flex');
  $('#friend-username').val(''); // Clear input
});

confirmAddFriend.addEventListener('click', async () => {
  const username = $('#friend-username').val().trim();
  
  if (!username) {
    showTempNotification('<?php echo $translations['EnterUsername'] ?? 'Lütfen bir kullanıcı adı girin'; ?>', 'error');
    return;
  }

  try {
    // Find user
    const response = await fetch('find_user.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: `action=find_user&username=${encodeURIComponent(username)}`
    });
    
    const data = await response.json();
    
    if (!data.success) {
      throw new Error(data.message || '<?php echo $translations['UserNotFound'] ?? 'Kullanıcı bulunamadı'; ?>');
    }
    
    // Check self-add
    if (data.user_id == <?php echo $_SESSION['user_id']; ?>) {
      throw new Error('<?php echo $translations['CannotAddSelf'] ?? 'Kendinizi arkadaş olarak ekleyemezsiniz'; ?>');
    }

    // Send friend request
    const requestResponse = await fetch('find_user.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: `action=send_request&receiver_id=${data.user_id}`
    });
    
    const requestData = await requestResponse.json();
    
    if (!requestData.success) {
      throw new Error(requestData.message || '<?php echo $translations['FailedToSendRequest'] ?? 'Arkadaşlık isteği gönderilemedi'; ?>');
    }

    // Send WebSocket notification
    ws.send(JSON.stringify({
      type: 'friend-request-sent',
      senderId: String(<?php echo $_SESSION['user_id']; ?>),
      receiverId: String(data.user_id)
    }));

    showTempNotification('<?php echo $translations['RequestSent'] ?? 'Arkadaşlık isteği gönderildi'; ?>', 'success');
    $('#friend-username').val('');
    addFriendModal.classList.add('hidden');
    // Optionally refresh sent requests

  } catch (error) {
    console.error('Error:', error);
    showTempNotification(error.message || '<?php echo $translations['ErrorOccurred'] ?? 'Bir hata oluştu'; ?>', 'error');
  }
});



function updateSentRequestsUI(requests) {
  const container = document.getElementById('sent-requests');
  container.innerHTML = '';

  if (requests.length === 0) {
    container.innerHTML = '<p class="text-gray-400 py-4 text-center"><?php echo $translations['SentRequest'] ?? 'Gönderilmiş istek yok'; ?></p>';
    return;
  }

  requests.forEach(request => {
    const div = document.createElement('div');
    div.className = 'bg-gray-800 rounded-lg p-3 flex items-center justify-between';
    div.innerHTML = `
      <div class="flex items-center">
        <div class="w-10 h-10 bg-gray-700 rounded-full flex items-center justify-center">
          ${request.avatar_url ? 
            `<img src="${request.avatar_url}" alt="${request.username}'s avatar">` : 
            `<span class="text-white font-medium">${request.username.charAt(0).toUpperCase()}</span>`
          }
        </div>
        <span class="ml-3 text-white">${request.username}</span>
      </div>
      <div class="flex space-x-2">
        <button class="cancel-request bg-gray-600 text-white px-3 py-1 rounded hover:bg-gray-700" data-receiver-id="${request.id}">
          <?php echo $translations['Cancelsend'] ?? 'İptal Et'; ?>
        </button>
      </div>
    `;
    container.appendChild(div);
  });

  // Attach cancel request handler
  $('.cancel-request').off('click').click(function() {
    const receiverId = $(this).data('receiver-id');
    const requestElement = $(this).closest('.bg-gray-800');
    
    $.post('find_user.php', {
      action: 'cancel_request',
      receiver_id: receiverId
    })
    .done(function(response) {
      response = typeof response === 'string' ? JSON.parse(response) : response;
      if (response.success) {
        ws.send(JSON.stringify({
          type: 'friend-request-updated',
          senderId: String(<?php echo $_SESSION['user_id']; ?>),
          receiverId: String(receiverId)
        }));
        requestElement.fadeOut(400, () => {
        });
        showTempNotification('<?php echo $translations['RequestCancelled'] ?? 'İstek iptal edildi'; ?>', 'success');
      } else {
        showTempNotification(response.message || '<?php echo $translations['FailedToCancel'] ?? 'İstek iptal edilemedi'; ?>', 'error');
      }
    })
    .fail(function() {
      showTempNotification('<?php echo $translations['FailedToCancel'] ?? 'İstek iptal edilemedi'; ?>', 'error');
    });
  });
}

// Initial fetch on page load
document.addEventListener('DOMContentLoaded', () => {
  fetchPendingRequests();
});




// Typing kullanıcılarını takip etmek için Map ve sabitler
const typingUsers = new Map();
const TYPING_TIMEOUT = 5000; // 5 saniye

// HTML yapısını bir kere oluşturun (mevcut kodunuzdan)
typingIndicator.innerHTML = `
  <div class="flex items-center gap-2 text-gray-400 text-sm">
    <div class="typing-animation flex items-center">
      <span></span>
      <span></span>
      <span></span>
    </div>
    <span class="typing-username"></span>
  </div>
`;

// Element referanslarını alın
const usernameSpan = typingIndicator.querySelector('.typing-username');
const translations = {
    typing: '<?php echo $translations['Typing']; ?>' // "yazıyor..." çevirisi
};

// Typing göstergesini güncelleyen fonksiyon
function updateTypingIndicator() {
    const usernames = Array.from(typingUsers.values()).map(user => user.username);
    if (usernames.length === 0) {
        typingIndicator.style.opacity = '0';
        setTimeout(() => {
            typingIndicator.style.display = 'none';
        }, 300);
    } else {
        typingIndicator.style.display = 'flex';
        typingIndicator.style.opacity = '1';
        let text;
        if (usernames.length === 1) {
            text = `${usernames[0]} ${translations.typing}`;
        } else if (usernames.length === 2) {
            text = `${usernames[0]} ve ${usernames[1]} yazıyor...`;
        } else {
            text = `${usernames.slice(0, 2).join(', ')} ve diğerleri yazıyor...`;
        }
        usernameSpan.textContent = text;
    }
}

// WebSocket mesaj işleyici
ws.addEventListener('message', (event) => {
    try {
        const data = JSON.parse(event.data);
        console.log('📩 Gelen WebSocket mesajı:', data);

        if (data.type === 'typing') {
            // Kendi typing mesajımızı yoksay
            if (String(data.senderId) === String(currentUserId)) {
                console.log('🛑 Kendi typing mesajı yoksayıldı:', data.senderId);
                return;
            }

            const isGroupChat = currentGroupId !== null;
            const isValidTyping = (
                (isGroupChat && data.groupId === currentGroupId) ||
                (!isGroupChat && String(data.senderId) === String(currentFriendId))
            );

            if (isValidTyping) {
                const userId = data.senderId;
                const username = data.username;

                if (data.isTyping) {
                    // Kullanıcıyı ekle veya güncelle
                    if (typingUsers.has(userId)) {
                        clearTimeout(typingUsers.get(userId).timeoutId);
                }
                    const timeoutId = setTimeout(() => {
                        typingUsers.delete(userId);
                        updateTypingIndicator();
                    }, TYPING_TIMEOUT);
                    typingUsers.set(userId, { username, timeoutId });
                } else {
                    // Kullanıcıyı kaldır
                    if (typingUsers.has(userId)) {
                        clearTimeout(typingUsers.get(userId).timeoutId);
                        typingUsers.delete(userId);
                    }
                }
                updateTypingIndicator();
            }
        } else if (data.type === 'message') {
            // Mesaj gönderen kullanıcıyı listeden çıkar
            const senderId = data.senderId;
            if (typingUsers.has(senderId)) {
                clearTimeout(typingUsers.get(senderId).timeoutId);
                typingUsers.delete(senderId);
                updateTypingIndicator();
            }
            // Diğer mesaj işleme kodları burada kalabilir
        }
    } catch (error) {
        console.error('🚨 Mesaj işleme hatası:', error);
    }
});

// Mevcut handleTyping fonksiyonu (değişmeden kalabilir)
function handleTyping(isTyping) {
    if (!currentFriendId && !currentGroupId) {
        console.log('🛑 Alıcı veya grup seçili değil, typing gönderilmedi');
        return;
    }

    const typingMessage = {
        type: 'typing',
        username: currentUsername,
        senderId: String(currentUserId),
        isTyping: isTyping
    };

    if (currentGroupId) {
        typingMessage.isGroup = true;
        typingMessage.groupId = currentGroupId;
    } else {
        typingMessage.receiverId = currentFriendId;
    }

    console.log('📤 Gönderilen typing mesajı:', typingMessage);
    ws.send(JSON.stringify(typingMessage));
}

// Mevcut olay dinleyicileri
let typingDebounce;
document.getElementById('message-input').addEventListener('input', () => {
    if (!currentFriendId && !currentGroupId) return;

    clearTimeout(typingDebounce);
    handleTyping(true);

    typingDebounce = setTimeout(() => {
        handleTyping(false);
    }, 1000);
});

document.getElementById('message-form').addEventListener('submit', () => {
    handleTyping(false);
});
// Global variables
let isInVoiceCall = false;
let currentChannelId = null;
let incomingCallerId = null;
let currentChatName = '';
let screenPeerConnection = null;
let screenStream = null;
let isMuted = false; // For mute functionality

// DOM elements
const voiceCallModal = document.getElementById('voice-call-modal');
const joinVoiceCallButton = document.getElementById('join-voice-call');
const leaveCallButton = document.getElementById('leave-call');
const callStatus = document.getElementById('call-status');
const toggleMicButton = document.getElementById('toggle-mic');
const callerMuteIcon = document.getElementById('caller-mute-icon');
const receiverMuteIcon = document.getElementById('receiver-mute-icon');

// User ID from PHP, with fallback
const userId = <?php echo isset($_SESSION['user_id']) ? json_encode($_SESSION['user_id']) : 'null'; ?>;
if (!userId) {
    console.error('🚨 User ID is not defined');
    alert('Hata: Kullanıcı kimliği eksik.');
}

// WebRTC configuration
const configuration = {
    iceServers: [
        { urls: 'stun:stun.l.google.com:19302' },
        { urls: 'stun:stun1.l.google.com:19302' },
        { urls: 'stun:stun2.l.google.com:19302' },
        { urls: 'stun:stun3.l.google.com:19302' },
        { urls: 'stun:stun4.l.google.com:19302' }
        // Add TURN server if needed
    ],
    iceTransportPolicy: 'all',
    bundlePolicy: 'max-bundle',
    rtcpMuxPolicy: 'require'
};

// Ensure DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    // Verify critical DOM elements
    if (!voiceCallModal || !joinVoiceCallButton || !leaveCallButton || !callStatus || !toggleMicButton || !callerMuteIcon || !receiverMuteIcon) {
        console.error('🚨 Required DOM elements not found:', {
            voiceCallModal,
            joinVoiceCallButton,
            leaveCallButton,
            callStatus,
            toggleMicButton,
            callerMuteIcon,
            receiverMuteIcon
        });
        alert('Hata: Gerekli arayüz öğeleri yüklenemedi.');
        return;
    }

    // Initialize Lucide icons
    lucide.createIcons();

    // Generate unique DM channel ID
    function generateDMChannelId(userId1, userId2) {
        const id1 = parseInt(userId1);
        const id2 = parseInt(userId2);
        if (isNaN(id1) || isNaN(id2)) {
            console.error(`🚨 Invalid userId or friendId: ${userId1}, ${userId2}`);
            return null;
        }
        const minId = Math.min(id1, id2);
        const maxId = Math.max(id1, id2);
        return `dm_${minId}_${maxId}`;
    }

    // Join voice call
    joinVoiceCallButton.addEventListener('click', () => {
        if (!currentFriendId) {
            alert('Lütfen arama yapmak için önce bir arkadaş sohbeti seçin.');
            return;
        }

        if (!isInVoiceCall) {
            currentChannelId = generateDMChannelId(userId, currentFriendId);
            if (!currentChannelId) {
                console.error('🚨 ChannelId oluşturulamadı');
                return;
            }

            // Update UI with friend's info
            const friendItem = document.querySelector(`.friend-item1[data-friend-id="${currentFriendId}"]`);
            const avatarSrc = friendItem ? friendItem.querySelector('img')?.src : '/avatars/default-avatar.png';
            document.getElementById('receiver-avatar').src = avatarSrc;
            document.getElementById('chat-username2').textContent = currentChatName;

            // Send call request
            ws.send(JSON.stringify({
                type: 'incoming-call',
                targetId: String(currentFriendId),
                channelId: currentChannelId,
                userId: userId
            }));

            // Show voice call modal
            voiceCallModal.classList.remove('hidden');
            voiceCallModal.classList.add('modal-visible');
            callStatus.textContent = `${currentChatName} aranıyor...`;

            console.log(`📞 ${currentFriendId} ID'li kullanıcıya arama isteği gönderildi, kanal: ${currentChannelId}`);
        }
    });

    // Leave voice call
    leaveCallButton.addEventListener('click', () => {
        if (isInVoiceCall) {
            ws.send(JSON.stringify({
                type: 'leave-dm-voice',
                friendId: currentFriendId,
                userId: userId,
                channelId: currentChannelId
            }));
            endVoiceCall();
        }
    });

    // Start WebRTC voice call
    async function startVoiceCall() {
        if (!currentChannelId) {
            currentChannelId = generateDMChannelId(userId, currentFriendId);
            if (!currentChannelId) {
                console.error('🚨 ChannelId oluşturulamadı');
                callStatus.textContent = 'Hata: Kanal oluşturulamadı.';
                return;
            }
        }

        isInVoiceCall = true;
        voiceCallModal.classList.remove('hidden');
        voiceCallModal.classList.add('modal-visible');
        callStatus.textContent = 'Sesli sohbete bağlanılıyor...';

        peerConnection = new RTCPeerConnection(configuration);

        peerConnection.oniceconnectionstatechange = () => {
            console.log('ℹ ICE Connection State:', peerConnection.iceConnectionState);
            if (peerConnection.iceConnectionState === 'connected') {
                callStatus.textContent = 'Bağlantı kuruldu. Konuşabilirsiniz!';
            } else if (peerConnection.iceConnectionState === 'failed') {
                callStatus.textContent = 'Bağlantı başarısız. Lütfen tekrar deneyin.';
                restartIce();
            }
        };

        peerConnection.ontrack = (event) => {
            const [remoteStream] = event.streams;
            const audioElement = document.createElement('audio');
            audioElement.srcObject = remoteStream;
            audioElement.autoplay = true;
            document.body.appendChild(audioElement);
            console.log('🎙 Karşı tarafın ses akışı alındı:', remoteStream);
        };

        peerConnection.onicecandidate = (event) => {
            if (event.candidate) {
                ws.send(JSON.stringify({
                    type: 'ice-candidate',
                    target: currentFriendId,
                    sender: userId,
                    channelId: currentChannelId,
                    candidate: event.candidate
                }));
            }
        };

        try {
            localStream = await navigator.mediaDevices.getUserMedia({ audio: true });
            localStream.getTracks().forEach(track => peerConnection.addTrack(track, localStream));
            console.log('🎙 Yerel ses akışı eklendi:', localStream);

            const offer = await peerConnection.createOffer();
            await peerConnection.setLocalDescription(offer);
            ws.send(JSON.stringify({
                type: 'voice-offer',
                target: currentFriendId,
                sender: userId,
                channelId: currentChannelId,
                sdp: peerConnection.localDescription
            }));
        } catch (err) {
            console.error('🚨 Ses akışı hatası:', err);
            callStatus.textContent = 'Mikrofon erişimi başarısız.';
        }
    }

    // ICE restart
    async function restartIce() {
        if (peerConnection) {
            peerConnection.restartIce();
            const offer = await peerConnection.createOffer({ iceRestart: true });
            await peerConnection.setLocalDescription(offer);
            ws.send(JSON.stringify({
                type: 'voice-offer',
                target: currentFriendId,
                sender: userId,
                channelId: currentChannelId,
                sdp: peerConnection.localDescription
            }));
        }
    }

    // End voice call
    function endVoiceCall() {
        isInVoiceCall = false;
        voiceCallModal.classList.remove('modal-visible');
        voiceCallModal.classList.add('hidden');
        callStatus.textContent = 'Bağlanıyor...';
        currentChannelId = null;

        if (localStream) {
            localStream.getTracks().forEach(track => track.stop());
            localStream = null;
        }
        if (peerConnection) {
            peerConnection.close();
            peerConnection = null;
        }
        stopCallMusic();
    }

    // Microphone toggle
    toggleMicButton.addEventListener('click', () => {
        if (!localStream) {
            console.warn('🚨 No local stream available for muting');
            return;
        }

        isMuted = !isMuted;
        localStream.getAudioTracks().forEach(track => {
            track.enabled = !isMuted;
        });

        // Update the mic icon by replacing the button's content
        toggleMicButton.innerHTML = `<i data-lucide="${isMuted ? 'mic-off' : 'mic'}" class="w-5 h-5"></i>`;
        lucide.createIcons();

        callerMuteIcon.classList.toggle('hidden', !isMuted);

        if (ws && ws.readyState === WebSocket.OPEN) {
            ws.send(JSON.stringify({
                type: 'voice-mute',
                channelId: currentChannelId,
                userId: userId,
                muted: isMuted
            }));
        }
    });

    // Start screen share
    async function startScreenShare() {
        if (!userId || !currentFriendId || !currentChannelId) {
            showTempNotification('Hata: Kullanıcı veya kanal bilgileri eksik.', 'error');
            console.error('Missing required variables:', { userId, currentFriendId, currentChannelId });
            return;
        }

        try {
            screenStream = await navigator.mediaDevices.getDisplayMedia({ video: true, audio: true });
            document.getElementById('local-screen').srcObject = screenStream;

            document.getElementById('screen-share-container').classList.remove('hidden');
            document.getElementById('toggle-screen-share').style.display = 'none';
            document.getElementById('stop-screen-share').style.display = 'inline-block';
            document.getElementById('screen-share-status').textContent = 'Ekran paylaşımı başlatıldı';

            screenPeerConnection = new RTCPeerConnection(configuration);

            screenStream.getTracks().forEach(track => screenPeerConnection.addTrack(track, screenStream));

            screenPeerConnection.ontrack = (event) => {
                document.getElementById('remote-screen').srcObject = event.streams[0];
            };

            screenPeerConnection.onicecandidate = (event) => {
                if (event.candidate) {
                    ws.send(JSON.stringify({
                        type: 'screen-ice-candidate',
                        target: currentFriendId,
                        sender: userId,
                        channelId: currentChannelId,
                        candidate: event.candidate
                    }));
                }
            };

            const offer = await screenPeerConnection.createOffer();
            await screenPeerConnection.setLocalDescription(offer);
            ws.send(JSON.stringify({
                type: 'screen-offer',
                target: currentFriendId,
                sender: userId,
                channelId: currentChannelId,
                offer: offer
            }));

            screenStream.getVideoTracks()[0].onended = stopScreenShare;
        } catch (error) {
            console.error('Ekran paylaşımı başlatılamadı:', error);
            showTempNotification('Ekran paylaşımı başlatılamadı: ' + error.message, 'error');
        }
    }

    // Stop screen share
    function stopScreenShare() {
        if (screenStream) {
            screenStream.getTracks().forEach(track => track.stop());
            screenStream = null;
        }
        if (screenPeerConnection) {
            screenPeerConnection.close();
            screenPeerConnection = null;
        }
        document.getElementById('local-screen').srcObject = null;
        document.getElementById('remote-screen').srcObject = null;
        document.getElementById('screen-share-container').classList.add('hidden');
        document.getElementById('toggle-screen-share').style.display = 'inline-block';
        document.getElementById('stop-screen-share').style.display = 'none';
        document.getElementById('screen-share-status').textContent = '';

        ws.send(JSON.stringify({
            type: 'screen-share-end',
            channelId: currentChannelId,
            userId: userId
        }));
    }

    // Handle screen offer
    async function handleScreenOffer(message) {
        if (!screenPeerConnection) {
            screenPeerConnection = new RTCPeerConnection(configuration);
            screenPeerConnection.ontrack = (event) => {
                document.getElementById('remote-screen').srcObject = event.streams[0];
                document.getElementById('screen-share-container').classList.remove('hidden');
                document.getElementById('screen-share-status').textContent = `${message.username} ekran paylaşıyor`;
            };
        }

        await screenPeerConnection.setRemoteDescription(new RTCSessionDescription(message.offer));
        const answer = await screenPeerConnection.createAnswer();
        await screenPeerConnection.setLocalDescription(answer);

        ws.send(JSON.stringify({
            type: 'screen-answer',
            target: message.sender,
            sender: userId,
            channelId: message.channelId,
            answer: answer
        }));

        screenPeerConnection.onicecandidate = (event) => {
            if (event.candidate) {
                ws.send(JSON.stringify({
                    type: 'screen-ice-candidate',
                    target: message.sender,
                    sender: userId,
                    channelId: message.channelId,
                    candidate: event.candidate
                }));
            }
        };
    }

    // Handle screen answer
    async function handleScreenAnswer(message) {
        if (screenPeerConnection) {
            await screenPeerConnection.setRemoteDescription(new RTCSessionDescription(message.answer));
        }
    }

    // Handle screen ICE candidate
    async function handleScreenIceCandidate(message) {
        if (screenPeerConnection) {
            await screenPeerConnection.addIceCandidate(new RTCIceCandidate(message.candidate));
        }
    }

    // Handle voice offer
    async function handleVoiceOffer(message) {
        if (!isInVoiceCall) {
            currentChannelId = message.channelId || generateDMChannelId(userId, message.sender);
            currentFriendId = message.sender;
            startVoiceCall();
        }
        await peerConnection.setRemoteDescription(new RTCSessionDescription(message.sdp));
        const answer = await peerConnection.createAnswer();
        await peerConnection.setLocalDescription(answer);
        ws.send(JSON.stringify({
            type: 'voice-answer',
            target: message.sender,
            sender: userId,
            channelId: currentChannelId,
            sdp: peerConnection.localDescription
        }));
    }

    // Handle voice answer
    async function handleVoiceAnswer(message) {
        if (peerConnection) {
            await peerConnection.setRemoteDescription(new RTCSessionDescription(message.sdp));
        }
    }

    // Handle ICE candidate
    async function handleIceCandidate(message) {
        if (peerConnection) {
            await peerConnection.addIceCandidate(new RTCIceCandidate(message.candidate));
        }
    }

    // Call music handling
    const callMusic = [
        document.getElementById('call-music-1'),
        document.getElementById('call-music-2')
    ];

    function playCallMusic() {
        callMusic.forEach(music => {
            music.pause();
            music.currentTime = 0;
        });

        const randomIndex = Math.floor(Math.random() * callMusic.length);
        const selectedMusic = callMusic[randomIndex];
        selectedMusic.play().catch(error => {
            console.error('Error playing call music:', error);
            showTempNotification('Müzik çalınırken bir hata oluştu.', 'error');
        });
    }

    function stopCallMusic() {
        callMusic.forEach(music => {
            music.pause();
            music.currentTime = 0;
        });
    }

    // WebSocket message handler
    ws.addEventListener('message', (event) => {
        const message = JSON.parse(event.data);

        switch (message.type) {
            case 'auth':
                currentUserId = String(message.userId);
                console.log('✅ Auth tamamlandı, currentUserId:', currentUserId);
                break;
            case 'incoming-call':
                incomingCallerId = message.callerId;
                currentFriendId = message.callerId;
                currentChannelId = message.channelId || generateDMChannelId(userId, currentFriendId);

                console.log('📥 Alınan incoming-call mesajı:', JSON.stringify(message, null, 2));
                console.log('ℹ callerUsername:', message.callerUsername);

                document.getElementById('voice-call-modal').classList.add('hidden');
                document.getElementById('screen-share-container').classList.add('hidden');

                const incomingModal = document.getElementById('incoming-call-modal');
                incomingModal.classList.remove('hidden');
                incomingModal.classList.add('modal-visible');
                incomingModal.style.zIndex = '1000000';
                document.getElementById('incoming-caller-avatar').src = message.callerAvatar || '/avatars/default-avatar.png';
                document.getElementById('incoming-caller-username').textContent = message.callerUsername || `User-${message.callerId}`;

                console.log('ℹ Modal içeriği (incoming-caller-username):', document.getElementById('incoming-caller-username').textContent);
                setTimeout(() => {
                    console.log('ℹ Modal içeriği (gecikmeli kontrol):', document.getElementById('incoming-caller-username').textContent);
                }, 1000);

                console.log('ℹ incoming-call-modal gösteriliyor, sınıflar:', incomingModal.className);
                console.log('ℹ Modal z-index:', incomingModal.style.zIndex);
                console.log('ℹ Modal display:', getComputedStyle(incomingModal).display);
                console.log(`📞 Gelen arama: ${message.callerId}, kanal: ${currentChannelId}`);
                playCallMusic();
                break;
            case 'call-accepted':
                console.log('ℹ call-accepted alındı, accepterId:', message.accepterId, 'channelId:', message.channelId);
                if (ws.readyState !== WebSocket.OPEN) {
                    console.error('🚨 WebSocket bağlantısı açık değil, readyState:', ws.readyState);
                    callStatus.textContent = 'Hata: Sunucu bağlantısı kesildi.';
                    return;
                }

                currentChannelId = message.channelId || generateDMChannelId(userId, message.accepterId);
                if (!currentChannelId) {
                    console.error('🚨 ChannelId oluşturulamadı, userId:', userId, 'accepterId:', message.accepterId);
                    callStatus.textContent = 'Hata: Kanal oluşturulamadı.';
                    return;
                }
                currentFriendId = message.accepterId;

                const joinDmVoiceMessage = {
                    type: 'join-dm-voice',
                    friendId: String(message.accepterId),
                    userId: userId,
                    channelId: currentChannelId,
                    avatar_url: <?php echo isset($_SESSION['avatar_url']) ? json_encode($_SESSION['avatar_url']) : '"avatars/default-avatar.png"'; ?>
                };
                ws.send(JSON.stringify(joinDmVoiceMessage));
                console.log('📤 join-dm-voice gönderildi:', joinDmVoiceMessage);

                document.getElementById('receiver-avatar').src = message.avatar_url || 'avatars/default-avatar.png';
                document.getElementById('chat-username2').textContent = message.accepterUsername || `User-${message.accepterId}`;
                voiceCallModal.classList.remove('hidden');
                voiceCallModal.classList.add('modal-visible');
                callStatus.textContent = 'Bağlantı kuruluyor...';

                startVoiceCall();
                break;
            case 'call-declined':
                console.log('❌ Arama reddedildi:', message);
                callStatus.textContent = `${message.declinerUsername} aramayı reddetti.`;
                setTimeout(() => {
                    voiceCallModal.classList.add('hidden');
                }, 3000);
                stopCallMusic();
                break;
            case 'call-unavailable':
                console.log('⚠️ Aranan kullanıcı uygun değil:', message);
                callStatus.textContent = `Aradığınız kullanıcı şu an çevrimdışı.`;
                setTimeout(() => {
                    voiceCallModal.classList.add('hidden');
                }, 3000);
                stopCallMusic();
                break;
            case 'voice-offer':
                handleVoiceOffer(message);
                break;
            case 'voice-answer':
                handleVoiceAnswer(message);
                break;
            case 'ice-candidate':
                handleIceCandidate(message);
                break;
            case 'voice-user-joined':
                callStatus.textContent = `${message.username || 'Bilinmeyen Kullanıcı'} sohbete katıldı!`;
                console.log('ℹ voice-user-joined:', message);
                break;
            case 'voice-user-left':
                callStatus.textContent = `${message.username || 'Bilinmeyen Kullanıcı'} sohbetten ayrıldı.`;
                endVoiceCall();
                break;
            case 'screen-offer':
                handleScreenOffer(message);
                break;
            case 'screen-answer':
                handleScreenAnswer(message);
                break;
            case 'screen-ice-candidate':
                handleScreenIceCandidate(message);
                break;
            case 'screen-share-started':
                document.getElementById('screen-share-status').textContent = `${message.username} ekran paylaşıyor`;
                break;
            case 'screen-share-ended':
                document.getElementById('screen-share-status').textContent = '';
                document.getElementById('screen-share-container').classList.add('hidden');
                break;
            case 'voice-mute':
                if (message.userId !== userId) {
                    receiverMuteIcon.classList.toggle('hidden', !message.muted);
                }
                break;
            case 'error':
                console.error('🚨 Sunucu hatası:', message.message);
                callStatus.textContent = `Hata: ${message.message}`;
                break;
            default:
                console.log('ℹ Bilinmeyen mesaj tipi:', message.type);
                break;
        }
    });

    // Accept call
    document.getElementById('accept-call-btn').addEventListener('click', () => {
        console.log('ℹ accept-call-btn tıklandı, incomingCallerId:', incomingCallerId, 'userId:', userId);
        if (!incomingCallerId) {
            console.error('🚨 incomingCallerId tanımlı değil');
            callStatus.textContent = 'Hata: Arama kimliği eksik.';
            return;
        }

        if (ws.readyState !== WebSocket.OPEN) {
            console.error('🚨 WebSocket bağlantısı açık değil, readyState:', ws.readyState);
            callStatus.textContent = 'Hata: Sunucu bağlantısı kesildi.';
            return;
        }

        currentChannelId = generateDMChannelId(userId, incomingCallerId);
        if (!currentChannelId) {
            console.error('🚨 ChannelId oluşturulamadı, userId:', userId, 'incomingCallerId:', incomingCallerId);
            callStatus.textContent = 'Hata: Kanal oluşturulamadı.';
            return;
        }

        const callAcceptedMessage = {
            type: 'call-accepted',
            targetId: String(incomingCallerId),
            channelId: currentChannelId,
            userId: userId,
            username: document.getElementById('chat-username2')?.textContent || `User-${userId}`,
            avatar_url: <?php echo isset($_SESSION['avatar_url']) ? json_encode($_SESSION['avatar_url']) : '"avatars/default-avatar.png"'; ?>
        };
        ws.send(JSON.stringify(callAcceptedMessage));
        console.log('📤 call-accepted gönderildi:', callAcceptedMessage);

        const joinDmVoiceMessage = {
            type: 'join-dm-voice',
            friendId: String(incomingCallerId),
            userId: userId,
            channelId: currentChannelId,
            avatar_url: <?php echo isset($_SESSION['avatar_url']) ? json_encode($_SESSION['avatar_url']) : '"avatars/default-avatar.png"'; ?>
        };
        ws.send(JSON.stringify(joinDmVoiceMessage));
        console.log('📤 join-dm-voice gönderildi:', joinDmVoiceMessage);

        document.getElementById('receiver-avatar').src = document.getElementById('incoming-caller-avatar').src;
        document.getElementById('chat-username2').textContent = document.getElementById('incoming-caller-username').textContent;
        voiceCallModal.classList.remove('hidden');
        voiceCallModal.classList.add('modal-visible');
        callStatus.textContent = 'Bağlantı kuruluyor...';

        startVoiceCall();
        document.getElementById('incoming-call-modal').classList.add('hidden');
        stopCallMusic();
        incomingCallerId = null;
    });

    // Decline call
    document.getElementById('decline-call-btn').addEventListener('click', () => {
        if (incomingCallerId) {
            currentChannelId = generateDMChannelId(userId, incomingCallerId);
            if (!currentChannelId) {
                console.error('🚨 ChannelId oluşturulamadı');
                return;
            }

            ws.send(JSON.stringify({
                type: 'call-declined',
                targetId: String(incomingCallerId),
                channelId: currentChannelId,
                userId: userId
            }));

            document.getElementById('incoming-call-modal').classList.add('hidden');
            stopCallMusic();
            incomingCallerId = null;
            console.log('❌ decline-call-btn tıklandı, call-declined gönderildi');
        }
    });

    // Button event listeners
    document.getElementById('toggle-screen-share').addEventListener('click', startScreenShare);
    document.getElementById('stop-screen-share').addEventListener('click', stopScreenShare);

    // WebSocket close handler
    ws.onclose = () => {
        if (isInVoiceCall) {
            endVoiceCall();
        }
        stopScreenShare();
        console.log('🔌 WebSocket bağlantısı kapandı');
    };
});

// Dosya yükleme animasyonu
const fileInput = document.getElementById('file-input');
if (fileInput) {
    fileInput.addEventListener('change', function() {
        const fileName = this.files[0]?.name;
        if (fileName) {
            const fileLabel = document.querySelector('label[for="file-input"]');
            if (fileLabel) {
                fileLabel.innerHTML = `<i data-lucide="check-circle" class="w-5 h-5 text-green-500 animate-pulse"></i>`;
                
                // 2 saniye sonra eski haline dön
                setTimeout(() => {
                    fileLabel.innerHTML = '<i data-lucide="paperclip" class="w-5 h-5"></i>';
                    lucide.createIcons();
                }, 2000);
            }
        }
    });
}

document.getElementById('file-input').addEventListener('change', function() {
    const files = Array.from(this.files);
    const previewContainer = document.getElementById('file-preview-container');
    const previewContent = document.getElementById('preview-content');
    
    previewContent.innerHTML = '';
    
    if(files.length > 0) {
        // İlk 3 dosyayı göster
        const filesToShow = files.slice(0, 1);
        const remainingFiles = files.length - 1;
        
        filesToShow.forEach((file, index) => {
            const fileElement = document.createElement('div');
            fileElement.className = 'file-preview-item relative group mb-2';
            
            const reader = new FileReader();
            reader.onload = function(e) {
                let content = '';
                if(file.type.startsWith('image/')) {
                    content = `
                        <img src="${e.target.result}" 
                             class="preview-image object-cover rounded-lg"
                             alt="${file.name}">
                    `;
                } else if(file.type.startsWith('video/')) {
                    content = `
                        <video controls class="preview-video rounded-lg">
                            <source src="${e.target.result}" type="${file.type}">
                        </video>
                    `;
                } else {
                    content = `
                        <div class="flex items-center p-2 bg-gray-700 rounded-lg">
                            <i data-lucide="file" class="w-8 h-8 mr-2 text-blue-400"></i>
                            <span class="text-gray-300 text-sm truncate">${file.name}</span>
                        </div>
                    `;
                }
                
                fileElement.innerHTML = `
                    <div class="relative">
                        ${content}
                        <button onclick="removeFilePreview(${index})" 
                                class="absolute -top-2 -right-2 bg-red-500 text-white rounded-full w-5 h-5 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity">
                            ×
                        </button>
                    </div>
                `;
                lucide.createIcons();
            };
            reader.readAsDataURL(file);
            previewContent.appendChild(fileElement);
        });

        // 3'ten fazla dosya varsa +X göster
        if(files.length > 1) {
            const remainingBox = document.createElement('div');
            remainingBox.className = 'flex items-center justify-center p-3 bg-gray-800 rounded-lg border-2 border-dashed border-gray-600 hover:border-blue-400 transition-colors cursor-pointer';
            remainingBox.innerHTML = `
                <span class="text-blue-400 font-medium text-lg">+${remainingFiles}</span>
            `;
            previewContent.appendChild(remainingBox);
        }
        
        previewContainer.classList.remove('hidden');
    } else {
        previewContainer.classList.add('hidden');
    }
});

// Dosya kaldırma fonksiyonu
function removeFilePreview(index) {
    const fileInput = document.getElementById('file-input');
    const files = Array.from(fileInput.files);
    files.splice(index, 1);
    
    const dataTransfer = new DataTransfer();
    files.forEach(file => dataTransfer.items.add(file));
    fileInput.files = dataTransfer.files;
    
    fileInput.dispatchEvent(new Event('change'));
}

// Tüm önizlemeleri temizleme
document.getElementById('cancel-file').addEventListener('click', function(e) {
    e.preventDefault();
    document.getElementById('file-input').value = '';
    document.getElementById('file-preview-container').classList.add('hidden');
    document.getElementById('preview-content').innerHTML = '';
});

    function toggleOnboarding() {
        const onboardingContent = document.getElementById('onboarding-content');
        const toggleIcon = document.getElementById('onboarding-toggle-icon');
        const onboardingHeader = document.getElementById('onboarding-header');

        if (onboardingContent.classList.contains('hidden')) {
            // Açılıyor
            onboardingContent.classList.remove('hidden');
            toggleIcon.setAttribute('data-lucide', 'chevron-up');
            onboardingHeader.style.backgroundColor = '#2a2a2a'; // Açıkken arka planı kapsayıcıyla aynı yap
            localStorage.setItem('onboardingOpen', 'true'); // Durumu kaydet
        } else {
            // Kapanıyor
            onboardingContent.classList.add('hidden');
            toggleIcon.setAttribute('data-lucide', 'chevron-down');
            onboardingHeader.style.backgroundColor = '#3f3f3f'; // Kapalıyken daha belirgin arka plan
            localStorage.setItem('onboardingOpen', 'false'); // Durumu kaydet
        }
        lucide.createIcons(); // Yeni ikon durumunu güncelle
    }

    // Başlangıçta "Keşfet" bölümünün durumunu yükle
    document.addEventListener('DOMContentLoaded', () => {
        const onboardingContent = document.getElementById('onboarding-content');
        const toggleIcon = document.getElementById('onboarding-toggle-icon');
        const onboardingHeader = document.getElementById('onboarding-header');
        const onboardingSection = document.querySelector('.onboarding-section');

        // Onboarding'in kalıcı olarak kapatılıp kapatılmadığını kontrol et
        const isPermanentlyDisabled = localStorage.getItem('onboardingDisabled') === 'true';
        if (isPermanentlyDisabled) {
            onboardingSection.classList.add('hidden'); // Bölümü tamamen gizle
            return; // Fonksiyonu burada sonlandır
        }

        // Açık/kapalı durumunu localStorage'dan yükle
        const onboardingOpen = localStorage.getItem('onboardingOpen');

        if (onboardingOpen === 'false') {
            onboardingContent.classList.add('hidden');
            toggleIcon.setAttribute('data-lucide', 'chevron-down');
            onboardingHeader.style.backgroundColor = '#3f3f3f'; // Kapalıyken daha belirgin arka plan
        } else {
            // Varsayılan olarak açık (veya 'true' ise)
            onboardingContent.classList.remove('hidden');
            toggleIcon.setAttribute('data-lucide', 'chevron-up');
            onboardingHeader.style.backgroundColor = '#2a2a2a'; 
        }
        lucide.createIcons();
    });

    // Onboarding'i kalıcı olarak kapatma uyarısını göster
    function showDisableOnboardingWarning() {
        document.getElementById('disable-onboarding-modal').classList.remove('hidden');
        lucide.createIcons();
    }

    // Onboarding'i kalıcı olarak kapatma uyarısını gizle
    function closeDisableOnboardingWarning() {
        document.getElementById('disable-onboarding-modal').classList.add('hidden');
    }

    // Onboarding'i kalıcı olarak devre dışı bırak
    function disableOnboardingPermanently() {
        const disableButton = document.querySelector('#disable-onboarding-modal #disable-confirm-button');
        const disableText = document.getElementById('disable-confirm-text');
        const disableSpinner = document.getElementById('disable-confirm-spinner');
        const onboardingSection = document.querySelector('.onboarding-section');

        disableText.classList.add('hidden');
        disableSpinner.classList.remove('hidden');
        // disableButton.disabled = true; // Butonu devre dışı bırakmak istersen

        // Gerçek dünyada burada bir sunucu tarafı isteği gönderilir
        // Örneğin: fetch('/api/disable-onboarding', { method: 'POST' });

        // Basitlik için hemen kapatma işlemi yapıyoruz
        localStorage.setItem('onboardingDisabled', 'true'); // Kalıcı olarak kapatıldığını işaretle
        localStorage.removeItem('onboardingOpen'); // Açık/kapalı durumunu sıfırla

        setTimeout(() => {
            onboardingSection.classList.add('hidden'); // Bölümü tamamen gizle
            closeDisableOnboardingWarning(); // Uyarı modalını kapat
            // İstersen burada bir bildirim gösterebilirsin
        }, 500); // Küçük bir gecikme ekleyelim
    }


    // Grup oluşturma modalını açma/kapama
    function openCreateGroupModal() {
        document.getElementById('create-group-modal').classList.remove('hidden');
    }

    function closeCreateGroupModal() {
        document.getElementById('create-group-modal').classList.add('hidden');
        document.getElementById('create-group-form').reset(); // Formu sıfırla
        document.getElementById('form-message').classList.add('hidden'); // Mesajı gizle
        document.getElementById('selected-members').innerHTML = ''; // Seçilen üyeleri temizle
        document.getElementById('selected-count').textContent = '0'; // Seçilen sayısını sıfırla
        document.getElementById('group-avatar-preview').innerHTML = '<i data-lucide="image" class="w-6 h-6 text-gray-400"></i>'; // Avatar önizlemesini sıfırla
        document.getElementById('group-avatar').value = ''; // Input file değerini sıfırla

        // Tüm checkbox'ların işaretini kaldır
        const checkboxes = document.querySelectorAll('#friend-selection input[type="checkbox"]');
        checkboxes.forEach(checkbox => {
            checkbox.checked = false;
        });
        lucide.createIcons(); // İkonları yeniden oluştur
    }

    // Grup avatarı önizlemesi
    function previewGroupAvatar(input) {
        const preview = document.getElementById('group-avatar-preview');
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                preview.innerHTML = `<img src="${e.target.result}" class="w-full h-full object-cover">`;
            };
            reader.readAsDataURL(input.files[0]);
        } else {
            preview.innerHTML = '<i data-lucide="image" class="w-6 h-6 text-gray-400"></i>';
            lucide.createIcons(); // İkonları yeniden oluştur
        }
    }

    // Seçilen üyeleri güncelleme
    function updateSelectedMembers() {
        const selectedMembersContainer = document.getElementById('selected-members');
        const checkboxes = document.querySelectorAll('#friend-selection input[type="checkbox"]:checked');
        const selectedCountSpan = document.getElementById('selected-count');
        
        selectedMembersContainer.innerHTML = ''; // Önceki üyeleri temizle
        let selectedUsernames = [];

        checkboxes.forEach(checkbox => {
            const friendId = checkbox.value;
            const friendUsername = checkbox.dataset.username;
            const friendAvatar = checkbox.dataset.avatar;

            selectedUsernames.push(friendUsername);

            const memberTag = document.createElement('div');
            memberTag.classList.add('flex', 'items-center', 'bg-[#3a3a3a]', 'rounded-full', 'pl-1', 'pr-3', 'py-1', 'text-sm', 'text-white');
            memberTag.innerHTML = `
                <div class="w-5 h-5 rounded-full bg-gray-600 flex items-center justify-center overflow-hidden mr-1">
                    ${friendAvatar ? `<img src="${friendAvatar}" class="w-full h-full object-cover">` : `<span class="text-white text-xs">${friendUsername.substring(0, 1).toUpperCase()}</span>`}
                </div>
                <span>${friendUsername}</span>
                <button type="button" class="ml-1 text-gray-400 hover:text-white" onclick="removeSelectedMember('${friendId}')">
                    <i data-lucide="x" class="w-4 h-4"></i>
                </button>
            `;
            selectedMembersContainer.appendChild(memberTag);
        });

        selectedCountSpan.textContent = checkboxes.length;
        lucide.createIcons(); // Yeni eklenen ikonları oluştur
    }

    // Seçilen üyeyi kaldırma
    function removeSelectedMember(friendId) {
        const checkbox = document.querySelector(`#friend-selection input[value="${friendId}"]`);
        if (checkbox) {
            checkbox.checked = false;
            updateSelectedMembers(); // Listeyi güncelle
        }
    }

    // Arkadaşları filtreleme
    function filterFriends() {
        const searchInput = document.getElementById('friend-search').value.toLowerCase();
        const friendLabels = document.querySelectorAll('#friend-selection label');

        friendLabels.forEach(label => {
            const usernameSpan = label.querySelector('span.text-white');
            if (usernameSpan) {
                const username = usernameSpan.textContent.toLowerCase();
                if (username.includes(searchInput)) {
                    label.style.display = 'flex'; // Görüntüle
                } else {
                    label.style.display = 'none'; // Gizle
                }
            }
        });
    }

    // Tüm arkadaşları seçme
    function selectAllFriends() {
        const checkboxes = document.querySelectorAll('#friend-selection input[type="checkbox"]');
        checkboxes.forEach(checkbox => {
            checkbox.checked = true;
        });
        updateSelectedMembers();
    }

    // Tüm arkadaşların seçimini kaldırma
    function deselectAllFriends() {
        const checkboxes = document.querySelectorAll('#friend-selection input[type="checkbox"]');
        checkboxes.forEach(checkbox => {
            checkbox.checked = false;
        });
        updateSelectedMembers();
    }

    // Form gönderimi (örnek, gerçek bir AJAX isteği gerektirecektir)
    document.getElementById('create-group-form').addEventListener('submit', function(e) {
        e.preventDefault();
        
        const groupName = document.getElementById('group-name').value;
        const selectedMembers = Array.from(document.querySelectorAll('#friend-selection input[type="checkbox"]:checked')).map(cb => cb.value);
        const groupAvatar = document.getElementById('group-avatar').files[0];
        const formMessage = document.getElementById('form-message');
        const createButton = document.getElementById('create-group-button');
        const createText = document.getElementById('create-group-text');
        const createSpinner = document.getElementById('create-group-spinner');

        if (!groupName) {
            formMessage.textContent = 'Grup adı boş bırakılamaz!';
            formMessage.classList.remove('hidden', 'bg-green-100', 'text-green-800');
            formMessage.classList.add('bg-red-100', 'text-red-800');
            return;
        }

        // Spinner'ı göster, metni gizle ve butonu devre dışı bırak
        createText.classList.add('hidden');
        createSpinner.classList.remove('hidden');
        createButton.disabled = true;

        // Burada AJAX isteği yapmalısın
        // Örneğin:
        const formData = new FormData();
        formData.append('group_name', groupName);
        formData.append('members', JSON.stringify(selectedMembers));
        if (groupAvatar) {
            formData.append('group_avatar', groupAvatar);
        }

        fetch('create_group.php', { // Bu URL'yi kendi sunucu tarafı betiğine göre ayarla
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            // Spinner'ı gizle, metni göster ve butonu etkinleştir
            createText.classList.remove('hidden');
            createSpinner.classList.add('hidden');
            createButton.disabled = false;

            if (data.success) {
                formMessage.textContent = data.message || 'Grup başarıyla oluşturuldu!';
                formMessage.classList.remove('hidden', 'bg-red-100', 'text-red-800');
                formMessage.classList.add('bg-green-100', 'text-green-800');
                // Modalı kapatabilir veya başka bir işlem yapabilirsin
                setTimeout(closeCreateGroupModal, 2000); // 2 saniye sonra kapat
            } else {
                formMessage.textContent = data.message || 'Grup oluşturulurken bir hata oluştu.';
                formMessage.classList.remove('hidden', 'bg-green-100', 'text-green-800');
                formMessage.classList.add('bg-red-100', 'text-red-800');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            // Spinner'ı gizle, metni göster ve butonu etkinleştir
            createText.classList.remove('hidden');
            createSpinner.classList.add('hidden');
            createButton.disabled = false;

            formMessage.textContent = 'Sunucuyla iletişim kurulurken bir hata oluştu.';
            formMessage.classList.remove('hidden', 'bg-green-100', 'text-green-800');
            formMessage.classList.add('bg-red-100', 'text-red-800');
        });
    });

    // Arkadaş seçenekleri menüsü
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.friend-item').forEach(item => {
            const friendId = item.dataset.friendId;
            const moreOptionsBtn = document.getElementById(`more-options-${friendId}`);
            const optionsMenu = document.getElementById(`options-menu-${friendId}`);

            if (moreOptionsBtn) {
                moreOptionsBtn.addEventListener('click', (event) => {
                    event.stopPropagation(); // Olayın diğer elemanlara yayılmasını engelle
                    // Tüm diğer açık menüleri kapat
                    document.querySelectorAll('.absolute.right-0.mt-2.w-48.bg-gray-800.rounded-md.shadow-lg:not(#options-menu-' + friendId + ')').forEach(menu => {
                        menu.classList.add('hidden');
                    });
                    optionsMenu.classList.toggle('hidden');
                });
            }

            // Sayfanın herhangi bir yerine tıklayınca menüyü kapat
            document.addEventListener('click', (event) => {
                if (!optionsMenu.contains(event.target) && !moreOptionsBtn.contains(event.target)) {
                    optionsMenu.classList.add('hidden');
                }
            });

            // Menüdeki butonlara tıklama olayları (örnek amaçlı)
            optionsMenu.querySelectorAll('button').forEach(button => {
                button.addEventListener('click', (event) => {
                    const action = event.target.dataset.action;
                    const id = event.target.dataset.friendId;
                    alert(`${id} ID'li arkadaş için "${action}" eylemi tetiklendi.`);
                    optionsMenu.classList.add('hidden'); // Eylem sonrası menüyü kapat
                });
            });
        });
    });


//Mobil kaydırma

const sidebar = document.getElementById("movesidebar");
const leftPanel = document.getElementById("left-panel");

function enableSwipeSidebar() {
  const sidebarWidth = sidebar.offsetWidth;

  let isDragging = false;
  let startX = 0;
  let currentTranslate = sidebarWidth;
  let previousTranslate = sidebarWidth;

  // Başlangıç pozisyonu
  sidebar.style.width = `${sidebarWidth}px`;
  sidebar.style.transform = `translateX(${sidebarWidth}px)`;
  sidebar.style.transition = 'transform 0.1s ease-out';

  // Ortak handler'lar
  function handleTouchStart(e) {
    startX = e.touches[0].clientX;
    isDragging = true;
    previousTranslate = currentTranslate;
    sidebar.style.transition = 'none';
  }

  function handleTouchMove(e) {
    if (!isDragging) return;

    const currentX = e.touches[0].clientX;
    const diff = currentX - startX;
    currentTranslate = previousTranslate + diff;

    // Sınırlar
    if (currentTranslate < 0) currentTranslate = 0;
    if (currentTranslate > sidebarWidth) currentTranslate = sidebarWidth;

    sidebar.style.transform = `translateX(${currentTranslate}px)`;
  }

  function handleTouchEnd() {
    isDragging = false;
    sidebar.style.transition = 'transform 0.2s ease-out';

    const threshold = sidebarWidth * 0.5;

    if (currentTranslate < threshold) {
      openSidebar();
    } else {
      closeSidebar();
    }
  }

  function openSidebar() {
    currentTranslate = 0;
    sidebar.style.transform = 'translateX(0)';
  }

  function closeSidebar() {
    currentTranslate = sidebarWidth;
    sidebar.style.transform = `translateX(${sidebarWidth}px)`;
  }

  // Sadece sürükleme için passive:false — tıklamaya engel olmaz
  const listeners = [
    { el: leftPanel, type: "touchstart", fn: handleTouchStart },
    { el: leftPanel, type: "touchmove", fn: handleTouchMove },
    { el: leftPanel, type: "touchend", fn: handleTouchEnd },
    { el: sidebar,    type: "touchstart", fn: handleTouchStart },
    { el: sidebar,    type: "touchmove", fn: handleTouchMove },
    { el: sidebar,    type: "touchend", fn: handleTouchEnd },
  ];

  // Ekle
  listeners.forEach(({ el, type, fn }) => {
    el.addEventListener(type, fn, { passive: false });
  });
}

//  Ekran küçükse başlat
if (window.innerWidth <= 768) {
  enableSwipeSidebar();
}





function updateSidebarWidth() {
    const solDiv = document.getElementById('server-sidebar');
    const ortaDiv = document.getElementById('left-panel');
    const movesidebar = document.getElementById('movesidebar');

    // Sol ve orta divlerin genişliklerini al
    const solWidth = solDiv.offsetWidth;
    const ortaWidth = ortaDiv.offsetWidth;

    // Sağ divin genişliğini ayarla
    movesidebar.style.width = `calc(100% - (${solWidth}px + ${ortaWidth}px))`;
}

function updateCustomWidth() {
    const solDiv = document.getElementById('server-sidebar');
    const ortaDiv = document.getElementById('left-panel');
    const customwidth = document.querySelector('.custom-width');

    // Sol ve orta divlerin genişliklerini al
    const solWidth = solDiv.offsetWidth;
    const ortaWidth = ortaDiv.offsetWidth;

    // Sağ divin genişliğini ayarla
    customwidth.style.width = `calc(100% - (${solWidth}px + ${ortaWidth}px))`;
}

  function handleResize() {
    const isLargeScreen = window.matchMedia('(min-width: 769px)').matches;

    if (isLargeScreen) {
        updateSidebarWidth();
        updateCustomWidth();
    } else {
        // Geniş ekran dışındaki durumlarda genişlik sıfırla (isteğe bağlı)
        const movesidebar = document.getElementById('movesidebar');
        const customwidth = document.querySelector('.custom-width');

        if (movesidebar) movesidebar.style.width = '';
        if (customwidth) customwidth.style.width = '';
    }
} 
window.addEventListener('load', handleResize);
window.addEventListener('resize', handleResize);
let leavingGroupId = null;

function openLeaveGroupModal(groupId) {
    leavingGroupId = groupId;
    document.getElementById('leave-group-modal').classList.remove('hidden');
}

function closeLeaveGroupModal() {
    leavingGroupId = null;
    document.getElementById('leave-group-modal').classList.add('hidden');
}

async function confirmLeaveGroup() {
    if (!leavingGroupId) return;

    try {
        const response = await fetch('leave_group.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ group_id: leavingGroupId })
        });
        
        const result = await response.json();
        if (result.success) {
            location.reload(); // Sayfayı yenile
        } else {
            alert(result.error || 'Gruptan ayrılırken hata oluştu');
        }
    } catch (error) {
        console.error('Hata:', error);
        alert('Bir hata oluştu');
    } finally {
        closeLeaveGroupModal();
    }
}
document.getElementById('leave-group-modal').addEventListener('click', function(e) {
    if (e.target === this) closeLeaveGroupModal();
});
lucide.createIcons(); // Tüm ikonları yeniden yükle
let currentGroupIdForMembers = null;

async function showGroupMembers() {
    console.log('showGroupMembers called with currentGroupId:', currentGroupId);

    if (!currentGroupId) {
        console.error('Group ID not set');
        showTempNotification('<?php echo addslashes($translations['groups']['no_group_selected'] ?? 'Grup seçilmedi'); ?>', 'error');
        return;
    }

    try {
        const modal = document.getElementById('group-members-modal');
        const membersList = document.getElementById('group-members-list');
        membersList.innerHTML = '<div class="text-center py-4"><?php echo addslashes($translations['groups']['loading_members'] ?? 'Yükleniyor...'); ?></div>';

        console.log('Opening group members modal for group ID:', currentGroupId);

        modal.classList.remove('hidden');
        setTimeout(() => {
            modal.querySelector('div.transform').classList.remove('translate-x-full');
            modal.querySelector('div.transform').classList.add('translate-x-0');
        }, 50);

        // Fetch group info
        console.log('Fetching group info from get_group_info.php?group_id=', currentGroupId);
        const response = await fetch(`get_group_info.php?group_id=${currentGroupId}`);
        if (!response.ok) {
            console.error('Fetch failed with status:', response.status);
            throw new Error(`HTTP error! status: ${response.status}`);
        }

        const data = await response.json();
        console.log('Response from get_group_info.php:', data);

        if (!data.success || data.error) {
            console.error('API error:', data.error || 'No success in response');
            membersList.innerHTML = `<div class="text-red-500 p-4">${data.error || '<?php echo addslashes($translations['groups']['error_loading_members'] ?? 'Üyeler yüklenemedi'); ?>'}</div>`;
            return;
        }

        const members = data.group.members;
        const currentUserId = <?php echo $_SESSION['user_id'] ?? 0; ?>;
        const isCreator = data.group.creator_id == currentUserId;
        console.log('Current User ID:', currentUserId);
        console.log('Group Creator ID:', data.group.creator_id);
        console.log('Is Current User Creator?', isCreator);
        console.log('Members:', members);

        membersList.innerHTML = members.map(member => {
            const isRemoveButtonVisible = isCreator && !member.is_creator && member.id != currentUserId;
            console.log(`Member: ${member.username}, ID: ${member.id}, is_creator: ${member.is_creator}, Show Remove Button: ${isRemoveButtonVisible}`);
            return `
                <div class="group-member-item flex items-center space-x-2" data-member-id="${member.id}">
                    <div class="member-avatar">
                        ${member.avatar_url ? 
                            `<img src="${member.avatar_url}" class="w-full h-full object-cover" alt="${member.username}">` : 
                            `<span>${member.username[0].toUpperCase()}</span>`}
                    </div>
                    <div class="ml-3 flex-1">
                        <div class="text-white flex items-center">
                            <span>${member.username}</span>
                            ${member.is_creator ? `<span class="admin-badge"><?php echo addslashes($translations['groups']['owner'] ?? 'Yönetici'); ?></span>` : ''}
                        </div>
                    </div>
                    ${isRemoveButtonVisible ? `
                        <button onclick="removeGroupMember(${currentGroupId}, ${member.id})" class="ml-auto text-red-400 hover:text-red-300 p-1.5 rounded-md hover:bg-red-500/20 transition-all" data-tooltip="<?php echo addslashes($translations['groups']['remove_member'] ?? 'Üyeyi Çıkar'); ?>">
                            <i data-lucide="user-x" class="w-5 h-5"></i>
                        </button>
                    ` : ''}
                </div>
            `;
        }).join('');

        console.log('Rendering complete, initializing Lucide icons');
        lucide.createIcons();
    } catch (error) {
        console.error('Error in showGroupMembers:', error);
        membersList.innerHTML = `<div class="text-red-500 p-4"><?php echo addslashes($translations['groups']['error_loading_members'] ?? 'Üyeler yüklenemedi'); ?>: ${error.message}</div>`;
    }
}

function removeGroupMember(groupId, memberId) {
    if (!confirm('<?php echo addslashes($translations['groups']['confirm_remove_member'] ?? 'Bu üyeyi gruptan çıkarmak istediğinizden emin misiniz?'); ?>')) {
        return;
    }

    fetch('remove_group_member.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `group_id=${groupId}&member_id=${memberId}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const memberElement = document.querySelector(`.group-member-item[data-member-id="${memberId}"]`);
            if (memberElement) {
                memberElement.remove();
            }
            showTempNotification(data.message, 'success');
        } else {
            showTempNotification(data.error, 'error');
        }
    })
    .catch(error => {
        console.error('Error removing member:', error);
        showTempNotification('<?php echo addslashes($translations['groups']['error_removing_member'] ?? 'Üye çıkarılırken bir hata oluştu'); ?>', 'error');
    });
}
function closeGroupMembers() {
    const modal = document.getElementById('group-members-modal');
    modal.querySelector('div').classList.remove('translate-x-0');
    setTimeout(() => {
        modal.classList.add('hidden');
    }, 300);
}

// Dışarı tıklamayı kontrol et
document.getElementById('group-members-modal').addEventListener('click', (e) => {
    if(e.target === e.currentTarget) closeGroupMembers();
});
// Modal fonksiyonları
function openCreateServerModal() {
    document.getElementById('create-server-modal').classList.remove('hidden');
}

function closeCreateServerModal() {
    document.getElementById('create-server-modal').classList.add('hidden');
}

// Dosya yükleme için
document.getElementById('modal-pp-upload').addEventListener('click', () => {
    document.querySelector('#create-server-form input[type="file"]').click();
});

// Form gönderimi
document.getElementById('create-server-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const formData = new FormData(e.target);
    
    try {
        const response = await fetch('create_server.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            closeCreateServerModal();
            window.location.href = `server.php?id=${result.server_id}`;
        } else {
            alert(result.error || 'Sunucu oluşturulurken bir hata oluştu');
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Bir hata oluştu, lütfen tekrar deneyin');
    }
});
// Rehber fonksiyonları
document.addEventListener('DOMContentLoaded', function() {
    const guide = document.getElementById('user-guide');
    const closeButton = document.getElementById('close-guide');
    
    if (!guide) return;
    
    // Kapatma işlevi
    if (closeButton) {
        closeButton.addEventListener('click', function() {
            guide.style.animation = 'none';
            setTimeout(() => {
                guide.style.opacity = '0';
                guide.style.transform = 'translateY(-10px)';
                setTimeout(() => {
                    guide.style.display = 'none';
                    localStorage.setItem('guideClosed', 'true');
                    
                    // Kapatma bildirimi göster
                    showTempNotification('Rehber kapatıldı. Ayarlardan tekrar açabilirsin.');
                }, 300);
            }, 10);
        });
    }
    
    // LocalStorage kontrolü
    const guideClosed = localStorage.getItem('guideClosed');
    if (guideClosed === 'true') {
        guide.style.display = 'none';
    }
    
    // Geçici bildirim fonksiyonu
    function showTempNotification(message) {
        const notification = document.createElement('div');
        notification.className = 'fixed bottom-4 right-4 bg-gray-800 text-white px-4 py-3 rounded-lg shadow-lg border-l-4 border-purple-500 transform transition-all duration-300 opacity-0 translate-y-10';
        notification.innerHTML = `
            <div class="flex items-center">
                <i data-lucide="info" class="w-5 h-5 text-purple-400 mr-2"></i>
                <span>${message}</span>
            </div>
        `;
        document.body.appendChild(notification);
        
        // Animasyonla göster
        setTimeout(() => {
            notification.style.opacity = '1';
            notification.style.transform = 'translateY(0)';
        }, 10);
        
        // 4 saniye sonra kaldır
        setTimeout(() => {
            notification.style.opacity = '0';
            notification.style.transform = 'translateY(10px)';
            setTimeout(() => {
                notification.remove();
            }, 300);
        }, 4000);
        
        lucide.createIcons();
    }
});

// Ayarlar menüsüne rehberi açma seçeneği ekleme
document.querySelector('a[href="/settings"]').addEventListener('click', function(e) {
    e.preventDefault();
    // Ayarlar sayfasına yönlendir
    window.location.href = '/settings?tab=guide';
});
// showMessage fonksiyonunu ekleyin
function showMessage(message, type) {
    const formMessage = document.getElementById('form-message');
    formMessage.textContent = message;
    formMessage.className = 'p-3 rounded text-sm'; // Temizle
    
    if (type === 'success') {
        formMessage.classList.add('bg-green-500/10', 'text-green-400');
    } else {
        formMessage.classList.add('bg-red-500/10', 'text-red-400');
    }
    
    formMessage.classList.remove('hidden');
}

// Grup oluşturma form submit işlemi (düzeltilmiş)
document.getElementById('create-group-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const submitButton = document.getElementById('create-group-button');
    const spinner = document.getElementById('create-group-spinner');
    const buttonText = document.getElementById('create-group-text');
    const formMessage = document.getElementById('form-message');
    
    submitButton.disabled = true;
    spinner.classList.remove('hidden');
    buttonText.textContent = 'Oluşturuluyor...';
    
    try {
        const formData = new FormData();
        formData.append('name', document.getElementById('group-name').value);
        
        const avatarInput = document.getElementById('group-avatar');
        if (avatarInput.files[0]) {
            formData.append('avatar', avatarInput.files[0]);
        }
        
        const selectedMembers = Array.from(
            document.querySelectorAll('#friend-selection input[type="checkbox"]:checked')
        ).map(checkbox => checkbox.value);
        
        formData.append('members', JSON.stringify(selectedMembers));
        
        const response = await fetch('create_group.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            showMessage('Grup başarıyla oluşturuldu!', 'success');
            setTimeout(() => {
                closeCreateGroupModal();
                location.reload();
            }, 2000);
        } else {
            showMessage(result.error || 'Grup oluşturulamadı', 'error');
        }
    } catch (error) {
        showMessage('Bir hata oluştu: ' + error.message, 'error');
    } finally {
        submitButton.disabled = false;
        spinner.classList.add('hidden');
        buttonText.textContent = 'Oluştur';
    }
});
$(document).on('click', '.cancel-request', function() {
    const $this = $(this);
    const receiverId = parseInt($this.data('receiver-id')); // int'e çevir
    const requestElement = $this.closest('.bg-gray-800');

    // receiverId kontrolü
    if (isNaN(receiverId) || receiverId === undefined) {
        console.error('Receiver ID bulunamadı veya geçersiz:', $this);
        alert('Geçersiz kullanıcı IDsi');
        return;
    }

    $.post('cancel_request.php', {
        receiver_id: receiverId
    }, 'json')
    .done(function(response) {
        // JSON parse kontrolü
        if (typeof response === 'string') {
            try {
                response = JSON.parse(response);
            } catch (e) {
                console.error('JSON parse hatası:', e);
                alert('Sunucu yanıtı hatalı');
                return;
            }
        }

        if (response.success) {
            requestElement.fadeOut(300, function() {
                $(this).remove();
                if ($('#sent-requests').children().length === 0) {
                    $('#sent-requests').html('<p class="text-gray-400 py-4 text-center">Gönderilen istek bulunmamaktadır</p>');
                }
            });
        } else {
            console.warn('İstek iptal edilemedi mesajı alındı:', response.message);
            alert(response.message || 'İstek iptal edilemedi');
        }
    })
    .fail(function(xhr, status, error) {
        console.error('AJAX hatası:', status, error);
        alert('Sunucu hatası, lütfen tekrar deneyin');
    });
});
let selectedMemberId = null;
let transferringGroupId = null;

async function openTransferModal(groupId) {
    transferringGroupId = groupId;
    selectedMemberId = null;
    
    document.getElementById('transfer-ownership-modal').classList.remove('hidden');
    
    try {
        const response = await fetch(`get_group_members.php?group_id=${groupId}`);
        const members = await response.json();
        
        const memberList = document.getElementById('member-list');
        memberList.innerHTML = '';
        
        if (members.length === 0 || (members.length === 1 && members[0].id === currentUserId)) {
            memberList.innerHTML = '<p class="text-gray-400">No other members to transfer ownership to.</p>';
            document.getElementById('confirm-transfer').disabled = true;
        } else {
            document.getElementById('confirm-transfer').disabled = false;
            members.forEach(member => {
                if (member.id !== currentUserId) {
                    const memberDiv = document.createElement('div');
                    memberDiv.className = 'flex items-center p-2 hover:bg-gray-700 rounded cursor-pointer';
                    memberDiv.innerHTML = `
                        <img src="${member.avatar_url || 'avatars/default-avatar.png'}" class="w-8 h-8 rounded-full mr-2">
                        <span>${member.username}</span>
                    `;
                    memberDiv.addEventListener('click', () => {
                        memberList.querySelectorAll('.bg-gray-700').forEach(el => el.classList.remove('bg-gray-700'));
                        memberDiv.classList.add('bg-gray-700');
                        selectedMemberId = member.id;
                    });
                    memberList.appendChild(memberDiv);
                }
            });
        }
    } catch (error) {
        console.error('Error fetching members:', error);
        alert('Üyeler yüklenemedi');
    }
}

document.getElementById('cancel-transfer').addEventListener('click', () => {
    document.getElementById('transfer-ownership-modal').classList.add('hidden');
});

document.getElementById('confirm-transfer').addEventListener('click', async () => {
    if (!selectedMemberId) {
        alert('Lütfen bir üye seçin');
        return;
    }
    
    try {
        const response = await fetch('transfer_ownership.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                group_id: transferringGroupId,
                new_owner_id: selectedMemberId
            })
        });
        
        const result = await response.json();
        if (result.success) {
            await leaveGroup(transferringGroupId);
        } else {
            alert(result.error || 'Ownership transfer failed');
        }
    } catch (error) {
        console.error('Error transferring ownership:', error);
        alert('Bir hata oluştu');
    }
});

async function leaveGroup(groupId) {
    try {
        const response = await fetch('leave_group.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ group_id: groupId })
        });
        
        const result = await response.json();
        if (result.success) {
            location.reload();
        } else {
            alert(result.error || 'Gruptan çıkılamadı');
        }
    } catch (error) {
        console.error('Error leaving group:', error);
        alert('Bir hata oluştu');
    }
}
// Üye ekleme modalını açma
function openAddMemberModal() {
    document.getElementById('add-member-modal').classList.remove('hidden');
    updateSelectedMembersForAdd(); // Seçilen üyeleri güncelle
}

// Üye ekleme modalını kapatma
function closeAddMemberModal() {
    document.getElementById('add-member-modal').classList.add('hidden');
    document.getElementById('member-search').value = ''; // Arama çubuğunu sıfırla
    document.getElementById('add-member-message').classList.add('hidden'); // Mesajı gizle
    document.getElementById('selected-members').innerHTML = ''; // Seçilen üyeleri temizle
    document.getElementById('selected-member-count').textContent = '0'; // Seçilen sayısını sıfırla
    
    // Tüm checkbox'ların işaretini kaldır
    const checkboxes = document.querySelectorAll('#friend-selection .add-member-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = false;
    });
    document.getElementById('add-members-button').disabled = true; // Ekle butonunu devre dışı bırak
    filterFriendsForAddMember(); // Filtrelenmiş listeyi sıfırla
}

// Arkadaşları filtreleme (üye ekleme için)
function filterFriendsForAddMember() {
    const searchInput = document.getElementById('member-search').value.toLowerCase();
    const friendLabels = document.querySelectorAll('#friend-selection label');

    friendLabels.forEach(label => {
        const usernameSpan = label.querySelector('span.text-white');
        if (usernameSpan) {
            const username = usernameSpan.textContent.toLowerCase();
            if (username.includes(searchInput)) {
                label.style.display = 'flex'; // Görüntüle
            } else {
                label.style.display = 'none'; // Gizle
            }
        }
    });
}

// Seçilen üyeleri güncelleme
function updateSelectedMembersForAdd() {
    const selectedMembersContainer = document.getElementById('selected-members');
    const checkboxes = document.querySelectorAll('#friend-selection .add-member-checkbox:checked');
    const selectedCountSpan = document.getElementById('selected-member-count');
    const addButton = document.getElementById('add-members-button');

    selectedMembersContainer.innerHTML = ''; // Önceki üyeleri temizle
    let selectedUsernames = [];

    checkboxes.forEach(checkbox => {
        const friendId = checkbox.value;
        const friendUsername = checkbox.dataset.username;
        const friendAvatar = checkbox.dataset.avatar;

        selectedUsernames.push(friendUsername);

        const memberTag = document.createElement('div');
        memberTag.classList.add('flex', 'items-center', 'bg-[#3a3a3a]', 'rounded-full', 'pl-1', 'pr-3', 'py-1', 'text-sm', 'text-white');
        memberTag.innerHTML = `
            <div class="w-5 h-5 rounded-full bg-gray-600 flex items-center justify-center overflow-hidden mr-1">
                ${friendAvatar ? `<img src="${friendAvatar}" class="w-full h-full object-cover">` : `<span class="text-white text-xs">${friendUsername.substring(0, 1).toUpperCase()}</span>`}
            </div>
            <span>${friendUsername}</span>
            <button type="button" class="ml-1 text-gray-400 hover:text-white" onclick="removeSelectedMemberForAdd('${friendId}')">
                <i data-lucide="x" class="w-4 h-4"></i>
            </button>
        `;
        selectedMembersContainer.appendChild(memberTag);
    });

    selectedCountSpan.textContent = checkboxes.length;
    addButton.disabled = checkboxes.length === 0; // Ekle butonunu etkinleştir/devre dışı bırak
    lucide.createIcons(); // Yeni eklenen ikonları oluştur
}

// Seçilen üyeyi kaldırma
function removeSelectedMemberForAdd(friendId) {
    const checkbox = document.querySelector(`#friend-selection input[value="${friendId}"]`);
    if (checkbox) {
        checkbox.checked = false;
        updateSelectedMembersForAdd(); // Listeyi güncelle
    }
}

// Checkbox olay dinleyicisi
document.querySelectorAll('#friend-selection .add-member-checkbox').forEach(checkbox => {
    checkbox.addEventListener('change', updateSelectedMembersForAdd);
});

// Üye ekleme form gönderimi
document.getElementById('add-members-button').addEventListener('click', async () => {
    const selectedMembers = Array.from(document.querySelectorAll('#friend-selection .add-member-checkbox:checked')).map(cb => cb.value);
    const addButton = document.getElementById('add-members-button');
    const addText = document.getElementById('add-members-text');
    const addSpinner = document.getElementById('add-members-spinner');
    const messageDiv = document.getElementById('add-member-message');

    if (selectedMembers.length === 0) {
        messageDiv.textContent = 'Lütfen en az bir üye seçin!';
        messageDiv.classList.remove('hidden', 'bg-green-500/10', 'text-green-400');
        messageDiv.classList.add('bg-red-500/10', 'text-red-400');
        return;
    }

    // Butonu devre dışı bırak ve yükleniyor animasyonu göster
    addButton.disabled = true;
    addText.classList.add('hidden');
    addSpinner.classList.remove('hidden');

    try {
        const response = await fetch('add_group_members.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                group_id: currentGroupId,
                member_ids: selectedMembers
            })
        });

        const result = await response.json();

        if (result.success) {
            messageDiv.textContent = 'Üyeler başarıyla eklendi!';
            messageDiv.classList.remove('hidden', 'bg-red-500/10', 'text-red-400');
            messageDiv.classList.add('bg-green-500/10', 'text-green-400');
            setTimeout(() => {
                closeAddMemberModal();
                showGroupMembers(); // Üye listesini yenile
            }, 2000);
        } else {
            messageDiv.textContent = result.error || 'Üyeler eklenemedi!';
            messageDiv.classList.remove('hidden', 'bg-green-500/10', 'text-green-400');
            messageDiv.classList.add('bg-red-500/10', 'text-red-400');
        }
    } catch (error) {
        console.error('Hata:', error);
        messageDiv.textContent = 'Bir hata oluştu: ' + error.message;
        messageDiv.classList.remove('hidden', 'bg-green-500/10', 'text-green-400');
        messageDiv.classList.add('bg-red-500/10', 'text-red-400');
    } finally {
        addButton.disabled = false;
        addText.classList.remove('hidden');
        addSpinner.classList.add('hidden');
    }
});

// Modal dışı tıklama ile kapatma
document.getElementById('add-member-modal').addEventListener('click', (e) => {
    if (e.target === e.currentTarget) {
        closeAddMemberModal();
    }
});

// Mesaj gönderme fonksiyonuna karakter limiti kontrolü ekleme
document.getElementById('message-input').addEventListener('input', function() {
    const messageInput = this;
    const maxLength = 2000;
    
    if (messageInput.value.length > maxLength) {
        messageInput.value = messageInput.value.substring(0, maxLength);
        showTempNotification('Mesaj 2000 karakter limitini aşıyor!');
    }
});

// Mesaj gönderme formu submit olayına kontrol ekleme
document.getElementById('message-form').addEventListener('submit', function(e) {
    const messageInput = document.getElementById('message-input');
    if (messageInput.value.length > 2000) {
        e.preventDefault();
        showTempNotification('Mesaj 2000 karakter limitini aşıyor!');
    }
});
document.addEventListener('DOMContentLoaded', () => {
    const serverList = document.getElementById('server-list');
    let draggedItem = null;

    // Add drag event listeners to each server item
    document.querySelectorAll('.server-item').forEach(item => {
        item.addEventListener('dragstart', (e) => {
            draggedItem = item;
            setTimeout(() => {
                item.classList.add('dragging');
            }, 0);
        });

        item.addEventListener('dragend', () => {
            draggedItem.classList.remove('dragging');
            draggedItem = null;
            updateServerOrder();
        });

        item.addEventListener('dragover', (e) => {
            e.preventDefault();
        });

        item.addEventListener('dragenter', (e) => {
            e.preventDefault();
            item.classList.add('drag-over');
        });

        item.addEventListener('dragleave', () => {
            item.classList.remove('drag-over');
        });

        item.addEventListener('drop', (e) => {
            e.preventDefault();
            item.classList.remove('drag-over');
            if (draggedItem !== item) {
                // Reorder the DOM
                const allItems = Array.from(serverList.querySelectorAll('.server-item'));
                const draggedIndex = allItems.indexOf(draggedItem);
                const targetIndex = allItems.indexOf(item);

                if (draggedIndex < targetIndex) {
                    item.after(draggedItem);
                } else {
                    item.before(draggedItem);
                }
            }
        });
    });

    // Update server order in the database
    function updateServerOrder() {
        const serverItems = Array.from(serverList.querySelectorAll('.server-item'));
        const order = serverItems.map((item, index) => ({
            server_id: item.dataset.serverId,
            position: index
        }));

        // Send the new order to the server
        fetch('update_server_order.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ order })
        })
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                console.error('Failed to update server order:', data.error);
                showTempNotification('Sunucu sıralaması güncellenemedi.', 'error');
            }
        })
        .catch(error => {
            console.error('Error updating server order:', error);
            showTempNotification('Bir hata oluştu.', 'error');
        });
    }
});

// Modified showTempNotification to support error type
function showTempNotification(message, type = 'info') {
    const notification = document.createElement('div');
    notification.className = `fixed bottom-4 right-4 bg-gray-800 text-white px-4 py-3 rounded-lg shadow-lg border-l-4 transform transition-all duration-300 opacity-0 translate-y-10`;
    notification.style.borderColor = type === 'error' ? '#ef4444' : '#3CB371';
    notification.innerHTML = `
        <div class="flex items-center">
            <i data-lucide="${type === 'error' ? 'alert-circle' : 'info'}" class="w-5 h-5 mr-2" style="color: ${type === 'error' ? '#ef4444' : '#3CB371'}"></i>
            <span>${message}</span>
        </div>
    `;
    document.body.appendChild(notification);

    setTimeout(() => {
        notification.style.opacity = '1';
        notification.style.transform = 'translateY(0)';
    }, 10);

    setTimeout(() => {
        notification.style.opacity = '0';
        notification.style.transform = 'translateY(10px)';
        setTimeout(() => notification.remove(), 300);
    }, 4000);

    lucide.createIcons();
}
function toggleFriendVisibility(friendId) {
    fetch('toggle_friend_visibility.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ friend_id: friendId })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Find the friend element in the DOM
            const friendElement = document.querySelector(`.friend-item1[data-friend-id="${friendId}"]`);
            
            if (friendElement) {
                friendElement.remove();
            } else {
                console.warn(`Friend element with ID ${friendId} not found in DOM.`);
            }
            
            showTempNotification(data.message || 'Arkadaş görünürlüğü değiştirildi!', 'success');
        } else {
            showTempNotification(data.error || 'İşlem başarısız oldu.', 'error');
        }
    })
    .catch(error => {
        console.error('Hata:', error);
        showTempNotification('Bir hata oluştu.', 'error');
    });
}



// Modified openVoiceCallModal to include music playback
function openVoiceCallModal() {
    const voiceCallModal = document.getElementById('voice-call-modal');
    voiceCallModal.classList.remove('hidden');
}

// Voice call modal kapatma
function closeVoiceCallModal() {
    const voiceCallModal = document.getElementById('voice-call-modal');
    voiceCallModal.classList.add('hidden');
}

// Join voice call button
document.getElementById('join-voice-call').addEventListener('click', () => {
    openVoiceCallModal();
});


document.addEventListener('DOMContentLoaded', () => {
    const openSearchBtn = document.getElementById('open-search-btn');
    const closeSearchBtn = document.getElementById('close-search-btn');
    const searchPanel = document.getElementById('search-panel');
    const searchInput = document.getElementById('search-input');
    const searchResultsContainer = document.getElementById('search-results-container');
    const messageContainer = document.getElementById('message-container');
    const messageForm = document.getElementById('message-form');

    let debounceTimer;

    function openSearchPanel() {
        searchPanel.classList.remove('hidden');
        messageContainer.classList.add('hidden');
        messageForm.classList.add('hidden');
        searchInput.focus();
    }

    function closeSearchPanel() {
        searchPanel.classList.add('hidden');
        messageContainer.classList.remove('hidden');
        messageForm.classList.remove('hidden');
        searchInput.value = '';
        searchResultsContainer.innerHTML = '<div class="text-center text-gray-500 mt-8"><?php echo $translations['search_bar']['type_for_search']; ?></div>';
    }
async function performSearch(query) {
    const friendId = currentFriendId; // Birebir sohbet için
    const groupId = currentGroupId; // Grup sohbeti için
    const searchResultsContainer = document.getElementById('search-results-container');

    if (!friendId && !groupId) {
        searchResultsContainer.innerHTML = '<div class="text-center text-gray-500 mt-8"><?php echo $translations['search_bar']['select_something']; ?></div>';
        return;
    }

    if (query.length < 2) {
        searchResultsContainer.innerHTML = '<div class="text-center text-gray-500 mt-8"><?php echo $translations['search_bar']['type_longer']; ?></div>';
        return;
    }

    searchResultsContainer.innerHTML = '<div class="text-center text-gray-500 mt-8"><i data-lucide="loader-2" class="animate-spin inline-block"></i> <?php echo $translations['search_bar']['searching']; ?></div>';
    lucide.createIcons();

    try {
        // URL parametrelerini oluştur
        let url = `search_direct_messages.php?query=${encodeURIComponent(query)}`;
        if (friendId) url += `&friend_id=${friendId}`;
        if (groupId) url += `&group_id=${groupId}`;

        const response = await fetch(url);
        const data = await response.json();

        if (data.success) {
            searchResultsContainer.innerHTML = '';
            if (data.messages.length > 0) {
                data.messages.forEach(message => {
                    const messageElement = document.createElement('div');
                    messageElement.classList.add('search-result-item', 'p-3', 'rounded-lg', 'flex', 'items-start', 'space-x-3', 'transition-colors', 'duration-200', 'cursor-pointer');
                    messageElement.innerHTML = `
                        <img src="${message.avatar_url || 'avatars/default-avatar.png'}" alt="${message.username}" class="w-10 h-10 rounded-full object-cover">
                        <div class="flex-1">
                            <div class="flex items-baseline space-x-2">
                                <span class="text-white font-semibold">${message.username}</span>
                                ${message.message_type === 'group' ? `<span class="text-gray-500 text-xs">[${message.group_name}]</span>` : ''}
                                <span class="text-gray-500 text-xs">${new Date(message.created_at).toLocaleDateString('tr-TR')}</span>
                            </div>
                            <p class="text-gray-300 text-sm mt-1">${message.message_text.replace(new RegExp(query, 'gi'), match => `<span class="highlight">${match}</span>`)}</p>
                        </div>
                    `;
                    searchResultsContainer.appendChild(messageElement);
                });
            } else {
                searchResultsContainer.innerHTML = '<div class="text-center text-gray-500 mt-8"><?php echo $translations['search_bar']['no_found']; ?></div>';
            }
        } else {
            searchResultsContainer.innerHTML = `<div class="text-center text-red-400 mt-8">Hata: ${data.error}</div>`;
        }
    } catch (error) {
        console.error('Arama hatası:', error);
        searchResultsContainer.innerHTML = '<div class="text-center text-red-400 mt-8">Arama servisine ulaşılamadı.</div>';
    }
}

    openSearchBtn.addEventListener('click', openSearchPanel);
    closeSearchBtn.addEventListener('click', closeSearchPanel);

    searchInput.addEventListener('input', () => {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => {
            performSearch(searchInput.value.trim());
        }, 300);
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !searchPanel.classList.contains('hidden')) {
            closeSearchPanel();
        }
    });
});
// Add to existing event listeners
document.addEventListener('click', (e) => {
    if (e.target.closest('[data-action="pin-message"]')) {
        const button = e.target.closest('[data-action="pin-message"]');
        const messageId = button.dataset.messageId;
        const action = button.textContent.includes('Pin') ? 'pin' : 'unpin';
        pinDirectMessage(messageId, action);
    }
    // Other existing actions (reply, edit, delete) remain here
});

// Function to handle pinning/unpinning
function pinDirectMessage(messageId, action) {
    fetch('pin_direct_message.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `message_id=${messageId}&action=${action}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const messageElement = document.querySelector(`[data-message-id="${messageId}"]`);
            if (messageElement) {
                const pinButton = messageElement.querySelector('[data-action="pin-message"]');
                if (pinButton) {
                    pinButton.innerHTML = data.is_pinned ? 
                        '<i data-lucide="pin-off" class="w-4 h-4 mr-2"></i> Unpin' : 
                        '<i data-lucide="pin" class="w-4 h-4 mr-2"></i> Pin';
                    lucide.createIcons();
                }
            }
        } else {
            alert(data.message);
        }
    })
    .catch(error => {
        console.error('Error pinning message:', error);
        alert('An error occurred while pinning the message');
    });
}
document.getElementById('pinned-messages-btn').addEventListener('click', openPinnedMessagesModal);

function openPinnedMessagesModal() {
    const modal = document.getElementById('pinned-messages-modal');
    modal.classList.remove('hidden');
    loadPinnedMessages();
}

function closePinnedMessagesModal() {
    const modal = document.getElementById('pinned-messages-modal');
    modal.classList.add('hidden');
}

function loadPinnedMessages() {
    const listContainer = document.getElementById('pinned-messages-list');
    const countBadge = document.getElementById('pinned-count');
    listContainer.innerHTML = '<div class="text-center text-gray-500 mt-8"><?php echo $translations['pinned_messages_dm']['pinned_loading']; ?></div>';

    let url = 'get_pinned_direct_messages.php?';
    if (currentFriendId) {
        url += `friend_id=${currentFriendId}`;
    } else if (currentGroupId) {
        url += `group_id=${currentGroupId}`;
    } else {
        listContainer.innerHTML = '<div class="text-center text-gray-500 mt-8">Sohbet seçilmedi.</div>';
        return;
    }

    fetch(url)
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! Status: ${response.status}`);
            }
            return response.text();
        })
        .then(text => {
            try {
                const data = JSON.parse(text);
                if (data.success) {
                    countBadge.textContent = data.messages.length;
                    if (data.messages.length > 0) {
                        listContainer.innerHTML = data.messages.map(message => `
                            <div class="rounded-lg shadow-lg p-3 relative cursor-pointer" style="background-color: #202225;" onclick="scrollToMessage(${message.id})">
                                <div class="flex items-center space-x-2">
                                    <img src="${message.avatar_url || 'avatars/default-avatar.png'}" class="w-8 h-8 rounded-full">
                                    <span class="text-white font-semibold">${message.username}</span>
                                    <span class="text-gray-400 text-sm">${new Date(message.created_at).toLocaleString('tr-TR')}</span>
                                </div>
                                <p class="text-gray-300 mt-2">${message.message_text}</p>
                                <button class="absolute top-2 right-2 text-gray-400 hover:text-white" onclick="unpinMessage(${message.id}); event.stopPropagation();">
                                    <i data-lucide="pin-off" class="w-5 h-5"></i>
                                </button>
                            </div>
                        `).join('');
                    } else {
                        listContainer.innerHTML = '<div class="text-center text-gray-500 mt-8"><?php echo $translations['pinned_messages_dm']['no_pinned']; ?></div>';
                    }
                    lucide.createIcons();
                } else {
                    listContainer.innerHTML = `<div class="text-center text-red-500 mt-8">${data.error}</div>`;
                }
            } catch (error) {
                console.error('JSON parse error:', error, 'Response:', text);
                listContainer.innerHTML = '<div class="text-center text-red-500 mt-8">Mesajlar yüklenemedi: Sunucu JSON formatında yanıt vermedi.</div>';
            }
        })
        .catch(error => {
            console.error('Sabitlenmiş mesajlar yüklenemedi:', error);
            listContainer.innerHTML = '<div class="text-center text-red-500 mt-8">Mesajlar yüklenemedi: ${error.message}</div>';
        });
}

function unpinMessage(messageId) {
    fetch('pin_direct_message.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `message_id=${messageId}&action=unpin`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            loadPinnedMessages(); // Listeyi yenile
        } else {
            alert(data.message);
        }
    })
    .catch(error => {
        console.error('Mesaj sabitlenmeden kaldırılamadı:', error);
        alert('Mesaj sabitlenmeden kaldırılamadı');
    });
}

function scrollToMessage(messageId) {
    const messageElement = document.getElementById(`message-${messageId}`);
    if (messageElement) {
        messageElement.scrollIntoView({ behavior: 'smooth' });
        closePinnedMessagesModal();
    } else {
        alert('Mesaj şu anda görünür değil');
    }
}

function gotoProfile() {
    window.location.href = '/profile-page?username=<?php echo htmlspecialchars($_SESSION['username']); ?>';
}
function gotoDM() {
    window.location.href = '/directmessages';
}
function gotoCOMM() {
    window.location.href = '/topluluklar';
}
function gotoLAKE() {
    window.location.href = '/posts';
}
function openStatusModal() {
    const mobileModal = document.getElementById('status-modal');
    mobileModal.style.display = (mobileModal.style.display === "none" || mobileModal.style.display === "") ? "fixed" : "none";
}
 // CSS injection
    (function() {
        const style = document.createElement('style');
        style.id = 'status-styles';
        style.textContent = `
            #user-profile .status-dot {
                width: 10px !important;
                height: 10px !important;
                border-radius: 50% !important;
                border: 2px solid #121212 !important;
                background-color: transparent !important;
                position: absolute !important;
                bottom: 0 !important;
                right: 0 !important;
            }
            #user-profile .status-dot.status-online {
                background-color: #43b581 !important;
            }
            #user-profile .status-dot.status-idle {
                background-color: #faa61a !important;
            }
            #user-profile .status-dot.status-dnd {
                background-color: #f04747 !important;
            }
            #user-profile .status-dot.status-offline {
                background-color: #747f8d !important;
            }
             #user-profile .status-dot1 {
                width: 10px !important;
                height: 10px !important;
                border-radius: 50% !important;
                border: 2px solid #121212 !important;
                background-color: transparent !important;
                position: absolute !important;
                bottom: 0 !important;
                right: 0 !important;
            }
            #user-profile .status-dot1.status-online {
                background-color: #43b581 !important;
            }
            #user-profile .status-dot1.status-idle {
                background-color: #faa61a !important;
            }
            #user-profile .status-dot1.status-dnd {
                background-color: #f04747 !important;
            }
            #user-profile .status-dot1.status-offline {
                background-color: #747f8d !important;
            }
            #status-modal .status-option {
                padding: 8px !important;
                cursor: pointer !important;
                color: #dcddde !important;
            }
            #status-modal .status-option:hover {
                background-color: #36393f !important;
            }
        `;
        document.head.appendChild(style);
    })();
    const translation = {
        Online: '<?php echo addslashes($translations['Online'] ?? 'Online'); ?>',
        Idle: '<?php echo addslashes($translations['Idle'] ?? 'Idle'); ?>',
        DoNotDisturb: '<?php echo addslashes($translations['DoNotDisturb'] ?? 'Do Not Disturb'); ?>',
        Offline: '<?php echo addslashes($translations['Offline'] ?? 'Offline'); ?>',
        StatusUpdateError: '<?php echo addslashes($translations['StatusUpdateError'] ?? 'Status update failed'); ?>'
    };

$(document).ready(function() {
    // Kullanıcı profiline sağ tıklama
    $('#user-profile').on('contextmenu', function(e) {
        e.preventDefault();
        const modal = $('#status-modal');
        modal.css({
            top: e.pageY,
            left: e.pageX
        }).show();
    });

$('.status-option').click(function() {
    const status = $(this).data('status');
    $.ajax({
        url: 'set_status.php',
        method: 'POST',
        data: { action: 'update_status', status: status },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                $('.status-dot1').removeClass('status-online status-idle status-dnd status-offline')
                    .addClass(`status-${response.status}`);
                $('#status-modal').hide();
                // Durum metnini güncelle
                const statusText = {
                    'online': translation.Online,
                    'idle': translation.Idle,
                    'dnd': translation.DoNotDisturb,
                    'offline': translation.Offline
                };
                $('#user-profile .text-gray-400.text-xs').text(statusText[response.status]);
                showTempNotification('Durumunuz güncellendi: ' + statusText[response.status], 'success');
                // original_status kontrolü
                if (response.original_status !== response.status) {
                    console.warn('original_status eşleşmiyor:', response);
                    showTempNotification('Hata: original_status güncellenemedi (' + response.original_status + ')', 'error');
                }
            } else {
                console.error('Status update failed:', response.message);
                showTempNotification(response.message, 'error');
            }
        },
        error: function(xhr, status, error) {
            console.error('AJAX error:', error);
            showTempNotification(translation.StatusUpdateError + ': ' + error, 'error');
        }
    });
});

    // Modal dışına tıklayınca kapat
    $(document).click(function(e) {
        if (!$(e.target).closest('#status-modal').length && !$(e.target).closest('#user-profile').length) {
            $('#status-modal').hide();
        }
    });
});
(function() {
    // Mevcut status-styles ID'sine sahip stil varsa kaldır
    const existingStyle = document.getElementById('status-styles');
    if (existingStyle) {
        existingStyle.remove();
    }

    // Yeni stil elementi oluştur
    const style = document.createElement('style');
    style.id = 'status-styles';
    style.textContent = `
        .status-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            border: 2px solid #121212;
            background-color: transparent;
            display: inline-block;
            margin-left: auto;
        }
        .status-dot.status-online {
            background-color: #43b581;
        }
        .status-dot.status-idle {
            background-color: #faa61a;
        }
        .status-dot.status-dnd {
            background-color: #f04747;
        }
        .status-dot.status-offline {
            background-color: #747f8d;
        }
        .status-dot1 {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            border: 2px solid #121212;
            background-color: transparent;
            display: inline-block;
            margin-left: auto;
        }
        .status-dot1.status-online {
            background-color: #43b581;
        }
        .status-dot1.status-idle {
            background-color: #faa61a;
        }
        .status-dot1.status-dnd {
            background-color: #f04747;
        }
        .status-dot1.status-offline {
            background-color: #747f8d;
        }
        #status-modal .status-option {
            padding: 0.5rem;
            cursor: pointer;
            color: #d1d5db;
        }
        #status-modal .status-option:hover {
            background-color: #374151;
        }
    `;
    document.head.appendChild(style);
})();
function updateLastActivity() {
    $.ajax({
        url: 'update_last_activity.php',
        method: 'POST',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                // Hem kendi profilini hem de diğer durum göstergelerini güncelle
                const statusDot = $('.status-dot1');
                const statusTextElement = $('.status-text, #user-profile .text-gray-400.text-xs');
                if (statusDot.length && statusTextElement.length) {
                    statusDot.removeClass('status-online status-idle status-dnd status-offline')
                        .addClass(`status-${response.status}`);
                    const statusText = {
                        'online': translation.Online,
                        'idle': translation.Idle,
                        'dnd': translation.DoNotDisturb,
                        'offline': translation.Offline
                    };
                    statusTextElement.text(statusText[response.status] || response.status);
                }
            } else {
                console.error('Last activity update failed:', response.message);
                showTempNotification(translation.LastActivityUpdateError, 'error');
            }
        },
        error: function(xhr, status, error) {
            console.error('Error updating last activity:', error);
            showTempNotification(translation.LastActivityUpdateError + ': ' + error, 'error');
        }
    });
}

function checkOfflineStatus() {
    fetch('update_offline_status.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Kullanıcı durumu sunucudan gelen bilgiye göre güncellenir
            updateLastActivity(); // Durumu tekrar kontrol et
        } else {
            console.error('Offline status check failed:', data.message);
            showTempNotification('Çevrimdışı durum kontrolü başarısız: ' + (data.message || 'Bilinmeyen hata'), 'error');
        }
    })
    .catch(error => {
        console.error('Error checking offline status:', error);
        showTempNotification('Çevrimdışı durum kontrolü başarısız: ' + error.message, 'error');
    });
}

// Her 5 saniyede bir last_activity'yi güncelle
setInterval(updateLastActivity, 5000);

// Her 10 saniyede bir offline durumunu kontrol et
setInterval(checkOfflineStatus, 10000);

// Sayfa yüklendiğinde bir kez çalıştır
document.addEventListener('DOMContentLoaded', () => {
    updateLastActivity();
    checkOfflineStatus();
});

document.addEventListener('DOMContentLoaded', function() {
    const bell = document.getElementById('notification-bell');
    const panel = document.getElementById('notification-panel');
    const counter = document.getElementById('notification-counter');
    const list = document.getElementById('notification-list');
    const markAllReadBtn = document.getElementById('mark-all-read');
    let isOpen = false;

    // Bildirim panelini aç/kapat
    bell.addEventListener('click', function(event) {
    event.stopPropagation();

    // Eğer panel açık değilse, animasyonla aç
    if (!isOpen) {
        notificationPanel.classList.remove('notific-anim-out');
        notificationPanel.classList.add('notific-anim-in');
        notificationPanel.classList.remove('hidden');

        // Bildirimleri almak için fetch fonksiyonunu çağır
        fetchNotifications();

    } else {
        // Eğer panel açık ise, animasyonla kapat
        notificationPanel.classList.remove('notific-anim-in');
        notificationPanel.classList.add('notific-anim-out');
    }
    isOpen = !isOpen;
    });
    // Dışarı tıklayınca paneli kapat
    document.addEventListener('click', function(event) {
        if (!panel.contains(event.target) && !bell.contains(event.target)) {
            panel.classList.add('notific-anim-out');
        }
    });

    // Tümünü okundu say
    markAllReadBtn.addEventListener('click', function() {
        markNotificationAsRead(null); // ID null ise hepsi okunur
        list.innerHTML = '<div class="p-4 text-center text-gray-500">Okunmamış bildirim yok.</div>';
        counter.classList.add('hidden');
        counter.textContent = '0';
    });

    // Bildirimleri sunucudan çek
    function fetchNotifications() {
        fetch('get_notifications.php')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    updateNotificationUI(data.notifications);
                }
            })
            .catch(error => console.error('Bildirimler alınamadı:', error));
    }

    // Bildirim arayüzünü güncelle
    function updateNotificationUI(notifications) {
        list.innerHTML = '';
        if (notifications.length > 0) {
            counter.textContent = notifications.length;
            counter.classList.remove('hidden');

            notifications.forEach(notif => {
                const notifElement = document.createElement('div');
                notifElement.className = 'p-3 hover:bg-gray-700 cursor-pointer border-b border-gray-700';
                notifElement.dataset.id = notif.id;
                notifElement.dataset.serverId = notif.server_id;
                notifElement.dataset.channelId = notif.channel_id;

                let message = `<strong>${notif.sender_username}</strong> sizi etiketledi.`;
                if (notif.server_name) {
                    message += ` - <strong>${notif.server_name}</strong> sunucusunda.`;
                }

                notifElement.innerHTML = `<p class="text-sm text-gray-300">${message}</p>`;
                list.appendChild(notifElement);
            });
        } else {
            list.innerHTML = '<div class="p-4 text-center text-gray-500">Okunmamış bildirim yok.</div>';
            counter.classList.add('hidden');
            counter.textContent = '0';
        }
    }

    // Bildirimi okundu olarak işaretle
function markNotificationAsRead(notificationId) {
    const formData = new FormData();
    if (notificationId) {
        formData.append('notification_id', notificationId);
    } else {
        formData.append('action', 'mark_all_read'); // Tümünü okundu saymak için özel bir aksiyon
    }

    fetch('mark_notification_read.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            if (!notificationId) {
                // Tüm bildirimler okunduysa arayüzü sıfırla
                list.innerHTML = '<div class="p-4 text-center text-gray-500">Okunmamış bildirim yok.</div>';
                counter.classList.add('hidden');
                counter.textContent = '0';
            }
            fetchNotifications(); // Bildirimleri yenile
            showTempNotification('Bildirimler okundu olarak işaretlendi.', 'success');
        } else {
            showTempNotification(data.error || 'Bildirimler okundu olarak işaretlenemedi.', 'error');
        }
    })
    .catch(error => {
        console.error('Bildirimler okundu olarak işaretlenemedi:', error);
        showTempNotification('Bildirimler okundu olarak işaretlenemedi: ' + error.message, 'error');
    });
}

// Tümünü okundu say butonu
markAllReadBtn.addEventListener('click', function() {
    markNotificationAsRead(null); // null göndererek tüm bildirimleri okundu say
});

    // Bildirime tıklama olayı
    list.addEventListener('click', function(event) {
        const target = event.target.closest('.p-3');
        if (target) {
            const notifId = target.dataset.id;
            const serverId = target.dataset.serverId;
            const channelId = target.dataset.channelId;

            markNotificationAsRead(notifId);

            // İlgili sunucu ve kanala yönlendir
            if (serverId && channelId) {
                window.location.href = `server?id=${serverId}&channel_id=${channelId}`;
            }
            // Gerekirse direct message için de yönlendirme eklenebilir
        }
    });

    // Sayfa yüklendiğinde ve periyodik olarak bildirimleri kontrol et
    fetchNotifications();
    setInterval(fetchNotifications, 5000); // 15 saniyede bir kontrol et
});

// Bildirim paneli animasyon giriş çıkış kodları
let notificationPanel = document.getElementById('notification-panel');
let toggleButton = document.getElementById('notification-bell');
let isOpen = false; // Durum, panel açık mı kapalı mı?

toggleButton.addEventListener('click', function() {
  if (isOpen) {
    // Paneli kapat
    notificationPanel.classList.remove('notific-anim-in');
    notificationPanel.classList.add('notific-anim-out');
  } else {
    // Paneli aç
    notificationPanel.classList.remove('notific-anim-out');
    notificationPanel.classList.add('notific-anim-in');
  }

  // Durumu güncelle
  isOpen = !isOpen;
});
document.addEventListener('DOMContentLoaded', () => {
    const messageInput = document.getElementById('message-input');
    const emojiButton = document.getElementById('emoji-button');
    const gifButton = document.getElementById('gif-button');

    // Mobil cihazları kontrol etmek için (max-width: 768px)
    const isMobile = window.matchMedia('(max-width: 768px)').matches;

    if (isMobile) {
        // Odaklanma olayı
        messageInput.addEventListener('focus', () => {
            messageInput.style.width = 'calc(100% - 10px)';
            messageInput.style.marginLeft = '-90px';
            messageInput.style.zIndex = '12';
            messageInput.style.transition = 'width 0.3s ease, margin-left 0.3s ease';

            // Emoji ve GIF butonlarını gizle
            if (emojiButton) emojiButton.style.opacity = '0';
            if (emojiButton) emojiButton.style.pointerEvents = 'none';
            if (gifButton) gifButton.style.opacity = '0';
            if (gifButton) gifButton.style.pointerEvents = 'none';
        });

        // Odak kaybı olayı
        messageInput.addEventListener('blur', () => {
            messageInput.style.width = 'calc(100% - 100px)';
            messageInput.style.marginLeft = '0';
            messageInput.style.zIndex = '10';

            // Emoji ve GIF butonlarını geri göster
            if (emojiButton) emojiButton.style.opacity = '1';
            if (emojiButton) emojiButton.style.pointerEvents = 'auto';
            if (gifButton) gifButton.style.opacity = '1';
            if (gifButton) gifButton.style.pointerEvents = 'auto';
        });
    }
});
// Anket modalını açma
document.getElementById('poll-button').addEventListener('click', () => {
    document.getElementById('create-poll-modal').classList.remove('hidden');
});

// Anket modalını kapatma ve formu sıfırlama
document.getElementById('cancel-poll').addEventListener('click', () => {
    const modal = document.getElementById('create-poll-modal');
    const form = document.getElementById('poll-form');
    const optionsContainer = document.getElementById('poll-options');
    
    modal.classList.add('hidden');
    form.reset();
    optionsContainer.querySelectorAll('input.poll-option').forEach((input, index) => {
        if (index > 1) input.remove();
    });
});

// Seçenek ekleme
document.getElementById('add-poll-option').addEventListener('click', () => {
    const optionsContainer = document.getElementById('poll-options');
    if (optionsContainer.querySelectorAll('input.poll-option').length < 10) {
        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'form-input poll-option w-full mb-2 bg-[#2a2a2a] text-white border border-gray-700 rounded p-2';
        input.required = true;
        input.placeholder = `Seçenek ${optionsContainer.querySelectorAll('input.poll-option').length + 1}`;
        optionsContainer.appendChild(input);
    } else {
    }
});
document.getElementById('poll-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const question = document.getElementById('poll-question').value.trim();
    const options = Array.from(document.querySelectorAll('input.poll-option'))
        .map(input => input.value ? input.value.trim() : '')
        .filter(val => val);
    
    if (!question) {
     
        return;
    }
    
    if (options.length < 2) {
      
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'create_poll');
    if (currentFriendId) {
        formData.append('receiver_id', currentFriendId);
    } else if (currentGroupId) {
        formData.append('group_id', currentGroupId);
    } else {
     
        return;
    }
    formData.append('question', question);
    formData.append('options', JSON.stringify(options));
    
    try {
        const response = await fetch('messages.php', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();
        
        if (data.success) {
            document.getElementById('create-poll-modal').classList.add('hidden');
            document.getElementById('poll-form').reset();
            document.querySelectorAll('input.poll-option').forEach((input, index) => {
                if (index > 1) input.remove();
            });
        } else {
           
        }
    } catch (error) {
        console.error('Anket oluşturma hatası:', error);
        
    }
});
// Mini profil/grup modalını aç
function openMiniProfileModal(id, type) {
    const modal = document.getElementById('mini-profile-modal');
    const title = document.getElementById('mini-profile-title');
    const avatar = document.getElementById('mini-profile-avatar');
    const avatarFrame = document.getElementById('mini-profile-avatar-frame');
    const username = document.getElementById('mini-profile-username');
    const statusText = document.getElementById('mini-profile-status-text');
    const statusDot = document.getElementById('mini-profile-status');
    const groupInfoSection = document.getElementById('group-info-section');
    const groupMemberCount = document.getElementById('group-member-count');
    const groupCreator = document.getElementById('group-creator');
    const groupMembersList = document.getElementById('group-members-list1');
    const fullProfileButton = document.getElementById('view-full-profile');

    // Varsayılan durum
    username.textContent = 'Yükleniyor...';
    avatar.src = 'avatars/default-avatar.png';
    if (avatarFrame) avatarFrame.src = ''; // Clear frame
    statusText.classList.add('hidden');
    statusDot.classList.add('hidden');
    groupInfoSection.classList.add('hidden');
    fullProfileButton.textContent = '';
    fullProfileButton.dataset.userId = '';
    fullProfileButton.dataset.username = '';
    fullProfileButton.dataset.groupId = '';

    if (type === 'user') {
        // Bireysel kullanıcı profili
        title.textContent = '<?php echo $translations['UserProfile'] ?? 'Kullanıcı Profili'; ?>';
        statusText.classList.remove('hidden');
        statusDot.classList.remove('hidden');
        fullProfileButton.textContent = '<?php echo $translations['ViewFullProfile'] ?? 'Tam Profili Görüntüle'; ?>';
        fullProfileButton.dataset.userId = id;

        fetch(`get_user_info.php?user_id=${id}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    username.textContent = data.user.username || 'Bilinmeyen Kullanıcı';
                    avatar.src = data.user.avatar_url || 'avatars/default-avatar.png';
                    if (avatarFrame && data.user.avatar_frame_url) {
                        avatarFrame.src = data.user.avatar_frame_url;
                        avatarFrame.classList.remove('hidden');
                    } else if (avatarFrame) {
                        avatarFrame.classList.add('hidden');
                    }
                    statusText.textContent = {
                        online: '<?php echo $translations['Online'] ?? 'Online'; ?>',
                        idle: '<?php echo $translations['Idle'] ?? 'Idle'; ?>',
                        dnd: '<?php echo $translations['DoNotDisturb'] ?? 'Do Not Disturb'; ?>',
                        offline: '<?php echo $translations['Offline'] ?? 'Offline'; ?>'
                    }[data.user.status] || 'Bilinmeyen Durum';
                    statusDot.className = `status-dot status-${data.user.status || 'offline'}`;
                    fullProfileButton.dataset.username = data.user.username || '';
                } else {
                    username.textContent = 'Hata';
                    statusText.textContent = data.error || 'Kullanıcı bilgileri alınamadı';
                }
            })
            .catch(error => {
                console.error('Kullanıcı bilgileri alınamadı:', error);
                username.textContent = 'Hata';
                statusText.textContent = 'Bir hata oluştu';
            });
    } else if (type === 'group') {
        // Grup profili
        title.textContent = '<?php echo $translations['GroupInfo'] ?? 'Grup Bilgileri'; ?>';
        groupInfoSection.classList.remove('hidden');
        fullProfileButton.textContent = '<?php echo $translations['ViewGroupPage'] ?? 'Grup Sayfasını Görüntüle'; ?>';
        fullProfileButton.dataset.groupId = id;
        if (avatarFrame) avatarFrame.classList.add('hidden');

        fetch(`get_group_info.php?group_id=${id}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    username.textContent = data.group.name || 'Bilinmeyen Grup';
                    avatar.src = data.group.avatar_url || 'avatars/default-group-avatar.png';
                    groupMemberCount.textContent = `${data.group.member_count} ${data.group.member_count > 1 ? '<?php echo $translations['Members'] ?? 'Üye'; ?>' : '<?php echo $translations['Member'] ?? 'Üye'; ?>'}`;
                    groupCreator.textContent = `Oluşturan: ${data.group.creator_username || 'Bilinmeyen'}`;
                    groupMembersList.innerHTML = data.group.members.map(member => `
                        <div class="group-member-item flex items-center space-x-2">
                            <div class="member-avatar">
                                <img src="${member.avatar_url || 'avatars/default-avatar.png'}" alt="${member.username}">
                            </div>
                            <span class="text-white text-sm">${member.username}</span>
                            ${member.is_creator ? '<span class="admin-badge">Yönetici</span>' : ''}
                        </div>
                    `).join('');
                    lucide.createIcons();
                } else {
                    username.textContent = 'Hata';
                    groupMemberCount.textContent = data.error || 'Grup bilgileri alınamadı';
                }
            })
            .catch(error => {
                console.error('Grup bilgileri alınamadı:', error);
                username.textContent = 'Hata';
                groupMemberCount.textContent = 'Bir hata oluştu';
            });
    }

    modal.classList.remove('hidden');
    lucide.createIcons();
}

// Mini profil/grup modalını kapat (unchanged)
function closeMiniProfileModal() {
    const modal = document.getElementById('mini-profile-modal');
    modal.classList.add('hidden');
}

// chat-username tıklama olayı (unchanged)
document.getElementById('chat-username').addEventListener('click', (e) => {
    e.preventDefault();
    if (currentFriendId) {
        openMiniProfileModal(currentFriendId, 'user');
    } else if (currentGroupId) {
        openMiniProfileModal(currentGroupId, 'group');
    } else {
        showTempNotification('<?php echo $translations['NoSelection'] ?? 'Kullanıcı veya grup seçilmedi!'; ?>', 'error');
    }
});

// Modal dışı tıklama ile kapatma (unchanged)
document.getElementById('mini-profile-modal').addEventListener('click', (e) => {
    if (e.target === e.currentTarget) {
        closeMiniProfileModal();
    }
});

// Kapat butonu (unchanged)
document.getElementById('close-mini-profile').addEventListener('click', closeMiniProfileModal);

// Tam profil/grup sayfası butonu
document.getElementById('view-full-profile').addEventListener('click', () => {
    const username = document.getElementById('view-full-profile').dataset.username;
    const groupId = document.getElementById('view-full-profile').dataset.groupId;
    if (username) {
        window.location.href = `/profile-page?username=${encodeURIComponent(username)}`; // Use username
    } else if (groupId) {
        window.location.href = `/group-page?group_id=${groupId}`;
    }
});
// JavaScript for hover effect on status-text-container
document.addEventListener('DOMContentLoaded', function() {
    const containers = document.querySelectorAll('#user-profile .status-text-container');
    containers.forEach(container => {
        const statusText = container.querySelector('.status-text');
        const realUsername = container.querySelector('.real-username');

        // Check if elements exist to avoid errors
        if (statusText && realUsername) {
            container.addEventListener('mouseover', function() {
                statusText.style.opacity = '0';
                realUsername.style.opacity = '1';
            });
            container.addEventListener('mouseout', function() {
                statusText.style.opacity = '1';
                realUsername.style.opacity = '0';
            });
        } else {
            console.error('Status text or real username element not found in container');
        }
    });
});

function openGroupSettingsModal(groupId) {
    const modal = document.getElementById('group-settings-modal');
    const nameInput = document.getElementById('group-name-input');
    const avatarInput = document.getElementById('group-avatar-input');
    const deleteButton = document.getElementById('delete-group-button');
    const messageDiv = document.getElementById('group-settings-message');

    if (!groupId) {
        showTempNotification('Grup ID bulunamadı!', 'error');
        return;
    }

    // Reset form
    nameInput.value = '';
    avatarInput.value = '';
    messageDiv.classList.add('hidden');
    
    // Fetch current group details
    fetch(`get_group_info.php?group_id=${groupId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                nameInput.value = data.group.name || '';
            } else {
                showTempNotification('Grup bilgileri yüklenemedi: ' + (data.error || 'Bilinmeyen hata'), 'error');
            }
        })
        .catch(error => {
            console.error('Failed to load group info:', error);
            showTempNotification('Grup bilgileri yüklenemedi.', 'error');
        });

    // Set group ID for form submission
    deleteButton.dataset.groupId = groupId;
    document.getElementById('group-settings-form').dataset.groupId = groupId;

    modal.classList.remove('hidden');
    lucide.createIcons();
}

function closeGroupSettingsModal() {
    const modal = document.getElementById('group-settings-modal');
    modal.classList.add('hidden');
}

document.getElementById('group-settings-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const groupId = e.target.dataset.groupId;
    const name = document.getElementById('group-name-input').value.trim();
    const avatarInput = document.getElementById('group-avatar-input');
    const messageDiv = document.getElementById('group-settings-message');
    const submitButton = document.getElementById('submit-group-settings');
    const submitText = document.getElementById('submit-settings-text');
    const submitSpinner = document.getElementById('submit-settings-spinner');

    if (!groupId) {
        messageDiv.textContent = 'Grup ID bulunamadı!';
        messageDiv.classList.remove('hidden', 'bg-green-500/10', 'text-green-400');
        messageDiv.classList.add('bg-red-500/10', 'text-red-400');
        return;
    }

    if (!name) {
        messageDiv.textContent = 'Grup adı zorunludur!';
        messageDiv.classList.remove('hidden', 'bg-green-500/10', 'text-green-400');
        messageDiv.classList.add('bg-red-500/10', 'text-red-400');
        return;
    }

    // Show loading state
    submitButton.disabled = true;
    submitText.classList.add('hidden');
    submitSpinner.classList.remove('hidden');

    try {
        const data = {
            action: 'update_group',
            group_id: groupId,
            name: name,
            avatar_url: null // Default to null if no file is uploaded
        };

        // Handle avatar upload if present
        if (avatarInput.files[0]) {
            const formData = new FormData();
            formData.append('avatar', avatarInput.files[0]);
            const uploadResponse = await fetch('upload_group_avatar.php', {
                method: 'POST',
                body: formData
            });
            const uploadResult = await uploadResponse.json();
            if (uploadResult.success) {
                data.avatar_url = uploadResult.avatar_url;
            } else {
                throw new Error(uploadResult.error || 'Avatar yükleme başarısız');
            }
        }

        const response = await fetch('group_settings.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });

        const result = await response.json();

        if (result.success) {
            messageDiv.textContent = result.message;
            messageDiv.classList.remove('hidden', 'bg-red-500/10', 'text-red-400');
            messageDiv.classList.add('bg-green-500/10', 'text-green-400');
            setTimeout(() => {
                closeGroupSettingsModal();
                location.reload(); // Refresh to show updated group info
            }, 2000);
        } else {
            messageDiv.textContent = result.error;
            messageDiv.classList.remove('hidden', 'bg-green-500/10', 'text-green-400');
            messageDiv.classList.add('bg-red-500/10', 'text-red-400');
        }
    } catch (error) {
        console.error('Error updating group:', error);
        messageDiv.textContent = 'Bir hata oluştu: ' + error.message;
        messageDiv.classList.remove('hidden', 'bg-green-500/10', 'text-green-400');
        messageDiv.classList.add('bg-red-500/10', 'text-red-400');
    } finally {
        submitButton.disabled = false;
        submitText.classList.remove('hidden');
        submitSpinner.classList.add('hidden');
    }
});

document.getElementById('delete-group-button').addEventListener('click', async () => {
    if (!confirm('Grubu silmek istediğinizden emin misiniz? Bu işlem geri alınamaz!')) {
        return;
    }

    const groupId = document.getElementById('delete-group-button').dataset.groupId;
    const messageDiv = document.getElementById('group-settings-message');
    const deleteButton = document.getElementById('delete-group-button');

    if (!groupId) {
        messageDiv.textContent = 'Grup ID bulunamadı!';
        messageDiv.classList.remove('hidden', 'bg-green-500/10', 'text-green-400');
        messageDiv.classList.add('bg-red-500/10', 'text-red-400');
        return;
    }

    deleteButton.disabled = true;

    try {
        const response = await fetch('group_settings.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'delete_group', group_id: groupId })
        });

        const data = await response.json();

        if (data.success) {
            messageDiv.textContent = data.message;
            messageDiv.classList.remove('hidden', 'bg-red-500/10', 'text-red-400');
            messageDiv.classList.add('bg-green-500/10', 'text-green-400');
            setTimeout(() => {
                closeGroupSettingsModal();
                window.location.href = '/directmessages'; // Redirect to DMs after deletion
            }, 2000);
        } else {
            messageDiv.textContent = data.error;
            messageDiv.classList.remove('hidden', 'bg-green-500/10', 'text-green-400');
            messageDiv.classList.add('bg-red-500/10', 'text-red-400');
        }
    } catch (error) {
        console.error('Error deleting group:', error);
        messageDiv.textContent = 'Bir hata oluştu: ' + error.message;
        messageDiv.classList.remove('hidden', 'bg-green-500/10', 'text-green-400');
        messageDiv.classList.add('bg-red-500/10', 'text-red-400');
    } finally {
        deleteButton.disabled = false;
    }
});

document.getElementById('group-settings-modal').addEventListener('click', (e) => {
    if (e.target === e.currentTarget) {
        closeGroupSettingsModal();
    }
});

document.getElementById('cancel-group-settings').addEventListener('click', closeGroupSettingsModal);


// Function to execute scripts in dynamically loaded content
async function executeScripts(container) {
    const scripts = Array.from(container.querySelectorAll('script'));
    for (const script of scripts) {
        const newScript = document.createElement('script');
        if (script.src) {
            const existingScript = document.querySelector(`script[src="${script.src}"]`);
            if (!existingScript) {
                newScript.src = script.src;
                document.head.appendChild(newScript);
                await new Promise((resolve, reject) => {
                    newScript.onload = resolve;
                    newScript.onerror = () => {
                        console.error(`Error loading script: ${script.src}`);
                        reject();
                    };
                });
            }
        } else {
            newScript.textContent = script.textContent;
            document.body.appendChild(newScript);
            setTimeout(() => {
                if (document.body.contains(newScript)) {
                    document.body.removeChild(newScript);
                }
            }, 0);
        }
    }
}

// Store resize listeners to clean them up later
let resizeListeners = [];

document.addEventListener('DOMContentLoaded', () => {
    if (window.spaInitialized) return;
    window.spaInitialized = true;
    const mainContent = document.getElementById('main-content');
    const dmLink = document.querySelector('.dm-link');
    const serverLinks = document.querySelectorAll('.server-link');
    let activeLink = null;

  

    // Function to update active link styling
    const setActiveLink = (link) => {
        if (activeLink) {
            activeLink.parentElement.classList.remove('bg-indigo-500', 'rounded-2xl');
            activeLink.parentElement.classList.add('bg-gray-700', 'rounded-3xl');
        }
        link.parentElement.classList.remove('bg-gray-700', 'rounded-3xl');
        link.parentElement.classList.add('bg-indigo-500', 'rounded-2xl');
        activeLink = link;
    };

    // Function to clean up resize listeners
    const cleanupResizeListeners = () => {
        resizeListeners.forEach(({ element, handler }) => {
            element.removeEventListener('resize', handler);
        });
        resizeListeners = []; // Reset the listeners array
    };

    // Function to initialize resize-dependent logic in loaded content
    const initResizeDependentLogic = (container) => {
        // Example: Find elements that depend on window.innerWidth
        const responsiveElements = container.querySelectorAll('[data-responsive]');
        responsiveElements.forEach(element => {
            const handler = () => {
                const width = window.innerWidth;
                // Example logic: Adjust based on window.innerWidth
                if (width < 768) {
                    element.classList.add('mobile');
                    element.classList.remove('desktop');
                } else {
                    element.classList.add('desktop');
                    element.classList.remove('mobile');
                }
            };
            // Run initially
            handler();
            // Add resize listener
            window.addEventListener('resize', handler);
            // Store for cleanup
            resizeListeners.push({ element: window, handler });
        });
    };

    // Function to load content
const loadContent = async (url, isDm = false) => {
    try {
        const response = await fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        if (!response.ok) throw new Error('Network response was not ok');
        const data = await response.text();

        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = data;

        const newTitle = tempDiv.querySelector('title')?.textContent || document.title;

        document.dispatchEvent(new CustomEvent('beforeContentUnload'));
        cleanupResizeListeners();

        // Find the content container, fall back to body if not found
        let contentContainer = document.getElementById('content-container');
        if (!contentContainer) {
            console.warn('Uyarı: #content-container bulunamadı, body kullanılıyor.');
            contentContainer = document.body;
        }

        // Inject content into the container
        contentContainer.innerHTML = tempDiv.innerHTML;

        // Debug: Verify critical elements
        const requiredElements = ['#server-sidebar', '#channel-sidebar-x', '#user-sidebar', '#content-container'];
        requiredElements.forEach(selector => {
            if (!document.querySelector(selector)) {
                console.warn(`Uyarı: ${selector} bulunamadı.`);
            }
        });

        document.title = newTitle;

        await executeScripts(contentContainer);

      
        initResizeDependentLogic(contentContainer);

        // Call updateSagOrtaDiv after a short delay
        setTimeout(() => {
            if (typeof window.updateSagOrtaDiv === 'function') {
                window.updateSagOrtaDiv();
            }
        }, 0);

        document.dispatchEvent(new Event('DOMContentLoaded', { bubbles: true, cancelable: true }));

        const state = { page: isDm ? 'dm' : 'server' };
        const title = isDm ? 'Direct Messages' : `Server ${url.split('id=')[1] || ''}`;
        history.pushState(state, title, url);
    } catch (error) {
        console.error('Error loading content:', error);
        const contentContainer = document.getElementById('content-container') || document.body;
        contentContainer.innerHTML = '<p>Error loading content. Please try again.</p>';
    }
};

    // Handle DM link click
    dmLink.addEventListener('click', (e) => {
        e.preventDefault();
        loadContent('directmessages.php', true);
        setActiveLink(dmLink);
    });

    // Handle server link clicks
    serverLinks.forEach(link => {
        link.addEventListener('click', (e) => {
            e.preventDefault();
            const url = link.getAttribute('href');
            loadContent(url);
            setActiveLink(link);
        });
    });

    // Handle browser back/forward navigation
    window.addEventListener('popstate', (e) => {
        const url = window.location.pathname + window.location.search;
        loadContent(url, url.includes('directmessages'));
        const isDm = url.includes('directmessages');
        const link = isDm ? dmLink : [...serverLinks].find(l => l.getAttribute('href') === url);
        if (link) setActiveLink(link);
    });

    // Load initial content based on current URL
    const currentUrl = window.location.pathname + window.location.search;
    if (currentUrl.includes('directmessages')) {
        loadContent('directmessages.php', true);
        setActiveLink(dmLink);
    } else if (currentUrl.includes('server?id=')) {
        loadContent(currentUrl);
        const activeServerLink = [...serverLinks].find(link => link.getAttribute('href') === currentUrl);
        if (activeServerLink) setActiveLink(activeServerLink);
    }
});
 </script>
</body>
</html>