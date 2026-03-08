<?php

function getPlayersForSport($pdo, $eventSportId, $gender = null) {
    $sql = "
        SELECT DISTINCT u.id, u.full_name 
        FROM registrations r 
        JOIN users u ON r.user_id = u.id 
        WHERE (r.sport1_id = ? OR r.sport2_id = ?)
    ";
    $params = [$eventSportId, $eventSportId];
    if ($gender !== null) {
        $sql .= " AND r.gender = ?";
        $params[] = $gender;
    }
    $sql .= " ORDER BY u.full_name";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function nextPowerOfTwo($n) {
    $p = 1;
    while ($p < $n) $p *= 2;
    return $p;
}

function getTeamsForSport($pdo, $eventSportId) {
    $stmt = $pdo->prepare("SELECT id, team_name AS full_name FROM teams WHERE event_sport_id = ? ORDER BY team_name");
    $stmt->execute([$eventSportId]);
    return $stmt->fetchAll();
}

function generateBracket($pdo, $eventSportId, $startTime, $avgGameTime, $placementType, $eventEndDateTime = null, $gender = null) {
    $players = getPlayersForSport($pdo, $eventSportId, $gender);
    
    if (count($players) < 2) return false;
    
    if ($placementType === 'random') {
        shuffle($players);
    }
    
    $bracketSize = nextPowerOfTwo(count($players));
    $byesNeeded = $bracketSize - count($players);
    
    // Add byes at the end
    for ($i = 0; $i < $byesNeeded; $i++) {
        $players[] = ['id' => null, 'full_name' => 'BYE', 'is_bye' => true];
    }
    
    // Distribute byes evenly using seeding pattern
    $seeded = seedPlayers($players, $bracketSize);
    
    $totalRounds = intval(log($bracketSize, 2));
    $matchNum = 1;
    $currentTime = new DateTime($startTime);
    $eventEnd = $eventEndDateTime ? new DateTime($eventEndDateTime) : null;
    $matchIds = [];
    
    // Create Round 1 matches
    $round1Matches = [];
    for ($i = 0; $i < $bracketSize; $i += 2) {
        $p1 = $seeded[$i];
        $p2 = $seeded[$i + 1];
        
        $isBye1 = isset($p1['is_bye']) ? 1 : 0;
        $isBye2 = isset($p2['is_bye']) ? 1 : 0;
        
        if ($eventEnd) {
            $proposedEnd = (clone $currentTime)->modify("+{$avgGameTime} minutes");
            if ($proposedEnd > $eventEnd) {
                throw new Exception('Cannot schedule all matches within the event time window. Adjust event end time or average game time.');
            }
        }
        
        $stmt = $pdo->prepare("INSERT INTO matches 
            (event_sport_id, round, match_number, player1_id, player2_id, 
             player1_name, player2_name, is_player1_bye, is_player2_bye,
             scheduled_time, gender, status, bracket_position)
            VALUES (?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)");
        
            $stmt->execute([
                $eventSportId, $matchNum,
                $p1['id'], $p2['id'],
                $p1['full_name'], $p2['full_name'],
                $isBye1, $isBye2,
                $currentTime->format('Y-m-d H:i:s'),
                $gender ?? 'male',
                $matchNum
            ]);
        
        $matchId = $pdo->lastInsertId();
        $round1Matches[] = $matchId;
        $matchNum++;
        
        if (!$isBye1 && !$isBye2) {
            $currentTime->modify("+{$avgGameTime} minutes");
        }
    }
    
    // Create subsequent round matches
    $prevRound = $round1Matches;
    for ($round = 2; $round <= $totalRounds; $round++) {
        $currentRound = [];
        for ($i = 0; $i < count($prevRound); $i += 2) {
            if ($eventEnd) {
                $proposedEnd = (clone $currentTime)->modify("+{$avgGameTime} minutes");
                if ($proposedEnd > $eventEnd) {
                    throw new Exception('Cannot schedule all matches within the event time window. Adjust event end time or average game time.');
                }
            }
            $stmt = $pdo->prepare("INSERT INTO matches 
                (event_sport_id, round, match_number, scheduled_time, gender, status, bracket_position)
                VALUES (?, ?, ?, ?, ?, 'pending', ?)");
            $stmt->execute([
                $eventSportId, $round, $matchNum,
                $currentTime->format('Y-m-d H:i:s'),
                $gender ?? 'male',
                $matchNum
            ]);
            $nextId = $pdo->lastInsertId();
            $currentRound[] = $nextId;
            
            // Link feeder matches
            $pdo->prepare("UPDATE matches SET next_match_id = ? WHERE id = ?")
                ->execute([$nextId, $prevRound[$i]]);
            $pdo->prepare("UPDATE matches SET next_match_id = ? WHERE id = ?")
                ->execute([$nextId, $prevRound[$i + 1]]);
            
            $matchNum++;
            $currentTime->modify("+{$avgGameTime} minutes");
        }
        $prevRound = $currentRound;
    }
    
    // Auto-advance byes
    autoAdvanceByes($pdo, $eventSportId, $gender);
    
    // Fix scheduling conflicts
    fixScheduleConflicts($pdo, $eventSportId, $startTime, $avgGameTime, $eventEndDateTime);
    
    return true;
}

function generateTeamBracket($pdo, $eventSportId, $startTime, $avgGameTime, $placementType, $eventEndDateTime = null) {
    $teams = getTeamsForSport($pdo, $eventSportId);
    
    if (count($teams) < 2) return false;
    
    if ($placementType === 'random') {
        // Fisher–Yates shuffle for teams
        $n = count($teams);
        for ($i = $n - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            $tmp = $teams[$i];
            $teams[$i] = $teams[$j];
            $teams[$j] = $tmp;
        }
    }
    
    $bracketSize = nextPowerOfTwo(count($teams));
    $byesNeeded = $bracketSize - count($teams);
    
    for ($i = 0; $i < $byesNeeded; $i++) {
        $teams[] = ['id' => null, 'full_name' => 'BYE', 'is_bye' => true];
    }
    
    $seeded = seedPlayers($teams, $bracketSize);
    
    $totalRounds = intval(log($bracketSize, 2));
    $matchNum = 1;
    $currentTime = new DateTime($startTime);
    $eventEnd = $eventEndDateTime ? new DateTime($eventEndDateTime) : null;
    
    $round1Matches = [];
    for ($i = 0; $i < $bracketSize; $i += 2) {
        $p1 = $seeded[$i];
        $p2 = $seeded[$i + 1];
        
        $isBye1 = isset($p1['is_bye']) ? 1 : 0;
        $isBye2 = isset($p2['is_bye']) ? 1 : 0;
        
        if ($eventEnd) {
            $proposedEnd = (clone $currentTime)->modify("+{$avgGameTime} minutes");
            if ($proposedEnd > $eventEnd) {
                throw new Exception('Cannot schedule all matches within the event time window. Adjust event end time or average game time.');
            }
        }
        
        $stmt = $pdo->prepare("INSERT INTO matches 
            (event_sport_id, round, match_number, player1_id, player2_id, 
             player1_name, player2_name, is_player1_bye, is_player2_bye,
             scheduled_time, status, bracket_position)
            VALUES (?, 1, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)");
        
        $stmt->execute([
            $eventSportId, $matchNum,
            $p1['id'], $p2['id'],
            $p1['full_name'], $p2['full_name'],
            $isBye1, $isBye2,
            $currentTime->format('Y-m-d H:i:s'),
            $matchNum
        ]);
        
        $matchId = $pdo->lastInsertId();
        $round1Matches[] = $matchId;
        $matchNum++;
        
        if (!$isBye1 && !$isBye2) {
            $currentTime->modify("+{$avgGameTime} minutes");
        }
    }
    
    $prevRound = $round1Matches;
    for ($round = 2; $round <= $totalRounds; $round++) {
        $currentRound = [];
        for ($i = 0; $i < count($prevRound); $i += 2) {
            if ($eventEnd) {
                $proposedEnd = (clone $currentTime)->modify("+{$avgGameTime} minutes");
                if ($proposedEnd > $eventEnd) {
                    throw new Exception('Cannot schedule all matches within the event time window. Adjust event end time or average game time.');
                }
            }
            $stmt = $pdo->prepare("INSERT INTO matches 
                (event_sport_id, round, match_number, scheduled_time, status, bracket_position)
                VALUES (?, ?, ?, ?, 'pending', ?)");
            $stmt->execute([
                $eventSportId, $round, $matchNum,
                $currentTime->format('Y-m-d H:i:s'),
                $matchNum
            ]);
            $nextId = $pdo->lastInsertId();
            $currentRound[] = $nextId;
            
            $pdo->prepare("UPDATE matches SET next_match_id = ? WHERE id = ?")
                ->execute([$nextId, $prevRound[$i]]);
            $pdo->prepare("UPDATE matches SET next_match_id = ? WHERE id = ?")
                ->execute([$nextId, $prevRound[$i + 1]]);
            
            $matchNum++;
            $currentTime->modify("+{$avgGameTime} minutes");
        }
        $prevRound = $currentRound;
    }
    
    autoAdvanceByes($pdo, $eventSportId);
    fixScheduleConflicts($pdo, $eventSportId, $startTime, $avgGameTime, $eventEndDateTime);
    
    return true;
}

function seedPlayers($players, $bracketSize) {
    // Simple seeding: place byes spread out
    $realPlayers = array_filter($players, fn($p) => !isset($p['is_bye']));
    $realPlayers = array_values($realPlayers);
    $byeCount = $bracketSize - count($realPlayers);
    
    $result = [];
    $byesPlaced = 0;
    $playerIdx = 0;
    
    for ($i = 0; $i < $bracketSize; $i++) {
        if ($byesPlaced < $byeCount && $i % 2 === 1 && ($bracketSize - $i) <= ($byeCount - $byesPlaced) * 2) {
            $result[] = ['id' => null, 'full_name' => 'BYE', 'is_bye' => true];
            $byesPlaced++;
        } else if ($playerIdx < count($realPlayers)) {
            $result[] = $realPlayers[$playerIdx++];
        } else {
            $result[] = ['id' => null, 'full_name' => 'BYE', 'is_bye' => true];
            $byesPlaced++;
        }
    }
    
    return $result;
}

function autoAdvanceByes($pdo, $eventSportId, $gender = null) {
    $sql = "SELECT * FROM matches WHERE event_sport_id = ? AND round = 1 AND (is_player1_bye = 1 OR is_player2_bye = 1)";
    $params = [$eventSportId];
    if ($gender !== null) {
        $sql .= " AND gender = ?";
        $params[] = $gender;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $matches = $stmt->fetchAll();
    
    foreach ($matches as $match) {
        $winnerId = null;
        $winnerName = '';
        
        if ($match['is_player1_bye'] && !$match['is_player2_bye']) {
            $winnerId = $match['player2_id'];
            $winnerName = $match['player2_name'];
        } elseif ($match['is_player2_bye'] && !$match['is_player1_bye']) {
            $winnerId = $match['player1_id'];
            $winnerName = $match['player1_name'];
        } elseif ($match['is_player1_bye'] && $match['is_player2_bye']) {
            // Both byes, mark completed
            $pdo->prepare("UPDATE matches SET status = 'completed' WHERE id = ?")->execute([$match['id']]);
            continue;
        }
        
        if ($winnerId) {
            $pdo->prepare("UPDATE matches SET winner_id = ?, winner_name = ?, status = 'completed', score1 = 'BYE', score2 = 'BYE' WHERE id = ?")
                ->execute([$winnerId, $winnerName, $match['id']]);
            
            advanceWinner($pdo, $match['id'], $winnerId, $winnerName);
        }
    }
}

function advanceWinner($pdo, $matchId, $winnerId, $winnerName) {
    $stmt = $pdo->prepare("SELECT next_match_id FROM matches WHERE id = ?");
    $stmt->execute([$matchId]);
    $nextMatchId = $stmt->fetchColumn();
    
    if (!$nextMatchId) return;
    
    $stmt = $pdo->prepare("SELECT * FROM matches WHERE id = ?");
    $stmt->execute([$nextMatchId]);
    $nextMatch = $stmt->fetch();
    
    if (!$nextMatch) return;
    
    if (!$nextMatch['player1_id']) {
        $pdo->prepare("UPDATE matches SET player1_id = ?, player1_name = ?, is_player1_bye = 0 WHERE id = ?")
            ->execute([$winnerId, $winnerName, $nextMatchId]);
    } else {
        $pdo->prepare("UPDATE matches SET player2_id = ?, player2_name = ?, is_player2_bye = 0 WHERE id = ?")
            ->execute([$winnerId, $winnerName, $nextMatchId]);
    }
}

function fixScheduleConflicts($pdo, $eventSportId, $startTime, $avgGameTime, $eventEndDateTime = null) {
    // Get sport's event_id to check across all sports
    $stmt = $pdo->prepare("SELECT event_id FROM event_sports WHERE id = ?");
    $stmt->execute([$eventSportId]);
    $eventId = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("
        SELECT m.* FROM matches m 
        JOIN event_sports es ON m.event_sport_id = es.id 
        WHERE es.event_id = ? AND m.status = 'pending' AND m.is_player1_bye = 0 AND m.is_player2_bye = 0
        ORDER BY m.round, m.match_number
    ");
    $stmt->execute([$eventId]);
    $allMatches = $stmt->fetchAll();
    
    $playerBusy = [];
    $currentTime = new DateTime($startTime);
    $eventEnd = $eventEndDateTime ? new DateTime($eventEndDateTime) : null;
    
    foreach ($allMatches as $match) {
        $players = array_filter([$match['player1_id'], $match['player2_id']]);
        $slotTime = clone $currentTime;
        
        $attempts = 0;
        while ($attempts < 100) {
            $conflict = false;
            foreach ($players as $pid) {
                if (isset($playerBusy[$pid])) {
                    foreach ($playerBusy[$pid] as $busy) {
                        $matchEnd = (clone $slotTime)->modify("+{$avgGameTime} minutes");
                        if ($slotTime < $busy['end'] && $matchEnd > $busy['start']) {
                            $conflict = true;
                            break 2;
                        }
                    }
                }
            }
            if (!$conflict) break;
            $slotTime->modify("+{$avgGameTime} minutes");
            
            if ($eventEnd) {
                $matchEnd = (clone $slotTime)->modify("+{$avgGameTime} minutes");
                if ($matchEnd > $eventEnd) {
                    throw new Exception('Cannot schedule all matches within the event time window. Adjust event end time or average game time.');
                }
            }
            $attempts++;
        }
        
        $endTime = (clone $slotTime)->modify("+{$avgGameTime} minutes");
        foreach ($players as $pid) {
            $playerBusy[$pid][] = ['start' => clone $slotTime, 'end' => clone $endTime];
        }
        
        $pdo->prepare("UPDATE matches SET scheduled_time = ? WHERE id = ?")
            ->execute([$slotTime->format('Y-m-d H:i:s'), $match['id']]);
    }
}

function generateTeams($pdo, $eventSportId, $maxTeams, $membersPerTeam) {
    $players = getPlayersForSport($pdo, $eventSportId);
    $totalPlayers = count($players);
    
    if ($totalPlayers < 2) return false;
    
    // Calculate optimal teams
    $numTeams = min($maxTeams, floor($totalPlayers / max(1, $membersPerTeam - 1)));
    if ($numTeams < 2) $numTeams = 2;
    
    $playersPerTeam = floor($totalPlayers / $numTeams);
    $extra = $totalPlayers % $numTeams;
    
    shuffle($players);
    $playerIdx = 0;
    
    for ($t = 1; $t <= $numTeams; $t++) {
        $stmt = $pdo->prepare("INSERT INTO teams (event_sport_id, team_name) VALUES (?, ?)");
        $stmt->execute([$eventSportId, "Team $t"]);
        $teamId = $pdo->lastInsertId();
        
        $count = $playersPerTeam + ($t <= $extra ? 1 : 0);
        for ($j = 0; $j < $count && $playerIdx < $totalPlayers; $j++) {
            $pdo->prepare("INSERT INTO team_members (team_id, user_id) VALUES (?, ?)")
                ->execute([$teamId, $players[$playerIdx]['id']]);
            $playerIdx++;
        }
    }
    
    return true;
}
?>
