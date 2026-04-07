<?php

/* ============================================================
   BRACKET GENERATOR
   ============================================================
   Scheduling rules
   ─────────────────
   • Each sport gets its own TimeSlotManager (independent clock).
   • Matches fill slots sequentially: slot_start = prev_slot_end.
   • When a slot would overflow today's end time the clock rolls
     to event_start_time on the next calendar day.
   • Conflict check: no individual player (or any member of a
     team) may appear in two overlapping matches — across ALL
     sports in the event (shared $busyMap by reference).

   Bracket types
   ─────────────
   single_elim_one / single_elim_two
       Standard single-elimination. One loss = out.
       (visual layout differs but structure is identical)

   double_elim
       Winners bracket (stage='stage1') + Losers bracket
       (stage='stage2'). A player must lose twice to be
       eliminated. The Grand Final links the two bracket
       winners. loser_match_id on each Winners match points
       to the Losers match the loser drops into.

   round_robin
       Every participant plays every other participant once.
       Round-robin is pure fixture generation — no elimination
       links needed.
   ============================================================ */


// ── Basic helpers ─────────────────────────────────────────────

function getPlayersForSport($pdo, $eventSportId, $gender = null): array {
    $sql    = "SELECT DISTINCT u.id, u.full_name
               FROM registrations r
               JOIN users u ON r.user_id = u.id
               WHERE (r.sport1_id = ? OR r.sport2_id = ?)";
    $params = [$eventSportId, $eventSportId];
    if ($gender !== null) { $sql .= " AND r.gender = ?"; $params[] = $gender; }
    $sql .= " ORDER BY u.full_name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getTeamsForSport($pdo, $eventSportId): array {
    $stmt = $pdo->prepare("SELECT id, team_name AS full_name FROM teams
                           WHERE event_sport_id = ? ORDER BY team_name");
    $stmt->execute([$eventSportId]);
    return $stmt->fetchAll();
}

function nextPowerOfTwo(int $n): int {
    $p = 1;
    while ($p < $n) $p *= 2;
    return $p;
}

/**
 * Resolve a participant to individual user IDs for conflict checking.
 * Individual sport  → [$participantId]
 * Team sport        → all team_members user IDs
 */
function resolveParticipantToUsers($pdo, $participantId, bool $isTeamSport): array {
    if (!$participantId) return [];
    if (!$isTeamSport)   return [(int)$participantId];
    $stmt = $pdo->prepare("SELECT user_id FROM team_members WHERE team_id = ?");
    $stmt->execute([$participantId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}


// ── TimeSlotManager ───────────────────────────────────────────

class TimeSlotManager {

    private DateTime $clock;
    private int      $startHour;
    private int      $startMin;
    private int      $endHour;
    private int      $endMin;
    private int      $slotMinutes;
    private array    $busyMap;

    public function __construct(
        string $eventStartDatetime,
        string $eventEndDatetime,
        int    $slotMinutes,
        array $busyMap
    ) {
        $this->clock       = new DateTime($eventStartDatetime);
        $this->slotMinutes = $slotMinutes;
        $this->busyMap     = $busyMap;

        $s = new DateTime($eventStartDatetime);
        $e = new DateTime($eventEndDatetime);
        $this->startHour = (int)$s->format('H');
        $this->startMin  = (int)$s->format('i');
        $this->endHour   = (int)$e->format('H');
        $this->endMin    = (int)$e->format('i');
    }

    /**
     * Find the earliest slot from the current clock at which all
     * $userIds are free, respecting the daily window.
     * Advances the clock to slot_end and returns slot_start.
     */
    public function allocate(array $userIds): DateTime {
        $slot  = clone $this->clock;
        $limit = 20000;

        while ($limit-- > 0) {
            $slot    = $this->snapToDayWindow($slot);
            $slotEnd = (clone $slot)->modify("+{$this->slotMinutes} minutes");
            $dayEnd  = $this->dayEndFor($slot);

            if ($slotEnd > $dayEnd) {
                $slot = $this->nextDayStart($slot);
                continue;
            }

            if ($this->hasConflict($userIds, $slot, $slotEnd)) {
                $slot = (clone $slot)->modify("+{$this->slotMinutes} minutes");
                continue;
            }

            $this->markBusy($userIds, $slot, $slotEnd);
            $this->clock = clone $slotEnd;
            return clone $slot;
        }

        throw new Exception(
            "Scheduling limit reached. Extend the event date range or reduce average game time."
        );
    }

    // ── private helpers ───────────────────────────────────────

    private function dayEndFor(DateTime $dt): DateTime {
        return (clone $dt)->setTime($this->endHour, $this->endMin, 0);
    }

    private function dayStartFor(DateTime $dt): DateTime {
        return (clone $dt)->setTime($this->startHour, $this->startMin, 0);
    }

    private function snapToDayWindow(DateTime $dt): DateTime {
        return $dt >= $this->dayEndFor($dt) ? $this->nextDayStart($dt) : $dt;
    }

    private function nextDayStart(DateTime $dt): DateTime {
        return $this->dayStartFor((clone $dt)->modify('+1 day'));
    }

    private function hasConflict(array $uids, DateTime $s, DateTime $e): bool {
        foreach ($uids as $uid) {
            foreach ($this->busyMap[$uid] ?? [] as $b) {
                if ($s < $b['end'] && $e > $b['start']) return true;
            }
        }
        return false;
    }

    private function markBusy(array $uids, DateTime $s, DateTime $e): void {
        foreach ($uids as $uid) {
            $this->busyMap[$uid][] = ['start' => clone $s, 'end' => clone $e];
        }
    }
}


// ── Seeding ───────────────────────────────────────────────────

function seedPlayers(array $players, int $bracketSize): array {
    $real       = array_values(array_filter($players, fn($p) => !isset($p['is_bye'])));
    $byeCount   = $bracketSize - count($real);
    $result     = [];
    $byesPlaced = 0;
    $pi         = 0;

    for ($i = 0; $i < $bracketSize; $i++) {
        if ($byesPlaced < $byeCount
            && $i % 2 === 1
            && ($bracketSize - $i) <= ($byeCount - $byesPlaced) * 2
        ) {
            $result[] = ['id' => null, 'full_name' => 'BYE', 'is_bye' => true];
            $byesPlaced++;
        } elseif ($pi < count($real)) {
            $result[] = $real[$pi++];
        } else {
            $result[] = ['id' => null, 'full_name' => 'BYE', 'is_bye' => true];
            $byesPlaced++;
        }
    }
    return $result;
}


// ── BYE auto-advance ──────────────────────────────────────────

function autoAdvanceByes($pdo, $eventSportId, $gender = null, string $stage = 'stage1'): void {
    $sql    = "SELECT * FROM matches
               WHERE event_sport_id = ? AND round = 1 AND stage = ?
               AND (is_player1_bye = 1 OR is_player2_bye = 1)";
    $params = [$eventSportId, $stage];
    if ($gender !== null) { $sql .= " AND gender = ?"; $params[] = $gender; }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $match) {
        if ($match['is_player1_bye'] && !$match['is_player2_bye']) {
            $wid  = $match['player2_id'];
            $wnam = $match['player2_name'];
        } elseif ($match['is_player2_bye'] && !$match['is_player1_bye']) {
            $wid  = $match['player1_id'];
            $wnam = $match['player1_name'];
        } elseif ($match['is_player1_bye'] && $match['is_player2_bye']) {
            $pdo->prepare("UPDATE matches SET status='completed' WHERE id=?")->execute([$match['id']]);
            continue;
        } else {
            continue;
        }
        if ($wid) {
            $pdo->prepare("UPDATE matches SET winner_id=?, winner_name=?,
                           status='completed', score1='BYE', score2='BYE' WHERE id=?")
                ->execute([$wid, $wnam, $match['id']]);
            advanceWinner($pdo, $match['id'], $wid, $wnam);
        }
    }
}

function advanceWinner($pdo, $matchId, $winnerId, $winnerName): void {
    $stmt = $pdo->prepare("SELECT next_match_id FROM matches WHERE id=?");
    $stmt->execute([$matchId]);
    $nextId = $stmt->fetchColumn();
    if (!$nextId) return;

    $stmt = $pdo->prepare("SELECT * FROM matches WHERE id=?");
    $stmt->execute([$nextId]);
    $next = $stmt->fetch();
    if (!$next) return;

    if (!$next['player1_id']) {
        $pdo->prepare("UPDATE matches SET player1_id=?, player1_name=?, is_player1_bye=0 WHERE id=?")
            ->execute([$winnerId, $winnerName, $nextId]);
    } else {
        $pdo->prepare("UPDATE matches SET player2_id=?, player2_name=?, is_player2_bye=0 WHERE id=?")
            ->execute([$winnerId, $winnerName, $nextId]);
    }
}


// ── Shared slot allocator for a participant pair ──────────────

/**
 * Returns userIds for both participants (handles team or individual).
 * BYE slots contribute no user IDs so they skip conflict checks.
 */
function participantUserIds($pdo, array $p1, array $p2, bool $isTeam): array {
    $ids = [];
    if (!isset($p1['is_bye']) && $p1['id']) {
        $ids = array_merge($ids, resolveParticipantToUsers($pdo, $p1['id'], $isTeam));
    }
    if (!isset($p2['is_bye']) && $p2['id']) {
        $ids = array_merge($ids, resolveParticipantToUsers($pdo, $p2['id'], $isTeam));
    }
    return array_values(array_unique($ids));
}


// ═══════════════════════════════════════════════════════════════
// SINGLE ELIMINATION
// (covers single_elim_one and single_elim_two — same structure)
// ═══════════════════════════════════════════════════════════════

function generateSingleElimBracket(
    $pdo,
    int    $eventSportId,
    array  $participants,       // already shuffled / ordered
    bool   $isTeam,
    string $gender,
    string $startTime,
    int    $avgGameTime,
    string $eventEndDateTime,
    array &$busyMap,
    int   &$matchNum            // shared counter, passed by ref
): void {
    $bracketSize = nextPowerOfTwo(count($participants));
    $byesNeeded  = $bracketSize - count($participants);
    for ($i = 0; $i < $byesNeeded; $i++) {
        $participants[] = ['id' => null, 'full_name' => 'BYE', 'is_bye' => true];
    }
    $seeded = seedPlayers($participants, $bracketSize);
    $tsm    = new TimeSlotManager($startTime, $eventEndDateTime, $avgGameTime, $busyMap);

    // ── Round 1 ──────────────────────────────────────────────
    $prevRoundIds = [];
    for ($i = 0; $i < $bracketSize; $i += 2) {
        $p1   = $seeded[$i];
        $p2   = $seeded[$i + 1];
        $bye1 = isset($p1['is_bye']) ? 1 : 0;
        $bye2 = isset($p2['is_bye']) ? 1 : 0;

        $uids = participantUserIds($pdo, $p1, $p2, $isTeam);
        $slot = ($bye1 && $bye2)
            ? new DateTime($startTime)
            : $tsm->allocate($uids);

        $stmt = $pdo->prepare("INSERT INTO matches
            (event_sport_id, round, match_number,
             player1_id, player2_id, player1_name, player2_name,
             is_player1_bye, is_player2_bye,
             scheduled_time, gender, status, bracket_position, stage)
            VALUES (?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, 'stage1')");
        $stmt->execute([
            $eventSportId, $matchNum,
            $p1['id'], $p2['id'],
            $p1['full_name'], $p2['full_name'],
            $bye1, $bye2,
            $slot->format('Y-m-d H:i:s'),
            $gender, $matchNum,
        ]);
        $prevRoundIds[] = (int)$pdo->lastInsertId();
        $matchNum++;
    }

    // ── Subsequent rounds ────────────────────────────────────
    $totalRounds = intval(log($bracketSize, 2));
    for ($round = 2; $round <= $totalRounds; $round++) {
        $currentIds = [];
        for ($i = 0; $i < count($prevRoundIds); $i += 2) {
            $slot = $tsm->allocate([]);   // players unknown yet

            $stmt = $pdo->prepare("INSERT INTO matches
                (event_sport_id, round, match_number,
                 scheduled_time, gender, status, bracket_position, stage)
                VALUES (?, ?, ?, ?, ?, 'pending', ?, 'stage1')");
            $stmt->execute([
                $eventSportId, $round, $matchNum,
                $slot->format('Y-m-d H:i:s'),
                $gender, $matchNum,
            ]);
            $nextId = (int)$pdo->lastInsertId();
            $currentIds[] = $nextId;

            $pdo->prepare("UPDATE matches SET next_match_id=? WHERE id=?")
                ->execute([$nextId, $prevRoundIds[$i]]);
            $pdo->prepare("UPDATE matches SET next_match_id=? WHERE id=?")
                ->execute([$nextId, $prevRoundIds[$i + 1]]);
            $matchNum++;
        }
        $prevRoundIds = $currentIds;
    }

    autoAdvanceByes($pdo, $eventSportId, $gender === 'none' ? null : $gender, 'stage1');
}


// ═══════════════════════════════════════════════════════════════
// DOUBLE ELIMINATION
//
// Structure
// ──────────
// Winners bracket (stage1): standard single-elim seeded bracket.
//   Each match has a loser_match_id pointing to the Losers bracket
//   match where the loser drops.
//
// Losers bracket (stage2): organised in paired rounds.
//   Round L1: losers from W-R1 play each other.
//   Round L2: survivors play losers from W-R2.
//   … and so on until one player remains.
//
// Grand Final (stage1, final round): Winner of W-bracket vs
//   Winner of L-bracket. Scheduled after both brackets finish.
//
// Scheduling: Winners rounds first (they're known), then Losers
//   rounds interleaved to respect day boundaries, then Grand Final.
// ═══════════════════════════════════════════════════════════════

function generateDoubleElimBracket(
    $pdo,
    int    $eventSportId,
    array  $participants,
    bool   $isTeam,
    string $gender,
    string $startTime,
    int    $avgGameTime,
    string $eventEndDateTime,
    array &$busyMap,
    int   &$matchNum
): void {
    $n           = count($participants);
    $bracketSize = nextPowerOfTwo($n);
    $byesNeeded  = $bracketSize - $n;
    for ($i = 0; $i < $byesNeeded; $i++) {
        $participants[] = ['id' => null, 'full_name' => 'BYE', 'is_bye' => true];
    }
    $seeded      = seedPlayers($participants, $bracketSize);
    $totalWRounds = intval(log($bracketSize, 2));
    $tsm         = new TimeSlotManager($startTime, $eventEndDateTime, $avgGameTime, $busyMap);

    /* ── 1. Build Winners bracket ──────────────────────────── */

    // wRounds[round] = [matchId, ...]
    $wRounds = [];

    // Round 1
    $r1Ids = [];
    for ($i = 0; $i < $bracketSize; $i += 2) {
        $p1   = $seeded[$i];
        $p2   = $seeded[$i + 1];
        $bye1 = isset($p1['is_bye']) ? 1 : 0;
        $bye2 = isset($p2['is_bye']) ? 1 : 0;

        $uids = participantUserIds($pdo, $p1, $p2, $isTeam);
        $slot = ($bye1 && $bye2) ? new DateTime($startTime) : $tsm->allocate($uids);

        $stmt = $pdo->prepare("INSERT INTO matches
            (event_sport_id, round, match_number,
             player1_id, player2_id, player1_name, player2_name,
             is_player1_bye, is_player2_bye,
             scheduled_time, gender, status, bracket_position, stage)
            VALUES (?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, 'stage1')");
        $stmt->execute([
            $eventSportId, $matchNum,
            $p1['id'], $p2['id'],
            $p1['full_name'], $p2['full_name'],
            $bye1, $bye2,
            $slot->format('Y-m-d H:i:s'),
            $gender, $matchNum,
        ]);
        $r1Ids[]  = (int)$pdo->lastInsertId();
        $matchNum++;
    }
    $wRounds[1] = $r1Ids;

    // Rounds 2 … totalWRounds-1  (not the final yet)
    $prevIds = $r1Ids;
    for ($round = 2; $round < $totalWRounds; $round++) {
        $curIds = [];
        for ($i = 0; $i < count($prevIds); $i += 2) {
            $slot = $tsm->allocate([]);
            $stmt = $pdo->prepare("INSERT INTO matches
                (event_sport_id, round, match_number,
                 scheduled_time, gender, status, bracket_position, stage)
                VALUES (?, ?, ?, ?, ?, 'pending', ?, 'stage1')");
            $stmt->execute([
                $eventSportId, $round, $matchNum,
                $slot->format('Y-m-d H:i:s'),
                $gender, $matchNum,
            ]);
            $mid = (int)$pdo->lastInsertId();
            $curIds[] = $mid;

            $pdo->prepare("UPDATE matches SET next_match_id=? WHERE id=?")
                ->execute([$mid, $prevIds[$i]]);
            $pdo->prepare("UPDATE matches SET next_match_id=? WHERE id=?")
                ->execute([$mid, $prevIds[$i + 1]]);
            $matchNum++;
        }
        $wRounds[$round] = $curIds;
        $prevIds         = $curIds;
    }

    /* ── 2. Build Losers bracket ───────────────────────────── */
    //
    // Losers bracket has (2 * totalWRounds - 2) rounds.
    // LR1  : losers from WR1 play each other  (bracketSize/4 matches)
    // LR2  : LR1 winners vs losers from WR2   (bracketSize/4 matches)
    // LR3  : LR2 winners play each other       (bracketSize/8 matches)
    // LR4  : LR3 winners vs losers from WR3   …
    // …
    // LR(2k-1) : survivors play each other
    // LR(2k)   : survivors vs losers from WR(k+1)

    $lRounds = [];   // lRounds[lRound] = [matchId, ...]

    // LR1: losers from WR1 play each other
    $lr1Ids = [];
    $wr1Ids = $wRounds[1];
    for ($i = 0; $i < count($wr1Ids); $i += 2) {
        $slot = $tsm->allocate([]);
        $stmt = $pdo->prepare("INSERT INTO matches
            (event_sport_id, round, match_number,
             scheduled_time, gender, status, bracket_position, stage)
            VALUES (?, ?, ?, ?, ?, 'pending', ?, 'stage2')");
        $stmt->execute([
            $eventSportId, $matchNum, $matchNum,
            $slot->format('Y-m-d H:i:s'),
            $gender, $matchNum,
        ]);
        $mid      = (int)$pdo->lastInsertId();
        $lr1Ids[] = $mid;

        // WR1 losers drop into this match
        $pdo->prepare("UPDATE matches SET loser_match_id=? WHERE id=?")
            ->execute([$mid, $wr1Ids[$i]]);
        $pdo->prepare("UPDATE matches SET loser_match_id=? WHERE id=?")
            ->execute([$mid, $wr1Ids[$i + 1]]);
        $matchNum++;
    }
    $lRounds[1]  = $lr1Ids;
    $prevLosersIds = $lr1Ids;

    // For WR rounds 2 … totalWRounds-1, create two LR rounds each:
    //   "drop-in" round (LR survivors vs WR-round losers)
    //   "cull"    round (survivors play each other)
    $lRoundNum = 2;
    for ($wr = 2; $wr < $totalWRounds; $wr++) {
        $wrDropIds = $wRounds[$wr] ?? [];

        // Drop-in: each LR survivor plays a WR loser
        $dropIds = [];
        $lrPrev  = $prevLosersIds;
        for ($i = 0; $i < count($lrPrev); $i++) {
            $slot = $tsm->allocate([]);
            $stmt = $pdo->prepare("INSERT INTO matches
                (event_sport_id, round, match_number,
                 scheduled_time, gender, status, bracket_position, stage)
                VALUES (?, ?, ?, ?, ?, 'pending', ?, 'stage2')");
            $stmt->execute([
                $eventSportId, $matchNum, $matchNum,
                $slot->format('Y-m-d H:i:s'),
                $gender, $matchNum,
            ]);
            $mid      = (int)$pdo->lastInsertId();
            $dropIds[] = $mid;

            // LR survivor feeds into this match
            $pdo->prepare("UPDATE matches SET next_match_id=? WHERE id=?")
                ->execute([$mid, $lrPrev[$i]]);

            // WR loser also drops into this match
            if (isset($wrDropIds[$i])) {
                $pdo->prepare("UPDATE matches SET loser_match_id=? WHERE id=?")
                    ->execute([$mid, $wrDropIds[$i]]);
            }
            $matchNum++;
        }
        $lRounds[$lRoundNum++] = $dropIds;

        // Cull: drop-in winners play each other (halve the field)
        if (count($dropIds) > 1) {
            $cullIds = [];
            for ($i = 0; $i < count($dropIds); $i += 2) {
                $slot = $tsm->allocate([]);
                $stmt = $pdo->prepare("INSERT INTO matches
                    (event_sport_id, round, match_number,
                     scheduled_time, gender, status, bracket_position, stage)
                    VALUES (?, ?, ?, ?, ?, 'pending', ?, 'stage2')");
                $stmt->execute([
                    $eventSportId, $matchNum, $matchNum,
                    $slot->format('Y-m-d H:i:s'),
                    $gender, $matchNum,
                ]);
                $mid       = (int)$pdo->lastInsertId();
                $cullIds[] = $mid;

                if (isset($dropIds[$i])) {
                    $pdo->prepare("UPDATE matches SET next_match_id=? WHERE id=?")
                        ->execute([$mid, $dropIds[$i]]);
                }
                if (isset($dropIds[$i + 1])) {
                    $pdo->prepare("UPDATE matches SET next_match_id=? WHERE id=?")
                        ->execute([$mid, $dropIds[$i + 1]]);
                }
                $matchNum++;
            }
            $lRounds[$lRoundNum++] = $cullIds;
            $prevLosersIds = $cullIds;
        } else {
            $prevLosersIds = $dropIds;
        }
    }

    /* ── 3. Winners Final (WF) — last Winners bracket match ── */
    $slot = $tsm->allocate([]);
    $stmt = $pdo->prepare("INSERT INTO matches
        (event_sport_id, round, match_number,
         scheduled_time, gender, status, bracket_position, stage)
        VALUES (?, ?, ?, ?, ?, 'pending', ?, 'stage1')");
    $stmt->execute([
        $eventSportId, $totalWRounds, $matchNum,
        $slot->format('Y-m-d H:i:s'),
        $gender, $matchNum,
    ]);
    $wFinalId = (int)$pdo->lastInsertId();
    $matchNum++;

    // Link last two Winners bracket matches into WF
    foreach (array_slice($prevIds, 0, 2) as $fid) {
        $pdo->prepare("UPDATE matches SET next_match_id=? WHERE id=?")
            ->execute([$wFinalId, $fid]);
    }
    // WF loser drops into Losers Final
    // (handled after Losers Final is created below)

    /* ── 4. Losers Final ───────────────────────────────────── */
    // Final LR cull: last LR round survivors play each other if > 1
    if (count($prevLosersIds) > 1) {
        $slot = $tsm->allocate([]);
        $stmt = $pdo->prepare("INSERT INTO matches
            (event_sport_id, round, match_number,
             scheduled_time, gender, status, bracket_position, stage)
            VALUES (?, ?, ?, ?, ?, 'pending', ?, 'stage2')");
        $stmt->execute([
            $eventSportId, $matchNum, $matchNum,
            $slot->format('Y-m-d H:i:s'),
            $gender, $matchNum,
        ]);
        $lFinalId = (int)$pdo->lastInsertId();
        foreach ($prevLosersIds as $pid) {
            $pdo->prepare("UPDATE matches SET next_match_id=? WHERE id=?")
                ->execute([$lFinalId, $pid]);
        }
        $matchNum++;
    } else {
        $lFinalId = $prevLosersIds[0];
    }

    // WF loser drops into Losers Final
    $pdo->prepare("UPDATE matches SET loser_match_id=? WHERE id=?")
        ->execute([$lFinalId, $wFinalId]);

    /* ── 5. Grand Final ────────────────────────────────────── */
    $slot = $tsm->allocate([]);
    $stmt = $pdo->prepare("INSERT INTO matches
        (event_sport_id, round, match_number,
         scheduled_time, gender, status, bracket_position, stage)
        VALUES (?, ?, ?, ?, ?, 'pending', ?, 'stage1')");
    $stmt->execute([
        $eventSportId, $totalWRounds + 1, $matchNum,
        $slot->format('Y-m-d H:i:s'),
        $gender, $matchNum,
    ]);
    $grandFinalId = (int)$pdo->lastInsertId();
    $matchNum++;

    // WF winner and LF winner meet in Grand Final
    $pdo->prepare("UPDATE matches SET next_match_id=? WHERE id=?")
        ->execute([$grandFinalId, $wFinalId]);
    $pdo->prepare("UPDATE matches SET next_match_id=? WHERE id=?")
        ->execute([$grandFinalId, $lFinalId]);

    // Auto-advance BYE matches in Winners bracket
    autoAdvanceByes($pdo, $eventSportId, $gender === 'none' ? null : $gender, 'stage1');
}


// ═══════════════════════════════════════════════════════════════
// ROUND ROBIN
// ═══════════════════════════════════════════════════════════════

function generateRoundRobinBracket(
    $pdo,
    int    $eventSportId,
    array  $participants,
    bool   $isTeam,
    string $gender,
    string $startTime,
    int    $avgGameTime,
    string $eventEndDateTime,
    array &$busyMap,
    int   &$matchNum
): void {
    $n = count($participants);
    if ($n < 2) return;

    // Add phantom BYE for odd-player round robin (circle algorithm)
    $hasBye = false;
    if ($n % 2 !== 0) {
        $participants[] = ['id' => null, 'full_name' => 'BYE', 'is_bye' => true];
        $n++;
        $hasBye = true;
    }

    $tsm   = new TimeSlotManager($startTime, $eventEndDateTime, $avgGameTime, $busyMap);
    $fixed = $participants[0];   // participant 0 stays fixed
    $rot   = array_slice($participants, 1); // rotate the rest
    $rounds = $n - 1;

    for ($round = 1; $round <= $rounds; $round++) {
        // Build current round's schedule using circle method
        $roundPairs = [];
        $circle     = array_merge([$fixed], $rot);
        for ($i = 0; $i < $n / 2; $i++) {
            $roundPairs[] = [$circle[$i], $circle[$n - 1 - $i]];
        }

        foreach ($roundPairs as [$p1, $p2]) {
            $bye1 = isset($p1['is_bye']) ? 1 : 0;
            $bye2 = isset($p2['is_bye']) ? 1 : 0;

            // Skip phantom-BYE matches
            if ($bye1 || $bye2) {
                $matchNum++;
                continue;
            }

            $uids = participantUserIds($pdo, $p1, $p2, $isTeam);
            $slot = $tsm->allocate($uids);

            $stmt = $pdo->prepare("INSERT INTO matches
                (event_sport_id, round, match_number,
                 player1_id, player2_id, player1_name, player2_name,
                 is_player1_bye, is_player2_bye,
                 scheduled_time, gender, status, bracket_position, stage)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, 'stage1')");
            $stmt->execute([
                $eventSportId, $round, $matchNum,
                $p1['id'], $p2['id'],
                $p1['full_name'], $p2['full_name'],
                0, 0,
                $slot->format('Y-m-d H:i:s'),
                $gender, $matchNum,
            ]);
            $matchNum++;
        }

        // Rotate: last element of $rot goes to front
        array_unshift($rot, array_pop($rot));
    }
}


// ═══════════════════════════════════════════════════════════════
// PUBLIC ENTRY POINTS
// ═══════════════════════════════════════════════════════════════

/**
 * Generate bracket for an individual sport (dispatches by bracket_type).
 */
function generateBracket(
    $pdo,
    int    $eventSportId,
    string $startTime,
    int    $avgGameTime,
    string $placementType,
    string $eventEndDateTime,
    ?string $gender,
    array  &$busyMap
): bool {
    // Fetch bracket_type from DB
    $stmt = $pdo->prepare("SELECT bracket_type FROM event_sports WHERE id=?");
    $stmt->execute([$eventSportId]);
    $bracketType = $stmt->fetchColumn() ?: 'single_elim_one';

    $players = getPlayersForSport($pdo, $eventSportId, $gender);
    if (count($players) < 2) return false;
    if ($placementType === 'random') shuffle($players);

    $g        = $gender ?? 'male';
    $matchNum = _nextMatchNum($pdo, $eventSportId);

    switch ($bracketType) {
        case 'double_elim':
            generateDoubleElimBracket(
                $pdo, $eventSportId, $players, false,
                $g, $startTime, $avgGameTime, $eventEndDateTime,
                $busyMap, $matchNum
            );
            break;

        case 'round_robin':
            generateRoundRobinBracket(
                $pdo, $eventSportId, $players, false,
                $g, $startTime, $avgGameTime, $eventEndDateTime,
                $busyMap, $matchNum
            );
            break;

        default: // single_elim_one, single_elim_two
            generateSingleElimBracket(
                $pdo, $eventSportId, $players, false,
                $g, $startTime, $avgGameTime, $eventEndDateTime,
                $busyMap, $matchNum
            );
    }

    return true;
}

/**
 * Generate bracket for a team sport (dispatches by bracket_type).
 */
function generateTeamBracket(
    $pdo,
    int    $eventSportId,
    string $startTime,
    int    $avgGameTime,
    string $placementType,
    string $eventEndDateTime,
    array  &$busyMap
): bool {
    $stmt = $pdo->prepare("SELECT bracket_type FROM event_sports WHERE id=?");
    $stmt->execute([$eventSportId]);
    $bracketType = $stmt->fetchColumn() ?: 'single_elim_one';

    $teams = getTeamsForSport($pdo, $eventSportId);
    if (count($teams) < 2) return false;

    if ($placementType === 'random') {
        $n = count($teams);
        for ($i = $n - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$teams[$i], $teams[$j]] = [$teams[$j], $teams[$i]];
        }
    }

    $matchNum = _nextMatchNum($pdo, $eventSportId);
    $gender   = 'male'; // teams are gender-neutral; use placeholder

    switch ($bracketType) {
        case 'double_elim':
            generateDoubleElimBracket(
                $pdo, $eventSportId, $teams, true,
                $gender, $startTime, $avgGameTime, $eventEndDateTime,
                $busyMap, $matchNum
            );
            break;

        case 'round_robin':
            generateRoundRobinBracket(
                $pdo, $eventSportId, $teams, true,
                $gender, $startTime, $avgGameTime, $eventEndDateTime,
                $busyMap, $matchNum
            );
            break;

        default:
            generateSingleElimBracket(
                $pdo, $eventSportId, $teams, true,
                $gender, $startTime, $avgGameTime, $eventEndDateTime,
                $busyMap, $matchNum
            );
    }

    return true;
}

/** Return the next available match_number for an event_sport. */
function _nextMatchNum($pdo, int $eventSportId): int {
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(match_number),0)+1 FROM matches WHERE event_sport_id=?");
    $stmt->execute([$eventSportId]);
    return (int)$stmt->fetchColumn();
}


// ── Auto-generate teams (unchanged) ──────────────────────────

function generateTeams($pdo, $eventSportId, $maxTeams, $membersPerTeam): bool {
    $players      = getPlayersForSport($pdo, $eventSportId);
    $totalPlayers = count($players);
    if ($totalPlayers < 2) return false;

    $numTeams       = min($maxTeams, floor($totalPlayers / max(1, $membersPerTeam - 1)));
    if ($numTeams < 2) $numTeams = 2;
    $playersPerTeam = floor($totalPlayers / $numTeams);
    $extra          = $totalPlayers % $numTeams;

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