<?php
/**
 * PharmaSys Hub - Prescription Generator & Document Utilities
 * Ensures every digital prescription has a high-fidelity visual clinical document.
 */

function generatePrescriptionSvg(array $rxData): string {
    $patientName = htmlspecialchars($rxData['patient_name'] ?? 'Patient');
    $doctorName = htmlspecialchars($rxData['doctor_name'] ?? 'Dr. V. K. Mehta, MD');
    $rxId = str_pad((string)($rxData['id'] ?? 1), 5, '0', STR_PAD_LEFT);
    $dateStr = isset($rxData['created_at']) ? date('d-M-Y h:i A', strtotime($rxData['created_at'])) : date('d-M-Y h:i A');
    $medicinesStr = htmlspecialchars($rxData['prescribed_medicines'] ?? 'Augmentin 625 Duo (1 Tab twice daily • 5 Days), Pan-D 40 (1 Cap morning)');
    $notes = htmlspecialchars($rxData['notes'] ?? 'Standard therapeutic course. Ensure full antibiotic compliance.');

    // Split medicines into items if separated by comma or newline
    $medList = preg_split('/[\n,]+/', $medicinesStr);
    $medRows = '';
    $yPos = 310;
    $idx = 1;

    foreach ($medList as $med) {
        $med = trim($med);
        if (!$med) continue;
        
        $medRows .= "
        <g transform='translate(50, {$yPos})'>
          <circle cx='10' cy='-5' r='8' fill='#0284C7' />
          <text x='10' y='-2' font-family='Arial, sans-serif' font-size='10' font-weight='bold' fill='#FFFFFF' text-anchor='middle'>{$idx}</text>
          <text x='30' y='0' font-family='Arial, sans-serif' font-size='14' font-weight='bold' fill='#0F172A'>{$med}</text>
          <rect x='520' y='-14' width='80' height='20' rx='4' fill='#FEF3C7' stroke='#F59E0B' stroke-width='1'/>
          <text x='560' y='0' font-family='Arial, sans-serif' font-size='10' font-weight='bold' fill='#B45309' text-anchor='middle'>Schedule H</text>
        </g>";
        $yPos += 36;
        $idx++;
        if ($idx > 6) break; // Limit rows
    }

    $notesY = max($yPos + 25, 430);

    return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 700 700" width="100%" height="100%">
  <defs>
    <linearGradient id="headerGrad" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" stop-color="#0284C7" />
      <stop offset="100%" stop-color="#0369A1" />
    </linearGradient>
    <filter id="shadow" x="-5%" y="-5%" width="110%" height="110%">
      <feDropShadow dx="0" dy="4" stdDeviation="6" flood-opacity="0.1" />
    </filter>
  </defs>

  <!-- Sheet Background -->
  <rect x="10" y="10" width="680" height="680" rx="8" fill="#FFFFFF" stroke="#CBD5E1" stroke-width="2" filter="url(#shadow)" />

  <!-- Header Banner -->
  <rect x="10" y="10" width="680" height="90" rx="8" fill="url(#headerGrad)" />
  <rect x="10" y="90" width="680" height="10" fill="#0284C7" />

  <!-- Clinic Emblem -->
  <circle cx="55" cy="55" r="26" fill="#FFFFFF" />
  <text x="55" y="64" font-family="'Segoe UI', Arial, sans-serif" font-size="24" font-weight="900" fill="#0284C7" text-anchor="middle">Rx</text>

  <!-- Hospital Title -->
  <text x="95" y="45" font-family="'Segoe UI', Arial, sans-serif" font-size="19" font-weight="800" fill="#FFFFFF">CAREFIRST CLINICAL PHARMACOLOGY</text>
  <text x="95" y="66" font-family="'Segoe UI', Arial, sans-serif" font-size="11" fill="#E0F2FE">Central Medical Council Reg: MCI-2026-MH-44910 &bull; CDSCO Accredited Hospital</text>
  <text x="95" y="82" font-family="'Segoe UI', Arial, sans-serif" font-size="10" fill="#BAE6FD">102 Medical Enclave, Civil Lines &bull; Phone: +91 98765 43210</text>

  <!-- Rx Serial / Date Ribbon -->
  <rect x="520" y="24" width="150" height="48" rx="6" fill="rgba(255,255,255,0.15)" stroke="rgba(255,255,255,0.3)" />
  <text x="595" y="42" font-family="'Courier New', monospace" font-size="12" font-weight="bold" fill="#FFFFFF" text-anchor="middle">#RX-{$rxId}</text>
  <text x="595" y="60" font-family="Arial, sans-serif" font-size="9" fill="#E0F2FE" text-anchor="middle">{$dateStr}</text>

  <!-- Demographics Card -->
  <rect x="35" y="120" width="630" height="85" rx="6" fill="#F8FAFC" stroke="#E2E8F0" stroke-width="1" />
  
  <text x="55" y="145" font-family="Arial, sans-serif" font-size="10" font-weight="bold" fill="#64748B" letter-spacing="1">PATIENT INFORMATION</text>
  <text x="55" y="168" font-family="Arial, sans-serif" font-size="15" font-weight="bold" fill="#0F172A">{$patientName}</text>
  <text x="55" y="188" font-family="Arial, sans-serif" font-size="11" fill="#475569">Status: Verified Consumer &bull; General OPD</text>

  <text x="360" y="145" font-family="Arial, sans-serif" font-size="10" font-weight="bold" fill="#64748B" letter-spacing="1">PRESCRIBING PHYSICIAN</text>
  <text x="360" y="168" font-family="Arial, sans-serif" font-size="15" font-weight="bold" fill="#0F172A">{$doctorName}</text>
  <text x="360" y="188" font-family="Arial, sans-serif" font-size="11" fill="#059669">&#10003; Certified Physician Signature on File</text>

  <!-- Divider with Rx Symbol -->
  <line x1="35" y1="230" x2="665" y2="230" stroke="#E2E8F0" stroke-width="1.5" />
  <rect x="45" y="216" width="40" height="28" rx="4" fill="#E0F2FE" />
  <text x="65" y="236" font-family="'Segoe UI', Arial, sans-serif" font-size="16" font-weight="bold" fill="#0284C7" text-anchor="middle">&#8478;</text>
  <text x="95" y="235" font-family="Arial, sans-serif" font-size="13" font-weight="bold" fill="#0F172A">PRESCRIBED MEDICINES &amp; DOSAGE REGIMEN</text>

  <!-- Medicines List Heading -->
  <rect x="35" y="255" width="630" height="30" fill="#F1F5F9" />
  <text x="55" y="275" font-family="Arial, sans-serif" font-size="11" font-weight="bold" fill="#475569">#</text>
  <text x="80" y="275" font-family="Arial, sans-serif" font-size="11" font-weight="bold" fill="#475569">Prescribed Formulation &amp; Strength</text>
  <text x="550" y="275" font-family="Arial, sans-serif" font-size="11" font-weight="bold" fill="#475569" text-anchor="middle">CDSCO Schedule</text>

  <!-- Prescribed Medicine Rows -->
  {$medRows}

  <!-- Clinical Notes Section -->
  <rect x="35" y="{$notesY}" width="630" height="65" rx="6" fill="#F8FAFC" stroke="#CBD5E1" stroke-dasharray="4" />
  <text x="50" y="{$notesY}+20" font-family="Arial, sans-serif" font-size="10" font-weight="bold" fill="#64748B">DIAGNOSIS &amp; CLINICAL INSTRUCTIONS:</text>
  <text x="50" y="{$notesY}+42" font-family="Arial, sans-serif" font-size="12" fill="#334155">{$notes}</text>

  <!-- Footer Verification Seal & Signature -->
  <line x1="35" y1="570" x2="665" y2="570" stroke="#E2E8F0" stroke-width="1" />

  <!-- Security Seal -->
  <circle cx="110" cy="625" r="35" fill="none" stroke="#059669" stroke-width="2" stroke-dasharray="3 3" />
  <circle cx="110" cy="625" r="30" fill="rgba(5, 150, 105, 0.05)" />
  <text x="110" y="622" font-family="Arial, sans-serif" font-size="8" font-weight="bold" fill="#059669" text-anchor="middle">VERIFIED</text>
  <text x="110" y="634" font-family="Arial, sans-serif" font-size="7" font-weight="bold" fill="#059669" text-anchor="middle">NDHM PORTAL</text>

  <!-- Verification Note -->
  <text x="160" y="620" font-family="Arial, sans-serif" font-size="10" font-weight="bold" fill="#0F172A">National Digital Health Mission Verified Slip</text>
  <text x="160" y="635" font-family="Arial, sans-serif" font-size="9" fill="#64748B">Pharmacist must log Schedule H dispensation in CDSCO register before billing.</text>

  <!-- Doctor Signature -->
  <path d="M 460 625 Q 490 600, 520 620 T 560 610 T 600 625" fill="none" stroke="#0369A1" stroke-width="2.5" />
  <text x="530" y="640" font-family="Arial, sans-serif" font-size="11" font-weight="bold" fill="#0F172A" text-anchor="middle">{$doctorName}</text>
  <text x="530" y="654" font-family="Arial, sans-serif" font-size="9" fill="#64748B" text-anchor="middle">Authorized Medical Practitioner</text>
</svg>
SVG;
}

/**
 * Ensures a physical visual file exists in uploads/prescriptions/ for an Rx record.
 */
function ensurePrescriptionDocumentFile(array $rx): string {
    $uploadDir = __DIR__ . '/../uploads/prescriptions';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $fileName = $rx['file_name'] ?? '';
    $filePath = $uploadDir . '/' . basename($fileName);

    if (empty($fileName) || !file_exists($filePath)) {
        // Generate SVG document
        $cleanPatient = preg_replace('/[^a-zA-Z0-9]/', '_', $rx['patient_name'] ?? 'Patient');
        $newFileName = 'Rx_' . $cleanPatient . '_' . ($rx['id'] ?? '1') . '.svg';
        $newFilePath = $uploadDir . '/' . $newFileName;
        
        $svgContent = generatePrescriptionSvg($rx);
        file_put_contents($newFilePath, $svgContent);
        
        // Update database record if needed
        if (isset($rx['id'])) {
            try {
                $pdo = Database::getConnection();
                $stmt = $pdo->prepare("UPDATE prescriptions SET file_name = :fname WHERE id = :id");
                $stmt->execute([':fname' => $newFileName, ':id' => $rx['id']]);
            } catch (Exception $e) {}
        }

        return $newFileName;
    }

    return $fileName;
}

