import os

def replace_in_file(filepath, search_str, replace_str):
    if not os.path.exists(filepath):
        print(f"File not found: {filepath}")
        return False
    with open(filepath, 'r', encoding='utf-8') as f:
        content = f.read()
    
    normalized_content = content.replace('\r\n', '\n')
    normalized_search = search_str.replace('\r\n', '\n')
    normalized_replace = replace_str.replace('\r\n', '\n')
    
    if normalized_search in normalized_content:
        new_content = normalized_content.replace(normalized_search, normalized_replace)
        if '\r\n' in content:
            new_content = new_content.replace('\n', '\r\n')
        with open(filepath, 'w', encoding='utf-8') as f:
            f.write(new_content)
        print(f"Successfully modified: {filepath}")
        return True
    else:
        print(f"Search string not found in: {filepath}")
        return False

# 1. Update includes/admin-helpers.php
helpers_path = r"includes/admin-helpers.php"

# shared_split replacement
search_split = """        if ($tiedMode === 'shared_split') {
            $sumPoints = 0;
            for ($i = 0; $i < $count; $i++) {
                $pos = $position + $i;
                $sumPoints += isset($pointConfig[$pos]) ? $pointConfig[$pos] : 0;
            }
            $teamScore = round($sumPoints / $count, 2);
            $rank = $position;

            foreach ($groupEntries as $e) {
                $eMark = (float)($e['total_mark'] ?? $score);
                $gradeInfo = admin_calculate_grade_info($eMark, $judgesCount, $settings);
                $eBonus = $isMarkBased ? (float)$gradeInfo['grade_points'] : 0;
                if ($rank >= 1 && $rank <= 3) {
                    $eBonus = 0.0;
                }
                $entryTeamScore = $teamScore + $eBonus;
                $update->execute([$finalScore, $rank, $entryTeamScore, $gradeInfo['grade'], $eBonus, 'completed', (int)$e['id'], $eventId, $programId]);
            }
            $position += $count;"""

replace_split = """        if ($tiedMode === 'shared_split') {
            $sumPoints = 0;
            for ($i = 0; $i < $count; $i++) {
                $pos = $position + $i;
                $sumPoints += isset($pointConfig[$pos]) ? $pointConfig[$pos] : 0;
            }
            $teamScore = round($sumPoints / $count, 2);
            $rank = $position;

            foreach ($groupEntries as $e) {
                $eMark = (float)($e['total_mark'] ?? $score);
                $gradeInfo = admin_calculate_grade_info($eMark, $judgesCount, $settings);
                $eBonus = $isMarkBased ? (float)$gradeInfo['grade_points'] : 0;
                if ($rank >= 1 && $rank <= 3) {
                    $eBonus = 0.0;
                }
                $finalRank = $rank;
                if (!isset($pointConfig[3]) && $finalRank >= 3) {
                    $finalRank = null;
                }
                $entryTeamScore = $teamScore + $eBonus;
                $update->execute([$finalScore, $finalRank, $entryTeamScore, $gradeInfo['grade'], $eBonus, 'completed', (int)$e['id'], $eventId, $programId]);
            }
            $position += $count;"""

replace_in_file(helpers_path, search_split, replace_split)


# shared_sequential replacement
search_seq = """        } elseif ($tiedMode === 'shared_sequential') {
            $rank = $seqRank;
            $teamScore = isset($pointConfig[$rank]) ? $pointConfig[$rank] : 0;

            foreach ($groupEntries as $e) {
                $eMark = (float)($e['total_mark'] ?? $score);
                $gradeInfo = admin_calculate_grade_info($eMark, $judgesCount, $settings);
                $eBonus = $isMarkBased ? (float)$gradeInfo['grade_points'] : 0;
                if ($rank >= 1 && $rank <= 3) {
                    $eBonus = 0.0;
                }
                $entryTeamScore = $teamScore + $eBonus;
                $update->execute([$finalScore, $rank, $entryTeamScore, $gradeInfo['grade'], $eBonus, 'completed', (int)$e['id'], $eventId, $programId]);
            }
            $position += $count;"""

replace_seq = """        } elseif ($tiedMode === 'shared_sequential') {
            $rank = $seqRank;
            $teamScore = isset($pointConfig[$rank]) ? $pointConfig[$rank] : 0;

            foreach ($groupEntries as $e) {
                $eMark = (float)($e['total_mark'] ?? $score);
                $gradeInfo = admin_calculate_grade_info($eMark, $judgesCount, $settings);
                $eBonus = $isMarkBased ? (float)$gradeInfo['grade_points'] : 0;
                if ($rank >= 1 && $rank <= 3) {
                    $eBonus = 0.0;
                }
                $finalRank = $rank;
                if (!isset($pointConfig[3]) && $finalRank >= 3) {
                    $finalRank = null;
                }
                $entryTeamScore = $teamScore + $eBonus;
                $update->execute([$finalScore, $finalRank, $entryTeamScore, $gradeInfo['grade'], $eBonus, 'completed', (int)$e['id'], $eventId, $programId]);
            }
            $position += $count;"""

replace_in_file(helpers_path, search_seq, replace_seq)


# tie_breaker replacement
search_tie = """        } elseif ($tiedMode === 'tie_breaker') {
            foreach ($groupEntries as $e) {
                $rank = $position;
                $teamScore = isset($pointConfig[$rank]) ? $pointConfig[$rank] : 0;
                $eMark = (float)($e['total_mark'] ?? $score);
                $gradeInfo = admin_calculate_grade_info($eMark, $judgesCount, $settings);
                $eBonus = $isMarkBased ? (float)$gradeInfo['grade_points'] : 0;
                if ($rank >= 1 && $rank <= 3) {
                    $eBonus = 0.0;
                }
                $entryTeamScore = $teamScore + $eBonus;
                $update->execute([$finalScore, $rank, $entryTeamScore, $gradeInfo['grade'], $eBonus, 'completed', (int)$e['id'], $eventId, $programId]);
                $position++;
            }"""

replace_tie = """        } elseif ($tiedMode === 'tie_breaker') {
            foreach ($groupEntries as $e) {
                $rank = $position;
                $teamScore = isset($pointConfig[$rank]) ? $pointConfig[$rank] : 0;
                $eMark = (float)($e['total_mark'] ?? $score);
                $gradeInfo = admin_calculate_grade_info($eMark, $judgesCount, $settings);
                $eBonus = $isMarkBased ? (float)$gradeInfo['grade_points'] : 0;
                if ($rank >= 1 && $rank <= 3) {
                    $eBonus = 0.0;
                }
                $finalRank = $rank;
                if (!isset($pointConfig[3]) && $finalRank >= 3) {
                    $finalRank = null;
                }
                $entryTeamScore = $teamScore + $eBonus;
                $update->execute([$finalScore, $finalRank, $entryTeamScore, $gradeInfo['grade'], $eBonus, 'completed', (int)$e['id'], $eventId, $programId]);
                $position++;
            }"""

replace_in_file(helpers_path, search_tie, replace_tie)


# shared_full replacement
search_full = """        } else {
            // shared_full
            $rank = $position;

            $teamScore = 0;
            if ($idx === 0) {
                $teamScore = $pointConfig[1] ?? 0;
            } elseif ($idx === 1) {
                $c1 = $groupCounts[0];
                if ($c1 === 1) {
                    $teamScore = $pointConfig[2] ?? 0;
                } elseif ($c1 === 2) {
                    $teamScore = $pointConfig[3] ?? 0;
                } else {
                    $teamScore = 0;
                }
            } elseif ($idx === 2) {
                $c1 = $groupCounts[0];
                $c2 = $groupCounts[1];
                if ($c1 === 1 && $c2 === 1) {
                    $teamScore = $pointConfig[3] ?? 0;
                } else {
                    $teamScore = 0;
                }
            } else {
                $teamScore = 0;
            }

            foreach ($groupEntries as $e) {
                $eMark = (float)($e['total_mark'] ?? $score);
                $gradeInfo = admin_calculate_grade_info($eMark, $judgesCount, $settings);
                $eBonus = $isMarkBased ? (float)$gradeInfo['grade_points'] : 0;
                if ($rank >= 1 && $rank <= 3) {
                    $eBonus = 0.0;
                }
                $entryTeamScore = $teamScore + $eBonus;
                $update->execute([$finalScore, $rank, $entryTeamScore, $gradeInfo['grade'], $eBonus, 'completed', (int)$e['id'], $eventId, $programId]);
            }"""

replace_full = """        } else {
            // shared_full
            $rank = $position;

            $teamScore = 0;
            if ($idx === 0) {
                $teamScore = $pointConfig[1] ?? 0;
            } elseif ($idx === 1) {
                $c1 = $groupCounts[0];
                if ($c1 === 1) {
                    $teamScore = $pointConfig[2] ?? 0;
                } elseif ($c1 === 2) {
                    $teamScore = $pointConfig[3] ?? 0;
                } else {
                    $teamScore = 0;
                }
            } elseif ($idx === 2) {
                $c1 = $groupCounts[0];
                $c2 = $groupCounts[1];
                if ($c1 === 1 && $c2 === 1) {
                    $teamScore = $pointConfig[3] ?? 0;
                } else {
                    $teamScore = 0;
                }
            } else {
                $teamScore = 0;
            }

            foreach ($groupEntries as $e) {
                $eMark = (float)($e['total_mark'] ?? $score);
                $gradeInfo = admin_calculate_grade_info($eMark, $judgesCount, $settings);
                $eBonus = $isMarkBased ? (float)$gradeInfo['grade_points'] : 0;
                if ($rank >= 1 && $rank <= 3) {
                    $eBonus = 0.0;
                }
                $finalRank = $rank;
                if (!isset($pointConfig[3]) && $finalRank >= 3) {
                    $finalRank = null;
                }
                $entryTeamScore = $teamScore + $eBonus;
                $update->execute([$finalScore, $finalRank, $entryTeamScore, $gradeInfo['grade'], $eBonus, 'completed', (int)$e['id'], $eventId, $programId]);
            }"""

replace_in_file(helpers_path, search_full, replace_full)


# 2. Update admin/score-update/score-approval.php
approval_path = r"admin/score-update/score-approval.php"

# rank preview calculation override
search_approval_loop = """                    foreach ($groupEntries as $entry) {
                        $gradeInfo = admin_calculate_grade_info((float)$entry['final_total'], $judgesCount, $settings);
                        $gradeBonus = $isMarkBased ? (float)$gradeInfo['grade_points'] : 0;
                        if ($rank >= 1 && $rank <= 3) {
                            $gradeBonus = 0;
                        }
                        $entry['rank'] = $rank;
                        $entry['grade'] = $gradeInfo['grade'];"""

replace_approval_loop = """                    foreach ($groupEntries as $entry) {
                        $gradeInfo = admin_calculate_grade_info((float)$entry['final_total'], $judgesCount, $settings);
                        $gradeBonus = $isMarkBased ? (float)$gradeInfo['grade_points'] : 0;
                        if ($rank >= 1 && $rank <= 3) {
                            $gradeBonus = 0;
                        }
                        $finalRank = $rank;
                        if (!isset($pointConfig[3]) && $finalRank >= 3) {
                            $finalRank = null;
                        }
                        $entry['rank'] = $finalRank;
                        $entry['grade'] = $gradeInfo['grade'];"""

replace_in_file(approval_path, search_approval_loop, replace_approval_loop)


# Render overrides
search_approval_render = """                                         <td style="text-align: center; color: #34d399; font-weight: 800;">+<?= number_format((float)($e['grade_bonus'] ?? 0), 0) ?></td>
                                         <td><strong><?= (int)$e['rank'] ?></strong></td>
                                         <td><strong><?= (int)$e['team_points'] ?></strong></td>"""

replace_approval_render = """                                         <td style="text-align: center; color: #34d399; font-weight: 800;">+<?= number_format((float)($e['grade_bonus'] ?? 0), 0) ?></td>
                                         <td><strong><?= $e['rank'] ? (int)$e['rank'] : '—' ?></strong></td>
                                         <td><strong><?= (int)$e['team_points'] ?></strong></td>"""

replace_in_file(approval_path, search_approval_render, replace_approval_render)
