<!DOCTYPE HTML>
<head>
    <title>Alu purna</title>
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
        .background {   /*Modal ana arka planı.*/
            user-select: none;
            position: absolute;
            display: flex;
            width: 65%;
            border-radius: 10px;
            margin-top: -30px;
            height: 450px;
            align-items: center;
            background-color: #101010;
            justify-content: center;
            border-style: solid;
            border-width: 2px;
            border-color: #202020;
        }
        .profile-container {   /*Kullanıcı pp lerinin üstünde durduğu div. Üstteki şey işte.*/
            position: absolute;
            display: flex;
            width: 30%;
            left: 50%;
            transform: translateX(-50%);
            top: 2%;
            border-radius: 25px;
            height: 70px;
            justify-content: center;
            align-items: center;
            background-color: #141414;
            perspective: 1000px;
            border-style: solid;
            border-width: 1px;
            border-color: #242424;
        }
        .profiles1 {   /*Kullanıcı pp 1.*/
            color: white;
            font-size: 30px;
            position: absolute;
            display: flex;
            width: 60px;
            height: 60px;
            border-radius: 300px;
            background-color: #181818;
            align-items: center;
            justify-content: center;
            left: 10%;
            background: none;
        }
        .profiles2 {   /*Kullanıcı pp 2.*/
            color: white;
            background: none;
            font-size: 30px;
            position: absolute;
            display: flex;
            width: 60px;
            height: 60px;
            border-radius: 300px;
            background-color: #181818;
            align-items: center;
            justify-content: center;
            left: 65%;
        }
        .call-container {   /*Alttaki dm, arama kapama butonlarının arkasındaki div.*/
            position: absolute;
            display: flex;
            width: 6%;
            left: 63%;
            transform: translateX(-50%);
            top: 370px;
            border-radius: 25px;
            height: 70px;
            justify-content: center;
            align-items: center;
            background-color: #141414;
            perspective: 1000px;
            border-style: solid;
            border-width: 1px;
            border-color: #242424;
        }
        .endcall {   /*Arama kapatma butonu.*/
            color: white;
            font-size: 30px;
            position: absolute;
            display: flex;
            width: 60px;
            height: 60px;
            border-radius: 300px;
            background-color: #d60000;
            align-items: center;
            justify-content: center;
            left: 13%;
            cursor: pointer;
            transition: box-shadow 0.7s ease, transform 0.7s ease;
            -webkit-tap-highlight-color: transparent;
        }
        .endcall:hover {   /*Arama kapatma butonu mouse üstüne getirince.*/
            box-shadow: 0px 0px 20px 10px rgba(180, 0, 0, 0.7);
            transform: rotateZ(100deg);
        }
        .return-dm {   /*Dm ye geri dönme butonu.*/
            color: white;
            font-size: 30px;
            position: absolute;
            display: flex;
            width: 60px;
            height: 60px;
            border-radius: 300px;
            background-color: #202020;
            align-items: center;
            justify-content: center;
            left: 5%;
            cursor: pointer;
            transition: box-shadow 0.7s ease;
            -webkit-tap-highlight-color: transparent;
        }
        .return-dm:hover {   /*Dm butonu mouse üstüne getirince.*/
            box-shadow: 0px 0px 20px 10px rgba(32, 32, 32, 0.7);
        }
        .add-user {   /*Sesli sohbete başka kulanıcı ekleme butonu.*/
            color: white;
            font-size: 30px;
            position: absolute;
            display: flex;
            width: 60px;
            height: 60px;
            border-radius: 300px;
            background-color: #202020;
            align-items: center;
            justify-content: center;
            left: 39%;
            cursor: pointer;
            transition: box-shadow 0.7s ease;
            -webkit-tap-highlight-color: transparent;
        }
        .add-user:hover {   /*Sohbete kullanıcı ekleme butonu mouse üstüne getirince.*/
            box-shadow: 0px 0px 20px 10px rgba(32, 32, 32, 0.7);
        }
        .mic-container {   /*Alttaki mikrofon, ses arkasındaki div.*/
            position: absolute;
            display: flex;
            width: 21%;
            left: 35%;
            transform: translateX(-50%);
            top: 370px;
            border-radius: 25px;
            height: 70px;
            justify-content: center;
            align-items: center;
            background-color: #141414;
            perspective: 1000px;
            border-style: solid;
            border-width: 1px;
            border-color: #242424;
        }
        .mic {   /*Mikrofon butonu.*/
            color: white;
            font-size: 30px;
            position: absolute;
            display: flex;
            width: 60px;
            height: 60px;
            border-radius: 300px;
            background-color: #202020;
            align-items: center;
            justify-content: center;
            left: 60%;
            cursor: pointer;
            transition: box-shadow 0.7s ease;
            -webkit-tap-highlight-color: transparent;
        }
        .mic:hover {   /*Dm butonu mouse üstüne getirince.*/
            box-shadow: 0px 0px 20px 10px rgba(32, 32, 32, 0.7);
            background-color: red;
        }
        .speaker {   /*Ses butonu.*/
            color: white;
            font-size: 30px;
            position: absolute;
            display: flex;
            width: 60px;
            height: 60px;
            border-radius: 300px;
            background-color: #202020;
            align-items: center;
            justify-content: center;
            left: 8%;
            cursor: pointer;
            transition: box-shadow 0.7s ease;
            -webkit-tap-highlight-color: transparent;
        }
        .speaker:hover {   /*Dm butonu mouse üstüne getirince.*/
            box-shadow: 0px 0px 20px 10px rgba(32, 32, 32, 0.7);
            background-color: red;
        }
    </style>
</head>
<body>
    <div class="background"> <!-- Ana koyu arkaplan. -->
    
             <div class="profile-container"> <!-- Üstteki kullanıcı cebi. -->
                 <div class="profiles1"> <!-- Kullanıcı profili. -->
                  <i data-lucide="circle-user" style="display: flex; width: 100%; height: 100%;"></i>
                    </div>
                 <div class="profiles2"> <!-- Kullanıcı profili. -->
                  <i data-lucide="circle-user" style="display: flex; width: 100%; height: 100%;"></i>
                    </div>
             </div>
             
             
          <div class="call-container"> <!-- Alttaki arama cebi. -->
       
            <div class="endcall"> <!-- Arama bitirme butonu. -->
                <i data-lucide="phone-off" style="display: flex; width: 80%; transform: scaleX(-1); height: 80%; color:#ffffff; stroke-width: 1.5px"></i>
                 </div>
         </div>
        <div class="mic-container">
            <div class="mic"> <!-- Mikrofon butonu. -->
                <i data-lucide="mic" style="display: flex; width: 80%; transform: scaleX(-1); height: 80%; color:#ffffff; stroke-width: 1.8px"></i>
                 </div>
            <div class="speaker"> <!-- Ses butonu. -->
                <i data-lucide="volume-2" style="display: flex; width: 80%; height: 80%; color:#ffffff; stroke-width: 1.8px"></i>
                 </div>
        </div>    
    </div>
    <script>
        lucide.createIcons();
    </script>
</body>
