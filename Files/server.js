// server.js tüm kodu (güncellenmiş hali)

const https = require('https');
const fs = require('fs');
const path = require('path');
const WebSocket = require('ws');
const mysql = require('mysql2/promise');

// SSL dizin yapısı
const SSL_DIR = path.join(__dirname, 'ssl');
const PRIVATE_KEY_PATH = path.join(SSL_DIR, 'keys', 'c2ad3_a72fd_7c8743fbe78e3c727d852a37b4238b95.key');
const CERTIFICATE_PATH = path.join(SSL_DIR, 'certs', '_wildcard__lakeban_com_c2ad3_a72fd_1763181958_76a6b1c1ea89c78d2ac4be8bc54cacc8.crt');

// Veritabanı bağlantı ayarları
const dbConfig = {
  host: 'localhost',
  user: 'lakebanc_Offihito',
  password: 'P4QG(m2jkWXN',
  database: 'lakebanc_Database'
};

// SSL/TLS sertifikalarını yükle
const server = https.createServer({
  cert: fs.readFileSync(CERTIFICATE_PATH),
  key: fs.readFileSync(PRIVATE_KEY_PATH)
});

const wss = new WebSocket.Server({ 
  server,
  clientTracking: true,
  maxPayload: 1048576, // 1MB maksimum mesaj boyutu
});

// Veritabanından bekleyen istek sayısını al
async function getPendingRequestsCount(userId) {
  let connection;
  try {
    connection = await mysql.createConnection(dbConfig);
    const [rows] = await connection.execute(
      "SELECT COUNT(*) as request_count FROM friend_requests WHERE receiver_id = ? AND status = 'pending'",
      [userId]
    );
    return rows[0].request_count;
  } catch (error) {
    console.error('🚨 Veritabanı hatası:', error);
    return 0;
  } finally {
    if (connection) await connection.end();
  }
}

// Okunmamış mesajları getir
async function getUnreadCountsForUser(userId) {
  let connection;
  try {
    connection = await mysql.createConnection(dbConfig);
    const [rows] = await connection.execute(
      `SELECT sender_id, COUNT(*) as count 
       FROM messages1 
       WHERE receiver_id = ? AND read_status = FALSE
       GROUP BY sender_id`,
      [String(userId)]
    );
    return rows.reduce((acc, row) => {
      acc[row.sender_id] = row.count;
      return acc;
    }, {});
  } catch (error) {
    console.error(`🚨 Okunmamış mesaj sayımı alınırken hata (userId: ${userId}):`, error);
    return {};
  } finally {
    if (connection) {
      try {
        await connection.end();
      } catch (err) {
        console.error('🚨 Bağlantı kapatma hatası (getUnreadCountsForUser):', err);
      }
    }
  }
}

const voiceChannels = new Map(); // Sesli kanalları ve ekran paylaşımı durumlarını tutar
const screenSharers = new Map(); // channelId -> userId (ekran paylaşımı yapan kullanıcı)
const users = new Map(); // userId -> { wsSet: Set<WebSocket>, pendingCount, username, avatar_url }

// Bağlantıları canlı tutmak için ping-pong mekanizması
function setupHeartbeat(ws, pingInterval = 30000) {
  let isAlive = true;

  const heartbeatInterval = setInterval(() => {
    if (!isAlive) {
      console.log(' APL Bağlantı zaman aşımına uğradı, kapatılıyor...');
      ws.terminate();
      return;
    }

    isAlive = false;
    ws.ping(null, false, (err) => {
      if (err) console.error(' APL Ping gönderilirken hata:', err);
    });
  }, pingInterval);

  ws.on('pong', () => {
    isAlive = true;
  });

  ws.on('close', () => {
    clearInterval(heartbeatInterval);
  });
}

// Doğrudan mesajlar için benzersiz kanal ID'si oluşturma
function generateDMChannelId(userId1, userId2) {
  const id1 = parseInt(userId1);
  const id2 = parseInt(userId2);
  if (isNaN(id1) || isNaN(id2)) {
    console.error(`🚨 Geçersiz userId veya friendId: ${userId1}, ${userId2}`);
    return null;
  }
  const minId = Math.min(id1, id2);
  const maxId = Math.max(id1, id2);
  return `dm_${minId}_${maxId}`;
}

async function getGroupMembers(groupId) {
  let connection;
  try {
    connection = await mysql.createConnection(dbConfig);
    const [rows] = await connection.execute(
      "SELECT user_id FROM group_members WHERE group_id = ?",
      [groupId]
    );
    return rows.map(row => String(row.user_id));
  } catch (error) {
    console.error('🚨 Veritabanı hatası:', error);
    return [];
  } finally {
    if (connection) await connection.end();
  }
}

async function getChannelMembers(serverId, channelId) {
  let connection;
  try {
    connection = await mysql.createConnection(dbConfig);
    const [rows] = await connection.execute(
      "SELECT DISTINCT sm.user_id FROM server_members sm " +
      "JOIN channels c ON c.server_id = sm.server_id " +
      "WHERE sm.server_id = ? AND c.id = ?",
      [serverId, channelId]
    );
    return rows.map(row => String(row.user_id));
  } catch (error) {
    console.error('🚨 Veritabanı hatası (getChannelMembers):', error);
    return [];
  } finally {
    if (connection) await connection.end();
  }
}

// Yeni: Reaksiyon ekle/kaldır (veritabanı işlemi)
async function addOrRemoveReaction(messageId, userId, emoji) {
  let connection;
  try {
    connection = await mysql.createConnection(dbConfig);
    // Önce var mı kontrol et
    const [existing] = await connection.execute(
      "SELECT id FROM reactions WHERE message_id = ? AND user_id = ? AND emoji = ?",
      [messageId, userId, emoji]
    );

    let action;
    if (existing.length > 0) {
      // Kaldır
      await connection.execute(
        "DELETE FROM reactions WHERE message_id = ? AND user_id = ? AND emoji = ?",
        [messageId, userId, emoji]
      );
      action = 'removed';
    } else {
      // Ekle
      await connection.execute(
        "INSERT INTO reactions (message_id, user_id, emoji) VALUES (?, ?, ?)",
        [messageId, userId, emoji]
      );
      action = 'added';
    }
    return action;
  } catch (error) {
    console.error('🚨 Reaksiyon ekleme/kaldırma hatası:', error);
    throw error;
  } finally {
    if (connection) await connection.end();
  }
}

// Yeni: Mesaj reaksiyonlarını getir (emoji: count şeklinde)
async function getMessageReactions(messageId) {
  let connection;
  try {
    connection = await mysql.createConnection(dbConfig);
    const [rows] = await connection.execute(
      "SELECT emoji, COUNT(*) as count FROM reactions WHERE message_id = ? GROUP BY emoji",
      [messageId]
    );
    return rows.reduce((acc, row) => {
      acc[row.emoji] = row.count;
      return acc;
    }, {});
  } catch (error) {
    console.error('🚨 Reaksiyonları alma hatası:', error);
    return {};
  } finally {
    if (connection) await connection.end();
  }
}

// Yeni: Mesaj silme işlemi (veritabanı)
async function deleteMessage(messageId, senderId) {
  let connection;
  try {
    connection = await mysql.createConnection(dbConfig);
    
    // Mesaj detaylarını al (DM mi grup mu, receiver/group_id)
    const [messageRows] = await connection.execute(
      "SELECT sender_id, receiver_id, group_id FROM messages1 WHERE id = ?",
      [messageId]
    );
    
    if (messageRows.length === 0) {
      throw new Error('Mesaj bulunamadı');
    }
    
    const messageDetails = messageRows[0];
    
    if (String(messageDetails.sender_id) !== String(senderId)) {
      throw new Error('Yetki yok: Sadece mesaj sahibi silebilir');
    }
    
    // Mesajı sil
    await connection.execute(
      "DELETE FROM messages1 WHERE id = ?",
      [messageId]
    );
    
    return {
      success: true,
      receiverId: messageDetails.receiver_id ? String(messageDetails.receiver_id) : null,
      groupId: messageDetails.group_id ? String(messageDetails.group_id) : null
    };
  } catch (error) {
    console.error('🚨 Mesaj silme hatası:', error);
    return { success: false, error: error.message };
  } finally {
    if (connection) await connection.end();
  }
}

wss.on('connection', function connection(ws) {
  console.log('✅ Yeni bir kullanıcı bağlandı. Toplam bağlantı:', wss.clients.size);

  let userId = null;
  let username = null;
  let avatarUrl = null;

  // Heartbeat başlat
  setupHeartbeat(ws);

  // Mesaj işleme
  ws.on('message', async function incoming(rawMessage) {
    try {
      const message = JSON.parse(rawMessage.toString());
      console.log('📥 Alınan mesaj:', JSON.stringify(message, null, 2));

      if (message.type === 'auth') {
        userId = String(message.userId);
        username = message.username || `User-${userId}`;
        avatarUrl = message.avatarUrl || 'avatars/default-avatar.png';
        
        // Kullanıcı yoksa yeni bir Set oluştur, varsa mevcut Set'e ekle
        if (!users.has(userId)) {
          users.set(userId, {
            wsSet: new Set(),
            pendingCount: await getPendingRequestsCount(userId),
            username,
            avatar_url: avatarUrl
          });
        }
        const userData = users.get(userId);
        userData.wsSet.add(ws);
        console.log(`📥 Auth mesajı alındı: userId=${userId}, username=${username}, avatarUrl=${avatarUrl}`);
        console.log(`📚 Users haritası güncellendi:`, userData);

        // Bekleyen istek sayısını gönder
        ws.send(JSON.stringify({ type: 'pending-count', count: userData.pendingCount }));

        // Okunmamış mesajları gönder
        const unreadCounts = await getUnreadCountsForUser(userId);
        ws.send(JSON.stringify({ type: 'unread-update', counts: unreadCounts }));

        // Diğer kullanıcılara okunmamış mesaj güncellemesi gönder
        users.forEach(async (user, id) => {
          if (id !== userId) {
            const counts = await getUnreadCountsForUser(id);
            user.wsSet.forEach(ws => {
              if (ws.readyState === WebSocket.OPEN) {
                ws.send(JSON.stringify({ type: 'unread-update', counts: counts }));
              }
            });
          }
        });
        console.log(`✅ Kullanıcı doğrulandı: ${userId} (${username})`);
        return;
      }

      if (!userId) {
        console.warn('⚠️ Kimlik doğrulaması yapılmamış bir mesaj alındı:', message.type);
        ws.send(JSON.stringify({ type: 'error', message: 'Kimlik doğrulaması gerekli' }));
        return;
      }

      if (message.type === 'incoming-call') {
        const callerInfo = users.get(userId);
        const targetUser = users.get(String(message.targetId));
        if (targetUser) {
          targetUser.wsSet.forEach(targetWs => {
            if (targetWs.readyState === WebSocket.OPEN) {
              targetWs.send(JSON.stringify({
                type: 'incoming-call',
                callerId: userId,
                callerUsername: callerInfo.username,
                callerAvatar: callerInfo.avatar_url
              }));
              console.log(`📞 Arama isteği yönlendirildi: ${callerInfo.username} -> ${targetUser.username}`);
            }
          });
        } else {
          console.log(`⚠️ Arama alıcısı bulunamadı veya çevrimdışı: ${message.targetId}`);
          ws.send(JSON.stringify({ type: 'call-unavailable', targetId: message.targetId }));
        }
        return;
      }
if (message.type === 'voice-mute') {
    const { channelId, userId, muted } = message;
    
    // Kanalı kontrol et
    const channel = voiceChannels.get(channelId.toString());
    if (channel && channel.users.has(userId.toString())) {
        // Diğer katılımcılara susturma durumunu yayınla
        channel.users.forEach(participantId => {
            if (participantId !== userId.toString()) {
                const participant = users.get(participantId);
                if (participant) {
                    participant.wsSet.forEach(participantWs => {
                        if (participantWs.readyState === WebSocket.OPEN) {
                            participantWs.send(JSON.stringify({
                                type: 'voice-mute',
                                userId,
                                muted
                            }));
                        }
                    });
                }
            }
        });
    }
    return;
}
      if (message.type === 'call-accepted') {
        const accepterInfo = users.get(String(message.userId));
        const targetUser = users.get(String(message.targetId));
        if (!accepterInfo) {
          console.log(`⚠️ Kabul eden kullanıcı bulunamadı: ${message.userId}`);
          ws.send(JSON.stringify({ type: 'error', message: 'Kullanıcı bulunamadı' }));
          return;
        }
        if (!targetUser) {
          console.log(`⚠️ Hedef kullanıcı bulunamadı veya çevrimdışı: ${message.targetId}`);
          ws.send(JSON.stringify({ type: 'error', message: 'Hedef kullanıcı çevrimdışı' }));
          return;
        }

        const callAcceptedResponse = {
          type: 'call-accepted',
          accepterId: message.userId,
          channelId: message.channelId,
          accepterUsername: accepterInfo.username || `User-${message.userId}`,
          avatar_url: message.avatar_url || accepterInfo.avatar_url || 'avatars/default-avatar.png'
        };
        console.log('📤 Gönderilen call-accepted mesajı:', JSON.stringify(callAcceptedResponse, null, 2));
        targetUser.wsSet.forEach(targetWs => {
          if (targetWs.readyState === WebSocket.OPEN) {
            targetWs.send(JSON.stringify(callAcceptedResponse));
            console.log(`📞 Arama kabul edildi: ${accepterInfo.username} -> ${targetUser.username}, kanal: ${message.channelId}`);
          }
        });
        return;
      }

      if (message.type === 'call-declined') {
        const declinerInfo = users.get(String(userId));
        const originalCaller = users.get(String(message.targetId));
        if (originalCaller) {
          originalCaller.wsSet.forEach(callerWs => {
            if (callerWs.readyState === WebSocket.OPEN) {
              callerWs.send(JSON.stringify({
                type: 'call-declined',
                declinerId: userId,
                declinerUsername: declinerInfo?.username || 'Bilinmeyen Kullanıcı'
              }));
              console.log(`❌ Arama reddedildi: ${declinerInfo?.username} -> ${originalCaller.username}`);
            }
          });
        }
        return;
      }
if (message.type === 'message-sent') {
    const receiverId = message.receiverId ? String(message.receiverId) : null;
    const groupId = message.groupId ? String(message.groupId) : null;
    const serverId = message.serverId ? String(message.serverId) : null;
    const channelId = message.channelId ? String(message.channelId) : null;
    const senderId = String(message.senderId);

    console.log(`message-sent alındı:`, { senderId, receiverId, groupId, serverId, channelId, message: message.message });

    // === UNREAD UPDATE FONKSİYONU (Yardımcı) ===
    const sendUnreadUpdate = async (userId) => {
        const userData = users.get(userId);
        if (!userData) return;

        const unreadCounts = await getUnreadCountsForUser(userId);
        userData.wsSet.forEach(ws => {
            if (ws.readyState === WebSocket.OPEN) {
                ws.send(JSON.stringify({
                    type: 'unread-update',
                    counts: unreadCounts,
                    debug: { origin: 'message-sent', triggeredBy: senderId }
                }));
                console.log(`Unread update gönderildi: ${userId} (${JSON.stringify(unreadCounts)})`);
            }
        });
    };

    // === 1. DM MESAJI ===
    if (receiverId && !groupId && !serverId && !channelId) {
        // Alıcıya mesaj gönder
        const receiverData = users.get(receiverId);
        if (receiverData) {
            receiverData.wsSet.forEach(receiverWs => {
                if (receiverWs.readyState === WebSocket.OPEN) {
                    receiverWs.send(JSON.stringify({
                        type: 'new-direct-message',
                        receiverId,
                        senderId,
                        message: message.message,
                        files: message.files || []
                    }));
                    console.log(`DM mesajı broadcast edildi: ${senderId} -> ${receiverId}`);
                }
            });
        } else {
            console.log(`Alıcı çevrimdışı veya users map'inde yok: ${receiverId}`);
        }

        // Unread update: Alıcıya (ve gönderene de kendi sayacını güncellemek için)
        await sendUnreadUpdate(receiverId);
        await sendUnreadUpdate(senderId);
    }

    // === 2. GRUP MESAJI ===
    else if (groupId && !serverId) {
        const groupMembers = await getGroupMembers(groupId);

        for (const memberId of groupMembers) {
            if (memberId === senderId) continue;

            const memberData = users.get(memberId);
            if (memberData) {
                memberData.wsSet.forEach(memberWs => {
                    if (memberWs.readyState === WebSocket.OPEN) {
                        memberWs.send(JSON.stringify({
                            type: 'new-group-message',
                            groupId,
                            senderId,
                            message: message.message,
                            files: message.files || []
                        }));
                        console.log(`Grup mesajı broadcast: ${senderId} -> ${memberId} (grup: ${groupId})`);
                    }
                });
            }
        }

        // Tüm grup üyelerine unread update gönder (göndereni de dahil et, çünkü kendi mesajı okundu sayılmaz)
        for (const memberId of groupMembers) {
            await sendUnreadUpdate(memberId);
        }
    }

    // === 3. SERVER KANALI MESAJI ===
    else if (serverId && channelId) {
        const channelMembers = await getChannelMembers(serverId, channelId);

        for (const memberId of channelMembers) {
            if (memberId === senderId) continue;

            const memberData = users.get(memberId);
            if (memberData) {
                memberData.wsSet.forEach(memberWs => {
                    if (memberWs.readyState === WebSocket.OPEN) {
                        memberWs.send(JSON.stringify({
                            type: 'new-server-message',
                            serverId,
                            channelId,
                            senderId,
                            message: message.message,
                            files: message.files || [],
                            timestamp: message.timestamp || Date.now()
                        }));
                        console.log(`Server mesajı broadcast: ${senderId} -> ${memberId} (server: ${serverId}, channel: ${channelId})`);
                    }
                });
            }
        }

        // Kanal üyelerine unread update
        for (const memberId of channelMembers) {
            await sendUnreadUpdate(memberId);
        }
    }

    return;
}
      if (message.type === 'typing') {
        console.log('📩 Typing mesajı alındı:', message);

        if (!message.senderId) {
          console.error('🚨 Hata: senderId eksik, typing mesajı işlenemiyor:', message);
          return;
        }

        const senderId = String(message.senderId);

        if (message.isServer && message.serverId && message.channelId) {
          const serverId = String(message.serverId);
          const channelId = String(message.channelId);
          console.log(`🏢 Server ID: ${serverId}, Kanal ID: ${channelId}, Gönderici ID: ${senderId}`);

          const channelMembers = await getChannelMembers(serverId, channelId);
          console.log('👥 Kanal üyeleri:', channelMembers);

          const membersToNotify = channelMembers.filter(memberId => memberId !== senderId);
          console.log('📤 Bildirim gönderilecek üyeler:', membersToNotify);

          membersToNotify.forEach(memberId => {
            const memberData = users.get(String(memberId));
            if (memberData) {
              memberData.wsSet.forEach(memberWs => {
                if (memberWs.readyState === WebSocket.OPEN) {
                  console.log(`📤 ${memberId}'ye typing mesajı gönderiliyor`);
                  memberWs.send(JSON.stringify({
                    type: 'typing',
                    senderId: message.senderId,
                    serverId: message.serverId,
                    channelId: message.channelId,
                    username: message.username,
                    isTyping: message.isTyping
                  }));
                } else {
                  console.log(`⚠️ Üye çevrimdışı veya WebSocket kapalı: ${memberId}`);
                }
              });
            } else {
              console.log(`⚠️ Üye bulunamadı: ${memberId}`);
            }
          });
        } else if (message.isGroup && message.groupId) {
          const groupId = String(message.groupId);
          console.log(`👥 Grup ID: ${groupId}, Gönderici ID: ${senderId}`);

          const groupMembers = await getGroupMembers(groupId);
          console.log('👥 Grup üyeleri:', groupMembers);

          const membersToNotify = groupMembers.filter(memberId => memberId !== senderId);
          console.log('📤 Bildirim gönderilecek üyeler:', membersToNotify);

          membersToNotify.forEach(memberId => {
            const memberData = users.get(String(memberId));
            if (memberData) {
              memberData.wsSet.forEach(memberWs => {
                if (memberWs.readyState === WebSocket.OPEN) {
                  console.log(`📤 ${memberId}'ye typing mesajı gönderiliyor`);
                  memberWs.send(JSON.stringify({
                    type: 'typing',
                    senderId: message.senderId,
                    groupId: message.groupId,
                    username: message.username,
                    isTyping: message.isTyping
                  }));
                } else {
                  console.log(`⚠️ Üye çevrimdışı veya WebSocket kapalı: ${memberId}`);
                }
              });
            } else {
              console.log(`⚠️ Üye bulunamadı: ${memberId}`);
            }
          });
        } else {
          const receiverId = String(message.receiverId);
          if (!receiverId) {
            console.error('🚨 Hata: receiverId eksik, birebir typing mesajı işlenemiyor:', message);
            return;
          }
          if (receiverId !== senderId) {
            const receiverData = users.get(receiverId);
            if (receiverData) {
              receiverData.wsSet.forEach(receiverWs => {
                if (receiverWs.readyState === WebSocket.OPEN) {
                  console.log(`📤 ${receiverId}'ye typing mesajı gönderiliyor`);
                  receiverWs.send(JSON.stringify({
                    type: 'typing',
                    senderId: message.senderId,
                    receiverId: message.receiverId,
                    username: message.username,
                    isTyping: message.isTyping
                  }));
                } else {
                  console.log(`⚠️ Typing event alıcısı WebSocket kapalı: ${receiverId}`);
                }
              });
            } else {
              console.log(`⚠️ Typing event alıcısı çevrimdışı veya bulunamadı: ${receiverId}`);
            }
          }
        }
        return;
      }

      if (message.type === 'update-pending-count') {
        const pendingCount = await getPendingRequestsCount(userId);
        const userData = users.get(userId);
        if (userData) {
          userData.pendingCount = pendingCount;
          userData.wsSet.forEach(ws => {
            if (ws.readyState === WebSocket.OPEN) {
              ws.send(JSON.stringify({ type: 'pending-count', count: pendingCount }));
            }
          });
        }
      }

      if (message.type === 'heartbeat') {
        console.log(`❤️ Heartbeat alındı: ${userId}`);
        ws.send(JSON.stringify({ type: 'heartbeat_ack', message: 'Heartbeat alındı' }));
        return;
      }

      if (message.type === 'friend-request-sent') {
        const receiverData = users.get(message.receiverId);
        if (receiverData) {
          const newCount = receiverData.pendingCount + 1;
          receiverData.pendingCount = newCount;
          receiverData.wsSet.forEach(receiverWs => {
            if (receiverWs.readyState === WebSocket.OPEN) {
              receiverWs.send(JSON.stringify({
                type: 'friend-request-update',
                count: newCount
              }));
            }
          });
        }
      }

      if (message.type === 'message-sent') {
        const receiverId = String(message.receiverId);
        const senderId = String(message.senderId);

        const receiverData = users.get(receiverId);
        if (receiverData) {
          const unreadCounts = await getUnreadCountsForUser(receiverId);
          receiverData.wsSet.forEach(receiverWs => {
            if (receiverWs.readyState === WebSocket.OPEN) {
              receiverWs.send(JSON.stringify({
                type: 'unread-update',
                counts: unreadCounts,
                debug: { sender: senderId, origin: 'message-sent' }
              }));
              console.log(`📩 Unread update gönderildi: ${receiverId} (${JSON.stringify(unreadCounts)})`);
            } else {
              console.log(`🔴 Alıcı WebSocket kapalı: ${receiverId}`);
            }
          });
        } else {
          console.log(`🔴 Alıcı çevrimdışı: ${receiverId}`);
        }

        const senderData = users.get(senderId);
        if (senderData) {
          const senderUnreadCounts = await getUnreadCountsForUser(senderId);
          senderData.wsSet.forEach(senderWs => {
            if (senderWs.readyState === WebSocket.OPEN) {
              senderWs.send(JSON.stringify({
                type: 'unread-update',
                counts: senderUnreadCounts
              }));
            }
          });
        }
        return;
      }

      if (message.type === 'friend-request-updated') {
        [String(message.senderId), String(message.receiverId)].forEach(async targetId => {
          const user = users.get(targetId);
          if (user) {
            const count = await getPendingRequestsCount(targetId);
            user.pendingCount = count;
            user.wsSet.forEach(userWs => {
              if (userWs.readyState === WebSocket.OPEN) {
                userWs.send(JSON.stringify({
                  type: 'friend-request-update',
                  count: count
                }));
              }
            });
          }
        });
        return;
      }

      // Yeni: Reaksiyon ekleme/kaldırma
      if (message.type === 'add-reaction') {
        const { messageId, emoji, receiverId, groupId } = message; // İstemciden receiverId veya groupId gelir
        try {
          const action = await addOrRemoveReaction(messageId, userId, emoji);
          const reactions = await getMessageReactions(messageId);

          // Yayın mesajı hazırla
          const broadcast = {
            type: 'reaction-update',
            messageId,
            reactions, // {emoji: count}
            userId,
            emoji,
            action
          };

          if (receiverId) {
            // DM: Gönderici ve alıcıya yayınla
            [userId, String(receiverId)].forEach(targetId => {
              const targetData = users.get(targetId);
              if (targetData) {
                targetData.wsSet.forEach(targetWs => {
                  if (targetWs.readyState === WebSocket.OPEN) {
                    targetWs.send(JSON.stringify(broadcast));
                  }
                });
              }
            });
          } else if (groupId) {
            // Grup: Tüm üyelere yayınla
            const groupMembers = await getGroupMembers(groupId);
            groupMembers.forEach(memberId => {
              const memberData = users.get(memberId);
              if (memberData) {
                memberData.wsSet.forEach(memberWs => {
                  if (memberWs.readyState === WebSocket.OPEN) {
                    memberWs.send(JSON.stringify(broadcast));
                  }
                });
              }
            });
          }
        } catch (error) {
          ws.send(JSON.stringify({ type: 'error', message: 'Reaksiyon işlenemedi' }));
        }
        return;
      }

      // Yeni: Mesaj silme
      if (message.type === 'delete-message') {
        const { messageId } = message;
        if (!messageId) {
          ws.send(JSON.stringify({ type: 'error', message: 'messageId gerekli' }));
          return;
        }

        const deleteResult = await deleteMessage(messageId, userId);
        
        if (deleteResult.success) {
          const broadcast = {
            type: 'message-deleted',
            messageId: String(messageId)
          };

          if (deleteResult.receiverId) {
            // DM: Gönderici ve alıcıya yayınla
            [userId, deleteResult.receiverId].forEach(targetId => {
              const targetData = users.get(targetId);
              if (targetData) {
                targetData.wsSet.forEach(targetWs => {
                  if (targetWs.readyState === WebSocket.OPEN) {
                    targetWs.send(JSON.stringify(broadcast));
                  }
                });
              }
            });
          } else if (deleteResult.groupId) {
            // Grup: Tüm üyelere yayınla
            const groupMembers = await getGroupMembers(deleteResult.groupId);
            groupMembers.forEach(memberId => {
              const memberData = users.get(memberId);
              if (memberData) {
                memberData.wsSet.forEach(memberWs => {
                  if (memberWs.readyState === WebSocket.OPEN) {
                    memberWs.send(JSON.stringify(broadcast));
                  }
                });
              }
            });
          }

          ws.send(JSON.stringify({ type: 'delete-success', messageId }));
        } else {
          ws.send(JSON.stringify({ type: 'error', message: deleteResult.error || 'Mesaj silinemedi' }));
        }
        return;
      }

      switch (message.type) {
        case 'join-voice-channel':
        case 'voice-join':
          console.log(`🔄 voice-join mesajı join-voice-channel olarak işleniyor: ${message.userId}`);
          handleVoiceChannelJoin(ws, message);
          break;
        case 'join-dm-voice':
          if (!message.friendId) {
            console.error(`🚨 friendId eksik: ${JSON.stringify(message)}`);
            ws.send(JSON.stringify({ type: 'error', message: 'friendId gerekli' }));
            return;
          }
          const friendId = String(message.friendId);
          const dmChannelId = generateDMChannelId(userId, friendId);
          if (!dmChannelId) {
            console.error(`🚨 DM kanal ID'si oluşturulamadı: userId=${userId}, friendId=${friendId}`);
            ws.send(JSON.stringify({ type: 'error', message: 'Geçersiz userId veya friendId' }));
            return;
          }
          console.log(`🎙 Kullanıcı ${userId}, ${friendId} ile DM sesli sohbete katılıyor: ${dmChannelId}`);
          handleVoiceChannelJoin(ws, { channelId: dmChannelId, userId });
          break;
        case 'leave-dm-voice':
          if (!message.friendId) {
            console.error(`🚨 friendId eksik: ${JSON.stringify(message)}`);
            ws.send(JSON.stringify({ type: 'error', message: 'friendId gerekli' }));
            return;
          }
          const leaveFriendId = String(message.friendId);
          const leaveDmChannelId = generateDMChannelId(userId, leaveFriendId);
          if (!leaveDmChannelId) {
            console.error(`🚨 DM kanal ID'si oluşturulamadı: userId=${userId}, friendId=${leaveFriendId}`);
            ws.send(JSON.stringify({ type: 'error', message: 'Geçersiz userId veya friendId' }));
            return;
          }
          console.log(`🎙 Kullanıcı ${userId}, ${leaveFriendId} ile DM sesli sohbetten ayrılıyor: ${leaveDmChannelId}`);
          handleVoiceChannelLeave(ws, { channelId: leaveDmChannelId, userId });
          break;
        case 'voice-offer':
        case 'voice-answer':
        case 'ice-candidate':
          if (!message.target || !message.sender || !message.channelId) {
            console.error(`🚨 Eksik alanlar: target=${message.target}, sender=${message.sender}, channelId=${message.channelId}`);
            ws.send(JSON.stringify({ type: 'error', message: 'target, sender ve channelId gerekli' }));
            return;
          }
          forwardVoiceData({ ...message, channelId: message.channelId });
          break;
        case 'screen-share-start':
          handleScreenShareStart(message);
          break;
        case 'screen-share-end':
          handleScreenShareEnd(message);
          break;
        case 'screen-offer':
          if (!message.target || !message.sender || !message.channelId) {
            console.error(`🚨 Eksik alanlar: target=${message.target}, sender=${message.sender}, channelId=${message.channelId}`);
            ws.send(JSON.stringify({ type: 'error', message: 'target, sender ve channelId gerekli' }));
            return;
          }
          screenSharers.set(message.channelId.toString(), message.sender.toString());
          forwardVoiceData({ ...message, channelId: message.channelId });
          break;
        case 'screen-answer':
        case 'screen-ice-candidate':
          if (!message.target || !message.sender || !message.channelId) {
            console.error(`🚨 Eksik alanlar: target=${message.target}, sender=${message.sender}, channelId=${message.channelId}`);
            ws.send(JSON.stringify({ type: 'error', message: 'target, sender ve channelId gerekli' }));
            return;
          }
          forwardVoiceData({ ...message, channelId: message.channelId });
          break;
        case 'leave-voice-channel':
        case 'voice-leave':
          handleVoiceChannelLeave(ws, message);
          break;
        default:
          console.warn(`❓ Bilinmeyen mesaj tipi alındı: ${message.type}`);
          ws.send(JSON.stringify({ type: 'error', message: `Bilinmeyen mesaj tipi: ${message.type}` }));
          break;
      }
    } catch (error) {
      console.error('🚨 Mesaj işleme hatası:', error);
      if (error instanceof SyntaxError) {
        ws.send(JSON.stringify({ type: 'error', message: 'Geçersiz mesaj formatı' }));
      } else {
        ws.send(JSON.stringify({ type: 'error', message: 'Sunucu hatası' }));
      }
    }
  });

  ws.on('close', () => {
    if (userId) {
      const userData = users.get(userId);
      if (userData) {
        userData.wsSet.delete(ws);
        console.log(`🔌 ${username} (${userId}) için bir WebSocket bağlantısı kesildi. Kalan bağlantılar: ${userData.wsSet.size}`);
        
        if (userData.wsSet.size === 0) {
          voiceChannels.forEach((channel, channelId) => {
            if (channel.users.has(userId)) {
              channel.users.delete(userId);
              console.log(`🚪 Kullanıcı ${userId} kanaldan ayrıldı: ${channelId}`);
              
              if (screenSharers.get(channelId) === userId) {
                handleScreenShareEnd({ channelId, userId });
              }

              channel.users.forEach(participantId => {
                const participant = users.get(participantId);
                if (participant) {
                  participant.wsSet.forEach(participantWs => {
                    if (participantWs.readyState === WebSocket.OPEN) {
                      participantWs.send(JSON.stringify({ type: 'voice-user-left', channelId, userId }));
                    }
                  });
                }
              });

              if (channel.users.size === 0) {
                voiceChannels.delete(channelId);
                screenSharers.delete(channelId);
                console.log(`🗑 Kanal ${channelId} boşaldı ve silindi.`);
              }

              console.log(`ℹ Kanal ${channelId} durumu (ayrıldıktan sonra):`, {
                users: Array.from(channel.users),
                sharer: channel.sharer
              });
            }
          });
          users.delete(userId);
          console.log(`🔌 ${username} (${userId}) tüm bağlantıları kesildi. Kalan kullanıcılar: ${users.size}`);
        }
      }
    }
  });

  ws.on('error', function error(err) {
    console.error('🚨 WebSocket hatası:', err);
  });
});

function handleScreenShareStart(data) {
  const { channelId, userId } = data;
  const channel = voiceChannels.get(channelId.toString());
  console.log(`📊 Channel state for ${channelId}:`, channel ? JSON.stringify([...channel.users], null, 2) : 'Not found');
  console.log(`📊 User state for ${userId}:`, users.has(userId.toString()) ? 'Found' : 'Not found');

  if (channel && channel.users.has(userId.toString())) {
    screenSharers.set(channelId.toString(), userId.toString());
    console.log(`🖥 Screen share started by ${userId} in channel ${channelId}`);
    
    channel.users.forEach(participantId => {
      if (participantId !== userId.toString()) {
        const participant = users.get(participantId);
        if (participant) {
          participant.wsSet.forEach(participantWs => {
            if (participantWs.readyState === WebSocket.OPEN) {
              participantWs.send(JSON.stringify({
                type: 'screen-share-started',
                channelId,
                userId,
                sender: userId,
                username: users.get(userId.toString())?.username || `User-${userId}`
              }));
              console.log(`📤 [screen-share-started] mesajı ${participantId}'e gönderildi, kanal: ${channelId}`);
            } else {
              console.warn(`Participant ${participantId} WebSocket closed`);
            }
          });
        } else {
          console.warn(`Participant ${participantId} not connected`);
        }
      }
    });
  } else {
    console.error(`Channel ${channelId} or user ${userId} not found`);
  }
}

function handleScreenShareEnd(data) {
  const { channelId, userId } = data;
  if (screenSharers.get(channelId.toString()) === userId.toString()) {
    screenSharers.delete(channelId.toString());
    console.log(`🖥 Screen share ended by ${userId} in channel ${channelId}`);
    
    const channel = voiceChannels.get(channelId.toString());
    if (channel) {
      channel.users.forEach(participantId => {
        const participant = users.get(participantId);
        if (participant) {
          participant.wsSet.forEach(participantWs => {
            if (participantWs.readyState === WebSocket.OPEN) {
              participantWs.send(JSON.stringify({
                type: 'screen-share-ended',
                channelId,
                userId
              }));
              console.log(`📤 [screen-share-ended] mesajı ${participantId}'e gönderildi, kanal: ${channelId}`);
            } else {
              console.warn(`Participant ${participantId} WebSocket closed`);
            }
          });
        }
      });
    }
  }
}

function handleVoiceChannelJoin(ws, data) {
  const { channelId, userId } = data;
  const normalizedUserId = String(userId);

  console.log(`🚀 Kullanıcı ${normalizedUserId} kanala katılmaya çalışıyor: ${channelId}`);

  if (!channelId || !userId) {
    console.error(`🚨 Eksik veri: channelId=${channelId}, userId=${userId}`);
    ws.send(JSON.stringify({
      type: 'error',
      message: 'channelId ve userId gerekli'
    }));
    return;
  }

  if (!voiceChannels.has(channelId)) {
    voiceChannels.set(channelId, { users: new Set(), sharer: null });
    console.log(`🛠 Kanal ${channelId} oluşturuldu.`);
  }

  const channel = voiceChannels.get(channelId);
  
  if (!channel.users.has(normalizedUserId)) {
    channel.users.add(normalizedUserId);
    console.log(`✅ Kullanıcı ${normalizedUserId} kanala katıldı: ${channelId}`);

    const participants = Array.from(channel.users).filter(id => id !== normalizedUserId);

    ws.send(JSON.stringify({
      type: 'voice-participants',
      channelId,
      participants: participants.map(id => ({
        id: id,
        username: users.get(id)?.username || `User-${id}`,
        avatar_url: users.get(id)?.avatar_url || '/images/default-avatar.png'
      }))
    }));

    participants.forEach(participantId => {
      const participant = users.get(participantId);
      if (participant) {
        participant.wsSet.forEach(participantWs => {
          if (participantWs.readyState === WebSocket.OPEN) {
            participantWs.send(JSON.stringify({
              type: 'voice-user-joined',
              channelId,
              userId: normalizedUserId,
              username: users.get(normalizedUserId)?.username || `User-${normalizedUserId}`,
              avatar_url: users.get(normalizedUserId)?.avatar_url || '/images/default-avatar.png'
            }));
          } else {
            console.warn(`⚠️ Katılımcı ${participantId} WebSocket kapalı.`);
          }
        });
      } else {
        console.warn(`⚠️ Katılımcı ${participantId} çevrimdışı değil.`);
      }
    });

    if (screenSharers.get(channelId)) {
      const sharerId = screenSharers.get(channelId);
      ws.send(JSON.stringify({
        type: 'screen-share-started',
        channelId,
        userId: sharerId,
        username: users.get(sharerId)?.username || `User-${sharerId}`
      }));
    }

    // DEĞİŞİKLİK: Katılım sonrası tam kanal güncellemesini broadcast et (yeni mesaj tipi: voice-channel-update)
    broadcastVoiceChannelUpdate(channelId);

    console.log(`ℹ Kanal ${channelId} durumu (katıldıktan sonra):`, {
      users: Array.from(channel.users),
      sharer: channel.sharer,
      totalUsers: users.size,
      connectedUsers: Array.from(users.keys())
    });
  } else {
    console.log(`ℹ Kullanıcı ${normalizedUserId} zaten kanal ${channelId}'de.`);
  }
}

function handleVoiceChannelLeave(ws, data) {
  const { channelId, userId } = data;
  const normalizedUserId = String(userId);

  console.log(`🚪 Kullanıcı ${normalizedUserId} kanaldan ayrılmaya çalışıyor: ${channelId}`);

  if (!channelId || !userId) {
    console.error(`🚨 Eksik veri: channelId=${channelId}, userId=${userId}`);
    ws.send(JSON.stringify({
      type: 'error',
      message: 'channelId ve userId gerekli'
    }));
    return;
  }

  const channel = voiceChannels.get(channelId);
  if (channel && channel.users.has(normalizedUserId)) {
    channel.users.delete(normalizedUserId);
    console.log(`✅ Kullanıcı ${normalizedUserId} kanaldan ayrıldı: ${channelId}`);

    if (screenSharers.get(channelId) === normalizedUserId) {
      handleScreenShareEnd({ channelId, userId: normalizedUserId });
    }

    channel.users.forEach(participantId => {
      const participant = users.get(participantId);
      if (participant) {
        participant.wsSet.forEach(participantWs => {
          if (participantWs.readyState === WebSocket.OPEN) {
            participantWs.send(JSON.stringify({
              type: 'voice-user-left',
              channelId,
              userId: normalizedUserId
            }));
          }
        });
      }
    });

    if (channel.users.size === 0) {
      voiceChannels.delete(channelId);
      screenSharers.delete(channelId);
      console.log(`🗑 Kanal ${channelId} boşaldı ve silindi.`);
    }

    // DEĞİŞİKLİK: Ayrılma sonrası tam kanal güncellemesini broadcast et
    broadcastVoiceChannelUpdate(channelId);

    console.log(`ℹ Kanal ${channelId} durumu (ayrıldıktan sonra):`, {
      users: Array.from(channel.users),
      sharer: channel.sharer
    });
  } else {
    console.log(`⚠️ Kullanıcı ${normalizedUserId} kanal ${channelId}'de bulunmuyor veya kanal mevcut değil.`);
  }
}

// YENİ: Kanal güncellemesini tüm bağlı kullanıcılara broadcast et (UI senkronizasyonu için)
function broadcastVoiceChannelUpdate(channelId) {
  const channel = voiceChannels.get(channelId);
  if (!channel) return;

  const participants = Array.from(channel.users).map(id => ({
    id: id,
    username: users.get(id)?.username || `User-${id}`,
    avatar_url: users.get(id)?.avatar_url || '/images/default-avatar.png'
  }));

  const updateMessage = {
    type: 'voice-channel-update',
    channelId,
    participants,
    participantCount: channel.users.size,
    sharer: screenSharers.get(channelId) || null
  };

  // DEĞİŞİKLİK: Tüm bağlı kullanıcılara gönder (kanala bağlı olmayanlara bile, eğer global UI güncellemesi istiyorsan; yoksa sadece channel.users'a sınırlı tut)
  users.forEach((userData) => {
    userData.wsSet.forEach(ws => {
      if (ws.readyState === WebSocket.OPEN) {
        ws.send(JSON.stringify(updateMessage));
      }
    });
  });

  console.log(`📤 [voice-channel-update] broadcast edildi: kanal ${channelId}, katılımcılar: ${participants.length}`);
}

function forwardVoiceData(data) {
  const { type, target, channelId, sender } = data;
  
  if (!channelId || !target || !sender) {
    console.error(`🚨 Eksik veri: type=${type}, channelId=${channelId}, target=${target}, sender=${sender}`);
    return;
  }

  const channel = voiceChannels.get(channelId.toString());
  console.log(`📊 Forwarding ${type} to ${target}, channel ${channelId}:`, channel ? JSON.stringify([...channel.users], null, 2) : 'Not found');
  
  if (channel && channel.users.has(target.toString())) {
    const targetUser = users.get(target.toString());
    if (targetUser) {
      targetUser.wsSet.forEach(targetWs => {
        if (targetWs.readyState === WebSocket.OPEN) {
          const message = { ...data, sender, channelId };
          targetWs.send(JSON.stringify(message));
          console.log(`📤 [${type}] mesajı ${target}'e yönlendirildi, kanal: ${channelId}, sender: ${sender}`);
        } else {
          console.warn(`Target ${target} WebSocket closed`);
        }
      });
    } else {
      console.warn(`Target ${target} not found`);
    }
  } else {
    console.warn(`Channel ${channelId} or target ${target} not found`);
  }
}

server.on('error', (err) => {
  console.error('🚨 Sunucu hatası:', err);
});

server.listen(8000, function() {
  console.log('wss sunucusu 8000 portunda çalışıyor.');
});