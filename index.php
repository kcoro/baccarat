<?php
session_start();
require_once 'BaccaratGame.php';

// Initialize game if not exists
if (!isset($_SESSION['game'])) {
    $_SESSION['game'] = serialize(new BaccaratGame());
    $_SESSION['balance'] = 1000; // Starting balance
    $_SESSION['current_bets'] = []; // Multiple bets support
}

// Ensure current_bets is always set (for existing sessions)
if (!isset($_SESSION['current_bets'])) {
    $_SESSION['current_bets'] = [];
}

// Ensure game_history is always set
if (!isset($_SESSION['game_history'])) {
    $_SESSION['game_history'] = [];
}

$game = unserialize($_SESSION['game']);
$balance = $_SESSION['balance'];
$currentBets = $_SESSION['current_bets'] ?? [];

// Handle HTMX requests
if (isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'new_game':
            // Process any pending bets first
            $currentGameState = $game->getGameState();
            if (isset($_SESSION['current_bets']) && !empty($_SESSION['current_bets']) && $currentGameState['state'] === 'finished') {
                // Add result to history
                $_SESSION['game_history'][] = $currentGameState['result'];
                
                // Keep only last 100 games
                if (count($_SESSION['game_history']) > 100) {
                    $_SESSION['game_history'] = array_slice($_SESSION['game_history'], -100);
                }
                
                $totalPayout = 0;
                foreach ($_SESSION['current_bets'] as $betType => $betAmount) {
                    $payout = $game->calculatePayout($betType, $betAmount);
                    $totalPayout += $payout;
                }
                $_SESSION['balance'] += $totalPayout;
            }
            $game->newGame();
            $_SESSION['game'] = serialize($game);
            $_SESSION['current_bets'] = [];
            session_write_close();
            session_start();
            break;
            
        case 'place_bet':
            if (isset($_POST['bet_type']) && isset($_POST['bet_amount'])) {
                $betAmount = (int)$_POST['bet_amount'];
                $betType = $_POST['bet_type'];
                
                if ($betAmount > 0 && $betAmount <= $balance) {
                    if (!isset($_SESSION['current_bets'][$betType])) {
                        $_SESSION['current_bets'][$betType] = 0;
                    }
                    $_SESSION['current_bets'][$betType] += $betAmount;
                    $_SESSION['balance'] -= $betAmount;
                }
            }
            break;
            
        case 'deal':
            if (!empty($_SESSION['current_bets'])) {
                // For now, let's use instant dealing but add progressive later
                $game->dealAllCardsAtOnce();
                $_SESSION['game'] = serialize($game);
                
                // Record the result in history
                $currentGameState = $game->getGameState();
                if ($currentGameState['state'] === 'finished') {
                    $_SESSION['game_history'][] = $currentGameState['result'];
                    
                    // Keep only last 100 games
                    if (count($_SESSION['game_history']) > 100) {
                        $_SESSION['game_history'] = array_slice($_SESSION['game_history'], -100);
                    }
                }
            }
            break;
            
        case 'clear_bets':
            $_SESSION['balance'] += array_sum($_SESSION['current_bets'] ?? []);
            $_SESSION['current_bets'] = [];
            break;
            
        case 'undo_bet':
            if (!empty($_SESSION['current_bets'])) {
                // Remove the last bet placed (simple undo - removes one chip value from the last bet type)
                end($_SESSION['current_bets']);
                $lastBetType = key($_SESSION['current_bets']);
                if ($_SESSION['current_bets'][$lastBetType] > 0) {
                    // Determine the chip value to remove (assume it was the selected chip value or smallest available)
                    $undoAmount = min($_SESSION['current_bets'][$lastBetType], 1); // Minimum 1 for now
                    $_SESSION['current_bets'][$lastBetType] -= $undoAmount;
                    $_SESSION['balance'] += $undoAmount;
                    
                    if ($_SESSION['current_bets'][$lastBetType] <= 0) {
                        unset($_SESSION['current_bets'][$lastBetType]);
                    }
                }
            }
            break;
            
        case 'auto_progress':
            if (isset($_SESSION['current_bets']) && !empty($_SESSION['current_bets'])) {
                // Make sure we have the current game state
                $currentGameState = $game->getGameState();
                if ($currentGameState['state'] === 'finished') {
                    // Add result to history
                    $_SESSION['game_history'][] = $currentGameState['result'];
                    
                    // Keep only last 100 games
                    if (count($_SESSION['game_history']) > 100) {
                        $_SESSION['game_history'] = array_slice($_SESSION['game_history'], -100);
                    }
                    
                    $totalPayout = 0;
                    foreach ($_SESSION['current_bets'] as $betType => $betAmount) {
                        $payout = $game->calculatePayout($betType, $betAmount);
                        $totalPayout += $payout;
                    }
                    
                    $_SESSION['balance'] += $totalPayout;
                }
                $_SESSION['current_bets'] = [];
                $game->newGame();
                $_SESSION['game'] = serialize($game);
                session_write_close();
                session_start();
            }
            break;
    }
}

$gameState = $game->getGameState();
// Refresh variables after potential updates
$balance = $_SESSION['balance'];
$currentBets = $_SESSION['current_bets'] ?? [];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Baccarat - Punto Banco</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/htmx.org@1.9.10"></script>
    <script>
        let countdownTimer = null;
        let secondsLeft = 3;
        
        function startCountdown() {
            const countdownText = document.querySelector('#countdown-text');
            const progressBar = document.querySelector('#progress-bar');
            
            if (!countdownText) return;
            
            secondsLeft = 3;
            
            function updateCountdown() {
                if (countdownText) {
                    countdownText.textContent = `Auto-starting new round in ${secondsLeft} seconds...`;
                }
                
                if (progressBar) {
                    const percentage = (secondsLeft / 3) * 100;
                    progressBar.style.width = percentage + '%';
                }
                
                if (secondsLeft <= 0) {
                    clearInterval(countdownTimer);
                    const autoBtn = document.querySelector('#auto-progress-btn');
                    if (autoBtn) {
                        autoBtn.click();
                    }
                } else {
                    secondsLeft--;
                }
            }
            
            // Clear any existing timer
            if (countdownTimer) {
                clearInterval(countdownTimer);
            }
            
            // Start immediate update then interval
            updateCountdown();
            countdownTimer = setInterval(updateCountdown, 1000);
        }
        
        // Start countdown when auto-progress elements are detected
        function checkAndStartCountdown() {
            if (document.querySelector('#auto-progress-btn')) {
                // Check if there are cards animating
                const animatingCards = document.querySelectorAll('.card-large');
                if (animatingCards.length > 0) {
                    // Calculate how long to wait for all cards to finish animating
                    const totalAnimationTime = calculateTotalAnimationTime();
                    console.log(`Delaying countdown by ${totalAnimationTime}ms for card animations`);
                    
                    // Wait for all cards to finish, then show results and start countdown
                    setTimeout(() => {
                        showGameResults();
                        startCountdown();
                    }, totalAnimationTime);
                } else {
                    // No card animations, show results and start countdown immediately
                    showGameResults();
                    startCountdown();
                }
            }
        }
        
        // Show the game results panel and scores
        function showGameResults() {
            const resultsPanel = document.getElementById('game-results');
            const bankerScore = document.getElementById('banker-score');
            const playerScore = document.getElementById('player-score');
            const bankerCardCount = document.getElementById('banker-card-count');
            const playerCardCount = document.getElementById('player-card-count');
            
            if (resultsPanel) {
                resultsPanel.classList.remove('results-hidden');
                resultsPanel.classList.add('results-visible');
            }
            
            // Show actual scores and hide card counts
            if (bankerScore && bankerCardCount) {
                bankerScore.classList.remove('results-hidden');
                bankerScore.classList.add('results-visible');
                bankerCardCount.style.display = 'none';
            }
            
            if (playerScore && playerCardCount) {
                playerScore.classList.remove('results-hidden');
                playerScore.classList.add('results-visible');
                playerCardCount.style.display = 'none';
            }
            
            console.log('Game results and scores now visible');
        }
        
        // Listen for HTMX events
        document.addEventListener('htmx:afterSwap', function() {
            setTimeout(checkAndStartCountdown, 100);
        });
        
        // Initial check on page load
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(checkAndStartCountdown, 100);
        });
        
        // Calculate total card animation time
        function calculateTotalAnimationTime() {
            const allCards = document.querySelectorAll('.card-large');
            let maxDelay = 0;
            
            allCards.forEach(card => {
                const animationDelay = parseFloat(getComputedStyle(card).animationDelay) || 0;
                maxDelay = Math.max(maxDelay, animationDelay);
            });
            
            // Convert to milliseconds and add animation duration (0.6s)
            return (maxDelay + 0.6) * 1000;
        }
        
        // Cleanup
        window.addEventListener('beforeunload', function() {
            if (countdownTimer) clearInterval(countdownTimer);
        });
        
        // Chip and betting functionality
        let selectedChipValue = 0;
        
        function selectChip(value) {
            selectedChipValue = value;
            
            // Remove selected class from all chips
            document.querySelectorAll('.chip').forEach(chip => {
                chip.classList.remove('selected');
            });
            
            // Add selected class to clicked chip
            document.querySelector(`[data-value="${value}"]`).classList.add('selected');
        }
        
        function placeBet(betType) {
            if (selectedChipValue === 0) {
                alert('Please select a chip first!');
                return;
            }
            
            // Create hidden form and submit bet
            const form = document.createElement('form');
            form.method = 'POST';
            form.setAttribute('hx-post', 'index.php');
            form.setAttribute('hx-target', 'body');
            form.setAttribute('hx-swap', 'outerHTML');
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'place_bet';
            
            const betTypeInput = document.createElement('input');
            betTypeInput.type = 'hidden';
            betTypeInput.name = 'bet_type';
            betTypeInput.value = betType;
            
            const betAmountInput = document.createElement('input');
            betAmountInput.type = 'hidden';
            betAmountInput.name = 'bet_amount';
            betAmountInput.value = selectedChipValue;
            
            form.appendChild(actionInput);
            form.appendChild(betTypeInput);
            form.appendChild(betAmountInput);
            
            document.body.appendChild(form);
            htmx.process(form);
            form.submit();
        }
        
        function startDeal() {
            // Deal all cards at once (server-side), then animate them progressively (client-side)
            const form = document.createElement('form');
            form.method = 'POST';
            form.setAttribute('hx-post', 'index.php');
            form.setAttribute('hx-target', 'body');
            form.setAttribute('hx-swap', 'outerHTML');
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'deal';
            
            form.appendChild(actionInput);
            document.body.appendChild(form);
            htmx.process(form);
            form.submit();
        }
        
        // No JavaScript needed - using pure CSS animations
        
        function clearAllBets() {
            const form = document.createElement('form');
            form.method = 'POST';
            form.setAttribute('hx-post', 'index.php');
            form.setAttribute('hx-target', 'body');
            form.setAttribute('hx-swap', 'outerHTML');
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'clear_bets';
            
            form.appendChild(actionInput);
            document.body.appendChild(form);
            htmx.process(form);
            form.submit();
        }
        
        function undoBet() {
            const form = document.createElement('form');
            form.method = 'POST';
            form.setAttribute('hx-post', 'index.php');
            form.setAttribute('hx-target', 'body');
            form.setAttribute('hx-swap', 'outerHTML');
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'undo_bet';
            
            form.appendChild(actionInput);
            document.body.appendChild(form);
            htmx.process(form);
            form.submit();
        }
    </script>
    <style>
        .card {
            aspect-ratio: 2.5/3.5;
            background: linear-gradient(145deg, #ffffff, #f0f0f0);
            border-radius: 12px;
            box-shadow: 0 6px 12px rgba(0,0,0,0.2);
            border: 2px solid #e5e5e5;
        }
        .card-back {
            background: linear-gradient(145deg, #1e40af, #3b82f6);
        }
        .betting-circle {
            width: 200px;
            height: 200px;
            border-radius: 50%;
            border: 4px solid rgba(255,255,255,0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }
        .betting-circle:hover {
            border-color: rgba(255,255,255,0.6);
            transform: scale(1.05);
        }
        .betting-circle.has-bet {
            border-color: #fbbf24;
            background: rgba(251,191,36,0.2);
        }
        .chip {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: white;
            cursor: pointer;
            transition: all 0.3s ease;
            border: 3px solid rgba(255,255,255,0.3);
            box-shadow: 0 4px 8px rgba(0,0,0,0.3);
        }
        .chip:hover {
            transform: scale(1.1);
            box-shadow: 0 6px 12px rgba(0,0,0,0.4);
        }
        .chip.selected {
            border-color: #fbbf24;
            box-shadow: 0 0 20px rgba(251,191,36,0.5);
        }
        .chip-1 { background: linear-gradient(145deg, #f3f4f6, #d1d5db); color: #1f2937; }
        .chip-5 { background: linear-gradient(145deg, #ef4444, #dc2626); }
        .chip-25 { background: linear-gradient(145deg, #22c55e, #16a34a); }
        .chip-100 { background: linear-gradient(145deg, #1f2937, #111827); }
        .chip-500 { background: linear-gradient(145deg, #3b82f6, #2563eb); }
        .game-table {
            background: linear-gradient(135deg, #0d9488, #14b8a6);
            border-radius: 20px;
            position: relative;
        }
        .betting-history {
            display: grid;
            grid-template-columns: repeat(10, 1fr);
            gap: 4px;
            margin: 20px 0;
        }
        .history-dot {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            border: 1px solid rgba(255,255,255,0.3);
        }
        .history-player { background: #3b82f6; }
        .history-banker { background: #ef4444; }
        .history-tie { background: #22c55e; }
        .card-large {
            width: 100px;
            height: 150px;
            opacity: 0;
            transform: translateY(-20px) scale(0.8);
            animation: cardDeal 0.6s ease-out forwards;
        }
        
        @keyframes cardDeal {
            from {
                opacity: 0;
                transform: translateY(-20px) scale(0.8);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }
        
        .results-hidden {
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.5s ease-in-out;
        }
        
        .results-visible {
            opacity: 1;
            pointer-events: auto;
        }
    </style>
</head>
<body class="min-h-screen" style="background: linear-gradient(135deg, #0d9488, #14b8a6);">
    <!-- Menu button -->
    <div class="absolute top-4 left-4">
        <div class="text-white text-2xl cursor-pointer">
            <div class="w-6 h-0.5 bg-white mb-1"></div>
            <div class="w-6 h-0.5 bg-white mb-1"></div>
            <div class="w-6 h-0.5 bg-white"></div>
        </div>
    </div>
    
    <!-- Header with balance -->
    <div class="absolute top-4 left-20 bg-black bg-opacity-20 rounded-lg px-4 py-2">
        <div class="text-white text-xl font-bold">$ <?= number_format($balance) ?></div>
    </div>
    
    <!-- Betting history -->
    <div class="absolute top-16 left-1/2 transform -translate-x-1/2">
        <div class="betting-history">
            <?php 
            $gameHistory = $_SESSION['game_history'] ?? [];
            // Fill empty slots up to 100
            $totalSlots = 100;
            $emptySlots = $totalSlots - count($gameHistory);
            
            // Show empty slots first
            for($i = 0; $i < $emptySlots; $i++): ?>
                <div class="history-dot"></div>
            <?php endfor; ?>
            
            <?php 
            // Show actual game results
            foreach($gameHistory as $result): 
                $cssClass = 'history-' . $result; // will be history-player, history-banker, or history-tie
            ?>
                <div class="history-dot <?= $cssClass ?>"></div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <div class="flex min-h-screen mt-20">
        <!-- Left Sidebar - Game Rules -->
        <div class="w-80 bg-black bg-opacity-20 p-6 fixed left-0 top-20 bottom-0 overflow-y-auto">
            <h3 class="text-xl font-bold text-white mb-4">Game Rules</h3>
            <div class="text-white text-sm space-y-3">
                <div>
                    <h4 class="font-semibold text-yellow-400 mb-1">Card Values:</h4>
                    <p>Cards 2-9 are worth face value, 10/J/Q/K are worth 0, Aces are worth 1</p>
                </div>
                <div>
                    <h4 class="font-semibold text-yellow-400 mb-1">Scoring:</h4>
                    <p>Hand values are calculated modulo 10 (only units digit counts). Closest to 9 wins.</p>
                </div>
                <div>
                    <h4 class="font-semibold text-yellow-400 mb-1">Payouts:</h4>
                    <ul class="list-disc list-inside space-y-1 ml-2">
                        <li>Player bets pay 1:1</li>
                        <li>Banker bets pay 19:20 (5% commission)</li>
                        <li>Tie bets pay 8:1</li>
                    </ul>
                </div>
                <div>
                    <h4 class="font-semibold text-yellow-400 mb-1">Third Card Rules:</h4>
                    <p class="text-xs">Player draws on 0-5, stands on 6-7. Banker drawing depends on player's third card and banker's total.</p>
                </div>
            </div>
        </div>

        <!-- Main Game Area -->
        <div class="flex-1 ml-80 px-8 py-8">
            <!-- Game Table -->
            <div class="game-table p-8 w-full max-w-none shadow-2xl relative" data-game-state="<?= $gameState['state'] ?>" style="min-height: 80vh;">
            
            <!-- Banker Section -->
            <div class="mb-8">
                <div class="text-center mb-4">
                    <h2 class="text-2xl font-bold text-white mb-2">Banker</h2>
                    <div class="text-3xl font-bold text-yellow-400">
                        <span id="banker-score" class="results-hidden">
                            <?= $gameState['bankerScore'] ?>
                        </span>
                        <span id="banker-card-count" class="text-6xl font-bold text-black bg-white rounded-full w-16 h-16 flex items-center justify-center mx-auto">
                            <?= count($gameState['bankerHand']) ?>
                        </span>
                    </div>
                </div>
                <div class="flex justify-center space-x-4" id="banker-cards">
                    <?php 
                    $showCards = $gameState['state'] === 'finished' || $gameState['state'] === 'dealing_progressive' || $gameState['state'] === 'dealing';
                    $bankerCards = $gameState['bankerHand'];
                    ?>
                    
                    <?php if ($showCards && count($bankerCards) > 0): ?>
                        <?php foreach ($bankerCards as $index => $card): ?>
                            <?php 
                            // Baccarat dealing order: player-0=0s, banker-0=0.8s, player-1=1.6s, banker-1=2.4s, etc.
                            $dealDelay = ($index == 0) ? '0.8s' : (($index == 1) ? '2.4s' : (($index == 2) ? '4.8s' : '0s'));
                            ?>
                            <div class="card card-large flex items-center justify-center text-2xl font-bold <?= in_array($card->suit, ['♥', '♦']) ? 'text-red-600' : 'text-black' ?>" 
                                 style="animation-delay: <?= $dealDelay ?>;">
                                <?= $card ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Betting Area -->
            <div class="flex justify-center items-center space-x-16 mb-12">
                <!-- Player Betting Circle -->
                <div class="betting-circle <?= isset($currentBets['player']) && $currentBets['player'] > 0 ? 'has-bet' : '' ?>" 
                     onclick="placeBet('player')" id="player-circle">
                    <div class="text-white font-bold text-lg">PLAYER</div>
                    <?php if (isset($currentBets['player']) && $currentBets['player'] > 0): ?>
                        <div class="absolute -bottom-2 bg-yellow-500 text-black px-2 py-1 rounded text-sm font-bold">
                            $<?= $currentBets['player'] ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Tie Betting Circle -->
                <div class="betting-circle <?= isset($currentBets['tie']) && $currentBets['tie'] > 0 ? 'has-bet' : '' ?>" 
                     onclick="placeBet('tie')" id="tie-circle">
                    <div class="text-white font-bold text-lg">TIE</div>
                    <div class="text-white text-sm">PAYS 9:1</div>
                    <div class="text-white text-xs">$1 Min | $500 Max</div>
                    <?php if (isset($currentBets['tie']) && $currentBets['tie'] > 0): ?>
                        <div class="absolute -bottom-2 bg-yellow-500 text-black px-2 py-1 rounded text-sm font-bold">
                            $<?= $currentBets['tie'] ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Banker Betting Circle -->
                <div class="betting-circle <?= isset($currentBets['banker']) && $currentBets['banker'] > 0 ? 'has-bet' : '' ?>" 
                     onclick="placeBet('banker')" id="banker-circle">
                    <div class="text-white font-bold text-lg">BANKER</div>
                    <?php if (isset($currentBets['banker']) && $currentBets['banker'] > 0): ?>
                        <div class="absolute -bottom-2 bg-yellow-500 text-black px-2 py-1 rounded text-sm font-bold">
                            $<?= $currentBets['banker'] ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Chip Selection -->
            <div class="flex justify-center space-x-4 mb-8" id="chip-selector">
                <div class="chip chip-1" onclick="selectChip(1)" data-value="1">1</div>
                <div class="chip chip-5" onclick="selectChip(5)" data-value="5">5</div>
                <div class="chip chip-25" onclick="selectChip(25)" data-value="25">25</div>
                <div class="chip chip-100" onclick="selectChip(100)" data-value="100">100</div>
                <div class="chip chip-500" onclick="selectChip(500)" data-value="500">500</div>
            </div>
            
            <!-- Control Buttons on Right -->
            <div class="absolute right-4 top-1/2 transform -translate-y-1/2 flex flex-col space-y-4">
                <!-- Clear All Button -->
                <div class="bg-white bg-opacity-20 rounded-full p-4 cursor-pointer hover:bg-opacity-30 transition-all" onclick="clearAllBets()">
                    <div class="text-white text-center">
                        <div class="text-2xl mb-1">✕</div>
                        <div class="text-sm font-bold">Clear All</div>
                    </div>
                </div>
                
                <!-- Deal Button -->
                <?php if ($gameState['state'] === 'betting' && !empty($currentBets)): ?>
                    <button onclick="startDeal()" class="bg-white bg-opacity-20 rounded-full p-4 cursor-pointer hover:bg-opacity-30 transition-all">
                        <div class="text-white text-center">
                            <div class="text-2xl mb-1">🂠</div>
                            <div class="text-sm font-bold">Deal</div>
                        </div>
                    </button>
                <?php endif; ?>
                
                <!-- Undo Button -->
                <div class="bg-white bg-opacity-20 rounded-full p-4 cursor-pointer hover:bg-opacity-30 transition-all" onclick="undoBet()">
                    <div class="text-white text-center">
                        <div class="text-2xl mb-1">↶</div>
                        <div class="text-sm font-bold">Undo</div>
                    </div>
                </div>
            </div>

            <!-- Player Section -->
            <div class="mb-8">
                <div class="text-center mb-4">
                    <h2 class="text-2xl font-bold text-white mb-2">Player</h2>
                    <div class="text-3xl font-bold text-yellow-400">
                        <span id="player-score" class="results-hidden">
                            <?= $gameState['playerScore'] ?>
                        </span>
                        <span id="player-card-count" class="text-6xl font-bold text-black bg-white rounded-full w-16 h-16 flex items-center justify-center mx-auto">
                            <?= count($gameState['playerHand']) ?>
                        </span>
                    </div>
                </div>
                <div class="flex justify-center space-x-4" id="player-cards">
                    <?php 
                    $showCards = $gameState['state'] === 'finished' || $gameState['state'] === 'dealing_progressive' || $gameState['state'] === 'dealing';
                    $playerCards = $gameState['playerHand'];
                    ?>
                    
                    <?php if ($showCards && count($playerCards) > 0): ?>
                        <?php foreach ($playerCards as $index => $card): ?>
                            <?php 
                            // Baccarat dealing order: player-0=0s, banker-0=0.8s, player-1=1.6s, banker-1=2.4s, etc.
                            $dealDelay = ($index == 0) ? '0s' : (($index == 1) ? '1.6s' : (($index == 2) ? '4.0s' : '0s'));
                            ?>
                            <div class="card card-large flex items-center justify-center text-2xl font-bold <?= in_array($card->suit, ['♥', '♦']) ? 'text-red-600' : 'text-black' ?>" 
                                 style="animation-delay: <?= $dealDelay ?>;">
                                <?= $card ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Game Results -->
            <?php if ($gameState['state'] === 'finished'): ?>
                <div class="text-center mb-6 results-hidden" id="game-results">
                    <div class="text-3xl font-bold text-white mb-4">
                        <?php
                        switch ($gameState['result']) {
                            case 'player':
                                echo 'Player Wins!';
                                break;
                            case 'banker':
                                echo 'Banker Wins!';
                                break;
                            case 'tie':
                                echo 'It\'s a Tie!';
                                break;
                        }
                        ?>
                    </div>
                    
                    <?php if (!empty($currentBets)): ?>
                        <div class="bg-gray-800 rounded-lg p-4 mb-4 max-w-md mx-auto">
                            <h4 class="text-lg font-bold text-white mb-3">Bet Results:</h4>
                            <?php 
                            $totalNetResult = 0;
                            foreach ($currentBets as $betType => $betAmount): 
                                $winnings = $game->calculateWinnings($betType, $betAmount);
                                $totalNetResult += $winnings;
                            ?>
                                <div class="flex justify-between items-center mb-2 text-white">
                                    <span class="capitalize"><?= $betType ?> ($<?= number_format($betAmount) ?>):</span>
                                    <span class="<?= $winnings >= 0 ? 'text-green-400' : 'text-red-400' ?> font-bold">
                                        <?= $winnings >= 0 ? '+' : '' ?>$<?= number_format($winnings) ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                            
                            <hr class="border-gray-600 my-3">
                            <div class="flex justify-between items-center text-lg font-bold">
                                <span class="text-white">Net Result:</span>
                                <span class="<?= $totalNetResult >= 0 ? 'text-green-400' : 'text-red-400' ?>">
                                    <?= $totalNetResult >= 0 ? '+' : '' ?>$<?= number_format($totalNetResult) ?>
                                </span>
                            </div>
                        </div>
                        
                        <div class="text-white mb-4">
                            <p id="countdown-text">Auto-starting new round in 3 seconds...</p>
                            <div class="w-full bg-gray-600 rounded-full h-2 mt-2">
                                <div id="progress-bar" class="bg-green-500 h-2 rounded-full transition-all duration-1000" style="width: 100%;"></div>
                            </div>
                        </div>
                        
                        <!-- Auto-progress form -->
                        <form hx-post="index.php" hx-target="body" hx-swap="outerHTML" style="display: none;">
                            <input type="hidden" name="action" value="auto_progress">
                            <button type="submit" id="auto-progress-btn" 
                                    hx-trigger="load delay:3s from:closest form">Auto Progress</button>
                        </form>
                    <?php endif; ?>
                    
                    <form hx-post="index.php" hx-target="body" hx-swap="outerHTML" class="inline-block">
                        <input type="hidden" name="action" value="new_game">
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-6 rounded-lg">
                            Manual New Game
                        </button>
                    </form>
                </div>
            <?php endif; ?>

            </div>
        </div>
    </div>
</body>
</html>