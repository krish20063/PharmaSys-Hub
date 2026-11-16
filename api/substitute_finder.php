<?php
/**
 * PharmaSys Hub - Clinical Substitute & Bioequivalence Finder API
 * Simple, accurate composition comparison with clear Dosage & Price differences.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

handle_api_cors();
header('Content-Type: application/json');

// Auth Guard: Requires valid session
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required to search active pharmaceutical inventory.']);
    exit;
}

enforce_rate_limit('substitute_finder', 120, 60);

$query = trim($_GET['q'] ?? '');
$refId = clean_int($_GET['ref_id'] ?? 0);

if (empty($query) && $refId <= 0) {
    echo json_encode([
        'success' => true,
        'query' => '',
        'primary_results' => [],
        'reference_drug' => null,
        'substitutes' => []
    ]);
    exit;
}

$pdo = Database::getConnection();

try {
    $referenceDrug = null;
    $primaryResults = [];

    // If a specific reference medicine ID is passed, load it directly
    if ($refId > 0) {
        $refStmt = $pdo->prepare("SELECT * FROM medicine_master WHERE id = :id");
        $refStmt->execute([':id' => $refId]);
        $referenceDrug = $refStmt->fetch(PDO::FETCH_ASSOC);
    }

    // Search matched medicines for the query
    if (!empty($query)) {
        $stmt = $pdo->prepare("
            SELECT m.*
            FROM medicine_master m
            WHERE m.brand_name LIKE :q OR m.generic_name LIKE :q OR m.salt_composition LIKE :q
            ORDER BY 
              CASE WHEN m.brand_name LIKE :q_exact THEN 1
                   WHEN m.brand_name LIKE :q_start THEN 2
                   ELSE 3 END,
              m.brand_name ASC
            LIMIT 8
        ");
        $stmt->execute([
            ':q' => "%{$query}%",
            ':q_exact' => $query,
            ':q_start' => "{$query}%"
        ]);
        $primaryResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // If no explicit reference drug selected yet, use the first matching result
    if (!$referenceDrug && !empty($primaryResults)) {
        $referenceDrug = $primaryResults[0];
    }

    $substitutes = [];

    if ($referenceDrug) {
        // Query ALL alternative medicines sharing the EXACT active salt composition
        // Exclude the reference medicine itself (m.id != :ref_id)
        $subStmt = $pdo->prepare("
            SELECT m.*
            FROM medicine_master m
            WHERE m.salt_composition = :salt AND m.id != :ref_id
            ORDER BY ABS(m.strength_mg - :ref_strength) ASC, m.price_mrp ASC
        ");
        $subStmt->execute([
            ':salt' => $referenceDrug['salt_composition'],
            ':ref_id' => $referenceDrug['id'],
            ':ref_strength' => $referenceDrug['strength_mg']
        ]);
        $rawAlternatives = $subStmt->fetchAll(PDO::FETCH_ASSOC);

        $refStrength = (int)$referenceDrug['strength_mg'];
        $refPrice = (float)$referenceDrug['price_mrp'];

        // Determine lowest absolute difference and lowest price among alternatives
        $minAbsDiff = PHP_INT_MAX;
        $lowestPrice = PHP_FLOAT_MAX;

        foreach ($rawAlternatives as $alt) {
            $absDiff = abs((int)$alt['strength_mg'] - $refStrength);
            if ($absDiff < $minAbsDiff) {
                $minAbsDiff = $absDiff;
            }
            if ((float)$alt['price_mrp'] < $lowestPrice) {
                $lowestPrice = (float)$alt['price_mrp'];
            }
        }

        // Build deduplicated, clean comparison objects
        foreach ($rawAlternatives as $alt) {
            $altStrength = (int)$alt['strength_mg'];
            $altPrice = (float)$alt['price_mrp'];

            $mgDiff = $altStrength - $refStrength;
            $absDiff = abs($mgDiff);

            // Plain english difference labels
            if ($mgDiff === 0) {
                $diffLabel = "0 mg difference (Exact {$altStrength}mg match)";
                $diffStatus = "exact";
            } elseif ($mgDiff > 0) {
                $diffLabel = "+{$mgDiff} mg higher ({$altStrength}mg vs {$refStrength}mg)";
                $diffStatus = "higher";
            } else {
                $diffLabel = "{$mgDiff} mg lower ({$altStrength}mg vs {$refStrength}mg)";
                $diffStatus = "lower";
            }

            // Price difference: reference price minus alternative price
            // If positive: saving money; if negative: more expensive
            $priceDiff = round($refPrice - $altPrice, 2);
            $savingPercent = ($refPrice > 0 && $priceDiff > 0) ? round(($priceDiff / $refPrice) * 100) : 0;

            // Only mark the single best alternative with minimum dosage difference and best price
            $isMinDiff = ($absDiff === $minAbsDiff && $altPrice === $lowestPrice);

            $substitutes[] = [
                'id' => (int)$alt['id'],
                'brand_name' => $alt['brand_name'],
                'generic_name' => $alt['generic_name'],
                'salt_composition' => $alt['salt_composition'],
                'dosage_form' => $alt['dosage_form'],
                'strength_mg' => $altStrength,
                'price_mrp' => $altPrice,
                'schedule_type' => $alt['schedule_type'] ?? 'General OTC',
                // Differences compared to reference drug:
                'mg_difference' => $mgDiff,
                'abs_mg_difference' => $absDiff,
                'diff_label' => $diffLabel,
                'diff_status' => $diffStatus,
                'price_diff' => $priceDiff,
                'saving_percent' => $savingPercent,
                'is_cheaper' => ($priceDiff > 0),
                'is_exact_strength' => ($mgDiff === 0),
                'is_min_difference' => ($absDiff === $minAbsDiff),
                'is_best_match' => $isMinDiff,
                'is_lowest_price' => ($altPrice === $lowestPrice)
            ];
        }

        // Sort substitutes: Exact / Minimum difference first, then lowest price
        usort($substitutes, function($a, $b) {
            if ($a['abs_mg_difference'] !== $b['abs_mg_difference']) {
                return $a['abs_mg_difference'] <=> $b['abs_mg_difference'];
            }
            return $a['price_mrp'] <=> $b['price_mrp'];
        });

        // Ensure at least one item has is_best_match if alternatives exist
        if (!empty($substitutes)) {
            $hasBest = false;
            foreach ($substitutes as $s) {
                if ($s['is_best_match']) { $hasBest = true; break; }
            }
            if (!$hasBest) {
                $substitutes[0]['is_best_match'] = true;
            }
        }
    }

    echo json_encode([
        'success' => true,
        'query' => $query,
        'primary_results' => $primaryResults,
        'reference_drug' => $referenceDrug,
        'substitutes' => $substitutes,
        'total_substitutes' => count($substitutes)
    ]);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}
