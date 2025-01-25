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

        /* Base Styles */
        body {
            margin: 0;
            min-height: 100vh;
            font-family: 'Arial', sans-serif;
            color: white;
            overflow-x: hidden;
            background: #0f0f1f;
        }

        /* Background Effects */
        .starfield-container {
            position: fixed;
            top: 50%;
            left: 50%;
            width: 300vw;
            height: 300vh;
            transform: translate(-50%, -50%);
            perspective: 1500px;
            overflow: hidden;
            z-index: -1;
            background: radial-gradient(
                ellipse at center,
                #0a0a2a 0%,
                #090921 40%,
                #06061a 100%
            );
        }

        .star {
            position: absolute;
            border-radius: 50%;
            transform-style: preserve-3d;
            left: 50%;
            top: 50%;
            will-change: transform;
        }

        .galaxy-core {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 400px;
            height: 400px;
            background: radial-gradient(
                circle at center,
                rgba(255, 255, 255, 0.2) 0%,
                rgba(255, 255, 255, 0.15) 20%,
                rgba(255, 255, 255, 0.1) 30%,
                rgba(255, 255, 255, 0.05) 40%,
                transparent 70%
            );
            border-radius: 50%;
            filter: blur(5px);
        }

        .spiral-arm {
            position: absolute;
            top: 50%;
            left: 50%;
            transform-style: preserve-3d;
            will-change: transform;
        }

        /* Post Processing Effects */
        .post-processing {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 1000;
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

        /* Layout */
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
            position: relative;
            z-index: 1;
        }

        .game-area {
            display: flex;
            gap: 30px;
            perspective: 1000px;
        }

        /* Header */
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

        /* Game Table */
        .game-table {
            flex: 1;
            background: linear-gradient(135deg, rgba(39, 174, 96, 0.8), rgba(33, 154, 82, 0.8));
            border-radius: 25px;
            padding: 40px;
            box-shadow: 
                0 10px 30px rgba(0,0,0,0.3),
                inset 0 0 100px rgba(255,255,255,0.1);
            backdrop-filter: blur(10px);
            transform-style: preserve-3d;
            transform: rotateX(5deg);
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

        /* Hand Areas */
        .hand-area {
            min-height: 220px;
            padding: 25px;
            background: rgba(0,0,0,0.2);
            border-radius: 15px;
            position: relative;
            display: flex;
            align-items: center;
            gap: 30px;
            margin-bottom: 20px;
            overflow-x: auto;
            perspective: 1000px;
        }

        .active-hand {
            box-shadow: 0 0 20px var(--accent);
        }

        /* Enhanced Card Styles */
        .card {
            width: var(--card-width);
            height: var(--card-height);
            position: relative;
            transform-style: preserve-3d;
            transition: transform 0.3s ease;
            transform: translateZ(20px);
            filter: drop-shadow(0 10px 20px rgba(0,0,0,0.4));
            will-change: transform, filter;
        }

        /* Card Face Base */
        .card-face {
            position: absolute;
            width: 100%;
            height: 100%;
            backface-visibility: hidden;
            border-radius: var(--card-radius);
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5em;
            font-weight: bold;
            transform-style: preserve-3d;
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        /* Card Front Design */
        .card-front {
            color: #1a1a1a;
            background: linear-gradient(135deg, #ffffff, #f8f8f8);
            box-shadow: 
                inset 0 0 20px rgba(0,0,0,0.05),
                inset 0 0 5px rgba(255,255,255,0.8);
        }

        /* Card Back Pattern */
        .card-back {
            transform: rotateY(180deg);
            background: 
                linear-gradient(135deg, #c0392b, #e74c3c);
            color: transparent;
        }

        .card-back::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: 
                repeating-linear-gradient(
                    45deg,
                    rgba(255,255,255,0.1) 0px,
                    rgba(255,255,255,0.1) 1px,
                    transparent 1px,
                    transparent 5px
                ),
                repeating-linear-gradient(
                    -45deg,
                    rgba(255,255,255,0.1) 0px,
                    rgba(255,255,255,0.1) 1px,
                    transparent 1px,
                    transparent 5px
                );
            mix-blend-mode: overlay;
        }

        /* Card Front Graphics */
        .card-content {
            position: relative;
            width: 90%;
            height: 90%;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            padding: 10px;
            transform: translateZ(1px);
        }

        .card-corner {
            position: absolute;
            display: flex;
            flex-direction: column;
            align-items: center;
            line-height: 1;
            font-size: 0.6em;
        }

        .card-corner.top-left {
            top: 8px;
            left: 8px;
        }

        .card-corner.bottom-right {
            bottom: 8px;
            right: 8px;
            transform: rotate(180deg);
        }

        .card-center {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 1.5em;
        }

        /* Card Toss Animation */
        @keyframes cardToss {
            0% {
                transform: 
                    translate(-1000px, -500px) 
                    rotate3d(1, 1, 1, 720deg) 
                    scale(0.1)
                    translateZ(-100px);
                opacity: 0;
                filter: drop-shadow(0 0 0 rgba(0,0,0,0));
            }
            50% {
                transform: 
                    translate(0, -100px) 
                    rotate3d(1, 0.5, 0.2, 180deg) 
                    scale(1.1)
                    translateZ(100px);
                opacity: 1;
                filter: drop-shadow(0 30px 40px rgba(0,0,0,0.6));
            }
            75% {
                transform: 
                    translate(0, 20px) 
                    rotate3d(1, 0.2, 0.1, 45deg) 
                    scale(1.05)
                    translateZ(50px);
                filter: drop-shadow(0 20px 30px rgba(0,0,0,0.5));
            }
            100% {
                transform: 
                    translate(0, 0) 
                    rotate3d(0, 0, 0, 0) 
                    scale(1)
                    translateZ(20px);
                opacity: 1;
                filter: drop-shadow(0 10px 20px rgba(0,0,0,0.4));
            }
        }

        /* Card Hover Effects */
        .card:hover {
            transform: 
                translateZ(40px) 
                scale(1.1) 
                rotateX(-5deg);
            filter: 
                drop-shadow(0 20px 30px rgba(0,0,0,0.5))
                brightness(1.1);
            z-index: 10;
        }

        /* Shadow on Table */
        .card::after {
            content: '';
            position: absolute;
            bottom: -30px;
            left: 50%;
            width: 90%;
            height: 20px;
            background: rgba(0,0,0,0.3);
            filter: blur(15px);
            transform: translateX(-50%) rotateX(60deg) scale(0.8);
            opacity: 0;
            transition: all 0.3s ease;
            pointer-events: none;
            animation: shadowAppear 0.8s cubic-bezier(0.17, 0.67, 0.83, 0.67) forwards;
        }

        @keyframes shadowAppear {
            0% {
                opacity: 0;
                transform: translateX(-50%) rotateX(60deg) scale(0.5);
            }
            100% {
                opacity: 0.4;
                transform: translateX(-50%) rotateX(60deg) scale(0.8);
            }
        }
.slots-ad {
    margin-top: 30px;
    padding: 20px;
    background: rgba(0,0,0,0.3);
    border-radius: 15px;
    border: 1px solid rgba(255,255,255,0.1);
    box-shadow: 0 10px 20px rgba(0,0,0,0.2);
}

.ad-header {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 15px;
    margin-bottom: 20px;
}

.ad-header h2 {
    margin: 0;
    background: linear-gradient(45deg, #ffd700, #ff6b6b);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    font-size: 1.8em;
}

.ad-header i {
    font-size: 1.5em;
    color: #ffd700;
    animation: pulse 2s infinite;
}

.ad-content {
    display: flex;
    gap: 30px;
    align-items: center;
}

.preview-container {
    flex: 2;
    border-radius: 10px;
    overflow: hidden;
    box-shadow: 0 5px 15px rgba(0,0,0,0.3);
    background: rgba(0,0,0,0.2);
}

.ad-text {
    flex: 1;
    padding: 20px;
    background: rgba(255,255,255,0.05);
    border-radius: 10px;
    border: 1px solid rgba(255,255,255,0.1);
}

.ad-text h3 {
    color: #ffd700;
    margin-top: 0;
}

.ad-text ul {
    list-style: none;
    padding: 0;
    margin: 15px 0;
}

.ad-text li {
    margin: 10px 0;
    font-size: 1.1em;
    color: rgba(255,255,255,0.9);
}

.ad-text .btn {
    margin-top: 20px;
    width: 100%;
    font-size: 1.2em;
    background: linear-gradient(45deg, #ffd700, #ff6b6b);
    border: none;
    animation: glow 2s infinite;
}

@keyframes glow {
    0%, 100% { box-shadow: 0 0 10px #ffd700; }
    50% { box-shadow: 0 0 20px #ffd700, 0 0 30px #ff6b6b; }
}

@keyframes pulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.1); }
}
        /* Metallic Sheen Effect */
        .card-front::after,
        .card-back::after {
            content: '';
            position: absolute;
            top: -100%;
            left: -100%;
            width: 300%;
            height: 300%;
            background: linear-gradient(
                45deg,
                transparent 0%,
                rgba(255,255,255,0.1) 45%,
                rgba(255,255,255,0.2) 50%,
                rgba(255,255,255,0.1) 55%,
                transparent 100%
            );
            transform: rotate(45deg);
            animation: sheen 5s linear infinite;
            pointer-events: none;
        }

        @keyframes sheen {
            0% { transform: rotate(45deg) translateX(-100%); }
            100% { transform: rotate(45deg) translateX(100%); }
        }
		
/* Button Glow Effects */
.btn {
    position: relative;
    overflow: visible;
}

/* High Confidence Glow */
.btn.glow-high {
    animation: pulse-green 2s infinite;
    box-shadow: 
        0 0 10px #4CAF50,
        0 0 20px #4CAF50,
        0 0 30px #4CAF50;
}

/* Medium Confidence Glow */
.btn.glow-medium {
    animation: pulse-yellow 2s infinite;
    box-shadow: 
        0 0 10px #FFC107,
        0 0 20px #FFC107,
        0 0 30px #FFC107;
}

/* Low Confidence Glow */
.btn.glow-low {
    animation: pulse-red 2s infinite;
    box-shadow: 
        0 0 10px #F44336,
        0 0 20px #F44336,
        0 0 30px #F44336;
}

/* Confidence Display */
.confidence-display {
    position: absolute;
    top: -20px;
    right: -10px;
    background: rgba(0, 0, 0, 0.8);
    color: white;
    padding: 2px 6px;
    border-radius: 10px;
    font-size: 0.8em;
    animation: bounce 1s infinite;
}

/* AI Thinking Indicator */
.ai-indicator {
    position: absolute;
    top: -25px;
    left: 50%;
    transform: translateX(-50%);
    font-size: 0.8em;
    color: #4CAF50;
    opacity: 0.8;
    animation: thinking 1.5s infinite;
}

/* Animations */
@keyframes pulse-green {
    0% { box-shadow: 0 0 10px #4CAF50; }
    50% { box-shadow: 0 0 20px #4CAF50, 0 0 30px #4CAF50; }
    100% { box-shadow: 0 0 10px #4CAF50; }
}

@keyframes pulse-yellow {
    0% { box-shadow: 0 0 10px #FFC107; }
    50% { box-shadow: 0 0 20px #FFC107, 0 0 30px #FFC107; }
    100% { box-shadow: 0 0 10px #FFC107; }
}

@keyframes pulse-red {
    0% { box-shadow: 0 0 10px #F44336; }
    50% { box-shadow: 0 0 20px #F44336, 0 0 30px #F44336; }
    100% { box-shadow: 0 0 10px #F44336; }
}

@keyframes bounce {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-3px); }
}

@keyframes thinking {
    0%, 100% { opacity: 0.4; }
    50% { opacity: 1; }
}

.security-dialog {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.8);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    backdrop-filter: blur(5px);
}

.security-content {
    background: linear-gradient(135deg, #1a1a2e, #152238);
    padding: 30px;
    border-radius: 15px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.3);
    border: 1px solid rgba(255,255,255,0.1);
    text-align: center;
    max-width: 500px;
    width: 90%;
}

.key-display {
    background: rgba(0,0,0,0.3);
    padding: 15px;
    border-radius: 8px;
    font-family: monospace;
    font-size: 1.2em;
    margin: 20px 0;
    color: #64B5F6;
    word-break: break-all;
    border: 1px solid rgba(100,181,246,0.3);
    text-shadow: 0 0 10px rgba(100,181,246,0.5);
}

.security-content .btn {
    margin: 10px;
    min-width: 150px;
}
        /* Sidebar */
        .sidebar {
            width: 350px;
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: 20px;
            padding: 25px;
            animation: float 6s ease-in-out infinite;
        }

        /* Controls */
        .controls {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            justify-content: center;
            padding: 25px;
            background: linear-gradient(to bottom, #8B4513, #654321);
            border-radius: 15px;
            box-shadow: 
                0 5px 15px rgba(0,0,0,0.3),
                0 1px 2px rgba(255,255,255,0.1) inset;
            border: 2px solid #A0522D;
            position: relative;
        }

        .btn {
            padding: 15px 30px;
            border: none;
            border-radius: 30px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            background: linear-gradient(to bottom, rgba(255,255,255,0.1), rgba(255,255,255,0.05));
            border: 1px solid rgba(255,255,255,0.2);
            box-shadow: 
                0 5px 15px rgba(0,0,0,0.2),
                0 1px 2px rgba(255,255,255,0.1) inset;
            color: white;
            text-shadow: 0 1px 2px rgba(0,0,0,0.5);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Chips */
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

        /* Animations */
        @keyframes gradient-rotate {
            0% { --gradient-angle: 0deg; }
            100% { --gradient-angle: 360deg; }
        }

        @keyframes cardToss {
            0% {
                transform: translate(-1000px, -200px) rotate3d(1, 1, 1, 720deg) scale(0.1);
                opacity: 0;
            }
            60% {
                transform: translate(0, -50px) rotate3d(1, 0.5, 0.2, 180deg) scale(1.1);
                opacity: 1;
            }
            80% {
                transform: translate(0, 20px) rotate3d(1, 0.2, 0.1, 45deg) scale(1.05);
            }
            100% {
                transform: translate(0, 0) rotate3d(0, 0, 0, 0) scale(1);
            }
        }

        @keyframes metallicSheen {
            0% { transform: translateX(-200%) translateY(-200%); }
            100% { transform: translateX(200%) translateY(200%); }
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
        }

        @keyframes rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        @keyframes pulse {
            0%, 100% { transform: translate(-50%, -50%) scale(1); opacity: 1; }
            50% { transform: translate(-50%, -50%) scale(1.2); opacity: 0.8; }
        }

        /* Messages */
        .message {
            padding: 15px;
            border-radius: 10px;
            text-align: center;
            font-weight: bold;
            margin: 20px 0;
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
        /* Input Groups */
        .input-group {
            margin-bottom: 15px;
        }

        .input-group label {
            display: block;
            margin-bottom: 5px;
            color: white;
        }

        .input-group input {
            width: 100%;
            padding: 10px;
            border: 1px solid rgba(255,255,255,0.2);
            background: rgba(255,255,255,0.1);
            border-radius: 5px;
            color: white;
            outline: none;
        }

        .input-group input:focus {
            border-color: var(--accent);
        }

        /* Chat Container */
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

        /* Rules Section */
        .rules {
            margin-top: 20px;
            padding: 15px;
            background: rgba(0, 0, 0, 0.2);
            border-radius: 10px;
        }

        .rules h3 {
            margin-top: 0;
            color: var(--accent);
        }

        .rules ul {
            padding-left: 20px;
            margin: 10px 0;
        }

        .rules li {
            margin: 5px 0;
            color: rgba(255, 255, 255, 0.8);
        }

        /* Loading Overlay */
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

        /* Button States and Effects */
        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            box-shadow: none;
        }

        .btn:not(:disabled):hover {
            box-shadow: 
                0 0 10px var(--btn-color),
                0 0 20px var(--btn-color),
                0 0 40px var(--btn-color);
            text-shadow: 0 0 5px rgba(255,255,255,0.5);
            transform: translateY(-2px);
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
    </style>
</head>
<body>
    <!-- Post-processing effects -->
    <div class="post-processing">
        <div class="noise"></div>
        <div class="scanlines"></div>
        <div class="vignette"></div>
    </div>
    <div class="starfield-container" id="starfield">
        <div class="galaxy-core"></div>
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
				<div class="slots-ad">
    <div class="ad-header">
        <i class="fas fa-slot-machine"></i>
        <h2>Try Your Luck at Epic Slots!</h2>
        <i class="fas fa-slot-machine"></i>
    </div>
    <div class="ad-content">
        <div class="preview-container">
            <iframe src="http://jcmc.serveminecraft.net/games/slots/" style="width: 100%; height: 800px; border: none;"></iframe>
        </div>
        <div class="ad-text">
            <h3>🎰 Epic Slots Features:</h3>
            <ul>
                <li>⭐ Multiple Exciting Themes</li>
                <li>💰 Massive Jackpots</li>
                <li>🎉 Daily Bonuses</li>
                <li>🔥 Progressive Multipliers</li>
            </ul>
            <a href="http://jcmc.serveminecraft.net/games/slots/" class="btn btn-primary">Play Now!</a>
        </div>
    </div>
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

                </div><iframe src="chats/chat.php" style="width: 100%; height: 600px; border: none;"></iframe>
<!-- Add below chat iframe in sidebar -->
<div class="market-ad">
    <div class="ad-header">
        <i class="fas fa-coins"></i>
        <h2>CyberCoin II Market</h2>
        <span class="live-badge">Now Live!</span>
    </div>

    <div class="exchange-rates">
        <h3><i class="fas fa-exchange-alt"></i> Exchange Rates</h3>
        <p class="rate-main">5000 CCII ↔ 1000 EXP</p>
        <p class="rate-fiat">Rate: $0.00001 per CCII</p>
    </div>

    <ul class="benefits">
        <li><i class="fas fa-check-circle"></i> Trade CCII for EXP to play Blackjack</li>
        <li><i class="fas fa-check-circle"></i> Instant transactions, no waiting</li>
        <li><i class="fas fa-check-circle"></i> Own tradeable cryptocurrency</li>
    </ul>

    <a href="http://jcmc.serveminecraft.net/cybercoin/shop.php" class="market-btn">Visit Crypto Market</a>
</div>

<style>
.market-ad {
    background: linear-gradient(135deg, #1a1a2e, #152238);
    border: 1px solid #3498db;
    border-radius: 15px;
    padding: 20px;
    margin-top: 20px;
    color: white;
}

.ad-header {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 20px;
}

.ad-header h2 {
    color: #3498db;
    margin: 0;
    font-size: 1.5em;
}

.live-badge {
    background: #2ecc71;
    padding: 5px 10px;
    border-radius: 20px;
    font-size: 0.8em;
}

.exchange-rates {
    background: rgba(0,0,0,0.3);
    padding: 15px;
    border-radius: 10px;
    margin-bottom: 20px;
}

.rate-main {
    font-size: 1.2em;
    color: #2ecc71;
    margin: 10px 0;
}

.rate-fiat {
    color: #95a5a6;
    font-size: 0.9em;
}

.benefits {
    list-style: none;
    padding: 0;
    margin: 20px 0;
}

.benefits li {
    margin: 10px 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.benefits i {
    color: #2ecc71;
}

.market-btn {
    display: block;
    background: linear-gradient(45deg, #3498db, #2980b9);
    color: white;
    text-decoration: none;
    text-align: center;
    padding: 12px;
    border-radius: 8px;
    font-weight: bold;
    transition: transform 0.2s;
}

.market-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(52, 152, 219, 0.3);
}
</style>
            </div>
        </div>
		

    </div>
<iframe src="http://jcmc.serveminecraft.net/widgets/purchaseCurrency/" style="width: 100%; height: 1200px; border: none;"></iframe>
    <!-- Sound Effects -->
    <audio id="cardSound" src="data:audio/mp3;base64,[BASE64_CARD_SOUND]" preload="auto"></audio>
    <audio id="chipSound" src="data:audio/mp3;base64,[BASE64_CHIP_SOUND]" preload="auto"></audio>
    <audio id="winSound" src="data:audio/mp3;base64,[BASE64_WIN_SOUND]" preload="auto"></audio>
    <audio id="loseSound" src="data:audio/mp3;base64,[BASE64_LOSE_SOUND]" preload="auto"></audio>
    <script>
        class SpiralArm {
            constructor(container, numStars, angleOffset, armIndex) {
                this.container = container;
                this.armElement = document.createElement('div');
                this.armElement.className = 'spiral-arm';
                this.armElement.style.transform = `rotate(${angleOffset}rad)`;
                container.appendChild(this.armElement);
                
                this.createStars(numStars, maxRadius);
                
                // Use CSS animation for rotation
                this.armElement.style.animation = `rotate ${30 + armIndex * 10}s linear infinite`;
            }

            createStars(numStars, maxRadius) {
                const fragment = document.createDocumentFragment();
                const colors = ['#fff', '#ffd700'];
                
                for (let i = 0; i < numStars; i++) {
                    const progress = i / numStars;
                    const radius = maxRadius * Math.pow(progress, 0.5);
                    const angle = progress * Math.PI * 4; // Tighter spiral
                    
                    const x = radius * Math.cos(angle);
                    const y = radius * Math.sin(angle);
                    const z = (Math.random() - 0.5) * 1000 * (1 - progress);
                    
                    const star = document.createElement('div');
                    star.className = 'star';
                    star.style.transform = `translate3d(${x}px, ${y}px, ${z}px)`;
                    star.style.width = `${(2 - progress)}px`;
                    star.style.height = `${(2 - progress)}px`;
                    star.style.background = colors[Math.floor(Math.random() * colors.length)];
                    star.style.opacity = 0.5 + Math.random() * 0.5;
                    
                    fragment.appendChild(star);
                }
                
                this.armElement.appendChild(fragment);
            }
        }

        class Galaxy {
            constructor() {
                this.container = document.getElementById('starfield');
                this.arms = [];
                
                // Calculate optimal size
                const screenSize = Math.max(window.innerWidth, window.innerHeight);
                window.maxRadius = screenSize * 0.6; // Make it bigger
                
                this.initGalaxy();
            }

            initGalaxy() {
                // Just 2 main arms
                for (let i = 0; i < 2; i++) {
                    const angleOffset = (i * Math.PI);
                    const arm = new SpiralArm(
                        this.container,
                        200, // Fewer stars for better performance
                        angleOffset,
                        i
                    );
                    this.arms.push(arm);
                }

                // Add fewer center stars
                this.addCenterStars(200);
            }

            addCenterStars(numStars) {
                const centerContainer = document.createElement('div');
                centerContainer.className = 'spiral-arm';
                const fragment = document.createDocumentFragment();
                
                for (let i = 0; i < numStars; i++) {
                    const radius = Math.random() * maxRadius * 0.2;
                    const angle = Math.random() * Math.PI * 2;
                    const x = radius * Math.cos(angle);
                    const y = radius * Math.sin(angle);
                    const z = (Math.random() - 0.5) * 200;
                    
                    const star = document.createElement('div');
                    star.className = 'star';
                    star.style.transform = `translate3d(${x}px, ${y}px, ${z}px)`;
                    star.style.width = '2px';
                    star.style.height = '2px';
                    star.style.background = '#fff';
                    star.style.opacity = 0.7 + Math.random() * 0.3;
                    
                    fragment.appendChild(star);
                }
                
                centerContainer.appendChild(fragment);
                this.container.appendChild(centerContainer);
            }
        }

        window.addEventListener('DOMContentLoaded', () => {
            const galaxy = new Galaxy();

            let resizeTimeout;
            window.addEventListener('resize', () => {
                clearTimeout(resizeTimeout);
                resizeTimeout = setTimeout(() => {
                    galaxy.container.innerHTML = '';
                    galaxy.container.appendChild(document.createElement('div')).className = 'galaxy-core';
                    galaxy.arms = [];
                    galaxy.initGalaxy();
                }, 250);
            });
        });
    </script>
    <script>
	class SecurityHandler {
    constructor(game) {
        this.game = game;
        this.securityKey = null;
    }

    async handleLogin(username) {
        try {
            const response = await fetch('security.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'createSession',
                    username: username
                })
            });

            const data = await response.json();
            
            if (data.success) {
                // Show security key dialog
                this.showSecurityKeyDialog(data.security_key);
                return true;
            } else {
                this.game.showMessage(data.message, 'error');
                return false;
            }
        } catch (error) {
            console.error('Security error:', error);
            this.game.showMessage('Failed to create secure session', 'error');
            return false;
        }
    }

    showSecurityKeyDialog(key) {
        const dialog = document.createElement('div');
        dialog.className = 'security-dialog';
        dialog.innerHTML = `
            <div class="security-content">
                <h2>🔐 Security Key Generated</h2>
                <p>IMPORTANT: Save this security key in a safe place. You will need it to access your account in the future:</p>
                <div class="key-display">${key}</div>
                <button id="copyKey" class="btn btn-primary">
                    <i class="fas fa-copy"></i> Copy Key
                </button>
                <button id="confirmKey" class="btn btn-success">
                    <i class="fas fa-check"></i> I've Saved It
                </button>
            </div>
        `;

        document.body.appendChild(dialog);

        // Add copy functionality
        document.getElementById('copyKey').addEventListener('click', () => {
            navigator.clipboard.writeText(key);
            this.game.showMessage('Security key copied to clipboard', 'success');
        });

        // Add confirmation handling
        document.getElementById('confirmKey').addEventListener('click', () => {
            dialog.remove();
            this.game.showMessage('Security key saved. Keep it safe!', 'success');
        });
    }

    async validateSecurityKey(username, key) {
        try {
            const response = await fetch('security.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'validateSession',
                    username: username,
                    security_key: key
                })
            });

            const data = await response.json();
            return data;
        } catch (error) {
            console.error('Validation error:', error);
            return { success: false, message: 'Failed to validate security key' };
        }
    }
}
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

        // Create front face with enhanced graphics
        const frontFace = document.createElement('div');
        frontFace.className = 'card-face card-front';

        // Add card content structure
        const content = document.createElement('div');
        content.className = 'card-content';

        // Top left corner
        const topLeft = document.createElement('div');
        topLeft.className = 'card-corner top-left';
        topLeft.innerHTML = `
            <span>${card.face}</span>
            <span>${card.suit}</span>
        `;

        // Center symbol
        const center = document.createElement('div');
        center.className = 'card-center';
        center.innerHTML = `${card.suit}`;

        // Bottom right corner (rotated)
        const bottomRight = document.createElement('div');
        bottomRight.className = 'card-corner bottom-right';
        bottomRight.innerHTML = `
            <span>${card.face}</span>
            <span>${card.suit}</span>
        `;

        content.appendChild(topLeft);
        content.appendChild(center);
        content.appendChild(bottomRight);
        frontFace.appendChild(content);

        // Create back face with pattern
        const backFace = document.createElement('div');
        backFace.className = 'card-face card-back';

        // Add faces to card
        cardElement.appendChild(frontFace);
        cardElement.appendChild(backFace);

        // Add to container
        container.appendChild(cardElement);

        // Add dynamic shadow effect based on mouse movement
        this.addCardShadowEffect(cardElement);
    }

    addCardShadowEffect(cardElement) {
        const table = document.querySelector('.game-table');
        
        table.addEventListener('mousemove', (e) => {
            const rect = cardElement.getBoundingClientRect();
            const centerX = rect.left + rect.width / 2;
            const centerY = rect.top + rect.height / 2;
            
            const deltaX = (e.clientX - centerX) / (table.clientWidth / 2);
            const deltaY = (e.clientY - centerY) / (table.clientHeight / 2);
            
            // Calculate rotation based on mouse position
            const rotateX = deltaY * -10;
            const rotateY = deltaX * 10;
            
            // Apply dynamic transform
            cardElement.style.transform = `
                translateZ(20px)
                rotateX(${rotateX}deg)
                rotateY(${rotateY}deg)
            `;

            // Update shadow position
            const shadowDistance = Math.min(Math.abs(deltaX * 30), 40);
            const shadowX = deltaX * 20;
            cardElement.style.filter = `drop-shadow(${shadowX}px ${shadowDistance}px ${shadowDistance/2}px rgba(0,0,0,0.4))`;
        });

        // Reset on mouse leave
        table.addEventListener('mouseleave', () => {
            cardElement.style.transform = 'translateZ(20px)';
            cardElement.style.filter = 'drop-shadow(0 10px 20px rgba(0,0,0,0.4))';
        });
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