const fetch = require('node-fetch');

// Variáveis de Ambiente (com fallbacks do código PHP original)
const NITRO_API_URL = process.env.NITRO_API_URL || 'https://api.nitropagamentos.com/api/public/v1/transactions';
const NITRO_API_TOKEN = process.env.NITRO_API_TOKEN || 'AP4LznUeVh1dgR6kLuqYfeiz9bMgybCOiEOBLqjQutBjlqfa1DNXARyXdHqL';
const NITRO_OFFER_HASH = process.env.NITRO_OFFER_HASH || 'tnvei3gut8';
const XTRACKY_API_URL = process.env.XTRACKY_API_URL || 'https://api.xtracky.com/api/integrations/api';

// Função de Log simplificada para Vercel (usa console.log)
function gravarLog(mensagem, dados = null) {
    const timestamp = new Date().toISOString();
    let logMessage = `[${timestamp}] ${mensagem}`;
    
    if (dados !== null) {
        logMessage += '\n' + JSON.stringify(dados, null, 2);
    }
    
    console.log(logMessage);
}

// Função para enviar evento para XTracky
async function enviarEventoXTracky(orderId, amount, status, utmSource = '') {
    const data = {
        orderId: String(orderId),
        amount: Number(amount),
        status: status,
        utm_source: utmSource
    };
    
    gravarLog("=== ENVIANDO PARA XTRACKY ===", { url: XTRACKY_API_URL, data });
    
    try {
        const response = await fetch(XTRACKY_API_URL, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify(data)
        });
        
        const httpCode = response.status;
        const responseText = await response.text();
        
        gravarLog("Status HTTP XTracky: " + httpCode);
        gravarLog("Resposta XTracky: " + (responseText || 'vazia'));
        
        return httpCode >= 200 && httpCode < 300;
    } catch (error) {
        gravarLog("Erro ao enviar para XTracky: " + error.message);
        return false;
    }
}

// Função para gerar QR Code em Base64
async function gerarQRCodeBase64(pixCode) {
    const size = '300x300';
    const url = `https://chart.googleapis.com/chart?cht=qr&chs=${size}&chl=${encodeURIComponent(pixCode)}`;
    
    try {
        let response = await fetch(url);
        let imageData = await response.buffer();
        
        if (response.status === 200 && imageData.length > 0) {
            return 'data:image/png;base64,' + imageData.toString('base64');
        }
        
        // Tenta o segundo serviço se o primeiro falhar
        const url2 = `https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=${encodeURIComponent(pixCode)}`;
        response = await fetch(url2);
        imageData = await response.buffer();
        
        if (response.status === 200 && imageData.length > 0) {
            return 'data:image/png;base64,' + imageData.toString('base64');
        }
        
        return '';
    } catch (error) {
        gravarLog("Erro ao gerar QR Code: " + error.message);
        return '';
    }
}

// Função para validar CPF (tradução da lógica PHP)
function validarCPF(cpf) {
    cpf = cpf.replace(/[^\d]/g, '');
    if (cpf.length !== 11 || /^(\d)\1{10}$/.test(cpf)) return false;
    
    let sum = 0;
    let remainder;
    
    for (let i = 1; i <= 9; i++) sum = sum + parseInt(cpf.substring(i - 1, i)) * (11 - i);
    remainder = (sum * 10) % 11;
    
    if ((remainder === 10) || (remainder === 11)) remainder = 0;
    if (remainder !== parseInt(cpf.substring(9, 10))) return false;
    
    sum = 0;
    for (let i = 1; i <= 10; i++) sum = sum + parseInt(cpf.substring(i - 1, i)) * (12 - i);
    remainder = (sum * 10) % 11;
    
    if ((remainder === 10) || (remainder === 11)) remainder = 0;
    if (remainder !== parseInt(cpf.substring(10, 11))) return false;
    
    return true;
}

// Função para gerar CPF válido (tradução da lógica PHP)
function gerarCPF() {
    const rand = () => Math.floor(Math.random() * 10);
    let cpf = Array.from({ length: 9 }, rand).join('');
    
    const calculateDigit = (baseCpf) => {
        let sum = 0;
        let multiplier = baseCpf.length + 1;
        for (let i = 0; i < baseCpf.length; i++) {
            sum += parseInt(baseCpf[i]) * multiplier--;
        }
        let remainder = sum % 11;
        let digit = remainder < 2 ? 0 : 11 - remainder;
        return digit;
    };
    
    let d1 = calculateDigit(cpf);
    cpf += d1;
    let d2 = calculateDigit(cpf);
    cpf += d2;
    
    return cpf;
}

// Mapeamento de status da Nitro para o formato esperado
const statusMap = {
    'waiting_payment': 'PENDING',
    'pending': 'PENDING',
    'paid': 'APPROVED',
    'refunded': 'REFUNDED',
    'canceled': 'REJECTED',
    'refused': 'REJECTED'
};

// Handler principal da Vercel
module.exports = async (req, res) => {
    // Configura headers CORS
    res.setHeader('Access-Control-Allow-Origin', '*');
    res.setHeader('Access-Control-Allow-Methods', 'POST, GET, OPTIONS');
    res.setHeader('Access-Control-Allow-Headers', 'Content-Type');
    res.setHeader('Content-Type', 'application/json; charset=utf-8');

    // Resposta para OPTIONS (preflight)
    if (req.method === 'OPTIONS') {
        return res.status(200).end();
    }

    // Lógica para GET (Verificar Status)
    if (req.method === 'GET') {
        let transactionHash = req.query.transactionHash;
        
        // Tenta extrair hash da URL (simulando PATH_INFO do PHP)
        // Na Vercel, a rota é /api/production/payments.js. Se o usuário fizer /api/production/payments.js/HASH,
        // o hash pode estar em req.url, mas para simplificar, vamos focar no query param 'transactionHash'
        
        if (!transactionHash) {
            return res.status(200).json({
                error: 'transactionHash é obrigatório',
                success: false,
                status: 'PENDING'
            });
        }
        
        const url = `${NITRO_API_URL}/${encodeURIComponent(transactionHash)}?api_token=${NITRO_API_TOKEN}`;
        
        try {
            const response = await fetch(url, {
                method: 'GET',
                headers: { 'Accept': 'application/json' }
            });
            
            const httpCode = response.status;
            const responseData = await response.json();
            
            if (httpCode !== 200) {
                return res.status(200).json({
                    error: 'Erro ao verificar status',
                    success: false,
                    status: 'PENDING'
                });
            }
            
            const nitroStatus = responseData.payment_status || responseData.status || 'pending';
            const status = statusMap[nitroStatus] || 'PENDING';
            
            // Lógica XTracky para PIX PAGO
            if (nitroStatus === 'paid' && responseData.amount) {
                let utmSource = req.query.utm_source || '';
                
                // O código PHP tentava pegar o utm_source do HTTP_REFERER, o que é complexo em serverless.
                // Vamos priorizar o query param e confiar que o cliente o enviará se necessário.
                
                gravarLog("🚀 Preparando envio: PIX PAGO", { transactionHash, amount: responseData.amount, utmSource });
                
                const xTrackyResult = await enviarEventoXTracky(transactionHash, responseData.amount, 'paid', utmSource);
                
                if (xTrackyResult) {
                    gravarLog(`✅ XTracky PIX Pago: ENVIADO com sucesso - Hash: ${transactionHash}`);
                } else {
                    gravarLog(`❌ XTracky PIX Pago: FALHOU - Hash: ${transactionHash}`);
                }
            }
            
            return res.status(200).json({
                success: true,
                status: status,
                transactionHash: transactionHash,
                paidAt: responseData.paid_at || null
            });
            
        } catch (error) {
            gravarLog("Erro na requisição GET para Nitro: " + error.message);
            return res.status(200).json({
                error: 'Erro interno ao verificar status',
                success: false,
                status: 'PENDING'
            });
        }
    }

    // Lógica para POST (Gerar PIX)
    if (req.method === 'POST') {
        try {
            const data = req.body;
            
            if (!data || !data.value || !data.payerName || !data.productName) {
                throw new Error('Campos obrigatórios: value, payerName, productName');
            }
            
            const amountInCents = Math.round(data.value * 100);
            
            if (amountInCents < 100) {
                throw new Error('Valor mínimo de R$ 1,00');
            }
            
            // Processa CPF
            let document = '';
            if (data.document) {
                document = data.document.replace(/[^\d]/g, '');
                if (!validarCPF(document)) {
                    document = gerarCPF();
                }
            } else {
                document = gerarCPF();
            }
            
            // Processa email
            let email = data.email || `cliente_${Date.now()}@notciiastopshoje.shop`;
            
            // Processa telefone
            let phone = data.phone ? data.phone.replace(/[^\d]/g, '') : '11999999999';
            
            // Captura utm_source
            let utmSource = data.utm_source || (data.utm && data.utm.source) || '';
            
            gravarLog("🔍 UTM Source capturado para evento PIX GERADO: " + (utmSource || 'VAZIO'));
            
            // Gera hash único do produto
            const productHash = require('crypto').createHash('md5').update(data.productName + Date.now()).digest('hex').substring(0, 10);
            
            // Captura offer_hash
            const offerHash = data.offer_hash || NITRO_OFFER_HASH;
            
            if (offerHash === 'SEU_OFFER_HASH_AQUI' || !offerHash) {
                throw new Error('offer_hash não configurado. Configure NITRO_OFFER_HASH ou envie no payload.');
            }
            
            // Processa número de parcelas
            const installments = (data.installments && !isNaN(data.installments)) ? parseInt(data.installments) : 1;
            
            // Monta payload para Nitro API
            const payload = {
                amount: amountInCents,
                offer_hash: offerHash,
                payment_method: 'pix',
                installments: installments,
                customer: {
                    name: data.payerName,
                    email: email,
                    phone_number: phone,
                    document: document,
                    street_name: 'Rua Principal',
                    number: '100',
                    neighborhood: 'Centro',
                    city: 'São Paulo',
                    state: 'SP',
                    zip_code: '01000000'
                },
                cart: [{
                    product_hash: productHash,
                    title: data.productName,
                    cover: null,
                    price: amountInCents,
                    quantity: 1,
                    operation_type: 1,
                    tangible: false
                }],
                expire_in_days: 1,
                transaction_origin: 'api',
                tracking: {
                    src: '',
                    utm_source: utmSource || '',
                    utm_medium: '',
                    utm_campaign: '',
                    utm_term: '',
                    utm_content: ''
                }
            };
            
            // Faz requisição para API Nitro
            const url = `${NITRO_API_URL}?api_token=${NITRO_API_TOKEN}`;
            
            gravarLog('REQUISIÇÃO ENVIADA PARA NITRO', { url, payload });
            
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify(payload)
            });
            
            const httpCode = response.status;
            const responseText = await response.text();
            let apiResponse;
            
            try {
                apiResponse = JSON.parse(responseText);
            } catch (e) {
                gravarLog('Resposta inválida da API (não é JSON)', { responseText });
                throw new Error('Resposta inválida da API');
            }
            
            gravarLog('RESPOSTA RECEBIDA DA NITRO', { httpCode, apiResponse });
            
            if (httpCode !== 200 && httpCode !== 201) {
                const errorMsg = apiResponse.message || 'Erro ao processar pagamento';
                gravarLog('ERRO NA API NITRO', { message: errorMsg, httpCode });
                throw new Error(errorMsg);
            }
            
            // Extrai dados do PIX da resposta Nitro
            let pixCode = '';
            let pixQrCode = '';
            
            const pixData = apiResponse.pix || apiResponse;
            
            if (pixData.pix_qr_code) {
                pixCode = pixData.pix_qr_code;
            } else if (pixData.qr_code) {
                pixCode = pixData.qr_code;
            } else if (pixData.emv) {
                pixCode = pixData.emv;
            } else if (pixData.code) {
                pixCode = pixData.code;
            }
            
            if (pixData.qr_code_base64) {
                pixQrCode = pixData.qr_code_base64;
            } else if (pixData.pix_qr_code_base64) {
                pixQrCode = pixData.pix_qr_code_base64;
            } else if (pixData.qr_code_image) {
                pixQrCode = pixData.qr_code_image;
            } else if (pixData.image) {
                pixQrCode = pixData.image;
            }
            
            const transactionHash = apiResponse.hash || '';
            const status = apiResponse.payment_status || 'pending';
            
            // Gera o QR Code em base64
            let qrCodeBase64 = '';
            if (pixCode) {
                if (pixQrCode) {
                    qrCodeBase64 = pixQrCode.startsWith('data:image') ? pixQrCode : `data:image/png;base64,${pixQrCode}`;
                } else {
                    qrCodeBase64 = await gerarQRCodeBase64(pixCode);
                }
            }
            
            // 🎯 EVENTO 1: Envia para XTracky - PIX GERADO (waiting_payment)
            if (transactionHash) {
                gravarLog("🚀 Preparando envio: PIX GERADO", { transactionHash, amount: amountInCents, utmSource });
                
                const xTrackyResult = await enviarEventoXTracky(transactionHash, amountInCents, 'waiting_payment', utmSource);
                
                if (xTrackyResult) {
                    gravarLog(`✅ XTracky PIX Gerado: ENVIADO com sucesso - Hash: ${transactionHash}`);
                } else {
                    gravarLog(`❌ XTracky PIX Gerado: FALHOU - Hash: ${transactionHash}`);
                }
            }
            
            const mappedStatus = statusMap[status] || 'PENDING';
            
            // Converte a resposta para o formato esperado
            const finalResponse = {
                success: true,
                paymentInfo: {
                    id: transactionHash,
                    qrCode: pixCode,
                    base64QrCode: qrCodeBase64,
                    status: mappedStatus,
                    transactionId: transactionHash
                },
                value: data.value,
                pixCode: pixCode,
                transactionId: transactionHash,
                status: mappedStatus,
                expirationDate: pixData.expiration_date || null
            };
            
            return res.status(200).json(finalResponse);
            
        } catch (e) {
            gravarLog("Erro no processamento POST: " + e.message);
            return res.status(200).json({
                error: e.message,
                success: false
            });
        }
    }

    // Método não permitido
    res.status(405).json({ error: 'Método não permitido. Use POST ou GET.', success: false });
};
