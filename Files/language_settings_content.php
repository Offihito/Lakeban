<?php
// Bu dosya, settings.php içinden çağrılacağı için session_start() ve db_connection.php
// zaten ana dosyada mevcut. Tekrar çağırmaya gerek yok.

// Varsayılan dil
$default_app_language = 'tr';

// Tarayıcı dilini algılama fonksiyonu
function getBrowserLanguage() {
    if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
        $langs = explode(',', $_SERVER['HTTP_ACCEPT_LANGUAGE']);
        foreach ($langs as $lang) {
            $lang_code = substr($lang, 0, 2);
            $supported_languages = ['tr', 'en', 'fi', 'fr', 'de', 'ru'];
            if (in_array($lang_code, $supported_languages)) {
                return $lang_code;
            }
        }
    }
    return null;
}

// POST isteği ile dil seçimi kaydediliyorsa (AJAX çağrısı)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['selected_language'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'message' => 'CSRF token doğrulanamadı.']);
        exit;
    }

    $selected_language = htmlspecialchars($_POST['selected_language']);

    if ($selected_language === 'auto') {
        $selected_language = getBrowserLanguage() ?? $default_app_language;
    }

    $_SESSION['user_language'] = $selected_language;

    // Veritabanına da kaydedebilirsin, örneğin:
    // $stmt = $db->prepare("UPDATE users SET language = ? WHERE id = ?");
    // $stmt->execute([$selected_language, $_SESSION['user_id']]);

    echo json_encode(['success' => true, 'message' => 'Dil ayarı başarıyla kaydedildi!', 'new_lang' => $selected_language]);
    exit();
}

// Kullanıcının mevcut dil tercihini al
$current_language = $_SESSION['user_language'] ?? $default_app_language;

// Çeviriler
$translations = [
    'tr' => [
        'title' => 'Dil Ayarları', 'subtitle' => 'Tercih ettiğiniz dili seçin.', 'search_placeholder' => 'Dil ara...', 'tip_message' => 'Dil tercihiniz kaydedildi. Uygulama dili bir sonraki oturumunuzda güncellenecektir.', 'auto_detect' => 'Otomatik Algıla (Tarayıcı Dili)', 'reset_to_default' => 'Varsayılana Sıfırla (Türkçe)', 'turkish' => 'Türkçe', 'english' => 'English', 'finnish' => 'Suomi', 'french' => 'Français', 'german' => 'Deutsch', 'russian' => 'Русский',
    ],
    'en' => [
        'title' => 'Language Settings', 'subtitle' => 'Choose your preferred language.', 'search_placeholder' => 'Search language...', 'tip_message' => 'Your language preference has been saved. The application language will be updated on your next session.', 'auto_detect' => 'Auto-Detect (Browser Language)', 'reset_to_default' => 'Reset to Default (Turkish)', 'turkish' => 'Turkish', 'english' => 'English', 'finnish' => 'Finnish', 'french' => 'French', 'german' => 'German', 'russian' => 'Russian',
    ],
    'fi' => [
        'title' => 'Kieliasetukset', 'subtitle' => 'Valitse haluamasi kieli.', 'search_placeholder' => 'Hae kieltä...', 'tip_message' => 'Kieliasetuksesi on tallennettu. Sovelluksen kieli päivitetään seuraavalla istunnollasi.', 'auto_detect' => 'Automaattinen tunnistus (selaimen kieli)', 'reset_to_default' => 'Palauta oletusasetukset (turkki)', 'turkish' => 'Turkki', 'english' => 'Englanti', 'finnish' => 'Suomi', 'french' => 'Ranska', 'german' => 'Saksa', 'russian' => 'Venäjä',
    ],
    'fr' => [
        'title' => 'Paramètres de langue', 'subtitle' => 'Choisissez votre langue préférée.', 'search_placeholder' => 'Rechercher une langue...', 'tip_message' => 'Votre préférence linguistique a été enregistrée. La langue de l\'application sera mise à jour lors de votre prochaine session.', 'auto_detect' => 'Détection automatique (langue du navigateur)', 'reset_to_default' => 'Réinitialiser par défaut (Turc)', 'turkish' => 'Turc', 'english' => 'Anglais', 'finnish' => 'Finnois', 'french' => 'Français', 'german' => 'Allemand', 'russian' => 'Russe',
    ],
    'de' => [
        'title' => 'Spracheinstellungen', 'subtitle' => 'Wählen Sie Ihre bevorzugte Sprache.', 'search_placeholder' => 'Sprache suchen...', 'tip_message' => 'Ihre Spracheinstellung wurde gespeichert. Die Anwendungssprache wird bei Ihrer nächsten Sitzung aktualisiert.', 'auto_detect' => 'Automatische Erkennung (Browsersprache)', 'reset_to_default' => 'Auf Standard zurücksetzen (Türkisch)', 'turkish' => 'Türkisch', 'english' => 'Englisch', 'finnish' => 'Finnisch', 'french' => 'Französisch', 'german' => 'Deutsch', 'russian' => 'Russisch',
    ],
    'ru' => [
        'title' => 'Настройки языка', 'subtitle' => 'Выберите предпочитаемый язык.', 'search_placeholder' => 'Поиск языка...', 'tip_message' => 'Ваши языковые предпочтения сохранены. Язык приложения будет обновлен в вашей следующей сессии.', 'auto_detect' => 'Автоматическое определение (язык браузера)', 'reset_to_default' => 'Сбросить до значений по умолчанию (Турецкий)', 'turkish' => 'Турецкий', 'english' => 'Английский', 'finnish' => 'Финский', 'french' => 'Французский', 'german' => 'Немецкий', 'russian' => 'Русский',
    ],
];

// Mevcut dile göre çevirileri al
$lang_texts = $translations[$current_language] ?? $translations['tr'];
?>

<h1 id="pageTitle"><?php echo $lang_texts['title']; ?></h1>
<h5 id="pageSubtitle"><?php echo $lang_texts['subtitle']; ?></h5>

<input type="text" id="languageSearch" class="language-search-bar" placeholder="<?php echo $lang_texts['search_placeholder']; ?>" style="width: 100%; padding: 10px; margin-bottom: 15px; border-radius: 8px; box-sizing: border-box; background-color: #2a2a2a; border: 1px solid #333; color: #fff;">

<div class="language-selector">
    <div class="language-list" style="max-height: calc(100vh - 300px); overflow-y: auto;">
        <div class="language-option" data-lang="auto">
            <i data-lucide="globe" style="width: 24px; height: 24px; margin-right: 10px;"></i>
            <span class="language-name"><?php echo $lang_texts['auto_detect']; ?></span>
            <i data-lucide="check" class="check-icon"></i>
        </div>
        <div class="language-option" data-lang="<?php echo $default_app_language; ?>">
            <img src="https://flagcdn.com/w40/tr.jpg" alt="Turkish Flag" class="flag-icon">
            <span class="language-name"><?php echo $lang_texts['reset_to_default']; ?></span>
            <i data-lucide="check" class="check-icon"></i>
        </div>

        <hr style="margin-top: 10px; margin-bottom: 10px; border-color: rgba(255,255,255,0.1);">

        <div class="language-option" data-lang="tr">
            <img src="https://flagcdn.com/w40/tr.jpg" alt="Turkish Flag" class="flag-icon">
            <span class="language-name"><?php echo $lang_texts['turkish']; ?></span>
            <i data-lucide="check" class="check-icon"></i>
        </div>
        <div class="language-option" data-lang="en">
            <img src="https://flagcdn.com/w40/us.jpg" alt="English Flag" class="flag-icon">
            <span class="language-name"><?php echo $lang_texts['english']; ?></span>
            <i data-lucide="check" class="check-icon"></i>
        </div>
        <div class="language-option" data-lang="fi">
            <img src="https://flagcdn.com/w40/fi.jpg" alt="Finnish Flag" class="flag-icon">
            <span class="language-name"><?php echo $lang_texts['finnish']; ?></span>
            <i data-lucide="check" class="check-icon"></i>
        </div>
        <div class="language-option" data-lang="fr">
            <img src="https://flagcdn.com/w40/fr.jpg" alt="French Flag" class="flag-icon">
            <span class="language-name"><?php echo $lang_texts['french']; ?></span>
            <i data-lucide="check" class="check-icon"></i>
        </div>
        <div class="language-option" data-lang="de">
            <img src="https://flagcdn.com/w40/de.jpg" alt="German Flag" class="flag-icon">
            <span class="language-name"><?php echo $lang_texts['german']; ?></span>
            <i data-lucide="check" class="check-icon"></i>
        </div>
        <div class="language-option" data-lang="ru">
            <img src="https://flagcdn.com/w40/ru.jpg" alt="Russian Flag" class="flag-icon">
            <span class="language-name"><?php echo $lang_texts['russian']; ?></span>
            <i data-lucide="check" class="check-icon"></i>
        </div>
    </div>
</div>

<hr style="margin-top: 20px;">
<div class="tip">
    <i data-lucide="info"></i>
    <span id="tipMessage"><?php echo $lang_texts['tip_message']; ?></span>
</div>

<div id="toastNotification" style="position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%); background-color: rgba(0, 0, 0, 0.8); color: #fff; padding: 10px 20px; border-radius: 8px; z-index: 1000; opacity: 0; transition: opacity 0.5s ease-in-out;"></div>

<script>
    // JavaScript kodunu buraya ekliyoruz
    (function() {
        // Canlı önizleme için çeviriler
        const jsTranslations = {
            'tr': {
                'title': 'Dil Ayarları',
                'subtitle': 'Tercih ettiğiniz dili seçin.',
                'search_placeholder': 'Dil ara...',
                'tip_message': 'Dil tercihiniz kaydedildi. Uygulama dili bir sonraki oturumunuzda güncellenecektir.',
                'auto_detect': 'Otomatik Algıla (Tarayıcı Dili)',
                'reset_to_default': 'Varsayılana Sıfırla (Türkçe)',
                'turkish': 'Türkçe',
                'english': 'English',
                'finnish': 'Suomi',
                'french': 'Français',
                'german': 'Deutsch',
                'russian': 'Русский',
            },
            'en': {
                'title': 'Language Settings',
                'subtitle': 'Choose your preferred language.',
                'search_placeholder': 'Search language...',
                'tip_message': 'Your language preference has been saved. The application language will be updated on your next session.',
                'auto_detect': 'Auto-Detect (Browser Language)',
                'reset_to_default': 'Reset to Default (Turkish)',
                'turkish': 'Turkish',
                'english': 'English',
                'finnish': 'Finnish',
                'french': 'French',
                'german': 'German',
                'russian': 'Russian',
            },
            'fi': {
                'title': 'Kieliasetukset',
                'subtitle': 'Valitse haluamasi kieli.',
                'search_placeholder': 'Hae kieltä...',
                'tip_message': 'Kieliasetuksesi on tallennettu. Sovelluksen kieli päivitetään seuraavalla istunnollasi.',
                'auto_detect': 'Automaattinen tunnistus (selaimen kieli)',
                'reset_to_default': 'Palauta oletusasetukset (turkki)',
                'turkish': 'Turkki',
                'english': 'Englanti',
                'finnish': 'Suomi',
                'french': 'Ranska',
                'german': 'Saksa',
                'russian': 'Venäjä',
            },
            'fr': {
                'title': 'Paramètres de langue',
                'subtitle': 'Choisissez votre langue préférée.',
                'search_placeholder': 'Rechercher une langue...',
                'tip_message': 'Votre préférence linguistique a été enregistrée. La langue de l\'application sera mise à jour lors de votre prochaine session.',
                'auto_detect': 'Détection automatique (langue du navigateur)',
                'reset_to_default': 'Réinitialiser par défaut (Turc)',
                'turkish': 'Turc',
                'english': 'Anglais',
                'finnish': 'Finnois',
                'french': 'Français',
                'german': 'Allemand',
                'russian': 'Russe',
            },
            'de': {
                'title': 'Spracheinstellungen',
                'subtitle': 'Wählen Sie Ihre bevorzugte Sprache.',
                'search_placeholder': 'Sprache suchen...',
                'tip_message': 'Ihre Spracheinstellung wurde gespeichert. Die Anwendungssprache wird bei Ihrer nächsten Sitzung aktualisiert.',
                'auto_detect': 'Automatische Erkennung (Browsersprache)',
                'reset_to_default': 'Auf Standard zurücksetzen (Türkisch)',
                'turkish': 'Türkisch',
                'english': 'Englisch',
                'finnish': 'Finnish',
                'french': 'Französisch',
                'german': 'Deutsch',
                'russian': 'Russisch',
            },
            'ru': {
                'title': 'Настройки языка',
                'subtitle': 'Выберите предпочитаемый язык.',
                'search_placeholder': 'Поиск языка...',
                'tip_message': 'Ваши языковые предпочтения сохранены. Язык приложения будет обновлен в вашей следующей сессии.',
                'auto_detect': 'Автоматическое определение (язык браузера)',
                'reset_to_default': 'Сбросить до значений по умолчанию (Турецкий)',
                'turkish': 'Турецкий',
                'english': 'Английский',
                'finnish': 'Финский',
                'french': 'Французский',
                'german': 'Немецкий',
                'russian': 'Русский',
            }
        };

        function showToast(message) {
            const toast = document.getElementById('toastNotification');
            toast.textContent = message;
            toast.style.opacity = '1';
            setTimeout(() => {
                toast.style.opacity = '0';
            }, 3000);
        }

        // Canlı önizleme için metinleri güncelleme fonksiyonu
        function updateLiveText(lang) {
            const texts = jsTranslations[lang] || jsTranslations['tr'];
            document.getElementById('pageTitle').textContent = texts.title;
            document.getElementById('pageSubtitle').textContent = texts.subtitle;
            document.getElementById('languageSearch').placeholder = texts.search_placeholder;
            document.getElementById('tipMessage').textContent = texts.tip_message;

            // Dil seçeneklerindeki isimleri güncelle
            document.querySelector('.language-option[data-lang="auto"] .language-name').textContent = texts.auto_detect;
            // Varsayılana sıfırla metnini de güncelle
            document.querySelector('.language-option[data-lang="<?php echo $default_app_language; ?>"] .language-name').textContent = texts.reset_to_default;
            
            // Diğer dillerin isimlerini güncelle
            document.querySelector('.language-option[data-lang="tr"] .language-name').textContent = texts.turkish;
            document.querySelector('.language-option[data-lang="en"] .language-name').textContent = texts.english;
            document.querySelector('.language-option[data-lang="fi"] .language-name').textContent = texts.finnish;
            document.querySelector('.language-option[data-lang="fr"] .language-name').textContent = texts.french;
            document.querySelector('.language-option[data-lang="de"] .language-name').textContent = texts.german;
            document.querySelector('.language-option[data-lang="ru"] .language-name').textContent = texts.russian;
        }

        // Sayfa yüklendiğinde çalışacak kod
        document.addEventListener('DOMContentLoaded', function() {
            const languageOptions = document.querySelectorAll('.language-option');
            const currentLanguage = "<?php echo $current_language; ?>";
            const languageSearchBar = document.getElementById('languageSearch');

            // Mevcut dili seçili olarak işaretle
            languageOptions.forEach(option => {
                if (option.dataset.lang === currentLanguage) {
                    option.classList.add('selected');
                    option.querySelector('.check-icon').style.display = 'inline';
                }

                option.addEventListener('click', async function() {
                    // Önceki seçimi kaldır
                    languageOptions.forEach(opt => {
                        opt.classList.remove('selected');
                        opt.querySelector('.check-icon').style.display = 'none';
                    });
                    
                    // Yeni seçimi ekle
                    this.classList.add('selected');
                    this.querySelector('.check-icon').style.display = 'inline';

                    const selectedLangCode = this.dataset.lang;

                    const formData = new FormData();
                    formData.append('selected_language', selectedLangCode);
                    formData.append('csrf_token', "<?php echo $_SESSION['csrf_token']; ?>");

                    try {
                        const response = await fetch('language_settings_content.php', {
                            method: 'POST',
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            body: formData
                        });
                        const result = await response.json();

                        if (result.success) {
                            showToast(result.message);
                            // Canlı önizleme için metinleri güncelle
                            updateLiveText(result.new_lang);
                        } else {
                            showToast('Dil ayarı kaydedilirken bir hata oluştu: ' + result.message);
                        }
                    } catch (error) {
                        showToast('Bir hata oluştu. Lütfen tekrar deneyin.');
                        console.error('Dil ayarını kaydederken hata:', error);
                    }
                });
            });

            // Dil arama işlevi
            languageSearchBar.addEventListener('keyup', function() {
                const searchTerm = languageSearchBar.value.toLowerCase();
                languageOptions.forEach(option => {
                    const languageName = option.querySelector('.language-name').textContent.toLowerCase();
                    // "Otomatik Algıla" ve "Varsayılana Sıfırla" seçeneklerini her zaman göster
                    if (option.dataset.lang === 'auto' || option.dataset.lang === '<?php echo $default_app_language; ?>') {
                        option.style.display = 'flex';
                    } else if (languageName.includes(searchTerm)) {
                        option.style.display = 'flex';
                    } else {
                        option.style.display = 'none';
                    }
                });
            });

            // Lucide ikonlarını yeniden oluştur
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
        });
    })();
</script>

<style>
    /* Bu stiller sadece bu content yüklendiğinde aktif olacak */
    .language-option { display: flex; align-items: center; padding: 10px 15px; cursor: pointer; border-radius: 8px; transition: background-color 0.2s ease; margin-bottom: 5px; }
    .language-option:hover { background-color: #35383e; }
    .language-option.selected { background-color: #40444b; border: 1px solid var(--accent-color, #3CB371); }
    .language-option .flag-icon { width: 24px; height: 24px; margin-right: 10px; border-radius: 3px; object-fit: cover; }
    .language-option .language-name { flex-grow: 1; font-size: 16px; }
    .language-option .check-icon { color: var(--accent-color, #3CB371); display: none; }
    .language-option.selected .check-icon { display: block; }
</style>