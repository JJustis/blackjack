<?php
session_start();
$conn = new mysqli('localhost', 'root', '', 'reservesphp');

if ($conn->connect_error) {
    die(json_encode(['error' => 'Connection failed']));
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    $username = $_POST['username'] ?? '';
    
    // Handle EXP check
    if ($action === 'checkExp') {
        $stmt = $conn->prepare("SELECT exp, wins, losses FROM members WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();
            echo json_encode([
                'success' => true,
                'exp' => $user['exp'],
                'stats' => [
                    'wins' => $user['wins'] ?? 0,
                    'losses' => $user['losses'] ?? 0
                ]
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'User not found']);
        }
        exit;
    }

    // Handle game actions
    if (isset($_SESSION['game'])) {
        $game = unserialize($_SESSION['game']);
    }

    switch ($action) {
        case 'start':
            $bet = (int)($_POST['bet'] ?? 0);
            $stmt = $conn->prepare("SELECT exp FROM members WHERE username = ?");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            
            if ($user['exp'] < $bet) {
                echo json_encode(['error' => 'Not enough EXP']);
                exit;
            }
            
            // Deduct initial bet
            $stmt = $conn->prepare("UPDATE members SET exp = exp - ? WHERE username = ?");
            $stmt->bind_param("is", $bet, $username);
            $stmt->execute();
            
            $game = [
                'username' => $username,
                'bet' => $bet,
                'deck' => generateDeck(),
                'playerHands' => [[]], // Support for splitting
                'dealerHand' => [],
                'currentHand' => 0,
                'status' => 'playing'
            ];
            
            // Initial deal
            shuffle($game['deck']);
            $game['playerHands'][0][] = array_pop($game['deck']);
            $game['dealerHand'][] = array_pop($game['deck']);
            $game['playerHands'][0][] = array_pop($game['deck']);
            // Dealer's hole card
            $game['dealerHand'][] = array_pop($game['deck']);
            
            $_SESSION['game'] = serialize($game);
            echo json_encode(getGameState($game));
            break;
            
        case 'hit':
            if (!$game) {
                echo json_encode(['error' => 'No active game']);
                exit;
            }
            
            $currentHand = &$game['playerHands'][$game['currentHand']];
            $currentHand[] = array_pop($game['deck']);
            
            $handValue = calculateHand($currentHand);
            if ($handValue > 21) {
                if ($game['currentHand'] >= count($game['playerHands']) - 1) {
                    finishGame($game, $conn);
                } else {
                    $game['currentHand']++;
                }
            }
            
            $_SESSION['game'] = serialize($game);
            echo json_encode(getGameState($game));
            break;
            
        case 'stand':
            if (!$game) {
                echo json_encode(['error' => 'No active game']);
                exit;
            }
            
            if ($game['currentHand'] >= count($game['playerHands']) - 1) {
                finishGame($game, $conn);
            } else {
                $game['currentHand']++;
                $_SESSION['game'] = serialize($game);
            }
            
            echo json_encode(getGameState($game));
            break;
            
        case 'split':
            if (!$game || !canSplit($game['playerHands'][$game['currentHand']])) {
                echo json_encode(['error' => 'Cannot split']);
                exit;
            }
            
            // Verify enough EXP for additional bet
            $stmt = $conn->prepare("SELECT exp FROM members WHERE username = ?");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            
            if ($user['exp'] < $game['bet']) {
                echo json_encode(['error' => 'Not enough EXP to split']);
                exit;
            }
            
            // Deduct additional bet
            $stmt = $conn->prepare("UPDATE members SET exp = exp - ? WHERE username = ?");
            $stmt->bind_param("is", $game['bet'], $username);
            $stmt->execute();
            
            $currentHand = &$game['playerHands'][$game['currentHand']];
            $newHand = [array_pop($currentHand)];
            $currentHand[] = array_pop($game['deck']);
            $newHand[] = array_pop($game['deck']);
            array_splice($game['playerHands'], $game['currentHand'] + 1, 0, [$newHand]);
            
            $_SESSION['game'] = serialize($game);
            echo json_encode(getGameState($game));
            break;
            
        case 'double':
            if (!$game || !canDouble($game['playerHands'][$game['currentHand']])) {
                echo json_encode(['error' => 'Cannot double']);
                exit;
            }
            
            // Verify enough EXP for doubling
            $stmt = $conn->prepare("SELECT exp FROM members WHERE username = ?");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            
            if ($user['exp'] < $game['bet']) {
                echo json_encode(['error' => 'Not enough EXP to double']);
                exit;
            }
            
            // Deduct additional bet
            $stmt = $conn->prepare("UPDATE members SET exp = exp - ? WHERE username = ?");
            $stmt->bind_param("is", $game['bet'], $username);
            $stmt->execute();
            
            $game['bet'] *= 2;
            $currentHand = &$game['playerHands'][$game['currentHand']];
            $currentHand[] = array_pop($game['deck']);
            
            if ($game['currentHand'] >= count($game['playerHands']) - 1) {
                finishGame($game, $conn);
            } else {
                $game['currentHand']++;
            }
            
            $_SESSION['game'] = serialize($game);
            echo json_encode(getGameState($game));
            break;
    }
    exit;
}

// Helper functions
function generateDeck() {
    $deck = [];
    $suits = ['♠', '♥', '♦', '♣'];
    $values = [
        'A' => 11, '2' => 2, '3' => 3, '4' => 4, '5' => 5,
        '6' => 6, '7' => 7, '8' => 8, '9' => 9, '10' => 10,
        'J' => 10, 'Q' => 10, 'K' => 10
    ];
    
    foreach ($suits as $suit) {
        foreach ($values as $face => $value) {
            $deck[] = [
                'suit' => $suit,
                'face' => $face,
                'value' => $value,
                'isRed' => in_array($suit, ['♥', '♦'])
            ];
        }
    }
    
    return $deck;
}

function calculateHand($hand) {
    $total = 0;
    $aces = 0;
    
    foreach ($hand as $card) {
        if ($card['face'] === 'A') {
            $aces++;
        }
        $total += $card['value'];
    }
    
    while ($total > 21 && $aces > 0) {
        $total -= 10;
        $aces--;
    }
    
    return $total;
}

function canSplit($hand) {
    return count($hand) === 2 && $hand[0]['face'] === $hand[1]['face'];
}

function canDouble($hand) {
    return count($hand) === 2;
}

function getGameState($game) {
    global $conn;
    
    // Get current exp
    $stmt = $conn->prepare("SELECT exp FROM members WHERE username = ?");
    $stmt->bind_param("s", $game['username']);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    
    return [
        'playerHands' => $game['playerHands'],
        'dealerHand' => $game['status'] === 'complete' ? $game['dealerHand'] : [$game['dealerHand'][0]],
        'currentHand' => $game['currentHand'],
        'status' => $game['status'],
        'bet' => $game['bet'],
        'exp' => $user['exp'] // Add this line to include current exp
    ];
}

function finishGame(&$game, $conn) {
    $game['status'] = 'complete';
    $dealerTotal = calculateHand($game['dealerHand']);
    
    // Dealer draws until 17 or higher
    while ($dealerTotal < 17) {
        $game['dealerHand'][] = array_pop($game['deck']);
        $dealerTotal = calculateHand($game['dealerHand']);
    }
    
    $totalWinnings = 0;
    foreach ($game['playerHands'] as $hand) {
        $playerTotal = calculateHand($hand);
        
        if ($playerTotal > 21) {
            continue; // Player bust, no winnings
        }
        
        if ($dealerTotal > 21 || $playerTotal > $dealerTotal) {
            $totalWinnings += $game['bet'] * 2;
        } elseif ($playerTotal === $dealerTotal) {
            $totalWinnings += $game['bet']; // Push
        }
    }
    
    if ($totalWinnings > 0) {
        $stmt = $conn->prepare("UPDATE members SET exp = exp + ?, wins = wins + 1 WHERE username = ?");
        $stmt->bind_param("is", $totalWinnings, $game['username']);
    } else {
        $stmt = $conn->prepare("UPDATE members SET losses = losses + 1 WHERE username = ?");
        $stmt->bind_param("s", $game['username']);
    }
    $stmt->execute();
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>🎰 Epic Blackjack Casino</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        @property --gradient-angle {
            syntax: '<angle>';
            initial-value: 0deg;
            inherits: false;
        }

        :root {
            --primary: #2ecc71;
            --secondary: #3498db;
            --accent: #f1c40f;
            --danger: #e74c3c;
            --dark: #1a1a2e;
            --table: #27ae60;
            
            --card-width: 140px;
            --card-height: 200px;
            --card-radius: 12px;
            
            --glass-bg: rgba(255, 255, 255, 0.1);
            --glass-border: rgba(255, 255, 255, 0.2);
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: 'Arial', sans-serif;
            background: linear-gradient(135deg, var(--dark), #0f172a);
            color: white;
            overflow-x: hidden;
        }

        /* Post-processing effects */
        .post-processing {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 1000;
        }
/* Particle Effects */
.particle {
    position: fixed;
    pointer-events: none;
    border-radius: 50%;
    z-index: 9999;
}

/* Card Effects */
@keyframes cardDeal {
    0% {
        transform: translate(-1000px, -500px) rotate(-720deg) scale(0.1);
        opacity: 0;
        filter: blur(10px);
    }
    100% {
        transform: translate(0, 0) rotate(0) scale(1);
        opacity: 1;
        filter: blur(0);
    }
}

@keyframes cardFlip {
    0% { transform: rotateY(0deg); }
    100% { transform: rotateY(180deg); }
}

@keyframes cardHover {
    0% { transform: translateY(0) rotate(0deg); filter: brightness(1); }
    50% { transform: translateY(-10px) rotate(2deg); filter: brightness(1.2); }
    100% { transform: translateY(0) rotate(0deg); filter: brightness(1); }
}

/* Chip Animation */
@keyframes chipSpin {
    0% { transform: rotateY(0deg) scale(1); }
    50% { transform: rotateY(180deg) scale(1.1); }
    100% { transform: rotateY(360deg) scale(1); }
}

/* Win Effects */
@keyframes winPulse {
    0% { transform: scale(1); filter: brightness(1); }
    50% { transform: scale(1.1); filter: brightness(1.5); }
    100% { transform: scale(1); filter: brightness(1); }
}

/* Table Effects */
.game-table {
    position: relative;
    overflow: hidden;
}

.table-light {
    position: absolute;
    width: 100%;
    height: 100%;
    background: radial-gradient(
        circle at var(--x, 50%) var(--y, 50%),
        rgba(255,255,255,0.1) 0%,
        transparent 50%
    );
    pointer-events: none;
    transition: all 0.3s ease;
}

/* Apply these new styles */
.card {
    animation: cardDeal 0.6s cubic-bezier(0.34, 1.56, 0.64, 1);
    transform-style: preserve-3d;
    transition: transform 0.6s cubic-bezier(0.34, 1.56, 0.64, 1);
}

.card:hover {
    animation: cardHover 3s infinite ease-in-out;
}

.chip {
    transform-style: preserve-3d;
    transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
}

.chip:hover {
    animation: chipSpin 1s ease-out;
}

.win-animation {
    animation: winPulse 1s ease-in-out;
}
        .noise {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAADIAAAAyBAMAAADsEZWCAAAAGFBMVEUAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAEwkKUAAAAB3RSTlMAGgcEAQQDAMIXbwAAACJJREFUKM9jYBgFo2AU0Bj4/59QhQkMhGoiyGAUjIJRQDcAAObzAx3Gh8HOAAAAAElFTkSuQmCC);
            opacity: 0.02;
            mix-blend-mode: overlay;
        }

        .vignette {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle, transparent 50%, rgba(0,0,0,0.5) 150%);
        }

        .scanlines {
            background: linear-gradient(
                0deg,
                rgba(0, 0, 0, 0) 0%,
                rgba(0, 0, 0, 0.2) 50%,
                rgba(0, 0, 0, 0) 100%
            );
            background-size: 100% 4px;
            opacity: 0.1;
        }

        /* Main container */
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
            position: relative;
            z-index: 1;
        }

        /* Header styles */
        .header {
            text-align: center;
            padding: 20px;
            margin-bottom: 30px;
            position: relative;
        }

        .header h1 {
            font-size: 4em;
            margin: 0;
            background: linear-gradient(
                var(--gradient-angle),
                var(--accent),
                #f39c12,
                #e67e22,
                var(--accent)
            );
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            animation: gradient-rotate 5s linear infinite;
            filter: drop-shadow(0 0 10px rgba(243, 156, 18, 0.3));
        }

        @keyframes gradient-rotate {
            0% { --gradient-angle: 0deg; }
            100% { --gradient-angle: 360deg; }
        }

        /* Game area with glass morphism */
        .game-area {
            display: flex;
            gap: 30px;
            perspective: 1000px;
        }

        .game-table {
            flex: 1;
            background: linear-gradient(
                135deg,
                rgba(39, 174, 96, 0.8),
                rgba(33, 154, 82, 0.8)
            );
            border-radius: 25px;
            padding: 40px;
            box-shadow: 
                0 10px 30px rgba(0,0,0,0.3),
                inset 0 0 100px rgba(255,255,255,0.1);
            backdrop-filter: blur(10px);
            transform-style: preserve-3d;
            transform: rotateX(5deg);
        }

        /* Card styles with 3D effects */
        .card {
            width: var(--card-width);
            height: var(--card-height);
            position: relative;
            transform-style: preserve-3d;
            transition: transform 0.6s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .card-face {
            position: absolute;
            width: 100%;
            height: 100%;
            backface-visibility: hidden;
            border-radius: var(--card-radius);
            box-shadow: 
                0 5px 15px rgba(0,0,0,0.3),
                inset 0 0 50px rgba(255,255,255,0.1);
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5em;
        }

        .card-back {
            transform: rotateY(180deg);
            background: linear-gradient(135deg, #e74c3c, #c0392b);
            background-image: 
                linear-gradient(45deg, rgba(255,255,255,0.1) 25%, transparent 25%),
                linear-gradient(-45deg, rgba(255,255,255,0.1) 25%, transparent 25%),
                linear-gradient(45deg, transparent 75%, rgba(255,255,255,0.1) 75%),
                linear-gradient(-45deg, transparent 75%, rgba(255,255,255,0.1) 75%);
            background-size: 20px 20px;
            background-position: 0 0, 0 10px, 10px -10px, -10px 0px;
        }
.chat-container {
    margin-top: 20px;
    background: rgba(0, 0, 0, 0.3);
    border-radius: 15px;
    overflow: hidden;
}

.chat-header {
    background: linear-gradient(45deg, var(--primary), var(--secondary));
    padding: 10px 15px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.chat-messages {
    height: 200px;
    overflow-y: auto;
    padding: 15px;
    scroll-behavior: smooth;
}

.chat-messages::-webkit-scrollbar {
    width: 5px;
}

.chat-messages::-webkit-scrollbar-track {
    background: rgba(255, 255, 255, 0.1);
}

.chat-messages::-webkit-scrollbar-thumb {
    background: var(--accent);
    border-radius: 5px;
}

.chat-message {
    margin-bottom: 10px;
    animation: messageSlide 0.3s ease-out;
}

.chat-message.game-event {
    color: var(--accent);
    font-style: italic;
}

.chat-message .time {
    font-size: 0.8em;
    color: rgba(255, 255, 255, 0.5);
    margin-right: 5px;
}

.chat-message .username {
    font-weight: bold;
    color: var(--accent);
}

.chat-input {
    display: flex;
    padding: 10px;
    background: rgba(0, 0, 0, 0.2);
    gap: 10px;
}

.chat-input input {
    flex: 1;
    background: rgba(255, 255, 255, 0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
    padding: 8px 12px;
    border-radius: 20px;
    color: white;
    transition: all 0.3s ease;
}

.chat-input input:focus {
    outline: none;
    border-color: var(--accent);
    background: rgba(255, 255, 255, 0.15);
}

.chat-input button {
    background: var(--accent);
    border: none;
    border-radius: 20px;
    padding: 8px 15px;
    color: white;
    cursor: pointer;
    transition: all 0.3s ease;
}

.chat-input button:hover {
    transform: translateY(-2px);
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
}

@keyframes messageSlide {
    from {
        transform: translateY(20px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}
        .card.flipped {
            transform: rotateY(180deg);
        }
		/* Sidebar with glass morphism */
        .sidebar {
            width: 350px;
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: 20px;
            padding: 25px;
            transform-style: preserve-3d;
            animation: float 6s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
        }

        /* Chip styles with 3D effects */
        .chip-rack {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin: 25px 0;
            perspective: 1000px;
        }

        .chip {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            position: relative;
            cursor: pointer;
            transform-style: preserve-3d;
            transition: transform 0.3s ease;
        }

        .chip::before {
            content: '';
            position: absolute;
            width: 100%;
            height: 100%;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--accent), #f39c12);
            border: 6px dashed rgba(255,255,255,0.3);
            transform: translateZ(1px);
        }

        .chip::after {
            content: attr(data-value);
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) translateZ(2px);
            font-weight: bold;
            color: white;
            text-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }

        .chip:hover {
            transform: translateY(-5px) rotateX(20deg);
        }

        /* Control buttons with neon effect */
        .controls {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            justify-content: center;
            padding: 25px;
        }

        .btn {
            padding: 15px 30px;
            border: none;
            border-radius: 30px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s;
            text-transform: uppercase;
            letter-spacing: 2px;
            position: relative;
            overflow: hidden;
            background: var(--glass-bg);
            backdrop-filter: blur(5px);
            border: 1px solid var(--glass-border);
            color: white;
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transform: translateX(-100%);
            transition: transform 0.6s;
        }

        .btn:hover::before {
            transform: translateX(100%);
        }

        .btn-primary { --btn-color: var(--primary); }
        .btn-secondary { --btn-color: var(--secondary); }
        .btn-danger { --btn-color: var(--danger); }

        .btn:hover {
            box-shadow: 
                0 0 10px var(--btn-color),
                0 0 20px var(--btn-color),
                0 0 40px var(--btn-color);
            text-shadow: 0 0 5px rgba(255,255,255,0.5);
        }

        /* Hand areas with lighting effects */
        .hand-area {
            min-height: 220px;
            padding: 25px;
            margin: 20px 0;
            background: rgba(0,0,0,0.2);
            border-radius: 15px;
            position: relative;
            overflow: hidden;
        }

        .hand-area::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 60%);
            transform: rotate(45deg);
            pointer-events: none;
        }

        .active-hand {
            box-shadow: 0 0 20px var(--accent);
        }

        /* Card animations */
        @keyframes dealCard {
            0% {
                transform: 
                    translate(-1000px, -500px) 
                    rotate(-720deg) 
                    scale(0.5);
                opacity: 0;
            }
            100% {
                transform: 
                    translate(0, 0) 
                    rotate(0deg) 
                    scale(1);
                opacity: 1;
            }
        }

        @keyframes cardHover {
            0%, 100% { transform: translateY(0) rotate(0); }
            50% { transform: translateY(-10px) rotate(2deg); }
        }

        .card {
            animation: dealCard 0.6s cubic-bezier(0.17, 0.67, 0.83, 0.67) backwards;
        }

        .card:hover {
            animation: cardHover 2s ease-in-out infinite;
        }

        /* Message styles */
        .message {
            padding: 15px;
            border-radius: 10px;
            text-align: center;
            font-weight: bold;
            position: relative;
            overflow: hidden;
            background: var(--glass-bg);
            backdrop-filter: blur(10px);
            animation: messageSlide 0.3s ease-out;
        }

        @keyframes messageSlide {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        /* Stats display */
        .stats {
            display: flex;
            gap: 15px;
            margin: 20px 0;
        }

        .stat-item {
            flex: 1;
            padding: 15px;
            border-radius: 10px;
            background: var(--glass-bg);
            text-align: center;
            border: 1px solid var(--glass-border);
            animation: statPulse 2s infinite;
        }

        @keyframes statPulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }

        /* Loading overlay */
        .loading {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.8);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            backdrop-filter: blur(5px);
        }

        .loading-spinner {
            width: 50px;
            height: 50px;
            border: 5px solid var(--glass-border);
            border-top-color: var(--accent);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <!-- Post-processing effects -->
    <div class="post-processing">
        <div class="noise"></div>
        <div class="scanlines"></div>
        <div class="vignette"></div>
    </div>

    <div class="container">
        <div class="header">
            <h1>
                <i class="fas fa-crown"></i> 
                Epic Blackjack Casino 
                <i class="fas fa-crown"></i>
            </h1>
        </div>

        <div class="game-area">
            <div class="game-table">
                <!-- Dealer Section -->
                <div class="dealer-section">
                    <h2>
                        <i class="fas fa-user-tie"></i> 
                        Dealer's Hand 
                        <span id="dealerScore" class="score">?</span>
                    </h2>
                    <div class="hand-area" id="dealerHand"></div>
                </div>

                <!-- Player Section -->
                <div class="player-section">
                    <h2>
                        <i class="fas fa-user"></i> 
                        Your Hand 
                        <span id="playerScore" class="score">0</span>
                    </h2>
                    <div class="hand-area" id="playerHand"></div>
                </div>

                <div class="message" id="message"></div>

                <!-- Game Controls -->
                <div class="controls">
                    <button class="btn btn-primary" id="startButton">
                        <i class="fas fa-play"></i> Deal
                    </button>
                    <button class="btn btn-secondary" id="hitButton" disabled>
                        <i class="fas fa-plus"></i> Hit
                    </button>
                    <button class="btn btn-secondary" id="standButton" disabled>
                        <i class="fas fa-stop"></i> Stand
                    </button>
                    <button class="btn btn-primary" id="doubleButton" disabled>
                        <i class="fas fa-times"></i> Double
                    </button>
                    <button class="btn btn-primary" id="splitButton" disabled>
                        <i class="fas fa-code-branch"></i> Split
                    </button>
                </div>
            </div>

            <div class="sidebar">
                <!-- Player Info -->
                <div class="player-info">
                    <div class="input-group">
                        <label><i class="fas fa-user"></i> Username</label>
                        <input type="text" id="username" placeholder="Enter username">
                    </div>

                    <!-- Stats Display -->
                    <div class="stats">
                        <div class="stat-item">
                            <i class="fas fa-star"></i>
                            <div class="stat-value" id="currentExp">0</div>
                            <div class="stat-label">EXP</div>
                        </div>
                        <div class="stat-item">
                            <i class="fas fa-trophy"></i>
                            <div class="stat-value" id="wins">0</div>
                            <div class="stat-label">Wins</div>
                        </div>
                        <div class="stat-item">
                            <i class="fas fa-times"></i>
                            <div class="stat-value" id="losses">0</div>
                            <div class="stat-label">Losses</div>
                        </div>
                    </div>
                </div>

                <!-- Betting Controls -->
                <div class="betting-controls">
                    <h3><i class="fas fa-coins"></i> Place Your Bet</h3>
                    <div class="chip-rack">
                        <button class="chip" data-value="100">100</button>
                        <button class="chip" data-value="500">500</button>
                        <button class="chip" data-value="1000">1K</button>
                        <button class="chip" data-value="5000">5K</button>
                    </div>
                    <div class="input-group">
                        <label>Bet Amount</label>
                        <input type="number" id="betAmount" min="100" step="100">
                    </div>
                </div>

                <!-- Quick Rules -->
                <div class="rules">
                    <h3><i class="fas fa-book"></i> Quick Rules</h3>
                    <ul>
                        <li>Blackjack pays 3:2</li>
                        <li>Dealer stands on all 17s</li>
                        <li>Double on any two cards</li>
                        <li>Split up to 3 times</li>
                    </ul>
<iframe src="chats/chat.php" style="width: 100%; height: 600px; border: none;"></iframe>
                </div>
            </div>
        </div>
		<iframe src="http://jcmc.serveminecraft.net/widgets/purchaseCurrency/" style="width: 100%; height: 600px; border: none;"></iframe>

    </div>

    <!-- Sound Effects -->
    <audio id="cardSound" src="data:audio/mp3;base64,[BASE64_CARD_SOUND]" preload="auto"></audio>
    <audio id="chipSound" src="data:audio/mp3;base64,[BASE64_CHIP_SOUND]" preload="auto"></audio>
    <audio id="winSound" src="data:audio/mp3;base64,[BASE64_WIN_SOUND]" preload="auto"></audio>
    <audio id="loseSound" src="data:audio/mp3;base64,[BASE64_LOSE_SOUND]" preload="auto"></audio>
    <script>
class BlackjackGame {
    constructor() {
        // Core game state
        this.username = '';
        this.currentExp = 0;
        this.currentBet = 0;
        this.isPlaying = false;

        // Initialize UI and events
        this.initializeUI();
        this.setupEventListeners();
    }

    initializeUI() {
        this.ui = {
            // Input elements
            username: document.getElementById('username'),
            betAmount: document.getElementById('betAmount'),
            
            // Display elements
            currentExp: document.getElementById('currentExp'),
            wins: document.getElementById('wins'),
            losses: document.getElementById('losses'),
            message: document.getElementById('message'),
            dealerScore: document.getElementById('dealerScore'),
            playerScore: document.getElementById('playerScore'),
            
            // Game areas
            dealerHand: document.getElementById('dealerHand'),
            playerHand: document.getElementById('playerHand'),
            
            // Buttons
            buttons: {
                start: document.getElementById('startButton'),
                hit: document.getElementById('hitButton'),
                stand: document.getElementById('standButton'),
                double: document.getElementById('doubleButton'),
                split: document.getElementById('splitButton')
            }
        };

        // Set initial button states
        this.setButtonStates(false);
    }

    setupEventListeners() {
        // Username handling
        this.ui.username.addEventListener('change', (e) => this.onUsernameChange(e));
        
        // Bet handling
        document.querySelectorAll('.chip').forEach(chip => {
            chip.addEventListener('click', () => this.onChipClick(chip));
        });
        this.ui.betAmount.addEventListener('input', () => this.validateBet());

        // Game action buttons
        this.ui.buttons.start.addEventListener('click', () => this.startGame());
        this.ui.buttons.hit.addEventListener('click', () => this.performAction('hit'));
        this.ui.buttons.stand.addEventListener('click', () => this.performAction('stand'));
        this.ui.buttons.double.addEventListener('click', () => this.performAction('double'));
        this.ui.buttons.split.addEventListener('click', () => this.performAction('split'));
    }

    async onUsernameChange(event) {
        const username = event.target.value.trim();
        if (!username) {
            this.resetGame();
            return;
        }

        try {
            const response = await this.sendRequest('checkExp', { username });
            if (response.success) {
                this.updatePlayerInfo(response);
            } else {
                this.showMessage('User not found', 'error');
                this.resetGame();
            }
        } catch (error) {
            this.showMessage('Connection error', 'error');
            console.error('Error checking username:', error);
        }
    }

    onChipClick(chip) {
        const value = parseInt(chip.dataset.value);
        if (value <= this.currentExp) {
            this.ui.betAmount.value = value;
            this.currentBet = value;
            this.validateBet();
        } else {
            this.showMessage('Not enough EXP for this bet', 'error');
        }
    }

    updatePlayerInfo(data) {
        this.username = this.ui.username.value;
        this.currentExp = data.exp;
        this.ui.currentExp.textContent = data.exp.toLocaleString();
        this.ui.wins.textContent = data.stats.wins;
        this.ui.losses.textContent = data.stats.losses;
        
        this.updateChips();
        this.showMessage(`Welcome, ${this.username}!`, 'success');
    }

    updateChips() {
        document.querySelectorAll('.chip').forEach(chip => {
            const value = parseInt(chip.dataset.value);
            const isEnabled = value <= this.currentExp;
            chip.disabled = !isEnabled;
            chip.style.opacity = isEnabled ? '1' : '0.5';
        });
    }

    validateBet() {
        const bet = parseInt(this.ui.betAmount.value);
        const isValid = bet && bet <= this.currentExp && bet >= 100;
        
        this.ui.buttons.start.disabled = !isValid || !this.username;
        
        if (!bet) {
            this.showMessage('Please enter a bet amount', 'error');
        } else if (bet > this.currentExp) {
            this.showMessage('Insufficient EXP for bet', 'error');
        } else if (bet < 100) {
            this.showMessage('Minimum bet is 100 EXP', 'error');
        } else {
            this.ui.message.textContent = '';
        }
    }

    async startGame() {
        if (!this.validateGameStart()) return;

        try {
            const response = await this.sendRequest('start', {
                bet: this.ui.betAmount.value
            });
            
            if (response.error) {
                this.showMessage(response.error, 'error');
                return;
            }

            this.isPlaying = true;
            this.updateGameState(response);
        } catch (error) {
            this.showMessage('Error starting game', 'error');
            console.error('Game start error:', error);
        }
    }

// Update performAction to handle completion
async performAction(action) {
    if (!this.isPlaying) return;

    try {
        const response = await this.sendRequest(action);
        
        if (response.error) {
            this.showMessage(response.error, 'error');
            return;
        }

        // If the game completes, get fresh exp value
        if (response.status === 'complete') {
            await this.updatePlayerExp();
        }

        this.updateGameState(response);
    } catch (error) {
        console.error(`Action error (${action}):`, error);
        this.showMessage(`Error performing ${action}`, 'error');
    }
}
async updatePlayerExp() {
    try {
        const response = await this.sendRequest('checkExp', { username: this.username });
        if (response.success) {
            this.currentExp = response.exp;
            this.ui.currentExp.textContent = response.exp.toLocaleString();
            this.updateChips();
        }
    } catch (error) {
        console.error('Error updating exp:', error);
    }
}
// Add better state logging for debugging
updateGameState(state) {
    if (!state) {
        console.error('Invalid game state received');
        this.showMessage('Error updating game state', 'error');
        return;
    }

    console.log('Received game state:', state); // Debug log

    if (state.error) {
        this.showMessage(state.error, 'error');
        return;
    }

    try {
        this.renderHands(state);
        this.updateScores(state);
        this.setButtonStates(state.status === 'playing');

        if (state.status === 'complete') {
            this.handleGameComplete(state);
        }
    } catch (error) {
        console.error('Error updating game state:', error, state);
        this.showMessage('Error updating game state', 'error');
    }
}

// Also add a debug method to help track the state:
debug(state) {
    console.log('Game State:', {
        dealerHand: state.dealerHand,
        playerHands: state.playerHands,
        status: state.status,
        currentHand: state.currentHand
    });
}

renderHands(state) {
    // Clear existing hands
    this.ui.dealerHand.innerHTML = '';
    this.ui.playerHand.innerHTML = '';

    // Render dealer's hand
    if (state.dealerHand && Array.isArray(state.dealerHand)) {
        state.dealerHand.forEach((card, index) => {
            this.renderCard(this.ui.dealerHand, card, index);
        });
    }

    // Render player's hands
    if (state.playerHands && Array.isArray(state.playerHands)) {
        state.playerHands.forEach((hand, handIndex) => {
            const handElement = document.createElement('div');
            handElement.className = `hand-area ${handIndex === state.currentHand ? 'active-hand' : ''}`;
            
            // Check if hand is an array of cards
            if (Array.isArray(hand)) {
                hand.forEach((card, cardIndex) => {
                    this.renderCard(handElement, card, cardIndex);
                });
            }
            
            this.ui.playerHand.appendChild(handElement);
        });
    }
}
renderCard(container, card, index) {
    if (!card || typeof card !== 'object') return;

    const cardElement = document.createElement('div');
    cardElement.className = `card ${card.isRed ? 'red' : ''}`;
    cardElement.style.animationDelay = `${index * 0.2}s`;

    // Create the card content
    const displayText = card.face ? `${card.face}${card.suit}` : `${card.display || ''}${card.suit || ''}`;
    cardElement.textContent = displayText;

    container.appendChild(cardElement);
}

    renderDealerHand(hand) {
        this.ui.dealerHand.innerHTML = '';
        hand.forEach((card, index) => {
            const cardElement = this.createCardElement(card, index * 200);
            this.ui.dealerHand.appendChild(cardElement);
        });
    }

    renderPlayerHands(hands, currentHand) {
        this.ui.playerHand.innerHTML = '';
        hands.forEach((hand, index) => {
            const handElement = document.createElement('div');
            handElement.className = `hand-area ${index === currentHand ? 'active-hand' : ''}`;
            
            hand.cards.forEach((card, cardIndex) => {
                const cardElement = this.createCardElement(card, cardIndex * 200);
                handElement.appendChild(cardElement);
            });

            this.ui.playerHand.appendChild(handElement);
        });
    }

    createCardElement(card, delay) {
        const element = document.createElement('div');
        element.className = `card ${card.isRed ? 'red' : ''}`;
        element.textContent = `${card.face}${card.suit}`;
        element.style.animationDelay = `${delay}ms`;
        return element;
    }

    updateScores(state) {
        if (state.dealerScore) {
            this.ui.dealerScore.textContent = state.dealerScore;
        }
        if (state.playerScore) {
            this.ui.playerScore.textContent = state.playerScore;
        }
    }

    setButtonStates(isPlaying) {
        Object.entries(this.ui.buttons).forEach(([action, button]) => {
            if (action === 'start') {
                button.disabled = isPlaying;
            } else {
                button.disabled = !isPlaying;
            }
        });
    }

handleGameComplete(state) {
    this.isPlaying = false;
    
    // Get current exp from the state or use existing
    const newExp = state.exp || state.currentExp || this.currentExp;
    const diff = newExp - this.currentExp;
    
    // Update message based on exp difference
    if (diff > 0) {
        this.showMessage(`You won ${diff} EXP! 🎉`, 'success');
    } else if (diff < 0) {
        this.showMessage(`You lost ${Math.abs(diff)} EXP`, 'error');
    } else {
        this.showMessage('Push - bet returned', 'info');
    }

    // Update current exp if we got a new value
    if (newExp !== undefined) {
        this.currentExp = newExp;
        this.ui.currentExp.textContent = newExp.toLocaleString();
    }

    // Update UI elements
    this.updateChips();
    this.setButtonStates(false);
    
    // Re-enable betting
    this.ui.betAmount.disabled = false;
}

    async sendRequest(action, extraData = {}) {
        const formData = new FormData();
        formData.append('action', action);
        formData.append('username', this.username);
        
        Object.entries(extraData).forEach(([key, value]) => {
            formData.append(key, value);
        });

        const response = await fetch('index.php', {
            method: 'POST',
            body: formData
        });

        return response.json();
    }

    showMessage(text, type = 'info') {
        this.ui.message.textContent = text;
        this.ui.message.className = `message ${type}`;
    }

    resetGame() {
        this.username = '';
        this.currentExp = 0;
        this.currentBet = 0;
        this.isPlaying = false;
        
        this.ui.currentExp.textContent = '0';
        this.ui.wins.textContent = '0';
        this.ui.losses.textContent = '0';
        
        this.updateChips();
        this.setButtonStates(false);
    }

    validateGameStart() {
        if (!this.username) {
            this.showMessage('Please enter your username', 'error');
            return false;
        }
        if (!this.ui.betAmount.value) {
            this.showMessage('Please enter a bet amount', 'error');
            return false;
        }
        return true;
    }
}
class UIEffects {
    constructor(game) {
        this.game = game;
        this.setupTableEffect();
    }

    setupTableEffect() {
        const table = document.querySelector('.game-table');
        const light = document.createElement('div');
        light.className = 'table-light';
        table.appendChild(light);

        table.addEventListener('mousemove', (e) => {
            const rect = table.getBoundingClientRect();
            const x = ((e.clientX - rect.left) / rect.width) * 100;
            const y = ((e.clientY - rect.top) / rect.height) * 100;
            light.style.setProperty('--x', `${x}%`);
            light.style.setProperty('--y', `${y}%`);
        });
    }

    createParticles(type = 'win') {
        const colors = type === 'win' 
            ? ['#ffd700', '#ffeb3b', '#ffc107']
            : ['#ff5252', '#ff1744', '#d50000'];

        for (let i = 0; i < 50; i++) {
            this.createParticle(colors[Math.floor(Math.random() * colors.length)]);
        }
    }

    createParticle(color) {
        const particle = document.createElement('div');
        particle.className = 'particle';
        particle.style.backgroundColor = color;

        const rect = this.game.ui.playerHand.getBoundingClientRect();
        const startX = rect.left + rect.width / 2;
        const startY = rect.top + rect.height / 2;

        const angle = Math.random() * Math.PI * 2;
        const velocity = 2 + Math.random() * 3;
        const size = 5 + Math.random() * 10;

        particle.style.left = `${startX}px`;
        particle.style.top = `${startY}px`;
        particle.style.width = `${size}px`;
        particle.style.height = `${size}px`;

        document.body.appendChild(particle);

        const animation = particle.animate([
            {
                transform: 'translate(0, 0) scale(1)',
                opacity: 1
            },
            {
                transform: `translate(${Math.cos(angle) * 200}px, 
                           ${Math.sin(angle) * 200}px) scale(0)`,
                opacity: 0
            }
        ], {
            duration: 1000 + Math.random() * 1000,
            easing: 'cubic-bezier(0.4, 0, 0.2, 1)'
        });

        animation.onfinish = () => particle.remove();
    }

    dealCard(element, delay = 0) {
        element.style.animation = 'none';
        element.offsetHeight; // Trigger reflow
        element.style.animation = `cardDeal 0.6s ${delay}ms cubic-bezier(0.34, 1.56, 0.64, 1) backwards`;
    }

    flipCard(element) {
        return new Promise(resolve => {
            element.style.animation = 'cardFlip 0.6s cubic-bezier(0.34, 1.56, 0.64, 1)';
            element.addEventListener('animationend', () => resolve(), { once: true });
        });
    }

    async showWinEffect() {
        this.createParticles('win');
        const cards = document.querySelectorAll('.card');
        cards.forEach(card => {
            card.classList.add('win-animation');
            setTimeout(() => card.classList.remove('win-animation'), 1000);
        });
    }

    showLoseEffect() {
        this.createParticles('lose');
    }

    animateChip(chip) {
        chip.style.animation = 'none';
        chip.offsetHeight;
        chip.style.animation = 'chipSpin 0.6s cubic-bezier(0.34, 1.56, 0.64, 1)';
    }

    updateExpCounter(from, to, duration = 1000) {
        const start = performance.now();
        const element = this.game.ui.currentExp;

        const animate = (currentTime) => {
            const elapsed = currentTime - start;
            const progress = Math.min(elapsed / duration, 1);

            const current = Math.floor(from + (to - from) * progress);
            element.textContent = current.toLocaleString();

            if (progress < 1) {
                requestAnimationFrame(animate);
            }
        };

        requestAnimationFrame(animate);
    }

    pulseElement(element) {
        element.classList.add('win-animation');
        setTimeout(() => element.classList.remove('win-animation'), 1000);
    }
}
// Initialize game when document loads
document.addEventListener('DOMContentLoaded', () => new BlackjackGame());
    </script>
</body>
</html>