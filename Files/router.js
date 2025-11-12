const routes = {
    '/settings': '/settings.html',
    '/bildirimses': '/notifications.html'
};

function navigateTo(url) {
    history.pushState(null, null, url);
    loadContent(url);
}

async function loadContent(url) {
    const contentContainer = document.getElementById('main-content');
    const route = routes[url] || routes['/settings'];
    
    // Fade out
    contentContainer.style.opacity = '0';
    
    try {
        const response = await fetch(route);
        const html = await response.text();
        
        // Fetch user data for settings
        let userData = {};
        let currentSound = '';
        if (url === '/settings') {
            const userResponse = await fetch('/settings.php');
            userData = await userResponse.json();
        } else if (url === '/bildirimses') {
            const soundResponse = await fetch('/bildirimses.php');
            const soundData = await soundResponse.json();
            currentSound = soundData.currentSound || '';
        }

        // Replace placeholders
        let content = html
            .replace('{{csrf_token}}', document.querySelector('input[name="csrf_token"]').value)
            .replace('{{username}}', userData.username || 'Kullanıcı')
            .replace('{{email}}', userData.email || 'E-posta gizli')
            .replace('{{user_id}}', userData.user_id || '')
            .replace('{{avatar_url}}', userData.avatar_url || '')
            .replace('{{two_factor_enabled}}', userData.two_factor_enabled ? 'true' : 'false')
            .replace(/{{currentSound === '([^']+)' \? 'checked' : ''}}/g, (match, sound) => currentSound === sound ? 'checked' : '');

        // Update content with transition
        setTimeout(() => {
            contentContainer.innerHTML = content;
            contentContainer.style.opacity = '1';
            lucide.createIcons();
            
            // Initialize page-specific scripts
            if (url === '/settings') {
                initializeSettings();
            } else if (url === '/bildirimses') {
                initializeBildirimses();
            }
        }, 300);

        // Update active sidebar item
        document.querySelectorAll('.sidebar-item').forEach(item => {
            item.classList.remove('active');
            if (item.getAttribute('data-route') === url) {
                item.classList.add('active');
            }
        });
    } catch (error) {
        console.error('Error loading content:', error);
        contentContainer.innerHTML = '<div class="tip" style="color: var(--error);">İçerik yüklenirken bir hata oluştu.</div>';
        contentContainer.style.opacity = '1';
    }
}

// Handle initial load and popstate
window.addEventListener('popstate', () => loadContent(window.location.pathname));
document.addEventListener('DOMContentLoaded', () => {
    loadContent(window.location.pathname || '/settings');
    
    // Handle sidebar navigation
    document.querySelectorAll('.sidebar-item[data-route]').forEach(item => {
        item.addEventListener('click', (e) => {
            e.preventDefault();
            const route = item.getAttribute('data-route');
            navigateTo(route);
        });
    });
});