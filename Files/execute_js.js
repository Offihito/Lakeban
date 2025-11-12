const { VM } = require('vm2');

process.stdin.setEncoding('utf8');

let data = '';
process.stdin.on('data', (chunk) => {
    data += chunk;
});

process.stdin.on('end', () => {
    try {
        const input = JSON.parse(data);
        const { script, command, args, server_id, channel_id, sender_id, sender_username, json_data } = input;

        // JSON verisini parse et
        let botData = JSON.parse(json_data || '{}');

        // Sandbox oluştur
        const vm = new VM({
            timeout: 1000, // 1 saniye zaman aşımı
            sandbox: {
                handleCommand: null,
                db: {
                    getData: () => botData,
                    setData: (newData) => {
                        botData = newData; // Yeni veriyi güncelle
                        return true;
                    }
                }
            },
            eval: false,
            wasm: false
        });

        // Scripti çalıştır
        vm.run(script);

        // handleCommand fonksiyonunu çağır
        if (typeof vm.sandbox.handleCommand === 'function') {
            const result = vm.sandbox.handleCommand(command, args, vm.sandbox.db, server_id, channel_id, sender_id, sender_username);
            // Güncellenen JSON verisini de döndür
            console.log(JSON.stringify({
                ...result,
                updated_json_data: JSON.stringify(botData)
            }));
        } else {
            console.error(JSON.stringify({ error: 'handleCommand fonksiyonu tanımlı değil' }));
        }
    } catch (e) {
        console.error(JSON.stringify({ error: e.message }));
    }
});