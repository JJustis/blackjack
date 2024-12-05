<?php
session_start();
header('Content-Type: text/html; charset=utf-8');

// Database connection
$conn = new mysqli('localhost', 'root', '', 'reservesphp');
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    $username = $_POST['username'] ?? '';
    
    if ($action === 'getMessages') {
    $lastId = isset($_POST['lastId']) ? intval($_POST['lastId']) : 0;
    
    $query = "SELECT c.*, m.exp 
             FROM chat c 
             LEFT JOIN members m ON c.username = m.username 
             WHERE c.id > ?
             ORDER BY c.timestamp ASC 
             LIMIT 50";
             
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $lastId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $messages = [];
    while ($row = $result->fetch_assoc()) {
        $messages[] = [
            'id' => intval($row['id']),
            'username' => $row['username'],
            'message' => $row['message'],
            'timestamp' => date('H:i', strtotime($row['timestamp'])),
            'exp' => $row['exp'] ?? 0
        ];
    }
    
    echo json_encode(['success' => true, 'messages' => $messages]);
    exit;
}
    
    if ($action === 'sendMessage') {
        $message = $_POST['message'] ?? '';
        
        if ($username && $message) {
            $stmt = $conn->prepare("INSERT INTO chat (username, message) VALUES (?, ?)");
            $stmt->bind_param("ss", $username, $message);
            $success = $stmt->execute();
            
            echo json_encode(['success' => $success]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Invalid input']);
        }
        exit;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Live Chat</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #2ecc71;
            --secondary: #3498db;
            --accent: #f1c40f;
            --dark: #2c3e50;
            --text: #ffffff;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: Arial, sans-serif;
            background: linear-gradient(135deg, var(--dark), #34495e);
            color: var(--text);
            height: 100vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .chat-container {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: rgba(0, 0, 0, 0.3);
            margin: 10px;
            border-radius: 10px;
            overflow: hidden;
        }

        .chat-header {
            padding: 15px;
            background: linear-gradient(45deg, var(--primary), var(--secondary));
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .chat-header h1 {
            font-size: 1.2em;
            margin: 0;
        }

        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 15px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .message {
            background: rgba(255, 255, 255, 0.1);
            padding: 8px 12px;
            border-radius: 8px;
            animation: slideIn 0.3s ease-out;
            word-break: break-word;
        }

        .message .header {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 4px;
        }

        .message .username {
            font-weight: bold;
            color: var(--accent);
        }

        .message .time {
            font-size: 0.8em;
            color: rgba(255, 255, 255, 0.5);
        }

        .message .exp {
            font-size: 0.8em;
            background: rgba(255, 255, 255, 0.1);
            padding: 2px 6px;
            border-radius: 10px;
        }

        .chat-input {
            padding: 15px;
            background: rgba(0, 0, 0, 0.2);
            
            gap: 10px;
        }

        .chat-input input {
            flex: 1;
            padding: 10px;
            border: none;
            border-radius: 20px;
            background: rgba(255, 255, 255, 0.1);
            color: white;
            transition: all 0.3s;
        }

        .chat-input input:focus {
            outline: none;
            background: rgba(255, 255, 255, 0.15);
            box-shadow: 0 0 0 2px var(--accent);
        }

        .chat-input button {
            padding: 10px 20px;
            border: none;
            border-radius: 20px;
            background: var(--accent);
            color: var(--dark);
            cursor: pointer;
            transition: all 0.3s;
        }

        .chat-input button:hover {
            transform: translateY(-2px);
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }

        @keyframes slideIn {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        /* Scrollbar styling */
        .chat-messages::-webkit-scrollbar {
            width: 6px;
        }

        .chat-messages::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.1);
        }

        .chat-messages::-webkit-scrollbar-thumb {
            background: var(--accent);
            border-radius: 3px;
        }
    </style>
</head>
<body>
    <div class="chat-container">
        <div class="chat-header">
            <i class="fas fa-comments"></i>
            <h1>Live Chat</h1>
        </div>
        
        <div class="chat-messages" id="messages"></div>
        
        <div class="chat-input">
            <input type="text" id="username" placeholder="Username" />
            <input type="text" id="messageInput" placeholder="Type a message..." />
            <button id="sendButton">
                <i class="fas fa-paper-plane"></i>
            </button>
        </div>
    </div>

    <script>
        class Chat {
            constructor() {
        this.messages = document.getElementById('messages');
        this.username = document.getElementById('username');
        this.messageInput = document.getElementById('messageInput');
        this.sendButton = document.getElementById('sendButton');
        this.lastMessageId = 0; // Track last message
        this.messageCache = new Map(); // Cache to prevent duplicates
        
        this.setupEventListeners();
        this.startPolling();
            }

            setupEventListeners() {
                this.sendButton.addEventListener('click', () => this.sendMessage());
                this.messageInput.addEventListener('keypress', (e) => {
                    if (e.key === 'Enter') this.sendMessage();
                });
            }
    appendNewMessages(messages) {
        let shouldScroll = 
            this.messages.scrollHeight - this.messages.scrollTop === this.messages.clientHeight;

        messages.forEach(msg => {
            if (!this.messageCache.has(msg.id)) {
                this.messageCache.set(msg.id, msg);
                this.lastMessageId = Math.max(this.lastMessageId, msg.id);
                
                const messageElement = document.createElement('div');
                messageElement.className = 'message';
                messageElement.innerHTML = `
                    <div class="header">
                        <span class="username">${msg.username}</span>
                        <span class="time">${msg.timestamp}</span>
                        <span class="exp">${msg.exp} EXP</span>
                    </div>
                    <div class="content">${msg.message}</div>
                `;
                
                this.messages.appendChild(messageElement);
            }
        });

        // Cleanup cache (keep last 100 messages)
        if (this.messageCache.size > 100) {
            const oldestKeys = Array.from(this.messageCache.keys())
                .slice(0, this.messageCache.size - 100);
            oldestKeys.forEach(key => this.messageCache.delete(key));
        }

        // Auto-scroll only if we were at bottom before
        if (shouldScroll) {
            this.scrollToBottom();
        }
    }
     // Update PHP endpoint to:
    async sendMessage() {
        const username = this.username.value.trim();
        const message = this.messageInput.value.trim();
        
        if (!username || !message) return;

        try {
            const response = await fetch('chat.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=sendMessage&username=${encodeURIComponent(username)}&message=${encodeURIComponent(message)}`
            });

            const data = await response.json();
            if (data.success) {
                this.messageInput.value = '';
                // Force an immediate poll for the new message
                this.pollMessages();
            }
        } catch (error) {
            console.error('Error sending message:', error);
        }
    }

            startPolling() {
                this.pollMessages();
                setInterval(() => this.pollMessages(), 2000);
            }

    async pollMessages() {
        try {
            const response = await fetch('chat.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=getMessages&lastId=${this.lastMessageId}`
            });

            const data = await response.json();
            if (data.success) {
                this.appendNewMessages(data.messages);
            }
        } catch (error) {
            console.error('Error polling messages:', error);
        }
    }

            renderMessages(messages) {
                this.messages.innerHTML = messages.map(msg => `
                    <div class="message">
                        <div class="header">
                            <span class="username">${msg.username}</span>
                            <span class="time">${msg.timestamp}</span>
                            <span class="exp">${msg.exp} EXP</span>
                        </div>
                        <div class="content">${msg.message}</div>
                    </div>
                `).join('');

                this.scrollToBottom();
            }

            scrollToBottom() {
                this.messages.scrollTop = this.messages.scrollHeight;
            }
        }

        // Initialize chat when page loads
        document.addEventListener('DOMContentLoaded', () => new Chat());
    </script>
</body>
</html>