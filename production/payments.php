<?php
// Limpa qualquer output anterior
ob_clean();

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

ini_set('display_errors', 0);
error_reporting(E_ALL);

// Função para gravar log em arquivo
function gravarLog($mensagem, $dados = null) {
    $logFile = __DIR__ . '/nitro_debug.txt';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[{$timestamp}] {$mensagem}\n";
    
    if ($dados !== null) {
        $logMessage .= json_encode($dados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }
    
    $logMessage .= str_repeat('-', 80) . "\n";
    
    file_put_contents($logFile, $logMessage, FILE_APPEND);
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

define('NITRO_API_URL', getenv('NITRO_API_URL') ?: 'https://api.nitropagamentos.com/api/public/v1/transactions');
define('NITRO_API_TOKEN', getenv('NITRO_API_TOKEN') ?: 'AP4LznUeVh1dgR6kLuqYfeiz9bMgybCOiEOBLqjQutBjlqfa1DNXARyXdHqL');
define('NITRO_OFFER_HASH', getenv('NITRO_OFFER_HASH') ?: 'tnvei3gut8'); 

define('XTRACKY_API_URL', getenv('XTRACKY_API_URL') ?: 'https://api.xtracky.com/api/integrations/api');

function enviarEventoXTracky($orderId, $amount, $status, $utmSource = '') {
    $data = [
        'orderId' => (string)$orderId,
        'amount' => (int)$amount,
        'status' => $status,
        'utm_source' => $utmSource
    ];
    
    $jsonData = json_encode($data);
    
    // Log dos dados sendo enviados
    error_log("=== ENVIANDO PARA XTRACKY ===");
    error_log("URL: " . XTRACKY_API_URL);
    error_log("Dados: " . $jsonData);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => XTRACKY_API_URL,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $jsonData,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Content-Length: ' . strlen($jsonData)
        ]
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    $curlErrno = curl_errno($ch);
    curl_close($ch);
    
    // Log da resposta
    error_log("Status HTTP: " . $httpCode);
    error_log("Resposta: " . ($response ?: 'vazia'));
    if ($curlErrno !== 0) {
        error_log("Erro CURL: [{$curlErrno}] {$curlError}");
    }
    error_log("=== FIM XTRACKY ===");
    
    return $httpCode >= 200 && $httpCode < 300;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $transactionHash = '';
    
    if (isset($_SERVER['PATH_INFO'])) {
        $transactionHash = trim($_SERVER['PATH_INFO'], '/');
    }
    
    if (empty($transactionHash) && isset($_GET['transactionHash'])) {
        $transactionHash = $_GET['transactionHash'];
    }
    
    if (empty($transactionHash)) {
        http_response_code(200);
        echo json_encode([
            'error' => 'transactionHash é obrigatório',
            'success' => false,
            'status' => 'PENDING'
        ], JSON_UNESCAPED_UNICODE);
        exit(0);
    }
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => NITRO_API_URL . '/' . urlencode($transactionHash) . '?api_token=' . NITRO_API_TOKEN,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json'
        ]
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if (empty($response) || $httpCode !== 200) {
        http_response_code(200);
        echo json_encode([
            'error' => 'Erro ao verificar status',
            'success' => false,
            'status' => 'PENDING'
        ], JSON_UNESCAPED_UNICODE);
        exit(0);
    }
    
    $data = json_decode($response, true);
    
    $statusMap = [
        'waiting_payment' => 'PENDING',
        'pending' => 'PENDING',
        'paid' => 'APPROVED',
        'refunded' => 'REFUNDED',
        'canceled' => 'REJECTED',
        'refused' => 'REJECTED'
    ];
    
    $nitroStatus = $data['payment_status'] ?? $data['status'] ?? 'pending';
    $status = $statusMap[$nitroStatus] ?? 'PENDING';
    
    if ($nitroStatus === 'paid' && isset($data['amount'])) {
        $utmSource = isset($_GET['utm_source']) ? $_GET['utm_source'] : '';
        
        if (empty($utmSource) && isset($_SERVER['HTTP_REFERER'])) {
            parse_str(parse_url($_SERVER['HTTP_REFERER'], PHP_URL_QUERY), $queryParams);
            if (isset($queryParams['utm_source'])) {
                $utmSource = $queryParams['utm_source'];
            }
        }
        
        error_log("🚀 Preparando envio: PIX PAGO");
        error_log("   TransactionHash: {$transactionHash}");
        error_log("   Amount: {$data['amount']}");
        error_log("   UTM Source: " . ($utmSource ?: 'VAZIO'));
        
        $xTrackyResult = enviarEventoXTracky($transactionHash, $data['amount'], 'paid', $utmSource);
        
        if ($xTrackyResult) {
            error_log("✅ XTracky PIX Pago: ENVIADO com sucesso - Hash: {$transactionHash}");
        } else {
            error_log("❌ XTracky PIX Pago: FALHOU - Hash: {$transactionHash}");
        }
    }
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'status' => $status,
        'transactionHash' => $transactionHash,
        'paidAt' => $data['paid_at'] ?? null
    ], JSON_UNESCAPED_UNICODE);
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido. Use POST ou GET.', 'success' => false]);
    exit(0);
}

function gerarQRCodeBase64($pixCode) {
    $size = '300x300';
    $url = 'https://chart.googleapis.com/chart?cht=qr&chs=' . $size . '&chl=' . urlencode($pixCode);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    
    $imageData = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200 && !empty($imageData)) {
        return 'data:image/png;base64,' . base64_encode($imageData);
    }
    
    $url2 = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($pixCode);
    
    $ch2 = curl_init();
    curl_setopt_array($ch2, [
        CURLOPT_URL => $url2,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    
    $imageData2 = curl_exec($ch2);
    $httpCode2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    curl_close($ch2);
    
    if ($httpCode2 === 200 && !empty($imageData2)) {
        return 'data:image/png;base64,' . base64_encode($imageData2);
    }
    
    return '';
}

function validarCPF($cpf) {
    $cpf = preg_replace('/[^0-9]/', '', $cpf);
    if (strlen($cpf) != 11 || preg_match('/(\d)\1{10}/', $cpf)) return false;
    for ($t = 9; $t < 11; $t++) {
        $d = 0;
        for ($c = 0; $c < $t; $c++) {
            $d += $cpf[$c] * (($t + 1) - $c);
        }
        $d = ((10 * $d) % 11) % 10;
        if ($cpf[$c] != $d) return false;
    }
    return true;
}

// Função para gerar CPF válido
function gerarCPF() {
    $n1 = rand(0, 9);
    $n2 = rand(0, 9);
    $n3 = rand(0, 9);
    $n4 = rand(0, 9);
    $n5 = rand(0, 9);
    $n6 = rand(0, 9);
    $n7 = rand(0, 9);
    $n8 = rand(0, 9);
    $n9 = rand(0, 9);
    
    $d1 = $n9 * 2 + $n8 * 3 + $n7 * 4 + $n6 * 5 + $n5 * 6 + $n4 * 7 + $n3 * 8 + $n2 * 9 + $n1 * 10;
    $d1 = 11 - ($d1 % 11);
    if ($d1 >= 10) $d1 = 0;
    
    $d2 = $d1 * 2 + $n9 * 3 + $n8 * 4 + $n7 * 5 + $n6 * 6 + $n5 * 7 + $n4 * 8 + $n3 * 9 + $n2 * 10 + $n1 * 11;
    $d2 = 11 - ($d2 % 11);
    if ($d2 >= 10) $d2 = 0;
    
    return "$n1$n2$n3$n4$n5$n6$n7$n8$n9$d1$d2";
}

try {
    $rawInput = file_get_contents('php://input');
    
    if (empty($rawInput)) {
        throw new Exception('Nenhum dado recebido');
    }
    
    $data = json_decode($rawInput, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('JSON inválido');
    }
    
    if (!isset($data['value']) || !isset($data['payerName']) || !isset($data['productName'])) {
        throw new Exception('Campos obrigatórios: value, payerName, productName');
    }
    
    $amountInCents = (int)round($data['value'] * 100);
    
    if ($amountInCents < 100) {
        throw new Exception('Valor mínimo de R$ 1,00');
    }
    
    // Processa CPF
    $document = '';
    if (isset($data['document']) && !empty($data['document'])) {
        $document = preg_replace('/[^0-9]/', '', $data['document']);
        if (!validarCPF($document)) {
            $document = gerarCPF();
        }
    } else {
        $document = gerarCPF();
    }
    
    // Processa email
    $email = '';
    if (isset($data['email']) && !empty($data['email'])) {
        $email = filter_var($data['email'], FILTER_VALIDATE_EMAIL);
        if ($email === false) {
            $email = 'cliente_' . uniqid() . '@notciiastopshoje.shop';
        }
    } else {
        $email = 'cliente_' . uniqid() . '@notciiastopshoje.shop';
    }
    
    // Processa telefone
    $phone = '';
    if (isset($data['phone'])) {
        $phone = preg_replace('/[^0-9]/', '', $data['phone']);
    }
    if (empty($phone)) {
        $phone = '11999999999';
    }
    
    // Captura utm_source de múltiplas fontes
    $utmSource = '';
    
    // Prioridade 1: Do payload enviado
    if (isset($data['utm_source']) && !empty($data['utm_source'])) {
        $utmSource = $data['utm_source'];
    }
    // Prioridade 2: Do objeto utm
    elseif (isset($data['utm']['source']) && !empty($data['utm']['source'])) {
        $utmSource = $data['utm']['source'];
    }
    // Prioridade 3: Da URL (se disponível via Referer ou Header)
    elseif (isset($_SERVER['HTTP_REFERER'])) {
        parse_str(parse_url($_SERVER['HTTP_REFERER'], PHP_URL_QUERY), $queryParams);
        if (isset($queryParams['utm_source'])) {
            $utmSource = $queryParams['utm_source'];
        }
    }
    
    error_log("🔍 UTM Source capturado para evento PIX GERADO: " . ($utmSource ?: 'VAZIO'));
    
    // Gera hash único do produto
    $productHash = substr(md5($data['productName'] . time()), 0, 10);
    
    // Captura offer_hash (aceita do payload ou usa o padrão da config)
    $offerHash = NITRO_OFFER_HASH;
    if (isset($data['offer_hash']) && !empty($data['offer_hash'])) {
        $offerHash = $data['offer_hash'];
    }
    
    // Valida se offer_hash foi configurado
    if ($offerHash === 'SEU_OFFER_HASH_AQUI' || empty($offerHash)) {
        throw new Exception('offer_hash não configurado. Configure NITRO_OFFER_HASH no código ou envie no payload.');
    }
    
    // Processa número de parcelas (PIX sempre é à vista = 1)
    $installments = 1;
    if (isset($data['installments']) && is_numeric($data['installments'])) {
        $installments = (int)$data['installments'];
    }
    
    // Monta payload para Nitro API
    $payload = [
        'amount' => $amountInCents,
        'offer_hash' => $offerHash,
        'payment_method' => 'pix',
        'installments' => $installments,
        'customer' => [
            'name' => $data['payerName'],
            'email' => $email,
            'phone_number' => $phone,
            'document' => $document,
            'street_name' => 'Rua Principal',
            'number' => '100',
            'neighborhood' => 'Centro',
            'city' => 'São Paulo',
            'state' => 'SP',
            'zip_code' => '01000000'
        ],
        'cart' => [
            [
                'product_hash' => $productHash,
                'title' => $data['productName'],
                'cover' => null,
                'price' => $amountInCents,
                'quantity' => 1,
                'operation_type' => 1,
                'tangible' => false
            ]
        ],
        'expire_in_days' => 1,
        'transaction_origin' => 'api',
        'tracking' => [
            'src' => '',
            'utm_source' => $utmSource ?: '',
            'utm_medium' => '',
            'utm_campaign' => '',
            'utm_term' => '',
            'utm_content' => ''
        ]
    ];
    
    // Faz requisição para API Nitro
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => NITRO_API_URL . '?api_token=' . NITRO_API_TOKEN,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json'
        ]
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    $curlErrno = curl_errno($ch);
    curl_close($ch);
    
    // 📝 GRAVA LOG DA REQUISIÇÃO
    gravarLog('REQUISIÇÃO ENVIADA PARA NITRO', [
        'url' => NITRO_API_URL . '?api_token=' . NITRO_API_TOKEN,
        'payload' => $payload,
        'httpCode' => $httpCode,
        'curlError' => $curlError ?: 'nenhum'
    ]);
    
    // 📝 GRAVA LOG DA RESPOSTA
    gravarLog('RESPOSTA RECEBIDA DA NITRO', [
        'httpCode' => $httpCode,
        'responseRaw' => $response,
        'responseParsed' => json_decode($response, true)
    ]);
    
    if ($curlErrno !== 0) {
        throw new Exception("Erro na conexão: {$curlError}");
    }
    
    if (empty($response)) {
        throw new Exception('Resposta vazia da API');
    }
    
    $apiResponse = json_decode($response, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Resposta inválida da API');
    }
    
    if (!is_array($apiResponse)) {
        throw new Exception('Resposta inválida da API');
    }
    
    // 📝 LOG: Estrutura completa da resposta
    gravarLog('ESTRUTURA DA RESPOSTA', [
        'keys' => array_keys($apiResponse),
        'full_response' => $apiResponse
    ]);
    
    // Verifica se houve erro
    if ($httpCode !== 200 && $httpCode !== 201) {
        $errorMsg = isset($apiResponse['message']) ? $apiResponse['message'] : 'Erro ao processar pagamento';
        gravarLog('ERRO NA API NITRO', ['message' => $errorMsg, 'httpCode' => $httpCode]);
        throw new Exception($errorMsg);
    }
    
    // Extrai dados do PIX da resposta Nitro
    $pixCode = '';
    $pixQrCode = '';
    
    // A API Nitro retorna em: pix.pix_qr_code
    if (isset($apiResponse['pix']['pix_qr_code'])) {
        $pixCode = $apiResponse['pix']['pix_qr_code'];
        gravarLog('✅ PIX CODE encontrado em: pix.pix_qr_code');
    } elseif (isset($apiResponse['pix']['qr_code'])) {
        $pixCode = $apiResponse['pix']['qr_code'];
        gravarLog('PIX CODE encontrado em: pix.qr_code');
    } elseif (isset($apiResponse['pix']['emv'])) {
        $pixCode = $apiResponse['pix']['emv'];
        gravarLog('PIX CODE encontrado em: pix.emv');
    } elseif (isset($apiResponse['pix']['code'])) {
        $pixCode = $apiResponse['pix']['code'];
        gravarLog('PIX CODE encontrado em: pix.code');
    } elseif (isset($apiResponse['qr_code'])) {
        $pixCode = $apiResponse['qr_code'];
        gravarLog('PIX CODE encontrado em: qr_code');
    } elseif (isset($apiResponse['emv'])) {
        $pixCode = $apiResponse['emv'];
        gravarLog('PIX CODE encontrado em: emv');
    } elseif (isset($apiResponse['code'])) {
        $pixCode = $apiResponse['code'];
        gravarLog('PIX CODE encontrado em: code');
    }
    
    // Tenta pegar imagem do QR Code se disponível (base64)
    if (isset($apiResponse['pix']['qr_code_base64']) && !empty($apiResponse['pix']['qr_code_base64'])) {
        $pixQrCode = $apiResponse['pix']['qr_code_base64'];
        gravarLog('QR CODE IMAGE encontrado em: pix.qr_code_base64');
    } elseif (isset($apiResponse['pix']['pix_qr_code_base64']) && !empty($apiResponse['pix']['pix_qr_code_base64'])) {
        $pixQrCode = $apiResponse['pix']['pix_qr_code_base64'];
        gravarLog('QR CODE IMAGE encontrado em: pix.pix_qr_code_base64');
    } elseif (isset($apiResponse['pix']['qr_code_image'])) {
        $pixQrCode = $apiResponse['pix']['qr_code_image'];
        gravarLog('QR CODE IMAGE encontrado em: pix.qr_code_image');
    } elseif (isset($apiResponse['pix']['image'])) {
        $pixQrCode = $apiResponse['pix']['image'];
        gravarLog('QR CODE IMAGE encontrado em: pix.image');
    } elseif (isset($apiResponse['qr_code_image'])) {
        $pixQrCode = $apiResponse['qr_code_image'];
        gravarLog('QR CODE IMAGE encontrado em: qr_code_image');
    }
    
    $transactionHash = $apiResponse['hash'] ?? '';
    $status = $apiResponse['payment_status'] ?? 'pending';
    
    // Se não encontrou o código PIX, loga erro
    if (empty($pixCode)) {
        gravarLog('⚠️ ATENÇÃO: Código PIX NÃO ENCONTRADO', [
            'keys_disponiveis' => array_keys($apiResponse),
            'tem_objeto_pix' => isset($apiResponse['pix']) ? 'SIM' : 'NÃO',
            'keys_pix' => isset($apiResponse['pix']) ? array_keys($apiResponse['pix']) : []
        ]);
    } else {
        gravarLog('✅ Código PIX extraído com sucesso', ['pixCode' => substr($pixCode, 0, 50) . '...']);
    }
    
    // Gera o QR Code em base64 (se tiver o código PIX)
    $qrCodeBase64 = '';
    if (!empty($pixCode)) {
        // Se já veio imagem pronta da API, usa ela
        if (!empty($pixQrCode)) {
            // Se já for base64, usa direto
            if (strpos($pixQrCode, 'data:image') === 0) {
                $qrCodeBase64 = $pixQrCode;
            } else {
                $qrCodeBase64 = 'data:image/png;base64,' . $pixQrCode;
            }
        } else {
            // Gera QR Code usando API externa
            $qrCodeBase64 = gerarQRCodeBase64($pixCode);
        }
    }
    
    // 🎯 EVENTO 1: Envia para XTracky - PIX GERADO (pending)
    if (!empty($transactionHash)) {
        error_log("🚀 Preparando envio: PIX GERADO");
        error_log("   TransactionHash: {$transactionHash}");
        error_log("   Amount: {$amountInCents}");
        error_log("   UTM Source: " . ($utmSource ?: 'VAZIO'));
        
        $xTrackyResult = enviarEventoXTracky($transactionHash, $amountInCents, 'waiting_payment', $utmSource);
        
        if ($xTrackyResult) {
            error_log("✅ XTracky PIX Gerado: ENVIADO com sucesso - Hash: {$transactionHash}");
        } else {
            error_log("❌ XTracky PIX Gerado: FALHOU - Hash: {$transactionHash}");
        }
    }
    
    // Mapeia status da Nitro para o formato esperado
    $statusMap = [
        'waiting_payment' => 'PENDING',
        'pending' => 'PENDING',
        'paid' => 'APPROVED',
        'refunded' => 'REFUNDED',
        'canceled' => 'REJECTED',
        'refused' => 'REJECTED'
    ];
    
    $mappedStatus = $statusMap[$status] ?? 'PENDING';
    
    // Converte a resposta para o formato esperado
    $response = [
        'success' => true,
        'paymentInfo' => [
            'id' => $transactionHash,
            'qrCode' => $pixCode,
            'base64QrCode' => $qrCodeBase64,
            'status' => $mappedStatus,
            'transactionId' => $transactionHash
        ],
        'value' => $data['value'],
        'pixCode' => $pixCode,
        'transactionId' => $transactionHash,
        'status' => $mappedStatus,
        'expirationDate' => $apiResponse['pix']['expiration_date'] ?? null
    ];
    
    http_response_code(200);
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
} catch (Exception $e) {
    http_response_code(200);
    echo json_encode([
        'error' => $e->getMessage(),
        'success' => false
    ], JSON_UNESCAPED_UNICODE);
}

// Força o término do script
exit(0);
?>