<!DOCTYPE HTML>
<head>
    <title>Yenilikler!</title>
    <script src="https://cdn.jsdelivr.net/npm/lucide@latest/dist/umd/lucide.min.js"></script>
    <link rel="icon" type="image/x-icon" href="/icon.ico">
    <style>
        body {   /*Boş. Mokoko.*/
            background-color: #36393f;
            position: relative;
            overflow: hidden;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }
        .background-alert {   /*Modal ana arka planı.*/
            user-select: none;
            position: absolute;
            display: flex;
            width: 65%;
            border-radius: 10px;
            margin-top: -30px;
            height: 350px;           /*GÜNCELLEME BOYUTUNA GÖRE SAYFAYI UZATMAYI VEYA KISALTMAYI UNUTMAYIN!!!!!!!!!!!*/
            align-items: center;
            background-color: #101010;
            justify-content: left;
            border-style: solid;
            border-width: 2px;
            border-color: #202020;
            box-shadow: 0px 5px 15px rgba(0,0,0,0.5);
        }
        .head-alert { /*Ana başlık.*/
           user-select: none;
           position: absolute;
           display: flex;
           width: 60%;
           font-family: Arial;
           font-weight: bolder;
           text-align: Left;
           color: #e1e7ed;
           font-size: 45px;
           padding: 3px;
           top: -28px;
           left: 36%;
        }
        .head-alt-alert { /*Güncelleme başlığı*/
           user-select: none;
           position: absolute;
           display: flex;
           width: 60%;
           font-family: Arial;
           font-weight: 600;
           text-align: Left;
           color: #3CB371;
           font-size: 25px;
           padding: 3px;
           top: 30px;
           left: 1%;
           padding-bottom: -50px;
        }
        .background-alert ul { /*Tag ile ilgili*/
            padding-left: 20px;
            padding-top: 10px;
        }
         .background-alert li { /*Tag ile ilgili*/
           margin-bottom: 10px;
             color: white;
            margin-left: -310px;
            font-size: 12px;
            font-weight: 600;
        }
        .tag { /*Tag ile ilgili*/
            display: inline-block;
            font-family: Arial;
            padding: 5px 10px;
            color: white;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-right: 5px;
        }

        .tag-new { /*Tag ile ilgili*/
           background-color: #43b581;
           user-select: none;
        }

        .tag-improvement { /*Tag ile ilgili*/
           background-color: #faa61a;
           user-select: none;
        }

        .tag-bugfix { /*Tag ile ilgili*/
           background-color: #f04747;
           user-select: none;
        }
        .pharaghp-alert {
           position: absolute;
           display: flex;
           width: 60%;
           font-family: Arial;
           font-weight: 600;
           text-align: Left;
           color: #3CB371;
           font-size: 15px;
           padding: 3px;
           top: 180px;
           left: 1%;
           right: 20px;
        }
        .checkbox {
            position: absolute;
            left: 315px;
            background-color: #181818;
        }
        .close-alert {
            position: absolute;
            bottom: -65px;
            width: 90px;
            height: 40px;
            font-family: Arial;
            left: 760px;
            background-color: #9b0000;
            color: white;
            text-align: center;
            font-size: 22px;
            font-style: bolder;
            border-color: white;
            border-width: 1px;
            border-style: solid;
            border-radius: 5px;
            -webkit-tap-highlight-color: transparent;
            transition: box-shadow 0.7s ease, transform 0.7s ease;
            cursor: pointer;
            box-shadow: 0px 0px 5px 2px rgba(130, 0, 0, 0.7);
        }
        .close-alert:hover {
            box-shadow: 0px 0px 11px 5px rgba(150, 0, 0, 0.7);
            transform: scale(1.04);
        }
    </style>
</head>
<body>
    <div class="background-alert"> <!-- Ana koyu arkaplan. -->
        <h1 class="head-alert"> <!-- Ana başlık -->
            Yenilikler!
        </h1>
        <h3 class="head-alt-alert"> <!-- Güncellme başlığı -->
            Sürüm 1.6.3 - 3 Nisan 2025 
             <ul> <!-- İçerikler -->
                <li><span class="tag tag-improvement">İyileştirme</span>Typing indicator websocket ile çalışmaya başladı, artık gerçek zamanlı.</li>
                <li><span class="tag tag-new">Yeni</span>Login, Topluluklar, Register sayfaları çevirildi.</li>
          </ul>
          <p class="pharaghp-alert">
              Bir sonraki güncellemeye kadar gösterme:
              <label>
                    <input type="checkbox" id="checkbox" class="checkbox">
             </label>
             <button class="close-alert">
                 Kapat
             </button>
          </p>
        </h3>
       
    </div>
    <script>
        lucide.createIcons();
    </script>
</body>
