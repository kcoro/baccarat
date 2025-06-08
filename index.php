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
                $game->dealInitialCards();
                $_SESSION['game'] = serialize($game);
            }
            break;
            
        case 'auto_progress':
            if (isset($_SESSION['current_bets']) && !empty($_SESSION['current_bets'])) {
                // Make sure we have the current game state
                $currentGameState = $game->getGameState();
                if ($currentGameState['state'] === 'finished') {
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
                startCountdown();
            }
        }
        
        // Listen for HTMX events
        document.addEventListener('htmx:afterSwap', function() {
            setTimeout(checkAndStartCountdown, 100);
        });
        
        // Initial check on page load
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(checkAndStartCountdown, 100);
        });
        
        // Cleanup
        window.addEventListener('beforeunload', function() {
            if (countdownTimer) clearInterval(countdownTimer);
        });
    </script>
    <style>
        .card {
            aspect-ratio: 2.5/3.5;
            background: linear-gradient(145deg, #ffffff, #f0f0f0);
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .card-back {
            background: linear-gradient(145deg, #1e3a8a, #3b82f6);
        }
        .betting-area {
            background: linear-gradient(145deg, #065f46, #10b981);
        }
    </style>
</head>
<body class="bg-green-900 min-h-screen">
    <div class="container mx-auto px-4 py-8">
        <!-- Header -->
        <div class="text-center mb-8">
            <h1 class="text-4xl font-bold text-white mb-2">Baccarat</h1>
            <p class="text-green-200">Punto Banco</p>
            <div class="mt-4 text-white">
                <span class="text-xl">Balance: $<?= number_format($balance) ?></span>
                <?php if (!empty($currentBets)): ?>
                    <br><span class="text-sm text-yellow-300">
                        Active Bets: $<?= number_format(array_sum($currentBets)) ?>
                    </span>
                <?php endif; ?>
                
            </div>
        </div>

        <!-- Game Table -->
        <div class="bg-green-800 rounded-lg p-8 max-w-6xl mx-auto shadow-2xl">
            
            <!-- Banker Section -->
            <div class="mb-8">
                <div class="text-center mb-4">
                    <h2 class="text-2xl font-bold text-white mb-2">Banker</h2>
                    <div class="text-3xl font-bold text-yellow-400">
                        Score: <?= $gameState['state'] === 'finished' ? $gameState['bankerScore'] : '?' ?>
                    </div>
                </div>
                <div class="flex justify-center space-x-4" id="banker-cards">
                    <?php if ($gameState['state'] === 'finished'): ?>
                        <?php foreach ($gameState['bankerHand'] as $card): ?>
                            <div class="card w-16 h-24 flex items-center justify-center text-lg font-bold <?= in_array($card->suit, ['♥', '♦']) ? 'text-red-600' : 'text-black' ?>">
                                <?= $card ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="card card-back w-16 h-24"></div>
                        <div class="card card-back w-16 h-24"></div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Betting Area -->
            <div class="grid grid-cols-3 gap-4 mb-8">
                <div class="betting-area rounded-lg p-6 text-center text-white">
                    <h3 class="text-xl font-bold mb-2">BANKER</h3>
                    <p class="text-sm mb-4">Pays 19:20</p>
                    <?php if ($gameState['state'] === 'betting'): ?>
                        <form hx-post="index.php" hx-target="body" hx-swap="outerHTML">
                            <input type="hidden" name="action" value="place_bet">
                            <input type="hidden" name="bet_type" value="banker">
                            <input type="number" name="bet_amount" min="1" max="<?= $balance ?>" 
                                   class="w-full mb-2 px-3 py-2 text-black rounded" placeholder="Bet Amount">
                            <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">
                                Add Bet
                            </button>
                        </form>
                    <?php endif; ?>
                    <?php if (isset($currentBets['banker']) && $currentBets['banker'] > 0): ?>
                        <div class="bg-yellow-500 text-black p-2 rounded font-bold mt-2">
                            Total Bet: $<?= number_format($currentBets['banker']) ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="betting-area rounded-lg p-6 text-center text-white">
                    <h3 class="text-xl font-bold mb-2">TIE</h3>
                    <p class="text-sm mb-4">Pays 8:1</p>
                    <?php if ($gameState['state'] === 'betting'): ?>
                        <form hx-post="index.php" hx-target="body" hx-swap="outerHTML">
                            <input type="hidden" name="action" value="place_bet">
                            <input type="hidden" name="bet_type" value="tie">
                            <input type="number" name="bet_amount" min="1" max="<?= $balance ?>" 
                                   class="w-full mb-2 px-3 py-2 text-black rounded" placeholder="Bet Amount">
                            <button type="submit" class="w-full bg-purple-600 hover:bg-purple-700 text-white font-bold py-2 px-4 rounded">
                                Add Bet
                            </button>
                        </form>
                    <?php endif; ?>
                    <?php if (isset($currentBets['tie']) && $currentBets['tie'] > 0): ?>
                        <div class="bg-yellow-500 text-black p-2 rounded font-bold mt-2">
                            Total Bet: $<?= number_format($currentBets['tie']) ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="betting-area rounded-lg p-6 text-center text-white">
                    <h3 class="text-xl font-bold mb-2">PLAYER</h3>
                    <p class="text-sm mb-4">Pays 1:1</p>
                    <?php if ($gameState['state'] === 'betting'): ?>
                        <form hx-post="index.php" hx-target="body" hx-swap="outerHTML">
                            <input type="hidden" name="action" value="place_bet">
                            <input type="hidden" name="bet_type" value="player">
                            <input type="number" name="bet_amount" min="1" max="<?= $balance ?>" 
                                   class="w-full mb-2 px-3 py-2 text-black rounded" placeholder="Bet Amount">
                            <button type="submit" class="w-full bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-4 rounded">
                                Add Bet
                            </button>
                        </form>
                    <?php endif; ?>
                    <?php if (isset($currentBets['player']) && $currentBets['player'] > 0): ?>
                        <div class="bg-yellow-500 text-black p-2 rounded font-bold mt-2">
                            Total Bet: $<?= number_format($currentBets['player']) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Deal Button -->
            <?php if ($gameState['state'] === 'betting' && !empty($currentBets)): ?>
                <div class="text-center mb-6">
                    <form hx-post="index.php" hx-target="body" hx-swap="outerHTML">
                        <input type="hidden" name="action" value="deal">
                        <button type="submit" class="bg-green-600 hover:bg-green-700 text-white font-bold py-4 px-8 rounded-lg text-xl">
                            DEAL CARDS
                        </button>
                    </form>
                </div>
            <?php endif; ?>

            <!-- Player Section -->
            <div class="mb-8">
                <div class="text-center mb-4">
                    <h2 class="text-2xl font-bold text-white mb-2">Player</h2>
                    <div class="text-3xl font-bold text-yellow-400">
                        Score: <?= $gameState['state'] === 'finished' ? $gameState['playerScore'] : '?' ?>
                    </div>
                </div>
                <div class="flex justify-center space-x-4" id="player-cards">
                    <?php if ($gameState['state'] === 'finished'): ?>
                        <?php foreach ($gameState['playerHand'] as $card): ?>
                            <div class="card w-16 h-24 flex items-center justify-center text-lg font-bold <?= in_array($card->suit, ['♥', '♦']) ? 'text-red-600' : 'text-black' ?>">
                                <?= $card ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="card card-back w-16 h-24"></div>
                        <div class="card card-back w-16 h-24"></div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Game Results -->
            <?php if ($gameState['state'] === 'finished'): ?>
                <div class="text-center mb-6">
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

            <!-- Game Rules -->
            <div class="mt-8 bg-green-700 rounded-lg p-4 text-white text-sm">
                <h3 class="font-bold mb-2">Game Rules:</h3>
                <ul class="list-disc list-inside space-y-1">
                    <li>Cards 2-9 are worth face value, 10/J/Q/K are worth 0, Aces are worth 1</li>
                    <li>Hand values are calculated modulo 10 (only units digit counts)</li>
                    <li>Closest to 9 wins</li>
                    <li>Player bets pay 1:1, Banker bets pay 19:20 (5% commission), Tie bets pay 8:1</li>
                </ul>
            </div>
        </div>
    </div>
</body>
</html>