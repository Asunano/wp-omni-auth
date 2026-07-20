<#
.SYNOPSIS
    Compile .po files to .mo binary format for WordPress.
.DESCRIPTION
    Pure PowerShell implementation — no gettext tools required.
    Usage: .\tools\build-mo.ps1
    Compiles all .po files in languages/ to .mo files.
#>

$ErrorActionPreference = 'Stop'
$pluginDir = Split-Path -Parent $PSScriptRoot
$langDir = Join-Path $pluginDir 'languages'

function ConvertTo-MoFile {
    [CmdletBinding()]
    param(
        [string]$PoPath
    )

    $MoPath = $PoPath -replace '\.po$', '.mo'
    $encoding = [System.Text.Encoding]::UTF8

    # Parse .po file
    $entries = @()
    $current = @{ msgid = $null; msgstr = $null }
    $field = $null  # 'msgid' or 'msgstr'

    foreach ($rawLine in (Get-Content $PoPath -Encoding UTF8)) {
        $line = $rawLine.Trim()

        # Skip comments and empty lines between entries
        if ($line -eq '' -or $line.StartsWith('#')) {
            # If we have a complete entry, save it
            if ($null -ne $current.msgid -and $null -ne $current.msgstr) {
                $entries += ,@($current.msgid, $current.msgstr)
                $current = @{ msgid = $null; msgstr = $null }
                $field = $null
            }
            continue
        }

        if ($line -match '^msgid\s+"(.*)"$') {
            $field = 'msgid'
            $current.msgid = Unescape-PoString $Matches[1]
        }
        elseif ($line -match '^msgstr\s+"(.*)"$') {
            $field = 'msgstr'
            $current.msgstr = Unescape-PoString $Matches[1]
        }
        elseif ($line -match '^"(.*)"$') {
            # Continuation line
            if ($field -eq 'msgid') {
                $current.msgid += Unescape-PoString $Matches[1]
            }
            elseif ($field -eq 'msgstr') {
                $current.msgstr += Unescape-PoString $Matches[1]
            }
        }
    }
    # Don't forget the last entry
    if ($null -ne $current.msgid -and $null -ne $current.msgstr) {
        $entries += ,@($current.msgid, $current.msgstr)
    }

    # Filter: skip entries where msgstr is empty (untranslated)
    # EXCEPT the header entry (empty msgid) which must always be included
    $valid = @()
    foreach ($e in $entries) {
        if ($e[0] -eq '' -or $e[1] -ne '') {
            $valid += ,$e
        }
    }

    # Build .mo binary
    # Sort entries by msgid (required by MO format)
    $valid = $valid | Sort-Object { $_[0] }

    $n = $valid.Count

    # Encode all strings
    $origBytes = @()
    $transBytes = @()
    foreach ($e in $valid) {
        $origBytes += ,($encoding.GetBytes($e[0]))
        $transBytes += ,($encoding.GetBytes($e[1]))
    }

    # MO file layout:
    # 0..27   : header (7 uint32)
    # 28..28+n*8-1  : original strings table (offset, length pairs)
    # 28+n*8..28+n*16-1 : translation strings table
    # rest    : string data

    $tableOffset = 28
    $origTableStart = $tableOffset
    $transTableStart = $tableOffset + $n * 8

    # Calculate string data start
    $dataStart = $transTableStart + $n * 8

    # Build string data and offset tables
    $stringData = [System.Collections.Generic.List[byte]]::new()
    $origTable = [System.Collections.Generic.List[byte]]::new()
    $transTable = [System.Collections.Generic.List[byte]]::new()

    $currentOffset = $dataStart
    for ($i = 0; $i -lt $n; $i++) {
        $ob = $origBytes[$i]
        $origTable.AddRange([BitConverter]::GetBytes([int]$ob.Length))
        $origTable.AddRange([BitConverter]::GetBytes([int]$currentOffset))
        $stringData.AddRange($ob)
        $stringData.Add(0)  # null terminator
        $currentOffset += $ob.Length + 1
    }

    for ($i = 0; $i -lt $n; $i++) {
        $tb = $transBytes[$i]
        $transTable.AddRange([BitConverter]::GetBytes([int]$tb.Length))
        $transTable.AddRange([BitConverter]::GetBytes([int]$currentOffset))
        $stringData.AddRange($tb)
        $stringData.Add(0)  # null terminator
        $currentOffset += $tb.Length + 1
    }

    # Build header
    $header = [System.Collections.Generic.List[byte]]::new()
    # Magic number 0x950412DE as raw bytes (little-endian) to avoid uint32 overflow
    $header.Add([byte]0xDE)
    $header.Add([byte]0x12)
    $header.Add([byte]0x04)
    $header.Add([byte]0x95)
    $header.AddRange([BitConverter]::GetBytes([uint32]0))           # revision
    $header.AddRange([BitConverter]::GetBytes([int]$n))             # string count
    $header.AddRange([BitConverter]::GetBytes([int]$origTableStart))  # orig table offset
    $header.AddRange([BitConverter]::GetBytes([int]$transTableStart)) # trans table offset
    $header.AddRange([BitConverter]::GetBytes([int]0))              # hash table size
    $header.AddRange([BitConverter]::GetBytes([int]0))              # hash table offset

    # Combine all parts
    $mo = [System.Collections.Generic.List[byte]]::new()
    $mo.AddRange($header)
    $mo.AddRange($origTable)
    $mo.AddRange($transTable)
    $mo.AddRange($stringData)

    # Write .mo file (UTF-8 without BOM)
    [System.IO.File]::WriteAllBytes($MoPath, $mo.ToArray())

    $translated = ($valid | Where-Object { $_[0] -ne '' -and $_[1] -ne '' }).Count
    Write-Host "Compiled: $MoPath ($translated translated, $($n - 1) total strings)"
}

function Unescape-PoString {
    param([string]$s)
    # Process PO escape sequences
    $result = $s -replace '\\n', "`n"
    $result = $result -replace '\\t', "`t"
    $result = $result -replace '\\"', '"'
    $result = $result -replace '\\\\', '\'
    return $result
}

# Main
$poFiles = Get-ChildItem -Path $langDir -Filter '*.po'
if ($poFiles.Count -eq 0) {
    Write-Host "No .po files found in $langDir"
    exit 0
}

foreach ($po in $poFiles) {
    ConvertTo-MoFile -PoPath $po.FullName
}

Write-Host "Done."
