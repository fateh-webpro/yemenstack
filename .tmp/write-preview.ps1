
function Fix-Three([string]$s) {
    $enc = [System.Text.Encoding]::GetEncoding(1256)
    $step1 = [System.Text.Encoding]::UTF8.GetString($enc.GetBytes($s))
    $step2 = [System.Text.Encoding]::UTF8.GetString($enc.GetBytes($step1))
    return [System.Text.Encoding]::UTF8.GetString($enc.GetBytes($step2))
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
            if ($text -match '[???????]' -or $text -match '???') { $text = Fix-Three $text }
            [void]$result.Append($text)
            if ($i -lt $content.Length) { [void]$result.Append($content[$i]) }
        } else {
            [void]$result.Append($ch)
        }
        $i++
    }
    return $result.ToString()
}
$content = Get-Content -Raw -Encoding UTF8 'app\Filament\Resources\WhatsappAccounts\WhatsappAccountResource.php'
$updated = Repair-PhpStrings $content
[System.IO.File]::WriteAllText((Join-Path (Get-Location) '.tmp\wa-fixed-preview.php'), $updated, [System.Text.UTF8Encoding]::new($false))
