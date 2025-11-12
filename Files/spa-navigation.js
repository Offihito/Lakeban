document.addEventListener('DOMContentLoaded', () => {
    const appContainer = document.getElementById('app-container');

    // Sayfadan ayrılmadan önce mevcut durumu kaydet
    history.replaceState({ path: window.location.href }, '', window.location.href);

    /**
     * Verilen URL'nin içeriğini yükler ve sayfayı günceller.
     * @param {string} url - Yüklenecek sayfanın URL'si.
     * @param {boolean} isPopState - Tarayıcının geri/ileri butonlarından mı gelindi?
     */
    const loadPage = async (url, isPopState = false) => {
        // Yükleme animasyonu göster (isteğe bağlı)
        appContainer.style.opacity = '0.5';

        try {
            const response = await fetch(url);
            if (!response.ok) {
                throw new Error(`Sunucu hatası: ${response.status}`);
            }
            const html = await response.text();
            
            // Gelen HTML'i ayrıştır
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');

            // Yeni başlığı ve içeriği al
            const newTitle = doc.querySelector('title')?.textContent || document.title;
            const newContent = doc.getElementById('app-container')?.innerHTML;

            if (!newContent) {
                console.error("Gelen içerikte '#app-container' bulunamadı.");
                // Hata durumunda tam sayfa yenilemesi yap
                window.location.href = url;
                return;
            }

            // Sayfayı güncelle
            document.title = newTitle;
            appContainer.innerHTML = newContent;

            // Gelen HTML içindeki script'leri çalıştır
            await executeScripts(doc);
            
            // Eğer bu bir geri/ileri işlemi değilse, tarayıcı geçmişine ekle
            if (!isPopState) {
                history.pushState({ path: url }, newTitle, url);
            }

        } catch (error) {
            console.error('Sayfa yüklenirken hata oluştu:', error);
            // Hata durumunda kullanıcıyı doğrudan sayfaya yönlendir
            window.location.href = url;
        } finally {
            // Yükleme animasyonunu kaldır
            appContainer.style.opacity = '1';
        }
    };

    /**
     * Gelen bir dokümandaki <script> etiketlerini bulur ve yeniden oluşturarak çalıştırır.
     * Bu, innerHTML ile eklenen script'lerin çalışmasını sağlar.
     * @param {Document} doc - Ayrıştırılmış HTML dokümanı.
     */
    const executeScripts = async (doc) => {
        const scripts = doc.querySelectorAll('script');
        for (const oldScript of scripts) {
            const newScript = document.createElement('script');
            
            // src, type gibi özellikleri kopyala
            Array.from(oldScript.attributes).forEach(attr => {
                newScript.setAttribute(attr.name, attr.value);
            });
            
            // Inline script ise içeriğini kopyala
            if (oldScript.textContent) {
                newScript.textContent = oldScript.textContent;
            }

            // Script'i dokümana ekleyerek çalıştır
            document.body.appendChild(newScript);

            // Eğer script'in bir 'src' özelliği varsa, yüklenmesini bekle
            if (newScript.src) {
                await new Promise((resolve, reject) => {
                    newScript.onload = resolve;
                    newScript.onerror = reject;
                });
            }
             // Çalıştırdıktan sonra temizle
            document.body.removeChild(newScript);
        }
    };

    // Tüm SPA linklerine tıklama olayı ekle
    document.body.addEventListener('click', (e) => {
        const link = e.target.closest('a.spa-link');
        if (link) {
            e.preventDefault(); // Normal link davranışını engelle
            const url = link.href;

            // Eğer zaten o sayfadaysak bir şey yapma
            if (url === window.location.href) {
                return;
            }

            loadPage(url);
        }
    });

    // Tarayıcının geri/ileri butonlarını dinle
    window.addEventListener('popstate', (e) => {
        if (e.state && e.state.path) {
            loadPage(e.state.path, true);
        }
    });
});