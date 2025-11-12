<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lakeban Destek Merkezi</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
    <style>
        :root {
            /* Lakeban Renk Paleti */
            --dark-bg: #0f1114;
            --darker-bg: #0a0c0f;
            --header-bg: #0a0c0f;
            --primary: #4CAF50; /* Changed from #3a6ea5 */
            --primary-dark: #388E3C; /* Darker shade of new primary */
            --primary-light: #81C784; /* Lighter shade of new primary */
            --secondary: #ff6b6b;
            --card-bg: #1e2025;
            --card-border: #2c2e33;
            --text-light: #e0e0e0;
            --text-dark: #121416;
            --success: #4CAF50;
            --warning: #FFC107;
            --danger: #F44336;
            --icon-color: #81C784; /* Changed to match new primary-light */
            --footer-bg: #0a0c0f;
            --footer-wave: #1a1c1e;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: var(--dark-bg);
            color: var(--text-light);
            line-height: 1.6;
        }

        .header {
            background-color: var(--header-bg);
            padding: 15px 5%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .header img {
            height: 40px;
        }

        .header nav {
            display: flex;
            gap: 25px;
        }

        .header nav a {
            color: var(--text-light);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s;
            position: relative;
        }

        .header nav a:hover {
            color: var(--primary-light);
        }

        .header nav a::after {
            content: '';
            position: absolute;
            bottom: -5px;
            left: 0;
            width: 0;
            height: 2px;
            background-color: var(--primary-light);
            transition: width 0.3s;
        }

        .header nav a:hover::after {
            width: 100%;
        }

        .utils {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .language-selector select {
            background-color: var(--card-bg);
            color: var(--text-light);
            border: 1px solid var(--card-border);
            border-radius: 4px;
            padding: 8px 12px;
            cursor: pointer;
        }

        .user-profile {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .user-profile a {
            color: var(--text-light);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .avatar {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            background-color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: white;
        }

        .support-hero {
            background: linear-gradient(135deg, var(--darker-bg), var(--dark-bg));
            padding: 80px 5%;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .support-hero::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle at top right, rgba(76, 175, 80, 0.1), transparent 70%); /* Changed primary color rgba */
            pointer-events: none;
        }

        .support-hero h1 {
            font-size: 2.5rem;
            margin-bottom: 20px;
            background: linear-gradient(to right, var(--primary-light), var(--primary));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .support-hero p {
            font-size: 1.1rem;
            max-width: 700px;
            margin: 0 auto 30px;
            color: rgba(255, 255, 255, 0.85);
        }

        .feedback-buttons {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-top: 30px;
            margin-bottom: 40px; /* Adjust as needed */
        }

        .feedback-button {
            background: linear-gradient(135deg, var(--primary-light), var(--primary));
            color: white;
            padding: 12px 25px;
            border: none;
            border-radius: 50px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        }

        .feedback-button:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.3);
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
        }


        .search-container {
            max-width: 700px;
            margin: 0 auto;
            position: relative;
        }

        .search-container input {
            width: 100%;
            padding: 15px 20px;
            border-radius: 50px;
            border: none;
            background: rgba(30, 32, 37, 0.7);
            backdrop-filter: blur(10px);
            color: white;
            font-size: 1rem;
            padding-left: 50px;
            border: 1px solid var(--card-border);
            transition: all 0.3s;
        }

        .search-container input:focus {
            outline: none;
            background: rgba(30, 32, 37, 0.9);
            border-color: var(--primary-light);
        }

        .search-container i {
            position: absolute;
            left: 20px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--primary-light);
        }

        /* Search Suggestions */
        #search-suggestions {
            position: absolute;
            top: calc(100% + 5px); /* Below the search input */
            left: 0;
            width: 100%;
            background-color: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
            z-index: 50;
            max-height: 200px;
            overflow-y: auto;
            display: none; /* Hidden by default */
            text-align: left; /* Align text to left */
        }

        .suggestion-item {
            padding: 10px 20px;
            cursor: pointer;
            color: var(--text-light);
            transition: background-color 0.2s;
            border-bottom: 1px solid rgba(255,255,255,0.05); /* Separator */
        }

        .suggestion-item:last-child {
            border-bottom: none;
        }

        .suggestion-item:hover {
            background-color: rgba(76, 175, 80, 0.2); /* Primary light with opacity */
        }


        .categories {
            padding: 60px 5%;
        }

        .section-title {
            text-align: center;
            margin-bottom: 40px;
            position: relative;
        }

        .section-title h2 {
            font-size: 2rem;
            display: inline-block;
            color: var(--primary-light);
        }

        .section-title::after {
            content: '';
            display: block;
            width: 60px;
            height: 3px;
            background: linear-gradient(to right, var(--primary-light), var(--primary));
            margin: 10px auto 0;
        }

        .categories-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 25px;
            max-width: 1400px;
            margin: 0 auto;
        }

        .category-card {
            background-color: var(--card-bg);
            border-radius: 10px;
            padding: 30px;
            transition: all 0.3s;
            border: 1px solid var(--card-border);
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }

        .category-card:hover {
            transform: translateY(-5px);
            border-color: var(--primary-light);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.3);
        }

        .category-card:hover::before {
            opacity: 1;
        }

        .category-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, rgba(76, 175, 80, 0.1), transparent); /* Changed primary color rgba */
            opacity: 0;
            transition: opacity 0.3s;
        }

        .category-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 20px;
            font-size: 1.5rem;
            color: white;
        }

        .category-card h3 {
            font-size: 1.3rem;
            margin-bottom: 15px;
            color: var(--primary-light);
        }

        .category-card p {
            color: rgba(255, 255, 255, 0.7);
            margin-bottom: 20px;
        }

        .article-tags {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            flex-wrap: wrap; /* Allow tags to wrap */
        }

        .tag {
            background-color: rgba(76, 175, 80, 0.2); /* Changed primary color rgba */
            color: var(--primary-light);
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            white-space: nowrap; /* Prevent tags from breaking */
        }

        .articles {
            padding: 60px 5%;
            background-color: var(--darker-bg);
        }

        .articles-container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .article-card {
            background-color: var(--card-bg);
            border-radius: 10px;
            padding: 25px;
            margin-bottom: 20px;
            border: 1px solid var(--card-border);
            transition: all 0.3s;
            cursor: pointer; /* Added for clickability */
        }

        .article-card:hover {
            border-color: var(--primary-light);
            transform: translateX(5px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2); /* Added for visual feedback */
        }

        .article-card h3 {
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--primary-light);
        }

        .article-card i {
            color: var(--primary-light);
        }

        .article-card p {
            color: rgba(255, 255, 255, 0.7);
            margin-bottom: 15px;
        }

        /* Yeni eklenen CSS kuralları */
        .breadcrumbs {
            max-width: 1200px;
            margin: 20px auto;
            padding: 0 5%;
            font-size: 0.9rem;
            color: rgba(255, 255, 255, 0.6);
        }

        .breadcrumbs a {
            color: var(--primary-light);
            text-decoration: none;
            transition: color 0.3s;
        }

        .breadcrumbs a:hover {
            color: var(--primary);
        }

        .breadcrumbs span {
            margin: 0 5px;
        }

        .article-detail {
            max-width: 900px;
            margin: 40px auto;
            padding: 40px;
            background-color: var(--card-bg);
            border-radius: 15px;
            border: 1px solid var(--card-border);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            display: none; /* Varsayılan olarak gizli */
        }

        .article-detail h2 {
            font-size: 2rem;
            color: var(--primary-light);
            margin-bottom: 20px;
        }

        .article-detail p, .article-detail ol li {
            color: rgba(255, 255, 255, 0.8);
            margin-bottom: 15px;
        }

        .article-detail .meta-info {
            font-size: 0.9rem;
            color: rgba(255, 255, 255, 0.6);
            margin-bottom: 20px;
            border-bottom: 1px solid var(--card-border);
            padding-bottom: 15px;
        }

        .article-detail .meta-info span {
            margin-right: 15px;
        }
        
        /* === YENİ EKLENEN CSS: YAZAR KUTUSU === */
        .article-author-box {
            margin-top: 40px;
            padding: 20px;
            background-color: var(--dark-bg);
            border: 1px solid var(--card-border);
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .author-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background-color: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            font-weight: bold;
            flex-shrink: 0; /* Avatarın küçülmesini engelle */
        }

        .author-details p {
            margin: 0;
            padding: 0;
            line-height: 1.3;
        }

        .author-details .author-name {
            font-weight: 600;
            color: var(--text-light);
        }

        .author-details .author-title {
            font-size: 0.9rem;
            color: rgba(255, 255, 255, 0.6);
        }
        /* === YENİ CSS SONU === */

        .article-detail .back-button {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: none;
            border: none;
            color: var(--primary-light);
            cursor: pointer;
            font-size: 1rem;
            margin-top: 30px;
            transition: color 0.3s;
        }

        .article-detail .back-button:hover {
            color: var(--primary);
        }

        .feedback-section {
            text-align: center;
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid var(--card-border);
        }

        .feedback-section p {
            margin-bottom: 15px;
        }
        
        .feedback-section button:disabled {
            cursor: not-allowed;
            opacity: 0.6;
        }
        
        #article-feedback-message {
            margin-top: 15px;
            color: var(--primary-light);
            font-size: 0.95rem;
        }

        /* Geri bildirim formu ve talep formu stilleri */
        .modal {
            display: none; /* Hidden by default */
            position: fixed; /* Stay in place */
            z-index: 1000; /* Sit on top */
            left: 0;
            top: 0;
            width: 100%; /* Full width */
            height: 100%; /* Full height */
            overflow: auto; /* Enable scroll if needed */
            background-color: rgba(0,0,0,0.7); /* Black w/ opacity */
            align-items: center; /* Changed from 'display: flex;' to control visibility via JS */
            justify-content: center;
        }

        .modal-content {
            background-color: var(--dark-bg);
            margin: auto;
            padding: 30px;
            border-radius: 10px;
            border: 1px solid var(--card-border);
            width: 90%;
            max-width: 600px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.5);
            position: relative;
        }

        .close-button {
            color: var(--text-light);
            position: absolute;
            top: 15px;
            right: 20px;
            font-size: 28px;
            font-weight: bold;
            transition: color 0.3s;
        }

        .close-button:hover,
        .close-button:focus {
            color: var(--primary-light);
            text-decoration: none;
            cursor: pointer;
        }

        .modal-content h2 {
            color: var(--primary-light);
            margin-bottom: 20px;
            text-align: center;
        }

        .modal-content label {
            display: block;
            margin-bottom: 8px;
            color: var(--text-light);
            font-weight: 500;
        }

        .modal-content input[type="text"],
        .modal-content input[type="email"],
        .modal-content textarea,
        .modal-content select,
        .modal-content input[type="file"] { /* Added file input */
            width: 100%;
            padding: 12px;
            margin-bottom: 20px;
            border-radius: 5px;
            border: 1px solid var(--card-border);
            background-color: var(--card-bg);
            color: var(--text-light);
            font-size: 1rem;
            box-sizing: border-box; /* Ensures padding doesn't increase total width */
        }

        .modal-content input[type="text"]:focus,
        .modal-content input[type="email"]:focus,
        .modal-content textarea:focus,
        .modal-content select:focus,
        .modal-content input[type="file"]:focus { /* Added file input */
            outline: none;
            border-color: var(--primary-light);
            box-shadow: 0 0 0 2px rgba(76, 175, 80, 0.2); /* Changed primary color rgba */
        }
        /* Specific styling for file input to look better */
        .modal-content input[type="file"] {
            padding: 10px; /* Adjust padding for file input */
        }
        .modal-content input[type="file"]::file-selector-button {
            background-color: var(--primary);
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 5px;
            cursor: pointer;
            margin-right: 10px;
            transition: background-color 0.3s;
        }
        .modal-content input[type="file"]::file-selector-button:hover {
            background-color: var(--primary-dark);
        }


        .modal-content textarea {
            resize: vertical;
            min-height: 100px;
        }

        .modal-content button[type="submit"] {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            padding: 12px 25px;
            border: none;
            border-radius: 50px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: block;
            width: 100%;
            max-width: 200px;
            margin: 0 auto;
        }

        .modal-content button[type="submit"]:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(76, 175, 80, 0.4); /* Changed primary color rgba */
        }

        .contact-section {
            padding: 80px 5%;
            text-align: center;
            background: linear-gradient(135deg, var(--dark-bg), var(--darker-bg));
            position: relative;
        }

        .contact-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" preserveAspectRatio="none"><rect fill="rgba(76,175,80,0.05)" width="100" height="100"/><path d="M0,0 L100,100 M100,0 L0,100" stroke="rgba(255,255,255,0.03)" stroke-width="1"/></svg>'); /* Changed primary color rgba */
            background-size: cover;
        }

        .contact-container {
            max-width: 700px;
            margin: 0 auto;
            position: relative;
            z-index: 1;
        }

        .contact-card {
            background-color: rgba(26, 28, 30, 0.7);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 40px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        }

        .contact-card h2 {
            font-size: 2rem;
            margin-bottom: 20px;
            color: var(--primary-light);
        }

        .contact-card p {
            margin-bottom: 30px;
            color: rgba(255, 255, 255, 0.8);
        }

        .contact-btn {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 50px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-block;
            text-decoration: none;
        }

        .contact-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(76, 175, 80, 0.4); /* Changed primary color rgba */
        }

        .footer {
            background-color: var(--footer-bg);
            padding: 60px 5% 30px;
            position: relative;
            overflow: hidden;
        }

        .footer::before {
            content: '';
            position: absolute;
            top: -50px;
            left: 0;
            width: 100%;
            height: 100px;
            background: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1440 200"><path fill="%231a1c1e" fill-opacity="0.3" d="M0,96L80,112C160,128,320,160,480,160C640,160,800,128,960,112C1120,96,1280,96,1360,96L1440,96V200H1360C1280,200,1120,200,960,200C800,200,640,200,480,200C320,200,160,200,80,200H0V200Z"></path></svg>');
            background-size: cover;
            background-position: center;
        }

        .footer-content {
            display: flex;
            flex-wrap: wrap;
            gap: 40px;
            max-width: 1400px;
            margin: 0 auto;
            position: relative;
            z-index: 1;
        }

        .footer-logo-section {
            flex: 1;
            min-width: 250px;
        }

        .footer-logo-section img {
            height: 50px;
            margin-bottom: 20px;
        }

        .footer-logo-section p {
            color: rgba(255, 255, 255, 0.6);
            max-width: 300px;
        }

        .footer-links {
            flex: 2;
            display: flex;
            gap: 40px;
            flex-wrap: wrap;
        }

        .footer-column {
            min-width: 180px;
        }

        .footer-column h3 {
            font-size: 1.2rem;
            margin-bottom: 20px;
            color: var(--primary-light);
        }

        .footer-column ul {
            list-style: none;
        }

        .footer-column ul li {
            margin-bottom: 12px;
        }

        .footer-column ul li a {
            color: rgba(255, 255, 255, 0.7);
            text-decoration: none;
            transition: color 0.3s;
        }

        .footer-column ul li a:hover {
            color: var(--primary-light);
        }

        .footer-bottom {
            max-width: 1400px;
            margin: 40px auto 0;
            padding-top: 30px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            text-align: center;
            color: rgba(255, 255, 255, 0.6);
            font-size: 0.9rem;
        }

        /* Search Filters */
        .search-filters {
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px;
            background-color: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 10px;
        }

        .search-filters label {
            font-weight: 500;
            color: var(--text-light);
        }

        .search-filters select {
            background-color: var(--dark-bg);
            color: var(--text-light);
            border: 1px solid var(--card-border);
            border-radius: 5px;
            padding: 8px 12px;
            cursor: pointer;
            font-size: 0.95rem;
            flex-grow: 1; /* Allow select to grow */
            max-width: 250px; /* Limit max width */
        }

        .search-filters select:focus {
            outline: none;
            border-color: var(--primary-light);
        }

        /* Related Articles Section */
        .related-articles-section {
            margin-top: 60px;
            padding-top: 40px;
            border-top: 1px solid var(--card-border);
            text-align: center;
        }

        /* Re-using articles-grid and article-card for related articles */
        #related-articles-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
            margin-top: 30px;
        }

        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                gap: 15px;
            }
            
            .header nav {
                flex-wrap: wrap;
                justify-content: center;
            }
            
            .categories-grid {
                grid-template-columns: 1fr;
            }
            
            .support-hero {
                padding: 50px 5%;
            }
            
            .support-hero h1 {
                font-size: 2rem;
            }
            
            .footer-content {
                flex-direction: column;
            }

            .feedback-buttons {
                flex-direction: column;
                gap: 15px;
            }

            .feedback-button {
                width: 80%;
                max-width: 300px;
            }

            .modal-content {
                width: 95%;
                padding: 20px;
            }
            .search-filters {
                flex-direction: column;
                align-items: flex-start;
            }
            .search-filters select {
                width: 100%;
                max-width: none;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <img src="icon.ico" alt="Lakeban Logo"/>
        
        <nav>
            <a href="#">Ana Sayfa</a>
            <a href="/topluluklar">Topluluklar</a>
            <a href="/changelog">Güncellemeler</a>
            <a href="/stats">İstatistikler</a>
            <a href="#">Etkinlikler</a>
            <a href="#" style="color: var(--primary-light);">Destek</a>
        </nav>

        <div class="utils">
            <div class="language-selector">
                <select>
                    <option value="tr" selected>Türkçe</option>
                    <option value="en">English</option>
                    <option value="fı">Suomi</option>
                    <option value="ru">Русский</option>
                    <option value="fr">Français</option>
                    <option value="de">Deutsch</option>
                </select>
            </div>

            <div class="user-profile">
                <a href="/profile">
                    <div class="avatar">
                        <span>K</span>
                    </div>
                    <span>KullanıcıAdı</span>
                </a>
            </div>
        </div>
    </header>

    <div class="support-hero">
        <h1>Lakeban Destek Merkezi</h1>
        <p>Sorularınıza hızlı yanıtlar bulun, kılavuzlara erişin veya destek ekibimizle iletişime geçin.</p>
        
        <div class="feedback-buttons">
            <a href="#" class="feedback-button" id="openFeedbackModal">
                <i class="fas fa-comment-alt"></i> Geri Bildirim Gönder
            </a>
            <a href="#" class="feedback-button" id="openTicketModal">
                <i class="fas fa-life-ring"></i> Talep Gönder
            </a>
        </div>

        <div class="search-container">
            <i class="fas fa-search"></i>
            <input type="text" placeholder="Sorunuzu buraya yazın..." id="search-input">
            <div id="search-suggestions"></div> </div>
    </div>


    <div class="articles" id="search-results-section" style="display: none;">
        <div class="articles-container">
            <div class="section-title">
                <h2>Arama Sonuçları</h2>
            </div>
            <div class="search-filters"> <label for="search-category-filter">Kategoriye Göre Filtrele:</label>
                <select id="search-category-filter">
                    <option value="">Tüm Kategoriler</option>
                    </select>
            </div>
            <div id="search-results-container">
            </div>
            <button class="contact-btn" style="margin-top: 30px;" onclick="hideSearchResults()">Aramayı Temizle</button>
        </div>
    </div>

    <div class="categories" id="categories-section">
        <div class="section-title">
            <h2>Yardım Kategorileri</h2>
        </div>
        
        <div class="categories-grid">
            <div class="category-card" data-category="Hesap & Profil">
                <div class="category-icon">
                    <i class="fas fa-user"></i>
                </div>
                <h3>Hesap & Profil</h3>
                <p>Hesap oluşturma, giriş problemleri, profil ayarları ve güvenlik konuları</p>
                <div class="article-tags">
                    <span class="tag">5 makale</span>
                </div>
            </div>
            
            <div class="category-card" data-category="Sohbet & Mesajlaşma">
                <div class="category-icon">
                    <i class="fas fa-comments"></i>
                </div>
                <h3>Sohbet & Mesajlaşma</h3>
                <p>Doğrudan mesajlar, grup sohbetleri ve mesajlaşma özellikleri ile ilgili yardım</p>
                <div class="article-tags">
                    <span class="tag">8 makale</span>
                </div>
            </div>
            
            <div class="category-card" data-category="Topluluklar & Sunucular">
                <div class="category-icon">
                    <i class="fas fa-users"></i>
                </div>
                <h3>Topluluklar & Sunucular</h3>
                <p>Topluluk oluşturma, yönetme ve sunucu ayarları ile ilgili rehberler</p>
                <div class="article-tags">
                    <span class="tag">12 makale</span>
                </div>
            </div>
            
            <div class="category-card" data-category="Ses & Görüntülü Sohbet">
                <div class="category-icon">
                    <i class="fas fa-microphone"></i>
                </div>
                <h3>Ses & Görüntülü Sohbet</h3>
                <p>Sesli sohbet, görüntülü arama ve ekran paylaşımı ile ilgili sorunlar</p>
                <div class="article-tags">
                    <span class="tag">7 makale</span>
                </div>
            </div>
            
            <div class="category-card" data-category="Güvenlik & Gizlilik">
                <div class="category-icon">
                    <i class="fas fa-shield-alt"></i>
                </div>
                <h3>Güvenlik & Gizlilik</h3>
                <p>Hesap güvenliği, gizlilik ayarları ve veri koruma konuları</p>
                <div class="article-tags">
                    <span class="tag">6 makale</span>
                </div>
            </div>
            
            <div class="category-card" data-category="Mobil Uygulama">
                <div class="category-icon">
                    <i class="fas fa-mobile-alt"></i>
                </div>
                <h3>Mobil Uygulama</h3>
                <p>Lakeban mobil uygulaması ile ilgili sorunlar ve çözüm önerileri</p>
                <div class="article-tags">
                    <span class="tag">9 makale</span>
                </div>
            </div>
        </div>
    </div>

    <div class="articles" id="faq-section">
        <div class="articles-container">
            <div class="section-title">
                <h2>Sık Sorulan Sorular</h2>
            </div>
            
            <div class="article-card" data-article-id="1">
                <h3><i class="fas fa-question-circle"></i> Şifremi unuttum, nasıl sıfırlayabilirim?</h3>
                <p>Giriş ekranında "Şifremi Unuttum" bağlantısına tıklayarak şifre sıfırlama işlemini başlatabilirsiniz. E-posta adresinize bir sıfırlama bağlantısı göndereceğiz.</p>
                <div class="article-tags">
                    <span class="tag">Hesap</span>
                    <span class="tag">Giriş</span>
                </div>
            </div>
            
            <div class="article-card" data-article-id="2">
                <h3><i class="fas fa-question-circle"></i> Topluluk nasıl oluşturulur?</h3>
                <p>Sol menüdeki "+" simgesine tıklayarak yeni bir topluluk oluşturabilirsiniz. Topluluğunuza isim verin, bir avatar seçin ve katılım ayarlarını yapılandırın.</p>
                <div class="article-tags">
                    <span class="tag">Topluluklar</span>
                    <span class="tag">Oluşturma</span>
                </div>
            </div>
            
            <div class="article-card" data-article-id="3">
                <h3><i class="fas fa-question-circle"></i> Sesli sohbet neden çalışmıyor?</h3>
                <p>Sesli sohbet sorunları genellikle mikrofon izinleri veya ses ayarlarıyla ilgilidir. Tarayıcı ayarlarınızdan mikrofon erişimini kontrol edin ve Lakeban ses ayarlarında doğru giriş/çıkış cihazlarının seçili olduğundan emin olun.</p>
                <div class="article-tags">
                    <span class="tag">Ses</span>
                    <span class="tag">Sorun Giderme</span>
                </div>
            </div>
            
            <div class="article-card" data-article-id="4">
                <h3><i class="fas fa-question-circle"></i> Mobil uygulama bildirimleri nasıl açılır?</h3>
                <p>Cihazınızın ayarlarından Lakeban uygulaması için bildirim izinlerini etkinleştirmeniz gerekmektedir. Uygulama içinde ise "Ayarlar > Bildirimler" bölümünden istediğiniz bildirim türlerini seçebilirsiniz.</p>
                <div class="article-tags">
                    <span class="tag">Mobil</span>
                    <span class="tag">Bildirimler</span>
                </div>
            </div>
        </div>
    </div>

    <div class="article-detail" id="article-detail-section">
        <div class="breadcrumbs" id="article-breadcrumbs">
            <a href="#" onclick="showMainContent()">Ana Sayfa</a>
            <span>/</span>
            <a href="#" onclick="showMainContent()">Destek</a>
            <span>/</span>
            <span id="breadcrumb-category"></span>
            <span>/</span>
            <span id="breadcrumb-article-title"></span>
        </div>
        <h2 id="article-title"></h2>
            <div class="article-author-box" id="author-info-box">
             <div class="author-avatar" id="author-avatar-initial"></div>
             <div class="author-details">
                <p class="author-name" id="author-name-display"></p>
                <p class="author-title">Makaleyi yazan kişi</p>
             </div>
        </div>
        <div class="meta-info">
            <span id="article-date"></span>
            <span id="article-author"></span>
        </div>
        <div id="article-content">
        </div>

        <div class="feedback-section">
            <p>Bu makale yardımcı oldu mu?</p>
            <button class="contact-btn" style="background: var(--success); margin-right: 10px;" id="article-helpful-yes">Evet</button>
            <button class="contact-btn" style="background: var(--danger);" id="article-helpful-no">Hayır</button>
            <div id="article-feedback-message"></div>
        </div>

        <button class="back-button" onclick="showMainContent()">
            <i class="fas fa-arrow-left"></i> Tüm Makalelere Geri Dön
        </button>

        <div class="related-articles-section" id="related-articles-section"> <div class="section-title" style="margin-top: 50px;">
                <h2>İlgili Makaleler</h2>
            </div>
            <div id="related-articles-container" class="articles-grid">
                </div>
        </div>
    </div>

    <div class="contact-section" id="contact-section">
        <div class="contact-container">
            <div class="contact-card">
                <h2>Hala Yardıma İhtiyacınız Var mı?</h2>
                <p>Destek ekibimiz sorunlarınızı çözmek için burada. Aşağıdaki butona tıklayarak bizimle iletişime geçebilirsiniz.</p>
                <a href="#" class="contact-btn" id="openContactModal">Destek Ekibiyle İletişime Geç</a>
            </div>
        </div>
    </div>

    <footer class="footer">
        <div class="footer-content">
            <div class="footer-logo-section">
                <img src="icon.ico" alt="Lakeban Logo">
                <p>Lakeban, kullanıcıların bir araya gelip iletişim kurmasını, paylaşmasını ve birlikte eğlenmesini sağlayan modern bir sosyal platformdur.</p>
            </div>
            
            <div class="footer-links">
                <div class="footer-column">
                    <h3>Ürün</h3>
                    <ul>
                        <li><a href="#">İndir</a></li>
                        <li><a href="#">Özellikler</a></li>
                        <li><a href="#">Güncellemeler</a></li>
                        <li><a href="#">İstatistikler</a></li>
                    </ul>
                </div>
                
                <div class="footer-column">
                    <h3>Kaynaklar</h3>
                    <ul>
                        <li><a href="#">Yardım Merkezi</a></li>
                        <li><a href="#">Topluluk Rehberi</a></li>
                        <li><a href="#">Geliştirici API</a></li>
                        <li><a href="#">Blog</a></li>
                    </ul>
                </div>
                
                <div class="footer-column">
                    <h3>Şirket</h3>
                    <ul>
                        <li><a href="#">Hakkımızda</a></li>
                        <li><a href="#">Kariyer</a></li>
                        <li><a href="#">İletişim</a></li>
                        <li><a href="#">Basın</a></li>
                    </ul>
                </div>
                
                <div class="footer-column">
                    <h3>Yasal</h3>
                    <ul>
                        <li><a href="#">Kullanım Şartları</a></li>
                        <li><a href="#">Gizlilik Politikası</a></li>
                        <li><a href="#">Çerez Politikası</a></li>
                        <li><a href="#">Telif Hakkı</a></li>
                    </ul>
                </div>
            </div>
        </div>
        
        <div class="footer-bottom">
            <p>© 2025 Lakeban. Tüm hakları saklıdır.</p>
        </div>
    </footer>

    <div id="feedbackModal" class="modal">
        <div class="modal-content">
            <span class="close-button" id="closeFeedbackModal">&times;</span>
            <h2>Geri Bildirim Gönder</h2>
            <form action="#" method="POST">
                <label for="feedback-type">Geri Bildirim Türü:</label>
                <select id="feedback-type" name="feedback_type" required>
                    <option value="genel">Genel Geri Bildirim</option>
                    <option value="hata">Hata Bildirimi</option>
                    <option value="ozellik">Özellik İsteği</option>
                    <option value="iyilestirme">İyileştirme Önerisi</option>
                </select>

                <label for="feedback-email">E-posta Adresiniz (isteğe bağlı):</label>
                <input type="email" id="feedback-email" name="email">

                <label for="feedback-message">Mesajınız:</label>
                <textarea id="feedback-message" name="message" required placeholder="Lütfen geri bildiriminizi detaylıca açıklayın..."></textarea>

                <button type="submit">Gönder</button>
            </form>
        </div>
    </div>

    <div id="ticketModal" class="modal">
        <div class="modal-content">
            <span class="close-button" id="closeTicketModal">&times;</span>
            <h2>Destek Talebi Gönder</h2>
            <form action="#" method="POST">
                <label for="ticket-subject">Konu:</label>
                <input type="text" id="ticket-subject" name="subject" required placeholder="Sorununuzu veya talebinizi özetleyin...">

                <label for="ticket-category">Kategori:</label>
                <select id="ticket-category" name="category" required>
                    <option value="">Seçiniz...</option>
                    <option value="hesap">Hesap Sorunu</option>
                    <option value="teknik">Teknik Destek</option>
                    <option value="fatura">Faturalandırma</option>
                    <option value="rapor">Kötüye Kullanım Bildirimi</option>
                    <option value="diger">Diğer</option>
                </select>

                <label for="ticket-email">E-posta Adresiniz:</label>
                <input type="email" id="ticket-email" name="email" required placeholder="example@example.com">

                <label for="ticket-description">Açıklama:</label>
                <textarea id="ticket-description" name="description" required placeholder="Lütfen sorununuzu detaylıca açıklayın, adımları ve ekran görüntülerini (varsa) ekleyebilirsiniz."></textarea>
                
                <label for="ticket-attachment">Ek Dosya (isteğe bağlı):</label>
                <input type="file" id="ticket-attachment" name="attachment">

                <button type="submit">Gönder</button>
            </form>
        </div>
    </div>

    <script>
        // === GÜNCELLENMİŞ JAVASCRIPT: MAKALE VERİLERİ ===
        // Oy sayılarını tutmak için "helpfulVotes" ve "totalVotes" eklendi.
        const articles = [
            {
                id: 1,
                title: "Şifremi unuttum, nasıl sıfırlayabilirim?",
                category: "Hesap & Profil",
                content: "<p>Şifrenizi unuttuğunuzda, Lakeban hesabınıza tekrar erişim sağlamak için birkaç basit adımı takip edebilirsiniz. İlk olarak, giriş ekranında bulunan <strong>\"Şifremi Unuttum\"</strong> veya <strong>\"Şifrenizi mi unuttunuz?\"</strong> bağlantısına tıklayın.</p><p>Bu bağlantıya tıkladıktan sonra, hesabınızla ilişkili olan e-posta adresinizi girmeniz istenecektir. E-posta adresinizi doğru bir şekilde girdikten sonra, sistemimiz size bir şifre sıfırlama bağlantısı içeren bir e-posta gönderecektir. Lütfen e-postanızın gelen kutusunu kontrol edin. Eğer göremiyorsanız, spam veya istenmeyen e-posta klasörlerinizi de kontrol etmeyi unutmayın.</p><p>E-postadaki bağlantıya tıklayarak yeni bir şifre belirleyebileceğiniz güvenli bir sayfaya yönlendirileceksiniz. Güçlü bir şifre seçtiğinizden emin olun (büyük/küçük harfler, rakamlar ve özel karakterler içeren).</p><p>Şifrenizi sıfırlarken veya hesabınıza giriş yaparken sorun yaşamaya devam ederseniz, lütfen destek ekibimizle iletişime geçmekten çekinmeyin.</p>",
                tags: ["Hesap", "Giriş", "Güvenlik", "Şifre"],
                date: "2023-10-26",
                author: "Lakeban Destek",
                helpfulVotes: 10093,
                totalVotes: 14836
            },
            {
                id: 2,
                title: "Topluluk nasıl oluşturulur?",
                category: "Topluluklar & Sunucular",
                content: "<p>Lakeban'da kendi topluluğunuzu oluşturmak oldukça kolaydır. İşte adım adım nasıl yapacağınız:</p><ol><li>Lakeban ana sayfasında veya sol menüde genellikle bir <strong>'+' (Artı)</strong> simgesi bulunur. Bu simgeye tıklayarak yeni bir topluluk oluşturma sürecini başlatın.</li><li>Karşınıza çıkan ekranda, topluluğunuza bir <strong>isim</strong> vermeniz istenecektir. Topluluğunuzu en iyi tanımlayan ve diğer kullanıcıların kolayca bulabileceği bir isim seçin.</li><li>İsteğe bağlı olarak, topluluğunuz için bir <strong>avatar (profil resmi)</strong> yükleyebilirsiniz. Bu, topluluğunuzun görsel kimliğini oluşturur.</li><li>Ardından, topluluğunuzun <strong>katılım ayarlarını</strong> yapılandırın. Topluluğunuzun herkese açık mı, yoksa sadece davetle mi katılınabilen bir topluluk mu olacağına karar verin.</li><li>Tüm ayarları yaptıktan sonra <strong>'Oluştur'</strong> veya <strong>'Kaydet'</strong> düğmesine tıklayın. Artık yeni topluluğunuz hazır!</li></ol><p>Topluluğunuzu oluşturduktan sonra, üyeleri davet edebilir, kanallar oluşturabilir ve topluluğunuzu yönetmeye başlayabilirsiniz.</p>",
                tags: ["Topluluklar", "Oluşturma", "Yönetim"],
                date: "2023-11-01",
                author: "Lakeban Destek",
                helpfulVotes: 8754,
                totalVotes: 9832
            },
            {
                id: 3,
                title: "Sesli sohbet neden çalışmıyor?",
                category: "Ses & Görüntülü Sohbet",
                content: "<p>Sesli sohbet sorunları kullanıcılarımız arasında zaman zaman karşılaşılan bir durumdur. Çoğu durumda, bu sorunlar basit ayar değişiklikleriyle çözülebilir. İşte kontrol etmeniz gerekenler:</p><ol><li><strong>Mikrofon İzinleri:</strong> Tarayıcınızın veya işletim sisteminizin Lakeban'a mikrofon erişimi izni verdiğinden emin olun. Genellikle tarayıcınızın adres çubuğunun yanında bir kilit simgesi veya kamera/mikrofon simgesi bulunur, buradan izinleri kontrol edebilirsiniz.</li><li><strong>Ses Ayarları:</strong> Lakeban içindeki ses ayarlarına gidin (genellikle kullanıcı ayarlarında bulunur). Doğru giriş (mikrofon) ve çıkış (hoparlör/kulaklık) cihazlarının seçili olduğundan emin olun. Ayrıca giriş hassasiyetini ve ses seviyelerini kontrol edin.</li><li><strong>Cihaz Sürücüleri:</strong> Mikrofon ve ses kartı sürücülerinizin güncel olduğundan emin olun. Gerekirse üreticinin web sitesinden en son sürücüleri indirin ve kurun.</li><li><strong>İnternet Bağlantısı:</strong> Kararlı bir internet bağlantısı, sesli sohbet kalitesi için kritiktir. Bağlantınızın hızını ve kararlılığını kontrol edin.</li><li><strong>Arka Plan Uygulamaları:</strong> Sesli sohbet sırasında band genişliği veya kaynak tüketen diğer uygulamaları kapatmayı deneyin.</li><li><strong>Farklı Cihaz/Tarayıcı:</strong> Mümkünse, farklı bir mikrofon, hoparlör veya farklı bir web tarayıcısı ile deneyin.</li></ol><p>Yukarıdaki adımlar sorunu çözmezse, daha detaylı destek için lütfen sistem bilgilerinizle birlikte destek ekibimizle iletişime geçin.</p>",
                tags: ["Ses", "Sorun Giderme", "Mikrofon", "Ayarlar"],
                date: "2023-11-15",
                author: "Lakeban Destek",
                helpfulVotes: 6421,
                totalVotes: 8145
            },
            {
                id: 4,
                title: "Mobil uygulama bildirimleri nasıl açılır?",
                category: "Mobil Uygulama",
                content: "<p>Lakeban mobil uygulamasından bildirim almak, topluluğunuzdaki gelişmelerden ve özel mesajlardan anında haberdar olmanızı sağlar. Bildirimleri etkinleştirmek için hem cihazınızın ayarlarından hem de uygulama içinden bazı adımları tamamlamanız gerekebilir:</p><ol><li><strong>Cihaz Ayarları (Android/iOS):</strong><p>   <ul><li><strong>Android:</strong> Cihazınızın 'Ayarlar' uygulamasını açın. 'Uygulamalar' veya 'Uygulama Yöneticisi' bölümüne gidin. Lakeban uygulamasını bulun ve üzerine dokunun. 'Bildirimler' veya 'Bildirim İzinleri' seçeneğini bulun ve buradan Lakeban için tüm bildirimlerin açık olduğundan emin olun.</li><li><strong>iOS (iPhone/iPad):</strong> Cihazınızın 'Ayarlar' uygulamasını açın. Aşağı kaydırarak 'Lakeban' uygulamasını bulun ve üzerine dokunun. 'Bildirimler' seçeneğine dokunun ve 'Bildirimlere İzin Ver' (Allow Notifications) seçeneğinin açık olduğundan emin olun. Buradan sesler, rozetler ve uyarı stilleri gibi detaylı bildirim ayarlarını da yapabilirsiniz.</li></ul></p></li><li><strong>Lakeban Uygulama İçi Ayarları:</strong><p>   Lakeban uygulamasını açın ve profilinize veya ayarlar menüsüne gidin. Genellikle sağ üstte veya alt menüde bir ayarlar (dişli) simgesi bulunur. 'Ayarlar' içinde <strong>'Bildirimler'</strong> bölümünü bulun. Buradan hangi tür bildirimleri (mesajlar, topluluk duyuruları, arkadaşlık istekleri vb.) almak istediğinizi özelleştirebilirsiniz. Sesler, titreşimler ve bildirim öncelikleri gibi detaylı ayarları da buradan yapabilirsiniz.</p></li></ol><p>Bu adımları tamamladıktan sonra, Lakeban bildirimlerini almaya başlamalısınız. Hala sorun yaşıyorsanız, mobil cihazınızı yeniden başlatmayı deneyin veya uygulamayı güncelleyin.</p>",
                tags: ["Mobil", "Bildirimler", "Ayarlar"],
                date: "2023-12-05",
                author: "Lakeban Destek",
                helpfulVotes: 7322,
                totalVotes: 8901
            },
             {
                id: 5,
                title: "Hesabıma erişemiyorum, ne yapmalıyım?",
                category: "Hesap & Profil",
                content: "<p>Hesabınıza erişememe sorunları birkaç farklı nedenden kaynaklanabilir. İşte deneyebileceğiniz bazı çözümler:</p><ol><li><strong>Doğru Kullanıcı Adı ve Şifre:</strong> İlk olarak, kullanıcı adınızı (veya e-posta adresinizi) ve şifrenizi doğru girdiğinizden emin olun. Büyük/küçük harf duyarlılığına dikkat edin.</li><li><strong>Şifre Sıfırlama:</strong> Şifrenizi unuttuysanız, giriş ekranındaki \"Şifremi Unuttum\" bağlantısını kullanarak şifrenizi sıfırlayın. E-posta adresinize gönderilen talimatları takip edin.</li><li><strong>Hesap Kilitleme:</strong> Çok sayıda yanlış giriş denemesi yaptıysanız hesabınız geçici olarak kilitlenmiş olabilir. Bir süre bekleyin ve tekrar deneyin.</li><li><strong>E-posta Doğrulama:</strong> Hesabınızın e-posta adresini doğrulamanız gerekebilir. Doğrulama e-postasını kontrol edin.</li><li><strong>İnternet Bağlantısı:</strong> Stabil bir internet bağlantınız olduğundan emin olun.</li></ol><p>Bu adımlar sorunu çözmezse, lütfen destek ekibimizle iletişime geçin ve sorununuzu detaylıca açıklayın.</p>",
                tags: ["Hesap", "Giriş", "Sorun Giderme"],
                date: "2024-01-10",
                author: "Lakeban Destek",
                helpfulVotes: 4321,
                totalVotes: 6789
            },
            {
                id: 6,
                title: "Topluluk kuralları ve moderasyon nedir?",
                category: "Topluluklar & Sunucular",
                content: "<p>Lakeban toplulukları, sağlıklı ve güvenli bir ortam sağlamak için belirli kurallar ve moderasyon mekanizmalarıyla işler.</p><ol><li><strong>Topluluk Kuralları:</strong> Her topluluğun kendi özel kuralları olabilir. Bu kurallar genellikle topluluğun amacına ve içeriğine göre belirlenir ve üyelerin uyması gereken davranış standartlarını belirtir. Genel Lakeban kurallarına ek olarak işler.</li><li><strong>Moderasyon:</strong> Topluluk sahipleri ve yöneticileri (moderatörler), topluluk kurallarına uyulmasını sağlamak için moderasyon araçlarını kullanır. Bu araçlar arasında mesaj silme, üyeleri uyarma, susturma veya topluluktan uzaklaştırma gibi yetenekler bulunur.</li></ol><p>Amacımız, her kullanıcının kendini güvende ve rahat hissettiği bir ortam yaratmaktır. Kurallara aykırı davranışlar gördüğünüzde, lütfen ilgili içeriği veya kullanıcıyı bildirin.</p>",
                tags: ["Topluluklar", "Kurallar", "Moderasyon", "Güvenlik"],
                date: "2024-02-20",
                author: "Lakeban Destek",
                helpfulVotes: 5102,
                totalVotes: 5890
            },
            {
                id: 7,
                title: "Grup sohbeti oluşturma ve yönetme",
                category: "Sohbet & Mesajlaşma",
                content: "<p>Lakeban'da arkadaşlarınızla veya belirli bir konu etrafında toplanmış kişilerle grup sohbetleri oluşturmak kolaydır. İşte nasıl yapacağınız:</p><ol><li><strong>Grup Oluşturma:</strong> Sol menüdeki 'Mesajlar' bölümüne gidin ve '+' simgesine tıklayın. 'Yeni Grup Sohbeti Oluştur' seçeneğini seçin.</li><li><strong>Üye Ekleme:</strong> Grubunuza eklemek istediğiniz arkadaşlarınızı listeden seçin veya kullanıcı adlarını aratarak bulun.</li><li><strong>Grubu Yönetme:</strong> Grup sohbeti açıldıktan sonra, grup ayarlarına giderek grubun adını değiştirebilir, bir grup resmi ekleyebilir, üyeleri çıkarabilir veya yeni üyeler ekleyebilirsiniz.</li></ol><p>Grup sohbetleri, birden fazla kişiyle aynı anda iletişim kurmak için harika bir yoldur.</p>",
                tags: ["Sohbet", "Grup", "Mesajlaşma"],
                date: "2024-03-01",
                author: "Lakeban Destek",
                helpfulVotes: 3456,
                totalVotes: 4321
            },
            {
                id: 8,
                title: "Lakeban'da rozetler ve seviyeler ne işe yarar?",
                category: "Hesap & Profil",
                content: "<p>Lakeban'daki rozetler ve seviyeler, platformdaki aktivitenizi ve katkılarınızı gösteren görsel ödüllerdir. İşte temel anlamları:</p><ol><li><strong>Rozetler:</strong> Belirli başarıları (örneğin, 100 mesaj gönderme, bir topluluğa katılma, etkinliklere katılma) tamamladığınızda kazandığınız özel işaretlerdir. Rozetler profilinizde görünür ve diğer kullanıcılara başarılarınızı gösterir.</li><li><strong>Seviyeler:</strong> Lakeban'da aktif oldukça (mesaj gönderme, topluluklara katılma, beğeniler alma vb.) deneyim puanı kazanırsınız. Bu puanlar biriktikçe seviyeniz yükselir. Daha yüksek seviyeler genellikle daha fazla profil özelleştirme seçeneği veya özel avantajlar sunabilir.</li></ol><p>Rozetler ve seviyeler, Lakeban deneyiminizi daha eğlenceli ve ödüllendirici hale getirmek için tasarlanmıştır.</p>",
                tags: ["Hesap", "Profil", "Rozetler", "Seviyeler"],
                date: "2024-03-15",
                author: "Lakeban Destek",
                helpfulVotes: 6789,
                totalVotes: 7890
            },
            {
                id: 9,
                title: "Lakeban'da görüntülü arama nasıl yapılır?",
                category: "Ses & Görüntülü Sohbet",
                content: "<p>Lakeban, arkadaşlarınızla ve topluluk üyelerinizle yüz yüze iletişim kurmanız için yüksek kaliteli görüntülü arama özellikleri sunar. İşte adım adım nasıl görüntülü arama başlatacağınız:</p><ol><li><strong>Bire Bir Görüntülü Arama:</strong> Bir arkadaşınızla özel sohbet penceresini açın. Sohbet penceresinin üst kısmında bir kamera simgesi veya 'Görüntülü Arama Başlat' butonu bulunur. Buna tıklayarak aramayı başlatabilirsiniz.</li><li><strong>Grup Görüntülü Arama:</strong> Bir grup sohbeti veya sesli kanal içindeyken, genellikle ekranın üst veya alt kısmında bulunan video kamera simgesine tıklayarak grup görüntülü aramasını başlatabilirsiniz.</li></ol><p>Görüntülü arama sırasında mikrofon ve kamera izinlerinizin doğru ayarlandığından emin olun. Ayrıca, iyi bir internet bağlantısı kesintisiz bir deneyim için önemlidir.</p>",
                tags: ["Görüntülü Sohbet", "Arama", "Ses"],
                date: "2024-04-01",
                author: "Lakeban Destek",
                helpfulVotes: 4567,
                totalVotes: 5678
            },
            {
                id: 10,
                title: "Lakeban mobil uygulamasını indirme ve yükleme",
                category: "Mobil Uygulama",
                content: "<p>Lakeban mobil uygulamasını indirerek, hareket halindeyken bile arkadaşlarınızla ve topluluklarınızla bağlantıda kalabilirsiniz. Uygulamayı indirme ve yükleme adımları mobil cihazınızın işletim sistemine göre değişir:</p><ol><li><strong>Android Cihazlar İçin:</strong> Google Play Store'u açın. Arama çubuğuna 'Lakeban' yazın. Uygulamayı bulduktan sonra 'Yükle' düğmesine dokunun. Yükleme tamamlandığında uygulamayı açabilirsiniz.</li><li><strong>iOS (iPhone/iPad) Cihazlar İçin:</strong> App Store'u açın. Arama çubuğuna 'Lakeban' yazın. Uygulamayı bulduktan sonra 'Al' veya bulut simgesine dokunun. Yükleme tamamlandığında uygulamayı açabilirsiniz.</li></ol><p>Uygulama yüklendikten sonra mevcut hesabınızla giriş yapabilir veya yeni bir hesap oluşturabilirsiniz.</p>",
                tags: ["Mobil", "İndirme", "Yükleme"],
                date: "2024-04-10",
                author: "Lakeban Destek",
                helpfulVotes: 9876,
                totalVotes: 11234
            },
            {
                id: 11,
                title: "Lakeban'da gizlilik ayarları nasıl yapılandırılır?",
                category: "Güvenlik & Gizlilik",
                content: "<p>Lakeban'da gizliliğiniz bizim için önemlidir. Profilinizin ve verilerinizin görünürlüğünü kontrol etmek için çeşitli gizlilik ayarlarını yapılandırabilirsiniz:</p><ol><li><strong>Profil Gizliliği:</strong> Kullanıcı ayarlarınıza gidin ve 'Gizlilik ve Güvenlik' bölümünü bulun. Burada, profilinizin kimler tarafından görüntülenebileceği, arkadaşlık isteği gönderebileceği veya doğrudan mesaj gönderebileceği gibi ayarları yapabilirsiniz.</li><li><strong>Mesaj Filtreleme:</strong> İstenmeyen veya uygunsuz mesajları otomatik olarak filtrelemek için ayarları yapılandırabilirsiniz.</li><li><strong>Veri İzinleri:</strong> Hangi verilerinizi Lakeban'ın kullanabileceği veya üçüncü taraf hizmetlerle paylaşabileceği konusunda seçenekleri gözden geçirin.</li></ol><p>Gizlilik ayarlarınızı düzenli olarak kontrol etmeniz ve ihtiyaçlarınıza göre güncellemeniz önerilir.</p>",
                tags: ["Gizlilik", "Ayarlar", "Güvenlik", "Veri"],
                date: "2024-05-01",
                author: "Lakeban Destek",
                helpfulVotes: 7654,
                totalVotes: 8765
            },
            {
                id: 12,
                title: "Lakeban kurallarını ihlal eden birini nasıl şikayet ederim?",
                category: "Güvenlik & Gizlilik",
                content: "<p>Lakeban topluluğunun güvenliğini sağlamak için, kurallarımızı ihlal eden davranışları bize bildirmeniz önemlidir. Bir kullanıcıyı veya içeriği şikayet etmek için aşağıdaki adımları izleyebilirsiniz:</p><ol><li><strong>Mesaj Şikayeti:</strong> Bir mesaja sağ tıklayın (veya mobil cihazda basılı tutun) ve 'Şikayet Et' veya 'Kötüye Kullanımı Bildir' seçeneğini seçin.</li><li><strong>Kullanıcı Şikayeti:</strong> Bir kullanıcının profiline gidin ve genellikle üç nokta (...) simgesi altında bulunan 'Şikayet Et' seçeneğini bulun.</li><li><strong>Detay Sağlama:</strong> Şikayet ederken, ihlalin türünü, gerçekleştiği tarihi/saati ve mümkünse ilgili kanıtları (ekran görüntüsü, mesaj bağlantısı vb.) detaylıca belirtin.</li></ol><p>Tüm şikayetler ciddiyetle incelenir ve uygun eylemler alınır. Şikayetleriniz tamamen gizli tutulacaktır.</p>",
                tags: ["Şikayet", "Güvenlik", "Kurallar", "Bildirim"],
                date: "2024-05-15",
                author: "Lakeban Destek",
                helpfulVotes: 8765,
                totalVotes: 9876
            }
        ];
        
        // === GÜNCELLENMİŞ JAVASCRIPT: DEĞİŞKENLER VE FONKSİYONLAR ===

        // HTML elemanlarını seçme
        const searchInput = document.getElementById('search-input');
        const searchSuggestionsContainer = document.getElementById('search-suggestions');
        const searchResultsSection = document.getElementById('search-results-section');
        const searchResultsContainer = document.getElementById('search-results-container');
        const searchCategoryFilter = document.getElementById('search-category-filter');
        const categoriesSection = document.getElementById('categories-section');
        const faqSection = document.getElementById('faq-section');
        const contactSection = document.getElementById('contact-section');
        const articleDetailSection = document.getElementById('article-detail-section');
        const articleTitle = document.getElementById('article-title');
        const articleDate = document.getElementById('article-date');
        const articleAuthor = document.getElementById('article-author');
        const articleContent = document.getElementById('article-content');
        const breadcrumbCategory = document.getElementById('breadcrumb-category');
        const breadcrumbArticleTitle = document.getElementById('breadcrumb-article-title');
        const relatedArticlesContainer = document.getElementById('related-articles-container');
        const articleHelpfulYesBtn = document.getElementById('article-helpful-yes');
        const articleHelpfulNoBtn = document.getElementById('article-helpful-no');
        const articleFeedbackMessage = document.getElementById('article-feedback-message');
        
        // Yeni eklenen yazar kutusu elemanları
        const authorAvatarInitial = document.getElementById('author-avatar-initial');
        const authorNameDisplay = document.getElementById('author-name-display');

        // Modallar ve Butonlar
        const feedbackModal = document.getElementById('feedbackModal');
        const openFeedbackModalBtn = document.getElementById('openFeedbackModal');
        const closeFeedbackModalBtn = document.getElementById('closeFeedbackModal');
        const ticketModal = document.getElementById('ticketModal');
        const openTicketModalBtn = document.getElementById('openTicketModal');
        const closeTicketModalBtn = document.getElementById('closeTicketModal');
        const openContactModalBtn = document.getElementById('openContactModal');

        // Görüntülenen makalenin ID'sini tutmak için global değişken
        let currentArticleId = null;

        // Modalları Açma/Kapama
        openFeedbackModalBtn.onclick = function() { feedbackModal.style.display = 'flex'; }
        closeFeedbackModalBtn.onclick = function() { feedbackModal.style.display = 'none'; }
        openTicketModalBtn.onclick = function() { ticketModal.style.display = 'flex'; }
        closeTicketModalBtn.onclick = function() { ticketModal.style.display = 'none'; }
        openContactModalBtn.onclick = function() { ticketModal.style.display = 'flex'; } 

        window.onclick = function(event) {
            if (event.target == feedbackModal) {
                feedbackModal.style.display = 'none';
            }
            if (event.target == ticketModal) {
                ticketModal.style.display = 'none';
            }
            if (event.target !== searchInput && !searchSuggestionsContainer.contains(event.target)) {
                searchSuggestionsContainer.style.display = 'none';
            }
        }

        // Form gönderimi
        document.getElementById('feedbackModal').querySelector('form').addEventListener('submit', function(e) {
            e.preventDefault();
            alert('Geri bildiriminiz gönderildi! Teşekkür ederiz.');
            feedbackModal.style.display = 'none';
            this.reset();
        });

        document.getElementById('ticketModal').querySelector('form').addEventListener('submit', function(e) {
            e.preventDefault();
            alert('Talebiniz başarıyla gönderildi! Sizinle en kısa sürede iletişime geçeceğiz.');
            ticketModal.style.display = 'none';
            this.reset();
        });

        // Ana içeriği gösteren fonksiyon
        function showMainContent() {
            categoriesSection.style.display = 'block';
            faqSection.style.display = 'block';
            contactSection.style.display = 'block';
            articleDetailSection.style.display = 'none';
            searchResultsSection.style.display = 'none';
            searchInput.value = '';
            searchSuggestionsContainer.innerHTML = '';
            searchSuggestionsContainer.style.display = 'none';
            document.querySelector('.support-hero h1').style.display = 'block';
            document.querySelector('.support-hero p').style.display = 'block';
            currentArticleId = null; // Aktif makale ID'sini temizle
        }

        // Makale detayını gösteren fonksiyon (GÜNCELLENDİ)
        function showArticleDetail(articleId) {
            const article = articles.find(a => a.id === articleId);
            if (article) {
                currentArticleId = articleId; // Global ID'yi ayarla

                articleTitle.textContent = article.title;
                articleDate.textContent = `Yayın Tarihi: ${article.date}`;
                articleAuthor.textContent = `Yazar: ${article.author}`; // Eski metin bilgisi kalabilir
                articleContent.innerHTML = article.content;
                breadcrumbCategory.textContent = article.category;
                breadcrumbArticleTitle.textContent = article.title;
                
                // Yazar kutusunu doldur
                authorNameDisplay.textContent = article.author;
                authorAvatarInitial.textContent = article.author.charAt(0).toUpperCase();

                // Oy butonlarını sıfırla ve etkinleştir
                articleHelpfulYesBtn.disabled = false;
                articleHelpfulNoBtn.disabled = false;
                
                // Başlangıç geri bildirim metnini göster
                updateFeedbackMessage(article);

                categoriesSection.style.display = 'none';
                faqSection.style.display = 'none';
                contactSection.style.display = 'none';
                searchResultsSection.style.display = 'none';
                articleDetailSection.style.display = 'block';
                document.querySelector('.support-hero h1').style.display = 'none';
                document.querySelector('.support-hero p').style.display = 'none';
                window.scrollTo(0, 0);
                displayRelatedArticles(article);
            }
        }
        
        // Geri bildirim mesajını güncelleyen fonksiyon (YENİ)
        function updateFeedbackMessage(article) {
             if(article.totalVotes > 0) {
                 articleFeedbackMessage.textContent = `${article.totalVotes.toLocaleString('tr-TR')} kişiden ${article.helpfulVotes.toLocaleString('tr-TR')}'ü bu makaleyi faydalı buldu.`;
             } else {
                 articleFeedbackMessage.textContent = ''; // Henüz oy yoksa mesajı boşalt
             }
        }

        // Arama ve filtreleme fonksiyonu
        function performSearch() {
            const query = searchInput.value.trim().toLowerCase();
            const selectedCategory = searchCategoryFilter.value;
            searchResultsContainer.innerHTML = ''; 
            searchSuggestionsContainer.innerHTML = ''; 
            searchSuggestionsContainer.style.display = 'none'; 

            let filteredArticles = articles;

            if (query.length > 2) {
                filteredArticles = filteredArticles.filter(article =>
                    article.title.toLowerCase().includes(query) ||
                    article.content.toLowerCase().includes(query) ||
                    article.tags.some(tag => tag.toLowerCase().includes(query)) ||
                    article.category.toLowerCase().includes(query)
                );
            } else if (query.length > 0 && query.length <= 2) {
                searchResultsSection.style.display = 'none';
                categoriesSection.style.display = 'block';
                faqSection.style.display = 'block';
                contactSection.style.display = 'block';
                return; 
            }

            if (selectedCategory) {
                filteredArticles = filteredArticles.filter(article =>
                    article.category === selectedCategory
                );
            }

            if (filteredArticles.length > 0) {
                filteredArticles.forEach(article => {
                    const articleCard = document.createElement('div');
                    articleCard.classList.add('article-card');
                    articleCard.setAttribute('data-article-id', article.id);
                    articleCard.innerHTML = `
                        <h3><i class="fas fa-question-circle"></i> ${article.title}</h3>
                        <p>${article.content.substring(0, 150)}...</p>
                        <div class="article-tags">
                            <span class="tag">${article.category}</span>
                            ${article.tags.map(tag => `<span class="tag">${tag}</span>`).join('')}
                        </div>
                    `;
                    articleCard.addEventListener('click', () => showArticleDetail(article.id));
                    searchResultsContainer.appendChild(articleCard);
                });
                searchResultsSection.style.display = 'block';
                categoriesSection.style.display = 'none';
                faqSection.style.display = 'none';
                contactSection.style.display = 'none';
                window.scrollTo(0, 0); 
            } else if (query.length > 0 || selectedCategory) { 
                searchResultsContainer.innerHTML = `<p style="text-align: center; color: rgba(255,255,255,0.7);">"${query}" için veya seçili filtrelerle sonuç bulunamadı.</p>`;
                searchResultsSection.style.display = 'block';
                categoriesSection.style.display = 'none';
                faqSection.style.display = 'none';
                contactSection.style.display = 'none';
                window.scrollTo(0, 0);
            } else { 
                hideSearchResults();
            }
        }


        searchInput.addEventListener('input', function(e) {
            const query = this.value.trim().toLowerCase();
            
            searchSuggestionsContainer.innerHTML = ''; 
            if (query.length > 0) {
                const suggestions = articles.filter(article =>
                    article.title.toLowerCase().includes(query)
                ).slice(0, 5); 

                if (suggestions.length > 0) {
                    suggestions.forEach(article => {
                        const suggestionItem = document.createElement('div');
                        suggestionItem.classList.add('suggestion-item');
                        suggestionItem.textContent = article.title;
                        suggestionItem.addEventListener('click', () => {
                            searchInput.value = article.title; 
                            performSearch();
                        });
                        searchSuggestionsContainer.appendChild(suggestionItem);
                    });
                    searchSuggestionsContainer.style.display = 'block';
                } else {
                    searchSuggestionsContainer.style.display = 'none';
                }
            } else {
                searchSuggestionsContainer.style.display = 'none';
            }
            
            if (query.length > 2 || query.length === 0) {
                performSearch();
            } else {
                searchResultsSection.style.display = 'none';
                categoriesSection.style.display = 'block';
                faqSection.style.display = 'block';
                contactSection.style.display = 'block';
            }
        });

        function hideSearchResults() {
            searchResultsSection.style.display = 'none';
            searchResultsContainer.innerHTML = '';
            searchCategoryFilter.value = ''; 
            showMainContent();
        }

        function populateCategoryFilter() {
            const categories = [...new Set(articles.map(article => article.category))].sort();
            categories.forEach(category => {
                const option = document.createElement('option');
                option.value = category;
                option.textContent = category;
                searchCategoryFilter.appendChild(option);
            });
        }
        searchCategoryFilter.addEventListener('change', performSearch);


        function displayRelatedArticles(currentArticle) {
            relatedArticlesContainer.innerHTML = '';

            const related = articles.filter(article =>
                article.category === currentArticle.category && article.id !== currentArticle.id
            ).slice(0, 3); 

            if (related.length > 0) {
                document.getElementById('related-articles-section').style.display = 'block';
                related.forEach(article => {
                    const articleCard = document.createElement('div');
                    articleCard.classList.add('article-card');
                    articleCard.setAttribute('data-article-id', article.id);
                    articleCard.innerHTML = `
                        <h3><i class="fas fa-question-circle"></i> ${article.title}</h3>
                        <p>${article.content.substring(0, 100)}...</p>
                        <div class="article-tags">
                            <span class="tag">${article.category}</span>
                        </div>
                    `;
                    articleCard.addEventListener('click', () => showArticleDetail(article.id));
                    relatedArticlesContainer.appendChild(articleCard);
                });
            } else {
                document.getElementById('related-articles-section').style.display = 'none';
            }
        }

        // === YENİ JAVASCRIPT: FAYDALI OLDU MU BUTONLARI ===
        articleHelpfulYesBtn.addEventListener('click', () => {
            if (!currentArticleId) return; // Makale seçili değilse bir şey yapma
            
            const article = articles.find(a => a.id === currentArticleId);
            article.helpfulVotes++;
            article.totalVotes++;
            
            updateFeedbackMessage(article);
            
            // Butonları devre dışı bırak
            articleHelpfulYesBtn.disabled = true;
            articleHelpfulNoBtn.disabled = true;
        });

        articleHelpfulNoBtn.addEventListener('click', () => {
            if (!currentArticleId) return; // Makale seçili değilse bir şey yapma
            
            const article = articles.find(a => a.id === currentArticleId);
            article.totalVotes++;
            
            updateFeedbackMessage(article);
            
            // Butonları devre dışı bırak
            articleHelpfulYesBtn.disabled = true;
            articleHelpfulNoBtn.disabled = true;
        });


        // Kategori kartlarına tıklama olayı
        document.querySelectorAll('.category-card').forEach(card => {
            card.addEventListener('click', function() {
                const category = this.dataset.category;
                const filteredArticles = articles.filter(article => article.category === category);

                searchResultsContainer.innerHTML = '';
                searchCategoryFilter.value = category; 

                if (filteredArticles.length > 0) {
                    filteredArticles.forEach(article => {
                        const articleCard = document.createElement('div');
                        articleCard.classList.add('article-card');
                        articleCard.setAttribute('data-article-id', article.id);
                        articleCard.innerHTML = `
                            <h3><i class="fas fa-question-circle"></i> ${article.title}</h3>
                            <p>${article.content.substring(0, 150)}...</p>
                            <div class="article-tags">
                                <span class="tag">${article.category}</span>
                                ${article.tags.map(tag => `<span class="tag">${tag}</span>`).join('')}
                            </div>
                        `;
                        articleCard.addEventListener('click', () => showArticleDetail(article.id));
                        searchResultsContainer.appendChild(articleCard);
                    });
                    searchResultsSection.style.display = 'block';
                    categoriesSection.style.display = 'none';
                    faqSection.style.display = 'none';
                    contactSection.style.display = 'none';
                    window.scrollTo(0, 0); 
                } else {
                    searchResultsContainer.innerHTML = `<p style="text-align: center; color: rgba(255,255,255,0.7);">"${category}" kategorisinde makale bulunamadı.</p>`;
                    searchResultsSection.style.display = 'block';
                    categoriesSection.style.display = 'none';
                    faqSection.style.display = 'none';
                    contactSection.style.display = 'none';
                    window.scrollTo(0, 0);
                }
            });
        });

        // Makale kartlarına tıklanabilirlik
        document.querySelectorAll('#faq-section .article-card').forEach(card => {
            card.addEventListener('click', function() {
                const articleId = parseInt(this.dataset.articleId);
                showArticleDetail(articleId);
            });
        });

        // Sayfa yüklendiğinde ana içeriği göster
        document.addEventListener('DOMContentLoaded', () => {
            populateCategoryFilter(); 
            showMainContent();
        });

    </script>
</body>
</html>