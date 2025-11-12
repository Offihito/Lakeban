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

// Session check
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Get server ID from POST
if (!isset($_POST['server_id'])) {
    die("Server ID is missing.");
}

$server_id = $_POST['server_id'];

// Check if the user is a member of the server
$stmt = $db->prepare("SELECT * FROM server_members WHERE server_id = ? AND user_id = ?");
$stmt->execute([$server_id, $_SESSION['user_id']]);

if ($stmt->rowCount() === 0) {  
    die("You are not a member of this server.");
}

// Fetch the server name
$stmt = $db->prepare("SELECT name, banner FROM servers WHERE id = ?");
$stmt->execute([$server_id]);
$server_info = $stmt->fetch();

$server_name = $server_info['name'];
$banner_url = $server_info['banner'];

// Fetch channels for the server
$stmt = $db->prepare("SELECT * FROM channels WHERE server_id = ? ORDER BY created_at ASC");
$stmt->execute([$server_id]);
$channels = $stmt->fetchAll();

// Default to the first channel if no channel is selected
$channel_id = isset($_GET['channel_id']) ? $_GET['channel_id'] : ($channels[0]['id'] ?? null);

// Fetch the channel name based on the selected channel ID
$stmt = $db->prepare("SELECT name FROM channels WHERE id = ?");
$stmt->execute([$channel_id]);
$channel_name = $stmt->fetchColumn();

// Fetch user profile data
$stmt = $db->prepare("
    SELECT u.avatar_url 
    FROM user_profiles u 
    JOIN server_members s ON u.user_id = s.user_id 
    WHERE s.server_id = ? AND s.user_id = ?
");
$stmt->execute([$server_id, $_SESSION['user_id']]);
$user_profile = $stmt->fetch();

$avatar_url = $user_profile['avatar_url'] ?? null; // Default avatar path

// Fetch current user's username
$stmt = $db->prepare("SELECT username FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$current_user_username = $stmt->fetchColumn();

// Get messages with sender details for the selected channel
$stmt = $db->prepare("
    SELECT m.*, u.username, up.avatar_url 
    FROM messages1 m 
    JOIN users u ON m.sender_id = u.id 
    LEFT JOIN user_profiles up ON m.sender_id = up.user_id 
    WHERE m.server_id = ? AND m.channel_id = ? 
    ORDER BY m.created_at ASC
");
$stmt->execute([$server_id, $channel_id]);
$messages = $stmt->fetchAll();

// Get invite link if available
$stmt = $db->prepare("SELECT invite_code FROM server_invites WHERE server_id = ?");
$stmt->execute([$server_id]);
$invite_code = $stmt->fetchColumn();
$invite_link = "http://lakeban.000.pe/join_server.php?code=$invite_code";

// Fetch server members with their roles and colors
$stmt = $db->prepare("
    SELECT 
        us.id AS user_id, 
        up.avatar_url, 
        s.status, 
        us.username, 
        GROUP_CONCAT(r.name ORDER BY r.importance DESC) AS role_names, 
        GROUP_CONCAT(r.color ORDER BY r.importance DESC) AS role_colors
    FROM 
        users us
    JOIN 
        server_members s ON us.id = s.user_id
    LEFT JOIN 
        user_profiles up ON us.id = up.user_id
    LEFT JOIN 
        user_roles ur ON us.id = ur.user_id AND ur.server_id = s.server_id
    LEFT JOIN 
        roles r ON ur.role_id = r.id
    WHERE 
        s.server_id = ?
    GROUP BY 
        us.id
");
$stmt->execute([$server_id]);
$server_members = $stmt->fetchAll();

// Group members by their highest role
$role_groups = [];
$online_members = [];
$offline_members = [];

foreach ($server_members as $member) {
    $roles = explode(',', $member['role_names'] ?? '');
    $colors = explode(',', $member['role_colors'] ?? '');
    if (empty($roles[0])) {
        if ($member['status'] == 'online') {
            $online_members[] = $member;
        } else {
            $offline_members[] = $member;
        }
    } else {
        $highest_role = $roles[0]; // The first role is the highest
        $highest_color = $colors[0]; // The first color is the highest
        if (!isset($role_groups[$highest_role])) {
            $role_groups[$highest_role] = [];
        }
        $member['highest_color'] = $highest_color;
        $role_groups[$highest_role][] = $member;
    }
}

// Fetch current user's roles and their colors
$stmt = $db->prepare("
    SELECT r.name, r.color 
    FROM roles r 
    JOIN user_roles ur ON r.id = ur.role_id 
    WHERE ur.user_id = ? AND ur.server_id = ?
    ORDER BY r.importance DESC
");
$stmt->execute([$_SESSION['user_id'], $server_id]);
$user_roles = $stmt->fetchAll();

// Get the highest role color for the current user
$highest_role_color = $user_roles[0]['color'] ?? '#ffffff'; // Default to white if no roles are found

// Ensure highest_role_color is not null before passing it to htmlspecialchars
$highest_role_color = $highest_role_color !== null ? htmlspecialchars($highest_role_color, ENT_QUOTES, 'UTF-8') : '#ffffff';

?>

<!-- Server Details -->
<div class="flex-1 bg-gray-700 flex flex-col">
    <!-- Top Menu -->
    <div class="h-16 bg-gray-800 border-b border-gray-900 flex items-center px-6 relative">
        <h3 class="text-white text-lg font-medium"><?php echo htmlspecialchars($server_name, ENT_QUOTES, 'UTF-8'); ?></h3>
    </div>

    <!-- Invite Link Input -->
    <div class="p-6 bg-gray-800 flex items-center">
        <input type="text" id="invite-link" class="w-full bg-gray-700 text-white px-4 py-3 rounded-lg mr-4" value="<?php echo $invite_link; ?>" readonly>
        <button id="copy-invite-link" class="bg-green-500 text-white px-6 py-3 rounded-lg hover:bg-green-600">Copy</button>
    </div>

    <!-- Messages -->
    <div class="flex-1 overflow-y-auto p-6 custom-scrollbar" id="message-container">
        <?php if (empty($messages)): ?>
            <div class="text-center text-gray-400 text-lg">
                Efsanevi bir sohbeti başlat!
            </div>
        <?php else: ?>
            <?php foreach ($messages as $message): ?>
                <?php $is_current_user = ($message['sender_id'] == $_SESSION['user_id']); ?>
                <div class="bg-gray-800 rounded-lg p-4 mb-4 shadow-md" data-message-id="<?php echo $message['id']; ?>">
                    <div class="flex items-start">
                        <div class="w-10 h-10 bg-green-500 rounded-full flex items-center justify-center mr-3">
                            <?php if (!empty($message['avatar_url'])): ?>
                                <img src="<?php echo htmlspecialchars($message['avatar_url'], ENT_QUOTES, 'UTF-8'); ?>" alt="Profile Photo" class="w-full h-full rounded-full">
                            <?php else: ?>
                                <span class="text-white font-medium"><?php echo strtoupper(substr($message['username'], 0, 1)); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="flex-1">
                            <div class="text-white text-sm font-medium"><?php echo htmlspecialchars($message['username'], ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="text-gray-300 text-base"><?php echo htmlspecialchars($message['message_text'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
                            <?php if (!empty($message['file_url'])): ?>
                                <?php if (strpos($message['file_type'], 'image/') === 0): ?>
                                    <img src="<?php echo htmlspecialchars($message['file_url'], ENT_QUOTES, 'UTF-8'); ?>" alt="Uploaded image" class="max-w-full h-auto rounded-lg mt-2">
                                <?php elseif (strpos($message['file_type'], 'video/') === 0): ?>
                                    <video src="<?php echo htmlspecialchars($message['file_url'], ENT_QUOTES, 'UTF-8'); ?>" controls class="max-w-full h-auto rounded-lg mt-2"></video>
                                <?php endif; ?>
                            <?php endif; ?>
                            <div class="text-gray-400 text-xs mt-1"><?php echo (new DateTime($message['created_at']))->format('H:i'); ?></div>
                            <?php if ($is_current_user): ?>
                                <div class="flex space-x-2 mt-2">
                                    <button class="edit-message text-blue-500 hover:text-blue-600 p-1 rounded hover:bg-gray-700" data-message-id="<?php echo $message['id']; ?>">
                                        <i data-lucide="edit-2" class="w-4 h-4"></i>
                                    </button>
                                    <button class="delete-message text-red-500 hover:text-red-600 p-1 rounded hover:bg-gray-700" data-message-id="<?php echo $message['id']; ?>">
                                        <i data-lucide="trash-2" class="w-4 h-4"></i>
                                    </button>
                                </div>
                            <?php else: ?>
                                <div class="flex space-x-2 mt-2">
                                    <button class="reply-message text-blue-500 hover:text-blue-600 p-1 rounded hover:bg-gray-700" data-message-id="<?php echo $message['id']; ?>">
                                        <i data-lucide="corner-down-left" class="w-4 h-4"></i>
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Message Input -->
    <form method="POST" action="server.php?id=<?php echo $server_id; ?>&channel_id=<?php echo $channel_id; ?>" class="p-6 bg-gray-800 flex items-center" enctype="multipart/form-data" id="message-form">
        <input type="text" name="message" class="w-full bg-gray-700 text-white px-4 py-3 rounded-lg mr-4" placeholder="Type a message..." required>
        <input type="file" name="file" class="hidden" id="file-input" accept="image/*,video/*">
        <label for="file-input" class="text-gray-400 hover:text-white p-1 rounded hover:bg-gray-700">
            <i data-lucide="paperclip" class="w-6 h-6"></i>
        </label>
        <button type="submit" class="bg-green-500 text-white px-6 py-3 rounded-lg hover:bg-green-600">Send</button>
    </form>
</div>

<!-- User Profile Sidebar -->
<div class="w-64 bg-gray-800 flex flex-col">
    <div class="h-16 bg-gray-800 border-b border-gray-900 flex items-center px-4">
        <h3 class="text-white text-lg font-medium">Üyeler</h3>
    </div>
    <div class="flex-1 overflow-y-auto custom-scrollbar p-4">
        <!-- Role Groups -->
        <?php foreach ($role_groups as $role => $members): ?>
            <div class="mb-4">
                <div class="text-gray-400 text-sm mb-2"><?php echo htmlspecialchars($role, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php foreach ($members as $member): ?>
                    <div class="flex items-center mb-2">
                        <div class="relative">
                            <div class="w-10 h-10 rounded-full flex items-center justify-center bg-indigo-500">
                                <?php if (!empty($member['avatar_url'])): ?>
                                    <img src="<?php echo htmlspecialchars($member['avatar_url'], ENT_QUOTES, 'UTF-8'); ?>" alt="Profile Photo" class="w-full h-full rounded-full">
                                <?php else: ?>
                                    <span class="text-white font-medium"><?php echo strtoupper(substr($member['username'], 0, 1)); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="absolute bottom-0 right-0 w-3 h-3 <?php echo $member['status'] == 'online' ? 'bg-green-500' : 'bg-gray-500'; ?> rounded-full border-2 border-gray-900"></div>
                        </div>
                        <div class="ml-2">
                            <a href="/id/<?php echo htmlspecialchars($member['username'], ENT_QUOTES, 'UTF-8'); ?>" class="text-white text-sm font-medium" style="color: <?php echo htmlspecialchars($member['highest_color'] ?? '#ffffff', ENT_QUOTES, 'UTF-8'); ?>;">
                                <?php echo htmlspecialchars($member['username'], ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                            <div class="text-gray-400 text-xs">
                                <?php if ($member['status'] == 'online'): ?>
                                    Online
                                <?php else: ?>
                                    Offline
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>

        <!-- Online Members -->
        <?php if (!empty($online_members)): ?>
            <div class="mb-4">
                <div class="text-gray-400 text-sm mb-2">Online</div>
                <?php foreach ($online_members as $member): ?>
                    <div class="flex items-center mb-2">
                        <div class="relative">
                            <div class="w-10 h-10 rounded-full flex items-center justify-center bg-indigo-500">
                                <?php if (!empty($member['avatar_url'])): ?>
                                    <img src="<?php echo htmlspecialchars($member['avatar_url'], ENT_QUOTES, 'UTF-8'); ?>" alt="Profile Photo" class="w-full h-full rounded-full">
                                <?php else: ?>
                                    <span class="text-white font-medium"><?php echo strtoupper(substr($member['username'], 0, 1)); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="absolute bottom-0 right-0 w-3 h-3 bg-green-500 rounded-full border-2 border-gray-900"></div>
                        </div>
                        <div class="ml-2">
                            <a href="/id/<?php echo htmlspecialchars($member['username'], ENT_QUOTES, 'UTF-8'); ?>" class="text-white text-sm font-medium" style="color: <?php echo htmlspecialchars($member['highest_color'] ?? '#ffffff', ENT_QUOTES, 'UTF-8'); ?>;">
                                <?php echo htmlspecialchars($member['username'], ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                            <div class="text-gray-400 text-xs">Online</div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Offline Members -->
        <?php if (!empty($offline_members)): ?>
            <div class="mb-4">
                <div class="text-gray-400 text-sm mb-2">Offline</div>
                <?php foreach ($offline_members as $member): ?>
                    <div class="flex items-center mb-2">
                        <div class="relative">
                            <div class="w-10 h-10 rounded-full flex items-center justify-center bg-indigo-500">
                                <?php if (!empty($member['avatar_url'])): ?>
                                    <img src="<?php echo htmlspecialchars($member['avatar_url'], ENT_QUOTES, 'UTF-8'); ?>" alt="Profile Photo" class="w-full h-full rounded-full">
                                <?php else: ?>
                                    <span class="text-white font-medium"><?php echo strtoupper(substr($member['username'], 0, 1)); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="absolute bottom-0 right-0 w-3 h-3 bg-gray-500 rounded-full border-2 border-gray-900"></div>
                        </div>
                        <div class="ml-2">
                            <a href="/id/<?php echo htmlspecialchars($member['username'], ENT_QUOTES, 'UTF-8'); ?>" class="text-white text-sm font-medium" style="color: <?php echo htmlspecialchars($member['highest_color'] ?? '#ffffff', ENT_QUOTES, 'UTF-8'); ?>;">
                                <?php echo htmlspecialchars($member['username'], ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                            <div class="text-gray-400 text-xs">Offline</div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    lucide.createIcons();

    // Copy invite link to clipboard
    document.getElementById('copy-invite-link').addEventListener('click', function() {
        var inviteLink = document.getElementById('invite-link');
        inviteLink.select();
        inviteLink.setSelectionRange(0, 99999); // For mobile devices
        document.execCommand('copy');
        alert('Invite link copied to clipboard!');
    });

    // Reply to a message
    document.querySelectorAll('.reply-message').forEach(button => {
        button.addEventListener('click', function() {
            const messageDiv = this.closest('.bg-gray-800');
            const username = messageDiv.querySelector('.text-white.text-sm.font-medium').innerText;
            const replyText = `@${username} `;
            document.querySelector('input[name="message"]').value = replyText;
            document.querySelector('input[name="message"]').focus();
            document.querySelector('input[name="message"]').dataset.replyTo = this.getAttribute('data-message-id');
        });
    });

    // Edit a message
    document.querySelectorAll('.edit-message').forEach(button => {
        button.addEventListener('click', function() {
            const messageId = this.getAttribute('data-message-id');
            const messageText = this.closest('.bg-gray-800').querySelector('.text-gray-300').innerText;
            const newMessageText = prompt('Edit your message:', messageText);
            if (newMessageText !== null) {
                fetch('edit_message.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `message_id=${messageId}&new_message_text=${encodeURIComponent(newMessageText)}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        this.closest('.bg-gray-800').querySelector('.text-gray-300').innerText = newMessageText;
                    } else {
                        alert('Failed to edit message.');
                    }
                });
            }
        });
    });

    // Delete a message
    document.querySelectorAll('.delete-message').forEach(button => {
        button.addEventListener('click', function() {
            const messageId = this.getAttribute('data-message-id');
            if (confirm('Are you sure you want to delete this message?')) {
                fetch('delete_message.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `message_id=${messageId}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        this.closest('.bg-gray-800').remove();
                    } else {
                        alert('Failed to delete message.');
                    }
                });
            }
        });
    });

    // Handle file upload
    document.getElementById('file-input').addEventListener('change', function() {
        const file = this.files[0];
        if (file) {
            const formData = new FormData();
            formData.append('file', file);
            formData.append('server_id', <?php echo $server_id; ?>);
            formData.append('channel_id', <?php echo $channel_id; ?>);

            fetch('serverupload.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('File uploaded successfully.');
                    // Fetch latest messages and update the UI
                    loadMessages(<?php echo $server_id; ?>, <?php echo $channel_id; ?>);
                } else {
                    alert('Failed to upload file: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Failed to upload file. Please try again.');
            });
        }
    });

    // Function to load messages
    function loadMessages(serverId, channelId) {
        return new Promise((resolve, reject) => {
            fetch('get_messages.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=get_messages&server_id=${serverId}&channel_id=${channelId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const container = document.getElementById('message-container');
                    container.innerHTML = '';

                    data.messages.forEach(message => {
                        const messageDiv = document.createElement('div');
                        messageDiv.className = 'bg-gray-800 rounded-lg p-4 mb-4 shadow-md';
                        messageDiv.dataset.messageId = message.id;

                        const messageText = document.createElement('div');
                        messageText.className = 'text-gray-300 text-base';
                        messageText.innerText = message.message_text;
                        messageDiv.appendChild(messageText);

                        if (message.file_url) {
                            if (message.file_type.startsWith('image/')) {
                                const img = document.createElement('img');
                                img.src = message.file_url;
                                img.alt = 'Uploaded image';
                                img.className = 'max-w-full h-auto rounded-lg mt-2';
                                img.addEventListener('click', () => {
                                    window.open(message.file_url, '_blank');
                                });
                                messageDiv.appendChild(img);
                            } else if (message.file_type.startsWith('video/')) {
                                const video = document.createElement('video');
                                video.src = message.file_url;
                                video.className = 'max-w-full h-auto rounded-lg mt-2';
                                video.controls = true;
                                messageDiv.appendChild(video);
                            }
                        }

                        const timestamp = document.createElement('div');
                        timestamp.className = 'text-gray-400 text-xs mt-1';
                        timestamp.innerText = (new Date(message.created_at)).toLocaleTimeString();
                        messageDiv.appendChild(timestamp);

                        const buttonsDiv = document.createElement('div');
                        buttonsDiv.className = 'flex space-x-2 mt-2';

                        if (message.sender_id === <?php echo $_SESSION['user_id']; ?>) {
                            const editButton = document.createElement('button');
                            editButton.className = 'edit-message text-blue-500 hover:text-blue-600 p-1 rounded hover:bg-gray-700';
                            editButton.dataset.messageId = message.id;
                            editButton.innerHTML = '<i data-lucide="edit-2" class="w-4 h-4"></i>';
                            buttonsDiv.appendChild(editButton);

                            const deleteButton = document.createElement('button');
                            deleteButton.className = 'delete-message text-red-500 hover:text-red-600 p-1 rounded hover:bg-gray-700';
                            deleteButton.dataset.messageId = message.id;
                            deleteButton.innerHTML = '<i data-lucide="trash-2" class="w-4 h-4"></i>';
                            buttonsDiv.appendChild(deleteButton);
                        } else {
                            const replyButton = document.createElement('button');
                            replyButton.className = 'reply-message text-blue-500 hover:text-blue-600 p-1 rounded hover:bg-gray-700';
                            replyButton.dataset.messageId = message.id;
                            replyButton.innerHTML = '<i data-lucide="corner-down-left" class="w-4 h-4"></i>';
                            buttonsDiv.appendChild(replyButton);
                        }

                        messageDiv.appendChild(buttonsDiv);
                        container.appendChild(messageDiv);
                    });

                    resolve();
                } else {
                    reject(new Error('Failed to load messages'));
                }
            })
            .catch(error => {
                reject(error);
            });
        });
    }

    // Load messages on page load
    loadMessages(<?php echo $server_id; ?>, <?php echo $channel_id; ?>)
        .then(() => {
            const container = document.getElementById('message-container');
            setTimeout(() => {
                container.scrollTop = container.scrollHeight;
            }, 0);
        })
        .catch(error => console.error('Error:', error));

    // Scroll to the bottom of the message container when the page loads
    window.onload = function() {
        const container = document.getElementById('message-container');
        container.scrollTop = container.scrollHeight;
    };
</script>