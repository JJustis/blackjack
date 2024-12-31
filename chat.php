<?php
// Add this at the top of index.php
header('Content-Type: application/json');

// Chat handling functions
function initChatFile() {
    $date = date('Y-m-d');
    $chatFile = __DIR__ . "/chats/{$date}.json";
    
    if (!file_exists(__DIR__ . '/chats')) {
        mkdir(__DIR__ . '/chats', 0777, true);
    }
    
    if (!file_exists($chatFile)) {
        file_put_contents($chatFile, json_encode([
            'date' => $date,
            'messages' => []
        ]));
        chmod($chatFile, 0777);
    }
    return $chatFile;
}

// In your POST handler, add these cases:
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch($action) {
        case 'getChat':
            try {
                $chatFile = initChatFile();
                $lastId = $_POST['lastId'] ?? null;
                
                if (file_exists($chatFile)) {
                    $chat = json_decode(file_get_contents($chatFile), true);
                    if ($lastId) {
                        $messages = array_filter($chat['messages'], function($msg) use ($lastId) {
                            return $msg['id'] > $lastId;
                        });
                        echo json_encode(['success' => true, 'messages' => array_values($messages)]);
                    } else {
                        echo json_encode(['success' => true, 'messages' => $chat['messages']]);
                    }
                } else {
                    echo json_encode(['success' => true, 'messages' => []]);
                }
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => 'Chat error']);
            }
            exit;
            
        case 'sendChat':
            try {
                $username = $_POST['username'] ?? '';
                $message = $_POST['message'] ?? '';
                
                if (!$username || !$message) {
                    echo json_encode(['success' => false, 'error' => 'Invalid input']);
                    exit;
                }
                
                $chatFile = initChatFile();
                $chat = json_decode(file_get_contents($chatFile), true);
                
                $chat['messages'][] = [
                    'id' => uniqid(),
                    'username' => htmlspecialchars($username),
                    'message' => htmlspecialchars($message),
                    'type' => 'message',
                    'timestamp' => time(),
                    'formattedTime' => date('H:i:s')
                ];
                
                // Keep only last 100 messages
                if (count($chat['messages']) > 100) {
                    $chat['messages'] = array_slice($chat['messages'], -100);
                }
                
                file_put_contents($chatFile, json_encode($chat));
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => 'Error sending message']);
            }
            exit;
    }
}
?>