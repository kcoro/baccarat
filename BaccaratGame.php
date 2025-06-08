<?php

class Card {
    public $suit;
    public $rank;
    
    public function __construct($suit, $rank) {
        $this->suit = $suit;
        $this->rank = $rank;
    }
    
    public function getValue() {
        if ($this->rank === 'A') return 1;
        if (in_array($this->rank, ['J', 'Q', 'K'])) return 0;
        return (int)$this->rank;
    }
    
    public function __toString() {
        return $this->rank . $this->suit;
    }
}

class Deck {
    private $cards = [];
    
    public function __construct() {
        $suits = ['♠', '♥', '♦', '♣'];
        $ranks = ['A', '2', '3', '4', '5', '6', '7', '8', '9', '10', 'J', 'Q', 'K'];
        
        foreach ($suits as $suit) {
            foreach ($ranks as $rank) {
                $this->cards[] = new Card($suit, $rank);
            }
        }
        
        $this->shuffle();
    }
    
    public function shuffle() {
        shuffle($this->cards);
    }
    
    public function dealCard() {
        return array_pop($this->cards);
    }
    
    public function remainingCards() {
        return count($this->cards);
    }
}

class BaccaratGame {
    private $deck;
    private $playerHand = [];
    private $bankerHand = [];
    private $gameState = 'betting'; // betting, dealing, finished
    private $result = null;
    private $playerScore = 0;
    private $bankerScore = 0;
    
    public function __construct() {
        $this->deck = new Deck();
    }
    
    public function newGame() {
        $this->deck = new Deck();
        $this->playerHand = [];
        $this->bankerHand = [];
        $this->gameState = 'betting';
        $this->result = null;
        $this->playerScore = 0;
        $this->bankerScore = 0;
    }
    
    public function dealInitialCards() {
        if ($this->gameState !== 'betting') return false;
        
        $this->gameState = 'dealing';
        
        // Deal two cards to each hand
        $this->playerHand[] = $this->deck->dealCard();
        $this->bankerHand[] = $this->deck->dealCard();
        $this->playerHand[] = $this->deck->dealCard();
        $this->bankerHand[] = $this->deck->dealCard();
        
        $this->calculateScores();
        $this->applyDrawingRules();
        $this->determineWinner();
        
        return true;
    }
    
    private function calculateScores() {
        $this->playerScore = $this->getHandValue($this->playerHand);
        $this->bankerScore = $this->getHandValue($this->bankerHand);
    }
    
    private function getHandValue($hand) {
        $total = 0;
        foreach ($hand as $card) {
            $total += $card->getValue();
        }
        return $total % 10;
    }
    
    private function applyDrawingRules() {
        $playerNatural = $this->playerScore >= 8;
        $bankerNatural = $this->bankerScore >= 8;
        
        // Natural win - no more cards
        if ($playerNatural || $bankerNatural) {
            return;
        }
        
        $playerThirdCard = null;
        
        // Player drawing rule
        if ($this->playerScore <= 5) {
            $playerThirdCard = $this->deck->dealCard();
            $this->playerHand[] = $playerThirdCard;
            $this->calculateScores();
        }
        
        // Banker drawing rules
        if ($playerThirdCard === null) {
            // Player stood
            if ($this->bankerScore <= 5) {
                $this->bankerHand[] = $this->deck->dealCard();
                $this->calculateScores();
            }
        } else {
            // Player drew third card
            $thirdCardValue = $playerThirdCard->getValue();
            $shouldDraw = false;
            
            switch ($this->bankerScore) {
                case 0:
                case 1:
                case 2:
                    $shouldDraw = true;
                    break;
                case 3:
                    $shouldDraw = $thirdCardValue !== 8;
                    break;
                case 4:
                    $shouldDraw = in_array($thirdCardValue, [2, 3, 4, 5, 6, 7]);
                    break;
                case 5:
                    $shouldDraw = in_array($thirdCardValue, [4, 5, 6, 7]);
                    break;
                case 6:
                    $shouldDraw = in_array($thirdCardValue, [6, 7]);
                    break;
                case 7:
                    $shouldDraw = false;
                    break;
            }
            
            if ($shouldDraw) {
                $this->bankerHand[] = $this->deck->dealCard();
                $this->calculateScores();
            }
        }
    }
    
    private function determineWinner() {
        $this->gameState = 'finished';
        
        if ($this->playerScore > $this->bankerScore) {
            $this->result = 'player';
        } elseif ($this->bankerScore > $this->playerScore) {
            $this->result = 'banker';
        } else {
            $this->result = 'tie';
        }
    }
    
    public function getGameState() {
        return [
            'state' => $this->gameState,
            'playerHand' => $this->playerHand,
            'bankerHand' => $this->bankerHand,
            'playerScore' => $this->playerScore,
            'bankerScore' => $this->bankerScore,
            'result' => $this->result
        ];
    }
    
    public function calculatePayout($bet, $amount) {
        if ($this->result === null) return 0;
        
        switch ($bet) {
            case 'player':
                return $this->result === 'player' ? $amount * 2 : 0; // Return bet + winnings
            case 'banker':
                return $this->result === 'banker' ? $amount * 1.95 : 0; // Return bet + winnings (5% commission)
            case 'tie':
                return $this->result === 'tie' ? $amount * 9 : 0; // Return bet + winnings (8:1 payout)
            default:
                return 0;
        }
    }
    
    public function calculateWinnings($bet, $amount) {
        if ($this->result === null) return -$amount; // Lost bet
        
        switch ($bet) {
            case 'player':
                return $this->result === 'player' ? $amount : -$amount; // Win amount or lose bet
            case 'banker':
                return $this->result === 'banker' ? $amount * 0.95 : -$amount; // Win with 5% commission or lose bet
            case 'tie':
                return $this->result === 'tie' ? $amount * 8 : -$amount; // Win 8:1 or lose bet
            default:
                return -$amount;
        }
    }
}