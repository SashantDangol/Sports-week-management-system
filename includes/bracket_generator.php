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


/**
 * Fetches distinct users registered for the specified sport, optionally filtered by registration gender.
 *
 * @param int $eventSportId The event sport identifier to match against registrations.
 * @param string|null $gender Optional registration gender to filter results (e.g., 'male', 'female'); pass null to omit gender filtering.
 * @return array An ordered list (by full_name) of rows each containing `id` and `full_name` for matching users.
 */

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

/**
 * Fetches teams associated with an event sport, ordered by team name.
 *
 * @param int $eventSportId The `event_sport` identifier to filter teams by.
 * @return array<int, array{ id: mixed, full_name: string }> Ordered list of teams with keys `id` and `full_name` (team_name).
 */
function getTeamsForSport($pdo, $eventSportId): array {
    $stmt = $pdo->prepare("SELECT id, team_name AS full_name FROM teams
                           WHERE event_sport_id = ? ORDER BY team_name");
    $stmt->execute([$eventSportId]);
    return $stmt->fetchAll();
}

/**
 * Compute the smallest power of two greater than or equal to the given integer.
 *
 * @param int $n The input integer.
 * @return int The smallest power of two that is >= $n (returns 1 for values <= 1).
 */
function nextPowerOfTwo(int $n): int {
    $p = 1;
    while ($p < $n) $p *= 2;
    return $p;
}

/**
 * Map a participant identifier to the individual user IDs used for conflict checking.
 *
 * @param int|null $participantId The participant identifier: a user ID when not a team, or a team ID when `$isTeamSport` is true; if falsy, no users are returned.
 * @param bool $isTeamSport Whether the participant represents a team (`true`) or an individual (`false`).
 * @return int[] An array of user IDs belonging to the participant; empty if `$participantId` is falsy or the team has no members.
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

    /**
     * Initialize the time-slot manager with the event window, slot duration, and existing busy intervals.
     *
     * Sets the internal clock to the provided event start datetime and extracts the per-day window start
     * and end hours/minutes used for slot allocation.
     *
     * @param string $eventStartDatetime Datetime string used to set the initial scheduling clock and daily window start.
     * @param string $eventEndDatetime Datetime string used to determine the daily window end time.
     * @param int $slotMinutes Duration of each scheduling slot in minutes.
     * @param array $busyMap Initial map of busy intervals keyed by user id (each value is an array of intervals).
     */
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
         * Finds the next available time slot at or after the manager's current clock where all specified users are free and the full slot fits inside the daily window.
         *
         * Marks the users busy for the allocated interval and advances the manager's internal clock to the end of that slot.
         *
         * @param int[] $userIds List of user IDs to check for conflicts; an empty array means no conflict checks.
         * @return DateTime The start time of the allocated slot.
         * @throws Exception If no suitable slot is found within the internal search limit.
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

    /**
     * Compute the daily window end for the given date using the manager's configured end hour and minute.
     *
     * @param DateTime $dt The date for which to compute the day-end.
     * @return DateTime A cloned DateTime set to the same date with time set to this manager's configured end hour and minute.
     */

    private function dayEndFor(DateTime $dt): DateTime {
        return (clone $dt)->setTime($this->endHour, $this->endMin, 0);
    }

    /**
     * Compute the daily window start for the given date using the manager's configured start hour and minute.
     *
     * @param DateTime $dt The date for which to compute the window start.
     * @return DateTime A DateTime set to the same date as `$dt` with time set to the manager's start hour and minute.
     */
    private function dayStartFor(DateTime $dt): DateTime {
        return (clone $dt)->setTime($this->startHour, $this->startMin, 0);
    }

    /**
     * Snap a DateTime into the daily scheduling window by moving times at or after the day's end to the next day's window start.
     *
     * @param DateTime $dt The date/time to snap.
     * @return DateTime The original `$dt` if it falls before the day's end; otherwise the next day's window start.
     */
    private function snapToDayWindow(DateTime $dt): DateTime {
        return $dt >= $this->dayEndFor($dt) ? $this->nextDayStart($dt) : $dt;
    }

    /**
     * Get the scheduling window start for the day after the given date.
     *
     * @param DateTime $dt Reference date from which to compute the next day's window.
     * @return DateTime The start datetime of the daily window on the next calendar day.
     */
    private function nextDayStart(DateTime $dt): DateTime {
        return $this->dayStartFor((clone $dt)->modify('+1 day'));
    }

    /**
     * Checks whether any of the given users has a busy interval that overlaps the specified time range.
     *
     * @param int[] $uids User IDs to check for conflicts.
     * @param DateTime $s Start of the interval to test.
     * @param DateTime $e End of the interval to test.
     * @return bool `true` if any user's busy interval overlaps the interval (i.e. `start < busy.end` and `end > busy.start`), `false` otherwise.
     */
    private function hasConflict(array $uids, DateTime $s, DateTime $e): bool {
        foreach ($uids as $uid) {
            foreach ($this->busyMap[$uid] ?? [] as $b) {
                if ($s < $b['end'] && $e > $b['start']) return true;
            }
        }
        return false;
    }

    /**
     * Mark the given users as busy for the specified time interval.
     *
     * Each user's busy list receives a cloned interval entry with the provided start (inclusive)
     * and end (exclusive) datetimes.
     *
     * @param int[]    $uids List of user IDs to mark busy.
     * @param DateTime $s    Start of the busy interval (inclusive).
     * @param DateTime $e    End of the busy interval (exclusive).
     */
    private function markBusy(array $uids, DateTime $s, DateTime $e): void {
        foreach ($uids as $uid) {
            $this->busyMap[$uid][] = ['start' => clone $s, 'end' => clone $e];
        }
    }
}


/**
 * Produces a bracket-sized ordered list of participants by inserting BYE entries as needed.
 *
 * Takes the provided participants (some may already be marked as BYE) and returns an array
 * of length `$bracketSize` where real participants retain their original entries and
 * additional BYE entries are inserted to fill remaining slots. BYE entries are represented
 * as `['id' => null, 'full_name' => 'BYE', 'is_bye' => true]` and are distributed to
 * balance the bracket according to seeding parity.
 *
 * @param array $players List of participant records; records may include an `'is_bye'` flag.
 * @param int $bracketSize Desired total number of slots in the bracket (power-of-two).
 * @return array An ordered array of length `$bracketSize` containing original participant
 *               records and inserted BYE records where necessary.
 */

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


/**
 * Marks round‑1 matches that include BYEs as completed and advances any real participant.
 *
 * For round 1 matches of the given event sport (and optional gender/stage), this updates matches
 * where exactly one player is marked as a BYE by setting the non‑BYE player as the winner,
 * recording both scores as `'BYE'`, and advancing that winner into the next match slot.
 * If both players are BYEs the match is marked completed. Matches without BYEs are untouched.
 *
 * @param int $eventSportId The event_sports.id value identifying the sport event.
 * @param string|null $gender Optional gender filter applied to matched rows; pass null to ignore.
 * @param string $stage The bracket stage to process (default: 'stage1').
 */

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

/**
 * Advance a match winner into the next scheduled match's first available player slot.
 *
 * If the completed match has a `next_match_id` and that next match exists, inserts the winner's id
 * and name into the first empty player slot (`player1` if empty, otherwise `player2`) and clears
 * the corresponding `is_player*_bye` flag. Does nothing if `next_match_id` is missing or the next
 * match cannot be found.
 *
 * @param int $matchId The ID of the completed match whose winner is advancing.
 * @param int|null $winnerId The advancing winner's user or participant ID.
 * @param string $winnerName The advancing winner's display name.
 */
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
 * Collects unique user IDs represented by two bracket participants, skipping BYE slots.
 *
 * @param array $p1 Participant data for player one. Expected keys: `'id'` (participant id) and optional `'is_bye'`.
 * @param array $p2 Participant data for player two. Expected keys: `'id'` (participant id) and optional `'is_bye'`.
 * @param bool $isTeam True when participants are teams; false when participants are individual users.
 * @return int[] Unique user IDs for both participants (empty if both are BYEs).
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
/**
 * Build and persist a single-elimination bracket for the given participants and schedule its matches.
 *
 * Pads participants with BYEs to the next power-of-two, seeds participants, creates and inserts round-1 match rows
 * (scheduling each match while respecting user busy intervals), creates subsequent-round match placeholders linked
 * via `next_match_id`, advances BYE winners for stage 'stage1', and increments the shared match counter.
 *
 * @param PDO $pdo Database connection used to insert and update match rows.
 * @param int $eventSportId Identifier of the event sport for which the bracket is being generated.
 * @param array $participants Ordered list of participants; each item is an associative array with at least `id` and `full_name`, and optional `is_bye`.
 * @param bool $isTeam True when participants represent teams (affects user-resolution for conflict checking).
 * @param string $gender Gender value to store on match rows (use 'none' to indicate no gender filter).
 * @param string $startTime Datetime string used as the earliest possible scheduled time for matches (e.g., event start).
 * @param int $avgGameTime Average match duration in minutes used to size scheduling slots.
 * @param string $eventEndDateTime Datetime string that defines the event scheduling window end for the TimeSlotManager.
 * @param array &$busyMap Reference to the shared busy-interval map used and mutated by the scheduler; entries are appended for allocated slots.
 * @param int &$matchNum Reference to a shared match-number counter; the function increments this as it inserts matches.
 */

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
/**
 * Builds and schedules a full double-elimination bracket and inserts all matches into the database.
 *
 * Seeds participants (adding BYEs to the next power-of-two), constructs the winners and losers brackets
 * (including inter-bracket links via `next_match_id` and `loser_match_id`), schedules every match
 * using a TimeSlotManager that respects per-user busy intervals, and auto-advances BYE results in the
 * winners bracket.
 *
 * @param int $eventSportId The event_sport identifier for which matches are created.
 * @param array $participants Array of participant entries (each item with keys `id`, `full_name` and optional `is_bye`).
 * @param bool $isTeam True when participants represent teams, false for individual participants.
 * @param string $gender Gender tag stored on created matches (use `'none'` to leave gender unset for auto-advancement).
 * @param string $startTime Event start datetime string used to initialize per-sport scheduling cursor.
 * @param int $avgGameTime Average game duration in minutes used to size scheduling slots.
 * @param string $eventEndDateTime Event end datetime string used to bound daily scheduling windows.
 * @param array &$busyMap Reference to a map of busy intervals per user id; this function reads and appends intervals when scheduling.
 * @param int &$matchNum Reference to the next match_number to use; incremented for every inserted match.
 */

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
/**
 * Generate and insert a round-robin schedule for the given participants and allocate time slots.
 *
 * Inserts match rows into the `matches` table for each non-BYE pairing using the circle-method
 * rotation and schedules each fixture with TimeSlotManager while observing and updating the
 * provided busy map.
 *
 * @param int $eventSportId The event_sports.id for which matches are being created.
 * @param array $participants List of participant records (each must include `id` and `full_name`; a phantom BYE may be present as `['id'=>null,'full_name'=>'BYE','is_bye'=>true]`).
 * @param bool $isTeam True when participants represent teams (affects participant-to-user resolution).
 * @param string $gender Gender value to store on created matches.
 * @param string $startTime Event start datetime string used to initialize the scheduler.
 * @param int $avgGameTime Average match duration in minutes used to size time slots.
 * @param string $eventEndDateTime Event end datetime string used to bound scheduling.
 * @param array &$busyMap By-reference map of user busy intervals used for conflict detection; this function updates the map via the TimeSlotManager when allocating slots.
 * @param int &$matchNum By-reference match number counter; incremented for every produced (or skipped due to phantom BYE) match and used as the `match_number` and `bracket_position` for inserted rows.
 */

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
 * Build and insert a match schedule for an individual sport, selecting the bracket generator
 * based on the event's `bracket_type`.
 *
 * @param PDO $pdo Database connection used for reads and match/team inserts.
 * @param int $eventSportId ID of the event_sports row to generate a bracket for.
 * @param string $startTime ISO 8601 datetime (or MySQL datetime string) representing the earliest desired match start.
 * @param int $avgGameTime Average match duration in minutes used to size scheduling slots.
 * @param string $placementType Placement strategy; when `'random'` participants are shuffled before seeding.
 * @param string $eventEndDateTime ISO 8601 datetime (or MySQL datetime string) indicating the final scheduling boundary.
 * @param string|null $gender Optional gender filter used when fetching registered players; `null` uses both/neutral.
 * @param array &$busyMap Reference to the shared busy-interval map used by the scheduler to avoid conflicts.
 * @return bool `true` if a bracket generation routine was executed; `false` when there are fewer than two registered players.
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
 * Builds and inserts a competition bracket for a team sport, selecting the bracket type from event_sports.
 *
 * Generates and schedules matches by dispatching to the appropriate generator (single-elim, double-elim, or
 * round-robin) and persists them to the database. When `$placementType` is `"random"`, team order is randomized
 * before seeding.
 *
 * @param int $eventSportId The event_sports.id identifying the sport.
 * @param string $startTime The event start datetime used as the initial scheduling cursor.
 * @param int $avgGameTime Average match duration in minutes used for slot allocation.
 * @param string $placementType `"random"` to shuffle teams; other values preserve the fetched order.
 * @param string $eventEndDateTime The event end datetime used to define daily scheduling windows.
 * @param array &$busyMap Reference to the shared busy-map for conflict-aware scheduling; modified in-place.
 * @return bool `true` if bracket generation was performed, `false` if there were fewer than two teams.
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

/**
 * Compute the next match number for the specified event sport.
 *
 * @param int $eventSportId The event_sport.id to query.
 * @return int The next available `match_number` (existing maximum + 1; `1` if none exist).
 */
function _nextMatchNum($pdo, int $eventSportId): int {
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(match_number),0)+1 FROM matches WHERE event_sport_id=?");
    $stmt->execute([$eventSportId]);
    return (int)$stmt->fetchColumn();
}


/**
 * Create teams for an event sport by distributing registered players into numbered teams.
 *
 * Players registered for the given event sport are shuffled and assigned to up to `$maxTeams` teams.
 * The function computes the actual number of teams based on available players and the requested
 * `$membersPerTeam`, ensures at least two teams, and distributes any remainder players one-per-team
 * among the first teams. Each created team is inserted into `teams` and its members into `team_members`.
 *
 * @param int $eventSportId The event_sport identifier to create teams for.
 * @param int $maxTeams The maximum number of teams to create.
 * @param int $membersPerTeam The target number of members per team used to derive the number of teams.
 * @return bool `true` on successful team creation, `false` if fewer than two players are available.
 */

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