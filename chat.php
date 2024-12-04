<?php
// Chat handling system
function initChatFile() {
    $date = date('Y-m-d');
    $chatFile = "chats/{$date}.json";
    
    if (!file_exists('chats')) {
        mkdir('chats', 0777, true);
    }
    
    if (!file_exists($chatFile)) {
        file_put_contents($chatFile, json_encode([
            'date' => $date,
            'messages' => []
        ]));
    }
    
    return $chatFile;
}

function addChatMessage($username, $message, $type = 'message') {
    $chatFile = initChatFile();
    $chat = json_decode(file_get_contents($chatFile), true);
    
    $chat['messages'][] = [
        'id' => uniqid(),
        'username' => $username,
        'message' => $message,
        'type' => $type,
        'timestamp' => time(),
        'formattedTime' => date('H:i:s')
    ];
    
    // Keep only last 100 messages
    if (count($chat['messages']) > 100) {
        $chat['messages'] = array_slice($chat['messages'], -100);
    }
    
    file_put_contents($chatFile, json_encode($chat));
}

function getChatMessages($lastId = null) {
    $chatFile = initChatFile();
    $chat = json_decode(file_get_contents($chatFile), true);
    
    if ($lastId) {
        $messages = array_filter($chat['messages'], function($msg) use ($lastId) {
            return $msg['id'] > $lastId;
        });
        return array_values($messages);
    }
    
    return $chat['messages'];
}

// Add to your existing game actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'sendChat') {
        $message = $_POST['message'] ?? '';
        if ($message && $username) {
            addChatMessage($username, $message);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['error' => 'Invalid message']);
        }
        exit;
    }
    
    if ($action === 'getChat') {
        $lastId = $_POST['lastId'] ?? null;
        $messages = getChatMessages($lastId);
        echo json_encode(['messages' => $messages]);
        exit;
    }
    
    // Add game events to chat
    if ($action === 'start') {
        addChatMessage($username, "started a new game with {$bet} EXP!", 'game');
    }
    
    if ($action === 'double') {
        addChatMessage($username, "doubled down!", 'game');
    }
    
    if ($gameResult) {
        $resultMessage = $gameResult > 0 ? 
            "won {$gameResult} EXP! 🎉" : 
            "lost " . abs($gameResult) . " EXP 😢";
        addChatMessage($username, $resultMessage, 'game');
    }
}
?>