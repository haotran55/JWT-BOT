<?php

// Bot configuration
define('BOT_TOKEN', '8550028771:AAG0fVTLwNl37ox1eoAnLm59jAv_Ll-Kkkc');
define('BOT_NAME', 'CỤ MẸ MÀY'); 
define('API_BASE_URLS', [
    'https://uditanshu-dev-fd.vercel.app/token?uid={Uid}&password={Password}',
]);
define('MAX_RETRIES', 10);
define('CONCURRENT_REQUESTS', 55);
define('TEMP_DIR', sys_get_temp_dir() . '/jwt_bot/');

// Ensure temp directory exists
if (!file_exists(TEMP_DIR)) {
    mkdir(TEMP_DIR, 0777, true);
}

// Simple lock file to prevent concurrent processing per user
function acquireLock($chat_id) {
    $lock_file = TEMP_DIR . "lock_$chat_id";
    if (file_exists($lock_file) && (time() - filemtime($lock_file)) < 300) {
        return false; 
    }
    file_put_contents($lock_file, time());
    return true;
}

function releaseLock($chat_id) {
    $lock_file = TEMP_DIR . "lock_$chat_id";
    if (file_exists($lock_file)) {
        unlink($lock_file);
    }
}

// Telegram API request function
function sendTelegramRequest($method, $params = []) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/$method";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = curl_exec($ch);
    curl_close($ch);
    return json_decode($result, true);
}

// Send message
function sendMessage($chat_id, $text, $reply_markup = null) {
    $params = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'Markdown',
    ];
    if ($reply_markup) {
        $params['reply_markup'] = json_encode($reply_markup);
    }
    return sendTelegramRequest('sendMessage', $params);
}

// Send document
function sendDocument($chat_id, $file_path, $caption = '') {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendDocument";
    $post_fields = [
        'chat_id' => $chat_id,
        'caption' => $caption,
        'document' => new CURLFile($file_path),
    ];
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = curl_exec($ch);
    curl_close($ch);
    return json_decode($result, true);
}

// Edit message
function editMessage($chat_id, $message_id, $text, $reply_markup = null) {
    $params = [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'text' => $text,
        'parse_mode' => 'Markdown',
    ];
    if ($reply_markup) {
        $params['reply_markup'] = json_encode($reply_markup);
    }
    return sendTelegramRequest('editMessageText', $params);
}

// Make API request to fetch JWT token
function fetchJwtToken($uid, $password, $api_url) {
    $url = str_replace(['{Uid}', '{Password}'], [urlencode($uid), urlencode($password)], $api_url);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['response' => $response, 'http_code' => $http_code];
}

// Process credentials with retries
function processCredential($credential, &$results, &$failed_count, &$invalid_count, &$failed_credentials) {
    $uid = $credential['uid'] ?? '';
    $password = $credential['password'] ?? '';
    if (empty($uid) || empty($password)) {
        $invalid_count++;
        $failed_credentials[] = ['uid' => $uid, 'password' => $password, 'reason' => 'Invalid: Missing UID or password'];
        return;
    }

    $attempts = 0;
    $success = false;
    while ($attempts < MAX_RETRIES && !$success) {
        $api_url = API_BASE_URLS[0];
        $result = fetchJwtToken($uid, $password, $api_url);
        $attempts++;

        if ($result['http_code'] == 200) {
            $data = json_decode($result['response'], true);
            if (isset($data['token'])) {
                $results[] = ['token' => $data['token']];
                $success = true;
            } else {
                $invalid_count++;
                $failed_credentials[] = ['uid' => $uid, 'password' => $password, 'reason' => 'Invalid: No token returned'];
                break;
            }
        } else {
            if ($attempts == MAX_RETRIES) {
                $failed_count++;
                $failed_credentials[] = ['uid' => $uid, 'password' => $password, 'reason' => 'Failed: Max retries reached'];
            }
        }
    }
}

// Handle incoming updates
$update = json_decode(file_get_contents('php://input'), true);
if ($update) {
    $chat_id = $update['message']['chat']['id'] ?? $update['callback_query']['message']['chat']['id'] ?? null;
    $message = $update['message'] ?? null;
    $callback_query = $update['callback_query'] ?? null;
    $user = $update['message']['from'] ?? $update['callback_query']['from'] ?? null;
    $username = $user['username'] ?? $user['first_name'] ?? 'User';

    if (!$chat_id) exit;

    $state_file = TEMP_DIR . "state_$chat_id.json";
    $user_state = file_exists($state_file) ? json_decode(file_get_contents($state_file), true) : [];

    // Handle /start command (Directly shows instructions)
    if ($message && isset($message['text']) && $message['text'] == '/start') {
        $info_text = "👋 *Chào $username!* Chào mừng bạn đến với *" . BOT_NAME . "*! 🚀\n\n" .
                     "Vui lòng gửi file JSON chứa thông tin tài khoản theo định dạng sau:\n\n" .
                     "```json\n" .
                     "[\n  {\"uid\": \"123\", \"password\": \"abc\"},\n  {\"uid\": \"456\", \"password\": \"def\"}\n]\n" .
                     "```\n\n" .
                     "⚡ Bot xử lý đồng thời 55 tài khoản.\n" .
                     "🔄 Tự động thử lại lên đến 10 lần.";
        sendMessage($chat_id, $info_text);
    }

    // Handle callback query
    if ($callback_query) {
        $message_id = $callback_query['message']['message_id'];
        $data = $callback_query['data'];

        if ($data == 'custom_generate') {
            $user_state['awaiting_custom_count'] = true;
            file_put_contents($state_file, json_encode($user_state));
            sendMessage($chat_id, "🔢 *Bạn muốn xử lý bao nhiêu tài khoản, $username?*");
        } elseif ($data == 'all_generate') {
            if (isset($user_state['credentials'])) {
                processCredentials($chat_id, $message_id, $username, $user_state['credentials'], count($user_state['credentials']), $user_state['local_file']);
            }
        }
    }

    // Handle custom count input
    if ($message && isset($message['text']) && !empty($user_state['awaiting_custom_count'])) {
        $count = intval($message['text']);
        if ($count > 0 && isset($user_state['credentials'])) {
            $user_state['awaiting_custom_count'] = false;
            file_put_contents($state_file, json_encode($user_state));
            processCredentials($chat_id, $message['message_id'], $username, array_slice($user_state['credentials'], 0, $count), $count, $user_state['local_file']);
        }
    }

    // Handle JSON file upload
    if ($message && isset($message['document']) && $message['document']['mime_type'] == 'application/json') {
        if (!acquireLock($chat_id)) {
            sendMessage($chat_id, "⏳ Đang xử lý yêu cầu trước, vui lòng đợi...");
            exit;
        }

        $file_id = $message['document']['file_id'];
        $file = sendTelegramRequest('getFile', ['file_id' => $file_id]);
        $file_url = "https://api.telegram.org/file/bot" . BOT_TOKEN . "/" . $file['result']['file_path'];
        $local_file = TEMP_DIR . "input_" . $chat_id . "_" . time() . ".json";
        file_put_contents($local_file, file_get_contents($file_url));

        $credentials = json_decode(file_get_contents($local_file), true);
        if (is_array($credentials)) {
            $user_state['credentials'] = $credentials;
            $user_state['local_file'] = $local_file;
            file_put_contents($state_file, json_encode($user_state));

            sendMessage($chat_id, "✅ Đã nhận " . count($credentials) . " tài khoản. Chọn chế độ:", [
                'inline_keyboard' => [[
                    ['text' => 'Số lượng tùy chỉnh', 'callback_data' => 'custom_generate'],
                    ['text' => 'Xử lý tất cả', 'callback_data' => 'all_generate'],
                ]]
            ]);
        }
        releaseLock($chat_id);
    }
}

function getProgressBar($progress) {
    $bars = [10=>'▰▱▱▱▱', 40=>'▰▰▰▱▱', 70=>'▰▰▰▰▱', 100=>'▰▰▰▰▰'];
    $progress = min(100, max(10, round($progress / 30) * 30 + 10));
    return $bars[$progress] ?? '▰▰▰▰▰';
}

function processCredentials($chat_id, $message_id, $username, $credentials, $total_count, $local_file) {
    $start_time = microtime(true);
    $results = []; $failed_count = 0; $invalid_count = 0; $failed_credentials = [];

    $msg = sendMessage($chat_id, "⏳ Đang xử lý $total_count tài khoản...");
    $bar_msg = sendMessage($chat_id, "▱▱▱▱▱ 0%");

    $chunks = array_chunk($credentials, CONCURRENT_REQUESTS);
    $total_processed = 0;

    foreach ($chunks as $chunk) {
        $mh = curl_multi_init();
        $handles = [];
        foreach ($chunk as $cred) {
            $ch = curl_init();
            $url = str_replace(['{Uid}', '{Password}'], [urlencode($cred['uid']??''), urlencode($cred['password']??'')], API_BASE_URLS[0]);
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_multi_add_handle($mh, $ch);
            $handles[] = $ch;
        }
        do { curl_multi_exec($mh, $running); } while ($running);

        foreach ($handles as $index => $ch) {
            $resp = curl_multi_getcontent($ch);
            $data = json_decode($resp, true);
            if (curl_getinfo($ch, CURLINFO_HTTP_CODE) == 200 && isset($data['token'])) {
                $results[] = ['token' => $data['token']];
            } else {
                $failed_count++;
            }
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        
        $total_processed += count($chunk);
        editMessage($chat_id, $bar_msg['result']['message_id'], getProgressBar(($total_processed/$total_count)*100));
    }

    $output_file = TEMP_DIR . "results_$chat_id.json";
    file_put_contents($output_file, json_encode($results, JSON_PRETTY_PRINT));
    
    $summary = "✅ *Hoàn tất!*\n- Thành công: " . count($results) . "\n- Thất bại: $failed_count";
    sendMessage($chat_id, $summary);
    sendDocument($chat_id, $output_file, "Kết quả JWT của bạn");
    
    unlink($output_file);
    releaseLock($chat_id);
}
