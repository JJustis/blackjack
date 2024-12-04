<?php
// config.php - Database configuration
$conn = new mysqli('localhost', 'root', '', 'reservesphp');
if ($conn->connect_error) {
    die(json_encode(['error' => 'Connection failed']));
}

// Classes for card handling
class Card {
    public $suit;
    public $rank;
    public $value;
    public $display;
    
    public function __construct($suit, $rank, $value, $display) {
        $this->suit = $suit;
        $this->rank = $rank;
        $this->value = $value;
        $this->display = $display;
    }
    
    public function toArray() {
        return [
            'suit' => $this->suit,
            'rank' => $this->rank,
            'value' => $this->value,
            'display' => $this->display,
            'isRed' => in_array($this->suit, ['♥', '♦'])
        ];
    }
}

class Hand {
    public $cards = [];
    public $bet = 0;
    public $status = 'active';
    public $isBlackjack = false;
    public $canSplit = false;
    public $canDouble = true;
    public $insurance = 0;
    
    public function addCard(Card $card) {
        $this->cards[] = $card;
        $this->updateStatus();
    }
    
    public function getValue() {
        $total = 0;
        $aces = 0;
        
        foreach ($this->cards as $card) {
            if ($card->rank === 'A') {
                $aces++;
            }
            $total += $card->value;
        }
        
        while ($total > 21 && $aces > 0) {
            $total -= 10;
            $aces--;
        }
        
        return $total;
    }
    
    public function updateStatus() {
        $value = $this->getValue();
        
        if ($value > 21) {
            $this->status = 'bust';
        } elseif ($value === 21 && count($this->cards) === 2) {
            $this->status = 'blackjack';
            $this->isBlackjack = true;
        }
        
        // Check for split possibility
        if (count($this->cards) === 2) {
            $this->canSplit = ($this->cards[0]->rank === $this->cards[1]->rank);
        } else {
            $this->canSplit = false;
        }
        
        // Can only double on first two cards
        $this->canDouble = (count($this->cards) === 2);
    }
    
    public function toArray() {
        return [
            'cards' => array_map(function($card) { return $card->toArray(); }, $this->cards),
            'value' => $this->getValue(),
            'bet' => $this->bet,
            'status' => $this->status,
            'isBlackjack' => $this->isBlackjack,
            'canSplit' => $this->canSplit,
            'canDouble' => $this->canDouble,
            'insurance' => $this->insurance
        ];
    }
}

class Deck {
    private $cards = [];
    
    public function __construct($numberOfDecks = 6) {
        $suits = ['♠', '♥', '♦', '♣'];
        $ranks = [
            'A' => [11, 'A'],
            '2' => [2, '2'],
            '3' => [3, '3'],
            '4' => [4, '4'],
            '5' => [5, '5'],
            '6' => [6, '6'],
            '7' => [7, '7'],
            '8' => [8, '8'],
            '9' => [9, '9'],
            '10' => [10, '10'],
            'J' => [10, 'J'],
            'Q' => [10, 'Q'],
            'K' => [10, 'K']
        ];
        
        for ($d = 0; $d < $numberOfDecks; $d++) {
            foreach ($suits as $suit) {
                foreach ($ranks as $rank => [$value, $display]) {
                    $this->cards[] = new Card($suit, $rank, $value, $display);
                }
            }
        }
        
        $this->shuffle();
    }
    
    public function shuffle() {
        shuffle($this->cards);
    }
    
    public function draw() {
        return array_pop($this->cards);
    }
}

class BlackjackGame {
    public $deck;
    public $playerHands = [];
    public $dealerHand;
    public $currentHand = 0;
    public $status = 'betting';
    public $username;
    public $totalBet = 0;
    
    public function __construct($username) {
        $this->deck = new Deck();
        $this->dealerHand = new Hand();
        $this->username = $username;
    }
    
    public function startHand($bet) {
        $this->status = 'playing';
        $this->playerHands = [];
        $this->dealerHand = new Hand();
        
        // Create first player hand
        $playerHand = new Hand();
        $playerHand->bet = $bet;
        $this->totalBet = $bet;
        
        // Initial deal
        $playerHand->addCard($this->deck->draw());
        $this->dealerHand->addCard($this->deck->draw());
        $playerHand->addCard($this->deck->draw());
        
        $this->playerHands[] = $playerHand;
        $this->currentHand = 0;
        
        return true;
    }
    
    public function hit($handIndex = null) {
        if ($handIndex === null) {
            $handIndex = $this->currentHand;
        }
        
        if (isset($this->playerHands[$handIndex])) {
            $this->playerHands[$handIndex]->addCard($this->deck->draw());
            
            if ($this->playerHands[$handIndex]->status === 'bust') {
                $this->nextHand();
            }
        }
    }
    
    public function stand($handIndex = null) {
        if ($handIndex === null) {
            $handIndex = $this->currentHand;
        }
        
        if (isset($this->playerHands[$handIndex])) {
            $this->playerHands[$handIndex]->status = 'stand';
            $this->nextHand();
        }
    }
    
    public function split($handIndex = null) {
        if ($handIndex === null) {
            $handIndex = $this->currentHand;
        }
        
        if (!isset($this->playerHands[$handIndex]) || !$this->playerHands[$handIndex]->canSplit) {
            return false;
        }
        
        // Create new hand with second card
        $newHand = new Hand();
        $newHand->bet = $this->playerHands[$handIndex]->bet;
        $this->totalBet += $newHand->bet;
        
        // Move second card to new hand
        $newHand->addCard(array_pop($this->playerHands[$handIndex]->cards));
        
        // Deal new cards to both hands
        $this->playerHands[$handIndex]->addCard($this->deck->draw());
        $newHand->addCard($this->deck->draw());
        
        // Insert new hand after current hand
        array_splice($this->playerHands, $handIndex + 1, 0, [$newHand]);
        
        return true;
    }
    
    public function double($handIndex = null) {
        if ($handIndex === null) {
            $handIndex = $this->currentHand;
        }
        
        if (!isset($this->playerHands[$handIndex]) || !$this->playerHands[$handIndex]->canDouble) {
            return false;
        }
        
        // Double the bet
        $this->totalBet += $this->playerHands[$handIndex]->bet;
        $this->playerHands[$handIndex]->bet *= 2;
        
        // Deal one card and stand
        $this->playerHands[$handIndex]->addCard($this->deck->draw());
        $this->stand($handIndex);
        
        return true;
    }
    
    public function insurance() {
        if ($this->dealerHand->cards[0]->rank !== 'A' || $this->status !== 'playing') {
            return false;
        }
        
        foreach ($this->playerHands as $hand) {
            $hand->insurance = $hand->bet / 2;
            $this->totalBet += $hand->insurance;
        }
        
        return true;
    }
    
    private function nextHand() {
        $this->currentHand++;
        
        if ($this->currentHand >= count($this->playerHands)) {
            $this->finishDealerHand();
        }
    }
    
    private function finishDealerHand() {
        // Deal dealer's hole card
        $this->dealerHand->addCard($this->deck->draw());
        
        // Dealer must hit on soft 17
        while ($this->dealerHand->getValue() < 17) {
            $this->dealerHand->addCard($this->deck->draw());
        }
        
        $this->status = 'complete';
        $this->settleHands();
    }
    
    private function settleHands() {
        global $conn;
        $dealerValue = $this->dealerHand->getValue();
        $dealerBlackjack = $this->dealerHand->isBlackjack;
        $totalWinnings = 0;
        
        foreach ($this->playerHands as $hand) {
            $winnings = 0;
            
            // Handle insurance first
            if ($hand->insurance > 0) {
                if ($dealerBlackjack) {
                    $winnings += $hand->insurance * 2;
                } else {
                    $winnings -= $hand->insurance;
                }
            }
            
            if ($hand->status === 'bust') {
                $winnings -= $hand->bet;
            } elseif ($dealerBlackjack) {
                if ($hand->isBlackjack) {
                    // Push on double blackjack
                    $winnings += $hand->bet;
                } else {
                    $winnings -= $hand->bet;
                }
            } elseif ($hand->isBlackjack) {
                // Player blackjack pays 3:2
                $winnings += $hand->bet * 2.5;
            } elseif ($dealerValue > 21 || $hand->getValue() > $dealerValue) {
                $winnings += $hand->bet * 2;
            } elseif ($hand->getValue() === $dealerValue) {
                $winnings += $hand->bet;
            } else {
                $winnings -= $hand->bet;
            }
            
            $totalWinnings += $winnings;
        }
        
        // Update user's EXP
        $stmt = $conn->prepare("UPDATE members SET exp = exp + ? WHERE username = ?");
        $stmt->bind_param("is", $totalWinnings, $this->username);
        $stmt->execute();
    }
    
    public function toArray($hideDealer = true) {
        $state = [
            'status' => $this->status,
            'currentHand' => $this->currentHand,
            'totalBet' => $this->totalBet,
            'playerHands' => array_map(function($hand) { return $hand->toArray(); }, $this->playerHands),
            'dealerHand' => $hideDealer ? 
                array_merge($this->dealerHand->toArray(), ['cards' => [array_shift($this->dealerHand->cards)->toArray()]]) :
                $this->dealerHand->toArray()
        ];
        
        // Get current EXP
        global $conn;
        $stmt = $conn->prepare("SELECT exp FROM members WHERE username = ?");
        $stmt->bind_param("s", $this->username);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $state['currentExp'] = $user['exp'];
        
        return $state;
    }
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    $username = $_POST['username'] ?? '';
    
    if ($action === 'checkExp') {
        $stmt = $conn->prepare("SELECT exp FROM members WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();
            echo json_encode(['success' => true, 'exp' => $user['exp']]);
        } else {
            echo json_encode(['success' => false, 'error' => 'User not found']);
        }
        exit;
    }
    
    if (!isset($_SESSION['game'])) {
        $_SESSION['game'] = new BlackjackGame($username);
    }
    
    $game = $_SESSION['game'];
    
    switch ($action) {
        case 'start':
            $bet = (int)($_POST['bet'] ?? 0);
            
            // Verify user has enough EXP
            $stmt = $conn->prepare("SELECT exp FROM members WHERE username = ?");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            
            if ($user['exp'] < $bet) {
                echo json_encode(['error' => 'Not enough EXP']);
                exit;
            }
            
            $game->startHand($bet);
            break;
            
        case 'hit':
            $game->hit();
            break;
            
        case 'stand':
            $game->stand();
            break;
            
        case 'split':
            if (!$game->split()) {
                echo json_encode(['error' => 'Cannot split']);
                exit;
            }
            break;
            
        case 'double':
            if (!$game->double()) {
                echo json_encode(['error' => 'Cannot double']);
                exit;
            }
            break;
            
        case 'insurance':
            if (!$game->insurance()) {
                echo json_encode(['error' => 'Cannot take insurance']);
                exit;
            }
            break;
    }
    
    echo json_encode($game->toArray($game->status !== 'complete'));
    exit;
}
?>