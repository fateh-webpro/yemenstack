
function Fix-Step([string]$s) {
    $enc = [System.Text.Encoding]::GetEncoding(1256)
    return [System.Text.Encoding]::UTF8.GetString($enc.GetBytes($s))
}

function Score-Text([string]$s) {
    $arabic = 0
    $taZa = 0
    $mojibakeLatin = 0
    $replacement = 0
    foreach ($ch in $s.ToCharArray()) {
        $code = [int][char]$ch
        if ($code -ge 0x0600 -and $code -le 0x06FF) { $arabic++ }
        if ($ch -eq '?' -or $ch -eq '?') { $taZa++ }
        if ($ch -eq '?' -or $ch -eq '?' -or $ch -eq '?' -or $ch -eq '?' -or $ch -eq '?' -or $ch -eq '?' -or $ch -eq '?') { $mojibakeLatin++ }
        if ($code -eq 0xFFFD) { $replacement++ }
    }
    return ($arabic * 4) - ($taZa * 3) - ($mojibakeLatin * 12) - ($replacement * 20)
}

function Fix-Mojibake([string]$s) {
    $best = $s
    $bestScore = Score-Text $s
    $current = $s
    for ($n = 0; $n -lt 4; $n++) {
        $current = Fix-Step $current
        $score = Score-Text $current
        if ($score -gt $bestScore) {
            $best = $current
            $bestScore = $score
        }
    }
    return $best
}

function Repair-PhpStrings([string]$content) {
    $result = New-Object System.Text.StringBuilder
    $i = 0
    while ($i -lt $content.Length) {
        $ch = $content[$i]
        if ($ch -eq "'" -or $ch -eq '"') {
            $quote = $ch
            [void]$result.Append($quote)
            $i++
            $body = New-Object System.Text.StringBuilder
            while ($i -lt $content.Length) {
                $current = $content[$i]
                if ($current -eq '\' -and ($i + 1) -lt $content.Length) {
                    [void]$body.Append($current)
                    $i++
                    [void]$body.Append($content[$i])
                    $i++
                    continue
                }
                if ($current -eq $quote) { break }
                [void]$body.Append($current)
                $i++
            }
            $text = $body.ToString()
            $needsFix = $false
            foreach ($marker in @('?','?','?','?','?','?','?','?','?')) {
                if ($text.Contains($marker)) { $needsFix = $true; break }
            }
            if ($needsFix) { $text = Fix-Mojibake $text }
            [void]$result.Append($text)
            if ($i -lt $content.Length) { [void]$result.Append($content[$i]) }
        } else {
            [void]$result.Append($ch)
        }
        $i++
    }
    return $result.ToString()
}

$path = 'app\Filament\Resources\WhatsappAccounts\WhatsappAccountResource.php'
$content = Get-Content -Raw -Encoding UTF8 $path
$updated = Repair-PhpStrings $content
$updated.Split([Environment]::NewLine) | Select-Object -First 80
