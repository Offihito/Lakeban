<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database connection
$db_host = 'localhost';
$db_user = 'lakebanc_Offihito';
$db_pass = 'P4QG(m2jkWXN';
$db_name = 'lakebanc_Database';

// Add error logging
function logError($message) {
    error_log(date('[Y-m-d H:i:s] ') . $message . PHP_EOL, 3, 'error.log');
}

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false
];

try {
    $db = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, $options);
} catch (PDOException $e) {
    logError("Database connection failed: " . $e->getMessage());
    die("Connection failed. Check error.log for details.");
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        switch ($_POST['action']) {
            case 'get_messages':
                if (!isset($_POST['server_id']) || !isset($_POST['channel_id'])) {
                    throw new Exception("Missing required parameters");
                }
                
                $server_id = filter_var($_POST['server_id'], FILTER_VALIDATE_INT);
                $channel_id = filter_var($_POST['channel_id'], FILTER_VALIDATE_INT);
                
                if (!$server_id || !$channel_id) {
                    throw new Exception("Invalid server or channel ID");
                }
                
                 $stmt = $db->prepare("
    SELECT 
        m.*,
        u.username as sender_username,
        up.avatar_url as sender_avatar,
        m.file_url,
        m.file_type,
        s.name as server_name,
        c.name as channel_name
    FROM messages1 m
    JOIN users u ON m.sender_id = u.id
    LEFT JOIN user_profiles up ON u.id = up.user_id
    JOIN servers s ON m.server_id = s.id
    JOIN channels c ON m.channel_id = c.id
    WHERE m.server_id = ? AND m.channel_id = ?
    ORDER BY m.created_at ASC
");
$stmt->execute([$server_id, $channel_id]);
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Mesajları JSON olarak döndürürken, özel karakterlerin doğru şekilde işlenmesini sağla
echo json_encode([
    'success' => true, 
    'messages' => array_map(function($message) {
        $message['message_text'] = htmlspecialchars_decode($message['message_text'], ENT_QUOTES);
        return $message;
    }, $messages),
    'server_name' => htmlspecialchars_decode($messages[0]['server_name'] ?? '', ENT_QUOTES),
    'channel_name' => htmlspecialchars_decode($messages[0]['channel_name'] ?? '', ENT_QUOTES)
], JSON_UNESCAPED_UNICODE);
                break;
                
            default:
                throw new Exception("Invalid action");
        }
    } catch (Exception $e) {
        error_log("Error in action {$_POST['action']}: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
}

// Get friends list
try {
    $stmt = $db->prepare("
        SELECT 
            u.id, 
            u.username, 
            up.avatar_url,
            CASE 
                WHEN TIMESTAMPDIFF(MINUTE, u.last_activity, CURRENT_TIMESTAMP) < 5 THEN 1 
                ELSE 0 
            END as is_online,
            COALESCE((
                SELECT last_message_at 
                FROM conversations 
                WHERE (user1_id = ? AND user2_id = u.id) 
                OR (user1_id = u.id AND user2_id = ?)
            ), '1970-01-01') as last_message_at
        FROM friends f
        JOIN users u ON (f.friend_id = u.id OR f.user_id = u.id)
        LEFT JOIN user_profiles up ON u.id = up.user_id
        WHERE (f.user_id = ? OR f.friend_id = ?) 
        AND u.id != ?
        ORDER BY last_message_at DESC
    ");
    $stmt->execute([
        $_SESSION['user_id'],
        $_SESSION['user_id'],
        $_SESSION['user_id'], 
        $_SESSION['user_id'], 
        $_SESSION['user_id']
    ]);
    $friends = $stmt->fetchAll();
} catch (Exception $e) {
    logError("Error fetching friends list: " . $e->getMessage());
    $friends = [];
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LakeBan - Messages</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        body { 
            background-color: #36393f; 
            color: #dcddde; 
            height: 100vh;
            display: flex;
            margin: 0;
            padding: 0;
        }
        .chat-container {
            display: flex;
            flex: 1;
            height: 100vh;
        }
        .friends-sidebar {
            width: 240px;
            background-color: #2f3136;
            overflow-y: auto;
            border-right: 1px solid #202225;
        }
        .chat-area {
            flex: 1;
            display: flex;
            flex-direction: column;
            background-color: #36393f;
        }
        .message-container {
            flex: 1;
            overflow-y: auto;
            padding: 1rem;
            display: flex;
            flex-direction: column;
        }
        .message {
            margin-bottom: 1rem;
            padding: 0.75rem;
            border-radius: 0.5rem;
            max-width: 80%;
            word-break: break-word;
        }
        .message.sent {
            background-color: #40444b;
            margin-left: auto;
            color: white;
        }
        .message.received {
            background-color: #32353b;
            margin-right: auto;
            color: white;
        }
        .input-area {
            padding: 1rem;
            background-color: #40444b;
            border-top: 1px solid #202225;
        }
        .friend-item {
            padding: 0.75rem;
            display: flex;
            align-items: center;
            cursor: pointer;
            transition: background-color 0.2s;
            border-bottom: 1px solid #202225;
        }
        .friend-item:hover {
            background-color: #36393f;
        }
        .friend-item.active {
            background-color: #40444b;
        }
        .avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            margin-right: 0.75rem;
            background-color: #5865f2;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: white;
            overflow: hidden;
        }
        #message-input {
            width: 100%;
            background-color: #40444b;
            color: white;
            border: 1px solid #202225;
            border-radius: 4px;
            padding: 10px;
            margin-right: 10px;
            outline: none;
        }
        #message-input:focus {
            border-color: #5865f2;
        }
        .send-button {
            background-color: #5865f2;
            color: white;
            border: none;
            border-radius: 4px;
            padding: 10px 20px;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        .send-button:hover {
            background-color: #4752c4;
        }
        .send-button:disabled {
            background-color: #72767d;
            cursor: not-allowed;
        }
    </style>
</head>
<body>
    <div class="chat-container">
        <!-- Friends Sidebar -->
        <div class="friends-sidebar">
            <div class="p-4 border-b border-gray-700">
                <h2 class="text-lg font-bold">Messages</h2>
            </div>
            <div id="friends-list">
                <?php foreach ($friends as $friend): ?>
                    <div class="friend-item" data-friend-id="<?php echo htmlspecialchars($friend['id'], ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="avatar">
                            <?php if (!empty($friend['avatar_url'])): ?>
                                <img src="<?php echo htmlspecialchars($friend['avatar_url'], ENT_QUOTES, 'UTF-8'); ?>" 
                                     alt="<?php echo htmlspecialchars($friend['username'], ENT_QUOTES, 'UTF-8'); ?>'s avatar"
                                     class="w-full h-full object-cover">
                            <?php else: ?>
                                <?php echo strtoupper(substr($friend['username'], 0, 1)); ?>
                            <?php endif; ?>
                        </div>
                        <div>
                            <div class="font-medium"><?php echo htmlspecialchars($friend['username'], ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="text-xs <?php echo $friend['is_online'] ? 'text-green-400' : 'text-gray-400'; ?>">
                                <?php echo $friend['is_online'] ? 'Online' : 'Offline'; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Chat Area -->
        <div class="chat-area">
            <div id="chat-header" class="p-4 border-b border-gray-700 hidden">
                <h3 class="text-lg font-bold"></h3>
            </div>
            <div id="message-container" class="message-container hidden">
                <!-- Messages will be loaded here -->
            </div>
            <div id="input-area" class="input-area hidden">
                <form id="message-form" class="flex space-x-2">
                    <input type="text" 
                           id="message-input" 
                           placeholder="Type a message..."
                           autocomplete="off">
                    <button type="submit" 
                            class="send-button"
                            id="send-button"
                            disabled>
                        Send
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
        let currentFriendId = null;
        let messageUpdateInterval = null;

        function loadMessages(friendId) {
            fetch('messages.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=get_messages&friend_id=${friendId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const container = document.getElementById('message-container');
                    container.innerHTML = '';
                    
                    data.messages.forEach(message => {
                        const messageDiv = document.createElement('div');
                        messageDiv.className = `message ${message.sender_id == <?php echo $_SESSION['user_id']; ?> ? 'sent' : 'received'}`;
                        
                        const messageContent = document.createElement('div');
                        messageContent.className = 'p-2';
                        messageContent.textContent = message.message_text;
                        
                        const timestamp = document.createElement('div');
                        timestamp.className = 'text-xs text-gray-400 mt-1';
                        timestamp.textContent = new Date(message.created_at).toLocaleString();
                        
                        messageDiv.appendChild(messageContent);
                        messageDiv.appendChild(timestamp);
                        container.appendChild(messageDiv);
                    });
                    
                    container.scrollTop = container.scrollHeight;
                }
            })
            .catch(error => console.error('Error:', error));
        }

        document.addEventListener('DOMContentLoaded', () => {
            const friendItems = document.querySelectorAll('.friend-item');
            const messageForm = document.getElementById('message-form');
            const messageInput = document.getElementById('message-input');
            const sendButton = document.getElementById('send-button');
            const chatHeader = document.getElementById('chat-header');
            const messageContainer = document.getElementById('message-container');
            const inputArea = document.getElementById('input-area');

            // Enable/disable send button based on input
            messageInput.addEventListener('input', () => {
                sendButton.disabled = !messageInput.value.trim();
            });

            friendItems.forEach(item => {
                item.addEventListener('click', () => {
                    // Remove active class from all items
                    friendItems.forEach(i => i.classList.remove('active'));
                    item.classList.add('active');

                    // Show chat interface
                    chatHeader.classList.remove('hidden');
                    messageContainer.classList.remove('hidden');
                    inputArea.classList.remove('hidden');

                    // Update chat header
                    const username = item.querySelector('.font-medium').textContent;
                    chatHeader.querySelector('h3').textContent = username;

                    // Load messages
                    currentFriendId = item.dataset.friendId;
                    loadMessages(currentFriendId);

                    // Clear existing interval and set new one
                    if (messageUpdateInterval) {
                        clearInterval(messageUpdateInterval);
                    }
                    messageUpdateInterval = setInterval(() => {
                        if (currentFriendId) {
                            loadMessages(currentFriendId);
                        }
                    }, 1000);

                    // Focus input field
                    messageInput.focus();
                });
            });

            messageForm.addEventListener('submit', (e) => {
                e.preventDefault();
                
                const message = messageInput.value.trim();
                
                if (message && currentFriendId) {
                    sendButton.disabled = true;
                    
                    fetch('messages.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `action=send_message&receiver_id=${currentFriendId}&message=${encodeURIComponent(message)}`
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            messageInput.value = '';
                            loadMessages(currentFriendId);
                        } else {
                            alert('Failed to send message');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('Error sending message');
                    })
                    .finally(() => {
                        sendButton.disabled = false;
                        messageInput.focus();
                    });
                }
            });
        });
    </script>
</body>
</html>