// SPA Navigation and Form Handling
document.addEventListener('DOMContentLoaded', function () {
    // Handle sidebar navigation
    document.querySelectorAll('.sidebar-item').forEach(item => {
        item.addEventListener('click', function (e) {
            e.preventDefault();
            const href = item.getAttribute('href') || item.dataset.href || '#';
            if (href !== '#') {
                loadPageContent(href);
            }
            // Update active state
            document.querySelectorAll('.sidebar-item').forEach(el => el.classList.remove('active'));
            item.classList.add('active');
        });
    });

    // Load page content via AJAX
    function loadPageContent(url) {
        fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' } // Indicate AJAX request
        })
        .then(response => response.text())
        .then(data => {
            const parser = new DOMParser();
            const doc = parser.parseFromString(data, 'text/html');
            const newContent = doc.querySelector('.content-container').innerHTML;
            const contentContainer = document.querySelector('.content-container');
            contentContainer.innerHTML = newContent;
            lucide.createIcons(); // Reinitialize Lucide icons
            reinitializeScripts(url); // Reinitialize page-specific scripts
            history.pushState({}, '', url); // Update browser URL
        })
        .catch(error => console.error('Error loading content:', error));
    }

    // Handle browser back/forward navigation
    window.addEventListener('popstate', function () {
        loadPageContent(window.location.pathname);
    });

    // Reinitialize page-specific scripts
    function reinitializeScripts(url) {
        if (url.includes('bildirimses')) {
            // Reattach event listeners for notification sound page
            document.querySelectorAll('.preview-btn').forEach(btn => {
                btn.addEventListener('click', function () {
                    const soundPath = btn.getAttribute('onclick').match(/'([^']+)'/)[1];
                    previewSound(soundPath);
                });
            });

            // Handle form submission for notification sound
            const soundForm = document.querySelector('form');
            if (soundForm) {
                soundForm.addEventListener('submit', function (e) {
                    e.preventDefault();
                    submitForm(soundForm, '/bildirimses', 'Ayarlar başarıyla kaydedildi!');
                });
            }
        } else if (url.includes('settings')) {
            // Reattach event listeners for settings page
            document.querySelectorAll('.setting-row:not(.disabled)').forEach(row => {
                if (row.onclick) {
                    row.addEventListener('click', row.onclick);
                }
            });

            // Reattach form submissions for settings modals
            submitForm('usernameForm', 'update_username.php', 'Kullanıcı adı başarıyla güncellendi!', 'usernameModal');
            submitForm('emailForm', 'update_email.php', 'E-posta güncellendi. Doğrulama e-postası gönderildi!', 'emailModal');
            submitForm('passwordForm', 'update_password.php', 'Şifre başarıyla güncellendi!', 'passwordModal');
            submitForm('deleteAccountForm', 'delete_account.php', 'Hesabınız silindi. Ana sayfaya yönlendiriliyorsunuz...', 'deleteAccountModal');
            submitForm('activate2FAForm', 'activate_2fa.php', 'İki aşamalı doğrulama etkinleştirildi!');
            submitForm('deactivate2FAForm', 'deactivate_2fa.php', 'İki aşamalı doğrulama devre dışı bırakıldı!');

            // Reattach avatar upload
            const avatarInput = document.querySelector('.avatar input[type="file"]');
            if (avatarInput) {
                avatarInput.addEventListener('change', uploadAvatar);
            }

            // Reattach 2FA toggle
            const twoFaToggle = document.getElementById('2faToggle');
            if (twoFaToggle) {
                twoFaToggle.addEventListener('change', function () {
                    if (this.checked) openActivate2FAModal();
                    else openDeactivate2FAModal();
                });
            }

            // Reattach show email
            const showEmailBtn = document.querySelector('.setting-action[onclick="showEmail()"]');
            if (showEmailBtn) {
                showEmailBtn.addEventListener('click', showEmail);
            }
        }
    }

    // Form submission via AJAX
    function submitForm(formIdOrElement, url, successMessage, modalId = null) {
        const form = typeof formIdOrElement === 'string' ? document.getElementById(formIdOrElement) : formIdOrElement;
        if (!form) return;
        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            const formData = new FormData(form);
            try {
                const response = await fetch(url, { method: 'POST', body: formData });
                const result = await response.json();
                if (result.success) {
                    alert(successMessage);
                    if (modalId) document.getElementById(modalId).style.display = 'none';
                    if (url.includes('delete_account.php')) {
                        window.location.href = '/';
                    } else {
                        loadPageContent(window.location.pathname); // Reload current page content
                    }
                } else {
                    alert(result.error);
                }
            } catch (error) {
                alert('Bir hata oluştu. Lütfen tekrar deneyin.');
            }
        });
    }

    // Notification sound preview
    function previewSound(soundPath) {
        const audio = new Audio(soundPath);
        audio.play();
    }

    // Settings page modal functions
    function openPasswordModal() { document.getElementById('passwordModal').style.display = 'block'; }
    function closePasswordModal() { document.getElementById('passwordModal').style.display = 'none'; }
    function openUsernameModal() { document.getElementById('usernameModal').style.display = 'block'; }
    function closeUsernameModal() { document.getElementById('usernameModal').style.display = 'none'; }
    function openEmailModal() { document.getElementById('emailModal').style.display = 'block'; }
    function closeEmailModal() { document.getElementById('emailModal').style.display = 'none'; }
    function openDeleteAccountModal() { document.getElementById('deleteAccountModal').style.display = 'block'; }
    function closeDeleteAccountModal() { document.getElementById('deleteAccountModal').style.display = 'none'; }
    function openActivate2FAModal() { document.getElementById('activate2FAModal').style.display = 'block'; send2FACode(); }
    function closeActivate2FAModal() { document.getElementById('activate2FAModal').style.display = 'none'; }
    function openDeactivate2FAModal() { document.getElementById('deactivate2FAModal').style.display = 'block'; }
    function closeDeactivate2FAModal() { document.getElementById('deactivate2FAModal').style.display = 'none'; }

    // Modal close on click outside
    window.onclick = function (event) {
        const modals = ['usernameModal', 'passwordModal', 'emailModal', 'deleteAccountModal', 'activate2FAModal', 'deactivate2FAModal'];
        modals.forEach(id => {
            const modal = document.getElementById(id);
            if (modal && event.target === modal) {
                modal.style.display = 'none';
            }
        });
    };

    // Close settings with ESC
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            window.location.href = '/directmessages';
        }
    });

    // Avatar upload
    async function uploadAvatar() {
        const form = document.getElementById('avatarForm');
        const formData = new FormData(form);
        try {
            const response = await fetch('update_avatar.php', { method: 'POST', body: formData });
            const result = await response.json();
            if (result.success) {
                alert('Profil resmi güncellendi.');
                loadPageContent('/settings');
            } else {
                alert(result.error);
            }
        } catch (error) {
            alert('Bir hata oluştu.');
        }
    }

    // Show email
    async function showEmail() {
        const password = prompt('E-postanızı görmek için şifrenizi girin:');
        if (!password) return;
        try {
            const response = await fetch('show_email.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `password=${encodeURIComponent(password)}`
            });
            const result = await response.json();
            if (result.email) {
                document.getElementById('emailDisplay').textContent = result.email;
            } else {
                alert(result.error);
            }
        } catch (error) {
            alert('Bir hata oluştu. Lütfen tekrar deneyin.');
        }
    }

    // Send 2FA code
    async function send2FACode() {
        try {
            const response = await fetch('send_2fa_code.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `csrf_token=<?php echo htmlspecialchars($csrf_token); ?>`
            });
            const result = await response.json();
            if (!result.success) {
                alert('Kod gönderilemedi: ' + result.error);
            }
        } catch (error) {
            alert('Bir hata oluştu: ' + error.message);
        }
    }
});